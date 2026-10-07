# Design: WhatsApp read receipt (both directions)

- **Date**: 2026-10-07
- **Status**: draft
- **Requirements**: `docs/requirements/2026-10-07-whatsapp-read-receipt.md`
- **SDLC tier**: A

## 1. Summary

Add two additive endpoints — Gateway `POST /read` (POS requests mark-as-read on
incoming messages) and CI4 `POST /api/inbox/gateway/message-status` (Gateway
reports outgoing `delivered`/`read`) — plus two nullable `messages` columns
(`delivered_at`, `read_at`). Internal read state (`last_seen_by_assignee_at`)
and `send_status` semantics are untouched.

## 2. Current flow (verified)

Inbound: `Evolution --MESSAGES_UPSERT--> Gateway /evolution/webhook`
(`src/evolution/webhookRoutes.js:65`) -> `normalizeMessagesUpsert`
(`src/evolution/normalize.js:456`) -> SQLite `incoming_queue`
-> `incomingDelivery.deliverOne` (`src/delivery/incomingDelivery.js:31`)
-> `POST /api/inbox/gateway/messages` -> `InboxGatewayApi::messages` (`app/Controllers/InboxGatewayApi.php:61`).

Outbound: `Inbox::kirim` (`app/Controllers/Inbox.php:1462`)
-> `kirimTeksViaGateway` (`:3213`) -> `callGatewaySend` (`:3784`)
-> Gateway `POST /send` (`src/evolution/ci4Routes.js:151`)
-> `client.sendText` (`src/evolution/client.js:94`)
-> Evolution `POST /message/sendText/{instance}`.

Status event: Evolution emits `MESSAGES_UPDATE`; Gateway **ignores** it today
(`webhookRoutes.js:81-86`). Callers affected: none (event was dropped).

## 3. Options

- **A. Separate nullable timestamp columns** (`delivered_at`, `read_at`), keep
  `send_status` untouched.
- **B. Extend `send_status` ENUM** with `delivered`/`read`.

**Recommendation:** A, because it preserves internal-state semantics (AC-10),
avoids touching the send path / `MessageModel` validation, and is idempotent by
construction (`COALESCE`-style first-seen).

## 4. Planned changes

| File | Change | Reason |
|---|---|---|
| `app/Database/Migrations/2026-10-07-000001_AddReadStatusToMessages.php` | add `delivered_at`, `read_at` | store WA receipt |
| `app/Models/MessageModel.php` | `markDelivered()`, `markRead()`, `incomingWaMessageIdsForConversation()` | idempotent status + read-target list |
| `app/Controllers/InboxGatewayApi.php` | `messageStatus()` | receive status from Gateway |
| `app/Config/Routes.php` | `POST /api/inbox/gateway/message-status`, `POST /inbox/percakapan/(:num)/whatsapp-dibaca` | new endpoints |
| `app/Controllers/Inbox.php` | `whatsappDibaca()`, `callGatewayMarkRead()` | request mark-as-read, fail-soft |
| `app/Views/inbox/index.php` | trigger `panggilWhatsappDibaca()` on open + on Tandai Dibaca; CSS read tick | AC-1/AC-2/AC-8 |
| `public/assets/js/inbox-thread.js` | `renderCentangKirim()` delivered/read | AC-8 |
| Gateway `src/evolution/client.js` | `markMessageAsRead()` | call Evolution |
| Gateway `src/evolution/ci4Routes.js` | `POST /read` | POS -> Gateway |
| Gateway `src/evolution/normalize.js` | `normalizeMessagesUpdate()` | parse MESSAGES_UPDATE |
| Gateway `src/evolution/webhookRoutes.js` | `handleMessagesUpdate()` | stop dropping status |
| Gateway `src/delivery/incomingDelivery.js` | `deliverStatus()` | forward status to CI4 |
| Gateway `src/evolution/jid.js` | `isLidJid()` | reject `@lid` early |

## 5. Impact

- **Database**: `inbox` group only; additive nullable columns; no data rewrite.
  Test DB `aulia_inboxdb_test` needs the same columns (schema-dump refresh).
- **Routes/API**: additive, non-breaking. New `POST /read` and
  `POST /api/inbox/gateway/message-status`.
- **Gateway contract**: both sides implemented in this change; verified against
  Evolution source, not against a live instance (see §8).
- **Existing data**: unaffected; older outgoing rows simply have NULL receipt.
- **Security**: trust boundaries validated (id length, status whitelist,
  `Authorization: Bearer` / `apikey`); mark-as-read requires auth.
- **Concurrency**: status update is monotonic (only fill NULL) so out-of-order
  events cannot downgrade.

## 6. Test plan

| Acceptance criterion | Test | Type |
|---|---|---|
| AC-4..AC-7, AC-9 | `tests/feature/InboxGatewayApiMessageStatusTest.php` | feature (DB) |
| AC-8 | `tests/js/inbox-thread.test.js` (AC-30) | js |
| AC-4/5/6 (normalizer) + deliverStatus | Gateway `test/test-read-status.js` | unit |

Run: `vendor/bin/phpunit --no-coverage`, `vendor/bin/phpunit --configuration phpunit.feature.xml`,
`node --test tests/js/*.test.js`, gateway `npm test`.

## 7. Risks and mitigations

- `@lid` chat -> Evolution skips read: rejected early in both Gateway `/read`
  and POS (`skipped:'lid'`).
- Evolution flood of MESSAGES_UPDATE -> filter `fromMe=true` + whitelist status;
  durable buffer dedup by `status:<status>:<id>`.
- CI4 down -> status stays pending in buffer and retries; `matched:false` is 200
  so no infinite retry.
- Read spam on polling -> trigger only on open/button, never on the 4s poll.
- Placeholder `wa_message_id` (`local-`, `evolution:`) -> excluded from the
  read-target list; status `matched:false` for them.

## 8. Not yet verified

- Real Evolution `MESSAGES_UPDATE` payload and true blue-tick E2E (needs a live
  phone + instance).
- Gateway `simulate-evolution-adapter.js` has a **pre-existing** failing
  assertion ("path webhook/set benar", red at HEAD too) unrelated to this change.
- Deployment ordering (POS migration before Gateway) not yet exercised.

## 9. Approval (Gate 2)

- [x] Approved by: pemilik (via "oke, kalau aman, lanjutkan"), date: 2026-10-07
