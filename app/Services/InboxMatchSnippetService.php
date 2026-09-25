<?php

namespace App\Services;

/**
 * Pure Match Snippet cutter for the Operational Inbox conversation list.
 *
 * The service does not touch DB, session, or request state: it only receives
 * the stored message text plus the keyword and returns the snippet, so it can
 * be tested without the CodeIgniter bootstrap. It is the one approved CON-004
 * exception of Fase 1e (Spec M3 4.4 / CL-019, plan TASK-023) and adds no
 * public contract: no query parameter, endpoint, column, or migration.
 *
 * Contract (Spec M3 4.4 / AC-014g):
 * - every run of whitespace, new lines included, collapses into one space,
 *   then the text is trimmed; `null`, empty, or whitespace-only text returns
 *   `null`;
 * - text of at most 120 characters (characters, not bytes) is returned as is,
 *   without the ellipsis;
 * - longer text is cut to a 120-character window starting 40 characters
 *   before the first case-insensitive occurrence of the keyword, clamped at
 *   the beginning of the text, with the ellipsis added only on the side(s)
 *   that were really cut;
 * - when the keyword is not found in PHP (e.g. the database matched "e" for
 *   an accented letter), the window starts at the beginning of the text;
 * - every length and cut uses `mb_*`, never `strlen`/`substr`, so multi-byte
 *   letters and emoji are never split.
 */
class InboxMatchSnippetService
{
    /** Maximum snippet length in characters, not bytes (CL-019). */
    private const MAKS_KARAKTER = 120;

    /** How many characters are kept before the match inside the window. */
    private const KARAKTER_SEBELUM_COCOK = 40;

    /** Single-character marker added on each side that was cut. */
    private const ELIPSIS = "\u{2026}";

    /**
     * Cut a stored message text into a display snippet around the keyword.
     *
     * @param string|null $teks Raw `messages.text` value (may be NULL).
     * @param string      $q    Keyword searched by the caller; an empty
     *                          keyword (or one not found here) starts the
     *                          window at the beginning of the text.
     *
     * @return string|null The snippet, or NULL when there is nothing to show.
     */
    public function potong(?string $teks, string $q): ?string
    {
        // Runs of whitespace (new lines included) collapse into one space.
        $teks = trim((string) preg_replace('/\s+/', ' ', (string) $teks));

        if ($teks === '') {
            return null;
        }

        $panjang = mb_strlen($teks);

        if ($panjang <= self::MAKS_KARAKTER) {
            return $teks;
        }

        $posisi = mb_stripos($teks, $q);

        if ($posisi === false) {
            $posisi = 0;
        }

        $awal = max(0, $posisi - self::KARAKTER_SEBELUM_COCOK);
        $potongan = mb_substr($teks, $awal, self::MAKS_KARAKTER);

        if ($awal > 0) {
            $potongan = self::ELIPSIS . $potongan;
        }

        if ($awal + self::MAKS_KARAKTER < $panjang) {
            $potongan .= self::ELIPSIS;
        }

        return $potongan;
    }
}
