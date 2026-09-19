# AuliaPos — Core Business Rules

Single source of truth untuk aturan bisnis AuliaPos inti: transaksi, pembayaran, kas, tagihan, jadwal karyawan, laporan, dan modul pendukung lain. **Tidak mencakup** modul Chat/Inbox WhatsApp (hanya ada di branch `v2.2`, lihat `CHAT.md` di sana) atau definisi lengkap Priority/Shift Leader (lihat `USER-SHIFT.md` — di sini hanya dirujuk sebagai istilah terdefinisi).

Riwayat implementasi (P1–P15, audit teknis, bug fix) ada di `CHANGELOG.md`. Dokumen historis lengkap (termasuk section number yang dikutip komentar kode) ada di `archive/`.

---

## Daftar Isi

1. [Transaction](#1-transaction)
2. [Transaction Status](#2-transaction-status)
3. [Payment](#3-payment)
4. [Backdate Payment](#4-backdate-payment)
5. [Cash](#5-cash)
6. [Receivable / Tagihan](#6-receivable--tagihan)
7. [POS / Kasir](#7-pos--kasir)
8. [Employee Schedule](#8-employee-schedule)
9. [User Account & Profile](#9-user-account--profile)
10. [Notifications & Confirmations](#10-notifications--confirmations)
11. [Archive Transaksi](#11-archive-transaksi)
12. [Cetak Nota / Printing](#12-cetak-nota--printing)
13. [Reports](#13-reports)
14. [Permissions](#14-permissions)
15. [Known Implementation Gaps](#15-known-implementation-gaps)
16. [Developer Invariants](#16-developer-invariants)

---

## 1. Transaction
`Historical source: [AULIA §4.1, §5, §9, §10]`

### 1.1 Transaksi baru
Semua transaksi baru masuk dengan `status = proses`, **tidak bergantung** pada metode pembayaran (tunai, QRIS, transfer, DP, piutang — semuanya `proses`). `status_pembayaran` dihitung terpisah (lihat Section 2).

### 1.2 Edit Transaksi
Edit normal hanya berlaku untuk `status = proses` (bukan berdasarkan `status_pembayaran`). `selesai`/`batal` tidak bisa diedit. Kalau perlu menambah barang setelah transaksi `selesai` → buat **transaksi baru**. Kalau isi transaksi salah, SOP: **Batal → buat transaksi baru**.

**Perubahan total & kelebihan bayar (2026-09-04):** Edit boleh menaikkan **atau** menurunkan total belanja, tanpa batas bawah. Kalau total baru lebih kecil dari yang sudah dibayar: transaksi tetap tersimpan, `total_dibayar` **tidak diubah** (tetap sesuai pembayaran aktif sebenarnya), `status_pembayaran` otomatis `lunas`, selisihnya jadi **kelebihan bayar** (`total_dibayar - grand_total`), ditampilkan sebagai indikator UI. **Kelebihan bayar BUKAN refund** — edit transaksi tidak pernah mencatat refund otomatis ke kas; refund fisik (kalau memang dilakukan) adalah aksi manual terpisah lewat **Kas Keluar → kategori "Refund Penjualan"**. Sumber kebenaran `total_dibayar`/`status_pembayaran` setelah edit adalah `TransaksiModel::sinkronkanPembayaran()`, bukan hitungan manual controller.

**Alasan keputusan ini:** AULIA adalah POS toko tunggal tanpa infrastruktur "reopen transaksi + refund otomatis" ala POS besar. Menjaga item dan pembayaran dalam satu record yang sama (bukan Batal + transaksi baru) mengurangi kerja ulang kasir dan risiko rekonsiliasi manual.

### 1.3 No Order
No Order **bukan** nomor urut transaksi database — merepresentasikan **nama/nomor file foto fisik yang sedang diproses**. `recommended_no_order` adalah saran/kandidat (tertinggi hari ini +1, atau tertinggi keseluruhan +1), bukan generator wajib. Produk kategori Studio/Foto **membutuhkan** No Order; produk lain boleh tanpa. Setelah dipakai pada transaksi berhasil, nomor tidak lagi tersedia. Saat transaksi batal, `no_order = null` (dikosongkan, bukan dipertahankan — beda dari MANGKRAK, lihat Section 2.5).

### 1.4 Banner — Aturan Harga
**Baris tetap terpisah** — Banner ukuran sama **tidak otomatis digabung** (bisa berasal dari desain berbeda).

- **Total < 1 m²**: minimum pricing berdasarkan total luas seluruh item Banner. Rumus `totalArea × hargaPerM2 × 1.1`, dibulatkan Rp500, minimum Rp10.000. Contoh: 0,8 m² @ Rp22.000 → Rp19.360 → dibulatkan Rp19.500. Kalau beberapa baris: total dihitung global, harga dibagi proporsional (luas × qty), baris terakhir jadi residual balancer, grand total harus tepat sama dengan total global.
- **Total ≥ 1 m²**: tiap baris `luas × qty × hargaPerM2`, dibulatkan Rp500, tidak digabung, tidak ada biaya tambahan.

---

## 2. Transaction Status
`Historical source: [AULIA §2, §3, §4.1–§4.7, §12, §14, §29]`

### 2.1 Tiga status resmi
`proses`, `selesai`, `batal` (plus `mangkrak`, lihat 2.5). Status `diambil` **dihapus** dari lifecycle, tidak digunakan lagi.

```text
TRANSAKSI BARU → PROSES → [EDIT | BATAL] → SELESAI (FINAL)
```

- **`proses`**: berjalan. Boleh Edit, Batal, atau Selesai (hanya jika `lunas`, lihat 2.3).
- **`selesai`**: **FINAL** untuk pekerjaan. Berarti garapan sudah selesai dikerjakan **DAN** `status_pembayaran = lunas` — kedua syarat harus terpenuhi bersamaan. Tidak boleh Edit, tidak kembali ke `proses` lewat alur normal.
- **`batal`**: dibatalkan. Tidak boleh Edit/menerima pembayaran baru/masuk Tagihan. `no_order` dikosongkan. **Tidak otomatis** berarti refund; histori pembayaran tidak dihapus.

### 2.2 Status transaksi vs status pembayaran — independen
Status transaksi (kondisi pekerjaan) dan status pembayaran (kondisi uang: `belum_bayar`/`dp`/`lunas`) adalah **dua sumbu independen**. **`lunas` bukan status final transaksi** — `proses + lunas` tetap `proses` sampai eksplisit ditandai Selesai.

| Status transaksi | Status pembayaran | Arti |
|---|---|---|
| proses | belum_bayar / dp / lunas | pekerjaan berjalan |
| selesai | lunas | pekerjaan final dan lunas (satu-satunya kombinasi valid lewat transisi normal) |
| selesai | belum_bayar / dp | hanya bisa terjadi lewat reversal Koreksi Pembayaran (Section 3.1) setelah SELESAI |
| batal | apa pun | dibatalkan |

### 2.3 Syarat & siapa boleh menyelesaikan (`proses → selesai`)
Transisi hanya diizinkan jika: `status=proses` **DAN** `status_pembayaran=lunas` **DAN** dipicu oleh pihak berwenang. `lunas` ≠ otomatis `selesai` — tombol Selesai tetap harus ditekan eksplisit.

| Role/status saat itu | Workflow umum (`/api/ubah-status`) | Workflow Kasir/POS (`/api/kasir/selesaikan-transaksi`) |
|---|---|---|
| admin | Ya, jika lunas | Ya, jika lunas |
| Effective Shift Leader saat itu | Ya, jika lunas (Tahap 3) | Ya, jika lunas **dan** miliknya sendiri |
| kasir pemilik transaksi `kasir_pos` | Tidak | Ya, jika lunas **dan** `kasir_id === id_user` **dan** `sumber='kasir_pos'` |
| kasir biasa (bukan pemilik, bukan Leader) | Tidak | Tidak |

Definisi "Effective Shift Leader" — lihat `USER-SHIFT.md`. Enforcement backend: `TransaksiModel::ubahStatus()`. **UI bukan enforcement** — penyembunyian tombol hanya lapis pertama; backend selalu memvalidasi ulang role, kepemilikan (`kasir_id`), `sumber`, status pembayaran, dan status transaksi.

### 2.4 Pembatalan (`→ batal`)
Berlaku sama untuk `PROSES → BATAL` maupun `SELESAI → BATAL` (sejak Tahap 5.1, satu aturan tunggal untuk keduanya):

| Role/status saat itu | Boleh membatalkan? |
|---|---|
| admin | Ya |
| Effective Shift Leader saat itu | Ya |
| kasir biasa | **Tidak** |

Sebelum Tahap 5.1, `PROSES → BATAL` terbuka untuk semua role login — **diperketat** menyusul keputusan produk eksplisit menyamakan aturan dengan `SELESAI → BATAL`. Tombol "Batalkan" sejak Tahap 5.1 di-gate visibility-nya juga (sebelumnya selalu tampil semua role).

Pembatalan tidak menghapus histori pembayaran. Prinsip: histori dipertahankan, status jadi `batal`, refund adalah proses tersendiri (lihat Section 3.1), koreksi pembayaran memakai `reversed`/`aktif`.

### 2.5 Status MANGKRAK
Beda dari `batal` (yang berarti "transaksi ini dianggap tidak pernah terjadi"): `mangkrak` untuk transaksi yang **beneran terjadi** (ada order, kadang sudah ada DP/pekerjaan berjalan) tapi macet tanpa kejelasan — dilepas dari radar aktif (Tagihan, badge notifikasi, reminder kasir) tanpa mengklaim tidak pernah terjadi.

```
PROSES ──(tandai mangkrak, ADMIN-ONLY)──> MANGKRAK
SELESAI + belum lunas ──(tandai mangkrak, ADMIN-ONLY)──> MANGKRAK
MANGKRAK ──(aktifkan kembali, ADMIN-ONLY)──> PROSES
```

- Bisa ditandai dari **PROSES** (kasus umum), atau dari **SELESAI** kalau `status_pembayaran` bukan `lunas` (bisa terjadi kalau pembayaran di-reversal lewat Koreksi setelah sempat SELESAI).
- Dari MANGKRAK, satu-satunya jalan keluar adalah balik ke **PROSES** (tidak langsung ke SELESAI/BATAL).
- **Admin-only murni** — TIDAK diperluas ke Effective Shift Leader (beda dari BATAL di 2.4). Ini kapabilitas terpisah dari BATAL.
- `no_order` **TIDAK** dikosongkan (beda dari BATAL) — transaksi masih bisa dilanjutkan kapan saja.
- Laporan **TIDAK** mengecualikan mangkrak (tetap tercatat, data historis nyata). Archive Transaksi **tidak ada perlakuan khusus** — ikut ter-archive normal.

### 2.6 `/transaksi/batal/(:num)` — dead code
Route/controller lama (`Transaksi::batal()`), tidak dipakai di view manapun, tidak disentuh secara eksplisit oleh perubahan Shift Leader — tapi karena memanggil `TransaksiModel::ubahStatus()` yang sama, gate 2.4 otomatis berlaku juga kalau jalur ini suatu saat dipakai lagi.

---

## 3. Payment
`Historical source: [AULIA §6, §7]`

### 3.1 Koreksi Metode Pembayaran
Kesalahan isi transaksi (Batal + transaksi baru) berbeda dari kesalahan metode pembayaran (**Koreksi Pembayaran**) berbeda dari uang benar-benar dikembalikan (**Refund**, proses terpisah). `pembayaran.metode` tidak pernah diubah langsung — histori harus tetap dapat diaudit:

```text
pembayaran lama: aktif
        |
     koreksi
        |
        +--> lama = reversed
        +--> baru = aktif
```

Penghitung total pembayaran hanya menghitung pembayaran `status=aktif`.

### 3.2 Konsistensi Total Pembayaran
`transaksi.total_dibayar` adalah cache/denormalisasi (menghindari `SUM` berulang). Sumber kebenaran secara konsep: `SUM(pembayaran.jumlah WHERE status='aktif')`. Cache disinkronkan setelah pembayaran ditambahkan; tersedia sinkronisasi, pemeriksaan konsistensi, dan command repair (`aulia:repair-total-dibayar`).

---

## 4. Backdate Payment
`Historical source: [AULIA §4.5, §19 P11]`

Mencatat pembayaran dengan **tanggal berbeda dari sekarang** (uang sudah diterima sebelumnya, baru dicatat belakangan) + memilih **kasir penerima** yang sebenarnya menangani.

### 4.1 Arti field pembayaran (ditegaskan, tidak pernah berubah)
- `tanggal` = kapan uang **benar-benar diterima**.
- `kasir_id` = kasir yang **benar-benar menangani** (bukan otomatis jadi yang menginput).
- `created_at` = kapan record dibuat di sistem — **selalu** diisi DB, tidak pernah disentuh kode aplikasi, baik skenario normal maupun backdate.

### 4.2 Siapa boleh & validasi
**Admin ATAU Effective Shift Leader saat itu** (sejak Tahap 5 — sebelumnya admin-only murni). Kapabilitas ini satu paket: (1) mencatat `tanggal` backdate, (2) mengoverride `kasir_id` penerima. Definisi Shift Leader — lihat `USER-SHIFT.md`.

Validasi yang **tetap berlaku tanpa kecuali** untuk admin maupun Shift Leader:
- tanggal dianggap "backdate" kalau berbeda **>60 detik** dari waktu server saat itu;
- tanggal **tidak boleh sebelum tanggal transaksi** (dibandingkan di level **tanggal/hari saja**, bukan jam — lihat catatan bug-fix historis di `CHANGELOG.md`);
- tanggal **tidak boleh di masa depan** (dibandingkan penuh sampai jam).

Berlaku di dua titik pemanggilan `tambahPembayaran()`: `Api::tambahPembayaran()` (workflow umum) dan `Tagihan::lunasi()` (halaman Tagihan). **Tidak berlaku** untuk pembayaran awal transaksi BARU di `kasir/index.php` — `transaksi.tanggal` transaksi baru selalu "sekarang", backdate hanya untuk pembayaran atas transaksi yang **sudah ada**.

`GET /api/kasir-list` (dropdown "Kasir Penerima") — admin atau Effective Shift Leader (sejak Tahap 5).

### 4.3 UI (2026-09-16)
Checkbox opsional "Pembayaran diterima sebelumnya" di dalam modal Tunai/DP/Konfirmasi (mekanisme awal, 2026-09-05) **diganti tombol terpisah** "Bayar Backdate"/"Lunasi Backdate" di halaman detail transaksi — dipicu insiden nyata (Shift Leader lupa mencentang checkbox, pembayaran tercatat tanggal hari ini tanpa peringatan). Tombol baru menampilkan field tanggal (wajib) & kasir penerima langsung di modal utama SEBELUM metode dipilih. Tombol pembayaran normal tidak menampilkan field ini sama sekali — behavior identik sebelum perubahan.

### 4.4 Efek samping & risiko yang didokumentasikan
`CashBalanceService::getCashSales()` dan semua query Laporan sudah pakai `pembayaran.tanggal` bukan `created_at` — begitu backdate aktif, uang otomatis "jatuh" ke tanggal yang benar tanpa kode tambahan. Histori pembayaran (`transaksi/detail.php`) menampilkan nama kasir penerima + badge "Dicatat belakangan" (murni tampilan, dihitung dari selisih `tanggal` vs `created_at` >5 menit, tidak disimpan DB).

**Risiko didokumentasikan (bukan bug):** kalau `cash_opname` untuk suatu tanggal **sudah** dilakukan sebelum ada pembayaran yang di-backdate ke tanggal itu, rekonsiliasi retroaktif bisa berbeda dari hasil opname saat itu. Tidak ada mekanisme koreksi otomatis (di luar scope — tidak boleh bikin `cash_opname`/`cash_expense` baru).

---

## 5. Cash
`Historical source: [AULIA §11]`

Kas bertambah berdasarkan uang yang benar-benar menjadi penerimaan penjualan setelah kembalian. Contoh: Total Rp100.000, Bayar Rp150.000, Kembalian Rp50.000 → Kas masuk Rp100.000.

```text
Kas Awal
+ Penjualan Tunai
+ Pemasukan Kas Lain
- Pengeluaran
- Refund
= Saldo Kas Sistem
```

Saldo sistem bisa dibandingkan dengan Opname Kas Fisik untuk mendapatkan selisih.

---

## 6. Receivable / Tagihan
`Historical source: [AULIA §8, §25.6, §25.8]`

### 6.1 Definisi Tagihan
**Berdasarkan pembayaran saja**, bukan status transaksi:

| Status transaksi | Pembayaran | Tagihan |
|---|---|---|
| proses | belum_bayar / dp | YA |
| proses | lunas | TIDAK |
| selesai | belum_bayar / dp | YA |
| selesai | lunas | TIDAK |
| batal / mangkrak | apa pun | TIDAK |

Pelunasan lewat Tagihan: menambah pembayaran aktif, memperbarui status pembayaran, **tidak otomatis mengubah status transaksi**. Contoh: `selesai + dp` → bayar sisa → `selesai + lunas`.

### 6.2 Jatuh Tempo — kebijakan global, tanpa kolom DB (2026-09-15)
**Keputusan produk: tidak menambah kolom/migration untuk jatuh tempo.** Dihitung sebagai kebijakan **global**: `transaksi.tanggal + Config\Tagihan::$defaultTempoHari` (default 7 hari, override lewat `.env` `tagihan.defaultTempoHari`), dihitung ulang tiap kali `/tagihan` dibuka — **tidak pernah disimpan ke database**. Logic di `App\Services\KalkulasiJatuhTempo` (stateless).

Konsekuensi yang disadari & diterima: satu nilai tempo berlaku untuk **semua** transaksi/pelanggan (tidak bisa berbeda per pelanggan tanpa kolom baru); "Terlambat" baru `true` **sehari setelah** tanggal jatuh tempo (hari H sendiri belum terlambat).

Tampil di `/tagihan`: badge merah "Terlambat" di kolom Tanggal (tooltip untuk tanggal jatuh tempo), filter checkbox "Hanya terlambat" (diterapkan di PHP setelah `findAll()`, bukan WHERE query — bukan kolom database).

### 6.3 Reminder Tagihan N Hari Terakhir
Mengingatkan kasir/admin soal tagihan yang digarap sendiri dan masih "segar". Ambang waktu = `Config\Tagihan::$defaultTempoHari` (sama angka dengan 6.2, default 7 hari — sebelumnya hardcode 3 hari, disatukan 2026-09-15 supaya "masih dalam masa tempo" dan "perlu direminder" pakai satu angka yang sama).

**Kriteria** (`Kasir::getReminderTagihanSaya()`, dipanggil `Kasir::index()`): `kasir_id` = user login, `status_pembayaran` IN (`belum_bayar`,`dp`), `status != batal`, `tanggal >= sekarang - N hari`. **Trigger**: server-side tiap `/kasir` dibuka (bukan polling). **Jeda anti-spam**: 15 menit (`session('reminder_tagihan_last_shown')`), hanya di-update saat toast benar-benar tampil.

**Tampilan**: modal `konfirmasi()` (tombol Tutup/Lihat — bukan toast auto-hilang). Tombol Lihat membuka tab baru ke `/tagihan?saya=1`. Filter `?saya=1` **selalu** dari `session()->get('id_user')`, tidak pernah dari query string — berlaku kasir dan admin, murni berdasarkan `kasir_id`.

---

## 7. POS / Kasir
`Historical source: [AULIA §13, §10, §26]`

### 7.1 Tampilan & aksi transaksi
Belum ada halaman baru khusus transaksi `proses` — dikelola lewat `transaksi/index`. Baris tabel tidak bisa diklik ke detail; tombol Lihat Detail (ikon mata) selalu tampil, tombol Edit dipindah ke halaman detail (alur edit wajib lewat detail dulu).

Aksi per status: `proses` → Edit, Selesai (Section 2.3), Batal (Section 2.4); `selesai` → lihat detail & bayar sisa; `batal` → read-only.

### 7.2 Preview Banner
Alat bantu internal membuat gambar preview banner (garis ukuran & crop mark) sebelum dicetak, untuk approval customer lewat WhatsApp — mengurangi reprint akibat revisi mendadak. **Murni client-side, tidak ada penyimpanan data**, tidak terhubung ke transaksi, tidak ada tabel/endpoint API baru — semua (upload, generate, download) di browser lewat `<canvas>`. File asli (`public/tools/preview-banner.html`) tidak diubah, ditampilkan lewat `<iframe>` (mengisolasi CSS/JS). Bisa diakses semua role login, route `/preview-banner`.

---

## 8. Employee Schedule
`Historical source: [AULIA §20]`

Domain **independen** dari transaksi/kasir — tidak ada irisan tabel/business rule, code-path terpisah sengaja supaya perubahan di satu domain tidak pernah menyentuh domain lain.

### 8.1 Konsep & mode
**Jadwal Karyawan** (`/jadwal`, admin-only) punya 4 mode: **Matrix** (roster mingguan, default), **Kalender** (FullCalendar), **Master Jadwal** (template yang bisa di-apply), **Analisis** (kombinasi kerja bersama).

Kode shift: `P`=Pagi 08:00-15:00, `S`=Siang 13:30-20:30, `PM`=08:00-12:30 & 18:00-20:30 (**SATU row, dua sesi** — bukan dua shift terpisah), `L`=Libur eksplisit (row ada, `shift='L'`), `-`=BELUM DIJADWALKAN (**tidak ada row sama sekali** — aturan mutlak: tidak ada row ≠ Libur, `-` tidak pernah diubah jadi `L`).

**Tidak ada batas jumlah hari kerja.** **Jadwal ≠ Absensi** — planned schedule, tidak ada tabel attendance/clock-in-out/payroll; `P/S/PM` tidak berarti "pasti hadir", `L` tidak berarti "pasti tidak bekerja".

**Jadwal jadi input Effective Shift Leader** (lihat `USER-SHIFT.md`) — modul ini sendiri tidak berubah oleh integrasi itu, `jadwal` cuma dibaca read-only.

### 8.2 Database
`users.is_active` (aktif=bisa dipilih untuk schedule baru; **bukan pengganti role**). Tabel `jadwal` (`karyawan_id, tanggal, shift`, `UNIQUE(karyawan_id,tanggal)`, FK ke `users.id` **tanpa** `ON DELETE CASCADE` — histori jadwal harus survive delete user; `karyawan_id` harus `INT(11)` **signed**, signedness-mismatch pernah menyebabkan error FK). `master_jadwal` (template header) + `master_jadwal_detail` (`ON DELETE CASCADE` ke master, tidak ke `users`).

### 8.3 Aturan create/edit/swap
Create/Edit manual: employee picker hanya `is_active=1`, tidak dibatasi divisi. **Swap**: wajib divisi sama (backend), yang ditukar adalah **NILAI SHIFT**, bukan kepemilikan baris (`karyawan_id`/`tanggal` tiap baris tidak berubah — mencegah bentrok unique constraint); shift sama ditolak; PM di-swap sebagai satu paket. **Tukar Libur** (kedua cell sama-sama `L`, karyawan beda): hari libur direlokasi, masing-masing mengambil alih shift kerja lawannya; butuh kedua karyawan sudah punya schedule kerja di tanggal lawan, kalau tidak ditolak jelas. **Delete range**: admin-only, hanya `jadwal` (actual), tidak pernah `master_jadwal`.

### 8.4 Master Jadwal & Apply
Template independen dari actual schedule (edit master tidak mengubah `jadwal` yang sudah di-apply, sebaliknya juga). Master membedakan `L` eksplisit dari kosong. **Apply** ke N minggu ke depan, default **isi slot kosong saja** (tidak menimpa yang sudah ada, dilaporkan sebagai conflict); overwrite eksplisit tersedia terpisah (checkbox + konfirmasi).

### 8.5 Otorisasi & Roster
Mutation (`/jadwal/simpan`, `/hapus`, `/hapus-range`, `/swap`, `/master/*`) admin-only lewat `AuthFilter::$adminRoutes`. Endpoint read-only kasir sengaja pakai prefix berbeda (`/roster/*`, bukan `/jadwal/*`) supaya tidak ikut ter-blok — cukup filter `auth` biasa, termasuk `GET /roster/shift-leader-saat-ini` (info Shift Leader, semua role, lihat `USER-SHIFT.md`).

Halaman `/roster` (kasir): **Ringkasan Hari Ini** dikelompokkan per shift, user login ditandai ★, "Belum Dijadwalkan" dari karyawan aktif tanpa row hari itu (bukan `shift='L'`); **Mingguan/Bulanan** read-only, filter divisi/shift/search; `GET /roster/status-saya` (polling 60 detik, dihitung server PHP) murni informasi, **bukan** otorisasi (beda dari Effective Shift Leader).

---

## 9. User Account & Profile
`Historical source: [AULIA §24]`

Dua area di atas tabel `users` yang sudah ada (tidak ada tabel `profile` baru): **Profil Saya** (`/profil`, semua role — ubah nama/no. HP/foto milik sendiri; `username`/`inisial`/`divisi`/`role`/`is_active` read-only) dan **Manajemen User** (`/user-management`, admin — kelola semua field termasuk role, status aktif, dan **Priority**, lihat `USER-SHIFT.md`).

**Otorisasi paling penting:** `Profil::index()/update()/uploadFoto()/hapusFoto()` **selalu** memakai `session()->get('id_user')` sebagai target — tidak pernah dari form/request. `UserModel::updateProfilSaya()` sengaja hanya menerima `nama`+`no_hp` (bukan array bebas) sebagai pertahanan kedua. Admin tidak bisa mengubah role akun sendiri jadi non-admin atau menonaktifkan diri sendiri (cegah self-lockout).

**Foto profil**: disimpan `WRITEPATH/uploads/foto_profil/` (di luar docroot publik, cegah eksekusi sebagai PHP). Nama file selalu server-generated (`random_bytes` hex + ekstensi dari MIME tervalidasi). Validasi: maks 2MB, MIME whitelist (jpeg/png/webp), `getimagesize()` untuk memastikan isi benar-benar gambar. Ditampilkan lewat `GET /foto-profil/{filename}` (stream dari disk, regex ketat cegah path traversal). File lama dibersihkan setelah update berhasil.

Header menampilkan foto profil + badge Effective Shift Leader — keduanya query langsung ke model di view, **sengaja tidak disimpan ke session** supaya perubahan langsung tercermin tanpa logout/login ulang.

---

## 10. Notifications & Confirmations
`Historical source: [AULIA §25.1–25.5, §25.7]`

Semua notifikasi hasil-aksi (redirect maupun AJAX) konsisten lewat `showToast()` — alert box dari flashdata dihapus total (kecuali `auth/login.php`, belum ada sesi untuk toast container). Durasi per tipe: sukses/info 3 detik, warning 4 detik, error/danger **sticky** (harus di-close manual). Posisi atas-tengah. Opsi `onClick` untuk toast yang bisa diklik (dipakai reminder tagihan).

**Keterbatasan diketahui:** sistem toast masih satu instance tunggal (`#liveToast`), belum mendukung stacking — dua toast nyaris bersamaan, yang kedua menimpa yang pertama.

Konfirmasi aksi berbahaya (mis. "Batalkan" transaksi) memakai modal `konfirmasi()` satu langkah (bukan native `confirm()` browser, bukan 2 modal berturutan).

---

## 11. Archive Transaksi
`Historical source: [AULIA §28]`

**Tujuan:** mengurangi beban database utama & menghilangkan transaksi historis dari Tagihan/badge, **tanpa kehilangan histori**. **Manual sepenuhnya** — admin menjalankan lewat UI, **tidak ada cron/scheduler**. Prinsip utama: **integritas data di atas segalanya** — data hanya dihapus dari MySQL setelah terbukti tersalin lengkap & valid di archive.

**Cutoff:** bulan X eligible kalau berjarak **≥6 bulan penuh** dari bulan berjalan (granularitas bulan). Semua transaksi bulan eligible di-archive tanpa filter status (termasuk `mangkrak`). Status & nilai **tidak pernah diubah** saat archive.

**Database:** SQLite terpisah (koneksi CI4 grup `archive`, default `WRITEPATH.'archive/aulia_pos_archive.db'`, override via `.env`) — MySQL utama tidak pernah disentuh strukturnya. `id` archive sama persis dengan `id` MySQL asli. Nama pelanggan/kasir **di-snapshot** ke archive (self-contained walau data master berubah nanti).

**Alur eksekusi** (destruktif, wajib berurutan): pilih bulan → preview read-only → ketik "ARCHIVE" untuk unlock → (1) tulis backup JSON mentah, (2) salin ke SQLite archive (idempotent), (3) **VALIDASI** jumlah baris & total `grand_total` harus cocok persis — gagal → STOP, MySQL tidak tersentuh, (4) hapus dari MySQL (satu transaksi DB, cascade). Kalau langkah hapus MySQL sendiri gagal setelah archive tervalidasi: data tetap ada di kedua tempat (tidak hilang), aman diulang.

**Integrasi modul lain:** Tagihan/badge otomatis aman (query tidak dibatasi tanggal); Kas tidak perlu diubah (selalu query same-day); Pencarian & Laporan **dual-source** (gabung MySQL+archive, ditandai `_sumber`); Detail transaksi fallback ke archive kalau ID tidak ketemu MySQL (jadi read-only); `Tagihan::detail()` **belum** di-fallback (risiko kecil); Cetak ulang **sengaja tidak didukung** untuk transaksi archive.

---

## 12. Cetak Nota / Printing
`Historical source: [AULIA §30]`

Tombol "Cetak Nota" = dropdown dua pilihan: **Cetak Langsung** (server-side ke printer share, mis. Epson L300, tanpa dialog print browser) dan **Pilih Printer** (perilaku lama — buka PDF/HTML window baru, dialog print browser, tidak diubah).

**Konfigurasi** (`App\Config\PrintNota`) sepenuhnya di sisi server (printer target, path tool `SumatraPDF`, timeout) — tidak pernah diterima dari request browser, override via `.env`.

**Flow Cetak Langsung**: fetch transaksi (sama dengan Pilih Printer) → render `cetak/nota.php` (template sama) → Dompdf → PDF A6 landscape → simpan sementara → `proc_open([SumatraPDF, -print-to, <printer>, -silent, <file>])` → tunggu (timeout default 25 detik) → hapus file sementara (berhasil maupun gagal) → log exit code/stdout/stderr → JSON ke browser (toast, tanpa dialog print). Endpoint **read-only** terhadap transaksi — gagal generate/print **tidak pernah** memengaruhi transaksi tersimpan.

`Cetak::thermal()` **tidak disentuh sama sekali** oleh fitur ini — target `smb://guest@aulia6/POS-58` tetap seperti sebelumnya.

---

## 13. Reports
`Historical source: [AULIA §15]`

Transaksi `batal` tidak dianggap aktif. Pola `status != batal` dapat dipakai bila laporan memang bermaksud menghitung semua transaksi non-batal — tapi laporan tertentu perlu dibedakan berdasarkan tujuan: semua non-batal / hanya `proses` / hanya `selesai` / hanya yang `lunas`. **Jangan menyamakan status pekerjaan dengan status pembayaran.** `mangkrak` **tidak** dikecualikan dari laporan (Section 2.5). Laporan dual-source (MySQL + archive) untuk transaksi lama — lihat Section 11.

---

## 14. Permissions

Ringkasan matriks kapabilitas lintas fitur (definisi lengkap "Effective Shift Leader" ada di `USER-SHIFT.md` — di sini hanya dirujuk sebagai istilah terdefinisi):

| Aksi | Admin | Effective Shift Leader | Kasir pemilik | Kasir lain |
|---|---|---|---|---|
| `PROSES → SELESAI` (workflow umum) | Ya, jika lunas | Ya, jika lunas | Tidak | Tidak |
| `PROSES → SELESAI` (workflow Kasir/POS, `sumber=kasir_pos`) | Ya, jika lunas | Ya, jika lunas & miliknya | Ya, jika lunas & miliknya | Tidak |
| `→ BATAL` (proses maupun selesai) | Ya | Ya | Tidak | Tidak |
| `→ MANGKRAK` / aktifkan kembali | Ya | **Tidak** (admin-only murni) | Tidak | Tidak |
| Backdate payment + override kasir_id | Ya | Ya | Tidak | Tidak |
| `GET /api/kasir-list` | Ya | Ya | Tidak | Tidak |
| Kelola Jadwal Karyawan (mutation) | Ya | Tidak | Tidak | Tidak |
| Lihat Roster (read-only) | Ya | Ya | Ya | Ya |
| Manajemen User (termasuk set Priority) | Ya | Tidak | Tidak | Tidak |
| Archive Transaksi | Ya | Tidak | Tidak | Tidak |

**UI bukan enforcement** untuk semua baris di atas — backend selalu jadi otoritas akhir (lihat Section 2.3).

---

## 15. Known Implementation Gaps

- **Toast stacking**: sistem toast masih satu instance tunggal — dua toast nyaris bersamaan, yang kedua menimpa yang pertama. Diketahui, tidak diperbaiki (di luar scope perubahan terkait).
- **Jadwal Karyawan — belum ada test end-to-end dengan DB sungguhan** — seluruh pengujian modul ini sejauh ini logic murni (standalone, tanpa DB).
- **Staffing conflict** (configurable, warning-only) ada di requirement modul Jadwal tapi **belum diimplementasikan**.
- Ada baris karyawan dengan `nama`/`inisial` kosong yang muncul di Matrix/Master (kemungkinan data lama) — belum ada tindakan.
- **Archive**: tidak ada UI restore otomatis (archive→MySQL, restore manual dari SQLite/backup JSON); file backup JSON menumpuk tanpa pembersihan otomatis; belum ada test end-to-end dengan MySQL sungguhan; `Tagihan::detail()` belum di-fallback ke archive.
- **Jatuh Tempo Tagihan**: satu nilai tempo global untuk semua transaksi/pelanggan — tidak bisa diatur berbeda per pelanggan (mis. termin langganan lebih panjang) tanpa menambah kolom baru.
- **Role SPV/otorisasi bertingkat** di luar Effective Shift Leader — kalau suatu saat dibutuhkan kapabilitas granular lain di luar yang sudah ada (SELESAI/BATAL/Backdate), belum ada mekanisme generik (`hasAuthority()`/capability registry) — sengaja belum dibangun sampai ada kebutuhan nyata (lihat `USER-SHIFT.md`).
- Beberapa fitur v2.1 (Closing Kas, DataTables sort di Manajemen User, redesign filter Tagihan, tampilan inisial kasir di berbagai halaman) belum punya dokumentasi bisnis eksplisit di dokumen manapun — diketahui, dicatat, belum ditulis.
- Security/hardening yang teridentifikasi tapi belum otomatis masuk perubahan lifecycle manapun (scope terpisah dari perubahan bisnis): whitelist metode pembayaran di endpoint tertentu, validasi nilai cash/kembalian dari client, potensi injection pada inline `onclick`, CSRF, concurrency/audit log.

---

## 16. Developer Invariants

> **`selesai` adalah final transaksi/pekerjaan. `lunas` hanya final pembayaran.** `selesai` HANYA boleh dicapai jika `lunas` DAN ditandai eksplisit oleh pihak berwenang (Section 2.3) — `lunas` sendirian tidak pernah cukup.

> **Tagihan ditentukan berdasarkan status pembayaran, bukan status transaksi; `batal` selalu dikecualikan.**

> **Membatalkan transaksi (proses maupun selesai) hanya boleh admin atau Effective Shift Leader** (Section 2.4) — kasir biasa tidak bisa membatalkan apa pun lewat workflow umum.

> **Backdate pembayaran boleh admin atau Effective Shift Leader, tidak pernah mengubah `created_at`, dan divalidasi ulang di backend terlepas dari apa yang dikirim client** (Section 4).

> **Modul Jadwal Karyawan adalah domain terpisah dari transaksi/kasir** — jadwal ≠ absensi, tidak pernah memblokir transaksi, mutation admin-only, viewing read-only untuk semua role.

> **UI bukan enforcement, di mana pun.** Penyembunyian tombol/field adalah lapis pertama saja — backend selalu memvalidasi ulang role, kepemilikan, status, dan syarat bisnis lain sebelum eksekusi.

Prinsip pengembangan yang disepakati (workflow perubahan aturan bisnis): (1) tentukan masalah, (2) sepakati aturan/tujuan, (3) audit kode yang ada, (4) daftarkan opsi solusi, (5) pilih solusi, (6) baru lakukan perubahan — satu file per satu file, jangan pakai patch kecuali diminta, (7) setiap perubahan diuji sebelum dianggap selesai.

Lifecycle pekerjaan: `TRANSAKSI BARU → PROSES → [EDIT | BATAL] → SELESAI (FINAL)`. Lifecycle pembayaran: `BELUM BAYAR → DP → LUNAS`. **Kedua lifecycle ini tidak boleh dicampur.**
