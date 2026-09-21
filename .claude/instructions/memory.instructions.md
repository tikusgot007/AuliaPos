# Project Memory Log

> This file is managed by the `memory-manager` skill.
> It persists context across AI chat sessions to prevent knowledge loss.
> Do NOT manually edit this file unless necessary.

---

## 📝 Session Checkpoint: 2026-09-21 (fifth)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Code (`/sdlc-write-code`) for M1 Wave 1, Phases 1–3 done up to TASK-016. Waiting on TASK-017 (real AC-001, stops the live Gateway) and TASK-018. Then `/sdlc-code-review` in a NEW session. This session mixed personas (Orchestrator → Software Engineer) under an explicit user override.
- **Active Artifacts:**
  - `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` — Status: 🔄 In progress. Completed/Date columns filled for TASK-001..016 (commit SHAs of the WA-Gateway repo). TASK-017/018 blank on purpose.
  - `docs/decisions/2026-09-21-m1-wave1-eksekusi-fase1-3.md` — Status: ✅ Written (commits, deviations from spec, evidence, limits of evidence).
  - `docs/TODO-CHAT.md` — Status: ✅ Synced to the new state. All claims are worded as simulation-only evidence.
  - WA-Gateway repo, worktree `C:\projects\WA-Gateway-m1`, branch `feature/stage-1-reliability`: 13 local commits above `091fe19` (`baf1896` … `065f683`), **not pushed, not merged, not running in production**.
- **Achieved Milestones:**
  - User dropped the Convia (ready-made WhatsApp inbox) idea; the self-built Baileys Gateway stays. M1 goes before M3.
  - Pulled AuliaPos `v2.2` and fetched/pulled both WA-Gateway checkouts (production `C:\projects\WA-Gateway` only fetched, untouched). The repo is `tikusgot007/WA-Gateway`, not `tikusgot/...`.
  - Executed plan Phases 1–3 with per-task commits and a mutation test for every new test (helper script lives outside the repo). 17 `test/simulate-*.js` scripts + the static guard `test/check-register-before-send.js` pass. AC-014 verified by replaying 12 identical messages against `origin/master` (`e18f716`, production code) and HEAD: payloads identical, negative control detected.
  - Audited the ad-hoc E-09 code against the spec and found 5 gaps; found that the ad-hoc E-05 cached a failed LID lookup as `null` forever.
- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** Integrity-checking a suspect SQLite file by opening it with a writable connection, then moving `-wal`/`-shm` aside.
  - **Reason:** closing a writable connection lets SQLite delete `-wal`/`-shm` itself, so only 1 of 3 files was quarantined (violates REQ-013). **Note:** probe with a read-only connection first (`{ readonly: true, fileMustExist: true }`).
  - **Attempted:** `git commit -F -` from PowerShell 5.1.
  - **Reason:** PowerShell 5.1 does not feed stdin that way; git treats the message as a pathspec. **Note:** commit from Git Bash with a heredoc (`git commit -F - <<'EOF'`).
  - **Attempted:** PowerShell commands that combine `Remove-Item` with a regex containing `\s*` or with `cmd /c`.
  - **Reason:** the command-safety check blocks them before anything runs. **Note:** split into separate calls; detach a junction with `cmd /c rmdir` (never `Remove-Item -Recurse` over a junction in PS 5.1); do file rewrites with a small Node script.
  - **Note:** the Windows checkouts are CRLF, so text-mutation helpers must convert `\n` to `\r\n`, otherwise mutations silently do not apply. `node` is not on the bash PATH: use `C:\nvm4w\nodejs` (v20.20.2, same as PM2). `SQLITE_PATH` must be set before requiring any `src/` module (config is read once).
- **Updated Files:**
  - `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` — status + Completed/Date columns
  - `docs/decisions/2026-09-21-m1-wave1-eksekusi-fase1-3.md` — new
  - `docs/decisions/2026-09-21-handoff-m1-wave1-eksekusi-lokal.md` — one pointer line (marked as executed)
  - `docs/TODO-CHAT.md` — M1 status, P0 risks, action items, references
- **Decisions Made:**
  - Option A from the handoff: ad-hoc code overwritten to match the plan.
  - `messageType` is mandatory in `enqueue()` (no `'text'` default), user's choice.
  - No `critical` log level exists: hard errors use `logger.error('[CRITICAL] …', { severity: 'critical' })`. **Assumption, needs confirmation.**
  - JSON fallback quarantines a corrupt main file first even when `.bak` recovers it (deviates from the letter of REQ-017, keeps evidence and protects `.bak`).
  - Two send insertion points (text and media) for the own-sent ID, within the plan's tolerance.
- **Next Action / Pending:**
  - TASK-017 needs separate explicit approval and only runs >21:00 or <08:00. Blocked on deciding how the code reaches the Gateway under test (merge to `master` + PM2 restart touches production).
  - Push the branch and open a PR only when the user asks.
  - Left alone on purpose: `_resolveLidForPhoneJid` caches `null` forever when the Gateway is not connected; the old `simulate-e05-lid-timeout.js` overlaps the new test; `node.exe` (~87 MB) is committed in WA-Gateway.
  - All evidence so far is mock/simulation. Do not describe M1 Wave 1 as done or verified before TASK-017/018.

<!-- checkpoint-tail: M1 Wave 1 Phases 1–3 (TASK-001..016) are coded and simulation-verified in WA-Gateway worktree (13 local commits, unpushed); plan, TODO-CHAT and a new decision log are synced; next is separate approval for TASK-017 (real pm2 stop test, >21:00 or <08:00) after deciding how to deploy, then a code review in a new session. -->

---

## 📝 Session Checkpoint: 2026-09-21 (fourth)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Planning (`/sdlc-plan-tasks`) done for M1 Wave 1 (WA-Gateway incoming reliability). Next is `/sdlc-clarify-reqs` on the new plan, then `/sdlc-write-code` in the WA-Gateway worktree.
- **Active Artifacts:**
  - `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` — Status: ✅ Created (second file in `/plan/`), 3 phases / 18 tasks, based on `spec/spec-process-m1-wave1-incoming-reliability.md` v1.1 (Readiness 85/100, projected 94/100 after remediation). Committed and pushed to `claude/m1-wave1-implementation-plan-4ehgjv`.
- **Achieved Milestones:**
  - As Planner Architect, read spec v1.1, the clarification report (D-03 pre-register ID before send via Baileys `messageId` option; D-04 append accepted only for `pn`/`lid`/`group` JIDs), Ticket 02 audit (E-01..E-09), and `docs/GATEWAY-REQUIREMENTS.md` (GW-08/GW-09 status).
  - Phased the work exactly as the user suggested, validated via `AskUserQuestion`: Phase 1 = enqueue integrity + durable buffer (E-03, E-04, Ticket 03), Phase 2 = append handling (E-01, D-03, D-04), Phase 3 = LID timeout/negative cache + error isolation + JSON recovery (E-05, E-06, Ticket 04) + the real AC-001 measurement.
  - Key structural decision (confirmed by user): AC-001 (`pm2 stop` ~30s, 10 messages, 3 repetitions — the only test that stops the live Gateway) is placed as a single VERIFY/APPROVAL checkpoint at the **end of Phase 3 only**, not repeated per phase, because it's only representative once E-01 (Phase 2) and E-03/E-04 (Phase 1) are both merged.
  - Confirmed granularity via a second `AskUserQuestion` — user accepted the draft breakdown as-is (including the two M-sized tasks: overflow-buffer wiring, and pre-register-ID-before-send touching 2 send paths) without further splitting.
  - Generated `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md`: every task traces to REQ-0xx/AC-0xx, dependency-ordered bottom-up, Risks section flags TASK-009 (pre-register ID before `sendMessage()`) as *High Risk* for the same race-condition bug class D-03 already fixed once (register must happen before `await`, not after).
  - Committed and pushed the plan directly to `claude/m1-wave1-implementation-plan-4ehgjv` (this session's designated branch) in response to a stop-hook requiring untracked files to be committed.
- **Dead-Ends (Do NOT Repeat):** None new this session.
- **Updated Files:**
  - `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` — new plan (created)
- **Decisions Made:**
  - AC-001 placement: end of Phase 3 only (see Achieved Milestones above).
  - Task granularity: kept as originally drafted, no further splitting of the two M-sized tasks.
- **Next Action / Pending:**
  - Create a PR for branch `claude/m1-wave1-implementation-plan-4ehgjv` (repo `tikusgot007/AuliaPos`) and merge it — requested by the user this session, in progress after this checkpoint.
  - After merge: run `/sdlc-clarify-reqs` on the new plan (new session), then `/sdlc-write-code` in `C:\projects\WA-Gateway-m1` (branch `feature/stage-1-reliability`) — never in the live Gateway at `C:\projects\WA-Gateway`, never touch `auth/`.

<!-- checkpoint-tail: M1 Wave 1 implementation plan (3 phases: enqueue integrity, append handling, LID/JSON recovery + AC-001 at the very end) is written and pushed to claude/m1-wave1-implementation-plan-4ehgjv; next is PR+merge (in progress), then /sdlc-clarify-reqs on the plan, then /sdlc-write-code in the WA-Gateway worktree. -->

---

## 📝 Session Checkpoint: 2026-09-21 (third)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Specification (`/sdlc-define-specs`) done for M1 Wave 1 (WA-Gateway incoming reliability). Next is `/sdlc-clarify-reqs` on the spec. The user explicitly overrode the session lock to run it in the same session (working from a phone), then `/sdlc-plan-tasks`.
- **Active Artifacts:**
  - `spec/spec-process-m1-wave1-incoming-reliability.md` — Status: 🔄 Draft v1.0, not yet clarified (Readiness Score: pending). Committed and pushed to `v2.2`.
  - `docs/decisions/2026-09-21-m1-ticket01-baseline.md` — Status: ✅ Ticket 01 finished (append-only log with corrections).
  - `docs/decisions/2026-09-21-m1-ticket02-audit-enqueue.md` — Status: ✅ Audit finished (E-01 to E-09, no code changed).
  - `docs/GATEWAY-REQUIREMENTS.md` — Status: ✅ GW-01 to GW-25 with measured status.
  - `docs/TODO-CHAT.md` — Status: 🔄 Master status checklist (roadmap Tahap 0 to M5), kept in sync with the logs.
- **Achieved Milestones:**
  - M1 Ticket 01 measured on Aan-PC: offline messages (`append`) are dropped at `connectionManager.js:385` (lost 3/14 and 3/15); `/send` has no idempotency key and the real Inbox UI produced a duplicate to the customer when the Gateway was slower than the 10 s AuliaPos timeout; incoming retry backoff verified 3,6,12,24,48,96,120,120 s with no max attempts or dead-letter (real 6 min AuliaPos outage, 5/5 delivered, 115 s recovery).
  - Real bursts (45 messages) lost 0 and duplicated 0, but decrypt failures with Baileys retry shifted arrival order and `message_timestamp` (Inbox order wrong). Cause NOT proven.
  - Ticket 02 audit found 8 loss points; verified by experiment that `INSERT OR IGNORE` silently drops NOT NULL violations, and that Baileys also emits the Gateway's own sent messages as `append`.
  - Wrote the Wave 1 spec with 17 requirements and 14 acceptance criteria.
- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** Killing the Gateway to hit the "message sent but response lost" window.
  - **Reason:** The window is about 1 ms. **Note:** suspend the process (NtSuspendProcess) for longer than the 10 s AuliaPos timeout instead.
  - **Attempted:** Claiming the decrypt errors were caused by session contamination from `/send` to a phone-number address, then claiming the buffer preserves the original message time.
  - **Reason:** The first was retracted then partly reinstated by correlation data (pn-addressed messages failed 10/10) but is unproven; the second is wrong because the timestamp Gateway receives is already shifted. **Note:** label proven vs hypothesis; a discriminating test needs a second, never-contacted test number.
  - **Attempted:** Editing `docs/TODO-CHAT.md` while it was open in Word.
  - **Reason:** Word locks the file (`~$` lock file, EPERM). **Note:** ask the user to close Word first.
- **Updated Files:**
  - `spec/spec-process-m1-wave1-incoming-reliability.md` — new spec (created)
  - `docs/decisions/2026-09-21-m1-ticket01-baseline.md`, `docs/decisions/2026-09-21-m1-ticket02-audit-enqueue.md` — decision log and audit (created)
  - `docs/GATEWAY-REQUIREMENTS.md`, `docs/README.md`, `docs/TODO-CHAT.md` — requirements, docs map row, checklist
- **Decisions Made:**
  - D-01 (E-01): accept all `append` messages except IDs the Gateway just sent itself (in-memory set, 10 min, max 1000).
  - D-02 (E-04): on enqueue failure retry 3 times, then hold in an in-memory overflow (max 500) with a loud error; no disk journal.
  - The user struck off the real reboot test and scenario 2 attempt 1 as out of scope. No ADR was created (decisions are easy to reverse).
  - Code for M1 is changed only in `C:\projects\WA-Gateway-m1` (branch `feature/stage-1-reliability`), never in the running Gateway at `C:\projects\WA-Gateway`. Never touch the live `auth/` folder.
- **Next Action / Pending:**
  - Run `/sdlc-clarify-reqs` on the Wave 1 spec (same session, by explicit override), resolve the two CLARIFICATION NEEDED items (other `append` sources at `messages-recv.js` lines 601 and 957; the message count for the "0 lost" threshold), then `/sdlc-plan-tasks`.
  - Later waves: idempotency for `/send` plus the AuliaPos timeout handling (Wave 2), dead-letter and health (Wave 3); verify E-02 (ephemeral/view-once wrappers) and E-07 before deciding.
  - Open: the cause of decrypt failures and timestamp shift (GW-11, GW-25) — needs a second test number.

<!-- checkpoint-tail: M1 Wave 1 spec (WA-Gateway incoming reliability, decisions D-01 append-except-own-sent and D-02 retry+in-memory overflow) is written and pushed; next is /sdlc-clarify-reqs on spec/spec-process-m1-wave1-incoming-reliability.md, then /sdlc-plan-tasks. -->

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

## 📝 Session Checkpoint: 2026-09-21 (fifth)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Clarification (`/sdlc-clarify-reqs`) done on the M1 Wave 1 implementation plan. Next is `/sdlc-plan-tasks` (new session) to insert the 6 clarified decisions into the plan, then `/sdlc-write-code` in the WA-Gateway worktree.
- **Active Artifacts:**
  - `docs/audit/clarification-report-m1-wave1-incoming-reliability-plan-2026-09-21.md` — Status: ✅ Finalized (Readiness Score: 92/100, Review Iteration 2), NOT yet committed/pushed (created this session, no PR yet).
  - `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` — Status: 🔄 Still v1.0, NOT yet revised with this session's 6 decisions (that's the pending `/sdlc-plan-tasks` step).
- **Achieved Milestones:**
  - As Clarification Analyst, read the plan (v1.0, 3 phases / 18 tasks), spec v1.1 (Readiness 85/100), and Ticket 02 audit (E-01..E-09).
  - User asked to focus interrogation on RISK-001 (register-before-send ordering, same bug class as D-03), RISK-002 (unconfirmed `/send`/`/send-media` code structure), and dependency/test-seam clarity for TASK-009-011.
  - Ran 6 grill rounds, one A/B question at a time (user worked from phone), each with a heavy-lifted recommendation; user picked the recommended option every time (A, A, A, B, A, A).
  - Readiness Score raised from 85 (spec) → 89/100 (Iteration 1, after 3 questions) → 92/100 (Iteration 2, after 3 more questions) once user said "Lanjut" a second time.
  - When user said "Lanjut" a third time (ambiguous), correctly interpreted from context as "proceed to next phase" and invoked the Strict Session Isolation rule (AGENTS.md §8) to refuse switching to `/sdlc-plan-tasks` in the same session — gave a ready-to-use handoff prompt with the ` all 6 decisions instead.
- **Dead-Ends (Do NOT Repeat):** None new this session.
- **Updated Files:**
  - `docs/audit/clarification-report-m1-wave1-incoming-reliability-plan-2026-09-21.md` — new (created, then edited twice to append Iteration 2 items; not yet committed)
- **Decisions Made (all 6, to be inserted into plan v1.1 by `/sdlc-plan-tasks`):**
  1. TASK-001: verify `incomingBuffer.js`'s current constructor first; add a minimal refactor (extract DB path as a constructor param) if not yet testable in isolation without Baileys.
  2. TASK-009 (RISK-002): explicit stop/continue criterion — continue if `/send` and `/send-media` share one internal send function; **STOP and report back** if the structure turns out to be >2 separate send points with no shared function (prevents the D-03 race-bug pattern from being duplicated unreviewed).
  3. TASK-011 (RISK-001): add an automated static guard in `test/simulate-append-handling.js` — read the source file via `fs.readFileSync`, regex-verify `register(` appears before `sendMessage(`/`await` at the TASK-009 send point. Runtime simulation (AC-002) alone was judged insufficient since it can pass by accident with loose simulated timing.
  4. TASK-013/TASK-014: add an explicit Dep — TASK-014 (per-message error isolation) first, then TASK-013 (LID timeout+negative cache) nested inside the same try-block TASK-014 creates, not a separate catch layer (both edit the same region of `connectionManager.js`).
  5. TASK-017 (RISK-003): replace the vague "outside busy hours" mitigation with a fixed written schedule — only runnable after >21:00 or before <08:00.
  6. TASK-006: explicitly note that `test/simulate-durable-buffer.js` calls the `incomingDelivery.js` worker-cycle function directly/manually for the AC-008 scenario, not waiting for the real timer interval (the real interval is undocumented and out of scope for this wave).
  - RISK-004 (E-02/E-07 scope boundary) and RISK-005 (GW-09 idempotency scope boundary) were intentionally NOT re-interrogated — existing plan mitigations judged clear enough, out of scope for this session.
- **Next Action / Pending:**
  - Open a **new chat session**, run `/sdlc-plan-tasks` attaching `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md`, `spec/spec-process-m1-wave1-incoming-reliability.md` (v1.1), and this session's clarification report, to bump the plan to v1.1 with the 6 decisions above.
  - The clarification report itself is not yet committed/pushed — commit it (likely alongside the plan v1.1 bump, or separately first) before or during the `/sdlc-plan-tasks` session.
  - After plan v1.1 is merged: `/sdlc-write-code` in `C:\projects\WA-Gateway-m1` (branch `feature/stage-1-reliability`) — never in the live Gateway at `C:\projects\WA-Gateway`, never touch `auth/`.
  - Unrelated stale note carried over yet again (6th session running): `AGENTS.md` still records a stale memory path (`.agents/instructions/...`) instead of the real `.claude/instructions/...` — still not fixed, still low priority.

<!-- checkpoint-tail: M1 Wave 1 plan clarified (Readiness 85→92/100), 6 decisions recorded in docs/audit/clarification-report-m1-wave1-incoming-reliability-plan-2026-09-21.md (not yet committed); next step is /sdlc-plan-tasks in a NEW session to bump the plan to v1.1 with these 6 decisions, then /sdlc-write-code in the WA-Gateway worktree. -->

---
