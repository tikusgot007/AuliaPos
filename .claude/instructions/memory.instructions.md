# Project Memory Log

> This file is managed by the `memory-manager` skill.
> It persists context across AI chat sessions to prevent knowledge loss.
> Do NOT manually edit this file unless necessary.

---

## 🧠 Knowledge Base

> This section accumulates cross-session knowledge that must survive compaction.
> Updated during Compaction Mode (Workflow 4). Do NOT delete entries here.
>
> **Compaction note (2026-09-23):** checkpoints for 2026-09-20 through 2026-09-23 (16 sessions, covering the M1 Wave 1 pipeline and the whole M3 Operational Inbox Fase 1 / Fase 2 / Fase 2a + review + refactor-plan pipeline) were compacted on 2026-09-23. Durable findings were promoted below. The 3 most recent checkpoints (2026-09-23 code review, refactor-plan clarification, refactor-plan amendment) were retained.

### Architecture & Patterns

- **Language policy per artifact:** PRD and `docs/audit/` reports are written in **Indonesian**; `/plan/` documents and `REMEDIATION STATUS` blocks are written in **English** (AGENTS.md "English-only documentation"). Conversational replies remain Indonesian. [Still valid 2026-09-23]
- **Strict session isolation:** a locked persona may not switch phase mid-session; the user can override explicitly, but the agent must print `[Session Override Active - Warning: Context Mixing Active]` first. A phase change normally belongs in a NEW session. [Recurring across all sessions]
- **Readiness scoring gate:** audits score 0–100 (Completeness 40 / Clarity 30 / Alignment 30); an unresolved fundamental contradiction caps the score at **79** (Critical Flaw Veto); the PROCEED/REFINE prompt fires at **≥80**; deadlock breaker after 3 iterations.
- **Remediation Protocol:** after an authoring agent revises a document, it appends `REMEDIATION STATUS: RESOLVED` with the projected score. The block goes **immediately after the H1** (front matter → H1 → banner), never above the H1 (see DE-06).
- **Narrow M2 gate (K-01):** the M2 State Consistency gate was opened only as an **expected-owner conditional write** (`WHERE id = ? AND assigned_to = ?` → `affectedRows() === 0` = 409, ownership never overwritten), reusing the existing `ambilPercakapan()` primitive. M2 as a program stays **deferred**; a Spec must say this explicitly and must not expand into a general state-consistency redesign (`docs/ARCHITECTURE.md` §12).
- **Ownership atomicity map (F-02):** `Inbox::ambilPercakapan()` (`app/Controllers/Inbox.php:1069-1097`) is already atomic (conditional UPDATE + `affectedRows()` → 409). The still-non-atomic paths are `lepas` (:1137), `tutup` (:1254), `snooze` (:1203), `tandaiDibaca` (:1170), `hapus` (:1002). Any future "M2 is required" claim must name which path.
- **ADR-0001:** the Queue View computed status (`ConversationModel::withComputedStatus()`) must be a thin layer over the existing `attachResponseState()` in `app/Controllers/Inbox.php`, never a parallel implementation. [Lives only on branch `v2.2` — see DE-30]
- **`conversation_handoffs` table contract (K-05/K-06):** additive migration, DB group `inbox`, English snake_case (`summary`, `next_action`, `note`), indexes `(conversation_id, created_at)` + `to_user_id`, no cross-DB FK to `users`; `conversation_id` = **BIGINT UNSIGNED** (FK to `conversations.id`); `from_user_id` nullable (owner before the write), `initiated_by_user_id` NOT NULL (always from session); the `messages` thread is untouched so REQ-009 stays safe.
- **Handoff HTTP contract (K-07/K-09):** `403` not permitted, `409` lost the race / rejected state (idempotency for free), `400` validation; `summary`/`next_action`/`note` max **4096**; allowed on every tab except `selesai` (collapses to `queue_status !== 'selesai'`); success body mirrors `tutupPercakapan()`.
- **Handoff initiator gate (D-01/P-05):** only a request whose initiator IS the current assignee can reach the conditional write; a non-assignee gets 403. On `belum_diambil` the initiator must be a member of `daftarKasirAktif`, so a non-assignee **admin** also gets 403 (Plan wins over Q2; locked by test E04). Widening the gate is a requirement change requiring `/sdlc-clarify-reqs`.
- **Handoff collision policy (K-04):** collision detection = **write-time conflict only** (409 + name of the lawful owner); Presence is deferred with a named precondition. Reusing an existing primitive on one more path is reversible → no ADR (Triple Gate fails).
- **Internal Note invariant (REQ-009):** `catatanInternal()` must NEVER call `ConversationModel::update()` for `last_message_at`/`last_message_direction`. Internal Note is allowed on `closed` conversations (no status gate).
- **Inbox query facts (verified in code):** `apiConversations()` has no filter params and hardcodes `findAll(100)`; `conversations.last_message_at`/`last_message_direction` are **denormalized** columns written explicitly at 3 message-insert call sites (`Inbox.php:834-835,1455-1456`, `InboxGatewayApi.php:262-263`), not an aggregate query. Filtering is filter-after-fetch in PHP; the limit was raised to `findAll(500)`.
- **Boolean column precedent:** the Inbox schema has **zero** `BOOLEAN` columns; the real precedent is `tinyint(1) NOT NULL DEFAULT ...` in the POS module (`CreateAuliaPosCore.php`). Decision `is_internal NOT NULL DEFAULT FALSE` stands on that justification.
- **SLA color rule:** `menunggu_customer` IS included; only `selesai` and `follow_up` (snoozed) are excluded.
- **CI4 test conventions:** `TestResponse::getJSON()` returns a JSON **string** (`json_decode($response->getJSON(), true)`); migrations are excluded from the composer classmap (`exclude-from-classmap **/Database/Migrations/**`) so a test must `require_once APPPATH . 'Database/Migrations/<file>.php'` first; `Config\Database::$inbox['numberNative'] = false` so DB ids arrive as **strings** (cast `(int)`).
- **MariaDB FK rule:** the child FK column type must match the parent exactly (`BIGINT UNSIGNED` for `conversations.id`) or MariaDB 10.4 rejects the DDL with errno 150.
- **Green/red test signal:** `vendor/bin/phpunit --no-coverage` is the exit-0 signal. `composer test` exits 1 solely because `phpunit.dist.xml` sets `failOnWarning="true"` plus coverage reports with no driver installed — a pre-existing, unrelated failure.
- **WA-Gateway scope invariant:** code changes only in `C:\projects\WA-Gateway-m1` (branch `feature/stage-1-reliability`), never the live gateway at `C:\projects\WA-Gateway`; never touch the live `auth/` folder. Repo is `tikusgot007/WA-Gateway` (not `tikusgot/...`).
- **Static guard beats runtime simulation:** for "register-before-send" ordering, add a source-reading static guard test (`fs.readFileSync` + regex verifying `register(` precedes `sendMessage(`/`await`); a runtime simulation can pass by accident with loose timing.
- **Repo topology / pointer staleness:** `docs/adr/`, `docs/audit/`, `docs/decisions/` exist **only on branch `v2.2`** — read them read-only via `git show v2.2:<path>`. The `.agents/` tree **does not exist**; the real tree is `.claude/` (`skills/`, `standards/`, `instructions/`). `AGENTS.md` still points documentation standards at the stale `.agents/standards/` (real path `.claude/standards/`).
- **M3 Fase 2 gating history:** Fase 2 was gated on M2 by `blueprint-m3-operational-inbox.md` (lines 100, 117-120, 133), `spec-design-m3-operational-inbox-fase1.md` §1.1, and PRD line 41 (Non-Goal) — the Non-Goal made Fase 2 an Orphaned Item. Resolved by amending the PRD to **v1.1** (GH-006 Handoff, GH-007 Collision Detection, GH-008 Auto-assignment). Scope split: **Fase 2a = Handoff + Collision Detection**, **Fase 2b = Auto-assignment**.
- **PRD bypass synergy (heavy lifting):** when the PRD is bypassed, the Spec guesses missing technical details and flags them with `[WARNING] [ASSUMPTION-00N]`; downstream agents must NOT block, only extract to "Risks & Assumptions"; the Clarification agent targets those assumptions first.
- **Dokumen hilang permanen:** `Panduan_Layar_AuliaPos_M3.md` and `status-proyek-master.md` were **never committed in any branch or tag** although the blueprint/spec cite them as basis. Fase 2 behavior must come from recorded decisions, never from those files.
- **Branch topology (as of 2026-09-24):** active branch is **`v2.3`** (local and `origin` in sync). Branches: `v2.1`, `v2.2`, `v2.3` (local + origin) and `v2.x` (origin only); `origin/HEAD` still points to `v2.1`. The M3 working branch `feature/m3-operational-inbox-fase1a-task001` was merged via PR #41 (`ce94660`) and **deleted locally and on GitHub on 2026-09-24** (0 unmerged commits) — start new work on a fresh branch off `v2.3`. The older `claude/m1-wave1-plan-clarify-y1km3u` is archived. WA-Gateway: `C:\projects\WA-Gateway` `master` = `origin/master` @ `21a4cb6`; the `WA-Gateway-m1` worktree no longer exists.

### Dead-Ends (Do NOT Repeat)

| # | Attempted | Why It Failed | Correct Solution |
|---|-----------|---------------|------------------|
| DE-01 | Running dependent git commands (`add`+`commit` pairs) as parallel tool calls in one response | They race on `.git/index.lock` ("File exists"); the interleaving produced a commit whose content did not match its message | Chain git commands with `;` inside ONE command string (sequential), never parallel calls; repair with `git reset --soft HEAD~1` + `git reset` |
| DE-02 | `git diff $base..HEAD` in PowerShell | PowerShell parses `$base..HEAD` as the **range operator**, so git received malformed args and printed usage | Build the range as a string first (`$range = $base + '..HEAD'`) or quote it |
| DE-03 | `$response->assertSee('id="panel"', false)` | CI4's signature is `assertSee(?string $search, ?string $element)` — the 2nd arg is a **CSS selector**; `false` coerced to `''` and DOMParser threw "read property length on bool" | Use `(string) $response->getBody()` + `assertStringContainsString`, or `assertSee($search)` with one argument |
| DE-04 | Piping phpunit through PowerShell (`vendor\bin\phpunit ... \| Select-Object -Last 6`) and reading the 30 s timeout as "too slow, run detached" | The console/pipe path is the slow part; phpunit itself runs the suite in ~5 s | `cmd /c 'vendor\bin\phpunit --no-coverage > build\<name>.txt 2>&1'` then read the file |
| DE-05 | `npx --no-install markdownlint-cli <file> > build\out.txt 2>&1` in PowerShell | PowerShell turns the CLI's stderr into a `NativeCommandError` and aborts the pipeline → the redirect file ends up empty | `cmd /c "npx --no-install markdownlint-cli <file> > build\out.txt 2>&1"` then read the file |
| DE-06 | Placing the remediation banner **above** the H1 of an audit report (copying the older m2-gate report) | Introduced a NEW `MD041/first-line-heading` finding on an otherwise clean file | Put the banner immediately **after** the H1 (front matter → H1 → banner) |
| DE-07 | `git commit -F -` from PowerShell 5.1 | PS 5.1 does not feed stdin that way; git treats the message as a pathspec | Commit from Git Bash with a heredoc (`git commit -F - <<'EOF'`), or write the message to a file |
| DE-08 | Integrity-checking a suspect SQLite file by opening it with a **writable** connection, then moving `-wal`/`-shm` aside | Closing a writable connection lets SQLite delete `-wal`/`-shm` itself → only 1 of 3 files was quarantined (violates REQ-013) | Probe with a read-only connection first (`{ readonly: true, fileMustExist: true }`) |
| DE-09 | PowerShell commands combining `Remove-Item` with a regex containing `\s*`, or with `cmd /c` | The command-safety check blocks them before anything runs | Split into separate calls; detach a junction with `cmd /c rmdir` (never `Remove-Item -Recurse` over a junction in PS 5.1); do file rewrites with a small Node script |
| DE-10 | Text-mutation helpers that assume LF line endings on the Windows checkouts | The checkouts are **CRLF**, so `\n` mutations silently did not apply | Convert `\n` → `\r\n` inside mutation helpers |
| DE-11 | Reading phpunit output containing ✔ through the console | Console CP1252 mangles the ✔ glyph | `[System.IO.File]::ReadAllText($p, [System.Text.Encoding]::UTF8)` |
| DE-12 | `json_decode($response->getBody(), true)` in a CI4 feature test | Returns `null` (the body is already consumed/stream) | `json_decode($response->getJSON(), true)` |
| DE-13 | Instantiating a migration class directly in a test (`new CreateConversationHandoffs()`) | Migrations are excluded from the composer classmap | `require_once APPPATH . 'Database/Migrations/<file>.php';` first |
| DE-14 | Declaring the migration FK child column `INT UNSIGNED` to match the Plan/Spec wording while pointing at `conversations.id` | MariaDB 10.4 rejects mismatched FK types (errno 150) | Child must be `BIGINT UNSIGNED` (same as `messages.conversation_id`); document the deviation in the migration docblock |
| DE-15 | Asserting a composite index by expecting one `SHOW INDEX` row | `SHOW INDEX` returns one row **per indexed column** (a 2-column index = 2 rows) | Assert on the row set, not a single row; note MariaDB 10.x normalizes `id DESC` in index DDL (backward scan still avoids filesort) |
| DE-16 | Comparing DB integer ids with `assertSame(int)` straight from a query result | `Config\Database::$inbox['numberNative'] = false` → ids arrive as **strings** | Cast with `(int)` in assertions (existing test convention) |
| DE-17 | Using bare `composer test` as the green/red signal | It exits 1 solely because `phpunit.dist.xml` sets `failOnWarning="true"` + coverage with no driver → "No code coverage driver available" (pre-existing) | Use `vendor/bin/phpunit --no-coverage` for an exit-0 signal |
| DE-18 | Appending test methods with `editor insert_line` at an estimated line number, and sending `new_text` blocks >6000 chars | The tool rejects oversized payloads; an approximated `insert_line` splits an in-progress method → "unexpected token public" / "Cannot redeclare" fatals needing a full-file rewrite | Count lines first (`(Get-Content $f).Count`) or anchor on a unique short `old_text`; keep chunks well under 6000 chars |
| DE-19 | Passing one large multi-line `old_text` block to the editor | It did not match exactly once → "text not found" twice although the text looked identical | Anchor on ONE line or a short unique substring, or use `insert_line`, then a second targeted edit |
| DE-20 | Measuring file content with bare `Get-Content` in PowerShell 5.1 | It decodes UTF-8 as CP1252 and shows mojibake dashes | `[System.IO.File]::ReadAllText($path, [System.Text.Encoding]::UTF8)` |
| DE-21 | Anchoring an editor replacement on a line containing `Config\\Inbox` | The file literally contains a **double** backslash, so a single-backslash `old_text` never matched | Anchor on a backslash-free line (e.g. the following `## 9.` heading), or use `insert_line` |
| DE-22 | Sending `expected_owner = <id>` for a conversation that is actually unassigned in a test payload | The server correctly answered **409** (the claim was stale), so the test failed for the wrong reason | The lawful "not yet taken" claim is an empty value (`expected_owner = ''`, Q5) — the conditional write matches `NULL <=> NULL` |
| DE-23 | `php -r 'var_dump((string) ["x"]);'` through PowerShell 5.1 | PS strips the inner double quotes when handing args to a native command → PHP parse error | Use a double-quoted PS string with **no** inner double quotes (`array(1)`, `chr(195)`), or write a temp file |
| DE-24 | `npx --no-install markdownlint-cli2 --version` (or plain `markdownlint`) to lint new docs | This repo has no `package.json` / local install → npx refuses ("canceled due to missing packages") | Use `npx --no-install markdownlint-cli` — the CLI **IS** available (v0.49.1; default MD013 limit is 80 chars). This supersedes the earlier "no markdownlint CLI available" note |
| DE-25 | `Select-String -Pattern 'a\|b'` with `\'`-escaped alternatives inside one quoted string | PowerShell parameter-binding error (`A positional parameter cannot be found`) | Use a double-quoted pattern or one pattern per call |
| DE-26 | Killing the Gateway to hit the "message sent but response lost" window | The window is about **1 ms** | Suspend the process (NtSuspendProcess) for longer than the 10 s AuliaPos timeout instead |
| DE-27 | Routing straight to `/sdlc-define-specs` for M3 Fase 2 because the Fase 2 checkpoint was recorded in memory | Fase 2 is gated on M2 by 3 upstream docs and would be an Orphaned Item (PRD declared it a Non-Goal) → `audit-consistency` would fail | One `/sdlc-clarify-reqs` session decides the narrow atomicity scope, then `/sdlc-define-specs` |
| DE-28 | Accepting the blueprint/spec claim "ownership is application-level, not atomic" and designing a general state-consistency fix | The claim is **stale for the take path** — `ambilPercakapan()` already performs a conditional UPDATE + `affectedRows()`; a general redesign also violates ARCHITECTURE §12 | Name WHICH path is non-atomic (the F-02 list) instead of redesigning the module |
| DE-29 | Diffing local `v2.2` against a feature branch to gauge PR scope (got 207 files / +81k) | The local `v2.2` branch ref was **stale**; `origin/v2.2` was actually identical to the merge-base | Always `git fetch origin <branch>` and diff against `origin/<branch>`, never a possibly-stale local ref |
| DE-30 | Reading `docs/adr/0001-...md` on the active branch | `docs/adr/` does not exist there; it lives only on `v2.2` | Read read-only with `git show v2.2:<path>` |
| DE-31 | Editing `docs/TODO-CHAT.md` while it was open in Word | Word locks the file (`~$` lock file, EPERM) | Ask the user to close Word first |
| DE-32 | Re-asking already-locked clarification items (Q1–Q8 / F-C05) in a follow-up session | All are recorded `[Disepakati — kunci]`; re-prompting violates the session rule | Mark accepted items `[Assumed / Out of Scope]` and never re-ask; point the new session back to the checkpoint |
| DE-33 | Claiming the decrypt errors came from session contamination from `/send`, and that the buffer preserves the original message time | The first was retracted then partly reinstated by correlation (unproven); the second is wrong because the timestamp Gateway receives is already shifted | Label proven vs hypothesis; a discriminating test needs a second, never-contacted test number |
| DE-34 | Reading `build/md-clarify-refactor.txt` being **0 bytes** as "clean lint" | 0 bytes was an empty redirect, not a clean lint run | The real baseline for these audit reports is MD013-only; never read "0 bytes" as "0 findings" |
| DE-35 | Replacing a whole PRD/Spec section by passing one large multi-line `old_text` block | The block did not match exactly once; the edit failed with "text not found" although the text looked identical | Anchor on one line / short unique substring, or use `insert_line`, then a follow-up targeted edit |

### Key Metrics & Baselines

- **AuliaPos PHPUnit suite:** **283 tests / 867 assertions** OK (2026-09-23, after M3 Fase 2a). Pre-Fase-2a baseline was 239 tests / 553 assertions (Fase 2a added 44 tests).
- **Inbox-module regression filter:** 77 tests / 424 assertions OK (2026-09-23).
- **M3 Fase 2a handoff test file:** `tests/session/InboxHandoffTest.php` — 26 tests / 192 assertions (H01–H08, C01–C04, G01–G05, E01–E08).
- **M3 Fase 2a boundary delta:** `app/Controllers/Inbox.php` **+312 / −0** (the 7 protected methods byte-identical); `app/Views/inbox/index.php` +325 / −1; zero diff on `ConversationModel.php`, `InboxSlaService.php`, `InboxGatewayApi.php`.
- **markdownlint baseline:** audit reports are **MD013-only**; plan/architecture docs effectively tolerate MD013 up to 400 chars (default limit 80). `docs/ARCHITECTURE.md` carries ~32 × MD013.
- **WA-Gateway M1:** 17 `test/simulate-*.js` scripts + 1 static guard `test/check-register-before-send.js`, all passing; branch `feature/stage-1-reliability`, 13 commits above `091fe19`.

---

## 📝 Session Checkpoint: 2026-09-23 (M3 Fase 2a Code Review)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Review (`/sdlc-code-review`) — M3 Fase 2a (Handoff + Collision Detection) audited over
  `600515c~1..HEAD` @ `3968aa0` (branch `feature/m3-operational-inbox-fase1a-task001`). Verdict: **0 Blocker, 0 Critical,
  2 Major, 8 Minor**. **No source code was touched** — the session produced a review report plus a refactoring plan, then
  stopped at the handoff gate.
- **Active Artifacts:**
  - `docs/audit/code-review-m3-fase2a-2026-09-23.md` — Status: ✅ new (Axis A/B review, findings CR-01..CR-10,
    traceability matrix REQ/CON → code → test, mutation-sensitivity table, frontend review, final verdict). 271 lines.
  - `plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md` — Status: ✅ new (mandatory refactoring template; Phase 1 =
    TASK-101..TASK-110 unconditional hardening/tests, Phase 2 = TASK-201..TASK-205 clarification-gated). 171 lines.
  - `plan/plan-feature-m3-operational-inbox-fase2a-v1.0.md` — ✅ delivered, unchanged (Completed/Date columns still blank).
  - `spec/spec-design-m3-operational-inbox-fase2a-handoff-collision.md` — 🔄 still v1.0 with the stale wording (RISK-01).
- **Achieved Milestones:**
  - Baseline re-verified independently: `vendor/bin/phpunit --no-coverage` → **OK (283 tests, 867 assertions)**.
  - Boundary claims re-verified by my own commands: `--numstat '600515c~1..HEAD'` = 7 files only, `Inbox.php`
    **312 insertions / 0 deletions** (7 protected methods byte-identical), zero diff on `ConversationModel.php`,
    `InboxSlaService.php`, `InboxGatewayApi.php`, the view diff is exactly 1 replaced toolbar line, and the migration only
    creates `conversation_handoffs` (no ALTER on `messages`/`conversations`). Test inventory 7+7+4+26 = 44 new tests.
  - Findings: **MAJOR CR-01** — `summary`/`next_action`/`note` are `(string)`-coerced, so an array payload stores the literal
    `"Array"` (proven with `php -r`: warning + `trim('Array') !== ''`); **MAJOR CR-02** — the locked Q5 contract
    "absent `expected_owner` = 400" has **no test**, so a refactor could silently turn it into 409/200 with the suite green.
  - **MINOR CR-03** — initiator gate keys on `assigned_to IS NULL` (superset of `queue_status === 'belum_diambil'`; reachable
    after snooze + `lepas`, no ownership overwritten, needs a product decision); **CR-04** — write uses the client
    `expected_owner` while `from_user_id` comes from the pre-transaction read (hair-thin race → stale audit value);
    **CR-05** — `strlen` (bytes) vs `mb_strlen` (chars) for the 4096 cap; **CR-06** — the `selesai` 409 lacks
    `current_owner_id`; **CR-07** — micro-contract test gaps (malformed `to_user_id`, oversized `note`, JSON dual-read,
    POST `auth` filter, gate order on double violations, 409 body key set, Asia/Jakarta); **CR-08** — `docs/ARCHITECTURE.md`
    never names `UserModel::daftarKasirAktif()` even though TASK-014 asked for it; **CR-09** — missing blank line at
    `Inbox.php:1215` + a 240-line method; **CR-10** — K-05's `to_user_id` index intentionally absent (YAGNI, no action).
  - Mutation-sensitivity assessment (static, no code changed): `C01`/`C02`/`E06` lock the conditional write,
    `C03` locks the rollback (real MariaDB trigger), `H07`/`G01` lock "Handoff never writes messages", `G02` locks
    cap/newest-first, `E07` locks snooze preservation, `H05`/`H08`/`C01b`/`E04` lock the 403 gates.
- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** `php -r 'var_dump((string) ["x"]);'` through PowerShell 5.1.
    **Reason:** PS strips the inner double quotes when handing arguments to a native command → PHP parse error.
    **Note:** use a double-quoted PS string with **no** inner double quotes (`array(1)`, `chr(195)`), or write a temp file.
  - **Attempted:** `npx --no-install markdownlint-cli2 --version` (and the plain `markdownlint` variant) to lint new docs.
    **Reason:** this repo has no `package.json` and no local install → npx refuses ("canceled due to missing packages");
    the MD013 counts in earlier checkpoints came from the VS Code extension, not a CLI.
    **Note:** lint manually with a small PowerShell rule script (MD009/MD010/MD012/MD013@400/MD022/MD032/MD058) — do not
    install packages just to lint.
  - **Attempted:** `Select-String -Pattern 'a|b'` with `\'`-escaped alternatives inside one quoted string.
    **Reason:** PowerShell parameter-binding error (`A positional parameter cannot be found`). **Note:** use a
    double-quoted pattern or one pattern per call.
  - **Confirmed still true:** `AGENTS.md` points at `.agents/` for skills, standards and the memory file, but `.agents/`
    **does not exist** in this repo — the real tree is `.claude/` (`skills/`, `standards/`, `instructions/`).
  - Referenced by label only (already documented in earlier checkpoints, still repo-wide): parallel git calls race on
    `.git/index.lock`; `$base..HEAD` is the PowerShell range operator; `TestResponse::assertSee`'s second argument is a CSS
    selector; phpunit piped through PowerShell is the slow part.

- **Updated Files:**
  - `docs/audit/code-review-m3-fase2a-2026-09-23.md` — new review report artifact (committed in `811221d`).
  - `plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md` — new refactoring plan artifact (committed in `811221d`).
  - `.claude/instructions/memory.instructions.md` — this checkpoint appended (append-only).
  - `git status --porcelain` shows **only those two untracked files** — no `app/`, `tests/`, or config file was modified.
- **Decisions Made:**
  - Reviewer scope honoured strictly: report + plan only; every fix is assigned to `/sdlc-write-code`.
  - Artifact languages: review report in Indonesian (matches its `docs/audit/` siblings), refactoring plan in English
    (matches `/plan/` siblings and the AGENTS.md "English-only documentation" rule).
  - CR-03 and CR-04 were deliberately **not** patched: they touch locked semantics (P-05/Q5), so they were gated behind
    `/sdlc-clarify-reqs` inside the plan (Phase 2) instead of being changed silently.
  - No new ADR (the findings are reversible hardening with no new trade-off).
  - Lint was validated with a manual PowerShell rule script (no CLI available); both artifacts report 0 issues.
- **Next Action / Pending:**
  - **NEW session:** `/sdlc-write-code` with `@plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md` → execute Phase 1
    (TASK-101..TASK-109), run the VERIFY gate, then stop at TASK-110 for explicit approval.
  - **NEW session:** `/sdlc-clarify-reqs` for CR-03 (gate reading), CR-04 (fail-fast 409) and the still-open
    Plan-vs-Q2 admin question; those answers unblock Phase 2 (TASK-201..TASK-205).
  - Both artifacts plus this checkpoint are committed and pushed as `811221d` on
    `feature/m3-operational-inbox-fase1a-task001` (origin in sync, fast-forward `3968aa0..811221d`).
  - This memory file **still has no Knowledge Base zone** — the dead-end list has now survived four checkpoints
    (git-lock, PS range operator, `assertSee` signature, phpunit pipe, plus `php -r` quoting and the missing markdownlint
    CLI); a Compaction Mode run is overdue and should finally create the Knowledge Base zone.

<!-- checkpoint-tail: M3 Fase 2a code review is DONE — 0 Blocker/0 Critical, 2 Major (CR-01 non-string summary/next_action/note coerced to "Array" and stored; CR-02 the locked Q5 "absent expected_owner = 400" contract has no test) plus 8 Minor, baseline re-verified at 283 tests / 867 assertions with Inbox.php 312 insertions / 0 deletions; artifacts are docs/audit/code-review-m3-fase2a-2026-09-23.md and plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md (Phase 1 TASK-101..110 unconditional, Phase 2 TASK-201..205 gated by /sdlc-clarify-reqs for CR-03/CR-04); next is /sdlc-write-code Phase 1 in a new session. -->

---

## 📝 Session Checkpoint: 2026-09-23 (M3 Fase 2a Refactor-Plan Clarification)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Clarification (`/sdlc-clarify-reqs`) — **completed** for
  `plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md` (Review Iteration 1). No source code was touched, and the
  plan itself was deliberately NOT edited (persona boundary: its five amendments belong to `/sdlc-plan-tasks`).
- **Active Artifacts:**
  - `docs/audit/clarification-report-m3-fase2a-refactor-plan-2026-09-23.md` — ✅ new, 211 lines, Readiness **86/100**
    (Completeness 34/40, Clarity 26/30, Alignment 26/30, no Critical Flaw Veto); User Decision Prompt = PROCEED.
  - `plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md` — 🔄 unchanged by this session; five amendments pending
    (TASK-201 insertion point + regression set, TASK-108 helper signature, TASK-202 third message branch + 3 tests,
    TASK-203 marked VOID, TASK-104 single test id).
  - `CONTEXT.md` — ✅ updated this session: new `### Kepemilikan Percakapan` with the canonical terms **Belum Diambil**
    and **Tanpa Pemilik**, plus the Handoff exception tightened to "percakapan yang tampil di tab Belum Diambil".
  - `plan/plan-feature-m3-operational-inbox-fase2a-v1.0.md` and
    `spec/spec-design-m3-operational-inbox-fase2a-handoff-collision.md` — unchanged; Spec finalisation still owes the
    Q2 narrowing sentence and the P-01..P-06 / 4096 / 409 boundary text.
- **Achieved Milestones:**
  - **CR-04 locked = A1:** fail-fast 409 inserted **immediately before `$db->transBegin()`** (`Inbox.php:1120`), i.e.
    AFTER both 403 gates; body byte-identical to the loser 409; helper signature
    `balas409KepemilikanBasi(?int $currentOwnerId)` (the race path keeps its own `find()`); full regression set
    `C01, C01b, C02, C04, E04, E06, H01, H06, H08`.
  - **CR-03 locked = A (TASK-202):** initiator gate keys off `queue_status === 'belum_diambil'` (reusing `$computed`
    from `:992`) + UI mirror `index.php:859-862` + three tests, AND a **third 403 message branch** is required
    (the existing wording of branches (i)/(ii) is locked by `E04(b)` `:750` and `E04(c)` `:762`).
  - **Product question settled:** the Plan wins over Q2 — an admin who is not the assignee stays **403** on
    `belum_diambil` (zero code/test/UI change; the admin loses nothing because `ambilPercakapan()` `:1387-1389`
    allows an unconditional admin claim first). Q2 must be restated as a deliberate narrowing at Spec finalisation.
  - Verified from code (not from the review's claims): unowned-and-not-`belum_diambil` states are reachable via
    mark-read + `lepas` (tab `menunggu`) and snooze + `lepas` (tab `ditunda`); the ABA window (`lepas :1449` then
    `ambil :1387-1391`); all four deterministic `expected` vs `read` combinations are behaviour-preserving under the
    fail-fast; `withComputedStatus()` (`ConversationModel.php:133-170`) sets `belum_diambil` only for
    `perlu_dibalas` + no owner.
  - Three near-blockers in the plan text: **CL-01** wrong insertion point (would flip non-assignee 403 → 409 and leak
    the owner name), **CL-02** incomplete regression list, **CL-03** TASK-108 signature/justification; plus **CL-04**
    glossary ambiguity and **CL-05** test-id divergence.

- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** capturing `npx --no-install markdownlint-cli <file>` output with a PowerShell redirect
    (`npx ... > build\out.txt 2>&1`).
    **Reason:** PowerShell converts the CLI's stderr into a `NativeCommandError` and aborts the pipeline, so the
    redirect file ends up empty and only one finding is ever shown.
    **Note:** use `cmd /c "npx --no-install markdownlint-cli <file> > build\out.txt 2>&1"` and then read the file.
    This **supersedes the earlier "no markdownlint CLI available" note** — the CLI IS available
    (`npx --no-install markdownlint-cli`, v0.49.1; the default MD013 limit is 80 characters).
  - Repo-wide dead-ends unchanged, referenced by label only: git index lock on parallel git calls, PowerShell
    `$base..HEAD` range operator, `TestResponse::assertSee` second-argument selector, phpunit piped through PowerShell.
- **Updated Files:**
  - `docs/audit/clarification-report-m3-fase2a-refactor-plan-2026-09-23.md` — new (this session's deliverable).
  - `CONTEXT.md` — added the `### Kepemilikan Percakapan` section (2 canonical terms) and tightened the Handoff
    definition; no other term touched.
  - `.claude/instructions/memory.instructions.md` — this checkpoint appended (append-only).
  - No `app/`, `tests/`, `plan/`, `spec/` or config file was modified; `git status` should show only the untracked
    `docs/audit/clarification-report-m3-fase2a-refactor-plan-2026-09-23.md` plus the modified `CONTEXT.md`.
- **Decisions Made:**
  - Clarification Analyst boundary honoured: report + glossary only; every plan fix assigned to `/sdlc-plan-tasks`,
    every code fix to `/sdlc-write-code`.
  - Markdown lint of the new report: MD025 and MD004 driven to zero (front matter without a duplicate `title:`;
    numbered sub-items instead of wrapped lines starting with `+`, which markdownlint otherwise reads as `+` bullets).
    Residual **121 × MD013** (80-column default) matches the repo-wide pre-existing baseline — the sibling
    clarification report carries 49 × MD013 + 10 × MD049 with a 469-character maximum vs 117 here.
  - No new ADR (Triple Gate fails: reversible text clarifications with no new trade-off).
- **Next Action / Pending:**
  - **NEW session:** `/sdlc-plan-tasks` to apply the five amendments to
    `plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md`, then append `REMEDIATION STATUS: RESOLVED` at the top of
    the new clarification report (AGENTS.md Remediation Protocol).
  - **NEW session:** `/sdlc-write-code` Phase 1 (TASK-101..TASK-110) — unaffected by this session, except that
    TASK-108 now has a justified second caller.
  - Spec finalisation still owes the Q2 narrowing sentence and the P-01..P-06 / 4096 / 409 boundary text.
  - This memory file **still has no Knowledge Base zone** (now overdue across five checkpoints); a Compaction Mode run
    should create it and promote the dead-end list.

<!-- checkpoint-tail: M3 Fase 2a refactor-plan clarification is DONE — CR-04 locked to a fail-fast 409 placed after both 403 gates (helper `balas409KepemilikanBasi(?int)`), CR-03 locked to `queue_status === 'belum_diambil'` with a third 403 message branch and three new tests, and the Plan-vs-Q2 admin question settled in favour of the Plan (admin stays 403 on belum_diambil); artifact docs/audit/clarification-report-m3-fase2a-refactor-plan-2026-09-23.md (86/100), CONTEXT.md gained Belum Diambil vs Tanpa Pemilik, and the plan's five amendments are pending in a new /sdlc-plan-tasks session before /sdlc-write-code Phase 1. -->

---

## 📝 Session Checkpoint: 2026-09-23 (M3 Fase 2a Refactor-Plan Amendment)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Planning (`/sdlc-plan-tasks`) — **completed** for the five clarifications-locked amendments on
  `plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md`, plus the mandatory `REMEDIATION STATUS: RESOLVED` block.
  Planner boundary honoured: no source code, test, Spec or PRD file was touched (only the plan + the audit report).
- **Active Artifacts:**
  - `plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md` — ✅ amended in place (+112 / −29; 254 lines). Phase 1
    (TASK-101..TASK-110) executable now; Phase 2 (TASK-201..TASK-205) no longer clarification-gated.
  - `docs/audit/clarification-report-m3-fase2a-refactor-plan-2026-09-23.md` — ✅ `REMEDIATION STATUS: RESOLVED` added as
    the first body block after the H1 (lines 10-51); projected Readiness **94/100** (37 + 28 + 29, no Critical Flaw Veto).
  - `spec/spec-design-m3-operational-inbox-fase2a-handoff-collision.md` — ⏳ still stale; owes the Q2 narrowing sentence
    and the P-01..P-06 / 4096 / 409 boundary text. That obligation is now recorded inside the plan's `GOAL-002`.
- **Achieved Milestones:**
  - Five amendments applied surgically: (1) TASK-201 — insertion point "around lines 1088-1104" replaced by
    "immediately before `$db->transBegin()`, AFTER both 403 gates", with the reason recorded (an earlier placement flips
    non-assignees 403 → 409 and leaks the owner name = Q3 violation) and the regression set expanded to
    `C01, C01b, C02, C04, E04, E06, H01, H06, H08` + `TEST-006` (mirrored in TASK-108/TEST-006/RISK-002);
    (2) TASK-108 — helper signature `private function balas409KepemilikanBasi(?int $currentOwnerId)`, two documented
    callers, no ownership re-read inside the helper; (3) TASK-202 — server gate on `$computed['queue_status']`
    (reuse of `:992`, zero extra queries) + UI mirror `index.php:859-862` + THREE 403 message branches ((i)/(ii)
    verbatim, locked by `E04(c)`:762 and `E04(b)`:750) + three tests; (4) TASK-203 marked VOID; (5) TASK-104 single id
    `H02b` and TASK-102 canonical id `E10` (the review's suggested name is an illustrative label only).
  - **Line-number correction verified against the tree:** `$db->transBegin()` is at `app/Controllers/Inbox.php:1126`;
    line 1120 starts its six-line comment block (the clarification report cites `:1120`). Both the plan and the report
    now state this explicitly so the implementor cannot mis-anchor the fail-fast 409.
  - Long-detail overflow moved into two "detail blocks" (TASK-108 after the Phase 1 table; TASK-201/TASK-202 after the
    Phase 2 table) so that no line exceeds 400 characters — max is 394, unchanged from the pre-amendment file.
  - Verification: `markdownlint` (repo-cached CLI) on the plan → MD013 only, zero structural findings; on the amended
    report → MD013 only; `git diff --numstat` on the plan = 112 insertions / 29 deletions.
- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** placing the remediation banner *above* the H1 of the audit report, mirroring
    `clarification-report-m3-fase2-m2-gate-2026-09-22.md` (whose banner sits above its H1).
    **Reason:** on this report it introduced a NEW `MD041/first-line-heading` finding on a file that was otherwise
    clean; the older file is simply lint-dirty, so it is not a safe precedent to copy.
    **Note:** put the banner immediately **after** the H1 (front matter → H1 → banner). Also,
    `build/md-clarify-refactor.txt` being 0 bytes was an **empty redirect**, not a clean lint run — the real baseline
    for these audit reports is MD013-only, so "0 bytes" must never be read as "0 findings".
  - Repo-wide dead-ends unchanged, referenced by label only: git index lock on parallel git calls, PowerShell
    `$base..HEAD` range operator, `TestResponse::assertSee` selector, phpunit piped through PowerShell, and the
    `cmd /c "... > file 2>&1"` form required for markdownlint output capture.
- **Updated Files:**
  - `plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md` — the five amendments plus the consistency updates they
    imply (REQ-007/REQ-008 LOCKED labels, Phase 2 heading/GOAL-002 unblocking + the Q2 note, TEST-006/TEST-007,
    RISK-002/003/004, FILE-001/002/004/005, and the closing Handoff paragraph).
  - `docs/audit/clarification-report-m3-fase2a-refactor-plan-2026-09-23.md` — remediation block (lines 10-51).
  - `.claude/instructions/memory.instructions.md` — this checkpoint appended (append-only).
- **Decisions Made:**
  - Amendments applied strictly in place (Surgical Edit Mandate); no new task was added and no requirement text changed
    beyond the five items. The consistency consequences listed above were reported to the user explicitly rather than
    being slipped in silently.
  - TASK-108 extraction range corrected to `:1148-1159` (owner-name resolution + payload), because `find()` at
    `:1144-1147` stays with the caller. "The helper performs no query of its own" means no ownership re-read, NOT
    "no `UserModel` lookup" — the name resolution stays inside the helper to keep the 409 body byte-identical.
  - No new ADR (Triple Gate still fails: reversible text clarifications, no new trade-off).
- **Next Action / Pending:**
  - **NEW session:** `/sdlc-write-code` **Phase 1 (TASK-101..TASK-110)** with the plan, the code-review report and the
    normative feature plan attached; VERIFY via TASK-109 (`vendor/bin/phpunit --no-coverage`, ≥ 283 tests /
    867 assertions, zero skips), then STOP for explicit approval (TASK-110).
  - **After that approval:** Phase 2 (TASK-201..TASK-205) — no longer gated; ship server + UI in the same commit for
    TASK-202 because the UI mirror must not offer an action that is guaranteed 403.
  - Spec finalisation still owes the Q2 narrowing sentence and the P-01..P-06 / 4096 / 409 text.
  - This memory file **still has no Knowledge Base zone** (overdue across six checkpoints) — a Compaction Mode run
    should create it and promote the accumulated dead-end list.

<!-- checkpoint-tail: M3 Fase 2a refactor-plan AMENDMENT is DONE — all five clarification-locked amendments are in the plan (TASK-201 placed immediately before `$db->transBegin()`, now verified as `Inbox.php:1126` not `:1120`, with the 9-test regression set; TASK-108 signature `balas409KepemilikanBasi(?int $currentOwnerId)`; TASK-202 three 403 branches + three tests with the server gate on `$computed['queue_status']` and the UI mirror at `index.php:859-862`; TASK-203 VOID; TASK-104 single id `H02b`), the clarification report carries `REMEDIATION STATUS: RESOLVED` with a projected 94/100, markdownlint is MD013-only, and the next step is `/sdlc-write-code` Phase 1 (TASK-101..TASK-110) in a new session. -->

---

## 📝 Session Checkpoint: 2026-09-23 (M3 Fase 2a Write-Code: Phase 1 + Phase 2)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Implementation (`/sdlc-write-code`) — **completed** for BOTH phases of
  `plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md`: Phase 1 (TASK-101..TASK-110) then, after explicit user
  approval, Phase 2 (TASK-201..TASK-205). One session; one task = one small commit; each task shipped its test in the
  same increment. Branch `feature/m3-operational-inbox-fase1a-task001`, baseline `bf9614e`, HEAD `51fb1fc`.
- **Active Artifacts:**
  - `plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md` — ✅ executed (both phases apply the 5 locked amendments).
  - `spec/spec-design-m3-operational-inbox-fase2a-handoff-collision.md` — ⏳ still stale (owes the Q2 narrowing sentence
    + P-01..P-06 / 4096 / 409 text); the next NON-code step (a NEW `/sdlc-define-specs` session).
- **Achieved Milestones:**
  - **Phase 1 commits:** `3136792` TASK-101 (`is_string()` guard before coercion, test E09); `dc14a83` TASK-102 (test E10
    for Q5 `expected_owner` absent → 400 + negative control); `77d822e` TASK-103 (`strlen`→`mb_strlen`, tests E11/E12);
    `2b4b3f6` TASK-104 (`current_owner_id` on the `selesai` 409 + test H02b); `d456da6` TASK-105 (six micro-contract tests
    E13–E18); `bb2e0b5` TASK-106 (`docs/ARCHITECTURE.md` names `UserModel::daftarKasirAktif()`); `f2aab5e` TASK-107
    (blank line); `ba31d9e` TASK-108 (helper `balas409KepemilikanBasi(?int $currentOwnerId)`).
  - **Phase 2 commit:** `51fb1fc` TASK-201 + TASK-202 — server + UI in one commit per RISK-004. TASK-201: fail-fast 409
    `if ($expectedOwner !== $assignedTo) return $this->balas409KepemilikanBasi($assignedTo);` placed AFTER both 403 gates
    and immediately BEFORE `$db->transBegin()`; no write on that path. TASK-202: unowned initiator exception narrowed to
    `($computed['queue_status'] ?? null) === 'belum_diambil' && in_array($userId, $idKasirAktif, true)`; three-branch 403
    message ((i)/(ii) verbatim, (iii) new); UI mirror `index.php` `dapatHandoff` gate. Tests F01 (no-write fail-fast),
    F02 (tab `ditunda` → 403), F03 (tab `menunggu` → 403), F04 (positive control after `ambilPercakapan()` → 200).
    TASK-203 remained VOID (not executed).
  - **VERIFY (TASK-109 + TASK-204):** full suite `vendor/bin/phpunit --no-coverage` → Phase 1 **294 tests / 930 assertions**;
    Phase 2 **298 tests / 948 assertions** (baseline 283/867 → +15 tests / +81 assertions), zero skips/suppressions, zero
    `ALTER TABLE` on `messages`/`conversations`. All 7 protected methods show **0 occurrences in the full diff**; zero diff
    on `ConversationModel.php` / `InboxSlaService.php` / `InboxGatewayApi.php`.
  - **RISK-005 not triggered:** E15 (JSON body → 200) passed as-is, no seam added.
- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** chaining `git add ... && git commit ...` (or any `&&`) through the default shell.
    **Reason:** the default shell here is PowerShell, which rejects `&&` ("token '&&' is not a valid statement separator")
    and mangles inner double quotes.
    **Note:** chain with `;` in PowerShell, or use `cmd /c "git add ... & git commit -F build\<msg>.txt"`; commit messages
    go through a temp file (`-F`) because inline `-m "..."` quoting gets split into pathspecs.
  - **Attempted (environmental, not code):** running the suite before migrations were applied.
    **Reason:** the `inbox` group DB had no `conversation_handoffs` table → 40 spurious errors (setup, never a regression).
    **Note:** apply the EXISTING repo migration with `php spark migrate` (no new migration, no schema change).
  - **Editor auto-format side effect:** VS Code format-on-save reformatted lines OUTSIDE the edited ranges (e.g.
    `fn (` → `fn(`, `if` reflow in `Inbox.php`; broad reflow in `index.php` plus pre-existing Intelephense "Undefined
    variable" false positives on the view). Pure cosmetics on top of the intended change — report, never hide; do a
    selective `revert`/`rebase` if a byte-exact diff is ever required.
  - Repo-wide dead-ends unchanged, referenced by label only: phpunit piped through PowerShell (use
    `cmd /c 'vendor\bin\phpunit --no-coverage > build\<name>.txt 2>&1'` then read the file — DE-04), git index lock on
    parallel git calls, `$base..HEAD` range operator, `TestResponse::assertSee` second-argument selector.
- **Updated Files:**
  - `app/Controllers/Inbox.php`, `tests/session/InboxHandoffTest.php`, `docs/ARCHITECTURE.md`,
    `app/Views/inbox/index.php` — all committed across the 9 commits above.
  - `.claude/instructions/memory.instructions.md` — this checkpoint appended (append-only).
- **Decisions Made:**
  - Executed strictly to the plan/spec contracts; no redesign, no new task, no Phase-2 work before explicit approval.
  - Phase 1 stopped at the TASK-110 APPROVAL gate; Phase 2 only started after the user's explicit "saya setujui".
  - The normative gate order Q3 is preserved end-to-end: 404 → 409 `selesai` → 400 validation → 403 initiator → 403 target
    → fail-fast 409 → conditional-write transaction.
  - No new ADR (reversible hardening + adopted clarifications; Triple Gate fails).
- **Next Action / Pending:**
  - **NEW session:** `/sdlc-define-specs` to finalise `spec/spec-design-m3-operational-inbox-fase2a-handoff-collision.md`
    (Q2 narrowing restatement, P-01..P-06, 4096 = characters, shared 409 shape with nullable `current_owner_id`,
    Q3 gate order, CR-04=A1 + CR-03=A semantics, three 403 branches). Documentation-only; no source code.
  - Then the usual downstream path: `/sdlc-clarify-reqs` → `/sdlc-plan-tasks` → `/sdlc-generate-docs`.
  - This session's write-code scope is CLOSED at HEAD `51fb1fc`.

<!-- checkpoint-tail: M3 Fase 2a WRITE-CODE is DONE for BOTH phases in one session — Phase 1 (TASK-101..TASK-110) and, after explicit user approval, Phase 2 (TASK-201 fail-fast 409 before transBegin reusing balas409KepemilikanBasi, TASK-202 narrowed belum_diambil gate + three-branch 403 + UI mirror; TASK-203 VOID), 9 small commits ending at HEAD 51fb1fc with the full suite green at 298 tests / 948 assertions (from 283/867), all 7 protected methods untouched and zero diff on ConversationModel/InboxSlaService/InboxGatewayApi; the only remaining step is a NEW /sdlc-define-specs session to finalise the stale Fase 2a Spec. -->

---

## 📝 Session Checkpoint: 2026-09-23 (M3 Fase 2a Spec Finalisation)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Specification (`/sdlc-define-specs`) — **completed** for the physical finalisation of
  `spec/spec-design-m3-operational-inbox-fase2a-handoff-collision.md` (v1.0 → **v1.1**). Documentation-only session:
  **no source code, test, migration, schema, or Gateway call** was touched; only that one spec file was modified
  (plus this checkpoint). Architect boundary honoured strictly.
- **Active Artifacts:**
  - `spec/spec-design-m3-operational-inbox-fase2a-handoff-collision.md` — ✅ **v1.1, no longer stale** (392 lines,
    +72 / reworked in place). Readiness **95/100** (Completeness 38, Clarity 28, Alignment 29, no Critical Flaw Veto).
  - `plan/plan-feature-m3-operational-inbox-fase2a-v1.0.md` — ✅ unchanged (normative; wins on conflict, RISK-01/CON-003).
  - `docs/audit/clarification-report-m3-fase2a-refactor-plan-2026-09-23.md` — ✅ unchanged (source of CR-03=A / CR-04=A1).
- **Achieved Milestones:**
  - **Six deliverables landed as surgical edits** (no full rewrite, per Surgical Edit Mandate):
    1. **Q2 narrowing (LOCKED)** — new Decision-Log row `Q2 (ditolak)` + a `> [!IMPORTANT]` block: an **admin
       non-assignee stays 403** on `belum_diambil`; the Plan wins over Q2 (RISK-01/CON-003) as a deliberate narrowing
       (product reason: closes the forbidden admin forced-handoff path; an admin can still claim first via
       `ambilPercakapan()`). Zero code/test/UI change.
    2. **P-01..P-06 explicit** — new **§1.2.2 "Patch Normatif"** table mapping each patch → binding contract → affected
       sections; `P-01..P-06` also appended to the Decision Log §1.2.1.
    3. **4096 = CHARACTERS** — §4.1 switched `summary`/`next_action` to `VARCHAR(4096)` (+ `note` = `TEXT` with a
       4096-controller cap) and added a CR-05 note (`mb_strlen` matches `VARCHAR(4096)`/`maxlength`). §4.4 now defines
       **two 409 families** (state `selesai` + ownership) with **one shared body** that includes **`current_owner_id`
       (nullable)** — this closes CR-06.
    4. **Q3 gate order** — §4.3 flow listed as 8 numbered steps and repeated as a compact block:
       **404 → 409 selesai → 400 → 403 initiator → 403 target → fail-fast 409 → conditional-write transaction**.
    5. **Latest semantics** — **CR-04 = A1** (fail-fast 409 before `transBegin()`, after both 403 gates, body
       byte-identical to the loser 409) at §4.3 step 6 + AC-H09; **CR-03 = A** (unowned exception only for
       `queue_status === 'belum_diambil'`) at §4.3 step 4 + the **three 403 message branches (i)/(ii)/(iii)** note in §4.4.
    6. **Open assumptions flagged** — `ASSUMPTION-002` (LOCKED by P-01), `ASSUMPTION-003` (SUPERSEDED by P-03), and new
       `ASSUMPTION-008..011` (Belum-Diambil ≠ tanpa-pemilik; residual window between fail-fast and conditional write;
       409 two-family body; H/C/G/E/F test-id mapping).
  - **Alignment edits:** REQ-H01/H02/H03/H08, AC-H02 (now 409), new AC-H08/AC-H09, §12 edge cases (initiator-not-owner
    now 403; `selesai` now 409), §15 traceability rows, and §4.3b (new GET endpoint, P-04). Introduction carries a
    v1.1 banner (Plan wins on conflict; docs-only).
  - **Verification:** targeted search confirms **no stale contract text remains** (every `500`/`selesai`=403/admin-boleh
    hit is now an explicit "was X, corrected by P-0N" reference); manual structural check via PowerShell → single H1,
    zero trailing whitespace, zero tabs, 392 lines.
- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** capturing the spec's lint via the repo-cached markdownlint CLI
    (`cmd /c "npx --no-install markdownlint-cli <file> > build\md-spec-fase2a.txt 2>&1"`).
    **Reason:** on this machine npx refused — `npm error npx canceled due to missing packages and no YES option:
    ["markdownlint-cli@0.48.0"]` (this **supersedes** the earlier "repo-cached CLI IS available" note for this run);
    the follow-up `find /c "MD"` returned `0`, which is exactly the DE-34 trap (empty redirect ≠ clean lint).
    **Note:** validate specs with the manual PowerShell rule script
    (`[System.IO.File]::ReadAllLines($p,[Text.Encoding]::UTF8)` + shape/max-length/trailing-ws checks), never read
    "0 bytes / 0 matches" as "clean".
  - **Attempted (twice):** emitting a `replace_in_file` call whose opening `<parameter name="diff">` tag was malformed.
    **Reason:** the tool received an EMPTY `diff` parameter → "The 'diff' parameter was empty" (no file change).
    **Note:** keep the call format exactly `<parameter name="diff">…</parameter>`; syntax slips silently void the edit.
  - Repo-wide dead-ends unchanged, referenced by label only: git index lock on parallel git calls, PowerShell
    `$base..HEAD` range operator, `TestResponse::assertSee` second-argument selector, phpunit piped through PowerShell,
    `php -r` inner-quote stripping in PS 5.1.
- **Updated Files:**
  - `spec/spec-design-m3-operational-inbox-fase2a-handoff-collision.md` — v1.0 → v1.1 (the six deliverables + alignment).
  - `.claude/instructions/memory.instructions.md` — this checkpoint appended (append-only).
  - No `app/`, `tests/`, `plan/`, `docs/`, `CONTEXT.md`, PRD or config file was touched.
- **Decisions Made:**
  - Spec finalisation stayed **documentation-only**; the Architect never wrote code and did not re-interrogate locked
    items (Q1, Q3, Q5..Q9, K-01..K-09, P-01..P-06, CR-01/02/05..09) — only restated the new CR-03/CR-04 locks and Q2.
  - No new ADR (Triple Gate fails: reversible text clarifications, unsurprising given Q2/REQ-H01, no new trade-off).
  - Header language of the §4.1 schema table kept as v1.0 (English) while new surrounding notes are bilingual —
    reported as a cosmetic deduction, not fixed silently.
  - Spec authoring language = Indonesian, matching the v1.0 file and its `spec/` sibling (the project's Specs are
    Indonesian, unlike the English `/plan/` docs) — an observed convention, not a new policy.
- **Next Action / Pending:**
  - **Spec is READY (95/100).** User Decision Prompt issued: **PROCEED** (recommended) or **REFINE**.
  - **NEW session (one persona per session):** `/sdlc-clarify-reqs` on the v1.1 Spec (target ASSUMPTION-008..011), then
    `/sdlc-plan-tasks` if the clarification asks for plan changes, then `/sdlc-generate-docs` (Diátaxis).
  - **Nothing pushed yet:** HEAD remains `51fb1fc`; the spec edit and this checkpoint are uncommitted (working tree
    dirty) — commit when the user asks (chain git with `;` in one command per DE-01).
  - This memory file **still has no Knowledge Base zone** (now overdue across seven checkpoints) — a Compaction Mode
    run should finally create it and promote the accumulated dead-end list.

<!-- checkpoint-tail: M3 Fase 2a SPEC FINALISATION is DONE — `spec/spec-design-m3-operational-inbox-fase2a-handoff-collision.md` moved v1.0 → v1.1 documentation-only (no code) with all six deliverables (Q2 deliberate narrowing = admin stays 403 on belum_diambil; P-01..P-06 table; 4096-CHARACTER cap via mb_strlen + two 409 families sharing a body with nullable current_owner_id; Q3 gate order 404→409 selesai→400→403 inisiator→403 target→fail-fast 409→transaction; CR-04=A1 fail-fast before transBegin after both 403 gates and CR-03=A belum_diambil-only gate with three 403 branches; open ASSUMPTION-008..011 flagged), readiness 95/100, verified no stale text remains, and the next step is a NEW /sdlc-clarify-reqs session followed by /sdlc-plan-tasks and /sdlc-generate-docs (spec + checkpoint still uncommitted at HEAD 51fb1fc). -->

---

## 📝 Session Checkpoint: 2026-09-23 (M3 Fase 2a Spec Text Remediation v1.2)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Specification (`/sdlc-define-specs`) — **remediation pass completed** for
  `spec/spec-design-m3-operational-inbox-fase2a-handoff-collision.md` (v1.1 → **v1.2**), driven by the six LOCKED
  resolutions in `docs/audit/clarification-report-m3-fase2a-assumptions-008-011-2026-09-23.md` (§2 + §4).
  **Documentation-only:** no `app/**`, `tests/**`, `app/Views/**`, migration, Routes or WA-Gateway file was touched.
  Only three text artifacts changed (Spec, `CONTEXT.md`, and the audit report's remediation banner).
- **Active Artifacts:**
  - `spec/spec-design-m3-operational-inbox-fase2a-handoff-collision.md` — ✅ **v1.2** (was v1.1). Header bumped
    `version: 1.2`, `last_updated: 2026-09-23`, plus a new v1.2 banner and a **6-row change-history table** referencing
    the clarification report. Projected Readiness **98/100**.
  - `CONTEXT.md` — ✅ glossary updated: `Belum Diambil` narrowed to "by any **active kasir**" (was "staff mana pun"),
    `Handoff` exception clarified to "may be initiated by an **active kasir**"; both `_Avoid_` lines preserved.
  - `docs/audit/clarification-report-m3-fase2a-assumptions-008-011-2026-09-23.md` — ✅ `REMEDIATION STATUS: RESOLVED`
    block added after the front matter (the report H1 is `# 🔍 …` at line 21, so the banner could not sit above it
    without breaking the YAML block; it was placed as the first body element after the front matter, ahead of the H1 —
    note this is an exception to the usual "after the H1" rule forced by the front matter's position).
- **Achieved Milestones:**
  - All six locked corrections applied as surgical `replace_in_file` edits (Surgical Edit Mandate; no full rewrite):
    (1) **ASSUMPTION-008** — deleted the stale "glossary does not yet contain these terms" sentence; stated the canonical
    rule (Belum Diambil Handoff limited to an **active kasir**);
    (2) **AC-H09** — removed the "Locked by `F01`" overclaim; the fail-fast is documented as a non-observable
    authorisation/audit guard whose only observable contract is "**409, no write, before the transaction**";
    (3) **ASSUMPTION-010 + §4.4** — documented the coverage asymmetry exactly: `E18` locks exactly-three-key for the
    **ownership** family, `H02b` locks only the **presence** of `current_owner_id` for the **state** family; "one shared
    body shape" stays a stated design; **no `H02c` added**;
    (4) **ASSUMPTION-011** — dropped the non-auditable "1:1" phrasing; the real method names in
    `tests/session/InboxHandoffTest.php` are the canonical ID source (`H01-H08`, `C01-C04` incl. `C01b`, `G01-G05`,
    `E01-E18`, `F01-F04`); no inventory table;
    (5) **REQ-H06** — restated honestly: `assigned_to` changes **and `updated_at` is refreshed** (proof
    `app/Controllers/Inbox.php:1177-1182`); other columns (`snoozed_until`, `last_message_*`, `status`) unchanged
    (snooze locked by `E07`);
    (6) **§4.4 + REQ-H09 + §4.3 step 7** — documented **HTTP 500** on the history-insert-failure rollback path
    (rollback, ownership intact, fixed message; proof `Inbox.php:1223-1232`, locked by `C03`).
  - **Evidence re-verified from source (not from the report's claims):** `Inbox.php:1177-1182` executes
    `UPDATE conversations SET assigned_to = ?, updated_at = ? WHERE id = ? AND assigned_to <=> ?`; `Inbox.php:1223-1232`
    catches → `transRollback()` → `setStatusCode(500)` with "Gagal menyimpan riwayat Handoff, percakapan tidak berpindah.";
    `InboxHandoffTest.php` method inventory confirmed live via search (C01b present, `E18` = exactly-3-key, `H02b` =
    presence-only, `C03` = assertStatus(500)).
  - **markdownlint differential (no new rule class):** this workspace has **no** `package.json` / markdownlint config, so
    the bare-default ruleset was used as an objective baseline. Committed v1.1 (`git show HEAD:`) vs working-tree v1.2
    both report the **identical rule set** `MD013, MD025, MD028, MD049, MD060` (`identical rule set: True`). `MD025`
    fires **exactly once at line 10** (`# Introduction`) in **both** versions → pre-existing, not a regression. Instance
    count rose 228 → 246 purely because the added text falls into pre-existing rule categories
    (MD013 ×183, MD060 ×48, MD028 ×12, MD049 ×2, MD025 ×1 in v1.2). No new rule class introduced.
- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** `npx --no-install markdownlint-cli2 <file>` (and later `markdownlint-cli --disable MD013 …` without a
    `--` terminator, and a `for /f` token extraction of the output).
    **Reason:** `markdownlint-cli2` is **not** in the repo and npx refused ("canceled due to missing packages and no YES
    option"); the `--disable` list needs a `--` terminator before the file args or the CLI prints its usage; and the
    `findstr → for /f` extraction echoed the literal word "error" per line instead of the rule id.
    **Note:** use `npx -y markdownlint-cli2` / `npx -y markdownlint-cli` (one-off download works here), and extract rule
    ids with **PowerShell** `[regex]::Matches($out,'MD\d{3}')` — not `findstr`/`for /f`. For a fast regression proof,
    run a **differential** lint (committed ref vs working tree) and compare the **rule-id sets**, not the raw counts.
  - **Attempted:** placing the remediation banner **above** the H1 of
    `docs/audit/clarification-report-m3-fase2a-assumptions-008-011-2026-09-23.md`.
    **Reason:** that file carries a YAML front matter block; the H1 is at line 21, so a banner above the H1 would have
    broken the front-matter structure. The banner was instead placed immediately **after** the front matter, before the H1
    (a deliberate exception to the usual "after the H1" rule from DE-06, forced by the front-matter position).
  - Repo-wide dead-ends unchanged, referenced by label only: git index lock on parallel git calls, PowerShell `$base..HEAD`
    range operator, `TestResponse::assertSee` second-argument selector, phpunit piped through PowerShell,
    `php -r` inner-quote stripping in PS 5.1, `cmd /c "… > file 2>&1"` for CLI output capture.
- **Updated Files:**
  - `spec/spec-design-m3-operational-inbox-fase2a-handoff-collision.md` — v1.1 → v1.2 (6 corrections + version bump +
    changelog table). `git diff --numstat`: 30 changed lines (26 insertions / 12 deletions incl. replacements).
  - `CONTEXT.md` — `Belum Diambil` and `Handoff` entries (8 changed lines).
  - `docs/audit/clarification-report-m3-fase2a-assumptions-008-011-2026-09-23.md` — `REMEDIATION STATUS: RESOLVED` block.
  - `.claude/instructions/memory.instructions.md` — this checkpoint appended (append-only).
  - `git status --porcelain`: `M CONTEXT.md`, ` M spec/…handoff-collision.md`, `?? .clinerules`,
    `?? docs/audit/clarification-report-m3-fase2a-assumptions-008-011-2026-09-23.md` — **no** `app/`, `tests/`,
    `Views/`, migration, Routes or WA-Gateway change.
- **Decisions Made:**
  - Applied the six locked decisions verbatim; did **not** reopen any locked input (Q1/Q3/Q5/Q6/Q7/Q9, K-01..K-09,
    P-01..P-06). Added **no** `H02c` test and **no** inventory table, per the locked Option X resolutions.
  - `composer test` deliberately **not** run (no code/test/UI/migration touched; suite stays `298 tests / 948 assertions`
    @ `51fb1fc`); markdownlint was the only verification, done differentially.
  - Banner placement in the audit report adapted to the front matter (reported, not hidden).
  - No new ADR (Triple Gate fails: reversible text clarifications, unsurprising given the locked decisions, no new trade-off).
- **Next Action / Pending:**
  - **Spec v1.2 is READY (projected 98/100).** Recommended next step for a **NEW session:** `/sdlc-audit-consistency`
    on PRD v1.1 ↔ Spec v1.2 ↔ Plan to lock traceability formally (attach all three).
  - Optional: commit the three text artifacts when the user asks (chain git with `;` in one command, per DE-01).
  - Then the usual downstream path remains: `/sdlc-generate-docs` (Diátaxis) for user-facing docs.


---

## 📝 Session Checkpoint: 2026-09-23 (M3 Fase 2a Artifact Consistency Audit)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Audit / Consistency Checkpoint (`/sdlc-audit-consistency`) — **completed** over
  PRD v1.1 ↔ Spec v1.2 ↔ Plan (feature + refactor) ↔ shipped code. **No source code was touched**; the session produced
  a consistency audit report and then stopped at the user-decision gate.
- **Active Artifacts:**
  - `docs/audit/consistency-audit-m3-fase2a-handoff-collision-2026-09-23.md` — ✅ new (Readiness **87/100**; Good Enough,
    no Critical Flaw Veto). Sections: executive summary, traceability findings (verified aligned + minor gaps),
    standards compliance, action plan, verdict & handoff.
  - `prd-20260922-0141-chat-whatsapp-inbox.md` — ✅ v1.1 unchanged (GH-006/GH-007 parent requirements intact).
  - `spec/spec-design-m3-operational-inbox-fase2a-handoff-collision.md` — ✅ v1.2 unchanged by this session.
  - `plan/plan-feature-m3-operational-inbox-fase2a-v1.0.md` + `plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md` — ✅ unchanged.
- **Achieved Milestones:**
  - **Readiness Score 87/100** (Completeness 36/40, Clarity 27/30, Alignment 24/30; no veto). The core traceability
    (PRD → Spec → Plan → code + tests) is 100% intact: GH-006 → REQ-H01..H10 / AC-H01..H09; GH-007 → REQ-C01..C04 /
    AC-C01..C03; snooze preservation locked by `E07`; P-01..P-06 + CR-03=A + CR-04=A1 + Q2 narrowing all reflected in
    Spec §1.2.1/§1.2.2 and in code (`51fb1fc`).
  - **No missing coverage, no orphaned items, no cross-document contradiction affecting code.** GH-008 auto-assignment,
    Presence and notifications/unread are consistently Out of Scope at every level (not scope creep).
  - **Verified against the codebase (not doc claims):** `Inbox::handoffPercakapan()`, `Inbox::apiHandoffs()`,
    `balas409KepemilikanBasi(?int)`, `ConversationHandoffModel`, migration `2026-09-23-000001_CreateConversationHandoffs`,
    both routes (`Routes.php`), and `UserModel::daftarKasirAktif()` all present; `docs/ARCHITECTURE.md` §4.2/§8/§12/§13
    names them; working tree clean at HEAD `7312c14`.
  - **Findings (all documentation/governance, zero code risk):**
    - **Missing ADR (K-01).** `clarification-report-m3-fase2-m2-gate-2026-09-22.md` §4 says the *expected-owner
      conditional write* meets the Triple Gate and warrants an ADR, but `spec/…fase2a…md` §10 says "No new ADR".
      Recommendation: create `docs/adr/0002-expected-owner-conditional-write.md` and reconcile Spec §10.
    - **Dangling reference (F-07).** `docs/adr/` does not exist on the active branch although Spec §14,
      `docs/ARCHITECTURE.md`, `spec/…fase1.md`, `plan/…fase1…md` and PRD §8.3 reference `docs/adr/0001-…`. Restore
      from branch `v2.2` (see DE-30).
    - **Version drift.** `plan/plan-feature-…fase2a-v1.0.md` still cites Spec `v1.0` (now `v1.2`).
    - **Internal plan inconsistency.** `plan-refactor-…v1.0.md` CON-002 summarises the gate order without the
      *fail-fast 409* step added by TASK-201.
  - **Verdict + user decision (recorded):** **PROCEED + save report**; ADR findings routed as governance backlog to
    `/sdlc-define-specs` + `/code-janitor`. Next phase: `/sdlc-generate-docs`.
- **Dead-Ends (Do NOT Repeat):**
  - No new tooling dead-end this session. Repo-wide dead-ends referenced by label only: git index lock on parallel git
    calls (DE-01), PowerShell `$base..HEAD` range operator (DE-02), `TestResponse::assertSee` second-argument selector
    (DE-03), phpunit piped through PowerShell (DE-04), reading a 0-byte CLI redirect as "clean lint" (DE-34), and reading
    `docs/adr/0001-…` on the active branch instead of `git show v2.2:<path>` (DE-30).
- **Updated Files:**
  - `docs/audit/consistency-audit-m3-fase2a-handoff-collision-2026-09-23.md` — new audit report artifact (this session's
    deliverable).
  - `.claude/instructions/memory.instructions.md` — this checkpoint appended (append-only).
  - No `app/`, `tests/`, `Views/`, `plan/`, `spec/`, PRD, `CONTEXT.md` or config file was modified.
- **Decisions Made:**
  - Auditor boundary honoured strictly: comparative cross-document analysis + one audit report only; no code, no PRD/Spec/
    Plan rewriting. Every fix stays with its authoring agent.
  - ADR absence (F-07 + missing K-01 ADR) is recorded as **governance backlog**, not a blocker, because the code is
    already consistent and green — hence no Critical Flaw Veto and a score above the 80 threshold.
  - No new ADR authored by this session (Auditor ≠ Author).
- **Next Action / Pending:**
  - **NEW session:** `/sdlc-define-specs` to reconcile Spec §10 with the K-01 ADR decision; and `/code-janitor` (or the
    ADR authoring path) to restore `docs/adr/0001-reuse-response-state-for-queue-view-status.md` and add
    `docs/adr/0002-expected-owner-conditional-write.md`.
  - Then `/sdlc-generate-docs` (Diátaxis) for user-facing documentation.
  - The audit report is untracked in the working tree — commit when the user asks (chain git with `;`, per DE-01).

<!-- checkpoint-tail: M3 Fase 2a ARTIFACT CONSISTENCY AUDIT is DONE — PRD v1.1 ↔ Spec v1.2 ↔ Plan ↔ code audited at Readiness 87/100 (Good Enough, no Critical Flaw Veto); traceability is 100% intact with no missing coverage and no orphaned items, and the only findings are governance-level (docs/adr/ absent though referenced everywhere = F-07; the K-01 expected-owner conditional write meets the Triple Gate but has no ADR while Spec §10 says "No new ADR"; plan-feature still cites Spec v1.0; refactor-plan CON-002 omits the fail-fast 409 step); artifact docs/audit/consistency-audit-m3-fase2a-handoff-collision-2026-09-23.md saved, user chose PROCEED + save with the ADR findings routed as backlog to /sdlc-define-specs + /code-janitor, and the next phase is /sdlc-generate-docs. -->

---

## 📝 Session Checkpoint: 2026-09-23 (Unfinished-Planning Inventory + M2 Stash Discard)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Documentation (`/sdlc-generate-docs`) — the tutorial from the previous checkpoint is
  already committed (`04c000f`). This session produced a **read-only inventory** plus **one deliberate git
  state change** (stash discard). No planning artifact was created.
- **Active Artifacts:**
  - `docs/tutorials/panduan-inbox-whatsapp-untuk-kasir.md` — ✅ committed `04c000f` (MD013-only lint).
  - `docs/audit/consistency-audit-m3-fase2a-handoff-collision-2026-09-23.md` — ✅ committed `ccc4bd2`.
- **Achieved Milestones:**
  - **Verified inventory of unfinished planning** (read-only): **(1)** M1 Wave 1 `TASK-017`/`TASK-018` are the
    only genuinely open tasks (`plan-process-m1-wave1-incoming-reliability-v1.0.md`, status `In progress`;
    TASK-001..016 already ✅); **(2)** three plans are executed but never closed (`plan-feature-...fase1`,
    `plan-feature-...fase2a`, `plan-refactor-...fase2a`: status `Planned`, every task row blank/`[ ]`);
    **(3)** `docs/Rencana Implementasi M3 Operational Inbox.md` is a stale duplicate plan (all DoD `- [ ]`);
    **(4)** referenced but absent on this branch: `docs/adr/`, `docs/decisions/`, `docs/TODO-CHAT.md`,
    `docs/GATEWAY-REQUIREMENTS.md`, `docs/CHAT.md` — all recoverable from `v2.2`;
    **(5)** Fase 1b leftovers verified in code: `sla_color` computed but never rendered, no search UI despite
    `?status=&q=` support, no `page` pagination, no snooze reason, no Internal Note composer UI.
  - **User decision (LOCKED): discard `stash@{0}`.** The stash (commit `4a789bf6`, 2026-09-22 15:03,
    "On v2.3") held only `prd-20260922-1200-m2-state-consistency-inbox.md` (+147) and a memory hunk (+30).
    Its substance is already covered by committed artifacts — m2-gate clarification `K-01`/`F-02`, PRD v1.1
    §2.3/§9.2, `docs/ARCHITECTURE.md` §12 (lines 298-299) — and its premise ("M2 must precede M3 Fase 2") is
    now false because Fase 2a shipped on the narrow K-01 gate. The earlier "priority #1 = rescue the M2 PRD"
    recommendation is therefore **VOID**; nothing of substance was lost. The hash stays recoverable via reflog
    until a prune.
- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** treating a local uncommitted `git stash` entry (a 147-line M2 PRD draft) as critical
    unfinished planning and recommending a rescue **before** checking whether its content already exists.
    **Reason:** the substance was already recorded — in places more accurately — by committed artifacts
    (`K-01`/`F-02`, PRD v1.1 §2.3/§9.2, `ARCHITECTURE.md` §12), while the draft's sequencing premise was
    obsolete; reviving it would have misled the next session.
    **Note:** generalizable rule — before "rescuing" a local-only artifact, grep the committed artifacts for
    the same facts, then **classify** it (`docs/0. Aturan dokumentasi.md` §15) instead of reviving it.
    Flagged for Knowledge Base promotion at the next compaction.
- **Updated Files:**
  - `.claude/instructions/memory.instructions.md` — this checkpoint appended (append-only).
  - No `app/`, `tests/`, `Views/`, `plan/`, `spec/`, PRD or config file was modified.
- **Decisions Made:**
  - Discard the stash **deliberately** rather than leave a superseded draft dangling; M2 as a *program* stays
    deferred per `K-01` and `ARCHITECTURE.md` §12.
  - The unfinished-planning inventory stays a chat deliverable — **no fourth overlapping planning document**
    was written. Remediation routing: `/code-janitor` (restore the v2.2 docs), `/sdlc-write-code` (close M1
    TASK-017/018), `/sdlc-plan-tasks` (sync the three trackers), `/sdlc-draft-prd` or `/sdlc-define-specs`
    (Fase 1b leftovers; M2/Fase 2b scheduling).
- **Next Action / Pending:**
  - **NEW session (`/code-janitor`):** restore from `v2.2` (read-only via `git show v2.2:<path>`, DE-30):
    `docs/adr/0001-reuse-response-state-for-queue-view-status.md`, the 5 `docs/decisions/*` files,
    `docs/TODO-CHAT.md`, `docs/GATEWAY-REQUIREMENTS.md`, `docs/CHAT.md`.
  - **M1 closure:** `TASK-017` may only run in the `>21:00 or <08:00` window with explicit approval (it stops
    the production Gateway 3×) and must write its decision log into `docs/decisions/`.
  - Deferred by design, unchanged: Fase 2b Auto-assignment (GH-008), Presence, notifications/unread, the M2
    program, M4, M5, GW-09.

<!-- checkpoint-tail: Read-only unfinished-planning inventory (M1 TASK-017/018 are the only truly open tasks; three plans executed but never closed; docs/adr|decisions|TODO-CHAT|GATEWAY-REQUIREMENTS|CHAT missing on this branch but present on v2.2; Fase 1b leftovers = SLA not rendered, no search UI, no pagination, no snooze reason, no Internal Note composer) plus a LOCKED user decision to DELIBERATELY DISCARD stash@{0} (4a789bf6, the 147-line M2 PRD draft) because its content is already covered by the m2-gate clarification K-01/F-02, PRD v1.1 2.3/9.2 and ARCHITECTURE 12 -- so the "rescue the M2 PRD" priority is VOID. -->

---

## 📝 Session Checkpoint: 2026-09-23 (M1 Closure Prep — Runbook + Plan Amendment Proposal)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Documentation (`/sdlc-generate-docs`) — continued. This session added two
  **non-normative** operational documents and corrected one recorded planning assumption. **No plan, spec,
  source code, or Gateway file was modified**; the Gateway was inspected read-only.
- **Active Artifacts:**
  - `docs/runbooks/runbook-m1-wave1-task017-ac001-2026-09-23.md` — ✅ new (How-to runbook for TASK-017).
  - `docs/proposal-amandemen-plan-m1-wave1-2026-09-23.md` — ✅ new (proposal for `/sdlc-plan-tasks`).
  - `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` — unchanged (status still `In progress`).
- **Achieved Milestones:**
  - **Verified (read-only) that TASK-017 would measure the WRONG code.** PM2 `wa-gateway` runs
    `script path = C:\projects\WA-Gateway\src\app\index.js` with `exec cwd = C:\projects\WA-Gateway`
    (= `master` @ `e18f716`, clean), while the M1 fix is `feature/stage-1-reliability` @ `065f683` and is
    **not merged** (`master..065f683` = 21 commits; master is 8 behind base `091fe19`).
  - **Verified the M1 worktree cannot host the test process:** `auth/` (the live WhatsApp session, 239 files)
    and `.env` exist **only** in the live folder — `C:\projects\WA-Gateway-m1` has neither.
  - **Verified the deploy is a clean fast-forward:** `git merge-base --is-ancestor master 065f683` → exit 0.
  - **User correction accepted and recorded (2026-09-23):** there are **no Inbox production users** and no
    customer waiting on that number, so the TASK-017 window constraint (`>21:00 or <08:00`) is **void** and
    RISK-003 drops to a temporary-delay risk. The plan text was **not** edited here; the change is proposed
    as an amendment (four items: RISK-003 rewrite, TASK-017 note removal + prerequisite, **new TASK-019
    DEPLOY**, housekeeping to v1.2).
  - Lint: both new docs are **MD013-only with zero structural findings** (MD032/MD029 found on the first pass
    were fixed by adding a blockquote separator and converting leftover ordered items to bullets).
- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** running the M1 code for TASK-017 from the worktree `C:\projects\WA-Gateway-m1`.
    **Reason:** it has no `auth/` (WhatsApp session) and no `.env`, so the process cannot connect to WhatsApp.
    **Correct solution:** deploy the branch into the live folder (fast-forward) and restart PM2 from there.
  - **Attempted:** invoking `pm2 ...` directly in PowerShell.
    **Reason:** the `.ps1` shim is blocked by execution policy ("running scripts is disabled on this system").
    **Note:** always call PM2 as `cmd /c "pm2 ..."`; use `--nostream` for `pm2 logs` so it cannot hang.
- **Updated Files:**
  - `docs/runbooks/runbook-m1-wave1-task017-ac001-2026-09-23.md` — new.
  - `docs/proposal-amandemen-plan-m1-wave1-2026-09-23.md` — new.
  - `.claude/instructions/memory.instructions.md` — this checkpoint appended (append-only).
- **Decisions Made:**
  - Keep the runbook (How-to) and the amendment proposal in **separate files**: Diataxis quadrant separation,
    and the proposal is explicitly non-normative until `/sdlc-plan-tasks` applies it.
  - Treat the missing deploy step as a **plan gap**, not something to improvise silently during the test.
  - Scope guard intact: AC-001 definition unchanged; E-02/E-07 stay out (RISK-004); `auth/` untouchable.
- **Next Action / Pending:**
  - **Priority #3 (`/code-janitor`):** restore from `v2.2` (read-only via `git show v2.2:<path>`, DE-30)
    `docs/adr/0001-...`, the 5 `docs/decisions/*` files, `docs/TODO-CHAT.md`, `docs/GATEWAY-REQUIREMENTS.md`,
    `docs/CHAT.md` — this also gives the TASK-017 decision log a home.
  - **Then:** `/sdlc-plan-tasks` applies the 4 amendments → `/sdlc-write-code` runs TASK-019 (deploy) →
    TASK-017 (3 attempts per the runbook) → decision log → TASK-018 approval.
  - Both new docs and this checkpoint are **uncommitted** at the time of writing (commit in the same session).

<!-- checkpoint-tail: M1 closure prep — verified read-only that PM2 runs the live folder on master e18f716 while the M1 fix (065f683) is unmerged and the worktree lacks auth/.env, so TASK-017 needs a missing DEPLOY step (fast-forward is confirmed safe); the user confirmed no Inbox production users so the >21:00/<08:00 window is VOID; delivered docs/runbooks/runbook-m1-wave1-task017-ac001-2026-09-23.md plus docs/proposal-amandemen-plan-m1-wave1-2026-09-23.md (4 amendments incl. new TASK-019), both MD013-only lint; next is priority #3 (restore docs/adr, docs/decisions, TODO-CHAT, GATEWAY-REQUIREMENTS, CHAT from v2.2). -->

---




## 📝 Session Checkpoint: 2026-09-23 (M1 Wave 1 Plan Amendment — v1.1 → v1.2)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Planning (`/sdlc-plan-tasks`) — **completed** for the four-item amendment on
  `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md`, applied from
  `docs/proposal-amandemen-plan-m1-wave1-2026-09-23.md`. Planner boundary honoured: **only the plan file** was
  modified (no spec, no source code, no Gateway/PM2 command, and neither the proposal nor the runbook was touched).
- **Active Artifacts:**
  - `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` — ✅ amended in place, **v1.2** (`+22 / −9`, 165 lines,
    9 diff hunks); front-matter status still `'In progress'` (flips to `'Completed'` only after TASK-018 approval).
  - `spec/spec-process-m1-wave1-incoming-reliability.md` — unchanged (v1.1); the AC-001 definition was NOT altered.
  - `docs/proposal-amandemen-plan-m1-wave1-2026-09-23.md` + `docs/runbooks/runbook-m1-wave1-task017-ac001-2026-09-23.md`
    — unchanged, now referenced from plan `§8`.
- **Achieved Milestones:**
  - (1) **RISK-003 rewritten:** environment is **not production** (no Inbox staff, no waiting customer), impact =
    temporary delay only (WhatsApp resends — that is exactly what AC-001 measures), **no mandatory window**;
    explicit APPROVAL stays mandatory. All ">21:00 / <08:00" obligations verified gone by text search.
  - (2) **TASK-017:** v1.1 window note deleted; new prerequisite "**MUST run only after TASK-019** — code under test
    MUST `065f683`; running it while the live folder is still `e18f716` measures the old code"; runbook reference
    added; `Dep` = `TASK-006,TASK-011,TASK-016,TASK-019`.
  - (3) **TASK-019 (new, DEPLOY)** inserted *before* TASK-017 in the Phase 3 table to keep bottom-up order: clean
    `status --short` → record `e18f716` as rollback point → `merge --ff-only feature/stage-1-reliability`
    (HEAD MUST `065f683`) → `pm2 restart` + `pm2 describe` (online, same `script path`) → never touch `auth/`, never
    `git checkout` → decision log into `docs/decisions/` (folder restored at HEAD `09da6bc`).
  - (4) **Housekeeping:** front matter `version: 1.2` / `last_updated: 2026-09-23`, bullet-style **Catatan v1.2**
    changelog; the `'In progress'` → `'Completed'` flip is recorded as an instruction inside the TASK-018 row.
  - Consistency consequences (disclosed to the user, no new task): CON-005 and the §2 EXECUTION DIRECTIVE now carry
    **one controlled exception** for TASK-019 (both previously banned any work on the live folder); DEP-005, `§8`,
    `§9` (deploy rollback bullet) and the v1.1 cross-reference note (TASK-017 note removed) were aligned.
- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** document the deploy only as TASK-017 prose while CON-005 / the execution directive still said
    "work in the worktree `C:\projects\WA-Gateway-m1` only".
    **Reason:** the plan contradicts itself (the deploy mutates the live folder), so the executing agent hits a rule
    conflict mid-task.
    **Correct solution:** put the TASK-019 carve-out into CON-005 *and* the execution directive.
  - **Attempted:** keep the v1.2 changelog as a single paragraph.
    **Reason:** it became a ~1.060-char line (this plan already carries 81 baseline MD013 line-length hits).
    **Correct solution:** bullet list — the changelog then adds zero long-line findings.
- **Updated Files:**
  - `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` — the four amendments plus the consistency edits above.
  - `.claude/instructions/memory.instructions.md` — this checkpoint appended (append-only).
- **Decisions Made:**
  - Amendment text kept in **Indonesian**, matching this plan/spec/runbook pair (AGENTS.md nominally asks for English
    SDLC docs); flagged to the user as an explicit assumption — translating the plan is separate work.
  - TASK-019's `Files` cell stays `-`: it changes no AuliaPos file (repo WA-Gateway only).
  - No new ADR (Triple Gate fails: reversible text clarifications, no new trade-off).
  - No `REMEDIATION STATUS` block on purpose: the source is a **non-normative proposal**, not a scored audit report,
    and this session was scoped to exactly one plan file.
- **Next Action / Pending:**
  - **NEW session:** `/sdlc-write-code` runs **TASK-019 (deploy)** → **TASK-017** (3 × AC-001 per the runbook) →
    decision log into `docs/decisions/` → **TASK-018** approval → flip plan status to `'Completed'`.
  - Critical path: TASK-018 cannot be approved before the live folder actually runs `065f683`.
  - Optional: `/sdlc-clarify-reqs` over plan v1.2 if the user wants a formal checkpoint before executing.

<!-- checkpoint-tail: M1 Wave 1 plan is amended to v1.2 by /sdlc-plan-tasks — RISK-003 now says the environment is not production with NO mandatory test window, TASK-017 lost its >21:00/<08:00 note and gained the hard prerequisite "only after TASK-019, code under test MUST 065f683", a new TASK-019 DEPLOY (fast-forward feature/stage-1-reliability into C:\projects\WA-Gateway + pm2 restart, rollback reset --hard e18f716) sits before TASK-017 in Phase 3, CON-005/execution-directive gained the single controlled live-folder exception, and the next session is /sdlc-write-code: TASK-019 -> TASK-017 (3 attempts, runbook) -> decision log -> TASK-018 approval, with the plan still 'In progress' until then. -->

---


## 📝 Session Checkpoint: 2026-09-23 (M1 Wave 1 CLOSURE — live deploy + real AC-001)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Code/Execution (`/sdlc-write-code`) — **completed**. M1 Wave 1 is CLOSED.
  No spec, code, `auth/`, or Gateway/AuliaPos source was modified in this session; the only writes
  were one new decision log (AuliaPos) plus the plan front matter + badge.
- **Active Artifacts:**
  - `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` — ✅ v1.2, status **`Completed`**
    (badge brightgreen); commit `4d43c8d` changed exactly 2 lines (front matter + badge).
  - `docs/decisions/2026-09-23-m1-wave1-deploy-dan-ac001.md` — ✅ new and complete (TASK-019 deploy
    evidence, 3 AC-001 attempts, interpretation, operational findings); commits `bd2f7de` (deploy)
    and `645d2a9` (AC-001 results); markdownlint 0 error.
  - `spec/spec-process-m1-wave1-incoming-reliability.md` (v1.1) — unchanged; the AC-001 definition
    was NOT altered.
- **Achieved Milestones:**
  - **TASK-019 DEPLOY:** `git -C C:\projects\WA-Gateway merge --ff-only feature/stage-1-reliability`
    → fast-forward `e18f716` → **`065f683`** (21 files, +2825/-48); `status --short` empty before and
    after; `pm2 restart wa-gateway` → `online`, `script path` =
    `C:\projects\WA-Gateway\src\app\index.js` and `exec cwd` = `C:\projects\WA-Gateway` unchanged;
    `logs/gateway.log` shows `connected` at 16:40:25 WIB. Rollback point remains `e18f716`
    (`reset --hard` + `pm2 restart`).
  - **TASK-017 AC-001 PASSED 3/3** with the runbook protocol (stop → 10 texts from the test phone to
    `6281913500707` → start): windows 16:45:47→16:48:12, 16:53:10→16:55:37, 16:57:12→16:58:34.
    **30/30 messages, 0 lost, 0 duplicate on BOTH sides** — `incoming_queue` id 116–145 (30 rows,
    30 unique `wa_message_id`) and AuliaPos `messages` id 168–197 (30 rows, 30 unique),
    `dup_groups=0`, plus 30/30 `[DELIVERY] pesan masuk berhasil diteruskan ke CI4` with
    `duplicate: false`.
  - Each attempt's offline batch was logged by Baileys (`handled 10` / `handled 11`).
  - Read-only measurement instruments proven in place: `node -e` + `better-sqlite3` inside the live
    folder (no file created in the live tree) and `mysql -u root aulia_inboxdb` (table `messages`).
  - **TASK-018 APPROVED** by the user → plan closed.
- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** calling `cmd /c "pm2 …"` from this agent's bash/MSYS shell.
    **Reason:** MSYS rewrites `/c` into `C:\`, so `cmd` opened interactively and PM2 never ran (only
    the cmd banner appeared; no version, no error). **Note:** use `cmd //c "pm2 …"` or
    `MSYS_NO_PATHCONV=1 cmd /c "pm2 …"`; the runbook's `cmd /c` form stays correct for PowerShell.
  - **Attempted:** treating `pm2 logs --nostream` as today's evidence.
    **Reason:** `~/.pm2/logs/wa-gateway-*.log` has not grown since **2026-09-21 20:46**, so 95 stale
    `Session error: … Bad MAC` lines looked like fresh failures (count stayed 95 over 12 s).
    **Note:** today's process output is in `C:\projects\WA-Gateway\logs\gateway.log` (JSON per line).
  - **Attempted:** chaining `pm2 start` + `sleep 60` + all queries into one shell command.
    **Reason:** the tool kills commands after 30 s, so the post-restart measurement was cut off.
    **Note:** keep each command under 30 s and re-query in follow-up steps.
  - **Attempted:** declaring "5 messages lost" about a minute after `connected` (attempt 1).
    **Reason:** offline messages arrive in waves — attempt 1 5+5 (+2 m 02 s), attempt 2 9+1 (+47 s),
    attempt 3 6+4 (+2 m 00 s); all were eventually stored exactly once. **Note:** wait at least
    2.5 minutes after `connected` before calling anything lost.
- **Updated Files:**
  - `docs/decisions/2026-09-23-m1-wave1-deploy-dan-ac001.md` — new (2 commits).
  - `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` — status + badge only (`4d43c8d`).
  - `.claude/instructions/memory.instructions.md` — this checkpoint (append-only).
- **Decisions Made:**
  - The live-folder deploy ran exactly once as the sanctioned CON-005 exception (merge --ff-only +
    pm2 restart only; no `git checkout`, no `npm install`, no live code edit, `auth/` untouched).
  - AC-001 was verified per `wa_message_id` uniqueness, never per message text, because attempt 2
    re-sent attempt 1's marker texts (real new messages, not REQ-005 duplicates).
  - E-02/E-07 remain OUT of scope (RISK-004); the delayed-arrival behaviour is recorded as an
    operational observation for the next wave instead of being patched here.
  - No new ADR (Triple Gate fails: reversible ops-only run plus documented observations).
- **Next Action / Pending:**
  - Wave 1 is closed. Next options: `/sdlc-clarify-reqs` or `/sdlc-code-review` over the M1
    deliverables, or start Wave 2 scoping (GW-09 `/send` idempotency, Ticket 09–11) — user's choice.
  - Carry-forward observations for the next wave: the wave-arrival tail of offline messages, Baileys
    `init queries Timed out` ±60 s after `connected` (pre-existing since 08:53), the `node.exe`
    (+87 MB) blob committed in WA-Gateway, and PM2 log files that no longer rotate/write.
  - Branch `feature/m3-operational-inbox-fase1a-task001` is now +3 commits this session
    (`bd2f7de`, `645d2a9`, `4d43c8d`) and is still unpushed.

<!-- checkpoint-tail: M1 Wave 1 is CLOSED — /sdlc-write-code deployed feature/stage-1-reliability into the live folder by fast-forward (e18f716 -> 065f683) and restarted PM2 (online, same script path, auth/ untouched), then measured real AC-001 3/3 with 30/30 messages and 0 lost / 0 duplicate on both sides (incoming_queue 116-145, AuliaPos messages 168-197), wrote docs/decisions/2026-09-23-m1-wave1-deploy-dan-ac001.md (bd2f7de + 645d2a9) and flipped the plan to 'Completed' (4d43c8d); key lessons are the MSYS cmd //c trap, stale PM2 log files, and waiting >=2.5 min after 'connected' because offline messages arrive in waves; next is wave-2 scoping or /sdlc-code-review. -->

---


## 📝 Session Checkpoint: 2026-09-23 (M1 Wave 1 code review)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Review (`/sdlc-code-review`) → awaiting user decision before `/sdlc-write-code` or PR
- **Active Artifacts:**
  - `spec/spec-process-m1-wave1-incoming-reliability.md` — v1.1, unchanged
  - `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` — v1.2 `Completed`, unchanged (review findings recorded, plan not edited)
  - `docs/audit/code-review-m1-wave1-2026-09-23.md` — ✅ new, two-axis review of WA-Gateway `e18f716..065f683`
  - `plan/plan-refactor-m1-wave1-incoming-reliability-v1.0.md` — ⏳ `Planned` (Phase 1 = P1, Phase 2 = 6 required P2)
- **Achieved Milestones:**
  - Reviewed all 21 files / 21 commits of M1 Wave 1 in worktree `C:\projects\WA-Gateway-m1` @ `065f683` (clean before and after). Live folder, `auth/`, PM2 untouched; no production code edited.
  - Verdict: **0 P0, 1 P1, 14 P2**. Spec axis: 19/19 REQ + CON-001..004 + GUD-001/002 met; AC-014 has no committed script (CR-15).
  - **CR-01 (P1), reproduced on Windows in scratchpad:** healthy SQLite DB locked by another connection at start → read-only `quick_check` blocks ~7.4 s → `SQLITE_BUSY` treated as "corrupt" → `renameSync` EBUSY → constructor throws → singleton catch (`incomingBuffer.js:584-593`) silently falls back to JSON with a misleading `warn` "better-sqlite3 tidak tersedia" → split-brain / silent message loss path.
  - 12/12 test scripts pass (temp `SQLITE_PATH`); floor-guard clean (no skip/eslint-disable/removed asserts); no token ever logged; `SUPERVISOR_TOKEN` does not exist in WA-Gateway source; CON-004 confirmed.
  - Declared deviations: (a)(c)(d)(e) documentation suffices; (b) needs owner confirmation (no `critical` log level); (f) fine but its file is orphan; **(g) inaccurate** — `OWN_SENT_TTL_MS<=0` accepted and silently disables the own-sent filter (CR-04).
- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** trusting sub-agent `file:line` citations verbatim. **Reason:** one agent quoted diff-hunk line numbers (e.g. `ownSentRegistry.js:212` for a 70-line file). **Note:** always re-grep line numbers from the real file at the reviewed SHA before writing a report.
  - **Attempted:** reproducing a DB lock while the first better-sqlite3 connection was still open. **Reason:** `BEGIN EXCLUSIVE` itself failed with SQLITE_BUSY. **Note:** close the writer connection first, then take the exclusive lock.
- **Updated Files:**
  - `docs/audit/code-review-m1-wave1-2026-09-23.md` — new review report (CR-01..CR-18, traceability matrix, 7-deviation table, evidence limits).
  - `plan/plan-refactor-m1-wave1-incoming-reliability-v1.0.md` — new refactor plan (TASK-101..105, TASK-201..209).
  - `.claude/instructions/memory.instructions.md` — this checkpoint.
- **Decisions Made:**
  - Refactor plan fix for CR-01: quarantine only on `quick_check != ok` or `SQLITE_CORRUPT`/`SQLITE_NOTADB`; constructor failure → `[CRITICAL]` log + rethrow (PM2 restarts) instead of JSON fallback (ALT-001 rejected: JSON rows are never read back).
  - Old overlapping tests (`simulate-enqueue-failure.js`, `simulate-e05-lid-timeout.js`, `simulate-e09-json-recovery.js`) to be deleted after a scenario-coverage mapping; they write to non-temp DBs and would inject fake pending rows if run in the live folder.
  - WA-Gateway copy of `docs/decisions/2026-09-21-m1-ticket01-baseline.md` (81 lines, stale vs 275-line AuliaPos copy) to be removed; AuliaPos is the single source.
  - Backlog only (not in plan, RISK-004): CR-05 (sync 5 s busy-timeout stall), CR-06 (static regex guard fragile; runtime assert already exists in append-handling #1-5), CR-07, CR-08 (`_lidFailureCache` unbounded), CR-09, CR-10 (SRP split), CR-11, CR-15.
- **Next Action / Pending:**
  - **User decision pending:** Option A (recommended) = run Phase 1 of the refactor plan via `/sdlc-write-code` before creating PR `feature/stage-1-reliability` → `master`; Option B = create PR now, fix in follow-up.
  - Owner confirmation still needed for deviation (b) (`logger.error` + `[CRITICAL]` instead of a `critical` level).
  - Leftover `data/test-e09-buffer.sqlite` (gitignored) remains in the M1 worktree; removal is TASK-206.
  - AuliaPos branch `feature/m3-operational-inbox-fase1a-task001`: the two new review files are uncommitted.

<!-- checkpoint-tail: /sdlc-code-review of M1 Wave 1 (WA-Gateway e18f716..065f683) produced docs/audit/code-review-m1-wave1-2026-09-23.md and plan/plan-refactor-m1-wave1-incoming-reliability-v1.0.md — 0 P0 / 1 P1 / 14 P2, spec 19/19 REQ met; the P1 (CR-01, reproduced) is a locked-but-healthy SQLite DB at start being treated as corrupt and the constructor failure silently falling back to JSON; awaiting user choice: fix Phase 1 via /sdlc-write-code before the PR (recommended) or open the PR now. -->

---


## 📝 Session Checkpoint: 2026-09-23 (M1 Wave 1 refactor — Phase 1 / CR-01 closed)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Implementation (`/sdlc-write-code`) — refactor plan Phase 1 DONE + approved; Phase 2 not started
- **Active Artifacts:**
  - `plan/plan-refactor-m1-wave1-incoming-reliability-v1.0.md` — `Planned`; TASK-101..105 done in code but checkboxes NOT yet ticked in the plan file
  - `docs/audit/code-review-m1-wave1-2026-09-23.md` — CR-01 now fixed
  - `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` — v1.2 `Completed`, untouched (reference only)
- **Achieved Milestones:**
  - WA-Gateway worktree `C:\projects\WA-Gateway-m1`, branch `feature/stage-1-reliability`: 3 commits on top of `065f683`, **pushed** after user APPROVAL (`065f683..bf23d2f`):
    - `2ca3065` TASK-101 `checkIntegrity()`: `{healthy:false}` only for `quick_check != 'ok'` or `err.code` in {`SQLITE_CORRUPT`,`SQLITE_NOTADB`}; other errors rethrown, no file moved.
    - `f87010e` TASK-102 singleton split: `require('better-sqlite3')` fails → warn + JSON (unchanged); constructor fails → `logger.error('[CRITICAL] gagal membuka database SQLite incoming buffer -- Gateway berhenti, TIDAK pindah ke JSON', {severity,path,error,code})` + rethrow.
    - `bf23d2f` TASK-103 scenarios 18/19/20 in `test/simulate-durable-buffer.js` (locked DB → SQLITE_BUSY, no quarantine, pending row survives; non-DB file still quarantined via SQLITE_NOTADB; child process on locked DB → exit 1, `[CRITICAL]`, no `.json`).
  - TASK-104 VERIFY: 9/9 regression scripts exit 0 (temp `SQLITE_PATH`, `CI4_*`/`LOG_FOLDER` empty), `data/` unchanged, `git status` clean. Mutation `catch → healthy:false` fails scenario 18 (`EBUSY` vs `SQLITE_BUSY`), file restored (sha256 `c2242d93…` identical). Extra mutation (old singleton from `2ca3065`) fails scenario 20 (child exit 0).
  - TASK-105 APPROVAL given by user.
- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** requiring `/c/projects/...` paths inside Node scripts run from MSYS bash. **Reason:** Node on Windows does not resolve MSYS POSIX paths. **Note:** use `C:/projects/...` inside JS; POSIX paths only for shell commands.
  - **Attempted (design trap):** asserting only "constructor throws" for the locked-DB test. **Reason:** on Windows the OLD code also throws (EBUSY from `renameSync` of the locked file), so the mutation would pass. **Note:** assert `err.code === 'SQLITE_BUSY'`.
  - **Attempted (design trap):** child test that only `require`s the singleton and checks "no .json". **Reason:** JSON buffer writes the file only on first enqueue, so old code also leaves no `.json`. **Note:** the child must `enqueue()` one event.
- **Updated Files (WA-Gateway):**
  - `src/store/incomingBuffer.js` — TASK-101 + TASK-102.
  - `test/simulate-durable-buffer.js` — scenarios 18-20 (+88 lines; test-side `timeout:100` wrapper `FastDatabase`, source unchanged).
- **Decisions Made:**
  - Corrupt-code set kept literal (`SQLITE_CORRUPT`, `SQLITE_NOTADB`); extended codes like `SQLITE_CORRUPT_INDEX` → hard stop, not quarantine (safe direction, RISK-002).
- **Next Action / Pending:**
  - **Next:** refactor plan Phase 2 (TASK-201..209) via `/sdlc-write-code` in a new session.
  - **TODO (new finding, out of scope):** with `LOG_FOLDER` set, pino async file destination (`sync:false`) loses the `[CRITICAL]` line when the process dies during module load (file not opened yet); stderr stack still visible to PM2. Scenario 20 pins `LOG_FOLDER=''`. Candidate for backlog.
  - Tick TASK-101..105 in the refactor plan file (AuliaPos) — not done yet.
  - Still open: owner confirmation of deviation (b); leftover `data/test-e09-buffer.sqlite` (TASK-206); AuliaPos branch `feature/m3-operational-inbox-fase1a-task001`: review files already committed (`81db651`, `eb59d0b`); only this checkpoint is uncommitted.
  - Test runtime note: scenario 20 takes ~7.5 s (default 5 s better-sqlite3 busy timeout, CR-05 backlog).

<!-- checkpoint-tail: Refactor plan Phase 1 (CR-01) is DONE and pushed on WA-Gateway feature/stage-1-reliability (065f683..bf23d2f: 2ca3065 quarantine only real corruption, f87010e constructor failure = [CRITICAL] + rethrow instead of silent JSON fallback, bf23d2f tests 18-20); 9/9 regressions pass, mutation tests caught; next is Phase 2 (TASK-201..209) in a new /sdlc-write-code session; new TODO: pino async LOG_FOLDER loses the [CRITICAL] line on startup crash. -->

---


## 📝 Session Checkpoint: 2026-09-23 (M1 Wave 1 refactor — Phase 2 done, plan Completed)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Implementation (`/sdlc-write-code`) DONE → **pushed** (origin = `fb585f1`), then PR decision
- **Active Artifacts:**
  - `plan/plan-refactor-m1-wave1-incoming-reliability-v1.0.md` — ✅ `Completed` (all TASK-101..105 and 201..209 ticked with commit SHAs)
  - `docs/audit/code-review-m1-wave1-2026-09-23.md` — CR-01, CR-02, CR-03, CR-04, CR-13, CR-14, CR-16 now fixed
  - `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` — v1.2 `Completed`, untouched
- **Achieved Milestones:**
  - WA-Gateway worktree `C:\projects\WA-Gateway-m1`, branch `feature/stage-1-reliability`: 6 commits on top of `bf23d2f`, **pushed** (`bf23d2f..fb585f1`, origin = `fb585f1`):
    - `8b00dfc` TASK-201 `tick()`: `overflowBuffer.drain()` first, own try/catch + `logger.error`, before `isRunning` (test durable-buffer #21: first tick hangs in stubbed `global.fetch`).
    - `f895a54` TASK-202 `ownSentTtlMs: Math.max(1, …)` (own-sent-registry #6: `0`/`-5` → 1).
    - `972c2a7` TASK-203 `_resolveLidForPhoneJid`: `if (!this.isConnected()) return null;` before query, no cache write; redundant `if` wrapper removed (whitespace-only reindent).
    - `5629f0b` TASK-204 append-handling #11 (AC-003 body keys outgoing == incoming, `contact_name` null), durable-buffer #22 (AC-007), #23 (AC-008).
    - `996202e` TASK-205/206 removed `simulate-enqueue-failure.js`, `simulate-e05-lid-timeout.js`, `simulate-e09-json-recovery.js`; mapping table in commit message; two gaps moved first (json-recovery #9 healthy round trip; durable-buffer #23 4 calls via `_persistIncoming`). `data/test-e09-buffer.sqlite` deleted.
    - `fb585f1` TASK-207 removed WA-Gateway copy of `docs/decisions/2026-09-21-m1-ticket01-baseline.md` (AuliaPos copy untouched).
  - TASK-208 VERIFY: 9/9 scripts exit 0; 7 mutation tests all caught, files restored with identical sha256; src diff vs `065f683` = 4 allowed files; `ci4Client.js` + `deliverOne` unchanged; no `package*.json` change; `data/` empty; git status clean.
- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** stubbing `ci4Client.postToCI4` after `incomingDelivery` was already required. **Reason:** `incomingDelivery.js` destructures `postToCI4` at load. **Note:** stub `global.fetch` (used by `postToCI4`), or stub `postToCI4` BEFORE the first `require('../src/delivery/incomingDelivery')`.
  - **Attempted:** asserting "exactly 1 error log" on the overflow path. **Reason:** by design 2 errors are logged (`enqueueRetry.js` "setelah dicoba ulang" + `_persistIncoming` "ke buffer utama"). **Note:** filter by message.
  - **Attempted:** `git push` and a bulk `node -e` plan-file rewrite from Bash. **Reason:** blocked by the auto-mode permission classifier ("Modify Shared Resources"). **Note:** use the Edit tool for doc edits; push needs explicit user permission or the user runs it.
- **Updated Files:**
  - WA-Gateway: `src/delivery/incomingDelivery.js`, `src/config/index.js`, `src/whatsapp/connectionManager.js`, `test/simulate-{durable-buffer,own-sent-registry,lid-timeout,append-handling,json-recovery}.js`; 3 old tests + `docs/decisions/…baseline.md` deleted.
  - AuliaPos: `plan/plan-refactor-m1-wave1-incoming-reliability-v1.0.md` (ticked, `Completed`), this checkpoint.
- **Decisions Made:**
  - AC-003 "tanpa identitas staff": the deliverOne contract has no staff field (CI4 sets `sent_by_user_id` NULL), so the assertion pins same key set as incoming + no staff-like key + `contact_name` null (own-account pushName not forwarded).
  - Floor-guard grep `xit(` also matches `process.exit(`; HEAD hits (7) are all `process.exit`, down from 13 because 3 files were deleted.
- **Next Action / Pending:**
  - ✅ **Pushed** `bf23d2f..fb585f1` to `origin/feature/stage-1-reliability` (after user said "push"; origin = `fb585f1`).
  - Then user decision: PR `feature/stage-1-reliability` → `master`. Deploy to live folder is NOT part of this plan.
  - Not run: 6 pre-M1 scripts in `test/` (audio-video, identity-hint, lid-conversation, send-media, sticker, tmpdir-override) — outside the plan's 9-script list.
  - Backlog unchanged: CR-05..CR-11, CR-15, pino async `LOG_FOLDER` TODO; owner confirmation of deviation (b).

<!-- checkpoint-tail: Refactor plan is COMPLETED — Phase 2 (CR-02/03/04/13/14/16) landed as 6 local commits on WA-Gateway feature/stage-1-reliability (bf23d2f..fb585f1: drain before isRunning, OWN_SENT_TTL_MS min 1, no null LID cache while disconnected, AC-003/007/008 asserts, 3 old tests removed with mapping, stale decision-log copy removed); 9/9 pass and 7 mutations caught; pushed to origin (fb585f1); next is the PR decision. -->

---


## 📝 Session Checkpoint: 2026-09-23 (M1 PR #4 merged + WA-Gateway branch cleanup)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** M1 Wave 1 + refactor MERGED to `master`; deploy of the refactor to the live folder pending (user decision)
- **Active Artifacts:**
  - `docs/TODO-CHAT.md` — updated for PR #4 merge + branch cleanup (supersedes the "PR belum dibuat" wording from `49bc882`)
  - `plan/plan-refactor-m1-wave1-incoming-reliability-v1.0.md` — `Completed` (unchanged)
- **Achieved Milestones:**
  - Read-only cross-agent audit of the M1 tracker (report handed to the tracker session; it applied 7/7 fixes in `49bc882` + memory `cfd6002`).
  - WA-Gateway **PR #4** `feature/stage-1-reliability` → `master` merged by the user on GitHub with **"Create a merge commit"**: `origin/master` = `21a4cb6` (parents `e18f716` + `fb585f1`; tree identical to `fb585f1`; `065f683` still in history). User chose merge commit over squash so SHAs cited in docs (AC-001 `065f683`, plan tables) stay valid and the live folder can still fast-forward.
  - Branch cleanup (commands run by the user after my attempts were blocked): remote branches `feature/stage-1-reliability`, `claude/buka-todo-chat-omnc7k`, `claude/aulia-wa-status-master-xg4fcr` deleted (the last held `test/simulate-reliability-baseline.js` + `docs/reliability/ticket-01-baseline-test.md`; user chose to drop it); worktree `C:\projects\WA-Gateway-m1` removed; local branch deleted; `origin/master` is the ONLY remote branch.
  - Live folder `C:\projects\WA-Gateway`: `master` @ `065f683`, clean, `behind 10` vs `origin/master` (9 refactor + 1 merge), PM2 online — untouched.
- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** `git push origin --delete …` and even read-only `git status` in the m1 worktree right after it. **Reason:** auto-mode classifier blocked them ("Git Destructive"). **Note:** hand destructive git commands to the user as ready-to-run bash lines (forward slashes: `C:/projects/...`; backslashes are eaten by MSYS bash, as the user hit with `C:\projects\WA-Gateway-m1`).
  - `gh` CLI is NOT installed on this machine; PRs are created/merged via the GitHub web UI (compare URL `…/compare/master...<branch>?expand=1`). PR existence can be checked read-only with `git ls-remote origin 'refs/pull/*/head'`.
- **Updated Files:**
  - `docs/TODO-CHAT.md` — lines 3, 44, 48, 85, 118, 119, 133 (Ticket 16 → `[x]`), 248 (PR done) + new line "Deploy 9 komit refactor" `[ ]`.
  - `.claude/instructions/memory.instructions.md` — this checkpoint.
- **Decisions Made:**
  - Merge strategy for M1: merge commit (not squash/rebase). `git log --first-parent master` gives the one-entry view.
- **Next Action / Pending:**
  - **User decision:** deploy the 9 refactor commits to the live folder (`git -C C:/projects/WA-Gateway merge --ff-only origin/master` → `21a4cb6`, then `pm2 restart wa-gateway`; rollback `065f683`). Not urgent; live `065f683` is proven by AC-001.
  - Backlog unchanged: CR-05..CR-11, CR-15, pino async `LOG_FOLDER`, deviation (b) owner confirmation; E-02/E-07; M1 Tickets 05–16 (waves 2–3).

<!-- checkpoint-tail: WA-Gateway PR #4 (M1 Wave 1 + 9 refactor commits, 30 total) is MERGED as merge commit 21a4cb6 and origin/master is the only remote branch (feature branch, two claude/* branches and the m1 worktree deleted); the live folder still runs 065f683 (behind 10) and deploying the refactor is the next user decision; gh is not installed and destructive git is blocked for the agent, so hand those commands to the user. -->

---


## 📝 Session Checkpoint: 2026-09-23 (M1 refactor DEPLOYED to live — 21a4cb6)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** M1 Wave 1 + refactor merged AND live; next = light real verification, then wave 2 scoping
- **Achieved Milestones:**
  - Live folder `C:\projects\WA-Gateway` fast-forwarded `065f683` → `21a4cb6` and `pm2 restart wa-gateway` — **run by the user** at 18:47 WIB (agent attempt blocked by the classifier as "Production Deploy"). Pre-checks by agent: tree clean, ff possible, no change in `package*.json`/`auth/`.
  - Post-deploy verification (agent, read-only): HEAD `21a4cb6` = `origin/master`, tree clean; PM2 online, restarts 2, unstable 0, script path/cwd = live folder; new pid 9300 `connected` at 11:47:14Z (18:47:14 WIB, ~3 s after start); 16 new log lines, all level 30, 0 `[CRITICAL]`.
  - `docs/TODO-CHAT.md` updated (lines 3, 44, 48, 51, 85, 133, deploy line now `[x]`, new `[ ]` real-verification line).
- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** agent-run `git merge --ff-only` + `pm2 restart` on the live folder. **Reason:** blocked ("Production Deploy"). **Note:** give the user the bash lines (`git -C C:/projects/WA-Gateway merge --ff-only origin/master`, `cmd //c "pm2 restart wa-gateway"`), then verify read-only.
- **Next Action / Pending:**
  - Real check of `21a4cb6`: send 1 WhatsApp message from the test phone → appears in AuliaPos Inbox (AC-001 was only measured on `065f683`).
  - Rollback if needed: `git -C C:/projects/WA-Gateway reset --hard 065f683` + `cmd //c "pm2 restart wa-gateway"` (then `e18f716`).
  - New startup behavior (CR-01): a locked SQLite DB at start now crashes with `[CRITICAL]` and PM2 retries (no silent JSON fallback).
  - Backlog unchanged (CR-05..CR-11, CR-15, pino async `LOG_FOLDER`, deviation (b), E-02/E-07, M1 Tickets 05–16).

<!-- checkpoint-tail: The M1 refactor is LIVE — the user fast-forwarded the live WA-Gateway folder to 21a4cb6 (= origin/master, PR #4 merge) and restarted PM2 at 18:47 WIB; it reconnected in ~3 s with no warnings/errors/[CRITICAL]; only simulation evidence exists for the 9 refactor commits, so the next step is a 1-message real check from the test phone. -->

---


## 📝 Session Checkpoint: 2026-09-23 (M3 Fase 1 TASK-012 — Snooze reason as Internal Note)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Implementation (M3 Fase 1b) → next: Review
- **Active Artifacts:**
  - `spec/spec-design-m3-operational-inbox-fase1.md` — unchanged
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — status `In progress`; TASK-012 ✅ 2026-09-23; TASK-013 Partial (AC-007 only). TASK-001..011 have code in the repo but were NOT re-verified/marked in this session.
- **Achieved Milestones:**
  - Snooze dialog `#modalSnooze` in `app/Views/inbox/index.php`: duration presets in the Follow-up dropdown now open the dialog with an optional "Alasan" textarea; "Batal" (menit 0) still runs directly.
  - Flow: POST `/snooze` (JSON `menit`) → only if reason filled, POST `/catatan` (form `teks`) → 1 Internal Note. Note failure = warning toast "Snooze berhasil, tapi alasan gagal disimpan." (no retry, no rollback).
  - `tests/session/InboxSnoozeAlasanTest.php` (3 tests). Full suite 301/301 green.
- **Decisions Made:**
  - CL-006 (reason > 4096 → snooze must not be saved) enforced client-side BEFORE the snooze call, counting UTF-8 bytes (`TextEncoder`) to match `strlen()` in `catatanInternal()`. Backend `snoozePercakapan()` untouched (spec "Ask first" on its contract).
- **Dead-Ends (Do NOT Repeat):**
  - `composer test` exits code 1 even when all tests pass — cause is the PHPUnit warning "XDEBUG_MODE=coverage has to be set", not a failure. Judge by the "Tests: N" line.
  - `catatanInternal()` reads `getPost('teks')` (form body), not JSON as spec 4.3 says; in FeatureTestTrait reset with `withBodyFormat('')` after a JSON call.
- **Next Action / Pending:**
  - `/sdlc-code-review` for TASK-012 (attach spec + plan).
  - Manual browser check of the Snooze dialog not done yet.
  - Remaining TASK-013 parts (SLA unit tests, `status`/`q` session tests) and plan marking of TASK-001..011 need their own verification task.

<!-- checkpoint-tail: M3 Fase 1 TASK-012 is done on branch feature/m3-operational-inbox-fase1a-task001 — the Follow-up presets open #modalSnooze with an optional Alasan saved as one Internal Note after a successful snooze (partial-failure warning toast, >4096-byte reason blocked before snooze); 301/301 tests pass; next is /sdlc-code-review and a manual browser check. -->

---

## 📝 Session Checkpoint: 2026-09-23 (Code review of M3 TASK-012, commit f0d6b94)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Review (M3 Fase 1b TASK-012) → done, verdict **Merge**
- **Active Artifacts:**
  - `spec/spec-design-m3-operational-inbox-fase1.md` — unchanged
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — unchanged by the review (TASK-012 ✅, TASK-013 Partial)
  - No refactoring plan created (no CRITICAL/REQUIRED findings).
- **Achieved Milestones:**
  - `/sdlc-code-review` of `f0d6b94` (done inline, not with 2 sub-agents, because the diff is small). Spec compliant: REQ-011/AC-007, partial-failure toast, CL-006 byte check before `/snooze`, and `snoozePercakapan()` unchanged (no diff in `app/Controllers`, `app/Config`, `app/Models`).
  - XSS: no new risk. The reason never goes into `showToast()` (which uses `innerHTML`), the duration label uses `textContent`, and the note is escaped in the thread by `escapeHtmlInbox()`.
- **Decisions Made:**
  - Findings are only NITs/FYIs, all optional:
    - NIT STD-01: the error text says "4096 karakter", but the limit is counted in bytes.
    - NIT SPEC-01: `maxlength="4096"` on `#snoozeAlasan` ([index.php:629]) cuts pasted text instead of rejecting it. Remedy: remove the attribute.
    - FYI (pre-existing, out of scope): `showToast()` uses `innerHTML`, and the CSRF filter is disabled globally.
- **Next Action / Pending:**
  - Manual browser check (user):
    - (1) no reason → only `/snooze` in Network;
    - (2) with a reason → an "Internal" note appears in the thread;
    - (3) an emoji-heavy reason over 4096 bytes → "terlalu panjang" toast and no `/snooze` call.
  - Optional: fix the 2 NITs via `/code-janitor`.
  - Remaining TASK-013 parts (SLA unit tests, `status`/`q` session tests) still open.

<!-- checkpoint-tail: The code review of M3 TASK-012 (commit f0d6b94) found only 2 optional NITs (byte-vs-karakter wording, maxlength truncation) and no XSS or spec issues, with the verdict Merge; the pending work is a manual browser check of the Snooze dialog and the remaining TASK-013 tests. -->

---

## 📝 Session Checkpoint: 2026-09-23 (Manual browser check of M3 TASK-012 — passed)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Review → done for TASK-012; next is the TASK-013 verification
- **Active Artifacts:**
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — unchanged (TASK-012 ✅, TASK-013 Partial)
- **Achieved Milestones:**
  - The user ran the manual browser check of the Snooze dialog on 2026-09-23, and all 3 steps passed:
    - (1) No reason: Network shows only `snooze` with payload `{menit: 60}`, and no `catatan`.
    - (2) Reason "tes alasan snooze": exactly 1 new "Internal" bubble appears (by Anshar, 23/09 21.09), and the conversation moves to the Ditunda tab.
    - (3) Reason of 1100 emoji (4400 bytes, set via the Console): the warning toast "Alasan terlalu panjang" appears, the dialog stays open, and there is no `snooze` request.
  - Test data was restored (snooze cancelled).
- **Decisions Made:**
  - TASK-012 is fully accepted: code review verdict Merge plus the manual check. The 2 optional NITs (wording, `maxlength`) were left as they are.
- **Next Action / Pending:**
  - Finish TASK-013. The files already exist:
    - `tests/unit/InboxSlaServiceTest.php`
    - `tests/session/OperationalInboxConversationTest.php`
    - `app/Services/InboxSlaService.php`
  - Verify that these tests cover AC-005/AC-006/AC-008 and the `status`/`q`/`page` contract of spec 4.4 (CL-002, CL-003, CL-005, CL-007..CL-013). Fill only the gaps, run `composer test`, then mark TASK-013 in the plan.

<!-- checkpoint-tail: The TASK-012 snooze reason passed code review and a 3-step manual browser check (no reason → only /snooze; with a reason → 1 Internal note; a >4096-byte reason → blocked before /snooze), so the next task is finishing TASK-013 by checking the existing SLA and status/q tests against the spec. -->

---

## 📝 Session Checkpoint: 2026-09-23 (M3 TASK-013 verify — partial, 3 API rules BLOCKED)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Implementation (VERIFY) — TASK-013 Partial
- **Active Artifacts:**
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — TASK-013 row updated: Partial + BLOCKED + TODO (2026-09-23)
- **Achieved Milestones:**
  - Built a coverage table for SLA (AC-005/006/008, selesai, 15/60 boundaries) and spec 4.4 (CL-001..013). The user approved Option A: add tests only for the GAP rows that the current code passes.
  - Added 6 tests (commit `5c8ec9d`):
    - SLA: 20 minutes → kuning for belum_diambil/open/menunggu.
    - API: CL-003 (status AND q), CL-005 (200 []), CL-007 (spaces-only q), CL-008 (% and _ as plain text), CL-001 (q finds an old conversation behind 500 newer ones).
  - `composer test`: 307/307 (was 301). Exit code 1 comes only from the Xdebug coverage warning.
- **Decisions Made:**
  - No production code was changed. The rules that `apiConversations()` does not implement are BLOCKED, not faked with tests:
    - CL-002: invalid `status` → 400 (today it returns 200 with an empty list).
    - CL-009: `q` > 255 → 400 (no limit today).
    - CL-010..013: `page` (50 per page, 400 for invalid page, `[]` past the end). `page` is ignored today and all rows are returned.
  - Reason for not implementing paging now: the Inbox UI has no "load more", so paging would hide conversations after the first 50. It needs its own task that includes the UI.
- **Updated Files:**
  - `tests/unit/InboxSlaServiceTest.php` — +1 test
  - `tests/session/OperationalInboxConversationTest.php` — +5 tests, +`idsDari()` helper
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — TASK-013 row
- **Next Action / Pending:**
  - Implement the BLOCKED API rules (CL-002, CL-009, CL-010..013) together with UI paging. This is new scope beyond TASK-013, so it needs the user's go-ahead.
  - TODO: `q` search is case-sensitive (`str_contains` in `Inbox::apiConversations()`), while spec 4.4 expects case-insensitive matching.
  - After that: finish TASK-013, then TASK-014 (APPROVAL).

<!-- checkpoint-tail: TASK-013 is partial: 6 new tests (commit 5c8ec9d, 307/307) cover SLA 20-min kuning and CL-001/003/005/007/008, while CL-002, CL-009 and CL-010..013 are BLOCKED because apiConversations() has no status/q validation or paging yet, and q is still case-sensitive (TODO). -->

---

## 📝 Session Checkpoint: 2026-09-23 (M3 TASK-013 — BLOCKED API rules finished, b8fd05a)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Implementation (`/sdlc-write-code`) — TASK-013 done; next is code review, then TASK-014 (APPROVAL)
- **Active Artifacts:**
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — TASK-013 row: BLOCKED removed, done 2026-09-23
  - `spec/spec-design-m3-operational-inbox-fase1.md` — unchanged
- **Achieved Milestones:**
  - `Inbox::apiConversations()` now follows spec 4.4: it validates before reading the DB (400 for `status` outside the 5 values = CL-002, `q` > 255 chars after trim via `mb_strlen` = CL-009, `page` not matching `^[1-9]\d{0,8}$` = CL-012). After the filter it pages 50 at a time (`array_slice`, CL-010/011, default page 1), and a page past the end returns 200 `[]` (CL-013). `q` is case-insensitive (`mb_stripos`). New private constants `QUEUE_STATUSES` and `CONVERSATIONS_PER_PAGE`, plus a `badRequest()` helper.
  - Inbox screen: new JS helper `ambilSemuaConversation()` fetches `?page=1..N` until a page has < 50 rows, dedupes by id, and rejects the whole round if one page fails. Used by the 6 s polling (`muatUlangDaftarConversation`) and the "Chat Baru" reload. Tab counts, list and badge are unchanged (still counted from the full array). The SSR first paint (`index()`) reads the DB directly and is not paged.
  - +7 tests in `tests/session/OperationalInboxConversationTest.php` (Red confirmed: 6 failed first; the paging-after-filter test was already green as a guard). `composer test` → **Tests: 314**, 0 failures (exit 1 comes only from the Xdebug coverage warning).
  - JS verified without a browser: `node --check` on the extracted `<script>` (replace `<?= ... ?>` with the non-greedy perl `s/<\?=.*?\?>/0/g`, because a greedy sed breaks on `?` inside the tag) plus a fake-fetch simulation of the helper (120 rows → pages 1,2,3; duplicate dropped; failure → rejected).
- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** `sed -E "s/<\?= [^?]*\?>/0/g"` to strip PHP echo tags before running `node --check` on the view script.
  - **Reason:** some tags contain `??` (e.g. `$daftarKasir ?? []`), so `[^?]*` stops early and leaves `<?=` in the JS → false SyntaxError.
  - **Note:** use `perl -pe 's/<\?=.*?\?>/0/g'`.
- **Updated Files:**
  - `app/Controllers/Inbox.php` — validation + paging + case-insensitive `q` in `apiConversations()`
  - `app/Views/inbox/index.php` — `ambilSemuaConversation()` and its 2 callers
  - `tests/session/OperationalInboxConversationTest.php` — +7 tests, helpers `assertBadRequest()` and `seedBerurutan()`
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — TASK-013 row
- **Decisions Made:**
  - The user approved "the screen fetches every page and merges them" (no "load more" UI). Accepted cost: one refresh = ceil(N/50) requests, and each request recomputes the whole list on the server (same kind of risk as RISK-002). If it gets heavy, the fix is a "load more" UI as a separate task.
  - An empty `?page=` returns 400 (not a valid number). CL-012 does not name this case, so it is recorded in the plan.
  - KB fact "`apiConversations()` has no filter params / `findAll(100)`" is STALE: it now has `status`/`q`/`page` and uses `findAll()` without a limit. Fix it at the next compaction.
- **Next Action / Pending:**
  - Manual browser check: open `/inbox`, wait about 10 s, and confirm the tab counts do not shrink (the JS paging has no automated test).
  - `/sdlc-code-review` for commit `b8fd05a`, then TASK-014 (APPROVAL).

<!-- checkpoint-tail: TASK-013 finished in b8fd05a: apiConversations() validates status/q/page (400), pages 50 per page after filter, returns [] past the end, and q is case-insensitive; the Inbox screen merges all pages via ambilSemuaConversation(); composer test 314/314; next is a manual browser check plus /sdlc-code-review, then TASK-014. -->

---

## 📝 Session Checkpoint: 2026-09-23 (Code review of M3 TASK-013, commit b8fd05a — Fase 1 plan Completed)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Review → done. M3 Fase 1 plan is **Completed** (TASK-014 APPROVAL given by the user).
- **Active Artifacts:**
  - `spec/spec-design-m3-operational-inbox-fase1.md` — 4.4 `page` bullet: an empty `?page=` is invalid (400); only an absent `page` means page 1.
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — status `Completed`, TASK-014 ✅ 2026-09-23.
- **Achieved Milestones:**
  - `/sdlc-code-review` of `b8fd05a`: no CRITICAL/REQUIRED findings → clear to merge, no refactoring plan written. Test file `OperationalInboxConversationTest.php` 18/18 OK.
  - Verified: paging is applied after the filter (CL-001 kept), status comes only from `withComputedStatus()` (REQ-002 kept), CL-002/009..013 implemented.
  - Manual browser check of `/inbox` tab counts after paging: passed (user, 2026-09-23).
- **Decisions Made:**
  - SPEC-01 = Option A: keep 400 for an empty `?page=`; spec text fixed, no code change.
- **Open review findings (all OPTIONAL/NIT, not scheduled — candidates for `/code-janitor`):**
  - STD-01: `setInterval(muatUlangDaftarConversation, 6000)` has no in-flight guard; with ceil(N/50) sequential page requests, rounds can overlap and an older round can overwrite newer data (`app/Views/inbox/index.php:2122`).
  - STD-02: a conversation that jumps from a later page to page 1 mid-round is missed for that round (dedupe only handles duplicates); self-heals after 6 s.
  - STD-03: `ambilHalaman()` has no max-page cap (infinite loop if the server ever ignores `page`).
  - STD-04: `ORDER BY last_message_at DESC` has no tie-breaker; add `->orderBy('id','DESC')` for stable paging.
  - STD-05: page regex uses `$`, so `"1\n"` is accepted; use `\z` (proven with `preg_match`).
  - STD-06 (pre-existing): `?status[]=x` / `?q[]=x` → `(string)` array cast warning (likely 500, not verified in CI4); `q[]` searches the word "Array". Guard with `is_string()`.
  - STD-07 test gaps: JS helper untested; CL-009 not tested with multibyte chars (`strlen` would pass); no `?q=..&page=2` test; no test that empty `?status=` means no filter.
  - SPEC-02: page > 9 digits → 400 instead of CL-013 `[]` (NIT, accepted).
  - Doc drift: spec §9 / REQ-012 / TASK-011 still say `findAll(500)`; code uses unbounded `findAll()` (correct per CL-001). Spec 4.4 also has the `q` bullet duplicated on one line.
- **KB correction:** the KB fact "`apiConversations()` has no filter params and hardcodes `findAll(100)`" is STALE — it now accepts `status`/`q`/`page` (50 per page, 400 validation) and uses `findAll()` without a limit. Fix at the next compaction.
- **Updated Files:**
  - `spec/spec-design-m3-operational-inbox-fase1.md` — SPEC-01 sentence in 4.4
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — TASK-014 ✅, status Completed
- **Next Action / Pending:**
  - PR opened: https://github.com/tikusgot007/AuliaPos/pull/41 (base `v2.3`, head `feature/m3-operational-inbox-fase1a-task001`, 63 files; full suite `phpunit --no-coverage` OK 314 tests / 1048 assertions on 2026-09-23). Before merge: run `php spark migrate` on the server (AddIsInternalToMessages, CreateConversationHandoffs; both `inbox` group, additive).
  - Optional later: `/code-janitor` for STD-01/03/05/06.

<!-- checkpoint-tail: Code review of b8fd05a found no blocking issues; SPEC-01 kept 400 for empty ?page= (spec updated), manual browser check passed, TASK-014 approved and the M3 Fase 1 plan is Completed; PR #41 was merged into v2.3 (merge commit ce94660); the local checkout now runs v2.3 with 314 tests passing, and production deploy (git pull + php spark migrate) is still to do. -->

---

## 📝 Session Checkpoint: 2026-09-23 (M3 PR #41 merged into v2.3)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** M3 Fase 1 + Fase 2a merged. No active plan.
- **Achieved Milestones:**
  - PR https://github.com/tikusgot007/AuliaPos/pull/41 merged by the user with "Create a merge commit" → `origin/v2.3` at `ce94660`.
  - Local checkout `c:\xampp\htdocs\aulia` switched from `feature/m3-operational-inbox-fase1a-task001` to `v2.3` (fast-forward, identical content). `vendor/bin/phpunit --no-coverage` on v2.3: OK 314 tests / 1048 assertions.
  - Local DB already had both migrations (`AddIsInternalToMessages` 2026-09-22, `CreateConversationHandoffs` 2026-09-23) → nothing to migrate locally.
- **Decisions Made:**
  - The "server" is the local XAMPP on the dev PC (same folder as the repo); there is no production deploy yet.
  - This memory update was committed directly on `v2.3` with the user's approval (docs-only, no PR).
- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** advising "run `php spark migrate` on the server before merging".
  - **Reason:** migration files arrive with the code; migrate can only run after the code is on the server.
  - **Note:** correct order for a future production deploy = pull/deploy the code, then `php spark migrate` immediately.
- **Next Action / Pending:**
  - Production deploy of v2.3 when the user decides (git pull + `php spark migrate` right after).
  - Optional: `/code-janitor` for the open NIT/OPTIONAL findings STD-01..07 (see the previous checkpoint).
  - ~~Feature branch `feature/m3-operational-inbox-fase1a-task001` can be deleted once the user is happy with v2.3.~~ Done 2026-09-24: deleted locally and on GitHub after confirming 0 unmerged commits vs `origin/v2.3`.

<!-- checkpoint-tail: PR #41 (M3 Fase 1 + Fase 2a) is merged into v2.3 at ce94660; the local XAMPP checkout now runs v2.3 with 314 tests passing and both migrations already applied; production deploy and the optional STD-01..07 cleanup remain. -->

---

## 📝 Session Checkpoint: 2026-09-24 (M3 Fase 1 consistency audit)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Checkpoint: Consistency (post-merge audit of M3 Fase 1 on `v2.3`)
- **Active Artifacts:**
  - `prd-20260922-0141-chat-whatsapp-inbox.md` v1.1 — §9.2 status is stale (says Fase 1a/1b "Kode belum dimulai", Fase 2a "Spec belum dibuat")
  - `spec/spec-design-m3-operational-inbox-fase1.md` v1.0 — needs fixes (CT-02, CT-03, no AC for REQ-012)
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — says `Completed` but is NOT complete (Readiness Score 66/100, Critical Flaw Veto)
  - `plan/plan-feature-m3-operational-inbox-fase2a-v1.0.md` (Planned, 0/14 ticked) and `plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md` (Planned, 1/15 ticked, only the VOID TASK-203) — both stale; the code is already merged
- **Achieved Milestones:**
  - Consistency audit of M3 Fase 1 done: 66/100, below the 80 gate.
  - Confirmed in code: the API has Internal Note POST, `sla_color` and `?q=` search, but `app/Views/inbox/index.php` has (MC-01) no standalone Internal Note input (only via the Snooze reason), (MC-02) no `sla_color` rendering, and (MC-03) no search box. Root cause: the Fase 1 plan never had UI tasks for these; FILE-007 lists them, but no TASK owns them.
  - Contradictions: CT-01 plan `Completed` while TASK-001..011 are unticked; CT-02 Spec §9 / Plan TASK-011 / RISK-002 still say `findAll(500)`, but the code uses `findAll()` with no limit (follows CL-001); CT-03 Spec §4.3 says a JSON body and `{message_id}`, but the code reads form `teks` and returns `{conversation_id, message}`.
- **Updated Files:**
  - `docs/audit/consistency-audit-m3-fase1-operational-inbox-2026-09-24.md` — new audit report (Iteration 1)
- **Decisions Made:**
  - None by the user yet; the audit recommends fixing the Plan first.
- **Next Action / Pending:**
  - `/sdlc-plan-tasks` to revise the Fase 1 plan: add UI tasks for MC-01..03 + a VERIFY task, tick TASK-001..011 with evidence, set status back to In Progress, fix the `findAll(500)` text.
  - Then `/sdlc-define-specs` (CT-02, CT-03, REQ-012 AC), `/sdlc-draft-prd` (§9.2), sync both Fase 2a plans with the code, and after that `/sdlc-write-code` for the 3 UI pieces.

<!-- checkpoint-tail: Audit 2026-09-24 scored M3 Fase 1 at 66/100 because the Internal Note input, SLA colors and search exist in the API but not on the Inbox screen, and the plan is wrongly marked Completed; next step is /sdlc-plan-tasks to add the missing UI tasks. -->

---

## 📝 Session Checkpoint: 2026-09-24 (M3 Fase 1 plan rev 1.1, audit remediation)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Plan (post-audit remediation). Next: Spec fix.
- **Active Artifacts:**
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — rev 1.1, Status: 🔄 In progress (Phase 3 open). Projected Readiness Score 78/100 (whole audit, not only the plan).
  - `spec/spec-design-m3-operational-inbox-fase1.md` v1.0 — still needs CT-02 (§9 `findAll(500)`), CT-03 (§4.3 real contract: form field `teks`, response `{conversation_id, message}`, 4096 limit), AC for REQ-012 + screen-level ACs, duplicate `q` bullet in §4.4.
  - `prd-20260922-0141-chat-whatsapp-inbox.md` v1.1 — §9.2 still stale.
  - Both Fase 2a plans — still stale (ST-01/ST-02).
- **Achieved Milestones:**
  - Plan rev 1.1: TASK-001..011 ticked with file/line/commit evidence (all implemented 2026-09-22). TASK-006 marked "implied only" (no written Phase 1 approval found). TASK-009 notes AC-003 is tested only indirectly (via `last_message_direction` unchanged).
  - New Phase 3 (Fase 1c, screen only, all in `app/Views/inbox/index.php`): TASK-015 Internal Note button + `#modalCatatanInternal` (all staff, all statuses, reuse `simpanAlasanSnooze()` fetch), TASK-016 SLA Timer dot from `c.sla_color` (null/missing = no dot), TASK-017 search box `#inputCariConversation` sending `q` through `ambilSemuaConversation()`, TASK-018 VERIFY (new `tests/session/OperationalInboxScreenTest.php` + manual browser checklist + `composer test`), TASK-019 APPROVAL.
  - `findAll(500)` text removed from TASK-011, TASK-013, ASSUMPTION-001, RISK-002, TEST-003 (CT-02, plan side).
  - Audit report got a "PARTIALLY RESOLVED (Plan scope only)" block.
- **Decisions Made:**
  - Search goes to the server via `q` (not client-only filtering) so the search rules stay in one place (ALT-004).
  - SLA dot may appear only after the first 6-second refresh, because `index()` sends no `sla_color`; accepted, no backend change (ALT-005).
- **Updated Files:**
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — rev 1.1
  - `docs/audit/consistency-audit-m3-fase1-operational-inbox-2026-09-24.md` — remediation status block
- **Next Action / Pending:**
  - `/sdlc-define-specs` in a new session: CT-02, CT-03, REQ-012 AC + screen ACs, §4.4 duplicate (optional backlog: §1.1, §6, §13).
  - Then `/sdlc-draft-prd` (§9.2), sync the two Fase 2a plans, re-run `/sdlc-audit-consistency`, then `/sdlc-write-code` for TASK-015..019.
  - Tooling note: `python` is not installed on this PC; use `/c/xampp/php/php.exe` for helper scripts.

<!-- checkpoint-tail: Fase 1 plan rev 1.1 adds screen tasks TASK-015..019 (Internal Note input, SLA dot, search box) and ticks TASK-001..011 with evidence; projected score 78/100, next step is /sdlc-define-specs to fix the spec (CT-02, CT-03, REQ-012 AC). -->

---

## 📝 Session Checkpoint: 2026-09-24 (M3 Fase 1 spec rev 1.1, audit remediation)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Specification (post-audit remediation) done. Next: Code (plan Phase 3 / Fase 1c).
- **Active Artifacts:**
  - `spec/spec-design-m3-operational-inbox-fase1.md` — rev 1.1, Status: ✅ remediated. Projected Readiness Score 86/100 (whole audit).
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — rev 1.1, Status: 🔄 In progress (TASK-015..019 open).
  - `prd-20260922-0141-chat-whatsapp-inbox.md` v1.1 — §9.2 still stale (ST-03).
  - Both Fase 2a plans — still stale (ST-01/ST-02).
- **Achieved Milestones:**
  - CT-02: spec §9 no longer says `findAll(500)`; contract = no row limit, 50 per page via `page`.
  - CT-03: spec §4.3 + §8 match `Inbox::catatanInternal()`: form field `teks` (`getPost`), response `{ status, conversation_id, message }`, 400 for empty text or > 4096 bytes (`strlen`, bytes not characters).
  - New AC-009 (REQ-012 API: status + q + page, old conversation found, empty = 200 `conversations: []`, 400 cases), AC-010 (GH-002 Internal Note button), AC-011 (GH-004 SLA dot), AC-012 (Layar 7 search box) — matching plan TASK-015/016/017.
  - §4.4: duplicate `q` bullet removed; response shape `{ status, conversations: [...] }` stated explicitly.
  - Backlog done: §1.1/§2 Fase 2 wording now points to PRD K-01; §6 drops the non-existent `is_internal` query test and adds the page-render test + manual browser checklist; AC-007/AC-008 order fixed; §13 maps every REQ to AC-001..AC-012.
  - Audit report got a "RESOLVED for Plan + Spec scope" block (86/100).
- **Decisions Made:**
  - Screen ACs (AC-010..012) pass only when the page-render test passes AND the manual browser checklist is recorded (no JS test runner).
- **Updated Files:**
  - `spec/spec-design-m3-operational-inbox-fase1.md` — rev 1.1
  - `docs/audit/consistency-audit-m3-fase1-operational-inbox-2026-09-24.md` — remediation status block
- **Next Action / Pending:**
  - `/sdlc-write-code` in a new session for plan Phase 3 (TASK-015..019), attach plan + spec.
  - Small plan fix: TASK-017 still says "REQ-012 has no spec AC yet" → can point to AC-009/AC-012.
  - Later: `/sdlc-draft-prd` (§9.2), sync the two Fase 2a plans, then `/sdlc-audit-consistency` to re-check MC-01..03.

<!-- checkpoint-tail: Spec M3 Fase 1 rev 1.1 fixed CT-02/CT-03 and added AC-009..AC-012 (API search + screen ACs), projected 86/100; next step is /sdlc-write-code for plan Phase 3 TASK-015..019 (Internal Note button, SLA dot, search box). -->

---

## 📝 Session Checkpoint: 2026-09-24 (M3 Fase 1c code, plan Phase 3 done)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Code done for plan Phase 3 (Fase 1c), approved by the user (TASK-019). Next: Review.
- **Active Artifacts:**
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — rev 1.1, Status: ✅ Completed (TASK-001..019 ticked).
  - `spec/spec-design-m3-operational-inbox-fase1.md` — rev 1.1, unchanged this session.
  - `prd-20260922-0141-chat-whatsapp-inbox.md` v1.1 — §9.2 still stale (ST-03).
  - Both Fase 2a plans — still stale (ST-01/ST-02).
- **Achieved Milestones:**
  - TASK-015: "Catatan Internal" button in `renderThreadHeader()` (no ownership/status gate) + `#modalCatatanInternal`; note fetch extracted to `kirimCatatanInternal()`, shared with `simpanAlasanSnooze()`.
  - TASK-016: SLA dot `renderTitikSla(c.sla_color)`, server value only; null/unknown = no dot.
  - TASK-017: `#inputCariConversation` (maxlength 255) + ✕; `kataKunciAktif` sent as `&q=` on every page request incl. polling; stale-keyword results dropped; failed search = one toast + previous keyword kept; `conversationAktifSaatIni()` keeps the open conversation usable while search hides it.
  - TASK-018: `tests/session/OperationalInboxScreenTest.php` (3 tests); full suite 317/317; manual browser check 8/8 by the user.
  - Commits on `v2.3` (pushed): `f3bd8fa` (feature), `70726c1` (plan closure).
- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** treating the manual-test "search fail" as a screen bug.
  - **Reason:** the server `q` matches only `contact_name`/`phone` (spec 4.4); the dummy conversation's name came from `whatsapp_name`. After saving the contact it was found. Not a code bug → TODO-SEARCH-01.
- **Updated Files:**
  - `app/Views/inbox/index.php` — Internal Note button/modal, SLA dot, search box
  - `tests/session/OperationalInboxScreenTest.php` — new page-render test
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — TASK-015..019 ticked, TASK-017 AC Ref = AC-009, AC-012, status Completed
- **Decisions Made:**
  - Search stays within spec 4.4 for Fase 1c; wider search is TODO-SEARCH-01 via `/sdlc-define-specs` (name/number first, message-content search second — content search needs spec decisions: include Internal Notes?, index/FULLTEXT + migration?, show matching snippet?).
  - `composer test` exits 1 only because of the Xdebug coverage-mode runner warning (`phpunit.dist.xml` has `<coverage>` + `failOnWarning`); `vendor/bin/phpunit --no-coverage` is the clean gate (exit 0).
- **Next Action / Pending:**
  - `/sdlc-code-review` of `f3bd8fa` (attach spec + plan).
  - `/sdlc-audit-consistency` to re-check MC-01..03.
  - `/sdlc-define-specs` for TODO-SEARCH-01.
  - Backlog: plan Phase 3 NOTE still says "spec has no screen-level AC for REQ-012 yet" (outdated); `/sdlc-draft-prd` §9.2; sync the two Fase 2a plans.

<!-- checkpoint-tail: M3 Fase 1c screen (Internal Note button, SLA dot, search box) done and approved, commits f3bd8fa + 70726c1 on v2.3, 317/317 tests + 8/8 manual; next is /sdlc-code-review, then /sdlc-audit-consistency, then /sdlc-define-specs for TODO-SEARCH-01 (search whatsapp_name/manual_phone + message content). -->

---

## 📝 Session Checkpoint: 2026-09-24 (code review of f3bd8fa, M3 Fase 1c)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Review done → refactoring plan written, waiting for user approval, then `/sdlc-write-code`.
- **Active Artifacts:**
  - `plan/plan-refactor-m3-fase1c-inbox-screen-v1.0.md` — NEW, Status: Planned (1 phase, TASK-101..105).
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — rev 1.1, ✅ Completed (unchanged).
  - `spec/spec-design-m3-operational-inbox-fase1.md` — rev 1.1 (unchanged).
- **Achieved Milestones:**
  - Two-axis `/sdlc-code-review` of `f3bd8fa`. Spec axis: AC-010 (a–e), AC-011 (a–c), AC-012 (a–g), §4.3, §4.4 all met; no scope creep. Security: no new XSS/secret issue.
  - 2 REQUIRED (Standards axis), both verified in code:
    - STD-01: reopening `#modalCatatanInternal` while a save is in flight resets `catatanInternalSedangKirim` → double note possible, and the first success hides the reopened dialog / clears new text (`index.php:1275-1281`, `1308-1322`).
    - STD-02: vacuous asserts in `OperationalInboxScreenTest.php` — `'/catatan'` and `bg-success/warning/danger` already existed before `f3bd8fa`; `bukaModalCatatanInternal()` also matches the function definition.
- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** asserting generic strings (CSS classes, URL fragments) in page-render tests.
  - **Reason:** they already exist elsewhere in the view, so the test passes without the feature. Assert only strings introduced by the feature (element ids, `onclick="..."`, new CSS class/const names).
- **Updated Files:**
  - `plan/plan-refactor-m3-fase1c-inbox-screen-v1.0.md` — new refactoring plan for STD-01/STD-02
- **Decisions Made:**
  - Minimal plan: only the 2 REQUIRED items. NIT/FYI deferred as TODOs (plan §3): restore search input value on failed search; rename `SNOOZE_ALASAN_MAKS_BYTE`; "karakter" vs byte wording; `maxlength="4096"` silently truncates long pastes; CSRF off app-wide (`Filters.php:67`); `showToast` uses innerHTML (`layout/main.php:1288`); wrap `setInterval` polling callback.
- **Next Action / Pending:**
  - User approves the plan → `/sdlc-write-code` for `plan-refactor-m3-fase1c-inbox-screen-v1.0.md` (TASK-101..105, incl. manual close-and-reopen browser check).
  - Then `/sdlc-audit-consistency` (MC-01..03), then `/sdlc-define-specs` for TODO-SEARCH-01.

<!-- checkpoint-tail: Code review of f3bd8fa found spec fully met but 2 REQUIRED standards issues (note dialog in-flight guard reset, vacuous render-test asserts); plan-refactor-m3-fase1c-inbox-screen-v1.0.md written; next is /sdlc-write-code for it. -->

---

## 📝 Session Checkpoint: 2026-09-24 (refactor STD-01/STD-02 executed, M3 Fase 1c)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Implementation done → next is Review of the fix commit.
- **Active Artifacts:**
  - `plan/plan-refactor-m3-fase1c-inbox-screen-v1.0.md` — ✅ Completed (TASK-101..105 ticked, 2026-09-24).
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — rev 1.1, ✅ Completed (unchanged).
  - `spec/spec-design-m3-operational-inbox-fase1.md` — rev 1.1 (unchanged).
- **Achieved Milestones:**
  - STD-01 fixed: `bukaModalCatatanInternal()` only calls `.show()` while `catatanInternalSedangKirim` is true; success branch of `simpanCatatanInternal()` hides/clears the dialog only if `textarea.value.trim() === teks`.
  - STD-02 fixed: screen test now asserts `id="btnSimpanCatatanInternal"`, `onclick="bukaModalCatatanInternal()"`, `const SLA_WARNA = {`, `.inbox-sla-dot {`, `class="inbox-sla-dot ` (all verified absent in `f3bd8fa^`). Red/Green micro-test done (removed onclick → 1 failure → restored → green).
  - Gate: screen test 3/13, full `vendor/bin/phpunit --no-coverage` 317/317 (1061 assertions), `node --check` on the view's JS OK, user's manual close-and-reopen browser check passed.
  - Commits pushed to `origin/v2.3`: `dd9e864` (fix), `5cfd3ba` (plan closed).
- **Dead-Ends (Do NOT Repeat):**
  - None new. (Generic-string render asserts: see previous checkpoint.)
- **Updated Files:**
  - `app/Views/inbox/index.php` — in-flight guard on reopen + conditional close on success
  - `tests/session/OperationalInboxScreenTest.php` — feature-specific asserts
  - `plan/plan-refactor-m3-fase1c-inbox-screen-v1.0.md` — ticked, status Completed
- **Decisions Made:**
  - Deferred NIT/FYI items from plan §3 stay TODOs (not fixed): restore search input on failed search; rename `SNOOZE_ALASAN_MAKS_BYTE`; "karakter" vs byte wording; `maxlength="4096"` truncation; CSRF off app-wide; `showToast` innerHTML; wrap `setInterval` polling callback.
  - Tip for JS syntax check of a PHP view: extract `<script>` blocks, strip `<?= ... ?>` with non-greedy perl, then `node --check`.
- **Next Action / Pending:**
  - `/sdlc-code-review` of `dd9e864` (attach spec + refactor plan).
  - Then `/sdlc-audit-consistency` (MC-01..03), then `/sdlc-define-specs` for TODO-SEARCH-01.
  - Side task (outside AuliaPos): user is moving WA Gateway (`C:\projects\wa-gateway`, clean and in sync with `origin/master` at `21a4cb6`) to another computer via `git clone` + `npm install` + manual copy of `.env`; do not copy `auth/` (scan new QR) and stop the gateway here first (same WA session on two gateways conflicts). Pending: check what `data/` (3.8 MB) holds — if it is an unsent-message queue it must be moved too. User will ask when at this computer.

<!-- checkpoint-tail: Refactor plan for f3bd8fa done (dd9e864 fix + 5cfd3ba plan close, 317/317, manual pass, pushed to v2.3); next is /sdlc-code-review of dd9e864; side task: check wa-gateway data/ before moving the gateway. -->

---

## 📝 Session Checkpoint: 2026-09-24 (code review of dd9e864, M3 Fase 1c fix)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Review done (verdict: Merge) → next is Consistency Check.
- **Active Artifacts:**
  - `plan/plan-refactor-m3-fase1c-inbox-screen-v1.0.md` — ✅ Completed (no new refactor plan needed).
  - `spec/spec-design-m3-operational-inbox-fase1.md` — rev 1.1 (unchanged).
  - `docs/audit/consistency-audit-m3-fase1-operational-inbox-2026-09-24.md` — MC-01..03 still marked open "until TASK-015..017 are coded"; they are now coded and reviewed.
- **Achieved Milestones:**
  - `/sdlc-code-review` of `dd9e864`: STD-01 and STD-02 confirmed closed. 0 CRITICAL/REQUIRED, 0 spec issues. Screen test re-run 3/3 (13 assertions).
  - STD-01 traced for 5 cases (reopen in flight, edit after reopen, close without reopen, failure, no `hidden.bs.modal` reset) — all OK.
  - STD-02: all 8 asserted strings have count 0 in `f3bd8fa~1` and 1 now.
- **Dead-Ends (Do NOT Repeat):**
  - None new.
- **Updated Files:**
  - `.claude/instructions/memory.instructions.md` — this checkpoint only.
- **Decisions Made:**
  - New TODOs (not fixed, low priority):
    - [NIT] STD-03: `catatanInternalSedangKirim = false;` at `app/Views/inbox/index.php:1289` is now dead (always false there). Remove when the file is touched again.
    - [FYI] STD-04: while a note for conversation A is saving, switching to B and opening the dialog shows A's text with Save disabled until the save finishes. No wrong data; cosmetic only.
- **Next Action / Pending:**
  - `/sdlc-audit-consistency` for M3 Fase 1 (PRD + spec rev 1.1 + plan) to re-check and close MC-01..03 (and the TASK-017 wording "REQ-012 has no spec AC yet").
  - Then `/sdlc-define-specs` for TODO-SEARCH-01.
  - Side task still pending: check WA Gateway `data/` before moving the gateway (see previous checkpoint).

<!-- checkpoint-tail: Review of dd9e864 passed (STD-01/02 closed, verdict Merge, NIT STD-03 + FYI STD-04 as TODOs); next is /sdlc-audit-consistency for M3 Fase 1 to close MC-01..03. -->

---

## 📝 Session Checkpoint: 2026-09-24 (consistency re-audit M3 Fase 1, Iteration 2)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Consistency Check done (89/100, Good Enough) → user chose **REFINE** (docs cleanup before moving on).
- **Active Artifacts:**
  - `prd-20260922-0141-chat-whatsapp-inbox.md` — v1.1, unchanged; §9.2 stale (ST-03), GH-001..004 checkboxes still `[ ]`.
  - `spec/spec-design-m3-operational-inbox-fase1.md` — rev 1.1, matches code (CT-02/CT-03 closed).
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — rev 1.1, Completed; TASK-017 row fixed (AC-009/AC-012).
  - `docs/audit/consistency-audit-m3-fase1-operational-inbox-2026-09-24.md` — updated in place: Iteration 2 on top (89/100: C 37, Cl 26, A 26, no veto), Iteration 1 kept below as history, REFINE decision + order recorded.
- **Achieved Milestones:**
  - MC-01..03, CT-01..03 and the Spec AC gap verified closed against code (`index.php`, `Inbox.php:939-980`), review of `dd9e864` (Merge), 317/317, manual 8/8.
  - Artifact "Peta Kemajuan AuliaPos Inbox" (https://claude.ai/artifact/BYaaWGszt8jtX7eotmFLC6) updated to Version 2: Fase 1b done, new Fase 1c block, REFINE next steps.
- **Dead-Ends (Do NOT Repeat):**
  - None.
- **Updated Files:**
  - `docs/audit/consistency-audit-m3-fase1-operational-inbox-2026-09-24.md` — Iteration 2 + REFINE decision.
- **Decisions Made:**
  - REFINE order: (1) `/sdlc-plan-tasks` NG-02 (stale Phase 3 NOTE, plan line 77) + NG-03 (add AC-010 to TASK-015, AC-011 to TASK-016, AC-010..012 in TASK-018); (2) `/sdlc-draft-prd` ST-03 §9.2 + tick GH-001..004 + terms "Internal Note"/"SLA Timer"; (3) `/sdlc-define-specs` NG-01 = TODO-SEARCH-01 (search `whatsapp_name`/`manual_phone`?), NG-04 (literal `\n` in §1.2 line 40), NG-05 ("4096 karakter" vs bytes); (4) final `/sdlc-audit-consistency`.
  - ST-01/ST-02 (both Fase 2a plans still `Planned`) = separate `/sdlc-plan-tasks` session, outside Fase 1.
- **Next Action / Pending:**
  - REFINE step 1: `/sdlc-plan-tasks` for NG-02/NG-03 (editorial only, keep status Completed).
  - Still pending from earlier: NIT STD-03, FYI STD-04; check WA Gateway `data/` before moving the gateway.

<!-- checkpoint-tail: M3 Fase 1 re-audit Iteration 2 = 89/100 (MC-01..03 closed), user chose REFINE; next is /sdlc-plan-tasks for NG-02/NG-03, then PRD (ST-03), then spec (TODO-SEARCH-01, NG-04/05), then final audit. -->

---

## 📝 Session Checkpoint: 2026-09-24 (REFINE step 1: plan NG-02/NG-03)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Plan remediation done (REFINE step 1 of 4) → next is REFINE step 2 (`/sdlc-draft-prd`).
- **Active Artifacts:**
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — rev 1.1, still `Completed`; editorial fix only.
  - `docs/audit/consistency-audit-m3-fase1-operational-inbox-2026-09-24.md` — new `REMEDIATION STATUS: PARTIALLY RESOLVED (Plan scope, REFINE step 1)` block under the Iteration 2 H1; projected 91/100 (C 37, Cl 28, A 26).
- **Achieved Milestones:**
  - NG-02: Phase 3 NOTE now says "Screen ACs: Spec AC-010..AC-012".
  - NG-03: TASK-015 AC Ref + AC-010; TASK-016 AC Ref + AC-011; TASK-018 "**VERIFY** (Spec AC-010..AC-012)".
  - Verified: stale sentence count 0, TASK-015..019 rows keep the same column count, status unchanged.
- **Dead-Ends (Do NOT Repeat):**
  - None.
- **Updated Files:**
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — NOTE + 3 task cells.
  - `docs/audit/consistency-audit-m3-fase1-operational-inbox-2026-09-24.md` — remediation block.
- **Decisions Made:**
  - None new (followed the recorded REFINE order).
- **Next Action / Pending:**
  - REFINE step 2: `/sdlc-draft-prd` for ST-03 (§9.2), tick GH-001..004, terms "Internal Note"/"SLA Timer".
  - Then step 3 `/sdlc-define-specs` (NG-01 = TODO-SEARCH-01, NG-04, NG-05), step 4 final `/sdlc-audit-consistency`.
  - Still pending: NIT STD-03, FYI STD-04; check WA Gateway `data/` before moving the gateway; ST-01/ST-02 separate session.

<!-- checkpoint-tail: REFINE step 1 done (plan NG-02/NG-03 fixed, projected 91/100); next is /sdlc-draft-prd for ST-03 + GH-001..004 + glossary terms. -->

---

## 📝 Session Checkpoint: 2026-09-24 (REFINE step 2: PRD v1.2 + v1.3 full search)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** PRD remediation done (REFINE step 2 of 4) + new PRD requirement (full search) → next is REFINE step 3 (`/sdlc-define-specs`) and a separate `/sdlc-plan-tasks` for ST-01/ST-02.
- **Active Artifacts:**
  - `prd-20260922-0141-chat-whatsapp-inbox.md` — v1.3.
  - `docs/audit/consistency-audit-m3-fase1-operational-inbox-2026-09-24.md` — new `PARTIALLY RESOLVED (PRD scope, REFINE step 2)` block; projected 94/100 (C 38, Cl 28, A 28).
- **Achieved Milestones:**
  - PRD v1.2: §9.2 synced (Fase 1a/1b merged via PR #41; new Fase 1c row; Fase 2a Spec v1.2 + code + review done, plan status sync pending). GH-001..004 ticked with evidence note. Terms "catatan internal" → Internal Note, "indikator/warna prioritas" → SLA Timer.
  - PRD v1.3: new GH-009 (Fase 1d: search every name/number shown in the list incl. WhatsApp profile name, closes NG-01/TODO-SEARCH-01) and GH-010 (Fase 1e: search message text: customer, staff, Internal Note; matching snippet under the name). Updated §2.2, §2.3, §4, §5.2, §5.3, §8.3, §9.2, §10.
- **Dead-Ends (Do NOT Repeat):**
  - None.
- **Updated Files:**
  - `prd-20260922-0141-chat-whatsapp-inbox.md` — v1.2 + v1.3.
  - `docs/audit/consistency-audit-m3-fase1-operational-inbox-2026-09-24.md` — remediation block + NG-01 decision.
- **Decisions Made:**
  - Owner use case: order done, nota says "Saerah" (named in chat), saved contact is "Jamet" (her employee) → search must cover message text.
  - Q2 A: all names/numbers shown in the list are searchable. Q3 A: customer + staff + Internal Note messages searchable. Q4 A: snippet under the name; Internal Note snippet labelled "Internal"; one row per conversation, newest matching snippet.
  - Deferred (PRD §2.3): Opsi B jump-to-message (owner: "cukup A dulu"); search by POS nota/transaction data → M4.
  - GH-010 performance target ≤ 3 detik (owner did not object; can be revised in Spec/clarify).
  - Order: Fase 1d first (small, Spec only), then Fase 1e (run `/sdlc-clarify-reqs` on GH-010 before its Spec).
- **Next Action / Pending:**
  - Session A: `/sdlc-define-specs` — GH-009 contract (NG-01), NG-04 (literal `\n` §1.2), NG-05 ("4096 byte").
  - Session B: `/sdlc-plan-tasks` — ST-01/ST-02 sync both Fase 2a plans with merged code.
  - Later: `/sdlc-clarify-reqs` GH-010 (Fase 1e); final `/sdlc-audit-consistency`.
  - Still pending: NIT STD-03, FYI STD-04; check WA Gateway `data/` before moving the gateway.

<!-- checkpoint-tail: PRD v1.3 done (status synced, GH-001..004 ticked, terms aligned, new GH-009 name/number search + GH-010 message-text search with snippet); next: /sdlc-define-specs for GH-009 + NG-04/05, and /sdlc-plan-tasks for ST-01/ST-02. -->

---

## 📝 Session Checkpoint: 2026-09-24 (REFINE step 3: Spec rev 1.2, Fase 1d search contract)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Spec remediation done (REFINE step 3 of 4). Next: `/sdlc-plan-tasks` for Fase 1d.
- **Active Artifacts:**
  - `spec/spec-design-m3-operational-inbox-fase1.md` — rev 1.2 (not committed yet).
  - `docs/audit/consistency-audit-m3-fase1-operational-inbox-2026-09-24.md` — new `RESOLVED for Spec scope (REFINE step 3)` block; projected 95/100 (C 38, Cl 29, A 28).
  - `prd-20260922-0141-chat-whatsapp-inbox.md` — v1.3, unchanged this session.
- **Achieved Milestones:**
  - NG-01 / TODO-SEARCH-01 closed at Spec level: Fase 1d contract for GH-009 (CL-015, REQ-013, CON-003, §4.4, AC-013 a–h, §6, §9, §12, §13, §15).
  - NG-04: literal `\n` in §1.2 replaced with real line breaks.
  - NG-05: "4096 byte" in CL-006, §8 sample, §12.
- **Dead-Ends (Do NOT Repeat):**
  - None.
- **Updated Files:**
  - `spec/spec-design-m3-operational-inbox-fase1.md` — rev 1.2.
  - `docs/audit/consistency-audit-m3-fase1-operational-inbox-2026-09-24.md` — remediation block.
- **Decisions Made:**
  - `q` matches 5 columns, ALWAYS all five regardless of which one is displayed: `contact_name`, `whatsapp_name`, `phone`, `manual_phone`, `chat_id` (`chat_id` included because the list shows it as the name fallback, `index.php:887`).
  - Matching is per column (no concatenated "name + number" match), case-insensitive, raw (no phone normalization), NULL columns = no match, no error.
  - Fase 1d = no migration, no new param, no screen change: only the `q` predicate in `Inbox::apiConversations()` (currently `mb_stripos` filter-after-fetch on `contact_name`/`phone`, `Inbox.php:124-130`) + tests in the existing `apiConversations` session test.
  - Accepted edges: old `whatsapp_name` not searchable after the customer renames; broad `q` like "lid" matches many `chat_id`s.
  - GH-010 (Fase 1e) explicitly out of scope in Spec §1.1 until a later revision.
- **Next Action / Pending:**
  - Commit Spec rev 1.2 + audit block (user has not asked yet).
  - `/sdlc-plan-tasks`: add Fase 1d task(s) for REQ-013/AC-013 to `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` (also update TASK-018's TODO-SEARCH-01 note).
  - Code-review TODO: "4096 karakter" wording still in `Inbox.php:951` and `index.php:1308`.
  - Separate: `/sdlc-plan-tasks` ST-01/ST-02 (Fase 2a plans); later `/sdlc-clarify-reqs` GH-010 (Fase 1e); final `/sdlc-audit-consistency`.
  - Still pending: NIT STD-03, FYI STD-04; check WA Gateway `data/` before moving the gateway.

<!-- checkpoint-tail: Spec rev 1.2 done (Fase 1d: q searches contact_name/whatsapp_name/phone/manual_phone/chat_id per column, AC-013; NG-04/NG-05 fixed; projected 95/100); next: /sdlc-plan-tasks for Fase 1d, then code. -->

---

## 📝 Session Checkpoint: 2026-09-24 (Plan: Fase 1d Phase 4 + Fase 2a plan status sync)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Planning (`/sdlc-plan-tasks`) — done. Next is Implementation (`/sdlc-write-code`) of Fase 1d.
- **Active Artifacts:**
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — rev 1.2, status `In progress` (Phase 4 = Fase 1d, TASK-020..022 open).
  - `plan/plan-feature-m3-operational-inbox-fase2a-v1.0.md` — rev 1.1, status `Completed` (ST-01 closed).
  - `plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md` — rev 1.1, status `Completed` (ST-02 closed; TASK-203 stays VOID).
  - `docs/audit/consistency-audit-m3-fase1-operational-inbox-2026-09-24.md` — new REMEDIATION STATUS block, projected 95/100.
  - `spec/spec-design-m3-operational-inbox-fase1.md` rev 1.2 — unchanged, source of Phase 4.
- **Achieved Milestones:**
  - Phase 4 (Fase 1d) added: TASK-020 = Red tests AC-013 (a)–(g) in `tests/session/OperationalInboxConversationTest.php`, then widen the `q` filter in `Inbox::apiConversations()` (`Inbox.php:123-131`) from `contact_name`/`phone` to the five columns `contact_name`, `whatsapp_name`, `phone`, `manual_phone`, `chat_id` (per column `mb_stripos`, OR, NULL-safe, filter-after-fetch, no SQL WHERE). TASK-021 VERIFY = full suite + `git diff` shows no view/route/migration change (CON-003) + manual browser AC-013 (h). TASK-022 APPROVAL = status back to `Completed`, close TODO-SEARCH-01 in TASK-018.
  - Verified before planning: the screen does no client-side name filtering; it only sends `q` (`index.php:933`), so the change is server-only. List display fallback confirmed: name `contact_name → whatsapp_name → phone → chat_id`, number `manual_phone → phone`.
  - Fase 2a sync: 28 task rows ticked with evidence (code lines, commits `793dbe9`..`8f11e89` and `3136792`..`51fb1fc`, 2026-09-23 memory checkpoints). All Fase 2a commits are ancestors of `v2.3` (PR #41). Fixed the pre-existing MD012 at the end of the Fase 2a feature plan.
  - Suite re-run 2026-09-24: `vendor/bin/phpunit --no-coverage` OK **317 tests / 1061 assertions**.
- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** running `python` for a scripted plan edit. **Reason:** Python is not installed (Windows Store alias only). **Note:** use `php` scripts (scratchpad) for mechanical multi-row edits; plan files are CRLF.
  - **Attempted:** two `> [!NOTE]` callouts separated only by a blank line. **Reason:** markdownlint MD028. **Note:** put a paragraph between callouts.
- **Updated Files:**
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — rev 1.2: Phase 4, REQ-013/CON-003, ALT-006/007, FILE-002/008, TEST-006/008, RISK-004/005, related docs, rollback.
  - `plan/plan-feature-m3-operational-inbox-fase2a-v1.0.md` — rev 1.1 status sync.
  - `plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md` — rev 1.1 status sync.
  - `docs/audit/consistency-audit-m3-fase1-operational-inbox-2026-09-24.md` — remediation block.
- **Decisions Made:**
  - Fase 1d is one work task (XS/S, 2 files) + VERIFY + APPROVAL; user approved the breakdown ("lanjut").
  - Keep filter-after-fetch in PHP (ALT-006 SQL WHERE rejected); always search all five columns (ALT-007 rejected, CL-015).
  - Four approvals without a written record are marked "implied": Fase 1 TASK-006, Fase 2a TASK-008/TASK-011, refactor TASK-205.
- **Next Action / Pending:**
  - **NEW session:** `/sdlc-write-code` Phase 4 (TASK-020..022) of the Fase 1 plan.
  - `/sdlc-draft-prd`: PRD §9.2 status text is now behind (Fase 1d "Plan belum"; Fase 2a plan sync note).
  - Later: `/sdlc-define-specs` for Fase 1e (GH-010, message-text search); "karakter" wording in code stays a code-review TODO.

<!-- checkpoint-tail: Fase 1d is planned as Phase 4 of the M3 Fase 1 plan (TASK-020 five-column q predicate in Inbox::apiConversations() with Red AC-013 tests, TASK-021 VERIFY, TASK-022 APPROVAL; plan In progress), both Fase 2a plans are synced to Completed with evidence, suite 317/1061 green; next is /sdlc-write-code Phase 4 in a new session. -->

---

## 📝 Session Checkpoint: 2026-09-24 (Code: Fase 1d TASK-020 done, TASK-021 partial)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Implementation (`/sdlc-write-code`, Phase 4 of the M3 Fase 1 plan). Stopped at TASK-021 VERIFY as the user asked.
- **Active Artifacts:**
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — rev 1.2, `In progress`: TASK-020 ✅, TASK-021 partial ((a)+(b) done, (c) manual pending), TASK-022 open.
  - `spec/spec-design-m3-operational-inbox-fase1.md` rev 1.2 — unchanged.
- **Achieved Milestones:**
  - Commit `db7f301`: `Inbox::apiConversations()` matches `q` against `Inbox::SEARCH_COLUMNS` = `contact_name`, `whatsapp_name`, `phone`, `manual_phone`, `chat_id` (per column `mb_stripos`, OR, NULL-safe, still filter-after-fetch). Docblock updated.
  - 7 AC-013 (a)–(g) tests in `tests/session/OperationalInboxConversationTest.php`. Red confirmed before the change: (a)–(e) failed; (f), (g) already passed.
  - Suite: `vendor\bin\phpunit --no-coverage` exit 0, **324 tests / 1097 assertions** (`build\fase1d.txt`). Diff boundary OK (2 files only, CON-003).
- **Dead-Ends (Do NOT Repeat):**
  - **Attempted:** seeding search tests with the default random `chat_id` (`628` + 9 random digits). **Reason:** `chat_id` is now searched, so a number `q` can match it by chance (flaky test). **Note:** new Fase 1d tests use a fixed, digit-free `chat_id` via `seedIdentitas()`.
- **Updated Files:**
  - `app/Controllers/Inbox.php` — `SEARCH_COLUMNS` + five-column `q` filter.
  - `tests/session/OperationalInboxConversationTest.php` — AC-013 tests + `seedIdentitas()` helper.
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — TASK-020/021 evidence.
- **Decisions Made:**
  - Code commit kept separate from the docs/memory commit so TASK-021 (b) boundary (`db7f301` = 2 files) stays checkable.
- **Next Action / Pending:**
  - **User:** TASK-021 (c) manual browser check AC-013 (h): "budi cetak" finds (1) a conversation with no contact name and WhatsApp name "Budi Cetak", (2) one saved as "Jamet" with WhatsApp name "Budi Cetak"; tab counts follow. Then TASK-022 (plan status `Completed`, close TODO-SEARCH-01 in TASK-018) → `/sdlc-code-review` of `db7f301`.
  - TODO (small, separate): existing `testQFilterCocokContactNameDanPhone` searches `q=9999` with random `chat_id` → ~0.1% flaky since Fase 1d; give it fixed chat_ids.
  - Carried over: code-review TODO "4096 karakter" wording (`Inbox.php` `catatanInternal()`, `index.php`); `/sdlc-draft-prd` PRD §9.2 status text behind; `/sdlc-define-specs` Fase 1e (GH-010); NIT STD-03, FYI STD-04; check WA Gateway `data/` before moving the gateway.

<!-- checkpoint-tail: Fase 1d code is committed (db7f301, q searches five name/number columns per column, AC-013 tests, suite 324/1097 green); only the manual browser check TASK-021 (c) and approval TASK-022 remain, then /sdlc-code-review of db7f301. -->

---

## 📝 Session Checkpoint: 2026-09-24 (Janitor: flaky q=9999 test fixed)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Implementation (M3 Fase 1 plan, Phase 4). Ad-hoc `/code-janitor` fix in the same session (user override of the session lock).
- **Active Artifacts:**
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — rev 1.2, `In progress`: TASK-020 ✅, TASK-021 (c) manual browser check still pending, TASK-022 open. Unchanged by this fix.
- **Achieved Milestones:**
  - Commit `88cc7e0`: `testQFilterCocokContactNameDanPhone` now seeds via `seedIdentitas()` with fixed, digit-free chat_ids (`q-filter-nama@…`, `q-filter-nomor@…`). Closes the "~0.1% flaky q=9999" TODO from the previous checkpoint. `seedIdentitas()` docblock generalized (now used outside Fase 1d tests).
  - Suite: `vendor\bin\phpunit --no-coverage` exit 0, **324 tests / 1097 assertions** (`build\janitor-chatid.txt`).
- **Dead-Ends (Do NOT Repeat):**
  - None new. Rule kept: any `q` test that searches digits must use a fixed, digit-free `chat_id`; other tests in the file keep the random `chat_id` because they search no digits.
- **Updated Files:**
  - `tests/session/OperationalInboxConversationTest.php` — fixed chat_ids in one test + docblock.
- **Decisions Made:**
  - None beyond the fix.
- **Next Action / Pending:**
  - **User:** TASK-021 (c) manual browser check AC-013 (h), then `/sdlc-write-code` TASK-022 → `/sdlc-code-review` of `db7f301` (+ `88cc7e0`).
  - Carried over: "4096 karakter" → "4096 byte" wording (`Inbox.php` `catatanInternal()`, `index.php`); `/sdlc-draft-prd` PRD §9.2 status text; `/sdlc-define-specs` Fase 1e (GH-010); NIT STD-03, FYI STD-04; check WA Gateway `data/` before moving the gateway.

<!-- checkpoint-tail: Flaky q=9999 test fixed (88cc7e0, suite 324/1097 green); Fase 1d still waits only for the manual browser check TASK-021 (c) and approval TASK-022, then /sdlc-code-review of db7f301+88cc7e0. -->

---

## 📝 Session Checkpoint: 2026-09-24 (Janitor: "4096 byte" wording)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Implementation (M3 Fase 1 plan, Phase 4). Ad-hoc `/code-janitor` fix in a fresh session.
- **Active Artifacts:**
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — rev 1.2, `In progress`: TASK-021 (c) manual browser check pending, TASK-022 open. Unchanged by this fix.
- **Achieved Milestones:**
  - Commit `7f2d82b`: "4096 karakter" → "4096 byte" in `Inbox::catatanInternal()` error message and the two toasts in `index.php` (Alasan Snooze, Internal Note). Wording only, no logic change. Closes the carried-over "4096 karakter" code-review TODO.
  - No test asserts these texts, so no test changed. Suite: `vendor\bin\phpunit --no-coverage` **324 tests / 1097 assertions**, OK.
- **Dead-Ends (Do NOT Repeat):**
  - None.
- **Updated Files:**
  - `app/Controllers/Inbox.php` — `catatanInternal()` message.
  - `app/Views/inbox/index.php` — snooze reason + internal note toasts.
- **Decisions Made:**
  - Handoff message (`Inbox.php` ~1111) keeps "karakter": it is measured with `mb_strlen` (REQ-003 / CR-05), so "karakter" is correct there.
- **Next Action / Pending:**
  - **User:** TASK-021 (c) manual browser check AC-013 (h), then `/sdlc-write-code` TASK-022 → `/sdlc-code-review` of `db7f301` (+ `88cc7e0`, `7f2d82b`).
  - TODO (small, new): `Inbox.php` ~658 (`kirimPesan`) and ~729 (`kirim`) check `strlen($text) > 4096` but still say "4096 karakter" — same byte/karakter mismatch, left out of scope.
  - Carried over: `/sdlc-draft-prd` PRD §9.2 status text; `/sdlc-define-specs` Fase 1e (GH-010); NIT STD-03, FYI STD-04; check WA Gateway `data/` before moving the gateway.

<!-- checkpoint-tail: "4096 byte" wording fixed for Internal Note and Snooze reason (7f2d82b, suite 324/1097 green); Fase 1d still waits for manual browser check TASK-021 (c) and TASK-022, then /sdlc-code-review. -->

---

## 📝 Session Checkpoint: 2026-09-24 (Code review: Fase 1d TASK-020)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Review (M3 Fase 1 plan, Phase 4 closed). `/sdlc-code-review` of `db7f301` + `88cc7e0` + `7f2d82b`.
- **Active Artifacts:**
  - `spec/spec-design-m3-operational-inbox-fase1.md` — rev 1.2, unchanged.
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — rev 1.2, **`Completed`**: TASK-021 (c) ✅ (manual browser check AC-013 (h), dummy conversations ids 24575-24584 in local `aulia_inboxdb`), TASK-022 ✅ approved, TODO-SEARCH-01 closed in TASK-018, review result noted in TASK-022.
- **Achieved Milestones:**
  - Code review verdict **Merge**: no CRITICAL/REQUIRED findings, so no refactoring plan file. Reviewed inline (diff ~150 lines), not with the two sub-agents the skill describes.
  - Findings: [NIT] STD-01 AC-013 (d) negative check uses `assertNotContains`, could be `assertSame([])` (`OperationalInboxConversationTest.php:575`). [FYI] STD-02 `Inbox.php` ~658/~729 use `strlen` but say "4096 karakter" (already a TODO). [FYI] STD-03 five `mb_stripos` per row (= Plan RISK-005, accepted). [FYI] SPEC-01 `7f2d82b` touches `app/Views/` but CON-003 applies only to the TASK-020 commit; wording follows Spec NG-05.
  - Security: `q` is only used in in-memory `mb_stripos`, never in SQL; 255 cap kept.
  - Re-ran `tests/session/OperationalInboxConversationTest.php`: 25 tests / 159 assertions OK.
- **Dead-Ends (Do NOT Repeat):**
  - None.
- **Updated Files:**
  - `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — status `Completed`, TASK-021 (c)/TASK-022 evidence, review note.
- **Decisions Made:**
  - No refactoring plan for Fase 1d; STD-01 is optional.
- **Next Action / Pending:**
  - `/sdlc-audit-consistency` of PRD ↔ Spec rev 1.2 ↔ Plan rev 1.2 (re-check MC-01..03 and NG-01, TASK-019 hand-off).
  - Small TODOs (`/code-janitor`): STD-02 wording at `Inbox.php` ~658/~729; optional STD-01; optionally delete dummy conversations 24575-24584 from local `aulia_inboxdb`.
  - Carried over: `/sdlc-draft-prd` PRD §9.2 status text; `/sdlc-define-specs` Fase 1e (GH-010); check WA Gateway `data/` before moving the gateway.

<!-- checkpoint-tail: Fase 1d (TASK-020, db7f301+88cc7e0+7f2d82b) reviewed: Merge, no refactoring plan; plan rev 1.2 Completed; next is /sdlc-audit-consistency for MC-01..03/NG-01. -->

---

## 📝 Session Checkpoint: 2026-09-24 (PRD v1.4: ST-04 + GH-009)

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** PRD remediation after consistency audit Iteration 3 (95/100, user decision PROCEED).
- **Active Artifacts:**
  - `prd-20260922-0141-chat-whatsapp-inbox.md` — **v1.4**, status text only, no scope change.
  - `docs/audit/consistency-audit-m3-fase1-operational-inbox-2026-09-24.md` — Iteration 3 (95/100) + PRD Remediation Status block, projected 98/100 (40/28/30).
  - Spec rev 1.2 and Plan rev 1.2 — unchanged.
- **Achieved Milestones:**
  - ST-04: PRD §9.2 Fase 1c row marks TODO-SEARCH-01 closed in Fase 1d; Fase 1d row = Spec rev 1.2 (REQ-013, AC-013), Plan rev 1.2 Phase 4 `Completed`, code `db7f301`, 324/324 tests + TASK-021 (c), review Merge; Fase 2a row drops the "plans not synced" note (both plans rev 1.1 `Completed`, checked in frontmatter).
  - GH-009 (§10.9) criteria ticked with an evidence note; §1.1 amendment row v1.4.
- **Dead-Ends (Do NOT Repeat):**
  - None.
- **Updated Files:**
  - `prd-20260922-0141-chat-whatsapp-inbox.md` — v1.4.
  - `docs/audit/consistency-audit-m3-fase1-operational-inbox-2026-09-24.md` — Iteration 3 report + PRD Remediation Status.
- **Decisions Made:**
  - None beyond status sync.
- **Next Action / Pending:**
  - `/sdlc-define-specs` Fase 1e (GH-010, message-text search incl. search-speed design) + Spec editorial from Iteration 3 (§1 Fase 1c, §6 REQ-013, §13 wording).
  - Optional `/sdlc-plan-tasks` editorial (TASK-011 pointer to TASK-020, TASK-020 line refs).
  - Carried over: `/code-janitor` STD-02 wording `Inbox.php` ~658/~729, optional STD-01, dummy conversations 24575-24584; Fase 1 TASK-006 implied approval; check WA Gateway `data/` before moving the gateway.

<!-- checkpoint-tail: PRD v1.4 synced §9.2 (Fase 1c/1d/2a) and ticked GH-009 per audit Iteration 3 ST-04; next is /sdlc-define-specs for Fase 1e (GH-010). -->

---
