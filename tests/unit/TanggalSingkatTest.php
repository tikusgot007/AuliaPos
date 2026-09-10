<?php

use PHPUnit\Framework\TestCase;

/**
 * Helper tanggal_singkat() (app/Helpers/order_helper.php). Fungsi murni
 * tanpa dependensi framework -> TestCase polos, file helper di-require
 * langsung (pola sama dengan StatusPembayaranPresentationTest &
 * StatusTransaksiPresentationTest).
 *
 * Format: `d M Y` dengan nama bulan 3 huruf (Jan..Des). Input di-
 * strtotime() bila string (semantics sama dengan kode inline lama di
 * transaksi/index.php & tagihan/index.php), atau dipakai apa adanya
 * bila integer Unix timestamp. Tidak mengubah timezone.
 *
 * @internal
 */
final class TanggalSingkatTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../app/Helpers/order_helper.php';
    }

    public function testJanuari(): void
    {
        $this->assertSame('15 Jan 2026', tanggal_singkat('2026-01-15'));
    }

    public function testMei(): void
    {
        $this->assertSame('01 Mei 2026', tanggal_singkat('2026-05-01'));
    }

    public function testAgustusJadiAgt(): void
    {
        $this->assertSame('09 Agt 2026', tanggal_singkat('2026-08-09'));
    }

    public function testSeptemberJadiSep(): void
    {
        $this->assertSame('29 Sep 2026', tanggal_singkat('2026-09-29'));
    }

    public function testDesember(): void
    {
        $this->assertSame('31 Des 2026', tanggal_singkat('2026-12-31'));
    }

    public function testHariSatuDigitTetapZeroPadded(): void
    {
        // Behavior existing: date('d') selalu 2 digit.
        $this->assertSame('05 Mar 2026', tanggal_singkat('2026-03-05'));
    }

    public function testDatetimeStringDiterima(): void
    {
        // Consumer memberi kolom transaksi.tanggal (datetime string).
        $this->assertSame('29 Sep 2026', tanggal_singkat('2026-09-29 14:30:00'));
    }

    public function testUnixTimestampIntegerDipakaiApaAdanya(): void
    {
        $ts = mktime(0, 0, 0, 3, 5, 2026);
        $this->assertSame('05 Mar 2026', tanggal_singkat($ts));
    }

    public function testTahunEmpatDigit(): void
    {
        $this->assertSame('02 Feb 1999', tanggal_singkat('1999-02-02'));
    }
}
