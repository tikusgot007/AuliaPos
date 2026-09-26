---
title: Clarification Report — Plan Grup Tahap 1
date: 2026-09-26
stage: Plan (`plan-feature-grup-tahap1-v1.0.md`, referensi `spec-design-grup-tahap1-tab-inbox.md`)
source_spec: spec/spec-design-grup-tahap1-tab-inbox.md
source_plan: plan/plan-feature-grup-tahap1-v1.0.md
readiness_score: 82/100
status: PROCEED
---

# 🔍 Clarification Report [Review Iteration 1]

> [!SUCCESS]
> **REMEDIATION STATUS: RESOLVED (SPEC-SIDE + PLAN-SIDE)**
> The Specification Architect (2026-09-26) has written the spec-side resolutions of this report into `spec/spec-design-grup-tahap1-tab-inbox.md` **v1.1** as `REQ-007`, `REQ-008`, `REQ-009`, `CON-005`, `CON-006`, and `AC-008`..`AC-012`, plus explicit STOP instructions under `ASSUMPTION-001`. One additional owner decision extends `CON-006` to hide the ownership/lifecycle badges too.
> **Projected Spec Readiness Score:** 95/100 (self-assessment by the Specification Architect, not an independent audit).
>
> The Planner Architect (2026-09-26) has completed Next Step #2: `plan/plan-feature-grup-tahap1-v1.0.md` **v1.1** now inherits the new Ref IDs — TASK-001 carries the explicit STOP instruction, TASK-006 is expanded (tab position last + `QUEUE_STATUS_LABEL['grup']` + hidden badges/buttons per CON-005/CON-006), **TASK-006A** (REQ-007, Hapus exemption) and **TASK-006B** (REQ-008, auto-assign exemption) are new, and TASK-007 (VERIFY) is expanded with automated tests for Hapus + auto-assign plus manual checks for hidden badges/buttons and tab position. Still 1 Implementation Phase, dependency order preserved, no scope beyond Spec v1.1.
> **Projected Plan Readiness Score:** 94/100 (self-assessment by the Planner Architect, not an independent audit).
>
> This report's findings are now **fully closed** on both the spec side and the plan side.

**Readiness Score:** 82/100
**Status:** Good Enough (≥80)

**Dokumen diperiksa:** `plan/plan-feature-grup-tahap1-v1.0.md`, `spec/spec-design-grup-tahap1-tab-inbox.md`, disilangkan dengan kode aktual (`app/Controllers/Inbox.php`, `app/Models/ConversationModel.php`, `app/Views/inbox/index.php`).

**Score Breakdown:**

- **Completeness (max 40):** 30/40 — 6 gap signifikan ditemukan dan diresolusi sesi ini (lihat Resolved Items). Semua sudah diputuskan pemilik proyek, tetapi belum ditulis ke dokumen spec/plan resmi — itu pekerjaan `/sdlc-define-specs` dan `/sdlc-plan-tasks` berikutnya, bukan wewenang sesi klarifikasi ini.
- **Clarity (max 30):** 26/30 — mayoritas requirement sudah presisi dengan kutipan baris kode eksplisit. Satu item kecil (redundansi teks preview "group" vs badge "Grup") sengaja dibiarkan sebagai polish opsional, bukan diklarifikasi tuntas.
- **Alignment (max 30):** 26/30 — dua kontradiksi kode-vs-spec baru ditemukan (auto-assign, syarat closed-untuk-hapus) dan sudah diputuskan resolusinya, tetapi field/Ref ID resminya belum ada karena menunggu fase authoring.
- **Critical Flaw Veto:** Tidak — tidak ada kontradiksi fundamental yang tak terselesaikan.

---

## 1. 🚨 Critical Findings (Blockers)

Tidak ada. Semua temuan di bawah ini sudah mendapat keputusan pemilik proyek sesi ini.

## 2. 🧩 Resolved Items & Agreements

- **Requirement:** "RISK-001: Kalau nilai literal ternyata bukan `'group'`... filter di TASK-002/003/004/005 harus disesuaikan." (Plan Section 7)
  - **Resolution:** Kalau verifikasi TASK-001 menemukan nilai literal `jid_type` grup **berbeda** dari `'group'`, developer **wajib STOP** — jangan lanjut TASK-002. Amandemen dulu spec (REQ-001/002/003, CON-004, contoh kode Section 12) lewat `/sdlc-define-specs` supaya menyebut nilai yang benar, baru lanjut ke implementasi kode. Instruksi ini harus ditulis eksplisit di TASK-001 plan dan ASSUMPTION-001 spec.

- **Requirement:** CON-001/CON-002 (Spec Section 3) hanya menyebut Ambil/Lepas/Tutup/Snooze (disembunyikan) dan Konfirmasi Nomor/Edit Profil (disabled) — tidak menyebut badge `response_state`, titik SLA, atau tombol Tandai Dibaca/Handoff.
  - **Resolution:** Berdasarkan Resolved Item #2 dan #3 di `clarification-report-whatsapp-grup-balas-teruskan-spec-2026-09-26.md` (kesepakatan sebelumnya yang belum masuk ke spec/plan Tahap 1): badge `response_state`, titik warna SLA, tombol **Tandai Dibaca**, dan tombol **Handoff** harus **disembunyikan** pada baris/header percakapan grup — pola yang sama dengan CON-001 (tidak dirender, bukan disabled). Perlu CON baru di spec + perluasan TASK-006 di plan.

- **Requirement:** CON-002 "Hapus percakapan tetap berfungsi penuh" untuk grup, vs `hapusPercakapan()` (`Inbox.php:1552`) yang mensyaratkan `status === 'closed'`.
  - **Resolution:** Karena grup tidak pernah bisa mencapai `status='closed'` (tombol Tutup disembunyikan CON-001, endpoint `tutupPercakapan()` menolak 403 CON-004, tidak ada reopen manual untuk siapa pun) — percakapan grup **dikecualikan** dari syarat "harus closed dulu" di `hapusPercakapan()`. Gate admin-only yang sudah ada (`Inbox.php:1545`) **tidak berubah** — kasir non-admin tetap ditolak 403 seperti biasa. Perlu REQ/CON baru di spec + TASK baru di plan.
  - **Test coverage:** Item ini butuh test otomatis (feature/HTTP test), bukan cuma manual check — karena mengubah kondisi endpoint yang bisa diassert programatis.

- **Requirement:** CON-003 "Kirim pesan teks/media tetap berfungsi tanpa perubahan pada grup", vs auto-assign di `kirimKeConversation()` (`Inbox.php:1049-1053`) dan `kirimMedia()` (`:2137-2144`) yang tidak mengecek `jid_type`.
  - **Resolution:** Auto-assign **dikecualikan** untuk grup — di kedua fungsi tersebut, tambah pengecualian: kalau `jid_type === 'group'`, jangan set `assigned_to` walau masih kosong. Ini mencegah kebuntuan permanen (grup ter-assign ke satu kasir tapi tidak ada jalan melepasnya, karena `lepasPercakapan()` juga menolak 403 untuk grup tanpa pengecualian di CON-004) dan konsisten dengan Spec Section 1.1 ("grup tidak menyentuh dimensi kepemilikan"). Perlu REQ/CON baru di spec + TASK baru di plan.

- **Requirement:** REQ-005 (Spec)/TASK-006(a) (Plan) "tambah tab Grup di daftar tab" — tidak menyebut posisi tab di antara 5 tab yang sudah ada.
  - **Resolution:** Tab **Grup ditaruh paling akhir** (setelah Selesai) — urutan jadi `Belum Diambil, Open, Menunggu, Ditunda, Selesai, Grup`. Alasan: 5 tab lama mengikuti urutan lifecycle percakapan pribadi; grup adalah dimensi berbeda dan tidak boleh menyisip ke tengah urutan yang sudah dihafal kasir.

- **Requirement:** TASK-006(a) "tambah tab Grup" — tidak menyebut objek JS `QUEUE_STATUS_LABEL` (`index.php:790-796`) yang jadi sumber kebenaran untuk highlight tombol aktif dan hitung badge angka tab.
  - **Resolution:** TASK-006(a) harus eksplisit menyertakan penambahan entri `grup: 'Grup'` ke `QUEUE_STATUS_LABEL`, bukan hanya menambah elemen HTML `<button>` tab. Tanpa ini, tombol tab Grup tidak akan pernah menyala aktif dan badge angkanya tidak akan pernah ter-update walau filter data tetap jalan (bug tersembunyi yang lolos review dangkal).

## 3. ⚠️ Assumed / Auto-Resolved / Out of Scope (The 20% we skip)

- **Scenario / Question:** Baris preview daftar grup (`index.php:373`/`:942`) akan menampilkan teks fallback literal `"group"` (nilai mentah `jid_type`), berdampingan/redundan dengan badge "Grup" (REQ-005) di baris yang sama.
  - **Handling:** `[Assumed / Out of Scope]` — murni kerapian visual, tidak menyebabkan bug fungsional. Developer boleh membersihkannya sebagai polish kecil di Tahap 1, atau membiarkannya untuk polish terpisah nanti.

## 4. 📝 Next Steps

- **Wajib:** `spec-design-grup-tahap1-tab-inbox.md` harus direvisi lewat `/sdlc-define-specs` untuk menuliskan kelima Resolved Items teknis di atas (RISK-001 instruksi eksplisit, CON baru untuk badge/SLA/Tandai-Dibaca/Handoff, pengecualian Hapus dari syarat closed, pengecualian auto-assign, posisi tab) sebagai REQ/CON/AC baru dengan Ref ID resmi.
- **Wajib:** `plan-feature-grup-tahap1-v1.0.md` harus direvisi lewat `/sdlc-plan-tasks` untuk mewarisi Ref ID baru tersebut ke task-task terkait (TASK-001 instruksi STOP, TASK-006 diperluas, TASK baru untuk Hapus + auto-assign, TASK-007 VERIFY diperluas dengan test otomatis untuk Hapus dan manual check untuk badge/tombol tersembunyi).
- Tidak ada istilah domain baru yang perlu masuk `CONTEXT.md` sesi ini.
- Tidak ada keputusan yang memenuhi Triple Gate ADR (semua adalah penerapan langsung pola yang sudah ada, bukan trade-off baru yang hard-to-reverse/surprising).

---
> **User Decision Prompt:**
> Dokumen sudah mencapai Readiness Score 82/100. Pemilik proyek memilih **PROCEED** ke fase Specification (`/sdlc-define-specs`) untuk menuliskan resolusi di atas ke dokumen resmi.
