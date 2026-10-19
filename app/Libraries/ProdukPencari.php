<?php

namespace App\Libraries;

use App\Models\ProdukModel;

/**
 * Pencarian produk untuk saran AI (desain §10): kata cari dari AI -> baris
 * produk dari tabel `produk`. Hanya baca. Harga/ID SELALU dari master produk,
 * tidak pernah dari output AI.
 */
class ProdukPencari
{
    public const KATA_MAX   = 5;
    public const HASIL_MAX  = 30;
    public const PANJANG_MIN = 2;
    public const PANJANG_MAX = 40;

    /** Kata cari dari AI -> bersih, unik, maksimal KATA_MAX. Murni (tanpa DB). */
    public static function bersihkanKata($mentah): array
    {
        if (!is_array($mentah)) {
            return [];
        }
        $hasil = [];
        foreach ($mentah as $k) {
            if (!is_string($k)) {
                continue;
            }
            $k = trim(str_replace(['%', '_', '\\'], '', $k));
            $n = mb_strlen($k);
            if ($n < self::PANJANG_MIN || $n > self::PANJANG_MAX) {
                continue;
            }
            $hasil[mb_strtolower($k)] ??= $k;
            if (count($hasil) >= self::KATA_MAX) {
                break;
            }
        }

        return array_values($hasil);
    }

    /**
     * Produk aktif, tidak terkunci, berharga yang namanya memuat salah satu kata.
     * Nama pendek lebih dulu (produk utama sebelum varian/frame).
     *
     * @return array<int, array{id:int, nama:string, harga:int}>
     */
    public function cari(array $kata): array
    {
        $kata = self::bersihkanKata($kata);
        if ($kata === []) {
            return [];
        }

        $q = $this->dasar()->groupStart();
        foreach ($kata as $k) {
            $q->orLike('nama', $k); // like() meng-escape % dan _ sendiri
        }
        $rows = $q->groupEnd()
            ->orderBy('LENGTH(nama)', 'ASC', false)->orderBy('id', 'ASC')
            ->findAll(self::HASIL_MAX);

        // LIKE %4R% juga menjaring "24R"; buang yang kata cari-nya menempel di tengah kata.
        $pola = '/(?<![0-9A-Za-z])(?:' . implode('|', array_map(static fn ($k) => preg_quote($k, '/'), $kata)) . ')/iu';

        return $this->format(array_filter($rows, static fn (array $r) => preg_match($pola, (string) $r['nama']) === 1));
    }

    /**
     * @param int[] $ids
     * @return array<int, array{id:int, nama:string, harga:int}>
     */
    public function byIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        if ($ids === []) {
            return [];
        }

        return $this->format($this->dasar()->whereIn('id', $ids)->orderBy('id', 'ASC')->findAll());
    }

    /**
     * ID yang sah dipakai di keranjang (aktif, tidak terkunci, harga > 0).
     *
     * @param int[] $ids
     * @return int[]
     */
    public function valid(array $ids): array
    {
        return array_map(static fn (array $r) => $r['id'], $this->byIds($ids));
    }

    private function dasar(): ProdukModel
    {
        return (new ProdukModel())
            ->select('id, nama, harga_jual')
            ->where('is_active', 1)
            ->where('is_locked', 0)
            ->where('harga_jual >', 0);
    }

    private function format(array $rows): array
    {
        return array_map(static fn (array $r) => [
            'id'    => (int) $r['id'],
            'nama'  => (string) $r['nama'],
            'harga' => (int) round((float) $r['harga_jual']),
        ], $rows);
    }
}
