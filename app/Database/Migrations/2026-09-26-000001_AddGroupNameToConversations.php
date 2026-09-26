<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Grup Tahap 2 / TASK-001 (spec-design-grup-tahap2-identitas v1.3,
 * Section 4.2; CON-002) -- kolom `conversations.group_name`.
 *
 * Menyimpan SUBJECT grup WhatsApp (`groupMetadata().subject`) sebagai
 * judul percakapan yang STABIL untuk `jid_type='group'`. Kolom ini
 * TERPISAH dari `whatsapp_name` (identitas kontak perorangan yang
 * selalu ditimpa otomatis tiap pesan masuk) dan dari `contact_name`
 * (nama manual customer) -- memakai ulang keduanya akan melanggar
 * invarian "setiap operasi hanya menulis kolom yang jadi tanggung
 * jawabnya" (docs/CHAT.md Section 18).
 *
 * `VARCHAR(255) NULL DEFAULT NULL`:
 * - NULL = nama grup belum diketahui (judul UI jatuh ke teks generik
 *   "Grup", lihat REQ-007) ATAU percakapan bukan grup.
 * - Nilai diisi WRITE-ONCE oleh InboxGatewayApi::messages() hanya saat
 *   `jid_type='group'` DAN kolom masih NULL (REQ-005/REQ-006).
 *
 * Additive only: tidak ada kolom lain yang diubah, tidak ada FK/indeks
 * baru, tidak ada backfill data (PRD Section 8.2).
 */
class AddGroupNameToConversations extends Migration
{
    protected $DBGroup = 'inbox';

    private const TABLE  = 'conversations';
    private const COLUMN = 'group_name';

    public function up()
    {
        if ($this->columnExists()) {
            return; // idempotent: aman dijalankan ulang
        }

        $this->forge->addColumn(self::TABLE, [
            self::COLUMN => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'default'    => null,
            ],
        ]);
    }

    public function down()
    {
        if ($this->columnExists()) {
            $this->forge->dropColumn(self::TABLE, self::COLUMN);
        }
    }

    /**
     * Cek lewat information_schema (bukan fieldExists(), yang membaca
     * cache field-name per koneksi sehingga bisa basi setelah DDL pada
     * request yang sama). Semua kueri memakai binding (SEC-002).
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
