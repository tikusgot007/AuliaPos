# AuliaPos Architecture

## 1. Scope

This document describes the current architecture of the AuliaPos application as observed on branch `v2.3` (updated 2026-09-27).

AuliaPos is a CodeIgniter 4 application running on PHP 8.2+ and providing the core POS workflows plus a Shared WhatsApp Inbox module.

This map is an architectural reference, not a product specification. Feature requirements remain defined by the applicable PRD, specification, ADR, and implementation plan.

## 2. Runtime and Framework

| Concern | Current implementation |
| --- | --- |
| Application framework | CodeIgniter 4 (`^4.7`) |
| Runtime | PHP `^8.2` |
| Primary database | MySQL/MariaDB via CodeIgniter `default` connection |
| Inbox database | Separate MySQL/MariaDB connection group `inbox` |
| Archive database | Separate SQLite connection group `archive` |
| Test database | In-memory SQLite connection group `tests` |
| Inbox test database | MySQL/MariaDB `aulia_inboxdb_test`, forced by `Config\Database` for group `inbox` under `testing` |
| Dependency management | Composer |
| Test framework | PHPUnit `^10.5.16` |
| Production web entry point | `public/index.php` |
| CLI entry point | `spark` |

## 3. High-Level Structure

```text
AuliaPos/
├── app/
│   ├── Commands/          # Application CLI commands
│   ├── Config/            # Framework and application configuration
│   ├── Controllers/       # HTTP/application entry points
│   ├── Database/
│   │   ├── Migrations/    # Production schema migrations
│   │   └── Seeds/         # Master/bootstrap data
│   ├── Filters/           # HTTP authentication/security filters
│   ├── Helpers/           # Shared procedural helpers
│   ├── Language/          # Framework/application language resources
│   ├── Libraries/         # Reusable infrastructure/integration libraries
│   ├── Models/            # Persistence and domain-oriented data access
│   ├── Services/          # Reusable application/domain services
│   ├── ThirdParty/        # Local third-party integration area
│   └── Views/             # Server-rendered UI
├── docs/                  # Business and technical documentation
├── public/                # Web root and static assets
├── tests/
│   ├── database/          # Database/model/integration tests
│   ├── session/           # HTTP/session integration tests
│   ├── unit/              # Unit and service-level tests
│   └── _support/          # Test-only migrations, fakes, and support classes
├── composer.json
└── spark
```

## 4. Application Layers

### 4.1 HTTP / Controller Layer

Controllers live under `app/Controllers/` and are registered through `app/Config/Routes.php`.

Major application areas include:

- `Auth` — authentication and user-management workflows.
- `Kasir` — POS cashier workflow.
- `Produk`, `Kategori`, `Pelanggan` — master-data workflows.
- `Transaksi`, `Pembayaran`, `Tagihan`, `Cash`, `Laporan` — POS operational workflows.
- `Jadwal` — employee schedule management.
- `Inbox` — browser-facing Shared WhatsApp Inbox workflow.
- `InboxGatewayApi` — machine-to-machine Gateway ingress.
- `ArchiveTransaksi` — archive workflow.
- `MigrasiManual` — authenticated migration workflow for environments without CLI access.

Authentication is applied through the `auth` filter to browser routes. Gateway ingress uses the dedicated `gatewaytoken` filter.

### 4.2 Model / Persistence Layer

Models live under `app/Models/`.

The Inbox persistence boundary is explicitly separated from the POS database:

- `ConversationModel`
- `MessageModel`
- `ConversationHandoffModel`
- `GatewayStatusModel`
- `ConversationIdentityModel`

These Inbox models use the `inbox` database group where applicable. The separation prevents Inbox persistence from accidentally using the primary POS database.

Core POS models include users, products, categories, customers, transactions, payments, cash, and scheduling.

In the Inbox module, `UserModel::daftarKasirAktif()` is the single source of the active-kasir list: the Handoff
dialog target dropdown, the 409 current-owner naming and the `belum_diambil` initiator check all derive from it, so
the UI and the server-side gate can never drift into two different lists.

### 4.3 Service Layer

Reusable application/domain logic lives under `app/Services/`.

Examples:

- `InboxSlaService` — deterministic SLA presentation calculation for the Inbox.
- `InboxMatchSnippetService` — pure Match Snippet cutting for Inbox conversation search (no DB, session, or request access).
- `EffectiveShiftLeaderService` — effective shift-leader resolution.
- `EvaluasiJendelaKerjaShift` — shift work-window evaluation.
- `TransaksiArchiveService` — SQLite transaction archive operations.
- `KalkulasiStatusPembayaran`, `KalkulasiDiskonTransaksi`, `KalkulasiJatuhTempo` — isolated calculation services.

Services are used to keep reusable business/application logic out of controllers where practical.

### 4.4 Libraries / Infrastructure

`app/Libraries/` contains reusable infrastructure components such as:

- `InboxMediaStorage` — Inbox media persistence/retrieval abstraction.
- `PhoneNumber` — phone-number handling.
- `FotoProfilService` — profile-photo handling.

## 5. Database Topology

AuliaPos intentionally uses multiple database groups.

```text
                    ┌──────────────────────┐
                    │   CodeIgniter App    │
                    └──────────┬───────────┘
                               │
          ┌────────────────────┼─────────────────────┐
          │                    │                     │
          ▼                    ▼                     ▼
   default / POS          inbox / Inbox        archive / SQLite
   MySQL/MariaDB          MySQL/MariaDB         SQLite file
          │                    │                     │
   POS business data     Conversations,          Archived
   and transactions      messages, gateway       transactions
                         status, identity
                              
                    tests / PHPUnit
                         SQLite :memory:
```

During the testing environment, both real MySQL groups are redirected so tests never write to live data:

- `default` → the `tests` group (SQLite `:memory:`);
- `inbox` → the dedicated database `aulia_inboxdb_test` (same server credentials from `.env`; the database name is forced, and `DSN`/`failover` are cleared so an `.env` DSN or failover entry cannot redirect the connection).

Both redirects live in `Config\Database::__construct()`. In addition, `tests/_support/bootstrap.php` refuses to start PHPUnit if the `inbox` group does not resolve to `aulia_inboxdb_test`.

Production migrations live in `app/Database/Migrations/`. Test-only schema support lives in `tests/_support/Database/Migrations/`.

## 6. Shared WhatsApp Inbox Architecture

The Inbox is a bounded application module inside AuliaPos with an external WhatsApp Gateway.

```text
WhatsApp
   │
   ▼
WA-Gateway (separate repository / Node.js + Baileys)
   │
   │ HTTP + Bearer token
   ▼
InboxGatewayApi
   │
   ▼
Inbox database
   │
   ├── conversations
   ├── messages
   ├── conversation_handoffs
   ├── gateway_status
   └── conversation identity data
   │
   ▼
Inbox controller / models / services
   │
   ▼
Server-rendered Inbox UI
```

### Balas Pesan (Reply with Quote) Architecture

The Reply feature allows cashiers to reply to a specific message with a quote. It spans both AuliaPos and the WA-Gateway.

**Database additions (`messages` table):**
- `quoted_wa_message_id` — `VARCHAR(64) NULL` (WhatsApp message ID of the quoted message)
- `quoted_source_message_id` — `INT UNSIGNED NULL` (local `messages.id` of the quoted message, for media live-fetch)
- `quoted_media_available` — `TINYINT(1) NOT NULL DEFAULT 0` (0 = text/unsupported, 1 = media available for live-fetch)
- `quoted_media_type` — `VARCHAR(30) NULL` (`image`, `sticker`, `document`, `audio`, `video`; `NULL` for text or not found)
- `quoted_sender_label` — `VARCHAR(191) NULL` (display name of the quoted message sender)
- `quoted_snippet` — `TEXT NULL` (trimmed, normalized quote preview, max 200 chars, multibyte-safe)

There is no `quoted_from_me` column: `quoted.fromMe` is a runtime-only field sent in the Gateway payload (`POST /send`/`/send-media`), derived at request time from the source message's `direction` column (`outgoing` → `true`, `incoming` → `false`). It is never persisted on `messages`.

**Gateway contract (`WA-Gateway`):**
- `POST /send` and `POST /send-media` accept optional `quoted` object:
  ```json
  { "quoted": { "wa_message_id": "string", "sender_jid": "string?", "snippet": "string?" } }
  ```
- Response includes `quote_applied: true|false` (only when `quoted` was provided; legacy responses unchanged).
- Inbound webhook extracts `contextInfo` → `quoted: { wa_message_id, sender_jid?, snippet? }` in payload.

**Service: `InboxQuoteSnapshotService` (`app/Services/InboxQuoteSnapshotService.php`)**
- Pure, stateless service (no DB, session, or request access).
- `rakitSnapshot(array $sumber): array` — builds the 7 quote columns from a source message row.
- `potongSnippet(?string $teks, string $q): ?string` — normalizes whitespace, clamps to 200 multibyte chars, adds ellipsis `…` on cut sides.
- `tipeMediaSumber(string $messageType): ?string` — maps source `message_type` to `quoted_media_type` (`image`/`sticker`/`document`/`audio`/`video` or `NULL` for text).

**UI: `renderKotakKutipan()` in `app/Views/inbox/index.php`**
- Single component renders quote box for both outgoing and incoming quotes.
- 5 display branches per `AC-005` (Spec v1.8):
  - (a) `quoted_media_available = 0` → text-only, no live-fetch
  - (b) `quoted_media_type IN ('image','sticker')` + live-fetch `GET /inbox/media/:quoted_source_message_id` → `<img>` with `onerror` fallback
  - (c) `quoted_media_type = 'document'` → link to media endpoint
  - (d) `quoted_media_type IN ('audio','video')` → label only (no fetch, Gateway rejects)
  - (e) `quoted_source_message_id IS NULL` (legacy/not found) → no live-fetch attempt
- Client-side `mediaGagal` memory (namespaced `'kutipan:' + messageId`) prevents repeated refetch polling on failure.

Gateway routes:

- `POST /api/inbox/gateway/messages`
- `POST /api/inbox/gateway/status`

These routes use the `gatewaytoken` filter rather than browser session authentication.

Browser Inbox routes use the `auth` filter.

### Outgoing send idempotency (M1 Wave 2)

Outbound replies cross the Gateway boundary carrying a caller-owned `operation_id`, so a cashier retry after a timeout does not deliver a second WhatsApp message.

- The reply form in `app/Views/inbox/index.php` owns the key: it creates it with `crypto.randomUUID()` (hex fallback for older browsers), reuses it while the cashier retries the same content, and discards it on success or when the content changes.
- AuliaPos forwards the key and stores it in `messages.gateway_operation_id` (nullable `VARCHAR(64)` with `UNIQUE uniq_messages_gateway_operation_id`, migration `2026-09-24-000001_AddGatewayOperationIdToMessages`). When the Gateway answers with a replayed result, AuliaPos returns the stored row instead of inserting a second one.
- The Gateway owns the matching `outgoing_operations` state machine inside the WA-Gateway repository and answers `409 SEND_IN_PROGRESS`, `504 SEND_UNRESOLVED`, or `409 OPERATION_ID_REUSED`; AuliaPos surfaces those as an "uncertain result" state instead of a plain failure.

| Concern | Location |
| --- | --- |
| Key creation and reuse | `app/Views/inbox/index.php` (reply-form JavaScript) |
| Forwarding, dedupe, response mapping | `app/Controllers/Inbox.php`: `kirimKeConversation()`, `kirimMedia()`, `findMessageByOperationId()`, `gatewayFailureResponse()` |
| Persistence | `messages.gateway_operation_id` |

## 7. Inbox HTTP Surface

Current Inbox routes include:

| Route | Responsibility |
| --- | --- |
| `GET /inbox` | Inbox page |
| `GET /inbox/api/conversations` | Conversation queue data |
| `GET /inbox/api/conversations/(:num)/messages` | Conversation thread |
| `GET /inbox/api/gateway-status` | Gateway status |
| `GET /inbox/media/(:num)` | Authenticated media access |
| `POST /inbox/kirim` | Send text reply (idempotent per caller-owned `operation_id`) |
| `POST /inbox/kirim-media` | Send media (idempotent per caller-owned `operation_id`) |
| `POST /inbox/percakapan/(:num)/ambil` | Take ownership |
| `POST /inbox/percakapan/(:num)/lepas` | Release ownership |
| `POST /inbox/percakapan/(:num)/tutup` | Close conversation |
| `POST /inbox/percakapan/(:num)/tandai-dibaca` | Mark conversation read |
| `POST /inbox/percakapan/(:num)/snooze` | Snooze conversation |
| `POST /inbox/percakapan/(:num)/catatan` | Add Internal Note |
| `POST /inbox/percakapan/(:num)/handoff` | Handoff ownership to another active kasir (conditional write + history) |
| `GET /inbox/percakapan/(:num)/handoff` | Handoff history for one conversation (newest-first, cap 50) |
| `POST /inbox/percakapan/(:num)/hapus` | Soft-delete conversation |
| `POST /inbox/percakapan/(:num)/profil` | Update customer profile |
| `POST /inbox/percakapan/(:num)/konfirmasi-nomor` | Confirm WhatsApp number |
| `POST /inbox/mulai-percakapan` | Start conversation |
| `GET /inbox/api/perlu-dibalas-count` | Sidebar reply-needed count |

**Outgoing replies with quote (`POST /inbox/kirim`, `POST /inbox/kirim-media`):**
- Request body includes optional `quoted_message_id` (local `messages.id`).
- Server resolves quote via `Inbox::resolveKutipan()` → writes 7 `quoted_*` columns.
- Response includes `quote_applied: true|false` (mirrors Gateway `quote_applied`).

**Incoming quote resolution (`InboxGatewayApi::resolveKutipanMasuk()`):**
- Called on inbound webhook (`POST /api/inbox/gateway/messages`).
- If quoted message found locally → builds snapshot from local row via `InboxQuoteSnapshotService` (ignores Gateway `snippet`).
- If not found → `quoted_sender_label = NULL`, snippet from Gateway payload / generic fallback.

`GET /inbox/api/conversations` accepts `page`, `status` and `q`. `q` matches the identity columns (`contact_name`, `whatsapp_name`, `phone`, `manual_phone`, `chat_id`) after fetch and — since M3 Fase 1e — the message text as well, through one aggregate `messages` query per request (`ROW_NUMBER()` over `message_timestamp DESC, id DESC`, `LIKE` with an explicit `ESCAPE`), so no `messages` query runs when `q` is empty. Every conversation element carries `match_snippet`: `null` when there is no `q` or when the hit came through an identity column, otherwise `{ text, is_internal, message_timestamp }` cut by `InboxMatchSnippetService::potong()`.

## 8. Current Operational Inbox Architecture

The current implementation establishes these architectural seams:

### Queue status

Queue status is computed centrally by `ConversationModel::withComputedStatus()`.

The design intentionally reuses the existing response-state computation instead of introducing independent SQL WHERE logic for queue tabs.

### Internal Notes

Internal Notes are persisted as messages with `is_internal = true`.

They:

- do not call the WhatsApp Gateway;
- do not mutate conversation `last_message_at`;
- do not mutate conversation `last_message_direction`;
- remain part of the conversation thread.

### SLA

`InboxSlaService` is a DB/session-independent calculation service.

Its thresholds are configured through `Config\\Inbox` and are not hardcoded inside the service.

### Handoff and Collision Detection

Handoff moves conversation ownership between staff and records every transfer in the `conversation_handoffs` table (Inbox database group).

- The ownership write is an expected-owner conditional write (`SET assigned_to = :to WHERE id = :id AND assigned_to <=> :expected`): a request that loses the race changes nothing and answers `409` with the current owner's name.
- The ownership write and the history insert share one `inbox`-group transaction, so a failed history insert rolls the ownership change back.
- History is read back through the dedicated `GET /inbox/percakapan/(:num)/handoff` route (auth filter only, newest-first, capped at 50); the message thread endpoint is untouched and the `messages` table is never written by Handoff.
- Staff-facing names in the handoff history are resolved in the Inbox UI from the same active-kasir list the Handoff dialog uses, because the read contract carries user ids only.

### Balas Pesan (Reply with Quote)

The Reply-with-Quote feature introduces the following seams:

**Snapshot immutability (REQ-007):** Quote data is copied **once** at send/receive time into the 7 `quoted_*` columns on the new message row. It is never re-derived from the source message at render time. This ensures the quote survives source message edits or soft-deletes.

**Single source of truth for snapshot building:** `InboxQuoteSnapshotService::rakitSnapshot()` is the only place that populates `quoted_*` columns. Both cashier-initiated replies (`Inbox::kirimKeConversation()`, `Inbox::kirimMedia()`) and inbound webhook processing (`InboxGatewayApi::resolveKutipanMasuk()`) call this service.

**Ownership check ordering (SEC-001):** `Inbox::kirimKeConversation()` now performs `cekOwnership()` **before** `resolveKutipan()` (matching `kirimMedia()`), preventing a non-owner from using the text-reply endpoint as an existence oracle for cross-conversation message IDs.

**Media live-fetch with failure memory (PERF-001):** `renderKotakKutipan()` performs live-fetch `GET /inbox/media/:quoted_source_message_id` only for `image`/`sticker`/`document` types. On error (404/410/5xx), the client records the failure in `sessionStorage` under key `'kutipan:' + messageId` and skips subsequent fetches for 4 seconds, preventing polling amplification.

**Replay lookup scoped to conversation (ARCH-001):** `MessageModel::findByOperationIdIncludingDeleted($operationId, $conversationId)` replaces the controller-level query builder, ensuring idempotency replay respects conversation boundaries.

**Snippet normalization (SEC-003, REQ-011):** Both local and Gateway fallback snippets pass through `potongSnippet()` (whitespace normalization, 200-char bound, ellipsis). Non-string payloads are coerced to generic label "Pesan tidak ditemukan" without throwing `TypeError`.

## 9. Authentication and Security Boundaries

There are two distinct authentication paths for the Inbox:

1. **Browser staff**
   - Session-based `auth` filter.
   - Used for UI and staff mutations.

2. **WhatsApp Gateway**
   - Bearer-token-based `gatewaytoken` filter.
   - Used only for machine-to-machine Gateway callbacks.

Secrets such as the Inbox Gateway token are supplied through environment configuration rather than source control.

## 10. Frontend / Presentation

The application primarily uses CodeIgniter server-rendered PHP views under `app/Views/`.

Inbox presentation is concentrated in:

- `app/Views/inbox/index.php`
- shared assets under `public/assets/js/` where applicable.

The Inbox API supplies computed presentation fields such as queue status and SLA state so the frontend does not independently reproduce core response-state rules.

## 11. Testing Architecture

Testing is organized by integration boundary:

```text
tests/
├── database/    -> model/database behavior
├── session/     -> HTTP controller + session behavior
├── unit/        -> isolated services/calculations
└── _support/    -> test schema, fakes, and fixtures
```

The repository's test command is:

```text
composer test
```

### One-time setup: Inbox test database

Inbox tests run against `aulia_inboxdb_test`, never the real `aulia_inboxdb`. Create it once, schema only (no rows):

```text
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS aulia_inboxdb_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
mysqldump -u root -p --no-data --routines --triggers aulia_inboxdb | mysql -u root -p aulia_inboxdb_test
```

`php spark migrate` cannot build this database: migration history is stored in the `default` database, so the inbox migrations are already marked as run and would be skipped.

> [!IMPORTANT]
> Re-run the `mysqldump --no-data ... | mysql ...` step after adding a new inbox migration. Otherwise tests fail with "unknown column/table" errors in `aulia_inboxdb_test`.

The M3 Phase 2a checkpoint recorded in `.claude/instructions/memory.instructions.md` reports 283 tests and 867 assertions on branch `feature/m3-operational-inbox-fase1a-task001` using `vendor/bin/phpunit --no-coverage` (plain `composer test` still exits non-zero because of the pre-existing "No code coverage driver available" warning).

## 12. Architectural Constraints Relevant to M3 Phase 2

The following constraints are important for subsequent Handoff and Collision Detection work:

- Ownership is represented on the conversation and is already used by existing Inbox actions.
- Ownership checking on the remaining paths (`lepas`, `tutup`, `snooze`, `tandai-dibaca`, `hapus`) is still application-level read-then-write logic; only the Handoff path uses an expected-owner conditional write.
- M2 State Consistency is deferred.
- M3 Phase 2a opened the M2 gate **narrowly** (Handoff only, per the M2-gate clarification) and must not silently expand into a general state-consistency redesign.
- The separate Inbox database boundary must be preserved.
- Gateway behavior is outside the Handoff and Collision Detection scope unless an approved specification explicitly requires it.
- New architectural modules, directories, or API contracts introduced by implementation must be reflected in this document.

## 13. Relevant Architectural Files

| Area | Primary files |
| --- | --- |
| Routing | `app/Config/Routes.php` |
| Database topology | `app/Config/Database.php` |
| Inbox configuration | `app/Config/Inbox.php` |
| Inbox controller | `app/Controllers/Inbox.php` |
| Gateway controller | `app/Controllers/InboxGatewayApi.php` |
| Conversation persistence | `app/Models/ConversationModel.php` |
| Handoff persistence | `app/Models/ConversationHandoffModel.php` |
| Message persistence | `app/Models/MessageModel.php` |
| User persistence | `app/Models/UserModel.php` (its `daftarKasirAktif()` is the single source of the active-kasir list for Handoff) |
| Schedule persistence | `app/Models/JadwalModel.php` |
| Inbox SLA | `app/Services/InboxSlaService.php` |
| Inbox match snippet | `app/Services/InboxMatchSnippetService.php` |
| Inbox quote snapshot | `app/Services/InboxQuoteSnapshotService.php` |
| Inbox media | `app/Libraries/InboxMediaStorage.php` |
| Inbox UI | `app/Views/inbox/index.php` |
| Production migrations | `app/Database/Migrations/` |
| Test support | `tests/_support/` |
| Database tests | `tests/database/` |
| Session tests | `tests/session/` |
| Unit tests | `tests/unit/` |

## 14. Architecture Change Policy

This document should be updated whenever implementation introduces:

- a new architectural module or directory;
- a new persistent integration boundary;
- a new API contract;
- a new database boundary;
- a significant ownership/state-management seam.

Routine changes inside an already documented module do not require restructuring this document unless they materially change the architecture.
