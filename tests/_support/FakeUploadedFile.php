<?php

namespace Tests\Support;

use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Test double untuk CodeIgniter\HTTP\Files\UploadedFile.
 *
 * UploadedFile asli menolak file apa pun di luar request HTTP nyata
 * lewat is_uploaded_file()/move_uploaded_file() -- keduanya TIDAK
 * PERNAH true di luar permintaan multipart nyata, termasuk di PHPUnit
 * CLI. Override ini HANYA mengganti scaffolding level-OS "apakah ini
 * upload HTTP asli" itu -- validasi MIME (finfo, lewat
 * File::getMimeType() yang tidak disentuh), ukuran, dan getimagesize()
 * di kode yang diuji (BalasanTemplateImageService) tetap berjalan
 * terhadap isi file sungguhan, tidak ada yang dipalsukan.
 */
class FakeUploadedFile extends UploadedFile
{
    public function isValid(): bool
    {
        return $this->getError() === UPLOAD_ERR_OK;
    }

    public function move(string $targetPath, ?string $name = null, bool $overwrite = false)
    {
        if ($this->hasMoved) {
            throw \CodeIgniter\HTTP\Exceptions\HTTPException::forAlreadyMoved();
        }

        if (!$this->isValid()) {
            throw \CodeIgniter\HTTP\Exceptions\HTTPException::forInvalidFile();
        }

        $targetPath = rtrim($targetPath, '/') . '/';
        if (!is_dir($targetPath)) {
            mkdir($targetPath, 0777, true);
        }

        $name ??= $this->getName();
        $destination = $overwrite ? $targetPath . $name : $this->getDestination($targetPath . $name);

        if (!copy($this->getTempName(), $destination)) {
            return false;
        }

        $this->hasMoved = true;
        $this->path     = $targetPath;
        $this->name     = basename($destination);

        return true;
    }
}
