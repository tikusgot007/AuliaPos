# Changelog & Catatan Teknis AULIA

> **Sumber asli:** `aturan-bisnis-AULIA.md` (Section 16–19, 20.9–20.11,
> 21, 22, 23). Berisi **riwayat pekerjaan, audit teknis, arsitektur
> implementasi, dan pekerjaan terbuka** — bukan aturan bisnis final
> (lihat `AULIA-01-transaksi-dan-kasir.md` dan
> `AULIA-02-modul-pendukung.md` untuk itu). Rujukan ke section asli
> memakai format `[AULIA §N]`.

---

## Daftar Isi

1. [Arsitektur Aturan Status](#1-arsitektur-aturan-status)
2. [Audit Status yang Wajib](#2-audit-status-yang-wajib)
3. [P7 Stabilization](#3-p7-stabilization)
4. [Riwayat Pekerjaan P1–P15](#4-riwayat-pekerjaan-p1p15)
5. [Housekeeping — Dead Code](#5-housekeeping--dead-code)
6. [Pekerjaan Terbuka (Transaksi/Kasir)](#6-pekerjaan-terbuka-transaksikasir)
7. [Security/Hardening — Scope Terpisah](#7-securityhardening--scope-terpisah)
8. [Riwayat & Pekerjaan Terbuka — Modul Jadwal Karyawan](#8-riwayat--pekerjaan-terbuka--modul-jadwal-karyawan)

---

## 1. Arsitektur Aturan Status
`[AULIA §16]`

Pendekatan yang dipilih untuk implementasi lifecycle status transaksi adalah **Opsi B — terpusat dan lebih aman**.

Prinsip:
- `TransaksiModel` menjadi pusat aturan status;
- API/controller tidak membuat aturan transisi sendiri-sendiri;
- View mengikuti aturan backend;
- endpoint status divalidasi;
- transisi ilegal harus ditolak backend.

```text
View → Controller/API → TransaksiModel → aturan lifecycle
```

---

## 2. Audit Status yang Wajib
`[AULIA §17]`

Sebelum perubahan lifecycle dilakukan, audit mencakup:
1. Lokasi yang menetapkan `proses`.
2. Lokasi yang menetapkan `selesai`.
3. Lokasi yang menetapkan `diambil`.
4. Lokasi yang menetapkan `batal`.
5. Semua pembacaan/filter `status`.
6. Tombol/label Selesai, Diambil, Batal.
7. Endpoint/API perubahan status.
8. Routes status.
9. `TransaksiModel`.
10. Alur Edit.
11. Alur Tagihan/pelunasan.
12. Laporan.
13. Perhitungan kas.

Area utama yang telah teridentifikasi:
```text
app/Controllers/Api.php
app/Controllers/Transaksi.php
app/Controllers/Kasir.php
app/Controllers/Tagihan.php
app/Controllers/Laporan.php
app/Models/TransaksiModel.php
app/Services/CashBalanceService.php
app/Views/transaksi/index.php
app/Views/transaksi/detail.php
app/Views/transaksi/hari_ini.php
app/Views/tagihan/index.php
app/Config/Routes.php
```
Daftar ini adalah titik audit; tidak berarti semua file pasti harus diubah.

**Status audit untuk perubahan syarat SELESAI (2026-09-05):** selesai dilakukan — lihat P8 di bawah untuk hasil dan file yang benar-benar disentuh (hanya `TransaksiModel.php`).

---

## 3. P7 Stabilization
`[AULIA §18]`

| Area | Status |
|---|---|
| P7-1 No Order | Selesai / Stabil |
| P7-2 Cart | Selesai / Stabil |
| P7-3 Diskon & Total | Selesai / Stabil |
| P7-4 Customer | Selesai / Stabil |
| P7-5 Payment | Selesai / Stabil |
| P7-6 Piutang | Selesai / Stabil |
| P7-7 Reset setelah transaksi | Selesai / Stabil |
| P7-8 Print | Selesai / Stabil |
| P7-9 Banner | Selesai / Stabil |
| P7-10 Audit `kasir/edit.php` | Selesai / Stabil |

`Regression` adalah jenis pengujian, bukan status pekerjaan.

---

## 4. Riwayat Pekerjaan P1–P15
`[AULIA §19]`

### P1 — Konsistensi Total Pembayaran
Solusi: `cache + auto sync + consistency check/repair`. Sudah diuji dan stabil.

### P2 — Koreksi Metode Pembayaran
Solusi: `pembayaran.status = aktif/reversed`. Sudah diuji dengan berbagai kombinasi metode dan stabil.

### P7-1 — No Order
Audit dan perbaikan minimal API sudah diuji dan stabil.

### P7-6 — Piutang
Pembuatan piutang dan pelunasan sudah diuji dan stabil.

### P7-7 — Reset
Reset cart/customer/No Order/diskon setelah transaksi sudah diuji dan stabil.

### P7-8 — Print
Dinyatakan aman/stabil.

### P7-9 — Banner
Aturan: baris tetap terpisah; minimum pricing berdasarkan total area untuk <1 m². Sudah diuji dengan berbagai luas dan qty dan stabil.

### P7-10 — Audit `kasir/edit.php` & Pembayaran (2026-09-04)

Scope yang diaudit dan diselesaikan:
1. **Bug `total_dibayar` hilang saat edit** — akses tipe data salah di `updateTransaksi()` (`->first()->jumlah`, gaya object, padahal `PembayaranModel` mengembalikan array) — nilainya diam-diam selalu jadi `0` tanpa error. Diperbaiki dengan `sinkronkanPembayaran()`.
2. **Auto-refund dihapus** — `updateTransaksi()` sebelumnya mencatat refund otomatis ke `cash_expense` begitu total baru lebih kecil dari yang sudah dibayar, termasuk bug yang membuat edit kedua pada transaksi yang sama langsung error 500. Perilaku ini bertentangan dengan aturan "refund adalah proses tersendiri" dan sudah dihapus (lihat `AULIA-01-transaksi-dan-kasir.md` §5.1).
3. **Duplikasi logika Banner** — `modal_banner.php` diperbaiki (sempat kehilangan seluruh `<script>` fungsi banner akibat edit manual di luar proses ini).
4. **State cart & shared JS** — logic kasir diekstrak ke `public/assets/js/kasir-shared.js`, dipakai bersama oleh `kasir/index.php` dan `kasir/edit.php`.
5. **Indikator kelebihan bayar** — ditambahkan di `kasir/edit.php` dan `transaksi/detail.php`.

Sudah diuji: transaksi belum bayar, transaksi lunas yang diturunkan (kelebihan bayar), dan transaksi DP yang dinaikkan — ketiganya stabil.

### P8 — Syarat SELESAI: admin + lunas (2026-09-05)

**Masalah:** `TransaksiModel::ubahStatus()` mengizinkan `proses → selesai` tanpa memeriksa `status_pembayaran` maupun role user, sehingga kondisi seperti `selesai + belum_bayar` bisa terjadi.

**Audit:** Ditemukan bahwa `ubahStatus()` adalah **satu-satunya** jalur penulisan `status='selesai'` ke tabel `transaksi` di seluruh codebase (dikonfirmasi lewat pencarian menyeluruh). `Api::ubahStatus()` sudah menghitung `$isAdmin` dari session role dan meneruskannya ke model. `Transaksi::updateTransaksi()` (alur Edit) tidak menyentuh kolom `status` sama sekali.

**Solusi:** Validasi ditambahkan langsung di `TransaksiModel::ubahStatus()`, tepat sebelum transisi `proses → selesai` dieksekusi:
1. Cek `$isAdmin`; tolak dengan pesan jelas jika bukan admin.
2. Panggil `sinkronkanPembayaran()` untuk menyegarkan `status_pembayaran` sebelum dicek (menghindari cache basi), lalu tolak dengan pesan jelas (termasuk nominal sisa) jika belum `lunas`.

Tidak ada perubahan di `Api.php`, view, atau mekanisme pembayaran — seluruhnya otomatis mengikuti aturan baru karena tersentralisasi di satu method (lihat Section 1 di atas).

**Pengujian:** 8 skenario diuji via harness standalone (model di-stub, tanpa DB): admin+lunas→berhasil; admin+belum_bayar→ditolak; admin+dp→ditolak; kasir+lunas→ditolak; pelunasan via `sinkronkanPembayaran()` tidak mengubah status transaksi ("LUNAS ≠ otomatis SELESAI"); klik Selesai dua kali pada transaksi yang sudah selesai bersifat idempotent; regression `batal` (dengan maupun tanpa admin) tidak berubah. Semua 8 lulus.

Status: **Selesai / Stabil**.

### P9 — UI & Error Handling tombol Selesai (2026-09-05)

**Konteks:** setelah P8 mengunci business rule di backend, tombol "Selesai" di `transaksi/index.php` dan "Selesai Dikerjakan" di `transaksi/detail.php` masih tampil untuk semua role dan memakai konfirmasi generik yang sama untuk semua kondisi.

**Perubahan (UI/error-handling saja, tidak menyentuh business rule):**
- Tombol digating `session()->get('role') === 'admin'` di halaman Daftar/Detail — kasir tidak melihat tombol ini di sana (lapis 1). Backend P8 tetap jadi lapis 2 yang otoritatif. (Diperluas oleh Phase 2 — Section 4.3 `AULIA-01-transaksi-dan-kasir.md`: kasir kini bisa menyelesaikan transaksinya sendiri lewat workflow Kasir/POS, bukan lewat halaman Daftar/Detail.)
- JS dipecah: `ubahStatus()` (untuk Batal, tidak berubah) vs `selesaikanTransaksi(id, statusPembayaran)` (khusus Selesai) — kalau `lunas`, tampil `confirm()` peringatan garapan sebelum kirim; kalau belum lunas, langsung kirim dan biarkan pesan penolakan spesifik dari backend (P8) tampil lewat `showToast()`.

File: `transaksi/index.php`, `transaksi/detail.php`.

### P10 — Filter Status Transaksi eksplisit (2026-09-05)

**Masalah:** filter Status Transaksi di `transaksi/index.php` cuma punya 2 pilihan ambigu: `Aktif` (proses+selesai) dan `Batal`.

**Solusi:** diganti 4 pilihan eksplisit sesuai nilai database: **Semua / Proses / Selesai / Batal**. Urutan filter dirapikan jadi Tanggal → Status Transaksi → Status Pembayaran → Pelanggan.

**Keputusan default yang disengaja:** default filter (termasuk saat klik Reset) berubah dari "proses+selesai, exclude batal" menjadi **Semua** (tidak difilter). Value lama `status_transaksi=aktif` **tetap didukung di backend** untuk backward-compat link lama, hanya tidak lagi ditawarkan sebagai pilihan di UI.

**Kombinasi penting yang jadi mungkin:** `Proses + Lunas` — daftar kerja admin untuk transaksi yang sudah lunas tapi belum ditandai Selesai (persis kasus yang dijaga P8).

File: `transaksi/index.php`, `Transaksi.php` (controller).

### P11 — Fitur Pelunasan Terlambat / Backdate (2026-09-05)

Fitur besar: Admin bisa mencatat pembayaran dengan **tanggal berbeda dari sekarang** (uang sudah diterima sebelumnya, baru dicatat belakangan) dan memilih **kasir penerima** yang sebenarnya menangani, cukup checkbox opsional "Pembayaran diterima sebelumnya" di modal Tunai/DP/Konfirmasi yang sudah ada.

**Arti field pembayaran (ditegaskan, tidak diubah):**
- `tanggal` = kapan uang **benar-benar diterima**.
- `kasir_id` = kasir yang **benar-benar menangani** (bukan otomatis jadi Admin yang menginput).
- `created_at` = kapan record dibuat di sistem — **selalu** diisi DB, tidak pernah disentuh kode aplikasi.

**Validasi backend (`TransaksiModel::tambahPembayaran()`):**
- tanggal pembayaran dianggap "backdate" kalau berbeda >60 detik dari waktu server;
- backdate **wajib admin** — kasir yang mencoba mengirim tanggal manual tetap ditolak backend meski lolos UI;
- tanggal tidak boleh **sebelum tanggal transaksi** (dibandingkan di level **tanggal/hari saja**, bukan jam) dan tidak boleh **di masa depan** (dibandingkan penuh sampai jam).

**Sengaja dikecualikan dari scope:** pembayaran awal transaksi BARU (`kasir/index.php`) — karena `transaksi.tanggal` transaksi baru selalu "sekarang". Backdate hanya berlaku untuk pembayaran atas transaksi yang **sudah ada** (Bayar Sekarang / Tagihan → Lunasi).

**Efek samping yang sudah benar tanpa kode tambahan:** `CashBalanceService::getCashSales()` dan semua query laporan sudah pakai `pembayaran.tanggal` bukan `created_at` — begitu backdate aktif, uang otomatis "jatuh" ke tanggal yang benar.

**Risiko yang didokumentasikan (bukan bug):** kalau `cash_opname` untuk suatu tanggal **sudah** dilakukan sebelum ada pembayaran yang di-backdate ke tanggal itu, rekonsiliasi retroaktif bisa berbeda dari hasil opname saat itu. Tidak dibuatkan mekanisme koreksi otomatis.

**Endpoint baru:** `GET /api/kasir-list` (admin-only) untuk dropdown "Kasir Penerima". Histori pembayaran menampilkan nama kasir penerima + badge "Dicatat belakangan" (murni tampilan, tidak disimpan di database).

File: `TransaksiModel.php`, `Api.php`, `Tagihan.php`, `Transaksi.php`, `Routes.php`, `components/payment/modal.php`, `payment.js`, `transaksi/index.php`, `transaksi/detail.php`, `tagihan/index.php`.

Pengujian: 17 skenario standalone lulus (15 acceptance test dokumen + 2 regresi tambahan).

### P11-fix — Backdate: perbandingan tanggal vs timestamp (2026-09-05)

**Bug ditemukan saat testing manual:** validasi "tidak boleh sebelum tanggal transaksi" awalnya membandingkan **timestamp lengkap** (tanggal+jam), bukan cuma tanggal. Akibatnya, backdate ke hari yang SAMA dengan transaksi tapi jam lebih awal salah ditolak — padahal ini justru skenario utama fitur ini.

**Fix:** perbandingan batas bawah diubah ke level **tanggal (hari)** saja. Batas atas ("tidak boleh masa depan") **tetap** presisi jam.

**Implikasi yang sudah dicek:** tidak berdampak ke pembayaran normal, `simpanTransaksi()`/`koreksiPembayaran()`, atau rule SELESAI. Efek disengaja: kas real-time bisa menghitung pembayaran backdate jam pagi masuk ke snapshot pagi hari yang sama.

File: `TransaksiModel.php` (satu method, `tambahPembayaran()`).

### P12 — Perbaikan UI kecil (2026-09-05)
- Tombol "Selesai Dikerjakan" di `transaksi/detail.php`: warna diganti dari kuning (`btn-warning`, sama dengan Edit) jadi biru (`btn-primary`).
- Riwayat Pembayaran: nama kasir dan keterangan dipisah dengan " · " supaya tidak terbaca menyatu.

File: `transaksi/detail.php`. Murni kosmetik, tidak ada logic yang berubah.

### P13 — Restrukturisasi aksi di daftar transaksi (2026-09-05)
Baris tabel `transaksi/index.php` tidak lagi bisa diklik untuk ke halaman detail. Tombol Edit (kuning, pensil) di kolom Aksi **dihapus**, digantikan tombol Lihat Detail (`btn-info`, ikon mata, selalu tampil). Tombol Edit yang sesungguhnya tetap ada di halaman detail — alur edit sekarang wajib lewat halaman detail dulu.

File: `transaksi/index.php`.

### P14 — Pisah Aksi Khusus dari daftar produk di Kasir (2026-09-05)
Kartu "Banner", "Manual Input", "Ukuran Custom" sebelumnya dirender di dalam `#produkList` sehingga ikut kena filter kategori/pencarian produk. Direstrukturisasi: ketiga kartu dipindah ke section terpisah "Aksi Khusus" di atas kotak pencarian. Fungsi ketiga tombol tidak diubah sama sekali — hanya lokasi/strukturnya di DOM.

File: `kasir/index.php`, `kasir/edit.php` (treatment identik), `kasir-shared.js` (`renderProdukKasir()` disederhanakan).

### P15 — Kapabilitas SELESAI dari workflow Kasir/POS (2026-09-10, Phase 2)

**Konteks:** setelah P8/P9 mengunci `proses → selesai` ke admin, semua transaksi tetap harus dituntaskan admin — termasuk transaksi POS yang dibuat kasir sendiri dan sudah lunas di tempat. Diputuskan memberi kasir kapabilitas terbatas untuk kasus itu tanpa melonggarkan jalur status umum.

**Aturan final:** lihat `AULIA-01-transaksi-dan-kasir.md` §4.3. Ringkas: kasir boleh menyelesaikan transaksi hanya lewat endpoint POS `/api/kasir/selesaikan-transaksi`, untuk transaksi `sumber='kasir_pos'` + `kasir_id === id_user` + `status='proses'` + `status_pembayaran='lunas'`. Jalur umum (`/api/ubah-status`, Daftar/Detail) tidak berubah — kasir tetap ditolak.

**Enforcement:** `TransaksiModel::ubahStatus()` dapat parameter kapabilitas-konteks yang melewati gate "harus admin" tetapi **tidak** melewati syarat `lunas`. Cek role/kepemilikan/`sumber` ada di controller endpoint.

File: `TransaksiModel.php`, `Api.php`, `Routes.php`, `kasir/index.php`.

---

## 5. Housekeeping — Dead Code
`[AULIA §21]` — 2026-09-05

Dikonfirmasi via audit (grep referensi + cek routing) bahwa 3 file berikut tidak dipakai di mana pun dan aman dihapus:

| File | Bukti tidak terpakai | Status |
|---|---|---|
| `app/Controllers/LaporanPenjualan.php` | Tidak ada route yang mengarah ke sana; `$autoRoute=false` sehingga tidak mungkin ke-hit lewat URL konvensi otomatis CI4. | **Sudah dihapus.** |
| `public/assets/js/banner.js` | File 0 byte, tidak direferensikan. | Sudah tidak ada di repo. |
| `public/js/modal_banner.js` | File 0 byte, tidak direferensikan. | Sudah tidak ada di repo. |

Fitur Banner di Kasir **tidak terpengaruh** — logic-nya ada inline di `app/Views/kasir/modal_banner.php`, bukan di kedua file JS kosong di atas.

Housekeeping lanjutan (removal `LaporanTest.php` dan view cetak lama) dicatat terpisah di riwayat commit.

---

## 6. Pekerjaan Terbuka (Transaksi/Kasir)
`[AULIA §22]`

Tidak ada pekerjaan terbuka terkait lifecycle status transaksi saat dokumen sumber ditulis (lihat P8–P14 untuk status implementasi terakhir).

Area terkait yang masih terbuka untuk pembahasan terpisah:
- Status `diambil`/"siap diambil" untuk penanganan barang yang belum diambil pelanggan — sengaja belum diimplementasikan.
- Role SPV, jika suatu saat dibutuhkan selain admin/kasir.
- Cleanup dead code lanjutan (lihat Section 5 di atas).
- Cetak Nota langsung ke Epson L3210 — **sudah terealisasi**, lihat riwayat di bawah.

### 6.1 Cetak Nota langsung ke Epson L3210 (rencana → terealisasi)
`[§22.1]`

**Masalah:** Tombol "Nota" hanya membuka window baru berisi HTML nota — user harus cetak manual lewat dialog print browser, beda dari tombol "Thermal" yang sudah cetak otomatis. Diminta: Nota juga bisa "langsung cetak" ke printer inkjet Epson L3210 di jaringan lokal.

**Kendala terkonfirmasi:** browser tidak bisa mendeteksi/memilih printer OS otomatis (keterbatasan browser) — solusi murni client-side tidak bisa mencapai "langsung cetak tanpa dialog".

**Opsi yang dipertimbangkan:**
- **Opsi A (dipilih):** server generate PDF (Dompdf, A6 landscape) lalu kirim langsung ke share printer pakai tool command-line (SumatraPDF) — pola sama dengan `Cetak::thermal()` yang sudah jalan.
- Opsi B (ditolak): `window.print()` + CSS `@page` + Chrome `--kiosk-printing` per komputer kasir — rawan human error, tidak scalable.

**Status:** Sudah diimplementasikan — lihat `AULIA-02-modul-pendukung.md` §6 untuk desain final (`Cetak::notaLangsung()` → `generateNotaPdfBinary()` → `kirimPdfKePrinter()`).

---

## 7. Security/Hardening — Scope Terpisah
`[AULIA §23]`

Potensi hardening yang teridentifikasi tetapi belum otomatis masuk perubahan lifecycle:
- whitelist metode pembayaran pada endpoint tertentu;
- validasi nilai cash/kembalian dari client;
- potensi injection pada inline `onclick`;
- authorization dan identitas user/kasir;
- CSRF;
- concurrency/audit log.

Area ini sebaiknya dikerjakan sebagai scope terpisah agar perubahan lifecycle tidak bercampur dengan hardening keamanan.

---

## 8. Riwayat & Pekerjaan Terbuka — Modul Jadwal Karyawan
`[AULIA §20.9–20.11]`

Aturan bisnis final modul ini ada di `AULIA-02-modul-pendukung.md` §1. Bagian ini murni riwayat bug & pekerjaan terbuka.

### 8.1 Bug yang pernah terjadi (dicatat supaya tidak terulang)
1. **FK errno 150** — `jadwal.karyawan_id`/`master_jadwal_detail.karyawan_id` sempat didefinisikan `UNSIGNED` padahal `users.id` asli signed. MySQL/MariaDB mewajibkan tipe identik persis (termasuk signedness) untuk foreign key. Fix: hapus `unsigned` dari kedua kolom tsb.
2. **Timezone shift 1 hari** — fungsi `tanggalPlus()` di JS sempat pakai `.toISOString()` untuk membentuk string tanggal. `toISOString()` mengonversi ke UTC; di WIB (UTC+7) tengah malam lokal jatuh ke tanggal sebelumnya — menyebabkan seluruh kolom Matrix salah lookup tanggal (mundur 1 hari) dan navigasi minggu cuma maju 6 hari. Fix: hitung tanggal murni pakai komponen lokal (`getFullYear`/`getMonth`/`getDate`). **Pelajaran berlaku umum:** semua perhitungan tanggal/jam yang bisa memengaruhi query/tampilan HARUS di server (PHP, `appTimezone` sudah `Asia/Jakarta`), bukan di browser JS.
3. **Swap salah desain: tukar kepemilikan baris, bukan nilai shift** — implementasi awal `Jadwal::swap()` menukar `karyawan_id` antar dua baris. Begitu Master Jadwal diterapkan (setiap karyawan punya baris di ke-7 hari), hampir semua swap lintas tanggal gagal false-positive "bentrok". Fix: desain diubah total jadi menukar **nilai shift** saja, plus cabang khusus untuk tukar Libur (lihat `AULIA-02-modul-pendukung.md` §1.4). Setelah fix ini ditemukan sub-kasus: tukar Libur↔Libur murni dengan desain "tukar nilai shift" adalah no-op (L tetap L) — dibuatkan cabang `swapLibur()` terpisah yang benar-benar merelokasi hari libur.

### 8.2 File-file modul ini
Migration: `2026-09-05-000001_AddIsActiveToUsers.php`, `...-000002_CreateJadwalTable.php`, `...-000003_CreateMasterJadwalTables.php`, `jadwal_module_raw.sql`. Model: `JadwalModel.php`, `MasterJadwalModel.php`. Controller: `Jadwal.php` (satu file untuk admin + roster kasir). View: `jadwal/index.php`, `roster/index.php`. JS: `jadwal.js`, `roster.js`. Diubah: `Routes.php`, `Filters/AuthFilter.php`, `Models/UserModel.php`, `Views/layout/main.php`.

### 8.3 Pekerjaan terbuka — Modul Jadwal
- Belum ada test end-to-end dengan DB sungguhan — seluruh pengujian sejauh ini adalah logic murni (standalone, tanpa DB).
- ~~UI edit cell di tab Master Jadwal masih pakai `prompt()` browser~~ — sudah diganti modal (`modalMasterCell`, 2026-09-11).
- Staffing conflict (configurable, warning-only) ada di requirement tapi **belum diimplementasikan**.
- Ada baris karyawan dengan `nama`/`inisial` kosong yang muncul di Matrix maupun Master — kemungkinan data lama, perlu dicek manual, belum ada tindakan.
