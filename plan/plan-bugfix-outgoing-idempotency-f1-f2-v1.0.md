---
goal: M1 Wave 2 Outgoing Idempotency — F-1 (OPERATION_STORE_ERROR signaling) & F-2 (uncertain-send UX mitigation)
version: 1.0
date_created: 2026-09-24
last_updated: 2026-09-24
owner: AuliaPos Inbox module
status: "Completed"
tags: ["bug-fix", "remediation", "patch", "inbox", "gateway", "outgoing-idempotency"]
---

# Introduction

![Status: Completed](https://img.shields.io/badge/status-Completed-brightgreen)

This plan remediates two findings recorded in the M1 Wave 2 code review checkpoint
(`.claude/instructions/memory.instructions.md`, commit `e49b582`, findings F-1 and F-2), both located in the
outgoing-message idempotency area introduced by `spec/spec-process-m1-wave2-outgoing-idempotency.md` and
`plan/plan-process-m1-wave2-outgoing-idempotency-v1.0.md`.

**F-1 (full fix in this plan):** when `outgoingOperations.begin()` fails persistently in WA-Gateway (disk full,
corrupt SQLite file), the Gateway correctly fails closed and replies `500 OPERATION_STORE_ERROR` for every retry of
the same `operation_id`. Root cause: `app/Views/inbox/index.php`, `tanganiKegagalanKirimBalasan()` (lines
2210-2231) has no explicit branch for `OPERATION_STORE_ERROR`, so it falls into the generic "ordinary failure"
default branch (lines 2228-2231) — a generic toast, with no indication that this needs an operator restart rather
than another retry. `Inbox.php::gatewayFailureResponse()` already forwards `error_code` unmodified in its default
branch (line 2301), so no server-side change is required.

**F-2 (UX mitigation only, NOT a full fix in this plan):** when a send genuinely succeeds at WhatsApp but AuliaPos
receives `409 SEND_IN_PROGRESS`/`504 SEND_UNRESOLVED` (lease not yet released), the message is never written to
`messages` — confirmed at `Inbox.php::kirimKeConversation()` (lines 1965-1969) and `kirimMedia()` (lines 868-872),
both of which return `gatewayFailureResponse()` before reaching the `insert` call, and confirmed further by the
existing locked test `testAmbiguousGatewayResponseDoesNotInsertSuccessfulMessage()`
(`tests/session/InboxOutgoingIdempotencyTest.php:140-160`), which asserts `messageCount() === 0` for exactly this
path. This is a **deliberate REQ-041 design decision**, already reviewed and accepted as
`[Assumed / Out of Scope]` for M1 Wave 2 (`memory.instructions.md` line 2423). A real fix requires either a new
Gateway status-check contract or a new `send_status` enum value for local reconciliation — both are architecture
decisions, not surgical patches (`DAT-002`/`CON-009` in the spec explicitly restrict schema changes to additive
nullable columns and forbid changing the meaning of existing columns). Per this skill's Architecture Escalation
rule, this plan therefore implements **only a UX-strengthening mitigation** (clearer, more persistent "uncertain
result" messaging) and formally recommends routing the actual reconciliation design to `/sdlc-define-specs` as a
Wave 3 candidate.

## 1. Requirements & Constraints (Fix Constraints)

- **REQ-001 (F-1):** `tanganiKegagalanKirimBalasan()` MUST show a distinct message for
  `json.error_code === 'OPERATION_STORE_ERROR'` that tells the cashier this needs an operator restart, not a
  retry, before falling through to the generic failure branch.
- **REQ-002 (F-1):** the `operation_id` MUST still be preserved (not discarded) on `OPERATION_STORE_ERROR`,
  consistent with the existing fail-closed semantics for all other "ordinary failure" codes — no attempt was ever
  registered on the Gateway side for this key.
- **REQ-003 (F-2, mitigation only):** the existing "hasil belum pasti" warning path (`SEND_IN_PROGRESS` /
  `SEND_UNRESOLVED`) MUST be strengthened with more explicit guidance (check WhatsApp/another tab before retrying)
  without inserting any `messages` row and without changing `gatewayFailureResponse()`'s HTTP contract.
- **CON-001:** this fix MUST NOT alter the existing public JSON response contract (`status`, `error_code`, `state`,
  `replayed`, `uncertain`, `new_key_required`, `message` keys) for any of the three error branches.
- **CON-002:** this fix MUST NOT modify `app/Controllers/Inbox.php` — `gatewayFailureResponse()` already forwards
  `OPERATION_STORE_ERROR` correctly; only the client-side JS in `app/Views/inbox/index.php` changes.
- **CON-003:** this fix MUST NOT touch `messages.send_status`, any migration, or the WA-Gateway repository/contract
  (out of scope — see F-2 Architecture Escalation note above).
- **CON-004:** the existing locked JS check (`tests/js/operation-id-composer.check.js`) and PHPUnit session test
  (`tests/session/InboxOutgoingIdempotencyTest.php`) MUST keep passing unmodified in their existing assertions;
  new assertions are additive only.
- **CON-005:** backward compatibility — a Gateway response without `error_code` (legacy) or with any other
  `error_code` MUST continue to fall through to the existing default "ordinary failure" branch unchanged.

## 2. Implementation Steps

> **⚠️ EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> You MUST execute this plan phase by phase. You MUST run the specific testing/verification task at the end of
> each phase. After a phase is tested, you **MUST STOP AND WAIT** for the user's explicit approval before
> proceeding to the next phase.

### Implementation Phase 1: Test Writing (Test-Driven Bug Fixing)

- **GOAL-001:** Write failing checks that reproduce the exact gaps described in F-1 and F-2 before any production
  code changes.

| Task     | Description                                                                                                                                                                                                                     | Ref ID            | Completed | Date |
| -------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------ | :-------: | :--: |
| TASK-001 | In `tests/js/operation-id-composer.check.js`, add a block asserting: for `error_code: 'OPERATION_STORE_ERROR'`, `data-operation-id` is preserved (same key), a distinct message mentioning operator/restart guidance is shown (not the generic "Gagal mengirim pesan." default), and `toasts[0].type === 'danger'`. | REQ-001, REQ-002    |    ✅     | 2026-09-24 |
| TASK-002 | In the same file, strengthen the assertion for the existing `SEND_IN_PROGRESS`/`SEND_UNRESOLVED` block (§3) to check the new, more explicit copy text (still starting with "Hasil belum pasti, jangan kirim ulang dulu.") once REQ-003 wording is finalized in Phase 2.                       | REQ-003             |    ✅     | 2026-09-24 |
| TASK-003 | **VERIFY**: Run `node tests/js/operation-id-composer.check.js`. It MUST FAIL (assertion error on the new `OPERATION_STORE_ERROR` block, since the branch does not exist yet).                                                     | -                   |    ✅     | 2026-09-24 |
| TASK-004 | **APPROVAL**: 🛑 Wait for explicit user confirmation to proceed to Phase 2                                                                                                                                                        | -                   |    ✅     | 2026-09-24 |

### Implementation Phase 2: Minimal Root Cause Remediation

- **GOAL-002:** Implement the client-side signaling fix for F-1 and the UX copy strengthening for F-2, without
  over-engineering and without touching server code, schema, or the Gateway contract.

| Task     | Description                                                                                                                                                                                                                                                             | Ref ID            | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------ | :-------: | :--: |
| TASK-005 | In `app/Views/inbox/index.php`, `tanganiKegagalanKirimBalasan()` (around line 2210), add a new `if (json.error_code === 'OPERATION_STORE_ERROR')` branch BEFORE the generic default branch (line 2228). Keep the key (do NOT call `buangOperationIdBalasan()`). Show a distinct message via `tampilkanStatusKirimBalasan(...)` and `showToast(..., 'danger')` explicitly stating the cashier must contact an operator/admin to restart the Gateway rather than retry. Return `true`. | REQ-001, REQ-002    |    ✅     | 2026-09-24 |
| TASK-006 | Copy the updated `tanganiKegagalanKirimBalasan()` function VERBATIM into `tests/js/operation-id-composer.check.js` (per the file's own documented convention at its header comment, lines 14-19), replacing the stale copy.                                             | CON-004             |    ✅     | 2026-09-24 |
| TASK-007 | Strengthen the existing `SEND_IN_PROGRESS`/`SEND_UNRESOLVED` branch message text (both in `index.php` and its verbatim JS copy) to add a short explicit instruction, e.g. append "Periksa WhatsApp atau tab lain sebelum mengirim ulang." to the existing "Hasil belum pasti, jangan kirim ulang dulu." string. Do NOT change `error_code`/`state`/`uncertain` keys or insert any `messages` row. | REQ-003             |    ✅     | 2026-09-24 |
| TASK-008 | Update only the JSDoc-style comment block above `tanganiKegagalanKirimBalasan()` (both copies) to mention the new `OPERATION_STORE_ERROR` branch, keeping the existing comment style.                                                                                   | REQ-001             |    ✅     | 2026-09-24 |
| TASK-009 | **VERIFY**: Run `node tests/js/operation-id-composer.check.js`. It MUST PASS (all blocks, including the new `OPERATION_STORE_ERROR` block).                                                                                                                             | -                   |    ✅     | 2026-09-24 |
| TASK-010 | **VERIFY**: Run `vendor/bin/phpunit --no-coverage --filter InboxOutgoingIdempotencyTest` to confirm no server-side regression, since `CON-002` means these tests should be unaffected.                                                                                  | CON-001, CON-002    |    ✅     | 2026-09-24 |
| TASK-011 | **VERIFY**: Run the full suite `vendor/bin/phpunit --no-coverage` to confirm zero regressions project-wide.                                                                                                                                                              | -                   |    ✅     | 2026-09-24 |
| TASK-012 | **APPROVAL**: 🛑 Wait for explicit user confirmation before closing this plan                                                                                                                                                                                            | -                   |    ✅     | 2026-09-24 |

## 3. Rollback Strategy

- **RBCK-001**: Revert the single commit(s) touching `app/Views/inbox/index.php` and
  `tests/js/operation-id-composer.check.js` via `git revert`; no migration, no schema, and no server-side
  (`Inbox.php`) change exists to roll back.
- **RBCK-002**: Because `CON-001` guarantees the JSON contract is unchanged, no client caching/versioning concern
  exists beyond a normal static-asset cache-bust (if the app serves `index.php`'s inline `<script>` through any
  cache layer); a hard refresh is sufficient to restore prior behavior after a revert.

## 4. Dependencies

- **DEP-001**: None. This fix is self-contained within `app/Views/inbox/index.php` and its verbatim JS test copy;
  it does not depend on any WA-Gateway change, migration, or third-party library.

## 5. Files Affected

- **FILE-001**: `app/Views/inbox/index.php` — add `OPERATION_STORE_ERROR` branch to
  `tanganiKegagalanKirimBalasan()`; strengthen `SEND_IN_PROGRESS`/`SEND_UNRESOLVED` message copy.
- **FILE-002**: `tests/js/operation-id-composer.check.js` — mirror the updated function verbatim (per the file's
  own documented convention) and add/extend assertions for both branches.

## 6. Testing Strategy & Edge Cases

- **TEST-001**: `node tests/js/operation-id-composer.check.js` is the authoritative check for
  `tanganiKegagalanKirimBalasan()` behavior (no Node infra elsewhere in this project — confirmed via the file's
  own header comment and `CLAUDE.md`). Both Phase 1 (red) and Phase 2 (green) runs of this script are mandatory.
- **TEST-002**: `vendor/bin/phpunit --no-coverage` (full suite) MUST stay green after Phase 2, proving `CON-002`
  (no server-side file touched) holds and no regression was introduced elsewhere.
- **Edge case (E-01)**: `OPERATION_STORE_ERROR` arriving together with a non-empty `json.message` from the
  Gateway — the new branch MUST still show the operator-restart guidance text, not just echo the raw Gateway
  message, so the cashier gets an actionable instruction regardless of what the Gateway happened to send.
- **Edge case (E-02)**: repeated `OPERATION_STORE_ERROR` responses for the same `operation_id` (the exact bug
  scenario) MUST NOT discard or regenerate the key on each attempt — verified by TASK-001/TASK-005 asserting the
  key is preserved identically across calls.
- **Edge case (E-03, F-2 mitigation scope check)**: confirm via TASK-010 that
  `testAmbiguousGatewayResponseDoesNotInsertSuccessfulMessage()` still asserts `messageCount() === 0` after this
  plan — the mitigation is UX-only and MUST NOT accidentally start inserting a `messages` row on this path.

## 7. Risks & Assumptions

- **RISK-001**: The exact wording for the new `OPERATION_STORE_ERROR` message and the strengthened
  `SEND_IN_PROGRESS`/`SEND_UNRESOLVED` copy is not dictated by any upstream spec (REQ-041 only requires an
  "uncertain result" state, not specific copy). `/sdlc-write-code` MAY finalize the exact Indonesian wording during
  implementation as long as it satisfies REQ-001/REQ-002/REQ-003 semantics; this is `[Assumed / Backlog]` for
  copy-review, not a blocking ambiguity.
- **RISK-002**: F-2's real fix (message reconciliation for genuinely-succeeded-but-uncertain sends) remains
  **unresolved by this plan by design**. Recommended next step: open a `/sdlc-define-specs` session (or a
  `/sdlc-draft-prd` session first, if the reconciliation approach needs product-level tradeoffs such as showing a
  "pending confirmation" bubble) to design either (a) a Gateway status-check/webhook contract, or (b) a new
  `messages.send_status` value plus reconciliation job — both are explicitly out of scope for `/sdlc-write-code`
  under this plan.
- **RISK-003**: This plan assumes `Inbox.php::gatewayFailureResponse()`'s default branch (lines 2299-2305) already
  forwards `error_code: 'OPERATION_STORE_ERROR'` correctly to the client unchanged — verified by direct code
  reading during Phase 1 diagnosis of this bug report, not merely assumed.
- **ASSUMPTION-001**: The WA-Gateway side (separate repository, not read during this diagnosis) is assumed to
  already return `error_code: 'OPERATION_STORE_ERROR'` with HTTP 500 as documented in
  `docs/decisions/2026-09-24-m1-wave2-eksekusi-fase1.md` (P-2). If the Gateway's actual JSON shape differs, TASK-005
  must be revisited before merge.
