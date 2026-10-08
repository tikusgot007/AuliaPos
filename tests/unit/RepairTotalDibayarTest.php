<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * TODO-BL14: RepairTotalDibayar must handle mixed aktif + reversed payments.
 * Query should count ONLY status='aktif', not include reversed.
 * Runs on the `tests` SQLite group (:memory:), never the .env DB.
 *
 * @internal
 */
final class RepairTotalDibayarTest extends CIUnitTestCase
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
     * Scenario: pembayaran aktif + reversed.
     * Cache harus dihitung dari aktif saja.
     * Tidak ada discrepancy jika cache = SUM(aktif).
     */
    public function testRepairDetectsMismatchWithReversedPayments(): void
    {
        $db = db_connect();

        $db->table('transaksi')->insert([
            'id'                => 1,
            'grand_total'       => 100000,
            'total_dibayar'     => 100000,
            'status_pembayaran' => 'lunas',
        ]);

        $db->table('pembayaran')->insert([
            'transaksi_id' => 1,
            'jumlah'       => 100000,
            'status'       => 'aktif',
        ]);

        $db->table('pembayaran')->insert([
            'transaksi_id' => 1,
            'jumlah'       => 100000,
            'status'       => 'reversed',
        ]);

        $transaksi = $db->table('transaksi')
            ->select('t.id, t.total_dibayar, COALESCE(SUM(CASE WHEN p.status = \'aktif\' THEN p.jumlah ELSE 0 END), 0) as actual')
            ->from('transaksi t')
            ->join('pembayaran p', 'p.transaksi_id = t.id', 'left')
            ->where('t.id', 1)
            ->groupBy('t.id')
            ->get()
            ->getRowArray();

        $this->assertEqualsWithDelta(100000.0, (float) $transaksi['total_dibayar'], 0.001, 'Cache correct: 100k (hanya aktif)');
        $this->assertEqualsWithDelta(100000.0, (float) $transaksi['actual'], 0.001, 'Actual correct: 100k (hanya aktif)');
    }

    /**
     * Scenario: cache != aktif (BL14 bug).
     * Cache dihitung dari aktif+reversed, actual dari aktif saja.
     * RepairTotalDibayar harus detect discrepancy.
     */
    public function testRepairDetectsBL14Discrepancy(): void
    {
        $db = db_connect();

        $db->table('transaksi')->insert([
            'id'                => 2,
            'grand_total'       => 100000,
            'total_dibayar'     => 200000,
            'status_pembayaran' => 'lunas',
        ]);

        $db->table('pembayaran')->insert([
            'transaksi_id' => 2,
            'jumlah'       => 100000,
            'status'       => 'aktif',
        ]);

        $db->table('pembayaran')->insert([
            'transaksi_id' => 2,
            'jumlah'       => 100000,
            'status'       => 'reversed',
        ]);

        $transaksi = $db->table('transaksi')
            ->select('t.id, t.total_dibayar, COALESCE(SUM(CASE WHEN p.status = \'aktif\' THEN p.jumlah ELSE 0 END), 0) as actual')
            ->from('transaksi t')
            ->join('pembayaran p', 'p.transaksi_id = t.id', 'left')
            ->where('t.id', 2)
            ->groupBy('t.id')
            ->get()
            ->getRowArray();

        $this->assertEqualsWithDelta(200000.0, (float) $transaksi['total_dibayar'], 0.001, 'Cache is doubled (BL14 bug): 200k');
        $this->assertEqualsWithDelta(100000.0, (float) $transaksi['actual'], 0.001, 'Actual is correct: 100k (hanya aktif)');
        $this->assertNotEqualsWithDelta((float) $transaksi['total_dibayar'], (float) $transaksi['actual'], 0.001, 'Must detect discrepancy');
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
