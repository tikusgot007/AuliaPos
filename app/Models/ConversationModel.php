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
 * Field assigned_to, last_replied_by, closed_by adalah LOGICAL
 * REFERENCE ke aulia_kasirdb.users.id (bukan FK database sungguhan,
 * lihat migration 2026-09-07-000001_CreateInboxTables). Kalau perlu
 * menampilkan nama user dari ID-ID ini, ambil terpisah lewat
 * `App\Models\UserModel` (koneksi default), lalu gabungkan di
 * controller/view -- BUKAN lewat JOIN SQL (tidak mungkin, beda
 * database).
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
        'phone',
        'status',
        'assigned_to',
        'last_message_at',
        'last_message_direction',
        'last_replied_by',
        'closed_at',
        'closed_by',
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
     * Cari (atau ambil) conversation berdasarkan chat_id (JID asli
     * WhatsApp, apa adanya -- tidak pernah dinormalisasi jadi nomor
     * telepon di layer ini).
     */
    public function findByChatId(string $chatId): ?array
    {
        return $this->where('chat_id', $chatId)->first();
    }
}
