<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model untuk tabel `conversations` di database aulia_inboxdb.
 *
 * PENTING: $DBGroup eksplisit di-set ke 'inbox' -- TIDAK PERNAH
 * mengandalkan default group, supaya mustahil salah nyambung ke
 * database AuliaPos utama (aulia_kasirdb).
 *
 * Field assigned_to, last_replied_by, closed_by, profile_updated_by
 * adalah LOGICAL REFERENCE ke aulia_kasirdb.users.id (bukan FK
 * database sungguhan, lihat migration
 * 2026-09-07-000001_CreateInboxTables.php). Kalau perlu menampilkan
 * nama user dari ID-ID ini, ambil terpisah lewat `App\Models\UserModel`
 * (koneksi default), lalu gabungkan di controller/view -- BUKAN lewat
 * JOIN SQL (tidak mungkin, beda database).
 *
 * --- Task Group 1.5: Customer Identity & Conversation Reconciliation ---
 * (lihat docs/aturan-bisnis-CHAT.md Section 11 untuk penjelasan penuh)
 *
 * `contact_name` vs `whatsapp_name`:
 * - `contact_name` = nama MANUAL customer profile (diisi kasir lewat
 *   fitur edit profil). TIDAK PERNAH ditimpa otomatis oleh event
 *   WhatsApp apa pun.
 * - `whatsapp_name` = push name dari WhatsApp, SELALU dimutakhirkan
 *   Gateway setiap pesan masuk baru, TIDAK dilindungi (memang boleh
 *   berubah kapan saja mengikuti WhatsApp).
 * - UI menampilkan `contact_name ?: whatsapp_name ?: ...` (manual
 *   menang kalau ada).
 *
 * `phone` vs `manual_phone`:
 * - `phone` = nomor TER-VERIFIKASI. Diisi dari 2 sumber SAJA: (a) JID
 *   `@s.whatsapp.net` asli yang di-derive Gateway (otomatis), atau
 *   (b) `Inbox::konfirmasiNomorWhatsapp()` -- tindakan MANUAL tapi
 *   SADAR/eksplisit oleh kasir/admin ("saya konfirmasi nomor ini
 *   benar milik WhatsApp customer ini", beda dari sekadar mengetik
 *   nomor di form edit profil biasa). Kolom inilah yang dipakai untuk
 *   reconciliation (lihat resolveConversationId()) -- TIDAK PERNAH
 *   diisi diam-diam/otomatis dari input manual biasa.
 * - `manual_phone` = nomor yang diketik MANUAL kasir lewat edit
 *   profil biasa, informasional saja, TIDAK PERNAH dipakai untuk
 *   mencari/menggabungkan conversation.
 *
 * `conversation_identities` (tabel terpisah, lihat
 * ConversationIdentityModel) menyimpan SEMUA JID (chat_id) yang
 * pernah dikenali sebagai milik satu conversation -- `chat_id` di
 * tabel ini TETAP jadi "JID aktif saat ini" (dipakai untuk kirim
 * balasan), tapi JID lama tidak pernah dilupakan.
 *
 * --- Revisi LID-FIRST -> PN-LATER (2026-09-12) ---
 * Masalah yang ditemukan setelah audit: kalau `@lid` datang DULUAN
 * (conversation dibuat dengan phone=NULL, sesuai desain -- @lid tidak
 * boleh ditebak), lalu BARU KEMUDIAN nomor PN asli yang SAMA muncul,
 * langkah cocok-nomor lama TIDAK PERNAH bisa menemukan match, karena
 * `phone` conversation @lid itu memang NULL selamanya (tidak pernah
 * ditebak). Diselesaikan dengan menambah langkah BARU (langkah 2 di
 * resolveConversationId(), sebelum cocok nomor) yang memakai
 * `$knownLid` -- HANYA diisi kalau Gateway berhasil menanyakan
 * LANGSUNG ke server WhatsApp (`sock.onWhatsApp()`, query USync
 * resmi, BUKAN tebakan) "JID @lid apa yang berkaitan dengan nomor PN
 * ini". Lihat docs/aturan-bisnis-CHAT.md Section 12 untuk audit
 * kapabilitas lengkap & CATATAN KEJUJURAN (belum diverifikasi
 * terhadap koneksi WhatsApp live).
 */
class ConversationModel extends Model
{
    protected $DBGroup    = 'inbox';
    protected $table      = 'conversations';
    protected $primaryKey = 'id';

    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'chat_id',
        'jid_type',
        'contact_name',
        'whatsapp_name',
        'phone',
        'manual_phone',
        'status',
        'assigned_to',
        'last_message_at',
        'last_message_direction',
        'last_replied_by',
        'closed_at',
        'closed_by',
        'profile_updated_at',
        'profile_updated_by',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'chat_id'  => 'required|max_length[191]',
        'jid_type' => 'required|max_length[20]',
        'status'   => 'in_list[open,closed]',
    ];

    /**
     * Cari conversation berdasarkan chat_id (JID asli WhatsApp, apa
     * adanya) -- lewat tabel alias `conversation_identities`, BUKAN
     * lewat kolom conversations.chat_id secara langsung (lihat
     * resolveConversationId() untuk alasannya: satu conversation bisa
     * dikenali lewat lebih dari satu chat_id/JID).
     */
    public function findByChatId(string $chatId): ?array
    {
        $conversationId = (new ConversationIdentityModel())->findConversationIdByChatId($chatId);

        return $conversationId ? $this->find($conversationId) : null;
    }

    /**
     * Cari (atau buat) conversation untuk sebuah chat_id (JID),
     * dengan reconciliation Task Group 1.5: mencegah duplicate
     * conversation saat WhatsApp melaporkan NOMOR YANG SAMA lewat JID
     * berbeda (paling umum: @lid <-> @s.whatsapp.net setelah Gateway
     * reconnect). Dipakai bersama oleh InboxGatewayApi::messages()
     * (pesan masuk dari Gateway) dan Inbox::mulaiPercakapan() (kasir
     * mengetik nomor untuk memulai chat baru).
     *
     * URUTAN PENCARIAN (JANGAN diubah urutannya -- lihat
     * docs/aturan-bisnis-CHAT.md Section 11 & 12 untuk pembahasan penuh):
     *
     * 1. chat_id ini SUDAH dikenal (ada baris di
     *    conversation_identities) -> pakai conversation itu apa
     *    adanya. Jalur tercepat & PALING SERING kena -- pesan
     *    berikutnya dari JID yang sama, termasuk setelah Gateway
     *    restart (chat_id tidak berubah).
     *
     * 2. BELUM dikenal, TAPI $knownLid diisi -- HANYA BOLEH diisi
     *    kalau Gateway sendiri yang menanyakan LANGSUNG ke server
     *    WhatsApp (sock.onWhatsApp(), query USync resmi, BUKAN
     *    tebakan dari angka @lid) "JID @lid apa yang berkaitan dengan
     *    nomor PN yang baru datang ini". Kalau JID @lid itu SUDAH
     *    dikenal (sudah pernah jadi conversation tersendiri -- kasus
     *    LID-FIRST -> PN-LATER, lihat revisi di docblock class ini),
     *    chat_id PN baru ini ditempelkan sebagai alias TAMBAHAN ke
     *    conversation @lid itu (BUKAN bikin conversation baru),
     *    persis seperti langkah 3 di bawah.
     *
     * 3. BELUM dikenal (langkah 1 & 2 gagal), TAPI $canonicalPhone
     *    diisi -- HANYA BOLEH diisi kalau berasal dari JID
     *    @s.whatsapp.net ASLI yang di-derive Gateway, ATAU dari
     *    konfirmasi manual eksplisit kasir/admin lewat
     *    Inbox::konfirmasiNomorWhatsapp() (pemanggil bertanggung jawab
     *    memastikan ini; TIDAK PERNAH ditebak dari angka @lid, dan
     *    TIDAK PERNAH dari `manual_phone`/edit profil biasa -- lihat
     *    catatan phone vs manual_phone di atas) -> cari conversation
     *    lain yang `phone`-nya SUDAH cocok.
     *
     * Langkah 2 & 3 sama-sama berarti "chat_id baru ini adalah JID
     * WhatsApp yang SAMA dengan conversation yang sudah ada" -> chat_id
     * baru didaftarkan sebagai alias TAMBAHAN (histori pesan lama
     * tetap utuh, TIDAK ADA yang dihapus/dipindah), dan
     * conversations.chat_id dimutakhirkan ke JID baru ini (dianggap
     * lebih bisa diandalkan untuk kirim balasan berikutnya) -- JID
     * lama TETAP ada di conversation_identities (kalau muncul lagi
     * nanti, tetap dikenali lewat langkah 1).
     *
     * 4. Ketiganya gagal -> identity yang benar-benar baru -> buat
     *    conversation baru + 1 baris alias untuk chat_id ini.
     *
     * SENGAJA TIDAK PERNAH mencocokkan berdasarkan nama
     * (contact_name/whatsapp_name) -- mencegah auto-merge yang salah
     * hanya karena nama kebetulan sama (aturan bisnis: "jangan
     * auto-merge history secara agresif").
     *
     * @param string|null $knownLid JID @lid yang di-resolve Gateway
     *   dari onWhatsApp() untuk pesan jid_type='pn' (opsional -- null
     *   kalau tidak tersedia/gagal/tidak relevan). TIDAK PERNAH diisi
     *   untuk pesan yang jid_type-nya sendiri 'lid' (tidak ada
     *   gunanya, lihat docblock class).
     * @return array{conversation_id: int, created: bool, reconciled: bool}
     *   created=true kalau conversation baru dibuat (langkah 4).
     *   reconciled=true kalau chat_id ini "ditempelkan" ke
     *   conversation LAMA lewat langkah 2/3 -- berguna untuk
     *   logging/observability.
     */
    public function resolveConversationId(string $chatId, string $jidType, ?string $canonicalPhone, ?string $whatsappName = null, ?string $knownLid = null): array
    {
        $identityModel = new ConversationIdentityModel();

        // --- Langkah 1: chat_id sudah dikenal --------------------------
        $existingId = $identityModel->findConversationIdByChatId($chatId);
        if ($existingId !== null) {
            return ['conversation_id' => $existingId, 'created' => false, 'reconciled' => false];
        }

        // --- Langkah 2: cocokkan lewat LID yang di-resolve Gateway -----
        // (revisi LID-FIRST -> PN-LATER -- lihat docblock class/method)
        if ($knownLid !== null) {
            $existingConversationId = $identityModel->findConversationIdByChatId($knownLid);

            if ($existingConversationId !== null) {
                return $this->attachAliasToConversation($identityModel, $existingConversationId, $chatId, $jidType);
            }
        }

        // --- Langkah 3: cocokkan lewat nomor ter-verifikasi ------------
        if ($canonicalPhone !== null) {
            $existing = $this->where('phone', $canonicalPhone)->first();

            if ($existing) {
                return $this->attachAliasToConversation($identityModel, (int) $existing['id'], $chatId, $jidType);
            }
        }

        // --- Langkah 4: benar-benar baru ---------------------------------
        $this->insert([
            'chat_id'       => $chatId,
            'jid_type'      => $jidType,
            'contact_name'  => null,
            'whatsapp_name' => $whatsappName,
            'phone'         => $canonicalPhone,
            'manual_phone'  => null,
            'status'        => 'open',
            'assigned_to'   => null,
        ]);
        $newId = $this->getInsertID();

        $identityModel->insert([
            'conversation_id' => $newId,
            'chat_id'         => $chatId,
            'jid_type'        => $jidType,
        ]);

        return ['conversation_id' => $newId, 'created' => true, 'reconciled' => false];
    }

    /**
     * Helper bersama untuk langkah 2 & 3 resolveConversationId():
     * tempelkan $chatId sebagai alias TAMBAHAN ke conversation yang
     * SUDAH ADA ($conversationId), dan mutakhirkan conversations.chat_id
     * ke JID baru ini. Tidak pernah menghapus/mengubah alias lama.
     */
    private function attachAliasToConversation(ConversationIdentityModel $identityModel, int $conversationId, string $chatId, string $jidType): array
    {
        $identityModel->insert([
            'conversation_id' => $conversationId,
            'chat_id'         => $chatId,
            'jid_type'        => $jidType,
        ]);

        $this->update($conversationId, ['chat_id' => $chatId, 'jid_type' => $jidType]);

        return ['conversation_id' => $conversationId, 'created' => false, 'reconciled' => true];
    }
}
