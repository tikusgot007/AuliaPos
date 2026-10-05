# Design: Display validated WhatsApp edited text in Inbox

- **Date**: 2026-10-05
- **Status**: Implemented and verified
- **SDLC tier**: A
- **Scope**: AuliaPos only

## 1. Decision summary

Persist a POS-owned timestamp, `edited_text_resolved_at DATETIME NULL`, on `messages`.

The timestamp means that `MessageModel::updateEditedText()` successfully found the
original message row and stored a valid decrypted edit into `messages.text`.

The Inbox list API exposes a separate boolean field, `is_edited_text_resolved`,
derived from `edited_text_resolved_at IS NOT NULL`.

The frontend uses this signal to distinguish:
- edited but unresolved: old F7 behavior (dimmed text + "Pesan diedit — versi ini belum tentu terbaru");
- edited and resolved: normal text rendering + "Pesan diedit — teks terbaru".

No field from the gateway is added. The existing `edited_text` payload remains
unchanged.

## 2. Data model

### Migration

Add one nullable column to `inbox.messages`:

`edited_text_resolved_at DATETIME NULL`

Properties:
- additive-only;
- nullable for all existing rows;
- no backfill;
- compatible with existing `edited_at` and `revoked_at` lifecycle markers.

Existing rows therefore remain unresolved until a future valid edit event is
processed.

### Model semantics

Add `edited_text_resolved_at` to `MessageModel::$allowedFields`.

Change `updateEditedText($waMessageId, $text)` so that one successful update
writes:
- `text = $text`;
- `edited_at = COALESCE(edited_at, now)`;
- `edited_text_resolved_at = now`.

The timestamp is refreshed for every successfully resolved edit so repeated valid
edits stay aligned with the latest stored text. Failed lookup or invalid gateway
payloads do not write this field.

`revoked_at` remains untouched.

## 3. API projection

### Inbox list response

In `Inbox::apiMessages()`, add:

`is_edited_text_resolved: boolean`

Derivation:

`(edited_text_resolved_at !== null)`

Do not expose the timestamp itself to the browser unless an existing API contract
already requires it.

For legacy/fallback edited messages:
- `is_edited = true`;
- `is_edited_text_resolved = false`.

For resolved edited messages:
- `is_edited = true`;
- `is_edited_text_resolved = true`.

Deleted messages continue to use the existing `is_revoked` behavior.

## 4. Frontend behavior

### Label

Update `renderLabelDiedit(m)`:

- resolved: **"Pesan diedit — teks terbaru"**;
- unresolved: existing **"Pesan diedit — versi ini belum tentu terbaru"**;
- not edited: no label.

### Text opacity

Update `renderIsiPesan(m)` so `inbox-teks-basi` is applied when:
- `is_revoked` is true; or
- `is_edited` is true AND `is_edited_text_resolved` is false.

Therefore a resolved edit is rendered at normal opacity.

### Polling / redraw

No new polling mechanism is introduced.

Existing bubble HTML reconciliation already compares rendered HTML. Because the
new signal participates in `renderLabelDiedit()` and `renderIsiPesan()`, a
transition from unresolved to resolved changes the bubble HTML and causes the
existing thread renderer to replace that bubble instead of appending a duplicate.

## 5. Compatibility and impact

- No change to `POST /api/inbox/gateway/message-event`.
- No change to WA-Gateway or Evolution crypto/decryption behavior.
- No change to delete handling.
- Existing F7 consumers keep working because `is_edited` and `is_revoked`
  remain present.
- Existing rows are safe with `NULL`.
- The new API field is additive and does not rename or remove existing fields.

## 6. Files expected to change

1. `app/Database/Migrations/<new migration>`
2. `app/Models/MessageModel.php`
3. `app/Controllers/Inbox.php`
4. `public/assets/js/inbox-thread.js`
5. Relevant existing Inbox/message tests.
6. `docs/CHANGELOG.md` for the user-visible business-rule change.

No other repository is modified.

## 7. Verification plan

### Verification result
- Automated PHP test: passed.
- Automated JS test: passed.
- Target DB migration: passed.
- Manual UI test: passed for resolved and fallback states.
- Deployment is handled by the team; production smoke test was confirmed
  passed by the user on 2026-10-05.

## 8. Edge cases

- `edited_text_resolved_at = NULL` is the unresolved state.
- An edited message with an empty/invalid `edited_text` remains unresolved.
- A successfully resolved edit followed by another successfully resolved edit
  remains resolved and shows only the latest stored text.
- Delete remains visually stale/dimmed regardless of the edit-resolution signal.
- No gateway crypto fields, secrets, ciphertext, or sender-candidate details are
  persisted or exposed by this feature.
