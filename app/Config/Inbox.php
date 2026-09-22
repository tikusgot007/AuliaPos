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

    /**
     * SLA threshold Inbox: usia <15 menit = hijau, 15-60 menit = kuning,
     * >60 menit = merah. Diisi lewat .env bila perlu.
     */
    public int $slaGreenMinutes = 15;
    public int $slaYellowMinutes = 60;

    /**
     * Batas ukuran file media KELUAR (kasir upload dari POS) dalam MB,
     * dicek di sisi CI4 SEBELUM file di-base64-encode dan dikirim ke
     * Gateway. SENGAJA dibuat <= MAX_MEDIA_UPLOAD_MB milik Gateway
     * (default Gateway 20MB) supaya CI4 menolak lebih dulu dengan
     * pesan jelas, bukan menunggu Gateway menolak lewat HTTP 413.
     *
     * Diisi lewat .env: inbox.maxMediaUploadMb (opsional, default 15).
     */
    public int $maxMediaUploadMb = 15;

    /**
     * Folder penyimpanan permanen media inbox (gambar/dokumen/sticker),
     * SENGAJA di luar direktori aplikasi -- lihat catatan di
     * InboxMediaStorage. Kosong = fitur nonaktif, semua media otomatis
     * fallback ke live-fetch dari Gateway seperti sebelum Tahap C
     * (TIDAK error, cuma lambat lagi seperti sedia kala).
     *
     * Diisi lewat .env: inbox.mediaStoragePath (contoh: X:\aulia_inbox_media\)
     */
    public string $mediaStoragePath;

    public function __construct()
    {
        parent::__construct();

        $this->gatewayToken     = (string) (env('inbox.gatewayToken') ?? '');
        $this->slaGreenMinutes   = (int) (env('inbox.slaGreenMinutes') ?? $this->slaGreenMinutes);
        $this->slaYellowMinutes  = (int) (env('inbox.slaYellowMinutes') ?? $this->slaYellowMinutes);
        $this->gatewayBaseUrl   = rtrim((string) (env('inbox.gatewayBaseUrl') ?? ''), '/');
        $this->maxMediaUploadMb = (int) (env('inbox.maxMediaUploadMb') ?? $this->maxMediaUploadMb);
        $this->slaGreenMinutes   = (int) (env('inbox.slaGreenMinutes') ?? $this->slaGreenMinutes);
        $this->slaYellowMinutes  = (int) (env('inbox.slaYellowMinutes') ?? $this->slaYellowMinutes);
        $this->mediaStoragePath = (string) (env('inbox.mediaStoragePath') ?? '');
    }
}
