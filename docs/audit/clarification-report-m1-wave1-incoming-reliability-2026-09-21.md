> [!SUCCESS]
> **REMEDIATION STATUS: RESOLVED**
> This audit report has been remediated by Specification Architect.
> - **Projected Readiness Score:** 94/100
> - All resolutions in Section 2 (D-03, D-04, and the two `append` sources) and the five auto-resolved items in Section 3 were written into `spec/spec-process-m1-wave1-incoming-reliability.md` (v1.1): REQ-003 rewritten, REQ-018 and REQ-019 added, REQ-011 and REQ-013 tightened, AC-001 protocol replaced, AC-015 to AC-018 added, and a REQ-to-AC mapping added.

# 🔍 Clarification Report [Review Iteration 1]

**Target Document:** `spec/spec-process-m1-wave1-incoming-reliability.md` (v1.0)
**Reference Documents:** `docs/decisions/2026-09-21-m1-ticket02-audit-enqueue.md`, `docs/decisions/2026-09-21-m1-ticket01-baseline.md`, `docs/GATEWAY-REQUIREMENTS.md`

**Readiness Score:** 85/100
**Status:** Good Enough

**Score Breakdown:**

- **Completeness (max 40):** 33 - REQ dan kasus tepi utama sudah ada. Kurang: AC untuk REQ-001 dan REQ-012, penanganan alamat tak dikenal untuk pesan `append`, dan cache kegagalan query LID.
- **Clarity (max 30):** 25 - Protokol dan jumlah pesan untuk AC-001 belum pasti. Semantik pengurasan penampung sementara (REQ-011) belum tegas.
- **Alignment (max 30):** 27 - Semua REQ terlacak ke temuan audit E-01 sampai E-09 dan GW-08. Klaim di Bagian 6 bahwa setiap REQ punya AC belum benar.
- **Critical Flaw Veto:** No - Balapan pencatatan ID kiriman sendiri (REQ-003) sempat menjadi blocker, tetapi sudah diresolusi lewat keputusan D-03. Spec harus diperbarui agar tidak lagi memuat cara lama.

---

## 1. 🚨 Critical Findings (Blockers)

None. (Satu blocker ditemukan dan diresolusi di sesi ini, lihat Bagian 2, item R-1.)

## 2. 🧩 Resolved Items & Agreements

- **Requirement:** REQ-003 - "Setiap pengiriman berhasil oleh Gateway ... MUST mencatat ID hasil kirim ke daftar ID kiriman sendiri."
  - **Issue ditemukan:** Baileys memancarkan `append` untuk kiriman sendiri lewat `process.nextTick` tepat sebelum `sendMessage()` mengembalikan hasil (`messages-send.js:703–708`). Event itu bisa diproses sebelum ID tercatat, sehingga balasan kasir tercatat ulang, persis masalah yang mau dihindari D-01.
  - **Resolution (D-03):** Gateway menentukan ID pesan sebelum mengirim, mencatatnya ke daftar ID kiriman sendiri, lalu meneruskannya ke Baileys lewat opsi `messageId`. Terverifikasi didukung: opsi pemanggil diterapkan setelah ID bawaan (`messages-send.js:660–661`). ID tetap tercatat walau kirim gagal (tidak berbahaya).

- **Requirement:** REQ-001 - "Gateway MUST memproses event `messages.upsert` bertipe `notify` dan `append`."
  - **Issue ditemukan:** Setelah filter dilepas, pesan `append` dari alamat non-pelanggan (mis. channel/newsletter) yang terklasifikasi `unknown` (`classifyJid`) bisa masuk antrean dan membuat percakapan aneh di AuliaPos.
  - **Resolution (D-04):** Untuk pesan `append`, hanya alamat berjenis `pn`, `lid`, dan `group` yang diterima. Alamat lain dilewati dan dicatat. Perilaku pesan `notify` tidak diubah.

- **Requirement:** "CLARIFICATION NEEDED: `append` juga dipancarkan Baileys dari dua tempat lain (`messages-recv.js` baris 601 dan 957)."
  - **Resolution:** Terjawab dari kode. Baris 601 adalah pesan turunan notifikasi (grup atau protokol) dan baris 957 adalah pesan channel (newsletter). Stub tanpa isi sudah tertahan oleh `!msg.message` di Gateway. Risiko yang tersisa (alamat `unknown`) ditutup oleh D-04.

- **Requirement:** REQ-005 - "Pesan yang sama yang tiba dua kali ... MUST menghasilkan satu baris."
  - **Resolution:** Terkonfirmasi oleh pengamatan Ticket 01: WhatsApp mengirim ulang pesan yang sudah diproses setelah restart (F01–F15), dan tidak ada duplikat karena `wa_message_id UNIQUE` di Gateway dan pengecekan di AuliaPos.

## 3. ⚠️ Assumed / Auto-Resolved / Out of Scope (The 20% we skip)

Pemilik proyek memilih PROCEED, sehingga butir berikut diselesaikan dengan rekomendasi teknis penganalisis.

- **Scenario / Question:** Protokol dan jumlah pesan untuk AC-001 ("0 pesan hilang").
  - **Handling:** `[Assumed / Auto-Resolved]` - Ukur dengan `pm2 stop` sekitar 30 detik, kirim 10 pesan dari HP tes saat Gateway berhenti, lalu `pm2 start`. Ulangi 3 kali. Deterministik, tidak seperti kill di tengah burst (Ticket 01) yang hanya menyisakan jendela sekitar 3 detik.
- **Scenario / Question:** Semantik pengurasan penampung sementara (REQ-011).
  - **Handling:** `[Assumed / Auto-Resolved]` - Satu percobaan per event tanpa jeda di setiap siklus worker. Coba ulang berjeda hanya berlaku pada jalur penerimaan pesan (REQ-009).
- **Scenario / Question:** Query LID yang timeout pada batch berisi banyak pesan beralamat nomor telepon (bisa menunggu 2 detik berulang kali).
  - **Handling:** `[Assumed / Auto-Resolved]` - Kegagalan query di-cache negatif 60 detik per JID (variabel `LID_LOOKUP_NEGATIVE_TTL_MS`).
- **Scenario / Question:** Pesan yang masih tertinggal di database SQLite yang korup saat start (REQ-013).
  - **Handling:** `[Assumed / Auto-Resolved]` - Dianggap hilang dari antrean aktif, tetapi berkasnya dipindah (tidak dihapus) ke `.corrupt-<waktu>` untuk diperiksa manual.
- **Scenario / Question:** Kelengkapan traceability REQ ke AC.
  - **Handling:** `[Assumed / Auto-Resolved]` - Tambah AC untuk REQ-001, REQ-012, dan REQ yang baru dari D-04 serta cache LID.
- **Scenario / Question:** Istilah "pesan masuk" dipakai untuk semua baris `incoming_queue`, padahal berisi juga balasan dari HP (`outgoing`).
  - **Handling:** `[Assumed / Out of Scope]` - Belum ada `CONTEXT.md`. Istilah dibiarkan, dan diberi catatan di bagian Definitions spec. Pembakuan istilah ditunda.

## 4. 📝 Next Steps

- Spec diperbarui oleh Specification Architect dengan D-03, D-04, dan lima asumsi di Bagian 3, lalu Readiness Score proyeksi dihitung.
- Setelah itu lanjut ke `/sdlc-plan-tasks` (disarankan sesi baru). Lampirkan spec dan laporan ini.
- Tidak ada istilah bisnis baru yang perlu masuk `CONTEXT.md`. Tidak ada ADR baru (keputusan mudah dibalik).

---
> **User Decision Prompt:**
> The document has achieved a Readiness Score of 85/100. It is ready for the next phase. Do you want to **PROCEED** to the next phase, or do you want to **REFINE** and clarify further?
>
> **Jawaban pemilik proyek:** PROCEED.
