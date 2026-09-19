<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tahap E -- hentikan retry berulang untuk media yang sudah dipastikan
 * kadaluarsa (410 Gone dari WhatsApp). Kolom BARU, bukan reuse
 * media_download_attempted_at (Tahap C) -- kolom itu maknanya "prefetch
 * saat pesan masuk sudah dicoba", bisa gagal karena alasan sementara
 * (timeout, Gateway down) yang masih layak dicoba ulang. Kolom ini
 * SENGAJA cuma diisi untuk 410 yang pasti final.
 */
class AddMediaConfirmedGone extends Migration
{
    protected $DBGroup = 'inbox';

    public function up()
    {
        $this->forge->addColumn('messages', [
            'media_confirmed_gone_at' => [
                'type' => 'DATETIME', 'null' => true, 'after' => 'media_download_attempted_at',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('messages', ['media_confirmed_gone_at']);
    }
}
