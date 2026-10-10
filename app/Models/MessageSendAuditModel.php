<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * L3: audit kiriman keluar yang gagal (append-only).
 *
 * Model ini SENGAJA tidak memakai timestamps otomatis ($useTimestamps=false)
 * karena tabel hanya punya `created_at` (tanpa `updated_at`); `created_at`
 * diisi di `insertAudit()`.
 *
 * `insertAudit()` tidak pernah melempar keluar: kegagalan tulis audit dicatat
 * lewat log_message('error') dan fungsi mengembalikan false. Audit yang gagal
 * TIDAK BOLEH menjatuhkan alur kirim (respons ke kasir tetap yang asli).
 *
 * Kolom `preview_text`/`media_*` (migrasi 2026-10-10, TODO-L3): pratinjau
 * RINGAN isi pesan/media yang gagal -- bukan byte media (hanya metadata),
 * supaya halaman "Log Kiriman Gagal" bisa menunjukkan apa yang gagal tanpa
 * membesarkan DB atau menyimpan konten sensitif berulang.
 */
class MessageSendAuditModel extends Model
{
    protected $DBGroup          = 'inbox';
    protected $table            = 'message_send_audit';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'operation_id',
        'conversation_id',
        'direction',
        'outcome_kind',
        'error_code',
        'error_message',
        'http_code',
        'context',
        'user_id',
        'preview_text',
        'media_type',
        'media_file_name',
        'media_size',
        'created_at',
    ];

    /** Batas panjang `preview_text` (harus cocok constraint kolom VARCHAR(1000)). */
    public const PREVIEW_TEXT_MAX_LENGTH = 1000;

    /**
     * Tulis satu baris audit. Mengisi `created_at`/`direction` default bila
     * kosong. Menangkap SEMUA throwable: kegagalan audit hanya di-log, tidak
     * diteruskan ke pemanggil.
     */
    public function insertAudit(array $data): bool
    {
        if (empty($data['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }
        if (empty($data['direction'])) {
            $data['direction'] = 'outgoing';
        }

        if (isset($data['preview_text']) && is_string($data['preview_text'])) {
            $data['preview_text'] = mb_substr($data['preview_text'], 0, self::PREVIEW_TEXT_MAX_LENGTH);
        }

        try {
            $insertId = $this->insert($data);

            return $insertId !== false;
        } catch (\Throwable $e) {
            log_message('error', 'MessageSendAuditModel::insertAudit gagal: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Daftar audit untuk halaman "Log Kiriman Gagal" (read-only, admin),
     * dengan filter opsional rentang tanggal, join ke `conversations` (DB
     * `inbox` yang sama -- aman di-JOIN SQL langsung) untuk nama/nomor WA
     * tujuan. `user_id` (nama kasir) adalah logical reference ke DB LAIN
     * (`aulia_kasirdb.users`) -- TIDAK bisa di-JOIN SQL, harus di-resolve
     * terpisah oleh pemanggil (lihat `Inbox.php`/`ConversationModel.php`
     * untuk pola yang sama).
     *
     * @return array<int, array<string, mixed>>
     */
    public function daftarUntukLog(?string $tanggalMulai, ?string $tanggalSampai, int $limit, int $offset): array
    {
        $builder = $this->db->table('message_send_audit')
            ->select('message_send_audit.*, conversations.chat_id, conversations.contact_name, conversations.whatsapp_name, conversations.phone, conversations.group_name')
            ->join('conversations', 'conversations.id = message_send_audit.conversation_id', 'left');

        if ($tanggalMulai !== null) {
            $builder->where('message_send_audit.created_at >=', $tanggalMulai . ' 00:00:00');
        }
        if ($tanggalSampai !== null) {
            $builder->where('message_send_audit.created_at <=', $tanggalSampai . ' 23:59:59');
        }

        return $builder->orderBy('message_send_audit.created_at', 'DESC')
            ->get($limit, $offset)
            ->getResultArray();
    }

    /**
     * Hitung total baris untuk pagination, dengan filter rentang tanggal
     * yang SAMA dengan {@see daftarUntukLog()}.
     */
    public function hitungUntukLog(?string $tanggalMulai, ?string $tanggalSampai): int
    {
        $builder = $this->db->table('message_send_audit');

        if ($tanggalMulai !== null) {
            $builder->where('created_at >=', $tanggalMulai . ' 00:00:00');
        }
        if ($tanggalSampai !== null) {
            $builder->where('created_at <=', $tanggalSampai . ' 23:59:59');
        }

        return (int) $builder->countAllResults();
    }
}
