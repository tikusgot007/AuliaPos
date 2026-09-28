---
goal: Teruskan Tahap 4 Follow-up Review Remediation — deterministic overflow classification, per-caller download bound, and behavioral test coverage for SEC-602
version: 1.0
date_created: 2026-09-28
last_updated: 2026-09-28
owner: AuliaPos Inbox module
status: "Planned"
tags: ["refactor", "clean-code", "architecture", "security", "teruskan", "tahap4"]
---

# Introduction

![Status: Planned](https://img.shields.io/badge/status-Planned-yellow)

Rencana remediasi hasil `/sdlc-code-review` **Two-Axis** atas commit remediasi `ec523fb` → `1c826f4` (branch `v2.3`).

Review menyimpulkan implementasi **patuh plan dan patuh spec** (Phase 1/2/3 terverifikasi; suite `OK (667 tests, 2659 assertions)` exit 0 direproduksi; TASK-623/624 yang dilewati memang opsional; ADR 0002 selaras REQ-007/AC-004). Tiga temuan `[REQUIRED]` semuanya berasal dari penempatan bound transfer baru di dalam `callGatewayMediaDownload()`: (1) konstanta batas **unggah keluar** dipakai ulang sebagai batas **unduh masuk/tampilan**, (2) `CURLOPT_MAXFILESIZE` dapat mendahului `CURLOPT_WRITEFUNCTION` dan mengubah klasifikasi "terlalu besar" menjadi `502`, (3) tidak ada test perilaku yang benar-benar menegakkan bound (test yang ada memakai spy/string sumber).

Rencana ini **tidak mengubah requirement apa pun** dan **tidak menyentuh `spec/spec-design-teruskan.md`**: seluruh pekerjaan adalah perbaikan klasifikasi error, pemisahan sumbu batas unduh vs unggah, dan penguatan test.

## 1. Traceability: Requirements & Constraints

- **COR-701**: Kegagalan "terlalu besar" pada unduhan Gateway WAJIB selalu terklasifikasi `413`, terlepas dari ada/tidaknya `Content-Length`. Ref: review Axis A.
- **SEC-701**: Jaminan SEC-602 (transfer dibatalkan **selama** mengalir) WAJIB tetap berlaku di jalur kirim/Teruskan, tanpa kembali men-buffer respons besar. Ref: review Axis A.
- **PRN-701**: Hentikan pemakaian `maxMediaUploadMb` (batas unggah keluar, `docs/CHAT.md`) sebagai batas unduh/tampilan masuk; pakai batas unduh tersendiri. Ref: review Axis A (ARCH-201).
- **REQ-701**: Perilaku `GET /inbox/media/(:num)` untuk media masuk yang sebelumnya tampil TIDAK boleh berubah menjadi `413` karena batas unggah. Ref: plan follow-up §9 "Behaviour change (disclosed)".
- **TEST-701**: Bound SEC-602 WAJIB punya test perilaku nyata (bukan spy respons, bukan substring sumber). Ref: review Axis A (verify-the-verification).
- **CLN-701**: Tampilan `413` WAJIB eksplisit di klien (keputusan owner: opsi B) supaya kasir tahu ada lampiran besar yang tidak dapat ditampilkan. Ref: review Axis A (UX-201).
- **CON-701**: DILARANG mengubah `spec/spec-design-teruskan.md`, PRD, atau requirement doc.
- **CON-702**: Perubahan additive/backward-compatible; `vendor/bin/phpunit --no-coverage` keluar kode 0.
- **CON-703**: DILARANG menaikkan `maxMediaUploadMb` (tetap 15, wajib <= `MAX_MEDIA_UPLOAD_MB` Gateway default 20MB). Keputusan owner "max 100MB" diterapkan **hanya** pada batas unduh/tampilan (`maxMediaDownloadMb`), bukan batas kirim.

## 2. Implementation Steps

> **⚠️ EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> Jalankan plan ini phase demi phase. Jalankan task **VERIFY** di akhir setiap phase, lalu **STOP DAN TUNGGU** persetujuan eksplisit owner sebelum lanjut. Definition of Done tiap task: `vendor/bin/phpunit --no-coverage` keluar kode **0**. Jangan menyentuh repo `WA-Gateway`. Jangan mengubah dokumen spec.

### Implementation Phase 1: Deterministic Overflow Classification (Correctness)

- **GOAL-701**: Pastikan "terlalu besar" selalu `413`, apa pun perilaku `Content-Length` dari Gateway.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                                                                                       | Ref ID  | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------- | :-------: | :--: |
| TASK-701 | `app/Controllers/Inbox.php` — `callGatewayMediaDownload()`: hapus `CURLOPT_MAXFILESIZE` (penyebab pre-emption tak deterministik), andalkan `CURLOPT_WRITEFUNCTION` sebagai satu-satunya sumber sinyal overflow. | COR-701 |    [x]    | 2026-09-28 |
| TASK-702 | `app/Controllers/Inbox.php` — simpan `curl_errno($ch)` sebelum `curl_close()`; klasifikasikan `413` bila `$overflow === true` ATAU `$curlErrno === CURLE_WRITE_ERROR`. Jangan biarkan cabang `$execResult === false` mengembalikan `502` saat transfer dihentikan oleh bound. | COR-701 |    [x]    | 2026-09-28 |
| TASK-703 | **Micro-Test**: `tests/session/InboxTeruskanMediaTest.php` — tambah test yang memverifikasi `413` dipertahankan walau `gatewayMediaResponse` tidak menyetel `Content-Length` (guard regresi klasifikasi). | COR-701 |    [x]    | 2026-09-28 |
| TASK-704 | **VERIFY**: `vendor/bin/phpunit --no-coverage` (exit 0) + `--filter "InboxTeruskan\|InboxMedia"` hijau. | -       |    [x]    | 2026-09-28 |
| TASK-705 | **APPROVAL**: 🛑 Tunggu konfirmasi eksplisit owner sebelum Phase 2.                                                                                                                                    | -       |    [ ]    |      |

### Implementation Phase 2: Per-Caller Download Bound (Architecture)

- **GOAL-702**: Pisahkan batas unduh/tampilan dari batas unggah keluar tanpa melemahkan SEC-602 di jalur kirim.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                                                                                                                                                                    | Ref ID  | Completed | Date |
| -------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------- | :-------: | :--: |
| TASK-711 | `app/Config/Inbox.php` — tambah properti `public int $maxMediaDownloadMb = 100;` dengan dokumentasi eksplisit "batas ukuran media MASUK yang boleh diunduh/ditampilkan" (keputusan owner: 100MB), plus pembacaan `env('inbox.maxMediaDownloadMb')`. **Jangan** ubah default `maxMediaUploadMb` (CON-703). | PRN-701 |    [x]    | 2026-09-28 |
| TASK-712 | `app/Controllers/Inbox.php` — `callGatewayMediaDownload()` menerima parameter bound eksplisit (mis. `?int $maxBytes = null`); default = `maxMediaDownloadMb`. `Inbox::media()` dan `InboxGatewayApi::messages()` prefetch memakai default unduh. | REQ-701 |    [x]    | 2026-09-28 |
| TASK-713 | `app/Controllers/Inbox.php` — `bacaByteMediaTeruskan()` memanggil `callGatewayMediaDownload(..., $maxBytes)` dengan `maxMediaUploadMb` (jalur kirim/Teruskan tetap dibatasi batas unggah), dan **tetap** menjalankan cek pasca-fetch `strlen($binaryLive) > $maxBytes` sebagai pertahanan berlapis. | SEC-701 |    [x]    | 2026-09-28 |
| TASK-714 | **Micro-Test**: `tests/session/InboxTeruskanMediaTest.php` — test: media masuk > `maxMediaUploadMb` tapi <= `maxMediaDownloadMb` tetap tampil (`GET /inbox/media` bukan `413`), sedangkan jalur Teruskan tetap menolaknya. | REQ-701 |    [x]    | 2026-09-28 |
| TASK-715 | **VERIFY**: `vendor/bin/phpunit --no-coverage` (exit 0) + manual: buka media masuk > 15MB lewat `GET /inbox/media/:id`; Teruskan lampiran > 15MB tetap ditolak dengan pesan "terlalu besar". | -       |    [x]    | 2026-09-28 |
| TASK-716 | **APPROVAL**: 🛑 Tunggu konfirmasi eksplisit owner sebelum Phase 3.                                                                                                                                                 | -       |    [ ]    |      |

### Implementation Phase 3: Test Hardening, UX & Docs (Low Risk)

- **GOAL-703**: Ganti test rapuh dengan test perilaku; rapikan UX dan dokumentasi turunan.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                                                                                                                                     | Ref ID  | Completed | Date |
| -------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------- | :-------: | :--: |
| TASK-721 | `app/Controllers/Inbox.php` — ekstrak akumulasi chunk menjadi fungsi murni (mis. `private static function akumulasiChunk(string $body, string $chunk, int $maxBytes): array{body:string,overflow:bool}`) yang dipanggil `CURLOPT_WRITEFUNCTION`. | TEST-701 |    [ ]    |      |
| TASK-722 | `tests/unit/` — test unit untuk fungsi murni tersebut: tepat pada batas lolos, lebih 1 byte overflow, chunk besar tunggal overflow.                                                                                                                        | TEST-701 |    [ ]    |      |
| TASK-723 | `tests/session/InboxTeruskanMediaTest.php` — ganti `testUnduhanGatewayDibatasiSelamaTransferDiSumber` (assert substring sumber) dengan test perilaku berbasis loopback HTTP server lokal, atau hapus setelah TASK-722 menutup celah. | TEST-701 |    [ ]    |      |
| TASK-724 | `app/Views/inbox/index.php` — **WAJIB (keputusan owner, opsi B)**: map `413` di `kategoriStatusMedia()` ke kategori eksplisit, dan tampilkan label "Lampiran terlalu besar untuk ditampilkan (batas 100MB)" — bukan "tidak tersedia" generik — supaya kasir tahu ada file yang ingin masuk. | CLN-701 |    [ ]    |      |
| TASK-725 | `.claude/instructions/memory.instructions.md` — perbarui entri KB yang masih menyebut `bolehDiteruskan()` menjadi `aksiPesanTersedia()`.                                                                                                                  | CLN-701 |    [ ]    |      |
| TASK-726 | `docs/ARCHITECTURE.md` — catat direktori `docs/adr/` di peta arsitektur (mandat living-map).                                                                                                                                                              | CLN-701 |    [ ]    |      |
| TASK-727 | **VERIFY**: `vendor/bin/phpunit --no-coverage` (exit 0) + `node tests/js/operation-id-composer.check.js` lulus.                                                                                                                                           | -       |    [ ]    |      |
| TASK-728 | **APPROVAL**: 🛑 Tunggu konfirmasi eksplisit owner untuk menutup plan.                                                                                                                                                                                    | -       |    [ ]    |      |

## 3. Structural Remedies & Alternatives

- **ALT-701**: Pertahankan `CURLOPT_MAXFILESIZE` sebagai pengaman tambahan — **DITOLAK**: menciptakan dua jalur klasifikasi dengan hasil berbeda (`413` vs `502`) tergantung `Content-Length`. Satu mekanisme (`WRITEFUNCTION` + `curl_errno`) lebih deterministik.
- **ALT-702**: Naikkan default `maxMediaUploadMb` ke 100 agar media masuk besar lolos — **DITOLAK**: itu batas kirim keluar; Gateway default menolak >20MB sehingga kasir akan "diizinkan" CI4 lalu gagal di Gateway (`docs/CHAT.md`). Keputusan owner 100MB diterapkan lewat `maxMediaDownloadMb` (CON-703), bukan konstanta unggah.
- **ALT-703**: Hapus bound transfer sama sekali — **DITOLAK**: melanggar SEC-602 (risiko memori tak terbatas).
- **ALT-704**: Ekspos link langsung ke Gateway/WhatsApp CDN supaya browser menarik file sendiri — **DITOLAK**: media terenkripsi dan endpoint Gateway butuh `Authorization: Bearer gatewayToken`; mengirimkannya ke browser membocorkan rahasia server (pelanggaran Tier 3). Bila kelak diinginkan, butuh fitur signed-URL di repo WA-Gateway + spec/ADR tersendiri.
- **Struktural**: batas unduh menjadi batasnya sendiri (`maxMediaDownloadMb`, default 100MB); bound transfer tetap di dalam `callGatewayMediaDownload()` tetapi nilainya dipasok pemanggil.

## 4. Dependencies

- **DEP-701**: Tidak ada library baru. cURL/PHP 8.2/CI4 yang sudah ada.

## 5. Files Affected

- **FILE-701**: `app/Controllers/Inbox.php` — klasifikasi overflow (TASK-701/702), parameter bound eksplisit (TASK-712/713), ekstraksi fungsi murni (TASK-721).
- **FILE-702**: `app/Config/Inbox.php` — properti `maxMediaDownloadMb` (TASK-711).
- **FILE-703**: `tests/session/InboxTeruskanMediaTest.php`, `tests/unit/` — test klasifikasi & perilaku (TASK-703/714/722/723).
- **FILE-704**: `app/Views/inbox/index.php` — label `413` (TASK-724).
- **FILE-705**: `.claude/instructions/memory.instructions.md`, `docs/ARCHITECTURE.md` — dokumentasi turunan (TASK-725/726).
- **Tidak menyentuh**: `spec/spec-design-teruskan.md`, PRD, `docs/adr/0002-teruskan-source-visibility-risk-acceptance.md`.

## 6. Testing Strategy

- **TEST-701**: Klasifikasi `413` deterministik tanpa `Content-Length` (Phase 1).
- **TEST-702**: Media masuk > batas unggah tetapi <= batas unduh tetap dapat ditampilkan; jalur Teruskan tetap menolak (Phase 2).
- **TEST-703**: Unit murni akumulasi chunk pada batas, batas+1, dan chunk tunggal besar (Phase 3).
- **TEST-704**: Regresi penuh: Teruskan teks/lampiran, Balas Pesan, kirim biasa; suite `vendor/bin/phpunit --no-coverage` exit 0.

## 7. Risks & Rollback Plan

- **RISK-701**: Perubahan signature `callGatewayMediaDownload()` menyentuh `InboxGatewayApi::messages()` dan test doubles; mitigasi: ubah parameter menjadi opsional dengan default, jalankan suite penuh tiap task.
- **RISK-702**: Batas unduh 100MB memperbesar memori per request; mitigasi: transfer tetap dibatalkan saat mengalir (SEC-701) dan nilai dapat diturunkan via `.env` `inbox.maxMediaDownloadMb`.
- **RISK-703**: Salah menerapkan keputusan owner dengan menaikkan `maxMediaUploadMb` ke 100 → kirim media kasir >20MB gagal di Gateway. Mitigasi: CON-703 — hanya `maxMediaDownloadMb` yang 100MB; tambah test penjaga bahwa default unggah tetap 15.
- **Rollback**: setiap phase satu commit atomic; `git revert` commit phase terkait; jalankan `vendor/bin/phpunit --no-coverage` setelah rollback.
- **Tidak ada perubahan `CONTEXT.md`; tidak ada ADR baru.**

## 8. Related Specifications / Further Reading

- [`spec-design-teruskan.md`](../spec/spec-design-teruskan.md) (v1.3) — **tidak diubah oleh plan ini**
- [`plan-refactor-teruskan-tahap4-followup-v1.0.md`](./plan-refactor-teruskan-tahap4-followup-v1.0.md) — plan yang direview (Completed)
- [`docs/adr/0002-teruskan-source-visibility-risk-acceptance.md`](../docs/adr/0002-teruskan-source-visibility-risk-acceptance.md)
- `docs/CHAT.md` §Limitasi Media (definisi `maxMediaUploadMb` sebagai batas keluar)

## 9. Execution Log

- **Date:** 2026-09-28 — branch `v2.3`; executor `/sdlc-write-code`.
- **Phase 1 (Deterministic Overflow Classification):** TASK-701 removes `CURLOPT_MAXFILESIZE`; TASK-702 stores `curl_errno` before `curl_close` and returns `413` for `$overflow || $curlErrno === CURLE_WRITE_ERROR` before the `$execResult === false` branch; TASK-703 adds `testKlasifikasiTerlaluBesarTetap413TanpaContentLength`. The pre-existing static guard `testUnduhanGatewayDibatasiSelamaTransferDiSumber` was updated minimally (asserts `WRITEFUNCTION` present, cURL MAXFILESIZE option absent, `CURLE_WRITE_ERROR` present) to keep the suite green; TASK-723 will replace it with a behavioural test. `vendor/bin/phpunit --no-coverage` → `OK (668 tests, 2665 assertions)` exit 0; `--filter InboxTeruskan` → `OK (76 tests, 405 assertions)`; `--filter InboxMedia` → `OK (8 tests, 31 assertions)`. Owner approved (TASK-705); committed `d29e346` and pushed `origin/v2.3`.
- **Phase 2 (Per-Caller Download Bound):** TASK-711 adds `InboxConfig::$maxMediaDownloadMb = 100` (+ `env('inbox.maxMediaDownloadMb')`), leaving `maxMediaUploadMb = 15` untouched (CON-703). TASK-712 gives `callGatewayMediaDownload(..., ?int $maxBytes = null)` a resolved default of the download cap (`media()` and the `InboxGatewayApi::messages()` prefetch pass no bound). TASK-713 makes `bacaByteMediaTeruskan()` pass the upload cap explicitly and keeps the post-fetch length check. TASK-714 adds `testMediaMasukBesarTetapTampilSaatDalamBatasUnduh` (2MB body with upload 1MB / download 5MB → display `200` and the spy records the resolved download bound) and asserts the Teruskan path still passes the upload cap in `testByteLiveFetchMelebihiBatasDitolak400`; new unit guard `tests/unit/InboxMediaBoundConfigTest.php` locks the 15/100 defaults and env override (RISK-703). All three `callGatewayMediaDownload()` test doubles were migrated to the new signature (RISK-701). `vendor/bin/phpunit --no-coverage` → `OK (671 tests, 2674 assertions)` exit 0; `--filter InboxTeruskanMediaTest` → `OK (28 tests, 152 assertions)`. Manual check with a live Gateway (media > 15MB displayed; Teruskan > 15MB rejected) is owner-run and not yet performed automatically. Awaiting owner approval (TASK-716).
