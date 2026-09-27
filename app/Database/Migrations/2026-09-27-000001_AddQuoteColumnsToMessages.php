<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Balas Pesan (Tahap 3, TASK-001) -- kolom kutipan pada `messages`
 * (spec Section 4.2, REQ-007, GUD-001).
 *
 * Keempat kolom menyimpan SNAPSHOT kutipan pada baris pesan balasan itu
 * sendiri, diambil SEKALI saat balasan dibuat (REQ-007) dan tidak pernah
 * di-refresh dari pesan asli setelahnya. Karena itu kutipan tetap tampil apa
 * adanya walau pesan asli kemudian di-soft-delete (AC-004) -- persis
 * Tradeoff yang disepakati di Clarification Report.
 *
 * SENGAJA snapshot, BUKAN foreign key hidup ke `messages.id`: referensi
 * hidup ikut berubah saat pesan asli diubah/dihapus, sedangkan kutipan
 * harus beku (spec Section 9 "Never do", ALT-001).
 *
 * SEMUA kolom nullable dan tanpa indeks baru (GUD-001): baris pesan yang
 * bukan balasan -- dan semua pesan masuk yang tidak membawa kutipan -- tetap
 * `NULL` di keempat kolom, jadi tidak ada write path yang berubah.
 *
 * Kolom yang sama juga dipakai ulang untuk kutipan arah MASUK dari pelanggan
 * (REQ-010/REQ-011, ASSUMPTION-006): kolomnya sudah generik/nullable sejak
 * didesain di Section 4.2, sehingga menambah sePadan kolom kedua hanya akan
 * menduplikasi data (GUD-001).
 *
 * Lebar `quoted_wa_message_id` (255) disamakan dengan `messages.wa_message_id`
 * yang sudah ada, karena keduanya menyimpan ID pesan WhatsApp dari Gateway.
 */
class AddQuoteColumnsToMessages extends Migration
{
    protected $DBGroup = 'inbox';

    private const TABLE = 'messages';

    /**
     * Definisi keempat kolom (spec Section 4.2). `after` menjaga urutan fisik
     * kolom tetap seperti di spec, dan dipakai bersama oleh `addColumn()`
     * (DDL) serta pemeriksaan idempotensi.
     */
    private const COLUMNS = [
        'quoted_wa_message_id'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'gateway_operation_id'],
        'quoted_sender_label'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'quoted_wa_message_id'],
        'quoted_snippet'          => ['type' => 'TEXT', 'null' => true, 'after' => 'quoted_sender_label'],
        'quoted_media_available'  => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true, 'default' => null, 'after' => 'quoted_snippet'],
    ];

    public function up()
    {
        // ADDITIVE ONLY (GUD-001): empat kolom nullable, tanpa indeks, tanpa
        // FK, tanpa mengubah kolom lama. Dicek per kolom supaya aman
        // dijalankan ulang (mis. setelah diterapkan manual di database uji,
        // lihat catatan F-02 pada test migrasi Gateway lama).
        foreach (self::COLUMNS as $column => $definition) {
            if (! $this->columnExists($column)) {
                $this->forge->addColumn(self::TABLE, [$column => $definition]);
            }
        }
    }

    public function down()
    {
        // Urutan rollback: kolom yang kolomnya "setelah" kolabor dilepas
        // lebih dulu, supaya tidak ada kolom yatim bila migrasi dijalankan
        // sebagian (pola kebalikan dari migrasi gateway_operation_id).
        foreach (array_reverse(array_keys(self::COLUMNS)) as $column) {
            if ($this->columnExists($column)) {
                $this->forge->dropColumn(self::TABLE, $column);
            }
        }
    }

    /**
     * Cek kolom lewat information_schema, bukan `fieldExists()`:
     * `fieldExists()` membaca field-name cache per koneksi sehingga hasilnya
     * basi setelah DDL dijalankan pada request yang sama. Semua kueri memakai
     * binding (SEC-002).
     */
    private function columnExists(string $column): bool
    {
        $row = $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$this->db->database, self::TABLE, $column]
        )->getRowArray();

        return $row !== null;
    }
}
