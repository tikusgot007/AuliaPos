<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tahap D (Soft-Delete Conversation) -- kolom `deleted_at` di
 * `conversations` dan `messages`. Sebelum ini, Inbox::hapusPercakapan()
 * hard-delete permanen -- identitas customer yang sudah dikonfirmasi
 * (nomor asli, nama benar) hilang tanpa bisa dipulihkan karena tidak
 * ada tabel `customers` terpisah (identitas hidup di baris
 * `conversations` itu sendiri).
 *
 * PENTING: migration ini TIDAK memulihkan data yang sudah terhapus
 * SEBELUM Tahap D -- itu sudah hilang permanen. Ini cuma mencegah
 * kehilangan yang akan datang.
 */
class AddSoftDeleteInbox extends Migration
{
    protected $DBGroup = 'inbox';

    public function up()
    {
        $this->forge->addColumn('conversations', [
            'deleted_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'updated_at'],
        ]);
        $this->forge->addColumn('messages', [
            'deleted_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'created_at'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('conversations', ['deleted_at']);
        $this->forge->dropColumn('messages', ['deleted_at']);
    }
}
