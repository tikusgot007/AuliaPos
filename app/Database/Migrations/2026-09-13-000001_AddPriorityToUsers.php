<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tambah users.priority sebagai ranking permanen karyawan.
 * NULL berarti belum diranking dan bukan kandidat Shift Leader.
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
