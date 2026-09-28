<?php

use App\Libraries\InboxMediaBound;
use PHPUnit\Framework\TestCase;

/**
 * CLN-901/CLN-1003/TEST-907: `InboxMediaBound::mbKeByte()` harus
 * mengembalikan nilai identik dengan literalin lama `$mb * 1024 * 1024`
 * supaya ekstraksi helper tidak mengubah perilaku transfer media
 * (0/1/15/100 MB). Dipanggil LANGSUNG (TASK-1103) -- helper sudah bukan
 * lagi private di Controller, jadi refleksi tak perlu.
 *
 * @internal
 */
final class InboxMbKeByteTest extends TestCase
{
    public function testKonversiIdentikDenganLiteralinLama(): void
    {
        foreach ([0, 1, 15, 100] as $mb) {
            $this->assertSame(
                $mb * 1024 * 1024,
                InboxMediaBound::mbKeByte($mb),
                "mbKeByte({$mb}) harus sama dengan {$mb} * 1024 * 1024."
            );
        }
    }

    /**
     * SEC-1001/TEST-1004: salah-ketik nilai batas media yang raksasa TIDAK
     * BOLEH melempar `TypeError` (dulu `$mb * 1024 * 1024` melampaui
     * PHP_INT_MAX -> float) dan TIDAK boleh membuat jalur media HTTP 500.
     * Nilai ekstrem gagal-aman ke PHP_INT_MAX.
     */
    public function testNilaiRaksasaGagalAmanKeIntMax(): void
    {
        $this->assertGreaterThan(
            intdiv(PHP_INT_MAX, 1024 * 1024),
            8800000000000,
            'Prasyarat: 8800000000000 memang di atas ambang overflow.'
        );

        $this->assertSame(
            PHP_INT_MAX,
            InboxMediaBound::mbKeByte(8800000000000),
            'SEC-1001: nilai raksasa harus gagal-aman ke PHP_INT_MAX, bukan TypeError.'
        );
    }
}
