<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tahap 4 -- dukungan lokasi & kontak pada `messages` (DB group `inbox`).
 *
 * Pesan lokasi dan kartu kontak WhatsApp TIDAK berbentuk file, jadi tidak ada
 * `direct_path`/`media_key` yang bisa diisi di kolom media. Datanya disimpan
 * sebagai JSON di kolom BARU `extra_json`:
 *
 *   {"kind":"location","latitude":-6.2,"longitude":106.8,"name":...,"address":...,"live":false}
 *   {"kind":"contact","contacts":[{"display_name":"Budi","vcard":"BEGIN:VCARD..."}]}
 *
 * Kolom generik (bukan satu kolom per tipe) supaya tipe terstruktur berikutnya
 * (mis. poll pada tahap lanjutan) tidak perlu migration baru.
 *
 * ADDITIVE-ONLY (GUD-001): nullable, tanpa indeks (dibaca bersama baris pesan,
 * tidak pernah dicari sendiri), tanpa foreign key, tidak mengubah kolom lama.
 * Rollback `down()` drop kolom; aman karena kode versi lama tidak membacanya.
 *
 * URUTAN DEPLOY (WAJIB): jalankan migrasi ini SEBELUM kode yang menulis
 * `extra_json` dinaikkan -- kalau tidak, insert pesan lokasi/kontak gagal
 * `Unknown column` -> HTTP 500.
 */
class AddExtraJsonToMessages extends Migration
{
    protected $DBGroup = 'inbox';

    private const TABLE  = 'messages';
    private const COLUMN = 'extra_json';

    public function up()
    {
        if (! $this->columnExists()) {
            $this->forge->addColumn(self::TABLE, [
                self::COLUMN => [
                    'type'  => 'JSON',
                    'null'  => true,
                    'after' => 'is_forwarded',
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
     * Cek kolom lewat information_schema (bukan `fieldExists()`, yang membaca
     * cache nama field per koneksi sehingga basi setelah DDL pada request yang
     * sama -- pola sama dipakai migrasi Inbox lainnya). Semua kueri memakai
     * binding (SEC-002).
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
