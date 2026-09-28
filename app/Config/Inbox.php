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
     * Batas ukuran media MASUK yang boleh diunduh / ditampilkan lewat
     * `GET /inbox/media/(:num)` (jalur baca live-fetch), dalam MB.
     *
     * TERPISAH dari `maxMediaUploadMb` di atas: yang itu batas KELUAR
     * (kasir upload dari POS, dibatasi <= default Gateway 20MB). Media
     * masuk dari pelanggan bisa jauh lebih besar dan tetap layak
     * ditampilkan ke kasir, jadi batas unduh SENGAJA lebih longgar.
     * JANGAN dipakai untuk membatasi kirim/Teruskan keluar.
     *
     * Diisi lewat .env: inbox.maxMediaDownloadMb (opsional, default 100).
     */
    public int $maxMediaDownloadMb = 100;

    /**
     * Batas byte yang boleh diunduh + disimpan ke disk oleh PREFETCH saat
     * webhook pesan masuk (`InboxGatewayApi::messages()`), dalam MB.
     *
     * Jalur ingest ini TIDAK TERPERCAYA (mengalir dari Gateway/Baileys) dan
     * mem-buffer + men-dekripsi + menulis ke disk, jadi SENGAJA dipisah dari
     * batas unduh/tampilan `maxMediaDownloadMb` yang lebih longgar (100MB) --
     * memakai batas tampilan di ingest memperbesar permukaan DoS memori+disk
     * per pesan masuk. Default 15 mempertahankan perilaku pra-refactor.
     * JANGAN dipakai untuk tampilan atau kirim/Teruskan.
     *
     * Diisi lewat .env: inbox.maxMediaPrefetchMb (opsional, default 15).
     */
    public int $maxMediaPrefetchMb = 15;

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
        // SEC-901: BaseConfig::__construct() menimpa properti dengan nilai env
        // mentah, jadi rekam default DEKLARASI (15/100/15) lebih dulu --
        // itulah fallback sah saat nilai env tidak valid.
        $defaultUpload   = $this->maxMediaUploadMb;
        $defaultDownload = $this->maxMediaDownloadMb;
        $defaultPrefetch = $this->maxMediaPrefetchMb;

        parent::__construct();

        $this->gatewayToken     = (string) (env('inbox.gatewayToken') ?? '');
        $this->slaGreenMinutes   = (int) (env('inbox.slaGreenMinutes') ?? $this->slaGreenMinutes);
        $this->slaYellowMinutes  = (int) (env('inbox.slaYellowMinutes') ?? $this->slaYellowMinutes);
        $this->gatewayBaseUrl   = rtrim((string) (env('inbox.gatewayBaseUrl') ?? ''), '/');
        $this->maxMediaUploadMb   = $this->batasiEnvMb('inbox.maxMediaUploadMb', $defaultUpload);
        $this->maxMediaDownloadMb = $this->batasiEnvMb('inbox.maxMediaDownloadMb', $defaultDownload);
        $this->maxMediaPrefetchMb = $this->batasiEnvMb('inbox.maxMediaPrefetchMb', $defaultPrefetch, $this->maxMediaDownloadMb);
        $this->mediaStoragePath = (string) (env('inbox.mediaStoragePath') ?? '');
    }

    /**
     * SEC-901: validasi nilai env batas media (MB). Nilai tidak sah
     * (< 1, atau untuk prefetch melebihi batas unduh/tampilan) JATUH ke
     * default + peringatan log, supaya salah-ketik tidak diam-diam
     * melumpuhkan (0/negatif -> semua prefetch 413) atau menonaktifkan
     * kontrol DoS (prefetch > batas unduh). Nilai sah tidak diubah;
     * default 15/100/15 tetap.
     */
    private function batasiEnvMb(string $kunci, int $default, ?int $maks = null): int
    {
        $nilai = (int) (env($kunci) ?? $default);

        if ($nilai < 1 || ($maks !== null && $nilai > $maks)) {
            log_message(
                'warning',
                'Config\\Inbox: env ' . $kunci . ' tidak sah (' . $nilai . '); memakai default ' . $default . '.'
            );

            return $default;
        }

        return $nilai;
    }
}
