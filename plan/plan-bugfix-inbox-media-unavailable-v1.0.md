---
goal: Make incoming media reliably visible in the Inbox and stop transient Gateway failures from permanently marking media as expired
version: 1.0
date_created: 2026-09-28
last_updated: 2026-09-28
owner: AuliaPos + WA-Gateway
status: "Planned"
tags: ["bug-fix", "remediation", "patch"]
---

# Introduction

![Status: Planned](https://img.shields.io/badge/status-Planned-yellow)

A cashier cannot see customer photos in the Inbox. Ordinary photos arrive correctly as
`message_type = image` with a complete media reference, but the Inbox renders
"Gambar tidak tersedia (kemungkinan sudah kadaluarsa)" instead of the picture.

The media is **not** expired. Re-requesting the stored reference returns the file normally.

Diagnosis on 2026-09-28 produced this evidence:

| Fact | Evidence |
| --- | --- |
| Media is intact | Replaying the stored reference of message `900038` to the live Gateway returned `200 image/jpeg`, 718001 bytes; `900040` returned `200 image/jpeg`, 115411 bytes. |
| The Inbox got `502`, not `410` | Apache access log: `GET /aulia/inbox/media/900038` → `502 158`, `900039` → `502 158`, `900040` → `502 158`. A `410` would mean expiry; `502` means the Gateway could not be reached. |
| The Gateway was absent at that moment | The live Gateway process (`127.0.0.1:3000`, PID `36180`) started at **10:32:41**. All three `502` responses are at `10:18:26`, `10:27:43`, `10:28:55` — before it existed. The first `200` (`900042`) is at `10:33:06`, after it started. Messages still arrived because a disposable E2E Gateway instance was delivering to AuliaPos while the live Gateway was stopped for the view-once deploy. |
| One successful fetch proves the path works | AuliaPos `GET /inbox/media/900042` → `200 114567`, and the Gateway logged `[MEDIA] berhasil mengambil & mendekripsi media on-demand`. |
| No local copy exists | All 6 media rows have `media_local_filename = NULL` **and** `media_download_attempted_at = NULL`. `inbox.mediaStoragePath` is absent from `.env`, so the Tahap C prefetch in `InboxGatewayApi.php:347-371` never runs. |
| Nothing has been marked permanently gone | `media_confirmed_gone_at` is `NULL` for all 6 rows, so no media has been lost yet. |

There are three distinct defects, in increasing severity.

1. **The placeholder is permanent for the page session.** `mediaGagal` (`app/Views/inbox/index.php:864`)
   is a `Set` that is populated from the `<img onerror` handler and **never cleared**. The Inbox
   re-renders the whole thread every 4 seconds, but any message in `mediaGagal` is skipped, so the
   photo is never retried. The cashier must reload the page to recover. A recovery hook already exists
   but is defeated by this latch: `index.php:2975-2980` reloads messages when the Gateway transitions
   to `connected`, yet the latch still skips the image.
2. **The failure message is wrong.** Every failure is rendered as "kemungkinan sudah kadaluarsa"
   (`index.php:2137`, `2155`, `2170`). An `<img>` error cannot distinguish "Gateway unreachable" from
   "media expired", so a 30-second outage is reported as permanent expiry. This misled the
   investigation and will mislead the cashier.
3. **A transient Gateway error permanently destroys recoverability (latent, most severe).**
   `src/api/ci4Routes.js:373-389` maps **every** error from `downloadMediaByRef` — reconnect, timeout,
   network blip — to `410 MEDIA_UNAVAILABLE`, and its log asserts expiry. AuliaPos treats `410` as
   final: `Inbox.php:525-529` writes `media_confirmed_gone_at`, after which `Inbox.php:468-478`
   short-circuits all future requests to `410` without ever contacting the Gateway again. One
   momentary hiccup would therefore blacklist an intact photo forever. This has not fired yet, only
   because the Gateway was entirely down so curl returned `502` instead of reaching the Gateway's
   `catch`.

The root cause of the *class* of failure is that no media copy is kept: `inbox.mediaStoragePath` is
unset, so every photo view is a fresh 30-second fetch from WhatsApp's media servers through the
Gateway. The fix below stops the permanent-loss path first, then makes failures bounded and honestly
reported, then removes the dependency on a live fetch.

## 1. Requirements & Constraints (Fix Constraints)

- **REQ-001**: A transient Gateway failure MUST NOT cause media to be permanently recorded as gone. The
  `410` → `media_confirmed_gone_at` path MUST only be reached for a genuinely expired media.
- **REQ-002**: The Inbox MUST report the real cause of a failed media load, distinguishing at minimum
  "media expired" from "Gateway could not be reached".
- **REQ-003**: When a media load fails transiently and the Gateway later recovers, the photo MUST become
  visible without the cashier reloading the page or switching conversation.
- **REQ-004**: Recovering media MUST be bounded. Retries MUST be capped in count and spaced in time so a
  permanently broken media cannot cause a request every poll cycle.
- **REQ-005**: `POST /media/download` MUST NOT be able to hold a request open indefinitely. It MUST
  return a distinct status for a timeout, separate from expiry.
- **REQ-006**: Incoming media MUST be stored locally when it arrives, so opening a photo does not depend
  on the Gateway or on WhatsApp still hosting the file.
- **CON-001**: The success contract of `POST /media/download` MUST NOT change: a successful response
  remains the raw decrypted binary with its media `Content-Type`. Only failure classification changes.
- **CON-002**: The `410` short-circuit in `Inbox.php:468-478` MUST be preserved. Genuinely expired media
  must still stop being retried.
- **CON-003**: `GET /inbox/media/(:num)` MUST remain read-open for any logged-in staff member — no
  `cekOwnership()` re-introduction (REQ-002 of `spec-design-inbox-read-authorization.md`).
- **CON-004**: Media per `message_id` MUST stay immutable, so the ETag-based browser caching
  (`Inbox.php:438-439`, `488-492`) remains valid and unchanged.
- **CON-005**: No `messages` schema change. The fix reuses `media_local_filename`,
  `media_download_attempted_at`, and `media_confirmed_gone_at`.
- **CON-006**: WA-Gateway changes MUST be made in a separate worktree, never in the live folder
  `C:\projects\WA-Gateway`. `auth/` MUST NOT be touched.
- **CON-007**: No new dependencies in either repository.
- **CON-008**: The Gateway media timeout MUST be shorter than the AuliaPos client timeout for the same
  call, so the Gateway produces a classified error instead of the client aborting first.
- **CON-009**: No existing passing test may be weakened, skipped, or deleted to make this fix land.

## 2. Implementation Steps

> **⚠️ EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> You MUST execute this plan phase by phase. You MUST run the specific testing/verification task at the
> end of each phase. After a phase is tested, you **MUST STOP AND WAIT** for the user's explicit
> approval before proceeding to the next phase.

### Implementation Phase 1: Test Writing (Test-Driven Bug Fixing)

- **GOAL-001:** Capture the misclassified error and the unbounded download as failing tests.

| Task | Description | Ref ID | Completed | Date |
| --- | --- | --- | :---: | :--: |
| TASK-001 | In the Gateway worktree, create `test/simulate-media-download-errors.js` following the existing `test/simulate-send-media.js` harness pattern (build the Express app from `src/api/server.js`, stub `connectionManager.downloadMediaByRef`). | REQ-001 | [ ] | |
| TASK-002 | Case: `downloadMediaByRef` rejects with a **transient** error (e.g. `Error('socket not open')`). Assert `POST /media/download` responds `503`. This MUST FAIL today, because the current catch returns `410` for every error. | REQ-001 | [ ] | |
| TASK-003 | Case: `downloadMediaByRef` rejects with the **expiry** signal. Assert `410`. This PASSES today and exists to guard CON-006 while TASK-011 rewrites the catch. | CON-006 | [ ] | |
| TASK-004 | Case: `downloadMediaByRef` rejects with the timeout signal. Assert `504` and assert a distinct `error_code`. This MUST FAIL today. | REQ-005 | [ ] | |
| TASK-005 | Case: `downloadMediaByRef` resolves a buffer. Assert `200`, the body bytes, and the `Content-Type` from the request `mimetype`. Guards CON-001. | CON-001 | [ ] | |
| TASK-006 | In AuliaPos, add a session test following `tests/session/InboxMediaAuthTest.php` that drives `Inbox::media()` with a stubbed Gateway returning `503`, and asserts `media_confirmed_gone_at` is **not** written and the response status is `503`. Extend `tests/unit/InboxMediaConfirmedGoneTest.php` with the same `503`/`504` scenarios. | REQ-001, CON-002 | [ ] | |
| TASK-007 | **VERIFY**: Run the new Gateway script. Record raw output. It MUST FAIL on TASK-002 and TASK-004, proving the tests detect the real defect. Run the new AuliaPos test and record its result. | - | [ ] | |
| TASK-008 | **APPROVAL**: 🛑 Wait for explicit user confirmation to proceed to Phase 2. | - | [ ] | |

### Implementation Phase 2: Gateway — Bound the Download and Classify Errors

- **GOAL-002:** Make `/media/download` return a truthful status: expired, timed out, or temporarily
  unreachable.

| Task | Description | Ref ID | Completed | Date |
| --- | --- | --- | :---: | :--: |
| TASK-009 | In `src/whatsapp/connectionManager.js`, give `downloadMediaByRef()` a bounded timeout using the existing `config.mediaFetchTimeoutMs` (currently only used by `mediaPayload.js:146`). On expiry, destroy the underlying stream and reject with a typed error carrying a stable code such as `MEDIA_DOWNLOAD_TIMEOUT`. | REQ-005, CON-008 | [ ] | |
| TASK-010 | **EMPIRICAL — do not guess.** Determine the real error signal for a genuinely expired media: pick an old `messages` row whose media is beyond WhatsApp's retention and call the live Gateway `/media/download` with its stored reference; record the raw thrown error (message, `code`, `output.statusCode`, or HTTP status from the media host). Save the raw evidence in the Phase 5 decision log. | REQ-001 | [ ] | |
| TASK-011 | In `src/api/ci4Routes.js` `/media/download`, rewrite the `catch` (lines 373-389) to classify: the signal measured in TASK-010 → `410 MEDIA_UNAVAILABLE`; the timeout code from TASK-009 → `504`; every other error → `503`. Keep the `{ success, error_code, message }` shape and change the log text so it no longer asserts expiry for non-expiry causes. Do not touch the success path. | REQ-001, REQ-005, CON-001, CON-006 | [ ] | |
| TASK-012 | `[OPTIONAL]` Add a fast-fail guard in `/media/download`: if there is no socket or the status is `disconnected`/`logged_out`, return `503` immediately instead of attempting the download. Verify against real behaviour before keeping — if `downloadContentFromMessage` can succeed while reconnecting, this guard would produce false `503`s and MUST be dropped. | REQ-005 | [ ] | |
| TASK-013 | **VERIFY**: Run `node test/simulate-media-download-errors.js` — all cases MUST PASS. Run the full Gateway batch (all `test/simulate-*.js` and `test/check-*.js`, in batches to stay below the command timeout) and confirm zero regressions. Record raw output. | - | [ ] | |
| TASK-014 | **APPROVAL**: 🛑 Wait for explicit user confirmation to proceed to Phase 3. | - | [ ] | |

### Implementation Phase 3: AuliaPos Inbox — Truthful Messages and Bounded Recovery

- **GOAL-003:** Show the real cause of a media failure, and retry transient failures without hammering.

| Task | Description | Ref ID | Completed | Date |
| --- | --- | --- | :---: | :--: |
| TASK-015 | In `app/Views/inbox/index.php`, in the media `onerror` path for `image`/`sticker` (lines 2132-2171), probe the response status once via `fetch(url)` and record the reason in a module-level `Map` keyed by message id: `410` → expired (permanent), `503`/`504`/network failure → temporary, anything else → unknown. The probe runs only after an error, so the happy path and its browser caching stay untouched. | REQ-002, CON-004 | [ ] | |
| TASK-016 | Replace the single placeholder string with cause-specific text: expired keeps the current wording; temporary renders a "Gateway belum bisa dihubungi — akan dicoba lagi" message; unknown stays generic. Preserve the existing caption rendering and the `inbox-media-*` CSS classes. | REQ-002 | [ ] | |
| TASK-017 | Add bounded recovery: only entries recorded as temporary are retried, at most 3 attempts per message, with a minimum gap of 30 seconds between attempts. Permanent (`410`) entries keep the current never-retry latch (CON-002). | REQ-003, REQ-004 | [ ] | |
| TASK-018 | Clear temporary entries when the Gateway transitions to `connected`, extending the existing hook at `index.php:2975-2980`, so recovery is immediate in the outage case that caused this bug. | REQ-003 | [ ] | |
| TASK-019 | **VERIFY**: Run the manual browser checklist in TEST-003 (Gateway up, photo visible; Gateway stopped, temporary message appears; Gateway restarted, photo appears with no page reload; an expired media still shows the permanent message and is not retried). Run `vendor/bin/phpunit --no-coverage` and confirm zero failures. | - | [ ] | |
| TASK-020 | **APPROVAL**: 🛑 Wait for explicit user confirmation to proceed to Phase 4. | - | [ ] | |

### Implementation Phase 4: Remove the Root Cause — Store Incoming Media Locally

- **GOAL-004:** Keep a local copy so opening a photo does not need the Gateway at all.

| Task | Description | Ref ID | Completed | Date |
| --- | --- | --- | :---: | :--: |
| TASK-021 | **OWNER DECISION (not a code change)**: choose the storage folder for `inbox.mediaStoragePath`. It should be outside the application directory, and the disk must be available whenever the cashier works. Record the choice and the consequences in the Phase 5 decision log. | REQ-006 | [ ] | |
| TASK-022 | Set `inbox.mediaStoragePath` in the live `.env` and confirm the path is writable by the web server user. | REQ-006 | [ ] | |
| TASK-023 | **VERIFY (real, required)**: send a real incoming photo. Confirm `messages.media_local_filename` and `media_download_attempted_at` are filled for the new row, the file exists on disk, and `gateway.log` shows **no** `[MEDIA]` entry when the photo is opened in the Inbox (proving the disk path served it). | REQ-006 | [ ] | |
| TASK-024 | Confirm the existing fallback still works: temporarily make the file unreadable and verify `Inbox::media()` falls through to live-fetch instead of erroring (`Inbox.php:462-466`). | CON-002 | [ ] | |
| TASK-025 | **APPROVAL**: 🛑 Wait for explicit user confirmation to proceed to Phase 5. | - | [ ] | |

### Implementation Phase 5: Deployment and Documentation

- **GOAL-005:** Put both changes live and record the evidence.

| Task | Description | Ref ID | Completed | Date |
| --- | --- | --- | :---: | :--: |
| TASK-026 | **DEPLOY (requires explicit owner go-ahead)**: merge the Gateway worktree branch into the deployed branch and restart the process, recording before/after HEAD, process state, and start-up log evidence in the shape of `docs/decisions/2026-09-28-viewonce-placeholder-fix-and-deploy.md` §5. | CON-006 | [ ] | |
| TASK-027 | Commit and push the AuliaPos changes on the active branch (`v2.3`). | - | [ ] | |
| TASK-028 | Update `docs/ARCHITECTURE.md` with the `/media/download` failure contract (`200` binary, `410` expired, `503` temporary, `504` timeout) and the local-storage decision. | - | [ ] | |
| TASK-029 | Update `docs/GATEWAY-REQUIREMENTS.md` with the media-download contract as a new `GW-*` entry carrying its measured status. | - | [ ] | |
| TASK-030 | Write the decision log in `docs/decisions/`: corrected root cause, the TASK-010 raw expiry evidence, what was measured versus assumed, and the storage-folder decision. | - | [ ] | |
| TASK-031 | Correct `docs/TODO-CHAT.md`: the note in `docs/decisions/2026-09-28-viewonce-placeholder-fix-and-deploy.md` §7 that called this "a media fetch/decrypt problem outside this fix's scope" is now a separate, diagnosed bug with its own plan. | - | [ ] | |
| TASK-032 | **VERIFY**: confirm both suites green (`vendor/bin/phpunit --no-coverage`; Gateway full batch) and that the live Gateway still reports `connected` after the restart. | - | [ ] | |
| TASK-033 | **APPROVAL**: 🛑 Wait for explicit user confirmation before closing the finding. | - | [ ] | |

## 3. Rollback Strategy

- **RBCK-001**: Record the live Gateway HEAD before TASK-026. The current deployed revision is `66bff03`
  on `master`. To revert the Gateway, check out the recorded commit in the live folder and restart the
  process. No migration is involved.
- **RBCK-002**: Gateway mitigation narrower than a full revert: restore only the `catch` block in
  `src/api/ci4Routes.js` to its previous all-cases-`410` form. This reintroduces the latent
  permanent-loss risk and MUST NOT be left in place longer than necessary.
- **RBCK-003**: To revert the AuliaPos view change, `git revert` the commit from TASK-027. No schema
  change is involved, so no data migration is needed in either direction.
- **RBCK-004**: To revert the storage decision, unset `inbox.mediaStoragePath`. Behaviour returns to
  live-fetch; files already on disk become unreferenced and can be deleted manually. No `messages`
  column needs clearing.
- **RBCK-005**: A whole-row recovery path, if a media is ever wrongly marked gone: clear that row's
  `media_confirmed_gone_at`. This plan does not automate it, because after TASK-011 the incorrectly
  marked state should no longer be producible. Record in the decision log how many rows (if any) needed
  it.

## 4. Dependencies

- **DEP-001**: WA-Gateway on `master`, currently `66bff03`, with Baileys `6.7.24`. No new packages.
- **DEP-002**: `config.mediaFetchTimeoutMs` (`src/config/index.js:161`) already exists and is reused for
  TASK-009. No new configuration key is required for the Gateway timeout; if a separate key is chosen
  anyway, it MUST be documented in `src/config/index.js` in the existing style.
- **DEP-003**: AuliaPos `v2.3`, PHPUnit baseline `574 test / 2201 assertion` (2026-09-28). The baseline is
  a moving target; the gate is "zero failures", not a frozen count.
- **DEP-004**: MySQL `aulia_inboxdb` reachable for the TASK-023 and TASK-024 checks.
- **DEP-005**: Existing helpers reused without modification: `InboxMediaStorage` (`save`/`read`/
  `exists`), `Inbox::callGatewayMediaDownload()`, `InboxGatewayApi::extensiFromMime()`.

## 5. Files Affected

- **FILE-001**: `src/api/ci4Routes.js` (WA-Gateway) — classify `/media/download` errors instead of
  returning `410` for every failure.
- **FILE-002**: `src/whatsapp/connectionManager.js` (WA-Gateway) — bound `downloadMediaByRef()` with a
  timeout.
- **FILE-003**: `test/simulate-media-download-errors.js` (WA-Gateway) — new test script.
- **FILE-004**: `app/Views/inbox/index.php` (AuliaPos) — cause-specific media messages, one-shot status
  probe, bounded retry, and clearing of temporary failures on Gateway reconnect.
- **FILE-005**: `tests/session/` (AuliaPos) — new session test for `503` not marking media gone.
- **FILE-006**: `tests/unit/InboxMediaConfirmedGoneTest.php` (AuliaPos) — add `503`/`504` mirror
  scenarios.
- **FILE-007**: `.env` (AuliaPos) — add `inbox.mediaStoragePath`. Not committed to version control.
- **FILE-008**: `docs/ARCHITECTURE.md`, `docs/GATEWAY-REQUIREMENTS.md`, `docs/decisions/`,
  `docs/TODO-CHAT.md` — contract and record updates.

## 6. Testing Strategy & Edge Cases

- **TEST-001 (Gateway, automated)**: `test/simulate-media-download-errors.js` covers transient → `503`,
  expiry → `410`, timeout → `504`, and success → `200` with the original bytes. The `500` path for an
  unexpected exception stays covered so a programming error is not disguised as a network problem.
- **TEST-002 (AuliaPos, automated)**: a session test drives `Inbox::media()` with a stubbed `503` and
  asserts no `media_confirmed_gone_at` write; the mirror script in
  `tests/unit/InboxMediaConfirmedGoneTest.php` gains the same scenarios. Note that the mirror duplicates
  the decision order from `Inbox.php` rather than calling it, so the session test is the one that would
  catch a regression in the controller itself.
- **TEST-003 (browser checklist, manual — required)**: there is no JavaScript test runner in this
  project, so a regression in `app/Views/inbox/index.php` will not fail the suite. All four steps MUST be
  walked manually and recorded: photo visible while the Gateway is healthy; Gateway stopped → temporary
  message with no false "kadaluarsa"; Gateway restarted → photo appears with no page reload; genuinely
  expired media → permanent message and no repeated requests. If `build/check-round-guard.php` is present
  (it is gitignored and may live outside the repo), run it as well.
- **TEST-004 (real, required)**: TASK-023 verifies local storage end to end with a real incoming photo
  and an empty `[MEDIA]` log line, which is the only proof that the disk path actually served the file.
- **Edge cases explicitly considered**:
  - **A `503` while the cashier is looking at the thread**: retried, bounded, and cleared immediately on
    reconnect. This is the exact case that produced this bug report.
  - **A genuinely expired media**: keeps the single fast `410`, no Gateway traffic, no retry loop
    (CON-002 preserved).
  - **Both transient and expired in one thread**: reasons are tracked per message id, so one does not
    change the other's behaviour.
  - **Outgoing media** (`direction = 'outgoing'`): `media_metadata` comes from the send response and may
    legitimately be `NULL`. It follows the same rendering path and MUST NOT regress; `Inbox.php:427`
    already returns `400` for a missing reference.
  - **Storage disk unavailable** (unplugged or full): `Inbox.php:462-466` falls through to live-fetch, and
    TASK-024 verifies it.
  - **Prefetch timeout budget**: the prefetch call uses an 8-second client timeout
    (`InboxGatewayApi.php:358`). Per CON-008 the Gateway's own timeout must be below that, otherwise the
    client aborts first and the prefetch records a failure without a useful classification. The chosen
    value MUST be checked against both budgets (8 s prefetch, 30 s live-fetch) and documented.
  - **Existing media rows**: the 6 current rows have no local file. They keep working through
    live-fetch and cannot be backfilled by this plan. Stated so a later reader does not treat it as an
    oversight.

## 7. Risks & Assumptions

- **RISK-001**: The frontend change touches a critical view with **no automated JavaScript coverage**.
  A mistake here breaks the whole Inbox, not just media. Mitigation: TASK-019 is a mandatory manual
  browser checklist, and the change is deliberately confined to the media rendering branch and the
  existing reconnect hook.
- **RISK-002**: The one-shot `fetch` probe in TASK-015 issues a second request for a media that already
  failed. It runs at most once per message per attempt and only on the error path, so the happy path and
  the existing ETag/`304` caching are unaffected (CON-004), but it does mean a failing media costs two
  requests instead of one. Accepted as the price of a truthful message.
- **RISK-003**: Misclassifying a genuine expiry as transient would make an unviewable media retry three
  times instead of stopping. Bounded and harmless. The reverse mistake — transient classified as expiry
  — is the dangerous direction and is exactly what TASK-010 exists to prevent by measuring the real
  signal instead of guessing it.
- **RISK-004**: Enabling local storage consumes disk indefinitely; nothing prunes old media. A retention
  policy is **out of scope** for this bug fix and MUST be raised separately if the folder grows. TASK-021
  records this consequence as part of the owner's decision.
- **RISK-005**: The Gateway process is currently started manually and there is no supervisor. If it stops,
  media and sending stop until someone restarts it. That operational gap is not fixed here.
- **ASSUMPTION-001**: The exact error shape of a genuinely expired media is **unknown until TASK-010
  measures it**. TASK-003 and TASK-011 MUST NOT be finalized on a guessed error property. If the signal
  turns out to be indistinguishable from a transient network error, say so and fall back to treating
  only an explicit expiry status from the media host as `410`, leaving everything else `503` — a missed
  expiry then merely retries within the bound instead of destroying recoverability.
- **ASSUMPTION-002**: AuliaPos continues to treat `410` as the only permanent signal (`Inbox.php:525`).
  This plan does not change that rule; it changes the Gateway so the rule is fed the truth. TASK-006
  locks the rule in place with a test.
- **ASSUMPTION-003**: The cashier can retry by simply waiting, and does not need a manual "coba lagi"
  button. If the owner prefers an explicit button, it is an additive change to TASK-016 and does not
  alter the rest of the plan.
- **OUT OF SCOPE — C3 / GW-25**: the Gateway connection flapping every 1–3 minutes (`428 Connection
  Terminated`, `getaddrinfo ENOTFOUND web.whatsapp.com`, `Timed Out` in init queries) and the repeated
  "No matching sessions found" decryption storm for the account's own LID. Observed live on 2026-09-28
  and still open. It makes media fetches intermittently fail even while the Gateway is up, and is the
  reason this fix must make failures recoverable rather than merely rarer. It is a separate, larger
  defect and is deliberately not addressed here.
