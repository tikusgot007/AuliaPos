# M1 Wave 2 — Phase 5 Deploy Record (TASK-022) and Measurement Preconditions (2026-09-24)

## 1. Status and Boundaries

TASK-022 was executed in this session, in the order the approved plan requires: the WA-Gateway live deploy first (steps a–e), then the AuliaPos sub-step (f) inside one change window.

The AuliaPos sub-step stopped after its fourth ordered action; the cashier-UI smoke test (fifth action) and TASK-023 were then executed with the owner in the loop (at the browser and, for the measurement, supplying the test number and driving the cashier UI). Both are recorded in §3.5 / §8.6 and §8.7.

Nothing was pushed to any remote. The plan front matter stays `status: 'Planned'` until TASK-024. TASK-023 was executed (rounds 1–4, §8.7), so GW-09's two acceptance criteria are now measured; the only remaining gate is TASK-024.

| Item | Verified state |
| --- | --- |
| Phase 5 scope | TASK-022 only (TASK-022 a–e complete; TASK-022 f actions 1–4 complete, action 5 pending owner) |
| WA-Gateway live folder | Deployed: `master` moved `21a4cb6` → `4010cc1`, process online |
| AuliaPos live folder | Deployed: `v2.3` moved `f2b4f2c` → `7ac1487`, suite green |
| Real database `aulia_inboxdb` | Migrated (`gateway_operation_id` + UNIQUE index present), backup taken first |
| Test database `aulia_inboxdb_test` | Re-synced from the real schema (no drift) |
| TASK-023 real measurement | Executed: rounds 1–4 (§8.7); AC-027 twice, AC-042 twice, no duplicate delivery |
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
- AC-027 and AC-042 are **not** claimed by this section; the measured evidence for both lives in §8.7.

## 7. What Remains

- **TASK-022 action (f)5** — done; evidence in §8.6.
- **TASK-023** — done; rounds 1–4, their evidence, and the honest ASSUMPTION-009 statement are in §8.7.
  - K-13 held as written: the first attempt of every round ended as an AuliaPos failure, and the "uncertain result" state appeared only on the retry (`409 SEND_IN_PROGRESS`).
- **TASK-024** — only after the owner declares Wave 2 finished: plan front matter `Planned` → `Completed`, badge swap, a `memory-manager` offer, then `/sdlc-code-review`.
- **RISK-002 follow-up** — satisfied for AuliaPos: `v2.3` now contains M1 Wave 2, so M3 Fase 1e may begin its plan/code work on this basis.

## 8. TASK-023 Pre-Flight — Written Rollback Point and Procedure

Recorded **before** any measurement runs, because the plan requires a written rollback point first and TASK-023 needs its own explicit approval.

### 8.1 State measured at pre-flight

| Item | Value |
| --- | --- |
| Gateway live | `master` @ `4010cc1`, PM2 `online`, `script path` = live folder |
| AuliaPos live | `v2.3` @ `66a0d8a`, working copy clean |
| `.env` backup | `C:\xampp\backups\aulia-env-pre-m1w2-phase5.bak` (601 bytes) |
| `.env` value before | `inbox.gatewayBaseUrl = 'http://localhost:3000'` |
| Live database | `messages.gateway_operation_id` present; 91 messages / 4 conversations |
| Measurement tool | `build/delay-proxy.js` (gitignored, throwaway) |
| Tool self-test | `node build/scratch-delay-proxy-check.js` → **9 PASS, 0 FAIL** on a local echo target (both modes) |
| Tool transparency check | `node build/scratch-proxy-live-pass.js` → proxy in front of the live Gateway returns the same response as a direct call |
| Code changes required for TASK-023 | **None** in either repository |

### 8.2 Rollback point (run this if anything goes wrong)

1. `cp C:\xampp\backups\aulia-env-pre-m1w2-phase5.bak C:\xampp\htdocs\aulia\.env` — restores `gatewayBaseUrl` to `http://localhost:3000`.
2. Stop the proxy process.
3. Nothing else to undo: no code change, no schema change, no `pm2` action, no file touched inside `C:\projects\WA-Gateway`. AuliaPos does not need a restart because `.env` is read per request.

### 8.3 Why the recommended technique was replaced

- **Port blocking cannot work here.** The clarification report's option (a) assumed a firewall rule would make connections hang. The Gateway listens on `127.0.0.1:3000`, and Windows does not filter loopback traffic with the host firewall, so a block rule is a no-op on this machine.
- **A pure response delay is not enough for AC-027.** The Gateway resolves an operation to `sent` in well under a second. Holding only the response makes the retry hit a terminal row, so the Gateway answers `200 replayed:true` — the AC-042 path — instead of `409 SEND_IN_PROGRESS`.
- **What AC-027 actually needs** is a row that is still `in_flight` when the retry arrives. `build/delay-proxy.js` mode `hold` creates exactly that: it withholds the first request *and* the retry, and the operator releases the first request, then the retry, while the row is genuinely in flight.
- **Blast radius is bounded by `chat_id`.** The proxy slows only requests whose JSON `chat_id` equals the agreed test target. Every other request, and every non-send path, is forwarded untouched, so live customer traffic never enters the slow path.

### 8.4 Procedure planned per round

| Round | Proxy mode | Who acts | Steps | What it proves |
| --- | --- | --- | --- | --- |
| S (smoke) | `pass` | owner | From the reply form, send one text and then one media to the test target | The real column accepts writes: both new rows carry `gateway_operation_id` and `send_status='sent'` |
| 1 | `delay-response` | owner | Send text; AuliaPos cURL times out at 10 s; wait past the 35 s lease; send again with the same key | AC-042 — `200 replayed:true`, no second WhatsApp delivery, exactly one `messages` row |
| 2 | `hold` | owner + operator | Send media; AuliaPos cURL times out at 30 s; send again; the operator releases request 1, waits until the operation row shows `in_flight` in the live SQLite, then releases request 2 | AC-027 — `409 SEND_IN_PROGRESS`, `sendMessage` not called a second time, at most one delivery, UI shows the uncertain state |
| 3 | `hold` | owner + operator | Repeat round 2 with different content, so the form generates a new key | AC-027, second observation |

Rules that apply to every round:

- Record per round: WhatsApp messages actually received, `messages` rows created for that key, the HTTP status / `error_code` the UI received, and the Gateway `outgoing_operations` row state.
- AC-040: the `/send` and `/send-media` payloads captured by the proxy must keep their pre-change shape, with `operation_id` only ever appended.
- The `messages` table must grow by the number of messages actually delivered, never by the number of attempts.
- AC-026(b) stays **`stub-only`** and the `422` branch stays **`[Assumed / Out of Scope]`**; neither may be claimed from these rounds (K-10).
- ASSUMPTION-009 stays **open** and must be restated in the results.
- K-13 applies: the first attempt of every round ends as an AuliaPos failure (client timeout), so the "uncertain result" state can only be observed on the retry.

### 8.5 Safety limits

- One text per AC-042 round and one media per AC-027 round; no bulk sending, and nothing sent to a customer conversation.
- The proxy is stopped and `.env` restored immediately after the last round, and immediately on any unexpected result.
- If three rounds fail to reproduce AC-027, do not claim it: record the finding, reopen Phase 2, and ask the owner (plan contingency 2).

### 8.6 Round S (smoke test) — executed and passed

| Parameter | Value |
| --- | --- |
| Test `chat_id` | `6281913500707@s.whatsapp.net` (conversation `25803`, created 19:21:54 local) |
| Proxy | not used: `.env` still pointed straight at `http://localhost:3000`, so nothing sat in the send path |

Send sequence and result (local time, UTC+7):

| # | Sent from | `message_type` | `send_status` | `gateway_operation_id` | Result |
| --- | --- | --- | --- | --- | --- |
| 1 | "Mulai Percakapan" form | text | `sent` | `NULL` | Expected: that form carries no key, so the column stays `NULL` |
| 2 | Reply form (`#formBalas`) | text | `sent` | `90dfa367-b2bb-4fb5-98ee-7145f68a244d` | Key stored, exactly one row |
| 3 | Reply form media | image | `sent` | `a2678c35-3f87-4c2a-af54-14ef306ae85a` | Key stored, exactly one row, caption `UJI-W2-S1 MEDIA` |

Cross-checks:

- Gateway `outgoing_operations` holds exactly **two** rows, both `state='sent'`, `attempts=1`, `last_error=NULL`, with `payload_hash` set and the same `wa_message_id` values AuliaPos stored (`3EB019F96C049E08BD64A4` for text, `3EB0A796D4C11EC4E18E78` for media). The keyless send created **no** row — that is AC-044 (no server-side key generation).
- `SELECT gateway_operation_id, COUNT(*) ... GROUP BY gateway_operation_id` → each non-`NULL` key appears exactly once, and 92 rows are `NULL` (many `NULL`s accepted by the UNIQUE index — the AC-041 schema half).
- `messages` grew 91 → 94 and `incoming` stayed 89: exactly the three messages that were really delivered, so nothing was echoed back as a new incoming message.
- The Gateway log shows exactly three `[SEND]` deliveries to the test JID (one per attempt) and `[SEND-OPERATION] kirim berhasil, operasi sent`. No duplicate send, and no error-level line except a benign Baileys `failed to remove tmp file`.
- Payload shape unchanged (AC-040): the reply form posted the same `{chat_id, text|media…}` object with only `operation_id` appended, and the keyless path posted no key at all.

Conclusion: **TASK-022 action (f)5 is complete.** The migrated column accepts real writes from both send paths, the keys are stored and unique, and the Gateway-side operation state agrees with AuliaPos.

### 8.7 Rounds 1–4 — executed, with results

Target chosen by the owner: `6281913500707@s.whatsapp.net`. Tool: `build/delay-proxy.js` (gitignored, self-tested). `.env` pointed at the proxy for rounds 1–4 and was restored immediately afterwards (verified: `gatewayBaseUrl = 'http://localhost:3000'`, PM2 holds only `wa-gateway`, the direct Gateway call and the AuliaPos login page both answer `200`).

| Round | Scenario | Proxy mode | What the UI showed | WhatsApp received | AuliaPos row | Gateway operation |
| --- | --- | --- | --- | --- | --- | --- |
| S | smoke: text + media | none | normal success | 3 delivered, 3 received | `397` (no key), `398`, `399` | 2 rows `sent`, `attempts=1` |
| 1 | text, retry inside the lease | `delay-response` (12 s) | attempt 1 failed (±10 s), attempt 2 success | 1 delivered, 1 received | id `400`, key `26463694-…` | `sent`, `attempts=1`; retry logged `replay hasil tersimpan, tidak dikirim ulang` |
| 2 | media, retry inside the lease | `hold`, auto-release 1.5 s | attempt 1 failed (±30 s); attempt 2 answered in ±2 s with **"Hasil belum pasti, jangan kirim ulang dulu."** | 1 delivered, 1 received | none (by design) | `sent`, `attempts=1`, 1× `[SEND]` media, `409 SEND_IN_PROGRESS` |
| 3 | media, repeat | `hold`, auto-release 1.5 s | identical to round 2 | 1 delivered, 1 received | none (by design) | `sent`, `attempts=1`, `409 SEND_IN_PROGRESS` |
| 4 | text, retry **after** the lease | `delay-response` (12 s) | attempt 1 failed (±10 s), attempt 2 success | 1 delivered, 1 received | id `401`, key `d4a834cc-…` | `sent`, `attempts=1`; retry 47.1 s after creation → `replay hasil tersimpan, tidak dikirim ulang` |

Raw evidence (log times are UTC; local = UTC+7):

- Proxy, round 4: the `/send` response was held `holdMs: 12000` and then dropped as `client already gone`; the retry was answered with `holdMs: 0`.
- Proxy, round 3: `holding request` twice for `35824f09-…`, then `second request for operation seen, auto-releasing both`, then `released first held request`, then `/send-media` with `statusCode: 409` delivered to the still-waiting client (and the first attempt's `200` dropped because its client had already gone).
- Gateway, rounds 2 and 3: `[SEND-OPERATION] operasi masih in_flight di dalam lease -- ditolak tanpa kirim (hasil belum pasti)`.
- Gateway, rounds 1 and 4: `[SEND-OPERATION] replay hasil tersimpan, tidak dikirim ulang`.
- Sends: exactly one `[SEND]` per round window (round-1 window = 1, round-4 window = 1; media sends for rounds S/2/3 = 3 in total). No round produced a second delivery.
- AuliaPos: four rows carry a key, each appearing exactly once (`398`, `399`, `400`, `401`); the remaining rows are `NULL`.
- AC-040 after the deploy: the existing harness was re-run (`php -S 127.0.0.1:8792 build/ac040-router.php` + `php build/scratch-ac040-verify.php 8792`) → **24 PASS, 0 FAIL**, so the `/send` and `/send-media` payload shape is unchanged. TASK-023 changed no code in either repository.

Findings and honest limits:

- **AC-027 is met, twice independently.** The retry inside the lease was answered `409 SEND_IN_PROGRESS`, `sendMessage` was not called a second time (one `[SEND]` per round), `attempts` stayed `1`, and the phone received exactly one media. The UI displayed "Hasil belum pasti, jangan kirim ulang dulu.", which is REQ-041/AC-046 on the real screen.
- **AC-042 is met, twice.** A retry against a terminal `sent` operation returned the stored result with no second delivery. Round 4 also meets the literal timing requirement: the retry arrived 47.1 s after the operation row was created, i.e. past the 35 s lease.
- **Round 1's retry arrived 25.7 s after the first press**, inside the lease. It still replayed, because a terminal row is answered from the record regardless of the lease — that is REQ-022 exactly. Round 4 was added so the literal "after the lease" wording is satisfied too.
- **A message delivered through the in-lease `409` path is not recorded in AuliaPos.** In rounds 2 and 3 the media reached the customer while no `messages` row was created, so the Inbox thread does not show it.
  - That is what REQ-041 asks for (no fabricated success row), but it is a real operational consequence: the cashier is told the result is uncertain and has nothing on screen to point at. Recorded as input for the code review / a possible Wave 3 item — not a deviation from the spec.
- **ASSUMPTION-009 is NOT closed.** If the Gateway process dies after WhatsApp accepted a message but before the row is marked `sent`, a retry with the same key can still duplicate it. Nothing measured here narrows that window; the only mitigations remain the row written before the send and the start-up log. Closing it requires GW-21 in M2.
- **These rounds are synthetic, not a real network failure.** A local delay proxy produced the client-side timeout; no real network degradation occurred. The mechanism under test (client gives up, Gateway keeps working) is the same, but this is not evidence from a real outage.
- **AC-026(b) stays `stub-only` and `422` stays `[Assumed / Out of Scope]`** — neither was exercised or claimed here (K-10).
- **K-04 stays open.** No dead-letter or abandoned row was produced because no outage happened, so `DELIVERY_MAX_ATTEMPTS` / `DELIVERY_DEAD_AFTER_MS` still need a real outage to calibrate.
- **D-13/TTL**: the idempotency guarantee remains bounded by `OUTGOING_OPERATION_TTL_MS` (24 hours).
- Cosmetic detail recorded so the raw evidence matches: row `401`'s text is literally `` `UJI-W2-R1B TEKS `` — a stray leading backtick typed in the UI. It does not affect the result.

Conclusion: **TASK-023 is complete.** GW-09's two acceptance criteria (AC-027 — no duplicate delivery on a retry inside the lease, and AC-042 — replayed result after the lease) are measured on the real system with the owner operating the cashier UI. The Gateway never sent a message twice in any round.
