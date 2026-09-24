---
goal: Stop PHPUnit from wiping the real Inbox database (aulia_inboxdb)
version: 1.0
date_created: 2026-09-24
last_updated: 2026-09-24
owner: AuliaPos Inbox module
status: "Planned"
tags: ["bug-fix", "remediation", "patch", "inbox", "testing", "data-loss"]
---

# Introduction

![Status: Planned](https://img.shields.io/badge/status-Planned-blue)

Every PHPUnit run deletes all rows in the real local Inbox database `aulia_inboxdb`. The Inbox screen (`/inbox`) that used to be full of chats is now empty. `conversations` holds 2 rows with `AUTO_INCREMENT = 25801`, and one of them is test seed data (`ac-g-andi@s.whatsapp.net`, "Andi", from `OperationalInboxConversationTest::testQKolomNullTidakError`).

**Root cause (confirmed).** Test isolation covers only the `default` group:

1. `app/Config/Database.php:291-293` switches only `$defaultGroup` to `tests` when `ENVIRONMENT === 'testing'`. The `inbox` group is untouched.
2. `.env` (lines 12-17) sets `database.inbox.database = aulia_inboxdb`. CodeIgniter loads `.env` in the PHPUnit bootstrap too (`Boot::loadDotEnv`), so under test `db_connect('inbox')` is the real database.
3. `phpunit.dist.xml` has no override for the `inbox` group.
4. Seven test classes call `emptyTable()` on `conversations`, `messages`, `conversation_identities` and `conversation_handoffs` of `db_connect('inbox')` in `setUp()`: `InboxHandoffTest`, `OperationalInboxConversationTest`, `OperationalInboxScreenTest`, `InboxSoftDeleteTest`, `InboxSnoozeAlasanTest`, `InboxInternalNoteTest` (tests/session) and `ConversationHandoffsMigrationTest`, `ConversationHandoffModelTest` (tests/database). Inbox models with `$DBGroup = 'inbox'` called by the controllers under test also write to the real database.

The high `AUTO_INCREMENT` with only 2 rows is the fingerprint: `emptyTable()` is `DELETE FROM`, which keeps the counter, and bulk tests seed 500+ rows per run.

The design comment "tests live in the real `inbox` group" in `InboxHandoffTest` and `ConversationHandoffsMigrationTest` was a deliberate choice, but no one isolated the group, so the choice became a data-loss bug. `docs/ARCHITECTURE.md` §5 also claims the `tests` group protects live data; that is only true for `default`.

**Fix in one line:** under `ENVIRONMENT === 'testing'`, force the `inbox` group to a dedicated database `aulia_inboxdb_test`, and refuse to start PHPUnit at all if it would point anywhere else.

> [!CAUTION]
> Until Phase 2 is merged, **do not run the full test suite** (`vendor/bin/phpunit` or `composer test`). Each run wipes `aulia_inboxdb` again. Recovery of the already lost chat data is **out of scope** of this plan (see RISK-004).

## 1. Requirements & Constraints (Fix Constraints)

- **REQ-001**: Under `ENVIRONMENT === 'testing'`, every connection of group `inbox` MUST point to database `aulia_inboxdb_test`, regardless of `.env` or shell environment variables.
- **REQ-002**: PHPUnit MUST stop before running any test if the `inbox` group resolves to anything other than `aulia_inboxdb_test` (fail closed, not fail after damage).
- **REQ-003**: A guard test MUST assert, through the live connection (`SELECT DATABASE()`), that the `inbox` group used by tests is `aulia_inboxdb_test`.
- **REQ-004**: `aulia_inboxdb_test` MUST have the same schema as `aulia_inboxdb`, created by a documented, repeatable one-time step.
- **REQ-005**: After the fix, a full suite run MUST leave `aulia_inboxdb` unchanged (row counts and `AUTO_INCREMENT` identical before and after).
- **CON-001**: Production and development behaviour MUST NOT change: outside `testing`, the `inbox` group keeps reading `.env` exactly as today.
- **CON-002**: No change to Inbox models, controllers, migrations, routes or API contracts.
- **CON-003**: No test logic rewrite. Test files may only receive docblock/comment corrections that say "real aulia_inboxdb".
- **CON-004**: The override MUST be hard-coded in `Config\Database::__construct()` (same pattern as the existing `defaultGroup = 'tests'` line), NOT through a `phpunit.dist.xml` `<env>` entry. Reason: `.env` puts the dotted key `database.inbox.database` into `$_ENV`, and `BaseConfig` checks the dotted key first, so an env-based override is fragile (depends on key spelling and `variables_order`).
- **CON-005**: Scope is limited to the `inbox` group. The `archive` group (SQLite file in `writable/`) is not used by any test today and is not touched.

## 2. Implementation Steps

> **⚠️ EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> You MUST execute this plan phase by phase. You MUST run the specific testing/verification task at the end of each phase. After a phase is tested, you **MUST STOP AND WAIT** for the user's explicit approval before proceeding to the next phase.
>
> Until TASK-010 is done, run ONLY the single test file named in each VERIFY step (`vendor/bin/phpunit --no-coverage <file>`). Never run the whole suite before then.

### Implementation Phase 0: Prepare the test database (one-time, no code)

- **GOAL-000:** Create `aulia_inboxdb_test` with the same schema as `aulia_inboxdb`, and take a snapshot of the real database so REQ-005 can be proven.

| Task     | Description | Ref ID | Completed | Date |
| -------- | ----------- | ------ | :-------: | :--: |
| TASK-001 | Snapshot the real database before anything else: `mysqldump -u root -p aulia_inboxdb > <scratch>/aulia_inboxdb-before-fix.sql` (outside the repo, never committed). | RBCK-003 | [ ] | |
| TASK-002 | Create the test database: `mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS aulia_inboxdb_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"`. | REQ-004 | [ ] | |
| TASK-003 | Copy the schema only (no rows): `mysqldump -u root -p --no-data --routines --triggers aulia_inboxdb \| mysql -u root -p aulia_inboxdb_test`. Why not `php spark migrate`: migration history is stored in `aulia_kasirdb.migrations`, so the inbox migrations are already marked as run and would be skipped. | REQ-004 | [ ] | |
| TASK-004 | Record the baseline of the real database: `SELECT COUNT(*) FROM aulia_inboxdb.conversations` (and `messages`, `conversation_identities`, `conversation_handoffs`), plus `SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA='aulia_inboxdb'`. Save the numbers in the plan notes. | REQ-005 | [ ] | |
| TASK-005 | **VERIFY**: `SHOW TABLES FROM aulia_inboxdb_test` lists the same tables as `aulia_inboxdb`; `SHOW TRIGGERS FROM aulia_inboxdb_test` is empty (no leftover `trg_handoff_fail_insert`). | - | [ ] | |
| TASK-006 | **APPROVAL**: 🛑 Wait for explicit user confirmation to proceed to Phase 1 | - | [ ] | |

### Implementation Phase 1: Test Writing (Test-Driven Bug Fixing)

- **GOAL-001:** Write a guard test that proves the `inbox` group used by tests is the real database today (so it fails now).

| Task     | Description | Ref ID | Completed | Date |
| -------- | ----------- | ------ | :-------: | :--: |
| TASK-007 | Create `tests/database/InboxTestDatabaseIsolationTest.php` (`CIUnitTestCase`, NO `DatabaseTestTrait`, NO writes). Three assertions: (a) `config('Database')->inbox['database'] === 'aulia_inboxdb_test'`; (b) `db_connect('inbox')->query('SELECT DATABASE() AS db')->getRow()->db === 'aulia_inboxdb_test'`; (c) the live name is not `aulia_inboxdb`. Failure messages must say plainly that tests are about to touch the real Inbox database. | REQ-003 | [ ] | |
| TASK-008 | **VERIFY**: Run ONLY this file: `vendor/bin/phpunit --no-coverage tests/database/InboxTestDatabaseIsolationTest.php`. It MUST FAIL (live database is `aulia_inboxdb`). The test does not write, so running it is safe. | - | [ ] | |
| TASK-009 | **APPROVAL**: 🛑 Wait for explicit user confirmation to proceed to Phase 2 | - | [ ] | |

### Implementation Phase 2: Minimal Root Cause Remediation

- **GOAL-002:** Redirect the `inbox` group under test and make PHPUnit refuse to start if it is not redirected.

| Task     | Description | Ref ID | Completed | Date |
| -------- | ----------- | ------ | :-------: | :--: |
| TASK-010 | `app/Config/Database.php` `__construct()`: inside the existing `if (ENVIRONMENT === 'testing')` block, add `$this->inbox['database'] = 'aulia_inboxdb_test';` after the `defaultGroup` line. Extend the comment: the `inbox` group must be redirected too, because it is a real MySQL database and tests empty its tables. Hostname/username/password still come from `.env`. | REQ-001, CON-001, CON-004 | [ ] | |
| TASK-011 | Create `tests/_support/bootstrap.php`: `require` the CodeIgniter test bootstrap (`vendor/codeigniter4/framework/system/Test/bootstrap.php`), then read `config('Database')->inbox['database']`; if it is not `aulia_inboxdb_test`, write a clear message to `STDERR` ("Refusing to run tests: inbox group points to <name>, expected aulia_inboxdb_test") and `exit(1)`. No DB connection here, config check only. | REQ-002 | [ ] | |
| TASK-012 | `phpunit.dist.xml`: change `bootstrap=` to `tests/_support/bootstrap.php`. | REQ-002 | [ ] | |
| TASK-013 | **VERIFY**: Run `vendor/bin/phpunit --no-coverage tests/database/InboxTestDatabaseIsolationTest.php`. It MUST PASS. | - | [ ] | |
| TASK-014 | **VERIFY (fail-closed)**: Temporarily comment out the line from TASK-010, run the same single test file, and confirm PHPUnit exits with the bootstrap refusal message and runs ZERO tests. Restore the line and confirm `git diff app/Config/Database.php` shows only the intended change. | - | [ ] | |
| TASK-015 | **VERIFY (no damage)**: Run the full suite `vendor/bin/phpunit --no-coverage`. It MUST be green (baseline: 317 tests on 2026-09-24, plus 1 new class). Then repeat the TASK-004 queries on `aulia_inboxdb`: row counts and `AUTO_INCREMENT` MUST be identical to the baseline. `aulia_inboxdb_test` is expected to contain test rows. | REQ-005 | [ ] | |
| TASK-016 | **APPROVAL**: 🛑 Wait for explicit user confirmation to proceed to Phase 3 | - | [ ] | |

### Implementation Phase 3: Correct misleading documentation

- **GOAL-003:** Remove statements that tell future readers tests use the real Inbox database, and document the one-time setup.

| Task     | Description | Ref ID | Completed | Date |
| -------- | ----------- | ------ | :-------: | :--: |
| TASK-017 | `docs/ARCHITECTURE.md`: §2 table add row "Inbox test database — MySQL/MariaDB `aulia_inboxdb_test`, forced by `Config\Database` under `testing`"; §5 correct the sentence so it says both `default` and `inbox` are redirected under test; §11 add a short "One-time setup" block with the TASK-002/TASK-003 commands and the rule "re-run TASK-003 after adding a new inbox migration". | REQ-004 | [ ] | |
| TASK-018 | Comment-only fixes: `tests/session/InboxHandoffTest.php` docblock (lines 43-44) and `tests/database/ConversationHandoffsMigrationTest.php` docblock (lines 10-12): replace "real `inbox` group (MySQL aulia_inboxdb)" with "`inbox` group, redirected to `aulia_inboxdb_test` under testing". No logic change. | CON-003 | [ ] | |
| TASK-019 | **VERIFY**: `git diff --stat` touches only the files in Section 5. Grep `aulia_inboxdb[^_]` in `tests/` returns no claim that tests use the real database. Run the guard test once more: PASS. | - | [ ] | |
| TASK-020 | **APPROVAL**: 🛑 Wait for explicit user confirmation that the fix is complete | - | [ ] | |

## 3. Rollback Strategy

- **RBCK-001**: Code rollback: `git revert <fix-commit>` (restores `Database.php`, `phpunit.dist.xml`, removes the bootstrap and guard test). WARNING: after a revert the tests hit `aulia_inboxdb` again, so do not run the suite on a reverted tree.
- **RBCK-002**: Test database rollback: `DROP DATABASE aulia_inboxdb_test;` It only holds test rows; nothing else reads it.
- **RBCK-003**: Real data safety net: if TASK-015 shows any change in `aulia_inboxdb`, stop, and restore from the TASK-001 snapshot: `mysql -u root -p aulia_inboxdb < <scratch>/aulia_inboxdb-before-fix.sql`.
- **RBCK-004**: Production impact: none. Production never runs with `ENVIRONMENT === 'testing'` and never runs PHPUnit, so no production rollback is needed.

## 4. Dependencies

- **DEP-001**: Local MariaDB/MySQL (XAMPP) with the `.env` `database.inbox.*` user able to `CREATE DATABASE` and use `aulia_inboxdb_test`.
- **DEP-002**: `mysqldump` / `mysql` CLI (XAMPP: `C:\xampp\mysql\bin\`).
- **DEP-003**: No Composer or package changes.

## 5. Files Affected

- **FILE-001**: `app/Config/Database.php` — one line + comment in `__construct()` (TASK-010).
- **FILE-002**: `phpunit.dist.xml` — `bootstrap` attribute (TASK-012).
- **FILE-003**: `tests/_support/bootstrap.php` — new, fail-closed guard (TASK-011).
- **FILE-004**: `tests/database/InboxTestDatabaseIsolationTest.php` — new guard test (TASK-007).
- **FILE-005**: `docs/ARCHITECTURE.md` — §2, §5, §11 (TASK-017).
- **FILE-006**: `tests/session/InboxHandoffTest.php`, `tests/database/ConversationHandoffsMigrationTest.php` — docblock only (TASK-018).

## 6. Testing Strategy & Edge Cases

- **TEST-001**: Two layers of protection. Layer 1 (`tests/_support/bootstrap.php`) runs before any test, so a wrong configuration can never reach an `emptyTable()` call. Layer 2 (guard test) checks the live connection with `SELECT DATABASE()`, which catches cases where config says one thing but the connection uses another.
- **TEST-002**: Why the guard test alone is not enough: PHPUnit runs `tests/database` and `tests/session` in file order, so wiping tests could run before the guard test fails. The bootstrap check closes that gap.
- **TEST-003**: Edge case — `.env` or a shell variable sets `database.inbox.database = aulia_inboxdb`: ignored under `testing`, because the constructor assignment runs after `BaseConfig` applies env values.
- **TEST-004**: Edge case — `aulia_inboxdb_test` does not exist: MySQL connection error, tests fail loudly; the real database is never used as a fallback (the `inbox` group has `failover = []`).
- **TEST-005**: Edge case — a developer adds a local `phpunit.xml` with the old CI4 bootstrap: Layer 1 is skipped, but Layer 2 still fails and TASK-010 still redirects the connection, so no data is lost. Accepted.
- **TEST-006**: Edge case — schema drift after a new inbox migration: tests fail with "unknown column/table" in `aulia_inboxdb_test`; the fix is re-running TASK-003 (documented in TASK-017).

## 7. Risks & Assumptions

- **RISK-001**: `aulia_inboxdb_test` schema is a copy, not produced by migrations, so it can drift. Mitigation: TEST-006 fails loudly and the re-sync step is documented. Accepted to keep the fix minimal (a migration-driven test schema would need changes to migration history handling — out of scope).
- **RISK-002**: `mysqldump --no-data` keeps `AUTO_INCREMENT=` values in `CREATE TABLE`. Harmless: tests never assert on absolute ids.
- **RISK-003**: If the test database lives on a shared server in the future, the name `aulia_inboxdb_test` must be unique there. Not the case today (local XAMPP).
- **RISK-004**: The chat data already deleted from `aulia_inboxdb` is NOT restored by this plan. `DELETE FROM` is not undoable without a backup or MariaDB binary log. Recovery (backup, binlog, or Gateway re-sync) must be handled as a separate task.
- **ASSUMPTION-001**: No test relies on seeing real Inbox data from `aulia_inboxdb` (all Inbox tests empty the tables in `setUp()` and seed their own rows).
- **ASSUMPTION-002**: The `.env` `database.inbox` user has the same privileges on `aulia_inboxdb_test` as on `aulia_inboxdb` (XAMPP `root`).
