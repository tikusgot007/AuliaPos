<?php

namespace App\Controllers;

use App\Models\TransaksiModel;
use App\Models\DetailTransaksiModel;
use App\Models\PembayaranModel;
use App\Models\PelangganModel;
use App\Models\UserModel;
use App\Services\KalkulasiJatuhTempo;
use App\Services\KalkulasiStatusPembayaran;
use Config\Tagihan as TagihanConfig;

class Tagihan extends BaseController
{
    /**
     * Ekspresi SQL untuk kolom turunan "Sisa" pada daftar tagihan.
     * Meniru tampilan di data(): sisa = max(0, grand_total - total_dibayar).
     * Dipakai untuk ORDER BY kolom 8 (tidak ada kolom `sisa` di tabel).
     * Sama dengan Transaksi::SQL_SISA.
     */
    private const SQL_SISA = 'GREATEST(transaksi.grand_total - transaksi.total_dibayar, 0)';

    public function index()
    {
        $userModel = new UserModel();
        $daftarKasir = $userModel->select('id, nama, username, inisial')->orderBy('nama', 'ASC')->findAll();

        $kasirIdValid = array_map('intval', array_column($daftarKasir, 'id'));
        $kasirIdFilter = $this->request->getGet('kasir_id');
        $kasirIdFilter = in_array((int) $kasirIdFilter, $kasirIdValid, true) ? (int) $kasirIdFilter : null;

        [$tanggalAwal, $tanggalAkhir] = $this->getRentangTanggal();

        return view('layout/main', [
            'title'           => 'Tagihan | AULIA',
            'content'         => 'tagihan/index',
            'tanggal_awal'    => $tanggalAwal ?? '',
            'tanggal_akhir'   => $tanggalAkhir ?? '',
            'hanya_terlambat' => $this->request->getGet('hanya_terlambat') == '1',
            'daftar_kasir'    => $daftarKasir,
            'kasir_id_filter' => $kasirIdFilter,
        ]);
    }

    private function tagihanFilterBag(): array
    {
        [$tanggalAwal, $tanggalAkhir] = $this->getRentangTanggal();

        $userModel = new UserModel();
        $daftarKasir = $userModel->select('id')->findAll();
        $kasirIdValid = array_map('intval', array_column($daftarKasir, 'id'));
        $kasirIdFilter = $this->request->getGet('kasir_id');
        $kasirIdFilter = in_array((int) $kasirIdFilter, $kasirIdValid, true) ? (int) $kasirIdFilter : null;

        return [
            'tanggal_awal'    => $tanggalAwal,
            'tanggal_akhir'   => $tanggalAkhir,
            'kasir_id'        => $kasirIdFilter,
            'hanya_terlambat' => $this->request->getGet('hanya_terlambat') == '1',
            'saya'            => $this->request->getGet('saya') == '1',
        ];
    }

    private function tagihanBaseBuilder($db)
    {
        return $db->table('transaksi')
            ->select('transaksi.*, pelanggan.nama as pelanggan_nama, users.username as kasir_nama, users.inisial as kasir_inisial')
            ->join('pelanggan', 'pelanggan.id = transaksi.pelanggan_id', 'left')
            ->join('users', 'users.id = transaksi.kasir_id', 'left')
            ->where('transaksi.status_pembayaran !=', 'lunas')
            ->whereNotIn('transaksi.status', ['batal', 'mangkrak']);
    }

    private function tagihanApplyFilters($builder, array $f, int $tempoHari): void
    {
        if ($f['tanggal_awal'] !== null) {
            $builder->where('transaksi.tanggal >=', $f['tanggal_awal'] . ' 00:00:00');
        }

        if ($f['tanggal_akhir'] !== null) {
            $akhirEksklusif = date('Y-m-d 00:00:00', strtotime($f['tanggal_akhir'] . ' +1 day'));
            $builder->where('transaksi.tanggal <', $akhirEksklusif);
        }

        if ($f['kasir_id'] !== null) {
            $builder->where('transaksi.kasir_id', $f['kasir_id']);
        }

        if ($f['saya']) {
            $builder->where('transaksi.kasir_id', (int) session()->get('id_user'));
        }

        if ($f['hanya_terlambat']) {
            // isOverdue = (tanggal + tempo) < hari ini  <=>  tanggal < hari ini - tempo
            $cutoff = date('Y-m-d', strtotime("today -{$tempoHari} days"));
            $builder->where('transaksi.tanggal <', $cutoff . ' 00:00:00');
        }
    }

    private function tagihanOrder(): array
    {
        $map = [
            1  => 'transaksi.kode_invoice',
            2  => 'transaksi.no_order',
            3  => 'transaksi.tanggal',
            4  => 'pelanggan.nama',
            5  => 'users.inisial',
            6  => 'transaksi.grand_total',
            7  => 'transaksi.total_dibayar',
            8  => self::SQL_SISA,
            9  => 'transaksi.status_pembayaran',
        ];

        $out = [];
        $order = $this->request->getGet('order');
        if (is_array($order)) {
            foreach ($order as $o) {
                $idx = (int) ($o['column'] ?? -1);
                if (!isset($map[$idx])) {
                    continue;
                }
                $dir = strtolower((string) ($o['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
                $out[] = [$map[$idx], $dir];
            }
        }
        if ($out === []) {
            $out = [['transaksi.tanggal', 'ASC']];
        }
        return $out;
    }

    /**
     * API: data Tagihan untuk DataTables (server-side).
     */
    public function data()
    {
        helper('order');

        $f = $this->tagihanFilterBag();
        $draw = (int) ($this->request->getGet('draw') ?? 1);
        $start = max(0, (int) ($this->request->getGet('start') ?? 0));
        $length = (int) ($this->request->getGet('length') ?? 25);
        if ($length <= 0) {
            $length = 25;
        }
        $order = $this->tagihanOrder();

        $tempoHari = (new TagihanConfig())->defaultTempoHari;
        $hariIni = date('Y-m-d');

        $db = db_connect();

        $countBuilder = $this->tagihanBaseBuilder($db);
        $this->tagihanApplyFilters($countBuilder, $f, $tempoHari);
        $total = (int) $countBuilder->countAllResults();

        $builder = $this->tagihanBaseBuilder($db);
        $this->tagihanApplyFilters($builder, $f, $tempoHari);
        foreach ($order as [$col, $dir]) {
            // $col hanya berasal dari whitelist tagihanOrder() (tidak pernah
            // input user mentah), jadi aman di-escape=false agar ekspresi
            // turunan Sisa bisa dipakai apa adanya.
            $builder->orderBy($col, $dir, false);
        }
        $rows = $builder->limit($length, $start)->get()->getResultArray();

        $data = [];
        foreach ($rows as $t) {
            $totalDibayar = (float) ($t['total_dibayar'] ?? 0);
            $grandTotal = (float) ($t['grand_total'] ?? 0);
            $sisa = max(0, $grandTotal - $totalDibayar);
            $statusPembayaran = KalkulasiStatusPembayaran::hitung($totalDibayar, $grandTotal);
            $jatuhTempo = KalkulasiJatuhTempo::hitung($t['tanggal'], $tempoHari);

            $data[] = [
                'id'                  => (int) $t['id'],
                'kode_invoice'        => $t['kode_invoice'] ?? '-',
                'no_order_display'    => !empty($t['no_order']) ? format_no_order($t['no_order']) : '-',
                'tanggal_ts'          => strtotime($t['tanggal']),
                'tanggal_display'     => tanggal_singkat($t['tanggal']),
                'is_overdue'          => KalkulasiJatuhTempo::isOverdue($t['tanggal'], $tempoHari, $hariIni),
                'jatuh_tempo_display' => tanggal_singkat($jatuhTempo),
                'pelanggan_nama'      => $t['pelanggan_nama'] ?? '-',
                'kasir'               => $t['kasir_inisial'] ?? $t['kasir_nama'] ?? '-',
                'grand_total'         => $grandTotal,
                'total_dibayar'       => $totalDibayar,
                'sisa'                => $sisa,
                'status_label'        => status_pembayaran_label($statusPembayaran),
                'status_class'        => status_pembayaran_badge_class($statusPembayaran),
                'status_pembayaran'   => $statusPembayaran,
            ];
        }

        return $this->response->setJSON([
            'draw'            => $draw,
            'recordsTotal'    => $total,
            'recordsFiltered' => $total,
            'data'            => $data,
        ]);
    }



    /**
     * Rentang tanggal filter daftar tagihan dari query string.
     *
     * Tidak ada default -- kalau tanggal_awal/tanggal_akhir kosong atau
     * tidak valid (strtotime() === false, jangan percaya isi query
     * string), filter itu diabaikan (null) sehingga TIDAK membatasi
     * tanggal. Masing-masing tanggal divalidasi/diabaikan independen.
     *
     * @return array{0: ?string, 1: ?string} [tanggal_awal, tanggal_akhir]
     */
    private function getRentangTanggal(): array
    {
        $tanggalAwal  = $this->request->getGet('tanggal_awal');
        $tanggalAkhir = $this->request->getGet('tanggal_akhir');

        if (empty($tanggalAwal) || strtotime($tanggalAwal) === false) {
            $tanggalAwal = null;
        }

        if (empty($tanggalAkhir) || strtotime($tanggalAkhir) === false) {
            $tanggalAkhir = null;
        }

        return [$tanggalAwal, $tanggalAkhir];
    }

    public function detail($id)
    {
        $transaksiModel = new TransaksiModel();
        $detailModel = new DetailTransaksiModel();
        $pembayaranModel = new PembayaranModel();
        $pelangganModel = new PelangganModel();

        // Ambil data transaksi
        $transaksi = $transaksiModel->select('transaksi.*, users.username as kasir_nama, users.inisial as kasir_inisial')
            ->join('users', 'users.id = transaksi.kasir_id', 'left')
            ->find($id);

        if (!$transaksi) {
            return redirect()->to('/tagihan')->with('error', 'Transaksi tidak ditemukan.');
        }

        if (in_array($transaksi['status'], ['batal', 'mangkrak'])) {
            return redirect()->to('/tagihan');
        }

        if ($transaksi['status_pembayaran'] === 'lunas') {
            return redirect()->to('/tagihan')->with('error', 'Transaksi tidak bisa dilunasi.');
        }

        $detailItems = $detailModel->where('transaksi_id', $id)->findAll();
        $pembayaran = $pembayaranModel
            ->select('pembayaran.*, users.nama as kasir_nama, users.username as kasir_username, users.inisial as kasir_inisial')
            ->join('users', 'users.id = pembayaran.kasir_id', 'left')
            ->where('pembayaran.transaksi_id', $id)
            ->where('pembayaran.status', 'aktif')
            ->orderBy('pembayaran.tanggal', 'ASC')
            ->findAll();
        $pelanggan = $transaksi['pelanggan_id'] ? $pelangganModel->find($transaksi['pelanggan_id']) : null;

        $total_dibayar = array_sum(array_column($pembayaran, 'jumlah'));
        $sisa_tagihan = max(0, $transaksi['grand_total'] - $total_dibayar);

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
            // Flag: dipakai view transaksi/detail untuk (1) tombol "Kembali"
            // mengarah ke /tagihan, bukan /transaksi, dan (2) menampilkan
            // tombol "Lunasi" saat masih ada sisa tagihan.
            'dariTagihan'   => $dariTagihan,
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
        $metode = strtolower(trim((string) ($request->metode ?? 'tunai')));

        if (!in_array($metode, PembayaranModel::METODE, true)) {
            return $this->response->setJSON([
                'status'  => 'error',
                'message' => 'Metode pembayaran tidak valid.'
            ])->setStatusCode(422);
        }

        $isAdmin = session()->get('role') === 'admin';
        $kasirIdSesi = session()->get('id_user') ?? 1;

        // Tahap 5: Shift Leader boleh backdate persis seperti admin,
        // sama pola dengan Api::tambahPembayaran() (lihat
        // App\Services\Authority).
        $isShiftLeader = \App\Services\Authority::isCurrentShiftLeader((int) session()->get('id_user'));

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
         * Sama seperti Api::tambahPembayaran() — admin atau Shift
         * Leader saat ini (Tahap 5), dan divalidasi ulang secara
         * otoritatif di TransaksiModel::tambahPembayaran().
         */
        $tanggalPembayaran = date('Y-m-d H:i:s');
        $kasirId = $kasirIdSesi;

        if (($isAdmin || $isShiftLeader) && !empty($request->tanggal)) {
            $tanggalPembayaran = (string) $request->tanggal;
        }

        if (($isAdmin || $isShiftLeader) && !empty($request->kasir_id)) {
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
            $transaksiModel->tambahPembayaran($id, $dataPembayaran, $isAdmin, $isShiftLeader);
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
