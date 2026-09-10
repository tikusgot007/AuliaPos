<?php

use App\Services\KalkulasiStatusPembayaran;
use PHPUnit\Framework\TestCase;

/**
 * Sengaja pakai PHPUnit\Framework\TestCase polos (bukan CIUnitTestCase)
 * -- App\Services\KalkulasiStatusPembayaran murni pure/stateless (tidak
 * menyentuh DB/session/request), jadi tidak perlu bootstrap CodeIgniter
 * penuh untuk mengujinya. Pola sama dengan
 * tests/unit/KalkulasiDiskonTransaksiTest.php.
 *
 * Test merepresentasikan behavior EXISTING dari ekspresi lama di
 * TransaksiModel::sinkronkanPembayaran() & RepairTotalDibayar:
 *
 *     $dibayar >= $grandTotal ? 'lunas' : ($dibayar > 0 ? 'dp' : 'belum_bayar')
 *
 * @internal
 */
final class KalkulasiStatusPembayaranTest extends TestCase
{
    public function testDibayarSamaDenganGrandTotalLunas(): void
    {
        $this->assertSame('lunas', KalkulasiStatusPembayaran::hitung(100000, 100000));
    }

    public function testDibayarMelebihiGrandTotalLunas(): void
    {
        // Kelebihan bayar tetap LUNAS (>=).
        $this->assertSame('lunas', KalkulasiStatusPembayaran::hitung(150000, 100000));
    }

    public function testDibayarSebagianDp(): void
    {
        $this->assertSame('dp', KalkulasiStatusPembayaran::hitung(50000, 100000));
    }

    public function testDibayarSedikitDiAtasNolTetapDp(): void
    {
        $this->assertSame('dp', KalkulasiStatusPembayaran::hitung(1, 100000));
    }

    public function testDibayarNolBelumBayar(): void
    {
        $this->assertSame('belum_bayar', KalkulasiStatusPembayaran::hitung(0, 100000));
    }

    /**
     * Boundary grand_total == 0: implementasi lama -> 0 >= 0 -> LUNAS.
     * Behavior ini DIPERTAHANKAN (bukan tebakan).
     */
    public function testGrandTotalNolDenganDibayarNolLunas(): void
    {
        $this->assertSame('lunas', KalkulasiStatusPembayaran::hitung(0, 0));
    }

    public function testGrandTotalNolDenganDibayarPositifLunas(): void
    {
        $this->assertSame('lunas', KalkulasiStatusPembayaran::hitung(5000, 0));
    }

    /**
     * Dibayar negatif (secara teknis bisa masuk kalau ada data/koreksi
     * aneh): bukan > 0 -> BELUM_BAYAR, selama grand_total > dibayar.
     */
    public function testDibayarNegatifBelumBayar(): void
    {
        $this->assertSame('belum_bayar', KalkulasiStatusPembayaran::hitung(-1000, 100000));
        $this->assertSame('belum_bayar', KalkulasiStatusPembayaran::hitung(-1000, 0));
    }

    /**
     * Kalau grand_total juga <= dibayar (mis. grand_total negatif),
     * ekspresi lama tetap menghasilkan LUNAS. Dipertahankan apa adanya.
     */
    public function testGrandTotalNegatifLunas(): void
    {
        $this->assertSame('lunas', KalkulasiStatusPembayaran::hitung(0, -100));
        $this->assertSame('lunas', KalkulasiStatusPembayaran::hitung(-50, -100));
    }

    public function testKonstantaAdalahStringYangDipakaiDb(): void
    {
        $this->assertSame('lunas', KalkulasiStatusPembayaran::LUNAS);
        $this->assertSame('dp', KalkulasiStatusPembayaran::DP);
        $this->assertSame('belum_bayar', KalkulasiStatusPembayaran::BELUM_BAYAR);
    }
}
