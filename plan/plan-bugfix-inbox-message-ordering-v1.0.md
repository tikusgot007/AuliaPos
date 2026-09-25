---
goal: Make Inbox message ordering deterministic for messages that share the same message_timestamp
version: 1.0
date_created: 2026-09-25
last_updated: 2026-09-25
owner: AuliaPos Inbox module
status: "Planned"
tags: ["bug-fix", "remediation", "patch", "inbox", "ordering", "gw-11"]
---

# Introduction

![Status: Planned](https://img.shields.io/badge/status-Planned-yellow)

After the WA Gateway reconnects from an outage, the messages that were buffered during the outage do arrive in the Inbox, but they can be displayed in an order that differs from the order in which the customer sent them. This is the user-visible symptom that was previously noted, but not explained, as gap **GW-11** in `docs/GATEWAY-REQUIREMENTS.md:38-42`.

**The diagnosis has two parts, and only one of them is inside this repository.** Part A is the confirmed cause of the reported display order and lives in the WA Gateway (out of scope here, escalated). Part B is a real but currently latent ordering defect in AuliaPos, and Part B is the only part this plan fixes.

**Part B (latent defect, in scope, confirmed in code).** The Inbox reads a conversation's history through `MessageModel::getByConversation()` (`app/Models/MessageModel.php:93-99`). That query is:

```php
return $this->where('conversation_id', $conversationId)
    ->orderBy('message_timestamp', 'ASC')
    ->limit($limit)
    ->findAll();
```

There is **no secondary sort key**. `message_timestamp` is a `datetime` column with second precision (`SHOW COLUMNS FROM aulia_inboxdb.messages`), and the value stored in it is the WhatsApp-origin timestamp forwarded by the Gateway (`app/Controllers/InboxGatewayApi.php:168`, inserted verbatim at `:253`, truncated to `Y-m-d H:i:s` by `parseTimestamp()` at `:396-409`). Two customer messages sent inside the same wall-clock second therefore produce **byte-identical** sort keys, so the query does not define which of them comes first. With no `id` tie-breaker, the ordering of such a pair is undefined **at the query level** — today MariaDB happens to return insertion order, but only as a side effect of the current execution plan (demonstrated below).

**This is not theoretical in the live data.** A read-only audit of `aulia_inboxdb.messages` (2026-09-25) shows 113 rows but only 109 distinct `(conversation_id, message_timestamp)` pairs, i.e. **4 real collisions**:

| conversation_id | message_timestamp   | rows sharing it |
| --------------- | ------------------- | --------------- |
| 11745           | 2026-09-25 08:31:33 | id 261, 262     |
| 11745           | 2026-09-25 08:31:35 | id 264, 265     |
| 11746           | 2026-09-25 11:33:00 | id 207, 256     |
| 11753           | 2026-09-25 09:29:03 | id 240, 241     |

**Why those pairs do not visibly break today.** `EXPLAIN SELECT * FROM messages WHERE conversation_id = 11745 AND deleted_at IS NULL ORDER BY message_timestamp ASC LIMIT 500` reports `key: conversation_id_message_timestamp` with **no `Using filesort`**, so the sort is served by the composite index `(conversation_id, message_timestamp)`. Because InnoDB appends the primary key to every secondary index entry, ties currently come back in `id ASC` order. That is a property of today's execution plan, **not a guarantee of the query**: a different plan, the loss of that index, or any future change to the query would silently reorder those pairs, and no test would catch it (no test covers `getByConversation()` today — see TEST-005). An outage that delivers a burst of buffered messages makes ties far more likely than usual, because the burst arrives faster than one second per message.

**Part A (cause of the reported symptom, out of scope).** Because the render path cannot reorder anything and the arrival order is intact (audit trail below), the only remaining explanation for a thread displayed in the wrong send order is that the `message_timestamp` values themselves do not carry the true send order. That is exactly gap **GW-11**, with **GW-25** as the suspected contributor: `message_timestamp` is supposed to be the real WhatsApp send time, but for part of the traffic the Gateway records an already-shifted time, because Baileys fails to decrypt on the first pass and only succeeds on a retry (`docs/GATEWAY-REQUIREMENTS.md:38-42` and `:55-60`). If a timestamp is genuinely *inverted* (B sent before A but carries a later timestamp), no sorting change in AuliaPos can repair it — the ordering information is already wrong at the source. **Part A MUST be fixed in the WA Gateway repository and MUST NOT be attempted here (see RISK-001).**

**Audit trail: AuliaPos reads and renders messages in the order it receives them.**

- Single read path: `Inbox::apiMessages()` (`app/Controllers/Inbox.php:173-200`) is the only caller of `getByConversation()` in the whole repository, served by `GET /inbox/api/conversations/(:num)/messages` (`app/Config/Routes.php:40`). No thread is rendered server-side from another query.
- No PHP-side reordering: `attachSenderNames()` (`app/Controllers/Inbox.php:496-524`) only decorates rows and returns them in the same order.
- No browser-side reordering: `renderPesan()` (`app/Views/inbox/index.php:1720-1740`) maps the received array as-is; it never sorts or reverses. `tampilkanBubbleOutgoing()` (`:1923-1935`) only appends the local echo of a just-sent outgoing reply and is discarded by the next poll.
- Arrival order is intact: the Gateway drains its buffer in `id` order (`incomingBuffer.js:658-664`, one event at a time in `incomingDelivery.js:113-114`), so `messages.id` equals the true arrival order in AuliaPos.

**Evidence recorded for Part A.** A burst that was sent as `Sjjs, Hhaaa, Hhhah, Hss, Hhsj` was displayed in the Inbox as `Hhaaa, Hhhah, Sjjs, Hhsj, Hss` (`docs/GATEWAY-REQUIREMENTS.md:42`). Since the render path is order-preserving (audit trail above), that permutation must already be present in the `message_timestamp` values forwarded by the Gateway.

**What Part B fixes, concretely.** Adding `id ASC` as the secondary sort key makes the ordering **contractual** instead of accidental: ties then resolve to `messages.id` order — the true arrival order — no matter which execution plan MariaDB chooses, and the guarantee becomes testable. It does **not** repair inverted timestamps, and therefore **does not explain or resolve the post-reconnect display incident**; that remains open against the Gateway (RISK-001).

> [!IMPORTANT]
> Scope statement: this plan fixes one latent ordering defect in one model plus its regression test. It deliberately does **not** claim to fix the reported post-reconnect display order (Part A, WA Gateway), and it does not touch the `message_timestamp` source, the schema, or the Gateway. Sections 7 and 8 record that exposure and the escalation.

## 1. Requirements & Constraints (Fix Constraints)

- **REQ-001**: `MessageModel::getByConversation()` MUST return a deterministic order for every conversation, including when several rows share the same `message_timestamp`.
- **REQ-002**: For rows that share a `message_timestamp`, the order MUST be the row insertion order (`messages.id ASC`), which is the order in which the Gateway delivered them.
- **REQ-003**: The fix MUST be covered by an automated test that FAILS on the current code and PASSES after the fix.
- **REQ-004**: After the fix, the returned set MUST still contain all rows of the conversation up to the existing `limit` (no row lost or duplicated by the sort change).
- **CON-001**: The JSON contract of `Inbox::apiMessages()` (`app/Controllers/Inbox.php:173-200`) MUST NOT change: same keys, same field types, same `status` envelope. No new or renamed field.
- **CON-002**: No schema change and no new migration. The fix is query-level only.
- **CON-003**: `message_timestamp` MUST keep its current meaning (WhatsApp-origin time forwarded by the Gateway). Rewriting it to a Gateway-receipt or CI4-insert time is a different, larger decision and is out of scope here (see RISK-001).
- **CON-004**: Only `app/Models/MessageModel.php` and the new test file may change. `app/Controllers/Inbox.php` and `app/Controllers/InboxGatewayApi.php` MUST stay byte-identical.
- **CON-005**: Floor-guard: no `@group` exclusion, no `markTestSkipped`, no disabled assertion, and no loosened assertion may be used to make the test pass.
- **CON-006**: Tests MUST run on the `inbox` **database group**, redirected to `aulia_inboxdb_test` by `tests/_support/bootstrap.php`, and MUST empty the Inbox tables in `setUp()` exactly like the existing Inbox tests. The green/red signal is `vendor/bin/phpunit --no-coverage` (memory DE-17: bare `composer test` is not the signal). There is no PHPUnit `@group inbox`; the `inbox` name refers to the database group only.
- **CON-007**: `ConversationModel` ordering (`conversations.last_message_at DESC`) is explicitly NOT modified by this plan, even though it carries the same class of defect for the conversation list (`docs/GATEWAY-REQUIREMENTS.md:38` affects both). See RISK-002.
- **CON-008**: This plan MUST NOT claim, in its title, tags, or body, that it resolves the reported post-reconnect display-order incident. That incident is Part A (Gateway timestamp accuracy) and is out of scope (RISK-001).

## 2. Implementation Steps

> **⚠️ EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> You MUST execute this plan phase by phase. You MUST run the specific testing/verification task at the end of each phase. After a phase is tested, you **MUST STOP AND WAIT** for the user's explicit approval before proceeding to the next phase.

### Implementation Phase 1: Test Writing (Test-Driven Bug Fixing)

- **GOAL-001:** Add a regression test suite for `MessageModel::getByConversation()` ordering that (a) locks in the desired behavior for tied timestamps and (b) fails against the current code because the guarantee is not yet part of the query.

| Task     | Description                                                                                                      | Ref ID  | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------ | ------- | :-------: | :--: |
| TASK-001 | Create `tests/database/MessageModelOrderingTest.php`, `inbox` group, following `ConversationHandoffModelTest.php` conventions (`seedConversation()` helper, `emptyTable()` in `setUp()`). | REQ-001 | [ ] | |
| TASK-002 | Add `testTiedTimestampsKeepInsertionOrder()`: insert 5 rows in one conversation with an identical `message_timestamp`, call `getByConversation()`, assert the returned `id` sequence equals the insertion order. | REQ-002 | [ ] | |
| TASK-003 | Add `testQueryOrdersByTimestampThenId()`: call `getByConversation()`, then read the SQL actually executed via `db_connect('inbox')->getLastQuery()`, and assert (with whitespace/case-insensitive matching) that the `ORDER BY` clause is `message_timestamp` ASC followed by `id` ASC. This is the guard that fails on today's code (see Introduction: the composite index currently returns `id ASC` for ties by accident, not by contract — TASK-002 alone could pass without the fix). | REQ-001, REQ-002 | [ ] | |
| TASK-004 | Add `testMixedTiedAndDistinctTimestampsOrderCorrectly()`: 2 rows tied at `T`, then 1 row at `T+1s`, assert the non-tied row still sorts last and the tied pair keeps insertion order. | REQ-002 | [ ] | |
| TASK-005 | Add `testDoesNotLeakRowsFromOtherConversations()` and `testHonoursExistingLimit()` reusing the existing `getByConversation($id, $limit)` contract, to lock REQ-004 (no row lost/duplicated by the sort change). | REQ-004 | [ ] | |
| TASK-00X | **VERIFY**: Run `vendor\bin\phpunit --no-coverage tests/database/MessageModelOrderingTest.php`. TASK-003 MUST FAIL on the current code (TASK-002 MAY pass, because today's execution plan happens to return `id ASC` for ties — see Introduction). Record the exact red output in the plan's evidence log before continuing. | - | [ ] | |
| TASK-00Y | **APPROVAL**: 🛑 Wait for explicit user confirmation to proceed to Phase 2 | - | [ ] | |

### Implementation Phase 2: Minimal Root Cause Remediation

- **GOAL-002:** Make the ordering guarantee explicit in the query itself, without changing anything else.

| Task     | Description                                                                                                       | Ref ID  | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------- | ------- | :-------: | :--: |
| TASK-006 | In `app/Models/MessageModel.php:93-99`, add `->orderBy('id', 'ASC')` immediately after `->orderBy('message_timestamp', 'ASC')` in `getByConversation()`. | CON-002, CON-004 | [ ] | |
| TASK-007 | Update the doc comment directly above `getByConversation()` (`:89-91`) to state the tie-breaker explicitly, so the next reader does not have to rediscover it from the index plan. | CON-004 | [ ] | |
| TASK-00X | **VERIFY**: Run `vendor\bin\phpunit --no-coverage tests/database/MessageModelOrderingTest.php`. All tests MUST PASS. Then run the full suite `vendor\bin\phpunit --no-coverage` (macro-level gate per AGENTS.md Testing Policy) and confirm zero regressions. | - | [ ] | |
| TASK-00Y | **APPROVAL**: 🛑 Wait for explicit user confirmation to proceed | - | [ ] | |

## 3. Rollback Strategy

The change is a single additive `orderBy()` call. Rolling back is safe and does not touch data.

- **RBCK-001**: Revert `app/Models/MessageModel.php` and delete `tests/database/MessageModelOrderingTest.php`. No migration, no schema change, and no data change is involved, so nothing else must be undone.
- **RBCK-002**: Re-run `vendor\bin\phpunit --no-coverage` to confirm the pre-fix baseline (the new test file is gone, so the suite returns to the previous count).
- **RBCK-003**: If only the new guard test (`testQueryOrdersByTimestampThenId()`) becomes a nuisance in the future, remove **that assertion only** — never the behavioral tests. Deleting a failing assertion to make a build pass is forbidden (Floor-Guard).
- **RBCK-004**: No cache, session, or queue state depends on this query, so no cache flush or restart is required after a rollback.

## 4. Dependencies

- **DEP-001**: PHPUnit `^10.5.16` (already in `require-dev`); no new package is added.
- **DEP-002**: MariaDB/MySQL via the `inbox` database group; the sort change is portable ANSI SQL (`ORDER BY a, b`), so no vendor-specific syntax is introduced.
- **DEP-003**: `tests/_support/bootstrap.php` must keep refusing to run when the `inbox` group does not point to `aulia_inboxdb_test` (`tests/database/InboxTestDatabaseIsolationTest.php` guards this). This plan depends on that guard staying in place.
- **DEP-004**: No dependency on the WA Gateway repository is introduced. The Gateway remains an external, unresolved dependency for the actual display-order incident (RISK-001).

## 5. Files Affected

- **FILE-001** (modified): `app/Models/MessageModel.php` — one added `->orderBy('id', 'ASC')` in `getByConversation()` (line ~93-99) and a doc-comment update above it.
- **FILE-002** (new): `tests/database/MessageModelOrderingTest.php` — regression suite for the ordering contract (TASK-001..TASK-005).
- **FILE-003** (this plan): `plan/plan-bugfix-inbox-message-ordering-v1.0.md` — evidence log filled in during execution, `status` updated to `Completed`/`Rejected` at the end.
- **Explicitly NOT modified**: `app/Controllers/Inbox.php`, `app/Controllers/InboxGatewayApi.php`, `app/Views/inbox/index.php`, `app/Models/ConversationModel.php`, and every migration file (CON-004, CON-007).

## 6. Testing Strategy & Edge Cases

Two layers are required by the project Testing Policy: micro (the new suite) and macro (full suite green before the phase is declared complete).

- **TEST-001**: Behavioral contract test — `testTiedTimestampsKeepInsertionOrder()`. Five rows in one conversation share one `message_timestamp`; the assertion is that the returned `id` sequence equals the insertion sequence. This is the test that protects the contract into the future.
- **TEST-002**: Deterministic guard test — `testQueryOrdersByTimestampThenId()`. **This is the only test in the suite that is guaranteed to fail before the fix**, and here is why:

  > The composite index `conversation_id_message_timestamp` currently serves the `ORDER BY` without a filesort, and InnoDB appends the primary key to each secondary index entry, so tied rows already come back in `id ASC` order today. A purely behavioral test therefore passes against the buggy code and can never prove the fix. The guard asserts the *promise* instead of the *accident*.

  Implementation shape (CI4 pins `lastQuery` in `BaseConnection::query()`, `system/Database/BaseConnection.php:811`, so the executed SQL is readable):

  ```php
  (new MessageModel())->getByConversation($conversationId);

  $sql = (string) db_connect('inbox')->getLastQuery();

  $this->assertMatchesRegularExpression(
      '/order by\s+`?messages`?\.?`?message_timestamp`?\s+asc\s*,\s*`?messages`?\.?`?id`?\s+asc/i',
      $sql,
      'getByConversation() must define the tie-break explicitly. Executed SQL: ' . $sql
  );
  ```

  `db_connect('inbox')` returns the same cached connection instance the model uses, and the raw SQL is echoed in the failure message so a future reader can debug without re-running the code. If `getLastQuery()` unexpectedly returns an empty query, the test MUST fail loudly (never be skipped) so the developer can add a probe — see RISK-003.
- **TEST-003**: Mixed-order test — `testMixedTiedAndDistinctTimestampsOrderCorrectly()`. Two rows tied at `T` followed by one row at `T+1`, inserted in that order; assert `[id1, id2, id3]`. This proves the new key did not disturb the primary `message_timestamp` ordering (the property the Inbox UI actually wants).
- **TEST-004**: Isolation test — `testDoesNotLeakRowsFromOtherConversations()`. A second conversation with its own messages must contribute nothing (REQ-004).
- **TEST-005**: Limit test — `testHonoursExistingLimit()`. Seed 5 rows, call `getByConversation($id, 3)`, assert exactly 3 rows and that the **oldest** three are returned (this is the existing contract; the fix must not change which window is returned). Note: no test covered `getByConversation()` before this plan, so this is also the new baseline for the method.
- **TEST-006**: Conventions to follow, taken from the existing suite:
  - `setUp()` must call `parent::setUp()`, connect with `db_connect('inbox')`, and `emptyTable()` the `messages` and `conversations` tables, copied from `tests/database/ConversationHandoffModelTest.php:20-28`.
  - A private `seedConversation()` helper that inserts a `conversations` row and returns `(int) $this->inbox->insertID()`, and a `seedMessage()` helper that inserts a `messages` row — both mirroring the existing `seedConversation()`/`seedMessage()` style.
  - Cast ids with `array_map('intval', ...)` before `assertSame` (memory DE-16: driver results are not native ints by default).
  - Do not echo anything from the tests (`beStrictAboutOutputDuringTests="true"`), and always assert — a test without assertions is "risky" and fails the run (`failOnRisky="true"`).

### Edge Cases Considered

- **Tie exactly on the `limit` boundary**: with `limit = 3` and four tied rows, the returned *set* is now stable instead of arbitrary. Asserted indirectly by TEST-005 plus TEST-001.
- **Ties across a soft-deleted row**: `MessageModel` uses `$useSoftDeletes = true`, so `deleted_at IS NULL` is part of the query. Ties between surviving rows still resolve by `id`; no new behaviour is introduced for deleted rows (they stay invisible).
- **Internal notes tied with customer messages**: `Inbox::catatanInternal()` writes `message_timestamp = now()`; a note created in the same second as an incoming message is now ordered by insertion rather than randomly.
- **Genuinely inverted timestamps** (Part A / GW-11): NOT addressed. A row whose `message_timestamp` is earlier than a row inserted later will still be displayed earlier. This is a documented residual, not an oversight — see RISK-001.
- **Empty conversation**: must return `[]` (existing behaviour, asserted by TEST-004's second conversation before seeding).
- **Equal timestamps in different conversations**: irrelevant to this query because it always filters by `conversation_id`.

### Evidence Log (filled during execution)

| Step | Command / action | Observed result | Date |
| ---- | ---------------- | --------------- | ---- |
| TASK-00X (Phase 1) | `vendor\bin\phpunit --no-coverage tests/database/MessageModelOrderingTest.php` | _pending — must show TASK-003 red_ | |
| TASK-00X (Phase 2) | same command after the fix | _pending — must be green_ | |
| Macro gate | `vendor\bin\phpunit --no-coverage` | _pending — must be green, zero regressions_ | |

## 7. Risks & Assumptions

- **RISK-001 (Critical, out of scope — the actual incident)**: The reported post-reconnect display order is caused by the Gateway forwarding `message_timestamp` values that do not reflect the true send order (**GW-11**, contributor **GW-25** in `docs/GATEWAY-REQUIREMENTS.md:38-42`, `:55-60`). **This fix does not resolve that symptom, and must not be reported as if it does (CON-008).** Mitigation: escalate to the WA Gateway repository (ESC-001); do not mark GW-11/GW-25 resolved on the basis of this plan.
- **RISK-002 (Open, same class, different query)**: `ConversationModel` orders the conversation list by `last_message_at DESC` with no tie-breaker, so two conversations updated in the same second can swap positions between polls. Out of scope here by CON-007; recommended follow-up in ESC-003.
- **RISK-003 (Test brittleness)**: TEST-002 is white-box — it asserts the SQL text. If a future CodeIgniter upgrade changes SQL formatting or `getLastQuery()` semantics, the test can fail for the wrong reason. Mitigation: the regex tolerates backticks, a `messages.` prefix, and whitespace variations; the failure message prints the executed SQL; if the guard ever has to be dropped, drop it deliberately (RBCK-003) and never silently.
- **RISK-004 (Assumption about `id` monotonicity)**: The tie-breaker is only meaningful because `messages.id` is a `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` (verified 2026-09-25 via `SHOW COLUMNS FROM aulia_inboxdb.messages`), i.e. strictly increasing insertion order that matches Gateway delivery order. If a future migration changes the primary key or bulk re-inserts rows, this assumption MUST be re-verified.
- **RISK-005 (Limit interaction)**: When `limit` truncates inside a tie group, the rows that make the cut are now *determined* instead of arbitrary. The window still contains the oldest messages, so no correctness loss; the visible effect is that the thread no longer reshuffles between polls.
- **RISK-006 (Performance)**: Two sort keys may move the plan from an index-ordered read to a filesort for conversations with many rows. Mitigation: the single call site caps at 500 rows; run `EXPLAIN` before and after the fix, record both plans in the evidence log, and treat a slowing regression as a separate index/design decision (out of scope by CON-002).

### Assumptions

- **ASSUMPTION-001**: `Inbox::apiMessages()` (limit 500, `app/Controllers/Inbox.php:188`) stays the only consumer of `getByConversation()`. Verified by repository-wide search on 2026-09-25; the fix lives inside the model, so any future consumer inherits it.
- **ASSUMPTION-002**: The Inbox thread is rendered exclusively from that endpoint — no server-side thread render from another query, and no client-side re-sorting (verified in the audit trail in the Introduction).
- **ASSUMPTION-003**: `message_timestamp` keeps its current meaning (WhatsApp-origin time forwarded by the Gateway). If the Gateway later forwards a receipt time or a sequence number instead, the ordering semantics change with it and this plan becomes obsolete rather than wrong.

## 8. Escalation & Out-of-Scope Follow-up

- **ESC-001 (Gateway, blocks the real incident)**: Open a bug report in the WA Gateway repository for **GW-11**, carrying this evidence: (a) the recorded permutation `Sjjs, Hhaaa, Hhhah, Hss, Hhsj` → `Hhaaa, Hhhah, Sjjs, Hhsj, Hss`; (b) AuliaPos's read/render path is order-preserving and the Gateway drains its buffer in `id` order, therefore the permutation must already exist in the forwarded `message_timestamp` values; (c) the suspected contributor is the failed-decrypt/retry path (**GW-25**, `docs/GATEWAY-REQUIREMENTS.md:55-60`). Success criterion: `message_timestamp` for a buffered burst reflects the true WhatsApp send time.
- **ESC-002 (Gateway API contract, needs a spec)**: If timestamps cannot be made reliable, the payload needs a monotonic sequence or receipt time so consumers can order messages even when a timestamp arrives inverted. That is an interface change and MUST go through `/sdlc-define-specs`, not through a bug fix in this repository.
- **ESC-003 (AuliaPos follow-up)**: A small plan for `ConversationModel` list ordering (RISK-002), same pattern as this fix (explicit tie-breaker plus test).
- **ESC-004 (Documentation hygiene)**: **GW-11/GW-25 must not be marked resolved** because of this plan. When this fix ships, add a short cross-reference in `docs/GATEWAY-REQUIREMENTS.md` stating that AuliaPos now guarantees deterministic ordering *within equal timestamps* only, and that the timestamp-accuracy gap remains open in the Gateway.
