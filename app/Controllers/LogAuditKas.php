<?php

namespace App\Controllers;

use App\Models\CashExpenseAuditModel;

/**
 * TODO-L3 (paket halaman log): halaman admin read-only untuk tabel
 * `cash_expense_audit` (riwayat EDIT/HAPUS pengeluaran kas -- TODO-BL10,
 * lihat `CashExpenseModel::updatePengeluaran()`/`hapusPengeluaran()`).
 *
 * Admin-only, pola proteksi sama dengan ArchiveTransaksi/MigrasiManual:
 * 1. prefix 'log-audit-kas' ada di AuthFilter::$adminRoutes.
 * 2. dicek ulang inline di cekAdmin() tiap method.
 *
 * Murni READ-ONLY: tidak ada method yang menulis ke `cash_expense_audit`
 * atau `cash_expense`. `data_sebelum`/`data_sesudah` adalah snapshot JSON
 * PENUH baris `cash_expense` pada saat kejadian -- TIDAK di-JOIN ke
 * `cash_expense.id` (expense_id sengaja tanpa FK, lihat komentar migrasi:
 * baris `delete` harus tetap terbaca walau baris sumbernya sudah hilang).
 */
class LogAuditKas extends BaseController
{
    private function cekAdmin()
    {
        if (!session()->get('isLoggedIn') || session()->get('role') != 'admin') {
            return redirect()->to('/login')->with('error', 'Akses ditolak.');
        }

        return null;
    }

    public function index()
    {
        if ($redirect = $this->cekAdmin()) {
            return $redirect;
        }

        return view('layout/main', [
            'title'   => 'Log Audit Kas | AULIA',
            'content' => 'log_audit_kas/index',
        ]);
    }

    public function data()
    {
        if ($redirect = $this->cekAdmin()) {
            return $redirect;
        }

        // Nama parameter standar proyek (lihat App\Config\DatePicker /
        // date-range.js, dan AC-5 di docs/design/2026-10-02-standardisasi-
        // pemilih-tanggal.md).
        $tanggalMulai = $this->validasiTanggal($this->request->getGet('tanggal_awal'));
        $tanggalSampai = $this->validasiTanggal($this->request->getGet('tanggal_akhir'));
        $halaman = max(1, (int) ($this->request->getGet('halaman') ?? 1));
        $perHalaman = 25;
        $offset = ($halaman - 1) * $perHalaman;

        $model = new CashExpenseAuditModel();
        $rows = $model->daftarUntukLog($tanggalMulai, $tanggalSampai, $perHalaman, $offset);
        $total = $model->hitungUntukLog($tanggalMulai, $tanggalSampai);

        $data = array_map(static function (array $row) {
            $sebelum = json_decode((string) $row['data_sebelum'], true) ?: [];
            $sesudah = $row['data_sesudah'] !== null ? (json_decode((string) $row['data_sesudah'], true) ?: []) : null;

            return [
                'id'           => (int) $row['id'],
                'created_at'   => $row['created_at'],
                'kasir'        => $row['nama'] ?: ($row['username'] ?: ('#' . $row['user_id'])),
                'expense_id'   => (int) $row['expense_id'],
                'aksi'         => $row['aksi'],
                'kategori'     => $sesudah['kategori'] ?? ($sebelum['kategori'] ?? null),
                'nominal_sebelum' => $sebelum['nominal'] ?? null,
                'nominal_sesudah' => $sesudah['nominal'] ?? null,
                'keterangan_sebelum' => $sebelum['keterangan'] ?? null,
                'keterangan_sesudah' => $sesudah['keterangan'] ?? null,
                'tanggal_transaksi'  => $sesudah['tanggal'] ?? ($sebelum['tanggal'] ?? null),
            ];
        }, $rows);

        return $this->response->setJSON([
            'status'      => 'success',
            'data'        => $data,
            'total'       => $total,
            'halaman'     => $halaman,
            'per_halaman' => $perHalaman,
        ]);
    }

    private function validasiTanggal(?string $tanggal): ?string
    {
        if ($tanggal === null || $tanggal === '') {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) ? $tanggal : null;
    }
}
