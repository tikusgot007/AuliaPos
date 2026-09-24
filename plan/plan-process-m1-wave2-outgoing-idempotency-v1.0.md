---
goal: M1 Gelombang 2 — Idempotensi Kirim Keluar (`/send`, `/send-media`) & Batas Percobaan/Dead-Letter
version: 1.0
date_created: 2026-09-24
last_updated: 2026-09-24
owner: WA-Gateway reliability (M1) & AuliaPos Inbox
status: 'Planned'
tags: [process, gateway, whatsapp, baileys, m1, reliability, idempotency, outgoing, dead-letter]
---

# Introduction

![Status: Planned](https://img.shields.io/badge/status-Planned-yellow)

Plan ini mengeksekusi `spec/spec-process-m1-wave2-outgoing-idempotency.md` (v1.1, commit `7897d38`) untuk menutup **GW-09** (idempotensi kirim keluar: kasir menekan "kirim ulang" setelah timeout tidak boleh menggandakan pesan pelanggan) dan **GW-19** (antrean retry berhenti dengan benar lewat attempt counter + dead-letter), sesuai Ticket 06–11 di `docs/TODO-CHAT.md`. Dua item gelombang 3 (Ticket 06–07) ditarik ke scope oleh spec §Introduction karena merupakan prasyarat terminal state bagi Ticket 11; Ticket 08 dipakai sebagai kriteria verifikasi mekanisme itu.

Bentuk plan, pola **VERIFY/APPROVAL/DEPLOY**, penomoran `REQ`/`CON`/`GUD`, dan gaya tabel mengikuti `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` v1.2 (dirujuk spec §14) agar traceability lintas-gelombang M1 tetap utuh.

**Kode di dua repo — setiap task menyebut repo yang disentuh di kolom `Repo`:**

- **WA-Gateway** — kerja **hanya** di worktree baru `C:\projects\WA-Gateway-m1w2`, branch `feature/m1-wave2-outgoing-idempotency`. Folder live `C:\projects\WA-Gateway` (@ `21a4cb6`) bersifat **read-only sampai TASK-022 (DEPLOY)**. Folder `auth/` dan versi Baileys tidak disentuh (CON-008).
- **AuliaPos** — kerja di `C:\xampp\htdocs\aulia`, branch baru `feature/m1-wave2-outgoing-idempotency`. Working copy ini **dibagi** dengan pekerjaan M3 (lihat RISK-002).

**Sesi ini tidak mengubah kode apa pun.** Eksekusi kode dilakukan oleh `/sdlc-write-code` di sesi terpisah per fase.

> [!NOTE] Bahasa & konvensi dokumen. Plan ini ditulis dalam **bahasa Indonesia**, mengikuti dua dokumen yang dirujuk user: `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` (pola bentuk, nyata berbahasa Indonesia) dan spec gelombang 2 v1.1 (ASSUMPTION-011). `AGENTS.md` menetapkan bahasa Inggris untuk dokumen SDLC; bila pemilik proyek menginginkan plan ini berbahasa Inggris, terjemahkan utuh pada revisi berikutnya — tidak ada keputusan teknis di dalamnya yang bergantung pada bahasa.

> [!NOTE] Anti-Data-Loss Guard. Per 2026-09-24 tidak ada `plan/plan-process-m1-wave2-*.md` di `/plan/` (diperiksa lewat daftar direktori), sehingga berkas ini dibuat baru dan tidak menimpa plan yang belum selesai. Plan gelombang 1 berstatus `Completed`; plan M3 Fase 1 berstatus `Completed` dengan Phase 4 (Fase 1d) sudah di-Approval — tidak ada task terbuka yang akan tertimpa oleh plan ini.

## 1. Requirements & Constraints

Penomoran requirement mengikuti spec v1.1 apa adanya (REQ-020..REQ-041 melanjutkan REQ-001..REQ-019 gelombang 1). Plan ini **tidak menambah** requirement baru (Red Flag #7: tidak ada fitur halusinasi).

**E-O1 — Operation ID & idempotensi kirim keluar (Ticket 09, 10):**

- **REQ-020**: `operation_id` opsional di `/send` dan `/send-media`, pola `^[A-Za-z0-9._:-]+$`, 1–64 karakter; tidak valid → `400 INVALID_OPERATION_ID` tanpa memanggil Baileys.
- **REQ-021**: baris `outgoing_operations` (`in_flight`, `attempts=1`, `payload_hash`) ditulis **sebelum** `sock.sendMessage()`; seluruh validasi payload (decode base64, tipe/ukuran media, `payload_hash`) selesai **sebelum** `begin()` (A-7).
- **REQ-022**: permintaan ulang dengan `operation_id` + fingerprint sama pada state terminal → hasil tersimpan + `replayed:true`, tanpa kirim ulang.
- **REQ-023**: `operation_id` sama + fingerprint beda → `409 OPERATION_ID_REUSED` tanpa memanggil Baileys.
- **REQ-024**: kolom lengkap `outgoing_operations` dan daya tahannya melintasi restart (SQLite; fallback JSON mengikuti ASSUMPTION-007).
- **REQ-025**: respons memuat `state` dan `replayed` (+ `operation_id` bila dikirim); field lama tetap ada dengan arti sama.
- **REQ-026**: tanpa `operation_id` → perilaku persis seperti sekarang, dengan satu `warn` per proses.

**E-O2 — Pemulihan kirim ambigu & batas percobaan (Ticket 11):**

- **REQ-027**: hasil `sendMessage()` dipetakan ke `sent` / `failed` (`INVALID_CHAT_ID`, jalur cadangan) / tetap `in_flight` + `504 SEND_UNRESOLVED`.
- **REQ-028**: `in_flight` yang lebih tua dari `OUTGOING_LEASE_MS` (bawaan `35000`) boleh dicoba ulang; yang masih di dalam lease dijawab `409 SEND_IN_PROGRESS` tanpa kirim.
- **REQ-029**: `attempts` = jumlah kiriman yang dijalankan; pemeriksaan `attempts >= OUTGOING_MAX_ATTEMPTS` (bawaan `5`) dilakukan **sebelum** `registerRetry()`/kirim; bila tercapai → `abandoned` + `dead_lettered_at` + log `[CRITICAL]` (tanpa kirim).
- **REQ-030**: `isConnected()` diperiksa **sebelum** baris operasi dibuat (agar `409 NOT_CONNECTED` tidak meninggalkan `in_flight` palsu).
- **REQ-031**: saat start, operasi `in_flight` basi dihitung dan dicatat level `error` (jumlah + maksimum 20 `operation_id`), tanpa memblokir start.
- **REQ-032**: `pruneTerminal()` menghapus baris terminal yang lebih tua dari `OUTGOING_OPERATION_TTL_MS` (bawaan `86400000`), **tidak** menghapus `in_flight`, dan mencatat `[CRITICAL]` untuk tiap baris `abandoned` sebelum dihapus → jaminan idempotensi berscope **≤ TTL** (D-13/A-5).

**E-O3 — Attempt counter, dead-letter & poison-message (Ticket 06, 07, 08):**

- **REQ-033**: `markFailedAttempt()` memaksa `status='dead'` bila `attempts + 1 >= DELIVERY_MAX_ATTEMPTS` (bawaan `100`) atau usia melampaui `DELIVERY_DEAD_AFTER_MS` (bawaan `86400000`), dan mengembalikan `deadLettered:true`.
- **REQ-034**: `getDueEvents()` tetap memfilter `status IN ('pending','failed')` sehingga `dead` tidak pernah dikembalikan.
- **REQ-035**: dead-letter non-destruktif (`attempts`/`last_error` utuh, `dead_lettered_at` diisi sekali) + log `[CRITICAL]` memuat `wa_message_id`, `attempts`, `last_error`, dan `reason` dari enum `max_attempts | max_age | permanent_rejection`.
- **REQ-036**: `deliverOne()` mengklasifikasikan `postToCI4()`: `400`/`422` = penolakan permanen → langsung `dead` (tanpa menambah `attempts`); `401/403/404/408/429/5xx`/timeout/jaringan → `markFailedAttempt()`.
- **REQ-037**: `replayDeadLetter(id)` mengembalikan `dead` → `failed` + `next_attempt_at=now` dengan `attempts` dipertahankan (**tepat satu** siklus tambahan); `countDeadLettered()` tersedia.
- **REQ-038**: jumlah baris `dead` saat start dicatat level `error` bila > 0; pertambahan `dead` melewati `DELIVERY_DEAD_BURST_THRESHOLD` (bawaan `10`) dalam satu siklus dicatat `[CRITICAL]` + instruksi menghentikan replay otomatis.

**E-O4 — Sisi pemanggil AuliaPos:**

- **REQ-039**: frontend AuliaPos adalah **pemilik tunggal** `operation_id`; Gateway MUST NOT membuat kunci di server (`crypto.randomUUID()` + fallback, disimpan di state composer, dipakai ulang saat kirim ulang).
- **REQ-040**: `Inbox::callGatewaySend()`/`callGatewaySendMedia()` juga mengembalikan `error_code`, `state`, `replayed` di samping field lama.
- **REQ-041**: UI menampilkan "hasil belum pasti, jangan kirim ulang dulu" untuk `409 SEND_IN_PROGRESS`/`504 SEND_UNRESOLVED`, memakai `operation_id` **baru** untuk `409 OPERATION_ID_REUSED`, dan membuang kunci setelah sukses atau isi kotak berubah.

**Security & operasional:**

- **SEC-001**: log/pesan error tidak memuat isi pesan atau string `media_base64`; hanya `operation_id`, `chat_id`, `messageId`, ukuran byte, dan hash.
- **SEC-002**: `operation_id` diperlakukan sebagai data tidak terpercaya — dipakai hanya sebagai kunci setelah lolos REQ-020, tidak diinterpolasi ke shell/nama berkas/kueri tanpa parameter.
- **GUD-003**: semua batas baru diatur lewat env dengan nilai bawaan spec dan clamp minimum (pola `Math.max` milik `ownSentTtlMs`/`ownSentMax`).
- **GUD-004**: setiap keputusan anti-duplikat/dead-letter dicatat bersama `operation_id`/`wa_message_id`, tanpa isi pesan.

**Constraints:**

- **CON-005**: kontrak `POST /api/inbox/gateway/messages` (Gateway → AuliaPos) MUST tidak berubah (lanjutan CON-001 gelombang 1).
- **CON-006**: perubahan skema `incoming_queue` hanya additive, memakai pola `PRAGMA table_info` + `ALTER TABLE ADD COLUMN` yang sudah ada di `incomingBuffer._migrate()`.
- **CON-007**: `operation_id` bersifat additive/opsional sehingga AuliaPos lama tetap bekerja selama rollout (D-09).
- **CON-008**: tidak ada dependensi npm baru; `auth/` dan versi Baileys tidak disentuh.
- **CON-009**: perubahan AuliaPos hanya menambah kolom nullable `messages.gateway_operation_id` (grup DB `inbox`) + indeks UNIQUE; arti kolom lama (`send_status`, `wa_message_id`) tidak berubah.
- **CON-010**: Gateway tidak menyimpan state bisnis atau berkas media.
- **CON-011 (plan-level)**: tidak ada `git checkout`/`git reset` pada folder live `C:\projects\WA-Gateway` kecuali `merge --ff-only` sekali di TASK-022 (pola CON-005 + TASK-019 gelombang 1).
- **CON-012 (plan-level)**: perubahan AuliaPos bersifat **surgical** pada `app/Controllers/Inbox.php` (`kirimKeConversation()`, `callGatewaySend()`, `callGatewaySendMedia()`), `app/Views/inbox/index.php` (form balas + JS kirim), dan satu migration baru; method lain tidak disentuh (termasuk `apiConversations()` milik M3 Fase 1d/1e).
- **CON-013 (plan-level)**: setiap task VERIFY wajib menjalankan ulang seluruh skrip uji fase sebelumnya (regresi kumulatif) dan tidak boleh menambah suppression/skip (Floor-Guard).
- **CON-014 (plan-level)**: tidak ada `php spark migrate` pada database kerja berisi data nyata tanpa persetujuan eksplisit user (spec §9 "Ask first"); migration diuji di database uji.

## 2. Implementation Steps

> **EXECUTION DIRECTIVE FOR AI AGENTS:**
> Eksekusi plan ini fase demi fase. Kode **hanya** ditulis di worktree `C:\projects\WA-Gateway-m1w2` (branch `feature/m1-wave2-outgoing-idempotency`) dan di `C:\xampp\htdocs\aulia` (branch `feature/m1-wave2-outgoing-idempotency`). Satu-satunya pengecualian adalah **TASK-022 (DEPLOY)**: `merge --ff-only` satu kali ke folder live `C:\projects\WA-Gateway` + `pm2 restart wa-gateway`.
> Jangan pernah `git checkout`/`git reset` di folder live, jangan menyentuh `auth/`, jangan menjalankan `npm run dev` pada Gateway yang sedang aktif, dan jangan menulis ke `data/gateway.sqlite` produksi (CON-011, CON-008, spec §9).
> Jalankan task **VERIFY** di akhir tiap fase, lalu **BERHENTI DAN TUNGGU** persetujuan eksplisit user sebelum masuk fase berikutnya. Satu task = satu commit kecil (pola gelombang 1). Setiap task kode MUST menyertakan ujinya pada penambahan yang sama (Micro-level Testing Mandate).
> **Paralelisasi yang diizinkan:** Fase 4 (AuliaPos, TASK-015..021) berada di repo berbeda dan boleh dikerjakan paralel dengan Fase 2/3 (Gateway) oleh sesi lain; urutan fase hanya mengikat di dalam satu repo. Fase 5 (TASK-022..024) baru boleh dimulai setelah Fase 3 dan Fase 4 selesai.

**Grafik dependensi (bottom-up; `Dep` hanya menunjuk task yang sudah dijadwalkan sebelumnya):**

- `[GW]` TASK-001 (worktree + branch) → TASK-002 (config + store `outgoingOperations`)
  - TASK-002 → TASK-003 (`outgoingOperationService` + `/send` teks) → TASK-004 (`/send-media`) → TASK-005 VERIFY → TASK-006 APPROVAL
  - TASK-003 → TASK-007 (lease, cap, matriks respons)
  - TASK-002 → TASK-008 (tugas start-up: `in_flight` basi, `pruneTerminal`)
    - TASK-007 + TASK-008 → TASK-009 VERIFY → TASK-010 APPROVAL
  - TASK-002 → TASK-011 (`incomingBuffer`: cap + `dead_lettered_at` + replay) → TASK-012 (`incomingDelivery`: klasifikasi `postToCI4`) → TASK-013 VERIFY → TASK-014 APPROVAL
- `[AP]` TASK-015 (preflight branch AuliaPos) → TASK-016 (migration kolom + indeks) → TASK-017 (`kirimKeConversation()`) → TASK-018 (`callGatewaySend*` + cabang respons) → TASK-019 (`index.php` + JS `operation_id`) → TASK-020 VERIFY → TASK-021 APPROVAL
- Fase 5: TASK-013 + TASK-021 → TASK-022 (DEPLOY `[GW]`) → TASK-023 (VERIFY pengukuran nyata AC-027/AC-042) → TASK-024 APPROVAL/handoff

### Implementation Phase 1 — Tracer Bullet: "Kirim ulang teks tidak menggandakan pesan" (E-O1)

- GOAL-001: Satu niat kirim teks punya `operation_id`, tercatat durable sebelum kirim, dan permintaan ulang menghasilkan replay tanpa panggilan Baileys kedua — terbukti lewat skrip uji dengan `sock.sendMessage` di-stub.

| Task | Repo | Description | Ref ID | AC Ref | Dep | Files | Completed | Date |
|---|---|---|---|---|---|---|---|---|
| TASK-001 | GW | Siapkan lingkungan kerja: `git -C C:\projects\WA-Gateway worktree add C:\projects\WA-Gateway-m1w2 -b feature/m1-wave2-outgoing-idempotency 21a4cb6`. Verifikasi: `git -C C:\projects\WA-Gateway status --short` kosong dan tetap di `21a4cb6` (folder live tidak tersentuh); `git worktree list` menampilkan worktree baru; `npm ci` di worktree sukses dan `require('better-sqlite3')` jalan di Node 20; catat `21a4cb6` sebagai titik rollback di decision log baru (`docs/decisions/`, repo AuliaPos). Jangan menyentuh `auth/` maupun `data/`. | Spec §7, CON-008 | - | - | 0 (XS) | ✅ WA-Gateway worktree dari `21a4cb6` (titik rollback) | 2026-09-24 |
| TASK-002 | GW | Store operasi + konfigurasi: di `src/config/index.js` tambah **enam** variabel (`OUTGOING_MAX_ATTEMPTS=5`, `OUTGOING_LEASE_MS=35000`, `OUTGOING_OPERATION_TTL_MS=86400000`, `DELIVERY_MAX_ATTEMPTS=100`, `DELIVERY_DEAD_AFTER_MS=86400000`, `DELIVERY_DEAD_BURST_THRESHOLD=10`) dengan clamp minimum mengikuti pola `Math.max` yang ada. Buat `src/store/outgoingOperations.js` (satu berkas, dua implementasi SQLite + fallback JSON, singleton dengan constructor yang menerima `Database`/path supaya bisa diuji terisolasi) dengan API penuh spec §4.1: `begin`, `get`, `markSent`, `markFailed`, `markUnresolved`, `registerRetry`, `abandon`, `listStaleInFlight`, `countInFlight`, `pruneTerminal`. DDL persis spec §4.4 + indeks `idx_outgoing_operations_state`. Sertakan uji `test/simulate-outgoing-store.js` (SQLite sementara) untuk transisi state dan pemangkasan TTL. | REQ-024, GUD-003, CON-006, CON-008 | AC-023 | TASK-001 | 3 (S) | ✅ WA-Gateway `55a1ae1` | 2026-09-24 |
| TASK-003 | GW | **Tracer bullet** idempotensi teks: buat `src/delivery/outgoingOperationService.js` dengan `runOperation({operationId, payloadHash, kind, chatId, send})` (gaya spec §8) yang menulis `begin()` **sebelum** `send()`. Wiring di `src/api/ci4Routes.js` untuk `POST /send`: validasi `operation_id` (pola + 1–64) → `400 INVALID_OPERATION_ID`; hitung `payload_hash` **setelah** validasi payload (A-7); fingerprint beda → `409 OPERATION_ID_REUSED`; state terminal → hasil tersimpan + `replayed:true`; tanpa `operation_id` berperilaku seperti sekarang + satu `warn` per proses; respons memuat `state`/`replayed`/`operation_id` di samping field lama. Tambah cabang `/send` pada `test/simulate-outgoing-idempotency.js`. | REQ-020, REQ-021, REQ-022, REQ-023, REQ-025, REQ-026 | AC-019, AC-020, AC-021, AC-022, AC-024, AC-025 | TASK-002 | 3 (M) | ✅ WA-Gateway `6fe151a` | 2026-09-24 |
| TASK-004 | GW | Jalur media: wiring `POST /send-media` pada service yang sama. Fingerprint memakai `mediaMeta` dari konten **hasil decode** (`media_type, size, sha256, mimetype, file_name, caption, is_animated`); body base64 penuh tidak pernah di-hash atau ditulis ke log (ASSUMPTION-005, SEC-001); `media_ref` disimpan pada `markSent`; payload media tidak valid → `400 INVALID_MEDIA_*` **tanpa** baris operasi (A-7). Tambahkan cabang media pada `test/simulate-outgoing-idempotency.js`. | REQ-020, REQ-021, REQ-022, REQ-023, REQ-025, SEC-001 | AC-019, AC-020, AC-021, AC-022, AC-024 | TASK-003 | 2 (S) | ✅ WA-Gateway `bdbf534` | 2026-09-24 |
| TASK-005 | - | **VERIFY**: jalankan `node test/simulate-outgoing-idempotency.js` dan `node test/simulate-outgoing-store.js` → 0 gagal (AC-019..AC-025, AC-044). Tambahan wajib: (a) **guard statis** (pola TASK-011 gelombang 1) yang gagal bila penulisan baris `in_flight` tidak mendahului `sendMessage(` pada kedua endpoint; (b) assert `sendMessage` **tidak** terpanggil pada jalur replay/`OPERATION_ID_REUSED`/`INVALID_OPERATION_ID`; (c) pemindaian berkas log untuk AC-039 (tidak ada teks pesan/`media_base64`); (d) periksa seluruh skrip uji hanya memakai SQLite di folder temp dan tidak menulis ke `data/gateway.sqlite`; (e) jalankan ulang semua `test/simulate-*.js` gelombang 1 (regresi kumulatif, CON-013). | - | AC-039, CON-013 | TASK-004 | 2 (S) | ✅ VERIFY lulus; guard/log-scan/isolasi WA-Gateway `5a48311` | 2026-09-24 |
| TASK-006 | - | **APPROVAL**: tunggu konfirmasi eksplisit user sebelum lanjut ke Fase 2. | - | - | - | - | ✅ APPROVAL diberikan user | 2026-09-24 |

### Implementation Phase 2 — Hasil kirim yang tidak pasti: lease, cap, dan pemulihan saat start (E-O2)

- GOAL-002: Kirim yang hasilnya tidak pasti tidak dicoba buta; percobaan terbatas pada 5 kiriman per operasi; sisa operasi `in_flight` dari crash terlihat saat start dan baris terminal tua dipangkas tanpa menghilangkan jejak.

| Task | Repo | Description | Ref ID | AC Ref | Dep | Files | Completed | Date |
|---|---|---|---|---|---|---|---|---|
| TASK-007 | GW | Matriks respons + lease + cap pada `outgoingOperationService.js` + `ci4Routes.js`: sukses → `sent`/`200`; error `INVALID_CHAT_ID` (guard JID, jalur cadangan) → `failed`/`500 SEND_FAILED`; error lain → tetap `in_flight` + `504 SEND_UNRESOLVED`; retry `in_flight` **di dalam** `OUTGOING_LEASE_MS` → `409 SEND_IN_PROGRESS` tanpa kirim dan `attempts` tidak berubah; retry **setelah** lease dan `attempts < cap` → `registerRetry()` lalu kirim ulang (`replayed:false`); retry setelah lease dan `attempts >= cap` → `abandon()` + log `[CRITICAL]` + `502 DEAD_LETTERED` **tanpa** memanggil `sendMessage`. Pindahkan pemeriksaan `isConnected()` ke **sebelum** pembuatan baris operasi (REQ-030). | REQ-027, REQ-028, REQ-029, REQ-030 | AC-026 (stub-only), AC-028, AC-029, AC-030, AC-042 | TASK-003 | 2 (M) | ✅ WA-Gateway `62e92c2` | 2026-09-24 |
| TASK-008 | GW | Tugas start-up di `src/app/index.js`: panggil `listStaleInFlight(OUTGOING_LEASE_MS)` lalu catat level `error` (jumlah + maksimum 20 `operation_id`) **tanpa** memblokir start (REQ-031); panggil `pruneTerminal(OUTGOING_OPERATION_TTL_MS)` yang menghapus baris terminal tua, **mempertahankan** `in_flight`, dan mencatat `[CRITICAL]` untuk tiap baris `abandoned` yang dihapus sebelum penghapusan (REQ-032). Catat di komentar bahwa pemangkasan inilah batas jaminan idempotensi (D-13/A-5). Tambahkan uji start-up pada `test/simulate-outgoing-recovery.js`. | REQ-031, REQ-032 | AC-031, AC-032, AC-043 | TASK-002 | 2 (S) | ✅ WA-Gateway `e0f5585` | 2026-09-24 |
| TASK-009 | - | **VERIFY**: jalankan `test/simulate-outgoing-recovery.js` → 0 gagal untuk AC-026 (tandai **stub-only**, jangan klaim bukti produksi), AC-028 (30 detik → 409 tanpa ubah `attempts`; 40 detik → retry dan `attempts` naik), AC-029 (kiriman ke-1..5 jalan, permintaan ke-6 → `abandoned` + `502` + `[CRITICAL]`, `sendMessage` tidak dipanggil), AC-030 (`NOT_CONNECTED` tidak meninggalkan baris), AC-031, AC-032, AC-043 (baris `abandoned` lama dicatat lalu dihapus; `operation_id` yang sama menjadi operasi baru). Jalankan ulang seluruh skrip Fase 1 + gelombang 1 (CON-013) dan ulangi pemindaian log AC-039. | - | AC-026, AC-039, CON-013 | TASK-007, TASK-008 | 2 (S) | ✅ VERIFY lulus; 21/21 skrip + log-scan AC-039 + isolasi SQLite temp (AC-026 stub-only) | 2026-09-24 |
| TASK-010 | - | **APPROVAL**: tunggu konfirmasi eksplisit user sebelum lanjut ke Fase 3. | - | - | - | - | | |

### Implementation Phase 3 — Antrean masuk berhenti dengan benar: attempt counter, dead-letter, poison-message (E-O3)

- GOAL-003: Event yang berulang gagal atau ditolak permanen berhenti pada state terminal `dead` dengan jejak yang bisa diputar ulang, bukan dicoba selamanya.

| Task | Repo | Description | Ref ID | AC Ref | Dep | Files | Completed | Date |
|---|---|---|---|---|---|---|---|---|
| TASK-011 | GW | `src/store/incomingBuffer.js`: tambah kolom `dead_lettered_at` lewat pola migrasi ringan yang sudah ada (`PRAGMA table_info` + `ALTER TABLE ADD COLUMN`, aman diulang); batasi `markFailedAttempt()` sehingga `attempts + 1 >= DELIVERY_MAX_ATTEMPTS` **atau** usia > `DELIVERY_DEAD_AFTER_MS` memindahkan baris ke `status='dead'` + `dead_lettered_at` dan mengembalikan `deadLettered:true` (tetap mengembalikan `delayMs`/`nextAttemptAt` untuk pemanggil lama); pertahankan filter `getDueEvents()` apa adanya; tambah `replayDeadLetter(id)` (satu siklus tambahan, `attempts` dipertahankan) dan `countDeadLettered()`; catat jumlah `dead` saat start pada level `error` bila > 0 dan catat `[CRITICAL]` + instruksi menghentikan replay otomatis bila pertambahan `dead` dalam satu siklus melewati `DELIVERY_DEAD_BURST_THRESHOLD`. Alasan `reason` wajib memakai enum `max_attempts` atau `max_age` pada jalur ini. Sertakan uji `test/simulate-dead-letter.js` (SQLite sementara). | REQ-033, REQ-034, REQ-035, REQ-037, REQ-038, CON-006 | AC-033, AC-034, AC-035, AC-037, AC-038 | TASK-002 | 2 (M) | | |
| TASK-012 | GW | `src/delivery/incomingDelivery.js`: klasifikasikan hasil `postToCI4()` — `400`/`422` = penolakan permanen → langsung `status='dead'` **tanpa** menambah `attempts` dan catat `[CRITICAL]` dengan `wa_message_id`, `attempts`, `last_error`, dan `reason='permanent_rejection'`; `401/403/404/408/429/5xx`/timeout/jaringan → `markFailedAttempt()` seperti sekarang (D-06). Beri komentar yang menandai cabang `422` sebagai `[Assumed / Out of Scope]` (A-8b: `InboxGatewayApi.php` hanya membalas `200`/`400`/`500`) supaya tidak diklaim sebagai bukti produksi. Tambahkan uji `deliverOne()` per kode HTTP. | REQ-035, REQ-036 | AC-035, AC-036 | TASK-011 | 2 (S) | | |
| TASK-013 | - | **VERIFY**: jalankan `test/simulate-dead-letter.js` → 0 gagal untuk AC-033 (`deadLettered:true` pada cap/usia), AC-034 (`getDueEvents()` tidak mengembalikan `dead`), AC-035 (non-destruktif: baris masih ada, `attempts`/`last_error` utuh, `dead_lettered_at` terisi, log `[CRITICAL]` memuat `wa_message_id` + `reason` dari enum), AC-036 (`400` → `dead` tanpa menambah `attempts`; `401`/`500` → tetap `failed` + dijadwalkan ulang), AC-037 (replay tepat satu siklus), AC-038 (3 baris `dead` saat start dicatat; burst > ambang → `[CRITICAL]`). Jalankan ulang seluruh skrip Fase 1–2 + gelombang 1 (CON-013); pastikan tidak ada skrip uji yang menulis ke `data/gateway.sqlite` produksi. | - | AC-033, AC-034, AC-035, AC-036, AC-037, AC-038, CON-013 | TASK-012 | 2 (S) | | |
| TASK-014 | - | **APPROVAL**: tunggu konfirmasi eksplisit user sebelum lanjut ke Fase 4. | - | - | - | - | | |

### Implementation Phase 4 — Sisi pemanggil AuliaPos: kolom kunci, dedupe baris, cabang respons, dan UI (E-O4)

- GOAL-004: Kasir bisa mengirim ulang dengan kunci yang sama dan AuliaPos tidak menulis baris `messages` kedua; perbedaan `SEND_IN_PROGRESS`/`SEND_UNRESOLVED`/`OPERATION_ID_REUSED` terlihat di UI sebagai keadaan yang benar.

**Prasyarat urutan (RISK-002):** fase ini MUST dijalankan di branch `feature/m1-wave2-outgoing-idempotency` repo AuliaPos, dimulai dari HEAD `v2.3` saat branch dibuat, dan **MUST di-merge sebelum pekerjaan kode M3 Fase 1e dimulai** (alasan lengkap: RISK-002).

| Task | Repo | Description | Ref ID | AC Ref | Dep | Files | Completed | Date |
|---|---|---|---|---|---|---|---|---|
| TASK-015 | AP | **Preflight branch & guard tabrakan M3**: pastikan `git status --short` kosong (working copy bersih), catat HEAD saat itu sebagai basis, lalu buat branch `feature/m1-wave2-outgoing-idempotency`. Verifikasi tidak ada perubahan M3 yang sedang berjalan pada `app/Controllers/Inbox.php`/`app/Views/inbox/index.php` (M3 Fase 1d sudah di-merge `db7f301`; M3 Fase 1e masih berstatus Spec-only tanpa plan/kode). Tulis catatan urutan RISK-002 (kerjakan + merge M1 W2 lebih dulu, Fase 1e rebase di atasnya) ke decision log baru (`docs/decisions/`, repo AuliaPos). | CON-012 | - | - | 0 (XS) | | |
| TASK-016 | AP | Migration additive: tambah `messages.gateway_operation_id VARCHAR(64) NULL` + indeks UNIQUE, mengikuti pola `app/Database/Migrations/2026-09-22-000001_AddIsInternalToMessages.php` (grup DB `inbox`). Lebar 64 disamakan dengan batas REQ-020 agar pemotongan senyap mustahil (R-3). MUST NOT mengubah kolom lain (`send_status`, `wa_message_id`) dan tidak menambah FK. Uji di `tests/database/` (kolom ada, UNIQUE menolak duplikat non-NULL, banyak `NULL` tetap diterima). Jalankan `php spark migrate` hanya pada database uji (CON-014). | CON-009 | AC-041 (bagian skema) | TASK-015 | 2 (S) | | |
| TASK-017 | AP | `Inbox::kirimKeConversation()`: baca `operation_id` dari request AJAX kasir dan **jangan** membuat kunci di server (REQ-039/A-4); teruskan ke `callGatewaySend()`/`callGatewaySendMedia()`; pada respons sukses, cek `messages` berdasarkan `gateway_operation_id` — bila sudah ada, kembalikan baris itu tanpa `insert` kedua, bila belum ada `insert` seperti sekarang dengan `gateway_operation_id` diisi dan `send_status='sent'` (spec §4.7 langkah 3). Tambahkan uji controller + `tests/database/` untuk AC-041 (satu baris per `operation_id`; baris masuk lain tidak terpengaruh). | REQ-039, REQ-041 | AC-041, AC-044 | TASK-016 | 2 (S) | | |
| TASK-018 | AP | `Inbox::callGatewaySend()` dan `Inbox::callGatewaySendMedia()`: kembalikan `error_code`, `state`, dan `replayed` dari respons Gateway di samping field lama yang sudah dibaca (`success`, `wa_message_id`, `timestamp`, `media_ref`, `error`) — sebelumnya hanya `success`/`wa_message_id`/`timestamp`/`message` yang dibaca (`Inbox.php:2062-2074`), sehingga `409 SEND_IN_PROGRESS`/`504 SEND_UNRESOLVED`/`409 OPERATION_ID_REUSED` tidak bisa dibedakan (REQ-040). Lalu di `kirimKeConversation()`: untuk `SEND_IN_PROGRESS`/`SEND_UNRESOLVED` jawab UI sebagai "hasil belum pasti" (**tanpa** memaksa insert baris sukses dan tanpa menyarankan kirim ulang buta); untuk `OPERATION_ID_REUSED` catat `log_message('error', ...)` dan minta UI memakai kunci baru; `NOT_CONNECTED`/`DEAD_LETTERED` tetap kegagalan biasa (spec §4.7 langkah 4–6). Uji controller untuk ketiga cabang. | REQ-040, REQ-041 | AC-045, AC-046 (bagian server) | TASK-017 | 2 (S) | | |
| TASK-019 | AP | UI balas di `app/Views/inbox/index.php` (+ JS-nya, surgical pada form balas saja): simpan `operation_id` pada state composer (mis. atribut `data-operation-id`), buat dengan `crypto.randomUUID()` dan fallback hex berbasis `Math.random` untuk peramban lama, pakai ulang saat kirim ulang setelah gagal/timeout, buang setelah sukses atau saat isi kotak berubah, dan buat kunci **baru** saat menerima `409 OPERATION_ID_REUSED`; tampilkan keadaan "hasil belum pasti, jangan kirim ulang dulu" untuk `SEND_IN_PROGRESS`/`SEND_UNRESOLVED` (REQ-039, REQ-041, ASSUMPTION-010). Jangan menyentuh daftar conversation/pencarian (`apiConversations()`, area M3). | REQ-039, REQ-041 | AC-046 | TASK-018 | 2 (S) | | |
| TASK-020 | - | **VERIFY**: jalankan `vendor/bin/phpunit --no-coverage` → 100% lulus tanpa skip/suppression, dengan baseline **≥ 324 test / 1097 assertion** (baseline terakhir M3 setelah Fase 1d) dan tambahan uji baru; catat jumlah test/assertion hasil. Periksa AC-040: payload yang dikirim AuliaPos ke `POST /api/inbox/gateway/messages` identik sebelum/sesudah perubahan (bandingkan contoh payload nyata). Periksa AC-045/AC-046 lewat uji controller + bukti render. Periksa diff `git --no-pager diff --stat` hanya menyentuh berkas CON-012 (Inbox.php method kirim, index.php form balas, migration, tests) — tidak ada perubahan `apiConversations()`/`ConversationModel`/`InboxGatewayApi`. | - | AC-040, AC-045, AC-046, CON-012, CON-013 | TASK-019 | 1 (S) | | |
| TASK-021 | - | **APPROVAL**: tunggu konfirmasi eksplisit user (termasuk hasil uji suite) sebelum lanjut ke Fase 5. Setelah disetujui, catat ringkasan commit per task di decision log. | - | - | - | - | | |

### Implementation Phase 5 — Deploy terukur & pengukuran nyata (GW-09)

- GOAL-005: Kode Wave 2 berjalan di folder live, dan jaminan GW-09 dibuktikan lewat pengukuran nyata AC-027/AC-042 (bukan stub), dengan batas ASSUMPTION-009 ditulis jujur.

| Task | Repo | Description | Ref ID | AC Ref | Dep | Files | Completed | Date |
|---|---|---|---|---|---|---|---|---|
| TASK-022 | GW | **DEPLOY (prasyarat TASK-023)**: pola TASK-019 gelombang 1, dijalankan di repo WA-Gateway (bukan AuliaPos). (a) `git -C C:\projects\WA-Gateway status --short` MUST kosong sebelum mulai; (b) catat `21a4cb6` sebagai titik rollback; (c) `git -C C:\projects\WA-Gateway merge --ff-only feature/m1-wave2-outgoing-idempotency`; (d) `cmd /c "pm2 restart wa-gateway"` lalu `cmd /c "pm2 describe wa-gateway"` → status `online` dan `script path` tetap menunjuk folder live; (e) periksa log start: jumlah operasi `in_flight` basi (jika ada) dan baris terminal yang dipangkas tercatat sesuai TASK-008, serta jumlah baris `dead` (AC-031/AC-032/AC-043 pada data nyata). TASK-022 MUST NOT memakai `git checkout`/`git reset` dan MUST NOT menyentuh `auth/`. Catat HEAD sebelum/sesudah, waktu, status PM2, dan hitungan start di decision log baru (`docs/decisions/`). | Spec §7, CON-011 | AC-031, AC-032, AC-043 (bukti nyata) | TASK-013, TASK-021 | 0 (XS) | | |
| TASK-023 | GW + AP | **VERIFY/APPROVAL (mematikan/menjeda Gateway aktif)**: minta persetujuan eksplisit user sebelum menjalankan. Jalankan prosedur pengukuran nyata spec §13 butir 2, minimal **3 kali** (pola Ticket 01): perlambat respons `/send` (mis. jeda terkontrol atau pemblokiran port sementara) sehingga cURL 10 detik AuliaPos timeout (`Inbox.php:2047`; media 30 detik di `:2112`), kasir mengirim lewat UI, konfirmasi UI menampilkan "hasil belum pasti", lalu tekan kirim ulang **sebelum** lease 35 detik lewat → Gateway MUST membalas `409 SEND_IN_PROGRESS`, `sendMessage` tidak terpanggil lagi, dan pelanggan menerima **maksimal satu** pesan (AC-027). Ulangi dengan retry **setelah** lease pada operasi `sent` → `200` + `replayed:true` (AC-042). Selama pengukuran, periksa payload `POST /api/inbox/gateway/messages` tetap identik (AC-040) dan jumlah baris masuk baru bertambah sesuai pesan yang benar-benar terkirim, bukan jumlah percobaan. Catat hasil per percobaan (diterima/hilang/duplikat) + pernyataan jujur batas ASSUMPTION-009 (jendela crash sempit belum tertutup penuh; penutup penuhnya GW-21 di M2) di decision log baru. | - | AC-027, AC-040, AC-042 | TASK-022 | 1 (S) | | |
| TASK-024 | - | **APPROVAL/handoff**: tunggu konfirmasi eksplisit user bahwa Wave 2 selesai. Setelah disetujui: ubah front-matter plan ini `status: 'Planned'` → `'Completed'` dan badge `status-Planned-yellow` → `status-Completed-brightgreen`; lalu tawarkan penyimpanan progres ke `memory.instructions.md` (`memory-manager`) dan arahkan ke `/sdlc-code-review` (spec + plan + diff), dilanjutkan `/sdlc-audit-consistency` (opsional). Sampaikan juga follow-up RISK-002: setelah merge AuliaPos M1 W2, M3 Fase 1e boleh mulai plan/kode di atas basis baru. | - | - | - | 0 (XS) | | |

## 3. Alternatives

- **ALT-001**: Gateway menurunkan `operation_id` sendiri dari hash `chat_id + text` dalam jendela waktu — ditolak (spec ASSUMPTION-002/D-09) karena akan membuang kiriman sah yang teksnya kebetulan sama (mis. dua kali "OK"), dan `operation_id` wajib berasal dari pemanggil yang tahu dua permintaan HTTP adalah niat kirim yang sama.
- **ALT-002**: Tidak pernah mengirim ulang; jawab `409` selamanya untuk `in_flight` — ditolak (D-05) karena berisiko pesan kasir tidak pernah terkirim dan tidak punya terminal state.
- **ALT-003**: Menganggap **semua** 4xx dari AuliaPos sebagai penolakan permanen — ditolak (D-06) karena `401`/`403` adalah masalah kredensial yang berlaku untuk seluruh antrean; satu salah konfigurasi akan membuang semua pesan pelanggan sekaligus.
- **ALT-004**: Dead-letter memakai tabel terpisah (`dead_letters`) — ditolak (D-07) karena `getDueEvents()` sudah memfilter `status` dan CON-002/CON-006 mewajibkan perubahan skema hanya berbentuk penambahan kolom.
- **ALT-005**: Menjadikan `outgoing_operations` sebagai **antrean kirim keluar yang durable** (worker mengirim sendiri) — di luar scope (spec §1.1/§10, CON-010). Bila ke depan dibutuhkan, keputusan itu sulit dibalik dan wajib dibuatkan ADR di `docs/adr/`.
- **ALT-006**: Menguji AC-027/AC-042 hanya lewat stub — ditolak (spec §6 "Batas bukti (R-1)") karena keduanya adalah inti jaminan GW-09 dan Ticket 01 Baseline 3 mengukur duplikat nyata; TASK-023 mengukurnya dengan prosedur nyata 3 kali.
- **ALT-007**: Menunggu M3 Fase 1e selesai sebelum menyentuh AuliaPos — ditolak (RISK-002): kode M1 W2 di AuliaPos jauh lebih kecil dan siap lebih dulu (spec sudah di-PROCEED), sementara Fase 1e masih berstatus Spec-only; menunggu akan menahan GW-09 tanpa manfaat, dan migration additive M1 W2 tidak dibutuhkan Fase 1e sehingga tidak ada dependensi dari sisi Fase 1e.
- **ALT-008**: Menulis ulang (rewrite) `app/Views/inbox/index.php` atau blok besar `Inbox.php` agar lebih rapi — ditolak (Surgical Edit Mandate + CON-012): diff besar pada berkas yang sama dengan pekerjaan M3 menaikkan risiko konflik dan regresi; yang diubah hanya form balas + JS kirim dan method kirim.
- **ALT-009**: Menambahkan dependensi npm/composer (mis. generator UUID atau test runner) — ditolak (CON-008, spec §7 "Build: tidak ada"). UUID dibuat dengan `crypto.randomUUID()`/fallback; uji tetap memakai skrip `assert` + PHPUnit yang sudah ada.

## 4. Dependencies

- **DEP-001**: `C:\projects\WA-Gateway` @ `21a4cb6` (branch `master`) — repositori sumber untuk worktree baru; branch `feature/m1-wave2-outgoing-idempotency` dan worktree `C:\projects\WA-Gateway-m1w2` **belum ada** dan dibuat di TASK-001 (RISK-001).
- **DEP-002**: Baileys 6.7.24 terpasang di worktree — `sock.sendMessage()`, `isDecodableJid()` (`connectionManager.js:891`/`:1037`), dan `ci4Routes.js:41`/`:94`/`:124`/`:226` sebagai kontrak kode yang berjalan (EXT-001, A-1, A-2).
- **DEP-003**: `better-sqlite3` — dipakai `outgoing_operations` (TASK-002) dan `incoming_queue.dead_lettered_at` (TASK-011); jalur fallback JSON (`IncomingBufferJsonFile`) untuk build Android (INF-001, ASSUMPTION-007).
- **DEP-004**: Node.js 20 (Node 24 tidak didukung) dan PM2 (`wa-gateway`) di Aan-PC — prasyarat TASK-001 dan TASK-022.
- **DEP-005**: MySQL/MariaDB AuliaPos dengan grup koneksi `inbox` (`aulia_inboxdb`) — prasyarat migration TASK-016 (INF-003).
- **DEP-006**: `app/Controllers/InboxGatewayApi.php` (kontrak masuk Gateway → AuliaPos) — MUST tidak berubah (CON-005); dipakai sebagai acuan klasifikasi HTTP di TASK-012.
- **DEP-007**: `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` — pola VERIFY/APPROVAL/DEPLOY, CON-005 gelombang 1, dan pelajaran CR (DB sementara, guard statis) yang dipakai ulang di TASK-005/TASK-009/TASK-013/TASK-022.
- **DEP-008**: `docs/audit/clarification-report-m1-wave2-outgoing-idempotency-2026-09-24.md` (Readiness 88/100, PROCEED) — sumber keputusan R-1..R-3 dan A-1..A-8 yang sudah tertanam di spec v1.1; rencananya tidak menambah item terbuka.
- **DEP-009**: Baseline uji AuliaPos **324 test / 1097 assertion** (setelah M3 Fase 1d) sebagai pembanding di TASK-020 (spec §13 butir 4 menyebut angka lawas 298/948 — gunakan angka terbaru).

## 5. Files

**WA-Gateway (worktree `C:\projects\WA-Gateway-m1w2`):**

- **FILE-001**: `src/store/outgoingOperations.js` (baru) — store + state machine + TTL pruning, dua implementasi (SQLite & fallback JSON) — TASK-002.
- **FILE-002**: `src/delivery/outgoingOperationService.js` (baru) — orkestrasi `begin → send → resolve` yang dipakai `/send` dan `/send-media` agar tidak duplikatif; menampung lease, cap, dan matriks respons — TASK-003, TASK-007.
- **FILE-003**: `src/api/ci4Routes.js` — validasi `operation_id`, `payload_hash`, cabang replay/`OPERATION_ID_REUSED`/`SEND_IN_PROGRESS`/`SEND_UNRESOLVED`/`DEAD_LETTERED`, respons `state`/`replayed` — TASK-003, TASK-004, TASK-007.
- **FILE-004**: `src/config/index.js` — enam variabel lingkungan baru + clamp minimum — TASK-002.
- **FILE-005**: `src/store/incomingBuffer.js` — kolom `dead_lettered_at`, cap pada `markFailedAttempt()`, `replayDeadLetter()`, `countDeadLettered()`, log start/burst — TASK-011.
- **FILE-006**: `src/delivery/incomingDelivery.js` — klasifikasi `postToCI4()` (poison vs retryable) + log dead-letter — TASK-012.
- **FILE-007**: `src/app/index.js` — pemanggilan pemeriksaan start (operasi `in_flight` basi, `pruneTerminal`, jumlah `dead`) — TASK-008 (dan bukti pada TASK-022).
- **FILE-008**: `test/simulate-outgoing-store.js`, `test/simulate-outgoing-idempotency.js`, `test/simulate-outgoing-recovery.js`, `test/simulate-dead-letter.js` (semua baru, pola `assert`, SQLite di folder temp) — TASK-002..TASK-013.

**AuliaPos (`C:\xampp\htdocs\aulia`):**

- **FILE-009**: `app/Database/Migrations/<tanggal>_AddGatewayOperationIdToMessages.php` (baru) — kolom nullable + indeks UNIQUE — TASK-016.
- **FILE-010**: `app/Controllers/Inbox.php` — `kirimKeConversation()`, `callGatewaySend()`, `callGatewaySendMedia()` saja (surgical, CON-012) — TASK-017, TASK-018.
- **FILE-011**: `app/Views/inbox/index.php` (+ JS di dalamnya) — `operation_id` pada form balas, keadaan "hasil belum pasti", kunci baru saat `OPERATION_ID_REUSED` — TASK-019.
- **FILE-012**: `tests/database/` (kolom + idempotensi baris `messages`) dan `tests/session/` (cabang respons Gateway) — TASK-016..TASK-020.

**Dokumentasi (repo AuliaPos):**

- **FILE-013**: `docs/decisions/` — decision log baru untuk: titik rollback + hasil TASK-001, catatan urutan RISK-002 (TASK-015), hasil deploy (TASK-022), dan hasil pengukuran AC-027/AC-042 + batas ASSUMPTION-009 (TASK-023).
- **FILE-014**: `docs/ARCHITECTURE.md` — diperbarui bila `outgoingOperations`/`dead_lettered_at`/`gateway_operation_id` dianggap sebagai perubahan kontrak arsitektur (Living Architecture Map Mandate, dikerjakan di task terakhir fase terkait).

## 6. Testing

- **TEST-001**: `test/simulate-outgoing-store.js` — transisi state store, `attempts`/`registerRetry`/`abandon`, `listStaleInFlight`, `pruneTerminal`, paritas SQLite vs fallback JSON (TASK-002).
- **TEST-002**: `test/simulate-outgoing-idempotency.js` — AC-019..AC-025 dan AC-044: `operation_id` tidak valid, urutan `begin()` sebelum `sendMessage()` (AC-020 dengan send yang menggantung), replay `sent` (AC-021), `OPERATION_ID_REUSED` (AC-022), daya tahan setelah restart (AC-023), field respons (AC-024), tanpa `operation_id` (AC-025/AC-044). Uji juga `/send-media`.
- **TEST-003**: `test/simulate-outgoing-recovery.js` — AC-026 (**stub-only**, ditandai jelas), AC-028, AC-029, AC-030, AC-031, AC-032, AC-043.
- **TEST-004**: `test/simulate-dead-letter.js` — AC-033..AC-038 (cap/usia, filter `getDueEvents()`, non-destruktif, klasifikasi HTTP, replay satu siklus, log start/burst).
- **TEST-005 (Guard statis)**: pemeriksa regex atas source yang gagal bila penulisan `in_flight`/`begin(` tidak mendahului `sendMessage(` (TASK-005) — pola guard statis gelombang 1 TASK-011.
- **TEST-006 (Regresi kumulatif, CON-013)**: seluruh skrip `simulate-*.js` gelombang 1 + Fase 1–3 dijalankan ulang di setiap task VERIFY; 0 gagal.
- **TEST-007 (Kontrak, AC-040)**: perbandingan payload `POST /api/inbox/gateway/messages` sebelum/sesudah perubahan (TASK-020 dan TASK-023).
- **TEST-008 (AuliaPos, macro gate)**: `vendor/bin/phpunit --no-coverage` 100% lulus — baseline ≥ 324 test / 1097 assertion + uji baru kolom/idempotensi/cabang respons (TASK-020). Sinyal exit-0 dipakai karena `composer test` keluar 1 hanya akibat peringatan coverage driver yang sudah ada.
- **TEST-009 (Macro Gate, nyata)**: TASK-023 — prosedur AC-027/AC-042 (3 ulangan, `pm2`/jeda terkontrol), satu-satunya uji yang menyentuh Gateway aktif; menentukan kelulusan GW-09.
- **TEST-010 (Kebersihan data)**: setiap skrip Gateway memakai SQLite di folder temp (pola `simulate-durable-buffer.js`) dan MUST NOT menulis ke `data/gateway.sqlite`; migration AuliaPos diuji di database uji (CON-014). Diverifikasi di TASK-005/TASK-009/TASK-013/TASK-020.
- **TEST-011 (Lint dokumen)**: plan ini diperiksa dengan `markdownlint-cli2`; jenis temuan yang diterima sama dengan plan/spec gelombang 1 (`MD013` bawaan 80, `MD028` antar dua blok alert, `MD060` gaya pipa tabel) — repo tidak punya konfigurasi `.markdownlint*` dan standar proyek menetapkan batas 400 karakter. Tidak boleh muncul jenis temuan baru. Hasil terukur `markdownlint-cli2@0.22.1` (2026-09-24): **`MD013`=186, `MD028`=1, `MD060`=90** (277 temuan, 0 selain tiga jenis itu), sedangkan plan gelombang 1 terukur `MD013`=92 dan `MD060`=48 untuk jenis yang sama; `MD009`/`MD012`/`MD025`/`MD032`/`MD056` = 0. Perbaikan yang sudah dilakukan: pipa liar di deskripsi TASK-011, jumlah sel TASK-024, dan spasi di ujung judul Phase 5.

## 7. Risks & Assumptions

### 7.1 Risiko wajib dari instruksi pemilik proyek

- **RISK-001 (High — wajib ditutup lebih dulu)**: worktree `C:\projects\WA-Gateway-m1w2` dan branch `feature/m1-wave2-outgoing-idempotency` **belum ada** (diverifikasi 2026-09-24: `Test-Path` = False; `git worktree list` hanya menampilkan `C:/projects/WA-Gateway`; `git branch` hanya `master` + `origin/master`; HEAD `21a4cb6`). Mitigasi: **TASK-001 adalah task pertama** dan menjadi prasyarat seluruh task Gateway; bila pembuatan worktree atau `npm ci` gagal (mis. `better-sqlite3` tidak ter-build di Node 20), hentikan fase dan lapor ke user (§9 Kontingensi Plan B). Task terkait: TASK-001..TASK-013, TASK-022.
- **RISK-002 (High — tabrakan working copy AuliaPos dengan M3)**: `C:\xampp\htdocs\aulia` dipakai bersama pekerjaan M3. Situasi terukur 2026-09-24: M3 Fase 1d **sudah di-merge** (`db7f301`, plan M3 Fase 1 berstatus `Completed`, TASK-022 sudah di-Approval), sehingga tidak ada perubahan M3 yang sedang berjalan hari ini; sumber tabrakan nyata adalah **M3 Fase 1e (GH-010, pencarian isi pesan)** yang Spec-nya sudah ada (rev 1.3) tetapi **plan dan kodenya belum dibuat** — dan Fase 1e menyentuh berkas yang sama: `Inbox::apiConversations()` + `app/Views/inbox/index.php` (spec M3 CON-004). Keputusan urutan/branch: (a) M1 W2 AuliaPos dikerjakan di branch **terpisah** `feature/m1-wave2-outgoing-idempotency` (TASK-015), bukan langsung di `v2.3`; (b) **M1 W2 diselesaikan dan di-merge lebih dulu**, baru M3 Fase 1e memulai `/sdlc-plan-tasks` dan kodenya di atas basis hasil merge — karena M1 W2 lebih kecil, Spec-nya sudah PROCEED, dan tidak ada dependensi dari sisi Fase 1e (migration additive M1 W2 tidak dipakai Fase 1e); (c) overlap fisik dibatasi: M1 W2 hanya menyentuh `kirimKeConversation()`/`callGatewaySend*()` dan **form balas** di `index.php`, sedangkan Fase 1e menyentuh `apiConversations()` dan **daftar/pencarian** — sisa konflik bersifat regional dan diselesaikan manual dengan mempertahankan kedua sisi (CON-012). Task terkait: TASK-015..TASK-021.
- **RISK-003 (High — batas jujur, wajib ditulis di decision log)**: kombinasi tiga batas yang tidak boleh diklaim tertutup — (i) **ASSUMPTION-009**: bila proses Gateway mati tepat setelah WhatsApp menerima pesan tetapi sebelum baris operasi ditandai `sent`, kirim ulang dengan `operation_id` sama **masih bisa menduplikasi**; penutup penuhnya adalah kabar status pengiriman eksplisit (GW-21, M2) di luar scope; (ii) **ASSUMPTION-007**: paritas jalur fallback JSON (`IncomingBufferJsonFile`) — perilaku di build Android belum pernah diuji di lingkungan nyata yang memakainya; (iii) **D-13/A-5**: jaminan idempotensi hanya berlaku **≤ `OUTGOING_OPERATION_TTL_MS` (24 jam)**; setelah baris terminal dipangkas, `operation_id` lama dianggap operasi baru (AC-043), dan baris `abandoned` yang dipangkas dicatat `[CRITICAL]` lebih dulu. Mitigasi: TASK-023 mencatat ketiganya secara eksplisit; tidak ada klaim "duplikat mustahil". Task terkait: TASK-002 (fallback JSON), TASK-008 (pemangkasan + log), TASK-023 (pernyataan batas).

- **RISK-004 (bukan stub — butuh manusia & proses nyata)**: AC-027 dan AC-042 MUST diukur dengan prosedur nyata (spec §13 butir 2), bukan stub: kasir mengirim lewat UI, respons `/send` diperlambat sehingga cURL 10 detik AuliaPos timeout, dan tombol kirim ulang ditekan pada waktu yang tepat (sebelum vs sesudah lease 35 detik). Mitigasi: TASK-023 disusun sebagai VERIFY/APPROVAL yang menghentikan/menjeda proses live hanya dengan persetujuan eksplisit, 3 ulangan, hasil per percobaan dicatat. Beban waktu jauh lebih kecil dari TASK-017 gelombang 1 (yang mematikan Gateway 3 × 30 detik + 10 pesan manual per percobaan). Task terkait: TASK-023.

### 7.2 Asumsi yang diekstrak dari spec (PRD-bypass synergy)

Seluruh tag `[ASSUMPTION-*]` spec v1.1 diekstrak apa adanya; task yang bergantung padanya ditandai **High Risk**.

- **ASSUMPTION-001 (cakupan ganda dead-letter)**: batas percobaan diterapkan pada `incoming_queue` **dan** `outgoing_operations`. *Mitigasi:* bila pemilik proyek hanya menginginkan antrean masuk, REQ-029/AC-029/TASK-007 dapat dibuang sebagai satu unit tanpa menyentuh sisanya (biaya membatalkan kecil: satu nilai enum + satu kolom). Task: TASK-007, TASK-011.
- **ASSUMPTION-002 (perubahan AuliaPos termasuk scope)**: kolom `messages.gateway_operation_id`, pengiriman `operation_id` dari `Inbox::kirimKeConversation()`, frontend sebagai pemilik kunci. *Mitigasi:* bila ditolak, hapus Fase 4 (TASK-015..021) dan seluruh AC bertanda AuliaPos (AC-041, AC-044..AC-046, versi UI AC-027/AC-042) — GW-09 hanya tertutup sebagian; ini MUST dikonfirmasi user sebelum Fase 4 dimulai. Task: TASK-016..TASK-019 (**High Risk**).
- **ASSUMPTION-003 (nilai bawaan batas)**: `OUTGOING_MAX_ATTEMPTS=5`, `OUTGOING_LEASE_MS=35000`, `OUTGOING_OPERATION_TTL_MS=86400000`, `DELIVERY_MAX_ATTEMPTS=100`, `DELIVERY_DEAD_AFTER_MS=86400000`, `DELIVERY_DEAD_BURST_THRESHOLD=10`. *Mitigasi:* nilai diubah lewat env tanpa mengubah kode; `DELIVERY_MAX_ATTEMPTS=100` setara ±3,4 jam dengan `maxDelayMs=120000` yang sudah berjalan, sehingga pemadaman AuliaPos > ±3,4 jam memindahkan pesan ke `dead` — dapat dikembalikan lewat `replayDeadLetter()` (non-destruktif). Task: TASK-002, TASK-011.
- **ASSUMPTION-004 (dead-letter non-destruktif)**: baris tidak pernah dihapus dan disediakan `replayDeadLetter()`; belum ada endpoint HTTP replay (Ticket 14). *Mitigasi:* replay dipakai manual oleh operator lewat fungsi; replay otomatis adalah aturan baru di luar spec. Task: TASK-011.
- **ASSUMPTION-005 (fingerprint payload keluar)**: SHA-256 dari JSON kanonik `[kind, chatId, text | null, mediaMeta | null]` dengan `mediaMeta` dari konten **hasil decode**; body base64 tidak pernah di-hash apa adanya maupun ditulis ke log. *Mitigasi:* uji memakai dua payload media berkonten berbeda dengan kunci sama → MUST `409`. Task: TASK-003, TASK-004 (**High Risk**).
- **ASSUMPTION-006 (semua error Baileys pasca-`in_flight` ambigu)**: kecuali `INVALID_CHAT_ID` yang diperlakukan sebagai jalur cadangan `failed`. *Mitigasi:* uji stub dengan beberapa bentuk error; klasifikasi keliru hanya menambah satu percobaan ulang sebelum cap. Task: TASK-007.
- **ASSUMPTION-007 (paritas fallback JSON) — High Risk**: `outgoing_operations` dan batas percobaan juga berlaku pada `IncomingBufferJsonFile` dengan semantik setara; jalur fallback ini belum pernah diuji di lingkungan yang benar-benar memakainya (build Android). *Mitigasi:* uji paritas pada TASK-002/TASK-009 bila jalur fallback tersedia; batas bukti ditulis di decision log (RISK-003 ii). Task: TASK-002.
- **ASSUMPTION-008 (penamaan)**: tabel `outgoing_operations`; kolom `operation_id`, `payload_hash`, `kind`, `chat_id`, `state`, `wa_message_id`, `media_ref_json`, `attempts`, `last_error`, `created_at`, `updated_at`, `resolved_at`, `dead_lettered_at`; kolom AuliaPos `messages.gateway_operation_id`. *Mitigasi:* nama dipakai apa adanya seperti spec §4.4/§4.7; renaming setelah migrasi lebih mahal. Task: TASK-002, TASK-016.
- **ASSUMPTION-009 (jendela crash) — High Risk**: lihat RISK-003 (i); jendela duplikat dipersempit (tanda `in_flight` sebelum kirim, `sent` segera sesudah) dan dibuat terlihat lewat log `[CRITICAL]` saat operasi `in_flight` basi ditemukan saat start — **tidak** diklaim tertutup. Task: TASK-007, TASK-008, TASK-023.
- **ASSUMPTION-010 (frontend pemilik tunggal `operation_id`) — High Risk**: `crypto.randomUUID()` (36 karakter) + fallback hex, disimpan pada state composer, dipakai ulang saat kirim ulang, dibuat baru bila teks diedit/berhasil/`409 OPERATION_ID_REUSED`. Bila `operation_id` hilang (muat ulang halaman), kasir kembali ke perilaku lama. *Mitigasi:* uji manual UI + uji endpoint (TASK-019, TASK-020); batas ini disadari, bukan bug. Task: TASK-019.
- **ASSUMPTION-011 (bahasa dokumen)**: spec berbahasa Indonesia; plan ini mengikuti (lihat note di Introduction). Task: seluruh dokumen.

### 7.3 Risiko tambahan yang ditemukan saat perencanaan

- **RISK-005 (rendah — inkonsistensi dokumen spec)**: spec §7 menulis "**lima** variabel lingkungan baru", sedangkan §4.6/GUD-003 mencantumkan **enam** (`DELIVERY_DEAD_BURST_THRESHOLD` tidak dihitung di §7). Keputusan plan: implementasikan **enam** sesuai §4.6/GUD-003/REQ-038; jangan menjatuhkan `DELIVERY_DEAD_BURST_THRESHOLD` karena REQ-038/AC-038 bergantung padanya. Task: TASK-002.
- **RISK-006 (rendah — bukti palsu potensial)**: AC-026(b) (`INVALID_CHAT_ID` → `failed`/`500`) bersifat **stub-only**: pada lalu lintas normal, `ci4Routes.js:41`/`:124` sudah menolak JID yang sama dengan `400 INVALID_CHAT_ID`, dan guard `isDecodableJid()` berjalan sebelum `sendMessage()`. Mitigasi: TASK-009 MUST menandai cabang ini sebagai jalur cadangan dan MUST NOT mengklaimnya sebagai bukti perilaku produksi (A-2). Task: TASK-007, TASK-009.
- **RISK-007 (rendah — sisa duplikat di UI)**: setelah muat ulang halaman, `operation_id` hilang sehingga kirim ulang menjadi kiriman baru (ASSUMPTION-010). Mitigasi yang mungkin (belum dispesifikasikan, jadi **bukan** scope plan ini): menyimpan kunci di penyimpanan peramban. Dicatat sebagai batas yang diterima. Task: TASK-019.
- **RISK-008 (rendah — urutan deploy dua repo)**: setelah Fase 4 selesai, UI AuliaPos mengirim `operation_id` ke Gateway; bila Gateway belum di-deploy (TASK-022 belum jalan), Gateway lama mengabaikan field itu dan berperilaku seperti sekarang (additive, CON-007) — tidak rusak, hanya idempotensi belum aktif. Mitigasi: jalankan TASK-022 sebelum pengukuran TASK-023, dan sebaiknya sebelum uji manual UI; catat urutan ini di decision log. Task: TASK-022, TASK-023.

- **RISK-009 (rendah — scope creep)**: pekerjaan ini rawan melebar ke M2 (state consistency, `assigned_to`), GW-20/GW-21 (health, kabar status pengiriman), E-02/E-07, dan Ticket 05/12–16. Mitigasi: task wajib terpetakan ke `Ref ID` yang ada; permintaan di luar itu MUST di-PUSHBACK sesuai `AGENTS.md` §Phase Code. Task: seluruh task.
- **RISK-010 (rendah — keselamatan data)**: pelajaran CR gelombang 1 (tiga skrip lama menulis ke DB non-sementara) dan bahaya `php spark migrate` pada database berisi data nyata. Mitigasi: TEST-010 + CON-014, diverifikasi di TASK-005/TASK-009/TASK-013/TASK-020.
- **RISK-011 (rendah — operasional dead-letter)**: replay manual berulang pada baris yang mati karena `max_attempts` akan langsung kembali ke `dead` (satu siklus per replay, REQ-037). Jika jumlah baris `dead` melonjak (bug payload sistemik), ada risiko operator memutar ulang antrean tanpa hasil. Mitigasi: log `[CRITICAL]` burst + instruksi menghentikan replay otomatis (REQ-038) dan larangan replay otomatis tanpa persetujuan. Task: TASK-011, TASK-013.
- **RISK-012 (rendah — rollback migration AuliaPos)**: kolom `gateway_operation_id` tidak dipakai kolom lain, tetapi indeks UNIQUE harus ikut dibalik. Mitigasi: `down()` pada migration menghapus indeks lalu kolom (lihat §9). Task: TASK-016.

## 8. Related Specifications / Further Reading

- `spec/spec-process-m1-wave2-outgoing-idempotency.md` v1.1 (commit `7897d38`) — sumber tunggal REQ-020..REQ-041, AC-019..AC-046, D-05..D-13, A-1..A-8.
- `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` v1.2 — pola bentuk plan, CON-005, dan pola TASK-019 (DEPLOY)/TASK-017 (VERIFY nyata) yang dipakai ulang.
- `spec/spec-process-m1-wave1-incoming-reliability.md` v1.1 — REQ-001..REQ-019 dan AC-001..AC-018 yang penomorannya dilanjutkan; CON-001 (kontrak masuk) dan CON-002 (skema additive).
- `docs/audit/clarification-report-m1-wave2-outgoing-idempotency-2026-09-24.md` — Readiness 88/100 (PROCEED); keputusan R-1 (lease 35000), R-2 (cap 5, dicek sebelum kirim ulang), R-3 (`operation_id` 1–64), A-1..A-8.
- `docs/GATEWAY-REQUIREMENTS.md` — GW-09 (idempotensi kirim keluar) dan GW-19 (batas percobaan/dead-letter); GW-20/GW-21 tetap di luar scope.
- `docs/decisions/2026-09-21-m1-ticket01-baseline.md` — Baseline 3 (duplikat pada retry `/send`) dan Baseline 4 (`attempts` sampai 8 tanpa batas; pemulihan 115 detik).
- `docs/decisions/2026-09-21-m1-ticket02-audit-enqueue.md` — E-01..E-09 (konteks antrean masuk yang kini diberi batas percobaan).
- `docs/decisions/2026-09-23-m1-wave1-deploy-dan-ac001.md` — pola deploy `merge --ff-only` + `pm2 restart` dan format bukti pengukuran.
- `docs/audit/code-review-m1-wave1-2026-09-23.md` — temuan CR (DB sementara, guard statis) yang menjadi input TEST-005/TEST-010.
- `docs/TODO-CHAT.md` — Ticket 06–11 yang plan ini mulai tutup.
- `spec/spec-design-m3-operational-inbox-fase1.md` rev 1.3 — CON-004/Fase 1e: berkas `Inbox.php` + `app/Views/inbox/index.php` yang menjadi sumber RISK-002.
- `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — Fase 1d (Phase 4) selesai `db7f301`; acuan baseline uji AuliaPos 324/1097.
- `app/Controllers/InboxGatewayApi.php`, `app/Controllers/Inbox.php` — kontrak nyata sisi AuliaPos (`kirimKeConversation()`, `callGatewaySend()` di `:2062-2074`, timeout `:2047`/`:2112`).
- `docs/ARCHITECTURE.md` — Living Architecture Map yang diperbarui bila kontrak arsitektur berubah (FILE-014).

## 9. Rollback / Recovery Plan

- **Umum**: setiap task dikerjakan sebagai commit terpisah di branch masing-masing (`feature/m1-wave2-outgoing-idempotency` di repo Gateway dan repo AuliaPos). Rollback per fase = `git revert` commit-commit task fase itu (bukan `reset --hard`), agar histori tetap bisa diaudit. `auth/` dan sesi WhatsApp tidak pernah tersentuh, sehingga rollback apa pun tidak berisiko kehilangan sesi Gateway.

- **Deploy Gateway (TASK-022)**: rollback = `git -C C:\projects\WA-Gateway reset --hard 21a4cb6` lalu `cmd /c "pm2 restart wa-gateway"`, kemudian `pm2 describe wa-gateway` (status `online`, `script path` tetap folder live). `reset --hard` hanya dipakai di folder live, dan folder itu selalu dipastikan bersih (`status --short` kosong) sebelum deploy sehingga tidak ada perubahan lokal yang hilang. Tabel `outgoing_operations` yang sudah terbentuk tidak perlu dihapus — kode lama tidak membacanya, dan rollback ini tidak menyentuh `data/gateway.sqlite`.

- **Fase 1 (idempotensi `/send`, `/send-media`)**: rollback = `git revert` TASK-002..TASK-004. Setelah revert, Gateway berperilaku seperti `21a4cb6` (setiap permintaan mengirim). Baris `outgoing_operations` yang sudah ada bersifat inert (tabel baru, tidak dibaca kode lama) dan boleh dibiarkan; penghapusan manual tabel adalah keputusan operator, bukan bagian rollback otomatis. AuliaPos lama yang belum mengirim `operation_id` tidak terpengaruh.

- **Fase 2 (lease/cap/start-up)**: rollback = `git revert` TASK-007/TASK-008. Risiko yang harus disadari: baris `abandoned`/`failed`/`sent` yang sudah tercatat tetap tinggal; setelah revert, permintaan ulang dengan `operation_id` lama akan mengirim lagi (perilaku `21a4cb6`). Bila jejak itu masih dibutuhkan, jangan hapus tabelnya. Jika `pruneTerminal()` terbukti memangkas terlalu agresif, revert khusus TASK-008 sambil mempertahankan TASK-007.

- **Fase 3 (dead-letter antrean masuk)**: kolom `incoming_queue.dead_lettered_at` bersifat additive (CON-006), sehingga revert kode aman — kolomnya boleh tetap ada dan diabaikan kode lama. **Perhatian khusus**: baris berstatus `dead` tidak akan pernah diproses kode lama (`getDueEvents()` memfilter `status IN ('pending','failed')`), jadi sebelum/sesudah rollback putuskan secara eksplisit: (a) `replayDeadLetter(id)` untuk mengembalikan baris penting ke `failed`, atau (b) biarkan baris `dead` sebagai jejak permanen dan dokumentasikan di decision log. Jangan menghapus baris (dead-letter non-destruktif, REQ-035).

- **Fase 4 (AuliaPos)**: rollback kode = `git revert` TASK-017..TASK-019 (kembali ke perilaku tanpa `operation_id`; Gateway lama maupun baru sama-sama kompatibel karena field opsional, CON-007). Rollback skema = `php spark migrate:rollback` untuk migration TASK-016 (`down()` menghapus indeks UNIQUE lalu kolom `gateway_operation_id`); kolomnya nullable dan tidak dibaca kode lain, jadi membiarkannya juga aman — catat pilihannya di decision log. Arti kolom `messages` yang sudah ada tidak pernah berubah (CON-009), sehingga tidak ada data yang perlu direkonsiliasi.

- **Fase 5 (pengukuran gagal)**: TASK-023 tidak mengubah kode. Bila hasilnya gagal (masih ada duplikat, UI tidak menampilkan "hasil belum pasti", atau `409 SEND_IN_PROGRESS` tidak muncul), Wave 2 **tidak** ditutup: temuan dicatat sebagai decision log baru dan fase terkait dibuka kembali untuk perbaikan sebelum TASK-024 disetujui.

### Kontingensi (Plan B)

- **Kontingensi 1 — worktree/branch gagal dibuat (TASK-001)**: fallback `git clone C:\projects\WA-Gateway C:\projects\WA-Gateway-m1w2` lalu `git switch -c feature/m1-wave2-outgoing-idempotency 21a4cb6`. Bila `better-sqlite3` gagal di-build (Node 20), berhenti dan lapor ke user sebelum menulis kode apa pun — mengubah versi Node atau memasang kompiler berada di luar kewenangan plan ini.
- **Kontingensi 2 — pengukuran AC-027 tidak bisa direproduksi (TASK-023)**: fallback sesuai spec §13 butir 2: ganti teknik perlambatan (jeda terkontrol pada respons `/send` ⇄ pemblokiran port sementara) atau ulangi di sesi terpisah dengan jumlah pesan lebih banyak. Bila tetap gagal setelah 3 ulangan, jangan klaim AC-027 lulus: tulis temuan, buka kembali Fase 2, dan minta keputusan user.
- **Kontingensi 3 — `php spark migrate` pada database kerja nyata diragukan (TASK-016)**: fallback: uji migration di database uji terpisah dan tunda penerapan ke database kerja sampai user menyetujui (CON-014); Fase 4 tetap boleh maju karena kolom itu hanya dipakai jalur kirim.
- **Kontingensi 4 — jalur fallback JSON tidak dapat diuji (TASK-002/TASK-009)**: fallback: implementasi fallback JSON tetap ditulis (paritas kode), uji yang tersedia dijalankan, dan batas bukti dicatat jujur di decision log (RISK-003 ii). Jangan mengklaim paritas Android terverifikasi.

## 10. Pre-Flight Self-Correction Checklist

- [x] **Tidak ada horizontal slicing**: setiap task mengikat lapisan yang dibutuhkan satu perilaku ujung-ke-ujung (store+config → service+route; kolom → store → delivery; migration → controller → UI). Tidak ada pola "buat semua tabel lalu semua endpoint".
- [x] **Tidak ada task XL**: task terbesar menyentuh 3 berkas (TASK-002, TASK-003); tidak ada task yang menggabungkan dua subsistem independen atau memakai kata "dan" untuk dua aksi besar.
- [x] **Traceability ketat**: setiap task aksi memuat `Ref ID` (REQ/CON/SEC/GUD/spec §) dan `AC Ref`; VERIFY/APPROVAL/DEPLOY ditandai khusus.
- [x] **Dependensi bottom-up**: kolom `Dep` hanya menunjuk task yang dijadwalkan lebih dulu; grafik dependensi ditulis eksplisit di §2.
- [x] **VERIFY + APPROVAL di setiap fase**: TASK-005/006, 009/010, 013/014, 020/021, 023/024.
- [x] **Repo disebut per ticket**: kolom `Repo` (`GW`/`AP`/`-`) ada di setiap tabel fase.
- [x] **Asumsi tidak memblokir**: semua `[ASSUMPTION-*]` spec diekstrak ke §7.2 (bukan dijadikan pertanyaan pemblokir) dengan mitigasi dan task terkait; yang berkonsekuensi besar (ASSUMPTION-002) ditandai High Risk dan dimintakan konfirmasi sebelum Fase 4.
- [x] **Batas jujur tercatat**: RISK-003 menampung ASSUMPTION-009, ASSUMPTION-007, dan TTL idempotensi (D-13) sebagai batas yang MUST ditulis di decision log, bukan klaim tertutup.
- [x] **Tidak mengubah requirement**: tidak ada kolom, endpoint, enum, atau AC baru di luar spec v1.1; satu inkonsistensi dokumen (jumlah variabel env) diselesaikan ke arah requirement (RISK-005), bukan dengan mengubah spec.
- [x] **Tidak menulis kode**: dokumen ini hanya berisi rencana; seluruh penulisan kode diserahkan ke `/sdlc-write-code`.
