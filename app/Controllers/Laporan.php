<?php

namespace App\Controllers;

use App\Models\TransaksiModel;
use App\Models\DetailTransaksiModel;
use App\Models\KategoriModel;
use App\Models\PelangganModel;

class Laporan extends BaseController
{
    // Mapping kategori untuk Laporan Bulanan -- DIKONFIRMASI ke
    // database aktual (bukan asumsi dokumen), konsisten dengan
    // processBulanan() lama:
    // 1=Penjualan, 2=Fotokopi, 3=Minuman, 16=Digital Foto, 4=Digital Printing
    private const KATEGORI_PENJUALAN = 1;
    private const KATEGORI_FOTOKOPI = 2;
    private const KATEGORI_MINUMAN = 3;
    private const KATEGORI_DIGITAL_FOTO = 16;
    private const KATEGORI_DIGITAL_PRINTING = 4;

    public function index()
    {
        // Cek apakah user adalah admin
        $session = session();
        if ($session->get('role') != 'admin') {
            return redirect()->to('/kasir')->with('error', 'Akses ditolak. Hanya untuk admin.');
        }

        $kategoriModel = new KategoriModel();
        $pelangganModel = new PelangganModel();

        $data = [
            'title' => 'Laporan | AULIA',
            'content' => 'laporan/index',
            'kategori' => $kategoriModel->orderBy('nama', 'ASC')->findAll(),
            'pelanggan' => $pelangganModel->orderBy('nama', 'ASC')->findAll(),
        ];

        return view('layout/main', $data);
    }

    /**
     * Ambil data laporan via AJAX
     */
    public function getData()
    {
        $request = $this->request->getJSON();
        $jenis = $request->jenis ?? 'harian';
        $tanggal_awal = $request->tanggal_awal ?? date('Y-m-d');
        $tanggal_akhir = $request->tanggal_akhir ?? date('Y-m-d');
        $kategori_id = $request->kategori_id ?? null;

        // =========================================================
        // TAB HARIAN / PEMASUKAN HARIAN
        // =========================================================
        // Jalur ini sengaja dipisahkan dari proses laporan lama.
        // Periode dan Per Kategori tetap memakai alur lama; Bulanan
        // juga sudah dipisah (lihat blok tepat di bawah ini).
        if ($jenis === 'harian') {
            $result = $this->getPemasukanHarianData(
                $tanggal_awal,
                $tanggal_akhir
            );

            log_message(
                'debug',
                'Pemasukan Harian - jumlah baris: ' .
                    count($result['data'])
            );

            return $this->response->setJSON([
                'status' => 'success',
                'data' => $result['data'],
                'summary' => $result['summary'],
                'total' => count($result['data'])
            ]);
        }

        // =========================================================
        // TAB BULANAN
        // =========================================================
        // Sama seperti Harian, jalur ini sengaja dipisahkan dari
        // proses laporan lama (processData/processBulanan) karena
        // spesifikasi kolom & aturan Ganti/Edit-nya berbeda. Harus
        // di atas kode lama supaya TIDAK ikut kena early-return
        // "kosong" milik jalur lama saat suatu bulan sepi transaksi
        // (Periode, Harian, Per Kategori TIDAK terpengaruh).
        if ($jenis === 'bulanan') {
            $result = $this->getLaporanBulananData(
                $tanggal_awal,
                $tanggal_akhir
            );

            return $this->response->setJSON([
                'status' => 'success',
                'data' => $result['data'],
                'summary' => $result['summary'],
                'total' => count($result['data'])
            ]);
        }

        // 🔥 LOG UNTUK DEBUG
        log_message('debug', '========== LAPORAN GET DATA ==========');
        log_message('debug', 'Jenis: ' . $jenis);
        log_message('debug', 'Tanggal Awal: ' . $tanggal_awal);
        log_message('debug', 'Tanggal Akhir: ' . $tanggal_akhir);
        log_message('debug', 'Kategori ID: ' . ($kategori_id ?? 'null'));

        $transaksiModel = new TransaksiModel();
        $detailModel = new DetailTransaksiModel();

        // 🔥 Cek jumlah transaksi dulu
        $count = $transaksiModel
            ->where('DATE(tanggal) >=', $tanggal_awal)
            ->where('DATE(tanggal) <=', $tanggal_akhir)
            ->where('status !=', 'batal')
            ->countAllResults();

        log_message('debug', 'Jumlah Transaksi (count): ' . $count);

        // 🔥 Query dasar
        $transaksi = $transaksiModel
            ->select('transaksi.*, pelanggan.nama as pelanggan_nama')
            ->join('pelanggan', 'pelanggan.id = transaksi.pelanggan_id', 'left')
            ->where('DATE(tanggal) >=', $tanggal_awal)
            ->where('DATE(tanggal) <=', $tanggal_akhir)
            ->where('transaksi.status !=', 'batal')
            ->orderBy('transaksi.tanggal', 'ASC')
            ->findAll();

        // 🔥 LOG query
        log_message('debug', 'Jumlah Transaksi (findAll): ' . count($transaksi));

        // =========================================================
        // LENGKAPI DENGAN ARCHIVE
        // =========================================================
        // Rentang tanggal laporan (Periode/Per Kategori) bebas dipilih
        // user, jadi bisa saja melewati bulan yang sudah di-archive.
        // MySQL tidak bisa JOIN ke SQLite, jadi archive di-query
        // terpisah lalu digabung di sini SEBELUM masuk ke processData()
        // -- supaya seluruh logic olah-data di bawah (processData,
        // processDetailTransaksi, processBulanan, processPerKategori,
        // calculateSummary) tetap berjalan sama persis tanpa diubah,
        // baik untuk data live maupun archive.
        try {
            $archiveService = new \App\Services\TransaksiArchiveService();
            $awalDt = $tanggal_awal . ' 00:00:00';
            $akhirDt = $tanggal_akhir . ' 23:59:59';
            $transaksiArchive = $archiveService->getTransaksiMentah($awalDt, $akhirDt);

            if (!empty($transaksiArchive)) {
                $transaksi = array_merge($transaksi, $transaksiArchive);
                log_message('debug', 'Jumlah Transaksi dari archive: ' . count($transaksiArchive));
            }
        } catch (\Throwable $e) {
            log_message('error', 'Laporan getData() gagal baca archive: ' . $e->getMessage());
        }

        if (empty($transaksi)) {
            log_message('debug', '⚠️ TIDAK ADA TRANSAKSI!');
            return $this->response->setJSON([
                'status' => 'success',
                'data' => [],
                'summary' => $this->getEmptySummary(),
                'message' => 'Tidak ada data untuk periode ini.'
            ]);
        }

        // 🔥 LOG transaksi pertama
        if (!empty($transaksi)) {
            log_message('debug', 'Contoh Transaksi: ' . json_encode($transaksi[0]));
        }

        // 🔥 Ambil detail untuk setiap transaksi
        $transaksiIds = array_column($transaksi, 'id');
        log_message('debug', 'Transaksi IDs: ' . json_encode($transaksiIds));

        $details = $detailModel->whereIn('transaksi_id', $transaksiIds)->findAll();
        log_message('debug', 'Jumlah Detail: ' . count($details));

        // Detail utk baris yang sumbernya archive tidak akan ketemu di
        // atas (whereIn ke tabel MySQL) -- lengkapi dari archive juga.
        try {
            $idArchive = array_column($transaksiArchive ?? [], 'id');
            if (!empty($idArchive)) {
                $detailArchive = $archiveService->getDetailTransaksiMentah($idArchive);
                $details = array_merge($details, $detailArchive);
                log_message('debug', 'Jumlah Detail dari archive: ' . count($detailArchive));
            }
        } catch (\Throwable $e) {
            log_message('error', 'Laporan getData() gagal baca detail archive: ' . $e->getMessage());
        }

        // 🔥 Group detail per transaksi
        $detailGroup = [];
        foreach ($details as $d) {
            $detailGroup[$d['transaksi_id']][] = $d;
        }

        // 🔥 LOG detail group
        log_message('debug', 'Detail Group Keys: ' . json_encode(array_keys($detailGroup)));

        // 🔥 Proses data berdasarkan jenis laporan
        $result = $this->processData($jenis, $transaksi, $detailGroup, $kategori_id);

        log_message('debug', 'Data hasil: ' . count($result['data']));
        log_message('debug', 'Summary: ' . json_encode($result['summary']));
        log_message('debug', '========== END LAPORAN GET DATA ==========');

        return $this->response->setJSON([
            'status' => 'success',
            'data' => $result['data'],
            'summary' => $result['summary'],
            'total' => count($result['data'])
        ]);
    }

    /**
     * Pemasukan Harian
     *
     * - Sumber tanggal = pembayaran.tanggal
     * - Pembayaran dialokasikan ke seluruh detail berdasarkan
     *   proporsi subtotal detail / total subtotal seluruh detail transaksi.
     * - Detail yang nama produknya mengandung "ganti" atau "edit"
     *   dipisahkan ke kolom ganti_edit.
     * - QRIS dan Transfer adalah nilai pembayaran aktual,
     *   TANPA alokasi proporsi.
     *
     * Jalur ini berdiri sendiri supaya laporan lama tidak berubah.
     */
    private function getPemasukanHarianData($tanggalAwal, $tanggalAkhir)
    {
        $db = db_connect();

        // =========================================================
        // MASTER KATEGORI
        // =========================================================

        $kategoriRows = $db
            ->table('kategori')
            ->select('id, nama')
            ->orderBy('nama', 'ASC')
            ->get()
            ->getResultArray();

        // =========================================================
        // PEMBAYARAN DALAM RENTANG TANGGAL
        // =========================================================

        $pembayaranRows = $db
            ->table('pembayaran')
            ->select('
                id,
                transaksi_id,
                tanggal,
                jumlah,
                metode
            ')
            ->where(
                'tanggal >=',
                $tanggalAwal . ' 00:00:00'
            )
            ->where(
                'tanggal <=',
                $tanggalAkhir . ' 23:59:59'
            )
            ->where('status', 'aktif')
            ->orderBy('tanggal', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        // Lengkapi dengan archive -- Pemasukan Harian sumbernya
        // pembayaran.tanggal, jadi rentang tanggal laporan ini bisa
        // saja masuk ke bulan yang sudah di-archive. MySQL tidak bisa
        // JOIN ke SQLite, jadi digabung di PHP di sini (kolom yang
        // dipilih SENGAJA disamakan persis: id, transaksi_id, tanggal,
        // jumlah, metode) sebelum masuk ke logic alokasi di bawah,
        // supaya logic itu tidak perlu diubah sama sekali.
        $archiveService = null;

        try {
            $archiveService = new \App\Services\TransaksiArchiveService();
            $pembayaranArchive = $archiveService->getPembayaranMentah(
                $tanggalAwal . ' 00:00:00',
                $tanggalAkhir . ' 23:59:59'
            );

            if (!empty($pembayaranArchive)) {
                $pembayaranRows = array_merge($pembayaranRows, $pembayaranArchive);
            }
        } catch (\Throwable $e) {
            log_message('error', 'getPemasukanHarianData gagal baca archive: ' . $e->getMessage());
        }

        // =========================================================
        // GROUP PEMBAYARAN PER TANGGAL + TRANSAKSI
        // =========================================================

        $pembayaranPerTanggal = [];
        $transaksiIds = [];

        // Untuk QRIS / Transfer aktual per tanggal.
        $nonTunaiPerTanggal = [];

        foreach ($pembayaranRows as $payment) {

            $transaksiId = (int) (
                $payment['transaksi_id'] ?? 0
            );

            if (!$transaksiId) {
                continue;
            }

            $tanggalBayar = date(
                'Y-m-d',
                strtotime($payment['tanggal'])
            );

            $jumlah = (float) (
                $payment['jumlah'] ?? 0
            );

            $metode = strtolower(
                trim((string) (
                    $payment['metode'] ?? ''
                ))
            );

            if (
                !isset(
                    $pembayaranPerTanggal[$tanggalBayar]
                )
            ) {
                $pembayaranPerTanggal[$tanggalBayar] = [];
            }

            $pembayaranPerTanggal[$tanggalBayar][$transaksiId] =
                (
                    $pembayaranPerTanggal[$tanggalBayar][$transaksiId]
                    ?? 0
                )
                + $jumlah;

            $transaksiIds[$transaksiId] = true;

            // =====================================================
            // PEMBAYARAN AKTUAL QRIS / TRANSFER
            // =====================================================

            if (
                !isset(
                    $nonTunaiPerTanggal[$tanggalBayar]
                )
            ) {
                $nonTunaiPerTanggal[$tanggalBayar] = [
                    'qris' => 0,
                    'transfer' => 0,
                    'total_non_tunai' => 0,
                ];
            }

            if ($metode === 'qris') {

                $nonTunaiPerTanggal[$tanggalBayar]['qris'] += $jumlah;
            } elseif ($metode === 'transfer') {

                $nonTunaiPerTanggal[$tanggalBayar]['transfer'] += $jumlah;
            }

            if (
                $metode === 'qris'
                || $metode === 'transfer'
            ) {
                $nonTunaiPerTanggal[$tanggalBayar]['total_non_tunai'] += $jumlah;
            }
        }

        // =========================================================
        // AMBIL SEMUA DETAIL TRANSAKSI YANG MEMILIKI PEMBAYARAN
        // =========================================================

        $detailPerTransaksi = [];

        if (!empty($transaksiIds)) {

            $detailRows = $db
                ->table('detail_transaksi')
                ->select('
                    id,
                    transaksi_id,
                    nama_produk,
                    kategori_id,
                    subtotal
                ')
                ->whereIn(
                    'transaksi_id',
                    array_keys($transaksiIds)
                )
                ->orderBy(
                    'transaksi_id',
                    'ASC'
                )
                ->orderBy(
                    'id',
                    'ASC'
                )
                ->get()
                ->getResultArray();

            // Lengkapi dengan archive -- IDs di $transaksiIds sudah
            // gabungan live+archive (dibangun dari $pembayaranRows yang
            // sudah digabung di atas), jadi list ID yang sama aman
            // dikirim ke archive juga -- ID yang tidak ada di sana
            // otomatis tidak menghasilkan baris tambahan (bukan error).
            try {
                $detailArchive = $archiveService ? $archiveService->getDetailTransaksiMentah(array_keys($transaksiIds)) : [];
                if (!empty($detailArchive)) {
                    $detailRows = array_merge($detailRows, $detailArchive);
                }
            } catch (\Throwable $e) {
                log_message('error', 'getPemasukanHarianData gagal baca detail archive: ' . $e->getMessage());
            }

            foreach ($detailRows as $detail) {

                $tid = (int) (
                    $detail['transaksi_id']
                    ?? 0
                );

                if (!$tid) {
                    continue;
                }

                $detailPerTransaksi[$tid][] =
                    $detail;
            }
        }

        // =========================================================
        // INITIAL HASIL PER TANGGAL
        // =========================================================

        $hasil = [];

        foreach ($pembayaranPerTanggal as $tanggal => $paymentsHari) {

            $hasil[$tanggal] = [
                'tanggal' => date(
                    'd/m/Y',
                    strtotime($tanggal)
                ),
                'total' => 0,
                'ganti_edit' => 0,
                'qris' => $nonTunaiPerTanggal[$tanggal]['qris'] ?? 0,
                'transfer' => $nonTunaiPerTanggal[$tanggal]['transfer'] ?? 0,
                'total_non_tunai' =>
                $nonTunaiPerTanggal[$tanggal]['total_non_tunai'] ?? 0,
            ];

            foreach ($kategoriRows as $kategori) {

                $hasil[$tanggal]['kategori_' .
                    (int) $kategori['id']] = 0;
            }
        }

        // =========================================================
        // ALOKASI PEMBAYARAN KE DETAIL
        // =========================================================

        foreach (
            $pembayaranPerTanggal
            as $tanggal => $paymentsHari
        ) {

            foreach (
                $paymentsHari
                as $transaksiId => $pembayaranHariIni
            ) {

                $details =
                    $detailPerTransaksi[$transaksiId] ?? [];

                if (empty($details)) {
                    continue;
                }

                // -------------------------------------------------
                // TOTAL SUBTOTAL SELURUH DETAIL
                // -------------------------------------------------

                $totalSubtotal = 0;

                foreach ($details as $detail) {

                    $totalSubtotal +=
                        (float) (
                            $detail['subtotal']
                            ?? 0
                        );
                }

                if ($totalSubtotal <= 0) {
                    continue;
                }

                // -------------------------------------------------
                // ALOKASI PER DETAIL
                // -------------------------------------------------

                foreach ($details as $detail) {

                    $subtotalDetail =
                        (float) (
                            $detail['subtotal']
                            ?? 0
                        );

                    if ($subtotalDetail <= 0) {
                        continue;
                    }

                    $proporsi =
                        $subtotalDetail
                        /
                        $totalSubtotal;

                    $nilaiDibayar =
                        $pembayaranHariIni
                        *
                        $proporsi;

                    $namaProduk =
                        strtolower(
                            trim(
                                (string) (
                                    $detail['nama_produk']
                                    ?? ''
                                )
                            )
                        );

                    $isGantiEdit =
                        strpos(
                            $namaProduk,
                            'ganti'
                        ) !== false
                        ||
                        strpos(
                            $namaProduk,
                            'edit'
                        ) !== false;

                    if ($isGantiEdit) {

                        $hasil[$tanggal]['ganti_edit'] += $nilaiDibayar;
                    } else {

                        $kategoriId =
                            (int) (
                                $detail['kategori_id']
                                ?? 0
                            );

                        $key =
                            'kategori_' .
                            $kategoriId;

                        if (
                            !isset(
                                $hasil[$tanggal][$key]
                            )
                        ) {
                            $hasil[$tanggal][$key] = 0;
                        }

                        $hasil[$tanggal][$key] +=
                            $nilaiDibayar;
                    }

                    $hasil[$tanggal]['total'] +=
                        $nilaiDibayar;
                }
            }
        }

        ksort($hasil);

        // =========================================================
        // SUMMARY
        // =========================================================

        $summary = [
            'total' => 0,
            'ganti_edit' => 0,
            'qris' => 0,
            'transfer' => 0,
            'total_non_tunai' => 0,
            'jumlah_transaksi' => count(
                $transaksiIds
            ),
            'jumlah_pembayaran' => count(
                $pembayaranRows
            ),
        ];

        foreach ($hasil as $dataHari) {

            $summary['total'] +=
                (float) (
                    $dataHari['total']
                    ?? 0
                );

            $summary['ganti_edit'] +=
                (float) (
                    $dataHari['ganti_edit']
                    ?? 0
                );

            $summary['qris'] +=
                (float) (
                    $dataHari['qris']
                    ?? 0
                );

            $summary['transfer'] +=
                (float) (
                    $dataHari['transfer']
                    ?? 0
                );

            $summary['total_non_tunai'] +=
                (float) (
                    $dataHari['total_non_tunai']
                    ?? 0
                );
        }

        return [
            'data' => array_values($hasil),
            'summary' => $summary,
        ];
    }

    /**
     * LAPORAN BULANAN (spesifikasi baru, 2026-09-05)
     *
     * Kolom: Tanggal, Penjualan, Fotokopi, Minuman, Digital Foto,
     * Digital Printing, Ganti BG, Total, TF+QRIS, Uang Keluar.
     *
     * Mapping kategori (DIKONFIRMASI ke database aktual, BUKAN
     * asumsi -- kode existing processBulanan() dan konfirmasi
     * langsung sama-sama menunjukkan mapping ini):
     *   1  = Penjualan
     *   2  = Fotokopi
     *   3  = Minuman
     *   16 = Digital Foto
     *   4  = Digital Printing
     *
     * Prinsip alokasi (konsisten dengan getPemasukanHarianData()):
     * - Baris dikelompokkan per TANGGAL PEMBAYARAN (bukan tanggal
     *   transaksi), karena TF+QRIS eksplisit diminta berdasarkan
     *   tanggal pembayaran, dan supaya satu mekanisme dipakai
     *   konsisten untuk seluruh kolom kategori juga.
     * - Nominal pembayaran dialokasikan proporsional ke tiap detail
     *   berdasarkan subtotal detail / total subtotal transaksi
     *   (mekanisme yang sama dengan Harian).
     * - Ganti/Edit diidentifikasi dari nama produk (LIKE '%ganti%'
     *   OR '%edit%'), SAMA seperti Harian -- TIDAK ada flag/kategori
     *   khusus di database untuk ini.
     * - PENTING (beda dari Harian): nilai Ganti/Edit TETAP masuk ke
     *   bucket kategorinya (Digital Foto, karena produk Ganti/Edit
     *   memang berkategori Digital Foto) -- TIDAK dikeluarkan/
     *   dikurangi. Ganti BG dihitung TERPISAH (nilai Ganti/Edit / 2)
     *   sebagai kolom tambahan, dan TIDAK pernah ditambahkan ke Total.
     */
    private function getLaporanBulananData($tanggalAwal, $tanggalAkhir)
    {
        $db = db_connect();

        // =========================================================
        // 1) SCAFFOLD SEMUA TANGGAL DALAM RENTANG (default 0)
        // =========================================================
        // Dibuat SEBELUM mengambil data apa pun, supaya tanggal tanpa
        // transaksi/pengeluaran tetap muncul (Section "Periode Bulanan"
        // & "Pencegahan Double Counting" poin 7-8).

        $hasil = [];
        $start = new \DateTime($tanggalAwal);
        $end = new \DateTime($tanggalAkhir);
        $end->modify('+1 day');

        foreach (new \DatePeriod($start, new \DateInterval('P1D'), $end) as $d) {
            $key = $d->format('Y-m-d');

            $hasil[$key] = [
                'tanggal' => $d->format('d/m/Y'),
                'tanggal_raw' => $key,
                'penjualan' => 0.0,
                'fotokopi' => 0.0,
                'minuman' => 0.0,
                'digital_foto' => 0.0,
                'digital_printing' => 0.0,
                'ganti_bg' => 0.0,
                'total' => 0.0,
                'tf_qris' => 0.0,
                'uang_keluar' => 0.0,
            ];
        }

        // =========================================================
        // 2) PEMBAYARAN DALAM RENTANG (by tanggal pembayaran)
        // =========================================================

        $pembayaranRows = $db->table('pembayaran')
            ->select('id, transaksi_id, tanggal, jumlah, metode')
            ->where('tanggal >=', $tanggalAwal . ' 00:00:00')
            ->where('tanggal <=', $tanggalAkhir . ' 23:59:59')
            ->where('status', 'aktif')
            ->orderBy('tanggal', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        // Lengkapi dengan archive -- pola sama seperti getPemasukanHarianData(),
        // lihat komentar lengkap di sana.
        $archiveService = null;

        try {
            $archiveService = new \App\Services\TransaksiArchiveService();
            $pembayaranArchive = $archiveService->getPembayaranMentah(
                $tanggalAwal . ' 00:00:00',
                $tanggalAkhir . ' 23:59:59'
            );

            if (!empty($pembayaranArchive)) {
                $pembayaranRows = array_merge($pembayaranRows, $pembayaranArchive);
            }
        } catch (\Throwable $e) {
            log_message('error', 'getLaporanBulananData gagal baca archive: ' . $e->getMessage());
        }

        $transaksiIds = [];
        $pembayaranPerTanggalTransaksi = [];

        foreach ($pembayaranRows as $p) {
            $tid = (int) ($p['transaksi_id'] ?? 0);

            if (!$tid) {
                continue;
            }

            $tgl = date('Y-m-d', strtotime($p['tanggal']));

            if (!isset($hasil[$tgl])) {
                // Di luar rentang (harusnya tidak terjadi krn WHERE di atas).
                continue;
            }

            $jumlah = (float) ($p['jumlah'] ?? 0);
            $metode = strtolower(trim((string) ($p['metode'] ?? '')));

            // --- TF + QRIS: nilai AKTUAL pembayaran, TIDAK diprorata,
            // TIDAK dihitung dua kali (satu row pembayaran = satu kali). ---
            if ($metode === 'qris' || $metode === 'transfer') {
                $hasil[$tgl]['tf_qris'] += $jumlah;
            }

            $transaksiIds[$tid] = true;
            $pembayaranPerTanggalTransaksi[$tgl][$tid] =
                ($pembayaranPerTanggalTransaksi[$tgl][$tid] ?? 0) + $jumlah;
        }

        // =========================================================
        // 3) DETAIL TRANSAKSI (untuk alokasi proporsional per kategori)
        // =========================================================

        $detailPerTransaksi = [];

        if (!empty($transaksiIds)) {
            $rows = $db->table('detail_transaksi')
                ->select('id, transaksi_id, nama_produk, kategori_id, subtotal')
                ->whereIn('transaksi_id', array_keys($transaksiIds))
                ->orderBy('transaksi_id', 'ASC')
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();

            try {
                $detailArchive = $archiveService ? $archiveService->getDetailTransaksiMentah(array_keys($transaksiIds)) : [];
                if (!empty($detailArchive)) {
                    $rows = array_merge($rows, $detailArchive);
                }
            } catch (\Throwable $e) {
                log_message('error', 'getLaporanBulananData gagal baca detail archive: ' . $e->getMessage());
            }

            foreach ($rows as $r) {
                $tid = (int) ($r['transaksi_id'] ?? 0);

                if ($tid) {
                    $detailPerTransaksi[$tid][] = $r;
                }
            }
        }

        // =========================================================
        // 4) ALOKASI PEMBAYARAN -> KATEGORI (proporsional per detail)
        // =========================================================

        foreach ($pembayaranPerTanggalTransaksi as $tgl => $perTransaksi) {
            foreach ($perTransaksi as $tid => $totalBayarHariIni) {
                $details = $detailPerTransaksi[$tid] ?? [];

                if (empty($details)) {
                    continue;
                }

                $totalSubtotal = 0.0;

                foreach ($details as $d) {
                    $totalSubtotal += (float) ($d['subtotal'] ?? 0);
                }

                if ($totalSubtotal <= 0) {
                    continue;
                }

                foreach ($details as $d) {
                    $subtotalDetail = (float) ($d['subtotal'] ?? 0);

                    if ($subtotalDetail <= 0) {
                        continue;
                    }

                    $proporsi = $subtotalDetail / $totalSubtotal;
                    $nilai = $totalBayarHariIni * $proporsi;

                    $katId = (int) ($d['kategori_id'] ?? 0);

                    switch ($katId) {
                        case self::KATEGORI_PENJUALAN:
                            $hasil[$tgl]['penjualan'] += $nilai;
                            break;
                        case self::KATEGORI_FOTOKOPI:
                            $hasil[$tgl]['fotokopi'] += $nilai;
                            break;
                        case self::KATEGORI_MINUMAN:
                            $hasil[$tgl]['minuman'] += $nilai;
                            break;
                        case self::KATEGORI_DIGITAL_FOTO:
                            $hasil[$tgl]['digital_foto'] += $nilai;
                            break;
                        case self::KATEGORI_DIGITAL_PRINTING:
                            $hasil[$tgl]['digital_printing'] += $nilai;
                            break;
                        default:
                            // Konsisten dgn fallback processBulanan() lama:
                            // kategori tak dikenal masuk Penjualan.
                            $hasil[$tgl]['penjualan'] += $nilai;
                            break;
                    }

                    // --- GANTI BG: tambahan TERPISAH, TIDAK exclude dari
                    // bucket kategori di atas (Ganti/Edit sudah otomatis
                    // masuk Digital Foto lewat kategori_id-nya sendiri). ---
                    $namaProduk = strtolower(trim((string) ($d['nama_produk'] ?? '')));
                    $isGantiEdit = strpos($namaProduk, 'ganti') !== false
                        || strpos($namaProduk, 'edit') !== false;

                    if ($isGantiEdit) {
                        $hasil[$tgl]['ganti_bg'] += $nilai / 2;
                    }
                }
            }
        }

        // =========================================================
        // 5) UANG KELUAR (cash_expense, exclude kas_awal_hari)
        // =========================================================

        $expenseModel = new \App\Models\CashExpenseModel();
        $pengeluaranPerTanggal = $expenseModel->getPengeluaranPerTanggal($tanggalAwal, $tanggalAkhir);

        foreach ($pengeluaranPerTanggal as $tgl => $total) {
            if (isset($hasil[$tgl])) {
                $hasil[$tgl]['uang_keluar'] = $total;
            }
        }

        // =========================================================
        // 6) TOTAL PER TANGGAL = SUM 5 KATEGORI SAJA (bukan Ganti BG)
        // =========================================================

        foreach ($hasil as $tgl => &$row) {
            $row['total'] = $row['penjualan']
                + $row['fotokopi']
                + $row['minuman']
                + $row['digital_foto']
                + $row['digital_printing'];

            // Bulatkan ke rupiah di titik akhir (bukan tiap alokasi),
            // supaya tidak ada akumulasi selisih pembulatan.
            foreach (['penjualan', 'fotokopi', 'minuman', 'digital_foto', 'digital_printing', 'ganti_bg', 'total', 'tf_qris', 'uang_keluar'] as $kol) {
                $row[$kol] = round($row[$kol]);
            }
        }
        unset($row);

        ksort($hasil);
        $data = array_values($hasil);

        // =========================================================
        // 7) SUMMARY (total kolom untuk footer)
        // =========================================================

        $summary = [
            'penjualan' => 0, 'fotokopi' => 0, 'minuman' => 0,
            'digital_foto' => 0, 'digital_printing' => 0, 'ganti_bg' => 0,
            'total' => 0, 'tf_qris' => 0, 'uang_keluar' => 0,
        ];

        foreach ($data as $row) {
            foreach ($summary as $kol => $val) {
                $summary[$kol] += $row[$kol];
            }
        }

        return ['data' => $data, 'summary' => $summary];
    }

    /**
     * Proses data berdasarkan jenis laporan
     */
    private function processData($jenis, $transaksi, $detailGroup, $kategori_id = null)
    {
        $data = [];
        $summary = $this->getEmptySummary();

        switch ($jenis) {
            case 'harian':
            case 'periode':
                $data = $this->processDetailTransaksi($transaksi, $detailGroup, $kategori_id);
                $summary = $this->calculateSummary($data);
                break;

            case 'bulanan':
                $data = $this->processBulanan($transaksi, $detailGroup, $kategori_id);
                $summary = $this->calculateSummary($data);
                break;

            case 'kategori':
                $data = $this->processPerKategori($transaksi, $detailGroup, $kategori_id);
                $summary = $this->calculateSummary($data);
                break;

            default:
                $data = $this->processDetailTransaksi($transaksi, $detailGroup, $kategori_id);
                $summary = $this->calculateSummary($data);
        }

        return ['data' => $data, 'summary' => $summary];
    }

    /**
     * Proses Detail Transaksi (untuk Harian & Periode)
     */
    private function processDetailTransaksi($transaksi, $detailGroup, $kategori_id = null)
    {
        $data = [];

        foreach ($transaksi as $t) {
            $details = $detailGroup[$t['id']] ?? [];
            $subtotal = $t['subtotal'];
            $diskon = $t['diskon'];
            $grandTotal = $t['grand_total'];

            // 🔥 Jika filter kategori, hanya ambil item dari kategori tersebut
            if ($kategori_id) {
                $filteredDetails = array_filter($details, function ($d) use ($kategori_id) {
                    return $d['kategori_id'] == $kategori_id;
                });
                $details = $filteredDetails;

                // Hitung ulang subtotal untuk kategori tersebut
                $subtotal = array_sum(array_column($details, 'subtotal'));

                // 🔥 Hitung diskon proporsional untuk kategori
                $totalSubtotalAll = $t['subtotal'];
                if ($totalSubtotalAll > 0) {
                    $diskon = ($subtotal / $totalSubtotalAll) * $t['diskon'];
                } else {
                    $diskon = 0;
                }

                $grandTotal = $subtotal - $diskon;
            }

            // 🔥 Jika tidak ada detail, skip
            if (empty($details)) {
                continue;
            }

            $data[] = [
                'tanggal' => date('d/m/Y', strtotime($t['tanggal'])),
                'invoice' => $t['kode_invoice'],
                'no_order' => $t['no_order'] ?? '-',
                'pelanggan' => $t['pelanggan_nama'] ?? '-',
                'subtotal' => $subtotal,
                'diskon' => $diskon,
                'grand_total' => $grandTotal,
                'sisa_tagihan' => $t['grand_total'] - ($t['total_dibayar'] ?? 0),
                'status_pembayaran' => $t['status_pembayaran'],
                'status' => $t['status']
            ];
        }

        return $data;
    }

    /**
     * Proses Laporan Bulanan (per hari)
     */
    /**
     * Proses Laporan Bulanan (per hari) - Format Baru
     */
    private function processBulanan($transaksi, $detailGroup, $kategori_id = null)
    {
        $data = [];
        $groupByDate = [];

        // 🔥 Ambil semua tanggal dalam bulan
        $tanggalAwal = date('Y-m-01', strtotime($transaksi[0]['tanggal'] ?? date('Y-m-d')));
        $tanggalAkhir = date('Y-m-t', strtotime($tanggalAwal));
        $startDate = new \DateTime($tanggalAwal);
        $endDate = new \DateTime($tanggalAkhir);
        $endDate->modify('+1 day');

        // 🔥 Ambil semua produk untuk mapping kategori
        $produkModel = new \App\Models\ProdukModel();
        $allProduk = $produkModel->select('id, kategori_id, nama')->findAll();
        $produkKategoriMap = [];
        foreach ($allProduk as $p) {
            $produkKategoriMap[$p['id']] = $p['kategori_id'];
        }

        // 🔥 Inisialisasi semua tanggal
        $interval = new \DateInterval('P1D');
        $dateRange = new \DatePeriod($startDate, $interval, $endDate);

        foreach ($dateRange as $date) {
            $key = $date->format('Y-m-d');
            $groupByDate[$key] = [
                'tanggal' => $date->format('d/m/Y'),
                'tanggal_raw' => $key,
                'penjualan_umum' => 0,
                'fotokopi' => 0,
                'minuman' => 0,
                'foto' => 0,
                'digital_printing' => 0,
                'cash' => 0,
                'digital_payment' => 0
            ];
        }

        // 🔥 Proses setiap transaksi
        foreach ($transaksi as $t) {
            $tanggalTransaksi = date('Y-m-d', strtotime($t['tanggal']));

            // 🔥 Ambil semua pembayaran untuk transaksi ini
            $pembayaranList = $this->getPembayaranByTransaksi($t['id']);

            if (empty($pembayaranList)) {
                // 🔥 Jika tidak ada pembayaran, skip
                continue;
            }

            // 🔥 Hitung subtotal per kategori (kotor / sebelum diskon)
            $details = $detailGroup[$t['id']] ?? [];
            $subtotalPenjualanUmum = 0;
            $subtotalFotokopi = 0;
            $subtotalMinuman = 0;
            $subtotalFoto = 0;
            $subtotalDigitalPrinting = 0;
            $totalSubtotalAll = 0;

            foreach ($details as $d) {
                $produkId = $d['produk_id'] ?? 0;
                $katId = $produkKategoriMap[$produkId] ?? 0;
                $subtotalItem = $d['subtotal'] ?? 0;
                $totalSubtotalAll += $subtotalItem;

                switch ($katId) {
                    case 1:
                        $subtotalPenjualanUmum += $subtotalItem;
                        break;
                    case 2:
                        $subtotalFotokopi += $subtotalItem;
                        break;
                    case 3:
                        $subtotalMinuman += $subtotalItem;
                        break;
                    case 16:
                        $subtotalFoto += $subtotalItem;
                        break;
                    case 4:
                        $subtotalDigitalPrinting += $subtotalItem;
                        break;
                    default:
                        $subtotalPenjualanUmum += $subtotalItem;
                        break;
                }
            }

            // 🔥 Jika total subtotal 0, skip
            if ($totalSubtotalAll == 0) {
                continue;
            }

            // 🔥 Proses setiap pembayaran
            foreach ($pembayaranList as $pembayaran) {
                $tanggalBayar = date('Y-m-d', strtotime($pembayaran['tanggal']));
                $nominalBayar = $pembayaran['jumlah'];
                $metode = $pembayaran['metode'];

                // 🔥 Jika tanggal bayar di luar range laporan, skip
                if (!isset($groupByDate[$tanggalBayar])) {
                    continue;
                }

                // 🔥 Alokasikan nominal bayar secara proporsional ke kategori
                $faktorAlokasi = $nominalBayar / $totalSubtotalAll;

                $isCash = ($metode === 'tunai');
                $isDigital = ($metode === 'qris' || $metode === 'transfer');

                // 🔥 Update group di tanggal pembayaran
                $groupByDate[$tanggalBayar]['penjualan_umum'] += round($subtotalPenjualanUmum * $faktorAlokasi);
                $groupByDate[$tanggalBayar]['fotokopi'] += round($subtotalFotokopi * $faktorAlokasi);
                $groupByDate[$tanggalBayar]['minuman'] += round($subtotalMinuman * $faktorAlokasi);
                $groupByDate[$tanggalBayar]['foto'] += round($subtotalFoto * $faktorAlokasi);
                $groupByDate[$tanggalBayar]['digital_printing'] += round($subtotalDigitalPrinting * $faktorAlokasi);

                if ($isCash) {
                    $groupByDate[$tanggalBayar]['cash'] += $nominalBayar;
                } elseif ($isDigital) {
                    $groupByDate[$tanggalBayar]['digital_payment'] += $nominalBayar;
                }
            }
        }

        ksort($groupByDate);
        $data = array_values($groupByDate);

        return $data;
    }

    /**
     * Ambil semua pembayaran untuk transaksi
     */
    private function getPembayaranByTransaksi($transaksiId)
    {
        $pembayaranModel = new \App\Models\PembayaranModel();
        return $pembayaranModel->where('transaksi_id', $transaksiId)
            ->where('status', 'aktif')
            ->orderBy('tanggal', 'ASC')
            ->findAll();
    }
    /**
     * Ambil metode pembayaran dari transaksi
     */
    private function getMetodePembayaran($transaksiId)
    {
        $pembayaranModel = new \App\Models\PembayaranModel();
        $pembayaran = $pembayaranModel->where('transaksi_id', $transaksiId)
            ->where('status', 'aktif')
            ->orderBy('tanggal', 'ASC')
            ->first();

        return $pembayaran ? $pembayaran['metode'] : 'tunai';
    }

    /**
     * Proses Laporan per Kategori
     */
    /**
     * Proses Laporan per Kategori
     */
    private function processPerKategori($transaksi, $detailGroup, $kategori_id = null)
    {
        $data = [];
        $kategoriModel = new \App\Models\KategoriModel();
        $produkModel = new \App\Models\ProdukModel();

        // 🔥 Ambil semua produk dengan kategori-nya
        $allProduk = $produkModel->select('id, kategori_id')->findAll();
        $produkKategoriMap = [];
        foreach ($allProduk as $p) {
            $produkKategoriMap[$p['id']] = $p['kategori_id'];
        }

        $kategoriList = $kategoriModel->orderBy('nama', 'ASC')->findAll();

        // 🔥 Buat mapping kategori
        $kategoriMap = [];
        foreach ($kategoriList as $k) {
            $kategoriMap[$k['id']] = $k['nama'];
        }

        // 🔥 Jika filter kategori, hanya ambil kategori itu
        if ($kategori_id) {
            $kategoriMap = array_filter($kategoriMap, function ($key) use ($kategori_id) {
                return $key == $kategori_id;
            }, ARRAY_FILTER_USE_KEY);
        }

        // 🔥 Inisialisasi data per kategori
        $kategoriData = [];
        foreach ($kategoriMap as $id => $nama) {
            $kategoriData[$id] = [
                'kategori_id' => $id,
                'kategori_nama' => $nama,
                'total_kotor' => 0,
                'total_diskon' => 0,
                'total_bersih' => 0,
                'total_transaksi' => 0,
                'details' => []
            ];
        }

        // 🔥 Proses setiap transaksi
        foreach ($transaksi as $t) {
            $details = $detailGroup[$t['id']] ?? [];

            foreach ($details as $d) {
                // 🔥 Ambil kategori_id dari produk
                $produkId = $d['produk_id'] ?? 0;
                $katId = $produkKategoriMap[$produkId] ?? 0;

                if (!isset($kategoriData[$katId])) {
                    continue;
                }

                // 🔥 Hitung diskon proporsional
                $totalSubtotalAll = $t['subtotal'];
                if ($totalSubtotalAll > 0) {
                    $diskonItem = ($d['subtotal'] / $totalSubtotalAll) * $t['diskon'];
                } else {
                    $diskonItem = 0;
                }

                $kategoriData[$katId]['total_kotor'] += $d['subtotal'];
                $kategoriData[$katId]['total_diskon'] += $diskonItem;
                $kategoriData[$katId]['total_bersih'] += ($d['subtotal'] - $diskonItem);
                $kategoriData[$katId]['total_transaksi']++;

                $kategoriData[$katId]['details'][] = [
                    'tanggal' => date('d/m/Y', strtotime($t['tanggal'])),
                    'invoice' => $t['kode_invoice'],
                    'produk' => $d['nama_produk'],
                    'subtotal' => $d['subtotal'],
                    'diskon' => $diskonItem,
                    'grand_total' => $d['subtotal'] - $diskonItem
                ];
            }
        }

        // 🔥 Format data - HANYA kategori yang punya data
        foreach ($kategoriData as $id => $kd) {
            if ($kd['total_transaksi'] == 0) {
                continue;
            }
            $data[] = [
                'kategori_id' => $kd['kategori_id'],
                'kategori_nama' => $kd['kategori_nama'],
                'total_kotor' => $kd['total_kotor'],
                'total_diskon' => $kd['total_diskon'],
                'total_bersih' => $kd['total_bersih'],
                'total_transaksi' => $kd['total_transaksi'],
                'details' => $kd['details']
            ];
        }

        usort($data, function ($a, $b) {
            return $b['total_bersih'] - $a['total_bersih'];
        });

        return $data;
    }
    /**
     * Hitung Summary
     */
    /**
     * Hitung Summary
     */
    private function calculateSummary($data)
    {
        $summary = $this->getEmptySummary();

        if (empty($data)) {
            return $summary;
        }

        $isDetail = isset($data[0]['invoice']);
        $isBulanan = isset($data[0]['penjualan_umum']);
        $isKategori = isset($data[0]['kategori_nama']);

        if ($isDetail) {
            foreach ($data as $row) {
                $summary['total_transaksi']++;
                $summary['total_kotor'] += $row['subtotal'] ?? 0;
                $summary['total_diskon'] += $row['diskon'] ?? 0;
                $summary['total_bersih'] += $row['grand_total'] ?? 0;
                $summary['total_piutang'] += $row['sisa_tagihan'] ?? 0;
            }
        } elseif ($isBulanan) {
            foreach ($data as $row) {
                $summary['total_transaksi'] += 1;
                $summary['total_penjualan_umum'] = ($summary['total_penjualan_umum'] ?? 0) + ($row['penjualan_umum'] ?? 0);
                $summary['total_fotokopi'] = ($summary['total_fotokopi'] ?? 0) + ($row['fotokopi'] ?? 0);
                $summary['total_minuman'] = ($summary['total_minuman'] ?? 0) + ($row['minuman'] ?? 0);
                $summary['total_foto'] = ($summary['total_foto'] ?? 0) + ($row['foto'] ?? 0);
                $summary['total_digital_printing'] = ($summary['total_digital_printing'] ?? 0) + ($row['digital_printing'] ?? 0);
                $summary['total_cash'] = ($summary['total_cash'] ?? 0) + ($row['cash'] ?? 0);
                $summary['total_digital_payment'] = ($summary['total_digital_payment'] ?? 0) + ($row['digital_payment'] ?? 0);
            }
        } elseif ($isKategori) {
            foreach ($data as $row) {
                $summary['total_transaksi'] += $row['total_transaksi'] ?? 0;
                $summary['total_kotor'] += $row['total_kotor'] ?? 0;
                $summary['total_diskon'] += $row['total_diskon'] ?? 0;
                $summary['total_bersih'] += $row['total_bersih'] ?? 0;
            }
        }

        return $summary;
    }
    /**
     * Summary kosong
     */
    private function getEmptySummary()
    {
        return [
            'total_transaksi' => 0,
            'total_kotor' => 0,
            'total_diskon' => 0,
            'total_bersih' => 0,
            'total_piutang' => 0,
            // 🔥 Tambahan untuk bulanan
            'total_fotokopi' => 0,
            'total_minuman' => 0,
            'total_foto' => 0,
            'total_digital_printing' => 0,
            'total_cash' => 0,
            'total_digital_payment' => 0
        ];
    }

    /**
     * Export ke Excel
     */
    /**
     * Export ke CSV (Alternatif tanpa PhpSpreadsheet)
     */
    public function exportExcel()
    {
        // 🔥 Cek admin
        $session = session();
        if ($session->get('role') != 'admin') {
            return redirect()->to('/kasir')->with('error', 'Akses ditolak.');
        }

        // 🔥 Ambil data dari POST
        $jenis = $this->request->getPost('jenis') ?? 'harian';
        $tanggal_awal = $this->request->getPost('tanggal_awal') ?? date('Y-m-d');
        $tanggal_akhir = $this->request->getPost('tanggal_akhir') ?? date('Y-m-d');
        $kategori_id = $this->request->getPost('kategori_id') ?? null;

        // =========================================================
        // TAB BULANAN -- jalur terpisah, sama seperti di getData()
        // =========================================================
        if ($jenis === 'bulanan') {
            $result = $this->getLaporanBulananData($tanggal_awal, $tanggal_akhir);

            $filename = 'Laporan_Bulanan_' . date('Y-m-d') . '.csv';

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            $output = fopen('php://output', 'w');
            fputs($output, "\xEF\xBB\xBF");

            fputcsv($output, ['Laporan Bulanan']);
            fputcsv($output, ['Periode: ' . date('d/m/Y', strtotime($tanggal_awal)) . ' - ' . date('d/m/Y', strtotime($tanggal_akhir))]);
            fputcsv($output, []);

            fputcsv($output, [
                'Tanggal', 'Penjualan', 'Fotokopi', 'Minuman', 'Digital Foto',
                'Digital Printing', 'Ganti BG', 'Total', 'TF + QRIS', 'Uang Keluar',
            ]);

            foreach ($result['data'] as $item) {
                fputcsv($output, [
                    $item['tanggal'],
                    $this->formatAngka($item['penjualan']),
                    $this->formatAngka($item['fotokopi']),
                    $this->formatAngka($item['minuman']),
                    $this->formatAngka($item['digital_foto']),
                    $this->formatAngka($item['digital_printing']),
                    $this->formatAngka($item['ganti_bg']),
                    $this->formatAngka($item['total']),
                    $this->formatAngka($item['tf_qris']),
                    $this->formatAngka($item['uang_keluar']),
                ]);
            }

            fputcsv($output, []);
            $s = $result['summary'];
            fputcsv($output, [
                'TOTAL',
                $this->formatAngka($s['penjualan']),
                $this->formatAngka($s['fotokopi']),
                $this->formatAngka($s['minuman']),
                $this->formatAngka($s['digital_foto']),
                $this->formatAngka($s['digital_printing']),
                $this->formatAngka($s['ganti_bg']),
                $this->formatAngka($s['total']),
                $this->formatAngka($s['tf_qris']),
                $this->formatAngka($s['uang_keluar']),
            ]);

            fclose($output);
            exit();
        }

        // 🔥 Ambil data
        $transaksiModel = new TransaksiModel();
        $detailModel = new DetailTransaksiModel();

        $transaksi = $transaksiModel
            ->select('transaksi.*, pelanggan.nama as pelanggan_nama')
            ->join('pelanggan', 'pelanggan.id = transaksi.pelanggan_id', 'left')
            ->where('DATE(tanggal) >=', $tanggal_awal)
            ->where('DATE(tanggal) <=', $tanggal_akhir)
            ->where('transaksi.status !=', 'batal')
            ->orderBy('transaksi.tanggal', 'ASC')
            ->findAll();

        // Lengkapi dengan archive -- sama seperti getData(), supaya
        // export Excel/CSV tidak "bolong" untuk periode yang sudah
        // di-archive. Lihat komentar lengkap di getData().
        $transaksiArchive = [];
        try {
            $archiveService = new \App\Services\TransaksiArchiveService();
            $awalDt = $tanggal_awal . ' 00:00:00';
            $akhirDt = $tanggal_akhir . ' 23:59:59';
            $transaksiArchive = $archiveService->getTransaksiMentah($awalDt, $akhirDt);

            if (!empty($transaksiArchive)) {
                $transaksi = array_merge($transaksi, $transaksiArchive);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Laporan exportExcel() gagal baca archive: ' . $e->getMessage());
        }

        if (empty($transaksi)) {
            return redirect()->back()->with('error', 'Tidak ada data untuk diexport.');
        }

        $transaksiIds = array_column($transaksi, 'id');
        $details = $detailModel->whereIn('transaksi_id', $transaksiIds)->findAll();

        if (!empty($transaksiArchive)) {
            try {
                $idArchive = array_column($transaksiArchive, 'id');
                $details = array_merge($details, $archiveService->getDetailTransaksiMentah($idArchive));
            } catch (\Throwable $e) {
                log_message('error', 'Laporan exportExcel() gagal baca detail archive: ' . $e->getMessage());
            }
        }

        $detailGroup = [];
        foreach ($details as $d) {
            $detailGroup[$d['transaksi_id']][] = $d;
        }

        // 🔥 Proses data
        $result = $this->processData($jenis, $transaksi, $detailGroup, $kategori_id);
        $data = $result['data'];
        $summary = $result['summary'];

        // 🔥 Buat file CSV
        $filename = 'Laporan_' . date('Y-m-d') . '.csv';

        // 🔥 Set header untuk download CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        // 🔥 Buka output
        $output = fopen('php://output', 'w');

        // 🔥 Tambahkan BOM untuk UTF-8 (agar Excel bisa membaca)
        fputs($output, "\xEF\xBB\xBF");

        // 🔥 Judul
        $judul = [
            'harian' => 'Laporan Harian',
            'bulanan' => 'Laporan Bulanan',
            'periode' => 'Laporan Periode',
            'kategori' => 'Laporan per Kategori'
        ][$jenis] ?? 'Laporan';

        fputcsv($output, [$judul]);
        fputcsv($output, ['Periode: ' . date('d/m/Y', strtotime($tanggal_awal)) . ' - ' . date('d/m/Y', strtotime($tanggal_akhir))]);
        fputcsv($output, []);

        // 🔥 Summary (ringkasan)
        fputcsv($output, ['SUMMARY']);
        fputcsv($output, ['Total Transaksi', $summary['total_transaksi']]);
        fputcsv($output, ['Total Pendapatan Kotor', $this->formatAngka($summary['total_kotor'])]);
        fputcsv($output, ['Total Diskon', $this->formatAngka($summary['total_diskon'])]);
        fputcsv($output, ['Total Pendapatan Bersih', $this->formatAngka($summary['total_bersih'])]);
        fputcsv($output, ['Total Piutang', $this->formatAngka($summary['total_piutang'])]);
        fputcsv($output, []);

        // 🔥 Header tabel
        $headers = $this->getHeaders($jenis);
        fputcsv($output, $headers);

        // 🔥 Data
        foreach ($data as $item) {
            $rowData = $this->getRowData($jenis, $item);
            fputcsv($output, $rowData);
        }

        // 🔥 Tutup output
        fclose($output);
        exit();
    }
    /**
     * Get headers berdasarkan jenis laporan
     */
    private function getHeaders($jenis)
    {
        $headers = [
            'harian' => ['Tanggal', 'Invoice', 'No Order', 'Pelanggan', 'Subtotal', 'Diskon', 'Grand Total', 'Sisa Tagihan', 'Status'],
            'periode' => ['Tanggal', 'Invoice', 'No Order', 'Pelanggan', 'Subtotal', 'Diskon', 'Grand Total', 'Sisa Tagihan', 'Status'],
            'kategori' => ['Kategori', 'Total Kotor', 'Total Diskon', 'Total Bersih', 'Total Transaksi']
            // 'bulanan' sengaja tidak ada di sini -- exportExcel() sudah
            // menangani bulanan lewat jalur terpisah (lihat method
            // exportExcel(), early-return sebelum method ini dipanggil).
        ];

        return $headers[$jenis] ?? $headers['harian'];
    }
    /**
     * Get row data berdasarkan jenis laporan
     */
    private function getRowData($jenis, $item)
    {
        switch ($jenis) {
            case 'harian':
            case 'periode':
                return [
                    $item['tanggal'] ?? '',
                    $item['invoice'] ?? '',
                    $item['no_order'] ?? '',
                    $item['pelanggan'] ?? '',
                    $item['subtotal'] ?? 0,
                    $item['diskon'] ?? 0,
                    $item['grand_total'] ?? 0,
                    $item['sisa_tagihan'] ?? 0,
                    $item['status_pembayaran'] ?? ''
                ];

            // 'bulanan' sengaja tidak ada di sini -- exportExcel() sudah
            // menangani bulanan lewat jalur terpisah.
            case 'kategori':
                return [
                    $item['kategori_nama'] ?? '',
                    $item['total_kotor'] ?? 0,
                    $item['total_diskon'] ?? 0,
                    $item['total_bersih'] ?? 0,
                    $item['total_transaksi'] ?? 0
                ];

            default:
                return [];
        }
    }
    /**
     * Format angka ke format Indonesia (contoh: 352.000,00)
     * TANPA Rp
     */
    private function formatAngka($angka)
    {
        // 🔥 Set locale dari sistem
        setlocale(LC_ALL, '');

        // 🔥 Ambil format desimal dan ribuan dari locale
        $localeInfo = localeconv();
        $thousands_sep = $localeInfo['thousands_sep'] ?? '.';
        $dec_point = $localeInfo['dec_point'] ?? ',';

        return number_format($angka, 0, $dec_point, $thousands_sep);
    }
    public function itemHarian()
    {
        $db = db_connect();

        // =========================================================
        // FILTER
        // =========================================================

        $tanggalMulai = trim(
            (string) (
                $this->request->getGet('tanggal_mulai')
                ?? date('Y-m-01')
            )
        );

        $tanggalSampai = trim(
            (string) (
                $this->request->getGet('tanggal_sampai')
                ?? date('Y-m-d')
            )
        );

        $kategoriId = trim(
            (string) (
                $this->request->getGet('kategori_id')
                ?? ''
            )
        );

        $keyword = trim(
            (string) (
                $this->request->getGet('keyword')
                ?? ''
            )
        );

        $metode = trim(
            (string) (
                $this->request->getGet('metode')
                ?? ''
            )
        );

        if (
            !preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $tanggalMulai
            )
        ) {
            $tanggalMulai = date('Y-m-01');
        }

        if (
            !preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $tanggalSampai
            )
        ) {
            $tanggalSampai = date('Y-m-d');
        }

        if ($tanggalMulai > $tanggalSampai) {
            [$tanggalMulai, $tanggalSampai] = [
                $tanggalSampai,
                $tanggalMulai
            ];
        }


        // =========================================================
        // MASTER KATEGORI
        // =========================================================

        $kategoriRows = $db
            ->table('kategori')
            ->select('id, nama')
            ->orderBy('nama', 'ASC')
            ->get()
            ->getResultArray();


        // =========================================================
        // QUERY DARI DATABASE VIEW
        // =========================================================

        $builder = $db
            ->table('v_pembayaran_item_harian')
            ->select('
        tanggal_pembayaran,
        transaksi_id,
        kode_invoice,
        kategori_id,
        nama_produk,
        SUM(jumlah) AS jumlah_item,
        SUM(subtotal_item) AS subtotal_item,
        SUM(jumlah_pembayaran) AS total_pembayaran,
        SUM(nilai_teralokasi) AS total_teralokasi
    ')
            ->where(
                'tanggal_pembayaran >=',
                $tanggalMulai
            )
            ->where(
                'tanggal_pembayaran <=',
                $tanggalSampai
            );


        // =========================================================
        // FILTER KATEGORI
        // =========================================================

        if ($kategoriId !== '') {

            $builder->where(
                'kategori_id',
                (int) $kategoriId
            );
        }


        // =========================================================
        // FILTER NAMA PRODUK
        // =========================================================

        if ($keyword !== '') {

            $builder->like(
                'nama_produk',
                $keyword
            );
        }


        // =========================================================
        // FILTER METODE
        // =========================================================

        if ($metode !== '') {
            $builder->where(
                'metode',
                strtolower($metode)
            );
        }
        // =========================================================
        // GROUPING
        // =========================================================

        $rows = $builder
            ->groupBy([
                'tanggal_pembayaran',
                'transaksi_id',
                'kode_invoice',
                'kategori_id',
                'nama_produk'
            ])
            ->orderBy(
                'tanggal_pembayaran',
                'ASC'
            )
            ->orderBy(
                'total_teralokasi',
                'DESC'
            )
            ->get()
            ->getResultArray();

        // =========================================================
        // LENGKAPI DENGAN ARCHIVE
        // =========================================================
        // VIEW live tidak menjangkau data yang sudah di-archive. Query
        // & agregasi (GROUP BY/SUM) yang SAMA PERSIS dijalankan di
        // SQLite archive (lihat TransaksiArchiveService::getItemHarianMentah()),
        // hasilnya sudah teragregasi jadi tinggal digabung (bukan
        // dijumlah ulang -- satu transaksi cuma ada di satu sumber).
        try {
            $archiveService = new \App\Services\TransaksiArchiveService();
            $rowsArchive = $archiveService->getItemHarianMentah(
                $tanggalMulai,
                $tanggalSampai,
                $kategoriId !== '' ? (int) $kategoriId : null,
                $keyword !== '' ? $keyword : null,
                $metode !== '' ? $metode : null
            );

            if (!empty($rowsArchive)) {
                $rows = array_merge($rows, $rowsArchive);

                // Urutkan ulang gabungannya (masing-masing sumber sudah
                // terurut sendiri, tapi gabungan keduanya belum tentu).
                usort($rows, function ($a, $b) {
                    $cmpTanggal = strcmp($a['tanggal_pembayaran'], $b['tanggal_pembayaran']);
                    if ($cmpTanggal !== 0) return $cmpTanggal;
                    return $b['total_teralokasi'] <=> $a['total_teralokasi'];
                });
            }
        } catch (\Throwable $e) {
            log_message('error', 'Laporan itemHarian() gagal baca archive: ' . $e->getMessage());
        }


        // =========================================================
        // MAP KATEGORI
        // =========================================================

        $kategoriMap = [];

        foreach ($kategoriRows as $kategori) {

            $kategoriMap[(int) $kategori['id']] = $kategori['nama'];
        }


        // =========================================================
        // NORMALISASI HASIL
        // =========================================================

        foreach ($rows as &$row) {

            $row['kategori_nama'] =
                $kategoriMap[(int) $row['kategori_id']] ?? 'Tanpa Kategori';

            $row['jumlah_item'] =
                (float) (
                    $row['jumlah_item']
                    ?? 0
                );

            $row['subtotal_item'] =
                (float) (
                    $row['subtotal_item']
                    ?? 0
                );

            $row['total_pembayaran'] =
                (float) (
                    $row['total_pembayaran']
                    ?? 0
                );

            $row['total_teralokasi'] =
                (float) (
                    $row['total_teralokasi']
                    ?? 0
                );
        }

        unset($row);


        // =========================================================
        // SUMMARY
        // =========================================================

        $jumlahBaris = count($rows);

        $totalSubtotal = 0;
        $totalTeralokasi = 0;

        foreach ($rows as $row) {

            $totalSubtotal +=
                $row['subtotal_item'];

            $totalTeralokasi +=
                $row['total_teralokasi'];
        }


        // =========================================================
        // RETURN
        // =========================================================

        return view(
            'layout/main',
            [

                'title' =>
                'Item Harian',

                'content' =>
                'laporan/item_harian',

                'tanggal_mulai' =>
                $tanggalMulai,

                'tanggal_sampai' =>
                $tanggalSampai,

                'kategoriRows' =>
                $kategoriRows,

                'kategoriId' =>
                $kategoriId,

                'keyword' =>
                $keyword,

                'metode' =>
                $metode,

                'rows' =>
                $rows,

                'jumlahBaris' =>
                $jumlahBaris,

                'totalSubtotal' =>
                $totalSubtotal,

                'totalTeralokasi' =>
                $totalTeralokasi,
            ]
        );
    }
    public function pembayaran()
{
    $db = db_connect();

    // =========================================================
    // FILTER
    // =========================================================

    $tanggalMulai = trim((string) (
        $this->request->getGet('tanggal_mulai')
        ?? date('Y-m-d')   // <-- diubah dari 'Y-m-01' ke 'Y-m-d'
    ));

    $tanggalSampai = trim((string) (
        $this->request->getGet('tanggal_sampai')
        ?? date('Y-m-d')
    ));

    $metode = trim((string) (
        $this->request->getGet('metode')
        ?? ''
    ));

    $keyword = trim((string) (
        $this->request->getGet('keyword')
        ?? ''
    ));

    if (!preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $tanggalMulai
    )) {
        $tanggalMulai = date('Y-m-d');   // <-- diubah dari 'Y-m-01'
    }

    if (!preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $tanggalSampai
    )) {
        $tanggalSampai = date('Y-m-d');
    }

    if ($tanggalMulai > $tanggalSampai) {
        [$tanggalMulai, $tanggalSampai] = [
            $tanggalSampai,
            $tanggalMulai
        ];
    }

    // =========================================================
    // QUERY DATABASE VIEW
    // =========================================================

    $builder = $db
        ->table('v_daftar_pembayaran')
        ->select('
            pembayaran_id,
            transaksi_id,
            kode_invoice,
            tanggal_transaksi,
            tanggal_pembayaran,
            nama_pelanggan,
            nama_kasir,
            username_kasir,
            inisial_kasir,
            metode,
            jumlah,
            uang_diterima,
            kembalian,
            keterangan,
            pelanggan_id,
            kasir_id
        ')
        ->where(
            'tanggal_pembayaran >=',
            $tanggalMulai . ' 00:00:00'
        )
        ->where(
            'tanggal_pembayaran <=',
            $tanggalSampai . ' 23:59:59'
        );

    if ($metode !== '') {
        $builder->where(
            'metode',
            strtolower($metode)
        );
    }

    if ($keyword !== '') {
        $builder->groupStart()
            ->like('kode_invoice', $keyword)
            ->orLike('nama_pelanggan', $keyword)
            ->orLike('nama_kasir', $keyword)
            ->orLike('keterangan', $keyword)
            ->groupEnd();
    }

    $rows = $builder
        ->orderBy('tanggal_pembayaran', 'DESC')   // <-- diubah dari ASC
        ->orderBy('pembayaran_id', 'DESC')        // <-- diubah dari ASC
        ->get()
        ->getResultArray();

    // =========================================================
    // LENGKAPI DENGAN ARCHIVE
    // =========================================================
    // Sama pola dengan itemHarian(): VIEW live tidak menjangkau data
    // yang sudah di-archive, jadi archive di-query terpisah dengan
    // filter yang sama (metode/keyword) lalu digabung & diurutkan
    // ulang di sini.
    try {
        $archiveService = new \App\Services\TransaksiArchiveService();
        $rowsArchive = $archiveService->getDaftarPembayaranMentah(
            $tanggalMulai . ' 00:00:00',
            $tanggalSampai . ' 23:59:59',
            $metode !== '' ? $metode : null,
            $keyword !== '' ? $keyword : null
        );

        if (!empty($rowsArchive)) {
            $rows = array_merge($rows, $rowsArchive);

            usort($rows, function ($a, $b) {
                $cmpTanggal = strcmp($b['tanggal_pembayaran'], $a['tanggal_pembayaran']);
                if ($cmpTanggal !== 0) return $cmpTanggal;
                return $b['pembayaran_id'] <=> $a['pembayaran_id'];
            });
        }
    } catch (\Throwable $e) {
        log_message('error', 'Laporan pembayaran() gagal baca archive: ' . $e->getMessage());
    }

    // =========================================================
    // SUMMARY
    // =========================================================

    $totalPembayaran = 0;
    $totalTunai = 0;
    $totalQris = 0;
    $totalTransfer = 0;

    foreach ($rows as $row) {

        $jumlah = (float) (
            $row['jumlah'] ?? 0
        );

        $metodeRow = strtolower(
            trim((string) (
                $row['metode'] ?? ''
            ))
        );

        $totalPembayaran += $jumlah;

        if ($metodeRow === 'tunai') {
            $totalTunai += $jumlah;
        } elseif ($metodeRow === 'qris') {
            $totalQris += $jumlah;
        } elseif ($metodeRow === 'transfer') {
            $totalTransfer += $jumlah;
        }
    }

    $jumlahBaris = count($rows);

    return view('layout/main', [
        'title' => 'Laporan Pembayaran',
        'content' => 'transaksi/laporan_pembayaran',

        'tanggal_mulai' => $tanggalMulai,
        'tanggal_sampai' => $tanggalSampai,

        'metode' => $metode,
        'keyword' => $keyword,

        'rows' => $rows,

        'jumlahBaris' => $jumlahBaris,
        'totalPembayaran' => $totalPembayaran,
        'totalTunai' => $totalTunai,
        'totalQris' => $totalQris,
        'totalTransfer' => $totalTransfer,
    ]);
}
}
