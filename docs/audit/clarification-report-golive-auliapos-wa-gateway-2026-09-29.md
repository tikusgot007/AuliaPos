# 🔍 Clarification Report [Review Iteration 2]

**Dokumen yang diinterogasi:** `plan/plan-infrastructure-golive-auliapos-wa-gateway-v1.0.md` v1.0
(status `Planned`), dengan rujukan `docs/adr/0003-single-pc-store-hours-gateway-topology.md`,
`docs/TODO-CHAT.md`, dan `docs/ARCHITECTURE.md`.

**Readiness Score:** 84/100

**Status:** Good Enough — PROCEED (dengan syarat penulis plan mentranskripsikan resolusi di bawah)

**Score Breakdown:**

- **Completeness (max 40):** 34 — 7 keputusan operasional tuntas + metode pengukuran eksplisit;
  sisa: nama eksekutor cadangan, penjadwalan maintenance detail, dan transkripsi task baru ke plan.
- **Clarity (max 30):** 25 — ambiguitas utama (durasi, "missing", heartbeat, batas read-only) sudah
  dikunci; sisa: koreksi redaksional hitungan migrasi.
- **Alignment (max 30):** 25 — selaras ADR-0003 / `ARCHITECTURE.md` / `TODO-CHAT.md`; sisa: koreksi
  faktual hitungan migrasi vs repo dan catatan versi Node.
- **Critical Flaw Veto:** No.

---

## 1. 🚨 Critical Findings (Blockers)

**None.** Seluruh temuan kritis Iteration 1 (C-01 hitungan migrasi, C-02 heartbeat tak terukur,
C-03 missing/duplicate tanpa ground truth) kini punya resolusi yang terekam di §2. Yang tersisa
hanyalah aplikasi penulisan oleh agen penulis plan; bukan lagi ambiguitas.

---

## 2. 🧩 Resolved Items & Agreements

### Decision 6 — Jam operasional & jadwal autostart

- Toko **buka setiap hari, tanpa hari libur**; PC tidak punya jam mati pasti (rata-rata hidup ~08:00,
  mati ~20:30).
- Jadwal autostart = **jaring pengaman**: trigger start **07:45** (atau saat boot), trigger stop
  **21:00**.
- **Accepted backlog window** = dari PC mati (~20:30) sampai hidup lagi (~08:00).
- **Maintenance** (migrasi/deploy Phase 2, restart Apache Phase 5) = **ad hoc di luar jam ramai**,
  karena tidak ada hari tutup.
- Penyebut "business hours" untuk heartbeat = **jam PC menyala**, bukan jam dinding.

### Decision 1 — Durasi shadow & pengukuran

- Shadow = **5 hari kalender berurutan**.
- **Pencatat otomatis dipakai (Opsi A):** ops-artifact baru — skrip poll status tiap 15–60 detik →
  file log; 99% dihitung dari log terhadap jam PC menyala.
- Staleness = `messages.created_at` (Inbox) − `message_timestamp` (Gateway), **hanya pesan masuk**;
  pesan yang baru terkirim lewat retry dekripsi (GW-25) dicatat sebagai kelas pengecualian; bila
  >5 menit → **gate gagal**, buka bug.
- Missing = volume dari log `incoming_queue` + rekonsiliasi harian vs daftar chat di HP toko;
  duplicate = `wa_message_id` muncul >1 di `messages`. Missing/duplicate = **0**.

### Decision 2 — Siapa memantau

- **Opsi B:** hanya **implementer** yang meninjau rekap log pencatat, **sekali sehari**; staff tidak
  dibebani.
- **Syarat wajib (accepted-risk guard):** fitur *restart on failure* (TASK-017) **harus diuji
  benar-benar memulihkan <5 menit**; jika tidak, Opsi B ditinjau ulang.

### Decision 3 — SOP koeksistensi WhatsApp Web + Inbox

- **Opsi B:** Web tetap terautentikasi saat cutover; aturan lisan "utamakan Inbox".
- **Accepted risk:** RISK-004 (double-reply manusia) **tidak hilang**. Dua penjaga wajib
  menyertainya: (1) Web disisakan hanya di **satu perangkat/kios**, bukan semua komputer staff;
  (2) **cek rekonsiliasi harian** untuk dua balasan keluar hampir identik pada percakapan sama
  dalam rentang singkat.
- Batas read-only: hanya **Phase 5** (shadow). Balasan keluar Inbox baru diuji di **Phase 6**
  memakai **satu nomor uji**, dengan staff diberi tahu agar tidak membalas percakapan uji itu.

### Decision 4 — Retensi media

- **Opsi B:** retensi berbasis umur — **buang media >90 hari** (tanpa arsip), dieksekusi lewat
  **runbook ops** (mis. bulanan), bukan kode.
- Wajib: saat file dihapus, baris terkait di-set **`media_local_filename = NULL`** agar tidak ada
  pointer menggantung.
- **Batas jujur:** media >90 hari tidak lagi bisa dibuka dari disk lokal; sebagian manfaat perbaikan
  "foto tidak kadaluarsa" 28-Sep berkurang untuk media lama. RISK-005 **ditutup dengan kebijakan
  ini**.

### Decision 5 — Peran PC kedua

- **Opsi A:** PC kedua = **cold standby**, tidak pernah menjalankan Gateway; dipakai untuk restore
  backup Phase 1 bila PC server rusak. ASSUMPTION-003 diganti decision record eksplisit + larangan
  "no Gateway di PC kedua".

### Decision 7 — Eksekutor rollback

- **Opsi A:** otoritas pemicu rollback = **pemilik toko**; eksekutor teknis = **implementer (Aan)**
  + satu cadangan; dibuat **runbook rollback** di `docs/runbooks/` (langkah persis restore kode +
  dump DB + `.env`).

### Perbaikan faktual & gap yang direkam

- **C-01:** Ganti gate hard-coded "20 migrasi / 6 v2.1" dengan pernyataan berbasis berkas.

  | Sumber | Entri `.php` | Total entri direktori |
  | --- | --- | --- |
  | `app/Database/Migrations` (v2.3) | **19** | 20 (termasuk `.gitkeep`) |
  | `origin/v2.3:app/Database/Migrations` | **19** | 20 (termasuk `.gitkeep`) |
  | `origin/v2.1:app/Database/Migrations` | **5** | 6 (termasuk `.gitkeep`) |

  Migrasi POS v2.1: `CreateAuliaPosCore`, `AddStatusMangkrakTransaksi`,
  `AddDiskonPelangganPersenTransaksi`, `AddPriorityToUsers`, `CreateClosingKasTable`.
- **F-01:** Tambah task menyetel **kredensial grup DB `inbox`** di `.env` AuliaPos **sebelum
  Phase 2** (prasyarat migrasi grup `inbox`).
- **F-02:** Tegaskan lokasi macro gate TASK-014 — bila di server, **provision `aulia_inboxdb_test`**
  dulu; atau jalankan suite di checkout dev.
- **F-03:** Tambah langkah **kontrol single-instance**: pastikan tidak ada Gateway manual berjalan
  sebelum registrasi autostart (GW-18, port 3000).
- **F-04:** Tambah **jendela maintenance ad hoc** (hasil Decision 6) ke Phase 2/5.
- **F-05:** Tegaskan **batas read-only** Phase 5 vs outgoing Phase 6 (hasil Decision 3), termasuk
  guardrail nomor uji.
- Tambah **task ops-artifact pencatat heartbeat** (hasil Decision 1).

---

## 3. ⚠️ Assumed / Auto-Resolved / Out of Scope (The 20% we skip)

- **Nama pemilik toko & eksekutor cadangan:** `[Assumed / Open]` — diisi di TASK-039; peran sudah
  pasti (otoritas = pemilik; eksekutor = implementer + cadangan).
- **ASSUMPTION-001 (brief = spec authoritative):** `[Assumed / Agreed]` — layak; reconfirm di
  TASK-010.
- **GW-25 / C3:** `[Assumed / Open]` — tetap terbuka; butuh nomor uji kedua (M1 Ticket 05), di luar
  plan ini.
- **ASSUMPTION-007 (`outgoing_operations`):** `[Assumed / Open]` — tetap terbuka sampai APK Wave-2
  berjalan di perangkat.
- **Node v22.23.2 vs baseline Node 20:** `[Assumed]` — konfirmasi build `better-sqlite3` native
  untuk v22 di server (TASK-001/TASK-002).
- **Secret di startup script:** `[Assumed]` — wajib ACL NTFS terbatas + gitignored (SEC-002).

---

## 4. 📝 Next Steps

1. **Agen penulis plan (`/sdlc-plan-tasks`)** mentranskripsikan §2 ke
   `plan/plan-infrastructure-golive-auliapos-wa-gateway-v1.0.md`: perbarui Section 6 (TEST-005),
   Section 7 (risiko diterima + tutup RISK-005, ganti ASSUMPTION-003), Section 9 (executor +
   trigger runbook), TASK-006/TASK-011 (gate migrasi), TASK-017 (pencatat heartbeat), TASK-027
   (maintenance), TASK-038/039 (SOP + runbook), dan tambah task F-01/F-02/F-03.
2. **Klarifikasi iterasi berikutnya tidak diperlukan** kecuali ingin menutup nama eksekutor cadangan.
3. **Glosarium/ADR:** Tidak ada istilah domain baru dan tidak ada keputusan arsitektural baru yang
   memenuhi Triple Gate — **tidak ada update `CONTEXT.md`/ADR**. Bila ingin, Decision 3 B (menerima
   RISK-004) bisa dicatat sebagai decision record bertanggal di `docs/decisions/`, bukan ADR.

---

> **User Decision Prompt:**
> The document has achieved a Readiness Score of **84/100**. It is ready for the next phase. Do you
> want to **PROCEED** to the next phase, or do you want to **REFINE** and clarify further?

**Keputusan pengguna:** ✅ **PROCEED** (2026-09-29). Dokumen dinyatakan layak untuk fase berikutnya
(pelaksanaan), dengan syarat resolusi di §2 ditranskripsikan ke plan oleh agen penulis.
