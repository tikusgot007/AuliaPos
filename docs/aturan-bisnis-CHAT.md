# Dokumentasi Modul Shared WhatsApp Inbox (Chat) — AuliaPos v3.x (ARSIP)

> **STATUS: DIGANTIKAN (2026-09-13).** Bacaan utama untuk aturan bisnis
> Chat/Inbox WhatsApp sekarang ada di:
> - `docs/CHAT-01-aturan-bisnis-inbox.md` — aturan bisnis per-topik (identity, media, assignment, Open/Closed x Assignment, Unread/Read, hak akses)
> - `docs/CHAT-CHANGELOG.md` — riwayat implementasi per fase/tahap, audit teknis, catatan verifikasi
>
> File ini **TETAP DIPERTAHANKAN APA ADANYA** (tidak dihapus, tidak
> direnumbering) karena banyak komentar di kode (`app/**`) mengutip
> nomor section-nya secara langsung (mis. "lihat
> docs/aturan-bisnis-CHAT.md Section 11"), dan kedua file pengganti di
> atas juga merujuk balik ke nomor section di sini lewat notasi
> `[CHAT §N]`. Jangan ubah/hapus/renumbering isi di bawah ini — kalau
> ada perbaikan/tahap baru, lakukan di file pengganti (tambahkan
> section baru di sana), bukan di sini.

Dokumen ini adalah dokumentasi **khusus modul Chat/Inbox WhatsApp**,
dipisah dari `docs/aturan-bisnis-AULIA.md` (aturan bisnis AuliaPos
inti/v2.x) supaya kedua jalur pengembangan (v2.x dan v3.x) tidak
"berebut" bagian dokumentasi yang sama.

Untuk aturan bisnis AuliaPos inti (transaksi, kasir, laporan, jadwal,
dll — semua yang TIDAK berkaitan dengan Chat), lihat
`docs/aturan-bisnis-AULIA.md`.

Penomoran section di sini **mandiri** (mulai dari 1), tidak
menyambung ke penomoran `aturan-bisnis-AULIA.md`.

---

# 1. Shared WhatsApp Inbox — Phase 1: Fondasi Database (2026-09-07)

## 1.1 Apa ini

Module baru: Shared WhatsApp Inbox — kasir bisa balas chat WhatsApp
customer langsung dari AuliaPos, terhubung ke Gateway WhatsApp
terpisah (Node.js/Baileys) yang jalan di komputer lain di LAN toko.
Dikerjakan bertahap (4 phase), ini laporan **Phase 1 saja**: fondasi
database. Belum ada API endpoint, belum ada UI, belum menyentuh
Gateway sama sekali.

## 1.2 Keputusan arsitektur inti

- **Database benar-benar terpisah**: `aulia_inboxdb`, database baru,
  bukan tabel tambahan di `aulia_kasirdb`. Koneksi default AuliaPos
  tidak disentuh sama sekali.
- **Tidak ada tabel `users` baru.** Identitas kasir tetap dari
  `aulia_kasirdb.users` yang sudah ada. Kolom `assigned_to`,
  `last_replied_by`, `closed_by` (di `conversations`) dan
  `sent_by_user_id` (di `messages`) adalah **logical reference** ke
  `users.id` — BUKAN foreign key database sungguhan (MySQL tidak
  aman untuk FK lintas database, dan ini juga permintaan eksplisit
  di spec). Kalau perlu nama user dari ID ini, ambil lewat
  `UserModel` (koneksi default) secara terpisah, gabungkan di
  controller/view — tidak bisa di-JOIN langsung.
- **Gateway TIDAK menyimpan business state.** SQLite di sisi Gateway
  (Phase 2) murni reliability buffer untuk retry pengiriman event,
  bukan sumber kebenaran.

## 1.3 File yang dibuat/diubah

- `app/Config/Database.php` — tambah connection group baru `$inbox`,
  mengikuti pola persis `$default` (kredensial kosong, di-override
  lewat `.env` di server: `database.inbox.hostname`,
  `database.inbox.username`, `database.inbox.password`,
  `database.inbox.database`). Connection `$default` AuliaPos tidak
  diubah sama sekali.
- `app/Database/Migrations/2026-09-07-000001_CreateInboxTables.php`
  — migration `$DBGroup = 'inbox'` (otomatis jalan ke database yang
  benar saat `php spark migrate`, atau lewat halaman Migrasi Manual
  yang sudah dibuat sebelumnya di `/migrasi-manual`). Membuat 3
  tabel: `conversations`, `messages`, `gateway_status`, skema persis
  sesuai spec, termasuk kolom `media_*` di `messages` yang disiapkan
  untuk fitur masa depan (image/document/dst) tapi belum dipakai
  (POC ini hanya `message_type='text'`).
- `app/Models/ConversationModel.php`, `MessageModel.php`,
  `GatewayStatusModel.php` — ketiganya eksplisit
  `protected $DBGroup = 'inbox';`, tidak pernah mengandalkan default
  group, supaya mustahil salah nyambung ke `aulia_kasirdb`.
  `MessageModel` sengaja `$updatedField = ''` (tabel tidak punya
  `updated_at`), `GatewayStatusModel` sengaja `$createdField = ''`
  (tabel tidak punya `created_at`, cuma `updated_at`, karena baris
  id=1 di-upsert terus-menerus oleh heartbeat).

## 1.4 Prasyarat sebelum migration bisa jalan

Migration ini **tidak membuat database-nya sendiri** (Forge cuma
bisa `CREATE TABLE`, bukan `CREATE DATABASE`). Sebelum
`php spark migrate` / halaman Migrasi Manual dijalankan:

1. Buat database kosong `aulia_inboxdb` secara manual di server
   (`CREATE DATABASE aulia_inboxdb CHARACTER SET utf8mb4 COLLATE
   utf8mb4_general_ci;`).
2. Isi kredensial koneksi `inbox` di `.env` server (lihat contoh di
   28.3).

## 1.5 Status per Phase

- Phase 1 (fondasi database) — **selesai**, migration sudah
  dikonfirmasi jalan di server.
- Phase 2 (incoming: Gateway → CI4) — **selesai & tervalidasi
  end-to-end sungguhan** (2026-09-07): kirim WA asli → tersimpan di
  `aulia_inboxdb.messages`/`conversations`, heartbeat
  `gateway_status` update tiap ~15 detik. Lihat Section 2, termasuk
  29.8 (bug `except`/`AuthFilter` yang ditemukan & diperbaiki saat
  proses testing ini).
- Phase 3 (outgoing: POS → Gateway → WhatsApp) — **selesai &
  tervalidasi end-to-end sungguhan** (2026-09-07), termasuk lewat
  halaman test sementara `/inbox/test`. Lihat Section 3.
- Phase 4 (UI Inbox, polling, tampilan status Gateway) — **selesai**,
  menunggu verifikasi kamu di server sungguhan. Lihat Section 4.

---

# 2. Shared WhatsApp Inbox — Phase 2: Incoming (Gateway → CI4) (2026-09-07)

## 2.1 Apa yang dikerjakan

Jalur **pesan masuk** end-to-end: Customer kirim WA → Gateway
(Baileys) terima → disimpan dulu ke SQLite lokal Gateway (reliability
buffer) → worker Gateway push ke CI4 lewat HTTP → CI4 simpan ke
`aulia_inboxdb` → ACK ke Gateway → Gateway tandai selesai. Idempotent
berdasarkan `wa_message_id` di kedua sisi. Sekaligus heartbeat status
Gateway → CI4 (`gateway_status`).

**Belum ada UI** untuk melihat hasilnya di POS (itu Phase 4) — Phase
2 ini murni jalur data machine-to-machine, diverifikasi lewat
query database langsung / test manual (lihat 29.6).

## 2.2 Sisi CI4 — file baru/diubah

- **`app/Config/Filters.php`** (diubah) — filter session `'auth'`
  ternyata meng-cover `api/*` secara umum (termasuk semua endpoint
  AJAX POS yang sudah ada). Endpoint Gateway baru
  (`api/inbox/gateway/*`) **dikecualikan** dari situ, karena Gateway
  (proses Node.js terpisah) tidak pernah punya session/cookie login.
- **`app/Filters/GatewayTokenFilter.php`** (baru) — filter Bearer
  token khusus endpoint Gateway, dipasang lewat alias
  `'gatewaytoken'` di route (bukan `'auth'`). Bandingkan token pakai
  `hash_equals()` (timing-safe). Kalau token belum dikonfigurasi
  sama sekali di server (`.env` kosong), SEMUA request ditolak (fail
  closed, bukan fail open).
- **`app/Config/Inbox.php`** (baru) — baca `inbox.gatewayToken` dari
  `.env`. Tidak pernah di-hardcode di kode.
- **`app/Controllers/InboxGatewayApi.php`** (baru) — 2 method:
  - `messages()` — endpoint `POST /api/inbox/gateway/messages`.
    Validasi minimal → cek idempotency (`existsByWaMessageId`) →
    kalau duplikat, return sukses tanpa insert apa pun → kalau baru,
    transaksi atomic: cari-atau-buat conversation, insert message,
    update conversation (status selalu `open` lagi, `last_message_at`,
    `last_message_direction='incoming'`).
  - `status()` — endpoint `POST /api/inbox/gateway/status`
    (heartbeat). Upsert baris tunggal `gateway_status` id=1.
- **`app/Config/Routes.php`** (diubah) — 2 route baru, keduanya pakai
  `['filter' => 'gatewaytoken']`, BUKAN `'auth'`.

### Keputusan/asumsi yang saya ambil (di luar spec eksplisit)

- **`last_message_at` diisi dari `message_timestamp` payload**
  (waktu pesan itu sendiri terjadi di WhatsApp), bukan waktu server
  CI4 menerima request. Ini lebih akurat kalau Gateway sempat retry
  lama (Test 3: CI4 mati, pesan baru masuk setelah CI4 hidup lagi) —
  urutan percakapan tetap merefleksikan kapan pesan itu benar-benar
  terjadi.
- Kalau conversation sudah ada dan `contact_name`/`phone` sebelumnya
  kosong tapi sekarang ada di payload, kolom itu diisi. Kalau
  sebelumnya sudah terisi, TIDAK ditimpa (jaga-jaga untuk fitur masa
  depan di mana kasir bisa edit nama kontak manual).
- Endpoint heartbeat (`status()`) saya masukkan ke Phase 2 (bukan
  ditunda ke Phase 4) karena secara alami satu paket dengan "CI4
  Gateway incoming API" — Phase 4 hanya perlu MENAMPILKAN data yang
  sudah dikumpulkan endpoint ini, bukan membuat endpoint barunya.

## 2.3 Sisi Gateway (Node.js) — file baru/diubah

- **`src/config/index.js`** (diubah) — tambah config `ci4.baseUrl`,
  `ci4.gatewayToken`, `ci4.requestTimeoutMs`, `sqlitePath`,
  `deliveryIntervalMs`, `deliveryRetry.*`, `heartbeatIntervalMs`.
- **`src/store/incomingBuffer.js`** (baru) — SQLite reliability
  buffer (better-sqlite3), tabel `incoming_queue` lokal (BUKAN
  source of truth). `enqueue()` idempotent lewat
  `UNIQUE(wa_message_id)` + `INSERT OR IGNORE`. Backoff retry per
  event tersimpan di `next_attempt_at`.
- **`src/delivery/ci4Client.js`** (baru) — helper HTTP POST + Bearer
  token + timeout ke CI4, dipakai bersama oleh incoming delivery dan
  heartbeat (hindari duplikasi kode).
- **`src/delivery/incomingDelivery.js`** (baru) — worker interval
  (`deliveryIntervalMs`, default 5 detik) yang ambil event pending
  dari SQLite lalu POST ke CI4. Sukses → `markCompleted`. Gagal →
  `markFailedAttempt` (backoff makin lama), event TETAP di SQLite,
  dicoba lagi siklus berikutnya.
- **`src/delivery/heartbeat.js`** (baru) — worker interval
  (`heartbeatIntervalMs`, default 15 detik) push status Gateway ke
  CI4. SENGAJA TIDAK masuk SQLite queue (heartbeat = state saat ini,
  bukan event yang harus dijamin sampai — heartbeat basi tidak ada
  gunanya di-retry, cukup tunggu siklus berikutnya).
- **`src/whatsapp/connectionManager.js`** (diubah) — di
  `_handleIncomingMessage()`, pesan yang `fromMe=false` (asli dari
  customer) di-`enqueue()` ke SQLite buffer. Pesan `fromMe=true`
  (sinkronisasi dari device lain yang login ke akun WA yang sama,
  mis. staff balas dari HP-nya sendiri) SENGAJA TIDAK diteruskan ke
  CI4 sebagai "incoming" — itu akan salah tercatat seolah dari
  customer. Tetap masuk `messageStore` lokal seperti sebelumnya
  (behavior dashboard existing tidak berubah).
- **`src/app/index.js`** (diubah) — start & stop
  `incomingDelivery`/`heartbeat` di lifecycle startup/shutdown
  gateway.
- **`package.json`** — tambah dependency `better-sqlite3`.
- **`.env.example`** — dokumentasi semua env var baru.

## 2.4 Prasyarat sebelum Phase 2 bisa dites end-to-end

1. Phase 1 sudah jalan (`aulia_inboxdb` ada & bermigrasi — **sudah
   dikonfirmasi**).
2. Isi `.env` CI4 (server): `inbox.gatewayToken = <string acak yang
   sama persis dengan Gateway>`.
3. Isi `.env` Gateway: `CI4_BASE_URL=http://<ip-server-aulia>/aulia`
   (atau URL sesuai deployment), `CI4_GATEWAY_TOKEN=<token sama
   persis>`.
4. Jalankan `npm install` di folder Gateway (menambah
   `better-sqlite3`).
5. Restart Gateway.

## 2.5 Yang SUDAH saya verifikasi sendiri (bukan cuma baca kode)

Environment saya tidak punya PHP/MySQL, jadi sisi CI4 belum saya
jalankan sungguhan (sama seperti Phase 1). Tapi sisi Gateway
(Node.js) BISA saya jalankan di sini, dan sudah saya test nyata:
- Semua file baru berhasil di-`require()` tanpa error.
- `npm install` sukses (`better-sqlite3` terpasang, ada prebuilt
  binary, tidak perlu compile manual).
- Siklus penuh SQLite buffer saya test langsung: enqueue pesan →
  duplikat dengan `wa_message_id` sama diabaikan (idempotent,
  pending tetap 1, bukan 2) → ambil due events → simulasi gagal
  kirim (`markFailedAttempt`, retry terjadwal 3000ms sesuai
  konfigurasi) → simulasi sukses (`markCompleted`, pending kembali
  0). Semua hasilnya sesuai ekspektasi.

## 2.6 Yang PERLU kamu jalankan/verifikasi (sisi CI4 & end-to-end asli)

Sesuai 7 acceptance test di spec awal, untuk Phase 2 minimal test
**TEST 1** dan **TEST 2** sudah relevan:

1. Set `.env` di kedua sisi (lihat 29.4), restart Gateway, scan QR
   kalau perlu login WA.
2. Kirim WhatsApp "Halo" dari HP lain ke nomor toko.
3. Cek tabel `aulia_inboxdb.messages` dan `aulia_inboxdb.conversations`
   — harus ada 1 baris baru masing-masing.
4. Kirim ulang wa_message_id yang sama secara manual (simulasi
   `curl` ke `/api/inbox/gateway/messages` dengan `wa_message_id`
   yang sama persis) — pastikan `messages` **tetap 1 baris**, bukan 2
   (TEST 2).
5. Matikan CI4 (atau salah konfigurasi URL sengaja), kirim pesan WA
   baru, cek Gateway tetap hidup & tidak crash, cek file
   `data/gateway.sqlite` (bisa dibuka pakai DB Browser for SQLite)
   ada baris `status='pending'` atau `'failed'`. Hidupkan CI4 lagi,
   tunggu beberapa detik, cek baris itu berubah jadi `'completed'`
   dan pesannya sudah masuk `aulia_inboxdb` (TEST 3).
6. Cek tabel `aulia_inboxdb.gateway_status` — `last_heartbeat_at`
   harus terus ter-update tiap ~15 detik selagi Gateway hidup
   (TEST 7).

## 2.7 Error/risiko yang masih ada

- Belum ada cara MELIHAT hasil ini dari UI AuliaPos sama sekali
  (Phase 4) — verifikasi Phase 2 murni lewat database/log/curl.
- Kalau `.env` CI4 dan Gateway tidak diisi TOKEN yang identik persis
  (typo/whitespace), semua request Gateway akan ditolak 401 — pesan
  error di log Gateway (`[DELIVERY] gagal meneruskan pesan masuk ke
  CI4`) akan menyebutkan ini, tapi tetap perlu pengecekan manual
  kalau terjadi.
- `CI4_BASE_URL` harus benar-benar bisa diakses dari komputer
  Gateway (biasanya komputer berbeda di LAN) — kalau CI4 di-bind ke
  `127.0.0.1` saja di web server-nya, Gateway di komputer lain tidak
  akan bisa menjangkaunya walau token benar.

## 2.8 Bug ditemukan & diperbaiki saat testing: `except` di Config/Filters.php TIDAK cukup

**Gejala**: walau route `api/inbox/gateway/*` sudah ditambahkan ke
`except` filter `'auth'` di `Config/Filters.php` (persis sesuai
dokumentasi resmi CI4), request dari Gateway tetap di-redirect ke
`/login` (HTTP 303) oleh `AuthFilter`, seolah `except`-nya tidak
pernah dibaca sama sekali. Sudah dipastikan BUKAN soal file belum
ter-apply (dicek langsung isi file di server) dan BUKAN soal OPcache
(sudah dicoba restart Apache penuh, hasil tetap sama).

**Root cause pastinya belum 100% dikonfirmasi** (kemungkinan
berkaitan dengan bagaimana `service('uri')->getPath()` menghitung
path pada konfigurasi subfolder deployment tertentu, dikombinasikan
dengan cara CI4 mencocokkan pattern `except`), tapi **fix-nya sudah
terbukti jalan**.

**Fix**: jangan mengandalkan `except` di `Config/Filters.php` sama
sekali untuk route Gateway. Sebagai gantinya, `AuthFilter::before()`
sekarang punya bypass EKSPLISIT di baris paling awal method:

```php
$uriGateway = service('uri')->getPath();
if (strpos($uriGateway, 'api/inbox/gateway/') !== false) {
    return null;
}
```

Poin penting: dipakai `strpos(...) !== false` ("mengandung", dicek
di mana saja dalam string), BUKAN `strpos(...) === 0` ("harus
diawali persis dari karakter pertama"). Versi `=== 0` **terbukti
gagal** di server production (kemungkinan `getPath()` mengembalikan
path dengan sesuatu di depannya yang tidak diduga), sedangkan versi
`!== false` terbukti jalan.

**Entri di `except` array Config/Filters.php TETAP dibiarkan ada**
(tidak dihapus) sebagai lapis dokumentasi/pertahanan tambahan yang
tidak merugikan, tapi jangan pernah mengandalkan itu SAJA untuk route
serupa di masa depan — selalu tambahkan bypass eksplisit langsung di
`AuthFilter.php` seperti pola di atas.

---

# 3. Shared WhatsApp Inbox — Phase 3: Outgoing (POS → Gateway → WhatsApp) (2026-09-07)

## 3.1 Apa yang dikerjakan

Jalur **kirim balasan** dari kasir: Browser POS → CI4 (`Inbox::kirim`)
→ cek Gateway usable → panggil `POST /send` milik Gateway (Bearer
token) → Baileys kirim ke WhatsApp → Gateway balas hasil → CI4
**baru** menyimpan sebagai `outgoing`/`sent` ke `aulia_inboxdb` kalau
Gateway konfirmasi sukses.

Arah Bearer token sekarang **kebalikan** dari Phase 2: di Phase 2,
Gateway yang mengirim token ke CI4; di Phase 3, **CI4 yang mengirim
token ke Gateway**. Shared secret yang dipakai **sama persis**
(`inbox.gatewayToken` di CI4 = `CI4_GATEWAY_TOKEN` di Gateway) --
satu token dipakai kedua arah, sesuai prinsip kesederhanaan POC.

## 3.2 Sisi Gateway — file baru/diubah

- **`src/api/authMiddleware.js`** (baru) — middleware Express
  `requireCI4Token`, validasi header `Authorization: Bearer <token>`
  dari CI4 terhadap `config.ci4.gatewayToken`.
- **`src/api/ci4Routes.js`** (baru) — route `POST /send`. SENGAJA
  dipasang di path **root** (`/send`, bukan `/api/send`), sesuai
  spec, terpisah dari router `/api/*` yang tidak pakai auth (dashboard
  test lokal). Memakai `connectionManager.sendReply()` (bukan
  `sendTextMessage()` langsung) supaya pesan yang dikirim dari POS
  JUGA tercatat di `messageStore` lokal Gateway -- dashboard test
  Gateway tetap konsisten menampilkan semua pesan keluar dari mana
  pun asalnya.
- **`src/api/server.js`** (diubah) — pasang `ci4Routes` di path root.

## 3.3 Sisi CI4 — file baru/diubah

- **`app/Config/Inbox.php`** (diubah) — tambah `$gatewayBaseUrl`
  (alamat Gateway, diisi lewat `.env`: `inbox.gatewayBaseUrl`, contoh
  `http://192.168.1.20:3000`) dan `$heartbeatStaleSeconds` (default
  30 detik).
- **`app/Models/GatewayStatusModel.php`** (diubah) — method baru
  `isUsable()`: true hanya kalau baris `gateway_status` ada,
  `status='connected'`, DAN heartbeat terakhir belum lebih basi dari
  `heartbeatStaleSeconds`. Dicek SEBELUM mencoba HTTP call ke Gateway,
  supaya kalau Gateway jelas-jelas mati, kasir langsung dapat
  penolakan cepat -- tidak menunggu timeout HTTP penuh (sesuai spec:
  "Gateway offline => reject segera").
- **`app/Controllers/Inbox.php`** (baru) — `Inbox::kirim()`, endpoint
  `POST /inbox/kirim` (session-authenticated, filter `'auth'` biasa,
  BUKAN `'gatewaytoken'` -- ini dipanggil browser kasir, bukan
  Gateway). Terima `conversation_id` + `text` dari form/AJAX.
  Panggil Gateway lewat cURL native PHP (tidak pakai Guzzle/library
  tambahan, supaya tidak bergantung ke dependency yang belum tentu
  ter-install).
- **`app/Config/Routes.php`** (diubah) — route baru
  `POST /inbox/kirim`.

## 3.4 Keputusan penting soal kegagalan kirim

Sesuai spec ("jangan menganggap message sent", "tidak boleh membuat
outgoing queue"): kalau Gateway gagal/menolak di titik manapun,
**TIDAK ADA APAPUN yang disimpan ke `aulia_inboxdb`** -- bukan cuma
"tidak ditandai sent", tapi memang tidak ada baris `messages` baru
sama sekali untuk percobaan yang gagal. Browser langsung dapat pesan
error, kasir bisa coba klik kirim lagi kalau mau (retry manual oleh
manusia, bukan retry otomatis sistem -- sesuai "tidak boleh membuat
outgoing queue").

Ini keputusan desain saya sendiri (spec tidak eksplisit melarang
menyimpan record 'failed' walau kolom `send_status` punya nilai
`'failed'` di skema) -- saya pilih pendekatan paling sederhana &
paling sesuai larangan eksplisit di spec, daripada menambah
kompleksitas (mis. wa_message_id palsu untuk record yang gagal)
tanpa ada instruksi jelas untuk itu.

## 3.5 Interface `conversation_id` vs `chat_id`

Endpoint `/inbox/kirim` sengaja menerima **`conversation_id`**
(primary key tabel `conversations`), BUKAN `chat_id` (JID WhatsApp
mentah) -- browser/UI Phase 4 nanti akan bekerja berbasis daftar
conversation, bukan JID mentah. Controller yang menerjemahkan
`conversation_id` → `chat_id` sebelum memanggil Gateway.

## 3.6 Yang SUDAH saya verifikasi sendiri

Sisi Gateway (Node.js) saya jalankan & test langsung 5 skenario:
tanpa token (401), token salah (401), `chat_id` invalid (400), teks
kosong (400), dan WhatsApp belum connected (409) -- semua sesuai
ekspektasi persis.

Sisi CI4 belum saya jalankan (tidak ada PHP di environment saya) --
sudah dicek manual (balance kurung, alur logic, konsistensi pola
`cURL` dengan gaya kode AuliaPos lain).

## 3.7 Yang PERLU kamu jalankan/verifikasi

1. Isi `.env` CI4: `inbox.gatewayBaseUrl = http://<ip-komputer-gateway>:3000`
   (port sesuai `PORT` di `.env` Gateway kamu, defaultnya 3000).
2. Restart Apache (jaga-jaga, walau seharusnya tidak perlu untuk
   perubahan config env biasa).
3. Test lewat `curl` dulu (belum ada UI, Phase 4) -- dari komputer
   mana pun yang bisa akses server AuliaPos:
   ```
   curl -i -X POST http://localhost/aulia/inbox/kirim ^
     -H "Cookie: ci_session=<isi dari cookie browser kamu yang sudah login>" ^
     -d "conversation_id=1&text=Test balasan dari POS"
   ```
   (Perlu cookie session yang valid karena endpoint ini
   session-authenticated -- ambil dari DevTools browser setelah
   login ke AuliaPos, atau lebih gampang tunggu Phase 4 untuk test
   lewat UI langsung.)
4. Cek `messages` di `aulia_inboxdb` -- harus ada baris baru
   `direction='outgoing'`, `send_status='sent'`.
5. Cek WhatsApp customer beneran menerima pesannya (TEST 4 di
   acceptance test spec).
6. Coba matikan Gateway, ulangi test -- harus dapat error cepat
   (503), bukan menggantung lama (TEST 5).

---

# 4. Shared WhatsApp Inbox — Phase 4: UI Inbox (2026-09-07)

## 4.1 Apa yang dikerjakan

UI Inbox yang sebenarnya (bukan lagi halaman test `/inbox/test`
seadanya): daftar conversation, riwayat pesan per conversation (gaya
bubble chat, bedakan incoming/outgoing), form kirim balasan, badge
status Gateway (Terhubung/Menghubungkan/Terputus), dengan **polling
sederhana** (bukan WebSocket, sesuai spec) untuk update pesan
masuk/daftar conversation/status Gateway tanpa refresh manual.

## 4.2 File yang dibuat/diubah

- **`app/Controllers/Inbox.php`** (diubah) — method baru:
  - `index()` — `GET /inbox`, halaman utama, render data awal
    (conversation list + status Gateway) langsung dari server untuk
    first-paint cepat.
  - `apiConversations()` — `GET /inbox/api/conversations`, JSON
    daftar conversation, dipoll tiap 6 detik.
  - `apiMessages($conversationId)` — `GET
    /inbox/api/conversations/(:num)/messages`, JSON riwayat pesan 1
    conversation, dipoll tiap 4 detik SELAMA ada conversation yang
    sedang dibuka.
  - `apiGatewayStatus()` — `GET /inbox/api/gateway-status`, JSON
    status Gateway (pakai `effective_status`, lihat 33.3), dipoll
    tiap 15 detik (selaras interval heartbeat Gateway).
  - `attachSenderNames()` (private) — contoh nyata pola "logical
    reference ke users.id" yang didokumentasikan sejak Phase 1:
    ambil nama user dari `UserModel` (koneksi default) secara
    terpisah per request, gabungkan ke data message di PHP -- BUKAN
    lewat JOIN SQL (mustahil, beda database).
  - `kirim()` (diubah) — respons sukses sekarang menyertakan data
    message yang baru dibuat (termasuk `sender_name`), supaya UI
    bisa langsung menampilkannya di thread tanpa menunggu siklus
    polling berikutnya (sesuai spec: "outgoing langsung terlihat
    setelah sukses").
- **`app/Views/inbox/index.php`** (baru) — UI 2 kolom (daftar chat |
  riwayat pesan), vanilla JS (tanpa framework tambahan, konsisten
  dengan pola AuliaPos yang sudah ada), reuse `showToast()` global
  untuk notifikasi error.
- **`app/Config/Routes.php`** (diubah) — 4 route baru (`/inbox` +
  3 endpoint API).
- **`app/Views/layout/main.php`** (diubah) — menu sidebar baru
  "Inbox WhatsApp".

## 4.3 Keputusan desain

- **`effective_status` vs `raw_status`**: kalau baris `gateway_status`
  bilang `status='connected'` tapi `last_heartbeat_at` sudah lebih
  basi dari `heartbeatStaleSeconds` (lihat Phase 3,
  `GatewayStatusModel::isUsable()`), UI menampilkan badge
  **"Terputus"**, BUKAN "Terhubung" yang menyesatkan -- kasir tidak
  boleh mengira Gateway hidup padahal sebenarnya sudah mati tanpa
  sempat lapor status terakhirnya.
- **Polling, bukan WebSocket** -- sesuai spec eksplisit ("jangan
  langsung memperumit dengan WebSocket"). Interval dipilih supaya
  terasa cukup responsif tanpa membebani server: daftar conversation
  6 detik, pesan dalam thread aktif 4 detik, status Gateway 15 detik
  (selaras heartbeat).
- **Tidak ada fitur di luar scope Phase 4**: tidak ada assignment/
  take conversation (~~sudah dibangun kemudian, lihat §9~~), tidak ada
  close conversation, tidak ada unread-per-user, tidak ada notifikasi
  push -- semua sengaja ditunda sesuai daftar "JANGAN IMPLEMENTASI
  DULU" di spec awal.
- **`/inbox/test` (halaman test Phase 3) dibiarkan tetap ada**, tidak
  dihapus -- tidak mengganggu apa pun, dan masih berguna untuk
  debugging cepat tanpa UI penuh kalau suatu saat dibutuhkan. Bisa
  dihapus kapan saja kalau dirasa tidak perlu lagi.

## 4.4 Yang SUDAH saya verifikasi sendiri

Sintaks PHP dicek manual (balance kurung). Bagian JavaScript
di-extract dan dicek pakai `node --check` -- **valid secara
sintaks**. Belum bisa dites end-to-end sungguhan (tidak ada PHP/MySQL
di environment saya).

## 4.5 Yang PERLU kamu jalankan/verifikasi

1. Buka `/inbox` setelah login -- pastikan daftar conversation yang
   sudah ada dari Phase 2/3 muncul.
2. Klik salah satu conversation -- pastikan riwayat pesannya muncul
   (bubble putih untuk masuk, bubble hijau untuk keluar).
3. Ketik balasan, klik kirim (atau Enter) -- pastikan langsung
   muncul di thread DAN customer beneran menerima di WhatsApp.
4. Kirim WA baru dari HP customer SAAT halaman `/inbox` masih
   terbuka (jangan refresh manual) -- tunggu beberapa detik,
   pastikan pesan baru muncul otomatis di thread (bukti polling
   jalan).
5. Perhatikan badge status Gateway di kanan atas -- matikan Gateway
   sebentar, tunggu ~30 detik, pastikan badge berubah jadi
   "Terputus" otomatis (tanpa refresh halaman).

---

# 5. Perbaikan pasca-Phase 4 (2026-09-07)

## 5.1 Bug jam tidak sinkron (timezone) — diperbaiki

**Gejala**: jam pesan yang tampil di `/inbox` tidak sesuai jam lokal
Jakarta (selisih beberapa jam).

**Penyebab**: Gateway mengirim timestamp dalam UTC (`.toISOString()`
di `connectionManager.js` -- ini SUDAH BENAR, standar untuk
komunikasi antar-sistem). Bug-nya ada di sisi CI4:
`InboxGatewayApi::parseTimestamp()` memformat `DateTime` apa adanya
tanpa konversi ke timezone lokal, jadi nilai UTC tersimpan seolah itu
waktu Asia/Jakarta.

**Fix** (3 tempat, semua eksplisit `new DateTimeZone('Asia/Jakarta')`,
TIDAK bergantung ke setting timezone default PHP di server yang tidak
bisa saya pastikan benar):
- `InboxGatewayApi::parseTimestamp()` -- konversi timestamp pesan
  masuk/sinkron ke WIB sebelum disimpan.
- `Inbox::kirim()` -- timestamp pesan outgoing dari POS pakai WIB
  eksplisit, bukan `date()` bawaan.
- `GatewayStatusModel::isUsable()` -- perhitungan usia heartbeat
  eksplisit interpretasikan `last_heartbeat_at` sebagai WIB (kalau
  tidak, `strtotime()` bisa salah hitung "basi" karena mismatch
  timezone yang sama).

## 5.2 Log Gateway diperbaiki (bukan bug bisnis, tapi kualitas log)

Setelah komputer Gateway sleep lama, WhatsApp/Baileys bisa mengalami
error decrypt beruntun (`Bad MAC`, `MessageCounterError`) -- ini
**perilaku Baileys/WhatsApp sendiri**, BUKAN bug di kode Gateway.
Safety net `process.on('uncaughtException'/'unhandledRejection')` di
`src/app/index.js` (sudah ada sejak POC awal) menangkapnya supaya
proses tidak crash -- itu bekerja sesuai desain.

**Update lanjutan (dikonfirmasi user)**: error yang sama ternyata
juga muncul terus-menerus **walau komputer tidak sleep**, tapi pesan
chat asli tetap masuk normal ke `/inbox` tanpa masalah -- ini
memastikan errornya murni noise dari lalu lintas protokol internal
WhatsApp multi-device (mis. sinkronisasi read-receipt/state dari
device lain yang login ke akun yang sama), bukan indikasi ada pesan
yang hilang.

Fix final: pesan error dengan pola yang SUDAH DIKENAL sebagai noise
Baileys (`Bad MAC`, `MessageCounterError`, `Key used already or
never filled`, `Failed to decrypt message`) sekarang di-log lewat
`logger.debug()`, bukan `logger.error()` -- dan `logger.debug()`
SENGAJA tidak pernah masuk ke ring buffer dashboard (`pushEvent()`
cuma dipanggil dari `info`/`warn`/`error`, lihat
`src/logging/index.js`), jadi otomatis tidak akan tampil di panel
Event/Log sama sekali dengan `LOG_LEVEL=info` (default). Error LAIN
di luar pola ini tetap tampil normal (plus throttle dari fix
sebelumnya, untuk kasus lain yang berulang persis sama).

**Saran operasional** (bukan kode): matikan sleep/hibernate di
komputer yang menjalankan Gateway, karena perannya seperti server
yang harus jalan terus-menerus.

## 5.3 Balasan dari WhatsApp Web/HP langsung kini ikut disinkronkan

**Masalah yang ditemukan user**: sebelumnya, kalau staff membalas
customer LANGSUNG dari WhatsApp Web/HP (bukan lewat POS), balasan itu
sama sekali tidak tercatat di `aulia_inboxdb` / tidak muncul di
`/inbox` -- kasir lain tidak tahu sudah dibalas, berisiko balasan
dobel.

**Keputusan**: balasan ini SEKARANG ikut disinkronkan sebagai
`direction='outgoing'`, TANPA identitas staff spesifik (Baileys tidak
punya info siapa yang login WA Web/pegang HP-nya) --
`sent_by_user_id=NULL`, ditampilkan di UI dengan label **"Staff (WA
Web/HP)"** supaya jelas beda dari balasan yang benar-benar dikirim
lewat POS.

### Perubahan sisi Gateway
- **`src/whatsapp/connectionManager.js`** -- pesan `fromMe=true`
  (sebelumnya diabaikan) sekarang JUGA di-enqueue ke buffer, dengan
  `direction: 'outgoing'`.
- **`src/store/incomingBuffer.js`** -- kolom `direction` baru di
  tabel `incoming_queue`. Migrasi otomatis & aman untuk database
  SQLite lama yang sudah ada (cek `PRAGMA table_info`, `ALTER TABLE
  ADD COLUMN` kalau belum ada) -- **sudah ditest langsung**: buat DB
  dengan skema lama, buka pakai kode baru, kolom bertambah otomatis,
  data lama tidak hilang, otomatis dapat `direction='incoming'`
  (default aman, sesuai perilaku sebelumnya).
- **`src/delivery/incomingDelivery.js`** -- sertakan field
  `direction` saat POST ke CI4.
- Nama file/class/tabel (`incomingBuffer`/`incoming_queue`) SENGAJA
  TIDAK di-rename walau sekarang menangani dua arah -- supaya
  perubahan minimal/rendah risiko. Cukup lihat kolom `direction` di
  tiap baris.

### Perubahan sisi CI4
- **`InboxGatewayApi::messages()`** -- terima field `direction`
  opsional dari payload (default `'incoming'`, kompatibel mundur
  dengan kontrak Phase 2 lama). Untuk `direction='outgoing'`:
  `sent_by_user_id=NULL`, `send_status='sent'` langsung (bukan
  `'received'`), dan **TIDAK** memaksa `conversation.status='open'`
  (beda dari incoming yang selalu membuka kembali conversation --
  balasan sinkron dari luar POS tidak seharusnya mengubah keputusan
  status conversation).
- **`Inbox::attachSenderNames()`** -- pesan outgoing dengan
  `sent_by_user_id` NULL diberi label `"Staff (WA Web/HP)"`, bukan
  dibiarkan kosong begitu saja.

---

# 6. Perbaikan & fitur tambahan pasca-Phase 4, gelombang 2 (2026-09-07)

## 6.1 Fix: Status WhatsApp (Stories) ikut masuk sebagai chat

**Gejala**: update Status WhatsApp (Stories) ikut tercatat/muncul di
Inbox, padahal bukan percakapan.

**Penyebab**: Baileys mengirim event `messages.upsert` untuk SEMUA
jenis pesan, termasuk Status -- JID-nya selalu literal
`status@broadcast`.

**Fix**: `connectionManager.js` `_handleIncomingMessage()` sekarang
memfilter `remoteJid === 'status@broadcast'` di baris PALING AWAL,
sebelum diproses/di-log sama sekali -- jadi tidak pernah sampai ke
`messageStore` lokal, apalagi ke `aulia_inboxdb`.

## 6.2 Fitur baru: "Chat Baru" ke nomor yang belum pernah masuk

**Masalah yang ditemukan user**: sebelumnya, kasir HANYA bisa
membalas conversation yang SUDAH ADA (dibuat otomatis saat customer
chat duluan) -- tidak ada cara untuk memulai kontak ke nomor customer
yang belum pernah mengirim pesan sama sekali.

**Fix**: endpoint & UI baru untuk mulai chat ke nomor manapun.

- **`Inbox::mulaiPercakapan()`** -- `POST /inbox/mulai-percakapan`.
  Terima `phone` + `text`. Normalisasi nomor (lihat 37.3), cari
  conversation yang sudah ada berdasarkan `chat_id` hasil normalisasi
  -- kalau belum ada, BUAT baru (`status='open'`, `contact_name=NULL`
  karena belum tahu namanya); kalau sudah ada (customer ini pernah
  chat sebelumnya), pakai yang sudah ada, TIDAK bikin duplikat.
  Setelah conversation siap, delegasikan ke helper yang sama dengan
  `kirim()` (lihat 37.4) untuk benar-benar mengirim pesannya.
- **UI** (`inbox/index.php`) -- tombol "Chat Baru" di header card,
  buka modal kecil (nomor + pesan pertama). Sukses -> modal tertutup,
  daftar conversation dimuat ulang, langsung membuka conversation
  yang baru dibuat/dipakai.

## 6.3 Normalisasi nomor telepon Indonesia

`Inbox::normalizePhoneToJid()` (baru) menerima format umum: `08xx`,
`8xx` tanpa awalan (DITOLAK, sengaja tidak ditebak-tebak -- ambigu),
`62xx`, `+62xx`, boleh ada spasi/strip/tanda kurung. Menghasilkan JID
`<62xxx>@s.whatsapp.net` + nomor bersih untuk kolom `phone`.
Mengembalikan `null` (ditolak dengan pesan error jelas) untuk format
yang tidak bisa dikenali dengan yakin, DAN divalidasi panjang wajar
(10-15 digit setelah normalisasi) supaya tidak menyimpan nomor
sampah ke database.

## 6.4 Refactor: `kirimKeConversation()` (helper bersama)

Logic "kirim ke Gateway lalu simpan sebagai outgoing" yang sebelumnya
cuma ada di `kirim()` sekarang diekstrak jadi
`kirimKeConversation(int $conversationId, string $chatId, string
$text)` (private), dipakai bersama oleh `kirim()` (conversation yang
sudah ada) dan `mulaiPercakapan()` (conversation baru/existing dari
nomor) -- supaya tidak ada duplikasi kode antara dua alur ini yang
sebenarnya sama persis setelah `conversation_id`/`chat_id` didapat.

## 6.5 Yang SUDAH saya verifikasi sendiri

Sisi Gateway (filter `status@broadcast`) -- sintaks dicek
`node --check`, tidak bisa test fungsional langsung tanpa koneksi WA
sungguhan.

Sisi CI4 (`normalizePhoneToJid()`, `kirimKeConversation()`) --
sintaks PHP dicek manual (balance kurung), logic normalisasi nomor
ditelusuri manual case-by-case (08xx, 62xx, +62xx dengan
spasi/strip, nomor tanpa awalan yang sengaja ditolak, nomor terlalu
pendek yang sengaja ditolak) -- semua sesuai ekspektasi. Belum bisa
dites end-to-end sungguhan (tidak ada PHP/MySQL di environment saya).

## 6.6 Belum dikerjakan: dukungan file/media selain teks

User minta ini juga, tapi SENGAJA belum langsung dikerjakan --
menunggu scoping bareng user dulu (lihat percakapan) karena ini fitur
besar yang eksplisit ditandai "belum untuk sekarang" di spec POC
awal. Kolom `media_path`, `media_mime_type`, `media_filename`,
`media_size`, `media_sha256`, `media_metadata` di tabel `messages`
SUDAH disiapkan sejak Phase 1 (migration
`2026-09-07-000001_CreateInboxTables.php`) untuk kebutuhan ini nanti.

---

# 7. Dukungan Media (Image/Document) — Incoming (2026-09-07)

## 7.1 Scope & keputusan desain

Sesuai kesepakatan: **incoming dulu** (customer kirim gambar/dokumen
ke toko), disiapkan strukturnya supaya **outgoing** (kasir kirim
media dari POS) tinggal dibangun di atasnya nanti. Gambar DAN
dokumen sekaligus (bukan salah satu dulu).

**Keputusan paling penting**: file media **TIDAK PERNAH** disimpan
permanen di server AuliaPos maupun di SQLite Gateway. Yang disimpan
di `aulia_inboxdb` cuma REFERENSI (`direct_path` + `media_key`
WhatsApp) -- file aslinya diambil & didekripsi ON-DEMAND dari server
WhatsApp setiap kali kasir benar-benar membuka pesan itu.

**Konsekuensi yang disadari & diterima**: WhatsApp tidak menjamin
media tersimpan selamanya di server mereka -- untuk pesan yang cukup
lama, referensi bisa "basi" dan file jadi tidak bisa diambil lagi.
User sudah mengonfirmasi ini bisa diterima ("kalau butuh file minggu
lalu masih bisa lewat HP/WA Web").

## 7.2 Alur lengkap

```
Customer kirim gambar/dokumen
  -> Gateway terima (connectionManager.js), EKSTRAK REFERENSI saja
     (directPath + mediaKey base64 + mimetype + ukuran + nama file),
     TIDAK mengunduh isi filenya
  -> Simpan referensi ke SQLite buffer (kolom media_json baru)
  -> Worker delivery kirim referensi ke CI4 (field 'media' di payload)
  -> CI4 simpan ke messages.media_metadata (JSON: direct_path +
     media_key_base64 + media_type), plus media_mime_type/
     media_filename/media_size/media_sha256 dari metadata yang sudah
     tersedia (BUKAN dari mengunduh file)
  -> Kasir buka /inbox, lihat bubble gambar/link dokumen
  -> Browser minta GET /inbox/media/{id}
  -> CI4 (Inbox::media()) baca referensi dari database, minta Gateway
     ambil+dekripsi via POST /media/download (Bearer token)
  -> Gateway download dari server WhatsApp, dekripsi, STREAMING balik
     (tidak disimpan ke disk Gateway)
  -> CI4 teruskan (stream) langsung ke browser (tidak disimpan ke
     disk CI4 juga)
```

## 7.3 Sisi Gateway — file baru/diubah

- **`connectionManager.js`**:
  - `_handleIncomingMessage()` sekarang deteksi `imageMessage`/
    `documentMessage` (selain `conversation`/`extendedTextMessage`
    yang sudah ada). Jenis lain (audio/video/sticker/lokasi/dst)
    masih dilewati & di-log debug -- eksplisit di luar scope untuk
    sekarang.
  - `buildMediaRef()` (baru, function-level, bukan method) --
    ekstrak `directPath`, `mediaKey` (di-encode base64 untuk
    transport JSON), `mimetype`, `fileLength`, `fileSha256` (base64),
    dan `fileName` (khusus dokumen) dari message Baileys. Return
    `null` kalau `directPath`/`mediaKey` tidak lengkap (pesan
    dilewati, tidak ada gunanya diteruskan).
  - `downloadMediaByRef()` (method baru) -- pakai
    `downloadContentFromMessage()` bawaan Baileys (diverifikasi
    LANGSUNG dari source code library yang ter-install, bukan
    ditebak, untuk pastikan signature parameter benar) untuk
    ambil+dekripsi file dari referensi, kembalikan sebagai Buffer.
    Bisa throw kalau media sudah kadaluarsa di server WhatsApp.
- **`src/store/incomingBuffer.js`** -- kolom `media_json` baru
  (migrasi otomatis untuk database lama, pola sama seperti kolom
  `direction` sebelumnya), `message_type` sekarang dinamis (dulu
  di-hardcode `'text'`).
- **`src/delivery/incomingDelivery.js`** -- sertakan `media` (hasil
  parse `media_json`) di payload POST ke CI4.
- **`src/api/ci4Routes.js`** -- endpoint baru `POST /media/download`
  (Bearer token, path root sama seperti `/send`). Terima referensi
  media dari CI4, kembalikan file BINARY langsung (bukan JSON) kalau
  sukses, atau JSON `{success:false, error_code:'MEDIA_UNAVAILABLE',
  ...}` (HTTP 410) kalau gagal/kadaluarsa.

## 7.4 Sisi CI4 — file baru/diubah

- **`InboxGatewayApi::messages()`** -- terima field `media` opsional
  dari payload untuk `message_type` image/document (`direct_path` +
  `media_key_base64` wajib ada, request ditolak 400 kalau tidak).
  Simpan ke `messages.media_metadata` (JSON, cuma referensi) +
  kolom `media_mime_type`/`media_filename`/`media_size`/
  `media_sha256` dari metadata yang dikirim Gateway (sha256 dikonversi
  dari base64 ke hex supaya cocok tipe kolom `CHAR(64)`). Kolom
  `media_path` SENGAJA selalu tetap NULL (tidak pernah menyimpan file
  lokal).
- **`Inbox::media($messageId)`** (baru) -- `GET /inbox/media/(:num)`,
  session-authenticated. Baca referensi dari database, minta Gateway
  ambil+dekripsi via `callGatewayMediaDownload()` (cURL, timeout 30
  detik -- lebih lama dari kirim teks karena unduh file lebih berat),
  teruskan (stream) langsung ke browser dengan `Content-Type` sesuai
  mimetype asli. Dokumen dikirim dengan `Content-Disposition:
  attachment` (langsung download), gambar dengan `inline` (tampil di
  browser/`<img>`).

## 7.5 UI (`inbox/index.php`)

- Gambar: `<img src="/inbox/media/{id}">`, dengan `onerror` fallback
  ke placeholder "Gambar tidak tersedia (kemungkinan sudah
  kadaluarsa)" -- BUKAN ikon broken-image generik browser.
- Dokumen: link dengan ikon + nama file, buka tab baru (`target=
  "_blank"`) -- browser yang tentukan cara menampilkan (preview PDF
  bawaan browser, atau langsung download, tergantung tipe file &
  pengaturan browser masing-masing).
- Caption (kalau ada) ditampilkan di bawah gambar/link dokumen.

## 7.6 Yang SUDAH saya verifikasi sendiri

- **Signature `downloadContentFromMessage()`** dicek LANGSUNG dari
  source code `node_modules/baileys` yang ter-install (bukan ditebak
  dari memori/dokumentasi) -- termasuk daftar valid `type` string
  (`'image'`, `'document'`, dst) dari `MEDIA_HKDF_KEY_MAPPING` di
  `Defaults/index.js`.
- Endpoint `POST /media/download` ditest 3 skenario nyata: tanpa
  token (401), `media_type` invalid (400), referensi kosong (400) --
  semua sesuai ekspektasi.
- Skenario ke-4 (unduh media SUNGGUHAN dari server WhatsApp) **tidak
  bisa ditest di environment saya** -- perlu akses internet ke
  `mmg.whatsapp.net` yang tidak tersedia di sandbox saya. **Ini WAJIB
  ditest langsung oleh user.**
- Sintaks semua file (JS via `node --check`, PHP via balance kurung
  manual) sudah dicek.

## 7.7 Yang PERLU kamu jalankan/verifikasi

1. Restart Gateway (migrasi kolom `media_json` jalan otomatis).
2. Kirim **gambar** dari HP ke nomor toko, cek muncul di `/inbox`
   sebagai bubble gambar (bukan cuma teks/kosong).
3. Kirim **dokumen** (PDF misalnya) dari HP, cek muncul sebagai link
   dokumen dengan nama file yang benar.
4. Klik gambar/dokumen tsb, pastikan benar-benar bisa
   ditampilkan/didownload (ini yang paling penting divalidasi, karena
   belum bisa saya test sama sekali).
5. (Opsional, untuk pahami keterbatasan) coba buka lagi gambar/
   dokumen yang SUDAH LAMA (kalau ada data lama) -- kalau muncul
   placeholder "tidak tersedia", itu WAJAR sesuai desain (media sudah
   kadaluarsa di WhatsApp), bukan bug.

## 7.8 Outgoing media (kasir kirim gambar/dokumen dari POS) -- SELESAI (2026-09-12)

Menyusul catatan di §7.8 versi sebelumnya ("baru incoming yang
dikerjakan"): arah keluar sekarang juga didukung, memakai persis pola
referensi-saja yang sama seperti incoming -- bukan mekanisme baru.

**Kunci implementasinya**: setelah Gateway (`sendMediaMessage()` di
`connectionManager.js`, repo `WA-Gateway` terpisah) berhasil upload
file ke server WhatsApp lewat `sock.sendMessage()`, hasilnya SUDAH
berisi `directPath`/`mediaKey` asli untuk file yang baru diunggah --
persis strukturnya seperti `imageMessage`/`documentMessage` pada
pesan masuk. Referensi ini diekstrak dengan `buildMediaRef()` yang
SAMA (fungsi yang sudah ada untuk incoming, dipakai ulang apa
adanya), lalu dikembalikan sebagai `media_ref` di response
`POST /send-media`.

Alur sisi CI4 (`Inbox::kirimMedia()`, `POST /inbox/kirim-media`):
kasir upload file dari form Inbox -> CI4 baca isi file ke memory
(TIDAK PERNAH ditulis ke disk CI4) -> base64-encode -> kirim ke
Gateway lewat `POST /send-media` (Bearer token) -> kalau sukses,
simpan `messages.media_metadata` dari `media_ref` yang dikembalikan
Gateway (kalau ada) -- format JSON-nya SAMA PERSIS dengan incoming
(`direct_path` + `media_key_base64` + `media_type`), sehingga
`Inbox::media($messageId)` (endpoint `GET /inbox/media/(:num)` yang
sudah ada) otomatis bisa membuka ulang media KELUAR ini juga, tanpa
endpoint atau logic tambahan apa pun.

Kalau Gateway tidak mengembalikan `media_ref` (kasus jarang -- mis.
Baileys tidak menyertakan `directPath`/`mediaKey` di respons untuk
alasan tertentu), pesan tetap tersimpan sebagai terkirim (sudah
terlanjur sampai ke WhatsApp), hanya saja tidak bisa dibuka ulang
nanti dari Inbox -- bukan kegagalan kirim, cuma keterbatasan tampil
ulang.

UI (`inbox/index.php`): tombol lampiran (ikon peniti) di sebelah
textarea balasan, aktif begitu satu conversation dipilih. File yang
dipilih ditampilkan sebagai badge kecil (bisa dibatalkan) sebelum
dikirim; textarea jadi caption opsional. Bubble outgoing media
memakai fungsi render yang SAMA (`renderIsiPesan()`) dengan incoming
-- gambar tampil `<img>`, dokumen tampil sebagai link.

Batas ukuran: `Config\Inbox::$maxMediaUploadMb` (default 15MB,
`.env`: `inbox.maxMediaUploadMb`) dicek di CI4 SEBELUM base64-encode,
sengaja lebih kecil dari `MAX_MEDIA_UPLOAD_MB` Gateway (default 20MB)
supaya CI4 menolak lebih dulu dengan pesan jelas.

**Belum diverifikasi end-to-end nyata** (kirim media sungguhan dari
form Inbox ke nomor WhatsApp asli, lalu buka lagi bubble-nya) --
hanya lolos `php -l`/`node --check`. Perlu ditest langsung sebelum
dianggap "selesai" sepenuhnya.

## 7.9 Belum dikerjakan (di luar scope sesi ini)

- Jenis media lain (audio, video, sticker, lokasi, kontak) -- masih
  di luar scope, dilewati & di-log debug di Gateway.
- Preview thumbnail di daftar conversation (list masih menampilkan
  nomor telepon sebagai preview, bukan "📷 Gambar"/"📄 Dokumen") --
  butuh perubahan skema tambahan (`last_message_type` di
  `conversations`) yang belum dikerjakan, murni polish tampilan.
- "Mulai chat baru" (`mulaiPercakapan()`) masih hanya menerima teks --
  kirim media hanya bisa ke conversation yang sudah ada.

---

# 8. Hapus Percakapan (2026-09-12)

## 8.1 Apa ini

Kasir/admin bisa menghapus satu conversation beserta **SEMUA**
riwayat pesannya secara permanen dari `aulia_inboxdb`, langsung dari
UI Inbox. Hard delete, bukan arsip/soft delete -- tidak ada spec yang
minta riwayat hapus disimpan, dan kedua model (`ConversationModel`,
`MessageModel`) memang `$useSoftDeletes = false`.

## 8.2 Alur & keputusan desain

- `Inbox::hapusPercakapan($conversationId)` (`POST
  /inbox/percakapan/(:num)/hapus`, session-authenticated) cukup
  memanggil `ConversationModel::delete($conversationId)` -- baris
  `messages` milik conversation itu ikut terhapus **otomatis** lewat
  foreign key `ON DELETE CASCADE` yang SUDAH ADA sejak Phase 1
  (`messages.conversation_id -> conversations.id`, lihat migration
  `2026-09-07-000001_CreateInboxTables.php`). Sengaja TIDAK ada query
  DELETE terpisah untuk `messages` -- FK yang menjamin konsistensinya.
- **Tidak menyentuh Gateway/WhatsApp sama sekali.** Gateway tidak
  menyimpan riwayat percakapan apa pun (murni reliability buffer
  SQLite untuk retry pengiriman, bukan sumber kebenaran -- lihat
  §1.2), jadi tidak ada yang perlu disinkronkan ke sana. Menghapus
  percakapan di Inbox POS **tidak menghapus chat di WhatsApp/HP
  customer maupun HP toko** -- ini murni membersihkan riwayat di sisi
  POS.
- UI (`inbox/index.php`): tombol hapus (ikon tong sampah) muncul di
  header thread setelah satu conversation dipilih, memicu modal
  konfirmasi eksplisit (nama kontak + peringatan "tidak bisa
  dikembalikan") sebelum benar-benar mengirim request hapus. Setelah
  sukses, panel kanan direset ke kondisi kosong dan conversation
  langsung hilang dari daftar kiri tanpa menunggu siklus polling
  berikutnya.
- ~~Tidak ada ownership restriction (siapa saja yang login boleh
  menghapus conversation manapun) -- assignment belum
  diimplementasikan~~ -- **SUDAH BERUBAH**, lihat §9: sejak assignment
  ada, hapus percakapan tunduk pada `cekOwnership()` yang sama dengan
  kirim balasan/media.

## 8.3 Yang PERLU diverifikasi

**SUDAH ditest langsung oleh user (2026-09-12) dan lolos** -- hapus
percakapan beserta semua pesannya bekerja sesuai desain di atas.

---

# 9. Assignment / "Ambil" Percakapan (2026-09-12)

## 9.1 Apa ini

Sebelumnya (Phase 3/4) sengaja BELUM ada assignment ("JANGAN
IMPLEMENTASI DULU" di spec awal, lihat §4.3) -- siapa saja yang login
bisa membalas/menghapus conversation manapun tanpa pembatasan. Fitur
ini menambahkan mekanisme itu: satu conversation bisa "ditangani" satu
staff, supaya jelas siapa yang bertanggung jawab dan tidak ada 2 kasir
membalas bersamaan tanpa sadar.

Kolom `conversations.assigned_to` (logical reference ke
`aulia_kasirdb.users.id`) SUDAH ADA sejak migration Phase 1 -- fitur
ini murni memanfaatkannya, TIDAK ADA migration baru.

## 9.2 Cara kerja

- **Auto-assign**: begitu SATU staff membalas (teks atau media) sebuah
  conversation yang `assigned_to`-nya masih `NULL`, conversation itu
  otomatis ter-assign ke staff tsb (`kirimKeConversation()` dan
  `kirimMedia()` di `Inbox.php`) -- tidak perlu klik apa pun dulu.
  Masuk akal: siapa yang membalas duluan, dialah yang "memegang"
  percakapan itu.
- **Ambil manual** (`POST /inbox/percakapan/(:num)/ambil`,
  `Inbox::ambilPercakapan()`): staff bisa mengklaim conversation
  SEBELUM sempat membalas apa pun (mis. supaya staff lain tahu duluan
  "ini sudah saya pegang"). Kalau sudah ditangani orang lain: admin
  boleh mengambil alih (override), staff non-admin ditolak (409) dengan
  pesan jelas siapa yang sedang menangani.
- **Lepas** (`POST /inbox/percakapan/(:num)/lepas`,
  `Inbox::lepasPercakapan()`): kosongkan `assigned_to`, conversation
  kembali bebas diambil/dibalas siapa saja. Hanya boleh dilakukan oleh
  yang sedang menangani, atau admin.
- **Ownership restriction** (`Inbox::cekOwnership()`, baru, dipakai
  bersama oleh `kirimKeConversation()`, `kirimMedia()`, dan
  `hapusPercakapan()`): sebuah aksi terhadap conversation DITOLAK
  (403) HANYA kalau conversation itu sedang ditangani staff LAIN (non-
  admin, dan bukan dirinya). Conversation yang belum ditangani siapa
  pun tetap bisa diakses siapa saja (konsisten dengan auto-assign di
  atas -- baru "terkunci" setelah ada yang benar-benar pegang).
- **Admin selalu boleh** override/take-over/lepas/balas/hapus
  conversation manapun, terlepas dari assignment -- untuk keperluan
  supervisi.

## 9.3 UI (`inbox/index.php`)

- Badge nama staff penangan (ikon 👤) muncul di daftar percakapan
  (kiri) dan header thread (kanan) kalau `assigned_to` terisi -- warna
  beda kalau itu adalah diri sendiri (biru) vs staff lain (abu-abu).
- Header thread: tombol **"Ambil"** (kalau belum ditangani) atau
  ikon **lepas** (kalau ditangani sendiri/oleh admin yang login),
  berdampingan dengan tombol hapus percakapan yang sudah ada.
- Pesan error 403 dari server (mis. "Percakapan ini sedang ditangani
  oleh Budi...") ditampilkan lewat `showToast()` yang sudah ada --
  tidak ada UI khusus tambahan untuk itu.

## 9.4 Yang PERLU diverifikasi

Baru lolos `php -l`. **Belum diuji end-to-end nyata** di browser
dengan 2 akun berbeda (mis. staff A ambil/balas, staff B coba
balas/hapus -- pastikan ditolak dengan pesan yang benar; admin coba
override -- pastikan berhasil). Perlu ditest langsung sebelum
dianggap "selesai" sepenuhnya.

---

# 10. Incoming Audio/Video/Voice Note (2026-09-12, Task Group 1)

## 10.1 Apa ini

Menyusul §7.9 lama ("jenis media lain -- audio, video, sticker,
lokasi, kontak -- masih di luar scope"): `audio` dan `video` sekarang
didukung untuk arah **masuk** (customer kirim ke toko). Sticker,
lokasi, kontak TETAP di luar scope (tidak disentuh sesi ini). Outbound
audio/video (kasir kirim dari POS) JUGA TETAP di luar scope -- itu
Task Group terpisah.

## 10.2 Keputusan desain PALING PENTING: beda prinsip dari image/document

Image/document (§7) menyimpan REFERENSI (`direct_path` + `media_key`)
supaya bisa didekripsi ulang ON-DEMAND dari server WhatsApp kapan pun
kasir membuka pesannya. **Audio/video TIDAK memakai pola itu sama
sekali** -- binary-nya TIDAK PERNAH diambil, baik oleh Gateway maupun
AuliaPos, titik. Yang disimpan cuma metadata pesan (`message_type`,
caption/`text`, `media_mime_type`, `media_size`, timestamp,
`wa_message_id`, `sender_jid`, `conversation_id`) -- `media_path` dan
`media_metadata` SELALU NULL untuk audio/video. UI cukup menampilkan
placeholder "Customer mengirim audio/video — cek WhatsApp Web.";
kasir yang perlu dengar/lihat isinya buka langsung dari WhatsApp
Web/HP toko.

Konsekuensinya: TIDAK ADA endpoint download media baru untuk
audio/video (beda dari image/document yang punya `GET
/inbox/media/(:num)` + `POST /media/download` Gateway) -- memang
sengaja tidak dibuat, sesuai instruksi eksplisit Task Group ini.

## 10.3 Voice note = audio, bukan tipe baru

WhatsApp mengirim voice note sebagai `audioMessage` dengan flag
`ptt: true` di level Baileys. Gateway TIDAK membuat cabang/tipe baru
untuk ini -- voice note masuk sebagai `message_type = audio` persis
sama dengan audio biasa (musik/rekaman yang dikirim sebagai file).
Flag `ptt` diperiksa TIDAK diteruskan ke AuliaPos sama sekali (tidak
ada kolom/kebutuhan untuk itu) -- murni supaya tidak ada business
logic bercabang berdasarkan itu, sesuai instruksi eksplisit.

## 10.4 Sisi Gateway (`connectionManager.js`)

`_handleIncomingMessage()` sekarang punya cabang `audioMsg || videoMsg`
sejajar dengan cabang `imageMsg`/`documentMsg` yang sudah ada -- BUKAN
transport/abstraction baru. BEDA dari cabang image/document: tidak
memanggil `buildMediaRef()` sama sekali (tidak butuh
`directPath`/`mediaKey`), cukup ekstrak `mimetype` dan `fileLength`
langsung dari `audioMessage`/`videoMessage`. `caption` diambil untuk
video (WhatsApp mengizinkannya); audio TIDAK PERNAH punya caption di
WhatsApp, jadi `text` selalu `null` untuk audio. **Beda penting
lainnya**: kalau `mimetype`/`fileLength` kosong, pesan **TETAP
diteruskan** (tidak di-drop) -- beda dari image/document yang WAJIB
punya `directPath`/`mediaKey` lengkap atau pesannya dibuang, karena
audio/video tidak punya syarat referensi apa pun untuk berguna nanti.

Payload ke CI4 (lewat `incomingBuffer`/`incomingDelivery.js`, TIDAK
ADA perubahan kontrak/nama field -- `media` tetap object generik yang
sudah ada, cuma sekarang bisa berisi bentuk lebih ringan):

```json
{
  "wa_message_id": "...",
  "chat_id": "...",
  "jid_type": "pn",
  "message_type": "audio",
  "direction": "incoming",
  "sender_jid": "...",
  "text": null,
  "message_timestamp": "...",
  "media": { "mimetype": "audio/ogg; codecs=opus", "file_length": 12345 }
}
```

Video sama persis, `message_type: "video"`, `text` boleh berisi
caption.

## 10.5 Sisi CI4 (`InboxGatewayApi::messages()`)

Cabang baru `elseif (in_array($messageType, ['audio', 'video'], true))`
sejajar dengan cabang `image`/`document` yang sudah ada. BEDA
kritisnya: `media` di payload SEPENUHNYA opsional untuk audio/video
(tidak ada validasi yang menolak request kalau `media` kosong/tidak
ada) -- kalau ada, cuma `mimetype`/`file_length` yang diambil ke
`media_mime_type`/`media_size`; `media_path`, `media_filename`,
`media_sha256`, `media_metadata` SELALU tetap NULL (tidak pernah
diisi apa pun untuk audio/video). Tidak ada migration baru -- kolom
`messages.message_type` sudah `VARCHAR(30)` tanpa `ENUM`/constraint
yang membatasi nilainya (lihat migration Phase 1), jadi `audio`/
`video` diterima begitu saja seperti string bebas lainnya.

Idempotency (`existsByWaMessageId()`) dan status-transition
(`incoming` selalu membuka conversation jadi `open`, `last_message_at`/
`last_message_direction` ter-update) TIDAK diubah SAMA SEKALI --
logic itu sudah generik terhadap `message_type` sejak awal, jadi
otomatis berlaku sama untuk audio/video tanpa perlu disentuh.

## 10.6 UI (`inbox/index.php`)

`renderIsiPesan()` (JS) dapat cabang baru untuk `message_type ===
'audio'` / `'video'`: ikon (mikrofon/video) + teks placeholder
`"Customer mengirim audio/video — cek WhatsApp Web."`, caption (kalau
ada, dari `text`) ditampilkan di bawahnya sama seperti pola
image/document. SENGAJA TIDAK ADA `<audio controls>`/`<video
controls>`, thumbnail, atau link download apa pun -- sesuai instruksi
eksplisit.

## 10.7 Yang SUDAH saya verifikasi sendiri

- `node --check` pada semua file Gateway yang diubah.
- Skrip simulasi baru `test/simulate-audio-video.js` (pola sama
  dengan simulasi image/document yang sudah ada) LULUS: audio biasa,
  voice note (`ptt=true` tetap `audio`), video dengan/tanpa caption,
  MIME/ukuran kosong tidak bikin crash, tidak tertukar dengan
  text/image/document dalam satu chat yang sama, dan idempotency
  `incomingBuffer.enqueue()` (SQLite, `INSERT OR IGNORE` + UNIQUE
  `wa_message_id`) untuk event audio yang dikirim 2x persis sama.
- Regression: skrip simulasi LAMA (`simulate-lid-conversation.js`,
  `simulate-send-media.js`) dijalankan ulang setelah perubahan, TETAP
  LULUS -- text/image/document/outgoing tidak terpengaruh.
- `php -l` pada `InboxGatewayApi.php` dan `inbox/index.php`.

## 10.8 Yang BELUM bisa saya verifikasi (perlu kamu jalankan)

- **Idempotency di level AuliaPos/CI4** (`existsByWaMessageId()`)
  untuk audio/video SPESIFIK belum di-test dengan database sungguhan
  -- `aulia_inboxdb` di lingkungan pengembangan berisi data LIVE
  (bukan database test kosong), jadi sengaja TIDAK dijalankan test
  otomatis yang menulis ke sana untuk sesi ini (repo ini juga belum
  punya infrastruktur test DB terpisah untuk connection group
  `inbox` -- ini keterbatasan/gap yang sudah ada sebelum Task Group
  ini, bukan sesuatu yang diperbaiki di sini supaya perubahan tetap
  minimal). Logic-nya sama persis dengan yang sudah dipakai
  text/image/document sejak awal (tidak diubah), jadi risiko rendah,
  tapi tetap **WAJIB ditest langsung**: kirim webhook/audio yang sama
  2x (atau simulasikan retry Gateway) dan pastikan cuma 1 baris
  `messages` yang tercipta.
- **Kirim audio/voice note/video SUNGGUHAN dari HP** ke nomor toko,
  pastikan muncul di `/inbox` sebagai placeholder yang benar
  (`audio`/`video`, bukan `text` kosong atau error), caption (untuk
  video) tampil, dan conversation ter-`open`/`last_message_at`
  ter-update seperti pesan lain.
- Skenario "conversation CLOSED lalu incoming audio/video -> OPEN
  lagi" -- logic-nya identik dengan incoming text/image/document yang
  sudah ada (tidak diubah), tapi belum ditest ULANG spesifik untuk
  audio/video.

---

# 11. Customer Identity & Conversation Reconciliation (2026-09-12, Task Group 1.5)

## 11.1 Root cause

`conversations.chat_id` (UNIQUE) adalah SATU-SATUNYA kunci pencarian
conversation (`findByChatId()`). WhatsApp kadang melaporkan **nomor
yang sama** lewat **JID berbeda** -- paling umum `@lid` lalu
`@s.whatsapp.net` (atau sebaliknya), terutama setelah Gateway
reconnect/rollout fitur privasi LID WhatsApp. Karena `chat_id` yang
baru tidak persis sama dengan yang lama, `findByChatId()` tidak
menemukan match -> AuliaPos membuat conversation KEDUA untuk customer
yang sebenarnya sama (kasus nyata: "Muhammad Anshar" vs
"628563324637" ternyata satu orang, satu nomor WhatsApp).

## 11.2 Desain yang dipilih (dan yang SENGAJA ditolak)

**Ditolak**: tabel `customers` terpisah (CRM). Tidak ada satu pun
business rule/test case (A-J) yang benar-benar butuh grouping
"satu orang, banyak nomor" di level penyimpanan -- itu cuma model
konseptual di brief, bukan kebutuhan konkret sekarang. Membuatnya
sekarang = over-engineering yang dilarang eksplisit ("jangan
redesign berlebihan", "jangan CRM besar").

**Dipilih**: 1 tabel alias kecil + 4 kolom baru di `conversations`,
migration aman & additive:

- **`conversation_identities`** (baru) -- alias many-to-one: banyak
  `chat_id` (JID) bisa menunjuk ke SATU `conversation_id`. Ini yang
  menggantikan pencarian langsung ke `conversations.chat_id`.
  `conversations.chat_id` TETAP ADA apa adanya (masih dipakai untuk
  kirim balasan lewat Gateway) -- **TIDAK PERNAH diganti jadi nomor
  telepon**, cuma dimutakhirkan ke JID TERBARU saat reconciliation
  terjadi (§11.3 langkah 2).
- **`whatsapp_name`** (baru, di `conversations`) -- push name WhatsApp,
  SELALU dimutakhirkan bebas oleh Gateway. **`contact_name`** (sudah
  ada) sekarang MURNI nama manual customer profile -- Gateway TIDAK
  PERNAH menyentuhnya lagi sama sekali (sebelumnya ada logic
  "isi kalau kosong" yang mencampur dua konsep ini).
- **`manual_phone`** (baru) -- nomor yang diketik MANUAL kasir,
  informasional saja. **`phone`** (sudah ada) sekarang MURNI nomor
  TER-VERIFIKASI (di-derive Gateway dari JID `@s.whatsapp.net` asli)
  -- SATU-SATUNYA kolom yang dipakai untuk reconciliation. Pemisahan
  ini yang menjamin "jangan menganggap nomor yang diketik user sebagai
  nomor yang sudah diverifikasi WhatsApp": `manual_phone` TIDAK PERNAH
  dipakai untuk mencari/menggabungkan conversation, apa pun isinya.
- **`profile_updated_at`/`profile_updated_by`** (baru) -- audit ringan
  KHUSUS perubahan manual (beda dari `updated_at` umum yang kesentuh
  banyak hal lain, mis. pesan masuk baru).

Field `App\Libraries\PhoneNumber::normalize()` (baru, diekstrak dari
`Inbox::normalizePhoneToJid()` yang sudah ada, PERILAKU TIDAK DIUBAH)
dipakai di 3 tempat (mulai chat baru, payload Gateway, edit profil
manual) supaya "08xx"/"+62xx"/"62xx" semuanya jadi string canonical
yang sama persis.

## 11.3 Alur lookup/reconciliation (`ConversationModel::resolveConversationId()`)

Dipakai bersama oleh `InboxGatewayApi::messages()` (pesan masuk dari
Gateway) dan `Inbox::mulaiPercakapan()` (kasir mengetik nomor untuk
chat baru). Urutan (JANGAN diubah):

1. **chat_id ini sudah dikenal** (ada baris di
   `conversation_identities`) -> pakai conversation itu apa adanya.
   Jalur tercepat & PALING SERING kena (JID sama setelah Gateway
   restart, dst).
2. **Belum dikenal, TAPI ada `canonicalPhone`** -- HANYA diisi kalau
   `jid_type==='pn'` DAN nomor itu di-derive Gateway dari JID
   `@s.whatsapp.net` ASLI (**TIDAK PERNAH** ditebak dari `@lid`,
   **TIDAK PERNAH** dari `manual_phone`) -> cari conversation lain
   yang `phone`-nya SUDAH cocok. Ketemu -> chat_id baru didaftarkan
   sebagai ALIAS TAMBAHAN ke conversation itu (histori pesan lama
   UTUH, TIDAK ADA yang dihapus/dipindah), `conversations.chat_id`
   dimutakhirkan ke JID baru (dianggap lebih bisa diandalkan utk
   kirim balasan), JID lama TETAP ada sebagai alias (kalau muncul
   lagi nanti, tetap dikenali lewat langkah 1).
3. **Keduanya gagal** -> identity benar-benar baru -> buat
   conversation baru + 1 baris alias.

SENGAJA TIDAK PERNAH mencocokkan berdasarkan nama -- mencegah
auto-merge yang salah hanya karena nama kebetulan sama. Group chat
(`jid_type==='group'`) tidak pernah dapat `canonicalPhone` (sudah
otomatis lewat syarat `jid_type==='pn'` di langkah 2) -- tidak pernah
dianggap personal customer.

## 11.4 Existing data (data yang SUDAH ADA) TETAP AMAN

Migration `2026-09-12-000001_AddConversationIdentityReconciliation.php`
murni ADDITIVE: 4 kolom baru (nullable) + 1 tabel baru + 1 query
backfill (`INSERT ... SELECT` dari `conversations` ke
`conversation_identities`, 1 baris alias per conversation yang sudah
ada, mencerminkan `chat_id` saat ini). **TIDAK ADA** `UPDATE`/`DELETE`
terhadap `conversations`/`messages` yang sudah ada.

**PENTING -- batasan yang disadari**: conversation DUPLIKAT yang
SUDAH ADA sebelum fix ini (mis. `@lid` + `@s.whatsapp.net` yang
sebenarnya nomor sama) **TIDAK di-auto-merge oleh migration ini**
(sesuai larangan eksplisit "jangan auto-merge history secara
agresif"). Reconciliation langkah 2 di atas HANYA kena untuk chat_id
yang **belum pernah punya alias sama sekali** -- conversation lama
yang masing-masing SUDAH punya alias-nya sendiri (hasil backfill)
akan terus resolve ke dirinya sendiri lewat langkah 1 (exact match),
tidak akan pernah "ketemu" pasangannya lewat langkah 2. Fix ini
**mencegah duplikat BARU ke depannya**, bukan menggabungkan yang
sudah telanjur ada. Reconciliation manual untuk pasangan duplikat
lama (kalau kasir/admin menemukannya) belum punya tombol UI -- di
luar scope Task Group ini; strategi paling aman untuk sekarang:
biarkan kedua conversation lama tetap ada apa adanya (tidak ada
risiko data hilang), dan kalau customer yang sama menghubungi lagi
dengan salah satu JID lamanya, pesan tetap masuk ke conversation yang
benar (tidak menambah duplikat ketiga).

## 11.5 Fitur baru: Edit Profil Pelanggan

`POST /inbox/percakapan/(:num)/profil` (`Inbox::updateCustomerProfile()`)
-- kasir/admin mengubah `contact_name` (nama manual) dan/atau
`manual_phone` (nomor manual, dinormalisasi lewat `PhoneNumber::normalize()`
sebelum disimpan; ditolak 400 kalau formatnya tidak dikenali). Tunduk
pada `cekOwnership()` yang sama dengan kirim/hapus (konsisten dengan
fitur assignment yang sudah ada). Boleh mengosongkan salah satu/kedua
field untuk menghapus data yang salah.

UI (`inbox/index.php`): tombol "Edit" kecil di header thread (ikon
pensil) membuka modal 2 field (Nama Pelanggan, No. Telepon) +
Batal/Simpan. Semua tempat yang menampilkan nama/nomor (daftar
percakapan, header thread, modal hapus) diperbarui fallback-nya jadi:
nama = `contact_name ?: whatsapp_name ?: phone ?: chat_id`, nomor
tampil = `manual_phone ?: phone`.

## 11.6 File yang diubah/dibuat

- `app/Database/Migrations/2026-09-12-000001_AddConversationIdentityReconciliation.php` (baru)
- `app/Models/ConversationIdentityModel.php` (baru)
- `app/Models/ConversationModel.php` -- `resolveConversationId()` (baru),
  `findByChatId()` dirombak (lewat alias, bukan langsung ke kolom),
  `allowedFields` + docblock diperbarui.
- `app/Libraries/PhoneNumber.php` (baru, diekstrak dari
  `Inbox::normalizePhoneToJid()`).
- `app/Controllers/Inbox.php` -- `normalizePhoneToJid()` delegasi ke
  `PhoneNumber`, `mulaiPercakapan()` pakai `resolveConversationId()`,
  `updateCustomerProfile()` (baru).
- `app/Controllers/InboxGatewayApi.php` -- `messages()` pakai
  `resolveConversationId()`, pisah update `whatsapp_name`/`phone`
  (otomatis) dari `contact_name`/`manual_phone` (manual, tidak
  disentuh sama sekali dari sini).
- `app/Config/Routes.php` -- route baru
  `POST /inbox/percakapan/(:num)/profil`.
- `app/Views/inbox/index.php` -- fallback nama/nomor + modal & JS
  edit profil.
- `tests/unit/PhoneNumberTest.php` (baru).

## 11.7 Yang SUDAH saya verifikasi sendiri

- **Migration + `resolveConversationId()` dites LANGSUNG terhadap
  MySQL sungguhan** (bukan simulasi/mock) -- dibuatkan database
  disposable terpisah (`aulia_inboxdb_migrationtest`, sengaja BUKAN
  `aulia_inboxdb` yang berisi data live) via `.env` sementara,
  migration dijalankan betulan, lalu Test Case **A sampai J** (persis
  seperti daftar di brief) dijalankan sebagai assertion nyata --
  **SEMUA LULUS**, termasuk skenario inti bug (`@lid` lalu
  `@s.whatsapp.net` dengan nomor sama -> menyatu ke conversation yang
  sama, `chat_id` termutakhirkan, JID lama tetap dikenali sebagai
  alias). Database test dihapus setelahnya, `.env` dikembalikan
  persis seperti semula -- **`aulia_inboxdb` live TIDAK PERNAH
  tersentuh** (diverifikasi ulang: isi tabel `conversations`/`messages`
  sebelum & sesudah identik).
- `tests/unit/PhoneNumberTest.php` (8 test, murni logic tanpa DB) --
  LULUS.
- **Regression**: seluruh test suite project (`phpunit.dist.xml`, 119
  test) dijalankan ulang setelah semua perubahan -- LULUS, tidak ada
  yang rusak.
- `php -l` pada semua file PHP yang diubah/dibuat.

## 11.8 Yang BELUM bisa saya verifikasi (perlu kamu jalankan)

- **Migration BELUM diterapkan ke `aulia_inboxdb` yang sebenarnya**
  (sengaja -- lihat §11.7, hanya diverifikasi di database disposable).
  **WAJIB dijalankan** (`php spark migrate` TANPA flag `-g`, atau
  lewat halaman `/migrasi-manual` yang sudah ada -- KEDUANYA aman,
  memakai `MigrationRunner::latest()` yang menghormati `$DBGroup`
  masing-masing file migration) **sebelum** kode ini dipakai,
  jika tidak `InboxGatewayApi::messages()`/`Inbox::mulaiPercakapan()`
  akan error (kolom/tabel belum ada).
  **PERINGATAN**: JANGAN pakai `php spark migrate -g inbox` (dengan
  flag `-g`) dari CLI -- flag itu memaksa SEMUA migration project
  (termasuk yang punya `$DBGroup` lain, mis. migration AuliaPos inti)
  ikut memakai koneksi `inbox`, ditemukan sendiri menyebabkan error
  saat verifikasi (`Table 'bonus_rule' already exists`). Ini murni
  soal cara memanggil CLI-nya, bukan bug di migration/kode Task Group
  ini -- gunakan `php spark migrate` polos atau `/migrasi-manual`.
- ~~Kirim pesan sungguhan dari HP dengan skenario nyata "JID berubah
  @lid <-> @pn, nomor sama"~~ -- **TEMUAN AUDIT (2026-09-12)**: versi
  §11.3 ini SEBENARNYA TIDAK MENANGANI kasus LID-FIRST -> PN-LATER
  yang sebenarnya (`@lid` datang duluan dengan phone=NULL selamanya,
  lalu PN asli baru datang belakangan) -- langkah 2 lama HANYA bisa
  mencocokkan 2 conversation yang SAMA-SAMA sudah punya `phone`
  terisi, yang tidak pernah terjadi untuk `@lid` murni. Diperbaiki di
  **Section 12** (revisi LID-FIRST -> PN-LATER) -- lihat di sana untuk
  status verifikasi yang benar & terkini.
- Reconciliation untuk pasangan conversation duplikat YANG SUDAH ADA
  sebelumnya di `aulia_inboxdb` (lihat batasan di §11.4) -- silakan
  cek manual apakah ada pasangan seperti itu di data production
  sebelum/sesudah migration, dan putuskan sendiri apakah perlu
  digabung manual (di luar scope Task Group ini).
- UI edit profil (modal, tombol) belum dites klik langsung di browser.

---

# 12. Revisi LID-FIRST -> PN-LATER (2026-09-12, Task Group 1.5 lanjutan)

## 12.1 Temuan audit (root cause)

Setelah Section 11 selesai, ditemukan lewat audit: reconciliation
langkah 2 (`resolveConversationId()` versi lama) HANYA bisa
mencocokkan 2 conversation yang **sama-sama** sudah punya `phone`
ter-verifikasi. Kasus nyata yang justru paling sering terjadi (LID
datang DULUAN -> conversation dibuat dengan `phone=NULL` SELAMANYA,
sesuai desain "jangan menebak dari @lid" -> baru KEMUDIAN nomor PN
asli yang SAMA muncul) tidak pernah bisa ketemu lewat pencocokan
`phone`, karena `phone` conversation @lid itu memang tidak pernah
terisi. Akibatnya kasus yang justru ingin dicegah (2 conversation
untuk 1 customer yang sama) tetap bisa terjadi.

## 12.2 Audit kapabilitas Baileys (SEBELUM memilih solusi)

Diperiksa LANGSUNG dari source code Baileys yang ter-install di
`WA-Gateway/node_modules/baileys` (versi 6.7.24) -- BUKAN ditebak dari
dokumentasi/memori:

1. **Apakah event `messages.upsert` membawa mapping LID<->PN?** TIDAK.
   `WAProto.proto` -- `message MessageKey` di versi ini HANYA punya
   `remoteJid, fromMe, id, participant`. Field `remoteJidAlt`/
   `participantAlt` (yang ada di versi protokol WhatsApp lebih baru)
   TIDAK ADA sama sekali di skema protobuf versi ini -- bukan cuma
   tidak dipakai, field-nya memang tidak didefinisikan.
2. **Apakah ada mekanisme mapping yang sah?** YA, satu -- fungsi
   `sock.onWhatsApp(phoneJid)` (`lib/Socket/chats.js`), yang mengirim
   **USync query resmi ke server WhatsApp** (`.withContactProtocol().withLIDProtocol()`,
   parser di `lib/WAUSync/Protocols/UsyncLIDProtocol.js` membaca node
   `<lid val="...">` dari **respons server**, bukan tebakan client).
3. **Arahnya SATU ARAH SAJA: PN -> LID.** Tidak ditemukan
   `lidMapping`/`LIDMappingStore` atau mekanisme sebaliknya (LID -> PN)
   di versi library ini (di-grep, nihil). Konsekuensi: **mustahil**
   secara teknis untuk resolve nomor dari sebuah `@lid` yang datang
   duluan -- satu-satunya jalan adalah menunggu PN asli muncul, lalu
   MEMPERKAYA event PN itu dengan LID terkaitnya (arah terbalik dari
   yang dibutuhkan kalau ingin "menebak" dari lid, makanya tidak
   pernah dicoba).
4. **Siapa yang query, siapa yang putuskan?** Gateway yang query
   (dia yang pegang koneksi socket), tapi Gateway HANYA mengirim hasil
   mentahnya (`identity_hint.lid`) ke AuliaPos sebagai metadata
   tambahan -- sama seperti `phone`/`media` yang sudah ada. AuliaPos
   yang memutuskan mau dipakai untuk apa (business logic tetap di
   AuliaPos, Gateway tetap transport-only).

**CATATAN KEJUJURAN**: `sock.onWhatsApp()` **belum pernah dipanggil
terhadap koneksi WhatsApp sungguhan** di lingkungan pengembangan ini
(tidak ada akses jaringan ke server WhatsApp). Signature & parser-nya
diverifikasi dari source code, TAPI format PERSIS nilai `lid` yang
benar-benar dikembalikan server (mis. sudah `"123@lid"` atau cuma
`"123"`) **belum terverifikasi live**. Kode dibuat defensif
(normalisasi format) dan bagian ini **WAJIB diverifikasi ulang**
begitu ada koneksi WhatsApp nyata -- lihat §12.7.

## 12.3 Solusi yang dipilih (dan alasannya)

**Solusi ganda** (bukan salah satu A/B/C dari opsi yang ditawarkan,
tapi kombinasi A+C -- karena B, "AuliaPos menyimpan mapping", TIDAK
relevan lagi begitu diketahui bahwa Gateway sendiri yang harus query,
bukan menerima dari event pasif):

1. **Otomatis (best-effort)**: Gateway meng-enrich SETIAP pesan masuk
   `jid_type='pn'` dengan `identity_hint.lid` (hasil `onWhatsApp()`,
   di-cache in-memory per nomor supaya tidak query berulang-ulang).
   AuliaPos (`resolveConversationId()`) memakainya sebagai langkah
   PENCARIAN BARU (langkah 2, sebelum cocok nomor) -- kalau LID hasil
   query itu SUDAH dikenal sebagai conversation yang ada (kasus
   LID-FIRST), chat_id PN baru ditempelkan sebagai alias ke
   conversation itu. **Alasan dipilih**: ini SATU-SATUNYA sumber
   bukti yang benar-benar berasal dari server WhatsApp sendiri (bukan
   tebakan), jadi aman dipercaya untuk auto-reconcile TANPA melanggar
   "jangan auto-merge agresif" -- ini bukan heuristik, ini fakta dari
   WhatsApp.
2. **Manual (fallback aman, karena #1 belum terverifikasi live)**:
   `Inbox::konfirmasiNomorWhatsapp()` -- kasir/admin secara SADAR
   mengkonfirmasi nomor pada conversation `@lid`, mengisi `phone`
   (kolom yang sama dipakai reconciliation), sehingga PN berikutnya
   otomatis nyambung lewat langkah 3 (phone-match) YANG SUDAH ADA
   sejak Section 11 -- TIDAK ADA logic baru untuk jalur ini, murni
   "isi phone dengan sengaja oleh manusia". **Alasan dipilih**: ini
   yang BISA saya verifikasi penuh end-to-end (tidak bergantung
   koneksi WhatsApp live), dan tetap 100% konsisten dengan "kalau
   solusi teknis membutuhkan tindakan user, buat desain minimal &
   aman" -- tidak ada mekanisme merge terpisah yang perlu dibangun.

**Ditolak**: menyimpan mapping LID<->PN di tabel terpisah khusus
("LID mapping store") -- tidak perlu, `conversation_identities` yang
SUDAH ADA sejak Section 11 sudah cukup jadi "peta" itu (setiap
chat_id yang pernah dikenal, LID maupun PN, sudah ada di sana);
menambah tabel lagi cuma duplikasi data yang sama.

## 12.4 Perubahan Gateway (repo `WA-Gateway`, transport-only)

- `connectionManager.js`:
  - `_resolveLidForPhoneJid(phoneJid)` (baru) -- panggil
    `sock.onWhatsApp()`, cache in-memory per JID PN, non-fatal (try/
    catch, return null kalau gagal/tidak connected/tidak ada hasil).
    **TIDAK PERNAH dipanggil untuk `@lid`** (tidak ada gunanya/tidak
    didukung arahnya, lihat §12.2).
  - `_handleIncomingMessage()` sekarang `async` (perlu `await` hasil
    resolve di atas untuk pesan `jid_type='pn'`); `_onMessagesUpsert()`
    meng-await tiap pesan satu per satu (bukan paralel, supaya tidak
    membanjiri koneksi WhatsApp).
  - `normalized.identityHint` (baru) -- `{lid: "..."}` atau `null`.
- `incomingBuffer.js` -- kolom baru `identity_hint_json` (migrasi
  ringan ALTER TABLE, pola sama dengan `media_json` sebelumnya).
- `incomingDelivery.js` -- field baru `identity_hint` di payload POST
  ke CI4 (opsional, `null` kalau tidak ada).
- `test/simulate-identity-hint.js` (baru) -- 8 skenario, `onWhatsApp()`
  DI-MOCK (lihat CATATAN KEJUJURAN di §12.2).
- **Regresi**: `test/simulate-lid-conversation.js` dan
  `test/simulate-audio-video.js` diperbaiki -- helper `simulateIncoming`
  di kedua file HARUS di-`await` sekarang (async function TETAP
  menunda minimal 1 microtask di titik `await`, WALAU tidak ada
  operasi async nyata yang tereksekusi di baliknya -- ini semantik JS
  standar, bukan bug). Tanpa perbaikan ini, assertion di kedua test
  lama gagal karena urutan `messageStore.add()` untuk pesan
  `jid_type='pn'` jadi tidak deterministik.

## 12.5 Perubahan AuliaPos

- `ConversationModel::resolveConversationId()` -- parameter baru
  `?string $knownLid = null`, langkah BARU disisipkan sebagai langkah
  2 (exact chat_id tetap langkah 1, cocok nomor jadi langkah 3, buat
  baru jadi langkah 4). Logic "tempelkan alias + mutakhirkan chat_id"
  diekstrak jadi method privat `attachAliasToConversation()` (dipakai
  bersama langkah 2 & 3, DRY).
- `InboxGatewayApi::messages()` -- ekstrak `payload.identity_hint.lid`
  jadi `$knownLid`, HANYA dipercaya kalau pesan itu SENDIRI
  `jid_type==='pn'` (defense in depth, walau Gateway seharusnya sudah
  tidak pernah mengisi ini untuk `@lid`).
- `Inbox::konfirmasiNomorWhatsapp()` (baru, `POST
  /inbox/percakapan/(:num)/konfirmasi-nomor`) -- isi `phone` (BUKAN
  `manual_phone`) dari konfirmasi sadar kasir/admin. Menolak (409)
  kalau nomor itu SUDAH dipakai conversation lain (mencegah 2
  conversation punya `phone` sama/ambigu) -- TIDAK menggabungkan
  message apa pun, murni menolak & mengarahkan.
- UI (`inbox/index.php`) -- tombol "Konfirmasi Nomor" (ikon shield)
  muncul HANYA kalau `jid_type==='lid'` DAN `phone` masih kosong,
  modal dengan peringatan eksplisit sebelum submit.

## 12.6 Keamanan data

Migration TIDAK berubah dari Section 11 (tidak ada kolom/tabel
tambahan untuk revisi ini -- `identity_hint` murni payload transient
Gateway->CI4, tidak disimpan sebagai kolom baru di `conversations`/
`messages`). Reconciliation langkah 2 & 3 SAMA-SAMA hanya menambah
baris `conversation_identities` + update `conversations.chat_id` --
TIDAK PERNAH menghapus/memindah `messages`. `konfirmasiNomorWhatsapp()`
juga TIDAK menyentuh `messages` sama sekali.

## 12.7 Yang SUDAH saya verifikasi sendiri

- **Test 1-10 (persis sesuai spec revisi) + skenario BONUS
  (unconfirmed -> confirmed)** dijalankan sebagai assertion NYATA
  terhadap **MySQL sungguhan** di database disposable KEDUA
  (`aulia_inboxdb_migrationtest2`, terpisah dari yang dipakai Section
  11, dibuat & dihapus khusus untuk ini) -- **SEMUA LULUS**, termasuk
  TEST 2 (skenario INTI bug: LID-first lalu PN-later dengan
  `knownLid` yang cocok -> menyatu ke conversation yang sama, chat_id
  termutakhirkan, histori pesan lama utuh) dan TEST 6/7/8 (LID tanpa
  mapping/manual_phone/nama sama TIDAK PERNAH memicu merge). Database
  test dihapus, `.env` dikembalikan persis semula, `aulia_inboxdb`
  live diverifikasi ulang TIDAK tersentuh (kolom baru dari Section 11
  pun masih belum ada di sana -- migration real memang belum
  dijalankan, lihat §11.8).
- `test/simulate-identity-hint.js` (Gateway, `onWhatsApp()` di-MOCK) --
  8/8 lulus, termasuk: cache bekerja (query 1x per nomor), non-fatal
  saat error/tidak connected, normalisasi format defensif, **`@lid`
  TIDAK PERNAH memicu query** (dibuktikan dengan spy, bukan asumsi).
- Regresi: `test/simulate-lid-conversation.js`, `test/simulate-audio-video.js`,
  `test/simulate-send-media.js` dijalankan ulang setelah perbaikan
  `await` -- SEMUA TETAP LULUS, termasuk dijalankan berkali-kali
  berturut-turut (membuktikan ID unik per-run, bukan cuma kebetulan
  lulus sekali).
- Seluruh test suite AuliaPos (119 test, `phpunit.dist.xml`)
  dijalankan ulang -- LULUS, tidak ada regresi.
- `php -l`/`node --check` pada semua file yang diubah/dibuat.

## 12.8 Yang BELUM bisa saya verifikasi (perlu kamu jalankan)

- **`sock.onWhatsApp()` belum pernah dipanggil terhadap server
  WhatsApp sungguhan** (lihat CATATAN KEJUJURAN §12.2/§12.3) --
  **JANGAN mengklaim "LID<->PN resolved" sampai ini benar-benar
  dicoba dengan koneksi live**: kirim pesan dari HP customer yang
  akunnya diketahui muncul sebagai `@lid` di Gateway, cek log
  `[IDENTITY]`/`identityHint` untuk pesan PN dari nomor yang sama,
  pastikan `lid` yang di-resolve PERSIS SAMA dengan JID `@lid` yang
  sebelumnya diterima Gateway (bukan cuma "ada isinya", tapi harus
  cocok persis supaya reconciliation langkah 2 benar-benar kena).
- Migration Section 11 (termasuk kebutuhan revisi ini) **masih belum
  diterapkan ke `aulia_inboxdb` yang sebenarnya** -- lihat §11.8,
  status tidak berubah oleh revisi ini.
- UI "Konfirmasi Nomor" belum diklik langsung di browser.
- Endpoint `POST /send-media` milik Gateway (fitur Task Group
  sebelumnya) TIDAK disentuh/diverifikasi ulang di sesi ini -- di luar
  scope revisi ini.

---

# 13. Tahap 2 — Assignment / Ambil Chat (2026-09-13)

## 13.1 Apa ini

Mekanisme "siapa yang sedang menangani" satu conversation, terpisah
total dari status lifecycle Open/Closed (Section 12). Sebagian besar
sudah diimplementasikan sejak §9 (kolom `assigned_to`, endpoint
ambil/lepas, `cekOwnership()`, auto-assign reply pertama) -- Tahap 2
ini AUDIT ulang implementasi itu terhadap business rule resmi,
menemukan & memperbaiki 2 gap nyata (race condition, bug perbandingan
tipe data di UI), TANPA membuat mekanisme/tabel/permission baru.

**Kombinasi valid** (assignment BUKAN bagian dari status):
`OPEN+assigned`, `OPEN+unassigned`, `CLOSED+assigned`,
`CLOSED+unassigned`.

## 13.2 Definisi

- **Unassigned** (`assigned_to = NULL`): default conversation baru.
  Membuka/melihat conversation TIDAK PERNAH meng-assign (read-only).
- **Ambil** (`POST /inbox/percakapan/(:num)/ambil`,
  `Inbox::ambilPercakapan()`, SUDAH ADA sejak §9): staff mengklaim
  conversation yang belum ada assignee-nya. Tidak mengubah status
  open/closed, history, atau identitas customer.
- **Lepas** (`POST /inbox/percakapan/(:num)/lepas`,
  `Inbox::lepasPercakapan()`, SUDAH ADA sejak §9): kosongkan
  `assigned_to`. Status open/closed tidak berubah.
- **Ownership** (`Inbox::cekOwnership()`, SUDAH ADA sejak §9): boleh
  bertindak (balas/hapus/ambil/lepas/profil/konfirmasi nomor/tutup)
  kalau `assigned_to` NULL, ATAU milik user itu sendiri, ATAU role
  `admin`. **Tidak ada pembedaan permission staff/staff lain di luar
  ini** -- semua staff non-admin diperlakukan setara oleh
  `cekOwnership()` (tidak ada level "supervisor"/"team lead" dsb).
  Ini fondasi permission SATU-SATUNYA yang ada di codebase; Tahap 2
  TIDAK menambah struktur baru, murni dipakai apa adanya.
- **Auto-assign reply pertama** (`kirimKeConversation()`/
  `kirimMedia()`, SUDAH ADA sejak §9): begitu SATU staff berhasil
  mengirim balasan (text/media, benar-benar terkonfirmasi sukses oleh
  Gateway) ke conversation yang `assigned_to`-nya masih NULL,
  otomatis ter-assign ke staff itu. Auto-assign TIDAK PERNAH menimpa
  assignment yang sudah ada (dicek `empty($conversation['assigned_to'])`
  SEBELUM update).
- **Incoming customer tidak mengganti assignee**: `InboxGatewayApi::messages()`
  untuk `direction='incoming'` HANYA menyentuh `status`/`last_message_at`/
  `last_message_direction`/`whatsapp_name`/`phone` -- TIDAK PERNAH ada baris
  kode yang menulis `assigned_to` di endpoint ini. Diverifikasi ulang
  lewat audit source + test F/H (§13.6).
- **Hubungan dengan Open/Closed**: `tutupPercakapan()` (Section 12)
  HANYA menulis `status`/`closed_at`/`closed_by`, tidak pernah
  menyentuh `assigned_to` -- Close/reopen dan assignment adalah 2
  kolom independen yang masing-masing endpoint hanya menyentuh
  miliknya sendiri.

## 13.3 Gap yang ditemukan lewat audit & diperbaiki

1. **Race condition di `ambilPercakapan()`** -- versi lama: `find()`
   baca `assigned_to`, cek di PHP, baru `update()` terpisah. Dua
   request nyaris bersamaan bisa SAMA-SAMA lolos pengecekan "masih
   NULL" (dibaca sebelum salah satu sempat menyimpan). **Fix**: satu
   `UPDATE ... WHERE id=? AND assigned_to IS NULL` (non-admin) / tanpa
   syarat tambahan (admin, override diizinkan) dalam SATU statement --
   MySQL mengunci baris per-statement UPDATE, jadi kalau 2 request
   bentrok cuma SATU yang benar-benar mengubah baris. Dideteksi lewat
   `affectedRows() === 0` (kalah race atau memang sudah dipegang orang
   lain) vs `=== 1` (menang). Ditambah short-circuit idempotent kalau
   `assigned_to` sudah sama dengan user itu sendiri (klik dobel tidak
   dianggap gagal).
2. **Bug perbandingan tipe data di UI (`inbox/index.php`)** --
   `conv.assigned_to === currentUserId` (JS strict equality) SELALU
   `false` karena `assigned_to` dari MySQLi/JSON berupa **string**
   ("3"), sedangkan `currentUserId` berupa **number** (3) -- root
   cause PERSIS SAMA dengan bug `id` yang sudah pernah diperbaiki
   sebelumnya (lihat `cariConversation()`). Akibatnya tombol "Lepas"
   TIDAK PERNAH muncul untuk staff pemilik asli (hanya admin yang bisa
   lihat, karena kondisinya `punyaSaya || role==='admin'`), dan badge
   assignment selalu tampil warna "orang lain" walau itu percakapan
   milik sendiri. **Fix**: `String(conv.assigned_to) === String(currentUserId)`,
   pola yang sama persis dengan `cariConversation()`.

## 13.4 UI (`inbox/index.php`)

- Daftar percakapan (kiri): badge `Dipegang: <Nama>` (biru kalau diri
  sendiri, abu-abu kalau staff lain) atau `Belum diambil` (abu-abu
  muda) -- selalu tampil, tidak ada state kosong tanpa keterangan.
- Header thread (kanan): badge status `OPEN`/`CLOSED` (Section 12) +
  badge assignment (`Dipegang: <Nama>` / `Belum diambil`) + tombol
  `Ambil` (kalau unassigned) atau `Lepas` (kalau milik sendiri/admin) --
  TIDAK ADA istilah teknis `assigned_to` di UI mana pun.
- Tombol Edit/Hapus TETAP di daftar kiri (hasil kerja UI sebelumnya,
  TIDAK dikembalikan ke header).
- Render daftar percakapan dipanggil sekali di awal load
  (`renderDaftarConversation()`) supaya badge assignment langsung
  konsisten tanpa menunggu polling pertama (6 detik) -- satu sumber
  logic render (JS), tidak menduplikasi template di PHP.

## 13.5 Database

**Tidak ada migration baru.** `conversations.assigned_to` (logical
reference ke `aulia_kasirdb.users.id`) sudah ada sejak migration
Phase 1. Tidak ada tabel assignment/users baru.

## 13.6 Yang SUDAH saya verifikasi sendiri

- **Test A-K (persis acceptance test Tahap 2)** dijalankan sebagai
  assertion NYATA terhadap MySQL disposable (`aulia_inboxdb_migrationtest5`,
  dibuat & dihapus khusus) -- **SEMUA LULUS**, termasuk Test J (race
  condition: 2 percobaan "Ambil" ke conversation kosong yang sama,
  TEPAT 1 yang berhasil lewat `affectedRows()`) dan Test K (admin
  override/take-over lewat rule `cekOwnership()`/query atomic yang
  sudah ada, tidak ada permission baru). Test D (membuka conversation
  tidak auto-assign) diverifikasi structural: `apiMessages()`/
  `pilihConversation()` dipastikan tidak pernah menulis `assigned_to`.
  Database test dihapus, `.env` dikembalikan semula, `aulia_inboxdb`
  live diverifikasi ulang tidak kehilangan/bertambah conversation.
- `php -l` pada `Inbox.php`/`inbox/index.php`, `node --check` pada
  blok JS `inbox/index.php`.
- Regresi: seluruh test suite (119 test, `phpunit.dist.xml`) LULUS.

## 13.7 Yang BELUM bisa saya verifikasi (perlu Anda jalankan)

- Klik nyata "Ambil"/"Lepas" di browser dengan 2 akun berbeda
  (termasuk race condition sungguhan -- 2 device/tab menekan "Ambil"
  hampir bersamaan) -- baru diverifikasi lewat query/assertion
  langsung ke MySQL, BUKAN lewat HTTP/browser end-to-end.
- Tampilan badge "Dipegang:"/"Belum diambil" di layar sungguhan
  (sudah lolos `node --check`, belum diklik langsung).

---

# 14. Tahap 3 — Integrasi Open/Closed x Assignment (2026-09-13)

## 14.1 Apa ini

Status lifecycle (Section 12) dan assignment (Section 13) adalah **2
dimensi independen** yang HARUS tetap konsisten saat berinteraksi.
Tahap ini murni **AUDIT + verifikasi integrasi** -- tidak ditemukan
gap baru, TIDAK ADA perubahan kode. Section 12 & 13 (dikerjakan
terpisah) ternyata SUDAH memenuhi seluruh rule integrasi ini karena
masing-masing endpoint sudah didesain untuk hanya menyentuh kolom
miliknya sendiri sejak awal (lihat 14.3).

## 14.2 Empat state valid

| | UNASSIGNED | ASSIGNED(x) |
|---|---|---|
| **OPEN** | Valid | Valid |
| **CLOSED** | Valid | Valid |

Tidak ada state ketiga (`waiting`/`pending`/`reopened`/`taken`/
`resolved`) -- `status` kolom tetap `ENUM('open','closed')` apa
adanya (skema Phase 1, tidak diubah).

## 14.3 Kenapa integrasinya otomatis benar (audit source code)

Setiap operasi HANYA menulis kolom yang menjadi tanggung jawabnya:

| Operasi | Menulis | TIDAK PERNAH menulis |
|---|---|---|
| `InboxGatewayApi::messages()` (`incoming`) | `status='open'`, `last_message_*`, `whatsapp_name`, `phone` | `assigned_to` |
| `InboxGatewayApi::messages()` (`outgoing` sync WA Web/HP) | `last_message_*` saja | `status`, `assigned_to` |
| `Inbox::tutupPercakapan()` (Close) | `status='closed'`, `closed_at`, `closed_by` | `assigned_to` |
| `Inbox::ambilPercakapan()` (Ambil) | `assigned_to` (atomic, Tahap 2) | `status` |
| `Inbox::lepasPercakapan()` (Lepas) | `assigned_to=NULL` | `status` |
| `kirimKeConversation()`/`kirimMedia()` (reply, auto-assign) | `assigned_to` (HANYA kalau masih NULL), `last_message_*`, `last_replied_by` | `status` |

Karena tidak ada satu pun operasi yang menyentuh kolom di luar
tanggung jawabnya, ke-16 kombinasi transisi (status x assignment x
jenis event) otomatis konsisten TANPA perlu logic penggabungan/rule
baru apa pun.

## 14.4 Rule kunci yang diverifikasi ulang

- **Reopen TIDAK membuat conversation baru**: `resolveConversationId()`
  mencari lewat `chat_id`/alias (Section 11), sama sekali tidak
  peduli `status` -- conversation `CLOSED` tetap "ditemukan" oleh
  incoming berikutnya lewat jalur pencarian yang SAMA seperti kalau
  dia `OPEN`. Reopen = update `status` pada baris yang sama, bukan
  insert baru.
- **Close tidak menghapus assignment** kecuali diminta eksplisit:
  tidak ada baris kode di `tutupPercakapan()` yang menyentuh
  `assigned_to` -- diverifikasi lewat pembacaan source + Test E/G/K.
- **Incoming reopen tidak mengubah assignment**: Test F (unassigned
  tetap unassigned, TIDAK auto-assign ke siapa pun) dan Test G/K
  (assigned tetap assigned, TIDAK berubah/hilang/pindah).
- **Outgoing sync tidak mengubah lifecycle ATAUPUN assignment**: Test H.
- **Ownership tetap dari Section 13** (`cekOwnership()`) -- tidak ada
  role/permission baru. Staff hanya bisa Ambil conversation unassigned
  atau miliknya sendiri (Test M), admin override tetap berlaku (sudah
  dibuktikan Tahap 2 §13, tidak diulang di sini karena tidak ada
  perubahan).

## 14.5 Concurrency: Close vs incoming hampir bersamaan

**Diaudit, TIDAK ditemukan risiko duplicate conversation atau
assignment hilang**: `resolveConversationId()` mencari berdasarkan
`chat_id`/alias yang SUDAH ada sebelum race ini terjadi (conversation
itu sendiri bukan baru), jadi urutan eksekusi Close vs incoming tidak
mempengaruhi identitas conversation sama sekali -- keduanya
memperbarui BARIS YANG SAMA. `assigned_to` tidak disentuh oleh kedua
operasi, jadi tidak mungkin hilang akibat race ini.

**Satu ceiling yang disadari dan diterima** (ponytail: tidak
diperbaiki di sini, di luar scope "perubahan minimal"): kalau
kebetulan Close dan incoming benar-benar berbarengan, hasil akhir
kolom `status` mengikuti **UPDATE mana yang commit terakhir**
(last-write-wins) -- staff bisa saja menutup, lalu ternyata ada pesan
baru yang statusnya "keburu" tertimpa balik jadi closed. Ini BUKAN
korupsi data (tidak ada baris ganda, tidak ada kolom setengah-tertulis,
`closed_at`/`closed_by` tetap konsisten dengan `status` masing-masing
UPDATE) -- hanya soal siapa yang menang di detik yang sama, risiko
yang sama seperti sistem last-write-wins lain pada umumnya. Upgrade
path kalau suatu saat dibutuhkan: bungkus `tutupPercakapan()` dengan
`WHERE updated_at = <nilai yang dibaca>` (optimistic locking) seperti
pola atomic `ambilPercakapan()` di Tahap 2.

## 14.6 UI

Tidak ada perubahan kode -- diaudit ulang, `renderThreadHeader()` dan
`renderDaftarConversation()` (Tahap 1 + 2) SUDAH menghasilkan
kombinasi tombol yang benar tanpa perubahan:

| State | Tombol/Info yang tampil |
|---|---|
| OPEN + milik saya | badge `OPEN`, `Dipegang: <saya>`, tombol **Lepas**, tombol **Tutup** |
| OPEN + unassigned | badge `OPEN`, `Belum diambil`, tombol **Ambil**, tombol **Tutup** |
| OPEN + milik staff lain | badge `OPEN`, `Dipegang: <lain>`, TANPA tombol Lepas (bukan pemilik) |
| CLOSED (assigned/unassigned) | badge `CLOSED`, info assignee tetap tampil, TANPA tombol Tutup |

Tidak ada tombol "Open" manual (sesuai rule -- reopen hanya lewat
pesan masuk). Filter Semua/Open/Closed (Tahap 1) tidak terpengaruh
assignment sama sekali (filter murni berdasar `status`).

## 14.7 Yang SUDAH saya verifikasi sendiri

- **Test A-R (18 skenario, persis acceptance test Tahap 3)** dijalankan
  sebagai assertion NYATA terhadap MySQL disposable
  (`aulia_inboxdb_migrationtest6`, dibuat & dihapus khusus) --
  **SEMUA LULUS (18/18)**, termasuk kombinasi CLOSED+ASSIGNED/
  CLOSED+UNASSIGNED x incoming/outgoing sync, race condition Ambil
  (Test O), dan filter Open/Closed dengan data campuran. Database test
  dihapus, `.env` dikembalikan semula, `aulia_inboxdb` live tidak
  kehilangan/bertambah conversation akibat test ini.
- Audit source code penuh `InboxGatewayApi.php`, `Inbox.php`,
  `inbox/index.php` -- dikonfirmasi tidak ada satu baris pun yang
  melanggar pemisahan tanggung jawab kolom (14.3).
- Regresi: seluruh test suite (119 test, `phpunit.dist.xml`) LULUS.
- `AUDIT PASS` (bukan E2E) untuk seluruh rule UI (14.6) -- kode
  diverifikasi lewat pembacaan logic render, BUKAN diklik di browser.

## 14.8 Yang BELUM bisa saya verifikasi (perlu Anda jalankan)

**BELUM DIVERIFIKASI -- browser E2E**: seluruh skenario A-R di atas
BELUM diklik langsung di browser sungguhan (mis. 2 device kirim WA
asli ke conversation yang sama sambil staff menutupnya nyaris
bersamaan). Semua kelulusan di atas adalah hasil query/assertion
langsung ke MySQL, bukan hasil interaksi HTTP/UI nyata.

---

# 15. Tahap 5 — Unread / Read (2026-09-13)

**Status dokumentasi: aturan bisnis DISEPAKATI, BELUM DIIMPLEMENTASIKAN.**
Bab ini murni menetapkan business rule (arsitektur lanjutan:
Identity → Conversation → Open/Closed → Assignment → **Unread/Read**
→ Search/Filter → Reliability). Belum ada perubahan kode/skema untuk
Tahap 5 -- implementasi baru dikerjakan setelah bab ini ditinjau, lewat
alur yang sama seperti tahap-tahap sebelumnya: audit kode existing →
identifikasi gap → implementasi minimal → test disposable DB →
regression test → browser E2E → baru commit.

## 15.1 Tujuan

Chat AuliaPos harus bisa membedakan conversation yang sudah dibaca dan
belum dibaca, sebagai **dimensi keempat yang independen**, terpisah
dari:

- Identity customer
- Conversation
- Lifecycle Open/Closed
- Assignment

Read/Unread **bukan pengganti** status Open/Closed dan **bukan
pengganti** Assignment -- ketiganya punya fungsi berbeda dan harus
tetap bisa berubah sendiri-sendiri tanpa saling mempengaruhi kecuali
memang diatur eksplisit di bab ini.

## 15.2 Prinsip utama: Unread di level Conversation, bukan per-Message

Unread BUKAN status per-message. Kalau customer mengirim 3 pesan
berturut-turut, conversation itu berada pada SATU kondisi `UNREAD`,
bukan 3 penanda unread terpisah. Jumlah pesan baru boleh ditampilkan
sebagai info tambahan di UI, tapi state utamanya tetap Unread pada
level conversation.

## 15.3 Hubungan dengan Assignment

- **Conversation assigned** (`assigned_to = User A`): Read/Unread
  menjadi tanggung jawab User A secara spesifik. Staff lain boleh
  melihat conversation itu, tapi aktivitas mereka (membuka, membaca)
  **tidak mengubah** status Read/Unread milik User A.
- **Conversation unassigned** (`assigned_to = NULL`): berada di inbox
  bersama. Pesan baru membuatnya `UNREAD` dan terlihat oleh semua
  staff yang punya akses inbox. **Membuka saja TIDAK menghilangkan
  Unread** selama conversation belum diambil (`Ambil`).

## 15.4 Kapan conversation menjadi READ

- **Assigned**: membuka conversation TIDAK otomatis membuat pesan
  terbaru jadi Read. Conversation menjadi `READ` hanya ketika assignee
  BENAR-BENAR melihat/mencapai pesan terbaru (pesan terbaru terlihat
  di viewport) -- bukan berdasarkan tindakan scroll manual, bukan
  berdasarkan sekadar membuka halaman.
- **Unassigned**: staff yang membuka tanpa melakukan `Ambil` TIDAK
  menghilangkan Unread.
- **Sedang mengetik balasan**: BUKAN bukti pesan sudah dibaca -- pesan
  customer yang masuk saat assignee sedang mengetik tetap `UNREAD`.
- **Tab/browser tidak aktif**: pesan yang masuk saat AuliaPos di
  tab/browser tidak aktif tetap `UNREAD` sampai assignee benar-benar
  kembali dan melihat pesan terbarunya.

## 15.5 Ambil Chat = ambil tanggung jawab + tandai terbaca

Ketika staff melakukan `Ambil` pada conversation unassigned:
`assigned_to = NULL` → `assigned_to = <user>` **DAN** conversation
langsung dianggap `READ` oleh user itu (kondisi pesan yang sedang ada
saat itu dianggap sudah dilihat). `Ambil` punya 2 makna sekaligus:
mengambil tanggung jawab, dan menandai pesan yang ada sebagai sudah
dibaca.

## 15.6 Pesan masuk (semua tipe media)

Setiap pesan incoming dari customer (text/image/document/audio/video,
dan tipe lain yang didukung Gateway di masa depan) menghasilkan
`UNREAD` kalau belum dilihat oleh assignee -- tidak ada pengecualian
berdasarkan `message_type`.

## 15.7 Persistensi & konsistensi multi-device/multi-tab

- Status Read/Unread **wajib** disimpan persisten di database --
  TIDAK BOLEH hanya bergantung pada `localStorage`/session
  browser/state JS/tab/device tertentu. Browser ditutup lalu dibuka
  lagi, status Unread di database tetap seperti sebelumnya.
- Read/Unread berlaku GLOBAL untuk satu user pada satu conversation,
  bukan per-device/per-tab. Kalau user yang sama membaca pesan
  terbaru dari Device B, Device A ikut menjadi Read setelah
  sinkronisasi (bukan status Read/Unread terpisah per device).
- User lain (atau admin) yang sekadar membuka conversation milik
  assignee lain, **TIDAK mengubah** Read/Unread milik assignee
  tersebut -- kecuali memang melakukan Takeover (lihat 15.8).

## 15.8 Efek aksi lifecycle/assignment terhadap Read/Unread

| Aksi | Efek terhadap Read/Unread |
|---|---|
| **Close** | TIDAK mengubah Read/Unread sama sekali (murni lifecycle) |
| **Customer kirim pesan setelah Closed** | conversation kembali OPEN dengan assignee yang SAMA, dan menjadi `UNREAD` (harus kembali muncul di filter Open, keluar dari filter Closed) |
| **Lepas** | conversation kembali ke inbox bersama DAN menjadi `UNREAD` untuk inbox tersebut (assignment lama tidak lagi relevan) |
| **Ambil setelah Lepas** | sama seperti 15.5 -- `assigned_to` terisi + langsung `READ` |
| **Takeover** (assigned_to A → assigned_to B, termasuk oleh Admin) | conversation menjadi `UNREAD` untuk assignee BARU, terlepas dari status Read sebelumnya milik assignee lama -- status Read lama adalah bukti "assignee LAMA sudah melihat", bukan bukti assignee baru sudah melihat |
| **Outgoing dari AuliaPos** (reply oleh assignee) | TIDAK menghasilkan Unread -- assignee yang membalas otomatis dianggap sudah melihat pesan terbaru (jadi `READ`) |

## 15.9 Activity & urutan daftar conversation

Incoming maupun outgoing SAMA-SAMA merupakan "aktivitas conversation"
yang menentukan urutan (aktivitas terbaru = paling atas daftar).
**Sorting berdasarkan aktivitas TIDAK SAMA dengan Read/Unread** --
dua konsep yang independen: conversation bisa saja aktif baru-baru
ini (naik ke atas daftar) tapi tetap Read (misalnya karena outgoing
reply), atau sebaliknya.

## 15.10 UI: dua tingkat indikator

1. **Badge total** pada Inbox: jumlah CONVERSATION yang Unread (bukan
   jumlah message Unread), mis. `Inbox 5`.
2. **Indikator per-conversation**: penanda visual (mis. titik/dot) di
   setiap baris conversation yang sedang Unread di daftar.

## 15.11 Delete Conversation (klarifikasi hak akses & prasyarat)

- Delete adalah tindakan destruktif, **hanya boleh dilakukan Admin**.
- Conversation harus **CLOSED** dulu sebelum bisa dihapus (urutan:
  OPEN → Close → CLOSED → Delete).
- Saat dihapus, SELURUH state ikut hilang: Read/Unread, assignment,
  lifecycle, messages.
- Kalau customer mengirim pesan baru setelah conversation-nya
  dihapus, sistem membuat conversation BARU (identitas lama sudah
  tidak ada lagi untuk di-reconcile).

> **CATATAN AUDIT (dicatat, bukan diimplementasikan di bab ini)**:
> implementasi `Inbox::hapusPercakapan()` SAAT INI (lihat Section 8)
> **belum** mensyaratkan conversation harus CLOSED lebih dulu, dan
> **belum** membatasi aksi ini hanya untuk Admin (memakai
> `cekOwnership()` yang sama dengan aksi lain, bukan pengecekan
> role admin khusus). Ini GAP antara dokumentasi Tahap 5 (aturan baru
> yang disepakati) dengan kode existing (Section 8/9) -- akan
> diselesaikan sebagai bagian dari audit implementasi Tahap 5, BUKAN
> diubah diam-diam lewat bab dokumentasi ini.

## 15.12 Hak akses (ringkasan, tidak membuat role baru)

| Operasi | Assignee | Staff lain | Admin |
|---|---|---|---|
| Lihat conversation | Ya | Ya* | Ya |
| Ambil | Ya | Ya | Ya |
| Lepas | Ya | Tidak | Ya |
| Close | Ya | Tidak | Ya |
| Edit profil | Ya | Tidak | Ya |
| Hapus | Tidak | Tidak | Ya |
| Takeover | — | — | Ya |

\* mengikuti aturan visibility inbox yang sudah ada (Section 13).

**Role "Shift Leader" belum termasuk** dalam spesifikasi ini --
penambahan role tersebut dibahas & didokumentasikan terpisah, TIDAK
dibuat sebagai bagian dari Tahap 5.

> **CATATAN AUDIT**: fondasi permission existing (`cekOwnership()`,
> Section 13) hanya mengenal 2 level: staff vs `role==='admin'`.
> Tabel di atas konsisten dengan itu -- TIDAK memerlukan permission
> baru untuk operasi Ambil/Lepas/Close/Edit profil (sudah persis
> perilaku `cekOwnership()` yang ada). Kolom "Hapus: hanya Admin"
> ADALAH aturan baru dibanding implementasi Section 8 saat ini (lihat
> catatan gap di 15.11).

## 15.13 Prinsip pemisahan state (3 dimensi independen)

```
CONVERSATION
├── Lifecycle:   OPEN / CLOSED
├── Assignment:  NULL / User ID
└── Read State:  READ / UNREAD
```

Perubahan satu dimensi TIDAK BOLEH secara tidak sengaja mengubah
dimensi lain, kecuali interaksi yang eksplisit diatur di bab ini
(ringkasan lihat tabel 15.8 dan matriks 15.14).

## 15.14 Matriks transisi (acceptance test untuk implementasi nanti)

| Kondisi awal | Aksi | Hasil |
|---|---|---|
| Unassigned + Unread | Buka (tanpa Ambil) | Tetap Unread |
| Unassigned + Unread | Ambil | Assigned + Read |
| Assigned + Unread | Assignee melihat pesan terbaru | Read |
| Assigned + Read | Customer kirim pesan | Unread |
| Assigned + Read | Close | Closed + Read |
| Closed + Read (assigned) | Customer kirim pesan | Open + Unread (assignee SAMA) |
| Assigned + Read | Lepas | Unassigned + Unread |
| Assigned A + Read | Takeover oleh B | Assigned B + Unread |
| Assigned + Unread | Staff lain buka | Tetap Unread |
| Assigned + Unread | Admin buka TANPA takeover | Tetap Unread |
| Assigned + Read | Outgoing dari POS | Tetap Read |
| Closed | Delete oleh Admin | Conversation terhapus (semua state ikut hilang) |
| (Deleted) | Customer kirim pesan | Conversation BARU dibuat |

## 15.15 Di luar scope Tahap 5

Bab ini TIDAK menentukan (dibahas terpisah kalau/ketika dibutuhkan):

- Desain role Shift Leader
- Notifikasi push / suara / desktop
- SLA, escalation
- Assignment otomatis berdasarkan Shift Leader
- Unread per-message (tetap per-conversation, lihat 15.2)
- Read receipt WhatsApp (centang biru dsb -- itu WhatsApp-native, di
  luar sistem Read/Unread internal AuliaPos ini)
- Indikator "customer sedang mengetik"
- Mekanisme sinkronisasi real-time tertentu yang belum ditentukan
  (polling vs lainnya) -- lihat audit polling existing di Section 12/13
  saat implementasi nanti dimulai

---

