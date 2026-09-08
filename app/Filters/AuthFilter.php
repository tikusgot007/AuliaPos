<?php

namespace App\Filters;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;

class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // 🔥 Endpoint machine-to-machine Gateway WhatsApp -- TIDAK PERNAH
        // punya session (Gateway itu proses Node.js terpisah, bukan
        // browser kasir yang login), diproteksi sendiri lewat filter
        // 'gatewaytoken' (Bearer token, lihat
        // app/Filters/GatewayTokenFilter.php dan Routes.php).
        //
        // Dicek EKSPLISIT di sini (bukan hanya mengandalkan 'except'
        // di Config/Filters.php) supaya perilakunya pasti dan tidak
        // bergantung pada detail mekanisme pattern-matching framework
        // -- pernah ditemukan kasus di mana 'except' saja tidak cukup
        // untuk mengecualikan endpoint ini dari filter session.
        //
        // Dibuat longgar (cek "mengandung", bukan harus PERSIS diawali
        // dari karakter pertama) -- terkonfirmasi perlu di server
        // production: getPath() di sana mengembalikan path dengan
        // sesuatu di depannya (kemungkinan terkait struktur subfolder
        // deployment) yang bikin pengecekan strict-prefix (=== 0)
        // gagal match walau strpos 'contains' berhasil.
        $uriGateway = service('uri')->getPath();
        if (strpos($uriGateway, 'api/inbox/gateway/') !== false) {
            return null;
        }

        // 🔥 Cek apakah user sudah login
        if (!session()->get('isLoggedIn')) {
            return redirect()->to('/login')->with('error', 'Silakan login terlebih dahulu.');
        }

        // 🔥 Cek session timeout (30 menit)
        $lastActivity = session()->get('last_activity');
        $timeout = 30 * 60; // 30 menit

        if ($lastActivity && (time() - $lastActivity > $timeout)) {
            session()->destroy();
            return redirect()->to('/login')->with('error', 'Session habis. Silakan login ulang.');
        }

        // 🔥 Update last activity
        session()->set('last_activity', time());

        // 🔥 Cek role untuk route tertentu (opsional)
        $uri = service('uri')->getPath();

        // Route yang hanya boleh diakses admin
        $adminRoutes = ['laporan', 'user-management', 'auth/tambah-user', 'auth/edit-user', 'auth/hapus-user', 'jadwal', 'migrasi-manual'];

        foreach ($adminRoutes as $route) {
            if (strpos($uri, $route) === 0 && session()->get('role') != 'admin') {
                return redirect()->to('/kasir')->with('error', 'Akses ditolak. Hanya untuk admin.');
            }
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Do nothing
    }
}
