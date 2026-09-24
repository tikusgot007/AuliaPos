# Handoff — M1 Gelombang 2, Sesi Code Review (Wave 2 ditutup 2026-09-24)

> [!IMPORTANT]
> **Dokumen ini non-normatif.** Sumber normatif tetap `spec/spec-process-m1-wave2-outgoing-idempotency.md` v1.1 dan `plan/plan-process-m1-wave2-outgoing-idempotency-v1.0.md` (kini `Completed`). Bila terjadi konflik, Plan + Spec menang.

## 1. Prompt siap paste

Salin blok berikut ke **sesi baru** dan lampirkan berkasnya:

```text
/sdlc-code-review

Attach: @spec/spec-process-m1-wave2-outgoing-idempotency.md (v1.1)
Attach: @plan/plan-process-m1-wave2-outgoing-idempotency-v1.0.md (status Completed)
Attach: @docs/decisions/2026-09-24-m1-wave2-phase5-deploy.md (deploy + pengukuran nyata §8)
Attach: @docs/decisions/2026-09-24-m1-wave2-phase4-aulias-pos-caller.md (sisi AuliaPos §4-§8)
Attach: @docs/decisions/2026-09-24-m1-wave2-phase3-incoming-dead-letter.md (dead-letter Gateway)
Attach: @docs/audit/clarification-report-m1-wave2-outgoing-idempotency-plan-2026-09-24.md (K-04/K-10/K-13)

LINGKUP REVIEW: seluruh perubahan M1 Wave 2 di DUA repo:
- WA-Gateway: 21a4cb6..4010cc1 (folder live sudah dideploy, commit lokal belum di-push)
- AuliaPos: f2b4f2c..HEAD (v2.3, commit lokal belum di-push)

Fokus yang diminta:
1. SEC-001 (tidak ada isi pesan/media_base64 di log) dan SEC-002 (kueri terparameter) pada kode baru.
2. Kebenaran state machine `outgoing_operations` (lease, cap, replay, abandoned) vs REQ-020..REQ-032.
3. `Inbox::findMessageByOperationId()` bersama + `gatewayFailureResponse()` (kontrak 200+status error).
4. Batas bukti yang TIDAK boleh diklaim tertutup (lihat §5 dokumen ini).
JANGAN mengubah kode: hasilkan rencana perbaikan, bukan patch (aturan skill review).
```

## 2. Kondisi akhir yang sudah diverifikasi

| Item | Nilai |
| --- | --- |
| WA-Gateway live | `C:\projects\WA-Gateway`, branch `master` @ `4010cc1`, `+8` vs `origin/master` (belum di-push) |
| Gateway proses | PM2 `wa-gateway` **online**, `restarts 3`, `script path` = folder live |
| Worktree Gateway | `C:\projects\WA-Gateway-m1w2` @ `4010cc1`, branch `feature/m1-wave2-outgoing-idempotency` |
| AuliaPos | `C:\xampp\htdocs\aulia`, branch `v2.3`, `+18` vs `origin/v2.3` (belum di-push), working copy bersih |
| Suite AuliaPos | `OK (349 tests, 1209 assertions)`, 0 skip/suppression |
| Skrip Gateway | 21+ skrip `simulate-*.js`/`check-*.js` lulus; guard statis + log-scan AC-039 lulus |
| Harness AC-040/AC-045 | `24 PASS, 0 FAIL` (server HTTP lokal, `build/`, gitignored) |
| DB kerja | `aulia_inboxdb.messages.gateway_operation_id` (`varchar(64) NULL`, UNIQUE) sudah diterapkan |
| DB uji | `aulia_inboxdb_test` sudah di-re-sync, tidak drift |
| Pengukuran nyata | Round S + 1-4: AC-027 ×2, AC-042 ×2, **nol duplikat** (detail §8.6/§8.7 decision log) |
| Sisa intervensi | Tidak ada: `.env` kembali ke `http://localhost:3000`, proxy `m1w2-delay-proxy` sudah dihapus dari PM2 |

## 3. Cara mereproduksi diff untuk review

```text
git -C C:\projects\WA-Gateway --no-pager diff --stat 21a4cb6..4010cc1
git -C C:\xampp\htdocs\aulia  --no-pager log --oneline f2b4f2c..HEAD
git -C C:\xampp\htdocs\aulia  --no-pager diff --stat f2b4f2c..HEAD -- app/ tests/
```

Peta berkas yang berubah:

- **WA-Gateway (baru)** `src/store/outgoingOperations.js`, `src/delivery/outgoingOperationService.js`.
- **WA-Gateway (ubah)** `src/api/ci4Routes.js`, `src/config/index.js`, `src/store/incomingBuffer.js`, `src/delivery/incomingDelivery.js`, `src/app/index.js`.
- **WA-Gateway (uji)** 8 skrip baru di `test/`.
- **AuliaPos (baru)** `app/Database/Migrations/2026-09-24-000001_AddGatewayOperationIdToMessages.php`, 5 berkas uji baru di `tests/`.
- **AuliaPos (ubah)** `app/Controllers/Inbox.php` (`kirimMedia()`, `kirimKeConversation()`, `callGatewaySend()`, `callGatewaySendMedia()`, `gatewayFailureResponse()`, `findMessageByOperationId()`), `app/Models/MessageModel.php` (`$allowedFields`), `app/Views/inbox/index.php` (form balas + JS kunci).
- **Dokumentasi** `docs/decisions/2026-09-24-m1-wave2-phase{3,4,5}*.md`, `docs/ARCHITECTURE.md`, plan M1 W2.

## 4. Bukti yang sudah tersedia (jangan dijalankan ulang tanpa alasan)

- **Deploy Gateway**: decision log Fase 5 §2 (HEAD sebelum/sesudah, PM2, kutipan log start-up, isi SQLite live).
- **Migrasi DB kerja**: decision log Fase 5 §3 (perintah, `SHOW COLUMNS`/`SHOW INDEX` verbatim, backup + verifikasi restore).
- **Smoke test & pengukuran**: decision log Fase 5 §8.6 dan §8.7 (tabel per putaran + kutipan log proxy/Gateway + keadaan baris DB).
- **Regresi Gateway**: decision log Fase 3 §3-§4 (23/23 skrip, guard, log-scan, isolasi SQLite temp).
- **Regresi AuliaPos**: decision log Fase 4 §5 (suite, AC-040/AC-045, render AC-046, footprint CON-012).
- **Alat ukur**: `build/delay-proxy.js` + `build/scratch-delay-proxy-check.js` (`SELFTEST_PORT=3020 node …` → 12 PASS) dapat dijalankan ulang bila perlu.

## 5. Batas jujur yang TIDAK boleh diklaim tertutup (K-10)

| Item | Status yang benar |
| --- | --- |
| **ASSUMPTION-009** (jendela crash: WhatsApp menerima pesan, Gateway mati sebelum baris ditandai `sent`) | **Terbuka.** Duplikat langka masih mungkin. Penutup penuh = GW-21 (M2). Jangan menulis "duplikat mustahil". |
| **AC-026(b)** (`INVALID_CHAT_ID` → `failed`/`500`) | **`stub-only`.** Pada lalu lintas nyata guard `isDecodableJid()` berjalan sebelum `sendMessage()`. |
| **Cabang HTTP `422`** di `incomingDelivery` | **`[Assumed / Out of Scope]`** (A-8b): `InboxGatewayApi` hanya membalas `200`/`400`/`500`. |
| **K-04** (`DELIVERY_MAX_ATTEMPTS=100`, `DELIVERY_DEAD_AFTER_MS`) | **Terbuka.** Tidak ada pemadaman nyata selama Wave 2, jadi belum ada data kalibrasi. |
| **D-13/A-5 (TTL)** | Jaminan idempotensi hanya **≤ 24 jam**; setelah baris terminal dipangkas, `operation_id` lama = operasi baru. |
| **ASSUMPTION-007** (fallback JSON Android) | **Belum diuji di lingkungan nyata**; Gateway live memakai SQLite. |
| **Pengukuran sintetis** | Round 1–4 memakai proxy jeda lokal, bukan degradasi jaringan nyata. Mekanismenya sama (klien menyerah, Gateway tetap bekerja), tetapi ini bukan bukti dari insiden nyata. |
| **Temuan baru (bukan cacat spec)** | Pesan yang terkirim lewat jalur `409` **tidak tercatat** di `messages` — konsekuensi REQ-041; kandidat Wave 3. |

## 6. Saran fokus review

- `outgoingOperationService.js`: urutan `begin()` → `send()` (REQ-021), pemeriksaan cap **sebelum** `registerRetry()` (R-2), pemetaan `failed` hanya untuk `INVALID_CHAT_ID`.
- `outgoingOperations.js`: transisi state, `pruneTerminal()` (mempertahankan `in_flight`), paritas SQLite vs fallback JSON.
- `incomingBuffer.js` / `incomingDelivery.js`: enum `reason` tunggal, dead-letter non-destruktif, replay tepat satu siklus.
- `ci4Routes.js`: validasi `operation_id`, `payload_hash` dihitung setelah validasi payload, tidak ada kunci dibuat di server.
- `Inbox.php`: dedupe bersama (`findMessageByOperationId()` sengaja **tidak** memfilter `deleted_at`), kontrak `gatewayFailureResponse()`, perubahan visibility `private` → `protected` (seam uji, bukan perubahan perilaku).
- `index.php`: siklus hidup kunci (dibuang saat isi berubah/berhasil/`OPERATION_ID_REUSED`, dipertahankan pada keadaan ambigu).
- SEC-001/SEC-002 pada seluruh kode baru; tidak ada `@ts-ignore`/skip (Floor-Guard).

## 7. Catatan yang mudah terlewat

| Kode | Ringkas |
| --- | --- |
| P-24 (log Fase 5) | Working copy AuliaPos sudah menjalankan kode Fase 4 sejak 18:28 sementara kolom baru ada 19:08 → jendela ±40 menit; terukur tidak ada kirim kasir di jendela itu. |
| P-26 | Deploy Gateway **wajar** menambah `incoming_queue.dead_lettered_at` + tabel `outgoing_operations` di SQLite live (additive, CON-006) — bukan kejutan. |
| K-13 | Percobaan pertama setiap putaran berakhir gagal di AuliaPos; keadaan "hasil belum pasti" muncul pada kirim ulang. |
| DBDebug | Grup DB `inbox` memakai `DBDebug = true` → kesalahan DB tampil sebagai HTTP 500, bukan gagal senyap. |
| MD056 | Empat baris plan (TASK-011..TASK-014) punya sel berlebih sejak sesi Fase 3; diperbaiki di commit `66a0d8a` (isi tidak berubah). |
| Push | Kedua repo **belum di-push** (AuliaPos `+18`, Gateway `+8`); push butuh perintah eksplisit pemilik. |

## 8. Sumber referensi

- `docs/decisions/2026-09-24-m1-wave2-phase5-deploy.md` — deploy, batas, pengukuran nyata (§8).
- `docs/decisions/2026-09-24-m1-wave2-phase4-aulias-pos-caller.md` — sisi AuliaPos, F-03/F-01, ringkasan commit.
- `docs/decisions/2026-09-24-m1-wave2-phase3-incoming-dead-letter.md` — dead-letter antrean masuk.
- `docs/audit/clarification-report-m1-wave2-outgoing-idempotency-plan-2026-09-24.md` — K-04/K-08/K-10/K-13.
- `build/delay-proxy.js` + `build/scratch-delay-proxy-check.js` — alat ukur (gitignored, dapat dijalankan ulang).
- `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` v1.2 — pola DEPLOY/VERIFY gelombang 1.
