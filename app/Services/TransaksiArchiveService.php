<?php

namespace App\Services;

use App\Models\UserModel;
use App\Models\PelangganModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Archive Transaksi -- memindahkan transaksi lama (bulan penuh, lihat
 * isBulanEligible()) dari database operasional (MySQL) ke database
 * SQLite terpisah, TANPA mengubah status/nilai transaksi.
 *
 * Prinsip inti (lihat juga docs/aturan-bisnis-AULIA.md kalau nanti
 * didokumentasikan di sana): archive BERSIFAT MANUAL, hanya berjalan
 * saat admin mengeksekusinya lewat ArchiveTransaksi::jalankan(). Tidak
 * ada cron/scheduler.
 *
 * Urutan aman (poin 5 spesifikasi fitur):
 *   1. pastikan folder+skema archive siap
 *   2. tulis backup JSON mentah (lapisan keamanan tambahan di luar
 *      salinan SQLite itu sendiri)
 *   3. salin data ke SQLite archive (INSERT OR REPLACE, idempotent --
 *      retry setelah gagal sebagian tidak akan menduplikasi baris)
 *   4. validasi jumlah baris & total nilai cocok persis dgn sumber
 *   5. HANYA kalau valid: hapus dari MySQL (satu transaksi DB, anak
 *      tabel ikut lewat FK ON DELETE CASCADE yang sudah ada)
 *
 * Kalau langkah 4 gagal, proses berhenti SEBELUM langkah 5 -- data
 * MySQL tidak pernah tersentuh kalau archive belum terbukti lengkap.
 */
class TransaksiArchiveService
{
    private BaseConnection $db;
    private BaseConnection $archive;

    /** Bulan (YYYY-MM) dianggap eligible kalau berjarak >= N bulan penuh dari bulan berjalan. */
    private const CUTOFF_BULAN = 6;

    private const LABEL_BULAN = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    public function __construct()
    {
        $this->db = Database::connect('default');

        $this->pastikanFolderArchive();
        $this->archive = Database::connect('archive');
        $this->pastikanSkemaArchive();
    }

    // ================================================================
    // SETUP (self-initializing -- tidak butuh langkah migrate terpisah,
    // supaya archive "dapat dibuat dengan mudah" cukup dari UI admin)
    // ================================================================

    private function pastikanFolderArchive(): void
    {
        $path = config('Database')->archive['database'];
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $backupDir = $dir . '/backups';

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
    }

    private function pastikanSkemaArchive(): void
    {
        // `id` SENGAJA disamakan dengan id asli di MySQL (bukan
        // autoincrement baru) supaya relasi & pencarian by-ID tetap
        // konsisten dengan histori (mis. link cetak lama, referensi
        // di catatan lain). INSERT OR REPLACE bergantung pada ini.
        //
        // Kolom *_nama di-snapshot langsung (pola yang SUDAH dipakai
        // existing app untuk detail_transaksi.nama_produk) -- supaya
        // archive tetap terbaca sendiri (self-contained) walau baris
        // pelanggan/users di DB utama suatu saat berubah, TANPA perlu
        // menyalin seluruh tabel master pelanggan/users (lihat audit).
        $this->archive->query(<<<'SQL'
CREATE TABLE IF NOT EXISTS transaksi_archive (
    id INTEGER PRIMARY KEY,
    merged_into INTEGER,
    kode_invoice TEXT NOT NULL,
    no_order INTEGER,
    tanggal TEXT,
    pelanggan_id INTEGER,
    pelanggan_nama TEXT,
    kasir_id INTEGER,
    kasir_nama TEXT,
    kasir_username TEXT,
    kasir_inisial TEXT,
    subtotal REAL NOT NULL DEFAULT 0,
    diskon REAL NOT NULL DEFAULT 0,
    pajak REAL NOT NULL DEFAULT 0,
    grand_total REAL NOT NULL DEFAULT 0,
    selisih_pembulatan REAL NOT NULL DEFAULT 0,
    total_dibayar REAL NOT NULL DEFAULT 0,
    status_pembayaran TEXT,
    status TEXT,
    sumber TEXT,
    created_at TEXT,
    updated_at TEXT,
    archived_at TEXT NOT NULL,
    archived_period TEXT NOT NULL
)
SQL);

        $this->archive->query(<<<'SQL'
CREATE TABLE IF NOT EXISTS detail_transaksi_archive (
    id INTEGER PRIMARY KEY,
    transaksi_id INTEGER NOT NULL,
    produk_id INTEGER,
    nama_produk TEXT,
    kategori_id INTEGER,
    jumlah REAL NOT NULL DEFAULT 0,
    harga_satuan REAL NOT NULL DEFAULT 0,
    subtotal REAL NOT NULL DEFAULT 0,
    catatan TEXT,
    created_at TEXT,
    updated_at TEXT
)
SQL);

        $this->archive->query(<<<'SQL'
CREATE TABLE IF NOT EXISTS pembayaran_archive (
    id INTEGER PRIMARY KEY,
    transaksi_id INTEGER NOT NULL,
    tanggal TEXT,
    jumlah REAL NOT NULL DEFAULT 0,
    uang_diterima REAL,
    kembalian REAL NOT NULL DEFAULT 0,
    metode TEXT,
    keterangan TEXT,
    kasir_id INTEGER,
    kasir_nama TEXT,
    kasir_username TEXT,
    kasir_inisial TEXT,
    status TEXT,
    created_at TEXT
)
SQL);

        $this->archive->query('CREATE INDEX IF NOT EXISTS idx_ta_tanggal ON transaksi_archive(tanggal)');
        $this->archive->query('CREATE INDEX IF NOT EXISTS idx_ta_period ON transaksi_archive(archived_period)');
        $this->archive->query('CREATE INDEX IF NOT EXISTS idx_ta_invoice ON transaksi_archive(kode_invoice)');
        $this->archive->query('CREATE INDEX IF NOT EXISTS idx_ta_pelanggan ON transaksi_archive(pelanggan_id)');
        $this->archive->query('CREATE INDEX IF NOT EXISTS idx_dta_transaksi ON detail_transaksi_archive(transaksi_id)');
        $this->archive->query('CREATE INDEX IF NOT EXISTS idx_pa_transaksi ON pembayaran_archive(transaksi_id)');

        // ------------------------------------------------------------
        // SELF-HEALING SCHEMA UPGRADE
        // ------------------------------------------------------------
        // `CREATE TABLE IF NOT EXISTS` TIDAK menambah kolom baru ke
        // tabel yang sudah ada (SQLite tidak auto-migrate). Kalau
        // archive ini dibuat SEBELUM kasir_username/kasir_inisial
        // ditambahkan ke skema, kolomnya perlu ditambah manual lewat
        // ALTER TABLE di sini -- dijalankan setiap service ini
        // diinstansiasi, idempotent (cek dulu lewat PRAGMA table_info,
        // hanya ALTER kalau benar-benar belum ada), jadi aman dipanggil
        // berkali-kali dan tidak perlu langkah manual apa pun dari
        // admin. Kalau nanti ada kolom baru lagi di masa depan, cukup
        // tambahkan pasangan (tabel, kolom, tipe) ke daftar di bawah.
        $this->tambahKolomJikaBelumAda('transaksi_archive', 'kasir_username', 'TEXT');
        $this->tambahKolomJikaBelumAda('transaksi_archive', 'kasir_inisial', 'TEXT');
        $this->tambahKolomJikaBelumAda('pembayaran_archive', 'kasir_username', 'TEXT');
        $this->tambahKolomJikaBelumAda('pembayaran_archive', 'kasir_inisial', 'TEXT');

        // Backfill nilai kolom baru itu untuk baris LAMA yang sudah
        // ter-archive sebelum kolom ini ada -- kasir_id-nya masih
        // tersimpan, dan tabel `users` di MySQL TIDAK ikut terhapus
        // oleh proses archive (cuma transaksi/detail/pembayaran),
        // jadi datanya masih bisa direkonstruksi dari sana. Hanya
        // menyentuh baris yang kasir_username-nya masih NULL --
        // idempotent, aman dijalankan berkali-kali, tidak menimpa
        // data yang sudah lengkap.
        $this->backfillKasirLama();
    }

    private function tambahKolomJikaBelumAda(string $tabel, string $kolom, string $tipe): void
    {
        $kolomAda = $this->archive->query("PRAGMA table_info({$tabel})")->getResultArray();
        $namaKolom = array_column($kolomAda, 'name');

        if (!in_array($kolom, $namaKolom, true)) {
            $this->archive->query("ALTER TABLE {$tabel} ADD COLUMN {$kolom} {$tipe}");
        }
    }

    private function backfillKasirLama(): void
    {
        $idPerlu = [];

        foreach (['transaksi_archive', 'pembayaran_archive'] as $tabel) {
            $rows = $this->archive->table($tabel)
                ->distinct()
                ->select('kasir_id')
                ->where('kasir_id IS NOT NULL')
                ->where('kasir_username IS NULL')
                ->get()->getResultArray();

            foreach ($rows as $r) {
                if (!empty($r['kasir_id'])) {
                    $idPerlu[(int) $r['kasir_id']] = true;
                }
            }
        }

        if (empty($idPerlu)) {
            return;
        }

        try {
            $users = model(UserModel::class)
                ->select('id, nama, username, inisial')
                ->whereIn('id', array_keys($idPerlu))
                ->findAll();
        } catch (\Throwable $e) {
            // DB utama tidak bisa diakses saat ini -- backfill dicoba
            // lagi otomatis di kesempatan berikutnya (idempotent,
            // tidak berbahaya untuk di-skip sementara).
            log_message('error', 'backfillKasirLama gagal baca users: ' . $e->getMessage());
            return;
        }

        foreach ($users as $u) {
            foreach (['transaksi_archive', 'pembayaran_archive'] as $tabel) {
                $this->archive->table($tabel)
                    ->where('kasir_id', $u['id'])
                    ->where('kasir_username IS NULL')
                    ->update([
                        'kasir_nama'     => $u['nama'],
                        'kasir_username' => $u['username'],
                        'kasir_inisial'  => $u['inisial'],
                    ]);
            }
        }
    }

    // ================================================================
    // CUTOFF & DAFTAR BULAN
    // ================================================================

    /**
     * Kunci bulan (YYYY*12+MM) yang jadi batas eligible. Dipakai
     * sebagai perbandingan GRANULARITAS BULAN (bukan tanggal persis)
     * -- lihat catatan penting di isBulanEligible().
     */
    private function kunciBulanSaatIni(): int
    {
        return ((int) date('Y')) * 12 + (int) date('n');
    }

    /**
     * CATATAN REKONSILIASI SPESIFIKASI:
     * Prosa asli fitur ini menyebut "tanggal terakhir bulan tersebut <
     * tanggal hari ini dikurangi 6 bulan" (perbandingan level TANGGAL).
     * Tapi contoh konkret yang diberikan (hari ini 8 Sep 2026 ->
     * Jan/Feb/Maret 2026 eligible, April belum) TIDAK cocok dengan
     * perbandingan level tanggal itu -- 8 Sep dikurangi 6 bulan =
     * 8 Maret 2026, dan tanggal terakhir Maret (31 Maret) TIDAK lebih
     * kecil dari 8 Maret, jadi Maret seharusnya BELUM eligible kalau
     * prosa itu diikuti literal.
     *
     * Contoh konkretnya saya ikuti (lebih presisi & bisa diuji
     * daripada prosa), yaitu perbandingan level BULAN: bulan X
     * eligible kalau X berjarak >= 6 bulan PENUH dari bulan berjalan
     * (Sep 2026 - 6 = Maret 2026 -> Jan/Feb/Maret eligible, April
     * tidak). Ini juga menghindari edge-case tanggal (akhir bulan,
     * bulan pendek Februari, dst) yang bikin perbandingan level
     * tanggal jadi rapuh.
     */
    public function isBulanEligible(string $ym): bool
    {
        [$tahun, $bulan] = array_map('intval', explode('-', $ym));
        $kunci = $tahun * 12 + $bulan;

        return $kunci <= ($this->kunciBulanSaatIni() - self::CUTOFF_BULAN);
    }

    public function labelBulan(string $ym): string
    {
        [$tahun, $bulan] = array_map('intval', explode('-', $ym));

        return (self::LABEL_BULAN[$bulan] ?? $ym) . ' ' . $tahun;
    }

    /**
     * Semua bulan yang punya data transaksi di DB utama (satu query
     * GROUP BY, bukan N+1 per bulan), ditandai eligible/belum.
     * Urutan lama -> baru (kronologis).
     *
     * @return array<int, array{ym: string, label: string, jumlah: int, eligible: bool}>
     */
    public function getDaftarBulan(): array
    {
        $rows = $this->db->table('transaksi')
            ->select("DATE_FORMAT(tanggal, '%Y-%m') as ym, COUNT(*) as jumlah", false)
            ->groupBy('ym')
            ->orderBy('ym', 'ASC')
            ->get()
            ->getResultArray();

        $daftar = [];

        foreach ($rows as $r) {
            if (empty($r['ym'])) {
                continue;
            }

            $daftar[] = [
                'ym'       => $r['ym'],
                'label'    => $this->labelBulan($r['ym']),
                'jumlah'   => (int) $r['jumlah'],
                'eligible' => $this->isBulanEligible($r['ym']),
            ];
        }

        return $daftar;
    }

    /**
     * Validasi server-side (jangan percaya input client mentah-mentah)
     * -- setiap bulan yang diminta HARUS format YYYY-MM valid DAN
     * eligible. Lempar exception kalau ada yang tidak lolos, sebutkan
     * bulan mana supaya pesan errornya jelas.
     *
     * @return string[] Daftar bulan yang sudah divalidasi (ter-urut).
     */
    private function validasiBulanEligible(array $bulanList): array
    {
        $bulanList = array_values(array_unique(array_filter($bulanList)));

        if (empty($bulanList)) {
            throw new \Exception('Pilih minimal satu bulan untuk di-archive.');
        }

        foreach ($bulanList as $ym) {
            if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
                throw new \Exception("Format bulan tidak valid: {$ym}");
            }

            if (!$this->isBulanEligible($ym)) {
                throw new \Exception(
                    $this->labelBulan($ym) . ' belum eligible untuk di-archive (kurang dari '
                        . self::CUTOFF_BULAN . ' bulan penuh dari bulan berjalan).'
                );
            }
        }

        sort($bulanList);

        return $bulanList;
    }

    /**
     * Terapkan filter "salah satu dari bulan-bulan ini" ke query
     * builder transaksi, pakai rentang tanggal (BUKAN
     * DATE_FORMAT(tanggal,...) di WHERE) supaya index `idx_tanggal`
     * yang sudah ada tetap kepakai -- function-wrapped column di WHERE
     * bikin MySQL tidak bisa pakai index itu.
     */
    private function terapkanFilterBulan($builder, array $bulanList)
    {
        $builder->groupStart();

        foreach ($bulanList as $i => $ym) {
            $awal = $ym . '-01 00:00:00';
            $akhir = date('Y-m-t 23:59:59', strtotime($ym . '-01'));

            if ($i === 0) {
                $builder->groupStart();
            } else {
                $builder->orGroupStart();
            }

            $builder->where('tanggal >=', $awal)
                ->where('tanggal <=', $akhir)
                ->groupEnd();
        }

        $builder->groupEnd();

        return $builder;
    }

    // ================================================================
    // PREVIEW (read-only, tidak menyentuh apa pun)
    // ================================================================

    /**
     * @return array{
     *   bulan: string[], tanggal_awal: string, tanggal_akhir: string,
     *   jumlah_transaksi: int, jumlah_detail: int, jumlah_pembayaran: int,
     *   total_transaksi: float, total_pembayaran: float,
     *   per_status: array<string,int>
     * }
     */
    public function preview(array $bulanList): array
    {
        $bulanList = $this->validasiBulanEligible($bulanList);

        $builder = $this->terapkanFilterBulan($this->db->table('transaksi'), $bulanList);
        $transaksi = $builder->select('id, grand_total, status_pembayaran, total_dibayar')->get()->getResultArray();

        $ids = array_column($transaksi, 'id');

        $jumlahDetail = 0;
        $jumlahPembayaran = 0;
        $totalPembayaran = 0.0;

        if (!empty($ids)) {
            $jumlahDetail = $this->db->table('detail_transaksi')
                ->whereIn('transaksi_id', $ids)
                ->countAllResults();

            $rowPembayaran = $this->db->table('pembayaran')
                ->select('COUNT(*) as jumlah, COALESCE(SUM(jumlah), 0) as total', false)
                ->whereIn('transaksi_id', $ids)
                ->get()->getRowArray();

            $jumlahPembayaran = (int) ($rowPembayaran['jumlah'] ?? 0);
            $totalPembayaran = (float) ($rowPembayaran['total'] ?? 0);
        }

        $perStatus = ['belum_bayar' => 0, 'dp' => 0, 'lunas' => 0, 'batal' => 0];
        $totalTransaksi = 0.0;

        foreach ($transaksi as $t) {
            $totalTransaksi += (float) $t['grand_total'];
            $key = $t['status_pembayaran'] ?? 'belum_bayar';

            // "batal" dihitung terpisah dari status_pembayaran karena
            // status pembayaran & status transaksi adalah dua sumbu
            // berbeda (lihat aturan bisnis Section 1) -- tapi untuk
            // ringkasan preview, transaksi status=batal tetap dihitung
            // di baris "batal" sendiri supaya kelihatan jelas.
            if (isset($perStatus[$key])) {
                $perStatus[$key]++;
            }
        }

        $awal = $bulanList[0] . '-01';
        $akhirYm = end($bulanList);
        $akhir = date('Y-m-t', strtotime($akhirYm . '-01'));

        return [
            'bulan'             => $bulanList,
            'tanggal_awal'      => $awal,
            'tanggal_akhir'     => $akhir,
            'jumlah_transaksi'  => count($ids),
            'jumlah_detail'     => $jumlahDetail,
            'jumlah_pembayaran' => $jumlahPembayaran,
            'total_transaksi'   => $totalTransaksi,
            'total_pembayaran'  => $totalPembayaran,
            'per_status'        => $perStatus,
        ];
    }

    // ================================================================
    // EKSEKUSI ARCHIVE (destruktif -- lihat urutan aman di docblock atas)
    // ================================================================

    public function jalankan(array $bulanList, int $userId): array
    {
        $bulanList = $this->validasiBulanEligible($bulanList);

        $builder = $this->terapkanFilterBulan($this->db->table('transaksi'), $bulanList);
        $transaksiRows = $builder->get()->getResultArray();

        if (empty($transaksiRows)) {
            return [
                'jumlah_transaksi'  => 0,
                'jumlah_detail'     => 0,
                'jumlah_pembayaran' => 0,
                'file_backup'       => null,
                'pesan'             => 'Tidak ada transaksi pada bulan yang dipilih.',
            ];
        }

        $ids = array_column($transaksiRows, 'id');

        $detailRows = $this->db->table('detail_transaksi')->whereIn('transaksi_id', $ids)->get()->getResultArray();

        // SEMUA status pembayaran ikut (aktif & reversed) -- "Relasi
        // dan isi histori pembayaran harus tetap utuh" di spesifikasi,
        // bukan cuma yang aktif.
        $pembayaranRows = $this->db->table('pembayaran')->whereIn('transaksi_id', $ids)->get()->getResultArray();

        // ---- Snapshot nama pelanggan & kasir (bulk, bukan N+1) ----
        $pelangganIds = array_values(array_unique(array_filter(array_column($transaksiRows, 'pelanggan_id'))));
        $kasirIds = array_values(array_unique(array_filter(array_merge(
            array_column($transaksiRows, 'kasir_id'),
            array_column($pembayaranRows, 'kasir_id')
        ))));

        $petaPelanggan = [];
        if (!empty($pelangganIds)) {
            $rows = model(PelangganModel::class)->select('id, nama')->whereIn('id', $pelangganIds)->findAll();
            foreach ($rows as $r) {
                $petaPelanggan[$r['id']] = $r['nama'];
            }
        }

        $petaKasir = [];
        if (!empty($kasirIds)) {
            // Ketiga field ini disnapshot (bukan cuma `nama`) karena
            // VIEW pelaporan existing (v_daftar_pembayaran) JOIN users
            // untuk nama/username/inisial sekaligus -- supaya laporan
            // yang membaca archive nanti tidak kehilangan fidelity.
            $rows = model(UserModel::class)->select('id, nama, username, inisial')->whereIn('id', $kasirIds)->findAll();
            foreach ($rows as $r) {
                $petaKasir[$r['id']] = [
                    'nama'     => $r['nama'],
                    'username' => $r['username'],
                    'inisial'  => $r['inisial'],
                ];
            }
        }

        // ---- Backup mentah (lapisan keamanan tambahan di luar SQLite) ----
        $fileBackup = $this->tulisBackupJson($bulanList, $transaksiRows, $detailRows, $pembayaranRows);

        // ---- Salin ke SQLite archive (idempotent lewat INSERT OR REPLACE) ----
        $this->salinKeArchive($transaksiRows, $detailRows, $pembayaranRows, $petaPelanggan, $petaKasir);

        // ---- Validasi ketat sebelum boleh menghapus dari MySQL ----
        $this->validasiHasilSalin($ids, $transaksiRows, $detailRows, $pembayaranRows);

        // ---- Baru sekarang hapus dari DB utama ----
        $this->hapusDariUtama($ids);

        return [
            'jumlah_transaksi'  => count($transaksiRows),
            'jumlah_detail'     => count($detailRows),
            'jumlah_pembayaran' => count($pembayaranRows),
            'file_backup'       => $fileBackup,
            'pesan'             => 'Archive berhasil.',
        ];
    }

    private function tulisBackupJson(array $bulanList, array $transaksi, array $detail, array $pembayaran): string
    {
        $config = config('Database');
        $dir = dirname($config->archive['database']) . '/backups';
        $nama = 'archive_' . date('Ymd_His') . '_' . implode('-', $bulanList) . '.json';
        $path = $dir . '/' . $nama;

        $payload = json_encode([
            'dibuat_pada' => date('Y-m-d H:i:s'),
            'bulan'       => $bulanList,
            'transaksi'   => $transaksi,
            'detail_transaksi' => $detail,
            'pembayaran'  => $pembayaran,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if (file_put_contents($path, $payload) === false) {
            throw new \Exception('Gagal menulis file backup sebelum archive. Proses dibatalkan demi keamanan data.');
        }

        return $path;
    }

    private function salinKeArchive(
        array $transaksiRows,
        array $detailRows,
        array $pembayaranRows,
        array $petaPelanggan,
        array $petaKasir
    ): void {
        $this->archive->transStart();

        try {
            $now = date('Y-m-d H:i:s');

            foreach ($transaksiRows as $t) {
                $period = date('Y-m', strtotime($t['tanggal'] ?? $now));
                $kasir = $petaKasir[$t['kasir_id']] ?? null;

                $this->archive->query(
                    'INSERT OR REPLACE INTO transaksi_archive
                        (id, merged_into, kode_invoice, no_order, tanggal, pelanggan_id, pelanggan_nama,
                         kasir_id, kasir_nama, kasir_username, kasir_inisial, subtotal, diskon, pajak,
                         grand_total, selisih_pembulatan, total_dibayar, status_pembayaran, status, sumber,
                         created_at, updated_at, archived_at, archived_period)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $t['id'], $t['merged_into'], $t['kode_invoice'], $t['no_order'], $t['tanggal'],
                        $t['pelanggan_id'], $petaPelanggan[$t['pelanggan_id']] ?? null,
                        $t['kasir_id'], $kasir['nama'] ?? null, $kasir['username'] ?? null, $kasir['inisial'] ?? null,
                        $t['subtotal'], $t['diskon'], $t['pajak'], $t['grand_total'], $t['selisih_pembulatan'],
                        $t['total_dibayar'], $t['status_pembayaran'], $t['status'], $t['sumber'],
                        $t['created_at'], $t['updated_at'], $now, $period,
                    ]
                );
            }

            foreach ($detailRows as $d) {
                $this->archive->query(
                    'INSERT OR REPLACE INTO detail_transaksi_archive
                        (id, transaksi_id, produk_id, nama_produk, kategori_id, jumlah, harga_satuan,
                         subtotal, catatan, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $d['id'], $d['transaksi_id'], $d['produk_id'], $d['nama_produk'], $d['kategori_id'],
                        $d['jumlah'], $d['harga_satuan'], $d['subtotal'], $d['catatan'],
                        $d['created_at'], $d['updated_at'],
                    ]
                );
            }

            foreach ($pembayaranRows as $p) {
                $kasir = $petaKasir[$p['kasir_id']] ?? null;

                $this->archive->query(
                    'INSERT OR REPLACE INTO pembayaran_archive
                        (id, transaksi_id, tanggal, jumlah, uang_diterima, kembalian, metode,
                         keterangan, kasir_id, kasir_nama, kasir_username, kasir_inisial, status, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $p['id'], $p['transaksi_id'], $p['tanggal'], $p['jumlah'], $p['uang_diterima'],
                        $p['kembalian'], $p['metode'], $p['keterangan'], $p['kasir_id'],
                        $kasir['nama'] ?? null, $kasir['username'] ?? null, $kasir['inisial'] ?? null,
                        $p['status'], $p['created_at'],
                    ]
                );
            }

            $this->archive->transComplete();

            if (!$this->archive->transStatus()) {
                throw new \Exception('Gagal menyalin data ke database archive.');
            }
        } catch (\Throwable $e) {
            $this->archive->transRollback();
            throw new \Exception('Gagal menyalin ke archive: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validasi ketat: jumlah baris per tabel di archive HARUS persis
     * sama dengan jumlah yang coba disalin, dan total nilai transaksi
     * harus cocok (toleransi float kecil). Gagal salah satu saja ->
     * lempar exception, TIDAK ada yang dihapus dari MySQL.
     */
    private function validasiHasilSalin(array $ids, array $transaksiRows, array $detailRows, array $pembayaranRows): void
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $jumlahTA = (int) $this->archive
            ->query("SELECT COUNT(*) as n FROM transaksi_archive WHERE id IN ({$placeholders})", $ids)
            ->getRow()->n;

        if ($jumlahTA !== count($transaksiRows)) {
            throw new \Exception(
                "Validasi gagal: transaksi_archive berisi {$jumlahTA}, seharusnya " . count($transaksiRows)
                    . '. Proses dibatalkan, DB utama TIDAK diubah.'
            );
        }

        $jumlahDTA = (int) $this->archive
            ->query("SELECT COUNT(*) as n FROM detail_transaksi_archive WHERE transaksi_id IN ({$placeholders})", $ids)
            ->getRow()->n;

        if ($jumlahDTA !== count($detailRows)) {
            throw new \Exception(
                "Validasi gagal: detail_transaksi_archive berisi {$jumlahDTA}, seharusnya " . count($detailRows)
                    . '. Proses dibatalkan, DB utama TIDAK diubah.'
            );
        }

        $jumlahPA = (int) $this->archive
            ->query("SELECT COUNT(*) as n FROM pembayaran_archive WHERE transaksi_id IN ({$placeholders})", $ids)
            ->getRow()->n;

        if ($jumlahPA !== count($pembayaranRows)) {
            throw new \Exception(
                "Validasi gagal: pembayaran_archive berisi {$jumlahPA}, seharusnya " . count($pembayaranRows)
                    . '. Proses dibatalkan, DB utama TIDAK diubah.'
            );
        }

        $totalSumber = array_sum(array_column($transaksiRows, 'grand_total'));
        $totalArchive = (float) $this->archive
            ->query("SELECT COALESCE(SUM(grand_total), 0) as t FROM transaksi_archive WHERE id IN ({$placeholders})", $ids)
            ->getRow()->t;

        if (abs($totalSumber - $totalArchive) > 0.01) {
            throw new \Exception(
                'Validasi gagal: total grand_total archive (' . $totalArchive . ') tidak cocok dengan sumber ('
                    . $totalSumber . '). Proses dibatalkan, DB utama TIDAK diubah.'
            );
        }
    }

    private function hapusDariUtama(array $ids): void
    {
        $this->db->transStart();

        try {
            // detail_transaksi & pembayaran ikut terhapus otomatis lewat
            // FK `ON DELETE CASCADE` yang sudah ada di skema baseline --
            // tidak perlu DELETE terpisah per tabel anak.
            $this->db->table('transaksi')->whereIn('id', $ids)->delete();

            $this->db->transComplete();

            if (!$this->db->transStatus()) {
                throw new \Exception('Gagal menghapus data dari database utama setelah archive tervalidasi.');
            }
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw new \Exception(
                'PENTING: archive SUDAH tersalin & tervalidasi, tapi penghapusan dari DB utama gagal ('
                    . $e->getMessage() . '). Data TIDAK hilang (masih ada di kedua tempat) -- '
                    . 'jalankan archive lagi untuk bulan yang sama, prosesnya aman diulang.',
                0,
                $e
            );
        }
    }

    // ================================================================
    // PENCARIAN (dipakai Api::searchGlobal supaya transaksi yang sudah
    // di-archive tetap ketemu -- poin 9 spesifikasi fitur). Read-only.
    // ================================================================

    /**
     * Ambil satu transaksi lengkap (transaksi + detail item + riwayat
     * pembayaran) dari ARCHIVE by ID -- dipakai sebagai fallback saat
     * Transaksi::detail()/Tagihan::detail() tidak menemukan ID di DB
     * utama (berarti sudah di-archive). Bentuk hasilnya SENGAJA
     * disamakan sedekat mungkin dengan hasil query live (nama kolom
     * yang sama) supaya bisa dipakai ulang oleh view `transaksi/detail`
     * yang sama, tanpa bikin view terpisah.
     *
     * @return array{transaksi: array, detail_items: array, pembayaran: array}|null
     */
    public function cariById(int $id): ?array
    {
        $transaksi = $this->archive->table('transaksi_archive')->where('id', $id)->get()->getRowArray();

        if (!$transaksi) {
            return null;
        }

        // Samakan nama kolom dengan hasil JOIN live (`kasir_nama` dari
        // users.username) -- di archive sudah berupa snapshot text,
        // tidak perlu JOIN apa pun.
        $transaksi['kasir_nama'] = $transaksi['kasir_nama'] ?? '(tidak diketahui)';

        $detailItems = $this->archive->table('detail_transaksi_archive')
            ->where('transaksi_id', $id)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        // Sama seperti tampilan live (Transaksi::detail()): hanya
        // pembayaran berstatus 'aktif' yang ditampilkan di halaman ini,
        // supaya tampilannya konsisten dengan transaksi yang belum
        // di-archive. Baris 'reversed' tetap ADA di tabel archive
        // (histori tetap utuh secara data), cuma tidak ditampilkan di
        // sini -- identik dengan perilaku live yang sudah ada.
        $pembayaran = $this->archive->table('pembayaran_archive')
            ->where('transaksi_id', $id)
            ->where('status', 'aktif')
            ->orderBy('tanggal', 'ASC')
            ->get()->getResultArray();

        foreach ($pembayaran as &$p) {
            $p['kasir_username'] = $p['kasir_username'] ?? '';
        }

        return [
            'transaksi'    => $transaksi,
            'detail_items' => $detailItems,
            'pembayaran'   => $pembayaran,
        ];
    }

    // ================================================================
    // DUKUNGAN LAPORAN (read-only). Semua method di bawah ini
    // mengembalikan array dengan BENTUK KOLOM YANG SAMA PERSIS dengan
    // query/VIEW di database utama yang dipakai Laporan.php -- supaya
    // pemanggil cukup array_merge() hasilnya dengan hasil query live,
    // TANPA perlu mengubah logic olah-data laporan yang sudah ada.
    //
    // Kenapa begini (bukan JOIN lintas database): MySQL tidak bisa
    // JOIN langsung ke SQLite. Jadi tiap sumber di-query terpisah,
    // lalu digabung di PHP SEBELUM masuk ke fungsi agregasi existing.
    // ================================================================

    /**
     * Baris transaksi mentah dari archive untuk suatu rentang tanggal
     * -- kolom sama seperti tabel `transaksi` (plus pelanggan_nama
     * yang di live didapat lewat JOIN, di sini sudah snapshot).
     */
    public function getTransaksiMentah(string $tglAwal, string $tglAkhir, bool $excludeBatal = true): array
    {
        $builder = $this->archive->table('transaksi_archive')
            ->select('*')
            ->where('tanggal >=', $tglAwal)
            ->where('tanggal <=', $tglAkhir);

        if ($excludeBatal) {
            $builder->where('status !=', 'batal');
        }

        return $builder->orderBy('tanggal', 'ASC')->get()->getResultArray();
    }

    /**
     * Baris pembayaran mentah dari archive untuk suatu rentang
     * tanggal -- kolom sama seperti tabel `pembayaran`.
     */
    public function getPembayaranMentah(string $tglAwal, string $tglAkhir, string $status = 'aktif'): array
    {
        return $this->archive->table('pembayaran_archive')
            ->select('id, transaksi_id, tanggal, jumlah, uang_diterima, kembalian, metode, keterangan, kasir_id, status')
            ->where('tanggal >=', $tglAwal)
            ->where('tanggal <=', $tglAkhir)
            ->where('status', $status)
            ->orderBy('tanggal', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
    }

    /**
     * Baris detail_transaksi mentah dari archive untuk sekumpulan
     * transaksi_id -- kolom sama seperti tabel `detail_transaksi`.
     */
    public function getDetailTransaksiMentah(array $transaksiIds): array
    {
        if (empty($transaksiIds)) {
            return [];
        }

        return $this->archive->table('detail_transaksi_archive')
            ->select('id, transaksi_id, produk_id, nama_produk, kategori_id, jumlah, harga_satuan, subtotal')
            ->whereIn('transaksi_id', $transaksiIds)
            ->orderBy('transaksi_id', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
    }

    /**
     * Setara `v_daftar_pembayaran` (lihat migration baseline), tapi
     * dibaca dari archive -- SQLite BISA JOIN antar tabelnya sendiri
     * (transaksi_archive + pembayaran_archive), yang tidak bisa cuma
     * JOIN lintas ke MySQL. Kolom hasil disamakan persis dengan nama
     * kolom VIEW aslinya supaya bisa langsung digabung dengan hasil
     * live di Laporan::pembayaran().
     */
    public function getDaftarPembayaranMentah(
        string $tglAwal,
        string $tglAkhir,
        ?string $metode = null,
        ?string $keyword = null
    ): array {
        $where = [
            "t.status != 'batal'",
            "p.status = 'aktif'",
            'p.tanggal >= ?',
            'p.tanggal <= ?',
        ];
        $params = [$tglAwal, $tglAkhir];

        if (!empty($metode)) {
            $where[] = 'p.metode = ?';
            $params[] = strtolower($metode);
        }

        if (!empty($keyword)) {
            $like = '%' . $keyword . '%';
            $where[] = '(t.kode_invoice LIKE ? OR t.pelanggan_nama LIKE ? OR p.kasir_nama LIKE ? OR p.keterangan LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }

        $sql = "SELECT
                p.id AS pembayaran_id,
                p.transaksi_id,
                t.kode_invoice,
                t.tanggal AS tanggal_transaksi,
                t.status AS status_transaksi,
                p.tanggal AS tanggal_pembayaran,
                t.pelanggan_nama AS nama_pelanggan,
                p.kasir_nama AS nama_kasir,
                p.kasir_username AS username_kasir,
                p.kasir_inisial AS inisial_kasir,
                p.metode,
                p.jumlah,
                p.uang_diterima,
                p.kembalian,
                p.keterangan,
                t.pelanggan_id,
                p.kasir_id
            FROM pembayaran_archive p
            JOIN transaksi_archive t ON t.id = p.transaksi_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.tanggal ASC";

        return $this->archive->query($sql, $params)->getResultArray();
    }

    /**
     * Setara `v_pembayaran_item_harian` SETELAH agregasi GROUP BY/SUM
     * (sama persis dengan query yang Laporan::itemHarian() jalankan ke
     * VIEW live) -- SQLite bisa GROUP BY di dalam dirinya sendiri
     * (cuma JOIN LINTAS ke MySQL yang tidak bisa), jadi agregasi
     * archive dikerjakan di sini, baru hasilnya (baris yang SUDAH
     * teragregasi) di-array_merge() dengan hasil live di controller.
     *
     * Aman digabung tanpa re-aggregate lagi karena satu transaksi
     * cuma bisa ada di SATU sumber (live ATAU archive, tidak pernah
     * dua-duanya) -- jadi tidak ada baris dari live & archive yang
     * perlu dijumlah ulang jadi satu baris.
     */
    public function getItemHarianMentah(
        string $tglMulai,
        string $tglSampai,
        ?int $kategoriId = null,
        ?string $keyword = null,
        ?string $metode = null
    ): array {
        $where = ['t.status != ?', 'p.status = ?', 'DATE(p.tanggal) >= ?', 'DATE(p.tanggal) <= ?'];
        $params = ['batal', 'aktif', $tglMulai, $tglSampai];

        if ($kategoriId !== null) {
            $where[] = 'dt.kategori_id = ?';
            $params[] = $kategoriId;
        }

        if (!empty($keyword)) {
            $where[] = 'dt.nama_produk LIKE ?';
            $params[] = '%' . $keyword . '%';
        }

        if (!empty($metode)) {
            $where[] = 'p.metode = ?';
            $params[] = strtolower($metode);
        }

        $sql = "SELECT
                DATE(p.tanggal) AS tanggal_pembayaran,
                p.transaksi_id,
                t.kode_invoice,
                dt.kategori_id,
                dt.nama_produk,
                SUM(dt.jumlah) AS jumlah_item,
                SUM(dt.subtotal) AS subtotal_item,
                SUM(p.jumlah) AS total_pembayaran,
                SUM(CASE WHEN total_detail.total_subtotal > 0
                    THEN p.jumlah * (dt.subtotal * 1.0 / total_detail.total_subtotal)
                    ELSE 0 END) AS total_teralokasi
            FROM pembayaran_archive p
            JOIN transaksi_archive t ON t.id = p.transaksi_id
            JOIN detail_transaksi_archive dt ON dt.transaksi_id = p.transaksi_id
            JOIN (
                SELECT transaksi_id, SUM(subtotal) AS total_subtotal
                FROM detail_transaksi_archive
                GROUP BY transaksi_id
            ) total_detail ON total_detail.transaksi_id = p.transaksi_id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY DATE(p.tanggal), p.transaksi_id, t.kode_invoice, dt.kategori_id, dt.nama_produk
            ORDER BY tanggal_pembayaran ASC, total_teralokasi DESC";

        return $this->archive->query($sql, $params)->getResultArray();
    }

    /**
     * Cari di database ARCHIVE saja dengan kriteria yang sama seperti
     * Api::searchGlobal() di database utama (kode_invoice/no_order
     * ATAU nama pelanggan yang sudah di-snapshot). Setiap baris hasil
     * diberi `_sumber = 'archive'` supaya UI bisa menandai asalnya
     * (poin 9: "tampilkan sumber data: Aktif / Archive").
     *
     * @return array<int, array<string, mixed>>
     */
    public function cariTransaksi(string $keyword, ?string $noOrder, int $limit = 10): array
    {
        $builder = $this->archive->table('transaksi_archive')
            ->select('id, kode_invoice, no_order, status_pembayaran, pelanggan_nama');

        if (!empty($noOrder)) {
            $builder->where('no_order', $noOrder);
        } else {
            $like = '%' . $keyword . '%';
            $builder->groupStart()
                ->like('kode_invoice', $keyword)
                ->orLike('no_order', $keyword)
                ->orLike('pelanggan_nama', $keyword)
                ->groupEnd();
        }

        $rows = $builder->where('status !=', 'batal')
            ->orderBy('tanggal', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        foreach ($rows as &$r) {
            // Samakan bentuk hasil dengan query DB utama (Api::searchGlobal)
            // supaya frontend tidak perlu tahu bedanya -- cuma tambahan
            // key pelanggan_nama (bukan JOIN) dan penanda sumber.
            $r['pelanggan_nama'] = $r['pelanggan_nama'] ?? null;
            $r['_sumber'] = 'archive';
        }

        return $rows;
    }

    /**
     * Cari di database ARCHIVE dengan kriteria & bentuk kolom yang
     * SAMA PERSIS dengan query utama Transaksi::index() -- dipakai
     * untuk melengkapi hasil pencarian keyword di halaman daftar
     * Transaksi supaya transaksi lama yang sudah di-archive tetap
     * ketemu lewat kotak pencarian yang sama (bukan fitur terpisah).
     *
     * @return array<int, array<string, mixed>>
     */
    public function cariUntukDaftarTransaksi(string $keyword, ?int $parsedNoOrder, int $limit = 200): array
    {
        $builder = $this->archive->table('transaksi_archive')
            ->select(
                'id, kode_invoice, no_order, tanggal, pelanggan_id, kasir_id, ' .
                'grand_total, total_dibayar, status_pembayaran, status, sumber, ' .
                'kasir_nama, pelanggan_nama'
            )
            ->groupStart()
                ->like('kode_invoice', $keyword)
                ->orLike('no_order', $keyword)
                ->orLike('pelanggan_nama', $keyword);

        if ($parsedNoOrder) {
            $builder->orWhere('no_order', $parsedNoOrder)
                ->orLike('no_order', (string) $parsedNoOrder);
        }

        $rows = $builder->groupEnd()
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        foreach ($rows as &$r) {
            $r['_sumber'] = 'archive';
        }

        return $rows;
    }
}
