---
date: 2026-09-26
stage: Spec (post spec-design-grup-tahap2-identitas.md v1.0)
source_prd: prd-20260926-0024-whatsapp-grup-balas-teruskan.md
readiness_score: 84/100
status: PROCEED
iteration: 1
---

# 🔍 Clarification Report [Review Iteration 1]

**Readiness Score:** 84/100
**Status:** Good Enough (PROCEED)

**Dokumen diperiksa:** `spec/spec-design-grup-tahap2-identitas.md` v1.0

**Dokumen rujukan:** `spec/spec-index.md`, `prd-20260926-0024-whatsapp-grup-balas-teruskan.md` v1.0, `spec/spec-design-grup-tahap1-tab-inbox.md` v1.1, `docs/CHAT.md`

**Catatan akses:** repo `tikusgot007/WA-Gateway` tidak dibuka dari sesi ini. Seluruh fakta sisi AuliaPos di bawah ini diverifikasi langsung pada kode sesi ini (`app/Controllers/InboxGatewayApi.php`, `app/Controllers/Inbox.php`, `app/Models/ConversationModel.php`), bukan dari klaim spec.

**Score Breakdown:**

- **Completeness (max 40):** 33 - Alur utama (kontrak payload, penyimpanan, tampilan, migrasi) lengkap. Celah yang tersisa bersifat penulisan: validasi 400 tidak punya REQ/AC, jalur `created=true` tidak ditentukan, dan label outgoing non-POS tidak diatur.
- **Clarity (max 30):** 25 - REQ-004 menyesatkan ("tinggal diisi", padahal baris penyimpanan sudah ada di kode) dan REQ-006 memakai `empty()` alih-alih `=== null` pada contoh §8.
- **Alignment (max 30):** 26 - Kontradiksi GH-013 ("nama pengirim") vs REQ-008 (nomor/`LID`) diselesaikan dengan Keputusan A. Tensi spec-internal SEC-01 (Tahap 1) diputuskan pada sesi ini.
- **Critical Flaw Veto:** No - None. Dua isu yang berpotensi memveto (T3 dan SEC-01) sudah diputuskan pada sesi ini.

---

## 1. 🚨 Critical Findings (Blockers)

None. Tidak ada kontradiksi fundamental yang tersisa setelah dua keputusan sesi ini. Tujuh item yang tercatat di bawah adalah **amandemen penulisan wajib**, bukan blocker yang menahan fase berikutnya.

## 2. 🧩 Resolved Items & Agreements

- **SEC-01 (carry-forward dari `/sdlc-code-review` Grup Tahap 1, commit `ab843ed`).**
  - **Temuan:** `Inbox::handoffPercakapan()` (`app/Controllers/Inbox.php:1196`) dan `Inbox::tandaiDibaca()` (`:1774`) tidak memanggil `cekBukanGrup()`, sedangkan 6 endpoint lain (`ambil` :1660, `lepas` :1741, `snooze` :1813, `tutup` :1867, `edit-profil` :1933, `konfirmasi-nomor` :2032) sudah menolak `403`. `handoffPercakapan()` menulis `assigned_to` (:1400-1405) + baris `conversation_handoffs`; karena Tahap 1 memberi grup `queue_status='grup'` (`ConversationModel.php:249-251`), check `!== 'selesai'` lolos, dan untuk grup legacy yang ter-assign gerbang inisiator (:1341-1361) mengizinkan assignee.
  - **Keputusan pemilik proyek:** perluas cakupan server-side untuk **kedua** endpoint.
  - **Keputusan:** `CON-004` dan `AC-006` Tahap 1 diperluas agar `handoffPercakapan()` **dan** `tandaiDibaca()` juga membalas `403` untuk `jid_type='group'`. Guard `cekBukanGrup()` diletakkan setelah pengecekan 404 dan sebelum cek eligibility/ownership — supaya jawabannya `403` (konsisten dengan 6 endpoint lain), bukan `409` yang menyesatkan. `Section 9` Tahap 1 diselaraskan menjadi invariant positif: "pada percakapan grup hanya berlaku baca, kirim pesan, dan Internal Note; seluruh endpoint aksi lain menolak `403`", dan dimensi **Read/Unread** disebut eksplisit agar tidak bentrok dengan `Section 1.1` (yang menyatakan Read/Unread out of scope). Detail: `tandaiDibaca()` menulis `last_seen_by_assignee_at` (:1790), yang memang di luar frasa lama "assigned_to/status/lifecycle".
  - **Batas sesi:** tidak ada kode yang disentuh. Amandemen ditulis pada `/sdlc-define-specs` sesi berikutnya.

- **T3 - Label pengirim: "nama" (PRD GH-013) vs nomor/`LID` (REQ-008).**
  - **Keputusan pemilik proyek (Opsi A):** identitas pengirim ditampilkan sebagai **nomor telepon** (untuk `sender_jid` `@s.whatsapp.net`) atau penanda `LID`, bukan nama orang. Redaksi PRD GH-013 dan `Section 4`/"Label pengirim di dalam grup" diselaraskan dari "nama pengirim" menjadi "identitas pengirim (nomor/`LID`)".
  - **Alasan:** hanya `key.participant` yang tersedia di Gateway; nama tampilan per peserta tidak dijamin Baileys tanpa sinkronisasi kontak, sehingga opsi "nama" menambah beban dua repo tanpa jaminan hasil. AC-002 spec sudah konsisten apa adanya.

- **Catatan:** item yang sudah dikunci pada `docs/audit/clarification-report-whatsapp-grup-balas-teruskan-spec-2026-09-26.md` (RESOLVED, proyeksi 93/100) tidak ditanyakan ulang.

## 3. ⚠️ Assumed / Auto-Resolved / Out of Scope (The 20% we skip)

- **T1 - REQ-004 sudah berjalan.** `InboxGatewayApi::messages()` (`app/Controllers/InboxGatewayApi.php:251`) sudah menulis `'sender_jid' => $payload['sender_jid'] ?? null` tanpa syarat untuk semua pesan.
  - **Handling:** `[Assumed / Auto-Resolved]` - Plan Tahap 2 harus menghapus klaim "tinggal diisi dari payload baru"; pekerjaan sebenarnya hanya (a) validasi 400 (T2), (b) penyimpanan `group_name` (REQ-005), dan (c) tampilan.

- **T2 - Validasi 400 tanpa REQ/AC.** Aturan "request ditolak 400 kalau `sender_jid` kosong untuk pesan grup" hanya ada di tabel `Section 4.1`.
  - **Handling:** `[Assumed / Auto-Resolved]` - Naikkan menjadi REQ baru bernomor (mis. REQ-010) + AC eksplisit (grup tanpa `sender_jid` → `400`, tidak disimpan), bukan hanya catatan tabel.

- **T4 - Urutan rilis dan pesan grup dari Gateway lama.** Karena `sender_jid` wajib + `400`, Gateway yang belum diperbarui akan membuat **setiap** pesan grup ditolak dan **tidak tersimpan** (tidak ada outgoing queue, `docs/CHAT.md` §5/§18).
  - **Handling:** `[Assumed / Auto-Resolved]` - Pertahankan kontrak wajib + `400` (PRD §7.3 mensyaratkan 100% pesan grup baru punya pengirim). Spec **wajib** menyatakan eksplisit: (a) AuliaPos hanya boleh dirilis **setelah** perubahan Gateway terpasang (EXT-001 diperjelas dari "wajib" menjadi constraint urutan), (b) pesan grup dari Gateway lama ditolak `400` dan hilang, (c) urutan rollback = Gateway dulu. Catat kontras dengan `group_name` yang additive (GUD-002).

- **T5 - Outgoing grup dari WA Web/HP.** REQ-009 hanya mengatur pesan yang dikirim kasir dari POS.
  - **Handling:** `[Assumed / Auto-Resolved]` - Outgoing tersinkron (`sent_by_user_id = NULL`) mengikuti label yang sudah ada di `docs/CHAT.md` §7 ("Staff (WA Web/HP)"); tambahkan satu kalimat di REQ-009.

- **T6 - `group_name` pada jalur create dan write-once.** Contoh §8 hanya menutup jalur update; jalur `created=true` (`ConversationModel.php:394-404`) tidak ditentukan, dan contoh memakai `empty()` alih-alih `=== null`.
  - **Handling:** `[Assumed / Auto-Resolved]` - Nyatakan penulisan `group_name` berlaku juga saat conversation baru dibuat dalam request yang sama; ganti `empty($conversation['group_name'])` menjadi `$conversation['group_name'] === null` sesuai REQ-006; catat bahwa dua penulisan pertama yang bersamaan berakhir *last-write-wins* (diterima, kasus jarang, satu nama grup dari server WhatsApp).

- **T7 - Pencarian berdasarkan `group_name`.** `group_name` tidak masuk `SEARCH_COLUMNS`, jadi grup tidak bisa ditemukan lewat nama aslinya.
  - **Handling:** `[Assumed / Out of Scope]` - PRD tidak meminta pencarian nama grup; grup tetap tertemukan lewat `whatsapp_name`/pesan. Catat sebagai backlog.

## 4. 📝 Next Steps

- Jalankan `/sdlc-define-specs` untuk dua amandemen dalam satu sesi authoring:
  1. **Tahap 1** (`spec-design-grup-tahap1-tab-inbox.md`) - perluas `CON-004`/`AC-006` ke `handoffPercakapan()` + `tandaiDibaca()`, dan selaraskan `Section 9`.
  2. **Tahap 2** (`spec-design-grup-tahap2-identitas.md`) - perbaiki T1, T2, T4, T5, T6; selaraskan redaksi GH-013 (Keputusan A).
- Setelah amandemen: jalankan `/sdlc-audit-consistency` (spec Tahap 1 **dan** PRD ikut berubah, jadi keterlacakan perlu dicek ulang).
- Tidak ada ADR baru — keputusan sesi ini gagal *Triple Gate Validation* dan merupakan penerapan pola yang sudah ada (`docs/CHAT.md` §18).
- Tidak ada istilah domain baru — `CONTEXT.md` tidak diubah.

---

> **User Decision Prompt:** The document has achieved a Readiness Score of 84/100. It is ready for the next phase. Do you want to **PROCEED** to the next phase, or do you want to **REFINE** and clarify further?
