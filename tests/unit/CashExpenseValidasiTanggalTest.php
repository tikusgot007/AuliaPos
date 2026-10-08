<?php

namespace Tests\Unit;

use App\Models\CashExpenseModel;
use PHPUnit\Framework\TestCase;

/**
 * TODO-BL10: pengeluaran kas tidak boleh tanggal masa depan (AC-3), tapi
 * HARI INI dan tanggal lampau tetap diperbolehkan di validasi ini (AC-4) --
 * beda dari ClosingKasModel::validasiTanggal(), yang justru melarang hari
 * ini. Pure function, tidak menyentuh DB -- lihat
 * docs/design/2026-10-08-audit-validasi-kas-keluar.md Section 6.
 *
 * @internal
 */
final class CashExpenseValidasiTanggalTest extends TestCase
{
    public function testFutureDateRejected(): void
    {
        $error = CashExpenseModel::validasiTanggal('2026-10-09 10:00:00', '2026-10-08');

        $this->assertNotNull($error);
        $this->assertStringContainsString('masa depan', $error);
    }

    public function testTodayAndPastDateAccepted(): void
    {
        $this->assertNull(CashExpenseModel::validasiTanggal('2026-10-08 10:00:00', '2026-10-08'));
        $this->assertNull(CashExpenseModel::validasiTanggal('2026-10-01 10:00:00', '2026-10-08'));
    }

    public function testEmptyOrInvalidDateRejected(): void
    {
        $this->assertNotNull(CashExpenseModel::validasiTanggal(null, '2026-10-08'));
        $this->assertNotNull(CashExpenseModel::validasiTanggal('', '2026-10-08'));
        $this->assertNotNull(CashExpenseModel::validasiTanggal('bukan-tanggal', '2026-10-08'));
    }

    public function testDefaultsToTodayWhenHariIniOmitted(): void
    {
        // strtotime('+1 day') relative to the real "today" must be rejected
        // without needing to pass $hariIni explicitly.
        $besok = date('Y-m-d H:i:s', strtotime('+1 day'));

        $this->assertNotNull(CashExpenseModel::validasiTanggal($besok));
    }
}
