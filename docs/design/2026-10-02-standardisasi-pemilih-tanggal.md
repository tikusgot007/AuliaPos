# Design: Standardize Date / Range / Period Pickers

- **Date**: 2026-10-02
- **Status**: draft
- **Requirements**: `docs/requirements/2026-10-02-standardisasi-pemilih-tanggal.md`
- **SDLC tier**: A

## 1. Summary

Introduce one canonical picker configuration (locale + presets) as a single
source of truth in `App\Config\DatePicker`, emit it once from the layout, and
consume it through one shared JS helper (`public/assets/js/date-range.js`).
Replace every ad-hoc daterangepicker config, native range input, and month
selector with calls to that helper, then unify asset loading and the range
query param name.

## 2. Current flow (verified)

Range param flows:

    GET /transaksi?tanggal_awal&tanggal_akhir
      -> Transaksi::index() -> getDateRange()      (app/Controllers/Transaksi.php:411-428)
    GET /tagihan?tanggal_awal&tanggal_akhir
      -> Tagihan::index() -> getRentangTanggal()   (app/Controllers/Tagihan.php:138-145)
    GET /laporan/item-harian?tanggal_mulai&tanggal_sampai
      -> Laporan::itemHarian()                     (app/Controllers/Laporan.php:1668-1680)
    GET /laporan-pembayaran?tanggal_mulai&tanggal_sampai
      -> Laporan::pembayaran()                     (app/Controllers/Laporan.php:1983-1991)
    POST /laporan/get-data (JSON tanggal_awal/tanggal_akhir)
      -> Laporan::getData()                        (app/Controllers/Laporan.php:48-51)

Picker initializations (config verbatim at each site):

- `app/Views/transaksi/index.php:484-512` (full locale + 6 presets; apply -> `applyFilter()` at `:643-645`; inside `$(document).ready` at `:474`)
- `app/Views/tagihan/index.php:242-271` (full locale + 6 presets; submit on apply `:273-281`)
- `app/Views/laporan/index.php:211-227` (singleDatePicker, locale partial), `:241-253` (periode, locale `format` only), `:256-268` (kategori, locale `format` only)
- `app/Views/laporan/item_harian.php:758-861` (locale partial, 5 presets, `autoApply:true`)
- `app/Views/transaksi/laporan_pembayaran.php:700-807` (locale partial, 5 presets, `autoApply:true`)
- `app/Views/cash/riwayat.php:12-23` and `app/Views/cash/pengeluaran.php:30-52` (two native `<input type="date">`)
- `app/Views/jadwal/index.php:324,327` (analysis range), `:437,441` (delete range), `:192,390,465` (single dates)
- `app/Views/cash/closing.php:15` (`type="month"`), `app/Views/roster/index.php:240-248` (prev/next), `app/Views/laporan/index.php:82-105` (Bulan+Tahun), `app/Views/archive_transaksi/index.php:40-51` (checkboxes)

JS consumers that depend on current value semantics (`YYYY-MM-DD` via `.value`):

- `public/assets/js/jadwal.js:167-169` (write analisis defaults), `:661-662` (write hapus defaults), `:667-668` & `:864-865` (read), all `YYYY-MM-DD`.
- `public/assets/js/payment.js:1121-1163` (read `.value`, set `.min`/`.max`, prefill `.value`), `:1189-1219` (read `.value`, re-validate).
- `public/assets/js/roster.js:340-350, 387-399` (month state + prev/next).

Asset loading verified:

- `app/Views/layout/main.php:21` (CSS global), `:27` jQuery, `:1601-1616` DataTables, `:1618` `renderSection('scripts')`.
- moment + daterangepicker JS loaded per-view: `laporan/index.php:172-173`, `item_harian.php:612,616`, `tagihan/index.php:188-189`, `transaksi/index.php:291,369`, `laporan_pembayaran.php:671,673`.

Resolved CDN latest (jsDelivr API, 2026-10-02): `daterangepicker@3.1.0`, `moment@2.31.0`.

## 3. Options

- **A. Canonical config in `App\Config\DatePicker` + shared JS helper.** Single
  source of truth emitted once by the layout; helper consumed by all views; the
  config is unit-testable via PHPUnit. Adds one config class + one JS file.
- **B. Canonical config only in the JS helper (`date-range.js`).** Fewer files,
  but the standard is verifiable only by manual browser checks (no JS test
  runner in this repo).
- **C. Fix each view in place without a helper.** Smallest per-file diff at
  first, but keeps duplication and re-introduces drift; does not meet the
  "seragam" goal.

**Recommendation:** A, because it gives one testable source of truth (matches the
existing `app/Services` pure-logic test pattern) and permanently removes the
duplication that caused the current drift.

Sub-decision for the risky single-date fields (surfaced here, decide at Gate 2):

- **A1.** Convert all date-only fields to `singleDatePicker`, including payment
  backdate (`#paymentBackdateTanggal`), and adapt `payment.js` (`min`/`max` ->
  picker `minDate`/`maxDate`, `.value` -> hidden input).
- **A2.** Convert jadwal and generic date-only fields, but **keep
  `#paymentBackdateTanggal` native**, because `payment.js` manipulates native
  `.min`/`.max`/`.value` inside the money flow (`payment.js:1121-1163`).

**Recommendation:** A2, because it removes money-flow risk while still
standardizing the rest; `#paymentBackdateTanggal` becomes a documented exception.

## 4. Planned changes

Work packages (one Gate-2 approval; implemented and reviewed in order).

**WP1 - Foundation**

| File | Change | Reason |
|---|---|---|
| `app/Config/DatePicker.php` (new) | Canonical `locale`, ordered `presets` (labels+keys), `bulanYm(int,int)` helper | Single source of truth; testable |
| `public/assets/js/date-range.js` (new) | `AuliaDateRange.initRange()`, `initSingle()`, `setRange()`, `AuliaMonthPicker` | One place to build pickers |
| `app/Views/layout/main.php` | Add pinned `moment@2.31.0` + `daterangepicker@3.1.0` JS before `:1618`; pin CSS `:21`; emit `window.AULIA_DATEPICKER`; include `date-range.js` | AC-6, single source |
| `app/Controllers/Laporan.php` | `itemHarian()` `:1670,1677` and `pembayaran()` `:1984,1989` read `tanggal_awal`/`tanggal_akhir` | AC-5 |
| `tests/unit/DatePickerConfigTest.php` (new) | Assert locale fields, preset order, `bulanYm` | AC-1, AC-2, AC-10 |

**WP2 - Range pickers**

| File | Change | Reason |
|---|---|---|
| `app/Views/transaksi/index.php` | Use helper (config already near-standard); remove `:290-291,369` includes | AC-1..AC-3, AC-6 |
| `app/Views/tagihan/index.php` | Use helper; remove `:188-189` includes | AC-1..AC-3, AC-6 |
| `app/Views/laporan/index.php` | Helper for harian/periode/kategori; fix locale + preset + `autoApply`->Terapkan; remove `:171-173` includes | AC-1..AC-4, AC-6 |
| `app/Views/laporan/item_harian.php` | Helper; param names -> `tanggal_awal/akhir`; hidden inputs renamed; remove `:609-616` includes | AC-1,2,3,5,6 |
| `app/Views/transaksi/laporan_pembayaran.php` | Helper; param names -> `tanggal_awal/akhir`; hidden inputs renamed; remove `:671,673` includes | AC-1,2,3,5,6 |
| `app/Views/cash/riwayat.php` | Replace two native dates with helper + hidden `tanggal_awal/akhir` | AC-7 |
| `app/Views/cash/pengeluaran.php` | Replace range with helper + hidden; keep `datetime-local` at `:122` | AC-7, AC-9 |
| `app/Views/jadwal/index.php` | Range pairs (`analisisStart/End`, `hapusRangeStart/End`) -> helper, hidden inputs keep IDs + `YYYY-MM-DD` | AC-8 |
| `public/assets/js/jadwal.js` | Use `AuliaDateRange.setRange()` at `:167-169, 661-662`; reads unchanged | AC-8 |

**WP3 - Single date & month**

| File | Change | Reason |
|---|---|---|
| `app/Views/jadwal/index.php` | `cariTanggalMatrix`, `tambahTanggal`, `applyStartMinggu` -> `singleDatePicker` with hidden inputs keeping IDs | AC-9 |
| `public/assets/js/jadwal.js` | Programmatic writes to those IDs use helper setters | AC-9 |
| `app/Views/components/payment/modal.php` | Keep `#paymentBackdateTanggal` native (A2 exception) | AC-9 |
| `app/Views/components/month_year_picker.php` (new) | Reusable Bulan+Tahun partial | AC-10 |
| `app/Views/cash/closing.php` | Replace `type="month"` with partial; submit computes `YYYY-MM` | AC-10 |
| `app/Views/roster/index.php` + `public/assets/js/roster.js` | Add dropdown (partial); keep prev/next syncing it | AC-10 |
| `app/Views/laporan/index.php` | Refactor existing month dropdowns to the partial | AC-10 |

`archive_transaksi/index.php` is intentionally unchanged (multi-select, AC-10).

## 5. Impact

- **Database / migrations**: none.
- **Routes / API / response formats**: no route changes. `item_harian` and
  `laporan_pembayaran` GET param names change from `tanggal_mulai/sampai` to
  `tanggal_awal/akhir` (AC-5). Old bookmarks fall back to the page default.
  No verified external consumer (no tests/docs reference them).
- **Gateway contract**: none (POS <-> WA Gateway untouched).
- **Existing data**: none modified; report totals unchanged.
- **Security / validation**: controller date validation unchanged; helper only
  sets client values; server remains authority. Values sent stay `YYYY-MM-DD`.
- **Transactions / concurrency / rollback**: none.
- **Performance**: global moment+daterangepicker (~80 KB) now loads on all pages;
  cached by the browser. No per-view duplicate loads remain.

## 6. Test plan

| Acceptance criterion | Test | Type |
|---|---|---|
| AC-1 locale, AC-2 presets, AC-10 `bulanYm` | `tests/unit/DatePickerConfigTest.php` | unit (PHPUnit) |
| AC-5 param rename | `php spark serve` + GET both pages with new params; assert applied period text | manual / smoke |
| AC-1,2,3,6 (all range pages) | Open each page: one Indonesian locale, 6 presets, must click Terapkan, single asset load (Network tab) | manual browser |
| AC-4 defaults | Open each page: default range matches Requirement AC-4 | manual browser |
| AC-7 Cash range | Open `/cash/riwayat` and `/cash/pengeluaran`: one range picker; filter returns same rows as before | manual browser |
| AC-8 Jadwal range | Open Jadwal analysis + delete-range: picker works; request `start`/`end` correct | manual browser |
| AC-9 single date | Backdate stays native; jadwal single dates show picker; requests send `YYYY-MM-DD` | manual browser |
| AC-10 month | Closing/Roster/Laporan month switch works; archive checkboxes unchanged | manual browser |
| AC-11 no regression | Compare totals before/after on same range; no console errors | manual browser |

Tests must not connect to the dev/production database (`phpunit.xml` bootstraps
autoload only). Interactive checks are listed as manual because there is no JS
test runner in this repo (AGENTS.md 10 allows manual browser verification for UI).

## 7. Risks and mitigations

- `payment.js` depends on native `.min`/`.max`/`.value` in the money flow -> A2 keeps
  backdate native; no change to payment.js.
- `jadwal.js` is timezone-sensitive and writes `.value` directly -> keep hidden
  inputs with the same IDs and `YYYY-MM-DD`; only writes go through helper setters.
- Library load order -> place moment/daterangepicker before `renderSection('scripts')`
  (`layout/main.php:1618`); all inits run in `$(document).ready` (DOMContentLoaded,
  after bottom scripts).
- Version pin changes behavior -> pin to the versions currently resolved by the
  unpinned URLs (`moment@2.31.0`, `daterangepicker@3.1.0`).
- Param rename breaks old bookmarks -> accepted (AC-5); documented in CHANGELOG.
- Scope is large (~16 files) -> implement in WP1/WP2/WP3, verify each before the next.

## 8. Not yet verified

- Exact apply handlers in `item_harian.php` and `laporan_pembayaran.php` and
  whether removing `autoApply` preserves their current submit behavior.
- All `daterangepicker()` inits are inside `$(document).ready` (assumed from
  `transaksi`; to confirm per view).
- All `payment.js` / `jadwal.js` programmatic write sites beyond those found.
- `roster.js` full surface for replacing/augmenting month navigation.
- Whether `laporan/index.php` month refactor to the shared partial is worth the
  diff (it is already the reference implementation).

## 9. Approval (Gate 2)

- [ ] Approved by: <name>, date: <YYYY-MM-DD>
