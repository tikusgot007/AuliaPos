<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Effective Shift Leader: dihitung ON-DEMAND setiap kali dipanggil,
 * tidak pernah disimpan/di-cache (tidak ada current_leader
 * table/column apa pun) -- lihat docs/aturan-bisnis-AULIA.md &
 * cross-version rule di docs/aturan-bisnis-USER-SHIFT.md (branch
 * v3.0).
 *
 * Kandidat (LOCKED, Tahap 2/3):
 *  - u.role = 'kasir'        (BUKAN sekadar `role <> 'admin'` --
 *                              Admin tidak pernah kandidat apa pun
 *                              datanya, walau punya priority & jadwal)
 *  - u.is_active = 1
 *  - u.priority IS NOT NULL  (NULL = belum di-ranking, bukan kandidat)
 *  - punya row `jadwal` pada tanggal itu, shift != 'L'
 *
 * Di antara kandidat (urut priority DESC), yang PERTAMA sedang berada
 * dalam jendela jam kerjanya (EvaluasiJendelaKerjaShift, dibaca dari
 * JadwalModel::DEFINISI_SHIFT) adalah Leader. Overlap P/S/PM masuk
 * SATU pool gabungan -- hanya ada SATU Leader untuk seluruh
 * operasional, bukan satu per kode shift. Tidak ada fallback: kalau
 * tidak ada kandidat yang sedang bekerja -> null.
 *
 * "Sekarang" (tanggal & jam) SELALU dihitung server-side (timezone
 * aplikasi, Asia/Jakarta) kecuali dioverride eksplisit lewat
 * parameter (hanya untuk kebutuhan test) -- caller tidak boleh
 * mengirim waktu dari client sebagai authority input.
 */
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
                    'id'       => (int) $row['id'],
                    'username' => $row['username'],
                    'nama'     => $row['nama'],
                    'priority' => (int) $row['priority'],
                ];
            }
        }

        return null;
    }
}
