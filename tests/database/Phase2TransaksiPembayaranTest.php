<?php

use App\Models\PembayaranModel;
use App\Models\TransaksiModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Phase 2 — aturan bisnis pembayaran & status transaksi.
 *
 * Menguji chokepoint di TransaksiModel:
 *  - validasi metode pembayaran (harus PembayaranModel::METODE);
 *  - perhitungan status_pembayaran & sisa/lebih-bayar dari
 *    (grand_total, total pembayaran kumulatif);
 *  - enforcement PROSES -> SELESAI: wajib LUNAS untuk SEMUA jalur;
 *    role admin ATAU kapabilitas konteks kasir ($izinSelesaikanKonteks).
 *
 * Gaya mengikuti tests/unit/KalkulasiDiskonTransaksiTest.php
 * (assertSame eksplisit, nama Indonesia) — hanya butuh DB karena
 * method-nya menyentuh tabel transaksi/pembayaran.
 *
 * @internal
 */
final class Phase2TransaksiPembayaranTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'Tests\Support';
    protected $migrate   = true;
    protected $refresh   = true;

    private TransaksiModel $transaksiModel;
    private PembayaranModel $pembayaranModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transaksiModel  = new TransaksiModel();
        $this->pembayaranModel = new PembayaranModel();
    }

    /** Buat satu transaksi PROSES + belum_bayar, kembalikan id-nya. */
    private function buatTransaksi(int $grandTotal = 100000, int $kasirId = 7, string $sumber = 'kasir_pos'): int
    {
        $db = db_connect();
        $db->table('transaksi')->insert([
            'kode_invoice'      => 'INV-TEST-' . random_int(1000, 9999),
            'tanggal'           => date('Y-m-d H:i:s'),
            'kasir_id'          => $kasirId,
            'grand_total'       => $grandTotal,
            'total_dibayar'     => 0,
            'status_pembayaran' => 'belum_bayar',
            'status'            => 'proses',
            'sumber'            => $sumber,
        ]);

        return (int) $db->insertID();
    }

    private function bayar(int $id, int $jumlah, string $metode = 'tunai'): void
    {
        $this->transaksiModel->tambahPembayaran($id, [
            'jumlah'   => $jumlah,
            'metode'   => $metode,
            'tanggal'  => date('Y-m-d H:i:s'),
            'kasir_id' => 7,
        ]);
    }

    private function ambilTransaksi(int $id): array
    {
        return $this->transaksiModel->find($id);
    }

    // =====================================================================
    // A. TRANSAKSI BARU / PEMBAYARAN
    // =====================================================================

    public function testMetodePembayaranTidakValidDitolak(): void
    {
        $id = $this->buatTransaksi(100000);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Metode pembayaran tidak valid.');

        $this->bayar($id, 50000, 'gopay');
    }

    public function testMetodePembayaranValidMenghasilkanLunas(): void
    {
        $id = $this->buatTransaksi(100000);
        $this->bayar($id, 100000, 'qris');

        $t = $this->ambilTransaksi($id);
        $this->assertSame('lunas', $t['status_pembayaran']);
        $this->assertSame(100000.0, (float) $t['total_dibayar']);
    }

    public function testPembayaranSebagianMenghasilkanDp(): void
    {
        $id = $this->buatTransaksi(100000);
        $this->bayar($id, 30000, 'tunai');

        $t = $this->ambilTransaksi($id);
        $this->assertSame('dp', $t['status_pembayaran']);
        $this->assertSame(30000.0, (float) $t['total_dibayar']);
    }

    public function testPembayaranKumulatifSampaiLunas(): void
    {
        $id = $this->buatTransaksi(100000);
        $this->bayar($id, 40000, 'tunai');
        $this->bayar($id, 60000, 'transfer');

        $t = $this->ambilTransaksi($id);
        $this->assertSame('lunas', $t['status_pembayaran']);
        $this->assertSame(100000.0, (float) $t['total_dibayar']);
    }

    public function testPembayaranMelebihiSisaDitolak(): void
    {
        $id = $this->buatTransaksi(100000);
        $this->bayar($id, 80000, 'tunai');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('melebihi sisa tagihan');

        $this->bayar($id, 30000, 'tunai');
    }

    // =====================================================================
    // B. EDIT TOTAL — sisa vs LEBIH BAYAR (schema tetap belum_bayar/dp/lunas)
    // =====================================================================

    public function testTotalBaruLebihBesarTetapOutstanding(): void
    {
        $id = $this->buatTransaksi(100000);
        $this->bayar($id, 80000, 'tunai');

        // total transaksi diedit naik jadi 120.000
        $this->transaksiModel->update($id, ['grand_total' => 120000]);
        $sinkron = $this->transaksiModel->sinkronkanPembayaran($id);

        $this->assertSame('dp', $sinkron['status_pembayaran']);
        $this->assertSame(80000.0, (float) $sinkron['total_dibayar']);

        $sisa = max(0, 120000 - $sinkron['total_dibayar']);
        $this->assertSame(40000.0, (float) $sisa);
    }

    public function testTotalBaruSamaDenganDibayarJadiLunasTanpaLebihBayar(): void
    {
        $id = $this->buatTransaksi(100000);
        $this->bayar($id, 80000, 'tunai');

        $this->transaksiModel->update($id, ['grand_total' => 80000]);
        $sinkron = $this->transaksiModel->sinkronkanPembayaran($id);

        $this->assertSame('lunas', $sinkron['status_pembayaran']);
        $kelebihan = max(0, $sinkron['total_dibayar'] - 80000);
        $this->assertSame(0.0, (float) $kelebihan);
    }

    public function testTotalBaruLebihKecilDariDibayarMenghasilkanLebihBayar(): void
    {
        $id = $this->buatTransaksi(100000);
        $this->bayar($id, 80000, 'tunai');

        // total transaksi diedit turun jadi 70.000 -> kondisi LEBIH BAYAR
        $this->transaksiModel->update($id, ['grand_total' => 70000]);
        $sinkron = $this->transaksiModel->sinkronkanPembayaran($id);

        // Schema tidak punya "lebih_bayar": status tetap 'lunas' ...
        $this->assertSame('lunas', $sinkron['status_pembayaran']);
        // ... dan pembayaran kumulatif TIDAK berubah karena edit total
        $this->assertSame(80000.0, (float) $sinkron['total_dibayar']);
        // ... lebih bayar = indikator hitungan
        $kelebihan = max(0, $sinkron['total_dibayar'] - 70000);
        $this->assertSame(10000.0, (float) $kelebihan);
        // sisa tidak boleh negatif
        $this->assertSame(0.0, (float) max(0, 70000 - $sinkron['total_dibayar']));
    }

    // =====================================================================
    // C. STATUS TRANSAKSI — enforcement PROSES -> SELESAI
    // =====================================================================

    public function testSelesaiDitolakKetikaBelumBayarWalauAdmin(): void
    {
        $id = $this->buatTransaksi(100000);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('belum lunas');

        $this->transaksiModel->ubahStatus($id, 'selesai', true);
    }

    public function testSelesaiDitolakKetikaDpWalauAdmin(): void
    {
        $id = $this->buatTransaksi(100000);
        $this->bayar($id, 50000, 'tunai');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('belum lunas');

        $this->transaksiModel->ubahStatus($id, 'selesai', true);
    }

    public function testSelesaiDitolakUntukKasirLewatJalurUmumWalauLunas(): void
    {
        // Jalur umum = Api::ubahStatus (Detail/Daftar) -> $isAdmin=false,
        // $izinSelesaikanKonteks=false (default).
        $id = $this->buatTransaksi(100000);
        $this->bayar($id, 100000, 'tunai');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Hanya admin');

        $this->transaksiModel->ubahStatus($id, 'selesai', false);
    }

    public function testSelesaiDiterimaUntukAdminKetikaLunas(): void
    {
        $id = $this->buatTransaksi(100000);
        $this->bayar($id, 100000, 'tunai');

        $hasil = $this->transaksiModel->ubahStatus($id, 'selesai', true);

        $this->assertTrue($hasil);
        $this->assertSame('selesai', $this->ambilTransaksi($id)['status']);
    }

    public function testSelesaiDiterimaLewatKonteksKasirKetikaLunas(): void
    {
        // Kapabilitas workflow kasir/index.php: $izinSelesaikanKonteks=true.
        $id = $this->buatTransaksi(100000);
        $this->bayar($id, 100000, 'tunai');

        $hasil = $this->transaksiModel->ubahStatus($id, 'selesai', false, true);

        $this->assertTrue($hasil);
        $this->assertSame('selesai', $this->ambilTransaksi($id)['status']);
    }

    public function testKonteksKasirTetapTidakBisaSelesaikanDp(): void
    {
        // Kapabilitas konteks TIDAK melewati syarat LUNAS.
        $id = $this->buatTransaksi(100000);
        $this->bayar($id, 50000, 'tunai');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('belum lunas');

        $this->transaksiModel->ubahStatus($id, 'selesai', false, true);
    }

    // =====================================================================
    // D. REGRESSION — koreksi metode tetap jalan lewat chokepoint yang sama
    // =====================================================================

    public function testKoreksiMetodeLewatChokepointTetapValid(): void
    {
        $id = $this->buatTransaksi(100000);
        $this->bayar($id, 100000, 'tunai');

        // tandai pembayaran lama reversed, catat pengganti (pola koreksiPembayaran)
        $this->pembayaranModel->where('transaksi_id', $id)->set(['status' => 'reversed'])->update();
        $this->bayar($id, 100000, 'qris');

        $t = $this->ambilTransaksi($id);
        $this->assertSame('lunas', $t['status_pembayaran']);
        $this->assertSame(100000.0, (float) $t['total_dibayar']);
    }

    public function testMetodeValidSesuaiKonstanta(): void
    {
        $this->assertSame(['tunai', 'qris', 'transfer'], PembayaranModel::METODE);
    }
}
