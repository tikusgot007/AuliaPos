# Design: Edit/Delete outgoing messages + "Pesan dihapus" in Inbox

- **Date**: 2026-10-07
- **Status**: approved
- **Requirements**: `docs/requirements/2026-10-07-edit-hapus-pesan-keluar-inbox.md`
- **SDLC tier**: A

## 1. Summary

Add browser→CI4 endpoints (`Inbox::hapusPesan`/`editPesan`, `auth` filter) that proxy to the
gateway `POST /delete`/`POST /edit` (Bearer `gatewayToken`), plus per-bubble Edit/Delete
buttons and a "Pesan ini telah dihapus" placeholder. Reuse the existing `revoked_at`/
`edited_at` lifecycle columns and handlers; no schema change.

## 2. Current flow (verified)

Incoming send (existing pattern to mirror):

    POST /inbox/kirim                       (app/Config/Routes.php:48, filter auth)
      -> Inbox::kirim()                     (app/Controllers/Inbox.php:1462)
      -> Inbox::kirimKeConversation()       (app/Controllers/Inbox.php:3095)
      -> Inbox::kirimTeksViaGateway()       (app/Controllers/Inbox.php:3278)
      -> Inbox::callGatewaySend()           (app/Controllers/Inbox.php:3849)  -- Bearer gatewayToken
         -> adapter POST /send
      -> MessageModel insert outgoing row    (after success)

Lifecycle markers (already implemented, unchanged):

    POST /api/inbox/gateway/message-event    (app/Config/Routes.php:36, filter gatewaytoken)
      -> InboxGatewayApi::messageEvent()     (app/Controllers/InboxGatewayApi.php:797)
      -> MessageModel::markLifecycle()       (app/Models/MessageModel.php:202)   -- idempotent
       | MessageModel::updateEditedText()    (app/Models/MessageModel.php:176)   -- idempotent

UI bubble render:

    GET /inbox/api/conversations/(:num)/messages  (Routes.php:43, auth)
      -> Inbox::apiMessages()                 (app/Controllers/Inbox.php:408)  -- sets is_edited/is_revoked
    JS: public/assets/js/inbox-thread.js
      - aksiPesanTersedia()/renderAksiPesan()  (:531/:511)
      - renderBubbleHtml()/renderIsiPesan()    (:983/:770)
      - renderLabelDiedit()/renderLabelDihapus():561/:574
    View: app/Views/inbox/index.php (buatOperationId() :3133; kirimBalasan() :3241)

Callers/consumers of the changed surface: only Inbox UI + its JS; no other consumer.

## 3. Options

- **A. Proxy via Inbox controller (auth), reuse existing columns.** Add `callGatewayDelete/Edit`
  + `Inbox::hapusPesan/editPesan` + routes under `/inbox/pesan/...`; UI buttons in
  `inbox-thread.js`; reuse `revoked_at`/`edited_at`. No migration.
- **B. Literal task reading: endpoints in `InboxGatewayApi` with `gatewaytoken` filter.** Wrong
  trust direction (browser does not hold `gatewayToken`); would require exposing the shared
  secret to the browser or a second token — rejected.
- **C. New `is_deleted`/`is_edited` columns.** Duplicates `revoked_at`/`edited_at`; two sources of
  truth for the same state — rejected.

**Recommendation:** A — mirrors the proven `/send` path, keeps the shared secret server-side,
and reuses the lifecycle state that TODO-F7/F8 already persist.

## 4. Planned changes

| File | Change | Reason |
|---|---|---|
| `app/Controllers/Inbox.php` | Add `hapusPesan()`/`editPesan()`; add `callGatewayDelete()`/`callGatewayEdit()` mirroring `callGatewaySend()` (`:3849`) | CI4 proxy to gateway delete/edit, auth-gated |
| `app/Config/Routes.php` | Add `POST /inbox/pesan/(:num)/hapus` and `/edit` (`auth`) | browser→CI4 entry points |
| `public/assets/js/inbox-thread.js` | Extend `aksiPesanTersedia()`/`renderAksiPesan()` with Edit/Delete; add `renderLabelDeletedPlaceholder()`/"Pesan ini telah dihapus"; hide Balas/Teruskan when revoked | per-bubble actions |
| `app/Views/inbox/index.php` | Add edit modal + delete confirm; add `hapusPesanKirim()`/`editPesanKirim()` using `buatOperationId()`; no auto-retry on 504; keep same operation_id on manual retry | UI + idempotency |
| `app/Views/inbox/index.php` (CSS) | Style disabled Edit tooltip + deleted placeholder (reuse `inbox-teks-basi`, `inbox-delete-label`) | visual |
| `docs/CHANGELOG.md` | Log the feature | business/feature doc |

No migration. No change to `messageEvent`, `markLifecycle`, `updateEditedText`, `/send`,
`/send-media`, `/media/download`, `/read`, `/api/inbox/gateway/*`.

## 5. Impact

- **Database / migrations**: none. Reuse `edited_at`/`revoked_at`/`edited_text_resolved_at`.
  `updated_at`? `messages` has no soft-delete timestamp touched here; `markLifecycle` uses query
  builder to avoid soft-delete/timestamp side effects.
- **Routes / API / response formats**: new routes only; no change to existing response shapes.
  New CI4 JSON: `{status:'success', state:'deleted'|'edited', replayed:bool}` passthrough, or
  `{status:'error', error_code, message}` on failure. Adapter `error_code` forwarded as-is.
- **Gateway contract (cross-repo)**: adapter `/delete` and `/edit` verified 2026-10-07 (E2E,
  commit `8d77caf`). Request/response shapes documented. **Other side verified.**
- **Existing data**: outgoing rows already carry `wa_message_id`; no backfill needed.
- **Security / validation at trust boundaries**: validate `conversation_id`, `message_id`
  (local `messages.id`), `new_text` length (1..4096), `operation_id`; confirm the target row
  belongs to the conversation and is `direction='outgoing'`; escape all rendered text
  (`escapeHtmlInbox`); never log message text (SEC). Verify message belongs to the conversation
  to avoid cross-conversation IDOR.
- **Transactions / concurrency / rollback**: the local `revoked_at` marker is set by the
  incoming webhook (idempotent). Optimistic UI update after a successful gateway call; the 4s
  poll reconciles. No local state write is required for the happy path; on 504 the state stays
  unchanged (correct — outcome unknown).

### Behavior decisions

- **Edit window**: server computes `now - created_at <= 15 min` (Asia/Jakarta) for the button;
  the controller also rejects out-of-window edits (`EDIT_WINDOW_EXPIRED`) to avoid trusting the
  client. Adapter/Evolution remains the final authority.
- **Edit text-only**: server rejects edit when `message_type !== 'text'`; UI hides the button.
- **Delete any outgoing type** not yet revoked.
- **Idempotency**: edit requires `operation_id` (generated with `buatOperationId()`); delete
  sends one too. No auto-retry on network/504; manual "Coba lagi" reuses the same `operation_id`.

## 6. Test plan

| Acceptance criterion | Test (file::method) | Type |
|---|---|---|
| AC-1/AC-2/AC-3/AC-4 | `public/assets/js` assertion (existing JS test harness) | unit (JS) |
| AC-5/AC-6 (proxy success + passthrough) | `tests/feature/InboxPesanKeluarEditHapusTest.php::testEditProxiesToGateway`, `::testDeleteProxiesToGateway` | feature |
| AC-5 validation (media/out-of-window) | `::testEditRejectsNonText`, `::testEditRejectsOutOfWindow` | feature |
| AC-7 render placeholder | JS harness + `InboxMessagesPaginationTest` (is_revoked) | unit/feature |
| AC-8 no auto-retry on 504 | `::testUnresolvedNotRetried` | feature |
| AC-9 replay same operation_id | `::testRetryReusesOperationId` | feature |
| AC-10 XSS escape | JS harness | unit (JS) |
| AC-11 lifecycle idempotent | `InboxGatewayMessageEventTest` (existing) | feature |

Feature tests mock the gateway HTTP via the existing trait (`tests/_support/GatewayApiTestTrait.php`);
they must NOT hit production/dev DBs (SDLC §4) — use the dedicated inbox test DB
(`aulia_inboxdb_test`, see `docs/ARCHITECTURE.md` §9; TODO-Q3b).

## 7. Risks and mitigations

- Evolution 15-min window differs from server clock → server check is advisory; adapter/Evolution
  is authoritative; on `EDIT_WINDOW_EXPIRED` UI shows a clear message.
- Double state sources (`revoked_at` vs optimistic UI) → webhook + poll reconcile; optimistic
  update only on confirmed success.
- Cross-conversation tampering (`message_id` from another chat) → validate row belongs to
  `conversation_id` and is outgoing.
- Secret leakage → `gatewayToken` stays server-side; browser never sees it.

## 8. Not yet verified

- Real Evolution behavior editing messages > 15 min and exact `EDIT_WINDOW_EXPIRED` error string.
- `MESSAGES_DELETE` webhook shape for **outgoing** messages deleted from POS (assumed identical
  to incoming; handler shared and idempotent).
- Which `message_type` values are possible for outgoing rows (media variants).

## 9. Approval (Gate 2)

- [x] Approved by: project owner (via chat), date: 2026-10-07 — Option A approved; auth-only; reuse existing columns.
