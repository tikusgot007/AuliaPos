# Design: M.A.R.A suggestions to the cashier (Inbox)

- **Date**: 2026-10-13
- **Status**: draft (awaiting Gate 2)
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

**Not yet verified** (must be read before implementing the UI steps, Phase 3):

- `app/Views/inbox/index.php` and `public/assets/js/inbox-page.js` — where the per-conversation
  panel should render, how the reply textarea is filled (precedent: reply templates in
  `public/assets/js/inbox-template.js`), and how existing panels toggle.
- Whether `Inbox::kirim`/`kirimMedia` have a single success/failure branch point where the
  expire call can be attached (candidate: after the gateway ACK, next to the webhook dispatch hook).

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
| `app/Controllers/MaraController.php` | Add `suggestions()`: validate (see §5), default `expires_at = now+30m`, supersede same type, insert, return `{status:'ok', data:{id}}`. | AC-1..7 |
| `app/Config/Routes.php` | Add `POST /api/inbox/mara/suggestions` → `MaraController::suggestions`, filter `maratoken`. | new M2M route |
| `app/Controllers/Inbox.php` | Add `apiSuggestions($conversationId)`: return `MaraSuggestionModel::activeForConversation()` as JSON (escape handled client-side). | AC-8,9 |
| `app/Config/Routes.php` | Add `GET /inbox/api/conversations/(:num)/suggestions` → `Inbox::apiSuggestions/$1`, filter `auth`. | cashier read |
| `app/Controllers/Inbox.php` | After a **successful** reply (text `kirimKeConversation` :3136, media `kirimMedia` :1472), call `MaraSuggestionModel::expireForConversation()`. | AC-10,11 |
| `app/Views/inbox/index.php` + `public/assets/js/inbox-page.js` (+ `inbox.css`) | Per-conversation suggestions panel: render on open; `balasan` clickable → fills the reply textarea (reuse `inbox-template.js` fill pattern); `daftar-periksa` checkboxes; `peringatan` alert; color by `priority`. | AC-12..14 |
| `tests/feature/MaraSuggestionsTest.php` (new) | Endpoint tests (see §6). | AC-1..15 |

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
| AC-12..14 | `tests/js/inbox-suggestions.test.js` (node:test, vm harness) | unit (JS) |
| AC-15 | JS render test asserts escaping | unit (JS) |
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

## 8. Not yet verified

- UI insertion points in `app/Views/inbox/index.php` / `inbox-page.js` (see §2).
- The exact success branch in `Inbox::kirim`/`kirimMedia` to attach the expire call.
- M.A.R.A's exact expected response body for `POST /api/inbox/mara/suggestions` (requirements
  §7 Q3) — confirm before telling M.A.R.A the endpoint is ready.

## 9. Approval (Gate 2)

- [ ] Approved by: <name>, date: <YYYY-MM-DD>
