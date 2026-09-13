# Aturan Bisnis Modul Shared WhatsApp Inbox (Chat) — AuliaPos v3.x

> **Sumber asli:** `aturan-bisnis-CHAT.md` (Section 1–15, penomoran
> mandiri terpisah dari `aturan-bisnis-AULIA.md`). Dokumen ini berisi
> **aturan bisnis yang berlaku saat ini** untuk modul Chat/Inbox WhatsApp,
> disusun ulang per-topik (bukan per-fase kronologis seperti sumber
> asli). Rujukan ke section asli memakai format `[CHAT §N]`. Riwayat
> implementasi, daftar file yang diubah, dan catatan
> "sudah/belum diverifikasi" ada di `CHAT-CHANGELOG.md`.
>
> Untuk aturan bisnis AuliaPos inti (transaksi, kasir, jadwal, dll —
> yang tidak berkaitan dengan Chat), lihat file `AULIA-*.md`.

**Status Tahap 5 (Unread/Read):** aturan **disepakati**, namun **belum
diimplementasikan** pada kode per tanggal dokumen sumber (2026-09-13).
Bagian lain di dokumen ini sudah diimplementasikan kecuali disebutkan
lain.

---

## Daftar Isi

1. [Prinsip Arsitektur](#1-prinsip-arsitektur)
2. [Alur Pesan Masuk & Keluar (Teks)](#2-alur-pesan-masuk--keluar-teks)
3. [Media: Gambar & Dokumen](#3-media-gambar--dokumen)
4. [Media: Audio & Video](#4-media-audio--video)
5. [Sinkronisasi Balasan dari WhatsApp Web/HP Langsung](#5-sinkronisasi-balasan-dari-whatsapp-webhp-langsung)
6. [Filter Status WhatsApp (Stories)](#6-filter-status-whatsapp-stories)
7. [Mulai Chat Baru & Normalisasi Nomor](#7-mulai-chat-baru--normalisasi-nomor)
8. [Hapus Percakapan](#8-hapus-percakapan)
9. [Assignment / "Ambil" Percakapan](#9-assignment--ambil-percakapan)
10. [Identitas Pelanggan & Reconciliation Conversation](#10-identitas-pelanggan--reconciliation-conversation)
11. [Status Lifecycle Open/Closed × Assignment](#11-status-lifecycle-openclosed--assignment)
12. [Unread / Read](#12-unread--read)
13. [Hak Akses — Ringkasan](#13-hak-akses--ringkasan)

---

## 1. Prinsip Arsitektur
`[CHAT §1.2]`

- **Database benar-benar terpisah**: `aulia_inboxdb`, database baru, bukan tabel tambahan di `aulia_kasirdb`. Koneksi default AuliaPos tidak disentuh sama sekali.
- **Tidak ada tabel `users` baru.** Identitas kasir tetap dari `aulia_kasirdb.users` yang sudah ada. Kolom `assigned_to`, `last_replied_by`, `closed_by` (di `conversations`) dan `sent_by_user_id` (di `messages`) adalah **logical reference** ke `users.id` — BUKAN foreign key database sungguhan (MySQL tidak aman untuk FK lintas database). Kalau perlu nama user dari ID ini, diambil lewat `UserModel` (koneksi default) secara terpisah, digabungkan di controller/view — tidak bisa di-`JOIN` langsung.
- **Gateway TIDAK menyimpan business state.** SQLite di sisi Gateway murni reliability buffer untuk retry pengiriman event, bukan sumber kebenaran.

---

## 2. Alur Pesan Masuk & Keluar (Teks)
`[CHAT §2, §3]`

### 2.1 Pesan masuk
Customer kirim WA → Gateway (Baileys) terima → CI4 simpan ke `aulia_inboxdb` → conversation ditandai `status='open'` kembali (apa pun status sebelumnya) → `last_message_at`/`last_message_direction` diperbarui. Idempotent berdasarkan `wa_message_id`.

`last_message_at` diisi dari waktu pesan itu sendiri terjadi di WhatsApp (`message_timestamp` payload), bukan waktu server CI4 menerima request — supaya urutan percakapan tetap merefleksikan kapan pesan itu benar-benar terjadi walau Gateway sempat retry lama.

### 2.2 Pesan keluar (balasan dari POS)
Browser POS → CI4 → cek Gateway usable (`GatewayStatusModel::isUsable()`: heartbeat terakhir belum basi) → kirim ke Gateway → Gateway kirim ke WhatsApp → CI4 **baru** menyimpan sebagai `outgoing`/`sent` kalau Gateway konfirmasi sukses.

**Keputusan penting soal kegagalan kirim:** kalau Gateway gagal/menolak di titik manapun, **TIDAK ADA APA PUN** yang disimpan ke `aulia_inboxdb` — bukan cuma "tidak ditandai sent", tapi memang tidak ada baris `messages` baru sama sekali. Kasir bisa coba klik kirim lagi (retry manual oleh manusia, bukan retry otomatis sistem — tidak boleh ada outgoing queue).

Endpoint kirim (`Inbox::kirim()`) menerima **`conversation_id`** (primary key tabel `conversations`), bukan `chat_id` (JID WhatsApp mentah) — controller yang menerjemahkan `conversation_id → chat_id` sebelum memanggil Gateway.

### 2.3 Status Gateway di UI
Badge status Gateway menampilkan `effective_status`, bukan `raw_status` mentah: kalau baris `gateway_status` bilang `status='connected'` tapi heartbeat terakhir sudah basi, UI menampilkan **"Terputus"** — supaya kasir tidak mengira Gateway hidup padahal sudah mati.

Update dilakukan lewat **polling** (bukan WebSocket, keputusan desain eksplisit): daftar conversation setiap 6 detik, pesan dalam thread aktif setiap 4 detik, status Gateway setiap 15 detik (selaras interval heartbeat).

---

## 3. Media: Gambar & Dokumen
`[CHAT §7]`

**Keputusan paling penting:** file media **TIDAK PERNAH** disimpan permanen di server AuliaPos maupun di SQLite Gateway. Yang disimpan di `aulia_inboxdb` hanya **REFERENSI** (`direct_path` + `media_key` WhatsApp) — file aslinya diambil & didekripsi **ON-DEMAND** dari server WhatsApp setiap kali kasir benar-benar membuka pesan itu.

**Konsekuensi yang disadari & diterima:** WhatsApp tidak menjamin media tersimpan selamanya di server mereka — untuk pesan yang cukup lama, referensi bisa "basi" dan file jadi tidak bisa diambil lagi (ditampilkan sebagai placeholder "tidak tersedia", bukan bug).

Alur ringkas: Customer kirim → Gateway ekstrak referensi (bukan isi file) → simpan referensi ke buffer → CI4 simpan referensi ke `messages.media_metadata` → kasir buka `/inbox` → browser minta unduh → Gateway ambil & dekripsi dari server WhatsApp → di-stream langsung ke browser (tidak pernah disimpan ke disk di CI4 maupun Gateway).

Tampilan: gambar `inline` (tampil di `<img>`), dokumen `attachment` (langsung download, ikon + nama file).

**Outgoing (kasir kirim media dari POS):** memakai pola referensi yang sama — setelah Gateway berhasil upload file ke WhatsApp, hasil upload sudah berisi `directPath`/`mediaKey` untuk file yang baru diunggah, direferensikan dengan cara yang sama seperti incoming, sehingga endpoint buka-ulang media yang sudah ada otomatis berfungsi juga untuk media keluar. File yang diupload kasir dibaca ke memory (tidak pernah ditulis ke disk CI4). Batas ukuran: `Config\Inbox::$maxMediaUploadMb` (default 15MB), dicek CI4 sebelum encode.

Kalau Gateway tidak mengembalikan referensi media (kasus jarang), pesan tetap tersimpan sebagai terkirim (sudah sampai ke WhatsApp) — hanya saja tidak bisa dibuka ulang nanti dari Inbox; ini bukan kegagalan kirim, hanya keterbatasan tampil ulang.

---

## 4. Media: Audio & Video
`[CHAT §10]`

Berlaku untuk arah **masuk** (customer → toko) saja. Sticker, lokasi, kontak, dan audio/video **keluar** (kasir → customer) di luar scope.

**Beda prinsip dari gambar/dokumen (Section 3):** audio/video **TIDAK** memakai pola referensi-untuk-didekripsi-ulang. Binary-nya **tidak pernah diambil**, baik oleh Gateway maupun AuliaPos. Yang disimpan hanya metadata pesan (`message_type`, caption/`text`, mime type, ukuran, timestamp, dll) — `media_path` dan `media_metadata` **selalu NULL** untuk audio/video.

UI cukup menampilkan placeholder: *"Customer mengirim audio/video — cek WhatsApp Web."* Kasir yang perlu dengar/lihat isinya membuka langsung dari WhatsApp Web/HP toko. **Tidak ada** endpoint download media untuk audio/video (beda dari gambar/dokumen).

**Voice note = audio, bukan tipe baru.** WhatsApp mengirim voice note sebagai pesan audio dengan flag `ptt: true` di level teknis — flag ini **tidak** diteruskan ke AuliaPos, tidak ada business logic yang bercabang berdasarkan itu; voice note diperlakukan identik dengan audio biasa.

---

## 5. Sinkronisasi Balasan dari WhatsApp Web/HP Langsung
`[CHAT §5.3]`

Kalau staff membalas customer **langsung dari WhatsApp Web/HP** (bukan lewat POS), balasan itu tetap disinkronkan sebagai `direction='outgoing'` ke `aulia_inboxdb` — supaya kasir lain tidak salah kira belum dibalas dan berisiko membalas dobel.

Karena Baileys tidak punya info siapa yang login WA Web/pegang HP-nya, balasan ini disimpan **tanpa identitas staff spesifik** (`sent_by_user_id = NULL`), ditampilkan di UI dengan label **"Staff (WA Web/HP)"** untuk membedakan dari balasan yang benar-benar dikirim lewat POS.

Balasan sinkron dari luar POS ini **tidak** memaksa `conversation.status='open'` (beda dari incoming customer yang selalu membuka kembali conversation) — balasan staff bukan keputusan yang seharusnya mengubah status conversation.

---

## 6. Filter Status WhatsApp (Stories)
`[CHAT §6.1]`

Update Status WhatsApp (Stories) **tidak boleh** ikut tercatat/muncul di Inbox — bukan percakapan. JID untuk Status selalu literal `status@broadcast`; pesan dengan JID ini difilter di titik paling awal, sebelum diproses/di-log sama sekali.

---

## 7. Mulai Chat Baru & Normalisasi Nomor
`[CHAT §6.2, §6.3]`

Kasir bisa memulai kontak ke nomor customer yang **belum pernah** mengirim pesan sama sekali (tidak hanya membalas conversation yang sudah ada). Kalau conversation untuk nomor tersebut sudah ada (customer ini pernah chat sebelumnya), sistem memakai yang sudah ada — **tidak membuat duplikat**.

**Normalisasi nomor telepon Indonesia:** menerima format umum `08xx`, `62xx`, `+62xx` (boleh ada spasi/strip/tanda kurung), diubah jadi JID `<62xxx>@s.whatsapp.net`. Format `8xx` tanpa awalan **ditolak secara sengaja** (ambigu, tidak ditebak-tebak). Panjang nomor divalidasi wajar (10–15 digit setelah normalisasi) supaya tidak menyimpan nomor sampah. Format yang tidak bisa dikenali dengan yakin ditolak dengan pesan error jelas.

---

## 8. Hapus Percakapan
`[CHAT §8]`

Kasir/admin bisa menghapus satu conversation beserta **SEMUA** riwayat pesannya secara permanen. **Hard delete**, bukan arsip/soft delete.

- Menghapus conversation otomatis menghapus seluruh `messages` miliknya lewat foreign key `ON DELETE CASCADE` (bukan query DELETE terpisah).
- **Tidak menyentuh Gateway/WhatsApp sama sekali** — menghapus percakapan di Inbox POS **tidak menghapus chat di WhatsApp/HP** customer maupun HP toko; ini murni membersihkan riwayat di sisi POS.
- UI meminta konfirmasi eksplisit (nama kontak + peringatan "tidak bisa dikembalikan") sebelum mengeksekusi.
- Sejak fitur assignment ada (Section 9), aksi hapus tunduk pada aturan kepemilikan yang sama dengan kirim balasan/media (lihat Section 13 untuk ringkasan hak akses).

> **Catatan penting (lihat juga Section 11):** aturan Tahap 5 yang disepakati mensyaratkan (a) hapus hanya boleh Admin, dan (b) conversation harus **CLOSED** dulu sebelum bisa dihapus. Per dokumen sumber, **implementasi saat ini belum menegakkan kedua syarat ini** — ini adalah gap terdokumentasi antara aturan yang disepakati dan kode yang berjalan, akan diselesaikan sebagai bagian dari audit implementasi Tahap 5 (bukan diubah diam-diam). Lihat `CHAT-CHANGELOG.md`.

---

## 9. Assignment / "Ambil" Percakapan
`[CHAT §9, §13]`

Satu conversation bisa "ditangani" oleh satu staff, supaya jelas siapa yang bertanggung jawab dan tidak ada 2 kasir membalas bersamaan tanpa sadar.

### 9.1 Definisi
- **Unassigned** (`assigned_to = NULL`): default conversation baru. **Membuka/melihat conversation tidak pernah meng-assign** (read-only).
- **Ambil**: staff mengklaim conversation yang belum ada assignee-nya, atau yang sudah ada assignee-nya kalau ia admin (override/take-over). Staff non-admin yang mencoba mengambil conversation yang sudah ditangani orang lain ditolak, dengan pesan jelas siapa yang sedang menangani.
- **Lepas**: mengosongkan `assigned_to`, conversation kembali bebas diambil/dibalas siapa saja. Hanya boleh dilakukan oleh yang sedang menangani, atau admin.
- **Auto-assign**: begitu satu staff berhasil mengirim balasan (teks/media, terkonfirmasi sukses oleh Gateway) ke conversation yang `assigned_to`-nya masih `NULL`, conversation itu otomatis ter-assign ke staff tsb — tidak perlu klik apa pun dulu. Auto-assign **tidak pernah** menimpa assignment yang sudah ada.
- **Ownership**: sebuah aksi (balas/hapus/ambil/lepas/edit profil/konfirmasi nomor/tutup) terhadap conversation ditolak **hanya kalau** conversation itu sedang ditangani staff LAIN (non-admin, dan bukan dirinya). Conversation yang belum ditangani siapa pun tetap bisa diakses siapa saja.
- **Admin selalu boleh** override/take-over/lepas/balas/hapus conversation manapun, terlepas dari assignment — untuk keperluan supervisi.
- **Incoming customer tidak pernah mengganti assignee.** Pesan masuk hanya menyentuh `status`/`last_message_at`/`last_message_direction`/`whatsapp_name`/`phone` — tidak pernah menulis `assigned_to`.

### 9.2 Kombinasi valid
Assignment **bukan** bagian dari status Open/Closed (Section 11) — keempat kombinasi berikut sama-sama valid: `OPEN+assigned`, `OPEN+unassigned`, `CLOSED+assigned`, `CLOSED+unassigned`.

---

## 10. Identitas Pelanggan & Reconciliation Conversation
`[CHAT §11, §12]`

### 10.1 Masalah yang diselesaikan
`conversations.chat_id` (UNIQUE) awalnya satu-satunya kunci pencarian conversation. WhatsApp kadang melaporkan **nomor yang sama** lewat **JID berbeda** (paling umum `@lid` lalu `@s.whatsapp.net`, atau sebaliknya) — kalau tidak ditangani, ini membuat AuliaPos membuat conversation KEDUA untuk customer yang sebenarnya sama.

### 10.2 Desain yang dipilih
Bukan tabel `customers`/CRM terpisah (dianggap over-engineering untuk kebutuhan saat ini). Dipilih: 1 tabel alias kecil (`conversation_identities`, banyak `chat_id`/JID bisa menunjuk ke satu `conversation_id`) + 4 kolom baru di `conversations`:
- **`whatsapp_name`** — push name WhatsApp, selalu dimutakhirkan otomatis oleh Gateway.
- **`contact_name`** (sudah ada) — sekarang murni nama manual customer profile, tidak pernah disentuh otomatis lagi.
- **`manual_phone`** — nomor yang diketik manual kasir, informasional saja, **tidak pernah** dipakai untuk mencari/menggabungkan conversation.
- **`phone`** (sudah ada) — sekarang murni nomor **TER-VERIFIKASI** (di-derive Gateway dari JID `@s.whatsapp.net` asli) — satu-satunya kolom yang dipakai untuk reconciliation berbasis nomor.
- `profile_updated_at`/`profile_updated_by` — audit ringan khusus perubahan manual.

### 10.3 Alur pencarian/pencocokan conversation
Urutan pencarian saat pesan masuk/mulai chat baru (tidak boleh diubah urutannya):
1. **chat_id sudah dikenal** (ada di `conversation_identities`) → pakai conversation itu apa adanya. Jalur tercepat & paling sering kena.
2. **Belum dikenal, tapi Gateway mengenali JID `@lid` terkait lewat query resmi ke server WhatsApp** (`identity_hint`, hanya berlaku untuk pesan `jid_type='pn'` yang baru datang) → kalau LID hasil query itu sudah dikenal sebagai conversation yang ada (kasus LID datang duluan, PN datang belakangan) → chat_id PN baru ditempelkan sebagai alias ke conversation itu.
3. **Belum dikenal, tapi ada `phone` yang sudah cocok** (hanya berlaku untuk JID personal `@s.whatsapp.net` asli, tidak pernah ditebak dari `@lid`/`manual_phone`) → gabung ke conversation yang `phone`-nya sudah cocok.
4. **Semuanya gagal** → identity benar-benar baru → buat conversation baru.

Saat penggabungan terjadi di langkah 2/3: histori pesan lama **utuh** (tidak dihapus/dipindah), `conversations.chat_id` dimutakhirkan ke JID terbaru (dianggap lebih bisa diandalkan untuk kirim balasan), JID lama tetap ada sebagai alias.

**Sengaja tidak pernah** mencocokkan berdasarkan nama (mencegah auto-merge salah karena nama kebetulan sama). Group chat tidak pernah ikut proses pencocokan berbasis nomor ini.

### 10.4 Konfirmasi Nomor manual (fallback)
Untuk conversation `@lid` yang belum bisa direkonsiliasi otomatis (mis. karena metode langkah 2 di atas belum sepenuhnya terverifikasi di lingkungan produksi), kasir/admin bisa secara sadar **mengkonfirmasi nomor** pada conversation tersebut — mengisi `phone` secara manual, sehingga pesan PN berikutnya otomatis tersambung lewat langkah 3 di atas. Ditolak (dengan pesan jelas) kalau nomor itu sudah dipakai conversation lain (mencegah dua conversation punya `phone` sama/ambigu) — tindakan ini **tidak pernah** menggabungkan pesan yang sudah ada, murni menolak atau mengarahkan.

### 10.5 Fitur Edit Profil Pelanggan
Kasir/admin bisa mengubah `contact_name` (nama manual) dan/atau `manual_phone` (nomor manual). Boleh mengosongkan salah satu/kedua field. Tunduk pada aturan ownership yang sama seperti kirim/hapus (Section 9).

Tampilan nama/nomor di seluruh UI memakai urutan fallback: nama = `contact_name` → `whatsapp_name` → `phone` → `chat_id`; nomor tampil = `manual_phone` → `phone`.

### 10.6 Keamanan data existing
Perubahan skema untuk fitur ini bersifat murni **additive** (kolom nullable + tabel baru + backfill) — tidak ada `UPDATE`/`DELETE` terhadap `conversations`/`messages` yang sudah ada. Conversation duplikat yang **sudah ada** sebelum fitur ini **tidak** di-auto-merge (mencegah auto-merge history yang agresif) — fitur ini mencegah duplikat baru ke depannya, bukan menggabungkan yang telanjur ada. Kalau ditemukan pasangan duplikat lama, strategi paling aman: biarkan keduanya tetap ada apa adanya (tidak ada risiko kehilangan data); reconciliation manual untuk kasus ini belum punya tombol UI.

---

## 11. Status Lifecycle Open/Closed × Assignment
`[CHAT §13, §14]`

> **Catatan editorial:** dokumen sumber merujuk definisi awal status
> lifecycle Open/Closed sebagai "Section 12"/"Tahap 1", namun bagian
> tersebut tidak ada secara utuh di file `aturan-bisnis-CHAT.md` yang
> diterima (kemungkinan penomoran bergeser di sumber, atau bagian itu
> memang tidak disertakan). Aturan Open/Closed di bawah ini disusun
> dari rujukan-rujukan silang di Section 13 & 14 sumber, yang cukup
> lengkap untuk menyimpulkan aturannya. Tanyakan ke penulis dokumen
> asli jika detail definisi awal "Tahap 1" dibutuhkan secara verbatim.

### 11.1 Prinsip
Status lifecycle (`status` kolom, `ENUM('open','closed')`) dan Assignment (Section 9) adalah **2 dimensi independen** yang harus tetap konsisten. Tidak ada state ketiga (`waiting`/`pending`/`reopened`/dst). Empat kombinasi berikut semuanya valid:

| | UNASSIGNED | ASSIGNED(x) |
|---|---|---|
| **OPEN** | Valid | Valid |
| **CLOSED** | Valid | Valid |

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
- **Reopen tidak membuat conversation baru.** Pencarian conversation (Section 10.3) tidak peduli `status` — conversation `CLOSED` tetap "ditemukan" oleh incoming berikutnya lewat jalur pencarian yang sama seperti kalau dia `OPEN`. Reopen = update `status` pada baris yang sama, bukan insert baru.
- **Close tidak menghapus assignment** kecuali diminta eksplisit (lepas).
- **Incoming setelah reopen tidak mengubah assignment** — unassigned tetap unassigned, assigned tetap assigned ke orang yang sama.
- **Outgoing sinkron (WA Web/HP) tidak mengubah lifecycle maupun assignment.**
- Tidak ada tombol "Open" manual — reopen hanya lewat pesan masuk dari customer.

### 11.4 Concurrency: Close vs incoming hampir bersamaan
Tidak ditemukan risiko duplicate conversation atau assignment hilang dalam skenario ini (kedua operasi memperbarui baris yang sama). Satu keterbatasan yang disadari dan diterima (tidak diperbaiki, di luar scope perubahan minimal): kalau Close dan incoming benar-benar berbarengan, kolom `status` mengikuti update mana yang commit terakhir (*last-write-wins*) — bukan korupsi data, hanya soal siapa yang menang di detik yang sama. Upgrade path kalau dibutuhkan nanti: optimistic locking (`WHERE updated_at = <nilai yang dibaca>`).

### 11.5 UI per kombinasi state

| State | Tombol/Info yang tampil |
|---|---|
| OPEN + milik saya | badge `OPEN`, `Dipegang: <saya>`, tombol **Lepas**, tombol **Tutup** |
| OPEN + unassigned | badge `OPEN`, `Belum diambil`, tombol **Ambil**, tombol **Tutup** |
| OPEN + milik staff lain | badge `OPEN`, `Dipegang: <lain>`, tanpa tombol Lepas |
| CLOSED (assigned/unassigned) | badge `CLOSED`, info assignee tetap tampil, tanpa tombol Tutup |

Filter Semua/Open/Closed tidak terpengaruh assignment sama sekali (filter murni berdasar `status`).

---

## 12. Unread / Read
`[CHAT §15]`

> **Status: aturan bisnis DISEPAKATI, per dokumen sumber (2026-09-13)
> BELUM DIIMPLEMENTASIKAN pada kode.**

### 12.1 Tujuan
Chat AuliaPos harus bisa membedakan conversation yang sudah dibaca dan belum dibaca, sebagai **dimensi keempat yang independen**, terpisah dari: Identitas customer, Conversation, Lifecycle Open/Closed, dan Assignment. Read/Unread bukan pengganti salah satu dari itu.

### 12.2 Prinsip utama: level Conversation, bukan per-Message
Unread bukan status per-pesan. Kalau customer mengirim 3 pesan berturut-turut, conversation berada pada SATU kondisi `UNREAD`, bukan 3 penanda terpisah. Jumlah pesan baru boleh ditampilkan sebagai info tambahan di UI, tapi state utamanya tetap Unread pada level conversation.

### 12.3 Hubungan dengan Assignment
- **Conversation assigned** (ke User A): Read/Unread menjadi tanggung jawab User A secara spesifik. Staff lain boleh melihat, tapi aktivitas mereka (membuka, membaca) **tidak mengubah** status Read/Unread milik User A.
- **Conversation unassigned**: berada di inbox bersama. Pesan baru membuatnya `UNREAD` dan terlihat oleh semua staff yang punya akses inbox. **Membuka saja tidak menghilangkan Unread** selama conversation belum diambil.

### 12.4 Kapan conversation menjadi READ
- **Assigned**: membuka conversation tidak otomatis membuat pesan terbaru jadi Read. Menjadi `READ` hanya ketika assignee **benar-benar melihat/mencapai pesan terbaru** (pesan terbaru terlihat di viewport) — bukan berdasarkan scroll manual atau sekadar membuka halaman.
- **Unassigned**: staff yang membuka tanpa melakukan **Ambil** tidak menghilangkan Unread.
- **Sedang mengetik balasan** bukan bukti pesan sudah dibaca — pesan customer yang masuk saat assignee mengetik tetap `UNREAD`.
- **Tab/browser tidak aktif**: pesan yang masuk saat AuliaPos di tab/browser tidak aktif tetap `UNREAD` sampai assignee benar-benar kembali dan melihat pesan terbarunya.

### 12.5 Ambil Chat = ambil tanggung jawab + tandai terbaca
Ketika staff melakukan **Ambil** pada conversation unassigned: `assigned_to` terisi **dan** conversation langsung dianggap `READ` oleh user itu (pesan yang sedang ada saat itu dianggap sudah dilihat). Ambil punya 2 makna sekaligus.

### 12.6 Pesan masuk (semua tipe media)
Setiap pesan incoming dari customer (text/image/document/audio/video, dan tipe lain di masa depan) menghasilkan `UNREAD` kalau belum dilihat assignee — tidak ada pengecualian berdasarkan `message_type`.

### 12.7 Persistensi & konsistensi multi-device/multi-tab
- Status Read/Unread **wajib** disimpan persisten di database — tidak boleh hanya bergantung pada `localStorage`/session browser/state JS/tab/device tertentu.
- Read/Unread berlaku **global** untuk satu user pada satu conversation, bukan per-device/per-tab.
- User lain (atau admin) yang sekadar membuka conversation milik assignee lain **tidak mengubah** Read/Unread milik assignee tersebut — kecuali memang melakukan Takeover (lihat 12.8).

### 12.8 Efek aksi lifecycle/assignment terhadap Read/Unread

| Aksi | Efek terhadap Read/Unread |
|---|---|
| **Close** | Tidak mengubah Read/Unread sama sekali |
| **Customer kirim pesan setelah Closed** | conversation kembali OPEN dengan assignee yang sama, dan menjadi `UNREAD` |
| **Lepas** | conversation kembali ke inbox bersama dan menjadi `UNREAD` |
| **Ambil setelah Lepas** | sama seperti 12.5 — `assigned_to` terisi + langsung `READ` |
| **Takeover** (A → B, termasuk oleh Admin) | conversation menjadi `UNREAD` untuk assignee baru, terlepas dari status Read sebelumnya milik assignee lama |
| **Outgoing dari AuliaPos** (reply oleh assignee) | Tidak menghasilkan Unread — assignee yang membalas otomatis dianggap sudah melihat (jadi `READ`) |

### 12.9 Activity & urutan daftar conversation
Incoming maupun outgoing sama-sama merupakan "aktivitas conversation" yang menentukan urutan (aktivitas terbaru = paling atas daftar). **Sorting berdasarkan aktivitas tidak sama dengan Read/Unread** — dua konsep independen.

### 12.10 UI: dua tingkat indikator
1. **Badge total** pada Inbox: jumlah CONVERSATION yang Unread (bukan jumlah pesan Unread).
2. **Indikator per-conversation**: penanda visual di setiap baris conversation yang sedang Unread.

### 12.11 Delete Conversation — klarifikasi hak akses & prasyarat
- Delete adalah tindakan destruktif, **hanya boleh dilakukan Admin**.
- Conversation harus **CLOSED** dulu sebelum bisa dihapus (urutan: OPEN → Close → CLOSED → Delete).
- Saat dihapus, seluruh state ikut hilang: Read/Unread, assignment, lifecycle, messages.
- Kalau customer mengirim pesan baru setelah conversation-nya dihapus, sistem membuat conversation **baru** (identitas lama sudah tidak ada lagi untuk di-reconcile).

> Lihat catatan gap implementasi di Section 8 — aturan ini belum sepenuhnya ditegakkan oleh kode per tanggal dokumen sumber.

### 12.12 Prinsip pemisahan state (3 dimensi independen)

```
CONVERSATION
├── Lifecycle:   OPEN / CLOSED
├── Assignment:  NULL / User ID
└── Read State:  READ / UNREAD
```

Perubahan satu dimensi tidak boleh secara tidak sengaja mengubah dimensi lain, kecuali interaksi yang eksplisit diatur di bagian ini.

### 12.13 Matriks transisi (acceptance test untuk implementasi)

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

### 12.14 Di luar scope
Belum ditentukan/dibahas terpisah: kewenangan spesifik Shift Leader di modul Chat (konsep dasar Priority/Shift Leader ada di `docs/aturan-bisnis-USER-SHIFT.md`, tapi daftar kewenangan per-fitur untuk Chat belum dibahas), notifikasi push/suara/desktop, SLA & escalation, assignment otomatis berdasarkan Shift Leader, unread per-message, read receipt WhatsApp (centang biru — itu native WhatsApp), indikator "customer sedang mengetik", mekanisme sinkronisasi real-time selain polling.

---

## 13. Hak Akses — Ringkasan
`[CHAT §15.12]`

Ringkasan hak akses lintas fitur (tidak membuat role baru — tetap 2 level: staff vs admin, sesuai fondasi ownership Section 9):

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

**Role "Shift Leader" belum termasuk** dalam spesifikasi ini — penambahan role tersebut dibahas & didokumentasikan terpisah kalau/ketika dibutuhkan. Konsep Priority/Shift Leader (kalau/ketika diterapkan ke Chat) mengikuti aturan cross-version di `docs/aturan-bisnis-USER-SHIFT.md`, bukan definisi lokal baru di file ini.

> Kolom "Hapus: hanya Admin" adalah aturan **baru** dibanding implementasi Section 8 saat ini yang belum menegakkannya (lihat catatan gap di Section 8 & 12.11).
