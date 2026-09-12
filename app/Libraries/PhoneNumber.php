<?php

namespace App\Libraries;

/**
 * Normalisasi nomor telepon Indonesia ke bentuk canonical (digit saja,
 * diawali "62", TANPA "+"/"0" di depan) -- dipakai bersama oleh
 * Inbox::mulaiPercakapan() (kasir mengetik nomor untuk chat baru) dan
 * fitur edit profil customer (Task Group 1.5), supaya "08563324637",
 * "+628563324637", dan "628563324637" semuanya dikenali sebagai nomor
 * yang SAMA (lihat docs/aturan-bisnis-CHAT.md Section 11).
 *
 * SENGAJA cuma format ulang STRING yang sudah diketik/diterima --
 * TIDAK PERNAH memvalidasi ke server WhatsApp sungguhan (nomor hasil
 * normalize() dari input manual TETAP dianggap "belum terverifikasi",
 * beda dari nomor yang di-derive Gateway dari JID @s.whatsapp.net asli
 * -- lihat catatan `manual_phone` vs `phone` di
 * ConversationModel/migration).
 *
 * Diekstrak dari logika yang sebelumnya inline di
 * Inbox::normalizePhoneToJid() -- perilakunya SENGAJA tidak diubah
 * sama sekali, cuma dipindah supaya bisa dipakai ulang tanpa duplikasi.
 */
class PhoneNumber
{
    /**
     * Mengembalikan nomor canonical ("62xxxxxxxxxx", digit saja) atau
     * null kalau formatnya tidak bisa dikenali dengan yakin -- SENGAJA
     * tidak menebak-nebak nomor yang ambigu.
     */
    public static function normalize(string $input): ?string
    {
        // Buang semua karakter selain digit (termasuk spasi, strip,
        // tanda kurung); '+' di depan ditangani terpisah di bawah.
        $hasPlus    = str_starts_with(trim($input), '+');
        $digitsOnly = preg_replace('/\D/', '', $input);

        if ($hasPlus && str_starts_with($digitsOnly, '62')) {
            $normalized = $digitsOnly; // "+62xxx" -> "62xxx"
        } elseif (str_starts_with($digitsOnly, '62')) {
            $normalized = $digitsOnly; // sudah "62xxx"
        } elseif (str_starts_with($digitsOnly, '0')) {
            $normalized = '62' . substr($digitsOnly, 1); // "08xxx" -> "628xxx"
        } else {
            return null; // format tidak dikenali -- jangan menebak
        }

        // Validasi panjang wajar untuk nomor Indonesia (62 + 8-13 digit).
        if (strlen($normalized) < 10 || strlen($normalized) > 15 || !ctype_digit($normalized)) {
            return null;
        }

        return $normalized;
    }
}
