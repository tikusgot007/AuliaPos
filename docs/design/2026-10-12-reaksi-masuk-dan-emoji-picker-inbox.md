# Design: Composer emoji picker + incoming reactions as quoted bubbles (Inbox)

- **Date**: 2026-10-12
- **Status**: approved (implemented 2026-10-12, not yet deployed)
- **Requirements**: `docs/requirements/2026-10-12-reaksi-masuk-dan-emoji-picker-inbox.md`
- **SDLC tier**: A (gateway change, cross-repo); emoji picker part is UI-only

## 1. Summary

(A) A local, dependency-free emoji panel in the Inbox composer that inserts at the caret.
(B) The gateway stops dropping incoming 1:1 `reactionMessage` and turns it into a normal
incoming `text` row whose `text` is the emoji and whose `quoted.wa_message_id` is the reacted
message. CI4 stores and renders it through the existing quote path; **no CI4 PHP change, no
schema change, no contract shape change** (same fields as a plain text reply).

## 2. Current flow (verified)

Incoming (gateway repo `C:\Projects\evolution-gateway`, HEAD `abd74e9`):

    POST /evolution/webhook                       (src/evolution/webhookRoutes.js:47)
      -> handleMessagesUpsert()                   (webhookRoutes.js:95)
      -> normalizeMessagesUpsert()                (src/evolution/normalize.js:464)
         -> noiseReason() -> 'reactionMessage'    (normalize.js:213-217, :240-254)  => skipped
      (non-noise) -> quotedStore.save()           (webhookRoutes.js:245)
                  -> enqueueWithRetry(incomingBuffer)  (webhookRoutes.js:248)
      -> deliverOne() -> CI4 POST /api/inbox/gateway/messages
         -> InboxGatewayApi::messages()           (app/Controllers/InboxGatewayApi.php:64)
            -> resolveKutipanMasuk($payload['quoted'])  (InboxGatewayApi.php:399, :713)
            -> insert `messages` (quoted_* columns, MessageModel.php:69-79)
            -> conversation last_message_* / unread  (same path as any incoming text)

Render: `apiMessages()` -> `public/assets/js/inbox-thread.js` quote box + bubble, text escaped
with `escapeHtmlInbox`.

Composer (CI4 repo):

    app/Views/inbox/index.php:120-132       buttons btnLampirkanMedia, btnTemplateBalasan, textarea teksBalasan
    public/assets/js/inbox-page.js:2555     'input' handler -> buangOperationIdBalasan(), sembunyikanStatusKirimBalasan(), autoResizeTeksBalasan()
    inbox-page.js:118-122,1273-1277,1403-1406,1427-1430   enable/disable of composer buttons
    public/assets/js/inbox-template.js      precedent: separate file + node test (tests/js/inbox-template.test.js)

Callers/consumers of changed surface: only `normalizeMessagesUpsert()` callers
(`webhookRoutes.js:96`) and its tests; composer additions are consumed only by the Inbox view.

## 3. Options

- **A. Gateway maps reaction to a normal `text` row with `quoted` (chosen at Gate 1).** No CI4
  backend change; unread/last-message behave like a new message automatically (AC-RX-3).
- **B. New `message_type='reaction'` handled in CI4.** Needs CI4 validation + render branch +
  contract change; contradicts "no reaction form" decision. Rejected.
- **C. Reaction as lifecycle event on the target row.** Needs new columns; does not count as a
  new message. Rejected (conflicts with AC-RX-3).

Emoji picker: **curated static list in a new `inbox-emoji.js`** (Gate 1 decision 3a) vs a
vendored library — library rejected (dependency + asset size, YAGNI).

**Recommendation:** A + curated list.

## 4. Planned changes

### Gateway (`C:\Projects\evolution-gateway`)

| File | Change | Reason |
|---|---|---|
| `src/evolution/normalize.js` | New `extractIncomingReaction(record, messageObj)` returning `{emoji, targetId}` or a skip reason. Called in `normalizeMessagesUpsert()` **before** `noiseReason()`. Skip (`ok:false, skip:true`) when `fromMe`, group JID, empty/whitespace `text` (reaction removed), or missing `reactionMessage.key.id`. Otherwise build the normal event with `messageType:'text'`, `text:emoji`, `quoted:{wa_message_id:targetId, sender_jid:null, snippet:null}`, `messageId = key.id` (the reaction's own id), plus internal flag `_isReaction:true`. `reactionMessage` stays in `NOISE_NODES` so every skipped case keeps today's behavior. Export the helper. | AC-RX-1, -4, -5, -6, -9 |
| `src/evolution/webhookRoutes.js` | Delete `_isReaction` from the event; **skip `quotedStore.save()`** for reactions so a later reply to the reaction bubble degrades to the existing `quote_applied:false` path instead of quoting a raw `reactionMessage` in Evolution. | avoid sending a reaction stanza as a quote |
| `test/simulate-evolution-adapter.js` | Replace the "reaction dilewati" block (`:726-732`) with: 1:1 reaction -> one pending row, `message_type:'text'`, `text` = emoji, `quoted.wa_message_id` = target; same webhook twice -> still one row; `fromMe`, group, empty text -> skipped, no row. | AC-RX-1/4/5/6/9 |
| `test/test-contract-ci4.js` | Add a reaction-derived event to the delivery-body assertion (shape identical to a quoted text). | AC-RX-1 contract |

### CI4 (`C:\xampp\htdocs\aulia`)

| File | Change | Reason |
|---|---|---|
| `public/assets/js/inbox-emoji.js` (new) | `DAFTAR_EMOJI_INBOX` (curated ~80 common emoji, few groups), `toggleEmojiPanel()`, `tutupEmojiPanel()`, `sisipkanEmoji(emoji)` using `textarea.setRangeText(emoji, start, end, 'end')`, then focus + `buangOperationIdBalasan()`, `sembunyikanStatusKirimBalasan()`, `autoResizeTeksBalasan()`. Close on outside click and Esc. Rendered once from the static list (no network). | AC-EP-1..5 |
| `app/Views/inbox/index.php` | Add `btnEmojiBalasan` (`inbox-composer-ikon`, `fa-smile`, disabled by default) before the textarea, an `#panelEmoji` container, and `<script src="assets/js/inbox-emoji.js">`. | AC-EP-1 |
| `public/assets/js/inbox-page.js` | Add `btnEmojiBalasan` to the four existing enable/disable blocks; close panel on conversation switch. | consistent state |
| `public/assets/css/inbox.css` | Panel styles (absolute above composer, grid, scroll, existing tokens). | layout |
| `tests/js/inbox-emoji.test.js` (new) | node:test, vm harness like `inbox-template.test.js`. | AC-EP-2/3/5 |
| `tests/unit/InboxViewStructureTest.php` | Include `inbox-emoji.js` in handler sources; add `btnEmojiBalasan`, `panelEmoji` to required ids. | guard |
| `docs/CHANGELOG.md` | Log behavior change (reactions now visible, count as new message). | AGENTS.md §15 |

No migration. No change to any route, `InboxGatewayApi`, `MessageModel`, or other endpoints.

## 5. Impact

- **Database / migrations**: none (both groups). Reaction rows use existing `messages` columns.
- **Routes / API / response formats**: none changed.
- **Gateway contract**: payload to `/api/inbox/gateway/messages` keeps the existing shape
  (`message_type:'text'`, `text`, `quoted{wa_message_id,sender_jid,snippet}`); CI4 side already
  accepts it (verified `InboxGatewayApi.php:105-110,399,713-760`). Non-breaking, additive in
  behavior only. Requires **adapter deploy to aulia7** (copy `normalize.js`, `webhookRoutes.js`,
  restart `AuliaPosGatewayAdapter`), separate from the CI4 deploy to AULIA-SERVER2.
- **Existing data**: none touched. Old reactions were never stored and are not recovered.
- **Security / validation**: emoji `text` comes from WhatsApp (untrusted): gateway trims and
  caps length (reaction text is short; reuse 4096 limit semantics, cut to 32 chars); CI4 escapes
  on render (existing). `quoted.snippet` sent as `null`, so CI4 shows local data or
  "Pesan tidak ditemukan". Emoji panel inserts only from a static list (no HTML injection).
- **Concurrency / idempotency**: reaction rows use the reaction's own `key.id`; duplicate webhooks
  are dropped by the buffer (`INSERT OR IGNORE`) and CI4 `existsByWaMessageId`.

## 6. Test plan

| Acceptance criterion | Test | Type |
|---|---|---|
| AC-RX-1 | `test/simulate-evolution-adapter.js` reaction section: row shape | gateway integration |
| AC-RX-1 | `test/test-contract-ci4.js` reaction delivery body | contract |
| AC-RX-2 | `tests/feature/` existing quoted-incoming test path covers storage; add `InboxGatewayMessagesQuotedTest`-style case posting the reaction payload (emoji text + quoted id of an existing row) and asserting `quoted_wa_message_id` + snippet resolved | feature (inbox test DB) |
| AC-RX-3 | same feature test asserts conversation `last_message_*` updated / unread incremented | feature |
| AC-RX-4/5/9 | adapter test: fromMe, empty text, group -> skipped, no row | gateway integration |
| AC-RX-6 | adapter test: same webhook twice -> one row | gateway integration |
| AC-RX-7 | full `npm test` (gateway) + existing feature/unit suites (CI4) unchanged | regression |
| AC-RX-8 | existing XSS escape test in `tests/js/inbox-thread.test.js` (emoji text path) | unit (JS) |
| AC-EP-1/3 | `tests/js/inbox-emoji.test.js` toggle/close | unit (JS) |
| AC-EP-2 | `tests/js/inbox-emoji.test.js` insert at caret, not replace | unit (JS) |
| AC-EP-4 | `inbox-emoji.test.js` asserts no `fetch` call; static list | unit (JS) |
| AC-EP-5 | `inbox-emoji.test.js` asserts `buangOperationIdBalasan` called | unit (JS) |
| guard | `InboxViewStructureTest` ids + handlers | unit |
| manual | browser: picker on PC kasir; real reaction from a phone after aulia7 deploy | manual E2E |

## 7. Risks and mitigations

- Real `reactionMessage` shape differs from Baileys (`key` not the target) -> **capture one real
  payload in dev first** (adapter debug log of the skipped event); adjust extractor before deploy.
- Customer changes reaction (👍 -> ❤️): if WhatsApp reuses the same outer `key.id`, the second is
  deduplicated and not shown -> accepted limitation (`ponytail:` comment in code); verify in E2E.
- Reaction bubble indistinguishable from a typed emoji reply -> accepted (Gate 1: no reaction form).
- Unread noise from frequent reactions -> accepted by user (counts as new message).

## 8. Not yet verified

- **Verified 2026-10-12** (read-only query on dev Evolution DB, 11 real rows, all group chats):
  `message.reactionMessage.key.id` = target message id, `reactionMessage.text` = emoji, outer
  `key.id` unique per reaction (same customer re-reacting to one target produced distinct ids,
  so a changed reaction shows as a new row).
- Still unverified: a real **1:1** reaction row and a real **removal** (empty `text`) row — no
  samples in dev DB; covered by synthetic tests in the verified shape. Confirm in E2E after
  the aulia7 adapter deploy.
- Implementation note: `inbox-emoji.js` loads **before** `inbox-page.js` (the page script calls
  `tutupEmojiPanel()`), guarded by `InboxViewStructureTest::testEmojiScriptLoadsBeforePageScript`.
  The design table listed six (not four) composer enable/disable points; all six updated.

## 9. Approval (Gate 2)

- [x] Approved by: project owner (via chat), date: 2026-10-12
