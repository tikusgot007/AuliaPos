<?php

namespace App\Services;

/**
 * TEST-ONLY. Tidak pernah di-load di request production mana pun --
 * hanya dipakai lewat require_once eksplisit dari test yang butuh jam
 * deterministik (lihat tests/session/KasirSelesaikanTransaksiTest.php).
 * Tidak ada perubahan pada App\Services\EffectiveShiftLeaderService,
 * App\Services\Authority, atau endpoint production mana pun -- kode
 * production sama sekali tidak disentuh oleh file ini.
 *
 * Teknik: PHP me-resolve pemanggilan function() yang TIDAK di-qualify
 * (tanpa "\" di depan) dengan urutan (1) function bernama sama di
 * namespace tempat pemanggilan itu berada, baru (2) fallback ke
 * function global -- ini perilaku bahasa PHP sendiri, bukan
 * monkey-patch/reflection/runkit/ekstensi apa pun (teknik yang sama
 * dipakai library seperti php-mock). Karena
 * EffectiveShiftLeaderService::shiftLeaderSaatIni() memanggil
 * date('Y-m-d')/date('H:i') TANPA qualifier di dalam
 * `namespace App\Services;`, begitu file INI ter-require, PHP akan
 * memakai App\Services\date() di bawah untuk kedua panggilan default
 * ($tanggal/$jamSekarang) tsb -- bukan date() global.
 *
 * Kalau FakeClock::$override null (default, atau setelah reset()),
 * fungsi ini murni mendelegasikan ke date() global -- perilaku
 * identik dengan sebelum file ini ada.
 */
final class FakeClock
{
    /** Format bebas yang bisa diparse strtotime(), mis. '2026-09-14 09:00:00'. Null = pakai jam asli (default). */
    public static ?string $override = null;

    public static function reset(): void
    {
        self::$override = null;
    }
}

if (! function_exists(__NAMESPACE__ . '\\date')) {
    function date(string $format, ?int $timestamp = null): string
    {
        if ($timestamp === null && FakeClock::$override !== null) {
            $timestamp = strtotime(FakeClock::$override);
        }

        return \date($format, $timestamp ?? time());
    }
}
