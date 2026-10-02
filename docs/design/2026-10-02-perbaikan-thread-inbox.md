# Design: Inbox thread improvements (latest-window pagination, per-message rendering)

- **Date**: 2026-10-02
- **Status**: draft (stages 1, 2 and 3 implemented; Node.js v22 confirmed available on the developer machine)
- **Requirements**: `docs/requirements/2026-10-02-perbaikan-thread-inbox.md`
- **SDLC tier**: A

## 1. Summary

Keep the hand-written Inbox thread (no UI library, per `docs/laporan-spike-vue-advanced-chat.md`). The messages endpoint returns the 200 **latest** messages and accepts an optional `before_id` keyset cursor; the thread logic moves into `public/assets/js/inbox-thread.js`, renders per message instead of replacing `innerHTML`, merges older pages client-side, and adopts the visual details of `vue-advanced-chat` (date capsule, scroll-to-latest button, ticks, autolink).

## 2. Current flow (verified)

    GET /inbox/api/conversations/(:num)/messages    (app/Config/Routes.php:40)
      -> Inbox::apiMessages()                       (app/Controllers/Inbox.php:383)
      -> MessageModel::getByConversation($id, 500)  (app/Models/MessageModel.php:216; call at Inbox.php:400)
         ORDER BY message_timestamp ASC, id ASC LIMIT n   => returns the OLDEST n rows (bug)
      -> attachSenderNames() + is_internal/is_forwarded cast to bool
      -> JSON { status, conversation, messages }

    Browser: setInterval 4 s -> muatUlangPesan() (app/Views/inbox/index.php:2965)
      -> renderPesan(messages, paksaScroll)         (index.php:2910)  replaces #threadMessages.innerHTML
    Other writers of the thread: tampilkanBubbleOutgoing() (index.php:3127), pilihConversation() (index.php:2080)

Callers of `getByConversation()`: only `Inbox::apiMessages()` (verified with grep over `app/` and `tests/`). Consumers of the endpoint: only `muatUlangPesan()` in `index.php` (grep for `api/conversations`; the other two hits are the conversation list). Not yet verified: any external consumer outside this repository (none expected).

## 3. Options

- **A. Keyset cursor on `(message_timestamp, id)`**: stable across late-arriving messages and equal timestamps; one extra lookup of the cursor row.
- **B. Cursor on `id` only**: simplest, but wrong when the gateway delivers a message with an older `message_timestamp` after a newer one has been stored (ordering key and cursor key differ).
- **C. Offset pagination**: shifts as new messages arrive; rejected.

**Recommendation:** A, because the page boundary must follow the same ordering contract as the display order.

## 4. Planned changes

| File | Change | Reason |
|---|---|---|
| `app/Models/MessageModel.php` | `getByConversation(int $conversationId, int $limit, ?int $beforeId = null): array` returns the latest `$limit + 1` rows before the cursor (query DESC, reversed to ASC); a small helper for the cursor row | AC-1, AC-2, AC-23, AC-24 |
| `app/Controllers/Inbox.php` | page-size constant (pattern of `CL-010`); read and validate `before_id`; trim to `$limit` and add `has_more` | AC-3, AC-25, AC-26 |
| `public/assets/js/inbox-thread.js` (new) | thread rendering. Stage 2 implemented: the bubble HTML itself is the change signature (`renderBubbleHtml`), `rencanaPembaruanThread` is a pure planner (keep/replace/add/remove), `terapkanRencanaThread` applies it to the DOM. Later stages add merge/dedup of older pages, date label, text format, autolink; `index.php` passes `base_url` and counters through one config object | AC-4 to AC-6 |
| `app/Views/inbox/index.php` | remove thread functions now in the new file; load the script; "Load older" button; CSS for date capsule, scroll button, ticks | AC-7 to AC-31 |
| `tests/js/inbox-thread.test.js` (new) | `assert`-based, run with `node` | AC-6, AC-7 to AC-22 |
| `tests/feature/InboxMessagesPaginationTest.php` (new) | latest window, boundary, same timestamp, invalid cursor | AC-1 to AC-3, AC-23 to AC-26 |
| `docs/CHANGELOG.md` | history limit 500 oldest -> 200 latest + pagination | business-visible change |

Cursor query (sketch, to be validated against the real schema in stage 1):

    cursor = SELECT message_timestamp, id FROM messages WHERE id = :before_id AND conversation_id = :cid
    rows   = WHERE conversation_id = :cid
             AND (message_timestamp < :T OR (message_timestamp = :T AND id < :id))   -- only when a cursor is given
             ORDER BY message_timestamp DESC, id DESC LIMIT :limit + 1
    has_more = count(rows) > limit; rows = reverse(first :limit of rows)

## 5. Impact

- **Database / migrations**: none. Index `(conversation_id, message_timestamp)` exists (`2026-09-07-000001_CreateInboxTables.php:222`; InnoDB appends the primary key `id`). Verified with `EXPLAIN` on MariaDB 10.11 with 20,000 rows in one conversation: the cursor query uses `conversation_id_message_timestamp`, no filesort. Walking far back reads and skips the newer rows of that conversation on the index (linear in the number of newer messages, acceptable at the expected volumes).
- **Routes / API / response formats**: same route, new optional query parameter, new additive field `has_more`. Not breaking for the current consumer.
- **Gateway contract**: unchanged. Other repository not touched.
- **Existing data**: read-only.
- **Security / validation**: `before_id` cast to a positive int, must belong to the same conversation (otherwise 404, no data from other conversations); autolink only for `http(s)`, applied after HTML escape; no `innerHTML` with unescaped data.
- **Transactions / concurrency**: reads only. A message stored between two page requests can only appear in the latest window, never duplicate in an older page; the client dedups by `id`.

## 6. Test plan

| Acceptance criterion | Test (file::method) | Type |
|---|---|---|
| AC-1, AC-2, AC-3 | `InboxMessagesPaginationTest::testLatestWindow*` | feature (needs `aulia_inboxdb_test`) |
| AC-23, AC-24, AC-25, AC-26 | `InboxMessagesPaginationTest::testCursor*` | feature |
| AC-6, AC-7 to AC-13 | `tests/js/inbox-thread.test.js` (signature, merge, replace/remove rules) | JS assert |
| AC-14 to AC-16 | same file (date label, separator not counted as a message) | JS assert |
| AC-17 to AC-19, AC-27 | same file (merge keeps older pages, jump-to-source decisions) | JS assert |
| AC-20 to AC-22, AC-31 | same file (format, XSS vectors, autolink schemes) | JS assert |
| Visual and regression | manual list from requirements section 7 plus the differential HTML check reused from the spike | manual / Playwright |

PHP feature tests cannot run in the authoring sandbox (no MariaDB); they run on a developer machine. This overlaps `TODO-T5` (feature tests are not wired into `composer test`).

## 7. Risks and mitigations

- Per-message diffing misses client-side state in the signature -> include `mediaGagal`, `mediaSementara`, `gatewayTerhubung`, `pesanTerkirimTanpaKutipan` in the signature; test each.
- Outgoing bubble inserted by `tampilkanBubbleOutgoing()` duplicated by polling -> same key and signature as the polling path (AC-12).
- Scroll jump when older messages are prepended -> record the anchor element offset before the insert and restore it after.
- Jump-to-source loops over many pages -> hard cap of 5 pages, then the toast.

## 8. Implementation notes (stage 3)

- `gabungPesanThread()` merges the latest window with everything already known: a known message inside the window's range that is missing from the response is deleted, one older than the window is kept (it was loaded with "Load older" or just slid out). Limitation: a message deleted on the server that sits in an already loaded older page is not noticed until the conversation is reopened.
- `susunItemThread()` builds the item list (load-older button, date separators, bubbles); the same planner/applier handles all item kinds.
- `muatPesanLama()` keeps the reading position by adding the height gained on top to `scrollTop`; `loncatKeKutipan()` loads up to 5 older pages to find the quoted message.
- `formatTeksWa()`: escape first, then code and URLs are set aside in placeholders (`\u0000N\u0000`, forged ones are stripped from the input), then `*`, `_`, `~` with word-boundary rules.
- `muatUlangPesan()` in `index.php` drops a response that arrives after the cashier switched conversation.

## 9. Not yet verified

- Real message volume per conversation in production beyond the 160 observed.

## 10. Approval (Gate 2)

- [ ] Approved by: <name>, date: <YYYY-MM-DD>
