<?php
// Unit test untuk App\Libraries\InboxMediaStorage (Tahap C -- penyimpanan
// permanen media inbox). Memuat CLASS ASLI via require langsung (tidak
// butuh bootstrap framework/DB), pola sama dengan
// InboxResponseStateManualTest.php (`php tests/unit/InboxMediaStorageTest.php`).
//
// Fokus utama: SEMUA method HARUS gagal-aman (return false/null, TIDAK
// PERNAH throw) -- termasuk saat basePath tidak ada/tidak bisa diakses
// (simulasi HDD eksternal tercabut/belum terpasang).

// InboxMediaStorage::save() panggil log_message() (helper CI4) saat
// folder gagal diakses/dibuat -- stub minimal supaya test ini jalan
// tanpa bootstrap framework penuh.
if (!function_exists('log_message')) {
    function log_message(string $level, string $message): void
    {
    }
}

require_once __DIR__ . '/../../app/Libraries/InboxMediaStorage.php';

use App\Libraries\InboxMediaStorage;

$pass = 0;
$fail = 0;

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {

function check(string $name, $expected, $actual, &$pass, &$fail): void
{
    if ($expected === $actual) {
        echo "[PASS] $name\n";
        $pass++;
    } else {
        echo '[FAIL] ' . $name . ' => expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true) . "\n";
        $fail++;
    }
}

$scratchBase = sys_get_temp_dir() . '/aulia_inbox_media_test_' . bin2hex(random_bytes(4));

// ---------------------------------------------------------------
// Skenario 1: basePath kosong -- fitur dianggap nonaktif.
// ---------------------------------------------------------------
$storageKosong = new InboxMediaStorage('');
check('isConfigured() false kalau basePath kosong', false, $storageKosong->isConfigured(), $pass, $fail);
check('save() false kalau basePath kosong', false, $storageKosong->save('a.jpg', 'isi'), $pass, $fail);
check('read() null kalau basePath kosong', null, $storageKosong->read('a.jpg'), $pass, $fail);
check('exists() false kalau basePath kosong', false, $storageKosong->exists('a.jpg'), $pass, $fail);

// ---------------------------------------------------------------
// Skenario 2: basePath valid -- save() lalu read() harus konsisten,
// dan exists()/read() untuk file yang belum pernah disimpan aman.
// ---------------------------------------------------------------
$storageValid = new InboxMediaStorage($scratchBase);
check('exists() false untuk file yang belum ada', false, $storageValid->exists('belum-ada.jpg'), $pass, $fail);
check('read() null untuk file yang belum ada', null, $storageValid->read('belum-ada.jpg'), $pass, $fail);

$saveOk = $storageValid->save('123.jpg', 'binary-dummy');
check('save() sukses ke folder valid (auto-create)', true, $saveOk, $pass, $fail);
check('exists() true setelah save()', true, $storageValid->exists('123.jpg'), $pass, $fail);
check('read() mengembalikan isi yang sama persis', 'binary-dummy', $storageValid->read('123.jpg'), $pass, $fail);

// ---------------------------------------------------------------
// Skenario 3: GAGAL-AMAN -- basePath menunjuk ke path yang SENGAJA
// tidak valid (parent-nya adalah FILE, bukan folder, jadi mkdir()
// pasti gagal) -- simulasi HDD eksternal tidak terpasang. Harus
// return false/null, BUKAN exception.
// ---------------------------------------------------------------
$fileBiasa = $scratchBase . '_bukan_folder.txt';
file_put_contents($fileBiasa, 'aku file biasa, bukan folder');
$basePathTidakValid = $fileBiasa . '/sub/folder';

$storageInvalid = new InboxMediaStorage($basePathTidakValid);
try {
    $saveInvalid = $storageInvalid->save('456.jpg', 'data');
    check('save() ke basePath tidak valid: false (bukan exception)', false, $saveInvalid, $pass, $fail);
} catch (\Throwable $e) {
    check('save() ke basePath tidak valid TIDAK boleh throw', 'no-exception', get_class($e), $pass, $fail);
}

try {
    $readInvalid = $storageInvalid->read('456.jpg');
    check('read() dari basePath tidak valid: null (bukan exception)', null, $readInvalid, $pass, $fail);
} catch (\Throwable $e) {
    check('read() dari basePath tidak valid TIDAK boleh throw', 'no-exception', get_class($e), $pass, $fail);
}

try {
    $existsInvalid = $storageInvalid->exists('456.jpg');
    check('exists() dari basePath tidak valid: false (bukan exception)', false, $existsInvalid, $pass, $fail);
} catch (\Throwable $e) {
    check('exists() dari basePath tidak valid TIDAK boleh throw', 'no-exception', get_class($e), $pass, $fail);
}

// --- Cleanup ---------------------------------------------------------
@unlink($scratchBase . '/123.jpg');
@rmdir($scratchBase);
@unlink($fileBiasa);

echo "\n== $pass PASS, $fail FAIL ==\n";
exit($fail > 0 ? 1 : 0);
}
