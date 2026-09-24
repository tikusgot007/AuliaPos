# Prompt & Handoff — `/sdlc-write-code` M1 Gelombang 2, Fase 2 (2026-09-24)

> [!IMPORTANT]
> **Dokumen ini non-normatif.** Sumber normatif tetap `plan/plan-process-m1-wave2-outgoing-idempotency-v1.0.md` (v1.0, commit `57de122`) dan `spec/spec-process-m1-wave2-outgoing-idempotency.md` v1.1 (commit `7897d38`). Bila terjadi konflik, **Plan + Spec menang**. Dokumen ini hanya brief operasional + prompt siap paste untuk sesi `/sdlc-write-code` berikutnya.

## 1. Prompt siap paste

Salin blok berikut ke **sesi baru** (lampirkan berkasnya, jangan hanya menyebut nama):

```text
/sdlc-write-code

Attach: @plan/plan-process-m1-wave2-outgoing-idempotency-v1.0.md (v1.0, status Planned, commit 57de122;
        kolom Completed TASK-001..006 sudah terisi)
Attach: @spec/spec-process-m1-wave2-outgoing-idempotency.md (v1.1, commit 7897d38)
Attach: @docs/decisions/2026-09-24-m1-wave2-eksekusi-fase1.md (hasil Fase 1: penyimpangan P-1..P-7, temuan T-1)
Attach: @docs/audit/clarification-report-m1-wave2-outgoing-idempotency-plan-2026-09-24.md (Readiness 82/100, PROCEED)

LINGKUP SESI INI: FASE 2 SAJA — TASK-007..TASK-010 (lease, batas percobaan, matriks respons, tugas start-up),
lalu BERHENTI di TASK-010 (APPROVAL). Jangan menyentuh Fase 3-5. Fase 1 sudah selesai dan DISETUJUI pemilik
(TASK-006, 2026-09-24); jangan dikerjakan ulang.

KONDISI AWAL (verifikasi dulu, jangan diasumsikan):
- WA-Gateway: kerja HANYA di worktree C:\projects\WA-Gateway-m1w2, branch feature/m1-wave2-outgoing-idempotency.
  Worktree SUDAH ADA (TASK-001 selesai) -- JANGAN membuatnya lagi. HEAD harus 5a48311 (4 commit Fase 1 di atas
  21a4cb6) dan `git status --short` kosong. Cek: git -C C:\projects\WA-Gateway-m1w2 log --oneline 21a4cb6..HEAD
- Folder live C:\projects\WA-Gateway harus tetap @ 21a4cb6, READ-ONLY sampai TASK-022. Jangan sentuh auth/ dan data/.
  Dilarang: git checkout / git reset di folder live, npm run dev pada Gateway aktif, pm2 stop.
- AuliaPos: C:\xampp\htdocs\aulia -- Fase 2 TIDAK menyentuh kode repo ini (hanya dokumen decision log/handoff/memory).

ATURAN EKSEKUSI:
- Ikuti EXECUTION DIRECTIVE plan §2: satu task = satu commit kecil, tiap task kode mengirim ujinya pada
  penambahan yang sama, jalankan VERIFY (TASK-009) di akhir fase.
- Sumber nilai & kontrak: spec §4.2 (state machine), §4.3 (matriks respons), §4.6 (env), REQ-027..REQ-032, D-10/D-11.
- Floor-Guard: dilarang menambah suppression/skip atau menghapus assertion. Semua skrip Fase 1 dan gelombang 1
  HARUS tetap lulus (CON-013).
- Uji Gateway hanya boleh memakai SQLite di folder temp; DILARANG menulis ke data/gateway.sqlite.
- Setiap kali ragu soal urutan/isi task, tanya dulu; jangan mengarang task baru.
- Commit hanya di worktree, satu task satu commit. JANGAN push tanpa perintah pemilik.

KELUARAN YANG DIMINTA:
1. TASK-007..TASK-009 dieksekusi + TASK-010 APPROVAL (berhenti di sini).
2. Bukti TASK-009 apa adanya: output test/simulate-outgoing-recovery.js (0 gagal) dengan AC-026 DITANDAI
   stub-only, AC-028 (30 dtk -> 409 tanpa ubah attempts; 40 dtk -> retry, attempts naik), AC-029 (kiriman 1..5 jalan,
   permintaan ke-6 -> abandoned + 502 + [CRITICAL], sendMessage tidak dipanggil), AC-030, AC-031, AC-032, AC-043;
   regresi kumulatif seluruh test/*.js; pemindaian log AC-039 diulang; konfirmasi SQLite temp.
3. Ringkasan commit per task + decision log Fase 2 baru di docs/decisions/ (jangan menimpa log Fase 1).
4. Jangan menaikkan status plan di front-matter (baru boleh di TASK-024). Boleh mengisi kolom Completed/Date
   untuk task yang sudah selesai.
5. Akhiri tiap langkah dengan 3 baris: Selesai / Belum / Masih dalam tujuan awal? YA/TIDAK.
```

## 2. Kondisi kode setelah Fase 1 (fakta terverifikasi 2026-09-24)

Branch `feature/m1-wave2-outgoing-idempotency`, HEAD `5a48311`:

| Task Fase 1 | Commit | Berkas |
| --- | --- | --- |
| TASK-002 | `55a1ae1` | `src/config/index.js` (enam env), `src/store/outgoingOperations.js`, `test/simulate-outgoing-store.js` |
| TASK-003 | `6fe151a` | `src/delivery/outgoingOperationService.js`, `src/api/ci4Routes.js` (`/send`), `test/simulate-outgoing-idempotency.js` |
| TASK-004 | `bdbf534` | `/send-media` di `ci4Routes.js`, cabang media pada uji idempotensi |
| TASK-005 | `5a48311` | `test/check-outgoing-begin-before-send.js`, `simulate-outgoing-order-guard.js`, `check-outgoing-log-scan.js`, `check-test-sqlite-isolation.js` |

**Yang SUDAH ada (jangan ditulis ulang):**

- Store (`outgoingOperations.js`): `begin`, `get`, `markSent` (menerima `sentAt`), `markFailed`, `markUnresolved`, `registerRetry`, `abandon(operationId, reason)` (sudah menulis log `[CRITICAL]`), `listStaleInFlight`, `countInFlight`, `pruneTerminal` (sudah mencatat `[CRITICAL]` untuk baris `abandoned` sebelum dihapus, maksimum 20 id). Semua transisi dijaga `WHERE state = 'in_flight'`. **Jam bisa disuntik lewat `store.now = () => ms`** (dipakai uji untuk lompat waktu).
- Service (`outgoingOperationService.js`): `runOperation()` dalam dua tahap (tahap 1 catat/`begin`, gagal → `store_error`; tahap 2 kirim). `classifyExisting()` saat ini menjawab `in_progress` untuk **setiap** baris `in_flight` (belum ada lease). `toHttpResponse()` sudah memetakan `sent`/`failed`/`unresolved`/`in_progress`/`reused`/`not_connected`/`store_error`/`replay` (termasuk replay `abandoned` → `502 DEAD_LETTERED`, `replayed:true`).
- Route: `/send` dan `/send-media` hanya memanggil Baileys lewat closure `doSend` yang diteruskan sebagai `send: doSend` ke `runOperation()`; `isReady` sudah dipanggil **sebelum** `begin()` untuk operasi baru.

**Penyimpangan Fase 1 yang menjadi titik awal Fase 2 (detail: decision log Fase 1 §4):**

- **P-1:** hasil percobaan saat ini (`200`, `500 failed`, `504 SEND_UNRESOLVED`) dan `409 SEND_IN_PROGRESS` sudah ada. **Sisa milik TASK-007:** lease, `registerRetry()` + kirim ulang setelah lease, cap → `abandoned` + `502`, dan uji formal AC-026/028/029/030/042.
- **P-2:** kode `OPERATION_STORE_ERROR` (500) — gagal mencatat sebelum kirim = tidak mengirim.
- **P-4:** replay dijawab walau WhatsApp tidak connected; `isConnected()` hanya untuk operasi baru (dan, di Fase 2, untuk percobaan ulang — lihat saran di bawah).

## 3. Lingkup Fase 2 (dan batas yang dilarang)

| Task | Repo | Isi singkat | AC |
| --- | --- | --- | --- |
| TASK-007 | GW | Lease `OUTGOING_LEASE_MS`, cap `OUTGOING_MAX_ATTEMPTS`, sisa matriks respons; buat `test/simulate-outgoing-recovery.js` | AC-026 (stub-only), AC-028, AC-029, AC-030, AC-042 |
| TASK-008 | GW | Tugas start-up di `src/app/index.js`: `listStaleInFlight` → log `error`; `pruneTerminal` → TTL; tambah uji start-up pada `simulate-outgoing-recovery.js` | AC-031, AC-032, AC-043 |
| TASK-009 | — | **VERIFY** (lihat "Keluaran yang diminta" no. 2) | AC-026, AC-039, CON-013 |
| TASK-010 | — | **APPROVAL:** berhenti, tunggu konfirmasi eksplisit pemilik | — |

**Dilarang di Fase 2:** Fase 3-5 (dead-letter `incoming_queue`, `incomingDelivery`, seluruh AuliaPos, deploy/`pm2 restart`, pengukuran nyata AC-027/AC-042), `php spark migrate`, menyentuh `auth/`/`data/`, dependensi npm baru, mengubah kontrak `POST /api/inbox/gateway/messages`.

## 4. Kontrak yang sudah dikunci (Fase 2)

| Aspek | Nilai terkunci | Sumber |
| --- | --- | --- |
| Lease | `in_flight` yang `updated_at`-nya **lebih tua dari** `OUTGOING_LEASE_MS` (35000) boleh dicoba ulang; yang masih di dalam lease → `409 SEND_IN_PROGRESS`, tanpa kirim, `attempts` tidak berubah | REQ-028, AC-028 |
| Arti `attempts` | jumlah **kiriman yang dijalankan**; pemeriksaan `attempts >= OUTGOING_MAX_ATTEMPTS` (5) dilakukan **sebelum** `registerRetry()`/kirim | REQ-029, D-11 |
| Cap tercapai | `abandon('max_attempts')` + log `[CRITICAL]` + `502 DEAD_LETTERED` (`state:'abandoned'`, `replayed:true`), `sendMessage` dan `registerRetry` **tidak** dipanggil | REQ-029, AC-029 |
| Hitungan AC-029 | kiriman ke-1..5 berjalan (tiap ulangan menunggu lease lewat), permintaan **ke-6** → `abandoned` | R-2, AC-029 |
| Cek koneksi | `isConnected()` **sebelum** baris operasi dibuat; `NOT_CONNECTED` tidak meninggalkan `in_flight` palsu | REQ-030, AC-030 |
| Start-up | operasi `in_flight` basi dicatat level `error` (jumlah + maks 20 `operation_id`), **tidak memblokir start** | REQ-031, AC-031 |
| Pemangkasan | `pruneTerminal(OUTGOING_OPERATION_TTL_MS)` menghapus terminal tua, **tidak** menghapus `in_flight`; `abandoned` dicatat `[CRITICAL]` dulu; jaminan idempotensi ≤ TTL | REQ-032, D-13, AC-032, AC-043 |
| AC-026(b) | `INVALID_CHAT_ID` → `failed`/`500` bersifat **stub-only** (A-2); tandai `stub-only` di output dan ringkasan, jangan diklaim bukti produksi | A-2, RISK-006 |

## 5. Saran rancangan (non-normatif, hasil analisis sesi Fase 1 — boleh diikuti atau diganti bila ada alasan)

- **Lease di service, bukan di route.** Ubah `classifyExisting()`: baris `in_flight` → hitung umur dengan `outgoingOperations.now() - Date.parse(row.updated_at)` (pakai jam store agar uji bisa lompat waktu); `> config.outgoingLeaseMs` → keputusan baru `stale`, selain itu tetap `in_progress`. Perhatikan pembanding **`>`** (AC-028: 30 dtk → 409, 40 dtk → retry).
- **Urutan pada keputusan `stale` (tahap 1, di dalam try/catch yang sama):** (1) cap: `attempts >= config.outgoingMaxAttempts` → `abandon(..., 'max_attempts')`, ambil ulang baris, kembalikan `{outcome:'replay', row}` (sudah dipetakan ke `502 DEAD_LETTERED`); (2) `isReady()` salah → `not_connected` **tanpa** `registerRetry()` (jangan membakar percobaan); (3) `registerRetry()` lalu lanjut ke tahap 2 (kirim). Cap diperiksa lebih dulu karena dead-letter tidak bergantung pada koneksi.
- **Setelah percobaan ulang gagal ambigu**, `markUnresolved()` sudah memperbarui `updated_at` → jendela lease baru mulai; uji AC-029 memakai `store.now` untuk melompat >35 dtk di antara permintaan.
- **Guard statis (TASK-005) harus tetap lulus:** `begin(` mendahului `await send(` dalam `runOperation()`, dan `/send`/`/send-media` hanya memanggil Baileys lewat `doSend`. Jalankan `node test/check-outgoing-begin-before-send.js` dan `node test/simulate-outgoing-order-guard.js` setelah mengubah `runOperation()`.
- **TASK-008:** buat fungsi `runStartupChecks()` yang diekspor dari `outgoingOperationService.js` (atau modul kecil setara), panggil dari `src/app/index.js` setelah `startServer()` / sebelum `connectionManager.start()`, dibungkus try/catch (gagal → log `error`, start **tetap jalan**). `src/app/index.js` tidak bisa di-`require` dalam uji (ia langsung start server + WhatsApp) — uji fungsinya langsung, dan tambahkan assert statis bahwa `index.js` memanggil `runStartupChecks(`. Beri komentar bahwa pemangkasan adalah batas jaminan idempotensi (D-13/A-5).
- **Pola uji:** salin pola harness `test/simulate-outgoing-idempotency.js` (server express port acak, `connectionManager.sendReply/sendMediaReply/isConnected` di-stub, "restart" = hapus `require.cache` modul store/service/router). Pakai `store.now` untuk waktu.

## 6. Jebakan yang sudah terbukti (jangan diulang)

- **`ensureBaileysLoaded()` wajib** di awal uji HTTP: `isDecodableJid()` memakai Baileys; tanpa itu JID valid dianggap tidak valid dan uji gagal dengan `400 INVALID_CHAT_ID` yang menyesatkan.
- **`connectionManager` ikut membuka `incomingBuffer`** pada berkas SQLite yang sama: tutup `incomingBuffer.close()` **dan** store di akhir, atau `fs.rmSync` folder temp gagal `EBUSY` di Windows.
- **CRLF:** berkas kerja memakai CRLF (`core.autocrlf=true`). Guard/uji berbasis regex atau string multi-baris harus menormalkan `\r\n` → `\n` lebih dulu. Mutasi uji-diri gagal keras bila teksnya tidak ditemukan — pertahankan sifat itu.
- **Tidak ada `python`** di shell ini; untuk edit massal pakai skrip `node` dari **berkas** (kutip bash merusak regex bila memakai `node -e`). Selalu periksa `git diff --stat` setelah edit massal.
- **Lima skrip uji gelombang 1 tidak mengisolasi database** (temuan T-1): `simulate-audio-video.js`, `simulate-identity-hint.js`, `simulate-lid-conversation.js`, `simulate-send-media.js`, `simulate-sticker.js`. Jalankan hanya dari worktree dan paksa `SQLITE_PATH` ke folder temp lewat environment. **Jangan menjalankannya dari folder live.** Perintah regresi yang dipakai di Fase 1:

  ```bash
  cd /c/projects/WA-Gateway-m1w2 && export SQLITE_PATH="$TEMP/wa-regress/gateway.sqlite" && mkdir -p "$(dirname "$SQLITE_PATH")"
  for f in test/check-register-before-send.js test/simulate-*.js test/check-outgoing-begin-before-send.js test/check-outgoing-log-scan.js; do
    timeout 240 node "$f" >/dev/null 2>&1 && echo "PASS $f" || echo "FAIL $f"; done
  node test/check-test-sqlite-isolation.js --expect-no-data-dir
  ```

- **Git:** `add` + `commit` sebagai satu rangkaian; pesan lewat `git commit -F` (heredoc). Jangan `push` tanpa perintah pemilik.
- **Bukti palsu:** jangan mengklaim AC-026(b) sebagai perilaku produksi, dan jangan mengklaim GW-09 tertutup — AC-027/AC-042 nyata baru ada di Fase 5.

## 7. Definition of Done Fase 2

- TASK-007..TASK-009 selesai, satu commit per task (`feat(outgoing): …`, `test(outgoing): …`).
- `node test/simulate-outgoing-recovery.js` → **0 gagal**; seluruh skrip Fase 1 + gelombang 1 → 0 gagal; guard statis + uji-dirinya lulus; pemindaian log AC-039 diulang → 0 kebocoran.
- Tidak ada skrip yang menulis ke `data/gateway.sqlite`; tidak ada suppression/skip.
- Decision log Fase 2 baru di `docs/decisions/`; kolom Completed/Date plan diisi untuk TASK-007..TASK-010.
- **BERHENTI di TASK-010.** Jangan mulai Fase 3 dan jangan push tanpa perintah pemilik. Tawarkan memory checkpoint.

## 8. Setelah Fase 2 (agar tidak hilang dari catatan)

- **Fase 3 (TASK-011..TASK-014):** attempt counter + dead-letter `incoming_queue` (`status='dead'`), klasifikasi `400`/`422` (cabang `422` ditandai `[Assumed / Out of Scope]`), `replayDeadLetter()`, log start/burst.
- **Fase 4 (TASK-015..TASK-021) — sebelum mulai:** sisipkan **F-01** (satu task DEPLOY migrasi `aulia_inboxdb` dengan persetujuan pemilik), perbaiki **F-02** pada TASK-016 (perintah migrasi DB uji `CI_ENVIRONMENT=testing` + `php spark migrate --dbgroup inbox`), perbarui gate TASK-020 (aturan "≥ jumlah terukur sebelum perubahan", angka awal 328/1102), dan tetapkan bentuk respons "belum pasti" (K-15 (i): `200` + `status:'error'` + `error_code`/`state`/`replayed`). F-01/F-02 **tidak** dikerjakan dan **tidak** dihapus dari catatan.
- **Fase 5 (TASK-022..TASK-024) — sebelum TASK-023:** perjelas urutan bukti AC-027 (percobaan pertama berakhir `502`; keadaan "hasil belum pasti" diverifikasi pada kirim ulang **di dalam lease**), teknik perlambatan = blokir port ≤15 detik (bukan `pm2 stop`), dan konfirmasi ulang premis lalu lintas nyata (nomor Gateway menerima pesan masuk sungguhan).

## 9. Rujukan

- `plan/plan-process-m1-wave2-outgoing-idempotency-v1.0.md` (`57de122`) — **normatif**, berisi EXECUTION DIRECTIVE.
- `spec/spec-process-m1-wave2-outgoing-idempotency.md` (v1.1, `7897d38`) — REQ-020..REQ-041, AC-019..AC-046, DDL, env, matriks respons.
- `docs/decisions/2026-09-24-m1-wave2-eksekusi-fase1.md` — hasil Fase 1, penyimpangan P-1..P-7, temuan T-1, batas jujur.
- `docs/audit/clarification-report-m1-wave2-outgoing-idempotency-plan-2026-09-24.md` — Readiness 82/100, F-01/F-02, K-01..K-15.
- `docs/handoff-m1-wave2-fase1-write-code-2026-09-24.md` — handoff Fase 1 (pola dokumen ini).
- `.claude/instructions/memory.instructions.md` — checkpoint 2026-09-24 (eksekusi Fase 1).
