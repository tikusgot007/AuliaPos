<?php

use App\Services\Authority;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Authority: seam tipis, hanya isAdmin() dan isCurrentShiftLeader().
 * TIDAK ada hasAuthority()/RBAC -- lihat App\Services\Authority.
 *
 * @internal
 */
final class AuthorityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'Tests\Support';
    protected $migrate   = true;
    protected $refresh   = true;

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

    public function testAdminAdalahAdmin(): void
    {
        $id = $this->seedUser('admin1', 'admin');

        $this->assertTrue(Authority::isAdmin($id));
    }

    public function testKasirBiasaBukanAdmin(): void
    {
        $id = $this->seedUser('kasir1', 'kasir');

        $this->assertFalse(Authority::isAdmin($id));
    }

    public function testKasirBiasaTidakOtomatisJadiLeader(): void
    {
        $id = $this->seedUser('kasir1', 'kasir', 1, 5);
        // Tidak ada jadwal hari ini -> bukan kandidat, apalagi Leader.

        $this->assertFalse(Authority::isCurrentShiftLeader($id, '2026-09-14', '09:00'));
    }

    public function testLeaderSaatIniMengembalikanTrue(): void
    {
        $id = $this->seedUser('aan', 'kasir', 1, 10);
        $this->seedJadwal($id, '2026-09-14', 'P');

        $this->assertTrue(Authority::isCurrentShiftLeader($id, '2026-09-14', '09:00'));
    }

    public function testKasirLainMengembalikanFalse(): void
    {
        $aan  = $this->seedUser('aan', 'kasir', 1, 10);
        $budi = $this->seedUser('budi', 'kasir', 1, 5);
        $this->seedJadwal($aan, '2026-09-14', 'P');
        $this->seedJadwal($budi, '2026-09-14', 'P');

        $this->assertTrue(Authority::isCurrentShiftLeader($aan, '2026-09-14', '09:00'));
        $this->assertFalse(Authority::isCurrentShiftLeader($budi, '2026-09-14', '09:00'));
    }

    public function testAdminDenganPriorityDanJadwalTetapBukanShiftLeader(): void
    {
        $admin = $this->seedUser('admin1', 'admin', 1, 99);
        $this->seedJadwal($admin, '2026-09-14', 'P');

        $this->assertTrue(Authority::isAdmin($admin));
        $this->assertFalse(Authority::isCurrentShiftLeader($admin, '2026-09-14', '09:00'));
    }

    public function testLeaderAuthorityIndependenDariUsersRole(): void
    {
        // Shift Leader TIDAK pernah ditulis sebagai users.role -- role
        // tetap 'kasir' persis, authority-nya murni hasil hitungan
        // EffectiveShiftLeaderService, tidak tersimpan di kolom mana pun.
        $id = $this->seedUser('aan', 'kasir', 1, 10);
        $this->seedJadwal($id, '2026-09-14', 'P');

        $user = (new \App\Models\UserModel())->find($id);
        $this->assertSame('kasir', $user['role']);
        $this->assertTrue(Authority::isCurrentShiftLeader($id, '2026-09-14', '09:00'));
        $this->assertSame('kasir', (new \App\Models\UserModel())->find($id)['role']); // tidak berubah setelah dicek
    }
}
