<?php

namespace Tests\Unit;

use App\Services\KalkulasiStatusPembayaran;
use PHPUnit\Framework\TestCase;

/**
 * Pins the CURRENT business rule documented in KalkulasiStatusPembayaran:
 * lunas when dibayar >= grand_total, dp when 0 < dibayar < grand_total,
 * belum_bayar otherwise. Boundaries are intentional; do not "fix" them here.
 */
final class KalkulasiStatusPembayaranTest extends TestCase
{
    public function testBelumBayarWhenNothingPaid(): void
    {
        $this->assertSame(KalkulasiStatusPembayaran::BELUM_BAYAR, KalkulasiStatusPembayaran::hitung(0, 100000));
    }

    public function testDpWhenPartiallyPaid(): void
    {
        $this->assertSame(KalkulasiStatusPembayaran::DP, KalkulasiStatusPembayaran::hitung(50000, 100000));
    }

    public function testSmallestPositiveAmountIsStillDp(): void
    {
        $this->assertSame(KalkulasiStatusPembayaran::DP, KalkulasiStatusPembayaran::hitung(0.01, 100000));
    }

    public function testLunasWhenPaidExactly(): void
    {
        $this->assertSame(KalkulasiStatusPembayaran::LUNAS, KalkulasiStatusPembayaran::hitung(100000, 100000));
    }

    public function testLunasWhenOverpaid(): void
    {
        $this->assertSame(KalkulasiStatusPembayaran::LUNAS, KalkulasiStatusPembayaran::hitung(150000, 100000));
    }

    public function testZeroGrandTotalIsLunas(): void
    {
        // 0 >= 0: a fully discounted transaksi needs no payment.
        $this->assertSame(KalkulasiStatusPembayaran::LUNAS, KalkulasiStatusPembayaran::hitung(0, 0));
    }

    public function testNegativePaymentIsBelumBayar(): void
    {
        $this->assertSame(KalkulasiStatusPembayaran::BELUM_BAYAR, KalkulasiStatusPembayaran::hitung(-10, 100000));
    }

    public function testNegativePaymentCanStillBeLunasWhenGrandTotalIsLower(): void
    {
        // Values are intentionally not clamped (documented in the service).
        $this->assertSame(KalkulasiStatusPembayaran::LUNAS, KalkulasiStatusPembayaran::hitung(-10, -20));
    }

    public function testStatusStringsAreStable(): void
    {
        // These strings are persisted in status_pembayaran; changing them is a data migration.
        $this->assertSame('lunas', KalkulasiStatusPembayaran::LUNAS);
        $this->assertSame('dp', KalkulasiStatusPembayaran::DP);
        $this->assertSame('belum_bayar', KalkulasiStatusPembayaran::BELUM_BAYAR);
    }
}
