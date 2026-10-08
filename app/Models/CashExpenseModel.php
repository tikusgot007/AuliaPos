<?php

namespace App\Models;

use CodeIgniter\Model;
use Config\Database;

class CashExpenseModel extends Model
{
    protected $table         = 'cash_expense';
    protected $primaryKey    = 'id';
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'tanggal',
        'kategori',
        'nominal',
        'keterangan',
        'penerima',
        'user_id',
    ];

    protected $validationRules = [
        'tanggal'   => 'required|valid_date',
        'kategori'  => 'required|in_list[kas_awal_hari,pengeluaran,refund_penjualan,penyesuaian]',
        'nominal'   => 'required|numeric|greater_than[0]',
        'keterangan' => 'permit_empty|max_length[255]',
        'penerima'  => 'permit_empty|max_length[150]',
        'user_id'   => 'required|numeric',
    ];

    /**
     * Mendapatkan total pengeluaran per kategori untuk hari ini
     */
    public function getSummaryToday($tanggal = null)
    {
        if ($tanggal === null) {
            $tanggal = date('Y-m-d');
        }

        return $this->select('kategori, SUM(nominal) AS total')
            ->where('tanggal >=', $tanggal . ' 00:00:00')
            ->where('tanggal <=', $tanggal . ' 23:59:59')
            ->where('kategori !=', 'kas_awal_hari')
            ->groupBy('kategori')
            ->findAll();
    }

    /**
     * Mendapatkan total pengeluaran (tanpa kas_awal_hari)
     */
    public function getTotalPengeluaran($tanggalAwal = null, $tanggalAkhir = null)
    {
        $builder = $this->select('COALESCE(SUM(nominal), 0) AS total')
            ->where('kategori !=', 'kas_awal_hari');

        if ($tanggalAwal) {
            $builder->where('tanggal >=', $tanggalAwal . ' 00:00:00');
        }
        if ($tanggalAkhir) {
            $builder->where('tanggal <=', $tanggalAkhir . ' 23:59:59');
        }

        $result = $builder->get()->getRowArray();
        return (float) ($result['total'] ?? 0);
    }

    /**
     * Mendapatkan semua pengeluaran dengan filter
     */
    public function getPengeluaran($limit = 50, $offset = 0, $tanggalAwal = null, $tanggalAkhir = null, $kategori = null)
    {
        $builder = $this->select('cash_expense.*, users.username as user_nama')
            ->join('users', 'users.id = cash_expense.user_id', 'left')
            ->where('kategori !=', 'kas_awal_hari')
            ->orderBy('tanggal', 'DESC');

        if ($tanggalAwal) {
            $builder->where('tanggal >=', $tanggalAwal . ' 00:00:00');
        }
        if ($tanggalAkhir) {
            $builder->where('tanggal <=', $tanggalAkhir . ' 23:59:59');
        }
        if ($kategori) {
            $builder->where('kategori', $kategori);
        }

        return $builder->findAll($limit, $offset);
    }

    /**
     * Menghitung total pengeluaran untuk hari ini (digunakan di dashboard)
     */
    public function getTotalPengeluaranHariIni()
    {
        return $this->getTotalPengeluaran(date('Y-m-d'), date('Y-m-d'));
    }

    /**
     * Total pengeluaran PER TANGGAL dalam rentang (exclude kas_awal_hari),
     * untuk Laporan Bulanan. Konsisten dengan getTotalPengeluaran() yang
     * sudah ada (exclude kategori sama), hanya di sini di-groupBy tanggal.
     *
     * @return array<string,float> key = 'Y-m-d', value = total nominal.
     */
    public function getPengeluaranPerTanggal($tanggalAwal, $tanggalAkhir): array
    {
        $rows = $this->select("DATE(tanggal) AS tgl, COALESCE(SUM(nominal), 0) AS total")
            ->where('kategori !=', 'kas_awal_hari')
            ->where('tanggal >=', $tanggalAwal . ' 00:00:00')
            ->where('tanggal <=', $tanggalAkhir . ' 23:59:59')
            ->groupBy('DATE(tanggal)')
            ->findAll();

        $hasil = [];

        foreach ($rows as $row) {
            $hasil[$row['tgl']] = (float) $row['total'];
        }

        return $hasil;
    }

    /**
     * Simpan pengeluaran baru
     */
    public function simpanPengeluaran($data)
    {
        $data['kategori'] = 'pengeluaran';
        $data['user_id'] = $data['user_id'] ?? session()->get('id_user') ?? 1;

        if (empty($data['tanggal'])) {
            $data['tanggal'] = date('Y-m-d H:i:s');
        }

        return $this->insert($data);
    }

    /**
     * Validasi tanggal pengeluaran: format valid, dan bukan masa depan
     * (TODO-BL10). Berbeda dari ClosingKasModel::validasiTanggal(), HARI INI
     * tetap diperbolehkan di sini -- hanya tanggal setelah hari ini yang
     * ditolak. Pure (tidak menyentuh DB) supaya bisa diuji tanpa bootstrap
     * penuh, mengikuti pola ClosingKasModel::validasiTanggal().
     *
     * Pengecekan "tanggal sudah di-closing" dilakukan terpisah oleh
     * pemanggil (lihat Cash::tambahPengeluaran/updatePengeluaran), karena
     * itu butuh akses DB (ClosingKasModel) dan bukan bagian dari validasi
     * format/rentang murni ini.
     */
    public static function validasiTanggal(?string $tanggal, ?string $hariIni = null): ?string
    {
        $hariIni ??= date('Y-m-d');

        if (empty($tanggal)) {
            return 'Tanggal tidak valid.';
        }

        $timestamp = strtotime($tanggal);
        if ($timestamp === false) {
            return 'Tanggal tidak valid.';
        }

        $tanggalSaja = date('Y-m-d', $timestamp);

        if ($tanggalSaja > $hariIni) {
            return 'Tanggal pengeluaran tidak boleh di masa depan.';
        }

        return null;
    }

    /**
     * Update pengeluaran. Menulis satu baris audit (before/after) sebelum
     * mengubah baris aslinya, dalam satu transaksi (TODO-BL10) -- lihat
     * docs/design/2026-10-08-audit-validasi-kas-keluar.md Section 4/5.
     * $userId WAJIB diisi oleh pemanggil (tidak ada fallback diam-diam),
     * supaya baris audit tidak pernah salah atribusi pelaku.
     */
    public function updatePengeluaran($id, $data, int $userId)
    {
        // 🔥 Pastikan data yang diupdate hanya field yang diizinkan
        $allowed = ['tanggal', 'nominal', 'keterangan', 'penerima', 'updated_at'];
        $filtered = array_intersect_key($data, array_flip($allowed));

        $filtered['updated_at'] = date('Y-m-d H:i:s');

        $db = Database::connect($this->DBGroup);
        $db->transStart();

        $sebelum = $this->find($id);
        $updated = $this->update($id, $filtered);

        if (!$updated) {
            // Validation failure returns false without a DB-level error, so
            // transStatus() alone would not catch it -- force a rollback
            // explicitly so no misleading audit row is written for a change
            // that never actually happened.
            $db->transRollback();

            return false;
        }

        $sesudah = $this->find($id);

        (new CashExpenseAuditModel())->catatUpdate($id, $sebelum, $sesudah, $userId);

        $db->transComplete();

        return $db->transStatus();
    }

    /**
     * Hapus pengeluaran. Menulis satu baris audit (before-only) sebelum
     * menghapus baris aslinya, dalam satu transaksi (TODO-BL10). $userId
     * WAJIB diisi oleh pemanggil -- lihat catatan di updatePengeluaran().
     */
    public function hapusPengeluaran($id, int $userId)
    {
        $db = Database::connect($this->DBGroup);
        $db->transStart();

        $sebelum = $this->find($id);

        if ($sebelum === null) {
            $db->transComplete();

            return false;
        }

        $deleted = $this->delete($id);

        if (!$deleted) {
            $db->transRollback();

            return false;
        }

        (new CashExpenseAuditModel())->catatHapus($id, $sebelum, $userId);

        $db->transComplete();

        return $db->transStatus();
    }
}
