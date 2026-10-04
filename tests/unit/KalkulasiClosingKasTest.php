<?php

namespace Tests\Unit;

use App\Services\KalkulasiClosingKas;
use PHPUnit\Framework\TestCase;

/**
 * TODO-BL02: a stored closing snapshot is final. saldoSistemFinal() must keep
 * the stored value and must NOT evaluate the recompute callable when a snapshot
 * exists (the recompute reads the archive DB and would otherwise run on every
 * edit).
 *
 * @internal
 */
final class KalkulasiClosingKasTest extends TestCase
{
    public function testStoredSnapshotWinsAndRecomputeIsNotCalled(): void
    {
        $called = false;

        $result = KalkulasiClosingKas::saldoSistemFinal(150000.0, function () use (&$called): float {
            $called = true;

            return 999.0;
        });

        $this->assertSame(150000.0, $result);
        $this->assertFalse($called, 'Recompute must be skipped when a snapshot exists.');
    }

    public function testNewClosingUsesRecompute(): void
    {
        $this->assertSame(999.0, KalkulasiClosingKas::saldoSistemFinal(null, static fn(): float => 999.0));
    }

    public function testZeroSnapshotIsStillAuthoritative(): void
    {
        $this->assertSame(0.0, KalkulasiClosingKas::saldoSistemFinal(0.0, static fn(): float => 123.0));
    }
}
