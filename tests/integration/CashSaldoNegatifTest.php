<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * TODO-BL30: a negative system balance is almost always a data error (kas awal not
 * entered, or an expense/refund with the wrong nominal). Before the fix it was used
 * silently by opname and closing. Now the server rejects opname/closing while the
 * balance is negative until the cashier sends an explicit confirmation, and the
 * opname records that confirmation permanently in alasan_selisih.
 *
 * Runs on the `tests` SQLite group (:memory:); the archive is redirected to a
 * temporary SQLite file so getSaldoKasHistoris() never touches the real archive.
 *
 * @internal
 */
final class CashSaldoNegatifTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private ?string $tmpBase = null;
    private ?string $originalArchivePath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $forge = \Config\Database::forge();

        $forge->addField([
            'id'            => ['type' => 'INTEGER', 'auto_increment' => true],
            'username'      => ['type' => 'TEXT'],
            'nama'          => ['type' => 'TEXT', 'null' => true],
            'inisial'       => ['type' => 'TEXT', 'null' => true],
            'divisi'        => ['type' => 'TEXT', 'null' => true],
            'profile_photo' => ['type' => 'TEXT', 'null' => true],
            'is_active'     => ['type' => 'INTEGER', 'default' => 1],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('users', true);
        db_connect()->table('users')->insert(['id' => 1, 'username' => 'admin-test', 'nama' => 'Admin Test']);

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
            'id'     => ['type' => 'INTEGER', 'auto_increment' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'proses'],
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
            'tanggal'      => ['type' => 'DATETIME'],
            'saldo_sistem' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'saldo_fisik'  => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'selisih'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_by'   => ['type' => 'INTEGER', 'default' => 1],
        ]);
        $forge->addKey('id', true);
        $forge->addUniqueKey('tanggal');
        $forge->createTable('closing_kas', true);

        // Arahkan archive ke file sementara agar getSaldoKasHistoris() tidak
        // menyentuh archive produksi (yang belum tentu ada di env test).
        $this->tmpBase = sys_get_temp_dir() . '/aulia_bl30_' . uniqid();
        mkdir($this->tmpBase, 0777, true);
        $this->originalArchivePath = config('Database')->archive['database'];
        config('Database')->archive['database'] = $this->tmpBase . '/archive.db';
    }

    protected function tearDown(): void
    {
        if ($this->originalArchivePath !== null) {
            config('Database')->archive['database'] = $this->originalArchivePath;
            $this->originalArchivePath = null;
        }

        $forge = \Config\Database::forge();
        foreach (['closing_kas', 'cash_opname', 'pembayaran', 'transaksi', 'cash_expense', 'users'] as $t) {
            $forge->dropTable($t, true);
        }

        if ($this->tmpBase !== null && is_dir($this->tmpBase)) {
            foreach (scandir($this->tmpBase) as $f) {
                if ($f === '.' || $f === '..') continue;
                @unlink($this->tmpBase . '/' . $f);
            }
            @rmdir($this->tmpBase);
        }
        $this->tmpBase = null;

        parent::tearDown();
    }

    private function seedPengeluaran(string $tanggal, float $nominal): void
    {
        db_connect()->table('cash_expense')->insert([
            'tanggal'    => $tanggal . ' 00:00:01',
            'kategori'   => 'pengeluaran',
            'nominal'    => $nominal,
            'created_at' => $tanggal . ' 00:00:01',
        ]);
    }

    private function adminPost(string $url, array $body)
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => 1, 'role' => 'admin'])
            ->withBodyFormat('json')
            ->post($url, $body);
    }

    public function testOpnameNegativeWithoutConfirmationIsRejected(): void
    {
        $this->seedPengeluaran(date('Y-m-d'), 50000);

        $response = $this->adminPost('/cash/simpan-opname', ['saldo_fisik' => 0]);
        $response->assertStatus(422);

        $json = json_decode($response->getJSON(), true);
        $this->assertSame('error', $json['status']);
        $this->assertTrue($json['saldo_negatif']);
        $this->assertSame(-50000.0, (float) $json['saldo_sistem']);
    }

    public function testOpnameNegativeWithConfirmationSucceedsAndRecordsPrefix(): void
    {
        $this->seedPengeluaran(date('Y-m-d'), 50000);

        $response = $this->adminPost('/cash/simpan-opname', [
            'saldo_fisik'               => 0,
            'konfirmasi_saldo_negatif'  => true,
        ]);
        $response->assertStatus(200);

        $json = json_decode($response->getJSON(), true);
        $this->assertSame('success', $json['status']);

        $row = db_connect()->table('cash_opname')->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertStringContainsString('[SALDO SISTEM NEGATIF', (string) $row['alasan_selisih']);
        $this->assertStringContainsString('dikonfirmasi kasir', (string) $row['alasan_selisih']);
    }

    public function testOpnameNormalBalanceUnaffected(): void
    {
        // kas awal 100.000, tanpa pengeluaran -> saldo positif.
        db_connect()->table('cash_expense')->insert([
            'tanggal' => date('Y-m-d') . ' 00:00:01', 'kategori' => 'kas_awal_hari', 'nominal' => 100000,
            'created_at' => date('Y-m-d') . ' 00:00:01',
        ]);

        $response = $this->adminPost('/cash/simpan-opname', ['saldo_fisik' => 100000]);
        $response->assertStatus(200);

        $row = db_connect()->table('cash_opname')->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertStringNotContainsString('SALDO SISTEM NEGATIF', (string) ($row['alasan_selisih'] ?? ''));
    }

    public function testClosingNegativeWithoutConfirmationIsRejected(): void
    {
        $kemarin = date('Y-m-d', strtotime('-1 day'));
        $this->seedPengeluaran($kemarin, 50000);

        $response = $this->adminPost('/cash/closing/simpan', [
            'tanggal' => $kemarin,
            'saldo_fisik' => 0,
        ]);
        $response->assertStatus(422);

        $json = json_decode($response->getJSON(), true);
        $this->assertSame('error', $json['status']);
        $this->assertTrue($json['saldo_negatif']);
    }

    public function testClosingNegativeWithConfirmationSucceeds(): void
    {
        $kemarin = date('Y-m-d', strtotime('-1 day'));
        $this->seedPengeluaran($kemarin, 50000);

        $response = $this->adminPost('/cash/closing/simpan', [
            'tanggal'                   => $kemarin,
            'saldo_fisik'               => 0,
            'konfirmasi_saldo_negatif'  => true,
        ]);
        $response->assertStatus(200);

        $this->assertSame('success', json_decode($response->getJSON(), true)['status']);
    }

    public function testDashboardShowsBannerWhenNegative(): void
    {
        $this->seedPengeluaran(date('Y-m-d'), 50000);

        $html = $this->withSession(['isLoggedIn' => true, 'id_user' => 1, 'role' => 'admin'])
            ->get('/cash')->getBody();

        $this->assertStringContainsString('Saldo sistem negatif', $html);
    }
}
