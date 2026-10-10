<?php

namespace App\Controllers;

use App\Models\MessageSendAuditModel;
use App\Models\UserModel;

/**
 * TODO-L3: halaman admin read-only untuk tabel `message_send_audit`
 * (kiriman keluar Inbox yang GAGAL -- lihat Inbox::gatewayFailureResponse()).
 *
 * Admin-only, pola proteksi sama dengan ArchiveTransaksi/MigrasiManual:
 * 1. prefix 'log-kirim-gagal' ada di AuthFilter::$adminRoutes.
 * 2. dicek ulang inline di cekAdmin() tiap method, jaga-jaga kalau
 *    filter di atas suatu saat berubah/lupa di-apply ke route baru.
 *
 * Murni READ-ONLY: tidak ada method yang menulis ke `message_send_audit`
 * atau tabel lain. `user_id` adalah logical reference ke
 * `aulia_kasirdb.users` (DB berbeda dari `message_send_audit` yang ada
 * di DB `inbox`) -- nama kasir di-resolve terpisah lewat UserModel,
 * BUKAN lewat JOIN SQL (tidak mungkin, beda database; lihat pola yang
 * sama di ConversationModel.php).
 */
class LogKirimGagal extends BaseController
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
            'title'   => 'Log Kiriman Gagal | AULIA',
            'content' => 'log_kirim_gagal/index',
        ]);
    }

    /**
     * AJAX -- data tabel (pagination server-side sederhana, bukan DataTables
     * penuh karena volume data diperkirakan rendah-sedang; lihat catatan
     * desain di docs/sesi).
     */
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

        $model = new MessageSendAuditModel();
        $rows = $model->daftarUntukLog($tanggalMulai, $tanggalSampai, $perHalaman, $offset);
        $total = $model->hitungUntukLog($tanggalMulai, $tanggalSampai);

        // user_id adalah logical reference ke DB LAIN (aulia_kasirdb.users) --
        // tidak bisa di-JOIN SQL dari DB 'inbox', resolve terpisah di sini
        // lalu digabung per baris (pola sama dengan ConversationModel.php).
        $userIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row) => $row['user_id'] ?? null,
            $rows
        ))));
        $namaUser = [];
        if (!empty($userIds)) {
            $users = (new UserModel())->whereIn('id', $userIds)->findAll();
            foreach ($users as $user) {
                $namaUser[(int) $user['id']] = $user['nama'] ?: $user['username'];
            }
        }

        $data = array_map(static function (array $row) use ($namaUser) {
            $namaKontak = $row['group_name'] ?: ($row['contact_name'] ?: ($row['whatsapp_name'] ?: $row['phone']));

            return [
                'id'               => (int) $row['id'],
                'created_at'       => $row['created_at'],
                'kasir'            => $row['user_id'] ? ($namaUser[(int) $row['user_id']] ?? ('#' . $row['user_id'])) : '-',
                'conversation_id'  => $row['conversation_id'] !== null ? (int) $row['conversation_id'] : null,
                'kontak'           => $namaKontak ?: '-',
                'phone'            => $row['phone'] ?? null,
                'context'          => $row['context'],
                'outcome_kind'     => $row['outcome_kind'],
                'error_code'       => $row['error_code'],
                'error_message'    => $row['error_message'],
                'preview_text'     => $row['preview_text'],
                'media_type'       => $row['media_type'],
                'media_file_name'  => $row['media_file_name'],
                'media_size'       => $row['media_size'] !== null ? (int) $row['media_size'] : null,
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

    /**
     * Validasi longgar: hanya terima format `YYYY-MM-DD`, selain itu
     * dianggap "tidak difilter" (null) -- konsisten dengan pola
     * `Laporan::itemHarian()` yang juga tidak menolak keras input tanggal
     * aneh, cukup mengabaikannya.
     */
    private function validasiTanggal(?string $tanggal): ?string
    {
        if ($tanggal === null || $tanggal === '') {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) ? $tanggal : null;
    }
}
