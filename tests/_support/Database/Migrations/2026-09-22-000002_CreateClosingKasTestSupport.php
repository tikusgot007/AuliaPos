<?php

namespace Tests\Support\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateClosingKasTestSupport extends Migration
{
    protected $DBGroup = 'tests';

    public function up(): void
    {
        if ($this->db->tableExists('closing_kas')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'INTEGER',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'tanggal' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'saldo_sistem' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'null'       => false,
            ],
            'saldo_fisik' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'null'       => false,
            ],
            'selisih' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'null'       => false,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'updated_by' => [
                'type' => 'INTEGER',
                'null' => false,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('tanggal');
        $this->forge->addKey('updated_by');
        $this->forge->createTable('closing_kas');
    }

    public function down(): void
    {
        $this->forge->dropTable('closing_kas', true);
    }
}
