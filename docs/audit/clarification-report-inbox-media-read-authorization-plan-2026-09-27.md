<!-- markdownlint-disable -->

# 🔍 Clarification Report [Review Iteration 1 — Inbox Media Read Authorization Plan (ALT-003 / AUTHZ-02)]

> [!SUCCESS]
> **REMEDIATION STATUS: RESOLVED**
> This audit report has been remediated by Planner Architect.
> - **Projected Readiness Score:** 99/100
> - All six resolutions in Section 2 were folded into `plan/plan-refactor-inbox-media-read-authorization-v1.0.md` (v1.0 → v1.1): TASK-001 now splits the comment update into two separate C-1/REQ-002 comments; TASK-002 now also fixes the stale class docblock and stale assertion message; TASK-003 now adds the `InboxMediaAuthSpy::$gatewayResponse` mutable property plus the third `gatewayMediaDownloadCalls` assertion; TASK-004 now adds the missing `seedMessage()` helper before the new anchor test; TASK-007 now targets a new named `### Media Read Authorization` sub-section instead of an ambiguous "after the table (or ...)" placement. No requirement, phase structure, or `Dep` graph was altered — a v1.1 revision note documents the change without disturbing existing task numbering.

**Readiness Score:** 91/100
**Status:** Good Enough

**Score Breakdown:**

- **Completeness (max 40):** 36 — All tasks, dependencies, tests, rollback steps, and risks are mapped and were verified line-by-line against the real code (`app/Controllers/Inbox.php`, `tests/session/InboxMediaAuthTest.php`, `tests/session/InboxHandoffTest.php`). One minor gap remains: the rollback plan does not mention reverting the new `InboxMediaAuthSpy::$gatewayResponse` property if Phase 1 is reverted.
- **Clarity (max 30):** 26 — Five ambiguous points (TASK-001 comment placement, TASK-003 Gateway-spy override mechanism, TASK-002 stale class-doc/assertion-message text, TASK-007 documentation placement, TASK-004 message-seeding mechanism) were surfaced and resolved explicitly during this session.
- **Alignment (max 30):** 29 — The plan tracks spec v1.1 with high precision (exact line numbers, requirement IDs, and acceptance-criteria IDs all matched the real controller and test files on verification).
- **Critical Flaw Veto:** No — all findings were execution-detail ambiguities, not a fundamental contradiction invalidating the plan's premise.

---

## 1. 🚨 Critical Findings (Blockers)

None.

## 2. 🧩 Resolved Items & Agreements

- **Requirement:** TASK-001 — "Update the inline comment above the deleted block to reference REQ-002 instead of the old SEC-002 rationale (mirror the spec Section 8 example)."
  - **Issue:** The existing SEC-002 comment (`Inbox.php:410-415`) sits above the code that is **kept** (the conversation lookup), not above the code that is **deleted** (`cekOwnership()`). The spec's Section 8 example shows two separate comments in two different places, not one comment edited in place.
  - **Resolution:** Split into two comments, mirroring the spec exactly: (1) a new C-1 comment before the conversation lookup explaining why it is retained (AC-007/404); (2) a new REQ-002 comment placed where the deleted `cekOwnership()` block used to be, explaining that read access is intentionally open to all logged-in staff.

- **Requirement:** TASK-003 — "override `InboxMediaAuthSpy::callGatewayMediaDownload()` to return `['ok' => false, 'status' => 410, ...]`."
  - **Issue:** The existing spy hardcodes a single always-succeeds response, used by all 4 existing tests. A literal "override to return X" has no mechanism in the current class shape.
  - **Resolution:** Add a public, mutable property `$gatewayResponse` on `InboxMediaAuthSpy` (default = the current success response), and have `callGatewayMediaDownload()` return it. Existing tests are unaffected (same default); the new TASK-003 test sets `$controller->gatewayResponse = [...410...]` before calling `media()`.

- **Requirement:** TASK-002 — rewrite/rename the non-holder test in `InboxMediaAuthTest.php`.
  - **Issue:** The class-level docblock (lines 12-18) and the assertion message at line 97 both describe the **old** behavior ("non-holder rejected 403"), which becomes factually wrong after the rewrite (non-holder is now served `200`).
  - **Resolution:** TASK-002 is expanded to also update the class docblock (open-read rule) and the assertion message (e.g., "served from disk for a non-holder, without contacting the Gateway"), preventing a stale/misleading comment from surviving next to `RISK-001`'s regression concern.

- **Requirement:** TASK-007 — "Add one short paragraph after the table (**or** in Section 8's Balas Pesan seam list)."
  - **Issue:** The two suggested locations are topically mismatched — Section 8's "Balas Pesan" sub-section is about the quote feature, not read authorization — and the "or" leaves the actual placement undecided.
  - **Resolution:** Create a new named sub-section `### Media Read Authorization` in Section 8, parallel to the existing `### Handoff and Collision Detection` pattern (one sub-section per architectural decision), rather than mixing it into the unrelated Balas Pesan sub-section.

- **Requirement:** TASK-004 — new named SEC-001 regression-anchor test in `InboxHandoffTest.php` asserting "`200` plus a non-empty `messages` array."
  - **Issue:** `InboxHandoffTest.php` has no existing helper to insert a `messages` row (only `seedUser()` and `seedConversation()` exist), so "non-empty messages array" cannot be produced as written.
  - **Resolution:** Add a small `seedMessage()` helper to `InboxHandoffTest.php` (same pattern as the existing `seedConversation()`), inserting one minimal `messages` row for the seeded conversation.

- **Requirement:** TASK-003 — AC-009/C-2 test assertions ("`410`" + "`media_confirmed_gone_at` non-null").
  - **Issue:** Those two assertions alone do not actually prove the request reached the Gateway path (the stated purpose of the test) — they only prove the end result, which could theoretically be reached another way.
  - **Resolution:** Add a third assertion, `assertSame(1, $controller->gatewayMediaDownloadCalls, ...)`, matching the pattern already used by the other 4 tests in the same file to prove which code path executed.

## 3. ⚠️ Assumed / Auto-Resolved / Out of Scope (The 20% we skip)

None — every finding in this session was resolved via a direct question to the user, not assumed.

## 4. 📝 Next Steps

- `/sdlc-plan-tasks` must fold the six resolutions above into TASK-001, TASK-002, TASK-003, TASK-004, and TASK-007 of `plan/plan-refactor-inbox-media-read-authorization-v1.0.md`.
- No new domain term was introduced → `CONTEXT.md` unchanged.
- No architectural decision passed the Triple Gate → no new ADR.
- Proceed to `/sdlc-write-code` once the plan is updated with these resolutions.

---

> **User Decision Prompt:**
> The document has achieved a Readiness Score of 91/100. It is ready for the next phase.
>
> **User's decision (2026-09-27):** PROCEED.

## 5. Evidence Index

- `plan/plan-refactor-inbox-media-read-authorization-v1.0.md` — target plan (v1.0), all 9 tasks across 2 phases.
- `spec/spec-design-inbox-read-authorization.md` — source spec (v1.1), REQ-001–REQ-005, SEC-001, AC-001–AC-009.
- `app/Controllers/Inbox.php` — `media()` `:396-551` (SEC-002 comment `:410-415`, conversation lookup `:416-423`, ownership guard to remove `:425-431`, `410` write `:531-535`); `apiMessages()` `:303-332`; `cekOwnership()` `:761-773`.
- `tests/session/InboxMediaAuthTest.php` — full file read (216 lines): class docblock `:12-18`, 4 existing tests `:79-152`, `InboxMediaAuthSpy` `:205-215`.
- `tests/session/InboxHandoffTest.php` — helpers `:1-135` (`seedUser()`, `seedConversation()`, no `seedMessage()`), existing SEC-001 incidental assertion `:596-598`.
- `docs/ARCHITECTURE.md` — Section 7 HTTP surface table `:243-269`; Section 8 sub-section pattern (`### Handoff and Collision Detection` `:309-316`, `### Balas Pesan` `:318-332`).
- `.claude/instructions/memory.instructions.md` — PHPUnit baseline (536 tests / 2058 assertions, `:134`).
