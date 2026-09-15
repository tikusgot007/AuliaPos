<?php

namespace App\Services;

/**
 * Kalkulasi jatuh tempo tagihan -- transaksi.tanggal + tempo (hari).
 *
 * Jatuh tempo TIDAK disimpan di database (lihat Config\Tagihan) --
 * dihitung ulang setiap kali dibutuhkan dari kebijakan tempo global.
 * Sengaja pure/stateless (tidak menyentuh DB/session/request/config
 * kecuali lewat parameter eksplisit) supaya bisa diuji tanpa bootstrap
 * framework penuh -- lihat tests/unit/KalkulasiJatuhTempoTest.php.
 * Mengikuti pola App\Services\KalkulasiStatusPembayaran.
 *
 * $tempoHari dibuat sebagai parameter eksplisit (bukan baca
 * config('Tagihan') langsung di dalam class ini) supaya class tetap
 * pure dan gampang ditest -- pemanggil (controller) yang bertanggung
 * jawab membaca config default.
 */
class KalkulasiJatuhTempo
{
    public static function hitung(string $tanggalTransaksi, int $tempoHari): string
    {
        return date('Y-m-d', strtotime($tanggalTransaksi . " +{$tempoHari} days"));
    }

    /**
     * @param string $sekarang Tanggal "hari ini" (Y-m-d), dibuat parameter
     *                         eksplisit (bukan date() langsung) supaya
     *                         boundary di sekitar tengah malam tetap
     *                         gampang ditest secara deterministik.
     */
    public static function isOverdue(string $tanggalTransaksi, int $tempoHari, string $sekarang): bool
    {
        return self::hitung($tanggalTransaksi, $tempoHari) < $sekarang;
    }
}
