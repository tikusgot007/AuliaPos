# Planning Documents

This folder holds **plans that are still relevant to the roadmap** — plans that
are `Planned`, `In progress`, or `Frozen` (paused by an owner decision but kept
on purpose, for example as correlation material if a real problem later needs to
be mapped to one of their tickets).

## Convention

- A plan that has reached `status: Completed` (with its APPROVAL gate closed)
  is **removed** from this folder during housekeeping, so the folder never
  becomes a graveyard and a new planning session can see at a glance what is
  still live.
- Removing a plan does **not** remove it from the project. Git keeps the full
  history, so the document is always recoverable.

## Recovering a removed plan

Any plan that was ever committed here can be printed from history by path:

```bash
git log --oneline -- plan/plan-refactor-teruskan-tahap4-v1.0.md
git show <commit>:plan/plan-refactor-teruskan-tahap4-v1.0.md
```

To restore a file into the working tree:

```bash
git restore --source=<commit> -- plan/plan-refactor-teruskan-tahap4-v1.0.md
```

## What happened on 2026-09-29

34 `Completed` plans were deleted from this folder at the owner's request.
Because of that, every reference to a removed plan across the repository
(`spec/`, `docs/audit/`, `docs/decisions/`, `docs/handoff-*`, and code
docblocks in `app/` and `tests/`) was rewritten to the **bare filename without
the `plan/` prefix**.

> [!IMPORTANT]
> A bare `plan-*.md` name in a document is therefore an **archived plan**,
> not a broken link. Locate it with the `git log` / `git show` commands above.

### Removed on 2026-09-29

- `plan-bugfix-inbox-last-message-at-monotonic-v1.0.md`
- `plan-bugfix-inbox-media-unavailable-v1.0.md`
- `plan-bugfix-inbox-message-ordering-v1.0.md`
- `plan-bugfix-inbox-test-db-isolation-v1.0.md`
- `plan-bugfix-outgoing-idempotency-f1-f2-v1.0.md`
- `plan-bugfix-wa-gateway-pairing-code-logged-out-v1.0.md`
- `plan-bugfix-wa-gateway-viewonce-unsupported-v1.0.md`
- `plan-feature-balas-pesan-auliapos-v1.0.md`
- `plan-feature-balas-pesan-wa-gateway-v1.0.md`
- `plan-feature-grup-tahap1-v1.0.md`
- `plan-feature-grup-tahap2-auliapos-v1.0.md`
- `plan-feature-grup-tahap2-wa-gateway-v1.0.md`
- `plan-feature-m3-operational-inbox-fase1-v1.0.md`
- `plan-feature-m3-operational-inbox-fase2a-v1.0.md`
- `plan-feature-teruskan-auliapos-v1.0.md`
- `plan-feature-teruskan-wa-gateway-v1.0.md`
- `plan-process-m1-wave1-incoming-reliability-v1.0.md`
- `plan-process-m1-wave2-outgoing-idempotency-v1.0.md`
- `plan-refactor-balas-pesan-tahap3-review2-v1.0.md`
- `plan-refactor-balas-pesan-tahap3-v1.0.md`
- `plan-refactor-grup-tahap2-identitas-v1.0.md`
- `plan-refactor-inbox-media-read-authorization-v1.0.md`
- `plan-refactor-m1-wave1-incoming-reliability-v1.0.md`
- `plan-refactor-m3-fase1c-inbox-screen-v1.0.md`
- `plan-refactor-m3-fase1e-message-search-v1.0.md`
- `plan-refactor-m3-fase2a-handoff-collision-v1.0.md`
- `plan-refactor-sender-identity-label-hardening-v1.0.md`
- `plan-refactor-teruskan-media-bound-v1.0.md`
- `plan-refactor-teruskan-media-bound-v1.1.md`
- `plan-refactor-teruskan-media-bound-v1.2.md`
- `plan-refactor-teruskan-media-bound-v1.3.md`
- `plan-refactor-teruskan-media-bound-v1.4.md`
- `plan-refactor-teruskan-tahap4-followup-v1.0.md`
- `plan-refactor-teruskan-tahap4-v1.0.md`
