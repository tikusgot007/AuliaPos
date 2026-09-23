---
goal: M3 Operational Inbox — Fase 2a (Handoff + Collision Detection)
version: 1.0
date_created: 2026-09-22
last_updated: 2026-09-22
owner: AuliaPos Inbox module
status: 'Planned'
tags: [feature, inbox, chat, whatsapp, m3, handoff, collision-detection]
---

# Introduction

![Status: Planned](https://img.shields.io/badge/status-Planned-blue)

Plan ini mengeksekusi `spec/spec-design-m3-operational-inbox-fase2a-handoff-collision.md` v1.0 (Readiness Score 97/100, Iterasi 2 setelah klarifikasi) untuk menambah Handoff antar staff dan Collision Detection di atas fondasi M3 Fase 1 (Queue View + `queue_status` + Internal Note). Dieksekusi sebagai 3 tracer bullets vertikal (DB → UI, masing-masing independen dan demoable) plus 1 hardening/DoD slice. Horizontal slicing (satu lapis untuk semua perilaku) dilarang.

Enam patch bedah dari hasil klarifikasi (sudah dikunci, tanpa redesign) diterapkan langsung oleh plan ini dan wajib dihormati `/sdlc-write-code` sebagai koreksi normatif atas teks Spec v1.0:

- P-01 (F-C01): penolakan `selesai` = 409 (bukan 403) — flow Section 4.3 + AC-H02.
- P-02 (F-C02): batas panjang `summary`/`next_action`/`note` = 4096 (bukan 500) — Section 4.1/4.3/4.4.
- P-03 (F-C03): target ber-role `admin` ditolak 403 — REQ-H03 (kasir aktif saja).
- P-04 (F-C04): kontrak formal Section 4.3b untuk `GET /inbox/percakapan/(:num)/handoff` (auth filter, envelope, limit 50, terbaru dulu, tanpa ubah `GET messages`).
- P-05 (F-C05a): validasi inisiator — inisiator = assignee saat ini, kecuali `belum_diambil` boleh kasir aktif mana pun; non-assignee = 403 — REQ-H01 + flow + edge case + Section 4.4.
- P-06 (F-C05b): AC-H08 baru + 1 controller test untuk penolakan non-assignee.

## 1. Requirements & Constraints

- **REQ-H01 (patched P-05):** Inisiator = staff sesi aktif DAN (`assigned_to` saat ini, ATAU percakapan `belum_diambil` oleh kasir aktif mana pun). Non-assignee pada tab selain `belum_diambil` = 403 (AC-H08). Tanpa approval, tanpa jalur paksa admin.
- **REQ-H02 (patched P-01):** Handoff diizinkan bila Queue View Status apapun kecuali `selesai` (`queue_status !== 'selesai'`, sumber tunggal `ConversationModel::withComputedStatus()`). Pelanggaran = 409 (keluarga state-reload, preseden `hapusPercakapan()`), bukan 403.
- **REQ-H03 (patched P-03):** Target wajib kasir aktif (`role = 'kasir'`, `is_active` benar) dari `UserModel::daftarKasirAktif()`; target `admin`/unknown/inactive/ineligible = 403. Target boleh offline.
- **REQ-H04 (patched P-02):** Payload `summary` wajib non-kosong max 4096, `next_action` wajib non-kosong teks bebas max 4096, `note` opsional max 4096, `to_user_id` wajib, `expected_owner` wajib (int atau null). Field `expected_owner` yang tidak dikirim = 400 (malformed); null/string-kosong = klaim sah 'saw unassigned' yang diteruskan ke conditional write `<=>` untuk diputus 200/409 (Q5).
- **REQ-H05:** Handoff ke diri sendiri = 400.
- **REQ-H06:** Saat sukses `assigned_to` menjadi `to_user_id`; kolom lain tidak berubah (kecuali `updated_at` bawaan); `snoozed_until` tidak direset.
- **REQ-H07:** Tiap sukses menyisipkan satu baris `conversation_handoffs` (`from_user_id` nullable, `initiated_by_user_id` NOT NULL dari session — K-06; `from = initiator` kecuali kasus unassigned NULL).
- **REQ-H08 (patched P-04):** Riwayat dibaca via endpoint khusus `GET /inbox/percakapan/(:num)/handoff` (auth filter), terbaru dulu, cap 50, tanpa edit/hapus; `GET /inbox/api/conversations/(:num)/messages` TIDAK diubah. GET handoff boleh dibaca staff aktif mana pun di bawah filter `auth` saja, tanpa gerbang assignee; 404 hanya untuk id tak dikenal (Q7, PRD GH-006).
- **REQ-H09:** Ownership write + history insert dalam satu transaksi grup `inbox`; 0 affected rows = rollback + 409; gagal insert = rollback + ownership utuh.
- **REQ-H10:** `lepas`, `tutup`, `snooze`, `tandai-dibaca`, `hapus`, `catatanInternal`, `ambilPercakapan` TIDAK diubah — hanya reuse pola.
- **REQ-C01:** Conditional write `SET assigned_to = :to WHERE id = :id AND assigned_to <=> :expected` (NULL-safe `<=>`).
- **REQ-C02 (patched P-05):** 0 affected rows = tanpa perubahan, tanpa insert, 409 wajib sebut nama pemilik sah + fallback `User #{id}`, body sertakan `current_owner_id` (nullable).
- **REQ-C03:** Tanpa presence (heartbeat/TTL/d Indikator "sedang dibuka") — dilarang diselundupkan.
- **REQ-C04:** Hanya dijamin tepat satu pemenang; tanpa janji fairness.
- **CON-H01:** Handoff tidak menulis `messages`, tidak memanggil Gateway, `last_message_*` tidak berubah.
- **CON-H02:** Semua tulis Handoff ke grup DB `inbox` saja; baca user via `UserModel` existing (read-only).
- **CON-H03:** Tanpa notifikasi dan tanpa unread per-user.
- **CON-H04:** Semua mutasi divalidasi ulang di server (identitas sesi, eligibilitas, bentuk payload).
- **CON-H05:** Migrasi additive saja (tabel baru, tanpa ALTER tabel lama); FK dalam DB Inbox `ON DELETE CASCADE`; soft-delete tidak menghapus riwayat.
- **CON-H06:** Timestamp `Asia/Jakarta`.
- **GUD-H01:** Respons mengikuti envelope `tutupPercakapan()` (`status: success|error`, pesan Indonesia); kode ikut K-09 + patch P-01/P-03/P-05.

## 2. Implementation Steps

> **EXECUTION DIRECTIVE FOR AI AGENTS:**
> Eksekusi bullet demi bullet (TB-01 → TB-02 → TB-03 → TB-04). Setiap bullet adalah tracer bullet vertikal DB → UI yang independen dan demoable: jangan kerjakan satu lapis untuk semua perilaku sekaligus (horizontal slicing dilarang). Jalankan task VERIFY di akhir tiap bullet. Setelah satu bullet diuji (`composer test` hijau), **BERHENTI DAN TUNGGU** persetujuan eksplisit user sebelum lanjut. Setiap increment wajib menyertakan test-nya (Two-Layer Mandate).

### Tracer Bullet TB-01 — Handoff happy path + gerbang inisiator/target

- GOAL-TB01: Kasir assignee bisa menyerahkan percakapan eligible ke kasir aktif lain via dialog; non-assignee ditolak; target admin ditolak; `selesai` ditolak 409; riwayat tercatat satu baris. Demo: A (owner) handoff ke B → Queue View pindah ke B.

| Task | Description | Ref ID | AC Ref | Dep | Files | Completed | Date |
| ---- | ----------- | ------ | ------ | --- | ----- | --------- | ---- |
| TASK-001 | Migration additive `app/Database/Migrations/<timestamp>_CreateConversationHandoffs.php` (`$DBGroup='inbox'`, guard create-only-if-missing): `id` INT UNSIGNED AI PK; `conversation_id` INT UNSIGNED NOT NULL FK ke `conversations.id` ON DELETE CASCADE; `from_user_id` NULL; `to_user_id` NOT NULL; `initiated_by_user_id` NOT NULL; `summary`/`next_action` max 4096 (P-02); `note` TEXT NULL; `created_at` DATETIME NOT NULL Asia/Jakarta; index `(conversation_id, id DESC)`. Tanpa ALTER tabel lama. + migration test. | CON-H05, P-02 | AC-H01 | - | 1+test | | |
| TASK-002 | Model `app/Models/ConversationHandoffModel.php` (`$DBGroup='inbox'`): `insertHandoff(): int` + `forConversation(id, limit=50): array` newest-first. Tanpa business logic. + `UserModel::daftarKasirAktif()` (BELUM ADA — wajib dibuat: `role='kasir'` + `is_active=1`). Kontrak: `WHERE role='kasir' AND is_active=1`, kolom `id` + `nama`, `ORDER BY nama ASC`; satu sumber untuk dropdown target, nama pemenang 409, dan cek inisiator belum_diambil (Q6). + `tests/database/` untuk keduanya (NULL from, newest-first+cap, admin/inactive excluded). | REQ-H03/P-03, H07/H08 | AC-H01 | TASK-001 | 2+tests | | |
| TASK-003 | Controller `Inbox::handoffPercakapan($id)` (method BARU; lama TIDAK diubah) + route POST auth: urutan (1) 404; (2) `withComputedStatus()`, `selesai`→409 (P-01); (3) validasi 400 (blank/over-4096 P-02, self, malformed); (4) gerbang inisiator P-05 (non-assignee non-belum_diambil→403 AC-H08); (5) target kasir-aktif else 403 incl admin (P-03); (6) transaksi inbox: conditional write `<=>` + insert riwayat + commit; 0 rows→rollback+409 bernama+`current_owner_id`; insert gagal→rollback. Sukses 200 envelope `tutupPercakapan()`. + `tests/session/InboxHandoffTest.php` (H01 sukses, H02 selesai→409, H03 blank→400, H04 self→400, H05 unknown/inactive/admin→403, H06 unassigned NULL, H07 messages nol baris, H08 non-assignee→403). | REQ-H01..H09, C01/C02 | AC-H01..H08 | TASK-002 | 2+test | | |
| TASK-004 | UI minimal `app/Views/inbox/index.php` (+JS existing): dialog Handoff (to/summary/next_action/note, `expected_owner` saat dialog dibuka, dropdown kasir aktif); fetch; sukses toast+refresh; 409/403/400 notice (409 sebut nama). Tanpa ubah tab/SLA/filter/messages. | REQ-H04 | AC-H01 | TASK-003 | 1-2 | | |
| TASK-005 | **VERIFY TB-01:** `composer test` 100% hijau. Demo happy path + tiap kode tolak. **BERHENTI, tunggu approval.** | — | AC-R01 parsial | TASK-004 | - | | |

### Tracer Bullet TB-02 — Collision Detection + loser UX

- GOAL-TB02: Dua staff bentrok pada `expected_owner` sama → tepat satu 200 + satu 409 menyebut pemenang; stale owner → 409; gagal insert → rollback. Demo: A dan B buka dialog saat owner=7; A menang ke 11; B ke 12 → 409 bernama, owner tetap 11, satu riwayat.

| Task | Description | Ref ID | AC Ref | Dep | Files | Completed | Date |
| ---- | ----------- | ------ | ------ | --- | ----- | --------- | ---- |
| TASK-006 | Perkuat jalur 409 (bila belum lengkap di TB-01): re-fetch owner terkini, nama via `UserModel` + fallback `User #{id}`, body `{status:error, message, current_owner_id}`; TANPA insert di jalur kalah. Test sekuensial (dua read sama → req1 200 → req2 stale → 409); stale → 409; forced insert-fail → rollback. Tambah AC-C01/C02/C03 ke `InboxHandoffTest.php`. | REQ-C01/C02, H09 | AC-C01..C03 | TB-01 | 1+test | | |
| TASK-007 | UI loser notice: tampilkan nama pemilik sah + tombol muat-ulang; dobel-klik aman (retry jatuh 409, K-09). | REQ-C02 | AC-C01 | TASK-006 | 1 | | |
| TASK-008 | **VERIFY TB-02:** `composer test` 100% hijau. Demo race + stale + rollback. **BERHENTI, tunggu approval.** | — | AC-R01 parsial | TASK-007 | - | | |

### Tracer Bullet TB-03 — Riwayat baca khusus GET handoff (tanpa ubah GET messages)

- GOAL-TB03: Staff bisa membaca riwayat penyerahan terbaru-dulu (cap 50) via endpoint khusus; thread `messages` tidak tersentuh. Demo: setelah 2 handoff, panel riwayat tampil 2 entri terbaru dulu.

| Task | Description | Ref ID | AC Ref | Dep | Files | Completed | Date |
| ---- | ----------- | ------ | ------ | --- | ----- | --------- | ---- |
| TASK-009 | Controller `Inbox::apiHandoffs($id)` (method BARU) + route `GET /inbox/percakapan/(:num)/handoff` (filter `auth`, kontrak P-04): 404 bila tak dikenal; sukses 200 `{status:success, handoffs:[{id, from_user_id, to_user_id, initiated_by_user_id, summary, next_action, note, created_at}], limit:50}` newest-first via `forConversation()`. `GET messages` TIDAK diubah. GET handoff boleh dibaca staff aktif mana pun di bawah filter `auth` saja, tanpa gerbang assignee; 404 hanya untuk id tak dikenal (Q7, PRD GH-006). + test: 2 handoff → GET tampil 2 terbaru-dulu; cap 50; 404 unknown; auth filter terdaftar. | REQ-H08/P-04 | AC-H01 | TB-01 | 2+test | | |
| TASK-010 | UI panel riwayat: render list dari GET handoff (nama from/to/initiator, summary, next_action, note, waktu Jakarta); refresh setelah handoff sukses dan setelah 409. | REQ-H08 | AC-H01 | TASK-009 | 1 | | |
| TASK-011 | **VERIFY TB-03:** `composer test` 100% hijau. Demo panel riwayat. **BERHENTI, tunggu approval.** | — | AC-R01 parsial | TASK-010 | - | | |

### Slice TB-04 — Hardening + DoD (edge cases, boundaries, docs)

- GOAL-TB04: Semua edge case + boundary terkunci, `composer test` 100% final, peta arsitektur mutakhir.

| Task | Description | Ref ID | AC Ref | Dep | Files | Completed | Date |
| ---- | ----------- | ------ | ------ | --- | ----- | --------- | ---- |
| TASK-012 | Edge cases: `note` kosong OK; blank-spasi summary/next_action → 400; `to==initiator` → 400 walau bukan owner; non-assignee selalu 403 kecuali `belum_diambil` (REQ-H01/P-05 ketat, Q1); `from_user_id` = initiator, kecuali kasus unassigned = NULL (K-06); unknown id → 404; `ambilPercakapan()` di antara buka-dialog dan submit → 409; Handoff pada `ditunda` pertahankan `snoozed_until`; Handoff dari `belum_diambil` → tab `open` atas nama penerima, tab lain tidak pindah tab; `assigned_to` NULL + `expected_owner` null cocok via `<=>`. Tambah test tiap butir. | REQ-H05/H06/H10, C01 | AC-H04/H06 | TB-02 | 1+test | | |
| TASK-013 | Boundaries audit: tidak ada ubah 7 method lama; tidak ada ALTER `messages`/`conversations`; tidak ada ubah `withComputedStatus()`; tidak ada panggil Gateway; tidak ada presence/unread/notifikasi; tidak ada ubah Queue tab/SLA/filter. Verifikasi via diff-review + test regresi Fase 1 hijau. | REQ-H10, C03, CON-H01/03 | — | TASK-012 | - | | |
| TASK-014 | **APPROVAL / DoD:** `composer test` 100% (AC-R01 final); update `docs/ARCHITECTURE.md` (tabel+model+2 route baru, Living Map Mandate); markdownlint plan; tunggu konfirmasi user sebelum `/sdlc-write-code`. | — | AC-R01 | TASK-013 | 1 | | |

## 3. Alternatives

- **ALT-01:** Satu task migrasi + satu task model + satu task controller + satu task UI (horizontal slicing) — DITOLAK: melanggar mandat vertical slicing; tidak ada yang demoable sebelum semua lapis selesai.
- **ALT-02:** Baca riwayat dengan mengubah `GET messages` (merge inline) — DITOLAK: melanggar kunci Q1 (endpoint khusus, tanpa ubah GET messages); risiko regresi thread.
- **ALT-03:** Enum `next_action` — DITOLAK: mengarang requirement (ASSUMPTION-007, kunci Q7 teks bebas).
- **ALT-04:** Notifikasi/push ke target — DITOLAK: K-08/CON-H03 (tanpa notifikasi; mitigasi prosedural).
- **ALT-05:** Presence/heartbeat — DITOLAK: K-04/REQ-C03 (deferred dengan prasyarat bernama).

## 4. Dependencies

- **DEP-01:** `ConversationModel::withComputedStatus()` (eligibilitas REQ-H02) — reuse, jangan duplikasi.
- **DEP-02:** `UserModel` + session `auth` filter — identitas aktor dan nama 409 (REQ-H01/C02).
- **DEP-03:** Pola migration `2026-09-22-000001_AddIsInternalToMessages.php` (`$DBGroup='inbox'`) + guard baseline `2026-09-07-000001_CreateInboxTables.php` + FK CASCADE preseden.
- **DEP-04:** Pola conditional write `ambilPercakapan()` `:1069-1097` (`affectedRows()`→409) — reuse, method aslinya TIDAK diubah.
- **DEP-05:** Envelope `tutupPercakapan()` `:1263-1268` — bentuk sukses (GUD-H01).
- **DEP-06:** Pola dual-read form+JSON `catatanInternal()` — bentuk request.
- **DEP-07 (GAP, dibuat TASK-002):** `UserModel::daftarKasirAktif()` belum ada; plan ini menjadikannya deliverable eksplisit. Kontrak: `WHERE role='kasir' AND is_active=1`, kolom `id` + `nama`, `ORDER BY nama ASC`; satu sumber untuk dropdown target, nama pemenang 409, dan cek inisiator belum_diambil (Q6).

## 5. Files

- **FILE-01:** `app/Database/Migrations/<timestamp>_CreateConversationHandoffs.php` — baru (TASK-001).
- **FILE-02:** `app/Models/ConversationHandoffModel.php` — baru (TASK-002).
- **FILE-03:** `app/Models/UserModel.php` — tambah `daftarKasirAktif()` (TASK-002).
- **FILE-04:** `app/Controllers/Inbox.php` — `handoffPercakapan()` + `apiHandoffs()` (TASK-003/006/009); 7 method lama TIDAK diubah.
- **FILE-05:** `app/Config/Routes.php` — 2 route auth (TASK-003/009).
- **FILE-06:** `app/Views/inbox/index.php` (+JS) — dialog + riwayat + notice (TASK-004/007/010).
- **FILE-07:** `tests/database/ConversationHandoffModelTest.php` — baru (TASK-002).
- **FILE-08:** `tests/session/InboxHandoffTest.php` — baru (TASK-003/006/009/012; incl 1 test AC-H08 P-06).
- **FILE-09:** `docs/ARCHITECTURE.md` — update TB-04 (TASK-014).

## 6. Testing

- **TEST-01:** `tests/database/` — migrasi (kolom/index/FK CASCADE, tabel lama untouched); `insertHandoff` incl NULL from; `forConversation` newest-first cap 50; `daftarKasirAktif` (admin/inactive excluded).
- **TEST-02:** `tests/session/` — AC-H01 sukses; H02 `selesai`→409 (P-01); H03 blank/over-4096→400 (P-02); H04 self→400; H05 unknown/inactive/admin→403 (P-03); H06 unassigned NULL; H07 messages nol + `last_message_*` utuh; H08 non-assignee→403 (P-06).
- **TEST-03:** `tests/session/` — AC-C01 race sekuensial 200+409 bernama + 1 baris; C02 stale→409; C03 insert-fail→rollback.
- **TEST-04:** `tests/session/` — GET handoff P-04 (2 entri terbaru-dulu, cap 50, 404, auth); edge TASK-012 (note kosong OK, blank-spasi 400, to==initiator 400, from/initiated, unknown 404, ambil-di-tengah→409, ditunda keep snooze, belum_diambil→open).
- **TEST-05:** Regresi Fase 1 hijau + boundaries TASK-013.
- **TEST-06 (Macro Gate):** `composer test` 100% di tiap VERIFY (TASK-005/008/011) dan final (TASK-014).

## 7. Risks & Assumptions

- **RISK-01 (HIGH):** Spec v1.0 masih bertuliskan 500/403/admin-boleh/tanpa-gate-inisiator; implementor tanpa plan akan salah. Mitigasi: patch P-01..P-06 normatif; `/sdlc-write-code` ikut plan bila konflik; finalisasi spec sebelum/saat coding. (TASK-003/009)
- **RISK-02 (MEDIUM):** `daftarKasirAktif()` belum ada — didefinisikan di TASK-002 (`role='kasir'` + `is_active=1`); jangan impor definisi luar repo.
- **RISK-03 (MEDIUM):** `VARCHAR(4096)` bisa lampaui batas row utf8mb4; bila forge menolak, pakai `TEXT` untuk summary/next_action, catat di commit; batas tetap ditegakkan di controller (400). (TASK-001)
- **RISK-04 (LOW):** Builder harus hasilkan `<=> NULL` (bukan `= NULL`) untuk unassigned; ditutup test AC-H06/edge. (TASK-003)
- **RISK-05 (LOW):** Dropdown target basi (kasir dinonaktifkan setelah dialog dibuka) — server tetap 403; tanpa auto-reassign.
- **ASSUMPTION (locked):** 9 hasil klarifikasi di Introduction — jangan diinterogasi ulang.

## 8. Related Specifications / Further Reading

- `spec/spec-design-m3-operational-inbox-fase2a-handoff-collision.md` v1.0 (+6 patch P-01..P-06 plan ini)
- `prd-20260922-0141-chat-whatsapp-inbox.md` v1.1 (GH-006/GH-007; GH-008 Fase 2b out of scope)
- `docs/audit/clarification-report-m3-fase2-m2-gate-2026-09-22.md` (K-01..K-09)
- `CONTEXT.md` + `docs/ARCHITECTURE.md` (diupdate TASK-014)
- `.claude/instructions/memory.instructions.md` (checkpoint 97/100)
- `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` (preseden format/VERIFY)

## 9. Rollback / Recovery Plan

- **TB-01:** drop tabel (`migrate:rollback`, additive aman); comment-out 2 route; `git revert` per-task-commit.
- **TB-02:** revert commit TASK-006/007 (logika + test di file sama).
- **TB-03:** nonaktifkan `GET handoff` dulu (comment route); data riwayat utuh (tanpa purge).
- **Umum:** tanpa ALTER tabel lama dan tanpa tulis `messages`, rollback tidak merusak data existing.

