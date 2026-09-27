<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Balas Pesan (Tahap 3, v1.6 REQ-008b) -- ID LOKAL pesan sumber pada
 * snapshot kutipan (`messages.quoted_source_message_id`).
 *
 * Kolom ini menyimpan `messages.id` baris pesan yang dikutip, diambil SEKALI
 * saat snapshot dirakit, supaya UI punya target yang benar untuk
 * `GET /inbox/media/(:num)` saat fallback tampilan `REQ-008`/`AC-005`
 * dijalankan. Snapshot hanya punya `quoted_wa_message_id` (ID WhatsApp),
 * sedangkan endpoint media butuh ID lokal -- tanpa kolom ini fallback
 * tampilan tidak dapat direalisasikan (lihat ALT-001 di
 * `plan/plan-refactor-balas-pesan-tahap3-v1.0.md`).
 *
 * SENGAJA snapshot beku, BUKAN foreign key hidup (spec Section 9 "Never do"):
 * tidak ada constraint referensial, konsisten dengan keempat kolom kutipan
 * lain di `AddQuoteColumnsToMessages`.
 *
 * ADDITIVE-ONLY (GUD-001): satu kolom nullable tanpa indeks. `NULL` berarti
 * pesan sumber tidak ditemukan (REQ-011 kasus kedua) ATAU baris dibuat
 * sebelum migrasi ini ada (baris legacy, tanpa backfill retroaktif).
 * Rollback trivial: `down()` drop kolom.
 */
class AddQuotedSourceMessageIdToMessages extends Migration
{
    protected $DBGroup = 'inbox';

    private const TABLE  = 'messages';
    private const COLUMN = 'quoted_source_message_id';

    public function up()
    {
        if (! $this->columnExists()) {
            $this->forge->addColumn(self::TABLE, [
                self::COLUMN => [
                    'type'       => 'INT',
                    'constraint' => 10,
                    'unsigned'   => true,
                    'null'       => true,
                    'default'    => null,
                    'after'      => 'quoted_media_available',
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
     * dipakai AddQuoteColumnsToMessages). Semua kueri memakai binding (SEC-002).
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
