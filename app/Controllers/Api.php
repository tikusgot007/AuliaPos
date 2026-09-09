<?php

namespace App\Controllers;

use App\Models\ProdukModel;

class Api extends BaseController
{
    /**
     * Mengembalikan markup komponen pembayaran tanpa JavaScript.
     */
    public function modalPembayaran()
    {
        return view('components/payment/modal');
    }

    // Pelanggan
    public function searchPelanggan()
    {
        $keyword = $this->request->getGet('keyword') ?? '';

        if (strlen($keyword) < 2) {
            return $this->response->setJSON([
                'status' => 'success',
                'data' => []
            ]);
        }

        $pelangganModel = new \App\Models\PelangganModel();
        $data = $pelangganModel->like('nama', $keyword)
            ->orLike('no_hp', $keyword)
            ->limit(10)
            ->findAll();

        return $this->response->setJSON([
            'status' => 'success',
            'data' => $data
        ]);
    }

    public function tambahPelanggan()
    {
        $request = $this->request->getJSON(true) ?? [];
        $nama = trim((string) ($request['nama'] ?? ''));
        $noHp = trim((string) ($request['no_hp'] ?? ''));

        if (mb_strlen($nama) < 2) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Nama pelanggan minimal 2 karakter.'
            ]);
        }

        $pelangganModel = new \App\Models\PelangganModel();
        $existing = $pelangganModel->findByName($nama);
        if ($existing) {
            return $this->response->setJSON([
                'status' => 'success',
                'id' => $existing['id'],
                'message' => 'Pelanggan yang sama sudah tersedia.'
            ]);
        }

        $id = $pelangganModel->insert([
            'nama' => $nama,
            'no_hp' => $noHp
        ]);

        return $this->response->setJSON([
            'status' => 'success',
            'id' => $id,
            'message' => 'Pelanggan berhasil ditambahkan.'
        ]);
    }


    // Transaksi
    public function simpanTransaksi()
    {
        $session = session();
        $kasirId = $session->get('id_user') ?? 1;

        // Cart berasal dari browser/tab, bukan dari PHP session.
        // Decode JSON sebagai associative array agar item dapat diakses dengan $item['field'].
        $request = $this->request->getJSON(true) ?? [];
        $keranjang = $request['keranjang'] ?? [];

        if (!is_array($keranjang) || empty($keranjang)) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Keranjang kosong. Tidak ada yang bisa disimpan.'
            ]);
        }

        $metode = $request['metode'] ?? 'tunai';
        $pelangganId = $request['pelanggan'] ?? null;
        $pelangganNama = trim((string) ($request['pelanggan_nama'] ?? ''));
        $pelangganTelp = trim((string) ($request['pelanggan_telp'] ?? ''));
        $diskon = (float)($request['diskon'] ?? 0);
        $jumlahDp = (float)($request['jumlah_dp'] ?? 0);
        $noOrder = $request['no_order'] ?? null;
        $uangDiterima = (float)($request['uang_diterima'] ?? 0);
        $kembalian = (float)($request['kembalian'] ?? 0);
        $noOrderInput = trim((string) ($request['no_order'] ?? ''));



        if ($noOrderInput !== '') {
            $noOrder = parse_no_order($noOrderInput);

            if ($noOrder === null) {
                return $this->response->setJSON([
                    'status' => 'error',
                    'message' => 'Format No Order tidak valid.'
                ]);
            }
        }
        //log_message('info', 'Simpan Transaksi: Metode=' . $metode . ', Pelanggan=' . $pelangganId . ', Diskon=' . $diskon . ', DP=' . $jumlahDp . ', No Order=' . $noOrder . ', Uang Diterima=' . $uangDiterima . ', Kembalian=' . $kembalian);
        // ==========================================
        // ==========================================

        $hasKategori16 = false;
        foreach ($keranjang as $item) {
            $kategoriId = isset($item['kategori_id']) ? (int)$item['kategori_id'] : 0;

            if ($kategoriId == 16) {
                $hasKategori16 = true;
                break;
            }

            if (isset($item['is_cetak']) && $item['is_cetak'] === true) {
                $hasKategori16 = true;
                break;
            }

            if (isset($item['is_custom']) && $item['is_custom'] === true && isset($item['is_cetak']) && $item['is_cetak'] === true) {
                $hasKategori16 = true;
                break;
            }
        }



        if ($hasKategori16 && empty($noOrder)) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => '❌ Transaksi mengandung produk Studio/Foto. No Order WAJIB diisi!'
            ]);
        }

        if (!$hasKategori16 && !empty($noOrder)) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => '❌ Transaksi TIDAK mengandung produk Studio/Foto. No Order harus dikosongkan!'
            ]);
        }

        // ==========================================
        // ==========================================

        $subtotal = 0;
        foreach ($keranjang as $item) {
            $subtotal += $item['subtotal'];
        }

        if ($diskon < 0) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Diskon tidak boleh negatif.'
            ]);
        }
        if ($diskon > $subtotal) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Diskon tidak boleh melebihi total belanja.'
            ]);
        }

        // ==========================================
        // ==========================================

        $finalPelangganId = null;

        if ($pelangganId === 'new') {
            if (mb_strlen($pelangganNama) < 2) {
                return $this->response->setJSON([
                    'status' => 'error',
                    'message' => 'Nama pelanggan minimal 2 karakter.'
                ]);
            }

            $pelangganModel = new \App\Models\PelangganModel();
            $existing = $pelangganModel->findByName($pelangganNama);
            $finalPelangganId = $existing
                ? (int) $existing['id']
                : $pelangganModel->insert([
                    'nama' => $pelangganNama,
                    'no_hp' => $pelangganTelp
                ]);
        } elseif (!empty($pelangganId) && is_numeric($pelangganId)) {
            $pelangganModel = new \App\Models\PelangganModel();
            $pelanggan = $pelangganModel->find((int) $pelangganId);
            if (!$pelanggan) {
                return $this->response->setJSON([
                    'status' => 'error',
                    'message' => 'Pelanggan yang dipilih tidak ditemukan.'
                ]);
            }
            $finalPelangganId = (int) $pelanggan['id'];
        }

        if ($metode === 'piutang' && $finalPelangganId === null) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Pelanggan wajib dipilih atau diisi untuk transaksi piutang.'
            ]);
        }

        // ==========================================
        // ==========================================

        $grandTotalSebelumPembulatan = $subtotal - $diskon;
        $grandTotal = floor($grandTotalSebelumPembulatan / 100) * 100;
        $selisihPembulatan = $grandTotalSebelumPembulatan - $grandTotal;

        // ==========================================
        // ==========================================

        $jumlahBayar = 0;
        $statusPembayaran = 'belum_bayar';

        if ($metode === 'dp') {
            $jumlahBayar = $jumlahDp;
            if ($jumlahBayar <= 0) {
                return $this->response->setJSON([
                    'status' => 'error',
                    'message' => 'Jumlah DP harus lebih dari 0.'
                ]);
            }
            if ($jumlahBayar > $grandTotal) {
                return $this->response->setJSON([
                    'status' => 'error',
                    'message' => 'DP tidak boleh melebihi total belanja.'
                ]);
            }
            $statusPembayaran = 'dp';
        } elseif ($metode === 'piutang') {
            $jumlahBayar = 0;
            $statusPembayaran = 'belum_bayar';
        } elseif ($metode === 'draft') {
            $jumlahBayar = 0;
            $statusPembayaran = 'belum_bayar';
        } elseif ($metode !== 'belum_bayar') {
            $jumlahBayar = $grandTotal;
            $statusPembayaran = 'lunas';
        }

        // ==========================================
        // ==========================================

        // Semua transaksi baru selalu masuk ke status pekerjaan PROSES.
        // Status pembayaran (lunas/dp/belum_bayar) berdiri sendiri.
        $statusTransaksi = 'proses';

        // ==========================================
        // ==========================================

        $dataTransaksi = [
            'kode_invoice' => $this->generateKodeInvoice(),
            'no_order' => $noOrder,
            'tanggal' => date('Y-m-d H:i:s'),
            'pelanggan_id' => $finalPelangganId,
            'kasir_id' => $kasirId,
            'subtotal' => $subtotal,
            'diskon' => $diskon,
            'pajak' => 0,
            'grand_total' => $grandTotal,
            'selisih_pembulatan' => $selisihPembulatan,
            'total_dibayar' => 0,
            'status_pembayaran' => $statusPembayaran,
            'status' => $statusTransaksi,
            'sumber' => 'kasir_pos'
        ];

        // ==========================================
        // ==========================================

        $detailItems = [];

        foreach ($keranjang as $item) {
            $detailItems[] = [
                'produk_id' => $item['produk_id'] ?? 1,
                'nama_produk' => $item['nama'],
                'kategori_id' => $item['kategori_id'] ?? 1,
                'jumlah' => $item['jumlah'],
                'harga_satuan' => $item['harga'] ?? 0,
                'subtotal' => $item['subtotal'],
                'catatan' => '',
            ];
        }

        $transaksiModel = new \App\Models\TransaksiModel();

        try {
            $transaksiId = $transaksiModel->simpanTransaksi($dataTransaksi, $detailItems);

            if ($jumlahBayar > 0) {
                $metodeBayar = $metode;
                if ($metode === 'dp') {
                    $metodeBayar = $request['metode_dp'] ?? 'tunai';
                }

                $keterangan = 'Lunas';
                if ($metode === 'dp') {
                    $keterangan = 'DP (Rp ' . number_format($jumlahBayar, 0, ',', '.') . ')';
                } elseif ($metode === 'tunai' && $kembalian > 0) {
                    $keterangan = 'Lunas (Kembali: Rp ' . number_format($kembalian, 0, ',', '.') . ')';
                }

                // Untuk DP tunai, modal DP tidak meminta nominal uang diterima.
                // Anggap pembayaran tunai diterima pas sebesar nominal DP.
                if ($metode === 'dp' && $metodeBayar === 'tunai') {
                    $uangDiterima = $jumlahBayar;
                    $kembalian = 0;
                }

                $dataPembayaran = [
                    'tanggal' => date('Y-m-d H:i:s'),
                    'jumlah' => $jumlahBayar,
                    'metode' => $metodeBayar,
                    'uang_diterima' => $metodeBayar === 'tunai' ? $uangDiterima : null,
                    'kembalian' => $metodeBayar === 'tunai' ? max(0, $kembalian) : 0,
                    'keterangan' => $keterangan,
                    'kasir_id' => $kasirId
                ];

                $ledgerJenis = ($metodeBayar === 'tunai') ? 'penjualan' : null;
                // 🔥 UBAH dari ini:
                // $transaksiModel->tambahPembayaran($transaksiId, $dataPembayaran, $ledgerJenis);

                // 🔥 MENJADI ini:
                $transaksiModel->tambahPembayaran($transaksiId, $dataPembayaran);
            }

            return $this->response->setJSON([
                'status' => 'success',
                'message' => $metode === 'dp' ? 'DP berhasil dibayar!' : 'Transaksi berhasil disimpan!',
                'invoice' => $dataTransaksi['kode_invoice'],
                'transaksi_id' => $transaksiId,
                'grand_total' => $grandTotal,
                'grand_total_sebelum_pembulatan' => $grandTotalSebelumPembulatan,
                'selisih_pembulatan' => $selisihPembulatan,
                'total_dibayar' => $jumlahBayar,
                'sisa_tagihan' => $grandTotal - $jumlahBayar,
                'status_pembayaran' => $statusPembayaran
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Error simpanTransaksi: ' . $e->getMessage());
            log_message('error', 'Trace: ' . $e->getTraceAsString());

            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Gagal menyimpan transaksi: ' . $e->getMessage()
            ]);
        }
    }

    private function generateKodeInvoice()
    {
        $transaksiModel = new \App\Models\TransaksiModel();

        do {
            $kodeInvoice =
                'INV-'
                . date('Ymd')
                . '-'
                . str_pad(
                    random_int(1, 999),
                    3,
                    '0',
                    STR_PAD_LEFT
                );

            $exists = $transaksiModel
                ->where('kode_invoice', $kodeInvoice)
                ->first();
        } while ($exists);

        return $kodeInvoice;
    }
    // Pembayaran
    public function tambahPembayaran()
    {
        $request = $this->request->getJSON();
        $transaksiId = $request->transaksi_id ?? 0;
        $jumlah = (float) ($request->jumlah ?? 0);
        $metode = strtolower(trim((string) ($request->metode ?? 'tunai')));
        $uangDiterima = isset($request->uang_diterima) ? (float) $request->uang_diterima : null;
        $kembalian = isset($request->kembalian) ? (float) $request->kembalian : 0;

        if ($transaksiId <= 0 || $jumlah <= 0) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Data pembayaran tidak valid.'
            ]);
        }

        $transaksiModel = new \App\Models\TransaksiModel();
        $transaksi = $transaksiModel->find($transaksiId);

        if (!$transaksi) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Transaksi tidak ditemukan.'
            ]);
        }

        $pembayaranModel = new \App\Models\PembayaranModel();
        $totalDibayar = $pembayaranModel->getTotalDibayar($transaksiId);
        $sisa = max(0, (float) $transaksi['grand_total'] - $totalDibayar);

        if ($jumlah > $sisa) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Jumlah pembayaran melebihi sisa tagihan.'
            ]);
        }

        $isAdmin = session()->get('role') === 'admin';
        $kasirIdSesi = session()->get('id_user') ?? 1;

        /*
         * Backdate / pembayaran diterima sebelumnya (2026-09-05).
         * Field 'tanggal' dan 'kasir_id' opsional dari client, HANYA
         * dipakai jika admin. Untuk request normal (tidak backdate,
         * atau dikirim non-admin), behavior lama tetap: tanggal =
         * sekarang, kasir_id = kasir yang sedang login.
         *
         * Validasi rentang tanggal & role dilakukan ulang secara
         * otoritatif di TransaksiModel::tambahPembayaran() — nilai
         * dari client di sini tidak pernah dipercaya begitu saja.
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
            'transaksi_id' => $transaksiId,
            'tanggal' => $tanggalPembayaran,
            'jumlah' => $jumlah,
            'metode' => $metode,
            'uang_diterima' => $metode === 'tunai' ? $uangDiterima : null,
            'kembalian' => $metode === 'tunai' ? $kembalian : 0,
            'keterangan' => $jumlah >= $sisa ? 'Lunas' : 'Pembayaran',
            'kasir_id' => $kasirId
        ];

        try {
            $transaksiModel->tambahPembayaran($transaksiId, $dataPembayaran, $isAdmin);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'message' => 'Pembayaran berhasil ditambahkan!'
        ]);
    }

    /**
     * Koreksi metode pembayaran tanpa mengubah nilai pembayaran efektif.
     *
     * Pembayaran lama tetap disimpan sebagai histori dengan status "reversed".
     * Pembayaran pengganti dibuat dengan jumlah yang sama dan status "aktif".
     */
    public function koreksiPembayaran()
    {
        $request = $this->request->getJSON(true) ?? [];

        $transaksiId = (int) ($request['transaksi_id'] ?? 0);
        $pembayaranId = (int) ($request['pembayaran_id'] ?? 0);
        $metodeBaru = strtolower(trim((string) ($request['metode_baru'] ?? '')));
        $keterangan = trim((string) ($request['keterangan'] ?? ''));

        $metodeValid = ['tunai', 'qris', 'transfer'];

        if ($transaksiId <= 0 || $pembayaranId <= 0 || !in_array($metodeBaru, $metodeValid, true)) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Data koreksi pembayaran tidak valid.'
            ]);
        }

        $db = \Config\Database::connect();
        $transaksiModel = new \App\Models\TransaksiModel();
        $pembayaranModel = new \App\Models\PembayaranModel();

        $transaksi = $transaksiModel->find($transaksiId);

        if (!$transaksi) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Transaksi tidak ditemukan.'
            ]);
        }

        $pembayaran = $pembayaranModel
            ->where('id', $pembayaranId)
            ->where('transaksi_id', $transaksiId)
            ->where('status', 'aktif')
            ->first();

        if (!$pembayaran) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Pembayaran aktif tidak ditemukan.'
            ]);
        }

        if ($metodeBaru === $pembayaran['metode']) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Metode pembayaran baru sama dengan metode sebelumnya.'
            ]);
        }

        $kasirId = session()->get('id_user') ?? 1;
        $jumlah = (float) $pembayaran['jumlah'];

        $uangDiterima = null;
        $kembalian = 0;

        if ($metodeBaru === 'tunai') {
            $uangDiterima = $request['uang_diterima'] ?? $jumlah;
            $uangDiterima = (float) $uangDiterima;
            $kembalian = max(0, $uangDiterima - $jumlah);

            if ($uangDiterima < $jumlah) {
                return $this->response->setJSON([
                    'status' => 'error',
                    'message' => 'Uang diterima tidak boleh kurang dari jumlah pembayaran.'
                ]);
            }
        }

        $keteranganLama = trim((string) ($pembayaran['keterangan'] ?? ''));
        $keteranganBaru = $keterangan !== ''
            ? $keterangan
            : 'Koreksi metode ' . strtoupper($pembayaran['metode']) . ' ke ' . strtoupper($metodeBaru);

        $db->transBegin();

        try {
            $updated = $pembayaranModel
                ->where('id', $pembayaranId)
                ->where('status', 'aktif')
                ->set(['status' => 'reversed'])
                ->update();

            if (!$updated) {
                throw new \Exception('Pembayaran lama gagal ditandai sebagai reversed.');
            }

            $dataPembayaranBaru = [
                'tanggal' => date('Y-m-d H:i:s'),
                'jumlah' => $jumlah,
                'metode' => $metodeBaru,
                'uang_diterima' => $uangDiterima,
                'kembalian' => $kembalian,
                'keterangan' => $keteranganBaru
                    . ($keteranganLama !== '' ? ' | Sebelumnya: ' . $keteranganLama : ''),
                'kasir_id' => $kasirId,
            ];

            $transaksiModel->tambahPembayaran($transaksiId, $dataPembayaranBaru);

            if ($db->transStatus() === false) {
                throw new \Exception('Transaksi database gagal.');
            }

            $db->transCommit();

            return $this->response->setJSON([
                'status' => 'success',
                'message' => 'Metode pembayaran berhasil dikoreksi.',
                'transaksi_id' => $transaksiId,
                'pembayaran_lama_id' => $pembayaranId,
                'pembayaran_baru_id' => $pembayaranModel->getInsertID(),
                'metode_lama' => $pembayaran['metode'],
                'metode_baru' => $metodeBaru,
                'jumlah' => $jumlah
            ]);
        } catch (\Throwable $e) {
            $db->transRollback();

            log_message('error', 'Error koreksiPembayaran: ' . $e->getMessage());
            log_message('error', 'Trace: ' . $e->getTraceAsString());

            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Gagal mengoreksi pembayaran: ' . $e->getMessage()
            ]);
        }
    }

    // Status transaksi
    public function ubahStatus()
    {
        $request = $this->request->getJSON();

        $id = $request->id ?? 0;
        $status = $request->status ?? '';

        // Lifecycle transaksi: proses -> selesai atau batal.
        // 'selesai' adalah status final pekerjaan, sedangkan 'lunas'
        // hanya merupakan status pembayaran.
        $validStatus = ['proses', 'selesai', 'batal'];

        if (!in_array($status, $validStatus, true)) {
            return $this->response->setJSON([
                'status'  => 'error',
                'message' => 'Status tidak valid.'
            ]);
        }

        $transaksiModel = new \App\Models\TransaksiModel();
        $transaksi = $transaksiModel->find($id);

        if (!$transaksi) {
            return $this->response->setJSON([
                'status'  => 'error',
                'message' => 'Transaksi tidak ditemukan.'
            ]);
        }

        try {
            $isAdmin = session()->get('role') === 'admin';

            $transaksiModel->ubahStatus($id, $status, $isAdmin);

            return $this->response->setJSON([
                'status'  => 'success',
                'message' => 'Status berhasil diubah menjadi ' . strtoupper($status)
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status'  => 'error',
                'message' => $e->getMessage()
            ]);
        }
    }
    // No Order
    public function searchOrder()
    {
        $noOrder = $this->request->getGet('no_order');
        $keyword = $this->request->getGet('keyword');

        $transaksiModel = new \App\Models\TransaksiModel();

        if ($noOrder) {
            $data = $transaksiModel->where('no_order', $noOrder)->first();
            if ($data) {
                return $this->response->setJSON([
                    'status' => 'success',
                    'data' => [
                        'no_order' => $data['no_order'],
                        'nama_pelanggan' => $data['nama_pelanggan'] ?? '',
                    ]
                ]);
            }
        }

        if ($keyword) {
            $data = $transaksiModel->like('nama_pelanggan', $keyword)
                ->orderBy('tanggal', 'DESC')
                ->limit(10)
                ->findAll();
            return $this->response->setJSON([
                'status' => 'success',
                'data' => $data
            ]);
        }

        return $this->response->setJSON([
            'status' => 'error',
            'message' => 'Parameter tidak valid.'
        ]);
    }

    public function generateOrder()
    {
        $transaksiModel = new \App\Models\TransaksiModel();
        $today = date('Y-m-d');

        $highestToday = $transaksiModel->where('DATE(tanggal)', $today)
            ->where('no_order IS NOT NULL')
            ->where('no_order >', 0)
            ->orderBy('no_order', 'DESC')
            ->first();

        if ($highestToday && !empty($highestToday['no_order'])) {
            $newNoOrder = (int)$highestToday['no_order'] + 1;

            return $this->response->setJSON([
                'status' => 'success',
                'no_order' => $newNoOrder
            ]);
        }

        $highestOverall = $transaksiModel->where('no_order IS NOT NULL')
            ->where('no_order >', 0)
            ->orderBy('no_order', 'DESC')
            ->first();

        if ($highestOverall && !empty($highestOverall['no_order'])) {
            $newNoOrder = (int)$highestOverall['no_order'] + 1;

            return $this->response->setJSON([
                'status' => 'success',
                'no_order' => $newNoOrder
            ]);
        }


        return $this->response->setJSON([
            'status' => 'success',
            'no_order' => 1
        ]);
    }

    public function getAvailableNoOrders()
    {
        $transaksiModel = new \App\Models\TransaksiModel();
        $today = date('Y-m-d');

        $highestToday = $transaksiModel->where('DATE(tanggal)', $today)
            ->orderBy('no_order', 'DESC')
            ->first();

        if ($highestToday) {
            $recommended = (int)$highestToday['no_order'] + 1;
        } else {
            $highestOverall = $transaksiModel->orderBy('no_order', 'DESC')->first();
            $recommended = $highestOverall ? (int)$highestOverall['no_order'] + 1 : 1;
        }

        $range = 50;
        $min = max(1, $recommended - $range);
        $max = $recommended + 10;

        $usedOrders = $transaksiModel->select('no_order')
            ->where('no_order >=', $min)
            ->where('no_order <=', $max)
            ->findAll();

        $usedArray = array_column($usedOrders, 'no_order');

        $available = [];
        for ($i = $min; $i <= $max; $i++) {
            if (!in_array($i, $usedArray)) {
                $available[] = $i;
            }
        }

        return $this->response->setJSON([
            'status' => 'success',
            'recommended' => $recommended,
            'available' => $available
        ]);
    }

    // Produk cetak foto
    public function getProdukCetak()
    {
        $produkModel = new \App\Models\ProdukModel();

        $data = $produkModel->where('kategori_id', 16)
            ->where('panjang IS NOT NULL')
            ->where('lebar IS NOT NULL')
            ->where('is_active', 1)
            ->findAll();

        return $this->response->setJSON([
            'status' => 'success',
            'data' => $data
        ]);
    }

    // Tagihan
    public function getSisaTagihan()
    {
        $request = $this->request->getJSON();
        $id = $request->id ?? 0;

        $transaksiModel = new \App\Models\TransaksiModel();
        $pembayaranModel = new \App\Models\PembayaranModel();

        $transaksi = $transaksiModel->find($id);
        if (!$transaksi) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Transaksi tidak ditemukan.'
            ]);
        }

        $totalDibayar = $pembayaranModel->getTotalDibayar($id);
        $sisa = max(0, (float) $transaksi['grand_total'] - $totalDibayar);

        return $this->response->setJSON([
            'status' => 'success',
            'sisa' => $sisa,
            'grand_total' => $transaksi['grand_total'],
            'total_dibayar' => $totalDibayar
        ]);
    }

    public function getJumlahTagihan()
    {
        $transaksiModel = new \App\Models\TransaksiModel();

        $jumlah = $transaksiModel->where('status_pembayaran !=', 'lunas')
            ->where('status !=', 'batal')
            ->countAllResults();

        return $this->response->setJSON([
            'status' => 'success',
            'jumlah' => $jumlah
        ]);
    }
    // Produk manual
    public function cariAtauBuatProduk()
    {
        $request = $this->request->getJSON();
        $nama = $request->nama ?? '';
        $kategoriId = $request->kategori_id ?? 1;
        $hargaJual = $request->harga_jual ?? 0;

        if (empty($nama)) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Nama produk harus diisi.'
            ]);
        }

        $produkModel = new ProdukModel();

        // 🔥 1. Cari produk berdasarkan nama (case insensitive)
        $produk = $produkModel->where('LOWER(nama)', strtolower($nama))
            ->where('is_active', 1)
            ->first();

        if ($produk) {
            return $this->response->setJSON([
                'status' => 'success',
                'found' => true,
                'produk' => [
                    'id' => $produk['id'],
                    'nama' => $produk['nama'],
                    'kategori_id' => $produk['kategori_id'],
                    'harga_jual' => $produk['harga_jual']
                ]
            ]);
        }

        $dataProduk = [
            'nama' => $nama,
            'kategori_id' => $kategoriId,
            'satuan' => 'item',
            'harga_jual' => $hargaJual,
            'harga_beli' => 0,
            'is_active' => 1
        ];

        $produkId = $produkModel->insert($dataProduk);

        if ($produkId) {
            return $this->response->setJSON([
                'status' => 'success',
                'found' => false,
                'produk' => [
                    'id' => $produkId,
                    'nama' => $nama,
                    'kategori_id' => $kategoriId,
                    'harga_jual' => $hargaJual
                ],
                'message' => 'Produk baru berhasil dibuat: ' . $nama
            ]);
        }

        return $this->response->setJSON([
            'status' => 'error',
            'message' => 'Gagal membuat produk baru.'
        ]);
    }
    // Search global
    public function searchGlobal()
    {
        $keyword = $this->request->getGet('keyword') ?? '';
        $no_order = $this->request->getGet('no_order') ?? null;

        if (strlen($keyword) < 2 && empty($no_order)) {
            return $this->response->setJSON([
                'status' => 'success',
                'data' => []
            ]);
        }

        $limit = 10;

        $transaksiModel = new \App\Models\TransaksiModel();

        $builder = $transaksiModel
            ->select('transaksi.id, transaksi.kode_invoice, transaksi.no_order, transaksi.status_pembayaran, pelanggan.nama as pelanggan_nama')
            ->join('pelanggan', 'pelanggan.id = transaksi.pelanggan_id', 'left')
            ->where('transaksi.status !=', 'batal');

        if (!empty($no_order)) {
            $builder->where('transaksi.no_order', $no_order);
        } else {
            // 🔥 Jika tidak, cari berdasarkan keyword
            $builder->groupStart()
                ->like('transaksi.kode_invoice', $keyword)
                ->orLike('transaksi.no_order', $keyword)
                ->orLike('pelanggan.nama', $keyword)
                ->groupEnd();
        }

        $data = $builder
            ->orderBy('transaksi.tanggal', 'DESC')
            ->limit($limit)
            ->findAll();

        // Tandai eksplisit sebagai data aktif, supaya bentuknya sama
        // dengan hasil dari archive (poin 9: UI bisa menampilkan
        // sumber Aktif/Archive).
        foreach ($data as &$row) {
            $row['_sumber'] = 'aktif';
        }
        unset($row);

        // Kalau hasil dari DB utama belum penuh, lengkapi sisanya dari
        // Archive -- supaya transaksi lama yang sudah di-archive tetap
        // bisa ditemukan lewat search yang sama (bukan fitur terpisah).
        // Tidak menyentuh archive kalau slot sudah penuh dari data
        // aktif (kasus paling umum), jadi tidak menambah beban query
        // untuk pencarian sehari-hari yang hasilnya sudah cukup.
        if (count($data) < $limit) {
            try {
                $archiveService = new \App\Services\TransaksiArchiveService();
                $sisaSlot = $limit - count($data);
                $dariArchive = $archiveService->cariTransaksi($keyword, $no_order, $sisaSlot);
                $data = array_merge($data, $dariArchive);
            } catch (\Throwable $e) {
                // Archive gagal diakses (mis. file belum ada / belum
                // pernah archive sama sekali) TIDAK boleh mematikan
                // search transaksi aktif -- log saja dan lanjut dengan
                // hasil dari DB utama.
                log_message('error', 'searchGlobal: gagal query archive: ' . $e->getMessage());
            }
        }

        return $this->response->setJSON([
            'status' => 'success',
            'data' => $data
        ]);
    }

    /**
     * Daftar user untuk dropdown "Kasir Penerima" pada form backdate
     * pembayaran. Admin-only — dipakai hanya oleh UI backdate yang
     * juga admin-only (lihat docs/aturan-bisnis-AULIA.md &
     * dokumentasi fitur pelunasan terlambat).
     */
    public function kasirList()
    {
        if (session()->get('role') !== 'admin') {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Hanya admin yang dapat mengakses daftar kasir.'
            ]);
        }

        $userModel = new \App\Models\UserModel();
        $data = $userModel
            ->select('id, nama, username, inisial')
            ->orderBy('nama', 'ASC')
            ->findAll();

        return $this->response->setJSON([
            'status' => 'success',
            'data' => $data
        ]);
    }
}
