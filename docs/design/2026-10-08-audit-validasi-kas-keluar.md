# Design: Audit trail & date validation for Kas Keluar (TODO-BL10)

- **Date**: 2026-10-08
- **Status**: approved (implemented 2026-10-08)
- **Requirements**: `docs/requirements/2026-10-08-audit-validasi-kas-keluar.md`
- **SDLC tier**: A

## 1. Summary

Add an append-only audit table for `cash_expense` update/delete, and reject
create/update when the expense date is in the future or falls on a date
already finalized in `closing_kas`. No change to who can access the Kas
Keluar module (DEC-1: Option B, no admin-gating).

## 2. Current flow (verified)

    POST /cash/tambah-pengeluaran
      -> Cash::tambahPengeluaran()            (app/Controllers/Cash.php:441)
      -> CashExpenseModel::simpanPengeluaran() (app/Models/CashExpenseModel.php:128-138)
      -> Model::insert()                       (no date check beyond valid_date)

    POST /cash/edit-pengeluaran/(:num)
      -> Cash::updatePengeluaran($id)          (app/Controllers/Cash.php:521-594)
      -> CashExpenseModel::updatePengeluaran() (app/Models/CashExpenseModel.php:143-152)
      -> Model::update()                       (overwrites tanggal/nominal/keterangan/penerima, no trace)

    DELETE /cash/hapus-pengeluaran/(:num)
      -> Cash::hapusPengeluaran($id)           (app/Controllers/Cash.php:599-615)
      -> CashExpenseModel::hapusPengeluaran()  (app/Models/CashExpenseModel.php:157-160)
      -> Model::delete()                       (hard delete, no trace)

    saldo_sistem (Closing Kas) consumption of cash_expense:
      CashBalanceService::getBalance()         (app/Services/CashBalanceService.php:19-40)
        sums cash_expense by kategori (kas_awal_hari / pengeluaran+penyesuaian / refund_penjualan)
      Cash::closingDetail() / simpanClosing()  (app/Controllers/Cash.php:330-331,383-384)
        recompute only when ClosingKasModel::getByTanggal($tanggal) is null (snapshot is final otherwise)

Callers of `CashExpenseModel::updatePengeluaran()` / `hapusPengeluaran()`:
only `Cash.php` controller methods above (verified via grep; no other
callers). `simpanPengeluaran()` is also only called from
`Cash::tambahPengeluaran()`. `CashBalanceService::saveOpeningCash()` writes
`cash_expense` directly via query builder for `kas_awal_hari` only — out of
scope (not `pengeluaran`/`penyesuaian`, not touched by this change).

Existing date-validation precedent: `ClosingKasModel::validasiTanggal()`
(`app/Models/ClosingKasModel.php:66-84`) is a pure static method (format +
reject today/future) with its own unit-style verification pattern. This
design follows the same shape but with different bounds (today is allowed;
only *future* and *already-closed* dates are rejected, per Decision 2).

## 3. Options

Only one open implementation choice remains (business decisions 1-3 are
already settled: audit = separate table, B; date rule = today or earlier AND
not closed, A; lock = both update and delete, A).

- **A. Store before/after values as a JSON blob** (one `data_sebelum` /
  `data_sesudah` JSON column each): matches existing JSON-column precedent
  in this codebase (`messages.extra_json`,
  `2026-10-01-000001_AddExtraJsonToMessages.php`). Schema stays stable if
  audited fields change later; querying individual fields needs
  `JSON_EXTRACT` but audit data is for manual investigation, not reporting.
- **B. Fixed columns per field** (`nominal_sebelum`, `nominal_sesudah`,
  `tanggal_sebelum`, ... x4 fields x2 = 8 columns): more directly queryable,
  but churns the schema if `cash_expense`'s editable fields change, and this
  table has no reporting UI planned (out of scope) to benefit from direct
  columns.

**Recommendation:** A, because the audit table has no reporting UI in scope,
JSON keeps the schema minimal, and the codebase already has a working
JSON-column precedent on MySQL (project charset `utf8mb4`, `DBDriver
MySQLi`, `app/Config/Database.php`).

## 4. Planned changes

| File | Change | Reason |
|---|---|---|
| `app/Database/Migrations/2026-10-08-000001_CreateCashExpenseAuditTable.php` | New migration: `cash_expense_audit` table (`id`, `expense_id` bigint indexed — no FK, see §5, `aksi` enum('update','delete'), `data_sebelum` JSON, `data_sesudah` JSON NULL, `user_id` int, `created_at` timestamp) | AC-1, AC-2 |
| `app/Models/CashExpenseAuditModel.php` | New model, `allowedFields` for the audit table; thin `catatUpdate()` / `catatHapus()` helpers | AC-1, AC-2 |
| `app/Models/CashExpenseModel.php` | Add pure static `validasiTanggal(?string $tanggal, ?string $hariIni = null): ?string` (format + reject future, mirrors `ClosingKasModel::validasiTanggal` shape but allows today). Change `updatePengeluaran()` and `hapusPengeluaran()` to accept `$userId`, wrap in a DB transaction, read the existing row first, write one audit row via `CashExpenseAuditModel`, then perform the update/delete. | AC-1, AC-2, AC-3, AC-4 |
| `app/Controllers/Cash.php` | In `tambahPengeluaran()`, `updatePengeluaran()`, `hapusPengeluaran()`: call `CashExpenseModel::validasiTanggal()`; for create/update also reject when `ClosingKasModel::getByTanggal()` finds a closing for the (new) date; for update/delete also reject when the **existing** row's date already has a closing. Pass `session()->get('id_user') ?? 1` into the model calls for the audit `user_id`. | AC-3, AC-4, AC-5, AC-6, AC-7 |
| `docs/CHANGELOG.md` | New entry documenting the business-rule change (date validation) | AGENTS.md §15 |

## 5. Impact

- **Database / migrations**: one new table, `default` group only (the same
  MySQL database as `cash_expense`; this feature does not touch the `inbox`
  group). No FK constraint from `cash_expense_audit.expense_id` to
  `cash_expense.id`: a `delete` audit row must survive after the source row
  is hard-deleted, and MySQL `ON DELETE CASCADE` would erase the very audit
  row the delete action is supposed to leave behind. An indexed (non-FK)
  column is used instead, matching how `closing_kas` already indexes
  `updated_by` without assuming referential integrity is required for an
  audit trail.
- **Routes / API / response formats**: no route changes. Response bodies for
  the three existing endpoints gain no new required fields; a request that
  is now rejected returns the existing `{status:'error', message:...}` shape
  (same contract already used for nominal/keterangan validation errors in
  `Cash.php:450-462`), so no breaking change for current callers (this is a
  server-rendered jQuery form, not a documented external API).
- **Gateway contract**: not applicable (no WA-Gateway interaction in this
  module).
- **Existing data**: no backfill. Historical `cash_expense` rows keep no
  audit trail (acceptable — audit only applies going forward, per AC-1/AC-2
  which describe new mutations). Existing rows whose `tanggal` already falls
  on a closed date remain stored as-is; they simply become un-editable going
  forward (AC-5/AC-6), which is the intended lock.
- **Security / validation at trust boundaries**: `tanggal` from the AJAX
  JSON body is already validated as `valid_date`; this adds a business-rule
  bound (not future, not closed) enforced server-side (not just in the
  `datetime-local` input), closing the gap described in TODO-BL10.
- **Transactions / concurrency / rollback behavior**: `updatePengeluaran()` /
  `hapusPengeluaran()` wrap the audit-insert + update/delete pair in
  `transBegin()`/`transComplete()` so a failure to write the audit row
  aborts the mutation (and vice versa) — no silent loss of either side.
  Existing closing-lock check is a plain `SELECT` before the transaction;
  a race where a closing is created *during* the update is not newly
  introduced here (the existing `closing_kas` unique index +
  `simpanClosing()`'s own retry-on-exception, `Cash.php:390-395`, already
  handles that race for the closing side) and is accepted as a pre-existing,
  narrow race window — not a regression from this change.

## 6. Test plan

| Acceptance criterion | Test (file::method) | Type |
|---|---|---|
| AC-1 | `tests/integration/CashExpenseAuditTest.php::testUpdateWritesAuditRowWithBeforeAndAfter` | integration |
| AC-2 | `tests/integration/CashExpenseAuditTest.php::testDeleteWritesAuditRowAndRemovesExpense` | integration |
| AC-3 | `tests/unit/CashExpenseValidasiTanggalTest.php::testFutureDateRejected` | unit |
| AC-4 | `tests/unit/CashExpenseValidasiTanggalTest.php::testTodayAndPastDateAccepted` | unit |
| AC-5 | `tests/integration/CashExpenseClosingLockTest.php::testUpdateAndDeleteRejectedWhenOldDateIsClosed` | integration |
| AC-6 | `tests/integration/CashExpenseClosingLockTest.php::testUpdateRejectedWhenNewDateIsClosed` | integration |
| AC-7 | `tests/integration/CashExpenseAuditTest.php::testCreateWritesNoAuditRow` | integration |

Integration tests follow the existing `tests/integration` pattern
(`ClosingKasArsipTest.php`): `CIUnitTestCase`, in-memory SQLite `tests`
group (forced by `ENVIRONMENT=testing`, see
`tests/_support/bootstrap-integration.php`), schema built with `Forge` in
`setUp()`/torn down in `tearDown()`. No `.env` database is touched. Unit
tests for `validasiTanggal()` follow `KalkulasiClosingKasTest.php`'s plain
`PHPUnit\Framework\TestCase` pattern (pure function, no DB).

## 7. Risks and mitigations

- Forgetting to pass `$userId` into the model calls would silently blame the
  audit row on a fallback user -> mitigated by requiring `$userId` as a
  non-optional constructor-style argument (no default) on the two changed
  model methods, so a missing argument is a PHP error, not a silent `?? 1`.
- A controller code path bypassing the new closing-lock check (e.g. a future
  caller invoking `CashExpenseModel::updatePengeluaran()` directly) would
  skip validation -> mitigated by keeping `validasiTanggal()` callable
  independently so any future caller can reuse it, matching how
  `ClosingKasModel::validasiTanggal()` is already shared between
  `closingDetail()` and `simpanClosing()`.
- JSON audit payload growing unboundedly over time -> accepted; no retention
  policy is in scope for this change (same as `cash_expense` itself, which
  has no retention/prune policy today).

## 8. Not yet verified

- Whether any other internal tool/report queries `cash_expense` directly and
  assumes update/delete never happens without a corresponding business
  reason — a repo-wide grep found only the two `Cash.php` controller methods
  as callers of the changed model methods, but downstream consumers of the
  *data* (e.g. ad-hoc SQL reports outside this repo) are not visible from
  the codebase.

## 9. Approval (Gate 2)

- [x] Approved by: user (session 2026-10-08), date: 2026-10-08
