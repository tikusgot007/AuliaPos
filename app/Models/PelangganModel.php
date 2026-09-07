<?php

namespace App\Models;

use CodeIgniter\Model;

class PelangganModel extends Model
{
    protected $table            = 'pelanggan';
    protected $primaryKey       = 'id';
    protected $allowedFields    = ['nama', 'no_hp', 'alamat', 'diskon'];
    protected $useTimestamps    = false;

    public function searchPelanggan(string $keyword): array
    {
        return $this->like('nama', $keyword)
            ->orLike('no_hp', $keyword)
            ->limit(10)
            ->findAll();
    }

    public function findByName(string $nama): ?array
    {
        return $this->where('nama', trim($nama))->first();
    }
}
