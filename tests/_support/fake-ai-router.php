<?php
// Fake 9router untuk feature test "Minta Saran AI". Jawaban dibaca dari file
// FAKE_AI_RESPONSE_FILE: baris 1 = status HTTP, sisanya = isi balasan.
$f = getenv('FAKE_AI_RESPONSE_FILE');
// File "<f>.once" dilayani sekali lalu dihapus (uji ulang saat 429).
if ($f && is_file($f . '.once')) {
    $f2 = $f . '.once';
    $o  = explode("\n", (string) file_get_contents($f2), 2);
    unlink($f2);
    http_response_code((int) $o[0]);
    header('Content-Type: application/json');
    echo $o[1] ?? '';
    return;
}
$lines = $f && is_file($f) ? explode("\n", (string) file_get_contents($f), 2) : ['500', ''];
http_response_code((int) $lines[0]);
header('Content-Type: application/json');
echo $lines[1] ?? '';
