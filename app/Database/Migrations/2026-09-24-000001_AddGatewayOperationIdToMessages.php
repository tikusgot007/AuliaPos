<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * M1 Wave 2 / TASK-016 -- kolom kunci idempotensi kirim keluar
 * (spec 4.7, CON-009).
 *
 * `gateway_operation_id` menyimpan `operation_id` milik pemanggil untuk
 * baris `messages` hasil kirim keluar, supaya respons replay dari
 * Gateway (`replayed:true`) TIDAK menulis baris kedua (AC-041).
 *
 * Kolom ini SENGAJA nullable: permintaan tanpa `operation_id` tetap
 * berperilaku persis seperti sebelumnya (CON-007/REQ-026), dan MySQL
 * mengizinkan banyak `NULL` pada indeks UNIQUE, sehingga baris pesan
 * masuk tidak terpengaruh.
 *
 * Lebar 64 sengaja disamakan dengan batas REQ-020 (1-64 karakter):
 * nilai yang lebih panjang sudah ditolak Gateway dengan
 * `400 INVALID_OPERATION_ID` sebelum bisa sampai ke kolom ini, jadi
 * pemotongan senyap mustahil (R-3).
 */
class AddGatewayOperationIdToMessages extends Migration
{
    protected $DBGroup = 'inbox';

    private const TABLE      = 'messages';
    private const COLUMN     = 'gateway_operation_id';
    private const INDEX_NAME = 'uniq_messages_gateway_operation_id';

    /**
     * Definisi kolom: `VARCHAR(64) NULL` setelah `send_status`.
     * Dipakai `addColumn()` (DDL) DAN `addField()` (state Forge), lihat
     * catatan di `up()`.
     */
    private const COLUMN_DEFINITION = [
        'type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'after' => 'send_status',
    ];

    public function up()
    {
        // ADDITIVE ONLY (CON-009): satu kolom nullable + satu indeks
        // UNIQUE. Tidak ada kolom lain yang diubah (termasuk
        // `send_status`/`wa_message_id`) dan tidak ada FK baru.
        if (! $this->columnExists()) {
            $this->forge->addColumn(self::TABLE, [self::COLUMN => self::COLUMN_DEFINITION]);
        }

        // Penjaga idempotensi: aman dijalankan ulang (mis. setelah
        // migrasi diterapkan manual di database uji, lihat F-02).
        if (! $this->uniqueIndexExists()) {
            // Forge::processIndexes() hanya membuat indeks untuk kolom
            // yang dikenalnya di instance Forge yang sama, sedangkan
            // addColumn() me-reset state itu, jadi kolomnya
            // dideklarasikan ulang di sini.
            $this->forge->addField([self::COLUMN => self::COLUMN_DEFINITION]);
            $this->forge->addKey(self::COLUMN, false, true, self::INDEX_NAME);
            $this->forge->processIndexes(self::TABLE);
        }
    }

    public function down()
    {
        // Urutan rollback wajib: indeks UNIQUE dilepas DULU, baru
        // kolomnya (RISK-012).
        if ($this->uniqueIndexExists()) {
            $this->forge->dropKey(self::TABLE, self::INDEX_NAME);
        }

        if ($this->columnExists()) {
            $this->forge->dropColumn(self::TABLE, self::COLUMN);
        }
    }

    /**
     * Cek kolom lewat information_schema, bukan `fieldExists()`:
     * `fieldExists()` membaca field-name cache per koneksi sehingga
     * hasilnya basi setelah DDL dijalankan pada request yang sama.
     * Semua kueri memakai binding (SEC-002).
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

    /** Cek indeks UNIQUE pada kolom ini. */
    private function uniqueIndexExists(): bool
    {
        $row = $this->db->query(
            'SELECT 1 FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?'
            . ' AND NON_UNIQUE = 0 AND COLUMN_NAME = ? LIMIT 1',
            [$this->db->database, self::TABLE, self::INDEX_NAME, self::COLUMN]
        )->getRowArray();

        return $row !== null;
    }
}
