<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Pencegahan insiden 2026-09-29: sesi WA Gateway sempat berstatus
 * 'connected' selama berjam-jam padahal diam-diam gagal memproses SEMUA
 * pesan masuk (sesi Signal Protocol corrupt/desync) -- tidak terdeteksi
 * sampai dilaporkan manual oleh kasir. Badge Inbox POS sebelumnya hanya
 * membaca kolom `status` (status SOCKET, bukan kesehatan fungsional sesi),
 * jadi tetap menampilkan "Terhubung" sepanjang insiden itu.
 *
 * Kolom baru `session_health` diisi Gateway lewat heartbeat (field baru
 * `session_health` di body POST /api/inbox/gateway/status, opsional --
 * lihat InboxGatewayApi::status()) berdasarkan deteksi pola kegagalan
 * dekripsi berulang tanpa keberhasilan (lihat WA-Gateway
 * src/whatsapp/connectionManager.js _recordDecryptFailure()).
 *
 * Nilai: 'ok' (default -- termasuk semua baris lama & Gateway versi lama
 * yang belum mengirim field ini) atau 'degraded'.
 *
 * ADDITIVE-ONLY: tanpa indeks (dibaca bersama baris tunggal gateway_status
 * yang sudah diambil by primary key, tidak pernah dicari sendiri), tanpa
 * foreign key, tanpa perubahan kolom lama. Gateway versi lama yang belum
 * mengirim field ini tetap kompatibel (kolom NOT NULL DEFAULT 'ok', upsert
 * cukup tidak menyertakan field ini -- lihat allowedFields di
 * GatewayStatusModel, kolom hilang di $data tidak menimpa nilai lama).
 */
class AddSessionHealthToGatewayStatus extends Migration
{
    protected $DBGroup = 'inbox';

    private const TABLE  = 'gateway_status';
    private const COLUMN = 'session_health';

    public function up()
    {
        if (! $this->columnExists()) {
            $this->forge->addColumn(self::TABLE, [
                self::COLUMN => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'null'       => false,
                    'default'    => 'ok',
                    'after'      => 'status',
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->columnExists()) {
            $this->forge->dropColumn(self::TABLE, self::COLUMN);
        }
    }

    /**
     * Cek kolom lewat information_schema, bukan `fieldExists()`:
     * `fieldExists()` membaca cache nama field per koneksi sehingga hasilnya
     * basi setelah DDL dijalankan pada request yang sama (pola yang sama
     * dipakai migration lain di modul Inbox, mis. AddIsForwardedToMessages).
     * Semua kueri memakai binding.
     */
    private function columnExists(): bool
    {
        $row = $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$this->db->database, self::TABLE, self::COLUMN]
        )->getRowArray();

        return $row !== null;
    }
}
