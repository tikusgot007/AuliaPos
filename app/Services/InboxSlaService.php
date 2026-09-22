<?php

namespace App\Services;

use Config\Inbox as InboxConfig;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Pure-ish SLA calculation for Operational Inbox.
 *
 * The service does not touch DB, session, or request state. The current
 * time is injected by the caller so boundary behavior remains deterministic
 * in tests. Thresholds come from Config\Inbox rather than being hardcoded.
 */
class InboxSlaService
{
    public const HIJAU  = 'hijau';
    public const KUNING = 'kuning';
    public const MERAH  = 'merah';

    private const TIMEZONE = 'Asia/Jakarta';

    public function __construct(private ?InboxConfig $config = null)
    {
        $this->config ??= new InboxConfig();
    }

    public function hitung(
        ?string $lastMessageAt,
        string $queueStatus,
        ?DateTimeImmutable $now = null
    ): ?string {
        if (in_array($queueStatus, ['selesai', 'ditunda'], true)) {
            return null;
        }

        if ($lastMessageAt === null || trim($lastMessageAt) === '') {
            return null;
        }

        try {
            $timezone = new DateTimeZone(self::TIMEZONE);
            $lastMessage = new DateTimeImmutable($lastMessageAt, $timezone);
            $now ??= new DateTimeImmutable('now', $timezone);
            $elapsedSeconds = max(0, $now->getTimestamp() - $lastMessage->getTimestamp());
        } catch (\Exception) {
            return null;
        }

        $greenSeconds = $this->config->slaGreenMinutes * 60;
        $yellowSeconds = $this->config->slaYellowMinutes * 60;

        if ($elapsedSeconds < $greenSeconds) {
            return self::HIJAU;
        }

        if ($elapsedSeconds <= $yellowSeconds) {
            return self::KUNING;
        }

        return self::MERAH;
    }
}
