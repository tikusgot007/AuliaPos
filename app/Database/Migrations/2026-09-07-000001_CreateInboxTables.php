<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 1 -- Fondasi database Shared WhatsApp Inbox.
 *
 * PENTING: migration ini jalan di koneksi 'inbox' (aulia_inboxdb),
 * BUKAN koneksi default AuliaPos -- lihat $DBGroup di bawah.
 * Menjalankan `php spark migrate` akan otomatis memproses migration
 * ini di database yang benar berkat $DBGroup, tidak tercampur dengan
 * migration AuliaPos yang lain.
 *
 * Skema persis mengikuti spec Shared WhatsApp Inbox (lihat
 * docs/aturan-bisnis-AULIA.md Section 28). Field assigned_to,
 * last_replied_by, closed_by, dan sent_by_user_id adalah LOGICAL
 * REFERENCE ke aulia_kasirdb.users.id -- SENGAJA TIDAK dibuat
 * foreign key database sungguhan, karena keduanya database yang
 * benar-benar terpisah (MySQL tidak mendukung FK lintas database
 * dengan aman, dan ini juga keputusan arsitektur eksplisit dari
 * spec: "Ketiganya adalah logical reference ke aulia_kasirdb.users.id").
 */
class CreateInboxTables extends Migration
{
    protected $DBGroup = 'inbox';

    public function up()
    {
        // ============================================================
        // conversations
        // ============================================================
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'chat_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
                'null'       => false,
            ],
            'jid_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => false,
            ],
            'contact_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'phone' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['open', 'closed'],
                'default'    => 'open',
                'null'       => false,
            ],
            // Logical reference ke aulia_kasirdb.users.id -- lihat
            // catatan di docblock class ini.
            'assigned_to' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],
            'last_message_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'last_message_direction' => [
                'type'       => 'ENUM',
                'constraint' => ['incoming', 'outgoing'],
                'null'       => true,
            ],
            // Logical reference ke aulia_kasirdb.users.id.
            'last_replied_by' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],
            'closed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            // Logical reference ke aulia_kasirdb.users.id.
            'closed_by' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
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
        $this->forge->addUniqueKey('chat_id');
        $this->forge->addKey('status');
        $this->forge->addKey('assigned_to');
        $this->forge->addKey('last_message_at');
        $this->forge->createTable('conversations', true, [
            'ENGINE'  => 'InnoDB',
            'CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_general_ci',
        ]);

        // ============================================================
        // messages
        // ============================================================
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
            'wa_message_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => false,
            ],
            'direction' => [
                'type'       => 'ENUM',
                'constraint' => ['incoming', 'outgoing'],
                'null'       => false,
            ],
            'message_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => false,
            ],
            'sender_jid' => [
                'type'       => 'VARCHAR',
                'constraint' => 191,
                'null'       => true,
            ],
            'text' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            // Kolom media_* disiapkan untuk fitur masa depan
            // (image/document/audio/dst) -- TIDAK dipakai di POC ini
            // (hanya message_type='text'), tapi disiapkan sesuai
            // permintaan spec supaya skema extensible tanpa migration
            // tambahan nanti.
            'media_path' => [
                'type'       => 'VARCHAR',
                'constraint' => 500,
                'null'       => true,
            ],
            'media_mime_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'media_filename' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'media_size' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],
            'media_sha256' => [
                'type'       => 'CHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'media_metadata' => [
                'type' => 'JSON',
                'null' => true,
            ],
            'message_timestamp' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            // Logical reference ke aulia_kasirdb.users.id.
            'sent_by_user_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],
            'send_status' => [
                'type'       => 'ENUM',
                'constraint' => ['received', 'sent', 'failed'],
                'null'       => false,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('wa_message_id');
        $this->forge->addKey(['conversation_id', 'message_timestamp']);
        $this->forge->addKey('sent_by_user_id');
        // FK INTERNAL (dalam aulia_inboxdb saja) -- messages.conversation_id
        // -> conversations.id. Ini BEDA dari logical reference ke users di
        // atas; FK ini valid karena kedua tabel ada di database yang sama.
        $this->forge->addForeignKey('conversation_id', 'conversations', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('messages', true, [
            'ENGINE'  => 'InnoDB',
            'CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_general_ci',
        ]);

        // ============================================================
        // gateway_status
        // ============================================================
        // id SELALU 1 (single gateway), bukan auto_increment.
        $this->forge->addField([
            'id' => [
                'type'       => 'TINYINT',
                'constraint' => 3,
                'unsigned'   => true,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => false,
            ],
            'phone' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => true,
            ],
            'gateway_version' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => true,
            ],
            'last_heartbeat_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'last_connected_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->createTable('gateway_status', true, [
            'ENGINE'  => 'InnoDB',
            'CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_general_ci',
        ]);
    }

    public function down()
    {
        // Urutan drop: messages dulu (punya FK ke conversations),
        // baru conversations, baru gateway_status (independen).
        $this->forge->dropTable('messages', true);
        $this->forge->dropTable('conversations', true);
        $this->forge->dropTable('gateway_status', true);
    }
}
