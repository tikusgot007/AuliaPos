# Design: Installer Gateway Pakai Git (Ganti ZIP) untuk Pengambilan Source

- **Date**: 2026-10-08
- **Status**: draft
- **Requirements**: `docs/requirements/2026-10-08-installer-git-based-source.md`
- **SDLC tier**: A
- **Target repo (implementation)**: `C:\Projects\evolution-gateway` (remote
  `https://github.com/TIKUSGOT007/WA-Gateway.git`, branch `evolution`).
- **Intended implementer**: deepseek (aan-deepseek), working directly in
  `C:\Projects\evolution-gateway`.

## 1. Summary

Replace `Ensure-Source`'s ZIP-download-and-extract mechanism
(`install.ps1:177-183`, `Get-ZipUrl`, `Expand-RepoZip`) with a new
`Ensure-GitSource` function that `git clone`s a repo once and `git fetch` +
`git checkout <ref>` on subsequent runs, for both Evolution API and the
adapter. Evolution's 2 local patches remain applied via the existing
`Invoke-ChildScript` calls, unconditionally re-run after every checkout
(idempotent, already proven). `npm ci` gains a HEAD-changed guard so
dependency updates in a new commit are actually installed, while the
resume-after-failure skip (`node_modules` exists) is preserved when HEAD is
unchanged. Git becomes a new prerequisite, bootstrapped via `winget` with the
same pattern as `Ensure-Node`.

## 2. Current flow (verified)

```
install.ps1
  :89-94   Get-ZipUrl(Repo, Ref, IsTag) -> codeload.github.com/<repo>/zip/<ref|tags/ref|heads/ref>
  :177-183 Ensure-Source(Dest, Url, Label)
             -> if package.json exists at Dest: skip entirely (no update path)
             -> else: download zip to $dlDir, Expand-RepoZip (lib/common.ps1:164-182)
  :239-240 Ensure-Source for evolution-api (tag $EvolutionRef) and
           evolution-gateway (ref $AdapterRef)
  :254-267 npm ci per dir, SKIPPED if node_modules already exists (resume-after-
           failure optimization, not update-aware)
  :270-271 Invoke-ChildScript apply-viewonce-patch.ps1, apply-lid-preservation-patch.ps1
           (always run, already idempotent via marker check)

lib/common.ps1
  :164-182 Expand-RepoZip: extract to temp, expect exactly 1 top-level dir,
           Remove-Item -Recurse $DestDir if exists, Move-Item into place
           (this REMOVES the dest dir wholesale -- incompatible with a git
           working tree holding .git/ across runs if blindly reused)

installer/tests/check-static.ps1
  no existing assertion against or for Git usage (only forbids scheduled
  tasks / NSSM / literal aulia3 / Import-DotEnv)

installer/tests/run-checks.ps1
  :4-12 lists only fixture/static checks safe on any dev machine
```

Verified facts shaping this design:

- `Ensure-Source` is called with 2 distinct `(Dest, Url, Label)` tuples
  (`install.ps1:239-240`) — no shared state between the two sources, so a
  git-based replacement naturally stays per-source, per AC-7.
- `check-static.ps1` (`installer/tests/check-static.ps1:1-89`) scans every
  `.ps1`/`.md`/`.js` under `installer/` (excluding `tests/`) for forbidden
  literals; it does not currently forbid Git patterns, so no removal is
  needed there, only an addition for AC-8.
- `apply-viewonce-patch.ps1` / `apply-lid-preservation-patch.ps1` operate on
  `$evoDir` (the checked-out Evolution directory) and are idempotent by
  design (marker-based, `.orig-<date>` backup) — verified by
  `check-viewonce-patch.ps1` / `check-lid-preservation-patch.ps1`, which
  apply twice to a fixture and assert exactly 1 marker + 1 backup. No change
  needed to the patch scripts themselves; they are simply invoked on every
  checkout instead of only once at fresh install.
- `npm ci` block (`install.ps1:254-267`) loops over `{evoDir, adapterDir}`
  and skips per-dir based solely on `node_modules` presence; it has no
  concept of "did the source change". This is the one real gap for AC-6.
- Both repos confirmed public via GitHub API (2026-10-08): `TIKUSGOT007/WA-Gateway`
  (`"private": false`, default branch `evolution`) and
  `evolution-foundation/evolution-api` (public, tag `2.3.7` exists). No
  credentials/token needed for `git clone`.
- `Ensure-Node` (`install.ps1:96-110`) is the proven pattern for "ensure a
  CLI tool via winget, throw a clear message if winget absent" — reused
  as-is in shape for `Ensure-Git`.
- `Resolve-NodeTools` (`lib/common.ps1:132-143`) refreshes `$env:Path` from
  the registry after a winget install so the current process sees newly
  installed tools without a reboot/relogin — same technique needed for `git.exe`
  after `Ensure-Git` installs it.

## 3. Options

- **A. New `Ensure-GitSource` function, clone-once + fetch/checkout thereafter,
  detect HEAD change to gate `npm ci`.** Minimal diff: one new function
  replaces `Ensure-Source`'s 2 call sites; `Expand-RepoZip`/`Get-ZipUrl`
  left in place (dead code removed in a follow-up cleanup, not blocking).
  Git becomes a new prerequisite (bootstrapped via winget like Node).
- **B. Keep ZIP for Evolution API (vendor, rarely upgraded), Git only for
  adapter.** Smaller surface area (only adapter changes), avoids adding Git
  as a prerequisite for the less-frequently-updated component. Rejected:
  user explicitly asked for both to use Git during this session ("oke deal.
  gas" after discussing both repos being public); also Evolution still needs
  re-checkout + re-patch on upgrade, so having two different mechanisms for
  the two sources (ZIP vs Git) adds asymmetry for no real benefit once Git
  is a prerequisite anyway.
- **C. Git submodules inside the adapter repo for Evolution.** Rejected:
  Evolution is a separate, independently-versioned third-party project; a
  submodule implies a permanent structural coupling in the adapter repo's
  history that is not needed just to fetch source for a sibling directory.

**Recommendation: A.** Matches the approved requirements exactly (both
sources via Git, patches unchanged, independent per-repo updates), smallest
behavioral change (only `Ensure-Source`'s internals + the `npm ci` gate),
and reuses the existing `Ensure-Node`/`Resolve-NodeTools` patterns instead of
inventing a new prerequisite-bootstrap style.

## 4. Planned changes

All changes are inside `C:\Projects\evolution-gateway`. Nothing in `src/`
(adapter runtime code), the patch scripts' own logic, or the CI4 HTTP
contract changes.

| File | Change | Reason |
|---|---|---|
| `installer/lib/common.ps1` | Add `Ensure-Git` (winget bootstrap, same shape as `Ensure-Node`, refresh `$env:Path` via `Resolve-NodeTools`-style re-read). Add `Get-GitRemoteUrl(Repo)` helper returning `https://github.com/<Repo>.git`. Add `Ensure-GitSource(Dest, Repo, Ref)`: if `Dest\.git` missing, `git clone --no-checkout $url $Dest` then `git -C $Dest checkout $Ref`; else `git -C $Dest fetch origin` then `git -C $Dest checkout $Ref` (works for both a branch name, a tag, and a full SHA — `git checkout` resolves all three against `fetch`ed refs/tags). Return the resolved HEAD SHA (before and after) so the caller can gate `npm ci`. | AC-1, AC-2, AC-3, AC-7 |
| `installer/install.ps1` | Replace `Get-ZipUrl`/`Ensure-Source` call sites (`:239-240`) with `Ensure-GitSource -Dest $evoDir -Repo 'evolution-foundation/evolution-api' -Ref $EvolutionRef` and `Ensure-GitSource -Dest $adapterDir -Repo 'tikusgot007/WA-Gateway' -Ref $AdapterRef`, each returning old/new HEAD SHA. Add `Ensure-Git` call alongside `Ensure-Node`/`Ensure-Postgres` (`:228-232`). Change the `npm ci` loop (`:254-267`) to run `npm ci` when `node_modules` is absent **or** the dir's HEAD SHA changed this run (pass a `$headChanged` hashtable keyed by label from the `Ensure-GitSource` calls). Leave `apply-viewonce-patch.ps1`/`apply-lid-preservation-patch.ps1` calls (`:270-271`) exactly as-is — always invoked, unconditionally, after every run (idempotent). Remove `Get-ZipUrl` function if no longer called anywhere (verify `Ensure-Postgres`/`Ensure-WinSW` do not use it — they use `Get-RemoteFile` directly, not `Get-ZipUrl`, so safe to remove). | AC-1..AC-7 |
| `installer/tests/check-static.ps1` | Add assertion: `install.ps1` must contain `Ensure-GitSource` (mechanism present) and must **not** contain `Get-ZipUrl`/`codeload.github.com` (old mechanism fully replaced, not left as dead parallel path). | AC-8 |
| `installer/tests/check-git-source.ps1` (new) | Unit-style check mirroring `check-viewonce-patch.ps1`'s fixture pattern: create a throwaway local bare git repo with 2 commits (tag `v1`, `v2`), call `Ensure-GitSource` against it twice (once for `v1`, once re-run for `v2`), assert (a) first call produces a working tree with no `.git`-breaking side effects, (b) second call checks out `v2` without deleting gitignored marker files placed in the working tree (simulates `.env`/`data/` survival), (c) returned old/new SHA differ. Added to `run-checks.ps1`'s list (safe to run on any dev machine — no network, no admin, no real GitHub access). | AC-2, AC-3 |
| `installer/petunjuk-penggunaan.md` §2 | Update the "Apa yang dilakukan install.ps1" numbered list item 3 ("Mengunduh Evolution API ... dan adapter ...") to say "Mengambil (clone/checkout) Evolution API dan adapter dari Git" instead of "Mengunduh ... (ZIP)". Add a short new subsection under §2 or §3 explaining update: "jalankan ulang `install.ps1` dengan `-AdapterRef`/`-EvolutionRef` baru untuk update (git checkout, bukan unduh ulang)". Update the parameter table row for `-AdapterRef`/`-EvolutionRef` if wording implies ZIP. | AC-9 |

## 5. Impact

- **Database / migrations**: none. This change only affects how source code
  arrives on disk; `install-postgres.ps1`, `prisma migrate deploy` paths are
  untouched.
- **Routes / API / response formats**: none. Adapter HTTP contract
  (`/send`, `/send-media`, `/media/download`, `/evolution/webhook`) and CI4
  consumer contract are unchanged.
- **Gateway contract (cross-repo)**: none. This is purely a provisioning
  mechanism inside the gateway's own installer; CI4 (`aulia-app`) is not
  touched and does not need to know how the gateway's source was fetched.
- **Existing data**: none at risk for *this* change's scope (PC-baru /
  pre-production installs only, per requirements §5 "di luar cakupan" —
  `aulia3` production is explicitly out of scope until a separate cutover
  decision). For the dev rigs (`C:\AuliaGateway-test`,
  `C:\AuliaGateway-service-test`) this design does not mandate converting
  them; that remains a separate, smaller follow-up the user may do manually
  (as discussed) if desired.
- **Security / validation at trust boundaries**: `git clone`/`fetch` over
  HTTPS to public GitHub repos, no new credential storage. `Ensure-GitSource`
  must resolve `$Ref` strictly via `git checkout` (which fails loudly on an
  unknown ref) — no silent fallback to a different ref, preserving the
  existing "fail fast with clear message" posture (`install.ps1`'s
  `$ErrorActionPreference = 'Stop'` plus `Invoke-Native`'s explicit exit-code
  checks).
- **Transactions / concurrency / rollback behavior**: `Ensure-GitSource`'s
  checkout step must **not** delete the working tree wholesale (unlike
  `Expand-RepoZip`'s `Remove-Item -Recurse` behavior) — this is the key
  behavioral difference that makes gitignored files (`.env`, `data/`,
  `node_modules/`) survive an update. A failed `git fetch`/`checkout` (e.g.
  network loss mid-update) leaves the working tree at its last-known-good
  commit (git's own crash-safety), which is strictly safer than the ZIP
  path's "delete destination then extract" sequence.

## 6. Test plan

| Acceptance criterion | Test / verification | Type |
|---|---|---|
| AC-1 fresh clone + checkout to resolved ref | `installer/tests/check-git-source.ps1`: local bare repo, first `Ensure-GitSource` call, assert `.git` exists and `git rev-parse HEAD` matches tag `v1`'s commit | unit (new, no network/admin) |
| AC-2 re-run without ref change: no re-clone, gitignored files survive | same script: second call with unchanged ref, assert no `.git` directory recreation (compare `.git` dir inode/mtime or a sentinel file written before the 2nd call), assert a marker file simulating `.env` is untouched | unit (new) |
| AC-3 ref change: checkout only, gitignored files survive | same script: third call with `-Ref v2`, assert working tree content changed to `v2`'s fixture content, assert the `.env`-simulating marker file is still present and unchanged | unit (new) |
| AC-4 patches re-applied after checkout, still idempotent | existing `check-viewonce-patch.ps1` / `check-lid-preservation-patch.ps1` continue to pass unmodified (they test the patch scripts in isolation, independent of how `$evoDir` was populated) | unit (existing, unmodified) |
| AC-5 Git prerequisite bootstrap / clear failure message | manual E2E on a clean PC/VM without Git (per existing project convention: AC-1/AC-2/AC-6/AC-7/AC-9/AC-11 in the 2026-10-02 design were also flagged E2E-only); code-review check that `Ensure-Git` mirrors `Ensure-Node`'s throw-with-clear-message branch when `winget` absent | manual E2E + code review |
| AC-6 npm ci runs when HEAD changed, skips when unchanged | `installer/tests/check-git-source.ps1` extended assertion, or a small standalone unit test: fake `$headChanged` hashtable fed to the install.ps1 npm-ci-loop logic (extracted as a testable function if needed) asserts `npm ci` is invoked only for the changed label | unit (new) |
| AC-7 independent per-repo update | covered by AC-1..AC-3 test calling `Ensure-GitSource` separately for 2 distinct fixture repos, asserting one's checkout does not touch the other's working tree | unit (new, part of check-git-source.ps1) |
| AC-8 static scan confirms Git mechanism, ZIP mechanism removed | `check-static.ps1` new assertions (per §4 above) | check (existing script extended) |
| AC-9 runbook documents Git-based update | `installer/tests/check-runbook.ps1` extended to assert the runbook no longer says "Mengunduh" for source steps and mentions `git checkout`/update instructions (mirrors the existing required-sections pattern) | unit (existing script extended) |

Honesty note, matching the pattern already established in
`docs/design/2026-10-02-paket-instalasi-gateway-pc-baru.md` §6 and
`docs/design/2026-10-07-installer-service-based-winsw.md` (end of file):
AC-5 (Git bootstrap via winget on a machine without Git) and the full
end-to-end "install on a truly clean PC" flow cannot be executed from this
dev/session environment — this session's dev machine already has Git
installed system-wide. `check-git-source.ps1`, `check-static.ps1`, and
`check-runbook.ps1` (file-content / local-fixture assertions only) **can**
run in-session and should be run immediately after implementation.

## 7. Risks and mitigations

- **`git checkout <branch-name>` on a re-fetch leaves a detached HEAD or a
  stale local branch pointer** (git does not auto-fast-forward a local
  branch ref on `checkout` the way `pull` does) -> mitigate by always
  checking out via `git checkout --detach <resolved-remote-ref>` (e.g.
  `origin/<branch>` for branch refs, the tag/SHA directly otherwise), never
  relying on a local branch staying in sync. This matches the already-observed
  state in `C:\AuliaGateway-service-test` (`HEAD detached at 8a9db77`),
  confirming detached-HEAD checkouts are an acceptable, already-occurring
  pattern for this project.
- **A moving ref like `-AdapterRef master` resolves to a different commit
  on every fetch, silently changing what gets installed** -> this is
  pre-existing behavior (the ZIP mechanism has the same problem: fetching
  `refs/heads/master` ZIP today already gets whatever `master` currently is).
  Not a regression; same documented guidance to pin a commit SHA for
  production-bound installs already exists in `petunjuk-penggunaan.md` and
  `docs/design/2026-10-02-...md:157` ("Whether the adapter `master` HEAD is
  the intended release, or a commit SHA must be pinned").
- **`npm ci` HEAD-changed detection adds a new failure mode**: if the
  HEAD-tracking state is lost/misread, `npm ci` could be skipped when it
  should run (stale dependencies) -> mitigate by making the check
  fail-open (run `npm ci` whenever HEAD comparison is ambiguous/unavailable,
  e.g. first install), never fail-closed (skip on ambiguity). This is a
  strictly safer default than the current behavior (always skip if
  `node_modules` exists, regardless of source changes).
- **Removing `Get-ZipUrl`/ZIP path entirely removes a working fallback** if
  `git` or GitHub Git-over-HTTPS is blocked by a restrictive network/firewall
  that still allows `codeload.github.com` HTTPS downloads -> accepted
  trade-off per explicit user decision in this session ("oke deal. gas"
  after discussing both repos as Git clones); no evidence of such a network
  restriction for the target environments (dev PC, planned new production
  PC on the same LAN as the current gateway).
- **Evolution upstream re-tagging / force-pushing a tag** (rare upstream
  behavior) could make `git checkout <tag>` resolve to unexpected content
  after a `fetch` -> same risk profile as ZIP's `refs/tags/<tag>` URL today;
  not introduced by this change.

## 8. Not yet verified

- Exact `git checkout` behavior across PowerShell 5.1's native-command error
  surfacing (per the `Invoke-Native`/`Invoke-ChildScript` lessons already
  documented at `install.ps1:68-87`) — needs implementation-time
  verification that `git`'s stderr-on-success chatter (e.g. "Switched to..."
  messages, which some git versions write to stderr) does not trip
  `$ErrorActionPreference='Stop'` the way raw native stderr did for other
  tools; likely needs the same `Invoke-Native`/`$ErrorActionPreference =
  'Continue'` wrapper already used for `winget`/`npm`.
- Whether GitHub rate-limits anonymous HTTPS `git fetch` differently from
  anonymous ZIP downloads at any practically relevant frequency (unlikely to
  matter for manual, infrequent updates, but not measured).
- Exact winget package ID for Git for Windows (likely `Git.Git`, to be
  confirmed against the winget source at implementation time, same
  verification step `Ensure-Node` already does for `OpenJS.NodeJS.LTS`).

## 9. Approval (Gate 2)

- [x] Approved by: user (chat, "Ya"), date: 2026-10-08
