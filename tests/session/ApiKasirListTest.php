<?php

require_once __DIR__ . '/../_support/Fakes/ShiftLeaderClockOverride.php';

use App\Services\FakeClock;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * /api/kasir-list dipakai dropdown "Kasir Penerima" saat backdate
 * pembayaran (payment.js::loadKasirList()). Sejak Tahap 5, backdate
 * boleh dilakukan Shift Leader (bukan cuma admin) -- endpoint ini
 * HARUS ikut terbuka untuknya, kalau tidak dropdown tampil kosong
 * walau section backdate-nya sendiri sudah muncul di UI (regresi
 * yang ditemukan lewat laporan user, bukan diasumsikan).
 *
 * @internal
 */
final class ApiKasirListTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    protected $namespace = 'Tests\Support';
    protected $migrate   = true;
    protected $refresh   = true;

    private const URL = 'api/kasir-list';

    private const HARI_TETAP = '2026-09-14';
    private const JAM_TETAP  = '09:00'; // dalam jendela shift P (08:00-15:00)

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

    protected function tearDown(): void
    {
        FakeClock::reset();
        parent::tearDown();
    }

    public function testAdminBisaAksesDaftarKasir(): void
    {
        $res = $this->withSession($this->sesi('admin', 1))->get(self::URL);

        $res->assertJSONFragment(['status' => 'success']);
    }

    public function testShiftLeaderBisaAksesDaftarKasir(): void
    {
        FakeClock::$override = self::HARI_TETAP . ' ' . self::JAM_TETAP . ':00';

        $this->seedUser(50, 'kasir', 1, 10);
        $this->seedJadwal(50, self::HARI_TETAP, 'P');

        $res = $this->withSession($this->sesi('kasir', 50))->get(self::URL);

        $res->assertJSONFragment(['status' => 'success']);
    }

    public function testKasirBiasaBukanLeaderDitolak(): void
    {
        FakeClock::$override = self::HARI_TETAP . ' ' . self::JAM_TETAP . ':00';

        $this->seedUser(50, 'kasir', 1, null); // tidak di-ranking -> bukan kandidat Leader

        $res = $this->withSession($this->sesi('kasir', 50))->get(self::URL);

        $res->assertJSONFragment(['status' => 'error']);
    }
}
