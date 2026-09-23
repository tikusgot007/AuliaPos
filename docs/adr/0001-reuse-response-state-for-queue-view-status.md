# 0001 - Reuse `attachResponseState()` as the base layer for M3 Queue View status

**Date:** 2026-09-20
**Status:** Accepted

## Context

M3 (Operational Inbox) Layar 1 (Queue View) needs a computed conversation status across 5 tabs (Belum Diambil / Open / Menunggu / Ditunda / Selesai). Tahap A already shipped a computed `response_state` (`selesai` / `follow_up` / `perlu_dibalas` / `menunggu_customer`) via `Inbox::attachResponseState()`, live in `v2.2`. Building the Queue View status as an independent computation would create two parallel status engines over the same underlying data (`status`, `assigned_to`, `last_message_direction`, `last_message_at`, `snoozed_until`), risking drift between what the sidebar badge (Tahap A) and the Queue View (M3) show for the same conversation.

## Decision

`ConversationModel::withComputedStatus()` (the new M3 Queue View status logic) reuses `attachResponseState()` as its base layer, then layers ownership (`assigned_to` → Belum Diambil vs Open) and snooze/closed state on top. It does not reimplement the reply-urgency logic from scratch.

## Consequences

Queue View inherits `attachResponseState()`'s existing edge-case handling (e.g., "Tandai Dibaca", temporary follow-up hiding) for free, but couples the two features: any future change to `attachResponseState()`'s reply-urgency rules will also shift Queue View tab membership. Internal Note rows (`is_internal = TRUE`, introduced in Fase 1b) must be excluded from the inputs to this computation (`last_message_at` / `last_message_direction`) to avoid corrupting both the existing badge and the new Queue View tabs.
