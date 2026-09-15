<?php

namespace App\Services;

use App\Models\UserModel;

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
