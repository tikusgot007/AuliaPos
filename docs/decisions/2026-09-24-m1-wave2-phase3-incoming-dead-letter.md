# M1 Wave 2 — Phase 3 Execution: Incoming Dead-Letter and Rejection Classification (2026-09-24)

## 1. Status and Boundaries

Phase 3 covers only TASK-011 through TASK-014 from the approved implementation plan. TASK-011 and TASK-012 are implemented and committed in the isolated WA-Gateway worktree. TASK-013 passed its verification gate. TASK-014 remains an explicit owner approval checkpoint; Phase 4 and Phase 5 were not started.

The AuliaPos application code was not changed. The only AuliaPos artifacts touched in this phase are the implementation plan completion fields and this decision log. The plan front matter remains `Planned`, as required until TASK-024.

| Item | Verified state |
| --- | --- |
| Gateway worktree | `C:\projects\WA-Gateway-m1w2` |
| Gateway branch | `feature/m1-wave2-outgoing-idempotency` |
| Initial verified HEAD | `e0f5585` |
| Final phase HEAD | `4010cc1` |
| Live Gateway | `C:\projects\WA-Gateway` at `21a4cb6`, unchanged |
| SQLite used by tests | A fresh folder under the operating-system temporary directory |
| Production `data/gateway.sqlite` | Not written; worktree `data/` did not exist after verification |
| Push or deployment | Not performed |

## 2. Commits by Task

One code task maps to one small commit in the Gateway worktree.

| Task | Commit | Result |
| --- | --- | --- |
| TASK-011 | `c766d6a` | Added additive `incoming_queue.dead_lettered_at` migration, attempt/age dead-letter transitions, one-cycle replay, dead-letter count and burst observability, with SQLite/JSON store tests. |
| TASK-012 | `4010cc1` | Classified permanent incoming HTTP rejections without incrementing `attempts`; preserved retry behavior for authentication, server, timeout, and network failures. |
| TASK-013 | None | Verification-only task; no empty commit was created. |
| TASK-014 | None | Approval checkpoint; awaiting the owner's explicit decision. |

## 3. TASK-013 Verification Evidence

All commands ran from the isolated Gateway worktree. Each test process received an explicit `SQLITE_PATH` and `LOG_FOLDER` inside a fresh temporary directory. No test was allowed to use the default worktree database path.

### Focused dead-letter simulation

`node C:\projects\WA-Gateway-m1w2\test\simulate-dead-letter.js` completed with **0 failed assertions**. The focused test covered both the SQLite store and the fallback JSON store.

| Criterion | Result |
| --- | --- |
| AC-033 | `markFailedAttempt()` returned `deadLettered:true` at the attempt cap and at the age limit. |
| AC-034 | `getDueEvents()` did not return rows in `dead` state. The existing due-event filter was preserved. |
| AC-035 | Dead-lettering was non-destructive: the row remained, `attempts` and `last_error` were retained, `dead_lettered_at` was populated, and the critical log included `wa_message_id` plus an allowed `reason`. |
| AC-036 | HTTP 400 moved the event to `dead` without incrementing `attempts`; HTTP 401 and 500 remained `failed` and were rescheduled. |
| AC-037 | `replayDeadLetter(id)` granted exactly one additional cycle. A subsequent cap failure immediately returned the event to `dead`. |
| AC-038 | Three existing dead rows at start were logged at error level; crossing the burst threshold emitted `[CRITICAL]` with an instruction to stop automatic replay. |
| A-8b | HTTP 422 behavior is explicitly `[Assumed / Out of Scope]`; the current AuliaPos controller does not produce 422, so this branch is not claimed as production evidence. |

Allowed dead-letter reasons are limited to `max_attempts`, `max_age`, and `permanent_rejection`.

## 4. Cumulative Regression and Safety Gates

| Gate | Result |
| --- | --- |
| Cumulative test suite | **23/23 JavaScript test scripts passed**, including all Phase 1, Phase 2, and prior-wave scripts. The suite was split into batches to stay below the command timeout. |
| Outgoing ordering guard | Passed for text and media endpoints; the guard's mutation/self-tests also passed. |
| Outgoing log scan | Passed: 56 log lines, including 9 error lines, contained no message text, `media_base64`, or media contents. |
| SQLite isolation checker | Passed under the temporary-directory environment. The checker identified six older scripts that require callers to set `SQLITE_PATH`; every execution in this phase supplied it, and no worktree `data/` directory remained. |
| Floor-Guard scan | Passed: no `@ts-ignore`, `eslint-disable`, skipped-test, `xit`, or `xtest` tokens were introduced in `test/*.js`. |
| Live Gateway | Remained on `master` at `21a4cb6` with a clean worktree status. No checkout, reset, process stop, or live-data access was performed. |
| AuliaPos application code | Unchanged. |

The six scripts reported by the isolation checker as requiring an explicit temporary path are `simulate-audio-video.js`, `simulate-dead-letter.js`, `simulate-identity-hint.js`, `simulate-lid-conversation.js`, `simulate-send-media.js`, and `simulate-sticker.js`. This is a caller prerequisite, not a product regression; the verified procedure is to set a fresh `SQLITE_PATH` before invoking each script.

## 5. Implementation Decisions and Deviations

### P-13 — Permanent rejection uses a non-counting store transition

TASK-012 needs a terminal `dead` state without consuming the retry budget. A new `markDeadLetter(id, reason)` transition was added to both store implementations and called for permanent rejection. The existing `markFailedAttempt()` remains the only transition that increments `attempts`. This is the smallest implementation that preserves A-6 and the meaning of the incoming failure counter.

### P-14 — Burst observation belongs in the delivery loop

`incomingBuffer` exposes deterministic dead-letter transitions, replay, and counting. The delivery loop samples the dead count before and after a cycle and emits the start count plus burst warning. This keeps SQLite/JSON store parity independent from process orchestration.

### P-15 — Existing `getDueEvents()` filter was preserved

The plan identified REQ-034 as already satisfied by the existing `status IN ('pending','failed')` and due-time filter. No filter change was made. The new test asserts that `dead` rows are excluded.

### P-16 — `422` is defensive classification only

The code recognizes 422 with the permanent 400 branch as required by the plan, but this is marked `[Assumed / Out of Scope]` under A-8b. The production AuliaPos endpoint currently documents/returns 200, 400, or 500; no production claim is made for 422.

## 6. Configuration and Evidence Limits

- `DELIVERY_MAX_ATTEMPTS=100` remains unchanged. At the existing maximum retry delay of 120 seconds, the theoretical window is approximately 3.4 hours. K-04 still requires calibration after a real outage; this phase does not close it.
- `DELIVERY_DEAD_AFTER_MS=0` still means no age limit; the special zero value is not clamped to one.
- Evidence is state-machine evidence using the real store and Express delivery path with external I/O controlled or stubbed. It is not evidence from a real AuliaPos outage or a deployed Gateway.
- AC-040 and the AuliaPos acceptance criteria remain in Phase 4 or Phase 5 and are not claimed here.
- `422` remains assumed/out of scope as stated above.

## 7. TASK-014 Approval Checkpoint

The owner explicitly approved Phase 3 on 2026-09-24 with the response "setuju". TASK-014 is approved, and Phase 3 is closed at this checkpoint.

No Phase 4 or Phase 5 task was started in this session. The implementation plan front matter remains `Planned` until TASK-024. The isolated Gateway worktree remains at `4010cc1`; the live Gateway remains at `21a4cb6`.
