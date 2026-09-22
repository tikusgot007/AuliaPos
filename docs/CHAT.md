# AuliaPos Chat / Shared WhatsApp Inbox

Single source of truth untuk modul Chat/Inbox WhatsApp — kasir/admin membalas customer langsung dari AuliaPos, terhubung ke Gateway WhatsApp terpisah (Node.js/Baileys) di LAN toko.

Riwayat implementasi per fase ada di `CHANGELOG.md`. Dokumen historis lengkap (section number yang dikutip komentar kode) ada di `archive/`. Definisi Priority/Shift Leader (kalau/ketika dipakai fitur Chat) — lihat `USER-SHIFT.md`, tidak didefinisikan ulang di sini.

---

## Daftar Isi

1. [Scope](#1-scope)
2. [Architecture](#2-architecture)
3. [Conversation](#3-conversation)
4. [Incoming Messages](#4-incoming-messages)
5. [Outgoing Messages](#5-outgoing-messages)
6. [Media](#6-media)
7. [WhatsApp Web / HP Synchronization](#7-whatsapp-web--hp-synchronization)
8. [Assignment & Ownership](#8-assignment--ownership)
9. [Identity & Conversation Reconciliation](#9-identity--conversation-reconciliation)
10. [Customer Profile](#10-customer-profile)
11. [Conversation Lifecycle](#11-conversation-lifecycle)
12. [Unread / Read](#12-unread--read)
13. [Permissions](#13-permissions)
14. [Gateway](#14-gateway)
15. [New Chat & Phone Normalization](#15-new-chat--phone-normalization)
16. [Delete Conversation](#16-delete-conversation)
17. [Known Implementation Gaps](#17-known-implementation-gaps)
18. [Developer Invariants](#18-developer-invariants)
19. [Response State](#19-response-state)
20. [Window Standalone](#20-window-standalone)

---

## 1. Scope
`Historical source: [CHAT §1.2]`

Modul ini menangani **teks + media WhatsApp masuk/keluar** lewat satu Gateway per toko, dengan assignment/ownership per-conversation, reconciliation identitas customer, dan lifecycle Open/Closed. **Tidak mencakup**: aturan bisnis AuliaPos inti (transaksi/kasir/jadwal — lihat `AULIA.md`), atau definisi Priority/Shift Leader (`USER-SHIFT.md` — kewenangan Shift Leader untuk Chat belum ditentukan, lihat Section 17).

---

## 2. Architecture
`Historical source: [CHAT §1.2, §2, §3.1]`

- **Database benar-benar terpisah**: `aulia_inboxdb`, database baru — bukan tabel tambahan di `aulia_kasirdb`. Koneksi default AuliaPos (`$default`) tidak disentuh sama sekali.
- **Tidak ada tabel `users` baru.** Identitas kasir tetap dari `aulia_kasirdb.users`. Kolom `assigned_to`, `last_replied_by`, `closed_by` (di `conversations`) dan `sent_by_user_id` (di `messages`) adalah **logical reference** ke `users.id` — BUKAN foreign key database sungguhan (MySQL tidak aman untuk FK lintas database). Nama user diambil lewat `UserModel` (koneksi default) secara terpisah, digabungkan di controller/view — tidak bisa di-`JOIN` langsung.
- **Gateway TIDAK menyimpan business state.** SQLite di sisi Gateway murni reliability buffer untuk retry pengiriman event, bukan sumber kebenaran.
- **Autentikasi dua arah, satu shared secret.** Gateway→CI4 (pesan masuk, heartbeat) dan CI4→Gateway (kirim balasan) **memakai token yang sama persis** (`inbox.gatewayToken` di CI4 = token Gateway) — satu shared secret dipakai kedua arah, keputusan desain sengaja untuk kesederhanaan.
- **Filter token Gateway fail-CLOSED.** Kalau token belum dikonfigurasi sama sekali di `.env` server, **SEMUA** request ke endpoint Gateway ditolak (bukan fail-open/dilewatkan).

---

## 3. Conversation
`Historical source: [CHAT §1.2, §11.2]`

Satu customer (satu nomor/JID WhatsApp yang sudah direkonsiliasi, lihat Section 9) = satu baris `conversations`. Field kuncinya lintas seluruh dokumen ini: `chat_id` (JID WhatsApp aktif — bisa berubah lewat reconciliation, tapi **hanya pernah dimutakhirkan ke JID lain**, tidak pernah diganti jadi nomor telepon polos), `status` (Open/Closed, Section 11), `assigned_to` (Section 8), `phone`/`manual_phone`/`whatsapp_name`/`contact_name` (Section 10), `last_message_at`/`last_message_direction` (Section 4/5).

Empat "dimensi" berikut berlaku independen pada satu conversation dan tidak boleh saling mempengaruhi secara tidak sengaja kecuali interaksi yang eksplisit diatur di bagian masing-masing: **identitas** (Section 9 — "conversation mana", ditentukan sekali di titik pencarian/pembuatan), **lifecycle** Open/Closed (Section 11), **assignment** (Section 8), dan **Read/Unread** (Section 12).

---

## 4. Incoming Messages
`Historical source: [CHAT §2.1, §6.1]`

Customer kirim WA → Gateway (Baileys) terima → CI4 simpan ke `aulia_inboxdb` → conversation ditandai `status='open'` kembali (apa pun status sebelumnya, lihat Section 11) → `last_message_at`/`last_message_direction` diperbarui. **Idempotent** berdasarkan `wa_message_id`.

`last_message_at` diisi dari waktu pesan itu sendiri terjadi di WhatsApp (`message_timestamp` payload), **bukan** waktu server CI4 menerima request — supaya urutan percakapan tetap merefleksikan kapan pesan benar-benar terjadi walau Gateway sempat retry lama. Timestamp selalu diinterpretasi eksplisit sebagai `Asia/Jakarta` (`new DateTimeZone('Asia/Jakarta')` di titik parsing) — **tidak pernah** bergantung pada setting timezone default PHP server.

**Update Status WhatsApp (Stories) difilter di titik paling awal** — JID untuk Status selalu literal `status@broadcast`; pesan dengan JID ini tidak pernah diproses/di-log sama sekali, tidak muncul di Inbox sebagai percakapan.

**Incoming tidak pernah mengganti `assigned_to`** — lihat Section 8.

---

## 5. Outgoing Messages
`Historical source: [CHAT §2.2]`

Browser POS → CI4 → cek Gateway usable (Section 14) → kirim ke Gateway → Gateway kirim ke WhatsApp → CI4 **baru** menyimpan sebagai `outgoing`/`sent` kalau Gateway konfirmasi sukses.

**Keputusan penting soal kegagalan kirim:** kalau Gateway gagal/menolak di titik manapun, **TIDAK ADA APA PUN** yang disimpan ke `aulia_inboxdb` — bukan cuma "tidak ditandai sent", tapi memang tidak ada baris `messages` baru sama sekali. Kasir bisa coba klik kirim lagi (**retry manual oleh manusia**, bukan retry otomatis sistem — **tidak boleh ada outgoing queue**).

Endpoint kirim (`Inbox::kirim()`) menerima **`conversation_id`** (primary key tabel `conversations`), bukan `chat_id` (JID WhatsApp mentah) — controller yang menerjemahkan `conversation_id → chat_id` sebelum memanggil Gateway.

---

## 6. Media
`Historical source: [CHAT §7, §10]`

### 6.1 Gambar & Dokumen
**Keputusan dasar:** yang disimpan di `aulia_inboxdb` adalah **REFERENSI** (`direct_path` + `media_key` WhatsApp); file aslinya diambil & didekripsi **ON-DEMAND** dari server WhatsApp lewat Gateway. Gateway sendiri tidak pernah menyimpan file. **Sejak Tahap C**, AuliaPos boleh menyimpan salinan gambar/dokumen di disk lokal (Section 6.3) — prinsip lama "tidak pernah disimpan permanen" sudah dicabut untuk sisi AuliaPos, tetap berlaku untuk Gateway dan untuk audio/video (Section 6.2).

**Kalau referensi media tidak lengkap (`direct_path`/`media_key` gagal diekstrak), pesan masuk DIBUANG** — tidak diteruskan ke CI4 sama sekali (beda dari audio/video, lihat 6.2). Konsekuensi yang disadari & diterima: WhatsApp tidak menjamin media tersimpan selamanya di server mereka — untuk pesan lama, referensi bisa "basi" dan file tidak bisa diambil lagi (placeholder "tidak tersedia", bukan bug).

Alur: Customer kirim → Gateway ekstrak referensi (bukan isi file) → simpan referensi ke buffer → CI4 simpan referensi ke `messages.media_metadata` → kasir buka `/inbox` → browser minta unduh → Gateway ambil & dekripsi dari server WhatsApp → di-stream langsung ke browser (Gateway tidak menyimpan; salinan disk di CI4 hanya kalau Section 6.3 aktif).

Tampilan: gambar `inline` (`<img>`), dokumen `attachment` (download langsung, ikon+nama file). **Caption (kalau ada) ditampilkan** di bawah gambar/link dokumen, untuk keduanya.

**Outgoing (kasir kirim media dari POS):** pola referensi yang sama — setelah Gateway berhasil upload file ke WhatsApp, hasil upload berisi `directPath`/`mediaKey` untuk file baru, direferensikan dengan cara sama seperti incoming (endpoint buka-ulang media otomatis berfungsi juga untuk media keluar). File yang diupload kasir dibaca ke memory dan dikirim ke Gateway (jalur kirim tidak menulis ke disk CI4). Batas ukuran CI4: `Config\Inbox::$maxMediaUploadMb` (default **15MB**) — **sengaja lebih kecil** dari batas Gateway sendiri (default 20MB) supaya CI4 yang menolak lebih dulu dengan pesan jelas, bukan Gateway yang menolak belakangan.

Kalau Gateway tidak mengembalikan referensi media (kasus jarang) untuk pesan **keluar**, pesan tetap tersimpan sebagai terkirim (sudah sampai ke WhatsApp) — hanya tidak bisa dibuka ulang nanti dari Inbox; ini bukan kegagalan kirim, hanya keterbatasan tampil ulang.

### 6.2 Audio & Video
Berlaku untuk arah **masuk** (customer → toko) saja. Lokasi, kontak, dan audio/video **keluar** (kasir → customer) di luar scope. **Sticker** sudah didukung (masuk & keluar) sejak port ke v2.2 dan diperlakukan seperti gambar (Section 6.1/6.3).

**Beda prinsip dari 6.1:** audio/video **TIDAK** memakai pola referensi-untuk-didekripsi-ulang. Binary-nya **tidak pernah diambil**, baik oleh Gateway maupun AuliaPos. Yang disimpan hanya metadata pesan (`message_type`, caption/`text` — **audio TIDAK PERNAH punya caption di WhatsApp, `text` selalu `null` untuk audio; video BOLEH punya caption**, mime type, ukuran, timestamp) — `media_path`/`media_metadata` **selalu NULL**.

**Kalau `mimetype`/`fileLength` kosong, pesan TETAP diteruskan** (tidak di-drop) — beda dari gambar/dokumen di 6.1 yang justru dibuang kalau referensinya tidak lengkap, karena audio/video tidak punya syarat referensi apa pun untuk berguna nanti.

UI cukup menampilkan placeholder: *"Customer mengirim audio/video — cek WhatsApp Web."* Kasir yang perlu dengar/lihat isinya membuka langsung dari WhatsApp Web/HP toko. **Tidak ada** endpoint download media untuk audio/video.

**Voice note = audio, bukan tipe baru.** WhatsApp mengirim voice note sebagai pesan audio dengan flag `ptt: true` di level teknis — flag ini **tidak** diteruskan ke AuliaPos, tidak ada business logic yang bercabang berdasarkan itu; voice note diperlakukan identik dengan audio biasa.

### 6.3 Penyimpanan Lokal & Media Kadaluarsa (Tahap C & E)
**Penyimpanan lokal (Tahap C).** Kalau `inbox.mediaStoragePath` di `.env` terisi (mis. HDD eksternal), gambar/dokumen/sticker masuk di-*prefetch* saat `InboxGatewayApi::messages()` (timeout 8 detik) dan disimpan ke folder itu. `Inbox::media()` mengecek disk lokal **lebih dulu** sebelum ETag/live-fetch ke Gateway. Kolom: `messages.media_local_filename`, `media_download_attempted_at` (artinya "prefetch sudah dicoba" — boleh diulang kalau gagal sementara). **Fail-safe:** kalau `mediaStoragePath` kosong, perilaku kembali ke live-fetch lama. Belum ada kebijakan retensi/pembersihan otomatis (sengaja, skala 1 toko).

**Media kadaluarsa (Tahap E).** Kalau WhatsApp membalas **410** (media sudah tidak tersedia), kegagalan itu final:
- Backend: `messages.media_confirmed_gone_at` diisi; `Inbox::media()` langsung membalas 410 tanpa menghubungi Gateway lagi (dicek setelah disk lokal, sebelum ETag). **Hanya 410** yang mengisi kolom ini — timeout/502 tidak, karena masih layak diulang. Kolom ini sengaja terpisah dari `media_download_attempted_at`.
- Frontend: `<img>` yang gagal (`onerror`) dicatat di `mediaGagal` (per sesi browser) sehingga polling 4 detik tidak membuat request ulang.
---

## 7. WhatsApp Web / HP Synchronization
`Historical source: [CHAT §5.3]`

Kalau staff membalas customer **langsung dari WhatsApp Web/HP** (bukan lewat POS), balasan itu tetap disinkronkan sebagai `direction='outgoing'` ke `aulia_inboxdb` — supaya kasir lain tidak salah kira belum dibalas dan berisiko membalas dobel.

Karena Baileys tidak punya info siapa yang login WA Web/pegang HP-nya, balasan ini disimpan **tanpa identitas staff spesifik** (`sent_by_user_id = NULL`), ditampilkan di UI dengan label **"Staff (WA Web/HP)"** untuk membedakan dari balasan yang dikirim lewat POS.

Balasan sinkron dari luar POS ini **tidak** memaksa `conversation.status='open'` (beda dari incoming customer yang selalu membuka kembali conversation) — balasan staff bukan keputusan yang seharusnya mengubah status conversation.

---

## 8. Assignment & Ownership
`Historical source: [CHAT §9, §13, Tahap 2 audit]`

Satu conversation bisa "ditangani" oleh satu staff, supaya jelas siapa yang bertanggung jawab dan tidak ada 2 kasir membalas bersamaan tanpa sadar.

### 8.1 Definisi
- **Unassigned** (`assigned_to = NULL`): default conversation baru. **Membuka/melihat conversation tidak pernah meng-assign** (read-only).
- **Ambil**: staff mengklaim conversation yang belum ada assignee-nya, atau yang sudah ada assignee-nya kalau ia admin (override/take-over). Staff non-admin yang mencoba mengambil conversation yang sudah ditangani orang lain ditolak, dengan pesan jelas siapa yang sedang menangani.
- **Lepas**: mengosongkan `assigned_to`, conversation kembali bebas diambil/dibalas siapa saja. Hanya boleh dilakukan oleh yang sedang menangani, atau admin.
- **Auto-assign**: begitu satu staff berhasil mengirim balasan (teks/media, terkonfirmasi sukses oleh Gateway) ke conversation yang `assigned_to`-nya masih `NULL`, conversation itu otomatis ter-assign ke staff tsb — tidak perlu klik apa pun dulu. Auto-assign **tidak pernah** menimpa assignment yang sudah ada.
- **Ownership**: sebuah aksi (balas/hapus/ambil/lepas/edit profil/konfirmasi nomor/tutup) terhadap conversation ditolak **hanya kalau** conversation itu sedang ditangani staff LAIN (non-admin, dan bukan dirinya). Conversation yang belum ditangani siapa pun tetap bisa diakses siapa saja.
- **Admin selalu boleh** override/take-over/lepas/balas/hapus conversation manapun, terlepas dari assignment — untuk keperluan supervisi.
- **Incoming customer tidak pernah mengganti assignee.** Pesan masuk hanya menyentuh `status`/`last_message_at`/`last_message_direction`/`whatsapp_name`/`phone` — tidak pernah menulis `assigned_to`.

### 8.2 Kombinasi valid
Assignment **bukan** bagian dari status Open/Closed (Section 11) — keempat kombinasi berikut sama-sama valid: `OPEN+assigned`, `OPEN+unassigned`, `CLOSED+assigned`, `CLOSED+unassigned`.

### 8.3 Race safety
Klaim ("Ambil") dieksekusi lewat **satu statement atomic** `UPDATE ... WHERE id=? AND assigned_to IS NULL` (non-admin) — MySQL mengunci baris per-statement UPDATE, jadi kalau 2 request "Ambil" nyaris bersamaan, **tepat satu** yang benar-benar mengubah baris (`affectedRows()===1`), yang lain kalah (`affectedRows()===0`). Diverifikasi lewat test assertion langsung ke MySQL (2 percobaan simultan, tepat 1 berhasil).

---

## 9. Identity & Conversation Reconciliation
`Historical source: [CHAT §11, §12]`

### 9.1 Masalah yang diselesaikan
`conversations.chat_id` (UNIQUE) awalnya satu-satunya kunci pencarian conversation. WhatsApp kadang melaporkan **nomor yang sama** lewat **JID berbeda** (paling umum `@lid` lalu `@s.whatsapp.net`, atau sebaliknya) — kalau tidak ditangani, ini membuat AuliaPos membuat conversation KEDUA untuk customer yang sebenarnya sama.

### 9.2 Desain yang dipilih
Bukan tabel `customers`/CRM terpisah (dianggap over-engineering). Dipilih: 1 tabel alias kecil (`conversation_identities`, banyak `chat_id`/JID bisa menunjuk ke satu `conversation_id`) + 4 kolom baru di `conversations` — lihat Section 10 untuk semantik masing-masing kolom.

### 9.3 Alur pencarian/pencocokan (urutan tidak boleh diubah)
1. **chat_id sudah dikenal** (ada di `conversation_identities`) → pakai conversation itu apa adanya. Jalur tercepat & paling sering kena.
2. **Belum dikenal, tapi Gateway mengenali JID `@lid` terkait lewat query resmi ke server WhatsApp** (`identity_hint`, hanya berlaku untuk pesan `jid_type='pn'` yang baru datang, lewat `sock.onWhatsApp()` — arahnya **satu arah saja, PN→LID**, tidak ada mekanisme sebaliknya di versi library yang dipakai) → kalau LID hasil query itu sudah dikenal sebagai conversation yang ada (kasus LID datang duluan, PN datang belakangan — "LID-FIRST → PN-LATER") → chat_id PN baru ditempelkan sebagai alias ke conversation itu.
3. **Belum dikenal, tapi ada `phone` yang sudah cocok** (hanya untuk JID personal `@s.whatsapp.net` asli, tidak pernah ditebak dari `@lid`/`manual_phone`) → gabung ke conversation yang `phone`-nya sudah cocok.
4. **Semuanya gagal** → identity benar-benar baru → buat conversation baru.

Saat penggabungan terjadi di langkah 2/3: histori pesan lama **utuh** (tidak dihapus/dipindah), `conversations.chat_id` dimutakhirkan ke JID terbaru (dianggap lebih bisa diandalkan untuk kirim balasan) — **`chat_id` hanya pernah dimutakhirkan ke JID lain, tidak pernah diganti jadi nomor telepon polos**. JID lama tetap ada sebagai alias di `conversation_identities`.

**Sengaja tidak pernah** mencocokkan berdasarkan nama (mencegah auto-merge salah karena nama kebetulan sama). Group chat tidak pernah ikut proses pencocokan berbasis nomor ini.

### 9.4 Konfirmasi Nomor manual (fallback)
Untuk conversation `@lid` yang belum bisa direkonsiliasi otomatis lewat langkah 2, kasir/admin bisa secara sadar **mengkonfirmasi nomor** — mengisi `phone` manual, sehingga pesan PN berikutnya otomatis tersambung lewat langkah 3. Ditolak (pesan jelas, HTTP 409) kalau nomor itu sudah dipakai conversation lain (cegah dua conversation punya `phone` sama). Tindakan ini **tidak pernah** menggabungkan pesan yang sudah ada, murni menolak atau mengarahkan pesan berikutnya.

### 9.5 Keamanan data existing
Perubahan skema untuk fitur ini murni **additive** (kolom nullable + tabel baru + backfill) — tidak ada `UPDATE`/`DELETE` terhadap `conversations`/`messages` yang sudah ada. Conversation duplikat yang **sudah ada** sebelum fitur ini **tidak** di-auto-merge (mencegah auto-merge history yang agresif) — fitur ini mencegah duplikat baru ke depannya, bukan menggabungkan yang telanjur ada.

---

## 10. Customer Profile
`Historical source: [CHAT §11.2, §11.5]`

Empat kolom customer di `conversations`, semantik masing-masing berbeda dan tidak saling menimpa:
- **`whatsapp_name`** — push name WhatsApp, selalu dimutakhirkan otomatis oleh Gateway.
- **`contact_name`** — nama manual customer profile, murni diisi kasir/admin, tidak pernah disentuh otomatis.
- **`manual_phone`** — nomor yang diketik manual kasir, **informasional saja**, **tidak pernah** dipakai untuk mencari/menggabungkan conversation (beda dari `phone`, lihat Section 9.3).
- **`phone`** — nomor **TER-VERIFIKASI** (di-derive Gateway dari JID `@s.whatsapp.net` asli) — satu-satunya kolom yang dipakai untuk reconciliation berbasis nomor (Section 9.3).
- `profile_updated_at`/`profile_updated_by` — audit ringan khusus perubahan manual.

**Edit Profil**: kasir/admin bisa mengubah `contact_name` dan/atau `manual_phone`, boleh mengosongkan salah satu/kedua. Tunduk pada aturan ownership yang sama seperti kirim/hapus (Section 8).

**Tampilan fallback di seluruh UI**: nama = `contact_name` → `whatsapp_name` → `phone` → `chat_id`; nomor tampil = `manual_phone` → `phone`.

---

## 11. Conversation Lifecycle
`Historical source: [CHAT §13, §14]`

### 11.1 Prinsip
`status` (`ENUM('open','closed')`) dan Assignment (Section 8) adalah **2 dimensi independen** yang harus tetap konsisten. Tidak ada state ketiga (`waiting`/`pending`/`reopened`/dst). Semua 4 kombinasi valid: `OPEN+unassigned`, `OPEN+assigned`, `CLOSED+unassigned`, `CLOSED+assigned`.

### 11.2 Aturan per-operasi
Setiap operasi hanya menulis kolom yang menjadi tanggung jawabnya — ini yang menjamin kedua dimensi tidak saling mempengaruhi secara tidak sengaja:

| Operasi | Menulis | Tidak pernah menulis |
|---|---|---|
| Pesan masuk (`incoming`) | `status='open'`, `last_message_*`, `whatsapp_name`, `phone` | `assigned_to` |
| Pesan keluar sinkron dari WA Web/HP | `last_message_*` saja | `status`, `assigned_to` |
| Tutup percakapan (Close) | `status='closed'`, `closed_at`, `closed_by` | `assigned_to` |
| Ambil | `assigned_to` (atomic) | `status` |
| Lepas | `assigned_to=NULL` | `status` |
| Kirim balasan (auto-assign) | `assigned_to` (hanya kalau masih NULL), `last_message_*`, `last_replied_by` | `status` |

### 11.3 Rule kunci
- **Reopen tidak membuat conversation baru.** Pencarian conversation (Section 9.3) tidak peduli `status` — conversation `CLOSED` tetap "ditemukan" oleh incoming berikutnya lewat jalur pencarian yang sama seperti kalau dia `OPEN`. Reopen = update `status` pada baris yang sama, bukan insert baru.
- **Close tidak menghapus assignment** kecuali diminta eksplisit (Lepas).
- **Incoming setelah reopen tidak mengubah assignment** — unassigned tetap unassigned, assigned tetap assigned ke orang yang sama.
- **Outgoing sinkron (WA Web/HP) tidak mengubah lifecycle maupun assignment.**
- Tidak ada tombol "Open" manual — reopen hanya lewat pesan masuk dari customer.

### 11.4 Concurrency: Close vs incoming hampir bersamaan
Tidak ada risiko duplicate conversation atau assignment hilang (kedua operasi memperbarui baris yang sama). **Keterbatasan yang disadari & diterima** (bukan diperbaiki, di luar scope perubahan minimal): kalau Close dan incoming benar-benar berbarengan, `status` mengikuti update mana yang commit terakhir (*last-write-wins*) — bukan korupsi data, hanya soal siapa menang di detik yang sama. Upgrade path kalau dibutuhkan: optimistic locking (`WHERE updated_at = <nilai yang dibaca>`).

### 11.5 UI per kombinasi state

| State | Tombol/Info yang tampil |
|---|---|
| OPEN + milik saya | badge `OPEN`, `Dipegang: <saya>`, tombol **Lepas**, tombol **Tutup** |
| OPEN + unassigned | badge `OPEN`, `Belum diambil`, tombol **Ambil**, tombol **Tutup** |
| OPEN + milik staff lain | badge `OPEN`, `Dipegang: <lain>`, tanpa tombol Lepas |
| CLOSED (assigned/unassigned) | badge `CLOSED`, info assignee tetap tampil, tanpa tombol Tutup |

Filter Semua/Open/Closed tidak terpengaruh assignment sama sekali (murni berdasar `status`).

---

## 12. Unread / Read
`Historical source: [CHAT §15]`

> **STATUS: SPESIFIKASI — sebagian kecil sudah berjalan**
>
> Section 12 di bawah adalah **spesifikasi aturan bisnis yang disepakati**, bukan deskripsi behavior berjalan. Yang sudah ada di kode hanya versi sederhana lewat **Response State (Section 19)**. Jangan membangun di atas bagian lain seolah-olah sudah ada. Lihat Section 17.

### 12.1 Tujuan & prinsip
Chat harus bisa membedakan conversation sudah/belum dibaca — dimensi independen, terpisah dari Lifecycle (Section 11) dan Assignment (Section 8). Read/Unread bukan pengganti salah satu dari itu (identitas — Section 9 — adalah concern terpisah lagi, tentang *conversation record mana* yang sedang dibicarakan, bukan state dari conversation itu sendiri).

**Level Conversation, bukan per-Message.** Kalau customer kirim 3 pesan berturut-turut, conversation berada pada SATU kondisi `UNREAD`, bukan 3 penanda terpisah. Jumlah pesan baru boleh ditampilkan sebagai info tambahan, tapi state utamanya tetap di level conversation.

```
CONVERSATION
├── Lifecycle:   OPEN / CLOSED     (Section 11)
├── Assignment:  NULL / User ID    (Section 8)
└── Read State:  READ / UNREAD     (bagian ini)
```

### 12.2 Hubungan dengan Assignment
- **Assigned** (ke User A): Read/Unread jadi tanggung jawab User A secara spesifik. Staff lain boleh melihat, tapi aktivitas mereka (membuka, membaca) **tidak mengubah** status Read/Unread milik User A.
- **Unassigned**: berada di inbox bersama. Pesan baru membuatnya `UNREAD` dan terlihat semua staff yang punya akses inbox. **Membuka saja tidak menghilangkan Unread** selama belum diambil.

### 12.3 Kapan conversation menjadi READ
- **Assigned**: membuka conversation tidak otomatis membuat pesan terbaru jadi Read. Menjadi `READ` hanya ketika assignee **benar-benar melihat/mencapai pesan terbaru** (terlihat di viewport) — bukan berdasarkan scroll manual atau sekadar membuka halaman.
- **Unassigned**: staff yang membuka tanpa **Ambil** tidak menghilangkan Unread.
- **Sedang mengetik balasan** bukan bukti pesan sudah dibaca — pesan customer yang masuk saat assignee mengetik tetap `UNREAD`.
- **Tab/browser tidak aktif**: pesan yang masuk saat AuliaPos di tab/browser tidak aktif tetap `UNREAD` sampai assignee benar-benar kembali dan melihat pesan terbarunya.

### 12.4 Ambil Chat = ambil tanggung jawab + tandai terbaca
Ketika staff **Ambil** conversation unassigned: `assigned_to` terisi **dan** conversation langsung `READ` oleh user itu (pesan yang ada saat itu dianggap sudah dilihat). Ambil punya 2 makna sekaligus.

### 12.5 Pesan masuk (semua tipe media)
Setiap pesan incoming (text/image/document/audio/video, dan tipe lain di masa depan) menghasilkan `UNREAD` kalau belum dilihat assignee — tidak ada pengecualian berdasarkan `message_type`.

### 12.6 Persistensi & konsistensi multi-device/multi-tab
Status Read/Unread **wajib** disimpan persisten di database — tidak boleh hanya bergantung pada `localStorage`/session browser/state JS/tab/device tertentu. Berlaku **global** untuk satu user pada satu conversation, bukan per-device/per-tab. User lain (atau admin) yang sekadar membuka conversation milik assignee lain **tidak mengubah** Read/Unread milik assignee tersebut — kecuali memang melakukan Takeover (12.7).

### 12.7 Efek aksi lifecycle/assignment terhadap Read/Unread

| Aksi | Efek terhadap Read/Unread |
|---|---|
| **Close** | Tidak mengubah Read/Unread sama sekali |
| **Customer kirim pesan setelah Closed** | conversation kembali OPEN dengan assignee yang sama, dan menjadi `UNREAD` |
| **Lepas** | conversation kembali ke inbox bersama dan menjadi `UNREAD` |
| **Ambil setelah Lepas** | sama seperti 12.4 — `assigned_to` terisi + langsung `READ` |
| **Takeover** (A → B, termasuk oleh Admin) | conversation menjadi `UNREAD` untuk assignee baru, terlepas dari status Read sebelumnya milik assignee lama |
| **Outgoing dari AuliaPos** (reply oleh assignee) | Tidak menghasilkan Unread — assignee yang membalas otomatis dianggap sudah melihat (jadi `READ`) |

### 12.8 Activity & urutan daftar conversation
Incoming maupun outgoing sama-sama "aktivitas conversation" yang menentukan urutan (aktivitas terbaru = paling atas). **Sorting berdasarkan aktivitas tidak sama dengan Read/Unread** — dua konsep independen.

### 12.9 UI: dua tingkat indikator
1. **Badge total** pada Inbox: jumlah CONVERSATION yang Unread (bukan jumlah pesan Unread).
2. **Indikator per-conversation**: penanda visual di setiap baris conversation yang sedang Unread.

### 12.10 Matriks transisi (acceptance test)

| Kondisi awal | Aksi | Hasil |
|---|---|---|
| Unassigned + Unread | Buka (tanpa Ambil) | Tetap Unread |
| Unassigned + Unread | Ambil | Assigned + Read |
| Assigned + Unread | Assignee melihat pesan terbaru | Read |
| Assigned + Read | Customer kirim pesan | Unread |
| Assigned + Read | Close | Closed + Read |
| Closed + Read (assigned) | Customer kirim pesan | Open + Unread (assignee sama) |
| Assigned + Read | Lepas | Unassigned + Unread |
| Assigned A + Read | Takeover oleh B | Assigned B + Unread |
| Assigned + Unread | Staff lain buka | Tetap Unread |
| Assigned + Unread | Admin buka tanpa takeover | Tetap Unread |
| Assigned + Read | Outgoing dari POS | Tetap Read |
| Closed | Delete oleh Admin | Conversation terhapus (semua state ikut hilang) |
| (Deleted) | Customer kirim pesan | Conversation baru dibuat |

### 12.11 Di luar scope
Belum ditentukan/dibahas terpisah: kewenangan spesifik Shift Leader di modul Chat (lihat `USER-SHIFT.md`), notifikasi push/suara/desktop, SLA & escalation, assignment otomatis berdasarkan Shift Leader, unread per-message, read receipt WhatsApp (centang biru — native WhatsApp), indikator "customer sedang mengetik", mekanisme sinkronisasi real-time selain polling.

---

## 13. Permissions
`Historical source: [CHAT §15.12]`

Ringkasan hak akses lintas fitur (tidak ada role baru — tetap 2 level: staff vs admin, mengikuti ownership Section 8):

| Operasi | Assignee | Staff lain | Admin |
|---|---|---|---|
| Lihat conversation | Ya | Ya* | Ya |
| Ambil | Ya | Ya | Ya |
| Lepas | Ya | Tidak | Ya |
| Close | Ya | Tidak | Ya |
| Edit profil | Ya | Tidak | Ya |
| Hapus | Tidak | Tidak | Ya |
| Takeover | — | — | Ya |

\* mengikuti aturan visibility inbox yang sudah ada.

**Role "Shift Leader" belum termasuk** dalam spesifikasi ini — kalau/ketika dibutuhkan, mengikuti aturan cross-version di `USER-SHIFT.md`, bukan definisi lokal baru di sini.

---

## 14. Gateway
`Historical source: [CHAT §2.3, §3.3, §4.1, §5.1]`

### 14.1 Status: effective vs raw
Badge status Gateway menampilkan **`effective_status`**, bukan `raw_status` mentah: kalau baris `gateway_status` bilang `status='connected'` tapi heartbeat terakhir sudah basi (>`heartbeatStaleSeconds`, default **30 detik**), UI menampilkan **"Terputus"** — supaya kasir tidak mengira Gateway hidup padahal sudah mati. Badge punya 3 state: **Terhubung / Menghubungkan / Terputus**.

`GatewayStatusModel::isUsable()` dicek **sebelum** melakukan HTTP call ke Gateway — kalau Gateway jelas mati, kasir langsung dapat penolakan cepat, bukan menunggu HTTP timeout penuh.

### 14.2 Polling
Tidak ada WebSocket (keputusan desain eksplisit). Interval: daftar conversation **6 detik**, pesan dalam thread aktif **4 detik**, status Gateway **15 detik** (selaras interval heartbeat).

### 14.3 Autentikasi
Lihat Section 2 — shared secret dipakai dua arah, filter token fail-closed.

### 14.4 Gate aksi saat Gateway tidak terhubung (Tahap F)
Saat `effective_status !== 'connected'`, semua aksi yang butuh Gateway **diblokir keras** (bukan sekadar peringatan): kirim teks (`kirimBalasan`), kirim media (`kirimMediaBalasan`), dan "+ Chat Baru" (`mulaiChatBaru`, tombolnya juga disabled). Guard ada di JS, bukan hanya tampilan disabled. Draft teks/file yang sudah diisi **tidak dihapus**. Gambar/sticker yang belum ada di disk lokal tidak dimuat (placeholder ikon wifi — sengaja beda dari placeholder 410 Tahap E). Saat status kembali `connected`, thread aktif dimuat ulang otomatis. Polling (6s/4s/15s) tetap jalan. Status awal dianggap terhubung sampai poll pertama selesai. Ini gate sisi frontend; backend tetap memakai `GatewayStatusModel::isUsable()` (14.1).

---

## 15. New Chat & Phone Normalization
`Historical source: [CHAT §6.2, §6.3]`

Kasir bisa memulai kontak ke nomor customer yang **belum pernah** mengirim pesan sama sekali (tidak hanya membalas conversation yang sudah ada). Kalau conversation untuk nomor tersebut sudah ada (customer ini pernah chat sebelumnya), sistem memakai yang sudah ada — **tidak membuat duplikat**.

**Normalisasi nomor telepon Indonesia:** menerima format umum `08xx`, `62xx`, `+62xx` (boleh ada spasi/strip/tanda kurung), diubah jadi JID `<62xxx>@s.whatsapp.net`. Format `8xx` tanpa awalan **ditolak secara sengaja** (ambigu, tidak ditebak-tebak). Panjang nomor divalidasi wajar (10–15 digit setelah normalisasi) supaya tidak menyimpan nomor sampah. Format yang tidak bisa dikenali dengan yakin ditolak dengan pesan error jelas.

---

## 16. Delete Conversation
`Historical source: [CHAT §8]`

### Current Rule (sejak Tahap D)

Menghapus conversation adalah **soft delete** (`deleted_at` di `conversations` dan `messages`), bukan hard delete. Alasan: tidak ada tabel `customers` terpisah — identitas customer yang sudah dikonfirmasi hidup di baris `conversations`, jadi hard delete menghilangkannya tanpa bisa dipulihkan.

- **Hanya Admin**, dan conversation harus berstatus **CLOSED** dulu (`Inbox::hapusPercakapan()`: non-admin → 403, masih open → 409). Ditegakkan di kode dan diuji (`tests/session/InboxSoftDeleteTest.php`).
- Kalau customer yang sama mengirim pesan lagi (cocok lewat `chat_id`, alias, `phone`, atau `manual_phone`), `ConversationModel::resolveConversationId()` **menghidupkan kembali** (`revive()`) conversation itu — bukan membuat duplikat.
- **Tidak menyentuh Gateway/WhatsApp** — chat di WhatsApp/HP tidak terhapus.
- UI meminta konfirmasi eksplisit sebelum eksekusi.
- Data yang terhapus **sebelum** Tahap D tidak bisa dipulihkan (soft delete tidak retroaktif).
---

## 17. Known Implementation Gaps

- **Unread/Read (Section 12) — sebagian besar masih spesifikasi.** Yang sudah berjalan hanya versi sederhana lewat Response State (Section 19: `last_seen_by_assignee_at`, tandai dibaca, snooze, badge sidebar). Matriks transisi lengkap Section 12.10 belum diimplementasikan.
- **Race-condition `ambilPercakapan()` belum punya test otomatis** (lihat `TODO-CHAT.md`).

- **Resolusi LID→PN (Section 9.3 langkah 2) belum pernah diverifikasi terhadap koneksi WhatsApp sungguhan** — `sock.onWhatsApp()` (mekanisme query resminya) belum pernah dipanggil ke server WhatsApp live per riwayat implementasi terakhir; kode dibuat defensif tapi format nilai `lid` yang dikembalikan server belum terkonfirmasi live.
- **Kewenangan Shift Leader untuk Chat belum dibahas** (lihat `USER-SHIFT.md` §7) — tidak ada kode Chat yang membaca `Authority::isCurrentShiftLeader()`.
- Di luar scope, belum dibahas: notifikasi push/suara/desktop, SLA & escalation, assignment otomatis berdasarkan Shift Leader, unread per-message (bukan per-conversation), read receipt WhatsApp asli (centang biru), indikator "sedang mengetik", sinkronisasi real-time selain polling.
- Reconciliation untuk pasangan conversation duplikat yang **sudah ada** sebelum fitur identity reconciliation ditambahkan — tidak di-auto-merge, belum ada tombol UI untuk reconciliation manual kasus ini.

---

## 18. Developer Invariants

> **Gateway tidak pernah jadi sumber kebenaran business state.** SQLite Gateway murni reliability buffer.

> **Outgoing message hanya pernah tersimpan setelah Gateway konfirmasi sukses.** Tidak ada outgoing queue, tidak ada retry otomatis sistem — kegagalan berarti tidak ada baris tersimpan sama sekali, retry adalah aksi manusia.

> **Identity, Lifecycle, Assignment, dan Read/Unread adalah dimensi independen** — setiap operasi hanya menulis kolom yang jadi tanggung jawabnya (lihat tabel Section 11.2). Menambah fitur baru yang menyentuh salah satu dimensi harus secara eksplisit menyatakan dimensi mana yang boleh disentuh, bukan menulis kolom lain "sekalian".

> **Gateway tidak pernah menyimpan file media; audio/video tidak pernah diambil sama sekali** (metadata saja). Gambar/dokumen/sticker hanya disimpan di disk CI4 kalau `inbox.mediaStoragePath` diisi (Section 6.3); tanpa itu, referensi + live-fetch.

> **Conversation tidak pernah dihapus permanen lewat UI** — hapus = soft delete (Section 16).

> **`chat_id` hanya pernah dimutakhirkan ke JID WhatsApp lain, tidak pernah diganti jadi nomor telepon polos.** Reconciliation menambah alias (`conversation_identities`), tidak pernah menghapus/mengganti identitas dengan cara yang menghilangkan jejak.

> **Incoming message tidak pernah mengubah `assigned_to`.** Assignment hanya berubah lewat Ambil/Lepas/Takeover/auto-assign-saat-outgoing — tidak pernah sebagai efek samping pesan masuk.

---

## 19. Response State
`Sumber: Tahap A (migration 2026-09-19-000001_AddResponseStateFoundation)`

State turunan (dihitung, bukan disimpan) untuk membantu kasir melihat conversation mana yang perlu dibalas. Dimensi independen dari Lifecycle, Assignment, dan Identity. Nilai `response_state` per conversation (dihitung di `Inbox::attachResponseState()`):

| State | Arti |
|---|---|
| `perlu_dibalas` | Pesan terakhir dari customer dan belum dibalas/ditandai dibaca |
| `menunggu_customer` | Pesan terakhir dari toko, atau ditandai dibaca oleh assignee |
| `follow_up` | Di-snooze sementara (`snoozed_until` di masa depan) |
| `selesai` | Conversation CLOSED |

Kolom baru: `conversations.last_seen_by_assignee_at`, `conversations.snoozed_until`. Endpoint: `POST /inbox/percakapan/{id}/tandai-dibaca`, `POST /inbox/percakapan/{id}/snooze`, `GET /inbox/api/perlu-dibalas-count` (badge sidebar). `/inbox` punya filter berdasarkan state. Mengirim media (`kirimMedia()`) tidak menulis `last_seen_by_assignee_at`.
---

## 20. Window Standalone
`/inbox` dibuka di window terpisah bernama tetap (`AuliaInbox`) memakai `layout/minimal.php` (tanpa sidebar; hanya Bootstrap + FontAwesome). Klik ulang link sidebar memfokuskan window yang sama.
