<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Inbox: let M.A.R.A attach product lines to a suggestion so the cashier's
 * cart can be pre-filled when opening the Kasir page.
 *
 * Stored as a JSON array of {produk_id, jumlah} objects (validated by
 * MaraController). Separate from `items` which holds checklist step text.
 *
 * Additive and nullable: existing rows stay NULL, safe without downtime.
 * Deploy order: run this migration BEFORE deploying code that writes the
 * column (MaraController only includes `cart_items` in the INSERT when
 * M.A.R.A sent one, so code deployed first still works for plain suggestions).
 *
 * Runs on the 'inbox' connection, same as CreateMaraSuggestions.
 */
class AddCartItemsToMaraSuggestions extends Migration
{
    protected $DBGroup = 'inbox';

    public function up()
    {
        if ($this->db->fieldExists('cart_items', 'mara_suggestions')) {
            return;
        }

        $this->forge->addColumn('mara_suggestions', [
            'cart_items' => [
                'type'    => 'TEXT',
                'null'    => true,
                'default' => null,
                'after'   => 'action',
            ],
        ]);
    }

    public function down()
    {
        if ($this->db->fieldExists('cart_items', 'mara_suggestions')) {
            $this->forge->dropColumn('mara_suggestions', 'cart_items');
        }
    }
}
