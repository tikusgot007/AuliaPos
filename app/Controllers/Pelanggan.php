<?php

namespace App\Controllers;

use App\Models\PelangganModel;

class Pelanggan extends BaseController
{
    public function index()
    {
        $model = new PelangganModel();

        $data = [
            'title'     => 'Pelanggan | AULIA',
            'content'   => 'pelanggan/index',
            'pelanggan' => $model->orderBy('nama', 'ASC')->findAll()
        ];

        return view('layout/main', $data);
    }

    public function tambah()
    {
        $data = [
            'title'   => 'Tambah Pelanggan | AULIA',
            'content' => 'pelanggan/tambah'
        ];

        return view('layout/main', $data);
    }

    public function simpan()
    {
        $model = new PelangganModel();

        $nama = trim($this->request->getPost('nama'));

        // 🔥 Validasi manual
        if (empty($nama)) {
            return redirect()->back()->withInput()->with('error', 'Nama pelanggan harus diisi.');
        }

        if (strlen($nama) < 2) {
            return redirect()->back()->withInput()->with('error', 'Nama pelanggan minimal 2 karakter.');
        }

        $existing = $model->where('nama', $nama)->first();
        if ($existing) {
            return redirect()->back()->withInput()->with('error', 'Nama pelanggan sudah digunakan. Silakan gunakan nama lain.');
        }

        $data = [
            'nama'      => $nama,
            'no_hp'     => $this->request->getPost('no_hp'),
            'alamat'    => $this->request->getPost('alamat'),
            'diskon'    => $this->request->getPost('diskon') ?? 0
        ];

        if ($model->save($data)) {
            return redirect()->to('/pelanggan')->with('success', 'Pelanggan berhasil ditambahkan!');
        } else {
            return redirect()->back()->withInput()->with('error', 'Gagal menambahkan pelanggan.');
        }
    }

    public function edit($id)
    {
        $model = new PelangganModel();

        $data = [
            'title'     => 'Edit Pelanggan | AULIA',
            'content'   => 'pelanggan/edit',
            'pelanggan' => $model->find($id)
        ];

        if (empty($data['pelanggan'])) {
            return redirect()->to('/pelanggan')->with('error', 'Pelanggan tidak ditemukan.');
        }

        return view('layout/main', $data);
    }

    public function update($id)
    {
        $model = new PelangganModel();

        // 🔥 Cek pelanggan saat ini
        $current = $model->find($id);
        if (!$current) {
            return redirect()->to('/pelanggan')->with('error', 'Pelanggan tidak ditemukan.');
        }

        $nama = trim($this->request->getPost('nama'));

        // 🔥 Validasi manual
        if (empty($nama)) {
            return redirect()->back()->withInput()->with('error', 'Nama pelanggan harus diisi.');
        }

        if (strlen($nama) < 2) {
            return redirect()->back()->withInput()->with('error', 'Nama pelanggan minimal 2 karakter.');
        }

        // 🔥 Cek duplikat (kecuali dirinya sendiri)
        if ($nama != $current['nama']) {
            $existing = $model->where('nama', $nama)->first();
            if ($existing) {
                return redirect()->back()->withInput()->with('error', 'Nama pelanggan sudah digunakan. Silakan gunakan nama lain.');
            }
        }

        $data = [
            'nama'      => $nama,
            'no_hp'     => $this->request->getPost('no_hp'),
            'alamat'    => $this->request->getPost('alamat'),
            'diskon'    => $this->request->getPost('diskon') ?? 0
        ];

        if ($model->update($id, $data)) {

            // Cek apakah edit berasal dari Kasir
            $return = $this->request->getPost('return');

            if ($return === 'kasir') {

                return redirect()->to(
                    '/kasir?' . http_build_query([
                        'pelanggan_id'   => $id,
                        'pelanggan_nama' => $nama,
                        'pelanggan_telp' => $data['no_hp'] ?? ''
                    ])
                );
            }

            // Jika edit dari Master Pelanggan biasa
            return redirect()->to('/pelanggan')
                ->with('success', 'Pelanggan berhasil diperbarui!');
        } else {

            return redirect()->back()
                ->withInput()
                ->with('error', 'Gagal memperbarui pelanggan.');
        }
    }

    public function hapus($id)
    {
        $model = new PelangganModel();

        // Cek apakah pelanggan memiliki transaksi
        $transaksiModel = new \App\Models\TransaksiModel();
        $digunakan = $transaksiModel->where('pelanggan_id', $id)->countAllResults();

        if ($digunakan > 0) {
            return redirect()->to('/pelanggan')->with('error', 'Pelanggan masih memiliki transaksi. Tidak bisa dihapus.');
        }

        if ($model->delete($id)) {
            return redirect()->to('/pelanggan')->with('success', 'Pelanggan berhasil dihapus!');
        } else {
            return redirect()->to('/pelanggan')->with('error', 'Gagal menghapus pelanggan.');
        }
    }
}
