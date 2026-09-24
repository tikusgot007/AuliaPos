---
title: M1 Gelombang 2 — Idempotensi Kirim Keluar (`/send`) & Batas Percobaan/Dead-Letter
version: 1.0
date_created: 2026-09-24
last_updated: 2026-09-24
owner: WA-Gateway reliability (M1) & AuliaPos Inbox
tags: [gateway, whatsapp, baileys, m1, reliability, idempotency, outgoing, dead-letter]
---

# Introduction

Spesifikasi ini mendefinisikan **gelombang 2 dari M1 (Reliability)**: memastikan kirim pesan keluar lewat `POST /send` dan `POST /send-media` tidak menghasilkan pesan ganda ketika AuliaPos kehabisan waktu lalu kasir mengirim ulang, **dan** memastikan antrean retry tidak lagi dicoba tanpa batas dengan menambah penghitung percobaan serta dead-letter.

Gelombang 2 (Ticket 09–11) memang **eksplisit di luar scope** `spec/spec-process-m1-wave1-incoming-reliability.md` v1.1 (§1.1, "Gelombang 2 (idempotency `/send`, Ticket 09–11) dan gelombang 3 (...)"). Spec ini adalah dokumen terpisahnya. Sesuai instruksi pemilik proyek, spec ini **sekalian menarik dua item gelombang 3** yang belum pernah dispesifikasikan (Ticket 06–07: attempt counter dan dead-letter) karena mekanisme batas percobaan itu adalah prasyarat langsung bagi pemulihan kirim yang ambigu (Ticket 11). Ticket 08 (poison-message) dipakai sebagai kriteria verifikasi mekanisme tersebut.

Dasar spec ini adalah bukti terukur Ticket 01 (`docs/decisions/2026-09-21-m1-ticket01-baseline.md`, Baseline 3 dan 4), requirement GW-09 dan GW-19 (`docs/GATEWAY-REQUIREMENTS.md`), kontrak kode yang berjalan sekarang (`C:\projects\WA-Gateway` @ `21a4cb6`), dan jalur kirim AuliaPos (`app/Controllers/Inbox.php`: `kirimKeConversation()` dan `callGatewaySend()`). Tidak ada PRD untuk M1.

## 1. Purpose & Scope

**Tujuan:** (a) kasir yang menekan "kirim ulang" setelah gagal/timeout tidak lagi membuat pelanggan menerima pesan yang sama dua kali; (b) antrean retry pesan masuk berhenti dengan benar (dead-letter) alih-alih mencoba selamanya, dan berhentinya terlihat.

**Audiens:** `/sdlc-clarify-reqs`, `/sdlc-plan-tasks`, dan developer yang mengubah kode WA-Gateway maupun jalur kirim AuliaPos.

**Dalam scope:**

- **E-O1 — Operation ID & idempotensi kirim keluar (Ticket 09, 10; GW-09).** `/send` dan `/send-media` menerima `operation_id`; Gateway mencatat operasi secara durable sebelum memanggil Baileys; permintaan ulang dengan `operation_id` yang sama tidak mengirim WhatsApp kedua kali.
- **E-O2 — Pemulihan kirim yang ambigu (Ticket 11).** Siklus hidup operasi (`in_flight` → `sent` / `failed` / `abandoned`), penandaan sebelum kirim, pemulihan setelah restart, dan batas percobaan.
- **E-O3 — Attempt counter & dead-letter (Ticket 06, 07; GW-19) plus poison-message (Ticket 08).** Batas percobaan pada `incoming_queue`, status terminal `dead`, replay non-destruktif, dan klasifikasi penolakan permanen dari AuliaPos.
- **E-O4 — Sisi pemanggil AuliaPos.** Kolom additive `messages.gateway_operation_id`, pengiriman `operation_id` dari `Inbox::kirimKeConversation()`, dan penanganan respons replay/ambigu di frontend.

## 1.1 Out of Scope

- Ticket 05 (crash/restart test), 12 (worker correctness), 13 (structured logging), 14 (metrics/health), 15 (full reliability test matrix), 16 (merge).
- E-02 (pesan berbungkus seperti ephemeral dan view-once) dan E-07 (upsert tanpa konten) — masih menunggu verifikasi WhatsApp nyata, sama seperti gelombang 1.
- GW-11 dan GW-25 (penyebab dekripsi gagal dan urutan `message_timestamp`).
- GW-20 (health yang bisa dipercaya) dan GW-21 (kabar status pengiriman eksplisit / `messages.update`) — bagian dari M2.
- M2 State Consistency: spec ini tidak menyentuh `assigned_to`, ownership, atau state percakapan.
- Perubahan kontrak masuk `POST /api/inbox/gateway/messages` (payload Gateway → AuliaPos MUST tetap identik, sama seperti CON-001 gelombang 1).
- Media: Gateway tetap tidak menyimpan file media atau state bisnis apa pun.
- Folder `auth/`, versi Baileys, dan skema autentikasi Bearer.
- Perubahan pada perilaku pesan masuk gelombang 1 (`append`, `ownSentRegistry`, buffer, LID) kecuali penambahan batas percobaan pada `markFailedAttempt` yang sudah ada.

## 1.2 Open Questions & Assumptions

Keputusan yang diambil saat menulis spec ini (lanjutan penomoran `D-01..D-04` dari gelombang 1):

- **D-05 (ambiguitas kirim, Ticket 11):** saat sebuah operasi berada di `in_flight` **dan sudah tidak ada permintaan aktif** yang memegangnya (usia lease lewat), permintaan ulang dengan `operation_id` yang sama **mengirim ulang** (attempt bertambah) selama `attempts < OUTGOING_MAX_ATTEMPTS`. Setelah cap tercapai, operasi menjadi `abandoned` (dead-letter kirim keluar) dan tidak pernah dikirim lagi. Dipilih di atas alternatif "jangan pernah kirim ulang, jawab 409 selamanya" (berisiko pesan tidak pernah terkirim) dan "kirim ulang tanpa batas" (tidak ada terminal state).
- **D-06 (penolakan permanen dari AuliaPos, Ticket 08):** hanya HTTP **400 dan 422** dari `POST /api/inbox/gateway/messages` yang dianggap penolakan permanen (pesan beracun) dan langsung masuk dead-letter. `401`, `403`, `404`, `408`, `429`, dan `5xx` tetap dapat dicoba ulang, supaya salah konfigurasi token atau rute tidak membuang pesan pelanggan. Dipilih di atas "semua 4xx adalah permanen".
- **D-07 (bentuk dead-letter antrean masuk, Ticket 07):** dead-letter memakai nilai `status = 'dead'` pada tabel `incoming_queue` yang sama plus kolom `dead_lettered_at`, **bukan** tabel terpisah. Alasan: `getDueEvents()` sudah memfilter status (`status IN ('pending','failed')`), jadi baris `dead` otomatis berhenti; dan CON-002 gelombang 1 mewajibkan perubahan skema hanya berupa penambahan kolom.
- **D-08 (penyimpanan operasi keluar):** operasi keluar disimpan di tabel SQLite baru `outgoing_operations` di **berkas database yang sama** dengan `incoming_queue`, dengan fallback JSON yang mencerminkan strategi `incomingBuffer` (ASSUMPTION-007).
- **D-09 (`operation_id` opsional saat rollout):** `operation_id` bersifat **opsional**. Permintaan tanpa `operation_id` berperilaku persis seperti sekarang (tanpa idempotensi) dan dicatat `warn` agar transisi AuliaPos terlihat. Dipilih di atas "wajib" yang akan memutus AuliaPos lama sebelum deploy bersamaan.

> [!WARNING] ASSUMPTION-001: Cakupan "dead-letter/attempt counter" dari gelombang 3 (Ticket 06–07) diterapkan pada **dua** antrean: `incoming_queue` (GW-19) **dan** `outgoing_operations` (terminal `abandoned`). Alasan: keduanya adalah antrean retry yang sama-sama tidak punya batas saat ini. Alternatif yang ditolak: hanya antrean masuk (akan membuat kirim keluar ambigu tanpa terminal state).
> *Risiko bila salah:* bila pemilik proyek hanya menginginkan antrean masuk, REQ-029 menjadi pekerjaan tambahan yang tidak diminta. Biaya membatalkannya kecil (satu nilai enum + satu kolom).
> *Verifikasi:* konfirmasi di `/sdlc-clarify-reqs`.

> [!WARNING] ASSUMPTION-002: Perubahan kode **AuliaPos termasuk scope** (kolom `messages.gateway_operation_id`, `callGatewaySend()` mengirim `operation_id`, frontend membuat/mereuse UUID). Alasan: GW-09 menyatakan retry dilakukan manusia, sehingga hanya pemanggil yang bisa memegang kunci idempotensi secara stabil. Alternatif yang ditolak: Gateway menurunkan kunci sendiri dari hash `chat_id + text` dalam jendela waktu (akan membuang kiriman sah yang teksnya kebetulan sama, mis. dua kali "OK").
> *Risiko bila salah:* spec menyentuh dua repo. Bila ditolak, E-O4 dan semua AC bertanda AuliaPos dihapus, dan GW-09 hanya tertutup sebagian.
> *Verifikasi:* konfirmasi di `/sdlc-clarify-reqs`.

> [!WARNING] ASSUMPTION-003: Nilai bawaan batas: `OUTGOING_MAX_ATTEMPTS=5`, `OUTGOING_LEASE_MS=15000`, `OUTGOING_OPERATION_TTL_MS=86400000`, `DELIVERY_MAX_ATTEMPTS=100`, `DELIVERY_DEAD_AFTER_MS=86400000`. Angka `DELIVERY_MAX_ATTEMPTS=100` dipilih karena dengan `maxDelayMs=120000` yang sudah berjalan (Ticket 01 Baseline 4) cap itu tercapai sekitar 3,4 jam, memberi ruang ~12× lipat atas pemadaman 6 menit yang terukur, tanpa membuat poison message mencoba selamanya.
> *Risiko bila salah:* pemadaman AuliaPos lebih lama dari ±3,4 jam akan memindahkan pesan pelanggan ke dead-letter. Mitigasi: dead-letter non-destruktif (ASSUMPTION-004) dan bisa di-replay.
> *Verifikasi:* tinjau ulang saat `/sdlc-plan-tasks`; nilai dapat diubah lewat env tanpa mengubah kode.

> [!WARNING] ASSUMPTION-004: Dead-letter bersifat **non-destruktif**: baris tidak pernah dihapus, dan disediakan fungsi replay yang mengembalikan status ke `failed` dengan `next_attempt_at = now`. Belum ada endpoint HTTP untuk replay (itu Ticket 14); pemakaian pertama lewat fungsi + dipanggil manual saat operator memulihkan AuliaPos.
> *Risiko bila salah:* bila pemilik proyek menginginkan replay otomatis, dibutuhkan aturan tambahan yang belum dispesifikasikan di sini.
> *Verifikasi:* tinjau saat `/sdlc-plan-tasks`.

> [!WARNING] ASSUMPTION-005: Fingerprint payload operasi keluar memakai SHA-256 dari JSON kanonik `[kind, chatId, text | null, mediaMeta | null]`, dengan `mediaMeta = {media_type, size, sha256, mimetype, file_name, caption, is_animated}` (SHA-256 konten **hasil decode**, bukan string base64). Body base64 penuh MUST NOT di-hash apa adanya maupun ditulis ke log.
> *Risiko bila salah:* dua kiriman media berbeda yang metadata-nya sama akan dianggap operasi yang sama — namun itu hanya terjadi bila `operation_id`-nya juga sama, yang berarti pemanggil memakai kunci yang sama secara keliru.
> *Verifikasi:* uji tetap memakai dua payload dengan konten berbeda dan kunci sama → harus 409.

> [!WARNING] ASSUMPTION-006: Setiap error yang dilempar Baileys **setelah** operasi masuk `in_flight` dianggap ambigu (`in_flight`, `error_code='SEND_UNRESOLVED'`, HTTP 504), kecuali kode error eksplisit `INVALID_CHAT_ID` yang diklasifikasikan sebagai gagal definitif (`failed`, HTTP 502). Alasan: kode Baileys 6.7.24 tidak memberi jaminan apakah sebuah error berarti "belum terkirim" atau "sudah diterima server WhatsApp".
> *Risiko bila salah:* kegagalan definitif diperlakukan sebagai ambigu, sehingga pemanggil mungkin mengirim ulang sekali sebelum cap tercapai.
> *Verifikasi:* uji simulasi dengan `sock.sendMessage` yang melempar berbagai bentuk error.

> [!WARNING] ASSUMPTION-007: `outgoing_operations` dan batas percobaan baru juga berlaku pada jalur fallback JSON (`IncomingBufferJsonFile`) dengan semantik setara. Jalur fallback ini belum pernah diuji di lingkungan yang benar-benar memakainya (build Android), sama seperti batas bukti gelombang 1.
> *Risiko bila salah:* perilaku di Android berbeda dari desktop.
> *Verifikasi:* verifikasi di lingkungan fallback JSON, tercatat sebagai item Ticket 04 gelombang 1 yang masih menggantung.

> [!WARNING] ASSUMPTION-008: Nama yang dipakai konsisten dengan kode yang ada (English snake_case untuk kolom & tabel, camelCase untuk fungsi JS): tabel `outgoing_operations`, kolom `operation_id`, `payload_hash`, `kind`, `chat_id`, `state`, `wa_message_id`, `media_ref_json`, `attempts`, `last_error`, `created_at`, `updated_at`, `resolved_at`, `dead_lettered_at`; kolom AuliaPos `messages.gateway_operation_id`.
> *Risiko bila salah:* renaming setelah migrasi lebih mahal daripada sekarang.
> *Verifikasi:* tinjau saat `/sdlc-plan-tasks`.

> [!WARNING] ASSUMPTION-009: Batas jujur yang diterima: bila proses Gateway mati **tepat setelah** WhatsApp menerima pesan tetapi sebelum baris operasi ditandai `sent`, kirim ulang dengan `operation_id` yang sama tetap bisa menduplikasi pesan. Satu-satunya penutup penuh adalah kabar status pengiriman eksplisit (GW-21, M2) yang berada di luar scope. Yang dilakukan spec ini: mempersempit jendela (tandai `in_flight` sebelum kirim, tandai `sent` segera sesudah) dan membuat kejadiannya terlihat lewat log `critical` saat operasi `in_flight` ditemukan saat start.
> *Risiko bila salah:* duplikat langka masih mungkin. Bukan regresi — perilaku sekarang menduplikasi pada **setiap** retry setelah timeout.
> *Verifikasi:* batas ini MUST ditulis jujur di decision log eksekusi dan tidak boleh diklaim tertutup.

> [!WARNING] ASSUMPTION-010: Frontend AuliaPos membuat `operation_id` dengan `crypto.randomUUID()` dan fallback hex berbasis `Math.random` untuk peramban lama, disimpan pada state composer, dipakai ulang saat kirim ulang, dan **dibuat baru** bila teks diedit atau kirim berhasil. Bila `operation_id` hilang (muat ulang halaman), kasir kembali ke perilaku lama (tanpa jaminan) — batas yang disadari, bukan bug.
> *Risiko bila salah:* sebagian duplikat pada skenario muat-ulang halaman tidak tertutup.
> *Verifikasi:* uji manual UI + uji unit endpoint.

> [!WARNING] ASSUMPTION-011: Spec ditulis dalam bahasa Indonesia, mengikuti spec M1 gelombang 1 dan M3. `AGENTS.md` menetapkan bahasa Inggris untuk dokumen SDLC; spec gelombang 1 memakai bahasa Indonesia dengan asumsi yang sama. Ubah bila diminta.

**CLARIFICATION NEEDED (prioritas tertinggi):**

- ASSUMPTION-001 (cakupan ganda dead-letter) dan ASSUMPTION-002 (perubahan AuliaPos masuk scope) mengubah luas pekerjaan secara material. Keduanya harus dijawab sebelum `/sdlc-plan-tasks`.
- D-05 (kirim ulang saat `in_flight`) adalah trade-off duplikat vs pesan tidak terkirim; perlu penegasan pemilik proyek.

## 2. Definitions

- **Kirim keluar:** permintaan `POST /send` atau `POST /send-media` dari AuliaPos ke Gateway untuk mengirim pesan/media atas nama kasir.
- **Operation ID (`operation_id`):** string kunci idempotensi yang dibuat pemanggil (AuliaPos), unik untuk satu niat kirim. Ia bertahan melintasi percobaan ulang manusia.
- **Operasi (kirim keluar):** satu baris `outgoing_operations` yang mewakili satu `operation_id`. Ia yang menentukan apakah sebuah permintaan ulang boleh mengirim WhatsApp lagi.
- **Fingerprint payload:** SHA-256 dari payload kanonik (lihat ASSUMPTION-005). Dipakai untuk mendeteksi pemakaian ulang `operation_id` dengan isi berbeda.
- **`in_flight`:** keadaan operasi antara "penanda ditulis" dan "hasil pasti diketahui". Ini keadaan ambigu, bukan keadaan gagal.
- **Lease (`OUTGOING_LEASE_MS`):** rentang waktu singkat setelah `updated_at` terakhir di mana operasi `in_flight` dianggap **sedang** dikerjakan oleh permintaan aktif. Di luar lease, `in_flight` dianggap sisa crash dan boleh dicoba ulang.
- **`abandoned`:** keadaan terminal operasi keluar setelah batas percobaan tercapai (dead-letter kirim keluar). Ia tidak pernah dikirim lagi.
- **Attempt counter:** kolom `attempts` yang sudah ada di `incoming_queue`; spec ini menambahkan **batas** pemakaiannya.
- **Dead-letter:** keadaan terminal sebuah event setelah percobaan berhenti. Untuk antrean masuk: `status='dead'` pada `incoming_queue`. Untuk kirim keluar: `state='abandoned'` pada `outgoing_operations`.
- **Pesan beracun (poison message):** event yang secara permanen ditolak AuliaPos (HTTP 400/422), sehingga mencoba ulang tidak akan pernah berhasil.
- **Replay (dead-letter):** mengembalikan baris `dead` ke antrean aktif (`status='failed'`, `next_attempt_at=now`) tanpa menghapus riwayat.
- **Replay (operasi keluar):** permintaan ulang dengan `operation_id` yang sudah `sent`/`failed`; Gateway menjawab ulang hasil tersimpan tanpa memanggil Baileys lagi.
- **`replayed`:** penanda pada respons Gateway bahwa hasil yang dikembalikan berasal dari catatan tersimpan, bukan dari pengiriman baru. Berkas domain glossary (`CONTEXT.md`) belum memuat istilah-istilah ini karena scope-nya baru sebatas Handoff/Collision; pembakuan ditunda seperti gelombang 1.

## 3. Requirements, Constraints & Guidelines

Penomoran melanjutkan gelombang 1 (REQ-001..019, CON-001..004, GUD-001..002) supaya traceability lintas spec M1 tetap utuh.

### E-O1 — Operation ID & idempotensi kirim keluar (Ticket 09, 10; GW-09)

- **REQ-020**: `POST /send` dan `POST /send-media` MUST menerima field opsional `operation_id` berupa string 1–128 karakter dengan pola `^[A-Za-z0-9._:-]+$`. Field yang ada tetapi tidak memenuhi pola atau melebihi panjang MUST ditolak `400` dengan `error_code: 'INVALID_OPERATION_ID'`, tanpa memanggil Baileys.
- **REQ-021**: Bila `operation_id` ada, Gateway MUST menulis baris `outgoing_operations` (state `in_flight`, `attempts = 1`, fingerprint payload) **sebelum** memanggil `sock.sendMessage()`. Pencatatan tidak boleh menunggu hasil kirim.
- **REQ-022**: Permintaan ulang dengan `operation_id` yang sama dan fingerprint yang sama MUST NOT memanggil `sock.sendMessage()` lagi bila state operasi sudah terminal (`sent`, `failed`, `abandoned`); Gateway MUST mengembalikan hasil tersimpan dengan `replayed: true`.
- **REQ-023**: Permintaan ulang dengan `operation_id` yang sama tetapi fingerprint berbeda MUST ditolak `409` `error_code: 'OPERATION_ID_REUSED'` tanpa memanggil Baileys.
- **REQ-024**: Baris `outgoing_operations` MUST memuat `state`, `attempts`, `wa_message_id`, `media_ref_json`, `last_error`, `created_at`, `updated_at`, `resolved_at`, dan `dead_lettered_at`; MUST bertahan melintasi restart proses (SQLite; fallback JSON mengikuti ASSUMPTION-007).
- **REQ-025**: Setiap respons `/send` dan `/send-media` MUST memuat `state` dan `replayed`, serta `operation_id` bila field itu dikirim pemanggil. Field lama (`success`, `wa_message_id`, `timestamp`, `media_ref`, `error_code`, `message`) MUST tetap ada dengan arti yang sama.
- **REQ-026**: Permintaan **tanpa** `operation_id` MUST berperilaku persis seperti kode sekarang (tanpa idempotensi, tanpa baris operasi) dan MUST dicatat pada level `warn` sekali per proses (bukan per permintaan) supaya transisi AuliaPos terlihat tanpa membanjiri log.

### E-O2 — Pemulihan kirim yang ambigu & batas percobaan (Ticket 11)

- **REQ-027**: Gateway MUST memanggil `sock.sendMessage()` hanya setelah operasi berstate `in_flight` tersimpan, dan MUST menyelesaikannya menjadi:
  - `sent` + `wa_message_id` bila pemanggilan mengembalikan hasil sukses;
  - `failed` + `last_error` bila error terklasifikasi definitif (`INVALID_CHAT_ID`, lihat ASSUMPTION-006);
  - tetap `in_flight` + `last_error` bila error ambigu, dan respons MUST `504` `error_code: 'SEND_UNRESOLVED'`.
- **REQ-028**: Operasi `in_flight` yang `updated_at`-nya lebih tua dari `OUTGOING_LEASE_MS` MUST dianggap milik percobaan yang sudah mati dan MUST boleh dicoba ulang; operasi `in_flight` yang masih di dalam lease MUST dijawab `409` `error_code: 'SEND_IN_PROGRESS'` tanpa memanggil Baileys.
- **REQ-029**: Percobaan ulang operasi `in_flight` MUST menambah `attempts`. Begitu `attempts >= OUTGOING_MAX_ATTEMPTS`, Gateway MUST mengubah state menjadi `abandoned`, menulis `dead_lettered_at`, mencatat log `error` berprefix `[CRITICAL]`, dan MUST NOT memanggil Baileys lagi untuk operasi itu.
- **REQ-030**: Pemeriksaan `isConnected()` MUST terjadi **sebelum** baris operasi dibuat, sehingga penolakan `409 NOT_CONNECTED` tidak meninggalkan baris `in_flight` yang membuat percobaan berikutnya terlihat ambigu.
- **REQ-031**: Saat start, Gateway MUST menghitung dan mencatat operasi berstate `in_flight` yang lebih tua dari `OUTGOING_LEASE_MS` pada level `error` (jumlah + daftar `operation_id` maksimum 20), sebagai sinyal paling awal bahwa ada kirim yang hasilnya tidak pasti. Perilaku ini MUST tidak memblokir start.
- **REQ-032**: Baris `outgoing_operations` berstate terminal yang `created_at`-nya lebih tua dari `OUTGOING_OPERATION_TTL_MS` MUST dibersihkan oleh pembersihan malas saat start. Baris `in_flight` MUST NOT dibersihkan.

### E-O3 — Attempt counter, dead-letter & poison-message (Ticket 06, 07, 08; GW-19)

- **REQ-033**: `incomingBuffer.markFailedAttempt()` MUST memeriksa batas: bila `attempts + 1 >= DELIVERY_MAX_ATTEMPTS`, atau `now - created_at > DELIVERY_DEAD_AFTER_MS`, baris MUST diubah ke `status = 'dead'` dan `dead_lettered_at` diisi, bukan dijadwalkan ulang. Nilai baliknya MUST menambah penanda `deadLettered: true` (dan tetap mengembalikan `delayMs`/`nextAttemptAt` untuk kompatibilitas pemanggil).
- **REQ-034**: `getDueEvents()` MUST NOT mengembalikan baris `status = 'dead'`. Filter `status IN ('pending','failed')` yang ada sudah memenuhi ini dan MUST dipertahankan.
- **REQ-035**: Dead-letter MUST non-destruktif: baris tidak dihapus, `last_error` dan `attempts` dipertahankan, dan `dead_lettered_at` diisi sekali. Setiap transisi ke `dead` MUST dicatat pada level `error` berprefix `[CRITICAL]` berisi `wa_message_id`, `attempts`, `last_error`, dan alasan (`max_attempts` atau `max_age`).
- **REQ-036**: `incomingDelivery.deliverOne()` MUST mengklasifikasikan hasil `postToCI4()`: HTTP `400`/`422` adalah penolakan permanen → baris langsung ke `status = 'dead'` (tanpa menambah `attempts`), sementara `401`, `403`, `404`, `408`, `429`, `5xx`, timeout, dan jaringan tetap lewat `markFailedAttempt()` (D-06).
- **REQ-037**: Gateway MUST menyediakan `replayDeadLetter(id)` (atau setara) yang mengubah satu baris `dead` menjadi `status = 'failed'`, `next_attempt_at = now`, dan mempertahankan `attempts` supaya kebijakan batas berikutnya tetap konsisten; serta `countDeadLettered()` untuk jumlah baris `dead`.
- **REQ-038**: `incomingBuffer` MUST mencatat jumlah baris `dead` saat start pada level `error` bila lebih dari nol.

### Security & operasional

- **SEC-001**: Log dan pesan error MUST NOT memuat isi pesan pelanggan, teks balasan kasir, atau string media base64. Yang boleh dicatat: `operation_id`, `chat_id`, `messageId`, ukuran byte, dan hash.
- **SEC-002**: `operation_id` dari pemanggil MUST diperlakukan sebagai data tidak terpercaya: hanya dipakai sebagai kunci penyimpanan setelah lolos validasi REQ-020; ia MUST NOT diinterpolasi ke shell, nama berkas, atau kueri tanpa parameter.

### Batasan

- **CON-005**: Kontrak `POST /api/inbox/gateway/messages` (Gateway → AuliaPos) MUST tidak berubah, termasuk seluruh field payload dan artinya. Ini melanjutkan CON-001 gelombang 1.
- **CON-006**: Perubahan skema `incoming_queue` MUST hanya menambah kolom dan MUST kompatibel dengan database SQLite yang sudah ada (pola `ALTER TABLE ... ADD COLUMN` yang sudah dipakai di `incomingBuffer._migrate()`).
- **CON-007**: Penambahan `operation_id` pada `/send` dan `/send-media` MUST bersifat additive (field opsional) sehingga AuliaPos lama tetap bekerja selama rollout (D-09).
- **CON-008**: Tidak ada dependensi npm baru; folder `auth/` dan versi Baileys MUST tidak disentuh.
- **CON-009**: Perubahan AuliaPos MUST hanya menambah kolom nullable `messages.gateway_operation_id` (grup DB `inbox`) dan MUST NOT mengubah arti kolom mana pun yang sudah ada, termasuk `send_status` dan `wa_message_id`.
- **CON-010**: Gateway tetap tidak menyimpan state bisnis atau berkas media. `outgoing_operations` adalah buffer kendali operasi, bukan riwayat percakapan.

### Panduan

- **GUD-003**: Semua batas baru (`OUTGOING_MAX_ATTEMPTS`, `OUTGOING_LEASE_MS`, `OUTGOING_OPERATION_TTL_MS`, `DELIVERY_MAX_ATTEMPTS`, `DELIVERY_DEAD_AFTER_MS`) SHOULD dapat diatur lewat variabel lingkungan dengan nilai bawaan di spec ini; nilai tidak valid MUST memakai bawaan utuh (pola `toInt`/`toIntList` yang ada).
- **GUD-004**: Setiap keputusan yang mencegah duplikat atau memindahkan sesuatu ke dead-letter SHOULD dicatat beserta `operation_id`/`wa_message_id`, tanpa isi pesan (SEC-001).

## 4. Interfaces & Data Contracts

Semua antarmuka Gateway bersifat internal kecuali `/send` dan `/send-media` (yang berubah secara additive). `POST /api/inbox/gateway/messages` tidak berubah.

### 4.1 Modul `outgoingOperations` (`src/store/outgoingOperations.js`, baru)

| Operasi | Kontrak |
|---|---|
| `begin({ operationId, payloadHash, kind, chatId })` | Membuat baris `in_flight` (`attempts = 1`). Mengembalikan `{ created: true }` bila baris baru, atau deskriptor baris yang sudah ada `{ created: false, row }` |
| `get(operationId)` | Mengembalikan baris atau `null` |
| `markSent(operationId, { waMessageId, mediaRef })` | `state = 'sent'`, mengisi `wa_message_id`/`media_ref_json`/`resolved_at` |
| `markFailed(operationId, errorMessage)` | `state = 'failed'`, mengisi `last_error`/`resolved_at` |
| `markUnresolved(operationId, errorMessage)` | Tetap `state = 'in_flight'`, mengisi `last_error`, memperbarui `updated_at` |
| `registerRetry(operationId)` | Menambah `attempts` dan memperbarui `updated_at` (dipakai sebelum percobaan ulang) |
| `abandon(operationId, reason)` | `state = 'abandoned'`, mengisi `dead_lettered_at` |
| `listStaleInFlight(olderThanMs)` | Daftar operasi `in_flight` yang lebih tua dari ambang (dipakai REQ-031) |
| `countInFlight()` | Jumlah operasi `in_flight` |
| `pruneTerminal(olderThanMs)` | Menghapus baris terminal yang lebih tua dari TTL (REQ-032) |

Modul ini mengikuti pola `incomingBuffer`: satu berkas, dua implementasi (SQLite & fallback JSON), dan merupakan singleton dengan constructor yang menerima `Database`/path supaya bisa diuji terisolasi.

### 4.2 State machine operasi keluar

```text
        begin()                sendMessage() sukses
   ──► in_flight ──────────────────────────────► sent        (terminal)
          │
          │  error definitif (INVALID_CHAT_ID)
          ├────────────────────────────────────► failed      (terminal)
          │
          │  error ambigu / proses mati
          ├──────────────► (tetap) in_flight
          │                     │
          │                     │  percobaan ulang, attempts >= OUTGOING_MAX_ATTEMPTS
          │                     └────────────► abandoned   (terminal, dead-letter)
```

Aturan transisi:

| Dari | Peristiwa | Ke |
|---|---|---|
| (tidak ada) | `begin()` | `in_flight` |
| `in_flight` | `sendMessage()` sukses | `sent` |
| `in_flight` | error `INVALID_CHAT_ID` | `failed` |
| `in_flight` | error lain / proses mati | `in_flight` (tetap, `attempts` tidak naik sampai dicoba ulang) |
| `in_flight` | percobaan ulang & `attempts + 1 >= cap` | `abandoned` |
| `sent`/`failed`/`abandoned` | permintaan ulang | tetap (replay) |

### 4.3 Matriks respons `/send` dan `/send-media`

| Kondisi | HTTP | `error_code` | Field inti |
|---|---|---|---|
| Percobaan baru sukses | `200` | — | `success:true, state:'sent', replayed:false, wa_message_id, timestamp` |
| Replay `sent` | `200` | — | idem + `replayed:true` |
| Percobaan baru gagal definitif | `502` | `SEND_FAILED`/`INVALID_CHAT_ID` | `success:false, state:'failed', replayed:false` |
| Replay `failed` | `502` | sama | idem + `replayed:true` |
| Percobaan baru ambigu | `504` | `SEND_UNRESOLVED` | `success:false, state:'in_flight', replayed:false` |
| Replay `in_flight`, masih di dalam lease | `409` | `SEND_IN_PROGRESS` | `success:false, state:'in_flight'` |
| Replay `in_flight`, lease lewat, `attempts < cap` | hasil percobaan ulang | — | seperti baris 1/3/5 dengan `replayed:false` |
| `abandoned` | `502` | `DEAD_LETTERED` | `success:false, state:'abandoned', replayed:true` |
| `operation_id` sama, fingerprint beda | `409` | `OPERATION_ID_REUSED` | `success:false, replayed:false`, tidak ada panggilan Baileys |
| `operation_id` tidak valid | `400` | `INVALID_OPERATION_ID` | `success:false`, tidak ada panggilan Baileys |
| WhatsApp belum `connected` | `409` | `NOT_CONNECTED` | tidak ada baris operasi dibuat (REQ-030) |
| Validasi field lain gagal | `400` | tetap seperti sekarang | `INVALID_CHAT_ID`, `INVALID_TEXT`, dst. |

### 4.4 Skema `outgoing_operations` (SQLite, database yang sama dengan `incoming_queue`)

```sql
CREATE TABLE IF NOT EXISTS outgoing_operations (
  operation_id        TEXT PRIMARY KEY,
  payload_hash        TEXT NOT NULL,
  kind                TEXT NOT NULL,              -- 'text' | 'media'
  chat_id             TEXT NOT NULL,
  state               TEXT NOT NULL DEFAULT 'in_flight',  -- in_flight | sent | failed | abandoned
  wa_message_id       TEXT,
  media_ref_json      TEXT,
  attempts            INTEGER NOT NULL DEFAULT 1,
  last_error          TEXT,
  created_at          TEXT NOT NULL,
  updated_at          TEXT NOT NULL,
  resolved_at         TEXT,
  dead_lettered_at    TEXT
);
CREATE INDEX IF NOT EXISTS idx_outgoing_operations_state
  ON outgoing_operations (state, updated_at);
```

### 4.5 Penambahan kolom `incoming_queue`

```sql
ALTER TABLE incoming_queue ADD COLUMN dead_lettered_at TEXT;
```

Ditambahkan lewat pola migrasi ringan yang sudah ada (`PRAGMA table_info` lalu `ALTER TABLE` bila kolom belum ada), aman dijalankan berkali-kali. Tidak ada kolom yang diubah atau dihapus (CON-006). Nilai `status` yang sah menjadi `pending | failed | completed | dead`.

### 4.6 Konfigurasi baru (variabel lingkungan)

| Nama | Bawaan | Arti |
|---|---|---|
| `OUTGOING_MAX_ATTEMPTS` | `5` | Batas percobaan satu operasi keluar sebelum `abandoned` |
| `OUTGOING_LEASE_MS` | `15000` | Usia `in_flight` yang masih dianggap "sedang dikerjakan" |
| `OUTGOING_OPERATION_TTL_MS` | `86400000` | Usia maksimum baris operasi terminal sebelum dibersihkan |
| `DELIVERY_MAX_ATTEMPTS` | `100` | Batas percobaan satu event `incoming_queue` sebelum `dead` |
| `DELIVERY_DEAD_AFTER_MS` | `86400000` | Usia maksimum event sebelum dipaksa `dead` |

Semua nilai MUST di-clamp minimum 1 (kecuali `DELIVERY_DEAD_AFTER_MS` minimum 0 = tanpa batas usia), mengikuti pola `Math.max(...)` yang sudah dipakai `ownSentTtlMs`/`ownSentMax`.

### 4.7 Kontrak AuliaPos

**Payload tambahan** (additive, opsional di Gateway):

```json
POST /send
{ "chat_id": "...", "text": "...", "operation_id": "b1f0c7a2-...." }

POST /send-media
{ "chat_id": "...", "media_type": "image", "media_base64": "...",
  "caption": "", "operation_id": "b1f0c7a2-...." }
```

**Migrasi additive (grup DB `inbox`)**: `messages.gateway_operation_id VARCHAR(64) NULL` dengan indeks UNIQUE (MySQL mengizinkan banyak `NULL`, sehingga baris masuk tidak terpengaruh). Pola migration mengikuti `2026-09-22-000001_AddIsInternalToMessages.php`.

**Alur `Inbox::kirimKeConversation()` setelah perubahan:**

1. Terima `operation_id` dari request AJAX kasir. Bila kosong, buat di server (`bin2hex(random_bytes(16))`) supaya jalur lama tetap punya kunci idempotensi.
2. Kirim ke Gateway lewat `callGatewaySend()`/`callGatewaySendMedia()` dengan `operation_id` disertakan.
3. Bila Gateway membalas sukses:
   - cek `messages` berdasarkan `gateway_operation_id`. Bila sudah ada, jangan `insert` kedua kali — kembalikan baris itu dan jawab sukses (`replayed` boleh diteruskan ke UI sebagai informasi).
   - bila belum ada, `insert` seperti sekarang dengan `gateway_operation_id` diisi dan `send_status = 'sent'`.
4. Bila Gateway membalas `409 SEND_IN_PROGRESS` atau `504 SEND_UNRESOLVED`: jawab UI dengan status khusus ("hasil belum pasti"), **jangan** memaksa insert baris sukses, dan **jangan** menyarankan kirim ulang tanpa memeriksa. Pesan kesalahan MUST menyebut bahwa pesan mungkin sudah terkirim.
5. Bila Gateway membalas `409 OPERATION_ID_REUSED`: catat `log_message('error', ...)` dan minta UI membuat `operation_id` baru (indikasi bug UI atau tab ganda).
6. Bila Gateway membalas `409 NOT_CONNECTED` atau `502 DEAD_LETTERED`: tetap seperti kegagalan biasa (tidak ada baris `messages`).

**Perilaku frontend**: `operation_id` dibuat sekali per "niat kirim" dan disimpan pada state composer (atribut `data-operation-id` pada form balas). Nilai itu dipakai ulang saat tombol kirim ditekan lagi setelah gagal/timeout, dan **dibuang** setelah kirim berhasil atau setelah isi kotak pesan berubah.

**Penanganan balasan Gateway yang tidak berubah**: bentuk `{success, wa_message_id, timestamp, media_ref, error_code, message}` tetap dibaca `callGatewaySend()` seperti sekarang; hanya cabang status baru yang ditambahkan.

## 5. Acceptance Criteria

- **AC-019 (REQ-020)**: Given `operation_id` berisi karakter di luar pola atau lebih dari 128 karakter, When `/send` dipanggil, Then dibalas `400 INVALID_OPERATION_ID`, tidak ada baris `outgoing_operations`, dan `sock.sendMessage` tidak dipanggil.
- **AC-020 (REQ-021)**: Given `operation_id` valid, When `/send` dipanggil dan `sock.sendMessage` disimulasikan menggantung, Then pada saat pemanggilan itu baris `outgoing_operations` sudah berstate `in_flight` sebelum `sendMessage` terpanggil.
- **AC-021 (REQ-022)**: Given `/send` dengan `operation_id=X` sudah `sent`, When `/send` dipanggil ulang dengan `operation_id=X` dan payload identik, Then dibalas `200` dengan `replayed:true` dan `wa_message_id` sama, dan `sock.sendMessage` **tidak** dipanggil lagi.
- **AC-022 (REQ-023)**: Given `operation_id=X` sudah tercatat, When `/send` dipanggil dengan `operation_id=X` tetapi `text` berbeda, Then dibalas `409 OPERATION_ID_REUSED` dan `sock.sendMessage` tidak dipanggil.
- **AC-023 (REQ-024)**: Given operasi `sent` tersimpan, When proses Gateway dimatikan dan dijalankan lagi, Then `/send` ulang dengan `operation_id` yang sama masih dibalas `replayed:true`.
- **AC-024 (REQ-025)**: Given `/send` sukses tanpa `operation_id`, When respons diperiksa, Then field `success`, `wa_message_id`, `timestamp` masih ada dengan arti yang sama dan `state:'sent'`, `replayed:false` ikut dikirim.
- **AC-025 (REQ-026)**: Given `/send` untuk AuliaPos lama tanpa `operation_id`, When dipanggil berkali-kali, Then perilakunya identik dengan kode sekarang (setiap panggilan mengirim) dan peringatan "permintaan tanpa operation_id" hanya muncul sekali per proses.
- **AC-026 (REQ-027)**: Given `sock.sendMessage` (a) mengembalikan sukses, (b) melempar error `INVALID_CHAT_ID`, (c) melempar error jaringan, When `/send` dipanggil, Then state berturut-turut `sent` (`200`), `failed` (`502`), dan `in_flight` dengan respons `504 SEND_UNRESOLVED`.
- **AC-027 (REQ-022, skenario terukur Ticket 01 Baseline 3)**: Given kasir mengirim lewat AuliaPos dan cURL AuliaPos timeout 10 detik padahal Gateway akhirnya sukses, When kasir menekan kirim ulang dengan `operation_id` yang sama, Then Gateway membalas `replayed:true` tanpa mengirim pesan kedua, dan pelanggan hanya menerima satu pesan.
- **AC-028 (REQ-028)**: Given operasi `in_flight` dengan `updated_at` 5 detik lalu (`OUTGOING_LEASE_MS=15000`), When `/send` ulang dipanggil, Then dibalas `409 SEND_IN_PROGRESS` tanpa memanggil `sendMessage`. Given `updated_at` 20 detik lalu, Then percobaan ulang dilakukan dan `attempts` bertambah.
- **AC-029 (REQ-029)**: Given operasi `in_flight` yang selalu gagal ambigu, When percobaan ulang dilakukan sampai `attempts` mencapai `OUTGOING_MAX_ATTEMPTS`, Then state menjadi `abandoned`, `dead_lettered_at` terisi, log `[CRITICAL]` tercatat, dan permintaan berikutnya dibalas `502 DEAD_LETTERED` tanpa memanggil `sendMessage`.
- **AC-030 (REQ-030)**: Given WhatsApp belum `connected`, When `/send` dipanggil dengan `operation_id` baru, Then dibalas `409 NOT_CONNECTED` dan **tidak ada** baris `outgoing_operations`; pemanggilan berikutnya dengan `operation_id` yang sama menjadi percobaan pertama yang bersih.
- **AC-031 (REQ-031)**: Given database berisi operasi `in_flight` berusia 1 jam, When Gateway start, Then jumlah dan daftar `operation_id` (maksimum 20) dicatat pada level `error` dan start tidak terblokir.
- **AC-032 (REQ-032)**: Given berisi satu baris `sent` berusia 25 jam dan satu baris `in_flight` berusia 25 jam (`OUTGOING_OPERATION_TTL_MS=86400000`), When start dijalankan, Then baris `sent` dibersihkan dan baris `in_flight` tetap ada.
- **AC-033 (REQ-033)**: Given event `incoming_queue` dengan `attempts = DELIVERY_MAX_ATTEMPTS - 1`, When `markFailedAttempt()` dipanggil, Then status menjadi `dead`, `dead_lettered_at` terisi, dan nilai balik memuat `deadLettered:true`.
- **AC-034 (REQ-034)**: Given baris berstatus `dead`, When `getDueEvents()` dipanggil, Then baris itu tidak ikut dikembalikan.
- **AC-035 (REQ-035)**: Given sebuah event dipindahkan ke `dead`, When baris diperiksa, Then baris masih ada, `attempts` dan `last_error` tidak berubah, `dead_lettered_at` terisi, dan log `[CRITICAL]` memuat `wa_message_id` serta alasan (`max_attempts`/`max_age`).
- **AC-036 (REQ-036)**: Given `postToCI4` mengembalikan HTTP `400`, When `deliverOne()` diproses, Then baris langsung `status='dead'` tanpa menambah `attempts` dan tanpa menunggu `DELIVERY_MAX_ATTEMPTS`. Given HTTP `401` atau `500`, Then baris tetap `failed` dan dijadwalkan ulang.
- **AC-037 (REQ-037)**: Given baris `dead`, When `replayDeadLetter(id)` dipanggil, Then `status='failed'`, `next_attempt_at` ≈ sekarang, `attempts` dipertahankan, dan baris ikut `getDueEvents()` siklus berikutnya.
- **AC-038 (REQ-038)**: Given database berisi 3 baris `dead` saat start, Then jumlah itu dicatat pada level `error`; dengan 0 baris `dead`, tidak ada log error tersebut.
- **AC-039 (SEC-001)**: Given `/send` gagal dan `/send-media` gagal, When berkas log diperiksa, Then tidak ada teks pesan maupun string `media_base64` di dalamnya; hanya `operation_id`, `chat_id`, ukuran byte, dan hash.
- **AC-040 (CON-005)**: Given payload yang sama dikirim ke `POST /api/inbox/gateway/messages` sebelum dan sesudah perubahan, When dibandingkan, Then field dan nilainya identik.
- **AC-041 (CON-009)**: Given Gateway membalas `replayed:true` untuk operasi yang sudah pernah disimpan AuliaPos, When `Inbox::kirimKeConversation()` selesai, Then hanya ada **satu** baris `messages` dengan `gateway_operation_id` itu, dan baris masuk lain tidak terpengaruh (indeks UNIQUE masih menerima banyak `NULL`).

## 6. Test Automation Strategy & Testing Seams

- **Testing Seams**:
  1. `outgoingOperations` langsung dengan berkas SQLite sementara (path disuntik ke constructor), tanpa Baileys.
  2. Router `/send` dan `/send-media` dengan `connectionManager.sendReply`/`sendMediaReply` di-stub, sehingga kegagalan bisa dibuat deterministik (sukses / `INVALID_CHAT_ID` / error jaringan).
  3. `incomingBuffer.markFailedAttempt()` / `replayDeadLetter()` / `getDueEvents()` dengan berkas SQLite sementara.
  4. `incomingDelivery.deliverOne()` dengan `postToCI4` di-stub per kode HTTP.
  5. AuliaPos: `tests/database/` untuk kolom + idempotensi baris `messages`, dan uji controller untuk cabang respons Gateway (`replayed`, `SEND_UNRESOLVED`, `OPERATION_ID_REUSED`).
- **Test Levels**: skrip integrasi ringan berbasis `assert` (pola `test/simulate-*.js` yang sudah ada) untuk Gateway; `vendor/bin/phpunit` untuk AuliaPos.
- **Test Data Management**: database SQLite sementara di folder temp sistem, dihapus setelah tiap skenario (pola `simulate-durable-buffer.js`). Kelas pengujian Gateway MUST NOT menyentuh `data/gateway.sqlite` produksi (pelajaran CR dari gelombang 1: tiga skrip lama menulis ke DB non-sementara).
- **CI/CD Integration**: tidak ada pipeline. Skrip Gateway dijalankan manual dengan `node`; AuliaPos lewat `composer test`.
- **Coverage Requirements**: setiap REQ punya minimal satu AC, dan setiap AC punya minimal satu skrip uji otomatis kecuali AC-027 (pengukuran nyata dengan `pm2 stop`/AuliaPos dijeda, dicatat sebagai prosedur tertulis). Tidak ada ambang persentase.
- **Pemetaan REQ ke AC**:
  - E-O1: REQ-020 ke AC-019, REQ-021 ke AC-020, REQ-022 ke AC-021 dan AC-027, REQ-023 ke AC-022, REQ-024 ke AC-023, REQ-025 ke AC-024, REQ-026 ke AC-025.
  - E-O2: REQ-027 ke AC-026, REQ-028 ke AC-028, REQ-029 ke AC-029, REQ-030 ke AC-030, REQ-031 ke AC-031, REQ-032 ke AC-032.
  - E-O3: REQ-033 ke AC-033, REQ-034 ke AC-034, REQ-035 ke AC-035, REQ-036 ke AC-036, REQ-037 ke AC-037, REQ-038 ke AC-038.
  - SEC: SEC-001 ke AC-039, SEC-002 diverifikasi saat review kode (kueri terparameter).
  - Batasan: CON-005 ke AC-040, CON-007 ke AC-025, CON-009 ke AC-041, CON-006/008 diverifikasi saat review kode, CON-010 diverifikasi lewat pemeriksaan diff. GUD-003 diverifikasi lewat pemeriksaan konfigurasi, GUD-004 lewat pemeriksaan log.

## 7. Project Structure & Commands

### Project Structure

**WA-Gateway** (`C:\projects\WA-Gateway`, branch kerja baru `feature/m1-wave2-outgoing-idempotency`, worktree terpisah — pola `C:\projects\WA-Gateway-m1w2` mengikuti CON-005 gelombang 1):

- `src/store/outgoingOperations.js` (baru): store operasi + state machine.
- `src/delivery/outgoingOperationService.js` (baru, opsional): orkestrasi `begin → send → resolve` yang dipakai kedua endpoint agar tidak duplikatif.
- `src/api/ci4Routes.js`: validasi `operation_id`, cabang replay/ambigu untuk `/send` dan `/send-media`.
- `src/store/incomingBuffer.js`: kolom `dead_lettered_at`, batas pada `markFailedAttempt()`, `replayDeadLetter()`, `countDeadLettered()`.
- `src/delivery/incomingDelivery.js`: klasifikasi `postToCI4()` (poison vs retryable) dan log dead-letter.
- `src/config/index.js`: lima variabel lingkungan baru.
- `src/app/index.js`: pemanggilan pemeriksaan start (operasi `in_flight` basi, prune terminal, jumlah `dead`).
- `test/`: skrip baru dengan pola `simulate-*.js`.

**AuliaPos** (`C:\xampp\htdocs\aulia`):

- `app/Database/Migrations/<tanggal>_AddGatewayOperationIdToMessages.php` (baru).
- `app/Controllers/Inbox.php`: `kirimKeConversation()`, `callGatewaySend()`, `callGatewaySendMedia()`.
- `app/Views/.../index.php` (+ JS): pembuatan & pemakaian ulang `operation_id`.
- `tests/`: uji database + controller.

### Commands

- **Build:** tidak ada (kedua repo).
- **Test (Gateway):** `node test/<nama-skrip>.js` (belum ada test runner).
- **Test (AuliaPos):** `vendor/bin/phpunit --no-coverage` atau `composer test`.
- **Migrate (AuliaPos):** `php spark migrate` (grup `inbox`).
- **Lint/Format:** tidak ada konfigurasi lint di kedua repo.
- **Dev (Gateway):** `npm run dev` (`node --watch src/app/index.js`) — MUST NOT dijalankan pada Gateway yang sedang aktif.
- **Deploy:** mengikuti TASK pola gelombang 1 (`merge --ff-only` + `pm2 restart`), dengan titik rollback dicatat sebelum deploy.

## 8. Code Style & Conventions

Gateway memakai CommonJS, `'use strict'`, dua spasi, tanda kutip tunggal, komentar bahasa Indonesia, dan pencatatan lewat `logger`. AuliaPos mengikuti PSR-12 dan gaya controller yang ada (komentar bahasa Indonesia, `log_message()` untuk error). Contoh gaya Gateway untuk orkestrasi kirim:

```javascript
'use strict';

const logger = require('../logging');
const outgoingOperations = require('../store/outgoingOperations');

/**
 * Bungkus satu percobaan kirim dengan idempotensi operasi (REQ-021, REQ-027).
 * `send` adalah fungsi yang memanggil Baileys; ia hanya dipanggil setelah
 * baris `in_flight` tersimpan -- mencatat setelah kirim kalah balapan
 * dengan crash dan membuat hasilnya tidak pasti (ASSUMPTION-009).
 */
async function runOperation({ operationId, payloadHash, kind, chatId, send }) {
  const existing = outgoingOperations.get(operationId);

  if (existing && existing.payload_hash !== payloadHash) {
    const err = new Error('operation_id sudah dipakai untuk payload yang berbeda');
    err.code = 'OPERATION_ID_REUSED';
    throw err;
  }

  if (!existing) {
    outgoingOperations.begin({ operationId, payloadHash, kind, chatId });
  } else if (existing.state === 'in_flight') {
    outgoingOperations.registerRetry(operationId); // REQ-029: naikkan attempts lebih dulu
  }

  try {
    const result = await send();
    outgoingOperations.markSent(operationId, result);
    return { replayed: false, result };
  } catch (err) {
    if (err.code === 'INVALID_CHAT_ID') {
      outgoingOperations.markFailed(operationId, err.message);
    } else {
      outgoingOperations.markUnresolved(operationId, err.message);
    }
    throw err;
  }
}

module.exports = { runOperation };
```

Catatan: snippet di atas menunjukkan **gaya** dan urutan `begin()` sebelum `send()`, bukan implementasi lengkap. Pemeriksaan lease `in_flight` (REQ-028), cap `abandoned` (REQ-029), pembersihan `payload_hash`, dan pemetaan HTTP status MUST ditambahkan di `outgoingOperationService.js`. Contoh pengecekan batas pada antrean masuk (REQ-033) mengikuti pola `markFailedStmt` yang sudah ada agar diff tetap kecil.

## 9. Implementation Boundaries

- **Always do:** menambah skrip uji untuk setiap perubahan; menjaga payload `POST /api/inbox/gateway/messages` tetap identik; membuat semua batas baru dapat diatur lewat variabel lingkungan; bekerja hanya di worktree Gateway yang baru dan di `C:\xampp\htdocs\aulia` untuk AuliaPos; mencatat titik rollback sebelum deploy.
- **Ask first:** menambah tabel atau kolom baru (`outgoing_operations`, `incoming_queue.dead_lettered_at`, `messages.gateway_operation_id`); menambah dependensi npm/composer; mengubah bentuk respons `/send` dan `/send-media` di luar yang tertulis di §4.3; menjalankan uji yang mematikan atau menjeda Gateway/AuliaPos yang aktif; menjalankan `php spark migrate` pada database kerja yang berisi data nyata.
- **Never do:** mengubah folder `auth/`; mengubah kontrak masuk `POST /api/inbox/gateway/messages`; menulis isi pesan atau `media_base64` ke log; menghapus atau melewati uji yang gagal; mengubah kode M2/M3 yang tidak terkait; menjalankan uji destruktif pada `data/gateway.sqlite` atau database `aulia_inboxdb` produksi.

## 10. Rationale, Context & Architecture Decisions (ADRs)

- **Kenapa idempotensi kirim keluar (GW-09, Ticket 09–10):** diukur pada Ticket 01 Baseline 3 — retry `/send` setelah client putus menduplikasi pesan pada 2 dari 3 percobaan; dan terbukti lewat Inbox AuliaPos (Gateway dijeda 12 detik, pelanggan menerima `U03` dua kali sementara Inbox mencatat satu). Penyebabnya struktural: AuliaPos sengaja tanpa outgoing queue (`CHAT.md`), jadi retry manusia adalah satu-satunya pemulihan, dan tanpa kunci idempotensi setiap retry adalah kiriman baru.
- **Kenapa `operation_id` datang dari pemanggil (D-09/ASSUMPTION-002):** hanya pemanggil yang tahu bahwa dua permintaan HTTP adalah "niat kirim yang sama". Gateway tidak bisa menebaknya tanpa risiko membuang kiriman sah (dua "OK" berturut-turut). Memakai ulang ID pesan Baileys (`generateMessageIDV2`) tidak mungkin karena AuliaPos belum punya ID itu sebelum Gateway mengirim.
- **Kenapa `in_flight` ditulis sebelum kirim (REQ-021, ASSUMPTION-009):** pola yang sama dengan D-03 gelombang 1 — mencatat setelah aksi kalah balapan dengan crash. Menulis sebelum kirim mempersempit jendela "terkirim tapi tidak tercatat" dari durasi kirim (bisa >10 detik untuk media) menjadi satu operasi basis data.
- **Kenapa lease, bukan "selalu percobaan ulang" (REQ-028):** tanpa lease, dua permintaan paralel dengan `operation_id` yang sama akan mengirim dua kali; lease memisahkan "sedang dikerjakan" (`409`) dari "ditinggalkan crash" (boleh dicoba ulang).
- **Kenapa batas percobaan (Ticket 06–07, GW-19):** Ticket 01 Baseline 4 mengukur `attempts` naik sampai 8 dalam pemadaman 6 menit tanpa batas maupun dead-letter. Retry tak terbatas menyembunyikan kegagalan permanen (pesan beracun) dan membuat antrean tumbuh tanpa henti.
- **Kenapa hanya 400/422 yang permanen (D-06):** `401`/`403` menandakan masalah kredensial gateway yang berlaku untuk **semua** pesan; memperlakukannya permanen akan membuang seluruh antrean sekaligus karena satu kesalahan konfigurasi. `408`/`429`/`5xx` bersifat sementara menurut definisinya.
- **Kenapa dead-letter memakai `status='dead'` (D-07):** `getDueEvents()` sudah memfilter status, sehingga perubahan cukup satu nilai status + satu kolom; ini memenuhi CON-002 gelombang 1 (hanya menambah kolom) dan menghindari migrasi tabel.
- **ADR:** tidak dibuat. D-05 sampai D-09 mudah dibalik (menghapus field opsional, menghapus `ALTER TABLE ADD COLUMN`, mengubah nilai status), sehingga tidak memenuhi ketiga kriteria di `.claude/standards/ADR-FORMAT.md`. Ini mengikuti keputusan yang sama pada gelombang 1. Bila ke depan muncul keputusan membuat **antrean kirim keluar yang durable** (bukan sekadar dedupe operasi), keputusan itu sulit dibalik dan wajib dibuatkan ADR di `docs/adr/`.

## 11. Dependencies & External Integrations

### External Systems

- **EXT-001**: Baileys 6.7.24 (`sock.sendMessage()` dengan opsi `messageId`, `generateMessageIDV2`). Perilaku yang sudah diverifikasi di gelombang 1 tetap berlaku; spec ini tidak mengubah pemakaiannya selain urutan pencatatan operasi.
- **EXT-002**: AuliaPos (`InboxGatewayApi::messages()` menerima payload masuk; `Inbox::callGatewaySend()`/`callGatewaySendMedia()` memanggil Gateway). AuliaPos juga pemilik `operation_id`.

### Infrastructure Dependencies

- **INF-001**: Node.js 20 dan `better-sqlite3` di Gateway desktop; fallback JSON untuk build Android (ASSUMPTION-007). Node 24 tidak didukung.
- **INF-002**: PM2 (`wa-gateway`) sebagai pengelola proses di Aan-PC; deploy memakai pola `merge --ff-only` + `pm2 restart`.
- **INF-003**: MySQL/MariaDB AuliaPos dengan grup koneksi `inbox` (`aulia_inboxdb`) untuk migrasi additive `messages.gateway_operation_id`.

### Data Dependencies

- **DAT-001**: Skema `incoming_queue` gelombang 1 (`status`, `attempts`, `next_attempt_at`, `last_error`, `created_at` sudah ada; spec ini hanya menambah `dead_lettered_at`).
- **DAT-002**: Skema `messages` AuliaPos (`wa_message_id` UNIQUE, `send_status ENUM('received','sent','failed')`, indeks `(conversation_id, message_timestamp)`); hanya ditambah kolom nullable.

## 12. Examples & Edge Cases

```text
Kasus 1 (Ticket 11, skenario terukur — timeout AuliaPos):
  Kasir kirim "R1" -> AuliaPos generate op=K1 -> POST /send {chat_id, text, operation_id:K1}
  Gateway: tulis outgoing_operations(K1, in_flight) -> sendMessage -> sukses -> state=sent
  AuliaPos cURL timeout 10 detik (respons tidak sampai) -> UI menampilkan gagal
  Kasir tekan kirim ulang -> POST /send {…, operation_id:K1} (teks tidak berubah)
  Gateway: state=sent -> replay 200 {success:true, replayed:true} -> TIDAK ada kiriman kedua
  Pelanggan menerima "R1" tepat sekali.

Kasus 2 (konflik kunci):
  Kasir kirim "R1" op=K1 (sukses). Kasir mengubah teks menjadi "R2" tanpa membuang op.
  -> POST /send {text:"R2", operation_id:K1} -> 409 OPERATION_ID_REUSED
  UI membuat op baru (K2) dan mengirim ulang.

Kasus 3 (edge, error ambigu):
  Gateway tulis in_flight(K3) -> sendMessage melempar socket error (hasil tidak pasti)
  -> 504 SEND_UNRESOLVED {state:'in_flight'}
  Kasir menunggu > lease (15 detik) lalu kirim ulang op=K3 -> attempts=2 -> sukses -> sent
  Pelanggan paling banyak menerima 2 pesan bila percobaan pertama sebenarnya terkirim
  (batas ASSUMPTION-009, dicatat jujur).

Kasus 4 (edge, crash):
  Gateway crash setelah in_flight(K4) ditulis. Saat start: log error "1 operasi in_flight basi".
  Kasir kirim ulang op=K4 -> lease sudah lewat -> percobaan ulang berjalan.

Kasus 5 (dead-letter keluar):
  op=K5 selalu gagal ambigu. Setelah attempts=5 (OUTGOING_MAX_ATTEMPTS):
  state=abandoned + log [CRITICAL]. Permintaan berikutnya -> 502 DEAD_LETTERED.

Kasus 6 (Ticket 08, pesan beracun antrean masuk):
  AuliaPos membalas 400 untuk event X (payload tidak sah). deliverOne -> status='dead'
  seketika, attempts tidak naik, log [CRITICAL] dengan wa_message_id + alasan 'permanent_rejection'.

Kasus 7 (batas percobaan antrean masuk):
  AuliaPos mati 6 menit -> attempts naik s.d. 8 (terukur). Ini masih jauh di bawah
  DELIVERY_MAX_ATTEMPTS=100, jadi tetap 'failed' dan dicoba ulang, bukan dead.
  Operasi admin memulihkan AuliaPos -> semua 'completed'.

Kasus 8 (edge, rollout):
  AuliaPos lama mengirim /send tanpa operation_id -> Gateway mengirim seperti biasa,
  log warn sekali per proses ("permintaan tanpa operation_id; idempotensi tidak aktif").

Kasus 9 (edge, 409 SEND_IN_PROGRESS):
  Dua tab kasir mengirim op=K6 hampir bersamaan. Permintaan kedua (masih di dalam lease)
  -> 409 SEND_IN_PROGRESS; tidak ada kiriman kedua.
```

## 13. Validation Criteria

1. Semua AC-019 sampai AC-041 lulus, dengan skrip uji otomatis untuk semuanya kecuali AC-027 yang memakai prosedur pengukuran nyata tertulis.
2. Pengukuran nyata AC-027 memakai prosedur: perlambat Gateway/AuliaPos sehingga cURL 10 detik AuliaPos timeout (mis. jeda terkontrol pada respons `/send` atau pemblokiran port sementara), kasir mengirim, konfirmasi UI menampilkan gagal, kasir menekan kirim ulang, lalu periksa bahwa pelanggan menerima **satu** pesan dan `outgoing_operations` menunjukkan `state='sent'` dengan `attempts=1`. Ulangi minimal 3 kali, mengikuti pola pengukuran Ticket 01.
3. Selama pengukuran nyata, `POST /api/inbox/gateway/messages` menerima payload yang identik dengan sebelum perubahan (AC-040), dan jumlah baris masuk baru di AuliaPos bertambah sesuai jumlah pesan yang benar-benar terkirim (bukan jumlah percobaan).
4. `vendor/bin/phpunit --no-coverage` lulus 100% setelah perubahan AuliaPos (baseline gelombang M3 terakhir: 298 test / 948 assertion).
5. Skrip `test/simulate-*.js` Gateway baru lulus semua (0 gagal) dan tidak ada skrip yang menulis ke `data/gateway.sqlite` produksi.
6. Tidak ada log yang memuat isi pesan atau media base64 (AC-039), dan tidak ada baris `dead`/`abandoned` yang hilang dari basis data (non-destruktif, AC-035).
7. Markdownlint dijalankan pada berkas ini. Profil temuannya **sama jenisnya** dengan spec gelombang 1 yang sudah di-approve dan berjalan di produksi: `MD013` (panjang baris, bawaan 80), `MD028` (baris kosong di antara dua blok `> [!WARNING]` — pola pemisah yang diwarisi dari gelombang 1), `MD060` (gaya pipa tabel), dan satu `MD025` (satu judul H1, pola `# Introduction` + `## 1.` warisan spec gelombang 1 dan spec M3 Fase 1/Fase 2a). Perbandingan terukur: berkas ini 219 `MD013` / 10 `MD028` / 24 `MD060` / 1 `MD025`, sedangkan `spec-process-m1-wave1-incoming-reliability.md` 117 / 11 / 18 / 1 untuk jenis yang sama. Repositori tidak memiliki konfigurasi `.markdownlint*`, dan `.claude/instructions/markdown.instructions.md` menetapkan batas **400** karakter (bukan 80), sehingga `MD013` bawaan tidak mencerminkan konvensi proyek. Sesuai batas kewenangan skill ini (hanya boleh menulis berkas di `/spec/`), normalisasi lint lintas-repo MUST NOT dilakukan di sini — itu tugas tata kelola terpisah. Yang MUST dipastikan dan sudah dipenuhi: **tidak ada jenis temuan baru** yang diperkenalkan berkas ini dibanding spec gelombang 1.
8. Uji Readiness mandiri terhadap rubrik klarifikasi: **Completeness** (semua perilaku E-O1 sampai E-O4, payload, kode HTTP, transisi state, dan kasus tepi tertulis), **Clarity** (implementable tanpa menebak — DDL, matriks respons, dan nilai bawaan eksplisit; sisa ketidakpastian ada di D-05 dan ASSUMPTION-001/002 yang ditandai untuk klarifikasi), **Alignment** (terlacak ke GW-09, GW-19, Ticket 06–11, dan temuan terukur Ticket 01; tidak ada item yatim ke luar scope nomor 1.1).

## 14. Related Specifications / Further Reading

- `spec/spec-process-m1-wave1-incoming-reliability.md` v1.1 — gelombang 1 (pesan masuk, buffer, `ownSentRegistry`, AC-001..AC-018, CON-001..004, REQ-001..019 yang penomorannya dilanjutkan di sini).
- `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` — contoh bentuk plan dan pola APPROVAL/VERIFY/DEPLOY yang akan dipakai gelombang ini.
- `docs/GATEWAY-REQUIREMENTS.md` — GW-09 (idempotensi kirim keluar) dan GW-19 (batas percobaan/dead-letter).
- `docs/decisions/2026-09-21-m1-ticket01-baseline.md` — Baseline 3 (duplikat pada retry `/send`) dan Baseline 4 (`attempts` naik sampai 8 tanpa batas; pemulihan 115 detik).
- `docs/decisions/2026-09-21-m1-ticket02-audit-enqueue.md` — E-01..E-09 (konteks antrean masuk yang diberi batas percobaan).
- `docs/decisions/2026-09-21-m1-wave1-eksekusi-fase1-3.md` dan `docs/decisions/2026-09-23-m1-wave1-deploy-dan-ac001.md` — penyimpangan yang dideklarasikan, batas bukti, dan pola deploy yang terbukti.
- `docs/audit/code-review-m1-wave1-2026-09-23.md` — temuan CR yang masih menjadi backlog (CR-05..CR-11, CR-15) dan pelajaran pengujian (DB sementara, guard statis).
- `docs/TODO-CHAT.md` — daftar Ticket 06–11 yang spec ini mulai tutup.
- `app/Controllers/InboxGatewayApi.php`, `app/Controllers/Inbox.php` — kontrak nyata sisi AuliaPos.
