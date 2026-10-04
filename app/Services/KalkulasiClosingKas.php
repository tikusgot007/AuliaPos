<?php

namespace App\Services;

/**
 * Keputusan nilai `saldo_sistem` closing kas -- satu sumber kebenaran untuk
 * aturan "snapshot closing bersifat final" (TODO-BL02).
 *
 * Sengaja pure/stateless (tidak menyentuh DB, session, maupun request) supaya
 * bisa diuji tanpa bootstrap framework penuh, mengikuti pola
 * App\Services\KalkulasiStatusPembayaran dan KalkulasiDiskonTransaksi.
 */
final class KalkulasiClosingKas
{
    /**
     * Snapshot tersimpan SELALU menang (imutabel). Nilai hitung ulang hanya
     * dipakai saat belum ada snapshot (closing baru).
     *
     * $hitungUlang sengaja berupa callable supaya pemanggil tidak perlu
     * menghitung (dan menyentuh arsip) ketika snapshot sudah ada.
     *
     * @param float|null      $snapshotTersimpan Nilai `saldo_sistem` yang
     *                                           sudah tersimpan, atau null.
     * @param callable():float $hitungUlang      Hitungan live + arsip.
     */
    public static function saldoSistemFinal(?float $snapshotTersimpan, callable $hitungUlang): float
    {
        return $snapshotTersimpan ?? $hitungUlang();
    }
}
