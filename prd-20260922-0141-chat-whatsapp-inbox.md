# PRD: Chat / WhatsApp Inbox — AuliaPos (v2.2/v2.3)

## 1. Product overview

### 1.1 Document title and version

- PRD: Chat / WhatsApp Inbox — AuliaPos
- Version: 1.0 (retroactive — ditulis setelah Spec dan Plan sebagian sudah ada dan sebagian sudah dikoding)

### 1.2 Product summary

AuliaPos saat ini punya modul Inbox WhatsApp yang berfungsi sebagai viewer chat sederhana: semua pesan masuk dari WhatsApp Gateway (repo terpisah, `tikusgot007/WA-Gateway`, berbasis Node.js/Baileys) ditampilkan dalam satu daftar tanpa status, prioritas, atau catatan internal. Kasir kesulitan tahu percakapan mana yang belum dibalas, mana yang sedang ditangani siapa, dan tidak ada tempat mencatat konteks tanpa mengirim pesan ke pelanggan.

Produk ini punya dua sisi yang saling melengkapi:
- **M1 — Keandalan Gateway**: memastikan pesan pelanggan yang masuk ke WhatsApp Gateway tidak hilang saat Gateway sedang mati/bermasalah, dan kegagalan penyimpanan tidak lagi senyap.
- **M3 Fase 1 — Operational Inbox**: mengubah tampilan Inbox AuliaPos dari daftar chat polos menjadi ruang kerja kasir — ada antrean bertab (Belum Diambil, Open, Menunggu, Ditunda, Selesai), indikator prioritas (SLA warna), dan catatan internal antar staff.

Dokumen ini ditulis **setelah** kedua bagian sudah melewati tahap Spec (dan M3 Fase 1 juga sudah punya Plan), sebagai bentuk PRD susulan (PRD Bypass, sesuai AGENTS.md) — supaya WHY dan WHO dari pekerjaan yang sudah berjalan ini terdokumentasi dengan jelas untuk siapa pun yang membaca proyek ini setelahnya.

> [!NOTE]
> Karena ini PRD retroaktif, sebagian besar detail teknis (kontrak API, skema DB) sudah final di `spec/spec-design-m3-operational-inbox-fase1.md` dan `spec/spec-process-m1-wave1-incoming-reliability.md`. PRD ini tidak mengulang detail itu — fokus pada WHY, WHO, dan gambaran fitur dari sudut pandang pengguna.

## 2. Goals

### 2.1 Business goals

- Merapikan alur kerja kasir/admin dalam membalas chat pelanggan lewat WhatsApp, supaya tidak ada percakapan yang "hilang" di tengah daftar chat yang panjang.
- Membuat jelas siapa yang sedang menangani percakapan mana, mengurangi tumpang tindih balasan antar kasir.
- Mengurangi risiko pesan pelanggan hilang akibat WhatsApp Gateway mati/bermasalah (fondasi teknis M1), karena Queue View di M3 baru berguna kalau data pesan yang ditampilkan memang lengkap dan akurat.

### 2.2 User goals

- Kasir bisa langsung melihat percakapan mana yang **belum dibalas sama sekali** vs yang **sedang ditangani** vs yang **menunggu balasan pelanggan** vs yang **ditunda**, tanpa harus scroll satu-satu.
- Kasir bisa menandai percakapan sebagai "diambil" supaya kasir lain tahu itu sudah ada yang urus.
- Kasir bisa menunda (snooze) percakapan yang belum perlu dibalas sekarang, dengan atau tanpa alasan.
- Kasir bisa menulis catatan internal (tidak terkirim ke pelanggan) untuk konteks — misalnya "customer minta di-follow-up besok" — tanpa mengubah status percakapan.
- Kasir bisa melihat indikator visual (warna) kalau ada percakapan yang sudah lama tidak dibalas, supaya tidak lupa.

### 2.3 Non-goals (Out of Scope)

- **Fase 2 (Handoff antar staff dengan ringkasan, Collision detection, Auto-assignment)** — menunggu M2 (State Consistency) selesai karena mekanisme kepemilikan percakapan (`cekOwnership()`) saat ini belum atomic.
- **Fase 3 (fitur AI: intent filter, ringkasan otomatis, saran balasan)** — menunggu M5.
- **Customer Context penuh** (riwayat transaksi/pembayaran pelanggan ditampilkan di Inbox) — ditunda ke M4. Fase 1 hanya menampilkan data dari Inbox sendiri.
- **@mention dengan notifikasi nyata antar staff** — ditunda ke Fase 2.
- Perubahan apa pun pada logika WhatsApp Gateway sebagai bagian dari M3 (M3 murni sisi AuliaPos/CI4).
- M1 tidak menjadi syarat teknis untuk M3 berjalan (tidak ada dependency kode), tapi tetap relevan secara operasional.

## 3. User personas

### 3.1 Key user types

- Kasir (pengguna utama fitur ini)
- Admin/Owner (pengguna sekunder — konteksnya sama seperti kasir untuk sementara di Fase 1)

### 3.2 Basic persona details

- **Kasir**: staff yang sehari-hari membalas chat pelanggan soal pesanan/pembayaran/status cetakan, selain tugas kasir di POS. Butuh tahu cepat mana chat yang harus segera dibalas.
- **Admin/Owner**: mengawasi jalannya toko, bisa melihat dan ikut menangani percakapan mana pun, tapi di Fase 1 belum ada perbedaan hak akses khusus dari kasir untuk fitur Inbox (lihat Section 3.3).

### 3.3 Role-based access

- **Kasir**: bisa membalas, mengambil/melepas percakapan, snooze, dan menulis catatan internal ke percakapan mana pun — termasuk yang di-assign ke staff lain (khusus catatan internal, lihat SEC-001 di spec M3). Aksi balas/hapus/snooze tetap dibatasi kepemilikan (`cekOwnership()`), catatan internal sengaja tidak dibatasi.
- **Admin**: akses sama seperti kasir untuk modul Inbox di Fase 1 — belum ada hak admin-khusus (mis. reassign paksa) di lingkup PRD ini.

## 4. Functional requirements

- **Queue View (Priority: Must-have, M3 Fase 1a)**
  - Menampilkan 5 tab: Belum Diambil, Open, Menunggu, Ditunda, Selesai — masing-masing hasil status yang dihitung dari data yang sudah ada, bukan kolom status baru di database.
- **Conversation Detail (Priority: Must-have, M3 Fase 1a)**
  - Menampilkan thread pesan satu percakapan + tombol aksi (Balas, Ambil/Lepas, Snooze, Selesai), memakai endpoint yang sudah ada.
- **Snooze percakapan (Priority: Must-have, M3 Fase 1a; alasan snooze Fase 1b)**
  - Kasir bisa menunda percakapan dengan durasi tertentu (menit). Field alasan snooze ditambahkan belakangan (Fase 1b), disimpan sebagai catatan internal, bukan kolom baru.
- **Catatan Internal (Priority: Must-have, M3 Fase 1b)**
  - Kasir bisa menulis catatan yang hanya terlihat oleh staff, tidak pernah terkirim ke WhatsApp pelanggan, dan tidak mengubah status/prioritas percakapan.
- **Indikator SLA / prioritas warna (Priority: Should-have, M3 Fase 1b)**
  - Percakapan yang lama tidak dibalas (berdasarkan waktu pesan terakhir) diberi warna hijau/kuning/merah, kecuali yang sudah selesai atau sedang ditunda.
- **Filter & Pencarian (Priority: Should-have, M3 Fase 1b)**
  - Kasir bisa memfilter daftar percakapan berdasarkan tab status dan mencari berdasarkan nama/nomor pelanggan.
- **Keandalan penerimaan pesan (Priority: Must-have, M1 Gelombang 1)**
  - Pesan pelanggan yang masuk saat Gateway sedang mati tetap tersimpan dan muncul begitu Gateway hidup kembali — tidak hilang diam-diam.
  - Kegagalan teknis di sisi Gateway (gagal simpan ke buffer, database korup, dll.) dicatat dengan jelas dan tidak membuat pesan lain ikut hilang.

## 5. User experience

### 5.1 Entry points & first-time user flow

- Kasir masuk ke menu Inbox dari layar utama AuliaPos (sudah ada), langsung melihat Queue View dengan 5 tab, default ke tab yang paling relevan (Belum Diambil).

### 5.2 Core experience

- **Lihat antrean**: Kasir membuka Inbox, melihat daftar percakapan terbagi per tab status, dengan indikator warna untuk yang sudah lama tidak dibalas.
- **Ambil percakapan**: Kasir klik percakapan di tab "Belum Diambil", langsung berpindah ke "Open" atas namanya.
- **Balas & tangani**: Kasir membalas dari Conversation Detail, menulis catatan internal bila perlu (tidak terkirim ke pelanggan), lalu menandai Selesai atau Snooze bila belum bisa dibalas tuntas.
- **Cari percakapan lama**: Kasir memakai filter/pencarian untuk menemukan percakapan lama berdasarkan nama/nomor.

### 5.3 UI/UX highlights & Edge cases

- Percakapan yang di-snooze tidak diberi warna prioritas merah/kuning — karena memang sengaja ditunda, bukan terlambat.
- Catatan internal boleh ditulis kasir mana pun (bukan cuma yang meng-assign dirinya ke percakapan itu), termasuk pada percakapan yang sudah "Selesai".
- Percakapan baru yang belum ada indikasi arah pesan tetap harus muncul dengan status yang masuk akal (fallback ke Belum Diambil/Open), bukan error atau kosong.

## 6. Narrative

Seorang kasir membuka AuliaPos di pagi hari dan langsung tahu ada 4 percakapan yang belum dijawab semalam (tab "Belum Diambil"), 2 di antaranya sudah berwarna kuning karena menunggu lebih dari 15 menit. Ia mengambil satu percakapan, membalasnya, lalu menulis catatan internal "customer tanya diskon reseller, tunggu konfirmasi owner" supaya kasir shift berikutnya tahu konteksnya tanpa perlu membaca ulang seluruh chat. Percakapan lain ia snooze 2 jam karena pelanggan bilang akan konfirmasi nanti siang. Di belakang layar, seluruh pesan yang sempat masuk semalam saat toko tutup dan Gateway sempat restart tetap tersimpan lengkap — tidak ada yang hilang.

## 7. Success metrics

### 7.1 User-centric metrics

- Kasir bisa langsung mengidentifikasi percakapan yang belum dibalas tanpa harus membaca ulang seluruh daftar chat.
- Berkurangnya kasus dua kasir membalas percakapan yang sama secara bersamaan tanpa saling tahu.

### 7.2 Business metrics

- Berkurangnya keluhan pelanggan soal pesan tidak dibalas/terlewat.

### 7.3 Technical metrics

- 0 pesan hilang dan 0 duplikat saat Gateway mati/restart selama pengujian AC-001 (protokol `pm2 stop` ~30 detik, 10 pesan, diulang 3 kali) — lihat spec M1.
- `composer test` (AuliaPos) lolos 100% sebagai syarat setiap fase M3 dianggap selesai.

## 8. Technical considerations (Input for Engineering Team)

### 8.1 Integration points

- WhatsApp Gateway (Node.js/Baileys, repo terpisah `tikusgot007/WA-Gateway`) — sisi M1 murni ada di repo ini, tidak menyentuh AuliaPos.
- `app/Controllers/Inbox.php` dan `ConversationModel` di AuliaPos — sisi M3, sebagian besar backend sudah ada, gap yang ditutup: computed status terpadu, endpoint catatan internal, perluasan filter.

### 8.2 Data storage & privacy

- Database Inbox (`aulia_inboxdb`, koneksi `inbox`) terpisah dari database POS (`aulia_kasirdb`).
- Catatan internal disimpan sebagai baris pesan biasa dengan penanda `is_internal`, dan secara sengaja **tidak pernah** dikirim ke Gateway/WhatsApp.

### 8.3 Scalability & potential technical challenges

- Perhitungan status antrean dan warna prioritas dilakukan real-time dari data yang ada (bukan kolom status tersimpan) — pendekatan ini dipilih supaya tidak ada duplikasi logic status yang bisa saling tidak sinkron (lihat ADR-0001).
- Keandalan Gateway (M1) adalah fondasi terpisah — kegagalannya tidak memblokir pengembangan M3, tapi tanpa M1, data yang ditampilkan Queue View bisa saja tidak lengkap.

## 9. Milestones & sequencing

### 9.1 Project estimate & Team composition

- Dikerjakan bertahap oleh tim kecil (developer tunggal dibantu AI pairing), tanpa estimasi waktu formal — mengikuti alur SDLC proyek per fase.

### 9.2 Suggested phases

- **M1 Gelombang 1** (repo WA-Gateway): keandalan pesan masuk — Spec ✅ selesai, Plan ✅ selesai, Kode 🔄 sedang berjalan (Fase 1–3 dari 3 fase sudah dikoding & diuji simulasi, menunggu pengujian nyata TASK-017/018).
- **M3 Fase 1a** (AuliaPos, tanpa migration): Queue View, Conversation Detail dasar, Snooze tanpa alasan — Spec ✅ selesai, Plan ✅ selesai, Kode belum dimulai.
- **M3 Fase 1b** (AuliaPos, dengan migration baru): Catatan Internal, SLA Timer, alasan Snooze, Filter & Pencarian — Spec ✅ selesai, Plan ✅ selesai, Kode belum dimulai.
- **M2, M4, M5** — di luar scope PRD ini, roadmap jangka panjang (State Consistency, Customer Context penuh, fitur AI).

## 10. User stories & Acceptance Criteria

### 10.1. Melihat antrean percakapan berdasarkan status

- **ID**: GH-001
- **Story**: Sebagai kasir, saya ingin melihat percakapan terbagi dalam tab status (Belum Diambil, Open, Menunggu, Ditunda, Selesai), supaya saya tahu mana yang harus segera saya tangani.
- **Acceptance criteria**:
  - [ ] Percakapan yang belum di-assign siapa pun dan belum dibalas muncul di tab "Belum Diambil".
  - [ ] Percakapan yang sudah di-assign ke kasir tertentu dan belum dibalas muncul di tab "Open".
  - [ ] Percakapan yang menunggu balasan pelanggan muncul di tab "Menunggu".
  - [ ] Percakapan yang di-snooze muncul di tab "Ditunda".
  - [ ] Percakapan yang sudah selesai muncul di tab "Selesai".

### 10.2. Menulis catatan internal tanpa mengganggu status percakapan

- **ID**: GH-002
- **Story**: Sebagai kasir, saya ingin menulis catatan internal pada sebuah percakapan, supaya kasir lain tahu konteksnya tanpa saya harus mengirim pesan ke pelanggan.
- **Acceptance criteria**:
  - [ ] Catatan internal tidak pernah terkirim ke WhatsApp pelanggan.
  - [ ] Menulis catatan internal tidak mengubah tab status percakapan (mis. tetap di "Menunggu", tidak berpindah ke "Open").
  - [ ] Kasir mana pun bisa menulis catatan internal ke percakapan mana pun, termasuk yang di-assign ke kasir lain atau yang sudah "Selesai".

### 10.3. Menunda (snooze) percakapan dengan alasan

- **ID**: GH-003
- **Story**: Sebagai kasir, saya ingin menunda percakapan yang belum perlu dibalas sekarang dan mencatat alasannya, supaya saya ingat konteksnya saat kembali menanganinya nanti.
- **Acceptance criteria**:
  - [ ] Kasir bisa menunda percakapan dengan memilih durasi (menit).
  - [ ] Kasir bisa mengisi alasan penundaan, yang tersimpan sebagai catatan internal.
  - [ ] Percakapan yang ditunda tidak diberi warna prioritas merah/kuning.

### 10.4. Melihat indikator prioritas berdasarkan lama tidak dibalas

- **ID**: GH-004
- **Story**: Sebagai kasir, saya ingin melihat indikator warna pada percakapan yang sudah lama tidak dibalas, supaya saya tidak lupa menanganinya.
- **Acceptance criteria**:
  - [ ] Percakapan dengan pesan terakhir kurang dari 15 menit lalu berwarna hijau (atau tanpa warna).
  - [ ] Percakapan dengan pesan terakhir 15–60 menit lalu berwarna kuning.
  - [ ] Percakapan dengan pesan terakhir lebih dari 60 menit lalu berwarna merah.
  - [ ] Percakapan berstatus "Selesai" atau "Ditunda" tidak diberi warna prioritas.

### 10.5. Pesan pelanggan tidak hilang saat Gateway sedang mati

- **ID**: GH-005
- **Story**: Sebagai pemilik toko, saya ingin pesan pelanggan yang masuk saat WhatsApp Gateway sedang mati tetap tersimpan dan muncul begitu Gateway hidup kembali, supaya tidak ada pesan pelanggan yang hilang.
- **Acceptance criteria**:
  - [ ] Saat Gateway dimatikan sementara dan pelanggan mengirim pesan, pesan tersebut tetap muncul di Inbox setelah Gateway hidup kembali (0 hilang, 0 duplikat, diuji berulang 3 kali).
  - [ ] Kegagalan teknis pada satu pesan (mis. gagal simpan) tidak membuat pesan lain dalam batch yang sama ikut gagal.
