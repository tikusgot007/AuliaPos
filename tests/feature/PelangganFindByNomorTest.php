<?php

use App\Models\PelangganModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * PelangganModel::findByNomor() on a REAL MariaDB.
 *
 * Why this exists: in the feature harness the `default` group is in-memory
 * SQLite, so InboxBuatPesananTest cannot prove that the REPLACE(...) LIKE
 * filter works on MariaDB (the production engine). Here the model is built on
 * the `inbox` connection (aulia_inboxdb_test, the dedicated test database that
 * bootstrap-feature.php refuses to run without) and a throwaway `pelanggan`
 * table is created and dropped around each test. `aulia:sync-inbox-test-db`
 * also wipes it. Production data is never touched.
 *
 * Maps docs/requirements/2026-10-16-inbox-ke-kasir.md AC-2.
 *
 * @internal
 */
final class PelangganFindByNomorTest extends CIUnitTestCase
{
    /** @var \CodeIgniter\Database\BaseConnection */
    private $conn;

    private PelangganModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = db_connect('inbox');

        // Fail closed: never create tables anywhere but the dedicated test DB.
        $this->assertSame(
            'aulia_inboxdb_test',
            (string) $this->conn->query('SELECT DATABASE() AS db')->getRow()->db
        );
        $this->assertStringContainsString('MySQL', $this->conn->getPlatform(), 'harus MariaDB/MySQL, bukan SQLite');

        $this->conn->query('DROP TABLE IF EXISTS `pelanggan`');
        // Same columns/charset/collation as 2026-09-08-000001_CreateAuliaPosCore.php.
        $this->conn->query(<<<'SQL'
CREATE TABLE `pelanggan` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama` varchar(100) NOT NULL,
  `no_hp` varchar(20) DEFAULT NULL,
  `alamat` text DEFAULT NULL,
  `diskon` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->model = new PelangganModel($this->conn);
    }

    protected function tearDown(): void
    {
        $this->conn->query('DROP TABLE IF EXISTS `pelanggan`');
        parent::tearDown();
    }

    private function seed(string $nama, ?string $noHp): int
    {
        $this->conn->table('pelanggan')->insert(['nama' => $nama, 'no_hp' => $noHp]);

        return (int) $this->conn->insertID();
    }

    public static function formatYangHarusCocokProvider(): array
    {
        return [
            'lokal 08'         => ['081234567890'],
            'kanonik 62'       => ['6281234567890'],
            'plus 62'          => ['+6281234567890'],
            'plus spasi strip' => ['+62 812-3456-7890'],
            'strip'            => ['0812-3456-7890'],
            'titik'            => ['0812.3456.7890'],
            'kurung'           => ['(0812) 3456 7890'],
            'spasi'            => ['0812 3456 7890'],
        ];
    }

    /**
     * AC-2: every stored format of the same number is found.
     *
     * @dataProvider formatYangHarusCocokProvider
     */
    public function testFormatTersimpanApaPunDitemukan(string $noHp): void
    {
        $id = $this->seed('Target', $noHp);

        $hasil = $this->model->findByNomor('6281234567890');

        $this->assertNotNull($hasil, "format '{$noHp}' seharusnya cocok");
        $this->assertSame($id, (int) $hasil['id']);
    }

    /** AC-2: a number that only shares the trailing digits is NOT a match. */
    public function testSufiksSamaTetapiNomorBedaTidakCocok(): void
    {
        $this->seed('Awalan Beda', '1081234567890');
        $this->seed('Digit Beda', '081234567891');

        $this->assertNull($this->model->findByNomor('6281234567890'));
    }

    /** NULL / empty / non-numeric no_hp rows never match and never error. */
    public function testNoHpKosongDanNullDiabaikan(): void
    {
        $this->seed('Null', null);
        $this->seed('Kosong', '');
        $this->seed('Teks', 'tidak ada nomor');
        $target = $this->seed('Target', '081234567890');

        $hasil = $this->model->findByNomor('6281234567890');

        $this->assertSame($target, (int) $hasil['id']);
    }

    /** With several candidates the oldest row (lowest id) wins, deterministically. */
    public function testBanyakKandidatMemilihIdTerkecil(): void
    {
        $pertama = $this->seed('Pertama', '081234567890');
        $this->seed('Kedua', '+62 812 3456 7890');

        $this->assertSame($pertama, (int) $this->model->findByNomor('6281234567890')['id']);
    }

    /**
     * The candidate filter is capped at 20 rows ordered by id; a real match
     * beyond that window is not returned. Documents the known ceiling
     * (ponytail note in PelangganModel::findByNomor) instead of hiding it.
     */
    public function testBatasDuaPuluhKandidatDidokumentasikan(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            // Same suffix, different prefix digit -> candidate in SQL, rejected in PHP.
            $this->seed('Pengganggu ' . $i, '1081234567890');
        }
        $this->seed('Tersembunyi', '081234567890');

        $this->assertNull($this->model->findByNomor('6281234567890'));
    }

    /** Input that is not a canonical number is rejected before touching SQL. */
    public function testInputBukanKanonikDitolakSebelumSql(): void
    {
        $this->seed('Target', '081234567890');

        foreach (['', '081234567890', '6281234567890%', "62'; DROP TABLE pelanggan;--", '62abc', '621234'] as $input) {
            $this->assertNull($this->model->findByNomor($input), "input '{$input}' seharusnya ditolak");
        }

        // Table still exists and still holds the row.
        $this->assertSame(1, $this->conn->table('pelanggan')->countAllResults());
    }
}
