<?php

use App\Services\InboxSlaService;
use Config\Inbox as InboxConfig;
use PHPUnit\Framework\TestCase;

/**
 * InboxSlaService is DB/session/request independent, so use plain PHPUnit.
 *
 * Boundaries:
 * - <15m = hijau
 * - 15-60m = kuning
 * - >60m = merah
 * - selesai/ditunda = null
 *
 * @internal
 */
final class InboxSlaServiceTest extends TestCase
{
    private InboxSlaService $service;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $config = new InboxConfig();
        $config->slaGreenMinutes = 15;
        $config->slaYellowMinutes = 60;

        $this->service = new InboxSlaService($config);
        $this->now = new DateTimeImmutable('2026-09-22 14:00:00', new DateTimeZone('Asia/Jakarta'));
    }

    public function testKurangDari15MenitHijau(): void
    {
        $this->assertSame(
            'hijau',
            $this->service->hitung('2026-09-22 13:46:00', 'menunggu', $this->now)
        );
    }

    public function testTepat15MenitKuning(): void
    {
        $this->assertSame(
            'kuning',
            $this->service->hitung('2026-09-22 13:45:00', 'menunggu', $this->now)
        );
    }

    public function testAntara15Sampai60MenitKuning(): void
    {
        $this->assertSame(
            'kuning',
            $this->service->hitung('2026-09-22 13:30:00', 'menunggu', $this->now)
        );
    }

    public function testTepat60MenitKuning(): void
    {
        $this->assertSame(
            'kuning',
            $this->service->hitung('2026-09-22 13:00:00', 'menunggu', $this->now)
        );
    }

    public function testLebihDari60MenitMerah(): void
    {
        $this->assertSame(
            'merah',
            $this->service->hitung('2026-09-22 12:59:59', 'menunggu', $this->now)
        );
    }

    public function testSelesaiNull(): void
    {
        $this->assertNull(
            $this->service->hitung('2026-09-22 10:00:00', 'selesai', $this->now)
        );
    }

    public function testDitundaNull(): void
    {
        $this->assertNull(
            $this->service->hitung('2026-09-22 10:00:00', 'ditunda', $this->now)
        );
    }

    public function testMenungguTetapDihitung(): void
    {
        $this->assertSame(
            'merah',
            $this->service->hitung('2026-09-22 12:00:00', 'menunggu', $this->now)
        );
    }

    public function testPerluDibalasJugaDihitung(): void
    {
        $this->assertSame(
            'hijau',
            $this->service->hitung('2026-09-22 13:50:00', 'open', $this->now)
        );
    }

    /**
     * AC-005: 20 menit = kuning untuk semua queue_status yang dihitung
     * (perlu_dibalas terbagi jadi belum_diambil/open, plus menunggu).
     */
    public function test20MenitKuningUntukPerluDibalasDanMenunggu(): void
    {
        foreach (['belum_diambil', 'open', 'menunggu'] as $queueStatus) {
            $this->assertSame(
                'kuning',
                $this->service->hitung('2026-09-22 13:40:00', $queueStatus, $this->now),
                $queueStatus
            );
        }
    }

    public function testTimestampMasaDepanTidakMenjadiMerah(): void
    {
        $this->assertSame(
            'hijau',
            $this->service->hitung('2026-09-22 14:05:00', 'menunggu', $this->now)
        );
    }

    public function testLastMessageAtKosongNull(): void
    {
        $this->assertNull(
            $this->service->hitung(null, 'menunggu', $this->now)
        );
    }
}
