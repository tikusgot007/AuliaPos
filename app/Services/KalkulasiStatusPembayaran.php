<?php

namespace App\Services;

/**
 * Kalkulasi status_pembayaran transaksi -- satu sumber kebenaran untuk
 * perhitungan yang SEBELUMNYA terduplikasi persis antara
 * TransaksiModel::sinkronkanPembayaran() dan
 * App\Commands\RepairTotalDibayar (dan, dalam bentuk setara berbasis
 * "sisa <= 0", pada app/Views/tagihan/index.php).
 *
 * Sengaja pure/stateless (tidak menyentuh DB, session, maupun request)
 * supaya bisa diuji langsung tanpa bootstrap framework penuh -- lihat
 * tests/unit/KalkulasiStatusPembayaranTest.php. Mengikuti pola
 * App\Services\KalkulasiDiskonTransaksi.
 *
 * Business rule DIPERTAHANKAN persis seperti ekspresi lama:
 *
 *     $status = $dibayar >= $grandTotal
 *         ? 'lunas'
 *         : ($dibayar > 0 ? 'dp' : 'belum_bayar');
 *
 * Konsekuensi boundary yang memang sudah berlaku dan TIDAK diubah:
 * - grand_total == 0 (dibayar 0)  -> LUNAS (0 >= 0).
 * - dibayar == grand_total        -> LUNAS.
 * - dibayar > grand_total (lebih) -> LUNAS.
 * - dibayar negatif               -> BELUM_BAYAR (bukan > 0), kecuali
 *   grand_total juga <= dibayar (mis. grand_total negatif) -> LUNAS.
 * Nilai TIDAK di-clamp di sini supaya perilaku identik dengan lama.
 */
class KalkulasiStatusPembayaran
{
    public const LUNAS       = 'lunas';
    public const DP          = 'dp';
    public const BELUM_BAYAR = 'belum_bayar';

    public static function hitung(float $totalDibayar, float $grandTotal): string
    {
        if ($totalDibayar >= $grandTotal) {
            return self::LUNAS;
        }

        return $totalDibayar > 0 ? self::DP : self::BELUM_BAYAR;
    }
}
