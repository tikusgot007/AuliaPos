<?php

use App\Models\CashExpenseAuditModel;
use App\Models\CashExpenseModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * TODO-BL10: cash_expense update/delete must leave an append-only audit
 * trail (before/after JSON snapshot); create must NOT write an audit row.
 *
 * Runs on the `tests` SQLite group (:memory:, ENVIRONMENT=testing); it never
 * connects to the .env MySQL database -- see
 * tests/_support/bootstrap-integration.php and
 * docs/design/2026-10-08-audit-validasi-kas-keluar.md Section 6.
 *
 * @internal
 */
final class CashExpenseAuditTest extends CIUnitTestCase
{
    private const USER_ID = 7;

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

    public function testUpdateWritesAuditRowWithBeforeAndAfter(): void
    {
        $expenseModel = new CashExpenseModel();
        $id = $expenseModel->insert([
            'tanggal'    => '2026-10-05 10:00:00',
            'kategori'   => 'pengeluaran',
            'nominal'    => 50000,
            'keterangan' => 'Beli ATK',
            'penerima'   => null,
            'user_id'    => 1,
        ]);

        $status = $expenseModel->updatePengeluaran($id, [
            'tanggal'    => '2026-10-05 10:00:00',
            'nominal'    => 500000,
            'keterangan' => 'Beli ATK (revisi)',
            'penerima'   => 'Budi',
        ], self::USER_ID);

        $this->assertTrue($status);

        $auditRows = (new CashExpenseAuditModel())->where('expense_id', $id)->findAll();
        $this->assertCount(1, $auditRows);

        $audit = $auditRows[0];
        $this->assertSame('update', $audit['aksi']);
        $this->assertSame(self::USER_ID, (int) $audit['user_id']);

        $sebelum = json_decode($audit['data_sebelum'], true);
        $sesudah = json_decode($audit['data_sesudah'], true);

        $this->assertSame(50000.0, (float) $sebelum['nominal']);
        $this->assertSame('Beli ATK', $sebelum['keterangan']);
        $this->assertSame(500000.0, (float) $sesudah['nominal']);
        $this->assertSame('Beli ATK (revisi)', $sesudah['keterangan']);
        $this->assertSame('Budi', $sesudah['penerima']);

        // The expense row itself was actually updated.
        $updated = $expenseModel->find($id);
        $this->assertSame(500000.0, (float) $updated['nominal']);
    }

    public function testDeleteWritesAuditRowAndRemovesExpense(): void
    {
        $expenseModel = new CashExpenseModel();
        $id = $expenseModel->insert([
            'tanggal'    => '2026-10-05 10:00:00',
            'kategori'   => 'pengeluaran',
            'nominal'    => 75000,
            'keterangan' => 'Bayar listrik',
            'penerima'   => null,
            'user_id'    => 1,
        ]);

        $status = $expenseModel->hapusPengeluaran($id, self::USER_ID);

        $this->assertTrue($status);

        // The row is really gone (hard delete, same as before this change).
        $this->assertNull($expenseModel->find($id));

        $auditRows = (new CashExpenseAuditModel())->where('expense_id', $id)->findAll();
        $this->assertCount(1, $auditRows);

        $audit = $auditRows[0];
        $this->assertSame('delete', $audit['aksi']);
        $this->assertSame(self::USER_ID, (int) $audit['user_id']);
        $this->assertNull($audit['data_sesudah']);

        $sebelum = json_decode($audit['data_sebelum'], true);
        $this->assertSame(75000.0, (float) $sebelum['nominal']);
        $this->assertSame('Bayar listrik', $sebelum['keterangan']);
    }

    /**
     * Regression guard: a validation failure inside update() (e.g. nominal
     * fails `greater_than[0]`) returns false WITHOUT a DB-level error, so
     * transStatus() alone would not notice it. The row must stay unchanged
     * and NO audit row may be written for a change that never happened.
     */
    public function testUpdateValidationFailureWritesNoAuditRowAndKeepsRowUnchanged(): void
    {
        $expenseModel = new CashExpenseModel();
        $id = $expenseModel->insert([
            'tanggal'    => '2026-10-05 10:00:00',
            'kategori'   => 'pengeluaran',
            'nominal'    => 50000,
            'keterangan' => 'Beli ATK',
            'penerima'   => null,
            'user_id'    => 1,
        ]);

        $status = $expenseModel->updatePengeluaran($id, [
            'tanggal'    => '2026-10-05 10:00:00',
            'nominal'    => 0, // fails greater_than[0]
            'keterangan' => 'Coba ubah jadi nol',
            'penerima'   => null,
        ], self::USER_ID);

        $this->assertFalse($status);

        $row = $expenseModel->find($id);
        $this->assertSame(50000.0, (float) $row['nominal'], 'Row must be unchanged after a failed validation.');

        $this->assertSame(0, (new CashExpenseAuditModel())->countAll(), 'No audit row for a change that never happened.');
    }

    public function testCreateWritesNoAuditRow(): void
    {
        $expenseModel = new CashExpenseModel();
        $expenseModel->simpanPengeluaran([
            'tanggal'    => '2026-10-05 10:00:00',
            'nominal'    => 30000,
            'keterangan' => 'Beli galon',
            'penerima'   => null,
            'user_id'    => 1,
        ]);

        $this->assertSame(0, (new CashExpenseAuditModel())->countAll());
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
    }

    private function dropSchema(): void
    {
        $forge = \Config\Database::forge();

        $forge->dropTable('cash_expense_audit', true);
        $forge->dropTable('cash_expense', true);
    }
}
