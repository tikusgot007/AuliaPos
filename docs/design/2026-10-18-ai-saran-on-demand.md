# Design: On-Demand AI Suggestions via 9router

English technical specification (AGENTS.md §2.1).

- **Date**: 2026-10-19
- **Status**: approved (Gate 2, 2026-10-19)
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

- [x] Approved by: Aan, date: 2026-10-19
