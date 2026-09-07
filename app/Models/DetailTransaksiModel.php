<?php

namespace App\Models;

use CodeIgniter\Model;

class DetailTransaksiModel extends Model
{
    protected $table = 'detail_transaksi';
    protected $primaryKey = 'id';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'transaksi_id',
        'produk_id',
        'nama_produk',
        'kategori_id',
        'jumlah',
        'harga_satuan',
        'subtotal',
        'catatan',
    ];

    public function getDetailByTransaksi($transaksi_id)
    {
        return $this->where('transaksi_id', $transaksi_id)
            ->orderBy('id', 'ASC')
            ->findAll();
    }

    public function hapusDetailByTransaksi($transaksi_id)
    {
        return $this->where('transaksi_id', $transaksi_id)->delete();
    }
}
