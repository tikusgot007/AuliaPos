---
goal: M3 Operational Inbox — Fase 1 (Queue View, Conversation Detail, Snooze, Internal Note, SLA, Filter)
version: 1.0
date_created: 2026-09-21
last_updated: 2026-09-21
owner: AuliaPos Inbox module
status: 'Planned'
tags: [feature, inbox, chat, whatsapp, m3, operational-inbox]
---

# Introduction

![Status: Planned](https://img.shields.io/badge/status-Planned-blue)

Plan ini mengeksekusi `spec/spec-design-m3-operational-inbox-fase1.md` (Readiness Score 96/100, remediasi via `docs/audit/clarification-report-m3-fase1-operational-inbox-spec-2026-09-20.md`) untuk mengubah Inbox AuliaPos dari viewer chat menjadi *operational customer workspace*: Queue View 5 tab, Conversation Detail dasar, Snooze, Internal Note, SLA Timer, dan Filter & Pencarian. Dieksekusi dalam 2 fase mergeable-independen: Fase 1a (tanpa migration, murni memanfaatkan endpoint existing) dan Fase 1b (migration additive `is_internal` + endpoint baru).

## 1. Requirements & Constraints

- **REQ-001**: Queue View 5 tab (Belum Diambil, Open, Menunggu, Ditunda, Selesai) dari satu sumber computed status.
- **REQ-002**: Logika computed status hidup satu-satunya di `ConversationModel::withComputedStatus()`, reuse `Inbox::attachResponseState()` (lihat `docs/adr/0001-reuse-response-state-for-queue-view-status.md`) — tidak boleh reimplementasi WHERE.
- **REQ-003/004**: Pemetaan tab dari kombinasi Response State × `assigned_to` (lihat spec Bagian 3).
- **REQ-005**: Conversation Detail (thread + action bar) memakai endpoint existing, tanpa endpoint baru di Fase 1a.
- **REQ-006**: Snooze Dialog Fase 1a hanya input `menit`, tanpa Alasan.
- **CON-001**: Fase 1a tidak boleh menambah migration/kolom DB baru.
- **REQ-007**: Migration `messages.is_internal BOOLEAN NOT NULL DEFAULT FALSE` (koneksi `inbox`, additive).
- **REQ-008**: Endpoint `POST /inbox/percakapan/(:num)/catatan` — insert `is_internal=TRUE`, tidak mengirim ke Gateway, diizinkan pada conversation status apa pun termasuk `closed`.
- **SEC-001**: Endpoint Internal Note TIDAK melalui `cekOwnership()` — staff manapun boleh menulis ke conversation manapun.
- **REQ-009**: Endpoint Internal Note TIDAK BOLEH memanggil `ConversationModel::update()` untuk `last_message_at`/`last_message_direction` (kolom denormalized, bukan hasil query agregasi).
- **REQ-010**: SLA Timer dihitung dari `last_message_at`, threshold Hijau `<15m`, Kuning `15-60m`, Merah `>60m`, konstanta di `app/Config/Inbox.php`.
- **REQ-011**: Field Alasan Snooze (Fase 1b) ditulis sebagai Internal Note otomatis — tidak ada kolom `snooze_reason` baru.
- **REQ-012**: `GET /inbox/api/conversations` diperluas dengan parameter `status`/`q` (filter-after-fetch, lihat Interfaces).
- **CON-002**: Internal Note tidak pernah muncul di payload ke Gateway WhatsApp.
- **GUD-001**: Semua migration Fase 1b additive-only.
- **SEC-002 (derived, ADR-0001 Consequences)**: `withComputedStatus()` dan agregasi `last_message_at`/`last_message_direction` HARUS meng-exclude baris `is_internal=TRUE` dari asumsi input mereka agar tidak mencemari badge Tahap A maupun tab Queue View — dijamin secara struktural oleh REQ-009 (Internal Note tidak pernah menulis kolom itu), bukan oleh filter query tambahan.

## 2. Implementation Steps

> **EXECUTION DIRECTIVE FOR AI AGENTS:**
> Eksekusi plan ini fase demi fase. Jalankan task **VERIFY** di akhir tiap fase. Setelah fase diuji, **BERHENTI DAN TUNGGU** persetujuan eksplisit user sebelum lanjut ke fase berikutnya.

### Implementation Phase 1 — Fase 1a: Queue View + Conversation Detail dasar

- GOAL-001: Staff bisa melihat conversation terkelompok ke 5 tab Queue View yang benar dan membuka/mengelola percakapan dasar (balas, ambil/lepas, snooze durasi, selesai) — seluruhnya memakai endpoint existing, tanpa migration baru.

| Task     | Description                                                                                                                                                                                                                                     | Ref ID          | AC Ref        | Dep      | Files | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | --------------- | ------------- | -------- | ----- | --------- | ---- |
| TASK-001 | Tambah `ConversationModel::withComputedStatus(array $conversations): array` di `app/Models/ConversationModel.php`. Method memanggil `Inbox::attachResponseState()` (atau logika setara yang di-port) untuk dapat `response_state`, lalu memetakan ke `queue_status` (`belum_diambil`/`open`/`menunggu`/`ditunda`/`selesai`) sesuai REQ-003/REQ-004. Tidak ada query SQL WHERE baru. Tangani fallback conversation tanpa `last_message_direction` (warisi fallback existing `attachResponseState()` baris ~483-487) — hasilkan `belum_diambil`/`open` sesuai `assigned_to`, jangan crash. | REQ-002,003,004 | AC-001,AC-002 | -        | 1     |           |      |
| TASK-002 | Panggil `withComputedStatus()` di `Inbox::apiConversations()` (`app/Controllers/Inbox.php`) sehingga payload tiap conversation bertambah key `queue_status`. Update `app/Views/inbox/*` (view Queue View existing) untuk render 5 tab berdasarkan `queue_status`, bukan menghitung status sendiri di frontend. **Tambahan (resolusi klarifikasi 2026-09-21):** setiap label tab menampilkan angka jumlah conversation per tab, dihitung murni di frontend dari payload `withComputedStatus()` yang sudah ada (count per `queue_status` di JS) — tidak ada query/endpoint baru untuk angka ini.                                                                                                                        | REQ-001         | AC-001,AC-002 | TASK-001 | 2-3   |           |      |
| TASK-003 | Verifikasi Conversation Detail (thread `GET /inbox/api/conversations/(:num)/messages` + action bar Balas/Ambil/Lepas/Selesai) sudah terhubung dengan benar ke UI Queue View baru — endpoint sudah ada, task ini murni wiring/regresi UI, tanpa endpoint baru (REQ-005). Jika ada view Inbox terpisah dari `kasir-shared.js`, pastikan tidak salah reuse pola POS.                                                                     | REQ-005         | -             | TASK-002 | 1-2   |           |      |
| TASK-004 | Verifikasi Snooze Dialog Fase 1a memanggil `Inbox::snoozePercakapan()` existing hanya dengan parameter `menit`, tanpa field Alasan dan tanpa mengirim `null`/string kosong ke endpoint yang belum ada.                                                                                                                                                                                                                                    | REQ-006         | -             | TASK-003 | 1     |           |      |
| TASK-005 | **VERIFY**: Tambah/lengkapi test `tests/database/` untuk `ConversationModel::withComputedStatus()` — cover 5 kombinasi `response_state` × `assigned_to` (AC-001, AC-002) dan kasus fallback tanpa `last_message_direction`. Jalankan `composer test` — pastikan test existing Tahap A (`apiPerluDibalasCount()`, badge sidebar) tidak regresi.                                                                                          | -               | -             | -        | -     |           |      |
| TASK-006 | **APPROVAL**: Tunggu konfirmasi eksplisit user sebelum lanjut ke Phase 2.                                                                                                                                                                                                                                                                                                                                                                  | -               | -             | -        | -     |           |      |

### Implementation Phase 2 — Fase 1b: Internal Note + SLA + Filter & Pencarian

- GOAL-002: Staff bisa menulis Internal Note ke conversation manapun (termasuk closed) tanpa memicu Gateway atau mengubah Response State, melihat indikator warna SLA per conversation, mengisi Alasan saat snooze, dan memfilter/mencari conversation di Queue View.

| Task     | Description                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       | Ref ID              | AC Ref               | Dep              | Files | Completed | Date |
| -------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------- | --------------------- | ---------------- | ----- | --------- | ---- |
| TASK-007 | Buat migration baru di `app/Database/Migrations/` (pola sama `2026-09-19-000001_AddResponseStateFoundation.php`, `$DBGroup = 'inbox'`): `addColumn('messages', ['is_internal' => ['type' => 'BOOLEAN', 'null' => false, 'default' => false, 'after' => 'direction']])`. Jalankan `php spark migrate`.                                                                                                                                                                                                                                          | REQ-007              | -                     | -                | 1     |           |      |
| TASK-008 | Tambah method `Inbox::catatanInternal($conversationId)` di `app/Controllers/Inbox.php` + route `POST /inbox/percakapan/(:num)/catatan` di `app/Config/Routes.php`. Ikuti pola guard clause di spec Bagian 8: cek conversation ditemukan (404), tidak melalui `cekOwnership()` (SEC-001), validasi `teks` tidak kosong (400), insert ke `MessageModel` dengan `is_internal = true`, `direction = 'outgoing'` (nilai netral, TIDAK memicu `POST /send` Gateway). **WAJIB TIDAK** memanggil `ConversationModel::update()` untuk `last_message_at`/`last_message_direction` (REQ-009) — beda dari pola `kirim()`/`InboxGatewayApi::messages()`. Tidak ada pengecekan status conversation di luar 404 (izinkan pada `closed`). **Kolom wajib `MessageModel` (resolusi klarifikasi 2026-09-21):** `$validationRules` mewajibkan `wa_message_id` (unik, ada `existsByWaMessageId()`), `message_timestamp` (`required|valid_date`), `send_status` (`required|in_list[received,sent,failed]`) — tidak ada nilai natural untuk Internal Note. Isi eksplisit: `wa_message_id` = nilai sintetis unik (mis. `'internal-' . uniqid()`, atau gabungan `conversation_id` + `time()`); `message_timestamp` = waktu insert (`date('Y-m-d H:i:s')` saat request diproses); `send_status = 'sent'` sebagai nilai netral valid terhadap `in_list`; `sent_by_user_id` diisi dari ID staff yang login (session), mengikuti pola pengisian existing untuk pesan `outgoing` lain (penting untuk audit atribusi penulis Internal Note, lihat SEC-001). Tidak ada perubahan skema/validasi existing. | REQ-008,009,SEC-001  | AC-003,AC-004         | TASK-007         | 1-2   |           |      |
| TASK-009 | **VERIFY**: Tambah test `tests/session/` untuk endpoint Internal Note: (a) staff bukan assignee → 200 (AC-004), (b) conversation `menunggu_customer` tetap di tab yang sama setelah note ditulis (AC-003) — assert `response_state` TIDAK berubah, (c) assert `conversations.last_message_direction`/`last_message_at` TIDAK berubah setelah insert note (regresi silent REQ-009, lihat spec Bagian 12), (d) note pada conversation `closed` → 200 (edge case). Jalankan `composer test`.                                                                                                                                                                                    | -                    | -                     | -                | -     |           |      |
| TASK-010 | Buat `app/Services/InboxSlaService.php` (pure function, pola `KalkulasiStatusPembayaran`) yang menerima `lastMessageAt`, `queueStatus` dan mengembalikan `sla_color` (`hijau|kuning|merah|null`). `null` untuk `queue_status` = `selesai` atau `ditunda` (ASSUMPTION-002); `menunggu` tetap dihitung. Threshold (`<15m` hijau, `15-60m` kuning, `>60m` merah) dibaca dari properti baru di `app/Config/Inbox.php`, bukan hardcode di Service.                                                                                                                                                                                                | REQ-010              | AC-005,AC-006         | TASK-001         | 2     |           |      |
| TASK-011 | Perluas `Inbox::apiConversations()`: naikkan `findAll(100)` → `findAll(500)` (baris ~65); jalankan `withComputedStatus()` (TASK-001) dan `InboxSlaService` (TASK-010) atas seluruh 500 baris; terima parameter query `status` dan `q`; terapkan filter **setelah** compute (filter-after-fetch di PHP, bukan `WHERE` SQL baru) sesuai ASSUMPTION-001 — `status` cocok terhadap `queue_status`, `q` cocok `LIKE '%q%'` mentah terhadap `contact_name`/`phone` (tanpa normalisasi nomor). Payload response bertambah key `queue_status` dan `sla_color`. **Payload thread & flag `is_internal` (resolusi klarifikasi 2026-09-21):** payload `apiConversations()` DAN payload endpoint thread `GET /inbox/api/conversations/(:num)/messages` menyertakan flag `is_internal` per baris pesan/conversation yang relevan — frontend WAJIB merender pesan `is_internal=true` dengan style visual berbeda dari pesan biasa (mis. background berbeda + label "Internal") agar staff tidak salah kira Internal Note terkirim ke customer (lihat CON-002). **Perlakuan `q` kosong:** parameter `q` kosong atau tidak dikirim (`?q=` atau absen) diperlakukan sebagai "tidak ada filter pencarian" — cek `!empty($q)` untuk skip logic filter sepenuhnya, JANGAN jalankan `LIKE '%%'` yang secara matematis match semua baris. **Konsistensi limit first-paint:** naikkan juga limit `findAll(100)` → `findAll(500)` di `Inbox::index()` (`app/Controllers/Inbox.php`, baris ~38, SSR first-paint) — konsisten dengan limit `apiConversations()` di atas, supaya tidak ada inkonsistensi jumlah data antara tampilan first-paint (SSR) dan AJAX refresh pertama.                                                                                                                | REQ-010,012          | AC-005,AC-006         | TASK-001,TASK-010 | 2-3   |           |      |
| TASK-012 | Perluas Snooze Dialog (Fase 1b) menambah field Alasan opsional. Saat diisi, setelah `Inbox::snoozePercakapan()` berhasil, panggil endpoint Internal Note (TASK-008) untuk insert 1 baris berisi alasan tersebut — TIDAK menambah kolom `snooze_reason` baru (REQ-011). Snooze tanpa alasan tetap berjalan seperti Fase 1a (tidak memanggil endpoint note). **Penanganan kegagalan parsial (resolusi klarifikasi 2026-09-21):** jika panggilan `snoozePercakapan()` berhasil tapi panggilan kedua (insert Internal Note berisi alasan) gagal, snooze TETAP dianggap berhasil sepenuhnya — tampilkan notifikasi non-blocking ke staff (mis. toast peringatan "Snooze berhasil, tapi alasan gagal disimpan") tanpa retry otomatis dan tanpa membatalkan/rollback snooze yang sudah tersimpan.                                                                                                                                                                                                                                                                    | REQ-011              | AC-007                | TASK-004,TASK-008 | 1-2   |           |      |
| TASK-013 | **VERIFY**: Tambah test `tests/unit/` untuk `InboxSlaService` (semua kombinasi threshold × `queue_status`, pure function tanpa DB — AC-005, AC-006). Tambah test `tests/session/` untuk `apiConversations()` dengan parameter `status`/`q` baru (termasuk kasus tab "Selesai" dengan `last_message_at` lama tidak hilang akibat limit 500). Tambah test untuk AC-007 (Internal Note otomatis tersimpan saat Alasan diisi, tidak ada kolom `snooze_reason`). Jalankan `composer test` penuh — 100% lolos, termasuk regresi Tahap A dan Phase 1.                                                                                                                        | -                    | -                     | -                | -     |           |      |
| TASK-014 | **APPROVAL**: Tunggu konfirmasi eksplisit user bahwa Fase 1a + Fase 1b selesai dan siap lanjut ke `/sdlc-clarify-reqs` / `/sdlc-write-code` handoff berikutnya (di luar scope plan ini, mis. Fase 2).                                                                                                                                                                                                                                                                                                                                                                                                                                          | -                    | -                     | -                | -     |           |      |

## 3. Alternatives

- **ALT-001**: Membuat endpoint filter terpisah (`GET /inbox/api/conversations/filter`) alih-alih memperluas `apiConversations()` — ditolak karena menduplikasi query dasar dan melanggar REQ-002 (single source computed status).
- **ALT-002**: Filter tab via `WHERE` SQL per status alih-alih filter-after-fetch — ditolak (ASSUMPTION-001 CONFIRMED) karena akan menerjemahkan ulang kondisi `attachResponseState()` di dua tempat, berisiko drift.
- **ALT-003**: Menambah kolom `snooze_reason` terpisah di `conversations` — ditolak eksplisit (spec Bagian 9 "Never do"), Internal Note dipilih sebagai storage tunggal alasan snooze.

## 4. Dependencies

- **DEP-001**: `Inbox::attachResponseState()` (existing, `app/Controllers/Inbox.php`) — basis `withComputedStatus()` (REQ-002).
- **DEP-002**: Migration `2026-09-19-000001_AddResponseStateFoundation.php` sebagai pola referensi migration Fase 1b.
- **DEP-003**: Koneksi DB `inbox` (`aulia_inboxdb`) — migration TASK-007 wajib `$DBGroup = 'inbox'`.
- **DEP-004**: `app/Config/Inbox.php` (existing) — tempat menambah properti threshold SLA (REQ-010).

## 5. Files

- **FILE-001**: `app/Models/ConversationModel.php` — tambah `withComputedStatus()` (TASK-001).
- **FILE-002**: `app/Controllers/Inbox.php` — perluas `apiConversations()`, tambah `catatanInternal()` (TASK-002, TASK-008, TASK-011).
- **FILE-003**: `app/Config/Routes.php` — route baru `POST /inbox/percakapan/(:num)/catatan` (TASK-008).
- **FILE-004**: `app/Database/Migrations/<timestamp>_AddIsInternalToMessages.php` — migration baru (TASK-007).
- **FILE-005**: `app/Services/InboxSlaService.php` — Service baru (TASK-010).
- **FILE-006**: `app/Config/Inbox.php` — tambah properti threshold SLA (TASK-010).
- **FILE-007**: `app/Views/inbox/*` (view existing, cek dulu sebelum menambah file baru) — render 5 tab, sla_color, filter UI, field Alasan Snooze (TASK-002, TASK-004, TASK-012).
- **FILE-008**: `tests/database/`, `tests/session/`, `tests/unit/` — test baru per TASK-005, TASK-009, TASK-013.

## 6. Testing

- **TEST-001**: `tests/database/` — `ConversationModel::withComputedStatus()` per kombinasi `response_state` × `assigned_to` (5 tab) + fallback tanpa `last_message_direction`.
- **TEST-002**: `tests/session/` — endpoint POST Internal Note (akses tanpa `cekOwnership()`, AC-004; non-mutasi `last_message_at`/`last_message_direction`, REQ-009; izin pada `closed`).
- **TEST-003**: `tests/session/` — `GET /inbox/api/conversations` dengan parameter `status`/`q` baru, termasuk kasus tab "Selesai" tidak kehilangan data akibat limit 500.
- **TEST-004**: `tests/unit/` — `InboxSlaService` sebagai pure function (semua kombinasi threshold × `queue_status`).
- **TEST-005**: Regresi — jalankan test existing Tahap A (`apiPerluDibalasCount()`, badge sidebar) sebelum & sesudah Phase 1 dan Phase 2, pastikan tidak ada perubahan hasil.
- **TEST-006 (Macro Gate)**: `composer test` 100% lolos sebelum tiap APPROVAL checkpoint (TASK-006, TASK-014), sesuai `AGENTS.md` Testing Policy.

## 7. Risks & Assumptions

- **ASSUMPTION-001 (dari spec, CONFIRMED)**: Filter tab & pencarian diimplementasikan filter-after-fetch di PHP dengan `findAll(500)`, bukan endpoint/WHERE baru. Task terkait: TASK-011. Risiko rendah (sudah dikonfirmasi eksplisit user di sesi clarification sebelumnya).
- **ASSUMPTION-002 (dari spec, CONFIRMED)**: `menunggu_customer` tetap dihitung warna SLA. Task terkait: TASK-010. Risiko rendah.
- **ASSUMPTION-003 (dari spec, CONFIRMED)**: `is_internal` `NOT NULL DEFAULT FALSE`, pola rujukan `tinyint(1)` modul POS. Task terkait: TASK-007. Risiko rendah.
- **RISK-001**: TASK-008 rawan human error "copy-paste" dari `kirim()`/`InboxGatewayApi::messages()` yang ikut memanggil `update()` pada `conversations` — ini adalah pelanggaran diam-diam (silent) yang TIDAK terdeteksi hanya dengan assert `is_internal=TRUE` tersimpan (lihat spec Bagian 12). Mitigasi: TASK-009 wajib assert eksplisit `last_message_direction` tidak berubah, bukan hanya assert insert sukses. Ditandai *High Risk* — review manual saat code review sebelum merge.
- **RISK-002**: Kenaikan `findAll(500)` di TASK-011 berpotensi memperlambat `apiConversations()` pada dataset besar — di luar scope Fase 1 untuk optimasi (mis. pagination), tapi perlu dipantau pasca-deploy (tidak ada task mitigasi performa di plan ini, dicatat sebagai risiko yang diterima sesuai keputusan clarification report).
- **RISK-003**: Fase 2 (Handoff, atomic `cekOwnership()`) terkunci menunggu M2 — plan ini tidak menyentuh area itu sama sekali (Out of Scope, spec Bagian 1.1), tidak ada task yang boleh diperluas ke sana.

## 8. Related Specifications / Further Reading

- `spec/spec-design-m3-operational-inbox-fase1.md`
- `docs/audit/clarification-report-m3-fase1-operational-inbox-spec-2026-09-20.md`
- `docs/audit/clarification-report-m3-fase1-operational-inbox-plan-2026-09-21.md` (Readiness Score 97/100 — 7 resolusi sudah dituliskan ulang ke TASK-002, TASK-008, TASK-011, TASK-012 di plan ini)
- `docs/adr/0001-reuse-response-state-for-queue-view-status.md`
- `docs/CHAT.md`, `docs/TODO-CHAT.md`

## 9. Rollback / Recovery Plan

- **Phase 1 (Fase 1a)**: Tidak ada migration — rollback cukup `git revert` commit terkait TASK-001 s/d TASK-004. Tidak ada perubahan skema, tidak ada risiko data.
- **Phase 2 (Fase 1b)**:
  - Jika migration TASK-007 perlu di-rollback: `php spark migrate:rollback` (kolom `is_internal` additive, aman di-drop, tidak ada data existing yang bergantung padanya).
  - Jika endpoint Internal Note (TASK-008) bermasalah di produksi (mis. ternyata memicu perubahan `response_state` akibat bug REQ-009): nonaktifkan route di `app/Config/Routes.php` (comment out) sebagai mitigasi cepat sebelum `git revert` penuh, karena data `messages.is_internal=TRUE` yang sudah terlanjur tersimpan tidak perlu dihapus (tidak destruktif, hanya perlu diperbaiki logikanya).
  - `git revert` per-task-commit direkomendasikan (bukan `reset --hard`) agar histori tetap bisa diaudit sesuai `CLAUDE.md` konvensi git commit yang deskriptif.
