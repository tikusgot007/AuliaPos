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
     * Ambil pesan-pesan milik satu conversation, urut lama -> baru
     * (urutan wajar untuk ditampilkan di UI chat).
     *
     * Tie-breaker WAJIB: `message_timestamp` berpresisi detik, jadi dua
     * pesan yang dikirim dalam detik yang sama punya sort key identik.
     * `id` ASC (= urutan insert = urutan pengiriman Gateway) membuat
     * urutannya deterministik dan menjadi kontrak query, bukan efek
     * samping dari execution plan index komposit
     * (bugfix plan: plan-bugfix-inbox-message-ordering-v1.0.md).
     */
    public function getByConversation(int $conversationId, int $limit = 200): array
    {
        return $this->where('conversation_id', $conversationId)
            ->orderBy('message_timestamp', 'ASC')
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->findAll();
    }
}
