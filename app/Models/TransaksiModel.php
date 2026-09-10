<?php

namespace App\Models;

use CodeIgniter\Model;
use App\Services\KalkulasiStatusPembayaran;

class TransaksiModel extends Model
{
    protected $table = 'transaksi';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'kode_invoice',
        'no_order',
        'tanggal',
        'pelanggan_id',
        'kasir_id',
        'subtotal',
        'diskon',
        'diskon_pelanggan_persen',
        'pajak',
        'grand_total',
        'selisih_pembulatan',
        'total_dibayar',
        'status_pembayaran',
        'status',
        'sumber',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    /**
     * Semua nilai kolom `status` transaksi yang valid.
     * Dipakai sebagai gate validasi awal di ubahStatus() dan
     * Api::ubahStatus(); validasi transisi lifecycle tetap di ubahStatus().
     */
    public const STATUS = ['proses', 'selesai', 'batal', 'mangkrak'];
    /**
     * Ubah status transaksi sesuai lifecycle bisnis.
     *
     * Lifecycle:
     * - proses  -> selesai (hanya admin, DAN status_pembayaran harus lunas)
     * - proses  -> batal
     * - selesai -> final (kecuali admin membatalkan)
     * - batal   -> final
     *
     * SELESAI = garapan clear DAN pembayaran lunas. Pembayaran lunas
     * TIDAK otomatis membuat transaksi menjadi selesai; admin tetap
     * harus menekan tombol Selesai secara eksplisit.
     */
    /**
     * Ubah status transaksi.
     *
     * @param int    $id
     * @param string $status
     * @param bool   $isAdmin Wajib true untuk transisi PROSES -> SELESAI,
     *                        dan untuk transisi SELESAI -> BATAL
     *                        (lihat docs/aturan-bisnis-AULIA.md Section 2
     *                        dan perubahan-alur-status-transaksi.md).
     */
    public function ubahStatus($id, $status, bool $isAdmin = false, bool $izinSelesaikanKonteks = false)
    {
        $status = strtolower(trim((string) $status));

        if (!in_array($status, self::STATUS, true)) {
            throw new \Exception('Status transaksi tidak valid.');
        }

        $transaksi = $this->find($id);

        if (!$transaksi) {
            throw new \Exception('Transaksi tidak ditemukan.');
        }

        $statusSaatIni = strtolower(trim((string) ($transaksi['status'] ?? '')));

        // Status final tidak boleh dipindahkan ke status lain,
        // KECUALI: admin membatalkan transaksi yang sudah SELESAI
        // (lihat docs/aturan-bisnis-AULIA.md Section 2), ATAU admin
        // menandainya MANGKRAK -- ini bisa terjadi kalau transaksi
        // sempat SELESAI (mensyaratkan lunas saat itu) tapi kemudian
        // pembayarannya di-reversal lewat Api::koreksiPembayaran(),
        // membuat status_pembayaran turun lagi ke belum_bayar/dp tanpa
        // status transaksi ikut berubah (tidak ada mekanisme otomatis
        // yang mengembalikan SELESAI ke PROSES). Sama seperti
        // PROSES->MANGKRAK, ini admin-only.
        if ($statusSaatIni === 'selesai') {
            if ($status === 'selesai') {
                return true;
            }

            if ($status === 'batal' && $isAdmin) {
                $data = [
                    'status'   => 'batal',
                    'no_order' => null,
                ];

                if (!$this->update($id, $data)) {
                    throw new \Exception('Gagal mengubah status transaksi.');
                }

                return true;
            }

            if ($status === 'mangkrak') {
                if (!$isAdmin) {
                    throw new \Exception(
                        'Hanya admin yang dapat menandai transaksi SELESAI sebagai mangkrak.'
                    );
                }

                $statusBayar = strtolower(trim((string) ($transaksi['status_pembayaran'] ?? '')));

                if ($statusBayar === 'lunas') {
                    throw new \Exception(
                        'Transaksi ini sudah SELESAI dan LUNAS -- tidak ada yang perlu ditandai mangkrak.'
                    );
                }

                if (!$this->update($id, ['status' => 'mangkrak'])) {
                    throw new \Exception('Gagal menandai transaksi sebagai mangkrak.');
                }

                return true;
            }

            throw new \Exception(
                $status === 'batal'
                    ? 'Hanya admin yang dapat membatalkan transaksi yang sudah SELESAI.'
                    : 'Transaksi yang sudah SELESAI tidak dapat diubah statusnya.'
            );
        }

        if ($statusSaatIni === 'batal') {
            if ($status === 'batal') {
                return true;
            }

            throw new \Exception(
                'Transaksi yang sudah BATAL tidak dapat diaktifkan kembali.'
            );
        }

        // MANGKRAK: transaksi yang BENERAN terjadi (beda dari 'batal'
        // yang berarti "dianggap tidak pernah terjadi") tapi macet
        // tanpa kejelasan -- belum dibayar, tidak diambil, dst. Satu-
        // satunya jalan keluar dari status ini adalah diaktifkan
        // kembali ke 'proses' (mis. pelanggan akhirnya muncul lagi) --
        // TIDAK bisa langsung ke 'selesai'/'batal' dari sini, harus
        // lewat 'proses' dulu supaya tetap melalui validasi normal
        // (pelunasan, dst). Admin-only (baik menandai maupun
        // mengaktifkan kembali) -- keputusan "lepas dari radar aktif"
        // maupun "masukkan lagi" sengaja tidak dibuka untuk semua role.
        if ($statusSaatIni === 'mangkrak') {
            if ($status === 'mangkrak') {
                return true;
            }

            if ($status === 'proses') {
                if (!$isAdmin) {
                    throw new \Exception(
                        'Hanya admin yang dapat mengaktifkan kembali transaksi MANGKRAK.'
                    );
                }

                if (!$this->update($id, ['status' => 'proses'])) {
                    throw new \Exception('Gagal mengaktifkan kembali transaksi.');
                }

                return true;
            }

            throw new \Exception(
                'Transaksi MANGKRAK harus diaktifkan kembali ke PROSES dulu sebelum diubah ke status lain.'
            );
        }

        // Hanya transaksi PROSES yang boleh menuju status berikutnya.
        if ($statusSaatIni !== 'proses') {
            throw new \Exception(
                'Status transaksi saat ini tidak dapat diubah.'
            );
        }

        // PROSES -> MANGKRAK: admin-only (beda dari PROSES -> BATAL
        // yang terbuka untuk semua role) -- keputusan melepas
        // transaksi dari radar aktif Tagihan/notifikasi sengaja
        // dipegang admin, bukan sembarang kasir. Tidak ada syarat
        // status pembayaran (justru kasus paling umum adalah belum
        // dibayar sama sekali).
        if ($status === 'mangkrak') {
            if (!$isAdmin) {
                throw new \Exception(
                    'Hanya admin yang dapat menandai transaksi sebagai mangkrak.'
                );
            }

            if (!$this->update($id, ['status' => 'mangkrak'])) {
                throw new \Exception('Gagal menandai transaksi sebagai mangkrak.');
            }

            return true;
        }

        // PROSES -> SELESAI mensyaratkan garapan benar-benar clear:
        // hanya admin yang boleh menyelesaikan, DAN pembayaran harus
        // sudah LUNAS. 'lunas' tidak pernah otomatis menjadi 'selesai';
        // tombol Selesai tetap harus ditekan secara eksplisit.
        // (lihat docs/aturan-bisnis-AULIA.md Section 5 & 22, serta
        // perubahan-alur-status-transaksi.md)
        //
        // $izinSelesaikanKonteks = kapabilitas KHUSUS KONTEKS: kasir
        // boleh menyelesaikan transaksi lewat workflow kasir/index.php
        // (POS) untuk transaksi buatannya sendiri. Diberikan HANYA oleh
        // Api::selesaikanTransaksiKasir() yang sudah memeriksa
        // sumber/kepemilikan. Endpoint status umum (Api::ubahStatus,
        // dipakai Detail/Daftar) memakai default false -> kasir tetap
        // ditolak di sana. Syarat LUNAS di bawah berlaku untuk SEMUA
        // jalur, tanpa kecuali.
        if ($status === 'selesai') {
            if (!$isAdmin && !$izinSelesaikanKonteks) {
                throw new \Exception(
                    'Hanya admin yang dapat menyelesaikan transaksi.'
                );
            }

            // Re-sync dulu supaya validasi tidak memakai cache
            // status_pembayaran yang mungkin basi (lihat riwayat bug
            // di Section 7 dokumentasi aturan bisnis).
            $pembayaran = $this->sinkronkanPembayaran($id);

            if ($pembayaran['status_pembayaran'] !== 'lunas') {
                $sisa = max(
                    0,
                    (float) ($transaksi['grand_total'] ?? 0) - $pembayaran['total_dibayar']
                );

                throw new \Exception(
                    'Transaksi belum dapat diselesaikan karena pembayaran belum lunas. '
                        . 'Sisa pembayaran: Rp' . number_format($sisa, 0, ',', '.')
                );
            }
        }

        $data = [
            'status' => $status,
        ];

        // Transaksi yang dibatalkan tidak lagi memiliki nomor order aktif.
        if ($status === 'batal') {
            $data['no_order'] = null;
        }

        if (!$this->update($id, $data)) {
            throw new \Exception('Gagal mengubah status transaksi.');
        }

        return true;
    }
    private function generateKodeInvoiceRetry()
    {
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

            $exists = $this
                ->where(
                    'kode_invoice',
                    $kodeInvoice
                )
                ->first();
        } while ($exists);

        return $kodeInvoice;
    }

    public function simpanTransaksi($dataTransaksi, $detailItems)
    {

        // Semua transaksi baru selalu dimulai dari PROSES.
        // Status pembayaran dihitung terpisah dari lifecycle transaksi.
        $dataTransaksi['status'] = 'proses';
        $db = \Config\Database::connect();

        /*
    |--------------------------------------------------------------------------
    | Maksimal percobaan jika terjadi collision kode_invoice
    |--------------------------------------------------------------------------
    */
        $maxAttempt = 5;

        for ($attempt = 1; $attempt <= $maxAttempt; $attempt++) {

            $db->transStart();

            try {

                /*
            |--------------------------------------------------------------------------
            | 1. Simpan transaksi utama
            |--------------------------------------------------------------------------
            */

                $transaksiId = $this->insert(
                    $dataTransaksi,
                    true
                );

                /*
            |--------------------------------------------------------------------------
            | Jika INSERT gagal
            |--------------------------------------------------------------------------
            */

                if (!$transaksiId) {

                    $dbError = $this->db->error();

                    /*
                |--------------------------------------------------------------------------
                | Collision kode_invoice
                |--------------------------------------------------------------------------
                */

                    if (
                        isset($dbError['code']) &&
                        (int) $dbError['code'] === 1062 &&
                        isset($dbError['message']) &&
                        strpos(
                            $dbError['message'],
                            'kode_invoice'
                        ) !== false
                    ) {

                        /*
                    | Batalkan transaction yang gagal.
                    */
                        $db->transRollback();

                        /*
                    | Buat kode invoice baru.
                    */
                        $dataTransaksi['kode_invoice'] =
                            $this->generateKodeInvoiceRetry();

                        /*
                    | Coba INSERT lagi.
                    */
                        continue;
                    }

                    /*
                |--------------------------------------------------------------------------
                | Error database lainnya
                |--------------------------------------------------------------------------
                */

                    throw new \Exception(
                        'Gagal mendapatkan ID transaksi. '
                            . 'DB Error: '
                            . ($dbError['code'] ?? '-')
                            . ' - '
                            . ($dbError['message'] ?? 'Unknown database error')
                    );
                }


                /*
            |--------------------------------------------------------------------------
            | 2. Simpan detail transaksi
            |--------------------------------------------------------------------------
            */

                $detailModel = model(
                    DetailTransaksiModel::class
                );



                foreach ($detailItems as $item) {

                    $item['transaksi_id'] =
                        $transaksiId;


                    $detailId =
                        $detailModel->insert($item);


                    /*
                |--------------------------------------------------------------------------
                | Pastikan detail berhasil disimpan
                |--------------------------------------------------------------------------
                */

                    if (!$detailId) {

                        $detailError =
                            $detailModel->db->error();

                        throw new \Exception(
                            'Gagal menyimpan detail transaksi. '
                                . 'DB Error: '
                                . ($detailError['code'] ?? '-')
                                . ' - '
                                . ($detailError['message'] ?? 'Unknown database error')
                        );
                    }
                }


                /*
            |--------------------------------------------------------------------------
            | 3. Selesaikan transaction database
            |--------------------------------------------------------------------------
            */

                $db->transComplete();


                if (!$db->transStatus()) {

                    throw new \Exception(
                        'Gagal menyimpan transaksi.'
                    );
                }


                /*
            |--------------------------------------------------------------------------
            | 5. Berhasil
            |--------------------------------------------------------------------------
            */

                return $transaksiId;
            } catch (\Throwable $e) {

                /*
            |--------------------------------------------------------------------------
            | Pastikan transaction dibatalkan
            |--------------------------------------------------------------------------
            */

                $db->transRollback();


                /*
            |--------------------------------------------------------------------------
            | Beberapa kondisi database bisa melempar
            | exception langsung untuk duplicate key.
            |--------------------------------------------------------------------------
            */

                $message =
                    $e->getMessage();


                if (
                    strpos(
                        $message,
                        'Duplicate entry'
                    ) !== false
                    &&
                    strpos(
                        $message,
                        'kode_invoice'
                    ) !== false
                ) {

                    /*
                | Buat kode invoice baru.
                */
                    $dataTransaksi['kode_invoice'] =
                        $this->generateKodeInvoiceRetry();

                    /*
                | Coba lagi.
                */
                    continue;
                }


                /*
            |--------------------------------------------------------------------------
            | Error lain tidak diulang.
            |--------------------------------------------------------------------------
            */

                throw $e;
            }
        }


        /*
    |--------------------------------------------------------------------------
    | Semua percobaan gagal
    |--------------------------------------------------------------------------
    */

        throw new \Exception(
            'Gagal menyimpan transaksi setelah '
                . $maxAttempt
                . ' percobaan karena kode invoice selalu bentrok.'
        );
    }

    /**
     * Sinkronkan total pembayaran efektif transaksi.
     *
     * Hanya pembayaran dengan status "aktif" yang dihitung.
     * Pembayaran "reversed" tetap tersimpan sebagai histori, tetapi
     * tidak memengaruhi total pembayaran maupun status pembayaran.
     */
    public function sinkronkanPembayaran($transaksi_id): array
    {
        $pembayaranModel = model(PembayaranModel::class);

        $transaksi = $this->find($transaksi_id);

        if (!$transaksi) {
            throw new \Exception('Transaksi tidak ditemukan.');
        }

        $totalDibayar = (float) $pembayaranModel->getTotalDibayar($transaksi_id);
        $grandTotal = (float) ($transaksi['grand_total'] ?? 0);

        $status = KalkulasiStatusPembayaran::hitung($totalDibayar, $grandTotal);

        $this->update($transaksi_id, [
            'total_dibayar' => $totalDibayar,
            'status_pembayaran' => $status,
        ]);

        return [
            'total_dibayar' => $totalDibayar,
            'status_pembayaran' => $status,
        ];
    }

    /**
     * Cek konsistensi cache total_dibayar dengan pembayaran aktif.
     */
    public function cekKonsistensiPembayaran($transaksi_id): array
    {
        $pembayaranModel = model(PembayaranModel::class);

        $transaksi = $this->find($transaksi_id);

        if (!$transaksi) {
            throw new \Exception('Transaksi tidak ditemukan.');
        }

        $totalAktual = (float) $pembayaranModel->getTotalDibayar($transaksi_id);
        $totalCache = (float) ($transaksi['total_dibayar'] ?? 0);

        return [
            'konsisten' => abs($totalAktual - $totalCache) < 0.0001,
            'total_cache' => $totalCache,
            'total_aktual' => $totalAktual,
            'selisih' => $totalAktual - $totalCache,
        ];
    }

    /**
     * TAMBAH PEMBAYARAN — VERSI BARU (TANPA LEDGER & SESSION)
     * 
     * Cukup simpan pembayaran dan update status transaksi.
     * Saldo kas sistem dihitung REAL TIME dari tabel pembayaran + cash_expense.
     *
     * @param bool $isAdmin Wajib true jika $data['tanggal'] adalah
     *                       backdate (tanggal signifikan berbeda dari
     *                       sekarang) — lihat blok BACKDATE di bawah.
     *                       Untuk pembayaran normal (tanggal = sekarang)
     *                       flag ini tidak berpengaruh.
     */
    public function tambahPembayaran($transaksi_id, $data, bool $isAdmin = false)
    {
        $db = \Config\Database::connect();
        $pembayaranModel = model(PembayaranModel::class);

        $transaksi = $this->find($transaksi_id);

        if (!$transaksi) {
            throw new \Exception('Transaksi tidak ditemukan.');
        }

        $jumlah = (float) ($data['jumlah'] ?? 0);
        $metode = strtolower(trim((string) ($data['metode'] ?? '')));

        if ($jumlah < 0) {
            throw new \Exception('Jumlah pembayaran tidak boleh negatif.');
        }

        // Chokepoint tunggal semua penulisan pembayaran (POS, tambah
        // pembayaran, pelunasan tagihan, koreksi metode). Metode wajib
        // salah satu nilai yang dikenal aplikasi = kolom ENUM DB.
        if (!in_array($metode, PembayaranModel::METODE, true)) {
            throw new \Exception('Metode pembayaran tidak valid.');
        }

        $totalSebelum = (float) $pembayaranModel->getTotalDibayar($transaksi_id);
        $grandTotal = (float) ($transaksi['grand_total'] ?? 0);
        $sisa = max(0, $grandTotal - $totalSebelum);

        if ($jumlah > $sisa + 0.0001) {
            throw new \Exception('Jumlah pembayaran melebihi sisa tagihan.');
        }

        $uangDiterima = array_key_exists('uang_diterima', $data)
            ? ($data['uang_diterima'] !== null ? (float) $data['uang_diterima'] : null)
            : null;

        $kembalian = array_key_exists('kembalian', $data)
            ? (float) ($data['kembalian'] ?? 0)
            : 0;

        if ($metode === 'tunai') {
            if ($uangDiterima === null) {
                $uangDiterima = $jumlah;
            }

            if ($uangDiterima + 0.0001 < $jumlah) {
                throw new \Exception('Uang diterima lebih kecil dari nominal pembayaran.');
            }

            $kembalian = max(0, $uangDiterima - $jumlah);
        } else {
            $uangDiterima = null;
            $kembalian = 0;
        }

        /*
         * ------------------------------------------------------------
         * BACKDATE / PELUNASAN TERLAMBAT (2026-09-05)
         * ------------------------------------------------------------
         * Kontrak: caller (controller) SELALU mengisi $data['tanggal'].
         * - Pembayaran normal: controller mengisi date('Y-m-d H:i:s')
         *   (waktu saat ini) — sama seperti sebelum fitur ini ada.
         * - Backdate: controller mengisi tanggal yang dipilih admin.
         *
         * Model memvalidasi rentang tanggal untuk SEMUA pembayaran
         * (defensif, satu sumber kebenaran), dan mewajibkan admin
         * hanya jika tanggal yang diberikan BUKAN "sekarang" (selisih
         * > 60 detik, mentolerir jeda proses request normal).
         *
         * Ini menghindari perlunya flag 'is_backdate' terpisah yang
         * bisa lupa disinkronkan dengan tanggal yang sebenarnya dikirim.
         */

        $tanggalInput = $data['tanggal'] ?? date('Y-m-d H:i:s');
        $tanggalTimestamp = strtotime((string) $tanggalInput);

        if ($tanggalTimestamp === false) {
            throw new \Exception('Format tanggal pembayaran tidak valid.');
        }

        $sekarangTimestamp = time();
        $toleransiDetik = 60;
        $isBackdate = abs($sekarangTimestamp - $tanggalTimestamp) > $toleransiDetik;

        if ($isBackdate && !$isAdmin) {
            throw new \Exception(
                'Hanya admin yang dapat mencatat pembayaran dengan tanggal berbeda (backdate).'
            );
        }

        if ($tanggalTimestamp > $sekarangTimestamp + $toleransiDetik) {
            throw new \Exception('Tanggal pembayaran tidak boleh di masa depan.');
        }

        if ($isBackdate) {
            $tanggalTransaksiTimestamp = strtotime((string) ($transaksi['tanggal'] ?? date('Y-m-d H:i:s')));

            /*
             * Perbandingan level TANGGAL (hari), bukan timestamp
             * lengkap. Jam pembayaran boleh lebih awal dari jam
             * transaksi selama masih di hari yang sama — ini justru
             * skenario utama fitur ini: uang diterima lebih dulu,
             * transaksinya baru dicatat belakangan di hari yang sama
             * atau setelahnya. Hanya TANGGAL yang lebih awal dari
             * tanggal transaksi yang ditolak (lihat contoh di dokumen
             * fitur: "Transaksi 01/09, valid 01/09/02/09/03/09,
             * tidak valid 31/08").
             */
            if ($tanggalTransaksiTimestamp !== false) {
                $tanggalPembayaranHari = date('Y-m-d', $tanggalTimestamp);
                $tanggalTransaksiHari = date('Y-m-d', $tanggalTransaksiTimestamp);

                if ($tanggalPembayaranHari < $tanggalTransaksiHari) {
                    throw new \Exception('Tanggal pembayaran tidak boleh sebelum tanggal transaksi.');
                }
            }
        }

        $data['tanggal'] = date('Y-m-d H:i:s', $tanggalTimestamp);

        /*
         * ------------------------------------------------------------
         * KASIR PENERIMA
         * ------------------------------------------------------------
         * $data['kasir_id'] SELALU diisi oleh caller (controller) —
         * baik pembayaran normal (session kasir yang login) maupun
         * backdate (kasir yang dipilih admin sebagai penerima aktual).
         * Model tidak mengganti nilai ini dengan admin yang login;
         * kasir_id tetap merepresentasikan siapa yang benar-benar
         * menangani pembayaran, sesuai definisi field yang sudah ada.
         */

        $data['transaksi_id'] = $transaksi_id;
        $data['jumlah'] = $jumlah;
        $data['uang_diterima'] = $uangDiterima;
        $data['kembalian'] = $kembalian;
        $data['status'] = 'aktif';

        $db->transStart();

        try {
            $pembayaranModel->insert($data);

            if ($db->transStatus() === false) {
                throw new \Exception('Gagal menyimpan pembayaran.');
            }

            $this->sinkronkanPembayaran($transaksi_id);

            $db->transComplete();

            if (!$db->transStatus()) {
                throw new \Exception('Gagal menyelesaikan transaksi pembayaran.');
            }

            return true;
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }
    }

    /**
     * GET MERGED CHILDREN — tetap
     */
    public function getMergedChildren($masterId)
    {
        return $this->where('merged_into', $masterId)->findAll();
    }

    /**
     * MERGE TRANSACTIONS — tetap
     */
    public function mergeTransactions($ids, $dataMaster)
    {
        $db = \Config\Database::connect();
        $db->transStart();

        try {
            $this->insert($dataMaster);
            $masterId = $this->insertID();

            $this->whereIn('id', $ids)
                ->set(['merged_into' => $masterId])
                ->update();

            $detailModel = model(DetailTransaksiModel::class);

            foreach ($ids as $id) {
                $detailModel->where('transaksi_id', $id)
                    ->set(['transaksi_id' => $masterId])
                    ->update();
            }

            $db->transComplete();

            if (!$db->transStatus()) {
                throw new \Exception('Gagal menggabungkan transaksi.');
            }

            return $masterId;
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }
    }
}
