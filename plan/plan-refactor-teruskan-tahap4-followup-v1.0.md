---
goal: Teruskan (Tahap 4) Follow-up Hardening & Cleanup — post-review of 40caf14...ec523fb; bound live-fetch transfer, remove structural smells, record spec-sanctioned risk acceptance
version: 1.0
date_created: 2026-09-28
last_updated: 2026-09-28
owner: AuliaPos Inbox module
status: "Completed"
tags: ["refactor", "clean-code", "architecture", "security", "teruskan", "tahap4"]
---

# Introduction

![Status: Completed](https://img.shields.io/badge/status-Completed-brightgreen)

Rencana lanjutan hasil `/sdlc-code-review` **Two-Axis** atas refactor **Teruskan Tahap 4** (branch `v2.3`, fixed point `40caf14` → HEAD `ec523fb`).

Review menyimpulkan refactor sudah **patuh spec** (0 CRITICAL, 0 REQUIRED fungsional; seluruh REQ/AC, non-stacking, ownership tujuan, all-or-nothing, idempotensi, guard dua arah, batas byte, dan DTO terverifikasi benar tanpa bypass). Rencana ini **tidak mengubah requirement apa pun** dan **tidak menyentuh `spec/spec-design-teruskan.md`**: semua item adalah perapian struktur, diagnostik, cakupan test, dan satu hardening transfer opsional, plus pencatatan risk-acceptance untuk celah yang memang disahkan spec.

## 1. Traceability: Requirements & Constraints

- **REQ-601**: Pertahankan 100% perilaku spec Teruskan/Balas Pesan/kirim biasa/idempotensi; tidak ada perubahan requirement. Ref: review Axis B (no mismatch), CON-101 plan sebelumnya.
- **SEC-601**: Celah visibilitas pesan sumber (IDOR-by-enumeration, `resolveTeruskan()` `Inbox.php:2928`) **disahkan spec** REQ-007/AC-004 → butuh **pencatatan risk-acceptance**, bukan guard kode. Ref: review SEC-401, plan sebelumnya ALT-101.
- **SEC-602**: Unduhan live-fetch WAJIB dibatasi **selama transfer** agar respons Gateway besar tidak ter-buffer penuh sebelum `strlen()` di `:3042`. Ref: review SEC-201b.
- **CLN-601**: Hapus variabel mati `$operationId` di `kirimMedia()` (`:1052-1053`), dibaca ulang di `kirimMediaViaGateway()` (`:1300`). Ref: review CLN-402, SPEC-01.
- **CLN-602**: Perbarui rujukan metode/konteks log basi jalur Teruskan teks (`kirim()` `:977-984`; `:2662`; `:2677`). Ref: review CLN-404, SPEC-02.
- **CLN-603**: Pisahkan pesan error lampiran-lebih-besar (`:2996`) dari pesan gagal-ambil WhatsApp. Ref: review CLN-405.
- **CLN-604**: `bolehDiteruskan()` (`index.php:2315`) dipakai menggerbangi "Balas" (`:2210`) — nama menyesatkan; rename ke predikat netral. Ref: review CLN-401, SPEC-05.
- **CLN-605**: Validasi panjang `caption` browser (`Inbox.php:1080`) di-skip saat `$isForward` (field diabaikan di mode Teruskan). Ref: review SPEC-03.
- **PRN-601**: `TIPE_TERUSKAN_DIIZINKAN` (`:106`) harus **diturunkan** dari `TIPE_TERUSKAN_LAMPIRAN` (`:98`) agar tidak drift. Ref: review ARCH-102, PRN-301.
- **PRN-602 (Opsional)**: Ekstrak orkestrasi replay/persist bersama dari `kirimTeksViaGateway()` (`:2645`) dan `kirimMediaViaGateway()` (`:1295`) untuk mencegah divergensi dedupe. Ref: review ARCH-101.
- **PRN-603 (Opsional)**: Flag argument `bool $isForward` (`:2645`, `:1295`) diganti strategi/aksi bila aksi kirim ketiga muncul. Ref: review CLN-403.
- **TEST-601**: Tambah `sticker` ke loop test tolak-sumber-lampiran jalur teks. Ref: review CLN-406.
- **TEST-602**: Uji relasi subset `TIPE_TERUSKAN_LAMPIRAN ⊂ TIPE_TERUSKAN_DIIZINKAN`.
- **CON-601**: Perubahan additive/backward-compatible; `vendor/bin/phpunit --no-coverage` keluar kode 0.
- **CON-602**: DILARANG mengubah `spec/spec-design-teruskan.md`, PRD, atau requirement doc (perintah owner). Perubahan hanya kode, test, dan — bila disetujui — satu ADR risk-acceptance.

## 2. Implementation Steps

> **⚠️ EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> Jalankan plan ini phase demi phase. Jalankan task **VERIFY** di akhir setiap phase, lalu **STOP DAN TUNGGU** persetujuan eksplisit owner sebelum lanjut. Definition of Done tiap task: `vendor/bin/phpunit --no-coverage` keluar kode **0**. Jangan menyentuh repo `WA-Gateway`. Jangan mengubah dokumen spec.

### Implementation Phase 1: Hygiene & Correctness-Adjacent (No Behavior Change)

- **GOAL-601**: Buang dead code, perbaiki diagnostik/komentar basi, dan tutup celah cakupan test tanpa mengubah perilaku yang teruji.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                                   | Ref ID   | Completed | Date |
| -------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- | -------- | :-------: | :--: |
| TASK-601 | `app/Controllers/Inbox.php` — hapus assignment mati `$operationId` di `:1052-1053`; biarkan pembacaan tunggal di `kirimMediaViaGateway()` (`:1300`). | CLN-601  |    [x]    | 2026-09-28 |
| TASK-602 | `app/Controllers/Inbox.php` — perbarui komentar `kirim()` (`:977-984`) dari `kirimKeConversation()` menjadi `kirimTeruskanTeks()`; ubah konteks `pastikanGatewaySiap($config, ...)` `:2662` dan `gatewayFailureResponse(..., ..., ...)` `:2677` menjadi `'kirimTeruskanTeks'` saat `$isForward`. | CLN-602  |    [x]    | 2026-09-28 |
| TASK-603 | `app/Controllers/Inbox.php` — di `bacaByteMediaTeruskan()` tambah pesan khusus lampiran-lebih-besar (mis. `PESAN_TERUSKAN_MEDIA_TERLALU_BESAR`) untuk cabang `strlen > $maxBytes` (`:2996`, `:3045`), menggantikan pemakaian pesan gagal-ambil. | CLN-603  |    [x]    | 2026-09-28 |
| TASK-604 | `app/Controllers/Inbox.php` — gerbangi validasi `caption` `:1080` dengan `if (! $isForward && $caption !== '' && strlen($caption) > 1024)`. | CLN-605  |    [x]    | 2026-09-28 |
| TASK-605 | `tests/session/InboxTeruskanTest.php` — tambah `'sticker'` pada loop `testSumberLampiranLewatEndpointKirimDitolakDengan400` (saat ini `image`,`document`). | TEST-601 |    [x]    | 2026-09-28 |
| TASK-606 | **Micro-Test**: jalankan `--filter "InboxTeruskan"` pastikan test baru hijau; pastikan tidak ada test lama berubah arti. | TEST-601 |    [x]    | 2026-09-28 |
| TASK-607 | **VERIFY**: `vendor/bin/phpunit --no-coverage` (exit 0) + manual: Teruskan teks/lampiran, Balas teks/media, kirim biasa tetap sukses; pesan error lampiran terlalu besar tampil beda dari gagal-ambil. | -        |    [x]    | 2026-09-28 |
| TASK-608 | **APPROVAL**: 🛑 Tunggu konfirmasi eksplisit owner sebelum Phase 2.                                                                                  | -        |    [x]    | 2026-09-28 |

### Implementation Phase 2: Single-Source & Naming (Low Risk)

- **GOAL-602**: Hilangkan duplikasi sumber daftar tipe dan nama predikat yang menyesatkan.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                                        | Ref ID   | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------- | :-------: | :--: |
| TASK-611 | `app/Controllers/Inbox.php` — definisikan `TIPE_TERUSKAN_DIIZINKAN` via `array_merge(['text'], self::TIPE_TERUSKAN_LAMPIRAN)` (atau helper) sehingga `:98` menjadi satu sumber kebenaran. Jaga nilai final identik = `['text','image','document','sticker']`. | PRN-601  |    [x]    | 2026-09-28 |
| TASK-612 | `tests/unit/` — tambah test penjaga relasi subset: setiap anggota `TIPE_TERUSKAN_LAMPIRAN` ada di `TIPE_TERUSKAN_DIIZINKAN`, dan `'text'` hanya di yang kedua. Gunakan reflection terhadap konstanta. | TEST-602 |    [x]    | 2026-09-28 |
| TASK-613 | `app/Views/inbox/index.php` — rename `bolehDiteruskan()` (`:2315`) ke predikat netral (mis. `aksiPesanTersedia()`); perbarui pemanggil `renderAksiBalas()` (`:2210`) dan aksi Teruskan; sinkronkan salinan VERBATIM `tests/js/operation-id-composer.check.js` bila terdampak, lalu `node tests/js/operation-id-composer.check.js`. | CLN-604  |    [x]    | 2026-09-28 |
| TASK-614 | **VERIFY**: `vendor/bin/phpunit --no-coverage` (exit 0) + manual: blok aksi Balas/Teruskan & label "Diteruskan" tetap benar; opsi Teruskan audio/video tetap disabled berlabel alasan. | -        |    [x]    | 2026-09-28 |
| TASK-615 | **APPROVAL**: 🛑 Tunggu konfirmasi eksplisit owner sebelum Phase 3.                                                                                       | -        |    [x]    | 2026-09-28 |

### Implementation Phase 3: Security Hardening & Structural Extraction (Optional, Higher Risk)

- **GOAL-603**: Batasi transfer unduhan live-fetch, catat risk-acceptance celah sumber, dan (opsional) satukan orkestrasi kirim teks/media. **Phase ini boleh dipecah atau dilewati sebagian atas keputusan owner.**

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                                     | Ref ID   | Completed | Date |
| -------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- | -------- | :-------: | :--: |
| TASK-621 | `app/Controllers/Inbox.php` — `callGatewayMediaDownload()` (`:631-682`): tambah `CURLOPT_MAXFILESIZE` = `$maxBytes` dan/atau `CURLOPT_WRITEFUNCTION` yang membatalkan transfer saat akumulasi byte melampaui `$maxBytes`; pertahankan pesan error yang sudah ada. | SEC-602  |    [x]    | 2026-09-28 |
| TASK-622 | **Micro-Test**: `tests/session/InboxTeruskanMediaTest.php` — simulasikan respons Gateway melebihi `maxMediaUploadMb` → `400` tanpa baris tersimpan, Gateway send tak dipanggil. | SEC-602  |    [x]    | 2026-09-28 |
| TASK-623 | **Opsional**: `app/Controllers/Inbox.php` — ekstrak orkestrator bersama (mis. `kirimDanPersist(...)`) yang menangani baca `operation_id`, replay lookup, insert, `updateLastMessageIfNewer`, dan `withForwardMarker(withQuoteApplied(...))`; `kirimTeksViaGateway()` (`:2645`) & `kirimMediaViaGateway()` (`:1295`) hanya membentuk payload + field persist. Tidak mengubah perilaku. | PRN-602  |    [-]    | 2026-09-28 |
| TASK-624 | **Micro-Test**: tambah test paritas replay/dedupe: kirim media & teks dengan `operation_id` sama → tetap satu baris (tidak ada regresi idempotensi). | PRN-602  |    [-]    | 2026-09-28 |
| TASK-625 | **Risk Acceptance (tanpa kode)**: buat ADR `docs/adr/` (lazy, hanya bila owner setuju) yang mencatat celah visibilitas sumber Teruskan sebagai risiko diterima dengan alasan spec REQ-007/AC-004, plus mitigasi operasional (audit log `resolveTeruskan` sudah ada di `:2931`/`:2953`). **Jangan** menambah guard kepemilikan sumber. | SEC-601  |    [x]    | 2026-09-28 |
| TASK-626 | **VERIFY**: `vendor/bin/phpunit --no-coverage` (exit 0) + `--filter "InboxTeruskan|InboxOutgoing"` hijau + manual smoke lengkap (teruskan teks/gambar, balas berkutipan, kirim biasa). | -        |    [x]    | 2026-09-28 |
| TASK-627 | **APPROVAL**: 🛑 Tunggu konfirmasi eksplisit owner untuk menutup plan.                                                                                   | -        |    [x]    | 2026-09-28 |

## 3. Structural Remedies & Alternatives

- **ALT-601**: Menambah `cekOwnership()`/cek visibilitas pada **percakapan sumber** Teruskan — **DITOLAK**: melanggar REQ-007/AC-004 spec secara eksplisit. Celah dicatat sebagai risk-acceptance (SEC-601/TASK-625), bukan diperbaiki di kode.
- **ALT-602**: Ganti flag `bool $isForward` dengan objek aksi penuh sekarang — **DITOLAK (ditunda)**: hanya dua pemanggil per metode; YAGNI sampai aksi ketiga muncul (PRN-603).
- **ALT-603**: Pisah seluruh blok kirim `Inbox.php` ke service baru (`InboxSendService`) — **DITOLAK untuk plan ini**: terlalu besar untuk follow-up; dicatat sebagai kandidat refactor terpisah (review ARCH-103, file >3400 baris).
- **Struktural**: nilai final konstanta tetap sama; `TIPE_TERUSKAN_DIIZINKAN` diturunkan (PRN-601), predikat view dinamai netral (CLN-604), pesan error dipisah (CLN-603).

## 4. Dependencies

- **DEP-601**: Tidak ada library baru. Semua memakai cURL/PHP/CI4 yang sudah ada.
- **DEP-602**: `WA-Gateway` di luar scope; kontrak `forward` + `forward_marker_applied` tidak berubah.
- **DEP-603**: Tidak ada perubahan skema/migrasi.

## 5. Files Affected

- **FILE-601**: `app/Controllers/Inbox.php` — dead code (CLN-601), komentar/konteks log (CLN-602), pesan error (CLN-603), gate caption (CLN-605), derivasi konstanta (PRN-601), batas transfer cURL (SEC-602), ekstraksi opsional (PRN-602).
- **FILE-602**: `app/Views/inbox/index.php` — rename predikat (CLN-604).
- **FILE-603**: `tests/session/InboxTeruskanTest.php`, `tests/session/InboxTeruskanMediaTest.php`, `tests/unit/` — test penjaga (TEST-601, TEST-602, SEC-602, PRN-602).
- **FILE-604**: `tests/js/operation-id-composer.check.js` — hanya bila rename menyentuh salinan verbatim.
- **FILE-605**: `docs/adr/NNNN-teruskan-source-visibility-risk-acceptance.md` — **hanya bila owner setuju** (TASK-625).
- **Tidak menyentuh**: `spec/spec-design-teruskan.md`, `plan/plan-feature-teruskan-auliapos-v1.0.md`, `plan/plan-refactor-teruskan-tahap4-v1.0.md`.

## 6. Testing Strategy

- **TEST-601**: `sticker` via `/inbox/kirim` → `400`, tanpa insert, Gateway tak dipanggil.
- **TEST-602**: Relasi subset konstanta tipe forwardable.
- **TEST-603**: Respons Gateway melebihi `maxMediaUploadMb` → `400` tanpa insert (SEC-602).
- **TEST-604**: Paritas idempotensi replay teks vs media (`operation_id` sama → satu baris).
- **TEST-605**: Regresi penuh: Teruskan teks/lampiran, Balas Pesan (teks & media), kirim biasa; suite `vendor/bin/phpunit --no-coverage` exit 0.
- **CATATAN**: Label "Diteruskan" di WhatsApp tetap hanya dapat diverifikasi manual.

## 7. Risks & Rollback Plan

- **RISK-601**: `CURLOPT_MAXFILESIZE` tidak selalu dihormati tanpa `Content-Length`; mitigasi: pasang juga `CURLOPT_WRITEFUNCTION` penghitung byte sebagai pengaman utama.
- **RISK-602**: Ekstraksi orkestrasi (PRN-602) menyentuh kedua jalur kirim; mitigasi: langkah kecil + suite penuh tiap task; rollback = `git revert` commit phase.
- **RISK-603**: Rename `bolehDiteruskan()` bisa memutus referensi verbatim di file JS/test; mitigasi: grep menyeluruh sebelum rename dan jalankan `node tests/js/operation-id-composer.check.js`.
- **RISK-604**: Menambah ADR risk-acceptance tanpa persetujuan owner → jangan buat bila owner menolak (TASK-625 opsional).
- **Rollback**: setiap phase satu commit atomic; `git revert` commit phase terkait; jalankan `vendor/bin/phpunit --no-coverage` setelah rollback.
- **Tidak ada perubahan `CONTEXT.md`; ADR hanya bila SEC-601 diterima formal.**

## 8. Related Specifications / Further Reading

- [`spec-design-teruskan.md`](../spec/spec-design-teruskan.md) (v1.3) — **tidak diubah oleh plan ini**
- [`plan-refactor-teruskan-tahap4-v1.0.md`](./plan-refactor-teruskan-tahap4-v1.0.md) — plan yang direview (Completed)
- [`plan-feature-teruskan-auliapos-v1.0.md`](./plan-feature-teruskan-auliapos-v1.0.md)
- `docs/ARCHITECTURE.md` §Inbox module

## 9. Execution Log

- **Date:** 2026-09-28 — branch `v2.3`; executor `/sdlc-write-code`.
- **Phase 1 (Hygiene):** TASK-601..605 implemented; TASK-606 filtered `InboxTeruskan` green; TASK-607 full suite `OK (662 tests, 2637 assertions)` exit 0. Owner approved (TASK-608). Side effect: `testByteLiveFetchMelebihiBatasDitolak400` assertion moved to the new oversize message introduced by TASK-603.
- **Phase 2 (Single-source & naming):** TASK-611 derives the constant via spread; TASK-612 adds `tests/unit/InboxTeruskanTipeKonstantaTest.php` (3 tests); TASK-613 renames the predicate to `aksiPesanTersedia()` in the view and the screen test, with `node tests/js/operation-id-composer.check.js` passing; TASK-614 full suite `OK (665 tests, 2649 assertions)` exit 0. Owner approved (TASK-615).
- **Phase 3 (partial, per owner decision):** TASK-621 bounds the download transfer (`CURLOPT_WRITEFUNCTION` + `CURLOPT_MAXFILESIZE`, overflow returns `status 413`); TASK-622 adds a behavioural test (413 becomes `400`) plus a static source guard; TASK-623/624 (PRN-602 extraction) deliberately **skipped** as optional; TASK-625 adds ADR `docs/adr/0002-teruskan-source-visibility-risk-acceptance.md` (Accepted). TASK-626 full suite `OK (667 tests, 2659 assertions)` exit 0. Owner approved closure (TASK-627).
- **Behaviour change (disclosed):** `GET /inbox/media/(:num)` now answers HTTP `413` when the file exceeds `maxMediaUploadMb`, because the transfer bound applies to every caller of `callGatewayMediaDownload()`.
- **Environment finding (not caused by this plan):** MariaDB refused `ALTER TABLE messages` with errno 1118 (`Row size too large ... 8126`) because the test table lacked an explicit `ROW_FORMAT=DYNAMIC`; fixed by pinning the row format on `aulia_inboxdb_test.messages` only. The live schema still relies on the implicit default, so a future production migration may hit the same wall and needs a separate fix.
- **Not verified manually:** the WhatsApp "Diteruskan" label and the live forward/quote smoke (requires a running Gateway).
- **Untouched:** `spec/spec-design-teruskan.md` (v1.3), the PRD, and every requirement document.

