# View-once Placeholder Fix and Live Deploy (2026-09-28)

## 1. Status and Scope

E-02 was confirmed and narrowed to view-once messages. This record covers the corrected root cause, the
fix, its verification (unit/simulation, regression, real disposable instance, and the live Gateway), and
the deployment to the live folder.

| Item | Verified state |
| --- | --- |
| WA-Gateway live folder | Deployed: `master` moved `a2ba409` → `66bff03` (fast-forward), process online, `connected` as `6281913500707` |
| Live Gateway process | `node src/app/index.js` from `C:\Projects\WA-Gateway`, PID `24636`, port `3000` |
| `auth/` | Not touched by the deploy (see §5) |
| AuliaPos application code | Unchanged |
| Plan | `plan/plan-bugfix-wa-gateway-viewonce-unsupported-v1.0.md` (TASK-001..014 done) |

## 2. Corrected Root Cause

The original plan assumed a view-once message arrives as a `viewOnceMessageV2` wrapper and is dropped by
the final `else` of the type dispatch (`connectionManager.js`). The real E2E disproved that:

- A view-once message sent to a **linked device** carries **no content**. WhatsApp sends only an
  `<unavailable type="view_once">` stanza.
- Baileys sets `msg.key.isViewOnce = true` (`node_modules/baileys/lib/Utils/decode-wa-message.js:127`)
  and leaves `msg.message` **undefined** with `messageStubType = CIPHERTEXT`
  (`decode-wa-message.js:192-195`); the stanza still flows through `upsertMessage`
  (`lib/Socket/chats.js:764-765`).
- The Gateway dropped it at the **first line** of `_handleIncomingMessage`
  (`if (!msg.message) return;`) — before the dispatch — so there was no DB row and no log, at any level.
- The `viewOnceMessageV2` wrapper only appears when the primary phone answers the placeholder-resend
  request (`RESOLVED`, `lib/Socket/messages-recv.js:627-630`), which did not happen in the test.

## 3. Change Summary

`src/whatsapp/connectionManager.js`:

- Detect the real path before the guard: `!msg.message && msg.key?.isViewOnce === true`.
  - `fromMe === false` → one `text` event with the fixed placeholder, `media = null` (REQ-001/REQ-002).
  - `fromMe === true` → still dropped (CON-002).
  - Other empty/protocol messages → dropped as before.
- Keep the `viewOnceMessageV2` branch (covers the placeholder-resend path).
- Raise the final dropped-type log from `debug` to `warn` (REQ-003).

`test/simulate-viewonce.js` (new): cases 1/2 (wrapper path, CON-002), 1b/2b/2c (real `key.isViewOnce`
path and the non-view-once empty message), 3 (unsupported type stays dropped but logs `warn`), 4 (empty
wrapper wrapper does not throw and logs `warn`).

Placeholder string (fixed):

```text
[Pesan lihat-sekali dari pelanggan — isinya tidak dapat ditampilkan di Inbox]
```

## 4. Verification Evidence

### 4.1 Automated

- `node test/simulate-viewonce.js` — all 7 cases pass (before the fix, cases 1 and 3 failed as expected).
- Full Gateway batch with a temporary `SQLITE_PATH` (TEST-010 hygiene): **27/27 passed** (23
  `simulate-*.js` + 4 `check-*.js`), zero regressions.
- `node --check src/whatsapp/connectionManager.js` — OK.

### 4.2 Real disposable instance

Instance run from the worktree (`PORT=3010`, separate SQLite under a temporary folder), connected as
`6281913500707`.

| Test | Gateway result | AuliaPos result |
| --- | --- | --- |
| Plain text `TES-KONTROL-VIEWONCE` | stored as `text` | row `900037` |
| Plain photo | stored as `image` | row `900038` (rendered as media-unavailable — a separate media-download issue) |
| View-once photo (before fix) | **no row, no log** | none |
| View-once photo (after fix) | row id 5, `text` = placeholder, `media_json = null` | row `900041` (`3EB0F537BC07D69307D502`) |

### 4.3 Live Gateway (post-deploy)

| Item | Value |
| --- | --- |
| `incoming_queue` row | id `8`, `message_type = text`, `status = completed`, `media_json = null`, placeholder text |
| AuliaPos `messages` row | id `900043`, placeholder text, `wa_message_id = 3EB0DF986CA743FED08371` |
| Media stored | none |

## 5. Deploy Record

| Item | Before | After |
| --- | --- | --- |
| Working folder | `C:\Projects\WA-Gateway` | unchanged |
| Branch | `master` | `master` |
| HEAD | `a2ba409` | `66bff03` |
| Merge step | — | `git merge --ff-only fix/viewonce-placeholder` → `Updating a2ba409..66bff03`, `Fast-forward` |
| Diff | — | 2 files, `+249 / −7` |
| Process | (Gateway was not running) | `node src/app/index.js`, PID `24636`, `HTTP API + Dashboard berjalan di http://127.0.0.1:3000` |

Start-up evidence (live `logs/gateway.log`, UTC): `[DELIVERY] worker pengiriman pesan masuk dimulai`
(`ci4BaseUrl: http://localhost/aulia`), `[HEARTBEAT] worker heartbeat status dimulai`, and
`Status koneksi berubah menjadi: connected` (`number: 6281913500707`).

`package.json` / `package-lock.json` unchanged, so no `npm ci` was required. `git diff --name-only
a2ba409..66bff03 | grep '^auth/'` returned nothing.

## 6. Rollback

- Code: `git -C C:\Projects\WA-Gateway reset --hard a2ba409`, then restart the Gateway process.
- No database migration and no AuliaPos change are involved, so nothing else needs undoing.
- Narrower mitigation: keep only the `warn` log (REQ-003) and drop the placeholder branch, restoring the
  previous behaviour while keeping the loss observable.

## 7. Evidence Limits and Open Items

- **CON-006 note (owner-approved):** the disposable E2E instance ran with `AUTH_FOLDER` pointing at the
  live `auth/` **in place** (the live process was stopped), so there was a single session state and no
  divergence. `auth/` was not replaced or deleted.
- **Decryption storm observed (separate issue, C3/GW-25):** during the E2E the Gateway logged repeated
  `failed to decrypt message` / `No matching sessions found` for the account's own LID
  (`255490491736112@lid`, `fromMe:true`), with retry receipts. This did not affect inbound customer
  messages (text and photo both arrived) and is **not** addressed by this change.
- **Media after arrival (corrected 2026-09-28):** a plain photo arrives as `image` and the Inbox
  showed "Gambar tidak tersedia (kemungkinan sudah kadaluarsa)". This was **not** a media
  fetch/decrypt problem and **not** an expiry: the live Gateway was down (`502`, not `410`), and three
  defects sat underneath — a permanent client-side failure latch, a message that always blamed
  expiry, and a Gateway `catch` that mapped every error to `410`. Diagnosed and fixed as its own bug
  under `plan-bugfix-inbox-media-unavailable-v1.0.md`; see
  `docs/decisions/2026-09-28-inbox-media-not-expired-and-failure-classification.md`. Incoming media is
  now also stored locally, so opening a photo no longer depends on the Gateway.
- **E-07 (upsert without content)** remains unproven.
- The live Gateway is currently started manually (no Windows service / PM2 installed). If the owner's
  normal flow is the `supervisor/` Control Panel, stop this process first to avoid two instances.
