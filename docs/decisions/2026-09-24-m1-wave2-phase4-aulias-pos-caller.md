# M1 Wave 2 — Phase 4 Execution: AuliaPos Caller Side (2026-09-24)

## 1. Status and Boundaries

Phase 4 covers only TASK-015 through TASK-021 of the approved implementation plan. Phase 5 (TASK-022 live Gateway deploy, TASK-023 real AC-027/AC-042 measurement, TASK-024 handoff) was not started. TASK-021 remains an explicit owner approval checkpoint.

The WA-Gateway repository was not touched in this phase: neither the isolated worktree `C:\projects\WA-Gateway-m1w2` (left at `4010cc1`) nor the live folder `C:\projects\WA-Gateway` (read-only at `21a4cb6`). No `pm2` action was performed.

The plan front matter remains `status: 'Planned'`, as required until TASK-024. Nothing was pushed to any remote.

| Item | Verified state |
| --- | --- |
| AuliaPos work branch | `feature/m1-wave2-outgoing-idempotency` |
| Branch basis (recorded HEAD at branch time) | `f2b4f2c` |
| Working copy before branching | clean (`git status --short` empty) |
| Test baseline before any change | `OK (328 tests, 1102 assertions)` |
| Real Inbox database `aulia_inboxdb` | untouched; no `php spark migrate` ran against it |
| Real Inbox test database `aulia_inboxdb_test` | schema synced with the new column + UNIQUE index (see P-17) |
| Gateway worktree / live folder | untouched |

The branch basis is `f2b4f2c` (`docs(m1-wave2): Phase 4 handoff + Phase 5 preflight blocker`) rather than `4fba319`: the Phase 4 handoff document was committed by the preceding session as its own docs commit, and the branch was created from the then-current HEAD. That commit is documentation-only and contains no code, schema, or test change.

## 2. TASK-015 Preflight, M3 Collision Guard, and RISK-002 Ordering

### 2.1 Preflight evidence

- `git status --short` was empty before the branch was created (the Phase 4 handoff document had already been committed by the preceding session, so no stray untracked file was carried into the branch).
- `git switch -c feature/m1-wave2-outgoing-idempotency` was run from `f2b4f2c`; `git worktree list` shows this working copy on that branch and no second worktree.
- Commit order note: the branch action itself produces no commit, and at that moment there was no new file to record it, so this TASK-015 record commit lands immediately after the TASK-016 code commit. This is the only intentional deviation from strict one-task-one-commit ordering; both tasks keep separate commits.

### 2.2 M3 collision guard

| Check | Result |
| --- | --- |
| Branches in the repository | `v2.1`, `v2.2`, `v2.3` (+ remotes); no M3 work-in-progress branch, no second worktree |
| Last commits touching `app/Controllers/Inbox.php` / `app/Views/inbox/index.php` | `7f2d82b` (copy fix) and `db7f301` (M3 Fase 1d), both already merged into `v2.3` before this branch |
| M3 Fase 1e (GH-010) state | Spec-only (rev 1.3): no plan document and no code, so nothing is in flight on the shared files |
| Concurrent modification of the shared files | None observed; all edits stayed inside the CON-012 files plus the two documented boundary notes P-19/P-20 |

### 2.3 RISK-002 ordering decision (K-03 option (a))

- **Merge target:** `v2.3`, the branch this work started from.
- **Boundary:** the ordering gate is satisfied once TASK-020's verification passes and TASK-021 is explicitly approved; M3 Fase 1e may then start its `/sdlc-plan-tasks` and code work **on top of the merged result**.
- **Merge bookkeeping:** the merge is executed by the owner (or by an explicitly instructed session) with `git merge --ff-only` when the branch can fast-forward, otherwise `--no-ff`, and `git status --short` must be empty immediately before it (CON-011 pattern).
- **Why this order:** M1 Wave 2 is the smaller change with an already PROCEED-ed spec, its additive migration is not used by Fase 1e, and the physical overlap is regional — M1 Wave 2 touches `kirimKeConversation()`/`callGatewaySend*()` and the reply form, while Fase 1e touches `apiConversations()` and the conversation list/search. Leftover conflicts, if any, are resolved by keeping both sides (CON-012).
- **Explicit non-actions:** no rebase, no merge, and no branch deletion was performed; the branch is left intact for TASK-021/TASK-024.

## 3. Execution Boundary Notes

These record the implementation decisions taken while executing the plan: either they touch files beyond the literal CON-012 list, or they were discovered from measured behaviour.

### P-17 — Test database sync mechanics (F-02), and what was deliberately not executed

- **Measured:** `php spark` cannot run in the testing environment in this project. `CI_ENVIRONMENT=testing php spark migrate:status --dbgroup inbox` aborts with `Undefined constant "CodeIgniter\Config\SUPPORTPATH"` (`vendor/codeigniter4/framework/system/Config/AutoloadConfig.php:147`). F-02 option (a) is therefore not executable as written.
- **Measured:** in the development environment, `php spark migrate:status --dbgroup inbox` reports every Inbox migration as already recorded in the **default** database history (`aulia_kasirdb`), matching `docs/ARCHITECTURE.md` §11 (`php spark migrate` cannot build the test database). Running spark with the `inbox` group would target the real `aulia_inboxdb` (CON-014 violation) while still leaving `aulia_inboxdb_test` drifted.
- **Decision:** the identical DDL was applied to `aulia_inboxdb_test` only (`VARCHAR(64) NULL AFTER send_status` plus `ADD UNIQUE KEY uniq_messages_gateway_operation_id (gateway_operation_id)`). The migration file stays the single artifact for the real database, and `GatewayOperationIdMigrationTest` proves that the artifact itself reproduces the schema through a `down()` → `up()` round trip. This is F-02 option (b), with the option (a) premise refuted by measurement.
- **Not executed:** migrating `aulia_inboxdb` itself (F-01 stays open, owner decision required — see §4).

### P-18 — Forge state and the field-name cache

The first implementation of `up()` produced `ALTER TABLE messages ADD UNIQUE KEY ... ()`. Root cause (reproduced with a scratch script against the test database, then removed):

- `BaseConnection::fieldExists()` resolves through `getFieldNames()`, which caches field names per connection, so it reports stale data after a DDL statement in the same request (it still answered "yes" right after `down()` dropped the column).
- `Forge::addColumn()` calls `reset()`, clearing the Forge field/key state, and `Forge::processIndexes()` only creates keys for columns known to the same Forge instance.

The migration now verifies existence through `information_schema` (parameter-bound queries) and declares the column with `addField()` before `addKey()`/`processIndexes()`. Both `down()` and `up()` were re-verified end to end against the test database, and the resulting schema was confirmed with `SHOW COLUMNS` / `SHOW INDEX`.

### P-19 — `MessageModel::$allowedFields` must list the new column

CodeIgniter's `Model::insert()` silently drops data for fields absent from `$allowedFields`, which would leave `gateway_operation_id` permanently NULL and make the AC-041 dedupe ineffective. One additive line (`'gateway_operation_id',`) was added to `app/Models/MessageModel.php`. `apiConversations()`, `ConversationModel`, and `InboxGatewayApi` remain untouched.

### P-20 — Where `operation_id` is read (CON-012 footprint)

`operation_id` is read from the kasir AJAX request inside `kirimKeConversation()` itself, so `kirim()` and `mulaiPercakapan()` need no change. For the media endpoint, `kirimMedia()` gains the minimal read-and-forward so the value can reach `callGatewaySendMedia()`; this is required by the plan's own directive ("Teruskan `operation_id` ke `callGatewaySend()` dan `callGatewaySendMedia()`") and is the smallest edit that satisfies it. No server-side key generation is added anywhere (REQ-039/A-4).

### P-21 — Response contract for the ambiguous states (K-15 (i))

`kirimKeConversation()` answers `200` + `status:'error'` + `error_code`/`state`/`replayed` for `SEND_IN_PROGRESS` and `SEND_UNRESOLVED`, with a message stating that the result is uncertain, and the same shape (plus a `log_message('error', ...)` entry) for `OPERATION_ID_REUSED`. All pre-existing response keys keep their meaning, so the generic failure path and current UI behaviour for other errors are unchanged; `NOT_CONNECTED` and `DEAD_LETTERED` stay on the ordinary failure path per TASK-018.

### P-22 — Test seams

`callGatewaySend()` and `callGatewaySendMedia()` change from `private` to `protected` (visibility only, no behaviour change) so a test-only controller double in `tests/_support/Controllers/` can be routed through the framework's `withRoutes()` and the real `kirimKeConversation()` flow can be exercised against the real Inbox schema with the Gateway call stubbed. The Gateway response mapping is tested separately through reflection, following the existing `tests/unit/InboxResponseStateManualTest.php` idiom.

### P-23 — One shared operation-ID lookup for both send paths (F-03 fix)

The dedupe lookup moved into `Inbox::findMessageByOperationId(?string $operationId): ?array`, called by `kirimKeConversation()` and `kirimMedia()`. Two deliberate choices: (1) it lives in ONE place so the two paths cannot drift apart again — a duplicated copy in each path is precisely how F-03 happened; (2) it deliberately does **not** filter `deleted_at`, because `UNIQUE uniq_messages_gateway_operation_id` also covers soft-deleted rows, so a soft-deleted row holding the key must be returned rather than re-inserted (inserting would be rejected by the database).

The media controller test needed a test seam: `UploadedFile::isValid()` is `is_uploaded_file($path) && $error === UPLOAD_ERR_OK`, and `is_uploaded_file()` is always false under CLI, so `kirimMedia()` would reject every fixture. `controllerForMedia()` therefore injects a `FileCollection` (via reflection on the request's `files` property) holding `InboxTestUploadedMedia`, a test-only subclass that relaxes **only** `isValid()`; the fixture itself is a real minimal 1x1 PNG so `finfo` still detects `image/png` through the untouched framework code path.

## 4. Owner Decisions (F-03 resolved 2026-09-24; F-01 deferred to Phase 5)

- **F-01 — real database migration is NOT executed.** `aulia_inboxdb.messages` does not have `gateway_operation_id` yet. The moment kasir traffic runs against the real database with the Phase 4 code active, every successful send would fail with `Unknown column 'gateway_operation_id'`. Because of this, the Phase 4 code must not be used by kasir until the migration is applied to `aulia_inboxdb`; that application is an owner-approved step (same decision point as the Phase 5 deploy, and it is listed in the clarification report F-01 recommendation as one explicit deploy task). CON-014 forbids doing it silently, so it was not done here. **DECISION (2026-09-24, owner): option (i) — this becomes part of an extended TASK-022 rather than a new task row.** The AuliaPos merge + migration + smoke test are therefore executed as an additional, explicitly listed sub-step of TASK-022 in Phase 5, in the order backup -> migrate (`aulia_inboxdb` + `SHOW COLUMNS`/`SHOW INDEX` proof) -> re-sync `aulia_inboxdb_test` -> merge/ deploy the AuliaPos code -> one text and one media smoke test. Nothing of that ran in this phase; Phase 5 is still gated behind TASK-021.
- **F-02 — test database sync.** Resolved for this phase by P-17; the procedure to repeat after this migration lands in the real database is `mysqldump --no-data --routines --triggers aulia_inboxdb | mysql aulia_inboxdb_test` (`docs/ARCHITECTURE.md` §11), which will then be a no-op for this column because the names match.
- **F-03 — media replay path was NOT deduplicated (RESOLVED 2026-09-24; owner decision (a): extend TASK-017).** TASK-017 names `Inbox::kirimKeConversation()` for the "look up by `gateway_operation_id` before inserting" step, so the dedupe landed there only. `Inbox::kirimMedia()` still inserts unconditionally with `gateway_operation_id` filled in (`app/Controllers/Inbox.php:886-904`). The TASK-019 UI deliberately reuses the same key when the cashier retries media, so this sequence is reachable: media send times out -> Gateway actually delivered -> cashier retries with the same key -> Gateway answers `200` with `replayed:true` -> AuliaPos attempts a second insert with the same non-`NULL` value -> `UNIQUE KEY uniq_messages_gateway_operation_id` rejects it. Because the `inbox` DB group has `DBDebug = true`, that raises a `DatabaseException` and the request fails with `500` instead of returning the already-stored row. Two facts were verified rather than assumed: UNIQUE rejection is proven by `tests/database/GatewayOperationIdMigrationTest.php`, and the unconditional insert is plain code at the line range above. This is inside Fase 4's allowed files but outside TASK-017's literal wording, and this session was explicitly instructed not to create new tasks, so it was **not** changed. Recommended options: (a) owner approves a small follow-up commit that mirrors the `kirimKeConversation()` dedupe block inside `kirimMedia()` plus a controller test, or (b) owner records it as a known limitation that must be closed before AuliaPos handles real media retries. Coverage note: Gateway-side idempotency (E-O1) still prevents a second WhatsApp delivery; only AuliaPos's response handling is affected. **Resolution:** the dedupe lookup was extracted into a single shared `findMessageByOperationId()` helper (boundary note P-23) and is now called by BOTH send paths, so they cannot drift apart again; a controller test (`testMediaSendDeduplicatesReplayWithoutSecondInsert`) covers the media replay. Commit `94845a0`. Red-check evidence: with the helper temporarily neutralised, that test (and the text replay test) fail with `mysqli_sql_exception: Duplicate entry ... for key 'wa_message_id'` routed through `DatabaseException` — which is exactly the HTTP 500 failure mode predicted above, so the new test genuinely guards the defect. Suite after the fix: `OK (349 tests, 1209 assertions)`; the AC-040/AC-045 harness still reports 24 PASS / 0 FAIL, i.e. the Gateway payload is unchanged.

## 5. Verification Evidence (TASK-020)

### 5.1 Full suite (boundary)

`vendor/bin/phpunit --no-coverage` (PHPUnit 10.5.64, PHP 8.2.12, config `phpunit.dist.xml`):

| Item | Value |
| --- | --- |
| Recorded pre-change baseline (branch time) | `OK (328 tests, 1102 assertions)` |
| Final result after TASK-015..TASK-019 | **`OK (348 tests, 1197 assertions)`** |
| Delta | +20 tests, +95 assertions |
| Failures / errors / skips / incomplete | 0 / 0 / 0 / 0 |
| Plan minimum (TASK-020 item 3) | satisfied (>= 324 tests, >= 1097 assertions, plus new tests) |

No suppression, skip, `markTestIncomplete`, or removed assertion exists in the new test files; a scan for `markTestSkipped|markTestIncomplete|->skip(|@group|eslint-disable|noqa|ts-ignore` across all five new test files returned nothing.

### 5.2 AC-040 and AC-045 against a real HTTP listener

AC-040 cannot be proven by mocking, because `callGatewaySend()`/`callGatewaySendMedia()` build the JSON body inline and post it with cURL. A throwaway harness (gitignored `build/`, not part of the application) was therefore used:

```text
php -S 127.0.0.1:8792 build/ac040-router.php      # separate process
php build/scratch-ac040-verify.php 8792
== 24 PASS, 0 FAIL ==
```

`build/ac040-router.php` records every request (path, `Authorization`, raw body) and answers with canned Gateway responses; `build/scratch-ac040-verify.php` drives the unmodified controller methods through reflection and asserts the recorded bodies.

| AC | Assertion | Result |
| --- | --- | --- |
| AC-040 | Endpoint still `/send` and `/send-media` | PASS |
| AC-040 | `Authorization: Bearer <token>` still sent | PASS |
| AC-040 | Text payload with **no** `operation_id` is byte-identical to the pre-change payload: `{"chat_id":"...","text":"Hello"}` | PASS |
| AC-040 | Text payload **with** `operation_id` is the same object plus one appended field | PASS |
| AC-040 | Media payload with **no** `operation_id` is byte-identical (same keys, order, and `json_encode` escaping) | PASS |
| AC-040 | Media payload **with** `operation_id` is the same object plus one appended field | PASS |
| AC-045 | `SEND_IN_PROGRESS` -> `ok=false`, `error_code`, `state='in_flight'`, `replayed=false`, `http_code=409` | PASS |
| AC-045 | `SEND_UNRESOLVED` -> `error_code`, `state='failed'`, `http_code=504` | PASS |
| AC-045 | `OPERATION_ID_REUSED` -> `error_code`, `state='sent'` | PASS |
| AC-045 | Replayed success -> `ok=true`, `replayed=true`, `state='sent'` | PASS |
| AC-045 | Same keys for the `/send-media` path | PASS |

> [!NOTE]
> The first harness run reported 2 failures in the media payload assertions. That was a defect in the harness literal, not in the application: `json_encode()` escapes `/` as `\/` (both before and after this change, since neither call site altered its encoding flags). After correcting the expected literal, all 24 assertions pass.

### 5.3 AC-041 and AC-044 (controller + database)

Covered by `tests/session/InboxOutgoingIdempotencyTest.php`: `testSuccessfulSendForwardsOperationIdAndDeduplicatesReplay()` proves a replayed send returns the **existing** row (same `id`, same `gateway_operation_id`, `replayed: true`) and that exactly one row exists for that key; `testSendWithoutOperationIdDoesNotCreateServerSideKey()` proves a send without `operation_id` stores `NULL` and creates no server-side key (AC-044). The schema half of AC-041 (column exists, UNIQUE rejects non-`NULL` duplicates, many `NULL`s accepted) is covered by `tests/database/GatewayOperationIdMigrationTest.php` and `tests/database/InboxOutgoingOperationIdTest.php`.

### 5.4 AC-046 (render evidence)

`tests/session/InboxOutgoingIdempotencyScreenTest.php` (4 tests) proves the shipped page contains the client-owned key slot (`<form id="formBalas" data-operation-id="">`), the persistent uncertain-result element (`id="statusKirimBalasan"`), the `crypto.randomUUID()` generator with its `Math.random` hex fallback, `operation_id` in both the urlencoded text request and the media `FormData`, and both Gateway rejection branches (`SEND_IN_PROGRESS`/`SEND_UNRESOLVED`, `OPERATION_ID_REUSED`). The key lifecycle itself is exercised by `tests/js/operation-id-composer.check.js` (`node tests/js/operation-id-composer.check.js` -> all cases pass), which checks create, reuse-on-retry, discard-on-success/content-change, rotate-on-`OPERATION_ID_REUSED`, and that the ambiguous state keeps the key and does not advise a blind resend.

### 5.5 Footprint check (CON-012 / CON-013)

`git --no-pager diff --name-status 4fba319..HEAD` returns exactly:

```text
M  app/Controllers/Inbox.php
A  app/Database/Migrations/2026-09-24-000001_AddGatewayOperationIdToMessages.php
M  app/Models/MessageModel.php
M  app/Views/inbox/index.php
A  docs/decisions/2026-09-24-m1-wave2-phase4-aulias-pos-caller.md
A  docs/handoff-m1-wave2-fase4-write-code-2026-09-24.md
A  tests/database/GatewayOperationIdMigrationTest.php
A  tests/database/InboxOutgoingOperationIdTest.php
A  tests/js/operation-id-composer.check.js
A  tests/session/InboxOutgoingIdempotencyScreenTest.php
A  tests/session/InboxOutgoingIdempotencyTest.php
```

`Inbox.php` hunks fall only in `kirimMedia()`, `kirimKeConversation()`, `callGatewaySend()`, `callGatewaySendMedia()`, and the new `gatewayFailureResponse()`; no hunk touches `apiConversations()`. The only changed declarations are the two send helpers (`private` -> `protected`, plus the optional `$operationId` parameter) and the new private helper. A search for `InboxGatewayApi|ConversationModel|GatewayStatusModel` across the changed-file list returns nothing, confirming `apiConversations()`, `ConversationModel`, and `InboxGatewayApi` are unchanged.

### 5.6 Cleanup notes

- No `php spark migrate` was executed against `aulia_inboxdb` in this phase; only `aulia_inboxdb_test` (CON-014). F-01 still blocks real-database use.
- The two `build/` harness files are gitignored throwaway verification tooling; they are kept so the AC-040/AC-045 evidence can be regenerated with the command in §5.2. `build/ac040-capture.json` is only a run artifact.

## 6. TASK-021 Approval Checkpoint

### 6.1 Commit summary (one small commit per code task)

| Task | Commit | Subject | Files |
| --- | --- | --- | --- |
| TASK-015 | `8bc4a8e` | `docs(m1-wave2): TASK-015 preflight branch and M3 guard` | this decision log |
| TASK-016 | `5ce8efb` | `feat(m1-wave2): TASK-016 add messages gateway operation ID` | migration + `tests/database/GatewayOperationIdMigrationTest.php`, `tests/database/InboxOutgoingOperationIdTest.php`, `app/Models/MessageModel.php` |
| TASK-017 | `f56446b` | `feat(m1-wave2): TASK-017 forward and deduplicate outgoing operation ID` | `app/Controllers/Inbox.php` (send methods), `tests/session/InboxOutgoingIdempotencyTest.php` |
| TASK-018 | `0c53e1c` | `feat(m1-wave2): TASK-018 handle gateway response states` | `app/Controllers/Inbox.php` (`gatewayFailureResponse()` + response mapping), controller tests |
| TASK-019 | `ef98533` | `feat(m1-wave2): TASK-019 reuse client operation ID in reply form` | `app/Views/inbox/index.php` (reply form + JS), `tests/session/InboxOutgoingIdempotencyScreenTest.php`, `tests/js/operation-id-composer.check.js` |
| TASK-020 + TASK-021 | this record commit (`git log -1 --format=%h`) | `docs(m1-wave2): TASK-020 verification evidence + TASK-021 checkpoint` | this decision log |
| TASK-017 expansion (F-03 fix) | `94845a0` | `fix(m1-wave2): deduplicate replayed media sends (F-03)` | `app/Controllers/Inbox.php` (`kirimMedia()` + shared `findMessageByOperationId()`), `tests/session/InboxOutgoingIdempotencyTest.php` |

The TASK-015 record commit lands after the TASK-016 code commit for the reason already stated in §2.1; every other task has exactly one commit, except TASK-017 which additionally received the F-03 follow-up fix commit `94845a0` after the owner's decision. That follow-up is a separate commit by design (it was not folded into the TASK-017 commit). Nothing was squashed and nothing was pushed.

### 6.2 What is being asked

- **Stop here.** TASK-021 is an explicit owner-approval gate. Phase 5 was not started: no WA-Gateway action, no `pm2` restart, no real AC-027/AC-042 measurement, no live-database migration, no `git push`, and no front-matter change to `plan/plan-process-m1-wave2-outgoing-idempotency-v1.0.md` (still `status: 'Planned'`).
- **Suite result for the decision:** `OK (349 tests, 1209 assertions)` after the F-03 fix — 0 failures, 0 errors, 0 skips (the pre-fix boundary was `348 / 1197`; see §5.1 and §7).
- **Both decisions are now resolved:**
  1. **F-03** (§4): **resolved** — owner chose option (a), implemented as an extension of TASK-017 (`94845a0`) with a controller test and a red-check.
  2. **F-01** (§4): **decided as option (i)** — the real-`aulia_inboxdb` migration plus the AuliaPos merge/deploy/smoke test become additional sub-steps of TASK-022, executed in Phase 5. Until they run, the Phase 4 code must not receive kasir traffic.
- After approval, per the plan: TASK-022 deploys the Gateway to the live folder, TASK-023 runs the real AC-027/AC-042 measurement (needs explicit approval to pause/slow the active Gateway), and TASK-024 closes the plan (`status: 'Planned'` -> `'Completed'`) and offers a `memory-manager` checkpoint. RISK-002 follow-up: once this branch is merged into `v2.3`, M3 Fase 1e may start its plan/code work on top of the merged result.

## 7. Addendum — Owner Decisions on F-03 and F-01 (2026-09-24)

The owner reviewed the open items in §4 and answered before Phase 5 was opened. This section records what was done with each answer.

| Item | Owner answer | Effect |
| --- | --- | --- |
| F-03 | option **(a)**, recorded as an **extension of TASK-017** (no new task row) | Implemented: shared `findMessageByOperationId()` helper called by both send paths, plus `testMediaSendDeduplicatesReplayWithoutSecondInsert`. Commit `94845a0`. See P-23 for the boundary notes. |
| F-01 | option **(i)**, expand **TASK-022** | Recorded only. The AuliaPos merge + `aulia_inboxdb` migration + smoke test become listed sub-steps of TASK-022 and run in Phase 5. Nothing was executed here. |

### 7.1 F-03 fix — evidence

- **Change:** `kirimMedia()` now returns the already-stored row (`replayed: true`) when the same `operation_id` was stored before, mirroring `kirimKeConversation()`. The lookup is one shared private helper, so the two paths cannot drift apart again.
- **Boundary/scope:** only `app/Controllers/Inbox.php` (send path) and `tests/session/InboxOutgoingIdempotencyTest.php` changed; `apiConversations()`, `ConversationModel`, `InboxGatewayApi`, and the reply-form UI were untouched.
- **Green:** full suite `OK (349 tests, 1209 assertions)` (was `348 / 1197`).
- **Red-check (proves the test guards the defect):** with the helper temporarily neutralised, `testMediaSendDeduplicatesReplayWithoutSecondInsert` and the text replay test both error with `mysqli_sql_exception: Duplicate entry ... for key 'wa_message_id'`, surfaced through `DatabaseException` — the same failure mode as the predicted HTTP 500. The temporary change was reverted and the marker scanned for afterwards (none left).
- **No regression in the Gateway contract:** the AC-040/AC-045 harness was re-run after the refactor and still reports `24 PASS, 0 FAIL`, so the `/send` and `/send-media` payloads are byte-identical to before.

### 7.2 Still open after this addendum

- **TASK-021 approval is still pending.** Only the F-03 remediation was authorised; Phase 5 (TASK-022, including the F-01 sub-steps, TASK-023 measurement, TASK-024 closure) has not started.
- No `git push`, no WA-Gateway action, no `pm2`, no migration against `aulia_inboxdb`, and the plan front matter is still `status: 'Planned'`.

No further code, migration, or test change will be made until the owner approves TASK-021.
