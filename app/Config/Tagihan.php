<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Konfigurasi halaman Tagihan -- lihat App\Controllers\Tagihan &
 * App\Services\KalkulasiJatuhTempo.
 *
 * Jatuh tempo tagihan SENGAJA tidak disimpan sebagai kolom di database
 * (keputusan produk: hindari perubahan skema untuk fitur ini). Sebagai
 * gantinya jatuh tempo adalah KEBIJAKAN GLOBAL yang dihitung saat
 * ditampilkan: transaksi.tanggal + $defaultTempoHari. Konsekuensinya
 * satu nilai tempo berlaku untuk semua transaksi/pelanggan -- tidak
 * bisa diatur berbeda per transaksi/pelanggan tanpa kolom baru.
 *
 * Override lewat .env:
 *   tagihan.defaultTempoHari = 14
 */
class Tagihan extends BaseConfig
{
    /**
     * Tempo (hari) dari tanggal transaksi sampai jatuh tempo tagihan.
     */
    public int $defaultTempoHari = 7;
}
