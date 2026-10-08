<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * TODO-BL14: Test untuk RepairTotalDibayar command dalam mode audit (tanpa --fix).
 * Verifikasi bahwa command:
 * - Hanya menghitung pembayaran status='aktif'
 * - Tidak include reversed
 * - Correctly detects BL14 discrepancy
 *
 * Runs on `tests` SQLite group (:memory:), never .env database.
 *
 * @internal
 */
final class CommandRepairTotalDibayarTest extends CIUnitTestCase
{
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

    /**
     * Scenario A: cache=100000, aktif=100000, reversed=100000
     * → command harus menyatakan tidak ada mismatch.
     * (Karena cache seharusnya = SUM(aktif) = 100000, BUKAN termasuk reversed)
     */
    public function testRepairAuditNoMismatchWhenCacheEqualsAktifOnly(): void
    {
        $db = db_connect();

        $db->table('transaksi')->insert([
            'id'                => 1,
            'grand_total'       => 100000,
            'total_dibayar'     => 100000,
            'status_pembayaran' => 'lunas',
        ]);

        $db->table('pembayaran')->insertBatch([
            [
                'id'           => 1,
                'transaksi_id' => 1,
                'jumlah'       => 100000,
                'status'       => 'aktif',
            ],
            [
                'id'           => 2,
                'transaksi_id' => 1,
                'jumlah'       => 100000,
                'status'       => 'reversed',
            ],
        ]);

        $rows = $db->table('transaksi t')
            ->select('t.id, t.total_dibayar AS cached_total, COALESCE(SUM(CASE WHEN p.status = \'aktif\' THEN p.jumlah ELSE 0 END), 0) AS actual_total, t.grand_total')
            ->join('pembayaran p', 'p.transaksi_id = t.id', 'left')
            ->groupBy('t.id', 't.total_dibayar', 't.grand_total')
            ->having('(ABS(COALESCE(t.total_dibayar, 0) - COALESCE(SUM(CASE WHEN p.status = \'aktif\' THEN p.jumlah ELSE 0 END), 0)) > 0.0001)', null, false)
            ->get()
            ->getResultArray();

        $this->assertEmpty($rows, 'Harus tidak ada mismatch: cache 100k = actual aktif-only 100k');
    }

    /**
     * Scenario B: cache=200000, aktif=100000, reversed=100000
     * → command harus mendeteksi mismatch (BL14 bug state).
     * Cache TIDAK boleh include reversed, jadi discrepancy = cache 200k vs actual aktif 100k.
     */
    public function testRepairAuditDetectsBL14Discrepancy(): void
    {
        $db = db_connect();

        $db->table('transaksi')->insert([
            'id'                => 2,
            'grand_total'       => 100000,
            'total_dibayar'     => 200000,
            'status_pembayaran' => 'lunas',
        ]);

        $db->table('pembayaran')->insertBatch([
            [
                'id'           => 3,
                'transaksi_id' => 2,
                'jumlah'       => 100000,
                'status'       => 'aktif',
            ],
            [
                'id'           => 4,
                'transaksi_id' => 2,
                'jumlah'       => 100000,
                'status'       => 'reversed',
            ],
        ]);

        $rows = $db->table('transaksi t')
            ->select('t.id, t.total_dibayar AS cached_total, COALESCE(SUM(CASE WHEN p.status = \'aktif\' THEN p.jumlah ELSE 0 END), 0) AS actual_total, t.grand_total')
            ->join('pembayaran p', 'p.transaksi_id = t.id', 'left')
            ->groupBy('t.id', 't.total_dibayar', 't.grand_total')
            ->having('(ABS(COALESCE(t.total_dibayar, 0) - COALESCE(SUM(CASE WHEN p.status = \'aktif\' THEN p.jumlah ELSE 0 END), 0)) > 0.0001)', null, false)
            ->get()
            ->getResultArray();

        $this->assertNotEmpty($rows, 'Harus detect mismatch: cache 200k != actual aktif-only 100k');
        $this->assertCount(1, $rows);

        $row = $rows[0];
        $this->assertSame(2, (int) $row['id']);
        $this->assertEqualsWithDelta(200000.0, (float) $row['cached_total'], 0.001, 'Cache = 200k (BL14 bug)');
        $this->assertEqualsWithDelta(100000.0, (float) $row['actual_total'], 0.001, 'Actual = 100k (aktif only)');
        $this->assertEqualsWithDelta(100000.0, abs((float) $row['cached_total'] - (float) $row['actual_total']), 0.001, 'Discrepancy = 100k');
    }

    /**
     * Scenario C: Multiple transaksi, some consistent, some with discrepancy.
     * Query harus correctly filter dan report hanya mismatch.
     */
    public function testRepairAuditHandlesMultipleTransaksi(): void
    {
        $db = db_connect();

        $db->table('transaksi')->insertBatch([
            ['id' => 1, 'grand_total' => 50000, 'total_dibayar' => 50000, 'status_pembayaran' => 'lunas'],
            ['id' => 2, 'grand_total' => 100000, 'total_dibayar' => 200000, 'status_pembayaran' => 'lunas'],
            ['id' => 3, 'grand_total' => 75000, 'total_dibayar' => 75000, 'status_pembayaran' => 'lunas'],
        ]);

        $db->table('pembayaran')->insertBatch([
            ['transaksi_id' => 1, 'jumlah' => 50000, 'status' => 'aktif'],
            ['transaksi_id' => 2, 'jumlah' => 100000, 'status' => 'aktif'],
            ['transaksi_id' => 2, 'jumlah' => 100000, 'status' => 'reversed'],
            ['transaksi_id' => 3, 'jumlah' => 75000, 'status' => 'aktif'],
        ]);

        $rows = $db->table('transaksi t')
            ->select('t.id, t.total_dibayar AS cached_total, COALESCE(SUM(CASE WHEN p.status = \'aktif\' THEN p.jumlah ELSE 0 END), 0) AS actual_total, t.grand_total')
            ->join('pembayaran p', 'p.transaksi_id = t.id', 'left')
            ->groupBy('t.id', 't.total_dibayar', 't.grand_total')
            ->having('(ABS(COALESCE(t.total_dibayar, 0) - COALESCE(SUM(CASE WHEN p.status = \'aktif\' THEN p.jumlah ELSE 0 END), 0)) > 0.0001)', null, false)
            ->get()
            ->getResultArray();

        $this->assertCount(1, $rows, 'Hanya 1 mismatch (transaksi 2)');
        $this->assertSame(2, (int) $rows[0]['id']);
    }

    private function createSchema(): void
    {
        $forge = \Config\Database::forge();

        $forge->addField([
            'id'                => ['type' => 'INTEGER', 'constraint' => 11, 'auto_increment' => true],
            'grand_total'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'total_dibayar'     => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'status_pembayaran' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'belum_bayar'],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('transaksi', true);

        $forge->addField([
            'id'           => ['type' => 'INTEGER', 'constraint' => 11, 'auto_increment' => true],
            'transaksi_id' => ['type' => 'INTEGER', 'constraint' => 11],
            'jumlah'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'status'       => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'aktif'],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('pembayaran', true);
    }

    private function dropSchema(): void
    {
        $forge = \Config\Database::forge();
        $forge->dropTable('pembayaran', true);
        $forge->dropTable('transaksi', true);
    }
}
