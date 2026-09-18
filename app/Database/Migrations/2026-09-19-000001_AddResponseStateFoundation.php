<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddResponseStateFoundation extends Migration
{
    protected $DBGroup = 'inbox'; // SAMA seperti migration Inbox lain, lihat catatan di bawah.

    public function up()
    {
        $this->forge->addColumn('conversations', [
            'last_seen_by_assignee_at' => [
                'type' => 'DATETIME', 'null' => true, 'after' => 'assigned_to',
            ],
            'snoozed_until' => [
                'type' => 'DATETIME', 'null' => true, 'after' => 'closed_by',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('conversations', ['last_seen_by_assignee_at', 'snoozed_until']);
    }
}
