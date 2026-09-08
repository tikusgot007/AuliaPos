<?php

namespace App\Controllers;

use App\Models\JadwalModel;
use App\Models\MasterJadwalModel;
use App\Models\UserModel;

/**
 * Modul Jadwal Karyawan.
 *
 * Seluruh modul ini ADMIN-ONLY (lihat AuthFilter::$adminRoutes) --
 * keputusan yang diambil karena role AULIA baru hanya admin/kasir
 * dan tidak ada kebutuhan eksplisit kasir mengakses jadwal. Kalau
 * nanti dibutuhkan akses read-only untuk kasir, ini titik yang perlu
 * disesuaikan (lihat catatan di laporan implementasi).
 *
 * PENTING: modul ini adalah PLANNED SCHEDULE, bukan attendance.
 * Tidak ada logic di sini yang menyimpulkan kehadiran aktual dari
 * P/S/PM/L, dan tidak bergantung pada data absensi apa pun.
 */
class Jadwal extends BaseController
{
    protected JadwalModel $jadwalModel;
    protected MasterJadwalModel $masterModel;
    protected UserModel $userModel;

    public function __construct()
    {
        $this->jadwalModel = new JadwalModel();
        $this->masterModel = new MasterJadwalModel();
        $this->userModel   = new UserModel();
    }

    // ================================================================
    // HALAMAN
    // ================================================================

    /**
     * Shell halaman + data Matrix untuk minggu berjalan (mode
     * default). Kalender/Master/Analisis dimuat via AJAX saat tab
     * yang bersangkutan dibuka (lihat method *Data() di bawah).
     */
    public function index()
    {
        $mingguAwal = $this->request->getGet('minggu') ?: $this->awalMinggu(date('Y-m-d'));

        $matrix = $this->buildMatrix($mingguAwal);

        $data = [
            'title'    => 'Jadwal Karyawan | AULIA',
            'content'  => 'jadwal/index',
            'matrix'   => $matrix,
            'divisiList' => $this->daftarDivisi(),
        ];

        return view('layout/main', $data);
    }

    // ================================================================
    // HELPER TANGGAL
    // ================================================================

    private function awalMinggu(string $tanggal): string
    {
        $ts = strtotime($tanggal);
        $dow = (int) date('N', $ts); // 1=Senin .. 7=Minggu

        return date('Y-m-d', strtotime('-' . ($dow - 1) . ' days', $ts));
    }

    private function daftarDivisi(): array
    {
        return $this->userModel
            ->distinct()
            ->select('divisi')
            ->where('divisi IS NOT NULL')
            ->orderBy('divisi', 'ASC')
            ->findColumn('divisi') ?? [];
    }

    // ================================================================
    // MATRIX
    // ================================================================

    private function buildMatrix(string $mingguAwal): array
    {
        $mingguAkhir = date('Y-m-d', strtotime('+6 days', strtotime($mingguAwal)));

        $karyawan = $this->jadwalModel->getKaryawanUntukPeriode($mingguAwal, $mingguAkhir);
        $jadwalRows = $this->jadwalModel->getJadwalPeriode($mingguAwal, $mingguAkhir);

        // Index shift + id per [karyawan_id][tanggal] untuk lookup O(1)
        // saat render DAN supaya JS tahu id row tanpa perlu re-fetch
        // (dipakai saat hapus/swap satu cell).
        $peta = [];
        $petaId = [];

        foreach ($jadwalRows as $r) {
            $peta[$r['karyawan_id']][$r['tanggal']] = $r['shift'];
            $petaId[$r['karyawan_id']][$r['tanggal']] = (int) $r['id'];
        }

        // Ringkasan Ketersediaan (menggantikan weekly summary lama --
        // lihat docs Section "Modul Jadwal Karyawan"). getStatistik()
        // TIDAK dihapus (dipertahankan di model), hanya berhenti
        // dipakai di response Matrix ini.
        $availability = $this->jadwalModel->getAvailability($mingguAwal, $mingguAkhir);

        return [
            'minggu_awal'  => $mingguAwal,
            'minggu_akhir' => $mingguAkhir,
            'karyawan'     => $karyawan,
            'peta'         => $peta,
            'peta_id'      => $petaId,
            'availability' => $availability,
        ];
    }

    /**
     * AJAX: data matrix untuk navigasi minggu tanpa reload penuh.
     */
    public function matrixData()
    {
        $minggu = $this->request->getGet('minggu') ?: $this->awalMinggu(date('Y-m-d'));
        $divisi = $this->request->getGet('divisi') ?: null;
        $shift  = $this->request->getGet('shift') ?: null;
        $search = $this->request->getGet('search') ?: null;

        $mingguAkhir = date('Y-m-d', strtotime('+6 days', strtotime($minggu)));

        $karyawan = $this->jadwalModel->getKaryawanUntukPeriode($minggu, $mingguAkhir, $divisi, $search);
        $jadwalRows = $this->jadwalModel->getJadwalPeriode($minggu, $mingguAkhir, $divisi, $shift, $search);

        $peta = [];
        $petaId = [];

        foreach ($jadwalRows as $r) {
            $peta[$r['karyawan_id']][$r['tanggal']] = $r['shift'];
            $petaId[$r['karyawan_id']][$r['tanggal']] = (int) $r['id'];
        }

        // Ketersediaan SENGAJA tidak menerima $divisi/$shift/$search --
        // lihat dokumentasi keputusan di JadwalModel::getAvailability().
        // Filter Matrix hanya memengaruhi baris karyawan yang tampil,
        // bukan ringkasan staffing.
        $availability = $this->jadwalModel->getAvailability($minggu, $mingguAkhir);

        return $this->response->setJSON([
            'status' => 'success',
            'minggu_awal'  => $minggu,
            'minggu_akhir' => $mingguAkhir,
            'karyawan'     => $karyawan,
            'peta'         => $peta,
            'peta_id'      => $petaId,
            'availability' => $availability,
        ]);
    }

    // ================================================================
    // CALENDAR
    // ================================================================

    /**
     * AJAX: sumber event untuk FullCalendar. PM ditampilkan sebagai
     * DUA event (dua sesi waktu) tapi keduanya membawa id row yang
     * SAMA (satu schedule) -- edit/delete salah satu event PM di UI
     * harus memperlakukan keduanya sebagai satu paket (ditangani di
     * JS: cari semua event dengan schedule_id yang sama).
     */
    public function calendarEvents()
    {
        $start = $this->request->getGet('start');
        $end   = $this->request->getGet('end');
        $divisi = $this->request->getGet('divisi') ?: null;
        $shift  = $this->request->getGet('shift') ?: null;
        $search = $this->request->getGet('search') ?: null;

        if (!$start || !$end) {
            return $this->response->setJSON([]);
        }

        $rows = $this->jadwalModel->getJadwalPeriode(
            date('Y-m-d', strtotime($start)),
            date('Y-m-d', strtotime('-1 day', strtotime($end))),
            $divisi,
            $shift,
            $search
        );

        $warna = ['P' => '#f0ad4e', 'S' => '#337ab7', 'PM' => '#5cb85c', 'L' => '#d9534f'];
        $events = [];

        foreach ($rows as $r) {
            $sesi = JadwalModel::jamShift($r['shift']);

            if (empty($sesi)) {
                // Libur: satu event tanpa jam spesifik (all-day).
                $events[] = [
                    'id'            => (string) $r['id'],
                    'schedule_id'   => (int) $r['id'],
                    'title'         => ($r['inisial'] ?: $r['nama']) . ' - Libur',
                    'start'         => $r['tanggal'],
                    'allDay'        => true,
                    'color'         => $warna['L'],
                    'extendedProps' => [
                        'karyawan_id' => (int) $r['karyawan_id'],
                        'nama'        => $r['nama'],
                        'inisial'     => $r['inisial'],
                        'divisi'      => $r['divisi'],
                        'shift'       => 'L',
                    ],
                ];

                continue;
            }

            foreach ($sesi as $s) {
                $events[] = [
                    'id'            => (string) $r['id'] . '-' . $s['mulai'],
                    'schedule_id'   => (int) $r['id'],
                    'title'         => ($r['inisial'] ?: $r['nama']) . ' - ' . $r['shift'],
                    'start'         => $r['tanggal'] . 'T' . $s['mulai'],
                    'end'           => $r['tanggal'] . 'T' . $s['selesai'],
                    'color'         => $warna[$r['shift']] ?? '#777',
                    'extendedProps' => [
                        'karyawan_id' => (int) $r['karyawan_id'],
                        'nama'        => $r['nama'],
                        'inisial'     => $r['inisial'],
                        'divisi'      => $r['divisi'],
                        'shift'       => $r['shift'],
                    ],
                ];
            }
        }

        return $this->response->setJSON($events);
    }

    // ================================================================
    // ANALISIS
    // ================================================================

    public function analisisData()
    {
        $start  = $this->request->getGet('start') ?: $this->awalMinggu(date('Y-m-d'));
        $end    = $this->request->getGet('end') ?: date('Y-m-d', strtotime('+6 days', strtotime($start)));
        $divisi = $this->request->getGet('divisi') ?: null;

        $hasil = $this->jadwalModel->getAnalisisKombinasi($start, $end, $divisi);

        return $this->response->setJSON(array_merge(['status' => 'success'], $hasil));
    }

    // ================================================================
    // EMPLOYEE PICKER (hanya aktif -- Section 12 & 14)
    // ================================================================

    public function karyawanAktif()
    {
        $divisi = $this->request->getGet('divisi') ?: null;

        $builder = $this->userModel->where('is_active', 1);

        if (!empty($divisi)) {
            $builder->where('divisi', $divisi);
        }

        $data = $builder->orderBy('nama', 'ASC')
            ->select('id, nama, inisial, divisi')
            ->findAll();

        return $this->response->setJSON(['status' => 'success', 'data' => $data]);
    }

    // ================================================================
    // CREATE / EDIT SCHEDULE (Section 14 & 15)
    // ================================================================

    /**
     * Simpan satu atau beberapa schedule sekaligus (multi-karyawan,
     * satu tanggal, satu shift -- pola create dari AULIA LAMA yang
     * masih relevan). Edit manual tunggal juga lewat sini (kirim
     * karyawan_id tunggal).
     *
     * Validasi backend (Section 32):
     * - karyawan harus ada;
     * - untuk schedule BARU, karyawan harus is_active=1 (Section 12,
     *   14). Edit manual pada karyawan yang SUDAH punya row (biasanya
     *   dari histori) tetap diizinkan meski sekarang nonaktif --
     *   supaya histori tetap bisa dikoreksi;
     * - shift harus salah satu dari P/S/PM/L;
     * - tidak boleh menghasilkan duplicate (dijamin simpanJadwal()
     *   lewat unique constraint + INSERT-atau-UPDATE).
     *
     * TIDAK ADA validasi divisi di sini (Section 15 -- edit manual
     * tidak dibatasi divisi; itu cuma berlaku untuk swap).
     */
    public function simpan()
    {
        $request = $this->request->getJSON();

        $tanggal = $request->tanggal ?? null;
        $shift   = $request->shift ?? null;
        $karyawanIds = $request->karyawan_id ?? [];

        if (!is_array($karyawanIds)) {
            $karyawanIds = [$karyawanIds];
        }

        if (!$tanggal || !$shift || empty($karyawanIds)) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Tanggal, shift, dan minimal satu karyawan wajib diisi.',
            ]);
        }

        if (!in_array($shift, JadwalModel::SHIFT_VALID, true)) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Shift tidak valid.',
            ]);
        }

        $disimpan = [];
        $ditolak = [];

        foreach ($karyawanIds as $karyawanId) {
            $karyawan = $this->userModel->find($karyawanId);

            if (!$karyawan) {
                $ditolak[] = ['karyawan_id' => $karyawanId, 'alasan' => 'Karyawan tidak ditemukan.'];

                continue;
            }

            $sudahAdaHistori = $this->jadwalModel
                ->where('karyawan_id', $karyawanId)
                ->countAllResults() > 0;

            // Karyawan nonaktif hanya boleh diedit kalau memang sudah
            // punya histori (mengoreksi data lama), bukan dijadikan
            // schedule benar-benar baru.
            if (!$karyawan['is_active'] && !$sudahAdaHistori) {
                $ditolak[] = [
                    'karyawan_id' => $karyawanId,
                    'alasan' => 'Karyawan nonaktif tidak dapat dipilih untuk schedule baru.',
                ];

                continue;
            }

            $id = $this->jadwalModel->simpanJadwal((int) $karyawanId, $tanggal, $shift);
            $disimpan[] = ['karyawan_id' => (int) $karyawanId, 'id' => $id];
        }

        if (empty($disimpan)) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Tidak ada schedule yang berhasil disimpan.',
                'ditolak' => $ditolak,
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'message' => count($disimpan) . ' schedule berhasil disimpan.',
            'disimpan' => $disimpan,
            'ditolak' => $ditolak,
        ]);
    }

    public function hapus($id)
    {
        $ok = $this->jadwalModel->hapusJadwal((int) $id);

        return $this->response->setJSON([
            'status' => $ok ? 'success' : 'error',
            'message' => $ok ? 'Schedule berhasil dihapus.' : 'Schedule tidak ditemukan / gagal dihapus.',
        ]);
    }

    /**
     * Hapus range (Section 34). ADMIN-ONLY -- dicek ulang di sini
     * secara eksplisit meskipun seluruh modul sudah admin-only lewat
     * AuthFilter, sebagai defense-in-depth (pola yang sama dipakai di
     * TransaksiModel::ubahStatus()). Hanya menyentuh tabel jadwal,
     * tidak pernah master.
     */
    public function hapusRange()
    {
        if (session()->get('role') !== 'admin') {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Hanya admin yang dapat menghapus jadwal.',
            ]);
        }

        $request = $this->request->getJSON();
        $start = $request->start ?? null;
        $end   = $request->end ?? null;

        if (!$start || !$end) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Rentang tanggal wajib diisi.',
            ]);
        }

        $jumlah = $this->jadwalModel->hapusRange($start, $end);

        return $this->response->setJSON([
            'status' => 'success',
            'message' => "{$jumlah} schedule berhasil dihapus.",
        ]);
    }

    // ================================================================
    // SWAP (Section 16, 17, 18) -- SAME DIVISION WAJIB DI BACKEND
    // ================================================================

    /**
     * Tukar schedule dua karyawan. Body JSON:
     * { schedule_id_a, schedule_id_b }
     *
     * DESAIN (revisi 2026-09-05 setelah ditemukan bug saat testing):
     * yang ditukar adalah NILAI SHIFT dua baris, BUKAN kepemilikan
     * baris (karyawan_id tetap, tanggal tetap). Alasan revisi:
     * pendekatan awal (tukar karyawan_id antar baris) rawan bentrok
     * unique(karyawan_id,tanggal) begitu Master Jadwal diterapkan --
     * karena setiap karyawan sudah otomatis punya baris di semua 7
     * hari, hampir semua swap lintas tanggal akan menabrak baris
     * milik sendiri di tanggal tujuan. Menukar nilai shift saja tidak
     * pernah bisa bentrok (karyawan_id+tanggal tiap baris tidak
     * pernah berubah), dan tetap mendukung swap bebas lintas tanggal
     * apa pun sesuai kebutuhan (lihat diskusi fitur ini).
     *
     * Karena PM cuma satu row (bukan dua seperti AULIA LAMA), swap
     * PM otomatis jadi satu paket -- nilai string 'PM' dipindah utuh,
     * tidak perlu logic tambahan seperti getEventsToSwap() di versi
     * lama.
     *
     * VALIDASI BACKEND (celah yang ditemukan di AULIA LAMA -- di sana
     * cek divisi HANYA ada di JavaScript, backend tidak pernah
     * memvalidasi ulang):
     * - kedua schedule harus ada;
     * - kedua karyawan harus divisi yang SAMA;
     * - kedua shift harus BEDA (kalau sama, tidak ada yang perlu
     *   ditukar -- ini juga otomatis mencakup kasus lama "Libur vs
     *   Libur di tanggal sama", tapi sekarang berlaku general untuk
     *   shift apa pun, bukan cuma Libur).
     */
    public function swap()
    {
        $request = $this->request->getJSON();
        $idA = $request->schedule_id_a ?? null;
        $idB = $request->schedule_id_b ?? null;

        if (!$idA || !$idB) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Dua schedule harus dipilih untuk swap.',
            ]);
        }

        $jadwalA = $this->jadwalModel->find($idA);
        $jadwalB = $this->jadwalModel->find($idB);

        if (!$jadwalA || !$jadwalB) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Salah satu schedule tidak ditemukan.',
            ]);
        }

        $karyawanA = $this->userModel->find($jadwalA['karyawan_id']);
        $karyawanB = $this->userModel->find($jadwalB['karyawan_id']);

        if (!$karyawanA || !$karyawanB) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Data karyawan tidak ditemukan.',
            ]);
        }

        // --- VALIDASI WAJIB: DIVISI SAMA (Section 16) ---
        if ($karyawanA['divisi'] !== $karyawanB['divisi']) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Swap hanya diperbolehkan antar karyawan dengan divisi yang sama.',
            ]);
        }

        // --- CABANG KHUSUS: TUKAR LIBUR (Section 18) ---
        //
        // Kalau kedua cell sama-sama 'L' milik DUA karyawan berbeda,
        // sekadar menukar label 'L'<->'L' tidak ada efeknya. Maksud
        // sebenarnya "tukar libur" adalah RELOKASI hari libur: hari
        // libur A pindah ke tanggal B, hari libur B pindah ke tanggal
        // A -- dan masing-masing gantian mengerjakan shift yang tadinya
        // dikerjakan lawannya di tanggal itu (konsep dari AULIA LAMA,
        // diadaptasi ke skema baru tanpa INSERT/DELETE: murni tukar
        // nilai shift antara 4 baris yang SUDAH ADA, jadi tidak pernah
        // bentrok unique(karyawan_id,tanggal) karena karyawan_id dan
        // tanggal tiap baris tidak pernah berubah).
        if (
            $jadwalA['shift'] === 'L'
            && $jadwalB['shift'] === 'L'
            && $jadwalA['karyawan_id'] !== $jadwalB['karyawan_id']
        ) {
            return $this->swapLibur($jadwalA, $jadwalB);
        }

        if ($jadwalA['shift'] === $jadwalB['shift']) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Kedua schedule sudah memiliki shift yang sama, tidak ada yang perlu ditukar.',
            ]);
        }

        // --- Tukar NILAI SHIFT saja. karyawan_id & tanggal masing- ---
        // --- masing baris tidak pernah berubah, jadi tidak mungkin ---
        // --- bentrok dengan unique(karyawan_id, tanggal).          ---
        $db = \Config\Database::connect();
        $db->transStart();

        $this->jadwalModel->update($jadwalA['id'], ['shift' => $jadwalB['shift']]);
        $this->jadwalModel->update($jadwalB['id'], ['shift' => $jadwalA['shift']]);

        $db->transComplete();

        if (!$db->transStatus()) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Gagal menukar schedule.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'message' => 'Swap berhasil.',
        ]);
    }

    /**
     * Tukar HARI LIBUR antara dua karyawan (dipanggil dari swap()
     * ketika kedua cell yang dipilih sama-sama 'L').
     *
     * $jadwalA = row Libur karyawan A di tanggal A.
     * $jadwalB = row Libur karyawan B di tanggal B.
     *
     * Hasil akhir: A libur di tanggal B (bukan tanggal A lagi), B
     * libur di tanggal A (bukan tanggal B lagi); masing-masing
     * mengambil alih shift kerja yang tadinya dikerjakan lawannya di
     * tanggal tsb.
     *
     * Diimplementasikan sebagai DUA swap-nilai-shift searah-tanggal:
     * - di tanggal A: tukar shift antara baris A (L) dan baris B di
     *   tanggal A (kerja);
     * - di tanggal B: tukar shift antara baris B (L) dan baris A di
     *   tanggal B (kerja).
     * Tidak pernah INSERT/DELETE, tidak pernah ubah karyawan_id atau
     * tanggal baris mana pun -- jadi tidak mungkin bentrok unique
     * constraint apa pun kondisinya.
     */
    private function swapLibur(array $jadwalA, array $jadwalB)
    {
        $rowBDiTanggalA = $this->jadwalModel
            ->where('karyawan_id', $jadwalB['karyawan_id'])
            ->where('tanggal', $jadwalA['tanggal'])
            ->first();

        $rowADiTanggalB = $this->jadwalModel
            ->where('karyawan_id', $jadwalA['karyawan_id'])
            ->where('tanggal', $jadwalB['tanggal'])
            ->first();

        if (!$rowBDiTanggalA || !$rowADiTanggalB) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Tukar libur butuh kedua karyawan sudah memiliki schedule di tanggal masing-masing lawan.',
            ]);
        }

        if ($rowBDiTanggalA['shift'] === 'L' || $rowADiTanggalB['shift'] === 'L') {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Tukar libur tidak berlaku karena salah satu karyawan juga libur di tanggal lawan.',
            ]);
        }

        $db = \Config\Database::connect();
        $db->transStart();

        // Di tanggal A: A jadi kerja (ambil shift B), B jadi libur.
        $shiftKerjaB = $rowBDiTanggalA['shift'];
        $this->jadwalModel->update($jadwalA['id'], ['shift' => $shiftKerjaB]);
        $this->jadwalModel->update($rowBDiTanggalA['id'], ['shift' => 'L']);

        // Di tanggal B: B jadi kerja (ambil shift A), A jadi libur.
        $shiftKerjaA = $rowADiTanggalB['shift'];
        $this->jadwalModel->update($jadwalB['id'], ['shift' => $shiftKerjaA]);
        $this->jadwalModel->update($rowADiTanggalB['id'], ['shift' => 'L']);

        $db->transComplete();

        if (!$db->transStatus()) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Gagal menukar libur.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'message' => 'Tukar libur berhasil.',
        ]);
    }

    // ================================================================
    // MASTER JADWAL (Section 20-26)
    // ================================================================

    public function master()
    {
        // Untuk implementasi sederhana (Section 21): satu master aktif.
        // Kalau belum ada, buat otomatis supaya UI selalu punya target
        // untuk diedit.
        $master = $this->masterModel->orderBy('id', 'ASC')->first();

        if (!$master) {
            $id = $this->masterModel->insert(['nama' => 'Master Jadwal'], true);
            $master = $this->masterModel->find($id);
        }

        $detail = $this->masterModel->getDetailMaster($master['id']);
        $karyawanAktif = $this->userModel->where('is_active', 1)->orderBy('nama', 'ASC')->findAll();

        return $this->response->setJSON([
            'status' => 'success',
            'master' => $master,
            'detail' => $detail['detail'],
            'karyawan_aktif' => $karyawanAktif,
        ]);
    }

    public function masterSimpanCell()
    {
        $request = $this->request->getJSON();
        $masterId = $request->master_id ?? null;
        $karyawanId = $request->karyawan_id ?? null;
        $hari = $request->hari ?? null;
        $shift = $request->shift ?? null;

        if (!$masterId || !$karyawanId || !$hari || !$shift) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Data tidak lengkap.',
            ]);
        }

        if (!in_array($shift, JadwalModel::SHIFT_VALID, true)) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Shift tidak valid.',
            ]);
        }

        if ((int) $hari < 1 || (int) $hari > 7) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Hari tidak valid.',
            ]);
        }

        $this->masterModel->simpanCell((int) $masterId, (int) $karyawanId, (int) $hari, $shift);

        return $this->response->setJSON(['status' => 'success']);
    }

    public function masterHapusCell()
    {
        $request = $this->request->getJSON();
        $masterId = $request->master_id ?? null;
        $karyawanId = $request->karyawan_id ?? null;
        $hari = $request->hari ?? null;

        if (!$masterId || !$karyawanId || !$hari) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Data tidak lengkap.',
            ]);
        }

        $this->masterModel->hapusCell((int) $masterId, (int) $karyawanId, (int) $hari);

        return $this->response->setJSON(['status' => 'success']);
    }

    /**
     * Terapkan master ke N minggu. Default: isi slot kosong saja
     * (Section 24). overwrite eksplisit dari body (Section 25).
     */
    public function masterApply()
    {
        $request = $this->request->getJSON();
        $masterId = $request->master_id ?? null;
        $startMinggu = $request->start_minggu ?? null;
        $jumlahMinggu = (int) ($request->jumlah_minggu ?? 0);
        $overwrite = (bool) ($request->overwrite ?? false);

        if (!$masterId || !$startMinggu || $jumlahMinggu < 1) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Master, tanggal mulai, dan jumlah minggu wajib diisi.',
            ]);
        }

        if ((int) date('N', strtotime($startMinggu)) !== 1) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Tanggal mulai harus hari Senin.',
            ]);
        }

        $hasil = $this->masterModel->applyMaster(
            (int) $masterId,
            $startMinggu,
            $jumlahMinggu,
            $overwrite
        );

        return $this->response->setJSON(array_merge(['status' => 'success'], $hasil));
    }

    // ================================================================
    // ROSTER (READ-ONLY, untuk role KASIR -- juga bisa diakses admin)
    // ================================================================
    //
    // Semua method di bawah ini HANYA membaca data. Tidak ada satupun
    // yang melakukan INSERT/UPDATE/DELETE. Route-nya sengaja berada
    // di prefix terpisah (/roster/*, lihat Routes.php) supaya TIDAK
    // ikut ter-blok oleh AuthFilter::$adminRoutes yang melindungi
    // seluruh prefix /jadwal/* -- dengan begitu mutation Admin tetap
    // 100% terkunci tanpa perlu mengubah aturan admin-only yang sudah
    // ada. Query di sini seluruhnya reuse method JadwalModel yang
    // sama dipakai controller Matrix Admin (tidak ada logic/SQL baru
    // yang diduplikasi).
    //
    // Halaman ini bukan attendance -- statusSaatIni() murni informasi,
    // tidak pernah dipakai untuk keputusan izin/block di mana pun.

    public function rosterIndex()
    {
        $minggu = $this->request->getGet('minggu') ?: $this->awalMinggu(date('Y-m-d'));
        $mingguAkhir = date('Y-m-d', strtotime('+6 days', strtotime($minggu)));

        $karyawan = $this->jadwalModel->getKaryawanUntukPeriode($minggu, $mingguAkhir);
        $jadwalRows = $this->jadwalModel->getJadwalPeriode($minggu, $mingguAkhir);

        $peta = [];

        foreach ($jadwalRows as $r) {
            $peta[$r['karyawan_id']][$r['tanggal']] = $r['shift'];
        }

        $data = [
            'title'      => 'Jadwal Karyawan | AULIA',
            'content'    => 'roster/index',
            'minggu_awal'  => $minggu,
            'minggu_akhir' => $mingguAkhir,
            'karyawan'   => $karyawan,
            'peta'       => $peta,
            'divisiList' => $this->daftarDivisi(),
            'hariIni'    => $this->buildRosterHariIni(date('Y-m-d')),
        ];

        return view('layout/main', $data);
    }

    /**
     * AJAX: navigasi minggu untuk tampilan Mingguan (read-only).
     * Sengaja TIDAK memakai peta_id -- roster tidak pernah butuh id
     * schedule karena tidak ada aksi edit/hapus dari sini.
     */
    public function rosterMatrixData()
    {
        $minggu = $this->request->getGet('minggu') ?: $this->awalMinggu(date('Y-m-d'));
        $divisi = $this->request->getGet('divisi') ?: null;
        $shift  = $this->request->getGet('shift') ?: null;
        $search = $this->request->getGet('search') ?: null;

        $mingguAkhir = date('Y-m-d', strtotime('+6 days', strtotime($minggu)));

        $karyawan = $this->jadwalModel->getKaryawanUntukPeriode($minggu, $mingguAkhir, $divisi, $search);
        $jadwalRows = $this->jadwalModel->getJadwalPeriode($minggu, $mingguAkhir, $divisi, $shift, $search);

        $peta = [];

        foreach ($jadwalRows as $r) {
            $peta[$r['karyawan_id']][$r['tanggal']] = $r['shift'];
        }

        return $this->response->setJSON([
            'status' => 'success',
            'minggu_awal'  => $minggu,
            'minggu_akhir' => $mingguAkhir,
            'karyawan'     => $karyawan,
            'peta'         => $peta,
        ]);
    }

    /**
     * AJAX: tampilan Bulanan (read-only). Bulan diberikan sebagai
     * 'YYYY-MM'; kalau kosong pakai bulan berjalan.
     */
    public function rosterBulanData()
    {
        $bulan = $this->request->getGet('bulan') ?: date('Y-m');
        $divisi = $this->request->getGet('divisi') ?: null;
        $shift  = $this->request->getGet('shift') ?: null;
        $search = $this->request->getGet('search') ?: null;

        $awalBulan = $bulan . '-01';
        $akhirBulan = date('Y-m-t', strtotime($awalBulan));

        $karyawan = $this->jadwalModel->getKaryawanUntukPeriode($awalBulan, $akhirBulan, $divisi, $search);
        $jadwalRows = $this->jadwalModel->getJadwalPeriode($awalBulan, $akhirBulan, $divisi, $shift, $search);

        $peta = [];

        foreach ($jadwalRows as $r) {
            $peta[$r['karyawan_id']][$r['tanggal']] = $r['shift'];
        }

        return $this->response->setJSON([
            'status' => 'success',
            'bulan'       => $bulan,
            'awal_bulan'  => $awalBulan,
            'akhir_bulan' => $akhirBulan,
            'jumlah_hari' => (int) date('t', strtotime($awalBulan)),
            'karyawan'    => $karyawan,
            'peta'        => $peta,
        ]);
    }

    /**
     * Ringkasan roster HARI INI, dikelompokkan per shift, dengan
     * penanda karyawan yang sedang login (Section 4 & 27 docs).
     */
    private function buildRosterHariIni(string $tanggal): array
    {
        $rows = $this->jadwalModel->getJadwalPeriode($tanggal, $tanggal);
        $userIdLogin = (int) (session()->get('id_user') ?? 0);

        $grup = ['P' => [], 'S' => [], 'PM' => [], 'L' => []];
        $karyawanDijadwalkan = [];

        foreach ($rows as $r) {
            $grup[$r['shift']][] = [
                'karyawan_id' => (int) $r['karyawan_id'],
                'nama'        => $r['nama'],
                'inisial'     => $r['inisial'],
                'divisi'      => $r['divisi'],
                'saya'        => (int) $r['karyawan_id'] === $userIdLogin,
            ];
            $karyawanDijadwalkan[] = (int) $r['karyawan_id'];
        }

        // Belum dijadwalkan = karyawan aktif yang TIDAK punya row hari ini.
        // Bukan employee non-aktif tanpa histori -- di sini murni employee
        // aktif yang relevan untuk hari ini (Section 5, 29 docs: no-row != L).
        $karyawanAktif = $this->userModel->where('is_active', 1)->orderBy('nama', 'ASC')->findAll();
        $belumDijadwalkan = [];

        foreach ($karyawanAktif as $k) {
            if (!in_array((int) $k['id'], $karyawanDijadwalkan, true)) {
                $belumDijadwalkan[] = [
                    'karyawan_id' => (int) $k['id'],
                    'nama'        => $k['nama'],
                    'inisial'     => $k['inisial'],
                    'divisi'      => $k['divisi'],
                    'saya'        => (int) $k['id'] === $userIdLogin,
                ];
            }
        }

        return [
            'tanggal'          => $tanggal,
            'pagi'             => $grup['P'],
            'siang'            => $grup['S'],
            'pm'               => $grup['PM'],
            'libur'            => $grup['L'],
            'belum_dijadwalkan' => $belumDijadwalkan,
        ];
    }

    public function rosterHariIni()
    {
        $tanggal = $this->request->getGet('tanggal') ?: date('Y-m-d');

        return $this->response->setJSON(array_merge(
            ['status' => 'success'],
            $this->buildRosterHariIni($tanggal)
        ));
    }

    /**
     * Endpoint RINGAN untuk polling periodik status jadwal user yang
     * SEDANG LOGIN (bukan dari parameter URL -- Section 11 docs).
     * Hanya query satu baris (karyawan_id + tanggal hari ini), TIDAK
     * menarik seluruh roster (Section 22 docs).
     *
     * Hasil ini MURNI INFORMASI untuk notifikasi -- tidak pernah
     * dipakai untuk otorisasi apa pun.
     */
    public function statusJadwalSaya()
    {
        $userId = (int) (session()->get('id_user') ?? 0);

        if (!$userId) {
            return $this->response->setJSON(['status' => 'error', 'message' => 'Belum login.']);
        }

        $tanggal = date('Y-m-d');
        $row = $this->jadwalModel->getJadwalHariIni($userId, $tanggal);
        $shift = $row['shift'] ?? null;

        $hasil = JadwalModel::statusSaatIni($shift);

        return $this->response->setJSON([
            'status'        => 'success', // status API call, BUKAN status jadwal
            'shift'         => $shift,
            'tanggal'       => $tanggal,
            'jadwal_status' => $hasil['status'], // sesuai/belum_masuk/lewat/jeda/libur/belum_dijadwalkan
            'pesan'         => $hasil['pesan'],
            'toast'         => $hasil['toast'],
        ]);
    }
}
