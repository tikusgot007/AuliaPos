<?php

use App\Services\TransaksiArchiveService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * TODO-BL02: a historical closing must still see cash sales that have moved to
 * the archive database, and the today-only balance path must NOT become
 * archive-aware.
 *
 * Live data uses the `tests` SQLite group (:memory:); the archive is a separate
 * temporary SQLite file injected into TransaksiArchiveService. No .env database
 * is touched.
 *
 * @internal
 */
final class ClosingKasArsipTest extends CIUnitTestCase
{
    private const DAY = '2026-03-15';

    private ?string $archiveFile = null;

    /** @var \CodeIgniter\Database\BaseConnection|null */
    private $archiveConn = null;

    protected function setUp(): void
    {
        parent::setUp();

        helper('cash');

        $this->createLiveSchema();
        $this->createArchiveDatabase();
    }

    protected function tearDown(): void
    {
        if ($this->archiveConn !== null) {
            $this->archiveConn->close();
            $this->archiveConn = null;
        }

        if ($this->archiveFile !== null && is_file($this->archiveFile)) {
            @unlink($this->archiveFile);
        }
        $this->archiveFile = null;

        $this->dropLiveSchema();

        parent::tearDown();
    }

    public function testArchivedCashSalesAreIncludedForHistoricalSaldo(): void
    {
        $cutoff = self::DAY . ' 23:59:59';

        // Today-only path stays live-only (AC-6): only the kas_awal is present.
        $this->assertSame(100000.0, getSaldoKasHariIni($cutoff)['saldo']);

        $historis = getSaldoKasHistoris($cutoff, new TransaksiArchiveService($this->archiveConn));

        // live kas_awal 100000 + archived tunai 50000 (AC-4).
        $this->assertSame(150000.0, $historis['saldo']);
        $this->assertSame(50000.0, $historis['penjualan']);
    }

    public function testArchivedSalesExcludeNonTunaiBatalAndReversed(): void
    {
        $svc = new TransaksiArchiveService($this->archiveConn);

        // Only the single tunai + aktif + non-batal payment counts.
        $this->assertSame(50000.0, $svc->getPenjualanTunaiMentah(self::DAY, self::DAY . ' 23:59:59'));

        // A cutoff before the archived payment must return 0.
        $this->assertSame(0.0, $svc->getPenjualanTunaiMentah(self::DAY, self::DAY . ' 09:00:00'));
    }

    private function createLiveSchema(): void
    {
        $forge = \Config\Database::forge();

        $forge->addField([
            'id'     => ['type' => 'INTEGER', 'constraint' => 11, 'auto_increment' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'proses'],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('transaksi', true);

        $forge->addField([
            'id'           => ['type' => 'INTEGER', 'constraint' => 11, 'auto_increment' => true],
            'transaksi_id' => ['type' => 'INTEGER', 'constraint' => 11],
            'tanggal'      => ['type' => 'DATETIME', 'null' => true],
            'jumlah'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'metode'       => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'tunai'],
            'status'       => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'aktif'],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('pembayaran', true);

        $forge->addField([
            'id'       => ['type' => 'INTEGER', 'constraint' => 11, 'auto_increment' => true],
            'tanggal'  => ['type' => 'DATETIME', 'null' => true],
            'kategori' => ['type' => 'VARCHAR', 'constraint' => 50],
            'nominal'  => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('cash_expense', true);

        // Live: only the opening cash for the day, no live sales.
        db_connect()->table('cash_expense')->insert([
            'tanggal'  => self::DAY . ' 08:00:00',
            'kategori' => 'kas_awal_hari',
            'nominal'  => 100000,
        ]);
    }

    private function createArchiveDatabase(): void
    {
        $this->archiveFile = tempnam(sys_get_temp_dir(), 'aulia_arch_');
        $this->assertNotFalse($this->archiveFile, 'Could not create a temporary archive database file.');

        $this->archiveConn = \Config\Database::connect([
            'DSN'         => '',
            'hostname'    => '',
            'username'    => '',
            'password'    => '',
            'database'    => $this->archiveFile,
            'DBDriver'    => 'SQLite3',
            'DBPrefix'    => '',
            'pConnect'    => false,
            'DBDebug'     => true,
            'charset'     => 'utf8',
            'DBCollat'    => '',
            'swapPre'     => '',
            'encrypt'     => false,
            'compress'    => false,
            'strictOn'    => false,
            'failover'    => [],
            'port'        => 3306,
            'foreignKeys' => true,
            'busyTimeout' => 1000,
            'synchronous' => null,
        ], false);

        $forge = \Config\Database::forge($this->archiveConn);

        $forge->addField([
            'id'     => ['type' => 'INTEGER', 'constraint' => 11],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'selesai'],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('transaksi_archive', true);

        $forge->addField([
            'id'           => ['type' => 'INTEGER', 'constraint' => 11],
            'transaksi_id' => ['type' => 'INTEGER', 'constraint' => 11],
            'tanggal'      => ['type' => 'DATETIME', 'null' => true],
            'jumlah'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'metode'       => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'tunai'],
            'status'       => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'aktif'],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('pembayaran_archive', true);

        // 1: counts (tunai, aktif, non-batal).
        $this->archiveConn->table('transaksi_archive')->insert(['id' => 1, 'status' => 'selesai']);
        $this->archiveConn->table('pembayaran_archive')->insert([
            'id' => 1, 'transaksi_id' => 1, 'tanggal' => self::DAY . ' 10:00:00',
            'jumlah' => 50000, 'metode' => 'tunai', 'status' => 'aktif',
        ]);

        // 2: excluded, non-tunai.
        $this->archiveConn->table('transaksi_archive')->insert(['id' => 2, 'status' => 'selesai']);
        $this->archiveConn->table('pembayaran_archive')->insert([
            'id' => 2, 'transaksi_id' => 2, 'tanggal' => self::DAY . ' 11:00:00',
            'jumlah' => 20000, 'metode' => 'qris', 'status' => 'aktif',
        ]);

        // 3: excluded, transaksi batal.
        $this->archiveConn->table('transaksi_archive')->insert(['id' => 3, 'status' => 'batal']);
        $this->archiveConn->table('pembayaran_archive')->insert([
            'id' => 3, 'transaksi_id' => 3, 'tanggal' => self::DAY . ' 12:00:00',
            'jumlah' => 30000, 'metode' => 'tunai', 'status' => 'aktif',
        ]);

        // 4: excluded, reversed payment.
        $this->archiveConn->table('transaksi_archive')->insert(['id' => 4, 'status' => 'selesai']);
        $this->archiveConn->table('pembayaran_archive')->insert([
            'id' => 4, 'transaksi_id' => 4, 'tanggal' => self::DAY . ' 13:00:00',
            'jumlah' => 99999, 'metode' => 'tunai', 'status' => 'reversed',
        ]);
    }

    private function dropLiveSchema(): void
    {
        $forge = \Config\Database::forge();

        $forge->dropTable('cash_expense', true);
        $forge->dropTable('pembayaran', true);
        $forge->dropTable('transaksi', true);
    }
}
