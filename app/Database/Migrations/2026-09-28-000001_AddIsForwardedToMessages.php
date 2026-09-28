<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Teruskan (Tahap 4, TASK-001) -- penanda "Diteruskan" pada `messages`
 * (spec Section 4.2, REQ-008, REQ-009).
 *
 * SATU-SATUNYA kolom yang ditambahkan plan ini. Tidak ada kolom penghitung
 * forward (`forward_count`, `forwarded_from`, dst.) dan tidak ada kolom
 * "sumber" apa pun: pesan hasil Teruskan adalah baris `messages` yang
 * BERBEDA di percakapan tujuan, jadi tidak ada yang perlu dihitung maupun
 * dirujuk (REQ-009: penanda tunggal, tidak berlapis, tidak ada penghitung).
 *
 * Nilai `0` = baris biasa (pesan masuk, balasan kasir, catatan internal).
 * Nilai `1` = baris hasil aksi Teruskan, dan label "Diteruskan" di UI
 * dibangun DARI kolom ini saja -- independen dari `forward_marker_applied`
 * yang dilaporkan Gateway (REQ-008), supaya label di AuliaPos tidak
 * bergantung pada metode penanda Gateway.
 *
 * NOT NULL DEFAULT 0 (bukan nullable seperti kolom kutipan): ini bukan
 * snapshot opsional, melainkan penanda yang selalu ada jawabannya, dan
 * baris lama harus otomatis jadi "bukan hasil Teruskan" tanpa backfill.
 * Tipe `tinyint(1)` mengikuti preseden `messages.is_internal`
 * (AddIsInternalToMessages) -- skema Inbox tidak punya kolom BOOLEAN.
 *
 * ADDITIVE-ONLY (GUD-001): tanpa indeks (penanda dibaca bersama baris pesan
 * yang sudah diambil, tidak pernah dicari sendiri), tanpa foreign key, tanpa
 * perubahan kolom lama. Rollback `down()` drop kolom; aman karena kode versi
 * lama tidak membacanya.
 *
 * URUTAN DEPLOY (WAJIB): jalankan migrasi ini SEBELUM kode yang menulis
 * `is_forwarded` dinaikkan -- `Inbox::kirimKeConversation()` menulis kolom
 * ini pada SETIAP baris pesan keluar, jadi kode naik lebih dulu akan gagal
 * `Unknown column` -> HTTP 500.
 */
class AddIsForwardedToMessages extends Migration
{
    protected $DBGroup = 'inbox';

    private const TABLE  = 'messages';
    private const COLUMN = 'is_forwarded';

    public function up()
    {
        if (! $this->columnExists()) {
            $this->forge->addColumn(self::TABLE, [
                self::COLUMN => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'null'       => false,
                    'default'    => 0,
                    'after'      => 'quoted_media_type',
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
     * dipakai AddQuoteColumnsToMessages/AddQuotedMediaTypeToMessages).
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
}
