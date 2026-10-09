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
        'created_at',
    ];

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

        try {
            $insertId = $this->insert($data);

            return $insertId !== false;
        } catch (\Throwable $e) {
            log_message('error', 'MessageSendAuditModel::insertAudit gagal: ' . $e->getMessage());

            return false;
        }
    }
}
