<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * TODO-BL10: append-only audit trail for `cash_expense` update/delete.
 * See docs/design/2026-10-08-audit-validasi-kas-keluar.md Section 3/4.
 */
class CashExpenseAuditModel extends Model
{
    protected $table         = 'cash_expense_audit';
    protected $primaryKey    = 'id';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'expense_id',
        'aksi',
        'data_sebelum',
        'data_sesudah',
        'user_id',
        'created_at',
    ];

    /**
     * Record an update: full before/after row snapshot as JSON.
     *
     * @param array<string,mixed> $sebelum Full `cash_expense` row before the update.
     * @param array<string,mixed> $sesudah Full `cash_expense` row after the update.
     */
    public function catatUpdate(int $expenseId, array $sebelum, array $sesudah, int $userId): bool
    {
        return (bool) $this->insert([
            'expense_id'   => $expenseId,
            'aksi'         => 'update',
            'data_sebelum' => json_encode($sebelum),
            'data_sesudah' => json_encode($sesudah),
            'user_id'      => $userId,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Record a delete: before-only row snapshot as JSON (`data_sesudah` NULL).
     *
     * @param array<string,mixed> $sebelum Full `cash_expense` row before the delete.
     */
    public function catatHapus(int $expenseId, array $sebelum, int $userId): bool
    {
        return (bool) $this->insert([
            'expense_id'   => $expenseId,
            'aksi'         => 'delete',
            'data_sebelum' => json_encode($sebelum),
            'data_sesudah' => null,
            'user_id'      => $userId,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }
}
