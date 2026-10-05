<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * TODO-BL01: creating a POS transaction must be atomic. The header, its detail
 * rows, and the initial payment row are written in a single database
 * transaction, so a failure at any step leaves no partial state and a retry
 * cannot duplicate the transaction.
 *
 * Runs on the `tests` SQLite group (:memory:, ENVIRONMENT=testing); it never
 * connects to the .env MySQL database.
 *
 * @internal
 */
final class TransaksiSimpanAtomikTest extends CIUnitTestCase
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

    private function conn(): \CodeIgniter\Database\BaseConnection
    {
        return db_connect();
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function headerData(string $invoice, float $grandTotal = 100000, array $overrides = []): array
    {
        return array_merge([
            'kode_invoice'            => $invoice,
            'no_order'                => null,
            'tanggal'                 => date('Y-m-d H:i:s'),
            'pelanggan_id'            => null,
            'kasir_id'                => 1,
            'subtotal'                => $grandTotal,
            'diskon'                  => 0,
            'diskon_pelanggan_persen' => null,
            'pajak'                   => 0,
            'grand_total'             => $grandTotal,
            'selisih_pembulatan'      => 0,
            'total_dibayar'           => 0,
            'status_pembayaran'       => 'lunas',
            'status'                  => 'proses',
            'sumber'                  => 'kasir_pos',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function paymentData(float $jumlah, array $overrides = []): array
    {
        return array_merge([
            'tanggal'       => date('Y-m-d H:i:s'),
            'jumlah'        => $jumlah,
            'metode'        => 'tunai',
            'uang_diterima' => $jumlah,
            'kembalian'     => 0,
            'keterangan'    => 'Lunas',
            'kasir_id'      => 1,
        ], $overrides);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function detailItems(float $subtotal = 100000): array
    {
        return [[
            'produk_id'    => 1,
            'nama_produk'  => 'Produk Uji',
            'kategori_id'  => 1,
            'jumlah'       => 1,
            'harga_satuan' => $subtotal,
            'subtotal'     => $subtotal,
            'catatan'      => '',
        ]];
    }

    public function testDailyInvoiceSequenceUsesTransactionDate(): void
    {
        $model = new \\App\\Models\\TransaksiModel();
        $tanggal = '2026-10-05 14:30:00';

        $firstId = $model->simpanTransaksi(
            $this->headerData('ignored-1', 100000, ['tanggal' => $tanggal]),
            $this->detailItems(),
            null
        );
        $secondId = $model->simpanTransaksi(
            $this->headerData('ignored-2', 100000, ['tanggal' => $tanggal]),
            $this->detailItems(),
            null
        );

        $this->assertSame(
            'INV-20261005-001',
            $this->conn()->table('transaksi')->where('id', $firstId)->get()->getRowArray()['kode_invoice']
        );
        $this->assertSame(
            'INV-20261005-002',
            $this->conn()->table('transaksi')->where('id', $secondId)->get()->getRowArray()['kode_invoice']
        );
    }

    public function testDailyInvoiceSequenceContinuesAfterExistingRows(): void
    {
        $model = new \\App\\Models\\TransaksiModel();
        $tanggal = '2026-10-06 09:00:00';

        $this->conn()->table('transaksi')->insert($this->headerData(
            'INV-20261006-007',
            100000,
            ['tanggal' => $tanggal]
        ));

        $id = $model->simpanTransaksi(
            $this->headerData('ignored-8', 100000, ['tanggal' => $tanggal]),
            $this->detailItems(),
            null
        );

        $this->assertSame(
            'INV-20261006-008',
            $this->conn()->table('transaksi')->where('id', $id)->get()->getRowArray()['kode_invoice']
        );
    }

    public function testDailyInvoiceSequenceRejectsMoreThan999(): void
    {
        $model = new \\App\\Models\\TransaksiModel();
        $tanggal = '2026-10-07 09:00:00';

        $this->conn()->table('transaksi')->insert($this->headerData(
            'INV-20261007-999',
            100000,
            ['tanggal' => $tanggal]
        ));

        $this->expectException(\\RuntimeException::class);
        $this->expectExceptionMessage('sudah mencapai batas 999');

        $model->simpanTransaksi(
            $this->headerData('ignored-overflow', 100000, ['tanggal' => $tanggal]),
            $this->detailItems(),
            null
        );
    }

    public function testCashCreateIsAtomicAndLunas(): void
    {
        $model = new \App\Models\TransaksiModel();

        $id = $model->simpanTransaksi(
            $this->headerData('INV-ATOMIC-001'),
            $this->detailItems(),
            $this->paymentData(100000)
        );

        $this->assertGreaterThan(0, $id);

        $header = $this->conn()->table('transaksi')->where('id', $id)->get()->getRowArray();

        $this->assertSame('lunas', $header['status_pembayaran']);
        $this->assertEqualsWithDelta(100000.0, (float) $header['total_dibayar'], 0.001);
        $this->assertSame(1, $this->conn()->table('detail_transaksi')->where('transaksi_id', $id)->countAllResults());
        $this->assertSame(1, $this->conn()->table('pembayaran')->where('transaksi_id', $id)->where('status', 'aktif')->countAllResults());
    }

    public function testPaymentStepFailureRollsBackWholeTransaction(): void
    {
        $model = new \App\Models\TransaksiModel();

        // Payment larger than the total makes the payment step fail AFTER the
        // header and detail rows were already written inside the open
        // transaction (AC-2).
        $firstFailed = false;

        try {
            $model->simpanTransaksi($this->headerData('INV-ATOMIC-002'), $this->detailItems(), $this->paymentData(150000));
        } catch (\Throwable $e) {
            $firstFailed = true;
        }

        $this->assertTrue($firstFailed, 'The payment step should have aborted the create.');
        $this->assertSame(0, $this->conn()->table('transaksi')->countAllResults());
        $this->assertSame(0, $this->conn()->table('detail_transaksi')->countAllResults());
        $this->assertSame(0, $this->conn()->table('pembayaran')->countAllResults());
    }

    public function testRetryAfterFailureLeavesSingleTransaction(): void
    {
        $model = new \App\Models\TransaksiModel();

        // First attempt fails at the payment step and must leave nothing behind.
        $firstFailed = false;

        try {
            $model->simpanTransaksi(
                $this->headerData('INV-ATOMIC-003'),
                $this->detailItems(),
                $this->paymentData(150000)
            );
        } catch (\Throwable $e) {
            $firstFailed = true;
        }

        $this->assertTrue($firstFailed, 'First attempt should have failed at the payment step.');

        // Retry reuses the same invoice; since the first attempt rolled back,
        // there is no collision and exactly one transaction must persist.
        $id = $model->simpanTransaksi(
            $this->headerData('INV-ATOMIC-003'),
            $this->detailItems(),
            $this->paymentData(100000)
        );

        $this->assertSame(1, $this->conn()->table('transaksi')->countAllResults());
        $this->assertSame(1, $this->conn()->table('pembayaran')->where('transaksi_id', $id)->countAllResults());
    }

    public function testPiutangAndDraftHaveNoPayment(): void
    {
        $model = new \App\Models\TransaksiModel();

        $piutangId = $model->simpanTransaksi(
            $this->headerData('INV-ATOMIC-004A', 100000, ['status_pembayaran' => 'belum_bayar']),
            $this->detailItems(),
            null
        );

        $draftId = $model->simpanTransaksi(
            $this->headerData('INV-ATOMIC-004B', 100000, ['status_pembayaran' => 'belum_bayar']),
            $this->detailItems(),
            null
        );

        foreach ([$piutangId, $draftId] as $id) {
            $header = $this->conn()->table('transaksi')->where('id', $id)->get()->getRowArray();
            $this->assertSame('belum_bayar', $header['status_pembayaran']);
            $this->assertSame(0, $this->conn()->table('pembayaran')->where('transaksi_id', $id)->countAllResults());
        }
    }

    public function testDpWritesOnePaymentAndDpStatus(): void
    {
        $model = new \App\Models\TransaksiModel();

        $id = $model->simpanTransaksi(
            $this->headerData('INV-ATOMIC-005', 100000, ['status_pembayaran' => 'dp']),
            $this->detailItems(),
            $this->paymentData(40000, ['keterangan' => 'DP'])
        );

        $row = $this->conn()->table('transaksi')->where('id', $id)->get()->getRowArray();

        $this->assertSame('dp', $row['status_pembayaran']);
        $this->assertEqualsWithDelta(40000.0, (float) $row['total_dibayar'], 0.001);
        $this->assertSame(1, $this->conn()->table('pembayaran')->where('transaksi_id', $id)->where('status', 'aktif')->countAllResults());
    }

    public function testExistingPaymentPathStillSyncs(): void
    {
        $model = new \App\Models\TransaksiModel();

        $id = $model->simpanTransaksi(
            $this->headerData('INV-ATOMIC-006', 100000, ['status_pembayaran' => 'belum_bayar']),
            $this->detailItems(),
            null
        );

        $model->tambahPembayaran($id, $this->paymentData(100000, ['metode' => 'qris', 'uang_diterima' => null, 'kembalian' => 0]));

        $row = $this->conn()->table('transaksi')->where('id', $id)->get()->getRowArray();

        $this->assertSame('lunas', $row['status_pembayaran']);
        $this->assertEqualsWithDelta(100000.0, (float) $row['total_dibayar'], 0.001);
        $this->assertSame(1, $this->conn()->table('pembayaran')->where('transaksi_id', $id)->where('status', 'aktif')->countAllResults());
    }

    private function createSchema(): void
    {
        $forge = \Config\Database::forge();

        $forge->addField([
            'id'                      => ['type' => 'INTEGER', 'constraint' => 11, 'auto_increment' => true],
            'kode_invoice'            => ['type' => 'VARCHAR', 'constraint' => 20],
            'no_order'                => ['type' => 'INTEGER', 'null' => true],
            'tanggal'                 => ['type' => 'DATETIME', 'null' => true],
            'pelanggan_id'            => ['type' => 'INTEGER', 'null' => true],
            'kasir_id'                => ['type' => 'INTEGER', 'null' => true],
            'subtotal'                => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'diskon'                  => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'diskon_pelanggan_persen' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'pajak'                   => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'grand_total'             => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'selisih_pembulatan'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'total_dibayar'           => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'status_pembayaran'       => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'belum_bayar'],
            'status'                  => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'proses'],
            'sumber'                  => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'kasir_pos'],
            'created_at'              => ['type' => 'DATETIME', 'null' => true],
            'updated_at'              => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('transaksi', true);

        $forge->addField([
            'id'           => ['type' => 'INTEGER', 'constraint' => 11, 'auto_increment' => true],
            'transaksi_id' => ['type' => 'INTEGER', 'constraint' => 11],
            'produk_id'    => ['type' => 'INTEGER', 'constraint' => 11, 'default' => 1],
            'nama_produk'  => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'kategori_id'  => ['type' => 'INTEGER', 'constraint' => 11, 'default' => 1],
            'jumlah'       => ['type' => 'INTEGER', 'constraint' => 11, 'default' => 1],
            'harga_satuan' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'subtotal'     => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'catatan'      => ['type' => 'TEXT', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('detail_transaksi', true);

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

    private function dropSchema(): void
    {
        $forge = \Config\Database::forge();

        $forge->dropTable('pembayaran', true);
        $forge->dropTable('detail_transaksi', true);
        $forge->dropTable('transaksi', true);
    }
}
