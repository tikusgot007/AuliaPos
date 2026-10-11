<?php

namespace App\Libraries;

use Config\Inbox as InboxConfig;

/**
 * HTTP client for the CI4 -> Gateway (evolution-gateway adapter) calls:
 * POST /send, /send-media, /edit, /delete, /read and /media/download, all
 * authenticated with the Bearer `inbox.gatewayToken`.
 *
 * Extracted verbatim from App\Controllers\Inbox (TODO-Q5c); behavior, payload
 * shapes, timeouts and result arrays are unchanged. Controller-specific work
 * (ownership, audit row, HTTP response to the browser) stays in the controller.
 * Payload builders are public so the contract can be tested without HTTP
 * (tests/unit/InboxGatewayPayloadContractTest.php).
 */
class InboxGatewayClient
{
    public function __construct(private readonly InboxConfig $config)
    {
    }

    /**
     * Panggil POST /media/download milik Gateway -- minta Gateway
     * ambil+dekripsi 1 file media dari server WhatsApp berdasarkan
     * referensi yang tersimpan, lalu kembalikan isinya (binary) di
     * sini. TIDAK ADA file yang disimpan ke disk CI4 di titik manapun.
     *
     * $timeoutSeconds default 30 (live-fetch on-demand dari
     * Inbox::media()) -- InboxGatewayApi::messages() memanggil dengan
     * timeout lebih pendek (8 detik) untuk prefetch saat pesan masuk,
     * supaya tidak menahan respons webhook Gateway lama-lama kalau
     * memang lambat.
     *
     * PRN-701: `$maxBytes` dipasok PEMANGGIL. Default null = batas UNDUH
     * /tampilan media masuk (`maxMediaDownloadMb`), masih dipakai
     * `Inbox::media()`. Prefetch pesan masuk kini memasok batas INGEST
     * (`maxMediaPrefetchMb`) secara eksplisit, dan jalur kirim/Teruskan
     * memasok batas unggah KELUAR (`maxMediaUploadMb`) supaya bound SEC-602
     * tidak melemah. Satu sumbu batas, bukan konstanta yang dipakai ulang
     * lintas arah transfer.
     *
     * @return array{ok: bool, binary?: string, status?: int, error?: string}
     */
    public function mediaDownload(array $mediaRef, ?string $mimetype, int $timeoutSeconds = 30, ?int $maxBytes = null): array
    {
        $url = $this->config->gatewayBaseUrl . '/media/download';

        $payload = json_encode([
            'media_type'        => $mediaRef['media_type'] ?? null,
            'direct_path'       => $mediaRef['direct_path'] ?? null,
            'media_key_base64'  => $mediaRef['media_key_base64'] ?? null,
            'mimetype'          => $mimetype,
        ]);

        // SEC-602/COR-701: batasi transfer SELAMA unduhan, bukan setelah
        // seluruh respons ter-buffer. Satu-satunya sumber sinyal overflow
        // adalah `CURLOPT_WRITEFUNCTION` yang menghitung byte dan membatalkan
        // transfer begitu melewati batas -- mencegah respons Gateway besar
        // menghabiskan memori sebelum sempat dicek. Opsi MAXFILESIZE bawaan
        // cURL SENGAJA dibuang: ia hanya dihormati saat `Content-Length`
        // tersedia dan bisa mendahului WRITEFUNCTION sehingga "terlalu besar"
        // salah terklasifikasi `502` (ALT-701).
        $maxBytes ??= InboxMediaBound::mbKeByte($this->config->maxMediaDownloadMb);
        $body     = '';
        $overflow = false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->config->gatewayToken,
            ],
            // WRITEFUNCTION menggantikan RETURNTRANSFER: body diakumulasi
            // manual supaya batas byte bisa ditegakkan sambil mengalir.
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION  => function ($handle, string $chunk) use (&$body, &$overflow, $maxBytes): int {
                $hasil = self::akumulasiChunk($body, $chunk, $maxBytes);
                $body  = $hasil['body'];

                if ($hasil['overflow']) {
                    $overflow = true;

                    return 0; // membatalkan transfer (CURLE_WRITE_ERROR)
                }

                return strlen($chunk);
            },
            // Lebih lama dari kirim teks -- unduh+dekripsi file butuh
            // waktu lebih, terutama untuk dokumen berukuran besar.
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $execResult  = curl_exec($ch);
        // Dibaca SEBELUM curl_close(): errno adalah bukti transfer dihentikan
        // oleh bound (CURLE_WRITE_ERROR) walau `$execResult` berupa `false`
        // dan Gateway tidak mengirim `Content-Length`.
        $curlErrno   = curl_errno($ch);
        $curlError   = curl_error($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        // Transfer dibatalkan karena melewati batas (SEC-602/COR-701): pesan
        // eksplisit supaya tidak tertukar dengan kegagalan Gateway biasa.
        // Klasifikasi deterministik lewat sinyal overflow ATAU errno batas,
        // bukan lewat `Content-Length` yang bisa saja absen.
        if ($overflow || $curlErrno === CURLE_WRITE_ERROR) {
            return ['ok' => false, 'status' => 413, 'error' => 'Lampiran dari WhatsApp melebihi batas ukuran.'];
        }

        if ($execResult === false) {
            return ['ok' => false, 'status' => 502, 'error' => 'Tidak bisa menghubungi Gateway: ' . $curlError];
        }

        // Gateway balas binary langsung kalau sukses (Content-Type
        // sesuai mimetype file), atau JSON {success:false,...} kalau
        // gagal (mis. media kadaluarsa). Bedakan lewat Content-Type
        // response, bukan cuma HTTP status, supaya tidak salah
        // interpretasi binary sebagai JSON atau sebaliknya.
        $isJsonError = $httpCode >= 400 || str_contains((string) $contentType, 'application/json');

        if ($isJsonError) {
            $json = json_decode($body, true);
            $message = is_array($json) ? ($json['message'] ?? 'Gateway menolak permintaan media.') : ('HTTP ' . $httpCode);

            return ['ok' => false, 'status' => $httpCode ?: 502, 'error' => $message];
        }

        return ['ok' => true, 'binary' => $body];
    }

    /**
     * TEST-701: akumulasi SATU chunk body saat transfer media mengalir.
     * Sengaja fungsi MURNI supaya batas byte bisa diuji langsung tanpa
     * cURL. Mengembalikan `overflow = true` -- TANPA menambah chunk -- bila
     * total akan melewati `$maxBytes`; callback cURL memakainya untuk
     * membatalkan transfer (return 0 -> CURLE_WRITE_ERROR).
     *
     * @return array{body: string, overflow: bool}
     */
    private static function akumulasiChunk(string $body, string $chunk, int $maxBytes): array
    {
        if (strlen($body) + strlen($chunk) > $maxBytes) {
            return ['body' => $body, 'overflow' => true];
        }

        return ['body' => $body . $chunk, 'overflow' => false];
    }

    /**
     * Panggil POST /delete milik Gateway (Bearer gatewayToken). Mengembalikan
     * bentuk array yang sama dengan `callGatewaySend()` supaya bisa diproses
     * `gatewayFailureResponse()`.
     *
     * @return array{ok: bool, state?: ?string, replayed?: bool, error?: string, error_code?: ?string, http_code?: int}
     */
    public function delete(string $chatId, string $waMessageId, ?string $operationId): array
    {
        return $this->postPesanKeluar(
            $this->config->gatewayBaseUrl . '/delete',
            $this->buildDeletePayload($chatId, $waMessageId, $operationId)
        );
    }

    /**
     * Bentuk payload JSON POST /delete persis seperti yang dikirim ke
     * Gateway. Diekstrak (TODO-Q3) supaya bentuk payload bisa diverifikasi
     * lewat unit test tanpa HTTP nyata; perilaku identik dengan sebelumnya.
     *
     * @return array<string, mixed>
     */
    public function buildDeletePayload(string $chatId, string $waMessageId, ?string $operationId): array
    {
        $payloadData = [
            'chat_id'       => $chatId,
            'wa_message_id' => $waMessageId,
        ];
        if ($operationId !== null) {
            $payloadData['operation_id'] = $operationId;
        }

        return $payloadData;
    }

    /**
     * Panggil POST /edit milik Gateway (Bearer gatewayToken).
     *
     * @return array{ok: bool, state?: ?string, replayed?: bool, error?: string, error_code?: ?string, http_code?: int}
     */
    public function edit(string $chatId, string $waMessageId, string $newText, ?string $operationId): array
    {
        return $this->postPesanKeluar(
            $this->config->gatewayBaseUrl . '/edit',
            $this->buildEditPayload($chatId, $waMessageId, $newText, $operationId)
        );
    }

    /**
     * Bentuk payload JSON POST /edit persis seperti yang dikirim ke
     * Gateway. Diekstrak (TODO-Q3), perilaku identik dengan sebelumnya
     * (`operation_id` selalu ikut, termasuk saat null -- adapter mewajibkan
     * field ini untuk /edit, lihat ci4Routes.js:592-599).
     *
     * @return array<string, mixed>
     */
    public function buildEditPayload(string $chatId, string $waMessageId, string $newText, ?string $operationId): array
    {
        return [
            'chat_id'       => $chatId,
            'wa_message_id' => $waMessageId,
            'new_text'      => $newText,
            'operation_id'  => $operationId,
        ];
    }

    /**
     * Kirim satu request JSON ke adapter untuk aksi pesan keluar (edit/hapus)
     * dan normalkan responsnya. Dipisah dari `callGatewaySend()` karena tidak
     * menyentuh `InboxOutgoingRequest` (tidak ada quoted/forward).
     *
     * @param array<string, mixed> $payloadData
     * @return array{ok: bool, state?: ?string, replayed?: bool, error?: string, error_code?: ?string, http_code?: int}
     */
    private function postPesanKeluar(string $url, array $payloadData): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payloadData),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->config->gatewayToken,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $rawResponse = curl_exec($ch);
        $curlError   = curl_error($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($rawResponse === false) {
            return ['ok' => false, 'error' => 'Tidak bisa menghubungi Gateway: ' . $curlError];
        }

        $json = json_decode($rawResponse, true);

        if ($httpCode >= 200 && $httpCode < 300 && is_array($json) && ($json['success'] ?? false) === true) {
            return [
                'ok'         => true,
                'state'      => $json['state'] ?? null,
                'replayed'   => (bool) ($json['replayed'] ?? false),
                'error_code' => null,
                'http_code'  => $httpCode,
            ];
        }

        $errorMessage = is_array($json)
            ? ($json['message'] ?? ('Gateway menolak (HTTP ' . $httpCode . ')'))
            : ('HTTP ' . $httpCode . ', respons Gateway tidak valid: ' . substr((string) $rawResponse, 0, 200));

        return [
            'ok'         => false,
            'error'      => $errorMessage,
            'error_code' => is_array($json) ? ($json['error_code'] ?? null) : null,
            'state'      => is_array($json) ? ($json['state'] ?? null) : null,
            'replayed'   => is_array($json) ? (bool) ($json['replayed'] ?? false) : false,
            'http_code'  => $httpCode,
        ];
    }

    /**
     * Bentuk payload JSON POST /send persis seperti yang dikirim ke Gateway.
     * Diekstrak dari `callGatewaySend()` (TODO-Q3) supaya kontrak bentuk
     * payload (key wajib vs opsional) bisa diverifikasi lewat unit test
     * tanpa HTTP nyata -- perilaku identik dengan sebelumnya, tidak ada
     * perubahan apa pun pada bentuk hasil.
     *
     * @return array<string, mixed>
     */
    public function buildSendPayload(InboxOutgoingRequest $request): array
    {
        $payloadData = [
            'chat_id' => $request->chatId,
            'text'    => $request->text,
        ];

        // Additive (CON-007/REQ-039): `operation_id` hanya ikut dikirim
        // kalau kasir mengirimkannya. Tanpa itu Gateway berperilaku
        // seperti sebelumnya, dan kunci TIDAK dibuat di sini.
        if ($request->operationId !== null) {
            $payloadData['operation_id'] = $request->operationId;
        }

        // Balas Pesan (REQ-001): hanya ikut kalau kasir benar-benar
        // mengutip. Tanpa itu Gateway tidak mengubah apa pun.
        if ($request->quoted !== null) {
            $payloadData['quoted'] = $request->quoted;
        }

        // Teruskan (REQ-001): hanya ikut saat kasir benar-benar meneruskan.
        // Gateway yang belum understands `forward` mengabaikan field tak
        // dikenal, jadi request biasa tidak tersentuh.
        if ($request->forward) {
            $payloadData['forward'] = true;
        }

        return $payloadData;
    }

    /**
     * Panggil POST /send milik Gateway lewat cURL langsung -- tidak
     * bergantung ke library HTTP client tambahan (Guzzle dkk) yang
     * belum tentu ter-install di project ini.
     *
     * PRN-303: menerima satu `InboxOutgoingRequest` (bukan daftar parameter
     * flag). `quoted`/`forward` sudah dijamin saling-menolak oleh konstruktor
     * value object itu (CON-001).
     *
     * @return array{ok: bool, wa_message_id?: ?string, timestamp?: ?string, quote_applied?: bool, forward_marker_applied?: ?string, error?: string}
     */
    public function send(InboxOutgoingRequest $request): array
    {
        $url = $this->config->gatewayBaseUrl . '/send';

        $payloadData = $this->buildSendPayload($request);
        $payload     = json_encode($payloadData);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->config->gatewayToken,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $rawResponse = curl_exec($ch);
        $curlError   = curl_error($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($rawResponse === false) {
            return ['ok' => false, 'error' => 'Tidak bisa menghubungi Gateway: ' . $curlError];
        }

        $json = json_decode($rawResponse, true);

        if ($httpCode >= 200 && $httpCode < 300 && is_array($json) && ($json['success'] ?? false) === true) {
            return [
                'ok'            => true,
                'wa_message_id' => $json['wa_message_id'] ?? null,
                'timestamp'     => $json['timestamp'] ?? null,
                'error_code'    => $json['error_code'] ?? null,
                'state'         => $json['state'] ?? 'sent',
                'replayed'      => (bool) ($json['replayed'] ?? false),
                // ASSUMPTION-010: Gateway yang belum understands `quoted`
                // (rollout parsial) tidak mengirim field ini sama sekali,
                // dan itu diperlakukan sebagai false -- kasir lalu melihat
                // penanda "Terkirim tanpa kutipan" yang jujur, bukan
                // kutipan yang dikira berhasil padahal tidak sampai.
                'quote_applied' => (bool) ($json['quote_applied'] ?? false),
                // Teruskan (REQ-003): metode penanda yang benar-benar dipakai
                // Gateway. Absen = Gateway versi lama (rollout parsial),
                // dilaporkan apa adanya lewat withForwardMarker(); label UI
                // tetap dibangun dari kolom `is_forwarded` (REQ-008).
                'forward_marker_applied' => $json['forward_marker_applied'] ?? null,
                'http_code'     => $httpCode,
            ];
        }

        $errorMessage = is_array($json)
            ? ($json['message'] ?? ('Gateway menolak (HTTP ' . $httpCode . ')'))
            : ('HTTP ' . $httpCode . ', respons Gateway tidak valid: ' . substr((string) $rawResponse, 0, 200));

        return [
            'ok'         => false,
            'error'      => $errorMessage,
            'error_code' => is_array($json) ? ($json['error_code'] ?? null) : null,
            'state'      => is_array($json) ? ($json['state'] ?? null) : null,
            'replayed'   => is_array($json) ? (bool) ($json['replayed'] ?? false) : false,
            'http_code'  => $httpCode,
        ];
    }

    /**
     * Panggil POST /read milik Gateway: minta Evolution menandai pesan MASUK
     * sebagai dibaca (WhatsApp read receipt Arah 1). FAIL-SOFT -- tidak pernah
     * throw; pemanggil hanya butuh tahu berhasil/tidak.
     *
     * @param array<int, string> $waMessageIds
     * @return array{ok: bool, requested?: int, error?: ?string}
     */
    public function buildMarkReadPayload(string $chatId, array $waMessageIds): array
    {
        return [
            'chat_id'        => $chatId,
            'wa_message_ids' => array_values($waMessageIds),
        ];
    }

    /**
     * @param array<int, string> $waMessageIds
     * @return array{ok: bool, requested?: int, error?: ?string}
     */
    public function markRead(string $chatId, array $waMessageIds): array
    {
        $url = $this->config->gatewayBaseUrl . '/read';

        $payload = json_encode($this->buildMarkReadPayload($chatId, $waMessageIds));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->config->gatewayToken,
            ],
            CURLOPT_RETURNTRANSFER => true,
            // Best-effort (blue tick) di jalur buka percakapan: timeout SENGAJA
            // pendek supaya worker PHP tidak tertahan lama bila Gateway lambat.
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);

        $rawResponse = curl_exec($ch);
        $curlError   = curl_error($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($rawResponse === false) {
            log_message('warning', 'Inbox::whatsappDibaca gagal menghubungi Gateway: ' . $curlError);
            return ['ok' => false, 'error' => $curlError];
        }

        $json = json_decode($rawResponse, true);
        $ok = $httpCode >= 200 && $httpCode < 300 && is_array($json) && ($json['success'] ?? false) === true;

        if (!$ok) {
            log_message('warning', 'Inbox::whatsappDibaca ditolak Gateway (HTTP ' . $httpCode . ').');
            return ['ok' => false, 'error' => is_array($json) ? ($json['message'] ?? null) : null];
        }

        return ['ok' => true, 'requested' => (int) ($json['requested'] ?? 0)];
    }

    /**
     * Panggil POST /send-media milik Gateway -- versi media dari
     * callGatewaySend(). Timeout lebih lama (30 detik, sama seperti
     * callGatewayMediaDownload()) karena upload base64 + kirim ke
     * Baileys butuh waktu lebih dibanding teks biasa.
     *
     * PRN-303: menerima satu `InboxOutgoingRequest` (bukan 10 parameter).
     * Objek kutipan media IDENTIK bentuknya dengan jalur teks -- termasuk
     * `fromMe` yang hanya diturunkan dari `direction`, dan `sender_jid` yang
     * boleh null untuk sumber outgoing. `quoted`/`forward` sudah dijamin
     * saling-menolak oleh konstruktor value object itu (CON-001).
     *
     * @return array{ok: bool, wa_message_id?: ?string, timestamp?: ?string, media_ref?: ?array, quote_applied?: bool, forward_marker_applied?: ?string, error?: string}
     */
    public function buildSendMediaPayload(InboxOutgoingRequest $request): array
    {
        $payloadData = [
            'chat_id'      => $request->chatId,
            'media_type'   => $request->mediaType,
            'media_base64' => $request->mediaBase64,
            'mimetype'     => $request->mimetype,
            'file_name'    => $request->fileName,
            // $caption selalu string (lihat signature method) -- Gateway
            // menolak null untuk field ini, cuma menerima string (boleh
            // kosong ''). Sebelumnya dikirim null saat kosong, tidak
            // pernah ketahuan salah karena outgoing media belum pernah
            // dites sampai ke Gateway asli.
            'caption'      => $request->caption,
        ];

        // Additive (CON-007/REQ-039): sama seperti callGatewaySend(),
        // kunci hanya diteruskan kalau kasir mengirimkannya -- tidak
        // pernah dibuat di sisi server.
        if ($request->operationId !== null) {
            $payloadData['operation_id'] = $request->operationId;
        }

        // Balas Pesan (REQ-001a): balas-dengan-lampiran sambil mengutip.
        // Hanya ikut kalau kasir benar-benar mengutip.
        if ($request->quoted !== null) {
            $payloadData['quoted'] = $request->quoted;
        }

        // Teruskan lampiran (TASK-006 (f)/REQ-001a): hanya ikut saat kasir
        // benar-benar meneruskan, dan TIDAK PERNAH bersama `quoted` (CON-001,
        // ditegakkan konstruktor `InboxOutgoingRequest`). Gateway versi lama
        // mengabaikan field tak dikenal, jadi kiriman media biasa tidak
        // tersentuh.
        if ($request->forward) {
            $payloadData['forward'] = true;
        }

        return $payloadData;
    }

    /**
     * Panggil POST /send-media milik Gateway -- versi media dari
     * callGatewaySend(). Timeout lebih lama (30 detik, sama seperti
     * callGatewayMediaDownload()) karena upload base64 + kirim ke
     * Baileys butuh waktu lebih dibanding teks biasa.
     *
     * PRN-303: menerima satu `InboxOutgoingRequest` (bukan 10 parameter).
     * Objek kutipan media IDENTIK bentuknya dengan jalur teks -- termasuk
     * `fromMe` yang hanya diturunkan dari `direction`, dan `sender_jid` yang
     * boleh null untuk sumber outgoing. `quoted`/`forward` sudah dijamin
     * saling-menolak oleh konstruktor value object itu (CON-001).
     *
     * @return array{ok: bool, wa_message_id?: ?string, timestamp?: ?string, media_ref?: ?array, quote_applied?: bool, forward_marker_applied?: ?string, error?: string}
     */
    public function sendMedia(InboxOutgoingRequest $request): array
    {
        $url = $this->config->gatewayBaseUrl . '/send-media';

        $payloadData = $this->buildSendMediaPayload($request);
        $payload     = json_encode($payloadData);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->config->gatewayToken,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $rawResponse = curl_exec($ch);
        $curlError   = curl_error($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($rawResponse === false) {
            return ['ok' => false, 'error' => 'Tidak bisa menghubungi Gateway: ' . $curlError];
        }

        $json = json_decode($rawResponse, true);

        if ($httpCode >= 200 && $httpCode < 300 && is_array($json) && ($json['success'] ?? false) === true) {
            $mediaRef = null;
            if (
                !empty($json['media_ref']) && is_array($json['media_ref'])
                && !empty($json['media_ref']['direct_path']) && !empty($json['media_ref']['media_key_base64'])
            ) {
                $mediaRef = [
                    'direct_path'      => $json['media_ref']['direct_path'],
                    'media_key_base64' => $json['media_ref']['media_key_base64'],
                ];
            }

            return [
                'ok'            => true,
                'wa_message_id' => $json['wa_message_id'] ?? null,
                'timestamp'     => $json['timestamp'] ?? null,
                'media_ref'     => $mediaRef,
                'error_code'    => $json['error_code'] ?? null,
                'state'         => $json['state'] ?? 'sent',
                'replayed'      => (bool) ($json['replayed'] ?? false),
                // ASSUMPTION-010, sama seperti jalur teks: Gateway yang belum
                // understands `quoted` tidak mengirim field ini, dan yang
                // hilang diperlakukan false.
                'quote_applied' => (bool) ($json['quote_applied'] ?? false),
                // Teruskan (TASK-006 (h)/REQ-003): metode penanda yang
                // benar-benar dipakai Gateway. Absen = Gateway versi lama
                // (rollout parsial), dilaporkan apa adanya lewat
                // withForwardMarker(); label UI tetap dari kolom `is_forwarded`.
                'forward_marker_applied' => $json['forward_marker_applied'] ?? null,
                'http_code'     => $httpCode,
            ];
        }

        $errorMessage = is_array($json)
            ? ($json['message'] ?? ('Gateway menolak (HTTP ' . $httpCode . ')'))
            : ('HTTP ' . $httpCode . ', respons Gateway tidak valid: ' . substr((string) $rawResponse, 0, 200));

        return [
            'ok'         => false,
            'error'      => $errorMessage,
            'error_code' => is_array($json) ? ($json['error_code'] ?? null) : null,
            'state'      => is_array($json) ? ($json['state'] ?? null) : null,
            'replayed'   => is_array($json) ? (bool) ($json['replayed'] ?? false) : false,
            'http_code'  => $httpCode,
        ];
    }
}
