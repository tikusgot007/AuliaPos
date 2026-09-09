<?php

use App\Services\KalkulasiDiskonTransaksi;
use PHPUnit\Framework\TestCase;

/**
 * Sengaja pakai PHPUnit\Framework\TestCase polos (bukan CIUnitTestCase)
 * -- App\Services\KalkulasiDiskonTransaksi murni pure/stateless (tidak
 * menyentuh DB/session/apa pun dari framework), jadi tidak perlu
 * bootstrap CodeIgniter penuh untuk mengujinya.
 *
 * Menutupi edge case dari spesifikasi fitur "Diskon Pelanggan Otomatis":
 * 1-5 (nilai diskon: 0/NULL/5/10/100), 9-10 (checkbox on/off = mode
 * pelanggan/manual), 15 (tidak ada double discount).
 *
 * @internal
 */
final class KalkulasiDiskonTransaksiTest extends TestCase
{
    // ----------------------------------------------------------
    // Mode MANUAL ($persenPelanggan = null) -- perilaku existing,
    // TIDAK BOLEH berubah sama sekali oleh fitur ini.
    // ----------------------------------------------------------

    public function testManualTanpaDiskon(): void
    {
        $r = KalkulasiDiskonTransaksi::hitung(100000, null, 0);

        $this->assertSame(0.0, $r['diskon']);
        $this->assertNull($r['diskon_pelanggan_persen']);
        $this->assertSame(100000.0, $r['grand_total']);
    }

    public function testManualDenganNominal(): void
    {
        $r = KalkulasiDiskonTransaksi::hitung(100000, null, 15000);

        $this->assertSame(15000.0, $r['diskon']);
        $this->assertNull($r['diskon_pelanggan_persen']);
        $this->assertSame(85000.0, $r['grand_total']);
    }

    public function testManualDiskonTidakBolehMelebihiSubtotal(): void
    {
        $r = KalkulasiDiskonTransaksi::hitung(50000, null, 999999);

        $this->assertSame(50000.0, $r['diskon']);
        $this->assertSame(0.0, $r['grand_total']);
    }

    public function testManualDiskonNegatifDianggapNol(): void
    {
        $r = KalkulasiDiskonTransaksi::hitung(50000, null, -1000);

        $this->assertSame(0.0, $r['diskon']);
    }

    // ----------------------------------------------------------
    // Mode DISKON PELANGGAN ($persenPelanggan tidak null) --
    // edge case 1-5 dari spesifikasi.
    // ----------------------------------------------------------

    public function testPelangganDiskon0Persen(): void
    {
        // Diskon 0% -- checkbox seharusnya tidak pernah aktifkan mode
        // ini dari sisi UI (lihat terapkanUIDiskonPelanggan() di JS),
        // tapi kalkulasi tetap harus benar kalau somehow dipanggil.
        $r = KalkulasiDiskonTransaksi::hitung(100000, 0, 0);

        $this->assertSame(0.0, $r['diskon']);
        $this->assertSame(0.0, $r['diskon_pelanggan_persen']);
        $this->assertSame(100000.0, $r['grand_total']);
    }

    public function testPelangganDiskon5Persen(): void
    {
        $r = KalkulasiDiskonTransaksi::hitung(200000, 5, 0);

        $this->assertSame(10000.0, $r['diskon']); // 5% * 200000
        $this->assertSame(5.0, $r['diskon_pelanggan_persen']);
        $this->assertSame(190000.0, $r['grand_total']);
    }

    public function testPelangganDiskon10Persen(): void
    {
        // Skenario persis contoh di spesifikasi: Budi, diskon 10%.
        $r = KalkulasiDiskonTransaksi::hitung(150000, 10, 0);

        $this->assertSame(15000.0, $r['diskon']);
        $this->assertSame(10.0, $r['diskon_pelanggan_persen']);
        $this->assertSame(135000.0, $r['grand_total']);
    }

    public function testPelangganDiskon100Persen(): void
    {
        $r = KalkulasiDiskonTransaksi::hitung(75000, 100, 0);

        $this->assertSame(75000.0, $r['diskon']);
        $this->assertSame(100.0, $r['diskon_pelanggan_persen']);
        $this->assertSame(0.0, $r['grand_total']);
    }

    public function testPelangganPersenDiClampKeRentangWajar(): void
    {
        // Jaga-jaga data master di luar rentang 0-100 (harusnya tidak
        // pernah terjadi kalau validasi form pelanggan benar).
        $r = KalkulasiDiskonTransaksi::hitung(100000, 150, 0);
        $this->assertSame(100.0, $r['diskon_pelanggan_persen']);
        $this->assertSame(100000.0, $r['diskon']);

        $r2 = KalkulasiDiskonTransaksi::hitung(100000, -20, 0);
        $this->assertSame(0.0, $r2['diskon_pelanggan_persen']);
        $this->assertSame(0.0, $r2['diskon']);
    }

    // ----------------------------------------------------------
    // TIDAK ADA DOUBLE DISCOUNT (edge case 15) -- $diskonManual
    // SELALU diabaikan total begitu $persenPelanggan aktif, apa pun
    // nilainya.
    // ----------------------------------------------------------

    public function testDiskonManualDiabaikanSaatModePelangganAktif(): void
    {
        // Kalau ada bug di pemanggil yang tetap mengirim diskon manual
        // BESAR sekalipun checkbox pelanggan aktif, hasilnya harus
        // TETAP murni dari persen pelanggan -- bukan dijumlah/diganti.
        $r = KalkulasiDiskonTransaksi::hitung(100000, 10, 500000);

        $this->assertSame(10000.0, $r['diskon']); // murni 10% dari subtotal
        $this->assertNotSame(500000.0, $r['diskon']);
        $this->assertSame(90000.0, $r['grand_total']);
    }

    // ----------------------------------------------------------
    // Pembulatan (konsisten dengan hitungPembulatan() di
    // kasir-shared.js -- floor ke ratusan terdekat).
    // ----------------------------------------------------------

    public function testPembulatanKeBawahRatusanTerdekat(): void
    {
        $r = KalkulasiDiskonTransaksi::hitung(100050, 10, 0);
        // subtotal 100050, diskon 10% = 10005 (dibulatkan ke 10005),
        // grand_total_sebelum = 90045 -> floor ke ratusan = 90000,
        // selisih_pembulatan = 45.
        $this->assertSame(10005.0, $r['diskon']);
        $this->assertSame(90000.0, $r['grand_total']);
        $this->assertSame(45.0, $r['selisih_pembulatan']);
    }

    public function testSubtotalNolTidakError(): void
    {
        $r = KalkulasiDiskonTransaksi::hitung(0, 10, 0);

        $this->assertSame(0.0, $r['diskon']);
        $this->assertSame(0.0, $r['grand_total']);
    }
}
