<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TODO-L3: tambah pratinjau isi pesan/media yang GAGAL terkirim ke
 * `message_send_audit`, supaya halaman "Log Kiriman Gagal" bisa menunjukkan
 * pesan apa yang gagal (sebelumnya tabel ini sengaja tidak menyimpan isi
 * pesan sama sekali -- lihat komentar migrasi 2026-10-09).
 *
 * Perubahan additive murni: semua kolom baru nullable, tidak mengubah/
 * menghapus kolom lama, aman dijalankan tanpa downtime. Baris audit lama
 * (sebelum migrasi ini) akan punya nilai NULL di kolom baru -- halaman
 * tetap menampilkannya, hanya kolom pratinjau kosong untuk entri lama.
 *
 * Keputusan lingkup (sesi 2026-10-10): HANYA metadata ringan untuk media
 * (nama file/ukuran/tipe), BUKAN isi byte (base64) -- menghindari pembesaran
 * DB dan menjaga semangat SEC-001 (gateway juga sengaja tidak simpan isi
 * media). `preview_text` dipotong ke 1000 karakter di sisi penulis
 * (Inbox.php), bukan di migrasi ini.
 */
class AddPreviewToMessageSendAudit extends Migration
{
    protected $DBGroup = 'inbox';

    public function up()
    {
        $fields = [
            'preview_text' => [
                'type'       => 'VARCHAR',
                'constraint' => 1000,
                'null'       => true,
                'after'      => 'context',
            ],
            'media_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
                'after'      => 'preview_text',
            ],
            'media_file_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'media_type',
            ],
            'media_size' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'media_file_name',
            ],
        ];

        foreach ($fields as $name => $definition) {
            if ($this->db->fieldExists($name, 'message_send_audit')) {
                continue;
            }
            $this->forge->addColumn('message_send_audit', [$name => $definition]);
        }
    }

    public function down()
    {
        foreach (['media_size', 'media_file_name', 'media_type', 'preview_text'] as $name) {
            if ($this->db->fieldExists($name, 'message_send_audit')) {
                $this->forge->dropColumn('message_send_audit', $name);
            }
        }
    }
}
