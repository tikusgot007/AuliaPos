---
goal: Perbaikan hasil code review M1 Gelombang 1 — start SQLite yang aman, drain overflow mandiri, dan kebersihan uji WA-Gateway
version: 1.0
date_created: 2026-09-23
last_updated: 2026-09-23
owner: WA-Gateway reliability (M1)
status: "Planned"
tags: ["refactor", "clean-code", "architecture", "security", "gateway", "m1", "reliability"]
---

# Introduction

![Status: Planned](https://img.shields.io/badge/status-Planned-blue)

Plan ini menindaklanjuti `docs/audit/code-review-m1-wave1-2026-09-23.md` (review `e18f716..065f683`, 0 P0, 1 P1, 14 P2).
Semua kode ada di repo **WA-Gateway**, worktree `C:\projects\WA-Gateway-m1`, branch `feature/stage-1-reliability`. Commit baru ditumpuk
di atas `065f683` **sebelum** PR `feature/stage-1-reliability` → `master` dibuat.

- **Fase 1 (P1, CR-01):** saat start, database SQLite yang sehat tetapi sedang terkunci tidak lagi dianggap korup. Kegagalan membuka SQLite tidak lagi
  pindah diam-diam ke buffer JSON. Ini menutup jalur kehilangan pesan senyap yang terbukti lewat reproduksi.
- **Fase 2 (P2 wajib):** drain overflow tidak lagi tertahan oleh siklus kirim CI4 (CR-02), batas bawah `OWN_SENT_TTL_MS` (CR-04), sisa E-05 (CR-13),
  pembersihan tiga skrip uji lama yang menulis ke DB non-sementara (CR-03), penghapusan salinan decision log basi (CR-14), dan tiga asersi AC yang kurang (CR-16).

Di luar plan ini (backlog gelombang berikutnya, sesuai RISK-004/005 plan induk): CR-05 (stall event loop / `timeout` better-sqlite3),
CR-06 (penguatan guard statis), CR-07 (alasan error di drain/`_tryLoadFrom`), CR-08 (batas `_lidFailureCache`), CR-09 (O(n) `_persist` JSON),
CR-10 (ekstraksi `LidResolver`/`IncomingPersistence`), CR-11 (duplikasi), dan CR-15 (skrip pembanding payload AC-014).

## 1. Traceability: Requirements & Constraints

- **SEC-001** (CR-01, P1): saat start, karantina `.corrupt-<waktu>` MUST hanya terjadi bila `PRAGMA quick_check` mengembalikan selain `ok`, atau error berkode
  `SQLITE_CORRUPT`/`SQLITE_NOTADB`. Error lain (mis. `SQLITE_BUSY`, `EPERM`, `EBUSY`) MUST dilempar ulang tanpa memindah berkas apa pun. Ini mempersempit tafsir "pemeriksaan gagal" pada REQ-013 plan induk.
- **SEC-002** (CR-01, P1, GUD-002): fallback ke buffer JSON MUST hanya terjadi bila `require('better-sqlite3')` gagal. Bila modul ada tetapi constructor
  `IncomingBufferSqlite` gagal, Gateway MUST mencatat `logger.error('[CRITICAL] …')` lalu melempar ulang, sehingga proses berhenti dan PM2 menyalakannya ulang. Gateway MUST NOT diam-diam menulis ke `gateway.json`.
- **PRN-001** (CR-02): pengurasan overflow (penyimpanan lokal) MUST NOT bergantung pada selesainya siklus kirim ke CI4. `drain()` dijalankan di setiap tick, sebelum cek `isRunning`.
- **REQ-001** (CR-04): `OWN_SENT_TTL_MS` MUST dibatasi minimal 1 (pola sama dengan `OWN_SENT_MAX` / `ENQUEUE_OVERFLOW_MAX`), supaya nilai `≤ 0` tidak mematikan filter kiriman sendiri (D-01).
- **REQ-002** (CR-13): `_resolveLidForPhoneJid()` MUST NOT menulis `null` ke `_lidResolutionCache` saat Gateway belum `connected`, dan MUST mengembalikan `null` untuk pesan itu saja.
- **PRN-002** (CR-03): semua skrip uji MUST memakai folder sementara (spec induk §6). Skrip lama yang redundan dihapus hanya setelah setiap skenarionya terbukti punya padanan.
- **PRN-003** (CR-14): repo WA-Gateway MUST NOT menyimpan salinan decision log AuliaPos. Sumber tunggalnya repo AuliaPos.
- **PRN-004** (CR-16): AC-003, AC-007, dan AC-008 MUST punya asersi eksplisit untuk bagian yang belum terkunci.
- **CON-001**: kontrak HTTP ke AuliaPos (`POST /api/inbox/gateway/messages`) MUST tidak berubah (CON-001 induk).
- **CON-002**: tanpa perubahan skema `incoming_queue`, tanpa dependensi npm baru (CON-002/CON-004 induk).
- **CON-003**: semua kerja hanya di worktree `C:\projects\WA-Gateway-m1`. MUST NOT menyentuh `C:\projects\WA-Gateway` (folder live), `auth/`, maupun PM2. **Tidak ada deploy di plan ini.**
- **CON-004**: 12 skrip uji yang ada MUST tetap lulus, kecuali tiga skrip yang dihapus secara sah lewat TASK-206. Tidak boleh ada asersi yang dilemahkan.

## 2. Implementation Steps

> **⚠️ EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> You MUST execute this plan phase by phase. You MUST run the specific testing/verification task at the end of each phase. After a phase is tested, you **MUST STOP AND WAIT** for the user's explicit approval before proceeding to the next phase. **DO NOT SKIP PHASES.**
> Kerja hanya di `C:\projects\WA-Gateway-m1`. Setiap uji MUST memakai `SQLITE_PATH` di folder sementara dan `CI4_BASE_URL`/`CI4_GATEWAY_TOKEN` kosong. Satu task = satu commit.

### Implementation Phase 1: Start SQLite yang Aman (P1)

- **GOAL-001:** database sehat yang sedang terkunci tidak pernah dikarantina, dan kegagalan membuka SQLite terlihat keras (proses berhenti), bukan pindah diam-diam ke JSON.

| Task ID  | Description (Include Exact File Paths & Micro-Testing) | Ref ID | Completed | Date |
| -------- | ------------------------------------------------------ | ------ | :-------: | :--: |
| TASK-101 | `src/store/incomingBuffer.js` `checkIntegrity()` (lines 79-93): kembalikan `{ healthy: false }` **hanya** bila `quick_check` ≠ `'ok'` atau `err.code` ∈ {`SQLITE_CORRUPT`, `SQLITE_NOTADB`}. Error lain dilempar ulang dari `checkIntegrity()`, sehingga `openVerifiedDatabase()` tidak memindah berkas apa pun. Perbarui komentar blok (lines 67-78) agar menyebut tafsir ini. | SEC-001 | [ ] | |
| TASK-102 | `src/store/incomingBuffer.js` singleton (lines 584-593): pisahkan menjadi dua langkah. (1) `try { Database = require('better-sqlite3') } catch → warn + JSON fallback` (perilaku lama untuk modul tidak ada). (2) `try { instance = new IncomingBufferSqlite(Database) } catch (err) → logger.error('[CRITICAL] gagal membuka database SQLite incoming buffer -- Gateway berhenti, TIDAK pindah ke JSON', { severity: 'critical', path, error: err.message, code: err.code }); throw err;`. Pesan log `warn` lama hanya tersisa di langkah (1). | SEC-002 | [ ] | |
| TASK-103 | Micro-Test di `test/simulate-durable-buffer.js`, 3 skenario baru (pola `mkdtemp` yang sudah ada): (a) DB sehat berisi 1 baris pending + koneksi lain memegang `locking_mode=EXCLUSIVE` + `BEGIN EXCLUSIVE` → `new IncomingBufferSqlite(Database, path)` **melempar**, tidak ada berkas `.corrupt-*`, dan setelah kunci dilepas instance baru melihat `countPending() === 1`. (b) Berkas bukan database (skenario AC-011 lama) → tetap dikarantina (regresi). (c) Singleton: jalankan proses anak (`execFileSync node -e`) dengan `SQLITE_PATH` menunjuk DB terkunci → exit non-zero, stderr/log memuat `[CRITICAL]`, dan **tidak ada** berkas `.json` dibuat di folder itu. Catatan: pada (a) set opsi `timeout` kecil hanya di sisi uji bila perlu mempercepat. Source tidak diubah untuk itu (CR-05 di luar scope). | SEC-001, SEC-002 | [ ] | |
| TASK-104 | **VERIFY**: di `C:\projects\WA-Gateway-m1`, jalankan `node test/simulate-durable-buffer.js` (termasuk 3 skenario baru) lalu seluruh skrip regresi: `simulate-enqueue-integrity`, `simulate-append-handling`, `simulate-lid-timeout`, `simulate-error-isolation`, `simulate-json-recovery`, `simulate-own-sent-registry`, `simulate-register-order-guard`, `check-register-before-send`. Semua exit 0. **Uji mutasi manual:** kembalikan sementara `catch → healthy:false` di `checkIntegrity` → skenario (a) MUST gagal; pulihkan berkas (hash sama). `git status` hanya berisi perubahan task Fase 1. | - | [ ] | |
| TASK-105 | **APPROVAL**: 🛑 Wait for explicit user confirmation to proceed to Phase 2 | - | [ ] | |

### Implementation Phase 2: Kopling Drain, Batas Env, Sisa E-05, dan Kebersihan Uji (P2)

- **GOAL-002:** menutup temuan P2 wajib tanpa mengubah perilaku yang sudah lulus AC-001..AC-018.

| Task ID  | Description (Include Exact File Paths & Micro-Testing) | Ref ID | Completed | Date |
| -------- | ------------------------------------------------------ | ------ | :-------: | :--: |
| TASK-201 | `src/delivery/incomingDelivery.js` `tick()` (lines 69-79): pindahkan `overflowBuffer.drain((event) => incomingBuffer.enqueue(event))` ke baris pertama `tick()`, **sebelum** `if (isRunning) return;`. Bungkus dengan `try/catch` sendiri yang mencatat `logger.error` (drain sinkron, tidak boleh menjatuhkan tick). Micro-Test di `test/simulate-durable-buffer.js`: panggil `tick()` saat siklus sebelumnya masih berjalan (`deliverOne` di-stub menggantung) → overflow tetap terkuras ke buffer utama. | PRN-001 | [ ] | |
| TASK-202 | `src/config/index.js` line 102: `ownSentTtlMs: Math.max(1, toInt(process.env.OWN_SENT_TTL_MS, 600000))` + komentar "min 1: ≤0 mematikan filter kiriman sendiri". Micro-Test di `test/simulate-own-sent-registry.js` (#6 sudah membaca config lewat proses anak): `OWN_SENT_TTL_MS=0` dan `-5` → `ownSentTtlMs === 1`. | REQ-001 | [ ] | |
| TASK-203 | `src/whatsapp/connectionManager.js` `_resolveLidForPhoneJid()` (lines 544, 583): bila `!this.isConnected()`, `return null` **tanpa** `_lidResolutionCache.set`. Jalur sukses (cache permanen) dan jalur gagal (cache negatif 60 s) tidak berubah. Micro-Test di `test/simulate-lid-timeout.js` skenario "Belum connected": tambah asersi `_lidResolutionCache.has(jid) === false`, lalu setelah status `connected` panggilan berikutnya **memanggil** `onWhatsApp()` sekali. | REQ-002 | [ ] | |
| TASK-204 | Tambah asersi (tanpa mengubah source): `test/simulate-append-handling.js` #6 (AC-003): body POST ke CI4 untuk baris `outgoing` itu (stub `postToCI4`) tidak memuat field identitas staff. Cek dulu nama field aktual di `deliverOne`/`ci4Client.js` saat eksekusi, lalu asersikan ketiadaannya. Bila kontrak memang tidak punya field seperti itu, asersikan daftar key body sama dengan pesan `incoming`, kecuali `direction`. `test/simulate-durable-buffer.js`: AC-007 lewat `_persistIncoming()` (gagal 2× lalu berhasil → `overflowBuffer.size() === 0`), dan AC-008 (event masuk overflow → tepat satu log `error` "GAGAL menyimpan pesan ke buffer utama"). | PRN-004 | [ ] | |
| TASK-205 | Pemetaan cakupan sebelum penghapusan: untuk tiap skenario di `test/simulate-enqueue-failure.js`, `test/simulate-e05-lid-timeout.js`, dan `test/simulate-e09-json-recovery.js`, tulis tabel "skenario lama → skenario padanan (berkas + nomor)" di pesan commit TASK-206. Skenario tanpa padanan MUST dipindah lebih dulu ke skrip baru yang sesuai dengan pola `mkdtemp` + `SQLITE_PATH` sementara + `CI4_*` kosong + `rmSync` di `finally`. | PRN-002 | [ ] | |
| TASK-206 | Hapus `test/simulate-enqueue-failure.js`, `test/simulate-e05-lid-timeout.js`, `test/simulate-e09-json-recovery.js` (`git rm`), dengan tabel TASK-205 di pesan commit. Hapus juga sisa lokal `data/test-e09-buffer.sqlite` di worktree (berkas ter-ignore, bukan bagian commit). | PRN-002 | [ ] | |
| TASK-207 | `git rm docs/decisions/2026-09-21-m1-ticket01-baseline.md` di repo WA-Gateway. Pesan commit menyebut sumber tunggalnya: repo AuliaPos `docs/decisions/2026-09-21-m1-ticket01-baseline.md`. Jangan menyentuh salinan AuliaPos. | PRN-003 | [ ] | |
| TASK-208 | **VERIFY**: jalankan seluruh skrip `test/simulate-*.js` + `test/check-register-before-send.js` yang tersisa di worktree (9 skrip), semua exit 0. Periksa: (1) `git diff --stat 065f683..HEAD -- src` hanya menyentuh `incomingBuffer.js`, `incomingDelivery.js`, `config/index.js`, `connectionManager.js`; (2) `git diff 065f683..HEAD -- src/delivery/ci4Client.js` kosong dan fungsi `deliverOne` tidak berubah (CON-001); (3) `package.json`/`package-lock.json` tidak berubah (CON-002); (4) `grep -rn "eslint-disable\|\.skip\|xit(" test/` tidak menambah hasil baru; (5) setelah uji, `git status --short` hanya berisi commit plan ini dan tidak ada berkas sementara di `data/`. | - | [ ] | |
| TASK-209 | **APPROVAL**: 🛑 Wait for explicit user confirmation. Setelah disetujui, handoff: pembuatan PR `feature/stage-1-reliability` → `master` (keputusan user). Deploy ke folder live **bukan** bagian plan ini. | - | [ ] | |

## 3. Structural Remedies & Alternatives

- **ALT-001 (ditolak):** tetap fallback ke JSON saat constructor SQLite gagal, tetapi dengan log `error`. Ditolak karena pesan yang masuk ke `gateway.json` tidak pernah dibaca lagi setelah SQLite normal kembali (tidak ada migrasi JSON→SQLite), sehingga kehilangan pesan tetap terjadi, hanya kini tercatat. Berhenti keras + restart PM2 tidak kehilangan apa pun, karena pesan yang belum di-ack akan dikirim ulang WhatsApp sebagai `append`, jalur yang sudah terbukti di AC-001.
- **ALT-002 (ditolak):** menunggu/mencoba ulang `quick_check` beberapa kali saat `SQLITE_BUSY`. Ditolak karena menambah kompleksitas. Restart oleh PM2 sudah menjadi retry alami.
- **ALT-003 (ditunda, CR-05):** opsi `timeout` kecil pada `new Database()` untuk memperpendek blokir sinkron 5 s. Ditunda ke backlog supaya perubahan Fase 1 minimal.
- **ALT-004 (ditolak):** memindahkan tiga skrip lama ke `mkdtemp` alih-alih menghapusnya. Ditolak karena skenarionya sudah tercakup (redundan). Pemindahan hanya berlaku untuk skenario tanpa padanan (TASK-205).
- **Structural remedy:** pemisahan "modul tidak ada" (boleh degradasi) dari "database gagal dibuka" (harus terlihat keras). Satu `catch` untuk dua kondisi berbeda adalah akar CR-01.

## 4. Dependencies

- **DEP-001:** tidak ada dependensi npm baru. `better-sqlite3` yang sudah terpasang dipakai untuk kode `err.code` (`SQLITE_CORRUPT`, `SQLITE_NOTADB`, `SQLITE_BUSY`).
- **DEP-002:** `C:\projects\WA-Gateway-m1\node_modules` sudah ada (Node v20.20.2, sama dengan PM2).
- **DEP-003:** `docs/audit/code-review-m1-wave1-2026-09-23.md` (repo AuliaPos): sumber CR-01..CR-18.

## 5. Files Affected

- **FILE-001:** `src/store/incomingBuffer.js` — `checkIntegrity()` hanya karantina untuk korupsi nyata. Singleton memisahkan `require` dari constructor (TASK-101, TASK-102).
- **FILE-002:** `src/delivery/incomingDelivery.js` — `drain()` sebelum cek `isRunning` (TASK-201).
- **FILE-003:** `src/config/index.js` — batas bawah `ownSentTtlMs` (TASK-202).
- **FILE-004:** `src/whatsapp/connectionManager.js` — tidak meng-cache `null` saat belum `connected` (TASK-203).
- **FILE-005:** `test/simulate-durable-buffer.js`, `test/simulate-own-sent-registry.js`, `test/simulate-lid-timeout.js`, `test/simulate-append-handling.js` — skenario dan asersi baru (TASK-103, 201-204).
- **FILE-006 (dihapus):** `test/simulate-enqueue-failure.js`, `test/simulate-e05-lid-timeout.js`, `test/simulate-e09-json-recovery.js` (TASK-206).
- **FILE-007 (dihapus):** WA-Gateway `docs/decisions/2026-09-21-m1-ticket01-baseline.md` (TASK-207).

## 6. Testing Strategy

- **TEST-001 (SEC-001):** DB sehat terkunci saat start → tidak ada `.corrupt-*`, baris pending tetap ada setelah kunci dilepas.
- **TEST-002 (SEC-001 regresi):** berkas bukan database → tetap dikarantina bersama `-wal`/`-shm` (AC-011 lama).
- **TEST-003 (SEC-002):** proses anak dengan DB terkunci → exit non-zero, log `[CRITICAL]`, tidak ada `gateway.json` baru.
- **TEST-004 (PRN-001):** `tick()` saat `isRunning` → overflow tetap terkuras.
- **TEST-005 (REQ-001):** `OWN_SENT_TTL_MS` `0`/`-5` → `1`.
- **TEST-006 (REQ-002):** belum `connected` → tidak ada entri cache. Setelah `connected`, query dijalankan.
- **TEST-007 (PRN-004):** asersi AC-003 (tanpa identitas staff), AC-007 via `_persistIncoming`, AC-008 (log error).
- **TEST-008 (regresi):** 9 skrip yang tersisa lulus, dan setiap uji mutasi di TASK-104 tertangkap.
- **Tidak termasuk:** uji nyata `pm2 stop/start` (AC-001 tidak diulang, karena kode jalur penerimaan normal tidak berubah) dan uji di folder live.

## 7. Risks & Rollback Plan

- **RISK-001 (perilaku start berubah):** setelah SEC-002, DB yang terkunci saat start membuat Gateway **gagal start** dan PM2 mengulang restart sampai kunci lepas, alih-alih jalan di mode JSON. Ini disengaja (lebih baik mati terlihat daripada hidup kehilangan pesan). Mitigasi: log `[CRITICAL]` menyebut path dan kode error. Selama PM2 restart, pesan tertunda dikirim ulang WhatsApp sebagai `append` (terbukti AC-001).
- **RISK-002 (daftar kode error SQLite kurang lengkap):** bila korupsi nyata muncul dengan kode lain, Gateway berhenti keras alih-alih mengkarantina. Arah salahnya aman (tidak ada data yang dipindah diam-diam), dan terlihat di log `[CRITICAL]`.
- **RISK-003 (penghapusan uji menghilangkan cakupan):** dimitigasi TASK-205 (tabel pemetaan wajib) dan CON-004.
- **RISK-004 (scope creep):** temuan backlog (CR-05..CR-11, CR-15) MUST NOT diselundupkan ke plan ini.
- **Rollback:** setiap task satu commit di atas `065f683`. Rollback = `git revert <commit>` di worktree (bukan `reset --hard`). Plan ini tidak menyentuh folder live, sehingga tidak ada rollback produksi. Bila kelak ter-deploy, rollback mengikuti plan induk §9 (`reset --hard` ke HEAD sebelumnya di folder live + `pm2 restart`).
