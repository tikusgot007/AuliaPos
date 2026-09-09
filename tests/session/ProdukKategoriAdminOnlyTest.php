<?php

use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Master data Produk & Kategori hanya boleh diakses admin.
 * Yang diuji: proteksi backend lewat AuthFilter (bukan sekadar menu sidebar),
 * termasuk akses langsung ke URL CRUD / endpoint mutasi.
 *
 * @internal
 */
final class ProdukKategoriAdminOnlyTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private function sessionFor(string $role): array
    {
        return [
            'isLoggedIn'    => true,
            'role'          => $role,
            'nama'          => 'Test ' . $role,
            'last_activity' => time(),
        ];
    }

    /**
     * Semua URL halaman + endpoint mutasi produk & kategori yang harus ditolak
     * untuk non-admin. Filter menahan request sebelum controller jalan, jadi
     * pemanggilan GET sudah cukup membuktikan tidak ada bypass via direct URL.
     */
    public static function protectedUrls(): array
    {
        return [
            'GET produk index'          => ['get', 'produk'],
            'GET produk tambah'         => ['get', 'produk/tambah'],
            'GET produk edit'           => ['get', 'produk/edit/1'],
            'GET produk hapus'          => ['get', 'produk/hapus/1'],
            'GET produk datatables'     => ['get', 'produk/get-produk-data'],
            'GET produk maintenance'    => ['get', 'produk/maintenance'],
            'GET produk export-audit'   => ['get', 'produk/export-audit'],
            'POST produk simpan'        => ['post', 'produk/simpan'],
            'POST produk update'        => ['post', 'produk/update/1'],
            'POST produk update-inline' => ['post', 'produk/update-inline'],
            'POST produk preview-import' => ['post', 'produk/preview-import'],
            'POST produk eksekusi-import' => ['post', 'produk/eksekusi-import'],
            'GET kategori index'        => ['get', 'kategori'],
            'GET kategori tambah'       => ['get', 'kategori/tambah'],
            'GET kategori edit'         => ['get', 'kategori/edit/1'],
            'GET kategori hapus'        => ['get', 'kategori/hapus/1'],
            'POST kategori simpan'      => ['post', 'kategori/simpan'],
            'POST kategori update'      => ['post', 'kategori/update/1'],
        ];
    }

    /**
     * @dataProvider protectedUrls
     */
    public function testNonAdminAksesLangsungDitolak(string $method, string $url): void
    {
        $result = $this->withSession($this->sessionFor('kasir'))->{$method}($url);

        $result->assertRedirectTo('/kasir');
    }

    /**
     * Guest (belum login) juga tetap ditolak (dilempar ke /login).
     */
    public function testGuestDitolak(): void
    {
        $this->get('produk')->assertRedirectTo('/login');
        $this->get('kategori/hapus/1')->assertRedirectTo('/login');
    }

    /**
     * Cabang "admin diizinkan": AuthFilter tidak mengembalikan redirect untuk
     * role admin pada URI produk/kategori. Diuji di level filter supaya tidak
     * bergantung pada skema DB test.
     */
    public function testAdminLolosFilter(): void
    {
        foreach (['produk', 'produk/tambah', 'produk/hapus/9', 'kategori', 'kategori/simpan'] as $path) {
            $this->assertNull($this->runAuthFilter($path, 'admin'), "admin harus lolos untuk: {$path}");
        }
    }

    public function testNonAdminDitahanFilter(): void
    {
        foreach (['produk', 'produk/simpan', 'kategori', 'kategori/update/3'] as $path) {
            $hasil = $this->runAuthFilter($path, 'kasir');
            $this->assertInstanceOf(\CodeIgniter\HTTP\RedirectResponse::class, $hasil, "non-admin harus ditahan untuk: {$path}");
        }
    }

    /**
     * Flow kasir tidak ikut terbatas: URI kasir lolos filter untuk non-admin.
     */
    public function testKasirTidakTerdampak(): void
    {
        $this->assertNull($this->runAuthFilter('kasir', 'kasir'));
        $this->assertNull($this->runAuthFilter('', 'kasir'));
    }

    private function runAuthFilter(string $path, string $role)
    {
        Services::reset(false);
        Services::injectMock('uri', new SiteURI(config('App'), $path));

        $session = session();
        $session->set($this->sessionFor($role));

        $filter = new \App\Filters\AuthFilter();

        return $filter->before(Services::request());
    }
}
