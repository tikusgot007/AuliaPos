<!-- markdownlint-disable -->

# 🔍 Clarification Report [Review Iteration 1]

**Readiness Score:** 89/100
**Status:** Good Enough

**Score Breakdown:**

- **Completeness (max 40):** 37 - All vertical slices (text quote, media quote, incoming quote), failure reactions (a)/(b)/(c), idempotency reuse, and rollback are covered. Points lost only for the three minor unstated defaults below (snippet truncation detail, absent `quote_applied`, and the spec Section 12 vs `ALT-002` wording drift).
- **Clarity (max 30):** 27 - After this session every quote-lifecycle behavior is deterministic (ownership guard, soft-deleted source, media-availability mapping, not-found discriminator, `fromMe`, marker lifetime). Deductions for the minor defaults left to agent judgment.
- **Alignment (max 30):** 25 - Traceability to `REQ`/`AC`/`CONTEXT.md` is strong. Deductions because the resolutions require upstream edits (`spec-design-balas-pesan.md` `REQ-008` column name and Section 4.1 `fromMe`) and because the known PRD GH-015 AC divergence (owned by `/sdlc-draft-prd`) is still live.
- **Critical Flaw Veto:** No - the single critical finding (F1, missing ownership/conversation-boundary validation on `quoted_message_id`) was resolved in-session; no fundamental contradiction remains downstream.

---

## 1. 🚨 Critical Findings (Blockers)

- **Requirement:** TASK-002/TASK-006 - *"Ambil pesan sumber dari DB via `MessageModel::find()` — **bukan** dari client."*
  - **Issue:** No validation that the source message belongs to the **target conversation**. The existing `cekOwnership()` (`app/Controllers/Inbox.php:727`, invoked at `:2157` for `kirimKeConversation()` and `:984` for `kirimMedia()`) authorizes the *destination* conversation only. Without an intra-conversation guard, a cashier could embed arbitrary message content from another conversation, which is both an IDOR data-leak and semantically **Teruskan** — explicitly Out of Scope (`spec/spec-design-balas-pesan.md:31`).
  - **Resolution:** See Section 2 (F1). **Resolved** — no remaining blocker; score is above threshold.

## 2. 🧩 Resolved Items & Agreements

- **Requirement (F1):** TASK-002/TASK-006 server-side source lookup.
  - **Resolution:** Server MUST reject with **`400`/`403`** when `messages.conversation_id !== conversation_id` of the target conversation. Balas Pesan quoting is intra-conversation only; this closes the IDOR and enforces the "Teruskan = Out of Scope" boundary with a single guard clause alongside the existing `cekOwnership()`.
- **Requirement (F2/F9):** lookup behavior when the source row is soft-deleted or the ID is invalid.
  - **Resolution:** The source lookup MUST **include soft-deleted rows** (pattern of `findMessageByOperationId()`, which deliberately omits the `deleted_at` filter — `app/Controllers/Inbox.php:2285`), so a quote still forms per spec Section 12 and `REQ-007`. Reject `400` only when the ID truly does not exist (or is in another conversation per F1). The same "include soft-deleted" rule applies to `MessageModel::findByWaMessageId()` used for incoming quotes (`REQ-011`).
- **Requirement (F3):** `quoted_media_available` (`REQ-008`).
  - **Resolution:** `0` **only** when `media_confirmed_gone_at IS NOT NULL`; `1` when the row is a media message and not confirmed-gone (locally stored or still live-fetchable); `NULL` when the source is not a media message. **Spec drift correction:** `REQ-008` refers to a non-existent `media_status` column; the real seams are `media_local_filename`, `media_download_attempted_at`, and `media_confirmed_gone_at` (`app/Models/MessageModel.php:54-56`; `app/Controllers/Inbox.php:418-499`). Route to `/sdlc-define-specs`.
- **Requirement (F6):** quoting the cashier's own outgoing message.
  - **Resolution:** Add an optional **`fromMe`** field to the `quoted` object (additive; `absent = false`). Outgoing source → `fromMe: true`; incoming source → `fromMe: false`. This lets Gateway reconstruct the Baileys `key` correctly without AuliaPos needing to know the bot number. Requires an additive spec Section 4.1 update and alignment with `plan/plan-feature-balas-pesan-wa-gateway-v1.0.md`.
- **Requirement (F5):** `TASK-011` not-found detection via string comparison of `quoted_snippet`.
  - **Resolution:** Not-found ⇒ `quoted_sender_label = NULL` and `quoted_snippet` = payload snippet as-is (or `"Pesan tidak ditemukan"` when empty); found ⇒ `quoted_sender_label` is **always non-NULL** (mandatory fallback to `sender_jid` / generic "Pelanggan" / "Anda"). The UI renders the generic label based on `quoted_sender_label === NULL`, not by string matching. Deterministic and requires no new column.
- **Requirement (F4):** "Terkirim tanpa kutipan" marker persistence (`AC-003`).
  - **Resolution:** Accept as **ephemeral** — the marker is shown on the response-rendered bubble only and does not survive a thread reload/polling. `AC-003` is satisfied at response-processing time. No new column (honors the plan's explicit "no extra columns" constraint and `GUD-001`).

## 3. ⚠️ Assumed / Auto-Resolved / Out of Scope (The 20% we skip)

- **Scenario / Question (F7):** Exact snippet truncation rule (length, ellipsis, multibyte).
  - **Handling:** `[Assumed / Auto-Resolved]` - Use `mb_substr($text, 0, 200)` and append `…` only when truncated (multibyte-safe, consistent with the spec's "mis. 200 karakter").
- **Scenario / Question (F8):** Gateway returns `sent: true` but omits `quote_applied` (partial rollout).
  - **Handling:** `[Assumed / Auto-Resolved]` - Treat a missing `quote_applied` as `false` (the message verifiably sent without a quote), so the UI shows "Terkirim tanpa kutipan". Safe-default; the release gate `TASK-015`/`EXT-001` should make this state transient anyway.
- **Scenario / Question (F10):** Contradiction between spec Section 12 ("kutipan tetap terbentuk dari data yang sudah dimuat di client saat pemilihan") and plan `ALT-002`/TASK-002 (server-side DB fetch).
  - **Handling:** `[Assumed / Auto-Resolved]` - Server-side fetch from the DB is authoritative (security: never trust the client payload, consistent with `ALT-002` and spec Section 9 "Always do"). The Section 12 wording should be corrected to "snapshot diambil server dari DB saat kirim" via `/sdlc-define-specs`.

## 4. 📝 Next Steps

- **Update `plan/plan-feature-balas-pesan-auliapos-v1.0.md`:** add the F1 conversation-boundary guard, F2 soft-deleted-inclusive lookup, F3 media-availability mapping (fixing the `media_status` reference), F5 `quoted_sender_label`-based not-found discriminator, and the F4 ephemeral-marker note.
- **Update `spec/spec-design-balas-pesan.md`:** correct `REQ-008` to real media columns; add the optional `fromMe` field to Section 4.1/4.1.1 (`REQ-001`/`REQ-001a`); fix Section 12 snapshot wording.
- **Update `plan/plan-feature-balas-pesan-wa-gateway-v1.0.md`:** accept and use `fromMe` when building the Baileys `quoted` object.
- **Carry-forward (not this plan's authoring scope):** the live PRD GH-015 AC divergence (audit `consistency-audit-balas-pesan-teruskan-2026-09-27-reaudit.md`, lines 54-57) remains for `/sdlc-draft-prd`.
- **Glossary/ADR:** No new canonical terms and no ADR required (no decision meets the ADR Triple Gate; `CONTEXT.md` unchanged per spec Section 10).

---
> **User Decision Prompt:** The document has achieved a Readiness Score of 89/100. It is ready for the next phase. Do you want to **PROCEED** to the next phase, or do you want to **REFINE** and clarify further?
>
> **User decision recorded:** PROCEED (2026-09-27).
