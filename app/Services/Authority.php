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

    public static function isCurrentShiftLeader(int $userId, ?string $tanggal = null, ?string $jamSekarang = null): bool
    {
        $leader = (new EffectiveShiftLeaderService())->shiftLeaderSaatIni($tanggal, $jamSekarang);

        return $leader !== null && $leader['id'] === $userId;
    }
}
