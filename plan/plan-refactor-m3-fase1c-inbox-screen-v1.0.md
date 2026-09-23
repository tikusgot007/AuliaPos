---
goal: Fix the two REQUIRED findings from the code review of commit f3bd8fa (M3 Fase 1c Inbox screen)
version: 1.0
date_created: 2026-09-24
owner: AuliaPos Inbox module
status: "Planned"
tags: ["refactor", "clean-code", "test-quality", "inbox", "m3"]
---

# Introduction

![Status: Planned](https://img.shields.io/badge/status-Planned-blue)

The `/sdlc-code-review` of commit `f3bd8fa` (plan `plan-feature-m3-operational-inbox-fase1-v1.0.md` Phase 3, TASK-015..018) found no spec mismatch and no security issue. It found two REQUIRED issues on the Standards axis:

1. **Double submit.** Reopening the Catatan Internal dialog while a save is still running resets the in-flight guard.
2. **Tests that prove nothing.** Some assertions in `tests/session/OperationalInboxScreenTest.php` already passed before the commit.

This plan fixes only these two. Nothing changes in the backend, routes or migrations.

## 1. Traceability: Requirements & Constraints

- **REQ-001**: The dialog can be closed (Batal, ✕ or Esc) while a note is saving and then reopened. When that happens, it must not reset `catatanInternalSedangKirim`, re-enable the Save button or clear the text. A second note must not be sent while the first is in flight. When the first request finishes, it must not hide the dialog or clear text the user typed after reopening. (Review finding A-1, `app/Views/inbox/index.php:1275-1281`, `1308-1322`.)
- **PRN-001**: Every screen render test must fail when the feature it names is removed. (Review finding A-2 / B-NIT-1.)
  - These assertions already passed before `f3bd8fa`: `"'/catatan'"`, `bg-success`, `bg-warning` and `bg-danger`.
  - `bukaModalCatatanInternal()` also matches the function definition itself, not only the header button.
- **CON-001**: The change is limited to `app/Views/inbox/index.php` and `tests/session/OperationalInboxScreenTest.php`. It must not change any behavior already accepted in AC-010..AC-012 or the 8/8 manual check from TASK-018 (b).
- **CON-002**: Keep the fix minimal. Do not add a JS test runner and do not restructure the modal code.

## 2. Implementation Steps

> **⚠️ EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> You MUST execute this plan phase by phase. You MUST run the specific testing/verification task at the end of each phase. After a phase is tested, you **MUST STOP AND WAIT** for the user's explicit approval before proceeding to the next phase. **DO NOT SKIP PHASES.**

### Implementation Phase 1: In-flight guard and meaningful render tests

- **GOAL-001:** A note can never be sent twice, and text typed after reopening is never lost. The render tests fail if the Fase 1c screen pieces are removed.

| Task ID  | Description (Include Exact File Paths & Micro-Testing) | Ref ID  | Completed | Date |
| -------- | ------------------------------------------------------ | ------- | :-------: | :--: |
| TASK-101 | In `app/Views/inbox/index.php`, `bukaModalCatatanInternal()`: if `catatanInternalSedangKirim` is `true`, only call `.show()` on the modal and return. Do not clear the textarea, reset the flag or re-enable the button. The reset logic for the normal case stays as it is. | REQ-001 | [ ] | |
| TASK-102 | In `app/Views/inbox/index.php`, `simpanCatatanInternal()` success branch: hide the modal and clear the textarea only if the dialog still holds the text that was sent (`textarea.value.trim() === teks`). Otherwise leave the dialog and the text alone. Keep the success toast and `muatUlangPesan(true)`. | REQ-001 | [ ] | |
| TASK-103 | In `tests/session/OperationalInboxScreenTest.php`, replace the weak assertions:<br>• Remove `"'/catatan'"`, `bg-success`, `bg-warning` and `bg-danger`.<br>• Assert `onclick="bukaModalCatatanInternal()"` (the button, not the function definition).<br>• Assert `id="btnSimpanCatatanInternal"`, `class="inbox-sla-dot` or `.inbox-sla-dot`, and `SLA_WARNA`.<br>• Keep `renderTitikSla(c.sla_color)`, the `#inputCariConversation` + `maxlength="255"` regex and `'&q='`.<br>• Micro-test: temporarily remove the "Catatan Internal" button string and confirm the test fails (Red), then restore it (Green). | PRN-001 | [ ] | |
| TASK-104 | **VERIFY**:<br>(a) `vendor/bin/phpunit --no-coverage tests/session/OperationalInboxScreenTest.php` passes.<br>(b) The full `composer test` passes 100% (baseline 317/317).<br>(c) Manual browser check: open Catatan Internal, type text, press Simpan, immediately press Batal, then reopen. The Save button must still be disabled and the dialog must show no reset text. When the save finishes, exactly one note appears in the thread. Also repeat one normal note save to confirm AC-010 (a) still works. | - | [ ] | |
| TASK-105 | **APPROVAL**: 🛑 Wait for explicit user confirmation that Phase 1 is done. | - | [ ] | |

## 3. Structural Remedies & Alternatives

- **ALT-001**: Disable the dialog's close buttons and Esc key while a save is running. Rejected because it needs more code (backdrop/keyboard options on the Bootstrap modal) than the guard in TASK-101.
- **ALT-002**: Add a JS test runner to test the dialog behavior automatically. Rejected because it is out of scope (CON-002). The behavior is checked by hand in TASK-104 (c).
- **Deferred (NIT/FYI, not in this plan; record as TODOs):**
  - After a failed search, put the old keyword back into `#inputCariConversation` (both reviewers flagged this).
  - Rename `SNOOZE_ALASAN_MAKS_BYTE` to a neutral name.
  - The toast says "karakter" but the check counts bytes.
  - `maxlength="4096"` silently cuts pasted text longer than 4096 characters. The same pattern exists in the other textareas.
  - CSRF is off app-wide (`app/Config/Filters.php:67`), a separate security TODO.
  - `showToast` uses `innerHTML` (`app/Views/layout/main.php:1288`).
  - Wrap the `setInterval` polling callback explicitly.

## 4. Dependencies

- **DEP-001**: None added or removed.

## 5. Files Affected

- **FILE-001**: `app/Views/inbox/index.php`, the in-flight guard in `bukaModalCatatanInternal()` and `simpanCatatanInternal()` (TASK-101, TASK-102).
- **FILE-002**: `tests/session/OperationalInboxScreenTest.php`, stronger render assertions (TASK-103).

## 6. Testing Strategy

- **TEST-001**: The render tests fail when the Catatan Internal button, the SLA dot or the search box is removed (TASK-103 micro-test).
- **TEST-002**: A manual browser check covers the close-and-reopen sequence during a save (TASK-104 c), because there is no JS test runner.
- **TEST-003**: Run the full `composer test` as the regression gate.

## 7. Risks & Rollback Plan

- **RISK-001**: TASK-102 compares the trimmed text. If the user reopens the dialog and types exactly the same text, the dialog closes on success. That is acceptable, because the same note was just saved.
- **RISK-002**: This is a screen-only change with no data impact. Roll back with `git revert` of the fix commit.
