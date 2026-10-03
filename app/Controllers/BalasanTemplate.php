<?php

namespace App\Controllers;

use App\Libraries\BalasanTemplateImageService;
use App\Models\BalasanTemplateModel;

class BalasanTemplate extends BaseController
{
    public function index()
    {
        $model = new BalasanTemplateModel();

        $data = [
            'title'    => 'Template Balasan | AULIA',
            'content'  => 'balasan_template/index',
            'template' => $model->orderBy('nama', 'ASC')->findAll(),
        ];

        return view('layout/main', $data);
    }

    public function tambah()
    {
        $data = [
            'title'   => 'Tambah Template Balasan | AULIA',
            'content' => 'balasan_template/tambah',
        ];

        return view('layout/main', $data);
    }

    public function simpan()
    {
        $model = new BalasanTemplateModel();

        $rules = [
            'nama' => 'required|min_length[2]|is_unique[balasan_template.nama]',
            'teks' => 'permit_empty|required_without[gambar]',
            'gambar' => 'permit_empty|required_without[teks]|max_size[gambar,' . $this->batasUploadKb() . ']|is_image[gambar]|mime_in[gambar,image/jpeg,image/png,image/webp]',
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $teks = trim((string) $this->request->getPost('teks'));
        $gambarFilename = null;

        $file = $this->request->getFile('gambar');
        if ($file && $file->isValid() && !$file->hasMoved()) {
            $service = new BalasanTemplateImageService();
            $hasil = $service->simpan($file);

            if (!$hasil['success']) {
                return redirect()->back()->withInput()->with('error', $hasil['error']);
            }

            $gambarFilename = $hasil['filename'];
        }

        if ($teks === '' && $gambarFilename === null) {
            return redirect()->back()->withInput()->with('error', 'Isi teks atau gambar, minimal salah satu.');
        }

        $data = [
            'nama'            => $this->request->getPost('nama'),
            'teks'            => $teks !== '' ? $teks : null,
            'gambar_filename' => $gambarFilename,
        ];

        if ($model->save($data)) {
            return redirect()->to('/balasan-template')->with('success', 'Template balasan berhasil ditambahkan!');
        }

        if ($gambarFilename !== null) {
            (new BalasanTemplateImageService())->hapus($gambarFilename);
        }

        return redirect()->back()->withInput()->with('error', 'Gagal menambahkan template balasan.');
    }

    public function edit($id)
    {
        $model = new BalasanTemplateModel();

        $data = [
            'title'    => 'Edit Template Balasan | AULIA',
            'content'  => 'balasan_template/edit',
            'template' => $model->find($id),
        ];

        if (empty($data['template'])) {
            return redirect()->to('/balasan-template')->with('error', 'Template balasan tidak ditemukan.');
        }

        return view('layout/main', $data);
    }

    public function update($id)
    {
        $model = new BalasanTemplateModel();

        $template = $model->find($id);
        if (empty($template)) {
            return redirect()->to('/balasan-template')->with('error', 'Template balasan tidak ditemukan.');
        }

        $rules = [
            'nama' => 'required|min_length[2]|is_unique[balasan_template.nama,id,{id}]',
            'gambar' => 'permit_empty|max_size[gambar,' . $this->batasUploadKb() . ']|is_image[gambar]|mime_in[gambar,image/jpeg,image/png,image/webp]',
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $teks = trim((string) $this->request->getPost('teks'));
        $hapusGambar = (bool) $this->request->getPost('hapus_gambar');
        $gambarFilename = $hapusGambar ? null : $template['gambar_filename'];
        $gambarLama = $template['gambar_filename'];

        $file = $this->request->getFile('gambar');
        if ($file && $file->isValid() && !$file->hasMoved()) {
            $service = new BalasanTemplateImageService();
            $hasil = $service->simpan($file);

            if (!$hasil['success']) {
                return redirect()->back()->withInput()->with('error', $hasil['error']);
            }

            $gambarFilename = $hasil['filename'];
        }

        if ($teks === '' && $gambarFilename === null) {
            return redirect()->back()->withInput()->with('error', 'Isi teks atau gambar, minimal salah satu.');
        }

        $data = [
            'nama'            => $this->request->getPost('nama'),
            'teks'            => $teks !== '' ? $teks : null,
            'gambar_filename' => $gambarFilename,
        ];

        if ($model->update($id, $data)) {
            // Simpan dulu, baru hapus gambar lama (jika diganti/dihapus) --
            // supaya kalau update DB gagal, gambar lama masih ada.
            if ($gambarLama !== null && $gambarLama !== $gambarFilename) {
                (new BalasanTemplateImageService())->hapus($gambarLama);
            }

            return redirect()->to('/balasan-template')->with('success', 'Template balasan berhasil diperbarui!');
        }

        if ($gambarFilename !== null && $gambarFilename !== $gambarLama) {
            (new BalasanTemplateImageService())->hapus($gambarFilename);
        }

        return redirect()->back()->withInput()->with('error', 'Gagal memperbarui template balasan.');
    }

    public function hapus($id)
    {
        $model = new BalasanTemplateModel();

        $template = $model->find($id);
        if (empty($template)) {
            return redirect()->to('/balasan-template')->with('error', 'Template balasan tidak ditemukan.');
        }

        if ($model->delete($id)) {
            (new BalasanTemplateImageService())->hapus($template['gambar_filename']);

            return redirect()->to('/balasan-template')->with('success', 'Template balasan berhasil dihapus!');
        }

        return redirect()->to('/balasan-template')->with('error', 'Gagal menghapus template balasan.');
    }

    /**
     * Streaming gambar template (dipakai untuk <img src>), clone
     * Profil::foto() -- lihat desain §8 poin 1: duplikasi kecil ini
     * sengaja dibiarkan (YAGNI, tidak ada base method bersama untuk ini
     * di codebase saat ini).
     */
    public function foto(string $filename)
    {
        $service = new BalasanTemplateImageService();
        $path    = $service->resolvePathUntukDitampilkan($filename);

        if ($path === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        return $this->response
            ->setHeader('Cache-Control', 'private, max-age=86400')
            ->setContentType(mime_content_type($path) ?: 'application/octet-stream')
            ->setBody(file_get_contents($path));
    }

    private function batasUploadKb(): int
    {
        return (new \Config\Inbox())->maxMediaUploadMb * 1024;
    }
}
