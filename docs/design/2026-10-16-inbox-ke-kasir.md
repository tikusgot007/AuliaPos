# Design: Inbox to Kasir ("Buat Pesanan")

- **Date**: 2026-10-16
- **Status**: approved (Gate 1 + Gate 2, 2026-10-16, Option A chosen)
- **Requirements**: `docs/requirements/2026-10-16-inbox-ke-kasir.md`
- **SDLC tier**: A (new feature + schema change on the `inbox` group)

## 1. Summary

Add a "Buat Pesanan" button to the Inbox conversation header. It calls a new session-authenticated
endpoint that finds or creates a `pelanggan` row (default DB) for the conversation, stores the link in
a new nullable `conversations.pelanggan_id` column (inbox DB), and returns the Kasir URL. The browser
then navigates to `/kasir` with the customer pre-selected.

## 2. Current flow (verified)

Inbox side (conversation identity lives only in `conversations`, DB group `inbox`):

    GET  /inbox/api/conversations/(:num)/messages  (app/Config/Routes.php:59)
      -> Inbox::apiMessages()                       (app/Controllers/Inbox.php:427)
    POST /inbox/percakapan/(:num)/profil            (app/Config/Routes.php:81)
      -> Inbox::updateCustomerProfile()             (app/Controllers/Inbox.php:2986)
         guards: cekBukanGrup(), cekOwnership() (Inbox.php:1169), PhoneNumber::normalize()
      -> ConversationModel::update()                (app/Models/ConversationModel.php:72, $DBGroup='inbox')

Kasir side (customer = `pelanggan` table, default DB; no relation to Inbox today):

    GET /kasir  (filter auth, app/Config/Routes.php:171)
      -> Kasir::index()                             (app/Controllers/Kasir.php:12)
         passes ALL pelanggan to view `kasir/index`; reads NO query parameter server-side.
    Browser: public/assets/js/kasir-shared.js
      -> restorePelangganFromUrl()  (kasir-shared.js:880, called at :724)
         reads ?pelanggan_id & ?pelanggan_nama (BOTH required) & ?pelanggan_telp & ?pelanggan_diskon
      -> pilihPelanggan(id, nama, no_hp, diskon)    (kasir-shared.js:907) fills #namaPelanggan, #telpPelanggan,
         sets `pelangganTerpilih`, strips the query string via history.replaceState.
    Existing producer of that URL contract:
      Pelanggan::update() (app/Controllers/Pelanggan.php:127-132) redirects to
      /kasir?pelanggan_id&pelanggan_nama&pelanggan_telp&pelanggan_diskon after "edit from kasir".

`pelanggan` columns (app/Database/Migrations/2026-09-08-000001_CreateAuliaPosCore.php:74): `id int(11)`,
`nama varchar(100) NOT NULL`, `no_hp varchar(20) NULL`, `alamat`, `diskon decimal(10,2)`, `created_at`.
`PelangganModel` (app/Models/PelangganModel.php) allows `nama,no_hp,alamat,diskon`; no unique key on
`nama` or `no_hp`.

Key finding: the Kasir already has an auto-fill mechanism driven by URL parameters. The task description
assumes `Kasir.php` must read `pelanggan_id`; today only the JS does, and it needs `pelanggan_nama` too.

Callers/consumers of things that change:
- `conversations` table: `ConversationModel`, `InboxGatewayApi::messages()`, many Inbox tests that insert
  rows via the query builder. A new nullable column does not break any INSERT/SELECT (additive).
- `ConversationModel::$allowedFields`: only widens what `update()/insert()` accepts.

## 3. Options

- **A. Reuse the existing Kasir URL contract (no Kasir change).** The API resolves the `pelanggan` row
  and returns `/kasir?pelanggan_id&pelanggan_nama&pelanggan_telp&pelanggan_diskon` (same shape as
  `Pelanggan::update()`). No change to `Kasir.php` or `kasir-shared.js`. Trade-off: customer data
  (name, phone) appears briefly in the URL/browser history (already true today for the Pelanggan edit flow).
- **B. Kasir reads only `?pelanggan_id=` server-side.** `Kasir::index()` loads the row and injects it
  into the view, JS pre-selects it. URL carries only an id. Trade-off: touches `Kasir.php`, the
  `kasir/index` view and `kasir-shared.js`; need a guard for unknown/deleted ids; a second path to
  pre-select a customer next to the existing one.
- **C. Make `restorePelangganFromUrl()` accept `pelanggan_id` alone and fetch the row from
  `/api/search-pelanggan`-style endpoint.** Trade-off: extra round trip and a new read endpoint for little gain.

**Recommendation:** A for the first iteration (YAGNI, smallest diff, zero Kasir regression risk).
If the owner wants a clean URL (id only), do B as a follow-up; the Inbox side does not change.
The requested approach ("Kasir.php reads the parameter") corresponds to B; this needs the owner's decision at Gate 2.

## 4. Planned changes

| File | Change | Reason |
|---|---|---|
| `app/Database/Migrations/2026-10-16-000001_AddPelangganIdToConversations.php` | New. `$DBGroup='inbox'`; add `conversations.pelanggan_id INT(11) UNSIGNED NULL` (idempotent via `fieldExists`), `down()` drops it | Link conversation to pelanggan (logical reference, no FK: cross-database) |
| `app/Models/ConversationModel.php` | Add `'pelanggan_id'` to `$allowedFields` | Allow `update()` to write the link |
| `app/Controllers/Inbox.php` | New `buatPesanan($conversationId)`: guards, resolve/create pelanggan, link, return JSON `{status, redirect_url}` | Server-side find-or-create |
| `app/Config/Routes.php` | `POST /inbox/percakapan/(:num)/buat-pesanan` -> `Inbox::buatPesanan/$1`, filter `auth` | Same pattern as `/profil`, `/ambil` |
| `app/Views/inbox/index.php` + `public/assets/js/inbox-*.js` | Button "Buat Pesanan" in conversation header; POST then `location.assign(redirect_url)` | UI trigger. Any JS file with inline handlers must be added to the `InboxViewStructureTest` list (TODO-MARA1 note) |
| `app/Controllers/Kasir.php` (+ view/JS) | Only if Option B is chosen | See section 3 |
| `docs/CHANGELOG.md` | Business rule entry (Bahasa Indonesia): when a pelanggan is auto-created from Inbox | AGENTS.md section 15 |

Phase 1 delivers only: this document, the migration, and the `$allowedFields` change. The rest is
implemented after Gate 2.

### 4.1 `buatPesanan()` rules (proposed)

1. `find($conversationId)` -> 404 if missing; `cekBukanGrup()` -> 403 for groups; `cekOwnership()` -> 403
   (identical guards to `updateCustomerProfile()`).
2. If `conversations.pelanggan_id` is set AND `PelangganModel::find()` returns a row -> reuse it.
   If the id points to a deleted pelanggan (dangling reference) -> treat as unset.
3. Otherwise match an existing `pelanggan` by phone: use `conversations.phone` (verified) else
   `manual_phone`; try the canonical `62xxx` form and the local `0xxx` form (`pelanggan.no_hp` is free
   text, stored format not yet verified). A `@lid` conversation with no phone is NOT matched by guess.
4. If no match -> insert `pelanggan` with `nama = contact_name ?: whatsapp_name ?: phone` (trimmed to 100),
   `no_hp` (<= 20 chars), `diskon = 0`. If no usable name -> return 422 asking the cashier to fill the
   profile first (do not invent a name).
5. Write `conversations.pelanggan_id` via `ConversationModel::update()`.
6. Return the redirect URL; no 4xx/5xx leaks internals.

## 5. Impact

- **Database / migrations**: one additive nullable column on `conversations` (inbox group only). No change
  to the `default` group. No FK (cross-database, consistent with `assigned_to`, `closed_by`). Signed
  `pelanggan.id INT(11)` vs unsigned `pelanggan_id`: harmless because nothing joins across DBs; the value
  is always positive. No index added now (no lookup by `pelanggan_id` yet; YAGNI, add when a query needs it).
  Run the migration on `inbox` BEFORE deploying code that reads/writes the column (docs/ARCHITECTURE.md
  deployment ordering rule; lesson from the 2026-10-13 mara_suggestions deploy).
- **Routes / API / response formats**: one new POST route. No existing route or response is changed
  (conversation JSON will additionally contain `pelanggan_id`; additive).
- **Gateway contract**: none. No change to the Gateway or its HTTP contract (cross-repo not affected).
- **Existing data**: all existing conversations get `pelanggan_id = NULL`; no backfill.
- **Security / validation at trust boundaries**: session `auth` filter plus ownership/group guards;
  `conversationId` cast to int; phone via `PhoneNumber::normalize()`; names length-capped before insert;
  query-builder bound params only; redirect URL built with `http_build_query` and a same-origin path.
  Customer name/phone in the URL (Option A) is not secret but is visible in history/logs.
- **Transactions / concurrency / rollback behavior**: the two writes are in different databases and cannot
  be atomic. Double-click / two cashiers could create duplicate `pelanggan` rows (no unique key). Mitigation:
  take `SELECT ... FOR UPDATE` on the conversation row (same technique as
  `MaraSuggestionModel::lockConversation()`) around match-or-create + link, and disable the button while the
  request is in flight. If the link write fails after the pelanggan insert, the next click re-matches the
  pelanggan by phone (no data loss, no orphan problem).
- **Money / state**: no `transaksi`, `pembayaran`, or `tagihan` logic is touched.

## 6. Test plan

Acceptance criteria will be defined in the requirements document; provisional mapping:

| Acceptance criterion (provisional) | Test (file::method) | Type |
|---|---|---|
| Migration adds nullable `conversations.pelanggan_id`, existing rows NULL | `tests/feature/...::testMigrationAddsPelangganId` (via `aulia:sync-inbox-test-db`, `DESCRIBE`) | feature (inbox test DB `aulia_inboxdb_test`) |
| Existing pelanggan matched by phone (62xxx and 0xxx) -> reused, not duplicated | `tests/feature/InboxBuatPesananTest::...` | feature |
| No match -> pelanggan created with name/phone, link stored | same | feature |
| Group conversation / foreign owner -> 403; missing -> 404 | same | feature |
| Dangling `pelanggan_id` -> re-resolved | same | feature |
| No usable name -> 422, nothing inserted | same | feature |
| Button calls endpoint and navigates | `tests/js/...` | js |

Tests must use the dedicated test DBs only (`aulia_inboxdb_test`, in-memory SQLite for `default`); never
the dev/production DB from `.env` (docs/sesi + `.kilo/rules/sdlc.md` section 4).

## 7. Risks and mitigations

- Duplicate `pelanggan` rows from retries/races -> row lock + button disable + phone re-match (section 5).
- Phone format mismatch between `conversations.phone` (`62xxx`) and `pelanggan.no_hp` (free text) -> match
  both canonical and local forms; wrong auto-match is limited to exact normalized equality.
- `@lid` conversations without a phone -> always create a new pelanggan only if the cashier confirmed the
  name; never guess a number.
- Deleted pelanggan leaves a dangling `pelanggan_id` -> validated with `find()` on every use.
- Code deployed before migration -> column-missing errors; follow the deployment ordering rule.
- Customer PII in the Kasir URL (Option A) -> same exposure as the existing Pelanggan edit flow; switch to
  Option B if the owner objects.

## 8. Not yet verified

- Requirements document and acceptance criteria do not exist yet (Gate 1 is not passed).
- Actual stored format of `pelanggan.no_hp` in production data.
- Whether `Inbox` should store `pelanggan_id` for conversations the cashier opens but does not order from.
- Layout location of the button in `app/Views/inbox/index.php` (not yet read).
- Behavior of the `auth` filter / CSRF for the new POST route (assumed identical to `/profil`).
- Whether an Option B change to `Kasir.php` is wanted.
- The migration has NOT been run on any database (dev, test, or production).

## 9. Approval (Gate 2)

- [x] Approved by: project owner (via Architect review), date: 2026-10-16. Option A. Implementation note:
  name collision with a different-phone pelanggan returns 409 (not auto-reuse), see requirements AC-7.
