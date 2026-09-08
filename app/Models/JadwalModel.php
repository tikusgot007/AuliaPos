<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * JadwalModel — ACTUAL schedule (bukan template/master).
 *
 * Aturan inti yang ditegakkan di sini (lihat docs modul Jadwal
 * Karyawan untuk detail lengkap):
 * - shift hanya P/S/PM/L. Tidak ada row untuk suatu tanggal berarti
 *   "belum dijadwalkan" ('-'), BUKAN libur.
 * - PM adalah SATU row. Jam kerja tidak disimpan di tabel ini,
 *   selalu dihitung dari DEFINISI_SHIFT di bawah.
 * - Satu karyawan hanya boleh punya satu row per tanggal
 *   (unique constraint karyawan_id+tanggal di database; simpanJadwal()
 *   di bawah menegakkannya juga di level aplikasi lewat INSERT/UPDATE
 *   sesuai keberadaan row).
 */
class JadwalModel extends Model
{
    protected $table         = 'jadwal';
    protected $primaryKey    = 'id';
    protected $allowedFields = ['karyawan_id', 'tanggal', 'shift'];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public const SHIFT_VALID = ['P', 'S', 'PM', 'L'];

    /**
     * Divisi tetap yang ditampilkan di ringkasan Ketersediaan Matrix
     * (lihat getAvailability()). Bukan sumber kebenaran divisi
     * (kolom `users.divisi` tetap bebas), murni daftar yang relevan
     * untuk indikator staffing per permintaan bisnis saat ini.
     * Karyawan dengan divisi di luar daftar ini (mis. kosong, atau
     * divisi lain di masa depan) sengaja tidak dihitung.
     */
    public const DIVISI_KETERSEDIAAN = ['Wanita', 'Pria', 'Banner'];

    /**
     * Definisi jam per shift. Satu-satunya sumber kebenaran jam kerja
     * di seluruh modul ini -- jangan simpan jam di database.
     *
     * PM punya dua sesi tapi tetap SATU business schedule (lihat
     * Section 5 docs).
     */
    public const DEFINISI_SHIFT = [
        'P'  => ['label' => 'Pagi', 'sesi' => [['mulai' => '08:00', 'selesai' => '15:00']]],
        'S'  => ['label' => 'Siang', 'sesi' => [['mulai' => '13:30', 'selesai' => '20:30']]],
        'PM' => [
            'label' => 'PM',
            'sesi'  => [
                ['mulai' => '08:00', 'selesai' => '12:30'],
                ['mulai' => '18:00', 'selesai' => '20:30'],
            ],
        ],
        'L'  => ['label' => 'Libur', 'sesi' => []],
    ];

    public static function jamShift(string $shift): array
    {
        return self::DEFINISI_SHIFT[$shift]['sesi'] ?? [];
    }

    public static function labelShift(string $shift): string
    {
        return self::DEFINISI_SHIFT[$shift]['label'] ?? $shift;
    }

    /**
     * Pecah input filter "Cari karyawan" jadi beberapa istilah,
     * supaya user bisa cari beberapa nama sekaligus dalam satu kotak
     * pencarian, mis. "budi-siti" atau "budi, siti" -- tabel lalu
     * menampilkan baris yang cocok dengan SALAH SATU istilah (OR).
     *
     * Pemisah yang didukung: koma (,), titik-koma (;), atau strip (-).
     * Istilah kosong (spasi ganda, pemisah beruntun, dll) dibuang.
     * Kalau tidak ada pemisah sama sekali, hasilnya tetap array berisi
     * satu istilah -- perilaku identik dengan search satu-nama seperti
     * sebelumnya, tidak ada regresi.
     *
     * @return string[]
     */
    public static function pecahIstilahSearch(?string $search): array
    {
        if (!is_string($search) || trim($search) === '') {
            return [];
        }

        $bagian = preg_split('/[,;\-]+/', $search) ?: [];
        $bagian = array_map('trim', $bagian);

        return array_values(array_filter($bagian, fn ($s) => $s !== ''));
    }

    /**
     * Terapkan filter "Cari karyawan" (bisa multi-istilah, lihat
     * pecahIstilahSearch()) ke query builder yang sedang berjalan.
     * Setiap istilah dicek ke KEDUA kolom (nama ATAU inisial), dan
     * antar-istilah digabung dengan OR -- supaya baris yang cocok
     * dengan salah satu nama yang dicari tetap muncul.
     *
     * $kolomNama/$kolomInisial diteruskan sebagai parameter (bukan
     * hardcode) karena dua pemanggil (getJadwalPeriode() vs
     * getKaryawanUntukPeriode()) memakai builder dengan prefix kolom
     * berbeda ('users.nama' vs 'nama').
     */
    private function terapkanFilterSearch($builder, ?string $search, string $kolomNama, string $kolomInisial)
    {
        $istilah = self::pecahIstilahSearch($search);

        if (empty($istilah)) {
            return $builder;
        }

        $builder->groupStart();

        foreach ($istilah as $i => $kata) {
            if ($i === 0) {
                $builder->groupStart();
            } else {
                $builder->orGroupStart();
            }

            $builder->like($kolomNama, $kata)
                ->orLike($kolomInisial, $kata)
                ->groupEnd();
        }

        $builder->groupEnd();

        return $builder;
    }

    /**
     * Query RINGAN untuk validasi jadwal user yang sedang login --
     * HANYA satu karyawan + satu tanggal (bukan bulk roster). Dipakai
     * untuk polling periodik status jadwal diri sendiri (modul Kasir),
     * supaya tidak perlu menarik seluruh roster hanya untuk mengecek
     * satu orang (lihat Section 22 docs fitur Kasir).
     */
    public function getJadwalHariIni(int $karyawanId, string $tanggal): ?array
    {
        return $this->where('karyawan_id', $karyawanId)
            ->where('tanggal', $tanggal)
            ->first();
    }

    /**
     * Hitung status jadwal terhadap jam SAAT INI (server time, timezone
     * aplikasi -- lihat app_timezone(), sudah Asia/Jakarta, tidak perlu
     * dihitung ulang di browser supaya tidak kena masalah timezone
     * seperti bug Matrix sebelumnya).
     *
     * INI MURNI INFORMASI, BUKAN AUTHORIZATION -- pemanggil tidak boleh
     * pernah memblokir aksi apa pun berdasarkan hasil method ini.
     *
     * @param string|null $shift 'P'/'S'/'PM'/'L', atau null jika belum
     *                            dijadwalkan (tidak ada row).
     * @param string      $jamSekarang Format 'H:i', default waktu server.
     *
     * @return array{status:string, pesan:string, toast:string}
     */
    public static function statusSaatIni(?string $shift, ?string $jamSekarang = null): array
    {
        $jamSekarang = $jamSekarang ?? date('H:i');

        if ($shift === null) {
            return [
                'status' => 'belum_dijadwalkan',
                'pesan'  => 'Perhatian — Anda belum memiliki jadwal hari ini.',
                'toast'  => 'warning',
            ];
        }

        if ($shift === 'L') {
            return [
                'status' => 'libur',
                'pesan'  => 'Perhatian — hari ini Anda dijadwalkan libur.',
                'toast'  => 'warning',
            ];
        }

        $sesi = self::jamShift($shift);
        $label = self::labelShift($shift);
        $jamLabel = implode(' & ', array_map(
            fn($s) => $s['mulai'] . '–' . $s['selesai'],
            $sesi
        ));

        if (empty($sesi)) {
            // Seharusnya tidak pernah terjadi untuk P/S/PM, tapi jaga-jaga.
            return [
                'status' => 'tidak_diketahui',
                'pesan'  => 'Jadwal Anda hari ini: ' . $label . '.',
                'toast'  => 'info',
            ];
        }

        // Sebelum sesi pertama dimulai.
        if ($jamSekarang < $sesi[0]['mulai']) {
            return [
                'status' => 'belum_masuk',
                'pesan'  => "Perhatian — jadwal Anda hari ini {$label} ({$jamLabel}). Saat ini belum masuk jam kerja.",
                'toast'  => 'warning',
            ];
        }

        // Setelah sesi terakhir berakhir.
        $sesiTerakhir = end($sesi);
        if ($jamSekarang > $sesiTerakhir['selesai']) {
            return [
                'status' => 'lewat',
                'pesan'  => "Perhatian — jadwal Anda hari ini {$label} ({$jamLabel}). Saat ini sudah di luar jam jadwal.",
                'toast'  => 'warning',
            ];
        }

        // Di dalam salah satu sesi -> sesuai jadwal.
        foreach ($sesi as $s) {
            if ($jamSekarang >= $s['mulai'] && $jamSekarang <= $s['selesai']) {
                return [
                    'status' => 'sesuai',
                    'pesan'  => "Sesuai jadwal — {$label} ({$jamLabel}).",
                    'toast'  => 'success',
                ];
            }
        }

        // Di antara dua sesi (khusus PM: jeda 12:30-18:00).
        return [
            'status' => 'jeda',
            'pesan'  => "Perhatian — jadwal Anda hari ini {$label} ({$jamLabel}). Saat ini jeda di antara sesi.",
            'toast'  => 'warning',
        ];
    }

    /**
     * Ambil semua row jadwal dalam rentang tanggal, dengan data
     * karyawan (nama/inisial/divisi) di-JOIN sekali (bukan N+1).
     *
     * Dipakai untuk Matrix, Calendar, Analisis, Statistik -- semua
     * konsumen data schedule per-periode lewat method ini supaya
     * query-nya konsisten dan bulk.
     *
     * @param string|null $divisi Filter opsional.
     * @param string|null $shift  Filter opsional (P/S/PM/L).
     * @param string|null $search Cari nama/inisial karyawan. Bisa
     *                              multi-istilah dipisah koma/titik-
     *                              koma/strip, lihat pecahIstilahSearch().
     */
    public function getJadwalPeriode(
        string $tanggalMulai,
        string $tanggalSelesai,
        ?string $divisi = null,
        ?string $shift = null,
        ?string $search = null
    ): array {
        $builder = $this->select(
            'jadwal.id, jadwal.karyawan_id, jadwal.tanggal, jadwal.shift, ' .
                'users.nama, users.inisial, users.divisi, users.is_active'
        )
            ->join('users', 'users.id = jadwal.karyawan_id')
            ->where('jadwal.tanggal >=', $tanggalMulai)
            ->where('jadwal.tanggal <=', $tanggalSelesai);

        if (!empty($divisi)) {
            $builder->where('users.divisi', $divisi);
        }

        if (!empty($shift)) {
            $builder->where('jadwal.shift', $shift);
        }

        if (!empty($search)) {
            $this->terapkanFilterSearch($builder, $search, 'users.nama', 'users.inisial');
        }

        return $builder->orderBy('jadwal.tanggal', 'ASC')->findAll();
    }

    /**
     * Buat atau perbarui SATU schedule (INSERT/UPDATE sesuai
     * unique(karyawan_id,tanggal) -- tidak pernah menghasilkan
     * duplicate row untuk karyawan+tanggal yang sama).
     *
     * PM diperlakukan sebagai satu paket secara alami di sini karena
     * memang cuma satu row -- tidak ada logic tambahan yang
     * diperlukan dibanding shift lain.
     *
     * @throws \InvalidArgumentException jika shift tidak valid.
     */
    public function simpanJadwal(int $karyawanId, string $tanggal, string $shift): int
    {
        if (!in_array($shift, self::SHIFT_VALID, true)) {
            throw new \InvalidArgumentException('Shift tidak valid: ' . $shift);
        }

        $existing = $this->where('karyawan_id', $karyawanId)
            ->where('tanggal', $tanggal)
            ->first();

        if ($existing) {
            $this->update($existing['id'], ['shift' => $shift]);

            return (int) $existing['id'];
        }

        $id = $this->insert([
            'karyawan_id' => $karyawanId,
            'tanggal'     => $tanggal,
            'shift'       => $shift,
        ], true);

        return (int) $id;
    }

    /**
     * Hapus satu schedule. PM tetap satu row jadi cukup satu delete
     * (tidak perlu logic khusus dibanding shift lain).
     */
    public function hapusJadwal(int $id): bool
    {
        return (bool) $this->delete($id);
    }

    /**
     * Hapus semua schedule ACTUAL dalam rentang tanggal. Hanya
     * menyentuh tabel jadwal -- tidak pernah menghapus master.
     * Otorisasi (admin-only) adalah tanggung jawab controller.
     */
    public function hapusRange(string $tanggalMulai, string $tanggalSelesai): int
    {
        $builder = $this->where('tanggal >=', $tanggalMulai)
            ->where('tanggal <=', $tanggalSelesai);

        $jumlah = $builder->countAllResults(false);

        $this->where('tanggal >=', $tanggalMulai)
            ->where('tanggal <=', $tanggalSelesai)
            ->delete();

        return $jumlah;
    }

    /**
     * Ambil daftar karyawan yang relevan untuk Matrix/Calendar pada
     * suatu periode: karyawan AKTIF, DITAMBAH karyawan nonaktif yang
     * punya histori jadwal di periode tersebut (Section 12 -- jangan
     * hanya WHERE is_active=1 untuk tampilan histori).
     *
     * Ini KHUSUS untuk tampilan (matrix/calendar rows). Untuk employee
     * picker saat membuat jadwal BARU, pakai UserModel::getKaryawanAktif()
     * yang murni is_active=1.
     */
    public function getKaryawanUntukPeriode(
        string $tanggalMulai,
        string $tanggalSelesai,
        ?string $divisi = null,
        ?string $search = null
    ): array {
        $userModel = model(UserModel::class);

        // Karyawan yang punya histori jadwal di periode ini (termasuk
        // yang sudah nonaktif) -- Section 12.
        $karyawanIdHistori = $this->select('karyawan_id')
            ->distinct()
            ->where('tanggal >=', $tanggalMulai)
            ->where('tanggal <=', $tanggalSelesai)
            ->findColumn('karyawan_id') ?? [];

        $builder = $userModel->builder()->select('users.*');

        if (!empty($karyawanIdHistori)) {
            $builder->groupStart()
                ->where('is_active', 1)
                ->orWhereIn('id', $karyawanIdHistori)
                ->groupEnd();
        } else {
            $builder->where('is_active', 1);
        }

        if (!empty($divisi)) {
            $builder->where('divisi', $divisi);
        }

        if (!empty($search)) {
            $this->terapkanFilterSearch($builder, $search, 'nama', 'inisial');
        }

        return $builder->orderBy('nama', 'ASC')->get()->getResultArray();
    }

    /**
     * Statistik ringkas untuk suatu periode + set karyawan yang
     * relevan (dari getKaryawanUntukPeriode()). "Belum dijadwalkan"
     * dihitung dari jumlah_karyawan x jumlah_hari - jumlah_row, BUKAN
     * dari row shift='L' (Section 29 -- no-row != L).
     */
    public function getStatistik(
        string $tanggalMulai,
        string $tanggalSelesai,
        array $karyawanIds,
        ?string $divisi = null
    ): array {
        $stat = ['P' => 0, 'S' => 0, 'PM' => 0, 'L' => 0];

        if (empty($karyawanIds)) {
            $jumlahHari = (strtotime($tanggalSelesai) - strtotime($tanggalMulai)) / 86400 + 1;

            return [
                'P' => 0, 'S' => 0, 'PM' => 0, 'L' => 0,
                'belum_dijadwalkan' => 0,
                'total_slot' => 0,
                'jumlah_karyawan' => 0,
                'jumlah_hari' => (int) $jumlahHari,
            ];
        }

        $builder = $this->select('shift, COUNT(*) as jumlah')
            ->whereIn('karyawan_id', $karyawanIds)
            ->where('tanggal >=', $tanggalMulai)
            ->where('tanggal <=', $tanggalSelesai);

        if (!empty($divisi)) {
            $builder->join('users', 'users.id = jadwal.karyawan_id')
                ->where('users.divisi', $divisi);
        }

        $rows = $builder->groupBy('shift')->findAll();

        $totalTerjadwal = 0;

        foreach ($rows as $row) {
            $stat[$row['shift']] = (int) $row['jumlah'];
            $totalTerjadwal += (int) $row['jumlah'];
        }

        $jumlahHari = (int) ((strtotime($tanggalSelesai) - strtotime($tanggalMulai)) / 86400 + 1);
        $jumlahKaryawan = count($karyawanIds);
        $totalSlot = $jumlahKaryawan * $jumlahHari;

        $stat['belum_dijadwalkan'] = max(0, $totalSlot - $totalTerjadwal);
        $stat['total_slot'] = $totalSlot;
        $stat['jumlah_karyawan'] = $jumlahKaryawan;
        $stat['jumlah_hari'] = $jumlahHari;
        // "jumlah hari kerja" = P+S+PM (L bukan hari kerja, no-row juga bukan).
        $stat['hari_kerja'] = $stat['P'] + $stat['S'] + $stat['PM'];

        return $stat;
    }

    /**
     * Ringkasan ketersediaan tenaga kerja PER TANGGAL x PER DIVISI x
     * PER SHIFT untuk suatu rentang minggu -- pengganti weekly summary
     * lama (getStatistik()) di tampilan Matrix.
     *
     * SATU query agregasi untuk seluruh rentang (bukan per
     * tanggal/divisi/shift -- lihat requirement performa docs), hasil
     * diindex sebagai [tanggal][divisi][shift] => jumlah, sudah
     * diprapopulasi 0 untuk semua kombinasi supaya konsumen (JS) tidak
     * perlu menangani kunci yang tidak ada.
     *
     * Aturan hitung (murni fakta, bukan penilaian cukup/kurang):
     * - hanya users.is_active = 1;
     * - shift dihitung hanya P/S/PM (L dan "belum dijadwalkan" tidak
     *   dihitung sebagai ketersediaan);
     * - hanya karyawan dengan divisi yang match $divisiTarget persis.
     *
     * SENGAJA independen dari filter Shift/search Matrix (lihat
     * catatan keputusan di docs/aturan-bisnis-AULIA.md) -- pemanggil
     * tidak boleh meneruskan filter shift/search UI ke sini. Filter
     * Divisi Matrix juga sengaja TIDAK diteruskan: availability selalu
     * menampilkan ketiga divisi supaya tetap berguna sebagai
     * pembanding staffing antar-divisi, apa pun filter yang sedang
     * aktif di tabel.
     *
     * @param string[] $divisiTarget Daftar divisi yang dihitung (lihat
     *                                DIVISI_KETERSEDIAAN).
     *
     * @return array<string,array<string,array<string,int>>> [tanggal][divisi][shift]
     */
    public function getAvailability(
        string $tanggalMulai,
        string $tanggalSelesai,
        array $divisiTarget = self::DIVISI_KETERSEDIAAN
    ): array {
        $hasil = [];

        // Prapopulasi 0 untuk semua tanggal x divisi x shift dalam
        // rentang, supaya tanggal tanpa satu pun schedule tetap
        // tampil sebagai 0, bukan hilang dari struktur.
        $cursor = $tanggalMulai;

        while ($cursor <= $tanggalSelesai) {
            foreach ($divisiTarget as $d) {
                $hasil[$cursor][$d] = ['P' => 0, 'S' => 0, 'PM' => 0];
            }
            $cursor = date('Y-m-d', strtotime('+1 day', strtotime($cursor)));
        }

        if (empty($divisiTarget)) {
            return $hasil;
        }

        $rows = $this->select('jadwal.tanggal, users.divisi, jadwal.shift, COUNT(*) as jumlah')
            ->join('users', 'users.id = jadwal.karyawan_id')
            ->where('jadwal.tanggal >=', $tanggalMulai)
            ->where('jadwal.tanggal <=', $tanggalSelesai)
            ->where('users.is_active', 1)
            ->whereIn('users.divisi', $divisiTarget)
            ->whereIn('jadwal.shift', ['P', 'S', 'PM'])
            ->groupBy('jadwal.tanggal, users.divisi, jadwal.shift')
            ->findAll();

        foreach ($rows as $r) {
            $hasil[$r['tanggal']][$r['divisi']][$r['shift']] = (int) $r['jumlah'];
        }

        return $hasil;
    }

    /**
     * Analisis kombinasi karyawan yang bekerja bersama pada shift
     * yang sama di tanggal yang sama.
     *
     * Berbeda dari behavior AULIA LAMA (yang meng-skip PM dan Libur,
     * dan dihitung 100% di client-side dari event kalender yang
     * sedang tampil): di sini PM DIIKUTKAN sebagai satu grup kerja
     * (Section 28 requirement baru), Libur tetap di-skip (bukan
     * kombinasi "kerja bersama"), dan seluruh perhitungan dilakukan
     * di backend (bukan JavaScript) sesuai Section 36/38.
     *
     * @return array{combos: array<string,array{a:string,b:string,divisi:string,jumlah:int}>, karyawan: array}
     */
    public function getAnalisisKombinasi(
        string $tanggalMulai,
        string $tanggalSelesai,
        ?string $divisi = null
    ): array {
        $rows = $this->getJadwalPeriode($tanggalMulai, $tanggalSelesai, $divisi, null, null);

        // Kelompokkan per tanggal|shift (PM ikut, Libur di-skip --
        // Libur bukan "bekerja bersama").
        $grup = [];
        $personDivisi = [];
        $personSet = [];

        foreach ($rows as $r) {
            if ($r['shift'] === 'L') {
                continue;
            }

            $key = $r['tanggal'] . '|' . $r['shift'];
            $inisial = $r['inisial'] ?: $r['nama'];

            $grup[$key][$inisial] = true;
            $personDivisi[$inisial] = $r['divisi'];
            $personSet[$inisial] = true;
        }

        $combos = [];

        foreach ($grup as $anggota) {
            $nama = array_keys($anggota);
            sort($nama);
            $n = count($nama);

            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $pairKey = $nama[$i] . '|' . $nama[$j];

                    if (!isset($combos[$pairKey])) {
                        $combos[$pairKey] = [
                            'a'      => $nama[$i],
                            'b'      => $nama[$j],
                            'divisi' => $personDivisi[$nama[$i]] ?? '',
                            'jumlah' => 0,
                        ];
                    }

                    $combos[$pairKey]['jumlah']++;
                }
            }
        }

        // Pasangan yang belum pernah ketemu, hanya untuk divisi sama
        // (konsisten dengan behavior lama).
        $belumKetemu = [];
        $namaSemua = array_keys($personSet);
        sort($namaSemua);
        $n = count($namaSemua);

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $namaSemua[$i];
                $b = $namaSemua[$j];
                $divA = $personDivisi[$a] ?? '';
                $divB = $personDivisi[$b] ?? '';

                if (!$divA || !$divB || $divA !== $divB) {
                    continue;
                }

                $pairKey = $a . '|' . $b;

                if (!isset($combos[$pairKey])) {
                    $belumKetemu[] = ['a' => $a, 'b' => $b, 'divisi' => $divA];
                }
            }
        }

        usort($combos, fn($x, $y) => $y['jumlah'] <=> $x['jumlah']);

        return [
            'pernah_ketemu'    => array_values($combos),
            'belum_pernah'     => $belumKetemu,
            'jumlah_karyawan'  => count($namaSemua),
        ];
    }
}
