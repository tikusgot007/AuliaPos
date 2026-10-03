<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Template Balasan Cepat (Inbox WhatsApp), TODO-R1.
 *
 * Additive only: creates `balasan_template` on the Inbox database (DB
 * group `inbox`), following the design decision in
 * docs/design/2026-10-03-template-balasan-cepat.md Section 3 (Option B,
 * chosen by the user over the design's default recommendation of
 * `database.default`).
 */
class CreateBalasanTemplate extends Migration
{
    protected $DBGroup = 'inbox';

    public function up()
    {
        if ($this->db->tableExists('balasan_template')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 10,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'nama' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => false,
            ],
            'teks' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'gambar_filename' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('nama');
        $this->forge->createTable('balasan_template', true, [
            'ENGINE'  => 'InnoDB',
            'CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_general_ci',
        ]);
    }

    public function down()
    {
        $this->forge->dropTable('balasan_template', true);
    }
}
