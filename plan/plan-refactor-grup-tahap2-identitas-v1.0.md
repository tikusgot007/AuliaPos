---
goal: Grup Tahap 2 — Hardening Android Runtime Data, Gateway Group-Name Cache, dan Label Identitas Pengirim
version: 1.0
date_created: 2026-09-26
last_updated: 2026-09-26
owner: AuliaPos Inbox module + WA-Gateway (tikusgot007/WA-Gateway)
status: "Completed"
tags: ["refactor", "clean-code", "architecture", "security"]
---

<!-- markdownlint-disable -->

# Introduction

![Status: Completed](https://img.shields.io/badge/status-Completed-brightgreen)

Rencana perbaikan ini adalah tindak lanjut `docs/audit/code-review-grup-tahap2-2026-09-26.md`.
Lingkupnya **hanya temuan sisi kode** dari Grup Tahap 2 (identitas pengirim & judul grup WhatsApp):

- kebocoran sesi WhatsApp akibat penyelamatan `auth/`/`data/` yang tidak exception-safe di
  `NodeBridge.kt` (`[CORR-01]`);
- `groupMetadata()` yang dapat kembali menjadi satu panggilan jaringan per pesan saat cache gagal
  (`[CORR-02]`);
- label `@s.whatsapp.net` yang masih membawa sufiks device (`[CORR-03]`);
- pesan grup legacy yang tampil berlabel "Pengirim", teks pengganti yang dilarang PRD GH-013
  (`[SPEC-01]`, **keputusan spec v1.4: Opsi (a) label-aman**);
- batas panjang `group_name` di trust boundary (`[SEC-01]`), ekstraksi aturan label
  (`[ARCH-01]`), dan perbaikan fixture test legacy (`[CC-01]`).

Temuan **dokumentasi** (`SPEC-02` premis `CON-004` yang keliru, `SPEC-03` daftar Files plan Gateway,
`SPEC-04` scope `NodeBridge.kt` yang tidak terencana) **bukan** lingkup plan refactor ini dan harus
diselesaikan lewat `/sdlc-plan-tasks` dan `/sdlc-define-specs`.

> [!NOTE]
> **Catatan revisi (2026-09-26, tindak lanjut spec v1.4 — `SPEC-01`..`SPEC-04`).** Temuan dokumentasi
> yang sebelumnya dirutekan ke `/sdlc-plan-tasks` kini **selesai**: kedua plan Grup Tahap 2
> (`plan-feature-grup-tahap2-wa-gateway-v1.0.md` dan `...-auliapos-v1.0.md`) sudah diamandemen ke spec
> v1.4 — premis `CON-004` `SPEC-02`, peta berkas `SPEC-03`, dan scope `NodeBridge.kt` `SPEC-04`. Keputusan
> `SPEC-01` juga sudah tercatat di spec v1.4 (**Opsi (a) label-aman di kode**, opsi (b) bersih-bersih
> data **ditolak**), sehingga **Phase 4 di bawah kini UNBLOCKED** dan siap dieksekusi lewat
> `/sdlc-write-code`. Phases 1/2/3/5 tetap seperti terverifikasi di Phase 7b.

## 1. Traceability: Requirements & Constraints

- **REQ-001**: Pesan grup yang tersimpan sebelum Tahap 2 (`sender_jid` = JID grup `@g.us`) **tidak**
  boleh menampilkan label "Pengirim" yang menyesatkan; perilakunya harus sama dengan pesan tanpa
  identitas (`CON-003`, PRD GH-013). **Prasyarat TERPENUHI (spec v1.4):** keputusan `SPEC-01` =
  **Opsi (a) label-aman di kode** (`REQ-011`/`AC-012`); opsi (b) bersih-bersih data **ditolak**.
- **REQ-002**: Label untuk `sender_jid` berformat `@s.whatsapp.net` harus berupa nomor telepon bersih
  tanpa sufiks device (`:NN`) — konsisten dengan `REQ-008`/`AC-002`.
- **PRN-001**: Cache subject grup harus tahan kegagalan; kegagalan `groupMetadata()` tidak boleh
  berubah menjadi panggilan jaringan per pesan (maksud `GUD-001`/`ASSUMPTION-003`).
- **PRN-002**: Penyelamatan `auth/`/`data/` di Android harus exception-safe dan **tidak boleh** bisa
  menghapus sesi WhatsApp / buffer retry pada start berikutnya.
- **PRN-003**: Aturan tampilan identitas pengirim grup diekstrak agar dapat dipakai ulang oleh Tahap 3
  (kutipan menampilkan identitas pengirim, PRD GH-015).
- **SEC-001**: `group_name` dari Gateway (input tak tepercaya) dibatasi panjangnya di boundary sebelum
  menyentuh `VARCHAR(255)`.
- **CON-001**: Perilaku percakapan pribadi dan kontrak pesan non-grup **tidak berubah** (additive).
- **CON-002**: Semantik write-once `group_name` dan gerbang `400` grup incoming tanpa `sender_jid`
  **tidak diubah** — hanya hardening/test yang mengelilinginya.

## 2. Implementation Steps

> **EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> Jalankan plan ini phase demi phase. Jalankan task **VERIFY** di akhir setiap phase. Setelah diuji,
> **STOP DAN TUNGGU** persetujuan eksplisit user sebelum lanjut ke phase berikutnya. **DO NOT SKIP PHASES.**
> Definition of Done: AuliaPos `vendor/bin/phpunit --no-coverage` exit 0; WA-Gateway
> `node test/simulate-group-identity.js` dan regresi `node test/simulate-*.js` / `node test/check-*.js` exit 0.

### Implementation Phase 1: Android Runtime-Data Preservation Hardening

- **GOAL-001**: Penyelamatan `auth/`/`data/` tidak dapat kehilangan data atau menjatuhkan service saat
  terjadi kegagalan I/O di tengah proses.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                          | Ref ID  | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------ | ------- | :-------: | :--: |
| TASK-101 | Di `android/app/src/main/java/com/auliapos/wagateway/NodeBridge.kt`, bungkus fase simpan (`renameTo`/`copyRecursively`) **dan** fase kembalikan dengan `try/catch (IOException)`; catat `Log.e` dan jangan melempar keluar dari `ensureProjectFilesInstalled()`. | PRN-002 |    [x]    | 2026-09-26 |
| TASK-102 | Ubah pembersihan `preservedRoot.deleteRecursively()` di awal menjadi **rekonsiliasi**: bila `projectDir/auth` atau `projectDir/data` tidak ada sedangkan salinan di `preservedRoot` ada, kembalikan dulu; hapus `preservedRoot` hanya setelah restore sukses. | PRN-002 |    [x]    | 2026-09-26 |
| TASK-103 | Tambah unit/instrumented test (atau test JVM dengan `File` temp) untuk: (a) restore sukses, (b) `copyRecursively` gagal di tengah restore → tidak ada exception yang lolos, dan salinan di `preservedRoot` tidak terhapus sebelum dipulihkan, (c) install pertama tidak membuat `preserved-tmp` nyangkut. | PRN-002 |    [x]    | 2026-09-26 |
| TASK-104 | **VERIFY**: jalankan build/unit test Android yang tersedia (`./gradlew test` atau perintah proyek) **dan** regresi manual: install bersih → simpan `auth/data` dummy → picu update APK → pastikan `auth/data` bertahan. | -       |    [x]    | 2026-09-26 |
| TASK-105 | **APPROVAL**: 🛑 Tunggu konfirmasi eksplisit user untuk lanjut ke Phase 2.                                                                      | -       |    [x]    | 2026-09-26 |

> **Catatan VERIFY Phase 1:** unit test JVM diperluas di
> `android/app/src/test/java/com/auliapos/wagateway/RuntimeDataPreserverTest.kt`
> (8 test) dan **HIJAU** lewat `./gradlew :app:testDebugUnitTest --offline`.
> Mencakup jalur gagal (`copyRecursively` melempar), pembersihan target parsial,
> rekonsiliasi, dan install pertama.
> Regresi manual "update APK di perangkat uji" **TERTUNDA** — tidak tersedia
> perangkat/emulator di lingkungan ini; wajib dijalankan sebelum rilis
> (RISK-001).

### Implementation Phase 2: Gateway Group-Name Cache Resilience

- **GOAL-002**: `groupMetadata()` tidak pernah degenerasi menjadi panggilan jaringan per pesan saat
  metadata gagal berulang.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                          | Ref ID  | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------ | ------- | :-------: | :--: |
| TASK-201 | Di `src/whatsapp/connectionManager.js`, tambah negative cache pada `_refreshGroupName()`: simpan `{ failedAt }` per JID saat `groupMetadata()` gagal; `_handleIncomingMessage()` hanya memicu refresh bila cooldown belum lewat. Gunakan durasi dari config (mis. tambah `groupNameFailureCooldownMs` di `src/config/index.js`, bawaan 30000-60000). Tetap fire-and-forget dan non-fatal. | PRN-001 |    [x]    | 2026-09-26 |
| TASK-202 | (Opsional, `[PERF-01]`) Batasi pertumbuhan `_groupNameCache` (cap jumlah entri + eviction entri tertua) supaya Map tidak tumbuh tanpa batas. | PRN-001 |    [x]    | 2026-09-26 |
| TASK-203 | Perluas `test/simulate-group-identity.js`: (a) kegagalan `groupMetadata()` berturut-turut → `groupMetadata()` **tidak** dipanggil untuk setiap pesan dalam jendela cooldown; (b) setelah cooldown lewat, refresh dicoba lagi; (c) regresi skenario (a)/(a2)/(b)/(c)/(d)/(e)/(f) tetap lulus. | PRN-001 |    [x]    | 2026-09-26 |
| TASK-204 | **VERIFY**: `node test/simulate-group-identity.js` exit 0, lalu regresi `node test/simulate-*.js` dan `node test/check-*.js` exit 0. | -       |    [x]    | 2026-09-26 |
| TASK-205 | **APPROVAL**: 🛑 Tunggu konfirmasi eksplisit user untuk lanjut ke Phase 3.                                                                      | -       |    [x]    | 2026-09-26 |

### Implementation Phase 3: AuliaPos Display Robustness & Shared Formatter

- **GOAL-003**: Label identitas pengirim bersih (tanpa sufiks device), terpusat, dan `group_name`
  dibatasi panjangnya di boundary.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                          | Ref ID  | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------ | ------- | :-------: | :--: |
| TASK-301 | Di `app/Controllers/Inbox.php`, bersihkan sufiks device pada `@s.whatsapp.net` sebelum menampilkan (`explode(':', $local)[0]`); hasil kosong → `Pengirim`. | REQ-002 |    [x]    | 2026-09-26 |
| TASK-302 | Ekstrak aturan label ke komponen bersama (mis. `app/Services/SenderIdentityFormatter.php`) dan panggil dari `Inbox.php` (dan siapkan untuk Tahap 3). Perilaku wajib identik. | PRN-003 |    [x]    | 2026-09-26 |
| TASK-303 | Di `app/Controllers/InboxGatewayApi.php`, batasi `group_name` ke kolom: `mb_substr($groupNameFromPayload, 0, 255)`. Tidak mengubah semantik write-once (`CON-002`). | SEC-001 |    [x]    | 2026-09-26 |
| TASK-304 | Tambah test: `6281234567890:12@s.whatsapp.net` → `6281234567890`; `group_name` > 255 karakter tersimpan terpotong 255 (bukan error); regresi label `@lid`/`*.lid`/`@g.us`/malformed. | REQ-002, SEC-001 | [x] | 2026-09-26 |
| TASK-305 | **VERIFY**: `vendor/bin/phpunit --no-coverage` exit 0 (jalankan `--filter InboxGrupTahap2` lalu suite penuh secara **sekuensial**). | -       |    [x]    | 2026-09-26 |
| TASK-306 | **APPROVAL**: 🛑 Tunggu konfirmasi eksplisit user untuk lanjut ke Phase 4.                                                                      | -       |    [x]    | 2026-09-26 |

### Implementation Phase 4: Legacy Group Message Label Correctness (UNBLOCKED)

- **GOAL-004**: Pesan grup legacy (`sender_jid` = JID grup `@g.us`) tampil **tanpa** label identitas,
  sesuai `REQ-011`/`AC-012` spec v1.4 (bukan "Pengirim", PRD GH-013).

> **✅ PRASYARAT TERPENUHI (2026-09-26, spec v1.4).** Keputusan `SPEC-01` sudah tercatat:
> **Opsi (a) label-aman di kode** (`REQ-011`/`AC-012`); opsi (b) bersih-bersih data **ditolak**.
> Phase ini **siap dieksekusi** lewat `/sdlc-write-code` pada sesi baru.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                          | Ref ID  | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------ | ------- | :-------: | :--: |
| TASK-401 | **Opsi (a) dipilih (spec v1.4).** Buat label-aman di jalur tampilan: bila `sender_jid` berdomain `g.us` (case-insensitive), jangan hasilkan `Pengirim` — sinyalkan "tanpa identitas" ke pemanggil (mis. `SenderIdentityFormatter::labelFor(): ?string` mengembalikan `null`, atau tambah `isGroupJid()` yang dicek di `Inbox::attachSenderNames()` `app/Controllers/Inbox.php:653-660` mengikuti `app/Services/SenderIdentityFormatter.php`). Hasilnya `sender_name = null` → tidak ada baris label, dan JID grup **tidak pernah** dirender. **Nilai DB tidak diubah** (tanpa `UPDATE`/migrasi). Opsi (b) **tidak** dijalankan. | REQ-001 |    [x]    | 2026-09-26 |
| TASK-402 | Perbarui `tests/session/InboxGrupTahap2Phase2Test.php`: (a) pertahankan `testPesanGrupLamaTanpaSenderJidTidakBerlabel` untuk `sender_jid = null`, **tambah** kasus legacy nyata `sender_jid = '<JID grup>@g.us'` → `sender_name === null`; (b) ubah kasus `'label-grup-jid' => ['sender_jid' => '120363@g.us', 'harapan' => 'Pengirim']` (baris ~288) menjadi harapan **tanpa label** (`null`), dengan assertion JID mentah tetap dijaga. | REQ-001 |    [x]    | 2026-09-26 |
| TASK-403 | **VERIFY**: `vendor/bin/phpunit --no-coverage` exit 0 — assert `AC-012` (`@g.us` → tanpa label, JID tidak tampil, nilai DB tidak berubah) dan regresi label `@lid`/`*.lid`/nomor/malformed tidak berubah; verifikasi manual satu thread grup legacy menampilkan **tanpa** label. | -       |    [x]    | 2026-09-26 |
| TASK-404 | **APPROVAL**: 🛑 Tunggu konfirmasi eksplisit user sebelum menutup pekerjaan.                                                                     | -       |    [x]    | 2026-09-26 |

### Implementation Phase 5: Test Fidelity Cleanup

- **GOAL-005**: Uji tidak lagi mengunci teks sumber JS dan dapat mendeteksi regresi perilaku.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                          | Ref ID  | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------ | ------- | :-------: | :--: |
| TASK-501 | Ganti `testJsDaftarDanHeaderMemakaiGroupName` (assertion substring sumber) dengan pemeriksaan perilaku yang tidak rapuh terhadap format (mis. melalui respons API/`renderDaftarConversation` yang dapat diamati, atau penanda eksplisit). | PRN-003 |    [x]    | 2026-09-26 |
| TASK-502 | **VERIFY**: `vendor/bin/phpunit --no-coverage` exit 0.                                                                                          | -       |    [x]    | 2026-09-26 |
| TASK-503 | **APPROVAL**: 🛑 Tunggu konfirmasi eksplisit user bahwa perbaikan ditutup.                                                                     | -       |    [x]    | 2026-09-26 |

> **Catatan VERIFY Phase 5:** `testJsDaftarDanHeaderMemakaiGroupName` diganti
> `testApiDaftarMembawaGroupNameUntukRenderJs` (kontrak data respons API) di
> `tests/session/InboxGrupTahap2Test.php`, ditambah cek perilaku JS
> `tests/js/grup-judul-dan-identitas.check.js` (pola `tests/js/*.check.js`).
> Suite penuh: **441 test / 1654 assertion HIJAU**; cek JS: 3/3 PASS.


## 3. Structural Remedies & Alternatives

- **ALT-001**: Menambah validasi format JID ketat pada guard `400` — ditolak: `REQ-010` sengaja
  longgar (cukup non-kosong); memperketatnya akan mengubah kontrak dan berisiko menolak JID sah.
- **ALT-002**: Prefill cache grup saat connect di Gateway — ditunda (`[SPEC-05]` OPTIONAL): desain
  fire-and-forget + write-once sudah memenuhi spec; prefill menambah kompleksitas tanpa kebutuhan rilis.
- **ALT-003**: Menyimpan label terformasi di kolom DB terpisah — ditolak: duplikasi data turunan;
  cukup formatter bersama (`PRN-003`).
- **ALT-004**: Menghapus `preserved-tmp` sepenuhnya dan mengandalkan `renameTo` — ditolak:
  `renameTo` dapat gagal (lintas-volume/izin); fallback salin tetap dibutuhkan, hanya perlu aman.
- **ALT-005 (ditolak, Phase 4)**: bersih-bersih data satu kali (`UPDATE messages SET sender_jid = NULL
  WHERE sender_jid LIKE '%@g.us'` pada conversation grup) — ditolak spec v1.4 (`REQ-011`): tidak dapat
  dibalik, butuh backup DB, dan tanpa manfaat fungsional di atas label-aman Opsi (a).
- **Struktur terpilih**: `try/catch` + rekonsiliasi (Android), negative cache (Gateway),
  formatter bersama + label-aman `@g.us` (AuliaPos).

## 4. Dependencies

- **DEP-001**: Tidak ada pustaka runtime baru. Seluruh perubahan memakai API standar
  (`java.io.File`, `Map`, `mb_substr`) dan pola yang sudah ada. Satu-satunya dependensi
  tambahan adalah `junit:junit:4.13.2` **test-scope** di Android (tidak masuk APK).
- **DEP-002**: Keputusan spec untuk Phase 4 (`REQ-001`) — **RESOLVED** (spec v1.4 `SPEC-01` = Opsi (a) label-aman); Phase 4 tidak lagi terblokir.
- **DEP-003**: Akses tulis ke repo WA-Gateway (`C:\home\wa-gateway-review` atau working copy otoritatif)
  untuk Phase 2.

## 5. Files Affected

- **FILE-001**: `android/app/src/main/java/com/auliapos/wagateway/NodeBridge.kt` — exception safety +
  rekonsiliasi `preserved-tmp`.
- **FILE-002**: `src/whatsapp/connectionManager.js` — negative cache + cap (WA-Gateway).
- **FILE-003**: `src/config/index.js` — `groupNameFailureCooldownMs` (WA-Gateway).
- **FILE-004**: `test/simulate-group-identity.js` — skenario kegagalan berulang (WA-Gateway).
- **FILE-005**: `app/Controllers/Inbox.php` — sufiks device + pemakaian formatter.
- **FILE-006**: `app/Services/SenderIdentityFormatter.php` (baru) — aturan label bersama (AuliaPos).
- **FILE-007**: `app/Controllers/InboxGatewayApi.php` — batas panjang `group_name`.
- **FILE-008**: `tests/session/InboxGrupTahap2Phase2Test.php` — fixture legacy + test label.
- **FILE-009**: `tests/session/InboxGrupTahap2Test.php` — perbaikan assertion rapuh.
- **FILE-010** (Phase 4, **tidak dipakai**): migrasi/command pembersih `messages.sender_jid` legacy untuk opsi (b) — opsi (b) **ditolak** spec v1.4; Phase 4 memakai Opsi (a) dan menyentuh `FILE-005` (`Inbox.php`) / `FILE-006` (`SenderIdentityFormatter.php`) / `FILE-008` (test).
- **FILE-011** (baru): `android/app/src/test/java/com/auliapos/wagateway/RuntimeDataPreserverTest.kt`
  — test JVM jalur aman simpan/kembalikan (Phase 1).
- **FILE-012**: `android/app/build.gradle.kts` — `testImplementation("junit:junit:4.13.2")`
  (test-scope saja, tidak masuk APK).
- **FILE-013** (baru): `tests/unit/SenderIdentityFormatterTest.php` — unit test aturan label.
- **FILE-014** (baru): `tests/js/grup-judul-dan-identitas.check.js` — cek perilaku judul grup (Phase 5).

## 6. Testing Strategy

- **TEST-001**: `[CORR-01]` — test Android (JVM temp `File` atau instrumented) untuk restore sukses,
  kegagalan di tengah restore (tidak melempar, tidak menghapus salinan), dan install pertama.
- **TEST-002**: `[CORR-02]` — `node test/simulate-group-identity.js` diperluas: kegagalan
  `groupMetadata()` berturut-turut tidak memicu panggilan per pesan dalam cooldown; panggilan dicoba
  lagi setelah cooldown.
- **TEST-003**: `[REQ-002]`/`[SEC-001]` — test unit `SenderIdentityFormatter` (sufiks device bersih,
  non-JID aman) dan test boundary panjang `group_name`.
- **TEST-004**: `[REQ-001]` — fixture pesan legacy memakai JID grup nyata; assert tanpa label
  (keputusan spec v1.4: Opsi (a) label-aman).
- **TEST-005**: Regresi penuh — AuliaPos `vendor/bin/phpunit --no-coverage` (sekuensial) dan
  WA-Gateway `node test/simulate-*.js` + `node test/check-*.js`, semua exit 0.

## 7. Risks & Rollback Plan

- **RISK-001 (sedang)**: Perubahan `NodeBridge.kt` menyentuh jalur update APK; kesalahan dapat
  memaksa login WhatsApp ulang. Mitigasi: test kegagalan restore + uji manual pada perangkat uji
  sebelum rilis; rollback = kembalikan `NodeBridge.kt` ke versi sebelumnya (perubahan murni aditif).
- **RISK-002 (rendah)**: Negative cache menunda munculnya `group_name` yang sebelumnya gagal
  sementara. Mitigasi: cooldown pendek (30-60 detik) dan `group_name` tetap write-once/opsional;
  rollback = hapus negative cache.
- **RISK-003 (ditutup, Phase 4)**: Opsi (b) bersih-bersih data mengubah data `messages` lama secara
  permanen dan **ditolak** oleh spec v1.4 (`REQ-011`) — tidak dapat dibalik, tanpa manfaat fungsional
  di atas label-aman. Yang dijalankan adalah **Opsi (a)** label-aman (reversibel, nilai DB tidak
  diubah); rollback = kembalikan `SenderIdentityFormatter`/`attachSenderNames()` ke versi sebelumnya.
- **RISK-004 (rendah)**: Ekstraksi formatter dapat mengubah perilaku label kecil. Mitigasi: test
  regresi label (`@lid`, `*.lid`, `@g.us`, malformed, nomor) wajib hijau sebelum lanjut.
- **Rollback umum**: setiap phase berdiri sendiri; revert commit per-phase. Tidak ada perubahan
  skema produksi (Phase 4 Opsi (a) tidak menyentuh DB).
