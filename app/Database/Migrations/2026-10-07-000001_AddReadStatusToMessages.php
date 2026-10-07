<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WhatsApp read receipt (dua arah) -- kolom status kirim dari Evolution.
 *
 * `delivered_at` = pesan keluar sampai ke HP pelanggan (DELIVERY_ACK).
 * `read_at`      = pelanggan membaca pesan keluar (READ).
 *
 * SENGAJA TIDAK mengubah ENUM `send_status` (received/sent/failed) yang
 * berarti state internal POS: kolom waktu terpisah menjaga pemisahan konsep
 * antara state internal dan WhatsApp receipt. Kedua kolom nullable dan
 * hanya MAJU (first-seen) -- lihat MessageModel::markDelivered()/markRead().
 *
 * URUTAN DEPLOY (WAJIB): jalankan migrasi ini SEBELUM kode yang menulis kolom
 * ini dinaikkan, kalau tidak `message-status` gagal `Unknown column` -> 500.
 *
 * Idempoten: guard `columnExists()` per kolom (pola sama dengan migrasi Inbox
 * lain) supaya aman diulang / di environment yang kolomnya sudah ditambahkan
 * out-of-band (mis. refresh skema DB uji).
 */
class AddReadStatusToMessages extends Migration
{
    protected $DBGroup = 'inbox';

    private const TABLE   = 'messages';
    private const COLUMNS = ['delivered_at', 'read_at'];

    public function up()
    {
        foreach (self::COLUMNS as $column) {
            if (! $this->columnExists($column)) {
                $this->forge->addColumn(self::TABLE, [
                    $column => [
                        'type'  => 'DATETIME',
                        'null'  => true,
                        'after' => 'send_status',
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
