<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tahap C (docs/.../Tahap-B-C-Thumbnail-dan-Storage-Permanen.md, Langkah C4)
 * -- 2 kolom baru di `messages` untuk penyimpanan permanen media ke disk
 * lokal/HDD eksternal (lihat App\Libraries\InboxMediaStorage).
 *
 * media_local_filename = NULL dan media_download_attempted_at = NULL
 * -> belum pernah dicoba. media_local_filename = NULL tapi
 * media_download_attempted_at terisi -> sudah dicoba, gagal.
 */
class AddMediaLocalStorage extends Migration
{
    protected $DBGroup = 'inbox';

    public function up()
    {
        $this->forge->addColumn('messages', [
            'media_local_filename' => [
                'type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'media_metadata',
            ],
            'media_download_attempted_at' => [
                'type' => 'DATETIME', 'null' => true, 'after' => 'media_local_filename',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('messages', ['media_local_filename', 'media_download_attempted_at']);
    }
}
