# AuliaPos Architecture

## 1. Scope

This document describes the current architecture of the AuliaPos application as observed on branch `feature/m3-operational-inbox-fase1a-task001`.

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
- `inbox` → the dedicated database `aulia_inboxdb_test` (same server credentials from `.env`, only the database name is forced).

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

Gateway routes:

- `POST /api/inbox/gateway/messages`
- `POST /api/inbox/gateway/status`

These routes use the `gatewaytoken` filter rather than browser session authentication.

Browser Inbox routes use the `auth` filter.

## 7. Inbox HTTP Surface

Current Inbox routes include:

| Route | Responsibility |
| --- | --- |
| `GET /inbox` | Inbox page |
| `GET /inbox/api/conversations` | Conversation queue data |
| `GET /inbox/api/conversations/(:num)/messages` | Conversation thread |
| `GET /inbox/api/gateway-status` | Gateway status |
| `GET /inbox/media/(:num)` | Authenticated media access |
| `POST /inbox/kirim` | Send text reply |
| `POST /inbox/kirim-media` | Send media |
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
