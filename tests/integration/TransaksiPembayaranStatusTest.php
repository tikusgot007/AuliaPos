<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * TODO-BL04: a BATAL transaction is terminal and must not accept new payments.
 * The guard lives in the chokepoint TransaksiModel::tambahPembayaran(), so it
 * covers Api::tambahPembayaran(), Api::koreksiPembayaran() and any future
 * caller. Runs on the `tests` SQLite group (:memory:), never the .env DB.
 *
 * @internal
 */
final class TransaksiPembayaranStatusTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->dropSchema();

        parent::tearDown();
    }

    public function testBatalTransactionRejectsNewPayment(): void
    {
        $model = new \App\Models\TransaksiModel();

        $gagal = false;

        try {
            $model->tambahPembayaran(1, $this->paymentData());
        } catch (\Throwable $e) {
            $gagal = true;
            $this->assertStringContainsString('dibatalkan', $e->getMessage());
        }

        $this->assertTrue($gagal, 'A payment on a BATAL transaction must be rejected.');
        $this->assertSame(0, db_connect()->table('pembayaran')->countAllResults());
    }

    public function testPaymentMethodCorrectionKeepsOriginalDateAndCashier(): void
    {
        $db = db_connect();
        $originalDate = '2026-10-05 10:15:00';
        $db->table('transaksi')->insert([
            'id' => 3, 'tanggal' => $originalDate, 'grand_total' => 100000,
            'total_dibayar' => 100000, 'status_pembayaran' => 'lunas', 'status' => 'proses',
            'created_at' => $originalDate, 'updated_at' => $originalDate,
        ]);
        $db->table('pembayaran')->insert([
            'id' => 1, 'transaksi_id' => 3, 'tanggal' => $originalDate, 'jumlah' => 100000,
            'uang_diterima' => 100000, 'kembalian' => 0, 'metode' => 'tunai',
            'keterangan' => 'Lunas', 'kasir_id' => 7, 'status' => 'aktif',
        ]);

        $result = (new \\App\\Models\\TransaksiModel())->koreksiMetodePembayaran(
            3, 1, 'qris', null, 'Salah metode', 12
        );

        $old = $db->table('pembayaran')->where('id', 1)->get()->getRowArray();
        $new = $db->table('pembayaran')->where('id', $result['new_payment_id'])->get()->getRowArray();

        $this->assertSame('reversed', $old['status']);
        $this->assertSame($originalDate, $new['tanggal']);
        $this->assertSame('7', (string) $new['kasir_id']);
        $this->assertSame('qris', $new['metode']);
        $this->assertStringContainsString('user #12', $new['keterangan']);
        $this->assertEqualsWithDelta(100000.0, (float) $new['jumlah'], 0.001);
    }

    public function testProsesTransactionAcceptsPayment(): void
    {
        $model = new \App\Models\TransaksiModel();

        $model->tambahPembayaran(2, $this->paymentData());

        $row = db_connect()->table('transaksi')->where('id', 2)->get()->getRowArray();

        $this->assertSame('lunas', $row['status_pembayaran']);
        $this->assertEqualsWithDelta(100000.0, (float) $row['total_dibayar'], 0.001);
        $this->assertSame(1, db_connect()->table('pembayaran')->where('transaksi_id', 2)->countAllResults());
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentData(): array
    {
        return [
            'tanggal'       => date('Y-m-d H:i:s'),
            'jumlah'        => 100000,
            'metode'        => 'tunai',
            'uang_diterima' => 100000,
            'kembalian'     => 0,
            'keterangan'    => 'Lunas',
            'kasir_id'      => 1,
        ];
    }

    private function createSchema(): void
    {
        $forge = \Config\Database::forge();

        $forge->addField([
            'id'                => ['type' => 'INTEGER', 'constraint' => 11, 'auto_increment' => true],
            'tanggal'           => ['type' => 'DATETIME', 'null' => true],
            'grand_total'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'total_dibayar'     => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'status_pembayaran' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'belum_bayar'],
            'status'            => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'proses'],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('transaksi', true);

        $forge->addField([
            'id'            => ['type' => 'INTEGER', 'constraint' => 11, 'auto_increment' => true],
            'transaksi_id'  => ['type' => 'INTEGER', 'constraint' => 11],
            'tanggal'       => ['type' => 'DATETIME', 'null' => true],
            'jumlah'        => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'uang_diterima' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'kembalian'     => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'metode'        => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'tunai'],
            'keterangan'    => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'kasir_id'      => ['type' => 'INTEGER', 'null' => true],
            'status'        => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'aktif'],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('pembayaran', true);
    }

    private function seed(): void
    {
        $db = db_connect();
        $now = date('Y-m-d H:i:s');

        $db->table('transaksi')->insert([
            'id' => 1, 'tanggal' => $now, 'grand_total' => 100000,
            'total_dibayar' => 0, 'status_pembayaran' => 'belum_bayar', 'status' => 'batal',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $db->table('transaksi')->insert([
            'id' => 2, 'tanggal' => $now, 'grand_total' => 100000,
            'total_dibayar' => 0, 'status_pembayaran' => 'belum_bayar', 'status' => 'proses',
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function dropSchema(): void
    {
        $forge = \Config\Database::forge();

        $forge->dropTable('pembayaran', true);
        $forge->dropTable('transaksi', true);
    }
}
