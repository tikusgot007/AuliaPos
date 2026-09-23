# Clarification Report [Review Iteration 1]

**Date:** 2026-09-23
**Branch:** `feature/m3-operational-inbox-fase1a-task001` @ `d472a91`
**Target document:** `plan/plan-feature-m3-operational-inbox-fase2a-v1.0.md` v1.0 (Plan)
**Upstream context:** `spec/spec-design-m3-operational-inbox-fase2a-handoff-collision.md` v1.0, `prd-20260922-0141-chat-whatsapp-inbox.md` v1.1 (GH-006, GH-007), `docs/audit/clarification-report-m3-fase2-m2-gate-2026-09-22.md` (K-01 to K-09), `CONTEXT.md`, `docs/ARCHITECTURE.md`, `.claude/instructions/memory.instructions.md` (Fase 2a Spec Clarification checkpoint 97/100)
**Locked inputs honoured (not re-interrogated):** dedicated GET handoff newest-first without touching GET messages; selesai rejection = 409; cap 4096; kasir-only target (admin 403); named 409 + `User #{id}`; Asia/Jakarta; FK CASCADE with soft-delete keeping history; free-text next_action; initiator = assignee except belum_diambil + AC-H08

**Readiness Score:** 90/100
**Status:** Good Enough

**Score Breakdown:**

- **Completeness (max 40):** 36 - All 4 slices (TB-01 to TB-04) are vertical DB to UI and demoable; every TASK carries Ref ID + AC Ref; all 6 patches P-01 to P-06 are present; the `daftarKasirAktif` gap is an explicit TASK-002 deliverable with DEP-07 + RISK-02. Deductions: TASK-012 holds one contradicting edge bullet (Q1), and three micro-contracts needed locking this session (expected_owner missing-vs-null, `daftarKasirAktif` columns/order, GET-handoff read gate).
- **Clarity (max 30):** 27 - Controller gate order (Q3), self-vs-initiator priority (Q8), TB-01/TB-02 boundary (Q4), and stale-dropdown handling (Q9) are now deterministic and testable. Minor interpretation residue remains (e.g. `updated_at` wording in REQ-H06, VARCHAR(4096)-vs-TEXT fallback in RISK-03), none blocking.
- **Alignment (max 30):** 27 - Fully traceable to PRD v1.1 GH-006/GH-007, Spec REQ/AC, K-01 to K-09, CONTEXT.md glossary, and ARCHITECTURE.md constraints; no presence/notification/auto-assignment smuggling. Deduction: one TASK-012 sentence contradicts PRD Section 3.3 + GH-006 + locked F-C05 and must be revised to the strict reading (Q1/Option A).
- **Critical Flaw Veto:** No - The TASK-012 contradiction is a one-line text defect with a locked resolution, not an architectural failure. No orphaned item, no horizontal slicing, no missing slice.

---

## 1. Critical Findings (Blockers)

_All are single-line text revisions by `/sdlc-plan-tasks` (or at Spec finalization) — no re-interrogation of locked items, no redesign._

- **Requirement:** TASK-012 — *"initiator!=owner is allowed (`from`=owner, `initiated_by`=actor — except K-06 unassigned NULL, and P-05 non-assignee still 403)"*
  - **Issue:** Directly contradicts REQ-H01/P-05 + AC-H08 (locked: initiator = current assignee, except belum_diambil; non-assignee = 403). As written, an implementor could build an unauthorized-transfer path. **Fix:** replace with the Q1/Option A strict rule — non-assignee always 403 except belum_diambil; `from_user_id` = initiator except the unassigned-NULL case (K-06). Ref ID stays REQ-H01/P-05.
- **Requirement:** REQ-H04 + TASK-003 — *"`expected_owner` required (int or null)"* without missing-field semantics
  - **Issue:** Cannot distinguish a malformed request (field absent = 400) from a lawful unassigned claim (null/empty = `<=>` write path) without the Q5 lock. **Fix:** append the Q5/Option A sentence — absent field = 400; null/empty-string = "saw unassigned", forwarded to the conditional write which decides 200/409.
- **Requirement:** TASK-002 + DEP-07 — *"`UserModel::daftarKasirAktif()` (NOT YET EXISTS — must be created: `role='kasir'` + `is_active=1`)"*
  - **Issue:** Filter alone is insufficient as a single source of truth for the dropdown, the 409 display name, and the belum_diambil-initiator check. **Fix:** append the Q6/Option A contract — returns `id` + `nama`, `WHERE role='kasir' AND is_active=1`, `ORDER BY nama ASC`; reused for target dropdown, 409 naming, and belum_diambil initiator eligibility.
- **Requirement:** TASK-009 — *"route `GET /inbox/percakapan/(:num)/handoff` (filter `auth`, contract P-04)"*
  - **Issue:** Silent on whether a non-assignee may read history; a strict reader could invent an assignee gate. **Fix:** append the Q7/Option A sentence — readable by any active staff under `auth` only (per PRD GH-006 "readable again by staff"); 404 only for unknown conversation.

## 2. Resolved Items and Agreements

- **Requirement:** TASK-012 initiator edge vs REQ-H01/P-05 + AC-H08
  - **Resolution:** [Agreed — locked] Q1/Option A strict is normative: non-assignee = 403 except belum_diambil; TASK-012 sentence must be revised. `/sdlc-write-code` follows REQ-H01 where texts conflict.
- **Requirement:** Initiator role vs admin (REQ-H01/P-05 silent, REQ-H03/P-03 kasir-only target)
  - **Resolution:** [Agreed — locked] Q2/Option A — an admin MAY initiate while being the assignee (or on belum_diambil); only the TARGET is kasir-only (admin target = 403). No initiator-role gate beyond assignee/belum_diambil + active session.
- **Requirement:** Controller gate order, Spec Section 4.3 "order is normative" vs double-violation determinism
  - **Resolution:** [Agreed — locked] Q3/Option A — TASK-003 order is normative: (1) 404, (2) selesai 409, (3) 400 incl. self, (4) initiator 403, (5) target 403, (6) transaction/conditional-write 409. State checks precede identity queries.
- **Requirement:** TB-01 vs TB-02 overlap (TASK-003 409-named + H01-H08 vs TASK-006 "strengthen if incomplete" + C01-C03, same test file)
  - **Resolution:** [Agreed — locked] Q4/Option B — TB-01 ships one working named-409 path with H01-H08; TB-02 proves it (sequential 200+409 race, stale-owner 409, insert-fail rollback) plus loser UX. "If incomplete" in TASK-006 reads as residual hardening, not a duplicate DoD. Vertical slicing holds.
- **Requirement:** `expected_owner` required (int or null); Spec Section 4.3 "null/empty means saw unassigned"
  - **Resolution:** [Agreed — locked] Q5/Option A — absent field = 400 (malformed); null/empty-string = lawful unassigned claim via `<=>`; the conditional write decides 200/409.
- **Requirement:** `daftarKasirAktif` gap (TASK-002, DEP-07, RISK-02)
  - **Resolution:** [Agreed — locked] Q6/Option A — contractual: `WHERE role='kasir' AND is_active=1`, columns `id` + `nama`, `ORDER BY nama ASC`; single source for target dropdown, 409 winner display, and belum_diambil-initiator check. Stays a TASK-002 deliverable; no external definition imported.
- **Requirement:** GET-handoff read gate (TASK-009 auth-only vs hypothetical assignee gate)
  - **Resolution:** [Agreed — locked] Q7/Option A — any active staff under `auth` may read; no assignee/participant gate; 404 for unknown id. Matches PRD GH-006 and P-04.
- **Requirement:** Self-check 400 vs initiator-gate 403 collision (TASK-003 step 3 vs step 4, TASK-012 "to==initiator = 400 even when not owner")
  - **Resolution:** [Agreed — locked] Q8/Option A — step-3 400 wins: a non-assignee submitting to self gets 400, consistent with the locked Q3 order and deterministic double-violation behaviour.
- **Requirement:** Stale target dropdown (RISK-05: kasir deactivated after dialog open)
  - **Resolution:** [Agreed — locked] Q9/Option A — Fase 2a mitigation is sufficient: dropdown filtered at dialog open + server-side 403 on submit, no auto-refresh, no auto-reassign.
- **Verified without asking (codebase facts, not decisions):** `UserModel` exists without `daftarKasirAktif` (gap claim true); `ConversationModel` carries the `$DBGroup='inbox'` boundary with logical (non-FK) user references; `Routes.php` has no handoff routes yet (both are genuinely new); `docs/audit/` previously held only the M2-gate report (this report is the second file).

## 3. Assumed / Auto-Resolved / Out of Scope (The 20 percent we skip)

- **Scenario / Question:** REQ-H06 *"(except built-in `updated_at`)"* — exact timestamp columns touched by the conditional write
  - **Handling:** [Assumed / Auto-Resolved] — implementor preserves `snoozed_until` and all columns except `assigned_to` plus framework-managed timestamps; covered by the AC-H01/`last_message_*`-untouched assertions. No new question.
- **Scenario / Question:** RISK-03 `VARCHAR(4096)` utf8mb4 row-limit fallback to TEXT
  - **Handling:** [Assumed / Auto-Resolved] — fallback accepted as written (controller-side 400 stays normative regardless of DDL type); commit must note the choice. No new question.
- **Scenario / Question:** Minor traceability thinness — TASK-004 cites only AC-H01 (its 409/403/400 notices implicitly serve AC-C01), TASK-007/010 single-AC refs, CON-H04/CON-H06/GUD-H01 enforced via TASK-001/003 without explicit Ref IDs
  - **Handling:** [Assumed / Auto-Resolved] — no orphaned behaviour; author may add the implicit Ref IDs during finalization but `/sdlc-write-code` is not blocked.
- **Scenario / Question:** Session-identity edge (deactivated initiator between dialog open and submit), double-submit idempotency beyond K-09, history-panel pagination UI details past cap-50
  - **Handling:** [Assumed / Out of Scope] — server revalidation (CON-H04) + conditional-write 409 + cap-50 newest-first already cover Fase 2a; deeper session-lifecycle UX is backlog.
- **Scenario / Question:** `docs/ARCHITECTURE.md` Section 12 + blueprint/spec-fase1 Fase-2-gate wording still stale (noted as residual in prior checkpoints)
  - **Handling:** [Assumed / Out of Scope] — owned by `/sdlc-map-architecture` + Spec finalization (TASK-014 Living Map update); not a Plan blocker.

## 4. Next Steps

- The upstream Plan MUST be updated with the Section 1 fixes (4 appended sentences + 1 TASK-012 replacement) by `/sdlc-plan-tasks` (or at Spec finalization per RISK-01) before `/sdlc-write-code` starts; on conflict the Plan patched REQ-H01 to REQ-H09 plus this report Q1-Q9 locks override stale Spec v1.0 text (500/403/admin-allowed/no-initiator-gate).
- If new canonical business terms were agreed upon, update the Domain Glossary (`CONTEXT.md`) — none were (all terms already canonical).
- If architectural decisions were made, document them as an ADR under `docs/adr/` — none qualify (Triple Gate: the Q1-Q9 locks are reversible text clarifications, unsurprising given locked F-C05, with no new trade-off).
- Suggested file for this report: `docs/audit/clarification-report-m3-fase2a-plan-2026-09-23.md` (this file).

---

> **User Decision Prompt:**
> The document has achieved a Readiness Score of 90/100. It is ready for the next phase. Do you want to **PROCEED** to the next phase, or do you want to **REFINE** and clarify further?
>
> Recommendation: **PROCEED** — apply the 4 sentence fixes in Section 1 during finalization, then continue to `/sdlc-write-code` TB-01 (or `/sdlc-audit-consistency` for a formal traceability matrix first). No question needs reopening; all 9 prior-session locks remain honoured.
