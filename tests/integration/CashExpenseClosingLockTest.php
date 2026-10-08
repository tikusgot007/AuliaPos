<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * TODO-BL10: a date that already has a `closing_kas` snapshot is final
 * (TODO-BL02) and must not accept new/changed/deleted `cash_expense` rows --
 * closing's `saldo_sistem` sums cash_expense (CashBalanceService::getBalance())
 * and would otherwise silently drift from its snapshot.
 *
 * Runs through the real HTTP routes (/cash/...) via FeatureTestTrait, on the
 * `tests` SQLite group (:memory:, ENVIRONMENT=testing); no .env database is
 * touched -- see docs/design/2026-10-08-audit-validasi-kas-keluar.md Section 6.
 *
 * @internal
 */
final class CashExpenseClosingLockTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const CLOSED_DAY = '2026-10-01';
    private const OPEN_DAY = '2026-10-02';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();

        db_connect()->table('closing_kas')->insert([
            'tanggal'      => self::CLOSED_DAY . ' 23:59:59',
            'saldo_sistem' => 100000,
            'saldo_fisik'  => 100000,
            'selisih'      => 0,
            'updated_at'   => date('Y-m-d H:i:s'),
            'updated_by'   => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropSchema();

        parent::tearDown();
    }

    /**
     * Cash's AJAX endpoints read the body via `$this->request->getJSON(true)`
     * (Cash.php:443,553), so requests must be sent as a JSON body, not a
     * regular form post -- see InboxGatewayApiMessageStatusTest.php for the
     * same `withBodyFormat('json')` pattern in this test suite.
     */
    private function asUser()
    {
        return $this
            ->withSession(['isLoggedIn' => true, 'id_user' => 7, 'role' => 'kasir'])
            ->withBodyFormat('json');
    }

    public function testCreateOnClosedDateIsRejected(): void
    {
        $response = $this->asUser()->post('/cash/tambah-pengeluaran', [
            'nominal'    => 50000,
            'keterangan' => 'Beli ATK',
            'tanggal'    => self::CLOSED_DAY . ' 10:00:00',
        ]);

        $json = json_decode($response->getJSON(), true);
        $this->assertSame('error', $json['status']);
        $this->assertSame(0, db_connect()->table('cash_expense')->countAllResults());
    }

    public function testUpdateRejectedWhenOldDateIsClosed(): void
    {
        $id = $this->insertExpense(self::CLOSED_DAY . ' 10:00:00', 50000);

        $response = $this->asUser()->post('/cash/edit-pengeluaran/' . $id, [
            'nominal'    => 999999,
            'keterangan' => 'Coba ubah',
            'tanggal'    => self::CLOSED_DAY . ' 10:00:00',
        ]);

        $json = json_decode($response->getJSON(), true);
        $this->assertSame('error', $json['status']);

        $row = db_connect()->table('cash_expense')->where('id', $id)->get()->getRowArray();
        $this->assertSame(50000.0, (float) $row['nominal'], 'Row must be unchanged.');
    }

    public function testUpdateRejectedWhenNewDateIsClosed(): void
    {
        $id = $this->insertExpense(self::OPEN_DAY . ' 10:00:00', 50000);

        $response = $this->asUser()->post('/cash/edit-pengeluaran/' . $id, [
            'nominal'    => 50000,
            'keterangan' => 'Pindah ke hari closed',
            'tanggal'    => self::CLOSED_DAY . ' 10:00:00',
        ]);

        $json = json_decode($response->getJSON(), true);
        $this->assertSame('error', $json['status']);

        $row = db_connect()->table('cash_expense')->where('id', $id)->get()->getRowArray();
        $this->assertStringStartsWith(self::OPEN_DAY, $row['tanggal'], 'Date must be unchanged.');
    }

    public function testUpdateOnOpenDateIsAccepted(): void
    {
        $id = $this->insertExpense(self::OPEN_DAY . ' 10:00:00', 50000);

        $response = $this->asUser()->post('/cash/edit-pengeluaran/' . $id, [
            'nominal'    => 60000,
            'keterangan' => 'Koreksi wajar',
            'tanggal'    => self::OPEN_DAY . ' 10:00:00',
        ]);

        $json = json_decode($response->getJSON(), true);
        $this->assertSame('success', $json['status']);

        $row = db_connect()->table('cash_expense')->where('id', $id)->get()->getRowArray();
        $this->assertSame(60000.0, (float) $row['nominal']);
    }

    public function testDeleteOnClosedDateIsRejected(): void
    {
        $id = $this->insertExpense(self::CLOSED_DAY . ' 10:00:00', 50000);

        $response = $this->asUser()->delete('/cash/hapus-pengeluaran/' . $id);

        $json = json_decode($response->getJSON(), true);
        $this->assertSame('error', $json['status']);
        $this->assertNotNull(db_connect()->table('cash_expense')->where('id', $id)->get()->getRowArray());
    }

    private function insertExpense(string $tanggal, float $nominal): int
    {
        db_connect()->table('cash_expense')->insert([
            'tanggal'    => $tanggal,
            'kategori'   => 'pengeluaran',
            'nominal'    => $nominal,
            'keterangan' => 'Fixture',
            'penerima'   => null,
            'user_id'    => 1,
        ]);

        return (int) db_connect()->insertID();
    }

    private function createSchema(): void
    {
        $forge = \Config\Database::forge();

        $forge->addField([
            'id'         => ['type' => 'INTEGER', 'constraint' => 11, 'auto_increment' => true],
            'tanggal'    => ['type' => 'DATETIME', 'null' => true],
            'kategori'   => ['type' => 'VARCHAR', 'constraint' => 50],
            'nominal'    => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'keterangan' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'penerima'   => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'user_id'    => ['type' => 'INTEGER', 'constraint' => 11],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('cash_expense', true);

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

        $forge->addField([
            'id'           => ['type' => 'INTEGER', 'constraint' => 11, 'auto_increment' => true],
            'tanggal'      => ['type' => 'DATETIME'],
            'saldo_sistem' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'saldo_fisik'  => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'selisih'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'updated_at'   => ['type' => 'DATETIME'],
            'updated_by'   => ['type' => 'INTEGER', 'constraint' => 11],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('closing_kas', true);
    }

    private function dropSchema(): void
    {
        $forge = \Config\Database::forge();

        $forge->dropTable('closing_kas', true);
        $forge->dropTable('cash_expense_audit', true);
        $forge->dropTable('cash_expense', true);
    }
}
