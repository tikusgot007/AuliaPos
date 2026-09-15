<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class EffectiveShiftLeaderService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array{id:int,username:string,nama:?string,priority:int}|null */
    public function shiftLeaderSaatIni(?string $tanggal = null, ?string $jamSekarang = null): ?array
    {
        $tanggal ??= date('Y-m-d');
        $jamSekarang ??= date('H:i');

        $kandidat = $this->db->table('jadwal j')
            ->select('u.id, u.username, u.nama, u.priority, j.shift')
            ->join('users u', 'u.id = j.karyawan_id')
            ->where('j.tanggal', $tanggal)
            ->where('j.shift !=', 'L')
            ->where('u.role', 'kasir')
            ->where('u.is_active', 1)
            ->where('u.priority IS NOT NULL', null, false)
            ->orderBy('u.priority', 'DESC')
            ->get()->getResultArray();

        foreach ($kandidat as $row) {
            if (EvaluasiJendelaKerjaShift::sedangBekerja($row['shift'], $jamSekarang)) {
                return [
                    'id' => (int) $row['id'],
                    'username' => $row['username'],
                    'nama' => $row['nama'],
                    'priority' => (int) $row['priority'],
                ];
            }
        }

        return null;
    }
}
