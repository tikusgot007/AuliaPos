# Design: Pre-fill the Kasir cart from an M.A.R.A suggestion

- **Date**: 2026-10-18
- **Status**: approved (Gate 2, 2026-10-18)
- **Requirements**: `docs/requirements/2026-10-18-isi-keranjang-dari-saran-mara.md` (Gate 1 approved 2026-10-18)
- **SDLC tier**: A

## 1. Summary

M.A.R.A sends `cart_items` (`[{produk_id, jumlah}]`) next to `action = buka_kasir`; POS stores it in a new
nullable column. When the cashier presses "Buka di Kasir", the redirect to Kasir carries only `saran_id`.
`Kasir::index()` re-reads that suggestion server-side, checks it belongs to the conversation linked to the
`pelanggan_id` in the URL, loads name/unit/category/price from `produk`, and hands the finished lines to the
page. M.A.R.A never supplies a price.

## 2. Current flow (verified, read 2026-10-18)

    M.A.R.A POST /api/inbox/mara/suggestions
      -> MaraController::suggestions()           app/Controllers/MaraController.php:271
         validates type/priority/items(daftar-periksa)/action; INSERT in one transaction with
         conversations row lock (:396-:437)
      -> MaraSuggestionModel::insert()           app/Models/MaraSuggestionModel.php ($allowedFields :31)

    Cashier opens a conversation
      -> Inbox::apiSuggestions()                 app/Controllers/Inbox.php:~560
         activeForConversation(): status='active' AND not expired (MaraSuggestionModel.php:56)
         decodes `items` JSON; returns no `cart_items` today

    Button "Buka di Kasir"
      -> jalankanAksiSaran(saranId)              public/assets/js/inbox-suggestions.js:193
      -> buatPesanan()                           public/assets/js/inbox-page.js:730   (no saran id passed)
      -> Inbox::buatPesanan()                    app/Controllers/Inbox.php (two-phase)
      -> buatPesananSukses() builds redirect_url app/Controllers/Inbox.php:~3427-3442
         query: pelanggan_id, pelanggan_nama, pelanggan_telp, pelanggan_diskon, dari_inbox=1

    Kasir
      -> Kasir::index()                          app/Controllers/Kasir.php:12  (loads all active produk :42)
      -> kasir-shared.js primeCartFromStorage()  :53   drops stored cart when dari_inbox=1
      -> restorePelangganFromUrl()               :893  fills customer, strips dari_inbox from URL
      -> tambahItemKeCart(item)                  :419  builds a cart line, persistCart(), renderLocalCart()
      -> persistCart()                           :292  sessionStorage[cartStorageKey]

`mara_suggestions.items` is already used by `daftar-periksa` for step text (MaraController.php:312-338), so a
separate column is required.

Not verified: how special products (banner / custom size / manual) behave when added as a plain line (section 8).

## 3. Options

How the lines travel from Inbox to Kasir (the part with a real trade-off):

- **A. `saran_id` in the redirect, resolved server-side in `Kasir::index()`**: URL stays short; prices come from
  `produk` at the moment Kasir opens; the id can be re-checked (ownership, active, not expired). One extra
  read of the inbox DB inside the Kasir page, wrapped in try/catch so Kasir still opens if it fails.
- **B. Put the finished lines in the redirect URL or in the opener's `sessionStorage`**: no new read in Kasir,
  but the URL grows and can be edited by hand; `sessionStorage` is not shared with the fallback new tab, so the
  fallback path would silently lose the cart.
- **C. Make `buatPesanan` return the lines and let JS push them**: needs a second handoff channel to a page that
  is about to navigate; same fallback problem as B.

**Recommendation:** A, because it is the only option that works for both the opener redirect and the new-tab
fallback, and the server stays the only source of prices.

## 4. Planned changes

| File | Change | Reason |
|---|---|---|
| `app/Database/Migrations/2026-10-18-000001_AddCartItemsToMaraSuggestions.php` | `$DBGroup='inbox'`; nullable `cart_items` TEXT after `action`; `up()` guarded by `fieldExists`; `down()` drops it | AC-1; additive, no downtime |
| `app/Models/MaraSuggestionModel.php` | add `cart_items` to `$allowedFields`; add `findActiveForConversation(int $suggestionId, int $conversationId)` (same status/expiry rule as `activeForConversation`) | AC-1, AC-10 |
| `app/Controllers/MaraController.php` | accept optional `cart_items`: array of objects, only `produk_id` (int >0) and `jumlah` (int 1-999) kept, extra fields dropped, max 20 rows, duplicate `produk_id` summed, requires `action='buka_kasir'` and type balasan/peringatan; write column only when sent (same trick as `action`) | AC-1..AC-4 |
| `app/Controllers/Inbox.php` | `apiSuggestions()` also returns `ada_keranjang` (bool) so the UI can label the button; `buatPesanan()` accepts optional `saran_id` and, only if that suggestion belongs to the conversation and is active, appends `saran_id` to `redirect_url` | AC-5, AC-10 |
| `public/assets/js/inbox-suggestions.js`, `inbox-page.js` | `jalankanAksiSaran` passes `saranId` into `buatPesanan(conversationId, saranId)`; fase 1 and fase 2 send it | AC-5 |
| `app/Controllers/Kasir.php` | in `index()`: if `saran_id` and `pelanggan_id` are present, load the suggestion, require `conversations.pelanggan_id == pelanggan_id` and active, map `cart_items` to lines using `ProdukModel` (accept only `is_active=1`, `harga_jual>0`; anything else counts as failed); pass `keranjangAwal` and `jumlahGagal` to the view | AC-5..AC-7, AC-10, AC-11 |
| `app/Views/kasir/index.php` | emit `KASIR_CONFIG.keranjangAwal` / `.jumlahGagal` | hand lines to JS |
| `public/assets/js/kasir-shared.js` | after `primeCartFromStorage()`, push `keranjangAwal` once via the existing line shape and `persistCart()`; toast when `jumlahGagal > 0`; `restorePelangganFromUrl()` also strips `saran_id` | AC-5..AC-9 |
| `docs/CHANGELOG.md`, `docs/TODO.md`, `docs/sesi/` | business-rule entry, `TODO-INBOX-KASIR3` status, session checkpoint | repo rules |
| Tests | see section 6 | |

## 5. Impact

- **Database / migrations**: one additive nullable column in `aulia_inboxdb.mara_suggestions`. Order on
  production: migrate first, then deploy code, then M.A.R.A starts sending. Code deployed before the migration
  still works because `cart_items` is only written when sent, and `apiSuggestions()` and `Kasir::index()` read it
  inside try/catch.
- **Routes / API / response formats**: `POST /api/inbox/mara/suggestions` gains an optional field (non-breaking).
  `apiSuggestions` gains `ada_keranjang`. `buat-pesanan` gains optional `saran_id`; the response shape is unchanged.
  No new route, no new endpoint.
- **Contract (cross-repo)**: M.A.R.A repo is NOT in this workspace. Its own answers (2026-10-18, not verified by POS): it reads `produk` directly as DB user `aulia_mara` (host 192.168.1.10) and selects only `id, nama, kategori_id, satuan, harga_jual, is_active`; sends `cart_items` only when product and size match with certainty; empties `cart_items` when ambiguous or when quantity is unclear; never sends banner / manual / custom / price-0 products; sends multiple rows for several items; resends the full latest `cart_items` when the order changes; reminds the cashier about No Order for category 16 in the suggestion text. POS does not depend on any of this (it re-validates everything), so a wrong M.A.R.A answer cannot change a price.
- **Existing data**: nothing rewritten; old suggestions have `cart_items = NULL` and behave as today.
- **Security / validation at trust boundaries**: M.A.R.A input is treated as untrusted. Only two integers per
  row survive. Price, name, unit, category are always read from `produk`. `saran_id` in the URL is untrusted too,
  so Kasir checks it belongs to the conversation linked to `pelanggan_id`, is `active`, and is not expired. The
  items array is never printed raw into HTML (emitted with `json_encode` flags `JSON_HEX_TAG|JSON_HEX_AMP`).
- **Transactions / concurrency / rollback**: no financial table is written; the cart only exists in the browser.
  Rollback = deploy the previous commit; the extra column can stay.

## 6. Test plan

| Acceptance criterion | Test (file::method) | Type |
|---|---|---|
| AC-1 | `tests/feature/MaraSuggestionsCartItemsTest.php::testCartItemsDisimpanDiKolomTerpisah`, `::testSaranTanpaCartItemsTidakBerubah` | feature |
| AC-2 | `::testHanyaProdukIdDanJumlahYangDisimpan`, `::testHargaDariMaraDiabaikan`, `::testLebihDari20BarisDitolak`, `::testJumlahDiLuarRentangDitolak` | feature |
| AC-3 | `::testProdukIdKembarDigabung` | feature |
| AC-4 | `::testCartItemsTanpaActionDitolak`, `::testCartItemsPadaDaftarPeriksaDitolak` | feature |
| AC-5 | `tests/feature/KasirKeranjangDariSaranTest.php::testItemMasukDenganHargaDariProduk` | feature |
| AC-6 | `::testSebagianProdukTidakAktifTetapMasukDenganJumlahGagal` | feature |
| AC-7 | `::testSemuaGagalKeranjangKosongDenganPeringatan` | feature |
| AC-8 | `tests/js/kasir-dari-saran.test.js` (item is a normal cart line: qty change / remove) | js |
| AC-9 | `tests/js/kasir-dari-saran.test.js` (second load after URL cleanup adds nothing) | js |
| AC-10 | `KasirKeranjangDariSaranTest::testSaranMilikPercakapanLainDitolak`, `::testSaranKedaluwarsaDiabaikan` | feature |
| AC-11 | `::testProdukIdPalsuTidakDipercaya`, `::testProdukHargaNolDitolak` | feature |
| AC-12 | existing `InboxBuatPesananTest` / `inbox-buat-pesanan.test.js` stay green; add `::testSaranIdIkutRedirect` | feature + js |

Also: run the existing 339 feature / 134 unit / 65 integration / 156 JS suites, and repeat the mutation check on
the new server and JS code.

## 7. Risks and mitigations

- A wrong `produk_id` from M.A.R.A puts the wrong product in the cart -> cart is a draft the cashier checks;
  name and price are shown in the normal cart; documented as M.A.R.A's responsibility.
- Price drift between the suggestion and the click -> price is read when Kasir opens, not when M.A.R.A writes.
- Kasir page slows or fails because of the extra inbox-DB read -> single indexed query, wrapped in try/catch,
  Kasir opens with an empty cart on failure.
- M.A.R.A's DB account `aulia_mara` has full `SELECT` on the table (its own statement), so `harga_beli` is readable by
  M.A.R.A even though it only selects the needed columns -> POS cannot enforce column limits; the owner should restrict
  the grant to the 7 needed columns (`GRANT SELECT (id, nama, kategori_id, satuan, harga_jual, is_active) ON aulia_kasirdb.produk TO 'aulia_mara'@...`). Flagged, not a blocker for POS.
- Wrong `produk_id` that still passes the filter (e.g. M.A.R.A picks 4x6 when the customer meant 4x6 Visa) -> draft cart the
  cashier verifies; M.A.R.A only sends on certain match.

## 8. Not yet verified

- M.A.R.A repo and behaviour (aulia7, not in this workspace): only its own written answers are available. The account `aulia_mara` could not be seen from the POS read-only session (`SHOW GRANTS` and `information_schema` returned nothing), so its real grants are unverified. Product ids quoted by M.A.R.A were checked against `produk`: 62 "3x4", 68 "Dompet", 73 "4R" (category 16, active, unlocked, Rp800 / Rp1.100 / Rp2.500) are correct.
- How a plain line behaves for banner / custom-size / manual products (`is_banner`, `is_custom`, `is_manual`,
  `is_cetak` flags in `tambahItemCart`); to be read from `kasir-shared.js` and `app/Views/kasir/index.php` before
  Phase 3, and if a product category needs a configuration modal it is excluded from auto-fill and counted in
  `jumlahGagal`.
- Whether `Kasir::index()` already has access to the inbox DB group in all environments.

## 9. Approval (Gate 2)

- [x] Approved by: Aan, date: 2026-10-18
