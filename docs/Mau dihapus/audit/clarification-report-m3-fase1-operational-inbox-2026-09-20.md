# 🔍 Clarification Report [Review Iteration 1]

**Target document:** `blueprint-m3-operational-inbox.md` (v1.0) + `Panduan_Layar_AuliaPos_M3.md`, upstream context: `status-proyek-master.md`.

**Readiness Score:** 92/100
**Status:** Good Enough (≥80)

**Score Breakdown:**
- **Completeness (max 40):** 36 — Kelima keputusan 🔶 yang ditandai blueprint sudah tuntas, plus 3 kontradiksi silang yang ditemukan selama interogasi (Internal Note vs computed status, snooze reason, duplikasi logika Tahap A vs Layar 1) juga sudah diputuskan. Fase 2 (Handoff/Collision) sengaja belum digali — di luar scope Fase 1a/1b, terkunci menunggu M2, bukan kelalaian.
- **Clarity (max 30):** 28 — Semua keputusan konkret (angka menit, nama method, kolom vs tidak), tidak ada bahasa kabur tersisa.
- **Alignment (max 30):** 28 — Konsisten dengan prinsip arsitektur proyek (`CLAUDE.md`: single source of truth, backend enforce role/ownership, additive migration).
- **Critical Flaw Veto:** Tidak — tidak ada kontradiksi fatal yang tersisa.

---

## 1. 🚨 Critical Findings (Blockers)

Tidak ada.

## 2. 🧩 Resolved Items & Agreements

- **🔶#1 Status granular Queue View** → Computed, satu sumber kebenaran di `ConversationModel::withComputedStatus()`, **reuse `attachResponseState()`** (Tahap A) sebagai lapisan dasar, tambah lapisan ownership + snooze + closed di atasnya. Dicatat sebagai **ADR-0001**.
- **🔶#2 Threshold SLA** → Hijau <15m, Kuning 15–60m, Merah >60m. Sumber waktu: `last_message_at`. Disimpan di `app/Config/Inbox.php` (bukan tabel setting).
- **🔶#3 Customer Context (Fase 1)** → Dibatasi data Inbox saja (nama, nomor, riwayat percakapan, internal note). Order/payment ditunda ke M4.
- **🔶#4 Internal Note** → Opsi A: kolom `is_internal` di `messages` (reuse struktur thread), migration additive di Fase 1b.
- **🔶#5 @mention** → Teks biasa di Fase 1, tanpa notifikasi nyata (tabel `message_mentions`). Ditunda ke Fase 2.
- **Internal Note vs computed status/SLA** → Internal Note **dikecualikan** dari perhitungan `last_message_at` / `last_message_direction` / SLA (filter `WHERE is_internal = FALSE` di semua query terkait) — mencegah baris Internal Note salah mereset tab Queue View atau SLA timer.
- **Snooze Reason** → Ikut skema Internal Note (Opsi A), bukan kolom `snooze_reason` baru. Konsekuensi disepakati: field "Alasan" di dialog Snooze **tidak tersedia di Fase 1a** (hanya durasi), baru aktif begitu migration `is_internal` masuk di Fase 1b.
- **Akses tulis Internal Note** → Terbuka untuk staff manapun (tidak dibatasi `cekOwnership()`), berbeda dari aturan balas/hapus percakapan yang membatasi ke assigned staff/admin.
- **(Verifikasi kode)** `apiConversations()` (`app/Controllers/Inbox.php:62`) saat ini tidak punya parameter filter/search — Layar 7 (Filter & Pencarian) memang butuh ekstensi endpoint, sesuai dugaan blueprint.
- **(Verifikasi kode)** Docblock di `Inbox::media()` (baris ~149-159) yang menyatakan "media tidak pernah disimpan permanen" adalah komentar basi dari sebelum Tahap C — kode aktual (baris 189-214) sudah benar mengecek `InboxMediaStorage` lebih dulu. Perlu diperbaiki teksnya saat file itu disentuh lagi; tidak mendesak, tidak berdampak fungsional.

## 3. ⚠️ Assumed / Auto-Resolved / Out of Scope

- **Fase 2 (Handoff, Collision detection, Auto-assignment)** — `[Out of Scope]` untuk sesi ini, terkunci menunggu M2 (ownership atomic `cekOwnership()` yang saat ini read-then-write, bukan atomic) selesai, sesuai roadmap `status-proyek-master.md`.
- **Fase 3 (AI features — intent filter, AI summary, suggested reply)** — `[Out of Scope]`, menunggu M5.

## 4. 📝 Next Steps

- Formalkan resolusi ini ke dokumen Spec resmi via `/sdlc-define-specs` (folder `/spec/` belum ada di repo, akan dibuat pertama kali).
- **ADR-0001** sudah dibuat: `docs/adr/0001-reuse-response-state-for-queue-view-status.md`.
- `CONTEXT.md` belum perlu dibuat — belum ada istilah domain baru yang butuh disepakati eksplisit di sesi ini.
- Migration Fase 1b yang perlu direncanakan di `/sdlc-plan-tasks`: kolom `is_internal` di `messages` (dengan filter wajib `is_internal = FALSE` di semua query computed status/SLA).
