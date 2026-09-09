<?php

namespace App\Filters;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;

class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
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
        $adminRoutes = ['laporan', 'user-management', 'auth/tambah-user', 'auth/edit-user', 'auth/hapus-user', 'jadwal', 'migrasi-manual', 'archive-transaksi'];

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
