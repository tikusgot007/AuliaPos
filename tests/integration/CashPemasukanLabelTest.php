<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * TODO-BL29: "Pemasukan" meant two different things on the Kas screens. The Dashboard
 * Kas card showed (saldo - kas awal), i.e. net of expenses and refunds, while the
 * Opname screen/pop-up showed kas awal + penjualan tunai (gross). Same word, two
 * numbers for the same day.
 *
 * Now every figure on the card comes from one CashBalanceService result and the
 * wording is unambiguous: "Penjualan tunai" and "Kas awal + penjualan". The stored
 * opname data and the saldo formula are unchanged.
 *
 * Goes through the real pages on the `tests` SQLite group (:memory:); no MySQL.
 *
 * @internal
 */
final class CashPemasukanLabelTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $forge = \Config\Database::forge();

        $forge->addField([
            'id'          => ['type' => 'INTEGER', 'auto_increment' => true],
            'username'    => ['type' => 'TEXT'],
            'profile_photo' => ['type' => 'TEXT', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('users', true);
        db_connect()->table('users')->insert(['id' => 1, 'username' => 'admin-test']);

        $forge->addField([
            'id'         => ['type' => 'INTEGER', 'auto_increment' => true],
            'tanggal'    => ['type' => 'DATETIME'],
            'kategori'   => ['type' => 'VARCHAR', 'constraint' => 30],
            'nominal'    => ['type' => 'DECIMAL', 'constraint' => '15,2'],
            'keterangan' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'penerima'   => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'user_id'    => ['type' => 'INTEGER', 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('cash_expense', true);

        $forge->addField([
            'id'                => ['type' => 'INTEGER', 'auto_increment' => true],
            'tanggal'           => ['type' => 'DATETIME'],
            'saldo_awal_hari'   => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'pemasukan_tunai'   => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'pengeluaran_tunai' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'saldo_sistem'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'saldo_fisik'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'selisih'           => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'status_selisih'    => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'sesuai'],
            'alasan_selisih'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'catatan'           => ['type' => 'TEXT', 'null' => true],
            'user_id'           => ['type' => 'INTEGER', 'default' => 1],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('cash_opname', true);

        $forge->addField([
            'id'           => ['type' => 'INTEGER', 'auto_increment' => true],
            'status'       => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'proses'],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('transaksi', true);

        $forge->addField([
            'id'           => ['type' => 'INTEGER', 'auto_increment' => true],
            'transaksi_id' => ['type' => 'INTEGER'],
            'tanggal'      => ['type' => 'DATETIME'],
            'jumlah'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'metode'       => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'tunai'],
            'status'       => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'aktif'],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('pembayaran', true);

        $now = date('Y-m-d H:i:s');
        $db  = db_connect();

        // Kas awal 100.000 | penjualan tunai 500.000 | pengeluaran 30.000 | refund 20.000.
        $db->table('transaksi')->insert(['id' => 1, 'status' => 'proses']);
        $db->table('pembayaran')->insert(['transaksi_id' => 1, 'tanggal' => $now, 'jumlah' => 500000, 'metode' => 'tunai', 'status' => 'aktif']);
        $db->table('cash_expense')->insert(['tanggal' => $now, 'kategori' => 'kas_awal_hari', 'nominal' => 100000, 'created_at' => $now]);
        $db->table('cash_expense')->insert(['tanggal' => $now, 'kategori' => 'pengeluaran', 'nominal' => 30000, 'created_at' => $now]);
        $db->table('cash_expense')->insert(['tanggal' => $now, 'kategori' => 'refund_penjualan', 'nominal' => 20000, 'created_at' => $now]);
    }

    protected function tearDown(): void
    {
        $forge = \Config\Database::forge();
        foreach (['pembayaran', 'transaksi', 'cash_opname', 'cash_expense', 'users'] as $t) {
            $forge->dropTable($t, true);
        }

        parent::tearDown();
    }

    private function halaman(string $url): string
    {
        $response = $this->withSession(['isLoggedIn' => true, 'id_user' => 1, 'role' => 'admin'])->get($url);
        $response->assertStatus(200);

        return $response->getBody();
    }

    public function testDashboardCardFiguresAddUpToTheSaldo(): void
    {
        $html = $this->halaman('/cash');

        $this->assertStringContainsString('Penjualan tunai', $html);
        // saldo = 100.000 + 500.000 - (30.000 + 20.000) = 550.000
        $this->assertStringContainsString('Rp 550.000', $html);
        $this->assertStringContainsString('Rp 100.000', $html);
        // The sales box is the real cash sales (500.000), not saldo - kas awal (450.000).
        $this->assertStringContainsString('Rp 500.000', $html);
        $this->assertStringNotContainsString('Rp 450.000', $html);
        // Pengeluaran box now includes the refund (same source as the saldo): 50.000.
        $this->assertStringContainsString('Rp 50.000', $html);
    }

    public function testAmbiguousPemasukanWordingIsGoneFromTheCard(): void
    {
        $html = $this->halaman('/cash');

        $this->assertStringNotContainsString('Penjualan / Pemasukan', $html);
        $this->assertStringNotContainsString('>Masuk:', $html);
        $this->assertStringContainsString('Kas awal + penjualan', $html);
    }

    public function testOpnamePageShowsSalesNotAnAmbiguousPemasukan(): void
    {
        $html = $this->halaman('/cash/opname');

        $this->assertStringContainsString('Penjualan tunai: Rp 500.000', $html);
        $this->assertStringContainsString('Kas Awal: Rp 100.000', $html);
        $this->assertStringContainsString('Pengeluaran: Rp 50.000', $html);
        $this->assertStringContainsString('Rp 550.000', $html);
        $this->assertStringNotContainsString('Pemasukan:', $html);
    }

    public function testOpnameDetailShowsSalesWithoutCountingKasAwalTwice(): void
    {
        // Stored exactly as Cash::simpanOpname() writes it: pemasukan_tunai = kas awal + penjualan.
        db_connect()->table('cash_opname')->insert([
            'id'                => 1,
            'tanggal'           => date('Y-m-d H:i:s'),
            'saldo_awal_hari'   => 100000,
            'pemasukan_tunai'   => 600000,
            'pengeluaran_tunai' => 50000,
            'saldo_sistem'      => 550000,
            'saldo_fisik'       => 550000,
            'selisih'           => 0,
            'status_selisih'    => 'sesuai',
            'user_id'           => 1,
        ]);

        $html = $this->halaman('/cash/detail/1');

        $this->assertStringContainsString('Penjualan Tunai', $html);
        $this->assertStringContainsString('Rp 500.000', $html);
        $this->assertStringNotContainsString('Pemasukan Tunai', $html);

        // The stored value itself is untouched.
        $this->assertSame(
            600000.0,
            (float) db_connect()->table('cash_opname')->where('id', 1)->get()->getRowArray()['pemasukan_tunai']
        );
    }

    public function testSaldoSistemEndpointStillReturnsTheSameFields(): void
    {
        $response = $this->withSession(['isLoggedIn' => true, 'id_user' => 1, 'role' => 'admin'])
            ->get('/cash/get-saldo-sistem');
        $response->assertStatus(200);

        $json = json_decode($response->getJSON(), true);

        $this->assertSame('success', $json['status']);
        $this->assertSame(550000.0, (float) $json['saldo_sistem']);
        $this->assertSame(100000.0, (float) $json['kas_awal']);
        $this->assertSame(600000.0, (float) $json['pemasukan']);
        $this->assertSame(50000.0, (float) $json['pengeluaran']);
    }
}
