# Aturan Bisnis AULIA — Modul Pendukung

> **Sumber asli:** `aturan-bisnis-AULIA.md` (Section 20, 24, 25, 26, 28, 30).
> Setiap modul di file ini **independen** dari domain Transaksi & Kasir
> (`AULIA-01-transaksi-dan-kasir.md`) — tidak ada irisan tabel/business
> rule kecuali disebutkan eksplisit. Rujukan ke section asli memakai
> format `[AULIA §N]`. Riwayat bug & pekerjaan terbuka masing-masing
> modul ada di `AULIA-CHANGELOG.md`.

---

## Daftar Isi

1. [Modul Jadwal Karyawan](#1-modul-jadwal-karyawan)
2. [Fitur Profil Saya + Pengelolaan Profil oleh Admin](#2-fitur-profil-saya--pengelolaan-profil-oleh-admin)
3. [Notifikasi, Konfirmasi & Reminder Tagihan](#3-notifikasi-konfirmasi--reminder-tagihan)
4. [Preview Banner](#4-preview-banner)
5. [Archive Transaksi](#5-archive-transaksi)
6. [Cetak Nota: "Cetak Langsung" & "Pilih Printer"](#6-cetak-nota-cetak-langsung--pilih-printer)

---

## 1. Modul Jadwal Karyawan
`[AULIA §20]` — domain baru (2026-09-05), **independen** dari transaksi/kasir. Tidak ada irisan tabel, tidak ada irisan business rule, dan sengaja dipisah code-path-nya supaya perubahan di modul ini tidak pernah menyentuh transaksi/kasir dan sebaliknya.

### 1.1 Tujuan & 4 mode
`[§20.1]` **Jadwal Karyawan** (`/jadwal`, admin-only) punya 4 mode dalam satu halaman, default **Matrix**:
- **Matrix** — roster mingguan (Karyawan × Sen-Min), tampilan operasional utama, klik cell untuk lihat/edit/tambah/hapus.
- **Kalender** — FullCalendar, detail jam per shift.
- **Master Jadwal** — template mingguan yang bisa diterapkan (apply) ke beberapa minggu ke depan.
- **Analisis** — kombinasi karyawan yang pernah/belum pernah bekerja bersama.

### 1.2 Business rule shift
`[§20.2]`

```text
P  = Pagi   08:00-15:00
S  = Siang  13:30-20:30
PM = PM     08:00-12:30 & 18:00-20:30 (SATU row, dua sesi)
L  = Libur eksplisit (row ada, shift='L')
-  = BELUM DIJADWALKAN (tidak ada row sama sekali)
```

Aturan mutlak: **tidak ada row ≠ Libur**. `-` tidak pernah diubah jadi `L` di mana pun.

**PM = satu business schedule**, bukan dua shift terpisah — ditegaskan di seluruh operasi (create/edit/delete/swap memperlakukan satu row PM sebagai satu paket). Ini sengaja berbeda dari AULIA lama yang menyimpan PM sebagai 2 row terpisah.

**Tidak ada batas jumlah hari kerja** (tidak ada validasi "maksimal N hari", "wajib libur", dst). Statistik hanya informasi; PM dihitung 1 hari kerja meski 2 sesi.

**Jadwal ≠ Absensi.** Modul ini planned schedule, tidak ada tabel attendance/clock-in-out/payroll. `P/S/PM` tidak berarti "pasti hadir", `L` tidak berarti "pasti tidak bekerja" — itu urusan modul lain di luar scope ini.

### 1.3 Database
`[§20.3]`
- `users` + `is_active TINYINT(1) DEFAULT 1` — aktif = bisa dipilih untuk schedule baru; nonaktif = tidak bisa dipilih baru, tapi tetap tampil di histori kalau punya row jadwal lama. **Bukan pengganti role.**
- Tabel `jadwal`: `id, karyawan_id, tanggal, shift ENUM('P','S','PM','L'), created_at, updated_at`. `UNIQUE(karyawan_id, tanggal)`. FK ke `users.id` **tanpa** `ON DELETE CASCADE` (histori jadwal harus mencegah physical delete user yang masih punya histori). `karyawan_id` harus `INT(11)` **signed** (mismatch signedness pernah menyebabkan error FK — lihat `AULIA-CHANGELOG.md`).
- Tabel `master_jadwal` (header template) + `master_jadwal_detail` (`master_jadwal_id, karyawan_id, hari 1-7, shift`). Detail `ON DELETE CASCADE` ke master; ke `users` tanpa cascade.

### 1.4 Aturan create/edit/swap
`[§20.4]`
- **Create/Edit manual**: employee picker HANYA `is_active=1` untuk schedule baru. Edit manual **tidak dibatasi divisi**.
- **Swap**: WAJIB divisi sama, divalidasi **di backend**. Yang ditukar adalah **NILAI SHIFT** dua baris, bukan kepemilikan baris (`karyawan_id`/`tanggal` tiap baris tidak pernah berubah) — supaya tidak mungkin bentrok `unique(karyawan_id,tanggal)`. Kalau kedua shift sama, ditolak.
  - **Cabang khusus: tukar Libur** (kedua cell sama-sama `L`, karyawan beda) — hari libur direlokasi (libur A pindah ke tanggal B, libur B pindah ke tanggal A), masing-masing mengambil alih shift kerja yang tadinya dikerjakan lawannya di tanggal itu. Butuh kedua karyawan sudah punya schedule kerja di tanggal masing-masing lawan; kalau salah satu belum dijadwalkan atau juga libur di sana, ditolak dengan pesan jelas.
  - PM di-swap sebagai satu paket otomatis.
- **Delete range**: admin-only, hanya menghapus `jadwal` (actual), tidak pernah menyentuh `master_jadwal`.

### 1.5 Master Jadwal & Apply
`[§20.5]` Master adalah **template**, bukan actual schedule — hidup independen: edit master tidak mengubah `jadwal` yang sudah di-apply sebelumnya, dan sebaliknya. Master membedakan `L` (eksplisit) dari kosong (tidak ada assignment) — sama seperti aturan `-` di actual schedule.

**Apply master** ke N minggu ke depan, default **"isi slot kosong saja"** — kalau actual schedule sudah ada di tanggal tsb, TIDAK ditimpa, dilaporkan sebagai conflict. Overwrite eksplisit tersedia sebagai opsi terpisah (checkbox + konfirmasi).

### 1.6 Role / Authorization
`[§20.6]` Mutation (`/jadwal/simpan`, `/hapus`, `/hapus-range`, `/swap`, `/master/*`) admin-only lewat `AuthFilter::$adminRoutes`.

**Keputusan desain penting:** endpoint read-only untuk Kasir (roster) sengaja diberi **prefix URL berbeda** (`/roster/*`, bukan `/jadwal/*`) supaya otomatis tidak ikut ter-blok oleh `$adminRoutes`. Endpoint `/roster/*` cukup filter `auth` biasa (login saja, role apa pun).

### 1.7 Fitur Kasir — Roster read-only
`[§20.7]` Halaman `/roster` (menu sidebar "Jadwal Karyawan" untuk role kasir):
- **Ringkasan Hari Ini**: dikelompokkan per shift (Pagi/Siang/PM/Libur/Belum Dijadwalkan), karyawan yang login ditandai ★. "Belum Dijadwalkan" dihitung dari karyawan aktif yang tidak punya row hari itu — **bukan** dari row `shift='L'`.
- **Mingguan** & **Bulanan** — read-only, filter divisi/shift/search, histori karyawan nonaktif tetap tampil kalau punya jadwal di periode itu.
- Backend reuse method `JadwalModel` yang sama dipakai Matrix Admin (tidak ada duplikasi logic).
- **Validasi jadwal user login** (`GET /roster/status-saya`, polling 60 detik): query ringan (1 baris), dihitung **di server (PHP)**, bukan browser JS.

---

## 2. Fitur Profil Saya + Pengelolaan Profil oleh Admin
`[AULIA §24]` — 2026-09-06

### 2.1 Scope
`[§24.1]` Dua area di atas tabel `users` yang sudah ada (tidak ada tabel `profile` baru):
- **Profil Saya** (`/profil`) — semua role login bisa ubah **nama**, **no. HP**, **foto profil** milik sendiri. Field `username`, `inisial`, `divisi`, `role`, `is_active` read-only di sini.
- **Manajemen User** (`/user-management`) — admin mengelola nama, no. HP, foto, username, inisial, divisi, role, status aktif milik user lain.

### 2.2 Database
`[§24.2]` Kolom baru di `users` (keduanya nullable): `no_hp` VARCHAR(20), `profile_photo` VARCHAR(255) (nama file saja).

### 2.3 Penyimpanan foto
`[§24.3]` File fisik disimpan di `WRITEPATH/uploads/foto_profil/`, **di luar** docroot publik — mencegah file upload dieksekusi sebagai PHP.

Nama file **selalu** dibuat server (`random_bytes` hex + ekstensi dari MIME tervalidasi), tidak pernah nama asli dari user. Validasi upload: ukuran maks 2MB, MIME whitelist (jpeg/png/webp), `getimagesize()` untuk memastikan isi file benar-benar gambar. Logic ada di satu tempat (`FotoProfilService`), dipakai ulang Profil Saya & Manajemen User.

Foto ditampilkan lewat `GET /foto-profil/{filename}` yang men-stream dari disk (bukan akses statis langsung); nama file divalidasi regex ketat (cegah path traversal). File lama dibersihkan dari disk setelah update database berhasil (tidak ada orphan file).

### 2.4 Otorisasi — aturan paling penting
`[§24.4]` `Profil::index()`, `update()`, `uploadFoto()`, `hapusFoto()` **selalu** memakai `session()->get('id_user')` sebagai target perubahan — tidak pernah membaca `user_id`/ID target dari form/request. User A tidak bisa mengubah profil user B walau memanipulasi request.

`UserModel::updateProfilSaya()` sengaja hanya menerima parameter `nama` dan `no_hp` (bukan array bebas) sebagai pertahanan kedua — field lain (role, is_active, dst) secara struktural tidak mungkin lewat method ini.

Manajemen User (admin) tetap pakai `$id` dari parameter route. Proteksi tambahan: admin tidak bisa mengubah role akun sendiri jadi non-admin atau menonaktifkan akun sendiri (mencegah mengunci diri sendiri dari akses admin).

### 2.5 Integrasi header/layout
`[§24.5]` Header menampilkan foto profil (jika ada) di avatar dropdown + link "Profil Saya". Foto diambil lewat query langsung ke `UserModel` di dalam view — **sengaja tidak disimpan ke session**, supaya perubahan foto langsung tercermin tanpa logout/login ulang.

### 2.6 Kompatibilitas
`[§24.6]` Tidak mengubah cara Jadwal Karyawan membaca `nama`/`inisial`/`divisi`/`is_active`. Route baru (`/profil`, `/foto-profil/*`) sengaja tidak match `AuthFilter::$adminRoutes` supaya kasir juga bisa akses.

---

## 3. Notifikasi, Konfirmasi & Reminder Tagihan
`[AULIA §25]` — 2026-09-06

### 3.1 Latar belakang
`[§25.1]` Sebelum perubahan ini ada 5 gaya notifikasi berbeda yang tidak seragam. Perubahan ini menyeragamkan 3 kategori (notifikasi hasil-aksi, konfirmasi aksi berbahaya) dan menambah 1 fitur baru (reminder tagihan). Info box statis (mis. keterangan role di form user) sengaja tidak diubah.

### 3.2 Alert box → Toast
`[§25.3]` Alert box dari flashdata dihapus total. Di `layout/main.php`, begitu ada flashdata `success`/`error`, langsung dipanggil `showToast()`. Semua notifikasi hasil-aksi (redirect maupun AJAX) konsisten lewat satu komponen toast.

`auth/login.php` adalah pengecualian disengaja (belum ada sesi login untuk toast container) — tetap pakai alert box biasa.

### 3.3 Toast: durasi per tipe & posisi
`[§25.4]` `showToast(message, type, opsi)`:
- **Durasi berbeda per tipe**: sukses/info 3 detik, warning 4 detik, error/danger **sticky** (harus di-close manual).
- **Posisi**: atas-tengah.
- **Opsi `onClick`** (baru): toast bisa diklik untuk memanggil fungsi (dipakai reminder tagihan, lihat 3.5).

Catatan keterbatasan: sistem toast masih **satu instance tunggal** (`#liveToast`), belum mendukung stacking. Kalau dua toast dipanggil nyaris bersamaan, yang kedua menimpa yang pertama — keterbatasan lama, tidak diperbaiki di perubahan ini (di luar scope).

### 3.4 Konfirmasi "Batalkan" transaksi — dari 2x jadi 1x
`[§25.5]` Tombol "Batalkan" sekarang memanggil `kirimUbahStatusAjax()` langsung setelah 1 konfirmasi (sebelumnya 2 modal berturutan).

### 3.5 Reminder Tagihan 3 Hari Terakhir
`[§25.6]` **Tujuan**: mengingatkan kasir (atau admin) soal tagihan yang dia garap sendiri dan masih "segar" (3 hari terakhir).

**Kriteria** (`Kasir::getReminderTagihanSaya()`, dipanggil dari `Kasir::index()`):
- `kasir_id` = user yang login
- `status_pembayaran` IN (`belum_bayar`, `dp`)
- `status != batal`
- `tanggal >= (sekarang − 3 hari)`

**Trigger**: dicek ulang tiap kali halaman `/kasir` dibuka (server-side, bukan polling AJAX). **Jeda anti-spam**: 15 menit (`session('reminder_tagihan_last_shown')`), hanya di-update saat toast benar-benar ditampilkan.

**Tampilan**: modal `konfirmasi()` (tombol **Tutup** / **Lihat**, bukan toast auto-hilang) — supaya reminder tidak hilang sebelum benar-benar direspons. Tombol **Lihat** membuka tab/window baru ke `/tagihan?saya=1`.

**Filter `?saya=1` di `Tagihan::index()`** (baru): menampilkan hanya tagihan `kasir_id` = user login. Nilai filter **selalu** dari `session()->get('id_user')`, tidak pernah dari query string. Berlaku untuk kasir dan admin — murni berdasarkan `kasir_id`, tidak ada pengecekan role.

---

## 4. Preview Banner
`[AULIA §26]` — 2026-09-07

### 4.1 Apa ini
`[§26.1]` Alat bantu internal untuk staff membuat gambar preview banner (lengkap garis ukuran & crop mark) sebelum dicetak, untuk dikirim ke customer lewat WhatsApp minta approval sebelum eksekusi cetak — mengurangi reprint akibat revisi mendadak.

Alur: upload desain → masukkan ukuran pesanan (lebar x tinggi cm) → generate preview. Kalau proporsi gambar vs ukuran pesanan beda jauh (>10% distorsi), otomatis tampil 5 opsi penyesuaian (Sesuai ukuran / Crop / Fit / Ikut Lebar / Ikut Tinggi).

### 4.2 Keputusan integrasi
`[§26.2]`
- **Murni client-side, tidak ada penyimpanan data.** Tidak terhubung ke transaksi, tidak ada tabel/endpoint API baru. Semua (upload, generate, download) terjadi di browser lewat `<canvas>`.
- **File asli TIDAK DIUBAH** (`public/tools/preview-banner.html`), ditampilkan lewat `<iframe>` — mengisolasi total CSS/JS-nya (mencegah class CSS umum seperti `.btn` bentrok dengan tampilan Bootstrap AULIA di halaman lain).
- **Bisa diakses semua role login** (kasir & admin), menu berdiri sendiri, route `/preview-banner`.

---

## 5. Archive Transaksi
`[AULIA §28]` — 2026-09-09

### 5.1 Tujuan & prinsip
`[§28.1]` Mengurangi beban database utama (MySQL) dan menghilangkan transaksi historis dari Tagihan/badge notifikasi, **tanpa kehilangan histori** yang dibutuhkan untuk laporan dan pencarian. Bersifat **manual sepenuhnya** — admin menjalankan lewat UI, **tidak ada cron/scheduler**.

Prinsip utama: **integritas data di atas segalanya**. Data hanya boleh dihapus dari MySQL setelah terbukti tersalin lengkap & valid di archive.

### 5.2 Aturan cutoff bulan eligible
`[§28.2]` **Bulan X eligible untuk di-archive kalau X berjarak ≥ 6 bulan PENUH dari bulan berjalan** (granularitas bulan, bukan tanggal persis). Contoh (hari ini 8 September 2026): Januari/Februari/Maret 2026 eligible, April 2026 belum.

Yang di-archive: **semua transaksi pada bulan eligible**, tanpa filter status transaksi/pembayaran. Status & nilai transaksi **tidak pernah diubah** saat archive.

### 5.3 Database archive
`[§28.3]` SQLite terpisah, koneksi CodeIgniter grup `archive`. Path default `WRITEPATH.'archive/aulia_pos_archive.db'`, bisa di-override lewat `.env` — database utama (MySQL) **tidak pernah** disentuh strukturnya.

Tabel: `transaksi_archive`, `detail_transaksi_archive`, `pembayaran_archive`. `id` di archive **sama persis** dengan `id` asli di MySQL (bukan autoincrement baru).

Nama pelanggan & kasir di-**snapshot** langsung ke archive saat archive dijalankan — supaya archive tetap self-contained walau data master di DB utama nanti berubah.

### 5.4 Alur eksekusi (destruktif, wajib berurutan)
`[§28.4]`

```text
Admin buka /archive-transaksi (admin-only)
        ↓
Sistem tampilkan bulan eligible
        ↓
Admin pilih 1+ bulan → Preview (read-only)
        ↓
Admin ketik "ARCHIVE" (unlock tombol) → modal konfirmasi
        ↓
1. Tulis backup JSON mentah ke writable/archive/backups/
2. Salin ke SQLite archive (INSERT OR REPLACE — idempotent)
3. VALIDASI: jumlah baris & total grand_total archive harus cocok
   PERSIS dengan sumber
        ↓
   Validasi gagal? → STOP. MySQL tidak tersentuh sama sekali.
        ↓ (valid)
4. Hapus dari MySQL (satu transaksi DB; detail_transaksi & pembayaran
   ikut lewat FK ON DELETE CASCADE)
```

Kalau langkah hapus dari MySQL sendiri gagal (setelah archive tervalidasi lengkap): data **tetap ada di kedua tempat** (tidak hilang), admin tinggal jalankan archive lagi untuk bulan yang sama — aman diulang.

### 5.5 Integrasi ke modul lain
`[§28.5]`

| Modul | Dampak |
|---|---|
| Tagihan + badge notifikasi | Otomatis aman, tanpa perubahan kode (query tidak pernah dibatasi tanggal) |
| Kas (`CashBalanceService`) | Tidak perlu diubah — saldo kas selalu query `pembayaran` untuk tanggal target (same-day) |
| Pencarian transaksi | Dual-source: keyword memicu query tambahan ke archive, hasil digabung & ditandai `_sumber` (aktif/archive) |
| Detail transaksi | Fallback ke archive kalau ID tidak ketemu di MySQL — halaman jadi read-only (semua tombol aksi disembunyikan) |
| `Tagihan::detail()` | **BELUM** di-fallback ke archive — risiko kecil |
| Cetak ulang/reprint | **Sengaja tidak didukung** untuk transaksi archive |
| Laporan | Dual-source penuh di semua jalur query, digabung di PHP sebelum masuk logic aggregasi asli |

### 5.6 Keterbatasan yang diketahui
`[§28.6]`
- Tidak ada UI restore otomatis (archive → MySQL) — bisa dikembalikan manual dari SQLite archive/backup JSON.
- File backup JSON menumpuk tanpa pembersihan otomatis — perlu dibersihkan manual sesekali.
- Belum ada test end-to-end dengan MySQL sungguhan.

---

## 6. Cetak Nota: "Cetak Langsung" & "Pilih Printer"
`[AULIA §30]` — 2026-09-09

### 6.1 Konsep
`[§30.1]` Tombol "Cetak Nota" sekarang dropdown dua pilihan:
- **Cetak Langsung** — server-side, langsung ke `\\AULIA-DP1\L300` (Epson L300), tanpa dialog print browser.
- **Pilih Printer** — perilaku LAMA (buka PDF/HTML di window baru, user pilih printer lewat dialog print browser) — **tidak diubah**, cuma dipindah jadi salah satu opsi dropdown.

### 6.2 Konfigurasi (`App\Config\PrintNota`)
`[§30.3]` Printer tujuan & path tool cetak **sepenuhnya di sisi server**, tidak pernah diterima dari request browser:

```php
public string $printerLangsung  = '\\\\AULIA-DP1\\L300';
public string $sumatraPdfPath   = 'C:\\Tools\\SumatraPDF\\SumatraPDF.exe';
public int    $timeoutDetik     = 25;
```

Override lewat `.env` tanpa ubah kode — default mengasumsikan tool **SumatraPDF** (portable, gratis, mendukung `-print-to <printer> -silent <file>`).

### 6.3 Flow "Cetak Langsung"
`[§30.4]`

```
Klik "Cetak Langsung"
  -> GET /cetak/nota-langsung/{id} (AJAX, read-only)
  -> fetch transaksi (SAMA persis dengan "Pilih Printer")
  -> render view cetak/nota.php -> HTML (SAMA template)
  -> Dompdf -> PDF A6 landscape
  -> simpan PDF sementara di writable/uploads/nota_print/
  -> proc_open([SumatraPDF, -print-to, <printer>, -silent, <file>])
  -> tunggu proses selesai (timeout 25 detik default)
  -> hapus file sementara (berhasil maupun gagal)
  -> log exit code + stdout + stderr
  -> JSON {status, message} ke browser (toast, TANPA dialog print)
```

Endpoint ini **read-only** terhadap transaksi (SELECT saja) — gagal generate PDF/kirim printer TIDAK PERNAH memengaruhi transaksi yang sudah tersimpan.

### 6.4 Regression Thermal
`[§30.5]` `Cetak::thermal()` **tidak disentuh sama sekali** — target `smb://guest@aulia6/POS-58` dan seluruh implementasinya tetap persis seperti sebelumnya.
