<?php

namespace Config;

/**
 * Canonical configuration for every date / range / period picker in the app.
 *
 * This is the single source of truth for the bootstrap-daterangepicker locale
 * and preset list. The layout emits it as `window.AULIA_DATEPICKER` and the
 * shared helper `public/assets/js/date-range.js` consumes it, so no view
 * re-declares locale or presets (see docs/design/2026-10-02-*).
 *
 * Kept as a plain class (not BaseConfig) so it can be unit-tested without
 * booting the framework — see tests/unit/DatePickerConfigTest.php.
 */
class DatePicker
{
    /**
     * Bootstrap-daterangepicker locale (Bahasa Indonesia).
     *
     * @var array<string, mixed>
     */
    public array $locale = [
        'format'            => 'DD/MM/YYYY',
        'separator'         => ' - ',
        'applyLabel'        => 'Terapkan',
        'cancelLabel'       => 'Batal',
        'fromLabel'         => 'Dari',
        'toLabel'           => 'Sampai',
        'customRangeLabel'  => 'Custom',
        'weekLabel'         => 'M',
        'daysOfWeek'        => ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'],
        'monthNames'        => [
            'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
        ],
        'firstDay'          => 1,
    ];

    /**
     * Ordered preset list. `key` is resolved to a moment range by the JS
     * helper; `label` is what the user sees.
     *
     * @var list<array{key: string, label: string}>
     */
    public array $presets = [
        ['key' => 'today',     'label' => 'Hari Ini'],
        ['key' => 'yesterday', 'label' => 'Kemarin'],
        ['key' => 'last7',     'label' => '7 Hari Terakhir'],
        ['key' => 'last30',    'label' => '30 Hari Terakhir'],
        ['key' => 'thisMonth', 'label' => 'Bulan Ini'],
        ['key' => 'lastMonth', 'label' => 'Bulan Lalu'],
    ];

    /**
     * Build a `YYYY-MM` value from a month (1-12) and a year.
     */
    public static function bulanYm(int $bulan, int $tahun): string
    {
        return sprintf('%04d-%02d', $tahun, $bulan);
    }
}
