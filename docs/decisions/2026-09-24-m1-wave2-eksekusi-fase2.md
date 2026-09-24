# M1 Gelombang 2 — Eksekusi Fase 2: Lease, Batas Percobaan & Tugas Start-up (2026-09-24)

> **Catatan pembaca:** dokumen ini mencatat eksekusi **Fase 2 (TASK-007..TASK-010)**
> dari `plan/plan-process-m1-wave2-outgoing-idempotency-v1.0.md` (v1.0, commit `57de122`)
> lewat `/sdlc-write-code`, mengikuti `spec/spec-process-m1-wave2-outgoing-idempotency.md`
> (v1.1). Kode berada di repo **WA-Gateway** (worktree `C:\projects\WA-Gateway-m1w2`,
> branch `feature/m1-wave2-outgoing-idempotency`); dokumen ini disimpan di repo AuliaPos
> sesuai plan (FILE-013). **Tidak ada kode AuliaPos yang diubah** di Fase 2.
> Log Fase 1 (`docs/decisions/2026-09-24-m1-wave2-eksekusi-fase1.md`) **TIDAK** ditimpa.
>
> **Legenda bukti:** seluruh bukti di sini adalah **Simulasi** (server express nyata,
> Baileys di-stub) — bukan pengukuran dengan koneksi WhatsApp nyata. Bukti nyata
> (AC-027/AC-042) baru ada di Fase 5 (TASK-023). Kode Fase 2 **belum ter-deploy**:
> folder live `C:\projects\WA-Gateway` tetap `21a4cb6`.

**Status:** TASK-007, TASK-008, TASK-009 **selesai dan terverifikasi**. **TASK-010
(APPROVAL) menunggu keputusan pemilik**; Fase 3 (TASK-011..) belum dimulai. Front-matter
plan **tidak** dinaikkan (baru boleh di TASK-024); kolom `Completed`/`Date` untuk
TASK-007..TASK-009 sudah diisi.

## 1. Kondisi awal yang diverifikasi (jangan diasumsikan)

| Butir | Hasil pengukuran (2026-09-24) |
| --- | --- |
| Worktree `C:\projects\WA-Gateway-m1w2` | Ada; `git worktree list` menampilkan folder live `21a4cb6 [master]` + worktree `[feature/m1-wave2-outgoing-idempotency]` |
| HEAD worktree sebelum Fase 2 | `5a48311` (4 commit Fase 1 di atas `21a4cb6`), `git status --short` kosong |
| Folder live `C:\projects\WA-Gateway` | `21a4cb6`, `master`, `status --short` kosong; `auth/` dan `data/` tidak disentuh |
| Titik rollback | `21a4cb6` (tidak berubah dari Fase 1) |

## 2. Ringkasan commit per task (repo WA-Gateway, branch `feature/m1-wave2-outgoing-idempotency`)

| Task | Commit | Isi |
| --- | --- | --- |
| TASK-007 | `62e92c2` | `src/delivery/outgoingOperationService.js`: `isLeaseExpired()`, `classifyExisting()` (reused/replay/in_progress/retry/dead_lettered), `deadLetter()`, `isReady()` sebelum `begin()` **dan** sebelum kirim ulang, cabang `dead_lettered` -> `502 DEAD_LETTERED`; `test/simulate-outgoing-recovery.js` (baru: AC-026/028/029/030/042 + SEC-001) |
| TASK-008 | `e0f5585` | `runStartupRecovery()` di service (REQ-031/REQ-032) + pemanggilannya di `src/app/index.js`; uji start-up AC-031/032/043 ditambahkan ke `test/simulate-outgoing-recovery.js` |
| TASK-009 | — (tanpa commit) | VERIFY: tidak ada berkas baru; seluruh ujinya dijalankan dan hasilnya dicatat di §3 |
| TASK-010 | — | APPROVAL, menunggu pemilik |

Total `21a4cb6..HEAD`: 6 commit (4 Fase 1 + 2 Fase 2). Berkas produksi yang berubah di
Fase 2: `src/delivery/outgoingOperationService.js` dan `src/app/index.js` (satu baris
panggilan + require). `src/api/ci4Routes.js` **tidak** perlu berubah — matriks respons
(termasuk `502 DEAD_LETTERED`) sudah sepenuhnya dihasilkan `toHttpResponse()`.

## 3. Bukti TASK-009 (VERIFY, dijalankan di worktree, Node v20.20.2)

| Butir | Hasil |
| --- | --- |
| `node test/simulate-outgoing-recovery.js` | **0 gagal**. AC-026 (a sukses `200 sent`; b `INVALID_CHAT_ID` `500 failed` **STUB-ONLY**; c error jaringan `504 SEND_UNRESOLVED`), AC-028 (30 dtk -> `409 SEND_IN_PROGRESS` tanpa ubah `attempts`; 40 dtk -> retry, `attempts` 1->2), AC-029 (`sendReply` tepat 5x, permintaan ke-6 -> `abandoned` + `502` + `[CRITICAL]` + `dead_lettered_at`, tanpa kirim), AC-030 (`NOT_CONNECTED` tanpa baris; retry pasca-lease `NOT_CONNECTED` tidak menghabiskan `attempts`), AC-031 (1 baris basi dicatat; 25 baris -> `jumlah=25`, daftar id dibatasi 20), AC-032 (`sent` 25 jam dipangkas, `in_flight` 25 jam tetap), AC-043 (`abandoned` dicatat `[CRITICAL]` lalu dipangkas; `operation_id` sama -> operasi BARU), AC-042 sebagai **stub** (nyata di TASK-023) |
| Regresi kumulatif (CON-013) | Seluruh `test/*.js` (**21 skrip**) dijalankan satu per satu dengan `SQLITE_PATH` dipaksa ke folder temp: **21/21 exit=0**. Termasuk Fase 1 (`simulate-outgoing-idempotency/order-guard/store`) dan gelombang 1 |
| Guard statis | `node test/check-outgoing-begin-before-send.js` -> `OK`; uji-dirinya `simulate-outgoing-order-guard.js` -> `SEMUA ASSERT LULUS` (mutasi pada baris `begin()` yang dipertahankan apa adanya tetap terdeteksi) |
| Pemindaian log AC-039 (diulang) | `node test/check-outgoing-log-scan.js` -> `OK`: berkas log 55 baris (9 level error) dipindai, **0 kebocoran** isi pesan/`media_base64`/isi media |
| Konfirmasi SQLite temp (TEST-010) | `node test/check-test-sqlite-isolation.js --expect-no-data-dir` -> `OK`: **6 skrip Fase 1** terisolasi (bertambah 1: `simulate-outgoing-recovery.js`); `data/gateway.sqlite` **tidak ada** di worktree. Temuan T-1 (5 skrip gelombang 1 tanpa `SQLITE_PATH` temp) tetap dicatat, tidak diubah; regresi di atas dijalankan dengan `SQLITE_PATH` temp |
| Tidak ada suppression/skip | Tidak ada `@ts-ignore`, `eslint-disable`, `.skip`, atau assertion yang dihapus (Floor-Guard) |

## 4. Keputusan implementasi & penyimpangan yang dideklarasikan

Semua di bawah ini berada dalam Fase 2, mudah dibalik, dan tidak menambah REQ/AC.

- **P-8 — Logika start-up TASK-008 ditaruh di `outgoingOperationService.runStartupRecovery()`,
  bukan inline di `src/app/index.js`.** Alasan: `src/app/index.js` memanggil `main()`
  saat di-require, sehingga logika inline **tidak bisa diuji** — padahal plan mewajibkan
  uji start-up di `test/simulate-outgoing-recovery.js` (AC-031/032/043). `src/app/index.js`
  tetap memanggil satu fungsi itu (satu baris) setelah `heartbeat.start()`. Fungsi sengaja
  **tidak melempar** agar start tidak terblokir (REQ-031).
- **P-9 — Retry pasca-lease juga memeriksa `isReady()` sebelum `registerRetry()`.** REQ-030
  hanya menyebut operasi BARU. Tanpa pemeriksaan ini, retry saat WhatsApp mati akan
  menaikkan `attempts` tanpa kiriman yang benar-benar dijalankan, melanggar arti
  `attempts` (REQ-029/D-11: jumlah kiriman yang dijalankan). Hasilnya `409 NOT_CONNECTED`
  dengan `attempts` tetap.
- **P-10 — TASK-007 tidak mengubah `src/api/ci4Routes.js`.** Plan mencatat dua berkas, tetapi
  seluruh matriks respons sudah diproduksi `toHttpResponse()` di service; menambah
  perubahan di router hanya menambah permukaan risiko dan berpotensi merusak guard statis.
  `isConnected()` sudah berada sebelum `begin()` di service (REQ-030).
- **P-11 — `dead_lettered` (cap tercapai) memakai `replayed:true` pada `502`.** Spec §4.3
  hanya punya satu baris untuk `abandoned` (`replayed:true`); nilai ini dipakai konsisten
  untuk cap-pertama maupun replay, mengikuti pola P-3 (`409 SEND_IN_PROGRESS`).
- **P-12 — `MAX_LISTED_IDS = 20` didefinisikan ulang di service** (store juga punya konstanta
  serupa) alih-alih mengekspor dari store, supaya diff store tetap kecil seperti Fase 1.

> [!NOTE] Kebersihan operasional. Saat menyiapkan sesi ini, satu perintah smoke-test
> (`node -e "require('./src/delivery/outgoingOperationService')"`) tanpa `SQLITE_PATH`
> sempat membuat `data/gateway.sqlite` di worktree; folder `data/` **langsung dihapus**
> dan diverifikasi tidak ada (`check-test-sqlite-isolation.js --expect-no-data-dir` -> `OK`).
> Tidak ada berkas `data/` yang tersisa maupun ter-commit; `auth/` tidak pernah disentuh.

## 5. Batas jujur (tidak boleh diklaim tertutup)

- **AC-026(b)** (`INVALID_CHAT_ID` -> `failed`/`500`) tetap **STUB-ONLY** (A-2/RISK-006):
  pada lalu lintas normal `isDecodableJid()` menolak JID itu dengan `400` sebelum
  `sendMessage()`. Output uji dan dokumen ini menandainya `stub-only`; **tidak** dipakai
  sebagai bukti perilaku produksi.
- **AC-042** hanya dibuktikan sebagai state machine di Node (stub). Pengukuran **nyata**
  (kasir lewat AuliaPos, retry setelah lease) ada di **TASK-023** (Fase 5).
- **AC-027** belum diukur sama sekali (butuh prosedur nyata Fase 5). **GW-09 belum tertutup.**
- **ASSUMPTION-009** (jendela crash: terkirim tapi belum tercatat `sent`) tetap terbuka;
  dipersempit lewat log `error` operasi `in_flight` basi saat start, **tidak** dihilangkan.
- **Paritas fallback JSON (ASSUMPTION-007)** masih diuji hanya di Node (suite store yang
  sama, `simulate-outgoing-store.js`), belum di Android nyata (RISK-003 ii).
- **D-13/A-5** (jaminan idempotensi <= `OUTGOING_OPERATION_TTL_MS` = 24 jam) kini punya
  bukti perilaku (AC-043): setelah dipangkas, `operation_id` yang sama menjadi operasi baru.
- **Kode Fase 2 belum ter-deploy**; folder live tetap `21a4cb6`, jadi tidak ada perubahan
  perilaku pada Gateway yang sedang berjalan.

## 6. TASK-010 — APPROVAL (disetujui)

**Disetujui pemilik pada 2026-09-24** ("setuju") dalam sesi yang sama, setelah checkpoint Fase 2 disimpan
(commit memori `e85c9a6`). Fase 2 **ditutup**. Fase 3 (TASK-011..TASK-014: attempt counter, dead-letter
`incoming_queue`, klasifikasi `postToCI4`) dikerjakan di **sesi baru** dengan `/sdlc-write-code`; brief +
prompt siap paste ada di `docs/handoff-m1-wave2-fase3-write-code-2026-09-24.md`. Fase 2 **tidak** menyentuh
kode AuliaPos, sehingga branch AuliaPos `feature/m1-wave2-outgoing-idempotency` (TASK-015) belum perlu dibuat.

Batas yang **tidak** berubah oleh approval ini: kode Fase 1-2 belum ter-deploy (folder live tetap `21a4cb6`),
GW-09 belum tertutup (AC-027/AC-042 nyata baru di Fase 5), dan TASK-022/024 tetap gate DEPLOY/APPROVAL.


