---
goal: Remediasi temuan code review ronde-2 Balas Pesan Tahap 3 — oracle keberadaan, object-level authz media, fidelitas tipe media pada kutipan, bound trust boundary, dan amplifikasi polling
version: 1.0
date_created: 2026-09-27
last_updated: 2026-09-27
owner: AuliaPos Inbox module
status: "Planned"
tags: ["refactor", "clean-code", "architecture", "security", "balas-pesan", "tahap3"]
---

# Introduction

![Status: Planned](https://img.shields.io/badge/status-Planned-yellow)

Plan ini menindaklanjuti `/sdlc-code-review` ronde-2 atas remediasi Tahap 3 (Balas Pesan, GH-015) di repo AuliaPos — commit `654ba97` (SEC-001/SEC-002) dan `4e0fb79` (Phase 2-3), baseline diff `88b28a6..4e0fb79` untuk `app/` dan `tests/`. Verifikasi terhadap `spec/spec-design-balas-pesan.md` v1.6 dan `plan/plan-refactor-balas-pesan-tahap3-v1.0.md`.

Review ronde-2 mengonfirmasi: urutan `cekOwnership()` (SEC-001), bound `quoted.snippet` (SEC-002), `findByIdIncludingDeleted()` (ARCH-001), dan `withQuoteApplied()` (PRN-001) sudah benar dan sesuai spec. Yang tersisa adalah **satu gap fungsional** (media non-gambar yang tersedia dilabeli `[Media tidak tersedia]`), **empat cacat keamanan/robustness** pada boundary yang kini dipakai fitur ini, plus kebersihan test/jejak plan.

> [!IMPORTANT]
> **`SPEC-B01` memerlukan keputusan hulu.** Memperbaiki `[Media tidak tersedia]` pada media tersedia menuntut snapshot mengetahui **tipe media sumber**, kolom yang belum ada di spec v1.6. Jalur yang benar adalah mengembalikan keputusan ini ke `/sdlc-define-specs` (lihat `ALT-001`), bukan menebak tipe dari `quoted_snippet` (fragile, dilarang oleh semangat F-B "jangan bandingkan string cuplikan"). Task `REQ-008c` di Phase 2 bersifat **blocked-by-spec**.

> [!NOTE]
> Tidak ada perubahan kontrak `quoted`/`quote_applied` yang disepakati spec v1.5/v1.6. Semua task adalah pembetulan; tidak menambah requirement produk baru.

## 1. Traceability: Requirements & Constraints

- **SEC-001**: `resolveKutipan()` tidak boleh membocorkan keberadaan pesan lintas-percakapan lewat pesan `400` yang dapat dibedakan; pemanggil yang berhak atas percakapan tujuan tidak menjadi oracle untuk percakapan lain.
- **SEC-002**: `GET /inbox/media/(:num)` wajib memverifikasi otorisasi object-level (percakapan pemilik media) sebelum menyajikan binary.
- **SEC-003**: Input dari Gateway (`quoted.snippet`) yang bukan string tidak boleh melempar `TypeError`/`500`; degradasi anggun.
- **REQ-008c**: Kutipan media harus menampilkan representasi yang benar sesuai **tipe media sumber** (gambar/stiker → thumbnail via live-fetch; dokumen/audio/video → label/tautan), sehingga `[Media tidak tersedia]` HANYA muncul untuk media yang benar-benar gagal dimuat (`404`/`410`/error).
- **PERF-001**: Media kutipan yang gagal dimuat tidak boleh di-fetch ulang setiap siklus polling 4 detik.
- **ARCH-001**: Pengetahuan akses data `messages` tinggal di Model; tidak ada query builder mentah di controller.
- **SPEC-001**: Wording `REQ-011` ("apa adanya") selaras dengan perilaku `potongSnippet()` (bound + normalisasi) atau sebaliknya.
- **CON-001**: Tidak mengubah kontrak `quoted`/`quote_applied` yang disepakati spec v1.6; `quote_applied`/`REQ-001`/`REQ-006` tidak disentuh.
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

### Implementation Phase 2: Fidelitas tipe media kutipan (blocked-by-spec)

- **GOAL-002**: Kutipan media menampilkan representasi sesuai tipe sumber; `[Media tidak tersedia]` hanya untuk kegagalan nyata; hentikan amplifikasi polling.

> **⚠️ BLOCKED-BY-SPEC:** `TASK-201`–`TASK-203` bergantung pada keputusan `/sdlc-define-specs` (amandemen spec v1.7 atau keputusan eksplisit user) atas `ALT-001`. Jangan mulai Phase 2 sebelum keputusan itu ada.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                                                                                                                                 | Ref ID   | Completed | Date |
| -------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------- | :-------: | :--: |
| TASK-201 | `/sdlc-define-specs`: putuskan mekanisme fidelitas tipe media (lihat `ALT-001`): (a) tambah field snapshot `quoted_media_type` additive-nullable (paling benar, konsisten snapshot beku), atau (b) batasi live-fetch. Perbarui `REQ-008`/`AC-005`/Section 4.2 + migrasi kolom. | REQ-008c | [ ] | |
| TASK-202 | Implementasi hasil keputusan `TASK-201`: migrasi additive kolom tipe media (bila opsi a), isi di `InboxQuoteSnapshotService::rakitSnapshot()`/`resolveKutipan()`/`resolveKutipanMasuk()`, dan ubah `app/Views/inbox/index.php` `renderKotakKutipan()`: `image`/`sticker` → `<img>`; `document` → tautan; `audio`/`video` → label/placeholder tanpa fetch; `[Media tidak tersedia]` hanya saat `404`/`410`/error nyata. | REQ-008c | [ ] | |
| TASK-203 | Di `app/Views/inbox/index.php`: catat kegagalan live-fetch kutipan di set `mediaGagal` (key `m.id`) dari `onerror`, dan short-circuit sebelum emisi `<img>` pada render berikutnya (pola `renderIsiPesan()` `:2089`/`:2115`). | PERF-001 | [ ] | |
| TASK-204 | Micro-test: `tests/unit/InboxQuoteSnapshotServiceTest.php` untuk tipe media (bila opsi a); `tests/database/<migrasi>_Test.php` untuk kolom baru; `tests/session/InboxBalasPesanScreenTest.php` diperkuat atau di-ekstraksi agar menguji keputusan cabang, bukan sekadar grep string. | REQ-008c | [ ] | |
| TASK-205 | **VERIFY**: `vendor/bin/phpunit --no-coverage` (exit 0) + verifikasi manual browser: kutipan gambar tampil thumbnail, kutipan dokumen tampil tautan, kutipan audio/video tampil label, media gagal tampil `[Media tidak tersedia]` sekali (tidak berulang tiap 4 detik). | - | [ ] | |
| TASK-206 | **APPROVAL**: 🛑 tunggu konfirmasi eksplisit user sebelum lanjut ke Phase 3. | - | [ ] | |

### Implementation Phase 3: Kebersihan arsitektur, spec wording, jejak plan

- **GOAL-003**: Menghapus seam akses data yang tersisa, menyelaraskan wording spec, dan memperbaiki jejak plan/test.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                                                                                                                                 | Ref ID   | Completed | Date |
| -------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------- | :-------: | :--: |
| TASK-301 | Pindahkan `Inbox::findMessageByOperationId()` (`app/Controllers/Inbox.php:2390-2402`) ke `app/Models/MessageModel.php` sebagai method Model (mis. `findByOperationIdIncludingDeleted()`); perbarui call-site di `kirimKeConversation()` (`:2283`) dan jalur media. Tambahkan `->where('conversation_id', $conversationId)` pada lookup replay di kedua jalur kirim (scope percakapan). | ARCH-001 | [ ] | |
| TASK-302 | Micro-test regresi: lookup replay tetap mengembalikan baris soft-deleted milik percakapan yang sama dan **tidak** mengembalikan baris percakapan lain dengan `operation_id` sama (uji ditempatkan di `tests/database/MessageModelSoftDeleteLookupTest.php`). | ARCH-001 | [ ] | |
| TASK-303 | Di `spec/spec-design-balas-pesan.md` `REQ-011` (baris 142) dan Section 4.2: selaraskan wording "apa adanya" dengan perilaku `potongSnippet()` (bound + elipsis + normalisasi whitespace) — atau ubah implementasi memakai `mb_substr` murni tanpa normalisasi. Pilih satu, jangan dua standar. | SPEC-001 | [ ] | |
| TASK-304 | Perbaiki jejak plan: `plan/plan-refactor-balas-pesan-tahap3-v1.0.md` — tandai `TASK-206` (APPROVAL) dan status manual `TASK-205`/`ENV-BLOCKER-001` secara konsisten dengan kenyataan (disetujui user atau ditandai waived), agar status `Completed` dapat dipertanggungjawabkan. | CON-001 | [ ] | |
| TASK-305 | Perkuat test migrasi `tests/database/QuotedSourceMessageIdMigrationTest.php:66`: ganti asersi `COLUMN_TYPE === 'int(10) unsigned'` menjadi `DATA_TYPE === 'int'` + `IS_NULLABLE === 'YES'` + default `NULL` (portable lintas versi MySQL/MariaDB). | CON-001 | [ ] | |
| TASK-306 | **VERIFY**: `vendor/bin/phpunit --no-coverage` (exit 0); test lama terkait replay/idempotensi tetap hijau. | - | [ ] | |
| TASK-307 | **APPROVAL**: 🛑 tunggu konfirmasi eksplisit user bahwa remediasi ronde-2 ditutup. | - | [ ] | |

## 3. Structural Remedies & Alternatives

- **ALT-001**: `[Media tidak tersedia]` untuk media tersedia (`SPEC-B01`/`REQ-008c`) — snapshot v1.6 hanya menyimpan `quoted_wa_message_id`/`quoted_sender_label`/`quoted_snippet`/`quoted_media_available`/`quoted_source_message_id`, **tanpa tipe media sumber**. `quoted_snippet` untuk media bercaption berisi caption (bukan label `[Dokumen]`), jadi UI tidak dapat menyimpulkan tipe dari string tanpa melanggar semangat F-B ("jangan bandingkan string cuplikan").
  - **Opsi (a) — rekomendasi:** tambah field snapshot `quoted_media_type` (nullable-additive, snapshot beku) via `/sdlc-define-specs` → amandemen v1.7; UI memilih representasi per tipe.
  - **Opsi (b) — minimal:** hidupkan live-fetch HANYA saat snapshot jelas berasal dari media tanpa caption, dan tampilkan label untuk sisanya; lebih kecil, tetapi menurunkan janji `AC-005(b)` (live-fetch media tersedia) dan sebagian media bercaption tak dapat thumbnail.
  - Opsi (c) — `fetch()` + dispatch `Content-Type`: lebih kompleks di klien, tetap butuh tahu cara render dokumen/audio/video; ditolak demi minimalisme.
- **ALT-002**: Membiarkan dua pesan `400` berbeda dan mengklaim "bukan kebocoran karena endpoint baca terbuka" — ditolak: `SEC-A01` tetap jalur informasi yang tidak perlu; penyatuan pesan nyaris tanpa biaya.
- **ALT-003**: Menambahkan guard `cekOwnership()` pada `GET /inbox/api/conversations/(:num)/messages` (AUTHZ-02) — **ditunda** ke `/sdlc-define-specs`: komentar `Routes.php:57-58` menyiratkan gerbang baca `auth`-only adalah keputusan desain. Bila ternyata buka-baca disengaja, hanya perlu dokumentasi; bila tidak, jadikan task terpisah setelah keputusan.

## 4. Dependencies

- **DEP-001**: Tidak ada dependensi pihak ketiga baru. Semua perbaikan memakai API internal (`MessageModel`, `InboxQuoteSnapshotService`) dan CodeIgniter 4 yang sudah ada.
- **DEP-002**: `TASK-201`–`TASK-203` bergantung pada keputusan spec (`/sdlc-define-specs`) atas `ALT-001`.

## 5. Files Affected

- **FILE-001**: `app/Controllers/Inbox.php` — pesan `400` generik (`resolveKutipan()`), otorisasi object-level (`media()`), pemindahan `findMessageByOperationId()` + scope percakapan, `quoted_source_message_id`/`quoted_media_type` di titik snapshot.
- **FILE-002**: `app/Controllers/InboxGatewayApi.php` — guard tipe `quoted.snippet` (`:550`); isi `quoted_media_type` bila opsi (a) dipilih.
- **FILE-003**: `app/Models/MessageModel.php` — method lookup operasi (menggantikan `Inbox::findMessageByOperationId()`), `allowedFields` kolom baru bila ada.
- **FILE-004**: `app/Services/InboxQuoteSnapshotService.php` — `rakitSnapshot()` menambah tipe media sumber (opsi a).
- **FILE-005**: `app/Views/inbox/index.php` — `renderKotakKutipan()` representasi per tipe media + memori `mediaGagal`.
- **FILE-006**: `app/Database/Migrations/<timestamp>_AddQuotedMediaTypeToMessages.php` (baru, bila opsi a) — additive-nullable, tanpa index/FK.
- **FILE-007**: `spec/spec-design-balas-pesan.md` — amandemen `REQ-008`/`REQ-011`/`AC-005`/Section 4.2 (via `/sdlc-define-specs`).
- **FILE-008**: `plan/plan-refactor-balas-pesan-tahap3-v1.0.md` — sinkronisasi status APPROVAL/manual.
- **FILE-009**: test — `tests/session/InboxBalasPesanTest.php`, `tests/session/InboxGatewayApiKutipanMasukTest.php`, `tests/session/InboxBalasPesanScreenTest.php`, `tests/database/MessageModelSoftDeleteLookupTest.php`, `tests/database/QuotedSourceMessageIdMigrationTest.php`, test media-auth baru, test migrasi tipe media (bila opsi a).

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
- **RISK-003 (sedang, spec)**: `TASK-201`–`TASK-203` bergantung pada amandemen spec; mengerjakan sebelum keputusan = kode dan spec berbeda (anti-pattern `ALT-001` plan lama). Mitigasi: gate blocked-by-spec; rollback trivial karena kolom additive-nullable (`down()` drop).
- **RISK-004 (rendah)**: Perubahan JS `renderKotakKutipan()` berisiko regresi tampilan. Mitigasi: `TEST-004` + verifikasi manual browser; rollback: revert perubahan view.
