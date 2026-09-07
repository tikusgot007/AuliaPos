<?php

namespace App\Controllers;

use App\Models\CashOpnameModel;
use App\Models\CashExpenseModel;

class Cash extends BaseController
{
    /**
     * Dashboard Kas
     */
    public function index()
    {
        $opnameModel = new CashOpnameModel();
        $opnameTerakhir = $opnameModel->getLastOpnameToday();

        // Selalu hitung realtime untuk "Penerimaan sampai saat ini"
        $saldo = getSaldoKasHariIni();
        $saldoSistem = $saldo['saldo'];

        $kasAwal = getKasAwalHari();
        $expenseModel = new CashExpenseModel();
        $totalPengeluaran = $expenseModel->getTotalPengeluaranHariIni();

        $data = [
            'title' => 'Kas | AULIA',
            'content' => 'cash/index',
            'saldo_sistem' => $saldoSistem,
            'kas_awal' => $kasAwal,
            'opname_terakhir' => $opnameTerakhir,
            'total_pengeluaran' => $totalPengeluaran,
            'riwayat' => $opnameModel->getRiwayat(10),
        ];

        return view('layout/main', $data);
    }
    /**
     * Form Opname Kas
     */
    public function opname()
    {
        // Cek jika ada request POST
        if ($this->request->getMethod() === 'post') {
            return $this->simpanOpname();
        }

        // Hitung saldo sistem saat ini
        $saldo = getSaldoKasHariIni();
        $kasAwal = getKasAwalHari();
        $opnameModel = new CashOpnameModel();
        $opnameTerakhir = $opnameModel->getLastOpnameToday();

        $data = [
            'title' => 'Opname Kas | AULIA',
            'content' => 'cash/opname',
            'saldo_sistem' => $saldo['saldo'],
            'kas_awal' => $kasAwal,
            'pemasukan' => $saldo['pemasukan'],
            'pengeluaran' => $saldo['pengeluaran'],
            'opname_terakhir' => $opnameTerakhir,
            'is_edit' => false,
        ];

        return view('layout/main', $data);
    }


    /**
     * API: Simpan kas awal hari (via AJAX POST)
     * Hanya bisa sebelum jam 9 pagi
     */
    public function simpanKasAwal()
    {
        // Cek waktu
        $jam = date('H');
        if ($jam >= 9) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Input kas awal hanya bisa dilakukan sebelum jam 09:00 pagi.'
            ]);
        }

        $request = $this->request->getJSON(true);
        $nominal = (float) ($request['nominal'] ?? 0);
        $catatan = trim($request['catatan'] ?? '');

        if ($nominal < 0) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Nominal tidak boleh negatif.'
            ]);
        }

        $userId = session()->get('id_user') ?? 1;
        $result = catatKasAwalHari($nominal, $userId, $catatan);

        if ($result) {
            return $this->response->setJSON([
                'status' => 'success',
                'message' => 'Kas awal hari berhasil disimpan: Rp ' . number_format($nominal, 0, ',', '.'),
                'nominal' => $nominal
            ]);
        } else {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Gagal menyimpan kas awal hari.'
            ]);
        }
    }

    /**
     * Riwayat Opname
     */
    public function riwayat()
    {
        $opnameModel = new CashOpnameModel();

        $tanggalAwal = $this->request->getGet('tanggal_awal') ?? date('Y-m-01');
        $tanggalAkhir = $this->request->getGet('tanggal_akhir') ?? date('Y-m-d');

        $data = [
            'title' => 'Riwayat Opname | AULIA',
            'content' => 'cash/riwayat',
            'opname' => $opnameModel->getRiwayat(100, 0, $tanggalAwal, $tanggalAkhir),
            'tanggal_awal' => $tanggalAwal,
            'tanggal_akhir' => $tanggalAkhir,
        ];

        return view('layout/main', $data);
    }

    public function detail($id)
    {
        $opnameModel = new CashOpnameModel();
        $opname = $opnameModel->select('cash_opname.*, users.username as user_nama')
            ->join('users', 'users.id = cash_opname.user_id', 'left')
            ->find($id);

        if (!$opname) {
            return redirect()->to('/cash/riwayat')->with('error', 'Data opname tidak ditemukan.');
        }

        $data = [
            'title' => 'Detail Opname | AULIA',
            'content' => 'cash/detail',
            'opname' => $opname,
        ];

        return view('layout/main', $data);
    }

    /**
     * API: Mendapatkan saldo sistem saat ini (via AJAX)
     */
    public function getSaldoSistem()
    {
        $saldo = getSaldoKasHariIni();

        return $this->response->setJSON([
            'status' => 'success',
            'saldo_sistem' => $saldo['saldo'],
            'kas_awal' => $saldo['kas_awal'],
            'pemasukan' => $saldo['pemasukan'],
            'pengeluaran' => $saldo['pengeluaran'],
        ]);
    }
    /**
     * API: Simpan opname (via AJAX POST)
     */
    public function simpanOpname()
    {
        $request = $this->request->getJSON(true);

        $saldoFisik = (float) ($request['saldo_fisik'] ?? 0);
        $alasan = trim($request['alasan_selisih'] ?? '');
        $catatan = trim($request['catatan'] ?? '');

        if ($saldoFisik < 0) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Saldo fisik tidak boleh negatif.'
            ]);
        }

        // Hitung saldo sistem realtime
        $waktuTarget = date('Y-m-d H:i:s');
        $saldo = getSaldoKasHariIni($waktuTarget);
        $saldoSistem = $saldo['saldo'];

        // Hitung selisih
        $selisih = $saldoFisik - $saldoSistem;

        // Validasi: jika selisih != 0, alasan wajib diisi
        if (abs($selisih) > 0.01 && empty($alasan)) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Alasan selisih wajib diisi karena terjadi selisih.'
            ]);
        }

        // Tentukan status selisih
        if (abs($selisih) < 0.01) {
            $statusSelisih = 'sesuai';
        } elseif ($selisih < 0) {
            $statusSelisih = 'kurang';
        } else {
            $statusSelisih = 'lebih';
        }

        // Simpan ke database
        $opnameModel = new CashOpnameModel();
        $data = [
            'tanggal' => $waktuTarget,
            'saldo_awal_hari' => $saldo['kas_awal'],
            'pemasukan_tunai' => $saldo['pemasukan'],
            'pengeluaran_tunai' => $saldo['pengeluaran'],
            'saldo_sistem' => $saldoSistem,
            'saldo_fisik' => $saldoFisik,
            'selisih' => $selisih,
            'status_selisih' => $statusSelisih,
            'alasan_selisih' => $alasan,
            'catatan' => $catatan,
            'user_id' => session()->get('id_user') ?? 1,
        ];

        try {
            $opnameModel->insert($data);

            return $this->response->setJSON([
                'status' => 'success',
                'message' => 'Opname berhasil disimpan!',
                'saldo_sistem' => $saldoSistem,
                'saldo_fisik' => $saldoFisik,
                'selisih' => $selisih,
                'status_selisih' => $statusSelisih,
            ]);
        } catch (\Exception $e) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Gagal menyimpan opname: ' . $e->getMessage(),
            ]);
        }
    }
    // ==========================================
// KAS KELUAR (PENGELUARAN)
// ==========================================

    /**
     * Halaman daftar pengeluaran
     */
    public function pengeluaran()
    {
        $expenseModel = new CashExpenseModel();

        $tanggalAwal = $this->request->getGet('tanggal_awal') ?? date('Y-m-01');
        $tanggalAkhir = $this->request->getGet('tanggal_akhir') ?? date('Y-m-d');
        $kategori = $this->request->getGet('kategori') ?? '';

        $data = [
            'title' => 'Kas Keluar | AULIA',
            'content' => 'cash/pengeluaran',
            'pengeluaran' => $expenseModel->getPengeluaran(100, 0, $tanggalAwal, $tanggalAkhir, $kategori),
            'tanggal_awal' => $tanggalAwal,
            'tanggal_akhir' => $tanggalAkhir,
            'kategori_filter' => $kategori,
            'total_pengeluaran' => $expenseModel->getTotalPengeluaran($tanggalAwal, $tanggalAkhir),
            'kategori_list' => ['pengeluaran', 'refund_penjualan', 'penyesuaian'],
        ];

        return view('layout/main', $data);
    }

    /**
     * Form tambah pengeluaran (via AJAX)
     */
    public function tambahPengeluaran()
    {
        $request = $this->request->getJSON(true);

        $nominal = (float) ($request['nominal'] ?? 0);
        $keterangan = trim($request['keterangan'] ?? '');
        $penerima = trim($request['penerima'] ?? '');
        $tanggal = $request['tanggal'] ?? date('Y-m-d H:i:s');

        if ($nominal <= 0) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Nominal harus lebih dari 0.'
            ]);
        }

        if (empty($keterangan)) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Keterangan wajib diisi.'
            ]);
        }

        $expenseModel = new CashExpenseModel();
        $data = [
            'tanggal' => $tanggal,
            'nominal' => $nominal,
            'keterangan' => $keterangan,
            'penerima' => $penerima,
            'user_id' => session()->get('id_user') ?? 1,
        ];

        try {
            $expenseModel->simpanPengeluaran($data);

            return $this->response->setJSON([
                'status' => 'success',
                'message' => 'Pengeluaran berhasil dicatat: Rp ' . number_format($nominal, 0, ',', '.'),
            ]);
        } catch (\Exception $e) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Gagal menyimpan: ' . $e->getMessage(),
            ]);
        }
    }

    // ==========================================
    // GET: Ambil data pengeluaran berdasarkan ID
    // ==========================================
    public function getPengeluaran($id = null)
    {
        $expenseModel = new CashExpenseModel();

        if (empty($id)) {
            return $this->response->setJSON([
                'status'  => 'error',
                'message' => 'ID pengeluaran tidak valid.'
            ]);
        }

        $data = $expenseModel->find($id);

        if (!$data) {
            return $this->response->setJSON([
                'status'  => 'error',
                'message' => 'Data pengeluaran tidak ditemukan.'
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'data'   => $data
        ]);
    }


    // ==========================================
    // POST: Update data pengeluaran
    // ==========================================
    public function updatePengeluaran($id = null)
    {
        $expenseModel = new CashExpenseModel();

        if (empty($id)) {
            return $this->response->setJSON([
                'status'  => 'error',
                'message' => 'ID pengeluaran tidak valid.'
            ]);
        }

        // Pastikan data ada
        $data = $expenseModel->find($id);

        if (!$data) {
            return $this->response->setJSON([
                'status'  => 'error',
                'message' => 'Data pengeluaran tidak ditemukan.'
            ]);
        }

        // Ambil JSON dari request
        $request = $this->request->getJSON(true);

        if (!is_array($request)) {
            return $this->response->setJSON([
                'status'  => 'error',
                'message' => 'Request JSON tidak valid.'
            ]);
        }

        // Ambil data
        $nominal    = isset($request['nominal']) ? (float) $request['nominal'] : 0;
        $keterangan = trim($request['keterangan'] ?? '');
        $penerima   = trim($request['penerima'] ?? '');
        $tanggal    = $request['tanggal'] ?? date('Y-m-d H:i:s');

        // Validasi nominal
        if ($nominal <= 0) {
            return $this->response->setJSON([
                'status'  => 'error',
                'message' => 'Nominal harus lebih dari 0.'
            ]);
        }

        // Validasi keterangan
        if (empty($keterangan)) {
            return $this->response->setJSON([
                'status'  => 'error',
                'message' => 'Keterangan wajib diisi.'
            ]);
        }

        try {

            $expenseModel->updatePengeluaran($id, [
                'tanggal'    => $tanggal,
                'nominal'    => $nominal,
                'keterangan' => $keterangan,
                'penerima'   => $penerima,
            ]);

            return $this->response->setJSON([
                'status'  => 'success',
                'message' => 'Pengeluaran berhasil diperbarui.'
            ]);
        } catch (\Exception $e) {

            return $this->response->setJSON([
                'status'  => 'error',
                'message' => 'Gagal memperbarui: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Hapus pengeluaran (via AJAX)
     */
    public function hapusPengeluaran($id)
    {
        $expenseModel = new CashExpenseModel();

        try {
            $expenseModel->hapusPengeluaran($id);
            return $this->response->setJSON([
                'status' => 'success',
                'message' => 'Pengeluaran berhasil dihapus.'
            ]);
        } catch (\Exception $e) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Gagal menghapus: ' . $e->getMessage(),
            ]);
        }
    }
}
