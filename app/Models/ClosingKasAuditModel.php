<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * TODO-BL28: append-only revision trail for `closing_kas` edits. Mirrors
 * CashExpenseAuditModel (TODO-BL10): `data_sebelum`/`data_sesudah` hold the full
 * row as JSON, and only edits are recorded.
 */
class ClosingKasAuditModel extends Model
{
    protected $table         = 'closing_kas_audit';
    protected $primaryKey    = 'id';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'closing_id',
        'aksi',
        'data_sebelum',
        'data_sesudah',
        'user_id',
        'created_at',
    ];

    /**
     * Record an update of one closing row: full before/after row snapshot as JSON.
     *
     * @param array<string,mixed> $sebelum Full `closing_kas` row before the update.
     * @param array<string,mixed> $sesudah Full `closing_kas` row after the update.
     */
    public function catatUpdate(int $closingId, array $sebelum, array $sesudah, int $userId): bool
    {
        return (bool) $this->insert([
            'closing_id'   => $closingId,
            'aksi'         => 'update',
            'data_sebelum' => json_encode($sebelum),
            'data_sesudah' => json_encode($sesudah),
            'user_id'      => $userId,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Revision list for the admin page "Log Revisi Closing Kas" (read-only),
     * with an optional revision-time range. `user_id` lives in the same DEFAULT
     * database as `users`, so a direct SQL JOIN is safe.
     *
     * @return array<int, array<string, mixed>>
     */
    public function daftarUntukLog(?string $tanggalMulai, ?string $tanggalSampai, int $limit, int $offset): array
    {
        $builder = $this->select('closing_kas_audit.*, users.username, users.nama')
            ->join('users', 'users.id = closing_kas_audit.user_id', 'left');

        $this->terapkanFilterWaktu($builder, $tanggalMulai, $tanggalSampai);

        return $builder->orderBy('closing_kas_audit.created_at', 'DESC')
            ->findAll($limit, $offset);
    }

    /**
     * Row count for pagination, with the SAME filter as {@see daftarUntukLog()}.
     */
    public function hitungUntukLog(?string $tanggalMulai, ?string $tanggalSampai): int
    {
        $builder = $this->builder();
        $this->terapkanFilterWaktu($builder, $tanggalMulai, $tanggalSampai);

        return (int) $builder->countAllResults();
    }

    private function terapkanFilterWaktu($builder, ?string $tanggalMulai, ?string $tanggalSampai): void
    {
        if ($tanggalMulai !== null) {
            $builder->where('closing_kas_audit.created_at >=', $tanggalMulai . ' 00:00:00');
        }
        if ($tanggalSampai !== null) {
            $builder->where('closing_kas_audit.created_at <=', $tanggalSampai . ' 23:59:59');
        }
    }
}
