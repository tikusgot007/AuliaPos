# 0003 - Run the WA-Gateway on the AuliaPos server PC, localhost-only, store hours only

**Date:** 2026-09-29  
**Status:** Accepted  

## Context

Production runs AuliaPos v2.1 (POS only) on the store server PC (`192.168.1.10`); staff handle
customer chat through WhatsApp Web. The target is AuliaPos v2.3 plus the external WA-Gateway, which
could be hosted on the same PC, on a second store PC, on a 24/7 machine, or on an Android device.
Both store computers only run during store hours. Incoming media is **not** stored on the Gateway: it
buffers only a `directPath`/`mediaKey` reference and downloads on demand at `POST /media/download`.
Gateway offline handling is already verified in code and by test: `messages.upsert` types `notify`
**and** `append` are both processed with no age filter (`connectionManager.js:450-469`,
`_shouldAcceptAppend` at `:531-547`), and M1 AC-001 proved 30/30 messages delivered, 0 lost,
0 duplicates across real Gateway restarts. Duplicate protection is double-layered
(`existsByWaMessageId()` plus a `UNIQUE` key on `messages.wa_message_id`). The Android host is a worse
alternative because its buffer there is the JSON fallback (only 2 of 8 scenarios hand-verified on
device) and it introduces a fragile LAN/IP dependency.

## Decision

The WA-Gateway runs **on the same PC as AuliaPos**, started **only during store hours**, and binds to
the loopback interface:

- `HOST=127.0.0.1`, `PORT=3000` — never exposed to the LAN.
- Gateway → AuliaPos: `CI4_BASE_URL=http://localhost/aulia` (the LAN variant
  `http://192.168.1.10/aulia` stays commented out).
- AuliaPos → Gateway: `inbox.gatewayBaseUrl='http://127.0.0.1:3000'`.
- `inbox.mediaStoragePath='D:/aulia_inbox_media/'`.
- Windows auto-start (service or scheduled task) with working directory `C:\projects\WA-Gateway` so
  `SQLITE_PATH=./data/gateway.sqlite` resolves correctly.
- Staff keep using WhatsApp Web until the planned cutover; during the shadow period the Inbox is a
  read-only mirror.

## Consequences

A 24/7 Gateway buys nothing here: delivery still waits for AuliaPos to be up, and because incoming
media is not stored on the Gateway (buffer holds only a reference, downloaded on demand) a 24/7 host
would not protect media either. Accepting store-hours-only operation means messages sent while the PC
is off are queued by WhatsApp and replayed on reconnect (`append` handling, no age filter), so an
overnight backlog drains at store opening — this is the accepted backlog behaviour, and it is only
safe because the double-layer duplicate protection already exists. Binding to `127.0.0.1` removes the
LAN attack surface and the IP dependency entirely. WhatsApp Web coexistence consumes a linked-device
slot and creates a real human double-reply risk until staff stop using Web, so the shadow period must
keep the Inbox read-only. Running POS and chat on one machine makes them share a single power/reboot
schedule and a single point of failure: if the server PC is down, both the counter and chat ingestion
stop until it is back.

## Considered Options

- **24/7 dedicated host — REJECTED.** Delivery still waits for AuliaPos, and media is not stored on
  the Gateway, so a 24/7 Gateway protects nothing that store hours does not already cover.
- **Android device as the Gateway host — REJECTED.** Its buffer is the JSON fallback, only 2 of 8
  scenarios were hand-verified on a physical device, and it adds a fragile LAN/IP dependency.
- **Second store PC as the Gateway host — REJECTED.** Both computers run the same store-hours window,
  so it adds an inter-machine sync and IP surface for no availability gain.
- **PM2 / NSSM as the auto-start mechanism — DEFERRED.** Neither is installed; the plan installs a
  native Windows mechanism (service or scheduled task) and records the exact choice as an operational
  decision.
