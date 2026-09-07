<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table      = 'users';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'username',
        'nama',
        'inisial',
        'divisi',
        'password_hash',
        'role',
        'is_active',
        'no_hp',
        'profile_photo',
    ];

    // Tabel users hanya memiliki created_at,
    // tidak memiliki updated_at.
    protected $useTimestamps = false;

    /**
     * Cari user berdasarkan username
     */
    public function getUserByUsername($username)
    {
        return $this->where('username', $username)->first();
    }

    /**
     * Verifikasi password
     */
    public function verifyPassword($password, $hash)
    {
        return password_verify($password, $hash);
    }

    /**
     * Hash password
     */
    public function hashPassword($password)
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Update password user
     */
    public function updatePassword($userId, $newPassword)
    {
        return $this->update($userId, [
            'password_hash' => $this->hashPassword($newPassword)
        ]);
    }

    /**
     * Update "Profil Saya" milik user yang sedang login.
     *
     * SENGAJA hanya menerima 'nama' dan 'no_hp' di sini (bukan
     * array bebas dari controller) supaya field lain (username,
     * inisial, divisi, role, is_active) TIDAK PERNAH bisa
     * diubah lewat jalur profil sendiri, apa pun isi request-nya.
     * Ini pertahanan kedua di layer model, selain validasi di
     * controller (defense in depth) — lihat Profil::update().
     *
     * $userId HARUS berasal dari session (id_user), tidak pernah
     * dari input user. Itu jadi tanggung jawab controller pemanggil.
     */
    public function updateProfilSaya(int $userId, ?string $nama, ?string $noHp)
    {
        return $this->update($userId, [
            'nama'  => $nama,
            'no_hp' => $noHp,
        ]);
    }

    /**
     * Simpan nama file foto profil baru untuk sebuah user.
     * Hanya menerima nama file (bukan path), lihat FotoProfilService.
     */
    public function updateFotoProfil(int $userId, ?string $filename)
    {
        return $this->update($userId, [
            'profile_photo' => $filename,
        ]);
    }
}
