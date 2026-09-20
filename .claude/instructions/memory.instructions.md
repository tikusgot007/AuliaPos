# Project Memory Log

> This file is managed by the `memory-manager` skill.
> It persists context across AI chat sessions to prevent knowledge loss.
> Do NOT manually edit this file unless necessary.

---

## 📝 Session Checkpoint: 2026-09-21

- **Active Memory Path:** `.claude/instructions/memory.instructions.md`
- **Current SDLC Phase:** Documentation (`/sdlc-generate-docs`, Diátaxis Explanation)
- **Active Artifacts:**
  - `docs/explanation/pos-inti-alasan-desain.md` — Status: 🔄 Draft complete, not yet linted or committed
- **Achieved Milestones:**
  - Clarified scope with the user, one question at a time: audience (developer > admin > cashier), quadrant (Explanation), module (POS core), format (Markdown), language (full Indonesian), no `CONTEXT.md`.
  - Scanned `docs/AULIA.md` and `TransaksiModel` (`ubahStatus`, `tambahPembayaran`, `sinkronkanPembayaran`), then wrote an 11-section Explanation document approved by the user.
- **Updated Files:**
  - `docs/explanation/pos-inti-alasan-desain.md` — new Explanation doc (created)
- **Decisions Made:**
  - Where `CLAUDE.md` and `docs/AULIA.md` disagree (Shift Leader may finish via `/api/ubah-status`; backdate is Section 4 not 11), follow `docs/AULIA.md` and the code.
  - Documentation is written in Indonesian, overriding the English default in `AGENTS.md`, by explicit user choice.
  - `CONTEXT.md` intentionally not created.
- **Next Action / Pending:**
  - Run a markdown lint pass on the new doc and commit it (not done yet).
  - Diskon rules (`KalkulasiDiskonTransaksi`) and tagihan/jatuh-tempo rules in the doc come from `CLAUDE.md`/`AULIA.md`, not verified line by line in code.
  - Optional: add rejected alternatives for "Opsi B" if found in `docs/CHANGELOG.md`.
  - Optional next doc: How-to (admin/cashier tasks such as payment method correction), in a new session.
  - `CLAUDE.md` is stale on Shift Leader and backdate section numbering; fix via `/code-janitor`.
  - `AGENTS.md` records a stale memory path (`.agents/instructions/...`); the real file is `.claude/instructions/`.

<!-- checkpoint-tail: Explanation doc for POS core written at docs/explanation/pos-inti-alasan-desain.md; needs lint, commit, and optional CHANGELOG check for Opsi B alternatives. -->

---
