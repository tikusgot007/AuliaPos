<?php

namespace App\Models;

use CodeIgniter\Model;

class PembayaranModel extends Model
{
    protected $table            = 'pembayaran';
    protected $primaryKey       = 'id';
    protected $useTimestamps    = false;
    protected $allowedFields    = [
        'transaksi_id',
        'tanggal',
        'jumlah',
        'uang_diterima',
        'kembalian',
        'metode',
        'keterangan',
        'kasir_id',
        'status'
    ];

    // Fungsi untuk mengambil riwayat pembayaran efektif suatu transaksi
    public function getPembayaranByTransaksi($transaksi_id)
    {
        return $this->where('transaksi_id', $transaksi_id)
            ->where('status', 'aktif')
            ->orderBy('tanggal', 'ASC')
            ->findAll();
    }

    // Fungsi untuk menghitung total pembayaran efektif
    public function getTotalDibayar($transaksi_id): float
    {
        $result = $this->selectSum('jumlah')
            ->where('transaksi_id', $transaksi_id)
            ->where('status', 'aktif')
            ->first();

        if (!$result) {
            return 0.0;
        }

        return (float) ($result['jumlah'] ?? 0);
    }
}
