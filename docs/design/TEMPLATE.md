# Design: <short title>

Copy to `docs/design/YYYY-MM-DD-<slug>.md`. English (technical doc, AGENTS.md §2.1).

- **Date**: YYYY-MM-DD
- **Status**: draft | approved | superseded
- **Requirements**: `docs/requirements/YYYY-MM-DD-<slug>.md`
- **SDLC tier**: A | B | C

## 1. Summary

One or two sentences describing the chosen approach.

## 2. Current flow (verified)

Call chains with `file:line` references, e.g.:

    POST /api/...
      -> Controller::method()   (app/Controllers/X.php:NN)
      -> Model::method()        (app/Models/Y.php:NN)

List callers and consumers of anything that will change. Mark anything not yet verified.

## 3. Options

Maximum 3. Always give one recommendation.

- **A. <name>**: <main consequence / trade-off>
- **B. <name>**: <main consequence / trade-off>

**Recommendation:** A, because <most important reason>.

## 4. Planned changes

| File | Change | Reason |
|---|---|---|
| `app/...` | | |

## 5. Impact

- **Database / migrations** (both `default` and `inbox` groups if relevant):
- **Routes / API / response formats** (breaking?):
- **Gateway contract** (cross-repo; other side verified?):
- **Existing data**:
- **Security / validation at trust boundaries**:
- **Transactions / concurrency / rollback behavior**:

## 6. Test plan

| Acceptance criterion | Test (file::method) | Type |
|---|---|---|
| AC-1 | `tests/unit/...::test...` | unit |

## 7. Risks and mitigations

- <risk> -> <mitigation>

## 8. Not yet verified

- <item>

## 9. Approval (Gate 2)

- [ ] Approved by: <name>, date: <YYYY-MM-DD>
