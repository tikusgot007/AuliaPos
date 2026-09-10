<?php

use PHPUnit\Framework\TestCase;

/**
 * Helper presentation status_pembayaran (app/Helpers/order_helper.php).
 * Fungsi murni tanpa dependensi framework -> pakai TestCase polos,
 * file helper di-require langsung (pola sama dengan
 * KalkulasiStatusPembayaranTest yang juga tidak butuh bootstrap CI).
 *
 * Merepresentasikan behavior EXISTING dari tiga view yang sebelumnya
 * menduplikasi:
 *   badge : map + `?? 'secondary'`
 *   label : strtoupper(str_replace('_', ' ', $status))
 *
 * @internal
 */
final class StatusPembayaranPresentationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../app/Helpers/order_helper.php';
    }

    public function testBadgeClassTigaStatusUtama(): void
    {
        $this->assertSame('danger', status_pembayaran_badge_class('belum_bayar'));
        $this->assertSame('warning', status_pembayaran_badge_class('dp'));
        $this->assertSame('success', status_pembayaran_badge_class('lunas'));
    }

    public function testBadgeClassStatusTakDikenalJadiSecondary(): void
    {
        // Persis perilaku `map[$status] ?? 'secondary'` di view.
        $this->assertSame('secondary', status_pembayaran_badge_class('mangkrak'));
        $this->assertSame('secondary', status_pembayaran_badge_class(''));
    }

    public function testLabelTigaStatusUtama(): void
    {
        $this->assertSame('BM', status_pembayaran_label('belum_bayar'));
        $this->assertSame('DP', status_pembayaran_label('dp'));
        $this->assertSame('LUNAS', status_pembayaran_label('lunas'));
    }

    public function testLabelStatusTakDikenalTanpaSpecialCase(): void
    {
        // Tidak ada fallback khusus -- hanya transformasi string apa adanya.
        $this->assertSame('FOO BAR', status_pembayaran_label('foo_bar'));
        $this->assertSame('', status_pembayaran_label(''));
    }
}
