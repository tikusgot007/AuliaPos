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
 * - `phone` = nomor TER-VERIFIKASI, HANYA diisi dari JID
 *   `@s.whatsapp.net` asli yang di-derive Gateway. SATU-SATUNYA kolom
 *   yang dipakai untuk reconciliation (lihat resolveConversationId())
 *   -- TIDAK PERNAH diisi dari input manual, supaya tidak ada
 *   auto-merge yang salah hanya karena kasir mengetik nomor yang
 *   keliru.
 * - `manual_phone` = nomor yang diketik MANUAL kasir, informasional
 *   saja, TIDAK PERNAH dipakai untuk mencari/menggabungkan
 *   conversation.
 *
 * `conversation_identities` (tabel terpisah, lihat
 * ConversationIdentityModel) menyimpan SEMUA JID (chat_id) yang
 * pernah dikenali sebagai milik satu conversation -- `chat_id` di
 * tabel ini TETAP jadi "JID aktif saat ini" (dipakai untuk kirim
 * balasan), tapi JID lama tidak pernah dilupakan.
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
     * docs/aturan-bisnis-CHAT.md Section 11 untuk pembahasan penuh):
     *
     * 1. chat_id ini SUDAH dikenal (ada baris di
     *    conversation_identities) -> pakai conversation itu apa
     *    adanya. Jalur tercepat & PALING SERING kena -- pesan
     *    berikutnya dari JID yang sama, termasuk setelah Gateway
     *    restart (chat_id tidak berubah).
     *
     * 2. BELUM dikenal, TAPI $canonicalPhone diisi -- HANYA BOLEH
     *    diisi kalau berasal dari JID @s.whatsapp.net ASLI yang
     *    di-derive Gateway (pemanggil bertanggung jawab memastikan
     *    ini; TIDAK PERNAH ditebak dari angka @lid, dan TIDAK PERNAH
     *    dari nomor yang diketik manual kasir lewat fitur edit
     *    profil -- lihat catatan phone vs manual_phone di atas) ->
     *    cari conversation lain yang `phone`-nya SUDAH cocok. Kalau
     *    ketemu, berarti ini nomor WhatsApp yang SAMA muncul dengan
     *    JID baru -> chat_id baru didaftarkan sebagai alias TAMBAHAN
     *    ke conversation yang sudah ada (BUKAN bikin conversation
     *    baru, histori pesan lama tetap utuh), dan
     *    conversations.chat_id dimutakhirkan ke JID baru ini (JID
     *    yang baru diverifikasi dianggap lebih bisa diandalkan untuk
     *    kirim balasan berikutnya dibanding JID lama yang mungkin
     *    sudah basi) -- JID lama TETAP ada di conversation_identities
     *    (kalau muncul lagi nanti, tetap dikenali lewat langkah 1).
     *
     * 3. Keduanya gagal -> identity yang benar-benar baru -> buat
     *    conversation baru + 1 baris alias untuk chat_id ini.
     *
     * SENGAJA TIDAK PERNAH mencocokkan berdasarkan nama
     * (contact_name/whatsapp_name) -- mencegah auto-merge yang salah
     * hanya karena nama kebetulan sama (aturan bisnis: "jangan
     * auto-merge history secara agresif").
     *
     * @return array{conversation_id: int, created: bool, reconciled: bool}
     *   created=true kalau conversation baru dibuat (langkah 3).
     *   reconciled=true kalau chat_id ini "ditempelkan" ke
     *   conversation LAMA lewat kecocokan nomor (langkah 2) --
     *   berguna untuk logging/observability.
     */
    public function resolveConversationId(string $chatId, string $jidType, ?string $canonicalPhone, ?string $whatsappName = null): array
    {
        $identityModel = new ConversationIdentityModel();

        // --- Langkah 1: chat_id sudah dikenal --------------------------
        $existingId = $identityModel->findConversationIdByChatId($chatId);
        if ($existingId !== null) {
            return ['conversation_id' => $existingId, 'created' => false, 'reconciled' => false];
        }

        // --- Langkah 2: cocokkan lewat nomor ter-verifikasi ------------
        if ($canonicalPhone !== null) {
            $existing = $this->where('phone', $canonicalPhone)->first();

            if ($existing) {
                $identityModel->insert([
                    'conversation_id' => $existing['id'],
                    'chat_id'         => $chatId,
                    'jid_type'        => $jidType,
                ]);

                $this->update($existing['id'], ['chat_id' => $chatId, 'jid_type' => $jidType]);

                return ['conversation_id' => (int) $existing['id'], 'created' => false, 'reconciled' => true];
            }
        }

        // --- Langkah 3: benar-benar baru ---------------------------------
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
}
