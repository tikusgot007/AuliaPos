# Changelog AULIA

Riwayat implementasi AuliaPos (modul transaksi/kasir inti), diringkas dari `AULIA-CHANGELOG.md` versi lama (sekarang di `archive/`). Entri modul Chat/Inbox WhatsApp sengaja tidak ada di branch ini — modul itu hanya ada di `v2.2`, riwayat lengkapnya ada di sana. File ini menjawab **"kenapa"** — aturan bisnis final ada di [`AULIA.md`](./AULIA.md), [`CHAT.md`](./CHAT.md), [`USER-SHIFT.md`](./USER-SHIFT.md), bukan di sini. Diurutkan kronologis.

---

## 2026-09-04 — Audit `kasir/edit.php` & Pembayaran

### Changed
Bug `total_dibayar` hilang saat edit transaksi (akses array sebagai object) diperbaiki lewat `sinkronkanPembayaran()`. Auto-refund otomatis ke `cash_expense` (pernah menyebabkan error 500 pada edit kedua) dihapus. Logic kasir diekstrak ke `public/assets/js/kasir-shared.js`, dipakai bersama `kasir/index.php` & `kasir/edit.php`. Indikator kelebihan bayar ditambahkan.

### Why
Refund otomatis dari edit transaksi bertentangan dengan aturan "refund adalah proses tersendiri", dan bug tipe data membuat `total_dibayar` diam-diam salah tanpa error.

### Impact
Transaksi belum bayar/lunas-diturunkan/DP-dinaikkan diuji stabil. Tidak ada perubahan skema.

---

## 2026-09-05 — Pengetatan Lifecycle Status Transaksi (Syarat SELESAI)

### Changed
`TransaksiModel::ubahStatus()` menolak transisi `proses → selesai` kecuali user admin **dan** `status_pembayaran` (disegarkan lewat `sinkronkanPembayaran()` sebelum dicek) sudah `lunas`. Tombol "Selesai" di-gate `role==='admin'` di UI (layer 1); pesan error spesifik dari backend ditampilkan lewat `showToast()`. Filter Status Transaksi diganti 4 pilihan eksplisit (Semua/Proses/Selesai/Batal), default berubah jadi "Semua".

### Why
`ubahStatus()` sebelumnya tidak memeriksa role maupun status pembayaran sama sekali, sehingga kombinasi tidak valid seperti `selesai + belum_bayar` bisa terjadi. `TransaksiModel::ubahStatus()` dikonfirmasi sebagai satu-satunya jalur penulisan `status='selesai'` di codebase.

### Impact
8 skenario standalone lulus. Tidak ada perubahan di `Api.php`/view/mekanisme pembayaran — otomatis mengikuti aturan baru karena tersentralisasi di satu method. Kombinasi `Proses + Lunas` di filter jadi daftar kerja admin untuk transaksi lunas yang belum ditandai selesai.

---

## 2026-09-05 — Pelunasan Terlambat / Backdate Payment (versi awal)

### Changed
`TransaksiModel::tambahPembayaran()` menerima `tanggal` manual dan `kasir_id` penerima. Tanggal berbeda >60 detik dari waktu server dianggap backdate → wajib admin, tidak boleh sebelum tanggal transaksi (dibanding di level hari, bukan jam — diperbaiki dari bug awal yang membanding timestamp penuh), tidak boleh di masa depan. Endpoint baru `GET /api/kasir-list` (saat itu admin-only).

### Why
Uang kadang sudah diterima sebelum sempat dicatat di sistem; sistem perlu mencatat siapa kasir yang benar-benar menangani, bukan otomatis admin yang menginput.

### Impact
`CashBalanceService`/laporan otomatis ikut benar karena sudah memakai `pembayaran.tanggal`, bukan `created_at` — tanpa kode tambahan. Risiko didokumentasikan (bukan bug): `cash_opname` yang sudah dilakukan sebelum backdate masuk bisa berbeda dari rekonsiliasi retroaktif; tidak ada mekanisme koreksi otomatis. 17 skenario standalone lulus.

---

## 2026-09-05 — Restrukturisasi UI Daftar Transaksi & Kasir/POS

### Changed
Baris tabel `transaksi/index.php` tidak lagi bisa diklik; tombol Edit di kolom Aksi dihapus, diganti "Lihat Detail" (alur edit wajib lewat halaman detail). Kartu "Banner"/"Manual Input"/"Ukuran Custom" di Kasir dipindah ke section "Aksi Khusus" terpisah dari `#produkList` supaya tidak ikut kena filter kategori/pencarian produk.

### Why
Murni kosmetik/struktur UI — tidak ada logic bisnis yang berubah.

### Impact
Tidak ada perubahan behavior fungsional pada kedua fitur.

---

## 2026-09-05 — Modul Jadwal Karyawan: Stabilisasi Awal

### Changed
Kolom FK `karyawan_id` diperbaiki dari `UNSIGNED` (menyebabkan errno 150) jadi signed sesuai `users.id`. Perhitungan tanggal di JS (`tanggalPlus()`) dipindah dari `.toISOString()` (UTC, menyebabkan shift 1 hari di WIB) ke komponen tanggal lokal. `Jadwal::swap()` didesain ulang total dari "tukar kepemilikan baris" jadi "tukar nilai shift" (plus cabang `swapLibur()` terpisah untuk swap Libur↔Libur, yang sebelumnya jadi no-op).

### Why
MySQL/MariaDB mewajibkan signedness identik untuk FK. `toISOString()` mengonversi ke UTC sehingga tengah malam WIB salah lookup tanggal. Setelah Master Jadwal aktif (tiap karyawan punya baris tiap hari), desain swap "tukar baris" nyaris selalu false-positive "bentrok".

### Impact
Pelajaran berlaku umum untuk seluruh codebase: semua perhitungan tanggal/jam yang memengaruhi query/tampilan harus dihitung di server (PHP, `appTimezone=Asia/Jakarta`), bukan di browser JS. 3 file 0-byte tidak terpakai (`LaporanPenjualan.php`, `banner.js`, `modal_banner.js`) dihapus terpisah sebagai housekeeping.

---

## 2026-09-10 — Kapabilitas SELESAI dari Workflow Kasir/POS (Phase 2)

### Changed
Kasir bisa menyelesaikan transaksinya sendiri lewat endpoint POS khusus `/api/kasir/selesaikan-transaksi`, terbatas untuk transaksi `sumber='kasir_pos'` + `kasir_id === id_user` + `status='proses'` + `status_pembayaran='lunas'`. `TransaksiModel::ubahStatus()` menerima parameter kapabilitas-konteks yang melewati gate "harus admin" tapi tetap tidak melewati syarat `lunas`.

### Why
Setelah pengetatan syarat SELESAI (2026-09-05), semua transaksi — termasuk POS yang dibuat & sudah lunas oleh kasir sendiri di tempat — tetap harus menunggu admin. Diputuskan memberi kasir kapabilitas terbatas untuk kasus ini tanpa melonggarkan jalur status umum (`/api/ubah-status`, Daftar/Detail tetap admin-only saat itu).

### Impact
Cek role/kepemilikan/`sumber` ada di controller endpoint, bukan di model. Jalur umum tidak berubah.

---

## 2026-09-15 — Backdate Payment: Diperluas ke Effective Shift Leader (Tahap 5)

### Changed
Kapabilitas backdate payment (sebelumnya admin-only sejak 2026-09-05) diperluas untuk Effective Shift Leader saat itu — termasuk endpoint `GET /api/kasir-list` yang jadi admin **atau** Shift Leader. `TransaksiModel::ubahStatus()` juga mengetatkan transisi PROSES→BATAL terkait kapabilitas Shift Leader di periode yang sama.

### Why
Bagian dari pengembangan fitur Employee Priority + Effective Shift Leader — Shift Leader butuh kapabilitas operasional setara admin untuk kasus tertentu tanpa menjadikannya role permanen baru.

### Impact
Validasi batas tanggal (tidak boleh sebelum tanggal transaksi, tidak boleh masa depan) berlaku tanpa kecuali untuk admin maupun Shift Leader. Suite PHPUnit (200+ test) tetap hijau.

---

## 2026-09-16 — Redesain UI Backdate Payment: Tombol Terpisah

### Changed
UI backdate payment diganti dari checkbox opsional di dalam modal Tunai/DP/Konfirmasi menjadi **tombol terpisah** ("Bayar Backdate"/"Lunasi Backdate") di halaman detail transaksi/tagihan. Tombol baru langsung menampilkan field tanggal (wajib) & kasir penerima di modal utama, sebelum metode pembayaran dipilih. Tombol pembayaran normal ("Bayar Sekarang"/"Lunasi") tidak menampilkan field ini sama sekali — behavior identik seperti sebelumnya.

### Why
Dipicu insiden nyata: Shift Leader lupa mencentang checkbox backdate di dalam modal, sehingga pembayaran tercatat dengan tanggal hari ini tanpa peringatan apa pun. Checkbox opsional yang tersembunyi terlalu mudah terlewat.

### Impact
File: `transaksi/detail.php` (2 tombol baru), `components/payment/modal.php` (section tanggal/kasir dipindah ke modal utama, dihapus dari 3 modal metode), `payment.js` (state `backdateMode`, guard sebelum lanjut ke metode). Validasi backend (`TransaksiModel::tambahPembayaran()`) tidak berubah sama sekali — murni perubahan UI. Suite PHPUnit tetap hijau setelah perubahan.

---

## 2026-09-16 — Konsolidasi Dokumentasi: AULIA.md, CHAT.md, USER-SHIFT.md

### Changed
Dokumen topik menengah (`AULIA-01/02`, `AULIA-CHANGELOG`, `CHAT-01`, `CHAT-CHANGELOG`) dan dokumen arsip bernomor asli (`aturan-bisnis-AULIA.md`, `aturan-bisnis-CHAT.md`, `aturan-bisnis-USER-SHIFT.md`) dikonsolidasikan jadi 3 dokumen tunggal per domain — `AULIA.md`, `CHAT.md`, `USER-SHIFT.md` — sebagai satu-satunya sumber aturan bisnis aktif. 8 dokumen lama dipindah ke `docs/archive/` sebagai riwayat, dengan 3 file kompatibilitas dipertahankan di lokasi asal (`docs/aturan-bisnis-{AULIA,CHAT,USER-SHIFT}.md`) karena dikutip langsung oleh komentar kode.

### Why
Sebelumnya seorang developer perlu membaca README + 2-3 dokumen topik + dokumen arsip bernomor untuk memahami satu domain aturan bisnis secara utuh — terlalu terfragmentasi. Target: README → satu dokumen domain → selesai.

### Impact
Tidak ada perubahan `app/**`/`public/**`/migration/route/test/business logic — murni dokumentasi. Lihat "Documentation Migration Report" pada akhir sesi ini untuk rincian lengkap (rule inventory, konflik, gap yang ditemukan).
