<?php

use App\Services\EffectiveShiftLeaderService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * EffectiveShiftLeaderService::shiftLeaderSaatIni() -- kandidat
 * (role='kasir', is_active=1, priority IS NOT NULL, punya row jadwal
 * hari itu, shift != 'L'), diurut priority DESC, pemenang = kandidat
 * pertama yang sedang dalam jendela jam kerja shift-nya. Satu Leader
 * global (overlap P/S/PM masuk satu pool), tidak ada fallback.
 *
 * @internal
 */
final class EffectiveShiftLeaderServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'Tests\Support';
    protected $migrate   = true;
    protected $refresh   = true;

    private EffectiveShiftLeaderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EffectiveShiftLeaderService();
    }

    /** @return int id user yang baru dibuat */
    private function seedUser(string $username, string $role = 'kasir', int $isActive = 1, ?int $priority = null): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'username'  => $username,
            'role'      => $role,
            'is_active' => $isActive,
            'priority'  => $priority,
        ]);

        return (int) $db->insertID();
    }

    private function seedJadwal(int $karyawanId, string $tanggal, string $shift): void
    {
        db_connect()->table('jadwal')->insert([
            'karyawan_id' => $karyawanId,
            'tanggal'     => $tanggal,
            'shift'       => $shift,
        ]);
    }

    public function testSatuKandidatTerpilihSaatDalamWindow(): void
    {
        $id = $this->seedUser('aan', 'kasir', 1, 10);
        $this->seedJadwal($id, '2026-09-14', 'P');

        $leader = $this->service->shiftLeaderSaatIni('2026-09-14', '09:00');

        $this->assertNotNull($leader);
        $this->assertSame($id, $leader['id']);
        $this->assertSame(10, $leader['priority']);
    }

    public function testPriorityTertinggiMenang(): void
    {
        $aan  = $this->seedUser('aan', 'kasir', 1, 10);
        $budi = $this->seedUser('budi', 'kasir', 1, 7);
        $this->seedJadwal($aan, '2026-09-14', 'P');
        $this->seedJadwal($budi, '2026-09-14', 'S');

        // 14:00 -> P (08:00-15:00) & S (13:30-20:30) overlap, satu pool.
        $leader = $this->service->shiftLeaderSaatIni('2026-09-14', '14:00');

        $this->assertSame($aan, $leader['id']);
    }

    public function testPriorityTertinggiDiLuarWindowJatuhKeKandidatBerikutnya(): void
    {
        $aan  = $this->seedUser('aan', 'kasir', 1, 10);
        $budi = $this->seedUser('budi', 'kasir', 1, 7);
        $this->seedJadwal($aan, '2026-09-14', 'P');   // 08:00-15:00
        $this->seedJadwal($budi, '2026-09-14', 'S');  // 13:30-20:30

        // 15:30 -> P sudah selesai, S masih berjalan.
        $leader = $this->service->shiftLeaderSaatIni('2026-09-14', '15:30');

        $this->assertSame($budi, $leader['id']);
    }

    public function testInactiveDikecualikanWalauPriorityTertinggi(): void
    {
        $aan  = $this->seedUser('aan', 'kasir', 0, 10); // inactive
        $budi = $this->seedUser('budi', 'kasir', 1, 7);
        $this->seedJadwal($aan, '2026-09-14', 'P');
        $this->seedJadwal($budi, '2026-09-14', 'P');

        $leader = $this->service->shiftLeaderSaatIni('2026-09-14', '09:00');

        $this->assertSame($budi, $leader['id']);
    }

    public function testAdminTidakPernahJadiKandidatWalauPunyaPriorityDanJadwal(): void
    {
        $admin = $this->seedUser('admin1', 'admin', 1, 99); // priority tertinggi
        $budi  = $this->seedUser('budi', 'kasir', 1, 7);
        $this->seedJadwal($admin, '2026-09-14', 'P');
        $this->seedJadwal($budi, '2026-09-14', 'P');

        $leader = $this->service->shiftLeaderSaatIni('2026-09-14', '09:00');

        $this->assertSame($budi, $leader['id']);
    }

    public function testPriorityNullDikecualikan(): void
    {
        $tanpaPriority = $this->seedUser('tanpa_priority', 'kasir', 1, null);
        $this->seedJadwal($tanpaPriority, '2026-09-14', 'P');

        $leader = $this->service->shiftLeaderSaatIni('2026-09-14', '09:00');

        $this->assertNull($leader);
    }

    public function testShiftLDikecualikan(): void
    {
        $id = $this->seedUser('aan', 'kasir', 1, 10);
        $this->seedJadwal($id, '2026-09-14', 'L');

        $leader = $this->service->shiftLeaderSaatIni('2026-09-14', '09:00');

        $this->assertNull($leader);
    }

    public function testTidakAdaJadwalBukanKandidat(): void
    {
        $this->seedUser('aan', 'kasir', 1, 10); // tidak ada row jadwal

        $leader = $this->service->shiftLeaderSaatIni('2026-09-14', '09:00');

        $this->assertNull($leader);
    }

    public function testTidakAdaKandidatMenghasilkanNull(): void
    {
        $leader = $this->service->shiftLeaderSaatIni('2026-09-14', '09:00');

        $this->assertNull($leader);
    }

    public function testOverlapPDanSSatuPoolGabungan(): void
    {
        $aan  = $this->seedUser('aan', 'kasir', 1, 5);
        $budi = $this->seedUser('budi', 'kasir', 1, 9);
        $this->seedJadwal($aan, '2026-09-14', 'P');
        $this->seedJadwal($budi, '2026-09-14', 'S');

        $leader = $this->service->shiftLeaderSaatIni('2026-09-14', '14:00');

        $this->assertSame($budi, $leader['id']); // priority tertinggi menang, bukan per-shift
    }

    public function testOverlapPDanSDanPmSatuPoolGabungan(): void
    {
        $aan   = $this->seedUser('aan', 'kasir', 1, 5);
        $budi  = $this->seedUser('budi', 'kasir', 1, 9);
        $citra = $this->seedUser('citra', 'kasir', 1, 20);
        $this->seedJadwal($aan, '2026-09-14', 'P');    // 08:00-15:00
        $this->seedJadwal($budi, '2026-09-14', 'S');   // 13:30-20:30
        $this->seedJadwal($citra, '2026-09-14', 'PM'); // 08:00-12:30 & 18:00-20:30

        // 14:00 -> P & S bekerja, PM sedang jeda -> Citra bukan kandidat aktif saat ini.
        $leader = $this->service->shiftLeaderSaatIni('2026-09-14', '14:00');
        $this->assertSame($budi, $leader['id']);

        // 19:00 -> hanya S & PM(sesi sore) bekerja, P sudah selesai.
        $leaderMalam = $this->service->shiftLeaderSaatIni('2026-09-14', '19:00');
        $this->assertSame($citra, $leaderMalam['id']);
    }

    public function testPmSesiPagi(): void
    {
        $id = $this->seedUser('citra', 'kasir', 1, 10);
        $this->seedJadwal($id, '2026-09-14', 'PM');

        $this->assertSame($id, $this->service->shiftLeaderSaatIni('2026-09-14', '08:30')['id']);
    }

    public function testPmJeda(): void
    {
        $id = $this->seedUser('citra', 'kasir', 1, 10);
        $this->seedJadwal($id, '2026-09-14', 'PM');

        $this->assertNull($this->service->shiftLeaderSaatIni('2026-09-14', '14:00'));
    }

    public function testPmSesiSore(): void
    {
        $id = $this->seedUser('citra', 'kasir', 1, 10);
        $this->seedJadwal($id, '2026-09-14', 'PM');

        $this->assertSame($id, $this->service->shiftLeaderSaatIni('2026-09-14', '19:00')['id']);
    }

    public function testPerubahanPriorityLangsungBerefek(): void
    {
        $aan  = $this->seedUser('aan', 'kasir', 1, 10);
        $budi = $this->seedUser('budi', 'kasir', 1, 7);
        $this->seedJadwal($aan, '2026-09-14', 'P');
        $this->seedJadwal($budi, '2026-09-14', 'P');

        $this->assertSame($aan, $this->service->shiftLeaderSaatIni('2026-09-14', '09:00')['id']);

        // Priority Budi dinaikkan melewati Aan -> panggilan berikutnya harus berubah seketika (tidak ada cache).
        db_connect()->table('users')->where('id', $aan)->update(['priority' => 3]);
        db_connect()->table('users')->where('id', $budi)->update(['priority' => 20]);

        $this->assertSame($budi, $this->service->shiftLeaderSaatIni('2026-09-14', '09:00')['id']);
    }

    public function testPerubahanJadwalLangsungBerefek(): void
    {
        $id = $this->seedUser('aan', 'kasir', 1, 10);
        $this->seedJadwal($id, '2026-09-14', 'P');

        $this->assertNotNull($this->service->shiftLeaderSaatIni('2026-09-14', '09:00'));

        // Jadwal diubah jadi Libur -> panggilan berikutnya harus langsung null.
        db_connect()->table('jadwal')->where('karyawan_id', $id)->where('tanggal', '2026-09-14')->update(['shift' => 'L']);

        $this->assertNull($this->service->shiftLeaderSaatIni('2026-09-14', '09:00'));
    }

    public function testDateBoundaryMemakaiJadwalTanggalYangBerbeda(): void
    {
        $id = $this->seedUser('aan', 'kasir', 1, 10);
        $this->seedJadwal($id, '2026-09-14', 'P');
        // Tidak ada jadwal untuk 2026-09-15.

        $this->assertNotNull($this->service->shiftLeaderSaatIni('2026-09-14', '09:00'));
        $this->assertNull($this->service->shiftLeaderSaatIni('2026-09-15', '09:00'));
    }
}
