# Design: "Buat Pesanan" — customer-name confirmation and main-tab redirect

- **Date**: 2026-10-18
- **Status**: approved (Gate 2, 2026-10-18)
- **Requirements**: `docs/requirements/2026-10-18-buat-pesanan-konfirmasi-nama-dan-tab-utama.md` (Gate 1 approved 2026-10-18)
- **SDLC tier**: A

## 1. Summary

Keep the single endpoint `POST /inbox/percakapan/(:num)/buat-pesanan` but make it two-phase through an optional `nama` field: without `nama` it only resolves and (if the customer is already compliant) proceeds; otherwise it answers `butuh_nama` and writes nothing. With `nama` it validates and writes (INSERT or UPDATE) under the existing row lock. The browser shows a Bootstrap modal for the name, and redirects `window.opener` (not a new tab) after an opener-side cart check. Kasir ignores the stored cart when the URL carries `dari_inbox=1`.

## 2. Current flow (verified by reading code 2026-10-18)

    click "Buat Pesanan" / M.A.R.A "Buka di Kasir"
      -> buatPesanan()                 public/assets/js/inbox-page.js:676
         window.open('', '_blank') sync, then fetch POST
      -> Inbox::buatPesanan()          app/Controllers/Inbox.php:3184
         404/403 guards -> transBegin -> SELECT ... FOR UPDATE (:3212)
         pelanggan lookup: pelanggan_id (:3227) -> phone/manual_phone/chat_id (:3235-3254) -> PelangganModel::findByNomor() (app/Models/PelangganModel.php)
         not found -> findByName + INSERT with raw WA name (:3262-3297)
         UPDATE conversations.pelanggan_id (:3299) -> commit -> JSON redirect_url (:3325-3338)
      -> tab.location = /kasir?pelanggan_id&pelanggan_nama&pelanggan_telp&pelanggan_diskon
      -> restorePelangganFromUrl()     public/assets/js/kasir-shared.js:880 (customer only)
         primeCartFromStorage()        kasir-shared.js:54-66 restores sessionStorage 'aulia_kasir_cart_v1'

Other facts:
- Inbox window is opened by `window.open(href, 'AuliaInbox', ...)` without `noopener` (`app/Views/layout/main.php:173`; `inbox-notifikasi.js:222`), so `window.opener` is the opener tab.
- `kasir/edit` sets `cartStorageKey: null` (`app/Views/kasir/edit.php:392`); the cart persists on every change at `kasir-shared.js:280-281`; `window.auliaCart` exposes the live array (`:68`).
- Existing test coverage: `tests/feature/InboxBuatPesananTest.php` (28), `tests/feature/PelangganFindByNomorTest.php`, `tests/js/inbox-buat-pesanan.test.js`.
- Not verified: `window.opener` behaviour after F5 of the Inbox window; whether `opener.focus()` raises a background window in the cashiers' browser.

## 3. Options

- **A. One endpoint, optional `nama`** (two calls): minimal surface, same route/CSRF/filters; phase 1 is read-only.
- **B. Two endpoints** (`.../buat-pesanan/cek` + `.../buat-pesanan`): clearer separation but a second route, second CSRF rotation, duplicated resolve logic.
- **C. Client-side only check** (browser decides if the modal is needed from data already in the page): the page does not hold the pelanggan table, so it cannot know; rejected.

**Recommendation:** A. Resolve logic stays in one method; phase 1 performs no write, which is what makes "Batal writes nothing" (AC-3/AC-9) true by construction.

## 4. Planned changes

| File | Change | Reason |
|---|---|---|
| `app/Controllers/Inbox.php` `buatPesanan()` | Resolve pelanggan as today (link wins, then number). Add private helpers: verified-number (`phone`, else `pn` chat_id JID; `manual_phone` still used for lookup only, never for the 4 digits), `pecahNamaPelanggan()` (strip trailing ` dddd` equal to the digits), `namaSesuaiFormat()`. No `nama` and (no pelanggan, or pelanggan not compliant) -> JSON 200 `{status:'butuh_nama', pelanggan_id|null, nama_usulan, digit}` and nothing written. No verified number and customer needs writing -> 422 "Konfirmasi nomor WhatsApp dulu" (AC-5). With `nama`: validate, then inside the existing FOR UPDATE transaction re-resolve; INSERT new, or UPDATE `nama` (+ `no_hp` only if empty) of the existing one; link `pelanggan_id`. Redirect query gains `dari_inbox=1`. | AC-1..AC-7, AC-12 |
| `app/Models/PelangganModel.php` | `findByName()` gets optional `$exceptId`. | self is not a duplicate (AC-2b/AC-4) |
| `app/Views/inbox/index.php` | New modal `#modalNamaPelanggan`: name input (editable), 4-digit input-group-text (read-only), buttons "Simpan & Buka Kasir" (Enter) and "Batal" (Esc); second modal `#modalGantiKasir` for the opener confirmation. | AC-2, AC-3, AC-9 |
| `public/assets/js/inbox-page.js` | Rewrite `buatPesanan()` flow (inside the existing markers `let buatPesananBerjalan` ... `// Menit dari sekarang` so the JS test slice keeps working): (1) `cekOpener()`; (2) if opener holds cart items or is on `/kasir/edit` -> confirm modal, "Batal" aborts before any request; (3) phase-1 fetch; (4) on `butuh_nama` open name modal, on submit send `nama`; (5) navigate opener (`opener.location.href`, `opener.focus()`) or fallback new tab. Fallback tab is opened synchronously at click and closed if the name modal is needed; reopened synchronously in the modal submit handler. All reads of `window.opener` in try/catch (cross-origin/closed = no opener). | AC-8..AC-11 |
| `public/assets/js/kasir-shared.js` | `primeCartFromStorage()`: when `location.search` has `dari_inbox=1`, remove the stored cart and skip restore; `restorePelangganFromUrl()` also strips `dari_inbox` from the URL. | AC-12 |
| `docs/CHANGELOG.md` | New customer-name rule (`NAMA 4DIGITHP`). | business rule change |
| `docs/TODO.md` | Update TODO-INBOX-KASIR2 status. | AGENTS.md |

## 5. Impact

- **Database / migrations**: none. `pelanggan.nama` is 100 chars (verified on production), `no_hp` text.
- **Routes / API**: same route and filters (`auth`, `csrf`). New response shape `status:'butuh_nama'` (HTTP 200) plus `dari_inbox=1` in `redirect_url`. Only caller is `inbox-page.js` (M.A.R.A button goes through the same `buatPesanan()`); existing tests asserting immediate INSERT must be updated.
- **Gateway / M.A.R.A contract**: unchanged.
- **Existing data**: no conversation-linking UI exists; `conversations.pelanggan_id` is only written by `buatPesanan()` itself (`Inbox.php:3300`), so a link can only come from an earlier run, a later master-data edit, or LID->PN mapping. Production `pelanggan` 2,086 rows: 151 with HP already compliant (no change), 1,935 without HP, updated lazily via AC-2b only when a cashier uses the button. Name uniqueness after uppercasing: 0 collisions today.
- **Security / validation (trust boundary = request body)**: `nama` is untrusted. Server: trim, collapse whitespace, `mb_strtoupper`, allow only `\p{L}\p{N}` space `.` `-` `'` (drops emoji/symbols; approved at Gate 2 2026-10-18), 2-95 chars, ignore any client-sent digits (always computed from the number), full name <= 100, duplicate check excluding the customer itself. **Identity rule (approved at Gate 2, option A)**: an existing `conversations.pelanggan_id` link always wins, even when the numbers differ (a different number is treated as a different customer only for number-based lookup, never by breaking an existing link). An existing pelanggan with a *different* non-empty `no_hp` is a different person: never overwrite its `no_hp`; the digits come from the customer's own `no_hp`. CSRF token rotation unchanged (every response, including `butuh_nama`, returns `csrf`).
- **Transactions / concurrency**: write phase re-resolves under `FOR UPDATE`; if a second cashier already created/updated the customer, the call reuses it (idempotent, AC-6). Rollback path unchanged (delete newly inserted row on error).

## 6. Test plan

| AC | Test | Type |
|---|---|---|
| AC-1 | `InboxBuatPesananTest::testPelangganSesuaiFormatLangsungKeKasirTanpaModal` | feature |
| AC-2 | `...::testPelangganBaruButuhNamaTanpaMenulis` (asserts `butuh_nama`, digits, uppercased suggestion, 0 rows inserted) | feature |
| AC-2b | `...::testPelangganLamaTanpaHpDiperbaruiBukanDuplikat`, `...::testHpPelangganYangBedaTidakDitimpa`, `...::testTautanTetapMenangWalauNomorBeda` (keeps `testTautanYangAdaDipakaiMeskiNomorCocokDenganOrangLain` green) | feature |
| AC-3 | `...::testSimpanDenganNamaMenghasilkanFormatNamaEmpatDigit` | feature |
| AC-3 (Batal/Esc) | `inbox-buat-pesanan.test.js`: cancel path sends no second request | js |
| AC-4 | `...::testNamaDivalidasiServer` (dataProvider: emoji-only, 1 char, 96 chars, forged digits ignored, duplicate -> 409, self not duplicate) | feature |
| AC-5 | `...::testTanpaNomorTerverifikasiDiblok422` (@lid, id-404-style `60…` JID) | feature |
| AC-6 | `...::testSubmitKeduaMemakaiPelangganYangSama` | feature |
| AC-7 | JS: M.A.R.A `jalankanAksiSaran` still calls the same `buatPesanan()` | js |
| AC-8 | `inbox-buat-pesanan.test.js`: opener navigated + focused, no `window.open` | js |
| AC-9 | JS: opener with cart / on `/kasir/edit` -> confirm shown; "Batal" -> zero fetch calls | js |
| AC-10 | JS: empty cart or non-kasir page -> straight redirect | js |
| AC-11 | JS: no opener / closed / throwing opener -> new tab fallback, closed again when name modal needed | js |
| AC-12 | new `tests/js/kasir-dari-inbox.test.js`: `dari_inbox=1` clears storage and skips restore; without it restore unchanged | js |

Mutation checks (break code, expect red, restore): drop server uppercase, drop duplicate check `exceptId`, drop `no_hp` overwrite guard, drop the opener cart check, drop `dari_inbox` handling. Full regression: unit, feature (`composer test:feature`), integration, `node --test tests/js/*.test.js`.

## 7. Risks and mitigations

- Opener lost (Inbox opened by bookmark or after F5) -> new-tab fallback; multiple Kasir tabs possible (accepted in requirements).
- `opener.focus()` may not raise a background window -> redirect still happens; cashier may need to switch tab. Verify manually.
- Opener cart read relies on same origin; any exception is treated as "no opener".
- Mass-assign risk on `UPDATE pelanggan`: only `nama` and (when empty) `no_hp` are written, explicit field list.
- Cashier types a different person's name for an existing number -> allowed (it is their correction), but audit trail is the existing `Inbox::buatPesanan` log line; extend it with old/new name.

## 8. Not yet verified

- Browser behaviour: `window.opener` after F5, `opener.focus()`, `opener.sessionStorage` access, popup blocker on the fallback path (manual test in the cashier browser).
- Character whitelist for names (proposal only).
- Whether non-Indonesian numbers (e.g. conversation 404, `60…`) should ever be accepted (out of scope, stays blocked).

## 9. Approval (Gate 2)

- [x] Approved by: Aan, date: 2026-10-18 (options: name whitelist yes; link wins over number, option A)
