<!-- markdownlint-disable -->

> [!NOTE]
> **REMEDIATION STATUS: RESOLVED**
> This audit report has been remediated by Specification Architect (`/sdlc-define-specs`).
> - **Target spec:** `spec/spec-design-balas-pesan.md` bumped to **v1.4** (2026-09-27).
> - **Resolutions applied:** all nine (F1-1..F1-4, F2-1..F2-4, F3-1) on `REQ-008`, `REQ-011`, Section 4.1, Section 4.1.1, Section 4.2, Section 4.3, Section 12, `AC-005` (+ Section 6/13 test hooks).
> - **Projected Readiness Score:** 96/100 (Completeness 40/40, Clarity 29/30, Alignment 27/30).
> - **Carry-forward (unchanged, out of authoring scope):** PRD GH-015 AC divergence; `plan/plan-feature-balas-pesan-auliapos-v1.0.md` `TASK-002` still names plain `MessageModel::find()` and must be updated after spec v1.4 approval.

# 🔍 Clarification Report [Review Iteration 1]

**Target:** `spec/spec-design-balas-pesan.md` v1.3 (amended: three-valued `quoted_media_available`, optional `quoted.fromMe`, Section 12 snapshot wording)
**Readiness Score:** 88/100
**Status:** Good Enough

**Score Breakdown:**

- **Completeness (max 40):** 36 - All three v1.3 corrections are now deterministic, and the media-availability lifecycle (frozen snapshot vs later expiry, local file vs confirmed-gone, overloaded `NULL`, heuristic `1`), `fromMe` derivation, and soft-delete-inclusive lookup are all covered. Points deducted only because the resolutions are decisions not yet written back into the document.
- **Clarity (max 30):** 27 - No remaining two-way interpretations within the three correction areas. Small deduction for the pending upstream spec rewrite.
- **Alignment (max 30):** 25 - Traceability to `REQ`/`AC` and the code is strong, and every `file:line` cited by v1.3 was verified accurate. Deductions because the resolutions require upstream `REQ-008`/`REQ-011`/`Section 4.1/4.1.1/4.2/4.3/12`/`AC-005` edits, and because the PRD GH-015 AC divergence is still live (out of this session's scope).
- **Critical Flaw Veto:** No - the one material contradiction (`AC-005` vs `REQ-007`, F1-1) was resolved in-session; no contradiction remains downstream.

---

## 1. 🚨 Critical Findings (Blockers)

None. The single material issue (F1-1) was resolved in-session; no blocking ambiguity remains.

---

## 2. 🧩 Resolved Items & Agreements

### Focus 1 - Three-valued `quoted_media_available` (REQ-008 / REQ-011 / Section 4.2)

- **Requirement:** `quoted_media_available` "merekam ketersediaan media pesan sumber **saat balasan dibuat** (snapshot, REQ-007)" with `0` only when `media_confirmed_gone_at` is set.
  - **Resolution (F1-1):** Keep the snapshot frozen (per `REQ-007`), but add a **display-time fallback**: when the source row's media request returns 404/410/error, the quote box renders **"[Media tidak tersedia]"** without writing back to the DB. This satisfies `AC-005`'s user-visible intent for media that expires *after* the reply was created, without refreshing the stored snapshot.
- **Requirement:** `0` - "**hanya** bila `media_confirmed_gone_at` pesan sumber terisi".
  - **Resolution (F1-2):** Align the definition exactly with the real code seam (`Inbox.php:440`): `0` **only** when `media_local_filename` is empty **AND** `media_confirmed_gone_at` is set. When `media_local_filename` is present, the value is `1` (local file wins, mirroring the endpoint's check order at `Inbox.php:418-438`). This closes a reachable contradiction (HDD unplugged -> live-fetch 410 writes `media_confirmed_gone_at` while `media_local_filename` remains set).
- **Requirement:** "`NULL` - bila pesan sumber **bukan** pesan media (teks)."
  - **Resolution (F1-3):** Broaden the `NULL` definition to "source is not a media message **OR** availability is unknown / not resolved (e.g. `REQ-011` not-found)". No value, schema, or UI branch changes.
- **Requirement:** "`1` - bila pesan sumber bertipe media dan **belum** confirmed-gone (file lokal masih ada via `media_local_filename`, atau masih layak di-live-fetch...)".
  - **Resolution (F1-4):** State that `1` is a **heuristic** ("media-typed and not confirmed gone - potentially available"), not a guarantee. Outgoing media rows have no `media_local_filename` and may have a `NULL` `media_metadata` reference (`Inbox.php:1061-1068`), in which case the endpoint can return 500. Load failures are handled by the F1-1 display-time fallback.

### Focus 2 - Optional additive `quoted.fromMe` (REQ-001 / REQ-001a / Section 4.1 / 4.1.1 / 4.3)

- **Requirement:** "`true` bila pesan sumber adalah pesan **keluaran** AuliaPos (**baris `messages` dengan `sender_jid = NULL`**)".
  - **Resolution (F2-1):** Remove the `sender_jid = NULL` equivalence. `fromMe` is derived **only** from `direction` (`outgoing` -> `true`, `incoming` -> `false`). Add an explicit prohibition: `sender_jid` MUST NOT be used to derive `fromMe`, because incoming rows can also have `sender_jid = NULL` (pre-Tahap-2 group messages, and private incoming payloads without `sender_jid` - `InboxGatewayApi.php:293`, validation only for group-incoming at `:85-91`).
- **Requirement:** `quoted.sender_jid` is listed as a `quoted` payload field.
  - **Resolution (F2-2):** State explicitly that `quoted.sender_jid` may be `null`/absent for outgoing sources (their `messages.sender_jid` is `NULL` by design - `Inbox.php:2227`, `:1076`).
- **Requirement:** "`quoted.fromMe` (boolean, opsional, additive, `absent = false`)".
  - **Resolution (F2-3):** An explicit JSON `"fromMe": null` is treated identically to absent -> `false`.
- **Requirement:** `REQ-002` Baileys key `{id, remoteJid, fromMe, participant}`.
  - **Resolution (F2-4):** For an outgoing source (`fromMe: true`), `quoted.sender_jid` is not sent; the Gateway fills `key.participant` from its own bot-account JID. This closes the "all data Baileys needs" claim without adding a new contract field.

### Focus 3 - Section 12 server-side snapshot

- **Requirement:** "snapshot kutipan diambil **server** dari DB (`messages`, termasuk baris yang sudah soft-deleted) saat permintaan kirim diproses".
  - **Resolution (F3-1):** Add an explicit mechanism requirement: the source lookup (both directions, including the incoming lookup of `REQ-011`) **MUST** include soft-deleted rows via `withDeleted()` or a `db_connect('inbox')->table('messages')` query without the `deleted_at` filter (existing pattern: `findMessageByOperationId()`, `Inbox.php:2285-2303`); plain `find()`/`first()` on `MessageModel` (which is `useSoftDeletes = true`, `MessageModel.php:38`; contrast `ConversationModel.php:363-369`) **MUST NOT** be used, or the snapshot silently fails to form after a concurrent soft-delete.

---

## 3. ⚠️ Assumed / Auto-Resolved / Out of Scope (The 20% we skip)

- **Scenario / Question:** PRD GH-015 acceptance-criteria divergence (PRD lines 362/364) - whether the cashier is offered "send without quote" versus the spec's automatic "Terkirim tanpa kutipan" marker.
  - **Handling:** `[Assumed / Out of Scope]` - Explicitly out of this session's scope; still owned by `/sdlc-draft-prd`.
- **Scenario / Question:** Whether `quoted_media_available` should be re-evaluated at render time (alternative B rejected for F1-1).
  - **Handling:** `[Assumed / Auto-Resolved]` - Rejected; re-evaluation would contradict `REQ-007`'s "never refreshed" snapshot contract. The display-time fallback (chosen A) preserves the contract.
- **Scenario / Question:** Introducing a fourth value (e.g. `2` = unknown) for `quoted_media_available` (alternative B rejected for F1-3).
  - **Handling:** `[Assumed / Auto-Resolved]` - Rejected as unnecessary complexity (`GUD-001`); the broadened `NULL` semantics are sufficient.
- **Scenario / Question:** Changing the media endpoint so confirmed-gone outranks a local file (alternative B rejected for F1-2).
  - **Handling:** `[Assumed / Auto-Resolved]` - Rejected; it would modify already-released media-endpoint behavior, outside this spec's scope.

---

## 4. 📝 Next Steps

- **`/sdlc-define-specs` (authoring agent):** apply the nine resolutions above to `REQ-008`, `REQ-011`, `Section 4.1`, `Section 4.1.1`, `Section 4.2`, `Section 4.3`, `Section 12`, and `AC-005`; add the soft-delete-inclusive lookup requirement and the display-time media fallback. Bump the spec version to v1.4.
- **`plan/plan-feature-balas-pesan-auliapos-v1.0.md`:** after spec v1.4 is approved, align `TASK-002`/`TASK-006`/`TASK-010` (soft-delete-inclusive lookup, two-condition `0`, `1` heuristic, display-time media fallback). The current `TASK-002` reference to `MessageModel::find()` contradicts Section 12 and must be corrected.
- **`plan/plan-feature-balas-pesan-wa-gateway-v1.0.md`:** use `fromMe` and fill `key.participant` from the bot JID for outgoing sources.
- **Glossary/ADR:** No new canonical terms; no ADR required (no decision meets the ADR Triple Gate; `CONTEXT.md` unchanged).
- **Carry-forward (not this session's authoring scope):** the live PRD GH-015 AC divergence remains for `/sdlc-draft-prd`.

---

> **User Decision Prompt:** The document has achieved a Readiness Score of 88/100. It is ready for the next phase. Do you want to **PROCEED** to the next phase, or do you want to **REFINE** and clarify further?
>
> **User decision recorded:** PROCEED (2026-09-27). Report saved to `docs/audit/clarification-report-balas-pesan-spec-v1.3-2026-09-27.md`.
