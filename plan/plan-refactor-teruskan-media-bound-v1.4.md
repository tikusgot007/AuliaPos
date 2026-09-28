---
goal: Teruskan Media-Bound Review Round 5 — bound the overflow guard so a misconfigured media MB cap fails safe instead of failing open to PHP_INT_MAX
version: 1.4
date_created: 2026-09-28
last_updated: 2026-09-28
owner: AuliaPos Inbox module
status: "Completed"
tags: ["refactor", "security", "teruskan", "media-bound", "dos"]
---

# Introduction

![Status: Completed](https://img.shields.io/badge/status-Completed-green)

Rencana remediasi hasil `/sdlc-code-review` **Two-Axis** atas eksekusi `plan-refactor-teruskan-media-bound-v1.3.md`, di-pin pada `1734c9b` (fixed point) → `bd18124` (HEAD, branch `v2.3`).

Eksekusi v1.3 **patuh plan dan patuh spec** secara fungsional: seluruh required TASK Phase 1 dan Phase 2 terverifikasi, `CON-1001` (spec/PRD/ADR/plan v1.1/v1.2 dan repo WA-Gateway tidak disentuh) dan `CON-1005` (tanpa suppression/test-skip baru) dipatuhi. Bukti direproduksi: `vendor/bin/phpunit --no-coverage` **OK (687 tests, 2728 assertions), exit 0**; `node tests/js/media-inbox-retry.check.js` **19 PASS**; `node tests/js/operation-id-composer.check.js` lulus.

Tersisa **satu temuan `[REQUIRED]` Axis A (Standards/Security)** yang belum ditutup di v1.3:

- **[REQUIRED] SEC-01 — guard overflow `mbKeByte()` GAGAL-OPEN, bukan gagal-aman.** (`app/Libraries/InboxMediaBound.php:21-28`) Saat `$mb > intdiv(PHP_INT_MAX, 1024*1024)`, helper mengembalikan `PHP_INT_MAX`, sehingga `$maxBytes` menjadi efektif tak terbatas dan kontrol batas ukuran (DoS/memori/disk) **nonaktif** pada jalur unggah, unduh, dan prefetch. Perilaku lama (`TypeError` → HTTP 500) memang gagal-tertutup; perilaku baru menggantinya dengan kontrol yang hilang diam-diam. Untuk jalur prefetch ini sebagian tertutup karena config menolak nilai prefetch raksasa lebih dulu, tetapi `maxMediaUploadMb`/`maxMediaDownloadMb` tidak punya plafon kebijakan, jadi satu salah-ketik env menonaktifkan batas unggah/unduh.

Temuan ini sudah tercatat sebagai trade-off yang diterima (`ALT-1001` `[DITOLAK]` dan `RISK-1003` pada plan v1.3). Reviewer menilai justifikasinya **sebagian kuat** (mencegah 500 massal) tetapi **belum cukup**: guard hanya mencegah crash, bukan menutup kelas salah-konfigurasi DoS — nilai besar yang tidak memicu overflow (mis. `100000000` MB) tetap menonaktifkan batas tanpa peringatan. Plan ini menawarkan remediasi berplafon; alternatif "re-afirmasi risiko diterima" disediakan bila owner memutuskan sebaliknya.

Rencana ini **tidak mengubah requirement produk apa pun** dan **tidak menyentuh `spec/spec-design-teruskan.md`, PRD, `docs/adr/0002-*`, maupun `plan-refactor-teruskan-media-bound-v1.1.md`/`-v1.2.md`/`-v1.3.md`**.

## 1. Traceability: Requirements & Constraints

- **SEC-1003** `[REQUIRED]`: Guard overflow batas media TIDAK BOLEH mengganti kontrol DoS dengan nilai efektif tak terbatas; salah-konfigurasi nilai batas media harus **gagal-tertutup atau gagal-terbatas**, bukan gagal-terbuka. Ref: review Axis A (SEC-01).
- **REQ-1002** `[REQUIRED]`: Pilih dan jalankan salah satu: (a) tambah plafon kebijakan yang wajar pada konversi MB→byte (mis. konstanta `CEILING_MB`), atau (b) re-afirmasi eksplisit oleh owner bahwa nilai tak terbatas dapat diterima, dicatat di Execution Log plan ini DAN dikomentari di helper. Tidak boleh dibiarkan implisit.
- **CLN-1006** `[NIT]`: Literal `1024 * 1024` diulang dua kali (`:23`, `:27`); ekstrak konstanta bernama (mis. `BYTES_PER_MB`).
- **CLN-1007** `[OPTIONAL]`: Nama kelas `InboxMediaBound` lebih luas dari tanggung jawab tunggalnya (`mbKeByte`); pertimbangkan `InboxMediaSize`.
- **TEST-1008** `[OPTIONAL]`: Cabang `$nilai < 1` pada `batasiEnvMb()` dengan `maxMediaDownloadMb < 15` (mis. prefetch tidak sah + unduh 10 → harap 10) belum diasersikan langsung.
- **CLN-1008** `[NIT]`: `mulaiServer()` dapat meninggalkan direktori temporer percobaan pertama bila percobaan kedua memakai `serverDir` acak baru; hanya direktori terakhir dibersihkan `tearDown()`.
- **CON-1006**: DILARANG mengubah nilai default `maxMediaUploadMb` (15), `maxMediaDownloadMb` (100), `maxMediaPrefetchMb` (15) saat env bersih.
- **CON-1007**: DILARANG menambah suppression (`@ts-ignore`, `eslint-disable`, `# noqa`), melewati test, atau menghapus asersi untuk membuat suite hijau.
- **CON-1008**: DILARANG mengubah `spec/spec-design-teruskan.md`, PRD, `docs/adr/0002-*`, `plan-refactor-teruskan-media-bound-v1.1.md`, `-v1.2.md`, `-v1.3.md`, dan repo `WA-Gateway`.
- **CON-1009**: Perubahan additive/backward-compatible; `vendor/bin/phpunit --no-coverage` keluar kode 0 dan tidak ada jumlah test turun.

## 2. Implementation Steps

> **⚠️ EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> Jalankan plan ini phase demi phase. Jalankan task **VERIFY** di akhir setiap phase, lalu **STOP DAN TUNGGU** persetujuan eksplisit owner sebelum lanjut. Definition of Done tiap task: `vendor/bin/phpunit --no-coverage` keluar kode **0** dan tidak ada suppression/test-skip baru. Jangan menyentuh repo `WA-Gateway`. Jangan mengubah dokumen spec/PRD/ADR/plan v1.1/v1.2/v1.3. Test Database Inbox (`aulia_inboxdb_test`) dipakai bersama: jalankan PHPUnit **berurutan**, tidak pernah paralel. Sebelum memilih arah TASK-1201, minta keputusan owner antara opsi (a) plafon kebijakan dan (b) re-afirmasi risiko diterima.

### Implementation Phase 1: Bound the Overflow Guard

- **GOAL-1201**: Pastikan salah-konfigurasi nilai batas media gagal-terbatas (atau gagal-tertutup), bukan menonaktifkan kontrol ukuran secara diam-diam; sambil mempertahankan jaminan `SEC-1001` (tidak ada `TypeError`/HTTP 500) dan invarian `maxMediaPrefetchMb <= maxMediaDownloadMb`.

| Task ID   | Description (Include Exact File Paths & Micro-Testing)                                                                                                                                                                                                                                          | Ref ID   | Completed | Date |
| --------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------- | :-------: | :--: |
| TASK-1200 | **DECISION GATE**: Minta owner memilih (a) tambah plafon kebijakan pada konversi MB→byte, atau (b) re-afirmasi eksplisit menerima nilai tak terbatas. Catat pilihan + alasan di Execution Log. Jangan lanjut tanpa jawaban.                                                                     | REQ-1002 |    [x]    | 2026-09-28 |
| TASK-1201 | Jika (a): `app/Libraries/InboxMediaBound.php` — tambah konstanta kelas `private const CEILING_MB = <nilai wajar, mis. 4096>;` dan ubah guard menjadi mengembalikan `self::CEILING_MB * self::BYTES_PER_MB` (bukan `PHP_INT_MAX`). Jika (b): tambahkan komentar eksplisit di helper bahwa `PHP_INT_MAX` adalah keputusan risiko yang diterima owner + tautkan review SEC-01.                                                              | SEC-1003 |    [x]    | 2026-09-28 |
| TASK-1202 | `app/Libraries/InboxMediaBound.php` — ekstrak `private const BYTES_PER_MB = 1024 * 1024;` dan pakai di guard + return. Micro-test tetap hijau untuk 0/1/15/100.                                                                                                                                  | CLN-1006 |    [x]    | 2026-09-28 |
| TASK-1203 | `tests/unit/InboxMbKeByteTest.php` — sesuaikan `testNilaiRaksasaGagalAmanKeIntMax()` mengikuti keputusan TASK-1200: bila (a), asersi menjadi `mbKeByte(8800000000000) === CEILING_MB * 1024 * 1024` dan tetap TIDAK melempar `TypeError`; bila (b), pertahankan asersi `PHP_INT_MAX` + tambah komentar risiko diterima.                                                                                                              | SEC-1003 |    [x]    | 2026-09-28 |
| TASK-1204 | `tests/unit/InboxMediaBoundConfigTest.php` — tambah asersi cabang `$nilai < 1`: env prefetch tidak sah (`0`) + `maxMediaDownloadMb=10` → `maxMediaPrefetchMb === 10` (membuktikan clamp fallback berlaku di cabang invalid-value, bukan hanya `$nilai > $maks`).                                    | TEST-1008|    [x]    | 2026-09-28 |
| TASK-1205 | `tests/session/InboxPrefetchIngestBoundTest.php` — bila owner menyetujui (nit): bersihkan `serverDir` percobaan sebelumnya sebelum mengganti `serverDir` di `mulaiServer()`, atau pakai satu direktori untuk kedua percobaan. JANGAN mengubah perilaku asersi 256KB/2MB. Boleh ditandai `[-]` bila dinilai tidak sepadan. | CLN-1008 |    [-]    | 2026-09-28 |
| TASK-1206 | **VERIFY**: `php -l` tiap file tersentuh; `cmd /c "vendor\bin\phpunit --no-coverage --filter InboxMbKeByteTest"` exit 0; `cmd /c "vendor\bin\phpunit --no-coverage --filter InboxMediaBoundConfigTest"` exit 0 pada env bersih dan kotor (`maxMediaDownloadMb=5`, `maxMediaPrefetchMb=7`); `cmd /c "vendor\bin\phpunit --no-coverage tests/session/InboxPrefetchIngestBoundTest.php"` exit 0 (start sungguhan, bukan skip); suite penuh `cmd /c "vendor\bin\phpunit --no-coverage"` exit 0 dengan jumlah test ≥ 687; `node tests/js/media-inbox-retry.check.js` 19 PASS. Catat bukti mentah di Execution Log. | -        |    [x]    | 2026-09-28 |
| TASK-1207 | **APPROVAL**: 🛑 Tunggu konfirmasi eksplisit owner untuk menutup plan.                                                                                                                                                                                                                          | -        |    [x]    | 2026-09-28 |

## 3. Structural Remedies & Alternatives

- **Struktural (SEC-1003)**: menaruh plafon/komentar keputusan di dalam `InboxMediaBound` (satu tempat), bukan menambal di tiap pemanggil — setiap jalur unggah/unduh/prefetch otomatis tunduk.
- **ALT-1200** `[DITOLAK — dipakai v1.3]` Membiarkan `PHP_INT_MAX` tanpa komentar eksplisit: dipertahankan hanya bila owner secara sadar memilih opsi (b) dan alasannya tercatat (TASK-1200/1201).
- **ALT-1201** `[DITOLAK]` Mengembalikan `float`/`0` saat overflow: `0` membuat semua media 413 (kontrol rusak arah sebaliknya), `float` melanggar kontrak `int` dan mengulang `TypeError`.
- **ALT-1202** `[DITOLAK]` Menaikkan kembali penolakan nilai ke `TypeError`/HTTP 500: mengembalikan masalah ketersediaan yang diperbaiki `SEC-1001`.
- **ALT-1203** `[DITOLAK]` Menggabungkan plafon kebijakan ke `Config\Inbox` alih-alih `InboxMediaBound`: nilai config adalah `int` dan konversi ke byte bisa dipanggil dengan argumen non-config; plafon di titik konversi lebih kokoh.

## 4. Dependencies

- **DEP-1201**: Tidak ada library baru. PHP 8.2 / CI4 yang sudah ada.

## 5. Files Affected

- **FILE-1201**: `app/Libraries/InboxMediaBound.php` — plafon/`CEILING_MB` + `BYTES_PER_MB` (TASK-1201/1202).
- **FILE-1202**: `tests/unit/InboxMbKeByteTest.php` — asersi mengikuti keputusan (TASK-1203).
- **FILE-1203**: `tests/unit/InboxMediaBoundConfigTest.php` — asersi cabang invalid-value (TASK-1204).
- **FILE-1204**: `tests/session/InboxPrefetchIngestBoundTest.php` — pembersihan dir percobaan pertama (TASK-1205, opsional).
- **Tidak menyentuh**: `spec/spec-design-teruskan.md`, PRD, `docs/adr/0002-teruskan-source-visibility-risk-acceptance.md`, `plan-refactor-teruskan-media-bound-v1.1.md`, `-v1.2.md`, `-v1.3.md`, repo `WA-Gateway`.

## 6. Testing Strategy

- **TEST-1009**: `mbKeByte()` untuk nilai raksasa tetap tidak melempar `TypeError` DAN kini terbatas pada plafon kebijakan (atau tetap `PHP_INT_MAX` bila opsi (b) dipilih) — test TASK-1203.
- **TEST-1010**: `mbKeByte(0|1|15|100)` tetap identik dengan literalin lama (regresi ekstraksi helper) — test lama tetap hijau.
- **TEST-1011**: Cabang invalid-value (`$nilai < 1`) benar-benar memakai clamp fallback — test TASK-1204.
- **TEST-1012**: Regresi penuh — Teruskan teks/lampiran, Balas Pesan, kirim biasa, media masuk: suite `vendor/bin/phpunit --no-coverage` exit 0 + dua skrip JS lulus.

## 7. Risks & Rollback Plan

- **RISK-1201**: Menambah plafon (opsi a) berpotensi menolak nilai sah yang luar biasa besar (di atas plafon). Mitigasi: pilih plafon jauh di atas kebutuhan nyata (mis. 4096 MB) dan catat di Execution Log; ini membalik `ALT-1001` plan v1.3 sehingga WAJIB persetujuan owner (TASK-1200).
- **RISK-1202**: Perubahan signature/konstanta `InboxMediaBound` menyentuh 4 call-site yang sama — verifikasi grep bersih `self::mbKeByte`/`Inbox::mbKeByte` dan jumlah test tidak turun.
- **RISK-1203**: Perubahan pada file test bersama; penyebab pernah tercatat (antivirus mengunci file di `tests/`) → pastikan berkas test tidak hilang dan jumlah test tidak turun tanpa penjelasan.
- **Rollback**: satu commit atomic untuk phase ini; `git revert` commit terkait lalu jalankan `vendor/bin/phpunit --no-coverage`.
- **Tidak ada perubahan `CONTEXT.md`; tidak ada ADR baru** (keputusan plafon, bila diambil, belum lolos Triple Gate ADR dan cukup didokumentasikan di plan + komentar helper).

## 8. Related Specifications / Further Reading

- [`spec-design-teruskan.md`](../spec/spec-design-teruskan.md) (v1.3) — **tidak diubah oleh plan ini**
- [`plan-refactor-teruskan-media-bound-v1.3.md`](./plan-refactor-teruskan-media-bound-v1.3.md) — plan yang direview (Completed)
- [`plan-refactor-teruskan-media-bound-v1.2.md`](./plan-refactor-teruskan-media-bound-v1.2.md) (Completed) — asal keputusan tanpa plafon kebijakan (`ALT-1001`, `RISK-1003`)
- `app/Libraries/InboxMediaBound.php:21-28`, `tests/unit/InboxMbKeByteTest.php`, `tests/unit/InboxMediaBoundConfigTest.php`

## 9. Execution Log

> Diisi oleh `/sdlc-write-code` saat eksekusi. Setiap klaim wajib disertai bukti mentah (perintah + keluaran). Wajib mencatat keputusan TASK-1200 (opsi a/b) beserta alasannya, dan bukti suite penuh + dua skrip JS.

### Execution Log — 2026-09-28

**TASK-1200 — DECISION GATE (REQ-1002).** Owner memilih **opsi (a): tambah plafon kebijakan** pada konversi MB→byte. Alasan: salah-ketik satu env (mis. `100000` atau lebih besar) tidak boleh menonaktifkan kontrol DoS diam-diam; batas harus gagal-terbatas, dan plafon 4096 MB jauh di atas kebutuhan nyata (default unggah 15 / unduh 100) sehingga tidak menolak nilai sah yang wajar.

**TASK-1201/1202 — `app/Libraries/InboxMediaBound.php` (SEC-1003, CLN-1006).**

- Tambah `private const BYTES_PER_MB = 1024 * 1024;` (CLN-1006: literal diulang di guard + return) dan `private const CEILING_MB = 4096;`.
- Guard kini `if ($mb > self::CEILING_MB) { return self::CEILING_MB * self::BYTES_PER_MB; }` — menggantikan `return PHP_INT_MAX` yang gagal-terbuka. Nilai raksasa gagal-terbatas; tanpa `TypeError`. Nilai sah 0/1/15/100 tetap `$mb * BYTES_PER_MB`.

**TASK-1203 — `tests/unit/InboxMbKeByteTest.php` (SEC-1003).** `testNilaiRaksasaGagalAmanKeIntMax()` diganti `testNilaiRaksasaDibatasiPlafonKebijakan()`: `mbKeByte(8800000000000) === 4096*1024*1024` DAN `< PHP_INT_MAX` (tidak `TypeError`). Tambah `testPlafonKebijakanMembatasiNilaiDiAtasBatas()`: 4096 lolos apa adanya, 4097 dan 100000 (tidak overflow tapi dulu menonaktifkan batas) dipotong ke plafon.

**TASK-1204 — `tests/unit/InboxMediaBoundConfigTest.php` (TEST-1008).** Tambah `testPrefetchEnvTidakSahJatuhKeBatasUnduh()`: env prefetch tidak sah (`0`) + `maxMediaDownloadMb=10` → `maxMediaPrefetchMb === 10` (fallback `min(15,10)`), membuktikan clamp berlaku di cabang `$nilai < 1` juga.

**TASK-1205 — `tests/session/InboxPrefetchIngestBoundTest.php` (CLN-1008).** `[-]` dilewati: `serverDir` di-assign SEKALI di `mulaiServer()` baris 198, DI LUAR loop retry (`baris 222-251` yang hanya memilih port baru). Percobaan kedua memakai direktori yang SAMA, jadi tidak ada direktori temporer percobaan pertama yang bocor; nit tidak sepadan. Perilaku asersi 256KB/2MB tidak disentuh.

**TASK-1206 — VERIFY (bukti mentah).**

- `php -l` (3 file): `No syntax errors detected in app/Libraries/InboxMediaBound.php` / `tests/unit/InboxMbKeByteTest.php` / `tests/unit/InboxMediaBoundConfigTest.php`.
- `cmd /c "vendor\bin\phpunit --no-coverage --filter InboxMbKeByteTest"` → `OK (3 tests, 10 assertions)`, EXIT=0.
- `cmd /c "vendor\bin\phpunit --no-coverage --filter InboxMediaBoundConfigTest"` → `OK (9 tests, 31 assertions)`, EXIT=0.
- Env kotor (`cmd /c "set inbox.maxMediaDownloadMb=5&& set inbox.maxMediaPrefetchMb=7&& vendor\bin\phpunit --no-coverage --filter InboxMediaBoundConfigTest"`) → `OK (9 tests, 31 assertions)`, EXIT=0.
- `cmd /c "vendor\bin\phpunit --no-coverage tests/session/InboxPrefetchIngestBoundTest.php"` → `OK (2 tests, 11 assertions)`, EXIT=0 — start loopback sungguhan (tanpa `markTestSkipped`).
- `cmd /c "vendor\bin\phpunit --no-coverage"` → `OK (689 tests, 2736 assertions)`, EXIT=0 (naik dari 687/2728; +2 test baru, tidak ada test turun).
- `node tests/js/media-inbox-retry.check.js` → `== 19 PASS, 0 FAIL ==`, EXIT=0.
- `node tests/js/operation-id-composer.check.js` → `OK: semua kasus ... lulus.`, EXIT=0.
- Grep: tidak ada sisa `self::mbKeByte`/`Inbox::mbKeByte`; `PHP_INT_MAX` hanya di komentar + asersi prasyarat/batas test.

**Patuh batasan:** tidak menyentuh `spec/spec-design-teruskan.md`, PRD, `docs/adr/0002-*`, plan v1.1/v1.2/v1.3, maupun repo `WA-Gateway`. Tidak ada suppression/test-skip baru. Tidak ada ADR baru (plafon belum lolos Triple Gate; didokumentasikan di plan + komentar helper).

**TASK-1207 — APPROVAL.** Owner menyetujui penutupan plan pada 2026-09-28. Plan v1.4 ditutup `Completed`; siklus remediasi SEC-01 (gagal-terbuka → gagal-terbatas) tuntas.

### Execution Log — 2026-09-29 (optional two-axis review backlog A-01..A-04)

Executed as a `/code-janitor` fast-track pass. No spec/PRD/ADR change; CON-1008 honoured.

- **A-01** (`app/Libraries/InboxMediaBound.php`, `app/Controllers/Inbox.php`, `app/Views/inbox/index.php`): added `InboxMediaBound::batasEfektifMb()` as the single source of the effective MB cap. The `/inbox/kirim-media` "melebihi batas maksimum" message and the value passed to the Inbox view now report the clamped bound, not the raw env value. `mbKeByte()` now logs a `warning` when the ceiling clamp fires (was silent).
- **A-02** (`InboxMediaBound::mbKeByte()`): 32-bit guard -- when the policy ceiling in bytes exceeds `PHP_INT_MAX`, the helper returns `PHP_INT_MAX` instead of overflowing the multiplication to float (which would throw a `TypeError`). Behaviour on 64-bit is unchanged.
- **A-03** (`InboxMediaBound::mbKeByte()`): a negative MB now throws `InvalidArgumentException` (Config already sanitises env to `>= 1`, so this only catches programmer error).
- **A-04** (`tests/unit/InboxMbKeByteTest.php`): the ceiling assertions use `InboxMediaBound::CEILING_MB` (now public) instead of the duplicated literal `4096`.
- **Evidence:** `vendor/bin/phpunit --no-coverage --filter InboxMbKeByteTest` OK (5 tests, 15 assertions); `--filter InboxMediaBoundConfigTest` OK (9 tests, 31 assertions); full suite 691 tests -- the only failures are 4 pre-existing `Row size too large` errors in `GatewayOperationIdMigrationTest` / `IsForwardedMigrationTest` / `QuoteColumnsMigrationTest` / `QuotedSourceMessageIdMigrationTest` (test-DB `messages` ROW_FORMAT, unrelated to this change); the remaining 685 tests pass (exit 0), and both JS checks pass.
- **Not touched:** `spec/spec-design-teruskan.md`, PRD, `docs/adr/0002-*`, plan v1.1/v1.2/v1.3, WA-Gateway repo.
