<?php

namespace Tests\Unit;

use App\Services\EvaluasiJendelaKerjaShift;
use PHPUnit\Framework\TestCase;

final class EvaluasiJendelaKerjaShiftTest extends TestCase
{
    public function testPSebelumMulaiFalse(): void { $this->assertFalse(EvaluasiJendelaKerjaShift::sedangBekerja('P', '07:59')); }
    public function testPTepatMulaiTrue(): void { $this->assertTrue(EvaluasiJendelaKerjaShift::sedangBekerja('P', '08:00')); }
    public function testPTepatSelesaiTrue(): void { $this->assertTrue(EvaluasiJendelaKerjaShift::sedangBekerja('P', '15:00')); }
    public function testPSetelahSelesaiFalse(): void { $this->assertFalse(EvaluasiJendelaKerjaShift::sedangBekerja('P', '15:01')); }
    public function testSSebelumMulaiFalse(): void { $this->assertFalse(EvaluasiJendelaKerjaShift::sedangBekerja('S', '13:29')); }
    public function testSTepatMulaiTrue(): void { $this->assertTrue(EvaluasiJendelaKerjaShift::sedangBekerja('S', '13:30')); }
    public function testSTepatSelesaiTrue(): void { $this->assertTrue(EvaluasiJendelaKerjaShift::sedangBekerja('S', '20:30')); }
    public function testSSetelahSelesaiFalse(): void { $this->assertFalse(EvaluasiJendelaKerjaShift::sedangBekerja('S', '20:31')); }
    public function testPmSebelumSesiPagiFalse(): void { $this->assertFalse(EvaluasiJendelaKerjaShift::sedangBekerja('PM', '07:59')); }
    public function testPmTepatMulaiSesiPagiTrue(): void { $this->assertTrue(EvaluasiJendelaKerjaShift::sedangBekerja('PM', '08:00')); }
    public function testPmDalamSesiPagiTrue(): void { $this->assertTrue(EvaluasiJendelaKerjaShift::sedangBekerja('PM', '12:29')); }
    public function testPmTepatSelesaiSesiPagiTrue(): void { $this->assertTrue(EvaluasiJendelaKerjaShift::sedangBekerja('PM', '12:30')); }
    public function testPmJedaSetelahSesiPagiFalse(): void { $this->assertFalse(EvaluasiJendelaKerjaShift::sedangBekerja('PM', '12:31')); }
    public function testPmJedaSebelumSesiSoreFalse(): void { $this->assertFalse(EvaluasiJendelaKerjaShift::sedangBekerja('PM', '17:59')); }
    public function testPmTepatMulaiSesiSoreTrue(): void { $this->assertTrue(EvaluasiJendelaKerjaShift::sedangBekerja('PM', '18:00')); }
    public function testPmTepatSelesaiSesiSoreTrue(): void { $this->assertTrue(EvaluasiJendelaKerjaShift::sedangBekerja('PM', '20:30')); }
    public function testPmSetelahSesiSoreFalse(): void { $this->assertFalse(EvaluasiJendelaKerjaShift::sedangBekerja('PM', '20:31')); }
    public function testLSelaluFalse(): void
    {
        $this->assertFalse(EvaluasiJendelaKerjaShift::sedangBekerja('L', '00:00'));
        $this->assertFalse(EvaluasiJendelaKerjaShift::sedangBekerja('L', '08:00'));
        $this->assertFalse(EvaluasiJendelaKerjaShift::sedangBekerja('L', '23:59'));
    }
    public function testKodeTidakDikenalFalse(): void { $this->assertFalse(EvaluasiJendelaKerjaShift::sedangBekerja('X', '10:00')); }
}
