# PRD: Chat / WhatsApp Inbox — AuliaPos (v2.2/v2.3)

## 1. Product overview

### 1.1 Document title and version

- PRD: Chat / WhatsApp Inbox — AuliaPos
- Version: 1.4 (retroactive — v1.0 ditulis setelah Spec dan Plan sebagian sudah ada dan sebagian sudah dikoding; v1.1 adalah amandemen yang memasukkan lingkup M3 Fase 2a; v1.2 menyinkronkan status dan istilah dengan Spec; v1.3 menambah kebutuhan pencarian menyeluruh; v1.4 menyinkronkan status Fase 1c/1d/2a)

**Riwayat amandemen:**

| Versi | Tanggal | Perubahan | Sumber |
| --- | --- | --- | --- |
| 1.0 | 2026-09-22 | PRD retroaktif awal: 5 user story (GH-001 s.d. GH-005) untuk M1 Gelombang 1 dan M3 Fase 1a/1b | — |
| 1.1 | 2026-09-22 | Fase 2 dikeluarkan dari Section 2.3 Non-goals; user story baru GH-006 (Handoff), GH-007 (Collision Detection), GH-008 (Auto-assignment, inkremen Fase 2b); gate M2 dicatat sebagai *constraint*; item yang ditunda dinamai (Presence, notifikasi/unread); Section 9.2 disinkronkan | `docs/audit/clarification-report-m3-fase2-m2-gate-2026-09-22.md` (K-02, K-03, K-04, K-08) |
| 1.2 | 2026-09-24 | Section 9.2 disinkronkan dengan status nyata M3 Fase 1a/1b/1c dan Fase 2a (ST-03); kriteria GH-001 s.d. GH-004 dicentang; istilah "catatan internal" → **Internal Note** dan "indikator prioritas" → **SLA Timer** sesuai glosarium Spec §2. Tidak ada perubahan lingkup atau perilaku. | `docs/audit/consistency-audit-m3-fase1-operational-inbox-2026-09-24.md` (Iterasi 2, REFINE langkah 2) |
| 1.3 | 2026-09-24 | Kebutuhan baru: pencarian menyeluruh dalam dua tahap. Fase 1d: nama/nomor yang dicari mencakup semua nama dan nomor yang tampil di daftar, termasuk nama profil WhatsApp (menutup NG-01 / TODO-SEARCH-01), GH-009. Fase 1e: pencarian isi pesan (pesan pelanggan, balasan staff, Internal Note) dengan potongan pesan yang cocok di hasil, GH-010. Section 2.2, 2.3, 4, 5, 8.3, 9.2 dan 10 diperbarui. | Diskusi dengan pemilik proyek 2026-09-24 (kasus: nota atas nama "Saerah", kontak tersimpan "Jamet") |
| 1.4 | 2026-09-24 | Section 9.2 disinkronkan dengan status nyata (ST-04): Fase 1c — TODO-SEARCH-01 ditutup di Fase 1d; Fase 1d — Spec, Plan, Kode, dan code review selesai; Fase 2a — kedua plan sudah `Completed`. Kriteria GH-009 dicentang. Tidak ada perubahan lingkup atau perilaku. | `docs/audit/consistency-audit-m3-fase1-operational-inbox-2026-09-24.md` (Iterasi 3, ST-04) |

### 1.2 Product summary

AuliaPos saat ini punya modul Inbox WhatsApp yang berfungsi sebagai viewer chat sederhana: semua pesan masuk dari WhatsApp Gateway (repo terpisah, `tikusgot007/WA-Gateway`, berbasis Node.js/Baileys) ditampilkan dalam satu daftar tanpa status, penanda keterlambatan, atau catatan antar staff. Kasir kesulitan tahu percakapan mana yang belum dibalas, mana yang sedang ditangani siapa, dan tidak ada tempat mencatat konteks tanpa mengirim pesan ke pelanggan.

Produk ini punya tiga sisi yang saling melengkapi:

- **M1 — Keandalan Gateway**: memastikan pesan pelanggan yang masuk ke WhatsApp Gateway tidak hilang saat Gateway sedang mati/bermasalah, dan kegagalan penyimpanan tidak lagi senyap.
- **M3 Fase 1 — Operational Inbox**: mengubah tampilan Inbox AuliaPos dari daftar chat polos menjadi ruang kerja kasir — ada antrean bertab (Belum Diambil, Open, Menunggu, Ditunda, Selesai), SLA Timer (indikator warna untuk percakapan yang lama tidak dibalas), dan Internal Note antar staff.
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
- Kasir bisa menulis Internal Note (tidak terkirim ke pelanggan) untuk konteks — misalnya "customer minta di-follow-up besok" — tanpa mengubah status percakapan.
- Kasir bisa menemukan percakapan lama dari nama atau kata apa pun yang pernah muncul — di nama/nomor yang tampil di daftar maupun di isi chat — misalnya untuk menghubungi pemesan setelah order selesai, walau nama di nota ("Saerah") berbeda dengan kontak yang tersimpan ("Jamet", pegawainya).
- Kasir bisa melihat SLA Timer (indikator warna) kalau ada percakapan yang sudah lama tidak dibalas, supaya tidak lupa.
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
- **Pencarian berdasarkan data nota/transaksi POS** (mis. nama pemesan yang hanya tercatat di nota, tidak pernah disebut di chat) — ditunda ke M4 bersama Customer Context. Pencarian Fase 1d/1e hanya memakai data Inbox.
- **Loncat langsung ke pesan yang cocok di dalam percakapan** (pesan ditandai/di-scroll otomatis) — ditunda; Fase 1e cukup menampilkan potongan pesan yang cocok di daftar hasil. Baru dipertimbangkan bila terbukti kasir kesulitan menemukan pesannya setelah membuka percakapan.
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

- **Kasir**: bisa membalas, mengambil/melepas percakapan, snooze, dan menulis Internal Note ke percakapan mana pun — termasuk yang di-assign ke staff lain (khusus Internal Note, lihat SEC-001 di spec M3). Aksi balas/hapus/snooze tetap dibatasi kepemilikan (`cekOwnership()`), Internal Note sengaja tidak dibatasi.
- **Admin**: akses sama seperti kasir untuk modul Inbox di Fase 1 — belum ada hak admin-khusus (mis. reassign paksa) di lingkup PRD ini.
- **Handoff (Fase 2a)**: hanya staff yang sedang memegang percakapan yang boleh menyerahkannya; pengecualian berlaku untuk percakapan di tab "Belum Diambil" yang boleh diserahkan oleh staff mana pun. Target wajib akun staff aktif yang lain (bukan diri sendiri) dan boleh sedang offline. Belum ada jalur handoff paksa oleh admin di lingkup PRD ini.

## 4. Functional requirements

- **Queue View (Priority: Must-have, M3 Fase 1a)**
  - Menampilkan 5 tab: Belum Diambil, Open, Menunggu, Ditunda, Selesai — masing-masing hasil status yang dihitung dari data yang sudah ada, bukan kolom status baru di database.
- **Conversation Detail (Priority: Must-have, M3 Fase 1a)**
  - Menampilkan thread pesan satu percakapan + tombol aksi (Balas, Ambil/Lepas, Snooze, Selesai), memakai endpoint yang sudah ada.
- **Snooze percakapan (Priority: Must-have, M3 Fase 1a; alasan snooze Fase 1b)**
  - Kasir bisa menunda percakapan dengan durasi tertentu (menit). Field alasan snooze ditambahkan belakangan (Fase 1b), disimpan sebagai Internal Note, bukan kolom baru.
- **Internal Note (Priority: Must-have, M3 Fase 1b; tombol mandiri di layar Fase 1c)**
  - Kasir bisa menulis catatan yang hanya terlihat oleh staff, tidak pernah terkirim ke WhatsApp pelanggan, dan tidak mengubah status percakapan maupun warna SLA Timer-nya.
- **SLA Timer — indikator warna (Priority: Should-have, M3 Fase 1b; tampil di daftar Fase 1c)**
  - Percakapan yang lama tidak dibalas (berdasarkan waktu pesan terakhir) diberi warna hijau/kuning/merah, kecuali yang sudah selesai atau sedang ditunda.
- **Filter & Pencarian (Priority: Should-have, M3 Fase 1b; kotak pencarian di layar Fase 1c)**
  - Kasir bisa memfilter daftar percakapan berdasarkan tab status dan mencari berdasarkan nama/nomor pelanggan.
- **Pencarian nama/nomor lengkap (Priority: Must-have, M3 Fase 1d)**
  - Pencarian nama/nomor mencakup **semua** nama dan nomor yang tampil di daftar percakapan — nama kontak yang disimpan, nama profil WhatsApp, nomor yang disimpan, dan nomor WhatsApp — sehingga percakapan yang namanya terlihat di layar selalu bisa ditemukan dengan nama itu (lihat GH-009).
- **Pencarian isi pesan (Priority: Should-have, M3 Fase 1e)**
  - Kata kunci yang sama juga dicari di isi pesan: pesan pelanggan, balasan staff, dan Internal Note, di seluruh riwayat (bukan hanya percakapan terbaru).
  - Percakapan yang cocok karena isi pesannya menampilkan potongan pesan yang cocok di bawah nama, supaya kasir tahu kenapa percakapan itu muncul (lihat GH-010).
  - Aturan pencarian yang sudah berlaku tetap sama: berlaku bersamaan dengan tab status, seluruh riwayat ikut dicari, hasil ditampilkan bertahap per halaman.
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

- **Lihat antrean**: Kasir membuka Inbox, melihat daftar percakapan terbagi per tab status, dengan SLA Timer (indikator warna) untuk yang sudah lama tidak dibalas.
- **Ambil percakapan**: Kasir klik percakapan di tab "Belum Diambil", langsung berpindah ke "Open" atas namanya.
- **Balas & tangani**: Kasir membalas dari Conversation Detail, menulis Internal Note bila perlu (tidak terkirim ke pelanggan), lalu menandai Selesai atau Snooze bila belum bisa dibalas tuntas.
- **Cari percakapan lama**: Kasir memakai filter/pencarian untuk menemukan percakapan lama berdasarkan nama/nomor yang tampil di daftar (Fase 1d) atau kata yang pernah muncul di isi chat (Fase 1e) — mis. mengetik "Saerah" untuk menemukan chat dari kontak "Jamet" yang memesan atas nama Saerah.
- **Serahkan percakapan (handoff)**: Kasir yang mengakhiri shift menyerahkan percakapan yang masih berjalan ke kasir shift berikutnya — mengisi ringkasan keadaan terakhir dan tindakan lanjutan yang diharapkan — supaya tanggung jawab berpindah tanpa konteks yang hilang.

### 5.3 UI/UX highlights & Edge cases

- Percakapan yang di-snooze tidak diberi warna SLA Timer merah/kuning — karena memang sengaja ditunda, bukan terlambat.
- Internal Note boleh ditulis kasir mana pun (bukan cuma yang meng-assign dirinya ke percakapan itu), termasuk pada percakapan yang sudah "Selesai".
- Percakapan baru yang belum ada indikasi arah pesan tetap harus muncul dengan status yang masuk akal (fallback ke Belum Diambil/Open), bukan error atau kosong.
- Kalau dua kasir menyimpan perubahan kepemilikan pada percakapan yang sama pada saat yang hampir bersamaan, kasir yang kalah tidak boleh melihat tampilan yang menyesatkan: layar menampilkan nama pemilik sah yang terbaru beserta penjelasan singkat, dan percakapan dimuat ulang ke kondisi terkini.
- Percakapan yang sedang ditunda lalu di-handoff tetap tampil di tab "Ditunda" atas nama penerima baru, tanpa warna SLA Timer.
- Hasil pencarian isi pesan (Fase 1e): kalau yang cocok adalah Internal Note, potongannya diberi label "Internal", supaya kasir tidak mengira itu tulisan pelanggan. Kalau beberapa pesan dalam satu percakapan cocok, percakapan tetap muncul **satu kali** dengan satu potongan pesan (yang paling baru). Kalau yang cocok nama/nomornya, tidak perlu potongan pesan.

## 6. Narrative

Seorang kasir membuka AuliaPos di pagi hari dan langsung tahu ada 4 percakapan yang belum dijawab semalam (tab "Belum Diambil"), 2 di antaranya sudah berwarna kuning karena menunggu lebih dari 15 menit. Ia mengambil satu percakapan, membalasnya, lalu menulis Internal Note "customer tanya diskon reseller, tunggu konfirmasi owner" supaya kasir shift berikutnya tahu konteksnya tanpa perlu membaca ulang seluruh chat. Percakapan lain ia snooze 2 jam karena pelanggan bilang akan konfirmasi nanti siang. Di belakang layar, seluruh pesan yang sempat masuk semalam saat toko tutup dan Gateway sempat restart tetap tersimpan lengkap — tidak ada yang hilang.
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
- `app/Controllers/Inbox.php` dan `ConversationModel` di AuliaPos — sisi M3, sebagian besar backend sudah ada, gap yang ditutup: computed status terpadu, endpoint Internal Note, perluasan filter.

### 8.2 Data storage & privacy

- Database Inbox (`aulia_inboxdb`, koneksi `inbox`) terpisah dari database POS (`aulia_kasirdb`).
- Internal Note disimpan sebagai baris pesan biasa dengan penanda `is_internal`, dan secara sengaja **tidak pernah** dikirim ke Gateway/WhatsApp.
- Riwayat Handoff disimpan di database Inbox yang sama dengan percakapan; riwayat ini tidak menambah data pribadi pelanggan di luar yang sudah tersimpan, dan tidak ada penghapusan riwayat pada Fase 2a.

### 8.3 Scalability & potential technical challenges

- Perhitungan status antrean dan warna SLA Timer dilakukan real-time dari data yang ada (bukan kolom status tersimpan) — pendekatan ini dipilih supaya tidak ada duplikasi logic status yang bisa saling tidak sinkron (lihat ADR-0001).
- Keandalan Gateway (M1) adalah fondasi terpisah — kegagalannya tidak memblokir pengembangan M3, tapi tanpa M1, data yang ditampilkan Queue View bisa saja tidak lengkap.
- Gate M2 sebagai *constraint* (K-01): atomicitas kepemilikan dibuka sempit hanya pada jalur Handoff — penulisan hanya berhasil bila pemilik percakapan masih sama dengan yang dibaca, dan kegagalannya ditolak **tanpa** menimpa kepemilikan. M2 tetap deferred dan tidak boleh melebar menjadi perombakan state consistency (`docs/ARCHITECTURE.md` Section 12).
- Jalur kepemilikan lain yang masih read-then-write (lepas, selesai, snooze, tandai dibaca, hapus) secara sadar tidak diperbaiki di Fase 2a; perbaikan menyeluruhnya menunggu M2.
- Handoff tidak mengirim pesan apa pun ke pelanggan dan tidak menambah panggilan baru ke WhatsApp Gateway.
- Pencarian isi pesan (Fase 1e) menyentuh data yang jauh lebih banyak daripada daftar percakapan (setiap percakapan berisi banyak pesan). Kecepatan pencarian perlu dirancang di Spec supaya layar tetap terasa cepat seiring riwayat chat bertambah; target perilakunya ada di GH-010.

## 9. Milestones & sequencing

### 9.1 Project estimate & Team composition

- Dikerjakan bertahap oleh tim kecil (developer tunggal dibantu AI pairing), tanpa estimasi waktu formal — mengikuti alur SDLC proyek per fase.

### 9.2 Suggested phases

- **M1 Gelombang 1** (repo WA-Gateway): keandalan pesan masuk — Spec ✅ selesai, Plan ✅ selesai, Kode 🔄 sedang berjalan (Fase 1–3 dari 3 fase sudah dikoding & diuji simulasi, menunggu pengujian nyata TASK-017/018).
- **M3 Fase 1a** (AuliaPos, tanpa migration): Queue View, Conversation Detail dasar, Snooze tanpa alasan — Spec ✅, Plan ✅, Kode ✅ selesai dan sudah digabung ke branch `v2.3` (PR #41).
- **M3 Fase 1b** (AuliaPos, dengan migration baru): sisi server untuk Internal Note, SLA Timer, alasan Snooze, Filter & Pencarian — Spec ✅, Plan ✅, Kode ✅ selesai dan sudah digabung ke `v2.3` (PR #41).
- **M3 Fase 1c** (AuliaPos, tanpa migration): sisi layar yang tertinggal dari Fase 1b — tombol Internal Note mandiri, titik warna SLA Timer di daftar, kotak pencarian nama/nomor — Spec ✅ (rev 1.1, AC-010..AC-012), Plan ✅ (Phase 3 + plan refactor Fase 1c, keduanya `Completed`), Kode ✅ selesai, diuji (317/317 test, cek manual browser 8/8), code review: layak digabung. Sisa kecil (pencarian belum mencakup nama profil WhatsApp, TODO-SEARCH-01) ✅ sudah ditutup di Fase 1d.
- **M3 Fase 1d** (AuliaPos, tanpa migration): pencarian nama/nomor lengkap — semua nama/nomor yang tampil di daftar ikut dicari (GH-009, menutup NG-01 / TODO-SEARCH-01) — PRD ✅ v1.3, Spec ✅ rev 1.2 (REQ-013, AC-013), Plan ✅ rev 1.2 (Phase 4, TASK-020..022, `Completed`), Kode ✅ selesai (`db7f301`), diuji (324/324 test, cek manual browser TASK-021 c), code review: layak digabung.
- **M3 Fase 1e** (AuliaPos): pencarian isi pesan + potongan pesan yang cocok di hasil (GH-010) — PRD ✅ v1.3, Spec ✅ v1.3 (termasuk rancangan kecepatan pencarian; angka uji ≤ 3 detik masih ditandai "perlu dikonfirmasi", ASSUMPTION-004), Plan belum, Kode belum. Dikerjakan setelah Fase 1d.
- **M3 Fase 2a** (AuliaPos, dengan migration baru): Handoff antar staff + Collision Detection — PRD ✅ v1.1, Spec ✅ v1.2, Plan ✅ rev 1.1 (plan fitur dan plan refactor Fase 2a, keduanya `Completed`), Kode ✅ sudah dikoding, diuji, dan digabung ke `v2.3` (PR #41), code review ✅ (0 Blocker, 0 Critical; perbaikan kecil lewat plan refactor Fase 2a).
- **M3 Fase 2b** (inkremen berikutnya): Auto-assignment (GH-008) — belum dijadwalkan; menunggu aturan pembagian beban kerja ditetapkan lebih dulu.
- **M2, M4, M5** — di luar scope PRD ini sebagai program, roadmap jangka panjang (State Consistency, Customer Context penuh, fitur AI). Catatan: *constraint* K-01 di Section 2.3 membuka jalur sempit agar Fase 2a bisa berjalan tanpa menutup M2 sebagai program.

## 10. User stories & Acceptance Criteria

### 10.1. Melihat antrean percakapan berdasarkan status

- **ID**: GH-001
- **Story**: Sebagai kasir, saya ingin melihat percakapan terbagi dalam tab status (Belum Diambil, Open, Menunggu, Ditunda, Selesai), supaya saya tahu mana yang harus segera saya tangani.
- **Acceptance criteria**:
  - [x] Percakapan yang belum di-assign siapa pun dan belum dibalas muncul di tab "Belum Diambil".
  - [x] Percakapan yang sudah di-assign ke kasir tertentu dan belum dibalas muncul di tab "Open".
  - [x] Percakapan yang menunggu balasan pelanggan muncul di tab "Menunggu".
  - [x] Percakapan yang di-snooze muncul di tab "Ditunda".
  - [x] Percakapan yang sudah selesai muncul di tab "Selesai".

### 10.2. Menulis Internal Note tanpa mengganggu status percakapan

- **ID**: GH-002
- **Story**: Sebagai kasir, saya ingin menulis Internal Note pada sebuah percakapan, supaya kasir lain tahu konteksnya tanpa saya harus mengirim pesan ke pelanggan.
- **Acceptance criteria**:
  - [x] Internal Note tidak pernah terkirim ke WhatsApp pelanggan.
  - [x] Menulis Internal Note tidak mengubah tab status percakapan (mis. tetap di "Menunggu", tidak berpindah ke "Open").
  - [x] Kasir mana pun bisa menulis Internal Note ke percakapan mana pun, termasuk yang di-assign ke kasir lain atau yang sudah "Selesai".

### 10.3. Menunda (snooze) percakapan dengan alasan

- **ID**: GH-003
- **Story**: Sebagai kasir, saya ingin menunda percakapan yang belum perlu dibalas sekarang dan mencatat alasannya, supaya saya ingat konteksnya saat kembali menanganinya nanti.
- **Acceptance criteria**:
  - [x] Kasir bisa menunda percakapan dengan memilih durasi (menit).
  - [x] Kasir bisa mengisi alasan penundaan, yang tersimpan sebagai Internal Note.
  - [x] Percakapan yang ditunda tidak diberi warna SLA Timer merah/kuning.

### 10.4. Melihat SLA Timer berdasarkan lama tidak dibalas

- **ID**: GH-004
- **Story**: Sebagai kasir, saya ingin melihat SLA Timer (indikator warna) pada percakapan yang sudah lama tidak dibalas, supaya saya tidak lupa menanganinya.
- **Acceptance criteria**:
  - [x] Percakapan dengan pesan terakhir kurang dari 15 menit lalu berwarna hijau (atau tanpa warna).
  - [x] Percakapan dengan pesan terakhir 15–60 menit lalu berwarna kuning.
  - [x] Percakapan dengan pesan terakhir lebih dari 60 menit lalu berwarna merah.
  - [x] Percakapan berstatus "Selesai" atau "Ditunda" tidak diberi warna SLA Timer.

> [!NOTE]
> GH-001 s.d. GH-004 dicentang per v1.2 (2026-09-24) berdasarkan bukti di `docs/audit/consistency-audit-m3-fase1-operational-inbox-2026-09-24.md` Iterasi 2: Spec AC-001..AC-012, plan Fase 1 dan plan refactor Fase 1c `Completed`, 317/317 test lolos, dan cek manual browser 8/8.

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

### 10.9. Menemukan percakapan dari nama/nomor yang terlihat di layar (Fase 1d)

- **ID**: GH-009
- **Story**: Sebagai kasir, saya ingin setiap nama atau nomor yang tampil di daftar percakapan bisa dipakai untuk mencari, supaya percakapan yang namanya saya lihat di layar selalu bisa saya temukan kembali.
- **Acceptance criteria**:
  - [x] Percakapan tanpa nama kontak tersimpan yang tampil di daftar dengan nama profil WhatsApp-nya (mis. "Budi Cetak") ditemukan saat kasir mencari "budi cetak".
  - [x] Percakapan ditemukan lewat nama kontak yang disimpan, nama profil WhatsApp, nomor yang disimpan, maupun nomor WhatsApp-nya.
  - [x] Pencarian tidak membedakan huruf besar/kecil dan mencari di seluruh riwayat percakapan, termasuk yang sudah "Selesai" dan yang lama.
  - [x] Pencarian tetap berlaku bersamaan dengan tab status, dan angka di tiap tab mengikuti hasil pencarian (perilaku Fase 1c tetap).

> [!NOTE]
> GH-009 dicentang per v1.4 (2026-09-24) berdasarkan bukti di `docs/audit/consistency-audit-m3-fase1-operational-inbox-2026-09-24.md` Iterasi 3: Spec AC-013 (a)–(h), plan Fase 1 rev 1.2 Phase 4 `Completed`, kode `db7f301`, 324/324 test lolos, dan cek manual browser TASK-021 (c).

### 10.10. Menemukan percakapan dari isi chat (Fase 1e)

- **ID**: GH-010
- **Story**: Sebagai kasir, saya ingin mencari kata yang pernah muncul di isi chat, supaya saya bisa menemukan dan menghubungi pemesan setelah order selesai walau nama di nota berbeda dengan kontak yang tersimpan (mis. nota atas nama "Saerah", kontak tersimpan "Jamet").
- **Acceptance criteria**:
  - [ ] Given kontak tersimpan "Jamet" pernah menulis "pesan atas nama Saerah" di chat, When kasir mencari "saerah", Then percakapan Jamet muncul di hasil.
  - [ ] Isi yang ikut dicari: pesan pelanggan, balasan staff, dan Internal Note, di seluruh riwayat (termasuk percakapan "Selesai" dan pesan lama).
  - [ ] Percakapan yang cocok karena isi pesannya menampilkan potongan pesan yang cocok di bawah nama; bila yang cocok adalah Internal Note, potongannya berlabel "Internal".
  - [ ] Satu percakapan hanya muncul satu kali walau beberapa pesannya cocok, dengan potongan dari pesan cocok yang paling baru.
  - [ ] Pencarian isi pesan tidak mengirim apa pun ke pelanggan/WhatsApp Gateway dan tidak mengubah status, pemilik, maupun warna SLA Timer percakapan.
  - [ ] Hasil pencarian tampil dalam waktu ≤ 3 detik pada data chat toko saat ini, dan layar tidak macet selama menunggu hasil.
