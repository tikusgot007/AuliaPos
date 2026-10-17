# Design: WAGRAB reconciler worker and Inbox alarm

- **Date**: 2026-10-17
- **Status**: draft
- **Requirements**: `docs/requirements/2026-10-17-wagrab-reconciler.md`
- **SDLC tier**: A

## 1. Summary

Create one zero-dependency Node.js ESM worker on `aulia7`. It reads a bounded UTC window from the WAGRAB SQLite database, posts candidates as one JSON array to AuliaPos, logs the outcome, and optionally sends a Telegram alert only after the POS reports inserted messages.

## 2. Current flow (verified)

    WhatsApp Web extension
      -> WAGRAB receiver POST handler
      -> C:\AuliaPosGateway\wagrab-receiver\wa_messages.db
      -> planned wagrab-reconciler.mjs
      -> POST /api/inbox/sync-missing
      -> InboxReconcile::syncMissing()
      -> ConversationModel::resolveConversationId()
      -> MessageModel::insert()

- WAGRAB receiver schema and writer: `C:\Projects\WAGRAB\receiver\receiver.js:13-55`, `:112-143`. This is the development copy. Production file parity is not yet verified.
- Production receiver location, DB path, runtime requirement, and `WAGRAB-Receiver` task: `docs/sesi/2026-10-17-pembanding-wagrab-vs-inbox.md:41-46`.
- POS route: `app/Config/Routes.php:55-58`.
- POS accepted request forms and response shape: `app/Controllers/Api/InboxReconcile.php:20-65`, `:200-204`.
- POS treats `wa_message_id` as idempotency key and skips existing IDs: `app/Controllers/Api/InboxReconcile.php:76-86`.
- Host identity was re-verified: `192.168.1.68` answers ping and NetBIOS reports `AULIA7`; ports 135, 445, 5985, 8645 reachable from dev. Node executable, DB readability, and remote task inventory are not yet verified.

## 3. Options

- **A. One scheduled Node worker, one POST batch per 3-minute run**: minimal code and service surface; POS keeps definitive idempotency. A repeatedly queried overlap is safe because CI4 ignores existing `wa_message_id`.
- **B. Local "sent" state table/file**: reduces duplicate POSTs but introduces a second consistency state that can lose messages if marked before a failed request.

**Recommendation:** A, because endpoint idempotency already exists and avoiding local delivery state prevents a new data-loss failure mode.

## 4. Planned changes

| File | Change | Reason |
|---|---|---|
| `C:\AuliaPosGateway\scripts\wagrab-reconciler.mjs` | New ESM worker with config loading, read-only SQLite query, `--dry-run`, POST, structured logs, optional Telegram alert, strict response/error handling. | Run reconciliation independently from WAGRAB receiver. |
| `C:\AuliaPosGateway\config\wagrab-reconciler.json.example` | Non-secret configuration template. | Documents keys without committing or printing real tokens. |
| `C:\AuliaPosGateway\config\wagrab-reconciler.json` | Local, non-repo secret config created manually by owner; task-readable by SYSTEM. | Stores internal and optional Telegram credentials outside source/repo. |
| Windows Task Scheduler: `WAGRAB-Reconciler` | New SYSTEM task, once + 3-minute repetition without duration, `IgnoreNew`, zero execution limit. | Runs headlessly across logouts/reboots. |
| `docs/CHANGELOG.md` | Add business-facing auto-heal behavior after verified implementation. | Required business-rule record. |
| `docs/sesi/YYYY-MM-DD-...md`, `docs/sesi/README.md` | Checkpoint after verification/deployment. | Required session record. |

### Worker behavior

1. Resolve config path adjacent to script: `..\config\wagrab-reconciler.json`; allow `WAGRAB_RECONCILER_CONFIG` override for manual tests.
2. Require `INTERNAL_SYNC_TOKEN`; default API URL is `http://192.168.1.10/api/inbox/sync-missing`. Reject malformed config without printing values.
3. Open `wa_messages.db` with `DatabaseSync` read-only if Node supports `readOnly: true`; otherwise execute only the fixed parameterless SELECT. Never create tables, WAL checkpoints, or write state.
4. Query:

   ```sql
   SELECT wa_message_id, message_timestamp, message_type, chat_label,
          text, caption, file_name, phone_raw, chat_jid, sender_jid
   FROM messages
   WHERE direction = 'in'
     AND message_type NOT IN ('album', 'revoked', 'ciphertext')
     AND datetime(message_timestamp) BETWEEN datetime('now', '-30 minutes')
                                         AND datetime('now', '-2 minutes')
   ORDER BY message_timestamp ASC
   ```

5. `--dry-run`: log candidate count/senders; no HTTP request and no Telegram request.
6. Normal run: POST candidate array using `Content-Type: application/json` and `X-Internal-Token`; timeout using `AbortSignal.timeout()`; require 2xx, valid JSON, `status === 'ok'`, and non-negative integer counters.
7. `inserted > 0`: log only masked sender values (last four digits), then optional Telegram `sendMessage` with `parse_mode=Markdown`. Empty Telegram config bypasses alert. Alert failure returns non-zero after POS success so Task Scheduler exposes degraded notification delivery; it never retries the POS write separately because next run is idempotent.
8. No candidates: log count and exit zero.

## 5. Impact

- **Database / migrations**: no schema or data writes on WAGRAB DB. AuliaPos may insert messages only through existing endpoint.
- **Routes / API / response formats**: no POS route change. Worker consumes existing endpoint contract. Not breaking.
- **Gateway contract**: new WAGRAB-to-POS caller. Endpoint implementation confirmed locally; production origin/token matching not yet verified.
- **Existing data**: overlapping 28-minute effective window intentionally reposts candidates. CI4 `wa_message_id` idempotency handles duplicates.
- **Security / validation at trust boundaries**: config parsed and required keys type-checked; token never logged; HTTP response strictly checked; Telegram is opt-in; endpoint remains LAN internal.
- **Transactions / concurrency / rollback behavior**: task `IgnoreNew` prevents parallel worker processes. SQLite is read-only. No rollback needed; POS endpoint owns its transaction/data integrity.
- **Operational behavior**: failed task becomes visible through LastTaskResult and local log. No auto-restart of Evolution or WhatsApp session.

## 6. Test plan

| Acceptance criterion | Test | Type |
|---|---|---|
| AC-1 | `node wagrab-reconciler.mjs --self-test` validates query/filter/window construction with temporary SQLite fixture | executable self-test |
| AC-2 | Manual local fake HTTP server / real endpoint approved smoke run with one known candidate | integration |
| AC-3 | Self-test mocked fetch response `inserted=1`; manual output checks masked sender/log path | executable + manual |
| AC-4 | Self-test runs with empty Telegram values and asserts no Telegram call | executable |
| AC-5 | Self-test invalid config, HTTP failure, malformed response paths; no token output | executable |
| AC-6 | `schtasks /Query /TN WAGRAB-Reconciler /V /FO LIST` checks SYSTEM, trigger, action, result | deployment verification |
| AC-7 | `node ... --dry-run` against aulia7 DB verifies count and no outbound call | manual |
| AC-8 | Normal run plus task `schtasks /Run`, fresh query status/output log | manual deployment verification |

## 7. Risks and mitigations

- SQLite writer concurrent with reader -> use read-only connection, fixed SELECT, catch `SQLITE_BUSY`, exit non-zero; task retries at next interval.
- UTC/local time confusion -> SQLite `datetime()` compares ISO-8601 UTC values against SQLite `now` UTC; do not transform timestamps in JavaScript.
- Scheduled task silently stops after default 10 minutes -> register task XML without `Repetition/Duration`, then observe at least three executions past minute 10.
- Task SYSTEM cannot read config/Node -> verify Node absolute path and file ACL before registration; task query alone is not proof.
- Telegram outage after POS success -> log and fail task; next run safe due endpoint idempotency.
- Large burst payload -> bounded 28-minute window; not yet verified whether endpoint/API has payload cap. Worker will later add a documented batch size only if real volume or endpoint limit requires it.

## 8. Not yet verified

- Remote `aulia7` access execution credentials are not available in this session; only host/port identity was probed.
- Production Node path/version and `node:sqlite` availability under SYSTEM.
- Production WAGRAB SQLite schema/file location/read permissions and WAL behavior.
- Existing `C:\AuliaPosGateway\scripts`/`config` repository status and any current reconciler/task.
- Which local log directory and ACL already exist for SYSTEM.
- CI4 production token source and whether its configured value can be distributed manually to the approved local config file.
- Telegram target/token values. They will not be requested or handled in chat.

## 9. Approval (Gate 2)

- [ ] Approved by: <name>, date: <YYYY-MM-DD>
