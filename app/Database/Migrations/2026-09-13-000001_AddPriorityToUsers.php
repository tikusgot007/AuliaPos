<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tambah `users`.`priority` (nullable, unik) -- ranking permanen
 * karyawan untuk Effective Shift Leader (lihat
 * App\Services\EffectiveShiftLeaderService). NULL = belum di-ranking,
 * BUKAN priority 0 -- karyawan dengan priority NULL tidak pernah jadi
 * kandidat Leader. Tidak ada backfill: seluruh user existing otomatis
 * NULL lewat DEFAULT kolom; MySQL memperlakukan tiap NULL sebagai
 * distinct di bawah UNIQUE KEY, jadi banyak user unranked aman hidup
 * berdampingan.
 */
class AddPriorityToUsers extends Migration
{
    public function up()
    {
        $this->db->query(
            "ALTER TABLE `users` ADD COLUMN `priority` " .
            "SMALLINT UNSIGNED NULL DEFAULT NULL AFTER `is_active`"
        );
        $this->db->query(
            "ALTER TABLE `users` ADD UNIQUE KEY `uq_users_priority` (`priority`)"
        );
    }

    public function down()
    {
        $this->db->query("ALTER TABLE `users` DROP INDEX `uq_users_priority`");
        $this->db->query("ALTER TABLE `users` DROP COLUMN `priority`");
    }
}
