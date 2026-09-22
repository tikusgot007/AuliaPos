---
title: M3 Operational Inbox — Fase 2a (Handoff + Collision Detection)
version: 1.0
date_created: 2026-09-22
owner: AuliaPos Inbox module
tags: [inbox, chat, whatsapp, m3, handoff, collision-detection]
---

# Introduction

Spesifikasi ini mendefinisikan **M3 — Operational Inbox, Fase 2a**: kemampuan *Handoff* antar staff dan *Collision Detection* pada modul Inbox WhatsApp AuliaPos. Fase 2a adalah pemotongan sempit dari "Fase 2" yang disebut dokumen lama — hanya dua perilaku itu, di atas fondasi Fase 1 (Queue View, Conversation Detail, Snooze, Selesai) yang sudah berjalan.

Kontrak teknis di spec ini **bukan karangan baru**. Seluruh keputusan intinya sudah ditetapkan sebagai **K-01 s.d. K-09** di `docs/audit/clarification-report-m3-fase2-m2-gate-2026-09-22.md`, dan requirement produknya ada di `prd-20260922-0141-chat-whatsapp-inbox.md` v1.1 (GH-006, GH-007). Spec ini menerjemahkan keduanya menjadi kontrak yang bisa langsung dikodekan dan diuji, tanpa menambah perilaku yang belum disepakati.

## 1. Purpose & Scope

Spec ini mencakup, dan hanya mencakup:

- **Handoff** — pemindahan `conversations.assigned_to` dari satu staff ke staff lain, disertai `summary` (wajib), `next_action` (wajib), dan `note` (opsional), dengan riwayat penyerahan yang tercatat permanen dan bisa dibaca kembali.
- **Collision Detection** — perilaku sistem ketika dua staff mengubah kepemilikan percakapan yang sama pada saat yang hampir bersamaan: tepat satu perubahan diterima (conditional write di database), permintaan yang kalah ditolak tanpa menimpa kepemilikan yang sudah sah, dan pelaku yang kalah diberi tahu siapa pemilik sah saat itu.
- **Gate M2 secara sempit (K-01)** — atomicitas kepemilikan **hanya** dibuka pada jalur Handoff, memakai primitif *expected-owner conditional write* yang sudah berjalan di `Inbox::ambilPercakapan()`.

Audiens: developer yang akan mengeksekusi `/sdlc-plan-tasks` → `/sdlc-write-code`, serta agent `/sdlc-clarify-reqs` dan `/sdlc-audit-consistency` yang akan memeriksa kelengkapan dan ketertelusuran spec ini.

Asumsi dasar (lihat juga Section 1.2):

1. Spec ini dibangun di atas branch turunan `v2.2`/`v2.3` yang sudah punya modul Inbox M3 Fase 1 (Queue View + `queue_status` + Internal Note).
2. Database Inbox (`aulia_inboxdb`, connection group `inbox`) tetap terpisah dari database POS (`aulia_kasirdb`).
3. Seluruh pekerjaan Fase 2a berada di sisi AuliaPos (CI4). Tidak ada perubahan di repo `tikusgot007/WA-Gateway`.
4. Definition of Done Fase 2a mengikuti kebijakan tes proyek: `composer test` lolos 100%.

### 1.1 Out of Scope

- **Presence / awareness** ("sedang dibuka oleh Budi", tabel presence, heartbeat, TTL) — dideklarasikan *deferred* di PRD v1.1 dengan prasyarat bernama (K-04). Collision Detection di spec ini **bukan** presence.
- **Notifikasi antar staff dan penanda belum dibaca (unread) per pengguna** (K-08) — Fase 2a sengaja berjalan tanpa notifikasi; penerima Handoff menemukan percakapan lewat Queue View bersama dan riwayat Handoff. Risiko target offline yang melewatkan penyerahan dimitigasi secara prosedural, bukan teknis.
- **Auto-assignment (Fase 2b, GH-008)** — dipisahkan ke inkremen berikutnya (K-03). Aturan beban kerja, definisi "sedang bertugas", dan tie-breaker belum ditetapkan.
- **Program M2 (State Consistency) secara menyeluruh** — tetap *deferred*. Jalur kepemilikan lain yang masih *read-then-write* (`lepas`, `tutup`, `snooze`, `tandai-dibaca`, `hapus`) **tidak diubah** di Fase 2a.
- **Penghapusan/purge riwayat Handoff** — tidak ada di Fase 2a; riwayat adalah jejak audit.
- **Handoff paksa oleh admin, alur persetujuan (approval), atau reassignment otomatis** — Handoff bersifat transfer langsung tanpa persetujuan; tidak ada jalur override admin pada Fase 2a.
- **@mention dengan notifikasi nyata** dan **Customer Context penuh (M4)** — tetap di luar lingkup.
- **Penulisan pesan/Internal Note otomatis saat Handoff** — lihat REQ-009 dan Section 10 (konteks lama yang sudah disuperseded oleh K-05).

### 1.2 Open Questions & Assumptions

Semua ambiguitas **mayor** sudah diselesaikan lewat sesi `/sdlc-clarify-reqs` dan tercatat sebagai K-01 s.d. K-09 di `docs/audit/clarification-report-m3-fase2-m2-gate-2026-09-22.md`. Yang tersisa hanya detail kontrak minor berikut. Sesuai protokol *heavy lifting*, setiap butir sudah diisi dengan pilihan paling logis berdasarkan kode yang ada, dan ditandai agar bisa diinterogasi di sesi klarifikasi berikutnya.

> [!WARNING]
> **[ASSUMPTION-001] Kontrak baca riwayat Handoff.** PRD GH-006 mewajibkan riwayat penyerahan "tercatat serta bisa dibaca kembali oleh staff", sedangkan K-05 hanya mengunci *model pencatatan* (tabel `conversation_handoffs`) dan menyebut visibilitas inline "bila nanti diinginkan". Spec ini mengambil pilihan paling minimal dan paling mudah diuji: endpoint **baca khusus** `GET /inbox/percakapan/(:num)/handoff` yang mengembalikan daftar riwayat (terbaru dulu), **tanpa** mengubah kontrak `GET /inbox/api/conversations/(:num)/messages` dan **tanpa** menulis apa pun ke tabel `messages`. Lihat Section 4.4.

> [!WARNING]
> **[ASSUMPTION-002] Kode status untuk penolakan eligibilitas `selesai` = 409.** K-09 hanya menetapkan `403` (pelaku/target tidak berhak), `409` (ownership berubah antara read dan write), dan `400` (validasi). Penolakan karena percakapan sudah di tab `Selesai` (K-07) diklasifikasikan sebagai **409** — satu keluarga dengan penolakan berbasis *state* yang menuntut klien memuat ulang kondisi terkini, mengikuti preseden `hapusPercakapan()` yang memakai 409 untuk pelanggaran prasyarat status. Alternatif yang dipertimbangkan: 403; tidak dipilih karena 403 di modul ini secara semantik berarti "identitas pelaku/target tidak berhak", bukan "state percakapan tidak mengizinkan".

> [!WARNING]
> **[ASSUMPTION-003] Target Handoff boleh ber-role `admin`.** Diterima selama `users.is_active` benar, konsisten dengan `cekOwnership()` yang menempatkan admin di jalur override dan dengan fakta bahwa enum `users.role` hanya `admin`/`kasir`.

> [!WARNING]
> **[ASSUMPTION-004] Pesan error 409 wajib menyebut nama pemilik sah saat itu.** Mengikuti preseden pesan 409 di `ambilPercakapan()` ("Percakapan ini sudah diambil oleh {nama}."), termasuk fallback `User #{id}` bila nama tidak ditemukan.

> [!WARNING]
> **[ASSUMPTION-005] Zona waktu `Asia/Jakarta`.** Mengikuti preseden `snoozePercakapan()`, `tutupPercakapan()`, dan `catatanInternal()` yang menulis waktu dengan `new \DateTime('now', new \DateTimeZone('Asia/Jakarta'))`.

> [!WARNING]
> **[ASSUMPTION-006] Riwayat Handoff mengikuti siklus hidup percakapan.** `conversation_handoffs.conversation_id` memakai foreign key **dalam database Inbox** ke `conversations.id` dengan `ON DELETE CASCADE` (preseden `messages` di `2026-09-07-000001_CreateInboxTables.php`), sedangkan soft-delete `hapusPercakapan()` **tidak** menghapus riwayat.

> [!WARNING]
> **[ASSUMPTION-007] `next_action` adalah teks bebas, bukan enum.** Tidak ada dokumen sumber yang dapat diverifikasi yang mendefinisikan daftar tindakan lanjutan; menetapkan enum sekarang berarti mengarang requirement. Enum dapat ditambahkan di inkremen berikutnya bila polanya sudah terlihat dari data nyata.

> [!NOTE]
> **Konteks yang sudah disuperseded (jangan dipakai).** Catatan Handoff lama di repo-root `memory.instructions.md` menyatakan "Successful Handoff ... creates one Internal Note in the conversation thread". Keputusan **K-05** yang lebih baru dan sudah diremediasi ke PRD v1.1 membatalkan itu: tabel `messages` **tidak disentuh** oleh Handoff. Spec ini mengikuti K-05.

### 1.2.1 Decision Log — locked this session

| ID | Keputusan | Sumber |
|---|---|---|
| K-01 | Atomicitas kepemilikan dibuka **sempit** di jalur Handoff lewat *expected-owner conditional write*; `affectedRows() === 0` berarti 409 tanpa menimpa ownership. M2 sebagai program tetap *deferred*. | `clarification-report-m3-fase2-m2-gate-2026-09-22.md` K-01 |
| K-03 | Lingkup Fase 2a = **Handoff + Collision Detection**. Auto-assignment dikeluarkan ke Fase 2b. | K-03, PRD v1.1 §2.3 dan §9.2 |
| K-04 | Collision Detection = **deteksi bentrok saat write saja**. Presence *deferred* dengan prasyarat bernama. | K-04 |
| K-05 | Riwayat Handoff hanya di tabel baru `conversation_handoffs` (DB group `inbox`, additive). Thread `messages` tidak disentuh. | K-05 |
| K-06 | Dua kolom identitas: `from_user_id` (nullable, pemilik sebelum Handoff) dan `initiated_by_user_id` (NOT NULL, pelaksana aksi dari session). | K-06 |
| K-07 | Eligibilitas runtuh menjadi satu kondisi: `queue_status !== 'selesai'`. | K-07 |
| K-08 | Fase 2a **tanpa** notifikasi dan tanpa unread per-user. | K-08 |
| K-09 | Kontrak HTTP: `400` validasi, `403` pelaku/target tidak berhak, `409` kalah conditional write; sukses mengikuti bentuk `tutupPercakapan()`. | K-09 |

## 2. Definitions

The following terms follow `CONTEXT.md` (Domain Glossary, created in the M2 gate session).

| Istilah | Definisi | _Avoid_ |
|---|---|---|
| **Handoff** | Pemindahan tanggung jawab percakapan antar staff, tercatat sebagai riwayat. | Transfer, Reassign |
| **Handoff Summary** | Ringkasan keadaan percakapan saat diserahkan, wajib diisi. | Deskripsi, Keterangan |
| **Next Action** | Tindakan lanjutan yang diharapkan dari penerima, wajib diisi, teks bebas. | Next step, TODO |
| **Handoff Note** | Catatan bebas penyerta Handoff, opsional, tidak terkirim ke pelanggan. | Komentar |
| **Collision Detection** | Tepat satu perubahan kepemilikan diterima saat dua staff menulis bersamaan; yang kalah ditolak. | Presence, Live view |
| **Presence** | Pengetahuan siapa sedang membuka percakapan. Bukan bagian Fase 2a (K-04). | Sedang online |
| **Expected Owner** | Nilai `assigned_to` yang dibaca sebelum tulis, dipakai sebagai syarat `WHERE`. | Pemilik lama |
| **Queue View Status** | Status turunan 5 tab; sumber tunggal `ConversationModel::withComputedStatus()`. | display status |
| **Internal Note** | Baris `messages` dengan `is_internal = TRUE`. Tidak dipakai Handoff (K-05). | catatan internal |

## 3. Requirements, Constraints & Guidelines

Requirement IDs: **REQ-Hxx** = Handoff, **REQ-Cxx** = Collision. Constraint IDs: **CON-Hxx**.

### 3.1 Handoff

- **REQ-H01 (who may initiate):** Initiator adalah staff sesi aktif. Tanpa approval, tanpa jalur paksa admin.
- **REQ-H02 (eligible conversation):** Handoff diizinkan bila Queue View Status apapun kecuali `selesai` (K-07).
- **REQ-H03 (eligible target):** Target wajib staff aktif yang boleh memiliki percakapan; validasi memakai `UserModel::daftarKasirAktif()` seperti `ambilPercakapan()`.
- **REQ-H04 (request payload):** `summary` wajib non-kosong, `next_action` wajib non-kosong teks bebas (ASSUMPTION-007), `note` opsional, `to_user_id` wajib, `expected_owner` wajib.
- **REQ-H05 (self-Handoff rejected):** Handoff ke diri sendiri ditolak 400 (K-09).
- **REQ-H06 (ownership change):** Saat sukses `assigned_to` menjadi `to_user_id`; kolom lain tidak berubah.
- **REQ-H07 (history record):** Tiap sukses menyisipkan satu baris `conversation_handoffs` dengan `from_user_id` (nullable) dan `initiated_by_user_id` (NOT NULL) (K-06).
- **REQ-H08 (read-back):** Riwayat terbaca per percakapan dari terbaru; tidak diedit/dihapus di Fase 2a.
- **REQ-H09 (atomicity, K-01):** Perubahan ownership dan insert riwayat dalam satu transaksi grup `inbox`; 0 affected rows berarti rollback + 409.
- **REQ-H10 (other paths untouched):** `lepas`, `tutup`, `snooze`, `tandai-dibaca`, `hapus` tidak diubah (K-01 sempit).
- **CON-H01:** Handoff tidak menulis tabel `messages` (K-05).
- **CON-H02:** Semua tulis Handoff ke grup DB `inbox` saja.
- **CON-H03:** Tanpa notifikasi dan tanpa unread per-user (K-08).

### 3.2 Collision Detection (write-time only, K-04)

- **REQ-C01 (expected-owner conditional write):** Server menulis `SET assigned_to = :to WHERE id = :id AND assigned_to <=> :expected`; pola sama seperti `ambilPercakapan()`.
- **REQ-C02 (loser loses cleanly):** 0 affected rows berarti tanpa perubahan, tanpa insert riwayat, respons 409 menyebut `current_owner_id` (K-09).
- **REQ-C03 (no presence):** Presence (heartbeat, TTL) deferred; dilarang diselundupkan ke Fase 2a (K-04).
- **REQ-C04 (determinism note):** Hanya dijamin tepat satu pemenang; urutan dari database, tidak ada janji fairness.

### 3.3 Cross-cutting constraints

- **CON-H04:** Semua mutasi divalidasi ulang di server (identitas sesi, eligibilitas REQ-H02/H03, bentuk payload).
- **CON-H05:** Migrasi additive saja (tabel baru, tanpa ALTER tabel lama).
- **CON-H06:** Timestamp zona `Asia/Jakarta` (ASSUMPTION-005).
- **GUD-H01:** Bentuk respons mengikuti `tutupPercakapan()`; kode status ikut K-09.

## 4. Interfaces & Data Contracts

### 4.1 New schema — `conversation_handoffs` (DB group `inbox`, additive)

Migration (new file, following the `2026-09-07-000001_CreateInboxTables.php` precedent; table creation guarded by the same "create only if missing" pattern used for the Inbox baseline):

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | NOT NULL | — | PK |
| `conversation_id` | INT UNSIGNED | NOT NULL | — | FK inside the Inbox database to `conversations.id`, `ON DELETE CASCADE` (ASSUMPTION-006; precedent: `messages` FK in baseline migration) |
| `from_user_id` | INT UNSIGNED | NULL | NULL | Owner before this Handoff; NULL when the conversation was unassigned (K-06). No FK to the POS `users` table — cross-database FKs are impossible with the split `inbox`/`kasirdb` groups |
| `to_user_id` | INT UNSIGNED | NOT NULL | — | New owner (same no-cross-DB-FK rationale) |
| `initiated_by_user_id` | INT UNSIGNED | NOT NULL | — | Session user who performed the Handoff; may differ from `from_user_id` (K-06) |
| `summary` | VARCHAR(500) | NOT NULL | — | Required Handoff Summary (ASSUMPTION-003 length cap) |
| `next_action` | VARCHAR(500) | NOT NULL | — | Required Next Action, free text (ASSUMPTION-007; same cap as summary for symmetry) |
| `note` | TEXT | NULL | NULL | Optional Handoff Note |
| `created_at` | DATETIME | NOT NULL | — | Write time in `Asia/Jakarta` (CON-H06) |

Indexes: `KEY idx_handoffs_conversation (conversation_id, id DESC)` — newest-first read-back without filesort. No changes to existing tables.

### 4.2 New model — `ConversationHandoffModel` (DB group `inbox`)

New file `app/Models/ConversationHandoffModel.php`, mirroring `MessageModel` conventions (`$DBGroup = 'inbox'`, `$table = 'conversation_handoffs'`, `$allowedFields`, `$useTimestamps = false` with explicit `created_at`, `$returnType = 'array'`). Minimal surface: `insertHandoff(array $row): int` and `forConversation(int $conversationId, int $limit = 50): array` (newest-first). No business logic in the model — eligibility, conditional write, and transaction orchestration live in the controller (consistent with existing Inbox code where `Inbox.php` owns the workflows).

### 4.3 Endpoints — Handoff

Both routes carry the existing `auth` filter, like every other `/inbox/percakapan/*` POST route.

**`POST /inbox/percakapan/(:num)/handoff` → `Inbox::handoffPercakapan/$1`**

Request (form-encoded or JSON, same dual-read pattern as `catatanInternal()`):

| Field | Required | Type | Rule |
|---|---|---|---|
| `to_user_id` | yes | int | Existing, active, Handoff-eligible staff (REQ-H03); must differ from session user (REQ-H05) |
| `summary` | yes | string | Non-empty after trim, max 500 chars (ASSUMPTION-003) |
| `next_action` | yes | string | Non-empty after trim, max 500 chars |
| `note` | no | string | Free text, may be empty |
| `expected_owner` | yes | int or null | `assigned_to` as seen by the client; null/empty means "saw unassigned" (REQ-C01) |

Server flow (order is normative):

1. Resolve session user; 404 if conversation missing.
2. Recompute eligibility via `ConversationModel::withComputedStatus()`; reject with 403 if Queue View Status is `selesai` (REQ-H02).
3. Validate payload shape (400 on violation: missing/blank `summary`/`next_action`, over-length, self-Handoff, malformed `to_user_id`).
4. Validate target eligibility via `UserModel::daftarKasirAktif()` (403 if unknown/inactive/ineligible — K-09).
5. `transBegin()` on the `inbox` group, then conditional write (`SET assigned_to = :to WHERE id = :id AND assigned_to <=> :expected`), then history insert (REQ-H07), then `transCommit()` (REQ-H09). Zero affected rows means `transRollback()` plus 409 naming the current owner (REQ-C02). History-insert failure means `transRollback()` with ownership unchanged.
6. Success response mirrors `tutupPercakapan()` (`status: success`, Indonesian message, plus the new owner id and the created history id).

### 4.4 Collision response contract (K-09)

- `400` — payload/validation failures (blank `summary`/`next_action`, over 500 chars, self-Handoff, malformed ids).
- `403` — initiator or target not eligible, or conversation in `selesai` Queue View Status.
- `404` — conversation id unknown.
- `409` — lost the conditional write. Body MUST include `current_owner_id` (nullable for safety) and, when resolvable, the winner's display name, so the loser can coordinate. `assigned_to` and history are untouched by the losing request.
- Success — HTTP 200 with the same envelope shape as `tutupPercakapan()` (`status: success`).

## 5. Acceptance Criteria

- **AC-H01:** Staff A opens an eligible conversation (any Queue View Status except `selesai`), picks staff B, fills `summary` + `next_action`, submits: `assigned_to` becomes B, exactly one `conversation_handoffs` row exists with correct `from/to/initiated_by`, and the history panel shows the new entry newest-first.
- **AC-H02:** Handoff on a `selesai` conversation is rejected (403); ownership and history unchanged.
- **AC-H03:** Handoff with blank `summary` or blank `next_action` is rejected (400); ownership and history unchanged.
- **AC-H04:** Handoff to self is rejected (400); ownership and history unchanged.
- **AC-H05:** Handoff to an unknown/inactive/ineligible user is rejected (403); ownership and history unchanged.
- **AC-H06:** Handoff of an unassigned conversation succeeds and records `from_user_id = NULL`.
- **AC-H07:** The `messages` table gains zero rows from any Handoff (success or rejection); no Gateway call is made; `last_message_*` unchanged.
- **AC-C01:** Two staff submit Handoff for the same conversation with the same `expected_owner`: exactly one succeeds (200), the other receives 409 naming the winner; only one history row exists; `assigned_to` equals the winner's target.
- **AC-C02:** A Handoff with a stale `expected_owner` (ownership changed since the dialog was opened) is rejected (409) without touching ownership or history.
- **AC-C03:** History insert failure rolls back the ownership change (`assigned_to` unchanged, no orphan row).
- **AC-R01:** Full suite `composer test` passes with zero failures (project Definition of Done).

## 6. Test Automation Strategy & Testing Seams

Per the Two-Layer Mandate, every code change ships with tests in the same increment, and the full suite must pass before the phase is declared complete.

- **Migration test:** `conversation_handoffs` table exists on the `inbox` group with the columns, defaults, index, and FK from Section 4.1; existing tables untouched.
- **Model tests (`tests/database/`):** `insertHandoff()` persists all fields incl. NULL `from_user_id`; `forConversation()` returns newest-first capped at 50; following the `ConversationModelComputedStatusTest` precedent (see Fase 1 spec Section 6).
- **Controller tests (`tests/session/`, following the `InboxInternalNoteTest` precedent):** AC-H01–H07 (success shape, 400/403/404 paths, unassigned-from-NULL, messages-table-untouched assertion), AC-C01–C03 (simulated race: second request reuses the first request's `expected_owner` and must get 409; stale-owner 409; forced history-insert failure rolls back ownership).
- **No new testing seams required:** the conditional write is verified through its observable effect (affected-rows-driven 200 vs 409), not by mocking the database layer. The "race" is simulated sequentially — open two logical reads of the same owner value, apply request 1, then apply request 2 with the stale value — which deterministically exercises the same `WHERE assigned_to <=> :expected` path a real race would take.

## 7. Project Structure & Commands

### Project Structure

New/changed files only (all paths repo-root-relative; everything else follows `docs/ARCHITECTURE.md`):

- `app/Database/Migrations/<timestamp>_CreateConversationHandoffs.php` — new table (Section 4.1).
- `app/Models/ConversationHandoffModel.php` — new model (Section 4.2).
- `app/Controllers/Inbox.php` — add `handoffPercakapan($id)` + `apiHandoffs($id)`; no changes to existing methods (REQ-H10).
- `app/Config/Routes.php` — two new routes (Section 4.3).
- `app/Views/inbox/index.php` (+ existing inbox JS) — Handoff dialog (fields per REQ-H04, `expected_owner` captured at dialog open) + history panel rendering + 409-loser notice naming the current owner.
- `tests/database/ConversationHandoffModelTest.php`, `tests/session/InboxHandoffTest.php` — Section 6.
- `docs/ARCHITECTURE.md` — update during `/sdlc-write-code` completion (Living Architecture Map Mandate): new table, model, routes.

### Commands

- `composer test` — full suite, must pass 100% (DoD, AC-R01).
- `php spark migrate --database inbox` (per the project's migration invocation convention) — apply the new additive migration; rollback path verified in tests.

## 8. Code Style & Conventions

Follows Fase 1 spec Section 8: Indonesian-language controller/response messages (GUD-H01 precedent), session-based staff identity, `auth`-filtered routes, `$DBGroup = 'inbox'` on all Inbox models, explicit `Asia/Jakarta` timestamps (CON-H06), additive migrations only (CON-H05), no new third-party libraries. Frontend work reuses the existing inbox view/JS patterns (dialog + fetch + panel render) rather than introducing a new framework or component system.

```php
// Handoff handlers live in app/Controllers/Inbox.php next to the existing
// ownership actions and follow the tutupPercakapan() response envelope:
// { status: 'success' | 'error', message: '<Indonesian sentence>' }.
public function handoffPercakapan(int $id) { /* ... */ }
public function apiHandoffs(int $id) { /* ... */ }
```

## 9. Implementation Boundaries

Guardrails for the `/sdlc-write-code` agent (three-tier system):

- **Always do:** re-validate everything server-side (CON-H04), keep the ownership write and the history insert in one `inbox`-group transaction (REQ-H09), run `composer test` before declaring the increment done (AC-R01).

- The Handoff implementation MUST NOT modify `ambilPercakapan()`, `lepasPercakapan()`, `tutupPercakapan()`, `snoozePercakapan()`, `tandaiDibaca()`, `hapusPercakapan()`, or `catatanInternal()` — it only reuses their patterns (REQ-H10).
- No changes to the `messages` or `conversations` table definitions; no changes to `ConversationModel::withComputedStatus()` semantics; no Gateway contract changes.
- Frontend scope is limited to the Handoff dialog, the history panel, and the 409 notice — no Queue View tab changes, no SLA/filter changes.
- Anything resembling presence (viewing indicators, heartbeats, "currently open by" state) is out of bounds for Fase 2a code review (REQ-C03).

## 10. Rationale, Context & Architecture Decisions (ADRs)

No new ADR is created in Fase 2a. Triple-gate check: the conditional-write primitive already exists (`ambilPercakapan()` precedent, ADR-0001 context); reusing it on one more path is reversible, unsurprising given K-01, and carries no new trade-off beyond what K-01 already decided. The narrow M2 gate itself (K-01) remains documented in `docs/audit/clarification-report-m3-fase2-m2-gate-2026-09-22.md`. If a later increment generalizes conditional writes to all ownership paths (full M2 program), that increment SHOULD record an ADR.

## 11. Dependencies & External Integrations

### External Systems

- WhatsApp Gateway (`tikusgot007/WA-Gateway`): **none** — Handoff never calls the Gateway (CON-H01).
- POS database (`aulia_kasirdb`): read-only user lookup via the existing `UserModel` path; zero writes (CON-H02).

### Infrastructure Dependencies

- MySQL NULL-safe equality (`<=>`) for the conditional write; InnoDB transactions on the `inbox` group for REQ-H09. Both already assumed by the existing codebase.

### Data Dependencies

- `ConversationModel::withComputedStatus()` (eligibility single source, REQ-H02).
- `UserModel::daftarKasirAktif()` (target eligibility, REQ-H03).
- Session authentication + `auth` filter (actor identity, route protection).

## 12. Examples & Edge Cases

**Happy path:** Conversation #123 (`assigned_to = 7`, Queue View Status `open`). Staff 7 opens the Handoff dialog (client records `expected_owner = 7`), selects staff 9, fills summary "Customer asked about bulk pricing; quoted price list v3." and next action "Follow up tomorrow morning if no reply.", submits. Server: eligibility OK → payload OK → target 9 eligible → transaction: conditional write matches (1 row) → history row (`from 7`, `to 9`, `initiated_by 7`) → commit. Response 200; Queue View now shows #123 under staff 9; history panel lists the entry.

**Collision:** Staff 7 and staff 9 both open the dialog while `assigned_to = 7` (both hold `expected_owner = 7`). Staff 7 hands off to 11 (wins, 200). Staff 9's request to hand off to 12 now finds `assigned_to = 11 ≠ 7`: 0 affected rows → rollback → 409 naming staff 11. Staff 9 refreshes, sees the new owner, coordinates with staff 11.

**Edge cases:**

- Unassigned conversation (`assigned_to IS NULL`, status `belum_diambil`): Handoff allowed; `expected_owner` null matches via `<=>`; history records `from_user_id = NULL` (AC-H06).
- `selesai` conversation: rejected even if the dialog was somehow opened (server recomputes; 403).
- Empty `note`: accepted (optional). Blank `summary`/`next_action` (whitespace only): 400.
- `to_user_id` equals initiator: 400 even if the initiator is not the current owner.
- Initiator is not the current owner (e.g. staff A hands off B's conversation to C): allowed — `from_user_id = B`, `initiated_by_user_id = A` (K-06). No ownership-gate on the initiator beyond authentication (REQ-H01).
- Unknown conversation id: 404 before any validation.
- Concurrent non-Handoff write (e.g. someone claims via `ambilPercakapan()` between dialog open and submit): same 409 path — the conditional write only cares that `assigned_to` moved, not which path moved it.
- History panel pagination: capped at 50 newest; older entries remain in the table (no purge in Fase 2a).

## 13. Validation Criteria

1. Every REQ/CON ID in Section 3 traces to at least one AC in Section 5 and one test bullet in Section 6 (reviewer checks the matrix during `/sdlc-audit-consistency`).
2. No requirement contradicts K-01–K-09; any deviation from the clarification report is flagged as a finding, not silently absorbed.
3. `composer test` passes 100% after implementation (AC-R01).
4. Markdownlint passes on this file (project AGENTS.md mandate; single pre-existing MD025 note: the `# Introduction` + `## 1.` pattern is inherited verbatim from the approved Fase 1 spec, so both spec files report the identical MD025/single-title finding — no new lint regression introduced here).
5. Readiness self-check against the Clarification scoring rubric: Completeness (all Handoff + Collision behaviours, payloads, codes, edge cases specified), Clarity (implementable without guessing — table DDL, endpoint flow order, response codes all explicit), Alignment (100% traceable to PRD v1.1 GH-006/GH-007 and K-01–K-09; no orphaned items — auto-assignment, presence, notifications explicitly excluded).

## 14. Related Specifications / Further Reading

- `prd-20260922-0141-chat-whatsapp-inbox.md` v1.1 (GH-006 Handoff, GH-007 Collision Detection; Deferred: presence, notifications/unread, auto-assignment Fase 2b).
- `docs/audit/clarification-report-m3-fase2-m2-gate-2026-09-22.md` (K-01–K-09 — the normative source for every Decision Log entry in Section 1.2.1).
- `spec/spec-design-m3-operational-inbox-fase1.md` (foundation: Queue View, computed status, Internal Note, Section 6/8 conventions reused here).
- `docs/adr/0001-reuse-response-state-for-queue-view-status.md` (why Queue View Status is computed, not a DB column — background for REQ-H02).
- `docs/ARCHITECTURE.md`, `CONTEXT.md` (glossary source for Section 2).
- `blueprint-m3-operational-inbox.md` (roadmap context; Fase 2b auto-assignment lives there, not here).

## 15. PRD Traceability

| PRD item (v1.1) | Spec coverage |
|---|---|
| GH-006 Handoff antar staff (summary + next action wajib, riwayat permanen) | REQ-H01–H09, Sections 4.1–4.3, AC-H01–H07 |
| GH-007 Collision Detection (penolakan + info pemilik sah, tanpa presence) | REQ-C01–C04, Section 4.4, AC-C01–C03 |
| Deferred: presence (prasyarat bernama K-04) | REQ-C03, Out of Scope, Section 9 boundary |
| Deferred: notifikasi + unread per-user (K-08) | CON-H03, Out of Scope |
| Fase 2b: auto-assignment GH-008 (K-03) | Out of Scope |
| DoD: `composer test` 100% | AC-R01, Section 6 |
