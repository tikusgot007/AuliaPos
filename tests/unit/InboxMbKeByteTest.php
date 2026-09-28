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
     * SEC-1001/SEC-1003/TEST-1009: salah-ketik nilai batas media yang
     * raksasa TIDAK BOLEH melempar `TypeError` (dulu `$mb * 1024 * 1024`
     * melampaui PHP_INT_MAX -> float) dan TIDAK boleh membuat jalur media
     * HTTP 500. Nilai ekstrem kini DIBATASI ke plafon kebijakan
     * (`CEILING_MB` = 4096 MB), BUKAN `PHP_INT_MAX`, supaya kontrol batas
     * ukuran tetap aktif (gagal-terbatas, bukan gagal-terbuka -- SEC-01
     * review v1.3).
     */
    public function testNilaiRaksasaDibatasiPlafonKebijakan(): void
    {
        $this->assertGreaterThan(
            intdiv(PHP_INT_MAX, 1024 * 1024),
            8800000000000,
            'Prasyarat: 8800000000000 memang di atas ambang overflow.'
        );

        $plafon = 4096 * 1024 * 1024;

        $this->assertSame(
            $plafon,
            InboxMediaBound::mbKeByte(8800000000000),
            'SEC-1003: nilai raksasa harus dipotong ke plafon kebijakan (4096 MB), bukan PHP_INT_MAX.'
        );
        $this->assertLessThan(
            PHP_INT_MAX,
            InboxMediaBound::mbKeByte(8800000000000),
            'SEC-1003: hasil wajib terbatas (di bawah PHP_INT_MAX), bukan nilai efektif tak terbatas.'
        );
    }

    /**
     * SEC-1003/TEST-1009: nilai tepat di plafon tetap lolos apa adanya,
     * sedangkan nilai di atasnya -- termasuk angka besar yang TIDAK memicu
     * overflow tetapi dulu menonaktifkan batas (mis. 100000 MB) -- seragam
     * dipotong ke plafon.
     */
    public function testPlafonKebijakanMembatasiNilaiDiAtasBatas(): void
    {
        $plafon = 4096 * 1024 * 1024;

        $this->assertSame($plafon, InboxMediaBound::mbKeByte(4096), 'Nilai tepat di plafon tidak diubah.');
        $this->assertSame($plafon, InboxMediaBound::mbKeByte(4097), 'Nilai di atas plafon dipotong ke plafon.');
        $this->assertSame(
            $plafon,
            InboxMediaBound::mbKeByte(100000),
            'SEC-01: salah-ketik 100000 MB (tidak overflow) tetap gagal-terbatas.'
        );
    }
}
