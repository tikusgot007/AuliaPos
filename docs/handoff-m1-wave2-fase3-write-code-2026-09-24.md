# Prompt & Handoff — `/sdlc-write-code` M1 Gelombang 2, Fase 3 (2026-09-24)

> [!IMPORTANT]
> **Dokumen ini non-normatif.** Sumber normatif tetap `plan/plan-process-m1-wave2-outgoing-idempotency-v1.0.md` (v1.0, commit `57de122`) dan `spec/spec-process-m1-wave2-outgoing-idempotency.md` v1.1 (commit `7897d38`). Bila terjadi konflik, **Plan + Spec menang**. Dokumen ini hanya brief operasional + prompt siap paste untuk sesi `/sdlc-write-code` berikutnya (Fase 3).

## 1. Prompt siap paste

Salin blok berikut ke **sesi baru** (lampirkan berkasnya, jangan hanya menyebut nama):

```text
/sdlc-write-code

Attach: @plan/plan-process-m1-wave2-outgoing-idempotency-v1.0.md (v1.0, status Planned, commit 57de122;
        kolom Completed TASK-001..010 sudah terisi)
Attach: @spec/spec-process-m1-wave2-outgoing-idempotency.md (v1.1, commit 7897d38)
Attach: @docs/decisions/2026-09-24-m1-wave2-eksekusi-fase2.md (hasil Fase 2: penyimpangan P-8..P-12, TASK-010 APPROVED)
Attach: @docs/decisions/2026-09-24-m1-wave2-eksekusi-fase1.md (hasil Fase 1: penyimpangan P-1..P-7, temuan T-1)

LINGKUP SESI INI: FASE 3 SAJA -- TASK-011..TASK-014 (attempt counter + dead-letter incoming_queue,
klasifikasi postToCI4), lalu BERHENTI di TASK-014 (APPROVAL). Jangan menyentuh Fase 4-5.
Fase 1 & 2 sudah selesai dan DISETUJUI pemilik (TASK-006, TASK-010); jangan dikerjakan ulang.

KONDISI AWAL (verifikasi dulu, jangan diasumsikan):
- WA-Gateway: kerja HANYA di worktree C:\projects\WA-Gateway-m1w2, branch feature/m1-wave2-outgoing-idempotency.
  Worktree SUDAH ADA -- JANGAN membuatnya lagi. HEAD harus e0f5585 (6 commit di atas 21a4cb6) dan
  `git status --short` kosong. Cek: git -C C:\projects\WA-Gateway-m1w2 log --oneline 21a4cb6..HEAD
  (urut dari bawah: 55a1ae1, 6fe151a, bdbf534, 5a48311, 62e92c2, e0f5585)
- Folder live C:\projects\WA-Gateway harus tetap @ 21a4cb6, READ-ONLY sampai TASK-022. Jangan sentuh auth/ dan data/.
  Dilarang: git checkout / git reset di folder live, npm run dev pada Gateway aktif, pm2 stop.
- AuliaPos: C:\xampp\htdocs\aulia -- Fase 3 TIDAK menyentuh kode repo ini (hanya dokumen decision log/handoff/memory).

ATURAN EKSEKUSI:
- Ikuti EXECUTION DIRECTIVE plan §2: satu task = satu commit kecil, tiap task kode mengirim ujinya pada
  penambahan yang sama, jalankan VERIFY (TASK-013) di akhir fase.
- Sumber nilai & kontrak: spec §4.5 (store incoming), §4.6 (env), REQ-033..REQ-038, D-06/D-07, dan enum
  reason (max_attempts | max_age | permanent_rejection).
- Floor-Guard: dilarang menambah suppression/skip atau menghapus assertion. Semua skrip Fase 1-2 dan
  gelombang 1 HARUS tetap lulus (CON-013).
- Uji Gateway hanya boleh memakai SQLite di folder temp; DILARANG menulis ke data/gateway.sqlite.
- JANGAN mengubah filter getDueEvents() -- REQ-034 sudah terpenuhi oleh filter yang ada dan MUST DIPERTAHANKAN.
- Setiap kali ragu soal urutan/isi task, tanya dulu; jangan mengarang task baru.
- Commit hanya di worktree, satu task satu commit. JANGAN push tanpa perintah pemilik.

KELUARAN YANG DIMINTA:
1. TASK-011..TASK-013 dieksekusi + TASK-014 APPROVAL (berhenti di sini).
2. Bukti TASK-013 apa adanya: output test/simulate-dead-letter.js (0 gagal) untuk AC-033 (deadLettered:true
   pada cap/usia), AC-034 (getDueEvents() tidak mengembalikan baris dead), AC-035 (non-destruktif: baris masih
   ada, attempts/last_error utuh, dead_lettered_at terisi, log [CRITICAL] memuat wa_message_id + reason dari enum),
   AC-036 (400 -> dead tanpa menambah attempts; 401/500 -> tetap failed + dijadwalkan ulang), AC-037 (replay tepat
   satu siklus), AC-038 (3 baris dead saat start dicatat; burst > ambang -> [CRITICAL] + instruksi hentikan replay
   otomatis); TANDAI cabang 422 sebagai [Assumed / Out of Scope] (A-8b); regresi kumulatif seluruh test/*.js;
   konfirmasi SQLite temp.
3. Ringkasan commit per task + decision log Fase 3 BARU di docs/decisions/ (jangan menimpa log Fase 1/2).
4. Jangan menaikkan status plan di front-matter (baru boleh di TASK-024). Boleh mengisi kolom Completed/Date.
5. Akhiri tiap langkah dengan 3 baris: Selesai / Belum / Masih dalam tujuan awal? YA/TIDAK.
```

## 2. Kondisi kode setelah Fase 2 (fakta terverifikasi 2026-09-24)

Branch `feature/m1-wave2-outgoing-idempotency`, HEAD `e0f5585` (6 commit di atas `21a4cb6`):

| Task Fase 2 | Commit | Berkas |
| --- | --- | --- |
| TASK-007 | `62e92c2` | `src/delivery/outgoingOperationService.js`, `test/simulate-outgoing-recovery.js` (baru) |
| TASK-008 | `e0f5585` | `src/delivery/outgoingOperationService.js` (`runStartupRecovery()`), `src/app/index.js` (1 require + 1 call), uji start-up di `test/simulate-outgoing-recovery.js` |

**Yang SUDAH ada (jangan ditulis ulang):**

- `src/config/index.js` **sudah memuat semua env Fase 3** (ditambahkan di TASK-002): `deliveryMaxAttempts` (bawaan 100), `deliveryDeadAfterMs` (bawaan 86400000), `deliveryDeadBurstThreshold` (bawaan 10), semua dengan clamp `Math.max`. **Jangan menambah variabel env baru.**
- `outgoingOperationService.runStartupRecovery()` sudah ada, tetapi **hanya** menyentuh `outgoing_operations` (operasi kirim KELUAR). TASK-011 menambah pemeriksaan `dead`/burst untuk `incoming_queue` — **jangan mengubah bagian outgoing**.
- Seluruh skrip Fase 1-2 + gelombang 1 lulus (regresi terakhir **21/21 exit 0**); guard statis + pemindaian log + pemeriksa isolasi SQLite sudah ada dan MUST tetap lulus.

**Catatan lintas fase:** `runStartupRecovery()` (outgoing) dan pemeriksaan `dead` `incoming_queue` (TASK-011) sama-sama dipanggil dari jalur start-up. Letakkan panggilan `incoming_queue` di `src/app/index.js` dekat panggilan yang sudah ada, atau di `incomingDelivery.start()` bila itu lebih sesuai pola yang ada — **jangan** menyentuh `outgoingOperationService`.

## 3. Lingkup Fase 3 (dan batas yang dilarang)

| Task | Repo | Isi singkat | AC | Dep |
| --- | --- | --- | --- | --- |
| TASK-011 | GW | `src/store/incomingBuffer.js`: kolom `dead_lettered_at` (migrasi ringan), cap pada `markFailedAttempt()` -> `status='dead'` + `dead_lettered_at` + `deadLettered:true`, `replayDeadLetter(id)`, `countDeadLettered()`, log `dead` saat start + `[CRITICAL]` burst; uji `test/simulate-dead-letter.js` (baru) | AC-033, AC-034, AC-035, AC-037, AC-038 | TASK-002 (selesai) |
| TASK-012 | GW | `src/delivery/incomingDelivery.js`: klasifikasi `postToCI4()` — `400`/`422` = permanen -> `dead` tanpa menambah `attempts`; `401/403/404/408/429/5xx`/timeout/jaringan -> `markFailedAttempt()`; uji `deliverOne()` per kode HTTP | AC-035, AC-036 | TASK-011 |
| TASK-013 | - | **VERIFY**: `test/simulate-dead-letter.js` 0 gagal (AC-033..AC-038) + regresi kumulatif seluruh `test/*.js` (CON-013) + pastikan tidak ada skrip menulis ke DB produksi | AC-033..AC-038, CON-013 | TASK-012 |
| TASK-014 | - | **APPROVAL**: berhenti, tunggu konfirmasi eksplisit pemilik | - | - |

**Dilarang di Fase 3:** Fase 4-5 (seluruh AuliaPos, `php spark migrate`, deploy/`pm2 restart`, pengukuran nyata AC-027/AC-042), menyentuh `auth/`/`data/`, dependensi npm baru, mengubah kontrak `POST /api/inbox/gateway/messages`, mengubah filter `getDueEvents()`, dan mengubah `outgoingOperationService`/`outgoingOperations` kecuali benar-benar diperlukan (dan bila begitu, deklarasikan sebagai penyimpangan).

## 4. Fakta kode `incoming_queue` yang sudah terverifikasi (jangan diasumsikan)

Diambil langsung dari `C:\projects\WA-Gateway-m1w2` (HEAD `e0f5585`):

| Aspek | Fakta |
| --- | --- |
| Kolom tabel (SQLite `CREATE TABLE`, `incomingBuffer.js:194`) | `id`, `wa_message_id`, `chat_id`, `message_type`, `text`, `media_json`, `identity_hint_json`, `message_timestamp`, `direction` (default `'incoming'`), `status` (default `'pending'`), `attempts` (default `0`), `next_attempt_at`, `last_error`, `created_at`, `updated_at`. **Kolom `dead_lettered_at` BELUM ada** — inilah yang ditambahkan TASK-011 |
| Pola migrasi ringan | `_migrate()` (`:192`): `PRAGMA table_info(incoming_queue)` -> `if (!columnNames.includes('x')) ALTER TABLE incoming_queue ADD COLUMN ...` diikuti `logger.info('[MIGRASI] ...')`. Preseden: `direction` (`:227`), `media_json` (`:235`), `identity_hint_json` (`:245`). Aman diulang; CON-006 additive |
| `getDueEvents(limit=20)` | SQLite `:308` / JSON `:548` — filter `status IN ('pending','failed')` **dan** `next_attempt_at <= now`. Sudah memenuhi REQ-034; **JANGAN diubah** (AC-034 hijau karena perilaku yang sudah ada ini) |
| `markFailedAttempt(id, attempts, errorMessage)` | SQLite `:316` / JSON `:564`. Sekarang: `status='failed'`, `attempts = attempts + 1`, `last_error`, `next_attempt_at = now + Math.min(initialDelayMs * backoffFactor^attempts, maxDelayMs)`. Nilai balik saat ini `{ delayMs, nextAttemptAt }` — **TAMBAHKAN `deadLettered`**, jangan hapus field lama (pemanggil lama harus tetap jalan) |
| `countPending()` | `:183` — menghitung `pending`/`failed` saja; baris `dead` tidak ikut (konsisten dengan REQ-034) |
| `incomingDelivery.deliverOne(event)` | `:30` — `postToCI4('/api/inbox/gateway/messages', body)` di `:49`; kegagalan -> `const { delayMs } = incomingBuffer.markFailedAttempt(event.id, event.attempts, result.error)` di `:60`; log memuat `httpStatus: result.status` di `:63` |
| Dua implementasi | `incomingBuffer` punya implementasi SQLite **dan** fallback JSON dengan API identik (pola sama dengan `outgoingOperations`). Paritas WAJIB diuji untuk keduanya (ASSUMPTION-007) |

## 5. Batas bukti, jebakan, dan hal yang sudah diketahui

- **Cabang `422` = `[Assumed / Out of Scope]`** (A-8b): `app/Controllers/InboxGatewayApi.php` di AuliaPos hanya mengembalikan `200`/`400`/`500` — tidak ada `422`. Beri komentar penanda dan **jangan** klaim sebagai bukti produksi.
- **Dead-letter non-destruktif** (REQ-035): baris `dead` **tidak pernah dihapus**; `attempts`/`last_error` dipertahankan; `dead_lettered_at` diisi sekali.
- **`replayDeadLetter(id)` memberi TEPAT SATU siklus tambahan** (A-6/REQ-037): baris yang mati karena `max_attempts` akan langsung kembali `dead` bila gagal lagi. Tidak ada endpoint HTTP replay (itu Ticket 14).
- **`DELIVERY_MAX_ATTEMPTS=100` belum final** (K-04): ±3,4 jam dengan `maxDelayMs=120000`; wajib ditinjau setelah pemadaman nyata. **Jangan ubah nilainya** — cukup dicatat di decision log.
- **`DELIVERY_DEAD_AFTER_MS=0` berarti tanpa batas usia** (clamp minimumnya 0, bukan 1).
- **Enum `reason` tunggal**: `max_attempts` | `max_age` | `permanent_rejection`. Nilai lain MUST NOT dipakai.
- **AC-040 (kontrak masuk) dan AC-041/AC-045/AC-046 (AuliaPos) milik Fase 4-5** — jangan diklaim di Fase 3.
- **Jebakan operasional yang sudah tercatat (jangan diulang):**
  - `node -e "require('./src/...')"` **tanpa** `SQLITE_PATH` membuat `data/gateway.sqlite` di worktree (pelanggaran TEST-010). Pakai `node --check` untuk sintaks, atau set `SQLITE_PATH` temp lebih dulu; hapus `data/` yang muncul seketika.
  - Loop regresi seluruh `test/*.js` dalam SATU perintah melewati batas 30 dtk — pecah per 3-4 skrip.
  - Berkas CRLF (`core.autocrlf=true`): normalkan `\r\n` -> `\n` sebelum regex/mutasi string; tidak ada `python` di shell.
  - Jangan pakai regex `xit\(` untuk mencari skip — false-positive pada `process.exit(`.

## 6. Sumber referensi

- `plan/plan-process-m1-wave2-outgoing-idempotency-v1.0.md` — TASK-011..TASK-014 (§2 Phase 3), CON-006, CON-013, TEST-004, TEST-010.
- `spec/spec-process-m1-wave2-outgoing-idempotency.md` v1.1 — REQ-033..REQ-038, AC-033..AC-038, D-06/D-07, A-6/A-8a/A-8b/A-8c, §2 (enum reason), §12 Kasus 6-7.
- `docs/decisions/2026-09-24-m1-wave2-eksekusi-fase2.md` — hasil Fase 2, penyimpangan P-8..P-12, TASK-010 APPROVED.
- `docs/decisions/2026-09-24-m1-wave2-eksekusi-fase1.md` — hasil Fase 1, penyimpangan P-1..P-7, temuan T-1 (5 skrip gelombang 1 tanpa `SQLITE_PATH` temp).
- `docs/audit/clarification-report-m1-wave2-outgoing-idempotency-plan-2026-09-24.md` — Readiness 82/100, K-04 (kalibrasi nilai), K-10 (larangan klaim bukti).


