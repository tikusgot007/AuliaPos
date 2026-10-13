<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Audit log endpoint GET /api/inbox/mara/resolve/{conversation_id}.
 *
 * Endpoint Resolve membuka IDENTITAS ASLI pelanggan (nama, nomor,
 * histori pesan lengkap) -- beda dari endpoint Export yang anonim.
 * Sesuai kesepakatan desain (lihat docs/aturan-bisnis-AULIA.md /
 * ide M.A.R.A): resolve HANYA dipanggil saat M.A.R.A eskalasi kasus
 * ke pemilik, BUKAN dipanggil otomatis tiap training -- jadi SETIAP
 * panggilan wajib tercatat (siapa/kapan/alasan) untuk audit.
 *
 * Jalan di koneksi 'inbox' (aulia_inboxdb) -- SAMA seperti tabel
 * conversations/messages yang di-audit, BUKAN koneksi default
 * AuliaPos, konsisten dengan migration CreateInboxTables.
 */
class CreateMaraResolveLog extends Migration
{
    protected $DBGroup = 'inbox';

    public function up()
    {
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
            // Alasan/konteks eskalasi yang dikirim M.A.R.A saat memanggil
            // endpoint ini (mis. "pola ambigu saat training histori",
            // "order mandek >2 jam") -- bebas teks, tidak divalidasi
            // format, HANYA untuk jejak audit manusia baca.
            'reason' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            // Request IP M.A.R.A (aulia7) -- membantu deteksi kalau token
            // bocor dan dipanggil dari IP tak terduga.
            'requested_from_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('conversation_id');
        $this->forge->addKey('created_at');
        // TANPA foreign key sungguhan ke conversations -- konsisten dengan
        // keputusan arsitektur "logical reference" di CreateInboxTables
        // (baris yang di-resolve bisa saja sudah soft-deleted, log tetap
        // harus bisa disimpan apa pun kondisi baris sumbernya).
        $this->forge->createTable('mara_resolve_log', true, [
            'ENGINE'  => 'InnoDB',
            'CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_general_ci',
        ]);
    }

    public function down()
    {
        $this->forge->dropTable('mara_resolve_log', true);
    }
}