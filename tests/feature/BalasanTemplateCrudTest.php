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

        $this->siapkanTabelUsersDefaultGroup();
    }

    /**
     * testIndexListsTemplatesSortedByNama me-render layout/main.php penuh
     * (halaman admin biasa), dan layout itu query UserModel untuk foto
     * profil header (main.php:1111-1113). UserModel pakai defaultGroup --
     * di environment testing itu SQLite `:memory:` ('tests' group) yang
     * KOSONG (tidak ada migrasi tabel users di sana; semua test feature
     * lain di repo ini kebetulan hanya menguji endpoint JSON yang tidak
     * melewati layout, jadi belum pernah kena). Buat tabel users minimal
     * (id + profile_photo cukup untuk query itu) supaya test ini tidak
     * bergantung pada tabel yang tidak dimigrasikan di DB test default.
     */
    private function siapkanTabelUsersDefaultGroup(): void
    {
        $db = db_connect();
        // Prefix (`db_` di group 'tests') HARUS disertakan di DDL mentah:
        // query builder (.table('users')) menambahkan prefix otomatis, tapi
        // DDL string mentah tidak -- tanpa prefix, CREATE membuat tabel
        // 'users' sementara builder tetap mencari 'db_users'.
        $db->query('CREATE TABLE IF NOT EXISTS ' . $db->DBPrefix . 'users (id INTEGER PRIMARY KEY, username TEXT, profile_photo TEXT)');
        $db->table('users')->where('id', 1)->delete();
        $db->table('users')->insert(['id' => 1, 'username' => 'admin-test', 'profile_photo' => null]);
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
        //
        // PENTING: $response->getBody() BUKAN body mentah -- TestResponse
        // mewarisi getBody() dari DOMParser (lihat @mixin DOMParser di
        // TestResponse), yang memuat body lewat DOMDocument::loadHTML()
        // lalu saveHTML() ulang. Untuk body biner (PNG) ini MERUSAK isinya
        // jadi dibungkus "<!DOCTYPE...><html><body><p>...</p></body></html>"
        // (DOMDocument menginterpretasikan byte biner sebagai HTML
        // malformed) -- bukan bug di controller, murni salah API test.
        // Body mentah yang benar: $response->response()->getBody().
        $this->assertStringStartsWith("\x89PNG", $response->response()->getBody());
    }

    public function testAdminCanAlsoFetchTemplateImage(): void
    {
        $filename = $this->simpanGambarNyata();

        $response = $this->asAdmin()->get('/foto-template/' . $filename);

        $response->assertStatus(200);
    }

    /**
     * BalasanTemplate::foto() melempar PageNotFoundException untuk
     * filename yang tidak ada/tidak valid (pola sama dengan
     * Profil::foto()) -- CI4 normalnya menangkap exception ini jadi
     * response 404 (CodeIgniter::display404errors()), TAPI karena route
     * app ini TIDAK mendaftarkan 404 override
     * ($routes->set404Override() tanpa argumen di Routes.php:16),
     * display404errors() justru MELEMPAR ULANG PageNotFoundException yang
     * baru (CodeIgniter.php sekitar baris 1024) -- di request HTTP nyata,
     * exception ini tertangkap entrypoint top-level dan tetap tampil
     * sebagai halaman 404 ke user. FeatureTestTrait::call(), beda dengan
     * request nyata, TIDAK membungkus pelemparan ulang ini, sehingga
     * exception itu bocor sampai ke PHPUnit sebagai error test, bukan
     * TestResponse berstatus 404. Ini keterbatasan environment test di
     * repo ini (pre-existing, bukan regresi TODO-R1 -- pola yang identik
     * berlaku juga untuk Profil::foto(), yang kebetulan belum pernah
     * diuji feature test sebelumnya), jadi di sini exception itu sendiri
     * yang diperiksa, bukan assertStatus(404).
     */
    public function testUnknownTemplateImageFilenameThrowsPageNotFound(): void
    {
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);

        $this->asKasir()->get('/foto-template/tidak-ada.png');
    }

    /**
     * Regresi bug: is_unique[balasan_template.nama] TANPA prefix dbGroup
     * query ke defaultGroup (aulia_kasirdb), yang TIDAK punya tabel
     * balasan_template (tabel itu ada di database.inbox) -- menyebabkan
     * exception 500 "table doesn't exist" pada SETIAP submit form, bukan
     * hanya saat nama benar-benar duplikat. Memakai nama BARU (bukan
     * duplikat) di sini justru PALING membuktikan bug lama: sebelum fix,
     * request ini sudah meledak 500 sebelum sempat mengecek keunikan sama
     * sekali. Fix: is_unique[inbox.balasan_template.nama].
     */
    public function testSubmittingANewUniqueNameDoesNotCrash(): void
    {
        $response = $this->asAdmin()->post('/balasan-template/simpan', [
            'nama' => 'Nama Benar-Benar Baru',
            'teks' => 'isi',
        ]);

        $response->assertRedirectTo('/balasan-template');
        $this->assertSame(1, $this->inbox->table('balasan_template')->where('nama', 'Nama Benar-Benar Baru')->countAllResults());
    }

    /**
     * Keunikan nama HARUS tetap ditegakkan setelah fix dbGroup -- bukan
     * cuma "tidak crash", tapi benar-benar menolak nama yang sudah dipakai
     * dengan redirect + pesan validasi, bukan insert duplikat.
     */
    public function testCreateRejectsDuplicateNama(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->inbox->table('balasan_template')->insert([
            'nama' => 'Sudah Ada', 'teks' => 'lama', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $response = $this->asAdmin()->post('/balasan-template/simpan', [
            'nama' => 'Sudah Ada',
            'teks' => 'baru',
        ]);

        $response->assertRedirect();
        $this->assertSame(1, $this->inbox->table('balasan_template')->where('nama', 'Sudah Ada')->countAllResults());
    }

    /**
     * Keunikan nama saat UPDATE harus mengabaikan baris itu sendiri (format
     * is_unique[...,id,{id}]) -- memastikan prefix dbGroup baru
     * (inbox.balasan_template.nama,id,{id}) tidak merusak pengecualian id.
     */
    public function testUpdateAllowsKeepingTheSameNama(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->inbox->table('balasan_template')->insert([
            'nama' => 'Tetap Sama', 'teks' => 'lama', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $this->inbox->insertID();

        $response = $this->asAdmin()->post('/balasan-template/update/' . $id, [
            'nama' => 'Tetap Sama',
            'teks' => 'diperbarui',
        ]);

        $response->assertRedirectTo('/balasan-template');
        $updated = $this->inbox->table('balasan_template')->where('id', $id)->get()->getRowArray();
        $this->assertSame('diperbarui', $updated['teks']);
    }
}
