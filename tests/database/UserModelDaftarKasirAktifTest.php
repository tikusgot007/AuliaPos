<?php

use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * M3 Phase 2a (TB-01, TASK-002) -- UserModel::daftarKasirAktif()
 * contract (locked Q6):
 *
 *  - WHERE role='kasir' AND is_active=1 (admins and inactive users
 *    are never candidates -- P-03),
 *  - columns 'id' + 'nama' only,
 *  - ORDER BY nama ASC.
 *
 * @internal
 */
final class UserModelDaftarKasirAktifTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'Tests\\Support';
    protected $migrate   = true;
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();

        db_connect()->table('users')->emptyTable();
    }

    private function seedUser(int $id, string $nama, string $role, int $isActive): void
    {
        db_connect()->table('users')->insert([
            'id'         => $id,
            'username'   => 'user' . $id,
            'nama'       => $nama,
            'role'       => $role,
            'is_active'  => $isActive,
        ]);
    }

    public function testHanyaKasirAktifDanTerurutNamaAsc(): void
    {
        // Seed intentionally out of alphabetical order.
        $this->seedUser(21, 'Zahra', 'kasir', 1);
        $this->seedUser(22, 'Andi', 'kasir', 1);
        $this->seedUser(23, 'Boss', 'admin', 1);      // admin: excluded (P-03)
        $this->seedUser(24, 'Dimas', 'kasir', 0);     // inactive: excluded
        $this->seedUser(25, 'Cecep', 'kasir', 1);

        $daftar = (new UserModel())->daftarKasirAktif();

        $this->assertCount(3, $daftar, 'Only active kasir rows are candidates.');
        $this->assertSame(['Andi', 'Cecep', 'Zahra'], array_column($daftar, 'nama'));
        $this->assertSame([22, 25, 21], array_map('intval', array_column($daftar, 'id')));
    }

    public function testHanyaMengembalikanKolomIdDanNama(): void
    {
        $this->seedUser(31, 'Sari', 'kasir', 1);

        $daftar = (new UserModel())->daftarKasirAktif();

        $this->assertCount(1, $daftar);
        $this->assertSame(['id', 'nama'], array_keys($daftar[0]));
    }

    public function testAdminTidakPernahMuncul(): void
    {
        $this->seedUser(41, 'Admin Satu', 'admin', 1);
        $this->seedUser(42, 'Admin Dua', 'admin', 1);

        $this->assertSame([], (new UserModel())->daftarKasirAktif());
    }

    public function testSemuaKasirNonaktifMenghasilkanDaftarKosong(): void
    {
        $this->seedUser(51, 'Pensiun', 'kasir', 0);

        $this->assertSame([], (new UserModel())->daftarKasirAktif());
    }
}