<?php

namespace Tests\Unit;

use App\Services\EvaluasiJendelaKerjaShift;
use PHPUnit\Framework\TestCase;

/**
 * Sengaja pakai PHPUnit\Framework\TestCase polos (bukan
 * CIUnitTestCase) -- EvaluasiJendelaKerjaShift stateless, hanya
 * membaca JadwalModel::DEFINISI_SHIFT (konstanta murni, tanpa DB),
 * tidak perlu bootstrap CodeIgniter penuh. Pola sama seperti
 * tests/unit/KalkulasiStatusPembayaranTest.php.
 *
 * Boundary wajib (lihat Tahap 3 Bagian E/J.A):
 *   P  = 08:00-15:00
 *   S  = 13:30-20:30
 *   PM = 08:00-12:30 dan 18:00-20:30 (dua sesi)
 *   L  = selalu false
 */
final class EvaluasiJendelaKerjaShiftTest extends TestCase
{
    // --- P ---

    public function testPSebelumMulaiFalse(): void
    {
        $this->assertSame(false, EvaluasiJendelaKerjaShift::sedangBekerja('P', '07:59'));
    }

    public function testPTepatMulaiTrue(): void
    {
        $this->assertSame(true, EvaluasiJendelaKerjaShift::sedangBekerja('P', '08:00'));
    }

    public function testPTepatSelesaiTrue(): void
    {
        $this->assertSame(true, EvaluasiJendelaKerjaShift::sedangBekerja('P', '15:00'));
    }

    public function testPSetelahSelesaiFalse(): void
    {
        $this->assertSame(false, EvaluasiJendelaKerjaShift::sedangBekerja('P', '15:01'));
    }

    // --- S ---

    public function testSSebelumMulaiFalse(): void
    {
        $this->assertSame(false, EvaluasiJendelaKerjaShift::sedangBekerja('S', '13:29'));
    }

    public function testSTepatMulaiTrue(): void
    {
        $this->assertSame(true, EvaluasiJendelaKerjaShift::sedangBekerja('S', '13:30'));
    }

    public function testSTepatSelesaiTrue(): void
    {
        $this->assertSame(true, EvaluasiJendelaKerjaShift::sedangBekerja('S', '20:30'));
    }

    public function testSSetelahSelesaiFalse(): void
    {
        $this->assertSame(false, EvaluasiJendelaKerjaShift::sedangBekerja('S', '20:31'));
    }

    // --- PM (dua sesi: 08:00-12:30 dan 18:00-20:30) ---

    public function testPmSebelumSesiPagiFalse(): void
    {
        $this->assertSame(false, EvaluasiJendelaKerjaShift::sedangBekerja('PM', '07:59'));
    }

    public function testPmTepatMulaiSesiPagiTrue(): void
    {
        $this->assertSame(true, EvaluasiJendelaKerjaShift::sedangBekerja('PM', '08:00'));
    }

    public function testPmDalamSesiPagiTrue(): void
    {
        $this->assertSame(true, EvaluasiJendelaKerjaShift::sedangBekerja('PM', '12:29'));
    }

    public function testPmTepatSelesaiSesiPagiTrue(): void
    {
        $this->assertSame(true, EvaluasiJendelaKerjaShift::sedangBekerja('PM', '12:30'));
    }

    public function testPmJedaSetelahSesiPagiFalse(): void
    {
        $this->assertSame(false, EvaluasiJendelaKerjaShift::sedangBekerja('PM', '12:31'));
    }

    public function testPmJedaSebelumSesiSoreFalse(): void
    {
        $this->assertSame(false, EvaluasiJendelaKerjaShift::sedangBekerja('PM', '17:59'));
    }

    public function testPmTepatMulaiSesiSoreTrue(): void
    {
        $this->assertSame(true, EvaluasiJendelaKerjaShift::sedangBekerja('PM', '18:00'));
    }

    public function testPmTepatSelesaiSesiSoreTrue(): void
    {
        $this->assertSame(true, EvaluasiJendelaKerjaShift::sedangBekerja('PM', '20:30'));
    }

    public function testPmSetelahSesiSoreFalse(): void
    {
        $this->assertSame(false, EvaluasiJendelaKerjaShift::sedangBekerja('PM', '20:31'));
    }

    // --- L ---

    public function testLSelaluFalse(): void
    {
        $this->assertSame(false, EvaluasiJendelaKerjaShift::sedangBekerja('L', '00:00'));
        $this->assertSame(false, EvaluasiJendelaKerjaShift::sedangBekerja('L', '08:00'));
        $this->assertSame(false, EvaluasiJendelaKerjaShift::sedangBekerja('L', '23:59'));
    }

    // --- kode shift tak dikenal (defensif, bukan bagian dari 4 kode resmi) ---

    public function testKodeTidakDikenalFalse(): void
    {
        $this->assertSame(false, EvaluasiJendelaKerjaShift::sedangBekerja('X', '10:00'));
    }
}
