<?php

namespace App\Services;

/**
 * Kalkulasi diskon & grand_total transaksi -- satu sumber kebenaran
 * untuk perhitungan yang SEBELUMNYA terduplikasi hampir persis antara
 * Api::simpanTransaksi() dan Transaksi::updateTransaksi().
 *
 * Sengaja pure/stateless (tidak menyentuh DB atau session) supaya bisa
 * diuji langsung tanpa bootstrap framework penuh -- lihat
 * tests/unit/KalkulasiDiskonTransaksiTest.php.
 *
 * Dua mode diskon, TIDAK PERNAH digabung (mencegah double discount):
 *
 * 1. Diskon Pelanggan aktif ($persenPelanggan tidak null): nilai Rp
 *    dihitung dari subtotal * persen / 100 DI SINI (server), bukan
 *    dipercaya mentah-mentah dari browser -- pemanggil WAJIB mengambil
 *    $persenPelanggan dari `pelanggan.diskon` di database saat itu
 *    juga (fresh read), bukan dari input request, supaya tidak bisa
 *    dipalsukan lewat request tanpa mengubah data pelanggan.diskon
 *    sama sekali (read-only terhadap master).
 * 2. Diskon manual ($persenPelanggan null): perilaku persis seperti
 *    mekanisme existing sebelum fitur ini ada -- $diskonManual (Rp
 *    nominal, sudah dikonversi dari % atau Rp di frontend) dipakai
 *    apa adanya, hanya dibatasi ke rentang [0, subtotal].
 */
class KalkulasiDiskonTransaksi
{
    /**
     * @return array{
     *   diskon: float,
     *   diskon_pelanggan_persen: float|null,
     *   grand_total: float,
     *   selisih_pembulatan: float
     * }
     */
    public static function hitung(
        float $subtotal,
        ?float $persenPelanggan,
        float $diskonManual
    ): array {
        $subtotal = max(0, $subtotal);

        if ($persenPelanggan !== null) {
            // Jaga-jaga nilai master di luar rentang wajar (harusnya
            // tidak pernah terjadi kalau validasi form pelanggan
            // benar, tapi kalkulasi ini tidak boleh ikut rusak kalau
            // suatu saat ada data lama yang aneh).
            $persenPelanggan = max(0.0, min(100.0, $persenPelanggan));
            $diskon = round($subtotal * $persenPelanggan / 100);
        } else {
            $diskon = round(max(0, $diskonManual));
        }

        // Diskon tidak pernah melebihi subtotal, apa pun sumbernya.
        $diskon = min($diskon, $subtotal);

        $grandTotalSebelumPembulatan = $subtotal - $diskon;
        $grandTotal = floor($grandTotalSebelumPembulatan / 100) * 100;
        $selisihPembulatan = $grandTotalSebelumPembulatan - $grandTotal;

        return [
            'diskon'                  => (float) $diskon,
            'diskon_pelanggan_persen' => $persenPelanggan,
            'grand_total'             => (float) $grandTotal,
            'selisih_pembulatan'      => (float) $selisihPembulatan,
        ];
    }
}
