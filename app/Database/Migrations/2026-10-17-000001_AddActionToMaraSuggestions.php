<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Inbox -> Kasir: let M.A.R.A attach an optional UI action to a suggestion.
 *
 * `action` is a WHITELISTED identifier (currently only 'buka_kasir'), never
 * a URL or parameters. The cashier UI turns it into a button that calls the
 * normal session-authenticated Inbox endpoint for the conversation on screen
 * (`POST /inbox/percakapan/(:num)/buat-pesanan`), so M.A.R.A cannot create a
 * pelanggan or redirect anyone by itself.
 *
 * Additive and nullable: existing rows stay NULL, safe without downtime.
 * Deployment order: run this migration BEFORE deploying code that writes the
 * column (MaraController only includes `action` in the INSERT when M.A.R.A
 * sent one, so code deployed first still works for plain suggestions).
 *
 * Runs on the 'inbox' connection, same as CreateMaraSuggestions.
 */
class AddActionToMaraSuggestions extends Migration
{
    protected $DBGroup = 'inbox';

    public function up()
    {
        if ($this->db->fieldExists('action', 'mara_suggestions')) {
            return;
        }

        $this->forge->addColumn('mara_suggestions', [
            'action' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => true,
                'default'    => null,
                'after'      => 'reason',
            ],
        ]);
    }

    public function down()
    {
        if ($this->db->fieldExists('action', 'mara_suggestions')) {
            $this->forge->dropColumn('mara_suggestions', 'action');
        }
    }
}
