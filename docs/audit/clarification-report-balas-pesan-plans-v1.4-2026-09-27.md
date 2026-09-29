<!-- markdownlint-disable -->

> [!IMPORTANT]
> **REMEDIATION STATUS: RESOLVED (spec-side + plan-side)**
> The spec-side remediation of this audit report has been applied by the Specification Architect; the plan-side remediation has been applied by the Planner Architect.
> - **Projected Readiness Score:** 93/100
> - **Artifact revised:** `spec/spec-design-balas-pesan.md` → **v1.5** (Section 4.3/12: F-A intra-conversation `400` guard + F-D source-lookup failure taxonomy; `REQ-011`/`REQ-013`: F-B `quoted_sender_label IS NULL` not-found discriminator + non-NULL found-label with `"Pengirim"` fallback; `REQ-001`/`REQ-001a`/`CON-001`: F-C malformed-`quoted` degradation). Additive only; no other requirement changed; no new ADR; `CONTEXT.md` unchanged.
> - **Plan-side remediation applied (additive; no `AC`/`REQ` changed):** `plan-feature-balas-pesan-auliapos-v1.0.md` → TASK-002/006 (F-A intra-conversation `400` guard + F-D source-lookup taxonomy), TASK-010 (F-B non-NULL `quoted_sender_label` with `"Pengirim"` fallback), TASK-011 (F-B `quoted_sender_label IS NULL`-only not-found discriminator), F-E recorded as `[Assumed]` (ASSUMPTION-008/009/010), spec pointers → v1.5; `plan-feature-balas-pesan-wa-gateway-v1.0.md` → TASK-001/002 (F-C malformed-`quoted` degradation), spec pointers → v1.5. Report Next Steps 1 and 3 are **complete**.

# 🔍 Clarification Report [Review Iteration 2]

**Target:** `plan-feature-balas-pesan-auliapos-v1.0.md` + `plan-feature-balas-pesan-wa-gateway-v1.0.md` (revised) vs `spec/spec-design-balas-pesan.md` v1.4
**Readiness Score:** 89/100
**Status:** Good Enough

**Score Breakdown:**

- **Completeness (max 40):** 36 - All vertical slices, the three failure reactions (a)/(b)/(c), idempotency reuse, media quoting, incoming-quote resolution, and rollback remain covered, and the four material findings (F-A..F-D) are now defined. Points lost only for the three minor unstated defaults in F-E.
- **Clarity (max 30):** 27 - After this session every remaining behavior is deterministic: the conversation-boundary rejection, the not-found discriminator, the malformed-`quoted` degradation, and the source-lookup failure taxonomy.
- **Alignment (max 30):** 26 - Traceability to `REQ`/`AC`/`GUD-001` is strong and the v1.4 corrections (three-valued `quoted_media_available`, `fromMe`, soft-delete-inclusive lookup) are correctly reflected in the revised plans. Deductions because F-A requires an upstream amendment to spec v1.4 Section 4.3/Section 12, and the live PRD GH-015 AC divergence remains carry-forward.
- **Critical Flaw Veto:** No - the single blocking finding (F-A, an unapplied Iteration 1 resolution that left an IDOR / cross-conversation gap) was resolved in-session; no fundamental contradiction remains downstream.

---

## 1. 🚨 Critical Findings (Blockers)

None remaining. The one blocking finding (F-A) was resolved in-session; the score is above the 80-point threshold.

- **Requirement:** `TASK-002`/`TASK-006` - *"Ambil pesan sumber dari DB via lookup soft-delete-inclusive."*
  - **Issue:** The revised plan contained **no** conversation-boundary guard. `grep` for `conversation_id|cekOwnership|ownership|400|403` across the AuliaPos plan returned only the route-drift note (line 21). `cekOwnership()` (`app/Controllers/Inbox.php:984` for media, invoked `:2157` for text) validates the **destination** conversation only, so a cashier could quote arbitrary content from another conversation - an IDOR and semantically **Teruskan**, which is explicitly Out of Scope (`spec/spec-design-balas-pesan.md:31`).
  - **Resolution:** See Section 2 (F-A). **Resolved** - no remaining blocker.

## 2. 🧩 Resolved Items & Agreements

- **Requirement (F-A):** source lookup in `Inbox::kirim()`/`Inbox::kirimMedia()`.
  - **Resolution:** Reject with **`400`** when the source row's `messages.conversation_id !==` the target conversation id, added as a guard clause alongside the existing `cekOwnership()`. This closes the IDOR and enforces the "Teruskan = Out of Scope" boundary. The same rule must be written into **spec v1.4 Section 4.3 + Section 12** via `/sdlc-define-specs`, and covered by an automated test ("cross-conversation quote -> 400").

- **Requirement (F-B):** not-found discriminator for incoming quotes (`TASK-010`/`TASK-011`, `REQ-011`/`REQ-013`).
  - **Resolution:** Use **`quoted_sender_label IS NULL`** as the sole "not found" marker. On found, `TASK-010` MUST write a non-NULL `quoted_sender_label` - substituting the `SenderIdentityFormatter::LABEL_FALLBACK` (`"Pengirim"`) when the formatter returns `null` (e.g. legacy group-JID rows, `app/Services/SenderIdentityFormatter.php:58-61`). On not-found, the label MUST NOT be written (stays `NULL`). The UI MUST NOT detect the generic label by comparing the snippet string. No new column (`GUD-001`).

- **Requirement (F-C):** malformed `quoted` handling in the Gateway plan (`TASK-001`, `REQ-001`/`CON-001`).
  - **Resolution:** Treat a malformed `quoted` (not an object, or empty `wa_message_id`) as "no quote": still send the message body, return `sent:true, quote_applied:false`, and log the quote-forming failure (**"gagal dengan suara, bukan senyap"**). `CON-001` is authoritative: a quote defect must never drop the user's message. Strict validation remains only for the pre-existing non-`quoted` fields.

- **Requirement (F-D):** source-lookup failure taxonomy in AuliaPos (`TASK-002`/`TASK-006`).
  - **Resolution:** Reject with **`400`** for (1) an ID that does not exist and (2) an ID in another conversation (F-A). A source whose `wa_message_id` is a local placeholder (`'local-' . bin2hex(...)`, `app/Controllers/Inbox.php:2224`) is still a legitimate message: send it with `quoted` and let the Gateway degrade to `quote_applied:false` (UI shows "Terkirim tanpa kutipan"). Soft-deleted sources remain accepted. No snapshot columns are written for the `400` cases.

## 3. ⚠️ Assumed / Auto-Resolved / Out of Scope (The 20% we skip)

- **Scenario / Question (F4):** persistence of the "Terkirim tanpa kutipan" marker (`AC-003`).
  - **Handling:** `[Assumed / Auto-Resolved]` - Ephemeral: shown on the response-rendered bubble only; does not survive a thread reload/polling. `AC-003` is satisfied at response-processing time. No new column (honors `GUD-001`).
- **Scenario / Question (F7):** exact snippet truncation rule.
  - **Handling:** `[Assumed / Auto-Resolved]` - `mb_substr($text, 0, 200)`, appending `…` only when truncated (multibyte-safe, matching the spec's "mis. 200 karakter").
- **Scenario / Question (F8):** Gateway returns `sent:true` but omits `quote_applied` (partial rollout).
  - **Handling:** `[Assumed / Auto-Resolved]` - Treat a missing `quote_applied` as `false`; the UI shows "Terkirim tanpa kutipan". Safe default; release gate `TASK-015`/`EXT-001` makes this state transient.
- **Scenario / Question:** PRD GH-015 acceptance-criteria divergence (`consistency-audit-balas-pesan-teruskan-2026-09-27-reaudit.md`, lines 54-57).
  - **Handling:** `[Assumed / Out of Scope]` - Owned by `/sdlc-draft-prd`; not part of this plan revision.

## 4. 📝 Next Steps

- **Update `plan-feature-balas-pesan-auliapos-v1.0.md`:** add the F-A conversation-boundary `400` guard; the F-B label-based not-found discriminator (with the `"Pengirim"` fallback for found rows); and the F-D lookup failure taxonomy, in `TASK-002`, `TASK-006`, `TASK-010`, and `TASK-011` (plus their automated tests).
- **Update `spec/spec-design-balas-pesan.md` (via `/sdlc-define-specs`):** add the intra-conversation source rule to Section 4.3 + Section 12; align the not-found discriminator wording in `REQ-011`/`REQ-013`; note the malformed-`quoted` degradation under `REQ-001`/`CON-001`; bump to v1.5.
- **Update `plan-feature-balas-pesan-wa-gateway-v1.0.md`:** state the F-C degradation explicitly in `TASK-001`/`TASK-002` (malformed `quoted` -> send without quote, `quote_applied:false`, logged).
- **Carry-forward (unchanged, out of scope):** PRD GH-015 AC divergence remains for `/sdlc-draft-prd`.
- **Glossary/ADR:** No new canonical terms and no ADR required (no decision meets the ADR Triple Gate; `CONTEXT.md` unchanged per spec Section 10).

---

> **User Decision Prompt:** The document has achieved a Readiness Score of 89/100. It is ready for the next phase. Do you want to **PROCEED** to the next phase, or do you want to **REFINE** and clarify further?
>
> **User decision recorded:** PROCEED (2026-09-27).
