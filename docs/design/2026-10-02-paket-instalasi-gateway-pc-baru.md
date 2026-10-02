# Design: Gateway Installation Package for a New PC

- **Date**: 2026-10-02
- **Status**: approved & terimplementasi (6 cek lokal lulus 2026-10-02; E2E PC baru/VM belum)
- **Requirements**: `docs/requirements/2026-10-02-paket-instalasi-gateway-pc-baru.md`
- **SDLC tier**: A
- **Target repo (implementation)**: `C:\Projects\evolution-gateway` (remote `https://github.com/tikusgot007/WA-Gateway.git`, branch `master`). Docs stay in `aulia-app`.

## 1. Summary

Ship a single online-bootstrap installer in the adapter repo (`installer/install.ps1`) that: downloads the pinned Evolution API tag and adapter ref, provisions Node LTS + PostgreSQL 16 binaries, generates paired `.env` files, initializes the Evolution database, applies the view-once patch idempotently, configures a restricted firewall rule, and registers the webhook. Manual `start`/`stop`/`status` scripts replace the scheduled-task stack, and a Bahasa Indonesia runbook (`petunjuk-penggunaan.md`) ships with the package. The proven aulia3 scripts are reused via parameters instead of being rewritten.

## 2. Current flow (verified)

Existing provisioning scripts and the order they encode (aulia3):

    setup-env.ps1            -> writes adapter .env + evolution .env (paired API key)
    install-postgres.ps1     -> initdb cluster, register service, create role/db
    start-stack.ps1          -> prisma migrate deploy, start Evolution (8080), adapter (3000)
    setup-instance.js        -> create instance, set webhook, restart instance
    enable-webhook-secret.ps1-> write secret, re-set webhook, restart adapter
    allow-lan-ports.ps1      -> firewall inbound 3000/8080, restricted sources

Verified facts:

- `setup-env.ps1:25,115` reads `CI4_GATEWAY_TOKEN` from `D:\WA-Gateway\.env` (old aulia3 gateway); `setup-env.ps1:105,110` requires `C:\Projects\evolution-api-server\.env.template`, which does **not** exist (`Test-Path = False`).
- `install-postgres.ps1` is already parameterized (`:27-34`: `-PgRoot`, `-ServiceName`, `-EvolutionEnv`, `-LogPath`) but defaults to `D:\pgsql16` / `postgresql-aulia3`; it expects PostgreSQL **binaries** under `PgRoot\bin` and does its own `initdb` (`:108-113`).
- `setup-instance.js:94` hardcodes the message `http://AULIA3:8080/manager (instance aulia-toko)`; otherwise it is config-driven and idempotent (create -> webhook -> restart, `:58-97`).
- `allow-lan-ports.ps1` is already parameterized (`:27` `-Sources`, `:30` `-LogPath`); it refuse-to-run on empty sources with a throw (`:35`).
- Task-oriented files are hardcoded and stay untouched: `run-adapter.cmd:4`, `run-evolution.cmd:12,19-20`, `start-stack.ps1:18-21`, `register-services.ps1:62-64`, `watchdog-stack.ps1:25-37`, `restart-adapter.ps1:13,18,84`.
- `restart-adapter.ps1:43-48` contains the safety pattern to reuse for manual stop: verify the process owning the port is node whose command line mentions `evolution` before killing.

Delivery facts verified this session:

- Both source ZIPs are publicly downloadable, HTTP 200: `https://codeload.github.com/tikusgot007/WA-Gateway/zip/refs/heads/master` and `https://codeload.github.com/evolution-foundation/evolution-api/zip/refs/tags/2.3.7`.
- Evolution source: tag `2.3.7` (detached `cd800f2`), has `package-lock.json` and `prisma/postgresql-migrations` (57 dirs); root `env.example` exists; patch target `src/api/integrations/channel/whatsapp/whatsapp.baileys.service.ts` exists.
- Adapter has `.env.example` with the keys `setup-env.ps1` expects; remote `master` HEAD `c4075c5`.
- `winget` is available on this PC; dev Node is `v22.23.2`.

Not verified: exact PostgreSQL 16 binaries URL, `npm ci` behavior on a clean machine (husky `prepare`, Prisma generate, `better-sqlite3` prebuilt).

## 3. Options

- **A. ZIP snapshot download (no Git dependency)** — installer downloads pinned `codeload` ZIPs for Evolution tag and adapter ref. Fewest prerequisites, deterministic, no Git install. Cannot `git pull` to upgrade (re-run installer with a new ref).
- **B. Require Git + clone** — gets history and easy upgrades, but adds Git as a prerequisite and credentials for a possibly-private repo.
- **C. Offline bundle (ZIP with binaries + node_modules)** — rejected by the user (bootstrap online chosen 2026-10-02).

For reusing the aulia3 scripts there are two sub-options: **parameterize the existing scripts** (keep aulia3 defaults) versus **fork copies into `installer/`**. Reusing is preferred; forking duplicates hard-won logic (native-stderr handling in `install-postgres.ps1:50-67`, PG-not-ready wait, webhook-after-restart ordering) and drifts.

**Recommendation:** A + parameterize existing scripts. ZIP download removes the Git prerequisite and is fully scriptable; parameterizing proven scripts avoids duplicating correctness-critical logic while preserving aulia3 behavior through unchanged defaults.

## 4. Planned changes

All new implementation files live in the adapter repo under `installer/`.

| File (adapter repo) | Change | Reason |
|---|---|---|
| `installer/install.ps1` | New orchestrator: validate params, preflight ports, download pinned sources, bootstrap prereqs, call existing scripts with explicit params, apply patch, start, firewall, instance/webhook, verify, emit summary | AC-1..AC-14 |
| `installer/lib/common.ps1` | New shared helpers: `L` logging, `Invoke-Native`, `Get-EnvValue`, `Test-Listen`/`Wait-Listen`, parameter validation | avoid duplicating in every script |
| `installer/start.ps1` | New: ensure PG service running, start Evolution then adapter as background processes, wait for ports | AC-7 (manual start) |
| `installer/stop.ps1` | New: stop adapter then Evolution, verifying port owner is the expected node process (reuse `restart-adapter.ps1:43-48` pattern) | AC-7 |
| `installer/status.ps1` | New: ports, owning processes, tail of logs, Evolution connection state | AC-11 |
| `installer/apply-viewonce-patch.ps1` | New: insert patch block before the anchor in `whatsapp.baileys.service.ts`, idempotent via marker check, backup `.orig` | AC-4, TODO-F3 |
| `installer/templates/evolution.env.template` | New minimal Evolution `.env` base (subset of upstream `env.example`) | AC-5; upstream `env.example` shape can drift |
| `installer/petunjuk-penggunaan.md` | New Bahasa Indonesia runbook: prerequisites, params, steps, start/stop/status, QR pairing, smoke test, POS-side config, troubleshooting | AC-14/AC-15 |
| `scripts/setup-env.ps1` | Add `-Ci4GatewayToken` and `-EvolutionEnvTemplate` params; fall back to `-OldGatewayEnv` only when the param is absent | AC-5; fix missing `.env.template` |
| `scripts/setup-instance.js` | Use resolved host/instance in the "not linked" message (remove `AULIA3`/`aulia-toko` literals) | AC-13 |
| `scripts/install-postgres.ps1` | No logic change; invoked with `-PgRoot`, `-ServiceName`, `-EvolutionEnv`, `-LogPath` | reuse (already parameterized) |
| `scripts/allow-lan-ports.ps1` | No change; invoked with `-Sources`, `-LogPath` | reuse (already parameterized) |

Patch applied (from `C:\Projects\evolution-gateway\docs\evolution-viewonce-patch.md:39-46`): insert the `if (!received?.message && received?.key?.isViewOnce) { received.message = { conversation: '' } }` block immediately before `if ((type !== 'notify' && type !== 'append') ...` in `whatsapp.baileys.service.ts`, guarded by a search for the `PATCH-ADAPTER (2026-10-01)` marker.

Install orchestration order:

    install.ps1
      1. require admin, validate params (non-empty Ci4BaseUrl/Ci4GatewayToken/LanSources)
      2. preflight: ports 5432/8080/3000 free (or already owned by our stack), InstallRoot writable
      3. prereqs: Node LTS (winget OpenJS.NodeJS.LTS, or direct MSI fallback); PostgreSQL 16 binaries -> <root>\pgsql16
      4. download + expand Evolution (tag 2.3.7) and adapter (pinned ref) into <root>\...
      5. npm ci both repos (HUSKY=0); prisma generate
      6. apply-viewonce-patch.ps1
      7. setup-env.ps1 -Ci4GatewayToken ... -EvolutionEnvTemplate installer\templates\...
      8. install-postgres.ps1 -PgRoot <root>\pgsql16 -ServiceName postgresql-auliagw ...
      9. prisma migrate deploy
     10. start.ps1
     11. allow-lan-ports.ps1 -Sources <LanSources>
     12. setup-instance.js  (create + webhook + restart)
     13. status.ps1 + test-send (smoke)
     14. write <root>\install-summary.txt with resolved values (AC-15)

Parameters (defaults chosen to be neutral, no aulia3 literals):

| Param | Default | Purpose |
|---|---|---|
| `-InstallRoot` | `C:\AuliaGateway` | root for pgsql16, sources, logs, data |
| `-Ci4BaseUrl` | (required) | AuliaPos base URL reachable from the gateway |
| `-Ci4GatewayToken` | (required) | must equal `inbox.gatewayToken` on the POS server |
| `-LanSources` | (required) | comma-separated IPs allowed to reach ports 3000/8080 |
| `-InstanceName` | `aulia-toko` | Evolution instance name |
| `-PgPort` / `-EvolutionPort` / `-AdapterPort` | `5432` / `8080` / `3000` | ports |
| `-EvolutionRef` / `-AdapterRef` | `2.3.7` / pinned SHA | source pinning |
| `-SkipPrereqs` | off | skip Node/PG bootstrap (testing on a machine that already has them) |

## 5. Impact

- **Database / migrations**: creates a **new** PostgreSQL database `evolution_gateway_pg` (gateway-only); no change to AuliaPos MySQL (`aulia_kasirdb`, `aulia_inboxdb`) or its schema.
- **Routes / API / response formats**: none. Adapter endpoints (`/send`, `/send-media`, `/media/download`, `/evolution/webhook`) and CI4 contract are unchanged.
- **Gateway contract (cross-repo)**: unchanged payloads/endpoints. The only POS-side action is configuration on the POS server: set `inbox.gatewayBaseUrl` to the new gateway and `inbox.gatewayToken` to the same value. That machine is outside this package; documented in the runbook (AC-14). Other side **verified** in this session only at the config-key level, not end-to-end.
- **Existing data**: none touched; fresh install, new instance, QR re-pair.
- **Security / validation at trust boundaries**: secrets (Evolution API key, PG app password, webhook secret, PG superuser password) generated with CSPRNG locally and never printed; `.env` remains gitignored; webhook secret is mandatory; firewall restricted to `-LanSources` and refuses empty; admin elevation required; QR/pairing never automated (operator-only).
- **Transactions / concurrency / rollback**: install steps are idempotent and re-runnable; a failed step stops with a non-zero exit and a log; no destructive operation on pre-existing data. No scheduled task/watchdog is registered (manual choice).
- **PostgreSQL service**: registered as a normal Windows service with `auto` start (needed by Evolution and standard PostgreSQL behavior). "Manual start" applies to the two Node apps (Evolution, adapter), not the DB engine — flagged as a decision in §7.
- **Ports**: Evolution binds 127.0.0.1:8080 (adapter reaches it locally); adapter binds `0.0.0.0:3000` so the POS server on the LAN can call it.

## 6. Test plan

Infra artifact: some criteria are only provable end-to-end on a clean Windows machine/VM. Executable checks are assert-based PowerShell under `installer/tests/` (no new framework), plus one static scan.

| Acceptance criterion | Test / verification | Type |
|---|---|---|
| AC-1 install completes with valid params | E2E on clean PC/VM: `install.ps1` -> exit 0 | manual E2E |
| AC-2 idempotent prereqs | `install.ps1` run twice on scratch root; second run skips downloads/installs | E2E |
| AC-3 pinned sources + `npm ci` | assert resolved Evolution `version` = `2.3.7`; `npm ci` exit 0 both repos | E2E + check |
| AC-4 view-once patch idempotent | `installer/tests/check-viewonce-patch.ps1`: apply twice to a fixture copy, assert exactly one marker block | unit |
| AC-5 env parity / no secret leak | `installer/tests/check-env-parity.ps1`: assert `EVOLUTION_API_KEY`==`AUTHENTICATION_API_KEY`, token==param; assert secret values absent from install log | unit |
| AC-6 PG service/role/db/login | E2E: `Get-Service` Running, `pg_isready`, login as app role with `.env` password | E2E |
| AC-7 start/stop idempotent | E2E: ports listen after `start.ps1`; gone after `stop.ps1`; second `stop` exits 0 | E2E |
| AC-8 instance created, QR manual | E2E: `connectionState` responds; runbook states; assert no QR text in logs | E2E + check |
| AC-9 webhook secret enforced | E2E: webhook registered; POST without secret rejected by adapter | E2E |
| AC-10 firewall restricted / empty refused | `installer/tests/check-firewall.ps1`: empty `-LanSources` throws; rule remote address equals sources | unit (admin) |
| AC-11 status + smoke test | E2E: `status.ps1` shows all ports; `test-send` returns success | E2E |
| AC-12 no scheduled tasks / no aulia-app edits | assert `Get-ScheduledTask` names absent; `git -C aulia-app status` clean | check |
| AC-13 no residual aulia3 literals | static scan: `rg "aulia3|AULIA3|AULIA-SERVER2|D:\\|192\.168\.1\.10"` over `installer/` and changed scripts | check |
| AC-14 runbook completeness | `installer/tests/check-runbook.ps1`: assert required sections present | unit |
| AC-15 runbook usable with resolved paths | assert every command block uses `<InstallRoot>` variable or a neutral placeholder; `install-summary.txt` written | check |

Honesty note: AC-1, AC-2, AC-6, AC-7, AC-9, AC-11 require a clean target (VM or the new PC). They cannot be run on this dev machine without risking the existing working setup, so they will be delivered as a documented E2E runbook and executed by the user/operator. Unit-level checks (AC-4, AC-5, AC-10, AC-12, AC-13, AC-14, AC-15) will be run in-session.

## 7. Risks and mitigations

- Evolution `npm ci` fails on `prepare: husky` outside a Git checkout -> set `HUSKY=0` for the install; if still failing, `npm ci --ignore-scripts` then run explicitly the needed postinstalls (`prisma generate`, `sharp`). Verify at implementation.
- `better-sqlite3` (adapter) native addon has no prebuilt for the target Node -> pin Node LTS that has a prebuilt; fallback documented: install VS Build Tools. Verify.
- Prisma client not generated -> explicit `npx prisma generate --schema prisma/postgresql-schema.prisma` before `migrate deploy`.
- PostgreSQL 16 binaries URL/version drift -> pin an explicit version, verify the URL at implementation; fallback `winget install PostgreSQL.PostgreSQL.16`.
- Windows service name collision (`postgresql-aulia3` default) -> installer passes a unique `-ServiceName` (`postgresql-auliagw`).
- Port 3000/8080/5432 already in use on target -> preflight fails early with a clear message.
- Auto-start choice: PG service stays `auto` while the two Node apps are manual. If the operator wants full manual, set the service to `demand` — documented, not default.
- Repo becomes private -> installer accepts `-SourceToken` / `-ZipUrl` overrides.
- Antivirus/ExecutionPolicy blocks scripts or node -> document `-ExecutionPolicy Bypass` and exclusions in the runbook.
- `WHATSAPP-BAILEYS` remains unofficial (ban risk) -> restated in the runbook; out of scope.

## 8. Not yet verified

- Exact PostgreSQL 16 binaries download URL and version, and that `Expand-Archive` yields a usable `PgRoot\bin`.
- Clean-machine behavior of Evolution `npm ci` (husky `prepare`, Prisma generate) and adapter `better-sqlite3` prebuilt availability.
- That the `setup-env.ps1` / `setup-instance.js` edits preserve aulia3 behavior (must not break production).
- Whether the adapter `master` HEAD is the intended release, or a commit SHA must be pinned.
- Whether winget is usable on the target PC (fallback: direct MSI / binaries download).

## 9. Approval (Gate 2)

- [x] Approved by: user, date: 2026-10-02
