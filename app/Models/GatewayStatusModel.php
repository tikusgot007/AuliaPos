<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model untuk tabel `gateway_status` di database aulia_inboxdb.
 *
 * Tabel ini SELALU berisi 1 baris saja (id=1), merepresentasikan
 * status Gateway WhatsApp tunggal yang dipakai toko. Tidak ada
 * multi-gateway di POC ini.
 *
 * PENTING: $DBGroup eksplisit di-set ke 'inbox' -- lihat catatan
 * lengkap di ConversationModel.
 */
class GatewayStatusModel extends Model
{
    protected $DBGroup    = 'inbox';
    protected $table      = 'gateway_status';
    protected $primaryKey = 'id';

    // id bukan auto_increment (selalu manual = 1), lihat migration.
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;

    protected $allowedFields = [
        'id',
        'status',
        'phone',
        'gateway_version',
        'last_heartbeat_at',
        'last_connected_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = ''; // tabel ini tidak punya kolom created_at
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'status' => 'required|max_length[20]',
    ];

    /** ID baris tunggal gateway status. */
    public const SINGLETON_ID = 1;

    /**
     * Ambil status gateway saat ini. Mengembalikan null kalau
     * gateway belum pernah heartbeat sama sekali (baris id=1 belum
     * pernah dibuat) -- pemanggil harus menganggap ini sebagai
     * "disconnected"/"tidak diketahui", bukan error.
     */
    public function getStatus(): ?array
    {
        return $this->find(self::SINGLETON_ID);
    }

    /**
     * Cek apakah Gateway "usable" untuk mengirim pesan saat ini --
     * dipakai sebelum CI4 mencoba memanggil Gateway (Phase 3:
     * outgoing), supaya kalau Gateway jelas-jelas tidak bisa dipakai,
     * CI4 langsung menolak cepat TANPA menunggu timeout HTTP
     * (sesuai spec: "Gateway offline => reject segera").
     *
     * Syarat usable: baris gateway_status ada, status='connected',
     * DAN heartbeat terakhir belum "basi" (lihat
     * Config\Inbox::$heartbeatStaleSeconds) -- baris yang bilang
     * 'connected' tapi heartbeat-nya sudah lama tidak update berarti
     * kemungkinan Gateway sudah mati/crash tanpa sempat lapor status
     * terakhirnya.
     */
    public function isUsable(): bool
    {
        $status = $this->getStatus();

        if (!$status || ($status['status'] ?? null) !== 'connected') {
            return false;
        }

        if (empty($status['last_heartbeat_at'])) {
            return false;
        }

        $staleSeconds = (new \Config\Inbox())->heartbeatStaleSeconds;

        // Eksplisit interpretasikan last_heartbeat_at sebagai
        // Asia/Jakarta -- konsisten dengan cara nilai itu DISIMPAN
        // (lihat InboxGatewayApi::status(), yang sekarang eksplisit
        // pakai WIB, bukan date() bawaan). Kalau di sini pakai
        // strtotime() biasa (bergantung timezone default PHP di
        // server, belum tentu WIB), perhitungan usia heartbeat bisa
        // salah sampai berjam-jam.
        $heartbeatAt = new \DateTime($status['last_heartbeat_at'], new \DateTimeZone('Asia/Jakarta'));
        $now         = new \DateTime('now', new \DateTimeZone('Asia/Jakarta'));
        $ageSeconds  = $now->getTimestamp() - $heartbeatAt->getTimestamp();

        return $ageSeconds <= $staleSeconds;
    }

    /**
     * Upsert status gateway (insert kalau baris id=1 belum ada,
     * update kalau sudah ada). Dipakai oleh endpoint heartbeat
     * (Phase 2).
     */
    public function upsertStatus(array $data): bool
    {
        $data['id'] = self::SINGLETON_ID;

        if ($this->find(self::SINGLETON_ID)) {
            return (bool) $this->update(self::SINGLETON_ID, $data);
        }

        return (bool) $this->insert($data, false) !== false;
    }
}
