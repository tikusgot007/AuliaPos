---
goal: Teruskan Media-Bound Review Round 4 — close the three REQUIRED defects left by the v1.2 clamp and loopback-retry hardening
version: 1.3
date_created: 2026-09-28
last_updated: 2026-09-28
owner: AuliaPos Inbox module
status: "Completed"
tags: ["refactor", "clean-code", "architecture", "security", "teruskan", "media-bound"]
---

# Introduction

![Status: Planned](https://img.shields.io/badge/status-Planned-yellow)

Rencana remediasi hasil `/sdlc-code-review` **Two-Axis** atas eksekusi `plan-refactor-teruskan-media-bound-v1.2.md`, di-pin pada `7ed10ad` (fixed point) → `1734c9b` (HEAD, branch `v2.3`).

Implementasi v1.2 **patuh plan dan patuh spec** secara fungsional. Seluruh angka bukti plan direproduksi: suite `vendor/bin/phpunit --no-coverage` **OK (685 tests, 2722 assertions), exit 0**; `node tests/js/media-inbox-retry.check.js` **18 PASS**; `node tests/js/operation-id-composer.check.js` lulus; run env kotor prefetch hijau. Klaim `CLN-901` (satu sumber `mbKeByte`, 4 call-site) dan `TASK-914` (retry port, stdio ke berkas log, pesan skip jujur) terverifikasi; `DEVIATION-901/911/912` masing-masing justifikasinya terbukti.

Empat temuan `[REQUIRED]` tersisa (3 di Axis A, 1 di Axis B), plus satu gugus opsional/nit:

- **[REQUIRED] Akar-1 (SEC-01 / Axis A):** `batasiEnvMb()` mengembalikan `$default` mentah tanpa meng-clamp ulang ke `$maks` (`app/Config/Inbox.php:137-143`, dari `:121`). Dengan `inbox.maxMediaDownloadMb=10` (nilai sah), prefetch tanpa override menghasilkan 15 > 10 — batas ingest diam-diam melebihi batas unduh, tepat kontrol DoS yang fungsi ini ada untuk menegakkan.
- **[REQUIRED] Akar-2 (SEC-02 / Axis A):** env `maxMediaUploadMb`/`maxMediaDownloadMb` tidak punya batas atas (`app/Config/Inbox.php:119-120`), sehingga nilai salah-ketik besar lolos, `$mb * 1024 * 1024` melewati `PHP_INT_MAX`, dan `mbKeByte()` (`app/Controllers/Inbox.php:749-752`) melempar `TypeError` tak tertangkap → semua permintaan unggah/`GET /inbox/media/:id` menjadi HTTP 500. Ter-reproduksi.
- **[REQUIRED] Akar-3 (CS-01 / Axis A):** retry loopback `mulaiServer()` memanggil `hentikanServer()` (`tests/session/InboxPrefetchIngestBoundTest.php:246`) yang justru menghapus `router.php` + `serverDir` dan mengosongkan `$this->serverDir`/`$this->logFile` (`:293-301`), sehingga percobaan kedua selalu gagal dan test turun menjadi `markTestSkipped` — retry tidak pernah bisa berhasil.
- **[REQUIRED] Akar-4 (SPEC-001 / Axis B):** `testPrefetchSahDihormatiDanBatasAtasIkutBatasUnduh()` (`tests/unit/InboxMediaBoundConfigTest.php:256-267`) menyetel hanya kunci prefetch tanpa mengisolasi `inbox.maxMediaDownloadMb`, sehingga `.env` pengembang dapat membuatnya merah — kelas kerapuhan yang justru hendak dihapus `REQ-901`. Ter-reproduksi RED dengan `inbox.maxMediaDownloadMb=5`.
- **[OPSIONAL/NIT]:** `nonRetryable` hilang saat reconnect (`mediaSementara.clear()`); `mbKeByte()` public static di Controller (sudah tercatat `DEVIATION-912`); refleksi tak perlu di `InboxMbKeByteTest`; test penjaga duplikat/nama menyesatkan; `pulihkanEnv()` mengubah distribusi kanal; `docs/ARCHITECTURE.md` belum mencerminkan batas ingest baru.

Rencana ini **tidak mengubah requirement apa pun** dan **tidak menyentuh `spec/spec-design-teruskan.md`, PRD, `docs/adr/0002-*`, `plan-refactor-teruskan-media-bound-v1.1.md`, maupun `-v1.2.md`** (keduanya sudah `Completed`).

## 1. Traceability: Requirements & Constraints

- **REQ-1001** `[REQUIRED]`: Nilai fallback clamp batas media WAJIB juga di-clamp ke batas atas yang berlaku, sehingga invarian `maxMediaPrefetchMb <= maxMediaDownloadMb` berlaku juga ketika env prefetch tidak diset. Ref: review Axis A (SEC-01).
- **SEC-1001** `[REQUIRED]`: Konversi MB→byte TIDAK BOLEH melempar `TypeError`/menghasilkan float untuk nilai env besar; salah-ketik nilai batas media harus gagal aman, bukan menjadikan seluruh jalur media HTTP 500. Ref: review Axis A (SEC-02).
- **CLN-1001** `[REQUIRED]`: Retry pada test loopback WAJIB benar-benar dapat berhasil: menghentikan proses TIDAK BOLEH menghapus direktori/router yang masih dibutuhkan percobaan berikutnya. Ref: review Axis A (CS-01).
- **TEST-1001** `[REQUIRED]`: Setiap test konfigurasi batas media WAJIB mengisolasi env ambien untuk **semua** kunci yang dapat memengaruhi asersinya (`inbox.maxMediaUploadMb`, `inbox.maxMediaDownloadMb`, `inbox.maxMediaPrefetchMb`). Ref: review Axis B (SPEC-001); plan v1.2 `REQ-901`/`GOAL-901`.
- **SEC-1002** `[OPTIONAL]`: Entri `nonRetryable` (413 deterministik) sebaiknya tidak hilang saat reset reconnect. Ref: review Axis A (SEC-03).
- **CLN-1002** `[OPTIONAL]`: `mbKeByte()` sebaiknya tidak tinggal sebagai public static di Controller. Ref: review Axis A (ARCH-01); sudah dicatat `DEVIATION-912` plan v1.2.
- **CLN-1003** `[NIT]`: Test `mbKeByte()` tidak perlu refleksi. Ref: review Axis A (CS-02).
- **CLN-1004** `[NIT]`: Test penjaga isolasi duplikat dinamai/digabung ulang agar tidak menyesatkan. Ref: review Axis A (CS-03).
- **TEST-1002** `[NIT]`: `pulihkanEnv()` sedapat mungkin memulihkan kanal sesuai asalnya. Ref: review Axis A (CS-04).
- **DOC-1001** `[OPTIONAL]`: `docs/ARCHITECTURE.md` diperbarui agar mencerminkan batas ingest `maxMediaPrefetchMb` dan flag klien `nonRetryable`. Ref: review Axis B (SPEC-006); mandat Living Architecture Map `AGENTS.md`.
- **CLN-1005** `[FYI]`: `log_message('warning', ...)` clamp dapat berulang tiap request; pertimbangkan dedupe. Ref: review Axis A (FYI).
- **CON-1001**: DILARANG mengubah `spec/spec-design-teruskan.md`, PRD, `docs/adr/0002-*`, `plan-refactor-teruskan-media-bound-v1.1.md`, dan `plan-refactor-teruskan-media-bound-v1.2.md`.
- **CON-1002**: DILARANG mengubah nilai default `maxMediaUploadMb` (15), `maxMediaDownloadMb` (100), `maxMediaPrefetchMb` (15) saat env bersih.
- **CON-1003**: Perubahan additive/backward-compatible; `vendor/bin/phpunit --no-coverage` keluar kode 0; `node tests/js/media-inbox-retry.check.js` dan `node tests/js/operation-id-composer.check.js` lulus.
- **CON-1004**: Repo `WA-Gateway` tidak disentuh.
- **CON-1005**: DILARANG menambah suppression (`@ts-ignore`, `eslint-disable`, `# noqa`), melewati test, atau menghapus asersi untuk membuat suite hijau.

## 2. Implementation Steps

> **⚠️ EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> Jalankan plan ini phase demi phase. Jalankan task **VERIFY** di akhir setiap phase, lalu **STOP DAN TUNGGU** persetujuan eksplisit owner sebelum lanjut. Definition of Done tiap task: `vendor/bin/phpunit --no-coverage` keluar kode **0** dan tidak ada suppression/test-skip baru. Jangan menyentuh repo `WA-Gateway`. Jangan mengubah dokumen spec/PRD/ADR/plan v1.1/v1.2. Test Database Inbox (`aulia_inboxdb_test`) dipakai bersama: jalankan PHPUnit **berurutan**, tidak pernah paralel.

### Implementation Phase 1: Required Defect Remediation (Security / Correctness)

- **GOAL-1001**: Tutup tiga cacat REQUIRED Axis A dan satu cacat REQUIRED Axis B tanpa mengubah perilaku yang sudah lulus review (default 15/100/15, semantik clamp untuk nilai sah).

| Task ID   | Description (Include Exact File Paths & Micro-Testing)                                                                                                                                                                                                                                                                                                                                                 | Ref ID    | Completed | Date |
| --------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | --------- | :-------: | :--: |
| TASK-1001 | `app/Config/Inbox.php:133-147` — clamp nilai fallback: `$fallback = $maks === null ? $default : min($default, $maks);` lalu `return $fallback;` (menggantikan `return $default;`). Micro-test baru di `tests/unit/InboxMediaBoundConfigTest.php`: env `inbox.maxMediaDownloadMb=10` (tanpa override prefetch) → `maxMediaPrefetchMb === 10` (sebelum fix: 15). Tambahkan juga asersi langsung `maxMediaPrefetchMb <= maxMediaDownloadMb`.                                                                                              | REQ-1001  |    [x]    | 2026-09-28 |
| TASK-1002 | `app/Controllers/Inbox.php:749-752` — jadikan `mbKeByte()` gagal-aman: sebelum `return`, bila `$mb > intdiv(PHP_INT_MAX, 1024 * 1024)` maka `return PHP_INT_MAX;`. Pertahankan keputusan plan v1.2 "tanpa batas atas kebijakan" pada `app/Config/Inbox.php:119-120`. Micro-test di `tests/unit/InboxMbKeByteTest.php`: `mbKeByte(8800000000000)` mengembalikan `PHP_INT_MAX` dan TIDAK melempar `TypeError`; nilai 0/1/15/100 tetap identik.                                          | SEC-1001  |    [x]    | 2026-09-28 |
| TASK-1003 | `tests/session/InboxPrefetchIngestBoundTest.php:188-250` + `:283-302` — pisahkan `hentikanProses()` (hanya `proc_terminate`/`proc_close`, set `proses`/`port` null) dari pembersihan direktori+log. Tulis `router.php` DI DALAM loop percobaan (per-attempt) atau jangan hapus router di antara percobaan; `serverDir`/`logFile` hanya dibersihkan di `tearDown()`. JANGAN mengubah perilaku asersi 256KB/2MB.                                                                                            | CLN-1001  |    [x]    | 2026-09-28 |
| TASK-1004 | `tests/unit/InboxMediaBoundConfigTest.php:256-283` — isolasi env ambien untuk ketiga kunci (snapshot via `env()` + bersihkan `$_ENV`/`$_SERVER`/`putenv` memakai pola `pulihkanEnv()` yang ada, TANPA helper kedua), lalu set nilai yang dimaksud secara eksplisit (`maxMediaDownloadMb` = 100) sebelum `new InboxConfig()` pertama. Test WAJIB hijau saat env ambien `inbox.maxMediaDownloadMb=5` dan `inbox.maxMediaPrefetchMb=7`.                                                                        | TEST-1001 |    [x]    | 2026-09-28 |
| TASK-1005 | **VERIFY**: `php -l` tiap file yang disentuh; `cmd /c "vendor\bin\phpunit --no-coverage --filter InboxMediaBoundConfigTest"` exit 0; `cmd /c "set inbox.maxMediaDownloadMb=5&&vendor\bin\phpunit --no-coverage --filter InboxMediaBoundConfigTest"` exit 0; `cmd /c "set inbox.maxMediaPrefetchMb=7&&vendor\bin\phpunit --no-coverage --filter InboxMediaBoundConfigTest"` exit 0; `cmd /c "vendor\bin\phpunit --no-coverage tests/session/InboxPrefetchIngestBoundTest.php"` exit 0 (server loopback benar-benar start, bukan skip); suite penuh `cmd /c "vendor\bin\phpunit --no-coverage"` exit 0 dengan jumlah test ≥ 685; `node tests/js/media-inbox-retry.check.js` 18 PASS. Catat bukti mentah di blok Execution Log. | -         |    [x]    | 2026-09-28 |
| TASK-1006 | **APPROVAL**: 🛑 Tunggu konfirmasi eksplisit owner sebelum Phase 2.                                                                                                                                                                                                                                                                                                                                        | -         |    [ ]    |      |

### Implementation Phase 2: Optional Hardening & Hygiene

- **GOAL-1002**: Tutup gugus opsional/nit tanpa mengubah perilaku yang sudah lulus review. Setiap task boleh dilewati atas keputusan owner; yang dilewati ditandai `[-]` dengan alasan, bukan `[x]` palsu.

| Task ID   | Description (Include Exact File Paths & Micro-Testing)                                                                                                                                                                                                                                                                  | Ref ID    | Completed | Date |
| --------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------- | :-------: | :--: |
| TASK-1101 | `app/Views/inbox/index.php:3601-3602` + salinan VERBATIM `tests/js/media-inbox-retry.check.js` — jangan buang entri `nonRetryable` saat `mediaSementara.clear()` pada reconnect: seed ulang entri tersebut (atau kecualikan dari reset). Micro-test check JS: setelah reset+reconnect simulasi, entri `terlalu_besar` tetap tidak boleh dicoba lagi. | SEC-1002  |    [x]    | 2026-09-28 |
| TASK-1102 | `app/Controllers/Inbox.php:749` — pindahkan helper konversi ke lokasi library/kelas nilai (mis. `app/Libraries/`), pertahankan SATU sumber dan perbarui 4 call-site (`Inbox.php:672,1175,3081`; `InboxGatewayApi.php:367`). Bila owner menilai churn tidak sepadan, tandai `[-]` dan tambahkan catatan docblock `@internal`. | CLN-1002  |    [x]    | 2026-09-28 |
| TASK-1103 | `tests/unit/InboxMbKeByteTest.php:17-18` — hapus refleksi; panggil `Inbox::mbKeByte($mb)` langsung.                                                                                                                                                                                                                        | CLN-1003  |    [x]    | 2026-09-28 |
| TASK-1104 | `tests/unit/InboxMediaBoundConfigTest.php:117-153` — gabung/benahi test penjaga yang menduplikasi `:155-186`, atau perbaiki namanya agar cocok dengan asersinya (override MEMANG dibaca, lalu default dipulihkan).                                                                                                          | CLN-1004  |    [x]    | 2026-09-28 |
| TASK-1105 | `tests/unit/InboxMediaBoundConfigTest.php:292-306` — `pulihkanEnv()` memulihkan kanal sesuai asalnya (catat kanal mana yang berisi nilai) alih-alih menulis ke ketiga kanal.                                                                                                                                                | TEST-1002 |    [x]    | 2026-09-28 |
| TASK-1106 | `docs/ARCHITECTURE.md` — tambahkan paragraf batas media: tiga sumbu (unggah 15 / unduh-tampilan 100 / ingest-prefetch 15) + flag klien `nonRetryable` untuk 413 deterministik. Surgical edit di section media Inbox; jangan menyentuh bagian lain.                                                                            | DOC-1001  |    [x]    | 2026-09-28 |
| TASK-1107 | `app/Config/Inbox.php:138` — hindari `log_message` berulang untuk kunci yang sama dalam satu request (mis. penanda statis per-kunci). Opsional; tandai `[-]` bila dinilai tidak sepadan.                                                                                                                                     | CLN-1005  |    [-]    | 2026-09-28 |
| TASK-1108 | **VERIFY**: suite penuh `cmd /c "vendor\bin\phpunit --no-coverage"` exit 0; `node tests/js/media-inbox-retry.check.js` lulus; `node tests/js/operation-id-composer.check.js` lulus; catat jumlah test akhir.                                                                                                                | -         |    [x]    | 2026-09-28 |
| TASK-1109 | **APPROVAL**: 🛑 Tunggu konfirmasi eksplisit owner untuk menutup plan.                                                                                                                                                                                                                                                     | -         |    [x]    | 2026-09-28 |

## 3. Structural Remedies & Alternatives

- **Struktural (REQ-1001)**: menaruh clamp fallback di dalam `batasiEnvMb()` (satu tempat), bukan menambal di pemanggil — invarian berlaku untuk setiap pemakai `Config\Inbox`.
- **Struktural (SEC-1001)**: jaga nilai di titik konversi (`mbKeByte()`), karena akar bahayanya adalah overflow aritmetika, bukan nilai konfigurasi yang "salah" secara kebijakan.
- **Struktural (CLN-1001)**: memisahkan "hentikan proses" dari "bersihkan artefak" — dua tanggung jawab yang berbeda; pembersihan pindah ke siklus hidup test (`tearDown`).
- **ALT-1001** `[DITOLAK]` Menambah batas atas kebijakan untuk `maxMediaUploadMb`/`maxMediaDownloadMb` di config (mis. 1024MB) — mengubah kebijakan yang sengaja dibiarkan terbuka di plan v1.2 dan berisiko menolak nilai sah; guard overflow di `mbKeByte()` sudah menghilangkan dampak fatal tanpa menambah batas baru.
- **ALT-1002** `[DITOLAK]` Menukar `markTestSkipped` loopback dengan hard-fail di semua lingkungan — akan memecahkan mesin tanpa loopback/`proc_open`; kompromi pesan skip jujur dipertahankan.
- **ALT-1003** `[DITOLAK]` Menggabungkan temuan SEC-01 dan SPEC-001 menjadi satu item — keduanya punya akar berbeda (kode produksi vs isolasi test) dan wajib dilaporkan terpisah pada model Two-Axis; memperbaikinya pun butuh dua task berbeda.
- **ALT-1004** `[DITOLAK]` Menghapus asersi "upload tanpa batas atas diterima apa adanya" (`InboxMediaBoundConfigTest:241`) — asersi itu masih benar (100000MB tidak overflow); yang salah hanya perilaku crash untuk nilai raksasa.

## 4. Dependencies

- **DEP-1001**: Tidak ada library baru. cURL/PHP 8.2/CI4 yang sudah ada.

## 5. Files Affected

- **FILE-1001**: `app/Config/Inbox.php` — clamp fallback di `batasiEnvMb()` (TASK-1001); dedupe log opsional (TASK-1107).
- **FILE-1002**: `app/Controllers/Inbox.php` — guard overflow `mbKeByte()` (TASK-1002); relokasi helper opsional (TASK-1102).
- **FILE-1003**: `tests/unit/InboxMediaBoundConfigTest.php` — test fallback clamp + isolasi test RISK-901 (TASK-1001/1004/1104/1105).
- **FILE-1004**: `tests/unit/InboxMbKeByteTest.php` — test guard overflow + hapus refleksi (TASK-1002/1103).
- **FILE-1005**: `tests/session/InboxPrefetchIngestBoundTest.php` — pemisahan hentikan-proses/pembersihan (TASK-1003).
- **FILE-1006**: `app/Views/inbox/index.php` + `tests/js/media-inbox-retry.check.js` — retensi `nonRetryable` saat reset (TASK-1101).
- **FILE-1007**: `docs/ARCHITECTURE.md` — dokumentasi tiga sumbu batas media + `nonRetryable` (TASK-1106).
- **Tidak menyentuh**: `spec/spec-design-teruskan.md`, PRD, `docs/adr/0002-teruskan-source-visibility-risk-acceptance.md`, `plan-refactor-teruskan-media-bound-v1.1.md`, `plan-refactor-teruskan-media-bound-v1.2.md`, repo `WA-Gateway`.

## 6. Testing Strategy

- **TEST-1003**: `maxMediaPrefetchMb <= maxMediaDownloadMb` selalu berlaku, termasuk saat env prefetch tidak diset dan `maxMediaDownloadMb` diturunkan (10) — test baru TASK-1001.
- **TEST-1004**: `mbKeByte()` gagal-aman untuk nilai ekstrem (`8800000000000`) tanpa `TypeError`, dan tetap identik untuk 0/1/15/100MB — test TASK-1002.
- **TEST-1005**: Test loopback start sungguhan (bukan skip) dan lulus berulang (jalankan `InboxPrefetchIngestBoundTest` 5x berturut-turut) — bukti TASK-1005/1003.
- **TEST-1006**: Seluruh test konfigurasi batas media hijau dengan env bersih DAN env kotor (`maxMediaDownloadMb=5`, `maxMediaPrefetchMb=7`) — TASK-1004.
- **TEST-1007**: Regresi penuh — Teruskan teks/lampiran, Balas Pesan, kirim biasa, media masuk: suite `vendor/bin/phpunit --no-coverage` exit 0 + dua skrip JS lulus.

## 7. Risks & Rollback Plan

- **RISK-1001**: Perbaikan fallback clamp (TASK-1001) mengubah hasil ketika `maxMediaDownloadMb < 15` dan prefetch tidak diset — sekarang prefetch mengikuti batas unduh (10), bukan 15. Ini perubahan perilaku yang benar (menegakkan invarian DoS), tetapi harus disebut di Execution Log; jangan sampai dianggap "regresi default" karena default bersih tetap 15/100/15.
- **RISK-1002**: Memisahkan pembersihan direktori dari `hentikanProses()` (TASK-1003) berisiko meninggalkan direktori temporer bila `tearDown()` tidak jalan; mitigasi: `tearDown()` wajib membersihkan `serverDir` dan menghentikan proses, dan jalankan test 5x untuk membuktikan tidak menumpuk.
- **RISK-1003**: Guard overflow (TASK-1002) menyamarkan salah-ketik raksasa menjadi "tanpa batas" (efektif `PHP_INT_MAX`); diterima karena mencegah 500 dan tetap memicu peringatan clamp di layer config bila nilainya tidak masuk akal. Catat di Execution Log.
- **RISK-1004**: Perubahan pada file test bersama; penyebab pernah tercatat (antivirus mengunci file di `tests/`) → pastikan berkas test tidak hilang dan jumlah test tidak turun tanpa penjelasan.
- **Rollback**: setiap phase satu commit atomic; `git revert` commit phase terkait lalu jalankan `vendor/bin/phpunit --no-coverage`.
- **Tidak ada perubahan `CONTEXT.md`; tidak ada ADR baru.**

## 8. Related Specifications / Further Reading

- [`spec-design-teruskan.md`](../spec/spec-design-teruskan.md) (v1.3) — **tidak diubah oleh plan ini**
- [`plan-refactor-teruskan-media-bound-v1.2.md`](./plan-refactor-teruskan-media-bound-v1.2.md) — plan yang direview (Completed)
- [`plan-refactor-teruskan-media-bound-v1.1.md`](./plan-refactor-teruskan-media-bound-v1.1.md) (Completed)
- `app/Config/Inbox.php:104-147`, `app/Controllers/Inbox.php:749-752`, `tests/unit/InboxMediaBoundConfigTest.php:256-307`, `tests/session/InboxPrefetchIngestBoundTest.php:188-303`

## 9. Execution Log

> Diisi oleh `/sdlc-write-code` saat eksekusi. Setiap klaim wajib disertai bukti mentah (perintah + keluaran). Untuk plan ini, WAJIB juga mencatat: (a) hasil test `maxMediaDownloadMb=10` sebelum vs sesudah fix TASK-1001; (b) bukti `mbKeByte(8800000000000)` tidak lagi `TypeError`; (c) bukti loopback test start sungguhan dan stabil 5x.

### Execution Log — Implementation Phase 1 (2026-09-28)

Eksekutor `/sdlc-write-code`. Branch `v2.3`. Semua perintah dari `C:\xampp\htdocs\aulia`; PHP 8.2.12 (ZTS), PHPUnit 10.5.64. Tidak ada suppression/test-skip baru. Repo `WA-Gateway` tidak disentuh. Spec/PRD/ADR/plan v1.1/v1.2 tidak disentuh.

**TASK-1001 — clamp fallback `batasiEnvMb()`** (`app/Config/Inbox.php:133-152`)

- Perubahan: `return $default;` menjadi `$fallback = $maks === null ? $default : min($default, $maks); return $fallback;` (dipakai juga untuk cabang `$nilai < 1`). Pesan log disesuaikan menyebut `fallback`.
- Test baru `testPrefetchFallbackIkutDibatasiOlehBatasUnduh` (`tests/unit/InboxMediaBoundConfigTest.php`): env `inbox.maxMediaDownloadMb=10`, prefetch tanpa override.
- (a) **SEBELUM fix — RED**:
  `cmd /c "vendor\bin\phpunit --no-coverage --filter testPrefetchFallbackIkutDibatasiOlehBatasUnduh"`
  → `Failed asserting that 15 is identical to 10.` (fallback default 15 > batas unduh 10).
- **SESUDAH fix — GREEN**:
  `cmd /c "vendor\bin\phpunit --no-coverage --filter InboxMediaBoundConfigTest"` → `OK (8 tests, 27 assertions)`.

**TASK-1002 — guard overflow `mbKeByte()`** (`app/Controllers/Inbox.php:749-761`)

- Perubahan: bila `$mb > intdiv(PHP_INT_MAX, 1024 * 1024)` maka `return PHP_INT_MAX;`. Batas atas kebijakan di `app/Config/Inbox.php:119-120` tetap tidak ditambahkan (sesuai plan v1.2).
- (b) **SEBELUM fix — RED**:
  `cmd /c "vendor\bin\phpunit --no-coverage tests/unit/InboxMbKeByteTest.php"`
  → `TypeError: App\Controllers\Inbox::mbKeByte(): Return value must be of type int, float returned` di `app/Controllers/Inbox.php:751`.
- **SESUDAH fix — GREEN**: `OK (2 tests, 6 assertions)`. `mbKeByte(8800000000000) === PHP_INT_MAX`, tidak ada `TypeError`; nilai 0/1/15/100 tetap identik (test lama tetap hijau).

**TASK-1003 — pisahkan `hentikanProses()` dari pembersihan direktori** (`tests/session/InboxPrefetchIngestBoundTest.php`)

- `hentikanServer()` lama dipisah menjadi `hentikanProses()` (hanya `proc_terminate`/`proc_close`, lalu null-kan `proses`/`port`) dan `bersihkanServerDir()` (hapus `serverDir`/`logFile`, dipanggil HANYA dari `tearDown()`). Retry di `mulaiServer()` kini memanggil `hentikanProses()`; `router.php` tidak dihapus di antara percobaan. Asersi 256KB/2MB tidak diubah.
- (c) **Loopback start sungguhan, stabil 5x** (tidak ada `markTestSkipped`):
  `cmd /c "vendor\bin\phpunit --no-coverage tests/session/InboxPrefetchIngestBoundTest.php"` ×5 → kelima run `OK (2 tests, 11 assertions)`.

**TASK-1004 — isolasi env RISK-901** (`tests/unit/InboxMediaBoundConfigTest.php`)

- `testPrefetchSahDihormatiDanBatasAtasIkutBatasUnduh` kini meng-unset ketiga kunci di `$_ENV`/`$_SERVER` + `putenv`, lalu menyetel `maxMediaDownloadMb` eksplisit (100 lalu 200) sebelum `new InboxConfig()`, memakai `pulihkanEnv()` yang ada (tanpa helper kedua).
- Hijau pada env bersih DAN kotor:
  - `cmd /c "vendor\bin\phpunit --no-coverage --filter InboxMediaBoundConfigTest"` → `OK (8 tests, 27 assertions)`
  - `cmd /c "set inbox.maxMediaDownloadMb=5&&vendor\bin\phpunit --no-coverage --filter InboxMediaBoundConfigTest"` → `OK (8 tests, 27 assertions)`
  - `cmd /c "set inbox.maxMediaPrefetchMb=7&&vendor\bin\phpunit --no-coverage --filter InboxMediaBoundConfigTest"` → `OK (8 tests, 27 assertions)`

**TASK-1005 VERIFY**

- `php -l` bersih untuk kelima file tersentuh: `app/Config/Inbox.php`, `app/Controllers/Inbox.php`, `tests/unit/InboxMediaBoundConfigTest.php`, `tests/unit/InboxMbKeByteTest.php`, `tests/session/InboxPrefetchIngestBoundTest.php`.
- Suite penuh: `cmd /c "vendor\bin\phpunit --no-coverage"` → `OK (687 tests, 2728 assertions)`, exit 0 (baseline plan 685; kini 687 karena +2 test baru, tidak ada skip baru).
- `node tests/js/media-inbox-retry.check.js` → `== 18 PASS, 0 FAIL ==`.
- `node tests/js/operation-id-composer.check.js` → lulus.

**Catatan risiko**

- RISK-1001: saat `maxMediaDownloadMb < 15` dan prefetch tanpa override, prefetch kini mengikuti batas unduh (mis. 10), bukan default 15. Ini perilaku benar (menegakkan invarian DoS). Default bersih tetap 15/100/15 (`testDefaultUnduhDanUnggahTerpisah` hijau).
- RISK-1003: guard overflow menyamarkan salah-ketik raksasa menjadi efektif `PHP_INT_MAX`; diterima karena mencegah HTTP 500, sementara peringatan clamp di layer config tetap muncul untuk nilai yang tidak masuk akal.

- **Status: Phase 1 SELESAI.** TASK-1006 (APPROVAL) menunggu konfirmasi eksplisit owner sebelum Phase 2. Phase 2 TIDAK dieksekusi.

### Execution Log — Implementation Phase 2 (2026-09-28)

Owner menyetujui lanjut Phase 2. Semua perintah dari `C:\xampp\htdocs\aulia`; PHP 8.2.12, PHPUnit 10.5.64. Tidak ada suppression/test-skip baru.

**TASK-1101 — retensi `nonRetryable` saat reconnect** (`app/Views/inbox/index.php` + `tests/js/media-inbox-retry.check.js`)

- `mediaSementara.clear()` diganti helper `bersihkanSementaraSetelahReconnect()` yang hanya membuang entri non-`nonRetryable`. Entri `413` "terlalu besar" (deterministik) dipertahankan; kegagalan sementara tetap dibuang agar dicoba lagi (REQ-003).
- Salinan VERBATIM di JS check disinkronkan + check baru. `node tests/js/media-inbox-retry.check.js` → `== 19 PASS, 0 FAIL ==` (sebelumnya 18).

**TASK-1102 — relokasi `mbKeByte()`** (keputusan: DIEKSEKUSI)

- Helper dipindah keluar dari Controller ke kelas baru `app/Libraries/InboxMediaBound.php` (`App\Libraries\InboxMediaBound::mbKeByte()`), tetap SATU sumber, guard overflow dipertahankan.
- 4 call-site diperbarui: `app/Controllers/Inbox.php:672,1185,3091` (dari `self::`) dan `app/Controllers/InboxGatewayApi.php:367` (dari `Inbox::`). Tidak ada sisa `self::mbKeByte`/`Inbox::mbKeByte` (grep bersih).

**TASK-1103 — hapus refleksi** (`tests/unit/InboxMbKeByteTest.php`)

- Refleksi dibuang; test memanggil `InboxMediaBound::mbKeByte($mb)` langsung (mengikuti relokasi TASK-1102). Hijau.

**TASK-1104 — perbaiki nama test penjaga menyesatkan** (`tests/unit/InboxMediaBoundConfigTest.php`)

- `testDefaultPrefetchTetapTerkunciWalauEnvMengOverride` → `testOverridePrefetchDihormatiLaluDefaultPulihSaatEnvBersih`, karena asersinya justru membuktikan override DIBACA (7), lalu default 15 pulih saat env bersih.

**TASK-1105 — `pulihkanEnv()` pulihkan kanal sesuai asal** (`tests/unit/InboxMediaBoundConfigTest.php`)

- Snapshot kini per-kanal lewat `rekamEnv()` baru (`$_ENV`/`$_SERVER`/`getenv`), dan `pulihkanEnv()` memulihkan tiap kanal persis seperti asal, bukan menulis ke ketiga kanal. Isolasi TASK-1004 tetap berlaku (hijau di env bersih & kotor).

**TASK-1106 — `docs/ARCHITECTURE.md`**

- Paragraf "Media size bounds (`Config\Inbox`)" menambahkan tiga sumbu (unggah 15 / unduh-tampilan 100 / ingest-prefetch 15, prefetch ≤ unduh) + `InboxMediaBound::mbKeByte()` overflow-safe; bullet klien diperbarui menyebut retensi `nonRetryable`; daftar `app/Libraries/` diberi `InboxMediaBound`.

**TASK-1107 — dedupe log clamp: `[-]` (dilewati)**

- Alasan: dedupe butuh penanda `static` per-kunci di `Config\Inbox`; state statis bertahan antar-request pada worker PHP jangka-panjang sehingga berisiko MENYEMBUNYIKAN peringatan yang seharusnya muncul, demi menghemat satu log. Nilai tidak sepadan dengan risiko regresi observability.

**TASK-1108 VERIFY**

- `php -l` bersih untuk keenam file PHP tersentuh (termasuk `app/Libraries/InboxMediaBound.php`).
- Suite penuh: `cmd /c "vendor\bin\phpunit --no-coverage"` → `OK (687 tests, 2728 assertions)`, exit 0.
- `node tests/js/media-inbox-retry.check.js` → `== 19 PASS, 0 FAIL ==`.
- `node tests/js/operation-id-composer.check.js` → lulus.

- **Status: Phase 2 SELESAI.** TASK-1109 (APPROVAL) menunggu keputusan eksplisit owner untuk menutup plan. `CON-1001` dipatuhi (spec/PRD/ADR/plan v1.1/v1.2 tidak disentuh); `CON-1005` dipatuhi (tanpa suppression/test-skip).
- **Status: PLAN DITUTUP** (`status: Completed`) — owner menyetujui penutupan plan v1.3 pada `2026-09-28`.


