---
goal: Remediasi temuan code review ronde-2 Balas Pesan Tahap 3 — oracle keberadaan, object-level authz media, fidelitas tipe media pada kutipan, bound trust boundary, dan amplifikasi polling
version: 1.0
date_created: 2026-09-27
last_updated: 2026-09-27
owner: AuliaPos Inbox module
status: "Completed"
tags: ["refactor", "clean-code", "architecture", "security", "balas-pesan", "tahap3"]
---

# Introduction

![Status: Completed](https://img.shields.io/badge/status-Completed-brightgreen)

Plan ini menindaklanjuti `/sdlc-code-review` ronde-2 atas remediasi Tahap 3 (Balas Pesan, GH-015) di repo AuliaPos — commit `654ba97` (SEC-001/SEC-002) dan `4e0fb79` (Phase 2-3), baseline diff `88b28a6..4e0fb79` untuk `app/` dan `tests/`. Verifikasi terhadap `spec/spec-design-balas-pesan.md` v1.6 dan `plan/plan-refactor-balas-pesan-tahap3-v1.0.md`. Spec kemudian diamandemen ke **v1.7** (2026-09-27) lewat `TASK-201`: `REQ-008c` menambah `quoted_media_type`, sehingga gate Phase 2 diangkat.

Review ronde-2 mengonfirmasi: urutan `cekOwnership()` (SEC-001), bound `quoted.snippet` (SEC-002), `findByIdIncludingDeleted()` (ARCH-001), dan `withQuoteApplied()` (PRN-001) sudah benar dan sesuai spec. Yang tersisa adalah **satu gap fungsional** (media non-gambar yang tersedia dilabeli `[Media tidak tersedia]`), **empat cacat keamanan/robustness** pada boundary yang kini dipakai fitur ini, plus kebersihan test/jejak plan.

> [!NOTE]
> **`SPEC-B01` sudah teresolusi (spec v1.7, 2026-09-27).** Keputusan hulu `ALT-001` diambil oleh `/sdlc-define-specs`: **opsi (a)** — kolom snapshot `quoted_media_type` ditambahkan sebagai `REQ-008c` (`VARCHAR(30) NULL`, additive-nullable, `after: quoted_source_message_id`), dengan `REQ-008`/`REQ-013` direvisi dan `AC-005` menjadi lima cabang (a–e). Gate **blocked-by-spec Phase 2 diangkat**; `TASK-201` selesai.

> [!NOTE]
> Tidak ada perubahan kontrak `quoted`/`quote_applied` yang disepakati spec v1.5/v1.6/v1.7. `quoted_media_type` (v1.7) adalah metadata snapshot internal sisi AuliaPos untuk tampilan, bukan field kontrak Gateway. Semua task adalah pembetulan; tidak menambah requirement produk baru.

## 1. Traceability: Requirements & Constraints

- **SEC-001**: `resolveKutipan()` tidak boleh membocorkan keberadaan pesan lintas-percakapan lewat pesan `400` yang dapat dibedakan; pemanggil yang berhak atas percakapan tujuan tidak menjadi oracle untuk percakapan lain.
- **SEC-002**: `GET /inbox/media/(:num)` wajib memverifikasi otorisasi object-level (percakapan pemilik media) sebelum menyajikan binary.
- **SEC-003**: Input dari Gateway (`quoted.snippet`) yang bukan string tidak boleh melempar `TypeError`/`500`; degradasi anggun.
- **REQ-008c (spec v1.7)**: Snapshot kutipan menyimpan `quoted_media_type` — `message_type` sumber (`image`/`document`/`sticker`/`audio`/`video`), `NULL` untuk sumber teks, tidak ditemukan, atau baris legacy. UI memilih representasi per tipe: `image`/`sticker` → thumbnail via live-fetch; `document` → tautan; `audio`/`video` → label tanpa fetch. `[Media tidak tersedia]` muncul HANYA untuk `quoted_media_available = 0` atau kegagalan live-fetch `404`/`410`/error nyata (tanpa tulis DB).
- **PERF-001**: Media kutipan yang gagal dimuat tidak boleh di-fetch ulang setiap siklus polling 4 detik.
- **ARCH-001**: Pengetahuan akses data `messages` tinggal di Model; tidak ada query builder mentah di controller.
- **SPEC-001**: Wording `REQ-011` ("apa adanya") selaras dengan perilaku `potongSnippet()` (bound + normalisasi) atau sebaliknya. **RESOLVED (spec v1.8, `TASK-303`, 2026-09-27):** wording spec diselaraskan — bukan perubahan implementasi.
- **CON-001**: Tidak mengubah kontrak `quoted`/`quote_applied` yang disepakati spec v1.6/v1.7; `quote_applied`/`REQ-001`/`REQ-006` tidak disentuh.
- **CON-002**: Tidak menyentuh fitur Teruskan (Tahap 4); tidak mengubah body `cekOwnership()`.

## 2. Implementation Steps

> **⚠️ EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> Jalankan plan ini phase demi phase. Jalankan task **VERIFY** di akhir setiap phase. Setelah diuji, **STOP DAN TUNGGU** persetujuan eksplisit user sebelum lanjut. Definition of Done setiap task: `vendor/bin/phpunit --no-coverage` keluar kode **0**. Jangan melemahkan guard test lama (perbarui string call-site bila signature berubah, jangan dihapus). Jangan menambah `@ts-ignore`/skip test/suppression lint.

### Implementation Phase 1: Security Remediation — oracle, authz media, trust boundary

- **GOAL-001**: Menutup oracle keberadaan residual, menegakkan otorisasi object-level pada media, dan mencegah `500` dari input Gateway non-string.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                                                                                                                                 | Ref ID  | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------- | :-------: | :--: |
| TASK-101 | Di `app/Controllers/Inbox.php` `resolveKutipan()`: satukan dua pesan `400` (`:2464` "tidak ditemukan" dan `:2470` "bukan dari percakapan ini") menjadi satu pesan generik (mis. `'Pesan yang ingin dikutip tidak valid.'`); perbedaan cabang tetap dicatat hanya lewat `log_message()`. | SEC-001 | [x] | 2026-09-27 |
| TASK-102 | Micro-test di `tests/session/InboxBalasPesanTest.php`: kasir yang **berhak atas percakapan tujuan** mengirim `quoted_message_id` (a) pesan nyata di percakapan lain, dan (b) ID tidak ada → keduanya `400` dengan **body identik** (status + message) dan tidak ada baris ditulis. | SEC-001 | [x] | 2026-09-27 |
| TASK-103 | Di `app/Controllers/Inbox.php` `media()` (`:386`): setelah `find()`, muat percakapan pemilik (`conversation_id`) dan jalankan `cekOwnership()`; tolak `403`/`404` **sebelum** baca disk, ETag, atau `callGatewayMediaDownload`. Bila kebijakan `auth`-only adalah keputusan desain yang disengaja, hentikan task ini dan arahkan ke `/sdlc-define-specs` untuk mendokumentasikannya (jangan diam-diam mengubah perilaku). | SEC-002 | [x] | 2026-09-27 |
| TASK-104 | Micro-test di `tests/session/InboxMediaAuthTest.php` (baru) atau file test Inbox media existing: ID pesan media milik percakapan yang tidak boleh diakses sesi → `403`/`404`; media milik percakapan sendiri → `200`; tidak ada hubungi Gateway pada kasus ditolak. | SEC-002 | [x] | 2026-09-27 |
| TASK-105 | Di `app/Controllers/InboxGatewayApi.php:550`: jaga tipe sebelum `potongSnippet()` — `$raw = $quoted['snippet'] ?? null; $snippetPayload = is_string($raw) ? (new InboxQuoteSnapshotService())->potongSnippet($raw) : null;`. | SEC-003 | [x] | 2026-09-27 |
| TASK-106 | Micro-test di `tests/session/InboxGatewayApiKutipanMasukTest.php`: payload `quoted.snippet` berupa array (dan objek) untuk `wa_message_id` tidak ditemukan → respons `success` (bukan `500`), `quoted_snippet` = `'Pesan tidak ditemukan'`, `quoted_sender_label` tetap `NULL`. | SEC-003 | [x] | 2026-09-27 |
| TASK-107 | **VERIFY**: `vendor/bin/phpunit --no-coverage` (exit 0) + `--filter InboxBalasPesanTest`/`--filter InboxGatewayApiKutipanMasukTest`. | - | [x] | 2026-09-27 |
| TASK-108 | **APPROVAL**: 🛑 tunggu konfirmasi eksplisit user sebelum lanjut ke Phase 2. | - | [x] | 2026-09-27 |

### Implementation Phase 2: Fidelitas tipe media kutipan

- **GOAL-002**: Kutipan media menampilkan representasi sesuai tipe sumber; `[Media tidak tersedia]` hanya untuk kegagalan nyata; hentikan amplifikasi polling.

> [!NOTE]
> **Gate blocked-by-spec diangkat (2026-09-27).** `TASK-201` selesai — `/sdlc-define-specs` memutuskan **opsi (a)** dan mengamandemen `spec/spec-design-balas-pesan.md` ke **v1.7**: `REQ-008c` (`quoted_media_type`), `REQ-008`/`REQ-013` direvisi, `AC-005` menjadi lima cabang (a–e). `TASK-202`–`TASK-205` siap dieksekusi.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                                                                                                                                 | Ref ID   | Completed | Date |
| -------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------- | :-------: | :--: |
| TASK-201 | `/sdlc-define-specs`: putuskan mekanisme fidelitas tipe media (lihat `ALT-001`) — **diputuskan opsi (a)**. Spec diamandemen ke **v1.7**: `REQ-008c` + kolom snapshot `quoted_media_type` (`VARCHAR(30) NULL`, additive-nullable, `after: quoted_source_message_id`), `REQ-008`/`REQ-013` direvisi, `AC-005` lima cabang (a–e), Section 4.2/6/7/12/13 + migrasi `AddQuotedMediaTypeToMessages`. | REQ-008c | [x] | 2026-09-27 |
| TASK-202 | Implementasi keputusan `TASK-201` (**opsi a**): migrasi additive `app/Database/Migrations/<timestamp>_AddQuotedMediaTypeToMessages.php` (`quoted_media_type`, `VARCHAR(30) NULL`, `after: quoted_source_message_id`); isi di `InboxQuoteSnapshotService::rakitSnapshot()` dari `$sumber['message_type']` (satu titik — mencakup kutipan keluar & masuk; `NULL` untuk teks/tidak ditemukan/legacy); tambah `quoted_media_type` ke `MessageModel::$allowedFields`; ubah `app/Views/inbox/index.php` `renderKotakKutipan()`: `image`/`sticker` → `<img>`; `document` → tautan; `audio`/`video` → label tanpa fetch; `[Media tidak tersedia]` hanya saat `quoted_media_available = 0` atau `404`/`410`/error nyata. | REQ-008c | [x] | 2026-09-27 |
| TASK-203 | Di `app/Views/inbox/index.php`: catat kegagalan live-fetch kutipan di set `mediaGagal` (key `m.id`) dari `onerror`, dan short-circuit sebelum emisi `<img>` pada render berikutnya (pola `renderIsiPesan()` `:2089`/`:2115`; kunci implementasi di-namespace `'kutipan:' + m.id` supaya tidak bentrok dengan `mediaGagal.has(m.id)` milik render media pesan itu sendiri). | PERF-001 | [x] | 2026-09-27 |
| TASK-204 | Micro-test: `tests/unit/InboxQuoteSnapshotServiceTest.php` untuk penurunan `quoted_media_type` tiap tipe media vs `NULL` untuk teks/tidak ditemukan; `tests/database/AddQuotedMediaTypeToMessagesMigrationTest.php` untuk kolom baru; `tests/session/InboxBalasPesanScreenTest.php` diperkuat atau di-ekstraksi agar menguji keputusan cabang (a–e), bukan sekadar grep string. | REQ-008c | [x] | 2026-09-27 |
| TASK-205 | **VERIFY**: `vendor/bin/phpunit --no-coverage` (exit 0) + verifikasi manual browser: kutipan gambar tampil thumbnail, kutipan dokumen tampil tautan, kutipan audio/video tampil label, media gagal tampil `[Media tidak tersedia]` sekali (tidak berulang tiap 4 detik). | - | [x] | 2026-09-27 |
| TASK-206 | **APPROVAL**: 🛑 tunggu konfirmasi eksplisit user sebelum lanjut ke Phase 3. | - | [x] | 2026-09-27 |

> [!NOTE]
> **Temuan tambahan saat verifikasi manual `TASK-205` (2026-09-27).** Verifikasi browser (data demo lokal, 7 kasus) mengonfirmasi kelima cabang `AC-005` benar: thumbnail untuk `image`, tautan untuk `document`, label untuk `audio`/`video`, `[Media tidak tersedia]` untuk `available=0`, dan snapshot apa adanya untuk baris legacy. Dua cacat ditemukan & ditutup **di luar** lingkup literal `TASK-203` tetapi di dalam semangat `PERF-001`:
>
> 1. **Kunci `mediaGagal` media pesan sendiri tidak pernah cocok (`renderIsiPesan()`).** `numberNative=false` membuat `m.id` berupa STRING, tetapi `onerror` menulis `mediaGagal.add(900010)` (angka) sementara pemeriksaannya `mediaGagal.has("900010")` (string). Akibatnya memori gagal (Tahap E) tidak efektif: polling 4 detik terus meminta ulang media gagal — terbukti dari log Apache (`/inbox/media/:id` diulang tiap 4 detik). Diperbaiki: kunci `onerror` kini string (pola sama dengan `kunciKutipanGagal`). Regresi dikunci oleh `tests/session/InboxBalasPesanScreenTest.php::testMediaGagalMedianPesanSendiriMemakaiKunciString`.
> 2. **Setup verifikasi (bukan kode produk):** `.env` `inbox.mediaStoragePath` dengan backslash membuat CI4 `DotEnv` menyimpan nilai berikut tanda kutip → file lokal tak terbaca → endpoint `media()` jatuh ke live-fetch Gateway (mati) → `502`. Diganti garis miring depan.
>
> `vendor/bin/phpunit --no-coverage` → **561 tests, 2153 assertions, exit 0** (baseline pra-sesi 543). `docs/ARCHITECTURE.md` §11 (kolom `quoted_media_type` + seam `InboxQuoteSnapshotService`) tetap menjadi carried-forward item, di luar lingkup plan ini.

### Implementation Phase 3: Kebersihan arsitektur, spec wording, jejak plan

- **GOAL-003**: Menghapus seam akses data yang tersisa, menyelaraskan wording spec, dan memperbaiki jejak plan/test.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                                                                                                                                 | Ref ID   | Completed | Date |
| -------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------- | :-------: | :--: |
| TASK-301 | Pindahkan `Inbox::findMessageByOperationId()` (`app/Controllers/Inbox.php:2390-2402`) ke `app/Models/MessageModel.php` sebagai method Model (mis. `findByOperationIdIncludingDeleted()`); perbarui call-site di `kirimKeConversation()` (`:2283`) dan jalur media. Tambahkan `->where('conversation_id', $conversationId)` pada lookup replay di kedua jalur kirim (scope percakapan). | ARCH-001 | [x] | 2026-09-27 |
| TASK-302 | Micro-test regresi: lookup replay tetap mengembalikan baris soft-deleted milik percakapan yang sama dan **tidak** mengembalikan baris percakapan lain dengan `operation_id` sama (uji ditempatkan di `tests/database/MessageModelSoftDeleteLookupTest.php`). | ARCH-001 | [x] | 2026-09-27 |
| TASK-303 | Di `spec/spec-design-balas-pesan.md` `REQ-011` (baris 142) dan Section 4.2: selaraskan wording "apa adanya" dengan perilaku `potongSnippet()` (bound + elipsis + normalisasi whitespace) — atau ubah implementasi memakai `mb_substr` murni tanpa normalisasi. Pilih satu, jangan dua standar. **SELESAI (spec v1.8, 2026-09-27):** `/sdlc-define-specs` memilih **perbaiki wording spec** (bukan ubah implementasi) — `REQ-011` (kedua cabang: sumber ditemukan dan tidak ditemukan) dan `AC-009` kini menyebutkan cuplikan fallback `quoted.snippet` Gateway dilewatkan lewat `potongSnippet()` yang sama seperti jalur sumber-ditemukan (satu standar: normalisasi whitespace + batas 200 karakter + elipsis bila terpotong); `ASSUMPTION-007` dan Section 4.2 diselaraskan senada. Tidak ada perubahan kode/perilaku — hanya wording spec. | SPEC-001 | [x] | 2026-09-27 |
| TASK-304 | Perbaiki jejak plan: `plan/plan-refactor-balas-pesan-tahap3-v1.0.md` — tandai `TASK-206` (APPROVAL) dan status manual `TASK-205`/`ENV-BLOCKER-001` secara konsisten dengan kenyataan (disetujui user atau ditandai waived), agar status `Completed` dapat dipertanggungjawabkan. | CON-001 | [x] | 2026-09-27 |
| TASK-305 | Perkuat test migrasi `tests/database/QuotedSourceMessageIdMigrationTest.php:66`: ganti asersi `COLUMN_TYPE === 'int(10) unsigned'` menjadi `DATA_TYPE === 'int'` + `IS_NULLABLE === 'YES'` + default `NULL` (portable lintas versi MySQL/MariaDB). | CON-001 | [x] | 2026-09-27 |
| TASK-306 | **VERIFY**: `vendor/bin/phpunit --no-coverage` (exit 0); test lama terkait replay/idempotensi tetap hijau. | - | [x] | 2026-09-27 |
| TASK-307 | **APPROVAL**: 🛑 tunggu konfirmasi eksplisit user bahwa remediasi ronde-2 ditutup. | - | [x] | 2026-09-27 |

> [!NOTE]
> **Status Phase 3 (2026-09-27).** `TASK-301`/`TASK-302`/`TASK-304`/`TASK-305`/`TASK-306` selesai: lookup replay pindah ke `MessageModel::findByOperationIdIncludingDeleted($operationId, $conversationId)` (scope percakapan) dan dipakai kedua jalur kirim; test regresi baru di `tests/database/MessageModelSoftDeleteLookupTest.php` (5 test ditambahkan ke 4 test `findByIdIncludingDeleted` yang sudah ada — 9 test, tidak ada test lama dihapus); test migrasi v1.6 dibuat portable (`DATA_TYPE`/`IS_NULLABLE`/default); jejak `plan-refactor-balas-pesan-tahap3-v1.0.md` disinkronkan. `vendor/bin/phpunit --no-coverage` → **566 tests, 2167 assertions, exit 0**. `TASK-303` **selesai** — spec diamandemen ke **v1.8** (`/sdlc-define-specs`, 2026-09-27) menyelaraskan wording `REQ-011`/`AC-009`/`ASSUMPTION-007` dengan `potongSnippet()`; tidak ada perubahan implementasi (lihat barisnya). `TASK-307` **disetujui & ditutup (2026-09-27)** — user mengonfirmasi remediasi ronde-2 selesai. Phase 3 tuntas; seluruh task Phase 1–3 plan ini berstatus selesai.

## 3. Structural Remedies & Alternatives

- **ALT-001 (RESOLVED spec v1.7, 2026-09-27 — opsi (a) dipilih)**: `[Media tidak tersedia]` untuk media tersedia (`SPEC-B01`/`REQ-008c`) — snapshot v1.6 hanya menyimpan `quoted_wa_message_id`/`quoted_sender_label`/`quoted_snippet`/`quoted_media_available`/`quoted_source_message_id`, **tanpa tipe media sumber**. `quoted_snippet` untuk media bercaption berisi caption (bukan label `[Dokumen]`), jadi UI tidak dapat menyimpulkan tipe dari string tanpa melanggar semangat F-B ("jangan bandingkan string cuplikan").
  - **Opsi (a) — DIPILIH (spec v1.7):** tambah field snapshot `quoted_media_type` (nullable-additive, snapshot beku) via `/sdlc-define-specs` → amandemen v1.7; UI memilih representasi per tipe.
  - **Opsi (b) — minimal:** hidupkan live-fetch HANYA saat snapshot jelas berasal dari media tanpa caption, dan tampilkan label untuk sisanya; lebih kecil, tetapi menurunkan janji `AC-005(b)` (live-fetch media tersedia) dan sebagian media bercaption tak dapat thumbnail.
  - Opsi (c) — `fetch()` + dispatch `Content-Type`: lebih kompleks di klien, tetap butuh tahu cara render dokumen/audio/video; ditolak demi minimalisme.
- **ALT-002**: Membiarkan dua pesan `400` berbeda dan mengklaim "bukan kebocoran karena endpoint baca terbuka" — ditolak: `SEC-A01` tetap jalur informasi yang tidak perlu; penyatuan pesan nyaris tanpa biaya.
- **ALT-003**: Menambahkan guard `cekOwnership()` pada `GET /inbox/api/conversations/(:num)/messages` (AUTHZ-02) — **ditunda** ke `/sdlc-define-specs`: komentar `Routes.php:57-58` menyiratkan gerbang baca `auth`-only adalah keputusan desain. Bila ternyata buka-baca disengaja, hanya perlu dokumentasi; bila tidak, jadikan task terpisah setelah keputusan.

## 4. Dependencies

- **DEP-001**: Tidak ada dependensi pihak ketiga baru. Semua perbaikan memakai API internal (`MessageModel`, `InboxQuoteSnapshotService`) dan CodeIgniter 4 yang sudah ada.
- **DEP-002 (TERPENUHI)**: Keputusan spec (`/sdlc-define-specs`) atas `ALT-001` selesai — spec v1.7 (`REQ-008c`, `quoted_media_type`). `TASK-202`–`TASK-205` tidak lagi tertahan.

## 5. Files Affected

- **FILE-001**: `app/Controllers/Inbox.php` — pesan `400` generik (`resolveKutipan()`), otorisasi object-level (`media()`), pemindahan `findMessageByOperationId()` + scope percakapan, `quoted_source_message_id`/`quoted_media_type` di titik snapshot.
- **FILE-002**: `app/Controllers/InboxGatewayApi.php` — guard tipe `quoted.snippet` (`:550`); `quoted_media_type` diisi lewat `rakitSnapshot()` (opsi a).
- **FILE-003**: `app/Models/MessageModel.php` — method lookup operasi (menggantikan `Inbox::findMessageByOperationId()`), `allowedFields` `quoted_media_type`.
- **FILE-004**: `app/Services/InboxQuoteSnapshotService.php` — `rakitSnapshot()` mengisi `quoted_media_type` dari `$sumber['message_type']` (`NULL` selain media; REQ-008c).
- **FILE-005**: `app/Views/inbox/index.php` — `renderKotakKutipan()` representasi per tipe media + memori `mediaGagal`.
- **FILE-006**: `app/Database/Migrations/<timestamp>_AddQuotedMediaTypeToMessages.php` (baru, REQ-008c) — additive-nullable, tanpa index/FK.
- **FILE-007**: `spec/spec-design-balas-pesan.md` — amandemen `REQ-008`/`REQ-008c`/`REQ-013`/`AC-005`/Section 4.2 (**SELESAI** v1.7 via `/sdlc-define-specs`); wording `REQ-011`/`AC-009`/`ASSUMPTION-007`/Section 4.2 diselaraskan dengan `potongSnippet()` (**SELESAI** v1.8 via `/sdlc-define-specs`, `TASK-303`).
- **FILE-008**: `plan/plan-refactor-balas-pesan-tahap3-v1.0.md` — sinkronisasi status APPROVAL/manual.
- **FILE-009**: test — `tests/session/InboxBalasPesanTest.php`, `tests/session/InboxGatewayApiKutipanMasukTest.php`, `tests/session/InboxBalasPesanScreenTest.php`, `tests/database/MessageModelSoftDeleteLookupTest.php`, `tests/database/QuotedSourceMessageIdMigrationTest.php`, test media-auth baru, `tests/database/AddQuotedMediaTypeToMessagesMigrationTest.php`, `tests/unit/InboxQuoteSnapshotServiceTest.php`.

## 6. Testing Strategy

- **TEST-001**: Oracle tertutup — pemanggil berhak atas percakapan tujuan, `quoted_message_id` ke pesan percakapan lain vs ID tidak ada → body `400` identik.
- **TEST-002**: IDOR media — media milik percakapan tak berhak → `403`/`404` tanpa baca disk tanpa hubungi Gateway; percakapan sendiri → `200`.
- **TEST-003**: Trust boundary — `quoted.snippet` array/objek → `200 success`, cuplikan label generik, label tetap `NULL`.
- **TEST-004**: Fidelitas tipe media — kutipan gambar/stiker → thumbnail; dokumen → tautan; audio/video → label; media gagal → `[Media tidak tersedia]` **sekali** (tidak berulang saat polling).
- **TEST-005**: Regresi nol kutipan (pribadi/grup) tetap hijau; idempotensi `operation_id` tetap satu baris; replay tidak melintasi percakapan.
- **TEST-006**: `vendor/bin/phpunit --no-coverage` keluar kode 0 (target suite tetap hijau, jumlah test naik sesuai test baru).

## 7. Risks & Rollback Plan

- **RISK-001 (rendah, keamanan)**: Menyatukan pesan `400` menghilangkan sinyal UX yang membedakan "kutipan tidak valid" vs "bukan percakapan ini" bagi kasir sah. Mitigasi: pesan generik tetap informatif ("tidak valid"), detail penyebab di log server. Rollback: `git revert` commit Phase 1.
- **RISK-002 (sedang, perilaku)**: Menambahkan `cekOwnership()` pada `media()` dapat memutus tampilan media bila kebijakan baca-terbuka memang disengaja. Mitigasi: konfirmasi keputusan desain lebih dulu (`TASK-103`); rollback: revert commit media-auth saja.
- **RISK-003 (TERELIMINASI, spec)**: Ketergantungan amandemen spec `ALT-001` teratasi — spec v1.7 (`REQ-008c`) sudah final sebelum implementasi, sehingga tidak ada divergensi kode-vs-spec. Rollback tetap trivial karena kolom additive-nullable (`down()` drop).
- **RISK-004 (rendah)**: Perubahan JS `renderKotakKutipan()` berisiko regresi tampilan. Mitigasi: `TEST-004` + verifikasi manual browser; rollback: revert perubahan view.
- **RISK-005 (sedang, deploy)**: Kode menulis `quoted_media_type` pada **setiap** insert (jalur teks `Inbox.php:2362`, jalur media `Inbox.php:1163`, dan pesan masuk `InboxGatewayApi`), jadi kode **bergantung** pada kolom migrasi `2026-09-27-000003`. Bila aplikasi naik sebelum migrasi dijalankan (atau migrasi gagal), semua insert gagal `Unknown column` → HTTP 500; rollback kolom sambil kode masih naik memberi kegagalan sama. Mitigasi: **urutan rilis wajib migrasi-dulu-baru-kode** (kolom tetap ada saat rollback aplikasi) — didokumentasikan di docblock migrasi. Rollback: `git revert` commit kode lebih dulu, baru `down()` migrasi bila perlu.
