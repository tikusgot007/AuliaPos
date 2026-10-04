<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TODO-F7 -- penanda "pesan diedit / dihapus pelanggan" pada `messages`
 * (DB group `inbox`).
 *
 * Pelanggan bisa MENGEDIT atau MENGHAPUS pesannya di WhatsApp. Isi pesan di
 * Inbox tidak bisa diperbarui (edit terenkripsi & tak terbaca gateway), jadi
 * yang ditandai adalah pesan ASLI:
 *
 *   edited_at  DATETIME NULL -- diisi saat pelanggan mengedit pesan ini
 *   revoked_at DATETIME NULL -- diisi saat pelanggan menghapus pesan ini
 *
 * Keduanya diisi SEKALI (idempoten) lewat `MessageModel::markLifecycle()`,
 * hanya bila masih NULL -- edit/hapus berulang tidak mengubah nilai pertama.
 *
 * ADDITIVE-ONLY (GUD-001): nullable, tanpa indeks (dibaca bersama baris pesan,
 * tidak pernah dicari sendiri), tanpa foreign key, tidak mengubah kolom lama.
 * Rollback `down()` drop kedua kolom; aman karena kode versi lama tidak
 * membacanya.
 *
 * URUTAN DEPLOY (WAJIB): jalankan migrasi ini SEBELUM kode yang menulis kolom
 * ini dinaikkan -- kalau tidak, `message-event` gagal `Unknown column` -> 500.
 *
 * CATATAN: `MessageModel` sengaja tidak punya `updated_at` (pesan append-only);
 * menandai lifecycle adalah UPDATE pertama pada baris `messages`, dan kolom ini
 * TIDAK memakai timestamp otomatis CodeIgniter.
 */
class AddEditedRevokedToMessages extends Migration
{
    protected $DBGroup = 'inbox';

    private const TABLE   = 'messages';
    private const COLUMNS = ['edited_at', 'revoked_at'];

    public function up()
    {
        foreach (self::COLUMNS as $column) {
            if (! $this->columnExists($column)) {
                $this->forge->addColumn(self::TABLE, [
                    $column => [
                        'type'  => 'DATETIME',
                        'null'  => true,
                        'after' => 'extra_json',
                    ],
                ]);
            }
        }
    }

    public function down()
    {
        foreach (self::COLUMNS as $column) {
            if ($this->columnExists($column)) {
                $this->forge->dropColumn(self::TABLE, $column);
            }
        }
    }

    /**
     * Cek kolom lewat information_schema (bukan `fieldExists()`, yang membaca
     * cache nama field per koneksi sehingga basi setelah DDL pada request yang
     * sama -- pola sama dipakai migrasi Inbox lainnya). Semua kueri memakai
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
