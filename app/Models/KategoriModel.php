<?php

namespace App\Models;

use CodeIgniter\Model;

class KategoriModel extends Model
{
    protected $table            = 'kategori';
    protected $primaryKey       = 'id';
    protected $allowedFields    = ['nama', 'parent_id'];
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
     // Fungsi untuk mengambil semua kategori induk (4 utama)
    public function getKategoriInduk()
    {
        return $this->where('parent_id IS NULL')->findAll();
    }
}
