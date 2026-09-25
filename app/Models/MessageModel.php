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
     * Ambil pesan-pesan milik satu conversation, urut lama -> baru
     * (urutan wajar untuk ditampilkan di UI chat).
     *
     * Tie-breaker WAJIB: `message_timestamp` berpresisi detik, jadi dua
     * pesan yang dikirim dalam detik yang sama punya sort key identik.
     * `id` ASC (= urutan insert = urutan pengiriman Gateway) membuat
     * urutannya deterministik dan menjadi kontrak query, bukan efek
     * samping dari execution plan index komposit
     * (bugfix plan: plan/plan-bugfix-inbox-message-ordering-v1.0.md).
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
