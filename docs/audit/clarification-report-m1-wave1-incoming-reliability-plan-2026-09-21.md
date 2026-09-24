# 🔍 Clarification Report [Review Iteration 2]

**Target Document:** `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` (v1.0), dengan rujukan `spec/spec-process-m1-wave1-incoming-reliability.md` (v1.1) dan `docs/decisions/2026-09-21-m1-ticket02-audit-enqueue.md` (E-01..E-09).

**Readiness Score:** 92/100
**Status:** Good Enough

**Score Breakdown:**

- **Completeness (max 40):** 36/40 - Semua REQ punya AC, semua task punya Ref ID dan Dep. Enam celah kini tertutup keputusannya (urutan RISK-001, struktur titik kirim RISK-002, kesiapan testing seam, batas jadwal RISK-003, urutan edit TASK-013/014, dan interval siklus worker). Sisa 4 poin dikurangi karena RISK-004/005 (batas scope E-02/E-07/GW-09) tidak diinterogasi ulang — namun sudah didokumentasikan dengan mitigasi jelas di plan asli.
- **Clarity (max 30):** 28/30 - Tidak ada bahasa subjektif tak terukur. 2 poin dikurangi karena nama pasti fungsi kirim internal (`/send`/`/send-media`) masih ditunda ke waktu eksekusi TASK-009 — ini sengaja (Keputusan A, Pertanyaan 1), bukan kelalaian.
- **Alignment (max 30):** 28/30 - Traceable penuh ke E-01..E-09 (audit), D-01..D-04 (spec), dan REQ/AC. 2 poin dikurangi karena istilah "titik kirim" di plan belum dikonfirmasi identik dengan struktur kode aktual (menunggu verifikasi runtime).
- **Critical Flaw Veto:** No - Tidak ada kontradiksi fundamental atau blocker katastropik yang ditemukan.

---

## 1. 🚨 Critical Findings (Blockers)

None. Ketiga fokus interogasi yang diminta user (RISK-001, RISK-002, kejelasan dependency/test seam) telah diputuskan dan tidak lagi memblokir eksekusi `/sdlc-write-code`.

## 2. 🧩 Resolved Items & Agreements

- **Requirement:** RISK-002 — "TASK-009 mengasumsikan endpoint `/send` dan `/send-media` melalui titik kirim yang bisa disisipi opsi `messageId` dengan mudah — struktur kode pasti... belum dikonfirmasi dari spec/audit."
  - **Resolution:** Ditambahkan kriteria stop/lanjut eksplisit untuk TASK-009. Jika `/send` dan `/send-media` berbagi satu fungsi kirim internal → lanjut, sisipkan `register()` + opsi `messageId` di satu titik itu. Jika ternyata terpisah lebih dari 2 fungsi kirim tanpa titik bersama → **STOP eksekusi**, jangan duplikasi logika `register()` secara mandiri di banyak tempat; laporkan balik untuk task tambahan sebelum melanjutkan Fase 2. Alasan: mencegah pola D-03 (bug balapan) tersebar dan lolos review di lebih dari satu lokasi.

- **Requirement:** RISK-001 — "TASK-009 rawan human error jika `register(id)` dipanggil setelah `await sock.sendMessage(...)`... Mitigasi: review manual kode TASK-009 sebelum merge."
  - **Resolution:** Review manual saja dianggap tidak cukup karena bergantung pada disiplin reviewer tiap kali kode disentuh ulang. Ditambahkan syarat konkret: `test/simulate-append-handling.js` (TASK-011) MUST menyertakan pemeriksaan statis pada kode sumber — baca file lewat `fs.readFileSync`, verifikasi lewat regex/string bahwa baris `register(` muncul **sebelum** baris `sendMessage(`/`await` pada fungsi kirim yang sama di titik yang diedit TASK-009. Assert ini otomatis, terpisah dari simulasi runtime AC-002 yang bisa lolos secara kebetulan akibat timing simulasi yang longgar.

- **Requirement:** Kesiapan test seam — spec Bagian 6 menyebut seam `incomingBuffer` langsung, tapi plan TASK-006 belum mengonfirmasi apakah `incomingBuffer.js` saat ini menerima path DB sebagai parameter constructor (testable terisolasi) atau terikat singleton `connectionManager`.
  - **Resolution:** TASK-001 (langkah pertama Fase 1) MUST membuka dan memverifikasi struktur `incomingBuffer.js` saat ini sebelum menulis validasi enqueue. Jika constructor belum menerima path DB sebagai parameter (tidak testable terisolasi tanpa Baileys), tambahkan refactor minimal (hanya extract constructor param, bukan fitur baru) sebagai bagian TASK-001, supaya `test/simulate-enqueue-integrity.js` dan `test/simulate-durable-buffer.js` (TASK-006) bisa berjalan tanpa menyalakan socket WhatsApp.

- **Requirement:** RISK-003 — "TASK-017... dijadwalkan di luar jam sibuk toko" tanpa definisi konkret kapan itu.
  - **Resolution:** Ditetapkan jadwal tetap tertulis: TASK-017 (protokol AC-001, mematikan Gateway aktif) hanya boleh dijalankan setelah jam tutup toko (>21:00) atau sebelum jam buka (<08:00). Dipilih di atas alternatif "konfirmasi manual staf hari itu" karena eksekusi kode dilakukan di sesi `/sdlc-write-code` terpisah yang tidak selalu punya akses real-time ke kondisi toko; jadwal tetap lebih aman sebagai default otonom.

- **Requirement:** Urutan TASK-013 (timeout+cache LID) dan TASK-014 (isolasi error per pesan) — keduanya tidak punya Dep satu sama lain di tabel task, padahal sama-sama menyunting region kode yang sama di `connectionManager.js` (loop pemrosesan pesan per-batch).
  - **Resolution:** TASK-014 (bungkus catch-all per pesan) dikerjakan lebih dulu; TASK-013 (`Promise.race` timeout LID) disisipkan di dalam try-block yang sudah dibuat TASK-014, bukan sebagai lapisan try-catch terpisah. Ini memastikan timeout LID yang gagal tetap tertangkap oleh isolasi error yang sama.

- **Requirement:** Interval siklus worker `incomingDelivery.js` tidak disebutkan di plan/spec/audit manapun, padahal AC-008 dan REQ-011 bergantung pada "siklus worker berjalan" untuk memulihkan overflow.
  - **Resolution:** Dianggap di luar scope klarifikasi — interval siklus adalah perilaku existing `incomingDelivery.js` yang tidak diubah plan ini (hanya ditambah `overflowBuffer.drain()` di awal siklus, TASK-004). `test/simulate-durable-buffer.js` (TASK-006) MUST memanggil fungsi siklus secara langsung/manual dalam skenario AC-008, bukan menunggu timer nyata berjalan.

## 3. ⚠️ Assumed / Auto-Resolved / Out of Scope (The 20% we skip)

- **Scenario / Question:** RISK-004 (batas scope E-02/E-07), RISK-005 (batas scope GW-09) — tidak diinterogasi di sesi ini karena user tidak mengarahkan pertanyaan ke sana.
  - **Handling:** `[Assumed / Out of Scope untuk sesi ini]` - Larangan eksplisit memperluas scope ke E-02/E-07/GW-09 dalam gelombang ini sudah tertulis jelas di plan asli dan tidak mengandung ambiguitas yang memerlukan keputusan tambahan. Tidak menurunkan skor di bawah ambang 80.
- **Scenario / Question:** Nama pasti file/fungsi titik kirim (`/send`, `/send-media`) di luar `connectionManager.js`.
  - **Handling:** `[Assumed / Out of Scope hingga waktu eksekusi]` - Sengaja ditunda ke TASK-009 sesuai Keputusan RISK-002 di atas; bukan ambiguitas yang bisa diselesaikan dari dokumen yang ada (memerlukan pembacaan kode aktual di worktree Gateway).

## 4. 📝 Next Steps

- Plan v1.0 tidak wajib direvisi ulang oleh `/sdlc-plan-tasks` karena skor sudah ≥80 — namun keenam keputusan di atas SEBAIKNYA disisipkan sebagai catatan eksplisit sebelum handoff ke `/sdlc-write-code`, agar tidak hilang saat sesi eksekusi kode dimulai terpisah (plan ini tidak mengeksekusi kode):
  - TASK-001: verifikasi struktur `incomingBuffer.js`, refactor constructor minimal bila perlu.
  - TASK-009: kriteria stop/lanjut RISK-002 (satu fungsi kirim vs lebih dari 2 titik terpisah).
  - TASK-011: guard statis RISK-001 (regex urutan `register(` sebelum `sendMessage(`/`await`).
  - TASK-013/TASK-014: tambahkan Dep eksplisit — TASK-014 dulu, baru TASK-013 disisipkan di try-block yang sama.
  - TASK-017: jadwal tetap (>21:00 atau <08:00), bukan konfirmasi manual harian.
  - TASK-006: catatan bahwa fungsi siklus worker dipanggil manual dalam skenario AC-008, bukan menunggu timer nyata.
- Tidak ada istilah domain baru yang perlu masuk `CONTEXT.md`.
- Tidak ada ADR baru — keenam keputusan di atas mudah dibalik (menambah baris assert/refactor kecil, mengubah urutan Dep, menetapkan jadwal), tidak memenuhi ketiga kriteria ADR (sulit dibalik, mengejutkan tanpa konteks, trade-off nyata).

---
> **User Decision Prompt:**
> Dokumen ini mencapai Readiness Score 92/100. Sudah layak lanjut. User memilih **PROCEED**.
