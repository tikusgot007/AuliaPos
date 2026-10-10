<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * TODO-BL28: halaman admin read-only "Log Revisi Closing Kas"
 * (`LogClosingKas::index()`/`data()`, tabel `closing_kas_audit` -- DB DEFAULT,
 * diarahkan ke SQLite `tests` saat ENVIRONMENT='testing').
 *
 * Membuktikan guard admin-only (kasir ditolak, non-login ke /login) dan bahwa
 * data() membaca snapshot JSON sebelum/sesudah + nama admin lewat JOIN `users`.
 *
 * @internal
 */
final class LogClosingKasTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $forge = \Config\Database::forge();
        $forge->dropTable('closing_kas_audit', true);
        $forge->dropTable('users', true);

        $forge->addField([
            'id'            => ['type' => 'INTEGER', 'auto_increment' => true],
            'username'      => ['type' => 'TEXT'],
            'nama'          => ['type' => 'TEXT', 'null' => true],
            'inisial'       => ['type' => 'TEXT', 'null' => true],
            'divisi'        => ['type' => 'TEXT', 'null' => true],
            'password_hash' => ['type' => 'TEXT', 'null' => true],
            'role'          => ['type' => 'TEXT', 'default' => 'kasir'],
            'is_active'     => ['type' => 'INTEGER', 'default' => 1],
            'no_hp'         => ['type' => 'TEXT', 'null' => true],
            'profile_photo' => ['type' => 'TEXT', 'null' => true],
            'priority'      => ['type' => 'INTEGER', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('users', true);
        \Config\Database::connect()->table('users')->insert(['id' => 4, 'username' => 'admin4', 'nama' => 'Bu Admin', 'role' => 'admin']);

        $forge->addField([
            'id'           => ['type' => 'INTEGER', 'constraint' => 20, 'auto_increment' => true],
            'closing_id'   => ['type' => 'INTEGER', 'constraint' => 20],
            'aksi'         => ['type' => 'VARCHAR', 'constraint' => 10],
            'data_sebelum' => ['type' => 'TEXT'],
            'data_sesudah' => ['type' => 'TEXT'],
            'user_id'      => ['type' => 'INTEGER', 'constraint' => 11],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('closing_kas_audit', true);
    }

    protected function tearDown(): void
    {
        $forge = \Config\Database::forge();
        $forge->dropTable('closing_kas_audit', true);
        $forge->dropTable('users', true);

        parent::tearDown();
    }

    private function seedRevisi(array $overrides = []): void
    {
        \Config\Database::connect()->table('closing_kas_audit')->insert(array_merge([
            'closing_id'   => 1,
            'aksi'         => 'update',
            'data_sebelum' => json_encode(['tanggal' => '2026-03-15 23:59:59', 'saldo_sistem' => 100000, 'saldo_fisik' => 98000, 'selisih' => -2000]),
            'data_sesudah' => json_encode(['tanggal' => '2026-03-15 23:59:59', 'saldo_sistem' => 100000, 'saldo_fisik' => 100500, 'selisih' => 500]),
            'user_id'      => 4,
            'created_at'   => date('Y-m-d H:i:s'),
        ], $overrides));
    }

    public function testNonLoginDiarahkanKeLogin(): void
    {
        $this->get('/log-closing-kas')->assertRedirectTo('/login');
    }

    public function testKasirDitolakAksesHalaman(): void
    {
        $this->withSession(['isLoggedIn' => true, 'id_user' => 1, 'role' => 'kasir'])
            ->get('/log-closing-kas')
            ->assertRedirectTo('/kasir');
    }

    public function testAdminBisaAksesHalaman(): void
    {
        $this->withSession(['isLoggedIn' => true, 'id_user' => 1, 'role' => 'admin'])
            ->get('/log-closing-kas')
            ->assertStatus(200);
    }

    public function testDataMenampilkanNamaAdminDanPerubahanSaldo(): void
    {
        $this->seedRevisi();

        $response = $this->withSession(['isLoggedIn' => true, 'id_user' => 1, 'role' => 'admin'])
            ->get('/log-closing-kas/data');
        $response->assertStatus(200);

        $json = json_decode($response->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertCount(1, $json['data']);

        $row = $json['data'][0];
        $this->assertSame('Bu Admin', $row['admin']);
        $this->assertSame(98000.0, (float) $row['saldo_fisik_sebelum']);
        $this->assertSame(100500.0, (float) $row['saldo_fisik_sesudah']);
        $this->assertSame(-2000.0, (float) $row['selisih_sebelum']);
        $this->assertSame(500.0, (float) $row['selisih_sesudah']);
    }

    public function testDataFilterTanggalMenyaring(): void
    {
        $this->seedRevisi(['created_at' => '2020-01-01 10:00:00']);
        $this->seedRevisi(['created_at' => date('Y-m-d H:i:s')]);

        $response = $this->withSession(['isLoggedIn' => true, 'id_user' => 1, 'role' => 'admin'])
            ->get('/log-closing-kas/data?tanggal_awal=' . date('Y-m-d') . '&tanggal_akhir=' . date('Y-m-d'));
        $response->assertStatus(200);

        $json = json_decode($response->getJSON(), true);
        $this->assertSame(1, $json['total']);
    }
}
