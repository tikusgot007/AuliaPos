# Design: Customer identity flow (Kasir & Inbox)

- **Date**: 2026-10-19
- **Status**: draft
- **Requirements**: `docs/requirements/2026-10-19-perbaikan-alur-identitas-pelanggan-kasir-inbox.md` (Gate 1 approved 2026-10-19)
- **SDLC tier**: A

## 1. Summary

Phone number becomes the customer identity; the typed name is only a label. One server-side resolver, shared by `Api::simpanTransaksi()` and `Transaksi::update()`, decides which `pelanggan` row a sale belongs to. The Kasir UI stops auto-selecting and silently clearing the selection; Inbox `buatPesanan()` gains a "same person?" confirmation for name matches without a phone. A new nullable `transaksi.atas_nama` stores the name typed at the till when it differs from the master name.

## 2. Current flow (verified, read this session)

Kasir UI (`public/assets/js/kasir-shared.js`):

    input #namaPelanggan -> handleCustomerNameInput()      (:766)
      -> pelangganTerpilih = null, telp cleared + readOnly=false   (:769-778)
      -> searchPelanggan()                                  (:787)
         -> exact name match => pilihPelanggan() automatically (:818-832)
    pilihPelanggan()   (:966) -> telp readOnly = true (:986), pelangganTerpilih set (:997)
    editTelpPelanggan() (:871) -> window.location.href = editPelanggan/{id}?return=kasir (:888)
    restorePelangganFromUrl() (:921) reads pelanggan_id/_nama/_telp/_diskon from Inbox URL (:924-927)

Save paths (two copies of the same decision, already inconsistent):

    Api::simpanTransaksi()  (app/Controllers/Api.php:200-226)
      pelanggan === 'new' -> PelangganModel::findByName(nama) (:209) -> reuse old id, new phone ignored
                             else insert {nama, no_hp} (:212)
      piutang without pelanggan -> error (:228)
      transaksi row written with pelanggan_id (:330)
    Transaksi::update()     (app/Controllers/Transaksi.php:1446-1461)
      pelanggan === 'new' -> always insert (:1447), no findByName
      transaksi row written with pelanggan_id (:1582)

Inbox "Buat Pesanan" (`app/Controllers/Inbox.php`, `buatPesanan()` ~3290-3514):

    1. conversation.pelanggan_id link              (:3340)
    2. PelangganModel::findByNomor(nomor)          (:3349-3351)   <- only phone, never name
    phase 1 (no nama input): returns butuh_nama with usulan (:3365-3416)
    phase 2: name = bersihkan(nama) + ' ' + digit4 (:3426-3441)   (digit from server)
      existing pelanggan  -> nama ALWAYS overwritten with the typed name (:3467-3478)   <- Alby would rename Suherman
      no pelanggan        -> insert (:3450-3455)
    redirect to Kasir with pelanggan_id/_nama/_telp/_diskon (:3521-3538)

Model: `PelangganModel` (app/Models/PelangganModel.php): `findByName` (:29), `findByNomor` (:51, canonical `62...` via `PhoneNumber::normalize`). `pelanggan.no_hp` is free text, no unique index. `transaksi` has no note column (migration `2026-09-08-000001_CreateAuliaPosCore.php:157-184`); `diskon_pelanggan_persen` was added the same way we add a column (`2026-09-09-000002_AddDiskonPelangganPersenTransaksi.php:28-34`).

## 3. Options

- **A. Shared server resolver + UI changes (phone = identity)**: one method decides the customer for both save paths; UI sends id/name/phone as typed; server never trusts the client's guess. Fixes the Api/Transaksi inconsistency by construction. One migration.
- **B. Patch JS only**: remove auto-select and keep selection on rename; leave the two backends as they are. No migration, but duplicate/hijack bugs (`Api.php:209`) and "atas nama" remain.
- **C. Unique index on `no_hp`**: DB-enforced identity. Rejected: existing duplicate/free-text numbers would break the migration.

**Recommendation:** A. B leaves the silent reuse by name; C needs a data cleanup nobody asked for.

## 4. Planned changes

| File | Change | Reason |
|---|---|---|
| `app/Database/Migrations/2026-10-19-000001_AddAtasNamaToTransaksi.php` | `ALTER TABLE transaksi ADD COLUMN atas_nama VARCHAR(100) NULL AFTER pelanggan_id`; `down()` drops it | AC-10 |
| `app/Libraries/PelangganResolver.php` (new) | `resolve($pelangganId, $nama, $telp): array{pelanggan_id, atas_nama}` (rules in 4.1) | AC-3, 5, 8, 10 |
| `app/Models/PelangganModel.php` | add `findTanpaNomorByNama(string $nama)` (same name, `no_hp` NULL or empty, case-insensitive via collation); no change to `findByName` | AC-6 |
| `app/Controllers/Api.php` (:200-226) | replace the `'new'` branch with `PelangganResolver::resolve()`; piutang check uses resolved row's `no_hp`; write `atas_nama` (:330 area) | AC-5, 10 |
| `app/Controllers/Transaksi.php` (:1446-1461) | same resolver; write `atas_nama` (:1582 area) | consistency |
| `app/Models/TransaksiModel.php` (if it filters `$allowedFields`; not yet verified) | add `atas_nama` | AC-10 |
| `public/assets/js/kasir-shared.js` | (a) delete exact-match auto-select (:818-832); (b) `handleCustomerNameInput` keeps `pelangganTerpilih` and the phone value, only re-searches; (c) dropdown: first row highlighted, Arrow Up/Down + Enter select, last row `+ Jadikan Pelanggan Baru: "<nama>"` sets id to `'new'`; (d) `telpPelanggan` never `readOnly`; (e) remove redirect in `editTelpPelanggan` (button hidden); (f) payload sends id (or `'new'`), nama, telp as typed | AC-1..4, 10 |
| `app/Views/kasir/index.php`, `app/Views/kasir/edit.php` | drop `readonly` default on telp, hide edit-phone button | AC-3 |
| `app/Controllers/Inbox.php` `buatPesanan()` | phase 1: when no link and no number match and `$nomor !== null`, look up `findTanpaNomorByNama(bersih(contact/whatsapp name))`; if found return `status: butuh_tautan` with `{pelanggan_id, nama}`. Phase 2 accepts `tautkan_pelanggan_id` (re-checks id still has empty `no_hp` and same base name, then fills `no_hp`, applies `NAMA 4DIGIT`) or `buat_baru=1`. Phase 1 `butuh_nama` additionally returns `nama_chat` (chat name) when it differs from the found pelanggan's base name | AC-6, 7, 9 |
| `public/assets/js/inbox-page.js` (not yet read) | modal step for `butuh_tautan` (two buttons) and a hint line "Chat mengaku <nama_chat>"; `atas_nama` carried in the Kasir URL | AC-6, 9 |
| `public/assets/js/kasir-shared.js` `restorePelangganFromUrl()` | read `atas_nama` param, prefill the name input with it while `pelangganTerpilih` stays the master customer | AC-9 |
| `docs/CHANGELOG.md` | business rule entry (Bahasa Indonesia) | rule change |

### 4.3 Revision 2026-10-19 (Aan approved): separate "Atas nama" field

Typing in the name box keeps today's behaviour: selection and phone are cleared (`kasir-shared.js:769-778`). Keeping the phone after a rename was rejected: "Budi" typed over a selected `SUHERMAN 1234` would silently bill Suherman (discount, piutang). Instead:

- New optional input `#atasNama` in `kasir/index.php` and `kasir/edit.php`, visible only while `pelangganTerpilih` is set, empty by default, hidden/cleared when the selection is released. Sent as `atas_nama` in the save payload.
- Resolver `$atasNama` param: stored only when a pelanggan resolved and `base($atasNama) !== base(resolved.nama)`; max 100 chars, trimmed.
- Inbox: `buatPesananSukses()` adds `atas_nama` to the Kasir URL when the chat name (contact/whatsapp name, cleaned) differs from the pelanggan's base name; `restorePelangganFromUrl()` fills `#atasNama`.
- Resolver rule 4 in 4.1 and the earlier "edit name in place" wording are superseded by this section. Requirements AC-10/11/12 updated.
- Phasing: **Tahap 1** = storage + input + Inbox prefill (this work). **Tahap 2** = `Alby(Suherman)` rendering in lists/reports/archive (`Transaksi.php`, `Laporan.php`, `Tagihan.php`, `Api.php:1040`, `TransaksiArchiveService.php` incl. its SQLite schema), separate package and tests.

### 4.2 Decisions from Aan (2026-10-19)

1. Changing a known customer's number at the till creates a new customer (OK). Number change of the same person goes through Master Pelanggan.
2. When `atas_nama` is set, wherever the customer name is shown for that transaction (receipt, invoice view, transaction list) it is rendered stacked: `<atas_nama>(<master name>)`, e.g. `Alby(Suherman)`. Rendering lives in one helper so all views share it. Exact views to touch: not yet verified (grep for `pelanggan.nama` / `nama_pelanggan` in `app/Views` before coding). This adds files to the planned changes table.

### 4.1 Resolver rules (server, in order)

Input: `$pelangganId` (numeric | `'new'` | empty), `$nama`, `$telp`. `canonical = PhoneNumber::normalize($telp)`; `base(x)` = name without trailing `\s\d{4}`, uppercase.

1. **Phone given and valid**: `owner = findByNomor(canonical)`.
   - owner found -> use owner (even if a different id was selected). 
   - not found and selected row has empty `no_hp` -> fill `no_hp`, set `nama = base(nama) . ' ' . last4(canonical)`.
   - not found otherwise (no selection, `'new'`, or selected row has a different number) -> insert new `base(nama) . ' ' . last4`.
2. **No phone**: selected numeric id -> use it (must exist); `'new'` with name >= 2 chars -> insert plain `nama` (explicit choice by the cashier, never reuse by name); empty -> `null` (walk-in).
3. **Phone given but invalid** -> error, nothing written.
4. `atas_nama = trim($nama)` only if resolved row exists and `base($nama) !== base(resolved.nama)`; else `null`. Master `pelanggan.nama` is never overwritten by a typed name once the row has a number.
5. Digits always computed from the number on the server, never taken from the client (same rule as `Inbox::buatPesanan()` :3440).

Piutang: after resolve, require `pelanggan_id !== null` AND resolved `no_hp` non-empty (AC-5).

## 5. Impact

- **Database / migrations**: one nullable column on `transaksi` (default group). Inbox DB untouched. `down()` drops the column.
- **Routes / API / response formats**: no new routes. `buatPesanan()` adds status `butuh_tautan` and request fields `tautkan_pelanggan_id`, `buat_baru`; old clients unaffected (they never send them, and `butuh_tautan` is only returned when a name candidate exists, which an old JS would not understand -> deploy JS and PHP together).
- **Gateway contract**: none; M.A.R.A not involved.
- **Existing data**: no backfill. Old plain names stay plain until their number arrives (AC-8).
- **Security / validation**: phone validated server-side via `PhoneNumber::normalize`; `tautkan_pelanggan_id` re-verified server-side (empty `no_hp`, same base name) so a forged id cannot overwrite another customer's number. `atas_nama` trimmed, max 100, rendered with `esc()`.
- **Transactions / concurrency**: resolver runs inside the existing save transaction (Transaksi/Api, 16 files already use `transBegin`). Two tills creating the same new number at the same instant could still produce two rows (no unique index). Accepted; follow-up is a normalized phone column + unique index.

## 6. Test plan

| Acceptance criterion | Test (file::method) | Type |
|---|---|---|
| AC-1, 2, 4 | `tests/js/kasir-pelanggan.test.js` (typing an exact name does not call `pilihPelanggan`; Arrow+Enter selects; last row is "new") | node |
| AC-3 | `tests/js/kasir-pelanggan.test.js` (telp never readOnly) + `PelangganResolverTest::testNomorBelumTerdaftarMengisiPelangganTanpaNomor` | node + unit |
| AC-5 | `SimpanTransaksiPelangganTest::testPiutangTanpaNomorDitolak` | feature |
| AC-6, 7 | `InboxBuatPesananTautanTest::testNamaSamaTanpaNomorMemintaKonfirmasi`, `::testTautkanMengisiNomorTanpaRowBaru`, `::testTautkanIdPalsuDitolak` | feature (Inbox) |
| AC-8 | `PelangganResolverTest::testNamaDigit4DariServer`, `::testTanpaNomorNamaPolos` | unit |
| AC-9 | `InboxBuatPesananTautanTest::testNomorTerdaftarNamaBedaPakaiPelangganLama` | feature |
| AC-10 | `PelangganResolverTest::testGantiNamaSajaJadiAtasNama`, `::testNomorBaruBuatPelangganBaru`, `::testNomorTerdaftarPakaiPemilik` | unit |
| Api/Transaksi parity | `SimpanTransaksiPelangganTest` and `TransaksiUpdatePelangganTest` run the same cases | feature |

Mutation check afterwards (swap each guard, expect a failing test): number-owner lookup, empty-`no_hp` check on `tautkan`, `atas_nama` condition, piutang `no_hp` check.

## 7. Risks and mitigations

- **Enter picks the highlighted first row, but the cashier meant a new person** (types "Soleha", Enter -> old Soleha) -> last row `+ Jadikan Pelanggan Baru` is always shown; piutang requires a number, so wrong picks cannot create untraceable debt. Residual risk accepted for cash sales.
- **AC-3 vs AC-10 tension**: a known customer who changed his number looks like a "new number for a selected customer" -> by AC-10 a new customer is created. Number changes of an existing customer go through Master Pelanggan (edit page stays available there). Flagged for Aan.
- **Old plain names with typos** (`SOLEHA` and `Soleha`) -> collation is case-insensitive, no fuzzy matching; accepted.
- **Browser-only behaviour** (keyboard dropdown, Inbox modal, opener hand-off) cannot be proven by curl/node tests -> manual dev-browser check required after implementation.

## 8. Not yet verified

- `TransaksiModel` / insert style at `Api.php:330` and `Transaksi.php:1582` (model with `$allowedFields` or builder).
- `inbox-page.js` modal code for `buatPesanan` phase 1 (`butuh_nama` handling) and its existing tests.
- `app/Views/kasir/index.php` and `edit.php` exact markup of the phone input and edit button (`readonly` at index.php:~42 was read in an earlier pass only).
- Which views/queries show the customer name (receipt, invoice, `transaksi/hari_ini`, reports) and whether they JOIN `pelanggan`; `atas_nama` must be selected in each. Not yet read.
- Existing test file names and helpers under `tests/` (names above are proposed).
- `docs/TODO.md` has no item for this work yet; a new ID should be added and linked to both documents.

## 9. Approval (Gate 2)

- [x] Approved by: Aan, date: 2026-10-19
