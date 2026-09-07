<?php

namespace App\Controllers;

use App\Models\ProdukModel;

class Ukuran extends BaseController
{
    public function index()
    {
        // Cek login
        if (!session()->get('isLoggedIn')) {
            return redirect()->to('/login')->with('error', 'Silakan login terlebih dahulu.');
        }

        $produkModel = new ProdukModel();

        // Ambil produk dengan kategori 16 (Foto/Studio) yang punya panjang dan lebar
        $produkList = $produkModel
            ->select('id, nama, panjang, lebar, harga_jual')
            ->where('kategori_id', 16)
            ->where('is_active', 1)
            ->where('panjang IS NOT NULL')
            ->where('lebar IS NOT NULL')
            ->where('panjang >', 0)
            ->where('lebar >', 0)
            ->orderBy('sort_order', 'ASC')
            ->findAll();

        // Format data untuk view
        $ukuran_foto = array_map(function ($p) {
            return [
                'nama' => $p['nama'],
                'lebar' => (float)$p['panjang'],
                'panjang' => (float)$p['lebar'],
                'harga' => (float)$p['harga_jual']
            ];
        }, $produkList);

        $data = [
            'title' => 'Skala Ukuran | AULIA',
            'content' => 'ukuran/index',  // 🔥 SESUAIKAN DENGAN POLA
            'ukuran_foto' => $ukuran_foto
        ];

        return view('layout/main', $data);  // 🔥 PAKAI layout/main
    }

    /**
     * API untuk mendapatkan daftar harga (untuk AJAX)
     */
    public function getList()
    {
        $produkModel = new ProdukModel();

        $data = $produkModel
            ->select('id, nama as nama_barang, lebar, panjang, harga_jual as harga_barang')
            ->where('kategori_id', 16)
            ->where('is_active', 1)
            ->where('panjang IS NOT NULL')
            ->where('lebar IS NOT NULL')
            ->where('panjang >', 0)
            ->where('lebar >', 0)
            ->orderBy('sort_order', 'ASC')
            ->findAll();

        // Format ulang data untuk JavaScript
        $result = array_map(function ($p) {
            $luas = (float)$p['lebar'] * (float)$p['panjang'];
            return [
                'id' => $p['id'],
                'nama_barang' => $p['nama_barang'],
                'lebar' => (float)$p['panjang'],
                'panjang' => (float)$p['lebar'],
                'luas' => $luas,
                'harga_barang' => (float)$p['harga_barang']
            ];
        }, $data);

        return $this->response->setJSON([
            'status' => 'success',
            'barangList' => $result
        ]);
    }
}
