<!-- markdownlint-disable -->

> [!SUCCESS]
> **REMEDIATION STATUS: PARTIALLY RESOLVED (Plan scope only)**
> This audit report has been remediated by Planner Architect on 2026-09-24 in `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` rev 1.1.
> - **Resolved in the Plan:** MC-01..03 (new Phase 3: TASK-015 Internal Note input, TASK-016 SLA Timer dot, TASK-017 search box, TASK-018 VERIFY, TASK-019 APPROVAL). CT-01 (TASK-001..011 ticked with code evidence, `status` back to `In progress`). CT-02 on the Plan side (TASK-011, TASK-013, ASSUMPTION-001, RISK-002, TEST-003 no longer say `findAll(500)`).
> - **Still open (outside Plan scope):** CT-02 in Spec §9, CT-03 (Spec §4.3), the REQ-012 AC and screen-level ACs in the Spec (`/sdlc-define-specs`). ST-03 PRD §9.2 (`/sdlc-draft-prd`). ST-01/ST-02 (Fase 2a plans). All Minor Gaps.
> - **Projected Readiness Score:** 78/100 (Completeness 33/40, Clarity 23/30, Alignment 22/30). The Critical Flaw Veto is lifted at plan level: GH-002 now has an owning task. It stays true in the running app until TASK-015 is coded.

# 🔍 Consistency Audit Report [Review Iteration 1]

**Date:** 2026-09-24 · **Scope:** M3 Fase 1 (Operational Inbox) after PR #41 merged into `v2.3`

**Readiness Score:** 66/100
**Status:** Below Threshold

**Score Breakdown:**

- **Completeness (max 40):** 24 - PRD features GH-002, GH-004 and the search part of Layar 7 have no UI task in the Plan. REQ-012 has no acceptance criterion in the Spec. TASK-001..011 have empty `Completed` cells.
- **Clarity (max 30):** 21 - The Spec contradicts itself on the 500-row limit (§9 vs §4.4 CL-001). §4.3 no longer matches the real endpoint. §4.4 has a duplicated `q` bullet.
- **Alignment (max 30):** 21 - The Plan says `Completed` although PRD user stories are not usable from the screen. Status sections in the PRD (§9.2) and in both Fase 2a plans do not match the merged code.
- **Critical Flaw Veto:** Yes - GH-002 (Must-have) cannot be used from the screen: a kasir cannot write a standalone Internal Note. The only path is the Snooze reason. The `Completed` status hides this gap from anyone reading the Plan.

---

## 1. 📊 Executive Summary

- **SDLC Phase:** Plan (post-implementation, PR #41 merged into `v2.3`)
- **Documents Analyzed:**
  - [x] PRD: `prd-20260922-0141-chat-whatsapp-inbox.md` v1.1
  - [x] Spec: `spec/spec-design-m3-operational-inbox-fase1.md` v1.0
  - [x] Plan: `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` v1.0 (+ both Fase 2a plans, status only)
- **Also checked:** `CONTEXT.md`, `docs/adr/0001-reuse-response-state-for-queue-view-status.md`, `app/Controllers/Inbox.php`, `app/Views/inbox/index.php`, `app/Config/Routes.php`
- **Standards Compliance:** PASS with minor terminology findings (§3)

## 2. 🔍 Traceability Findings

### 🚨 Critical Blockers (Must Fix)

**Missing Coverage (Upstream → Downstream):**

| # | Item | Backend (API) | Screen (`app/Views/inbox/index.php`) | Plan task |
|---|---|---|---|---|
| MC-01 | **GH-002** Write an Internal Note (PRD §4 Must-have, §5.2, §6) | ✅ `POST /inbox/percakapan/(:num)/catatan` (`Inbox::catatanInternal()`, Inbox.php:925) | ❌ No note input. The endpoint is called only from `simpanAlasanSnooze()` (index.php:1087). Displaying notes works (index.php:1496). | ❌ TASK-008 is backend only; TASK-012 covers only the Snooze reason |
| MC-02 | **GH-004** SLA color (PRD §4, §5.2 "dengan indikator warna") | ✅ `sla_color` is added to each conversation (Inbox.php:107-113) | ❌ `sla_color` is never read. The list shows only the Response State, owner and closed badges (index.php:786-815). | ❌ TASK-010/011 are backend only |
| MC-03 | **Layar 7: search by name/number** (PRD §4, §5.2 "Cari percakapan lama") | ✅ `?q=` with validation and escaping (Inbox.php:82-131) | ❌ No search box. `ambilSemuaConversation()` sends only `?page=` (index.php:831). The status filter works, but on the client side through the 5 tabs. | ❌ TASK-011 is backend only |

- **Root cause (plan level):** FILE-007 lists "render 5 tab, sla_color, filter UI, field Alasan Snooze". GOAL-002 also promises that staff "bisa menulis… melihat indikator warna… memfilter/mencari". But no TASK in Phase 2 carries the UI part of the note input, `sla_color` or the search box. FILE-007 names only TASK-002/004/012, so the UI work has no owner. TASK-013 (VERIFY) and TASK-014 (APPROVAL) tested only the API and the tab counts, so the gap was not caught.
- **Spec level:** Spec §13 claims "AC-001 to AC-007 cover REQ-002/003/004/007/008/009/010/011". REQ-012 (filter/search) has no AC, and none of the ACs covers the screen.

**Contradictions (Cross-Document Conflicts):**

- **CT-01, Plan status vs reality:** The frontmatter says `status: 'Completed'`, but TASK-001..TASK-011 have empty `Completed`/`Date` cells. Only TASK-012/013/014 are marked. PRD user stories GH-002/GH-004 and the search are not usable from the screen (MC-01..03).
- **CT-02, the 500 limit:** Spec §9 "Always do: `apiConversations()` pakai `findAll(500)`", Plan TASK-011, ASSUMPTION-001 and RISK-002 all still say `findAll(500)`. This contradicts Spec §4.4 / CL-001 ("tidak boleh dibatasi… 500 terbaru") and the code: both `index()` and `apiConversations()` use `findAll()` with no limit (Inbox.php:46, Inbox.php:103). The code follows CL-001; the documents are stale.
- **CT-03, Internal Note contract:** Spec §4.3 says `Body (JSON): { "teks" }` and `Response 200: { message_id }`. The code reads `getPost('teks')` (form data) and returns `{ conversation_id, message }` (Inbox.php:939, 976-979). The 4096-byte limit (implied by CL-006) is also missing from §4.3.

**Stale status (outside Fase 1, requested by the user):**

- **ST-01:** `plan/plan-feature-m3-operational-inbox-fase2a-v1.0.md` says `status: 'Planned'`, `last_updated: 2026-09-22`, 0/14 tasks marked. The code contains TASK-004 (Handoff dialog, index.php:547), TASK-007 (409 notice + reload button, index.php:567) and TASK-010 (history panel, index.php:1336). `docs/audit/code-review-m3-fase2a-2026-09-23.md` also exists.
- **ST-02:** `plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md` says `status: "Planned"`, 1/15 marked, and that one is TASK-203, which is VOID. The code already contains TASK-202 (referenced at index.php:948-953). The other TASK-1xx/2xx items were not checked one by one; that needs a separate verification.
- **ST-03:** PRD §9.2 still says M3 Fase 1a/1b "Kode belum dimulai" and Fase 2a "Spec belum dibuat, Plan belum". In reality all of these are merged.

### ⚠️ Minor Gaps (Assumed / Backlog)

- **Spec §4.4:** the `q` bullet is duplicated on one line (copy-paste leftover). `[Backlog]`, editorial only.
- **Spec §1.1 and §2:** they still say Fase 2 is "terkunci menunggu M2". PRD v1.1 has since changed this into the narrow K-01 constraint. `[Backlog]`
- **Spec §6:** it still asks for a test of "filter `is_internal = FALSE` pada query `last_message_at`". This contradicts REQ-009, which says no such query exists. `[Backlog]`
- **Spec §13:** it mentions AC-001..AC-007, but AC-008 exists (and AC-008 is placed before AC-007). `[Backlog]`
- **PRD §10 GH-001..GH-004:** all AC checkboxes are still `[ ]`. `[Backlog]`, tick them after MC-01..03 are closed.
- **PRD §3.3:** it says a Belum Diambil conversation may be handed off by "staff mana pun". `CONTEXT.md` and the code say "active kasir" (`currentUserRole === 'kasir'`). This belongs to Fase 2a. `[Backlog]`

## 3. 🛡️ Standards Compliance (Documentation Audit)

- **ADR Format Compliance:** PASS
  - ADR-0001 is still relevant. `withComputedStatus()` is reused and `queue_status` is not recomputed on the client (index.php:654-656).
- **Context/Glossary Alignment:** PASS (minor)
  - The Spec §2 glossary puts "catatan internal" under _Avoid_ and "prioritas" under _Avoid_ for SLA Timer. The PRD uses both ("Catatan Internal", "indikator prioritas"). Internal Note and SLA Timer are not in `CONTEXT.md` yet, so the Spec's own glossary is the only reference.
- **Codebase Reality Check:** FAIL
  - See CT-01..CT-03 and ST-01..ST-03. The code is ahead of the documents; there is no sign the code is wrong.

## 4. 📝 Action Plan (Corrective Actions)

- **Updates Required:**
  - [ ] **Plan (Fase 1):** Add UI tasks: (a) an Internal Note input in Conversation Detail, (b) render `sla_color` in the conversation list, (c) a search box sending `q`, plus one VERIFY/manual-browser task. Mark TASK-001..011 with evidence. Change `status` back to `In Progress` until the UI tasks are done. Update TASK-011, ASSUMPTION-001 and RISK-002 to drop `findAll(500)`.
  - [ ] **Plan (Fase 2a, both):** Sync status and ticks with the merged code, and verify each TASK-1xx/2xx item against the code.
  - [ ] **Spec:** Add an AC for REQ-012 plus UI-level ACs for GH-002/GH-004/search. Fix §9 (500), §4.3 (form body + real response), the duplicate in §4.4, §1.1, §6 and §13.
  - [ ] **PRD:** Update §9.2 to match reality. Align "Catatan Internal"/"prioritas" with the canonical terms "Internal Note"/"SLA Timer" (the terms the Spec uses).
  - [ ] **Standards (ADR/Context):** None required. Optionally, promote "Internal Note" and "SLA Timer" to `CONTEXT.md` when they are next touched.

## 5. ➡️ Recommended Handoff

Score is below 80, so the next phase is not unlocked. First step:

```
/sdlc-plan-tasks Revise plan-feature-m3-operational-inbox-fase1-v1.0.md per consistency audit 2026-09-24:
add UI tasks for GH-002 note input, GH-004 sla_color, and Layar 7 search (MC-01..03),
tick TASK-001..011 with evidence, set status back to In Progress, fix the findAll(500) text (CT-02).
Attach: @spec/spec-design-m3-operational-inbox-fase1.md @plan/plan-feature-m3-operational-inbox-fase1-v1.0.md
```

Then `/sdlc-define-specs` for CT-02, CT-03 and the REQ-012 AC, and `/sdlc-draft-prd` for PRD §9.2.
