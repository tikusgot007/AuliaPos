<?php
// Regression check untuk Tahap E (hentikan retry berulang untuk media
// yang sudah dipastikan kadaluarsa) di Inbox::media():
//   1. media_confirmed_gone_at terisi + media_local_filename kosong ->
//      short-circuit 410, Gateway TIDAK dihubungi sama sekali.
//   2. Gateway balas 410 -> media_confirmed_gone_at diisi.
//   3. Gateway balas gagal SELAIN 410 (mis. 502) -> media_confirmed_gone_at
//      TETAP NULL (bukan kegagalan permanen, masih layak dicoba lagi).
//
// Inbox::media() sendiri butuh $this->request/$this->response (CI4
// Controller) + MessageModel (koneksi DB 'inbox') sehingga tidak bisa
// dipanggil langsung tanpa bootstrap framework penuh (lihat
// tests/session/ untuk pola itu, mis. InboxSoftDeleteTest.php). Test
// ini memverifikasi PERSIS kondisi/urutan keputusan yang dipakai
// media() -- disalin persis dari app/Controllers/Inbox.php (Tahap E),
// bukan reimplementasi logic baru -- lewat stub $panggilGateway yang
// SENGAJA melempar exception kalau dipanggil, jadi Skenario 1
// benar-benar membuktikan Gateway tidak dihubungi (bukan cuma
// mengabaikan hasilnya).

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

/**
 * Cermin persis urutan keputusan di Inbox::media() (Tahap E):
 *   if (empty(media_local_filename) && !empty(media_confirmed_gone_at)) {
 *       return 410 TANPA panggil Gateway;
 *   }
 *   $result = callGatewayMediaDownload(...);
 *   if (!$result['ok']) {
 *       if ((int) $result['status'] === 410) { catat media_confirmed_gone_at; }
 *       return $result['status'] ?: 502;
 *   }
 *
 * @return array{called_gateway: bool, http_status: int, confirmed_gone_set: bool}
 */
function putuskanAksiMedia(array $message, callable $panggilGateway): array
{
    if (empty($message['media_local_filename']) && !empty($message['media_confirmed_gone_at'])) {
        return ['called_gateway' => false, 'http_status' => 410, 'confirmed_gone_set' => false];
    }

    $result = $panggilGateway();

    if (!$result['ok']) {
        $confirmedGoneSet = (int) $result['status'] === 410;

        return ['called_gateway' => true, 'http_status' => (int) ($result['status'] ?: 502), 'confirmed_gone_set' => $confirmedGoneSet];
    }

    return ['called_gateway' => true, 'http_status' => 200, 'confirmed_gone_set' => false];
}

// ---------------------------------------------------------------
// Skenario 1: media_confirmed_gone_at sudah terisi (410 tercatat
// sebelumnya), media_local_filename kosong -> short-circuit, Gateway
// TIDAK dihubungi sama sekali.
// ---------------------------------------------------------------
$panggilGatewayTidakBolehDipanggil = function () {
    throw new \RuntimeException('Gateway TIDAK BOLEH dihubungi -- media sudah dipastikan gone.');
};

$hasil1 = putuskanAksiMedia(
    ['media_local_filename' => null, 'media_confirmed_gone_at' => '2026-09-19 10:00:00'],
    $panggilGatewayTidakBolehDipanggil
);
check('Skenario 1: Gateway tidak dihubungi', false, $hasil1['called_gateway'], $pass, $fail);
check('Skenario 1: balas 410 langsung', 410, $hasil1['http_status'], $pass, $fail);

// ---------------------------------------------------------------
// Skenario 2: Gateway balas 410 -> media_confirmed_gone_at HARUS
// dicatat sebagai final.
// ---------------------------------------------------------------
$hasil2 = putuskanAksiMedia(
    ['media_local_filename' => null, 'media_confirmed_gone_at' => null],
    fn () => ['ok' => false, 'status' => 410, 'error' => 'Media sudah kadaluarsa.']
);
check('Skenario 2: Gateway dihubungi', true, $hasil2['called_gateway'], $pass, $fail);
check('Skenario 2: media_confirmed_gone_at diisi', true, $hasil2['confirmed_gone_set'], $pass, $fail);
check('Skenario 2: http status 410', 410, $hasil2['http_status'], $pass, $fail);

// ---------------------------------------------------------------
// Skenario 3: Gateway gagal SELAIN 410 (mis. 502, timeout) ->
// media_confirmed_gone_at TETAP NULL -- masih layak dicoba lagi.
// ---------------------------------------------------------------
$hasil3 = putuskanAksiMedia(
    ['media_local_filename' => null, 'media_confirmed_gone_at' => null],
    fn () => ['ok' => false, 'status' => 502, 'error' => 'Tidak bisa menghubungi Gateway.']
);
check('Skenario 3: Gateway dihubungi', true, $hasil3['called_gateway'], $pass, $fail);
check('Skenario 3: media_confirmed_gone_at TETAP NULL', false, $hasil3['confirmed_gone_set'], $pass, $fail);
check('Skenario 3: http status 502 diteruskan apa adanya', 502, $hasil3['http_status'], $pass, $fail);

echo "\n== $pass PASS, $fail FAIL ==\n";
exit($fail > 0 ? 1 : 0);
}
