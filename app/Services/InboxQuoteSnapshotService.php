<?php

namespace App\Services;

/**
 * Snapshot kutipan (Balas Pesan Tahap 3, spec Section 4.2 / REQ-007/REQ-008).
 *
 * Diekstrak dari controller supaya bisa diuji tanpa bootstrap CodeIgniter,
 * mengikuti pola `InboxMatchSnippetService` yang lahir dari kebutuhan yang
 * sama (logic murni yang tidak boleh bersembunyi di dalam controller besar).
 *
 * Kontrak inti (REQ-007): hasil fungsi-fungsi di sini dipakai **sekali**, saat
 * balasan dibuat, lalu disimpan sebagai snapshot beku. Tidak ada satu pun
 * jalur yang memanggilnya lagi saat ditampilkan -- kalau media sumber ternyata
 * hilang setelahnya, UI memakai nilai snapshot yang sudah tersimpan dan
 * menampilkan "[Media tidak tersedia]" TANPA menulis ulang database
 * (REQ-008 "fallback tampilan", AC-005).
 *
 * Tidak menyentuh DB, session, atau request state. Label pengirim TIDAK
 * dihitung di sini: pemanggil menyediakannya lewat `SenderIdentityFormatter`
 * / `UserModel` (seam identitas yang sudah ada -- lihat ASSUMPTION-001),
 * supaya kelas ini tetap murni dan tidak menarik dependensi model.
 */
class InboxQuoteSnapshotService
{
    /** Panjang maksimum cuplikan kutipan, dalam KARAKTER (bukan byte). */
    public const MAKS_KARAKTER = 200;

    /** Penanda tunggal yang ditambahkan saat teks benar-benar terpotong. */
    private const ELIPSIS = "\u{2026}";

    /**
     * Label jenis media untuk cuplikan kutipan. Nilai `null` untuk `text`
     * karena pesan teks memakai teksnya sendiri, bukan label.
     *
     * Dipakai juga sebagai teks kotak kutipan saat pesan sumbernya bertipe
     * media tanpa caption.
     */
    private const LABEL_MEDIA = [
        'image'    => '[Foto]',
        'document' => '[Dokumen]',
        'sticker'  => '[Stiker]',
        'audio'    => '[Audio]',
        'video'    => '[Video]',
    ];

    /**
     * Tipe pesan yang dianggap "media" untuk aturan ketersediaan (REQ-008).
     * Daftar ini mengikuti tipe yang benar-benar diterima Gateway untuk pesan
     * masuk -- lihat `InboxGatewayApi::messages()`.
     */
    private const TIPE_MEDIA = ['image', 'document', 'sticker', 'audio', 'video'];

    /**
     * Potong teks menjadi cuplikan kutipan (multibyte-safe).
     *
     * Teks yang lebih pendek dari batas dikembalikan utuh TANPA elipsis;
     * elipsis ditambahkan hanya kalau teks benar-benar terpotong, sehingga
     * kutipan pesan pendek tidak terlihat seperti teks yang tidak utuh
     * (ASSUMPTION-009).
     */
    public function potongSnippet(?string $teks): ?string
    {
        $teks = trim((string) preg_replace('/\s+/', ' ', (string) $teks));

        if ($teks === '') {
            return null;
        }

        if (mb_strlen($teks) <= self::MAKS_KARAKTER) {
            return $teks;
        }

        return mb_substr($teks, 0, self::MAKS_KARAKTER) . self::ELIPSIS;
    }

    /**
     * Cuplikan kutipan untuk satu baris pesan sumber: teks bila ada, kalau
     * tidak label jenis media. `null` bila sumbernya tidak punya teks maupun
     * tipe media yang dikenal (kasir tidak melihat kotak kutipan kosong).
     */
    public function snippetDari(array $sumber): ?string
    {
        $potong = $this->potongSnippet($sumber['text'] ?? null);

        if ($potong !== null) {
            return $potong;
        }

        $tipe = (string) ($sumber['message_type'] ?? '');

        return self::LABEL_MEDIA[$tipe] ?? null;
    }

    /**
     * Ketersediaan media pesan sumber, aturan TIGA-NILAI (REQ-008, selaras
     * urutan cek endpoint media yang sudah ada):
     *
     * - `0` HANYA bila `media_local_filename` kosong DAN `media_confirmed_gone_at`
     *   terisi. File lokal SELALU menang: kalau `media_local_filename` terisi
     *   nilainya `1` walau `media_confirmed_gone_at` juga terisi (kasus HDD
     *   dicabut -- live-fetch mengembalikan 410 dan mengisi confirmed-gone
     *   sementara file lokal masih ada).
     * - `1` = heuristik "media-typed & belum confirmed-gone" -> *potentially
     *   available*, BUKAN jaminan (media outgoing tidak punya file lokal dan
     *   endpoint-nya bisa mengembalikan 500).
     * - `NULL` = sumber bukan pesan media.
     *
     * Nilai ini TIDAK PERNAH ditulis ulang saat tampilan; kegagalan muat
     * `404`/`410`/error ditangani di UI sebagai "[Media tidak tersedia]"
     * (fallback tampilan).
     */
    public function ketersediaanMedia(array $sumber): ?int
    {
        $tipe = (string) ($sumber['message_type'] ?? '');

        if (! in_array($tipe, self::TIPE_MEDIA, true)) {
            return null;
        }

        $fileLokal = trim((string) ($sumber['media_local_filename'] ?? ''));
        $sudahGone = trim((string) ($sumber['media_confirmed_gone_at'] ?? ''));

        // Dua kondisi WAJIB terisi BERSAMAAN: tidak ada file lokal DAN sudah
        // dipastikan hilang permanen. Kalau file lokal masih ada, nilainya 1
        // walau confirmed-gone juga terisi.
        if ($fileLokal === '' && $sudahGone !== '') {
            return 0;
        }

        return 1;
    }

    /**
     * Tipe media pesan sumber untuk snapshot `quoted_media_type` (REQ-008c):
     * hanya tipe media yang dikenal (`image`/`document`/`sticker`/`audio`/
     * `video`), `null` untuk pesan teks atau tipe yang tidak dikenal. UI
     * memakai nilai ini untuk memilih representasi kutipan TANPA menebak dari
     * `quoted_snippet` (caption bisa menggantikan label jenis).
     */
    public function tipeMediaSumber(array $sumber): ?string
    {
        $tipe = (string) ($sumber['message_type'] ?? '');

        return in_array($tipe, self::TIPE_MEDIA, true) ? $tipe : null;
    }

    /**
     * Rakit nilai snapshot kutipan dari satu baris pesan sumber.
     *
     * `$senderLabel` diserahkan pemanggil (lihat catatan kelas): untuk grup
     * memakai label identitas Tahap 2, untuk pesan kasir memakai nama staff,
     * dan WAJIB non-`null` saat sumber DITEMUKAN -- inilah penanda tunggal
     * status "ditemukan" (F-B). Nilai `null` berarti sumber tidak ditemukan
     * dan pemanggil harus menaatkan label generik accordingly.
     *
     * v1.6 (REQ-008b): `quoted_source_message_id` diisi dari `$sumber['id']`
     * (baris sumber sudah di tangan pemanggil -- TANPA query tambahan) agar UI
     * punya target ID lokal untuk `GET /inbox/media/(:num)` saat fallback
     * tampilan `REQ-008` dijalankan. Nilainya `null` bila baris sumber tidak
     * membawa `id` (kasus "tidak ditemukan" tidak lewat method ini).
     *
     * v1.7 (REQ-008c): `quoted_media_type` diisi dari `$sumber['message_type']`
     * bila sumbernya pesan media, `null` untuk teks/tipe tak dikenal -- tetap
     * satu titik (mencakup kutipan keluar DAN masuk), tanpa query tambahan.
     *
     * @return array{quoted_wa_message_id: ?string, quoted_sender_label: ?string, quoted_snippet: ?string, quoted_media_available: ?int, quoted_source_message_id: ?int, quoted_media_type: ?string}
     */
    public function rakitSnapshot(array $sumber, ?string $senderLabel): array
    {
        return [
            'quoted_wa_message_id'     => $sumber['wa_message_id'] ?? null,
            'quoted_sender_label'      => $senderLabel,
            'quoted_snippet'           => $this->snippetDari($sumber),
            'quoted_media_available'   => $this->ketersediaanMedia($sumber),
            'quoted_source_message_id' => isset($sumber['id']) ? (int) $sumber['id'] : null,
            'quoted_media_type'        => $this->tipeMediaSumber($sumber),
        ];
    }

    /** Label jenis media yang dikenal, untuk dipakai controller saat dibutuhkan. */
    public function labelMedia(string $messageType): ?string
    {
        return self::LABEL_MEDIA[$messageType] ?? null;
    }
}
