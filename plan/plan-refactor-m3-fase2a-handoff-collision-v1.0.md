---
goal: M3 Fase 2a (Handoff + Collision Detection) — Code Review Remediation
version: 1.1
date_created: 2026-09-23
last_updated: 2026-09-24
owner: AuliaPos Inbox module
status: "Completed"
tags: ["refactor", "clean-code", "architecture", "security"]
---

# Introduction

![Status: Completed](https://img.shields.io/badge/status-Completed-brightgreen)

> [!NOTE]
> **Revision 1.1 (2026-09-24), per `docs/audit/consistency-audit-m3-fase1-operational-inbox-2026-09-24.md` (ST-02):**
> status sync only, no task or scope change. Status `Planned` → `Completed`. TASK-101..110 and TASK-201..205 are ticked
> with evidence from the code (line numbers as of `v2.3` on 2026-09-24), the commits `3136792`..`51fb1fc` and the
> memory checkpoint "2026-09-23 Write-Code: Phase 1 + Phase 2". TASK-203 stays VOID. All commits are in `v2.3` via
> PR #41. Full suite re-run on 2026-09-24: `vendor/bin/phpunit --no-coverage` OK, 317 tests / 1061 assertions.
> The line numbers inside the task descriptions are from before the change and are kept as written.

This plan remediates the findings of `docs/audit/code-review-m3-fase2a-2026-09-23.md` over the M3 Fase 2a
Handoff + Collision Detection implementation (`600515c~1..HEAD` on `feature/m3-operational-inbox-fase1a-task001`).

The review found **0 Blocker and 0 Critical** issues: ownership atomicity is real, identity comes only from the
session, every query is parameter-bound, and the 7 protected controller methods are byte-identical
(`Inbox.php` 312 insertions / 0 deletions). The remaining work is deliberate hardening:

- one input-validation gap that lets a non-string payload reach a required free-text column (`CR-01`),
- one locked contract with no test locking it (`CR-02`, mutation-insensitive),
- boundary/envelope/coverage nits (`CR-05`, `CR-06`, `CR-07`, `CR-08`, `CR-09`),
- and two semantics questions that were answered by `/sdlc-clarify-reqs` on 2026-09-23: `CR-03` = **Option A**
  (the initiator gate is narrowed to `queue_status === 'belum_diambil'`) and `CR-04` = **Option A1**
  (a fail-fast 409 is adopted immediately before the transaction).

The five amendments locked by that clarification session
(`docs/audit/clarification-report-m3-fase2a-refactor-plan-2026-09-23.md`, Readiness 86/100) are applied in place in
Phase 2 below, with no other requirement changed.

Normative upstream documents: `plan/plan-feature-m3-operational-inbox-fase2a-v1.0.md` v1.0 (wins on conflict,
RISK-01), `docs/audit/clarification-report-m3-fase2a-plan-2026-09-23.md` (Q1–Q9) and
`docs/audit/clarification-report-m3-fase2a-refactor-plan-2026-09-23.md` (CR-03/CR-04 resolutions).

## 1. Traceability: Requirements & Constraints

- **REQ-001 (CR-01):** reject non-string `summary` / `next_action` / `note` with HTTP 400 before coercion, so no
  `"Array"` string can be stored in the handoff history.
- **REQ-002 (CR-02):** lock the Q5 contract "absent `expected_owner` field = 400" with a dedicated test.
- **REQ-003 (CR-05):** measure the 4096 cap in characters (`mb_strlen`), matching `VARCHAR(4096)` and `maxlength`.
- **REQ-004 (CR-06):** make the two 409 families of `POST .../handoff` consistent, or explicitly documented.
- **REQ-005 (CR-07):** lock the remaining micro-contracts with tests (malformed `to_user_id`, oversized `note`,
  JSON dual-read path, `auth` filter on the POST route, deterministic gate order on double violations,
  409 body key set, `Asia/Jakarta` timestamp).
- **REQ-006 (CR-08):** name `UserModel::daftarKasirAktif()` in the Living Architecture Map.
- **REQ-007 (CR-03, LOCKED 2026-09-23 = Option A):** align the `belum_diambil` initiator exception with
  `queue_status === 'belum_diambil'` instead of `assigned_to IS NULL`; the "document the widening" alternative
  (Option B) was REJECTED, so TASK-203 is VOID.
- **REQ-008 (CR-04, LOCKED 2026-09-23 = Option A1):** remove the client `expected_owner` vs server-read `assigned_to`
  mismatch (fail-fast 409, placed after both 403 gates) so `from_user_id` can never be recorded from a stale read.
- **PRN-001:** keep PSR-12 style discipline in `app/Controllers/Inbox.php` (single blank line between class members).
- **PRN-002:** if `handoffPercakapan()` is decomposed, extract private helpers with identical behaviour — never a rewrite.
- **SEC-001:** enforce every input boundary server-side on typed, character-measured values.
- **CON-001:** do NOT modify `ambilPercakapan()`, `lepasPercakapan()`, `tutupPercakapan()`, `snoozePercakapan()`,
  `tandaiDibaca()`, `hapusPercakapan()`, `catatanInternal()`; no ALTER on `messages`/`conversations`; no Gateway call;
  no presence/unread/notification (REQ-H10, CON-H01, CON-H03, CON-H05).
- **CON-002:** the normative gate order stays deterministic: 404 → 409 `selesai` → 400 validation → 403 initiator →
  403 target → conditional-write transaction (Q3).
- **CON-003:** the Plan wins over stale Spec v1.0 text (RISK-01); any semantic change needs `/sdlc-clarify-reqs`,
  never a silent code change.
- **CON-004:** the full suite must stay green via `vendor/bin/phpunit --no-coverage` with **no decrease** from the
  review baseline of 283 tests / 867 assertions, and no suppression/skip may be added (Floor-Guard).

## 2. Implementation Steps

> **⚠️ EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> Execute phase by phase. Run the VERIFY task at the end of each phase, then **STOP AND WAIT** for explicit user
> approval before starting the next phase. DO NOT SKIP PHASES. Every task ships its tests in the same increment
> (Two-Layer Mandate). Never add `@group`, skips, or deleted assertions to force green (Floor-Guard).
>
> Mandatory upstream attachments for the executing session:
> `@plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md`, `@docs/audit/code-review-m3-fase2a-2026-09-23.md`,
> `@plan/plan-feature-m3-operational-inbox-fase2a-v1.0.md`.

### Implementation Phase 1: Unconditional Hardening & Test Coverage

- **GOAL-001:** close every finding that does not change product semantics (CR-01, CR-02, CR-05, CR-06, CR-07,
  CR-08, CR-09) while keeping the gate order (Q3) and the 7 protected methods untouched.

| Task ID | Description (Include Exact File Paths & Micro-Testing) | Ref ID | Completed | Date |
| --- | --- | --- | --- | --- |
| TASK-101 | `app/Controllers/Inbox.php` (1009-1014): ganti coercion `(string)` pada `summary`/`next_action`/`note` dengan guard `is_string()` → 400 sebelum `trim()`; cek hilir tidak berubah. Test `E09` di `tests/session/InboxHandoffTest.php`: `summary` bertipe array → 400, ownership tetap 7, riwayat nol. | REQ-001, SEC-001 | ✅ `is_string()` guard before `trim()`; test E09. Commit `3136792`. Verified 2026-09-24. | 2026-09-23 |
| TASK-102 | `tests/session/InboxHandoffTest.php`: test id `E10` (canonical; `E09` = TASK-101) — `unset($payload['expected_owner'])` → 400 with message `'Field expected_owner wajib dikirim.'`, ownership/history untouched; plus a negative control (same payload WITH the field → 200). The review's testE09ExpectedOwnerAbsenDitolak400 is a label only, not the id (CL-05). | REQ-002 | ✅ Test E10 (absent `expected_owner` → 400 `'Field expected_owner wajib dikirim.'` + negative control 200); message at `Inbox.php:1126`. Commit `dc14a83`. | 2026-09-23 |
| TASK-103 | `app/Controllers/Inbox.php` (lines 1028-1034): swap the three `strlen()` calls for `mb_strlen()` (verified available: `function_exists('mb_strlen') === true`). Tests: `E11` — 4096 multi-byte characters (`str_repeat('é', 4096)`) → 200 and the row stores the full text; `E12` — 4097 → 400. | REQ-003 | ✅ `mb_strlen()` on `summary`/`next_action`/`note` (`Inbox.php:1091-1092`); tests E11/E12. Commit `77d822e`. | 2026-09-23 |
| TASK-104 | `app/Controllers/Inbox.php` (lines 993-998): add `'current_owner_id' => $assignedTo` to the `selesai` 409 body (nullable). Test: one single id `H02b` (do NOT extend `H02` — keep one contract per test) asserting `current_owner_id` equals the current owner and that ownership/history are unchanged. | REQ-004 | ✅ `current_owner_id` in the `selesai` 409 body (`Inbox.php:1040`); test H02b. Commit `2b4b3f6`. | 2026-09-23 |
| TASK-105 | `tests/session/InboxHandoffTest.php`: enam test mikro-kontrak — (a) `to_user_id` non-numerik/`'0'` → 400; (b) `note` 4097 → 400; (c) JSON body → 200 (DEP-06); (d) POST tanpa session → redirect `/login` (cermin `G04`); (e) unknown id + payload invalid → 404, lalu non-assignee + target invalid → 403 inisiator; (f) body 409 tepat 3 key. | REQ-005 | ✅ Tests E13–E18 (a)–(f); E15 JSON body passed as-is, so RISK-005 did not trigger. Commit `d456da6`. | 2026-09-23 |
| TASK-106 | `docs/ARCHITECTURE.md` (§4.2 model list and §13 file table): name `UserModel::daftarKasirAktif()` as the single source of the active-kasir list used by the Handoff dialog, the 409 naming and the `belum_diambil` initiator check. Documentation only, no code. | REQ-006 | ✅ `docs/ARCHITECTURE.md` names `UserModel::daftarKasirAktif()` as the single active-kasir source (§4.2 and the file table). Commit `bb2e0b5`. | 2026-09-23 |
| TASK-107 | `app/Controllers/Inbox.php` (line 1215): insert one blank line between the closing brace of `handoffPercakapan()` and the docblock of `apiHandoffs()`, matching the file convention. | PRN-001 | ✅ Blank line between `handoffPercakapan()` and the `apiHandoffs()` docblock. Commit `f2aab5e`. | 2026-09-23 |
| TASK-108 | `app/Controllers/Inbox.php`: ekstrak blok jalan kalah 409 (`:1148-1159`: resolusi nama pemilik + payload) ke private helper ber-signature TERKUNCI — lihat **TASK-108 detail block** di bawah tabel Phase 1. Perilaku harus sama: `C01`, `C01b`, `C02`, `C04`, `E04`, `E06`, `H01`, `H06`, `H08` tetap hijau. Jangan ekstrak hal lain. | PRN-002, CON-002 | ✅ `private function balas409KepemilikanBasi(?int $currentOwnerId)` (`Inbox.php:1300`), no ownership re-read inside. Commit `ba31d9e`. | 2026-09-23 |
| TASK-109 | **VERIFY**: `cmd /c 'vendor\bin\phpunit --no-coverage > build\phase1.txt 2>&1'` → exit 0, test >= 283 + baru, assertion >= 867 + baru, nol skip; audit batas ulang (`--numstat`: `Inbox.php` 0 deletions di luar baris yang diubah; nol diff model/SLA/Gateway; tanpa ALTER tabel lama). | CON-004, CON-001 | ✅ `vendor/bin/phpunit --no-coverage` OK 294 tests / 930 assertions (baseline 283/867), zero skips; the 7 protected methods have no diff; no ALTER on old tables (memory checkpoint 2026-09-23 Write-Code). | 2026-09-23 |
| TASK-110 | **APPROVAL**: 🛑 Report the VERIFY evidence and wait for explicit user confirmation before Phase 2. | - | ✅ Approved by the user ("saya setujui") before Phase 2 started (memory checkpoint 2026-09-23 Write-Code). | 2026-09-23 |

**TASK-108 detail — locked helper signature (CL-03).**
Files: `app/Controllers/Inbox.php` (extraction) and `tests/session/InboxHandoffTest.php` (regression).

Target signature: `private function balas409KepemilikanBasi(?int $currentOwnerId)`. The helper RECEIVES the owner id and
does NOT re-read ownership — no `ConversationModel::find()` inside it. It only resolves the display name via `UserModel`
(with the `User #{id}` fallback) and builds the payload, so `status`, `message` and `current_owner_id` stay
byte-identical to today's loser 409.

- **Caller 1 (race path):** keeps its own `find()` at `:1144-1147`, then passes the id it read there.
- **Caller 2 (fail-fast path, TASK-201):** passes `$assignedTo` — the same value a re-fetch would return on a mismatch,
  with zero extra queries.

Two callers are the reason this extraction still belongs in Phase 1.

### Implementation Phase 2: Clarification-Gated Semantics (CR-03 = Option A and CR-04 = Option A1 locked 2026-09-23; executable after Phase 1 approval)

- **GOAL-002:** apply the two clarified semantics (narrowed initiator gate + fail-fast 409) exactly as locked by
  `docs/audit/clarification-report-m3-fase2a-refactor-plan-2026-09-23.md`, without breaking any locked test.
- **Q2 narrowing note (locked):** the Spec finalisation MUST restate Q2
  (`docs/audit/clarification-report-m3-fase2a-plan-2026-09-23.md:39`, "an admin MAY initiate ... on `belum_diambil`")
  as a deliberate narrowing by the Plan: an admin stays 403 on `belum_diambil` (Plan wins, RISK-01/CON-003). This item
  changes zero code, zero test and zero UI — it is a text obligation on the Spec.

| Task ID | Description (Include Exact File Paths & Micro-Testing) | Ref ID | Completed | Date |
| --- | --- | --- | --- | --- |
| TASK-201 | Clarification locked (CR-04 = A1): `app/Controllers/Inbox.php` — fail-fast `409` on `expected_owner` != server-read `assigned_to`, placed immediately before `$db->transBegin()` and AFTER both 403 gates. Full detail below (**TASK-201 / TASK-202 detail block**). Reuse `balas409KepemilikanBasi($assignedTo)` (TASK-108). | REQ-008, CON-003 | ✅ Fail-fast 409 after both 403 gates and right before `$db->transBegin()` (`Inbox.php:1210`), via `balas409KepemilikanBasi($assignedTo)`; test F01 (no write). Commit `51fb1fc`. Verified 2026-09-24. | 2026-09-23 |
| TASK-202 | Option A for CR-03 (**LOCKED**; TASK-203 is VOID): narrow the initiator gate to `queue_status === 'belum_diambil'` instead of `assigned_to IS NULL`, server (`Inbox.php:1092-1094`) plus UI mirror (`index.php:859-862`). Exact expressions, 403 branches and the three tests: see the **TASK-201 / TASK-202 detail block**. Ship server + UI in one commit (RISK-004). | REQ-007 | ✅ Gate on `$computed['queue_status'] === 'belum_diambil'` (`Inbox.php:1158`), three 403 messages (`Inbox.php:1165-1169`), UI mirror (`app/Views/inbox/index.php:1095`), server + UI in one commit; tests F02, F03, F04. Commit `51fb1fc`. Verified 2026-09-24. | 2026-09-23 |
| TASK-203 | **VOID / SUPERSEDED** (2026-09-23): Option B was REJECTED; the gate is narrowed by TASK-202 per CR-03 = Option A — **DO NOT EXECUTE**. No Spec text change and no behaviour-documentation test is needed for CR-03, because A1 restores fidelity to REQ-H01. The row is kept for traceability only. | REQ-007, CON-003 | VOID (superseded) | 2026-09-23 |
| TASK-204 | **VERIFY**: full suite green (`vendor/bin/phpunit --no-coverage`), gate-order assertions (`H02`, `E03`, `E05`, `H08`) unchanged, `Inbox.php` still 0 deletions on the 7 protected methods. | CON-004, CON-002 | ✅ `vendor/bin/phpunit --no-coverage` OK 298 tests / 948 assertions, zero skips; H02, E03, E05, H08 unchanged; the 7 protected methods have no diff (memory checkpoint 2026-09-23 Write-Code). Re-run 2026-09-24 on `v2.3`: OK 317 tests / 1061 assertions. | 2026-09-23 |
| TASK-205 | **APPROVAL**: 🛑 Wait for explicit user confirmation, then hand off to Spec finalisation / `/sdlc-generate-docs`. | - | ✅ Implied: the next step it names, Spec finalisation, was done (commit `7aa8c2b`, Spec v1.1), and the code is in `v2.3` via PR #41. No separate written record of this approval was found. | 2026-09-23 |

**TASK-201 / TASK-202 detail — locked by the CR-04 = A1 and CR-03 = A clarifications (2026-09-23).**
Touched files: `app/Controllers/Inbox.php` (fail-fast insertion point + initiator gate 1092-1094 + the 403 message),
`app/Views/inbox/index.php` (859-862), `tests/session/InboxHandoffTest.php`.

**TASK-201 — fail-fast 409 placement (normative).** Insert immediately before `$db->transBegin()`, i.e. AFTER the 403
initiator gate (`Inbox.php:1092-1102`) and the 403 target gate (`:1104-1111`). The clarification report cites `:1120`
(the start of the six-line comment block); the call itself is `Inbox.php:1126`. Reason: installed before the gates, a
non-assignee answer would flip 403 → 409 and leak the owner name, because the 409 `message` names the current owner
while the 403 branches name nobody — a violation of locked Q3. Regression set that MUST stay green: `C01`, `C01b`,
`C02`, `C04`, `E04`, `E06`, `H01`, `H06`, `H08`, plus `TEST-006` (no ownership write on the fail-fast path).

**TASK-202 — narrowed initiator gate.** Server (`Inbox.php:1092-1094`) becomes:
`$assignedTo !== null ? ($assignedTo === $userId) : (($computed['queue_status'] ?? null) === 'belum_diambil' && in_array($userId, $idKasirAktif, true))`,
reusing `$computed` already available at `Inbox.php:992` (DEP-01 single source of truth, no extra query).
UI mirror (`app/Views/inbox/index.php:859-862`) unowned branch becomes:
`(!conv.assigned_to && conv.queue_status === 'belum_diambil' && currentUserRole === 'kasir')`; the assignee branch
(`String(conv.assigned_to) === String(currentUserId)`) stays unchanged.

**(a) The 403 message has THREE branches** (the branch follows the server state, never the client claim):

- **(i)** conversation already owned, initiator != owner — wording MUST stay verbatim:
  `'Hanya staff yang sedang menangani percakapan ini yang bisa menyerahkannya.'`
  (locked by assertion `E04(c)` at `tests/session/InboxHandoffTest.php:762`).
- **(ii)** unowned + `queue_status === 'belum_diambil'` — wording MUST stay verbatim:
  `'Hanya kasir aktif yang bisa menyerahkan percakapan yang belum diambil.'`
  (locked by assertion `E04(b)` at `tests/session/InboxHandoffTest.php:750`).
- **(iii)** unowned + tab other than `belum_diambil` (new branch, no test exists yet) — uses its own message, e.g.
  `'Percakapan tanpa pemilik hanya bisa diserahkan dari tab Belum Diambil. Ambil dulu percakapan ini.'`.
  Without branch (iii) an active kasir would read wording (ii) and be misled, which would itself become the next
  audit finding.

**(b) Three tests to add** (none of them edits an existing assertion):

1. `assigned_to NULL` + active `snoozed_until` (tab `ditunda`) + non-assignee kasir + `expected_owner = ''`
   → 403, ownership stays `NULL`, zero history rows.
2. `assigned_to NULL` + `last_seen_by_assignee_at >= last_message_at` (tab `menunggu`) + non-assignee kasir
   → 403 + zero history rows.
3. Positive control: after the same kasir claims the conversation via `ambilPercakapan()`, the Handoff → 200,
   proving the narrowing leaves no dead end.

**(c) Compatibility with TASK-201:** test (1) sends `expected_owner = ''` while the server read is `NULL`, so the
fail-fast equality check never fires and the answer stays 403. The two decisions are independent because the
fail-fast sits after this gate.

## 3. Structural Remedies & Alternatives

- **ALT-001:** Keep the `(string)` coercion and rely on the `VARCHAR(4096)` column to "absorb" bad input — DITOLAK:
  the DB accepts `"Array"` happily, so the required free-text contract stays broken and the audit trail is polluted.
- **ALT-002:** Rewrite `handoffPercakapan()` as a dedicated service class in one go — DITOLAK: violates the Surgical Edit
  Mandate and CON-002 (gate order is normative); TASK-108 extracts one helper only, with identical behaviour.
- **ALT-003:** Move the 4096 enforcement to the schema (or drop the controller check) — DITOLAK: P-02 requires a server-side
  400, and a schema error would surface as 500.
- **ALT-004:** Make the conditional write use the server-read `assigned_to` instead of the client `expected_owner` —
  DITOLAK untuk Fase 2a: nilai yang dibaca saat dialog dibuka adalah kontrak Collision Detection (Q5/P-05). TASK-201
  hanya menambahkan *pre-check* kesetaraan supaya write yang mustahil tidak pernah dijalankan.
- **ALT-005:** Tambahkan field `queue_status` (atau seluruh baris conversation) pada body 409 — DITOLAK: contract creep,
  dan mempermudah kebocoran data di luar pemilik sah + `current_owner_id`.
- **ALT-006:** Memindahkan validasi ke layer Form Request/validator baru — DITOLAK: bukan konvensi codebase ini
  (Spec §4.2/§8 menempatkan orchestration + validasi di controller, seperti 7 method lama).

## 4. Dependencies

- **DEP-001:** Ekstensi `mbstring` — sudah ada di lingkungan (diverifikasi `function_exists('mb_strlen') === true`);
  tanpa perubahan `composer.json`.
- **DEP-002:** Harness trigger MariaDB dari test `C03` (`BEFORE INSERT ... SIGNAL SQLSTATE '45000'`) — dipakai ulang bila
  diperlukan; tidak ada dependency baru.
- **DEP-003:** Tidak ada library pihak ketiga baru dan tidak ada migrasi/schema change.

## 5. Files Affected

- **FILE-001:** `app/Controllers/Inbox.php` — TASK-101 (guard tipe), TASK-103 (`mb_strlen`), TASK-104 (`current_owner_id`),
  TASK-107 (baris kosong), TASK-108 (helper 409), TASK-201 (fail-fast tepat sebelum `transBegin()`), TASK-202 (gerbang
  inisiator; locked).
- **FILE-002:** `tests/session/InboxHandoffTest.php` — TASK-101..TASK-105, ditambah TASK-201 dan TASK-202 (TASK-203 VOID).
- **FILE-003:** `docs/ARCHITECTURE.md` — TASK-106 (menamai `UserModel::daftarKasirAktif()`).
- **FILE-004:** `app/Views/inbox/index.php` — TASK-202 (Option A, LOCKED): mirror gerbang `belum_diambil` di baris 859-862.
- **FILE-005:** `spec/spec-design-m3-operational-inbox-fase2a-handoff-collision.md` — TIDAK lagi tersentuh plan ini
  (TASK-203 VOID); finalisasi Spec berjalan lewat jalur Spec, bukan lewat task di sini.

## 6. Testing Strategy

- **TEST-001 (REQ-001):** `E09` — payload dengan `summary` bertipe array → 400, tanpa perubahan ownership/riwayat. [TASK-101]
- **TEST-002 (REQ-002):** `E10` — field `expected_owner` absen → 400 bernama, plus kontrol negatif payload lengkap → 200. [TASK-102]
- **TEST-003 (REQ-003):** `E11`/`E12` — 4096 karakter multi-byte → 200 dan tersimpan utuh; 4097 karakter → 400. [TASK-103]
- **TEST-004 (REQ-004):** `H02b` — 409 `selesai` menyertakan `current_owner_id` yang benar; ownership/riwayat tetap. [TASK-104]
- **TEST-005 (REQ-005):** enam test mikro-kontrak pada TASK-105 butir (a)-(f). [TASK-105]
- **TEST-006 (REQ-008, Fase 2):** jalur fail-fast 409 tidak menjalankan write (ownership/`updated_at` tidak berubah);
  regresi `C01`, `C01b`, `C02`, `C04`, `E04`, `E06`, `H01`, `H06`, `H08` tetap hijau. [TASK-201]
- **TEST-007 (REQ-007, Fase 2, Option A locked):** tiga test — (1) `assigned_to NULL` + snooze aktif (tab `ditunda`) +
  non-assignee kasir + `expected_owner = ''` → 403, ownership tetap `NULL`, riwayat 0; (2) `assigned_to NULL` +
  `last_seen_by_assignee_at >= last_message_at` (tab `menunggu`) + non-assignee kasir → 403 + riwayat 0; (3) kontrol
  positif: setelah `ambilPercakapan()`, Handoff kasir yang sama → 200. Pesan cabang (i)/(ii) verbatim (locked
  `E04(b)`:750 dan `E04(c)`:762); cabang (iii) memakai pesan baru. [TASK-202]
- **TEST-008 (CON-001/CON-002):** audit batas — `git diff --numstat` (`Inbox.php` 0 deletions di luar baris yang diubah),
  nol diff `ConversationModel.php`/`InboxSlaService.php`/`InboxGatewayApi.php`, tidak ada ALTER `messages`/`conversations`,
  urutan gerbang `H02`/`E03`/`E05`/`H08` tetap. [TASK-109 / TASK-204]
- **TEST-009 (Macro Gate, AC-R01):** `vendor/bin/phpunit --no-coverage` hijau di akhir tiap fase, tanpa skip/suppression,
  jumlah test/assertion tidak turun dari 283/867. [TASK-109 / TASK-204]

## 7. Risks & Rollback Plan

- **RISK-001 (REQ-003, `mb_strlen`):** batas menjadi lebih longgar — string 4096 karakter multi-byte kini diterima.
  Aman terhadap schema (`VARCHAR(4096)` utf8mb4 = maks 16 KB/kolom; dua kolom + kolom lain masih jauh di bawah batas
  baris 64 KB) dan sudah dikunci oleh `E11`. Mitigasi: `E11` menyimpan dan membaca ulang teks penuh.
- **RISK-002 (REQ-008, fail-fast 409):** jalur kalah kini menjawab sebelum transaksi dijalankan. Mitigasi: status HTTP
  identik dan body byte-identical ke 409 loser; `C01`, `C01b`, `C02`, `C04`, `E04`, `E06`, `H01`, `H06`, `H08` wajib
  tetap hijau, ditambah assertion "tidak ada write" (TEST-006). Pemasangan WAJIB setelah kedua gerbang 403
  (`:1092-1102` dan `:1104-1111`): bila dipasang sebelumnya, non-assignee berubah 403 → 409 dan nama pemilik bocor
  (melanggar Q3).
- **RISK-003 (TASK-108, ekstraksi helper):** refactor pada method baru bisa mengubah perilaku bila tidak murni.
  Mitigasi: helper terkunci `private function balas409KepemilikanBasi(?int $currentOwnerId)` — menerima id dari pemanggil
  dan TIDAK melakukan query ulang kepemilikan; `find()` (`:1144-1147`) tetap di sisi pemanggil jalur race dan jalur
  fail-fast mengirim `$assignedTo`; payload tiga key + fallback `User #{id}` identik, tanpa mengubah urutan/teks pesan,
  diverifikasi suite penuh.
- **RISK-004 (TASK-202, gerbang inisiator):** mempersempit izin yang saat ini berlaku. Klarifikasi 2026-09-23 sudah
  mengunci Option A (CR-03), sehingga mitigasinya kini: server gate dan UI mirror (`index.php` 859-862) diubah di commit
  yang sama agar UI tidak menawarkan aksi yang pasti 403, dan tiga test pada detail TASK-202 wajib ikut hijau.
- **RISK-005 (TASK-105 butir c, JSON dual-read):** bila CI4 ternyata sudah mengisi `getPost()` dari body JSON, jangan
  menambah seam/mengubah kode — kunci perilaku apa adanya dengan test.
- **Rollback umum:** satu task = satu commit; `git revert` per commit; tidak ada migrasi, tidak ada perubahan data,
  tidak ada panggilan Gateway, sehingga rollback tidak berisiko merusak data Inbox yang sudah ada.
  Kolom `Completed`/`Date` pada `plan/plan-feature-m3-operational-inbox-fase2a-v1.0.md` tetap di luar lingkup plan ini.

---

> **Handoff:** Phase 1 (TASK-101..TASK-110) boleh dimulai sekarang lewat `/sdlc-write-code` di sesi baru. Phase 2
> (TASK-201..TASK-205) **tidak lagi menunggu klarifikasi** — CR-03 = Option A dan CR-04 = Option A1 sudah dikunci
> 2026-09-23 — sehingga boleh dieksekusi setelah Phase 1 disetujui. Implementasi wajib lewat `/sdlc-write-code`;
> reviewer/planner tidak menulis source code.
