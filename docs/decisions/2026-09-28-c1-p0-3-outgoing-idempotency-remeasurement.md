# C1 / P0 #3 Re-measurement — Outgoing Idempotency on Real Gateway + WhatsApp (2026-09-28)

## 1. Status and Boundaries

This record closes the measurement that C1 (the remaining open P0 #3 risk item) asked for: *"duplicate outgoing on timeout — handled in Wave 2 via `operation_id` idempotency, but never re-verified end-to-end with a real Gateway after `a2ba409`."*

It was executed against the **real** Gateway at `C:\projects\WA-Gateway` (branch `master`, `a2ba409`) linked to a real WhatsApp account, driving the real cashier UI. The procedure followed is `docs/prosedur-verifikasi-p0-3-idempotensi-outgoing.md`, written earlier the same day.

| Item | Verified state |
| --- | --- |
| Gateway | Running (PID 26692), `connected` as `6281913500707` |
| AuliaPos | `http://localhost/aulia`, HTTP 200 |
| Test conversation | id `900002`, `chat_id=149701252890753@lid`, holder user 3 |
| Rounds executed | 4 (baseline + 3 measurement rounds) |
| Duplicates observed | **None** |
| Push | Yes (AuliaPos `origin/v2.3`) |

> **Boundary — read before quoting this record.** This run demonstrates that *the timeout + human-retry sequence no longer duplicates a message*. It does **not** close ASSUMPTION-009: the narrow window in which the Gateway dies exactly between "WhatsApp accepted the message" and "the operation row is marked `sent`" remains open, and the full guard for it is GW-21 in M2. Round 2 below deliberately grazed that window and produced the honest outcome (an unresolved operation requiring a human retry) — not a false success.

## 2. What the measurement was aimed at

Two acceptance criteria from `spec/spec-process-m1-wave2-outgoing-idempotency.md`:

- **AC-027** — cashier sends, the client times out, the cashier retries **inside** the 35 s lease; the Gateway must answer `409 SEND_IN_PROGRESS`, must **not** call `sendMessage` again, and the customer must receive **at most one** message.
- **AC-042** — retry **after** the lease on a `sent` operation must be answered `200` with `replayed: true`, with no second delivery.

## 3. Environment prepared in this session

| Step | Result |
| --- | --- |
| `npm install` in `C:\projects\WA-Gateway` | 214 packages, exit 0 (prebuilt binaries; no build tools needed) |
| `.env` created from `.env.example` | 103 lines, written UTF-8 **without BOM** (a BOM would break `dotenv` parsing of the first key) |
| `CI4_BASE_URL` | `http://localhost/aulia` (from AuliaPos `app.baseURL`, trailing slash removed) |
| `CI4_GATEWAY_TOKEN` | copied verbatim from AuliaPos `inbox.gatewayToken` (23 chars; value never printed or logged) |
| `HOST` / `PORT` | `127.0.0.1` / `3000` — matches AuliaPos `inbox.gatewayBaseUrl` |
| Process start | `node src/app/index.js` (directly; `npm.ps1` is blocked by the machine's PowerShell execution policy) |
| Node version | `v22.23.2` (spec requires ≥ 20; Node 24 is not supported) |
| WhatsApp link | QR pairing from the dashboard; `status: connected` |

The Gateway process was started as a background process with `persistent` lifetime, so it survives the agent session. It is **not** under PM2 (PM2 is not on `PATH` on this machine), so it will not restart automatically after a reboot.

## 4. Method and the freeze-budget finding

To make AuliaPos's 10 s client timeout fire while the Gateway still owed a send, the Gateway **process was frozen** with `NtSuspendProcess` (`build/suspend-gateway.ps1`, a local gitignored helper). Freezing is required rather than killing: the "sent but response lost" window is ~1 ms (`memory DE-26`), so a kill almost never reproduces it.

**Finding — the freeze budget is bounded by Baileys' keepalive, not by AuliaPos's heartbeat threshold.** There are two independent limits, and the *sooner* one wins:

| Limit | Value | Source |
| --- | --- | --- |
| AuliaPos marks the Gateway "not usable" (fast `503`) | 30 s | `app/Config/Inbox.php:44` (`heartbeatStaleSeconds`), heartbeat every 15 s |
| Baileys declares the WhatsApp socket dead | `keepAliveIntervalMs + 5000` (≈ 35 s of no server data) | `node_modules/baileys/lib/Socket/socket.js:295` |
| Baileys gives up waiting for a send acknowledgement | 60 s | `baileys/lib/Utils/generics.js:131` via `waitForMessage` |

Practically: **keep the freeze ≤ ~15 s.** Round 2 below used ≈ 26 s and the WhatsApp socket was killed mid-send.

## 5. Rounds

All timestamps are UTC; the machine's local time is UTC+7. Evidence is from `C:\projects\WA-Gateway\logs\gateway.log`, `C:\projects\WA-Gateway\data\gateway.sqlite` (read-only) and AuliaPos `aulia_inboxdb`.

### 5.1 Baseline — ordinary send

| Field | Value |
| --- | --- |
| Text | `VERIF-C1-A1` |
| Operation | `1acb34e9-a733-42c8-ade5-3a690a2a0ebe` |
| Lifecycle | created `00:24:09.998Z` → `sent` `00:24:11.045Z` |
| AuliaPos row | `messages.id = 900028` |
| Phone | received once |

### 5.2 Round 1 — retry inside the lease, operation already `sent`

| Step | Timestamp / value |
| --- | --- |
| Freeze | short (no `connection errored` line — the socket survived) |
| First attempt | the cashier UI reported failure; the send itself was buffered and completed on resume |
| Operation | `b7d1171e-992a-4e2f-808b-a57d1edab70b` |
| Send | `00:27:39.775Z` → `sent`, `attempts = 1` |
| Retry (same `operation_id`) | `00:28:02.300Z` |
| Gateway decision | `[SEND-OPERATION] replay hasil tersimpan, tidak dikirim ulang \| state=sent` |
| AuliaPos row | `messages.id = 900029` — exactly **one** |
| Phone | `VERIF-C1-A2` received **once** (WhatsApp shows 7:27 local, while AuliaPos recorded the row at 07:28:02 — the 23 s gap is the replay) |

Reading: AC-042's replay path on real hardware; **no duplicate**.

### 5.3 Round 2 — retry inside the lease while the operation was still `in_flight`

This round was set up so the cashier pressed "Kirim ulang" **while the Gateway was still frozen**, producing two near-simultaneous requests on resume.

| Step | Timestamp / value |
| --- | --- |
| Operation | `8d31cd2a-8359-4e35-87dd-5a16305cec38` |
| `begin()` (`in_flight`) | `00:33:52.113Z` |
| `[CHAT] balasan diminta` + `[SEND] mengirim pesan keluar` | `00:33:52.115Z` |
| Second request answered | `00:33:52.116Z` — `[SEND-OPERATION] operasi masih in_flight di dalam lease -- ditolak tanpa kirim (hasil belum pasti)` |
| WhatsApp socket | `00:33:52.117Z` — `Connection was lost` (408), `Koneksi WhatsApp terputus`, `reconnecting` |
| Reconnected | `00:33:57.198Z` — `connected` |
| Send gave up | `00:34:52.116Z` — `[SEND] gagal mengirim pesan`, `error: Timed Out` (**60.001 s** after the send started) |
| Row marked unresolved | `00:34:52.119Z` — `[SEND-OPERATION] hasil kirim tidak pasti (in_flight)`, `errorCode: SEND_FAILED` |
| Phone | **nothing received** |

Reading:

- **AC-027's literal branch is met.** The retry inside the lease was answered `409 SEND_IN_PROGRESS` (`state=in_flight`, `replayed:true`) and `sendMessage` was **not** called a second time. The cashier UI showed *"Hasil belum pasti, jangan kirim ulang dulu…"* (`app/Views/inbox/index.php:2713-2716`), which is REQ-041/AC-046 on a real screen.
- **This round is NOT valid evidence for "no duplicate"**, because nothing was delivered at all — see §6 for why.
- The row legitimately remained `in_flight` with `resolved_at = NULL`. `markUnresolved()` only writes `last_error` + `updated_at` and deliberately keeps the state `in_flight` (`src/store/outgoingOperations.js:156-160`): a timeout is genuinely ambiguous, so the system refuses to claim a definite failure.

### 5.4 Round 2b — retry after the lease expired (recovery)

| Step | Timestamp / value |
| --- | --- |
| Lease expiry | `00:35:27Z` (35 s after `updated_at = 00:34:52.117Z`; `outgoingLeaseMs`, `src/config/index.js:142`) |
| Cashier pressed "Kirim ulang" | `00:38:04.381Z` (lease 140 s past) |
| Gateway decision | `[SEND-OPERATION] lease lewat -- kirim ulang (attempts dicatat sebelum kirim)`, `attempts: 2` |
| Send | `00:38:04.381Z` → `[SEND] pesan berhasil dikirim` `00:38:04.584Z` (**203 ms**), `waMessageId 3EB0256EA1F959E92DC83D` |
| Row | `state=sent`, `attempts=2`, `resolved_at=00:38:04.584Z` |
| AuliaPos row | `messages.id = 900030` — exactly **one** |
| Phone | `VERIF-C1-A3` received **once** |

Reading: the documented recovery path works — `classifyExisting()` returns `retry` once the lease has expired and `attempts < cap` (`src/delivery/outgoingOperationService.js:105,154`), the retry is registered **before** sending, and the message is delivered. No duplicate: the first attempt provably never reached WhatsApp.

## 6. Root cause of the lost send in round 2

The freeze (≈ 26 s) exceeded Baileys' keepalive budget, so the socket was declared lost **2 ms after** the send had been handed to it:

```text
00:33:52.115  [SEND] mengirim pesan keluar
00:33:52.117  connection errored -- Connection was lost
```

The reconnect at `00:33:57` built a **new** socket, but the pending acknowledgement wait belonged to the dead one, so it simply idled until Baileys' 60 s limit and then reported `Timed Out`. No acknowledgement ever arrived, therefore no delivery — confirmed independently by the phone.

**This is an artefact of the measurement method, not a product defect.** Nothing in normal operation freezes the Gateway for 26 s. It nevertheless illustrates the real, documented boundary: the outgoing path has **no queue by design** (`docs/CHAT.md`), so if the link drops mid-send the send is genuinely uncertain and recovery depends on a human noticing the warning and retrying after the lease.

## 7. Conclusions

1. **P0 #3's core claim is verified on real hardware: the timeout + human-retry sequence does not duplicate a message** (round 1 — replay, exactly one delivery, exactly one `messages` row).
2. **The lease guard works as specified** (round 2 — `409 SEND_IN_PROGRESS`, no second `sendMessage`).
3. **Post-lease retry genuinely sends** and is safe when the previous attempt did not deliver (round 2b — `attempts` 1→2, one delivery, one row).
4. **The honest "hasil belum pasti" path works end to end**: an unresolved send is neither claimed as delivered nor silently dropped, and the cashier is told to check WhatsApp before retrying.

## 8. Honest limits and follow-ups

| # | Limit / follow-up |
| --- | --- |
| 1 | **ASSUMPTION-009 stays open.** The ~1 ms "accepted by WhatsApp but not yet recorded" window is not closed; the full guard is GW-21 (M2). |
| 2 | **No single round produced both the `409` branch and a successful delivery.** Round 2 proved the `409`; round 1 and 2b proved delivery. Achieving both at once needs the send to be slow while the Gateway stays responsive — e.g. stalling only the outbound WhatsApp path — which the freeze method cannot do. |
| 3 | **The `409`/`504` response never reached the cashier UI in round 2**, because AuliaPos's retry had already been buffered past its own 10 s timeout. The UI branch of AC-046 is therefore inferred from `REQ-041`'s rendering plus the round-2 warning text, not observed as a live `409` round-trip. |
| 4 | **Cosmetic oddity:** after a successful post-lease retry the row keeps `last_error = "Gagal mengirim pesan: Timed Out"` while `state = sent`. It is a historical trace of attempt 1, not a defect, but it reads as confusing and could be clarified later. |
| 5 | **The freeze technique has a hard budget of ≤ ~15 s**; the 30 s figure in the original procedure referred to the wrong limiter. The procedure document has been corrected. |
| 6 | The Gateway used here is a plain background process, not PM2 — it will not auto-start after a reboot. |

## 9. Artefacts produced

| Artefact | Note |
| --- | --- |
| `docs/prosedur-verifikasi-p0-3-idempotensi-outgoing.md` | Corrected freeze budget + Baileys 60 s send timeout + recovery step |
| `build/suspend-gateway.ps1` | Freeze/resume helper (gitignored, machine-local) |
| This record | Evidence tables above |
