<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Task Group 1.5 -- Customer Identity & Conversation Reconciliation.
 *
 * PENTING: migration ini jalan di koneksi 'inbox' (aulia_inboxdb),
 * SAMA seperti migration Phase 1 -- lihat $DBGroup di bawah.
 *
 * Latar belakang (lihat docs/aturan-bisnis-CHAT.md Section 11 untuk
 * penjelasan lengkap): `conversations.chat_id` unique dipakai sebagai
 * SATU-SATUNYA kunci pencarian conversation. WhatsApp kadang
 * melaporkan NOMOR YANG SAMA lewat JID berbeda (paling umum: `@lid`
 * lalu `@s.whatsapp.net`, atau sebaliknya, terutama setelah Gateway
 * reconnect) -- akibatnya AuliaPos membuat conversation KEDUA untuk
 * customer yang sebenarnya sama.
 *
 * SENGAJA TIDAK membuat tabel `customers`/CRM terpisah -- tidak ada
 * kebutuhan nyata (semua business rule Task Group 1.5 bisa dipenuhi
 * di level conversation + alias JID). `conversations.chat_id` TETAP
 * ada apa adanya (masih dipakai untuk kirim balasan lewat Gateway) --
 * TIDAK diganti jadi nomor telepon.
 *
 * Perubahan:
 * 1. Tabel baru `conversation_identities` -- alias many-to-one dari
 *    chat_id (JID) ke conversation_id. Satu conversation BISA punya
 *    lebih dari 1 baris di sini (mis. @lid lama + @s.whatsapp.net
 *    baru yang terbukti nomor yang sama) tanpa kehilangan histori
 *    JID lama (kalau JID lama itu muncul lagi, tetap dikenali).
 * 2. 4 kolom baru di `conversations`:
 *    - `whatsapp_name` -- push name dari WhatsApp, SELALU
 *      dimutakhirkan oleh Gateway, TERPISAH dari `contact_name`
 *      (yang sekarang murni nama manual/customer profile, dilindungi
 *      dari ketiban otomatis -- lihat InboxGatewayApi::messages()).
 *    - `manual_phone` -- nomor yang diketik MANUAL oleh kasir lewat
 *      fitur edit profil customer. SENGAJA kolom TERPISAH dari
 *      `phone` (yang HANYA diisi dari nomor ter-verifikasi WhatsApp,
 *      di-derive Gateway dari JID `@s.whatsapp.net` asli) --
 *      `manual_phone` TIDAK PERNAH dipakai untuk reconciliation
 *      (mencegah auto-merge yang salah hanya karena kasir mengetik
 *      nomor yang keliru/belum tentu benar).
 *    - `profile_updated_at`, `profile_updated_by` -- audit ringan
 *      KHUSUS untuk perubahan manual customer profile (nama/nomor),
 *      TIDAK ikut tersentuh oleh update otomatis lain (mis. pesan
 *      masuk baru) -- beda dari `updated_at` umum yang dipakai
 *      banyak hal.
 *
 * DATA EXISTING: migration ini otomatis mem-backfill 1 baris
 * `conversation_identities` untuk SETIAP conversation yang sudah ada
 * (mencerminkan chat_id-nya saat ini) -- TIDAK ADA data yang hilang,
 * TIDAK ADA conversation yang digabung/dihapus. Conversation
 * duplikat lama (mis. @lid + @s.whatsapp.net yang sebenarnya nomor
 * sama) TETAP terpisah setelah migration ini -- migration ini hanya
 * mencegah DUPLIKAT BARU ke depannya, bukan menggabungkan yang sudah
 * ada (sengaja, sesuai aturan "jangan auto-merge history secara
 * agresif").
 */
class AddConversationIdentityReconciliation extends Migration
{
    protected $DBGroup = 'inbox';

    public function up()
    {
        // ============================================================
        // conversations -- kolom baru
        // ============================================================
        $this->forge->addColumn('conversations', [
            'whatsapp_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'contact_name',
            ],
            'manual_phone' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => true,
                'after'      => 'phone',
            ],
            'profile_updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            // Logical reference ke aulia_kasirdb.users.id -- SAMA
            // pola dengan assigned_to/last_replied_by/closed_by yang
            // sudah ada (lihat migration Phase 1), BUKAN foreign key
            // sungguhan (beda database).
            'profile_updated_by' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],
        ]);

        // ============================================================
        // conversation_identities (baru)
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
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('chat_id');
        $this->forge->addKey('conversation_id');
        // FK INTERNAL (dalam aulia_inboxdb saja, sama seperti
        // messages.conversation_id) -- BUKAN logical reference,
        // karena kedua tabel ada di database yang sama.
        $this->forge->addForeignKey('conversation_id', 'conversations', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('conversation_identities', true, [
            'ENGINE'  => 'InnoDB',
            'CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_general_ci',
        ]);

        // ============================================================
        // Backfill: setiap conversation yang sudah ada dapat 1 baris
        // alias yang mencerminkan chat_id-nya SAAT INI. Aman dijalankan
        // di atas data live -- murni INSERT tambahan, tidak mengubah/
        // menghapus baris conversations/messages manapun.
        // ============================================================
        $this->db->query(
            'INSERT INTO conversation_identities (conversation_id, chat_id, jid_type, created_at)
             SELECT id, chat_id, jid_type, COALESCE(created_at, NOW())
             FROM conversations'
        );
    }

    public function down()
    {
        $this->forge->dropTable('conversation_identities', true);
        $this->forge->dropColumn('conversations', ['whatsapp_name', 'manual_phone', 'profile_updated_at', 'profile_updated_by']);
    }
}
