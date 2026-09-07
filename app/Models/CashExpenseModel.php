<?php

namespace App\Models;

use CodeIgniter\Model;

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
     * Update pengeluaran
     */
    public function updatePengeluaran($id, $data)
    {
        // 🔥 Pastikan data yang diupdate hanya field yang diizinkan
        $allowed = ['tanggal', 'nominal', 'keterangan', 'penerima', 'updated_at'];
        $filtered = array_intersect_key($data, array_flip($allowed));

        $filtered['updated_at'] = date('Y-m-d H:i:s');

        return $this->update($id, $filtered);
    }

    /**
     * Hapus pengeluaran
     */
    public function hapusPengeluaran($id)
    {
        return $this->delete($id);
    }
}
