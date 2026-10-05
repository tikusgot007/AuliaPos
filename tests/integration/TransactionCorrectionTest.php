<?php

use CodeIgniter\Test\CIUnitTestCase;

final class TransactionCorrectionTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $forge = \Config\Database::forge();
        foreach (['transaction_correction', 'payment_correction_audit', 'pembayaran', 'detail_transaksi', 'transaksi'] as $table) {
            $forge->dropTable($table, true);
        }
        parent::tearDown();
    }

    public function testAC1CashierCanChangePaymentMethodAndKeepsAmount(): void
    {
        $model = new \App\Models\TransaksiModel();

        $result = $model->koreksiMetodePembayaran(1, 1, 'qris', null, 'Salah pilih metode', 7);

        $this->assertSame(1, $result['old_payment_id']);
        $this->assertSame('reversed', db_connect()->table('pembayaran')->where('id', 1)->get()->getRowArray()['status']);

        $new = db_connect()->table('pembayaran')->where('id', $result['new_payment_id'])->get()->getRowArray();
        $this->assertSame('qris', $new['metode']);
        $this->assertEqualsWithDelta(100000.0, (float) $new['jumlah'], 0.001);
        $this->assertSame('2026-10-05 09:30:00', $new['tanggal']);
        $this->assertSame(3, (int) $new['kasir_id']);
        $this->assertSame(1, db_connect()->table('payment_correction_audit')->where('operator_id', 7)->countAllResults());
    }

    public function testAC3SameMethodDoesNotMutatePayment(): void
    {
        $model = new \App\Models\TransaksiModel();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('sama dengan metode sebelumnya');
        $model->koreksiMetodePembayaran(1, 1, 'tunai', null, '', 7);

        $this->assertSame(1, db_connect()->table('pembayaran')->where('status', 'aktif')->countAllResults());
    }

    public function testAC5FinalCorrectionCreatesReplacementAndReversesOriginalPayment(): void
    {
        $model = new \App\Models\TransaksiModel();

        $replacementId = $model->koreksiTransaksiSelesai(
            1,
            [
                'tanggal' => '2026-10-05 09:00:00',
                'pelanggan_id' => null,
                'kasir_id' => 7,
                'subtotal' => 20000,
                'diskon' => 0,
                'diskon_pelanggan_persen' => null,
                'pajak' => 0,
                'grand_total' => 20000,
                'selisih_pembulatan' => 0,
                'sumber' => 'kasir_pos',
            ],
            [[
                'produk_id' => 11,
                'nama_produk' => 'Produk Koreksi',
                'kategori_id' => 1,
                'jumlah' => 1,
                'harga_satuan' => 20000,
                'subtotal' => 20000,
                'catatan' => null,
            ]],
            7,
            'Qty salah input'
        );

        $original = db_connect()->table('transaksi')->where('id', 1)->get()->getRowArray();
        $replacement = db_connect()->table('transaksi')->where('id', $replacementId)->get()->getRowArray();

        $this->assertSame('batal', $original['status']);
        $this->assertSame('selesai', $replacement['status']);
        $this->assertEqualsWithDelta(100000.0, (float) $replacement['total_dibayar'], 0.001);
        $this->assertSame(1, db_connect()->table('pembayaran')->where('transaksi_id', 1)->where('status', 'aktif')->countAllResults());
        $this->assertSame(1, db_connect()->table('pembayaran')->where('transaksi_id', 1)->where('status', 'reversed')->countAllResults());
        $this->assertSame(1, db_connect()->table('transaction_correction')->where('original_transaction_id', 1)->where('replacement_transaction_id', $replacementId)->countAllResults());
    }

    public function testAC6FinalCorrectionRollsBackOnDetailFailure(): void
    {
        $model = new \App\Models\TransaksiModel();

        try {
            $model->koreksiTransaksiSelesai(
                1,
                [
                    'tanggal' => '2026-10-05 09:00:00',
                    'pelanggan_id' => null,
                    'kasir_id' => 7,
                    'subtotal' => 20000,
                    'diskon' => 0,
                    'diskon_pelanggan_persen' => null,
                    'pajak' => 0,
                    'grand_total' => 20000,
                    'selisih_pembulatan' => 0,
                    'sumber' => 'kasir_pos',
                ],
                [['produk_id' => 999, 'nama_produk' => 'x', 'kategori_id' => 1, 'jumlah' => 1, 'harga_satuan' => 20000, 'subtotal' => null, 'catatan' => null]],
                7,
                'Uji rollback'
            );
        } catch (\Throwable $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertSame('selesai', db_connect()->table('transaksi')->where('id', 1)->get()->getRowArray()['status']);
    }

    private function createSchema(): void
    {
        $forge = \Config\Database::forge();

        $forge->addField([
            'id' => ['type' => 'INTEGER', 'constraint' => 11, 'auto_increment' => true],
            'kode_invoice' => ['type' => 'VARCHAR', 'constraint' => 30],
            'no_order' => ['type' => 'INTEGER', 'null' => true],
            'tanggal' => ['type' => 'DATETIME', 'null' => true],
            'pelanggan_id' => ['type' => 'INTEGER', 'null' => true],
            'kasir_id' => ['type' => 'INTEGER', 'default' => 1],
            'subtotal' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'diskon' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'diskon_pelanggan_persen' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'pajak' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'grand_total' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'selisih_pembulatan' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'total_dibayar' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'status_pembayaran' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'belum_bayar'],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'proses'],
            'sumber' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('transaksi', true);

        $forge->addField([
            'id' => ['type' => 'INTEGER', 'constraint' => 11, 'auto_increment' => true],
            'transaksi_id' => ['type' => 'INTEGER', 'constraint' => 11],
            'produk_id' => ['type' => 'INTEGER', 'constraint' => 11],
            'nama_produk' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'kategori_id' => ['type' => 'INTEGER', 'null' => true],
            'jumlah' => ['type' => 'DECIMAL', 'constraint' => '10,2'],
            'harga_satuan' => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'subtotal' => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'catatan' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('detail_transaksi', true);

        $forge->addField([
            'id' => ['type' => 'INTEGER', 'constraint' => 11, 'auto_increment' => true],
            'transaksi_id' => ['type' => 'INTEGER', 'constraint' => 11],
            'tanggal' => ['type' => 'DATETIME', 'null' => true],
            'jumlah' => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'uang_diterima' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => true],
            'kembalian' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'metode' => ['type' => 'VARCHAR', 'constraint' => 20],
            'keterangan' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'kasir_id' => ['type' => 'INTEGER', 'default' => 1],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'aktif'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('pembayaran', true);

        $forge->addField([
            'id' => ['type' => 'INTEGER', 'constraint' => 11, 'auto_increment' => true],
            'transaksi_id' => ['type' => 'INTEGER', 'constraint' => 11],
            'pembayaran_lama_id' => ['type' => 'INTEGER', 'constraint' => 11],
            'pembayaran_baru_id' => ['type' => 'INTEGER', 'constraint' => 11],
            'metode_lama' => ['type' => 'VARCHAR', 'constraint' => 20],
            'metode_baru' => ['type' => 'VARCHAR', 'constraint' => 20],
            'operator_id' => ['type' => 'INTEGER', 'constraint' => 11],
            'alasan' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('payment_correction_audit', true);

        $forge->addField([
            'id' => ['type' => 'INTEGER', 'constraint' => 11, 'auto_increment' => true],
            'original_transaction_id' => ['type' => 'INTEGER', 'constraint' => 11],
            'replacement_transaction_id' => ['type' => 'INTEGER', 'constraint' => 11],
            'operator_id' => ['type' => 'INTEGER', 'constraint' => 11],
            'reason' => ['type' => 'VARCHAR', 'constraint' => 255],
            'financial_delta' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->addUniqueKey('original_transaction_id');
        $forge->addUniqueKey('replacement_transaction_id');
        $forge->createTable('transaction_correction', true);
    }

    private function seed(): void
    {
        $db = db_connect();
        $db->table('transaksi')->insert([
            'id' => 1,
            'kode_invoice' => 'INV-OLD-001',
            'tanggal' => '2026-10-05 09:00:00',
            'kasir_id' => 3,
            'subtotal' => 100000,
            'grand_total' => 100000,
            'total_dibayar' => 100000,
            'status_pembayaran' => 'lunas',
            'status' => 'selesai',
            'sumber' => 'kasir_pos',
            'created_at' => '2026-10-05 09:00:00',
            'updated_at' => '2026-10-05 09:00:00',
        ]);
        $db->table('detail_transaksi')->insert([
            'transaksi_id' => 1,
            'produk_id' => 11,
            'nama_produk' => 'Produk Lama',
            'kategori_id' => 1,
            'jumlah' => 5,
            'harga_satuan' => 20000,
            'subtotal' => 100000,
        ]);
        $db->table('pembayaran')->insert([
            'id' => 1,
            'transaksi_id' => 1,
            'tanggal' => '2026-10-05 09:30:00',
            'jumlah' => 100000,
            'uang_diterima' => 100000,
            'kembalian' => 0,
            'metode' => 'tunai',
            'kasir_id' => 3,
            'status' => 'aktif',
            'created_at' => '2026-10-05 09:30:00',
        ]);
    }
}
