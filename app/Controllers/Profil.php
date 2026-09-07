<?php

namespace App\Controllers;

use App\Libraries\FotoProfilService;
use App\Models\UserModel;

/**
 * Controller "Profil Saya".
 *
 * ATURAN PALING PENTING (lihat docs/aturan-bisnis-AULIA.md Section 24):
 * Semua method di sini SELALU memakai session()->get('id_user')
 * sebagai target perubahan. TIDAK PERNAH menerima user_id dari
 * form/request sebagai target. Ini mencegah user A mengubah profil
 * user B hanya dengan memanipulasi request.
 *
 * Untuk kelola user LAIN (termasuk foto, username, inisial, divisi,
 * role, is_active), itu tugas Auth::userManagement() dkk (admin-only,
 * memakai ID target — itu memang wewenang admin).
 */
class Profil extends BaseController
{
    /**
     * Halaman Profil Saya.
     */
    public function index()
    {
        $userId = session()->get('id_user');
        $model  = new UserModel();
        $user   = $model->find($userId);

        if (!$user) {
            // Seharusnya tidak mungkin terjadi selama session valid
            // (filter auth sudah memastikan login), tapi jaga-jaga
            // kalau user dihapus admin di tengah session aktif.
            session()->destroy();
            return redirect()->to('/login')->with('error', 'Akun tidak ditemukan. Silakan login ulang.');
        }

        $data = [
            'title'   => 'Profil Saya | AULIA',
            'content' => 'profil/index',
            'user'    => $user,
        ];

        return view('layout/main', $data);
    }

    /**
     * Update nama & no. HP milik diri sendiri.
     *
     * SENGAJA tidak membaca 'user_id' dari POST sama sekali — target
     * selalu session()->get('id_user').
     */
    public function update()
    {
        $userId = session()->get('id_user');
        $model  = new UserModel();

        $nama  = trim((string) $this->request->getPost('nama'));
        $noHp  = trim((string) $this->request->getPost('no_hp'));

        if ($nama === '') {
            return redirect()->to('/profil')->with('error', 'Nama tidak boleh kosong.');
        }

        if ($noHp !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/', $noHp)) {
            return redirect()->to('/profil')->with('error', 'Format nomor HP tidak valid.');
        }

        $model->updateProfilSaya($userId, $nama, $noHp !== '' ? $noHp : null);

        return redirect()->to('/profil')->with('success', 'Profil berhasil diperbarui.');
    }

    /**
     * Upload/ganti foto profil milik diri sendiri.
     */
    public function uploadFoto()
    {
        $userId  = session()->get('id_user');
        $model   = new UserModel();
        $service = new FotoProfilService();

        $user = $model->find($userId);
        if (!$user) {
            return redirect()->to('/login')->with('error', 'Akun tidak ditemukan.');
        }

        $hasil = $service->simpan($this->request->getFile('foto'));

        if (!$hasil['success']) {
            return redirect()->to('/profil')->with('error', $hasil['error']);
        }

        // Simpan nama file baru dulu, baru hapus file lama, supaya
        // kalau proses update DB gagal, file lama masih ada
        // (tidak kehilangan foto sama sekali).
        $fotoLama = $user['profile_photo'] ?? null;
        $model->updateFotoProfil($userId, $hasil['filename']);
        $service->hapus($fotoLama);

        return redirect()->to('/profil')->with('success', 'Foto profil berhasil diperbarui.');
    }

    /**
     * Hapus foto profil milik diri sendiri (kembali ke avatar
     * inisial default).
     */
    public function hapusFoto()
    {
        $userId  = session()->get('id_user');
        $model   = new UserModel();
        $service = new FotoProfilService();

        $user = $model->find($userId);
        if (!$user) {
            return redirect()->to('/login')->with('error', 'Akun tidak ditemukan.');
        }

        $fotoLama = $user['profile_photo'] ?? null;

        $model->updateFotoProfil($userId, null);
        $service->hapus($fotoLama);

        return redirect()->to('/profil')->with('success', 'Foto profil berhasil dihapus.');
    }

    /**
     * Streaming file foto profil (dipakai untuk <img src>).
     *
     * Menerima nama file siapa pun (bukan cuma foto sendiri) karena
     * foto profil dipakai untuk menampilkan identitas user lain juga
     * (mis. di header, daftar Manajemen User, Jadwal Karyawan) —
     * bukan data sensitif. Nama file divalidasi ketat lewat
     * FotoProfilService::resolvePathUntukDitampilkan() untuk
     * mencegah path traversal.
     */
    public function foto(string $filename)
    {
        $service = new FotoProfilService();
        $path    = $service->resolvePathUntukDitampilkan($filename);

        if ($path === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        return $this->response
            ->setHeader('Cache-Control', 'private, max-age=86400')
            ->setContentType(mime_content_type($path) ?: 'application/octet-stream')
            ->setBody(file_get_contents($path));
    }
}
