# Design: Mark customer-edited and customer-deleted messages in the WhatsApp Inbox

- **Date**: 2026-10-04
- **Status**: draft (revised after spike — now covers edit AND delete)
- **Requirements**: `docs/requirements/2026-10-04-tandai-pesan-diedit-inbox.md`
- **SDLC tier**: A (cross-repo POS <-> WA Gateway contract + Evolution webhook subscription)

## 1. Summary

WhatsApp signals two customer actions we must surface in the Inbox, both carrying
the original message id, so one mechanism covers both:

- **Edit**: gateway `messages.upsert` with `message.secretEncryptedMessage`
  (`secretEncType: 2`), `targetMessageKey.id` = original `wa_message_id`. Content is
  opaque — only a marker is set.
- **Delete**: Evolution webhook `messages.delete`, `data.id` = deleted
  `wa_message_id`, `data.status: "DELETED"`. Requires subscribing the instance to
  `MESSAGES_DELETE` (verified during the spike; currently not subscribed).

Both are turned into a marker on the original POS message; the Inbox renders a badge.
The edit/delete payloads are NOT written as new Inbox messages.

## 2. Current flow (verified)

Edit arriving today:

    WhatsApp edit -> Evolution webhook MESSAGES_UPSERT
      -> Gateway normalizeMessagesUpsert()        (src/evolution/normalize.js:415)
         -> detectMessageType() null              (src/evolution/normalize.js:464)
         -> unsupportedLabel() raw-node fallback  (src/evolution/normalize.js:228-254)
      -> enqueueWithRetry(...)                    (src/evolution/webhookRoutes.js:185)
      -> delivery deliverOne() -> POST /api/inbox/gateway/messages (incomingDelivery.js:79)
      -> InboxGatewayApi::messages() inserts a NEW row (InboxGatewayApi.php:417)

Delete arriving today: Evolution emits `messages.delete` only if the instance
subscribes to it (Evolution `whatsapp.baileys.service.ts:1663-1678`); the gateway has
no handler, so it falls through to the default "belum ditangani" branch
(src/evolution/webhookRoutes.js:80). The original stays visible untouched.

Verified spike evidence (local instance `aulia-test`, 2026-10-04):

- Edit upsert: `message.secretEncryptedMessage = { targetMessageKey:{id}, encPayload,
  encIv, secretEncType:2 }`, opaque (see requirements §2).
- Delete webhook: `{ event:"messages.delete", data:{ id, remoteJid, remoteJidAlt,
  fromMe, status:"DELETED" } }`.
- `messages.delete` was captured only after adding `MESSAGES_DELETE` to the instance
  webhook events (`EVOLUTION_INSTANCE` config).

Relevant existing pieces: `messages.wa_message_id` VARCHAR(255) UNIQUE (production);
`MessageModel` is append-only with no `updated_at` (app/Models/MessageModel.php:86-88);
badge pattern `renderLabelDiteruskan` (public/assets/js/inbox-thread.js:543-549, 935-945);
durable buffer with idempotent `ALTER TABLE` pattern (src/store/incomingBuffer.js:284-347);
`postToCI4` success = HTTP 2xx AND body `status:'success'` (src/delivery/ci4Client.js:14).

## 3. Options

- **A. One unified "message lifecycle marker" (recommended)**: a single new endpoint
  `POST /api/inbox/gateway/message-event` with `{ wa_message_id, event: 'edited' |
  'deleted' }`; two nullable columns `edited_at`/`revoked_at`; both detection sources
  (edit upsert, delete webhook) enqueue a synthetic event through the existing durable
  buffer (`message_type='lifecycle'`, target in `extra_json`) and `deliverOne()`
  routes `lifecycle` events to the new endpoint.
- **B. Two endpoints/columns, separate handlers**: more code, duplicate retry/UI/test
  surface; no benefit.
- **C. Edit only now, delete later**: rejected by the user (wants one piece of work).

**Recommendation: A** — one contract, one durable path, one UI mechanism, one test set.

## 4. Planned changes

| File | Change | Reason |
|---|---|---|
| `C:\Projects\evolution-gateway\src\evolution\normalize.js` | Detect `secretEncryptedMessage` with `secretEncType===2`; return `{ok:true, lifecycle:{event:'edited', targetWaMessageId}}` instead of the `unsupported` event. Other secretEncType values stay dropped. | AC-1/AC-4 |
| `C:\Projects\evolution-gateway\src\evolution\webhookRoutes.js` | (a) Handle the edit result by enqueuing a `lifecycle` event. (b) Add a `messages.delete` branch (`normalizeEventName` -> `messagesdelete`) that enqueues `lifecycle` event `{event:'deleted', targetWaMessageId: data.id}`. | AC-1/AC-2 |
| `C:\Projects\evolution-gateway\src\delivery\incomingDelivery.js` | In `deliverOne()`, route `event.message_type==='lifecycle'` to `POST /api/inbox/gateway/message-event` with `{wa_message_id, event}` (from `extra_json`), else unchanged `/messages`. | Send marker |
| `C:\Projects\evolution-gateway\src\store\incomingBuffer.js` | Synthetic `message_type='lifecycle'` accepted (`assertRequiredFields` already allows any type); JSON fallback carries it via `extra_json`. No schema change. | Durable marker |
| `C:\Projects\evolution-gateway\scripts\set-webhook.js` (+ `setup-instance.*`) | Add `MESSAGES_DELETE` to the subscribed events so delete is delivered. | AC-9 |
| `app/Config/Routes.php` | Add `POST /api/inbox/gateway/message-event` -> `InboxGatewayApi::messageEvent`, filter `gatewaytoken`. | New contract |
| `app/Controllers/InboxGatewayApi.php` | New `messageEvent()`: validate `wa_message_id` (non-empty, <=255) and `event` (`in_list[edited,deleted]`); find via `findByWaMessageId()`; if found set `edited_at`/`revoked_at` only when NULL; always 200 `{status:'success', matched:bool}`. | AC-1/2/5/6 |
| `app/Database/Migrations/2026-10-04-000001_AddEditedRevokedToMessages.php` (new, group `inbox`) | Add nullable `edited_at`, `revoked_at` DATETIME (idempotent column-exists guard like `AddExtraJsonToMessages`). | Marker storage |
| `app/Models/MessageModel.php` | Add `edited_at`, `revoked_at` to `$allowedFields`. | Allow marker writes |
| `app/Controllers/Inbox.php` | In `apiMessages()` add `is_edited`/`is_revoked` bools (mirror `is_forwarded`). | Expose to UI |
| `public/assets/js/inbox-thread.js` | Add `renderLabelDiedit(m)` + `renderLabelDihapus(m)`; insert near `renderLabelDiteruskan` (line 939). | AC-1/2/3 badges |
| `app/Views/inbox/index.php` (or CSS asset) | Add `.inbox-edit-label`, `.inbox-delete-label` styles, and `.inbox-teks-basi` (faded message text -- AC-10). | Badge/text styling |
| `public/assets/js/inbox-thread.js` | Wrap the (stale) message text in `<span class="inbox-teks-basi">` when `is_edited`/`is_revoked` (renderIsiPesan text branch). Badges stay fully visible. | AC-10 |

No change to `/messages` behavior for normal messages (AC-8).

## 5. Impact

- **Database**: one ADDITIVE migration (two nullable DATETIME columns, no index); run
  BEFORE the gateway/POS code that writes them. `MessageModel` gets two allowed fields.
- **Routes/API**: new endpoint (additive). `apiMessages()` gains `is_edited`/`is_revoked`
  (additive). Existing consumers ignore unknown fields.
- **Cross-repo contract**: additive; plus a **webhook subscription change** on the
  Evolution side (`MESSAGES_DELETE`). Adding it changes Evolution -> gateway traffic;
  verifying the delete payload on both local and aulia3. The POS side is verified here;
  the gateway change is in the sibling repo.
- **Existing data**: historical rows get NULL markers (unchanged). Old `unsupported`
  noise rows from past edits are NOT backfilled/removed (out of scope).
- **Security**: `wa_message_id`/`event` from the gateway are untrusted — validated at
  the boundary; the endpoint mutates only one row matched by the UNIQUE column; no
  user input echoed; `gatewaytoken` filter. Deleted content stays in our DB (only a
  marker is set) — deliberate (spike showed Evolution removes its own copy; we keep
  ours so the marker has something to attach to).
- **Concurrency/rollback**: single-row `UPDATE ... WHERE wa_message_id=? AND
  <marker> IS NULL` (idempotent). No conversation/last_message/unread change (an edit
  or delete is not a new message and must not reopen a closed conversation).

## 6. Test plan

| AC | Test (file::method) | Type |
|---|---|---|
| AC-1, AC-5 | `tests/feature/InboxGatewayMessageEventTest.php::testEditMarkerSetOnce` | feature (needs test DB) |
| AC-2, AC-5 | `tests/feature/InboxGatewayMessageEventTest.php::testDeleteMarkerSetOnce` | feature (needs test DB) |
| AC-3 | `tests/js/inbox-thread.test.js` renderLabelDiedit asserts warning wording | js unit |
| AC-4 | gateway `test/simulate-evolution-adapter.js` edit case (no `unsupported` row) | gateway script |
| AC-6 | `tests/feature/InboxGatewayMessageEventTest.php::testUnknownTargetIsSafe` | feature (needs test DB) |
| AC-7 | `tests/feature/InboxGatewayMessageEventTest.php::testMarksMediaWithoutCaption` | feature (needs test DB) |
| AC-8 | existing `tests/feature/InboxGatewayApi*` suite (regression) | feature |
| AC-9 | `C:\Projects\evolution-gateway\scripts\set-webhook.js` run => events include MESSAGES_DELETE (manual/command check) | script |

Feature tests need a dedicated test database (sdlc.md §4) — user approval required.
`renderLabelDiedit`/`renderLabelDihapus` and gateway `normalize` detection are testable
without a DB.

## 7. Risks and mitigations

- Delete detection depends on the Evolution subscription change; if not applied, deletes
  stay invisible (silent). Mitigation: document and verify AC-9; gateway `messages.delete`
  branch is harmless if the event never arrives.
- Race: marker arrives before the original row exists -> endpoint returns `matched:false`
  (dropped) -> missing badge only, no data loss; documented, accepted.
- Ambiguous edit badge -> AC-3 wording test.
- `message_type='lifecycle'` leaking to `/messages` -> guard in `deliverOne()` AND a
  defensive reject in `messages()` for the reserved type.
- Regression on normal messages -> AC-8 suite.

## 8. Not yet verified

- Whether `MESSAGES_DELETE` can be added on **aulia3** without an Evolution restart
  (locally it applied live via `POST /webhook/set`); needs confirmation before the
  production rollout.
- Whether `data.id` on delete for a **group** chat or a **media** message behaves the
  same as the tested 1:1 text case (assumed by analogy).
- Visual placement of the badges in the real Inbox (pending).
- Feature tests not yet run (no approved test DB).

## 9. Approval (Gate 2)

- [ ] Approved by: <name>, date: <YYYY-MM-DD>
