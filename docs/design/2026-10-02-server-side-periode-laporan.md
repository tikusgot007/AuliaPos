# Design: Server-side DataTables for Laporan tab Periode

- **Date**: 2026-10-02
- **Status**: approved (Gate 2, Option A approved by user 2026-10-02)
- **Requirements**: `docs/requirements/2026-10-02-server-side-periode-laporan.md`
- **SDLC tier**: A
- **Parent pattern**: `docs/design/2026-10-02-server-side-datatables.md` (S1/S2/S3b)

## 1. Summary

Add a server-side DataTables endpoint for the Periode tab only (`Laporan::periodeData()` for DataTables +
`Laporan::periodeExport()` for the full-filtered-set CSV), following the proven S1/S2/S3b pattern. The view
`app/Views/laporan/index.php` changes **only for `jenis='periode'`**; the other three tabs and the existing
`POST /laporan/get-data` path are left untouched.

## 2. Current flow (verified)

```
GET /laporan  -> Laporan::index()                      (app/Controllers/Laporan.php:22-28, admin-only)
  -> view app/Views/laporan/index.php
     loadLaporan('periode')  parses #filterPeriode -> tanggal_awal/akhir   (index.php:250-345, default = start of month..today at :289-292)
     POST /laporan/get-data {jenis:'periode',...}   (route: app/Config/Routes.php:177)
       -> Laporan::getData()                                          (Laporan.php:46)
          -> SELECT transaksi + join pelanggan, date range, status!='batal' (Laporan.php:123-130)
          -> merge archive: TransaksiArchiveService::getTransaksiMentah()  (Laporan.php:146-158)
          -> SELECT detail_transaksi whereIn(transaksi_id) + archive detail (Laporan.php:179-193)
          -> group details by transaksi_id                              (Laporan.php:196-199)
          -> processData('periode',...) -> processDetailTransaksi()     (Laporan.php:999-1027, 1032-1083)
             1 row = 1 transaction with >=1 detail row                  (Laporan.php:1036-1082)
             sisa_tagihan = grand_total - total_dibayar (NOT clamped)    (Laporan.php:1076)
          -> calculateSummary()  (used by Harian/Bulanan tfoot only; Periode renders no tfoot) (Laporan.php:1347; view :580-703)
       -> renderLaporan('periode', data, ...)                          (index.php:502-814)
          <table id="table_resultPeriode"> holds ALL rows               (index.php:532-707)
          client-side DataTables (serverSide defaults to false)          (index.php:718-801)
```

Periode columns (9 columns, `getHeaders('periode')` index.php:833; row mapping `getRowData('periode')` index.php:868-878):
Tanggal, Invoice, No Order, Pelanggan, Subtotal, Diskon, Grand Total, Sisa Tagihan, Status.
Numeric columns = index 4..7; Status column renders the **raw** `status_pembayaran` value (no label mapping);
`no_order` is rendered **raw** (not through `format_no_order()`); date is already `d/m/Y` from the backend.

Measured data (DB `aulia_kasirdb`, 2026-10-02): range 2026-01-01..2026-10-02 -> 11,926 rows (freeze threshold);
transactions with zero detail rows = 0; transactions with `grand_total < total_dibayar` = 7 (proves sisa can be
negative and must not be clamped).

## 3. Options

- **A (chosen). New endpoint, legacy path untouched**: add `periodeData()`/`periodeExport()` + routes
  `GET /laporan/periode-data` & `/laporan/periode-export`. The Periode tab in the view branches to server-side
  DataTables; the other tabs and `getData()` stay exactly as they are. Smallest diff, consistent with S1/S2/S3b,
  and `getData()` keeps serving the other three tabs unchanged.
- **B. Reuse `getData()` for Periode**: paginate inside `processDetailTransaksi()`.
  Rejected: `getData()` still fetches the entire live+archive transaction set and all detail rows before any
  pagination would happen, so it does not remove the actual bottleneck; it also risks touching code shared with
  the other tabs' fallback path.

**Recommendation: A** (matches the parent design's already-approved S1/S2/S3b pattern).

## 4. Planned changes

| File | Change | Reason |
|---|---|---|
| `app/Controllers/Laporan.php` | Add `periodeFilterBag()`, `periodeBaseBuilder()`, `periodeApplyFilters()`, `periodeOrder()` (whitelist column index 0..8; Sisa Tagihan ordered via the raw SQL expression `transaksi.grand_total - transaksi.total_dibayar`, **no** `GREATEST`, because the value can be negative), `periodeLiveCount()`, `periodeLiveRows()` (bounded to `start + length` when merging archive), `periodeArchiveRows()`, `periodeSortRows()` (derived-column key map), `periodeRowForJson()`, `periodeData()` (admin guard), `periodeExport()` (admin guard) | New server-side endpoint + CSV export; single source of truth; Sisa Tagihan is a derived expression, not a real column |
| `app/Config/Routes.php` | Add `GET /laporan/periode-data` and `GET /laporan/periode-export`, both `['filter' => 'auth']` | New routes; `auth` filter plus an explicit admin check inside the controller |
| `app/Views/laporan/index.php` | Branch server-side **only** for `jenis==='periode'`: render an empty `<table>` and init DataTables with `serverSide:true`, `ajax.url=/laporan/periode-data`, explicit `columns` with `render`; search box and pagination stay enabled; the **CSV** button points at `/laporan/periode-export`; Copy/Excel/PDF/Print keep `exportOptions:{columns:':visible'}` (current page only, same as S1/S2). Harian/Bulanan/Kategori are **not** touched | Server-side rendering + full-set CSV export; the other three tabs stay safe |

`periodeBaseBuilder()` (live SQL, no join to `detail_transaksi` — an `EXISTS` subquery is enough to enforce the
"must have at least one detail row" rule, since `subtotal`/`diskon`/`grand_total` already live on `transaksi`):

```sql
SELECT t.id, t.kode_invoice, t.no_order, t.tanggal, t.pelanggan_id, t.kasir_id,
       t.subtotal, t.diskon, t.grand_total, t.total_dibayar,
       t.status_pembayaran, t.status, p.nama AS pelanggan_nama
FROM transaksi t
LEFT JOIN pelanggan p ON p.id = t.pelanggan_id
WHERE t.tanggal >= :awal 00:00:00 AND t.tanggal <= :akhir 23:59:59
  AND t.status != 'batal'
  AND EXISTS (SELECT 1 FROM detail_transaksi d WHERE d.transaksi_id = t.id)
```

## 5. Impact

- **Database / migrations**: none. Read-only queries against `transaksi`, `pelanggan`, `detail_transaksi` (via
  `EXISTS` only), plus the existing SQLite archive through `TransaksiArchiveService`.
- **Routes / API**: two new GET routes, purely additive. `POST /laporan/get-data` keeps its exact current
  request/response shape; the other tabs and `exportExcel()` are unaffected.
- **Gateway contract**: not applicable.
- **Existing data**: no writes.
- **Security at trust boundaries**: `periodeOrder()` whitelists `order[0][column]` (index 0..8; unknown index
  ignored, falls back to default `tanggal ASC`). Admin access is enforced twice: `App\Filters\AuthFilter`
  already blocks non-admins for the whole `laporan` prefix (`app/Filters/AuthFilter.php:70-78`, route path
  `laporan/...`), and `periodeData()`/`periodeExport()` additionally check `session()->get('role') === 'admin'`
  (defense in depth). Both new routes also carry the `auth` filter, so unauthenticated access is redirected to
  `/login`. The earlier draft of this document inaccurately described `POST /laporan/get-data` as an admin gap;
  it is not — the prefix rule covers it.
- **Transactions / concurrency / rollback**: not applicable, read-only reporting.

## 6. Test plan

No pure-logic unit test is added (the SQL-builder helpers need a live DB connection; consistent with S1/S2/S3b,
where verification was DB-parity + headless rather than `tests/unit`).

| AC | Verification | Type |
|---|---|---|
| AC-1, AC-2 | For a wide range, `recordsFiltered` from `/laporan/periode-data` equals `total` from `/laporan/get-data` for the same range | DB-parity |
| AC-3 | `EXISTS` + date range: `SELECT COUNT(*) ... EXISTS(...)` matches `recordsFiltered` | DB-parity |
| AC-4 | Sort by Sisa Tagihan (`order[0][column]=7`): compare against `grand_total - total_dibayar` computed in SQL; confirm the 7 known-negative rows sort correctly at either end (not treated as 0) | DB-parity + headless |
| AC-5 | Search box: `search[value]=<invoice fragment>` narrows to matching rows | headless |
| AC-6 | Download CSV: row count equals `recordsFiltered`; Copy/Excel/PDF/Print remain page-scoped | manual |
| AC-7 | Harian/Bulanan/Kategori tabs still work (headless, 0 console errors) | headless |
| AC-8 | Non-admin (kasir) session against the new routes gets redirected/denied, not data | HTTP |
| AC-9 | `order[0][column]=999` and an invalid date value do not error; defaults apply | HTTP |
| AC-10 | Changing the date range updates `recordsFiltered` accordingly | headless |

## 7. Risks and mitigations

- **Sisa Tagihan can be negative**: do not use `GREATEST(0, ...)` like `Transaksi::transaksiRowForJson()`'s
  `sisa`. Periode must keep the raw `grand_total - total_dibayar` subtraction, both as the SQL `ORDER BY`
  expression and as the derived key in `periodeSortRows()` for the archive-merge path. `SQL_SISA_PERIODE` uses
  `COALESCE(total_dibayar, 0)` so the SQL and PHP copies agree when the nullable column is NULL.
- **Unbounded archive pull (implemented)**: the archive is not fetched in full. `periodeData()` gets the
  archive count via `TransaksiArchiveService::countTransaksiPeriodeMentah()` and, only when it is non-zero,
  fetches the top `start + length` rows via `getTransaksiPeriodeMentah()`, ordered by the same column and with
  the same keyword filter, so the merged page is correct without loading the whole archive. The live fetch is
  likewise bounded to `start + length`. `periodeExport()` intentionally fetches the full set (no limit).
- **Archive/detail parity (implemented)**: `getTransaksiPeriodeMentah()` requires
  `EXISTS (detail_transaksi_archive)`, matching `processDetailTransaksi()`'s skip of detail-less transactions,
  so `recordsFiltered`/CSV do not diverge once the archive is populated.
- **Stored XSS in the new render**: DataTables writes `columns.render` output via `innerHTML`. The new
  `initPeriodeTable()` string columns use `fnTeks()` (`$('<div>').text(v).html()`) so a kasir-supplied
  `pelanggan.nama` cannot inject markup into the admin's session.
- **Export memory (implemented)**: `periodeExport()` streams rows to `php://output` instead of building the
  whole CSV in memory, and rounds money to integers to match the previous export format.
- **Admin guard**: the new endpoints must explicitly check `role === 'admin'`; the `auth` route filter alone is
  not sufficient (it only requires login, not the admin role).
- **`no_order` formatting**: do not call `format_no_order()` here; render the raw value to match
  `processDetailTransaksi()` (`Laporan.php:1071`) exactly.
- **No category filter**: this tab never sends `kategori_id`; `periodeFilterBag()` must not accept it.

## 8. Not yet verified

- Archive-merge behavior once the SQLite archive is non-empty (currently empty database-wide). The new archive
  SQL (EXISTS + `COALESCE` order + LIMIT, and the keyword filter) was validated directly against the archive
  SQLite file via PDO and returns the expected 0 rows; the CodeIgniter query-builder rendering of that path has
  not been exercised live because the archive has no rows.
- Whether any caller other than `app/Views/laporan/index.php` uses `POST /laporan/get-data` with
  `jenis=periode` — a repository grep found none as of 2026-10-02, so the legacy branch is left in place
  (not deleted) rather than assuming it is provably dead.

## 9. Approval (Gate 2)

- [x] Approved by: user (session 2026-10-02) — Option A