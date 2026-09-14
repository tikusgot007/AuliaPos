<?php

require_once __DIR__ . '/../_support/Fakes/ShiftLeaderClockOverride.php';

use App\Services\FakeClock;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Tahap 4 -- GET /roster/shift-leader-saat-ini: info Shift Leader saat
 * ini, MURNI INFORMASI, terlihat untuk SEMUA role yang login (tidak
 * ada gate admin/kasir -- beda dari /roster/status-saya yang khusus
 * kasir). Tidak menyentuh App\Services\EffectiveShiftLeaderService/
 * Authority -- murni mengonsumsinya read-only.
 *
 * @internal
 */
final class ShiftLeaderInfoRouteTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    protected $namespace = 'Tests\Support';
    protected $migrate   = true;
    protected $refresh   = true;

    private const ROUTE     = 'roster/shift-leader-saat-ini';
    private const HARI_TETAP = '2026-09-14';
    private const JAM_TETAP  = '09:00';

    protected function tearDown(): void
    {
        FakeClock::reset();
        parent::tearDown();
    }

    private function sesi(string $role, int $idUser): array
    {
        return [
            'isLoggedIn'    => true,
            'role'          => $role,
            'id_user'       => $idUser,
            'nama'          => 'Test ' . $role,
            'last_activity' => time(),
        ];
    }

    private function seedUser(int $id, string $role, int $isActive, ?int $priority): void
    {
        db_connect()->table('users')->insert([
            'id'        => $id,
            'username'  => 'user' . $id,
            'nama'      => 'Nama ' . $id,
            'role'      => $role,
            'is_active' => $isActive,
            'priority'  => $priority,
        ]);
    }

    private function seedJadwal(int $karyawanId, string $tanggal, string $shift): void
    {
        db_connect()->table('jadwal')->insert([
            'karyawan_id' => $karyawanId,
            'tanggal'     => $tanggal,
            'shift'       => $shift,
        ]);
    }

    public function testAdaShiftLeaderTerlihatUntukAdminDanKasir(): void
    {
        FakeClock::$override = self::HARI_TETAP . ' ' . self::JAM_TETAP . ':00';

        $this->seedUser(50, 'kasir', 1, 10);
        $this->seedJadwal(50, self::HARI_TETAP, 'P');

        // Diakses oleh admin -- harus tetap terlihat (tidak ada gate).
        $resAdmin = $this->withSession($this->sesi('admin', 1))->get(self::ROUTE);
        $resAdmin->assertOK();
        $resAdmin->assertJSONFragment(['status' => 'success']);
        $bodyAdmin = json_decode($resAdmin->getJSON(), true);
        $this->assertSame(50, $bodyAdmin['leader']['id']);
        $this->assertSame('user50', $bodyAdmin['leader']['username']);

        // Diakses oleh kasir lain (bukan Leader-nya sendiri) -- tetap terlihat.
        $resKasir = $this->withSession($this->sesi('kasir', 7))->get(self::ROUTE);
        $resKasir->assertOK();
        $bodyKasir = json_decode($resKasir->getJSON(), true);
        $this->assertSame(50, $bodyKasir['leader']['id']);
    }

    public function testTidakAdaShiftLeaderMengembalikanNull(): void
    {
        FakeClock::$override = self::HARI_TETAP . ' ' . self::JAM_TETAP . ':00';
        // Tidak ada user/jadwal yang di-seed -> tidak ada kandidat.

        $res = $this->withSession($this->sesi('kasir', 7))->get(self::ROUTE);

        $res->assertOK();
        $res->assertJSONFragment(['status' => 'success', 'leader' => null]);
    }
}
