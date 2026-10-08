<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TODO-BL10: append-only audit trail for `cash_expense` update/delete.
 *
 * `expense_id` intentionally has NO foreign key to `cash_expense.id`: a
 * `delete` audit row must survive after the source row is hard-deleted, and
 * `ON DELETE CASCADE` would erase the very audit row the delete action is
 * supposed to leave behind. An index (not an FK) is enough for lookups --
 * same approach as `closing_kas.updated_by` for auditing, except that one
 * references a row (`users`) that is never deleted.
 *
 * `data_sebelum` / `data_sesudah` store the full `cash_expense` row (minus
 * audit-irrelevant timestamps) as JSON, per
 * docs/design/2026-10-08-audit-validasi-kas-keluar.md Section 3 (Option A):
 * the schema stays stable if `cash_expense`'s editable fields change, and
 * there is no reporting UI in scope that would need individual SQL columns.
 * `data_sesudah` is NULL for a `delete` action.
 */
class CreateCashExpenseAuditTable extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('cash_expense_audit')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'expense_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => false,
            ],
            'aksi' => [
                'type'       => 'ENUM',
                'constraint' => ['update', 'delete'],
                'null'       => false,
            ],
            'data_sebelum' => [
                'type' => 'JSON',
                'null' => false,
            ],
            'data_sesudah' => [
                'type' => 'JSON',
                'null' => true,
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
        $this->forge->addKey('expense_id');
        $this->forge->addKey('user_id');
        $this->forge->createTable('cash_expense_audit', true, [
            'ENGINE'  => 'InnoDB',
            'CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_general_ci',
        ]);
    }

    public function down()
    {
        $this->forge->dropTable('cash_expense_audit', true);
    }
}
