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
     * CLN-1006: satu literal ukuran 1 MB, dipakai guard + return.
     */
    private const BYTES_PER_MB = 1024 * 1024;

    /**
     * SEC-1003/TASK-1201: plafon kebijakan (MB). Nilai env yang salah-ketik
     * dan jauh di atas ini DIPOTONG ke plafon -- bukan diteruskan sebagai
     * nilai efektif tak terbatas. Dipilih 4096 MB, jauh di atas kebutuhan
     * nyata (default unggah 15 MB, unduh 100 MB), sehingga tidak menolak
     * nilai sah yang wajar sementara satu salah-ketik tetap gagal-terbatas
     * dan tidak mematikan kontrol DoS memori/disk.
     */
    private const CEILING_MB = 4096;

    /**
     * CLN-901/SEC-1001/SEC-1003: SATU sumber konversi MB -> byte.
     * Gagal-aman DAN gagal-terbatas:
     *  - tidak pernah melempar `TypeError` yang membuat seluruh jalur media
     *    HTTP 500 (SEC-1001);
     *  - nilai raksasa (salah-ketik env) dipotong ke `CEILING_MB`, BUKAN
     *    dikembalikan sebagai `PHP_INT_MAX` yang menonaktifkan kontrol
     *    batas ukuran (SEC-1003).
     */
    public static function mbKeByte(int $mb): int
    {
        if ($mb > self::CEILING_MB) {
            return self::CEILING_MB * self::BYTES_PER_MB;
        }

        return $mb * self::BYTES_PER_MB;
    }
}
