# Project Memory Log

> This file is managed by the `memory-manager` skill.
> It persists context across AI chat sessions to prevent knowledge loss.
> Do NOT manually edit this file unless necessary.

---

## 📝 Session Checkpoint: 2026-09-21 (second)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Planning (`/sdlc-plan-tasks`) done — plan created, next is `/sdlc-clarify-reqs` on the plan, then `/sdlc-write-code`.
- **Active Artifacts:**
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — Status: ✅ Created (first file in `/plan/`), committed on `claude/m3-operational-inbox-plan-h244ji`; PR creation+merge in progress this session.
- **Achieved Milestones:**
  - As Planner Architect, read the approved spec (`spec/spec-design-m3-operational-inbox-fase1.md`, remediated 96/100), the clarification report, and ADR-0001, then built a dependency graph and phasing strategy (Fase 1a: no migration, reuse existing endpoints; Fase 1b: additive migration + new endpoints).
  - Ran the mandatory interactive validation (quiz) with the user via `AskUserQuestion` before finalizing: user chose to merge the originally separate "SLA Service" and "extend `apiConversations()` filter" tasks into one task.
  - Generated `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md`: 14 tasks across 2 phases (Phase 1 = 6 tasks incl. VERIFY/APPROVAL, Phase 2 = 8 tasks incl. VERIFY/APPROVAL), each with Ref ID traceability to spec REQ-00x/AC-00x, dependency graph, sizing all XS-M (no task >3 files), Risks section flagging TASK-008 (Internal Note endpoint) as *High Risk* for a silent copy-paste bug (calling `ConversationModel::update()` on `last_message_at`/`last_message_direction`, which spec Section 12 explicitly warns is undetectable by a naive `is_internal=TRUE` assertion alone).
  - Committed and pushed the plan file directly to `claude/m3-operational-inbox-plan-h244ji` (this session's designated branch).
- **Updated Files:**
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — new (created, committed, pushed)
- **Decisions Made:**
  - Merged "SLA Color Service" and "extend `apiConversations()` with `?status=`/`?q=`" into a single task (TASK-011) per explicit user choice during the quiz step, since both touch the same `apiConversations()` payload.
  - No `CONTEXT.md` update needed (no new canonical domain term coined in the plan itself, terms already defined in the spec's Definitions section).
  - No new ADR needed for plan-level decisions (all are easily-reversible implementation details, same reasoning as the spec's own ADR-skip note).
- **Next Action / Pending:**
  - Create a PR for `claude/m3-operational-inbox-plan-h244ji` and merge it to `v2.2` (user explicitly requested this — in progress, do before ending session).
  - After merge: recommended next step is `/sdlc-clarify-reqs` on `@plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` in a **new chat session**, referencing the spec.
  - Unrelated stale note carried over yet again (4th session running): `AGENTS.md` still records a stale memory path (`.agents/instructions/...`) instead of the real `.claude/instructions/...` — still not fixed, still low priority.

<!-- checkpoint-tail: M3 Fase 1 implementation plan created (plan/plan-feature-m3-operational-inbox-fase1-v1.0.md, 14 tasks, 2 phases) on claude/m3-operational-inbox-plan-h244ji; PR+merge to v2.2 in progress this session; next step after merge is /sdlc-clarify-reqs on the plan in a new session, then /sdlc-write-code. -->

---

## 📝 Session Checkpoint: 2026-09-21

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Documentation (`/sdlc-generate-docs`, Diátaxis Explanation)
- **Active Artifacts:**
  - `docs/explanation/pos-inti-alasan-desain.md` — Status: 🔄 Draft complete, not yet linted or committed
- **Achieved Milestones:**
  - Clarified scope with the user, one question at a time: audience (developer > admin > cashier), quadrant (Explanation), module (POS core), format (Markdown), language (full Indonesian), no `CONTEXT.md`.
  - Scanned `docs/AULIA.md` and `TransaksiModel` (`ubahStatus`, `tambahPembayaran`, `sinkronkanPembayaran`), then wrote an 11-section Explanation document approved by the user.
- **Updated Files:**
  - `docs/explanation/pos-inti-alasan-desain.md` — new Explanation doc (created)
- **Decisions Made:**
  - Where `CLAUDE.md` and `docs/AULIA.md` disagree (Shift Leader may finish via `/api/ubah-status`; backdate is Section 4 not 11), follow `docs/AULIA.md` and the code.
  - Documentation is written in Indonesian, overriding the English default in `AGENTS.md`, by explicit user choice.
  - `CONTEXT.md` intentionally not created.
- **Next Action / Pending:**
  - Run a markdown lint pass on the new doc and commit it (not done yet).
  - Diskon rules (`KalkulasiDiskonTransaksi`) and tagihan/jatuh-tempo rules in the doc come from `CLAUDE.md`/`AULIA.md`, not verified line by line in code.
  - Optional: add rejected alternatives for "Opsi B" if found in `docs/CHANGELOG.md`.
  - Optional next doc: How-to (admin/cashier tasks such as payment method correction), in a new session.
  - `CLAUDE.md` is stale on Shift Leader and backdate section numbering; fix via `/code-janitor`.
  - `AGENTS.md` records a stale memory path (`.agents/instructions/...`); the real file is `.claude/instructions/`.

<!-- checkpoint-tail: Explanation doc for POS core written at docs/explanation/pos-inti-alasan-desain.md; needs lint, commit, and optional CHANGELOG check for Opsi B alternatives. -->

---

## 📝 Session Checkpoint: 2026-09-20 (second)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Specification (`/sdlc-define-specs`) done, next is `/sdlc-clarify-reqs` on the new spec, then `/sdlc-plan-tasks`.
- **Active Artifacts:**
  - `docs/audit/clarification-report-m3-fase1-operational-inbox-2026-09-20.md` — Status: ✅ Finalized (Readiness Score: 92/100), merged to `v2.2` via PR #28.
  - `docs/adr/0001-reuse-response-state-for-queue-view-status.md` — Status: ✅ Accepted, merged to `v2.2` via PR #28.
  - `spec/spec-design-m3-operational-inbox-fase1.md` — Status: 🔄 Drafted, pushed to `claude/cek-dulu-ubvqe2`, NOT yet PR'd/merged to `v2.2`.
- **Achieved Milestones:**
  - User asked which SDLC route to continue the WhatsApp Chat feature. Investigated `docs/TODO-CHAT.md` (repo, current) vs two uploaded stale/draft files (a stale `todo_draft.md`, since disavowed by user) — settled on repo's `docs/TODO-CHAT.md` plus two freshly uploaded docs (`status-proyek-master.md`, `blueprint-m3-operational-inbox.md`) as the real source of truth for the M1-M5 roadmap.
  - Ran `/sdlc-clarify-reqs` on `blueprint-m3-operational-inbox.md`: resolved all 5 flagged 🔶 decisions (computed status, SLA threshold 15/60min, Customer Context scope = Inbox-only, Internal Note = `is_internal` column, @mention = text-only Fase 1) plus 3 self-found cross-cutting gaps (Internal Note must exclude from `last_message_at`/`last_message_direction`/SLA computation; snooze reason rides Internal Note, not a new column, accepting Fase 1a has no reason field; Internal Note write access is open to any staff, not gated by `cekOwnership()`).
  - Verified via code (not asked to user): `attachResponseState()` in `app/Controllers/Inbox.php` already implements a computed-status mechanism (Tahap A) that the new Queue View status must reuse, not duplicate — this became ADR-0001.
  - Ran `/sdlc-define-specs`: wrote `spec/spec-design-m3-operational-inbox-fase1.md` covering Fase 1a (Queue View, Conversation Detail, Snooze without reason, Selesai/Arsip — no new migration) and Fase 1b (Internal Note migration, SLA Timer, snooze reason via Internal Note, Filter & Pencarian endpoint extension).
- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** Diffed local `v2.2` branch against `claude/cek-dulu-ubvqe2` to gauge PR scope, got a scary 207-file/+81k diff.
  - **Reason:** Local `v2.2` branch ref was stale; `origin/v2.2` (after `git fetch`) was actually identical to the branch's merge-base.
  - **Correct Solution:** Always `git fetch origin <branch>` and diff against `origin/<branch>`, never a possibly-stale local branch ref, before reporting PR scope to the user.
- **Updated Files:**
  - `docs/audit/clarification-report-m3-fase1-operational-inbox-2026-09-20.md` — new (merged to `v2.2`)
  - `docs/adr/0001-reuse-response-state-for-queue-view-status.md` — new (merged to `v2.2`, first ADR in repo)
  - `spec/spec-design-m3-operational-inbox-fase1.md` — new (pushed to `claude/cek-dulu-ubvqe2` only, first file in `/spec/`)
- **Decisions Made:**
  - Queue View computed status (`ConversationModel::withComputedStatus()`) must be built as a thin layer over the existing `attachResponseState()`, not a parallel implementation (ADR-0001).
  - Fase 2 (Handoff, Collision detection, Auto-assignment) stays out of scope until M2 (State Consistency, ownership atomicity) is done — this is a repo-external roadmap item tracked in `status-proyek-master.md`, not yet reflected in `docs/TODO-CHAT.md`.
- **Next Action / Pending:**
  - Recommended next: open a NEW chat session, run `/sdlc-clarify-reqs` on `@spec/spec-design-m3-operational-inbox-fase1.md`, targeting the 3 flagged `ASSUMPTION` blocks (filter param shape for `apiConversations()`, SLA color rule when snoozed, `is_internal` column default/nullability).
  - After that: `/sdlc-plan-tasks` to break Fase 1a/1b into tickets.
  - `spec/spec-design-m3-operational-inbox-fase1.md` is NOT yet in a PR — user may want it merged to `v2.2` the same way as the clarification report/ADR (PR #28 precedent).
  - Unrelated stale note carried over: `AGENTS.md` still records a stale memory path (`.agents/instructions/...`) instead of the real `.claude/instructions/...ed` — not fixed yet, low priority.

<!-- checkpoint-tail: M3 Operational Inbox Fase 1 — clarification report + ADR-0001 merged to v2.2 (PR #28); spec/spec-design-m3-operational-inbox-fase1.md drafted and pushed but not yet PR'd; next step is /sdlc-clarify-reqs on the spec (3 ASSUMPTION flags) in a new session, then /sdlc-plan-tasks. -->

---

## 📝 Session Checkpoint: 2026-09-20 (third)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Specification remediated (`/sdlc-define-specs`, Phase 5 Audit Remediation) — spec ready for `/sdlc-plan-tasks`.
- **Active Artifacts:**
  - `docs/audit/clarification-report-m3-fase1-operational-inbox-spec-2026-09-20.md` — Status: ✅ Finalized (Readiness Score: 87/100), remediation status appended (Projected Score 96/100), merged to `v2.2` via PR #30 (clarification report commit only).
  - `spec/spec-design-m3-operational-inbox-fase1.md` — Status: ✅ Remediated (Projected Readiness 96/100), pushed to `claude/spec-operational-inbox-fase1-tuux4c`, NOT yet PR'd/merged (remediation commit is after PR #30 merged).
- **Achieved Milestones:**
  - Ran `/sdlc-clarify-reqs` on `spec/spec-design-m3-operational-inbox-fase1.md`, grilling one question at a time: resolved the 3 tagged ASSUMPTIONs (filter/search mechanism for `apiConversations()`, SLA color scope, `is_internal` column) plus 2 extra gaps found via direct code verification (not asked lazily — actually read `Inbox.php`/migrations first).
  - Verified via code: `apiConversations()` has no filter params today and hardcodes `findAll(100)`; `conversations.last_message_at`/`last_message_direction` are denormalized columns updated explicitly at 3 message-insert call sites (`Inbox.php:834-835,1455-1456`, `InboxGatewayApi.php:262-263`), not an aggregate query — this invalidated the spec's original REQ-009 wording ("filter WHERE is_internal=FALSE") which implied a query that doesn't exist.
  - Verified via code that ASSUMPTION-003's justification ("consistent with other boolean columns in the Inbox schema") was factually wrong — the Inbox schema has zero `BOOLEAN` columns; the real precedent is `tinyint(1) NOT NULL DEFAULT ...` in the POS module (`CreateAuliaPosCore.php`). Decision (`NOT NULL DEFAULT FALSE`) stood, only the justification was corrected.
  - Saved clarification report to `docs/audit/`, committed+pushed, created PR #30, merged to `v2.2` (user explicitly asked "bikin pr, merge").
  - User asked to continue directly to `/sdlc-define-specs` in the SAME chat session; Clarification Analyst persona was already locked (Strict Session Isolation, AGENTS.md §8) — refused once per protocol, then user gave an explicit override ("Ya, override, lanjut di sesi ini"); proceeded with `[Session Override Active]` warning printed.
  - As Specification Architect, applied Phase 5 Audit Remediation: 4 surgical edits to the spec (Section 1.2 assumptions → CONFIRMED, Section 4.4 filter mechanism + `findAll(500)`, REQ-008/REQ-009 wording fix, Section 9 boundaries, Section 12 example/edge case), then appended `REMEDIATION STATUS: RESOLVED` block to the clarification report per the mandatory sequence.
- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** N/A this session — see KB-worthy note below instead.
- **Updated Files:**
  - `docs/audit/clarification-report-m3-fase1-operational-inbox-spec-2026-09-20.md` — new, then remediation status appended (2 commits, 2nd not yet in a PR)
  - `spec/spec-design-m3-operational-inbox-fase1.md` — remediated per clarification (Sections 1.2, 4.4, 3/REQ-008-009, 9, 12)
- **Decisions Made:**
  - `apiConversations()`: filter-after-fetch in PHP (no new SQL WHERE duplicating `attachResponseState()`), limit raised `findAll(100)` → `findAll(500)` (matches existing limit used elsewhere in the same controller). `q` param: raw `LIKE '%q%'`, no phone-number normalization.
  - SLA color: `menunggu_customer` IS included (per AC-005 as literally written), only `selesai`/`follow_up` (snoozed) are excluded.
  - `is_internal NOT NULL DEFAULT FALSE` stands, justification corrected to cite the POS module's `tinyint(1)` pattern instead of a nonexistent Inbox boolean pattern.
  - Internal Note endpoint (`catatanInternal()`) must NEVER call `ConversationModel::update()` for `last_message_at`/`last_message_direction` (the real risk REQ-009 was trying to prevent, worded incorrectly in the original draft).
  - Internal Note is allowed on `closed` conversations, no status gate — consistent with SEC-001's already-permissive stance.
- **Next Action / Pending:**
  - Spec remediation commit (`docs/audit/...` + `spec/...`) on `claude/spec-operational-inbox-fase1-tuux4c` is pushed but NOT yet in a PR (previous PR #30 only carried the first clarification-report commit and is already merged). User asked to create+merge a new PR next — do that before ending the session.
  - After that PR merges: recommended next step is `/sdlc-plan-tasks` **in a new session**, attaching the approved `spec/spec-design-m3-operational-inbox-fase1.md`.
  - Unrelated stale note carried over again: `AGENTS.md` still records a stale memory path (`.agents/instructions/...`) instead of the real `.claude/instructions/...` — still not fixed, still low priority, flagged 3 sessions running now.

<!-- checkpoint-tail: M3 Fase 1 spec clarified (Readiness 87) and remediated (Projected 96) — spec/spec-design-m3-operational-inbox-fase1.md + audit report updated on claude/spec-operational-inbox-fase1-tuux4c, pushed but the remediation commit needs its own PR+merge next, then /sdlc-plan-tasks in a new session. -->

---
