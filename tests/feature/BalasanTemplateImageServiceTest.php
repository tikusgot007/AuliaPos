<?php

use App\Libraries\BalasanTemplateImageService;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\FakeUploadedFile;

/**
 * BalasanTemplateImageService (AC-4, AC-5, AC-6 of
 * docs/requirements/2026-10-03-template-balasan-cepat.md).
 *
 * Lives under tests/feature/ (not tests/unit/) because the service
 * uses the WRITEPATH constant and Config\Inbox (needs env()/service()),
 * which require the full CodeIgniter bootstrap -- see
 * BalasanTemplateModelTest.php for the same reasoning. This test does
 * NOT touch the database. Not yet run in this session -- no test
 * database is reachable here (bootstrap-feature.php aborts the whole
 * feature suite before any test runs without one).
 *
 * @internal
 */
final class BalasanTemplateImageServiceTest extends CIUnitTestCase
{
    private BalasanTemplateImageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new BalasanTemplateImageService();

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
        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.png';
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
        ));

        return $path;
    }

    private function uploadedFrom(string $path, string $originalName = 'upload.png'): FakeUploadedFile
    {
        return new FakeUploadedFile($path, $originalName, mime_content_type($path), filesize($path), UPLOAD_ERR_OK);
    }

    public function testSimpanAcceptsValidPngAndGeneratesServerFilename(): void
    {
        $hasil = $this->service->simpan($this->uploadedFrom($this->pngValidPath()));

        $this->assertTrue($hasil['success'], $hasil['error'] ?? '');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $hasil['filename']);
        $this->assertFileExists($this->service->getDir() . '/' . $hasil['filename']);
    }

    public function testSimpanRejectsNonImageContentDespiteImageExtension(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.png';
        file_put_contents($path, 'bukan gambar, cuma teks biasa');

        $hasil = $this->service->simpan($this->uploadedFrom($path));

        $this->assertFalse($hasil['success']);
        $this->assertNotEmpty($hasil['error']);
    }

    public function testSimpanRejectsUnsupportedMime(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.gif';
        // GIF header asli -- MIME sungguhan image/gif, bukan di daftar yang didukung.
        file_put_contents($path, "GIF89a" . str_repeat("\0", 20));

        $hasil = $this->service->simpan($this->uploadedFrom($path));

        $this->assertFalse($hasil['success']);
        $this->assertStringContainsString('Tipe file tidak didukung', $hasil['error']);
    }

    public function testSimpanRejectsOversizedFile(): void
    {
        $maxKb = (new \Config\Inbox())->maxMediaUploadMb * 1024;
        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.png';
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
        ));

        $file = new FakeUploadedFile($path, 'besar.png', 'image/png', ($maxKb + 1) * 1024, UPLOAD_ERR_OK);
        $hasil = $this->service->simpan($file);

        $this->assertFalse($hasil['success']);
        $this->assertStringContainsString('melebihi batas', $hasil['error']);
    }

    public function testHapusRemovesFileFromDisk(): void
    {
        $hasil = $this->service->simpan($this->uploadedFrom($this->pngValidPath()));
        $filename = $hasil['filename'];
        $path = $this->service->getDir() . '/' . $filename;
        $this->assertFileExists($path);

        $this->service->hapus($filename);

        $this->assertFileDoesNotExist($path);
    }

    public function testResolvePathUntukDitampilkanRejectsPathTraversalAndUnknownFile(): void
    {
        $this->assertNull($this->service->resolvePathUntukDitampilkan('../../.env'));
        $this->assertNull($this->service->resolvePathUntukDitampilkan('tidakada.png'));
    }

    public function testResolvePathUntukDitampilkanReturnsRealPathForKnownFile(): void
    {
        $hasil = $this->service->simpan($this->uploadedFrom($this->pngValidPath()));

        $path = $this->service->resolvePathUntukDitampilkan($hasil['filename']);

        $this->assertSame($this->service->getDir() . '/' . $hasil['filename'], $path);
    }
}
