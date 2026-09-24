# Prompt & Handoff — `/sdlc-write-code` M1 Gelombang 2, Fase 1 (2026-09-24)

> [!IMPORTANT]
> **Dokumen ini non-normatif.** Sumber normatif tetap `plan/plan-process-m1-wave2-outgoing-idempotency-v1.0.md` (v1.0, commit `57de122`) dan `spec/spec-process-m1-wave2-outgoing-idempotency.md` v1.1 (commit `7897d38`). Bila terjadi konflik, **Plan + Spec menang**. Dokumen ini hanya brief operasional + prompt siap paste untuk sesi `/sdlc-write-code` berikutnya.

## 1. Prompt siap paste

Salin blok berikut ke sesi baru (lampirkan berkasnya, jangan hanya menyebut nama):

```text
/sdlc-write-code

Attach: @plan/plan-process-m1-wave2-outgoing-idempotency-v1.0.md (v1.0, status Planned, commit 57de122)
Attach: @docs/audit/clarification-report-m1-wave2-outgoing-idempotency-plan-2026-09-24.md (Readiness 82/100, PROCEED)
Attach: @spec/spec-process-m1-wave2-outgoing-idempotency.md (v1.1, commit 7897d38)
Opsional (pola bentuk): @plan/plan-process-m1-wave1-incoming-reliability-v1.0.md (v1.2)

LINGKUP SESI INI: FASE 1 SAJA — TASK-001..TASK-006 (tracer bullet idempotensi kirim teks), lalu BERHENTI
di TASK-006 (APPROVAL). Jangan menyentuh Fase 2-5.

REPO (dua repo, jangan tertukar):
- WA-Gateway: kerja HANYA di worktree C:\projects\WA-Gateway-m1w2, branch feature/m1-wave2-outgoing-idempotency.
  Folder live C:\projects\WA-Gateway (@ 21a4cb6) READ-ONLY sampai TASK-022. Jangan sentuh auth/ dan data/.
  Dilarang: git checkout / git reset di folder live, npm run dev pada Gateway aktif.
- AuliaPos: C:\xampp\htdocs\aulia — Fase 1 TIDAK menyentuh repo ini sama sekali.

ATURAN EKSEKUSI:
- Ikuti EXECUTION DIRECTIVE plan §2 apa adanya: satu task = satu commit kecil, tiap task kode mengirim ujinya
  pada penambahan yang sama, jalankan VERIFY di akhir fase.
- Sumber nilai & kontrak: spec §4.1-§4.6 (DDL outgoing_operations, enam env var, matriks respons §4.3).
- Floor-Guard: dilarang menambah suppression/skip atau menghapus assertion.
- Gateway uji hanya boleh memakai SQLite di folder temp; DILARANG menulis ke data/gateway.sqlite.
- Setiap kali ragu soal urutan/isi task, tanya dulu; jangan mengarang task baru.

KELUARAN YANG DIMINTA:
1. TASK-001..TASK-005 dieksekusi + TASK-006 APPROVAL (berhenti di sini).
2. Bukti TASK-005 apa adanya: output `node test/simulate-outgoing-*.js` (0 gagal), hasil guard statis urutan
   `begin()` sebelum `sendMessage(`, hasil pemindaian log AC-039, dan konfirmasi semua skrip memakai SQLite temp.
3. Ringkasan commit per task + decision log baru di docs/decisions/ (titik rollback 21a4cb6).
4. Jangan menaikkan status plan di front-matter (baru boleh di TASK-024).

Catatan klarifikasi yang mengikat sesi ini (dari laporan 82/100):
- F-01/F-02 (migrasi AuliaPos) BELUM relevan di Fase 1; jangan dikerjakan sekarang, tetapi jangan dihapus
  dari catatan saat menyentuh Fase 4.
- K-15(iii): permintaan tanpa operation_id -> tidak ada baris operasi + satu warn per proses (AC-025/AC-044).
- RISK-001 sudah diverifikasi 2026-09-24: worktree/branch belum ada, HEAD 21a4cb6, hanya branch master.
```

## 2. Status & prasyarat

- **Status sesi:** sesi `/sdlc-clarify-reqs` pada **plan** sudah selesai — Readiness **82/100**, pemilik memilih **PROCEED** (24 September 2026). Laporan: `docs/audit/clarification-report-m1-wave2-outgoing-idempotency-plan-2026-09-24.md`.
- **Prasyarat yang SUDAH terbukti (diukur 2026-09-24):**
  - `C:\projects\WA-Gateway` @ `21a4cb6`, branch `master`, `git worktree list` hanya folder live → RISK-001 benar.
  - Node `v20.20.2`; `baileys 6.7.24`; `better-sqlite3 ^11.3.0`; `engines.node >= 20`.
  - `pm2` 7.0.4, proses `wa-gateway` **online** (uptime 21 jam) di mesin yang sama → jangan jalankan `npm run dev`.
  - AuliaPos: branch `v2.3`, working copy bersih, suite `OK (328 tests, 1102 assertions)`.
- **Fase 1 tidak memerlukan keputusan pemilik tambahan.** Dua konfirmasi yang masih tertunda (scope AuliaPos/Fase 4 dan cara migrasi) baru dibutuhkan **sebelum TASK-016**, bukan sekarang.

## 3. Lingkup Fase 1 (dan batas yang dilarang)

Lingkup Fase 1 hanya `TASK-001`..`TASK-006` (plan §2, "Implementation Phase 1 — Tracer Bullet"):

| Task | Repo | Isi singkat | AC |
| --- | --- | --- | --- |
| TASK-001 | GW | Buat worktree `C:\projects\WA-Gateway-m1w2` + branch dari `21a4cb6`; `npm ci`; verifikasi folder live tidak tersentuh; catat titik rollback | — |
| TASK-002 | GW | Enam env var di `src/config/index.js` (+ clamp `Math.max`); `src/store/outgoingOperations.js` (SQLite + fallback JSON, constructor menerima `Database`/path) dengan API spec §4.1 + DDL §4.4 + indeks `idx_outgoing_operations_state`; uji `test/simulate-outgoing-store.js` | AC-023 |
| TASK-003 | GW | `src/delivery/outgoingOperationService.js` (`runOperation`): `begin()` **sebelum** `send()`; wiring `/send` di `src/api/ci4Routes.js`; validasi `operation_id`; hitung `payload_hash` **setelah** validasi payload; replay/`409 OPERATION_ID_REUSED`; respons `state`/`replayed`/`operation_id`; uji `test/simulate-outgoing-idempotency.js` | AC-019..AC-022, AC-024, AC-025 |
| TASK-004 | GW | Jalur `/send-media` pada service yang sama; fingerprint dari `mediaMeta` hasil decode; base64 tidak pernah di-hash/di-log; payload tidak valid → `400 INVALID_MEDIA_*` tanpa baris operasi | AC-019..AC-022, AC-024 |
| TASK-005 | — | **VERIFY:** dua skrip uji (0 gagal) + guard statis urutan `begin` sebelum `sendMessage(` + assert `sendMessage` tidak terpanggil pada replay/`OPERATION_ID_REUSED`/`INVALID_OPERATION_ID` + pemindaian log AC-039 + cek SQLite temp + regresi kumulatif gelombang 1 | AC-039, CON-013 |
| TASK-006 | — | **APPROVAL:** berhenti, tunggu konfirmasi eksplisit pemilik | — |

**Dilarang di Fase 1:**

- menyentuh Fase 2-5 (lease, cap, `abandoned`, start-up, dead-letter, AuliaPos, deploy) — termasuk REQ-028/REQ-029/REQ-032 dan TASK-007..TASK-024;
- menyentuh repo AuliaPos apa pun (tidak ada migration, tidak ada controller, tidak ada view);
- menjalankan `php spark migrate` dalam bentuk apa pun;
- `git checkout`/`git reset` di `C:\projects\WA-Gateway`, menyentuh `auth/`, `data/gateway.sqlite`, atau `npm run dev` di Gateway aktif;
- menambah dependensi npm apa pun (CON-008).

## 4. Kontrak yang sudah dikunci (jangan ditafsir ulang)

| Aspek | Nilai terkunci | Sumber |
| --- | --- | --- |
| Pola `operation_id` | `^[A-Za-z0-9._:-]+$`, panjang 1–64; gagal → `400 INVALID_OPERATION_ID` tanpa memanggil Baileys | REQ-020, R-3 |
| Urutan wajib | validasi payload → `payload_hash` → `begin()` (`in_flight`) → `sendMessage()` → `markSent`/`markFailed`/`markUnresolved` | REQ-021, A-7 |
| Fingerprint | SHA-256 JSON kanonik `[kind, chatId, text \| null, mediaMeta \| null]`; `mediaMeta` dari konten **hasil decode** | ASSUMPTION-005 |
| Beda fingerprint | `409 OPERATION_ID_REUSED`, tanpa Baileys | REQ-023 |
| State terminal | respons tersimpan + `replayed:true`, tanpa Baileys | REQ-022 |
| Tanpa `operation_id` | perilaku persis seperti sekarang, tanpa baris operasi, satu `warn` per proses | REQ-026, AC-025/AC-044 |
| Env (enam, bawaan) | `OUTGOING_MAX_ATTEMPTS=5`, `OUTGOING_LEASE_MS=35000`, `OUTGOING_OPERATION_TTL_MS=86400000`, `DELIVERY_MAX_ATTEMPTS=100`, `DELIVERY_DEAD_AFTER_MS=86400000`, `DELIVERY_DEAD_BURST_THRESHOLD=10`; clamp minimum 1 (kecuali `DELIVERY_DEAD_AFTER_MS` minimum 0) | spec §4.6, GUD-003, RISK-005 |
| DDL | `outgoing_operations` persis spec §4.4 + indeks `(state, updated_at)`; `attempts` mulai dari `1` | spec §4.4, A-8a |
| Arti `attempts` | jumlah **kiriman yang sudah dijalankan** (cap diperiksa **sebelum** kirim ulang) — relevan di Fase 2 | D-11/R-2 |
| Enam env, bukan lima | spec §7 masih menulis "lima"; implementasikan **enam** | RISK-005 |
| Larangan log | tidak ada isi pesan/teks balasan/`media_base64` di log; hanya `operation_id`, `chat_id`, `messageId`, ukuran byte, hash | SEC-001, AC-039 |

> [!NOTE] Kalau implementasi butuh nilai/kontrak yang tidak ada di tabel ini, **berhenti dan tanya**. Jangan menambah requirement baru — plan ini tidak menambah REQ di luar spec v1.1 (Red Flag #7).

## 5. Titik sentuh kode & fakta yang sudah diverifikasi

Baca struktur aktual **sebelum** mengedit; fakta berikut berasal dari pembacaan 2026-09-24 (repo live `21a4cb6`):

- `src/api/ci4Routes.js` — `:41`/`:124` menolak JID tak ter-decode dengan `400 INVALID_CHAT_ID`; `:94`/`:226` membalas `res.status(500).json({ success:false, error_code: err.code || 'SEND_FAILED' })`. Baca dulu sebelum menambah cabang baru.
- `src/config/index.js` — pakai pola clamp yang sudah ada (`ownSentTtlMs`, `ownSentMax`) agar gaya konsisten.
- `src/store/incomingBuffer.js` — contoh nyata pola singleton + `PRAGMA table_info` + `ALTER TABLE` + implementasi fallback JSON (`IncomingBufferJsonFile`); **jangan** diubah di Fase 1 (baru TASK-011).
- `src/app/index.js` — titik start-up yang baru disentuh di TASK-008 (Fase 2); di Fase 1 cukup dibaca untuk memastikan singleton `outgoingOperations` diinisialisasi dengan benar (bila perlu).
- Pola uji yang wajib diikuti: `test/simulate-*.js` dengan `assert`, SQLite di folder temp sistem, dihapus setelah tiap skenario (lihat `test/simulate-durable-buffer.js`), dan guard statis ala TASK-011 gelombang 1 (regex atas source).
- `package.json` Gateway: `baileys 6.7.24`, `better-sqlite3 ^11.3.0`, `engines.node >= 20` (Node 24 tidak didukung).

## 6. Jebakan yang sudah terbukti (jangan diulang)

- **Git di repo ini:** jalankan `add` + `commit` sebagai **satu** rangkaian perintah (`;`), jangan tiga panggilan paralel — pernah bertabrakan di `.git/index.lock` dan menghasilkan commit dengan isi tidak sesuai pesannya. Commit message kompleks lewat file (`git commit -F`), karena kutip dalam `-m` mudah terpecah di PowerShell.
- **Jangan** pakai `&&` di shell bawaan: PowerShell menolaknya; rangkai dengan `;` atau gunakan `cmd /c "… & …"`.
- **Uji besar via `cmd /c`:** tulis keluaran ke berkas (`cmd /c "node test/… > build\out.txt 2>&1"`) lalu baca berkasnya; pipe PowerShell adalah bagian yang lambat.
- **`markdownlint-cli2` keluar dengan exit 1** bila ada temuan — itu **bukan** kegagalan tool. Yang dinilai adalah **jenis** temuan (bandingkan dengan dokumen preseden), bukan kode keluar.
- **Jangan mengukur dengan `pm2 stop`** pada Fase 1 (tidak diperlukan) dan jangan menghentikan proses `wa-gateway` yang sedang online.
- **Bukti palsu:** jangan mengklaim `INVALID_CHAT_ID`/jalur `failed` sebagai perilaku produksi (itu stub-only, A-2) dan jangan mengklaim Fase 2/3 sudah tertutup dari Fase 1.

## 7. Definition of Done Fase 1

- TASK-001..TASK-005 selesai, satu commit per task, gaya pesan repo (contoh: `feat(gateway): add outgoing operations store`, `feat(gateway): make /send idempotent by operation_id`).
- `node test/simulate-outgoing-store.js` dan `node test/simulate-outgoing-idempotency.js` → **0 gagal**; seluruh `test/simulate-*.js` gelombang 1 dijalankan ulang → **0 gagal** (regresi kumulatif, CON-013).
- Guard statis membuktikan `begin()`/penulisan `in_flight` **mendahului** `sendMessage(`; assert bahwa `sendMessage` tidak terpanggil pada jalur replay/`OPERATION_ID_REUSED`/`INVALID_OPERATION_ID`.
- Tidak ada berkas uji yang menulis ke `data/gateway.sqlite`; tidak ada suppression/skip (Floor-Guard).
- `docs/decisions/` berisi satu log baru: titik rollback `21a4cb6`, hasil TASK-001, dan bukti TASK-005.
- **BERHENTI di TASK-006.** Jangan mulai Fase 2 dan **jangan push** tanpa perintah pemilik. Tawarkan memory checkpoint.

## 8. Setelah Fase 1

- **Fase 2 (TASK-007..TASK-010):** lease, cap `attempts`, matriks respons (`INVALID_CHAT_ID` → `500`, ambigu → tetap `in_flight` + `504 SEND_UNRESOLVED`), dan tugas start-up (`listStaleInFlight`, `pruneTerminal`).
- **Fase 3 (TASK-011..TASK-014):** attempt counter + dead-letter `incoming_queue` (`status='dead'`), klasifikasi `400`/`422` sebagai penolakan permanen (cabang `422` ditandai `[Assumed / Out of Scope]`).
- **Fase 4 (TASK-015..TASK-021) — sebelum mulai:** sisipkan **F-01** (satu task DEPLOY migrasi `aulia_inboxdb`, dengan persetujuan pemilik) dan perbaiki **F-02** pada TASK-016 (perintah migrasi DB uji yang eksplisit), lalu perbarui gate TASK-020/TEST-008/DEP-009 (**≥ 328 test / 1102 assertion** terukur hari ini, dengan aturan "≥ terukur sebelum perubahan").
- **Fase 5 (TASK-022..TASK-024) — sebelum TASK-023:** perjelas urutan bukti AC-027 (percobaan pertama berakhir `502`; keadaan "hasil belum pasti" diverifikasi pada kirim ulang **di dalam lease**), pilih teknik perlambatan (blokir port ≤15 detik; **bukan** `pm2 stop`), dan konfirmasi ulang premis lalu lintas nyata (nomor Gateway sekarang menerima pesan masuk sungguhan).

## 9. Rujukan

- `plan/plan-process-m1-wave2-outgoing-idempotency-v1.0.md` (v1.0, `57de122`) — **normatif**, berisi EXECUTION DIRECTIVE.
- `spec/spec-process-m1-wave2-outgoing-idempotency.md` (v1.1, `7897d38`) — REQ-020..REQ-041, AC-019..AC-046, DDL, env, matriks respons.
- `docs/audit/clarification-report-m1-wave2-outgoing-idempotency-plan-2026-09-24.md` — Readiness 82/100, PROCEED, F-01/F-02, K-01..K-15.
- `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` (v1.2) — pola VERIFY/APPROVAL/DEPLOY dan pelajaran CR (DB sementara, guard statis).
- `plan/plan-bugfix-inbox-test-db-isolation-v1.0.md` (`Completed`) + `docs/ARCHITECTURE.md` §5/§11 — isolasi DB uji dan langkah sinkronisasi skema.
- `.claude/instructions/memory.instructions.md` — checkpoint 2026-09-24 (klarifikasi plan M1 W2).
