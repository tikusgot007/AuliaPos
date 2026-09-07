<?php

namespace App\Controllers;

use App\Models\ProdukModel;
use App\Models\KategoriModel;

class Produk extends BaseController
{
    public function index()
    {
        $model = new ProdukModel();
        $kategoriModel = new KategoriModel();

        $data = [
            'title'    => 'Produk | AULIA',
            'content'  => 'produk/index',
            'produk'   => $model->join('kategori', 'kategori.id = produk.kategori_id')
                ->select('produk.id, produk.barcode, produk.nama, produk.kategori_id, produk.satuan, produk.harga_jual, produk.harga_beli, produk.is_active, kategori.nama as kategori_nama')
                ->where('produk.is_active', 1)
                ->findAll(),

            // Data kategori untuk dropdown inline editing
            'kategori' => $kategoriModel->getKategoriInduk()
        ];

        return view('layout/main', $data);
    }

    public function tambah()
    {
        $kategoriModel = new KategoriModel();

        $data = [
            'title'    => 'Tambah Produk | AULIA',
            'content'  => 'produk/tambah',
            'kategori' => $kategoriModel->getKategoriInduk() // HANYA 4 INDUK
        ];

        return view('layout/main', $data);
    }

    public function edit($id)
    {
        $model = new ProdukModel();
        $kategoriModel = new KategoriModel();

        $data = [
            'title'    => 'Edit Produk | AULIA',
            'content'  => 'produk/edit',
            'produk'   => $model->find($id),
            'kategori' => $kategoriModel->getKategoriInduk() // HANYA 4 INDUK
        ];

        if (empty($data['produk'])) {
            return redirect()
                ->to('/produk')
                ->with('error', 'Produk tidak ditemukan.');
        }

        return view('layout/main', $data);
    }

    public function simpan()
    {
        $model = new ProdukModel();

        $rules = [
            'nama'        => 'required|min_length[3]',
            'kategori_id' => 'required|integer',
            'harga_jual'  => 'required|numeric',
        ];

        if (!$this->validate($rules)) {
            return redirect()
                ->back()
                ->withInput()
                ->with('errors', $this->validator->getErrors());
        }

        // Siapkan data dasar
        $data = [
            'nama'        => $this->request->getPost('nama'),
            'kategori_id' => $this->request->getPost('kategori_id'),
            'satuan'      => $this->request->getPost('satuan') ?? 'pcs',
            'harga_jual'  => $this->request->getPost('harga_jual'),
            'harga_beli'  => $this->request->getPost('harga_beli') ?? 0,
            'is_active'   => 1
        ];

        // Barcode hanya disimpan jika tidak kosong
        $barcode = $this->request->getPost('barcode');

        if (!empty($barcode)) {
            $data['barcode'] = $barcode;
        }

        if ($model->save($data)) {
            return redirect()
                ->to('/produk')
                ->with('success', 'Produk berhasil ditambahkan!');
        }

        return redirect()
            ->back()
            ->with('error', 'Gagal menambahkan produk.');
    }

    public function update($id)
    {
        $model = new ProdukModel();

        $rules = [
            'nama'        => 'required|min_length[3]',
            'kategori_id' => 'required|integer',
            'harga_jual'  => 'required|numeric',
        ];

        if (!$this->validate($rules)) {
            return redirect()
                ->back()
                ->withInput()
                ->with('errors', $this->validator->getErrors());
        }

        // Siapkan data dasar
        $data = [
            'nama'        => $this->request->getPost('nama'),
            'kategori_id' => $this->request->getPost('kategori_id'),
            'satuan'      => $this->request->getPost('satuan') ?? 'pcs',
            'harga_jual'  => $this->request->getPost('harga_jual'),
            'harga_beli'  => $this->request->getPost('harga_beli') ?? 0,
        ];

        // Barcode kosong menjadi NULL
        $barcode = $this->request->getPost('barcode');

        if (!empty($barcode)) {
            $data['barcode'] = $barcode;
        } else {
            $data['barcode'] = null;
        }

        if ($model->update($id, $data)) {
            return redirect()
                ->to('/produk')
                ->with('success', 'Produk berhasil diperbarui!');
        }

        return redirect()
            ->back()
            ->with('error', 'Gagal memperbarui produk.');
    }

    public function hapus($id)
    {
        $model = new ProdukModel();

        // Soft delete: set is_active = 0
        if ($model->update($id, ['is_active' => 0])) {
            return redirect()
                ->to('/produk')
                ->with('success', 'Produk berhasil dinonaktifkan!');
        }

        return redirect()
            ->to('/produk')
            ->with('error', 'Gagal menghapus produk.');
    }

    /**
     * API: Data produk untuk DataTables (server-side)
     */
    public function getProdukData()
    {
        $request = $this->request->getGet();

        $draw    = $request['draw'] ?? 1;
        $start   = $request['start'] ?? 0;
        $length  = $request['length'] ?? 10;
        $search  = $request['search'] ?? ['value' => ''];
        $order   = $request['order'] ?? [];
        $columns = $request['columns'] ?? [];

        $model = new ProdukModel();

        $result = $model->getDataTablesProduk(
            $draw,
            $start,
            $length,
            $search,
            $order,
            $columns
        );

        return $this->response->setJSON($result);
    }

    /**
     * API: Update satu field produk dari mode spreadsheet.
     *
     * POST:
     * - id
     * - field
     * - value
     */
    public function updateInline()
    {
        $model = new ProdukModel();

        $id    = $this->request->getPost('id');
        $field = $this->request->getPost('field');
        $value = $this->request->getPost('value');

        // Pastikan ID valid
        if (!is_numeric($id) || (int) $id <= 0) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON([
                    'success' => false,
                    'message' => 'ID produk tidak valid.'
                ]);
        }

        $id = (int) $id;

        // Field yang boleh diedit dari spreadsheet
        $allowedFields = [
            'barcode',
            'nama',
            'kategori_id',
            'satuan',
            'harga_jual',
            'harga_beli'
        ];

        if (!in_array($field, $allowedFields, true)) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON([
                    'success' => false,
                    'message' => 'Field produk tidak boleh diubah.'
                ]);
        }

        // Pastikan produk ada dan masih aktif
        $produk = $model->find($id);

        if (!$produk || (int) $produk['is_active'] !== 1) {
            return $this->response
                ->setStatusCode(404)
                ->setJSON([
                    'success' => false,
                    'message' => 'Produk tidak ditemukan atau sudah tidak aktif.'
                ]);
        }

        /*
         * VALIDASI PER FIELD
         */

        // Nama
        if ($field === 'nama') {
            $value = trim((string) $value);

            if ($value === '' || mb_strlen($value) < 3) {
                return $this->response
                    ->setStatusCode(422)
                    ->setJSON([
                        'success' => false,
                        'message' => 'Nama produk minimal 3 karakter.'
                    ]);
            }
        }

        // Kategori
        if ($field === 'kategori_id') {
            if (!is_numeric($value) || (int) $value <= 0) {
                return $this->response
                    ->setStatusCode(422)
                    ->setJSON([
                        'success' => false,
                        'message' => 'Kategori tidak valid.'
                    ]);
            }

            $kategoriModel = new KategoriModel();
            $kategori = $kategoriModel->find((int) $value);

            if (!$kategori) {
                return $this->response
                    ->setStatusCode(422)
                    ->setJSON([
                        'success' => false,
                        'message' => 'Kategori tidak ditemukan.'
                    ]);
            }

            $value = (int) $value;
        }

        // Harga beli / harga jual
        if ($field === 'harga_beli' || $field === 'harga_jual') {
            if ($value === '' || !is_numeric($value)) {
                return $this->response
                    ->setStatusCode(422)
                    ->setJSON([
                        'success' => false,
                        'message' => 'Harga harus berupa angka.'
                    ]);
            }

            if ((float) $value < 0) {
                return $this->response
                    ->setStatusCode(422)
                    ->setJSON([
                        'success' => false,
                        'message' => 'Harga tidak boleh negatif.'
                    ]);
            }

            $value = (float) $value;
        }

        // Satuan
        if ($field === 'satuan') {
            $value = trim((string) $value);

            if ($value === '') {
                return $this->response
                    ->setStatusCode(422)
                    ->setJSON([
                        'success' => false,
                        'message' => 'Satuan tidak boleh kosong.'
                    ]);
            }
        }

        // Barcode kosong menjadi NULL
        if ($field === 'barcode') {
            $value = trim((string) $value);

            if ($value === '') {
                $value = null;
            }
        }

        try {
            $model->updateInline($id, $field, $value);

            return $this->response->setJSON([
                'success' => true,
                'message' => 'Produk berhasil diperbarui.',
                'data' => [
                    'id'    => $id,
                    'field' => $field,
                    'value' => $value
                ]
            ]);
        } catch (\Throwable $e) {
            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'success' => false,
                    'message' => $e->getMessage()
                ]);
        }
    }

    // ================================================================
    // MAINTENANCE MASTER BARANG
    // ================================================================
    //
    // Alur: exportAudit() -> user edit kolom Aksi di Excel/CSV ->
    // previewImport() (validasi + ringkasan, TIDAK menulis apa pun) ->
    // eksekusiImport() (backup otomatis, baru UPDATE/INSERT/DELETE).
    //
    // Kolom CSV export & import HARUS sinkron (lihat CSV_HEADER).
    private const CSV_HEADER = [
        'ID',
        'Nama',
        'Kategori_ID',
        'Kategori',
        'Satuan',
        'Harga_Jual',
        'Harga_Beli',
        'Aktif',
        'Locked',
        'Jumlah_Detail',
        'Total_Qty',
        'Tanggal_Pertama',
        'Tanggal_Terakhir',
        'Aksi'
    ];

    private const AKSI_VALID = ['', 'PERTAHANKAN', 'NONAKTIF', 'HAPUS', 'INSERT'];

    /**
     * Halaman Maintenance Master Barang (upload CSV, preview, konfirmasi).
     */
    public function maintenance()
    {
        if (session()->get('role') != 'admin') {
            return redirect()->to('/produk')->with('error', 'Akses ditolak. Hanya admin.');
        }

        return view('layout/main', [
            'title'   => 'Maintenance Master Barang | AULIA',
            'content' => 'produk/maintenance',
        ]);
    }

    /**
     * Export data audit produk (produk + statistik pemakaian) ke CSV.
     * Kolom "Aksi" sengaja dikosongkan, diisi manual oleh admin.
     */
    public function exportAudit()
    {
        if (session()->get('role') != 'admin') {
            return redirect()->to('/produk')->with('error', 'Akses ditolak. Hanya admin.');
        }

        $model = new ProdukModel();
        $rows = $model->getAuditData();

        $filename = 'Audit_Produk_' . date('Y-m-d_His') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');
        fputs($output, "\xEF\xBB\xBF");

        fputcsv($output, self::CSV_HEADER);

        foreach ($rows as $row) {
            fputcsv($output, [
                $row['id'],
                $row['nama'],
                $row['kategori_id'],
                $row['kategori_nama'] ?? '',
                $row['satuan'],
                $row['harga_jual'],
                $row['harga_beli'],
                (int) $row['is_active'],
                (int) $row['is_locked'],
                (int) $row['jumlah_detail'],
                $row['total_qty'],
                $row['tanggal_pertama'] ?? '',
                $row['tanggal_terakhir'] ?? '',
                '', // Aksi: dikosongkan untuk diisi manual
            ]);
        }

        fclose($output);
        exit();
    }

    /**
     * Terima upload CSV, validasi ULANG setiap baris ke database saat ini,
     * TIDAK menulis apa pun. Mengembalikan ringkasan + rincian per baris,
     * plus token file sementara untuk dipakai eksekusiImport().
     */
    public function previewImport()
    {
        if (session()->get('role') != 'admin') {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'Akses ditolak. Hanya admin.'
            ]);
        }

        $file = $this->request->getFile('file_csv');

        if (!$file || !$file->isValid()) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'File CSV tidak valid atau gagal diupload.'
            ]);
        }

        $dir = WRITEPATH . 'uploads/maintenance';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Gagal menyiapkan folder upload sementara.'
            ]);
        }

        $token = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.csv';
        $file->move($dir, $token);

        try {
            $hasil = $this->validasiCsv($dir . DIRECTORY_SEPARATOR . $token);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Gagal membaca CSV: ' . $e->getMessage()
            ]);
        }

        return $this->response->setJSON([
            'status'   => 'success',
            'token'    => $token,
            'ringkasan' => $hasil['ringkasan'],
            'baris'    => $hasil['baris'],
        ]);
    }

    /**
     * Validasi ULANG (DB bisa saja berubah sejak preview), backup tabel
     * produk, baru eksekusi UPDATE/INSERT/DELETE dalam satu transaksi.
     */
    public function eksekusiImport()
    {
        if (session()->get('role') != 'admin') {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'Akses ditolak. Hanya admin.'
            ]);
        }

        $token = (string) ($this->request->getPost('token') ?? '');
        $token = basename($token); // cegah path traversal

        $path = WRITEPATH . 'uploads/maintenance' . DIRECTORY_SEPARATOR . $token;

        if ($token === '' || !is_file($path)) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'File hasil preview tidak ditemukan. Silakan upload ulang.'
            ]);
        }

        try {
            $hasil = $this->validasiCsv($path);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Gagal membaca ulang CSV: ' . $e->getMessage()
            ]);
        }

        $model = new ProdukModel();
        $db = \Config\Database::connect();

        // Backup WAJIB sebelum tulis apa pun, di luar transaksi DB
        // (supaya backup tetap ada walau eksekusi di bawah gagal).
        try {
            $backupPath = $model->backupTable();
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Gagal membuat backup, eksekusi dibatalkan: ' . $e->getMessage()
            ]);
        }

        $eksekusi = ['update' => 0, 'nonaktif' => 0, 'hapus' => 0, 'insert' => 0];

        $db->transStart();

        try {
            foreach ($hasil['baris'] as $baris) {
                if ($baris['status'] !== 'ok') {
                    continue; // blocked/error dilewati, tidak dieksekusi
                }

                switch ($baris['aksi_final']) {
                    case 'hapus':
                        // Re-cek sekali lagi persis sebelum DELETE (bukan cache dari validasi di atas).
                        [$boleh, $alasan] = $model->bolehDihapus((int) $baris['id']);
                        if (!$boleh) {
                            continue 2;
                        }
                        $model->delete((int) $baris['id']);
                        $eksekusi['hapus']++;
                        break;

                    case 'insert':
                        $model->insert([
                            'nama'        => $baris['nama'],
                            'kategori_id' => $baris['kategori_id'],
                            'satuan'      => $baris['satuan'],
                            'harga_jual'  => $baris['harga_jual'],
                            'harga_beli'  => $baris['harga_beli'],
                            'is_active'   => $baris['is_active'],
                            'is_locked'   => $baris['is_locked'],
                        ]);
                        $eksekusi['insert']++;
                        break;

                    case 'nonaktif':
                    case 'update':
                        $model->update((int) $baris['id'], [
                            'nama'        => $baris['nama'],
                            'kategori_id' => $baris['kategori_id'],
                            'satuan'      => $baris['satuan'],
                            'harga_jual'  => $baris['harga_jual'],
                            'harga_beli'  => $baris['harga_beli'],
                            'is_active'   => $baris['is_active'],
                            'is_locked'   => $baris['is_locked'],
                        ]);
                        $eksekusi[$baris['aksi_final']]++;
                        break;
                }
            }

            $db->transComplete();

            if (!$db->transStatus()) {
                throw new \Exception('Transaksi database gagal diselesaikan.');
            }
        } catch (\Throwable $e) {
            $db->transRollback();

            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Import gagal, database di-rollback: ' . $e->getMessage(),
                'backup' => $backupPath,
            ]);
        }

        @unlink($path); // bersihkan file sementara

        return $this->response->setJSON([
            'status'    => 'success',
            'message'   => 'Import berhasil dijalankan.',
            'eksekusi'  => $eksekusi,
            'backup'    => $backupPath,
        ]);
    }

    /**
     * Baca file CSV di $path, validasi ULANG setiap baris terhadap kondisi
     * database SAAT INI (bukan percaya nilai dari CSV), kembalikan ringkasan
     * + rincian per baris. Dipakai bersama oleh previewImport() dan
     * eksekusiImport() supaya logic validasinya satu tempat saja.
     */
    private function validasiCsv(string $path): array
    {
        $model = new ProdukModel();

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('File CSV tidak dapat dibuka.');
        }

        // Buang BOM UTF-8 jika ada.
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $headerRaw = fgetcsv($handle);
        if ($headerRaw === false) {
            fclose($handle);
            throw new \RuntimeException('CSV kosong atau header tidak terbaca.');
        }

        // Peta nama_kolom(lowercase) -> index, supaya tidak bergantung urutan kolom persis.
        $indexKolom = [];
        foreach ($headerRaw as $i => $nama) {
            $indexKolom[strtolower(trim($nama))] = $i;
        }

        $wajib = ['id', 'nama', 'kategori_id', 'satuan', 'harga_jual', 'harga_beli', 'aktif', 'locked', 'aksi'];
        foreach ($wajib as $kolom) {
            if (!isset($indexKolom[$kolom])) {
                fclose($handle);
                throw new \RuntimeException('Kolom wajib "' . $kolom . '" tidak ditemukan di CSV.');
            }
        }

        $ambil = function (array $row, string $kolom) use ($indexKolom) {
            $i = $indexKolom[$kolom];
            return trim((string) ($row[$i] ?? ''));
        };

        $baris = [];
        $ringkasan = [
            'total_baris'   => 0,
            'akan_update'   => 0,
            'akan_nonaktif' => 0,
            'akan_hapus'    => 0,
            'akan_insert'   => 0,
            'diblokir'      => 0,
            'error'         => 0,
            'blokir_pernah_dipakai' => 0,
            'blokir_locked'         => 0,
            'blokir_id_khusus'      => 0,
            'blokir_tidak_ditemukan' => 0,
        ];

        $nomor = 1;

        while (($row = fgetcsv($handle)) !== false) {
            if (count(array_filter($row, fn($v) => trim((string) $v) !== '')) === 0) {
                continue; // lewati baris kosong
            }

            $nomor++;
            $ringkasan['total_baris']++;

            $idRaw = $ambil($row, 'id');
            $nama = $ambil($row, 'nama');
            $kategoriId = $ambil($row, 'kategori_id');
            $satuan = $ambil($row, 'satuan') ?: 'pcs';
            $hargaJual = $ambil($row, 'harga_jual');
            $hargaBeli = $ambil($row, 'harga_beli') ?: '0';
            $aktif = $ambil($row, 'aktif');
            $locked = $ambil($row, 'locked');
            $aksi = strtoupper($ambil($row, 'aksi'));

            $item = [
                'baris_ke'    => $nomor,
                'id'          => $idRaw !== '' ? (int) $idRaw : null,
                'nama'        => $nama,
                'kategori_id' => is_numeric($kategoriId) ? (int) $kategoriId : null,
                'satuan'      => $satuan,
                'harga_jual'  => is_numeric($hargaJual) ? (float) $hargaJual : null,
                'harga_beli'  => is_numeric($hargaBeli) ? (float) $hargaBeli : 0.0,
                'is_active'   => $aktif !== '' ? (int) (bool) (int) $aktif : 1,
                'is_locked'   => $locked !== '' ? (int) (bool) (int) $locked : 0,
                'aksi'        => $aksi,
                'aksi_final'  => null,
                'status'      => 'error',
                'keterangan'  => '',
            ];

            if (!in_array($aksi, self::AKSI_VALID, true)) {
                $item['keterangan'] = 'Nilai Aksi tidak dikenal: "' . $aksi . '".';
                $ringkasan['error']++;
                $baris[] = $item;
                continue;
            }

            // ---------- ID kosong: hanya boleh INSERT ----------
            if ($item['id'] === null) {
                if ($aksi !== 'INSERT') {
                    $item['keterangan'] = 'ID kosong tapi Aksi bukan INSERT.';
                    $ringkasan['error']++;
                    $baris[] = $item;
                    continue;
                }

                if ($item['nama'] === '' || $item['kategori_id'] === null || $item['harga_jual'] === null) {
                    $item['keterangan'] = 'Data INSERT tidak lengkap (nama/kategori_id/harga_jual wajib diisi & valid).';
                    $ringkasan['error']++;
                    $baris[] = $item;
                    continue;
                }

                $item['aksi_final'] = 'insert';
                $item['status'] = 'ok';
                $item['keterangan'] = 'Barang baru akan ditambahkan.';
                $ringkasan['akan_insert']++;
                $baris[] = $item;
                continue;
            }

            // ---------- ID ada: INSERT tidak valid ----------
            if ($aksi === 'INSERT') {
                $item['keterangan'] = 'ID sudah diisi (' . $item['id'] . '), tidak bisa INSERT.';
                $ringkasan['error']++;
                $baris[] = $item;
                continue;
            }

            // ---------- HAPUS: validasi ulang ke database saat ini ----------
            if ($aksi === 'HAPUS') {
                [$boleh, $alasan] = $model->bolehDihapus($item['id']);

                if (!$boleh) {
                    $item['status'] = 'blocked';
                    $item['keterangan'] = $alasan;
                    $ringkasan['diblokir']++;

                    if (str_contains($alasan, 'ID khusus')) {
                        $ringkasan['blokir_id_khusus']++;
                    } elseif (str_contains($alasan, 'terkunci')) {
                        $ringkasan['blokir_locked']++;
                    } elseif (str_contains($alasan, 'pernah dipakai')) {
                        $ringkasan['blokir_pernah_dipakai']++;
                    } else {
                        $ringkasan['blokir_tidak_ditemukan']++;
                    }

                    $baris[] = $item;
                    continue;
                }

                $item['aksi_final'] = 'hapus';
                $item['status'] = 'ok';
                $item['keterangan'] = 'Aman untuk dihapus.';
                $ringkasan['akan_hapus']++;
                $baris[] = $item;
                continue;
            }

            // ---------- UPDATE / NONAKTIF / PERTAHANKAN (kosong) ----------
            $produkAda = $model->find($item['id']);

            if (!$produkAda) {
                $item['keterangan'] = 'ID ' . $item['id'] . ' tidak ditemukan di database.';
                $ringkasan['error']++;
                $baris[] = $item;
                continue;
            }

            if ($item['nama'] === '' || $item['kategori_id'] === null || $item['harga_jual'] === null) {
                $item['keterangan'] = 'Data tidak lengkap/valid (nama/kategori_id/harga_jual).';
                $ringkasan['error']++;
                $baris[] = $item;
                continue;
            }

            if ($aksi === 'NONAKTIF') {
                $item['is_active'] = 0;
                $item['aksi_final'] = 'nonaktif';
                $item['status'] = 'ok';
                $item['keterangan'] = 'Akan dinonaktifkan (is_active = 0).';
                $ringkasan['akan_nonaktif']++;
            } else {
                // '' atau 'PERTAHANKAN'
                $item['aksi_final'] = 'update';
                $item['status'] = 'ok';
                $item['keterangan'] = 'Field akan disamakan dengan isian CSV.';
                $ringkasan['akan_update']++;
            }

            $baris[] = $item;
        }

        fclose($handle);

        return ['ringkasan' => $ringkasan, 'baris' => $baris];
    }
}
