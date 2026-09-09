<?php

namespace App\Controllers;

use App\Services\TransaksiArchiveService;

/**
 * Admin-only. Lihat App\Services\TransaksiArchiveService untuk engine
 * archive-nya sendiri -- controller ini murni orkestrasi HTTP
 * (validasi input dasar, panggil service, kembalikan response).
 *
 * Proteksi admin DUA LAPIS (pola yang sama dengan MigrasiManual):
 * 1. prefix 'archive-transaksi' ada di AuthFilter::$adminRoutes
 * 2. dicek ulang inline di cekAdmin() tiap method, jaga-jaga kalau
 *    filter di atas suatu saat berubah/lupa di-apply ke route baru.
 */
class ArchiveTransaksi extends BaseController
{
    private function cekAdmin()
    {
        if (!session()->get('isLoggedIn') || session()->get('role') != 'admin') {
            return redirect()->to('/login')->with('error', 'Akses ditolak.');
        }

        return null;
    }

    public function index()
    {
        if ($redirect = $this->cekAdmin()) {
            return $redirect;
        }

        $service = new TransaksiArchiveService();

        $data = [
            'title'   => 'Archive Transaksi | AULIA',
            'content' => 'archive_transaksi/index',
            'daftar_bulan' => $service->getDaftarBulan(),
        ];

        return view('layout/main', $data);
    }

    /**
     * AJAX -- preview read-only, tidak mengubah apa pun.
     */
    public function preview()
    {
        if ($redirect = $this->cekAdmin()) {
            return $redirect;
        }

        $bulan = $this->request->getJSON(true)['bulan'] ?? [];

        if (!is_array($bulan)) {
            $bulan = [];
        }

        try {
            $service = new TransaksiArchiveService();
            $hasil = $service->preview($bulan);

            return $this->response->setJSON([
                'status' => 'success',
                'data'   => $hasil,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ])->setStatusCode(422);
        }
    }

    /**
     * AJAX -- eksekusi archive (destruktif). Lihat
     * TransaksiArchiveService::jalankan() untuk urutan keamanannya.
     */
    public function jalankan()
    {
        if ($redirect = $this->cekAdmin()) {
            return $redirect;
        }

        $bulan = $this->request->getJSON(true)['bulan'] ?? [];

        if (!is_array($bulan)) {
            $bulan = [];
        }

        $userId = (int) (session()->get('id_user') ?? 0);

        try {
            $service = new TransaksiArchiveService();
            $hasil = $service->jalankan($bulan, $userId);

            log_message(
                'info',
                'Archive Transaksi dijalankan oleh user_id=' . $userId
                    . ' bulan=' . implode(',', $bulan)
                    . ' hasil=' . json_encode($hasil)
            );

            return $this->response->setJSON([
                'status' => 'success',
                'data'   => $hasil,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Archive Transaksi GAGAL: ' . $e->getMessage());

            return $this->response->setJSON([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ])->setStatusCode(422);
        }
    }
}
