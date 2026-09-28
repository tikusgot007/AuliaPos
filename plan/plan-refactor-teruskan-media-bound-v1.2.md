---
goal: Teruskan Media-Bound Review Round 3 — complete the config-test env isolation required by TEST-802 and harden the new ingest bound
version: 1.2
date_created: 2026-09-28
last_updated: 2026-09-28
owner: AuliaPos Inbox module
status: "Completed"
tags: ["refactor", "clean-code", "architecture", "security", "teruskan", "media-bound"]
---

# Introduction

![Status: Completed](https://img.shields.io/badge/status-Completed-brightgreen)

Rencana remediasi hasil `/sdlc-code-review` **Two-Axis** atas eksekusi `plan-refactor-teruskan-media-bound-v1.1.md` (commit `2053f63..19e4680`, branch `v2.3`).

Review menyimpulkan implementasi v1.1 **patuh plan dan patuh spec** pada seluruh sumbu fungsional: batas ingest prefetch (`maxMediaPrefetchMb`, 15MB) terpisah dari batas unduh/tampilan (100MB) dan batas unggah (15MB); `413` kini non-retryable lewat mekanisme jatah-habis yang sudah ada; label eksplisit "terlalu besar" dipertahankan. Suite direproduksi **OK (681 tests, 2705 assertions), exit 0**; `node tests/js/media-inbox-retry.check.js` **16 PASS**.

Dua temuan `[REQUIRED]` (satu akar, dilaporkan terpisah per sumbu sesuai model Two-Axis) dan satu gugus temuan opsional/nit tersisa:

- **Akar temuan `[REQUIRED]`:** `tests/unit/InboxMediaBoundConfigTest.php::testDefaultPrefetchBoundTerkunciDanTerpisah()` (baris 72-82) membangun `new InboxConfig()` dan meng-assert nilai default **tanpa** mengisolasi env ambien — padahal `TEST-802` di plan v1.1 mewajibkan isolasi env untuk test konfigurasi, dan test tetangganya (`testDefaultUnduhDanUnggahTerpisah()`, baris 17-42) sudah mengisolasinya. Hari ini test tetap hijau karena `.env:28` masih berupa komentar (`# inbox.maxMediaPrefetchMb = 15`); ia hanya merah kalau pengembang meng-uncomment/mengekspor override. Cacat laten, bukan kegagalan aktif.
- **Temuan opsional:** env `maxMediaPrefetchMb` tidak di-clamp (nilai `0`/negatif/raksasa diam-diam mematikan atau melumpuhkan kontrol DoS yang baru dibuat); konversi MB→byte terduplikasi di 4 titik; test loopback baru punya risiko port TOCTOU + `markTestSkipped` yang bisa menyembunyikan non-coverage; pipe `proc_open` tidak dikuras; sentinel `MEDIA_COBAAN_MAKS` dipinjam untuk makna "non-retryable"; serta beberapa nit dokumentasi/lintas-referensi.

Rencana ini **tidak mengubah requirement apa pun** dan **tidak menyentuh `spec/spec-design-teruskan.md`, PRD, atau `docs/adr/0002-*`**.

## 1. Traceability: Requirements & Constraints

- **REQ-901** `[REQUIRED]`: Setiap test konfigurasi yang meng-assert nilai **default** `Config\Inbox` WAJIB mengisolasi env ambien (`inbox.maxMediaUploadMb`, `inbox.maxMediaDownloadMb`, `inbox.maxMediaPrefetchMb`) dan memulihkannya; hasil test tidak boleh bergantung pada `.env` pengembang. Ref: plan v1.1 `TEST-802`; review Axis A (CS-01), Axis B (SPEC-001).
- **SEC-901** `[OPTIONAL]`: Nilai env batas media (khususnya `maxMediaPrefetchMb`, sumbu kontrol DoS) WAJIB divalidasi/di-clamp ke rentang sah supaya nilai salah-ketik tidak diam-diam melumpuhkan (`0`/negatif → semua prefetch `413`) atau menonaktifkan (`>& maxMediaDownloadMb`) kontrol yang dimaksudkan. Ref: review Axis A (SEC-01).
- **CLN-901** `[OPTIONAL]`: Konversi MB→byte WAJIB punya satu sumber (helper), bukan literalin `* 1024 * 1024` di empat call-site. Ref: review Axis A (CS-03), prinsip PRN-701 plan v1.1.
- **CLN-902** `[NIT]`: Makna "deterministik / non-retryable" JANGAN dipinjam dari sentinel `cobaan = MEDIA_COBAAN_MAKS`; nyatakan eksplisit agar perubahan `MEDIA_COBAAN_MAKS` kelak tidak diam-diam mengubah semantik retry. Ref: review Axis A (CS-02).
- **TEST-901** `[OPTIONAL]`: Test loopback `InboxPrefetchIngestBoundTest` WAJIB tidak rawan port TOCTOU, tidak menyembunyikan non-coverage di balik `markTestSkipped`, dan tidak meninggalkan pipe `proc_open` yang belum dikuras. Ref: review Axis A (SEC-02, SEC-03).
- **DOC-901** `[NIT]`: Docblock `Inbox::callGatewayMediaDownload()` yang menyatakan default `$maxBytes` "dipakai `Inbox::media()` dan prefetch pesan masuk" sudah tidak akurat setelah prefetch memasok batas sendiri. Ref: review Axis B (SPEC-002).
- **CON-901**: DILARANG mengubah `spec/spec-design-teruskan.md`, PRD, `docs/adr/0002-*`, atau `plan-refactor-teruskan-media-bound-v1.1.md` (plan lama sudah `Completed`, anti-overwrite).
- **CON-902**: DILARANG mengubah nilai default `maxMediaUploadMb` (15) dan `maxMediaDownloadMb` (100); `maxMediaPrefetchMb` tetap default 15.
- **CON-903**: Perubahan additive/backward-compatible; `vendor/bin/phpunit --no-coverage` keluar kode 0 dan `node tests/js/media-inbox-retry.check.js` lulus.
- **CON-904**: Repo `WA-Gateway` tidak disentuh.

## 2. Implementation Steps

> **⚠️ EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> Jalankan plan ini phase demi phase. Jalankan task **VERIFY** di akhir setiap phase, lalu **STOP DAN TUNGGU** persetujuan eksplisit owner sebelum lanjut. Definition of Done tiap task: `vendor/bin/phpunit --no-coverage` keluar kode **0**. Jangan menyentuh repo `WA-Gateway`. Jangan mengubah dokumen spec/PRD/ADR/plan lama.

### Implementation Phase 1: Complete the Required Config-Test Isolation (Correctness / Traceability)

- **GOAL-901**: Tuntaskan `TEST-802` — semua test default konfigurasi tahan terhadap `.env` pengembang, tanpa mengubah perilaku produksi.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                                                                                                                            | Ref ID  | Completed | Date |
| -------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------- | :-------: | :--: |
| TASK-901 | `tests/unit/InboxMediaBoundConfigTest.php:72-82` — bungkus `testDefaultPrefetchBoundTerkunciDanTerpisah()` dengan pola isolasi env yang sudah dipakai test tetangga: simpan tiga kunci `inbox.maxMedia*`, `unset(...)`, lalu `try { new InboxConfig(); ...assertSame/assertLessThan... } finally { pulihkanEnv(...) }`. Gunakan helper `pulihkanEnv()` yang sudah ada (baris 106-116) — JANGAN menambah helper kedua. **[DEVIATION-901: helper `pulihkanEnv()` diperluas ke TIGA kanal karena `unset($_ENV)` saja tidak mengisolasi (bukti TASK-903). Tidak ada helper kedua.]** | REQ-901 |    [x]    | 2026-09-28 |
| TASK-902 | **Micro-Test (bukti isolasi):** tambah satu test penjaga di file yang sama, `testDefaultPrefetchTetapTerkunciWalauEnvMengOverride()`: set `$_ENV['inbox.maxMediaPrefetchMb'] = '7'`, bangun `InboxConfig`, buktikan env override MEMANG terbaca (7) lalu pulihkan; lalu bangun ulang setelah `unset` dan buktikan default 15 kembali. Tujuan: membuktikan isolasi bekerja dua arah (env override tetap dihormati; default tetap 15 saat env bersih), bukan sekadar menghapus env. | REQ-901 |    [x]    | 2026-09-28 |
| TASK-903 | **VERIFY**: jalankan `vendor/bin/phpunit --no-coverage --filter InboxMediaBoundConfigTest` (exit 0), lalu suite penuh `vendor/bin/phpunit --no-coverage` (exit 0, jumlah test ≥ 681 + test baru). Jalankan juga dengan env kotor untuk membuktikan isolasi: `cmd /c "set inbox.maxMediaPrefetchMb=7&&vendor\bin\phpunit --no-coverage --filter InboxMediaBoundConfigTest"` — WAJIB tetap hijau. Catat bukti mentah di blok Execution Log. | -       |    [x]    | 2026-09-28 |
| TASK-904 | **APPROVAL**: 🛑 Tunggu konfirmasi eksplisit owner sebelum Phase 2.                                                                                                                                                                                | -       |    [x]    | 2026-09-28 |

### Implementation Phase 2: Optional Hardening (Security / Hygiene)

- **GOAL-902**: Tutup gugus temuan opsional/nit tanpa mengubah perilaku yang sudah lulus review. Setiap task boleh dilewati atas keputusan owner; yang dilewati ditandai `[-]` dengan alasan, bukan `[x]` palsu.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                                                                                                                                                                          | Ref ID   | Completed | Date |
| -------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------- | :-------: | :--: |
| TASK-911 | `app/Config/Inbox.php:112-114` — clamp nilai env batas media: setelah cast `(int)`, bila hasil `< 1` atau `> maxMediaDownloadMb` (untuk prefetch) maka jatuh ke default + `log_message('warning', ...)`. Nilai sah tidak berubah; default 15/100/15 tidak berubah. Micro-test: test baru di `InboxMediaBoundConfigTest` — env `0`, env `-5`, env `100000` masing-masing menghasilkan nilai ter-clamp + default yang benar. Contoh sederhana: env `inbox.maxMediaPrefetchMb = 0` → `maxMediaPrefetchMb` menjadi 15 (BUKAN 0 yang membuat semua prefetch gagal `413` diam-diam). **[DEVIATION-911: `BaseConfig::__construct()` sudah menimpa properti dari env mentah, jadi default deklarasi direkam sebelum `parent::__construct()`. Batas atas hanya untuk prefetch (upload/download hanya batas bawah) sesuai teks plan.]** | SEC-901  |    [x]    | 2026-09-28 |
| TASK-912 | `app/Controllers/Inbox.php` — ekstrak helper privat murni `mbKeByte(int $mb): int` dan pakai di tiga titik (`:671`, `:1164`, `:3070`) + `app/Controllers/InboxGatewayApi.php:367`; nilai numerik HARUS identik (`* 1024 * 1024`). Micro-test: unit test `tests/unit/InboxMbKeByteTest.php` (nilai 0/1/15/100) via reflection. **[DEVIATION-912: helper dibuat `public static` (plan menulis "privat") karena `InboxGatewayApi` bukan subclass Inbox dan harus memakai sumber yang sama; tetap satu helper, tanpa duplikasi literalin.]** | CLN-901  |    [x]    | 2026-09-28 |
| TASK-913 | `app/Views/inbox/index.php:2602-2626` + salinan VERBATIM `tests/js/media-inbox-retry.check.js` — tambahkan field eksplisit `nonRetryable: true` pada entri `terlalu_besar` dan periksa field itu LEBIH DULU di `bolehCobaLagiMedia()`; `cobaan = MEDIA_COBAAN_MAKS` boleh tetap sebagai pengaman kedua. Micro-test: check JS baru — entri `terlalu_besar` punya `nonRetryable === true` dan `bolehCobaLagiMedia()` `false`; entri `sementara` tidak punya/menyetel `false`. | CLN-902  |    [x]    | 2026-09-28 |
| TASK-914 | `tests/session/InboxPrefetchIngestBoundTest.php` — kurangi kerapuhan test loopback: (a) `cariPort()` jangan menutup socket probe sebelum `php -S` sempat mencoba bind, atau tambah retry bind sekali; (b) alihkan stdio `proc_open` ke berkas log sementara (`1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']`) supaya pipe tidak perlu dikuras, dan hapus file log di `hentikanServer()`; (c) pertahankan `markTestSkipped` HANYA untuk lingkungan tanpa `proc_open`/loopback (didokumentasikan), dan pastikan pesan skip menyebut bahwa bound ingest tidak tervalidasi di run itu. | TEST-901 |    [x]    | 2026-09-28 |
| TASK-915 | `app/Controllers/Inbox.php:643-648` — perbarui docblock `callGatewayMediaDownload()`: default `$maxBytes = null` = batas **unduh/tampilan** (`maxMediaDownloadMb`) yang masih dipakai `Inbox::media()`; prefetch pesan masuk kini memasok batas ingest (`maxMediaPrefetchMb`) secara eksplisit. Komentar saja, nol perubahan perilaku. | DOC-901  |    [x]    | 2026-09-28 |
| TASK-916 | **VERIFY**: `vendor/bin/phpunit --no-coverage` (exit 0) + `node tests/js/media-inbox-retry.check.js` + `node tests/js/operation-id-composer.check.js` lulus; recount test suite dan catat di Execution Log. | -        |    [x]    | 2026-09-28 |
| TASK-917 | **APPROVAL**: 🛑 Tunggu konfirmasi eksplisit owner untuk menutup plan.                                                                                                                                                                                | -        |    [x]    | 2026-09-28 |

## 3. Structural Remedies & Alternatives

- **Struktural (REQ-901)**: mengekstrak pola isolasi yang sudah ada menjadi helper privat kelas (`isolasiEnvBatasMedia()`), lalu memakainya di ketiga test default — satu sumber pola, bukan salin-tempel ketiga kalinya.
- **Struktural (SEC-901)**: validasi terpusat di `__construct()` (bukan di call-site) supaya semua pemakai `Config\Inbox` mendapat nilai sah yang sama.
- **ALT-901** `[DITOLAK]` Menghapus asersi default dan hanya menguji env override — menghilangkan penjaga yang justru menjadi tujuan `TEST-802`/`RISK-703`.
- **ALT-902** `[DITOLAK]` Clamp hanya `maxMediaPrefetchMb` dan membiarkan `maxMediaUploadMb`/`maxMediaDownloadMb` apa adanya — konsisten dengan semangat review bahwa kelas cacat yang sama diperbaiki sekali di satu tempat (`__construct()`), bukan tambal per-kunci.
- **ALT-903** `[DITOLAK]` Mengganti `markTestSkipped` dengan hard-fail di semua lingkungan — akan memecahkan mesin tanpa loopback; kompromi (c) di TASK-914 yang dipilih.
- **ALT-904** `[DITOLAK]` Menyeragamkan kombinasi `MEDIA_COBAAN_MAKS` + `nonRetryable` menjadi flag tunggal — perubahan lebih besar dari yang diperlukan (YAGNI); TASK-913 bersifat aditif di atas mekanisme yang sudah lulus review.

## 4. Dependencies

- **DEP-901**: Tidak ada library baru. cURL/PHP 8.2/CI4 yang sudah ada.

## 5. Files Affected

- **FILE-901**: `tests/unit/InboxMediaBoundConfigTest.php` — isolasi env untuk test default prefetch + test penjaga isolasi (TASK-901/902/911).
- **FILE-902**: `app/Config/Inbox.php` — clamp env batas media (TASK-911).
- **FILE-903**: `app/Controllers/Inbox.php` — helper `mbKeByte()` + docblock (TASK-912/915).
- **FILE-904**: `app/Controllers/InboxGatewayApi.php` — pemakaian `mbKeByte()` (TASK-912).
- **FILE-905**: `app/Views/inbox/index.php` + `tests/js/media-inbox-retry.check.js` — field `nonRetryable` (TASK-913).
- **FILE-906**: `tests/session/InboxPrefetchIngestBoundTest.php` — ketahanan test loopback (TASK-914).
- **FILE-907**: `tests/unit/InboxMbKeByteTest.php` — test unit baru untuk helper konversi (TASK-912).
- **Tidak menyentuh**: `spec/spec-design-teruskan.md`, PRD, `docs/adr/0002-teruskan-source-visibility-risk-acceptance.md`, `plan-refactor-teruskan-media-bound-v1.1.md`, repo `WA-Gateway`.

## 6. Testing Strategy

- **TEST-905**: Test default konfigurasi tetap hijau dengan `$_ENV` bersih DAN dengan `.env`/variabel override aktif (bukti isolasi dua arah).
- **TEST-906**: Env batas media bernilai tidak sah (`0`, negatif, raksasa) jatuh ke nilai sah/default tanpa kegagalan senyap.
- **TEST-907**: `mbKeByte()` mengembalikan nilai identik dengan literalin lama untuk 0/1/15/100MB.
- **TEST-908**: Entri `terlalu_besar` menandai non-retryable secara eksplisit; kategori `sementara` tetap retryable sampai jatah habis.
- **TEST-909**: Regresi penuh — Teruskan teks/lampiran, Balas Pesan, kirim biasa, media masuk: suite `vendor/bin/phpunit --no-coverage` exit 0 + dua skrip JS lulus.

## 7. Risks & Rollback Plan

- **RISK-901**: Clamp env (TASK-911) bisa menolak nilai sah yang disengaja di luar rentang (mis. prefetch 150MB untuk kebutuhan khusus) → nilai di luar rentang kini jatuh ke default dengan peringatan log. Mitigasi: rentang atas mengikuti `maxMediaDownloadMb` yang sendiri dapat dinaikkan lewat env; pesan log menyebut nilai yang ditolak.
- **RISK-902**: Perubahan test (TASK-901/902/914) menyentuh file test bersama; penyebabnya pernah tercatat (Avast mengunci file test di `tests/`) → pastikan berkas test tidak hilang setelah run dan jumlah test tidak turun tanpa penjelasan.
- **Rollback**: setiap phase satu commit atomic; `git revert` commit phase terkait; jalankan `vendor/bin/phpunit --no-coverage` setelah rollback.
- **Tidak ada perubahan `CONTEXT.md`; tidak ada ADR baru.**

## 8. Related Specifications / Further Reading

- [`spec-design-teruskan.md`](../spec/spec-design-teruskan.md) (v1.3) — **tidak diubah oleh plan ini**
- [`plan-refactor-teruskan-media-bound-v1.1.md`](./plan-refactor-teruskan-media-bound-v1.1.md) — plan yang direview (Completed)
- `app/Config/Inbox.php:112-114`, `app/Controllers/Inbox.php:652-759`, `app/Controllers/InboxGatewayApi.php:362-368`, `tests/unit/InboxMediaBoundConfigTest.php:72-116`, `tests/session/InboxPrefetchIngestBoundTest.php:187-289`

## 9. Execution Log

> Diisi oleh `/sdlc-write-code` saat eksekusi. Setiap klaim wajib disertai bukti mentah (perintah + keluaran).

- _(kosong — belum dieksekusi)_

### Phase 1

**TASK-901 + TASK-902 — config-test env isolation (done 2026-09-28, branch `v2.3`)**

Files changed:

- `tests/unit/InboxMediaBoundConfigTest.php`
  - `testDefaultPrefetchBoundTerkunciDanTerpisah()` now saves the three
    `inbox.maxMedia*` keys, clears them, asserts inside `try`, restores in
    `finally`.
  - New guard test `testDefaultPrefetchTetapTerkunciWalauEnvMengOverride()`
    proves both directions: override read as `7`, then clean env yields `15`.
  - Existing `pulihkanEnv()` helper kept (no second helper) but extended to
    restore all three channels `env()` reads.

**DEVIATION-901 (root cause found during TASK-903 VERIFY):** the plan assumed
`unset($_ENV[...])` isolates the ambient env. It does not. CI4
`env()` resolves `$_ENV[$key] ?? $_SERVER[$key] ?? getenv($key)`
(`vendor/codeigniter4/framework/system/Common.php:420`), and CI4 `DotEnv`
writes every key into all three channels (`.../system/Config/DotEnv.php:98-106`).
Under the plan's own dirty-env command the filter run was RED:

```text
$ cmd /c "set inbox.maxMediaPrefetchMb=7&&vendor\bin\phpunit --no-coverage --filter InboxMediaBoundConfigTest"
F.FF.   5 / 5
FAILURES! Tests: 5, Assertions: 11, Failures: 3.
1) testDefaultUnduhDanUnggahTerpisah — 7 is not identical to 15
2) testDefaultPrefetchBoundTerkunciDanTerpisah — 7 is not identical to 15
3) testDefaultPrefetchTetapTerkunciWalauEnvMengOverride — 7 is not identical to 15
```

Fix: snapshot via `env($key)` (already 3-channel aware) and clear
`$_ENV` + `$_SERVER` + `putenv()`; `pulihkanEnv()` restores all three. The
neighbouring `testEnvMenaikkanBatasUnduhTanpaMengubahBatasUnggah()` was
switched to the same helper for consistency (it previously restored
`$_ENV` only). No production code touched; no second helper added.

**TASK-903 — VERIFY (raw evidence)**

```text
$ php -l tests\unit\InboxMediaBoundConfigTest.php
No syntax errors detected in tests\unit\InboxMediaBoundConfigTest.php

$ cmd /c "vendor\bin\phpunit --no-coverage --filter InboxMediaBoundConfigTest"
OK (5 tests, 12 assertions)          # exit 0

$ cmd /c "set inbox.maxMediaPrefetchMb=7&&vendor\bin\phpunit --no-coverage --filter InboxMediaBoundConfigTest"
OK (5 tests, 12 assertions)          # exit 0  (dirty env now GREEN)

$ cmd /c "vendor\bin\phpunit --no-coverage"
OK (682 tests, 2707 assertions)      # exit 0
```

Baseline was 681 tests / 2705 assertions; +1 test / +2 assertions from
TASK-902 equals the expected 682 / 2707.

**TASK-904 — APPROVAL:** waiting for explicit owner approval before Phase 2.

**Recommended plan amendment (not executed):** correct TASK-901's premise to
require all three env channels; the current text "unset(...)" is insufficient
on its own.

### Phase 2 (approved by owner 2026-09-28)

**TASK-911 — clamp env batas media (`app/Config/Inbox.php`)**

- `__construct()` now records the DECLARED defaults (15/100/15) *before*
  `parent::__construct()`, because `Config\BaseConfig::__construct()` already
  overwrites each property with the raw env value (`initEnvValue()`). Using the
  post-parent property as the fallback would have returned the invalid `0`.
- New private `batasiEnvMb(string $kunci, int $default, ?int $maks = null)`:
  value `< 1`, or `> $maks` (prefetch only), falls back to the default and
  logs a `warning`. Upper bound of prefetch follows the resolved
  `maxMediaDownloadMb`; upload/download get the lower bound only (plan text:
  upper bound "untuk prefetch").
- Micro-tests added to `InboxMediaBoundConfigTest`: env `0` and `-5` on all
  three keys fall back to 15/15/100; env `100000` keeps upload as-is, keeps
  download default, and falls the prefetch back to 15; a valid prefetch `150`
  is accepted once download is raised to `200` (RISK-901).

**TASK-912 — `mbKeByte()` (`app/Controllers/Inbox.php`, `InboxGatewayApi.php`)**

- New `public static function mbKeByte(int $mb): int` (one source for
  `* 1024 * 1024`). Call sites replaced: `Inbox.php:671` (download default),
  `:1164` and `:3070` (upload), `InboxGatewayApi.php:367` (prefetch). No
  literal `* 1024 * 1024` remains outside the helper.
- New `tests/unit/InboxMbKeByteTest.php` asserts identity for 0/1/15/100 MB
  via reflection.

**TASK-913 — explicit `nonRetryable` (`app/Views/inbox/index.php` + JS check)**

- `catatKegagalanMedia()` writes `nonRetryable: kategori === 'terlalu_besar'`;
  `bolehCobaLagiMedia()` checks `entri.nonRetryable === true` FIRST, keeping
  `cobaan = MEDIA_COBAAN_MAKS` as the second safeguard.
- The verbatim copy in `tests/js/media-inbox-retry.check.js` was updated, plus
  two checks: `sementara` is not non-retryable, and `nonRetryable` wins over an
  otherwise-permissive `cobaan`/jeda. Result: **18 PASS, 0 FAIL** (was 16).

**TASK-914 — loopback test resilience (`tests/session/InboxPrefetchIngestBoundTest.php`)**

- `mulaiServer()` returns `null` (skip) when `proc_open` is unavailable, and
  retries once with a fresh port to absorb the small probe-port TOCTOU window.
- stdio now goes to a per-attempt log file (`1 => ['file', ...], 2 => [...]`)
  so no pipe needs draining; the log is removed with the temp dir.
- Skip message now states the ingest bound is NOT validated on that run.

**TASK-915 — docblock (`Inbox::callGatewayMediaDownload()`)**

- Corrected: default `$maxBytes = null` = the download/display cap used by
  `Inbox::media()`; prefetch now supplies its own ingest cap.

**TASK-916 — VERIFY (raw evidence)**

```text
$ php -l app\Config\Inbox.php                          -> No syntax errors
$ php -l app\Controllers\Inbox.php                     -> No syntax errors
$ php -l app\Controllers\InboxGatewayApi.php           -> No syntax errors
$ php -l tests\session\InboxPrefetchIngestBoundTest.php-> No syntax errors
$ php -l tests\unit\InboxMediaBoundConfigTest.php      -> No syntax errors
$ php -l tests\unit\InboxMbKeByteTest.php              -> No syntax errors

$ cmd /c "vendor\bin\phpunit --no-coverage"
OK (685 tests, 2722 assertions)                        # exit 0

$ cmd /c "set inbox.maxMediaPrefetchMb=7&&vendor\bin\phpunit --no-coverage --filter InboxMediaBoundConfigTest"
OK (7 tests, 23 assertions)                            # exit 0 (dirty env)

$ node tests/js/media-inbox-retry.check.js             -> 18 PASS, 0 FAIL
$ node tests/js/operation-id-composer.check.js         -> OK (all cases)
```

Count math: 682 (end of Phase 1) + 1 (`InboxMbKeByteTest`) + 2
(`InboxMediaBoundConfigTest` clamp tests) = **685**. Assertions 2707 → 2722.

**TASK-917 — APPROVAL:** waiting for explicit owner confirmation to close the
plan.

**Deviations recorded:** DEVIATION-911 (pre-Parent defaults + prefetch-only
upper bound), DEVIATION-912 (`public static` so `InboxGatewayApi` shares one
source), DEVIATION-901 (three-channel env isolation, Phase 1).

**TASK-917 — APPROVED and plan closed (2026-09-28).** Owner accepted Phase 2
and authorized closing the plan; `status` moved `Planned` -> `Completed`. No
further code changes required.



