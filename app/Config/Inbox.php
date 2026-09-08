<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Konfigurasi module Shared WhatsApp Inbox.
 *
 * $gatewayToken adalah shared secret antara CI4 dan Gateway
 * (Node.js/Baileys) -- Gateway mengirim ini sebagai
 * "Authorization: Bearer <token>" di setiap request ke endpoint
 * /api/inbox/gateway/*.
 *
 * SENGAJA diambil dari environment variable (.env), TIDAK PERNAH
 * di-hardcode di sini -- sesuai instruksi keamanan di spec:
 * "jangan hardcode token di source control".
 *
 * Isi di .env server:
 *   inbox.gatewayToken = <string acak yang panjang & sulit ditebak>
 *
 * Token yang SAMA PERSIS harus diisi di .env milik Gateway
 * (CI4_GATEWAY_TOKEN), supaya keduanya bisa saling autentikasi.
 */
class Inbox extends BaseConfig
{
    public string $gatewayToken;

    /**
     * Base URL Gateway (Node.js), TANPA trailing slash -- dipakai CI4
     * untuk memanggil endpoint POST /send milik Gateway (Phase 3:
     * outgoing). Contoh: http://192.168.1.20:3000
     *
     * Diisi lewat .env: inbox.gatewayBaseUrl
     */
    public string $gatewayBaseUrl;

    /**
     * Berapa detik sejak heartbeat terakhir sebelum Gateway dianggap
     * "stale"/tidak bisa dipercaya statusnya, walau baris
     * gateway_status masih bilang 'connected'. Default 2x interval
     * heartbeat Gateway (~15 detik) supaya ada toleransi wajar.
     */
    public int $heartbeatStaleSeconds = 30;

    public function __construct()
    {
        parent::__construct();

        $this->gatewayToken   = (string) (env('inbox.gatewayToken') ?? '');
        $this->gatewayBaseUrl = rtrim((string) (env('inbox.gatewayBaseUrl') ?? ''), '/');
    }
}
