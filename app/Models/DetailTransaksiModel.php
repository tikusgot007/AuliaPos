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

    /**
     * Build the persisted `catatan` value for a cart row.
     *
     * Banner rows store their entered per-m2 price as JSON so it can be
     * restored exactly on edit; every other row keeps the existing value.
     */
    public static function catatanBanner(array $item): string
    {
        $isBanner = ($item['is_banner'] ?? false) === true;
        $hargaPerM2 = $item['detail']['harga_per_m2'] ?? null;

        if ($isBanner && is_numeric($hargaPerM2)) {
            return json_encode(['harga_per_m2' => (int) $hargaPerM2]);
        }

        return (string) ($item['catatan'] ?? '');
    }
}
