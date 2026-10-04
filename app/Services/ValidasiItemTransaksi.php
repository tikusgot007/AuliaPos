<?php

namespace App\Services;

/**
 * Validasi & normalisasi baris keranjang transaksi POS -- satu sumber
 * kebenaran untuk aturan jumlah/harga/subtotal (TODO-BL03), dipakai jalur
 * buat (Api::simpanTransaksi) dan edit (Transaksi::updateTransaksi).
 *
 * Aturan:
 * - `jumlah` > 0, <= JUMLAH_MAKS, angka valid.
 * - `harga` dan `subtotal` tidak boleh negatif.
 * - Item non-banner: `subtotal` WAJIB = `harga x jumlah` (toleransi 0.01).
 * - Item banner: `subtotal` (total masukan kasir) jadi acuan dan
 *   `harga` dihitung ulang `round(subtotal / jumlah)`.
 *
 * Sengaja pure/stateless supaya bisa diuji tanpa bootstrap framework penuh.
 */
final class ValidasiItemTransaksi
{
    public const JUMLAH_MAKS = 9999;

    /**
     * @param array<int, mixed> $keranjang
     *
     * @return array{items: array<int, array<string, mixed>>, subtotal: float, error: string|null}
     */
    public static function normalisasi(array $keranjang): array
    {
        $items = [];
        $subtotal = 0.0;

        foreach ($keranjang as $item) {
            if (!is_array($item)) {
                return self::gagal('Data item tidak valid.');
            }

            $jumlah = $item['jumlah'] ?? null;
            if (!is_numeric($jumlah) || !is_finite((float) $jumlah)) {
                return self::gagal('Jumlah item tidak valid.');
            }
            $jumlah = (float) $jumlah;

            if ($jumlah <= 0) {
                return self::gagal('Jumlah item harus lebih dari 0.');
            }
            if ($jumlah > self::JUMLAH_MAKS) {
                return self::gagal('Jumlah item maksimal ' . self::JUMLAH_MAKS . '.');
            }

            $harga = $item['harga'] ?? null;
            if (!is_numeric($harga) || !is_finite((float) $harga)) {
                return self::gagal('Harga item tidak valid.');
            }
            $harga = (float) $harga;

            if ($harga < 0) {
                return self::gagal('Harga item tidak boleh negatif.');
            }

            $subtotalKirim = $item['subtotal'] ?? null;
            if (!is_numeric($subtotalKirim) || !is_finite((float) $subtotalKirim)) {
                return self::gagal('Subtotal item tidak valid.');
            }
            $subtotalKirim = (float) $subtotalKirim;

            if ($subtotalKirim < 0) {
                return self::gagal('Subtotal item tidak boleh negatif.');
            }

            if (($item['is_banner'] ?? false) === true) {
                if ($subtotalKirim <= 0) {
                    return self::gagal('Subtotal item banner harus lebih dari 0.');
                }

                $harga = round($subtotalKirim / $jumlah);
                $lineSubtotal = $subtotalKirim;
            } else {
                $lineSubtotal = $harga * $jumlah;

                if (abs($subtotalKirim - $lineSubtotal) > 0.01) {
                    return self::gagal('Subtotal item tidak konsisten dengan harga x jumlah.');
                }
            }

            $item['jumlah'] = $jumlah;
            $item['harga'] = $harga;
            $item['subtotal'] = $lineSubtotal;

            $items[] = $item;
            $subtotal += $lineSubtotal;
        }

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'error' => null,
        ];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, subtotal: float, error: string}
     */
    private static function gagal(string $pesan): array
    {
        return [
            'items' => [],
            'subtotal' => 0.0,
            'error' => $pesan,
        ];
    }
}
