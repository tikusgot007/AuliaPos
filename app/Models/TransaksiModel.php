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
     * @param bool   $isShiftLeader Kapabilitas KHUSUS KONTEKS untuk PROSES
     *                        -> SELESAI di workflow umum: Effective Shift
     *                        Leader saat ini (App\Services\Authority::
     *                        isCurrentShiftLeader()) boleh menyelesaikan
     *                        transaksi yang sebelumnya admin-only di sana.
     *                        TIDAK berlaku untuk transisi lain (batal,
     *                        mangkrak, reaktivasi tetap admin-only persis
     *                        seperti sebelumnya). Diberikan HANYA oleh
     *                        Api::ubahStatus() -- lihat POC Tahap 3.
     */
    public function ubahStatus($id, $status, bool $isAdmin = false, bool $izinSelesaikanKonteks = false, bool $isShiftLeader = false)
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

            // Tahap 5: Shift Leader boleh membatalkan transaksi SELESAI
            // persis seperti admin (kapabilitas KHUSUS KONTEKS, sama
            // pola dengan PROSES->SELESAI di bawah) -- $isShiftLeader
            // sudah jadi parameter method ini sejak Tahap 3.
            if ($status === 'batal' && ($isAdmin || $isShiftLeader)) {
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
                    ? 'Hanya admin atau Shift Leader yang dapat membatalkan transaksi yang sudah SELESAI.'
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

        // PROSES -> MANGKRAK: admin-only (BUKAN dilonggarkan ke Shift
        // Leader seperti SELESAI/BATAL -- lihat catatan di cabang
        // 'selesai' di bawah) -- keputusan melepas transaksi dari
        // radar aktif Tagihan/notifikasi sengaja dipegang admin murni,
        // bukan sembarang kasir maupun Shift Leader. Tidak ada syarat
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
        // dipakai Detail/Daftar) memakai default false untuk parameter
        // ini -> kasir biasa tetap ditolak di sana.
        //
        // $isShiftLeader = kapabilitas KHUSUS KONTEKS lain, KHUSUS untuk
        // workflow umum (Api::ubahStatus): Effective Shift Leader saat
        // ini (dihitung dari users.priority + jadwal + jam shift, lihat
        // App\Services\EffectiveShiftLeaderService/Authority -- BUKAN
        // role permanen, users.role tidak pernah jadi 'shift_leader')
        // boleh menyelesaikan transaksi apa pun di workflow umum yang
        // sebelumnya admin-only. Tidak menggantikan/melemahkan aturan
        // ownership POS di atas -- keduanya independen. Syarat LUNAS di
        // bawah berlaku untuk SEMUA jalur (admin/konteks kasir/Shift
        // Leader), tanpa kecuali.
        if ($status === 'selesai') {
            if (!$isAdmin && !$izinSelesaikanKonteks && !$isShiftLeader) {
                throw new \Exception(
                    'Hanya admin atau Shift Leader yang dapat menyelesaikan transaksi.'
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

        // PROSES -> BATAL: HANYA admin atau Shift Leader saat ini
        // (kapabilitas KHUSUS KONTEKS, identik pola SELESAI di atas).
        // SEBELUM Tahap 5.1 ini terbuka untuk semua role -- diperketat
        // atas keputusan produk supaya otoritas pembatalan transaksi
        // (baik dari PROSES maupun dari SELESAI, lihat cabang
        // 'selesai' di atas) konsisten: selalu admin atau Shift
        // Leader, tidak pernah kasir biasa.
        if ($status === 'batal' && !$isAdmin && !$isShiftLeader) {
            throw new \Exception(
                'Hanya admin atau Shift Leader yang dapat membatalkan transaksi.'
            );
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
    /**
     * Buat nomor invoice berikutnya untuk tanggal transaksi.
     *
     * Caller wajib memegang GET_LOCK per tanggal saat driver MySQL agar
     * dua kasir tidak mengambil nomor yang sama secara bersamaan.
     * Query + parsing suffix sengaja portable untuk test SQLite.
     */
    private function generateKodeInvoiceBerikutnya(string $tanggal): string
    {
        $tanggalKey = date('Ymd', strtotime($tanggal));
        $prefix = 'INV-' . $tanggalKey . '-';

        $rows = $this
            ->select('kode_invoice')
            ->like('kode_invoice', $prefix, 'after')
            ->findAll();

        $terakhir = 0;

        foreach ($rows as $row) {
            $kode = (string) ($row['kode_invoice'] ?? '');
            if (strpos($kode, $prefix) !== 0) {
                continue;
            }

            $suffix = substr($kode, strlen($prefix));
            if ($suffix !== '' && ctype_digit($suffix)) {
                $terakhir = max($terakhir, (int) $suffix);
            }
        }

        $berikutnya = $terakhir + 1;

        return $prefix . str_pad((string) $berikutnya, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Simpan transaksi baru (header + detail) dan, bila diberikan, pembayaran
     * awalnya -- semuanya dalam SATU transaksi database (TODO-BL01). Dengan
     * begitu tidak mungkin tersisa header berstatus "lunas" tanpa baris
     * pembayaran, dan percobaan ulang setelah kegagalan tidak menduplikasi.
     *
     * @param array<string, mixed>      $dataTransaksi  Header (grand_total, tanggal, dst).
     * @param array<int, array<string, mixed>> $detailItems
     * @param array<string, mixed>|null $dataPembayaran Pembayaran awal (opsional).
     */
    public function simpanTransaksi($dataTransaksi, $detailItems, ?array $dataPembayaran = null)
    {

        // Semua transaksi baru selalu dimulai dari PROSES.
        // Status pembayaran dihitung terpisah dari lifecycle transaksi.
        $dataTransaksi['status'] = 'proses';
        $db = \\Config\\Database::connect();
        $tanggalInvoice = (string) ($dataTransaksi['tanggal'] ?? date('Y-m-d H:i:s'));
        $tanggalInvoiceKey = date('Ymd', strtotime($tanggalInvoice));
        $isMySql = $db->getPlatform() === 'MySQLi';

        /*
    |--------------------------------------------------------------------------
    | Maksimal percobaan jika terjadi collision kode_invoice
    |--------------------------------------------------------------------------
    */
        $maxAttempt = 5;

        for ($attempt = 1; $attempt <= $maxAttempt; $attempt++) {

            $noOrderLockAcquired = false;
            $noOrderLockName = null;
            $invoiceLockAcquired = false;
            $invoiceLockName = null;

            try {

                /*
            |--------------------------------------------------------------------------
            | Lock No Order untuk mencegah dua kasir menyimpan nomor yang sama
            | secara bersamaan. Histori tetap boleh memiliki duplikasi setelah
            | transaksi berstatus BATAL.
            |--------------------------------------------------------------------------
            */
                if (!empty($dataTransaksi['no_order'])) {
                    $noOrder = (int) $dataTransaksi['no_order'];

                    if ($isMySql) {
                        $noOrderLockName = 'auliapos:no_order:' . $noOrder;

                        $lockResult = $db->query(
                            'SELECT GET_LOCK(?, 10) AS acquired',
                            [$noOrderLockName]
                        )->getRowArray();

                        if ((int) ($lockResult['acquired'] ?? 0) !== 1) {
                            throw new \RuntimeException(
                                'No Order ' . $noOrder . ' sedang diproses oleh kasir lain. Silakan coba lagi.',
                                409
                            );
                        }

                        $noOrderLockAcquired = true;
                    }

                    $existingActive = $this
                        ->where('no_order', $noOrder)
                        ->whereIn('status', ['proses', 'selesai', 'mangkrak'])
                        ->first();

                    if ($existingActive) {
                        throw new \RuntimeException(
                            'No Order ' . $noOrder . ' sedang digunakan oleh transaksi aktif.',
                            409
                        );
                    }
                }

                /*
            |--------------------------------------------------------------------------
            | Lock invoice per tanggal.
            |
            | Pada MySQL, GET_LOCK bersifat connection-level sehingga lock tetap
            | hidup selama transaksi database dan dilepas eksplisit setelah
            | commit/rollback. Pada SQLite test suite tidak ada GET_LOCK; test
            | berjalan satu proses sehingga query max tetap deterministik.
            |--------------------------------------------------------------------------
            */
                if ($isMySql) {
                    $invoiceLockName = 'auliapos:invoice:' . $tanggalInvoiceKey;

                    $lockResult = $db->query(
                        'SELECT GET_LOCK(?, 10) AS acquired',
                        [$invoiceLockName]
                    )->getRowArray();

                    if ((int) ($lockResult['acquired'] ?? 0) !== 1) {
                        throw new \RuntimeException(
                            'Nomor invoice tanggal ' . date('Y-m-d', strtotime($tanggalInvoice)) . ' sedang diproses kasir lain. Silakan coba lagi.',
                            409
                        );
                    }

                    $invoiceLockAcquired = true;
                }

                $dataTransaksi['kode_invoice'] = $this->generateKodeInvoiceBerikutnya($tanggalInvoice);

                $db->transStart();

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

                    if (
                        isset($dbError['code']) &&
                        (int) $dbError['code'] === 1062 &&
                        isset($dbError['message']) &&
                        strpos($dbError['message'], 'kode_invoice') !== false
                    ) {
                        $db->transRollback();
                        continue;
                    }

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
            | 2b. Pembayaran awal (bila ada) -- bagian dari transaksi yang sama
            |--------------------------------------------------------------------------
            */

                if ($dataPembayaran !== null) {
                    $dataPembayaran = $this->normalisasiPembayaran(
                        (int) $transaksiId,
                        $dataTransaksi,
                        $dataPembayaran,
                        false,
                        false
                    );

                    $this->tulisPembayaran($dataPembayaran);
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

                if ($noOrderLockAcquired && $noOrderLockName !== null) {
                    $db->query('SELECT RELEASE_LOCK(?)', [$noOrderLockName]);
                    $noOrderLockAcquired = false;
                }

                if ($invoiceLockAcquired && $invoiceLockName !== null) {
                    $db->query('SELECT RELEASE_LOCK(?)', [$invoiceLockName]);
                    $invoiceLockAcquired = false;
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

                if ($noOrderLockAcquired && $noOrderLockName !== null) {
                    $db->query('SELECT RELEASE_LOCK(?)', [$noOrderLockName]);
                    $noOrderLockAcquired = false;
                }

                if ($invoiceLockAcquired && $invoiceLockName !== null) {
                    $db->query('SELECT RELEASE_LOCK(?)', [$invoiceLockName]);
                    $invoiceLockAcquired = false;
                }

                $message = $e->getMessage();

                if (
                    strpos($message, 'Duplicate entry') !== false
                    && strpos($message, 'kode_invoice') !== false
                ) {
                    continue;
                }

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
     * @param bool $isAdmin Wajib true (atau $isShiftLeader true) jika
     *                       $data['tanggal'] adalah backdate (tanggal
     *                       signifikan berbeda dari sekarang) — lihat
     *                       blok BACKDATE di bawah. Untuk pembayaran
     *                       normal (tanggal = sekarang) kedua flag ini
     *                       tidak berpengaruh.
     * @param bool $isShiftLeader Kapabilitas KHUSUS KONTEKS: Effective
     *                       Shift Leader saat ini (App\Services\
     *                       Authority::isCurrentShiftLeader()) boleh
     *                       backdate persis seperti admin untuk fitur
     *                       ini (Tahap 5) -- validasi masa-depan &
     *                       tidak-boleh-sebelum-tanggal-transaksi di
     *                       bawah TETAP berlaku tanpa kecuali untuk
     *                       kedua kapabilitas ini, tidak ada yang
     *                       dilewati.
     */
    public function tambahPembayaran($transaksi_id, $data, bool $isAdmin = false, bool $isShiftLeader = false)
    {
        $db = \Config\Database::connect();

        $transaksi = $this->find($transaksi_id);

        if (!$transaksi) {
            throw new \Exception('Transaksi tidak ditemukan.');
        }

        // BATAL is terminal: the transaction is treated as never having
        // happened, so it must not receive any new payment (TODO-BL04).
        if (strtolower(trim((string) ($transaksi['status'] ?? ''))) === 'batal') {
            throw new \Exception('Pembayaran tidak dapat dicatat pada transaksi yang sudah dibatalkan.');
        }

        // Validation runs before the transaction opens, exactly as before
        // (a validation error never touches the database).
        $data = $this->normalisasiPembayaran(
            (int) $transaksi_id,
            $transaksi,
            $data,
            $isAdmin,
            $isShiftLeader
        );

        $db->transStart();

        try {
            $this->tulisPembayaran($data);

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
     * Validate and normalize one payment write. Read-only: it never writes
     * and never opens a transaction, so the caller owns the surrounding
     * transaction. Shared by tambahPembayaran() and simpanTransaksi() so the
     * rules stay a single source of truth.
     *
     * @param array<string, mixed> $transaksi Header row (tambahPembayaran) or
     *                                        the header array about to be
     *                                        inserted (simpanTransaksi). Must
     *                                        carry `grand_total` and `tanggal`.
     *
     * @return array<string, mixed> Data ready for tulisPembayaran().
     */
    private function normalisasiPembayaran(int $transaksi_id, array $transaksi, array $data, bool $isAdmin, bool $isShiftLeader): array
    {
        $pembayaranModel = model(PembayaranModel::class);

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

        $data['transaksi_id'] = $transaksi_id;
        $data['tanggal'] = $this->validasiTanggalPembayaran($transaksi, $data, $isAdmin, $isShiftLeader);
        $data['jumlah'] = $jumlah;
        $data['metode'] = $metode;
        $data['uang_diterima'] = $uangDiterima;
        $data['kembalian'] = $kembalian;
        $data['status'] = 'aktif';

        return $data;
    }

    /**
     * Validate and normalize the payment date (backdate rules), returning the
     * normalized `Y-m-d H:i:s` string. Moved verbatim from tambahPembayaran:
     * the caller always fills $data['tanggal']; only admin/Shift Leader may
     * backdate (more than 60 seconds off "now"); future dates and dates
     * before the transaction's day are rejected for every role.
     */
    private function validasiTanggalPembayaran(array $transaksi, array $data, bool $isAdmin, bool $isShiftLeader): string
    {
        $tanggalInput = $data['tanggal'] ?? date('Y-m-d H:i:s');
        $tanggalTimestamp = strtotime((string) $tanggalInput);

        if ($tanggalTimestamp === false) {
            throw new \Exception('Format tanggal pembayaran tidak valid.');
        }

        $sekarangTimestamp = time();
        $toleransiDetik = 60;
        $isBackdate = abs($sekarangTimestamp - $tanggalTimestamp) > $toleransiDetik;

        if ($isBackdate && !$isAdmin && !$isShiftLeader) {
            throw new \Exception(
                'Hanya admin atau Shift Leader yang dapat mencatat pembayaran dengan tanggal berbeda (backdate).'
            );
        }

        if ($tanggalTimestamp > $sekarangTimestamp + $toleransiDetik) {
            throw new \Exception('Tanggal pembayaran tidak boleh di masa depan.');
        }

        if ($isBackdate) {
            $tanggalTransaksiTimestamp = strtotime((string) ($transaksi['tanggal'] ?? date('Y-m-d H:i:s')));

            // Compare by DAY, not full timestamp: a payment earlier in the
            // same day is valid, only an earlier day is rejected.
            if ($tanggalTransaksiTimestamp !== false) {
                $tanggalPembayaranHari = date('Y-m-d', $tanggalTimestamp);
                $tanggalTransaksiHari = date('Y-m-d', $tanggalTransaksiTimestamp);

                if ($tanggalPembayaranHari < $tanggalTransaksiHari) {
                    throw new \Exception('Tanggal pembayaran tidak boleh sebelum tanggal transaksi.');
                }
            }
        }

        return date('Y-m-d H:i:s', $tanggalTimestamp);
    }

    /**
     * Insert one payment row and synchronize the transaction's cached
     * total/status. Opens no transaction of its own: the caller
     * (tambahPembayaran or simpanTransaksi) provides it.
     */
    private function tulisPembayaran(array $data): void
    {
        $pembayaranModel = model(PembayaranModel::class);

        $pembayaranId = $pembayaranModel->insert($data);

        if (!$pembayaranId) {
            $dbError = $pembayaranModel->db->error();

            throw new \Exception(
                'Gagal menyimpan pembayaran. DB Error: '
                    . ($dbError['code'] ?? '-')
                    . ' - '
                    . ($dbError['message'] ?? 'Unknown database error')
            );
        }

        $this->sinkronkanPembayaran((int) $data['transaksi_id']);
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

    /**
     * Rekomendasi no_order berikutnya (tertinggi hari ini + 1, atau
     * tertinggi keseluruhan + 1 kalau belum ada transaksi hari ini).
     * Dipakai untuk saran default di Kasir::index() maupun sebagai
     * fallback dropdown no_order di Transaksi::edit() saat transaksi
     * yang diedit belum punya no_order (lihat
     * Kasir::getAvailableNoOrders() / Transaksi::getAvailableNoOrdersForEdit()).
     */
    public function getRecommendedNoOrder(): int
    {
        $today = date('Y-m-d');

        $highestToday = $this
            ->where('tanggal >=', $today . ' 00:00:00')
            ->where('tanggal <=', $today . ' 23:59:59')
            ->where('no_order IS NOT NULL')
            ->where('no_order >', 0)
            ->orderBy('no_order', 'DESC')
            ->first();

        if ($highestToday && !empty($highestToday['no_order'])) {
            return (int) $highestToday['no_order'] + 1;
        }

        $highestOverall = $this->where('no_order IS NOT NULL')
            ->where('no_order >', 0)
            ->orderBy('no_order', 'DESC')
            ->first();

        if ($highestOverall && !empty($highestOverall['no_order'])) {
            return (int) $highestOverall['no_order'] + 1;
        }

        return 1;
    }
}
