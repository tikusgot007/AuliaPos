<?php

namespace App\Libraries;

/**
 * Adapter penyimpanan permanen media Inbox. Backend saat ini: disk
 * lokal (termasuk HDD eksternal yang dikenali sebagai drive lokal,
 * mis. X:\). Semua pemanggil (Inbox::media(), InboxGatewayApi) HARUS
 * lewat sini, TIDAK boleh panggil file_put_contents()/fopen() langsung
 * -- supaya ganti backend (mis. ke MinIO/S3 nanti) cuma ubah file ini.
 *
 * SEMUA method gagal-aman (return false/null, TIDAK PERNAH throw) --
 * storage yang tidak terpasang/gagal tulis HARUS terasa seperti
 * "belum ke-download", bukan error yang merusak alur pesan masuk.
 */
class InboxMediaStorage
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '\\/');
    }

    public function isConfigured(): bool
    {
        return $this->basePath !== '';
    }

    public function save(string $filename, string $binary): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        if (!is_dir($this->basePath) && !@mkdir($this->basePath, 0775, true)) {
            log_message('error', "InboxMediaStorage: folder tidak bisa diakses/dibuat: {$this->basePath} (HDD eksternal belum terpasang?)");
            return false;
        }

        return @file_put_contents($this->fullPath($filename), $binary) !== false;
    }

    public function read(string $filename): ?string
    {
        $path = $this->fullPath($filename);

        if (!is_file($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        return $content === false ? null : $content;
    }

    public function exists(string $filename): bool
    {
        return $this->isConfigured() && is_file($this->fullPath($filename));
    }

    private function fullPath(string $filename): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . $filename;
    }
}
