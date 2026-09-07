<?php

namespace App\Controllers;

class LaporanTest extends BaseController
{
    public function penjualanUmum()
    {
        $db = db_connect();

        // =========================================================
        // FILTER TANGGAL
        // =========================================================

        $tanggal = trim(
            (string) (
                $this->request->getGet('tanggal')
                ?? date('Y-m-d')
            )
        );

        if (
            !preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $tanggal
            )
        ) {
            $tanggal = date('Y-m-d');
        }



        // =========================================================
        // FILTER KATEGORI
        // =========================================================
        //
        // Default = kategori 1
        //
        // =========================================================

        $kategoriId = (int) (
            $this->request->getGet('kategori_id')
            ?? 1
        );


        // =========================================================
        // MASTER KATEGORI
        // =========================================================

        $kategoriRows = $db
            ->table('kategori')
            ->select('id, nama')
            ->orderBy('nama', 'ASC')
            ->get()
            ->getResultArray();


        $kategoriNama = 'Semua Kategori';

        foreach ($kategoriRows as $kategori) {

            if (
                (int) $kategori['id']
                === $kategoriId
            ) {

                $kategoriNama =
                    $kategori['nama'];

                break;
            }
        }


        // =========================================================
        // PEMBAYARAN PADA TANGGAL LAPORAN
        // =========================================================
        //
        // Tanggal laporan mengikuti:
        // pembayaran.tanggal
        //
        // BUKAN tanggal transaksi.
        //
        // =========================================================

        $pembayaranRows = $db
            ->table('pembayaran p')
            ->select('
                p.id,
                p.transaksi_id,
                p.tanggal,
                p.jumlah,
                p.metode
            ')
            ->where('p.status', 'aktif')
            ->where(
                'DATE(p.tanggal)',
                $tanggal
            )
            ->orderBy(
                'p.transaksi_id',
                'ASC'
            )
            ->orderBy(
                'p.tanggal',
                'ASC'
            )
            ->get()
            ->getResultArray();


        // =========================================================
        // KELOMPOKKAN PEMBAYARAN PER TRANSAKSI
        // =========================================================

        $pembayaranPerTransaksi = [];

        foreach ($pembayaranRows as $row) {

            $transaksiId =
                (int) (
                    $row['transaksi_id']
                    ?? 0
                );

            if (!$transaksiId) {
                continue;
            }

            if (
                !isset(
                    $pembayaranPerTransaksi[$transaksiId]
                )
            ) {

                $pembayaranPerTransaksi[$transaksiId] = 0;
            }

            $pembayaranPerTransaksi[$transaksiId] += (float) (
                $row['jumlah']
                ?? 0
            );
        }


        // =========================================================
        // ARRAY HASIL
        // =========================================================

        $rows = [];

        $totalLaporan = 0;


        // =========================================================
        // KALAU TIDAK ADA PEMBAYARAN
        // =========================================================

        if (
            !empty($pembayaranPerTransaksi)
        ) {

            $transaksiIds =
                array_keys(
                    $pembayaranPerTransaksi
                );


            // =====================================================
            // DETAIL SEMUA TRANSAKSI
            // =====================================================
            //
            // PENTING:
            //
            // Semua detail diambil.
            //
            // BUKAN hanya kategori yang dipilih.
            //
            // Karena persentase harus dihitung terhadap
            // seluruh detail transaksi.
            //
            // =====================================================

            $detailRows = $db
                ->table('detail_transaksi d')
                ->select('
                    d.id,
                    d.transaksi_id,
                    d.produk_id,
                    d.nama_produk,
                    d.kategori_id,
                    d.jumlah,
                    d.harga_satuan,
                    d.subtotal
                ')
                ->whereIn(
                    'd.transaksi_id',
                    $transaksiIds
                )
                ->orderBy(
                    'd.transaksi_id',
                    'ASC'
                )
                ->orderBy(
                    'd.id',
                    'ASC'
                )
                ->get()
                ->getResultArray();


            // =====================================================
            // HEADER TRANSAKSI
            // =====================================================

            $transaksiRows = $db
                ->table('transaksi t')
                ->select('
                    t.id,
                    t.kode_invoice,
                    t.tanggal,
                    t.subtotal,
                    t.diskon,
                    t.grand_total,
                    t.status,
                    t.status_pembayaran
                ')
                ->whereIn(
                    't.id',
                    $transaksiIds
                )
                ->get()
                ->getResultArray();


            // =====================================================
            // MAP TRANSAKSI
            // =====================================================

            $transaksiMap = [];

            foreach ($transaksiRows as $transaksi) {

                $transaksiMap[(int) $transaksi['id']] = $transaksi;
            }


            // =====================================================
            // GROUP DETAIL PER TRANSAKSI
            // =====================================================

            $detailPerTransaksi = [];

            foreach ($detailRows as $detail) {

                $transaksiId =
                    (int) (
                        $detail['transaksi_id']
                        ?? 0
                    );

                if (!$transaksiId) {
                    continue;
                }

                if (
                    !isset(
                        $detailPerTransaksi[$transaksiId]
                    )
                ) {

                    $detailPerTransaksi[$transaksiId] = [];
                }

                $detailPerTransaksi[$transaksiId][] = $detail;
            }


            // =====================================================
            // PROSES SETIAP TRANSAKSI
            // =====================================================

            foreach (
                $pembayaranPerTransaksi
                as $transaksiId =>
                $pembayaranHariIni
            ) {

                $details =
                    $detailPerTransaksi[$transaksiId]
                    ?? [];


                if (
                    empty($details)
                ) {
                    continue;
                }


                // =================================================
                // TOTAL SUBTOTAL SELURUH DETAIL
                // =================================================
                //
                // INI BASIS PERHITUNGAN PERSENTASE.
                //
                // =================================================

                $totalSubtotalDetail = 0;


                foreach ($details as $detail) {

                    $totalSubtotalDetail +=
                        (float) (
                            $detail['subtotal']
                            ?? 0
                        );
                }


                if (
                    $totalSubtotalDetail <= 0
                ) {
                    continue;
                }


                // =================================================
                // DATA HEADER
                // =================================================

                $transaksi =
                    $transaksiMap[$transaksiId]
                    ?? [];


                // =================================================
                // HITUNG SETIAP DETAIL
                // =================================================

                foreach ($details as $detail) {

                    $subtotalDetail =
                        (float) (
                            $detail['subtotal']
                            ?? 0
                        );


                    if (
                        $subtotalDetail <= 0
                    ) {
                        continue;
                    }


                    // =============================================
                    // PERSENTASE DETAIL
                    // =============================================

                    $persentase =
                        $subtotalDetail
                        /
                        $totalSubtotalDetail;


                    // =============================================
                    // ALOKASI PEMBAYARAN
                    // =============================================
                    //
                    // Pembayaran aktual pada tanggal ini
                    // dibebankan secara proporsional.
                    //
                    // =============================================

                    $nilaiDibayarDetail =
                        $pembayaranHariIni
                        *
                        $persentase;


                    // =============================================
                    // KATEGORI DETAIL
                    // =============================================

                    $kategoriIdDetail =
                        (int) (
                            $detail['kategori_id']
                            ?? 0
                        );


                    // =============================================
                    // APAKAH MASUK KATEGORI YANG DIPILIH?
                    // =============================================

                    $masukLaporan =
                        (
                            $kategoriIdDetail
                            === $kategoriId
                        );


                    // =============================================
                    // TOTAL LAPORAN
                    // =============================================

                    if (
                        $masukLaporan
                    ) {

                        $totalLaporan +=
                            $nilaiDibayarDetail;
                    }


                    // =============================================
                    // SIMPAN DATA DETAIL
                    // =============================================

                    $rows[] = [

                        'transaksi_id' =>
                        $transaksiId,

                        'invoice' =>
                        $transaksi['kode_invoice']
                            ?? '-',

                        'tanggal_transaksi' =>
                        $transaksi['tanggal']
                            ?? null,

                        'status' =>
                        $transaksi['status']
                            ?? null,

                        'status_pembayaran' =>
                        $transaksi['status_pembayaran']
                            ?? null,

                        'kategori_id' =>
                        $kategoriIdDetail,

                        'produk' =>
                        $detail['nama_produk']
                            ?? '-',

                        'jumlah' =>
                        (float) (
                            $detail['jumlah']
                            ?? 0
                        ),

                        'harga_satuan' =>
                        (float) (
                            $detail['harga_satuan']
                            ?? 0
                        ),

                        'subtotal_detail' =>
                        $subtotalDetail,

                        'total_subtotal_transaksi' =>
                        $totalSubtotalDetail,

                        'persentase' =>
                        $persentase
                            * 100,

                        'pembayaran_hari_ini' =>
                        $pembayaranHariIni,

                        'nilai_dibayar_detail' =>
                        $nilaiDibayarDetail,

                        'masuk_laporan' =>
                        $masukLaporan,
                    ];
                }
            }
        }


        // =========================================================
        // RETURN VIEW
        // =========================================================

        return view('layout/main', [
            'title'        => 'Test Laporan Penjualan',
            'content'      => 'laporan/test_penjualan_umum',

            'tanggal'      => $tanggal,

            'kategoriId'   => $kategoriId,
            'kategoriRows' => $kategoriRows,
            'kategoriNama' => $kategoriNama,

            'rows'         => $rows,
            'total'        => $totalLaporan,
        ]);
    }
    public function getNonTunaiPerTanggal($tanggalMulai, $tanggalSampai)
    {
        $db = db_connect();

        $rows = $db
            ->table('pembayaran')
            ->select("
            DATE(tanggal) AS tanggal,

            COALESCE(SUM(
                CASE
                    WHEN LOWER(TRIM(metode)) = 'qris'
                    THEN jumlah
                    ELSE 0
                END
            ), 0) AS qris,

            COALESCE(SUM(
                CASE
                    WHEN LOWER(TRIM(metode)) = 'transfer'
                    THEN jumlah
                    ELSE 0
                END
            ), 0) AS transfer,

            COALESCE(SUM(
                CASE
                    WHEN LOWER(TRIM(metode)) IN ('qris', 'transfer')
                    THEN jumlah
                    ELSE 0
                END
            ), 0) AS total_non_tunai
        ", false)
            ->where('status', 'aktif')
            ->where(
                'tanggal >=',
                $tanggalMulai . ' 00:00:00'
            )
            ->where(
                'tanggal <=',
                $tanggalSampai . ' 23:59:59'
            )
            ->groupBy('DATE(tanggal)')
            ->orderBy('tanggal', 'ASC')
            ->get()
            ->getResultArray();

        $hasil = [];

        foreach ($rows as $row) {

            $tanggal = $row['tanggal'];

            $hasil[$tanggal] = [
                'qris' => (float) (
                    $row['qris'] ?? 0
                ),

                'transfer' => (float) (
                    $row['transfer'] ?? 0
                ),

                'total_non_tunai' => (float) (
                    $row['total_non_tunai'] ?? 0
                ),
            ];
        }

        return $hasil;
    }
    public function gantiedit()
    {
        $db = db_connect();

        // =========================================================
        // FILTER TANGGAL TRANSAKSI
        // =========================================================
        $tanggalMulai = trim((string) ($this->request->getGet('tanggal_mulai') ?? date('Y-m-01')));
        $tanggalSampai = trim((string) ($this->request->getGet('tanggal_sampai') ?? date('Y-m-d')));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalMulai)) {
            $tanggalMulai = date('Y-m-01');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalSampai)) {
            $tanggalSampai = date('Y-m-d');
        }

        if ($tanggalMulai > $tanggalSampai) {
            [$tanggalMulai, $tanggalSampai] = [$tanggalSampai, $tanggalMulai];
        }

        // =========================================================
        // 1. DETAIL GANTI / EDIT
        // =========================================================
        // Filter berdasarkan NAMA PRODUK, sesuai query manual Anda.
        $detailRows = $db
            ->table('detail_transaksi dt')
            ->select(
                ''
                    . 'dt.id, '
                    . 'dt.transaksi_id, '
                    . 'dt.produk_id, '
                    . 'dt.nama_produk, '
                    . 'dt.kategori_id, '
                    . 'dt.jumlah, '
                    . 'dt.harga_satuan, '
                    . 'dt.subtotal, '
                    . 'dt.catatan, '
                    . 't.kode_invoice, '
                    . 't.tanggal AS tanggal_transaksi, '
                    . 't.subtotal AS subtotal_transaksi, '
                    . 't.diskon, '
                    . 't.grand_total, '
                    . 't.status, '
                    . 't.status_pembayaran'
            )
            ->join('transaksi t', 't.id = dt.transaksi_id', 'inner')
            ->where("(LOWER(dt.nama_produk) LIKE '%ganti%' OR LOWER(dt.nama_produk) LIKE '%edit%')", null, false)
            ->where('t.tanggal >=', $tanggalMulai . ' 00:00:00')
            ->where('t.tanggal <=', $tanggalSampai . ' 23:59:59')
            ->orderBy('t.tanggal', 'ASC')
            ->orderBy('dt.id', 'ASC')
            ->get()
            ->getResultArray();

        // =========================================================
        // 2. SEMUA DETAIL UNTUK TRANSAKSI YANG DITEMUKAN
        //    Dipakai untuk menghitung proporsi pembayaran.
        // =========================================================
        $transaksiIds = [];

        foreach ($detailRows as $row) {
            $transaksiIds[(int) $row['transaksi_id']] = true;
        }

        $allDetailsPerTransaksi = [];

        if (!empty($transaksiIds)) {
            $allDetails = $db
                ->table('detail_transaksi')
                ->select('id, transaksi_id, nama_produk, kategori_id, jumlah, harga_satuan, subtotal, catatan')
                ->whereIn('transaksi_id', array_keys($transaksiIds))
                ->orderBy('transaksi_id', 'ASC')
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();

            foreach ($allDetails as $detail) {
                $tid = (int) $detail['transaksi_id'];
                $allDetailsPerTransaksi[$tid][] = $detail;
            }
        }

        // =========================================================
        // 3. SEMUA PEMBAYARAN DARI TRANSAKSI TERSEBUT
        // =========================================================
        $paymentsPerTransaksi = [];

        if (!empty($transaksiIds)) {
            $paymentRows = $db
                ->table('pembayaran')
                ->select('id, transaksi_id, tanggal, jumlah, metode, uang_diterima, kembalian, keterangan')
                ->where('status', 'aktif')
                ->whereIn('transaksi_id', array_keys($transaksiIds))
                ->orderBy('tanggal', 'ASC')
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();

            foreach ($paymentRows as $payment) {
                $tid = (int) $payment['transaksi_id'];
                $paymentsPerTransaksi[$tid][] = $payment;
            }
        }

        // =========================================================
        // 4. BENTUK DATA UNTUK TABEL
        // =========================================================
        $rows = [];

        foreach ($detailRows as $detail) {
            $tid = (int) $detail['transaksi_id'];

            $allDetails = $allDetailsPerTransaksi[$tid] ?? [];
            $totalSubtotalSemuaDetail = 0;

            foreach ($allDetails as $allDetail) {
                $totalSubtotalSemuaDetail += (float) ($allDetail['subtotal'] ?? 0);
            }

            $subtotalDetail = (float) ($detail['subtotal'] ?? 0);
            $proporsi = $totalSubtotalSemuaDetail > 0
                ? ($subtotalDetail / $totalSubtotalSemuaDetail)
                : 0;

            $tanggalTransaksi = date('Y-m-d', strtotime($detail['tanggal_transaksi']));
            $grandTotal = (float) ($detail['grand_total'] ?? 0);

            $payments = $paymentsPerTransaksi[$tid] ?? [];

            $totalDibayar = 0;
            $dibayarHariTransaksi = 0;
            $pembayaranPertama = null;
            $pembayaranTerakhir = null;
            $paymentDisplay = [];

            foreach ($payments as $payment) {
                $jumlah = (float) ($payment['jumlah'] ?? 0);
                $totalDibayar += $jumlah;

                $tanggalBayar = date('Y-m-d', strtotime($payment['tanggal']));

                if ($pembayaranPertama === null) {
                    $pembayaranPertama = $payment['tanggal'];
                }

                $pembayaranTerakhir = $payment['tanggal'];

                if ($tanggalBayar === $tanggalTransaksi) {
                    $dibayarHariTransaksi += $jumlah;
                }

                $alokasiKeDetail = $jumlah * $proporsi;

                $paymentDisplay[] = [
                    'tanggal' => $payment['tanggal'],
                    'jumlah' => $jumlah,
                    'metode' => $payment['metode'] ?? '',
                    'uang_diterima' => $payment['uang_diterima'] ?? null,
                    'kembalian' => $payment['kembalian'] ?? null,
                    'keterangan' => $payment['keterangan'] ?? '',
                    'alokasi_ke_detail' => $alokasiKeDetail,
                ];
            }

            // =====================================================
            // STATUS PEMBAYARAN
            // =====================================================
            if ($totalDibayar <= 0) {
                $statusCek = 'BELUM BAYAR';
            } elseif ($dibayarHariTransaksi > 0) {
                $statusCek = ($grandTotal > 0 && $dibayarHariTransaksi >= $grandTotal)
                    ? 'DIBAYAR HARI YANG SAMA'
                    : 'DP HARI YANG SAMA';
            } else {
                $statusCek = 'DIBAYAR BELAKANGAN';
            }

            $rows[] = [
                'detail_id' => (int) $detail['id'],
                'transaksi_id' => $tid,
                'invoice' => $detail['kode_invoice'] ?? '-',
                'tanggal_transaksi' => $detail['tanggal_transaksi'],
                'nama_produk' => $detail['nama_produk'] ?? '-',
                'catatan' => $detail['catatan'] ?? '',
                'jumlah' => (float) ($detail['jumlah'] ?? 0),
                'harga_satuan' => (float) ($detail['harga_satuan'] ?? 0),
                'subtotal_detail' => $subtotalDetail,
                'subtotal_transaksi' => $totalSubtotalSemuaDetail,
                'proporsi' => $proporsi * 100,
                'diskon' => (float) ($detail['diskon'] ?? 0),
                'grand_total' => $grandTotal,
                'total_dibayar' => $totalDibayar,
                'dibayar_hari_transaksi' => $dibayarHariTransaksi,
                'pembayaran_pertama' => $pembayaranPertama,
                'pembayaran_terakhir' => $pembayaranTerakhir,
                'status_cek' => $statusCek,
                'payments' => $paymentDisplay,
            ];
        }

        // =========================================================
        // RINGKASAN
        // =========================================================
        $jumlahDetail = count($rows);
        $jumlahBelumBayar = 0;
        $jumlahBayarHariSama = 0;
        $jumlahDpHariSama = 0;
        $jumlahBayarBelakangan = 0;

        // Nominal Ganti/Edit berdasarkan subtotal item untuk setiap status.
        $nominalBelumBayar = 0;
        $nominalBayarHariSama = 0;
        $nominalDpHariSama = 0;
        $nominalBayarBelakangan = 0;
        $nominalTotalGantiEdit = 0;

        foreach ($rows as $row) {
            $nominalTotalGantiEdit += (float) (
                $row['subtotal_detail'] ?? 0
            );
        }

        foreach ($rows as $row) {
            $nominal = (float) ($row['subtotal_detail'] ?? 0);

            switch ($row['status_cek']) {
                case 'BELUM BAYAR':
                    $jumlahBelumBayar++;
                    $nominalBelumBayar += $nominal;
                    break;
                case 'DIBAYAR HARI YANG SAMA':
                    $jumlahBayarHariSama++;
                    $nominalBayarHariSama += $nominal;
                    break;
                case 'DP HARI YANG SAMA':
                    $jumlahDpHariSama++;
                    $nominalDpHariSama += $nominal;
                    break;
                case 'DIBAYAR BELAKANGAN':
                    $jumlahBayarBelakangan++;
                    $nominalBayarBelakangan += $nominal;
                    break;
            }
        }

        $data = [
            'title' => 'Cek Ganti / Edit',
            'content' => 'laporan/ganti_edit',
            'tanggal_mulai' => $tanggalMulai,
            'tanggal_sampai' => $tanggalSampai,
            'rows' => $rows,
            'jumlahDetail' => $jumlahDetail,
            'jumlahBelumBayar' => $jumlahBelumBayar,
            'jumlahBayarHariSama' => $jumlahBayarHariSama,
            'jumlahDpHariSama' => $jumlahDpHariSama,
            'jumlahBayarBelakangan' => $jumlahBayarBelakangan,
            'nominalTotalGantiEdit' => $nominalTotalGantiEdit,
            'nominalBelumBayar' => $nominalBelumBayar,
            'nominalBayarHariSama' => $nominalBayarHariSama,
            'nominalDpHariSama' => $nominalDpHariSama,
            'nominalBayarBelakangan' => $nominalBayarBelakangan,
        ];

        return view('layout/main', $data);
    }

    public function pemasukanHarian()
    {
        $db = db_connect();

        // =========================================================
        // FILTER RENTANG TANGGAL
        // =========================================================

        $tanggalMulai = trim((string) (
            $this->request->getGet('tanggal_mulai')
            ?? date('Y-m-01')
        ));

        $tanggalSampai = trim((string) (
            $this->request->getGet('tanggal_sampai')
            ?? date('Y-m-d')
        ));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalMulai)) {
            $tanggalMulai = date('Y-m-01');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalSampai)) {
            $tanggalSampai = date('Y-m-d');
        }

        if ($tanggalMulai > $tanggalSampai) {
            [$tanggalMulai, $tanggalSampai] = [$tanggalSampai, $tanggalMulai];
        }
        $nonTunaiPerTanggal =
            $this->getNonTunaiPerTanggal(
                $tanggalMulai,
                $tanggalSampai
            );
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
        // PEMBAYARAN PER TANGGAL
        // =========================================================

        $pembayaranRows = $db
            ->table('pembayaran')
            ->select('id, transaksi_id, tanggal, jumlah, metode')
            ->where('status', 'aktif')
            ->where('tanggal >=', $tanggalMulai . ' 00:00:00')
            ->where('tanggal <=', $tanggalSampai . ' 23:59:59')
            ->orderBy('tanggal', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        // pembayaranPerTanggal[tanggal][transaksi_id] = total pembayaran hari itu
        $pembayaranPerTanggal = [];
        $transaksiIds = [];

        foreach ($pembayaranRows as $payment) {
            $transaksiId = (int) ($payment['transaksi_id'] ?? 0);
            if (!$transaksiId) {
                continue;
            }

            $tanggalBayar = date('Y-m-d', strtotime($payment['tanggal']));
            $jumlah = (float) ($payment['jumlah'] ?? 0);

            $pembayaranPerTanggal[$tanggalBayar][$transaksiId] =
                ($pembayaranPerTanggal[$tanggalBayar][$transaksiId] ?? 0) + $jumlah;

            $transaksiIds[$transaksiId] = true;
        }

        // =========================================================
        // SEMUA DETAIL TRANSAKSI YANG MEMPUNYAI PEMBAYARAN
        // =========================================================

        $detailPerTransaksi = [];

        if (!empty($transaksiIds)) {
            $detailRows = $db
                ->table('detail_transaksi')
                ->select('id, transaksi_id, nama_produk, kategori_id, subtotal')
                ->whereIn('transaksi_id', array_keys($transaksiIds))
                ->orderBy('transaksi_id', 'ASC')
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();

            foreach ($detailRows as $detail) {
                $tid = (int) $detail['transaksi_id'];
                $detailPerTransaksi[$tid][] = $detail;
            }
        }

        // =========================================================
        // INISIALISASI HASIL PER HARI
        // =========================================================

        $hasil = [];

        foreach ($pembayaranPerTanggal as $tanggal => $paymentsHari) {
            $hasil[$tanggal] = [
                'total' => 0,
                'ganti_edit' => 0,
                'jumlah_transaksi' => count($paymentsHari),
                'jumlah_pembayaran' => 0,
            ];

            foreach ($kategoriRows as $kategori) {
                $hasil[$tanggal]['kategori_' . (int) $kategori['id']] = 0;
            }
        }

        // =========================================================
        // ALOKASI PEMBAYARAN KE DETAIL
        // =========================================================
        //
        // Pembayaran hari itu dialokasikan berdasarkan proporsi
        // subtotal tiap detail terhadap seluruh subtotal transaksi.
        //
        // Detail yang nama produknya mengandung "ganti" / "edit"
        // dipisahkan ke kolom Ganti/Edit agar tidak double count
        // dengan kategori induknya.
        // =========================================================

        foreach ($pembayaranPerTanggal as $tanggal => $paymentsHari) {

            foreach ($paymentsHari as $transaksiId => $pembayaranHariIni) {

                $details = $detailPerTransaksi[$transaksiId] ?? [];

                if (empty($details)) {
                    continue;
                }

                $totalSubtotal = 0;

                foreach ($details as $detail) {
                    $totalSubtotal += (float) ($detail['subtotal'] ?? 0);
                }

                if ($totalSubtotal <= 0) {
                    continue;
                }

                foreach ($details as $detail) {

                    $subtotalDetail = (float) ($detail['subtotal'] ?? 0);
                    if ($subtotalDetail <= 0) {
                        continue;
                    }

                    $proporsi = $subtotalDetail / $totalSubtotal;
                    $nilaiDibayar = $pembayaranHariIni * $proporsi;

                    $namaProduk = strtolower(trim((string) ($detail['nama_produk'] ?? '')));
                    $isGantiEdit =
                        strpos($namaProduk, 'ganti') !== false
                        || strpos($namaProduk, 'edit') !== false;

                    if ($isGantiEdit) {
                        $hasil[$tanggal]['ganti_edit'] += $nilaiDibayar;
                    } else {
                        $kategoriId = (int) ($detail['kategori_id'] ?? 0);
                        $key = 'kategori_' . $kategoriId;

                        if (!isset($hasil[$tanggal][$key])) {
                            $hasil[$tanggal][$key] = 0;
                        }

                        $hasil[$tanggal][$key] += $nilaiDibayar;
                    }

                    $hasil[$tanggal]['total'] += $nilaiDibayar;
                }

                $hasil[$tanggal]['jumlah_pembayaran']++;
            }
        }

        ksort($hasil);

        // =========================================================
        // TOTAL PERIODE
        // =========================================================

        $totalPeriode = [
            'total' => 0,
            'ganti_edit' => 0,
            'jumlah_transaksi' => 0,
            'jumlah_pembayaran' => 0,
        ];

        foreach ($kategoriRows as $kategori) {
            $totalPeriode['kategori_' . (int) $kategori['id']] = 0;
        }

        $transaksiUnikPeriode = [];

        foreach ($hasil as $tanggal => $dataHari) {
            $totalPeriode['total'] += $dataHari['total'];
            $totalPeriode['ganti_edit'] += $dataHari['ganti_edit'];
            $totalPeriode['jumlah_pembayaran'] += $dataHari['jumlah_pembayaran'];

            foreach ($kategoriRows as $kategori) {
                $key = 'kategori_' . (int) $kategori['id'];
                $totalPeriode[$key] += $dataHari[$key] ?? 0;
            }
        }

        // Jumlah transaksi unik pada periode berdasarkan seluruh pembayaran.
        foreach ($pembayaranRows as $payment) {
            $tid = (int) ($payment['transaksi_id'] ?? 0);
            if ($tid) {
                $transaksiUnikPeriode[$tid] = true;
            }
        }

        $totalPeriode['jumlah_transaksi'] = count($transaksiUnikPeriode);

        return view('layout/main', [
            'title' => 'Pemasukan Harian',
            'content' => 'laporan/pemasukan_harian',
            'tanggal_mulai' => $tanggalMulai,
            'tanggal_sampai' => $tanggalSampai,
            'kategoriRows' => $kategoriRows,
            'hasil' => $hasil,
            'totalPeriode' => $totalPeriode,
            'nonTunaiPerTanggal' => $nonTunaiPerTanggal,
        ]);
    }
    public function sumkategoriharian()
    {
        $db = db_connect();

        // =========================================================
        // FILTER TANGGAL
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

        // Kalau terbalik, tukar
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
        // PEMBAYARAN PADA RENTANG TANGGAL
        // =========================================================
        //
        // Yang dipakai adalah tanggal PEMBAYARAN,
        // bukan tanggal transaksi.
        //
        // =========================================================

        $pembayaranRows = $db
            ->table('pembayaran p')
            ->select('
                p.id,
                p.transaksi_id,
                p.tanggal,
                p.jumlah,
                p.metode
            ')
            ->where('p.status', 'aktif')
            ->where(
                'p.tanggal >=',
                $tanggalMulai . ' 00:00:00'
            )
            ->where(
                'p.tanggal <=',
                $tanggalSampai . ' 23:59:59'
            )
            ->orderBy(
                'p.tanggal',
                'ASC'
            )
            ->orderBy(
                'p.transaksi_id',
                'ASC'
            )
            ->get()
            ->getResultArray();


        // =========================================================
        // KELOMPOKKAN PEMBAYARAN:
        //
        // TANGGAL -> TRANSAKSI -> TOTAL BAYAR
        // =========================================================

        $pembayaranPerTanggal = [];

        $transaksiIds = [];

        foreach ($pembayaranRows as $payment) {

            $transaksiId =
                (int) (
                    $payment['transaksi_id']
                    ?? 0
                );

            if (!$transaksiId) {
                continue;
            }

            $tanggalPembayaran =
                date(
                    'Y-m-d',
                    strtotime(
                        $payment['tanggal']
                    )
                );

            $jumlah =
                (float) (
                    $payment['jumlah']
                    ?? 0
                );

            if (
                !isset(
                    $pembayaranPerTanggal[$tanggalPembayaran]
                )
            ) {

                $pembayaranPerTanggal[$tanggalPembayaran] = [];
            }

            if (
                !isset(
                    $pembayaranPerTanggal[$tanggalPembayaran][$transaksiId]
                )
            ) {

                $pembayaranPerTanggal[$tanggalPembayaran][$transaksiId] = 0;
            }

            $pembayaranPerTanggal[$tanggalPembayaran][$transaksiId] += $jumlah;

            $transaksiIds[$transaksiId] = true;
        }


        // =========================================================
        // SEMUA DETAIL TRANSAKSI
        // =========================================================

        $detailPerTransaksi = [];

        if (!empty($transaksiIds)) {

            $detailRows = $db
                ->table('detail_transaksi d')
                ->select('
                    d.id,
                    d.transaksi_id,
                    d.kategori_id,
                    d.nama_produk,
                    d.jumlah,
                    d.harga_satuan,
                    d.subtotal
                ')
                ->whereIn(
                    'd.transaksi_id',
                    array_keys($transaksiIds)
                )
                ->orderBy(
                    'd.transaksi_id',
                    'ASC'
                )
                ->orderBy(
                    'd.id',
                    'ASC'
                )
                ->get()
                ->getResultArray();


            foreach ($detailRows as $detail) {

                $transaksiId =
                    (int) (
                        $detail['transaksi_id']
                        ?? 0
                    );

                if (!$transaksiId) {
                    continue;
                }

                if (
                    !isset(
                        $detailPerTransaksi[$transaksiId]
                    )
                ) {

                    $detailPerTransaksi[$transaksiId] = [];
                }

                $detailPerTransaksi[$transaksiId][] = $detail;
            }
        }


        // =========================================================
        // HASIL AKHIR
        //
        // tanggal => kategori_id => total
        // =========================================================

        $hasilPerTanggal = [];


        // =========================================================
        // PROSES SETIAP TANGGAL
        // =========================================================

        foreach (
            $pembayaranPerTanggal
            as $tanggal => $pembayaranTransaksi
        ) {

            // Pastikan semua kategori punya nilai awal 0
            $hasilPerTanggal[$tanggal] = [];

            foreach ($kategoriRows as $kategori) {

                $hasilPerTanggal[$tanggal][(int) $kategori['id']] = 0;
            }


            // =====================================================
            // PROSES SETIAP TRANSAKSI PADA TANGGAL TERSEBUT
            // =====================================================

            foreach (
                $pembayaranTransaksi
                as $transaksiId => $totalPembayaran
            ) {

                $details =
                    $detailPerTransaksi[$transaksiId]
                    ?? [];


                if (empty($details)) {
                    continue;
                }


                // =================================================
                // TOTAL SUBTOTAL SELURUH DETAIL TRANSAKSI
                // =================================================

                $totalSubtotal =
                    0;

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


                // =================================================
                // ALOKASI PEMBAYARAN KE SETIAP DETAIL
                // =================================================

                foreach ($details as $detail) {

                    $subtotalDetail =
                        (float) (
                            $detail['subtotal']
                            ?? 0
                        );

                    if ($subtotalDetail <= 0) {
                        continue;
                    }


                    // ---------------------------------------------
                    // PERSENTASE DETAIL
                    // ---------------------------------------------

                    $persentase =
                        $subtotalDetail
                        /
                        $totalSubtotal;


                    // ---------------------------------------------
                    // BAGIAN PEMBAYARAN DETAIL
                    // ---------------------------------------------

                    $nilaiDibayar =
                        $totalPembayaran
                        *
                        $persentase;


                    // ---------------------------------------------
                    // KATEGORI
                    // ---------------------------------------------

                    $kategoriId =
                        (int) (
                            $detail['kategori_id']
                            ?? 0
                        );


                    if (
                        !array_key_exists(
                            $kategoriId,
                            $hasilPerTanggal[$tanggal]
                        )
                    ) {

                        /*
                         * Jaga-jaga apabila ada kategori
                         * pada detail tetapi tidak ditemukan
                         * di master kategori.
                         */
                        $hasilPerTanggal[$tanggal][$kategoriId] = 0;
                    }


                    $hasilPerTanggal[$tanggal][$kategoriId] +=
                        $nilaiDibayar;
                }
            }
        }


        // =========================================================
        // TOTAL SELURUH PERIODE PER KATEGORI
        // =========================================================

        $totalPerKategori = [];

        foreach ($kategoriRows as $kategori) {

            $kategoriId =
                (int) $kategori['id'];

            $totalPerKategori[$kategoriId] = 0;
        }


        foreach (
            $hasilPerTanggal
            as $tanggal => $kategoriData
        ) {

            foreach (
                $kategoriData
                as $kategoriId => $nilai
            ) {

                if (
                    !isset(
                        $totalPerKategori[$kategoriId]
                    )
                ) {

                    $totalPerKategori[$kategoriId] = 0;
                }

                $totalPerKategori[$kategoriId] += (float) $nilai;
            }
        }


        // =========================================================
        // RINGKASAN TOTAL SEMUA KATEGORI
        // =========================================================

        $grandTotal =
            array_sum(
                $totalPerKategori
            );


        // =========================================================
        // VIEW
        // =========================================================

        $data = [

            'title' =>
            'Laporan Penjualan',

            'content' =>
            'laporan/penjualan',

            'tanggal_mulai' =>
            $tanggalMulai,

            'tanggal_sampai' =>
            $tanggalSampai,

            'kategoriRows' =>
            $kategoriRows,

            'hasilPerTanggal' =>
            $hasilPerTanggal,

            'totalPerKategori' =>
            $totalPerKategori,

            'grandTotal' =>
            $grandTotal,
        ];


        return view(
            'layout/main',
            $data
        );
    }
}
