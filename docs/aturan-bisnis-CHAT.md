# Dokumentasi Modul Shared WhatsApp Inbox (Chat) — AuliaPos v3.x

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

