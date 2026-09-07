<?php

namespace App\Models;

use CodeIgniter\Model;

class CashOpnameModel extends Model
{
    protected $table         = 'cash_opname';
    protected $primaryKey    = 'id';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'tanggal',
        'saldo_awal_hari',
        'pemasukan_tunai',
        'pengeluaran_tunai',
        'saldo_sistem',
        'saldo_fisik',
        'selisih',
        'status_selisih',
        'alasan_selisih',
        'catatan',
        'user_id',
    ];

    /**
     * Mendapatkan opname terakhir untuk hari ini
     */
    public function getLastOpnameToday($tanggal = null)
    {
        if ($tanggal === null) {
            $tanggal = date('Y-m-d');
        }

        return $this->where('tanggal >=', $tanggal . ' 00:00:00')
            ->where('tanggal <=', $tanggal . ' 23:59:59')
            ->orderBy('tanggal', 'DESC')
            ->first();
    }

    /**
     * Mendapatkan semua opname untuk hari tertentu
     */
    public function getOpnamesByDate($tanggal)
    {
        return $this->where('tanggal >=', $tanggal . ' 00:00:00')
            ->where('tanggal <=', $tanggal . ' 23:59:59')
            ->orderBy('tanggal', 'ASC')
            ->findAll();
    }

    /**
     * Mendapatkan riwayat opname dengan filter
     */
    public function getRiwayat($limit = 50, $offset = 0, $tanggalAwal = null, $tanggalAkhir = null)
    {
        $builder = $this->select('cash_opname.*, users.username as user_nama')
            ->join('users', 'users.id = cash_opname.user_id', 'left')
            ->orderBy('tanggal', 'DESC');

        if ($tanggalAwal) {
            $builder->where('tanggal >=', $tanggalAwal . ' 00:00:00');
        }

        if ($tanggalAkhir) {
            $builder->where('tanggal <=', $tanggalAkhir . ' 23:59:59');
        }

        return $builder->findAll($limit, $offset);
    }

    /**
     * Menghitung selisih dan status selisih
     */
    public function hitungSelisih($saldoSistem, $saldoFisik)
    {
        $selisih = $saldoFisik - $saldoSistem;

        if (abs($selisih) < 0.01) {
            $status = 'sesuai';
        } elseif ($selisih < 0) {
            $status = 'kurang';
        } else {
            $status = 'lebih';
        }

        return [
            'selisih' => $selisih,
            'status' => $status,
        ];
    }

    /**
     * Simpan opname dengan validasi
     */
    public function simpanOpname($data)
    {
        // Hitung selisih
        $saldoSistem = (float) ($data['saldo_sistem'] ?? 0);
        $saldoFisik = (float) ($data['saldo_fisik'] ?? 0);
        $selisihData = $this->hitungSelisih($saldoSistem, $saldoFisik);

        $data['selisih'] = $selisihData['selisih'];
        $data['status_selisih'] = $selisihData['status'];

        // Pastikan user_id terisi
        if (empty($data['user_id'])) {
            $data['user_id'] = session()->get('id_user') ?? 1;
        }

        return $this->insert($data);
    }
}
