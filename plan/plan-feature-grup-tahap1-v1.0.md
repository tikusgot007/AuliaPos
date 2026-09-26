---
goal: Pisahkan percakapan Grup WhatsApp dari antrean kerja kasir (tab Grup, badge, aksi dinonaktifkan)
version: 1.2
date_created: 2026-09-26
last_updated: 2026-09-26
owner: AuliaPos Inbox module
status: 'Completed'
tags: [feature, inbox, grup, tahap1]
---

# Introduction

![Status: Completed](https://img.shields.io/badge/status-Completed-brightgreen)

> [!NOTE]
> **Revision 1.2 (2026-09-26) — Closure:** Implementation Phase 1 executed end to end via `/sdlc-write-code`. TASK-001 verified the production literal (`aulia_inboxdb`, `jid_type='group'`, confirmed lowercase, 5 characters) — the STOP instruction did NOT fire. TASK-002..TASK-006B are implemented, TASK-007 (VERIFY) is green (15 new automated tests + 1 adjusted pre-existing test; macro gate `vendor/bin/phpunit --no-coverage` → `OK (415 tests, 1532 assertions)`, up from the 400-test baseline; manual browser checklist passed in full). TASK-008 (APPROVAL) is closed: the product owner confirmed completion on 2026-09-26. See Section 10 (Closure) for the full evidence ledger.

Plan ini adalah rincian eksekusi untuk `spec-design-grup-tahap1-tab-inbox.md` (v1.1): menambah tab **Grup** di `/inbox`, mengecualikan percakapan grup dari badge `perlu_dibalas`, dan menonaktifkan/menyembunyikan aksi yang tidak berlaku untuk grup. Tahap ini murni sisi AuliaPos — tidak ada perubahan skema database, tidak menyentuh repo `WA-Gateway`.

> [!NOTE]
> **Revision 1.1 (2026-09-26), per Spec v1.1 (`REQ-007`, `REQ-008`, `REQ-009`, `CON-005`, `CON-006`, `AC-008`..`AC-012`) dan `docs/audit/clarification-report-grup-tahap1-plan-2026-09-26.md` Next Step #2:** TASK-001 mendapat instruksi STOP eksplisit; TASK-006 diperluas mencakup posisi tab Grup paling akhir + entri `QUEUE_STATUS_LABEL['grup']` dan penyembunyian badge/tombol tambahan (CON-005/CON-006); dua task baru ditambahkan — **TASK-006A** (pengecualian grup dari syarat `status==='closed'` di `hapusPercakapan()`, REQ-007) dan **TASK-006B** (pengecualian grup dari auto-assign di `kirimKeConversation()`/`kirimMedia()`, REQ-008); TASK-007 (VERIFY) diperluas dengan test otomatis untuk Hapus & auto-assign plus manual check untuk badge/tombol tersembunyi dan posisi tab. Tetap **1 Implementation Phase**, urutan dependensi dipertahankan, tidak ada scope baru di luar Spec v1.1.

## 1. Requirements & Constraints

- **REQ-001**: `GET /inbox/api/conversations?status=grup` mengembalikan seluruh percakapan `jid_type='group'`.
- **REQ-002**: Percakapan grup tidak pernah cocok dengan status lama (`belum_diambil`/`open`/`menunggu`/`ditunda`/`selesai`).
- **REQ-003**: `withComputedStatus()` menghitung `queue_status='grup'` untuk baris `jid_type='group'`, ditaruh **setelah** blok if/elseif yang sudah ada.
- **REQ-004**: `apiPerluDibalasCount()` mengecualikan `jid_type='group'` dari `count()`, dengan menambah `jid_type` ke `select()`.
- **REQ-005**: Baris daftar grup menampilkan badge teks "Grup".
- **REQ-006**: Header percakapan grup menampilkan penanda yang sama, terpisah dari judul.
- **CON-001**: Tombol Ambil/Lepas/Tutup/Snooze **tidak dirender** (bukan disabled) untuk grup.
- **CON-002**: Konfirmasi Nomor (header) dan Edit Profil (baris daftar) dirender **disabled** untuk grup; Hapus percakapan tetap berfungsi penuh.
- **CON-003**: Kirim pesan teks/media dan Internal Note tetap berfungsi tanpa perubahan pada grup.
- **CON-004**: Endpoint aksi (`ambilPercakapan`, `lepasPercakapan`, `tutupPercakapan`, `snoozePercakapan`, `konfirmasiNomor`, Edit Profil) menolak `403` untuk `jid_type='group'` di server, terlepas dari UI.
- **REQ-007**: `hapusPercakapan()` (`app/Controllers/Inbox.php:1531`) mengecualikan percakapan `jid_type='group'` dari syarat `status==='closed'` (`:1552`) — grup tidak pernah bisa mencapai `status='closed'` (CON-001 + CON-004 + tidak ada reopen manual), sehingga syarat itu membuat Hapus selalu gagal `409`. Gate admin-only (`:1545`) tidak berubah.
- **REQ-008**: Auto-assign pada `kirimKeConversation()` (`Inbox.php:2143-2145`) dan `kirimMedia()` (`:1051-1053`) dikecualikan untuk `jid_type='group'` — `assigned_to` tidak boleh terisi otomatis saat kasir membalas grup (mencegah kebuntuan permanen karena `lepasPercakapan()` selalu menolak 403 untuk grup).
- **REQ-009**: Tab **Grup** diletakkan di posisi **paling akhir** pada baris tab (setelah Selesai), dan entri `grup: 'Grup'` wajib ditambahkan ke objek JS `QUEUE_STATUS_LABEL` (`index.php:790-796`) — satu-satunya sumber kebenaran untuk highlight tombol aktif (`renderFilterButtons()`) dan badge angka tab (`renderTabCounts()`).
- **CON-005**: Badge `response_state` (baris daftar, `index.php:932-933`), titik warna SLA (`renderTitikSla()`, `:877-883`/`:947`), tombol **Tandai Dibaca** (header, `:1186-1189`), dan tombol **Handoff** (header, `:1197-1204`) **tidak dirender** (bukan disabled) pada percakapan grup, pola yang sama dengan CON-001.
- **CON-006**: Badge kepemilikan (`"Belum diambil"`/`"Dipegang: X"`, `index.php:937-940` baris daftar, `:1149-1157` header) dan badge lifecycle `OPEN`/`CLOSED` (`badgeStatus`, `:1175-1177`/`:1228`, dan badge `closed` baris daftar `:931`) **tidak dirender** pada grup. Penanda "Grup" (REQ-005/REQ-006) tetap tampil menggantikan posisi informasi tersebut.
- **GUD-001**: Tidak boleh menambah query baru ke database — semua filter beroperasi di atas `findAll()` yang sudah ada.

## 2. Implementation Steps

> **EXECUTION DIRECTIVE FOR AI AGENTS:**
> Jalankan plan ini phase demi phase. Jalankan task VERIFY di akhir phase. Setelah diuji, **STOP DAN TUNGGU** persetujuan eksplisit user sebelum lanjut ke phase berikutnya.

### Implementation Phase 1

- GOAL-001: Percakapan Grup punya tab, penanda, dan badge sendiri; aksi yang tidak relevan dinonaktifkan di UI maupun server; tidak ada regresi pada percakapan pribadi.

| Task     | Description                                                                                                                                                                                 | Ref ID          | AC Ref         | Dep      | Files | Completed | Date |
| -------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------- | -------------- | -------- | ----- | --------- | ---- |
| TASK-001 | **[HIGH RISK — ASSUMPTION-001]** Verifikasi nilai literal `jid_type` untuk grup adalah `'group'` terhadap `aulia_inboxdb` produksi atau log payload `POST /api/inbox/gateway/messages`. Catat hasilnya (nilai literal terkonfirmasi atau berbeda) sebelum lanjut TASK-002. **STOP WAJIB (spec ASSUMPTION-001/AC-012):** bila nilai literal yang ditemukan **berbeda** dari `'group'`, HENTIKAN pekerjaan di sini — jangan lanjut ke TASK-002 dan seterusnya. Amandemen `spec-design-grup-tahap1-tab-inbox.md` lebih dulu lewat `/sdlc-define-specs` (REQ-001, REQ-002, REQ-003, REQ-007, REQ-008, CON-004, dan contoh kode Section 12) supaya menyebut nilai literal yang benar, baru kembali ke plan ini untuk melanjutkan implementasi. | ASSUMPTION-001, AC-012  | AC-012              | -        | 0     | [x]  | 2026-09-26 |
| TASK-002 | Di `app/Models/ConversationModel.php`, fungsi `withComputedStatus()`: tambahkan assignment `$conversation['queue_status'] = 'grup'` untuk `jid_type === 'group'`, ditaruh **setelah** blok if/elseif `response_state`/`queue_status` yang sudah ada di dalam loop (lihat contoh spec §12), agar tidak tertimpa.                                        | REQ-003         | AC-002         | TASK-001 | 1     | [x]  | 2026-09-26 |
| TASK-003 | Di `app/Controllers/Inbox.php`: tambahkan `'grup'` ke konstanta `QUEUE_STATUSES` (baris ~29), lalu di `apiConversations()` pastikan `status=grup` memfilter `jid_type='group'` (filter-after-fetch, tanpa query baru).                                                    | REQ-001, REQ-002 | AC-001, AC-002 | TASK-002 | 1     | [x]  | 2026-09-26 |
| TASK-004 | Di `app/Controllers/Inbox.php`, fungsi `apiPerluDibalasCount()` (baris ~316-333): tambahkan `jid_type` ke daftar kolom `select()` yang sudah ada, lalu kecualikan baris `jid_type='group'` dari `count()`.                                                                 | REQ-004         | AC-003         | TASK-002 | 1     | [x]  | 2026-09-26 |
| TASK-005 | Di `app/Controllers/Inbox.php`: tambahkan pengecekan `jid_type==='group'` → balas `403` di awal `ambilPercakapan()`, `lepasPercakapan()`, `tutupPercakapan()`, `snoozePercakapan()`, `konfirmasiNomor()`, dan endpoint Edit Profil, mengikuti pola `cekOwnership()` yang sudah ada.                                          | CON-004         | AC-006         | TASK-002 | 1     | [x]  | 2026-09-26 |
| TASK-006 | Di `app/Views/inbox/index.php`: (a) tambah tab **Grup** di daftar tab pada posisi **PALING AKHIR** (setelah Selesai), sehingga urutan menjadi `Belum Diambil, Open, Menunggu, Ditunda, Selesai, Grup` — 5 tab lama tidak bergeser; tambahkan entri `grup: 'Grup'` ke objek JS `QUEUE_STATUS_LABEL` (`:790-796`), sumber kebenaran untuk highlight tombol aktif (`renderFilterButtons()`) dan badge angka tab (`renderTabCounts()`); (b) tampilkan badge teks "Grup" pada baris daftar (dekat `RESPONSE_STATE_LABEL`, baris ~840) dan di header percakapan, terpisah dari judul; (c) untuk `jid_type==='group'`, jangan render tombol Ambil/Lepas/Tutup/Snooze sama sekali; (d) render tombol Konfirmasi Nomor (header, pola `tombolKonfirmasiNomor` baris ~1166) dan Edit Profil (baris daftar kiri) dalam keadaan `disabled`, sementara tombol Hapus di baris yang sama tetap aktif; (e) untuk `jid_type==='group'`, **sembunyikan** (jangan render, bukan disabled) badge `response_state` (`:932-933`), titik warna SLA (`renderTitikSla()`, `:877-883`/`:947`), tombol **Tandai Dibaca** (`:1186-1189`), tombol **Handoff** (`:1197-1204`), badge kepemilikan (`"Belum diambil"`/`"Dipegang: X"`, `:937-940` baris daftar & `:1149-1157` header), dan badge lifecycle `OPEN`/`CLOSED`/`closed` (`badgeStatus` `:1175-1177`/`:1228`, badge `closed` baris daftar `:931`) — pola sama dengan (c), tidak dirender sama sekali. | REQ-005, REQ-006, REQ-009, CON-001, CON-002, CON-003, CON-005, CON-006 | AC-004, AC-005, AC-008, AC-011 | TASK-003, TASK-004, TASK-005 | 1 | [x] | 2026-09-26 |
| TASK-006A | Di `app/Controllers/Inbox.php`, fungsi `hapusPercakapan()` (`:1531`): kecualikan `jid_type==='group'` dari syarat `status !== 'closed'` (`:1552`) supaya grup tidak pernah gagal `409` — gate admin-only (`:1545`) **tidak berubah**. Tambahkan test otomatis (feature/HTTP): admin menghapus grup dengan `status` bukan `closed` → `200`; kasir non-admin yang sama → tetap `403`. | REQ-007 | AC-009 | TASK-001 | 1 | [x] | 2026-09-26 |
| TASK-006B | Di `app/Controllers/Inbox.php`: kecualikan `jid_type==='group'` dari auto-assign di `kirimKeConversation()` (`:2143-2145`) dan `kirimMedia()` (`:1051-1053`) — `assigned_to` tidak boleh terisi otomatis untuk grup. Tambahkan test otomatis: kasir membalas grup (teks dan media) yang `assigned_to`-nya kosong → pesan tetap terkirim `200` **dan** `assigned_to` tetap kosong setelahnya. | REQ-008 | AC-010 | TASK-001 | 1 | [x] | 2026-09-26 |
| TASK-007 | **VERIFY**: Jalankan `vendor/bin/phpunit --no-coverage --filter InboxTest` (harus hijau). Tambahkan/gunakan factory conversation `jid_type='group'` (pola `app/Commands/SeedFase1ePerf.php:180`) untuk menguji filter `status=grup`, badge count, 403 pada endpoint aksi, **Hapus grup oleh admin (`200`) vs kasir non-admin (`403`) tanpa syarat closed (AC-009)**, dan **auto-assign tidak mengisi `assigned_to` pada balasan teks/media grup (AC-010)** — keempat item pertama plus dua item baru ini WAJIB test otomatis. Lanjutkan manual check di browser: tab Grup hanya menampilkan grup dan berada di posisi **paling akhir** (setelah Selesai), menyala aktif saat dipilih dengan badge angka ter-update (AC-011); badge `perlu_dibalas` berkurang tepat sejumlah grup di data uji; tidak ada tombol Ambil/Lepas/Tutup/Snooze pada grup; badge `response_state`, titik SLA, tombol Tandai Dibaca, tombol Handoff, badge kepemilikan, dan badge lifecycle **tidak tampak** pada grup (AC-008); dan percakapan pribadi tidak berubah perilaku (regresi nol, AC-007). | -       | -      | TASK-006, TASK-006A, TASK-006B    | -     | [x] | 2026-09-26 |
| TASK-008 | **APPROVAL**: Tunggu konfirmasi eksplisit user bahwa Tahap 1 selesai dan siap lanjut ke `spec-design-grup-tahap2-identitas.md`.                                                                                                                                            | -       | -      | -    | -     | [x] | 2026-09-26 |

## 3. Alternatives

- **ALT-001**: Menambah kolom `is_group` terpisah di `conversations` alih-alih memakai `jid_type` yang sudah ada — ditolak karena melanggar CON (Tahap 1 tidak boleh menambah skema) dan tidak perlu, karena `jid_type='group'` sudah cukup sebagai sumber kebenaran.
- **ALT-002**: Menambah query baru khusus untuk hitung badge grup — ditolak karena melanggar GUD-001 (tidak boleh menambah query, harus filter-after-fetch di atas `findAll()` yang sudah ada).

## 4. Dependencies

- **DEP-001**: Tidak ada dependency library baru — seluruh perubahan memakai kode dan pola yang sudah ada di `Inbox.php`, `ConversationModel.php`, `index.php`.

## 5. Files

- **FILE-001**: `app/Controllers/Inbox.php` — tambah `'grup'` ke `QUEUE_STATUSES`; ubah `apiConversations()`, `apiPerluDibalasCount()`; tambah cek 403 grup di endpoint aksi; kecualikan grup dari syarat `status==='closed'` di `hapusPercakapan()` (REQ-007); kecualikan grup dari auto-assign di `kirimKeConversation()`/`kirimMedia()` (REQ-008).
- **FILE-002**: `app/Models/ConversationModel.php` — ubah `withComputedStatus()` untuk set `queue_status='grup'`.
- **FILE-003**: `app/Views/inbox/index.php` — tambah tab Grup di posisi paling akhir + entri `QUEUE_STATUS_LABEL['grup']` (REQ-009), badge penanda, sembunyikan/disable tombol untuk grup, sembunyikan badge `response_state`/SLA/Tandai Dibaca/Handoff/kepemilikan/lifecycle (CON-005, CON-006).

## 6. Testing

- **TEST-001**: `vendor/bin/phpunit --no-coverage --filter InboxTest` — unit test `ConversationModel` (`jid_type='group'` → `queue_status='grup'`) dan feature/HTTP test (filter `status=grup`, badge count, penolakan 403, Hapus grup admin→200/non-admin→403 (AC-009), auto-assign tidak mengisi `assigned_to` (AC-010)).
- **TEST-002**: Manual check di browser (localhost XAMPP): tab Grup di posisi paling akhir dan menyala aktif dengan badge angka ter-update (AC-011); badge sidebar; tombol yang hilang/disabled; badge `response_state`/titik SLA/Tandai Dibaca/Handoff/kepemilikan/lifecycle tersembunyi pada grup (AC-008); regresi nol pada percakapan pribadi.

## 7. Risks & Assumptions

- **ASSUMPTION-001** *(dari spec, [ASSUMPTION] tag)*: Nilai `jid_type` untuk grup adalah string literal `'group'`, dikonfirmasi dari kode publik `tikusgot007/WA-Gateway` (`classifyJid()`), tapi **belum diverifikasi terhadap data produksi** karena database lokal sesi ini kosong dari data grup. **TASK-001 (High Risk)** wajib dijalankan lebih dulu untuk memverifikasi nilai ini sebelum TASK-002 dst. dikerjakan. **Instruksi STOP wajib (AC-012):** bila nilai literal produksi ternyata **berbeda** dari `'group'`, TASK-001 berhenti di situ juga — jangan lanjut TASK-002 — dan spec harus diamandemen dulu lewat `/sdlc-define-specs` sebelum implementasi berlanjut.
- **RISK-001**: Kalau nilai literal ternyata bukan `'group'` (mis. berbeda kapitalisasi atau nilai lain), seluruh filter di TASK-002/003/004/005/006A/006B harus disesuaikan — dampaknya terbatas karena semua ada di file yang sama, tapi wajib dicek ulang sebelum lanjut (lihat instruksi STOP di atas).

## 8. Related Specifications / Further Reading

- [`spec-design-grup-tahap1-tab-inbox.md`](../spec/spec-design-grup-tahap1-tab-inbox.md)
- [`spec-index.md`](../spec/spec-index.md)
- `spec-design-grup-tahap2-identitas.md` — lanjutan tahap ini
- `docs/CHAT.md` §11 (Lifecycle), §18 (Developer Invariants), §19 (Response State)

## 9. Rollback / Recovery Plan

Jika implementasi Phase 1 gagal atau menyebabkan error kritis pada percakapan pribadi (regresi):
1. `git revert` commit yang mengubah `app/Controllers/Inbox.php`, `app/Models/ConversationModel.php`, dan `app/Views/inbox/index.php` untuk plan ini.
2. Tidak ada migrasi database yang perlu dibatalkan (Tahap 1 tidak mengubah skema).
3. Tidak ada environment variable yang perlu direstore.
4. Jalankan ulang `vendor/bin/phpunit --no-coverage` untuk memastikan kembali ke status hijau sebelum perubahan.

## 10. Closure (Revision 1.2, 2026-09-26)

- **TASK-001 evidence:** query langsung ke `aulia_inboxdb` produksi (`SELECT jid_type, COUNT(*) FROM conversations GROUP BY jid_type`) mengembalikan `group` (1 baris) di antara `lid`/`pn`. Verifikasi lanjutan (`HEX(jid_type)`, `LENGTH(jid_type)`) pada baris itu (`id=25800`) mengonfirmasi literal persis `67726F7570` = ASCII `group`, 5 karakter, huruf kecil semua — sama persis dengan ASSUMPTION-001. **Instruksi STOP tidak terpicu.**
- **Files changed (FILE-001..003):**
  - `app/Models/ConversationModel.php` — `withComputedStatus()`: assignment `queue_status='grup'` ditaruh setelah blok if/elseif lama (REQ-003).
  - `app/Controllers/Inbox.php` — `QUEUE_STATUSES` +`'grup'`; `apiPerluDibalasCount()` menambah `jid_type` ke `select()` dan mengecualikan grup dari `count()`; guard baru `cekBukanGrup()` dipasang di `ambilPercakapan()`, `lepasPercakapan()`, `tutupPercakapan()`, `snoozePercakapan()`, `konfirmasiNomorWhatsapp()`, `updateCustomerProfile()`; `hapusPercakapan()` mengecualikan grup dari syarat `status==='closed'`; `kirimKeConversation()`/`kirimMedia()` mengecualikan grup dari auto-assign.
  - `app/Views/inbox/index.php` — tab Grup di posisi paling akhir + `QUEUE_STATUS_LABEL['grup']`; badge "Grup" di baris daftar & header; Ambil/Lepas/Tutup/Snooze/Handoff/Tandai Dibaca tidak dirender untuk grup; Konfirmasi Nomor & Edit Profil disabled untuk grup (Hapus tetap aktif); badge response_state/SLA/kepemilikan/lifecycle tidak dirender untuk grup.
- **TASK-007 automated evidence:**
  - Test baru: `tests/session/InboxGrupTahap1Test.php` (14 test: filter status=grup, exclusion 5 status lama, badge perlu_dibalas, 403 pada 6 endpoint aksi, Hapus admin→200/kasir→403, kontrol negatif percakapan pribadi tetap mensyaratkan closed, auto-assign tidak mengisi assigned_to untuk teks & media).
  - Test baru: `tests/database/ConversationModelComputedStatusTest.php::testWithComputedStatusSetsGrupQueueStatusForGroupJidType` (1 test, regression guard urutan assignment REQ-003).
  - Test disesuaikan: `tests/session/OperationalInboxScreenTest.php::testHalamanInboxMembacaSlaColorDariServer` — assertion diperbarui ke kontrak baru `renderTitikSla(isGrup ? null : c.sla_color)` (CON-005), bukan pelemahan test.
  - Macro gate: `vendor/bin/phpunit --no-coverage` → **`OK (415 tests, 1532 assertions)`**, naik dari baseline 400 tests/1462 assertions (+15 test, 0 kegagalan, 0 skip).
- **TASK-007 manual browser evidence:** login admin sementara (password di-reset lalu dikembalikan ke hash semula setelah selesai), data uji grup non-destructive (`120363000000000001@g.us`, dibuat lalu dihapus setelah verifikasi — tidak menyentuh baris produksi lain). Terverifikasi via Playwright: tab Grup di posisi paling akhir (AC-011); badge angka tab ter-update ("Grup 1" saat 1 percakapan grup ada); baris daftar grup menampilkan badge "Grup", Edit Profil disabled, Hapus tetap aktif, tanpa badge closed/response_state/kepemilikan (AC-005, AC-008); header percakapan grup menampilkan identitas + badge "Grup" terpisah dari judul, Konfirmasi Nomor disabled, tanpa badge status/lifecycle, tanpa tombol Ambil/Lepas/Tutup/Snooze/Handoff/Tandai Dibaca, hanya Catatan Internal yang tersedia (AC-004, AC-006, CON-001, CON-003, CON-005, CON-006).
- **Regresi nol (AC-007):** seluruh assertion percakapan pribadi (`jid_type != 'group'`) di suite yang sudah ada tetap hijau tanpa modifikasi logic, kecuali satu penyesuaian assertion visual di atas yang murni mengikuti perubahan kontrak CON-005 yang disengaja.
- **Approval:** TASK-008 disetujui eksplisit oleh pemilik proyek pada 2026-09-26. Plan status → `Completed`.
- **Next:** lanjut ke `spec-design-grup-tahap2-identitas.md` per urutan wajib `spec-index.md` — mulai dari `/sdlc-code-review` (review Tahap 1) dan/atau `/sdlc-define-specs`/`/sdlc-clarify-reqs` untuk Tahap 2, di sesi chat baru (session isolation).
