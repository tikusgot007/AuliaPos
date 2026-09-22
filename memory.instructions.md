# SDLC Session Memory — M3 Operational Inbox Phase 1

## Checkpoint
- Repository: `tikusgot007/AuliaPos`
- Branch: `feature/m3-operational-inbox-fase1a-task001`
- Latest branch commit: `2bc5287`
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

## Next Handoff
- M3 Phase 1 plan still ends at TASK-014.
- The next SDLC work is the formal clarification/specification flow for M3 Phase 2; do not start implementation until the next upstream specification and implementation plan are available and approved.
- Preserve M2 as a deferred dependency; do not silently expand scope into M2 work.
