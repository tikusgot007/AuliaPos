# PRD: Chat / WhatsApp Inbox — AuliaPos (v2.2/v2.3)

## 1. Product overview

### 1.1 Document title and version

- PRD: Chat / WhatsApp Inbox — AuliaPos
- Version: 1.1 (retroactive — v1.0 ditulis setelah Spec dan Plan sebagian sudah ada dan sebagian sudah dikoding; v1.1 adalah amandemen yang memasukkan lingkup M3 Fase 2a)

**Riwayat amandemen:**

| Versi | Tanggal | Perubahan | Sumber |
| --- | --- | --- | --- |
| 1.0 | 2026-09-22 | PRD retroaktif awal: 5 user story (GH-001 s.d. GH-005) untuk M1 Gelombang 1 dan M3 Fase 1a/1b | — |
| 1.1 | 2026-09-22 | Fase 2 dikeluarkan dari Section 2.3 Non-goals; user story baru GH-006 (Handoff), GH-007 (Collision Detection), GH-008 (Auto-assignment, inkremen Fase 2b); gate M2 dicatat sebagai *constraint*; item yang ditunda dinamai (Presence, notifikasi/unread); Section 9.2 disinkronkan | `docs/audit/clarification-report-m3-fase2-m2-gate-2026-09-22.md` (K-02, K-03, K-04, K-08) |

### 1.2 Product summary

AuliaPos saat ini punya modul Inbox WhatsApp yang berfungsi sebagai viewer chat sederhana: semua pesan masuk dari WhatsApp Gateway (repo terpisah, `tikusgot007/WA-Gateway`, berbasis Node.js/Baileys) ditampilkan dalam satu daftar tanpa status, prioritas, atau catatan internal. Kasir kesulitan tahu percakapan mana yang belum dibalas, mana yang sedang ditangani siapa, dan tidak ada tempat mencatat konteks tanpa mengirim pesan ke pelanggan.

Produk ini punya tiga sisi yang saling melengkapi:

- **M1 — Keandalan Gateway**: memastikan pesan pelanggan yang masuk ke WhatsApp Gateway tidak hilang saat Gateway sedang mati/bermasalah, dan kegagalan penyimpanan tidak lagi senyap.
- **M3 Fase 1 — Operational Inbox**: mengubah tampilan Inbox AuliaPos dari daftar chat polos menjadi ruang kerja kasir — ada antrean bertab (Belum Diambil, Open, Menunggu, Ditunda, Selesai), indikator prioritas (SLA warna), dan catatan internal antar staff.
- **M3 Fase 2a — Handoff & Collision Detection**: menyempurnakan ruang kerja itu dengan kemampuan menyerahkan percakapan antar staff beserta ringkasan dan tindakan lanjutan, serta memastikan kepemilikan percakapan tidak tertimpa ketika dua staff menyentuh percakapan yang sama pada saat yang hampir bersamaan.

Dokumen ini ditulis **setelah** M1 dan M3 Fase 1 sudah melewati tahap Spec (dan M3 Fase 1 juga sudah punya Plan), sebagai bentuk PRD susulan (PRD Bypass, sesuai AGENTS.md) — supaya WHY dan WHO dari pekerjaan yang sudah berjalan ini terdokumentasi dengan jelas untuk siapa pun yang membaca proyek ini setelahnya.
Versi 1.1 (lihat Section 1.1) menambahkan lingkup M3 Fase 2a ke dalam dokumen ini tanpa mengubah lingkup Fase 1 yang sudah disetujui.

> [!NOTE]
> Karena ini PRD retroaktif, sebagian besar detail teknis (kontrak API, skema DB) sudah final di `spec/spec-design-m3-operational-inbox-fase1.md` dan `spec/spec-process-m1-wave1-incoming-reliability.md`. PRD ini tidak mengulang detail itu — fokus pada WHY, WHO, dan gambaran fitur dari sudut pandang pengguna.

## 2. Goals

### 2.1 Business goals

- Merapikan alur kerja kasir/admin dalam membalas chat pelanggan lewat WhatsApp, supaya tidak ada percakapan yang "hilang" di tengah daftar chat yang panjang.
- Membuat jelas siapa yang sedang menangani percakapan mana, mengurangi tumpang tindih balasan antar kasir.
- Memastikan perpindahan tanggung jawab percakapan antar staff (mis. saat pergantian shift) tidak membuat konteks dan tindakan lanjutan ikut hilang.
- Mengurangi risiko pesan pelanggan hilang akibat WhatsApp Gateway mati/bermasalah (fondasi teknis M1), karena Queue View di M3 baru berguna kalau data pesan yang ditampilkan memang lengkap dan akurat.

### 2.2 User goals

- Kasir bisa langsung melihat percakapan mana yang **belum dibalas sama sekali** vs yang **sedang ditangani** vs yang **menunggu balasan pelanggan** vs yang **ditunda**, tanpa harus scroll satu-satu.
- Kasir bisa menandai percakapan sebagai "diambil" supaya kasir lain tahu itu sudah ada yang urus.
- Kasir bisa menunda (snooze) percakapan yang belum perlu dibalas sekarang, dengan atau tanpa alasan.
- Kasir bisa menulis catatan internal (tidak terkirim ke pelanggan) untuk konteks — misalnya "customer minta di-follow-up besok" — tanpa mengubah status percakapan.
- Kasir bisa melihat indikator visual (warna) kalau ada percakapan yang sudah lama tidak dibalas, supaya tidak lupa.
- Kasir bisa menyerahkan (handoff) percakapan yang sedang ia tangani kepada kasir lain bersama ringkasan dan tindakan lanjutan yang diharapkan, supaya konteksnya tidak hilang saat ia berhenti menanganinya.
- Kasir yang kalah cepat diberi tahu dengan jelas siapa pemilik sah sebuah percakapan, supaya tidak ada dua kasir membalas percakapan yang sama atau saling menimpa pekerjaan.

### 2.3 Non-goals (Out of Scope)

> [!IMPORTANT]
> **Constraint (bukan Non-goal) — Gate M2 dibuka secara sempit (K-01).**
> M2 sebagai program State Consistency tetap ditunda. Fase 2a boleh berjalan hanya lewat satu kontrak sempit: perubahan kepemilikan percakapan dilakukan sebagai *conditional write* terhadap pemilik yang diharapkan.
> Penulisan hanya berhasil kalau pemilik percakapan masih sama dengan yang dibaca sebelumnya; kalau tidak, penulisan ditolak dan kepemilikan yang sudah sah **tidak pernah** ditimpa.
> Lingkup atomicitas dibatasi pada jalur Handoff dan **tidak boleh** melebar menjadi perombakan state consistency menyeluruh (`docs/ARCHITECTURE.md` Section 12). Jalur lain yang masih read-then-write (lepas, selesai, snooze, tandai dibaca) tidak diubah di Fase 2a.
>
> Sumber keputusan: `docs/audit/clarification-report-m3-fase2-m2-gate-2026-09-22.md` (K-01, K-03).

- **Fase 2b — Auto-assignment** — ditunda ke inkremen berikutnya setelah Fase 2a (lihat GH-008 di Section 10.8). Aturan penentuannya belum ditetapkan (beban kerja apa yang dihitung, apa arti "sedang bertugas", dan urutan prioritas antar staff), sehingga belum boleh dikerjakan bersamaan dengan Handoff dan Collision Detection.
- **Presence / awareness antar staff** ("sedang dibuka oleh Budi") — sengaja ditunda dengan prasyarat bernama: baru relevan setelah ada kebutuhan nyata mencegah dua staff membuka percakapan yang sama secara bersamaan. Presence bukan bagian dari Collision Detection.
- **Notifikasi antar staff dan penanda belum dibaca (unread) per pengguna** — sengaja ditunda dengan prasyarat bernama: baru diperlukan setelah terbukti secara operasional bahwa penerima Handoff yang sedang offline melewatkan percakapan yang diserahkan kepadanya. Fase 2a sengaja berjalan tanpa notifikasi; risiko ini diterima secara sadar dengan mitigasi prosedural, bukan teknis.
- **Handoff dan Collision Detection (Fase 2a)** — sekarang **masuk lingkup** dokumen ini; lihat Section 4, Section 10.6, dan Section 10.7.
- **Fase 3 (fitur AI: intent filter, ringkasan otomatis, saran balasan)** — menunggu M5.
- **Customer Context penuh** (riwayat transaksi/pembayaran pelanggan ditampilkan di Inbox) — ditunda ke M4. Fase 1 hanya menampilkan data dari Inbox sendiri.
- **@mention dengan notifikasi nyata antar staff** — ditunda ke inkremen berikutnya setelah Fase 2a; Fase 2a secara sadar berjalan tanpa notifikasi antar staff.
- **Penghapusan riwayat Handoff** — tidak ada penghapusan riwayat di Fase 2a; riwayat penyerahan adalah jejak audit yang harus tetap bisa dibaca.
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
- **Handoff (Fase 2a)**: hanya staff yang sedang memegang percakapan yang boleh menyerahkannya; pengecualian berlaku untuk percakapan di tab "Belum Diambil" yang boleh diserahkan oleh staff mana pun. Target wajib akun staff aktif yang lain (bukan diri sendiri) dan boleh sedang offline. Belum ada jalur handoff paksa oleh admin di lingkup PRD ini.

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
- **Handoff antar staff (Priority: Must-have, M3 Fase 2a)**
  - Staff yang sedang memegang percakapan bisa menyerahkannya kepada staff aktif lain, wajib disertai ringkasan keadaan percakapan dan tindakan lanjutan yang diharapkan, dengan catatan bebas yang sifatnya opsional.
  - Handoff bersifat transfer langsung tanpa alur persetujuan; riwayat penyerahan tercatat dan bisa dibaca kembali oleh staff.
  - Percakapan yang sedang ditunda tetap ditunda setelah diserahkan (waktu penundaan tidak direset).
- **Collision Detection (Priority: Must-have, M3 Fase 2a)**
  - Kalau dua staff mengubah kepemilikan percakapan yang sama pada saat yang hampir bersamaan, tepat satu perubahan diterima; permintaan yang kalah ditolak dan kepemilikan yang sudah sah tidak pernah ditimpa.
  - Staff yang kalah diberi tahu siapa pemilik sah percakapan itu saat ini, supaya ia tahu harus berkoordinasi dengan siapa.
  - Deteksi hanya berlaku pada saat perubahan disimpan, bukan saat percakapan sekadar dibuka atau dilihat (Presence bukan bagian dari fitur ini).
- **Auto-assignment (Priority: Should-have, M3 Fase 2b — inkremen berikutnya)**
  - Percakapan baru diarahkan otomatis ke staff tertentu berdasarkan aturan pembagian beban kerja. Aturan tersebut belum ditetapkan, sehingga fitur ini belum masuk Fase 2a (lihat Section 2.3 dan GH-008 di Section 10.8).
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
- **Serahkan percakapan (handoff)**: Kasir yang mengakhiri shift menyerahkan percakapan yang masih berjalan ke kasir shift berikutnya — mengisi ringkasan keadaan terakhir dan tindakan lanjutan yang diharapkan — supaya tanggung jawab berpindah tanpa konteks yang hilang.

### 5.3 UI/UX highlights & Edge cases

- Percakapan yang di-snooze tidak diberi warna prioritas merah/kuning — karena memang sengaja ditunda, bukan terlambat.
- Catatan internal boleh ditulis kasir mana pun (bukan cuma yang meng-assign dirinya ke percakapan itu), termasuk pada percakapan yang sudah "Selesai".
- Percakapan baru yang belum ada indikasi arah pesan tetap harus muncul dengan status yang masuk akal (fallback ke Belum Diambil/Open), bukan error atau kosong.
- Kalau dua kasir menyimpan perubahan kepemilikan pada percakapan yang sama pada saat yang hampir bersamaan, kasir yang kalah tidak boleh melihat tampilan yang menyesatkan: layar menampilkan nama pemilik sah yang terbaru beserta penjelasan singkat, dan percakapan dimuat ulang ke kondisi terkini.
- Percakapan yang sedang ditunda lalu di-handoff tetap tampil di tab "Ditunda" atas nama penerima baru, tanpa warna prioritas.

## 6. Narrative

Seorang kasir membuka AuliaPos di pagi hari dan langsung tahu ada 4 percakapan yang belum dijawab semalam (tab "Belum Diambil"), 2 di antaranya sudah berwarna kuning karena menunggu lebih dari 15 menit. Ia mengambil satu percakapan, membalasnya, lalu menulis catatan internal "customer tanya diskon reseller, tunggu konfirmasi owner" supaya kasir shift berikutnya tahu konteksnya tanpa perlu membaca ulang seluruh chat. Percakapan lain ia snooze 2 jam karena pelanggan bilang akan konfirmasi nanti siang. Di belakang layar, seluruh pesan yang sempat masuk semalam saat toko tutup dan Gateway sempat restart tetap tersimpan lengkap — tidak ada yang hilang.
Sebelum pulang, ia menyerahkan dua percakapan yang belum tuntas kepada kasir shift malam bersama ringkasan dan tindakan lanjutan yang diharapkan, sehingga rekannya langsung tahu apa yang harus dikerjakan.
Ketika keduanya sempat menyentuh percakapan yang sama, hanya satu perubahan yang diterima — kasir yang kalah diberi tahu siapa pemilik sahnya.

## 7. Success metrics

### 7.1 User-centric metrics

- Kasir bisa langsung mengidentifikasi percakapan yang belum dibalas tanpa harus membaca ulang seluruh daftar chat.
- Berkurangnya kasus dua kasir membalas percakapan yang sama secara bersamaan tanpa saling tahu.
- Frekuensi percakapan yang berpindah tangan (handoff) tercatat lengkap beserta ringkasan dan tindakan lanjutannya, sehingga koordinasi antar shift bisa dievaluasi.

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
- Riwayat Handoff disimpan di database Inbox yang sama dengan percakapan; riwayat ini tidak menambah data pribadi pelanggan di luar yang sudah tersimpan, dan tidak ada penghapusan riwayat pada Fase 2a.

### 8.3 Scalability & potential technical challenges

- Perhitungan status antrean dan warna prioritas dilakukan real-time dari data yang ada (bukan kolom status tersimpan) — pendekatan ini dipilih supaya tidak ada duplikasi logic status yang bisa saling tidak sinkron (lihat ADR-0001).
- Keandalan Gateway (M1) adalah fondasi terpisah — kegagalannya tidak memblokir pengembangan M3, tapi tanpa M1, data yang ditampilkan Queue View bisa saja tidak lengkap.
- Gate M2 sebagai *constraint* (K-01): atomicitas kepemilikan dibuka sempit hanya pada jalur Handoff — penulisan hanya berhasil bila pemilik percakapan masih sama dengan yang dibaca, dan kegagalannya ditolak **tanpa** menimpa kepemilikan. M2 tetap deferred dan tidak boleh melebar menjadi perombakan state consistency (`docs/ARCHITECTURE.md` Section 12).
- Jalur kepemilikan lain yang masih read-then-write (lepas, selesai, snooze, tandai dibaca, hapus) secara sadar tidak diperbaiki di Fase 2a; perbaikan menyeluruhnya menunggu M2.
- Handoff tidak mengirim pesan apa pun ke pelanggan dan tidak menambah panggilan baru ke WhatsApp Gateway.

## 9. Milestones & sequencing

### 9.1 Project estimate & Team composition

- Dikerjakan bertahap oleh tim kecil (developer tunggal dibantu AI pairing), tanpa estimasi waktu formal — mengikuti alur SDLC proyek per fase.

### 9.2 Suggested phases

- **M1 Gelombang 1** (repo WA-Gateway): keandalan pesan masuk — Spec ✅ selesai, Plan ✅ selesai, Kode 🔄 sedang berjalan (Fase 1–3 dari 3 fase sudah dikoding & diuji simulasi, menunggu pengujian nyata TASK-017/018).
- **M3 Fase 1a** (AuliaPos, tanpa migration): Queue View, Conversation Detail dasar, Snooze tanpa alasan — Spec ✅ selesai, Plan ✅ selesai, Kode belum dimulai.
- **M3 Fase 1b** (AuliaPos, dengan migration baru): Catatan Internal, SLA Timer, alasan Snooze, Filter & Pencarian — Spec ✅ selesai, Plan ✅ selesai, Kode belum dimulai.
- **M3 Fase 2a** (AuliaPos, dengan migration baru): Handoff antar staff + Collision Detection — PRD ✅ v1.1 (dokumen ini), Spec belum dibuat, Plan belum, Kode belum dimulai.
- **M3 Fase 2b** (inkremen berikutnya): Auto-assignment (GH-008) — belum dijadwalkan; menunggu aturan pembagian beban kerja ditetapkan lebih dulu.
- **M2, M4, M5** — di luar scope PRD ini sebagai program, roadmap jangka panjang (State Consistency, Customer Context penuh, fitur AI). Catatan: *constraint* K-01 di Section 2.3 membuka jalur sempit agar Fase 2a bisa berjalan tanpa menutup M2 sebagai program.

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

### 10.6. Menyerahkan percakapan ke staff lain (Handoff — Fase 2a)

- **ID**: GH-006
- **Story**: Sebagai kasir, saya ingin menyerahkan percakapan yang sedang saya tangani kepada kasir lain beserta ringkasan dan tindakan lanjutan yang diharapkan, supaya tanggung jawab dan konteksnya tidak hilang saat saya berhenti menanganinya.
- **Acceptance criteria**:
  - [ ] Kasir yang sedang memegang percakapan bisa menyerahkannya kepada staff aktif lain dengan mengisi ringkasan keadaan percakapan dan tindakan lanjutan yang diharapkan (keduanya wajib), serta catatan bebas yang opsional.
  - [ ] Handoff tersedia pada percakapan di tab Belum Diambil, Open, Menunggu, dan Ditunda; tidak tersedia untuk percakapan di tab Selesai.
  - [ ] Kasir yang bukan pemegang percakapan tidak bisa memulai Handoff, kecuali pada percakapan di tab Belum Diambil.
  - [ ] Target Handoff tidak boleh diri sendiri atau pemilik saat ini, dan harus akun staff yang aktif; target boleh sedang offline.
  - [ ] Permintaan Handoff yang tidak memenuhi syarat ditolak tanpa mengubah kepemilikan percakapan dan tanpa menghapus riwayat yang sudah ada.
  - [ ] Setelah Handoff berhasil, percakapan muncul di antrean pemilik baru dan riwayat penyerahan tercatat serta bisa dibaca kembali oleh staff.
  - [ ] Percakapan yang sedang ditunda tetap ditunda setelah Handoff (waktu penundaan tidak direset).
  - [ ] Handoff tidak mengirim pesan apa pun ke pelanggan; percakapan dari tab Belum Diambil berpindah ke tab Open atas nama penerima, sedangkan Handoff pada tab lain tidak mengubah tab percakapan.

### 10.7. Kepemilikan percakapan tidak tertimpa saat dua staff bentrok (Collision Detection — Fase 2a)

- **ID**: GH-007
- **Story**: Sebagai kasir, saya ingin tahu dengan pasti siapa pemilik sah sebuah percakapan ketika dua kasir menyentuhnya pada saat yang hampir bersamaan, supaya tidak ada balasan ganda dan tidak ada pekerjaan yang tertimpa diam-diam.
- **Acceptance criteria**:
  - [ ] Kalau dua staff mengubah kepemilikan percakapan yang sama pada saat yang hampir bersamaan, tepat satu perubahan diterima; kepemilikan akhir hanya mencerminkan satu staff.
  - [ ] Permintaan yang kalah ditolak, tidak menimpa kepemilikan yang sudah sah, dan staff yang kalah diberi tahu nama pemilik sah saat itu.
  - [ ] Percobaan berulang akibat dobel-klik atau koneksi terputus tidak menghasilkan dua pemilik sekaligus — aksi yang sama tidak diterapkan dua kali.
  - [ ] Bentrok hanya terdeteksi saat perubahan disimpan; membuka atau melihat percakapan tidak pernah menghasilkan penolakan (tidak ada Presence di Fase 2a).
  - [ ] Setelah bentrok, percakapan tetap bisa dipakai normal: staff yang kalah bisa memuat ulang ke kondisi terkini, mencoba lagi, atau memilih tindakan lain.

### 10.8. Percakapan baru terbagi otomatis antar staff (Auto-assignment — Fase 2b, inkremen berikutnya)

- **ID**: GH-008
- **Story**: Sebagai pemilik toko, saya ingin percakapan baru diarahkan otomatis kepada staff yang tepat, supaya tidak ada percakapan yang menunggu terlalu lama hanya karena belum ada yang mengambilnya. Fitur ini belum berlaku di Fase 2a: aturan pembagian bebannya belum ditetapkan, sehingga Auto-assignment direncanakan untuk inkremen Fase 2b (lihat Section 2.3 dan Section 4).
- **Acceptance criteria**:
  - [ ] Percakapan baru memiliki pemilik tanpa aksi manual staff, dan pemiliknya sudah tercatat saat percakapan itu pertama kali muncul di Queue View.
  - [ ] Aturan penentuan penerima — ukuran beban kerja yang dipakai, definisi "sedang bertugas", dan urutan prioritas antar staff — sudah ditetapkan tertulis sebelum fitur ini dikerjakan.
  - [ ] Auto-assignment tidak pernah mengubah kepemilikan percakapan yang sudah dimiliki staff (tidak ada penimpaan otomatis).
  - [ ] Percakapan yang diberikan otomatis tetap mengikuti aturan Handoff dan Collision Detection yang sudah berlaku di Fase 2a.

> [!NOTE]
> Tiga kriteria pertama **belum dapat diverifikasi** pada Fase 2a karena Auto-assignment sengaja dikeluarkan dari lingkup inkremen ini. Kriteria tersebut dicatat sebagai definisi tujuan, bukan sebagai komitmen pengujian Fase 2a.
