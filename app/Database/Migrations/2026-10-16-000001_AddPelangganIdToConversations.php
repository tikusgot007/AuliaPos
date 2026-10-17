<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Inbox -> Kasir ("Buat Pesanan"): link a conversation to a `pelanggan` row.
 *
 * `pelanggan` lives in the default database (aulia_kasirdb) while
 * `conversations` lives in the inbox database (aulia_inboxdb), so this is a
 * LOGICAL reference only (no foreign key), consistent with assigned_to /
 * closed_by on the same table. Code must tolerate a dangling id (pelanggan
 * deleted later) by treating it as unset.
 *
 * Additive and nullable: existing rows stay NULL, no backfill, safe to run
 * without downtime. Run this BEFORE deploying code that reads/writes the
 * column.
 *
 * Runs on the 'inbox' connection, same as CreateInboxTables.
 */
class AddPelangganIdToConversations extends Migration
{
    protected $DBGroup = 'inbox';

    public function up()
    {
        if ($this->db->fieldExists('pelanggan_id', 'conversations')) {
            return;
        }

        $this->forge->addColumn('conversations', [
            'pelanggan_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'default'    => null,
                'after'      => 'manual_phone',
            ],
        ]);
    }

    public function down()
    {
        if ($this->db->fieldExists('pelanggan_id', 'conversations')) {
            $this->forge->dropColumn('conversations', 'pelanggan_id');
        }
    }
}
