<?php

use App\Services\KalkulasiJatuhTempo;
use PHPUnit\Framework\TestCase;

/**
 * Sengaja pakai PHPUnit\Framework\TestCase polos (bukan CIUnitTestCase)
 * -- App\Services\KalkulasiJatuhTempo murni pure/stateless, jadi tidak
 * perlu bootstrap CodeIgniter penuh untuk mengujinya. Pola sama dengan
 * tests/unit/KalkulasiStatusPembayaranTest.php.
 *
 * @internal
 */
final class KalkulasiJatuhTempoTest extends TestCase
{
    public function testHitungTempoTujuhHari(): void
    {
        $this->assertSame('2026-09-22', KalkulasiJatuhTempo::hitung('2026-09-15 10:00:00', 7));
    }

    public function testHitungTempoNolHariSamaDenganTanggalTransaksi(): void
    {
        $this->assertSame('2026-09-15', KalkulasiJatuhTempo::hitung('2026-09-15 10:00:00', 0));
    }

    public function testBelumOverduePadaTepatHariJatuhTempo(): void
    {
        // Transaksi 15 Sep + tempo 7 hari -> jatuh tempo 22 Sep.
        // Tepat di tanggal jatuh tempo: BELUM overdue (hari terakhir
        // masih diberi kesempatan bayar, baru overdue keesokan harinya).
        $this->assertFalse(KalkulasiJatuhTempo::isOverdue('2026-09-15 10:00:00', 7, '2026-09-22'));
    }

    public function testOverdueSehariSetelahJatuhTempo(): void
    {
        $this->assertTrue(KalkulasiJatuhTempo::isOverdue('2026-09-15 10:00:00', 7, '2026-09-23'));
    }

    public function testBelumOverdueSebelumJatuhTempo(): void
    {
        $this->assertFalse(KalkulasiJatuhTempo::isOverdue('2026-09-15 10:00:00', 7, '2026-09-18'));
    }
}
