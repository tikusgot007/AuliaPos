<?php
/**
 * Shared Bulan + Tahun dropdown (single-month selector).
 *
 * Emits ids "{prefix}Bulan" and "{prefix}Tahun" so window.AuliaMonthPicker
 * (public/assets/js/date-range.js) can read/write the selected YYYY-MM.
 *
 * Vars:
 *   $prefix (string) element id prefix, e.g. "bulanPicker"
 *   $bulan  (int)    1-12, default current month
 *   $tahun  (int)    4-digit year, default current year
 */
$prefix = $prefix ?? 'picker';
$bulan  = (int) ($bulan ?? (int) date('n'));
$tahun  = (int) ($tahun ?? (int) date('Y'));

$namaBulan = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
];
?>
<div class="d-flex align-items-end gap-2">
    <div>
        <label class="form-label mb-1" for="<?= esc($prefix) ?>Bulan">Bulan</label>
        <select class="form-select" id="<?= esc($prefix) ?>Bulan">
            <?php foreach ($namaBulan as $angka => $nama): ?>
                <option value="<?= $angka ?>" <?= $angka === $bulan ? 'selected' : '' ?>><?= esc($nama) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="form-label mb-1" for="<?= esc($prefix) ?>Tahun">Tahun</label>
        <select class="form-select" id="<?= esc($prefix) ?>Tahun">
            <?php for ($t = $tahun - 10; $t <= $tahun + 1; $t++): ?>
                <option value="<?= $t ?>" <?= $t === $tahun ? 'selected' : '' ?>><?= $t ?></option>
            <?php endfor; ?>
        </select>
    </div>
</div>
