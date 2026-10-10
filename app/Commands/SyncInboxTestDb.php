<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Bangun ulang DB uji Inbox `aulia_inboxdb_test` dari migrasi Inbox.
 *
 * Latar (TODO-Q3b): `php spark migrate` tidak bisa membangun DB uji karena
 * riwayat migrasi berada di grup `default` (produksi). Akibatnya skema
 * `aulia_inboxdb_test` harus disamakan manual setiap ada migrasi Inbox baru,
 * dan feature suite bisa gagal `Unknown column`/tabel tidak ada.
 *
 * Command ini:
 *  - memaksa koneksi grup `inbox` ke `aulia_inboxdb_test` (menolak DB lain),
 *  - mengosongkan seluruh tabel DB uji,
 *  - menjalankan HANYA migrasi Inbox ($DBGroup = 'inbox') berurutan timestamp.
 *
 * Hasilnya skema DB uji selalu identik dengan hasil migrasi. Idempoten --
 * aman dijalankan berulang. Hanya menyentuh `aulia_inboxdb_test`; tidak pernah
 * menyentuh `aulia_inboxdb` (live) maupun grup `default`.
 */
class SyncInboxTestDb extends BaseCommand
{
    protected $group       = 'AULIA';
    protected $name        = 'aulia:sync-inbox-test-db';
    protected $description = 'Bangun ulang DB uji Inbox (aulia_inboxdb_test) dari migrasi Inbox agar selalu sinkron.';
    protected $usage       = 'aulia:sync-inbox-test-db';
    protected $arguments   = [];
    protected $options     = [];

    private const TEST_DB = 'aulia_inboxdb_test';

    public function run(array $params)
    {
        // 1. Arahkan grup `inbox` ke DB uji, apa pun ENVIRONMENT saat ini.
        //    DSN/failover dikosongkan supaya .env tidak bisa mengalihkan
        //    koneksi ke `aulia_inboxdb` (live).
        $config                    = config(Database::class);
        $config->inbox['database'] = self::TEST_DB;
        $config->inbox['DSN']      = '';
        $config->inbox['failover'] = [];

        $db = Database::connect('inbox');

        // 2. Fail-closed: tolak bila koneksi tidak benar-benar ke DB uji.
        $live = (string) $db->query('SELECT DATABASE() AS db')->getRow()->db;
        if ($live !== self::TEST_DB) {
            CLI::error("Ditolak: koneksi inbox menunjuk '{$live}', diharapkan '" . self::TEST_DB . "'.");

            return EXIT_ERROR;
        }

        // 3. Kosongkan semua tabel DB uji (FK dimatikan agar urutan drop bebas).
        $tables = array_column(
            $db->query(
                'SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
                [self::TEST_DB]
            )->getResultArray(),
            't'
        );

        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            // Hanya identitas tabel normal yang boleh di-interpolasi.
            if (preg_match('/^[A-Za-z0-9_]+$/', (string) $table) !== 1) {
                continue;
            }
            $db->query('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        // 4. Jalankan HANYA migrasi Inbox, urut timestamp.
        $files = glob(APPPATH . 'Database/Migrations/*.php') ?: [];
        sort($files);

        $dijalankan = 0;
        foreach ($files as $file) {
            $nama = basename($file, '.php');
            if (preg_match('/^\d{4}-\d{2}-\d{2}-\d{6}_(.+)$/', $nama, $m) !== 1) {
                continue;
            }

            $class = 'App\\Database\\Migrations\\' . $m[1];
            if (! class_exists($class, false)) {
                require_once $file;
            }
            if (! class_exists($class, false)) {
                CLI::error("Kelas migrasi tidak ditemukan di berkas {$nama}.");

                return EXIT_ERROR;
            }

            // Baca $DBGroup TANPA memanggil konstruktor: migrasi non-Inbox
            // (mis. POS) tidak boleh ikut membuka koneksi ke grup lain
            // (terutama DB live `default`).
            $defaults = (new \ReflectionClass($class))->getDefaultProperties();
            if (($defaults['DBGroup'] ?? null) !== 'inbox') {
                continue;
            }

            /** @var Migration $migration */
            $migration = new $class();
            $migration->up();
            $dijalankan++;
        }

        CLI::write("DB uji '{$live}' dibangun ulang dari {$dijalankan} migrasi Inbox.", 'green');

        return EXIT_SUCCESS;
    }
}
