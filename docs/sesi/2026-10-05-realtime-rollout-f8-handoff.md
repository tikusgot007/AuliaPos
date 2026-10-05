# Sesi 2026-10-05 — Rollout Inbox Realtime + Handoff Gate F8

Status: **Realtime resmi sudah ter-merge/deploy (lokal). F8 (edit pesan) belum resmi — gate belum clear. Lanjutkan gate F8 di sesi berikutnya.**

---

## 1. Yang sudah selesai sesi ini

- **Rollout Inbox Realtime**
  - AuliaPos `v2.4` = `3401ea7` (merge realtime + docs TODO) — terpush ke `origin/v2.4`.
  - WA-Gateway `evolution` = `ba4bcf3` (realtime: `message.created`, heartbeat, shutdown cleanup, broadcast failure isolation) — terpush ke `origin/evolution`.
- **Uji sistem**: E2E, failure matrix, burst/coalesce, reconnect, performance, regresi — PASS (soak hanya bounded ~2.5 menit; lihat TODO-REALTIME-SOAK).
- **F8 (edit pesan) dijaga jalan di lokal**:
  - Gateway test lokal `C:\AuliaGateway-test\evolution-gateway` (`:3000`, pid 27024) menjalankan **build gabungan F8 + realtime** → fitur edit pesan dan inbox realtime dua-duanya jalan.
  - Build gabungan = merge `evolution` + `todo-f8-capture-fixture`, dipush sebagai branch **`integration/realtime-f8` = `e653de5`** (lokal + `origin`). **Bukan** `evolution`.
  - Fixture edit di-capture ulang (valid); suite gabungan `npm test` lulus (catatan: menjalankan test di folder deploy bisa 401 karena `.env` memuat `EVOLUTION_WEBHOOK_SECRET`).

## 2. Kondisi repo terkini

| Repo | Branch | SHA | Catatan |
|---|---|---|---|
| AuliaPos | `v2.4` | `3401ea7` | = `origin/v2.4` |
| WA-Gateway | `evolution` | `ba4bcf3` | realtime resmi, **tanpa F8** |
| WA-Gateway | `integration/realtime-f8` | `e653de5` | gabungan F8+realtime, lokal+origin |
| WA-Gateway | `todo-f8-capture-fixture` | `55e963a` | F8, PR #5 masih Draft |

Gateway test lokal: adapter `:3000` pid 27024 (build gabungan); PG `:5433`; Evolution `:8080`. Capture fixture edit = OFF (`EVOLUTION_CAPTURE_EDIT_FIXTURE=0`); dekripsi edit = ON (`EVOLUTION_DECRYPT_MESSAGE_EDIT=1`).

## 3. Gate F8 — sisa pekerjaan (juga di `docs/TODO.md` TODO-F8-G1..G6)

Rujukan: `docs/laporan-keputusan-todo-f8-dekripsi-pesan-edit.md`, `docs/sesi/2026-10-04-promosi-aulia-htaccess-todo-f8-e2e.md`.

- **G1 — Verifikasi produksi `aulia3`**: cek versi Evolution produksi; pasang patch LID (`installer/apply-lid-preservation-patch.ps1`) + deploy gateway F8 + POS PR #49 ke `aulia3`; uji 1 edit WhatsApp nyata E2E. *(butuh akses aulia3)*
- **G2 — Phase 2 UI**: kerjakan **atau** putuskan tunda eksplisit. Sinyal `is_edited_text_resolved` + styling final (biar teks hasil edit valid tidak tampil "samar" ala F7). *(butuh keputusan user; Tier A — requirement/design)*
- **G3 — Fix bug self-check** `installer/apply-lid-preservation-patch.ps1:93` (regex tanpa cast `(messageRaw.key as any)` → salah lapor "GAGAL" walau patch sukses). *(non-produksi; bisa dikerjakan lebih dulu)*
- **G4 — Verifikasi visual** label "Pesan diedit"/"Pesan dihapus" di browser.
- **G5 — Merge PR**: naikkan PR #5 (WA-Gateway) & PR #49 (AuliaPos) dari Draft → merge setelah G1+G2.
- **G6 — Integrasi lokal**: `integration/realtime-f8` sudah dipush; keputusan akhirnya: merge ke `evolution` setelah gate clear (jangan sebelum G1+G2).

## 4. Urutan rekomendasi sesi berikutnya

1. **G3** (fix bug patch — non-produksi, kecil, jelas).
2. **G2** (keputusan Phase 2 UI: kerjakan atau tunda).
3. **G1** (verifikasi aulia3) setelah G2/G3.
4. **G4** (visual), lalu **G5** (merge PR).

## 5. Titik masuk

- Baca: file ini, `docs/TODO.md` (TODO-F8-G1..G6), `docs/laporan-keputusan-todo-f8-dekripsi-pesan-edit.md`, `docs/sesi/2026-10-04-promosi-aulia-htaccess-todo-f8-e2e.md`.
- Referensi teknis WA-Gateway: `installer/apply-lid-preservation-patch.ps1`, `installer/tests/check-lid-preservation-patch.ps1`, `docs/evolution-lid-preservation-patch.md`.
