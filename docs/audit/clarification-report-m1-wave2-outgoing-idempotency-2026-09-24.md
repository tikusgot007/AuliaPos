# 🔍 Clarification Report [Review Iteration 1]

**Target Document:** `spec/spec-process-m1-wave2-outgoing-idempotency.md` (v1.0)
**Reference Documents:** `spec/spec-process-m1-wave1-incoming-reliability.md` v1.1, `docs/GATEWAY-REQUIREMENTS.md` (GW-09, GW-19), `docs/decisions/2026-09-21-m1-ticket01-baseline.md`, dan kode nyata `C:\projects\WA-Gateway` @ `21a4cb6` + `app/Controllers/Inbox.php` / `app/Controllers/InboxGatewayApi.php`.

**Readiness Score:** 88/100 (skor mentah 88, veto tidak aktif)
**Status:** Good Enough

**Score Breakdown (proyeksi setelah R-1, R-2, dan R-3):**

- **Completeness (max 40):** 34 - R-1..R-3 melengkapi jalur `409 SEND_IN_PROGRESS`, jalur `abandoned`, dan batas panjang kunci dengan angka eksplisit. Sisa kekurangan: E-O4 masih tanpa REQ/AC formal (perilakunya hanya tertulis sebagai prosa di §4.7), tak ada AC untuk `pruneTerminal` vs jaminan idempotensi, tak ada AC untuk urutan validasi `/send-media`.
- **Clarity (max 30):** 28 - Semua ambang dan transisi kini eksplisit dan dapat diuji. Sisa: enum `reason` bercabang (H-4) dan urutan penulisan baris operasi di `/send-media` belum ditentukan (H-5).
- **Alignment (max 30):** 26 - Sisa: jalur gagal definitif masih diklaim `502` sementara kode berjalan membalas `500` (C-3), klasifikasi `422` yang tidak eksis di AuliaPos (H-7), dan §4.7 langkah 1 vs ASSUMPTION-010 (H-1).
- **Critical Flaw Veto:** **No** - Setelah R-1 (lease > timeout klien), R-2 (semantik cap), dan R-3 (batas panjang kunci), tidak ada lagi kontradiksi mendasar yang dapat berujung pada duplikat atau pesan yang tidak pernah terkirim.

**Score Breakdown (sebelum R-1, iterasi awal):**

- **Completeness (max 40):** 31 - Semua perilaku Gateway (E-O1..E-O3) punya REQ + AC yang terlacak. Yang hilang: E-O4 tidak punya satu pun REQ (hanya CON-009 + AC-041), sehingga langkah 4 dan 5 di §4.7 (UI "hasil belum pasti", paksaan `operation_id` baru) tidak punya kontrak maupun AC; tidak ada AC untuk interaksi `pruneTerminal` vs jaminan idempotensi; tidak ada AC untuk urutan validasi payload media terhadap pembuatan baris operasi.
- **Clarity (max 30):** 20 - Semantik cap `attempts` untuk operasi keluar bertabrakan antara REQ-029, tabel transisi §4.2, dan AC-029 (off-by-one). Urutan penulisan baris `outgoing_operations` di dalam `/send-media` (relatif terhadap decode base64, `INVALID_MEDIA_*`, dan `isConnected()`) tidak ditentukan. Kosakata `reason` dead-letter memakai tiga nilai sementara hanya dua yang dideklarasikan.
- **Alignment (max 30):** 25 - Dua kontradiksi terhadap kode berjalan: (a) jalur error definitif diklaim `502` padahal `ci4Routes.js:94` membalas `500`; (b) D-06 menyebut `422` padahal `InboxGatewayApi.php` tidak pernah membalas `422`. Konflik juga antara §4.7 langkah 1 dan ASSUMPTION-010 soal siapa pemilik `operation_id`.
- **Critical Flaw Veto:** **Yes** - AC-027 adalah satu-satunya AC unggulan yang diukur nyata (Ticket 01 Baseline 3), tetapi hasilnya **tidak deterministik** dengan nilai bawaan yang tertulis (`OUTGOING_LEASE_MS=15000` vs timeout klien 10 detik teks / 30 detik media). Jaminan inti GW-09 karena itu belum benar-benar tertutup oleh spec ini.

---


## 1. 🚨 Critical Findings (Blockers)

### C-1 (FLAGSHIP, memicu veto) - Lease 15 detik vs timeout klien 10/30 detik

- **Requirement:** "`OUTGOING_LEASE_MS` | `15000` | Usia `in_flight` yang masih dianggap \"sedang dikerjakan\"" (§4.6) dan "AC-027 ... Then Gateway membalas `replayed:true` tanpa mengirim pesan kedua, dan pelanggan hanya menerima satu pesan."
- **Issue:** Retry kasir selalu datang **setelah** timeout klien, tetapi lease 15 detik lebih panjang dari timeout teks dan lebih pendek dari timeout media:
  - Teks (`Inbox.php:2047-2048`, `CURLOPT_TIMEOUT = 10`): retry pada t≈10 s masih **di dalam** lease → jawaban `409 SEND_IN_PROGRESS`, bukan `replayed:true`. AC-027 hanya lulus kalau percobaan pertama kebetulan sudah selesai.
  - Media (`Inbox.php:2112`, `CURLOPT_TIMEOUT = 30`): timeout klien **melewati** lease, sehingga operasi `in_flight` diklasifikasikan "sisa crash" dan **dikirim ulang** → duplikat, tepat pada skenario yang ingin ditutup (REQ-028 + D-05).
  - §12 Kasus 3 mengonfirmasi perilaku ini ("menunggu > lease lalu kirim ulang op=K3 -> `attempts=2`"), jadi ini bukan salah tafsir.
- **Kenapa memblokir:** AC-027 adalah satu-satunya AC dengan bukti nyata dan merupakan inti GW-09. Selama hubungan lease/timeout belum diputuskan, spec tidak bisa dites secara deterministik maupun dideploy dengan jaminan yang diklaimnya.
  **→ DIPUTUSKAN pada iterasi ini (opsi A). Lihat §2 item R-1; spec WAJIB disesuaikan di `/sdlc-define-specs`.**

### C-2 - Semantik cap `attempts` operasi keluar saling bertabrakan (off-by-one)

> **→ DIPUTUSKAN pada iterasi ini (opsi A). Lihat §2 item R-2; spec WAJIB disesuaikan di `/sdlc-define-specs`.**

- **Requirement:** REQ-029 "Begitu `attempts >= OUTGOING_MAX_ATTEMPTS`, Gateway MUST mengubah state menjadi `abandoned` ... dan MUST NOT memanggil Baileys lagi untuk operasi itu"; tabel §4.2 "`in_flight` | percobaan ulang & `attempts + 1 >= cap` | `abandoned`"; AC-029 "percobaan ulang dilakukan sampai `attempts` mencapai `OUTGOING_MAX_ATTEMPTS`, Then state menjadi `abandoned`".
- **Issue:** Ketiga kalimat menghasilkan jumlah kiriman berbeda. Dengan `begin()` menulis `attempts = 1` (§4.1) dan cap 5: REQ-029 = 5 kiriman total (retry yang menaikkan ke 5 tetap dieksekusi, permintaan berikutnya di-abandon tanpa kirim); tabel §4.2 = 4 kiriman total (retry yang akan menaikkan ke 5 langsung di-abandon); AC-029 tidak bisa membedakan keduanya karena hanya memeriksa state akhir.
- **Kenapa memblokir:** Implementasi dan skrip uji akan memilih tafsir yang berbeda. Ini juga menentukan apakah operasi yang ambigu masih punya satu kesempatan terakhir atau tidak — persis trade-off duplikat-vs-tidak-terkirim yang menjadi alasan D-05 dipilih.

### C-3 - Status code jalur gagal definitif: `502` (spec) vs `500` (kode berjalan)

> **→ AUTO-RESOLVED via PROCEED. Lihat §3 item A-1.**

- **Requirement:** §4.3 "Percobaan baru gagal definitif | `502` | `SEND_FAILED`/`INVALID_CHAT_ID`" dan AC-026 "Then state berturut-turut `sent` (`200`), `failed` (`502`)".
- **Issue:** `ci4Routes.js:91-99` (dan `:223-231` untuk media) membalas **`500`** untuk setiap `catch`, dengan `error_code: err.code || 'SEND_FAILED'`. Spec meminta `502`, yaitu **perubahan perilaku endpoint yang sudah dipakai AuliaPos** — padahal CON-007 hanya mengizinkan penambahan additive dan baris terakhir §4.3 justru berjanji "Validasi field lain gagal | `400` | tetap seperti sekarang".
- **Kenapa memblokir:** Perubahan status code pada jalur yang sudah hidup harus dinyatakan eksplisit (dampak, rollback, kompatibilitas AuliaPos), bukan disisipkan sebagai baris matriks. AuliaPos sendiri tidak terpengaruh (`Inbox.php:2062` hanya menerima 2xx), tetapi pernyataan "additive" di CON-007 menjadi tidak benar.
### C-4 - ASSUMPTION-006 mengklasifikasikan error yang secara struktural tidak mungkin muncul dari Baileys

> **→ AUTO-RESOLVED via PROCEED. Lihat §3 item A-2.**

- **Requirement:** ASSUMPTION-006 "Setiap error yang dilempar Baileys **setelah** operasi masuk `in_flight` dianggap ambigu ... kecuali kode error eksplisit `INVALID_CHAT_ID` yang diklasifikasikan sebagai gagal definitif (`failed`, HTTP 502)", dan REQ-027 "`failed` + `last_error` bila error terklasifikasi definitif (`INVALID_CHAT_ID`)".
- **Issue:** `INVALID_CHAT_ID` **bukan** error Baileys. Ia di-set di `connectionManager.js:891` dan `:1037` pada guard `isDecodableJid()` yang berjalan **sebelum** `sendMessage()`. Sebelum itu, `ci4Routes.js:41` (`/send`) dan `:124` (`/send-media`) sudah menolak JID yang sama dengan `400 INVALID_CHAT_ID`. Artinya:
  - `failed` sebagai state terminal menjadi **praktis tidak terjangkau** pada lalu lintas normal;
  - tidak ada satu pun error Baileys nyata yang diklasifikasikan definitif, sehingga seluruh cabang "definitive failure" bersifat spekulatif;
  - `markFailed()` mungkin tidak pernah dipanggil di produksi, sehingga AC-026(b) hanya bisa lulus lewat stub — harness uji akan "hijau" tanpa membuktikan apa pun.
- **Kenapa memblokir:** Ini menentukan apakah state `failed` layak ada. Bila tidak, state machine §4.2 dan REQ-027 harus disederhanakan (dan spec harus jujur menyatakannya), atau klasifikasi definitif harus ditambah sumber yang nyata.

### C-5 - Panjang `operation_id` tidak konsisten (REQ-020 128 karakter vs kolom 64)

> **→ DIPUTUSKAN pada iterasi ini (opsi B). Lihat §2 item R-3; veto dilepas.**

- **Requirement:** REQ-020 "field opsional `operation_id` berupa string 1–128 karakter" vs §4.7 "`messages.gateway_operation_id VARCHAR(64) NULL` dengan indeks UNIQUE".
- **Issue:** `operation_id` sepanjang 65–128 karakter sah di Gateway tetapi tidak muat di kolom AuliaPos → pada MySQL mode ketat `insert` gagal (dan dengan indeks UNIQUE kegagalannya muncul sebagai error duplikasi yang menyesatkan); pada mode non-ketat nilai terpotong sehingga dedupe `gateway_operation_id` **salah cocok/meleset**.
- **Kenapa memblokir:** Salah satunya adalah kontrak yang salah. Perlu satu angka kanonik (128 di kedua sisi, atau 64 dengan penolakan `400` di Gateway).

### C-6 - E-O4 tidak punya REQ; prasyarat AuliaPos tidak tertulis dan bertentangan dengan kode nyata

> **→ AUTO-RESOLVED via PROCEED. Lihat §3 item A-3.**

- **Requirement:** §1 "E-O4 — Sisi pemanggil AuliaPos ... penanganan respons replay/ambigu di frontend."; §4.7 langkah 4-5; dan baris "bentuk `{success, wa_message_id, timestamp, media_ref, error_code, message}` tetap dibaca `callGatewaySend()` seperti sekarang".
- **Issue:** Seluruh scope E-O4 tidak memiliki REQ (REQ-020..REQ-038 semuanya Gateway), sehingga klaim §6 "setiap REQ punya minimal satu AC" benar tetapi menutupi bahwa **tidak ada AC sama sekali** untuk perilaku UI "hasil belum pasti", paksaan `operation_id` baru saat `409 OPERATION_ID_REUSED`, dan dedupe sisi AuliaPos selain AC-041. Lebih jauh, klaim §4.7 bahwa `callGatewaySend()` sudah cukup untuk membedakan `409 SEND_IN_PROGRESS` / `504 SEND_UNRESOLVED` / `409 OPERATION_ID_REUSED` **bertentangan dengan kode**: `Inbox.php:2062-2074` hanya membaca `success`, `wa_message_id`, `timestamp`, dan `message` — `error_code` dan `state` tidak pernah diekstrak. Jadi langkah 4 dan 5 tidak dapat diimplementasikan tanpa perubahan signature yang belum dispesifikasikan.
- **Kenapa memblokir:** Ini separuh dari nilai bisnis gelombang 2 (kasir berhenti mengirim ulang tanpa berpikir) dan saat ini tidak punya kontrak yang bisa dites.

---

## 2. 🧩 Resolved Items & Agreements

Tiga item diputuskan pada iterasi ini (R-1..R-3); sisa temuan diselesaikan lewat PROCEED dan dicatat di §3 (A-1..A-8).

### R-1 - Aturan lease vs timeout klien (menutup C-1)

- **Pertanyaan (Q-1):** `OUTGOING_LEASE_MS=15000` lebih panjang dari timeout teks AuliaPos (10 detik) tetapi lebih pendek dari timeout media (30 detik), sehingga hasil retry kasir setelah timeout tidak deterministik.
- **Keputusan pemilik proyek:** **Opsi A** - `OUTGOING_LEASE_MS` dinaikkan di atas timeout klien terpanjang (default baru `35000` ms). Retry yang datang setelah timeout klien (teks 10 detik maupun media 30 detik) karena itu **selalu berada di dalam lease** dan MUST dijawab `409 SEND_IN_PROGRESS` tanpa memanggil Baileys. Kiriman ulang hanya terjadi bila lease benar-benar sudah lewat (mis. proses Gateway mati saat mengirim).
- **Konsekuensi yang harus ditulis di spec:**
  1. §4.6: nilai bawaan `OUTGOING_LEASE_MS` berubah dari `15000` menjadi `35000`, dengan catatan relasinya terhadap `CURLOPT_TIMEOUT` AuliaPos (10 detik teks / 30 detik media) sebagai dasar angka tersebut.
  2. §4.2: transisi `in_flight` + retry di dalam lease → tetap `in_flight` (`409`), bukan percobaan ulang.
  3. **AC-027 ditulis ulang:** Given kasir mengirim dan cURL AuliaPos timeout 10 detik, When kasir menekan kirim ulang sebelum lease lewat, Then Gateway membalas `409 SEND_IN_PROGRESS` dengan pesan "mungkin sudah terkirim", `sock.sendMessage` **tidak** dipanggil lagi, dan pelanggan menerima **paling banyak satu** pesan. Perlu AC tambahan: retry **setelah** lease lewat dengan operasi yang sudah `sent` → `200 replayed:true`.
  4. §1.2/ASSUMPTION-010: UI MUST menampilkan keadaan "hasil belum pasti, jangan kirim ulang dulu" (menguatkan §4.7 langkah 4 yang sudah ada).
  5. Batas jujur yang harus tetap dinyatakan: bila proses Gateway mati tepat setelah WhatsApp menerima pesan, retry **setelah lease lewat** masih dapat menduplikasi (ASSUMPTION-009 tidak berubah).
- **Dampak ke laporan:** C-1 tertutup setelah spec diperbarui. Veto masih berlaku karena C-3/C-4/C-5/C-6 (dan C-5 memegang veto) belum diputuskan.

### R-2 - Semantik cap percobaan operasi keluar (menutup C-2)

- **Pertanyaan (Q-2):** Dengan `begin()` menulis `attempts = 1` dan `OUTGOING_MAX_ATTEMPTS = 5`, berapa kali pesan sebenarnya boleh dikirim dan di mana pemeriksaan cap dilakukan?
- **Keputusan pemilik proyek:** **Opsi A** - `attempts` berarti **jumlah kiriman yang sudah benar-benar dijalankan**. Pemeriksaan cap dilakukan **sebelum** kirim ulang: bila `attempts >= OUTGOING_MAX_ATTEMPTS`, operasi langsung menjadi `abandoned` **tanpa** memanggil Baileys. Dengan nilai bawaan, satu operasi mengirim **paling banyak 5 kali** (1 percobaan awal + 4 percobaan ulang); setiap percobaan ulang menunggu lease (35 detik) lewat lebih dulu.
- **Konsekuensi yang harus ditulis di spec:**
  1. REQ-029 dipertahankan apa adanya (sudah sesuai tafsir ini); **tabel §4.2 baris `attempts + 1 >= cap` diperbaiki** menjadi pemeriksaan `attempts >= cap` yang dilakukan sebelum `registerRetry()`/`sendMessage()`.
  2. AC-029 diberi angka eksplisit: percobaan ke-1..ke-5 memanggil `sock.sendMessage` (attempts 1..5); permintaan ke-6 membalas `502 DEAD_LETTERED` dengan `state='abandoned'` **tanpa** panggilan Baileys.
  3. Contoh kode di §8 (`runOperation`) harus memuat pemeriksaan cap sebelum `registerRetry()`, supaya contoh dan tabel tidak lagi berbeda.
  4. Kap `DELIVERY_MAX_ATTEMPTS` (antrean masuk) tetap memakai basis berbeda (`attempts` mulai dari 0 lalu naik per kegagalan, lihat `incomingBuffer.js:177`); perbedaan basis ini MUST disebut eksplisit di §4.6 agar tidak dianggap inkonsistensi baru (lihat H-6).

### R-3 - Batas panjang `operation_id` (menutup C-5, melepas veto)

- **Pertanyaan (Q-3):** Kontrak Gateway mengizinkan `operation_id` 1–128 karakter (REQ-020), sedangkan kolom `messages.gateway_operation_id` dideklarasikan `VARCHAR(64)` + UNIQUE (§4.7), sementara kunci nyata hanya 32 karakter (hex server) atau 36 karakter (UUID frontend).
- **Keputusan pemilik proyek:** **Opsi B** - REQ-020 disempitkan menjadi **1–64 karakter** dan kolom AuliaPos tetap `VARCHAR(64)`.
- **Konsekuensi yang harus ditulis di spec:**
  1. REQ-020: ubah rentang menjadi `1–64` karakter (pola `^[A-Za-z0-9._:-]+$` tidak berubah).
  2. AC-019: batas uji bergeser - `operation_id` 65 karakter ke atas MUST dibalas `400 INVALID_OPERATION_ID` tanpa baris `outgoing_operations` dan tanpa panggilan Baileys.
  3. §4.7: nilai `VARCHAR(64)` dipertahankan, dan MUST diberi catatan eksplisit bahwa batas kolom sengaja disamakan dengan batas validasi supaya **pemotongan senyap tidak mungkin** terjadi.
  4. §4.6/§2 Definitions: catat bahwa panjang kunci yang dihasilkan sekarang adalah 32 karakter (`bin2hex(random_bytes(16))`) atau 36 karakter (`crypto.randomUUID()`), sehingga batas 64 karakter menyisakan ruang 1,7× tanpa memaksa perubahan generator.
- **Dampak ke laporan:** C-5 tertutup. Sejak item ini diputuskan, tidak ada lagi kontradiksi mendasar yang berpotensi menghasilkan duplikat atau pesan hilang, sehingga **veto Critical Flaw dicabut** dan skor naik ke 88/100.

### 2.1 Hidden Assumptions & Decision Requests (diselesaikan lewat PROCEED)

Temuan berikut **tidak memblokir** sendiri, tetapi masing-masing mengubah perilaku yang bisa diamati. Semuanya **telah diselesaikan** lewat PROCEED (§3 A-4..A-8) dan disimpan di sini sebagai jejak audit; tidak ada lagi item terbuka:

- **H-1 (kontradiksi pemilik kunci `operation_id`):** ASSUMPTION-010 menyatakan frontend membuat `crypto.randomUUID()` dan memakainya ulang saat kirim ulang, tetapi §4.7 langkah 1 menyatakan server membuat `bin2hex(random_bytes(16))` bila request tidak mengirimnya. Bila server membuat kunci baru pada setiap request, jalur "kasir muat ulang halaman" memperoleh **kunci baru setiap percobaan** → nol idempotensi, satu baris `outgoing_operations` per percobaan, dan sinyal `warn` REQ-026 justru tertutup. Rekomendasi: jadikan frontend pemilik tunggal kunci, dan bila `operation_id` kosong biarkan Gateway **tidak** mencatat operasi (perilaku lama + warn), bukan membuat kunci di server.
- **H-2 (`pruneTerminal` vs jaminan idempotensi):** REQ-032 menghapus baris terminal setelah `OUTGOING_OPERATION_TTL_MS` (24 jam). Retry setelah TTL membuat operasi baru → untuk baris `sent` terjadi **kiriman duplikat**, dan untuk baris `abandoned` catatan dead-letter hilang sehingga pesan yang sudah dihentikan bisa terkirim lagi. Tidak ada REQ/AC yang menguji interaksi ini. Rekomendasi: nyatakan batas jaminan idempotensi sebagai "≤ TTL" secara eksplisit di §1.2 dan tambahkan satu AC negatif.
- **H-3 (`replayDeadLetter` tidak mengubah `attempts`):** REQ-037 mempertahankan `attempts`. Untuk baris `dead` karena `max_attempts`, hasilnya adalah **tepat satu** percobaan tambahan sebelum mati lagi. Perilaku ini masuk akal tetapi tidak tertulis; AC-037 hanya memeriksa baris kembali ke antrean. Rekomendasi: tuliskan eksplisit "replay memberi satu siklus percobaan lagi" (atau tentukan `attempts` di-reset −1).
- **H-4 (kosakata `reason` dead-letter bercabang):** REQ-035 menyebut alasan `max_attempts` / `max_age`, sementara §12 Kasus 6 memakai `permanent_rejection`. Tiga nilai beredar, dua dideklarasikan. Rekomendasi: tetapkan satu enum `max_attempts | max_age | permanent_rejection`.
- **H-5 (urutan penulisan baris operasi di `/send-media`):** REQ-021 hanya menyatakan "sebelum memanggil Baileys". Relatif terhadap decode base64, `INVALID_MEDIA_*`, dan `isConnected()` tidak ditentukan. AC-019 hanya menjamin "tidak ada baris" untuk `operation_id` tidak valid, sehingga payload media yang ditolak berpotensi meninggalkan baris `in_flight` — bertentangan dengan semangat REQ-030. Rekomendasi: semua validasi payload (termasuk decode media dan hash) MUST terjadi sebelum `begin()`.
- **H-6 (asimetri counter & nilai `reason` di log):** `begin()` menulis `attempts = 1` sementara `incoming_queue` mulai dari `0` dan naik lewat `attempts + 1`; cap berarti "5 kiriman" di satu sisi dan "100 kegagalan" di sisi lain. Rekomendasi: beri nama konsepnya (mis. kap `OUTGOING_MAX_SENDS` vs `DELIVERY_MAX_FAILURES`) supaya off-by-one C-2 tidak terulang.
- **H-7 (`422` tidak eksis):** D-06 mengklasifikasikan `422` sebagai penolakan permanen, padahal `InboxGatewayApi.php` hanya membalas `400`, `500`, dan `200`. Klasifikasi `422` akan menjadi kode mati. Rekomendasi: pertahankan sebagai jaring pengaman tetapi catat sebagai `[Assumed / Out of Scope]`, atau hapus agar tabel tidak menyiratkan kemampuan yang tidak ada.
- **H-8 (`400` dapat berasal dari bug Gateway, bukan pesan beracun):** `InboxGatewayApi.php:58/74/113/170/343` membalas `400` berdasarkan payload yang **dibangun Gateway**. Bug payload sistemik akan memindahkan seluruh antrean ke `dead` (bukan satu pesan beracun). Rekomendasi: tambahkan guard "jika baris `dead` bertambah > N dalam satu siklus, log `[CRITICAL]` dengan instruksi hentikan replay otomatis" — cukup satu baris kebijakan, bukan fitur baru.

---

## 3. ⚠️ Assumed / Auto-Resolved / Out of Scope (The 20% we skip)

Pemilik proyek memilih **PROCEED** pada skor 88/100. Sesuai protokol Quality Gate, pertanyaan yang tidak diajukan diselesaikan dengan rekomendasi teknis analis dan dicatat di sini sebagai `[Assumed / Auto-Resolved]`. Seluruh butir di bawah MUST ditulis ke dalam spec oleh `/sdlc-define-specs` bersama R-1..R-3.

- **A-1 (C-3 — status code jalur gagal definitif):** `[Assumed / Auto-Resolved]` - §4.3 dan AC-026 diselaraskan dengan kode berjalan, yaitu **HTTP `500`** dengan `error_code: 'SEND_FAILED'` atau `'INVALID_CHAT_ID'` (persis `ci4Routes.js:94` dan `:226`). Opsi `502` ditolak karena memaksa perubahan kontrak endpoint yang sudah hidup hanya demi kerapian semantik, sementara CON-007 menyatakan perubahan MUST additive. Bila `502` kelak memang diinginkan, itu perubahan kontrak tersendiri dengan CON baru dan catatan rollback.
- **A-2 (C-4 — dasar klasifikasi "gagal definitif"):** `[Assumed / Auto-Resolved]` - state `failed` dipertahankan sebagai **jalur cadangan**, dan §4.2 diberi catatan jujur bahwa pada lalu lintas normal state ini hanya tercapai bila guard JID di `connectionManager.js:891`/`:1037` menyala (sebelum `sendMessage()`). §6 ditambah catatan bahwa AC-026(b) bersifat *stub-only* dan MUST NOT diklaim sebagai bukti perilaku produksi. Klasifikasi definitif tidak diperluas ke error Baileys lain.
- **A-3 (C-6 — REQ/AC untuk E-O4):** `[Assumed / Auto-Resolved]` - ditambahkan tiga requirement baru beserta AC-nya: **REQ-039** (pemilik tunggal kunci adalah frontend; bila request tidak membawa `operation_id`, Gateway MUST NOT membuat kunci di server dan MUST berperilaku seperti REQ-026), **REQ-040** (`callGatewaySend()`/`callGatewaySendMedia()` MUST mengembalikan `error_code`, `state`, dan `replayed` ke pemanggil — perubahan signature yang saat ini tidak ada di `Inbox.php:2062-2074`), dan **REQ-041** (UI MUST menampilkan keadaan "hasil belum pasti" untuk `409 SEND_IN_PROGRESS`/`504 SEND_UNRESOLVED` dan MUST meminta kunci baru saat `409 OPERATION_ID_REUSED`). Pemetaan REQ ke AC di §6 diperbarui.
- **A-4 (H-1 — kepemilikan kunci idempotensi):** `[Assumed / Auto-Resolved]` - frontend adalah pemilik tunggal; pembuatan kunci di sisi server (§4.7 langkah 1) dihapus karena menghasilkan kunci baru pada setiap request sehingga nol idempotensi dan menutupi sinyal `warn` REQ-026.

- **A-5 (H-2 — `pruneTerminal` vs jaminan idempotensi):** `[Assumed / Auto-Resolved]` - jaminan idempotensi dinyatakan berscope **≤ `OUTGOING_OPERATION_TTL_MS`** di §1.2, dan ditambahkan satu AC negatif: setelah baris terminal dipangkas, `operation_id` yang sama MUST dianggap operasi baru (batas yang disadari, bukan bug). Baris `abandoned` yang dipangkas MUST dicatat di log `[CRITICAL]` sebelum dihapus agar jejak dead-letter tidak hilang tanpa terlihat.
- **A-6 (H-3/H-4 — replay & enum `reason`):** `[Assumed / Auto-Resolved]` - `replayDeadLetter()` mempertahankan `attempts` dan spec menyatakan eksplisit bahwa replay memberi **tepat satu** siklus percobaan tambahan; enum alasan dead-letter dibakukan menjadi `max_attempts | max_age | permanent_rejection` dan dipakai konsisten di REQ-035, AC-035, dan §12 Kasus 6.
- **A-7 (H-5 — urutan pembuatan baris operasi di `/send-media`):** `[Assumed / Auto-Resolved]` - seluruh validasi payload (termasuk decode base64, cek tipe/ukuran, dan penghitungan `payload_hash` atas konten hasil decode) MUST selesai **sebelum** `begin()`, sehingga payload yang ditolak tidak meninggalkan baris `in_flight`. AC-019 diperluas: `INVALID_MEDIA_*` juga MUST NOT menghasilkan baris operasi.
- **A-8 (H-6/H-7/H-8 — basis counter, `422`, dan `400` sistemik):** `[Assumed / Auto-Resolved]` - (a) §4.6 menyatakan eksplisit bahwa `attempts` operasi keluar mulai dari 1 (jumlah kiriman) sedangkan `incoming_queue.attempts` mulai dari 0 (jumlah kegagalan); (b) klasifikasi `422` dipertahankan sebagai jaring pengaman tetapi ditandai `[Out of Scope]` karena `InboxGatewayApi.php` tidak pernah membalasnya; (c) ditambahkan satu kebijakan log: bila jumlah baris `dead` bertambah melampaui ambang kewajaran dalam satu siklus, log `[CRITICAL]` memuat instruksi menghentikan replay otomatis (melindungi dari bug payload Gateway yang sistemik, lihat H-8).

Batas yang sudah dinyatakan jujur oleh spec ini dan **tidak** saya anggap blocker: ASSUMPTION-009 (jendela crash setelah WhatsApp menerima pesan tetapi sebelum baris ditandai `sent`), ASSUMPTION-007 (paritas jalur fallback JSON yang belum teruji di Android), dan batas ASSUMPTION-010 untuk skenario muat ulang halaman.

## 4. 📝 Next Steps

**Q-1 (SELESAI — opsi A diputuskan, lihat R-1):** aturan lease vs timeout klien. `OUTGOING_LEASE_MS` naik ke `35000` dan AC-027 ditulis ulang menjadi jalur `409 SEND_IN_PROGRESS`.

**Q-2 (SELESAI — opsi A diputuskan, lihat R-2):** semantik cap percobaan operasi keluar. Cap 5 = maksimal 5 kiriman total, pemeriksaan `attempts >= cap` dilakukan sebelum kirim ulang.

**Q-3 (SELESAI — opsi B diputuskan, lihat R-3):** REQ-020 disempitkan ke 1–64 karakter; kolom AuliaPos tetap `VARCHAR(64)` sehingga pemotongan senyap mustahil.

### Keputusan Akhir: PROCEED (skor 88/100)

Sesi interogasi dihentikan pada ambang **Good Enough**, dan pemilik proyek memilih **PROCEED**. Tiga temuan berikut karena itu dicatat sebagai `[Assumed / Auto-Resolved]` (§3 A-1..A-3) memakai rekomendasi di bawah:

- **C-3 (status code gagal definitif)** → Rekomendasi: **selaraskan §4.3 dan AC-026 dengan kode berjalan (`500`)**, bukan memaksa `502`. Bila pemilik proyek memang menginginkan `502`, tambahkan satu CON baru yang menyatakan perubahan kontrak ini secara jujur beserta alasannya (menggantikan klaim "additive" di CON-007).
- **C-4 (dasar klasifikasi gagal definitif)** → Rekomendasi: **pertahankan state `failed` sebagai jalur cadangan**, dan nyatakan jujur di §4.2 bahwa pada praktiknya ia hanya tercapai bila guard JID di `connectionManager` menyala; tambahkan catatan di §6 bahwa AC-026(b) bersifat *stub-only* dan tidak boleh diklaim sebagai bukti perilaku produksi.
- **C-6 (E-O4 tanpa REQ/AC)** → Rekomendasi: tambahkan **REQ-039..REQ-041** (pemilik `operation_id` di sisi AuliaPos; `callGatewaySend()`/`callGatewaySendMedia()` MUST mengembalikan `error_code`, `state`, dan `replayed`; UI MUST menampilkan "hasil belum pasti" untuk `409 SEND_IN_PROGRESS`/`504 SEND_UNRESOLVED` dan MUST membuat kunci baru untuk `409 OPERATION_ID_REUSED`) beserta AC masing-masing, lalu perbarui pemetaan REQ ke AC di §6.

Tambahan ringan yang saya sarankan dikerjakan sekalian saat spec diperbarui (tidak mengubah scope): seluruh butir **H-1..H-8** di §2.1, terutama **H-1** (siapa pemilik kunci) dan **H-5** (urutan validasi `/send-media`). Semuanya sudah tercatat di §3 (A-4..A-8).

### Final Status & Routing

- **Status laporan:** FINAL - Readiness Score **88/100**, *Good Enough*, veto tidak aktif, tanpa item terbuka.
- **Rincian keputusan yang harus masuk spec (oleh `/sdlc-define-specs`, sesi terpisah):** R-1 (`OUTGOING_LEASE_MS=35000`, AC-027 ditulis ulang), R-2 (cap = maksimum 5 kiriman, pemeriksaan `attempts >= cap` sebelum kirim ulang), R-3 (REQ-020 1–64 karakter, kolom tetap `VARCHAR(64)`), ditambah A-1..A-8.
- **Dokumen yang harus diubah:** §1.2 (batas scope idempotensi ≤ TTL), §4.2 (tabel transisi + catatan `failed` cadangan), §4.3 (status code `500`), §4.6 (lima variabel, catatan basis counter), §4.7 (hapus pembuatan kunci di server, `VARCHAR(64)` disengaja), §5 (AC-019, AC-026, AC-027, AC-029 diperbarui + AC baru untuk REQ-039..REQ-041), §6 (pemetaan REQ ke AC + catatan *stub-only*), §12 (Kasus 6 alasan `permanent_rejection`), dan §2 Definitions (enum `reason`).
- **Dokumentasi pendukung:** tidak ada istilah bisnis baru yang perlu masuk `CONTEXT.md` (pembakuan istilah tetap ditunda seperti gelombang 1). Tidak ada ADR: R-1..R-3 dan A-1..A-8 semuanya dapat dibalik lewat variabel lingkungan, kolom, atau validasi, sehingga tidak memenuhi Triple Gate di `.claude/standards/ADR-FORMAT.md`.
- **Langkah berikutnya:** jalankan **`/sdlc-define-specs`** pada sesi baru dengan melampirkan `spec/spec-process-m1-wave2-outgoing-idempotency.md` + laporan ini, lalu lanjutkan ke `/sdlc-audit-consistency` (opsional) dan `/sdlc-plan-tasks`.


**Urutan perbaikan yang disarankan (untuk agen penulis, `/sdlc-define-specs`):**

1. Selesaikan Q-1 (C-1) dan cap `attempts` (C-2) — keduanya mengubah state machine §4.2, AC-027, AC-029, dan nilai bawaan §4.6.
2. Perbaiki C-3 (status code gagal definitif) dan C-4 (dasar klasifikasi definitif) bersama-sama, karena keduanya menyangkut jalur yang sama.
3. Samakan C-5 (panjang `operation_id`) dan tambahkan REQ + AC untuk E-O4 (C-6), termasuk plumbing `error_code`/`state` di `callGatewaySend()`.
4. Tutup H-1..H-8 (§2.1) atau tandai `[Assumed / Out of Scope]` secara eksplisit.
5. Ulangi `/sdlc-clarify-reqs` (iterasi 2) atau langsung pilih **PROCEED** bila pemblokir sudah dinilai cukup.

**Hal yang tidak perlu diubah:** penomoran REQ/CON/GUD yang melanjutkan gelombang 1, keputusan D-07 (`status='dead'` pada tabel yang sama), D-08 (tabel `outgoing_operations` di database yang sama), dan keputusan "tidak ada ADR" di §10 — ketiganya konsisten dengan CON-002 gelombang 1 dan kode berjalan (`incomingBuffer._migrate()`, `getDueEvents()` dengan filter status).

**Pengelolaan artefak:** tidak ada istilah bisnis baru yang perlu masuk `CONTEXT.md` pada iterasi ini (spec sendiri sudah mencatat bahwa pembakuan istilah ditunda mengikuti gelombang 1). Tidak ada ADR yang memenuhi Triple Gate, termasuk Q-1: ketiga opsinya masih mudah dibalik lewat variabel lingkungan.

---

> **User Decision Prompt:**
> Dokumen telah mencapai Readiness Score **88/100** dan siap untuk fase berikutnya. Apakah Anda ingin **PROCEED** ke fase berikutnya, atau ingin **REFINE** dan memperjelas lebih lanjut?
>
> **Jawaban pemilik proyek:** **PROCEED** (24 September 2026). Tiga temuan sisa (C-3, C-4, C-6) beserta H-1..H-8 dicatat sebagai `[Assumed / Auto-Resolved]` di §3 memakai rekomendasi teknis analis. Laporan difinalkan dan pekerjaan diarahkan ke `/sdlc-define-specs` untuk menulis ulang spec sesuai R-1..R-3 dan A-1..A-8.

