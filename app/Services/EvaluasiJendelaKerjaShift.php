<?php

namespace App\Services;

use App\Models\JadwalModel;

/**
 * Stateless: apakah shift X sedang berada dalam jam kerjanya pada
 * HH:MM tertentu?
 *
 * TIDAK menyentuh user/priority/DB/otorisasi -- jam SELALU dibaca
 * dari JadwalModel::DEFINISI_SHIFT (satu-satunya sumber kebenaran),
 * tidak pernah diduplikasi di sini. Boundary start & end inklusif,
 * resolusi menit (string 'H:i', perbandingan leksikografis) --
 * konsisten dengan konvensi JadwalModel::statusSaatIni(), tapi
 * implementasi independen: tidak memanggil atau mengubah fungsi itu,
 * kontraknya (informational-only) tidak disentuh sama sekali.
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

        // Shift 'L' (sesi kosong) dan kode shift tak dikenal
        // sama-sama jatuh ke sini -> false, tanpa perlu kasus khusus.
        return false;
    }
}
