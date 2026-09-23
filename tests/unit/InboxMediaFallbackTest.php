<?php
// Regression check untuk logic fallback disk-lokal di Inbox::media()
// (Tahap C, Langkah C6): "media_local_filename ada isinya DAN berhasil
// dibaca -> serve dari disk; kalau kosong/gagal baca -> lanjut ke
// live-fetch (blok existing, tidak disentuh)".
//
// Inbox::media() sendiri butuh $this->request/$this->response (CI4
// Controller) + MessageModel (koneksi DB 'inbox') sehingga tidak bisa
// dipanggil langsung tanpa bootstrap framework penuh (lihat
// tests/database/ untuk pola itu). Test ini memverifikasi PERSIS
// kondisi yang dipakai media() untuk memutuskan jalur disk vs fallback
// -- lewat CLASS ASLI InboxMediaStorage (bukan tiruan/reimplementasi),
// jadi regressi di kelas itu (mis. read() berhenti mengembalikan null
// pada kegagalan) akan tertangkap di sini juga.

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

// Simulasi persis kondisi Inbox::media():
//   if (!empty($message['media_local_filename'])) {
//       $storage = new InboxMediaStorage($config->mediaStoragePath);
//       $binary  = $storage->read($message['media_local_filename']);
//       if ($binary !== null) { /* serve dari disk */ } else { /* fallback */ }
//   }
function putuskanSumberMedia(array $message, string $mediaStoragePath): string
{
    if (!empty($message['media_local_filename'])) {
        $storage = new InboxMediaStorage($mediaStoragePath);
        $binary = $storage->read($message['media_local_filename']);

        if ($binary !== null) {
            return 'disk';
        }
    }

    return 'fallback_live_fetch';
}

$scratchBase = sys_get_temp_dir() . '/aulia_inbox_media_fallback_test_' . bin2hex(random_bytes(4));
mkdir($scratchBase, 0775, true);
file_put_contents($scratchBase . '/99.jpg', 'isi-file-asli');

// ---------------------------------------------------------------
// Skenario 1: media_local_filename kosong (belum pernah dicoba /
// mediaStoragePath belum diisi sama sekali) -> harus fallback.
// ---------------------------------------------------------------
check(
    'media_local_filename NULL -> fallback live-fetch',
    'fallback_live_fetch',
    putuskanSumberMedia(['media_local_filename' => null], $scratchBase),
    $pass,
    $fail
);

// ---------------------------------------------------------------
// Skenario 2: media_local_filename terisi DAN file benar-benar ada
// di disk & terbaca -> harus serve dari disk (TANPA hubungi Gateway).
// ---------------------------------------------------------------
check(
    'file ada & terbaca -> serve dari disk',
    'disk',
    putuskanSumberMedia(['media_local_filename' => '99.jpg'], $scratchBase),
    $pass,
    $fail
);

// ---------------------------------------------------------------
// Skenario 3: media_local_filename terisi (prefetch dulu sukses)
// TAPI file sudah tidak terbaca sekarang -- simulasi HDD eksternal
// tercabut sesudah prefetch. HARUS fallback, BUKAN error.
// ---------------------------------------------------------------
check(
    'filename tercatat tapi file hilang/HDD tercabut -> fallback live-fetch',
    'fallback_live_fetch',
    putuskanSumberMedia(['media_local_filename' => '99.jpg'], $scratchBase . '_hdd_tercabut'),
    $pass,
    $fail
);

// --- Cleanup -----------------------------------------------------
@unlink($scratchBase . '/99.jpg');
@rmdir($scratchBase);

echo "\n== $pass PASS, $fail FAIL ==\n";
exit($fail > 0 ? 1 : 0);
}
