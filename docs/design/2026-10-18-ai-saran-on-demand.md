# Design: On-Demand AI Suggestions via 9router

English technical specification (AGENTS.md §2.1).

- **Date**: 2026-10-19
- **Status**: approved (Gate 2, 2026-10-19), incl. amendment §10.
- **Requirements**: `docs/requirements/2026-10-18-ai-saran-on-demand.md` (Gate 1 approved 2026-10-19)
- **SDLC tier**: A

## 1. Summary

Add a "Minta Saran AI" button inside the existing suggestions panel of the Inbox. A new cashier-authenticated endpoint loads today's messages of the conversation (fallback: last 15), calls 9router (`/v1/chat/completions`, model `aan-groq`), validates the JSON answer, and writes it to `mara_suggestions` using the same supersede+insert transaction the M.A.R.A endpoint already uses. The existing "Buat Pesanan" button then fills the cart from `cart_items` (`KeranjangDariSaran`). No schema change.

## 2. Current flow (verified by reading the files this session)

Read path (unchanged):

    Browser inbox-suggestions.js:67  GET /inbox/api/conversations/(:num)/suggestions
      -> Routes.php:69 [filter: auth]
      -> Inbox::apiSuggestions()                      (app/Controllers/Inbox.php:556)
      -> MaraSuggestionModel::activeForConversation() (app/Models/MaraSuggestionModel.php:57)

Write path used by M.A.R.A (the logic the new endpoint must mirror):

    POST /api/inbox/mara/suggestions [filter: maratoken]   (Routes.php:53)
      -> MaraController::suggestions()                      (MaraController.php:275)
         type in SUGGESTION_TYPES = balasan|daftar-periksa|peringatan   (:44, :294)
         text max 4096, items max 50 x 500 chars, cart max 20 lines, qty max 999 (:50-58)
         action only 'buka_kasir', only for type balasan|peringatan    (:65-68, :366-369)
         default TTL 30 min, expires_at in WIB                         (:71, :410-416)
      -> transBegin; lockConversation(); supersedeSameType(); insert(); transCommit
                                                            (:429-459; model :100, :118)
      row columns: conversation_id,type,priority,text,items,reason,status='active',
                   expires_at,created_at,updated_at, + action, + cart_items (JSON)

Message source: `MessageModel` (group `inbox`, table `messages`, soft deletes, columns `direction`,
`message_type`, `text`, `media_path`, `message_timestamp`; app/Models/MessageModel.php:23-58).

Cart fill (unchanged): `KeranjangDariSaran::bangun(saranId, pelangganId)` (app/Libraries/KeranjangDariSaran.php:24)
re-reads price/name/active state from `produk`; AI price/name is never used.

## 3. Options

- **A. Library + cashier endpoint (recommended)**: `AiSuggestionService` builds the prompt and calls 9router; `Inbox::generateSaran()` persists. Server-side, reuses existing model/transaction pattern.
- **B. Browser calls 9router directly**: CORS, no server validation, gateway exposed to the client.
- **C. Background worker**: not on-demand, burns tokens on every inbound message, extra service.

**Recommendation:** A.

## 4. Planned changes

| File | Change | Reason |
|---|---|---|
| `app/Config/AiGateway.php` | New. `baseUrl`, `model` (`aan-groq`), `apiKey` (from `.env`), `timeoutSeconds` (15), `fallbackLastN` (15), hardcoded `systemPrompt` | AC-4, AC-5; prompt change = edit + deploy |
| `app/Libraries/AiSuggestionService.php` | New. `kumpulkanPesan()` (today WIB, else last 15), `bangunPrompt()`, `panggil()` (cURL, 15s), `parse()` (strict JSON, whitelist fields, caps) | AC-3, AC-5, AC-8; reusable by a later webhook/worker trigger |
| `app/Controllers/Inbox.php` | New `generateSaran($conversationId)`: 404 if conversation missing, call service, then lockConversation+supersedeSameType+insert in one transaction per type, return fresh `activeForConversation()` | AC-6, AC-7 |
| `app/Config/Routes.php` | `POST /inbox/api/conversations/(:num)/generate-saran` with `['filter' => 'auth']` | Route |
| `public/assets/js/inbox-suggestions.js` | Button in panel, disabled + spinner while fetching, re-render on success, toast on failure; CSRF header as other Inbox POSTs | AC-1, AC-2, AC-7, AC-8 |
| `docs/CHANGELOG.md` | Entry (Bahasa Indonesia) | Business-visible feature |
| tests (see §6) | New | Verification |

## 5. Impact

- **DB / migrations**: none. Writes `mara_suggestions` (group `inbox`); reads `messages`, `conversations`.
- **Routes / API**: additive, one POST. Existing endpoints unchanged.
- **Gateway contract (WA-Gateway, M.A.R.A)**: none. Rows are indistinguishable from M.A.R.A rows, so a running M.A.R.A and this button supersede each other per type. Accepted.
- **Existing data**: safe, only same-type active rows of that conversation are superseded.
- **Security**:
  - `auth` filter + CSRF.
  - Group conversations are blocked (decision 2026-10-19): `generateSaran()` returns 403 when `jid_type === 'group'`, same check as `Inbox::bisaAmbilPercakapan` (Inbox.php:1273). The button is hidden for group conversations.
  - LLM output is untrusted: `json_decode`, whitelist `type`/`text`/`items`/`cart_items`, apply the same caps as `MaraController`, drop everything else. `cart_items` keeps only `produk_id` + `jumlah`; `action = 'buka_kasir'` only on a `balasan` row (mirrors :366-369).
  - Customer message text goes to an external LLM through 9router. Send no phone number or contact name, only message text and direction.
  - Never log the full prompt or API key.
- **Transactions**: same lock+supersede+insert transaction as `MaraController.php:429-459`.

## 6. Test plan

| AC | Test | Type |
|---|---|---|
| AC-1, AC-2, AC-7 | `tests/js/inbox-suggestions.test.js`: button rendered, disabled while pending, panel re-render after success | JS (`node --test`) |
| AC-3 | `tests/unit/AiSuggestionServiceTest.php::testPesanHariIniLalu15TerakhirBilaKosong` (today present, today empty, 20 images today all kept) | unit |
| AC-4 | `...::testSystemPromptDariConfig` | unit |
| AC-5 | `...::testParseJsonValidDanBuangFieldAsing` with fake HTTP client | unit |
| AC-6 | `tests/feature/InboxAiSuggestionTest.php::testSimpanDanSupersedeTipeSama` | feature |
| AC-8 | `...::testTimeout429JsonRusakTidakMenulisDb` | unit + feature |
| auth | `...::testTanpaLoginDitolak` | feature |
| group block | `...::testPercakapanGrupDitolak403` | feature |

The service takes an injectable HTTP callable so tests never hit the real 9router.

## 7. Risks and mitigations

- 9router unreachable / 429 / timeout -> 15s cap, friendly error, no DB write.
- Malformed JSON -> one regex extraction attempt, else error, no DB write.
- Reasoning model eats `max_tokens` -> set `max_tokens` >= 4096.
- Non-streaming reply may carry trailing `data: [DONE]` -> take first non-empty line only.
- Cashier double click -> button disabled (AC-2) plus `lockConversation()` server side.

## 8. Not yet verified

- **9router in production**: per Aan (2026-10-19), `aulia-server2` runs its own 9router, same setup as dev, so `baseUrl` stays `http://localhost:20128/v1` in both. Not yet checked on the server: 9router installed and running, `aan-groq` combo present, and Groq key filled there. Verify before deploy.
- `aan-groq` live status: probe returned `Missing API key` (no client key sent), so the Groq connection and combo contents are unconfirmed. Probe with a real gateway key before the integration test.
- Real Groq rate limits (TPM) not measured; 9router reports 128k context.
- Contents of `tests/js/inbox-suggestions.test.js` not read.

## 9. Approval (Gate 2)

- [x] Approved by: Aan, date: 2026-10-19 (scope §1-§9)

## 10. Amendment 2026-10-19: product lookup from `produk` (two-stage call)

### 10.1 Why

Implementation of §1-§9 shipped with a hardcoded 15-product catalog in `Config\AiGateway::$katalog`. Verified in `aulia_kasirdb.produk` (468 rows, 413 with `harga_jual > 0`): 3R (id 72, Rp 2.500), 4R (73), 5R (74), 6R (75) and many frames exist, so the AI refused prices that the shop really has. The whole table is too large for the prompt, and a copied price list goes stale when prices change.

### 10.2 Flow (replaces the single AI call of §1)

    generateSaran()
      1. AiSuggestionService::kumpulkanPesan()            (unchanged)
      2. tahap 1: AI call, short prompt, timeout 8s
           in : conversation text
           out: {"cari": ["4R", "frame 4R"]}               (<= 5 terms)
           failure / empty / invalid -> terms = [], continue
      3. ProdukPencari::cari(terms)                         (new, DB only)
           SELECT id, nama, harga_jual FROM produk
           WHERE is_active=1 AND is_locked=0 AND harga_jual>0 AND nama LIKE %term% (escaped)
           LIMIT 30 total, dedupe by id
      4. tahap 2: AI call, existing reply prompt, catalog =
           base list (the 15 ids, prices re-read from `produk`) + search hits
      5. parse(): cart_items validated against `produk` (active, unlocked, price > 0)
      6. lock + supersede + insert                          (unchanged)

Both stages share the 429 retry (one retry, 3s). Total wall clock capped at 20s; stage 1 failure never fails the request.

### 10.3 Changes

| File | Change |
|---|---|
| `app/Libraries/ProdukPencari.php` | New. `cari(array $terms): array` (sanitize: trim, 2-40 chars, strip `%` `_`, max 5 terms; query builder with bound params, max 30 rows) and `valid(array $ids): array` (ids that are active, unlocked, price > 0) |
| `app/Libraries/AiSuggestionService.php` | Add stage 1 `tentukanKataCari()`; stage 2 prompt receives catalog string; `keranjang()` validates via `ProdukPencari::valid()` instead of `$config->katalog` |
| `app/Config/AiGateway.php` | `$katalog` becomes `$produkDasar` = list of 15 ids only (no names/prices); new `$promptCari` (stage 1) and `timeoutCariSeconds = 8`; `{{KATALOG}}` is filled at call time |
| `app/Controllers/Inbox.php` | None beyond what exists |
| tests | Unit: term sanitizing, LIKE escaping, cap 30, cart validation against DB, stage 1 failure still yields a reply. Feature: 4R conversation gets id 73 in the stage 2 prompt (fake router records request bodies) |
| `docs/CHANGELOG.md` | Update the "Katalog AI" entry |

### 10.4 Impact

- DB: read-only on `produk` (group `default`). No schema change. Prices always come from the master table, so there is nothing to keep in sync by hand.
- Cost per click (measured from the Groq log, qwen/qwen3.8-27b): about 2.300 tokens and 2.7s today; with stage 1 about 3.000 tokens and about 6s. Requests per minute double, so 429 risk rises; the shared retry mitigates it.
- Security: stage 1 output is untrusted. Terms are sanitized and used only as bound `LIKE` parameters, never concatenated into SQL. Search hits go into the stage 2 prompt as data (name, id, price), no instructions. Cart ids are re-validated against `produk`.
- The AI still never sets prices; `KeranjangDariSaran` re-reads them at cart time (§2).

### 10.5 Risks

- Stage 1 misses a term -> stage 2 says "harga kami cek dulu" (safe failure, same as today).
- Common terms such as "foto" match many rows -> hard cap 30 and a 2-char minimum; order by `sort_order, id`.
- Two calls in a row exceed the 15s UI expectation -> stage 1 has its own 8s cap; stage 1 failure is skipped.

### 10.6 Approval (Gate 2, amendment)

- [x] Approved by: Aan, date: 2026-10-19

## 11. Amendment 2026-10-19: prices computed by the server, not the model

### 11.1 Why

Live runs of §10 (qwen/qwen3.8-27b) gave a correct `cart_items` every time but a different total in the text each run (Rp 58.000 / 57.000 / 50.000 / 40.000 for a true Rp 60.000), and once put the 4R price on a 10R line. The model cannot be trusted with arithmetic or price-to-item mapping.

### 11.2 Change

- Stage 2 catalog lists `name: ID n` only, no price. Prompt rule 1: never write a price, total or "Rp"; say "harga ... kami cek dulu".
- `AiSuggestionService::parse()`:
  1. `buangHarga()` drops every sentence with a price pattern (`Rp <digit>`, `<digit>rb|ribu|k`). All sentences dropped -> no suggestion.
  2. If a valid cart exists (`action=buka_kasir`), `total()` sums `harga_jual x jumlah` from `produk` and `sisipTotal()` inserts `Total Rp N.` before the trailing emoji.
  3. No valid cart -> no price at all in the text.
- No schema change, no UI change.

### 11.3 Trade-off

A reply to "berapa harga 4R?" no longer carries a number; the cashier types it (or the customer is told "kami cek dulu"). Wrong prices cannot reach the customer from the AI.

### 11.4 Approval (Gate 2, amendment 2)

- [x] Approved by: Aan (choice "A"), date: 2026-10-19
