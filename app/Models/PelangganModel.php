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

    /**
     * Cari pelanggan dengan nama persis sama.
     *
     * `$exceptId` dipakai saat mengoreksi nama pelanggan yang sudah ada:
     * baris itu sendiri tidak boleh dianggap bentrok (lihat
     * Inbox::buatPesanan(), AC-2b).
     */
    /**
     * Pelanggan TANPA nomor HP dengan nama persis `$nama`. Kandidat "orang
     * yang sama" untuk ditautkan ke nomor baru; null bila tidak ada atau
     * ambigu (lebih dari satu).
     */
    public function findTanpaNomorByNama(string $nama): ?array
    {
        $nama = trim($nama);
        if ($nama === '') {
            return null;
        }

        $rows = $this->where('nama', $nama)
            ->groupStart()->where('no_hp', null)->orWhere('no_hp', '')->groupEnd()
            ->findAll(2);

        return count($rows) === 1 ? $rows[0] : null;
    }

    public function findByName(string $nama, ?int $exceptId = null): ?array
    {
        $builder = $this->where('nama', trim($nama));

        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }

        return $builder->first();
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
