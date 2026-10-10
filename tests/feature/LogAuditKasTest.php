<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * TODO-L3 (paket halaman log): halaman admin read-only "Log Audit Kas"
 * (`LogAuditKas::index()`/`data()`, tabel `cash_expense_audit` -- DB
 * DEFAULT, bukan `inbox`; diarahkan ke SQLite `tests` saat ENVIRONMENT=
 * 'testing', lihat app/Config/Database.php).
 *
 * Membuktikan:
 *  - Guard admin-only: kasir ditolak, non-login diarahkan ke /login.
 *  - Endpoint data() membaca snapshot JSON (data_sebelum/data_sesudah) dan
 *    JOIN langsung ke `users` (aman, satu database yang sama) untuk nama
 *    kasir.
 *  - Baris `delete` (data_sesudah NULL) tidak error, hanya nilai "sesudah"
 *    null.
 *
 * @internal
 */
final class LogAuditKasTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
        parent::tearDown();
    }

    private function createSchema(): void
    {
        $forge = \Config\Database::forge();

        $forge->dropTable('cash_expense_audit', true);
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
        \Config\Database::connect()->table('users')->insert(['id' => 3, 'username' => 'kasir3', 'nama' => 'Rudi', 'role' => 'kasir']);

        $forge->addField([
            'id'           => ['type' => 'INTEGER', 'constraint' => 20, 'auto_increment' => true],
            'expense_id'   => ['type' => 'INTEGER', 'constraint' => 20],
            'aksi'         => ['type' => 'VARCHAR', 'constraint' => 10],
            'data_sebelum' => ['type' => 'TEXT'],
            'data_sesudah' => ['type' => 'TEXT', 'null' => true],
            'user_id'      => ['type' => 'INTEGER', 'constraint' => 11],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('cash_expense_audit', true);
    }

    private function dropSchema(): void
    {
        $forge = \Config\Database::forge();
        $forge->dropTable('cash_expense_audit', true);
        $forge->dropTable('users', true);
    }

    private function seedAuditRow(array $overrides = []): void
    {
        \Config\Database::connect()->table('cash_expense_audit')->insert(array_merge([
            'expense_id'   => 1,
            'aksi'         => 'update',
            'data_sebelum' => json_encode(['nominal' => 50000, 'keterangan' => 'Beli ATK', 'kategori' => 'pengeluaran', 'tanggal' => '2026-10-05 10:00:00']),
            'data_sesudah' => json_encode(['nominal' => 500000, 'keterangan' => 'Beli ATK (revisi)', 'kategori' => 'pengeluaran', 'tanggal' => '2026-10-05 10:00:00']),
            'user_id'      => 3,
            'created_at'   => date('Y-m-d H:i:s'),
        ], $overrides));
    }

    public function testNonLoginDiarahkanKeLogin(): void
    {
        $response = $this->get('/log-audit-kas');
        $response->assertRedirectTo('/login');
    }

    public function testKasirDitolakAksesHalaman(): void
    {
        $response = $this->withSession(['isLoggedIn' => true, 'id_user' => 1, 'role' => 'kasir'])
            ->get('/log-audit-kas');
        $response->assertRedirectTo('/kasir');
    }

    public function testAdminBisaAksesHalaman(): void
    {
        $response = $this->withSession(['isLoggedIn' => true, 'id_user' => 1, 'role' => 'admin'])
            ->get('/log-audit-kas');
        $response->assertStatus(200);
    }

    public function testDataMenampilkanNamaKasirDanPerubahanNominal(): void
    {
        $this->seedAuditRow();

        $response = $this->withSession(['isLoggedIn' => true, 'id_user' => 1, 'role' => 'admin'])
            ->get('/log-audit-kas/data');
        $response->assertStatus(200);

        $json = json_decode($response->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertCount(1, $json['data']);
        $row = $json['data'][0];
        $this->assertSame('Rudi', $row['kasir'], 'nama kasir harus di-JOIN langsung dari users (DB sama)');
        $this->assertSame('update', $row['aksi']);
        $this->assertSame(50000.0, (float) $row['nominal_sebelum']);
        $this->assertSame(500000.0, (float) $row['nominal_sesudah']);
    }

    public function testDataBarisHapusTidakErrorWalauDataSesudahNull(): void
    {
        $this->seedAuditRow([
            'aksi'         => 'delete',
            'data_sesudah' => null,
        ]);

        $response = $this->withSession(['isLoggedIn' => true, 'id_user' => 1, 'role' => 'admin'])
            ->get('/log-audit-kas/data');
        $response->assertStatus(200);

        $json = json_decode($response->getJSON(), true);
        $this->assertCount(1, $json['data']);
        $this->assertSame('delete', $json['data'][0]['aksi']);
        $this->assertNull($json['data'][0]['nominal_sesudah']);
        $this->assertSame(50000.0, (float) $json['data'][0]['nominal_sebelum']);
    }

    public function testDataFilterTanggalMenyaring(): void
    {
        $this->seedAuditRow(['created_at' => '2020-01-01 10:00:00']);
        $this->seedAuditRow(['created_at' => date('Y-m-d H:i:s')]);

        $response = $this->withSession(['isLoggedIn' => true, 'id_user' => 1, 'role' => 'admin'])
            ->get('/log-audit-kas/data?tanggal_awal=' . date('Y-m-d') . '&tanggal_akhir=' . date('Y-m-d'));
        $response->assertStatus(200);

        $json = json_decode($response->getJSON(), true);
        $this->assertSame(1, $json['total'], 'hanya baris hari ini yang lolos filter');
    }
}
