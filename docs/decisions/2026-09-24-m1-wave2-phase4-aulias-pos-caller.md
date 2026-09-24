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

## 4. Owner Decisions Still Open

- **F-01 — real database migration is NOT executed.** `aulia_inboxdb.messages` does not have `gateway_operation_id` yet. The moment kasir traffic runs against the real database with the Phase 4 code active, every successful send would fail with `Unknown column 'gateway_operation_id'`. Because of this, the Phase 4 code must not be used by kasir until the migration is applied to `aulia_inboxdb`; that application is an owner-approved step (same decision point as the Phase 5 deploy, and it is listed in the clarification report F-01 recommendation as one explicit deploy task). CON-014 forbids doing it silently, so it was not done here.
- **F-02 — test database sync.** Resolved for this phase by P-17; the procedure to repeat after this migration lands in the real database is `mysqldump --no-data --routines --triggers aulia_inboxdb | mysql aulia_inboxdb_test` (`docs/ARCHITECTURE.md` §11), which will then be a no-op for this column because the names match.

## 5. Verification Evidence (TASK-020)

Filled in when TASK-020 runs; see §6 for the per-task commit summary.

## 6. TASK-021 Approval Checkpoint

Pending. TASK-021 requires the owner's explicit confirmation, including the suite result recorded in §5, before Phase 5 (TASK-022..TASK-024) may start.
