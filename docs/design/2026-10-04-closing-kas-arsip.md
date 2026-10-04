# Design: Historical closing snapshot vs archived transactions (TODO-BL02)

- **Date**: 2026-10-04
- **Status**: approved
- **Requirements**: `docs/requirements/2026-10-04-closing-kas-arsip.md`
- **SDLC tier**: A

## 1. Summary

Make a saved closing snapshot authoritative: a date that already has a
`closing_kas` row keeps its stored `saldo_sistem` (only `saldo_fisik`/`selisih`
may change). Only a brand-new closing date is computed, and that compute now
includes cash sales that have moved to the archive database, so an archived
period does not silently read 0.

## 2. Current flow (verified)

Read / edit modal:

    GET /cash/closing/detail
      -> Cash::closingDetail()                    (app/Controllers/Cash.php:320)
           $saldo = getSaldoKasHariIni($cutoff)    (Cash.php:330)
             -> CashBalanceService::getBalance()   (app/Services/CashBalanceService.php:19)
                  getCashSales(): live pembayaran+transaksi  (CashBalanceService.php:94)
      -> view uses response.saldo_sistem (recomputed) (app/Views/cash/closing.php:402)
         `response.existing` is returned but NOT used

Save:

    POST /cash/closing/simpan
      -> Cash::simpanClosing()                    (Cash.php:359)
           $saldo = getSaldoKasHariIni($cutoff)    (Cash.php:375) [recomputed, live only]
           ClosingKasModel::simpanClosing()        (app/Models/ClosingKasModel.php:90)
             update-or-insert closing_kas.saldo_sistem = recomputed

Archive removes the live source rows:

    TransaksiArchiveService::hapusDariUtama()     (app/Services/TransaksiArchiveService.php:705)
      delete transaksi where id in (...) [cascade: detail_transaksi, pembayaran]

`cash_expense` is never archived, so only the `penjualan` component of the
balance is lost from the live view after archiving.

Callers / consumers of anything that will change (verified by repo search):

- `getSaldoKasHariIni()` callers: `Cash.php:20,23,50,51,159,189,330,375`.
  Today-only paths (`index`, `opname`, `getSaldoSistem`, `simpanOpname`) must
  stay live-only (AC-6). Historical paths (`closingDetail`, `simpanClosing`)
  are the ones in scope.
- `ClosingKasModel::getByBulan`/`getByRentang` (list + Laporan Bulanan) already
  read snapshots — unchanged, must not regress (AC-5).
- `TransaksiArchiveService` is the existing owner of the archive DB; report
  merging already uses the "one transaction lives in exactly one source
  (live XOR archive)" invariant (`TransaksiArchiveService.php:1015-1018`).

## 3. Options

- **A. Immutable snapshot + archive-aware compute for new closings
  (recommended)**: for an existing date, return/reuse the stored `saldo_sistem`;
  for a new date, compute live sales + archived cash sales. Adds a small
  archived-sales query to the archive service and a composer helper.
  - Pro: removes the corruption and still lets an admin close a missed day with
    a correct number; reuses the existing live-XOR-archive pattern.
  - Con: couples the closing path to the archive DB; extra query on the
    new-closing path only.
- **B. Immutable snapshot + reject new closings for archived months**: same
  immutability, but a new date whose month is archived is refused with a clear
  message instead of computed.
  - Pro: no cross-DB coupling, smallest, no perf cost.
  - Con: blocks a legitimate admin action (closing a missed day) and needs a
    month-archived check.
- **C. Archive-aware compute only (keep recompute for existing)**: include
  archived sales in `getBalance` and keep overwriting `saldo_sistem`.
  - Con: does not make the snapshot final; any other live drift still rewrites
    a historical closed day. Rejected.

**Recommendation:** A, because it makes the saved closing genuinely final
(structurally impossible to overwrite with a live-only recompute) while keeping
the admin able to complete a missed closing with a correct figure; it reuses the
invariant already trusted by Laporan.

## 4. Planned changes

| File | Change | Reason |
|---|---|---|
| `app/Controllers/Cash.php` | `closingDetail()`: use `existing.saldo_sistem` when a snapshot exists; otherwise compute historical (live + archive). `simpanClosing()`: preserve `existing.saldo_sistem`; compute historical only for a new date. | Snapshot becomes authoritative (AC-1/2/3); new dates stay correct (AC-4). |
| `app/Models/ClosingKasModel.php` | `simpanClosing()` keeps a passed-in `saldo_sistem` and does not recompute; optionally expose a pure `saldoSistemFinal(?array $existing, float $hitungUlang): float` for testability. | One place decides snapshot-vs-recompute; unit-testable. |
| `app/Services/TransaksiArchiveService.php` | Add `getPenjualanTunaiMentah(string $date, string $at): float` (SUM archived cash payments, `t.status != 'batal'`, `p.status = 'aktif'`, `p.metode = 'tunai'`, date range). | Provide the archived component of cash sales. |
| `app/Helpers/cash_helper.php` | Add `getSaldoKasHistoris(?string $at): array` = live `getSaldoKasHariIni($at)` + archived cash sales (added to `penjualan`/`saldo`). | Single composer for the closing path; keeps `CashBalanceService` live-only for today. |
| `tests/unit/ClosingKasSaldoSistemTest.php` (new) | Pure decision: existing → stored, new → recomputed. | AC-1/2/3 without DB. |
| `tests/feature/ClosingKasHistorisTest.php` (new) | Integration over the `tests` SQLite group + a temp archive SQLite file: archived sales added for a new closing; snapshot untouched on re-save. | AC-3/4 executable. |
| `docs/CHANGELOG.md` | Note the new rule "`saldo_sistem` closing bersifat final setelah disimpan" (Bahasa Indonesia). | Business-rule change logging (AGENTS.md §15). |

No schema change to `closing_kas`. Response shapes stay identical:
`saldo_sistem` simply carries the stored value when the date is already closed.

## 5. Impact

- **Database / migrations**: none. `closing_kas` unchanged. Archive DB untouched
  (read-only query added). The `inbox` group is irrelevant here.
- **Routes / API / response formats**: unchanged; `GET /cash/closing/detail` and
  `POST /cash/closing/simpan` keep their payloads. For an existing date,
  `saldo_sistem` now equals the stored snapshot instead of a live recompute.
- **Gateway contract**: not involved.
- **Existing data**: no migration; existing snapshots become authoritative and
  are no longer overwritten. No historical row is modified by the change itself.
- **Security / validation at trust boundaries**: closing stays admin-only
  (`AuthFilter` prefix `cash/closing`); the client still only supplies
  `tanggal` and `saldo_fisik`; `saldo_sistem` remains server-computed/derived.
- **Transactions / concurrency / rollback behavior**: unchanged; the existing
  unique-date race retry in `Cash::simpanClosing()` (`Cash.php:383-386`) stays.
  Archive read failure must **not** fall back to a live-only number silently —
  fail the save with an error instead of storing a wrong snapshot.

## 6. Test plan

| Acceptance criterion | Test (file::method) | Type |
|---|---|---|
| AC-1, AC-2, AC-3 (decision) | `tests/unit/ClosingKasSaldoSistemTest.php::testExistingKeepsStored` | unit (pure) |
| AC-3, AC-4 | `tests/feature/ClosingKasHistorisTest.php::testNewClosingIncludesArchivedCashSales` | integration (SQLite) |
| AC-4 | `tests/feature/ClosingKasHistorisTest.php::testArchivedSalesAreNotCountedTwice` | integration (SQLite) |
| AC-5 | existing `ClosingKasModel::getByRentang` behavior asserted in the feature test | integration |
| AC-6 | assert `CashBalanceService::getBalance()` (today path) is not archive-aware | unit/integration |

Test note: the `tests` group is in-memory SQLite; the archive group is a
separate SQLite file. The feature test points the `archive` group at a temp file
and seeds `transaksi_archive`/`pembayaran_archive`, mirroring the existing
`TransaksiArchiveService` schema, so no live DB is touched.

## 7. Risks and mitigations

- <Archive DB unreadable during a new closing> -> fail the save with a clear
  error; never store a live-only number. (Do not silently fall back.)
- <Extra archive query on every closing detail> -> only compose the archive
  component for the **new-closing** path; existing dates return the snapshot
  with no archive query.
- <Double count if archive copy succeeded but live delete failed> -> rely on the
  documented live-XOR-archive invariant used by Laporan (TODO-BL20 tracks the
  broader dedup concern); not re-solved here.
- <Regression in today's dashboard/opname> -> keep `CashBalanceService` and the
  today-only callers untouched; AC-6.

## 8. Not yet verified

- Whether any production month has actually been archived (impact is latent
  until then).
- Exact cost of `TransaksiArchiveService` instantiation (it self-initializes the
  archive schema) on the new-closing path; low-frequency admin action, but not
  measured.

## 9. Approval (Gate 2)

- [x] Approved by: user (chat), date: 2026-10-04
