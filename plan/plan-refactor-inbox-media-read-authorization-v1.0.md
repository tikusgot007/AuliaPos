---
goal: Open GET /inbox/media/(:num) to all logged-in staff per spec-design-inbox-read-authorization.md (ALT-003/AUTHZ-02)
version: 1.1
date_created: 2026-09-27
last_updated: 2026-09-27
owner: AuliaPos Inbox module
status: 'Completed'
tags: [inbox, chat, whatsapp, authz, security, alt-003, authz-02, refactor]
---

# Introduction

![Status: Planned](https://img.shields.io/badge/status-Planned-yellow)

This plan implements `spec/spec-design-inbox-read-authorization.md` (v1.1). It removes the ownership guard on `Inbox::media()` so every logged-in staff member can read media attachments regardless of who holds the conversation, while `GET /inbox/api/conversations/(:num)/messages` remains untouched (already gate-free, confirmed SEC-001 in the spec). Write operations elsewhere are not touched. This work **replaces** `SEC-002`/`TASK-103` from `plan/plan-refactor-balas-pesan-tahap3-review2-v1.0.md:50`, which introduced the guard now being reversed.

> [!NOTE]
> **Revision note v1.1 (2026-09-27):** amended per `docs/audit/clarification-report-inbox-media-read-authorization-plan-2026-09-27.md` (Readiness Score 91/100, PROCEED). Six execution-detail ambiguities were resolved and folded into TASK-001, TASK-002, TASK-003, TASK-004, and TASK-007 below — no requirement, phase structure, or Dep graph changed:
>
> 1. **TASK-001:** the inline comment update is split into two separate comments (a new C-1 comment before the retained conversation lookup, a new REQ-002 comment where the deleted guard used to be), not one comment edited in place.
> 2. **TASK-003:** `InboxMediaAuthSpy` gains a public mutable `$gatewayResponse` property (backward-compatible default) so the new AC-009/C-2 test can actually force a `410` Gateway response.
> 3. **TASK-002:** the rewrite now also fixes the stale class docblock (`:12-18`) and the stale assertion message (`:97`), so no comment describing the old 403-behavior survives the rewrite.
> 4. **TASK-007:** documentation lands in a new named `### Media Read Authorization` sub-section in Section 8 (parallel to `### Handoff and Collision Detection`), not mixed into the unrelated Balas Pesan sub-section.
> 5. **TASK-004:** a new `seedMessage()` helper is added to `InboxHandoffTest.php` first, since no existing helper can seed a `messages` row.
> 6. **TASK-003:** the AC-009/C-2 test adds a third assertion (`gatewayMediaDownloadCalls === 1`) to actually prove the Gateway path executed, not just its end result.

## 1. Requirements & Constraints

- **REQ-001**: All logged-in staff (role `admin` or `kasir`) may read every conversation without `assigned_to` restriction — list, thread, media, Handoff history, Gateway status, reply-needed count. (Spec REQ-001; already true everywhere except `media()`.)
- **REQ-002**: `GET /inbox/media/(:num)` must serve media to any logged-in staff regardless of the owning conversation's `assigned_to`. Remove **only** the `cekOwnership()` block (`app/Controllers/Inbox.php:425-431`). Keep the conversation lookup (`:416-423`) and its `404` intact — it is what produces `404` when the owning conversation does not exist (AC-007 depends on it).
- **REQ-002-C2**: The `410` path's write of `media_confirmed_gone_at` (`app/Controllers/Inbox.php:531-535`) may be triggered by any logged-in staff, not just the holder — it records an objective fact (WhatsApp confirmed the media gone) and only prevents repeated Gateway calls; it is not a Write Operation requiring ownership (spec Section 2, "Penanda Objektif").
- **REQ-003 (unchanged)**: All write operations that change conversation state remain gated by `cekOwnership()` or `cekBukanGrup()` exactly as today. No write endpoint is touched by this plan.
- **SEC-001 (unchanged, verified)**: `GET /inbox/api/conversations/(:num)/messages` (`Inbox::apiMessages()`, `app/Controllers/Inbox.php:303`) must **never** gain a `cekOwnership()` guard. This plan adds a regression anchor test proving it stays `200` for a non-holder, so a future session cannot silently reintroduce a guard there.
- **CON-001**: No schema/migration/column change. This is purely an endpoint-permission change.
- **CON-002**: Group conversation rules are untouched — group reads stay open, group write actions stay `403` via `cekBukanGrup()` (out of scope here, already correct).
- **CON-003**: The `auth` filter on all Inbox routes stays in place — open-read applies to logged-in staff, never the public.
- **GUD-001**: No new endpoint. Ownership logic for writes remains sourced solely from `cekOwnership()`; this plan must not duplicate that logic anywhere.

## 2. Implementation Steps

> **EXECUTION DIRECTIVE FOR AI AGENTS:**
> You MUST execute this plan phase by phase. You MUST run the specific testing/verification task at the end of each phase. After a phase is tested, you **MUST STOP AND WAIT** for the user's explicit approval before proceeding to the next phase.

### Implementation Phase 1

- GOAL-001: Remove the ownership guard from `Inbox::media()`, keep the `404` guard, and prove the new behavior (and the untouched SEC-001 contract) with tests.

| Task     | Description                                                                                                                                                                                                                                                                                          | Ref ID           | AC Ref | Dep      | Files | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------- | ------ | -------- | ----- | --------- | ---- |
| TASK-001 | In `app/Controllers/Inbox.php::media()`, delete **only** the `cekOwnership()` block (current lines 425-431: the `$ownershipError = ...` call and its `if ($ownershipError) { return ... 403 ... }` block). Keep the conversation lookup (`:416-423`) and its `404` response exactly as-is. Replace the single old SEC-002 comment (which currently sits above the retained lookup, `:410-415`) with **two** separate comments mirroring the spec Section 8 example exactly: (1) a new **C-1** comment placed immediately before the conversation lookup (`:416-423`), explaining it is retained on purpose to produce `404` when the owning conversation does not exist (AC-007); (2) a new **REQ-002** comment placed where the deleted `cekOwnership()` block used to be, stating that read access is intentionally open to all logged-in staff. Do not edit the old comment in place — it must become two distinct comments in two distinct locations. | REQ-002            | AC-002, AC-007 | -        | 1     | ✅        | 2026-09-27 |
| TASK-002 | In `tests/session/InboxMediaAuthTest.php`, rewrite `testMediaPercakapanMilikKasirLainDitolak403TanpaHubungiGateway` to assert the opposite outcome: a non-holder now receives `200` with the correct file body (`'ISI-RAHASIA'`), and `gatewayMediaDownloadCalls` stays `0` (served from disk). Rename the method to `testMediaPercakapanMilikKasirLainDisajikanDariDisk` to match the new assertion. Keep the other 3 existing test methods (`testMediaPercakapanSendiriDisajikanDariDisk`, `testMediaPercakapanBelumDitanganiTetapDisajikan`, `testAdminBolehMenyajikanMediaPercakapanKasirLain`) unchanged — they already pass under the new behavior and remain valid regression coverage. **Also update the two other now-stale artifacts in this file so nothing describing the old 403 behavior survives the rewrite:** (a) the class-level docblock (`:12-18`), which currently documents the old "non-holder rejected 403" rule — rewrite it to describe the open-read rule instead; (b) the assertion message string at `:97`, which currently reads like a 403-rejection message — rewrite it to state the new expectation (e.g. "served from disk for a non-holder, without contacting the Gateway"). | REQ-002            | AC-002 | TASK-001 | 1     | ✅        | 2026-09-27 |
| TASK-003 | In `tests/session/InboxMediaAuthTest.php`: **(a)** First, extend the `InboxMediaAuthSpy` test double (`:205-215`) by adding a public, mutable property `$gatewayResponse`, defaulting to the exact same always-succeeds array it currently hardcodes, and change `callGatewayMediaDownload()` to `return $this->gatewayResponse;` instead of the hardcoded literal — this is backward-compatible and must not alter the outcome of any of the 4 existing tests (they never set the property, so they keep using the default). **(b)** Then add a new test method proving AC-009/C-2: seed a conversation held by a different user (`assigned_to = 9`), session as a non-holder non-admin (`id_user = 7`, `role = 'kasir'`), seed an image message with `media_local_filename = null` and `media_confirmed_gone_at = null` so the request falls through to the Gateway path, set `$controller->gatewayResponse = ['ok' => false, 'status' => 410, 'error' => 'Media sudah tidak tersedia (kedaluwarsa).']` on the spy **before** calling `media()`, then assert three things: the response is `410` (not `403` at the start); `messages.media_confirmed_gone_at` for that row is non-null after the call; and `assertSame(1, $controller->gatewayMediaDownloadCalls, ...)` (matching the pattern already used by the other 4 tests in this file) to prove the request actually reached the Gateway path, not merely that it landed on the same end-result some other way. | REQ-002-C2         | AC-009 | TASK-001 | 1     | ✅        | 2026-09-27 |
| TASK-004 | In `tests/session/InboxHandoffTest.php`: **(a)** First, add a small `seedMessage()` helper (same pattern as the existing `seedConversation()`/`seedUser()` helpers, `:1-135`), inserting one minimal `messages` row for a given seeded conversation — this file currently has no helper capable of producing a `messages` row. **(b)** Then add one new focused test method (e.g. `testApiMessagesTetapTerbukaUntukKasirBukanPemegang`) that seeds a conversation with `assigned_to` set to a different user than the session, uses `seedMessage()` to seed at least one message for it, calls `GET inbox/api/conversations/(:id)/messages` as the non-holder, and asserts `200` plus a non-empty `messages` array in the JSON body. This is a dedicated, explicitly-named regression anchor for SEC-001 distinct from the incidental assertion already at line 596-598, so a future audit/grep can find it by name. | SEC-001             | AC-001 | -        | 1     | ✅        | 2026-09-27 |
| TASK-005 | **VERIFY**: Run `vendor/bin/phpunit --no-coverage --filter InboxMediaAuthTest` and confirm 5 tests pass (4 existing + 1 new AC-009 test), then run `vendor/bin/phpunit --no-coverage --filter InboxHandoffTest` and confirm the new SEC-001 anchor test passes, then run the full suite `vendor/bin/phpunit --no-coverage` and confirm exit code 0 with the test count at or above the pre-session baseline (see `.claude/instructions/memory.instructions.md` Key Metrics & Baselines) plus 2 new tests. | -                   | -      | TASK-002, TASK-003, TASK-004 | -     | ✅        | 2026-09-27 |
| TASK-006 | **APPROVAL**: Wait for explicit user confirmation to proceed to Phase 2                                                                                                                                                                                                                              | -                   | -      | TASK-005 | -     | ✅        | 2026-09-27 |

### Implementation Phase 2

- GOAL-002: Synchronize `docs/ARCHITECTURE.md` with the new read-open media behavior (Living Architecture Map mandate).

| Task     | Description                                                                                                                                                                                                                                                                                   | Ref ID  | AC Ref | Dep      | Files | Completed | Date |
| -------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------- | ------ | -------- | ----- | --------- | ---- |
| TASK-007 | In `docs/ARCHITECTURE.md` Section 7 (the Inbox HTTP Surface table, line 253), change the `GET /inbox/media/(:num)` row's Responsibility text from `Authenticated media access` to something that reflects open-read (e.g. `Media access (open to all logged-in staff; no ownership check)`). Then, in Section 8, create a **new named sub-section** `### Media Read Authorization`, placed parallel to the existing `### Handoff and Collision Detection` sub-section (one sub-section per architectural decision — do **not** append this content into the unrelated `### Balas Pesan` sub-section, which covers the quote feature). In that new sub-section, state that `Inbox::media()` no longer performs a `cekOwnership()` check per `spec-design-inbox-read-authorization.md` REQ-002, that the conversation `404` lookup is retained, and that the `410`/`media_confirmed_gone_at` write path is triggerable by any logged-in staff (REQ-002-C2). Perform a surgical edit only — do not regenerate the file or restructure existing sections. | REQ-002 | -      | TASK-006 | 1     | ✅        | 2026-09-27 |
| TASK-008 | **VERIFY**: Re-read the edited section of `docs/ARCHITECTURE.md` to confirm the wording matches the actual code behavior implemented in Phase 1 (no `cekOwnership()` in `media()`, `404` on missing conversation retained, `410` write open to all staff), and confirm no other section of the document still claims `media()` is ownership-gated.                                                                                                                                                          | -       | -      | TASK-007 | -     | ✅        | 2026-09-27 |
| TASK-009 | **APPROVAL**: Wait for explicit user confirmation that the plan is complete                                                                                                                                                                                                                 | -       | -      | TASK-008 | -     | ✅        | 2026-09-27 |

## 3. Alternatives

- **ALT-001**: Keep the `cekOwnership()` guard on `media()` and instead relax it to a softer check (e.g. allow read but log access). Rejected — the spec (Section 10) explicitly decided text/media parity: any staff who can already read the message text (unrestricted) should be able to read its media without a second, inconsistent gate. A logging-only compromise was never requested and adds scope beyond the spec.
- **ALT-002**: Remove the conversation lookup (`:416-423`) entirely along with the ownership guard, since it appears only to feed `cekOwnership()`. Rejected — clarification session C-1 explicitly determined this lookup is what produces the `404` for AC-007 (message references a conversation that no longer exists); removing it would let such requests fall through to the disk/Gateway path incorrectly.

## 4. Dependencies

- **DEP-001**: None — no new library, framework, or external service. `Inbox::callGatewayMediaDownload()` and its Gateway contract are untouched (spec Section 11, EXT-001).

## 5. Files

- **FILE-001**: `app/Controllers/Inbox.php` — `media()` method (`:396-551`): remove the `cekOwnership()` guard block; keep conversation lookup + `404`; update inline comment (TASK-001).
- **FILE-002**: `tests/session/InboxMediaAuthTest.php` — rewrite the non-holder test to expect `200`; fix the stale class docblock and assertion message; extend `InboxMediaAuthSpy` with a mutable `$gatewayResponse` property; add one new AC-009/C-2 test for the `410` path (TASK-002, TASK-003).
- **FILE-003**: `tests/session/InboxHandoffTest.php` — add a `seedMessage()` helper; add one new named SEC-001 regression anchor test (TASK-004).
- **FILE-004**: `docs/ARCHITECTURE.md` — Section 7 table row + short explanatory paragraph (TASK-007).

## 6. Testing

- **TEST-001**: `vendor/bin/phpunit --no-coverage --filter InboxMediaAuthTest` — 5 tests, all green, proving non-holder read access, holder/admin/unassigned regression, and the AC-009 `410` path.
- **TEST-002**: `vendor/bin/phpunit --no-coverage --filter InboxHandoffTest` — includes the new SEC-001 anchor test proving the thread endpoint stays open for non-holders.
- **TEST-003**: `vendor/bin/phpunit --no-coverage` (full suite) — exit code 0, no regression anywhere else in the Inbox test suite.

## 7. Risks & Assumptions

- **ASSUMPTION-001** (from spec): all logged-in staff are mutually trusted (shared-inbox model for a small store); there is no per-staff privacy requirement at the conversation level. If a future requirement introduces per-staff conversation privacy, this plan's premise — and the underlying spec — must be revisited.
- **ASSUMPTION-002** (from spec): the guard introduced by `SEC-002`/`TASK-103` (`plan/plan-refactor-balas-pesan-tahap3-review2-v1.0.md:50`) is deliberately reversed here because its premise ("a caller unauthorized for the owning conversation must not see it") no longer holds under the open-read decision.
- **RISK-001**: A future session or PR could silently reintroduce a `cekOwnership()` check to `media()` or `apiMessages()`, reverting this decision without anyone noticing (it is a small, easily-reversible diff). Mitigated by TASK-002 (rewritten test that fails loudly if the guard returns) and TASK-004 (dedicated named SEC-001 anchor test for the thread endpoint).
- **RISK-002**: No other test file in the repository was found to assert `403` on `Inbox::media()` for a non-holder besides `InboxMediaAuthTest.php` (verified via repo-wide grep during planning). If an untracked or newly-added test elsewhere makes the same assumption, it will need updating outside this plan's file list.

## 8. Related Specifications / Further Reading

- [`spec/spec-design-inbox-read-authorization.md`](../spec/spec-design-inbox-read-authorization.md) — source spec (v1.1), REQ-001–REQ-005, SEC-001, AC-001–AC-009.
- [`docs/audit/clarification-report-inbox-read-authorization-2026-09-27.md`](../docs/audit/clarification-report-inbox-read-authorization-2026-09-27.md) — C-1/C-2 resolutions this plan implements.
- [`docs/audit/clarification-report-inbox-media-read-authorization-plan-2026-09-27.md`](../docs/audit/clarification-report-inbox-media-read-authorization-plan-2026-09-27.md) — Readiness 91/100 PROCEED; six execution-detail resolutions folded into TASK-001/002/003/004/007 (v1.1 revision note above).
- `plan/plan-refactor-balas-pesan-tahap3-review2-v1.0.md` — origin of the `SEC-002`/`TASK-103` guard this plan reverses.
- `docs/ARCHITECTURE.md` Section 7 — Inbox HTTP Surface table to be updated in Phase 2.

## 9. Rollback / Recovery Plan

- **Phase 1 rollback**: `git revert` the commit containing TASK-001–004, or manually restore the deleted `cekOwnership()` block (`app/Controllers/Inbox.php:425-431` in the pre-change version) and revert the two test files to their prior assertions. No database state is affected — this is a pure code/test change with no migration.
- **Phase 2 rollback**: `git revert` the commit containing TASK-007's `docs/ARCHITECTURE.md` edit, or manually restore the prior table row text and remove the added paragraph. No functional impact either way — Phase 2 is documentation-only.
- Both phases are independently revertible without affecting the other, since Phase 2 touches only documentation.
