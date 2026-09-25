---
goal: WA-Gateway Android — pairing code stuck null after a 401 logged_out disconnect (zombie socket + no UI recovery)
version: 1.0
date_created: 2026-09-25
last_updated: 2026-09-25
owner: WA-Gateway Android build
status: "Completed"
tags: ["bug-fix", "remediation", "patch", "wa-gateway", "android", "pairing-code", "baileys"]
---

# Introduction

![Status: Completed](https://img.shields.io/badge/status-Completed-brightgreen)

On the physical Android device, `GET /api/status` reports `pairingCode: null` forever once
`lastDisconnectReason` becomes `"401: Connection Failure"` (Baileys `DisconnectReason.loggedOut`) and
`status` becomes `logged_out`. This was initially suspected to be a Kotlin/JSON parsing bug, but live
inspection of the device's private storage (`adb shell run-as com.auliapos.wagateway`) and a controlled
`POST /api/logout` → `POST /api/pairing-code` reproduction on the physical device confirmed the real root
cause is entirely on the Node/Baileys side, with a compounding Android UI gap:

1. **Zombie socket after `logged_out` (WA-Gateway repo, `src/whatsapp/connectionManager.js`).**
   `_onConnectionUpdate()` (lines 196-265) detects `isLoggedOut` (line 247-249) and correctly stops
   auto-reconnect (by design — the operator must consciously call `/api/logout` to get a new
   QR/pairing code). However, the `isLoggedOut` branch (lines 249-256) never clears `this.sock`, unlike
   the deliberate cleanup already implemented in `logout()` (lines 356-370: `sock.logout()` +
   `removeAllListeners()` + `sock.end(undefined)` + `this.sock = null`). The dead socket object stays
   referenced on `this.sock`.
2. **`requestPairingCode()` accepts stale, dead sockets (`src/whatsapp/connectionManager.js`, lines
   324-340).** The guard only checks `if (!this.sock)` (line 328) and
   `this.sock.authState?.creds?.registered` (line 331) — both pass even when the underlying WebSocket
   is already closed after a `logged_out` event, because the socket object itself still exists and
   `registered` is still `false` (confirmed on-device: `auth/creds.json` had `"registered": false` with
   a leftover `"pairingCode": "PNAEED74"` and populated `"me"` from a previous, unfinished pairing
   attempt). Calling `sock.requestPairingCode()` on this dead socket does not surface a useful error to
   the HTTP response — `GET /api/status` then shows `pairingCode: null` indefinitely.
3. **No recovery path in the Android UI
   (`android/app/src/main/java/com/auliapos/wagateway/ui/MonitorScreen.kt`).** `StatusCard()` already
   renders a distinct label for `"logged_out"` ("Logout — perlu login ulang"), and
   `GatewayApiClient.logout()` already exists and works correctly (`GatewayApiClient.kt`, lines 61-66;
   confirmed manually via `adb forward` + `Invoke-RestMethod POST /api/logout`, which returned the
   gateway to a clean `connecting` / `hasQr: true` state and let a brand new pairing code
   (`HHXRAY2Q`) be issued successfully). However, no Composable in `MonitorScreen.kt` ever calls
   `GatewayApiClient.logout()` — the pairing form is shown for any `status != "connected"`, including
   `"logged_out"`, with no distinct "Reset Session" action. The operator is stuck retrying "Minta
   Pairing Code" against the zombie socket with no way to trigger the one action (`/api/logout`) that
   actually fixes it, short of manual `adb`/`curl` access.

**Reproduction performed (2026-09-25, physical device `RR8N201VC9T`):**

- `GET /api/status` → `{"status":"logged_out","lastDisconnectReason":"401: Connection Failure","pairingCode":null}`.
- `adb shell run-as com.auliapos.wagateway ls -la files/nodejs-project/auth` → `auth/creds.json`
  present, `"registered": false`, stale `"pairingCode": "PNAEED74"`, populated `"me"` — evidence of an
  earlier, unfinished pairing attempt that transitioned the socket to `logged_out` without cleanup.
- `POST /api/logout` → `{"ok":true}`; 3s later `GET /api/status` →
  `{"status":"connecting","hasQr":true,"pairingCode":null}` (`auth/` folder confirmed emptied by
  `ls -la`).
- `POST /api/pairing-code {"phone":"6281913500707"}` →
  `{"ok":true,"data":{"pairingCode":"HHXRAY2Q", ...}}` — proves the pairing flow itself is correct once
  the zombie socket is cleared.

This confirms the bug is a **state-cleanup gap in `connectionManager.js`** compounded by a **missing
recovery affordance in the Android UI**, not a parsing or networking defect.

## 1. Requirements & Constraints (Fix Constraints)

- **REQ-001:** After a `logged_out` disconnect (`DisconnectReason.loggedOut`, HTTP-style code 401), the
  Gateway MUST NOT leave a stale/dead `this.sock` reference that can be handed to
  `requestPairingCode()` or any other socket-dependent call.
- **REQ-002:** `requestPairingCode()` MUST fail fast with a clear, actionable error message (not a
  silent `pairingCode: null`) when called while `status === "logged_out"`, instructing the caller to
  reset the session first.
- **REQ-003:** The Android Monitor screen MUST offer an explicit "Reset Session" action, visible only
  when `status == "logged_out"`, that calls the existing `GatewayApiClient.logout()` and gives the
  operator visible feedback (loading state + error handling), so recovery does not require `adb`/`curl`.
- **CON-001:** The intentional "no auto-reconnect after logout" behavior (operator must consciously
  reset) MUST be preserved — this fix must not silently auto-reconnect after `logged_out`.
- **CON-002:** No change to the wire contract of `/api/status`, `/api/pairing-code`, `/api/logout`, or
  `/api/qr` (response shape stays identical) — this is a state-cleanup and UX fix, not an API redesign.
- **CON-003:** No change to `config.reconnect` backoff behavior for the *non*-logged-out reconnect path
  (`_scheduleReconnect()`), and no change to `logout()`'s existing, already-correct cleanup sequence.
- **CON-004:** Existing `test/simulate-*.js` and `test/check-*.js` scripts MUST keep passing unmodified
  in their existing assertions; new assertions are additive only.

## 2. Implementation Steps

> **⚠️ EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> You MUST execute this plan phase by phase. You MUST run the specific testing/verification task at the
> end of each phase. After a phase is tested, you **MUST STOP AND WAIT** for the user's explicit
> approval before proceeding to the next phase.

### Implementation Phase 1: Test Writing (Test-Driven Bug Fixing)

- **GOAL-001:** Write failing checks that reproduce the two gaps (zombie `this.sock` after
  `logged_out`, and `requestPairingCode()` not fast-failing on a `logged_out` status) before any
  production code changes.

| Task     | Description                                                                                                                                                                                                                    | Ref ID  | Completed | Date |
| -------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------- | :-------: | :--: |
| TASK-001 | Add a new runtime simulation `test/simulate-logged-out-cleanup.js`: stub Baileys' `makeWASocket` to return a fake socket, drive a `connection.update` event with `lastDisconnect.error.output.statusCode = DisconnectReason.loggedOut`, then assert `connectionManager` exposes `this.sock === null` (or an equivalent public accessor) afterward. | REQ-001 |    [x]    | 2026-09-25 |
| TASK-002 | In the same script, after simulating the `logged_out` transition, call `requestPairingCode('6281900000000')` and assert it **rejects** with a message mentioning "logout"/"reset" (not a silent success with an empty code). | REQ-002 |    [x]    | 2026-09-25 |
| TASK-003 | **VERIFY**: Run `node test/simulate-logged-out-cleanup.js`. It MUST FAIL (both assertions fail against current code: `this.sock` is still set, and `requestPairingCode()` does not reject).                                  | -       |    [x]    | 2026-09-25 |
| TASK-004 | **APPROVAL**: 🛑 Wait for explicit user confirmation to proceed to Phase 2                                                                                                                                                    | -       |    [x]    | 2026-09-25 |

### Implementation Phase 2: Minimal Root Cause Remediation (Node/Baileys side)

- **GOAL-002:** Clear the dead socket on `logged_out` and make `requestPairingCode()` fail fast and
  clearly, without touching the intentional "no auto-reconnect" behavior.

| Task     | Description                                                                                                                                                                                                  | Ref ID          | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------- | :-------: | :--: |
| TASK-005 | In `src/whatsapp/connectionManager.js`, inside the `isLoggedOut` branch (around line 249-256), before `return`, safely detach the dead socket: wrap in try/catch, call `this.sock.ev.removeAllListeners()` and `this.sock.end(undefined)` if `this.sock` exists, then set `this.sock = null` — mirroring the existing pattern already used in `logout()` (lines 356-370). | REQ-001, CON-001 |    [x]    | 2026-09-25 |
| TASK-006 | In `requestPairingCode()` (lines 324-340), add an explicit `if (this.status === 'logged_out')` guard (checked before the existing `!this.sock` check) that throws `new Error('Session sudah logout. Panggil /api/logout untuk mereset session sebelum meminta pairing code baru.')`. | REQ-002          |    [x]    | 2026-09-25 |
| TASK-007 | **VERIFY**: Re-run `node test/simulate-logged-out-cleanup.js`. It MUST PASS.                                                                                                                                 | -               |    [x]    | 2026-09-25 |
| TASK-008 | **VERIFY (regression)**: Run the existing `test/simulate-*.js` and `test/check-*.js` scripts touching `connectionManager.js` (at minimum: `simulate-outgoing-idempotency.js`, `simulate-outgoing-recovery.js`, `check-register-before-send.js`, `check-outgoing-begin-before-send.js`). All MUST still PASS unmodified. | CON-004          |    [x]    | 2026-09-25 |
| TASK-009 | **APPROVAL**: 🛑 Wait for explicit user confirmation to proceed to Phase 3                                                                                                                                   | -               |    [x]    | 2026-09-25 |

### Implementation Phase 3: Android UI Recovery Affordance

- **GOAL-003:** Give the operator a visible "Reset Session" action on the physical device when the
  Gateway is stuck in `logged_out`, using the already-existing (and already-verified-working)
  `GatewayApiClient.logout()`.

| Task     | Description                                                                                                                                                                                                             | Ref ID  | Completed | Date |
| -------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------- | :-------: | :--: |
| TASK-010 | In `android/app/src/main/java/com/auliapos/wagateway/ui/MonitorScreen.kt`, add local state (`isResettingSession`, `resetError`, mirroring the existing `isRequestingCode`/`pairingError` pattern at lines 60-62). | REQ-003 |    [x]    | 2026-09-25 |
| TASK-011 | Add a `Button`/`TextButton` visible only when `status?.status == "logged_out"`, labeled e.g. `"Reset Session"`, that calls `GatewayApiClient.logout(port)` inside a `LaunchedEffect`-driven trigger (same pattern as the existing pairing-code trigger), shows a loading label while in flight, and surfaces `resetError` on failure. | REQ-003 |    [x]    | 2026-09-25 |
| TASK-012 | Ensure the pairing-code form still renders for `logged_out` (so the operator can request a new code right after reset) but the "Reset Session" button is the primary, clearly-labeled action for that specific status — no removal of existing QR/pairing functionality. | REQ-003, CON-002 |    [x]    | 2026-09-25 |
| TASK-013 | **VERIFY (manual, physical device)**: Force a `logged_out` state (e.g. call `/api/pairing-code` with an intentionally expired/never-entered code, wait for Baileys to reject), confirm the "Reset Session" button appears, tap it, confirm `GET /api/status` transitions to `connecting`/`hasQr:true` within a few seconds, then confirm a fresh `requestPairingCode` call from the same screen returns a real (non-null) code. | REQ-003 |    [x]    | 2026-09-25 |
| TASK-014 | **APPROVAL**: 🛑 Wait for explicit user confirmation that the fix is complete and ready to close                                                                                                                        | -       |    [x]    | 2026-09-25 |

## 3. Rollback Strategy

- **RBCK-001**: All Phase 2 changes are confined to two small, additive edits inside
  `src/whatsapp/connectionManager.js` (`isLoggedOut` branch + `requestPairingCode()` guard). Revert by
  `git checkout -- src/whatsapp/connectionManager.js` (or a targeted `git revert` of the fix commit) if
  the new guard causes unexpected regressions in the reconnect/pairing flow.
- **RBCK-002**: All Phase 3 changes are confined to `MonitorScreen.kt` (new state + one conditional
  button + one `LaunchedEffect`). Revert by
  `git checkout -- android/app/src/main/java/com/auliapos/wagateway/ui/MonitorScreen.kt` if the new UI
  element causes a Compose recomposition/build issue; `GatewayApiClient.logout()` itself is untouched
  and does not need to be reverted.
- **RBCK-003**: If both phases must be rolled back simultaneously, revert the fix commit(s) entirely; no
  schema, `.env`, or `auth/` folder changes are made by this plan, so rollback carries no data-loss risk.

## 4. Dependencies

- **DEP-001**: `src/whatsapp/baileysLoader.js` (`getBaileys()` — `DisconnectReason` enum) — read-only
  dependency, already used at line 246 of `connectionManager.js`; no change needed.
- **DEP-002**: `GatewayApiClient.kt` (`logout()`, lines 61-66) — already implemented and already
  verified working via manual `adb forward` + `Invoke-RestMethod` reproduction; Phase 3 only wires the
  existing method into the UI, it does not modify `GatewayApiClient.kt`.
- **DEP-003**: Physical Android device with ADB access for TASK-013 manual verification (no emulator
  substitute — this bug was only reproducible against the real Baileys/WhatsApp network path).

## 5. Files Affected

- **FILE-001**: `src/whatsapp/connectionManager.js` — `isLoggedOut` branch (socket cleanup) and
  `requestPairingCode()` (fast-fail guard).
- **FILE-002**: `test/simulate-logged-out-cleanup.js` (new) — reproduction/regression test for both
  Phase 2 fixes.
- **FILE-003**: `android/app/src/main/java/com/auliapos/wagateway/ui/MonitorScreen.kt` — new "Reset
  Session" button and supporting state, scoped to the `logged_out` status.

## 6. Testing Strategy & Edge Cases

- **TEST-001**: `test/simulate-logged-out-cleanup.js` covers the two Node-side gaps end-to-end using a
  stubbed Baileys socket (no real WhatsApp network dependency, consistent with the existing
  `test/simulate-outgoing-idempotency.js` stubbing pattern).
- **TEST-002**: Regression run of the existing `connectionManager.js`-adjacent `simulate-*`/`check-*`
  scripts (TASK-008) guards against accidentally breaking the non-logged-out reconnect path
  (`_scheduleReconnect()`) or the already-correct `logout()` cleanup sequence.
- **TEST-003**: Manual on-device verification (TASK-013) is mandatory because the original bug was only
  observable against the real Baileys/WhatsApp 401 rejection path — no unit stub can fully substitute
  for confirming the Android UI button actually recovers a physically stuck gateway.
- **Edge case — double-tap on "Reset Session":** the button MUST disable itself while
  `isResettingSession` is true (same guard pattern already used for `isRequestingCode`), to avoid firing
  `/api/logout` twice concurrently.
- **Edge case — `logged_out` immediately followed by a fresh `logged_out` (rapid re-expiry):** REQ-002's
  fast-fail guard ensures the operator sees an explicit error instead of silently receiving
  `pairingCode: null` again if they retry pairing before pressing "Reset Session".

## 7. Risks & Assumptions

- **RISK-001**: The exact synchronous/asynchronous failure mode of calling `sock.requestPairingCode()`
  on a dead-but-still-referenced Baileys socket was not exhaustively traced inside the `baileys`
  dependency itself (v6.7.24) — REQ-002's guard sidesteps this entirely by checking `this.status` first,
  so the fix does not depend on fully understanding Baileys' internal error behavior on a dead socket.
- **RISK-002**: TASK-013's manual verification requires deliberately driving a real WhatsApp account
  into `logged_out` (e.g. via an expired pairing code) on the test device — this consumes one pairing
  attempt cycle and a few minutes of wait time; no automated substitute is proposed given DEP-003.
- **ASSUMPTION-001**: The root cause investigation and manual reproduction in this document were
  performed against the WA-Gateway repository checked out at `C:\home\wa-gateway-review`
  (`master` @ `4010cc1`), not the `C:\projects\WA-Gateway` live deployment referenced in prior M1 Wave 2
  session history — `/sdlc-write-code` MUST confirm which working copy is authoritative for this fix
  before committing.
