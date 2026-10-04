<?php

use App\Services\TransaksiArchiveService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * TODO-BL06 (DEC-3 "A+ dengan katup E"): the archive must never move an active
 * receivable (belum_bayar/dp, status not batal/mangkrak). Lunas, batal and
 * mangkrak stay eligible; mangkrak is the deliberate valve for dead receivables.
 *
 * Live data uses the `tests` SQLite group (:memory:); the archive is a separate
 * temporary SQLite file injected into TransaksiArchiveService. No live MySQL or
 * the real writable/archive DB is touched.
 *
 * @internal
 */
final class TransaksiArsipPiutangTest extends CIUnitTestCase
{
    private const BULAN_A = '2026-01'; // lunas + batal + mangkrak + 2 piutang
    private const BULAN_B = '2025-12'; // hanya piutang aktif

    private ?string $tmpBase = null;
    private ?string $archiveFile = null;
    private ?string $originalArchivePath = null;

    /** @var \CodeIgniter\Database\BaseConnection|null */
    private $archiveConn = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLiveSchema();
        $this->createArchiveDatabase();
        $this->seedLive();
    }

    protected function tearDown(): void
    {
        if ($this->archiveConn !== null) {
            $this->archiveConn->close();
            $this->archiveConn = null;
        }

        if ($this->originalArchivePath !== null) {
            config('Database')->archive['database'] = $this->originalArchivePath;
            $this->originalArchivePath = null;
        }

        $this->dropLiveSchema();

        if ($this->tmpBase !== null && is_dir($this->tmpBase)) {
            $this->rrmdir($this->tmpBase);
        }
        $this->tmpBase = null;

        parent::tearDown();
    }

    public function testPreviewReportsExcludedReceivablesSeparately(): void
    {
        $hasil = (new TransaksiArchiveService($this->archiveConn))->preview([self::BULAN_A]);

        // Eligible set: t1 (lunas), t2 (batal), t5 (mangkrak).
        $this->assertSame(3, $hasil['jumlah_transaksi']);
        $this->assertSame(180000.0, $hasil['total_transaksi']); // 100000 + 50000 + 30000

        // AC-6: batal counted by TRANSACTION status, not status_pembayaran.
        $this->assertSame(1, $hasil['per_status']['batal']);
        $this->assertSame(1, $hasil['per_status']['lunas']);
        $this->assertSame(1, $hasil['per_status']['belum_bayar']); // t5 mangkrak (belum_bayar)

        // AC-1: excluded receivables reported separately.
        $this->assertSame(2, $hasil['jumlah_piutang_aktif']);
        $this->assertSame(350000.0, $hasil['total_piutang_aktif']); // 200000 + 150000
    }

    public function testJalankanArchivesEligibleAndKeepsReceivables(): void
    {
        $service = new TransaksiArchiveService($this->archiveConn);
        $hasil = $service->jalankan([self::BULAN_A], 1);

        $this->assertSame(3, $hasil['jumlah_transaksi']);

        $liveIds = $this->liveIds();

        // AC-2: active receivables t3 (belum_bayar) & t4 (dp) stay in MySQL.
        $this->assertContains(3, $liveIds);
        $this->assertContains(4, $liveIds);

        // AC-3: lunas (t1) and batal (t2) are archived/removed.
        $this->assertNotContains(1, $liveIds);
        $this->assertNotContains(2, $liveIds);

        // AC-4: mangkrak (t5) is archived via valve E.
        $this->assertNotContains(5, $liveIds);

        $archiveIds = $this->archiveIds();
        $this->assertSame([1, 2, 5], $archiveIds);

        // The receivable's detail + payment rows must be untouched in MySQL.
        $this->assertSame(1, db_connect()->table('detail_transaksi')->where('transaksi_id', 3)->countAllResults());
        $this->assertSame(1, db_connect()->table('pembayaran')->where('transaksi_id', 4)->countAllResults());
    }

    public function testJalankanWithOnlyReceivablesTouchesNothing(): void
    {
        $service = new TransaksiArchiveService($this->archiveConn);
        $hasil = $service->jalankan([self::BULAN_B], 1);

        $this->assertSame(0, $hasil['jumlah_transaksi']);
        $this->assertStringContainsString('piutang aktif', $hasil['pesan']);

        // The receivable in month B stays in MySQL, nothing archived.
        $this->assertContains(6, $this->liveIds());
        $this->assertSame([], $this->archiveIds());
    }

    /**
     * @return int[]
     */
    private function liveIds(): array
    {
        return array_map('intval', array_column(
            db_connect()->table('transaksi')->select('id')->orderBy('id', 'ASC')->get()->getResultArray(),
            'id'
        ));
    }

    /**
     * @return int[]
     */
    private function archiveIds(): array
    {
        return array_map('intval', array_column(
            $this->archiveConn->table('transaksi_archive')->select('id')->orderBy('id', 'ASC')->get()->getResultArray(),
            'id'
        ));
    }

    private function createLiveSchema(): void
    {
        $forge = \Config\Database::forge();

        $forge->addField([
            'id'                  => ['type' => 'INTEGER', 'constraint' => 11, 'auto_increment' => true],
            'merged_into'         => ['type' => 'INTEGER', 'null' => true],
            'kode_invoice'        => ['type' => 'VARCHAR', 'constraint' => 30],
            'no_order'            => ['type' => 'INTEGER', 'null' => true],
            'tanggal'             => ['type' => 'DATETIME', 'null' => true],
            'pelanggan_id'        => ['type' => 'INTEGER', 'null' => true],
            'kasir_id'            => ['type' => 'INTEGER', 'null' => true],
            'subtotal'            => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'diskon'              => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'pajak'               => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'grand_total'         => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'selisih_pembulatan'  => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'total_dibayar'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'status_pembayaran'   => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'belum_bayar'],
            'status'              => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'proses'],
            'sumber'              => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'kasir_pos'],
            'created_at'          => ['type' => 'DATETIME', 'null' => true],
            'updated_at'          => ['type' => 'DATETIME', 'null' => true],
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
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
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
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('pembayaran', true);

        $forge->addField([
            'id'       => ['type' => 'INTEGER', 'constraint' => 11, 'auto_increment' => true],
            'nama'     => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'username' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'inisial'  => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('users', true);

        $forge->addField([
            'id'   => ['type' => 'INTEGER', 'constraint' => 11, 'auto_increment' => true],
            'nama' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('pelanggan', true);
    }

    private function createArchiveDatabase(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/aulia_bl06_' . uniqid();
        mkdir($this->tmpBase, 0777, true);
        mkdir($this->tmpBase . '/backups', 0777, true);
        $this->archiveFile = $this->tmpBase . '/archive.db';

        // Redirect the backup-JSON path (tulisBackupJson reads config) away from
        // writable/archive, then restore it in tearDown.
        $this->originalArchivePath = config('Database')->archive['database'];
        config('Database')->archive['database'] = $this->archiveFile;

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

        $this->archiveConn->query(<<<'SQL'
CREATE TABLE transaksi_archive (
    id INTEGER PRIMARY KEY, merged_into INTEGER, kode_invoice TEXT NOT NULL, no_order INTEGER,
    tanggal TEXT, pelanggan_id INTEGER, pelanggan_nama TEXT, kasir_id INTEGER, kasir_nama TEXT,
    kasir_username TEXT, kasir_inisial TEXT, subtotal REAL NOT NULL DEFAULT 0, diskon REAL NOT NULL DEFAULT 0,
    pajak REAL NOT NULL DEFAULT 0, grand_total REAL NOT NULL DEFAULT 0, selisih_pembulatan REAL NOT NULL DEFAULT 0,
    total_dibayar REAL NOT NULL DEFAULT 0, status_pembayaran TEXT, status TEXT, sumber TEXT,
    created_at TEXT, updated_at TEXT, archived_at TEXT NOT NULL, archived_period TEXT NOT NULL
)
SQL);

        $this->archiveConn->query(<<<'SQL'
CREATE TABLE detail_transaksi_archive (
    id INTEGER PRIMARY KEY, transaksi_id INTEGER NOT NULL, produk_id INTEGER, nama_produk TEXT,
    kategori_id INTEGER, jumlah REAL NOT NULL DEFAULT 0, harga_satuan REAL NOT NULL DEFAULT 0,
    subtotal REAL NOT NULL DEFAULT 0, catatan TEXT, created_at TEXT, updated_at TEXT
)
SQL);

        $this->archiveConn->query(<<<'SQL'
CREATE TABLE pembayaran_archive (
    id INTEGER PRIMARY KEY, transaksi_id INTEGER NOT NULL, tanggal TEXT, jumlah REAL NOT NULL DEFAULT 0,
    uang_diterima REAL, kembalian REAL NOT NULL DEFAULT 0, metode TEXT, keterangan TEXT,
    kasir_id INTEGER, kasir_nama TEXT, kasir_username TEXT, kasir_inisial TEXT, status TEXT, created_at TEXT
)
SQL);
    }

    private function seedLive(): void
    {
        $db = db_connect();

        $db->table('users')->insert(['id' => 1, 'nama' => 'Kasir Uji', 'username' => 'kasir1', 'inisial' => 'KU']);

        $t = function (int $id, string $status, string $statusBayar, float $grand, string $tanggal) use ($db) {
            $db->table('transaksi')->insert([
                'id' => $id, 'kode_invoice' => 'INV-' . $id, 'tanggal' => $tanggal, 'kasir_id' => 1,
                'subtotal' => $grand, 'grand_total' => $grand, 'total_dibayar' => 0,
                'status_pembayaran' => $statusBayar, 'status' => $status,
            ]);

            $db->table('detail_transaksi')->insert([
                'id' => $id, 'transaksi_id' => $id, 'produk_id' => 1, 'nama_produk' => 'Item ' . $id,
                'kategori_id' => 1, 'jumlah' => 1, 'harga_satuan' => $grand, 'subtotal' => $grand,
            ]);
        };

        // Month A (2026-01)
        $t(1, 'selesai', 'lunas', 100000, self::BULAN_A . '-05 10:00:00');
        $t(2, 'batal', 'belum_bayar', 50000, self::BULAN_A . '-06 10:00:00');
        $t(3, 'proses', 'belum_bayar', 200000, self::BULAN_A . '-07 10:00:00');
        $t(4, 'proses', 'dp', 150000, self::BULAN_A . '-08 10:00:00');
        $t(5, 'mangkrak', 'belum_bayar', 30000, self::BULAN_A . '-09 10:00:00');

        // Month B (2025-12): only an active receivable.
        $t(6, 'proses', 'belum_bayar', 70000, self::BULAN_B . '-10 10:00:00');

        // Payments: one for the lunas sale (t1) and one for the DP (t4).
        $db->table('pembayaran')->insert([
            'id' => 1, 'transaksi_id' => 1, 'tanggal' => self::BULAN_A . '-05 10:05:00',
            'jumlah' => 100000, 'metode' => 'tunai', 'kasir_id' => 1, 'status' => 'aktif',
        ]);
        $db->table('pembayaran')->insert([
            'id' => 2, 'transaksi_id' => 4, 'tanggal' => self::BULAN_A . '-08 10:05:00',
            'jumlah' => 50000, 'metode' => 'tunai', 'kasir_id' => 1, 'status' => 'aktif',
        ]);
    }

    private function dropLiveSchema(): void
    {
        $forge = \Config\Database::forge();

        $forge->dropTable('pelanggan', true);
        $forge->dropTable('users', true);
        $forge->dropTable('pembayaran', true);
        $forge->dropTable('detail_transaksi', true);
        $forge->dropTable('transaksi', true);
    }

    private function rrmdir(string $dir): void
    {
        $items = scandir($dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
