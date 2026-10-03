<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model untuk tabel `balasan_template` di database aulia_inboxdb.
 *
 * PENTING: $DBGroup eksplisit 'inbox', sama seperti ConversationModel /
 * MessageModel -- lihat keputusan Option B di
 * docs/design/2026-10-03-template-balasan-cepat.md Section 3.
 */
class BalasanTemplateModel extends Model
{
    protected $DBGroup       = 'inbox';
    protected $table         = 'balasan_template';
    protected $primaryKey    = 'id';
    protected $allowedFields = ['nama', 'teks', 'gambar_filename'];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    // AC-3: minimal salah satu dari teks/gambar_filename wajib diisi.
    // Keunikan nama (is_unique) SENGAJA tidak di sini -- butuh koneksi DB
    // dan sudah dicek terpisah oleh controller lewat $this->validate()
    // pada input request mentah (yang juga memvalidasi file upload
    // 'gambar', field berbeda dari 'gambar_filename' yang disimpan).
    protected $validationRules = [
        'nama'            => 'required|min_length[2]',
        'teks'            => 'permit_empty|required_without[gambar_filename]',
        'gambar_filename' => 'permit_empty|required_without[teks]',
    ];
}
