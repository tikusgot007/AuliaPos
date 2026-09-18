<?php

namespace App\Services;

use App\Models\UserModel;

/**
 * Seam otorisasi tipis. Hanya dua pertanyaan -- TIDAK ada
 * hasAuthority()/capability registry/permission matrix/role
 * hierarchy engine generik (locked keputusan Tahap 2/3: belum ada
 * business rule soal capability apa saja yang diberikan Shift Leader,
 * jadi tidak dibuat sekarang -- over-engineering yang tidak diminta).
 *
 * users.role tidak pernah dibandingkan dengan 'shift_leader' di sini
 * atau di mana pun -- tetap persis enum('admin','kasir').
 *
 * Ini adalah fondasi seam yang nanti dapat dipakai v3.0 Chat (lihat
 * docs/aturan-bisnis-USER-SHIFT.md, branch v3.0) tanpa Chat perlu
 * tahu apa pun soal jadwal/shift/priority.
 */
final class Authority
{
    public static function isAdmin(int $userId): bool
    {
        $user = (new UserModel())->find($userId);

        return ($user['role'] ?? null) === 'admin';
    }

    /**
     * Dibungkus try/catch dengan sengaja (Tahap 6 -- ditemukan lewat
     * audit branch v2.1): fungsi ini dipanggil dari titik-titik yang
     * dipakai SEMUA transaksi/pembayaran (Api::ubahStatus(),
     * Api::tambahPembayaran(), Tagihan::lunasi()), bukan cuma jalur
     * khusus Shift Leader. Kalau skema `users.priority`/`jadwal` belum
     * ada (migration belum dijalankan di DB yang dipakai -- kondisi
     * yang sama persis menyebabkan layout crash sebelum Tahap 4.1),
     * query di EffectiveShiftLeaderService melempar exception --
     * TANPA guard ini, exception itu akan menjatuhkan seluruh operasi
     * PEMANGGIL (termasuk untuk ADMIN yang sama sekali tidak butuh
     * jawaban "siapa Shift Leader"), bukan cuma menolak Shift Leader.
     * Gagal menghitung -> anggap bukan Shift Leader (fail closed, sama
     * prinsipnya dengan degradasi badge/endpoint info), BUKAN
     * melempar ke atas. Error sesungguhnya tetap di-log.
     */
    public static function isCurrentShiftLeader(int $userId, ?string $tanggal = null, ?string $jamSekarang = null): bool
    {
        try {
            $leader = (new EffectiveShiftLeaderService())->shiftLeaderSaatIni($tanggal, $jamSekarang);
        } catch (\Throwable $e) {
            log_message('error', 'Gagal hitung Shift Leader saat ini (Authority): ' . $e->getMessage());

            return false;
        }

        return $leader !== null && $leader['id'] === $userId;
    }
}
