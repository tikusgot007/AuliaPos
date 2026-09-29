---
goal: Go-live deployment of AuliaPos v2.3 + WA-Gateway on the store server PC (single machine, localhost-only, store hours)
version: 1.0
date_created: 2026-09-29
last_updated: 2026-09-29
owner: Store Go-Live / Operations (AuliaPos Inbox module)
status: 'Planned'
tags: [infrastructure, deployment, golive, ops, whatsapp, gateway, inbox, migration, windows]
---

# Introduction

![Status: Planned](https://img.shields.io/badge/status-Planned-yellow)

> **Status note (2026-09-29):** Plan drafted and self-reviewed by `Planner Architect`. The topology
> decision is recorded in `docs/adr/0003-single-pc-store-hours-gateway-topology.md`. Next checkpoint:
> `/sdlc-clarify-reqs` (new chat session) to resolve the open operational decisions in Section 7. No
> production change has been made; this plan stays `Planned` until execution begins.

This plan takes the current production store server from **AuliaPos v2.1 (POS only, no chat)** to
**AuliaPos v2.3 + WA-Gateway running on the same machine**, with the Gateway bound to
`127.0.0.1:3000` and started only during store hours. Production today has never run the chat feature;
staff use WhatsApp Web for customer chat. The target topology is fixed and encoded here (see
`docs/adr/0003-single-pc-store-hours-gateway-topology.md`); this plan does not re-litigate it.

This is a **deployment/operations plan**. It changes **no application code**. The only new written
artifacts are (a) Windows startup/service helper scripts marked as **ops artifacts** and stored
outside the application source tree, and (b) documentation updates. Any WA-Gateway code change, if it
turns out to be genuinely required, MUST be planned separately inside the WA-Gateway repository and
MUST never touch the live `auth/` folder.

Execution is split into 8 phases, each independently verifiable. Phases are sequential (they are
operations, not parallelisable code slices) but each phase MUST leave the store in a fully working
state that can be safely abandoned or rolled back. Every phase ends with a `VERIFY` and an `APPROVAL`
task; execution MUST stop and wait for explicit user approval before proceeding.

Two risks dominate and are marked High Risk: **Phase 1** (backups are the rollback substrate) and
**Phase 2** (production migrations plus the v2.1→v2.3 code deploy), **Phase 4** (WhatsApp pairing
touches the live WhatsApp session), and **Phase 5** (shadow period with human double-reply risk).

## 1. Requirements & Constraints

- **REQ-001**: The WA-Gateway MUST run on the same PC as AuliaPos, started only during store hours
  (one machine, one schedule).
- **REQ-002**: The Gateway MUST bind `HOST=127.0.0.1` and `PORT=3000` and MUST NOT be reachable from
  the LAN.
- **REQ-003**: Gateway → AuliaPos MUST use `CI4_BASE_URL=http://localhost/aulia`; the LAN variant
  `http://192.168.1.10/aulia` MUST stay commented out.
- **REQ-004**: AuliaPos → Gateway MUST use `inbox.gatewayBaseUrl='http://127.0.0.1:3000'`.
- **REQ-005**: `inbox.mediaStoragePath='D:/aulia_inbox_media/'`.
- **REQ-006**: The Gateway MUST auto-start on Windows (service or scheduled task) with working
  directory `C:\projects\WA-Gateway` so `SQLITE_PATH=./data/gateway.sqlite` resolves correctly.
- **REQ-007**: The Gateway MUST be paired to the store WhatsApp number from the server PC; AuliaPos
  MUST never write to or read the live `auth/` folder.
- **REQ-008**: Before cutover there MUST be a shadow period where the Gateway runs, staff KEEP using
  WhatsApp Web, and the Inbox is a read-only mirror.
- **REQ-009**: A real end-to-end proof MUST cover: incoming text, incoming photo, outgoing reply,
  duplicate check, and a restart-the-PC test.
- **REQ-010**: Cutover MUST move staff off WhatsApp Web onto the Inbox for customer chat.
- **REQ-011**: After execution, `docs/TODO-CHAT.md` and `docs/ARCHITECTURE.md` MUST be synced to
  reality.
- **REQ-012**: All **20** v2.3 migrations MUST be applied on production, and all **6** v2.1 migrations
  MUST be present in the applied set.
- **REQ-013**: AuliaPos v2.3 code MUST be deployed; the only v2.1→v2.3 code gap is the MySQLi platform
  guard around `GET_LOCK` in `TransaksiModel.php` (behaviour identical on MySQL).
- **CON-001**: No application source code changes. Startup/service scripts are **ops artifacts** only,
  stored outside the application source tree.
- **CON-002**: Any WA-Gateway code change MUST be planned separately in the WA-Gateway repo.
- **CON-003**: The live `auth/` folder MUST never be touched, copied over, or re-checked-out.
- **CON-004**: A new Inbox migration MUST run **before** deploying code that reads/writes the new
  column.
- **CON-005**: Inbox DB groups stay separate and distinct: `aulia_inboxdb` (production),
  `aulia_inboxdb_test` (PHPUnit, never live), `aulia_inboxdb_perf` (perf only). PHPUnit must run
  sequentially.
- **CON-006**: The shadow period is read-only: no staff replies may be sent from the Inbox.
- **SEC-001**: A verified, restorable backup MUST exist before any mutation of code, database, media,
  or `.env`.
- **SEC-002**: The shared Gateway token is a secret; it MUST be stored per-machine and never committed
  to a tracked file.
- **SEC-003**: The Gateway MUST NOT be bound to `0.0.0.0`; port 3000 MUST NOT be exposed to the LAN.

## 2. Implementation Steps

> **EXECUTION DIRECTIVE FOR AI AGENTS:**
> Execute this plan phase by phase. Run the `VERIFY` task at the end of each phase, then **STOP AND
> WAIT** for explicit user approval before starting the next phase. Do **NOT** modify application
> source code. Do **NOT** touch the live `auth/` folder. Mark any helper script as an ops artifact.
> If a phase fails its verification, do not proceed: apply the phase rollback (Section 9) and report.

### Implementation Phase 1 — Pre-flight Verification & Backup (High Risk)

- GOAL-001: Establish ground truth about the host, close the four open verification questions with
  evidence, and produce a verified, restorable backup before anything in production is mutated.

| Task     | Description                                                                                                                                                                                                                                                                                                                                                                                                                                                              | Ref ID          | AC Ref | Dep     | Files | Completed | Date |
| -------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------- | ------ | ------- | ----- | --------- | ---- |
| TASK-001 | Confirm host identity: determine whether the machine executing this plan **is** the production server `192.168.1.10` and whether it is the same PC that runs AuliaPos v2.1. Record hostname, all IPv4 addresses, and the production code folder path plus its actual branch/HEAD. This gates TASK-002/TASK-003.                                                                                                                                                              | REQ-001         | AC-001 | -       | 0     |           |      |
| TASK-002 | **CONDITIONAL (only if TASK-001 proves the server is a different host, or Node/Gateway are absent there):** on the server install Node v22.x, `git clone`/checkout `WA-Gateway` on `master`, run `npm install`, and confirm a working `better-sqlite3` native build (`node -e "require('better-sqlite3')"`).                                                                                                                                | REQ-001, REQ-006 | AC-002 | TASK-001 | 0     |           |      |
| TASK-003 | **CONDITIONAL (only with TASK-002):** Windows Firewall review — confirm no inbound rule exposes TCP 3000, and that the existing 80/443 (Apache) and 3306 (MySQL) rules are unchanged and reachable.                                                                                                                                                                                                                                                                        | REQ-002, SEC-003 | AC-003 | TASK-002 | 0     |           |      |
| TASK-004 | Decide and record the role of the **second PC** currently in use: decommission / repurpose / keep offline as fallback. This MUST become a decision record, not an assumption. If undecided, record it as an explicit open question (Section 7).                                                                                                                                                                                                                          | REQ-001         | AC-004 | TASK-001 | 0     |           |      |
| TASK-005 | Capture the current **WhatsApp linked-device list** on the store phone and confirm at least one free slot exists for the Gateway, without disturbing the staff WhatsApp Web session. This gates pairing (TASK-023).                                                                                                                                                                                                                                                      | REQ-007         | AC-005 | TASK-001 | 0     |           |      |
| TASK-006 | Config pre-flight: verify the shared token is present on both sides and SHA256-identical (23 chars); report free space on `D:`; confirm port 3000 is free and identify owners of 80/443/3306; record whether `aulia_inboxdb` already exists; confirm migration parity (all 6 v2.1 migrations exist in v2.3, 20 total).                                                                                                                                                        | REQ-001, REQ-012, REQ-013 | AC-006 | -       | 0     |           |      |
| TASK-007 | If `aulia_inboxdb` does not exist, provision it (empty database owned by the app DB user) so the Inbox migrations in Phase 2 have a target. If it exists, record its current table/row state.                                                                                                                                                                                                                                                                             | REQ-012         | AC-007 | TASK-006 | 0     |           |      |
| TASK-008 | **High Risk.** Full backup, each item with size + timestamp: (a) AuliaPos production code folder (snapshot/tag) as the rollback substrate; (b) POS database dump; (c) Inbox database dump (if it existed at TASK-007); (d) `D:/aulia_inbox_media/`; (e) both `.env` files (AuliaPos and Gateway). Prove restorability with a checksum or a restore rehearsal, and name the rollback point (commit/tag + backup set). SEC-001. | SEC-001         | AC-008 | TASK-007 | 0     |           |      |
| TASK-009 | **VERIFY**: Confirm every Phase 1 artifact exists: written host-identity record, conditional install evidence (if applicable), second-PC decision, linked-device slot count, config pre-flight checklist, Inbox DB state, and the backup set with restore evidence. Confirm AC-001..AC-008 all satisfied.                                                                                          | -               | -      | -       | -     |           |      |
| TASK-010 | **APPROVAL**: Wait for explicit user confirmation to proceed to Phase 2.                                                                                                                                                                                                                                                                                                                                                                                                  | -               | -      | -       | -     |           |      |

### Implementation Phase 2 — Production Migrations & AuliaPos v2.3 Deploy (High Risk)

- GOAL-002: Bring the production schema to v2.3 (all 20 migrations, all 6 v2.1 present) and deploy the
  v2.3 code, with POS proven non-regressed.

| Task     | Description                                                                                                                                                                                                                                                                                                                                                                                             | Ref ID                  | AC Ref | Dep     | Files | Completed | Date |
| -------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------- | ------ | ------- | ----- | --------- | ---- |
| TASK-011 | Record the pre-migration state (migration ledger rows, key table row counts). Run the pending migrations so the applied ledger totals 20 with all 6 v2.1 migrations present. If Inbox migrations do not apply via the default call, run them for the `inbox` group explicitly. Record which command was used and the exact applied list. Migration BEFORE code (CON-004).                                    | REQ-012, CON-004        | AC-009 | TASK-008 | 0     |           |      |
| TASK-012 | Probe `messages` row-size safety: run a throwaway `ADD COLUMN` + `DROP COLUMN` on `aulia_inboxdb.messages`. If it fails with errno 1118 (`Row size too large ... 8126`), apply the documented remedy (`ALTER TABLE aulia_inboxdb.messages ROW_FORMAT=DYNAMIC, FORCE;`) and re-probe until it passes. This closes the known InnoDB row-size trap from `docs/ARCHITECTURE.md` §9.                            | REQ-012                 | AC-010 | TASK-011 | 0     |           |      |
| TASK-013 | Deploy AuliaPos v2.3 code to the production folder: bring the production checkout to the v2.3 target commit (working tree clean). Confirm the v2.1→v2.3 code diff is limited to the MySQLi platform guard around `GET_LOCK` in `TransaksiModel.php` (`Api.php` already identical; the 5th v2.1-only commit is docs-only).                                                                               | REQ-013                 | AC-011 | TASK-011 | 0     |           |      |
| TASK-014 | Macro gate: run the full suite `vendor/bin/phpunit --no-coverage` (baseline 701 tests / 2766 assertions) and require exit 0 at ≥ the pre-change count. Then POS smoke: log in, create one transaction, print one receipt.                                  | REQ-013                 | AC-012 | TASK-013 | 0     |           |      |
| TASK-015 | **VERIFY**: Confirm AC-009..AC-012 — migration ledger = 20 with 6 v2.1 present, row-size probe passes, production HEAD = v2.3 target with clean tree and expected diff, suite exit 0, POS smoke passed.                                                                                                                                                                                                  | -                       | -      | -       | -     |           |      |
| TASK-016 | **APPROVAL**: Wait for explicit user confirmation to proceed to Phase 3.                                                                                                                                                                                                                                                                                                                                  | -                       | -      | -       | -     |           |      |

### Implementation Phase 3 — Gateway Auto-Start on Windows (ops artifact)

- GOAL-003: The Gateway starts automatically during store hours with the correct working directory, and
  survives a reboot without LAN exposure.

| Task     | Description                                                                                                                                                                                                                                                                                                                                                                                               | Ref ID            | AC Ref | Dep     | Files | Completed | Date |
| -------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------- | ------ | ------- | ----- | --------- | ---- |
| TASK-017 | **Ops artifact** (outside the application source tree, e.g. `C:\projects\ops-aulia\golive\`): create the startup script and the install helper. The install helper registers a native Windows mechanism — a **Scheduled Task** (store-hours trigger, restart on failure) is the default because the requirement is store-hours-only; a Windows **Service** is the alternative if the task proves unreliable. Encode env: `HOST=127.0.0.1`, `PORT=3000`, `CI4_BASE_URL=http://localhost/aulia`, `CI4_GATEWAY_TOKEN=<secret>`, `SQLITE_PATH=./data/gateway.sqlite`, working directory `C:\projects\WA-Gateway`. | REQ-001, REQ-006, CON-001 | AC-013 | TASK-001 | 0     |           |      |
| TASK-018 | Verify path resolution: start the Gateway through the installed mechanism and confirm `SQLITE_PATH` resolves to `C:\projects\WA-Gateway\data\gateway.sqlite` (the SQLite file exists and is used). This is the explicit working-directory trap called out by the brief.                                                                                                                                    | REQ-006            | AC-014 | TASK-017 | 0     |           |      |
| TASK-019 | Reboot test: reboot the PC; confirm the Gateway process is running, listening on `127.0.0.1:3000` **only** (`netstat -ano` shows `127.0.0.1` and not `0.0.0.0`), and the heartbeat is green. Record the effective store-hours schedule. SEC-003.                                                                                                                                                       | REQ-002, REQ-006, SEC-003 | AC-015 | TASK-018 | 0     |           |      |
| TASK-020 | **VERIFY**: Confirm AC-013..AC-015 — ops artifact exists and is marked ops-only, `SQLITE_PATH` resolves correctly, after reboot the Gateway is up, loopback-only bound, and heartbeat green.                                                                                                                                                                                                             | -                  | -      | -       | -     |           |      |
| TASK-021 | **APPROVAL**: Wait for explicit user confirmation to proceed to Phase 4.                                                                                                                                                                                                                                                                                                                                   | -                  | -      | -       | -     |           |      |

### Implementation Phase 4 — WhatsApp Pairing on the Server PC (High Risk)

- GOAL-004: The Gateway is paired to the store WhatsApp number from the server PC, connected, and
  reporting a heartbeat to AuliaPos.

| Task     | Description                                                                                                                                                                                                                                                                                                     | Ref ID             | AC Ref | Dep             | Files | Completed | Date |
| -------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------ | ------ | --------------- | ----- | --------- | ---- |
| TASK-022 | Free a linked-device slot on the store phone (use the TASK-005 inventory) without logging out or disturbing the staff WhatsApp Web session. Record the slot count before and after pairing prep.                                                                                                                 | REQ-007            | AC-016 | TASK-005, TASK-019 | 0     |           |      |
| TASK-023 | **High Risk.** Pair the Gateway from the dashboard on the server PC (scan QR). AuliaPos MUST NOT write to or read the live `auth/` folder (CON-003). Confirm status `connected` and the correct WhatsApp number.                                                                                                 | REQ-007, CON-003   | AC-017 | TASK-022        | 0     |           |      |
| TASK-024 | Verify the heartbeat path end-to-end: the Gateway calls AuliaPos `POST /api/inbox/gateway/status` with the Bearer token; confirm a fresh status row/state is visible on the AuliaPos side. This proves `CI4_BASE_URL=http://localhost/aulia` reaches the local Apache instance.                                   | REQ-003, REQ-004   | AC-018 | TASK-023        | 0     |           |      |
| TASK-025 | **VERIFY**: Confirm AC-016..AC-018 — a slot was available, Web session intact, Gateway `connected` with correct number, `auth/` untouched by AuliaPos, heartbeat reaches AuliaPos.                                                                                                                                | -                  | -      | -               | -     |           |      |
| TASK-026 | **APPROVAL**: Wait for explicit user confirmation to proceed to Phase 5.                                                                                                                                                                                                                                        | -                  | -      | -               | -     |           |      |

### Implementation Phase 5 — Shadow Period, Inbox as Read-Only Mirror (High Risk)

- GOAL-005: Run the Gateway during real store hours while staff keep using WhatsApp Web, treat the
  Inbox as a read-only mirror, and collect measurable evidence that the pipeline is reliable before
  cutover.

| Task     | Description                                                                                                                                                                                                                                                                                                                                                                     | Ref ID            | AC Ref | Dep             | Files | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------- | ------ | --------------- | ----- | --------- | ---- |
| TASK-027 | Enable the AuliaPos `.env`: `inbox.gatewayBaseUrl='http://127.0.0.1:3000'`, `inbox.mediaStoragePath='D:/aulia_inbox_media/'`; keep `CI4_BASE_URL=http://localhost/aulia` on the Gateway and keep the LAN variants commented out. Restart Apache/PHP as needed. SEC-002 (secret handling).                                                                                         | REQ-004, REQ-005  | AC-019 | TASK-011, TASK-024 | 0     |           |      |
| TASK-028 | Run the shadow period for **N working days (default 5)**. During shadow: the Gateway runs of store hours; staff KEEP replying from WhatsApp Web; the Inbox is a **read-only mirror** — no staff reply is sent from the Inbox (CON-006). Record the start/end timestamps.                                                                                                          | REQ-008, CON-006  | AC-020 | TASK-027        | 0     |           |      |
| TASK-029 | Daily monitoring log during shadow: missing count, duplicate count, per-message staleness, and heartbeat state across business hours. Evaluate against the thresholds in Section 6 (TEST-007). Watch specifically for open issues GW-25 / C3 (decryption + `message_timestamp` drift) and ASSUMPTION-007 (`outgoing_operations`, unverified until a Wave-2 APK runs on device). | REQ-008            | AC-021 | TASK-028        | 0     |           |      |
| TASK-030 | **VERIFY**: Confirm AC-019..AC-021 — env correct; shadow ran ≥ N working days read-only; **missing = 0**, **duplicates = 0**, 95% of messages land ≤ 60s, none > 5 min; heartbeat green ≥ 99% of business hours with no red longer than 5 minutes.                                                                                                                                | -                  | -      | -               | -     |           |      |
| TASK-031 | **APPROVAL**: Wait for explicit user confirmation to proceed to Phase 6.                                                                                                                                                                                                                                                                                                        | -                  | -      | -               | -     |           |      |

### Implementation Phase 6 — Real End-to-End Proof

- GOAL-006: Prove, on the real store setup, that the four core behaviours work and that an outage does
  not lose or duplicate messages.

| Task     | Description                                                                                                                                                                       | Ref ID            | AC Ref | Dep      | Files | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------- | ------ | -------- | ----- | --------- | ---- |
| TASK-032 | Send an incoming **text** from an external phone to the store number; confirm it appears **once** in the Inbox with the correct direction and timestamp.                          | REQ-009           | AC-022 | TASK-028 | 0     |           |      |
| TASK-033 | Send an incoming **photo**; confirm it appears and is stored under `D:/aulia_inbox_media/` with a `media_local_filename`, and that opening it does not depend on the live Gateway. | REQ-005, REQ-009  | AC-023 | TASK-028 | 0     |           |      |
| TASK-034 | Send an **outgoing reply** from the Inbox (ownership rules apply); confirm the customer receives exactly **one** message.                                                        | REQ-009           | AC-024 | TASK-028 | 0     |           |      |
| TASK-035 | **Duplicate check + restart-the-PC test**: with the Gateway stopped, send several messages, restart the PC (auto-start brings the Gateway back), and confirm the backlog drains with **0 lost, 0 duplicates**. | REQ-009           | AC-025 | TASK-032, TASK-034 | 0     |           |      |
| TASK-036 | **VERIFY**: Confirm AC-022..AC-025 — text once, photo stored+served locally, outgoing exactly once, outage replay 0 lost / 0 duplicates after restart.                            | -                 | -      | -        | -     |           |      |
| TASK-037 | **APPROVAL**: Wait for explicit user confirmation to proceed to Phase 7.                                                                                                          | -                 | -      | -        | -     |           |      |

### Implementation Phase 7 — Cutover

- GOAL-007: Move staff off WhatsApp Web onto the Inbox for customer chat, with the rollback trigger and
  executor declared.

| Task     | Description                                                                                                                                                                                                                                        | Ref ID            | AC Ref | Dep      | Files | Completed | Date |
| -------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------- | ------ | -------- | ----- | --------- | ---- |
| TASK-038 | Cutover: staff stop using WhatsApp Web for customer chat and use the Inbox. Deliver the staff SOP for the new single-tool flow (this removes the human double-reply risk that existed only because Web and Inbox coexisted).                        | REQ-010           | AC-026 | TASK-037 | 0     |           |      |
| TASK-039 | Declare and record the rollback point, the rollback trigger, and the **named rollback executor/role** (Section 9). Monitor the first cutover day for missing/duplicate/staleness regressions and POS stability.                                      | REQ-010, SEC-001  | AC-027 | TASK-038 | 0     |           |      |
| TASK-040 | **VERIFY**: Confirm AC-026..AC-027 — SOP in place, Web no longer used for customer chat, rollback point/trigger/executor recorded, first-day monitoring logged.                                                                                     | -                 | -      | -        | -     |           |      |
| TASK-041 | **APPROVAL**: Wait for explicit user confirmation to proceed to Phase 8.                                                                                                                                                                            | -                 | -      | -        | -     |           |      |

### Implementation Phase 8 — Documentation Sync After Execution

- GOAL-008: Make the project documentation honest about the new topology.

| Task     | Description                                                                                                                                                                                                                                                                  | Ref ID   | AC Ref | Dep      | Files | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------- | ------ | -------- | ----- | --------- | ---- |
| TASK-042 | Update `docs/TODO-CHAT.md`: correct the stale line at `:98` that says "WA-Gateway di PM2" so it reflects the actual auto-start mechanism installed in Phase 3, and update the current status section to record the go-live.                                                     | REQ-011  | AC-028 | TASK-041 | 1     |           |      |
| TASK-043 | Update `docs/ARCHITECTURE.md` (§9 Environment & Deployment, §13 Inbox module): record the single-PC topology, loopback-only bind, store-hours auto-start, `CI4_BASE_URL=http://localhost/aulia`, `inbox.gatewayBaseUrl='http://127.0.0.1:3000'`, and `inbox.mediaStoragePath='D:/aulia_inbox_media/'`. | REQ-011  | AC-029 | TASK-042 | 1     |           |      |
| TASK-044 | **VERIFY**: Confirm AC-028..AC-029 — `:98` no longer stale, topology in `ARCHITECTURE.md` matches the running system, links are valid, markdown lint clean.                                                                                                                  | -        | -      | -        | -     |           |      |
| TASK-045 | **APPROVAL**: Final sign-off that the go-live is complete.                                                                                                                                                                                                                     | -        | -      | -        | -     |           |      |

## 3. Alternatives

- **ALT-001 (topology):** Host the Gateway 24/7 on a dedicated machine — REJECTED. Delivery still
  waits for AuliaPos, and incoming media is not stored on the Gateway (buffered only as a reference,
  downloaded on demand), so 24/7 protects nothing. See ADR-0003.
- **ALT-002 (host):** Use the Android device as the Gateway host — REJECTED. Its buffer is the JSON
  fallback (only 2/8 scenarios hand-verified on device) plus a fragile LAN/IP dependency. See ADR-0003.
- **ALT-003 (host):** Use the second store PC as the Gateway host — REJECTED. Same store-hours window,
  extra sync/IP surface, no availability gain. See ADR-0003.
- **ALT-004 (auto-start):** Install PM2 or NSSM — DEFERRED. Neither is installed; the plan uses a
  native Windows mechanism and records the exact choice as an operational decision.
- **ALT-005 (cutover):** Skip the shadow period and cut over directly — REJECTED. This is the first
  time chat is live in production; a read-only shadow is the only way to measure missing/duplicate/
  staleness under real store traffic before staff depend on it.

## 4. Dependencies

- **DEP-001**: Node v22.23.2 and npm 10.9.8 (verified on the current checkout; re-verified on the
  server host by TASK-001/TASK-002).
- **DEP-002**: WA-Gateway checkout on `master` with a working `better-sqlite3` native build at
  `node_modules/better-sqlite3/build/Release/`.
- **DEP-003**: XAMPP (Apache 2.4 + MySQL/MariaDB) running as Windows services with Automatic start.
- **DEP-004**: AuliaPos v2.3 target commit and the 20-migration set (all 6 v2.1 migrations present).
- **DEP-005**: Shared Gateway token present on both sides and SHA256-identical.
- **DEP-006**: `D:/aulia_inbox_media/` target path with sufficient free space (32.8 GB observed; no
  retention policy exists yet — open decision).
- **DEP-007**: A free WhatsApp linked-device slot on the store phone (verified by TASK-005).

## 5. Files

- **FILE-001**: `docs/adr/0003-single-pc-store-hours-gateway-topology.md` — this ADR (created with the
  plan).
- **FILE-002**: `plan/plan-infrastructure-golive-auliapos-wa-gateway-v1.0.md` — this plan.
- **FILE-003**: `docs/TODO-CHAT.md` — TASK-042 (correct stale line `:98` + status).
- **FILE-004**: `docs/ARCHITECTURE.md` — TASK-043 (topology §9/§13).
- **FILE-005**: AuliaPos production `.env` (git-ignored) — TASK-027 (`inbox.gatewayBaseUrl`,
  `inbox.mediaStoragePath`).
- **FILE-006**: Gateway `.env` (outside the AuliaPos repo) — `HOST`, `PORT`, `CI4_BASE_URL`,
  `CI4_GATEWAY_TOKEN`, `SQLITE_PATH`.
- **FILE-007 (ops artifact, outside the application source tree)**: startup script + install helper
  under e.g. `C:\projects\ops-aulia\golive\` — TASK-017. Not an application change.
- **FILE-008 (backup set, outside the repo)**: code snapshot/tag + POS DB dump + Inbox DB dump +
  `D:/aulia_inbox_media/` copy + both `.env` copies — TASK-008.

## 6. Testing

- **TEST-001 (Phase 1)**: Host/config pre-flight checklist and the backup restore evidence (AC-001,
  AC-006, AC-008).
- **TEST-002 (Phase 2)**: Migration ledger verification (20 applied, 6 v2.1 present), row-size probe,
  and the macro suite `vendor/bin/phpunit --no-coverage` exit 0 at ≥ baseline (AC-009, AC-010, AC-012).
- **TEST-003 (Phase 3)**: Auto-start after a real reboot, loopback-only bind, `SQLITE_PATH` resolution
  (AC-014, AC-015).
- **TEST-004 (Phase 4)**: Pairing status `connected`, heartbeat reaches AuliaPos (AC-017, AC-018).
- **TEST-005 (Phase 5 — measurable shadow thresholds):**
  - Shadow duration: **≥ N working days** (default **5**) `[OPEN — confirm in clarification]`.
  - Missing messages: **0**.
  - Duplicate messages: **0**.
  - Staleness: **95%** of messages land in the Inbox within **60 seconds**; **none** exceeds
    **5 minutes**.
  - Heartbeat: **green ≥ 99%** of business hours; **no continuous red > 5 minutes**; checked at least
    **twice per working day**.
  - Human double-reply: **0** (enforced by the read-only shadow rule, CON-006).
- **TEST-006 (Phase 6)**: Real E2E — incoming text, incoming photo, outgoing reply, duplicate check,
  restart-the-PC outage replay (AC-022..AC-025).
- **TEST-007 (macro gate)**: The full PHPUnit suite must be green (exit 0) before the plan is declared
  complete, with no suppressions, skipped tests, or deleted assertions.

## 7. Risks & Assumptions

- **ASSUMPTION-001**: There is no formal Technical Spec for this deployment; the user brief is treated
  as the authoritative specification. This MUST be reconfirmed at TASK-010.
- **ASSUMPTION-002**: The executing machine may or may not be the production server `192.168.1.10`.
  This is **verified, not assumed**, by TASK-001; conditional install tasks (TASK-002/TASK-003) cover
  the "different host" branch.
- **ASSUMPTION-003**: The role of the second store PC (decommission / repurpose / fallback) is
  undecided; TASK-004 records the decision or leaves it as an explicit open question.
- **ASSUMPTION-004**: A free WhatsApp linked-device slot exists; verified by TASK-005 before pairing.
- **ASSUMPTION-005**: `aulia_inboxdb` may not exist on the server; verified/provisioned by
  TASK-006/TASK-007.
- **RISK-001 (High)**: Production migrations could fail (e.g. InnoDB row-size errno 1118). Mitigation:
  verified backup (TASK-008) + row-size probe (TASK-012).
- **RISK-002 (High)**: A backup that has never been restored is not a real rollback. Mitigation:
  restore rehearsal/checksum at TASK-008.
- **RISK-003 (High)**: Pairing consumes a linked-device slot and can disturb the store's WhatsApp/Web
  session. Mitigation: TASK-005 slot inventory, TASK-022 prep, CON-003 (never touch `auth/`).
- **RISK-004 (High)**: During shadow, a human could double-reply (once from Web, once from the Inbox).
  Mitigation: Inbox is a strict read-only mirror (CON-006); SOP at TASK-038; this risk disappears at
  cutover.
- **RISK-005**: `D:` has only 32.8 GB free and there is **no media retention policy**. A long
  high-media period could exhaust disk. Open decision (Section 7 open items); mitigation to be
  defined by monitoring in TASK-029.
- **RISK-006**: GW-25 / C3 (decryption plus `message_timestamp` drift) is unresolved and needs a
  second test number. Mitigation: watch during shadow (TASK-029); do not expand scope here.
- **RISK-007**: ASSUMPTION-007 (`outgoing_operations`) remains unverified until a Wave-2 APK runs on
  the physical device. Recorded as a limitation; the desktop/Inbox path is what this plan exercises.
- **RISK-008**: POS and chat now share one machine — a single point of failure and a shared reboot
  schedule. Accepted by ADR-0003.
- **RISK-009**: The exact store-hours window driving the schedule is unknown. Open decision; TASK-019
  records the effective schedule actually installed.
- **RISK-010**: No supervisor (pm2/nssm) is installed today, so auto-start behaviour is new and must be
  proven by the reboot test (TASK-019).
- **RISK-011**: Node and the Gateway checkout may be absent on the server host. Covered by conditional
  TASK-002/TASK-003.

**Open operational decisions (to be resolved in the follow-up `/sdlc-clarify-reqs` session):**

1. Shadow duration (default 5 working days) and final success thresholds (Section 6, TEST-005).
2. Who monitors the heartbeat, and how often.
3. Staff SOP for WhatsApp Web + Inbox coexistence (the real risk is a human double-reply, not
   automation).
4. Media retention policy given only 32.8 GB free on `D:`.
5. The role of the second PC (decommission / repurpose / keep offline as fallback).
6. The exact store-hours window used for the auto-start schedule (and thus the accepted backlog
   window).
7. The named rollback executor/role.

## 8. Related Specifications / Further Reading

- `docs/adr/0003-single-pc-store-hours-gateway-topology.md` (topology decision, this plan's ADR)
- `docs/TODO-CHAT.md` (master status reference; stale `:98` line corrected by TASK-042)
- `docs/ARCHITECTURE.md` (§9 deployment, §12 database topology, §13 Inbox module)
- `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` (offline `append` handling and AC-001
  evidence, 30/30, 0 lost, 0 duplicates)
- `spec/spec-design-inbox-read-authorization.md` (read-only mirror is consistent with open-read rules)
- `docs/GATEWAY-REQUIREMENTS.md` (GW-08, GW-09, GW-25)
- `docs/decisions/2026-09-23-m1-wave1-deploy-dan-ac001.md` (real AC-001 evidence)
- `docs/decisions/2026-09-25-ticket04-json-fallback-android-verifikasi-nyata.md` (JSON fallback limits)
- `docs/decisions/2026-09-28-inbox-media-not-expired-and-failure-classification.md` (media failure
  contract)

## 9. Rollback / Recovery Plan

**Rollback point:** the verified Phase 1 backup set (TASK-008): the pre-deploy AuliaPos code
snapshot/tag, the POS DB dump, the Inbox DB dump, the `D:/aulia_inbox_media/` copy, and both `.env`
copies. These are the single authoritative rollback substrate.

**Rollback triggers (any one is sufficient):**

- POS becomes unusable after the v2.3 deploy (login, transaction, or print fails).
- Migration failure, or the row-size probe cannot be made to pass.
- Shadow/E2E shows any message **lost** or any **duplicate** beyond the threshold (both are 0).
- The Gateway destabilises the server PC (Apache/MySQL), or WhatsApp pairing breaks the store's
  messaging.
- Media disk exhaustion on `D:`.

**Rollback executor:** the **named** executor/role recorded at TASK-039 (owner/operator of the store
server, optionally assisted by the implementer). If unresolved, it is an open decision (Section 7).

**Per-phase rollback:**

- **Phase 1**: No production mutation. Rollback = discard the phase; backups simply remain.
- **Phase 2**: Restore the AuliaPos code folder to the pre-deploy snapshot/tag (not a code edit); if a
  migration caused harm, restore the POS/Inbox DB from the TASK-008 dumps. Inbox DB dump restoration
  also requires stopping writes (stop the Gateway) first.
- **Phase 3**: Unregister the scheduled task/service and stop the Gateway; no data change.
- **Phase 4**: Unpair the linked device from the store phone (Settings → Linked devices); the `auth/`
  folder is never touched by AuliaPos (CON-003).
- **Phase 5**: Revert the AuliaPos `.env` (`inbox.gatewayBaseUrl` / `inbox.mediaStoragePath`) and
  return staff to WhatsApp Web only; the Inbox stops receiving. No data loss because Web was still the
  source of truth during shadow.
- **Phase 6**: No rollback needed; failures here mean **do not proceed to cutover** — record the
  finding and reopen the relevant phase.
- **Phase 7**: Roll back to the shadow state (Gateway still running, Inbox read-only) by restoring the
  Phase 5 `.env` and re-instructing staff to use WhatsApp Web, then investigate before retrying.
- **Phase 8**: Documentation-only; revert the doc edits if they are wrong.

**Hard rule:** a failed verification NEVER proceeds to the next phase. Record the finding, apply the
phase rollback, and report.
