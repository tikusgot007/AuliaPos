<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * M3 Fase 2a (TB-01, TASK-001) - Handoff history table.
 *
 * Additive only: creates `conversation_handoffs` on the Inbox database
 * (DB group `inbox`). No existing table is altered (CON-H05).
 *
 * Design notes:
 * - `conversation_id` is BIGINT UNSIGNED instead of the INT UNSIGNED
 *   written in the plan table: InnoDB rejects a FK whose child column
 *   type differs from the parent (errno 150, verified on MariaDB 10.4)
 *   and `conversations.id` is BIGINT UNSIGNED. This matches the
 *   baseline precedent cited by Spec 4.1 (`messages.conversation_id`).
 * - `from_user_id` / `to_user_id` / `initiated_by_user_id` are logical
 *   references to `aulia_kasirdb.users.id` and carry no FK (cross-
 *   database FKs are impossible with the split inbox/kasirdb groups).
 * - `summary` / `next_action` are capped at 4096 characters (P-02); the
 *   cap is enforced in the controller (400), not in the schema.
 * - The composite index is created with an explicit `id DESC` part for
 *   newest-first read-back; note that MariaDB 10.x parses but
 *   normalizes index direction, which keeps the same no-filesort
 *   guarantee via backward index scan.
 * - The index is created before the FK so InnoDB reuses it instead of
 *   silently adding a duplicate single-column index.
 */
class CreateConversationHandoffs extends Migration
{
    protected $DBGroup = 'inbox';

    public function up()
    {
        // Create-only-if-missing guard: re-running up() is a no-op.
        if ($this->db->tableExists('conversation_handoffs')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 10,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'conversation_id' => [
                // BIGINT UNSIGNED, see class docblock.
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => false,
            ],
            'from_user_id' => [
                // NULL when the conversation was unassigned (K-06).
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
            ],
            'to_user_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => false,
            ],
            'initiated_by_user_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => false,
            ],
            'summary' => [
                'type'       => 'VARCHAR',
                'constraint' => 4096,
                'null'       => false,
            ],
            'next_action' => [
                'type'       => 'VARCHAR',
                'constraint' => 4096,
                'null'       => false,
            ],
            'note' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                // Always written in Asia/Jakarta (CON-H06).
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->createTable('conversation_handoffs', true, [
            'ENGINE'  => 'InnoDB',
            'CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_general_ci',
        ]);

        // KEY idx_handoffs_conversation (conversation_id, id DESC):
        // newest-first read-back without filesort. Created via raw SQL
        // because Forge cannot express a per-column DESC direction.
        $this->db->query(
            'CREATE INDEX `idx_handoffs_conversation`'
            . ' ON `conversation_handoffs` (`conversation_id`, `id` DESC)'
        );

        // FK inside the Inbox database only (CON-H05), ON DELETE CASCADE
        // like the baseline `messages` FK. Added after the index so
        // InnoDB reuses idx_handoffs_conversation.
        $this->db->query(
            'ALTER TABLE `conversation_handoffs`'
            . ' ADD CONSTRAINT `fk_handoffs_conversation`'
            . ' FOREIGN KEY (`conversation_id`)'
            . ' REFERENCES `conversations` (`id`)'
            . ' ON DELETE CASCADE ON UPDATE CASCADE'
        );
    }

    public function down()
    {
        $this->forge->dropTable('conversation_handoffs', true);
    }
}