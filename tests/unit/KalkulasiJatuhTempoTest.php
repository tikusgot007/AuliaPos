<?php

namespace Tests\Unit;

use App\Services\KalkulasiJatuhTempo;
use PHPUnit\Framework\TestCase;

final class KalkulasiJatuhTempoTest extends TestCase
{
    public function testAddsTempoDaysToTransactionDate(): void
    {
        $this->assertSame('2026-10-08', KalkulasiJatuhTempo::hitung('2026-10-01', 7));
    }

    public function testZeroTempoKeepsSameDate(): void
    {
        $this->assertSame('2026-10-01', KalkulasiJatuhTempo::hitung('2026-10-01', 0));
    }

    public function testRollsOverMonthAndYear(): void
    {
        $this->assertSame('2026-02-02', KalkulasiJatuhTempo::hitung('2026-01-30', 3));
        $this->assertSame('2027-01-03', KalkulasiJatuhTempo::hitung('2026-12-30', 4));
    }

    public function testLeapYearFebruary(): void
    {
        $this->assertSame('2028-02-29', KalkulasiJatuhTempo::hitung('2028-02-27', 2));
        $this->assertSame('2026-03-01', KalkulasiJatuhTempo::hitung('2026-02-27', 2));
    }

    public function testNotOverdueOnTheDueDate(): void
    {
        // Due 2026-10-08; overdue only when today is strictly after the due date.
        $this->assertFalse(KalkulasiJatuhTempo::isOverdue('2026-10-01', 7, '2026-10-08'));
    }

    public function testNotOverdueBeforeTheDueDate(): void
    {
        $this->assertFalse(KalkulasiJatuhTempo::isOverdue('2026-10-01', 7, '2026-10-07'));
    }

    public function testOverdueTheDayAfterTheDueDate(): void
    {
        $this->assertTrue(KalkulasiJatuhTempo::isOverdue('2026-10-01', 7, '2026-10-09'));
    }
}
