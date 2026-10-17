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

    /**
     * Cari pelanggan yang nomornya sama persis dengan `$canonical` ("62xxx",
     * hasil PhoneNumber::normalize()). `no_hp` adalah teks bebas ("0812-3456",
     * "+62 812 ...", "(0812) 3456"), jadi SQL hanya menyaring kandidat yang
     * berakhiran sama setelah spasi/strip/plus/titik/kurung dibuang; kesamaan
     * sebenarnya dipastikan di PHP lewat PhoneNumber::normalize(). Saringan
     * akhiran saja tidak cukup ("1081234567890" juga berakhiran "81234567890").
     *
     * ponytail: REPLACE() membuat saringan ini scan penuh tabel pelanggan
     * (kecil untuk satu toko); upgrade path: kolom no_hp_normal ber-index.
     */
    public function findByNomor(string $canonical): ?array
    {
        if (preg_match('/^62\d{8,13}$/', $canonical) !== 1) {
            return null;
        }

        $bersih = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(no_hp, ' ', ''), '-', ''), '+', ''), '.', ''), '(', ''), ')', '')";

        // $canonical hanya digit (divalidasi di atas) dan tetap di-escape.
        $kandidat = $this->where($bersih . ' LIKE ' . $this->db->escape('%' . substr($canonical, 2)), null, false)
            ->orderBy('id', 'ASC')
            ->limit(20)
            ->findAll();

        foreach ($kandidat as $pelanggan) {
            if (\App\Libraries\PhoneNumber::normalize((string) ($pelanggan['no_hp'] ?? '')) === $canonical) {
                return $pelanggan;
            }
        }

        return null;
    }
}
