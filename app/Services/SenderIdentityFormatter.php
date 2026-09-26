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

    /**
     * Turunkan label tampilan yang AMAN dari `messages.sender_jid`:
     * - `@s.whatsapp.net` -> nomor telepon bersih (tanpa sufiks device `:NN`).
     * - `@lid` atau domain berakhiran `.lid` -> `LID`.
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
        if ($pos === false || $pos === 0 || $pos === strlen($senderJid) - 1) {
            return self::LABEL_FALLBACK;
        }

        $local  = substr($senderJid, 0, $pos);
        $domain = substr($senderJid, $pos + 1);

        if ($domain === 's.whatsapp.net') {
            // key.participant dapat berupa `<nomor>:<device>@s.whatsapp.net`;
            // kontrak REQ-008/AC-002 hanya menampilkan nomor telepon bersih.
            $phone = explode(':', $local, 2)[0];

            return $phone !== '' ? $phone : self::LABEL_FALLBACK;
        }

        if ($domain === 'lid' || str_ends_with($domain, '.lid')) {
            return self::LABEL_LID;
        }

        if (strcasecmp($domain, 'g.us') === 0) {
            // JID grup: warisan Gateway lama mengisi `sender_jid` dengan JID
            // grup, yang BUKAN identitas anggota. Tampilkan tanpa identitas.
            return null;
        }

        return self::LABEL_FALLBACK;
    }
}
