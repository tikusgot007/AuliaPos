# 🔍 Clarification Report [Review Iteration 1]

**Target document:** `spec/spec-process-m1-wave3-reliability-observability.md` (v1.0)
**Session date:** 2026-09-29
**Readiness Score (document as submitted):** 71/100
**Readiness Score (projected after resolutions below are applied):** 90/100
**Status:** Below Threshold at submission → **Good Enough (PROCEED)** once resolutions are applied by `/sdlc-define-specs`.

**Score Breakdown (as submitted):**

- **Completeness (max 40):** 28 — Requirement block E-W5 (Ticket 15) is missing entirely from §3; several config knobs referenced in prose are absent from §4.7; the metrics registry does not cover all limits REQ-053 requires visible.
- **Clarity (max 30):** 21 — `send_ready`/`receive_ready`/`delivery_ready` were named but not formulated; the per-event time budget (REQ-049) could not mathematically hold LID + media + AuliaPos call inside 8000 ms; single-instance lock mechanism was underspecified for crash recovery.
- **Alignment (max 30):** 22 — Orphaned ACs (REQ-068..071 referenced but never defined), OI-001 dependency not propagated to AC-047/AC-051, ASSUMPTION-015 contradicted its own E-W2 requirement count, and §10 made an unsupported claim about closing ASSUMPTION-009.
- **Critical Flaw Veto:** Yes, at submission — REQ-052 (single-instance guard) risked breaking GW-17 crash recovery and AC-048 if implemented as a naive lock file; the missing E-W5 block meant the M1 closure gate (Ticket 15) had no formal requirements. Both are resolved below.

**Remediation Protocol note for the authoring agent:** apply all 14 resolutions in §1 before re-submitting for `/sdlc-audit-consistency` or `/sdlc-plan-tasks`. Recompute the score mentally after edits and prepend a `REMEDIATION STATUS: RESOLVED` block per AGENTS.md protocol.

---

## 1. 🚨 Critical Findings (Blockers) — all RESOLVED via user decision

| ID | Finding | User Decision | Spec sections to update |
|---|---|---|---|
| **CB-01** | E-W5 requirement block (Ticket 15) never defined in §3; REQ-068..071 only appear in AC mapping/pemetaan, making AC-073..AC-076 orphaned. §13.9's self-score claim ("lima ticket E-W1..E-W5 punya REQ, AC...") is factually false. | **(A)** Write `### E-W5` with REQ-068..071 in §3. For the test matrix itself: reuse prior real-run evidence **only if** produced on a commit that is an ancestor of the final HEAD; otherwise re-run the real protocol. | §3 (new E-W5), §5 (AC-073..076 already exist, just need REQ anchors), §6, §13.9 |
| **CB-02** | `HEALTH_INBOUND_STALE_MS` referenced in ASSUMPTION-020 but absent from §4.7. `send_ready`/`receive_ready`/`delivery_ready` (REQ-062) named with no formula. | **(A)** Formulas fixed: `send_ready` = socket connected AND last probe success within `HEALTH_PROBE_FRESH_MS`. `receive_ready` = probe fresh OR inbound message stored within new `HEALTH_INBOUND_STALE_MS` (default `600000` ms); no evidence at all → `unknown`, not `false`. `delivery_ready` = `last_delivery_ok_at` fresh within `HEALTH_PROBE_FRESH_MS` AND `pending`/`overflow` gauges below warn thresholds. | §3 REQ-062/063, §4.2, §4.7 (add `HEALTH_INBOUND_STALE_MS=600000`) |
| **CB-03** | REQ-053 demands metrics visibility for overflow, own-sent list, group-name cache, and dashboard event buffer, but §4.3 only exposes `overflow_size`. AC-058 requires gauges that don't exist. | **(A)** Add gauges `group_name_cache_size` and `event_buffer_size` to §4.3. | §3 REQ-053, §4.3, AC-058 |
| **CB-04** | Naive lock-file single-instance guard (REQ-052) can leave a stale lock after `SIGKILL`/crash, breaking GW-17 auto-recovery and failing AC-048 (the very crash/restart test it should support). | **(A)** Lock file stores PID + start timestamp with periodic heartbeat; on start, if the recorded PID is no longer alive (checked directly against the OS) or the heartbeat is stale, the lock is automatically taken over. No new dependency; works identically on Windows and the JSON fallback (Android). | §3 REQ-052, AC-057 |
| **CB-05** | REQ-063 downgrades `status` to `disconnected` on a single failed/stale probe, risking a false "offline" window up to `HEALTH_PROBE_INTERVAL_MS` (5 min) that blocks the kasir's send button in AuliaPos even though the socket and delivery path are fine. | **(A)** `status` is only downgraded after **N consecutive** probe failures (threshold to be fixed by plan, e.g. 2–3). A new field `socket_status` carries the raw socket state separately from the debounced `status`/health verdict. | §3 REQ-063, §4.2 (add `socket_status`), AC-068 |
| **CB-06** | Ticket 05's isolated test instance needs a live WhatsApp session, but OI-001 is only a contact number, not a Gateway account; REQ-046 (isolated instance, MUST NOT touch active `auth/`) conflicts with GW-18 if a copy of the active session is reused. | **(A)** A **third WhatsApp account** is provisioned as the isolated test Gateway (clean session, one-time QR scan). Arm H1 must send `/send` **from this test account** to contact B's `pn` address — not from the production number `6281913500707` — or the reproduction is invalid. Recorded as new decision **D-14**. | §3 REQ-046, AC-048/049/051, §11 DAT-002 (OI-001 now needs two external inputs: test account + test contact number) |
| **CB-07** | AC-047 (decrypt-fail-then-retry) and AC-051 (explicitly mentions "uji pembeda memakai nomor OI-001") both depend on the OI-001/D-14 test session but were not marked BLOCKED like AC-049, contradicting the spec's own rule that unmeasurable claims must not be asserted. | **(A)** Mark AC-047 and AC-051 as OI-001-dependent for the portions that require the test session/number; portions that don't (e.g. AC-047 under `WA_DIAG_RAW_MESSAGE=0`) remain testable now. Update §13.1's automation claim accordingly. | §5 AC-047/AC-051, §13.1 |
| **CB-08** | No mapping table from Baileys' numeric `messages.update` status codes to the `receipt_state` enum (`pending/sent/delivered/read/failed`); EXT-001 admits the exact 6.7.24 payload shape "MUST diverifikasi di plan sebelum REQ-066 dikunci" — i.e. the requirement is locked before the fact it depends on is verified. | Folded into **CB-09 (A)**: promoted to explicit open item **OI-002** — hard gate before REQ-066 implementation. | §1.2 (new OI-002 block), §3 REQ-066, §11 EXT-001 |
| **CB-09** | ASSUMPTION-012 (GW-21 Gateway-only) is fine in principle, but §10's rationale claims this "menutup sebagian ASSUMPTION-009" — false, since nothing in Wave 3 consumes the recorded receipt to resolve an ambiguous `in_flight` operation. | **(A)** Keep GW-21 as record-only in Wave 3 (per owner's "measure/scope only" instruction). Remove the unsupported claim in §10. Require **OI-002** (payload shape + status mapping verification) as a hard gate before REQ-066 is locked/implemented. | §10 (remove claim), §3 REQ-066, §1.2 (OI-002) |
| **CB-10** | "Kriteria keluar M1" (M1 exit criteria) referenced by AC-075 is never defined; no statement on whether M1 may close while AC-049 remains BLOCKED. | **(B)** Soft gate: M1 may close for every AC that can actually be run now; AC-049 (and OI-001-dependent portions of AC-047/AC-051) are recorded as an explicit **open carry-over** with an owner action item, and are **not** counted as passed. Ticket 15 report must include a "carry-over terbuka" section. | §3 (new E-W5, REQ-070), AC-075, §13 |
| **CB-11** | ASSUMPTION-015 claims worker fixes are limited to "five things," but E-W2 actually contains 8 REQs (047–054); §2 names three workers (`incomingDelivery`, `heartbeat`, `outgoingOperationService`) but E-W2 has zero requirements for `heartbeat` correctness, so "correct" is undefined for one of the three workers Ticket 12 claims to cover. | **(A)** Extend Ticket 12/E-W2 to include minimal correctness invariants for `heartbeat` (overlap guard, per-cycle error isolation, send time budget). Rewrite ASSUMPTION-015 to match the actual 8 REQs plus the new heartbeat items. | §1.2 (rewrite ASSUMPTION-015), §3 (E-W2 additions), §5 (new AC) |
| **CB-12** | Claim that structured logging is purely "additive" (REQ-056) plus JSON-per-line (REQ-055) needed verification against the actual logger, and no explicit default log level was stated (risk of unbounded `logs/gateway.log` growth per ASSUMPTION-019). | **Verified against code, not assumed:** `src/logging/index.js` in `C:\projects\WA-Gateway` already wraps `pino` (JSON-per-line by default) and `console.*` is used only at the 2 documented fatal startup paths. Default `logLevel` in `src/config/index.js` is already `'info'`, not `'debug'` — so the "additive" claim and the noise-volume risk are both largely unfounded. **Not a blocker.** Spec should still state the default level (`info`) explicitly in §4.7 for completeness. | §4.7 (add `LOG_LEVEL` default note) |
| **CB-13** | §4.7 sets `HEALTH_PROBE_ENABLED` default to `1` (active) while §9 "Ask first" lists "mengaktifkan probe reachability secara default di lingkungan produksi" as requiring prior approval — a direct contradiction. | **(A)** Probe defaults to **active** (`1`) in all environments including production; the conflicting "Ask first" bullet in §9 is removed since the decision is now made explicitly here. | §4.7, §9 (remove the bullet) |
| **CB-14** | REQ-049's stated 8000 ms per-event budget (`DELIVERY_EVENT_TIMEOUT_MS`) cannot mathematically enclose LID lookup (2000 ms) + media download (6000 ms) + the AuliaPos POST budget (8000 ms) — summing to ~16000 ms worst case, contradicting AC-054 which only exercises `postToCI4` hanging. | **(B)** `DELIVERY_EVENT_TIMEOUT_MS` (8000 ms) bounds **only** the AuliaPos POST call; LID (2000 ms) and media download (6000 ms) keep their own independent limits. The true worst-case single-event bound (~16000 ms) MUST be written explicitly in REQ-049/§12, and compared against `SHUTDOWN_DRAIN_MS` (5000 ms) — an event still in-flight when drain elapses simply stays persisted for the next cycle, not lost. | §3 REQ-049, AC-054, §12 |

---

## 2. 🧩 Resolved Items & Agreements (already correct in v1.0, confirmed)

- **OI-001 correctly framed as OPEN INPUT** (not an assumption); AC-049 correctly `BLOCKED` without it. Now expanded to require **two** external inputs (per D-14): a test Gateway account and a never-contacted test number.
- **New open item OI-002**: Baileys 6.7.24 `messages.update` payload shape and the numeric-status → `receipt_state` mapping table must be verified before REQ-066 is implemented.
- **New decision D-14**: Ticket 05's isolated test instance uses a third WhatsApp account with a clean session (one-time QR scan), never a copy of the active session's `auth/` folder.
- Evidentiary honesty requirements (no claims of "fixed" for GW-11/GW-25/GW-21, non-destructive dead-lettering, etc.) were already sound and remain unchanged.
- Out-of-scope boundaries (Ticket 06–11/16, M2, AuliaPos code changes under CON-015) were already correctly and explicitly excluded.

## 3. ⚠️ Assumed / Auto-Resolved / Out of Scope

- **Exact `messages.update` payload shape** — `[Assumed / Out of Scope]` for the spec itself; resolved as gate **OI-002** to be closed during `/sdlc-plan-tasks`/implementation, not guessed here.
- **AC-049 and the OI-001-dependent portions of AC-047/AC-051** — `[Assumed / Out of Scope]` for M1 closure purposes per CB-10 (B): recorded as open carry-over with an explicit owner action, not counted as passed.
- **Worst-case ~16s per-event budget vs. 5s shutdown drain** — `[Assumed / Auto-Resolved]`: accepted because an unfinished event is simply re-processed on the next cycle after restart (REQ-051 already guarantees this), not lost.
- **No log rotation (ASSUMPTION-019)** — `[Assumed / Out of Scope]`, risk accepted; somewhat mitigated by the verified `info` default log level (CB-12).

## 4. 📝 Next Steps

1. **`/sdlc-define-specs` (required next)** — apply all 14 resolutions above to `spec-process-m1-wave3-reliability-observability.md`, bump to **v1.1**, and run the Remediation Protocol (mental score recompute + `REMEDIATION STATUS: RESOLVED` block prepended to this report).
2. Optionally re-run `/sdlc-clarify-reqs` or `/sdlc-audit-consistency` on v1.1 to confirm the resolutions were fully carried through before `/sdlc-plan-tasks`.
3. In the resulting plan: **OI-002** must be the first task under Ticket 14 (verify payload shape before building the `messages.update` handler), and provisioning the **D-14** test account/session must be a prerequisite for any Ticket 05 task.
4. No new domain terms were agreed in this session — `CONTEXT.md` is not updated (lazy creation preserved). No decision met the ADR Triple Gate (all are easily reversible) — no ADR created. If GW-21 is later promoted to a binding cross-repo receipt contract touching AuliaPos, that decision would require an ADR at that time.

---

> **User Decision Prompt (resolved):** User selected **PROCEED** at the projected 90/100 score, conditional on `/sdlc-define-specs` applying the 14 resolutions above.
