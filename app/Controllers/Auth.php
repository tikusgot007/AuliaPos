<?php

namespace App\Controllers;

use App\Libraries\FotoProfilService;
use App\Models\UserModel;

class Auth extends BaseController
{
    /**
     * Halaman Login
     */
    public function login()
    {
        // Jika sudah login, redirect ke kasir
        if (session()->get('isLoggedIn')) {
            return redirect()->to('/kasir');
        }

        $data = [
            'title' => 'Login | AULIA'
        ];

        return view('auth/login', $data);
    }

    /**
     * Proses Login
     */
    public function prosesLogin()
    {
        $session = session();
        $model = new UserModel();

        $username = $this->request->getPost('username');
        $password = $this->request->getPost('password');

        // 🔥 Validasi input
        if (empty($username) || empty($password)) {
            return redirect()->back()->with('error', 'Username dan password harus diisi.');
        }

        // 🔥 Cari user
        $user = $model->getUserByUsername($username);

        if (!$user) {
            return redirect()->back()->with('error', 'Username tidak ditemukan.');
        }

        // 🔥 Verifikasi password
        if (!$model->verifyPassword($password, $user['password_hash'])) {
            return redirect()->back()->with('error', 'Password salah.');
        }

        // 🔥 Set session
        $sessionData = [
            'id_user'    => $user['id'],
            'username'   => $user['username'],
            'role'       => $user['role'],
            'isLoggedIn' => true,
            'last_activity' => time()
        ];

        $session->set($sessionData);

        // 🔥 Redirect berdasarkan role
        if ($user['role'] === 'admin') {
            return redirect()->to('/laporan')->with('success', 'Selamat datang Admin!');
        } else {
            return redirect()->to('/kasir')->with('success', 'Selamat datang Kasir!');
        }
    }

    /**
     * Logout
     */
    public function logout()
    {
        session()->destroy();
        return redirect()->to('/login')->with('success', 'Anda berhasil logout.');
    }

    /**
     * Ganti Password
     */
    public function gantiPassword()
    {
        // 🔥 Cek login
        if (!session()->get('isLoggedIn')) {
            return redirect()->to('/login')->with('error', 'Silakan login terlebih dahulu.');
        }

        $data = [
            'title'   => 'Ganti Password | AULIA',
            'content' => 'auth/ganti_password'
        ];

        return view('layout/main', $data);
    }

    /**
     * Proses Ganti Password
     */
    public function prosesGantiPassword()
    {
        // 🔥 Cek login
        if (!session()->get('isLoggedIn')) {
            return redirect()->to('/login')->with('error', 'Silakan login terlebih dahulu.');
        }

        $session = session();
        $model = new UserModel();

        $userId = $session->get('id_user');
        $passwordLama = $this->request->getPost('password_lama');
        $passwordBaru = $this->request->getPost('password_baru');
        $konfirmasi = $this->request->getPost('konfirmasi_password');

        // 🔥 Validasi
        if (empty($passwordLama) || empty($passwordBaru) || empty($konfirmasi)) {
            return redirect()->back()->with('error', 'Semua field harus diisi.');
        }

        if ($passwordBaru !== $konfirmasi) {
            return redirect()->back()->with('error', 'Password baru dan konfirmasi tidak sama.');
        }

        if (strlen($passwordBaru) < 6) {
            return redirect()->back()->with('error', 'Password baru minimal 6 karakter.');
        }

        // 🔥 Cek user
        $user = $model->find($userId);
        if (!$user) {
            return redirect()->back()->with('error', 'User tidak ditemukan.');
        }

        // 🔥 Verifikasi password lama
        if (!$model->verifyPassword($passwordLama, $user['password_hash'])) {
            return redirect()->back()->with('error', 'Password lama salah.');
        }

        // 🔥 Update password
        if ($model->updatePassword($userId, $passwordBaru)) {
            return redirect()->to('/logout')->with('success', 'Password berhasil diubah. Silakan login ulang.');
        } else {
            return redirect()->back()->with('error', 'Gagal mengubah password.');
        }
    }

    /**
     * Manajemen User (Tambah/Edit/Hapus)
     */
    public function userManagement()
    {
        // 🔥 Hanya admin yang bisa akses
        if (!session()->get('isLoggedIn') || session()->get('role') != 'admin') {
            return redirect()->to('/login')->with('error', 'Akses ditolak.');
        }

        $model = new UserModel();
        $users = $model->findAll();

        $data = [
            'title'   => 'Manajemen User | AULIA',
            'content' => 'auth/user_management',
            'users'   => $users
        ];

        return view('layout/main', $data);
    }

    /**
     * Tambah User
     */
    public function tambahUser()
    {
        // 🔥 Hanya admin yang bisa akses
        if (!session()->get('isLoggedIn') || session()->get('role') != 'admin') {
            return redirect()->to('/login')->with('error', 'Akses ditolak.');
        }

        $data = [
            'title'   => 'Tambah User | AULIA',
            'content' => 'auth/tambah_user'
        ];

        return view('layout/main', $data);
    }

    /**
     * Simpan User Baru
     */
    public function simpanUser()
    {
        // 🔥 Hanya admin yang bisa akses
        if (!session()->get('isLoggedIn') || session()->get('role') != 'admin') {
            return redirect()->to('/login')->with('error', 'Akses ditolak.');
        }

        $model = new UserModel();

        $username = $this->request->getPost('username');
        $password = $this->request->getPost('password');
        $role = $this->request->getPost('role');
        $nama = trim((string) $this->request->getPost('nama'));
        $inisial = trim((string) $this->request->getPost('inisial'));
        $divisi = trim((string) $this->request->getPost('divisi'));
        $noHp = trim((string) $this->request->getPost('no_hp'));
        $isActive = $this->request->getPost('is_active') ? 1 : 0;

        // 🔥 Validasi
        if (empty($username) || empty($password) || empty($role)) {
            return redirect()->back()->withInput()->with('error', 'Username, password, dan role harus diisi.');
        }

        if (strlen($password) < 6) {
            return redirect()->back()->withInput()->with('error', 'Password minimal 6 karakter.');
        }

        // 🔥 Cek duplikat username
        $existing = $model->where('username', $username)->first();
        if ($existing) {
            return redirect()->back()->withInput()->with('error', 'Username sudah digunakan.');
        }

        // 🔥 Foto profil (opsional saat tambah user)
        $fotoService = new FotoProfilService();
        $fotoFilename = null;
        $fotoFile = $this->request->getFile('foto');
        if ($fotoFile && $fotoFile->isValid()) {
            $hasilFoto = $fotoService->simpan($fotoFile);
            if (!$hasilFoto['success']) {
                return redirect()->back()->withInput()->with('error', $hasilFoto['error']);
            }
            $fotoFilename = $hasilFoto['filename'];
        }

        // 🔥 Simpan user
        $data = [
            'username'      => $username,
            'password_hash' => $model->hashPassword($password),
            'role'          => $role,
            'nama'          => $nama !== '' ? $nama : null,
            'inisial'       => $inisial !== '' ? $inisial : null,
            'divisi'        => $divisi !== '' ? $divisi : null,
            'no_hp'         => $noHp !== '' ? $noHp : null,
            'is_active'     => $isActive,
            'profile_photo' => $fotoFilename,
        ];

        if ($model->save($data)) {
            return redirect()->to('/user-management')->with('success', 'User berhasil ditambahkan!');
        } else {
            if ($fotoFilename) {
                $fotoService->hapus($fotoFilename);
            }
            return redirect()->back()->withInput()->with('error', 'Gagal menambahkan user.');
        }
    }

    /**
     * Hapus User
     */
    public function hapusUser($id)
    {
        // 🔥 Hanya admin yang bisa akses
        if (!session()->get('isLoggedIn') || session()->get('role') != 'admin') {
            return redirect()->to('/login')->with('error', 'Akses ditolak.');
        }

        $model = new UserModel();

        // 🔥 Cegah hapus diri sendiri
        if ($id == session()->get('id_user')) {
            return redirect()->to('/user-management')->with('error', 'Tidak bisa menghapus akun sendiri.');
        }

        $user = $model->find($id);
        if (!$user) {
            return redirect()->to('/user-management')->with('error', 'User tidak ditemukan.');
        }

        if ($model->delete($id)) {
            // Bersihkan file foto profil (jika ada) supaya tidak
            // meninggalkan orphan file di disk.
            if (!empty($user['profile_photo'])) {
                (new FotoProfilService())->hapus($user['profile_photo']);
            }
            return redirect()->to('/user-management')->with('success', 'User berhasil dihapus.');
        } else {
            return redirect()->to('/user-management')->with('error', 'Gagal menghapus user.');
        }
    }

    /**
     * Edit User
     */
    public function editUser($id)
    {
        // 🔥 Hanya admin yang bisa akses
        if (!session()->get('isLoggedIn') || session()->get('role') != 'admin') {
            return redirect()->to('/login')->with('error', 'Akses ditolak.');
        }

        $model = new UserModel();
        $user = $model->find($id);

        if (!$user) {
            return redirect()->to('/user-management')->with('error', 'User tidak ditemukan.');
        }

        $data = [
            'title'   => 'Edit User | AULIA',
            'content' => 'auth/edit_user',
            'user'    => $user
        ];

        return view('layout/main', $data);
    }

    /**
     * Update User
     */
    public function updateUser($id)
    {
        // 🔥 Hanya admin yang bisa akses
        if (!session()->get('isLoggedIn') || session()->get('role') != 'admin') {
            return redirect()->to('/login')->with('error', 'Akses ditolak.');
        }

        $model = new UserModel();
        $user = $model->find($id);

        if (!$user) {
            return redirect()->to('/user-management')->with('error', 'User tidak ditemukan.');
        }

        $username = $this->request->getPost('username');
        $role = $this->request->getPost('role');
        $password = $this->request->getPost('password');
        $nama = trim((string) $this->request->getPost('nama'));
        $inisial = trim((string) $this->request->getPost('inisial'));
        $divisi = trim((string) $this->request->getPost('divisi'));
        $noHp = trim((string) $this->request->getPost('no_hp'));
        $isActive = $this->request->getPost('is_active') ? 1 : 0;
        $hapusFoto = (bool) $this->request->getPost('hapus_foto');

        // 🔥 Validasi
        if (empty($username) || empty($role)) {
            return redirect()->back()->withInput()->with('error', 'Username dan role harus diisi.');
        }

        // 🔥 Cegah admin menonaktifkan/mengubah role akun sendiri
        // jadi non-admin (supaya tidak mengunci diri sendiri keluar
        // dari akses admin secara tidak sengaja).
        if ((int) $id === (int) session()->get('id_user')) {
            if ($role !== 'admin') {
                return redirect()->back()->withInput()->with('error', 'Tidak bisa mengubah role akun sendiri menjadi bukan admin.');
            }
            if ($isActive !== 1) {
                return redirect()->back()->withInput()->with('error', 'Tidak bisa menonaktifkan akun sendiri.');
            }
        }

        // 🔥 Cek duplikat username (kecuali dirinya sendiri)
        $existing = $model->where('username', $username)->where('id !=', $id)->first();
        if ($existing) {
            return redirect()->back()->withInput()->with('error', 'Username sudah digunakan.');
        }

        // 🔥 Data update
        $data = [
            'username'  => $username,
            'role'      => $role,
            'nama'      => $nama !== '' ? $nama : null,
            'inisial'   => $inisial !== '' ? $inisial : null,
            'divisi'    => $divisi !== '' ? $divisi : null,
            'no_hp'     => $noHp !== '' ? $noHp : null,
            'is_active' => $isActive,
        ];

        // 🔥 Jika password diisi, update password
        if (!empty($password)) {
            if (strlen($password) < 6) {
                return redirect()->back()->withInput()->with('error', 'Password minimal 6 karakter.');
            }
            $data['password_hash'] = $model->hashPassword($password);
        }

        // 🔥 Kelola foto profil user (opsional): upload baru,
        // hapus, atau tidak diubah sama sekali.
        $fotoService = new FotoProfilService();
        $fotoLama = $user['profile_photo'] ?? null;
        $fotoBaruFilename = null;

        $fotoFile = $this->request->getFile('foto');
        if ($fotoFile && $fotoFile->isValid()) {
            $hasilFoto = $fotoService->simpan($fotoFile);
            if (!$hasilFoto['success']) {
                return redirect()->back()->withInput()->with('error', $hasilFoto['error']);
            }
            $fotoBaruFilename = $hasilFoto['filename'];
            $data['profile_photo'] = $fotoBaruFilename;
        } elseif ($hapusFoto) {
            $data['profile_photo'] = null;
        }

        if ($model->update($id, $data)) {
            // Bersihkan file lama HANYA setelah update DB berhasil,
            // dan HANYA jika memang ada penggantian/penghapusan foto.
            if ($fotoBaruFilename && $fotoLama) {
                $fotoService->hapus($fotoLama);
            } elseif ($hapusFoto && $fotoLama) {
                $fotoService->hapus($fotoLama);
            }
            return redirect()->to('/user-management')->with('success', 'User berhasil diperbarui!');
        } else {
            if ($fotoBaruFilename) {
                $fotoService->hapus($fotoBaruFilename);
            }
            return redirect()->back()->withInput()->with('error', 'Gagal memperbarui user.');
        }
    }
}
