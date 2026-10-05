<?php

namespace App\Controllers;

use App\Models\TransaksiModel;
use App\Models\DetailTransaksiModel;
use App\Models\PembayaranModel;
use App\Models\PelangganModel;
use App\Models\ProdukModel;
use App\Models\KategoriModel;
use App\Models\UserModel;

class Transaksi extends BaseController
{
    /**
     * Ekspresi SQL untuk kolom turunan "Sisa" pada daftar transaksi.
     * Meniru tampilan di transaksiRowForJson(): sisa =
     * max(0, grand_total - total_dibayar). Dipakai untuk ORDER BY kolom 8
     * (tidak ada kolom `sisa` di tabel) dan dipetakan balik ke key baris
     * di transaksiSortRows().
     */
    private const SQL_SISA = 'GREATEST(transaksi.grand_total - transaksi.total_dibayar, 0)';

    public function index()
    {
        /*
    |--------------------------------------------------------------------------
    | FILTER (tanggal, status, kasir, keyword)
    |--------------------------------------------------------------------------
    |
    | Parsing query string dipindah ke transaksiFilterBag() supaya
    | index() dan endpoint data() memakai sumber yang sama. Daftar
    | kasir tetap diambil di sini karena dipakai untuk mengisi
    | dropdown filter.
    |
    */

        $f = $this->transaksiFilterBag();

        $daftar_kasir =
            (new UserModel())
            ->select('id, nama, username, inisial')
            ->orderBy('nama', 'ASC')
            ->findAll();


        /*
    |--------------------------------------------------------------------------
    | QUERY UTAMA
    |--------------------------------------------------------------------------
    |
    | Pindah ke Transaksi::data() (endpoint DataTables server-side).
    | index() hanya menyemai form filter; baris tabel diambil per
    | halaman dari /transaksi/data. applyDateFilter(),
    | applyStatusFilters(), dan applyKeywordFilter() tidak berubah --
    | sekarang dipanggil dari transaksiApplyFilters() yang dipakai
    | kedua halaman.
    |
    */


        /*
    |--------------------------------------------------------------------------
    | DATA UNTUK VIEW
    |--------------------------------------------------------------------------
    */

        $data = [

            'title' =>
            'Transaksi | AULIA',

            'content' =>
            'transaksi/index',

            /*
         * Filter tanggal yang sedang aktif
         */
            'tanggal_awal' =>
            $f['tanggal_awal'],

            'tanggal_akhir' =>
            $f['tanggal_akhir'],

            /*
         * Filter pembayaran
         */
            'status_pembayaran' =>
            $f['status_pembayaran'],

            /*
         * Filter transaksi
         */
            'status_transaksi' =>
            $f['status_transaksi'],

            /*
         * Filter karyawan (kasir)
         */
            'kasir_id_filter' =>
            $f['kasir_id'],

            /*
         * Keyword
         */
            'keyword' =>
            $f['keyword'],

            /*
         * Daftar karyawan (kasir)
         */
            'daftar_kasir' =>
            $daftar_kasir,

            /*
         * 3 pilihan kelompok status pembayaran (2026-09-09).
         * '' = Semua.
         */
            'status_pembayaran_list' =>
            [
                '',
                'belum_lunas',
                'lunas'
            ],

            /*
         * 3 pilihan kelompok status transaksi (2026-09-09).
         * '' = Semua.
         */
            'status_transaksi_list' =>
            [
                '',
                'aktif',
                'tidak_aktif'
            ],

            /*
         * Penanda halaman bukan berasal dari tagihan
         */
            'dariTagihan' =>
            false
        ];


        /*
    |--------------------------------------------------------------------------
    | RENDER
    |--------------------------------------------------------------------------
    */

        return view(
            'layout/main',
            $data
        );
    }

    /**
     * Tentukan rentang tanggal filter untuk index() dari query string.
     *
     * Behavior dipertahankan persis seperti sebelumnya:
     * - tanggal_awal / tanggal_akhir diambil dari GET;
     * - nilai kosong (null / '' / empty()) -> default "hari ini"
     *   (date('Y-m-d'); tanggal_awal lewat strtotime('-0 days') yang
     *   ekuivalen hari ini);
     * - nilai non-kosong dipakai apa adanya, tanpa normalisasi.
     *
     * Tidak menyentuh model/DB/session dan tidak mengubah timezone.
     *
     * @return array{0: string, 1: string} [tanggal_awal, tanggal_akhir]
     */
    private function getDateRange(): array
    {
        $tanggal_awal = $this->request->getGet('tanggal_awal');
        $tanggal_akhir = $this->request->getGet('tanggal_akhir');

        if (empty($tanggal_awal)) {
            $tanggal_awal = date(
                'Y-m-d',
                strtotime('-0 days')
            );
        }

        if (empty($tanggal_akhir)) {
            $tanggal_akhir = date('Y-m-d');
        }

        return [$tanggal_awal, $tanggal_akhir];
    }

    /**
     * Terapkan filter rentang tanggal ke query builder daftar transaksi.
     *
     * Dipindah verbatim dari index() -- behavior TIDAK berubah:
     * - hanya diterapkan saat $keyword === '' (kalau ada keyword,
     *   pencarian berlaku ke seluruh histori tanpa batas tanggal);
     * - batas bawah: $tanggalAwal . ' 00:00:00';
     * - batas atas eksklusif: $tanggalAkhir + 1 hari, di-normalisasi ke
     *   'Y-m-d 00:00:00' lewat strtotime();
     * - where('transaksi.tanggal >=', awal) lalu
     *   where('transaksi.tanggal <', akhir).
     *
     * $awalDatetime / $akhirDatetime sengaja lokal -- tidak dipakai lagi
     * oleh index() setelah filter diterapkan. $builder dimutasi by-handle.
     */
    private function applyDateFilter(
        \CodeIgniter\Database\BaseBuilder $builder,
        string $keyword,
        string $tanggalAwal,
        string $tanggalAkhir
    ): void {
        if ($keyword === '') {

            $awalDatetime =
                $tanggalAwal . ' 00:00:00';

            /*
         * Tambahkan 1 hari untuk menjadikan batas atas eksklusif.
         *
         * Contoh:
         * tanggal_akhir = 2026-08-31
         *
         * menjadi:
         * < 2026-09-01 00:00:00
         *
         * sehingga transaksi sampai
         * 2026-08-31 23:59:59 tetap masuk.
         */

            $akhirDatetime =
                date(
                    'Y-m-d 00:00:00',
                    strtotime(
                        $tanggalAkhir . ' +1 day'
                    )
                );


            $builder
                ->where(
                    'transaksi.tanggal >=',
                    $awalDatetime
                )
                ->where(
                    'transaksi.tanggal <',
                    $akhirDatetime
                );
        }
    }

    /**
     * Terjemahkan pilihan filter status (pembayaran + transaksi) menjadi
     * kondisi WHERE pada query builder daftar transaksi.
     *
     * Dipindah verbatim dari index() -- pemetaan nilai TIDAK berubah:
     *
     *   status_pembayaran:
     *     'belum_lunas' -> status_pembayaran IN (belum_bayar, dp)
     *     'lunas'       -> status_pembayaran = lunas
     *     lainnya != '' -> status_pembayaran = <nilai>  (kompat mundur)
     *     ''            -> tidak difilter (Semua)
     *
     *   status_transaksi:
     *     'aktif'       -> status IN (proses, selesai, diambil)
     *     'tidak_aktif' -> status IN (batal, mangkrak)
     *     'batal' | 'proses' | 'selesai' | 'mangkrak' -> status = <nilai>  (kompat mundur)
     *     ''            -> tidak difilter (Semua)
     *
     * $builder adalah BaseBuilder (hasil $db->table()), dimutasi
     * by-handle.
     */
    private function applyStatusFilters(
        \CodeIgniter\Database\BaseBuilder $builder,
        string $statusPembayaran,
        string $statusTransaksi
    ): void {
        /*
         * FILTER STATUS PEMBAYARAN
         */
        if ($statusPembayaran === 'belum_lunas') {

            /*
         * Kelompok Belum Lunas: belum_bayar ATAU dp.
         */

            $builder->whereIn(
                'transaksi.status_pembayaran',
                ['belum_bayar', 'dp']
            );
        } elseif ($statusPembayaran === 'lunas') {

            $builder->where(
                'transaksi.status_pembayaran',
                'lunas'
            );
        } elseif ($statusPembayaran !== '') {

            /*
         * Kompatibilitas mundur: value individual lama
         * ('belum_bayar' atau 'dp' dikirim langsung, exact match).
         */

            $builder->where(
                'transaksi.status_pembayaran',
                $statusPembayaran
            );
        }

        /*
         * $statusPembayaran === '' (Semua) -> tidak ada filter.
         */


        /*
         * FILTER STATUS TRANSAKSI
         */
        if ($statusTransaksi === 'aktif') {

            /*
         * Kelompok Aktif: proses ATAU selesai ATAU diambil.
         */

            $builder->whereIn(
                'transaksi.status',
                ['proses', 'selesai', 'diambil']
            );
        } elseif ($statusTransaksi === 'tidak_aktif') {

            /*
         * Kelompok Tidak Aktif: batal ATAU mangkrak.
         */

            $builder->whereIn(
                'transaksi.status',
                ['batal', 'mangkrak']
            );
        } elseif ($statusTransaksi === 'batal') {

            /*
         * Kompatibilitas mundur: hanya transaksi batal.
         */

            $builder->where(
                'transaksi.status',
                'batal'
            );
        } elseif ($statusTransaksi === 'proses') {

            /*
         * Kompatibilitas mundur: hanya transaksi proses.
         */

            $builder->where(
                'transaksi.status',
                'proses'
            );
        } elseif ($statusTransaksi === 'selesai') {

            /*
         * Kompatibilitas mundur: hanya transaksi selesai.
         */

            $builder->where(
                'transaksi.status',
                'selesai'
            );
        } elseif ($statusTransaksi === 'mangkrak') {

            /*
         * Kompatibilitas mundur: hanya transaksi mangkrak.
         */

            $builder->where(
                'transaksi.status',
                'mangkrak'
            );
        }

        /*
         * $statusTransaksi === '' (Semua) -> tidak ada filter status
         * transaksi sama sekali; proses + selesai + batal + mangkrak
         * semua tampil.
         */
    }

    /**
     * Terapkan pencarian keyword ke query builder daftar transaksi.
     *
     * Dipindah verbatim dari index() -- kondisi pencarian TIDAK berubah:
     * grup OR atas kode_invoice / no_order / pelanggan.nama, plus exact
     * & LIKE atas no_order numerik bila keyword bisa diparse.
     *
     * Keyword kosong -> builder tidak disentuh, return null.
     *
     * @return int|null Nomor order hasil parse (dipakai lagi di index()
     *                  untuk pencarian archive), null jika tidak ada.
     */
    private function applyKeywordFilter(
        \CodeIgniter\Database\BaseBuilder $builder,
        string $keyword
    ): ?int {
        if ($keyword === '') {
            return null;
        }

        $parsedNoOrder =
            $this->parseNoOrder(
                $keyword
            );


        $builder->groupStart();

        /*
     * Invoice
     */
        $builder->like(
            'transaksi.kode_invoice',
            $keyword
        );

        /*
     * No Order
     */
        $builder->orLike(
            'transaksi.no_order',
            $keyword
        );

        /*
     * Nama Pelanggan
     */
        $builder->orLike(
            'pelanggan.nama',
            $keyword
        );


        /*
     * Jika keyword dapat diparse
     * menjadi nomor order numerik,
     * cari juga secara exact number.
     */

        if ($parsedNoOrder) {

            $builder->orWhere(
                'transaksi.no_order',
                (int) $parsedNoOrder
            );

            $builder->orLike(
                'transaksi.no_order',
                (string) $parsedNoOrder
            );
        }

        $builder->groupEnd();

        return $parsedNoOrder;
    }

    /**
     * Filter daftar transaksi dari query string, dipakai bersama oleh
     * index() (menyemai form filter) dan data() (endpoint DataTables).
     *
     * Semantik SAMA dengan parsing lama di index():
     * - status_transaksi null (parameter benar-benar absen) -> 'aktif';
     *   string kosong tetap dianggap "Semua";
     * - kasir_id divalidasi terhadap daftar user nyata (whitelist);
     *   pengecekan hanya jalan bila parameter kasir_id benar-benar dikirim
     *   supaya tiap request tidak menambah query `users` tanpa perlu;
     * - keyword di-trim.
     *
     * @return array{tanggal_awal: string, tanggal_akhir: string, status_pembayaran: string, status_transaksi: string, kasir_id: ?int, keyword: string}
     */
    private function transaksiFilterBag(): array
    {
        [$tanggal_awal, $tanggal_akhir] = $this->getDateRange();

        $status_pembayaran = $this->request->getGet('status_pembayaran') ?? '';

        $status_transaksi = $this->request->getGet('status_transaksi');
        if ($status_transaksi === null) {
            $status_transaksi = 'aktif';
        }

        $kasir_id_filter = null;
        $kasirIdRaw = $this->request->getGet('kasir_id');
        if ($kasirIdRaw !== null && $kasirIdRaw !== '') {
            $kasirIdValid = array_map(
                'intval',
                array_column((new UserModel())->select('id')->findAll(), 'id')
            );
            $kasir_id_filter = in_array((int) $kasirIdRaw, $kasirIdValid, true)
                ? (int) $kasirIdRaw
                : null;
        }

        $keyword = trim((string) ($this->request->getGet('keyword') ?? ''));

        return [
            'tanggal_awal'      => $tanggal_awal,
            'tanggal_akhir'     => $tanggal_akhir,
            'status_pembayaran' => $status_pembayaran,
            'status_transaksi'  => $status_transaksi,
            'kasir_id'          => $kasir_id_filter,
            'keyword'           => $keyword,
        ];
    }

    /**
     * Query builder dasar daftar transaksi (kolom + join sama dengan
     * index() lama). BaseBuilder, bukan Model, mengikuti pola
     * server-side S1/S2/Tagihan.
     */
    private function transaksiBaseBuilder($db)
    {
        return $db->table('transaksi')
            ->select(
                'transaksi.id,
             transaksi.kode_invoice,
             transaksi.no_order,
             transaksi.tanggal,
             transaksi.pelanggan_id,
             transaksi.kasir_id,
             transaksi.grand_total,
             transaksi.total_dibayar,
             transaksi.status_pembayaran,
             transaksi.status,
             transaksi.sumber,
             users.username AS kasir_nama,
             users.inisial AS kasir_inisial,
             pelanggan.nama AS pelanggan_nama'
            )
            ->join('users', 'users.id = transaksi.kasir_id', 'left')
            ->join('pelanggan', 'pelanggan.id = transaksi.pelanggan_id', 'left');
    }

    /**
     * Terapkan seluruh filter daftar transaksi (tanggal, status, kasir,
     * keyword) ke builder. Urutan & pemetaan nilai tidak berubah dari
     * index() lama.
     */
    private function transaksiApplyFilters($builder, array $f): void
    {
        $this->applyDateFilter($builder, $f['keyword'], $f['tanggal_awal'], $f['tanggal_akhir']);
        $this->applyStatusFilters($builder, $f['status_pembayaran'], $f['status_transaksi']);

        if ($f['kasir_id'] !== null) {
            $builder->where('transaksi.kasir_id', $f['kasir_id']);
        }

        $this->applyKeywordFilter($builder, $f['keyword']);
    }

    /**
     * Whitelist pengurutan DataTables -> kolom SQL. Cegah injeksi
     * kolom lewat order[][column]. Tie-breaker id DESC selalu
     * ditambahkan agar paging & merge arsip deterministik.
     */
    private function transaksiOrder(): array
    {
        $map = [
            1 => 'transaksi.kode_invoice',
            2 => 'transaksi.no_order',
            3 => 'transaksi.tanggal',
            4 => 'pelanggan.nama',
            5 => 'users.inisial',
            6 => 'transaksi.grand_total',
            7 => 'transaksi.total_dibayar',
            8 => self::SQL_SISA,
            9 => 'transaksi.status_pembayaran',
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

        $out[] = ['transaksi.id', 'DESC'];

        return $out;
    }

    private function transaksiLiveCount($db, array $f): int
    {
        $builder = $this->transaksiBaseBuilder($db);
        $this->transaksiApplyFilters($builder, $f);
        return (int) $builder->countAllResults();
    }

    private function transaksiLiveRows($db, array $f, array $order, int $limit = 0, int $offset = 0): array
    {
        $builder = $this->transaksiBaseBuilder($db);
        $this->transaksiApplyFilters($builder, $f);
        foreach ($order as [$col, $dir]) {
            // $col hanya berasal dari whitelist transaksiOrder() (tidak pernah
            // input user mentah), jadi aman di-escape=false agar ekspresi
            // turunan Sisa bisa dipakai apa adanya.
            $builder->orderBy($col, $dir, false);
        }
        if ($limit > 0) {
            $builder->limit($limit, $offset);
        }
        return $builder->get()->getResultArray();
    }

    /**
     * Hasil pencarian di database archive (hanya bila ada keyword).
     * Bentuk kolom disamakan oleh TransaksiArchiveService; limit 200
     * bawaan service dipertahankan.
     */
    private function transaksiArchiveRows(array $f): array
    {
        if ($f['keyword'] === '') {
            return [];
        }

        try {
            $service = new \App\Services\TransaksiArchiveService();
            $rows = $service->cariUntukDaftarTransaksi(
                $f['keyword'],
                $this->parseNoOrder($f['keyword']) ?: null
            );
        } catch (\Throwable $e) {
            log_message('error', 'Transaksi::data keyword archive gagal: ' . $e->getMessage());
            return [];
        }

        foreach ($rows as &$r) {
            // Archive menyimpan kasir_nama (snapshot), bukan kasir_inisial.
            $r['kasir_inisial'] = $r['kasir_inisial'] ?? ($r['kasir_nama'] ?? '');
        }
        unset($r);

        return $rows;
    }

    /**
     * Merge-sort live + archive untuk jalur keyword. Nama kolom ORDER BY
     * (qualified) dipetakan ke key baris hasil query.
     */
    private function transaksiSortRows(array $rows, array $order): array
    {
        $keyMap = [
            'transaksi.kode_invoice'      => 'kode_invoice',
            'transaksi.no_order'          => 'no_order',
            'transaksi.tanggal'           => 'tanggal',
            'transaksi.id'                => 'id',
            'pelanggan.nama'              => 'pelanggan_nama',
            'users.inisial'               => 'kasir_inisial',
            'transaksi.grand_total'       => 'grand_total',
            'transaksi.total_dibayar'     => 'total_dibayar',
            'transaksi.status_pembayaran' => 'status_pembayaran',
            self::SQL_SISA                => 'sisa',
        ];

        // Sisa tidak ada di hasil query (kolom turunan); hitung sekali di sini
        // supaya urutan pada jalur merge sama dengan urutan SQL di MySQL.
        foreach ($rows as &$row) {
            $row['sisa'] = max(0, (float) ($row['grand_total'] ?? 0) - (float) ($row['total_dibayar'] ?? 0));
        }
        unset($row);

        usort($rows, function ($a, $b) use ($order, $keyMap) {
            foreach ($order as [$col, $dir]) {
                $key = $keyMap[$col] ?? $col;
                $av = $a[$key] ?? null;
                $bv = $b[$key] ?? null;
                $cmp = (is_numeric($av) && is_numeric($bv))
                    ? ($av <=> $bv)
                    : strcmp((string) $av, (string) $bv);
                if ($cmp !== 0) {
                    return $dir === 'DESC' ? -$cmp : $cmp;
                }
            }
            return 0;
        });

        return $rows;
    }

    /**
     * Bentuk satu baris JSON untuk DataTables. Format tampilan & badge
     * dihitung server agar view tidak perlu helper PHP.
     */
    private function transaksiRowForJson(array $row): array
    {
        $grandTotal = (float) ($row['grand_total'] ?? 0);
        $totalDibayar = (float) ($row['total_dibayar'] ?? 0);
        $statusPembayaran = (string) ($row['status_pembayaran'] ?? '');
        $status = (string) ($row['status'] ?? '');

        return [
            'id'                => (int) ($row['id'] ?? 0),
            'kode_invoice'      => $row['kode_invoice'] ?? '-',
            'no_order_display'  => !empty($row['no_order']) ? format_no_order((int) $row['no_order']) : '-',
            'tanggal_ts'        => strtotime((string) ($row['tanggal'] ?? '')) ?: 0,
            'tanggal_display'   => !empty($row['tanggal']) ? tanggal_singkat($row['tanggal']) : '-',
            'pelanggan_nama'    => $row['pelanggan_nama'] ?? '-',
            'kasir'             => $row['kasir_inisial'] ?? ($row['kasir_nama'] ?? '-'),
            'grand_total'       => $grandTotal,
            'total_dibayar'     => $totalDibayar,
            'sisa'              => max(0, $grandTotal - $totalDibayar),
            'kelebihan'         => max(0, $totalDibayar - $grandTotal),
            'status'            => $status,
            'status_pembayaran' => $statusPembayaran,
            'payment_class'     => status_pembayaran_badge_class($statusPembayaran),
            'payment_label'     => status_pembayaran_label($statusPembayaran),
            'status_class'      => status_transaksi_badge_class($status),
            'status_label'      => status_transaksi_label($status),
            'dari_archive'      => ($row['_sumber'] ?? 'aktif') === 'archive',
        ];
    }

    /**
     * API: data daftar transaksi untuk DataTables (server-side).
     * Tanpa `totals` -- halaman ini tidak menampilkan baris TOTAL.
     */
    public function data()
    {
        helper('order');

        $f = $this->transaksiFilterBag();
        $draw = (int) ($this->request->getGet('draw') ?? 1);
        $start = max(0, (int) ($this->request->getGet('start') ?? 0));
        $length = (int) ($this->request->getGet('length') ?? 25);
        if ($length <= 0) {
            $length = 25;
        }
        $order = $this->transaksiOrder();

        $db = db_connect();
        $liveCount = $this->transaksiLiveCount($db, $f);
        $archiveRows = $this->transaksiArchiveRows($f);

        if ($archiveRows === []) {
            $rows = $this->transaksiLiveRows($db, $f, $order, $length, $start);
            $total = $liveCount;
        } else {
            // ponytail: arsip kecil (maks 200) & diharapkan lebih lama dari live.
            // Ambil hanya (start + length) baris live teratas: cukup untuk mengisi
            // halaman setelah digabung arsip, tanpa menarik seluruh histori.
            // Upgrade ke paging lintas sumber dua arah bila arsip membesar.
            $rows = $this->transaksiSortRows(
                array_merge(
                    $this->transaksiLiveRows($db, $f, $order, $start + $length),
                    $archiveRows
                ),
                $order
            );
            $rows = array_slice($rows, $start, $length);
            $total = $liveCount + count($archiveRows);
        }

        $data = array_map([$this, 'transaksiRowForJson'], $rows);

        return $this->response->setJSON([
            'draw'            => $draw,
            'recordsTotal'    => $total,
            'recordsFiltered' => $total,
            'data'            => $data,
        ]);
    }

    public function hariIni()
    {
        $transaksi = $this->ambilItemTerjualHariIni();


        /*
    |--------------------------------------------------------------------------
    | Kirim ke view
    |--------------------------------------------------------------------------
    */

        $data = [
            'title'     => 'Item Terjual Hari Ini | AULIA',
            'content'   => 'transaksi/hari_ini',
            'transaksi' => $transaksi,
            'tanggal'   => date('Y-m-d'),
        ];

        return view(
            'layout/main',
            $data
        );
    }

    /**
     * Query laporan "Item Terjual Hari Ini".
     *
     * Dipindah verbatim dari hariIni() -- satu baris = satu item
     * detail_transaksi hari ini (batas [00:00:00 hari ini, 00:00:00
     * besok)), transaksi induk di-join, status 'batal' dikecualikan.
     * SELECT/alias/JOIN/WHERE/ORDER BY dan perilaku date()/timezone
     * TIDAK berubah.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ambilItemTerjualHariIni(): array
    {
        $db = \Config\Database::connect();

        /*
    |--------------------------------------------------------------------------
    | Batas waktu hari ini
    |--------------------------------------------------------------------------
    */

        $awalHari = date('Y-m-d 00:00:00');
        $awalBesok = date(
            'Y-m-d 00:00:00',
            strtotime('+1 day')
        );


        /*
    |--------------------------------------------------------------------------
    | Ambil item transaksi
    |--------------------------------------------------------------------------
    |
    | Satu baris = satu item dari detail_transaksi.
    | Data transaksi induk ikut dibawa agar view bisa
    | mengelompokkan item berdasarkan transaksi_id.
    |
    */

        $builder = $db->table('detail_transaksi dt');

        $builder->select([
            'dt.id AS detail_id',
            'dt.transaksi_id',
            'dt.produk_id',
            'dt.nama_produk',
            'dt.kategori_id',
            'dt.jumlah',
            'dt.harga_satuan',
            'dt.subtotal',

            't.tanggal',
            't.kode_invoice',
            't.no_order',
            't.pelanggan_id',
            't.kasir_id',
            't.grand_total',
            't.total_dibayar',
            't.status_pembayaran',
            't.status',
            't.sumber',

            'p.nama AS pelanggan_nama',

            'u.username AS kasir_nama',
            'u.nama AS kasir_real_nama',
        ]);


        /*
    |--------------------------------------------------------------------------
    | JOIN
    |--------------------------------------------------------------------------
    */

        $builder->join(
            'transaksi t',
            't.id = dt.transaksi_id',
            'inner'
        );

        $builder->join(
            'pelanggan p',
            'p.id = t.pelanggan_id',
            'left'
        );

        $builder->join(
            'users u',
            'u.id = t.kasir_id',
            'left'
        );


        /*
    |--------------------------------------------------------------------------
    | Hanya transaksi hari ini
    |--------------------------------------------------------------------------
    */

        $builder->where(
            't.tanggal >=',
            $awalHari
        );

        $builder->where(
            't.tanggal <',
            $awalBesok
        );


        /*
    |--------------------------------------------------------------------------
    | Hanya transaksi aktif
    |--------------------------------------------------------------------------
    |
    | Transaksi yang sudah dibatalkan tidak ditampilkan
    | di halaman Item Terjual Hari Ini.
    |
    */

        $builder->where(
            't.status !=',
            'batal'
        );


        /*
    |--------------------------------------------------------------------------
    | Urutan
    |--------------------------------------------------------------------------
    |
    | Transaksi terbaru di atas.
    | Dalam transaksi yang sama, item mengikuti
    | urutan detail.
    |
    */

        $builder->orderBy(
            't.tanggal',
            'DESC'
        );

        $builder->orderBy(
            't.id',
            'DESC'
        );

        $builder->orderBy(
            'dt.id',
            'ASC'
        );


        /*
    |--------------------------------------------------------------------------
    | Ambil data
    |--------------------------------------------------------------------------
    */

        return $builder
            ->get()
            ->getResultArray();
    }

    private function getAvailableNoOrdersForEdit(?int $selectedNoOrder = null): array
    {
        $transaksiModel = new \App\Models\TransaksiModel();

        // Transaksi belum punya no_order (mis. dibuat lewat alur yang
        // tidak mewajibkannya) -- tetap tampilkan dropdown dengan
        // rekomendasi terbaru, sama seperti Kasir::index(), supaya
        // kasir tetap bisa memilih no_order dari halaman edit.
        $pivot = ($selectedNoOrder !== null && $selectedNoOrder > 0)
            ? $selectedNoOrder
            : $transaksiModel->getRecommendedNoOrder();

        $min = max(1, $pivot - 20);
        $max = $pivot + 20;

        $usedOrders = $transaksiModel
            ->select('no_order')
            ->where('no_order >=', $min)
            ->where('no_order <=', $max)
            ->findAll();

        $usedArray = array_map(
            'intval',
            array_column($usedOrders, 'no_order')
        );

        // Nomor yang sedang diedit jangan dianggap terpakai
        $usedArray = array_values(
            array_diff($usedArray, [$selectedNoOrder])
        );

        $available = [];

        for ($i = $min; $i <= $max; $i++) {
            if (!in_array($i, $usedArray, true)) {
                $available[] = $i;
            }
        }

        sort($available, SORT_NUMERIC);

        return $available;
    }
    /**
     * Decode format tampilan no_order (mis. "A0003") ke nomor internal,
     * KHUSUS untuk keyword global search (Transaksi::applyKeywordFilter()).
     *
     * Beda kontrak dari helper global parse_no_order() (dipakai form
     * input no_order di Api::simpanTransaksi()/Transaksi::updateTransaksi()):
     * di sini keyword angka polos SENGAJA dikembalikan null -- ditangani
     * cukup lewat LIKE biasa di applyKeywordFilter(), bukan exact
     * no_order. Jangan hapus guard is_numeric() ini / delegasikan
     * langsung ke parse_no_order() tanpanya -- keyword angka dengan
     * leading zero (mis. "0001") akan mulai match no_order lama
     * bernilai kecil kalau guard ini dilepas (lihat riwayat commit).
     */
    private function parseNoOrder($formatted)
    {
        $formatted = trim((string) $formatted);

        if (is_numeric($formatted)) {
            return null;
        }

        return parse_no_order($formatted);
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
            // Tidak ada di DB utama -- coba cari di archive sebelum
            // menyerah (poin 9: transaksi yang sudah di-archive tetap
            // boleh dilihat, cuma read-only).
            try {
                $dariArchive = (new \App\Services\TransaksiArchiveService())->cariById((int) $id);
            } catch (\Throwable $e) {
                log_message('error', 'Transaksi::detail fallback archive gagal: ' . $e->getMessage());
                $dariArchive = null;
            }

            if (!$dariArchive) {
                return redirect()->to('/transaksi')->with('error', 'Transaksi tidak ditemukan.');
            }

            $transaksi = $dariArchive['transaksi'];
            $detailItems = $dariArchive['detail_items'];
            $pembayaran = $dariArchive['pembayaran'];
            $pelanggan = $transaksi['pelanggan_id']
                ? $pelangganModel->find($transaksi['pelanggan_id'])
                : null;

            // Kalau baris pelanggan sudah tidak ada/berubah di DB utama,
            // tetap tampilkan nama hasil snapshot archive supaya
            // halaman ini tetap informatif & self-contained.
            if (!$pelanggan && !empty($transaksi['pelanggan_nama'])) {
                $pelanggan = ['nama' => $transaksi['pelanggan_nama']];
            }

            $total_dibayar = array_sum(array_column($pembayaran, 'jumlah'));
            $sisa_tagihan = max(0, $transaksi['grand_total'] - $total_dibayar);
            $kelebihan_bayar = max(0, $total_dibayar - $transaksi['grand_total']);

            $data = [
                'title'   => 'Detail Transaksi (Archive) | AULIA',
                'content' => 'transaksi/detail',
                'transaksi' => $transaksi,
                'detail_items' => $detailItems,
                'pembayaran' => $pembayaran,
                'pelanggan' => $pelanggan,
                'total_dibayar' => $total_dibayar,
                'sisa_tagihan' => $sisa_tagihan,
                'kelebihan_bayar' => $kelebihan_bayar,
                'dariTagihan' => false,
                'dariArchive' => true,
            ];

            return view('layout/main', $data);
        }

        // Ambil detail item
        $detailItems = $detailModel->where('transaksi_id', $id)->findAll();

        // Ambil pembayaran
        $pembayaran = $pembayaranModel
            ->select('pembayaran.*, users.nama as kasir_nama, users.username as kasir_username, users.inisial as kasir_inisial')
            ->join('users', 'users.id = pembayaran.kasir_id', 'left')
            ->where('pembayaran.transaksi_id', $id)
            ->where('pembayaran.status', 'aktif')
            ->orderBy('pembayaran.tanggal', 'ASC')
            ->findAll();

        // Ambil pelanggan
        $pelanggan = null;
        if ($transaksi['pelanggan_id']) {
            $pelanggan = $pelangganModel->find($transaksi['pelanggan_id']);
        }

        $total_dibayar = array_sum(array_column($pembayaran, 'jumlah'));
        $sisa_tagihan = max(0, $transaksi['grand_total'] - $total_dibayar);
        $kelebihan_bayar = max(0, $total_dibayar - $transaksi['grand_total']);

        $data = [
            'title'   => 'Detail Transaksi | AULIA',
            'content' => 'transaksi/detail',
            'transaksi' => $transaksi,
            'detail_items' => $detailItems,
            'pembayaran' => $pembayaran,
            'pelanggan' => $pelanggan,
            'total_dibayar' => $total_dibayar,
            'sisa_tagihan' => $sisa_tagihan,
            'kelebihan_bayar' => $kelebihan_bayar,
            'dariTagihan' => false,
            'dariArchive' => false,
        ];

        return view('layout/main', $data);
    }

    public function batal($id)
    {
        $model = new TransaksiModel();
        $detailModel = new DetailTransaksiModel();
        $produkModel = new \App\Models\ProdukModel();

        $transaksi = $model->find($id);

        if (!$transaksi) {
            return redirect()->to('/transaksi')->with('error', 'Transaksi tidak ditemukan.');
        }

        if ($transaksi['status'] === 'batal') {
            return redirect()->to('/transaksi')->with('error', 'Transaksi sudah dibatalkan.');
        }

        // Mulai transaksi database
        $db = \Config\Database::connect();
        $db->transStart();

        try {
            // Aturan perubahan status dipusatkan di TransaksiModel.
            $isAdmin = session()->get('role') === 'admin';
            $model->ubahStatus($id, 'batal', $isAdmin);

            $db->transComplete();

            return redirect()->to('/transaksi')->with('success', 'Transaksi berhasil dibatalkan.');
        } catch (\Exception $e) {
            $db->transRollback();
            return redirect()->to('/transaksi')->with('error', 'Gagal membatalkan transaksi: ' . $e->getMessage());
        }
    }

    /**
     * Halaman Edit Transaksi (Menggunakan tampilan kasir)
     */
    public function edit($id)
    {
        $produkModel = new ProdukModel();
        $transaksiModel = new TransaksiModel();
        $detailModel = new DetailTransaksiModel();
        $kategoriModel = new KategoriModel();
        $pelangganModel = new PelangganModel();

        // Ambil transaksi
        $transaksi = $transaksiModel
            ->select('transaksi.*, users.username as kasir_nama')
            ->join('users', 'users.id = transaksi.kasir_id', 'left')
            ->find($id);

        if (!$transaksi) {
            return redirect()
                ->to('/transaksi')
                ->with('error', 'Transaksi tidak ditemukan.');
        }

        // Edit hanya diperbolehkan selama transaksi masih PROSES.
        if (($transaksi['status'] ?? '') !== 'proses') {
            return redirect()
                ->to('/transaksi/detail/' . $id)
                ->with('error', 'Transaksi sudah final atau batal, hanya transaksi PROSES yang dapat diedit.');
        }

        /*
     * ============================================================
     * FILTER PRODUK
     * ============================================================
     *
     * Tetap definisikan variabel ini agar view lama yang masih
     * menggunakan $keyword / $kategoriFilter tidak error.
     *
     * PENTING:
     * Variabel ini TIDAK digunakan untuk query produk.
     * Filter sebenarnya dilakukan oleh JavaScript di browser.
     */
        $kategoriFilter = trim(
            (string) ($this->request->getGet('kategori') ?? '')
        );

        $keyword = trim(
            (string) ($this->request->getGet('keyword') ?? '')
        );

        /*
     * ============================================================
     * PRODUK
     * ============================================================
     *
     * Ambil SEMUA produk aktif sekali saja.
     * Jangan gunakan method Paginated.
     */
        $produk = $produkModel->getProdukAktifWithPopularity();

        // Detail transaksi
        $detailItems = $detailModel
            ->where('transaksi_id', $id)
            ->orderBy('id', 'ASC')
            ->findAll();

        // Pelanggan
        $pelanggan = null;

        if (!empty($transaksi['pelanggan_id'])) {
            $pelanggan = $pelangganModel->find(
                $transaksi['pelanggan_id']
            );
        }

        $selectedNoOrder = !empty($transaksi['no_order'])
            ? (int) $transaksi['no_order']
            : null;

        $availableNoOrders = $this->getAvailableNoOrdersForEdit(
            $selectedNoOrder
        );

        $data = [
            'title' => 'Edit Transaksi | AULIA',
            'content' => 'kasir/edit',

            'is_edit' => true,
            'edit_mode' => true,
            'transaksi_id' => (int) $id,

            'transaksi' => $transaksi,
            'detail_items' => $detailItems,
            'pelanggan' => $pelanggan,

            /*
         * Semua produk.
         * Filter dilakukan di JavaScript.
         */
            'produk' => $produk,

            'kategori' => $kategoriModel
                ->where('parent_id IS NULL')
                ->findAll(),

            'kategori_aktif' => $kategoriFilter,

            /*
         * Dipertahankan untuk kompatibilitas view.
         */
            'keyword_produk' => $keyword,
            'keyword' => $keyword,

            'available_no_orders' => $availableNoOrders,
            'selected_no_order' => $selectedNoOrder,
        ];

        return view('layout/main', $data);
    }

    /** Update transaksi tanpa membuat ulang histori pembayaran. */
    public function updateTransaksi($id)
    {
        $db = \Config\Database::connect();

        $transaksiModel = new \App\Models\TransaksiModel();
        $detailModel = new \App\Models\DetailTransaksiModel();
        $pelangganModel = new \App\Models\PelangganModel();

        $request = $this->request->getJSON(true) ?? [];
        $keranjang = $request['keranjang'] ?? [];

        // ==========================================
        // 1. VALIDASI DASAR
        // ==========================================

        if (!is_array($keranjang) || empty($keranjang)) {
            return $this->response->setJSON([
                'status'  => 'error',
                'message' => 'Keranjang kosong. Tidak ada yang bisa disimpan.'
            ]);
        }

        // Validasi & normalisasi tiap baris (jumlah/harga/subtotal) --
        // satu sumber kebenaran yang sama dengan jalur buat (TODO-BL03).
        $validasiItem = \App\Services\ValidasiItemTransaksi::normalisasi($keranjang);

        if ($validasiItem['error'] !== null) {
            return $this->response->setJSON([
                'status'  => 'error',
                'message' => $validasiItem['error']
            ]);
        }

        $keranjang = $validasiItem['items'];

        $transaksi = $transaksiModel->find($id);

        if (!$transaksi) {
            return $this->response->setJSON([
                'status'  => 'error',
                'message' => 'Transaksi tidak ditemukan.'
            ]);
        }

        // Update hanya diperbolehkan selama transaksi masih PROSES.
        if (($transaksi['status'] ?? '') !== 'proses') {
            return $this->response->setJSON([
                'status'  => 'error',
                'message' => 'Hanya transaksi PROSES yang dapat diedit.'
            ]);
        }

        // ==========================================
        // 2. DATA REQUEST
        // ==========================================

        $pelangganId = $request['pelanggan'] ?? null;
        $pelangganNama = trim((string) ($request['pelanggan_nama'] ?? ''));
        $pelangganTelp = trim((string) ($request['pelanggan_telp'] ?? ''));
        $diskon = (float) ($request['diskon'] ?? 0);
        // Sama seperti Api::simpanTransaksi() -- flag saja, persennya
        // diresolusi ulang dari master pelanggan di bawah (read-only).
        $diskonPelangganAktif = !empty($request['diskon_pelanggan_aktif']);
        $noOrderInput = trim((string) ($request['no_order'] ?? ''));

        // ==========================================
        // 3. PARSE NO ORDER
        // ==========================================

        $noOrder = null;

        if ($noOrderInput !== '') {
            $noOrder = parse_no_order($noOrderInput);

            if ($noOrder === null) {
                return $this->response->setJSON([
                    'status'  => 'error',
                    'message' => 'Format No Order tidak valid.'
                ]);
            }
        }

        // ==========================================
        // 4. VALIDASI KATEGORI 16 / CETAK
        // ==========================================

        $hasKategori16 = false;

        foreach ($keranjang as $item) {
            $kategoriId = isset($item['kategori_id'])
                ? (int) $item['kategori_id']
                : 0;

            if ($kategoriId === 16) {
                $hasKategori16 = true;
                break;
            }

            if (
                isset($item['is_cetak']) &&
                $item['is_cetak'] === true
            ) {
                $hasKategori16 = true;
                break;
            }

            if (
                isset($item['is_custom']) &&
                $item['is_custom'] === true &&
                isset($item['is_cetak']) &&
                $item['is_cetak'] === true
            ) {
                $hasKategori16 = true;
                break;
            }
        }

        $noOrderValidationError = $this->validasiNoOrderEdit(
            $noOrder,
            $hasKategori16
        );

        if ($noOrderValidationError !== null) {
            return $this->response->setJSON([
                'status'  => 'error',
                'message' => $noOrderValidationError
            ]);
        }

        // ==========================================
        // 5. HITUNG SUBTOTAL
        // ==========================================

        $subtotal = $validasiItem['subtotal'];

        // ==========================================
        // 6. VALIDASI DISKON
        // ==========================================

        if ($diskon < 0) {
            return $this->response->setJSON([
                'status'  => 'error',
                'message' => 'Diskon tidak boleh negatif.'
            ]);
        }

        if ($diskon > $subtotal) {
            return $this->response->setJSON([
                'status'  => 'error',
                'message' => 'Diskon tidak boleh melebihi total belanja.'
            ]);
        }

        // ==========================================
        // 7. PELANGGAN
        // ==========================================

        $finalPelangganId = null;

        if ($pelangganId === 'new' && $pelangganNama !== '') {
            $finalPelangganId = $pelangganModel->insert([
                'nama'  => $pelangganNama,
                'no_hp' => $pelangganTelp
            ]);

            if (!$finalPelangganId) {
                throw new \Exception('Gagal membuat pelanggan baru.');
            }
        } elseif (!empty($pelangganId) && is_numeric($pelangganId)) {
            $finalPelangganId = (int) $pelangganId;
        } else {
            $finalPelangganId = !empty($transaksi['pelanggan_id'])
                ? (int) $transaksi['pelanggan_id']
                : null;
        }

        // ==========================================
        // 8. RESOLUSI DISKON PELANGGAN + HITUNG GRAND TOTAL
        // Pakai App\Services\KalkulasiDiskonTransaksi -- SATU SUMBER
        // KEBENARAN yang sama dengan Api::simpanTransaksi(), supaya
        // kedua alur (buat baru & edit) tidak lagi punya kalkulasi
        // yang terduplikasi/berpotensi mencong satu sama lain.
        // ==========================================

        $persenDiskonPelanggan = null;

        if ($diskonPelangganAktif && $finalPelangganId !== null) {
            $pelangganUntukDiskon = $pelangganModel->find($finalPelangganId);
            $persenMaster = $pelangganUntukDiskon ? (float) ($pelangganUntukDiskon['diskon'] ?? 0) : 0;

            if ($persenMaster > 0) {
                $persenDiskonPelanggan = $persenMaster;
            }
        }

        $kalkulasi = \App\Services\KalkulasiDiskonTransaksi::hitung($subtotal, $persenDiskonPelanggan, $diskon);
        $diskon = $kalkulasi['diskon'];
        $grandTotal = $kalkulasi['grand_total'];
        $selisihPembulatan = $kalkulasi['selisih_pembulatan'];
        $diskonPelangganPersenTersimpan = $kalkulasi['diskon_pelanggan_persen'];

        // ==========================================
        // 9. BENTUK DETAIL TRANSAKSI
        // ==========================================
        //
        // Catatan arsitektur (lihat docs/aturan-bisnis-AULIA.md
        // Section 6, 7, 12, 16, 22):
        //
        // - Edit transaksi TIDAK PERNAH mencatat refund otomatis.
        //   Refund adalah proses tersendiri (Kas Keluar > kategori
        //   'refund_penjualan'), diputuskan manual oleh kasir.
        // - total_dibayar & status_pembayaran BUKAN dihitung manual
        //   di sini. Keduanya cache/denormalisasi dari
        //   SUM(pembayaran WHERE status='aktif') — sumber kebenaran
        //   tunggalnya adalah TransaksiModel::sinkronkanPembayaran(),
        //   dipanggil setelah grand_total baru tersimpan (lihat step 11).
        // - Kalau grand_total baru < total pembayaran aktif yang sudah
        //   ada, itu SAH: hasilnya kelebihan bayar (status tetap
        //   'lunas'), bukan kondisi error. Kelebihan bayar dihitung
        //   di response, ditampilkan di UI, ditindaklanjuti manual.

        $detailItems = [];

        foreach ($keranjang as $item) {
            $detailItems[] = [
                'produk_id'    => (int) ($item['produk_id'] ?? 1),
                'nama_produk'  => (string) ($item['nama'] ?? ''),
                'kategori_id'  => (int) ($item['kategori_id'] ?? 1),
                'jumlah'       => (float) ($item['jumlah'] ?? 1),
                'harga_satuan' => (float) ($item['harga'] ?? 0),
                'subtotal'     => (float) ($item['subtotal'] ?? 0),
                'catatan'      => \App\Models\DetailTransaksiModel::catatanBanner($item)
            ];
        }

        // ==========================================
        // 10. SIMPAN DALAM SATU TRANSAKSI DATABASE
        // ==========================================

        $noOrderLockAcquired = false;
        $noOrderLockName = null;

        try {
            /*
             * GET_LOCK bersifat connection-level pada MySQL. Lock harus
             * didapat SEBELUM cek conflict dan dipertahankan sampai commit,
             * supaya edit transaksi dan create transaksi tidak bisa
             * melewati cek secara bersamaan.
             */
            if (!empty($noOrder) && $db->getPlatform() === 'MySQLi') {
                $noOrderLockName = 'auliapos:no_order:' . (int) $noOrder;

                $lockResult = $db->query(
                    'SELECT GET_LOCK(?, 10) AS acquired',
                    [$noOrderLockName]
                )->getRowArray();

                if ((int) ($lockResult['acquired'] ?? 0) !== 1) {
                    throw new \RuntimeException(
                        'No Order ' . (int) $noOrder . ' sedang diproses oleh kasir lain. Silakan coba lagi.',
                        409
                    );
                }

                $noOrderLockAcquired = true;
            }

            if (!empty($noOrder) && $this->noOrderDipakaiTransaksiAktifLain(
                $transaksiModel,
                (int) $id,
                (int) $noOrder
            )) {
                throw new \RuntimeException(
                    'No Order ' . (int) $noOrder . ' sedang digunakan oleh transaksi aktif.',
                    409
                );
            }

            $db->transStart();

            // --------------------------------------
            // Update transaksi existing
            //
            // total_dibayar & status_pembayaran SENGAJA tidak
            // diisi di sini — akan diisi oleh sinkronkanPembayaran()
            // setelah grand_total baru ini tersimpan.
            // --------------------------------------

            $transaksiModel->update($id, [
                'no_order'                 => $noOrder,
                'pelanggan_id'             => $finalPelangganId,
                'subtotal'                 => $subtotal,
                'diskon'                   => $diskon,
                'diskon_pelanggan_persen'  => $diskonPelangganPersenTersimpan,
                'pajak'                    => 0,
                'grand_total'              => $grandTotal,
                'selisih_pembulatan'       => $selisihPembulatan,
            ]);

            // --------------------------------------
            // Hapus detail lama
            // --------------------------------------

            $detailModel
                ->where('transaksi_id', $id)
                ->delete();

            // --------------------------------------
            // Simpan detail baru
            // --------------------------------------

            foreach ($detailItems as $item) {
                $item['transaksi_id'] = (int) $id;
                $detailModel->insert($item);
            }

            // --------------------------------------
            // 11. SINKRONKAN total_dibayar & status_pembayaran
            //
            // Sumber kebenaran tunggal: SUM(pembayaran aktif) vs
            // grand_total yang baru saja disimpan di atas.
            // --------------------------------------

            $sinkron = $transaksiModel->sinkronkanPembayaran($id);
            $totalDibayar = (float) $sinkron['total_dibayar'];
            $statusPembayaran = $sinkron['status_pembayaran'];

            // --------------------------------------
            // Selesaikan transaksi database
            // --------------------------------------

            $db->transComplete();

            if (!$db->transStatus()) {
                throw new \Exception(
                    'Gagal menyelesaikan update transaksi.'
                );
            }

            if ($noOrderLockAcquired) {
                $db->query(
                    'SELECT RELEASE_LOCK(?)',
                    [$noOrderLockName]
                );

                $noOrderLockAcquired = false;
            }

            // --------------------------------------
            // Response
            // --------------------------------------

            $sisaTagihan = max(0, $grandTotal - $totalDibayar);
            $kelebihanBayar = max(0, $totalDibayar - $grandTotal);

            return $this->response->setJSON([
                'status'                         => 'success',
                'message'                        => $kelebihanBayar > 0
                    ? 'Transaksi berhasil diperbarui. Ada kelebihan bayar Rp'
                    . number_format($kelebihanBayar, 0, ',', '.')
                    . ' — refund fisik (jika perlu) dicatat manual lewat Kas Keluar.'
                    : 'Transaksi berhasil diperbarui.',
                'transaksi_id'                   => (int) $id,
                'invoice'                        => $transaksi['kode_invoice'],
                'grand_total'                    => $grandTotal,
                'grand_total_sebelum_pembulatan' => $grandTotal + $selisihPembulatan,
                'selisih_pembulatan'             => $selisihPembulatan,
                'total_dibayar'                  => $totalDibayar,
                'sisa_tagihan'                   => $sisaTagihan,
                'kelebihan_bayar'                => $kelebihanBayar,
                'status_pembayaran'              => $statusPembayaran,
                'redirect'                       => base_url(
                    '/transaksi/detail/' . $id
                )
            ]);
        } catch (\Throwable $e) {
            $db->transRollback();

            if ($noOrderLockAcquired) {
                $db->query(
                    'SELECT RELEASE_LOCK(?)',
                    [$noOrderLockName]
                );
            }

            log_message(
                'error',
                'Error updateTransaksi: ' . $e->getMessage()
            );

            log_message(
                'error',
                $e->getTraceAsString()
            );

            $statusCode = (int) $e->getCode();
            if ($statusCode < 400 || $statusCode > 599) {
                $statusCode = 500;
            }

            return $this->response
                ->setStatusCode($statusCode)
                ->setJSON([
                    'status'  => 'error',
                    'message' => 'Gagal memperbarui transaksi: ' .
                        $e->getMessage()
                ]);
        }
    }

    /**
     * Terapkan aturan no_order pada edit sama seperti jalur buat baru:
     * - Studio/Foto (kategori 16 / is_cetak) -> no_order wajib.
     * - Non-Studio/Foto -> no_order harus kosong.
     *
     * @return string|null Pesan validasi, atau null jika valid.
     */
    private function validasiNoOrderEdit(
        ?int $noOrder,
        bool $hasKategori16
    ): ?string {
        if ($hasKategori16 && empty($noOrder)) {
            return 'Transaksi mengandung produk Studio/Foto. No Order WAJIB diisi!';
        }

        if (!$hasKategori16 && !empty($noOrder)) {
            return 'Transaksi TIDAK mengandung produk Studio/Foto. No Order harus dikosongkan!';
        }

        return null;
    }

    /**
     * Cek apakah no_order dipakai transaksi aktif LAIN.
     * Transaksi yang sedang diedit sendiri dikecualikan.
     */
    private function noOrderDipakaiTransaksiAktifLain(
        TransaksiModel $transaksiModel,
        int $id,
        int $noOrder
    ): bool {
        return $transaksiModel
            ->where('no_order', $noOrder)
            ->where('id !=', $id)
            ->whereIn('status', ['proses', 'selesai', 'mangkrak'])
            ->first() !== null;
    }
}
