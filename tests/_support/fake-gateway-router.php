<?php

/**
 * Router script for `php -S`: a fake WA-Gateway used by feature tests
 * (TODO-Q2). It lets Inbox::callGatewaySend()/callGatewayPesanKeluar() run
 * their real cURL + response-parsing code against controllable answers,
 * without sending anything to WhatsApp.
 *
 * URL shape: /skenario/<name>/<endpoint>, e.g. /skenario/http500/send.
 * The test sets inbox.gatewayBaseUrl to http://127.0.0.1:<port>/skenario/<name>
 * and CI4 appends the endpoint itself, so no server-side state is needed.
 *
 * Every request is appended (one JSON line) to the file named by the
 * FAKE_GATEWAY_LOG environment variable so tests can assert what CI4 sent.
 */

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (! preg_match('#^/skenario/([a-z0-9_]+)/([a-z/-]+)$#', $path, $m)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'route fake gateway tidak dikenal: ' . $path]);

    return true;
}

[, $skenario, $endpoint] = $m;
$raw  = (string) file_get_contents('php://input');
$body = json_decode($raw, true);
$body = is_array($body) ? $body : [];

$headers = function_exists('getallheaders') ? getallheaders() : [];
$log     = getenv('FAKE_GATEWAY_LOG');
if (is_string($log) && $log !== '') {
    file_put_contents($log, json_encode([
        'skenario'      => $skenario,
        'endpoint'      => $endpoint,
        'authorization' => $headers['Authorization'] ?? ($headers['authorization'] ?? null),
        'body'          => $body,
    ]) . "\n", FILE_APPEND | LOCK_EX);
}

$kirim = static function (int $status, array $json): bool {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($json);

    return true;
};

$states = ['send' => 'sent', 'send-media' => 'sent', 'edit' => 'edited', 'delete' => 'deleted'];
$sukses = [
    'success'  => true,
    'state'    => $states[$endpoint] ?? 'ok',
    'replayed' => false,
];
if ($endpoint === 'send' || $endpoint === 'send-media') {
    $sukses['wa_message_id'] = 'FAKEWA' . strtoupper(bin2hex(random_bytes(6)));
    $sukses['timestamp']     = gmdate('Y-m-d\TH:i:s.000\Z');
    if (array_key_exists('quoted', $body)) {
        $sukses['quote_applied'] = true;
    }
}
if ($endpoint === 'send-media') {
    $sukses['media_ref'] = [
        'direct_path'      => '/v/t62.fake/' . $sukses['wa_message_id'],
        'media_key_base64' => base64_encode('fake-media-key-' . $sukses['wa_message_id']),
    ];
}

switch ($skenario) {
    case 'ok':
        return $kirim(200, $sukses);

    case 'lama':
        // Older Gateway build that predates the `quote_applied` field.
        unset($sukses['quote_applied']);

        return $kirim(200, $sukses);

    case 'tolak400':
        return $kirim(400, ['success' => false, 'error_code' => 'INVALID_CHAT_ID', 'message' => 'chat_id tidak valid']);

    case 'unresolved':
        return $kirim(504, ['success' => false, 'error_code' => 'SEND_UNRESOLVED', 'state' => 'in_flight', 'replayed' => false, 'message' => 'hasil belum pasti']);

    case 'http500':
        return $kirim(500, ['success' => false, 'error_code' => 'SEND_FAILED', 'message' => 'Evolution gagal mengirim']);

    case 'bukan_json':
        http_response_code(502);
        header('Content-Type: text/html');
        echo '<html><body>502 Bad Gateway</body></html>';

        return true;

    case 'sukses_palsu':
        return $kirim(200, ['success' => false, 'message' => 'ditolak walau HTTP 200']);

    case 'lambat':
        // Longer than the 10 s CURLOPT_TIMEOUT used by Inbox.php.
        sleep(12);

        return $kirim(200, $sukses);
}

return $kirim(404, ['success' => false, 'message' => 'skenario tidak dikenal: ' . $skenario]);
