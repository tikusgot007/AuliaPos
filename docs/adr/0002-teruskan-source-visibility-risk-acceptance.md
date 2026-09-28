# 0002 - Accept the Teruskan source-visibility gap

**Date:** 2026-09-28  
**Status:** Accepted  

## Context

`Inbox::resolveTeruskan()` (`app/Controllers/Inbox.php`) resolves a forwarded source by local `messages.id` and never checks that the source conversation is owned by, or visible to, the acting user. A user allowed to send to the target conversation can therefore probe the existence (and content) of a message that lives in a conversation they cannot open. The behaviour is deliberate: `spec/spec-design-teruskan.md` (REQ-007 / AC-004) requires forwarding whichever message the cashier can currently see, without a source-conversation ownership gate. Adding a code guard would contradict the approved specification.

## Decision

We accept the source-visibility gap as a recorded risk and do **not** add an ownership check on the forwarded source. Forwarding keeps resolving the source snapshot by local `messages.id`; detection relies on the audit logs already written in `resolveTeruskan()`.

## Consequences

A cashier with send rights on the target conversation can learn whether a given local message id exists and forward its content, independent of the source conversation's ownership. The exposure is bounded: client-supplied ids are not listed anywhere in the UI, the source id must be individually known, and every accepted/denied attempt is logged. If the specification changes, this ADR must be superseded before a guard is added.

## Considered Options

- **Add a source-conversation ownership check (`cekOwnership()` on the source) — REJECTED.** It contradicts REQ-007/AC-004 and would break the approved forward flow for group and assigned conversations.
