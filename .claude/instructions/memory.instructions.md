# Project Memory Log

> This file is managed by the `memory-manager` skill.
> It persists context across AI chat sessions to prevent knowledge loss.
> Do NOT manually edit this file unless necessary.

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
