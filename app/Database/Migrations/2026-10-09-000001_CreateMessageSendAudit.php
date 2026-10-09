<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * L3: audit kiriman keluar yang GAGAL di jalur inbox (gateway menolak / hasil
 * ambigu / error jaringan). Tabel ini append-only dan TERPISAH dari `messages`
 * (CON-009-style): tidak ada baris `messages` yang ditulis/diubah, dan enum
 * `send_status` TIDAK disentuh.
 *
 * Ringkasan skema:
 *  - `operation_id` nullable (permintaan tanpa operation_id tetap boleh gagal).
 *  - `outcome_kind` = 'definitive' | 'unresolved' | 'network'.
 *  - `error_message` hanya pesan diagnosa (bukan dump); tabel ini bukan riwayat
 *    percakapan, jadi tidak menyimpan isi pesan.
 *  - `context` mencatat jalur pemanggil: 'kirimKeConversation', 'kirimMedia',
 *    'editPesan', 'hapusPesan', 'kirimTeruskanTeks', dst.
 *
 * DBGroup 'inbox' mengikuti MessageModel/ConversationModel supaya audit berada
 * di database yang sama dengan data inbox.
 */
class CreateMessageSendAudit extends Migration
{
    protected $DBGroup = 'inbox';

    public function up()
    {
        if ($this->db->tableExists('message_send_audit')) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'operation_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'conversation_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
            ],
            'direction' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'null'       => false,
                'default'    => 'outgoing',
            ],
            'outcome_kind' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => false,
            ],
            'error_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'error_message' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'http_code' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
            ],
            'context' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['conversation_id', 'created_at'], false, false, 'idx_msg_send_audit_conv_created');
        $this->forge->addKey('operation_id', false, false, 'idx_msg_send_audit_operation');
        $this->forge->addKey(['outcome_kind', 'created_at'], false, false, 'idx_msg_send_audit_outcome_created');

        $this->forge->createTable('message_send_audit', true);
    }

    public function down()
    {
        $this->forge->dropTable('message_send_audit', true);
    }
}
