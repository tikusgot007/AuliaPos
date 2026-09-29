<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * TransaksiModel::simpanTransaksi() hanya memanggil GET_LOCK()/
 * RELEASE_LOCK() (fungsi khusus MySQL) saat koneksi database aktif
 * benar-benar MySQLi -- pada driver lain (SQLite3 di test suite) tahap
 * lock dilewati seluruhnya. Test ini membuktikan sinyal yang dipakai
 * untuk keputusan itu (BaseConnection::getPlatform()) tanpa menyentuh
 * tabel transaksi sama sekali, supaya tidak perlu database MySQL
 * terpisah untuk membuktikan cabang deteksi platform-nya.
 *
 * Perilaku bisnis (no_order aktif ditolak 409, no_order batal boleh
 * dipakai ulang) diuji terpisah di
 * tests/database/TransaksiNoOrderConcurrencyTest.php lewat DB test SQLite
 * -- lock MySQL sungguhan (GET_LOCK/RELEASE_LOCK lintas-proses) TIDAK
 * diuji otomatis di sini karena test suite proyek ini berjalan di
 * SQLite (lihat app/Config/Database.php, grup `tests`); memverifikasinya
 * butuh dua proses PHP nyata melawan MySQL, di luar cakupan PHPUnit.
 *
 * @internal
 */
final class TransaksiNoOrderLockPlatformTest extends CIUnitTestCase
{
    public function testGrupTestsMemakaiSqliteBukanMysql(): void
    {
        $db = db_connect();

        // Ini justru MEMBUKTIKAN kenapa guard getPlatform() di
        // TransaksiModel dibutuhkan: test suite TIDAK berjalan di MySQL,
        // jadi GET_LOCK() tidak pernah dipanggil selama test otomatis.
        $this->assertSame('SQLite3', $db->getPlatform());
    }

    public function testGrupDefaultDikonfigurasiSebagaiMysqli(): void
    {
        // Tidak membuka koneksi (agar test tidak butuh MySQL hidup) --
        // cukup baca konfigurasi statis yang dipakai TransaksiModel saat
        // produksi (ENVIRONMENT !== 'testing') untuk memastikan cabang
        // "$isMySql" akan bernilai true di lingkungan nyata.
        $config = new \Config\Database();

        $this->assertSame('MySQLi', $config->default['DBDriver']);
    }
}
