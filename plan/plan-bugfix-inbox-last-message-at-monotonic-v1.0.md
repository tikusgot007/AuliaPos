---
goal: Make conversations.last_message_at monotonic (never move backwards) regardless of message insertion order
version: 1.0
date_created: 2026-09-25
last_updated: 2026-09-25
owner: AuliaPos Inbox module
status: "Completed"
tags: ["bug-fix", "remediation", "patch", "inbox", "sla", "data-integrity"]
---

# Introduction

![Status: Completed](https://img.shields.io/badge/status-Completed-brightgreen)

`conversations.last_message_at`/`last_message_direction` are **denormalized** columns (spec REQ-009, `spec/spec-design-m3-operational-inbox-fase1.md:132`): they are not produced by an aggregate query, they are written explicitly at every message-insert call site with a plain unconditional `UPDATE`. `last_message_at` feeds two consumers directly: the SLA Timer (spec REQ-010; age of `last_message_at`, thresholds in `app/Config/Inbox.php`) and the conversation-list sort (`ORDER BY last_message_at DESC`, e.g. `app/Controllers/Inbox.php:52` and `:112`; requirement GW-11, `docs/GATEWAY-REQUIREMENTS.md:38`).

**The defect.** All three write sites replace `last_message_at` unconditionally with the timestamp of the message that was just inserted — none of them checks whether that timestamp is actually newer than the value already stored. Under normal traffic this is harmless, because inserts arrive in chronological order and the newest insert is always the newest timestamp. It stops being harmless the moment messages are **not** inserted in chronological order — which the sibling plan `plan/plan-bugfix-inbox-message-ordering-v1.0.md` already proved happens in this system: after a Gateway outage the buffered backlog is delivered and inserted in a burst, and insertion order does not reliably match `message_timestamp` order (that plan documented the observed `id`-vs-`message_timestamp` inversions from the 2026-09-25 flush). When an older-timestamped message is inserted **after** a newer-timestamped one for the same conversation, "last write wins" silently drags `last_message_at` backwards in time.

**This is observed in the live database, not merely predicted.** A read-only audit of `aulia_inboxdb` (2026-09-25), correctly scoped to **exclude internal notes** (`messages.is_internal = 1` rows never call `ConversationModel::update()` for these columns, per spec REQ-009 — including them yields false positives; the naive unscoped query over-counts to 7 rows, 3 of which are internal-note false positives from an unrelated 2026-09-24 dataset), shows **4 conversations** where `conversations.last_message_at` is strictly older than the true latest non-internal message of that conversation:

| conversation_id | `conversations.last_message_at` (stored) | true latest `message_timestamp` | true latest direction | drift (minutes) |
| --------------- | ---------------------------------------- | ------------------------------- | --------------------- | --------------- |
| 11745           | 2026-09-25 08:31:35                      | 2026-09-25 09:48:37             | incoming              | 77              |
| 11747           | 2026-09-25 12:31:18                      | 2026-09-25 13:47:15             | incoming              | 75              |
| 11748           | 2026-09-25 11:23:51                      | 2026-09-25 11:47:54             | outgoing              | 24              |
| 11750           | 2026-09-25 10:16:36                      | 2026-09-25 13:16:17             | incoming              | 179             |

> [!NOTE]
> The 3 rows excluded by the `is_internal = 0` filter are conversations **11730, 11739, 11740** (2026-09-24). Their apparent drift comes only from Internal Notes (`is_internal = 1`), which by design never touch `last_message_at` (spec REQ-009). They are **not** bugs and MUST NOT be "repaired".

**Mechanism confirmed ("last inserted row wins").** For all 4 conversations, the row that produced the *stale* `conversations.last_message_at` is the one with the **highest `messages.id`** (the last one physically inserted) for that conversation — even though its `message_timestamp` is *older* than a sibling row inserted earlier in the same burst:

| conversation_id | last-inserted `messages.id` | its `message_timestamp` (= what got written to `last_message_at`) | its `created_at` (DB insert time) |
| --------------- | --------------------------- | ---------------------------------------------------------------- | --------------------------------- |
| 11745           | 265                         | 2026-09-25 08:31:35                                              | 2026-09-25 13:58:03               |
| 11747           | 259                         | 2026-09-25 12:31:18                                              | 2026-09-25 13:57:28               |
| 11748           | 255                         | 2026-09-25 11:23:51                                              | 2026-09-25 13:57:18               |
| 11750           | 246                         | 2026-09-25 10:16:36                                              | 2026-09-25 13:57:07               |

All 4 `created_at` values fall inside the same ~96-second backlog-flush window documented for the sibling ordering bug, confirming both defects share one root event (a burst of out-of-order inserts) and are independent in their effect.

**User-visible impact (quantified at the flush window, 2026-09-25 13:57:00; thresholds Hijau `<15m`, Kuning `15-60m`, Merah `>60m`, spec REQ-010):**

| conversation_id | SLA from stored value | SLA from true value | observable harm |
| --------------- | --------------------- | ------------------- | --------------- |
| 11745           | Merah (325 min)       | Merah (248 min)     | list position understated by 77 min |
| 11747           | Merah (86 min)        | **Hijau (10 min)**  | a fresh conversation is painted as breached |
| 11748           | Merah (153 min)       | Merah (129 min)     | direction regressed to `incoming`, so the UI shows "awaiting staff reply" although staff already replied (`outgoing`, id 212) |
| 11750           | Merah (220 min)       | **Kuning (41 min)** | a 41-minute-old message is painted as breached |

## 1. Requirements & Constraints (Fix Constraints)

> [!NOTE]
> Identifier namespaces: `REQ-001`..`REQ-005` and `CON-001`..`CON-009` below are **this plan's own** fix constraints. Citations of `spec REQ-009` / `spec REQ-010` refer to the parent spec's requirement IDs (`spec/spec-design-m3-operational-inbox-fase1.md:132-133`), not to the list below.

- **REQ-001**: `conversations.last_message_at` MUST be monotonic per conversation — an insert whose `message_timestamp` is older than the value currently stored MUST NOT overwrite it.
- **REQ-002**: `last_message_direction` MUST remain consistent with whichever `message_timestamp` wins — both columns move together, atomically, in the same conditional write. A state where `last_message_at` reflects one message and `last_message_direction` reflects another is forbidden (case 11748 above is exactly that state today).
- **REQ-003**: All 3 existing write sites MUST go through one shared implementation (not 3 independent inline `if` conditions), so the call sites cannot drift apart again. The sites are enumerated in Section 5.
- **REQ-004**: A write with a **newer** `message_timestamp` than the stored value MUST behave exactly as today: same columns written, same values, same transaction context. This is a hardening of an existing write, not a change of behavior for the normal (in-order) case.
- **REQ-005**: Idempotency is untouched: the existing `wa_message_id` dedup / early-return path in `InboxGatewayApi::messages()` MUST NOT be reordered or bypassed.
- **CON-001**: Every other column currently written alongside `last_message_at` at each site (`status`, `snoozed_until`, `assigned_to`, `last_replied_by`, `last_seen_by_assignee_at`) is **out of scope** and MUST keep being written unconditionally — only the `last_message_at`/`last_message_direction` pair becomes conditional. A backlog delivery MUST still re-open a conversation (`status = 'open'`, `snoozed_until = NULL` for `incoming`) regardless of whether the timestamp guard passes.
- **CON-002**: No schema change. The columns stay denormalized on `conversations` (spec REQ-009 still holds); no trigger, no generated column, no migration.
- **CON-003**: `Inbox::catatanInternal()` MUST keep never calling `ConversationModel::update()` for these columns (spec REQ-009). No 4th write site is introduced.
- **CON-004**: Transaction boundaries MUST NOT change. `InboxGatewayApi::messages()` keeps its existing `transStart()`/`transComplete()` envelope (`app/Controllers/InboxGatewayApi.php:192` and `:278`); the guarded write stays inside it.
- **CON-005**: `NULL` is always "older" than any real timestamp: a conversation whose `last_message_at IS NULL` MUST always accept the first write.
- **CON-006**: Code style follows the existing model conventions (`ConversationModel` already mixes query-builder usage; no new dependency, no new library).
- **CON-007**: Test execution uses the existing `inbox` DB group redirected to `aulia_inboxdb_test` under testing (guard asserted in test `setUp()`, exactly as `tests/session/InboxOutgoingIdempotencyTest.php:28` does). No new DB configuration.
- **CON-008**: Floor-Guard: no suppressions (`@ts-ignore`, `eslint-disable`, `# noqa`), no `.skip`/`markTestSkipped`, no weakened assertions to make the suite pass.
- **CON-009**: Each call site may issue up to **2 statements** instead of 1 (the guarded pair write, then the `$otherFields` write). This is accepted: the two statements touch **disjoint** column sets, no consumer derives SLA/direction from `status`/`snoozed_until`/assignee fields, and they stay inside the same existing transaction envelope where one exists (CON-004).
- **CON-010**: `conversations.updated_at` MUST keep advancing exactly as it does today. The guarded write is a raw query-builder `UPDATE`, so it bypasses `Model::update()`'s `$useTimestamps = true` / `$updatedField = 'updated_at'` mechanism (`app/Models/ConversationModel.php:111,113`), and the column is `DATETIME NOT NULL` with no `ON UPDATE CURRENT_TIMESTAMP` fallback (`app/Database/Migrations/2026-09-07-000001_CreateInboxTables.php:106`) — the value MUST therefore be supplied explicitly in the same statement. Precedent for this exact shape already exists in the module: the conditional handoff write at `app/Controllers/Inbox.php:1251-1256` sets `assigned_to` and `updated_at` together in one raw `UPDATE`, and a prior audit explicitly rejected the alternative of "stop writing `updated_at`" as an unnecessary behavior change (`docs/audit/clarification-report-m3-fase2a-assumptions-008-011-2026-09-23.md:75`).

## 2. Implementation Steps

> **⚠️ EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> You MUST execute this plan phase by phase. You MUST run the specific testing/verification task at the end of each phase. After a phase is tested, you **MUST STOP AND WAIT** for the user's explicit approval before proceeding to the next phase.

### Implementation Phase 1: Test Writing (Test-Driven Bug Fixing)

- **GOAL-001:** Add regression tests that pin the monotonic contract of `last_message_at`/`last_message_direction` and that FAIL against the current code, proving the defect before it is fixed.

| Task     | Description                                                                                                                                                                                                                                                                                                   | Ref ID                      | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------- | :-------: | :--: |
| TASK-001 | Create `tests/database/ConversationModelLastMessageAtTest.php` (`CIUnitTestCase` per `tests/database/ConversationHandoffModelTest.php`, `db_connect('inbox')`, plus the `aulia_inboxdb_test` connection guard from `tests/session/InboxOutgoingIdempotencyTest.php:28`, `emptyTable()` the `conversations`/`messages` tables in `setUp()`). | REQ-003, CON-007           | [x]       | 2026-09-25 |
| TASK-002 | Add the model contract tests (TEST-001 in Section 6) for `updateLastMessageIfNewer()`: stale write rejected for both columns, newer write applied, tie ignored, stored `NULL` accepted, `$otherFields` written on **both** branches, empty `$otherFields` skipped, `updated_at` bumped on the applied branch and untouched on a fully-rejected call. | REQ-001, REQ-002, CON-001, CON-005, CON-010 | [x]       | 2026-09-25 |
| TASK-003 | Create `tests/session/InboxGatewayLastMessageAtTest.php` following the direct-controller-call pattern of `tests/session/InboxOutgoingIdempotencyTest.php` (`new IncomingRequest(new \Config\App(), new URI('cli'), null, new UserAgent())`, `$request->setBody(json_encode([...]))`, `new InboxGatewayApi()`, `$controller->initController($request, new Response(new \Config\App()), new NullLogger())`, then call `messages()` directly — no real HTTP call, so `GatewayTokenFilter` never runs and no token is needed): deliver a **newer**-timestamped message, then an **older**-timestamped one for the same `chat_id` (2 separate `messages()` calls, distinct `wa_message_id`), and assert `last_message_at`/`last_message_direction` still reflect the newer message while the older `incoming` delivery still applied `status`/`snoozed_until` (TEST-002 in Section 6). | REQ-001, REQ-002, CON-001  | [x]       | 2026-09-25 |
| TASK-004 | Extend `tests/session/InboxOutgoingIdempotencyTest.php` (FILE-006 in Section 5, reusing its existing `setUp()` and controller-double fixtures) with the backdated-send regression for `Inbox::kirimKeConversation()` and `Inbox::kirimMedia()`: pre-seed a `last_message_at` in the future relative to the send, send, assert it was not overwritten while `last_replied_by`/`last_seen_by_assignee_at`/`assigned_to` were still written (TEST-003 in Section 6). | REQ-001, CON-001           | [x]       | 2026-09-25 |
| TASK-005 | **VERIFY**: Run `vendor\bin\phpunit --no-coverage tests/database/ConversationModelLastMessageAtTest.php tests/session/InboxGatewayLastMessageAtTest.php`. TASK-002 and TASK-003 MUST FAIL on the current code (`updateLastMessageIfNewer()` does not exist yet; the Gateway test must reproduce the backward move). Record the exact red output in the evidence log before continuing. | -                           | [x]       | 2026-09-25 |
| TASK-006 | **APPROVAL**: 🛑 Wait for explicit user confirmation to proceed to Phase 2.                                                                                                                                                                                                                                   | -                           | [x]       | 2026-09-25 |

### Implementation Phase 2: Minimal Root Cause Remediation

- **GOAL-002:** Make the `last_message_at`/`last_message_direction` write conditional on being newer, in one shared place, without changing anything else about the ingest or send paths.

| Task     | Description                                                                                                                                                                                                                                                                                             | Ref ID                     | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------- | :-------: | :--: |
| TASK-007 | Add `ConversationModel::updateLastMessageIfNewer()` to `app/Models/ConversationModel.php` (reference shape below). It issues a single guarded `UPDATE` (`WHERE id = ? AND (last_message_at IS NULL OR last_message_at < ?)`) so the guard and the write are atomic in one round trip — no read-then-write race between two concurrent requests for the same conversation — and supplies `updated_at` explicitly in that same statement (CON-010). | REQ-001, REQ-002, REQ-003, CON-010 | [x]       | 2026-09-25 |
| TASK-008 | Rewire `InboxGatewayApi::messages()` (`app/Controllers/InboxGatewayApi.php:260-276`): remove `last_message_at`/`last_message_direction` from `$conversationUpdate` (keep `status`, and `snoozed_until` for `incoming`), then replace `$conversationModel->update($conversationId, $conversationUpdate)` with `$conversationModel->updateLastMessageIfNewer($conversationId, $messageTimestamp, $direction, $conversationUpdate)`. The `outgoing` branch then leaves `$otherFields` empty, which is exactly the empty-array path pinned by `testEmptyOtherFieldsSkipsSecondUpdate()` (TEST-001). | REQ-003, CON-001, CON-004  | [x]       | 2026-09-25 |
| TASK-009 | Rewire `Inbox::kirimMedia()` (`app/Controllers/Inbox.php:927-932`): same substitution using `$now` / `'outgoing'`; `last_replied_by`, `last_seen_by_assignee_at`, `assigned_to` stay in `$otherFields`. | REQ-003, CON-001           | [x]       | 2026-09-25 |
| TASK-010 | Rewire `Inbox::kirimKeConversation()` (`app/Controllers/Inbox.php:2015-2020`): same substitution. | REQ-003, CON-001           | [x]       | 2026-09-25 |
| TASK-011 | Re-confirm every call-site line number against `HEAD` immediately before editing (the sibling ordering plan may land first and shift them). | -                          | [x]       | 2026-09-25 |
| TASK-012 | **VERIFY**: Run `vendor\bin\phpunit --no-coverage tests/database/ConversationModelLastMessageAtTest.php tests/session/InboxGatewayLastMessageAtTest.php tests/session/InboxOutgoingIdempotencyTest.php` (all MUST PASS), then the full suite `vendor\bin\phpunit --no-coverage` (macro gate, zero regressions, no suppressions). Then re-run the canonical drift query from Section 6 against `aulia_inboxdb` and record the result (it MUST NOT grow). | -                          | [x]       | 2026-09-25 |
| TASK-013 | **OPTIONAL / DATA (approval-gated, NOT part of the code commit)**: one-off correction of the 4 pre-existing drifted conversations (11745, 11747, 11748, 11750) per RBCK-002 — exact target values in the Introduction table, previous values recorded there for reversal. Execute only with explicit user approval. | REQ-001                    | [x]       | 2026-09-25 |
| TASK-014 | **APPROVAL**: 🛑 Wait for explicit user confirmation to close the phase. | -                          | [x]       | 2026-09-25 |

Reference shape for TASK-007 — the method owns both the time guard and the "other fields still get written" rule, so the 3 call sites stay one-liners and cannot drift apart again (REQ-003):

```php
/**
 * Advance the denormalized "last message" summary of a conversation,
 * but only forward in time. Returns TRUE when the summary advanced.
 */
public function updateLastMessageIfNewer(
    int $conversationId,
    string $messageTimestamp,
    string $direction,
    array $otherFields = []
): bool {
    $applied = $this->db->table($this->table)
        ->where($this->primaryKey, $conversationId)
        ->groupStart()
            ->where('last_message_at', null)                  // stored NULL -> always older
            ->orWhere('last_message_at <', $messageTimestamp)
        ->groupEnd()
        ->update([
            'last_message_at'        => $messageTimestamp,
            'last_message_direction' => $direction,
            // A raw builder UPDATE bypasses Model::update()'s
            // $useTimestamps handling, so bump this explicitly (CON-010).
            'updated_at'             => (new \DateTime('now', new \DateTimeZone('Asia/Jakarta')))
                ->format('Y-m-d H:i:s'),
        ]);

    // Every other field the call site passes (status, snoozed_until,
    // assigned_to, last_replied_by, last_seen_by_assignee_at) stays
    // unconditional, exactly as before this fix (CON-001).
    if ($otherFields !== []) {
        $this->update($conversationId, $otherFields);
    }

    return (bool) $applied;
}
```

Three deliberate implementation details, all load-bearing:

- The guarded `UPDATE` goes through `$this->db->table($this->table)` (**not** `$this->builder()`), because `Model::builder()` caches and reuses one builder instance for the lifetime of the model — reusing it across calls risks stale `WHERE` fragments, and it would also drag the model's soft-delete semantics into a statement that today effectively has none. Guarding on the primary key alone matches reality: every call site already operates on a conversation resolved to a live (non-deleted) row (`ConversationModel::resolveConversationId()` calls `revive()` at `app/Models/ConversationModel.php:340`).
- `$this->db` is the model's own connection group (`protected $DBGroup = 'inbox'`), so this statement can never touch `aulia_kasirdb` — same group as today's `update()`.
- `updated_at` is supplied explicitly using the same `Asia/Jakarta`-aware construction the rest of the controller layer already uses (e.g. `app/Controllers/Inbox.php:894`), rather than relying on `Model::update()`'s automatic timestamping — the raw builder call does not go through that mechanism (CON-010).

- **No backfill migration in this plan.** The 4 currently-drifted conversations (11745, 11747, 11748, 11750) are corrected as a one-off, logged, approval-gated data fix after the code ships (TASK-013, RBCK-002) — a one-time correction of pre-existing bad data, not a repeatable schema migration, so the code change can be reviewed and reverted independently of the data correction.

## 3. Rollback Strategy

The code change is additive at the model level (`updateLastMessageIfNewer()` is a new method) and surgical at the 3 call sites (one method-call substitution each, same arguments regrouped). Revert is a single `git revert` of the commit — no migration, no schema change, so there is nothing to roll back at the data layer for the code fix itself.

- **RBCK-001**: If the conditional `UPDATE` is found to interact badly with `ConversationModel::revive()` (sets `deleted_at = NULL` at `app/Models/ConversationModel.php:340-354` but never touches `last_message_at`) or with any other consumer discovered during Phase 1, revert the 3 call sites first (restores the old unconditional behavior) while leaving the new method dormant in the model, then investigate before re-applying.
- **RBCK-002 (data fix, separate from the code change)**: After the code fix ships, run a one-off, logged `UPDATE conversations SET last_message_at = ?, last_message_direction = ? WHERE id = ?` for each of 11745, 11747, 11748, 11750, restoring the true-latest values recorded in the Introduction table. Fully reversible (the previous stored values are recorded above) and intentionally not bundled into a migration file, since it corrects specific rows once rather than defining a repeatable schema change.
- **RBCK-003 (test rollback)**: Reverting the code fix also requires deleting FILE-004/FILE-005 and reverting the FILE-006 extension, because TEST-002/TEST-003 assert the new contract by design and would stay red against the old code. Deleting or weakening a failing assertion instead of reverting the code is forbidden (Floor-Guard, CON-008).
- **RBCK-004 (no cache/state)**: Nothing caches this summary — `last_message_at` is read live per request by the SLA Timer and the conversation-list query — so a rollback needs no cache flush, no scheduler change, and no worker restart.

## 4. Dependencies

- **DEP-001**: `plan/plan-bugfix-inbox-message-ordering-v1.0.md` — same root event (the 2026-09-25 backlog flush), a different defect (message *display* order vs. conversation *summary* regression). No code dependency between the two fixes: they touch different files/methods (`MessageModel::getByConversation()` there vs. `ConversationModel` plus 3 controller call sites here). Both should be verified together against the same live evidence before either is marked fully resolved, since they share one incident.
- **DEP-002**: `ConversationModel::revive()` (`app/Models/ConversationModel.php:340-354`) — unaffected by this plan (it never touches `last_message_at`), but any future change to `revive()` must keep that true given RBCK-001.
- **DEP-003**: spec REQ-010 (SLA Timer thresholds, `app/Config/Inbox.php`) is the primary downstream consumer motivating REQ-001; this plan does not change threshold values, only the accuracy of the input they read.
- **DEP-004**: PHPUnit `^10.5.16` (already in `require-dev`, `composer.json:21`); no new package is added.
- **DEP-005**: `tests/_support/bootstrap.php` must keep refusing to run when the `inbox` group does not point to `aulia_inboxdb_test` (guarded there, and separately verified by `tests/database/InboxTestDatabaseIsolationTest.php`). This plan depends on that guard staying in place, same as its sibling (CON-007).
- **DEP-006**: No dependency on the WA Gateway repository is introduced. The Gateway's timestamp-accuracy gap (GW-11) remains an external, unresolved dependency this plan does not fix (RISK-001, ESC-002).

## 5. Files Affected

- **FILE-001** (modified): `app/Models/ConversationModel.php` — add `updateLastMessageIfNewer()`.
- **FILE-002** (modified): `app/Controllers/InboxGatewayApi.php` — `messages()` (~line 260-276): route the `last_message_at`/`last_message_direction` write through `updateLastMessageIfNewer()`; `status` and `snoozed_until` (incoming-only) stay unconditional via `$otherFields`.
- **FILE-003** (modified): `app/Controllers/Inbox.php` — `kirimMedia()` (~line 927-932) and `kirimKeConversation()` (~line 2015-2020): same substitution; `last_replied_by`, `last_seen_by_assignee_at`, `assigned_to` stay unconditional via `$otherFields`.
- **Write sites covered by REQ-003** (exactly 3, all inside FILE-002/FILE-003 above): `InboxGatewayApi::messages()` (`app/Controllers/InboxGatewayApi.php:262`), `Inbox::kirimMedia()` (`app/Controllers/Inbox.php:928`), `Inbox::kirimKeConversation()` (`app/Controllers/Inbox.php:2016`).
- **FILE-004** (new): `tests/database/ConversationModelLastMessageAtTest.php` — model contract tests for `updateLastMessageIfNewer()` (TEST-001, TASK-001/002).
- **FILE-005** (new): `tests/session/InboxGatewayLastMessageAtTest.php` — controller-level regression for out-of-order Gateway delivery (TEST-002, TASK-003).
- **FILE-006** (modified): `tests/session/InboxOutgoingIdempotencyTest.php` — extend with the 2 backdated-send regression cases (TEST-003, TASK-004). Both `Inbox.php` send paths already have their controller doubles and reflection helper in this file (`kirimKeConversation` reached at `:69-70`), so extending it reuses those fixtures instead of duplicating them in a new file.
- **FILE-007** (this plan): `plan/plan-bugfix-inbox-last-message-at-monotonic-v1.0.md` — evidence log filled in during execution, `status` updated at the end.
- **Explicitly NOT modified**: every migration file (CON-002), `app/Views/inbox/*`, `app/Config/Routes.php`, `app/Config/Inbox.php` (thresholds unchanged, DEP-003), `app/Models/MessageModel.php` (that is the sibling plan's scope, DEP-001), and `plan/plan-bugfix-inbox-message-ordering-v1.0.md`.

## 6. Testing Strategy & Edge Cases

Two layers are required by the project Testing Policy: micro (the three new/extended suites) and macro (full suite green before the phase is declared complete).

- **TEST-001**: Model contract test — `tests/database/ConversationModelLastMessageAtTest.php` (TASK-001/002). Covers `updateLastMessageIfNewer()` directly, isolated from any controller:
  - `testRejectsOlderTimestamp()`: seed `last_message_at = T`, call with `T - 1 min`; assert both `last_message_at` and `last_message_direction` are **unchanged** (REQ-002) and the method returns `false`.
  - `testAppliesNewerTimestamp()`: seed `T`, call with `T + 1 min` and a different direction; assert both columns move together and the method returns `true`.
  - `testTieIsRejected()`: call with exactly `T`; per REQ-001 a tie is not *newer*, so this MUST behave like the reject case. This is the boundary the guard's `<` operator encodes.
  - `testAcceptsFirstWriteWhenStoredIsNull()`: seed `last_message_at = NULL` (CON-005), call with any timestamp; assert it is always applied.
  - `testOtherFieldsWrittenOnRejectedBranch()` / `testOtherFieldsWrittenOnAppliedBranch()`: pass `$otherFields = ['status' => 'open']` on a rejected **and** an applied call; assert `status` changed both times (CON-001 — the guard must not leak onto unrelated columns).
  - `testEmptyOtherFieldsSkipsSecondUpdate()`: pass `$otherFields = []`; assert no error and no unintended write (guards against calling `update()` with an empty array, a CodeIgniter footgun).
  - `testBumpsUpdatedAtOnAppliedBranch()`: capture `updated_at` before the call, then assert it **advanced** on an applied write (CON-010). This is the regression lock for the raw-builder statement bypassing `$useTimestamps`; without an explicit value the column would silently freeze.
  - `testFullyRejectedCallTouchesNoRow()`: reject the timestamp **and** pass `$otherFields = []`; assert `last_message_at`, `last_message_direction`, and `updated_at` are all unchanged — i.e. the fully-rejected path issues no partial write at all (CON-009: the second statement is only reached when `$otherFields` is non-empty).
- **TEST-002**: Controller-level regression — `tests/session/InboxGatewayLastMessageAtTest.php` (TASK-003). **This is the test guaranteed to fail before the fix**, because it replays the exact backlog-flush scenario from the Introduction: deliver one message with `message_timestamp = T`, then a second message for the same `chat_id` with `message_timestamp = T - 30 min` (the late-arriving backlog item). Assert after both deliveries that `last_message_at = T` and `last_message_direction` matches the first message — **not** silently overwritten by the second, unconditional `UPDATE` that exists today. Also assert the older `incoming` delivery still applied `status = 'open'` and cleared `snoozed_until` (CON-001), proving the guard is scoped to exactly the two columns in REQ-001/REQ-002. Mechanics: instantiate `InboxGatewayApi` directly and call its public `messages()` twice (TASK-003), mirroring the `initController()` setup in `tests/session/InboxOutgoingIdempotencyTest.php`; `GatewayTokenFilter` is a route filter, so calling the controller directly bypasses the token check. Use `message_type = 'text'` so the payload needs no `media` block (hence no prefetch attempt and no outbound Gateway call).
- **TEST-003**: Send-path regression (TASK-004, extends FILE-006 `tests/session/InboxOutgoingIdempotencyTest.php`). Pre-seed `last_message_at` to a value **in the future** relative to the call (a conversation whose last inbound message already advanced the summary past what an in-flight outgoing send would produce), call `Inbox::kirimKeConversation()` and separately `Inbox::kirimMedia()`, then assert `last_message_at`/`last_message_direction` were **not** rolled backward while `last_replied_by`, `last_seen_by_assignee_at`, and `assigned_to` were still written (CON-001).
- **TEST-004**: Conventions to follow, taken from the existing suite:
  - `setUp()` calls `parent::setUp()`, asserts the `inbox` connection resolves to `aulia_inboxdb_test` (`tests/session/InboxOutgoingIdempotencyTest.php:28`), `emptyTable()`s `messages`/`conversations`/`gateway_status`, then re-seeds the `gateway_status` singleton (`id = 1`, `status = 'connected'`, heartbeat/updated timestamps — lines 33-38) and the `db_users` stub rows (lines 39-41) that the controllers resolve staff names from.
  - Private controller methods are reached via reflection (`(new ReflectionClass($controller))->getMethod('kirimKeConversation')->setAccessible(true)`, `tests/session/InboxOutgoingIdempotencyTest.php:69-70`), never by routing an HTTP request.
  - Cast ids with `(int)` / `array_map('intval', ...)` before `assertSame` (driver results are not native ints by default).
  - No suppressions, no skipped tests, no weakened assertions (CON-008 / Floor-Guard).

### Edge Cases Considered

- **Exact tie on `message_timestamp`**: treated as "not newer" and rejected (`testTieIsRejected()`), matching the literal wording of REQ-001. A future requirement to prefer the tie could change `<` to `<=` at the single guard site without touching call sites.
- **`last_message_at IS NULL` (brand-new conversation)**: always accepted as the first write (CON-005, `testAcceptsFirstWriteWhenStoredIsNull()`). This is implemented by the `groupStart() ... ->where('last_message_at', null)->orWhere('last_message_at <', $ts) ... ->groupEnd()` shape in the reference method — a bare `last_message_at < ?` would be a silent no-op for these rows, which is a **live** condition, not a hypothetical one: the 2026-09-25 read-only audit of `aulia_inboxdb` found 1 of 30 `conversations` rows with `last_message_at IS NULL`.
- **Fully-rejected write with no other fields**: when the guard rejects **and** `$otherFields` is empty (the Gateway `outgoing` branch, TASK-008), the method issues no statement at all — so `last_message_at`, `last_message_direction`, and `updated_at` all stay exactly as they were. This is intended: nothing changed, so nothing is written (pinned by `testFullyRejectedCallTouchesNoRow()`). When `$otherFields` is non-empty, `updated_at` still advances via the model's normal `update()` on that second statement (CON-009/CON-010).
- **Reviving a soft-deleted conversation**: `ConversationModel::revive()` clears `deleted_at` but never touches `last_message_at`, so a revived conversation keeps whatever value it had before deletion; this plan's guard then applies normally against that stale-but-real value. Not a regression introduced by this plan — a pre-existing behavior this plan does not change.
- **Internal Notes**: `Inbox::catatanInternal()` never reaches `updateLastMessageIfNewer()` at all (CON-003, spec REQ-009 unchanged) — no interaction with this guard by construction.
- **Outgoing message from staff arriving "in the past" relative to a customer's later incoming message**: correctly rejected like any other stale write; `last_message_direction` therefore keeps reflecting the customer's message, which is exactly what REQ-002 requires (this is conversation 11748 from the Introduction).
- **Two backlog messages with the same `message_timestamp` delivered to two different conversations in the same request burst**: irrelevant to this guard, which always filters by a single `conversation_id`.

### Canonical Drift Query (used for TASK-012's post-fix re-check)

This is the exact, `is_internal`-scoped audit query that produced the Introduction's evidence table, and MUST be re-run verbatim by TASK-012 against `aulia_inboxdb` after the fix ships — the result set MUST NOT grow (the existing 4 rows may shrink via TASK-013, but no new row may appear):

```sql
SELECT c.id AS conversation_id, c.last_message_at AS stored_last_message_at, m.true_latest
FROM conversations c
JOIN (
    SELECT conversation_id, MAX(message_timestamp) AS true_latest
    FROM messages
    WHERE is_internal = 0 AND deleted_at IS NULL
    GROUP BY conversation_id
) m ON m.conversation_id = c.id
WHERE c.last_message_at < m.true_latest;
```

### Evidence Log (filled during execution)

| Step | Command / action | Observed result | Date |
| ---- | ---------------- | --------------- | ---- |
| TASK-005 (Phase 1) | `vendor\bin\phpunit --no-coverage tests/database/ConversationModelLastMessageAtTest.php tests/session/InboxGatewayLastMessageAtTest.php tests/session/InboxOutgoingIdempotencyTest.php` | **RED as required** — `Tests: 21, Assertions: 89, Errors: 9, Failures: 3`. 9 errors = `BadMethodCallException: Call to undefined method App\Models\ConversationModel::updateLastMessageIfNewer` (TASK-002, one per model test). 1 failure = TEST-002 `testLateArrivingBacklogMessageDoesNotMoveLastMessageAtBackward` — `last_message_at` expected `2026-09-25 10:30:00`, actual `2026-09-25 10:00:00` (the backlog flush moves the summary BACKWARD). 2 failures = TEST-003 — both send paths rolled back a future summary: expected `2026-09-25 17:38:23`, actual `2026-09-25 16:38:23` (kirimKeConversation + kirimMedia). `testInOrderDeliveryMovesSummaryForward` stayed GREEN (positive control). | 2026-09-25 |
| TASK-006 (Phase 2 gate) | Explicit user confirmation to enter Phase 2 | Satisfied by the user's explicit pre-approval to execute this plan in full (both sibling plans run in sequence, session 2026-09-25). Red evidence was recorded BEFORE any Phase 2 edit; TASK-013 (live data correction) remains separately approval-gated and was NOT executed. | 2026-09-25 |
| TASK-012 (Phase 2) | `vendor\bin\phpunit --no-coverage tests/database/ConversationModelLastMessageAtTest.php tests/session/InboxGatewayLastMessageAtTest.php tests/session/InboxOutgoingIdempotencyTest.php`, after the fix | **GREEN** — `OK (21 tests, 132 assertions)`. | 2026-09-25 |
| TASK-012 (Phase 2) | `vendor\bin\phpunit --no-coverage` (macro gate, full suite) | **GREEN** — `OK (367 tests, 1295 assertions)`, zero regressions. | 2026-09-25 |
| TASK-012 (Phase 2) | Canonical drift query above, re-run against `aulia_inboxdb` via `mysql.exe` | Row count unchanged: still exactly 4 rows (11745, 11747, 11748, 11750), and every `stored_last_message_at`/`true_latest` value is byte-identical to the Introduction table (e.g. 11747: `2026-09-25 12:31:18` vs `2026-09-25 13:47:15`). Confirms the Phase 2 fix stops NEW drift without silently mutating the pre-existing bad rows — TASK-013 (separately approval-gated) remains required to correct them. | 2026-09-25 |
| TASK-013 (RBCK-002) | User explicitly approved live data correction. Ran 4 guarded `UPDATE conversations SET last_message_at = ?, last_message_direction = ? WHERE id = ? AND last_message_at = <previous stored value>` statements (one per row, guard on the pre-observed stale value so the write is a no-op if the row had already changed underneath us) via `mysql.exe` against `aulia_inboxdb`: 11745 `08:31:35→09:48:37`, 11747 `12:31:18→13:47:15`, 11748 `11:23:51→11:47:54` (direction `incoming→outgoing`), 11750 `10:16:36→13:16:17`. `ROW_COUNT()` returned `1` for each of the 4 statements (no silent no-op, no unexpected multi-row write). | 2026-09-25 |
| TASK-013 (post-fix re-check) | Re-ran the canonical drift query immediately after | **`drift_row_count = 0`**. `SELECT ... WHERE id IN (11745,11747,11748,11750)` confirms all 4 rows now hold the exact true-latest values from the Introduction table. Fully reversible per RBCK-002 (previous values are preserved above in this log). | 2026-09-25 |
| TASK-014 (APPROVAL) | Explicit user confirmation to close the phase and to run the approval-gated data fix | Granted in-session after the evidence above was presented: full suite `OK (367 tests, 1295 assertions)`, targeted suite `OK (21 tests, 132 assertions)`, drift query still exactly the 4 known rows pre-fix, and `drift_row_count = 0` post-fix. Code change and data correction are both complete; this plan is declared **Completed**. | 2026-09-25 |

## 7. Risks & Assumptions

- **RISK-001 (Shared root cause with a sibling plan)**: This defect and the display-ordering defect documented in `plan/plan-bugfix-inbox-message-ordering-v1.0.md` both stem from the same Gateway backlog-flush event delivering messages out of `message_timestamp` order. Fixing this plan does not fix the sibling's display-order symptom and vice versa — they are independent code paths that happen to share one incident. Neither plan may be reported as resolving the other's symptom.
- **RISK-002 (Residual data until the data fix runs)**: Until TASK-013 runs (approval-gated, may be deferred indefinitely), the 4 already-drifted conversations (11745, 11747, 11748, 11750) keep showing an inaccurate SLA color and list position. The Phase 2 code fix prevents **new** drift only; it does not retroactively repair existing bad rows.
- **RISK-003 (Future 4th write site)**: If a future feature adds a 4th place that writes `last_message_at` directly instead of through `updateLastMessageIfNewer()`, the monotonic guarantee silently breaks again for that site. Mitigation: REQ-003 centralizes the write in one method; a reviewer should treat any new direct `update()` touching `last_message_at` as a red flag.
- **RISK-004 (Query-builder caching)**: The guarded write deliberately uses `$this->db->table(...)` rather than the model's cached `builder()` (documented inline in the Phase 2 reference implementation) to avoid stale `WHERE` fragments leaking across calls in one request lifecycle. If this is later "simplified" back to `$this->builder()`, the TEST-001 boundary cases MUST be re-verified.

### Assumptions

- **ASSUMPTION-001**: The 3 write sites enumerated in Section 5 (`InboxGatewayApi::messages()`, `Inbox::kirimMedia()`, `Inbox::kirimKeConversation()`) are the only places that write `conversations.last_message_at`/`last_message_direction`. Verified by repository-wide search on 2026-09-25 (same verification basis as spec REQ-009). The spec lists these sites under older names — `Inbox::kirim()`, "endpoint balas tagihan", `InboxGatewayApi::messages()` — which today resolve to `Inbox::kirim()` delegating to `kirimKeConversation()` (`app/Controllers/Inbox.php:1921`), `Inbox::kirimMedia()`, and `InboxGatewayApi::messages()`; the enumeration is therefore consistent with the spec, not in conflict with it. Any future 4th site must be routed through `updateLastMessageIfNewer()` as well (RISK-003).
- **ASSUMPTION-002**: `messages.message_timestamp` keeps its current meaning and format (`DATETIME`, string-comparable with `<`/`>` in MySQL) and keeps being populated from the Gateway payload, consistent with the sibling plan's assumption on timestamp fidelity. If the Gateway payload format changes, this guard's comparison MUST be re-verified.
- **ASSUMPTION-003**: The 4 drifted conversations identified in the Introduction are exhaustive as of the 2026-09-25 audit, confirmed by re-running the canonical query above on 2026-09-25 (result: exactly 11745, 11747, 11748, 11750). Because the system is live, a fresh run of that query immediately before TASK-013 execution is REQUIRED to catch drift introduced between plan-writing and execution time.
- **ASSUMPTION-004**: The `messages.deleted_at` soft-delete column stays unused in the ingest path today (nothing sets it during normal Gateway delivery), so the canonical drift query's `deleted_at IS NULL` filter is scoped for correctness rather than to exclude live traffic.

## 8. Escalation & Out-of-Scope Follow-up

- **ESC-001 (Data correction, gated)**: TASK-013 corrects the 4 known-drifted conversations. It MUST NOT be executed silently as part of the code deployment — it requires its own explicit user approval at the TASK-014 gate and is recorded (RBCK-002) so it can be reversed (previous values are preserved in the Introduction table).
- **ESC-002 (Shared incident with the sibling plan)**: Cross-reference this plan and `plan/plan-bugfix-inbox-message-ordering-v1.0.md` once both ship, so a future reader understands that a single Gateway event produced two independent, separately-fixed symptoms (RISK-001). Neither plan resolves the underlying Gateway timestamp-accuracy gap (that remaining issue stays tracked in the sibling plan's escalation section).
- **ESC-003 (Periodic drift audit)**: Recommended out-of-scope follow-up: run the canonical drift query (Section 6) on a light schedule to catch any future regression early, instead of relying on user-reported SLA anomalies. This plan introduces no new scheduled task; the recommendation is recorded here for a future backlog item.

