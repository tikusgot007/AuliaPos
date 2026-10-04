<!-- markdownlint-disable -->

# AGENTS.md — Aulia Kasir System

> **Project Description:** Aulia Kasir is a Point of Sale (POS) application built with CodeIgniter 4 for managing transactions, cashiers, products, customers, schedules, payments, and cash reports.

# 1. Core Engineering Principles

## 1.1 Spec-First & Impact-Aware

Do not write code immediately.

Before changing code:

1. Understand the requirement and requested behavior.
2. Read the relevant code.
3. Trace the real flow end-to-end.
4. Identify callers, consumers, dependencies, and potentially affected features.
5. Check relevant business and technical documentation.
6. Clarify anything that can materially change the implementation.
7. Create an execution plan.
8. Wait for explicit user approval before making code changes.

## 1.2 Efficient Senior Developer Principles

Efficiency means avoiding unnecessary work and unnecessary code. It does not mean sacrificing correctness.

After understanding the problem, use this order:

1. Does this need to be built at all? **YAGNI.**
2. Does it already exist in the repository? Reuse the existing helper, utility, service, model, or pattern.
3. Does the standard library already solve it?
4. Does a native platform feature solve it?
5. Does an already-installed dependency solve it?
6. If multiple correct implementations are similar in size and complexity, choose the simpler one.
7. Only then write the minimum code required.

Principles:

* Deletion over addition.
* Boring over clever.
* Fewest files possible.
* No unnecessary abstractions.
* No unnecessary dependencies.
* No unnecessary boilerplate.
* No unrelated refactors.
* Keep the working diff as small as possible.
* The shortest working diff wins only after the real problem and flow are understood.

When two standard-library approaches are roughly equal in size, choose the one that is more correct for edge cases.

If a deliberate simplification accepts a meaningful limitation, add a `ponytail:` comment describing the limitation and upgrade path.

## 1.3 Never Be Lazy About Important Things

Do not compromise:

* correctness;
* understanding of the problem;
* input validation at trust boundaries;
* error handling that prevents data loss;
* security;
* accessibility;
* data integrity;
* compatibility;
* real-world hardware limitations;
* anything explicitly requested by the user.

Untested non-trivial logic is unfinished work.

# 2. Communication

## 2.1 Language

`AGENTS.md` is written in **English**.

Communication with the user must be in **Bahasa Indonesia**.

Use **English** for:

* source code;
* code comments;
* file and folder names;
* class names;
* method names;
* variable names;
* commands;
* library names;
* original error messages;
* official technical terminology;
* technical and architecture documentation.

Use **Bahasa Indonesia** for:

* communication with the user;
* explanations and analysis addressed to the user;
* business and domain documentation;
* business rules;
* operational documentation for the Indonesian team;
* `docs/CHANGELOG.md`.

Keep established domain terms unchanged, including:

`transaksi`, `pelanggan`, `kasir`, `tagihan`, `pembayaran`, `status_pembayaran`, `jadwal`.

## 2.2 Tone

Use a style that is:

* clear;
* direct;
* professional;
* concise;
* free of unnecessary small talk;
* free of emojis.

## 2.3 Facts, Assumptions, and Verification

Clearly distinguish between:

* facts verified in the repository;
* assumptions;
* hypotheses;
* information not yet verified.

Never invent:

* file names;
* class names;
* method names;
* routes;
* database tables;
* database columns;
* APIs;
* dependencies;
* behavior.

If something has not been checked, state that it is **not yet verified** and inspect it before making a technical decision.

# 3. Task Execution Protocol

Every task, bug fix, or new feature follows these phases.

The tiered SDLC workflow (Tier A/B/C), approval gates, and phase rules are defined in `.kilo/rules/sdlc.md`; read it together with this file.

## Phase 1 — Understand the Spec & Check Impact

### Do not write code yet.

Start by:

1. Restating the task in one sentence.
2. Identifying the files and functions likely to be affected.
3. Reading the relevant code.
4. Tracing the real flow end-to-end.
5. Checking callers and consumers.
6. Checking relevant tests and documentation.

For changes involving transactions, payments, customers, cashiers, schedules, reports, or shared data, inspect the relevant application and database flow before proposing implementation.

### Clarification Rules

Ask only when the answer can change the result.

Ask when:

* scope is ambiguous;
* a business rule is undefined;
* multiple materially different approaches are valid;
* data loss is possible;
* compatibility requirements are unknown;
* an instruction or source of truth conflicts;
* a destructive or irreversible action is involved.

Ask at most **one round**, with at most **3 numbered questions**.

## Phase 2 — Work Plan & Mitigation

After the specification is clear, provide a concise execution plan, normally **3–5 steps**.

Include:

* goal;
* files to modify or create;
* major changes;
* dependencies;
* impact mitigation;
* verification/testing approach;
* important edge cases.

When multiple approaches are viable:

```text
Ada 2 opsi:

A. <short name> — <main consequence / trade-off>

B. <short name> — <main consequence / trade-off>

Rekomendasi: A, karena <most important reason>.
```

Maximum 3 options.

Always give one recommendation when multiple viable approaches exist.

For business-rule decisions, identify the relevant business documentation.

After the plan, ask:

> "Apakah rencana dan penanganan dampaknya sudah sesuai untuk dieksekusi?"

### Approval Gate

**Do not write or modify code until the user explicitly approves the plan**, such as `OK` or `Lanjut`.

## Phase 3 — Execution

After explicit approval:

* implement the agreed changes;
* use the minimum code necessary;
* preserve existing architecture and conventions;
* keep the scope controlled;
* avoid unrelated changes;
* run appropriate verification.

If new findings materially change behavior, scope, data impact, security impact, or compatibility, stop and request approval for the revised plan.

# 4. Bug Fix & Root Cause

A bug report usually describes a **symptom**, not the root cause.

For bug fixes:

1. Identify the symptom.
2. Trace the flow to the root cause.
3. Search all callers of the function or component being changed.
4. Fix the shared root cause when it is genuinely shared.
5. Verify sibling callers are not left broken.
6. Verify related flows for regression.

Prefer fixing one shared root cause over adding repeated fixes to individual callers.

If the root cause is uncertain:

* state the candidate cause;
* state the current evidence;
* state what must be checked to confirm it.

Bug reports should follow:

1. **Symptom**
2. **Root cause** — `file:line`
3. **Other affected callers**
4. **Proposed fix**
5. **Verification**

# 5. Technical Investigation Format

Prefer call chains with `file:line` references over long narrative explanations.

Example:

```text
POST /api/kasir/selesaikan-transaksi
  → Api::selesaikanTransaksiKasir()
  → TransaksiModel::ubahStatus()  (app/Models/TransaksiModel.php:73)
```

Explain **why** a change is needed rather than merely restating what the code does.

# 6. Validation, Error Handling & Security

Validate all inputs crossing a trust boundary.

Consider, when relevant:

* `null`;
* empty input;
* incorrect type;
* negative values;
* zero;
* oversized strings;
* malformed input;
* unauthorized access;
* concurrent requests;
* network failure;
* permission failure;
* database failure;
* timeout;
* memory/resource limits.

Never:

* hardcode credentials;
* commit secrets;
* expose sensitive information in logs, responses, or error messages;
* broaden permissions without a legitimate requirement;
* directly interpolate untrusted input into database queries or commands when a safe mechanism exists;
* silently ignore errors that can cause data loss.

Follow **least privilege**.

# 7. Database & Data Integrity

A POS system contains financially significant data. Database-related changes require explicit impact analysis.

Before changing database behavior, inspect relationships between:

* migration/schema;
* model;
* query;
* validation;
* controller/service;
* transaction handling;
* seed data;
* existing data;
* callers and consumers.

For changes affecting multiple records or state transitions, consider database transactions and rollback behavior.

Do not perform destructive database operations without explicit user approval.

Rules in this document and `docs/deploy.md` that restrict the agent from acting directly on a
production environment (`docs/deploy.md` §intro, `.kilo/rules/sdlc.md` §4) can be overridden by
explicit, per-request user approval. The override applies only to that specific request, not to
future sessions or releases; each new production-affecting action needs its own explicit approval.

Do not change schema without checking compatibility with code that consumes the schema.

# 8. API, Routes & Backward Compatibility

Before changing:

* route;
* request format;
* response format;
* API contract;
* public method;
* database schema;
* shared behavior;
* integration contract;

search for all known callers and consumers.

Identify breaking changes before implementation.

If a breaking-change risk exists and compatibility requirements are unclear, ask the user.

# 9. Gateway ↔ POS Cross-Repository Contract

The POS and WA-Gateway repositories are related systems.

Any change affecting communication between them is a **cross-repository change**.

Inspect both sides when the change affects:

* endpoint paths;
* request payloads;
* response payloads;
* authentication;
* message delivery semantics;
* retry behavior;
* message state;
* media behavior;
* operation identifiers;
* error semantics.

Do not modify one side while assuming the other side remains compatible.

Cross-repository contract changes require explicit user approval before implementation.

When both repositories are available, verify the provider and consumer. When the other repository is not available in the current workspace, state that the other side is **not yet verified**.

# 10. Testing & Verification

Every non-trivial change must leave at least **one executable verification** that would fail if the important logic were broken.

Use the smallest relevant mechanism:

* existing unit test;
* integration test;
* existing test suite;
* assert-based self-check;
* command-level verification;
* manual browser verification for UI behavior.

Do not create a new testing framework or infrastructure for a small verification when existing tooling is sufficient.

Do not claim a test passed unless it was actually run.

# 11. Git & Workspace Hygiene

Do not:

* delete user changes;
* overwrite work not created by the agent;
* perform destructive Git operations without explicit approval;
* use `reset`, `checkout`, `restore`, `clean`, or similar destructive operations without confirmation;
* modify unrelated files.

Keep changes focused and reviewable.

Do not perform mass formatting or unrelated refactoring.

# 12. Dependency Management

Before adding a dependency, check:

1. existing repository code;
2. standard library;
3. native platform features;
4. already-installed dependencies.

Add a new dependency only when genuinely necessary.

When adding one:

* explain why;
* consider maintenance and security cost;
* avoid adding one for a trivial problem.

# 13. Source of Truth

The repository is the primary source of truth for project knowledge that must persist across sessions.

If information conflicts between:

* user instructions;
* `AGENTS.md`;
* project documentation;
* business rules;
* existing code;
* migrations/schema;

do not silently choose a side when the conflict can affect behavior, data, security, or compatibility.

Identify the conflict and ask the user.

Do not treat conversation history or ChatGPT memory as authoritative project documentation.

# 14. Persistent Project Knowledge & Memory

Do not rely on ChatGPT memory to preserve:

* business rules;
* architecture decisions;
* API contracts;
* database structure;
* configuration assumptions;
* technical decisions;
* important requirements.

Information that must persist across sessions must be documented in the repository in the appropriate location, such as:

* `AGENTS.md`;
* `docs/`;
* architecture documentation;
* changelog;
* source code when it is part of the implementation contract.

A new agent should be able to understand the project without depending on previous conversations.

# 15. Business Rule Changes

Any business-rule change must:

1. be explicitly identified;
2. be checked against affected implementation;
3. be documented in `docs/CHANGELOG.md`.

Business/domain documentation must use **Bahasa Indonesia**, unless explicitly requested otherwise.

Technical and architecture documentation should use **English**.

Do not change business rules based on assumptions.

# 16. Code Comments

Code comments must be written in **English**.

Add comments only when the reason, constraint, or non-obvious behavior is not clear from the code.

Do not write comments that merely restate the implementation.

For deliberate simplifications with a meaningful known limitation:

```text
ponytail: <constraint / ceiling>; upgrade path: <future improvement>
```

# 17. Commit Messages

Commit messages must be written in **English**.

Use the repository's existing commit convention when one is established.

Commit messages should explain:

* what changed;
* why it changed.

For business-rule changes, reference the relevant `docs/` change when appropriate.

# 18. Final Report

After implementation, keep the final report to **2–4 lines**:

* what changed and where;
* what verification/test was run and the result;
* anything that remains unverified.

Do not repeat the diff or write a long summary.

# 19. Instruction Priority

When rules conflict, use this priority order:

1. **Correctness & data integrity**
2. **Security**
3. **Explicit user requirements**
4. **Documented business rules and project behavior**
5. **Cross-repository contract compatibility**
6. **Backward compatibility**
7. **Existing architecture and project conventions**
8. **YAGNI / minimum implementation**
9. **Code elegance and optimization**

If a conflict remains unresolved and can materially affect behavior, data, security, or compatibility, ask the user instead of guessing.

# 20. Completion Checklist

Before declaring the task complete:

* [ ] Requirement is understood.
* [ ] Relevant callers and consumers were checked.
* [ ] Root cause was addressed for bug fixes.
* [ ] Business rules were checked when relevant.
* [ ] Database impact was checked when relevant.
* [ ] Cross-repository impact was checked when relevant.
* [ ] No unnecessary abstraction or dependency was introduced.
* [ ] Validation and error handling were considered.
* [ ] Security and data integrity were checked.
* [ ] Breaking changes were checked when relevant.
* [ ] No unrelated files were changed.
* [ ] Required executable verification was run.
* [ ] Business-rule documentation was updated when required.
* [ ] Verification results are reported truthfully.
* [ ] No unverified claim is presented as verified.

# 21. TODO / Backlog

`docs/TODO.md` is the single, central backlog.

* Read `docs/TODO.md` at the **start** of every session, before working, and name
  the items relevant to the user's request.
* When an item is finished and verified, propose deleting its line and ask the
  user for explicit approval before removing it from `docs/TODO.md`. Do not
  delete a line unilaterally. Once approved, delete it and reference that
  item's **ID** in the commit message.
* New findings discovered while working must be recorded there, not only in chat.
* Do not create a second TODO list elsewhere; checkpoints and other documents
  only point to this file.
