---
goal: M3 Fase 1e (Pencarian Isi Pesan) — Code Review Remediation
version: 1.0
date_created: 2026-09-25
last_updated: 2026-09-25
owner: AuliaPos Inbox module
status: "Planned"
tags: ["refactor", "correctness", "sql-escaping", "frontend", "security"]
---

# Introduction

![Status: Planned](https://img.shields.io/badge/status-Planned-yellow)

> [!NOTE]
> **Revision 1.0 (2026-09-25).** This plan remediates `docs/audit/code-review-m3-fase1e-2026-09-25.md`
> over the Fase 1e change set (`0f22806^..HEAD` = `bd00c84` on branch `v2.3`, 7 files, +1139 / -8).
> It contains **no scope beyond the review findings**: no new feature, no migration, no index, no query
> parameter, no endpoint, no Gateway call.

The review found **0 CRITICAL** issues and **2 REQUIRED** issues. Everything else is `[OPTIONAL]`,
`[NIT]` or `[FYI]`. What actually blocks the merge:

- **`[SPEC-01]` (blocking).** The message-text `LIKE` pattern is escaped **twice**, so any keyword
  containing an apostrophe, a double quote or a backslash silently matches nothing. Proven on the live
  database: `q = "it's"` reaches **0** of the 2 real messages that contain an apostrophe as written,
  and **2** of 2 with the corrected bind. The feature's core contract (Spec §4.4, REQ-014) is broken
  for an ordinary class of Indonesian conversation text.
- **`[CORR-01]` (blocking).** The round guard is a single shared boolean that **any** finishing round
  releases, so a superseded round can hand the guard over while a newer round is still in flight —
  exactly the request pile-up REQ-017b exists to prevent, and newly expensive because Fase 1e made
  every request scan `messages`.

Two Axis A conclusions were corrected during verification and are reflected here, not silently dropped:

- **`[SEC-03]` was wrong.** Axis A declared the predicate safe because it traced
  `escapeLikeString() -> escapeString($q, true) -> _escapeString()`. That chain is accurate but
  incomplete: it stops one step before CI4's bind engine, which escapes the already-escaped value a
  second time (`Query::matchSimpleBinds()` -> `Query.php:305` -> `$this->db->escape()`).
- **`[CORR-02]` was overstated.** The "second seeder run writes 0 rows while printing success" scenario
  is **not reachable**: `batchExecute()` calls `query()` with no `try/catch` and `inbox.DBDebug = true`
  (`app/Config/Database.php:85`), so `BaseConnection:830-842` **throws**. The finding is downgraded to
  `[OPTIONAL]`; only "printed counts are attempted, not verified" survives.

**Why the fix needs no Spec clarification.** REQ-017b's normative text reads: *"tidak memulai putaran
pemuatan baru (polling 6 detik maupun pencarian dengan kata kunci yang sama) selama putaran sebelumnya
masih berjalan... Pengamannya berupa penanda sederhana yang **dilepas saat putaran selesai, berhasil
maupun gagal** (`.finally()`)"*. Two consequences follow. First, the scope is explicit: only polling
ticks and same-keyword searches are blocked, while REQ-017c deliberately allows a **new** keyword to
start a round — so a concurrent new-keyword round is intended behaviour, not a gap. Second, the guard
must be released **by the round that owns it**; today any round releases it. The round token in Phase 2
is therefore the faithful implementation of REQ-017b's own wording, and the shared boolean is the
deviation. No `/sdlc-clarify-reqs` pass is required. `[SPEC-02]` closes with `[CORR-01]`.

A useful side effect of the Phase 1 fix: `InboxMatchSnippetService::potong()` centres the snippet with
`mb_stripos($teks, $q)` on the **raw** keyword. Before the fix, SQL and the snippet logic disagreed
about what "matches" means; afterwards they agree again.

Normative upstream documents: `spec/spec-design-m3-operational-inbox-fase1.md` (rev 1.4) and
`plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` (v1.4, `Completed`) win on any conflict
(RISK-01 pattern, unchanged). Plan TASK-024 rule 3(b) is the direct mandate for Phase 1.

## 1. Traceability: Requirements & Constraints

- **REQ-001 (SPEC-01):** the message-text predicate must be equivalent to `LIKE '%q%'` for **every** `q`
  — the LIKE-metacharacter escaping and the SQL string escaping each happen exactly once.
- **REQ-002 (SPEC-01):** lock that equivalence with tests that use a keyword containing `'`, `"`, `\`
  and `!`, so the regression can never return unnoticed.
- **REQ-003 (CORR-01):** the round guard may be released only by the round that owns it; a superseded
  round must neither release the guard nor write to the DOM.
- **REQ-004 (CORR-03):** a synchronous throw while starting a round must not leave the guard stuck.
- **REQ-005 (CC-03):** REQ-017b/AC-015d must have **executable human evidence** (browser checklist),
  because the project has no JS test runner and the current screen test only greps JS source text.
- **REQ-006 (SEC-01, optional):** reject a `q` that is not valid UTF-8 with HTTP 400 at the boundary.
- **REQ-007 (PERF-01, optional):** do not compute a snippet for rows that pagination discards.
- **REQ-008 (CORR-02 + SPEC-03, optional):** the perf seeder verifies its own counts by re-reading, and
  the walkthrough records the fixture composition actually used.
- **PRN-001:** keep PSR-12 discipline and the existing house style in `app/Controllers/Inbox.php` and
  `app/Views/inbox/index.php`; comments explain **why**, never **what**.
- **PRN-002:** surgical edit only. `cariPesanCocok()` is **not** rewritten; the `ROW_NUMBER()` subquery
  stays as it is and only the bind changes.
- **SEC-001:** a `q` reaching the database stays parameter-bound; this plan never builds SQL by
  concatenation and never adds a code path that does.
- **SEC-002:** no new dependency in `composer.json`, no new library, no new tooling.
- **CON-001:** the CON-004 boundary stays intact — **no migration, no index, no FULLTEXT, no query
  parameter, no endpoint**, no Gateway call, and no data write (search stays read-only).
- **CON-002:** **no** `AbortController` and **no** timeout may be added to the round guard
  (CL-020 / REQ-017b).
- **CON-003:** the two semantic decisions already locked by the product owner are not reopened —
  `LIKE '%q%'` without an index (CL-020) and the separate `aulia_inboxdb_perf` measurement database
  (R-04). This plan touches neither.
- **CON-004:** the full suite must stay green via `vendor/bin/phpunit --no-coverage`, with **no
  decrease** from the review baseline of **395 tests / 1443 assertions**, and **no** skip, `@group`,
  suppression or deleted assertion may be introduced (Floor-Guard).
- **CON-005:** only **one** PHPUnit process at a time. `aulia_inboxdb_test` is shared and every Inbox
  test class calls `emptyTable()` in `setUp()`; parallel runs produce false failures.
- **CON-006:** do not touch the live `aulia_inboxdb`, the `auth/` folder, or the live WA-Gateway.

## 2. Implementation Steps

> **⚠️ EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> Execute phase by phase. Run the VERIFY task at the end of each phase, then **STOP AND WAIT** for
> explicit user approval before starting the next phase. DO NOT SKIP PHASES. Each code task ships its
> test in the same increment (Two-Layer Mandate). Never add `@group`, skips or deleted assertions to
> force green (Floor-Guard). Never write the Spec — if a semantic question appears, stop and route it
> to `/sdlc-clarify-reqs`.
>
> Mandatory upstream attachments for the executing session:
> `@plan/plan-refactor-m3-fase1e-message-search-v1.0.md`,
> `@docs/audit/code-review-m3-fase1e-2026-09-25.md`,
> `@spec/spec-design-m3-operational-inbox-fase1.md` (rev 1.4),
> `@plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` (v1.4).

### Implementation Phase 1: Escape-Once Predicate (BLOCKING — do this first)

- **GOAL-001:** restore REQ-014/§4.4 for every keyword by making the LIKE pattern equivalent to
  `LIKE '%q%'` for all `q`, and lock it with tests. No other behaviour changes.

| Task ID | Description (Include Exact File Paths & Micro-Testing) | Ref ID | Completed | Date |
| --- | --- | --- | --- | --- |
| TASK-101 | `app/Controllers/Inbox.php` (`cariPesanCocok()`, ~line 230-234): replace `['%' . $db->escapeLikeString($q) . '%']` with a value built by `strtr()` and bound once — full detail in the **TASK-101 detail block** below. Keep the SQL text, the `ESCAPE '!'` clause and the `ROW_NUMBER()` subquery byte-identical. Add a short `why` comment naming the double-escape trap. | REQ-001, PRN-002, CON-003 | | |
| TASK-102 | `tests/session/OperationalInboxConversationTest.php`: extend the AC-014 matrix with cases (j)-(n) per the **TASK-102 detail block**: apostrophe, double quote, backslash, and literal `!`, each with a negative control. Do not edit or weaken any existing assertion. | REQ-002 | | |
| TASK-103 | **VERIFY**: `cmd /c 'vendor\bin\phpunit --no-coverage > build\phase1-refactor.txt 2>&1'` → exit 0, **>= 395 tests / >= 1443 assertions**, zero skips; then re-run the original AC-014 cases (a)-(i) individually to prove AC-014h still holds; and re-run the read-only escape check against the live database to confirm the apostrophe keyword now reaches the real rows. | CON-004, REQ-001 | | |
| TASK-104 | **APPROVAL**: 🛑 Report the VERIFY evidence and wait for explicit user confirmation before Phase 2. | - | | |

**TASK-101 detail — the escape-once bind (normative).**
File: `app/Controllers/Inbox.php`, function `cariPesanCocok(string $q): array`.

Root cause, stated precisely, so the fix is not mistaken for a style preference: `escapeLikeString()`
**already** performs one full SQL escape (`escapeString($q, true)` -> `_escapeString()` -> mysqli
`real_escape_string`) and then escapes the LIKE metacharacters. Binding that result through
`$db->query($sql, [$value])` runs the SQL escape **a second time** in `Query::matchSimpleBinds()`
(`Query.php:305` -> `$this->db->escape($value)`). For `q = "it's"` the value reaching SQL becomes
`%it\\\'s%`, which under `ESCAPE '!'` demands a literal backslash in `messages.text` that no real
message has. The two escaping concerns must each be handled exactly once:

- **SQL string quoting** -> left to the bind engine (once).
- **LIKE metacharacters** -> handled explicitly by the caller (once).

Target value (the only intended change):

```php
// Escape LIKE metacharacters here; the SQL string escaping is done exactly once by the
// bind engine. Do NOT use escapeLikeString() on this value: it would be escaped twice.
$pola = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
```

`strtr()` with a map is a single pass and does **not** re-scan its own replacements, so `!` -> `!!`
cannot cascade into `!!!!` — which is why three sequential `str_replace()` calls would be wrong here.
The `ESCAPE '!'` clause stays as it is; it is what makes `!%`, `!_` and `!!` literal.

This satisfies plan TASK-024 rule 3(b), which prescribed `escapeLikeString()` **together with**
`like(..., 'both', false)` *or* **"an equivalent explicit escape"**. The builder form is not available
here: `ROW_NUMBER() OVER (PARTITION BY ...)` cannot be expressed by the CI4 query builder, so the raw
query must stay and the bind is what gets fixed — the "equivalent explicit escape" branch.

**TASK-102 detail — the regression matrix.**
File: `tests/session/OperationalInboxConversationTest.php`. Seed one conversation whose `contact_name`
contains no apostrophe and does not contain the substring `its`, holding messages with the texts below.
Every case names one contract; do not merge them and do not touch cases (a)-(i).

| Case | Input `q` | Expected | What it locks |
| --- | --- | --- | --- |
| (j) | `it's` | conversation found | SPEC-01 — the actual defect |
| (k) | `its` | conversation NOT found | negative control: the apostrophe is not being ignored |
| (l) | `50%` | AC-014h as written: matches `diskon 50%`, not `diskon 500` | preserved CL-008 behaviour |
| (m) | `lumayan!` | matches `lumayan!`, not `lumayan` | the `!` escape char doubles correctly under `strtr()` |
| (n) | `C:\foto` | matches literal `C:\foto` | backslash stays a literal, not an escape prefix |

Case (m) matters because the old call silently got `!` right and the new `strtr()` must keep getting it
right; case (n) is the one keyword class where a slipped extra escape pass would still look "almost
correct". If a seeded text makes a case ambiguous, change the seed text, never the expectation.

### Implementation Phase 2: Round-Guard Ownership (BLOCKING — after Phase 1 approval)

- **GOAL-002:** make the round guard obey REQ-017b literally — it is released by the round that owns it
  — without adding `AbortController`, a timeout, or any new state beyond one counter. This closes
  `[CORR-01]`, `[SPEC-02]` and `[CORR-03]`.

| Task ID | Description (Include Exact File Paths & Micro-Testing) | Ref ID | Completed | Date |
| --- | --- | --- | --- | --- |
| TASK-201 | `app/Views/inbox/index.php` (`muatUlangDaftarConversation()`, ~1008-1036): add one module-scope counter and make `.finally()` release the guard **only** when the finishing round is the newest — full detail in the **TASK-201 detail block**. Keep `jalankanPencarianConversation()`'s same-keyword check as it is (it already encodes REQ-017b's parenthetical). | REQ-003, CON-002 | | |
| TASK-202 | `app/Views/inbox/index.php` (same function, ~1008-1012): make the synchronous prelude throw-safe so the guard cannot stay `true` forever — detail in the **TASK-201 detail block**, item (c). Same commit as TASK-201 (one function, one edit region). | REQ-004 | | |
| TASK-203 | Add the **superseded-round scenario** to the AC-015d browser checklist and record its result: (1) polling running on keyword K while the network is slowed in DevTools, (2) type a new keyword K2 and press Enter, (3) confirm in the Network panel that K2 round and K round overlap, no second polling round starts while K2 is in flight, and the DOM shows only K2 results. Evidence goes to the Fase 1e walkthrough §6 (`docs/walkthrough-m3-fase1e-message-search-2026-09-25.md`) or to a new remediation walkthrough. Documentation only, no code. | REQ-005 | | |
| TASK-204 | **VERIFY**: `vendor/bin/phpunit --no-coverage` exit 0, >= 395 tests / >= 1443 assertions, zero skips; `OperationalInboxScreenTest` still green; the TASK-203 checklist recorded with its outcome; `git diff --numstat` shows zero changes to `app/Controllers/Inbox.php`, `app/Services/`, models and tests in this phase. | CON-004, CON-001 | | |
| TASK-205 | **APPROVAL**: 🛑 Report the VERIFY evidence and wait for explicit user confirmation before Phase 3. | - | | |

**TASK-201 detail — guard ownership (normative).**
File: `app/Views/inbox/index.php`, function `muatUlangDaftarConversation(saatGagal)`.

**(a) Add identity to the round.** One module-scope counter next to the existing
`putaranDaftarBerjalan` declaration:

```js
let putaranDaftarTerakhir = 0; // identitas putaran terbaru; pengaman hanya boleh dilepas olehnya
```

**(b) Claim and release by identity.** The function currently sets `putaranDaftarBerjalan = true`
unconditionally and releases it unconditionally from `.finally()`. Capture the round's own number and
release the guard only if it is still the newest:

```js
const giliran = ++putaranDaftarTerakhir;   // before starting the round
// ...existing .then() / .catch() bodies keep their own kataKunciAktif stale-keyword guards...
.finally(function() {
    if (giliran === putaranDaftarTerakhir) putaranDaftarBerjalan = false;
});
```

`giliran` is a closure variable, so two overlapping rounds each hold their own number. A round that has
been superseded leaves the guard alone and lets the owner release it.

**(c) Throw-safe prelude (TASK-202).** `ambilSemuaConversation()` can throw **synchronously** — it
builds a query string with `encodeURIComponent()`, which raises `URIError` on a lone surrogate. When it
throws, no `.finally()` is ever attached and the guard stays `true` forever, so the list stops
refreshing until the page is reloaded. Hold the promise, then attach the chain:

```js
const giliran = ++putaranDaftarTerakhir;
putaranDaftarBerjalan = true;
let janji;
try {
    janji = ambilSemuaConversation(kataKunci);
} catch (err) {
    putaranDaftarBerjalan = false;
    if (typeof saatGagal === 'function') { saatGagal(err); }
    return;
}
janji.then(...).catch(...).finally(...);
```

Claiming the guard before the call is safe: there is no `await` between claiming it and attaching the
chain, so no other round can interleave in that window.

**(d) Optional, same commit, explicitly not required.** Mirroring the ownership check
(`if (giliran !== putaranDaftarTerakhir) return;`) into `.then()` and `.catch()` is redundant today,
because those bodies already discard superseded rounds via their `kataKunciAktif` guard. Add it only if
the implementer prefers the invariant to be locally obvious; it changes no behaviour. Keep this
optional so the diff stays minimal.

**(e) Forbidden.** Do not add `AbortController`, `AbortSignal`, a timeout, or a request cancel
(CON-002 / CL-020 / REQ-017b). Do not change `jalankanPencarianConversation()`'s condition
`if (putaranDaftarBerjalan && baru === kataKunciSebelumnya) return;` — it is the literal encoding of
REQ-017b's parenthetical and of REQ-017c's new-keyword allowance.

### Implementation Phase 3 (OPTIONAL): Hardening — execute only on explicit user approval

- **GOAL-003:** close the non-blocking `[OPTIONAL]` findings that have a real failure mode. This phase
  is **skippable**; if the user declines it, close the plan after Phase 2 and carry these items as
  standing TODOs.

| Task ID | Description (Include Exact File Paths & Micro-Testing) | Ref ID | Completed | Date |
| --- | --- | --- | --- | --- |
| TASK-301 | `app/Controllers/Inbox.php` (input boundary, ~95-105): after `trim()`, reject a non-UTF-8 `q` with HTTP 400 (`mb_check_encoding($q, 'UTF-8')`), so a malformed byte sequence becomes a 400 instead of an unhandled driver exception under `DBDebug = true`. Test: `GET /inbox/api/conversations?q=%FF` → 400, and a valid UTF-8 multi-byte keyword (`é`) still → 200. | REQ-006, SEC-001 | | |
| TASK-302 | `app/Controllers/Inbox.php` (`apiConversations()`, ~171-184 and ~221-257): stop calling `potong()` for rows that pagination discards — carry `text`/`is_internal`/`message_timestamp` through the slice and build `match_snippet` afterwards. Behaviour must be byte-identical, so no new test is required; the whole suite is the regression evidence (per `[CORR-06]` the `null` guard is unreachable for DB rows, which is why this is safe). | REQ-007 | | |
| TASK-303 | `app/Commands/SeedFase1ePerf.php` (~159-164): after seeding, verify by re-reading (`countAllResults()` on both tables) and print the **verified** counts, so a partial load cannot be reported as success. Documentation: record the fixture composition actually used (1% Internal Note, ~55-character texts — see `[SPEC-03]`/`[CORR-04]`) next to the AC-016 tables in the walkthrough. | REQ-008 | | |
| TASK-304 | **VERIFY**: `vendor/bin/phpunit --no-coverage` exit 0, no decrease from the Phase 2 count, zero skips; the seeder run twice against `aulia_inboxdb_perf` to confirm both the verified counts and the absence of silent partial writes. | CON-004 | | |
| TASK-305 | **APPROVAL**: 🛑 Report the VERIFY evidence and wait for explicit user confirmation before closing the plan. | - | | |

## 3. Structural Remedies & Alternatives

- **ALT-001: keep `escapeLikeString()` and neutralize the bind's second escape pass** — REJECTED for
  explicitness, not for correctness. It is behaviourally equivalent if the bind shape that disables
  escaping is used, but it makes a security-relevant guarantee depend on a framework detail that is
  invisible at the call site. The `strtr()` form states the contract in one line, next to the
  `ESCAPE '!'` clause that gives it meaning.
- **ALT-002: use the query builder, `like('text', $q, 'both', false)`** — REJECTED as unavailable: the
  `ROW_NUMBER() OVER (PARTITION BY ...)` subquery cannot be expressed by the CI4 builder, so the raw
  query stays. TASK-101 is exactly the "equivalent explicit escape" alternative that plan TASK-024
  rule 3(b) permits.
- **ALT-003: stop escaping LIKE metacharacters entirely** — REJECTED. `%` and `_` would become
  wildcards and CL-008/AC-014h would break (`q = "50%"` would match `diskon 500`).
- **ALT-004: reject keywords containing `'`, `"`, `\`** — REJECTED. It fails a legitimate class of
  ordinary conversation text in order to dodge a bug in our own escaping.
- **ALT-005: move Phase 1 into a shared escaping helper** — REJECTED for this plan (single call site;
  an unused abstraction). Revisit only if a second `LIKE` search appears.
- **ALT-006: replace the round guard with `AbortController`** — REJECTED. CON-002 / CL-020 / REQ-017b
  forbid it, and the justification is recorded in the Spec: the smallest change that keeps the list
  responsive.
- **ALT-007: serialize every round, including a new keyword** — REJECTED. It would violate REQ-017c,
  which requires a new keyword to be sent immediately.
- **ALT-008: add a JS test runner to lock REQ-017b automatically** — REJECTED for Fase 1e. Tooling is
  not justified by a `[NIT]`; TASK-203 supplies human evidence instead. If a JS runner is ever added,
  the round-guard scenario is the first test to write.
- **ALT-009: fold the current guard into a "generation + keyword" pair object** — REJECTED. Same
  behaviour as TASK-201 with more state; PRN-002 asks for the surgical edit.
- **DEFER-001 (`[NIT] CC-01`, duplicate `is_internal` truthiness check):** no behaviour change, two
  call sites — hand to `/code-janitor` rather than widening this plan.
- **DEFER-002 (`[NIT] CC-02`, `escapeHtmlInbox()` is text-context only):** all current call sites are
  element-content context, so there is no live defect; renaming or hardening it touches every call
  site. Hand to `/code-janitor`.
- **DEFER-003 (`[FYI] SEC-02`, hardcoded `ESCAPE '!'`):** currently correct. Locking it belongs with a
  future config change; TASK-102 case (m) already pins the behaviour, so the risk is bounded.
- **DEFER-004 (`[FYI] CORR-05`, MariaDB >= 10.2 requirement):** a deployment prerequisite that belongs
  in `docs/ARCHITECTURE.md` via `/sdlc-map-architecture`, not in a code refactor.

## 4. Dependencies

- **DEP-001:** PHP `mbstring` — already present (used by the Service and by verified `mb_strlen` usage);
  no `composer.json` change (SEC-002).
- **DEP-002:** MariaDB 10.4 on XAMPP — supports both `ROW_NUMBER()` (>= 10.2) and `ESCAPE`; no change.
- **DEP-003:** no migration, no index, no FULLTEXT, no query parameter, no endpoint, no new library
  (CON-001, CL-020). Phase 3 TASK-303 is the only task that runs a Spark command, and only against
  `aulia_inboxdb_perf`.
- **DEP-004:** TASK-203 needs a real browser session with DevTools (Network panel + throttling) and the
  local Apache already running. No automation is possible in this project (no JS runner).
- **DEP-005 (sequencing, not a blocker):** Phase 2 depends on Phase 1 only for the shared green
  baseline (CON-004), not for code. Both must land on `v2.3`; keep the phases in separate commits so
  `git revert` can separate a predicate fix from a frontend fix.

## 5. Files Affected

- **FILE-001:** `app/Controllers/Inbox.php` — TASK-101 (bind in `cariPesanCocok()`), TASK-301 (input
  boundary, optional), TASK-302 (snippet after pagination, optional). `apiConversations()`'s contract,
  response shape and keys do not change.
- **FILE-002:** `tests/session/OperationalInboxConversationTest.php` — TASK-102 (AC-014 cases j-n).
- **FILE-003:** `app/Views/inbox/index.php` — TASK-201 and TASK-202 (`muatUlangDaftarConversation()`
  only). `jalankanPencarianConversation()`, `renderSnippetCocok()` and the snippet markup are untouched.
- **FILE-004:** `docs/walkthrough-m3-fase1e-message-search-2026-09-25.md` — TASK-203 (AC-015d
  checklist + result) and TASK-303's fixture-composition note. If a remediation walkthrough is created
  instead, link it from here.
- **FILE-005:** `app/Commands/SeedFase1ePerf.php` — TASK-303 (verified counts, optional).
- **Not touched by this plan:** `app/Services/InboxMatchSnippetService.php`,
  `tests/unit/InboxMatchSnippetServiceTest.php`, `ConversationModel.php`, `InboxSlaService.php`,
  `app/Config/Database.php`, `spec/`, `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md`, and any
  migration. `potong()` stays a pure function; its `mb_stripos($teks, $q)` call already uses the raw
  keyword and needs no change.

## 6. Testing Strategy

- **TEST-001 (REQ-001):** AC-014 case (j) — `q = "it's"` finds a message containing an apostrophe.
  This is the test that would have caught `[SPEC-01]`. [TASK-102]
- **TEST-002 (REQ-001):** AC-014 case (k) — `q = "its"` does **not** find it (negative control; proves
  the fix is not simply ignoring the apostrophe). [TASK-102]
- **TEST-003 (CON-003):** AC-014 case (l) — the existing AC-014h behaviour is re-asserted unchanged:
  `q = "50%"` matches `diskon 50%` and not `diskon 500`. [TASK-102]
- **TEST-004 (REQ-001):** AC-014 cases (m) and (n) — a literal `!` and a literal `\` each stay literal.
  [TASK-102]
- **TEST-005 (REQ-002, regression lock):** the existing cases (a)-(i) and REQ-016c stay green without a
  single edited assertion; `git diff` on the test file shows **additions only** (Floor-Guard).
  [TASK-102, TASK-103]
- **TEST-006 (REQ-001, live confirmation):** a read-only check against the live database showing the
  apostrophe keyword now reaches the real rows (0 -> 2 at review time). Evidence, not a test.
  [TASK-103]
- **TEST-007 (REQ-003, human evidence):** the AC-015d superseded-round browser scenario, recorded with
  its outcome and the Network-panel observation. [TASK-203]
- **TEST-008 (REQ-004):** no automated test exists (no JS runner). Evidence is the code review of the
  `try/catch` plus the AC-015d checklist still passing. [TASK-202, TASK-203]
- **TEST-009 (REQ-006, optional):** `?q=%FF` → 400; a valid multi-byte keyword → 200. [TASK-301]
- **TEST-010 (REQ-007, optional):** behaviour-neutral; the existing AC-014 assertions plus a byte-level
  comparison of one paginated response before and after are the evidence. [TASK-302]
- **TEST-011 (REQ-008, optional):** run the seeder twice against `aulia_inboxdb_perf` and confirm the
  printed counts are re-read counts and that no partial write is reported as success. [TASK-303]
- **TEST-012 (Macro Gate, CON-004):** `vendor/bin/phpunit --no-coverage` green at the end of every
  phase, no skip/suppression, test and assertion counts never below 395 / 1443. One process at a time
  (CON-005). [TASK-103, TASK-204, TASK-304]
- **TEST-013 (CON-001 boundary audit):** `git diff --numstat` over each phase — no migration, no index
  DDL, no route, no new query parameter, no Gateway call, no write path. [TASK-103, TASK-204]

## 7. Risks & Rollback Plan

- **RISK-001 (REQ-001, the bind change):** the predicate is security-relevant, so a wrong fix could
  loosen escaping instead of tightening it. Mitigation: the change **removes** no escaping — it removes
  one *duplicate* pass and keeps `ESCAPE '!'`; TEST-003 and TEST-004 pin exactly the two ways it could
  go wrong (`%`/`_` becoming wildcards, `!`/`\` losing their meaning); the review's empirical table is
  the expected outcome to reproduce (0 -> 2 for the apostrophe keyword).
- **RISK-002 (REQ-001, `strtr()` cascading):** a naive three-call `str_replace()` chain would re-escape
  `!` -> `!!` into `!!!!`. Mitigation: the plan mandates `strtr()` with a map (single pass, no
  re-scan); TEST-004 case (m) fails loudly if the implementer substitutes the wrong primitive.
- **RISK-003 (REQ-003, guard token):** if the counter is incremented after the promise chain is
  attached, a superseded round could still compare equal. Mitigation: increment **before** starting the
  round, as written in TASK-201 detail (b); TASK-203's browser checklist is the observable check.
- **RISK-004 (REQ-003, frontend has no automated test):** a regression in the guard would not fail CI.
  Mitigation: TASK-203 records human evidence, REQ-017b's wording is quoted in the code comment, and
  the residual gap is stated openly rather than claimed closed. Accepted consciously — the same
  trade-off the Spec recorded for RISK-009.
- **RISK-005 (REQ-004, `try/catch` hides an error):** swallowing a genuine programming error would mask
  a bug. Mitigation: the `catch` re-uses the existing `saatGagal` callback so the screen still surfaces
  the failure; it releases the guard and reports, it does not stay silent.
- **RISK-006 (Phase 3, TASK-301 UTF-8 rejection):** a previously "working" keyword could start
  returning 400. Mitigation: the check runs only on a byte sequence that mysqli would reject anyway
  under `DBDebug = true` — the change converts a 500 into a 400, and the valid-UTF-8 positive control
  forbids over-rejection.
- **RISK-007 (Phase 3, TASK-302 pagination move):** reordering could drop a snippet. Mitigation:
  `[CORR-06]` establishes that the `null` guard is unreachable for DB rows, so the two orderings are
  equivalent; skip TASK-302 entirely if the diff is not obviously behaviour-neutral.
- **RISK-008 (scope):** this plan touches two files of production code plus tests and docs. If any task
  starts to need a change to `app/Services/`, a migration, an index, or a Spec edit, **STOP** — that is
  outside the review's findings and needs a new decision from the product owner.
- **Rollback (general):** one task = one commit, one phase = one reviewable increment; `git revert` per
  commit. No migration, no data change, no Gateway call, no config change, so a rollback cannot corrupt
  existing Inbox data. Phase 1 and Phase 2 are independent: reverting either leaves the other valid
  (Phase 1 without Phase 2 = the feature works but the guard is still shared; Phase 2 without Phase 1 =
  the guard is correct but apostrophe keywords still fail).
- **Rollback (Phase 3 TASK-303):** the seeder writes only to `aulia_inboxdb_perf`; the live database and
  `aulia_inboxdb_test` are never touched (CON-006, Spec §9).

---

> **Handoff:** Phase 1 (TASK-101..TASK-104) may start now via `/sdlc-write-code` in a new session with
> the four upstream attachments listed in the execution directive. Phase 2 (TASK-201..TASK-205) needs
> Phase 1 approved first, because both phases report against one shared green baseline. Phase 3
> (TASK-301..TASK-305) is optional and requires an explicit decision from the product owner.
> Implementation MUST go through `/sdlc-write-code`; the reviewer/planner does not write source code.
