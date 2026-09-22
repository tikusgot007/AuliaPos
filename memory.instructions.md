# SDLC Session Memory — M3 Operational Inbox Phase 1

## Checkpoint
- Repository: `tikusgot007/AuliaPos`
- Branch: `feature/m3-operational-inbox-fase1a-task001`
- Latest branch commit: `44bc842` (verified 2026-09-22 via `git log -1`; the previously recorded `db3b563` was stale by 3 commits and the branch is up to date with `origin`)
- Status: M3 Operational Inbox Phase 1a + Phase 1b implementation and verification complete.
- Working tree on the development machine was clean after verification.

## Completed Scope

### Phase 1a
- Added `ConversationModel::withComputedStatus()` as the single computed queue-status mapping.
- Reused computed response state; no new SQL WHERE logic for queue tabs.
- Extended Inbox conversation payload with `queue_status`.
- Added five Queue View tabs and frontend counts.
- Verified existing Conversation Detail and Snooze wiring without introducing new Phase 1a endpoints.
- Added regression coverage for computed queue status and fallback behavior.

### Phase 1b
- Added additive migration `2026-09-22-000001_AddIsInternalToMessages.php` on the `inbox` database group.
- Added Internal Note endpoint and route:
  `POST /inbox/percakapan/(:num)/catatan`.
- Internal Notes use `is_internal=true`, do not call the WhatsApp Gateway, and do not mutate conversation `last_message_at` or `last_message_direction`.
- Added Internal Note session tests.
- Added `InboxSlaService` and configurable SLA thresholds in `Config\\Inbox`.
- Extended conversation API with `status` and `q` filters, computed `queue_status`, and `sla_color`.
- Extended thread payload with boolean `is_internal`.
- Updated Inbox UI to render Internal Notes distinctly.
- Renamed the operational conversation API test to `OperationalInboxConversationTest.php` to avoid a Windows filename/locking problem.
- Updated that test to explicitly JSON-decode response bodies with `JSON_THROW_ON_ERROR`.

## Test / Tooling Checkpoint
- SQLite3 enabled in the CLI PHP environment.
- Xdebug 3.5.3 installed and enabled.
- Full PHPUnit suite completed successfully with:
  - 233 tests
  - 522 assertions
  - code coverage generated
  - no test failures or errors
- Four legacy standalone regression harnesses under `tests/unit/` were prevented from executing during PHPUnit discovery.
- Added test-support migration for `closing_kas` so the SQLite test database contains the schema required by existing report tests.

## Important Decisions
- Keep queue status computation centralized in `ConversationModel::withComputedStatus()`.
- Internal Notes must not affect denormalized conversation last-message fields.
- Snooze reason remains an Internal Note; no `snooze_reason` column.
- Keep M3 Phase 1 limited to the approved plan; do not invent Phase 2 scope from this plan.

## M3 Phase 2 Clarification Checkpoint
- Clarified next-scope candidate from `blueprint-m3-operational-inbox.md`: Handoff, Collision Detection, and Auto-assignment.
- M2 (State Consistency) is deferred for now and remains a dependency constraint for implementation where ownership consistency is required.
- Handoff:
  - Direct transfer; no approval workflow.
  - `Ringkasan` and `Next Action` are required; `Catatan` is optional.
  - Allowed on `Belum Diambil` and `Ditunda`; denied on `Selesai`.
  - Only the current assignee may initiate Handoff; exception: `Belum Diambil` may be handed off by any staff.
  - Target must be an active staff account, must not be the initiating/current staff, and may be offline/unavailable.
  - Handoff preserves existing snooze state when the conversation is `Ditunda`.
  - Successful Handoff records transfer history and creates one Internal Note in the conversation thread.
  - Concurrent Handoff conflict policy: first write wins; a losing request is rejected without overwriting ownership.
- Collision Detection:
  - Conversation remains viewable when owned by another staff member.
  - Conflicting mutations are blocked: `Balas`, `Ambil`, `Lepas`, `Snooze`, `Selesai`, and `Handoff`.
  - `Tandai Dibaca` remains allowed.
  - Conflict feedback identifies the current owning staff member.
- Auto-assignment:
  - Runs automatically for a newly created conversation only.
  - Candidate staff are determined by the existing work schedule/shift; a staff member is available when the current time is within their scheduled working session.
  - Uses least-loaded assignment.
  - Load counts `Open + Menunggu`; `Belum Diambil`, `Ditunda`, and `Selesai` do not count toward load.
  - Tie-breaker: staff member who has gone the longest without receiving an assignment.
  - If no staff is currently within shift, the conversation remains `Belum Diambil`.
  - Once a staff member becomes available, waiting `Belum Diambil` conversations are eligible for assignment using the same least-loaded rule.
  - A conversation is not re-assigned on later incoming messages after its initial assignment.
  - A conversation reopened from `Selesai` keeps its previous ownership and is not auto-assigned again.
  - A previously assigned conversation remains with its owner even when that staff member becomes unavailable; no automatic reassignment.
- Existing schedule source reviewed: `JadwalModel` stores employee/date/shift and defines shift working sessions in code; schedule status is distinct from authorization.

## M2 Gate Verification (2026-09-22)

- Verified with primary evidence that M3 Phase 2 (Handoff, Collision Detection, Auto-assignment) is gated on M2 State Consistency by these upstream documents:
  - `blueprint-m3-operational-inbox.md` line 100 ("Handoff ... dan collision detection (Fase 2) harus tunggu M2 selesai"), lines 117-120 ("Fase 2 - Tunggu M2 selesai"), line 133 (`conversation_handoffs` table -> "Fase 2, setelah M2").
  - `spec/spec-design-m3-operational-inbox-fase1.md` section 1.1 (Out of Scope).
  - `prd-20260922-0141-chat-whatsapp-inbox.md` line 41 (Phase 2 listed as a Non-Goal).
  - `docs/ARCHITECTURE.md` section 12 (lines 279-284): ownership checking is application-level read-then-write, **not an atomic concurrency primitive**; M2 is deferred; Phase 2 must not silently expand into a general state-consistency redesign.
- No M2 artifact exists anywhere in the repository. A full-history scan (`git log --all --name-only`) found no `*m2*`, no `*consistency*`, and no `status-proyek-master.md` file.
- Evidence documents referenced by the Fase 1 spec are **not** on this branch. `docs/adr/0001-reuse-response-state-for-queue-view-status.md`, the five `docs/audit/clarification-report-m3-*` reports, and `docs/decisions/*` (11 files total) exist **only on branch `v2.2`**; they are absent on `v2.3` and on the active branch. Read them read-only with `git show v2.2:<path>`.
- The `AGENTS.md` pointer `.agents/standards/` is **stale** (the `.agents/` folder does not exist). The real standards live at `.claude/standards/ADR-FORMAT.md` and `.claude/standards/CONTEXT-FORMAT.md`. There is also no `CONTEXT.md` / `CONTEXT-MAP.md` in the repository.
- Narrow-atomicity candidate for the gate: the already-recorded Handoff conflict policy ("first write wins; a losing request is rejected without overwriting ownership") implies a DB-level conditional write on the Handoff path only, so the gate can likely be satisfied without a general state-consistency redesign. This must be decided in `/sdlc-clarify-reqs` and written explicitly into the Spec - never assumed silently.
- Routing outcome: M3 Phase 2 must **not** go straight to `/sdlc-define-specs`. The agreed path is one `/sdlc-clarify-reqs` session to open the M2 gate, including whether the PRD needs an amendment because it currently declares Phase 2 a Non-Goal (otherwise the Spec becomes an Orphaned Item and fails `/sdlc-audit-consistency`).

---

## Next Handoff
- M3 Phase 1 plan still ends at TASK-014.
- The next SDLC work is the formal clarification/specification flow for M3 Phase 2; do not start implementation until the next upstream specification and implementation plan are available and approved.
- Preserve M2 as a deferred dependency; do not silently expand scope into M2 work.
- Immediate next step (decided 2026-09-22): open a NEW chat session and run `/sdlc-clarify-reqs` to formally open the M2 gate before any M3 Phase 2 Spec is written. Expected output: a clarification report with a Readiness Score, saved under `docs/audit/` (that folder does not exist on the active branch yet).
- The active branch is `feature/m3-operational-inbox-fase1a-task001` at `44bc842`; this `memory.instructions.md` refresh is modified locally but not yet committed.
