---
goal: Teruskan (Tahap 4, AuliaPos) Refactoring & Hardening — close server-side enforcement gap, bound media bytes, re-validate caption, remove structural smells
version: 1.0
date_created: 2026-09-28
last_updated: 2026-09-28
owner: AuliaPos Inbox module
status: "Planned"
tags: ["refactor", "clean-code", "architecture", "security", "teruskan", "tahap4"]
---

# Introduction

![Status: Planned](https://img.shields.io/badge/status-Planned-yellow)

Refactoring plan hasil `/sdlc-code-review` atas fitur **Teruskan** (Tahap 4, sisi AuliaPos) dengan fixed point `2eca592` → HEAD `40caf14` (branch `v2.3`). Review menemukan fungsionalitas inti sudah patuh spec (10/10 AC terpenuhi, non-stacking, ownership, idempotensi, all-or-nothing benar), tetapi ada **satu celah penegakan server** (media-source bisa diteruskan lewat jalur teks sebagai caption-only), **dua celah validasi batas** (byte live-fetch tak dibatasi, panjang caption sumber tak divalidasi ulang), dan sejumlah **code smell struktural** (parameter flag bertambah, method panjang, daftar tipe terduplikasi antar-layer).

Plan ini **tidak mengubah requirement**. Semua remediasi adalah implementasi ulang dari REQ/CON/GUD yang sudah ada, ditambah perapian struktur dan dokumentasi.

## 1. Traceability: Requirements & Constraints

- **REQ-101**: Penegakan forwardability server-side harus dua arah — endpoint teks (`/inbox/kirim`) WAJIB menolak sumber selain `text`; endpoint media (`/inbox/kirim-media`) sudah menolak sumber non-lampiran (`app/Controllers/Inbox.php:1130`). Ref: SPEC-01, REQ-006, CON-002, GUD-001 spec `spec-design-teruskan.md`.
- **SEC-201**: Byte media hasil live-fetch WAJIB dibatasi `maxMediaUploadMb` sebelum `base64_encode`, agar tidak ada jalur memori tak terbatas. Ref: review SEC-02.
- **SEC-202**: Caption turunan pesan sumber WAJIB divalidasi ≤1024 karakter setelah disalin (`Inbox.php:1185`), memakai pesan 400 yang sudah ada (`Inbox.php:1048`). Ref: review SEC-03.
- **PRN-301**: Daftar tipe forwardable-lampiran harus satu sumber kebenaran, tidak dua salinan server+view. Ref: review ARCH-04.
- **PRN-302**: Signature `kirimKeConversation(array, string $text, ?int)` yang membuang `$text` di mode Teruskan adalah Flag Argument + dead input; harus dihilangkan. Ref: review ARCH-03.
- **PRN-303**: Pertumbuhan parameter `?bool $forward = null` di `callGatewaySend`/`callGatewaySendMedia` (6 & 10 parameter) adalah Long Parameter List; eksklusivitas `quoted`/`forward` harus ditegakkan di satu tipe, bukan di ingatan pemanggil. Ref: review ARCH-01.
- **PRN-304**: `kirimMedia()` (~340 baris) dan `kirimKeConversation()` (~255 baris) melayani dua use case (kirim biasa vs Teruskan) — Divergent Change / Long Method. Ref: review ARCH-02.
- **DOC-401**: Rujukan `file:line` `cekOwnership()` di spec/plan basi (`:727`/`:755`; aktual `app/Controllers/Inbox.php:796`). Ref: SPEC-02.
- **DOC-402**: `forward_marker_applied` bisa `null` saat rollout parsial — di luar enum spec `"native" | "text_fallback"`. Ref: SPEC-03.
- **CLN-401**: Duplikasi predikat kelayakan di view (`renderAksiBalas` vs `bolehDiteruskan`) dan nama generator menyesatkan (`buatOperationIdBalasan` dipakai Teruskan). Ref: SMELL-01, SMELL-02.
- **CLN-402**: Normalisasi `is_forwarded` ke bool di `apiMessages()` seperti `is_internal` (`Inbox.php:363-365`). Ref: SMELL-04.
- **TEST-501**: Tambah test penjaga khusus celah REQ-101 (sumber media lewat `/inbox/kirim` → 400, tanpa baris tersimpan, Gateway tidak dipanggil).
- **CON-101**: Perubahan harus additive/backward-compatible untuk kirim biasa dan Balas Pesan; `vendor/bin/phpunit --no-coverage` keluar kode 0.
- **CON-102**: WAJIB mempertahankan keputusan spec yang disengaja: `cekOwnership()` **hanya** pada percakapan tujuan; sumber **tidak** dicek (REQ-007/AC-004). Jangan menambah guard visibilitas sumber tanpa perubahan spec.

## 2. Implementation Steps

> **⚠️ EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> Jalankan plan ini phase demi phase. Jalankan task **VERIFY** di akhir setiap phase, lalu **STOP DAN TUNGGU** persetujuan eksplisit owner sebelum lanjut. Definition of Done tiap task: `vendor/bin/phpunit --no-coverage` keluar kode **0**. Jangan menyentuh repo `WA-Gateway`.

### Implementation Phase 1: Correctness & Security Hardening (REQ-101, SEC-201, SEC-202)

- **GOAL-101**: Menutup celah penegakan server dan batas input, dengan test penjaga; perilaku UI/UX tidak berubah.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                                    | Ref ID   | Completed | Date |
| -------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- | -------- | :-------: | :--: |
| TASK-101 | `app/Controllers/Inbox.php` — di cabang `if ($isForward)` pada `kirimKeConversation()` (sekitar `:2469-2501`), setelah `resolveTeruskan()` berhasil, tolak sumber yang `message_type !== 'text'` dengan `400` (pakai pesan baru mis. `PESAN_TERUSKAN_HARUS_MEDIA = 'Lampiran harus diteruskan lewat jalur media.'`), tanpa menulis baris apa pun. Cermin terbalik dari guard `TIPE_TERUSKAN_LAMPIRAN` di `kirimMedia()` `:1130`. | REQ-101  |    [ ]    |      |
| TASK-102 | `app/Controllers/Inbox.php` — di `kirimMedia()` setelah `$caption = trim($sumber['text'])` (`:1185`), validasi `strlen($caption) > 1024` → `400` "Caption terlalu panjang (maksimal 1024 karakter)." (pesan yang sama dengan `:1048`); lakukan **sebelum** `callGatewaySendMedia()`. | SEC-202  |    [ ]    |      |
| TASK-103 | `app/Controllers/Inbox.php` — batasi byte live-fetch: di `bacaByteMediaTeruskan()` (`:2810-2857`) tolak `strlen($hasil['binary']) > $maxBytes` (`$maxBytes = $config->maxMediaUploadMb * 1024 * 1024`) dengan `PESAN_TERUSKAN_MEDIA_GAGAL_DIAMBIL`; jalur disk lokal juga divalidasi panjangnya sebelum `base64_encode` di `:1198`. | SEC-201  |    [ ]    |      |
| TASK-104 | **Micro-Test**: `tests/session/InboxTeruskanTest.php` — tambah test: sumber `image`/`document` dikirim ke `POST /inbox/kirim` dengan `forward_from_message_id` → `400`, tidak ada baris `messages` baru, Gateway tidak dipanggil. | TEST-501 |    [ ]    |      |
| TASK-105 | **Micro-Test**: `tests/session/InboxTeruskanMediaTest.php` — tambah test: caption sumber >1024 karakter → `400`; byte live-fetch melebihi `maxMediaUploadMb` → `400` tanpa baris tersimpan. | SEC-201, SEC-202 |    [ ]    |      |
| TASK-106 | **VERIFY**: `vendor/bin/phpunit --no-coverage` (exit 0) + `--filter InboxTeruskan` (hijau) + manual: paksa `POST /inbox/kirim` dengan sumber gambar → `400`; regresi teruskan teks & lampiran tetap sukses. | -        |    [ ]    |      |
| TASK-107 | **APPROVAL**: 🛑 Tunggu konfirmasi eksplisit owner sebelum Phase 2.                                                                                    | -        |    [ ]    |      |

### Implementation Phase 2: Structural Remedies (PRN-301, PRN-302, PRN-303, PRN-304)

- **GOAL-102**: Menghentikan pertumbuhan parameter/flag dan duplikasi antar-layer tanpa mengubah perilaku yang teruji.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                                       | Ref ID   | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------- | :-------: | :--: |
| TASK-201 | `app/Controllers/Inbox.php` + `app/Views/inbox/index.php` — jadikan daftar tipe lampiran forwardable satu sumber kebenaran: konstanta server `TIPE_TERUSKAN_LAMPIRAN` (`:82`) di-embed ke view (mis. lewat `json_encode` ke variabel JS yang menggantikan `JALUR_MEDIA_TERUSKAN` di `index.php:2111`), atau tampilkan capability flag dari server. | PRN-301  |    [ ]    |      |
| TASK-202 | `app/Controllers/Inbox.php` — hapus Flag Argument pada `kirimKeConversation()` (`:2403`): pisahkan jalur Teruskan teks menjadi entry point sendiri (mis. `kirimTeruskanTeks()` yang memanggil helper bersama), sehingga `$text` tidak pernah diterima-lalu-dibuang. | PRN-302  |    [ ]    |      |
| TASK-203 | `app/Controllers/Inbox.php` — perkenalkan value object/DTO request kirim (mis. `app/Libraries/InboxOutgoingRequest.php` atau setara) yang mengangkut `chatId`, media/teks, `operationId`, `quoted`, `forward`, dan menegakkan eksklusivitas `quoted` XOR `forward` di konstruktor; ubah `callGatewaySend()` (`:3030`) & `callGatewaySendMedia()` (`:3139`) menerima satu argumen DTO. | PRN-303  |    [ ]    |      |
| TASK-204 | `app/Controllers/Inbox.php` — ekstrak helper service dari `kirimMedia()`/`kirimKeConversation()` untuk menyusutkan method: minimal `resolveForwardMediaSource()` (atribut + byte, memindahkan `bacaByteMediaTeruskan()`) dan pemisahan orkestrasi "kirim biasa" vs "Teruskan". | PRN-304  |    [ ]    |      |
| TASK-205 | **Micro-Test**: pertahankan seluruh test Teruskan/Balas/kirim-media hijau setelah refactor; tambah test unit untuk DTO (menolak `quoted`+`forward` bersamaan) dan untuk `resolveForwardMediaSource()` (disk → live-fetch → permanen-gone). | PRN-303, PRN-304 |    [ ]    |      |
| TASK-206 | **VERIFY**: `vendor/bin/phpunit --no-coverage` (exit 0) + manual smoke: teruskan teks, teruskan gambar, balas berkutipan, kirim biasa — semua tidak berubah. | -        |    [ ]    |      |
| TASK-207 | **APPROVAL**: 🛑 Tunggu konfirmasi eksplisit owner sebelum Phase 3.                                                                                       | -        |    [ ]    |      |

### Implementation Phase 3: Documentation, Cleanups & Minor UX (DOC-401, DOC-402, CLN-401, CLN-402, PERF-01)

- **GOAL-103**: Sinkronisasi rujukan dokumen dan bereskan smell kecil; tidak menyentuh jalur kirim.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                          | Ref ID            | Completed | Date |
| -------- | --------------------------------------------------------------------------------------------------------------------------------------------- | ----------------- | :-------: | :--: |
| TASK-301 | `spec/spec-design-teruskan.md` (`REQ-007`, line 82) & `plan/plan-feature-teruskan-auliapos-v1.0.md` (Section 1 `REQ-007`) — perbarui `file:line` `cekOwnership()` menjadi `app/Controllers/Inbox.php:796`. | DOC-401           |    [ ]    |      |
| TASK-302 | `spec/spec-design-teruskan.md` (`REQ-003`) — dokumentasikan `null` sebagai nilai sah ketiga `forward_marker_applied` (rollout parsial), atau nyatakan field boleh absen. Tanpa perubahan kode wajib. | DOC-402           |    [ ]    |      |
| TASK-303 | `app/Views/inbox/index.php` — `renderAksiBalas()` (`:2204-2212`) memanggil `bolehDiteruskan(m)`/predikat bersama untuk kelayakan; rename `buatOperationIdBalasan()` → `buatOperationId()` (pemanggil `ambilOperationIdTeruskan` `:3263`). | CLN-401           |    [ ]    |      |
| TASK-304 | `app/Controllers/Inbox.php` — di `apiMessages()` (`:363-365`) cast `is_forwarded` ke bool bersama `is_internal`, sehingga view tidak perlu menebak `true/1/"1"`. | CLN-402           |    [ ]    |      |
| TASK-305 | `app/Views/inbox/index.php` — modal pemilih tujuan (`muatDaftarTujuanTeruskan()` `:2399`) dukung paginasi (reuse loader halaman rekursif) atau tampilkan afordansi "cari untuk melihat semua" yang jelas saat hasil page 1 penuh. Opsional, hanya bila owner menghendaki cakupan >50 percakapan. | PERF-01           |    [ ]    |      |
| TASK-306 | **VERIFY**: `vendor/bin/phpunit --no-coverage` (exit 0) + lint markdown pada spec/plan yang diubah + manual: label "Diteruskan" & UI aksi tetap benar. | -                 |    [ ]    |      |
| TASK-307 | **APPROVAL**: 🛑 Tunggu konfirmasi eksplisit owner untuk menutup plan.                                                                      | -                 |    [ ]    |      |

## 3. Structural Remedies & Alternatives

- **ALT-101**: Menambah guard visibilitas sumber (`resolveTeruskan` mensyaratkan kasir berhak atas percakapan sumber) — **DITOLAK**: melanggar `REQ-007`/`AC-004` spec yang secara eksplisit mengizinkan meneruskan dari sumber yang tidak dimiliki. Celah ini dicatat sebagai risiko laten ([FYI] SEC-01), bukan defect; perubahan hanya boleh lewat amendment spec.
- **ALT-102**: Menghapus kolom `is_forwarded` dan menyimpulkan label dari respons Gateway — **DITOLAK**: `REQ-008`/AC-008 mensyaratkan label berdiri sendiri dari metode Gateway.
- **ALT-103**: Menolak media-source di `resolveTeruskan()` untuk kedua endpoint — **DITOLAK**: akan mematikan Teruskan lampiran (sah lewat `/inbox/kirim-media`). Guard harus dipasang **per endpoint**.
- **ALT-104**: Menyimpan salinan byte media ke disk saat Teruskan — sudah ditolak plan fitur (PRD 8.2, `ALT-006`); plan ini tidak mengubahnya.
- **Struktural**: value object request menggantikan penambahan parameter flag (PRN-303); single-source daftar tipe menggantikan duplikasi server/view (PRN-301).

## 4. Dependencies

- **DEP-101**: Tidak ada library baru. Semua memakai pola CI4/PHP yang sudah ada.
- **DEP-102**: `WA-Gateway` tetap di luar scope; kontrak `forward` + `forward_marker_applied` sudah ter-deploy (commit `4a766d2…`) dan tidak berubah.
- **DEP-103**: `php spark migrate` tidak dijalankan plan ini (tidak ada perubahan skema).

## 5. Files Affected

- **FILE-101**: `app/Controllers/Inbox.php` — guard tipe dua arah (REQ-101), validasi caption (SEC-202), batas byte (SEC-201), pemisahan entry point (PRN-302), DTO (PRN-303), ekstraksi helper (PRN-304), normalisasi `is_forwarded` (CLN-402).
- **FILE-102**: `app/Views/inbox/index.php` — single-source daftar tipe (PRN-301), predikat bersama + rename + paginasi modal (CLN-401, PERF-01).
- **FILE-103**: `app/Libraries/InboxOutgoingRequest.php` (baru, atau nama setara) — DTO request kirim (PRN-303).
- **FILE-104**: `app/Models/MessageModel.php` — hanya bila ekstraksi menyentuh akses model (kemungkinan tidak).
- **FILE-105**: `tests/session/InboxTeruskanTest.php`, `tests/session/InboxTeruskanMediaTest.php` — test penjaga baru (TEST-501, SEC-201, SEC-202).
- **FILE-106**: `spec/spec-design-teruskan.md`, `plan/plan-feature-teruskan-auliapos-v1.0.md` — sinkronisasi `file:line` & enum (DOC-401, DOC-402).

## 6. Testing Strategy

- **TEST-101**: Media-source via `/inbox/kirim` → `400`, tanpa insert, Gateway tak dipanggil (TEST-501).
- **TEST-102**: Caption sumber >1024 → `400`; byte live-fetch > `maxMediaUploadMb` → `400` (SEC-201, SEC-202).
- **TEST-103**: DTO menolak `quoted` + `forward` bersamaan; `resolveForwardMediaSource()` mengembalikan disk → live-fetch → permanen-gone sesuai urutan (PRN-303, PRN-304).
- **TEST-104**: Regresi penuh: Teruskan teks/lampiran, Balas Pesan (teks & media), kirim biasa, idempotensi `operation_id` — semua tetap hijau; suite `vendor/bin/phpunit --no-coverage` exit 0.
- **CATATAN**: label "Diteruskan" di WhatsApp tetap hanya bisa diverifikasi manual (tidak ada test otomatis).

## 7. Risks & Rollback Plan

- **RISK-101**: Guard `message_type !== 'text'` pada jalur teks salah menolak kasus sah — **Mitigasi**: guard dipasang setelah `resolveTeruskan()` dan hanya aktif saat `$isForward`; test regresi teruskan teks + Balas teks wajib hijau.
- **RISK-102**: Refactor DTO/ekstraksi helper menyentuh jalur kirim biasa & Balas Pesan — **Mitigasi**: lakukan per langkah kecil dengan suite penuh di setiap task; rollback = `git revert` commit per-phase.
- **RISK-103**: Batas byte live-fetch menolak lampiran besar yang sah yang sebelumnya lolos — **Mitigasi**: batas = `maxMediaUploadMb` yang sudah berlaku untuk unggahan kasir; pesan error memakai pesan "gagal mengambil lampiran" yang sudah ada.
- **RISK-104**: Paginasi modal menambah kompleksitas JS — **Mitigasi**: opsional/bertahap; default tetap page 1 + pencarian server (target tetap terjangkau lewat `q`).
- **Rollback**: setiap phase satu commit atomic; `git revert` commit phase terkait. Tidak ada migrasi/env yang perlu direstorasi. Jalankan `vendor/bin/phpunit --no-coverage` setelah rollback.
- **Tidak ada ADR baru; `CONTEXT.md` tidak berubah.**

## 8. Related Specifications / Further Reading

- [`spec-design-teruskan.md`](../spec/spec-design-teruskan.md) (v1.2)
- [`plan-feature-teruskan-auliapos-v1.0.md`](./plan-feature-teruskan-auliapos-v1.0.md)
- [`spec-design-balas-pesan.md`](../spec/spec-design-balas-pesan.md) — pola `resolveKutipan` & eksklusivitas kutipan
- [`spec-design-grup-tahap1-tab-inbox.md`](../spec/spec-design-grup-tahap1-tab-inbox.md) — pola `CON-002` (opsi disabled + alasan)
- `docs/ARCHITECTURE.md` §Inbox module
