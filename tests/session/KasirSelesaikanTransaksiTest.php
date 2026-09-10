<?php

use App\Models\TransaksiModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Phase 2 — kapabilitas "Selesai dari Kasir" (workflow kasir/index.php).
 *
 * Menegaskan pembagian KONTEKS:
 *  - /api/kasir/selesaikan-transaksi : kasir boleh menyelesaikan
 *    transaksi kasir_pos BUATANNYA SENDIRI, HANYA jika sudah lunas.
 *  - /api/ubah-status (Detail/Daftar) : kasir tetap DITOLAK, tak berubah.
 *
 * Semua enforcement diuji lewat HTTP (bukan sekadar tombol UI).
 *
 * @internal
 */
final class KasirSelesaikanTransaksiTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    protected $namespace = 'Tests\Support';
    protected $migrate   = true;
    protected $refresh   = true;

    private const SELESAIKAN_KASIR = 'api/kasir/selesaikan-transaksi';
    private const UBAH_STATUS      = 'api/ubah-status';

    private function sesi(string $role, int $idUser): array
    {
        return [
            'isLoggedIn'    => true,
            'role'          => $role,
            'id_user'       => $idUser,
            'nama'          => 'Test ' . $role,
            'last_activity' => time(),
        ];
    }

    private function seedTransaksi(array $override = []): int
    {
        $db = db_connect();
        $db->table('transaksi')->insert(array_merge([
            'kode_invoice'      => 'INV-T-' . random_int(1000, 9999),
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

    private function bayarLunas(int $id, int $grandTotal = 100000): void
    {
        (new TransaksiModel())->tambahPembayaran($id, [
            'jumlah'   => $grandTotal,
            'metode'   => 'tunai',
            'tanggal'  => date('Y-m-d H:i:s'),
            'kasir_id' => 7,
        ]);
    }

    private function statusTransaksi(int $id): string
    {
        return (string) db_connect()->table('transaksi')->getWhere(['id' => $id])->getRow('status');
    }

    // ---- A. kasir + kasir/index + LUNAS -> ALLOWED --------------------

    public function testKasirMenyelesaikanTransaksiLunasBuatannyaSendiri(): void
    {
        $id = $this->seedTransaksi(['kasir_id' => 7]);
        $this->bayarLunas($id);

        $res = $this->withSession($this->sesi('kasir', 7))
            ->withBodyFormat('json')
            ->post(self::SELESAIKAN_KASIR, ['transaksi_id' => $id]);

        $res->assertOK();
        $res->assertJSONFragment(['status' => 'success']);
        $this->assertSame('selesai', $this->statusTransaksi($id));
    }

    // ---- B & C. kasir + DP / belum bayar -> REJECTED -----------------

    public function testKasirTidakBisaMenyelesaikanTransaksiDp(): void
    {
        $id = $this->seedTransaksi(['kasir_id' => 7]);
        (new TransaksiModel())->tambahPembayaran($id, [
            'jumlah' => 40000, 'metode' => 'tunai',
            'tanggal' => date('Y-m-d H:i:s'), 'kasir_id' => 7,
        ]);

        $res = $this->withSession($this->sesi('kasir', 7))
            ->withBodyFormat('json')
            ->post(self::SELESAIKAN_KASIR, ['transaksi_id' => $id]);

        $res->assertJSONFragment(['status' => 'error']);
        $this->assertSame('proses', $this->statusTransaksi($id));
    }

    public function testKasirTidakBisaMenyelesaikanTransaksiBelumBayar(): void
    {
        $id = $this->seedTransaksi(['kasir_id' => 7]);

        $res = $this->withSession($this->sesi('kasir', 7))
            ->withBodyFormat('json')
            ->post(self::SELESAIKAN_KASIR, ['transaksi_id' => $id]);

        $res->assertJSONFragment(['status' => 'error']);
        $this->assertSame('proses', $this->statusTransaksi($id));
    }

    // ---- Gate kepemilikan & sumber ----------------------------------

    public function testKasirTidakBisaMenyelesaikanTransaksiKasirLain(): void
    {
        $id = $this->seedTransaksi(['kasir_id' => 99]);
        $this->bayarLunas($id);

        $res = $this->withSession($this->sesi('kasir', 7))
            ->withBodyFormat('json')
            ->post(self::SELESAIKAN_KASIR, ['transaksi_id' => $id]);

        $res->assertJSONFragment(['status' => 'error']);
        $this->assertSame('proses', $this->statusTransaksi($id));
    }

    public function testTransaksiNonKasirPosDitolak(): void
    {
        $id = $this->seedTransaksi(['kasir_id' => 7, 'sumber' => 'aplikasi_cetak_foto']);
        $this->bayarLunas($id);

        $res = $this->withSession($this->sesi('kasir', 7))
            ->withBodyFormat('json')
            ->post(self::SELESAIKAN_KASIR, ['transaksi_id' => $id]);

        $res->assertJSONFragment(['status' => 'error']);
        $this->assertSame('proses', $this->statusTransaksi($id));
    }

    // ---- E. admin lewat endpoint kasir tetap boleh (bypass ownership) --

    public function testAdminBolehMenyelesaikanTransaksiKasirPosLunasSiapaPun(): void
    {
        $id = $this->seedTransaksi(['kasir_id' => 99]);
        $this->bayarLunas($id);

        $res = $this->withSession($this->sesi('admin', 1))
            ->withBodyFormat('json')
            ->post(self::SELESAIKAN_KASIR, ['transaksi_id' => $id]);

        $res->assertJSONFragment(['status' => 'success']);
        $this->assertSame('selesai', $this->statusTransaksi($id));
    }

    // ---- D. jalur umum /api/ubah-status TIDAK berubah untuk kasir -----

    public function testKasirTetapDitolakLewatUbahStatusUmumWalauLunas(): void
    {
        $id = $this->seedTransaksi(['kasir_id' => 7]);
        $this->bayarLunas($id);

        $res = $this->withSession($this->sesi('kasir', 7))
            ->withBodyFormat('json')
            ->post(self::UBAH_STATUS, ['id' => $id, 'status' => 'selesai']);

        $res->assertJSONFragment(['status' => 'error']);
        $this->assertSame('proses', $this->statusTransaksi($id));
    }

    public function testAdminBolehLewatUbahStatusUmumKetikaLunas(): void
    {
        $id = $this->seedTransaksi(['kasir_id' => 7]);
        $this->bayarLunas($id);

        $res = $this->withSession($this->sesi('admin', 1))
            ->withBodyFormat('json')
            ->post(self::UBAH_STATUS, ['id' => $id, 'status' => 'selesai']);

        $res->assertJSONFragment(['status' => 'success']);
        $this->assertSame('selesai', $this->statusTransaksi($id));
    }
}
