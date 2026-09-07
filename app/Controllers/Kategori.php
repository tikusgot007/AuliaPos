<?php

namespace App\Controllers;

use App\Models\KategoriModel;

class Kategori extends BaseController
{
    public function index()
    {
        $model = new KategoriModel();

        // Ambil semua kategori dengan parent (induk) dan anak
        $kategori = $model->orderBy('parent_id', 'ASC')->orderBy('nama', 'ASC')->findAll();

        // Buat hierarki untuk tampilan
        $data = [
            'title'     => 'Kategori | AULIA',
            'content'   => 'kategori/index',
            'kategori'  => $kategori,
            'induk'     => $model->where('parent_id IS NULL')->findAll() // Untuk dropdown di form
        ];

        return view('layout/main', $data);
    }

    public function tambah()
    {
        $model = new KategoriModel();

        $data = [
            'title'     => 'Tambah Kategori | AULIA',
            'content'   => 'kategori/tambah',
            'induk'     => $model->where('parent_id IS NULL')->findAll()
        ];

        return view('layout/main', $data);
    }

    public function simpan()
    {
        $model = new KategoriModel();

        $rules = [
            'nama' => 'required|min_length[2]|is_unique[kategori.nama]',
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $data = [
            'nama'      => $this->request->getPost('nama'),
            'parent_id' => $this->request->getPost('parent_id') ?: null
        ];

        if ($model->save($data)) {
            return redirect()->to('/kategori')->with('success', 'Kategori berhasil ditambahkan!');
        } else {
            return redirect()->back()->with('error', 'Gagal menambahkan kategori.');
        }
    }

    public function edit($id)
    {
        $model = new KategoriModel();

        $data = [
            'title'     => 'Edit Kategori | AULIA',
            'content'   => 'kategori/edit',
            'kategori'  => $model->find($id),
            'induk'     => $model->where('parent_id IS NULL')->where('id !=', $id)->findAll()
        ];

        if (empty($data['kategori'])) {
            return redirect()->to('/kategori')->with('error', 'Kategori tidak ditemukan.');
        }

        return view('layout/main', $data);
    }

    public function update($id)
    {
        $model = new KategoriModel();

        $rules = [
            'nama' => 'required|min_length[2]|is_unique[kategori.nama,id,{id}]',
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $data = [
            'nama'      => $this->request->getPost('nama'),
            'parent_id' => $this->request->getPost('parent_id') ?: null
        ];

        if ($model->update($id, $data)) {
            return redirect()->to('/kategori')->with('success', 'Kategori berhasil diperbarui!');
        } else {
            return redirect()->back()->with('error', 'Gagal memperbarui kategori.');
        }
    }

    public function hapus($id)
    {
        $model = new KategoriModel();

        // Cek apakah kategori ini memiliki anak
        $anak = $model->where('parent_id', $id)->countAllResults();
        if ($anak > 0) {
            return redirect()->to('/kategori')->with('error', 'Kategori ini memiliki sub-kategori. Hapus sub-kategori terlebih dahulu.');
        }

        // Cek apakah kategori ini digunakan di produk
        $produkModel = new \App\Models\ProdukModel();
        $digunakan = $produkModel->where('kategori_id', $id)->countAllResults();
        if ($digunakan > 0) {
            return redirect()->to('/kategori')->with('error', 'Kategori ini masih digunakan oleh produk. Pindahkan produk terlebih dahulu.');
        }

        if ($model->delete($id)) {
            return redirect()->to('/kategori')->with('success', 'Kategori berhasil dihapus!');
        } else {
            return redirect()->to('/kategori')->with('error', 'Gagal menghapus kategori.');
        }
    }
}
