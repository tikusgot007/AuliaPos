# Inbox Media Was Never Expired — Failure Classification and Local Storage (2026-09-28)

## 1. Status and Scope

Plan: `plan-bugfix-inbox-media-unavailable-v1.0.md` (**Completed**).
Two repositories:

| Repo | Change | Commit |
| --- | --- | --- |
| AuliaPos (`v2.3`) | Inbox media UI: honest failure cause + bounded recovery | `1cb44ff` |
| WA-Gateway (`master`) | `/media/download` failure classification + download deadline | `1591512` |

Goal: stop a transient Gateway failure from permanently recording intact media as gone, report the
real cause of a failed media load, bound the retries, and remove the dependency on a live fetch by
keeping a local copy of incoming media.

## 2. Corrected Root Cause

The cashier's report ("Gambar tidak tersedia (kemungkinan sudah kadaluarsa)") was **wrong about
expiry**. The media was intact; the live Gateway process was simply not running when the photos were
opened, so Apache logged `502`, not `410`.

Underneath that, three defects sat on the same path:

1. **Permanent latch.** `mediaGagal` (`app/Views/inbox/index.php`) was a `Set` filled from the
   `<img onerror>` handler and never cleared, so the photo was never retried for the rest of the page
   session. The existing "reload on reconnect" hook was defeated by it.
2. **Misleading message.** Every failure rendered "kemungkinan sudah kadaluarsa". An `<img onerror>`
   cannot distinguish "Gateway unreachable" from "media expired", so a 30-second outage was reported
   as permanent expiry.
3. **Latent permanent loss (most severe).** `src/api/ci4Routes.js` mapped **every** error from
   `downloadMediaByRef()` to `410 MEDIA_UNAVAILABLE`. AuliaPos treats `410` as final: `Inbox::media()`
   writes `media_confirmed_gone_at` and every later request short-circuits without contacting the
   Gateway again. One reconnect, timeout or network blip would have blacklisted an intact photo
   forever. It had not fired only because the Gateway was fully down, so curl returned `502` before
   ever reaching that `catch`.

The class-level cause is that no media copy was kept: `inbox.mediaStoragePath` was unset, so every
photo view was a fresh 30-second fetch from WhatsApp through the Gateway.

## 3. What Was Measured, What Was Assumed (TASK-010)

The plan ordered an empirical measurement instead of guessing the expiry signal. All probes were
read-only calls to the live Gateway using references stored in `aulia_inboxdb.messages`.

| Probe | Simulates | Response |
| --- | --- | --- |
| control, reference as stored | — | `200`, full bytes |
| `oe` forced into the past | expired signed URL | `403` |
| `oh` changed by one character | corrupted signature | `403` |
| object id mutated | object absent | `403` |
| malformed `direct_path` | — | `404` |
| wrong `media_key_base64` | — | decryption error `error:1C800064:...bad decrypt` |

**Conclusion:** expired signature, corrupted signature and a missing object are **indistinguishable**
from each other (all `403`). No observed response reliably means "this media is gone", so mapping any
of them to `410` would delete intact photos. Per `ASSUMPTION-001` the safe direction was taken: only
an explicit `410` from the media host stays permanent; everything else becomes retryable.

The error object shape was verified **at runtime**, not read from source: an axios `AxiosError` with
`.code = 'ERR_BAD_REQUEST'` and `.response.status` populated, and no Boom `.output`. This is what the
new classifier keys on.

### 3.1 Withdrawn finding (recorded so the mistake is not repeated)

An earlier probe run reported that **all six** stored references failed with
`Request failed with status code 404`, including the control, and inferred that media dies after a
Gateway reconnect. **That was wrong and is withdrawn.** The probe script itself was broken: it read
the `media_metadata` JSON out of MySQL and round-tripped it through PowerShell 5.1
`ConvertFrom-Json` → `ConvertTo-Json`, which does not decode MySQL's `\/` escapes and then
re-escapes the backslash. The Gateway received a literal `"direct_path":"\\/o1\\/v\\/..."`, i.e. a
malformed path, and the media host answered `404` for every probe. After rebuilding the payload with
`JSON_OBJECT`/`JSON_UNQUOTE` in MySQL — no PowerShell in the middle — all six references returned
`200` again.

Lesson: never round-trip a MySQL JSON column through PowerShell's JSON cmdlets.

## 4. Change Summary

**AuliaPos — `app/Views/inbox/index.php`**

- Failure handling moved into `tanganiMediaGagal()`: swap the broken image for a placeholder
  immediately, then probe the response status **once** with `fetch()` and record the cause per
  message id. The probe runs only on the error path, so the success path and its ETag caching are
  untouched (`CON-004`).
- Honest text per cause: `410` keeps the existing "kemungkinan sudah kadaluarsa" wording; `502`/`503`/
  `504`/network failure render "Gateway belum bisa dihubungi — akan dicoba lagi"; anything else stays
  generic with no expiry claim.
- Retry only transient failures: at most 3 attempts, minimum 30-second gap (`REQ-004`). `410` keeps
  the permanent latch (`CON-002`).
- Transient entries are cleared when the Gateway transitions to `connected`, so the photo returns
  without a reload (`REQ-003`).
- `mediaGagal` keys are now always `String(id)`, matching the value read back from the DOM.

**WA-Gateway — `master`**

- `src/whatsapp/boundedDownload.js` (new): read a stream under a hard deadline, destroy it on expiry,
  reject with `code = 'MEDIA_DOWNLOAD_TIMEOUT'`. It is a standalone module because Baileys' ESM
  namespace is frozen and cannot be stubbed, so the timeout could not otherwise be tested.
- `connectionManager.downloadMediaByRef()` runs through that bound using
  `config.mediaDownloadTimeoutMs`; underlying errors pass through unwrapped so the caller can still
  read `err.response.status`.
- `src/config/index.js`: new `mediaDownloadTimeoutMs` (default `6000`, `MEDIA_DOWNLOAD_TIMEOUT_MS`).
  Deliberately a separate key rather than reusing `mediaFetchTimeoutMs` (default `15000`, used by the
  dashboard `mediaUrl` feature): `CON-008` requires the Gateway to answer before AuliaPos' 8-second
  prefetch budget expires, so the shared default could not simply be lowered. Measured downloads are
  far below this (718001 bytes in under a second).
- `src/api/ci4Routes.js`: the `catch` classifies instead of assuming expiry — `504` for
  `MEDIA_DOWNLOAD_TIMEOUT`, `410` only for an explicit host `410`, `503` otherwise. The success
  contract (`200`, raw decrypted binary, request mimetype) is unchanged (`CON-001`).
- **TASK-012 was dropped, not implemented.** A "not connected → fail fast" guard would produce false
  `503`s: Baileys downloads media with `axios.get()` straight to `mmg.whatsapp.net`
  (`node_modules/baileys/lib/Utils/messages-media.js:298`), which does not depend on the WebSocket
  being up and can succeed while it is reconnecting.

**Local storage (TASK-021/022)**

- `.env`: `inbox.mediaStoragePath = 'D:/aulia_inbox_media/'`. Forward slashes on purpose — a
  trailing backslash would risk escaping the closing quote during `.env` parsing.
- The folder is on `D:`, a fixed local disk (42.9 GB free), not on the system drive and not on a
  removable or network drive; Apache runs as `LocalSystem`, so it can write there.
- This activates the Tahap C prefetch in `InboxGatewayApi::messages()` (8-second budget), which had
  never run before because the key was unset.

## 5. Deploy Record

Live folder `C:\Projects\WA-Gateway`, started manually (no supervisor).

| Item | Before | After |
| --- | --- | --- |
| Branch | `master` | `master` |
| HEAD | `66bff03` | `1591512` |
| Merge step | — | `git merge --ff-only fix/media-download-classification` → `Updating 66bff03..1591512`, `Fast-forward` |
| Diff | — | 5 files, `+598 / −24` |
| Process | PID `45192` (stopped) | PID `21048` |
| Status | `connected` | `connected`, number `6281913500707` |

Start-up evidence (live `logs/gateway.log`, UTC, PID `21048`):
`06:01:13.790` `HTTP API + Dashboard berjalan di http://127.0.0.1:3000`, and
`06:01:15.277` `Status koneksi berubah menjadi: connected` (`number: 6281913500707`).

`package.json` / `package-lock.json` unchanged, so no `npm ci` was required, and `auth/` was not
touched. Pushed the same day: `66bff03..1591512` (`master -> master`), so the fix exists on the
remote and not only on this machine.

**Post-deploy proof that the new classification is live** (tools/build probe against the live
Gateway, `06:02:18` UTC):

| Input | Response | Before this deploy |
| --- | --- | --- |
| reference as stored | `200` + binary | `200` |
| object id mutated | `503` `{"error_code":"MEDIA_DOWNLOAD_FAILED"}` | `410 MEDIA_UNAVAILABLE` |

The log line for the failure now reads `[MEDIA] gagal mengambil/mendekripsi media (sementara, bisa
dicoba lagi)` with `statusDariHost: 403` — the assertion of expiry is gone.

## 6. Rollback

- **Gateway (RBCK-001):** `git -C C:\Projects\WA-Gateway reset --hard 66bff03`, then restart the
  process. No migration.
- **Gateway, narrower (RBCK-002):** restore only the `catch` in `src/api/ci4Routes.js` to its
  all-cases-`410` form. This reintroduces the latent permanent-loss risk and must not be left in
  place longer than necessary.
- **AuliaPos (RBCK-003):** `git revert 1cb44ff` on `v2.3`. No schema change, so no data migration in
  either direction.
- **Storage (RBCK-004):** unset `inbox.mediaStoragePath`. Behaviour returns to live-fetch; files
  already on disk become unreferenced and can be deleted by hand. No `messages` column needs
  clearing.
- **Wrongly marked row (RBCK-005):** clear that row's `media_confirmed_gone_at`. **0 rows needed
  this** — verified after the work: 6 media rows, 0 marked permanently gone.

## 7. Evidence Limits and Open Items

- **The `410` branch is currently unreachable in practice.** No genuine expiry was ever observed, and
  the deliberate `410` test set the flag by hand in the database, so what it proved is the UI's
  permanent-latch behaviour, not the Gateway's classification of a real expiry. A genuinely expired
  media therefore retries within the bound and then stops, instead of being recorded as gone. This is
  the accepted, safe direction; the `410` path stays in place so the contract is ready if the media
  host ever states it explicitly.
- **Browser-side bounded-retry observation is deferred.** It could not be exercised honestly before
  the deploy: with the old Gateway, a media failure while the Gateway was healthy returned `410` and
  would have marked the photo permanently. The rule itself is covered automatically by
  `tests/js/media-inbox-retry.check.js` (13 cases). Also note that when the Gateway is *known* down,
  the UI deliberately issues no request at all, so the retry budget only applies to the
  "Gateway up, media fails" case.
- **No retention policy (RISK-004).** `D:\aulia_inbox_media\` grows without pruning and is not
  cleaned when a conversation or message is deleted. At 42.9 GB free this is tens of thousands of
  photos; a retention decision is out of scope for this plan and must be raised separately once the
  folder grows.
- **The six pre-existing media rows keep no local copy** and still use live-fetch. They cannot be
  backfilled by this plan.
- **`GW-25`/C3 is untouched.** The Gateway still drops and re-establishes its connection every 1–3
  minutes and still logs a `No matching sessions found` decryption storm for the account's own LID.
  Media fetches now survive it gracefully (they fail retryably instead of permanently), but the
  underlying defect remains open.
- **The live Gateway has no supervisor** and is started by hand. This deploy started it as a
  persistent background process; if the machine reboots it needs starting again (RISK-005).
- **The one-shot axios probe script was not committed.** It depended on `axios` transitively rather
  than as a declared dependency. The measured error shape is recorded in section 3 instead.
