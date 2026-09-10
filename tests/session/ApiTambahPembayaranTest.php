<?php

use App\Models\TransaksiModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Safety net untuk Api::tambahPembayaran() (POST /api/tambah-pembayaran)
 * SEBELUM method itu direfactor. Diuji lewat HTTP (routing + filter auth
 * dilewati via withSession) -> controller -> TransaksiModel -> SQLite
 * test DB, tanpa mock -- gaya sama dengan
 * tests/session/KasirSelesaikanTransaksiTest.php.
 *
 * Behavior yang dikunci:
 *  - endpoint SELALU balas HTTP 200; sukses/gagal dibedakan lewat
 *    field JSON `status` (jangan assert 4xx);
 *  - transaksi tidak ditemukan -> error 'Transaksi tidak ditemukan.',
 *    tanpa row pembayaran;
 *  - jumlah > sisa (termasuk kondisi SUDAH LUNAS -> sisa 0) -> error
 *    'Jumlah pembayaran melebihi sisa tagihan.' (tidak ada pesan khusus
 *    "sudah lunas"), total_dibayar tidak berubah;
 *  - metode tak dikenal -> divalidasi di TransaksiModel::tambahPembayaran(),
 *    exception di-catch controller & diteruskan verbatim
 *    ('Metode pembayaran tidak valid.'), tanpa row pembayaran;
 *  - happy path parsial -> status_pembayaran 'dp', 1 row 'aktif',
 *    kasir_id = id_user sesi, keterangan 'Pembayaran';
 *  - happy path pelunasan (jumlah == sisa) -> status_pembayaran 'lunas',
 *    keterangan 'Lunas', uang_diterima NULL untuk metode non-tunai.
 *
 * Sengaja TIDAK menguji pembayaran ke transaksi BATAL (latent gap yang
 * belum diputuskan) maupun jalur backdate (menyisakan process-state
 * yang menabrak ProdukKategoriAdminOnlyTest::runAuthFilter di full run).
 *
 * @internal
 */
final class ApiTambahPembayaranTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    protected $namespace = 'Tests\Support';
    protected $migrate   = true;
    protected $refresh   = true;

    private const ENDPOINT = 'api/tambah-pembayaran';

    private function sesiKasir(int $idUser = 7): array
    {
        return [
            'isLoggedIn'    => true,
            'role'          => 'kasir',
            'id_user'       => $idUser,
            'nama'          => 'Test kasir',
            'last_activity' => time(),
        ];
    }

    private function seedTransaksi(array $override = []): int
    {
        $db = db_connect();
        $db->table('transaksi')->insert(array_merge([
            'kode_invoice'      => 'INV-TP-' . random_int(1000, 9999),
            'tanggal'           => date('Y-m-d H:i:s'),
            'kasir_id'          => 7,
            'grand_total'       => 100000,
            'total_dibayar'     => 0,
            'status_pembayaran' => 'belum_bayar',
            'status'            => 'proses',
            'sumber'            => 'kasir_pos',
        ], $override));

        return (int) $db->insertID();
    }

    /** Susun kondisi awal lewat chokepoint model (bukan endpoint yang diuji). */
    private function bayar(int $id, int $jumlah, string $metode = 'tunai'): void
    {
        (new TransaksiModel())->tambahPembayaran($id, [
            'jumlah'   => $jumlah,
            'metode'   => $metode,
            'tanggal'  => date('Y-m-d H:i:s'),
            'kasir_id' => 7,
        ]);
    }

    private function transaksiRow(int $id): array
    {
        return db_connect()->table('transaksi')->getWhere(['id' => $id])->getRowArray() ?? [];
    }

    private function pembayaranRows(int $id): array
    {
        return db_connect()->table('pembayaran')
            ->where('transaksi_id', $id)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
    }

    private function kirim(array $session, array $body)
    {
        return $this->withSession($session)
            ->withBodyFormat('json')
            ->post(self::ENDPOINT, $body);
    }

    // ------------------------------------------------------------------

    public function testTransaksiTidakDitemukanDitolak(): void
    {
        $res = $this->kirim($this->sesiKasir(), [
            'transaksi_id' => 999999,
            'jumlah'       => 10000,
            'metode'       => 'tunai',
        ]);

        $res->assertStatus(200);
        $res->assertJSONFragment(['status' => 'error']);
        $this->assertStringContainsString('Transaksi tidak ditemukan.', $res->getJSON());

        $this->assertSame(0, db_connect()->table('pembayaran')->countAllResults());
    }

    public function testJumlahMelebihiSisaDitolak(): void
    {
        $id = $this->seedTransaksi(['grand_total' => 100000]);

        $res = $this->kirim($this->sesiKasir(), [
            'transaksi_id' => $id,
            'jumlah'       => 150000,
            'metode'       => 'tunai',
        ]);

        $res->assertStatus(200);
        $res->assertJSONFragment(['status' => 'error']);
        $this->assertStringContainsString('Jumlah pembayaran melebihi sisa tagihan.', $res->getJSON());

        $this->assertSame(0.0, (float) $this->transaksiRow($id)['total_dibayar']);
        $this->assertCount(0, $this->pembayaranRows($id));

        // --- Variasi: transaksi SUDAH LUNAS -> pesan yang SAMA
        // (tidak ada pesan khusus "sudah lunas").
        $idLunas = $this->seedTransaksi(['grand_total' => 100000]);
        $this->bayar($idLunas, 100000, 'tunai');
        $this->assertSame('lunas', $this->transaksiRow($idLunas)['status_pembayaran']);

        $resLunas = $this->kirim($this->sesiKasir(), [
            'transaksi_id' => $idLunas,
            'jumlah'       => 10000,
            'metode'       => 'tunai',
        ]);

        $resLunas->assertStatus(200);
        $resLunas->assertJSONFragment(['status' => 'error']);
        $this->assertStringContainsString('Jumlah pembayaran melebihi sisa tagihan.', $resLunas->getJSON());
        $this->assertCount(1, $this->pembayaranRows($idLunas)); // hanya baris pelunasan awal
    }

    public function testMetodeInvalidDitolakLewatModel(): void
    {
        $id = $this->seedTransaksi(['grand_total' => 100000]);

        $res = $this->kirim($this->sesiKasir(), [
            'transaksi_id' => $id,
            'jumlah'       => 50000,
            'metode'       => 'gopay',
        ]);

        $res->assertStatus(200);
        $res->assertJSONFragment(['status' => 'error']);
        $this->assertStringContainsString('Metode pembayaran tidak valid.', $res->getJSON());

        $this->assertCount(0, $this->pembayaranRows($id));
        $this->assertSame(0.0, (float) $this->transaksiRow($id)['total_dibayar']);
    }

    public function testHappyPathParsialTercatatSebagaiDp(): void
    {
        $id = $this->seedTransaksi(['grand_total' => 100000]);

        $res = $this->kirim($this->sesiKasir(7), [
            'transaksi_id' => $id,
            'jumlah'       => 40000,
            'metode'       => 'tunai',
        ]);

        $res->assertStatus(200);
        $res->assertJSONFragment(['status' => 'success']);
        $this->assertStringContainsString('Pembayaran berhasil ditambahkan!', $res->getJSON());

        $rows = $this->pembayaranRows($id);
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame(40000.0, (float) $row['jumlah']);
        $this->assertSame('tunai', $row['metode']);
        $this->assertSame('aktif', $row['status']);
        $this->assertSame(7, (int) $row['kasir_id']);
        $this->assertSame('Pembayaran', $row['keterangan']);

        $t = $this->transaksiRow($id);
        $this->assertSame(40000.0, (float) $t['total_dibayar']);
        $this->assertSame('dp', $t['status_pembayaran']);
    }

    public function testHappyPathPelunasanJumlahSamaDenganSisa(): void
    {
        $id = $this->seedTransaksi(['grand_total' => 100000]);

        $res = $this->kirim($this->sesiKasir(7), [
            'transaksi_id' => $id,
            'jumlah'       => 100000,
            'metode'       => 'transfer',
        ]);

        $res->assertStatus(200);
        $res->assertJSONFragment(['status' => 'success']);

        $rows = $this->pembayaranRows($id);
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame(100000.0, (float) $row['jumlah']);
        $this->assertSame('transfer', $row['metode']);
        $this->assertSame('Lunas', $row['keterangan']);
        $this->assertNull($row['uang_diterima']);

        $t = $this->transaksiRow($id);
        $this->assertSame(100000.0, (float) $t['total_dibayar']);
        $this->assertSame('lunas', $t['status_pembayaran']);
    }
}
