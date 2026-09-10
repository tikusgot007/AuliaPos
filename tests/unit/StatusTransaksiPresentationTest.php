<?php

use PHPUnit\Framework\TestCase;

/**
 * Helper presentation status transaksi (app/Helpers/order_helper.php).
 * Fungsi murni tanpa dependensi framework -> TestCase polos, file helper
 * di-require langsung (pola sama dengan StatusPembayaranPresentationTest).
 *
 * Merepresentasikan behavior EXISTING dari transaksi/index.php:
 *   badge : map + `?? 'secondary'`
 *   label : map Title Case + `?? strtoupper(str_replace('_', ' ', $status))`
 *
 * transaksi/detail.php sebelumnya memakai fallback badge 'success' dan
 * label `strtoupper($status)`; keduanya disatukan ke bentuk index.php
 * sebagai keputusan presentation yang disengaja (lihat docblock helper).
 *
 * @internal
 */
final class StatusTransaksiPresentationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../app/Helpers/order_helper.php';
    }

    public function testBadgeClassEmpatStatusDomain(): void
    {
        $this->assertSame('warning', status_transaksi_badge_class('proses'));
        $this->assertSame('primary', status_transaksi_badge_class('selesai'));
        $this->assertSame('secondary', status_transaksi_badge_class('batal'));
        $this->assertSame('dark', status_transaksi_badge_class('mangkrak'));
    }

    public function testBadgeClassUnknownDanEmptyJadiSecondary(): void
    {
        $this->assertSame('secondary', status_transaksi_badge_class('diambil'));
        $this->assertSame('secondary', status_transaksi_badge_class('foo'));
        $this->assertSame('secondary', status_transaksi_badge_class(''));
    }

    public function testLabelEmpatStatusDomainTitleCase(): void
    {
        $this->assertSame('Proses', status_transaksi_label('proses'));
        $this->assertSame('Selesai', status_transaksi_label('selesai'));
        $this->assertSame('Batal', status_transaksi_label('batal'));
        $this->assertSame('Mangkrak', status_transaksi_label('mangkrak'));
    }

    public function testLabelUnknownFallbackStrtoupper(): void
    {
        $this->assertSame('DIAMBIL', status_transaksi_label('diambil'));
        $this->assertSame('FOO BAR', status_transaksi_label('foo_bar'));
        $this->assertSame('', status_transaksi_label(''));
    }
}
