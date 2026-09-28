<?php

namespace App\Libraries;

use InvalidArgumentException;

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
     *
     * A-04: public supaya lapisan laporan/UI dan test memakai angka yang
     * SAMA, bukan menyalin literal 4096.
     */
    public const CEILING_MB = 4096;

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
        // A-03: negative MB is meaningless (Config already sanitises env to
        // >= 1); reject loudly instead of silently returning negative bytes.
        if ($mb < 0) {
            throw new InvalidArgumentException('InboxMediaBound: ukuran MB tidak boleh negatif.');
        }

        if ($mb > self::CEILING_MB) {
            // A-01: the ceiling clamp used to be silent; log it so an
            // over-large configuration is visible, not invisible.
            log_message(
                'warning',
                'InboxMediaBound: ukuran ' . $mb . 'MB di atas plafon ' . self::CEILING_MB . 'MB; dipotong ke plafon.'
            );

            $mb = self::CEILING_MB;
        }

        // A-02: on a 32-bit platform the policy ceiling in bytes is not
        // representable as an int (4096 MB > PHP_INT_MAX / 1 MB). Return the
        // largest representable bound instead of letting the multiplication
        // overflow to float and throw a TypeError (SEC-1001).
        if ($mb > intdiv(PHP_INT_MAX, self::BYTES_PER_MB)) {
            return PHP_INT_MAX;
        }

        return $mb * self::BYTES_PER_MB;
    }

    /**
     * A-01: the EFFECTIVE MB bound after the policy ceiling (and the 32-bit
     * platform bound) is applied. Report/UI layers use this so the number
     * shown to the cashier matches the bound `mbKeByte()` actually enforces,
     * instead of the raw env value.
     */
    public static function batasEfektifMb(int $mb): int
    {
        return min($mb, self::CEILING_MB, intdiv(PHP_INT_MAX, self::BYTES_PER_MB));
    }
}
