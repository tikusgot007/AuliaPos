# M1 Gelombang 2 — Eksekusi Fase 1: Tracer Bullet Idempotensi Kirim Teks/Media (2026-09-24)

> **Catatan pembaca:** dokumen ini mencatat eksekusi **Fase 1 (TASK-001..TASK-006)**
> dari `plan/plan-process-m1-wave2-outgoing-idempotency-v1.0.md` (v1.0, commit `57de122`)
> lewat `/sdlc-write-code`, mengikuti `spec/spec-process-m1-wave2-outgoing-idempotency.md`
> (v1.1). Kode ada di repo **WA-Gateway** (worktree `C:\projects\WA-Gateway-m1w2`,
> branch `feature/m1-wave2-outgoing-idempotency`); dokumen ini disimpan di repo AuliaPos
> sesuai plan (FILE-013). **Tidak ada kode AuliaPos yang diubah** di Fase 1.
>
> **Legenda bukti:** seluruh bukti di sini adalah **Simulasi** (server express nyata,
> Baileys di-stub) — bukan pengukuran dengan koneksi WhatsApp nyata. Bukti nyata
> (AC-027/AC-042) baru ada di Fase 5 (TASK-023).

**Status:** TASK-001..TASK-005 selesai dan terverifikasi. **TASK-006 (APPROVAL) disetujui
pemilik pada 2026-09-24** ("setuju, lanjut ke Fase 2"). Front-matter plan **tidak** dinaikkan
(baru boleh di TASK-024). Fase 2 dikerjakan di sesi terpisah (lihat
`docs/handoff-m1-wave2-fase2-write-code-2026-09-24.md`); belum ada perubahan kode Fase 2.

## 1. TASK-001 — Lingkungan kerja & titik rollback

| Item | Nilai |
| --- | --- |
| **Titik rollback Gateway** | **`21a4cb6`** (`master`, "Merge pull request #4 … feature/stage-1-reliability") |
| Perintah | `git -C C:\projects\WA-Gateway worktree add C:\projects\WA-Gateway-m1w2 -b feature/m1-wave2-outgoing-idempotency 21a4cb6` |
| Kondisi sebelum (RISK-001) | HEAD `21a4cb6`, hanya branch `master`/`origin/master`, worktree tunggal, `status --short` kosong |
| Kondisi sesudah | `git worktree list`: folder live `21a4cb6 [master]` + worktree baru `[feature/m1-wave2-outgoing-idempotency]` |
| Folder live | `status --short` kosong, HEAD tetap `21a4cb6`; tidak ada `checkout`/`reset`; `auth/` dan `data/` tidak disentuh |
| Toolchain | Node `v20.20.2`; `npm ci` sukses di worktree; `better-sqlite3` jalan (SQLite 3.49.2) |

Rollback Fase 1 (tidak ada yang ter-deploy): cukup tidak melakukan merge. Bila worktree
ingin dibuang: `git -C C:\projects\WA-Gateway worktree remove C:\projects\WA-Gateway-m1w2`
lalu hapus branch. Folder live tidak pernah berubah, jadi tidak ada yang perlu dipulihkan.

## 2. Ringkasan commit per task (repo WA-Gateway, branch `feature/m1-wave2-outgoing-idempotency`)

| Task | Commit | Isi |
| --- | --- | --- |
| TASK-001 | — (tanpa commit) | worktree + branch + `npm ci` |
| TASK-002 | `55a1ae1` | enam env var (`config/index.js`), `store/outgoingOperations.js` (SQLite + fallback JSON, API spec §4.1), `test/simulate-outgoing-store.js` |
| TASK-003 | `6fe151a` | `delivery/outgoingOperationService.js`, wiring `POST /send`, `test/simulate-outgoing-idempotency.js` (jalur teks) |
| TASK-004 | `bdbf534` | wiring `POST /send-media` (fingerprint dari konten hasil decode), cabang media di uji idempotensi |
| TASK-005 | `5a48311` | guard statis + uji-dirinya, pemindaian berkas log, pemeriksa isolasi DB |
| TASK-006 | — | APPROVAL, menunggu pemilik |

Total `21a4cb6..HEAD`: 4 commit, 10 berkas, `+2268 / −24`. Berkas produksi yang berubah hanya
`src/api/ci4Routes.js` (dua handler kirim), `src/config/index.js`, dan dua berkas baru
(`src/store/outgoingOperations.js`, `src/delivery/outgoingOperationService.js`).

## 3. Bukti TASK-005 (dijalankan di worktree, Node v20.20.2)

| Butir | Hasil |
| --- | --- |
| `node test/simulate-outgoing-store.js` | **0 gagal** — suite yang sama dijalankan pada SQLite **dan** fallback JSON, skema 13 kolom sesuai spec §4.4, enam env var (bawaan/clamp/tidak valid) |
| `node test/simulate-outgoing-idempotency.js` | **0 gagal** — AC-019, AC-020, AC-021, AC-022, AC-023 (restart = muat ulang modul di atas berkas SQLite yang sama), AC-024, AC-025/AC-044; jalur teks **dan** media |
| (a) Guard statis begin() sebelum kirim | `node test/check-outgoing-begin-before-send.js` → `OK`: `begin()` mendahului `await send()` di `runOperation()`; `/send` dan `/send-media` hanya memanggil Baileys lewat closure `doSend` di dalam service. Uji-diri (`simulate-outgoing-order-guard.js`) menyuntikkan 11 mutasi (begin sesudah send, hapus `runOperation`, panggilan langsung `sendReply`/`sendMediaReply`, hapus validasi/warn, handler hilang) → semuanya terdeteksi; CLI exit 1 pada salinan bermutasi |
| (b) `sendMessage` tidak terpanggil pada replay / `OPERATION_ID_REUSED` / `INVALID_OPERATION_ID` | Terbukti pada lapisan stub `connectionManager.sendReply`/`sendMediaReply` (jumlah panggilan tetap). Ini seam #2 spec §6; keduanya satu-satunya jalan ke `sock.sendMessage` |
| (c) Pemindaian log AC-039 | `node test/check-outgoing-log-scan.js` → `OK`: **berkas `gateway.log` sungguhan** (55 baris, 9 baris level error dari kegagalan kirim) dipindai dengan isi pesan/caption, `media_base64`, potongan base64, dan isi media hasil decode acak → **0 kebocoran**; pemindai punya kontrol positif dan menolak log kosong. Basis `outgoing_operations` juga bebas isi pesan/media |
| (d) Semua skrip Fase 1 memakai SQLite temp | `node test/check-test-sqlite-isolation.js --expect-no-data-dir` → 5 skrip Fase 1 `OK`; `data/gateway.sqlite` **tidak terbentuk** di worktree setelah seluruh skrip dijalankan. Lihat temuan T-1 di bawah untuk skrip lama |
| (e) Regresi kumulatif (CON-013) | Seluruh `test/*.js` (Wave 1 + Fase 1 + guard) dijalankan satu per satu: **20 dari 20 lulus, 0 gagal**; tidak ada suppression/skip/penghapusan assertion |

## 4. Keputusan implementasi & penyimpangan yang dideklarasikan

Semua di bawah ini berada dalam Fase 1 dan mudah dibalik; tidak ada REQ/AC baru.

- **P-1 — TASK-003 memuat sebagian matriks respons yang secara nominal milik TASK-007.**
  Tracer tidak bisa membalas apa pun bila `sendReply` melempar error, jadi hasil percobaan
  *saat ini* sudah dipetakan sesuai spec §4.3 (sukses `200`, `INVALID_CHAT_ID` → `500 failed`,
  error lain → tetap `in_flight` + `504 SEND_UNRESOLVED`). Untuk operasi yang **sudah**
  `in_flight` jawabannya selalu `409 SEND_IN_PROGRESS` tanpa kirim (belum ada lease). Ini
  jawaban paling aman (tidak pernah menggandakan pesan). **Sisa untuk TASK-007:** lease
  `OUTGOING_LEASE_MS`, `registerRetry()` + kirim ulang setelah lease, cap → `abandoned` +
  `502 DEAD_LETTERED`, dan uji formal AC-026/AC-028/AC-029/AC-030.
- **P-2 — Kode baru `OPERATION_STORE_ERROR` (HTTP 500) di luar matriks §4.3.** Bila baris
  operasi gagal ditulis **sebelum** kirim, Gateway menolak dan **tidak mengirim** (fail closed);
  tanpa ini request bisa menggantung/crash (Express 4 tidak menangkap promise error) atau
  mengirim tanpa catatan durable. Bila pencatatan gagal **setelah** kirim sukses, hasil tetap
  `200 sent` (pesan memang terkirim) dan baris tinggal `in_flight` (terlihat basi saat start,
  REQ-031). Pemilik boleh meminta kode ini diganti lewat `/sdlc-define-specs`.
- **P-3 — `replayed:true` pada `409 SEND_IN_PROGRESS`.** Matriks §4.3 tidak menetapkan nilainya;
  dipilih `true` karena jawaban berasal dari catatan yang ada (sama seperti `DEAD_LETTERED`).
- **P-4 — Replay dijawab walau WhatsApp sedang tidak connected.** `isConnected()` hanya
  diperiksa untuk operasi **baru**, sebelum `begin()`. Ini sudah memenuhi REQ-030 (tidak ada
  `in_flight` palsu); uji formalnya (AC-030) tetap di TASK-009.
- **P-5 — `markSent()` menerima `sentAt` (waktu kirim asli) sebagai `resolved_at`,** supaya
  `timestamp` pada replay identik dengan respons pertama.
- **P-6 — `abandon()` di store yang menulis log `[CRITICAL]`** (dengan `operation_id`,
  `attempts`, `last_error`, `reason`, tanpa isi pesan) sehingga TASK-007 tidak perlu menulis
  ulang log itu.
- **P-7 — Bukti (c) memakai berkas log nyata,** bukan hanya log yang ditangkap di memori
  (proses anak + `LOG_FOLDER` temp), karena plan meminta pemindaian *berkas* log.

## 5. Temuan di luar scope (dicatat sebagai TODO, **tidak dikerjakan**)

- **T-1 — Lima skrip uji gelombang 1 tidak mengisolasi database:** `simulate-audio-video.js`,
  `simulate-identity-hint.js`, `simulate-lid-conversation.js`, `simulate-send-media.js`,
  `simulate-sticker.js` memuat `connectionManager` (yang membuka `incomingBuffer`) tanpa
  menyetel `SQLITE_PATH`. Bila dijalankan dari folder **live** `C:\projects\WA-Gateway`, skrip
  itu membuka/menulis `data/gateway.sqlite` produksi. Pada sesi ini semuanya dijalankan dari
  worktree dengan `SQLITE_PATH` dipaksa ke folder temp lewat environment (berkas tidak
  diubah), dan `data/` tidak terbentuk. **Saran:** satu perubahan kecil di kelima skrip itu
  (menyetel `SQLITE_PATH` temp di baris awal, pola `simulate-durable-buffer.js`) sebagai task
  terpisah di luar Wave 2, atau minimal jangan menjalankannya dari folder live.
- **F-01/F-02 (migrasi AuliaPos, dari laporan klarifikasi 82/100):** belum relevan di Fase 1;
  **tidak** dikerjakan dan **tidak** dihapus dari catatan — tetap wajib ditangani sebelum
  TASK-016 (Fase 4): task DEPLOY migrasi `aulia_inboxdb` terpisah + perintah migrasi DB uji
  yang eksplisit (mekanika isolasi DB uji), gate uji memakai "≥ jumlah terukur sebelum perubahan".

## 6. Batas jujur (tidak boleh diklaim tertutup)

- **AC-026(b)** (`INVALID_CHAT_ID` → `failed`) bersifat **stub-only**: pada lalu lintas normal
  guard `isDecodableJid()` menolak JID itu dengan `400` sebelum `sendMessage()` (A-2). Cabangnya
  dites hanya agar state machine tidak mati.
- **Paritas fallback JSON** diuji di Node biasa (suite yang sama), belum di Android nyata
  (ASSUMPTION-007, RISK-003 ii).
- **GW-09 belum tertutup:** AC-027/AC-042 (pengukuran nyata dengan timeout cURL AuliaPos) baru
  ada di Fase 5. Kode Fase 1 **belum ter-deploy** — folder live tetap `21a4cb6`, sehingga tidak
  ada perubahan perilaku di Gateway yang berjalan.
- **Interim tanpa lease:** sampai TASK-007, operasi `in_flight` yang hasilnya ambigu tidak bisa
  dicoba ulang (selalu `409`). Itu aman terhadap duplikat tetapi berarti pesan ambigu tidak
  pernah terkirim ulang. Tidak berdampak selama kode belum di-deploy.

## 7. Berikutnya

APPROVAL TASK-006 sudah diberikan. Fase 2 (TASK-007..TASK-010: lease, cap, tugas start-up,
verifikasi) dikerjakan di sesi terpisah dengan `/sdlc-write-code`; prompt siap paste ada di
`docs/handoff-m1-wave2-fase2-write-code-2026-09-24.md`. Branch `feature/m1-wave2-outgoing-idempotency`
(4 commit Fase 1) di-push ke `origin` atas perintah pemilik; folder live tetap `21a4cb6`.
