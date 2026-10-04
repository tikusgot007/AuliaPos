# Design: POS transaction item validation (TODO-BL03)

- **Date**: 2026-10-04
- **Status**: approved
- **Requirements**: `docs/requirements/2026-10-04-validasi-item-transaksi.md`
- **SDLC tier**: A

## 1. Summary

Add one pure service, `App\Services\ValidasiItemTransaksi::normalisasi()`, that
validates and normalizes every cart line (positive `jumlah`, non-negative
`harga`/`subtotal`, and `subtotal = harga × jumlah` — except banners, where
`subtotal` is canonical and `harga` is derived). Both the create and edit
controllers consume the normalized items and the normalized subtotal, removing
the duplicated unvalidated loops.

## 2. Current flow (verified)

Create:

    POST /api/simpan-transaksi
      -> Api::simpanTransaksi()                    (app/Controllers/Api.php:78)
           $keranjang = request JSON               (Api.php:86)
           per item: subtotal summed raw           (Api.php:167-170)
           detail built from raw item fields       (Api.php:337-347)

Edit:

    POST /transaksi/update/{id}  (route -> Transaksi::updateTransaksi)
      -> Transaksi::updateTransaksi()              (app/Controllers/Transaksi.php:1287)
           per item: subtotal summed raw           (Transaksi.php:1395-1399)
           detail built from raw item fields       (Transaksi.php:1489-1499)

Both call `App\Services\KalkulasiDiskonTransaksi::hitung()` (single source of
truth for discount) after the subtotal is known — that part is already shared and
stays unchanged.

Legitimate item shapes (verified in the client):

- catalog / manual / custom: `subtotal = harga × jumlah` exactly
  (`public/assets/js/kasir-shared.js:406-448`, `app/Views/kasir/modal_produk.php:261-299,362-375,489-496,566-581`).
- banner: `subtotal` entered by the cashier, `harga = round(subtotal / jumlah)`
  (`kasir-shared.js:1134-1136`) — the only case where `harga × jumlah` may differ.

## 3. Options

- **A. Shared pure validator, banner canonical, strict reject for the rest
  (recommended)**: reject `jumlah <= 0`, negative values, and non-banner
  `subtotal != harga × jumlah`; for banners keep `subtotal` and set
  `harga = round(subtotal / jumlah)`; return normalized items + subtotal. Both
  controllers use it.
  - Pro: one source of truth; surfaces malformed input instead of storing it;
    handles the banner exception explicitly.
  - Con: a malformed client is rejected (intended).
- **B. Shared validator that silently recomputes `subtotal = harga × jumlah` for
  non-banner**: never rejects a mismatch.
  - Con: hides client bugs and makes tampering invisible; still needs the same
    service. Weaker.
- **C. Inline validation duplicated in both controllers**: rejected — the two
  copies would drift (same root cause as this bug).

**Recommendation:** A, because it centralizes the rule (AC-6) and rejects
nonsensical input rather than persisting it, while explicitly preserving the one
legitimate exception (banner).

## 4. Planned changes

| File | Change | Reason |
|---|---|---|
| `app/Services/ValidasiItemTransaksi.php` (new) | Pure `normalisasi(array $keranjang): array{items: array, subtotal: float, error: ?string}`. | Single source of truth; unit-testable without framework/DB. |
| `app/Controllers/Api.php` | After the empty-cart check, normalize the cart; on error return JSON error; use normalized items + subtotal (`Api.php:128-170`, detail `:337-347`). | Apply validation to the create path. |
| `app/Controllers/Transaksi.php` | Same in `updateTransaksi()` (`Transaksi.php:1360-1399`, detail `:1489-1499`). | Apply the same rule to the edit path (shared root cause). |
| `tests/unit/ValidasiItemTransaksiTest.php` (new) | Rule tests for AC-1..AC-5, AC-7. | Executable verification. |
| `docs/CHANGELOG.md` | Log the new input rule (Bahasa Indonesia). | Business-rule change (AGENTS.md §15). |

Normalization rules (proposed, per Q1/Q2/Q3):

- `jumlah`: numeric, finite, `> 0`, `<= 9999` (Q3) — else error.
- `harga`: numeric, finite, `>= 0` — else error.
- `subtotal`: numeric, finite, `>= 0` — else error.
- `is_banner === true`: `subtotal > 0` required; `harga := round(subtotal / jumlah)`;
  line subtotal := `subtotal` (Q1).
- otherwise: require `abs(subtotal - harga × jumlah) <= 0.01` (Q2: reject on
  mismatch); line subtotal := `harga × jumlah`.
- returned `subtotal` = sum of line subtotals.

Other item keys (`produk_id`, `kategori_id`, `nama`, `is_banner`, `detail`, ...)
are preserved so `DetailTransaksiModel::catatanBanner($item)` keeps working.

## 5. Impact

- **Database / migrations**: none. `detail_transaksi.subtotal`/`harga_satuan`
  keep their types; only the values are normalized.
- **Routes / API / response formats**: routes and success payloads unchanged.
  New failure mode: `{status:error, message:"..."}` with the existing shape when
  an item is invalid (the controller already returns that shape elsewhere).
- **Gateway contract**: not involved.
- **Existing data**: not migrated. Only newly written transactions are affected.
  Legitimate clients (catalog/manual/custom/banner) keep working.
- **Security / validation at trust boundaries**: adds server-side validation of
  client-supplied money/qty fields — the point of the change. No new trust.
- **Transactions / concurrency / rollback behavior**: unchanged; edit still runs
  in one DB transaction.

## 6. Test plan

| Acceptance criterion | Test (file::method) | Type |
|---|---|---|
| AC-1 | `tests/unit/ValidasiItemTransaksiTest.php::testJumlahHarusPositif` | unit |
| AC-2 | `...::testNilaiNegatifDitolak` | unit |
| AC-3 | `...::testSubtotalTidakKonsistenDitolak` | unit |
| AC-4 | `...::testBannerSubtotalJadiAcuan` | unit |
| AC-5 | `...::testItemValidMengembalikanSubtotalKonsisten` | unit |
| AC-7 | `...::testDiskonDihitungDariSubtotalHasilNormalisasi` | unit |
| AC-6 | verified structurally: both controllers call the single service (no second copy); asserted in review, not by a test. | review |

Endpoint-level manual check (both create & edit) is listed as a manual
verification step; no lightweight DB-backed controller test exists for these
endpoints and adding one is out of this change's scope.

## 7. Risks and mitigations

- <Banner rejected by a too-strict rule> -> banner path is explicit and tested
  (AC-4).
- <A legitimate but slightly inconsistent client payload now fails> -> rule uses
  an exact `<= 0.01` tolerance; the existing client is exactly consistent for
  non-banner items, so normal sales are unaffected.
- <Two controllers drift> -> both must call the one service; the edit path's
  duplicated subtotal loop is removed (AC-6).
- <Rounding when recomputing banner `harga`> -> `harga_satuan` is set to
  `round(subtotal / jumlah)`; the line subtotal (the financially significant
  value) stays the cashier's total.

## 8. Not yet verified

- No production evidence of manipulated payloads (analysis is from code only).
- Whether any external/off-platform client posts `keranjang` with its own
  invariants (only the browser JS is in this repo).

## 9. Approval (Gate 2)

- [x] Approved by: user (chat), date: 2026-10-04
