<?php

use App\Models\ProdukModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Produk di halaman kasir harus urut nama A -> Z, bukan berdasarkan
 * popularitas (jumlah penjualan). Kasir::index() memakai
 * ProdukModel::getProdukAktif(); filter kategori & pencarian dilakukan
 * di browser terhadap array itu tanpa mengurutkan ulang, jadi cukup
 * membuktikan urutan dasarnya + bahwa filter mempertahankan urutan.
 *
 * @internal
 */
final class KasirProdukSortTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'Tests\Support';
    protected $migrate   = true;
    protected $refresh   = true;

    private const KATEGORI_MINUMAN = 5;
    private const KATEGORI_MAKANAN  = 3;

    protected function setUp(): void
    {
        parent::setUp();

        // Nama sengaja tidak alfabetis saat di-insert; popularitas sengaja
        // dibuat terbalik dari urutan nama (Zebra paling laris).
        $produk = [
            ['nama' => 'Zebra Coffee', 'kategori_id' => self::KATEGORI_MINUMAN, 'terjual' => 50],
            ['nama' => 'Americano',    'kategori_id' => self::KATEGORI_MINUMAN, 'terjual' => 1],
            ['nama' => 'Bakso',        'kategori_id' => self::KATEGORI_MAKANAN,  'terjual' => 40],
            ['nama' => 'Cappuccino',   'kategori_id' => self::KATEGORI_MINUMAN, 'terjual' => 10],
            ['nama' => 'Mie Kuah',     'kategori_id' => self::KATEGORI_MAKANAN,  'terjual' => 30],
            ['nama' => 'Mie Ayam',     'kategori_id' => self::KATEGORI_MAKANAN,  'terjual' => 5],
            ['nama' => 'Mie Goreng',   'kategori_id' => self::KATEGORI_MAKANAN,  'terjual' => 45],
            ['nama' => 'Nonaktif Item', 'kategori_id' => self::KATEGORI_MINUMAN, 'terjual' => 0, 'is_active' => 0],
        ];

        $db = db_connect();

        foreach ($produk as $p) {
            $db->table('produk')->insert([
                'nama'        => $p['nama'],
                'kategori_id' => $p['kategori_id'],
                'harga_jual'  => 10000,
                'is_active'   => $p['is_active'] ?? 1,
            ]);
            $id = $db->insertID();

            for ($i = 0; $i < $p['terjual']; $i++) {
                $db->table('detail_transaksi')->insert(['produk_id' => $id]);
            }
        }
    }

    private function namaProdukKasir(): array
    {
        return array_column((new ProdukModel())->getProdukAktif(), 'nama');
    }

    public function testSemuaProdukUrutNamaBukanPopularitas(): void
    {
        $nama = $this->namaProdukKasir();

        $this->assertSame(
            ['Americano', 'Bakso', 'Cappuccino', 'Mie Ayam', 'Mie Goreng', 'Mie Kuah', 'Zebra Coffee'],
            $nama
        );

        // Kalau masih pakai popularitas, Zebra Coffee akan di depan.
        $this->assertNotSame('Zebra Coffee', $nama[0]);
    }

    public function testProdukNonaktifTidakIkut(): void
    {
        $this->assertNotContains('Nonaktif Item', $this->namaProdukKasir());
    }

    public function testFilterKategoriTetapUrutNama(): void
    {
        // Replikasi predikat filter kategori di kasir-shared.js:
        // String(p.kategori_id) === kategori
        $minuman = array_values(array_filter(
            (new ProdukModel())->getProdukAktif(),
            fn ($p) => (string) $p['kategori_id'] === (string) self::KATEGORI_MINUMAN
        ));

        $this->assertSame(
            ['Americano', 'Cappuccino', 'Zebra Coffee'],
            array_column($minuman, 'nama')
        );
    }

    public function testSearchTetapUrutNama(): void
    {
        // Replikasi predikat search di kasir-shared.js:
        // nama.toLowerCase().includes(keyword)
        $keyword = 'mie';
        $hasil = array_values(array_filter(
            (new ProdukModel())->getProdukAktif(),
            fn ($p) => str_contains(strtolower($p['nama']), $keyword)
        ));

        $this->assertSame(
            ['Mie Ayam', 'Mie Goreng', 'Mie Kuah'],
            array_column($hasil, 'nama')
        );
    }

    public function testSearchGabungKategoriTetapUrutNama(): void
    {
        $keyword = 'mie';
        $hasil = array_values(array_filter(
            (new ProdukModel())->getProdukAktif(),
            fn ($p) => (string) $p['kategori_id'] === (string) self::KATEGORI_MAKANAN
                && str_contains(strtolower($p['nama']), $keyword)
        ));

        $this->assertSame(
            ['Mie Ayam', 'Mie Goreng', 'Mie Kuah'],
            array_column($hasil, 'nama')
        );
    }
}
