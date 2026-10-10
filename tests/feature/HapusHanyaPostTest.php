<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * TODO-BL22: aksi yang mengubah/menghapus data tidak boleh bisa dipicu lewat GET
 * (cukup satu klik link, `<img src>`, atau prefetch dari halaman lain).
 *
 * Untuk tiap route: GET harus "route tidak ditemukan" (PageNotFoundException,
 * tampil sebagai 404 di produksi); POST harus sampai ke controller. Guard admin
 * pada user tetap berlaku untuk POST. Penghapusan nyata untuk template balasan
 * sudah dibuktikan di BalasanTemplateCrudTest.
 *
 * Pada tes GET, id yang dipakai tidak perlu ada: kalau route masih GET, controller
 * akan menjawab redirect (302) tanpa exception sehingga regresi tertangkap.
 *
 * @internal
 */
final class HapusHanyaPostTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    /** @return array<string, array{0:string}> */
    public static function routeMutasi(): array
    {
        return [
            'hapus user'            => ['/auth/hapus-user/999999'],
            'hapus kategori'        => ['/kategori/hapus/999999'],
            'hapus pelanggan'       => ['/pelanggan/hapus/999999'],
            'hapus template balasan' => ['/balasan-template/hapus/999999'],
            'batal transaksi'       => ['/transaksi/batal/999999'],
        ];
    }

    private function asAdmin()
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => 1, 'role' => 'admin']);
    }

    /**
     * @dataProvider routeMutasi
     */
    public function testGetTidakLagiMemicuAksi(string $url): void
    {
        // Route GET sudah tidak ada: framework melempar PageNotFoundException
        // (di produksi tampil sebagai halaman 404). Kalau route kembali GET,
        // controller akan menjawab redirect dan exception ini tidak muncul.
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);

        $this->asAdmin()->get($url);
    }

    /**
     * @dataProvider routeMutasi
     */
    public function testPostMasihSampaiKeController(string $url): void
    {
        try {
            $response = $this->asAdmin()->post($url);
        } catch (\Throwable $e) {
            // Controller dijangkau (mis. DB uji tidak punya tabelnya) -- artinya
            // route POST ada dan tidak 404.
            $this->assertStringNotContainsString('Can\'t find a route', $e->getMessage());

            return;
        }

        $this->assertNotSame(404, $response->response()->getStatusCode(), 'POST ' . $url . ' harus terdaftar');
    }

    public function testKasirTidakBisaHapusUserLewatPost(): void
    {
        $this->withSession(['isLoggedIn' => true, 'id_user' => 7, 'role' => 'kasir'])
            ->post('/auth/hapus-user/999999')
            ->assertRedirectTo('/kasir');
    }

    public function testNonLoginDiarahkanKeLogin(): void
    {
        $this->post('/kategori/hapus/1')->assertRedirectTo('/login');
    }
}
