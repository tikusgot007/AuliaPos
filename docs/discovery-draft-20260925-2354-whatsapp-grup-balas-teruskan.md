---
title: Project Discovery & Architecture Summary — Inbox WhatsApp: Dukungan Grup, Balas Pesan, Teruskan
status: DRAFT (Phase 0)
date_analyzed: 2026-09-25
owner: AuliaPos Inbox module
tags: [inbox, chat, whatsapp, group-chat, reply, forward, discovery]
---

# Project Discovery Summary

> **Catatan bahasa.** Dokumen ini ditulis dalam bahasa Indonesia mengikuti konvensi PRD
> (`prd-20260922-0141-chat-whatsapp-inbox.md`) dan Spec (`spec/`), bukan konvensi
> `/plan/` yang berbahasa Inggris. Draft ini adalah bahan mentah PRD dan dibaca
> pemilik proyek, sehingga bahasanya disamakan dengan artefak turunannya.

## 1. Project Overview

AuliaPos adalah aplikasi POS (Point of Sale) berbasis CodeIgniter 4 yang di dalamnya
tertanam satu modul tambahan: **Shared WhatsApp Inbox** — kotak masuk WhatsApp bersama
yang dipakai beberapa kasir sekaligus. Modul ini bukan sekadar penampil chat; ia adalah
*operational customer workspace*: kasir bisa mengambil kepemilikan percakapan, menandai
mana yang belum dibalas, menunda (snooze), menutup, mencatat Internal Note yang tidak
terkirim ke pelanggan, dan menyerahkan percakapan ke kasir lain (Handoff).

Eksplorasi ini menyiapkan **tiga fitur baru**:

1. **Dukungan grup chat** — percakapan grup WhatsApp ditampilkan dan diperlakukan
   setara WhatsApp biasa (bukan menumpuk jadi satu percakapan anonim).
2. **Balas pesan (reply/quote)** — membalas pesan tertentu dengan kutipan asli.
3. **Teruskan pesan (forward)** — meneruskan pesan ke percakapan lain.

### 1.1 Temuan inti eksplorasi

Inbox saat ini dibangun di atas satu asumsi yang tidak pernah ditulis eksplisit di mana
pun: **satu percakapan = satu pelanggan = satu identitas**. Asumsi ini menembus lapisan
penyimpanan (`conversations`), kepemilikan (`assigned_to`), profil pelanggan,
konfirmasi nomor, sampai penautan ke data pelanggan POS.

Grup chat **melanggar asumsi itu**, dan pelanggarannya sudah berjalan di produksi
hari ini — bukan skenario hipotetis:

- 4 JID grup (`@g.us`) sudah pernah masuk ke `aulia_inboxdb`.
- Pada 2026-09-25 tercatat sekitar 36 pesan grup yang berhasil disimpan, dengan log
  `INFO --> InboxGatewayApi::messages() sukses menyimpan pesan (incoming)`.
- Gejala yang dilaporkan pemilik proyek: *"dianggap 1 percakapan, namanya berubah-ubah
  tergantung yang terakhir ngirim; jadi di halaman pesannya, seperti pesan dari 1 orang."*

Ketiga gejala itu berhasil di-*root cause* ke satu titik yang sama di repo WA-Gateway
(lihat §3 dan §4). Artinya: **fitur grup bukan penambahan fitur baru, melainkan
perbaikan atas kebocoran model data yang sudah terjadi.**

## 2. Technology Stack & Infrastructure

Bagian ini merujuk `docs/ARCHITECTURE.md` untuk hal-hal yang sudah terdokumentasi, dan
hanya menambahkan aspek yang **belum** ada di sana.

### 2.1 Yang sudah terdokumentasi (tidak diulang)

Stack, topologi database berlapis (`default`/POS, `inbox`, `archive`/SQLite,
`tests`/SQLite, `aulia_inboxdb_test`), dan tabel rute Inbox semuanya sudah ada di
`docs/ARCHITECTURE.md` §2, §5, §7. Tidak ada perubahan pada bagian ini.

### 2.2 Yang belum terdokumentasi: repo kedua (WA-Gateway)

Ini aspek terpenting yang tidak tercakup `docs/ARCHITECTURE.md`, dan yang paling
menentukan bentuk PRD nanti.

| Aspek | Kenyataan |
| --- | --- |
| Lokasi | Repo **terpisah** dari AuliaPos (`C:\home\wa-gateway-review` saat eksplorasi) |
| Teknologi | Node.js + Baileys |
| Peran | Jembatan WhatsApp ↔ AuliaPos; **bukan** sumber kebenaran data |
| Media | Hanya sebagai buffer retry (SQLite); binary media **tidak** disimpan permanen |
| Komunikasi | HTTP + Bearer shared secret |
| Titik masuk AuliaPos | `POST /api/inbox/gateway/messages`, `POST /api/inbox/gateway/status` |
| Titik keluar dari AuliaPos | Gateway `POST /send`, `POST /send-media`, `POST /media/download` |

**Konsekuensi arsitektural:** setiap fitur Inbox yang butuh data yang belum pernah
dikirim Gateway **wajib mengubah dua repo, dan karena itu butuh dua plan terpisah**.
Ini bukan preferensi gaya — ini konsekuensi dari batas repo.

### 2.3 Pola state management yang belum terdokumentasi

- **Tanpa message queue keluar.** Pesan keluar disimpan ke database **hanya setelah**
  Gateway mengonfirmasi sukses. Tidak ada antrean pesan keluar di AuliaPos.
- **Idempotensi milik pemanggil.** UI membuat `operation_id` (`crypto.randomUUID()`),
  AuliaPos meneruskannya, Gateway menyimpannya di state machine `outgoing_operations`.
  Ini yang membuat retry kasir tidak mengirim pesan ganda.
- **Status respons turunan, bukan kolom.** `perlu_dibalas` / `menunggu_customer` /
  `follow_up` / `selesai` dihitung ulang (`ConversationModel::withComputedStatus()`),
  tidak disimpan sebagai kolom.
- **Empat dimensi independen.** `CHAT.md` §18 mengunci bahwa identitas, siklus hidup,
  kepemilikan, dan baca-belum-dibaca adalah empat dimensi terpisah. Fitur baru **wajib
  menyatakan dimensi mana yang disentuh** — tidak boleh "sekalian" menulis kolom lain.

## 3. Current Architecture Assessment

### 3.1 Strengths

Yang sudah benar dan **tidak perlu diubah** — penting dicatat supaya PRD tidak
membongkar hal yang sudah sehat:

- **`jid_type` sudah diklasifikasi dan sudah sampai ke database.** Gateway
  mengklasifikasi JID menjadi `pn` (nomor), `lid`, `group` (`@g.us`), `unknown`, dan
  AuliaPos menyimpannya di `conversations.jid_type` (`VARCHAR(20)`, tanpa batasan ENUM).
  Artinya **tidak perlu migration baru hanya untuk mengenali grup.** Yang kurang adalah
  *pemakaian* kolom itu, bukan kolomnya.
- **Kolom `messages.sender_jid` sudah ada** (`VARCHAR(191)`, nullable) dan sudah masuk
  `allowedFields` `MessageModel`. Data pengirim per-pesan sebenarnya sudah pernah
  disimpan — hanya tidak pernah ditampilkan UI.
- **Resolusi identitas percakapan sudah terurut dan sengaja begitu.**
  `ConversationModel::resolveConversationId()` memakai 4 langkah berurutan
  (`chat_id` → `lid` → `phone` → buat baru) dan urutan itu **tidak boleh diubah**;
  melanggar urutannya bisa menggabungkan dua percakapan berbeda.
- **Pengaman grup sudah ada di dua lapis independen.** Gateway mengembalikan `phone =
  null` untuk `@g.us`, dan AuliaPos hanya mengisi `canonicalPhone` bila
  `jidType === 'pn'`. Selama eksplorasi saya sempat mengangkat risiko "nomor pelanggan
  bisa dibajak oleh JID grup model lama (mis. `628563324637-1520910566@g.us`)"; risiko
  itu **saya tarik kembali** setelah membaca kode Gateway — dua lapis itu menutupnya,
  dan log membuktikan belum pernah terjadi rekonsiliasi semacam itu.
- **Soft delete** (`deleted_at`) dan **idempotensi `operation_id`** sudah menjadi pola
  mapan; fitur baru harus memakainya, bukan membuat mekanisme sendiri.

### 3.2 Tech Debt & Risks

#### R-01 — Pengirim grup hilang di Gateway (akar gejala "seperti pesan dari 1 orang")

Di `connectionManager.js`, objek pesan yang dinormalisasi mengisi:

```js
const remoteJid = msg.key?.remoteJid || null;
const normalized = {
  chatId: remoteJid,
  sender: {
    jid: remoteJid,        // ← untuk pesan grup ini adalah JID GRUP, bukan pengirimnya
    phone,                 // ← @g.us selalu null
    name: senderName,      // ← pushName orang yang mengirim
  },
};
```

`msg.key.participant` — kolom Baileys yang berisi **JID orang yang mengirim di dalam
grup** — **tidak pernah dibaca di seluruh `src/`**. Akibatnya AuliaPos menerima
"pengirim = grup itu sendiri" untuk setiap pesan grup, sehingga semua pesan tampak
berasal dari satu orang.

*Catatan penting:* ada satu informasi yang masih selamat — `sender.name` berisi pushName
pengirim yang sebenarnya. Itu sebabnya nama tampilan bisa berubah-ubah (R-02), tapi juga
sebabnya label pengirim **masih bisa diperbaiki untuk pesan baru**.

#### R-02 — `contact_name` mencampur dua konsep berbeda

Gateway mengirim `contact_name` = pushName orang yang **terakhir mengirim**, dan
AuliaPos menuliskannya ke `conversations.whatsapp_name`. Untuk chat pribadi ini benar.
Untuk grup ini salah: nama percakapan (identitas grup) tertimpa oleh nama pengirim
setiap kali ada pesan masuk.

Ini pelanggaran *Single Responsibility* pada tingkat makna: satu field dipakai untuk dua
konsep yang berbeda (`identity of the chat` vs `identity of the last sender`), dan
perbedaannya baru terlihat saat grup muncul. Untuk grup, nama percakapan seharusnya
**subject grup**, yang saat ini belum pernah dikirim Gateway sama sekali (R-03).

#### R-03 — Metadata grup tidak tersedia

Tidak ada `groupMetadata`, `subject`, atau `fetchGroupMetadata` di seluruh `src/`
Gateway. Jadi **nama grup tidak bisa ditampilkan** tanpa perubahan Gateway. Ini bukan
sesuatu yang bisa diakali di sisi AuliaPos.

#### R-04 — Badge "perlu dibalas" terinflasi oleh grup

`app/Controllers/Inbox.php:323` (`apiPerluDibalasCount()`) mengambil percakapan
`status = 'open'` **tanpa filter `jid_type`**. Karena grup tidak punya konsep
"dibalas oleh pelanggan", setiap grup yang belum ditutup ikut terhitung di badge sidebar
kasir. Ini bug yang sudah aktif hari ini dan **tidak butuh perubahan Gateway** untuk
diperbaiki.

#### R-05 — Kontrak kirim belum mendukung kutip atau teruskan

`app/Controllers/Inbox.php:2224` (`callGatewaySend()`) hanya mengirim `chat_id`, `text`,
dan `operation_id` opsional; Gateway `POST /send` juga hanya membaca ketiga itu. Tidak
ada field kutipan maupun penerusan di kedua sisi.

**Konsekuensi penting, dan ini kabar baik:** karena `chat_id` diteruskan apa adanya,
**membalas ke grup kemungkinan besar sudah bisa berfungsi hari ini tanpa mengubah
Gateway** (belum pernah diuji — lihat item verifikasi terbuka). Sebaliknya, **kutip dan
teruskan sama-sama butuh perubahan kontrak dua repo.**

#### R-06 — Audio dan video tidak akan pernah bisa diteruskan

`CHAT.md` §6.2 mengunci: untuk audio/video, binary-nya **tidak pernah diambil** dan
`media_path`/`media_metadata` **selalu NULL**. Tidak ada file untuk diteruskan. Ini
bukan bug yang bisa diperbaiki di fase ini — ini keputusan desain yang sudah final dan
harus tercermin sebagai batasan eksplisit di PRD, bukan sebagai bug.

#### R-07 — Celah pada asumsi "satu percakapan = satu pelanggan"

Selain dampak di atas, asumsi ini juga bocor ke fitur yang sudah ada:

| Fitur | Kenapa bermasalah untuk grup |
| --- | --- |
| Kepemilikan (`assigned_to`) & Handoff | Grup bukan milik satu pelanggan; konsep "pemilik" jadi rancu |
| Profil pelanggan & Konfirmasi Nomor | Grup tidak punya nomor telepon (`extractPhoneIfAvailable()` → `null`) |
| Penautan ke pelanggan POS | Grup tidak bisa dipetakan ke satu baris `pelanggan` |
| `perlu_dibalas` / Belum Diambil | Grup tidak punya lawan bicara tunggal untuk ditunggu |

#### R-08 — Drift dokumentasi (kecil, catatan saja)

`docs/ARCHITECTURE.md:5` masih menyatakan peta ini "as observed on branch
`feature/m3-operational-inbox-fase1a-task001`", padahal branch itu sudah di-merge dan
dihapus; branch aktif sekarang `v2.3`. Tidak memblokir pekerjaan, tapi sebaiknya
disinkronkan saat dokumen ini diperbarui nanti.

## ⚙️ Operational Workflow

Tiga alur di bawah ditelusuri dari WhatsApp sampai UI. Alur C adalah alur yang bocor.

### Alur A — Pesan masuk, chat pribadi (sehat, jadi acuan)

```text
WhatsApp
  → Gateway connectionManager.js
      classifyJid(remoteJid) → 'pn'
      extractPhoneIfAvailable() → nomor
      normalized { chatId, jidType:'pn', sender:{jid, phone, name} }
  → Gateway incomingDelivery.js  → POST /api/inbox/gateway/messages
  → InboxGatewayApi::messages()
      jidType === 'pn'  → canonicalPhone diisi
      telepon tidak cocok → buat percakapan baru (langkah 4 resolveConversationId)
  → ConversationModel::updateLastMessageIfNewer()   (penjaga monoton, tidak bisa mundur)
  → UI Inbox (polling) menampilkan percakapan
```

### Alur B — Balasan keluar (sehat, polanya harus ditiru fitur baru)

```text
UI Inbox  → buat operation_id (crypto.randomUUID())
  → POST /inbox/kirim
  → Inbox::kirim() → kirimKeConversation()
  → callGatewaySend()  { chat_id, text, operation_id }   timeout 10 detik
  → Gateway POST /send → state machine outgoing_operations
  → HANYA setelah Gateway sukses: baris messages disimpan
      (gateway_operation_id, UNIQUE) → retry tidak menggandakan pesan
```

Dua sifat yang wajib dipertahankan fitur baru: **tidak ada antrean keluar**, dan
**simpan setelah konfirmasi**, bukan sebelum.

### Alur C — Pesan masuk, grup (bocor)

```text
WhatsApp grup
  → Gateway connectionManager.js
      classifyJid(remoteJid) → 'group'          ✅ benar
      extractPhoneIfAvailable() → null          ✅ benar
      msg.key.participant                        ❌ TIDAK PERNAH DIBACA
      normalized.sender.jid = remoteJid (JID GRUP)   ❌ di sini bocornya
      normalized.sender.name = pushName pengirim      ⚠️ satu-satunya sisa info pengirim
  → incomingDelivery.js → POST /api/inbox/gateway/messages   (jid_type='group' ikut terkirim)
  → InboxGatewayApi::messages()
      jidType !== 'pn' → canonicalPhone tetap null   ✅ aman
      percakapan dicari lewat chat_id (JID grup) → SELALU ketemu yang sama
  → conversations.whatsapp_name DITIMPA contact_name tiap pesan masuk   ❌ R-02
  → messages.sender_jid diisi JID grup (bukan pengirim)                ❌ R-01
  → UI tidak pernah merender sender_jid                                ❌ R-04-UI
```

**Rantai sebab-akibatnya, satu per satu:**

| Gejala yang dilaporkan | Penyebab |
| --- | --- |
| "dianggap 1 percakapan" | Semua pesan grup ber-`chat_id` sama (JID grup) → wajar, dan tidak perlu diubah |
| "namanya berubah-ubah tergantung yang terakhir ngirim" | `contact_name` = pushName pengirim terakhir, ditulis ke `whatsapp_name` (R-02) |
| "seperti pesan dari 1 orang" | `sender.jid` = JID grup, `participant` tidak dibaca, UI tidak merender `sender_jid` (R-01) |
| "nama grup tidak ada" | Gateway tidak pernah mengirim subject grup (R-03) |

Perhatikan bahwa **`jid_type='group'` sudah benar dari Gateway.** AuliaPos menerima
sinyal yang benar dan mengabaikannya. Ini berarti sebagian perbaikan grup **murni
pekerjaan sisi AuliaPos** (memakai `jid_type` yang sudah ada, merender `sender_jid`,
memperbaiki badge), sementara sebagian lagi **wajib mengubah Gateway** (mengirim
`participant`, mengirim subject grup).

## 5. Handoff Notes for Product Manager (/sdlc-draft-prd)

### 5.1 Keputusan yang sudah dikonfirmasi pemilik proyek

Sembilan keputusan ini sudah final dan **tidak perlu ditanyakan ulang** saat menulis PRD:

| # | Topik | Keputusan |
| --- | --- | --- |
| 1 | Lingkup grup | Grup dipisah dan diberi label; nama = nama grup; label pengirim per pesan; bisa dibalas. **Tanpa** daftar anggota |
| 2 | Antrean kerja | Grup **dikeluarkan** dari `perlu_dibalas` dan dari tab Belum Diambil |
| 3 | Kepemilikan | Grup **tanpa** kepemilikan, **tanpa** tombol Tutup/Lepas |
| 4 | Kutipan | Kutipan WhatsApp asli. Bila Gateway menolak kutipan → beri tahu kasir dengan jelas dan tawarkan kirim tanpa kutipan |
| 5 | Tujuan teruskan | Hanya ke percakapan yang **sudah ada** (tidak membuat percakapan baru) |
| 6 | Label teruskan | Diberi penanda **"Diteruskan"** |
| 7 | Data grup lama | **Dibiarkan apa adanya**; nama pulih sendiri saat pesan berikutnya; label pengirim lama tidak bisa dipulihkan |
| 8 | Audio/video | Tidak bisa diteruskan; tampilkan pesan yang jelas |
| 9 | Urutan pengerjaan | **Grup lebih dulu**, baru balas dan teruskan |

Keputusan tambahan yang disepakati tanpa keberatan (agar tidak hilang):

- Kutipan harus kutipan WhatsApp asli, bukan teks yang ditempel manual.
- Kutipan berlaku untuk teks maupun media.
- Cuplikan teks yang dikutip disimpan di AuliaPos (perlu, karena thread dimuat ulang
  tiap 4 detik).
- Kutipan di grup menampilkan nama pengirim yang dikutip.
- Kegagalan teruskan media (HTTP 410) → pesan jelas dan pengiriman dibatalkan.
- Grup: tombol Konfirmasi Nomor dan Edit Profil dinonaktifkan.
- Grup tidak bisa dibuat dari POS.
- Balas dan teruskan berlaku di chat pribadi **dan** grup.

### 5.2 ⚠️ Peringatan terpenting: ini menyentuh DUA repo

PRD harus memuat ini secara eksplisit, karena menentukan jumlah plan dan urutan kerja.

| Kebutuhan | Cukup di AuliaPos? | Wajib ubah Gateway? |
| --- | --- | --- |
| Memakai `jid_type='group'` untuk memisahkan tampilan | ✅ | ❌ |
| Label pengirim per pesan di grup | ❌ | ✅ butuh `msg.key.participant` |
| Nama grup sebagai judul percakapan | ❌ | ✅ butuh group metadata / `subject` |
| Perbaikan badge `perlu_dibalas` | ✅ | ❌ |
| Balas ke grup | Kemungkinan ✅ (belum diuji) | Kemungkinan ❌ |
| Kutip / balas pesan tertentu | ❌ | ✅ field baru di `/send` |
| Teruskan pesan | ❌ | ✅ kontrak baru |

**Konsekuensi:** minimal ada satu pekerjaan AuliaPos dan satu pekerjaan WA-Gateway, dan
keduanya harus direncanakan terpisah. PRD sebaiknya menyatakan ini sebagai *constraint*,
bukan disembunyikan sebagai detail teknis.

### 5.3 Item verifikasi terbuka (jangan diasumsikan di PRD)

Tiga hal berikut **belum saya buktikan**, dan sebaiknya diverifikasi di fase Spec,
bukan diambil sebagai kebenaran:

1. **`inbox.mediaStoragePath` benar-benar terisi di `.env`.** Pemilik proyek menyatakan
   ya; pembuktiannya lewat kolom `media_local_filename` pada data nyata. Ini menentukan
   apakah gambar/dokumen/stiker bisa diteruskan.
2. **Nilai `jid_type` dan `sender_jid` yang benar-benar tersimpan** untuk 4 grup lama di
   `aulia_inboxdb`. Perlu dilihat langsung ke database, bukan disimpulkan dari kode.
3. **Apakah `POST /send` ke JID grup sudah berhasil hari ini.** Kode menunjukkan seharusnya
   bisa; belum pernah diuji.

### 5.4 Batasan yang tidak boleh dilanggar PRD

- **Empat dimensi independen** (`CHAT.md` §18): identitas, siklus hidup, kepemilikan,
  baca-belum-dibaca. Fitur grup menyentuh **identitas** — PRD harus menyatakannya, dan
  tidak boleh "sekalian" mengubah kepemilikan grup.
- **Audio/video tidak pernah punya binary** (`CHAT.md` §6.2). "Tidak bisa diteruskan"
  adalah batasan final, bukan bug yang menunggu diperbaiki.
- **Gateway bukan sumber kebenaran** dan **tidak ada antrean keluar**.
- **Grup tidak ikut pencocokan berbasis nomor** (`CHAT.md` §9.3) — sudah dipatuhi kode,
  jangan sampai PRD meminta sebaliknya.
- **`resolveConversationId()` urutannya tidak boleh diubah.**

### 5.5 Di luar lingkup (usulan, bukan permintaan)

Sengaja **tidak** dimasukkan ke eksplorasi ini: daftar anggota grup, keluar/masuk grup,
membuat grup dari POS, penautan grup ke data pelanggan POS, Presence, notifikasi/unread.
Bila pemilik proyek menginginkannya, itu kebutuhan baru dan butuh eksplorasi sendiri.

### 5.6 Istilah glosarium

Tiga istilah baru diusulkan dan ditambahkan ke `CONTEXT.md` sebagai bagian dari
eksplorasi ini: **Grup**, **Balas Pesan**, **Teruskan**. PRD **wajib memakai ketiga
istilah kanonik ini**, bukan sinonimnya (*group chat*, *reply*, *quote*, *forward*).

### 5.7 Urutan yang disarankan

1. **Grup** dulu — karena sebagian perbaikannya murni sisi AuliaPos dan bisa dikerjakan
   tanpa menunggu Gateway, sekaligus memperbaiki bug badge yang sudah aktif.
2. **Balas pesan (kutip)** — karena butuh perubahan kontrak Gateway di `/send`.
3. **Teruskan** — karena kontraknya paling baru dan paling bergantung pada hasil
   verifikasi media (§5.3 butir 1).

### 5.8 Satu hal yang perlu diputuskan di PRD

Perbaikan grup menuntut **dua plan di dua repo**, dan Gateway punya siklus rilis
sendiri. PRD perlu menyatakan apakah fitur grup **boleh dirilis bertahap** (tampilan
grup di AuliaPos dulu, label pengirim dan nama grup menyusul setelah Gateway siap),
atau harus **menunggu Gateway selesai** agar dirilis utuh. Ini keputusan produk, bukan
keputusan teknis.
