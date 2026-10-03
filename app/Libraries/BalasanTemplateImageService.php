<?php

namespace App\Libraries;

use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Inbox as InboxConfig;

/**
 * Service kecil untuk menyimpan/menghapus file gambar Template Balasan
 * Cepat (TODO-R1). Clone dari FotoProfilService -- lihat class docblock
 * di sana untuk alasan desain penyimpanan (WRITEPATH, nama file server-
 * generated, validasi MIME dari isi file). Dipisah menjadi class sendiri
 * (bukan reuse FotoProfilService) karena foldernya berbeda
 * (uploads/balasan_template/, bukan uploads/foto_profil/) dan batas
 * ukurannya mengikuti InboxConfig->maxMediaUploadMb (bukan 2 MB tetap),
 * sesuai docs/requirements/2026-10-03-template-balasan-cepat.md Section 5.
 */
class BalasanTemplateImageService
{
    private const ALLOWED_MIME_TO_EXT = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    public function getDir(): string
    {
        $dir = WRITEPATH . 'uploads/balasan_template';

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Gagal menyiapkan folder gambar template balasan.');
        }

        return $dir;
    }

    /**
     * Validasi & simpan file upload baru. Tidak menyentuh database.
     *
     * @return array{success: bool, filename?: string, error?: string}
     */
    public function simpan(?UploadedFile $file): array
    {
        if (!$file || !$file->isValid() || $file->hasMoved()) {
            return ['success' => false, 'error' => 'File tidak valid atau gagal diupload.'];
        }

        $maxKb = (new InboxConfig())->maxMediaUploadMb * 1024;

        if ($file->getSizeByUnit('kb') > $maxKb) {
            return ['success' => false, 'error' => 'Ukuran file melebihi batas maksimal.'];
        }

        $mime = $file->getMimeType();

        if (!isset(self::ALLOWED_MIME_TO_EXT[$mime])) {
            return ['success' => false, 'error' => 'Tipe file tidak didukung. Gunakan JPG, PNG, atau WEBP.'];
        }

        if (@getimagesize($file->getTempName()) === false) {
            return ['success' => false, 'error' => 'File bukan gambar yang valid.'];
        }

        $ext = self::ALLOWED_MIME_TO_EXT[$mime];
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;

        try {
            $file->move($this->getDir(), $filename);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Gagal menyimpan file: ' . $e->getMessage()];
        }

        return ['success' => true, 'filename' => $filename];
    }

    /**
     * Hapus file gambar lama dari disk (jika ada). Tidak menyentuh
     * database -- pemanggil bertanggung jawab meng-update kolom
     * gambar_filename.
     */
    public function hapus(?string $filename): void
    {
        if (!$filename) {
            return;
        }

        $filename = basename($filename);
        $path = $this->getDir() . DIRECTORY_SEPARATOR . $filename;

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Ambil path fisik file untuk keperluan streaming, HANYA jika nama
     * file valid (format hasil generate server) dan file-nya benar-benar
     * ada. Mengembalikan null jika tidak valid/tidak ada -- pemanggil
     * harus menganggap ini sebagai 404, bukan error.
     */
    public function resolvePathUntukDitampilkan(string $filename): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $filename)) {
            return null;
        }

        $path = $this->getDir() . DIRECTORY_SEPARATOR . $filename;

        return is_file($path) ? $path : null;
    }
}
