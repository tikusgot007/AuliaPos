---
version: 1.0
date_created: 2026-09-23
owner: AuliaPos Inbox module
tags: [clarification, inbox, m3, handoff, collision-detection, assumptions]
---

> [!IMPORTANT]
> **REMEDIATION STATUS: RESOLVED** — 2026-09-23, authoring agent `/sdlc-define-specs`.
> All six locked resolutions in Section 2 / Section 4 were applied as **documentation-only** text edits. No code, test, UI, or migration was touched (suite stays at `298 tests / 948 assertions`, commit `51fb1fc`).
> - **ASSUMPTION-008:** stale "glossary does not yet contain these terms" sentence removed; canonical rule stated that a `Belum Diambil` Handoff is limited to an **active kasir**. ✅
> - **AC-H09:** "Locked by `F01`" overclaim replaced with the non-observable-guard wording; the observable contract is "409, no write, before the transaction". ✅
> - **ASSUMPTION-010 + §4.4:** exact coverage documented (`E18` = exactly-three-key for the ownership family; `H02b` = presence-only of `current_owner_id` for the state family); no `H02c` added. ✅
> - **ASSUMPTION-011:** non-auditable "1:1" phrasing dropped; the actual method names in `tests/session/InboxHandoffTest.php` are cited as the canonical ID source. ✅
> - **REQ-H06:** restated honestly (`assigned_to` changes **and `updated_at` is refreshed**; other columns unchanged). ✅
> - **§4.4 + REQ-H09 + §4.3 step 7:** HTTP **500** documented for the history-insert-failure rollback path. ✅
> - **Glossary (`CONTEXT.md`):** `Belum Diambil` narrowed to "any **active kasir**"; `Handoff` clarified for the Belum Diambil exception. ✅
> - **Spec version:** bumped `1.1 → 1.2` with a change-history table referencing this report.
> **Projected Readiness Score after remediation: 98/100** (Completeness 39/40, Clarity 29/30, Alignment 30/30; no Critical Flaw veto). Markdownlint: identical rule set vs committed v1.1 (`MD013/MD025/MD028/MD049/MD060`); the single `MD025` is pre-existing and unchanged — no new lint regression.

# 🔍 Clarification Report [Review Iteration 1]

**Date:** 2026-09-23
**Branch:** `feature/m3-operational-inbox-fase1a-task001`
**Target document:** `spec/spec-design-m3-operational-inbox-fase2a-handoff-collision.md` v1.1
**Upstream context:** `docs/audit/clarification-report-m3-fase2a-refactor-plan-2026-09-23.md`;
`plan/plan-feature-m3-operational-inbox-fase2a-v1.0.md` v1.0 (wins on conflict, RISK-01);
`plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md`; `docs/audit/clarification-report-m3-fase2-m2-gate-2026-09-22.md` (K-01..K-09);
`docs/audit/clarification-report-m3-fase2a-plan-2026-09-23.md` (Q1–Q9); `CONTEXT.md`;
`tests/session/InboxHandoffTest.php`; `app/Controllers/Inbox.php`
**Interrogation focus:** ASSUMPTION-008..011 (per user request), extended during REFINE
**Locked inputs honoured (not re-interrogated):** Q1, Q3, Q5, Q6, Q7, Q9, K-01..K-09, P-01..P-06, CR-01/02/05/06..09

**Readiness Score:** 90/100
**Status:** Good Enough

**Score Breakdown:**

- **Completeness (max 40):** 36 - All Handoff + Collision behaviours, payload rules, status codes and edge cases are present; the six findings are precision defects in the *documentation* (stale sentences, overclaimed test coverage, two undocumented facts), not missing functionality.
- **Clarity (max 30):** 27 - After this session's corrections the contract stops being misleading; deductions for the three sentences that did not match verifiable code/test reality (ASSUMPTION-008 staleness, AC-H09 "Locked by F01", REQ-H06 "no other column changes").
- **Alignment (max 30):** 27 - Remains 100% traceable to PRD v1.1 GH-006/GH-007 and K-01..K-09; deductions for the open `CONTEXT.md` vs Spec contradiction (Belum Diambil "any staff" vs "active kasir only") and the Spec-vs-code `updated_at` mismatch.
- **Critical Flaw Veto:** No - None. Every finding is a text-only correction with a verified, zero-cost resolution; no ownership path, gate order, atomicity guarantee or protected method is affected.

---

## 1. 🚨 Critical Findings (Blockers)

None. All six items interrogated this session were resolved with document-only decisions (zero code / test / UI changes), consistent with the already-green suite (`298 tests / 948 assertions`, commit `51fb1fc`).

## 2. 🧩 Resolved Items & Agreements

### ASSUMPTION-008 — "Belum Diambil" vs "tanpa pemilik" (glossary vs Spec)

- **Requirement (Spec §1.2):** "Glosarium `CONTEXT.md` belum memuat kedua istilah ini; usulan pencatatan (lazy creation) ada di `clarification-report-m3-fase2a-refactor-plan-2026-09-23.md` §3."
  - **Resolution:** **[Agreed — locked] Option A — the Spec wins.** Verified that `CONTEXT.md:37-46` now DOES contain `Belum Diambil` and `Tanpa Pemilik` (lazy creation already executed), so the Spec sentence is stale. Also verified a real contradiction: `CONTEXT.md:39-40` says the Belum Diambil tab "boleh diserahkan oleh staff mana pun", while REQ-H01 / CR-03 = A / the "Plan wins over Q2" decision and tests `E04(b)`, `F01-F04` allow only an **active kasir**. Resolution: narrow the glossary to "kasir aktif" (not "any staff") and correct the stale ASSUMPTION-008 sentence. Zero code/test/UI change. Option B (glossary wins → widen the gate) rejected: it reverses a locked decision and reopens the PRD v1.1-excluded forced-admin path.

### ASSUMPTION-009 — fail-fast 409 placement and its "Locked by F01" claim

- **Requirement (AC-H09):** "When the client's `expected_owner` differs from the server-read `assigned_to`, the request is rejected with 409 before any transaction begins ... Locked by `F01`; bodies must match the loser 409 byte-for-byte (`C02`/`E06`)."
  - **Resolution:** **[Agreed — locked] Option A — acknowledge a non-observable guard.** The fail-fast and the *losing conditional write* are black-box indistinguishable (byte-identical `status`/`message`/`current_owner_id`, both write nothing), so `F01` stays green even if the fail-fast is removed; the claim "Locked by F01" is a scope overclaim. Resolution: state the fail-fast as an authorisation/audit-trail guard whose **observable** contract is "409 with no write, before the transaction", and stop claiming that `F01` uniquely locks the placement. Zero code/test change. Option C (delete the fail-fast) rejected: it reopens the ABA window (`lepas()` → NULL → `ambil()`) that mis-attributes `from_user_id`.

### ASSUMPTION-010 — "one shared 409 body shape" only half-locked

- **Requirement (Spec §4.4 / ASSUMPTION-010):** "Both families MUST return the same key set ... Dikunci `H02b` (state) + `E18` (anti-leak tiga key)."
  - **Resolution:** **[Agreed — locked] Option X/B — honest coverage documentation.** Verified: `E18` (`assertCount(3, $json)`) locks the **ownership** family to exactly three keys; `H02b` (state/`selesai`) only asserts that `current_owner_id` exists and equals `7` — it does NOT lock the key count. Resolution (after the user revised an initial Option A to Option X): do NOT add a mirror test `H02c`; instead, document the coverage exactly as it is — the state family is locked for the *presence* of `current_owner_id` (`H02b`) while the *exactly-three-key* guarantee (`E18`) applies to the ownership family only. "One shared body shape" remains stated as design, with the asymmetry recorded. Zero code/test change (net -1 test vs the interim A choice).

### ASSUMPTION-011 — test-ID 1:1 mapping not auditable

- **Requirement (Spec §12):** "ID tes di §6 dan §12 merujuk `tests/session/InboxHandoffTest.php`: `H01-H08` ... `F01-F04` ... Bila nama file/ID berubah, dokumen ini yang harus disesuaikan."
  - **Resolution:** **[Agreed — locked] Option X — honest documentation.** Verified against the real file: `H01-H08`, `C01-C04`, `G01-G05`, `E01-E18`, `F01-F04` are complete and correctly named, but §12 quotes only a few IDs descriptively, so the "1:1" claim cannot be audited line-by-line and would drift further. Resolution: reference the actual method names in `InboxHandoffTest.php` as the canonical ID source, without overclaiming; no `H02c` added; no full inventory table. Zero code/test change.

### FINDING NEW-1 — REQ-H06 "no other column changes" vs code

- **Requirement (REQ-H06):** "Saat sukses `assigned_to` menjadi `to_user_id`; kolom lain tidak berubah."
  - **Resolution:** **[Agreed — locked] Option A — correct the Spec sentence.** Verified in `app/Controllers/Inbox.php:1177-1182`: the success path executes `UPDATE conversations SET assigned_to = ?, updated_at = ? WHERE id = ? AND assigned_to <=> ?`, so `updated_at` is refreshed on every successful Handoff — contradicting "no other column changes". No test locks `updated_at` on the success path (`F01` covers it only on the fail-fast path). Resolution: restate REQ-H06 as "`assigned_to` becomes `to_user_id` and `updated_at` is refreshed; all other columns (`snoozed_until`, `last_message_*`, `status`) are unchanged" — consistent with `E07` (snooze not reset). Zero code/test change. Option B (stop writing `updated_at`) rejected: it changes runtime behaviour without a real need.

### FINDING NEW-2 — rollback path returns HTTP 500, undocumented

- **Requirement (Spec §4.4 / REQ-H09 / §4.3 langkah 7):** "Collision response contract" lists only `400 / 403 / 404 / 409 / 200`; step 7 says "History-insert failure = `transRollback()` with ownership unchanged" without naming the HTTP code.
  - **Resolution:** **[Agreed — locked] Option A — document HTTP 500.** Verified in `app/Controllers/Inbox.php:1223-1232`: `catch` → `transRollback()` → `setStatusCode(500)` with the fixed message "Gagal menyimpan riwayat Handoff, percakapan tidak berpindah." (locked by `C03`). Resolution: add `500` to the §4.4 response contract and clarify REQ-H09 / step 7 so the rollback status code is no longer documented only inside the test file. Zero code/test change.

## 3. ⚠️ Assumed / Auto-Resolved / Out of Scope (The 20% we skip)

- **Scenario / Question:** Directly editing `CONTEXT.md` during this session (narrowing "staff mana pun" → "kasir aktif").
  - **Handling:** `[Assumed / Out of Scope]` — the Clarification Analyst interrogates and reports but does not author source/glossary edits; the glossary change is routed to the authoring agent. No file write happens here.
- **Scenario / Question:** Whether ASSUMPTION-001..007 need the same interrogation round.
  - **Handling:** `[Assumed / Out of Scope]` — not requested (focus was ASSUMPTION-008..011) and not re-opened; they remain locked as written in the Spec.
- **Scenario / Question:** Whether the interim choice (add mirror test `H02c`) should still be pursued as a backlog item.
  - **Handling:** `[Assumed / Out of Scope]` — resolved by the user's Option X revision (010 & 011 = documentation-only); recorded here only as a possible future hardening, not a task in Fase 2a.
- **Scenario / Question:** Return type and internal placement of the fail-fast guard beyond the documented observable contract.
  - **Handling:** `[Assumed / Auto-Resolved]` — keep the existing private-helper conventions in `app/Controllers/Inbox.php`; the observable contract ("409, no write, before the transaction") is what the Spec documents.

## 4. 📝 Next Steps

- **Spec remediation (`spec/spec-design-m3-operational-inbox-fase2a-handoff-collision.md`)** — surgical text edits only, via `/sdlc-define-specs` (or `/code-janitor` for light text edits):
  1. ASSUMPTION-008: remove the stale "glosarium belum memuat" sentence; state the canonical rule "Belum Diambil handoff is limited to an active kasir".
  2. AC-H09: replace the "Locked by `F01`" claim with the non-observable-guard wording and the observable contract ("409, no write, before the transaction").
  3. ASSUMPTION-010 / §4.4: document the exact coverage — `E18` locks exactly-three-keys for the **ownership** family; `H02b` locks the *presence* of `current_owner_id` for the **state** family.
  4. ASSUMPTION-011 / §12: reference the actual method names in `InboxHandoffTest.php` as the canonical ID source; drop the non-auditable 1:1 phrasing; no `H02c`.
  5. REQ-H06: restate that `updated_at` is refreshed and other columns stay unchanged.
  6. §4.4 + REQ-H09/step 7: add HTTP `500` for the history-insert-failure rollback path.
- **Domain Glossary (`CONTEXT.md`)** — narrow the `Belum Diambil` entry from "staff mana pun" to "kasir aktif"; keep `_Avoid_` synonyms. Route to the authoring agent (lazy-creation rule already satisfied).
- **ADR:** none qualifies (Triple Gate: these are reversible text clarifications, unsurprising given the locked decisions, and carry no new trade-off).
- **Verification after remediation:** re-run `markdownlint` on the Spec; `composer test` is **not** required by these changes because no code/test/UI/migration was touched (suite remains `298 tests / 948 assertions`, commit `51fb1fc`).

## 5. 📌 Evidence Read This Session

- `spec/spec-design-m3-operational-inbox-fase2a-handoff-collision.md` (v1.1, 392 lines) — target.
- `docs/audit/clarification-report-m3-fase2a-refactor-plan-2026-09-23.md` (CR-03/CR-04 source).
- `docs/audit/clarification-report-m3-fase2a-plan-2026-09-23.md`, `docs/audit/clarification-report-m3-fase2-m2-gate-2026-09-22.md`, `docs/audit/code-review-m3-fase2a-2026-09-23.md` (context).
- `tests/session/InboxHandoffTest.php` (1.210 lines) — verified `H01-H08`, `C01-C04`, `G01-G05`, `E01-E18`, `F01-F04`; `E18` = `assertCount(3)`; `H02b` = presence-only; `C03` = `assertStatus(500)`; `F01` = fail-fast observably identical to a lost conditional write.
- `app/Controllers/Inbox.php` (lines 1100-1260) — gate order, conditional write incl. `updated_at`, rollback + HTTP 500, success envelope.
- `CONTEXT.md` (47 lines) — `Belum Diambil` / `Tanpa Pemilik` entries already present.

---
> **User Decision Prompt:**
> The document has achieved a Readiness Score of 90/100. It is ready for the next phase. Do you want to **PROCEED** to the next phase, or do you want to **REFINE** and clarify further?
>
> **User decision (recorded):** PROCEED + save this report. Remediation of the Spec / `CONTEXT.md` text is routed to the authoring agent; this session changes no source code.
