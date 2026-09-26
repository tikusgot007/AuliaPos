---
goal: Harden SenderIdentityFormatter so it never emits a raw JID and classifies sender domains case-insensitively
version: 1.0
date_created: 2026-09-26
last_updated: 2026-09-26
owner: AuliaPos Inbox module
status: "Planned"
tags: ["refactor", "clean-code", "architecture", "security"]
---

<!-- markdownlint-disable -->

# Introduction

![Status: Planned](https://img.shields.io/badge/status-Planned-blue)

Follow-up to the Phase 4 code review of `plan-refactor-grup-tahap2-identitas-v1.0.md`
(REQ-011/AC-012, label-safe legacy group messages). Phase 4 itself is
**spec-compliant**: `@g.us` returns `null`, the raw group JID is not rendered,
and the read path does not mutate the DB.

This plan remediates one **latent invariant gap** in
`app/Services/SenderIdentityFormatter.php` surfaced by the Two-Axis review: for a
crafted `s.whatsapp.net` local part that itself contains `@`, the formatter emits
the raw prefix (e.g. `120363@g.us`) as the sender label, defeating its own
"never return a raw JID" contract and the intent of REQ-011. It also normalizes
domain classification case and strengthens the test oracle.

> [!NOTE]
> Reachability from the real WA-Gateway is nil (Baileys `key.participant` is always
> `<digits>[@lid|@s.whatsapp.net]`, never a local part containing `@`). The fix is
> defense-in-depth for the display trust boundary, and it must **not** add strict
> JID validation to the gateway `400` guard (ALT-001).

## 1. Traceability: Requirements & Constraints

- **REQ-001**: `labelFor()` MUST NOT return a raw JID. For domain `s.whatsapp.net`,
  the phone part MUST match `^\d+$`; anything else (including any `@` in the local
  part) falls back to `'Pengirim'`.
- **REQ-002**: Domain classification MUST be case-insensitive for
  `s.whatsapp.net`, `lid`, and `*.lid` (hostnames are case-insensitive):
  `@S.WHATSAPP.NET` -> phone; `@LID`/`@Hosted.LID` -> `'LID'`.
- **REQ-003**: `@g.us` (case-insensitive) MUST return `null` (no identity) for any
  local part, including an empty local (`@g.us`), consistent with REQ-011.
- **PRN-001**: Keep the class pure and stateless (no DB, session, request, or
  static mutable state).
- **PRN-002**: Domain literals and the device separator SHOULD be named constants.
- **SEC-001**: Tests MUST assert the emitted label contains no `@` and is not the
  raw input (replace the weak `assertNotSame` oracle).
- **CON-001**: Do NOT add strict JID validation to the gateway `400` guard --
  REQ-010/ALT-001 keep it lenient (non-empty only); the fix lives entirely in the
  formatter.
- **CON-002**: Personal and outgoing behavior unchanged; the read path never
  mutates the DB.

## 2. Implementation Steps

> **EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> Execute this plan phase by phase. Run the VERIFY task at the end of every phase.
> After a phase is verified, **STOP AND WAIT** for the user's explicit approval
> before the next phase. **DO NOT SKIP PHASES.**
> Definition of Done: `vendor/bin/phpunit --no-coverage` exits 0.

### Implementation Phase 1: Formatter Hardening

- **GOAL-001**: `labelFor()` never emits a raw JID, classifies domains
  case-insensitively, and treats any `g.us` domain as no-identity.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                                        | Ref ID  | Completed |    Date    |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------- | :-------: | :--------: |
| TASK-101 | In `app/Services/SenderIdentityFormatter.php`, in the `s.whatsapp.net` branch validate the phone part with `preg_match('/^\d+$/', $phonePart) === 1` after stripping the device suffix; otherwise `return self::LABEL_FALLBACK`. Makes `120363@g.us@s.whatsapp.net` -> `'Pengirim'`. | REQ-001 |    [ ]    |            |
| TASK-102 | Normalize `$domain = strtolower($domain);` once after extraction, then compare strictly: `s.whatsapp.net`, `lid`, `g.us`, and `str_ends_with($domain, '.lid')`. | REQ-002 |    [ ]    |            |
| TASK-103 | Evaluate the group-domain check so any `g.us` domain -- including an empty local (`@g.us`) -- returns `null` without leaking the value; malformed non-group inputs stay `'Pengirim'`. | REQ-003 |    [ ]    |            |
| TASK-104 | Extract constants: `DOMAIN_WHATSAPP = 's.whatsapp.net'`, `DOMAIN_GROUP = 'g.us'`, `DOMAIN_LID_SUFFIX = '.lid'`, `DEVICE_SEPARATOR = ':'`. | PRN-002 |    [ ]    |            |
| TASK-105 | Extend `tests/unit/SenderIdentityFormatterTest.php`: crafted `120363@g.us@s.whatsapp.net` -> `'Pengirim'`; `6281234567890@S.WHATSAPP.NET` -> phone; `999@LID`/`999@hosted.LID` -> `'LID'`; `@g.us` -> `null`; and for every case assert `assertStringNotContainsString('@', $label)`. | REQ-001, REQ-002, REQ-003, SEC-001 | [ ] | |
| TASK-106 | **VERIFY**: `vendor/bin/phpunit --no-coverage --filter SenderIdentityFormatterTest` exit 0, then full suite exit 0. | -       |    [ ]    |            |
| TASK-107 | **APPROVAL**: 🛑 Wait for explicit user confirmation to proceed to Phase 2. | -       |    [ ]    |            |

### Implementation Phase 2: Session-level Regression Lock

- **GOAL-002**: The thread API locks the "no raw JID in `sender_name`" property
  end to end.

| Task ID  | Description (Include Exact File Paths & Micro-Testing)                                                                                                        | Ref ID  | Completed |    Date    |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------- | :-------: | :--------: |
| TASK-201 | In `tests/session/InboxGrupTahap2Phase2Test.php`, add a crafted-JID case (`120363@g.us@s.whatsapp.net`) to the label matrix and assert the returned `sender_name` never contains `@` and is not the raw value. | SEC-001 |    [ ]    |            |
| TASK-202 | **VERIFY**: full suite exit 0; AC-012/AC-006/AC-008/AC-011 regressions stay green. | -       |    [ ]    |            |
| TASK-203 | **APPROVAL**: 🛑 Wait for explicit user confirmation before closing. | -       |    [ ]    |            |

## 3. Structural Remedies & Alternatives

- **ALT-001**: Add strict JID-format validation at the gateway `400` guard --
  REJECTED: REQ-010/ALT-001 keep the guard lenient (non-empty only); the formatter
  validates its own input instead.
- **ALT-002**: Keep inline domain literals -- rejected in favour of named
  constants (PRN-002).
- **Structure chosen**: a single normalized domain and a numeric-only allowlist
  inside the pure formatter; no new class or dependency.

## 4. Dependencies

- **DEP-001**: None. Uses only `preg_match`, `strtolower`, `str_ends_with`, and
  `explode` from the PHP standard library.

## 5. Files Affected

- **FILE-001**: `app/Services/SenderIdentityFormatter.php` -- numeric allowlist,
  case normalization, group-domain precedence, constants.
- **FILE-002**: `tests/unit/SenderIdentityFormatterTest.php` -- crafted-JID,
  uppercase-domain, empty-local-group cases + `@`-free oracle.
- **FILE-003**: `tests/session/InboxGrupTahap2Phase2Test.php` -- crafted-JID case
  at the API boundary.

## 6. Testing Strategy

- **TEST-001**: Crafted local part containing `@` -> `'Pengirim'`, never the raw
  JID (`SEC-001`).
- **TEST-002**: Uppercase domain variants (`@S.WHATSAPP.NET`, `@LID`,
  `@Hosted.LID`) classify correctly (`REQ-002`).
- **TEST-003**: Empty-local `@g.us` -> `null` (`REQ-003`).
- **TEST-004**: Full regression suite (`vendor/bin/phpunit --no-coverage`) exits 0,
  including AC-012/AC-006/AC-008/AC-011.

## 7. Risks & Rollback Plan

- **RISK-001 (low)**: Tightening the `s.whatsapp.net` branch could reject a
  legitimate local part. Mitigation: validate the phone part as `^\d+$` after
  stripping the `:NN` device suffix only; keep the fallback non-fatal.
  Rollback: revert the formatter to the previous revision.
- **RISK-002 (low)**: Case normalization changes labels for uppercase domains from
  `'Pengirim'` to the correct value. No consumer depends on the previous value;
  rollback is a one-line revert.
- **Rollback (general)**: The change is confined to one pure class plus tests;
  revert the commit per phase.
