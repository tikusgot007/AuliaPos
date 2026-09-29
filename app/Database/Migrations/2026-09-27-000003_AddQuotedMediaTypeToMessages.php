<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Balas Pesan (Tahap 3, v1.7 REQ-008c) -- TIPE MEDIA pesan sumber pada
 * snapshot kutipan (`messages.quoted_media_type`).
 *
 * Kolom ini menyimpan `message_type` baris pesan yang dikutip BILA sumbernya
 * pesan media (`image`/`document`/`sticker`/`audio`/`video`), diambil SEKALI
 * saat snapshot dirakit (`InboxQuoteSnapshotService::rakitSnapshot()`), supaya
 * UI dapat memilih representasi kutipan per tipe (thumbnail/tautan/label)
 * TANPA menebak dari `quoted_snippet` -- caption dapat menggantikan label
 * jenis, sehingga penebakan string akan salah (lihat ALT-001 di
 * `plan-refactor-balas-pesan-tahap3-review2-v1.0.md`).
 *
 * SENGAJA snapshot beku, BUKAN foreign key hidup (spec Section 9 "Never do"):
 * tidak ada constraint referensial, konsisten dengan kolom kutipan lain di
 * `AddQuoteColumnsToMessages`/`AddQuotedSourceMessageIdToMessages`.
 *
 * ADDITIVE-ONLY (GUD-001): satu kolom nullable tanpa indeks. `NULL` berarti
 * sumber BUKAN pesan media (teks), pesan sumber tidak ditemukan (REQ-011 kasus
 * kedua), ATAU baris dibuat sebelum migrasi ini ada (baris legacy, tanpa
 * backfill retroaktif). Rollback trivial: `down()` drop kolom.
 *
 * URUTAN DEPLOY (WAJIB): jalankan migrasi ini SEBELUM kode yang menulis
 * `quoted_media_type` dinaikkan. Ketiga jalur insert (`Inbox::kirimKeConversation()`,
 * `Inbox::kirimMedia()`, dan `InboxGatewayApi::messages()`) menulis kolom ini pada
 * SETIAP baris, jadi bila kode naik lebih dulu tanpa kolom, semua insert gagal
 * (`Unknown column`) -> HTTP 500. Rollback aplikasi juga mengharuskan kolom ini
 * tetap ada; `down()` hanya boleh dijalankan setelah kode yang bergantung
 * padanya diturunkan.
 */
class AddQuotedMediaTypeToMessages extends Migration
{
    protected $DBGroup = 'inbox';

    private const TABLE  = 'messages';
    private const COLUMN = 'quoted_media_type';

    public function up()
    {
        if (! $this->columnExists()) {
            $this->forge->addColumn(self::TABLE, [
                self::COLUMN => [
                    'type'       => 'VARCHAR',
                    'constraint' => 30,
                    'null'       => true,
                    'default'    => null,
                    'after'      => 'quoted_source_message_id',
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
     * dipakai AddQuoteColumnsToMessages/AddQuotedSourceMessageIdToMessages).
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
