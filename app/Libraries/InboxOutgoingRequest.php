<?php

namespace App\Libraries;

use InvalidArgumentException;

/**
 * PRN-303: value object satu request kirim keluar ke Gateway.
 *
 * Menggantikan pertumbuhan parameter `?bool $forward = null` pada
 * `Inbox::callGatewaySend()` / `Inbox::callGatewaySendMedia()` (6 dan 10
 * parameter). Objek ini mengangkut `chatId`, teks ATAU media,
 * `operation_id`, kutipan, dan penanda `forward`, serta menegakkan
 * eksklusivitas `quoted` XOR `forward` di SATU tempat (CON-001) -- bukan di
 * ingatan tiap pemanggil.
 *
 * Dua factory: `teks()` untuk POST /send, `media()` untuk POST /send-media.
 * Konstruktor privat supaya satu-satunya jalan masuk melewati validasi.
 */
final class InboxOutgoingRequest
{
    /**
     * @param array<string, mixed>|null $quoted
     */
    private function __construct(
        public readonly string $chatId,
        public readonly ?string $text,
        public readonly ?string $mediaType,
        public readonly ?string $mediaBase64,
        public readonly ?string $mimetype,
        public readonly ?string $fileName,
        public readonly string $caption,
        public readonly ?string $operationId,
        public readonly ?array $quoted,
        public readonly bool $forward,
    ) {
        if ($this->quoted !== null && $this->forward) {
            throw new InvalidArgumentException('InboxOutgoingRequest: `quoted` dan `forward` tidak boleh bersamaan (CON-001).');
        }
    }

    /**
     * Request jalur teks (POST /send).
     *
     * @param array<string, mixed>|null $quoted
     */
    public static function teks(
        string $chatId,
        string $text,
        ?string $operationId = null,
        ?array $quoted = null,
        bool $forward = false
    ): self {
        return new self($chatId, $text, null, null, null, null, '', $operationId, $quoted, $forward);
    }

    /**
     * Request jalur media (POST /send-media).
     *
     * @param array<string, mixed>|null $quoted
     */
    public static function media(
        string $chatId,
        string $mediaType,
        string $mediaBase64,
        ?string $mimetype,
        ?string $fileName,
        string $caption,
        ?string $operationId = null,
        ?array $quoted = null,
        bool $forward = false
    ): self {
        return new self($chatId, null, $mediaType, $mediaBase64, $mimetype, $fileName, $caption, $operationId, $quoted, $forward);
    }
}
