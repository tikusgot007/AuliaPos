<?php

namespace App\Controllers;

use App\Models\ClosingKasAuditModel;

/**
 * TODO-BL28: halaman admin read-only untuk tabel `closing_kas_audit`
 * (riwayat REVISI closing kas -- lihat `ClosingKasModel::simpanClosing()`).
 *
 * Admin-only, pola proteksi sama dengan LogAuditKas:
 * 1. prefix 'log-closing-kas' ada di AuthFilter::$adminRoutes.
 * 2. dicek ulang inline di cekAdmin() tiap method.
 *
 * Murni READ-ONLY: tidak ada method yang menulis ke `closing_kas_audit`
 * atau `closing_kas`. `data_sebelum`/`data_sesudah` adalah snapshot JSON PENUH
 * baris `closing_kas` pada saat revisi.
 */
class LogClosingKas extends BaseController
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
            'title'   => 'Log Revisi Closing Kas | AULIA',
            'content' => 'log_closing_kas/index',
        ]);
    }

    public function data()
    {
        if ($redirect = $this->cekAdmin()) {
            return $redirect;
        }

        $tanggalMulai = $this->validasiTanggal($this->request->getGet('tanggal_awal'));
        $tanggalSampai = $this->validasiTanggal($this->request->getGet('tanggal_akhir'));
        $halaman = max(1, (int) ($this->request->getGet('halaman') ?? 1));
        $perHalaman = 25;
        $offset = ($halaman - 1) * $perHalaman;

        $model = new ClosingKasAuditModel();
        $rows = $model->daftarUntukLog($tanggalMulai, $tanggalSampai, $perHalaman, $offset);
        $total = $model->hitungUntukLog($tanggalMulai, $tanggalSampai);

        $data = array_map(static function (array $row) {
            $sebelum = json_decode((string) $row['data_sebelum'], true) ?: [];
            $sesudah = json_decode((string) $row['data_sesudah'], true) ?: [];

            return [
                'id'                => (int) $row['id'],
                'created_at'        => $row['created_at'],
                'admin'             => $row['nama'] ?: ($row['username'] ?: ('#' . $row['user_id'])),
                'closing_id'        => (int) $row['closing_id'],
                'tanggal_closing'   => $sesudah['tanggal'] ?? ($sebelum['tanggal'] ?? null),
                'saldo_sistem_sebelum' => $sebelum['saldo_sistem'] ?? null,
                'saldo_sistem_sesudah' => $sesudah['saldo_sistem'] ?? null,
                'saldo_fisik_sebelum'  => $sebelum['saldo_fisik'] ?? null,
                'saldo_fisik_sesudah'  => $sesudah['saldo_fisik'] ?? null,
                'selisih_sebelum'      => $sebelum['selisih'] ?? null,
                'selisih_sesudah'      => $sesudah['selisih'] ?? null,
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
