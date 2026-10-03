<?php

namespace App\Libraries;

/**
 * Helper murni untuk respons `GET /inbox/media/:id`: nama file hasil unduhan
 * dan header `Content-Disposition`. Dipisah dari `App\Controllers\Inbox`
 * supaya bisa dites tanpa database.
 *
 * @internal
 */
final class InboxMediaDownload
{
    /** Mime -> ekstensi untuk jenis yang lazim dikirim lewat WhatsApp. */
    private const EXTENSIONS = [
        'image/jpeg'         => 'jpg',
        'image/png'          => 'png',
        'image/webp'         => 'webp',
        'image/gif'          => 'gif',
        'application/pdf'    => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'docx',
        'application/vnd.ms-excel'                                                  => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => 'xlsx',
        'application/vnd.ms-powerpoint'                                             => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/zip'    => 'zip',
        'text/plain'         => 'txt',
        'text/csv'           => 'csv',
    ];

    /**
     * Ekstensi (tanpa titik) dari mime, atau '' bila tidak dikenal.
     */
    public static function extensionFromMime(?string $mime): string
    {
        $mime = strtolower(trim(explode(';', (string) $mime)[0]));

        return self::EXTENSIONS[$mime] ?? '';
    }

    /**
     * Nama file unduhan. Nama asli dari pengirim dipakai bila ada (dibersihkan
     * dari path dan karakter kontrol); selain itu `media-<id>`. Ekstensi
     * selalu ada bila mime-nya dikenal.
     */
    public static function filename(?string $stored, ?string $mime, int $messageId): string
    {
        $name = trim(basename(str_replace('\\', '/', (string) $stored)));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F"]+/u', '', $name));

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'media-' . $messageId;
        }

        $ext = self::extensionFromMime($mime);

        if ($ext !== '' && pathinfo($name, PATHINFO_EXTENSION) === '') {
            $name .= '.' . $ext;
        }

        return $name;
    }

    /**
     * 'inline' hanya untuk gambar non-SVG yang tidak diminta diunduh.
     * Selain itu 'attachment' (SVG dan dokumen dapat memuat skrip bila dibuka
     * langsung di tab).
     */
    public static function disposition(?string $mime, string $messageType, bool $forceDownload): string
    {
        if ($forceDownload || $messageType === 'document') {
            return 'attachment';
        }

        $mime = strtolower(trim(explode(';', (string) $mime)[0]));

        if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') {
            return 'inline';
        }

        return 'attachment';
    }

    /**
     * Nilai header `Content-Disposition` dengan fallback ASCII dan
     * `filename*` (RFC 6266 / RFC 5987) untuk nama non-ASCII.
     */
    public static function contentDisposition(string $disposition, string $filename): string
    {
        $ascii = (string) preg_replace('/[^\x20-\x7E]+/', '_', $filename);
        $ascii = str_replace(['\\', '"', '%'], '_', $ascii);

        return $disposition . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
    }
}
