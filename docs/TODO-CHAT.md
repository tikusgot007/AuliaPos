# Status Proyek — AuliaPos + WA-Gateway (Master Reference)

**Terakhir diupdate:** 21 September 2026 (revisi setelah cek langsung ke remote — banyak progres di Claude Code belum tercermin di versi sebelumnya)
**Cara pakai:** Sematkan/paste dokumen ini di awal sesi Claude Code baru sebagai context. Update bagian "Status Sekarang" dan "Yang Menggantung" setiap kali ada progres baru — dokumen ini gampang basi kalau kerja paralel jalan di beberapa sesi Claude Code sekaligus, jadi **selalu `git fetch` + cek HEAD nyata sebelum percaya isi dokumen ini secara buta**.

---

## Tujuan Jangka Panjang

> Mengembangkan WhatsApp Inbox menjadi operational customer workspace, bukan sekadar viewer chat.

## Roadmap Besar

```
Tahap 0 (Baseline)
   ↓
M1 — Reliability
   ↓
M2 — State Consistency
   ↓
M3 — Operational Workflow
   ↓
M4 — POS / Customer Context
   ↓
M5 — Intelligence / AI
```

## Aturan Tetap (berlaku di semua tahap)

- **Dokumentasi bukan source of truth.** Source code, migration, test, dan git diff adalah sumber kebenaran.
- **Jangan loncat tahap.** Tiap tahap punya prasyarat dari tahap sebelumnya — terutama M3 yang sebagian bergantung keras pada M2 (lihat catatan di bagian M3).
- **Klaim harus berbasis bukti nyata**, bukan asumsi. Kalau ada yang tidak bisa diverifikasi, catat sebagai limitation eksplisit, jangan ditutup-tutupi atau dipaksakan kesimpulan.

---

## Baseline Repository

### AuliaPos
- Repo: `tikusgot007/AuliaPos`
- Branch: `v2.2`
- HEAD terverifikasi (21 Sep): `0ee6a49` — "Write 7 clarification resolutions into M3 Inbox Fase 1 plan tasks (#34)"
- 37 commit masuk sejak HEAD lama (`07d30c8`) — mencakup merge Tahap 0 (PR #24, #25) + rangkaian spec/plan M3 Fase 1 (PR #26, #28, #29, #30, #31, #32, #33, #34)
- Branch kerja M3 yang sudah ter-merge/selesai perannya: `claude/tahap-0-aulia-wa-handoff-ic861g`, `claude/cek-dulu-ubvqe2`, `claude/spec-operational-inbox-fase1-tuux4c`, `claude/m3-operational-inbox-plan-h244ji`, `claude/m3-inbox-plan-review-y9mbw3`
- Tooling baru: `.claude/standards/` berisi skema SDLC (spec → clarification report → plan → ADR), `AGENTS.md` diganti "SDLC Orchestrator template"

### WA-Gateway
- Repo: `tikusgot007/WA-Gateway`
- Branch utama: `claude/android-app-p40bl1`
- HEAD terverifikasi: `5b28eb6c8a7e6e6c2e1d5b7d7261389f9309c295` (tidak berubah)
- Branch dev aktif: `feature/stage-1-reliability` — **belum bergerak sama sekali** sejak dibuat, masih persis di HEAD yang sama. Ticket 01 M1 belum dieksekusi.
- `master` sudah merge PR #1 dari `claude/android-app-p40bl1` + commit "Create node.exe" (packaging, di luar scope M1)
- Branch lain yang muncul (`fix/lid-fromme-pushname-leak`, `claude/cek-bandingkan-mimac-fln1h4`) — dicek, **tidak relevan** dengan kerja saat ini (versi lama/terpisah, salah satunya referensi "AuliaPos v3.0")

### Environment aktif (hasil verifikasi terakhir)
- Gateway yang benar-benar jalan: `G:\wa-gateway-5b28eb6` (kode `5b28eb6`), nomor `6281913500707`
- ⚠️ 3 folder Gateway lama di drive G masih ada, belum dirapikan — potensi salah identifikasi ulang kalau tidak diberi label jelas
- ⚠️ `.env` di `htdocs\wa-gateway` sempat diubah saat investigasi, folder ini **bukan** yang dipakai — perubahan tidak berbahaya tapi sebaiknya dikembalikan/ditandai

---

## Status Per Tahap

### Tahap 0 — Baseline ✅ DONE (20 Sep 2026)

Decision log: `docs/decisions/2026-09-19-tahap-0-baseline.md`, commit `4062833`, branch `claude/tahap-0-aulia-wa-handoff-ic861g`

Hasil ringkas:
- Test unit AuliaPos: 82 test + 144 assertion PASS
- Test database: 61/61 PASS (catatan: jalan di SQLite, bukan MySQL — limitation tercatat)
- Test session: 63 PASS, 2 ERROR (`no such table: db_closing_kas` di `LaporanBulananExcludeBatalTest`)
- Smoke test incoming/outgoing/fromMe: semua lolos
- Verifikasi ulang kasus dekripsi gagal (`AC0B72AD…`) di kondisi bersih: **tidak ada bug sistemik di kondisi normal** (16/16 fromMe sukses, 19/19 incoming/sticker burst sukses)
- Hipotesis dipersempit: kegagalan lama terjadi **tepat setelah restart Gateway** — belum diuji ulang dengan skenario restart eksplisit → diteruskan ke M1 ticket 01

4 limitation tercatat (test soft-delete tidak jalan, test DB pakai SQLite bukan MySQL, duplicate-on-timeout belum teruji, WebP non-sticker belum diverifikasi via WhatsApp asli).

---

### M1 — Reliability ⚠️ TERTINGGAL — belum bergerak sejak baseline

**Status nyata (dicek 21 Sep)**: branch `feature/stage-1-reliability` masih persis di HEAD `5b28eb6`, sama seperti saat dibuat. **Tidak ada progres sama sekali** di M1 sementara M3 sudah sampai tahap plan matang siap eksekusi. Ini bukan pelanggaran urutan (M3 Fase 1a boleh paralel), tapi berarti risiko P0 (message loss, duplicate, retry tak terbatas) yang jadi alasan M1 dibuat **masih 100% belum tertangani**.

**Ticket 01 — Baseline Test**: rencana eksekusi sudah disusun (file: `m1-ticket01-baseline-eksekusi.md`), **belum dijalankan**.

4 skenario yang perlu diukur:
1. Enqueue normal (baseline angka kegagalan kondisi baik)
2. Restart Gateway saat pesan sedang dikirim (langsung menindaklanjuti hipotesis `AC0B72AD…`, diulang 3× dengan titik potong berbeda)
3. Gateway mati di tengah proses kirim (duplicate-on-timeout, limitation Tahap 0 yang belum teruji)
4. Retry queue/backoff behavior (persiapan data untuk ticket 06-07)

**Ticket 02-16**: belum dibahas detail, menyusul setelah ticket 01 selesai. Urutan sesuai daftar awal:
```
02. Audit enqueue          09. Outgoing operation ID
03. Durable buffer         10. Idempotency
04. JSON recovery          11. Ambiguous-send recovery
05. Crash/restart test     12. Worker correctness
06. Attempt counter        13. Structured logging
07. Dead-letter            14. Metrics / health
08. Poison-message test    15. Full reliability test matrix
                            16. Merge
```

Risiko P0 yang jadi alasan M1 ada:
1. Incoming enqueue failure — event hanya dilog, pesan berisiko hilang
2. JSON fallback corruption — queue rusak berisiko restart dari kosong
3. Outgoing duplicate — belum ada end-to-end idempotency saat timeout

---

### M2 — State Consistency ⏳ BELUM MULAI

Fokus: ownership atomic, delivery state eksplisit, audit transition, health yang bisa dipercaya.

Sudah dikonfirmasi lewat analisis kode (saat menyusun blueprint M3): `cekOwnership()` di AuliaPos saat ini **read-then-write di level aplikasi, bukan atomic di database** — ini bukti nyata risiko P1 "ownership race" yang perlu ditangani di M2.

Belum ada breakdown ticket detail untuk M2 — menyusul setelah M1 selesai.

---

### M3 — Operational Inbox 🚦 PLAN LENGKAP & SIAP EKSEKUSI — jauh lebih maju dari catatan sebelumnya

**Update penting (21 Sep)**: sesi Claude Code sudah menghasilkan spec + plan formal yang jauh melampaui blueprint awal kita, lewat proses SDLC terstruktur (spec → clarification report → remediasi → plan → clarification report kedua → resolusi). Semua 5 keputusan desain 🔶 yang saya tandai sebelumnya **sudah diresolusikan** (jadi "7 resolusi klarifikasi").

Dokumen yang kini jadi acuan resmi (menggantikan blueprint lama sebagai working reference):
- `spec/spec-design-m3-operational-inbox-fase1.md` — Readiness Score 96/100
- `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — Readiness Score 97/100, **status: Planned, 0 dari 14 task Completed**
- `docs/adr/0001-reuse-response-state-for-queue-view-status.md` — keputusan status granular: **computed**, reuse `Inbox::attachResponseState()` yang sudah ada (persis rekomendasi blueprint awal, sekarang jadi ADR resmi)
- `docs/audit/clarification-report-m3-fase1-operational-inbox-spec-2026-09-20.md` dan `-plan-2026-09-21.md`

**Keputusan desain yang sudah final (bukan lagi 🔶):**
1. Status granular → **computed** via `ConversationModel::withComputedStatus()`, reuse `attachResponseState()` — ADR-0001
2. Threshold SLA → Hijau `<15m`, Kuning `15-60m`, Merah `>60m`, sumber waktu `last_message_at` (REQ-010)
3. Customer Context → **dibatasi ke Fase 1a dasar**, tidak menarik M4 (konsisten prinsip urutan)
4. Internal Note → kolom `is_internal BOOLEAN` di `messages` (REQ-007), bukan tabel terpisah
5. @mention → **tidak masuk Fase 1** (disederhanakan, tidak disebut lagi di plan — kemungkinan didrop, perlu dikonfirmasi eksplisit kalau perlu dipastikan)

**Pembagian fase (final, dari plan resmi):**
- **Fase 1a** (TASK-001 s/d TASK-006): Queue View 5 tab, Conversation Detail wiring, Snooze dasar — **tanpa migration**. Berhenti di TASK-006 (APPROVAL checkpoint) menunggu konfirmasi Anda sebelum lanjut Fase 1b.
- **Fase 1b** (TASK-007 s/d TASK-014): migration `is_internal`, endpoint Internal Note, SLA Service, filter/pencarian, Alasan Snooze (disimpan sebagai Internal Note, bukan kolom baru — ALT-003 ditolak eksplisit). Berhenti di TASK-014 (APPROVAL) menunggu konfirmasi sebelum lanjut ke Fase 2.
- **Fase 2** (Handoff, Collision detection) — **RISK-003 eksplisit menulis**: "terkunci menunggu M2, plan ini tidak menyentuh area itu sama sekali." Prinsip prasyarat M2 **dihormati secara tertulis** di plan resmi.
- **Fase 3** — tunggu M5, tidak berubah.

**Risiko teknis yang tercatat di plan (lebih detail dari blueprint awal):**
- RISK-001 (High Risk): endpoint Internal Note rawan *silent violation* kalau accidentally ikut update `last_message_at`/`last_message_direction` — bisa merusak badge Tahap A & Queue View tanpa error kelihatan. Mitigasi: test wajib assert kolom itu TIDAK berubah, bukan cuma assert insert sukses.
- RISK-002: `findAll(500)` di filter-after-fetch berpotensi lambat di data besar — diterima sebagai risiko, tidak dimitigasi di Fase 1 (dicatat, bukan diabaikan diam-diam).

Migration baru yang dibutuhkan (final, dari plan):
- `messages.is_internal BOOLEAN NOT NULL DEFAULT FALSE` (satu-satunya migration Fase 1, additive)
- **Tidak ada** kolom `snooze_reason` (ditolak, pakai Internal Note)
- Tabel `conversation_handoffs` untuk Fase 2 — belum masuk plan resmi, baru rencana blueprint awal, perlu dibuatkan spec/plan resmi sendiri saat M2 selesai

---

### M4 — POS / Customer Context ⏳ BELUM DIBAHAS

Baru disinggung sebagai prasyarat untuk Customer Context penuh (order/payment) di Conversation Detail M3. Belum ada rencana detail.

---

### M5 — Intelligence / AI ⏳ BELUM DIBAHAS

Ditunda sampai fondasi M1-M4 stabil. Fase 3 di blueprint M3 (intent filters, AI summary, suggested reply) menunggu ini.

---

## Yang Menggantung — Action Items Konkret

⚠️ **Keputusan prioritas yang perlu Anda ambil sekarang**: M3 plan sudah siap eksekusi (Readiness 97/100), sementara M1 Ticket 01 belum tersentuh sama sekali. Dua opsi:
- **(A) Jalankan M1 Ticket 01 dulu** sebelum mulai eksekusi plan M3 Fase 1a — sesuai urutan roadmap asli, memastikan reliability diukur duluan.
- **(B) Mulai eksekusi M3 Fase 1a sekarang** (plan sudah matang, tidak menyentuh migration/M2), sambil M1 Ticket 01 menyusul paralel — valid karena Fase 1a memang didesain tidak bergantung M1/M2.

Tidak ada jawaban "benar" secara teknis untuk A vs B — keduanya sah. Ini murni soal prioritas kapasitas Anda. Yang penting: **jangan biarkan M1 terus tertunda tanpa batas** karena risiko P0 di baliknya (message loss, duplicate) tetap aktif di produksi selama itu belum ditangani.

Urutan prioritas realistis (dengan asumsi opsi B dipilih — sesuaikan kalau Anda pilih A):

1. **Eksekusi M3 Fase 1a** sesuai `plan-feature-m3-operational-inbox-fase1-v1.0.md` TASK-001 s/d TASK-006 — plan sudah siap pakai, tinggal jalankan di Claude Code.
2. **Approval checkpoint TASK-006** — review hasil Fase 1a sebelum izinkan lanjut Fase 1b.
3. **Paralel atau setelahnya**: jalankan M1 Ticket 01 (baseline test 4 skenario) — file rencana sudah ada (`m1-ticket01-baseline-eksekusi.md`), belum dieksekusi sama sekali.
4. **Rapikan housekeeping environment** — beri label jelas Gateway aktif, bereskan 3 folder lama di drive G, kembalikan/tandai `.env` yang sempat diubah.
5. **Eksekusi M3 Fase 1b** (TASK-007 s/d TASK-014) setelah TASK-006 disetujui.
6. **M1 Ticket 02-16** menyusul setelah Ticket 01 selesai.
7. **M2** dimulai setelah M1 selesai (atau tepatnya ticket-ticket kritis M1 seperti idempotency — konfirmasi urutan pasti saat M1 mendekati akhir).
8. **Fase 2 M3** (Handoff, Collision detection) — **wajib** tunggu M2 selesai, ini sudah tertulis eksplisit di RISK-003 plan resmi, bukan lagi cuma catatan blueprint.

---

## File-File Referensi

**Dari sesi chat ini (di luar repo, mungkin perlu disalin manual kalau belum ada di repo):**
- `handoff-tahap0-claude-code.md` — handoff awal Tahap 0 (historis, sudah tidak relevan — Tahap 0 sudah DONE & merged)
- `checklist-eksekusi-lokal-tahap0.md` — checklist smoke test Tahap 0 (historis)
- `verifikasi-dekripsi-gagal-fromme.md` — investigasi kasus `AC0B72AD…` (historis, sudah masuk decision log resmi)
- `m1-ticket01-baseline-eksekusi.md` — rencana eksekusi Ticket 01 M1, **masih berlaku, belum dieksekusi**
- `Panduan_Layar_AuliaPos_M3.md` — desain 7 layar M3 awal (sudah digantikan oleh spec resmi di repo, simpan sebagai referensi historis saja)
- `blueprint-m3-operational-inbox.md` — blueprint awal (sudah digantikan plan resmi di repo, referensi historis)

**Di repo AuliaPos (branch `v2.2`, sudah ter-push, ini yang jadi acuan resmi sekarang):**
- `docs/decisions/2026-09-19-tahap-0-baseline.md` — decision log Tahap 0 lengkap
- `spec/spec-design-m3-operational-inbox-fase1.md` — spec resmi M3 Fase 1
- `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — **plan eksekusi resmi, 14 task, siap jalan**
- `docs/adr/0001-reuse-response-state-for-queue-view-status.md` — ADR status granular
- `docs/audit/clarification-report-m3-fase1-operational-inbox-spec-2026-09-20.md`
- `docs/audit/clarification-report-m3-fase1-operational-inbox-plan-2026-09-21.md`

**Prinsip update dokumen ini ke depan**: kalau ada progres baru dari sesi Claude Code manapun, jalankan `git fetch` + `git log <base>..<HEAD_terbaru> --oneline` dulu untuk lihat commit yang masuk sebelum percaya status di dokumen ini — jangan asumsikan dokumen ini otomatis sinkron dengan kerja paralel.
