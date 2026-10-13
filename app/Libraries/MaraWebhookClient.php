<?php

namespace App\Libraries;

use Config\Mara as MaraConfig;

/**
 * HTTP client untuk dispatch event REALTIME AuliaPos -> M.A.R.A
 * (`POST {mara.webhookUrl}`, satu per pesan). Arah KEBALIKAN dari
 * `MaraController::export()/resolve()` (M.A.R.A -> AuliaPos, Bearer
 * token `mara.apiToken`) -- lihat Config\Mara untuk alasan kedua
 * rahasia ini sengaja terpisah.
 *
 * Auth dikonfirmasi M.A.R.A via Telegram (2026-10-13), BUKAN Bearer:
 * tanda tangan HMAC-SHA256 di atas BODY MENTAH, dua header:
 *   X-Webhook-Timestamp:   <detik unix saat kirim>
 *   X-Webhook-Signature-V2: hex(hmac_sha256(mara.webhookSecret,
 *                                 "<timestamp>." . <body mentah>))
 * Toleransi 300 detik di sisi M.A.R.A -- jam kedua server wajib NTP-sync.
 *
 * SENGAJA best-effort/fire-and-forget (pola sama dengan prefetch media
 * Tahap C di InboxGatewayApi::messages()): gagal kirim webhook TIDAK
 * PERNAH menggagalkan penyimpanan/pengiriman pesan itu sendiri di
 * AuliaPos -- M.A.R.A hanya kehilangan satu event, bukan AuliaPos yang
 * rusak. Pemanggil TIDAK boleh membungkus panggilan ini di dalam
 * transaksi DB utama (lihat MaraWebhookDispatcher::dispatchAfterInsert()).
 *
 * Idempotency (kontrak M.A.R.A, serah terima 2026-10-13): `event_id`
 * WAJIB unik per event, dan WAJIB dikirim DUA kali -- di header
 * `webhook-id` (lowercase, bukan `Webhook-Id`) DAN di body -- M.A.R.A
 * membaca dari header untuk dedup utama, body untuk pencocokan dengan
 * WhatsApp (conversation_id + wa_message_id) sebagai cadangan kedua.
 */
class MaraWebhookClient
{
    public function __construct(private readonly MaraConfig $config)
    {
    }

    /**
     * True bila webhook sudah dikonfigurasi (`mara.webhookUrl` dan
     * `mara.webhookSecret` keduanya terisi). Dipakai dispatcher supaya
     * tidak mencoba HTTP call sama sekali selama M.A.R.A belum
     * menyediakan alamat/kunci final (lihat TODO-MARA1).
     */
    public function isConfigured(): bool
    {
        return $this->config->webhookUrl !== '' && $this->config->webhookSecret !== '';
    }

    /**
     * Kirim satu event `inbox-event` ke M.A.R.A.
     *
     * @param array<string, mixed> $event Payload sudah lengkap TERMASUK
     *        `event_id` (lihat MaraWebhookDispatcher pembentuk payload).
     * @return array{ok: bool, status?: int, error?: string}
     */
    public function sendInboxEvent(array $event): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'mara.webhookUrl/mara.webhookSecret belum dikonfigurasi'];
        }

        $eventId = (string) ($event['event_id'] ?? '');
        if ($eventId === '') {
            // Trust boundary ke diri sendiri (bukan Gateway) -- pemanggil
            // WAJIB sudah mengisi event_id (ARCH-001-style single builder
            // di MaraWebhookDispatcher), jadi ini pertanda bug pemanggil,
            // bukan kondisi yang boleh dikirim diam-diam tanpa ID.
            return ['ok' => false, 'error' => 'event_id wajib diisi sebelum dikirim ke webhook M.A.R.A'];
        }

        $body      = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $this->config->webhookSecret);

        $ch = curl_init($this->config->webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'webhook-id: ' . $eventId,
                'X-Webhook-Timestamp: ' . $timestamp,
                'X-Webhook-Signature-V2: ' . $signature,
            ],
            CURLOPT_RETURNTRANSFER => true,
            // Pendek SENGAJA (lihat Config\Mara::$webhookTimeoutSeconds):
            // ini jalur best-effort, tidak boleh menahan request
            // kasir/webhook Gateway yang memicunya.
            CURLOPT_TIMEOUT        => $this->config->webhookTimeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->config->webhookTimeoutSeconds,
        ]);

        $execResult = curl_exec($ch);
        $curlErrno  = curl_errno($ch);
        $curlError  = curl_error($ch);
        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($execResult === false || $curlErrno !== 0) {
            return ['ok' => false, 'error' => 'Tidak bisa menghubungi webhook M.A.R.A: ' . $curlError];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return ['ok' => false, 'status' => $httpCode, 'error' => 'M.A.R.A menolak event (HTTP ' . $httpCode . ')'];
        }

        return ['ok' => true, 'status' => $httpCode];
    }
}
