<?php

namespace App\Libraries;

/**
 * CLN-1002: helper murni batas media Inbox. Dipindahkan keluar dari
 * `App\Controllers\Inbox` supaya konversi MB->byte bukan lagi `public
 * static` yang menggantung di Controller (review Axis A ARCH-01 /
 * `DEVIATION-912` plan v1.2). Dipakai jalur unggah/unduh/prefetch.
 *
 * @internal
 */
final class InboxMediaBound
{
    /**
     * CLN-901/SEC-1001: SATU sumber konversi MB -> byte. Gagal-aman
     * terhadap overflow aritmetika -- nilai env raksasa (salah-ketik)
     * dibatasi ke `PHP_INT_MAX`, BUKAN melempar `TypeError` yang membuat
     * seluruh jalur media HTTP 500.
     */
    public static function mbKeByte(int $mb): int
    {
        if ($mb > intdiv(PHP_INT_MAX, 1024 * 1024)) {
            return PHP_INT_MAX;
        }

        return $mb * 1024 * 1024;
    }
}
