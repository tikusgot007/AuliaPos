<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * TODO-BL25: a transaction created with a grand_total of exactly Rp 0 must get the
 * payment status the official rule gives it (KalkulasiStatusPembayaran: nothing
 * left to pay = lunas), instead of the hard-coded 'belum_bayar' that the piutang
 * and draft branches of Api::simpanTransaksi() used to write. That mismatch left a
 * "tagihan" that the list view showed as LUNAS while the stored column (used by the
 * "belum lunas" filter, the open-bills badge and reports) said belum_bayar.
 *
 * Goes through the real endpoint (Api::simpanTransaksi) on the `tests` SQLite group
 * (:memory:); no MySQL is touched.
 *
 * @internal
 */
final class TransaksiTotalNolStatusTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $forge = \Config\Database::forge();

        $forge->addField([
            'id'                      => ['type' => 'INTEGER', 'auto_increment' => true],
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
            'id'           => ['type' => 'INTEGER', 'auto_increment' => true],
            'transaksi_id' => ['type' => 'INTEGER'],
            'produk_id'    => ['type' => 'INTEGER', 'default' => 1],
            'nama_produk'  => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'kategori_id'  => ['type' => 'INTEGER', 'default' => 1],
            'jumlah'       => ['type' => 'INTEGER', 'default' => 1],
            'harga_satuan' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'subtotal'     => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'catatan'      => ['type' => 'TEXT', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('detail_transaksi', true);

        $forge->addField([
            'id'            => ['type' => 'INTEGER', 'auto_increment' => true],
            'transaksi_id'  => ['type' => 'INTEGER'],
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

        $forge->addField([
            'id'     => ['type' => 'INTEGER', 'auto_increment' => true],
            'nama'   => ['type' => 'VARCHAR', 'constraint' => 100],
            'no_hp'  => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
            'alamat' => ['type' => 'TEXT', 'null' => true],
            'diskon' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('pelanggan', true);

        db_connect()->table('pelanggan')->insert(['id' => 1, 'nama' => 'Pelanggan Uji']);
    }

    protected function tearDown(): void
    {
        $forge = \Config\Database::forge();
        $forge->dropTable('pelanggan', true);
        $forge->dropTable('pembayaran', true);
        $forge->dropTable('detail_transaksi', true);
        $forge->dropTable('transaksi', true);

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function simpan(string $metode, float $harga, array $extra = []): array
    {
        $response = $this->withSession(['isLoggedIn' => true, 'id_user' => 1, 'role' => 'admin'])
            ->withBodyFormat('json')
            ->post('/api/simpan-transaksi', array_merge([
                'metode'    => $metode,
                'pelanggan' => 1,
                'keranjang' => [[
                    'produk_id'   => 1,
                    'nama'        => 'Item Uji',
                    'kategori_id' => 1,
                    'jumlah'      => 1,
                    'harga'       => $harga,
                    'subtotal'    => $harga,
                ]],
            ], $extra));

        $response->assertStatus(200);

        return json_decode($response->getJSON(), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function barisTransaksi(int $id): array
    {
        return db_connect()->table('transaksi')->where('id', $id)->get()->getRowArray();
    }

    public function testPiutangTotalNolBecomesLunas(): void
    {
        $json = $this->simpan('piutang', 0);

        $this->assertSame('success', $json['status'], $json['message'] ?? '');
        $this->assertSame('lunas', $json['status_pembayaran']);

        $row = $this->barisTransaksi((int) $json['transaksi_id']);
        $this->assertSame('lunas', $row['status_pembayaran']);
        $this->assertSame(0.0, (float) $row['grand_total']);
    }

    public function testDraftTotalNolBecomesLunas(): void
    {
        $json = $this->simpan('draft', 0);

        $this->assertSame('success', $json['status'], $json['message'] ?? '');
        $this->assertSame('lunas', $this->barisTransaksi((int) $json['transaksi_id'])['status_pembayaran']);
    }

    public function testPiutangWithPositiveTotalStaysBelumBayar(): void
    {
        $json = $this->simpan('piutang', 100000);

        $this->assertSame('success', $json['status'], $json['message'] ?? '');
        $this->assertSame('belum_bayar', $json['status_pembayaran']);
        $this->assertSame('belum_bayar', $this->barisTransaksi((int) $json['transaksi_id'])['status_pembayaran']);
    }

    public function testDraftWithPositiveTotalStaysBelumBayar(): void
    {
        $json = $this->simpan('draft', 100000);

        $this->assertSame('success', $json['status'], $json['message'] ?? '');
        $this->assertSame('belum_bayar', $this->barisTransaksi((int) $json['transaksi_id'])['status_pembayaran']);
    }

    public function testZeroTotalCreatesNoPaymentRow(): void
    {
        $json = $this->simpan('piutang', 0);

        $this->assertSame(
            0,
            (int) db_connect()->table('pembayaran')->where('transaksi_id', (int) $json['transaksi_id'])->countAllResults()
        );
    }

    public function testDpAndCashPathsAreUnchanged(): void
    {
        $dp = $this->simpan('dp', 100000, ['jumlah_dp' => 40000, 'metode_dp' => 'tunai']);
        $this->assertSame('success', $dp['status'], $dp['message'] ?? '');
        $this->assertSame('dp', $this->barisTransaksi((int) $dp['transaksi_id'])['status_pembayaran']);

        $tunai = $this->simpan('tunai', 50000, ['uang_diterima' => 50000]);
        $this->assertSame('success', $tunai['status'], $tunai['message'] ?? '');
        $this->assertSame('lunas', $this->barisTransaksi((int) $tunai['transaksi_id'])['status_pembayaran']);
    }
}
