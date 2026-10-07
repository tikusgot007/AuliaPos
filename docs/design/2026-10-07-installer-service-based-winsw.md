# Design: Installer Evolution Gateway Berbasis Windows Service (WinSW)

- **Date**: 2026-10-07
- **Status**: approved
- **Requirements**: `docs/requirements/2026-10-07-installer-service-based-winsw.md`
- **SDLC tier**: A
- **Target repo (implementation)**: `C:\Projects\evolution-gateway` (remote `https://github.com/tikusgot007/WA-Gateway.git`, branch `evolution`, local checkout currently on branch `evolution`). Docs stay in `aulia-app` (this repo).
- **Intended implementer**: deepseek (aan-deepseek) agent working directly in `C:\Projects\evolution-gateway`. This document is written to be directly actionable — every file to create/edit is listed with full or near-full content.

## 1. Summary

Replace the manual `Start-Process`/`Stop-Process` lifecycle in `installer/start.ps1` and `installer/stop.ps1` with **two new Windows Services** (Evolution API, adapter) wrapped by **WinSW v2.12.0** (pinned, downloaded from GitHub Releases, MIT-licensed, self-contained — no .NET prerequisite beyond what's already on Windows 10/11). PostgreSQL keeps its existing native Windows service (`pg_ctl register`, unchanged). `install.ps1` downloads WinSW once, generates one XML config per Node process from new templates in `installer/services/`, and registers both services with `StartType=Automatic` and `<onfailure action="restart">` escalation. `start.ps1`/`stop.ps1`/`status.ps1` switch from process-hunting-by-port to `Start-Service`/`Stop-Service`/`Get-Service`. `check-static.ps1` flips from *forbidding* a start mechanism to *requiring* the service mechanism (and still forbids scheduled tasks and now also forbids NSSM). A new `installer/tests/check-service.ps1` verifies, on an installed machine, that all three services are registered and `Running`. `petunjuk-penggunaan.md` §3 is rewritten to describe the service model.

## 2. Current flow (verified)

```
install.ps1 (C:\Projects\evolution-gateway\installer\install.ps1)
  :269-274  Invoke-ChildScript start.ps1 -InstallRoot ... -PgService ... -NodeExe $tools.Node.Source ...

start.ps1
  :30-51   Start-StackApp: Start-Process -FilePath node -ArgumentList <entry> -WorkingDirectory $Dir
                            -RedirectStandardOutput/-Error -WindowStyle Hidden
  :66-74   Postgres: Start-Service -Name $PgService (ALREADY service-based, native pg_ctl register)
  :77-78   Start-StackApp evolution (port 8080, grace 420s), then adapter (port 3000, grace 90s)

stop.ps1
  :31-61   Stop-OwnedProcess: Get-NetTCPConnection -LocalPort $Port -> Get-Process -> verify
            ProcessName -like 'node*' AND CommandLine -match $Match -> Stop-Process -Force
  :64-65   adapter stopped first, then evolution
  :67-73   Postgres stopped only if -StopPostgres (Stop-Service)

status.ps1
  :30-33   Postgres: Get-Service -Name $PgService -> print Status/StartType
  :35-41   port listen + owning process name for pg/evolution/adapter (unchanged by this design)

installer/tests/check-static.ps1
  :1,10-21  FORBIDS Register-ScheduledTask/New-ScheduledTaskAction/New-ScheduledTaskTrigger/schtasks
             anywhere under installer/ (excluding tests/)
  :33-36    FORBIDS scheduled tasks named AuliaEvolution/AuliaAdapter/AuliaStackWatchdog being
             registered on the machine running the check

petunjuk-penggunaan.md
  §3 (lines 88-107)  documents start.ps1/stop.ps1/status.ps1 as MANUAL, no auto-start
```

Verified facts that shape this design:

- `src/app/evolution.js:80-99` (adapter): registers `SIGINT`/`SIGTERM` with graceful shutdown (`server.close`, worker `.stop()`, 5s force-exit fallback). A WinSW `stop` (Ctrl+C event, which Node on Windows surfaces as `'SIGINT'`) is handled cleanly — **no change needed in adapter source**.
- `C:\Projects\evolution-api-server\src\main.ts` + `src/config/error.config.ts`: **no** `SIGINT`/`SIGTERM` handler is registered. Default Node-on-Windows behavior with no listener is to terminate the process on Ctrl+C — equivalent to today's `Stop-Process -Force` in `stop.ps1`. **No regression, no change needed in Evolution source** (out of scope per the task: only the three named installer deliverables plus `services/`, `start.ps1`, `stop.ps1`, `status.ps1`, `check-static.ps1`, `check-service.ps1`, `petunjuk-penggunaan.md` §3).
- `C:\Projects\evolution-api-server\src\api\repository\repository.service.ts:19-22`: `onModuleInit()` does `await this.$connect()` with **no retry**. If PostgreSQL is not yet accepting connections, this throws; `bootstrap()` (`main.ts`) is called with no `.catch`, so the rejection becomes an **unhandled rejection**, which `onUnexpectedError()` (`error.config.ts:13-20`) only **logs** — it does not `process.exit`. This is a pre-existing fragility in Evolution API, **not introduced by this change**, and is mitigated at the service-ordering level (see §3/§4 `<depend>`) rather than by patching vendor source.
- PostgreSQL's own Windows service (registered via `pg_ctl register` in `scripts/install-postgres.ps1:138`) uses PostgreSQL's native Win32 service control, which reports `SERVICE_RUNNING` to SCM only once the postmaster is ready to accept connections (documented PostgreSQL behavior for `pg_ctl register`/`net start`). This means a WinSW `<depend>` on the PostgreSQL service name is a **correct and sufficient** ordering guarantee — SCM will not even launch the Evolution service process until PostgreSQL is actually ready, closing the race that `repository.service.ts` cannot handle itself.
- WinSW requires its renamed executable and the XML config to share the same base name and live side by side (`doc/installation.md`: *"Take WinSW.exe ... rename it ... Write myapp.xml ... Place those two files side by side"*). Confirmed on `v2.12.0` release assets: `WinSW-x64.exe` (self-contained .NET Core 3.1 build, no framework prerequisite), `sample-minimal.xml`, `sample-allOptions.xml` (both fetched and inspected for this design).
- `<onfailure action="restart" delay="N sec"/>` is WinSW's built-in auto-restart-on-crash mechanism (`doc/xmlConfigFile.md`, confirmed by fetch). `<depend>ServiceName</depend>` controls SCM start/stop ordering (same doc, confirmed).
- `package.json:12` (adapter) `"start": "node src/app/evolution.js"` and `C:\Projects\evolution-api-server\package.json` `"start": "tsx ./src/main.ts"` confirm the exact entry points already used by `start.ps1:77-78`; the WinSW XML reuses the identical `node.exe` + relative-path + `-workingdirectory` pattern already proven there, nothing new is invented.
- `installer/tests/check-syntax.ps1:8-9` auto-discovers every `.ps1` under `installer/` (excluding `tests/`) — no manual registration list to update as long as new `.ps1` files are placed under `installer/`. The new `installer/services/*.xml.template` files are **not** `.ps1` and are not picked up (no action needed there).
- `installer/tests/run-checks.ps1:4-12` only lists checks that are safe to run on *any* dev machine (fixtures, static scans). `check-service.ps1` (new) requires a machine with the services actually installed, so it must **not** be added to this list — same existing pattern as `test-send.js`, which also requires a live stack and is invoked manually from the runbook, not from `run-checks.ps1`.

## 3. Options

- **A. WinSW, one Service Wrapper copy per Node process, `<depend>` for ordering, no source patches.** Minimal diff, reuses the exact entry-point/workdir pattern already in `start.ps1`, relies on PostgreSQL's own service-readiness signaling (verified above) instead of inventing a wait-loop. Risk: if a future PostgreSQL alternative without that readiness guarantee is ever substituted, the depend-only ordering would need a wait loop added back.
- **B. WinSW with a small `.cmd`/`.ps1` launcher as `<executable>` that polls `pg_isready` before `exec`-ing node** (mirrors the old aulia3 `run-evolution.cmd` pattern). More defensive, but WinSW would then supervise the **launcher process**, not the Node process directly — Ctrl+C/stop semantics become unreliable unless the launcher forwards signals correctly, which is exactly the fragility the old scheduled-task stack already struggled with (`watchdog-stack.ps1:11-14` explicit warning about `schtasks /end` and Ctrl+C). Rejected for the two services the install.ps1 controls; the ordering guarantee in Option A already covers the real risk.
- **C. NSSM instead of WinSW.** Explicitly rejected by the user for this task.

**Recommendation: A.** It is the smallest correct diff, does not touch adapter or Evolution source, and the PostgreSQL service-readiness fact (verified in §2) removes the need for a custom wait loop.

## 4. Planned changes

All changes are inside `C:\Projects\evolution-gateway`. Nothing in `src/` (adapter), the Evolution patches (`apply-viewonce-patch.ps1`, `apply-lid-preservation-patch.ps1`), or the CI4 HTTP contract changes.

| File | Change | Reason |
|---|---|---|
| `installer/services/evolution-service.xml.template` | **New.** WinSW XML template for Evolution API, placeholders filled by `install.ps1`. | AC-1 |
| `installer/services/adapter-service.xml.template` | **New.** WinSW XML template for the adapter. | AC-1 |
| `installer/install.ps1` | Add `-WinswVersion`, `-EvolutionServiceName`, `-AdapterServiceName` params; add `Ensure-WinSW`, `Install-WinSwService` functions; call them before `start.ps1`; update the `start.ps1`/`stop.ps1`/`status.ps1` `Invoke-ChildScript` calls to pass the new service-name params instead of `-NodeExe`; update the summary text block. | AC-1, AC-7 |
| `installer/start.ps1` | Replace `Start-StackApp` (Start-Process) with `Start-ServiceAndWait` (`Start-Service` + `Wait-Listen`); drop `-NodeExe`; add `-EvolutionServiceName`/`-AdapterServiceName` params. | AC-2, AC-5 |
| `installer/stop.ps1` | Replace `Stop-OwnedProcess` (port-hunting) with `Stop-ServiceSafe` (`Stop-Service`); add `-EvolutionServiceName`/`-AdapterServiceName` params; keep `-StopPostgres` behavior and the final port-check safety net. | AC-4 |
| `installer/status.ps1` | Add Evolution/adapter service status lines (`Get-Service`) next to the existing PostgreSQL service line; add `-EvolutionServiceName`/`-AdapterServiceName` params. | AC-6 |
| `installer/tests/check-static.ps1` | Keep existing scheduled-task prohibition (defense in depth) and literal-host prohibition; **add**: require `installer/services/*.xml.template` to exist, require `install.ps1` to reference `Ensure-WinSW`/`Install-WinSwService`, require `start.ps1` to use `Start-Service` and **not** `Start-Process`, require `stop.ps1` to use `Stop-Service`; **add** new forbidden literal `nssm`/`NSSM`. | AC-8, AC-12 |
| `installer/tests/check-service.ps1` | **New.** Runs on an installed machine; asserts all three services exist, are `Running`, and (for the two WinSW-controlled ones) `StartType = Automatic`. | AC-9 |
| `installer/petunjuk-penggunaan.md` | Rewrite §3 to describe the service model: auto-start on boot, auto-restart on crash (≤30s), service names, `Get-Service`/`check-service.ps1` for inspection; keep all tokens required by `check-runbook.ps1` (`install.ps1`, `start.ps1`, `stop.ps1`, `status.ps1`, section headings unchanged). | AC-10 |

### 4.1 `installer/services/evolution-service.xml.template`

```xml
<service>
  <id>__SERVICE_ID__</id>
  <name>__SERVICE_DISPLAY_NAME__</name>
  <description>Evolution API (WhatsApp session server) for the Aulia gateway stack. Installed by evolution-gateway installer; do not edit manually, re-run install.ps1 instead.</description>
  <executable>__NODE_EXE__</executable>
  <arguments>node_modules\tsx\dist\cli.mjs src\main.ts</arguments>
  <workingdirectory>__WORKDIR__</workingdirectory>
  <depend>__PG_SERVICE_NAME__</depend>
  <startmode>Automatic</startmode>
  <onfailure action="restart" delay="5 sec"/>
  <onfailure action="restart" delay="15 sec"/>
  <onfailure action="restart" delay="30 sec"/>
  <resetfailure>1 hour</resetfailure>
  <stoptimeout>20 sec</stoptimeout>
  <logpath>__LOG_DIR__</logpath>
  <log mode="roll-by-size">
    <sizeThreshold>10240</sizeThreshold>
    <keepFiles>8</keepFiles>
  </log>
</service>
```

### 4.2 `installer/services/adapter-service.xml.template`

```xml
<service>
  <id>__SERVICE_ID__</id>
  <name>__SERVICE_DISPLAY_NAME__</name>
  <description>Adapter evolution-gateway (AuliaPos CI4 &lt;-&gt; Evolution API bridge). Installed by evolution-gateway installer; do not edit manually, re-run install.ps1 instead.</description>
  <executable>__NODE_EXE__</executable>
  <arguments>src\app\evolution.js</arguments>
  <workingdirectory>__WORKDIR__</workingdirectory>
  <depend>__EVOLUTION_SERVICE_ID__</depend>
  <startmode>Automatic</startmode>
  <onfailure action="restart" delay="5 sec"/>
  <onfailure action="restart" delay="15 sec"/>
  <onfailure action="restart" delay="30 sec"/>
  <resetfailure>1 hour</resetfailure>
  <stoptimeout>20 sec</stoptimeout>
  <logpath>__LOG_DIR__</logpath>
  <log mode="roll-by-size">
    <sizeThreshold>10240</sizeThreshold>
    <keepFiles>8</keepFiles>
  </log>
</service>
```

Notes:

- `<depend>` value for the adapter is the **Evolution service `<id>`** (a Windows service name), not a port — this only affects SCM start/stop **order**, not HTTP readiness; the adapter's existing heartbeat/retry logic (`src/evolution/heartbeat.js:30-59`) already tolerates Evolution not yet being ready to serve.
- `__NODE_EXE__` is the absolute path to `node.exe` resolved by `Resolve-NodeTools` (same helper `install.ps1`/`start.ps1` already use) — do **not** rely on `PATH` inside the XML, because services may run in a session whose `PATH` differs from the interactive shell used to install.
- Entry arguments (`node_modules\tsx\dist\cli.mjs src\main.ts` and `src\app\evolution.js`) are **copied verbatim** from the existing `start.ps1:77-78` `-Entry` values — do not re-derive them.
- `<log mode="roll-by-size">` prevents unbounded `*.out.log`/`*.err.log` growth (WinSW rolls at 10MB, keeps 8 files) — this is a new, independent log stream from the adapter's own `logging/index.js` and from the existing `logs\adapter.out.log`/`evolution.out.log` written under the old Start-Process model; no collision because the WinSW log file base name is the service `<id>`, which differs from `evolution`/`adapter`.

### 4.3 `installer/install.ps1` — new parameters

Add to the `param()` block (near the existing `-ServiceName` for PostgreSQL, around line 37):

```powershell
  [string]$WinswVersion = '2.12.0',
  [string]$EvolutionServiceName = 'AuliaGatewayEvolution',
  [string]$AdapterServiceName   = 'AuliaGatewayAdapter',
```

### 4.4 `installer/install.ps1` — new functions

Add after `Ensure-Postgres` (currently ends at line 113), before `Ensure-Source`:

```powershell
function Ensure-WinSW {
  param([string]$Version)
  $exe = Join-Path $dlDir ('WinSW-' + $Version + '.exe')
  if (Test-Path -LiteralPath $exe) { Write-Log ('winsw: sudah ada ' + $exe); return $exe }
  $url = 'https://github.com/winsw/winsw/releases/download/v' + $Version + '/WinSW-x64.exe'
  Get-RemoteFile -Url $url -OutFile $exe -Label ('WinSW ' + $Version)
  return $exe
}

# Render satu service WinSW dari template: tulis XML, salin biner WinSW (hanya
# sekali -- biner yang sedang Running tidak bisa ditimpa), lalu `install`
# lewat SCM kalau belum terdaftar. Idempotent: dipanggil ulang tiap kali
# install.ps1 jalan, XML selalu disegarkan supaya path/port yang berubah ikut
# terbawa; biner & registrasi SCM hanya disentuh sekali.
function Install-WinSwService {
  param(
    [Parameter(Mandatory = $true)][string]$WinswExe,
    [Parameter(Mandatory = $true)][string]$ServiceId,
    [Parameter(Mandatory = $true)][string]$DisplayName,
    [Parameter(Mandatory = $true)][string]$TemplatePath,
    [Parameter(Mandatory = $true)][string]$DestDir,
    [Parameter(Mandatory = $true)][hashtable]$Tokens,
    [Parameter(Mandatory = $true)][string]$Label
  )
  if (-not (Test-Path -LiteralPath $DestDir)) { New-Item -ItemType Directory -Path $DestDir -Force | Out-Null }
  $svcExe = Join-Path $DestDir ($ServiceId + '.exe')
  $svcXml = Join-Path $DestDir ($ServiceId + '.xml')

  if (-not (Test-Path -LiteralPath $svcExe)) {
    Copy-Item -LiteralPath $WinswExe -Destination $svcExe -Force
    Write-Log ($Label + ': biner WinSW disalin ke ' + $svcExe)
  } else {
    Write-Log ($Label + ': biner WinSW sudah ada, dilewati (' + $svcExe + ').')
  }

  $xml = Get-Content -LiteralPath $TemplatePath -Raw
  foreach ($key in $Tokens.Keys) { $xml = $xml.Replace(('__' + $key + '__'), [string]$Tokens[$key]) }
  [IO.File]::WriteAllText($svcXml, $xml, (New-Object System.Text.UTF8Encoding($false)))
  Write-Log ($Label + ': konfigurasi ditulis -> ' + $svcXml)

  $existing = Get-Service -Name $ServiceId -ErrorAction SilentlyContinue
  if ($existing) {
    Write-Log ($Label + ': service ' + $ServiceId + ' sudah terdaftar (dilewati install, config disegarkan).')
  } else {
    $r = Invoke-Native -Exe $svcExe -NativeArgs @('install') -Label ($Label + ' install')
    if ($r.Code -ne 0) { throw ($Label + ': gagal mendaftarkan service (exit ' + $r.Code + ').') }
    Write-Log ($Label + ': service ' + $ServiceId + ' terdaftar.')
  }
  Set-Service -Name $ServiceId -StartupType Automatic
}
```

Placement rationale: both functions only call helpers already in `lib\common.ps1` (`Get-RemoteFile`, `Invoke-Native`, `Write-Log`) that `install.ps1` already dot-sources — no new shared-lib file needed, keeping the diff in one file as the task asked (`installer/services/` is data, not code).

### 4.5 `installer/install.ps1` — wire into the main flow

Insert **before** the existing `start.ps1` invocation (currently line 269), after the Prisma `migrate deploy` step:

```powershell
  $winswExe = Ensure-WinSW -Version $WinswVersion
  $servicesDir = Join-Path $root 'services'

  Install-WinSwService -WinswExe $winswExe -ServiceId $EvolutionServiceName `
    -DisplayName ('Evolution API (Aulia Gateway)') `
    -TemplatePath (Join-Path $here 'services\evolution-service.xml.template') `
    -DestDir $servicesDir -Label 'service-evolution' `
    -Tokens @{
      SERVICE_ID            = $EvolutionServiceName
      SERVICE_DISPLAY_NAME  = 'Evolution API (Aulia Gateway)'
      NODE_EXE              = $tools.Node.Source
      WORKDIR               = $evoDir
      PG_SERVICE_NAME       = $ServiceName
      LOG_DIR               = $logDir
    }

  Install-WinSwService -WinswExe $winswExe -ServiceId $AdapterServiceName `
    -DisplayName ('Adapter evolution-gateway (Aulia Gateway)') `
    -TemplatePath (Join-Path $here 'services\adapter-service.xml.template') `
    -DestDir $servicesDir -Label 'service-adapter' `
    -Tokens @{
      SERVICE_ID            = $AdapterServiceName
      SERVICE_DISPLAY_NAME  = 'Adapter evolution-gateway (Aulia Gateway)'
      NODE_EXE              = $tools.Node.Source
      WORKDIR               = $adapterDir
      EVOLUTION_SERVICE_ID  = $EvolutionServiceName
      LOG_DIR               = $logDir
    }
```

Then **update** the existing `start.ps1`/`status.ps1` `Invoke-ChildScript` calls (lines 269-274 and 292-296) to drop `-NodeExe` and add the two service-name params:

```powershell
  Invoke-ChildScript -Script (Join-Path $here 'start.ps1') -ScriptArgs @(
    '-InstallRoot', $root, '-PgService', $ServiceName,
    '-EvolutionServiceName', $EvolutionServiceName, '-AdapterServiceName', $AdapterServiceName,
    '-PgPort', "$PgPort", '-EvolutionPort', "$EvolutionPort", '-AdapterPort', "$AdapterPort",
    '-LogPath', (Join-Path $logDir 'start.log')
  ) -Label 'start'
```

```powershell
  Invoke-ChildScript -Script (Join-Path $here 'status.ps1') -ScriptArgs @(
    '-InstallRoot', $root, '-PgService', $ServiceName,
    '-EvolutionServiceName', $EvolutionServiceName, '-AdapterServiceName', $AdapterServiceName,
    '-PgPort', "$PgPort", '-EvolutionPort', "$EvolutionPort", '-AdapterPort', "$AdapterPort",
    '-LogPath', (Join-Path $logDir 'status.log')
  ) -Label 'status'
```

Finally, update the summary text block (lines 302-321): change line

```powershell
    '3. Start/stop/status manual: installer\start.ps1 | stop.ps1 | status.ps1',
```

to:

```powershell
    '3. Service (auto-start saat boot, auto-restart jika crash): ' + $EvolutionServiceName + ', ' + $AdapterServiceName,
    '   Start/stop/status manual tetap bisa: installer\start.ps1 | stop.ps1 | status.ps1',
    '   Verifikasi service: installer\tests\check-service.ps1',
```

### 4.6 `installer/start.ps1`

Replace the `param()` block: remove `[string]$NodeExe`, add:

```powershell
  [string]$EvolutionServiceName = 'AuliaGatewayEvolution',
  [string]$AdapterServiceName   = 'AuliaGatewayAdapter',
```

Replace `Start-StackApp` (lines 30-51) with:

```powershell
function Start-ServiceAndWait {
  param(
    [string]$Name,
    [int]$Port,
    [int]$GraceSec
  )
  $svc = Get-Service -Name $Name -ErrorAction SilentlyContinue
  if (-not $svc) { throw ($Name + ': service tidak terdaftar. Jalankan installer\install.ps1 dulu.') }
  if ($svc.Status -ne 'Running') {
    Start-Service -Name $Name
    Write-Log ($Name + ': Start-Service dipanggil.')
  } else {
    Write-Log ($Name + ': service sudah Running.')
  }
  if (Wait-Listen -Port $Port -Seconds $GraceSec) {
    Write-Log ($Name + ': port ' + $Port + ' siap.')
  } else {
    Write-Log ($Name + ': service Running tapi port ' + $Port + ' TIDAK listen setelah ' + $GraceSec + ' detik.')
    throw ($Name + ' gagal start (service Running, port tidak merespons)')
  }
}
```

Remove the `if ($NodeExe) { ... } else { $tools = Resolve-NodeTools ... }` block (lines 57-63) entirely — Node resolution is no longer needed in `start.ps1` since WinSW already has the absolute `node.exe` path baked into its XML.

Replace the two `Start-StackApp` calls (lines 77-78) with:

```powershell
  Start-ServiceAndWait -Name $EvolutionServiceName -Port $EvolutionPort -GraceSec 420
  Start-ServiceAndWait -Name $AdapterServiceName -Port $AdapterPort -GraceSec 90
```

Leave the PostgreSQL block (lines 65-74) exactly as-is — it already uses `Start-Service`/`Wait-Listen` correctly.

### 4.7 `installer/stop.ps1`

Add params:

```powershell
  [string]$EvolutionServiceName = 'AuliaGatewayEvolution',
  [string]$AdapterServiceName   = 'AuliaGatewayAdapter',
```

Replace `Stop-OwnedProcess` (lines 31-61) with:

```powershell
function Stop-ServiceSafe {
  param(
    [string]$Name,
    [string]$Label
  )
  $svc = Get-Service -Name $Name -ErrorAction SilentlyContinue
  if (-not $svc) { Write-Log ($Label + ': service ' + $Name + ' tidak terdaftar (dilewati).'); return }
  if ($svc.Status -eq 'Stopped') { Write-Log ($Label + ': sudah Stopped.'); return }
  Stop-Service -Name $Name -Force -ErrorAction SilentlyContinue
  foreach ($i in 1..15) {
    if ((Get-Service -Name $Name).Status -eq 'Stopped') { break }
    Start-Sleep -Seconds 1
  }
  $final = (Get-Service -Name $Name).Status
  Write-Log ($Label + ': Stop-Service selesai, status=' + $final + '.')
}
```

Replace the two call lines (lines 64-65):

```powershell
Stop-ServiceSafe -Name $AdapterServiceName -Label 'adapter'
Stop-ServiceSafe -Name $EvolutionServiceName -Label 'evolution'
```

Keep the `-StopPostgres` block (lines 67-73) and the final port-check sanity net (lines 75-80) **unchanged** — they are an independent, still-valid safety net regardless of how the processes were stopped.

### 4.8 `installer/status.ps1`

Add params (next to `-PgService`):

```powershell
  [string]$EvolutionServiceName = 'AuliaGatewayEvolution',
  [string]$AdapterServiceName   = 'AuliaGatewayAdapter',
```

After the existing PostgreSQL service block (lines 30-33), add:

```powershell
Write-Log '--- service Evolution/adapter ---'
foreach ($svcName in @($EvolutionServiceName, $AdapterServiceName)) {
  $s = Get-Service -Name $svcName -ErrorAction SilentlyContinue
  if ($s) { Write-Log ('  ' + $svcName + ': ' + $s.Status + ' (start=' + $s.StartType + ')') }
  else { Write-Log ('  ' + $svcName + ': tidak terdaftar') }
}
```

Nothing else in `status.ps1` changes — port listen, instance check, and log tail stay as-is.

### 4.9 `installer/tests/check-static.ps1`

Keep sections 1-4 (scheduled-task prohibition, `aulia3`/`D:\` literal prohibition, task-not-registered check, `Import-DotEnv` prohibition, `setup-instance.js` literal check) **exactly as they are**. Add two new sections before the final `if ($problems.Count -eq 0) ...`:

```powershell
# 5. Service mechanism must exist (AC-1/AC-8): templates present, install.ps1
#    registers via WinSW, start/stop use Start-Service/Stop-Service.
$evoTemplate = Join-Path $installer 'services\evolution-service.xml.template'
$adapterTemplate = Join-Path $installer 'services\adapter-service.xml.template'
if (-not (Test-Path -LiteralPath $evoTemplate)) { $problems.Add('template service Evolution tidak ada: ' + $evoTemplate) }
if (-not (Test-Path -LiteralPath $adapterTemplate)) { $problems.Add('template service adapter tidak ada: ' + $adapterTemplate) }

$installPs1 = Get-Content -LiteralPath (Join-Path $installer 'install.ps1') -Raw
foreach ($token in @('Ensure-WinSW', 'Install-WinSwService')) {
  if ($installPs1 -notlike ('*' + $token + '*')) { $problems.Add('install.ps1 tidak memuat ' + $token + ' (mekanisme service hilang)') }
}

$startPs1 = Get-Content -LiteralPath (Join-Path $installer 'start.ps1') -Raw
if ($startPs1 -notlike '*Start-Service*') { $problems.Add('start.ps1 tidak memakai Start-Service') }
if ($startPs1 -like '*Start-Process*') { $problems.Add('start.ps1 masih memakai Start-Process (seharusnya service-based)') }

$stopPs1 = Get-Content -LiteralPath (Join-Path $installer 'stop.ps1') -Raw
if ($stopPs1 -notlike '*Stop-Service*') { $problems.Add('stop.ps1 tidak memakai Stop-Service') }

# 6. NSSM secara eksplisit tidak dipakai (keputusan user; WinSW saja).
foreach ($f in $files) {
  $t = Get-Content -LiteralPath $f.FullName -Raw
  foreach ($p in @('nssm', 'NSSM')) {
    if ($t -like ('*' + $p + '*')) { $problems.Add('literal "' + $p + '" di ' + $f.FullName + ' (NSSM tidak dipakai, hanya WinSW)') }
  }
}
```

Update the header comment (line 1-2) from *"AC-12 (tanpa scheduled task) + AC-13 (tanpa literal aulia3 ...)"* to also mention the new requirement, e.g.:

```powershell
# check-static.ps1 -- AC-12 (tanpa scheduled task, tanpa NSSM) + AC-13 (tanpa
# literal aulia3) + AC-8 (service WinSW WAJIB ada: template, install.ps1,
# start.ps1/stop.ps1 berbasis Start-Service/Stop-Service).
```

### 4.10 `installer/tests/check-service.ps1` (new file, full content)

```powershell
<#
  check-service.ps1 -- AC-9: verifikasi Windows Service gateway terdaftar dan
  Running pada MESIN YANG SUDAH TERINSTAL. Berbeda dari check lain di folder
  ini: skrip ini TIDAK memakai fixture sandbox, dan karenanya TIDAK
  dimasukkan ke run-checks.ps1 (pola yang sama dengan test-send.js) --
  jalankan manual setelah install.ps1 selesai, sesuai petunjuk-penggunaan.md.
#>
[CmdletBinding()]
param(
  [string]$PgServiceName        = 'postgresql-auliagw',
  [string]$EvolutionServiceName = 'AuliaGatewayEvolution',
  [string]$AdapterServiceName   = 'AuliaGatewayAdapter'
)

$ErrorActionPreference = 'Continue'
$problems = New-Object System.Collections.Generic.List[string]

function Test-OneService {
  param([string]$Name, [bool]$RequireAutomatic)
  $svc = Get-Service -Name $Name -ErrorAction SilentlyContinue
  if (-not $svc) { $problems.Add($Name + ': TIDAK TERDAFTAR'); return }
  if ($svc.Status -ne 'Running') { $problems.Add($Name + ': status=' + $svc.Status + ' (seharusnya Running)') }
  if ($RequireAutomatic -and $svc.StartType -ne 'Automatic') {
    $problems.Add($Name + ': StartType=' + $svc.StartType + ' (seharusnya Automatic)')
  }
  Write-Host ('  ' + $Name + ': status=' + $svc.Status + ' start=' + $svc.StartType)
}

Write-Host '=== check-service (AC-9) ==='
Test-OneService -Name $PgServiceName -RequireAutomatic $false
Test-OneService -Name $EvolutionServiceName -RequireAutomatic $true
Test-OneService -Name $AdapterServiceName -RequireAutomatic $true

if ($problems.Count -eq 0) { Write-Host 'PASS check-service (AC-9)'; exit 0 }
Write-Host 'FAIL check-service (AC-9):'
foreach ($p in $problems) { Write-Host ('  - ' + $p) }
exit 1
```

Design notes:

- `$RequireAutomatic $false` for PostgreSQL: its service was registered by `scripts/install-postgres.ps1` (out of scope for this change) and may legitimately use a different `StartType` policy; this script only asserts it is `Running`, matching what `status.ps1` already checks today.
- Exit code 1 on any failure, matching the convention every other `check-*.ps1` in this folder already uses.

### 4.11 `installer/petunjuk-penggunaan.md` §3 (replacement text)

Replace the current §3 block (lines 88-107) with:

```markdown
## 3. Service (auto-start, auto-restart) + Start / Stop / Status manual

Evolution API dan adapter berjalan sebagai **Windows Service** (dibungkus
[WinSW](https://github.com/winsw/winsw), bukan NSSM), terdaftar otomatis oleh
`install.ps1`:

| Service | Nama default | Dependensi |
|---|---|---|
| PostgreSQL | `postgresql-auliagw` (native, `pg_ctl register`) | — |
| Evolution API | `AuliaGatewayEvolution` | PostgreSQL (menunggu PostgreSQL *Running* sebelum start) |
| Adapter `evolution-gateway` | `AuliaGatewayAdapter` | Evolution API |

Konsekuensi:

- **Auto-start saat boot**: ketiga service `StartType=Automatic`. PC gateway
  yang di-restart (listrik mati, Windows Update, dll.) akan menyalakan ulang
  seluruh stack **tanpa perlu login operator**.
- **Auto-restart saat crash**: bila proses `node.exe` Evolution atau adapter
  mati di luar jalur normal (crash, dimatikan antivirus, dsb.), Windows
  Service Control Manager menyalakannya kembali **dalam hitungan detik**
  (percobaan pertama setelah 5 detik), tanpa menunggu siklus watchdog manual.
- Perintah `start.ps1`/`stop.ps1`/`status.ps1` **tetap tersedia** dan sekarang
  memanggil `Start-Service`/`Stop-Service`/`Get-Service` di balik layar:

```powershell
# Menyalakan PostgreSQL + Evolution + adapter (service)
powershell -ExecutionPolicy Bypass -File installer\start.ps1 -InstallRoot C:\AuliaGateway

# Mematikan Evolution + adapter (PostgreSQL tetap jalan kecuali -StopPostgres)
powershell -ExecutionPolicy Bypass -File installer\stop.ps1  -InstallRoot C:\AuliaGateway

# Status: service, port, proses, status instance, ekor log
powershell -ExecutionPolicy Bypass -File installer\status.ps1 -InstallRoot C:\AuliaGateway

# Verifikasi khusus: ketiga service terdaftar DAN Running
powershell -ExecutionPolicy Bypass -File installer\tests\check-service.ps1
```

- Melihat service secara native Windows (tanpa skrip):
  `Get-Service AuliaGatewayEvolution, AuliaGatewayAdapter` atau
  `services.msc`.
- Untuk juga mematikan PostgreSQL lewat `stop.ps1`: tambahkan `-StopPostgres`.
- Mengganti konfigurasi (port, path) setelah instalasi awal: jalankan ulang
  `install.ps1` dengan parameter yang sama — konfigurasi service (file XML
  WinSW) disegarkan otomatis; registrasi service itu sendiri tidak diulang.
```

Note: this keeps the exact required tokens for `check-runbook.ps1` (`install.ps1`, `start.ps1`, `stop.ps1`, `status.ps1`, section heading starting with `## 3. Start / Stop / Status` — the heading text itself changes to add "Service..." as a prefix; **verify** `check-runbook.ps1:11` matches `'## 3. Start / Stop / Status'` as a substring, which the new heading `## 3. Service (auto-start, auto-restart) + Start / Stop / Status manual` still contains verbatim).

## 5. Impact

- **Database / migrations**: none. PostgreSQL provisioning (`scripts/install-postgres.ps1`) is untouched.
- **Routes / API / response formats**: none. No HTTP contract changes.
- **Gateway contract (cross-repo)**: unchanged. This is purely a process-supervision change on the gateway PC; AuliaPos (CI4) side needs no change.
- **Existing data**: none touched.
- **Security / validation at trust boundaries**: WinSW services run as `LocalSystem` by default (same privilege level the old scheduled tasks used at `aulia3`, per `scripts/register-services.ps1:40` `UserId 'SYSTEM'`) — no privilege change. No new secrets are introduced; the WinSW XML contains only paths and service names, no credentials.
- **Transactions/concurrency/rollback**: `install.ps1` steps remain idempotent and re-runnable (see AC-7); a failed service registration throws and stops the script with a non-zero exit, consistent with every other step.
- **Backward compatibility**: `start.ps1`/`stop.ps1`/`status.ps1` keep the same file names, same primary parameters (`-InstallRoot`, `-PgService`, `-PgPort`, `-EvolutionPort`, `-AdapterPort`), and same exit-code conventions. Only `-NodeExe` (an internal plumbing parameter, not documented as a user-facing flag in `petunjuk-penggunaan.md`) is removed from `start.ps1`.
- **Upgrade-from-old-install risk** (not yet verified, flagged in §8): if an operator re-runs the **new** `install.ps1` against an `InstallRoot` that was provisioned by the **old** manual-start version and still has Evolution/adapter running as plain `Start-Process` processes (not yet stopped), `Install-WinSwService`'s `install` call registers the service fine, but the subsequent `start.ps1` → `Start-Service` could fail with a port-already-in-use error from the old orphan process. Mitigation documented in §8, not solved in code (out of the requested scope, and the stated test target is a **fresh** PC test machine, not an in-place upgrade).

## 6. Test plan

| Acceptance criterion | Test / verification | Type |
|---|---|---|
| AC-1 two services registered, Automatic | E2E on PC test: after `install.ps1`, `Get-Service AuliaGatewayEvolution,AuliaGatewayAdapter` → `StartType=Automatic` | manual E2E |
| AC-2 reboot → auto-start | E2E: reboot PC test, wait, `installer\tests\check-service.ps1` → PASS without running any script manually | manual E2E |
| AC-3 kill node.exe → restart ≤30s | E2E: `Stop-Process -Name node -Force` (or targeted PID), then poll `Get-Service` / port every few seconds, confirm `Running` + port listening again within 30s | manual E2E |
| AC-4 stop.ps1 stops all | E2E: `stop.ps1` then `Get-Service` → both `Stopped` | manual E2E |
| AC-5 start.ps1 starts all | E2E: `start.ps1` then `Get-Service` → both `Running`, ports listening | manual E2E |
| AC-6 status.ps1 shows 3 services | manual run, visual check of the new "service Evolution/adapter" block | manual check |
| AC-7 idempotent re-run | run `install.ps1` twice on the same root; second run must not fail on `Install-WinSwService`/`Start-Service`/port binding | manual E2E |
| AC-8 check-static.ps1 requires service | `installer\tests\check-static.ps1` run in-session (sandbox-safe, no admin/service needed since it only inspects file contents) | automated (session) |
| AC-9 check-service.ps1 exists and works | `installer\tests\check-service.ps1` run on PC test post-install | manual E2E |
| AC-10 runbook describes service model | `installer\tests\check-runbook.ps1` run in-session (unchanged assertions must still pass against the rewritten §3) | automated (session) |
| AC-11 no src/ or patch changes | `git -C C:\Projects\evolution-gateway diff --stat` after implementation shows only `installer/` paths changed | automated (session) |
| AC-12 no forbidden literals | `installer\tests\check-static.ps1` (new NSSM check included) | automated (session) |

Honesty note, matching the pattern already established in `docs/design/2026-10-02-paket-instalasi-gateway-pc-baru.md` §6: AC-1 through AC-7 and AC-9 require the actual PC test machine (reboot, process-kill, service start/stop) and **cannot** be executed from this dev/session environment without a real Windows service host. `check-static.ps1`, `check-runbook.ps1`, and `check-syntax.ps1` (file-content assertions only) **can** run in-session and should be run immediately after implementation, before handing off to the PC test.

## 7. Risks and mitigations

- **WinSW binary pinned per `InstallRoot`, not auto-upgraded**: `Install-WinSwService` only copies the WinSW exe once (skipped if the destination already exists, because the file is locked while its service is running). Upgrading WinSW version later requires stopping the service, deleting `<InstallRoot>\services\<id>.exe`, and re-running `install.ps1` with a new `-WinswVersion`. Documented limitation, not automated — acceptable because WinSW itself does not need frequent upgrades for this use case.
- **`repository.service.ts` has no DB-connect retry** (verified §2): mitigated by the WinSW `<depend>` on the PostgreSQL service name, which leverages PostgreSQL's own service-readiness signaling. Residual risk: if the PostgreSQL service is `Running` but briefly refuses new connections for an unrelated reason (e.g., mid-crash-recovery replay) exactly at the moment Evolution starts, Evolution could still hit the same silent-hang failure mode described in TODO-Q3c. This is a **pre-existing** Evolution API fragility, not newly introduced; fully fixing it would require patching `evolution-api-server` source, which is outside the three files this task names and outside the "adapter/CI4 contract/Evolution patches unchanged" constraint. Flagged here for visibility, not fixed.
- **In-place upgrade from the old manual-start installer** (see §5 "Upgrade-from-old-install risk"): not solved; the stated test target is a fresh PC, so this is documented as a known gap rather than implemented.
- **`<onfailure>` only reacts to process *exit*, not to a "Running but not actually serving" hang** (the exact TODO-Q3c symptom when it originates from the DB-connect race above): `start.ps1`'s `Wait-Listen` after `Start-Service` catches this **at install/start time** (throws loudly instead of reporting false success), but **does not** continuously re-check after that point — there is no restored watchdog loop, by design (the task explicitly moves away from watchdog/scheduled-task patterns toward relying on SCM restart-on-crash). If this residual gap matters operationally, a future follow-up could add a lightweight periodic health check, but that is out of scope for this task.
- **Admin rights required for `<exe> install`**: already satisfied, `install.ps1:125` calls `Assert-Admin` before anything else runs.

## 8. Not yet verified

- Real E2E on the actual PC test machine: reboot-to-auto-start, kill-to-restart-within-30s, `start.ps1`/`stop.ps1` full-stack behavior. These require the physical/VM Windows test machine named in the task and cannot be executed from this session.
- Whether Windows Defender or another AV on the PC test machine quarantines `WinSW-x64.exe` on first download/copy (similar to the Avast incident noted for `D:\evolution-gateway` in `docs/TODO.md` line with `monitor-aulia3.ps1`) — if it happens, the fix is an AV exclusion for `<InstallRoot>\services\`, to be added to `petunjuk-penggunaan.md` §8 (troubleshooting) only if actually observed.
- Exact behavior of `net stop`/`Stop-Service -Force` timing against WinSW's own `<stoptimeout>20 sec</stoptimeout>` under PowerShell's default `Stop-Service` timeout — if `Stop-Service` returns before WinSW's internal Ctrl+C-then-TerminateProcess sequence fully completes, `stop.ps1`'s own 15-iteration poll loop (§4.7) is the actual source of truth, not the `Stop-Service` call returning.

## 9. Approval (Gate 2)

- [x] Approved by: user, date: 2026-10-07
