<?php

namespace App\Controllers;

use App\Models\TransaksiModel;

/**
 * Admin-only. Pengganti Scheduled Task untuk `aulia:auto-selesai`: admin
 * melihat jumlah kandidat (preview, read-only), lalu menekan tombol untuk
 * menyelesaikannya. Logika bisnis ada di
 * TransaksiModel::autoSelesaikanLunasLama() (lewat ubahStatus(), jadi syarat
 * LUNAS tetap diperiksa ulang) -- controller ini hanya orkestrasi HTTP.
 *
 * Proteksi admin DUA LAPIS (pola sama dengan ArchiveTransaksi):
 * 1. prefix 'auto-selesai-transaksi' ada di AuthFilter::$adminRoutes
 * 2. dicek ulang inline di cekAdmin() tiap method.
 */
class AutoSelesaiTransaksi extends BaseController
{
    /** Umur minimal (hari, dari tanggal transaksi). Sama dengan default command. */
    private const HARI = 7;

    /** Maks transaksi yang diubah per request supaya tidak kena batas waktu PHP. */
    private const BATCH = 300;

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

        $hasil = (new TransaksiModel())->autoSelesaikanLunasLama(self::HARI, true);

        return view('layout/main', [
            'title'    => 'Auto-Selesai Transaksi | AULIA',
            'content'  => 'auto_selesai_transaksi/index',
            'hari'     => self::HARI,
            'kandidat' => $hasil['kandidat'],
        ]);
    }

    /**
     * AJAX -- read-only, tidak mengubah apa pun.
     */
    public function preview()
    {
        if ($redirect = $this->cekAdmin()) {
            return $redirect;
        }

        try {
            $hasil = (new TransaksiModel())->autoSelesaikanLunasLama(self::HARI, true);

            return $this->response->setJSON([
                'status' => 'success',
                'data'   => ['kandidat' => $hasil['kandidat'], 'hari' => self::HARI],
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ])->setStatusCode(422);
        }
    }

    /**
     * AJAX -- eksekusi satu batch (maks BATCH transaksi). UI memanggil
     * berulang sampai `sisa` 0 atau tidak ada kemajuan.
     */
    public function jalankan()
    {
        if ($redirect = $this->cekAdmin()) {
            return $redirect;
        }

        $userId = (int) (session()->get('id_user') ?? 0);
        $model  = new TransaksiModel();

        try {
            // Backup id + status lama SEBELUM mengubah (superset: semua kandidat).
            $kandidat = $model->autoSelesaikanLunasLama(self::HARI, true);
            $backup   = $this->tulisBackup($kandidat['ids']);

            $hasil = $model->autoSelesaikanLunasLama(self::HARI, false, self::BATCH);
            $sisa  = $model->autoSelesaikanLunasLama(self::HARI, true)['kandidat'];

            log_message(
                'info',
                'Auto-Selesai Transaksi dijalankan oleh user_id=' . $userId
                    . ' kandidat=' . $hasil['kandidat']
                    . ' diubah=' . $hasil['diubah']
                    . ' dilewati=' . count($hasil['dilewati'])
                    . ' sisa=' . $sisa
                    . ' backup=' . $backup
            );

            return $this->response->setJSON([
                'status' => 'success',
                'data'   => [
                    'diubah'   => $hasil['diubah'],
                    'dilewati' => count($hasil['dilewati']),
                    'sisa'     => $sisa,
                    'backup'   => $backup,
                ],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Auto-Selesai Transaksi GAGAL: ' . $e->getMessage());

            return $this->response->setJSON([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ])->setStatusCode(422);
        }
    }

    /**
     * Tulis CSV (id, status_lama) ke writable/backups (di-ignore git).
     * Mengembalikan nama file; kosong kalau tidak ada kandidat.
     */
    private function tulisBackup(array $ids): string
    {
        if (!$ids) {
            return '';
        }

        $dir = WRITEPATH . 'backups';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Folder backup tidak bisa dibuat.');
        }

        $nama = 'auto-selesai-' . date('Ymd-His') . '.csv';
        $isi  = "id,status_lama\n";
        foreach ($ids as $id) {
            $isi .= (int) $id . ",proses\n";
        }

        if (file_put_contents($dir . DIRECTORY_SEPARATOR . $nama, $isi) === false) {
            throw new \RuntimeException('Backup gagal ditulis; auto-selesai dibatalkan.');
        }

        return $nama;
    }
}
