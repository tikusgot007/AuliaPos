---
goal: Teruskan Media-Bound Follow-up Remediation — separate prefetch ingest bound from display bound and stop retrying deterministic 413
version: 1.1
date_created: 2026-09-28
last_updated: 2026-09-28
owner: AuliaPos Inbox module
status: "Completed"
tags: ["refactor", "clean-code", "architecture", "security", "teruskan", "media-bound"]
---

# Introduction

![Status: Completed](https://img.shields.io/badge/status-Completed-brightgreen)

Rencana remediasi hasil `/sdlc-code-review` **Two-Axis** atas refactor media-bound Teruskan (commit `d29e346` → `2053f63`, branch `v2.3`) — kelanjutan `plan-refactor-teruskan-media-bound-v1.0.md` (Completed).

Review menyimpulkan implementasi **patuh plan v1.0 dan patuh spec** (Phase 1/2/3 terverifikasi; suite `OK (677 tests, 2688 assertions)` exit 0 direproduksi; loopback behavior test `2 tests, 7 assertions` nyata; JS checks 15/15 PASS; `spec-design-teruskan.md` tidak tersentuh). Satu temuan `[REQUIRED]` tersisa: `callGatewayMediaDownload()` default-nya kini **batas unduh/tampilan** (`maxMediaDownloadMb`, 100MB), dan prefetch media saat webhook pesan masuk (`InboxGatewayApi::messages()`) memakai default itu — padahal prefetch adalah **jalur ingest tak terpercaya** yang mem-buffer+men-dekripsi+meyimpan ke disk. Batasnya naik dari 15MB (batas unggah lama) menjadi 100MB, memperbesar permukaan *Denial of Service* (memori + disk) ~6,7x per pesan masuk, sementara `RISK-702` hanya menyebut "transfer dibatalkan saat mengalir" (yang membatasi *overrun*, bukan *peak buffer*).

Rencana ini **tidak mengubah requirement apa pun** dan **tidak menyentuh `spec/spec-design-teruskan.md`, PRD, atau `docs/adr/0002-*`**: seluruh pekerjaan adalah pemisahan sumbu batas ingest, penghentian retry deterministik, dan penguatan isolasi test.

## 1. Traceability: Requirements & Constraints

- **REQ-801**: Prefetch media saat webhook pesan masuk (`InboxGatewayApi::messages()`) WAJIB memakai batas byte tersendiri yang **tidak lebih longgar** dari perilaku sebelumnya (15MB), terpisah dari batas unduh/tampilan (`maxMediaDownloadMb`). Ref: review Axis A (PERF-01).
- **SEC-801**: Bound transfer di jalur ingest WAJIB tetap membatalkan transfer **selama** mengalir (SEC-602 tidak melemah) dan tetap membalaskan klasifikasi `413` deterministik (COR-701 tetap berlaku). Ref: review Axis A (PERF-01).
- **CLN-801**: `413` (lampiran melebihi batas unduh) adalah kondisi **deterministik**, bukan sementara — UI WAJIB berhenti mencoba ulang, tanpa menghilangkan label eksplisit "Lampiran terlalu besar untuk ditampilkan (batas NMB)". Ref: review Axis A (OPTIONAL CLN-01).
- **TEST-801**: Bound prefetch WAJIB punya test perilaku/parameter nyata (bukan hanya asersi default konstanta). Ref: review Axis A (verify-the-verification).
- **TEST-802**: Test konfigurasi WAJIB mengisolasi env ambien supaya tidak rapuh terhadap `.env` pengembang. Ref: review Axis A (NIT TEST-01).
- **CON-801**: DILARANG mengubah `spec/spec-design-teruskan.md`, PRD, atau requirement doc.
- **CON-802**: DILARANG mengubah `maxMediaUploadMb` (tetap 15) atau `maxMediaDownloadMb` (tetap 100) — hanya menambah batas ingest baru (aditif/backward-compatible).
- **CON-803**: Perubahan additive/backward-compatible; `vendor/bin/phpunit --no-coverage` keluar kode 0 dan `node tests/js/media-inbox-retry.check.js` lulus.

## 2. Implementation Steps

> **⚠️ EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> Jalankan plan ini phase demi phase. Jalankan task **VERIFY** di akhir setiap phase, lalu **STOP DAN TUNGGU** persetujuan eksplisit owner sebelum lanjut. Definition of Done tiap task: `vendor/bin/phpunit --no-coverage` keluar kode **0**. Jangan menyentuh repo `WA-Gateway`. Jangan mengubah dokumen spec/PRD.

### Implementation Phase 1: Separate Prefetch Ingest Bound (Security / Performance)

- **GOAL-801**: Kembalikan bound jalur ingest tak terpercaya ke tingkat aman tanpa menyentuh batas unduh/tampilan (100MB) atau batas unggah (15MB).

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                                                                                                                          | Ref ID  | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------- | :-------: | :--: |
| TASK-801 | `app/Config/Inbox.php` — tambah `public int $maxMediaPrefetchMb = 15;` + pembacaan `env('inbox.maxMediaPrefetchMb')`. Dokumentasikan sebagai "batas byte yang boleh diunduh + disimpan ke disk oleh prefetch saat webhook pesan masuk (jalur tak terpercaya); JANGAN dipakai untuk tampilan atau kirim".       | REQ-801 |    [x]    | 2026-09-28 |
| TASK-802 | `app/Controllers/InboxGatewayApi.php:358` — panggil `callGatewayMediaDownload(..., 8, maxBytes: $config->maxMediaPrefetchMb * 1024 * 1024)` (named argument) supaya prefetch memakai batas ingest, bukan default unduh 100MB.                     | REQ-801 |    [x]    | 2026-09-28 |
| TASK-803 | **Micro-Test**: `tests/unit/InboxMediaBoundConfigTest.php` — kunci default `maxMediaPrefetchMb = 15`, env override, dan tegaskan `maxMediaPrefetchMb < maxMediaDownloadMb` (tiga sumbu batas tetap terpisah).                                        | TEST-801 |    [x]    | 2026-09-28 |
| TASK-804 | **Micro-Test**: `tests/session/` (baru atau lanjutkan spy yang ada) — buktikan prefetch webhook memanggil `callGatewayMediaDownload()` dengan `maxBytes` = batas ingest, bukan 100MB; jalur `Inbox::media()` tetap 100MB; jalur Teruskan tetap 15MB. | SEC-801 |    [x]    | 2026-09-28 |
| TASK-805 | **VERIFY**: `vendor/bin/phpunit --no-coverage` (exit 0).                                                                                                                                                                                        | -       |    [x]    | 2026-09-28 |
| TASK-806 | **APPROVAL**: 🛑 Tunggu konfirmasi eksplisit owner sebelum Phase 2.                                                                                                                                                                             | -       |    [x]    | 2026-09-28 |

### Implementation Phase 2: Deterministic 413 Latch & Test Hygiene (Correctness / Low Risk)

- **GOAL-802**: Hentikan percobaan ulang yang sia-sia atas lampiran terlalu besar; rapikan isolasi test.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                                                                                                                                                                                            | Ref ID  | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------- | :-------: | :--: |
| TASK-811 | `app/Views/inbox/index.php` — `catatKegagalanMedia()`: bila `kategori === 'terlalu_besar'`, simpan `cobaan = MEDIA_COBAAN_MAKS` (jatah habis) pada entri `mediaSementara` supaya `bolehCobaLagiMedia()` `false` permanen, **tanpa** memindahkannya ke latch `kadaluarsa` (label eksplisit "terlalu besar" tetap muncul lewat `entriSementara.kategori`).                                                                                             | CLN-801 |    [x]    | 2026-09-28 |
| TASK-812 | **Micro-Test**: `tests/js/media-inbox-retry.check.js` — salin ulang `catatKegagalanMedia()` verbatim + tambah check: `catatKegagalanMedia(k, 'terlalu_besar')` menghasilkan entri dengan `bolehCobaLagiMedia(entri) === false` dan `entri.kategori === 'terlalu_besar'`.                                                 | CLN-801 |    [x]    | 2026-09-28 |
| TASK-813 | **Micro-Test**: `tests/unit/InboxMediaBoundConfigTest.php` — `testDefaultUnduhDanUnggahTerpisah()` isolasi env ambien (`inbox.maxMediaUploadMb`, `inbox.maxMediaDownloadMb`, `inbox.maxMediaPrefetchMb`) di awal dan pulihkan di `finally`.                                                                            | TEST-802 |    [x]    | 2026-09-28 |
| TASK-814 | **VERIFY**: `vendor/bin/phpunit --no-coverage` (exit 0) + `node tests/js/media-inbox-retry.check.js` lulus.                                                                                                                                                                                                        | -       |    [x]    | 2026-09-28 |
| TASK-815 | **APPROVAL**: 🛑 Tunggu konfirmasi eksplisit owner untuk menutup plan.                                                                                                                                                                                                                                             | -       |    [x]    | 2026-09-28 |

## 3. Structural Remedies & Alternatives

- **Struktural (PERF-01)**: tambah sumbu batas ingest ketiga (`maxMediaPrefetchMb`) alih-alih memakai ulang batas unduh. Tiga sumbu eksplisit per arah pemakaian: `maxMediaPrefetchMb` (ingest disk), `maxMediaDownloadMb` (tampilan), `maxMediaUploadMb` (kirim/Teruskan).
- **Struktural (CLN-01)**: pakai ulang mekanisme "jatah percobaan habis" yang sudah ada (`cobaan = MEDIA_COBAAN_MAKS`) untuk membuat `413` non-retryable, sehingga label kategori `terlalu_besar` tidak perlu dipindah ke latch `kadaluarsa`.
- **ALT-801**: Batasi prefetch dengan memakai ulang `maxMediaUploadMb` di call-site (satu baris) — **DITOLAK sebagai pilihan utama**: mengikat ulang jalur ingest ke konstanta unggah (pelanggaran prinsip PRN-701: satu konstanta lintas arah), dan perubahan `maxMediaUploadMb` kelak akan diam-diam mengubah ingest.
- **ALT-802**: Jadikan `terlalu_besar` latch permanen seperti `kadaluarsa` (ubah `Set` → `Map(kunci→kategori)`) — **DITOLAK sebagai pilihan utama**: perubahan lebih besar dari yang diperlukan (YAGNI); mekanisme jatah-habis sudah memberi hasil yang sama.
- **ALT-803**: Pindahkan bound ke luar `callGatewayMediaDownload()` — **DITOLAK**: parameter `?int $maxBytes` sudah benar; yang salah hanya pemanggil prefetch yang tidak memasok batas.

## 4. Dependencies

- **DEP-801**: Tidak ada library baru. cURL/PHP 8.2/CI4 yang sudah ada.

## 5. Files Affected

- **FILE-801**: `app/Config/Inbox.php` — properti `maxMediaPrefetchMb` (TASK-801).
- **FILE-802**: `app/Controllers/InboxGatewayApi.php` — prefetch memakai batas ingest (TASK-802).
- **FILE-803**: `app/Views/inbox/index.php` — latch `terlalu_besar` (TASK-811).
- **FILE-804**: `tests/unit/InboxMediaBoundConfigTest.php`, `tests/js/media-inbox-retry.check.js`, `tests/session/` — test (TASK-803/804/812/813).
- **Tidak menyentuh**: `spec/spec-design-teruskan.md`, PRD, `docs/adr/0002-teruskan-source-visibility-risk-acceptance.md`, `app/Controllers/Inbox.php` (logika bound per-caller sudah benar).

## 6. Testing Strategy

- **TEST-801**: Default + env `maxMediaPrefetchMb`; tiga sumbu batas tetap terpisah dan terurut (prefetch 15 < upload 15 = sama, download 100).
- **TEST-802**: Prefetch webhook memakai batas ingest; `Inbox::media()` memakai batas unduh; jalur Teruskan memakai batas unggah.
- **TEST-803**: `413` di view menghasilkan latch non-retryable dengan label eksplisit dipertahankan.
- **TEST-804**: Regresi penuh: Teruskan teks/lampiran, Balas Pesan, kirim biasa; suite `vendor/bin/phpunit --no-coverage` exit 0 + `node tests/js/media-inbox-retry.check.js` lulus.

## 7. Risks & Rollback Plan

- **RISK-801**: Prefetch kini gagal untuk media 15–100MB → media **tidak** tersimpan di disk lokal, tetapi tampilan tetap berfungsi lewat fallback live-fetch (`Inbox::media()`, bound 100MB). Mitigasi: TASK-804 mengunci perilaku ini dengan test.
- **RISK-802**: Nilai `maxMediaPrefetchMb` terlalu kecil dapat memperbanyak live-fetch on-demand. Mitigasi: dapat dinaikkan via `.env` `inbox.maxMediaPrefetchMb`; default 15 mempertahankan perilaku pra-refactor.
- **RISK-803**: Menurunkan retry `413` menyembunyikan peningkatan batas unduh saat runtime. Mitigasi: latch hanya state JS per-muat-halaman (hilang saat refresh), bukan persist.
- **Rollback**: setiap phase satu commit atomic; `git revert` commit phase terkait; jalankan `vendor/bin/phpunit --no-coverage` setelah rollback.
- **Tidak ada perubahan `CONTEXT.md`; tidak ada ADR baru.**

## 8. Related Specifications / Further Reading

- [`spec-design-teruskan.md`](../spec/spec-design-teruskan.md) (v1.3) — **tidak diubah oleh plan ini**
- [`plan-refactor-teruskan-media-bound-v1.0.md`](./plan-refactor-teruskan-media-bound-v1.0.md) — plan yang direview (Completed)
- `app/Controllers/Inbox.php:652` (`callGatewayMediaDownload`), `app/Controllers/InboxGatewayApi.php:358` (prefetch)
- `docs/CHAT.md` §Limitasi Media (definisi `maxMediaUploadMb` sebagai batas keluar)
