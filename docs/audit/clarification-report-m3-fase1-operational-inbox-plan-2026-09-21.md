# 🔍 Clarification Report [Review Iteration 3]

**Target Document:** `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md`
**Reference Spec:** `spec/spec-design-m3-operational-inbox-fase1.md`

**Readiness Score:** 97/100
**Status:** Good Enough

**Score Breakdown:**

- **Completeness (max 40):** 39 - Seluruh REQ/TASK di plan sudah punya jalur implementasi konkret; 7 gap struktural yang sebelumnya tidak tercakup (skema DB, atribusi, UI, error handling, edge case filter, badge count, konsistensi limit) sudah diresolusi dengan keputusan eksplisit.
- **Clarity (max 30):** 29 - Semua keputusan baru bersifat terukur dan dapat langsung diimplementasikan tanpa interpretasi lebih lanjut.
- **Alignment (max 30):** 29 - Seluruh resolusi selaras dengan REQ-002 (single source computed status), CON-002, dan prinsip minimal-changes di `CLAUDE.md`/plan.
- **Critical Flaw Veto:** No - Tidak ada kontradiksi fundamental yang ditemukan.

---

## 1. 🚨 Critical Findings (Blockers)

None.

## 2. 🧩 Resolved Items & Agreements

- **Requirement:** TASK-008 - "insert ke `MessageModel` dengan `is_internal = true`, `direction = 'outgoing'`"
  - **Issue ditemukan:** `MessageModel::$validationRules` mewajibkan `wa_message_id` (`required`, ada `existsByWaMessageId()` untuk idempotency), `message_timestamp` (`required|valid_date`), dan `send_status` (`required|in_list[received,sent,failed]`) - tidak ada nilai natural untuk Internal Note yang bukan pesan WhatsApp asli.
  - **Resolution:** `wa_message_id` diisi nilai sintetis unik (`'internal-' . uniqid()` atau setara, mis. gabungan `conversation_id` + `time()`), `message_timestamp` = waktu insert, `send_status = 'sent'` sebagai nilai netral yang valid terhadap `in_list`. Tidak ada perubahan skema/validasi existing.

- **Requirement:** SEC-001 - "staff manapun boleh menulis Internal Note ke conversation manapun"
  - **Issue ditemukan:** Kolom `sent_by_user_id` pada `MessageModel` tidak disebutkan pengisiannya di spec/plan untuk Internal Note, padahal SEC-001 justru membuat atribusi penulis penting untuk audit.
  - **Resolution:** `sent_by_user_id` diisi dari ID staff yang login (session), mengikuti pola pengisian existing untuk pesan `outgoing` lain. Tidak ada kolom/migration baru.

- **Requirement:** CON-002 - "Internal Note tidak muncul dalam payload apa pun yang dikirim ke Gateway WhatsApp"
  - **Issue ditemukan:** CON-002 hanya menjamin non-pengiriman ke Gateway, tidak menjamin bagaimana Internal Note tampil di thread UI internal (`GET /inbox/api/conversations/(:num)/messages`) - berisiko staff salah kira Internal Note sebagai pesan yang terkirim ke customer.
  - **Resolution:** Internal Note tetap tercampur kronologis di thread yang sama; payload endpoint menyertakan flag `is_internal` per baris, frontend WAJIB render dengan style visual berbeda (mis. background berbeda + label "Internal").

- **Requirement:** TASK-012 - alur Snooze + Alasan (dua panggilan endpoint terpisah: `snoozePercakapan()` lalu Internal Note)
  - **Issue ditemukan:** Tidak ada penanganan untuk kasus panggilan pertama sukses tapi panggilan kedua (insert Internal Note alasan) gagal.
  - **Resolution:** Snooze dianggap berhasil sepenuhnya meski catatan alasan gagal tersimpan; tampilkan notifikasi non-blocking ke staff tanpa retry otomatis.

- **Requirement:** ASSUMPTION-001 / Bagian 4.4 - parameter `q` memakai `LIKE '%q%'` mentah
  - **Issue ditemukan:** Tidak didefinisikan perlakuan saat `q` kosong/tidak dikirim (`?q=`).
  - **Resolution:** `q` kosong diperlakukan sebagai "tidak ada filter pencarian" - skip logic filter (cek `!empty($q)`), bukan menjalankan filter yang secara matematis match semua.

- **Requirement:** REQ-001 - "Queue View menampilkan 5 tab"
  - **Issue ditemukan:** Tidak disebutkan apakah label tab menampilkan angka jumlah conversation, berbeda dari badge sidebar Tahap A yang sudah ada.
  - **Resolution:** Tambahkan angka jumlah per tab, dihitung di frontend dari payload `withComputedStatus()` yang sudah ada (tanpa query/endpoint baru).

- **Requirement:** TASK-011 vs `Inbox::index()` (baris ~38, first-paint SSR)
  - **Issue ditemukan:** Plan hanya menaikkan limit `findAll(100)` → `findAll(500)` di `apiConversations()` ("baris ~65"); `index()` memakai pola identik tapi tidak disebutkan, berisiko inkonsistensi tampilan first-paint vs AJAX refresh pertama.
  - **Resolution:** Naikkan juga limit `index()` menjadi `findAll(500)` supaya konsisten dengan `apiConversations()` sejak first-paint.

## 3. ⚠️ Assumed / Auto-Resolved / Out of Scope (The 20% we skip)

None - seluruh temuan pada sesi ini diselesaikan lewat jawaban eksplisit user (bukan auto-resolve karena PROCEED di skor rendah), karena skor sudah di atas 80 sejak awal dan REFINE dipilih tiga kali secara sukarela oleh user untuk menggali lebih dalam.

## 4. 📝 Next Steps

- Plan (`plan/plan-feature-m3-operational-inbox-fase1-v1.0.md`) sebaiknya diperbarui oleh `/sdlc-plan-tasks` untuk memasukkan 7 resolusi di atas secara eksplisit ke deskripsi TASK-008, TASK-011, TASK-012 sebelum eksekusi `/sdlc-write-code` - saat ini keputusan hanya tercatat di laporan ini, belum tertulis ulang ke dokumen plan itu sendiri.
- Tidak ada istilah domain baru yang butuh entri `CONTEXT.md`.
- Tidak ada keputusan yang memenuhi Triple Gate ADR (hard-to-reverse + surprising + real trade-off) - seluruh resolusi bersifat detail implementasi minor, bukan keputusan arsitektural baru.

---
> **User Decision Prompt:**
> Dokumen ini mencapai Skor Kesiapan 97/100. Klarifikasi Plan M3 Fase 1 sudah lengkap dan siap dilanjutkan ke `/sdlc-write-code` — **dengan catatan**: pertimbangkan meminta `/sdlc-plan-tasks` menuliskan ulang 7 resolusi di atas ke badan dokumen plan itu sendiri agar developer/agent yang mengeksekusi `/sdlc-write-code` tidak perlu membuka laporan ini secara terpisah.
