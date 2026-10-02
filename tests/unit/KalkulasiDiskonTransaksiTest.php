<?php

namespace Tests\Unit;

use App\Services\KalkulasiDiskonTransaksi;
use PHPUnit\Framework\TestCase;

/**
 * Pins the discount and grand_total rules in KalkulasiDiskonTransaksi.
 * Key rules: the two discount modes are never combined, discount never exceeds
 * subtotal, and grand_total is floored to the nearest 100 (selisih_pembulatan = remainder).
 */
final class KalkulasiDiskonTransaksiTest extends TestCase
{
    public function testManualDiscountIsAppliedAsIs(): void
    {
        $r = KalkulasiDiskonTransaksi::hitung(100000, null, 5000);

        $this->assertEquals(5000, $r['diskon']);
        $this->assertEquals(95000, $r['grand_total']);
        $this->assertEquals(0, $r['selisih_pembulatan']);
        $this->assertNull($r['diskon_pelanggan_persen']);
    }

    public function testCustomerPercentDiscountIsComputedOnTheServer(): void
    {
        $r = KalkulasiDiskonTransaksi::hitung(100000, 10.0, 0);

        $this->assertEquals(10000, $r['diskon']);
        $this->assertEquals(90000, $r['grand_total']);
        $this->assertEquals(10.0, $r['diskon_pelanggan_persen']);
    }

    public function testCustomerPercentIgnoresManualDiscountToPreventDoubleDiscount(): void
    {
        $r = KalkulasiDiskonTransaksi::hitung(100000, 10.0, 99999);

        $this->assertEquals(10000, $r['diskon']);
        $this->assertEquals(90000, $r['grand_total']);
    }

    public function testZeroPercentStillCountsAsCustomerMode(): void
    {
        // 0.0 is "active customer discount of 0%", not "no customer discount" (null).
        $r = KalkulasiDiskonTransaksi::hitung(100000, 0.0, 5000);

        $this->assertEquals(0, $r['diskon']);
        $this->assertEquals(100000, $r['grand_total']);
    }

    public function testPercentDiscountIsRoundedThenGrandTotalFloored(): void
    {
        // 12345 * 15% = 1851.75 -> 1852; 12345 - 1852 = 10493 -> floor to 10400, remainder 93.
        $r = KalkulasiDiskonTransaksi::hitung(12345, 15.0, 0);

        $this->assertEquals(1852, $r['diskon']);
        $this->assertEquals(10400, $r['grand_total']);
        $this->assertEquals(93, $r['selisih_pembulatan']);
    }

    public function testGrandTotalIsFlooredToHundredsWithoutDiscount(): void
    {
        $r = KalkulasiDiskonTransaksi::hitung(12345, null, 0);

        $this->assertEquals(12300, $r['grand_total']);
        $this->assertEquals(45, $r['selisih_pembulatan']);
    }

    public function testOutOfRangeCustomerPercentIsClamped(): void
    {
        $over = KalkulasiDiskonTransaksi::hitung(100000, 150.0, 0);
        $this->assertEquals(100.0, $over['diskon_pelanggan_persen']);
        $this->assertEquals(100000, $over['diskon']);
        $this->assertEquals(0, $over['grand_total']);

        $under = KalkulasiDiskonTransaksi::hitung(100000, -5.0, 0);
        $this->assertEquals(0.0, $under['diskon_pelanggan_persen']);
        $this->assertEquals(0, $under['diskon']);
        $this->assertEquals(100000, $under['grand_total']);
    }

    public function testManualDiscountIsCappedAtSubtotal(): void
    {
        $r = KalkulasiDiskonTransaksi::hitung(100000, null, 200000);

        $this->assertEquals(100000, $r['diskon']);
        $this->assertEquals(0, $r['grand_total']);
    }

    public function testNegativeManualDiscountIsTreatedAsZero(): void
    {
        $r = KalkulasiDiskonTransaksi::hitung(100000, null, -5000);

        $this->assertEquals(0, $r['diskon']);
        $this->assertEquals(100000, $r['grand_total']);
    }

    public function testNegativeSubtotalIsTreatedAsZero(): void
    {
        $r = KalkulasiDiskonTransaksi::hitung(-500, null, 0);

        $this->assertEquals(0, $r['diskon']);
        $this->assertEquals(0, $r['grand_total']);
        $this->assertEquals(0, $r['selisih_pembulatan']);
    }

    public function testMoneyIsConservedAcrossManyInputs(): void
    {
        // Invariant: grand_total + diskon + selisih_pembulatan == subtotal (subtotal >= 0).
        foreach ([0, 1, 99, 100, 12345, 99999, 100000, 250050] as $subtotal) {
            foreach ([null, 0.0, 7.5, 10.0, 100.0] as $persen) {
                foreach ([0, 500, 5050, 1000000] as $manual) {
                    $r = KalkulasiDiskonTransaksi::hitung($subtotal, $persen, $manual);

                    $this->assertEqualsWithDelta(
                        $subtotal,
                        $r['grand_total'] + $r['diskon'] + $r['selisih_pembulatan'],
                        0.0001,
                        "subtotal={$subtotal} persen=" . var_export($persen, true) . " manual={$manual}"
                    );
                    $this->assertGreaterThanOrEqual(0, $r['grand_total']);
                    $this->assertLessThan(100, $r['selisih_pembulatan']);
                    $this->assertLessThanOrEqual($subtotal, $r['diskon']);
                }
            }
        }
    }
}
