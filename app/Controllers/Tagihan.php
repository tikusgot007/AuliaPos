<?php

namespace App\Controllers;

use App\Models\TransaksiModel;
use App\Models\DetailTransaksiModel;
use App\Models\PembayaranModel;
use App\Models\PelangganModel;

class Tagihan extends BaseController
{
    public function index()
    {
        $transaksiModel = new TransaksiModel();

        // Filter opsional "hanya tagihan saya" (dipakai oleh link
        // reminder tagihan di halaman Kasir). SENGAJA selalu memakai
        // session()->get('id_user'), tidak pernah menerima ID user
        // dari query string -- supaya tidak bisa dipakai mengintip
        // filter atas nama orang lain sekadar dengan mengganti angka
        // di URL. Kalau parameter tidak ada, perilaku default (tampil
        // semua tagihan) tidak berubah sama sekali.
        $hanyaSaya = $this->request->getGet('saya') == '1';

        $query = $transaksiModel
            ->select('transaksi.*, pelanggan.nama as pelanggan_nama, users.username as kasir_nama')
            ->join('pelanggan', 'pelanggan.id = transaksi.pelanggan_id', 'left')
            ->join('users', 'users.id = transaksi.kasir_id', 'left')
            // Tagihan ditentukan oleh status pembayaran, bukan status pekerjaan.
            // Transaksi PROSES maupun SELESAI tetap dapat memiliki tagihan.
            // Transaksi BATAL & MANGKRAK tidak masuk daftar tagihan --
            // batal dianggap tidak pernah terjadi, mangkrak sengaja
            // "dilepas" dari radar aktif meski transaksinya nyata
            // (lihat TransaksiModel::ubahStatus() & docs Section 28).
            ->where('transaksi.status_pembayaran !=', 'lunas')
            ->whereNotIn('transaksi.status', ['batal', 'mangkrak']);

        if ($hanyaSaya) {
            $query->where('transaksi.kasir_id', (int) session()->get('id_user'));
        }

        $tagihan = $query->orderBy('transaksi.tanggal', 'DESC')->findAll();

        $data = [
            'title'   => 'Tagihan | AULIA',
            'content' => 'tagihan/index',
            'tagihan' => $tagihan
        ];

        return view('layout/main', $data);
    }

    public function detail($id)
    {
        $transaksiModel = new TransaksiModel();
        $detailModel = new DetailTransaksiModel();
        $pembayaranModel = new PembayaranModel();
        $pelangganModel = new PelangganModel();

        // Ambil data transaksi
        $transaksi = $transaksiModel->select('transaksi.*, users.username as kasir_nama')
            ->join('users', 'users.id = transaksi.kasir_id', 'left')
            ->find($id);

        if (!$transaksi) {
            return redirect()->to('/tagihan')->with('error', 'Transaksi tidak ditemukan.');
        }

        $detailItems = $detailModel->where('transaksi_id', $id)->findAll();
        $pembayaran = $pembayaranModel
            ->select('pembayaran.*, users.nama as kasir_nama, users.username as kasir_username')
            ->join('users', 'users.id = pembayaran.kasir_id', 'left')
            ->where('pembayaran.transaksi_id', $id)
            ->where('pembayaran.status', 'aktif')
            ->orderBy('pembayaran.tanggal', 'ASC')
            ->findAll();
        $pelanggan = $transaksi['pelanggan_id'] ? $pelangganModel->find($transaksi['pelanggan_id']) : null;

        $total_dibayar = array_sum(array_column($pembayaran, 'jumlah'));
        $sisa_tagihan = $transaksi['grand_total'] - $total_dibayar;

        // 🔥 Flag: apakah ini dari halaman tagihan?
        $dariTagihan = true; // Karena ini controller Tagihan

        $data = [
            'title'         => 'Detail Tagihan | AULIA',
            'content'       => 'transaksi/detail', // 🔥 Pakai view yang sama
            'transaksi'     => $transaksi,
            'detail_items'  => $detailItems,
            'pembayaran'    => $pembayaran,
            'pelanggan'     => $pelanggan,
            'total_dibayar' => $total_dibayar,
            'sisa_tagihan'  => $sisa_tagihan,
            //'dariTagihan'   => $dariTagihan // 🔥 Flag untuk menampilkan tombol lunasi
        ];

        return view('layout/main', $data);
    }

    public function lunasi($id)
    {
        $transaksiModel = new TransaksiModel();
        $transaksi = $transaksiModel->find($id);

        if (!$transaksi) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Transaksi tidak ditemukan.'
            ]);
        }

        // BATAL adalah status terminal dan tidak boleh menerima pembayaran baru.
        if (($transaksi['status'] ?? '') === 'batal') {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Transaksi BATAL tidak dapat menerima pembayaran.'
            ])->setStatusCode(422);
        }

        if ($transaksi['status_pembayaran'] === 'lunas') {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Transaksi sudah lunas.'
            ]);
        }

        $request = $this->request->getJSON();
        $metode = $request->metode ?? 'tunai';

        $isAdmin = session()->get('role') === 'admin';
        $kasirIdSesi = session()->get('id_user') ?? 1;

        // Hitung sisa tagihan
        $pembayaranModel = new PembayaranModel();
        $totalDibayar = $pembayaranModel->getTotalDibayar($id);
        $sisa = $transaksi['grand_total'] - $totalDibayar;

        if ($sisa <= 0) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Sisa tagihan Rp 0. Transaksi sudah lunas.'
            ]);
        }

        // Catat pembayaran pelunasan
        $uangDiterima = isset($request->uang_diterima) ? (float) $request->uang_diterima : null;
        $kembalian = isset($request->kembalian) ? (float) $request->kembalian : 0;

        /*
         * Backdate / pembayaran diterima sebelumnya (2026-09-05).
         * Sama seperti Api::tambahPembayaran() — hanya admin, dan
         * divalidasi ulang secara otoritatif di
         * TransaksiModel::tambahPembayaran().
         */
        $tanggalPembayaran = date('Y-m-d H:i:s');
        $kasirId = $kasirIdSesi;

        if ($isAdmin && !empty($request->tanggal)) {
            $tanggalPembayaran = (string) $request->tanggal;
        }

        if ($isAdmin && !empty($request->kasir_id)) {
            $kasirId = (int) $request->kasir_id;
        }

        $dataPembayaran = [
            'transaksi_id' => $id,
            'tanggal' => $tanggalPembayaran,
            'jumlah' => $sisa,
            'metode' => $metode,
            'uang_diterima' => $metode === 'tunai' ? $uangDiterima : null,
            'kembalian' => $metode === 'tunai' ? $kembalian : 0,
            'keterangan' => 'Pelunasan',
            'kasir_id' => $kasirId
        ];

        try {
            $transaksiModel->tambahPembayaran($id, $dataPembayaran, $isAdmin);
        } catch (\Throwable $e) {
            log_message('error', 'Tagihan::lunasi: ' . $e->getMessage());

            return $this->response->setJSON([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ])->setStatusCode(422);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'message' => 'Pelunasan berhasil!',
            'sisa' => 0,
            'status_pembayaran' => 'lunas'
        ]);
    }
}
