# Changelog & Catatan Teknis — Modul Chat/Inbox WhatsApp

> **Sumber asli:** `aturan-bisnis-CHAT.md` (Section 1–15, seluruh
> subsection "File yang dibuat/diubah", "Keputusan/asumsi", "Yang
> SUDAH/BELUM diverifikasi", dan bug-fix log). Berisi **riwayat
> implementasi per fase/tahap** — bukan aturan bisnis final (lihat
> `CHAT-01-aturan-bisnis-inbox.md` untuk itu). Rujukan ke section asli
> memakai format `[CHAT §N]`.

---

## Daftar Isi

1. [Phase 1 — Fondasi Database (2026-09-07)](#1-phase-1--fondasi-database-2026-09-07)
2. [Phase 2 — Incoming: Gateway → CI4 (2026-09-07)](#2-phase-2--incoming-gateway--ci4-2026-09-07)
3. [Phase 3 — Outgoing: POS → Gateway → WhatsApp (2026-09-07)](#3-phase-3--outgoing-pos--gateway--whatsapp-2026-09-07)
4. [Phase 4 — UI Inbox (2026-09-07)](#4-phase-4--ui-inbox-2026-09-07)
5. [Perbaikan Pasca-Phase 4 (2026-09-07)](#5-perbaikan-pasca-phase-4-2026-09-07)
6. [Perbaikan & Fitur Tambahan, Gelombang 2 (2026-09-07)](#6-perbaikan--fitur-tambahan-gelombang-2-2026-09-07)
7. [Dukungan Media Gambar/Dokumen — Implementasi (2026-09-07/12)](#7-dukungan-media-gambardokumen--implementasi-2026-09-0712)
8. [Hapus Percakapan — Implementasi (2026-09-12)](#8-hapus-percakapan--implementasi-2026-09-12)
9. [Assignment — Implementasi Awal (2026-09-12)](#9-assignment--implementasi-awal-2026-09-12)
10. [Audio/Video Masuk — Implementasi (2026-09-12)](#10-audiovideo-masuk--implementasi-2026-09-12)
11. [Customer Identity & Reconciliation — Implementasi (2026-09-12)](#11-customer-identity--reconciliation--implementasi-2026-09-12)
12. [Revisi LID-FIRST → PN-LATER (2026-09-12)](#12-revisi-lid-first--pn-later-2026-09-12)
13. [Tahap 2 — Audit Assignment (2026-09-13)](#13-tahap-2--audit-assignment-2026-09-13)
14. [Tahap 3 — Audit Integrasi Open/Closed × Assignment (2026-09-13)](#14-tahap-3--audit-integrasi-openclosed--assignment-2026-09-13)
15. [Status Verifikasi Keseluruhan & Gap Terbuka](#15-status-verifikasi-keseluruhan--gap-terbuka)

---

## 1. Phase 1 — Fondasi Database (2026-09-07)
`[CHAT §1]`

Fondasi database untuk modul baru: kasir bisa balas chat WhatsApp customer langsung dari AuliaPos, terhubung ke Gateway WhatsApp terpisah (Node.js/Baileys) di komputer lain di LAN toko. Phase 1 murni fondasi database — belum ada API endpoint, belum ada UI, belum menyentuh Gateway.

**File yang dibuat/diubah:**
- `app/Config/Database.php` — connection group baru `$inbox`, kredensial lewat `.env` (`database.inbox.*`). `$default` tidak diubah.
- `app/Database/Migrations/2026-09-07-000001_CreateInboxTables.php` — `$DBGroup='inbox'`. Membuat 3 tabel: `conversations`, `messages`, `gateway_status`, termasuk kolom `media_*` di `messages` (disiapkan untuk fitur masa depan, belum dipakai di POC ini — `message_type='text'` saja).
- `app/Models/ConversationModel.php`, `MessageModel.php`, `GatewayStatusModel.php` — eksplisit `$DBGroup='inbox'`. `MessageModel` sengaja `$updatedField=''` (tabel tidak punya `updated_at`). `GatewayStatusModel` sengaja `$createdField=''` (baris id=1 di-upsert terus oleh heartbeat).

**Prasyarat migration:** migration tidak membuat database-nya sendiri (Forge cuma `CREATE TABLE`) — perlu `CREATE DATABASE aulia_inboxdb` manual dulu + isi kredensial `.env`.

**Status per Phase (per akhir dokumen sumber):**
- Phase 1 — selesai, migration terkonfirmasi jalan di server.
- Phase 2 — selesai & tervalidasi end-to-end sungguhan (2026-09-07).
- Phase 3 — selesai & tervalidasi end-to-end sungguhan (2026-09-07).
- Phase 4 — selesai, menunggu verifikasi user di server sungguhan.

---

## 2. Phase 2 — Incoming: Gateway → CI4 (2026-09-07)
`[CHAT §2]`

Jalur pesan masuk end-to-end: Customer kirim WA → Gateway terima → simpan ke SQLite lokal Gateway (reliability buffer) → worker push ke CI4 lewat HTTP → CI4 simpan ke `aulia_inboxdb` → ACK ke Gateway. Idempotent berdasarkan `wa_message_id` di kedua sisi. Sekaligus heartbeat status Gateway → CI4.

**Sisi CI4:**
- `app/Config/Filters.php` — endpoint Gateway (`api/inbox/gateway/*`) dikecualikan dari filter `'auth'` (Gateway tidak pernah punya session/cookie login). *(Catatan: pendekatan `except` ini kemudian terbukti tidak cukup — lihat 2.1 di bawah.)*
- `app/Filters/GatewayTokenFilter.php` (baru) — filter Bearer token khusus Gateway, pasang lewat alias `'gatewaytoken'`. Bandingkan token pakai `hash_equals()` (timing-safe). Token belum dikonfigurasi di server → SEMUA request ditolak (fail closed).
- `app/Config/Inbox.php` (baru) — baca `inbox.gatewayToken` dari `.env`, tidak pernah hardcode.
- `app/Controllers/InboxGatewayApi.php` (baru) — `messages()` (`POST /api/inbox/gateway/messages`): cek idempotency → kalau duplikat, sukses tanpa insert; kalau baru, transaksi atomic (cari/buat conversation, insert message, update conversation). `status()` (`POST /api/inbox/gateway/status`): heartbeat, upsert baris tunggal id=1.
- `app/Config/Routes.php` — 2 route baru, pakai `['filter'=>'gatewaytoken']`, bukan `'auth'`.

**Keputusan/asumsi yang diambil di luar spec eksplisit:**
- `last_message_at` diisi dari `message_timestamp` payload (waktu pesan di WhatsApp), bukan waktu server menerima — lebih akurat kalau Gateway sempat retry lama.
- Kalau conversation sudah ada dan `contact_name`/`phone` kosong tapi sekarang ada di payload, diisi; kalau sudah terisi, tidak ditimpa.
- Endpoint heartbeat dimasukkan ke Phase 2 (bukan ditunda ke Phase 4) karena satu paket alami dengan "CI4 Gateway incoming API".

### 2.1 Bug ditemukan & diperbaiki: `except` di `Config/Filters.php` tidak cukup
`[CHAT §2.8]`

**Gejala:** walau route Gateway sudah ditambahkan ke `except` filter `'auth'` (persis dokumentasi resmi CI4), request dari Gateway tetap di-redirect ke `/login` oleh `AuthFilter`, seolah `except`-nya tidak pernah dibaca. Sudah dipastikan bukan soal file belum ter-apply, bukan soal OPcache.

**Root cause pastinya belum 100% dikonfirmasi** (kemungkinan berkaitan dengan cara `service('uri')->getPath()` menghitung path pada konfigurasi subfolder deployment tertentu, dikombinasikan dengan cara CI4 mencocokkan pattern `except`), tapi fix-nya sudah terbukti jalan.

**Fix:** jangan mengandalkan `except` sama sekali untuk route Gateway. `AuthFilter::before()` diberi bypass eksplisit di baris paling awal:
```php
$uriGateway = service('uri')->getPath();
if (strpos($uriGateway, 'api/inbox/gateway/') !== false) {
    return null;
}
```
Penting: dipakai `strpos(...) !== false` ("mengandung"), **bukan** `strpos(...) === 0` ("harus diawali persis") — versi `=== 0` **terbukti gagal** di server production. Entri `except` di `Config/Filters.php` **tetap dibiarkan ada** sebagai lapis dokumentasi tambahan, tapi jangan pernah diandalkan sendirian untuk route serupa di masa depan.

---

## 3. Phase 3 — Outgoing: POS → Gateway → WhatsApp (2026-09-07)
`[CHAT §3]`

Jalur kirim balasan kasir: Browser POS → CI4 (`Inbox::kirim`) → cek Gateway usable → `POST /send` Gateway (Bearer token) → Baileys kirim ke WhatsApp → CI4 simpan `outgoing`/`sent` hanya kalau Gateway konfirmasi sukses.

Arah Bearer token **kebalikan** dari Phase 2: di Phase 2 Gateway mengirim token ke CI4; di Phase 3, **CI4 yang mengirim token ke Gateway**. Shared secret sama persis di kedua arah (kesederhanaan POC).

**Sisi Gateway:** `src/api/authMiddleware.js` (baru, `requireCI4Token`); `src/api/ci4Routes.js` (baru, route `POST /send` sengaja di path root, bukan `/api/send`, memakai `connectionManager.sendReply()` supaya tercatat juga di dashboard test Gateway).

**Sisi CI4:** `app/Config/Inbox.php` — tambah `$gatewayBaseUrl`, `$heartbeatStaleSeconds` (default 30 detik). `GatewayStatusModel::isUsable()` — dicek sebelum HTTP call, supaya kalau Gateway jelas mati, kasir langsung dapat penolakan cepat (bukan timeout HTTP penuh). `app/Controllers/Inbox.php` (baru) — `kirim()`, filter `'auth'` biasa (dipanggil browser kasir, bukan Gateway), cURL native PHP (tanpa Guzzle).

**Yang sudah diverifikasi sendiri (oleh pengembang):** sisi Gateway dijalankan & ditest langsung 5 skenario (tanpa token 401, token salah 401, `chat_id` invalid 400, teks kosong 400, WhatsApp belum connected 409) — semua sesuai ekspektasi. Sisi CI4 belum dijalankan (tidak ada PHP di environment pengembang) — dicek manual (balance kurung, alur logic, konsistensi pola cURL).

**Yang perlu dijalankan/verifikasi oleh user:** isi `.env` `inbox.gatewayBaseUrl`; test lewat `curl` (belum ada UI); cek baris baru di `aulia_inboxdb` (`direction='outgoing'`, `send_status='sent'`); cek WhatsApp customer beneran menerima; coba matikan Gateway, pastikan dapat error cepat (503).

---

## 4. Phase 4 — UI Inbox (2026-09-07)
`[CHAT §4]`

UI Inbox sebenarnya (bukan halaman test `/inbox/test`): daftar conversation, riwayat pesan bubble chat, form kirim balasan, badge status Gateway, dengan **polling sederhana** (bukan WebSocket, sesuai spec eksplisit).

**File yang dibuat/diubah:** `Inbox.php` (method baru `index()`, `apiConversations()`, `apiMessages()`, `apiGatewayStatus()`, `attachSenderNames()`); `inbox/index.php` (baru, UI 2 kolom, vanilla JS); `Routes.php` (4 route baru); `layout/main.php` (menu sidebar baru).

**Keputusan desain:** `effective_status` vs `raw_status` (lihat aturan bisnis §2.3); polling bukan WebSocket dengan interval 6/4/15 detik; tidak ada fitur di luar scope Phase 4 (assignment, close, unread-per-user, notifikasi push — semua ditunda sesuai spec awal); halaman test Phase 3 dibiarkan tetap ada untuk debugging.

**Verifikasi:** sintaks PHP dicek manual, JS dicek `node --check` — valid secara sintaks, belum dites end-to-end sungguhan (tidak ada PHP/MySQL di environment pengembang). User diminta memverifikasi: daftar conversation muncul, klik conversation, kirim balasan, terima WA baru saat halaman terbuka (bukti polling), badge Gateway berubah otomatis saat Gateway mati.

---

## 5. Perbaikan Pasca-Phase 4 (2026-09-07)
`[CHAT §5]`

### 5.1 Bug jam tidak sinkron (timezone) — diperbaiki
Gejala: jam pesan tidak sesuai jam lokal Jakarta. Penyebab: Gateway mengirim UTC (`.toISOString()`, ini **sudah benar** untuk komunikasi antar-sistem) — bug ada di sisi CI4 (`InboxGatewayApi::parseTimestamp()` memformat `DateTime` tanpa konversi ke lokal). Fix (3 tempat, semua eksplisit `new DateTimeZone('Asia/Jakarta')`, tidak bergantung ke setting timezone default server): `parseTimestamp()`, `Inbox::kirim()`, `GatewayStatusModel::isUsable()`.

### 5.2 Log Gateway diperbaiki (kualitas log, bukan bug bisnis)
Setelah komputer Gateway sleep lama, Baileys bisa error decrypt beruntun (`Bad MAC`, `MessageCounterError`) — perilaku Baileys/WhatsApp sendiri, bukan bug Gateway; sudah ditangkap `process.on('uncaughtException'/'unhandledRejection')` sejak POC awal. Update lanjutan: error yang sama juga muncul walau komputer tidak sleep, tapi chat asli tetap masuk normal — murni noise protokol internal WhatsApp multi-device. Fix final: pesan error dengan pola dikenal (`Bad MAC`, `MessageCounterError`, dll) di-log lewat `logger.debug()` (tidak masuk ring buffer dashboard) — error lain tetap tampil normal.

### 5.3 Balasan dari WhatsApp Web/HP langsung ikut disinkronkan
Lihat aturan bisnis final di `CHAT-01-aturan-bisnis-inbox.md` §5. Perubahan sisi Gateway: `connectionManager.js` — pesan `fromMe=true` (sebelumnya diabaikan) sekarang ikut di-enqueue; `incomingBuffer.js` — kolom `direction` baru (migrasi otomatis, sudah ditest langsung terhadap DB SQLite lama, data lama tidak hilang). Perubahan sisi CI4: `InboxGatewayApi::messages()` terima field `direction` opsional (default `'incoming'`, kompatibel mundur); `Inbox::attachSenderNames()` — label `"Staff (WA Web/HP)"` untuk `sent_by_user_id` NULL.

---

## 6. Perbaikan & Fitur Tambahan, Gelombang 2 (2026-09-07)
`[CHAT §6]`

### 6.1 Fix: Status WhatsApp (Stories) ikut masuk sebagai chat
Lihat aturan bisnis final §6. Fix: filter `remoteJid === 'status@broadcast'` di baris paling awal `_handleIncomingMessage()`.

### 6.2–6.3 "Chat Baru" & normalisasi nomor
Lihat aturan bisnis final §7. Implementasi: `Inbox::mulaiPercakapan()` (`POST /inbox/mulai-percakapan`), `Inbox::normalizePhoneToJid()` (baru).

### 6.4 Refactor: `kirimKeConversation()`
Logic "kirim ke Gateway lalu simpan sebagai outgoing" (sebelumnya hanya di `kirim()`) diekstrak jadi helper privat `kirimKeConversation()`, dipakai bersama `kirim()` dan `mulaiPercakapan()` — menghindari duplikasi kode.

### 6.5 Belum dikerjakan (saat itu): dukungan file/media
Sengaja belum dikerjakan sampai scoping bareng user (lihat Section 7 di bawah untuk realisasinya). Kolom `media_*` di `messages` sudah disiapkan sejak Phase 1.

---

## 7. Dukungan Media Gambar/Dokumen — Implementasi (2026-09-07/12)
`[CHAT §7]`

Aturan bisnis final ada di `CHAT-01-aturan-bisnis-inbox.md` §3. Bagian ini mencatat detail implementasi & verifikasi.

**Sisi Gateway:** `connectionManager.js` — `_handleIncomingMessage()` deteksi `imageMessage`/`documentMessage`; `buildMediaRef()` (baru) ekstrak `directPath`, `mediaKey` (base64), `mimetype`, `fileLength`, `fileSha256`, `fileName` — return `null` (pesan dilewati) kalau referensi tidak lengkap. `downloadContentFromMessage()` bawaan Baileys dipakai untuk ambil+dekripsi (signature diverifikasi langsung dari source code library ter-install, bukan ditebak). `incomingBuffer.js` — kolom `media_json` baru (migrasi otomatis). `ci4Routes.js` — endpoint baru `POST /media/download` (Bearer token, return binary atau JSON error `MEDIA_UNAVAILABLE`/410).

**Sisi CI4:** `InboxGatewayApi::messages()` — field `media` opsional untuk image/document, wajib `direct_path`+`media_key_base64` (ditolak 400 kalau tidak lengkap). `Inbox::media($messageId)` (baru) — `GET /inbox/media/(:num)`, minta Gateway ambil+dekripsi (cURL, timeout 30 detik), stream langsung ke browser.

**Verifikasi yang sudah dilakukan:** signature `downloadContentFromMessage()` dicek langsung dari source code ter-install; endpoint `/media/download` ditest 3 skenario (tanpa token 401, tipe invalid 400, referensi kosong 400). **Skenario ke-4 (unduh media sungguhan) tidak bisa ditest** di environment pengembang (tidak ada akses internet ke `mmg.whatsapp.net`) — **wajib ditest langsung oleh user**.

### 7.1 Outgoing media (kasir kirim dari POS) — SELESAI (2026-09-12)
Memakai pola referensi yang sama dengan incoming. Setelah Gateway upload file (`sendMediaMessage()`), hasilnya berisi `directPath`/`mediaKey` yang diekstrak dengan `buildMediaRef()` yang sama, dikembalikan sebagai `media_ref` di response `POST /send-media`. Alur CI4 (`Inbox::kirimMedia()`): baca file ke memory (tidak pernah ditulis ke disk CI4) → base64 → kirim ke Gateway → simpan `media_metadata` dari `media_ref` yang dikembalikan.

**Belum diverifikasi end-to-end nyata** (kirim media sungguhan dari form Inbox ke nomor WhatsApp asli) — hanya lolos `php -l`/`node --check` saat dokumen sumber ditulis.

### 7.2 Belum dikerjakan (di luar scope sesi terkait)
Jenis media lain (audio, video, sticker, lokasi, kontak — audio/video kemudian direalisasikan, lihat Section 10); preview thumbnail di daftar conversation; "Mulai chat baru" masih hanya menerima teks (kirim media hanya ke conversation yang sudah ada).

---

## 8. Hapus Percakapan — Implementasi (2026-09-12)
`[CHAT §8]`

Aturan bisnis final ada di `CHAT-01-aturan-bisnis-inbox.md` §8. Implementasi: `Inbox::hapusPercakapan($conversationId)` (`POST /inbox/percakapan/(:num)/hapus`) memanggil `ConversationModel::delete()` — `messages` ikut terhapus otomatis lewat FK `ON DELETE CASCADE` yang sudah ada sejak Phase 1 (sengaja tidak ada query DELETE terpisah).

**Status verifikasi:** **SUDAH ditest langsung oleh user (2026-09-12) dan lolos.**

**Gap terbuka (dicatat di Tahap 5, lihat aturan bisnis §8):** implementasi ini awalnya tidak punya ownership restriction sama sekali (berubah sejak Section 9 — sekarang tunduk `cekOwnership()`), namun **belum** mensyaratkan Admin-only maupun status CLOSED lebih dulu seperti yang kemudian disepakati di Tahap 5.

---

## 9. Assignment — Implementasi Awal (2026-09-12)
`[CHAT §9]`

Aturan bisnis final (setelah audit Tahap 2) ada di `CHAT-01-aturan-bisnis-inbox.md` §9. Kolom `conversations.assigned_to` sudah ada sejak migration Phase 1 — fitur ini murni memanfaatkannya, **tidak ada migration baru**.

Endpoint yang dibuat: `Inbox::ambilPercakapan()` (`POST /inbox/percakapan/(:num)/ambil`), `Inbox::lepasPercakapan()` (`POST /inbox/percakapan/(:num)/lepas`), `Inbox::cekOwnership()` (dipakai bersama `kirimKeConversation()`, `kirimMedia()`, `hapusPercakapan()`).

UI: badge nama staff penangan (biru = diri sendiri, abu-abu = staff lain) di daftar & header thread; tombol Ambil/Lepas.

**Status verifikasi saat itu:** baru lolos `php -l`. **Belum diuji end-to-end nyata** di browser dengan 2 akun berbeda — kemudian diaudit ulang & diverifikasi penuh di Tahap 2 (Section 13 dokumen ini).

---

## 10. Audio/Video Masuk — Implementasi (2026-09-12)
`[CHAT §10]`

Aturan bisnis final ada di `CHAT-01-aturan-bisnis-inbox.md` §4.

**Sisi Gateway (`connectionManager.js`):** cabang baru `audioMsg || videoMsg` sejajar dengan cabang image/document, tapi **tidak** memanggil `buildMediaRef()` (tidak butuh `directPath`/`mediaKey`) — cukup ekstrak `mimetype`/`fileLength`. Beda penting: kalau `mimetype`/`fileLength` kosong, pesan **tetap diteruskan** (tidak di-drop, beda dari image/document).

Payload ke CI4 (tidak ada perubahan kontrak field — `media` tetap object generik yang sudah ada):
```json
{
  "wa_message_id": "...", "chat_id": "...", "jid_type": "pn",
  "message_type": "audio", "direction": "incoming", "sender_jid": "...",
  "text": null, "message_timestamp": "...",
  "media": { "mimetype": "audio/ogg; codecs=opus", "file_length": 12345 }
}
```

**Sisi CI4:** cabang baru `elseif (in_array($messageType, ['audio','video'], true))` — `media` sepenuhnya opsional; `media_path`, `media_filename`, `media_sha256`, `media_metadata` selalu NULL. Tidak ada migration baru (`messages.message_type` sudah `VARCHAR(30)` tanpa constraint).

**Verifikasi yang sudah dilakukan:** `node --check` semua file Gateway; skrip simulasi baru `test/simulate-audio-video.js` lulus (audio biasa, voice note `ptt=true` tetap `audio`, video dengan/tanpa caption, MIME/ukuran kosong tidak crash, idempotency); regresi skrip simulasi lama tetap lulus; `php -l` pada file PHP yang diubah.

**Belum bisa diverifikasi (perlu dijalankan user):** idempotency dengan database sungguhan untuk audio/video spesifik (tidak dijalankan test otomatis yang menulis ke `aulia_inboxdb` live); kirim audio/voice note/video sungguhan dari HP; skenario "conversation CLOSED lalu incoming audio/video → OPEN lagi" belum ditest ulang spesifik untuk tipe ini (logic identik dengan text/image/document yang tidak diubah).

---

## 11. Customer Identity & Reconciliation — Implementasi (2026-09-12)
`[CHAT §11]`

Aturan bisnis final ada di `CHAT-01-aturan-bisnis-inbox.md` §10. Root cause & desain yang dipilih/ditolak sudah dijelaskan di sana secara business-level; bagian ini mencatat implementasi & bukti verifikasi.

**File yang diubah/dibuat:** migration `2026-09-12-000001_AddConversationIdentityReconciliation.php` (murni additive: 4 kolom nullable + 1 tabel baru + backfill `INSERT...SELECT`, tidak ada `UPDATE`/`DELETE` pada `conversations`/`messages` yang ada); `ConversationIdentityModel.php` (baru); `ConversationModel::resolveConversationId()` (baru), `findByChatId()` dirombak (lewat alias); `App\Libraries\PhoneNumber.php` (baru, diekstrak dari `normalizePhoneToJid()`, perilaku tidak diubah); `Inbox::updateCustomerProfile()` (baru); route baru `POST /inbox/percakapan/(:num)/profil`; `tests/unit/PhoneNumberTest.php` (baru).

**Verifikasi yang sudah dilakukan:** migration + `resolveConversationId()` dites langsung terhadap **MySQL sungguhan** di database disposable terpisah (`aulia_inboxdb_migrationtest`, bukan database live) — Test Case A–J **semua lulus**, termasuk skenario inti bug (`@lid` lalu `@s.whatsapp.net` nomor sama → menyatu, chat_id termutakhirkan, JID lama tetap alias). Database test dihapus setelahnya, `aulia_inboxdb` live diverifikasi ulang tidak tersentuh. `PhoneNumberTest.php` (8 test) lulus. Regression: seluruh test suite project (119 test) lulus.

**Belum bisa diverifikasi (perlu dijalankan user):**
- **Migration belum diterapkan ke `aulia_inboxdb` yang sebenarnya** — wajib dijalankan (`php spark migrate` **tanpa** flag `-g`, atau lewat `/migrasi-manual`) sebelum kode ini dipakai. **Peringatan:** jangan pakai `php spark migrate -g inbox` — flag itu memaksa SEMUA migration project (termasuk grup lain) ikut memakai koneksi `inbox`, terbukti menyebabkan error (`Table 'bonus_rule' already exists`) saat verifikasi.
- Skenario nyata "JID berubah @lid ↔ @pn, nomor sama" lewat kirim pesan sungguhan — **temuan audit lanjutan menunjukkan versi ini sebenarnya tidak menangani kasus LID-FIRST → PN-LATER yang sebenarnya** (lihat Section 12 di bawah untuk perbaikannya).
- Reconciliation untuk pasangan conversation duplikat yang sudah ada sebelumnya di `aulia_inboxdb` — perlu dicek manual apakah ada, dan diputuskan sendiri apakah perlu digabung manual (di luar scope).
- UI edit profil belum dites klik langsung di browser.

---

## 12. Revisi LID-FIRST → PN-LATER (2026-09-12)
`[CHAT §12]`

### 12.1 Temuan audit (root cause)
Setelah Section 11 selesai, ditemukan: reconciliation langkah pencocokan-nomor (versi lama) hanya bisa mencocokkan 2 conversation yang **sama-sama** sudah punya `phone` terverifikasi. Kasus nyata yang justru paling sering terjadi (LID datang duluan → conversation `phone=NULL` selamanya sesuai desain "jangan menebak dari @lid" → baru kemudian PN asli yang sama muncul) tidak pernah bisa ketemu lewat pencocokan `phone`. Akibatnya kasus yang ingin dicegah (2 conversation untuk 1 customer) tetap bisa terjadi.

### 12.2 Audit kapabilitas Baileys (sebelum memilih solusi)
Diperiksa langsung dari source code Baileys ter-install (`WA-Gateway/node_modules/baileys`, versi 6.7.24) — bukan ditebak dari dokumentasi:
1. Event `messages.upsert` **tidak** membawa mapping LID↔PN di versi protobuf ini (`remoteJidAlt`/`participantAlt` tidak didefinisikan sama sekali).
2. Mekanisme mapping yang sah: `sock.onWhatsApp(phoneJid)` — mengirim **USync query resmi ke server WhatsApp**, bukan tebakan client.
3. **Arahnya satu arah saja: PN → LID.** Tidak ada mekanisme sebaliknya (LID → PN) di versi library ini — mustahil secara teknis me-resolve nomor dari `@lid` yang datang duluan; satu-satunya jalan adalah menunggu PN asli muncul, lalu memperkaya event PN itu dengan LID terkaitnya.
4. Gateway yang query (memegang koneksi socket), tapi hanya mengirim hasil mentah (`identity_hint.lid`) ke AuliaPos sebagai metadata — business logic tetap di AuliaPos, Gateway tetap transport-only.

**Catatan kejujuran:** `sock.onWhatsApp()` **belum pernah dipanggil terhadap koneksi WhatsApp sungguhan** di lingkungan pengembangan (tidak ada akses jaringan ke server WhatsApp). Format persis nilai `lid` yang dikembalikan server **belum terverifikasi live** — kode dibuat defensif dan **wajib diverifikasi ulang** begitu ada koneksi nyata.

### 12.3 Solusi yang dipilih
**Solusi ganda:**
1. **Otomatis (best-effort):** Gateway meng-enrich setiap pesan masuk `jid_type='pn'` dengan `identity_hint.lid` (hasil `onWhatsApp()`, di-cache in-memory per nomor). AuliaPos memakainya sebagai langkah pencarian baru — kalau LID hasil query sudah dikenal sebagai conversation yang ada, chat_id PN baru ditempelkan sebagai alias. Dipilih karena ini satu-satunya bukti yang benar-benar dari server WhatsApp sendiri (bukan heuristik tebakan).
2. **Manual (fallback, karena solusi #1 belum terverifikasi live):** `Inbox::konfirmasiNomorWhatsapp()` — kasir/admin secara sadar mengisi `phone` pada conversation `@lid`, sehingga PN berikutnya otomatis tersambung lewat langkah pencocokan-nomor yang sudah ada sejak Section 11 (tidak ada logic baru).

Ditolak: tabel "LID mapping store" terpisah — `conversation_identities` yang sudah ada sejak Section 11 sudah cukup jadi peta itu.

### 12.4–12.6 Perubahan kode
**Gateway (`connectionManager.js`):** `_resolveLidForPhoneJid()` (baru, cache in-memory, non-fatal, tidak pernah dipanggil untuk `@lid`); `_handleIncomingMessage()` jadi `async`; `normalized.identityHint` baru. `incomingBuffer.js` — kolom `identity_hint_json` baru. Regresi: helper `simulateIncoming` di skrip simulasi lama harus di-`await` sekarang (semantik JS standar, bukan bug).

**AuliaPos:** `resolveConversationId()` — parameter baru `?string $knownLid`, langkah baru disisipkan sebagai langkah ke-2 (exact chat_id langkah 1, cocok nomor jadi langkah 3, buat baru jadi langkah 4); logic "tempelkan alias" diekstrak ke `attachAliasToConversation()` (DRY). `InboxGatewayApi::messages()` — `identity_hint.lid` hanya dipercaya kalau pesan itu sendiri `jid_type==='pn'`. `Inbox::konfirmasiNomorWhatsapp()` (baru) — menolak (409) kalau nomor sudah dipakai conversation lain.

Migration **tidak berubah** dari Section 11 (tidak ada kolom/tabel tambahan — `identity_hint` murni payload transient Gateway→CI4).

### 12.7 Verifikasi
Test 1–10 + skenario bonus dijalankan sebagai assertion nyata terhadap MySQL sungguhan di database disposable kedua (`aulia_inboxdb_migrationtest2`) — **semua lulus**, termasuk Test 2 (skenario inti: LID-first lalu PN-later dengan `knownLid` cocok → menyatu, chat_id termutakhirkan, histori utuh) dan Test 6/7/8 (LID tanpa mapping/manual_phone/nama sama tidak pernah memicu merge). `test/simulate-identity-hint.js` (Gateway, `onWhatsApp()` di-mock) 8/8 lulus. Regresi & seluruh test suite (119 test) lulus.

**Belum bisa diverifikasi (perlu dijalankan user):**
- `sock.onWhatsApp()` belum pernah dipanggil terhadap server WhatsApp sungguhan — **jangan mengklaim "LID↔PN resolved" sampai benar-benar dicoba dengan koneksi live**.
- Migration Section 11 (termasuk kebutuhan revisi ini) masih belum diterapkan ke `aulia_inboxdb` sebenarnya.
- UI "Konfirmasi Nomor" belum diklik langsung di browser.

---

## 13. Tahap 2 — Audit Assignment (2026-09-13)
`[CHAT §13]`

Audit ulang implementasi assignment (Section 9) terhadap business rule resmi — menemukan & memperbaiki 2 gap nyata, **tanpa** membuat mekanisme/tabel/permission baru. Tidak ada migration baru.

### 13.1 Gap yang ditemukan & diperbaiki
1. **Race condition di `ambilPercakapan()`** — versi lama: `find()` baca `assigned_to`, cek di PHP, baru `update()` terpisah; dua request nyaris bersamaan bisa sama-sama lolos pengecekan "masih NULL". **Fix:** satu statement `UPDATE ... WHERE id=? AND assigned_to IS NULL` (non-admin) / tanpa syarat tambahan (admin) — MySQL mengunci baris per-statement UPDATE, jadi kalau 2 request bentrok cuma SATU yang benar-benar mengubah baris. Dideteksi lewat `affectedRows()===0` (kalah race) vs `===1` (menang). Ditambah short-circuit idempotent kalau `assigned_to` sudah sama dengan user itu sendiri.
2. **Bug perbandingan tipe data di UI** — `conv.assigned_to === currentUserId` (JS strict equality) selalu `false` karena `assigned_to` dari MySQLi/JSON berupa string ("3") sedangkan `currentUserId` berupa number (3) — root cause sama persis dengan bug `id` yang pernah diperbaiki sebelumnya (`cariConversation()`). Akibatnya tombol "Lepas" tidak pernah muncul untuk staff pemilik asli. **Fix:** `String(conv.assigned_to) === String(currentUserId)`.

### 13.2 Verifikasi
Test A–K (persis acceptance test Tahap 2) dijalankan sebagai assertion nyata terhadap MySQL disposable (`aulia_inboxdb_migrationtest5`) — semua lulus, termasuk Test J (race condition: 2 percobaan "Ambil" bersamaan, tepat 1 berhasil) dan Test K (admin override). `php -l`/`node --check`; regresi seluruh test suite (119 test) lulus.

**Belum bisa diverifikasi (perlu dijalankan user):** klik nyata "Ambil"/"Lepas" di browser dengan 2 akun berbeda (termasuk race condition sungguhan — 2 device/tab hampir bersamaan); tampilan badge di layar sungguhan.

---

## 14. Tahap 3 — Audit Integrasi Open/Closed × Assignment (2026-09-13)
`[CHAT §14]`

Murni **audit + verifikasi integrasi** antara status lifecycle (Section 11 dalam file aturan bisnis) dan assignment (Section 9/13) — **tidak ditemukan gap baru, tidak ada perubahan kode**. Kedua fitur (dikerjakan terpisah sebelumnya) ternyata sudah memenuhi seluruh rule integrasi karena masing-masing endpoint sudah didesain untuk hanya menyentuh kolom miliknya sendiri sejak awal.

### 14.1 Verifikasi
Test A–R (18 skenario, persis acceptance test Tahap 3) dijalankan sebagai assertion nyata terhadap MySQL disposable (`aulia_inboxdb_migrationtest6`) — **semua lulus (18/18)**, termasuk kombinasi CLOSED+ASSIGNED/CLOSED+UNASSIGNED × incoming/outgoing sync, race condition Ambil, filter Open/Closed dengan data campuran. Audit source code penuh (`InboxGatewayApi.php`, `Inbox.php`, `inbox/index.php`) dikonfirmasi tidak ada satu baris pun yang melanggar pemisahan tanggung jawab kolom. Regresi seluruh test suite (119 test) lulus. UI diverifikasi lewat **audit pembacaan logic render** (bukan E2E klik browser).

**Belum bisa diverifikasi (perlu dijalankan user):** seluruh skenario A–R **belum diklik langsung di browser sungguhan** (mis. 2 device kirim WA asli ke conversation yang sama sambil staff menutupnya nyaris bersamaan) — semua kelulusan di atas hasil query/assertion langsung ke MySQL, bukan interaksi HTTP/UI nyata.

---

## 15. Status Verifikasi Keseluruhan & Gap Terbuka

Ringkasan status per fitur, ditarik dari catatan verifikasi di seluruh dokumen sumber:

| Fitur | Diverifikasi lewat | Belum diverifikasi |
|---|---|---|
| Fondasi DB, incoming, outgoing teks (Phase 1–3) | Test manual + end-to-end sungguhan (dikonfirmasi user) | — |
| UI Inbox (Phase 4) | Sintaks saja | Klik langsung di browser oleh user |
| Media gambar/dokumen — incoming | 3 skenario endpoint | Unduh media sungguhan dari server WhatsApp |
| Media gambar/dokumen — outgoing | `php -l`/`node --check` saja | End-to-end kirim media sungguhan |
| Audio/video masuk | Simulasi otomatis lengkap | Kirim sungguhan dari HP; idempotency di DB live |
| Hapus percakapan | **Ditest langsung user, lolos** | Aturan Admin-only + wajib CLOSED (Tahap 5) belum ditegakkan kode |
| Assignment (Tahap 2 audit) | Assertion DB disposable lengkap | Klik nyata 2 akun berbeda di browser |
| Customer identity/reconciliation (Section 11) | Assertion DB disposable | Migration belum diterapkan ke DB live; kirim pesan sungguhan skenario LID/PN |
| Revisi LID-FIRST→PN-LATER (Section 12) | Assertion DB disposable + mock | `sock.onWhatsApp()` belum pernah dipanggil ke server WhatsApp sungguhan |
| Integrasi Open/Closed × Assignment (Tahap 3) | Assertion DB disposable, audit source | Belum E2E browser sungguhan |
| Unread/Read (Tahap 5) | — (baru disepakati) | **Belum diimplementasikan sama sekali** |

**Prasyarat migration yang wajib dijalankan sebelum fitur terkait dipakai** (per tanggal dokumen sumber):
1. Migration Phase 1 (`aulia_inboxdb`) — sudah dikonfirmasi jalan.
2. Migration `2026-09-12-000001_AddConversationIdentityReconciliation.php` — **belum diterapkan ke database live**, wajib dijalankan sebelum fitur identity reconciliation (Section 11–12) dipakai. **Jangan** pakai `php spark migrate -g inbox` (flag `-g` memaksa semua migration project ikut koneksi `inbox` dan terbukti menyebabkan error di grup lain) — gunakan `php spark migrate` polos atau `/migrasi-manual`.
