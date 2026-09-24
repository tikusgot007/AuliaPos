# M1 Wave 2 — Phase 5 Deploy Record (TASK-022) and Measurement Preconditions (2026-09-24)

## 1. Status and Boundaries

TASK-022 was executed in this session, in the order the approved plan requires: the WA-Gateway live deploy first (steps a–e), then the AuliaPos sub-step (f) inside one change window.

The AuliaPos sub-step stopped after its fourth ordered action. The cashier-UI smoke test (fifth action) and TASK-023 were **not** started: both need the owner in the loop (a human at the browser plus a target WhatsApp number for the smoke test, and an explicit in-the-moment approval for TASK-023 because it slows or pauses the Gateway that serves real customers).

Nothing was pushed to any remote. The plan front matter stays `status: 'Planned'` until TASK-024. TASK-023 has not been started, so GW-09 stays open.

| Item | Verified state |
| --- | --- |
| Phase 5 scope | TASK-022 only (TASK-022 a–e complete; TASK-022 f actions 1–4 complete, action 5 pending owner) |
| WA-Gateway live folder | Deployed: `master` moved `21a4cb6` → `4010cc1`, process online |
| AuliaPos live folder | Deployed: `v2.3` moved `f2b4f2c` → `7ac1487`, suite green |
| Real database `aulia_inboxdb` | Migrated (`gateway_operation_id` + UNIQUE index present), backup taken first |
| Test database `aulia_inboxdb_test` | Re-synced from the real schema (no drift) |
| TASK-023 real measurement | Not started; needs explicit owner approval |
| Push | None |

## 2. TASK-022 (a)–(e) — WA-Gateway Live Deploy

### 2.1 State before and after

| Item | Before | After |
| --- | --- | --- |
| Working folder | `C:\projects\WA-Gateway` | unchanged |
| `git status --short` | empty (clean) | empty (clean) |
| Branch | `master` | `master` |
| HEAD | `21a4cb663dada19a16c82d8b3faa79849cadb2ef` | `4010cc11e3dfe409df0fcd0999288afcf0dfbf05` |
| Merge step | — | `git merge --ff-only feature/m1-wave2-outgoing-idempotency` → `Updating 21a4cb6..4010cc1`, `Fast-forward` |
| PM2 status | `online`, uptime 24h, restarts 2 | `online`, restarts 3, uptime 14s (measured 19:08 local) |
| PM2 `script path` | `C:\projects\WA-Gateway\src\app\index.js` | unchanged |
| PM2 `exec cwd` | `C:\projects\WA-Gateway` | unchanged |

Deployed content: 15 files, `+3264 / −54` (7 source files, 8 test scripts). `package.json` and `package-lock.json` are **unchanged**, so no `npm ci` was required (CON-008). `auth/` was not touched: `git diff --name-only 21a4cb6..feature/m1-wave2-outgoing-idempotency | grep '^auth/'` returned nothing.

### 2.2 Live start-up evidence

Log lines below are excerpts from `C:\projects\WA-Gateway\logs\gateway.log`; timestamps in that file are UTC, so `12:08:08Z` is `19:08:08` local (UTC+7). The new process is PID `16476`.

- `[MIGRASI] kolom dead_lettered_at ditambahkan ke incoming_queue (database SQLite lama).`
- `"pendingSaatStartup":0` — `SQLite incoming buffer siap`
- `"inFlightSaatStartup":0` — `SQLite outgoing operations siap`
- `Gateway starting`, `HTTP API + Dashboard berjalan di http://127.0.0.1:3000`
- `[DELIVERY] worker pengiriman pesan masuk dimulai`, `[HEARTBEAT] worker heartbeat status dimulai`
- `Status koneksi berubah menjadi: connected` (number `62881082323928`)

| Acceptance criterion on real data | Evidence | Reading |
| --- | --- | --- |
| AC-031 (REQ-031) | no `operasi in_flight basi` line; `inFlightSaatStartup: 0` | REQ-031 logs at error level only when the count is greater than zero, so an empty set is the correct evidence |
| AC-038 (REQ-038) | no `[DELIVERY] incoming queue memiliki event dead-letter` line; `incoming_queue` rows with `status='dead'` = 0 | Same rule: the line exists only when the count is greater than zero |
| AC-032 / AC-043 (REQ-032) | `pruneTerminal()` ran during start-up without error; no `[CRITICAL]` `abandoned` line | Zero terminal rows existed at deploy time (the table was created by this deploy) |
| No critical alert raised | no `[CRITICAL]` line in the start-up window | Consistent with the two zero counts above |

### 2.3 Live SQLite state after the deploy

The new code created its table and column in the live database, which is the intended additive behaviour of TASK-002/TASK-011 (CON-006). Measured read-only via `better-sqlite3` on `C:\projects\WA-Gateway\data\gateway.sqlite`:

| Query | Result |
| --- | --- |
| Tables | `incoming_queue`, `sqlite_sequence`, `outgoing_operations` |
| `SELECT COUNT(*) FROM outgoing_operations` | 0 |
| `pragma_table_info('incoming_queue')` contains `dead_lettered_at` | yes (1 row) |
| `incoming_queue` rows with `status='dead'` | 0 |

Pre-deploy safety net: `C:\xampp\backups\wa-gateway-sqlite-pre-m1w2-20260924.sqlite` (122 880 bytes), produced with the SQLite online-backup API (`better-sqlite3` `db.backup()`) while the **old** process was still running, so the file is a consistent snapshot.

## 3. TASK-022 (f) — AuliaPos Sub-step, Actions 1–4

### 3.1 Action 1 — Backup of the real database

| Item | Value |
| --- | --- |
| Command | `mysqldump -u root --single-transaction --routines --triggers --events --default-character-set=utf8mb4 aulia_inboxdb` |
| File | `C:\xampp\backups\aulia_inboxdb-pre-m1w2-20260924.sql` |
| Size | 36 584 bytes |
| SHA-256 | `71fe26bb841a28dfe505a1ff0aa6eaa214a53b30a35637d6379d74bb5284b862` |
| Content | 4 `INSERT INTO` statements |

The dump was **not** trusted merely because it existed: it was imported into a scratch database (`aulia_inboxdb_bakverify`), compared row-for-row against the live database, and the scratch database was then dropped.

| Table | Restored from the dump | Live database |
| --- | --- | --- |
| `messages` | 91 | 91 |
| `conversations` | 4 | 4 |
| `messages` where `direction='outgoing'` | 2 | 2 |

### 3.2 Action 2 — Migration of the real database

Why plain `php spark migrate` is the correct command here (F-02 mechanics — established by reading the framework and by measurement, not by assumption):

- `CodeIgniter\Database\Migration::__construct()` resolves its connection through `Database::forge($this->DBGroup)`, and this migration declares `protected $DBGroup = 'inbox'`.
- `MigrationRunner::migrate()` line 986 reads `$group = $instance->getDBGroup() ?? $this->group;` — a per-migration group wins over the runner's default group.
- Decisive measurement: `aulia_kasirdb` (the `default` group) has **no** `messages` table at all, yet earlier inbox migrations are visibly applied inside `aulia_inboxdb` (for example `is_internal`, added by `2026-09-22-000001`). So this is how every previous inbox migration reached the real database.
- Migration history rows continue to live in the `default` group's `migrations` table in `aulia_kasirdb`; this migration is recorded as batch `4`.

Exactly one migration was pending (`php spark migrate:status`), so the command could not sweep unrelated schema changes:

```text
Running all new migrations...
  Running: (App) 2026-09-24-000001_App\Database\Migrations\AddGatewayOperationIdToMessages
Migrations complete.
```

Proof taken from the real database afterwards (verbatim):

```text
Field                 Type         Null  Key  Default  Extra
gateway_operation_id  varchar(64)  YES   UNI  NULL
```

```text
messages  0  uniq_messages_gateway_operation_id  1  gateway_operation_id  A  2  NULL NULL YES BTREE
```

`SHOW FULL COLUMNS` places `gateway_operation_id` immediately after `send_status`, as the migration declares, and no other column changed.

### 3.3 Action 3 — Re-sync of the test database

`mysqldump -u root --no-data --routines --triggers aulia_inboxdb | mysql -u root aulia_inboxdb_test` — the procedure `docs/ARCHITECTURE.md` §11 prescribes. Result: `aulia_inboxdb_test.messages` now carries the same column and the same UNIQUE index, so the F-02 drift the clarification report warned about is closed for this change.

### 3.4 Action 4 — Merge and deploy of the AuliaPos code

| Item | Value |
| --- | --- |
| Live folder | `C:\xampp\htdocs\aulia` (the folder this XAMPP serves) |
| Branch before | `feature/m1-wave2-outgoing-idempotency` @ `7ac1487` |
| Command | `git switch v2.3 && git merge --ff-only feature/m1-wave2-outgoing-idempotency` |
| Result | `Updating f2b4f2c..7ac1487`, `Fast-forward` — 13 files, `+1952 / −41` |
| Branch after | `v2.3` @ `7ac1487`, `git status --short` empty |
| Suite after deploy | `OK (349 tests, 1209 assertions)` — 0 failures, 0 errors, 0 skips, 19.8 s |

Two facts worth stating plainly:

1. The fast-forward **changed no file content on disk**. The working copy was already at `7ac1487` on the feature branch and `v2.3` was its ancestor, so this step moved a branch pointer; the tree content is identical before and after. P-24 explains why that matters.
2. RISK-002 is now satisfied on the AuliaPos side: M1 Wave 2 is merged into `v2.3`, so M3 Fase 1e may start its plan/code work on top of the merged result.

### 3.5 Action 5 — Cashier-UI smoke test (PENDING, owner)

Not executed in this session. It needs a person at the browser (the reply form is what generates and reuses `operation_id`) and a target WhatsApp number that is safe to message. It is recorded here as pending so it cannot be mistaken for done. When it runs, the verification query is:

```sql
SELECT id, conversation_id, message_type, send_status, gateway_operation_id, message_timestamp
FROM messages
WHERE direction = 'outgoing'
ORDER BY id DESC
LIMIT 2;
```

Expected: for each new row, `send_status = 'sent'` and `gateway_operation_id` is **not** `NULL`.

## 4. Rollback Points

| Scope | Documented rollback |
| --- | --- |
| WA-Gateway live deploy | `git -C C:\projects\WA-Gateway reset --hard 21a4cb6`, then `pm2 restart wa-gateway`, then confirm `online` and an unchanged `script path` (plan §9). The snapshot in §2.3 is the extra safety net; the documented rollback does not touch `data/gateway.sqlite`. |
| `aulia_inboxdb` migration | `php spark migrate:rollback` — `down()` drops the UNIQUE index first and then the column, and every pre-existing value is `NULL`, so nothing is lost. Restoring §3.1's dump is the heavier alternative, and both can be combined. |
| AuliaPos code deploy | `git revert` of the Phase 4 commits, per plan §9 (Phase 4). `reset --hard` is deliberately **not** used on this folder because it is shared with the M3 work. Because the fast-forward changed no file content (§3.4, note 1), reverting the Phase 4 commits is what actually removes the new behaviour. |
| Gateway `outgoing_operations` / `dead_lettered_at` | Additive only (CON-006). Old code ignores both, so a code rollback needs no schema rollback and no data reconciliation. |

## 5. Boundary Notes and Declared Deviations

### P-24 — The Phase 4 code was live for about 40 minutes while the column was missing

The folder Apache serves has been on branch `feature/m1-wave2-outgoing-idempotency` since TASK-015, and both send paths write `gateway_operation_id` unconditionally (`Inbox.php:2009` for text, `Inbox.php:921` for media).

The real database received the column only at 19:08 local, while those write paths landed at 18:28 local (`5ce8efb`, `f56446b`; `0c53e1c` at 18:38, `ef98533` at 18:42). With `DBDebug = true` on the `inbox` group, any cashier send inside that window would have failed loudly with an HTTP 500 rather than silently.

Measured outcome: the newest `messages` row is `message_timestamp = 2026-09-24 16:33:06`, i.e. **before** the code landed, so no cashier send occurred inside the window and no live failure happened.

This hazard is inherited from the Phase 4 decision to defer F-01 — it was not introduced in this session. It is recorded for two reasons: it is exactly why action 2 must precede action 4 in the plan's ordering, and it is why the migration was treated as urgent rather than cosmetic.

### P-25 — `pm2 restart` was a normal process restart

`pm2 restart wa-gateway` ran per TASK-022(d): `restarts` went 2 → 3, uptime reset, `script path` and `exec cwd` stayed on the live folder, and the log shows the connection re-established (`connected`, number `62881082323928`). `auth/` was not touched, so the WhatsApp session survived the restart.

### P-26 — The live deploy performed an additive SQLite migration

Stated explicitly because spec §9 lists new tables and columns under "Ask first". The deploy caused `incoming_queue.dead_lettered_at` to be added and `outgoing_operations` to be created inside `C:\projects\WA-Gateway\data\gateway.sqlite`.
Both are the deliberate additive shape of TASK-002/TASK-011 (CON-006) and were authorised as part of this deploy; a consistent pre-deploy snapshot (§2.3) was taken first, so the change was neither silent nor unrecoverable.

### P-27 — The real-database migration ran with owner-authorised scope

CON-014 requires the owner's approval before the real database is migrated. That approval exists in two recorded places:

- the owner's F-01 decision of 2026-09-24 (option (i), which defines this work as a listed sub-step of TASK-022 — `docs/decisions/2026-09-24-m1-wave2-phase4-aulias-pos-caller.md` §4 and §7);
- this session's explicit instruction to execute TASK-022 including sub-step (f) with a backup first.

The backup (§3.1) and its restore check both completed **before** the `ALTER TABLE` ran.

## 6. Evidence Limits

- AC-026(b) stays **`stub-only`**, and the `422` branch of `incomingDelivery` stays **`[Assumed / Out of Scope]`** (A-8b). Nothing in this record claims either as production evidence (K-10).
- ASSUMPTION-009 — the narrow window where WhatsApp has received the message but the operation row was not yet marked `sent` — is **not** claimed closed. The full closure is GW-21 (explicit delivery status) in M2. Nothing measured here narrows it beyond what TASK-007/TASK-008 already do.
- ASSUMPTION-007 (JSON fallback parity) is **not** exercised: the live Gateway runs on SQLite, so the Android fallback path remains untested.
- D-13/A-5: the idempotency guarantee is bounded by `OUTGOING_OPERATION_TTL_MS` (24 hours). Once a terminal row is pruned, the same `operation_id` counts as a new operation.
- K-04: `DELIVERY_MAX_ATTEMPTS` and `DELIVERY_DEAD_AFTER_MS` still need calibration after a real outage. The live start-up reported zero `dead` rows, so this deploy produced **no** calibration data; the item stays open.
- The start-up counts are evidence about an **empty** set. They do not show what the code does when the sets are non-empty — that evidence lives in `test/simulate-outgoing-recovery.js` and `test/simulate-dead-letter.js`.
- This document makes **no** AC-027 or AC-042 claim. Both depend on TASK-023.

## 7. What Remains

- **TASK-022 action (f)5** — cashier-UI smoke test (one text send, one media send). Pending the owner; the expected verification query is in §3.5.
- **TASK-023** — the real AC-027/AC-042 measurement, at least three rounds. It needs an explicit in-the-moment approval because it slows or pauses the live Gateway, and a written rollback point must exist before it starts.
  - Procedure gotcha K-13 applies: the first attempt ends as an AuliaPos 502 (cURL timeout), and the "uncertain result" UI state only appears on a **resend inside the lease** (`409 SEND_IN_PROGRESS`).
- **TASK-024** — only after the owner declares Wave 2 finished: plan front matter `Planned` → `Completed`, badge swap, a `memory-manager` offer, then `/sdlc-code-review`.
- **RISK-002 follow-up** — satisfied for AuliaPos: `v2.3` now contains M1 Wave 2, so M3 Fase 1e may begin its plan/code work on this basis.
