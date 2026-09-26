<?php

namespace App\Services;

/**
 * Aturan tampilan identitas pengirim grup (Grup Tahap 2, REQ-008/AC-002).
 *
 * Diekstrak dari controller agar dapat dipakai ulang oleh Tahap 3 (kutipan
 * menampilkan identitas pengirim yang dikutip, PRD GH-015) -- lihat temuan
 * ARCH-01.
 *
 * Pure: tidak menyentuh DB, session, atau request state. TIDAK PERNAH
 * mengembalikan JID mentah ke UI (invarian proyek: jangan membocorkan JID
 * mentah).
 *
 * Konvensi `null`: label yang dikembalikan `null` berarti "tanpa identitas"
 * (tidak ada baris label sama sekali). Saat ini hanya JID grup (`@g.us`)
 * yang diperlakukan begitu -- nilai warisan Gateway lama yang bukan identitas
 * anggota (REQ-011/AC-012).
 */
class SenderIdentityFormatter
{
    public const LABEL_FALLBACK = 'Pengirim';
    public const LABEL_LID      = 'LID';

    public const DOMAIN_WHATSAPP   = 's.whatsapp.net';
    public const DOMAIN_GROUP      = 'g.us';
    public const DOMAIN_LID_SUFFIX = '.lid';
    public const DEVICE_SEPARATOR  = ':';

    /**
     * Turunkan label tampilan yang AMAN dari `messages.sender_jid`:
     * - `@s.whatsapp.net` -> nomor telepon bersih (tanpa sufiks device `:NN`),
     *   hanya bila local part numerik murni; selain itu -> `Pengirim`.
     * - `@lid` atau domain berakhiran `.lid` (case-insensitive) -> `LID`.
     * - domain grup `g.us` (case-insensitive) -> `null` (tanpa identitas;
     *   JID grup TIDAK PERNAH dirender -- REQ-011/AC-012). Nilai DB tidak
     *   diubah; baris legacy cukup tidak diberi label.
     * - Selain itu (termasuk null/kosong/malformed) -> `Pengirim`.
     */
    public function labelFor(?string $senderJid): ?string
    {
        if ($senderJid === null || $senderJid === '') {
            return self::LABEL_FALLBACK;
        }

        $pos = strrpos($senderJid, '@');
        if ($pos === false) {
            return self::LABEL_FALLBACK;
        }

        // Hostname tidak case-sensitive: normalisasi sekali, lalu bandingkan ketat.
        $domain = strtolower(substr($senderJid, $pos + 1));

        // Domain grup didahulukan agar local kosong (`@g.us`) pun tanpa
        // identitas; JID grup TIDAK PERNAH dirender (REQ-011/AC-012).
        if ($domain === self::DOMAIN_GROUP) {
            // JID grup: warisan Gateway lama mengisi `sender_jid` dengan JID
            // grup, yang BUKAN identitas anggota. Tampilkan tanpa identitas.
            return null;
        }

        if ($pos === 0 || $pos === strlen($senderJid) - 1) {
            return self::LABEL_FALLBACK;
        }

        $local = substr($senderJid, 0, $pos);

        if ($domain === self::DOMAIN_WHATSAPP) {
            // key.participant dapat berupa `<nomor>:<device>@s.whatsapp.net`;
            // kontrak REQ-008/AC-002 hanya menampilkan nomor telepon bersih.
            // Allowlist numerik murni: local part ber-`@` (mis. `x@g.us`)
            // tidak pernah lolos sebagai label (REQ-001).
            $phone = explode(self::DEVICE_SEPARATOR, $local, 2)[0];

            return preg_match('/^\d+$/', $phone) === 1 ? $phone : self::LABEL_FALLBACK;
        }

        if ($domain === 'lid' || str_ends_with($domain, self::DOMAIN_LID_SUFFIX)) {
            return self::LABEL_LID;
        }

        return self::LABEL_FALLBACK;
    }
}
