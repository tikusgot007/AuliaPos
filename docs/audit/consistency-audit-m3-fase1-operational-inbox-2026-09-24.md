<!-- markdownlint-disable -->

# 🔍 Consistency Audit Report [Review Iteration 2]

> [!SUCCESS]
> **REMEDIATION STATUS: RESOLVED for Spec scope (REFINE step 3)**
> This audit report has been remediated by Specification Architect on 2026-09-24 in `spec/spec-design-m3-operational-inbox-fase1.md` rev 1.2.
> - **Resolved:** NG-01 / TODO-SEARCH-01 (Fase 1d contract per PRD v1.3 GH-009: `q` matches `contact_name`, `whatsapp_name`, `phone`, `manual_phone`, `chat_id`, per column, no phone normalization. See CL-015, REQ-013, CON-003, §4.4, AC-013 a–h, §6, §12, §13, §15). NG-04 (literal `\n` in §1.2 replaced with real line breaks). NG-05 ("4096 byte" in CL-006, the §8 sample, and §12).
> - **Not in this step:** GH-010 (Fase 1e, message-text search) is explicitly out of scope in Spec §1.1 until a later revision. Fase 1d still needs a plan task and code (`/sdlc-plan-tasks`). The "karakter" wording in code (`Inbox.php:951`, `index.php:1308`) remains a code-review TODO. ST-01/ST-02 (Fase 2a plans) remain a separate session.
> - **Projected Readiness Score:** 95/100 (Completeness 38/40, Clarity 29/30, Alignment 28/30).

> [!SUCCESS]
> **REMEDIATION STATUS: PARTIALLY RESOLVED (PRD scope, REFINE step 2)**
> This audit report has been remediated by Product Manager PRD on 2026-09-24 in `prd-20260922-0141-chat-whatsapp-inbox.md` v1.2 (status and wording only, no scope change).
> - **Resolved:** ST-03 (§9.2 now shows Fase 1a/1b merged via PR #41, a new Fase 1c row, and Fase 2a Spec v1.2 + code + code review done, with its plan status sync noted as pending). GH-001..GH-004 ticked in §10 with an evidence note. Glossary: "catatan internal" → "Internal Note" and "indikator/warna prioritas" → "SLA Timer" across §1.2–§10.
> - **NG-01 decided (PRD v1.3):** the owner chose a full search. Fase 1d (GH-009): search every name/number shown in the list, incl. the WhatsApp profile name. Fase 1e (GH-010): also search message text (customer, staff, Internal Note) with a matching snippet. The Spec still has to write the contract.
> - **Still open:** NG-01 in the Spec (per GH-009/GH-010), NG-04, NG-05 (`/sdlc-define-specs`, REFINE step 3); ST-01/ST-02 (Fase 2a plans, separate session); PRD §3.3 "staff mana pun" (Fase 2a scope).
> - **Projected Readiness Score:** 94/100 (Completeness 38/40, Clarity 28/30, Alignment 28/30).

> [!SUCCESS]
> **REMEDIATION STATUS: PARTIALLY RESOLVED (Plan scope, REFINE step 1)**
> This audit report has been remediated by Planner Architect on 2026-09-24 in `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` (editorial only, no new tasks, status stays `Completed`).
> - **Resolved:** NG-02 (the Phase 3 NOTE now points to Spec AC-010..AC-012). NG-03 (TASK-015 `AC Ref` + AC-010, TASK-016 `AC Ref` + AC-011, TASK-018 VERIFY names Spec AC-010..AC-012).
> - **Still open:** ST-03 and the PRD minor gaps (`/sdlc-draft-prd`); NG-01, NG-04, NG-05 (`/sdlc-define-specs`); ST-01/ST-02 (Fase 2a plans, separate session).
> - **Projected Readiness Score:** 91/100 (Completeness 37/40, Clarity 28/30, Alignment 26/30).

**Date:** 2026-09-24 · **Scope:** M3 Fase 1 (Operational Inbox) re-audit after Fase 1c (`f3bd8fa`, fix `dd9e864`, code review verdict: Merge)

**Readiness Score:** 89/100
**Status:** Good Enough

**Score Breakdown:**

- **Completeness (max 40):** 37 - Every Fase 1 PRD feature now has a Spec REQ + AC, a Plan task, and working code (MC-01..03 closed). -2: PRD asks for search "berdasarkan nama/nomor pelanggan", but Spec 4.4 searches only `contact_name`/`phone`, while the list shows `whatsapp_name`/`manual_phone` (NG-01, already tracked as TODO-SEARCH-01). -1: PRD §10 GH-001..GH-004 checkboxes are still `[ ]`.
- **Clarity (max 30):** 26 - The Phase 3 NOTE in the Plan still says the Spec has no screen AC for REQ-012 (NG-02). TASK-015/016 do not cite AC-010/AC-011 (NG-03). Spec §1.2 has literal `\n` text (NG-04). "4096 karakter" vs a byte limit (NG-05).
- **Alignment (max 30):** 26 - Spec, Plan and code agree on every Fase 1 contract (CT-01..03 closed). -3: status text outside Fase 1 is still stale (ST-01, ST-02, ST-03). -1: the PRD still uses "Catatan Internal"/"indikator prioritas" instead of the Spec glossary terms.
- **Critical Flaw Veto:** No - GH-002 is now usable from the screen (standalone Internal Note button + dialog). No blocking defect remains in the Fase 1 scope.

---

## 1. 📊 Executive Summary

- **SDLC Phase:** Plan (post-implementation; Plan Phase 3 `Completed`, refactor plan `plan-refactor-m3-fase1c-inbox-screen-v1.0.md` `Completed`)
- **Documents Analyzed:**
  - [x] PRD: `prd-20260922-0141-chat-whatsapp-inbox.md` v1.1 (unchanged since Iteration 1)
  - [x] Spec: `spec/spec-design-m3-operational-inbox-fase1.md` rev 1.1
  - [x] Plan: `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` rev 1.1 (+ `plan-refactor-m3-fase1c-inbox-screen-v1.0.md`; both Fase 2a plans, status only)
- **Also checked:** `app/Views/inbox/index.php`, `app/Controllers/Inbox.php` (`catatanInternal()`), `tests/session/OperationalInboxScreenTest.php`, `.claude/instructions/memory.instructions.md` (review record of `dd9e864`)
- **Standards Compliance:** PASS (minor terminology finding, §3)

### Status of Iteration 1 findings

| ID | Iteration 1 | Now | Evidence |
|---|---|---|---|
| MC-01 (GH-002 note input) | ❌ Open | ✅ **Closed** | "Catatan Internal" button in `renderThreadHeader()` with no ownership/status gate (`index.php:1113-1117`); `#modalCatatanInternal` (`index.php:668`); `simpanCatatanInternal()` blocks blank and > 4096-byte text before any request, keeps the dialog + text on error (`index.php:1294-1339`); one shared fetch `kirimCatatanInternal()` (`index.php:1238`). In-flight guard fixed in `dd9e864` (`index.php:1283-1286`, `1322`). Spec AC-010, Plan TASK-015. |
| MC-02 (GH-004 SLA color) | ❌ Open | ✅ **Closed** | `renderTitikSla(c.sla_color)` in the list (`index.php:905`), server value only, null/unknown renders nothing (`index.php:840-861`). Spec AC-011, Plan TASK-016. |
| MC-03 (Layar 7 search) | ❌ Open | ✅ **Closed** | `#inputCariConversation` `maxlength="255"` (`index.php:331`); `kataKunciAktif` sent as `&q=` on every page request incl. polling (`index.php:928-951`); stale result dropped (`index.php:963`); CL-005 empty text (`index.php:877-880`); failed search restores the old keyword + one toast (`index.php:983-991`). Spec AC-009/AC-012, Plan TASK-017. |
| CT-01 (Plan status vs reality) | ❌ Open | ✅ **Closed** | TASK-001..019 all ticked with evidence; `status: 'Completed'` now matches the code. |
| CT-02 (`findAll(500)`) | ❌ Open | ✅ **Closed** | Spec §9 and Plan TASK-011/ASSUMPTION-001/RISK-002 describe no row limit + 50 per page. |
| CT-03 (Internal Note contract) | ❌ Open | ✅ **Closed** | Spec §4.3 = code: form field `teks` (`Inbox.php:939`), 400 for empty and `strlen > 4096` (`Inbox.php:941-953`), response `{ status, conversation_id, message }` (`Inbox.php:976-980`). |
| Spec: REQ-012 AC + screen ACs | ❌ Open | ✅ **Closed** | AC-009..AC-012; §13 maps every REQ to an AC. |
| Plan TASK-017 "REQ-012 has no spec AC yet" | ❌ Open | ✅ **Closed in the row** / ⚠️ leftover in NOTE | TASK-017 `AC Ref` = `AC-009, AC-012` and the row no longer has that sentence. The same claim is still in the Phase 3 NOTE above the table (Plan line 77), see NG-02. |
| ST-01, ST-02 (Fase 2a plans) | ❌ Open | ❌ **Still open** | Both still `status: Planned` (`last_updated` 2026-09-22 / 2026-09-23). Outside Fase 1. |
| ST-03 (PRD §9.2) | ❌ Open | ❌ **Still open** | PRD §9.2 still says Fase 1a/1b "Kode belum dimulai" and Fase 2a "Spec belum dibuat". |

**Verification basis for MC-01..03:** render test `tests/session/OperationalInboxScreenTest.php` (3 tests, 13 assertions, each fails when its feature is removed, per refactor TASK-103); full suite 317/317; manual browser check 8/8 (Plan TASK-018 b) plus the close-and-reopen check (refactor TASK-104 c); `/sdlc-code-review` of `dd9e864`: 0 CRITICAL/REQUIRED, 0 spec issues, verdict Merge.

## 2. 🔍 Traceability Findings

### 🚨 Critical Blockers (Must Fix)

- **Missing Coverage (Upstream -> Downstream):** None in the Fase 1 scope.
- **Orphaned Items (Scope Creep):** None. Every Phase 3 task traces to a PRD story (GH-002, GH-004, Layar 7) and a Spec AC. The refactor plan only fixes review findings on the same screen.
- **Contradictions (Cross-Document Conflicts):** None blocking. See NG-01 for a PRD vs Spec gap in the search fields.

### ⚠️ Minor Gaps (Assumed / Backlog - The 20% we skip)

- **NG-01 — Search fields (PRD §4/§5.2 vs Spec 4.4):** The PRD asks for search "berdasarkan nama/nomor pelanggan". Spec 4.4 matches only `contact_name`/`phone`, but the list shows `whatsapp_name` and `manual_phone` when those are empty (`index.php:887`, `index.php:901`). A conversation known only by its WhatsApp profile name is shown but cannot be found by that name. The code follows the Spec, so this is not a code bug.
  - **Handling:** `[Backlog]` - already tracked as TODO-SEARCH-01 (Plan TASK-018). Decide in `/sdlc-define-specs`: widen `q` to `whatsapp_name`/`manual_phone`, or state in Spec 4.4 that only the saved contact name/number is searchable.
- **NG-02 — Plan Phase 3 NOTE is stale:** Plan line 77: "The spec has no screen-level AC for REQ-012 yet… until the spec adds them." Spec rev 1.1 added AC-009..AC-012.
  - **Handling:** `[Backlog]` - editorial. Replace with "Screen ACs: Spec AC-010..AC-012."
- **NG-03 — Plan AC Ref cells are incomplete:** TASK-015 cites `AC-003, AC-004` but not `AC-010`; TASK-016 cites `AC-005, AC-006, AC-008` but not `AC-011`. TASK-018 does not name AC-010..AC-012.
  - **Handling:** `[Backlog]` - editorial; the task text already matches those ACs.
- **NG-04 — Spec §1.2 formatting:** line 40 has literal `\n\n` text instead of line breaks, so the NOTE and the next paragraph render as one line.
  - **Handling:** `[Backlog]` - editorial.
- **NG-05 — "4096 karakter" vs bytes:** Spec §8 sample code, the endpoint message (`Inbox.php:951`) and the screen toast (`index.php:1308`) say "karakter", while Spec §4.3 defines the limit in bytes. CL-006 also says "4096 karakter".
  - **Handling:** `[Backlog]` - wording only; the rule (bytes, `strlen`) is clear in §4.3. Already a code-review TODO.
- **PRD §10 GH-001..GH-004 checkboxes still `[ ]`:** these can now be ticked; Iteration 1 said to tick them after MC-01..03 close.
  - **Handling:** `[Backlog]` - `/sdlc-draft-prd`, together with ST-03.
- **PRD §3.3 "staff mana pun" for Handoff (Fase 2a):** unchanged from Iteration 1.
  - **Handling:** `[Backlog]` - Fase 2a scope.

## 3. 🛡️ Standards Compliance (Documentation Audit)

- **ADR Format Compliance:** PASS
  - ADR-0001 still holds: the screen reads `queue_status` and `sla_color` from the server and computes neither in JS (`index.php:869-871`, `index.php:840-861`). Fase 1c added no decision that meets the Triple Gate, so no new ADR is needed.
- **Context/Glossary Alignment:** PASS (minor)
  - Unchanged from Iteration 1: the PRD uses "Catatan Internal" and "indikator prioritas", which the Spec §2 glossary lists under _Avoid_. The screen label "Catatan Internal" is Indonesian UI copy, not a document term, so it is not flagged.
- **Codebase Reality Check:** PASS for Fase 1
  - Spec rev 1.1, Plan rev 1.1 and the code agree on every Fase 1 contract checked (Internal Note endpoint, SLA dot, search `q` + `page`). Remaining drift is only in status text outside Fase 1 (ST-01..ST-03).

## 4. 📝 Action Plan (Corrective Actions)

- **Updates Required:**
  - [ ] **PRD:** (ST-03) update §9.2 to the real status of M3 Fase 1a/1b/1c and Fase 2a; tick the GH-001..GH-004 checkboxes; align "Catatan Internal"/"prioritas" with "Internal Note"/"SLA Timer". → `/sdlc-draft-prd`
  - [ ] **Spec:** (NG-01) decide TODO-SEARCH-01 in §4.4; (NG-04) fix the literal `\n` in §1.2; (NG-05) optional wording "4096 byte". → `/sdlc-define-specs`
  - [ ] **Plan:** (NG-02) fix the Phase 3 NOTE; (NG-03) add AC-010/AC-011 to TASK-015/016. Separately, (ST-01/ST-02) sync both Fase 2a plans with the merged code. → `/sdlc-plan-tasks`
  - [x] **Standards (ADR/Context):** None required.

---
> **User Decision Prompt:**
> The document has achieved a Readiness Score of 89/100. It is ready for the next phase. Do you want to **PROCEED** to the next phase, or do you want to **REFINE** and clarify further?
>
> **User decision (2026-09-24): REFINE.** Order: (1) `/sdlc-plan-tasks` for NG-02/NG-03, (2) `/sdlc-draft-prd` for ST-03 + GH-001..004 checkboxes + glossary terms, (3) `/sdlc-define-specs` for NG-01 (TODO-SEARCH-01), NG-04, NG-05. ST-01/ST-02 (Fase 2a plans) stay a separate `/sdlc-plan-tasks` session.

---
---

# 📜 History — Review Iteration 1 (kept for traceability)

> [!SUCCESS]
> **REMEDIATION STATUS: RESOLVED for Plan + Spec scope (PRD and Fase 2a plans still open)**
> This audit report has been remediated by Specification Architect on 2026-09-24 in `spec/spec-design-m3-operational-inbox-fase1.md` rev 1.1.
> - **Resolved in the Spec:** CT-02 (§9 no longer says `findAll(500)`; no row limit, 50 per page via `page`). CT-03 (§4.3 and §8 match `Inbox::catatanInternal()`: form field `teks`, response `{ status, conversation_id, message }`, 400 for text > 4096 bytes via `strlen`). New AC-009 (REQ-012 API: status + q + page, old conversation found, empty result 200 `conversations: []`) and screen ACs AC-010 (GH-002), AC-011 (GH-004), AC-012 (Layar 7), matching plan TASK-015/016/017. Duplicate `q` bullet in §4.4 removed. Minor gaps: §1.1/§2 Fase 2 wording now points to K-01; §6 no longer asks for an `is_internal` query test; AC-007/AC-008 order fixed; §13 lists AC-001..AC-012 with REQ mapping.
> - **Still open:** ST-03 and the PRD minor gaps (`/sdlc-draft-prd`). ST-01/ST-02 (Fase 2a plans). MC-01..03 in the running app until TASK-015..017 are coded. Plan TASK-017 still says "REQ-012 has no spec AC yet"; it can now point to AC-009/AC-012.
> - **Projected Readiness Score:** 86/100 (Completeness 36/40, Clarity 27/30, Alignment 23/30).

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
