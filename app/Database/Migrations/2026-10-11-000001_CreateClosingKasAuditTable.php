<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TODO-BL28: append-only revision trail for `closing_kas` edits.
 *
 * `closing_id` intentionally has NO foreign key to `closing_kas.id`: the trail
 * must stay readable even if the source row were ever removed, and
 * `ON DELETE CASCADE` would erase the very history being recorded. An index is
 * enough for lookups -- same approach as `cash_expense_audit` (TODO-BL10).
 *
 * `data_sebelum` / `data_sesudah` store the full `closing_kas` row as JSON
 * (Option A of docs/design/2026-10-08-audit-validasi-kas-keluar.md): the schema
 * stays stable if the editable fields change, and no per-column SQL reporting is
 * needed. Only edits are recorded (`aksi = 'update'`); the initial creation is
 * not, because the first edit's `data_sebelum` already captures those values.
 */
class CreateClosingKasAuditTable extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('closing_kas_audit')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'closing_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => false,
            ],
            'aksi' => [
                'type'       => 'ENUM',
                'constraint' => ['update'],
                'null'       => false,
            ],
            'data_sebelum' => [
                'type' => 'JSON',
                'null' => false,
            ],
            'data_sesudah' => [
                'type' => 'JSON',
                'null' => false,
            ],
            'user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => false,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('closing_id');
        $this->forge->addKey('user_id');
        $this->forge->createTable('closing_kas_audit', true, [
            'ENGINE'  => 'InnoDB',
            'CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_general_ci',
        ]);
    }

    public function down()
    {
        $this->forge->dropTable('closing_kas_audit', true);
    }
}
