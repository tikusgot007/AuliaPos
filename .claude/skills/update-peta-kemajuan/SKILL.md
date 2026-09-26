---
name: update-peta-kemajuan
description: "Create and surgically maintain docs/peta-kemajuan-inbox.html — the static WhatsApp Inbox progress map for the non-technical project owner (milestones M1–M5 plus the out-of-chain GH-011 Grup/Balas/Teruskan feature). Use this skill whenever the user says 'update peta kemajuan', 'perbarui peta kemajuan', 'sync peta kemajuan', 'peta kemajuan inbox', 'update progress map', or asks to refresh the status page after commits, specs, plans, reviews, or deployments — even if they do not name the file. Also use it to explain what a card or milestone on that map means. Do not wait for an explicit path; the phrase alone is enough."
license: MIT
---

<!-- markdownlint-disable -->

# Update Peta Kemajuan (Inbox Progress Map)

## Overview

`docs/peta-kemajuan-inbox.html` is a single static HTML page that tells the project owner (a non-technical person) where the WhatsApp Inbox project stands: milestones M1–M5 plus the out-of-chain feature GH-011 (Grup/Balas/Teruskan). It is the owner's "one screen" source of truth, assembled from the repository itself — plans, specs, audit reports, commits, test results — never from memory or guesswork.

Your job is to keep that page honest. A progress map is only useful if the reader can trust it, so the hard part is not writing HTML: it is deciding, for each item, what the evidence actually proves. Almost every mistake with this skill comes from claiming more than the evidence supports.

## When to use

Trigger on the owner's everyday phrasing:

- "update peta kemajuan", "perbarui peta kemajuan", "sync peta kemajuan", "cek peta kemajuan"
- "update progress map", "peta kemajuan inbox", "progress map AuliaPos"
- a request to refresh the status page after commits, specs, plans, reviews, or deploys
- a question about what a specific card or milestone on the map means (see "Explain mode")

If the owner only asks a question about the page, answer from the page and its cited sources and **do not edit the file**.

## Before you start: the authoritative long-form

`docs/prompt-kilo-peta-kemajuan.md` is the owner's long-form maintenance brief. If it exists, treat it as authoritative: read it and follow any extra detail it adds. This skill must still work when that file is gone or moved, so the essential rules are repeated below and in `references/peta-structure.md`.

## Artifact and sources

- Page: `docs/peta-kemajuan-inbox.html` (living artifact, committed on branch `v2.3`).
- Source repo: `tikusgot007/AuliaPos` (the current working copy), branch `v2.3`; compare against `origin/v2.3`.
- Optional second repo: `tikusgot007/WA-Gateway` (branch `master`), monitored only when the page already carries a WA-Gateway line or the owner asks. **Its checkout location is not fixed** — see "Environment notes".
- Evidence roots to read when a claim is unclear: `plan/`, `spec/`, `docs/audit/`, `docs/decisions/`, `docs/runbooks/`, `docs/TODO-CHAT.md`, `.claude/instructions/memory.instructions.md`.

## Setup — only if the page is missing

1. Look first for a seed the owner may have attached: `peta-kemajuan-inbox-seed.html`.
2. Otherwise restore the last committed copy: `git show <branch>:docs/peta-kemajuan-inbox.html`.
3. Only if neither exists, create a minimal skeleton following `references/peta-structure.md`. Never invent history you cannot source.

## Update workflow

### Step 1 — Read the current baseline

Read `docs/peta-kemajuan-inbox.html`. Extract the recorded baseline hash from the header eyebrow (for example `v2.3 @ 9d4ac14`), plus the WA-Gateway line if the page carries one. That hash is what you diff from.

### Step 2 — Detect what actually changed

- `git fetch origin <branch>` first, then `git log <old-hash>..origin/<branch> --oneline`.
- Also inspect the working tree: `git status -sb` and `git diff --stat`. Uncommitted work is real progress and the owner may want it reflected.

### Step 3 — Decide the action

- **No new commits and no material working-tree change**: report that and **do not touch the file**. Do not "improve" the page just to have something to do.
- **New commits, or material uncommitted work**: update.
- **Only uncommitted material change (no new commit)**: do not silently rewrite and do not silently skip. Tell the owner what you found and ask whether to update from the working tree or wait for a commit. If they say update, label the uncommitted state clearly in the eyebrow and the footer.

### Step 4 — Read the evidence for each change

Never judge status from a commit subject. For every new commit run `git show <hash>` and read the full message and diff. Then open the plan/spec/report/memory files it touches whenever a status claim depends on them. A commit saying "done" is a claim, not evidence.

### Step 5 — Edit the page surgically

Change only the parts that changed. Keep the DOM structure, CSS, color tokens and fonts byte-identical unless something is genuinely broken in the browser. Anchor edits on short unique strings; never rewrite the whole file. Update as applicable: eyebrow hash/date, the "Berubah sejak…" box, the affected lane cards, lane notes, "Langkah berikutnya", and the footer source list.
### Step 6 — Report

Give the owner at most 5 short points: what changed in the file and one next step. Give the path so
they can open it (`file:///.../docs/peta-kemajuan-inbox.html`). **Do not commit or push unless the
owner explicitly asks.**

### Step 7 — Generate the next-step prompt

After the page is updated, also emit a **ready-to-run prompt** for the single most-urgent next step,
so the owner can start it in a fresh session without having to re-explain context. Derive it from
the `Langkah berikutnya` section you just wrote — never from memory.

Build the prompt from these parts, in this order:

1. **The trigger phrase.** Open with the exact phrase that routes to the right SDLC phase, e.g.
   `Jalankan /sdlc-write-code untuk Phase 4 …` or `Jalankan /sdlc-code-review …`. Use the
   `/command` shown in the page's `<span class="cmd">`, never a guess.
2. **The target artifact.** Name the plan, spec, or ticket file by its exact path
   (`plan/plan-...md`), pulled from the page text you wrote.
3. **The key requirement.** One sentence quoting the concrete acceptance criterion from the
   spec/plan — the thing that would make the step fail if ignored (e.g. "JID mentah tidak pernah
   di-render, nilai database tidak diubah").
4. **The prerequisite state.** State what already exists and where, so the owner does not have to
   re-discover it (e.g. "refactor + spec v1.4 + amandemen plan sudah ada di working tree, belum
   di-commit").
5. **The follow-on chain.** List the steps that depend on this one, in order, so the owner sees the
   full path to closing the phase (commit + push → code review → guard yang masih terbuka).
6. **The gate.** State the One Path Rule explicitly: jangan mulai langkah berikutnya sebelum langkah
   ini tuntas dan direview ulang.
7. **Cleanup before commit.** If the working tree holds artifacts that must not be committed
   (build logs, generated bat files), name them in the prompt so the owner cleans before `git add`.

Wrap the whole thing in a single fenced code block so the owner can copy-paste it verbatim into a
new session. Keep it in simple Indonesian, same register as the page prose. One prompt per update —
do not emit a prompt for more than one next step, even if the page lists several options; the
others belong in the page's "pilihan lain yang menunggu" paragraph, not in the prompt.

**Anti-patterns for this step:**

- Emitting a prompt that points at a file or command not present in the page you just wrote.
- Generating prompts for optional/lower-priority items (those stay as owner decisions in the page).
- Emitting a prompt when the page's `Langkah berikutnya` says there is nothing urgent — in that
  case say so plainly and offer no prompt.
## Content and evidence rules

These exist so the page never over-claims. The owner decides from this page, so a wrong "Selesai" is worse than an honest "sebagian".

1. **One language, plain.** Write page text in simple Indonesian for a non-technical reader. Keep file names, commit hashes, function names and commands exactly as they are; do not translate them.
2. **"Selesai" needs concrete evidence.** Mark a card `step done` only with real proof: a plan whose status is `Completed`, a code review with a Merge verdict / no blocking finding, or a written test or measurement result. A commit message alone is not proof — use `step partial` and say what is still missing.
3. **Separate the four claims.** "In code" versus "reviewed" versus "live/deployed" versus "proven by real measurement" are different things. Never collapse them into one "selesai"; say which one you mean.
4. **Respect explicit limits.** If the spec/plan/report itself says a claim must not be made (for example "do not claim Android parity verified", "stub-only"), do not claim it done even if it appears to work.
5. **Chase deferred items.** If the owner deferred a finding to a later stage but it never appears in that stage's plan or code, report it as an open gap — never assume it was handled.
6. **Production incidents outrank everything.** A real incident (customer data or service affected) is always priority #1 in "Langkah berikutnya" until it is closed with evidence, above any development work.
7. **Do not pile on scope.** While a `step partial` is in progress, do not add new work to "Langkah berikutnya" — finish started work first. Exceptions: a production incident, or an explicit new priority from the owner.
8. **Disclose unverified numbers.** Anything taken from a report, commit message or memory but not re-run by you must be marked as such in the footer. Attribution is not verification.
9. **Label uncommitted work.** If you update from the working tree, say so in the eyebrow (for example `+ working tree (belum di-commit)`) and in the footer, and name the uncommitted files.

## Page structure contract

Keep the existing structure. The full contract and a skeleton live in `references/peta-structure.md`. In short:

- **Header eyebrow**: `Status per <tanggal> · GitHub tikusgot007/AuliaPos · v2.3 @ <hash>` (plus an optional WA-Gateway line).
- **Legend**: four colors — `done` / `partial` / `todo` / `gated`.
- **"Berubah sejak versi `<hash lama>`" box**: 2–5 short, plain-language points.
- **Roadmap chain** (Tahap 0 → M1 → M2 → M3 → M4 → M5): leave it alone unless a milestone truly changes phase.
- **One lane per active milestone** (M1, M2, M3), a `Fix` lane for cross-milestone fixes, and a separate lane for out-of-chain features (for example `GH-011`). Add a lane for a genuinely new initiative.
- **Each lane**: a 1–2 sentence verdict; step cards using exactly `class="step done|partial|todo|gated"`, each with a short tag, a date, and an evidence `<ul>` when relevant.
- **Notes per lane**: `Bukti` and `Risiko/Masih terbuka`.
- **Langkah berikutnya** (`<section class="next">`): ONE most-urgent path, 1–3 concrete steps. If no yellow/red card remains, say so plainly and present the remaining options as owner decisions, not urgent tasks.
- **Footer**: the commits and files behind each claim, plus a clear marker on any number you did not re-run.

## Explain mode (no edit)

If the owner only asks what a card or milestone means, answer from the page and trace the claim to its cited source (`plan/`, `spec/`, `docs/audit/`, `docs/TODO-CHAT.md`, memory). Do not modify the file.

## Environment notes

- **The WA-Gateway checkout moves.** Older paths vanished (`C:\projects\...` no longer exists; copies later lived under `C:\home\`). Resolve the working copy before trusting any remembered path; if you cannot find it, say so instead of guessing or inventing a commit.
- **AuliaPos tests are database-sensitive.** If you re-run tests to verify a claim, run PHPUnit sequentially, never two processes at once — Inbox tests share a test database and would wipe each other's fixtures. The green signal is `vendor/bin/phpunit --no-coverage` exiting 0.
- **Windows/PowerShell quirks** are recorded in project memory (lint via `cmd /c`, UTF-8 reads, and so on). Follow them when a command misbehaves.

## Do NOT

- Do not write the page from scratch or restyle it.
- Do not change CSS, color tokens or fonts.
- Do not claim "live/deployed" from a note that is not in GitHub.
- Do not commit or push.
- Do not present uncommitted work as committed.

## Bundled resources

- `references/peta-structure.md` — the exact section contract plus a minimal skeleton for the setup path.
