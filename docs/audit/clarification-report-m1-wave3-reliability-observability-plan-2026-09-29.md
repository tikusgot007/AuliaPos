# 🔍 Clarification Report [Review Iteration 1]

**Target document:** `plan/plan-process-m1-wave3-reliability-observability-v1.0.md` (v1.0)
**Reference:** `spec/spec-process-m1-wave3-reliability-observability.md` v1.1
**Session date:** 2026-09-29
**Readiness Score (document as submitted):** 79/100 (Critical Flaw Veto triggered)
**Readiness Score (after resolutions below):** 91/100
**Status:** Below Threshold at submission → **Good Enough (PROCEED)**. User invoked Human Override
(`AGENTS.md` §Clarification & Consistency Check Policy) and delegated resolution of all remaining
findings to the AI's technical judgment before the "Good Enough" threshold prompt could be shown.

**Score Breakdown (as submitted):**

- **Completeness (max 40):** 37 — task/REQ/AC/test coverage is thorough, but the ASSUMPTION-020
  decision-log obligation had no owning task (F-15), and the OI-002 failure path had no carry-over
  entry for AC-071 (F-02).
- **Clarity (max 30):** 24 — eight real ambiguities (F-03..F-09): undefined instance-lock staleness
  threshold, three time budgets stacked on one `postToCI4()` call with no precedence rule, undefined
  `METRICS_ENABLED=0` health semantics, unowned decrypt-counter increment site, undefined OI-002
  observation venue, path-based redaction vs. message-content guarantee, and a gauge with two
  plausible owning tasks.
- **Alignment (max 30):** 18 — F-01/F-16 (Phase 8 artifact task transitively gated on an
  OI-001-blocked task, contradicting the plan's own REQ-070 soft-gate and Contingency 3), F-13
  (wrong AC citation in TASK-012), F-14 (DEPLOY task depends on an optional/discardable phase),
  and F-10/F-11/F-12 (three task descriptions contradict the verified state of the actual
  WA-Gateway codebase).
- **Critical Flaw Veto:** **Yes, at submission.** F-01+F-16 together mean that in the most likely
  real-world scenario (OI-001 not yet supplied), the entire Phase 8 closure artifact is unreachable
  — defeating the plan's own stated soft-gate purpose (REQ-070/CB-10). This alone caps the score at
  79 regardless of weighted math. **Resolved below.**

---

## 1. 🚨 Critical Findings (Blockers) — all RESOLVED via Human Override

- **F-01 / F-16 — Requirement:** Dependency graph (plan §2, line 122) sets
  `TASK-041 Dep = TASK-038` and `TASK-043 Dep = TASK-038 + TASK-042`.
  - **Issue:** `TASK-038` is the real-protocol execution gated by OI-001
    (`DEP-009: BELUM tersedia`). This transitively blocks all of Phase 8 (the
    M1 exit-criteria artifact) whenever OI-001 is unavailable — directly
    contradicting REQ-070's soft gate ("M1 boleh ditutup untuk semua AC yang
    dapat dijalankan sekarang") and the plan's own Kontingensi 3 (line 368),
    which promises Ticket 15 can still run runnable ACs.
  - **Resolution Applied:** `TASK-041 Dep` → `TASK-040` (waits for the Phase 7
    approval decision, not TASK-038's actual execution). `TASK-043 Dep` →
    `TASK-042` only (drops `TASK-038`). The matrix artifact and its real-run
    harness can now be authored and partially executed independent of
    whether OI-001 has been supplied, while AC-049/047/051 still correctly
    report as `OPEN CARRY-OVER` per REQ-070.
  - **Plan sections to update:** §2 dependency graph, TASK-041, TASK-043.
- **F-02 — Requirement:** REQ-070's carry-over list (plan §1, line 78;
  TASK-041 description, line 221) only names AC-049 and the OI-001-dependent
  portions of AC-047/AC-051.
  - **Issue:** It never names **AC-071** (REQ-066/GW-21), even though
    REQ-066 is an equally hard gate (OI-002) with its own explicit "MUST NOT
    be claimed passed" language (spec REQ-066, AC-071). If OI-002 is only
    closed via source-reading (see Q1 resolution below), the plan has no
    honest place to record that AC-071 is unverified.
  - **Resolution Applied:** **AC-071 is added to the "carry-over terbuka"**
    section whenever OI-002 is closed only via static source verification
    (Baileys source read) without an empirically observed receipt.
    TASK-041/TASK-043 descriptions must list AC-071 alongside AC-049/047/051
    in that case.
  - **Plan sections to update:** TASK-019, TASK-041, TASK-043.

---

## 2. 🧩 Resolved Items & Agreements (Auto-Resolved via Human Override)

- **Q1 — OI-002 closure criterion.** Decision: **Option A.** A status-code mapping table derived
  purely from reading `node_modules/baileys` 6.7.24 source (no empirically observed receipt) is
  **sufficient to close the OI-002 gate and unblock TASK-027/028**, but the mapping MUST be labeled
  `empirically-unverified` in the decision log, and **AC-071 MUST be added to the "carry-over
  terbuka" section** until a real receipt is observed. This keeps REQ-070 honest without pulling M2
  scope forward or blocking the Phase 6 deploy. *Rationale:* REQ-066/CB-09 already scopes the
  handler as record-only with no consumer of the receipt, so the cost of a temporarily unverified
  mapping is low and reversible (additive column, no downstream logic depends on it yet).
- **F-13 — Wrong AC citation.** `TASK-012` VERIFY item (b) cites "AC-049/AC-054" for the
  `postToCI4()` 8000 ms + ~16000 ms worst-case timing assertion. AC-049 is the H1/H2 differential
  test, unrelated to timing budgets. **Corrected citation: AC-054 (and AC-059 for tick duration
  logging), not AC-049.**
- **F-14 — DEPLOY dependency on optional phase.** `TASK-031 Dep = TASK-026 + TASK-030` makes the
  Phase 6 production deploy of Ticket 12–14 depend on Phase 5 (GW-21 receipt recording), which
  ASSUMPTION-012 explicitly allows discarding as a unit if the project owner wants GW-21 kept pure
  M2. **Resolved: `TASK-031 Dep` → `TASK-026` only.** Phase 5 becomes deploy-independent, matching
  its documented disposability.
- **F-10 — `GROUP_NAME_CACHE_MAX_ENTRIES` already implemented.** Verified directly against
  `C:\projects\WA-Gateway\src\config\index.js:124` and `connectionManager.js:675,687`: the env var,
  its default (`500`), and its two consuming caches already exist in the live codebase. TASK-002's
  wording ("tambah ... `GROUP_NAME_CACHE_MAX_ENTRIES=500`") must be corrected by `/sdlc-plan-tasks`
  to "verify existing implementation matches §4.7 intent; add only the missing spec/GUD-005
  documentation reference (RISK-001)" — not framed as new implementation work.
- **F-11 — `isRunning` guard release already correct.** Verified against
  `incomingDelivery.js:114-136`: the `finally { isRunning = false; }` pattern already exists. Only
  the **per-event** error isolation (REQ-048) inside the `for` loop at lines 128-130 is genuinely
  new work for TASK-007. TASK-007's description should be narrowed accordingly.
- **F-12 — `GET /api/events` already exists.** Verified at `routes.js:267`. TASK-017's conditional
  wording ("bila endpoint ada") should be resolved to an unconditional assertion against the
  existing endpoint.

## 3. ⚠️ Assumed / Auto-Resolved / Out of Scope (delegated via Human Override)

- **F-03 — Instance-lock staleness thresholds undefined.**
  `[Assumed / Auto-Resolved]` — New env vars: `INSTANCE_LOCK_HEARTBEAT_INTERVAL_MS=5000`,
  `INSTANCE_LOCK_STALE_MS=15000` (3× the heartbeat interval — short enough to not delay GW-17 crash
  recovery, long enough to avoid false takeover from a single slow tick). To be added to plan
  TASK-002 and spec §4.7 by the respective authoring agents.
- **F-04 — Three time budgets stacked on one `postToCI4()` call, no precedence rule.**
  `[Assumed / Auto-Resolved]` — **High Risk.** `DELIVERY_EVENT_TIMEOUT_MS` and
  `HEARTBEAT_SEND_TIMEOUT_MS` are per-caller **overrides** of `ci4.requestTimeoutMs` for their
  respective call sites, not additional wrapper timeouts layered on top of the existing
  `ci4Client.js:21` `AbortController`. TASK-008/TASK-010 must NOT add a second `setTimeout`/abort
  around `postToCI4()` — they should parameterize the existing one per caller. Flagged for
  mandatory re-verification during `/sdlc-write-code` since a double-timeout bug (two competing
  `AbortController`s) would be a subtle, hard-to-test defect.
- **F-05 — `METRICS_ENABLED=0` health semantics undefined.**
  `[Assumed / Auto-Resolved]` — `delivery_ready` MUST evaluate to `unknown` (not `healthy`) when
  the metrics registry is disabled, consistent with ASSUMPTION-020's "no evidence ≠ healthy"
  principle for `receive_ready`.
- **F-06 — Decrypt-failure counter has no clearly assigned increment site.**
  `[Assumed / Auto-Resolved]` — `decrypt_failure_total` is incremented in `connectionManager`
  (the actual decryption error handler); `health.js` remains a pure function reading only the
  injected snapshot/count. This is a scope clarification for TASK-020, not a new task.
- **F-07 — OI-002 observation venue unspecified (TASK-019 predates OI-001's Phase 7 provisioning).**
  `[Assumed / Auto-Resolved]` — TASK-019 may perform **passive, read-only observation** of
  naturally occurring `messages.update` events on the already-connected production Gateway
  (`6281913500707`), without sending any test message and without waiting for the Phase 7 test
  instance. If no real event arrives during the session, TASK-019 falls back to
  source-code-only mapping (see Q1 resolution).
- **F-08 — Message-content redaction mechanism unclear (path-based `REDACT_PATHS` vs. free text).**
  `[Assumed / Auto-Resolved]` — Message content is guaranteed absent from logs by **contract**
  (no code path passes message text into a logged field/argument), enforced by a static guard
  test (already planned as TEST-009/TASK-015), not by adding new `REDACT_PATHS` entries.
- **F-09 — `event_buffer_size` gauge owned by a task that doesn't build the buffer.**
  `[Assumed / Auto-Resolved]` — Gauge wiring for `event_buffer_size` moves from TASK-011 to
  TASK-016 (where the dashboard event buffer is actually created in `logging/index.js`). TASK-011
  keeps ownership of `overflow_size` and `group_name_cache_size` only.
- **F-15 — ASSUMPTION-020 decision-log obligation has no explicit owning task.**
  `[Assumed / Auto-Resolved]` — Folded into TASK-020/TASK-025 (Phase 4) as an explicit VERIFY
  sub-item: the health model's "indirect evidence only" honesty statement must appear in the
  Ticket 14 decision log, not only in the Phase 6 deploy decision log (TASK-031).
- **F-17 — Terminology drift: "kabar status pengiriman (receipt)" / `receipt_state` / `status` /
  `wa_status` used somewhat interchangeably across spec and plan.**
  `[Assumed / Out of Scope]` — Not resolved this session. Recommend revisiting during
  `/sdlc-write-code` or a future `CONTEXT.md` update once GW-21 usage stabilizes; not urgent
  enough to block planning given the user's explicit fatigue-driven override.

## 4. 📝 Next Steps

- `/sdlc-plan-tasks` (same session or a new one, per the project's phase-per-session preference)
  MUST apply all resolutions in §1 and §3 above to
  `plan/plan-process-m1-wave3-reliability-observability-v1.0.md` before this plan is handed to
  `/sdlc-write-code`. Score is projected at 91/100 **after** those edits are applied — the plan as
  currently saved on disk is still at 79/100 and MUST NOT be executed as-is.
- No new canonical business terms were agreed upon this session (F-17 deferred) — `CONTEXT.md` is
  not updated.
- No architectural decisions met the ADR Triple Gate (all resolutions here are easily reversible
  dependency-graph/env-var/task-scope adjustments) — no ADR created under `docs/adr/`.
- Per the Remediation Protocol, the plan's authoring agent should prepend a
  `REMEDIATION STATUS: RESOLVED` block referencing this report once the edits are applied, and
  recompute/state the projected score in chat before routing to `/sdlc-write-code`.

---
> **Human Override Notice:** The user explicitly stated "capek, anda saja yg memutuskan semuanya"
> (delegating all remaining decisions to the AI's technical judgment) before the Readiness Score
> reached 80 and before the standard one-question-at-a-time grilling queue was exhausted. Per
> `AGENTS.md` §Clarification & Consistency Check Policy ("Human Override Primacy"), this session
> was ended immediately upon that instruction, all queued and newly-surfaced findings were
> auto-resolved using the AI's recommended technical judgment, and this report was generated and
> saved without further questions.
