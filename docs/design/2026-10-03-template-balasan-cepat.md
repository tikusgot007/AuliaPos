# Design: Quick Reply Templates (Inbox)

- **Date**: 2026-10-03
- **Status**: draft
- **Requirements**: `docs/requirements/2026-10-03-template-balasan-cepat.md`
- **SDLC tier**: A

## 1. Summary

Add an admin-managed CRUD for reusable reply templates (name + optional text
+ optional single image), and a "Template" button in the Inbox composer that
lets a cashier insert a template's image/text into the existing attachment
queue preview before sending, reusing the send pipeline added by PR #47
(`antrianMediaBalasan`) and the existing `/inbox/kirim` / `/inbox/kirim-media`
endpoints unchanged.

## 2. Current flow (verified)

**Admin CRUD pattern** (to be cloned for templates), e.g. `Kategori`:

    GET  /kategori            -> Kategori::index()   (app/Controllers/Kategori.php:9)
    GET  /kategori/tambah     -> Kategori::tambah()   (Kategori.php:26)
    POST /kategori/simpan     -> Kategori::simpan()   (Kategori.php:38)
    GET  /kategori/edit/:id   -> Kategori::edit($id)  (Kategori.php:58)
    POST /kategori/update/:id -> Kategori::update($id)(Kategori.php:74)
    GET  /kategori/hapus/:id  -> Kategori::hapus($id) (Kategori.php:94)

Admin-only gate is a prefix whitelist, not a route filter:
`app/Filters/AuthFilter.php:70` `$adminRoutes` array; `kategori` is already
listed there as an example of the same mechanism this feature will reuse.

**File upload outside the public docroot** (to be cloned for the template
image), e.g. profile photo:

    POST /profil/foto/upload -> Profil::uploadFoto()        (app/Controllers/Profil.php:62)
      -> FotoProfilService::simpan($file)                   (app/Libraries/FotoProfilService.php:57)
      -> UserModel::updateFotoProfil($userId, $filename)
    GET  /foto-profil/(:any) -> Profil::foto($filename)      (Profil.php:139)
      -> FotoProfilService::resolvePathUntukDitampilkan()    (FotoProfilService.php:125)
      -> streamed via setContentType(mime_content_type($path))->setBody(file_get_contents($path))

**Inbox send pipeline** (unchanged, reused as-is):

    POST /inbox/kirim        -> Inbox::kirim()         (app/Controllers/Inbox.php:1176)
      -> kirimKeConversation()                         (Inbox.php:2723)
      -> kirimTeksViaGateway()                         (Inbox.php:2855)
    POST /inbox/kirim-media  -> Inbox::kirimMedia()     (Inbox.php:1255)
      -> kirimMediaBiasa()                             (Inbox.php:1366)
      -> kirimMediaViaGateway()                        (Inbox.php:1505)

**Composer attachment queue** (reused, not modified) in
`app/Views/inbox/index.php`:
- Markup: `index.php:872-883` (`formBalas`, `btnLampirkanMedia`,
  `inputMediaBalasan`, `teksBalasan`, `btnKirimBalasan`).
- Queue state + render: `antrianMediaBalasan` array,
  `renderAntrianMediaBalasan()`, `tambahMediaBalasan(files)`
  (`index.php:2740-2825`, added by PR #47 / commit `6084e32`).
- Send dispatch: `kirimBalasan(e)` branches on queue length
  (`index.php:3226-3313`); per-item send is `kirimSatuMediaBalasan()`
  (`index.php:3319-3362`), POSTing `FormData` to `/inbox/kirim-media`.

**Existing JS fetch-list pattern** to mirror for "load template list":

    fetch('/inbox/percakapan/' + id + '/handoff')
      .then(res => res.json())
      .then(json => { if (json.status === 'success') renderRiwayatHandoff(json.handoffs); })
      (index.php:2290-2309)

**Config**: `InboxConfig->maxMediaUploadMb` default `15`
(`app/Config/Inbox.php:62`) — the existing outbound upload limit, reused
as-is for template image upload size.

**DB group convention**: `KategoriModel` has no `$DBGroup` (defaults to
`database.default` / `aulia_kasirdb`); `ConversationModel`/`MessageModel`
explicitly set `$DBGroup = 'inbox'`. No table named `balasan_template`
exists yet in either database (checked: `kategori`, `produk`, `pelanggan`,
`transaksi`, etc. in `2026-09-08-000001_CreateAuliaPosCore.php`; no
collision).

**Sidebar menu**: all admin items render flat inside the collapsible
"Administrasi" submenu (`app/Views/layout/main.php:923-968`), e.g. the
`Kategori` link at `main.php:945-951`. Active-state detection uses
`$__seg(...)` with a hardcoded segment list at `main.php:652`.

## 3. Options

**Option set A — where does `balasan_template` live?**

- **A. `database.default` (`aulia_kasirdb`)**: matches the `KategoriModel`
  precedent (admin-managed POS settings, not WhatsApp archive data). Inbox
  controller methods that read it must open a second connection
  (`db_connect()` without `'inbox'`, or a model without `$DBGroup`) alongside
  the `inbox`-group calls already used for conversations/messages in the
  same request.
- **B. `database.inbox` (`aulia_inboxdb`)**: matches the module that
  consumes it (Inbox). Keeps the read inside the same connection the
  composer's other Inbox queries already use, but breaks the established
  convention that `aulia_inboxdb` is WhatsApp archive data, not POS
  settings, and would be the first non-WhatsApp-data table in that schema.

**Recommendation: A**, because the requirement (§7, open question 1)
already identifies this as consistent with `kategori`/`produk` precedent,
and `kirimMedia()`/`kirim()` already run in default-DB request context for
everything except the explicit `db_connect('inbox')` calls — adding one more
plain `Model::find()` against `database.default` is the smaller deviation.

**Option set B — how does the cashier pick a template?**

- **A. Modal dialog** listing templates by name, opened by a new composer
  button; clicking a row calls `tambahMediaBalasan()`-equivalent logic to
  push the template's image (as a `File`-like object) and text into the
  existing queue/textarea.
- **B. Native `<select>` dropdown** inline in the composer toolbar.

**Recommendation: A**, because AC-8 says "daftar nama template muncul
(modal/dropdown)" and a modal gives room for an empty-state message (AC-12)
and matches the existing Handoff modal pattern already in `index.php`
(riwayat Handoff modal), which is the closest existing UI precedent for
"list of items with a selection action" in this view.

## 4. Planned changes

| File | Change | Reason |
|---|---|---|
| `app/Database/Migrations/2026-10-03-000001_CreateBalasanTemplate.php` | New migration, `database.default` (no `$DBGroup`), creates `balasan_template` (`id`, `nama` unique, `teks` nullable, `gambar_filename` nullable, `created_at`/`updated_at` as `timestamp` matching the `kategori` baseline convention) | New table, AC-1..AC-6 |
| `app/Models/BalasanTemplateModel.php` | New model, clone of `KategoriModel` shape (`$table`, `$primaryKey`, `$allowedFields`, `$useTimestamps`) | Data access for CRUD + listing |
| `app/Libraries/BalasanTemplateImageService.php` | New service, clone of `FotoProfilService` (store under `WRITEPATH/uploads/balasan_template/`, server-generated filename, MIME+`getimagesize()` validation, `resolvePathUntukDitampilkan()`) | AC-4, isolated storage per requirement §5 |
| `app/Controllers/BalasanTemplate.php` | New controller: `index()`, `tambah()`, `simpan()`, `edit($id)`, `update($id)`, `hapus($id)` (admin CRUD, clone of `Kategori.php` shape, `required_without` validation so at least one of teks/gambar is present) | AC-1..AC-7 |
| `app/Views/balasan_template/index.php`, `tambah.php`, `edit.php` | New views, Bootstrap form matching `app/Views/kategori/*.php` | Admin UI |
| `app/Controllers/Inbox.php` | New method `apiBalasanTemplate()` -> `GET /inbox/api/balasan-template`, admin+kasir, returns `{status, templates:[{id,nama,teks,gambar_url}]}` (no new DB group touched beyond a plain `BalasanTemplateModel` read) | AC-8, AC-12 — list endpoint for the composer modal |
| `app/Controllers/BalasanTemplate.php` | Also exposes `foto($filename)` streaming method (clone of `Profil::foto()`), OR reuse a single shared helper — see Not yet verified #1 | AC-9 — `<img>` source for template images in Inbox UI |
| `app/Config/Routes.php` | Add 6 admin CRUD routes (`/balasan-template...`, `filter => 'auth'`) + 1 image route (`/balasan-template/foto/(:any)`) + 1 Inbox list route (`/inbox/api/balasan-template`, `filter => 'auth'`) | Routing |
| `app/Filters/AuthFilter.php` | Add `'balasan-template'` to `$adminRoutes` (line 70) | AC-7 — kasir blocked from CRUD pages |
| `app/Views/layout/main.php` | Add one `<li>` inside the existing "Administrasi" submenu (after `Kategori`, `main.php:945-951` pattern), and add `'balasan-template'` to the `$__seg(...)` active-detection list (`main.php:652`) | Admin navigation |
| `app/Views/inbox/index.php` | Add a new composer button (`btnTemplateBalasan`) next to `btnLampirkanMedia` (`index.php:874`); add a modal partial listing templates; add JS: `bukaModalTemplate()` (fetches `/inbox/api/balasan-template`), `pilihTemplate(template)` (pushes into `antrianMediaBalasan` if image present, and/or sets `teksBalasan.value`) | AC-8..AC-11 |

## 5. Impact

- **Database / migrations**: one new additive table in `database.default`
  (`aulia_kasirdb`). No existing table altered. No `inbox`-group migration
  needed.
- **Routes / API / response formats**: purely additive new routes; no
  existing route's request/response shape changes. The new
  `GET /inbox/api/balasan-template` is a brand-new read-only JSON endpoint.
- **Gateway contract**: none. Sending still goes through the unmodified
  `/inbox/kirim` and `/inbox/kirim-media` controller methods, which already
  build the Gateway payload identically regardless of whether the
  image/text originated from a file picker or a template.
- **Existing data**: none affected; new table starts empty.
- **Security / validation at trust boundaries**:
  - Template image upload (admin-only) reuses the same MIME-from-content +
    `getimagesize()` validation as `FotoProfilService`, restricted to
    `image/jpeg`, `image/png`, `image/webp`.
  - Template image filenames are server-generated (`random_bytes` hex),
    never derived from the uploaded filename — same path-traversal defense
    as `FotoProfilService::resolvePathUntukDitampilkan()`.
  - `GET /inbox/api/balasan-template` is read-only, `filter => 'auth'`
    (any logged-in role, since AC-8 allows cashiers to use templates); it
    must not leak the physical `gambar_filename` path, only expose a URL
    through the streaming route.
  - CRUD routes gated by `AuthFilter::$adminRoutes` prefix match, identical
    mechanism already protecting `kategori`/`produk`.
- **Transactions / concurrency / rollback behavior**: single-row
  insert/update/delete per request, no multi-step writes; matches
  `Kategori`'s transaction-less pattern (acceptable here because this is
  config data, not financial/state data per AGENTS.md §7).

## 6. Test plan

| Acceptance criterion | Test (file::method) | Type |
|---|---|---|
| AC-2, AC-3 | `tests/unit/BalasanTemplateModelTest.php::test_requires_text_or_image` | unit |
| AC-4 | `tests/unit/BalasanTemplateImageServiceTest.php::test_rejects_oversized_or_wrong_mime` | unit |
| AC-5, AC-6 | `tests/unit/BalasanTemplateImageServiceTest.php::test_replace_and_delete_removes_old_file` | unit |
| AC-1, AC-2, AC-6 | `tests/feature/BalasanTemplateCrudTest.php::test_admin_can_create_edit_delete` | feature (DB) |
| AC-7 | `tests/feature/BalasanTemplateCrudTest.php::test_kasir_blocked_from_crud_routes` | feature (DB) |
| AC-8, AC-12 | `tests/feature/InboxTemplateListTest.php::test_list_endpoint_returns_templates_or_empty` | feature (DB) |
| AC-9, AC-10, AC-11 | `tests/js/inbox-template.test.js::...` (new file, mirrors `tests/js/inbox-thread.test.js` style) covering: selecting a template populates the queue/textarea; editing/removing after selection behaves like a normal queue item; existing `kirimAntrianMediaBalasan()`/text-send path is unchanged | JS unit |

## 7. Risks and mitigations

- Adding a 7th admin CRUD surface increases `app/Views/layout/main.php`
  sidebar clutter -> mitigated by nesting under the existing "Administrasi"
  submenu rather than creating a new top-level group (per requirement,
  deferred: exact position TBD with user, see Not yet verified #2).
- `app/Views/inbox/index.php` is already 3609 lines (per earlier research)
  -> mitigated by keeping template-selection JS minimal (reuses
  `antrianMediaBalasan` machinery instead of duplicating queue logic); if it
  grows past a few dozen lines, extract to a new
  `public/assets/js/inbox-template.js` file, following the precedent set by
  `inbox-thread.js`'s extraction.
  Thread
- Two near-identical "stream a WRITEPATH image by generated filename"
  controllers (`Profil::foto()` and the new `BalasanTemplate::foto()`)
  duplicate ~10 lines of streaming logic -> acceptable per existing
  precedent (no shared base method exists yet for this), but flagged in
  Not yet verified #1 in case a shared helper is preferred instead.

## 8. Not yet verified

1. Whether to add a second near-duplicate streaming controller method
   (`BalasanTemplate::foto()`) or extract a tiny shared trait/base method for
   "stream a validated WRITEPATH file by generated filename" shared with
   `Profil::foto()`. Leaning toward duplicating (YAGNI, matches current
   codebase style of no shared base for this), but worth confirming.
2. Exact sidebar label/icon and position within "Administrasi" (after
   `Kategori`? after `Migrasi Database`?) — cosmetic, low-impact, can be
   decided at implementation time without re-opening Gate 2.
3. Response shape of `GET /inbox/api/balasan-template` -- does `gambar_url`
   need to be an absolute `base_url('/balasan-template/foto/...')` string
   built server-side, or just the bare filename with the client
   constructing the URL (matching how `main.php:1111-1113` builds
   `base_url('/foto-profil/' . $__headerFoto)` client/view-side)? Proposed:
   server builds the full URL, since this is a JSON API consumed by
   JavaScript rather than a Blade/PHP view with `base_url()` available
   inline — needs no further decision, flagged only for implementer
   awareness.

## 9. Approval (Gate 2)

- [ ] Approved by: <name>, date: <YYYY-MM-DD>
