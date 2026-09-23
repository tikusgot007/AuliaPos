<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIsInternalToMessages extends Migration
{
    protected $DBGroup = 'inbox';

    public function up()
    {
        $this->forge->addColumn('messages', [
            'is_internal' => [
                'type' => 'BOOLEAN',
                'null' => false,
                'default' => false,
                'after' => 'direction',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('messages', 'is_internal');
    }
}
