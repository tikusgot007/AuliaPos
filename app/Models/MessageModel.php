<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model untuk tabel `messages` di database aulia_inboxdb.
 *
 * PENTING: $DBGroup eksplisit di-set ke 'inbox', sama seperti
 * ConversationModel -- lihat catatan lengkap di sana.
 *
 * Tabel ini hanya punya `created_at` (TIDAK ada `updated_at` --
 * pesan yang sudah masuk/terkirim tidak pernah diubah lagi, sesuai
 * spec). $updatedField sengaja dikosongkan supaya CodeIgniter tidak
 * mencoba menulis ke kolom yang tidak ada.
 *
 * `sent_by_user_id` adalah LOGICAL REFERENCE ke
 * aulia_kasirdb.users.id (bukan FK sungguhan) -- lihat migration.
 */
class MessageModel extends Model
{
    protected $DBGroup    = 'inbox';
    protected $table      = 'messages';
    protected $primaryKey = 'id';

    protected $useAutoIncrement = true;
    protected $returnType       = 'array';

    /**
     * Tahap D -- soft-delete, konsisten dengan ConversationModel (lihat
     * catatan lengkap di sana). Inbox::hapusPercakapan() saat ini HANYA
     * soft-delete baris conversations, TIDAK menyentuh baris messages
     * (tetap deleted_at=NULL) -- kolom ini disiapkan untuk konsistensi
     * skema/kemungkinan penghapusan pesan individual di masa depan,
     * bukan dipakai aktif oleh alur hapus percakapan sekarang.
     */
    protected $useSoftDeletes = true;
    protected $deletedField   = 'deleted_at';

    protected $allowedFields = [
        'conversation_id',
        'wa_message_id',
        'direction',
        'message_type',
        'sender_jid',
        'text',
        'media_path',
        'media_mime_type',
        'media_filename',
        'media_size',
        'media_sha256',
        'media_metadata',
        'media_local_filename',
        'media_download_attempted_at',
        'media_confirmed_gone_at',
        'message_timestamp',
        'sent_by_user_id',
        'send_status',
        // WhatsApp read receipt (dua arah): status kirim dari Evolution.
        // Nullable; HANYA diisi via markDelivered()/markRead() (idempotent,
        // first-seen/monotonic). Lihat migration 2026-10-07-000001.
        'delivered_at',
        'read_at',
        'is_internal',
        'gateway_operation_id',
        // Balas Pesan (Tahap 3, spec Section 4.2): snapshot kutipan pada baris
        // pesan itu sendiri. Nullable -- baris yang bukan balasan tetap NULL.
        'quoted_wa_message_id',
        'quoted_sender_label',
        'quoted_snippet',
        'quoted_media_available',
        // v1.6 (REQ-008b): ID LOKAL `messages.id` pesan sumber, snapshot beku
        // -- target `GET /inbox/media/(:num)` untuk fallback tampilan REQ-008.
        'quoted_source_message_id',
        // v1.7 (REQ-008c): `message_type` sumber media (image/document/
        // sticker/audio/video) -- UI memilih representasi kutipan per tipe,
        // bukan menebak dari `quoted_snippet`. NULL untuk teks/legacy.
        'quoted_media_type',
        // Teruskan (Tahap 4, spec Section 4.2): penanda tunggal hasil aksi
        // Teruskan. 0 = baris biasa, 1 = pesan diteruskan. Label "Diteruskan"
        // dibangun dari kolom ini SAJA (REQ-008), bukan dari
        // `forward_marker_applied` Gateway.
        'is_forwarded',
        // Tahap 4: data terstruktur untuk pesan non-file (lokasi & kontak),
        // JSON. Nullable -- baris tipe lain tetap NULL.
        'extra_json',
        // TODO-F7: penanda lifecycle pesan -- pesan ASLI yang diedit/dihapus
        // pelanggan di WhatsApp. Nullable; diisi SEKALI via markLifecycle().
        // Lihat migration 2026-10-04-000001_AddEditedRevokedToMessages.
        'edited_at',
        'edited_text_resolved_at',
        'revoked_at',
        'deleted_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = ''; // tabel ini tidak punya kolom updated_at

    protected $validationRules = [
        'conversation_id'   => 'required|integer',
        'wa_message_id'     => 'required|max_length[255]',
        'direction'         => 'required|in_list[incoming,outgoing]',
        'message_type'      => 'required|max_length[30]',
        'message_timestamp' => 'required|valid_date',
        'send_status'       => 'required|in_list[received,sent,failed]',
    ];

    /**
     * Cek apakah sebuah wa_message_id sudah pernah tersimpan --
     * dipakai untuk memastikan idempotency incoming message (lihat
     * spec Section "CI4 Incoming API": pesan dengan wa_message_id
     * yang sama tidak boleh membuat baris kedua).
     */
    public function existsByWaMessageId(string $waMessageId): bool
    {
        return $this->where('wa_message_id', $waMessageId)->countAllResults() > 0;
    }

    /**
     * Ambil satu baris `messages` berdasarkan `wa_message_id`, atau null.
     *
     * Pemakaian (Balas Pesan, Tahap 3): resolusi kutipan MASUK pada
     * `InboxGatewayApi::messages()` (REQ-011) mencari pesan yang dikutip lewat
     * ID yang sama dengan yang dipakai pengecekan idempotensi di atas, tapi
     * butuh BARIS-nya (bukan hanya boolean) untuk membentuk snapshot kutipan
     * dari data lokal.
     *
     * SENGAJA memakai query builder TANPA filter `deleted_at`, bukan
     * `find()`/`first()` polos: model ini memakai `useSoftDeletes` (lihat
     * properti di atas), jadi pencarian biasa diam-diam mengabaikan baris
     * yang sudah di-soft-delete. Kalau baris sumber kutipan ikut hilang dari
     * hasil pencarian, snapshot kutipan gagal terbentuk tanpa suara --
     * kutipan tampil kosong padahal datanya ada (spec REQ-011, Section 12).
     * Pola yang sama dipakai `findByOperationIdIncludingDeleted()` di bawah.
     *
     * @return array<string, mixed>|null
     */
    public function findByWaMessageId(string $waMessageId): ?array
    {
        if ($waMessageId === '') {
            return null;
        }

        $rows = db_connect('inbox')->table('messages')
            ->where('wa_message_id', $waMessageId)
            ->get()
            ->getResultArray();

        return $rows[0] ?? null;
    }

    /**
     * TODO-F7: tandai pesan ASLI (dicari via `wa_message_id`) sebagai "diedit"
     * atau "dihapus pelanggan". Dipanggil endpoint gateway `message-event`.
     *
     * IDEMPOTEN: kolom hanya diisi bila masih NULL, jadi edit/hapus berulang dan
     * retry Gateway tidak mengubah nilai pertama.
     *
     * SENGAJA pakai query builder (bukan `update()` model) supaya tidak
     * tersentuh soft-delete/timestamp, dan tetap soft-delete-inclusive seperti
     * `findByWaMessageId()` -- pesan yang sudah di-soft-delete tetap ditandai.
     *
     * @param string $waMessageId
     * @param string $event 'edited' atau 'deleted' (sudah divalidasi controller)
     * @return bool true bila pesan asli ADA (baris ditemukan); false bila tidak.
     */
    /**
     * TODO-F8: simpan teks hasil dekripsi MESSAGE_EDIT pada baris pesan ASLI.
     *
     * edited_at tetap first-seen/idempoten, sedangkan text sengaja mengikuti
     * hasil edit TERAKHIR yang sudah tervalidasi. Update tidak membuat baris
     * baru dan tidak menyentuh revoked_at.
     */
    public function updateEditedText(string $waMessageId, string $text): bool
    {
        if ($waMessageId === '') {
            return false;
        }

        $db = db_connect('inbox');
        $table = $db->table('messages');
        $exists = $table
            ->where('wa_message_id', $waMessageId)
            ->countAllResults() > 0;

        if (! $exists) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $table
            ->where('wa_message_id', $waMessageId)
            ->set('text', $text)
            ->set('edited_at', 'COALESCE(edited_at, ' . $db->escape($now) . ')', false)
            ->set('edited_text_resolved_at', $now)
            ->update();

        return true;
    }
    public function markLifecycle(string $waMessageId, string $event): bool
    {
        if ($waMessageId === '') {
            return false;
        }

        $column = $event === 'deleted' ? 'revoked_at' : 'edited_at';

        $exists = db_connect('inbox')->table('messages')
            ->where('wa_message_id', $waMessageId)
            ->countAllResults() > 0;

        if (! $exists) {
            return false;
        }

        db_connect('inbox')->table('messages')
            ->where('wa_message_id', $waMessageId)
            ->where($column, null)
            ->update([$column => date('Y-m-d H:i:s')]);

        return true;
    }

    /**
     * WhatsApp read receipt (Arah 2): tandai pesan KELUAR sebagai "sampai ke
     * HP pelanggan" (DELIVERY_ACK Evolution).
     *
     * Menerima ID lokal baris (`messages.id`) -- pemanggil sudah memegang
     * barisnya, jadi TIDAK ada SELECT kedua.
     *
     * IDEMPOTEN & MAJU: kolom hanya diisi bila masih NULL, jadi event
     * delivered berulang / retry Gateway tidak mengubah nilai pertama.
     */
    public function markDelivered(int $messageId, ?string $at = null): void
    {
        db_connect('inbox')->table('messages')
            ->where('id', $messageId)
            ->where('delivered_at', null)
            ->update(['delivered_at' => $at ?? date('Y-m-d H:i:s')]);
    }

    /**
     * WhatsApp read receipt (Arah 2): tandai pesan KELUAR sebagai "dibaca
     * pelanggan" (READ Evolution). Read menyiratkan delivered, jadi
     * delivered_at diisi lebih dulu bila masih kosong.
     *
     * Menerima ID lokal baris (`messages.id`) -- pemanggil sudah memegang
     * barisnya, jadi TIDAK ada SELECT kedua.
     *
     * IDEMPOTEN & MAJU: hanya diisi bila masih NULL.
     */
    public function markRead(int $messageId, ?string $at = null): void
    {
        $at = $at ?? date('Y-m-d H:i:s');
        $db = db_connect('inbox');

        $db->table('messages')
            ->where('id', $messageId)
            ->where('delivered_at', null)
            ->update(['delivered_at' => $at]);

        $db->table('messages')
            ->where('id', $messageId)
            ->where('read_at', null)
            ->update(['read_at' => $at]);
    }

    /**
     * WhatsApp read receipt (Arah 1): daftar `wa_message_id` pesan MASUK pada
     * satu percakapan yang layak dikirim ke Evolution untuk ditandai dibaca.
     *
     * Hanya ID WhatsApp asli yang dikembalikan -- placeholder lokal
     * (`local-`/`internal-`) dan ID sintetis adapter (`evolution:`/`status:`
     * /`lifecycle:`) DIBUANG karena Evolution tidak mengenalinya.
     *
     * @return array<int, string>
     */
    public function incomingWaMessageIdsForConversation(int $conversationId, int $limit = 200): array
    {
        $limit = max(1, min($limit, 500));

        $rows = db_connect('inbox')->table('messages')
            ->select('wa_message_id')
            ->where('conversation_id', $conversationId)
            ->where('direction', 'incoming')
            ->where('wa_message_id IS NOT NULL', null, false)
            ->notLike('wa_message_id', 'local-', 'after')
            ->notLike('wa_message_id', 'internal-', 'after')
            ->notLike('wa_message_id', 'evolution:%', 'after')
            ->notLike('wa_message_id', 'status:%', 'after')
            ->notLike('wa_message_id', 'lifecycle:%', 'after')
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        return array_values(array_unique(array_map(
            static fn(array $row): string => (string) $row['wa_message_id'],
            $rows
        )));
    }

    /**
     * Ambil satu baris `messages` berdasarkan ID lokal, TERMASUK baris yang
     * sudah soft-deleted (ARCH-001).
     *
     * Lookup kutipan pesan sumber (`Inbox::resolveKutipan()`) WAJIB
     * soft-delete-inclusive: kutipan dari pesan yang sudah di-soft-delete tetap
     * harus terbentuk (AC-004). `find()`/`first()` polos pada model ini
     * DILARANG karena `useSoftDeletes` menyaring baris tersebut tanpa suara
     * (spec REQ-008b/REQ-011, Section 12). Pola sama dengan
     * `findByWaMessageId()` di atas.
     *
     * @return array<string, mixed>|null
     */
    public function findByIdIncludingDeleted(int $id): ?array
    {
        $rows = db_connect('inbox')->table('messages')
            ->where('id', $id)
            ->get()
            ->getResultArray();

        return $rows[0] ?? null;
    }

    /**
     * M1 Wave 2 (TASK-017/AC-041; ARCH-001 review ronde-2 Tahap 3): cari
     * baris `messages` yang sudah menyimpan operasi kirim ini, supaya
     * percobaan ulang (`replay` Gateway) dengan `operation_id` yang sama
     * tidak menulis baris kedua.
     *
     * Dipakai BERSAMA oleh `Inbox::kirimKeConversation()` dan
     * `Inbox::kirimMedia()` -- pemilik satu method ini di Model mencegah
     * kedua jalur menyimpan salinannya sendiri dan drift lagi (temuan F-03).
     *
     * SENGAJA memakai query builder TANPA filter `deleted_at`, bukan
     * `find()`/`first()` polos: indeks UNIQUE
     * `uniq_messages_gateway_operation_id` juga mencakup baris yang sudah
     * di-soft-delete, jadi baris itu WAJIB dikembalikan -- kalau tidak,
     * insert berikutnya justru ditolak database (pola sama dengan
     * `findByWaMessageId()`/`findByIdIncludingDeleted()`).
     *
     * `conversation_id` WAJIB disertakan (scope percakapan): `operation_id`
     * hanya unik per operasi kirim, dan lookup tanpa scope bisa mengembalikan
     * baris percakapan LAIN dengan `operation_id` kebetulan sama -- replay
     * lintas percakapan yang salah (ARCH-001 review ronde-2).
     *
     * @return array<string, mixed>|null
     */
    public function findByOperationIdIncludingDeleted(?string $operationId, int $conversationId): ?array
    {
        if ($operationId === null || $operationId === '') {
            return null;
        }

        $rows = db_connect('inbox')->table('messages')
            ->where('gateway_operation_id', $operationId)
            ->where('conversation_id', $conversationId)
            ->get()
            ->getResultArray();

        return $rows[0] ?? null;
    }

    /**
     * Satu halaman pesan milik satu conversation: `$limit` pesan TERBARU
     * (atau `$limit` pesan tepat sebelum kursor `$beforeId`), dikembalikan
     * urut lama -> baru (urutan wajar untuk ditampilkan di UI chat).
     *
     * Tie-breaker WAJIB: `message_timestamp` berpresisi detik, jadi dua
     * pesan yang dikirim dalam detik yang sama punya sort key identik.
     * `id` ASC (= urutan insert = urutan pengiriman Gateway) membuat
     * urutannya deterministik dan menjadi kontrak query, bukan efek
     * samping dari execution plan index komposit
     * (bugfix plan: plan-bugfix-inbox-message-ordering-v1.0.md).
     *
     * Kursor memakai kunci urutan yang SAMA `(message_timestamp, id)`, bukan
     * `id` saja: Gateway bisa menyimpan pesan terlambat yang timestamp-nya
     * lebih lama daripada pesan yang sudah ada, jadi batas halaman berbasis
     * `id` akan melewatkan atau menggandakan pesan
     * (docs/design/2026-10-02-perbaikan-thread-inbox.md).
     *
     * Query diambil DESC lalu dibalik supaya yang terpotong adalah pesan
     * TERTUA, bukan terbaru (bug lama: ASC + limit mengambil yang tertua).
     * Satu baris ekstra diambil hanya untuk menentukan `has_more`.
     *
     * @return array{messages: array<int, array<string, mixed>>, has_more: bool}|null
     *         null bila `$beforeId` bukan pesan milik conversation ini.
     */
    public function getPageByConversation(int $conversationId, int $limit, ?int $beforeId = null): ?array
    {
        $cursor = null;

        if ($beforeId !== null) {
            // withDeleted(): kursor hanya penanda posisi; pesan yang sudah
            // di-soft-delete tidak boleh membuat klien kehilangan jalur ke
            // riwayat yang lebih lama.
            $cursor = $this->withDeleted()
                ->select('id, message_timestamp')
                ->where('conversation_id', $conversationId)
                ->where('id', $beforeId)
                ->first();

            if ($cursor === null) {
                return null;
            }
        }

        $this->where('conversation_id', $conversationId);

        if ($cursor !== null) {
            $this->groupStart()
                ->where('message_timestamp <', $cursor['message_timestamp'])
                ->orGroupStart()
                    ->where('message_timestamp', $cursor['message_timestamp'])
                    ->where('id <', $cursor['id'])
                ->groupEnd()
                ->groupEnd();
        }

        $rows = $this->orderBy('message_timestamp', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit($limit + 1)
            ->findAll();

        return [
            'messages' => array_reverse(array_slice($rows, 0, $limit)),
            'has_more' => count($rows) > $limit,
        ];
    }
}
