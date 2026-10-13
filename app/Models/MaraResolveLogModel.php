<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model untuk tabel `mara_resolve_log` di database aulia_inboxdb.
 * Lihat migration CreateMaraResolveLog untuk alasan kolom & tujuan
 * audit trail ini.
 */
class MaraResolveLogModel extends Model
{
    protected $DBGroup    = 'inbox';
    protected $table      = 'mara_resolve_log';
    protected $primaryKey = 'id';

    protected $useAutoIncrement = true;
    protected $returnType       = 'array';

    protected $useTimestamps = false; // created_at diisi manual (Asia/Jakarta eksplisit, konsisten pola lain)

    protected $allowedFields = [
        'conversation_id',
        'reason',
        'requested_from_ip',
        'created_at',
    ];
}