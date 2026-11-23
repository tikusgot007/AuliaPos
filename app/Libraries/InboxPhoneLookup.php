<?php

namespace App\Libraries;

use App\Models\ConversationModel;

/**
 * Cari percakapan inbox dari nomor HP pelanggan (tombol WA di detail
 * transaksi/tagihan).
 *
 * Aturan (disetujui Muhammad):
 * - Cocok HANYA lewat nomor HP, dinormalisasi (PhoneNumber::normalize)
 *   dan dibandingkan dengan `conversations.phone` (nomor ter-verifikasi).
 *   `manual_phone` dan `pelanggan_id` SENGAJA tidak dipakai.
 * - Grup diabaikan; percakapan soft-deleted tidak ikut (default model).
 * - Kalau ada beberapa, ambil `last_message_at` terbaru.
 * - Read-only: tidak pernah menulis ke database mana pun.
 */
class InboxPhoneLookup
{
    /**
     * @return array{wa_url: string, conversation_id: ?int}|null null kalau
     *         nomor kosong/tidak valid (tombol tidak ditampilkan).
     */
    public static function untukNomor(?string $noHp): ?array
    {
        $nomor = PhoneNumber::normalize((string) $noHp);
        if ($nomor === null) {
            return null;
        }

        $id = null;
        try {
            $row = (new ConversationModel())
                ->select('id')
                ->where('phone', $nomor)
                ->where('jid_type !=', 'group')
                ->orderBy('last_message_at IS NULL', 'ASC', false)
                ->orderBy('last_message_at', 'DESC')
                ->orderBy('id', 'DESC')
                ->first();
            $id = $row ? (int) $row['id'] : null;
        } catch (\Throwable $e) {
            // DB inbox tidak bisa dijangkau -> jatuh ke wa.me, halaman
            // detail transaksi tidak boleh ikut rusak.
            log_message('error', 'InboxPhoneLookup gagal: ' . $e->getMessage());
        }

        return [
            'wa_url'          => 'https://wa.me/' . $nomor,
            'conversation_id' => $id,
        ];
    }
}
