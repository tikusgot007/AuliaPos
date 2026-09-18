<?php

use App\Libraries\PhoneNumber;
use PHPUnit\Framework\TestCase;

/**
 * PhoneNumber::normalize() (app/Libraries/PhoneNumber.php) -- Task
 * Group 1.5 (Customer Identity & Conversation Reconciliation).
 * Fungsi murni, tanpa dependensi framework/database -> TestCase polos
 * (pola sama dengan TanggalSingkatTest/KalkulasiDiskonTransaksiTest).
 *
 * Perilakunya SENGAJA identik dengan
 * Inbox::normalizePhoneToJid() versi lama (logika dipindah ke sini
 * tanpa diubah) -- test ini juga jadi regression guard untuk
 * pemindahan itu.
 *
 * @internal
 */
final class PhoneNumberTest extends TestCase
{
    public function testFormat08DinormalisasiKe62(): void
    {
        $this->assertSame('628563324637', PhoneNumber::normalize('08563324637'));
    }

    public function testFormat62ApaAdanya(): void
    {
        $this->assertSame('628563324637', PhoneNumber::normalize('628563324637'));
    }

    public function testFormatPlus62DibuangTandaPlusnya(): void
    {
        $this->assertSame('628563324637', PhoneNumber::normalize('+628563324637'));
    }

    public function testKetigaFormatMenghasilkanNilaiCanonicalYangSama(): void
    {
        // Test Case G (Task Group 1.5): "08563324637", "+628563324637",
        // "628563324637" HARUS dikenali sebagai nomor yang sama persis.
        $a = PhoneNumber::normalize('08563324637');
        $b = PhoneNumber::normalize('+628563324637');
        $c = PhoneNumber::normalize('628563324637');

        $this->assertSame($a, $b);
        $this->assertSame($b, $c);
        $this->assertSame('628563324637', $a);
    }

    public function testSpasiDanStripDiabaikan(): void
    {
        $this->assertSame('628563324637', PhoneNumber::normalize('0856-3324-637'));
        $this->assertSame('628563324637', PhoneNumber::normalize('0856 3324 637'));
        $this->assertSame('628563324637', PhoneNumber::normalize('+62 856-3324-637'));
    }

    public function testFormatTidakDikenaliMengembalikanNull(): void
    {
        // Tidak diawali 0/62/+62 -- SENGAJA tidak ditebak.
        $this->assertNull(PhoneNumber::normalize('123456'));
        $this->assertNull(PhoneNumber::normalize('abcdef'));
        $this->assertNull(PhoneNumber::normalize(''));
    }

    public function testNomorTerlaluPendekDitolak(): void
    {
        $this->assertNull(PhoneNumber::normalize('0812'));
    }

    public function testNomorTerlaluPanjangDitolak(): void
    {
        $this->assertNull(PhoneNumber::normalize('0812345678901234567890'));
    }
}
