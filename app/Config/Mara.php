<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Konfigurasi kontrak M.A.R.A (agent pemantau Inbox, jalan di aulia7,
 * satu LAN dengan server AuliaPos/aulia-serve2).
 *
 * $apiToken adalah shared secret M.A.R.A <-> CI4, dipakai SEPENUHNYA
 * terpisah dari $gatewayToken milik WA-Gateway (Config\Inbox) --
 * M.A.R.A dan WA-Gateway adalah dua klien machine-to-machine yang
 * berbeda, jangan pernah dicampur satu token supaya rotasi/cabut
 * akses salah satu tidak memutus yang lain.
 *
 * Isi di .env server:
 *   mara.apiToken = <string acak panjang, beda dari inbox.gatewayToken>
 *   mara.resolveSalt = <string acak, SEPARATE dari apiToken -- untuk
 *                        hash customer_ref di endpoint export>
 */
class Mara extends BaseConfig
{
    /**
     * Bearer token yang dicek MaraTokenFilter untuk semua endpoint
     * /api/inbox/mara/*.
     */
    public string $apiToken;

    /**
     * Salt untuk hash SHA-256 nomor telepon -> customer_ref di
     * endpoint export (training, anonim). TERPISAH dari apiToken --
     * kompromi salt ini TIDAK membuka akses endpoint, dan sebaliknya.
     */
    public string $resolveSalt;

    /**
     * Webhook REALTIME AuliaPos -> M.A.R.A (arah kebalikan dari
     * export/resolve di atas). Dikonfirmasi langsung oleh M.A.R.A via
     * Telegram (2026-10-13): auth BUKAN Bearer, melainkan tanda tangan
     * HMAC-SHA256 di atas body mentah, dengan kunci TERPISAH dari
     * $apiToken (arah & kegunaan beda -- lihat MaraWebhookClient).
     *
     * Isi di .env server (alamat & kunci FINAL dari dokumen serah terima
     * M.A.R.A, 2026-10-13 -- PENTING: jalur `/webhooks/` pakai 's'):
     *   mara.webhookUrl    = http://192.168.1.68:8644/webhooks/auliapos-inbox
     *   mara.webhookSecret = <kunci bersama HMAC, SEPARATE dari apiToken>
     *
     * Kosong ('') = webhook nonaktif (dispatcher jadi no-op) -- dipakai
     * saat belum ada alamat/kunci dari M.A.R.A (lihat status di TODO-MARA1).
     */
    public string $webhookUrl;

    /**
     * Kunci HMAC khusus webhook, TERPISAH dari $apiToken dan
     * $resolveSalt -- tiga rahasia dengan kegunaan berbeda, kompromi
     * satu tidak membuka yang lain.
     */
    public string $webhookSecret;

    /**
     * Toleransi umur timestamp tanda tangan (detik), dikonfirmasi M.A.R.A:
     * 300 detik. Request dengan X-Webhook-Timestamp di luar jendela ini
     * ditolak di sisi M.A.R.A -- AuliaPos cukup mengirim waktu server saat
     * ini, TIDAK perlu logic toleransi di sisi pengirim.
     */
    public int $webhookTimestampToleranceSeconds = 300;

    /**
     * Timeout HTTP ke webhook M.A.R.A, SENGAJA pendek: pengiriman event
     * ini best-effort, DILUAR alur utama penyimpanan pesan (sama seperti
     * prefetch media Tahap C di InboxGatewayApi::messages()) -- gagal di
     * sini TIDAK PERNAH menggagalkan penyimpanan/pengiriman pesan itu
     * sendiri, cuma event tidak sampai ke M.A.R.A.
     */
    public int $webhookTimeoutSeconds = 3;

    public function __construct()
    {
        parent::__construct();

        $this->apiToken      = (string) (env('mara.apiToken') ?? '');
        $this->resolveSalt   = (string) (env('mara.resolveSalt') ?? '');
        $this->webhookUrl    = (string) (env('mara.webhookUrl') ?? '');
        $this->webhookSecret = (string) (env('mara.webhookSecret') ?? '');
    }
}