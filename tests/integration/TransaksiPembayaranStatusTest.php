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
     * TODO-BL14: Koreksi pembayaran (reversed + insert baru) harus maintain cache consistency.
     * Test flow: UPDATE old→reversed, INSERT new→aktif, sync cache.
     * Seluruh operasi dalam single transaction boundary, atomic rollback jika gagal.
     */
    public function testKoreksiPembayaranAtomicTransaction(): void
    {
        $model = new \App\Models\TransaksiModel();
        $db = db_connect();

        $model->tambahPembayaran(2, $this->paymentData());

        $pembayaranLama = $db->table('pembayaran')
            ->where('transaksi_id', 2)
            ->where('status', 'aktif')
            ->get()
            ->getRowArray();

        $this->assertNotNull($pembayaranLama, 'Initial payment must exist');

        $dataBaru = [
            'tanggal'       => date('Y-m-d H:i:s'),
            'jumlah'        => 100000,
            'metode'        => 'qris',
            'uang_diterima' => null,
            'kembalian'     => 0,
            'keterangan'    => 'Koreksi metode tunai → qris',
            'kasir_id'      => 1,
        ];

        $db->transBegin();
        try {
            $updated = $db->table('pembayaran')
                ->where('id', $pembayaranLama['id'])
                ->where('status', 'aktif')
                ->set(['status' => 'reversed'])
                ->update();

            if (!$updated) {
                throw new \Exception('Pembayaran lama gagal ditandai reversed');
            }

            $model->koreksiPembayaranTanpaSync(2, $dataBaru);
            $model->sinkronkanPembayaran(2);

            $db->transComplete();
            if (!$db->transStatus()) {
                throw new \Exception('Transaksi database gagal');
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            $this->fail('Koreksi pembayaran atomic failed: ' . $e->getMessage());
        }

        $transaksi = $db->table('transaksi')->where('id', 2)->get()->getRowArray();
        $pembayaranAktif = $db->table('pembayaran')
            ->where('transaksi_id', 2)
            ->where('status', 'aktif')
            ->get()
            ->getResultArray();
        $pembayaranReversed = $db->table('pembayaran')
            ->where('transaksi_id', 2)
            ->where('status', 'reversed')
            ->get()
            ->getResultArray();

        $totalAktif = array_sum(array_column($pembayaranAktif, 'jumlah'));

        $this->assertCount(1, $pembayaranAktif, 'Must have exactly 1 aktif payment');
        $this->assertCount(1, $pembayaranReversed, 'Must have exactly 1 reversed payment');
        $this->assertSame('reversed', $pembayaranReversed[0]['status'], 'Old payment must be reversed');
        $this->assertSame('aktif', $pembayaranAktif[0]['status'], 'New payment must be aktif');
        $this->assertSame('qris', $pembayaranAktif[0]['metode'], 'New payment metode must be qris');

        $this->assertEqualsWithDelta(100000.0, (float) $transaksi['total_dibayar'], 0.001, 'Cache must equal aktif payment only, not 200k or doubled');
        $this->assertEqualsWithDelta($totalAktif, (float) $transaksi['total_dibayar'], 0.001, 'Cache must match SUM(aktif)');
        $this->assertSame('lunas', $transaksi['status_pembayaran'], 'Status pembayaran must be lunas');

        $consistency = $model->cekKonsistensiPembayaran(2);
        $this->assertTrue($consistency['konsisten'], 'After koreksi, pembayaran must be consistent: ' . json_encode($consistency));
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
