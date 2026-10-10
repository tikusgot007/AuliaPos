<?php

use App\Controllers\Laporan;
use App\Services\TransaksiArchiveService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * TODO-BL20: a transaction that stays in MySQL *and* exists in the archive
 * (i.e. the post-archive delete failed) must never be counted twice by the
 * reports. MySQL wins: archive rows whose id also exists live are dropped.
 *
 * Covers the root-cause detection queries
 * (TransaksiArchiveService::idTransaksiGandaDiAktif() /
 * idTransaksiGandaPeriodeDiAktif()) and the merge-time dedup helpers used by
 * every report path (Laporan::buangArsipKembar() / saringArsipById() /
 * kunciBaris()).
 *
 * Live data uses the `tests` SQLite group (:memory:); the archive is a separate
 * temporary SQLite file injected into TransaksiArchiveService. No MySQL and no
 * real writable/archive DB is touched.
 *
 * @internal
 */
final class LaporanDedupArsipTest extends CIUnitTestCase
{
    private ?string $tmpBase = null;
    private ?string $archiveFile = null;
    private ?string $originalArchivePath = null;

    /** @var \CodeIgniter\Database\BaseConnection|null */
    private $archiveConn = null;

    /** @var Laporan */
    private $laporan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createLiveSchema();
        $this->createArchiveDatabase();

        $this->laporan = new Laporan();
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

        $forge = \Config\Database::forge();
        $forge->dropTable('transaksi', true);

        if ($this->tmpBase !== null && is_dir($this->tmpBase)) {
            $this->rrmdir($this->tmpBase);
        }
        $this->tmpBase = null;

        parent::tearDown();
    }

    public function testIdTransaksiGandaDiAktifFlagsRowsStillInMysql(): void
    {
        // t1 exists live + archive (delete failed), t2 live only, t3 archive only.
        $this->insertLiveTransaksi(1, '2026-01-05 10:00:00');
        $this->insertLiveTransaksi(2, '2026-01-06 10:00:00');
        $this->insertArchiveTransaksi(1, '2026-01-05 10:00:00', false);
        $this->insertArchiveTransaksi(3, '2026-01-07 10:00:00', false);

        $svc = new TransaksiArchiveService($this->archiveConn);

        $ganda = $svc->idTransaksiGandaDiAktif('2026-01-01 00:00:00', '2026-01-31 23:59:59');

        $this->assertSame([1], $ganda);
    }

    public function testIdTransaksiGandaDiAktifEmptyWhenNoOverlap(): void
    {
        $this->insertLiveTransaksi(2, '2026-01-06 10:00:00');
        $this->insertArchiveTransaksi(3, '2026-01-07 10:00:00', false);

        $svc = new TransaksiArchiveService($this->archiveConn);

        $this->assertSame([], $svc->idTransaksiGandaDiAktif('2026-01-01 00:00:00', '2026-01-31 23:59:59'));
    }

    public function testIdTransaksiGandaPeriodeRequiresDetailAndExcludesBatal(): void
    {
        $this->insertLiveTransaksi(1, '2026-01-05 10:00:00');
        $this->insertLiveTransaksi(2, '2026-01-06 10:00:00');
        $this->insertLiveTransaksi(3, '2026-01-07 10:00:00');

        // t1: duplicate, has detail -> counted.
        $this->insertArchiveTransaksi(1, '2026-01-05 10:00:00', false);
        $this->insertArchiveDetail(1);
        // t2: duplicate, NO detail -> excluded (paritas tab Periode).
        $this->insertArchiveTransaksi(2, '2026-01-06 10:00:00', false);
        // t3: duplicate but batal -> excluded.
        $this->insertArchiveTransaksi(3, '2026-01-07 10:00:00', true);
        $this->insertArchiveDetail(3);

        $svc = new TransaksiArchiveService($this->archiveConn);

        $ganda = $svc->idTransaksiGandaPeriodeDiAktif('2026-01-01 00:00:00', '2026-01-31 23:59:59');

        $this->assertSame([1], $ganda);
    }

    public function testBuangArsipKembarKeepsLiveAndDropsArchiveDuplicates(): void
    {
        $live = [
            ['id' => 1, 'jumlah' => 100],
            ['id' => 2, 'jumlah' => 200],
        ];
        $archive = [
            ['id' => 1, 'jumlah' => 100], // duplicate of live -> dropped (MySQL wins)
            ['id' => 3, 'jumlah' => 300], // archive-only -> kept
        ];

        $hasil = $this->invoke('buangArsipKembar', [$live, $archive, 'id']);

        $this->assertSame([3], array_column($hasil, 'id'));
    }

    public function testBuangArsipKembarUsesCompositeKeyForItemHarian(): void
    {
        $key = ['tanggal_pembayaran', 'transaksi_id', 'kategori_id', 'nama_produk'];
        $live = [
            ['tanggal_pembayaran' => '2026-01-05', 'transaksi_id' => 1, 'kategori_id' => 2, 'nama_produk' => 'A'],
        ];
        $archive = [
            // Same composite key as live -> dropped.
            ['tanggal_pembayaran' => '2026-01-05', 'transaksi_id' => 1, 'kategori_id' => 2, 'nama_produk' => 'A'],
            // Different product -> kept.
            ['tanggal_pembayaran' => '2026-01-05', 'transaksi_id' => 1, 'kategori_id' => 2, 'nama_produk' => 'B'],
        ];

        $hasil = $this->invoke('buangArsipKembar', [$live, $archive, $key]);

        $this->assertSame(['B'], array_column($hasil, 'nama_produk'));
    }

    public function testBuangArsipKembarKeepsRowsWithMissingKey(): void
    {
        $live = [['id' => 1]];
        $archive = [['tanpa_id' => 'x']]; // no `id` -> never treated as duplicate

        $hasil = $this->invoke('buangArsipKembar', [$live, $archive, 'id']);

        $this->assertCount(1, $hasil);
    }

    public function testSaringArsipByIdDropsGivenIds(): void
    {
        $archive = [
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
        ];

        $hasil = $this->invoke('saringArsipById', [$archive, [1, 3]]);

        $this->assertSame([2], array_column($hasil, 'id'));
    }

    /**
     * @param array<int, mixed> $args
     *
     * @return mixed
     */
    private function invoke(string $method, array $args)
    {
        $ref = new ReflectionMethod(Laporan::class, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($this->laporan, $args);
    }

    private function createLiveSchema(): void
    {
        $forge = \Config\Database::forge();
        $forge->addField([
            'id'         => ['type' => 'INTEGER', 'auto_increment' => true],
            'tanggal'    => ['type' => 'DATETIME', 'null' => true],
            'status'     => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'proses'],
            'kode_invoice' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('transaksi', true);
    }

    private function createArchiveDatabase(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/aulia_bl20_' . uniqid();
        mkdir($this->tmpBase, 0777, true);
        $this->archiveFile = $this->tmpBase . '/archive.db';

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
    id INTEGER PRIMARY KEY, kode_invoice TEXT, no_order INTEGER, tanggal TEXT,
    pelanggan_id INTEGER, pelanggan_nama TEXT, kasir_id INTEGER, kasir_nama TEXT,
    kasir_username TEXT, kasir_inisial TEXT, subtotal REAL NOT NULL DEFAULT 0,
    diskon REAL NOT NULL DEFAULT 0, pajak REAL NOT NULL DEFAULT 0,
    grand_total REAL NOT NULL DEFAULT 0, selisih_pembulatan REAL NOT NULL DEFAULT 0,
    total_dibayar REAL NOT NULL DEFAULT 0, status_pembayaran TEXT, status TEXT,
    sumber TEXT, created_at TEXT, updated_at TEXT, archived_at TEXT, archived_period TEXT
)
SQL);

        $this->archiveConn->query(<<<'SQL'
CREATE TABLE detail_transaksi_archive (
    id INTEGER PRIMARY KEY, transaksi_id INTEGER NOT NULL, produk_id INTEGER,
    nama_produk TEXT, kategori_id INTEGER, jumlah REAL NOT NULL DEFAULT 0,
    harga_satuan REAL NOT NULL DEFAULT 0, subtotal REAL NOT NULL DEFAULT 0,
    catatan TEXT, created_at TEXT, updated_at TEXT
)
SQL);
    }

    private function insertLiveTransaksi(int $id, string $tanggal): void
    {
        db_connect()->table('transaksi')->insert([
            'id'           => $id,
            'tanggal'      => $tanggal,
            'status'       => 'selesai',
            'kode_invoice' => 'INV-' . $id,
        ]);
    }

    private function insertArchiveTransaksi(int $id, string $tanggal, bool $batal): void
    {
        $this->archiveConn->table('transaksi_archive')->insert([
            'id'           => $id,
            'tanggal'      => $tanggal,
            'status'       => $batal ? 'batal' : 'selesai',
            'kode_invoice' => 'INV-' . $id,
        ]);
    }

    private function insertArchiveDetail(int $transaksiId): void
    {
        $this->archiveConn->table('detail_transaksi_archive')->insert([
            'id'           => $transaksiId,
            'transaksi_id' => $transaksiId,
            'nama_produk'  => 'Item ' . $transaksiId,
            'kategori_id'  => 1,
            'subtotal'     => 1000,
        ]);
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) as $item) {
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
