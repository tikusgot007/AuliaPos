<?php

namespace App\Libraries;

use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Service kecil untuk menyimpan/menghapus file foto profil user.
 *
 * Dipakai oleh Profil::uploadFoto() (user mengubah foto sendiri) dan
 * oleh Auth::simpanUser()/updateUser() (admin mengelola foto user
 * lain). Sengaja dipisah jadi satu class supaya logic validasi &
 * penyimpanan TIDAK diduplikasi di dua tempat.
 *
 * Keputusan desain penting (lihat docs/aturan-bisnis-AULIA.md
 * Section 24):
 * - File disimpan di WRITEPATH/uploads/foto_profil/, di LUAR
 *   docroot publik (public/). Ini supaya file yang di-upload user
 *   tidak pernah bisa dieksekusi sebagai PHP oleh webserver,
 *   apa pun konfigurasi .htaccess-nya — sama seperti pola upload
 *   CSV maintenance produk yang sudah ada (WRITEPATH/uploads/...).
 * - Nama file SELALU dibuat oleh server (random hex + ekstensi dari
 *   MIME yang divalidasi), tidak pernah nama file asli dari user.
 * - Database hanya menyimpan nama file (mis. "a1b2c3....jpg"),
 *   bukan path lengkap dan bukan binary.
 * - Untuk ditampilkan di <img>, file di-stream lewat
 *   Profil::foto($filename) (route /foto-profil/{filename}), bukan
 *   diakses langsung sebagai static file.
 */
class FotoProfilService
{
    /** Ekstensi & MIME yang diizinkan. */
    private const ALLOWED_MIME_TO_EXT = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    private const MAX_SIZE_KB = 2048; // 2 MB

    public function getDir(): string
    {
        $dir = WRITEPATH . 'uploads/foto_profil';

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Gagal menyiapkan folder foto profil.');
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

        if ($file->getSizeByUnit('kb') > self::MAX_SIZE_KB) {
            return ['success' => false, 'error' => 'Ukuran file maksimal 2 MB.'];
        }

        // Validasi MIME dari isi file (bukan dari nama file/ekstensi
        // yang bisa dipalsukan client).
        $mime = $file->getMimeType();

        if (!isset(self::ALLOWED_MIME_TO_EXT[$mime])) {
            return ['success' => false, 'error' => 'Tipe file tidak didukung. Gunakan JPG, PNG, atau WEBP.'];
        }

        // Pastikan file benar-benar gambar valid (bukan cuma header
        // MIME yang dipalsukan) — getimagesize() akan gagal/false
        // untuk file non-gambar meskipun ekstensi/MIME-nya dipalsukan.
        if (@getimagesize($file->getTempName()) === false) {
            return ['success' => false, 'error' => 'File bukan gambar yang valid.'];
        }

        $ext = self::ALLOWED_MIME_TO_EXT[$mime];

        // Nama file dibuat server sepenuhnya, tidak ada bagian dari
        // nama file asli user yang dipakai.
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;

        try {
            $file->move($this->getDir(), $filename);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Gagal menyimpan file: ' . $e->getMessage()];
        }

        return ['success' => true, 'filename' => $filename];
    }

    /**
     * Hapus file foto lama dari disk (jika ada). Tidak menyentuh
     * database — pemanggil bertanggung jawab meng-update kolom
     * profile_photo jadi NULL.
     */
    public function hapus(?string $filename): void
    {
        if (!$filename) {
            return;
        }

        // Jaga-jaga: pastikan hanya nama file polos yang diproses,
        // bukan path (mencegah path traversal walau nilainya
        // seharusnya selalu berasal dari server, bukan input user).
        $filename = basename($filename);
        $path = $this->getDir() . DIRECTORY_SEPARATOR . $filename;

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Ambil path fisik file untuk keperluan streaming, HANYA jika
     * nama file valid (format hasil generate server) dan file-nya
     * benar-benar ada. Mengembalikan null jika tidak valid/tidak ada
     * — pemanggil harus menganggap ini sebagai 404, bukan error.
     */
    public function resolvePathUntukDitampilkan(string $filename): ?string
    {
        // Nama file yang kita generate selalu match pola ini.
        // Menolak apa pun di luar pola ini mencegah path traversal
        // (mis. "../../.env") sejak awal.
        if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $filename)) {
            return null;
        }

        $path = $this->getDir() . DIRECTORY_SEPARATOR . $filename;

        return is_file($path) ? $path : null;
    }
}
