<?php

namespace App\Services;

use App\Models\JadwalModel;

/**
 * Stateless: apakah shift X sedang berada dalam jam kerjanya pada
 * HH:MM tertentu. Jam shift tetap bersumber dari JadwalModel.
 */
final class EvaluasiJendelaKerjaShift
{
    public static function sedangBekerja(string $shift, string $jamSekarang): bool
    {
        foreach (JadwalModel::jamShift($shift) as $sesi) {
            if ($jamSekarang >= $sesi['mulai'] && $jamSekarang <= $sesi['selesai']) {
                return true;
            }
        }

        return false;
    }
}
