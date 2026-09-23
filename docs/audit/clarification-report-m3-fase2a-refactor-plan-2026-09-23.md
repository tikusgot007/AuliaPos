---
version: 1.0
date_created: 2026-09-23
owner: AuliaPos Inbox module
tags: [clarification, inbox, m3, handoff, collision-detection]
---

# 🔍 Clarification Report [Review Iteration 1]

> [!NOTE]
> **REMEDIATION STATUS: RESOLVED** — applied 2026-09-23 by `/sdlc-plan-tasks` (Planner Architect), surgical text edits
> only. No source code, test, PRD or Spec file was touched.

**Plan amended in place:** `plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md` (+112 / -29 lines).
**Source of the five amendments:** this report, Section 2 (CR-03/CR-04 resolutions) and Section 4 (Next Steps 1-5).

**The five locked amendments, as applied:**

1. **TASK-201 (closes CL-01, CL-02):** the insertion point "around lines 1088-1104" is replaced by a normative
   placement — fail-fast 409 immediately before `$db->transBegin()`, i.e. AFTER the 403 initiator gate
   (`Inbox.php:1092-1102`) and the 403 target gate (`:1104-1111`). A verifiable line note was added: this report cites
   `:1120` (the start of the six-line comment block) while the call site in the tree is `Inbox.php:1126`. The reason is
   now in the plan: installed before the gates, a non-assignee answer would flip 403 → 409 and leak the owner name
   (the 409 `message` names the owner, the 403 branches name nobody) — a violation of locked Q3. The must-stay-green set
   is expanded to `C01`, `C01b`, `C02`, `C04`, `E04`, `E06`, `H01`, `H06`, `H08` + `TEST-006` and mirrored in TASK-108,
   TEST-006 and RISK-002.
2. **TASK-108 (closes CL-03):** signature locked to `private function balas409KepemilikanBasi(?int $currentOwnerId)`
   through a dedicated detail block. The helper receives the owner id and performs no ownership re-read; caller 1 (race)
   keeps its own `find()` at `:1144-1147`, caller 2 (fail-fast) passes `$assignedTo`; two callers are documented as the
   reason the extraction stays in Phase 1. Extracted range corrected to `:1148-1159` (name resolution + payload).
3. **TASK-202 (CR-03 = Option A):** server gate locked to
   `$assignedTo !== null ? ($assignedTo === $userId) : (($computed['queue_status'] ?? null) === 'belum_diambil' && in_array($userId, $idKasirAktif, true))`,
   explicitly reusing `$computed` from `Inbox.php:992` (no extra query); UI mirror locked to
   `(!conv.assigned_to && conv.queue_status === 'belum_diambil' && currentUserRole === 'kasir')` (`index.php:859-862`);
   the 403 message now has three documented branches — (i) and (ii) verbatim (locked by `E04(c)`:762 and `E04(b)`:750)
   plus the new branch (iii) with its own wording; the three tests are specified (ditunda tab, menunggu tab, positive
   control after `ambilPercakapan()`).
4. **TASK-203:** marked **VOID / SUPERSEDED** (Option B rejected; the gate is narrowed per CR-03 = A). `FILE-005` and
   `TEST-007` no longer reference it as executable.
5. **TASK-104 / TASK-102 (closes CL-05):** TASK-104 names the single test id `H02b` (the "extend H02" alternative is
   gone); TASK-102 keeps the canonical id `E10` and records the review's suggested name as an illustrative label only.

**Locked decisions untouched:** Q1, Q3, Q5, Q6, Q7, Q9, K-01..K-09, P-01..P-06, CR-01/02/05/06/07/08/09, and the
product answer for "admin vs `belum_diambil`" (Plan wins; admin stays 403 — zero code/test/UI change). That narrowing is
now carried as an explicit text obligation on the Spec inside Phase 2 (`GOAL-002`).

**Verification run:** `markdownlint` (repo-cached CLI) reports only `MD013/line-length` — the same pre-existing,
repo-wide class recorded in the code review — with zero structural findings; no line exceeds 400 characters
(max 394, unchanged from the pre-amendment file).

**Projected Readiness Score after remediation: 94/100** (Completeness 37/40, Clarity 28/30, Alignment 29/30 — no
Critical Flaw Veto). Above the 80 threshold, so the amended plan is viable for `/sdlc-write-code`.

**Date:** 2026-09-23
**Branch:** `feature/m3-operational-inbox-fase1a-task001` @ `25f85ee` (working tree clean)
**Target document:** `plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md` v1.0 (Refactor Plan)
**Upstream context:** `docs/audit/code-review-m3-fase2a-2026-09-23.md`;
`plan/plan-feature-m3-operational-inbox-fase2a-v1.0.md` v1.0 (wins on conflict, RISK-01);
`spec/spec-design-m3-operational-inbox-fase2a-handoff-collision.md` v1.0;
`docs/audit/clarification-report-m3-fase2a-plan-2026-09-23.md` (Q1–Q9);
`prd-20260922-0141-chat-whatsapp-inbox.md` v1.1; `CONTEXT.md`
**Locked inputs honoured (not re-interrogated):** Q1, Q3, Q5, Q6, Q7, Q9, K-01..K-09, P-01..P-06,
CR-01/02/05/06/07/08/09
**Interrogated this session:** CR-03 (initiator gate), CR-04 (expected-owner consistency), and the open
product question "Plan vs Q2: admin on `belum_diambil`"

**Readiness Score:** 86/100
**Status:** Good Enough

**Score Breakdown:**

- **Completeness (max 40):** 34 - Every finding CR-01..CR-09 maps to a REQ id, a task, a file+line and a named
  test; Phase 1 is executable as written; Phase 2 is explicitly clarification-gated (CON-003), which is correct
  discipline. Deductions: TASK-201 pointed at the wrong insertion point, omitted three regression tests and used
  an undefined helper signature (CL-01..CL-03); no task locked the 403 message branches or the UI mirror text.
- **Clarity (max 30):** 26 - Status codes, gate order, file:line references and test ids are concrete. Deductions:
  TASK-201 placement/helper signature ambiguity; TASK-104 carries a dual test id ("extend H02 or add H02b");
  TASK-102 uses a different test id than the review's suggested name (CL-05).
- **Alignment (max 30):** 26 - Traceable to the review report and to Plan REQ/CON ids; CON-001..CON-004 restated
  correctly; the two semantics items were flagged for clarification instead of being silently patched (excellent
  alignment discipline). Deduction: the Plan-vs-Q2 conflict and the glossary ambiguity (tab "Belum Diambil" vs
  ownership state "tanpa pemilik") were recorded nowhere (CL-04).
- **Critical Flaw Veto:** No - The three candidate blockers (CL-01..CL-03) are task-text defects with verified,
  zero-cost resolutions, not architectural failures. Atomicity, the conditional write and the 7 protected
  methods are untouched by all resolutions.

---

## 1. 🚨 Critical Findings (Blockers)

None. Three near-blockers were found (all inside Phase 2 tasks) and are fully resolved in Section 2:

- **CL-01:** TASK-201 located the fail-fast 409 "around lines 1088-1104" — i.e. inside the 403 initiator gate —
  where it would flip non-assignee answers from 403 to 409 (violating locked Q3) and leak the current owner's
  name to non-assignees (the 403 message names nobody). Resolution: insertion point locked to immediately
  before `$db->transBegin()` (`app/Controllers/Inbox.php:1120`), i.e. after both 403 gates.
- **CL-02:** TASK-201/TEST-006 listed only `C01/C02/C04/E06` as the must-stay-green set, omitting `C01b`, `E04`
  and `H08` — the tests that actually exercise the 403 gates the new 409 sits behind. Resolution: the full
  regression set is locked.
- **CL-03:** TASK-108 (the 409 helper extraction) had no parameter signature, and its entire justification
  depended on the CR-04 outcome. Resolution: CR-04 = adopt (A1), and the signature is locked to
  `balas409KepemilikanBasi(?int $currentOwnerId)`.

## 2. 🧩 Resolved Items & Agreements

### CR-04 — expected-owner consistency (REQ-008 / TASK-201)

- **Requirement:** "TASK-201 — Only after the clarification: `app/Controllers/Inbox.php` (around lines
  1088-1104) — fail-fast `409` when the normalised `expected_owner` differs from the server-read
  `assigned_to`, reusing `balas409KepemilikanBasi()` from TASK-108."
- **Resolution:** **[Agreed — locked] Option A1 — adopt the fail-fast, placed immediately before
  `$db->transBegin()` (`:1120`), i.e. AFTER the 403 initiator gate (`:1092-1102`) and the 403 target gate
  (`:1104-1111`).** Rejected A2 (before the gate: non-assignees would receive 409 plus the owner's name instead
  of 403 — a contract change and a new information leak, plus a violation of locked Q3). Rejected B (no
  fail-fast: leaves the audit-trail/authorisation hole open).
- **Evidence read during the session:** read at `:1078-1080` (before the gate and transaction, reused for both
  the gate and `from_user_id` at `:1167`); the write condition uses the client value at `:1131-1136`. The only
  way the write can win while `expected_owner !== $assignedTo` is the ABA window — two ownership moves between
  read and write (`lepasPercakapan()` `:1449` → `NULL`, then `ambilPercakapan()` `:1387-1391` → previous owner)
  — which mis-attributes `from_user_id` and authorises an initiator who is no longer the owner.
- **Behaviour preservation verified for all four deterministic combinations** (`expected`, `read`): (7,7) → 200
  both ways; (null,null) → 200 both ways; (7,null) → 409 with identical body both ways; (null,7) → 409 with
  identical body both ways. A failed conditional write leaves `updated_at` untouched, so the fail-fast merely
  skips a transaction that could not have changed anything (TEST-006 becomes unconditional).
- **Body contract:** must stay byte-identical to the loser 409 — `status`, `message` (including the `User #{id}`
  fallback) and `current_owner_id` — so `C02` (`:442-445`) and `E06` (`:780-811`) pass without editing any
  assertion.
- **Helper signature (closes CL-03):** `balas409KepemilikanBasi(?int $currentOwnerId)` — the helper RECEIVES the
  owner id and performs no query of its own. The race path keeps its current `find()` (`:1144-1147`) then calls
  the helper; the fail-fast path passes `$assignedTo` (the same value a re-fetch would return for a mismatch,
  with zero extra queries). TASK-108 therefore stays in Phase 1 with a justified second caller.
- **Full regression set (closes CL-02), all must stay green:** `C01`, `C01b`, `C02`, `C04`, `E04`, `E06`, `H01`,
  `H06`, `H08`, plus `TEST-006` ("no write on the fail-fast path"; now trivially true because neither a
  transaction nor an UPDATE is executed).
- **Kept as-is:** the conditional write remains normative (REQ-C01) because a race between the pre-check and the
  `UPDATE` is still possible; ALT-004 is respected.

### CR-03 — initiator gate on `belum_diambil` (REQ-007 / TASK-202)

- **Requirement:** "Option A for CR-03: change the initiator gate (`app/Controllers/Inbox.php` lines 1092-1094)
  to key off `queue_status === 'belum_diambil'` and mirror it in `app/Views/inbox/index.php` (lines 859-862)."
- **Resolution:** **[Agreed — locked] Option A (TASK-202).** TASK-203 (document the widening) becomes VOID for
  this increment; RISK-004 is now satisfied, so Phase 2 may execute this item.
- **Reachability proved from code, not assumed.** `withComputedStatus()` (`ConversationModel.php:133-170`) sets
  `belum_diambil` only when `response_state = perlu_dibalas` AND `assigned_to` is empty, so
  `assigned_to IS NULL` is NOT equivalent to `belum_diambil`. Two reachable unowned-and-not-`belum_diambil` paths:
  (1) `menunggu` — the owner marks the thread read, then `lepasPercakapan()` (`:1449`) clears `assigned_to`
  without touching `last_seen_*`; (2) `ditunda` — `snoozePercakapan()` (owner/admin only, `:1505`) sets
  `snoozed_until`, then `lepasPercakapan()`. In both states the current gate (`:1092-1094`) admits any active
  kasir. Impact is bounded by `ambilPercakapan()` (`:1387-1389`, unowned = claimable in any tab), so no new
  capability appears — only `from_user_id` (NULL vs claimer) and one extra step differ.
- **Server change:** `$assignedTo !== null ? ($assignedTo === $userId)
  : (($computed['queue_status'] ?? null) === 'belum_diambil' && in_array($userId, $idKasirAktif, true))`, reusing
  the `$computed` value already produced at `:992` (single source of truth per DEP-01, no extra query).
- **403 message becomes three branches (new finding; not covered by the plan).** Branches (i) assignee != owner
  and (ii) unowned + `belum_diambil` MUST keep their exact current wording, because `E04(c)` (`:762`) and
  `E04(b)` (`:750`) assert them. A new branch (iii) — unowned + tab other than `belum_diambil` — needs its own
  message (e.g. "Percakapan tanpa pemilik hanya bisa diserahkan dari tab Belum Diambil. Ambil dulu percakapan
  ini."); otherwise an active kasir would read "Hanya kasir aktif ..." and be misled, which would itself become
  the next audit finding.
- **UI mirror:** `(!conv.assigned_to && conv.queue_status === 'belum_diambil' && currentUserRole === 'kasir')`;
  the assignee branch (`String(conv.assigned_to) === String(currentUserId)`) stays unchanged.
- **Tests (no existing assertion is touched):** three additions, none of which edits an existing assertion:
  1. `assigned_to NULL` + active `snoozed_until` (tab `ditunda`) + non-assignee kasir with `expected_owner = ''`
     → 403, ownership stays `NULL`, zero history rows.
  2. `assigned_to NULL` + `last_seen_by_assignee_at >= last_message_at` (tab `menunggu`) + non-assignee kasir
     → 403 + zero history rows.
  3. Positive control — after the kasir claims via `ambilPercakapan()`, the same kasir's Handoff → 200, proving
     the narrowing leaves no dead end.
- **No regression risk to existing tests:** `H06`, `E04(a)` and `E08` seed `assigned_to => null` with the seed
  defaults (`status open`, `last_message_direction incoming`, no `last_seen_*`), which computes
  `queue_status = belum_diambil` (`tests/session/InboxHandoffTest.php:118-135`).
- **Compatibility with CR-04/A1:** test (1) sends `expected_owner = ''` while the read is `NULL` → not a
  mismatch, so the fail-fast never fires and the answer stays 403. The two decisions are independent
  (the fail-fast sits after this gate).

### Plan vs Q2 — admin on `belum_diambil` (product question)

- **Conflict:** Q2/Option A (locked, `docs/audit/clarification-report-m3-fase2a-plan-2026-09-23.md:39`) — "an admin
  MAY initiate while being the assignee (or on `belum_diambil`)" — versus Plan REQ-H01/P-05/P-03 plus the locked
  test `E04(b)` (`:749-752`) and the UI mirror (`index.php:861`), which allow only an active **kasir**.
- **Resolution:** **[Agreed — locked] Option A — the Plan wins; admin stays 403 on `belum_diambil`.** Zero code,
  test and UI changes. The admin loses no capability: `ambilPercakapan()` (`:1387-1389`) lets an admin claim any
  conversation (override), after which the assignee branch permits the Handoff; only `from_user_id` (admin vs
  `NULL`) and one extra request differ.
- **Documentation obligation:** during Spec finalisation, Q2's sentence must be restated as a deliberate narrowing
  by the Plan (RISK-01/CON-003); otherwise the conflict stays silent across two locked documents.
- **Informational (the user declined a named backlog item, option C):** every other mutation grants admin override
  through `cekOwnership()` (`:532`), so an admin may `lepas`/`tutup`/`hapus` a conversation owned by someone else
  but may not hand it off. Generalising that override would create a forced-handoff path, which PRD v1.1 line 89
  explicitly excludes → out of scope for Fase 2a, and NOT recorded as a defect or a task.

## 3. ⚠️ Assumed / Auto-Resolved / Out of Scope (The 20% we skip)

- **Scenario / Question:** Glossary ambiguity — tab "Belum Diambil" vs ownership state "tanpa pemilik"
  (`assigned_to IS NULL`). CR-03/A1 makes these two concepts materially different, but `CONTEXT.md` defines
  neither.
  - **Handling:** `[Assumed / Auto-Resolved]` — proposed canonical terms (lazy creation, written only after
  explicit user confirmation): **Belum Diambil** = queue view status `belum_diambil` (`perlu_dibalas` + no owner),
  `_Avoid_: unassigned, belum dipegang`; **Tanpa Pemilik** = `assigned_to IS NULL`, possible in any tab,
  `_Avoid_: belum_diambil, kosong`. No file write happens until the user confirms.
- **Scenario / Question:** Test-id divergence (CL-05) — the review names `testE09ExpectedOwnerAbsenDitolak400`,
  the plan writes `E10`, TASK-104 writes "extend H02 (or add H02b)".
  - **Handling:** `[Assumed / Auto-Resolved]` — the plan's ids (`E09`..`E12`, `H02b`) stand; the requirement is
  that each REQ-001..REQ-005 contract maps to exactly one uniquely named test. The review's suggested name inside
  the plan text should be corrected during the plan amendment.
- **Scenario / Question:** Micro-race between the fail-fast pre-check and the conditional `UPDATE` (ownership moves
  again after the pre-check).
  - **Handling:** `[Assumed / Out of Scope]` — already covered by the normative conditional write (REQ-C01) and
  the loser 409; no new assumption, seam or code required.
- **Scenario / Question:** Return type and placement of `balas409KepemilikanBasi()` inside the controller.
  - **Handling:** `[Assumed / Auto-Resolved]` — follow the existing private-helper conventions in
  `app/Controllers/Inbox.php`; its observable contract is already frozen by the body contract in Section 2.
- **Scenario / Question:** TASK-203 (document the widening) still present in the plan.
  - **Handling:** `[Assumed / Out of Scope]` — the widen-or-not question is closed by CR-03/A1 (gate narrowed), so
  TASK-203 must be marked VOID when the plan is amended; no Spec text change is needed for CR-03 because A1
  restores fidelity to REQ-H01.
- **Scenario / Question:** CR-05 `mb_strlen` vs the 4096 cap.
  - **Handling:** `[Assumed / Auto-Resolved]` — untouched by this session; `E11`/`E12` already lock the character
  semantics (4096 multi-byte characters accepted, 4097 rejected).

## 4. 📝 Next Steps

- **Phase 1 can execute as written.** TASK-101..TASK-110 are unaffected by this session; only TASK-108's
  justification is now explicit (two callers: race path + fail-fast path). Recommended immediate action:
  `/sdlc-write-code` in a new session, ending with `vendor/bin/phpunit --no-coverage` at >= 283 tests /
  867 assertions and zero skips (TASK-109).
- **Plan text amendments required before Phase 2 executes** — to be applied by `/sdlc-plan-tasks` (new session,
  surgical edits only):
  1. TASK-201: replace "around lines 1088-1104" with "immediately before `$db->transBegin()` (`:1120`), after both
     403 gates"; expand the must-stay-green list to `C01, C01b, C02, C04, E04, E06, H01, H06, H08`.
  2. TASK-108: lock the helper signature `balas409KepemilikanBasi(?int $currentOwnerId)` (the race path keeps its
     own `find()` at the call site; the helper performs no query).
  3. TASK-202: add the third 403 message branch (unowned + tab other than `belum_diambil`) and the three tests;
     branches (i) and (ii) keep their existing wording verbatim.
  4. TASK-203: mark VOID — superseded by the CR-03/A1 decision.
  5. TASK-104: settle on one test id (`H02b`).
- **Spec finalisation:** add the Q2 narrowing sentence (the Plan wins over Q2 for admin-on-`belum_diambil`) and keep
  the planned P-01..P-06 / 4096 / 409 boundary text, so the Spec stops being stale.
- **Domain Glossary:** offer only — write the two proposed canonical terms to `CONTEXT.md` after explicit user
  confirmation (lazy creation rule).
- **ADR:** none qualifies (Triple Gate: these are reversible text clarifications, unsurprising given Q2/REQ-H01, and
  carry no new trade-off).
- **Remediation protocol:** once the five plan amendments land, the authoring agent MUST append
  `REMEDIATION STATUS: RESOLVED` at the top of this report (AGENTS.md Remediation Protocol).

---

> **User Decision Prompt:**
> The document has achieved a Readiness Score of 86/100. It is ready for the next phase. Do you want to **PROCEED**
> to the next phase, or do you want to **REFINE** and clarify further?
>
> Recommendation: **PROCEED** — all three interrogated items are locked with zero-cost resolutions, Phase 1 is
> executable unchanged, and the five Phase 2 amendments are additive text fixes. Apply the amendments to
> `plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md` (or fold them into the same `/sdlc-write-code` session
> after Phase 1), then execute Phase 2 with `/sdlc-write-code`.
