<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model untuk tabel `conversation_identities` di database
 * aulia_inboxdb -- Task Group 1.5 (Customer Identity & Conversation
 * Reconciliation).
 *
 * Alias many-to-one dari chat_id (JID WhatsApp) ke conversation_id.
 * Satu conversation bisa punya LEBIH dari satu baris di sini (mis.
 * @lid lama + @s.whatsapp.net baru yang terbukti nomor yang sama,
 * lihat ConversationModel::resolveConversationId()) -- ini yang
 * menggantikan pencarian langsung ke conversations.chat_id supaya
 * JID lama tidak pernah "hilang" dari pengenalan walau conversation
 * sudah punya chat_id aktif yang baru.
 *
 * PENTING: $DBGroup eksplisit 'inbox', sama seperti model inbox
 * lainnya -- tidak pernah mengandalkan default group.
 */
class ConversationIdentityModel extends Model
{
    protected $DBGroup    = 'inbox';
    protected $table      = 'conversation_identities';
    protected $primaryKey = 'id';

    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'conversation_id',
        'chat_id',
        'jid_type',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = ''; // tabel ini tidak punya kolom updated_at -- baris alias tidak pernah diubah, cuma ditambah

    protected $validationRules = [
        'conversation_id' => 'required|integer',
        'chat_id'         => 'required|max_length[191]',
        'jid_type'        => 'required|max_length[20]',
    ];

    /**
     * Cari conversation_id pemilik sebuah chat_id (JID), lewat tabel
     * alias ini -- BUKAN lewat conversations.chat_id langsung.
     */
    public function findConversationIdByChatId(string $chatId): ?int
    {
        $row = $this->where('chat_id', $chatId)->first();

        return $row ? (int) $row['conversation_id'] : null;
    }
}
