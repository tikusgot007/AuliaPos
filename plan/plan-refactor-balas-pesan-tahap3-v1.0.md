---
goal: Remediasi temuan code review Tahap 3 (Balas Pesan) — urutan guard otorisasi, bound trust boundary, fallback tampilan media, dan kebersihan arsitektur
version: 1.0
date_created: 2026-09-27
last_updated: 2026-09-27
owner: AuliaPos Inbox module
status: "Planned"
tags: ["refactor", "clean-code", "architecture", "security", "balas-pesan", "tahap3"]
---

# Introduction

![Status: Planned](https://img.shields.io/badge/status-Planned-yellow)

Refactoring plan ini menindaklanjuti `/sdlc-code-review` formal atas implementasi fitur **Balas Pesan (Tahap 3, GH-015)** di repo AuliaPos (baseline `c0d10f3..b205b5f`) dan WA-Gateway (`1db79e1..a2ba409`). Review menemukan satu cacat urutan otorisasi (alias oracle keberadaan pesan), satu trust-boundary tanpa bound, satu celah cabang acceptance criteria yang belum diimplementasikan, plus utang kebersihan arsitektur. Fitur secara fungsional sudah berjalan dan diuji manual; plan ini **memperbaiki cacat dan menyelaraskan kode dengan spec**, tanpa menambah requirement baru.

> [!IMPORTANT]
> **Tidak ada perubahan perilaku yang diinginkan pengguna** selain penutupan celah keamanan dan pemenuhan `AC-005`. Semua task bersifat pembetulan; `AC`/`REQ` tidak diubah. Bila remediasi `SPEC-001` dianggap tidak layak teknis, jalur alternatifnya adalah mengembalikan cabang tersebut ke `/sdlc-define-specs` (lihat `ALT-001`), bukan menghapus acceptance criteria diam-diam.

## 1. Traceability: Requirements & Constraints

- **SEC-001**: Guard kutipan F-A di jalur teks harus dijalankan **setelah** `cekOwnership()` — selaras dengan `kirimMedia()` — agar kasir tanpa hak tidak dapat memakai endpoint teks sebagai oracle keberadaan pesan lintas-percakapan (OWASP A01).
- **SEC-002**: Teks dari Gateway (`quoted.snippet`) yang disimpan ke `messages.quoted_snippet` wajib dibatasi panjangnya di sisi AuliaPos (trust boundary), tidak mengandalkan truncation Gateway.
- **ARCH-001**: Lookup pesan sumber soft-delete-inclusive tidak boleh memakai query builder mentah di controller; route lewat method Model (Dependency Rule).
- **PRN-001**: Hindari Duplicated Code (Fowler) pada perakitan respons `quote_applied`.
- **REQ-008 / AC-005**: `quoted_media_available` tiga-nilai + **fallback tampilan**: media sumber yang gagal dimuat (`404`/`410`/error) menampilkan `[Media tidak tersedia]` **tanpa** menulis ulang DB (snapshot beku, `REQ-007`).
- **REQ-001**: `quoted.sender_jid` tidak dikirim untuk sumber outgoing (`fromMe: true`).
- **CON-001**: Perbaikan tidak boleh mengubah kontrak `quoted`/`quote_applied` yang sudah disepakati spec v1.5.
- **CON-002**: Tidak menyentuh fitur Teruskan (Tahap 4) maupun body `cekOwnership()`.

## 2. Implementation Steps

> **⚠️ EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> Jalankan plan ini phase demi phase. Jalankan task **VERIFY** di akhir setiap phase. Setelah diuji, **STOP DAN TUNGGU** persetujuan eksplisit user sebelum lanjut. Definition of Done setiap task: `vendor/bin/phpunit --no-coverage` keluar kode **0**. Jangan melemahkan guard test lama (perbarui string call-site bila signature berubah, jangan dihapus).

### Implementation Phase 1: Security Remediation — urutan otorisasi & bound trust boundary

- **GOAL-001**: Menutup oracle keberadaan pesan lintas-percakapan di jalur teks, dan membatasi teks Gateway yang disimpan.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                                                                                                                                 | Ref ID          | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | --------------- | :-------: | :--: |
| TASK-101 | Di `app/Controllers/Inbox.php` `kirimKeConversation()`: pindahkan pembacaan `quoted_message_id` + pemanggilan `resolveKutipan()` beserta blok `400`-nya ke **setelah** blok `cekOwnership()` (`403`), persis urutan `kirimMedia()` (`:985` lalu `:1005`). Pastikan tidak ada kolom snapshot yang ditulis saat `403`. | SEC-001         |    [ ]    |      |
| TASK-102 | Micro-test di `tests/session/InboxBalasPesanTest.php`: sesi kasir **tidak memiliki** percakapan tujuan + `quoted_message_id` milik percakapan lain → respons **`403`** (bukan `400`), dan respons tidak mengonfirmasi/menyangkal keberadaan pesan tersebut. | SEC-001         |    [ ]    |      |
| TASK-103 | Di `app/Controllers/InboxGatewayApi.php` `resolveKutipanMasuk()` cabang "tidak ditemukan": batasi `$snippetPayload` dengan `mb_substr($snippetPayload, 0, InboxQuoteSnapshotService::MAKS_KARAKTER)` (atau reuse `potongSnippet()`) sebelum disimpan ke `quoted_snippet`. | SEC-002         |    [ ]    |      |
| TASK-104 | Micro-test di `tests/session/InboxGatewayApiKutipanMasukTest.php`: payload `quoted.snippet` sangat panjang (mis. 5.000 karakter) untuk `wa_message_id` yang tidak ada → `quoted_snippet` tersimpan terpotong sesuai batas, `quoted_sender_label` tetap `NULL` (F-B), pesan tetap `200`. | SEC-002         |    [ ]    |      |
| TASK-105 | **VERIFY**: `vendor/bin/phpunit --no-coverage` (harus exit 0) + `--filter InboxBalasPesanTest`/`--filter InboxGatewayApiKutipanMasukTest`. | -               |    [ ]    |      |
| TASK-106 | **APPROVAL**: 🛑 tunggu konfirmasi eksplisit user sebelum lanjut ke Phase 2.                                                                                                                                                                        | -               |    [ ]    |      |

### Implementation Phase 2: Pemenuhan AC & keselarasan spec

- **GOAL-002**: Memenuhi cabang *display-time* `AC-005` dan menyelaraskan detail kontrak `quoted` serta jejak plan.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                                                                                                                                 | Ref ID          | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | --------------- | :-------: | :--: |
| TASK-201 | Di `app/Views/inbox/index.php` `renderKotakKutipan()`: implementasikan fallback tampilan `REQ-008`/`AC-005` — saat kutipan bertipe media dan `quoted_media_available` bukan `0`, coba muat media sumber (mis. lewat resolusi `quoted_wa_message_id` → pesan lokal → `GET /inbox/media/(:num)`, bila seam tersedia) dengan handler `onerror` bahwa kegagalan (`404`/`410`/error) menampilkan teks literal `[Media tidak tersedia]` **tanpa menulis DB**. Bila seam media sumber tidak dapat diakses dari snapshot, **STOP** dan jalankan `ALT-001` (kembalikan ke `/sdlc-define-specs`), jangan tambal setengah jalan. | REQ-008, AC-005 |    [ ]    |      |
| TASK-202 | Micro-test di `tests/session/InboxBalasPesanScreenTest.php`: bubble kutipan media dengan `quoted_media_available = 1` yang pemuatan medianya gagal → menampilkan `[Media tidak tersedia]`; snapshot DB tidak berubah. | REQ-008, AC-005 |    [ ]    |      |
| TASK-203 | Di `app/Controllers/Inbox.php` `quotedPayloadGateway()`: saat `fromMe` true, jangan sertakan `sender_jid` (set `null`/hilangkan key). Micro-test: payload `/send` untuk sumber outgoing tidak memuat `sender_jid`. | REQ-001         |    [ ]    |      |
| TASK-204 | Di `plan/plan-feature-balas-pesan-auliapos-v1.0.md`: tandai `TASK-009`/`TASK-013` dengan bukti/ditanggalkan keberadaannya — catat eksplisit bahwa gate APPROVAL per-slice dilipat ke persetujuan akhir `TASK-016`, sehingga jejak plan konsisten. | CON-001         |    [ ]    |      |
| TASK-205 | **VERIFY**: `vendor/bin/phpunit --no-coverage` (exit 0) + cek manual browser untuk fallback media.                                                                                                                                                  | -               |    [ ]    |      |
| TASK-206 | **APPROVAL**: 🛑 tunggu konfirmasi eksplisit user sebelum lanjut ke Phase 3.                                                                                                                                                                        | -               |    [ ]    |      |

### Implementation Phase 3: Kebersihan arsitektur (non-blocking)

- **GOAL-003**: Menghapus duplikasi pengetahuan akses data dan duplikasi perakitan respons.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                                                                                                                                 | Ref ID  | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------- | :-------: | :--: |
| TASK-301 | Tambah `MessageModel::findByIdIncludingDeleted(int $id): ?array` (pola `findByWaMessageId()`, tanpa filter `deleted_at`); ubah `Inbox::resolveKutipan()` memakai method itu alih-alih query builder mentah (`app/Controllers/Inbox.php:2431-2434`). | ARCH-001 |    [ ]    |      |
| TASK-302 | Ekstrak helper privat `withQuoteApplied(array $body, ?array $quoteSnapshot, array $result): array` di `app/Controllers/Inbox.php`; pakai di 4 lokasi (`:1085`, `:1167`, `:2293`, `:2364`). | PRN-001 |    [ ]    |      |
| TASK-303 | **VERIFY**: `vendor/bin/phpunit --no-coverage` (exit 0); pastikan perilaku `quote_applied` tidak berubah (test lama hijau).                                                                                                                            | -       |    [ ]    |      |
| TASK-304 | **APPROVAL**: 🛑 tunggu konfirmasi eksplisit user bahwa refactor ditutup.                                                                                                                                                                             | -       |    [ ]    |      |

## 3. Structural Remedies & Alternatives

- **ALT-001**: Bila fallback tampilan media (`TASK-201`) tidak dapat direalisasikan karena snapshot tidak menyimpan referensi media yang dapat dimuat ulang, jalur yang benar adalah mengembalikan cabang tersebut ke `/sdlc-define-specs` untuk mencabut/mengubah `REQ-008`/`AC-005` — **bukan** membiarkan kode dan spec berbeda.
- **ALT-002**: Memindahkan seluruh otorisasi ke `kirim()`/`kirimMedia()` alih-alih di dalam `kirimKeConversation()` — ditolak, karena menyebar cek otorisasi dan memutus pola `kirimMedia()` yang sudah benar.

## 4. Dependencies

- **DEP-001**: Tidak ada dependensi pihak ketiga baru. Semua perbaikan memakai API internal (`MessageModel`, `InboxQuoteSnapshotService`) dan CodeIgniter 4 yang sudah ada.

## 5. Files Affected

- **FILE-001**: `app/Controllers/Inbox.php` — urutan guard (`kirimKeConversation()`), `quotedPayloadGateway()`, `resolveKutipan()` (pakai Model), helper `withQuoteApplied()`.
- **FILE-002**: `app/Controllers/InboxGatewayApi.php` — bound `quoted.snippet` di `resolveKutipanMasuk()`.
- **FILE-003**: `app/Models/MessageModel.php` — method `findByIdIncludingDeleted()`.
- **FILE-004**: `app/Views/inbox/index.php` — fallback tampilan media di `renderKotakKutipan()`.
- **FILE-005**: `tests/session/InboxBalasPesanTest.php`, `tests/session/InboxBalasPesanMediaTest.php`, `tests/session/InboxBalasPesanScreenTest.php`, `tests/session/InboxGatewayApiKutipanMasukTest.php` — test regresi baru.
- **FILE-006**: `plan/plan-feature-balas-pesan-auliapos-v1.0.md` — jejak TASK-009/013.

## 6. Testing Strategy

- **TEST-001**: Kasir non-pemilik + `quoted_message_id` lintas percakapan → `403`, tidak ada oracle keberadaan.
- **TEST-002**: `quoted.snippet` payload panjang → tersimpan terpotong, label tetap `NULL`, pesan tetap `200`.
- **TEST-003**: Bubble kutipan media usang (`= 1`) yang gagal dimuat → `[Media tidak tersedia]`, DB tidak berubah.
- **TEST-004**: Sumber outgoing → payload `/send` tanpa `sender_jid`.
- **TEST-005**: Regresi nol percakapan pribadi/grup tanpa kutipan; idempotensi `operation_id` tetap 1 baris.

## 7. Risks & Rollback Plan

- **RISK-001 (rendah)**: Memindahkan `resolveKutipan()` ke setelah `cekOwnership()` dapat mengubah pesan error pada kasus gabungan (tidak berhak **dan** kutipan tidak valid) dari `400` menjadi `403`. Ini **disengaja** (otorisasi menang) dan selaras keputusan `kirimMedia()`; rollback: kembalikan urutan semula via `git revert` commit remediasi.
- **RISK-002 (rendah)**: `TASK-201` menyentuh JS `renderKotakKutipan()`; risiko regresi tampilan. Mitigasi: test screen + verifikasi manual browser; rollback: revert perubahan view saja.
- **RISK-003 (rendah)**: Refactor `withQuoteApplied()`/`findByIdIncludingDeleted()` murni mekanis; rollback trivial via revert commit Phase 3.
