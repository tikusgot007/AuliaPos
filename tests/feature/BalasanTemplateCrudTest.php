<?php

use App\Libraries\BalasanTemplateImageService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FakeUploadedFile;

/**
 * Admin CRUD untuk Template Balasan Cepat (AC-1, AC-2, AC-6, AC-7 of
 * docs/requirements/2026-10-03-template-balasan-cepat.md), plus regresi
 * untuk bug route gambar (lihat testKasirCanFetchTemplateImage... di bawah):
 * `GET /foto-template/(:any)` sebelumnya terdaftar di bawah prefix
 * `balasan-template` yang masuk AuthFilter::$adminRoutes, jadi kasir
 * di-redirect ke /kasir alih-alih menerima gambarnya (memblokir AC-9).
 *
 * Runs against `aulia_inboxdb_test` (see tests/_support/bootstrap-feature.php),
 * same as the other feature tests. Not yet run in this session -- no
 * test database is reachable here (see docs/TODO.md, TODO-U2).
 *
 * @internal
 */
final class BalasanTemplateCrudTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private $inbox;
    private BalasanTemplateImageService $imageService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inbox = db_connect('inbox');
        $this->inbox->table('balasan_template')->emptyTable();

        $this->imageService = new BalasanTemplateImageService();
        $this->bersihkanDirGambar();
    }

    protected function tearDown(): void
    {
        $this->bersihkanDirGambar();
        parent::tearDown();
    }

    private function bersihkanDirGambar(): void
    {
        foreach (glob($this->imageService->getDir() . '/*') ?: [] as $f) {
            @unlink($f);
        }
    }

    /** 1x1 PNG valid asli, sama seperti BalasanTemplateImageServiceTest -- simpan file NYATA ke disk lewat service, bukan menulis file manual, supaya nama server-generated & validasi MIME-nya benar-benar diuji. */
    private function simpanGambarNyata(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.png';
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
        ));
        $upload = new FakeUploadedFile($path, 'upload.png', mime_content_type($path), filesize($path), UPLOAD_ERR_OK);

        $hasil = $this->imageService->simpan($upload);
        $this->assertTrue($hasil['success'], $hasil['error'] ?? 'gagal menyiapkan fixture gambar');

        return $hasil['filename'];
    }

    private function asAdmin()
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => 1, 'role' => 'admin']);
    }

    private function asKasir()
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => 7, 'role' => 'kasir']);
    }

    public function testAdminCanCreateEditAndDeleteATextOnlyTemplate(): void
    {
        $create = $this->asAdmin()->post('/balasan-template/simpan', [
            'nama' => 'Jam Buka',
            'teks' => 'Kami buka 08:00-20:00 setiap hari.',
        ]);
        $create->assertRedirectTo('/balasan-template');

        $row = $this->inbox->table('balasan_template')->where('nama', 'Jam Buka')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('Kami buka 08:00-20:00 setiap hari.', $row['teks']);

        $update = $this->asAdmin()->post('/balasan-template/update/' . $row['id'], [
            'nama' => 'Jam Buka',
            'teks' => 'Kami buka 08:00-21:00 setiap hari.',
        ]);
        $update->assertRedirectTo('/balasan-template');

        $updated = $this->inbox->table('balasan_template')->where('id', $row['id'])->get()->getRowArray();
        $this->assertSame('Kami buka 08:00-21:00 setiap hari.', $updated['teks']);

        $delete = $this->asAdmin()->get('/balasan-template/hapus/' . $row['id']);
        $delete->assertRedirectTo('/balasan-template');

        $gone = $this->inbox->table('balasan_template')->where('id', $row['id'])->get()->getRowArray();
        $this->assertNull($gone);
    }

    public function testCreateIsRejectedWhenBothTeksAndGambarAreEmpty(): void
    {
        $response = $this->asAdmin()->post('/balasan-template/simpan', ['nama' => 'Kosong']);

        $response->assertRedirect();
        $this->assertSame(0, $this->inbox->table('balasan_template')->where('nama', 'Kosong')->countAllResults());
    }

    public function testIndexListsTemplatesSortedByNama(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->inbox->table('balasan_template')->insert(['nama' => 'Zebra', 'teks' => 'z', 'created_at' => $now, 'updated_at' => $now]);
        $this->inbox->table('balasan_template')->insert(['nama' => 'Ayam', 'teks' => 'a', 'created_at' => $now, 'updated_at' => $now]);

        $response = $this->asAdmin()->get('/balasan-template');

        $response->assertStatus(200);
        $body = $response->getBody();
        $this->assertTrue(strpos($body, 'Ayam') < strpos($body, 'Zebra'));
    }

    public function testKasirIsBlockedFromCrudRoutes(): void
    {
        $index = $this->asKasir()->get('/balasan-template');
        $index->assertRedirectTo('/kasir');

        $tambah = $this->asKasir()->get('/balasan-template/tambah');
        $tambah->assertRedirectTo('/kasir');

        $simpan = $this->asKasir()->post('/balasan-template/simpan', ['nama' => 'X', 'teks' => 'y']);
        $simpan->assertRedirectTo('/kasir');

        $this->assertSame(0, $this->inbox->table('balasan_template')->where('nama', 'X')->countAllResults());
    }

    /**
     * Regresi bug route: GET /foto-template/(:any) HARUS 200 untuk kasir,
     * BUKAN redirect 302 ke /kasir (AC-9 -- kasir memakai template
     * bergambar di composer Inbox). Prefix 'foto-template' sengaja beda
     * dari 'balasan-template' supaya tidak match AuthFilter::$adminRoutes.
     */
    public function testKasirCanFetchTemplateImageWithoutBeingRedirected(): void
    {
        $filename = $this->simpanGambarNyata();

        $response = $this->asKasir()->get('/foto-template/' . $filename);

        $response->assertStatus(200);
        $this->assertStringContainsString('image/png', $response->response()->getHeaderLine('Content-Type'));
        // Isinya harus benar-benar byte gambar, bukan HTML halaman /kasir
        // (itulah bug-nya: redirect diikuti fetch() lalu res.blob() membungkus HTML).
        $this->assertStringStartsWith("\x89PNG", $response->getBody());
    }

    public function testAdminCanAlsoFetchTemplateImage(): void
    {
        $filename = $this->simpanGambarNyata();

        $response = $this->asAdmin()->get('/foto-template/' . $filename);

        $response->assertStatus(200);
    }

    public function testUnknownTemplateImageFilenameIs404NotRedirect(): void
    {
        $response = $this->asKasir()->get('/foto-template/tidak-ada.png');

        $response->assertStatus(404);
    }
}
