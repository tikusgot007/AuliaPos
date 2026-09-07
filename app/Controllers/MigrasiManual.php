<?php

namespace App\Controllers;

use Config\Services;

/**
 * Menjalankan database migration lewat browser.
 *
 * Dibuat khusus karena hosting yang dipakai tidak menyediakan akses
 * CLI/SSH untuk menjalankan `php spark migrate` secara langsung.
 *
 * CATATAN KEAMANAN (baca sebelum dipakai di production):
 * - Admin-only, dicek dua lapis: prefix 'migrasi-manual' ada di
 *   AuthFilter::$adminRoutes, DAN dicek ulang inline di setiap
 *   method (pola yang sama dipakai Auth::userManagement() dkk).
 * - Endpoint yang bisa menjalankan migration lewat URL sebaiknya
 *   TIDAK menyala permanen di production. Setelah migration yang
 *   kamu butuhkan sudah berhasil jalan, sebaiknya:
 *     a) hapus 2 baris route-nya di app/Config/Routes.php, ATAU
 *     b) minimal, jangan share link ini ke siapa pun selain admin
 *        yang benar-benar butuh.
 * - Method jalankan() memanggil ->latest() dari CodeIgniter
 *   MigrationRunner, yang HANYA menjalankan file migration yang
 *   sudah ada di app/Database/Migrations/ (bukan menerima kode dari
 *   input user), dan idempotent — migration yang sudah pernah
 *   tercatat di tabel `migrations` tidak akan dijalankan ulang.
 * - Tetap disarankan backup database dulu sebelum menjalankan
 *   migration apa pun di production.
 */
class MigrasiManual extends BaseController
{
    private function cekAdmin()
    {
        if (!session()->get('isLoggedIn') || session()->get('role') != 'admin') {
            return redirect()->to('/login')->with('error', 'Akses ditolak.');
        }
        return null;
    }

    /**
     * Halaman status: migration apa saja yang tersedia, mana yang
     * sudah pernah jalan, mana yang masih pending.
     */
    public function index()
    {
        if ($redirect = $this->cekAdmin()) {
            return $redirect;
        }

        $migrate = Services::migrations();

        $tersedia = [];
        $sudahJalan = [];
        $errorBaca = null;

        try {
            // Daftar semua file migration yang terdeteksi framework,
            // dikelompokkan per namespace.
            foreach ($migrate->findMigrations() as $namespace => $daftar) {
                foreach ($daftar as $versi => $migrasi) {
                    $tersedia[] = [
                        'namespace' => $namespace,
                        'versi'     => $versi,
                        'class'     => $migrasi->class ?? '-',
                        'nama'      => $migrasi->name ?? basename((string) ($migrasi->path ?? '')),
                    ];
                }
            }
        } catch (\Throwable $e) {
            $errorBaca = 'Gagal membaca daftar migration: ' . $e->getMessage();
        }

        try {
            foreach ($migrate->getHistory() as $row) {
                $sudahJalan[] = $row->version . '_' . $row->class;
            }
        } catch (\Throwable $e) {
            // Tabel `migrations` kemungkinan belum pernah dibuat —
            // artinya belum pernah migrate sama sekali. Ini bukan
            // error fatal, cukup anggap "belum ada yang jalan".
        }

        $data = [
            'title'      => 'Migrasi Database | AULIA',
            'content'    => 'migrasi/index',
            'tersedia'   => $tersedia,
            'sudahJalan' => $sudahJalan,
            'errorBaca'  => $errorBaca,
        ];

        return view('layout/main', $data);
    }

    /**
     * Eksekusi migration ke versi terbaru (setara `php spark migrate`).
     */
    public function jalankan()
    {
        if ($redirect = $this->cekAdmin()) {
            return $redirect;
        }

        $konfirmasi = $this->request->getPost('konfirmasi');
        if ($konfirmasi !== 'JALANKAN') {
            return redirect()->to('/migrasi-manual')->with('error', 'Konfirmasi tidak sesuai. Ketik JALANKAN persis untuk melanjutkan.');
        }

        $migrate = Services::migrations();

        try {
            $sukses = $migrate->latest();
        } catch (\Throwable $e) {
            return redirect()->to('/migrasi-manual')->with('error', 'Migrasi gagal: ' . $e->getMessage());
        }

        // Ambil log proses migration (dari CLI messages) dan bersihkan
        // kode warna ANSI supaya rapi saat ditampilkan di halaman web.
        $log = array_map(
            static fn ($pesan) => preg_replace('/\x1b\[[0-9;]*m/', '', (string) $pesan),
            $migrate->getCliMessages()
        );

        if ($sukses === false) {
            return redirect()->to('/migrasi-manual')
                ->with('error', 'Migrasi tidak berhasil dijalankan. Lihat log di bawah.')
                ->with('migrasi_log', $log);
        }

        return redirect()->to('/migrasi-manual')
            ->with('success', 'Migrasi berhasil dijalankan (atau memang sudah up-to-date).')
            ->with('migrasi_log', $log);
    }
}
