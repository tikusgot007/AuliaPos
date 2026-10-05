# Design: Payment and final transaction correction

- **Date**: 2026-10-05
- **Status**: draft
- **Requirements**: `docs/requirements/2026-10-05-koreksi-transaksi-final.md`
- **SDLC tier**: A

## 1. Summary

Keep payment-method correction available to every authenticated cashier, but preserve the original payment's business timestamp and cashier identity. Record the correction operator in a dedicated immutable correction-history table.

For a wrong quantity/price discovered after `SELESAI`, use a dedicated final-transaction correction flow: atomically cancel the original transaction, create a replacement transaction with corrected details, and explicitly link the two records. Do not reuse `merged_into`.

## 2. Current flow (verified)

### Payment-method correction

    POST /api/koreksi-pembayaran
      -> Api::koreksiPembayaran()                 (app/Controllers/Api.php:546+)
      -> PembayaranModel::update()/TransaksiModel::tambahPembayaran()
      -> TransaksiModel::sinkronkanPembayaran()

Route:

    POST /api/koreksi-pembayaran
      -> Auth filter only                           (app/Config/Routes.php)

UI:

    transaksi/detail.php
      -> bukaKoreksiPembayaran()
      -> POST /api/koreksi-pembayaran

The replacement payment currently receives current `tanggal` and current session `kasir_id`. This is the BL07 audit defect.

### Existing final-transaction flow

    POST /api/ubah-status
      -> Api::ubahStatus()
      -> Authority::isCurrentShiftLeader()
      -> TransaksiModel::ubahStatus()

`SELESAI -> BATAL` is already supported for Admin/Shift Leader. Normal edit remains limited to `PROSES`.

Payment reports/cash:

    v_daftar_pembayaran / v_pembayaran_item_harian
      -> pembayaran aktif + transaksi != batal

    CashBalanceService::getCashSales()
      -> pembayaran aktif + tunai + transaksi != batal

    TransaksiArchiveService
      -> preserves reversed payment rows in archive history

## 3. Options

- **A. Add correction metadata directly to `pembayaran` and add a self-link on `transaksi`**: fewer tables, but mixes original payment facts with correction metadata and complicates multiple successive corrections.
- **B. Dedicated immutable correction-history tables**: keeps payment/transaction facts clean, supports repeated audit events, and survives archive deletion without relying on live rows.

**Recommendation:** B, because correction history is an audit event, not a mutable property of the payment itself.

## 4. Planned changes

| File | Change | Reason |
|---|---|---|
| `app/Database/Migrations/...` | Add payment-correction audit table and final-transaction-correction relation/table as designed; preserve archive compatibility. | Persist audit without overwriting original facts. |
| `app/Models/PembayaranModel.php` | Add a focused correction operation/chokepoint used by `Api::koreksiPembayaran()`. | Keep reversal + replacement + audit atomic. |
| `app/Models/TransaksiModel.php` | Add focused final-correction operation that clones validated detail data, cancels original, creates replacement, and synchronizes payment state. | Keep state/data integrity in the model transaction boundary. |
| `app/Controllers/Api.php` | Refactor `koreksiPembayaran()` to the correction operation; add dedicated final-correction endpoint. | Thin controller and consistent validation. |
| `app/Config/Routes.php` | Add final-correction route with existing authentication plus controller-level Admin/Shift Leader authorization. | Preserve BL07 cashier access while protecting final transaction replacement. |
| `app/Views/transaksi/detail.php` | Keep payment-method correction visible to cashiers; add final-correction action only when eligible. | UI must reflect distinct permissions. |
| `app/Services/TransaksiArchiveService.php` | Ensure correction history remains queryable after archive; do not cascade-delete audit history. | Historical traceability. |
| `app/Controllers/Laporan.php` / relevant report queries | Verify no active-payment or active-sales query accidentally includes the canceled original. | Prevent double counting. |
| `docs/CHANGELOG.md` | Document the new business rules. | Required by AGENTS.md §15. |
| `docs/TODO.md` | Keep BL07 until verified; add the newly discovered final-transaction correction gap. | Single backlog source of truth. |
| `tests/...` | Add executable tests mapped to AC-1..AC-10. | Regression coverage for money/state behavior. |

### Payment correction semantics

1. Lock/read the active original payment and transaction inside one DB transaction.
2. Reverse the original payment.
3. Create the replacement payment with:
   - same `tanggal` as the original payment;
   - same `kasir_id` as the original payment;
   - same amount/cash fields unless the method semantics require an explicit normalized value;
   - new method;
   - current database `created_at`.
4. Insert a correction-audit row containing transaction ID, old payment ID, new payment ID, old method, new method, correction operator, correction time, and reason.
5. Recompute transaction payment summary.
6. Commit all changes atomically.

The correction operator is never substituted into the original payment's cashier field.

### Final transaction correction semantics

1. Require original transaction status `SELESAI`.
2. Require Admin or current Shift Leader.
3. Validate corrected detail rows with the existing `ValidasiItemTransaksi` path.
4. Create the replacement transaction with a new invoice identity and the corrected totals/details.
5. Reverse/deactivate the original active payments as part of the same transaction.
6. Create replacement payments only according to the explicit correction payload; do not silently invent a refund.
7. Mark the original transaction `BATAL`.
8. Insert an immutable correction relation containing original transaction ID, replacement transaction ID, operator, timestamp, reason, and financial delta.
9. Synchronize both transaction payment summaries.
10. Commit; any failure rolls back the complete correction.

The replacement transaction is a new business transaction. It must not reuse `merged_into`.

## 5. Impact

- **Database / migrations**: additive schema only. Existing payment and transaction rows remain valid. Audit rows must not have cascading foreign keys that erase history when operational rows are archived.
- **Routes / API / response formats**: existing `POST /api/koreksi-pembayaran` remains available to authenticated cashiers; its success response should remain backward-compatible. Final correction uses a new endpoint.
- **Gateway contract**: unchanged; other side not involved.
- **Existing data**: no backfill is required for historical corrections because prior operator information was not stored separately. Existing rows remain untouched.
- **Security / validation**: payment-method correction remains cashier-accessible by explicit product decision; final transaction correction is Admin/Shift Leader only. Both endpoints validate IDs, transaction/payment ownership, allowed methods, status, corrected detail values, and reason length.
- **Transactions / concurrency / rollback**: use one DB transaction per correction; lock/re-check the original state before mutation to prevent two simultaneous corrections of the same payment/transaction.

## 6. Test plan

| Acceptance criterion | Test | Type |
|---|---|---|
| AC-1 | `tests/integration/PaymentCorrectionTest.php::test_AC1_cashier_can_change_payment_method` | integration |
| AC-2 | `tests/integration/PaymentCorrectionTest.php::test_AC2_original_payment_identity_is_preserved_and_operator_is_audited` | integration |
| AC-3 | `tests/integration/PaymentCorrectionTest.php::test_AC3_same_method_is_rejected_without_mutation` | integration |
| AC-4 | `tests/integration/FinalTransactionCorrectionTest.php::test_AC4_final_transaction_still_rejects_normal_edit` | integration |
| AC-5 | `tests/integration/FinalTransactionCorrectionTest.php::test_AC5_creates_replacement_and_links_original` | integration |
| AC-6 | `tests/integration/FinalTransactionCorrectionTest.php::test_AC6_rolls_back_original_and_replacement_on_failure` | integration |
| AC-7 | `tests/integration/FinalTransactionCorrectionTest.php::test_AC7_payment_totals_do_not_double_count_original` | integration |
| AC-8 | `tests/integration/FinalTransactionCorrectionTest.php::test_AC8_lower_replacement_total_does_not_auto_refund` | integration |
| AC-9 | `tests/integration/FinalTransactionCorrectionTest.php::test_AC9_reports_ignore_canceled_original_payment` | integration |
| AC-10 | `tests/integration/FinalTransactionCorrectionTest.php::test_AC10_correction_history_survives_archive_cleanup` | integration |

Tests must use the existing test DB/SQLite group and must not connect to production/dev DB.

## 7. Risks and mitigations

- **Double-counting payment** -> original transaction becomes `BATAL` and its active payments are reversed before replacement payments are considered.
- **Lost audit after archive** -> correction history has no destructive FK dependency on archived operational rows.
- **Two simultaneous corrections** -> transaction + row locks and state re-check.
- **Incorrect refund accounting** -> no automatic refund; existing `refund_penjualan` remains the explicit cash-out path.
- **Breaking existing cashier workflow** -> keep existing payment-correction route and response contract.
- **Misusing merge semantics** -> introduce a correction-specific relation; never repurpose `merged_into`.

## 8. Not yet verified

- Exact existing migration naming/placement convention for incremental migrations on `v2.4`.
- Exact test bootstrap/group names for the new integration tests.
- Exact report methods consuming `v_daftar_pembayaran` and `v_pembayaran_item_harian` that need assertions in AC-9.
- Whether replacement payments for final transaction correction should copy the original payment method/amount automatically or require an explicit payment correction payload from the UI. Implementation must not guess this if the existing UI contract does not provide enough information.

## 9. Approval (Gate 2)

- [ ] Approved by: <name>, date: <YYYY-MM-DD>
