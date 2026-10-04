<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * TODO-BL05: a user with `is_active = 0` must not be able to log in, while an
 * active user still can. The inactive check runs after password verification so
 * the disabled status is not revealed to a caller without the password.
 *
 * Runs on the `tests` SQLite group (:memory:) under ENVIRONMENT=testing; the
 * `users` table is forged per test, so no .env database is touched.
 *
 * @internal
 */
final class AuthLoginAktifTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createUsersTable();
        $this->seedUsers();
    }

    protected function tearDown(): void
    {
        $forge = \Config\Database::forge();
        $forge->dropTable('users', true);

        parent::tearDown();
    }

    public function testUserNonAktifTidakBisaLogin(): void
    {
        $result = $this->post('/auth/proses-login', [
            'username' => 'nonaktif',
            'password' => 'secret',
        ]);

        $result->assertRedirect();
        $result->assertSessionMissing('isLoggedIn');
        $this->assertSame('Akun Anda tidak aktif. Hubungi admin.', service('session')->getFlashdata('error'));
    }

    public function testUserAktifBisaLogin(): void
    {
        $result = $this->post('/auth/proses-login', [
            'username' => 'aktif',
            'password' => 'secret',
        ]);

        $result->assertRedirect();
        $result->assertSessionHas('isLoggedIn', true);
    }

    public function testUserNonAktifDenganPasswordSalahTidakBocorkanStatus(): void
    {
        $result = $this->post('/auth/proses-login', [
            'username' => 'nonaktif',
            'password' => 'salah',
        ]);

        $result->assertRedirect();
        $result->assertSessionMissing('isLoggedIn');
        $this->assertSame('Password salah.', service('session')->getFlashdata('error'));
    }

    private function createUsersTable(): void
    {
        $forge = \Config\Database::forge();

        $forge->addField([
            'id'            => ['type' => 'INTEGER', 'constraint' => 11, 'auto_increment' => true],
            'username'      => ['type' => 'VARCHAR', 'constraint' => 50],
            'nama'          => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'inisial'       => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'divisi'        => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'password_hash' => ['type' => 'VARCHAR', 'constraint' => 255],
            'role'          => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'kasir'],
            'is_active'     => ['type' => 'INTEGER', 'constraint' => 1, 'default' => 1],
            'no_hp'         => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'profile_photo' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'priority'      => ['type' => 'INTEGER', 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('users', true);
    }

    private function seedUsers(): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);

        db_connect()->table('users')->insert([
            'username' => 'aktif', 'nama' => 'User Aktif', 'password_hash' => $hash,
            'role' => 'kasir', 'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'),
        ]);

        db_connect()->table('users')->insert([
            'username' => 'nonaktif', 'nama' => 'User Nonaktif', 'password_hash' => $hash,
            'role' => 'kasir', 'is_active' => 0, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
