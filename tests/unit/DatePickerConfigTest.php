<?php

namespace Tests\Unit;

use Config\DatePicker;
use PHPUnit\Framework\TestCase;

final class DatePickerConfigTest extends TestCase
{
    private function config(): DatePicker
    {
        return new DatePicker();
    }

    public function testLocaleUsesIndonesianFormatAndLabels(): void
    {
        $locale = $this->config()->locale;

        $this->assertSame('DD/MM/YYYY', $locale['format']);
        $this->assertSame(' - ', $locale['separator']);
        $this->assertSame('Terapkan', $locale['applyLabel']);
        $this->assertSame('Batal', $locale['cancelLabel']);
        $this->assertSame('Dari', $locale['fromLabel']);
        $this->assertSame('Sampai', $locale['toLabel']);
        $this->assertSame('Custom', $locale['customRangeLabel']);
        $this->assertSame('M', $locale['weekLabel']);
        $this->assertSame(1, $locale['firstDay']);
    }

    public function testLocaleHasSevenThreeLetterDayNamesStartingSunday(): void
    {
        $days = $this->config()->locale['daysOfWeek'];

        $this->assertCount(7, $days);
        $this->assertSame(['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'], $days);
    }

    public function testLocaleHasTwelveIndonesianMonthNames(): void
    {
        $months = $this->config()->locale['monthNames'];

        $this->assertCount(12, $months);
        $this->assertSame('Januari', $months[0]);
        $this->assertSame('Desember', $months[11]);
    }

    public function testPresetOrderAndLabels(): void
    {
        $presets = $this->config()->presets;

        $this->assertSame(
            ['today', 'yesterday', 'last7', 'last30', 'thisMonth', 'lastMonth'],
            array_column($presets, 'key')
        );
        $this->assertSame(
            ['Hari Ini', 'Kemarin', '7 Hari Terakhir', '30 Hari Terakhir', 'Bulan Ini', 'Bulan Lalu'],
            array_column($presets, 'label')
        );
    }

    public function testBulanYmPadsMonthToTwoDigits(): void
    {
        $this->assertSame('2026-01', DatePicker::bulanYm(1, 2026));
        $this->assertSame('2026-10', DatePicker::bulanYm(10, 2026));
        $this->assertSame('2026-12', DatePicker::bulanYm(12, 2026));
    }
}
