# Changelog AULIA

Riwayat implementasi AuliaPos (modul transaksi/kasir inti + modul Chat/Inbox WhatsApp), diringkas dari `AULIA-CHANGELOG.md` dan `CHAT-CHANGELOG.md` versi lama (sekarang di `archive/`). File ini menjawab **"kenapa"** — aturan bisnis final ada di [`AULIA.md`](./AULIA.md), [`CHAT.md`](./CHAT.md), [`USER-SHIFT.md`](./USER-SHIFT.md), bukan di sini. Diurutkan kronologis.

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

## 2026-09-07 — Modul Chat/Inbox: Fondasi & Alur Pesan Dasar (Phase 1–4)

### Changed
Database terpisah total (`aulia_inboxdb`, connection group `$inbox`) dengan 3 tabel (`conversations`, `messages`, `gateway_status`). Alur masuk: Gateway (Node.js/Baileys, reliability buffer SQLite lokal) → `POST /api/inbox/gateway/messages` (Bearer token, idempotent by `wa_message_id`) → `aulia_inboxdb`. Alur keluar: `Inbox::kirim()` → Gateway `POST /send` → hanya disimpan `outgoing`/`sent` kalau Gateway konfirmasi sukses. UI Inbox dengan polling (6/4/15 detik, bukan WebSocket).

### Why
Kasir perlu balas chat WhatsApp customer langsung dari AuliaPos, terhubung ke Gateway WhatsApp terpisah di LAN toko, tanpa mencampur data dengan skema AuliaPos yang sudah ada.

### Impact
`$default` connection AuliaPos tidak tersentuh sama sekali. **Bug ditemukan & diperbaiki:** `except` filter `Config/Filters.php` saja terbukti tidak cukup untuk membebaskan route Gateway dari `AuthFilter` di production — fix definitif adalah bypass eksplisit `strpos($uriGateway, 'api/inbox/gateway/') !== false` di `AuthFilter::before()` (bukan `=== 0`). Phase 2–3 divalidasi end-to-end sungguhan; Phase 4 baru lolos audit sintaks saat itu.

---

## 2026-09-07 — Modul Chat/Inbox: Perbaikan Pasca-Launch & Gelombang 2

### Changed
Timezone jam pesan diperbaiki (`DateTimeZone('Asia/Jakarta')` eksplisit di 3 tempat CI4, tidak bergantung setting server). Balasan dari WhatsApp Web/HP langsung (`fromMe=true`) ikut disinkronkan sebagai `direction` baru, ditampilkan sebagai "Staff (WA Web/HP)". Pesan status WhatsApp (`status@broadcast`) difilter agar tidak masuk sebagai chat. Fitur "Chat Baru" + normalisasi nomor telepon ditambahkan.

### Why
Baileys mengirim UTC (benar untuk transport), tapi CI4 memformat tanpa konversi ke lokal. Balasan dari HP/WA Web staf sebelumnya diabaikan sepenuhnya, padahal tetap perlu tercatat sebagai riwayat percakapan.

### Impact
Tidak ada perubahan skema untuk fix timezone; kolom `direction` baru untuk sinkronisasi WA Web/HP (migrasi otomatis, data lama tidak hilang).

---

## 2026-09-10 — Kapabilitas SELESAI dari Workflow Kasir/POS (Phase 2)

### Changed
Kasir bisa menyelesaikan transaksinya sendiri lewat endpoint POS khusus `/api/kasir/selesaikan-transaksi`, terbatas untuk transaksi `sumber='kasir_pos'` + `kasir_id === id_user` + `status='proses'` + `status_pembayaran='lunas'`. `TransaksiModel::ubahStatus()` menerima parameter kapabilitas-konteks yang melewati gate "harus admin" tapi tetap tidak melewati syarat `lunas`.

### Why
Setelah pengetatan syarat SELESAI (2026-09-05), semua transaksi — termasuk POS yang dibuat & sudah lunas oleh kasir sendiri di tempat — tetap harus menunggu admin. Diputuskan memberi kasir kapabilitas terbatas untuk kasus ini tanpa melonggarkan jalur status umum (`/api/ubah-status`, Daftar/Detail tetap admin-only saat itu).

### Impact
Cek role/kepemilikan/`sumber` ada di controller endpoint, bukan di model. Jalur umum tidak berubah.

---

## 2026-09-12 — Modul Chat/Inbox: Media Gambar/Dokumen & Audio/Video

### Changed
Media gambar/dokumen memakai pola **referensi** (`directPath`, `mediaKey`, bukan file disimpan) — diunduh on-demand lewat `Inbox::media()` → Gateway `POST /media/download`, di-stream langsung ke browser, tidak pernah ditulis ke disk CI4. Audio/video memakai jalur lebih longgar: hanya metadata (`mimetype`, `file_length`), tidak butuh referensi lengkap — pesan tetap diteruskan meski metadata kosong (beda dari gambar/dokumen yang di-drop kalau referensi tidak lengkap). Outgoing media (kasir kirim dari POS) memakai pola referensi yang sama lewat `POST /send-media`.

### Why
Menyimpan file media di server AuliaPos tidak perlu dan berisiko — referensi terenkripsi WhatsApp sudah cukup untuk retrieval on-demand. Audio/video secara teknis tidak menyediakan referensi terenkripsi yang sama seperti gambar/dokumen.

### Impact
Kolom `media_*` di `messages` (disiapkan sejak Phase 1) mulai dipakai. Unduh media sungguhan dari server WhatsApp tidak bisa ditest di environment pengembang (tanpa akses internet) — wajib diverifikasi user. Simulasi otomatis audio/video (MIME/ukuran kosong, voice note `ptt=true`, idempotency) lulus lengkap.

---

## 2026-09-12 — Modul Chat/Inbox: Hapus Percakapan & Assignment (versi awal)

### Changed
`Inbox::hapusPercakapan()` memanggil `ConversationModel::delete()`; `messages` ikut terhapus lewat FK `ON DELETE CASCADE` (bukan query DELETE terpisah). `conversations.assigned_to` (sudah ada sejak Phase 1) dimanfaatkan lewat endpoint `ambilPercakapan()`/`lepasPercakapan()`/`cekOwnership()` — badge staff penangan (biru=diri sendiri, abu-abu=staff lain).

### Why
Perlu ada cara staf "memiliki" sebuah percakapan agar tidak dua orang membalas bersilangan, dan cara membersihkan percakapan yang tidak relevan.

### Impact
Hapus percakapan **ditest langsung user dan lolos**. Assignment awal baru lolos `php -l`, belum diuji end-to-end 2-akun (diaudit ulang & diperbaiki di Tahap 2, 2026-09-13). Gap dicatat: hapus percakapan awalnya tidak mensyaratkan Admin-only maupun status CLOSED lebih dulu (baru disepakati Tahap 5).

---

## 2026-09-12 — Modul Chat/Inbox: Identity & Conversation Reconciliation

### Changed
4 kolom nullable + tabel `conversation_identities` baru (additive, ada backfill, tanpa `UPDATE`/`DELETE` pada data existing). `ConversationModel::resolveConversationId()` baru dengan urutan pencarian: exact `chat_id` → LID hint (lihat revisi di bawah) → cocok nomor telepon → buat baru. `App\Libraries\PhoneNumber.php` diekstrak dari `normalizePhoneToJid()`.

**Revisi LID-FIRST → PN-LATER (hari sama):** audit source code Baileys ter-install (bukan tebakan dokumentasi) mengonfirmasi mapping LID↔PN via `sock.onWhatsApp()` hanya berjalan **satu arah, PN → LID** — mustahil me-resolve nomor dari `@lid` yang datang duluan. Solusi ganda: (1) Gateway meng-enrich setiap pesan `jid_type='pn'` dengan `identity_hint.lid` (hasil `onWhatsApp()`, best-effort, dipercaya AuliaPos hanya jika pesan itu sendiri `jid_type==='pn'`); (2) fallback manual `Inbox::konfirmasiNomorWhatsapp()` untuk kasir/admin mengisi `phone` pada conversation `@lid` secara sadar.

### Why
Customer yang sama bisa muncul sebagai 2 conversation berbeda (`@lid` vs `@s.whatsapp.net`) tanpa reconciliation. Pencocokan-nomor versi awal hanya bisa mencocokkan 2 conversation yang **sama-sama** sudah punya `phone` terverifikasi — tidak pernah bisa menangani kasus paling umum (LID datang duluan, `phone=NULL` selamanya per desain "jangan menebak dari @lid").

### Impact
Migration + `resolveConversationId()` diuji langsung terhadap MySQL disposable terpisah (Test Case A–J dan Test 1–10 skenario LID/PN, semua lulus). Migration **belum diterapkan ke `aulia_inboxdb` live** saat ditulis — wajib `php spark migrate` (tanpa `-g`, karena `-g inbox` terbukti memaksa migration grup lain ikut koneksi `inbox` dan error). `sock.onWhatsApp()` belum pernah dipanggil ke server WhatsApp sungguhan — status "LID↔PN resolved" belum bisa diklaim sampai diverifikasi live.

---

## 2026-09-13 — Modul Chat/Inbox: Audit Assignment & Integrasi Lifecycle (Tahap 2–3)

### Changed
**Tahap 2:** race condition di `ambilPercakapan()` diperbaiki — dari baca-lalu-update terpisah jadi satu statement atomic `UPDATE ... WHERE id=? AND assigned_to IS NULL`, dideteksi lewat `affectedRows()`. Bug perbandingan tipe data JS (`===` string vs number) di badge assignment diperbaiki jadi `String()===String()`. **Tahap 3:** audit integrasi status Lifecycle (Open/Closed) × Assignment — tidak ditemukan gap, tidak ada perubahan kode.

### Why
Dua request "Ambil" nyaris bersamaan bisa sama-sama lolos pengecekan `assigned_to IS NULL` di PHP sebelum salah satu meng-update. Root cause bug tipe data sama persis dengan bug `id` yang pernah terjadi sebelumnya di modul ini.

### Impact
Tahap 2: Test A–K (termasuk race condition 2-request bersamaan) lulus di MySQL disposable. Tahap 3: Test A–R (18 skenario) lulus — kedua fitur (dikerjakan terpisah) sudah memenuhi rule integrasi karena masing-masing endpoint hanya menyentuh kolom miliknya sendiri sejak awal. Tidak ada migration baru di kedua tahap. Klik nyata di browser (2 akun berbeda, race condition sungguhan) tetap belum diverifikasi.

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

---

## 2026-09-16 s/d 2026-09-18 — Port Shared WhatsApp Inbox ke v2.1 + Response State

### Changed
Modul Inbox diport dari `v3.0` ke `v2.1`, lalu ditambah: sticker (kirim & terima), drag-and-drop file ke panel chat, HTTP cache untuk media (cegah re-fetch berulang ke Gateway), dan **Response State** (Perlu Dibalas / Menunggu Customer / Follow-up / Selesai), tandai dibaca, snooze, badge sidebar, serta filter di `/inbox` (`CHAT.md` Section 19). Migration `2026-09-19-000001_AddResponseStateFoundation` menambah `last_seen_by_assignee_at` dan `snoozed_until` di `conversations`.

### Why
Kasir butuh melihat cepat conversation mana yang harus dibalas; Response State sengaja dihitung (bukan kolom yang ditulis manual) agar tidak bisa "kebalik" saat endpoint baru lupa memperbaruinya. Bug awal (`tandaiDibaca()` kembali ke `perlu_dibalas`, `kirimMedia()` tidak menulis `last_seen_by_assignee_at`) sudah diperbaiki.

### Impact
Migration additive di koneksi `inbox`. Tidak ada perubahan pada modul transaksi/kasir.
---

## 2026-09-19 — Inbox Tahap C–F (media lokal, soft delete, media kadaluarsa, gate Gateway) + window standalone

### Changed
- **Window standalone:** `/inbox` dibuka di window terpisah (`layout/minimal.php`, tanpa sidebar); scroll otomatis tidak lagi menyeret balik saat polling.
- **Tahap C:** gambar/dokumen/sticker bisa disimpan permanen di disk lokal/HDD eksternal (`inbox.mediaStoragePath`), di-prefetch dan dicek lebih dulu sebelum live-fetch ke Gateway. Kosong = perilaku lama.
- **Tahap D:** hapus conversation berubah dari hard delete menjadi **soft delete** (`deleted_at`), admin-only dan wajib `closed`; conversation yang sama otomatis dihidupkan kembali bila customer chat lagi.
- **Tahap E:** media yang dipastikan kadaluarsa (HTTP 410) tidak dicoba ulang — kolom `media_confirmed_gone_at` (lintas sesi) dan cache `mediaGagal` di browser.
- **Tahap F:** kirim teks, kirim media, dan "+ Chat Baru" diblokir keras di UI saat status Gateway bukan `connected`; draft tidak dihapus; auto-reload saat tersambung kembali.

### Why
- C: prinsip lama "media tidak pernah disimpan" membuat gambar lama kadaluarsa di WhatsApp dan terasa lambat dibuka.
- D: tidak ada tabel `customers` terpisah, jadi hard delete menghilangkan identitas customer yang sudah dikonfirmasi tanpa bisa dipulihkan. Data yang terhapus sebelum Tahap D tidak bisa dipulihkan.
- E: tanpa ini, polling 4 detik terus meminta media yang pasti gagal.
- F: kasir bisa mengetik/mengirim padahal Gateway mati dan baru tahu setelah gagal.

### Impact
Migration baru di koneksi `inbox`: `000002_AddMediaLocalStorage`, `000003_AddSoftDeleteInbox`, `000004_AddMediaConfirmedGone`. Test baru: `InboxMediaStorageTest`, `InboxMediaFallbackTest`, `InboxMediaConfirmedGoneTest`, `InboxSoftDeleteTest`. Prinsip "media tidak pernah disimpan permanen" dicabut untuk sisi AuliaPos (`CHAT.md` Section 6.3, 16, 14.4, 18).