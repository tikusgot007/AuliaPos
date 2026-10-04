# Design: Atomic POS transaction creation (TODO-BL01)

- **Date**: 2026-10-04
- **Status**: approved
- **Requirements**: `docs/requirements/2026-10-04-atomisitas-transaksi-pos.md`
- **SDLC tier**: A

## 1. Summary

Move the initial payment insert into the same database transaction that writes
the transaction header and its detail rows, so the header can never be committed
as `lunas` without its `pembayaran` row, and a failed create leaves no partial
state. Reuse the existing payment validation as a single internal code path
shared by `simpanTransaksi()` and `tambahPembayaran()`.

## 2. Current flow (verified)

Create (POS):

    POST /api/simpan-transaksi
      -> Api::simpanTransaksi()              (app/Controllers/Api.php:78)
           status_pembayaran computed        (Api.php:255-302)
           header$status_pembayaran set       (Api.php:314-330)
      -> TransaksiModel::simpanTransaksi()   (app/Models/TransaksiModel.php:326)
           transStart -> insert header+details -> transComplete  [COMMIT #1]
      -> TransaksiModel::tambahPembayaran()  (app/Models/TransaksiModel.php:699)
           transStart -> insert pembayaran
                      -> sinkronkanPembayaran() -> transComplete [COMMIT #2]
      -> response success                    (Api.php:393-404)
      catch -> 500                           (Api.php:405-417)

Commit #1 makes the header visible (with `status_pembayaran` already set and
`total_dibayar = 0`) before commit #2 writes the payment. Failure/crash between
them leaves a lunas header with no payment; a manual retry creates a duplicate.

Existing-payment path (unchanged behavior, must not regress):

    POST /api/tambah-pembayaran -> Api::tambahPembayaran()  (Api.php:444)
    Api::koreksiPembayaran()                                 (Api.php:546)
    Tagihan::lunasi()                                        (Tagihan.php:371)
      -> TransaksiModel::tambahPembayaran()                  (TransaksiModel.php:699)

Callers of the methods that change (verified by repo search):

- `TransaksiModel::simpanTransaksi()`: only `Api::simpanTransaksi()`
  (`Api.php:352`). Signature change is safe.
- `TransaksiModel::tambahPembayaran()`: `Api.php:390`, `Api.php:526`,
  `Api.php:643`, `Tagihan.php:371`. Public signature is **not** changed.
- Route `Kasir::tambahPembayaran` (`app/Config/Routes.php:128`) is dead
  (already tracked as TODO-BL31) — no live caller.

## 3. Options

- **A. Single-transaction create + shared internal payment writer
  (recommended)**: extend `simpanTransaksi($dataTransaksi, $detailItems,
  ?array $dataPembayaran = null)`; inside its existing transaction, after detail
  inserts, write the payment row and call `sinkronkanPembayaran()`; extract the
  current payment normalization/validation/insert/sync from
  `tambahPembayaran()` into one private method used by both entry points.
  - Pro: true atomicity (one commit); existing-payment callers unchanged;
    validation stays a single source of truth.
  - Con: moderate diff in `TransaksiModel` + controller; needs a DB-backed test.
- **B. Outer transaction wrapping controller with nested inner transactions**:
  controller opens `transBegin()` and lets the model methods nest.
  - Con: CI4 nested semantics plus the inner invoice-collision retry loop and
    inner `transRollback()` make partial-commit/over-rollback failure modes hard
    to reason about and verify. Rejected.
- **C. Minimal guard only**: insert header as `belum_bayar`, and if the payment
  step fails, delete the orphan header in a compensating action.
  - Con: still two separate commits; crash between commits still leaves an
    orphan; compensating deletes are fragile; does not fully satisfy AC-2/AC-3.
    Rejected.

**Recommendation:** A, because it makes the create path a single commit
(structurally impossible to persist a lunas header without its payment) while
keeping the existing-payment callers untouched and validation in one place.

## 4. Planned changes

| File | Change | Reason |
|---|---|---|
| `app/Models/TransaksiModel.php` | Add optional `$dataPembayaran` param to `simpanTransaksi()`; after detail insert (still inside `transStart`), write the payment row and call `sinkronkanPembayaran()` when the param is provided. | One commit for header+details+payment. |
| `app/Models/TransaksiModel.php` | Extract payment normalize/validate/insert/sync into a private `catatPembayaranInternal()` used by both `tambahPembayaran()` and `simpanTransaksi()`. `tambahPembayaran()` keeps wrapping it in its own transaction. | Single source of truth; no behavior drift between create and existing paths. |
| `app/Controllers/Api.php` | Stop calling `tambahPembayaran()` after `simpanTransaksi()`; build `$dataPembayaran` as today and pass it to `simpanTransaksi()`. Do not pre-set a payment-specific commit. | Remove the second commit and keep the response contract identical. |
| `tests/integration/TransaksiSimpanAtomikTest.php` (new) + `phpunit.integration.xml` (new) | DB-backed regression tests over the `tests` SQLite group (`:memory:`, `db_` prefix). | Executable verification for AC-1..AC-6 without touching `.env`/live DB. |
| `docs/TODO.md` | Keep TODO-BL01 line; add a pointer to these design/requirement docs. | Traceability (AGENTS.md §21). |

Behavior to preserve explicitly:

- Method `piutang`/`draft`: no payment row, `status_pembayaran = 'belum_bayar'`
  (header value supplied by the controller is kept).
- Method real payment with `grand_total = 0` (full discount): `status_pembayaran
  = 'lunas'`, `total_dibayar = 0`, no payment row — unchanged.
- Method `dp`: one payment row equal to the DP, `status_pembayaran = 'dp'`.
- `simpanTransaksi()` invoice-collision retry (unique `kode_invoice`) keeps
  working: on retry the whole (uncommitted) unit is rolled back and re-inserted;
  the payment is written only after the header+details succeed in that attempt.
- `tambahPembayaran()` public signature and its backdate/admin/shift-leader
  validation are unchanged.

## 5. Impact

- **Database / migrations**: none. No schema change; `transaksi.kode_invoice`
  stays unique, `pembayaran.status` stays enum `aktif|reversed`.
- **Routes / API / response formats**: none. `POST /api/simpan-transaksi`
  request and response payloads stay identical; error path already returns
  `{status:error, message:...}`.
- **Gateway contract**: not involved (POS-only).
- **Existing data**: none migrated. The fix only prevents new partial rows.
- **Security / validation at trust boundaries**: payment validation continues to
  run in the model (metode enum, amount vs sisa, tunai `uang_diterima`, backdate
  gate). No new trust in client input.
- **Transactions / concurrency / rollback behavior**: create becomes a single
  transaction; on any failure the whole unit rolls back (AC-2). The existing
  `no_order` GET_LOCK and invoice-collision retry are preserved. Residual
  limitation: a client retry after an *uncertain* outcome (response lost after a
  successful commit) can still duplicate — this needs an idempotency key and is
  explicitly out of scope (TODO-BL33/DEC-4).

## 6. Test plan

| Acceptance criterion | Test (file::method) | Type |
|---|---|---|
| AC-1 | `tests/integration/TransaksiSimpanAtomikTest.php::testCashCreateIsAtomicAndLunas` | integration (SQLite) |
| AC-2 | `...::testPaymentFailureRollsBackWholeTransaction` | integration (SQLite) |
| AC-3 | `...::testRetryAfterFailureLeavesSingleTransaction` | integration (SQLite) |
| AC-4 | `...::testPiutangAndDraftHaveNoPayment` | integration (SQLite) |
| AC-5 | `...::testDpWritesOnePaymentAndDpStatus` | integration (SQLite) |
| AC-6 | `...::testExistingPaymentPathStillSyncs` | integration (SQLite) |

Test note: AC-2/AC-3 force the payment step to fail deterministically (e.g. a
payment value that fails validation, or a stubbed insert failure) and then assert
zero rows in `transaksi`/`detail_transaksi`/`pembayaran`. SQLite cannot express
true cross-connection concurrency; the tests assert the post-condition that makes
the race impossible (no commit of header without payment), the same limitation
already documented for the inbox concurrency tests.

## 7. Risks and mitigations

- <Regression in existing-payment paths> -> keep `tambahPembayaran()` public
  signature and wrap the shared internal method in its own transaction; AC-6.
- <DP/fully-discounted edge cases> -> explicit AC-4/AC-5 plus the preserved
  `grand_total = 0` rule; assert exact status/rows.
- <Payment written before header in a retry attempt> -> payment is written only
  after header+details succeed in the same attempt; retry rolls back the failed
  attempt first.
- <Test infra touching live DB> -> new suite uses the `tests` SQLite `:memory:`
  group under `ENVIRONMENT=testing`; no `.env` MySQL group is used.

## 8. Not yet verified

- Production incidence of the partial-state condition (no empirical count).
- Whether any external/manual DB consumer assumes the two-step commit timing
  (code search found none, but production tooling is not in this workspace).

## 9. Approval (Gate 2)

- [x] Approved by: user (chat), date: 2026-10-04
