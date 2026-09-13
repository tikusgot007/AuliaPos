# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

@AGENTS.md

## What this is

**AULIA** — a single-shop Point of Sale for a print / photo / banner business, built on **CodeIgniter 4** (PHP 8.2+), MySQL/MariaDB (`aulia_kasirdb`), served under XAMPP at `http://localhost/aulia/`. UI language and domain vocabulary are Indonesian (`transaksi`, `pelanggan`, `produk`, `kasir`, `tagihan`, `pembayaran`, `jadwal`). Current branch: `v2.x`.

**Business-rules documentation (POS core, transaksi/kasir/jadwal/dll):** primary reading is `docs/AULIA-01-transaksi-dan-kasir.md`, `docs/AULIA-02-modul-pendukung.md`, and `docs/AULIA-CHANGELOG.md` — read the relevant section before changing transaction lifecycle, payment, cash, tagihan, or reporting logic; they record decisions, rejected alternatives, and past bugs. `docs/aturan-bisnis-AULIA.md` (the original ~2000-line monolith) is kept as an **archived numbering reference only** — code comments still cite it by section (e.g. "lihat Section 4.2"), and the new files cite it back via `[AULIA §N]`; don't edit/renumber it, add new content to the split files instead.

**Business-rules documentation (Chat/Inbox WhatsApp, branch `v3.0`):** primary reading is `docs/CHAT-01-aturan-bisnis-inbox.md` (rules per topic: identity, media, assignment, Open/Closed × Assignment, Unread/Read, permissions) and `docs/CHAT-CHANGELOG.md` (implementation history per phase/tahap). `docs/aturan-bisnis-CHAT.md` is likewise kept as an archived numbering reference only (`[CHAT §N]` citations, code comments still point at it) — same rule: don't edit/renumber it, add new tahap/phases to the split files.

## Commands

```powershell
composer test                              # run full PHPUnit suite (alias for `phpunit`)
vendor\bin\phpunit --testdox               # readable output
vendor\bin\phpunit tests\unit\KalkulasiStatusPembayaranTest.php   # single file
vendor\bin\phpunit --filter testNamaMethod                        # single test

php spark migrate                          # apply DB migrations
php spark migrate:rollback
php spark db:seed AuliaPosInitialSeeder    # master/reference data only (no operational data)
php spark aulia:repair-total-dibayar       # reconcile transaksi.total_dibayar cache from pembayaran
```

Tests requiring a database run against the connection in `phpunit.dist.xml` (`database.tests.*`, commented out by default). Unit tests under `tests/unit/` are pure and need no DB — the calculation services are deliberately stateless so they test without a framework bootstrap. Test suites: `tests/unit/` (pure), `tests/database/` (`CIUnitTestCase` + `DatabaseTestTrait`), `tests/session/` (feature/HTTP with session). Test-only migrations/seeds live in `tests/_support/`.

For hosting without CLI access, `/migrasi-manual` (admin-only web route) runs migrations, and `/archive-transaksi` moves old transactions to a separate SQLite DB via `TransaksiArchiveService`.

## Architecture

**Centralized lifecycle rules ("Opsi B", business-rules doc Section 16).** Controllers and API endpoints must NOT invent their own status-transition or payment rules. All transaction-status transitions flow through **`TransaksiModel::ubahStatus()`** — it is the *only* writer of `status='selesai'` in the codebase. Payment writes funnel through **`TransaksiModel::tambahPembayaran()`** (single chokepoint for POS, add-payment, tagihan settlement, method correction). View-level button hiding is only "layer 1"; the backend re-validates role, ownership (`kasir_id`), `sumber`, payment status, and transaction status every time.

**Two independent status axes** (never conflate them):
- `transaksi.status`: `proses` → `selesai` (final) or `batal`. Also `mangkrak` (stalled, admin-only, reactivate to `proses`). `diambil` is retired.
- `transaksi.status_pembayaran`: `belum_bayar` / `dp` / `lunas`, computed from active payments. `lunas` never auto-promotes to `selesai` — an explicit action is still required.
- `proses → selesai` requires `status_pembayaran = lunas` AND either admin (general workflow, `/api/ubah-status`) or the owning kasir via the POS-only endpoint `/api/kasir/selesaikan-transaksi` (Section 4.2).

**Stateless calculation services** (`app/Services/`), each with a matching `tests/unit/` test — the single source of truth for logic that was previously duplicated across controllers:
- `KalkulasiStatusPembayaran::hitung(totalDibayar, grandTotal)` → payment status string.
- `KalkulasiDiskonTransaksi::hitung(subtotal, persenPelanggan, diskonManual)` → diskon, grand_total, rounding. Two discount modes never combine (customer-% vs manual); customer % is always re-read fresh from `pelanggan.diskon`, never trusted from the request. Grand total floors to Rp100; remainder stored in `selisih_pembulatan`.
- `CashBalanceService` — real-time cash balance from `cash_expense` + `pembayaran` (cash, `status=aktif`) by date; there is no running-ledger table.
- `TransaksiArchiveService` — old-transaction archival to SQLite.

**Payment history is append-only / auditable.** Method corrections don't mutate `pembayaran.metode`; the old row goes `status=reversed`, a new `status=aktif` row is inserted. Only `status=aktif` payments count toward totals. `transaksi.total_dibayar` is a denormalized cache kept in sync by `sinkronkanPembayaran()`; `aulia:repair-total-dibayar` repairs drift.

**Backdated payments** (Section 11): `tambahPembayaran()` treats a `tanggal` >60s from server time as backdate → admin-only, cannot predate the transaction's date (compared at day granularity), cannot be in the future. `pembayaran.tanggal` = when money was received; `kasir_id` = who actually handled it; `created_at` = DB row creation (never touched by app code).

**Auth** — session-based, no library. `app/Filters/AuthFilter.php` (alias `auth`, applied per-route in `Routes.php`, not globally): login check, 30-min idle timeout, and a role gate. Roles are `admin` / `kasir`. `AuthFilter::$adminRoutes` is a URI-prefix list (`laporan`, `produk`, `kategori`, `jadwal`, `user-management`, `migrasi-manual`, `archive-transaksi`, …) — kasir is redirected away. Route prefixes are chosen deliberately to fall inside/outside this list (e.g. `/roster` is a kasir-visible read-only view of `/jadwal` data, kept on a separate prefix on purpose).

**Routing** — all routes are explicit in `app/Config/Routes.php` (no auto-routing); default controller `Kasir`. `Api.php` returns JSON for the AJAX-heavy POS/kasir screens.

**Jadwal (employee scheduling) module** — `Jadwal` controller, `jadwal` / `master_jadwal` / `master_jadwal_detail` tables. Deliberately independent from the transaction/kasir domain: no shared tables, no shared rules (Section 20).

**Printing** — `Cetak` controller renders `app/Views/cetak/*` (nota, thermal, ticket); `dompdf` for PDF, `mike42/escpos-php` for ESC/POS. Direct-print settings (`printnota.*`, SumatraPDF path, printer share) come from `.env` / `app/Config/PrintNota.php`.

**Schema** — the baseline is one migration, `2026-09-08-000001_CreateAuliaPosCore.php` (raw `CREATE TABLE` from the verified v2.0 DB, not incremental ALTERs), plus two later `ADD COLUMN` migrations. Two DB views: `v_daftar_pembayaran`, `v_pembayaran_item_harian` (proportional per-item payment allocation for reports). Framework owns the `migrations` table.

## Conventions

- Frontend logic for the kasir/POS screens is shared JS in `public/assets/js/` (`kasir-shared.js`, `payment.js`) used by both `kasir/index.php` and `kasir/edit.php` — those two views are byte-identical for shared sections; change both.
- Views extend `app/Views/layout/main.php`.
- Comments and the business-rules doc are in Indonesian; match that when editing them.
