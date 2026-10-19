<?php
// Fake 9router untuk feature test "Minta Saran AI". Jawaban dibaca dari file
// FAKE_AI_RESPONSE_FILE: baris 1 = status HTTP, sisanya = isi balasan.
// - "<f>.cari"  : jawaban untuk tahap kata cari (prompt memuat MODE_CARI); bila tak ada -> 500.
// - "<f>.once"  : dilayani sekali lalu dihapus (uji ulang saat 429), hanya untuk tahap 2.
// - "<f>.log"   : setiap body request ditambahkan (satu JSON per baris) untuk diperiksa tes.
$f    = getenv('FAKE_AI_RESPONSE_FILE');
$body = (string) file_get_contents('php://input');
if ($f) {
    file_put_contents($f . '.log', $body . "\n", FILE_APPEND);
}
$cari = strpos($body, 'MODE_CARI') !== false;
$pick = $f . ($cari ? '.cari' : (is_file($f . '.once') ? '.once' : ''));

$lines = $f && is_file($pick) ? explode("\n", (string) file_get_contents($pick), 2) : ['500', ''];
if (substr($pick, -5) === '.once') {
    unlink($pick);
}
http_response_code((int) $lines[0]);
header('Content-Type: application/json');
echo $lines[1] ?? '';
