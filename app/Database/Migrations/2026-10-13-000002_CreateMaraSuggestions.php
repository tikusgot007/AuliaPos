<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Suggestions posted by M.A.R.A (Inbox AI monitor) to AuliaPos, rendered
 * to the cashier in the Inbox page.
 *
 * Direction: M.A.R.A -> AuliaPos -> cashier. M.A.R.A NEVER touches the
 * database directly; it POSTs to `POST /api/inbox/mara/suggestions`
 * (see App\Controllers\MaraController::suggestions()) and AuliaPos stores
 * the row here.
 *
 * Runs on the 'inbox' connection (aulia_inboxdb) -- SAME as the
 * conversations/messages tables it references, NOT the default AuliaPos
 * connection, consistent with CreateMaraResolveLog / CreateInboxTables
 * (group `inbox`).
 *
 * Lifecycle (`status`):
 * - `active`     : visible to the cashier while not past `expires_at`.
 * - `superseded` : replaced by a newer suggestion of the SAME type
 *                  (max 1 active per type per conversation; total max 3).
 * - `expired`    : the cashier replied in that conversation (all active
 *                  suggestions are expired), or the row aged out.
 *
 * `expires_at` is never null in practice: AuliaPos defaults it to
 * now + 30 minutes when M.A.R.A omits it (requirement AC-6).
 */
class CreateMaraSuggestions extends Migration
{
    protected $DBGroup = 'inbox';

    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'conversation_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => false,
            ],
            // Suggestion kind: 'balasan' | 'daftar-periksa' | 'peringatan'.
            // VARCHAR (not ENUM) so adding a type later is a data-only
            // change, matching the Inbox convention of avoiding ENUM.
            'type' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => false,
            ],
            // 'normal' | 'tinggi' | 'darurat' -- drives the badge colour in
            // the cashier UI.
            'priority' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => false,
            ],
            // Reply draft / warning body. Null for a checklist-only
            // suggestion (items carry the content instead).
            'text' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            // Checklist steps, JSON-encoded array of strings. Only used for
            // type='daftar-periksa'; NULL otherwise. Stored as TEXT (not a
            // native JSON column) to stay portable with the rest of the
            // inbox schema.
            'items' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            // Free-text context from M.A.R.A (why the suggestion was made) --
            // for human audit only, not validated for format.
            'reason' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => false,
                'default'    => 'active',
            ],
            // When the suggestion stops being shown. Never null in practice
            // (AuliaPos defaults it); NULL allowed only as a defensive
            // fallback.
            'expires_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        // Hot read path: active suggestions for one conversation.
        $this->forge->addKey(['conversation_id', 'status']);
        $this->forge->addKey('expires_at');
        // TANPA foreign key ke conversations -- konsisten dengan keputusan
        // "logical reference" di CreateInboxTables/CreateMaraResolveLog
        // (saran tetap bisa disimpan/dibaca apa pun kondisi baris sumber).
        $this->forge->createTable('mara_suggestions', true, [
            'ENGINE'  => 'InnoDB',
            'CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_general_ci',
        ]);
    }

    public function down()
    {
        $this->forge->dropTable('mara_suggestions', true);
    }
}
