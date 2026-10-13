# Design: M.A.R.A suggestions to the cashier (Inbox)

- **Date**: 2026-10-13
- **Status**: APPROVED Gate 2 (2026-10-13)
- **Requirements**: `docs/requirements/2026-10-13-mara-saran-ke-kasir.md`
- **SDLC tier**: A (new feature + new schema in `inbox` group)

## 1. Summary

Add a second direction to the existing M.A.R.A contract: M.A.R.A POSTs a suggestion
(`balasan` | `daftar-periksa` | `peringatan`) to a new machine-to-machine endpoint,
AuliaPos stores it in a new `mara_suggestions` table (group `inbox`), and the Inbox UI
fetches active suggestions when a cashier opens a conversation. "Save only" (Option A) —
no realtime push. A suggestion disappears when it expires (`expires_at`) or when the
cashier replies in that conversation.

## 2. Current flow (verified)

Existing M2M contract (already live):

    GET  /api/inbox/mara/export/conversations      app/Config/Routes.php:47
    GET  /api/inbox/mara/resolve/(:num)            app/Config/Routes.php:48
      -> filter 'maratoken'                        app/Config/Filters.php:36
         -> App\Filters\MaraTokenFilter             app/Filters/MaraTokenFilter.php
      -> MaraController::export()/resolve()        app/Controllers/MaraController.php

M2M exclusion from the session filter (both layers already present, no change needed):

    app/Config/Filters.php:139-142   except list 'api/inbox/mara/*'
    app/Filters/AuthFilter.php:33-34  strpos($uri,'api/inbox/mara/') !== false

Outgoing webhook (reverse direction, already live — payload carries `direction`, so M.A.R.A
drops `outgoing`; consequence: M.A.R.A never learns the cashier replied):

    InboxGatewayApi::messages()        app/Controllers/InboxGatewayApi.php (~505, dispatch hook)
    Inbox::kirimKeConversation()       app/Controllers/Inbox.php:3136   (text reply)
    Inbox::kirimMedia()                app/Controllers/Inbox.php:1472   (media reply)
      -> MaraWebhookDispatcher::dispatchAfterInsert()

Cashier-facing read endpoints (session `auth`):

    GET /inbox/api/conversations/(:num)/messages   app/Config/Routes.php:54
      -> Inbox::apiMessages()                      app/Controllers/Inbox.php:426

Realtime channel to the browser (for a possible Option B later, not used now):

    GET /inbox/api/realtime-ticket  -> Inbox::apiRealtimeTicket()   app/Controllers/Inbox.php:628
    gateway WS /realtime            C:\projects\evolution-gateway\src\realtime\server.js
    browser handler                 public/assets/js/inbox-page.js

DB/model pattern to follow (group `inbox`):

    app/Database/Migrations/2026-10-13-000001_CreateMaraResolveLog.php
    app/Models/MaraResolveLogModel.php:12,14   ($DBGroup = 'inbox')

**Verified in Phase 2** (the items previously listed as "not yet verified"):

- Inbox UI insertion point: the per-conversation panel goes inside `.inbox-thread-form`
  (`app/Views/inbox/index.php:97`), directly above the active-quote block (`#kutipanAktif`,
  line 103) and the composer `<form id="formBalas">` (line 120). This area is outside the
  scrolling thread (`#threadMessages`, line 61), exactly like the existing nudge banner
  (`#nudgeAmbilBanner`, line 89) — so a suggestion panel here survives polling rerenders of
  the thread.
- Reply textarea to fill from a `balasan` suggestion: `#teksBalasan`
  (`app/Views/inbox/index.php:132`), the same element the reply-template feature fills. Precedent:
  `public/assets/js/inbox-template.js` (`pilihTemplate()`, uses `teksBalasan` + `tambahMediaBalasan`),
  loaded from the view at line 584.
- Conversation selection hook: `pilihConversation(id)` (`public/assets/js/inbox-page.js:1251`)
  sets `conversationAktif = id` (line 1262) and un-disables the composer (lines 1275-1280). The
  suggestions fetch is triggered from here (and can be refreshed with the message polling).
- Reply send success branches (single success point per channel, next to the existing webhook
  hooks — confirmed):
  - media: `Inbox::kirimMedia()` success path, after gateway ACK, at the webhook hook
    `app/Controllers/Inbox.php:1867` (inside the tail of the send method, after the `messages`
    insert at line 1814 and `updateLastMessageIfNewer()` at line 1859).
  - text: `Inbox::kirimTeksViaGateway()` success path, at the webhook hook
    `app/Controllers/Inbox.php:3441` (after the `messages` insert at line 3397).
  Both hooks fire only on success; the failure/`403`/`400` branches return earlier, which is what
  AC-11 needs (no expire before a confirmed send).
  - Gateway-synced outgoing (review fix 2026-10-13): staff replies sent directly from WA Web/HP
    arrive at `InboxGatewayApi::messages()` as `direction='outgoing'`; that commited path also
    expires suggestions when `$direction === 'outgoing'` (next to the webhook hook ~line 505).
  - Concurrency (review fix 2026-10-13): the "max 1 active per type" invariant is enforced by a
    `SELECT ... FOR UPDATE` lock on the `conversations` row (`MaraSuggestionModel::lockConversation()`)
    taken inside the same transaction as supersede+insert — a transaction alone does not lock when
    no active same-type row exists yet.
- Escaping helper for untrusted suggestion text: `escapeHtmlInbox()` at
  `public/assets/js/inbox-thread.js:350` (loaded before `inbox-page.js`, view line 583).
- JS test harness: `tests/js/*.test.js` run under Node's built-in `node:test` with a `vm` sandbox
  (pattern in `tests/js/inbox-template.test.js`). View-structure guard: `tests/unit/InboxViewStructureTest`
  asserts element ids/handlers and that inline `<script>` blocks stay config-only
  (`InboxViewStructureTest.php:49-52`) — the new panel must follow that rule.

## 3. Options

- **A. Store in CI4, cashier fetches on open/refresh (chosen by M.A.R.A).** No gateway change,
  audit trail, survives refresh. Delay of seconds — within the 15-minute SLA.
- **B. A + gateway `broadcast('mara.suggestion')` over the existing WS.** Instant, but needs a
  new broadcast endpoint in the gateway and a redeploy of `aulia7`. Deferred until needed.
- **C. M.A.R.A posts straight to the gateway/browser.** Rejected — violates "M.A.R.A only via
  the AuliaPos contract", no audit, suggestions lost on refresh.

**Recommendation:** A.

## 4. Planned changes

| File | Change | Reason |
|---|---|---|
| `app/Database/Migrations/2026-10-13-000002_CreateMaraSuggestions.php` (new) | Table `mara_suggestions`, group `inbox`: `id`, `conversation_id`, `type`, `priority`, `text` (TEXT null), `items` (TEXT/JSON null), `reason` (TEXT null), `status` (`active\|superseded\|expired`), `expires_at` (DATETIME null), `created_at`, `updated_at`; index `(conversation_id,status)` + `expires_at`. No FK (logical reference, same as inbox tables). | store suggestions | 
| `app/Models/MaraSuggestionModel.php` (new) | `$DBGroup='inbox'`, `$allowedFields`, helpers: `activeForConversation($id)` (active + not expired, ordered by priority then `created_at`), `supersedeSameType($conversationId,$type)`, `expireForConversation($conversationId)`. | data access | 
| `app/Controllers/MaraController.php` | Add `suggestions()` after `resolve()` (file ends at line 293): validate (see §5), default `expires_at = now+30m` in Asia/Jakarta, supersede same type + insert in one transaction, return `{status:'ok', data:{id}}`. | AC-1..7 |
| `app/Config/Routes.php` | Add `POST /api/inbox/mara/suggestions` → `MaraController::suggestions`, filter `maratoken` — next to lines 47-48. | new M2M route |
| `app/Controllers/Inbox.php` | Add `apiSuggestions($conversationId)`: return `MaraSuggestionModel::activeForConversation()` as JSON (text is escaped client-side). Fail safe to an empty list if the table is not migrated. Place near `apiMessages()` (`Inbox.php:426`). | AC-8,9 |
| `app/Config/Routes.php` | Add `GET /inbox/api/conversations/(:num)/suggestions` → `Inbox::apiSuggestions/$1`, filter `auth` — next to line 54. | cashier read |
| `app/Controllers/Inbox.php` | After a **successful** reply, call `MaraSuggestionModel::expireForConversation($conversationId)`, at the two webhook hook sites: `Inbox.php:1867` (media) and `Inbox.php:3441` (text). Best-effort next to the existing dispatch. | AC-10,11 |
| `app/Views/inbox/index.php` | Add the suggestions panel container inside `.inbox-thread-form` (above `#kutipanAktif`, line 103; the container id must be unique — guard `InboxViewStructureTest`). Panel content is rendered by JS, not PHP. | AC-12..14 |
| `public/assets/js/inbox-page.js` | Render/refresh suggestions in `pilihConversation()` (line 1251) and on message polling; `balasan` → fill `#teksBalasan` (fill pattern as in `inbox-template.js`); `daftar-periksa` → per-item checkboxes; `peringatan` → alert; colour by `priority`. Escape all text with `escapeHtmlInbox()` (`inbox-thread.js:350`). | AC-12..15 |
| `public/assets/css/inbox.css` | Panel + priority colours (normal/blue, tinggi/yellow, darurat/red) + checklist styles. | AC-13,14 |
| `tests/feature/MaraSuggestionsTest.php` (new) | Endpoint tests (see §6). Needs the `inbox` test DB (`aulia_inboxdb_test`, synced via `aulia:sync-inbox-test-db`). | AC-1..11 |
| `tests/js/inbox-suggestions.test.js` (new) | `node:test` + `vm` harness for render/fill/escape (see §6). | AC-12..15 |

No gateway change. No change to the existing export/resolve/webhook code. `.env` needs no new key
(reuses `mara.apiToken`).

## 5. Impact

- **Database / migrations**: new table `mara_suggestions` in group `inbox` only (`aulia_inboxdb`).
  Additive; no existing table/column changed. Must be applied in production via
  `/migrasi-manual` at deploy time.
- **Routes / API / response formats**: two new routes, both additive. No existing route/format
  changed. `POST /api/inbox/mara/suggestions` is new (M.A.R.A will call it — M.A.R.A must be told
  when it is ready).
- **Gateway contract**: none (Option A). Cross-repo note: the M.A.R.A side is a consumer that
  must be informed; its side is **not in this workspace** — the exact request/response shape must
  be confirmed with M.A.R.A before/at integration (see requirements §7 Q3).
- **Existing data**: none touched.
- **Security / validation at the trust boundary**: M.A.R.A is an untrusted external caller.
  - Auth: `maratoken` filter (Bearer, `hash_equals`), same token as export/resolve.
  - Validate: `conversation_id` is a positive int that exists in `conversations`;
    `type` in the allowed set; `priority` in the allowed set; `items` must be a non-empty
    array of strings when `type='daftar-periksa'` (and `null`/ignored otherwise);
    `text` length-capped; `expires_at` parseable and in the future (or defaulted).
  - Reject oversize/malformed bodies; never store partial rows (validate fully before insert).
  - Render: suggestion text is untrusted → escape on render (Inbox pattern `escapeHtmlInbox`).
- **Transactions / concurrency**: supersede + insert for one suggestion should be one DB
  transaction so a conversation never ends with two active suggestions of the same type.
  `expireForConversation()` is a single UPDATE by `(conversation_id, status='active')`.
- **Timezone**: store `expires_at`/`created_at` consistently with the rest of `inbox`
  (DB datetime, WIB convention as used elsewhere); M.A.R.A sends `+07:00`.

## 6. Test plan

| Acceptance criterion | Test (file::method) | Type |
|---|---|---|
| AC-1 | `tests/feature/MaraSuggestionsTest::testRejectsMissingToken` | feature |
| AC-2 | `...::testStoresValidSuggestion` | feature |
| AC-3 | `...::testRejectsChecklistWithoutItems` | feature |
| AC-4 | `...::testRejectsUnknownConversation` | feature |
| AC-5 | `...::testNewSameTypeSupersedesOld` | feature |
| AC-6 | `...::testDefaultExpiryWhenMissing` | feature |
| AC-7 | `...::testRejectsOversizeBody` | feature |
| AC-8 | `...::testActiveOnlyReturnedToCashier` | feature |
| AC-9 | `...::testExpiredNotReturned` | feature |
| AC-10 | `...::testReplyExpiresSuggestions` | feature |
| AC-11 | `...::testFailedReplyKeepsSuggestionsActive` | feature |
| AC-12..15 | `tests/js/inbox-suggestions.test.js` (node:test, vm harness) | unit (JS) |
| AC-15 | JS render test asserts escaping (`escapeHtmlInbox`) | unit (JS) |
| guard | `tests/unit/InboxViewStructureTest` (new ids/handlers) | unit |

Note (SDLC §4): tests that need a DB require the user's approval and a dedicated test DB
(`aulia_inboxdb_test`, synced via `aulia:sync-inbox-test-db`). Follow the existing feature-test
pattern used for other Inbox endpoints.

## 7. Risks and mitigations

- Cashier sees a suggestion after the moment has passed (delay) -> acceptable per M.A.R.A
  (SLA 15 min); `expires_at` + expire-on-reply keep the screen clean.
- M.A.R.A sends an unexpected shape -> strict validation returns 400 with a clear message;
  nothing partial is stored.
- Two suggestions of the same type racing -> supersede+insert in one transaction.
- Suggestion text is untrusted HTML/JS -> escape on render.
- New table not migrated in production -> deploy checklist must include running the migration
  (`/migrasi-manual`); the read endpoint should fail safe (empty list) if the table is missing.

## 8. Verified in Phase 2 / open items

Resolved by the Phase 2 code trace (see §2 for the `file:line` evidence):

- UI insertion point: `.inbox-thread-form` above `#kutipanAktif` (`index.php:97,103`); reply fill
  target `#teksBalasan` (`index.php:132`); refresh trigger `pilihConversation()`
  (`inbox-page.js:1251`). JS render stays out of inline `<script>` to satisfy
  `InboxViewStructureTest.php:49-52`.
- Expire call site: the two single success branches at `Inbox.php:1867` (media) and `Inbox.php:3441`
  (text), both after the gateway ACK and `messages` insert; all failure branches return earlier
  (satisfies AC-11).

Still open (not code — external contract):

- M.A.R.A's exact expected response body for `POST /api/inbox/mara/suggestions`
  (requirements §7 Q3, decided default `{status, data:{id}}`) — confirm with M.A.R.A before
  telling it the endpoint is ready. The M.A.R.A side is **not in this workspace**; it is
  **not yet verified**.

## 9. Approval (Gate 2)

- [x] Approved by: pemilik proyek, date: 2026-10-13 (Gate 2 — balasan "ya, lanjut").
  Confirmed decision: **every successful outgoing message** in the conversation expires the
  suggestions (including "Teruskan"), not only plain replies.
