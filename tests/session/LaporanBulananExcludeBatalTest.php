<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Bug: Laporan::getLaporanBulananData() (tab "Bulanan"/Ringkasan &
 * tab "Harian" di /laporan) tidak mengecualikan transaksi
 * status='batal' -- pembayaran yang belum di-reverse dari transaksi
 * batal ikut kehitung di kolom kategori & TF+QRIS, padahal
 * docs/aturan-bisnis-AULIA.md Section 12 mendefinisikan batal =
 * dianggap tidak pernah terjadi (konsisten dengan
 * v_pembayaran_item_harian & CashBalanceService, yang sudah benar).
 *
 * @internal
 */
final class LaporanBulananExcludeBatalTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    protected $namespace = 'Tests\Support';
    protected $migrate   = true;
    protected $refresh   = true;

    private const KATEGORI_FOTOKOPI = 2;

    private function session(): array
    {
        return ['isLoggedIn' => true, 'role' => 'admin', 'id_user' => 1, 'last_activity' => time()];
    }

    /** @return int id transaksi yang baru dibuat */
    private function buatTransaksiDenganPembayaran(
        string $invoice,
        string $statusTransaksi,
        int $jumlah,
        string $metode,
        string $tanggal
    ): int {
        $db = db_connect();

        $db->table('transaksi')->insert([
            'kode_invoice'      => $invoice,
            'tanggal'           => $tanggal,
            'kasir_id'          => 7,
            'subtotal'          => $jumlah,
            'grand_total'       => $jumlah,
            'total_dibayar'     => $jumlah,
            'status_pembayaran' => 'lunas',
            'status'            => $statusTransaksi,
        ]);
        $transaksiId = (int) $db->insertID();

        $db->table('produk')->insert([
            'nama'         => 'Fotokopi 1 Sisi ' . $invoice,
            'kategori_id'  => self::KATEGORI_FOTOKOPI,
            'harga_jual'   => $jumlah,
        ]);
        $produkId = (int) $db->insertID();

        $db->table('detail_transaksi')->insert([
            'transaksi_id' => $transaksiId,
            'produk_id'    => $produkId,
            'nama_produk'  => 'Fotokopi 1 Sisi',
            'kategori_id'  => self::KATEGORI_FOTOKOPI,
            'jumlah'       => 1,
            'harga_satuan' => $jumlah,
            'subtotal'     => $jumlah,
        ]);

        // status pembayaran SENGAJA tetap 'aktif' walau transaksinya
        // batal -- ini persis kasus nyata yang jadi sumber bug (lihat
        // docs Section 12: pembatalan tidak otomatis me-reverse
        // pembayaran, refund adalah proses tersendiri).
        $db->table('pembayaran')->insert([
            'transaksi_id' => $transaksiId,
            'tanggal'      => $tanggal,
            'jumlah'       => $jumlah,
            'metode'       => $metode,
            'kasir_id'     => 7,
            'status'       => 'aktif',
        ]);

        return $transaksiId;
    }

    private function panggilLaporanBulanan(string $tanggal): array
    {
        $body = [
            'jenis'         => 'bulanan',
            'tanggal_awal'  => $tanggal,
            'tanggal_akhir' => $tanggal,
        ];

        $res = $this->withSession($this->session())
            ->withBodyFormat('json')
            ->post('laporan/get-data', $body);

        $res->assertStatus(200);
        $json = json_decode($res->getJSON(), true);

        foreach ($json['data'] as $row) {
            if ($row['tanggal_raw'] === $tanggal) {
                return $row;
            }
        }

        $this->fail("Baris tanggal {$tanggal} tidak ditemukan di response.");
    }

    public function testKategoriTidakIkutkanTransaksiBatal(): void
    {
        $tanggal = date('Y-m-d');
        $tanggalJam = $tanggal . ' 10:00:00';

        $this->buatTransaksiDenganPembayaran('INV-OK', 'proses', 24500, 'tunai', $tanggalJam);
        $this->buatTransaksiDenganPembayaran('INV-BATAL', 'batal', 25500, 'tunai', $tanggalJam);

        $row = $this->panggilLaporanBulanan($tanggal);

        // Hanya transaksi yang tidak batal yang boleh masuk kolom Fotokopi.
        $this->assertSame(24500.0, (float) $row['fotokopi']);
    }

    public function testTfQrisTidakIkutkanTransaksiBatal(): void
    {
        $tanggal = date('Y-m-d');
        $tanggalJam = $tanggal . ' 10:00:00';

        $this->buatTransaksiDenganPembayaran('INV-OK-TF', 'proses', 24500, 'transfer', $tanggalJam);
        $this->buatTransaksiDenganPembayaran('INV-BATAL-TF', 'batal', 25500, 'transfer', $tanggalJam);

        $row = $this->panggilLaporanBulanan($tanggal);

        // TF+QRIS juga harus exclude pembayaran dari transaksi batal.
        $this->assertSame(24500.0, (float) $row['tf_qris']);
    }
}
