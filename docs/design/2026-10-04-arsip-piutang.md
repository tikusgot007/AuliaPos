# Design: Archive excludes active receivables (TODO-BL06, DEC-3 "A+ with valve E")

- **Date**: 2026-10-04
- **Status**: approved
- **Requirements**: `docs/requirements/2026-10-04-arsip-piutang.md`
- **SDLC tier**: A

## 1. Summary

`TransaksiArchiveService` currently selects every transaction in the chosen
month(s) regardless of payment status. Per DEC-3, add a payment-status
eligibility filter at the single chokepoint (`terapkanFilterBulan()`): a
transaction is archive-eligible only if `status_pembayaran = 'lunas'` OR
`status IN ('batal', 'mangkrak')`. Active receivables (`belum_bayar`/`dp`,
not `mangkrak`) stay in the live database. `preview()` reports the
excluded receivables (count + value) separately so an admin sees them before
executing.

## 2. Current flow (verified)

    ArchiveTransaksi::preview() / jalankan()      (app/Controllers/ArchiveTransaksi.php:48,80)
      -> TransaksiArchiveService::preview()/jalankan() (TransaksiArchiveService.php:414,479)
           validasiBulanEligible($bulanList)       (:345) -- month-level eligibility only
           terapkanFilterBulan($builder, $bulanList) (:378-400) -- date range ONLY, no status filter
           -> ALL transactions in the month(s), any status_pembayaran

`terapkanFilterBulan()` has exactly two callers (`preview()`, `jalankan()`) —
confirmed by search, no other usage. It is the correct single chokepoint for
the new rule; both read (preview) and write (execute) paths automatically
stay consistent.

`jalankan()` then copies and deletes everything the builder returned
(`:479-555`, `:711-726`), so today a `belum_bayar`/`dp` transaction is
archived and removed from MySQL exactly like a `lunas` one. `Tagihan.php`
has no archive awareness and does not need any (verified: `tagihanBaseBuilder()`
already excludes `status IN ('batal','mangkrak')`, `Tagihan.php:65-73`).

ESC-002 (corrective check, verified 2026-10-04): `transaksi_archive` has 0
rows (the feature has never been run on this installation), so no backfill /
data-recovery step is needed.

Bug found while reading the same function (in scope to fix, same file/method,
not a separate root cause): `preview()`'s `per_status` bucket keys are
`['belum_bayar','dp','lunas','batal']` but the counted value is always
`$t['status_pembayaran']` (`:446`), which is never `'batal'` (that's the
*transaction* status, a different column, `status_pembayaran` enum only has
`belum_bayar|dp|lunas`). So the `batal` bucket is dead code (always 0) and a
cancelled transaction is miscounted under its `status_pembayaran` value
instead. AC-6 fixes this as part of the same edit.

## 3. Options

- **A. Filter inside `terapkanFilterBulan()` (recommended)**: add the
  eligibility condition to the one shared builder function.
  - Pro: single source of truth for both preview and execute; zero risk of
    the two paths drifting; minimal diff.
  - Con: none identified.
- **B. Filter separately in `preview()` and `jalankan()`**: duplicate the
  `where` clause in both methods.
  - Con: exactly the kind of duplication that causes drift bugs; rejected
    per AGENTS.md §1.2 (reuse over duplication).
- **C. Keep selecting everything, exclude receivables only at the delete
  step**: still copy receivables into the archive DB, just don't delete them
  from MySQL.
  - Con: contradicts DEC-3 (receivables must not be *moved* at all, not even
    duplicated into the archive); also means `jalankan()`'s row-count
    validation (`validasiHasilSalin()`) would need special-casing. Rejected.

**Recommendation:** A, because the existing architecture already funnels
both preview and execution through one filter builder — extending it is the
smallest, safest change and cannot let the two paths disagree.

## 4. Planned changes

| File | Change | Reason |
|---|---|---|
| `app/Services/TransaksiArchiveService.php` | `terapkanFilterBulan()`: add `AND (status_pembayaran = 'lunas' OR status IN ('batal','mangkrak'))` to the existing date-range `WHERE`. | Single chokepoint; AC-1..AC-5. |
| `app/Services/TransaksiArchiveService.php` | `preview()`: also query the **excluded** receivables (month range, NOT eligible) for `jumlah_piutang_aktif` / `total_piutang_aktif`; fix `per_status['batal']` to count `status = 'batal'` rows instead of a `status_pembayaran` value that never equals `'batal'`. | AC-1, AC-6. |
| `app/Services/TransaksiArchiveService.php` | `jalankan()`: when the eligible set is empty but the month has transactions (all of them receivables), return the existing "no transactions" message path, which already touches nothing in MySQL. | AC-5 (already correct given AC-2's filter; confirmed by test, not changed). |
| `app/Views/archive_transaksi/index.php` | Preview panel: show the new `jumlah_piutang_aktif`/`total_piutang_aktif` fields (the "pengaman UI" from DEC-3). | AC-1 (UI side of the safeguard). |
| `tests/integration/TransaksiArchiveService PiutangTest.php` (new) | DB-backed tests for AC-1..AC-6 on MySQL-shaped SQLite tables (mirrors the existing `tests/integration` pattern). | Executable verification. |
| `docs/CHANGELOG.md` | Log the business rule (Bahasa Indonesia): archive never moves an active receivable. | AGENTS.md §15 (business-rule change). |

No change to `validasiBulanEligible()`, `isBulanEligible()`, `hapusDariUtama()`,
`salinKeArchive()`, `validasiHasilSalin()`, or `Tagihan.php` — they operate
purely on whatever row set `terapkanFilterBulan()` already narrowed down.

### Eligibility rule, precisely

A transaction is archive-eligible for a chosen month set when:

```
tanggal is within one of the selected months
AND (
    status_pembayaran = 'lunas'
    OR status IN ('batal', 'mangkrak')
)
```

`status = 'batal'` is included regardless of `status_pembayaran` (a
cancelled sale has no real receivable, matches current intent and the
existing `Tagihan` exclusion). `status = 'mangkrak'` is included regardless
of `status_pembayaran` (valve E: an admin already had to explicitly mark it
mangkrak via the existing `TransaksiModel::ubahStatus()` admin-gated flow
before this rule ever sees it — no new authorization logic needed here).

## 5. Impact

- **Database / migrations**: none. No schema change; the new condition uses
  existing columns/enum values.
- **Routes / API / response formats**: `POST /archive-transaksi/preview`
  response gains two new fields (`jumlah_piutang_aktif`, `total_piutang_aktif`);
  existing fields keep their meaning (they now describe the *eligible*
  subset, which is the intended fix, not a breaking change in shape).
  `POST /archive-transaksi/jalankan` response shape is unchanged.
- **Gateway contract**: not involved.
- **Existing data**: ESC-002 confirmed empty archive; nothing to migrate.
- **Security / validation at trust boundaries**: no new input; `bulanList`
  validation (`validasiBulanEligible()`) is unchanged.
- **Transactions / concurrency / rollback behavior**: unchanged; `jalankan()`
  keeps its backup -> copy -> validate -> delete order, just over a smaller
  (correct) row set.

## 6. Test plan

| Acceptance criterion | Test (file::method) | Type |
|---|---|---|
| AC-1 | `...::testPreviewReportsExcludedReceivablesSeparately` | integration |
| AC-2 | `...::testJalankanDoesNotArchiveActiveReceivable` | integration |
| AC-3 | `...::testJalankanStillArchivesLunasAndBatal` | integration |
| AC-4 | `...::testJalankanArchivesMangkrakReceivable` | integration |
| AC-5 | `...::testJalankanWithOnlyReceivablesTouchesNothing` | integration |
| AC-6 | `...::testPreviewCountsBatalByTransactionStatus` | integration |
| AC-7 | covered implicitly by AC-2..AC-4 (validasiHasilSalin runs unchanged over the narrowed set; no separate test needed) | - |

Test note: these run on the `tests` SQLite group with a dedicated temporary
SQLite file for the `archive` connection (same pattern as
`tests/integration/ClosingKasArsipTest.php`), so no live MySQL or the real
`writable/archive/aulia_pos_archive.db` is touched.

## 7. Risks and mitigations

- <A receivable later marked `mangkrak` could be archived by mistake if the
  admin didn't intend that> -> this is the explicitly chosen valve E
  behavior (DEC-3); marking mangkrak is itself an admin-gated, deliberate
  action, not an automatic side effect.
- <Preview's new excluded-receivables query adds load> -> it reuses the same
  date-range filter already built for the eligible query; one extra
  `COUNT`/`SUM`, negligible for an admin-only, low-frequency page.
- <Fixing the `batal` bucket bug changes a number admins may already be used
  to (always 0)> -> flagged explicitly in the plan and CHANGELOG; it was
  dead/wrong code, not an intentional display choice.

## 8. Not yet verified

- No production run of Archive Transaksi exists yet to observe real receivable
  volumes; behavior is verified against the rule and synthetic test data only.

## 9. Approval (Gate 2)

- [x] Approved by: user (chat), date: 2026-10-04
