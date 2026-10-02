# SDLC Workflow (applies to every task)

This file complements `AGENTS.md`. Read `AGENTS.md` first. If the two conflict,
follow `AGENTS.md` §19 (instruction priority) and tell the user about the conflict.
Communicate with the user in Bahasa Indonesia (AGENTS.md §2.1).

## 1. Classify the task first

State the tier in one line before doing anything else.

| Tier | When | Phases |
|---|---|---|
| A | New feature, business-rule change, schema change, cross-repo (Gateway) change | 1 → 7 (full) |
| B | Bug fix | Root cause (AGENTS.md §4) → Plan → Fix + regression test → Review → CHANGELOG if a business rule is touched |
| C | Small/cosmetic change, no business or data impact | Short plan → Implement → Verify |

When unsure between tiers, pick the higher one and say so.

## 2. Phases (Tier A)

| # | Phase | Output | Location |
|---|---|---|---|
| 1 | Requirements | Goal, user stories, numbered acceptance criteria (AC-1, AC-2, ...), constraints, assumptions, open questions | `docs/requirements/YYYY-MM-DD-<slug>.md` (Bahasa Indonesia, business doc) |
| 2 | Design | Affected files and call chains (`file:line`), data/schema impact, API/route impact, options with one recommendation, risks, test plan | `docs/design/YYYY-MM-DD-<slug>.md` (English, technical doc) |
| 3 | Implementation | Minimal diff per the approved design | source |
| 4 | Testing | At least one executable test per acceptance criterion; run it and report the real result | `tests/` |
| 5 | Review | Diff checked against requirements and design; AGENTS.md §20 checklist | session checkpoint in `docs/sesi/` |
| 6 | Deployment | Release and rollback notes | `docs/deploy.md` (update only if the procedure changed) |
| 7 | Maintenance | Business-rule change logged; session checkpoint written | `docs/CHANGELOG.md`, `docs/sesi/` |

Use `docs/requirements/TEMPLATE.md` and `docs/design/TEMPLATE.md` as starting points.

For every tier, Phase 1 starts by reading `docs/TODO.md`: name the items relevant to
the request, and record any new finding discovered while working there (AGENTS.md §21).

## 3. Approval gates

Wait for an explicit "OK" / "Lanjut" from the user (AGENTS.md §3) at each gate.
Never carry an approval over to a later gate.

- Gate 1: after Requirements (is the spec right?).
- Gate 2: after Design (this is the AGENTS.md "plan approval"; no code before it).
- Gate 3: after Review, before anything deployment-related.

Phases 3 and 4 run together after Gate 2. Stop and ask again if new findings change
behavior, data impact, security impact, or compatibility (AGENTS.md §3, Phase 3).

## 4. Rules that keep the phases honest

- Do not write production code during phases 1-2. Do not edit production code during phase 4;
  a failing test that exposes a production bug goes back to the user as a finding.
- Traceability: every acceptance criterion maps to at least one named test.
  Test names or comments reference the criterion (e.g. `AC-2`).
- Money and state logic (`transaksi`, `pembayaran`, `tagihan`, `kas`, discounts, due dates)
  needs tests even for Tier B and C changes that touch it.
- Prefer pure services under `app/Services` for new calculation logic so it can be tested
  without booting the framework (existing pattern: `KalkulasiDiskonTransaksi`,
  `KalkulasiStatusPembayaran`, `KalkulasiJatuhTempo`).
- Tests must never connect to the production or development database from `.env`.
  Tests that need a database require the user's approval and a dedicated test database.
- Never claim a test passed unless it was actually run in this session. If it could not be run, say so.
- Do not run deployment, migration, or rollback commands against a real environment.
  Write them in `docs/deploy.md` and let the user run them.

## 5. Closing a task

Final report is 2-4 lines (AGENTS.md §18): what changed and where, which verification ran
and its result, what remains unverified.

Before reporting, update `docs/TODO.md`: delete the line of every item that is finished
and verified, and add any new finding discovered during the work (AGENTS.md §21).
