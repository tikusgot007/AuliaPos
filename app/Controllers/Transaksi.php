<?php

namespace App\Controllers;

use App\Models\TransaksiModel;
use App\Models\DetailTransaksiModel;
use App\Models\PembayaranModel;
use App\Models\PelangganModel;
use App\Models\ProdukModel;
use App\Models\KategoriModel;

class Transaksi extends BaseController
{
    public function index()
    {
        $model = new TransaksiModel();
        $pelangganModel = new PelangganModel();

        /*
    |--------------------------------------------------------------------------
    | FILTER TANGGAL
    |--------------------------------------------------------------------------
    |
    | Default: 7 hari terakhir sampai hari ini.
    |
    | Jika user memilih tanggal sendiri, gunakan tanggal tersebut.
    |
    */

        [$tanggal_awal, $tanggal_akhir] = $this->getDateRange();


        /*
    |--------------------------------------------------------------------------
    | FILTER STATUS PEMBAYARAN (2026-09-09, dikelompokkan)
    |--------------------------------------------------------------------------
    |
    | ''            = Semua
    | belum_lunas   = belum_bayar ATAU dp
    | lunas         = lunas
    |
    | Kompatibilitas: value individual lama ('belum_bayar', 'dp')
    | tetap didukung di backend (exact match) walau tidak lagi
    | ditawarkan sebagai pilihan di dropdown UI.
    |
    */

        $status_pembayaran =
            $this->request->getGet('status_pembayaran') ?? '';


        /*
    |--------------------------------------------------------------------------
    | FILTER STATUS TRANSAKSI (2026-09-09, dikelompokkan)
    |--------------------------------------------------------------------------
    |
    | ''            = Semua (proses + selesai + batal + mangkrak, tidak difilter)
    | aktif         = proses ATAU selesai
    | tidak_aktif   = batal ATAU mangkrak
    |
    | Default saat parameter TIDAK dikirim sama sekali (pertama kali
    | buka /transaksi/index) = 'aktif'. Kalau parameter dikirim
    | eksplisit sebagai string kosong (mis. dari link lama), tetap
    | diperlakukan sebagai Semua, bukan di-override ke default.
    |
    | Catatan perubahan makna 'aktif' (menggantikan definisi P10):
    | sebelumnya 'aktif' berarti "bukan batal" (backward-compat untuk
    | link lama, dari sebelum status 'mangkrak' ada). Sekarang 'aktif'
    | didefinisikan eksplisit sebagai proses+selesai saja (mangkrak
    | masuk kelompok 'tidak_aktif'). Tidak berdampak ke data historis
    | karena status 'mangkrak' baru dibuat di tanggal yang sama dengan
    | perubahan ini (lihat docs Section 29).
    |
    | Value individual lama ('proses', 'selesai', 'batal', 'mangkrak')
    | tetap didukung di backend untuk jaga-jaga ada link lama, walau
    | tidak lagi ditawarkan sebagai pilihan di dropdown UI.
    |
    */

        $status_transaksi =
            $this->request->getGet('status_transaksi');

        if ($status_transaksi === null) {
            // Halaman dibuka tanpa parameter filter sama sekali -> default Aktif.
            $status_transaksi = 'aktif';
        }


        /*
    |--------------------------------------------------------------------------
    | FILTER PELANGGAN
    |--------------------------------------------------------------------------
    */

        $pelanggan_filter =
            $this->request->getGet('pelanggan') ?? '';


        /*
    |--------------------------------------------------------------------------
    | KEYWORD
    |--------------------------------------------------------------------------
    |
    | Digunakan untuk mencari:
    | - kode invoice
    | - no order
    | - nama pelanggan
    |
    | Jika keyword diisi, pencarian tidak dibatasi
    | oleh default periode 3 bulan.
    |
    */

        $keyword =
            trim(
                (string) (
                    $this->request->getGet('keyword') ?? ''
                )
            );


        /*
    |--------------------------------------------------------------------------
    | QUERY UTAMA
    |--------------------------------------------------------------------------
    */

        $builder = $model
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
             pelanggan.nama AS pelanggan_nama'
            )
            ->join(
                'users',
                'users.id = transaksi.kasir_id',
                'left'
            )
            ->join(
                'pelanggan',
                'pelanggan.id = transaksi.pelanggan_id',
                'left'
            )
            ->orderBy(
                'transaksi.id',
                'DESC'
            );


        /*
    |--------------------------------------------------------------------------
    | FILTER TANGGAL
    |--------------------------------------------------------------------------
    |
    | Jika TIDAK ada keyword:
    | gunakan filter tanggal.
    |
    | Jika ADA keyword:
    | pencarian berlaku ke seluruh histori.
    |
    */

        $this->applyDateFilter(
            $builder,
            $keyword,
            $tanggal_awal,
            $tanggal_akhir
        );


        /*
    |--------------------------------------------------------------------------
    | FILTER STATUS PEMBAYARAN + STATUS TRANSAKSI
    |--------------------------------------------------------------------------
    |
    | Lihat Transaksi::applyStatusFilters() -- pemetaan nilai filter
    | (termasuk kompatibilitas mundur & arti "Semua") tidak berubah,
    | hanya dipindah supaya index() lebih ringkas.
    |
    */

        $this->applyStatusFilters(
            $builder,
            $status_pembayaran,
            $status_transaksi
        );


        /*
    |--------------------------------------------------------------------------
    | FILTER PELANGGAN
    |--------------------------------------------------------------------------
    */

        if ($pelanggan_filter !== '') {

            $builder->where(
                'transaksi.pelanggan_id',
                $pelanggan_filter
            );
        }


        /*
    |--------------------------------------------------------------------------
    | FILTER KEYWORD
    |--------------------------------------------------------------------------
    |
    | Lihat Transaksi::applyKeywordFilter() -- kondisi pencarian tidak
    | berubah, hanya dipindah. Nilai balik ($parsedNoOrder) dipakai lagi
    | di blok LENGKAPI DENGAN HASIL ARCHIVE di bawah.
    |
    */

        $parsedNoOrder = $this->applyKeywordFilter($builder, $keyword);


        /*
    |--------------------------------------------------------------------------
    | AMBIL DATA
    |--------------------------------------------------------------------------
    |
    | Untuk sementara tetap menggunakan findAll()
    | karena halaman saat ini menggunakan DataTables
    | client-side.
    |
    */

        $transaksi =
            $builder->findAll();

        // Tandai eksplisit sebagai data aktif -- supaya bentuknya
        // konsisten dengan hasil dari archive di bawah (poin 9: UI
        // bisa menampilkan sumber Aktif/Archive per baris).
        foreach ($transaksi as &$row) {
            $row['_sumber'] = 'aktif';
        }
        unset($row);

        /*
    |--------------------------------------------------------------------------
    | LENGKAPI DENGAN HASIL ARCHIVE (HANYA SAAT ADA KEYWORD)
    |--------------------------------------------------------------------------
    |
    | Tanpa keyword, daftar ini memang dibatasi periode tanggal aktif
    | (lihat blok FILTER TANGGAL di atas) -- archive TIDAK ikut di sini
    | karena bukan pencarian, murni daftar transaksi berjalan.
    |
    | Dengan keyword, pencarian "berlaku ke seluruh histori" (sudah jadi
    | komentar existing di atas) -- supaya itu benar-benar utuh, hasil
    | dari database archive ikut digabung di sini (poin 9 spesifikasi
    | Archive Transaksi: transaksi lama yang sudah di-archive tetap
    | harus bisa ditemukan lewat pencarian yang sama).
    |
    */

        if ($keyword !== '') {
            try {
                $archiveService = new \App\Services\TransaksiArchiveService();
                $dariArchive = $archiveService->cariUntukDaftarTransaksi($keyword, $parsedNoOrder ?: null);
                $transaksi = array_merge($transaksi, $dariArchive);
            } catch (\Throwable $e) {
                // Archive gagal diakses TIDAK boleh mematikan pencarian
                // transaksi aktif -- log saja dan lanjut dengan hasil
                // dari DB utama.
                log_message('error', 'Transaksi::index keyword archive gagal: ' . $e->getMessage());
            }
        }


        /*
    |--------------------------------------------------------------------------
    | DAFTAR PELANGGAN
    |--------------------------------------------------------------------------
    */

        $pelanggan_list =
            $pelangganModel
            ->orderBy(
                'nama',
                'ASC'
            )
            ->findAll();


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

            'transaksi' =>
            $transaksi,

            /*
         * Filter tanggal yang sedang aktif
         */
            'tanggal_awal' =>
            $tanggal_awal,

            'tanggal_akhir' =>
            $tanggal_akhir,

            /*
         * Filter pembayaran
         */
            'status_pembayaran' =>
            $status_pembayaran,

            /*
         * Filter transaksi
         */
            'status_transaksi' =>
            $status_transaksi,

            /*
         * Filter pelanggan
         */
            'pelanggan_filter' =>
            $pelanggan_filter,

            /*
         * Keyword
         */
            'keyword' =>
            $keyword,

            /*
         * Daftar pelanggan
         */
            'pelanggan_list' =>
            $pelanggan_list,

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
        \CodeIgniter\Model $builder,
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
     *     'aktif'       -> status IN (proses, selesai)
     *     'tidak_aktif' -> status IN (batal, mangkrak)
     *     'batal' | 'proses' | 'selesai' | 'mangkrak' -> status = <nilai>  (kompat mundur)
     *     ''            -> tidak difilter (Semua)
     *
     * $builder adalah instance Model (di CI4 Model::__call mengembalikan
     * $this saat memproxy method builder), dimutasi by-handle.
     */
    private function applyStatusFilters(
        \CodeIgniter\Model $builder,
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
         * Kelompok Aktif: proses ATAU selesai.
         */

            $builder->whereIn(
                'transaksi.status',
                ['proses', 'selesai']
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
        \CodeIgniter\Model $builder,
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

        if ($selectedNoOrder === null || $selectedNoOrder <= 0) {
            return [];
        }

        $min = max(1, $selectedNoOrder - 20);
        $max = $selectedNoOrder + 20;

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
     * 🔥 Parse No Order dari format huruf (contoh: A4295 → 104295)
     * Jika input bukan format huruf, return null
     */
    private function parseNoOrder($formatted)
    {
        $ambang = 100000;
        $siklus = 9999;

        $formatted = strtoupper(trim($formatted));

        // 🔥 Jika angka murni, return null (tidak perlu parsing)
        if (is_numeric($formatted)) {
            return null;
        }

        // 🔥 Jika format huruf + angka (contoh: A4295, A0001, B0001)
        if (preg_match('/^([A-Z]+)(\d+)$/', $formatted, $matches)) {
            $hurufStr = $matches[1];
            $angkaStr = $matches[2];
            $nomorDalamSiklus = (int)$angkaStr;

            // Konversi huruf ke angka (A=0, B=1, ...)
            $indexSiklus = 0;
            for ($i = 0; $i < strlen($hurufStr); $i++) {
                $indexSiklus = $indexSiklus * 26 + (ord($hurufStr[$i]) - 64);
            }
            $indexSiklus -= 1;

            $posisi = ($indexSiklus * $siklus) + $nomorDalamSiklus;
            return $ambang + $posisi;
        }

        return null;
    }


    public function detail($id)
    {
        $transaksiModel = new TransaksiModel();
        $detailModel = new DetailTransaksiModel();
        $pembayaranModel = new PembayaranModel();
        $pelangganModel = new PelangganModel();

        // Ambil data transaksi
        $transaksi = $transaksiModel->select('transaksi.*, users.username as kasir_nama')
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
            ->select('pembayaran.*, users.nama as kasir_nama, users.username as kasir_username')
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

    public function cetakStruk($id)
    {
        return redirect()->to('/transaksi/detail/' . $id)->with('info', 'Fitur cetak struk sedang dalam pengembangan.');
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

        // ==========================================
        // 5. HITUNG SUBTOTAL
        // ==========================================

        $subtotal = 0;

        foreach ($keranjang as $item) {
            $subtotal += (float) ($item['subtotal'] ?? 0);
        }

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
                'catatan'      => (string) ($item['catatan'] ?? '')
            ];
        }

        // ==========================================
        // 10. SIMPAN DALAM SATU TRANSAKSI DATABASE
        // ==========================================

        $db->transStart();

        try {
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

            log_message(
                'error',
                'Error updateTransaksi: ' . $e->getMessage()
            );

            log_message(
                'error',
                $e->getTraceAsString()
            );

            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'status'  => 'error',
                    'message' => 'Gagal memperbarui transaksi: ' .
                        $e->getMessage()
                ]);
        }
    }

}
