<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model untuk tabel `conversation_handoffs` di database aulia_inboxdb.
 *
 * PENTING: $DBGroup eksplisit 'inbox', sama seperti MessageModel /
 * ConversationModel -- riwayat Handoff tidak PERNAH boleh nyangkut ke
 * database AuliaPos utama.
 *
 * Tabel ini append-only (riwayat Handoff tidak pernah diubah/dihapus
 * lewat aplikasi), jadi:
 *  - $useTimestamps = false: created_at ditulis EKSPLISIT oleh
 *    controller dengan zona Asia/Jakarta (CON-H06), bukan diisi otomatis
 *    oleh CI4 (yang tidak aware zona).
 *  - Tidak ada soft-delete: hapus baris riwayat hanya lewat FK CASCADE
 *    saat percakapan induknya dihapus.
 *
 * Sengaja TANPA bisnis logika (eligibility, conditional write, transaksi
 * semuanya milik controller Inbox::handoffPercakapan) -- lihat Spec
 * Section 4.2.
 */
class ConversationHandoffModel extends Model
{
    protected $DBGroup    = 'inbox';
    protected $table      = 'conversation_handoffs';
    protected $primaryKey = 'id';

    protected $useAutoIncrement = true;
    protected $returnType       = 'array';

    protected $useSoftDeletes = false;
    protected $useTimestamps  = false; // created_at eksplisit, lihat catatan di atas

    protected $allowedFields = [
        'conversation_id',
        'from_user_id',
        'to_user_id',
        'initiated_by_user_id',
        'summary',
        'next_action',
        'note',
        'created_at',
    ];

    /**
     * Simpan satu baris riwayat Handoff, kembalikan id baris baru.
     *
     * $row WAJIB sudah memuat created_at (format 'Y-m-d H:i:s',
     * Asia/Jakarta) dari controller -- model ini sengaja tidak mengisi
     * timestamp sendiri.
     */
    public function insertHandoff(array $row): int
    {
        $this->insert($row);

        return (int) $this->getInsertID();
    }

    /**
     * Ambil riwayat Handoff milik satu percakapan, TERBARU DULU
     * (newest-first) -- index (conversation_id, id DESC) dibuat khusus
     * untuk query ini supaya tanpa filesort.
     *
     * Limit default 50: panel riwayat di UI hanya menampilkan 50
     * entri terakhir; permintaan riwayat lebih lama di luar scope
     * Fase 2a.
     */
    public function forConversation(int $conversationId, int $limit = 50): array
    {
        return $this->where('conversation_id', $conversationId)
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->findAll();
    }
}