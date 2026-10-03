<?php

use App\Libraries\FotoProfilService;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\FakeUploadedFile;

/**
 * FotoProfilService (TODO-F7).
 *
 * Lives under tests/feature/ (not tests/unit/) for the same reason as
 * BalasanTemplateImageServiceTest.php: the service uses the WRITEPATH
 * constant, which needs the full CodeIgniter bootstrap. This test does
 * NOT touch the database.
 *
 * The oversized-file case is the regression guard for TODO-F7:
 * getSizeByUnit('kb') returns a number_format() string ("2,048.001"),
 * so comparing it with `>` against an int never rejected large files.
 *
 * @internal
 */
final class FotoProfilServiceTest extends CIUnitTestCase
{
    /** Batas layanan: 2048 KB (lihat FotoProfilService::MAX_SIZE_KB). */
    private const MAX_BYTES = 2048 * 1024;

    private FotoProfilService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new FotoProfilService();

        // Bersihkan folder sebelum dan sesudah tiap test supaya test tidak
        // saling mempengaruhi lewat file fisik yang tertinggal.
        $this->bersihkanDir();
    }

    protected function tearDown(): void
    {
        $this->bersihkanDir();
        parent::tearDown();
    }

    private function bersihkanDir(): void
    {
        $dir = $this->service->getDir();
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
    }

    /** 1x1 PNG valid asli -- supaya getimagesize()/finfo (MIME dari isi file) lolos sungguhan. */
    private function pngValidPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'foto') . '.png';
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
        ));

        return $path;
    }

    /**
     * FakeUploadedFile dengan ukuran yang dikontrol -- ukuran asli dikirim
     * via argumen ke-4 (sama seperti $_FILES['size']), bukan filesize()
     * fisik, supaya batas 2 MB bisa diuji tanpa menulis file 2 MB nyata.
     */
    private function uploadedFrom(string $path, int $sizeBytes, string $originalName = 'upload.png'): FakeUploadedFile
    {
        return new FakeUploadedFile($path, $originalName, mime_content_type($path), $sizeBytes, UPLOAD_ERR_OK);
    }

    public function testSimpanRejectsFileLargerThanMaxSize(): void
    {
        $file  = $this->uploadedFrom($this->pngValidPath(), self::MAX_BYTES + 1, 'besar.png');
        $hasil = $this->service->simpan($file);

        $this->assertFalse($hasil['success']);
        $this->assertStringContainsString('Ukuran file maksimal 2 MB', $hasil['error'] ?? '');
    }

    public function testSimpanAcceptsFileAtMaxSize(): void
    {
        $hasil = $this->service->simpan($this->uploadedFrom($this->pngValidPath(), self::MAX_BYTES));

        $this->assertTrue($hasil['success'], $hasil['error'] ?? '');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $hasil['filename']);
    }

    public function testSimpanRejectsNonImageContent(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'foto') . '.png';
        file_put_contents($path, 'bukan gambar, cuma teks biasa');

        $hasil = $this->service->simpan($this->uploadedFrom($path, (int) filesize($path)));

        $this->assertFalse($hasil['success']);
    }

    public function testSimpanRejectsUnsupportedMime(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'foto') . '.gif';
        // GIF header asli -- MIME sungguhan image/gif, bukan di daftar yang didukung.
        file_put_contents($path, 'GIF89a' . str_repeat("\0", 20));

        $hasil = $this->service->simpan($this->uploadedFrom($path, (int) filesize($path), 'x.gif'));

        $this->assertFalse($hasil['success']);
        $this->assertStringContainsString('Tipe file tidak didukung', $hasil['error'] ?? '');
    }
}
