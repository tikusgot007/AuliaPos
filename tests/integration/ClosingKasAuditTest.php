<?php

use App\Models\ClosingKasAuditModel;
use App\Models\ClosingKasModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * TODO-BL28: every edit of an existing closing row must leave one append-only
 * revision row (`closing_kas_audit`) with the full before/after snapshot, written
 * in the SAME transaction as the change. Creating a closing records no revision.
 *
 * Runs against the `tests` SQLite group (:memory:); no MySQL is touched.
 *
 * @internal
 */
final class ClosingKasAuditTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $forge = \Config\Database::forge();

        $forge->addField([
            'id'           => ['type' => 'INTEGER', 'auto_increment' => true],
            'username'     => ['type' => 'TEXT'],
            'nama'         => ['type' => 'TEXT', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('users', true);
        db_connect()->table('users')->insert(['id' => 5, 'username' => 'admin5', 'nama' => 'Bu Admin']);

        $forge->addField([
            'id'           => ['type' => 'INTEGER', 'constraint' => 11, 'auto_increment' => true],
            'tanggal'      => ['type' => 'DATETIME'],
            'saldo_sistem' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'saldo_fisik'  => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'selisih'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_by'   => ['type' => 'INTEGER', 'default' => 0],
        ]);
        $forge->addKey('id', true);
        $forge->addUniqueKey('tanggal');
        $forge->createTable('closing_kas', true);

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
        // The `tests` connection is shared across the whole suite. A forced
        // rollback leaves its transaction status flagged as failed, which would
        // make every later test's transStatus() fail -- reset it.
        db_connect()->resetTransStatus();

        $forge = \Config\Database::forge();
        $forge->dropTable('closing_kas_audit', true);
        $forge->dropTable('closing_kas', true);
        $forge->dropTable('users', true);

        parent::tearDown();
    }

    public function testCreatingClosingWritesNoRevision(): void
    {
        (new ClosingKasModel())->simpanClosing('2026-03-15', 100000.0, 98000.0, 5);

        $this->assertSame(0, (int) db_connect()->table('closing_kas_audit')->countAllResults());
    }

    public function testEditingClosingWritesBeforeAfterSnapshot(): void
    {
        $model = new ClosingKasModel();
        $id = $model->simpanClosing('2026-03-15', 100000.0, 98000.0, 5);
        $model->simpanClosing('2026-03-15', 100000.0, 100500.0, 5);

        $rows = db_connect()->table('closing_kas_audit')->get()->getResultArray();
        $this->assertCount(1, $rows);

        $audit = $rows[0];
        $this->assertSame((int) $id, (int) $audit['closing_id']);
        $this->assertSame('update', $audit['aksi']);
        $this->assertSame(5, (int) $audit['user_id']);

        $sebelum = json_decode($audit['data_sebelum'], true);
        $sesudah = json_decode($audit['data_sesudah'], true);
        $this->assertSame(98000.0, (float) $sebelum['saldo_fisik']);
        $this->assertSame(-2000.0, (float) $sebelum['selisih']);
        $this->assertSame(100500.0, (float) $sesudah['saldo_fisik']);
        $this->assertSame(500.0, (float) $sesudah['selisih']);
    }

    public function testEachEditAddsOneRevisionAndKeepsHistory(): void
    {
        $model = new ClosingKasModel();
        $model->simpanClosing('2026-03-15', 100000.0, 90000.0, 5);
        $model->simpanClosing('2026-03-15', 100000.0, 95000.0, 5);
        $model->simpanClosing('2026-03-15', 100000.0, 99000.0, 5);

        $rows = db_connect()->table('closing_kas_audit')->orderBy('id', 'ASC')->get()->getResultArray();
        $this->assertCount(2, $rows);

        $this->assertSame(90000.0, (float) json_decode($rows[0]['data_sebelum'], true)['saldo_fisik']);
        $this->assertSame(95000.0, (float) json_decode($rows[0]['data_sesudah'], true)['saldo_fisik']);
        $this->assertSame(95000.0, (float) json_decode($rows[1]['data_sebelum'], true)['saldo_fisik']);
        $this->assertSame(99000.0, (float) json_decode($rows[1]['data_sesudah'], true)['saldo_fisik']);
    }

    public function testFailedRevisionRollsBackTheChange(): void
    {
        $model = new ClosingKasModel();
        $model->simpanClosing('2026-03-15', 100000.0, 98000.0, 5);

        // Drop the audit table so writing the revision fails; the edit itself
        // must then be rolled back (no change without its history).
        \Config\Database::forge()->dropTable('closing_kas_audit', true);

        try {
            $model->simpanClosing('2026-03-15', 100000.0, 123456.0, 5);
            $this->fail('Expected an exception when the revision cannot be written.');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
        }

        $row = (new ClosingKasModel())->getByTanggal('2026-03-15');
        $this->assertSame(98000.0, (float) $row['saldo_fisik'], 'edit must be rolled back when its revision fails');
    }

    public function testDaftarUntukLogJoinsUserAndFiltersByRevisionTime(): void
    {
        $audit = new ClosingKasAuditModel();
        $audit->catatUpdate(1, ['saldo_fisik' => 1], ['saldo_fisik' => 2], 5);

        $rows = $audit->daftarUntukLog(null, null, 25, 0);
        $this->assertCount(1, $rows);
        $this->assertSame('Bu Admin', $rows[0]['nama']);
        $this->assertSame(1, $audit->hitungUntukLog(null, null));

        $this->assertSame(1, $audit->hitungUntukLog(date('Y-m-d'), date('Y-m-d')));
        $this->assertSame(0, $audit->hitungUntukLog('2020-01-01', '2020-01-02'));
    }
}
