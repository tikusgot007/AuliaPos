---
goal: Surface incoming view-once messages as a visible placeholder instead of dropping them silently
version: 1.0
date_created: 2026-09-28
last_updated: 2026-09-28
owner: WA-Gateway
status: "Completed"
tags: ["bug-fix", "remediation", "patch"]
---

# Introduction

![Status: Completed](https://img.shields.io/badge/status-Completed-brightgreen)

Incoming **view-once** messages are lost silently. When a customer sends a photo or voice note as
"lihat sekali" (view once) to the shop number, WhatsApp wraps it in `viewOnceMessageV2`
(`viewOnceMessage` / `viewOnceMessageV2Extension` on older clients). Baileys forwards that wrapper
untouched (`baileys/lib/Socket/chats.js:765` — it does not call `normalizeMessageContent` on the
receive path), and the Gateway's type dispatch in `connectionManager.js` only looks for bare
`conversation` / `imageMessage` / `documentMessage` / `stickerMessage` / `audioMessage` /
`videoMessage`. Every one of those is `undefined` inside a wrapper, so the message falls through to
the final `else`, is logged at `debug` level — which `LOG_LEVEL=info` suppresses entirely — and is
discarded.

The cashier therefore never sees the message, and there is no error, no warning and no database row
to reveal that anything was lost. The customer believes it was sent.

This was confirmed with a controlled real-WhatsApp test on 2026-09-28 (see §6 TEST-002): a plain text
sent from the same phone arrived within seconds, while a view-once photo sent two minutes earlier
produced no `incoming_queue` row, no `messages` row and no log line, with the Gateway reporting
`connected` and a 13-second-old heartbeat.

The fix surfaces the message as a **text placeholder** and deliberately does **not** fetch the
view-once media (product decision: a customer who chooses "view once" is signalling that the content
should not be retained or displayed by a third party).

## 1. Requirements & Constraints (Fix Constraints)

- **REQ-001**: An incoming view-once message (`viewOnceMessage`, `viewOnceMessageV2`,
  `viewOnceMessageV2Extension`) MUST produce exactly one persisted incoming event, so the cashier can
  see that the customer sent something.
- **REQ-002**: The placeholder MUST be a plain text event. The Gateway MUST NOT download, decrypt,
  store or forward the wrapped media.
- **REQ-003**: A genuinely unsupported message type (location, contact card, poll, etc.) MUST remain
  dropped, but its rejection MUST be observable — logged at `warn`, not `debug`.
- **CON-001**: The `POST /api/inbox/gateway/messages` payload structure MUST NOT change. The
  placeholder reuses the existing `message_type: 'text'` + `text` fields, which AuliaPos already
  accepts (AC-040: payload stays identical in shape).
- **CON-002**: Only **incoming** (`fromMe === false`) view-once messages get a placeholder. A
  view-once the cashier sends from WhatsApp Web or the phone MUST keep its current behaviour
  (dropped), so the Inbox never shows a fabricated outgoing message the staff did not send from the
  POS.
- **CON-003**: No AuliaPos application code changes. The fix is Gateway-side only.
- **CON-004**: `_handleIncomingMessage()` is on the only path that calls `_persistIncoming()`
  (REQ-001 of the incoming-reliability plan), so the change MUST NOT add a new persistence call or
  bypass the existing enqueue/overflow handling.
- **CON-005**: Gateway code changes MUST be made in an isolated worktree, never in the live folder
  `C:\projects\WA-Gateway` (Gateway scope invariant), and deployed only via the documented deploy
  step.
- **CON-006**: `auth/` MUST NOT be touched.

## 2. Implementation Steps

> **⚠️ EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> You MUST execute this plan phase by phase. You MUST run the specific testing/verification task at
> the end of each phase. After a phase is tested, you **MUST STOP AND WAIT** for the user's explicit
> approval before proceeding to the next phase.

### Implementation Phase 1: Test Writing (Test-Driven Bug Fixing)

- **GOAL-001:** Write a failing test that reproduces the silent loss of a view-once message.

| Task     | Description                                                                                                                                                                                                                                                                                                                                                                     | Ref ID  | Completed | Date |
| -------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------- | :-------: | :--: |
| TASK-001 | Create `test/simulate-viewonce.js` in the Gateway worktree, following the existing `test/simulate-sticker.js` / `test/simulate-audio-video.js` pattern (drive `_handleIncomingMessage()` with a synthetic Baileys message, assert on what reaches the incoming store).                                                                                                          | REQ-001 |  [x]  | 2026-09-28 |
| TASK-002 | In that test, feed `{ message: { viewOnceMessageV2: { message: { imageMessage: {...} } } } }` with `fromMe: false` and assert the store received **one** event with `message_type = 'text'` and `text` equal to the agreed placeholder string. This assertion MUST FAIL against the current code (the message is dropped, so zero events are stored).                          | REQ-001 |  [x]  | 2026-09-28 |
| TASK-003 | Add a second case: the same wrapper with `fromMe: true` MUST store **zero** events (CON-002), keeping current behaviour.                                                                                                                                                                                                                                                          | CON-002 |  [x]  | 2026-09-28 |
| TASK-004 | Add a third case: a genuinely unsupported type (e.g. `{ message: { locationMessage: {...} } }`) MUST store zero events **and** MUST emit a `warn`-level log line (REQ-003). This case also fails today, because the line is `debug`-level.                                                                                                                                        | REQ-003 |  [x]  | 2026-09-28 |
| TASK-005 | **VERIFY**: Run `node test/simulate-viewonce.js`. It MUST FAIL on TASK-002 and TASK-004 assertions, proving the tests detect the real defect. Record the raw output.                                                                                                                                                                                                              | -       |  [x]  | 2026-09-28 |
| TASK-006 | **APPROVAL**: 🛑 Wait for explicit user confirmation to proceed to Phase 2.                                                                                                                                                                                                                                                                                                       | -       |  [ ]    |      |

**Placeholder string (fixed, must match exactly in code and test):**

```text
[Pesan lihat-sekali dari pelanggan — isinya tidak dapat ditampilkan di Inbox]
```

### Implementation Phase 2: Minimal Root Cause Remediation

- **GOAL-002:** Detect the view-once wrapper in the type dispatch and emit the placeholder; make
  unsupported-type drops observable.

| Task     | Description                                                                                                                                                                                                                                                                                                                                                    | Ref ID  | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------- | :-------: | :--: |
| TASK-007 | In `src/whatsapp/connectionManager.js`, inside `_handleIncomingMessage()`, in the `if (text === null)` block (currently lines 801–886), add a view-once branch **before** the final `else`: detect `msg.message.viewOnceMessage \|\| msg.message.viewOnceMessageV2 \|\| msg.message.viewOnceMessageV2Extension`. When found **and** `fromMe === false`, set `messageType = 'text'`, `text` = the placeholder string, and leave `media = null` (REQ-002). Return the same `logger.info` note recording that a view-once was replaced by a placeholder, including `messageId` and the wrapper key. | REQ-001, REQ-002, CON-002 |  [x]  | 2026-09-28 |
| TASK-008 | In the same file, when the wrapper is found **and** `fromMe === true`, keep the existing skip path unchanged (CON-002). Do not emit a placeholder.                                                                                                                                                                                                               | CON-002 |  [x]  | 2026-09-28 |
| TASK-009 | In the same file, change the final `else` dropped-type log from `logger.debug(...)` to `logger.warn(...)`, keeping the existing `messageId` and `type` fields (REQ-003). Add one clause to the message stating the message is being discarded so it is not mistaken for a handled path.                                                                           | REQ-003 |  [x]  | 2026-09-28 |
| TASK-010 | Confirm no other call site of `_persistIncoming()` was added or removed, and that `normalized` object shape is unchanged apart from `text` / `messageType` values (CON-001, CON-004).                                                                                                                                                                             | CON-001, CON-004 |  [x]  | 2026-09-28 |
| TASK-011 | **VERIFY**: Run `node test/simulate-viewonce.js` — all assertions MUST PASS. Then run the full Gateway test batch (all `test/simulate-*.js` and `test/check-*.js`, in batches to stay below the command timeout) and confirm zero regressions. Record the raw output.                                                                                              | -       |  [x]  | 2026-09-28 |
| TASK-012 | **MANUAL E2E (real WhatsApp, required)**: with a disposable Gateway instance, send a view-once photo from the test phone to the shop number and confirm exactly one incoming event with the placeholder text appears in the `incoming_queue` and in AuliaPos `messages`, and that no media is stored. Then send a plain text and confirm it still arrives normally. | REQ-001, REQ-002 |  [x]  | 2026-09-28 |
| TASK-013 | **APPROVAL**: 🛑 Wait for explicit user confirmation before deployment.                                                                                                                                                                                                                                                                                            | -       |    [ ]    |      |

> [!IMPORTANT]
> **Amendment 2026-09-28 — corrected detection point (evidence from the real E2E in TASK-012).**
> The Phase 2 implementation as originally written (wrapper-only, TASK-007) proved **insufficient**. A real view-once photo sent to the linked device produced **no `viewOnceMessageV2` wrapper at all**: WhatsApp sends only an `<unavailable type="view_once">` stanza, Baileys sets `msg.key.isViewOnce = true` and leaves `msg.message` **undefined** (`node_modules/baileys/lib/Utils/decode-wa-message.js:127,192-195`; `lib/Socket/messages-recv.js:624-632`), then still emits `messages.upsert` (`lib/Socket/chats.js:764-765`). The Gateway was dropping it at the first line of `_handleIncomingMessage` (`if (!msg.message) return;`) — **before** the wrapper branch — so nothing was stored and nothing was logged.
> Additional change applied in `src/whatsapp/connectionManager.js`: detect `!msg.message && msg.key?.isViewOnce === true` **before** that guard. `fromMe === false` → placeholder text event (REQ-001/REQ-002/CON-002); `fromMe === true` → still dropped (CON-002); other empty/protocol messages → dropped as before. The wrapper branch is **kept**: it covers the placeholder-resend path (`messages-recv.js:627-630`, `RESOLVED`), where the primary phone re-sends the real content.
> `test/simulate-viewonce.js` gained cases 1b/2b/2c for this real path (all pass). This amendment records the corrected primary detection point; TASK-007's wrapper branch stays as defence-in-depth.

### Implementation Phase 3: Deployment (owner approval required)

- **GOAL-003:** Put the verified change on the live Gateway without touching `auth/`.

| Task     | Description                                                                                                                                                                                                                                                                     | Ref ID  | Completed | Date |
| -------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------- | :-------: | :--: |
| TASK-014 | **VERIFY/APPROVAL (deploys to the live Gateway)**: request the owner's explicit go-ahead, then merge the worktree branch into the deployed branch and restart the process, following the same deploy record shape as `docs/decisions/2026-09-24-m1-wave2-phase5-deploy.md` §2 (record before/after HEAD, process status, start-up log evidence). | CON-005 |  [x]  | 2026-09-28 |
| TASK-015 | Record the deployment plus a fresh real-WhatsApp view-once check as a new decision log in `docs/decisions/`.                                                                                                                                                                       | -       |  [x]  | 2026-09-28 |
| TASK-016 | **APPROVAL**: 🛑 Wait for explicit user confirmation before closing the finding.                                                                                                                                                                                                  | -       |  [x]  | 2026-09-28 |

## 3. Rollback Strategy

- **RBCK-001**: Before deploying, record the live Gateway HEAD (currently `a2ba409` on `master`) and
  the process state, exactly as the Wave 2 Phase 5 deploy record does, so the previous revision is
  known.
- **RBCK-002**: To revert, check out the recorded previous commit in the live folder and restart the
  Gateway process (the same restart used to deploy). No database migration or AuliaPos change is
  involved, so nothing else needs undoing.
- **RBCK-003**: If the change misbehaves but only for view-once messages, the narrower mitigation is
  to take TASK-009 alone (log at `warn`) and drop TASK-007/TASK-008, which restores the exact
  previous behaviour while leaving the loss observable.

## 4. Dependencies

- **DEP-001**: Baileys `6.7.24` (installed, pinned in `package.json`). The wrapper key names came from
  `node_modules/baileys/lib/Utils/messages.js:564` (`normalizeMessageContent`, which lists exactly
  `ephemeralMessage`, `viewOnceMessage`, `documentWithCaptionMessage`, `viewOnceMessageV2`,
  `viewOnceMessageV2Extension`, `editedMessage`).
- **DEP-002**: No new packages. The fix uses only plain property access on the already-received
  message object.

## 5. Files Affected

- **FILE-001**: `C:\projects\WA-Gateway\src\whatsapp\connectionManager.js` — add the view-once branch
  in `_handleIncomingMessage()` and raise the dropped-type log to `warn`.
- **FILE-002**: `C:\projects\WA-Gateway\test\simulate-viewonce.js` — new test script.
- **FILE-003**: `docs/TODO-CHAT.md` (AuliaPos) — correct item C4 once the fix is verified: the old
  note claimed E-02/E-07 were unverified hypotheses; E-02 is now confirmed and narrowed to view-once.
- **FILE-004**: `docs/decisions/` (AuliaPos) — new decision log for the fix and its real-WhatsApp
  re-check.

## 6. Testing Strategy & Edge Cases

- **TEST-001 (automated)**: `test/simulate-viewonce.js` covers the three cases in Phase 1, plus one
  case where the wrapper is empty (`viewOnceMessageV2: {}`) — that MUST NOT throw; it should take the
  unsupported path and log `warn`.
- **TEST-002 (real WhatsApp, already executed as diagnosis)**: on 2026-09-28 a view-once photo sent
  from the test phone produced zero events while a plain text sent two minutes later arrived normally
  (event id 6 at `01:04:29Z`). This is the reproduction; TASK-012 repeats it after the fix.
- **TEST-003 (regression)**: the full Gateway `simulate-*` / `check-*` batch must stay green. The
  dispatch sits ahead of every media type, so `simulate-sticker.js`, `simulate-audio-video.js`,
  `simulate-reply-quote.js` and `simulate-group-identity.js` are the most relevant neighbours.
- **Edge cases explicitly considered**:
  - `fromMe: true` view-once (CON-002 — no placeholder).
  - A view-once whose inner message is itself unknown — the placeholder replaces the whole wrapper, so
    the inner type never needs to be understood.
  - A view-once in a **group** chat — the placeholder follows the normal path, so `sender_jid` and
    `group_name` handling is unchanged (REQ-010 of the group plan).
  - `editedMessage` and `documentWithCaptionMessage`: **out of scope** — the 2026-09-28 tests showed
    a document with a caption arrives correctly as `document` with its caption intact, so no wrapper
    handling is added for it. Recorded so a later reader does not assume it was overlooked.

## 7. Risks & Assumptions

- **RISK-001**: The placeholder is fabricated content. If a later reader forgets why it exists, they
  may mistake it for a real customer message. Mitigation: the placeholder text is explicit, and the
  Gateway logs one `info` line per substitution with the `messageId`.
- **RISK-002**: A future WhatsApp/Baileys change may start wrapping **other** types (one of the six
  keys in `normalizeMessageContent`). The TASK-009 `warn` log is the safety net that makes any such
  loss visible instead of silent; it is deliberately included for that reason.
- **RISK-003**: An earlier audit finding (E-02, 2026-09-21) predicted this bug for *all* wrapped
  messages, including disappearing messages. Real testing on 2026-09-28 showed disappearing text,
  normal photos, and documents with captions all arrive correctly, so the fix is deliberately kept
  narrow to view-once. If that audit's broader claim is later re-asserted, the 2026-09-28 evidence
  supersedes it.
- **ASSUMPTION-001**: AuliaPos renders a `message_type: 'text'` incoming event without further
  validation, so no AuliaPos change is required (CON-003). TASK-012 verifies this on real hardware
  rather than trusting the assumption.
- **ASSUMPTION-002**: The cashier can act on the placeholder (e.g. ask the customer to resend
  normally). This is a workflow assumption, not a technical one; it is the reason a placeholder was
  chosen over silently fetching the media.
