<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * MasterJadwalModel — TEMPLATE mingguan (bukan actual schedule).
 *
 * Master hidup independen dari jadwal aktual (tabel jadwal):
 * - Apply master -> membuat/mengisi row di tabel jadwal.
 * - Edit master SETELAHNYA tidak mengubah jadwal yang sudah dibuat
 *   dari apply sebelumnya (Section 26).
 * - Edit actual jadwal tidak pernah mengubah master.
 */
class MasterJadwalModel extends Model
{
    protected $table         = 'master_jadwal';
    protected $primaryKey    = 'id';
    protected $allowedFields = ['nama'];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Ambil header + seluruh detail (karyawan x hari x shift) satu
     * master, di-index [karyawan_id][hari] => shift untuk gampang
     * dipetakan ke Matrix.
     */
    public function getDetailMaster(int $masterId): array
    {
        $header = $this->find($masterId);

        if (!$header) {
            return ['header' => null, 'detail' => []];
        }

        $rows = $this->db->table('master_jadwal_detail')
            ->select('master_jadwal_detail.*, users.nama, users.inisial, users.divisi')
            ->join('users', 'users.id = master_jadwal_detail.karyawan_id')
            ->where('master_jadwal_id', $masterId)
            ->orderBy('users.nama', 'ASC')
            ->orderBy('master_jadwal_detail.hari', 'ASC')
            ->get()
            ->getResultArray();

        $detail = [];

        foreach ($rows as $r) {
            $detail[$r['karyawan_id']]['info'] = [
                'nama'    => $r['nama'],
                'inisial' => $r['inisial'],
                'divisi'  => $r['divisi'],
            ];
            $detail[$r['karyawan_id']]['hari'][(int) $r['hari']] = $r['shift'];
        }

        return ['header' => $header, 'detail' => $detail];
    }

    /**
     * Set satu cell template (karyawan x hari). hari kosong (tidak
     * ada row) berarti "tidak ada assignment", BUKAN 'L' -- jadi
     * untuk mengosongkan cell, panggil hapusCell(), jangan simpan
     * shift kosong.
     */
    public function simpanCell(int $masterId, int $karyawanId, int $hari, string $shift): int
    {
        if (!in_array($shift, JadwalModel::SHIFT_VALID, true)) {
            throw new \InvalidArgumentException('Shift tidak valid: ' . $shift);
        }

        $existing = $this->db->table('master_jadwal_detail')
            ->where('master_jadwal_id', $masterId)
            ->where('karyawan_id', $karyawanId)
            ->where('hari', $hari)
            ->get()
            ->getRowArray();

        if ($existing) {
            $this->db->table('master_jadwal_detail')
                ->where('id', $existing['id'])
                ->update(['shift' => $shift]);

            return (int) $existing['id'];
        }

        $this->db->table('master_jadwal_detail')->insert([
            'master_jadwal_id' => $masterId,
            'karyawan_id'      => $karyawanId,
            'hari'             => $hari,
            'shift'            => $shift,
        ]);

        return (int) $this->db->insertID();
    }

    public function hapusCell(int $masterId, int $karyawanId, int $hari): bool
    {
        return (bool) $this->db->table('master_jadwal_detail')
            ->where('master_jadwal_id', $masterId)
            ->where('karyawan_id', $karyawanId)
            ->where('hari', $hari)
            ->delete();
    }

    /**
     * Terapkan master ke N minggu ke depan mulai dari tanggal Senin
     * tertentu.
     *
     * Default behavior (Section 24): HANYA isi slot yang kosong.
     * Slot yang sudah punya actual schedule TIDAK ditimpa -- malah
     * dikumpulkan sebagai conflict untuk dilaporkan ke admin.
     *
     * $overwrite = true (Section 25) -> actual schedule yang sudah
     * ada boleh ditimpa. Ini harus berupa aksi eksplisit dari
     * controller (misal checkbox terpisah), tidak pernah default.
     *
     * Karyawan NON-AKTIF (users.is_active = 0) tidak dijadwalkan: sel-selnya
     * dilewati dan dilaporkan terpisah dari conflict (TODO-BL21), sama
     * seperti picker jadwal manual yang hanya menawarkan karyawan aktif.
     *
     * Seluruh apply berjalan dalam SATU transaksi DB: gagal di tengah
     * berarti tidak ada jadwal yang tersimpan (rollback) dan exception
     * dilempar -- tidak pernah hasil sebagian.
     *
     * @return array{diisi:int, dilewati:int, ditimpa:int, conflict:array, dilewati_nonaktif:int, nonaktif:array}
     *
     * @throws \Throwable bila penyimpanan salah satu sel gagal (semua dibatalkan).
     */
    public function applyMaster(
        int $masterId,
        string $startMinggu,
        int $jumlahMinggu,
        bool $overwrite = false
    ): array {
        $jadwalModel = model(JadwalModel::class);

        [, $detail] = array_values($this->getDetailMaster($masterId));

        $hasil = [
            'diisi'             => 0,
            'dilewati'          => 0,
            'ditimpa'           => 0,
            'conflict'          => [],
            'dilewati_nonaktif' => 0,
            'nonaktif'          => [],
        ];

        if (empty($detail)) {
            return $hasil;
        }

        $nonaktifIds = $this->idKaryawanNonaktif(array_keys($detail));
        $startTimestamp = strtotime($startMinggu);

        $this->db->transStart();

        try {
            for ($minggu = 0; $minggu < $jumlahMinggu; $minggu++) {
                foreach ($detail as $karyawanId => $data) {
                    if (isset($nonaktifIds[(int) $karyawanId])) {
                        $hasil['dilewati_nonaktif'] += count($data['hari']);

                        if ($minggu === 0) {
                            $hasil['nonaktif'][] = [
                                'karyawan_id' => (int) $karyawanId,
                                'nama'        => $data['info']['nama'],
                            ];
                        }

                        continue;
                    }

                    foreach ($data['hari'] as $hari => $shift) {
                        // hari 1 (Senin) = offset 0 dari startMinggu.
                        $offsetHari = ($minggu * 7) + ($hari - 1);
                        $tanggal = date('Y-m-d', strtotime("+{$offsetHari} days", $startTimestamp));

                        $existing = $jadwalModel
                            ->where('karyawan_id', $karyawanId)
                            ->where('tanggal', $tanggal)
                            ->first();

                        if (!$existing) {
                            $this->simpanAtauGagal($jadwalModel, (int) $karyawanId, $tanggal, $shift);
                            $hasil['diisi']++;

                            continue;
                        }

                        if ($existing['shift'] === $shift) {
                            // Sudah sama persis, tidak perlu dianggap conflict.
                            continue;
                        }

                        if ($overwrite) {
                            $this->simpanAtauGagal($jadwalModel, (int) $karyawanId, $tanggal, $shift);
                            $hasil['ditimpa']++;

                            continue;
                        }

                        $hasil['dilewati']++;
                        $hasil['conflict'][] = [
                            'karyawan_id'    => (int) $karyawanId,
                            'nama'           => $data['info']['nama'],
                            'tanggal'        => $tanggal,
                            'master_shift'   => $shift,
                            'existing_shift' => $existing['shift'],
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        $this->db->transComplete();

        if (!$this->db->transStatus()) {
            throw new \RuntimeException('Gagal menerapkan master jadwal.');
        }

        return $hasil;
    }

    /**
     * Simpan satu sel jadwal dan pastikan benar-benar tersimpan. Dengan
     * DBDebug=false (produksi) insert yang gagal mengembalikan id 0 tanpa
     * exception -- tanpa cek ini hitungan `diisi` salah dan transaksi
     * tetap ter-commit sebagian.
     */
    private function simpanAtauGagal(JadwalModel $jadwalModel, int $karyawanId, string $tanggal, string $shift): void
    {
        if ($jadwalModel->simpanJadwal($karyawanId, $tanggal, $shift) <= 0) {
            throw new \RuntimeException('Gagal menyimpan jadwal ' . $tanggal . ' (karyawan #' . $karyawanId . ').');
        }
    }

    /**
     * Set karyawan_id (kunci array) yang NON-AKTIF dari daftar yang diberikan.
     *
     * @param array<int, int|string> $karyawanIds
     *
     * @return array<int, true>
     */
    private function idKaryawanNonaktif(array $karyawanIds): array
    {
        if ($karyawanIds === []) {
            return [];
        }

        $rows = $this->db->table('users')
            ->select('id')
            ->whereIn('id', $karyawanIds)
            ->where('is_active', 0)
            ->get()
            ->getResultArray();

        $set = [];

        foreach ($rows as $r) {
            $set[(int) $r['id']] = true;
        }

        return $set;
    }
}
