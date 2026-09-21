# Handoff ke Claude Code lokal — Eksekusi M1 Gelombang 1 (2026-09-21)

> **Cara pakai:** sematkan/paste dokumen ini di awal sesi Claude Code baru di **Aan-PC** (bukan sandbox). Ditulis oleh sesi Claude Code sandbox setelah menemukan konflik antara kerja ad-hoc yang sudah dilakukan dan plan resmi yang sudah ada di repo.

## Ringkasan situasi

Ada dua jalur kerja M1 Ticket 02 yang berjalan **tidak sinkron**:

1. **Plan resmi** (`plan/plan-process-m1-wave1-incoming-reliability-v1.0.md`, v1.1, Readiness proyeksi 94/100, sudah di-*clarify* lewat `docs/audit/clarification-report-m1-wave1-incoming-reliability-2026-09-21.md`) — merge ke `v2.2` lewat PR #36/#37 **sebelum** sesi sandbox mulai kerja. Plan ini dirancang untuk dieksekusi di worktree `C:\projects\WA-Gateway-m1`, branch `feature/stage-1-reliability`, lewat `/sdlc-write-code`.
2. **Kerja ad-hoc sandbox** (repo WA-Gateway, branch `claude/buka-todo-chat-omnc7k`) — sesi sandbox **tidak tahu plan resmi ini ada** saat mulai kerja (diminta lanjut dari `docs/decisions/2026-09-21-m1-ticket02-audit-enqueue.md` langsung tanpa cek `/plan/` dulu). Menghasilkan fix E-01, E-03, E-04, E-05, E-06, E-09 dengan pendekatan sendiri (bukan sesuai spec), lalu **ter-merge ke `feature/stage-1-reliability`** lewat PR #2 sebelum konflik disadari.

## Yang sudah terjadi di `feature/stage-1-reliability` (WA-Gateway)

Setelah PR #2 dan PR #3 (revert), state `feature/stage-1-reliability` sekarang:

| Temuan | Status | Catatan |
|---|---|---|
| E-01 (`type='append'` diterima) | **Direvert** (PR #3) | Bentrok dengan D-03/ALT-002 plan resmi: Baileys juga memancarkan `append` untuk kiriman Gateway sendiri; tanpa `ownSentRegistry` (TASK-008/009/010), berisiko duplikat pesan keluar di Inbox. Kembali ke `type !== 'notify'` seperti semula. |
| E-03 (`enqueue()` validasi field wajib, throw kalau kosong) | **Tetap** (PR #2) | **Belum sesuai kontrak plan resmi** (TASK-001): plan minta `enqueue()` mengembalikan `{status:'inserted'\|'duplicate'}` dan melempar tipe error khusus (`EnqueueValidationError`) yang TIDAK di-retry (beda dari error tak terduga yang DI-retry). Implementasi ad-hoc cuma `throw new Error(...)` generik — tidak membedakan keduanya. **Perlu diperiksa ulang saat eksekusi TASK-001**, kemungkinan perlu direfactor, bukan dipakai apa adanya. |
| E-04 (`_enqueueWithRetry`, 3× percobaan 200ms) | **Tetap** (PR #2) | Beda dari plan resmi (TASK-002/003/004): jeda plan resmi `50,200,800`ms (bukan `200,200,200`), dan plan resmi minta ada **penampung overflow** (`overflowBuffer.js`, TASK-003) yang dikuras di awal siklus worker — implementasi ad-hoc tidak punya ini, kalau retry 3× habis pesan tetap hilang (cuma dilog). **Ini bukan implementasi TASK-002/003/004, cuma pendekatan sementara yang lebih lemah.** |
| E-05 (timeout query LID 5 detik) | **Tetap** (PR #2) | Beda dari plan resmi (TASK-013): timeout plan resmi `2000ms` (bukan `5000ms`), dan plan resmi minta **cache negatif 60 detik** per JID supaya JID yang baru gagal tidak di-query ulang terus-menerus — implementasi ad-hoc tidak punya cache negatif. |
| E-06 (fallback pesan minimal) | **Direvert** (PR #3) | Bentrok langsung dengan ALT-004 plan resmi (ditolak eksplisit): pesan minimal jadi poison message karena kontrak AuliaPos menolak event tidak lengkap. Kembali ke: kegagalan hanya dilog. |
| E-09 (karantina + pemulihan `.bak` JSON fallback) | **Tetap** (PR #2) | Cukup dekat dengan plan resmi (TASK-015), tapi belum diverifikasi detail penamaan/urutan operasi sama persis dengan spec REQ-016/017. **Periksa saat eksekusi TASK-015**, mungkin bisa dipertahankan atau perlu disesuaikan kecil. |

**Semua perubahan ad-hoc di atas (yang masih tersisa: E-03/E-04/E-05/E-09) hanya diuji dengan simulasi/mock di sandbox** (`test/simulate-enqueue-failure.js`, `simulate-e05-lid-timeout.js`, `simulate-e09-json-recovery.js`), **belum pernah dijalankan dengan Gateway nyata**.

## Keputusan yang perlu diambil di sesi lokal ini

Plan resmi (`plan/plan-process-m1-wave1-incoming-reliability-v1.0.md`) adalah **rencana yang lebih matang** — sudah lewat proses spec → clarify → plan → clarify, readiness 94/100, requirement bernomor (REQ-001 s/d REQ-019), acceptance criteria bernomor (AC-001 s/d AC-018), alternatif yang dipertimbangkan dan ditolak dengan alasan eksplisit (ALT-001 s/d ALT-004). Rekomendasi kuat: **jadikan plan resmi ini sumber kebenaran, bukan kerja ad-hoc sandbox.**

Sebelum mulai eksekusi TASK-001 dst., putuskan salah satu:

- **(A) Anggap E-03/E-04/E-05/E-09 ad-hoc sebagai draft awal, timpa penuh sesuai spec plan resmi saat mengerjakan TASK-001, TASK-002/003/004, TASK-013, TASK-015.** Ini yang paling konsisten dengan proses SDLC proyek — jangan asumsikan kode ad-hoc "sudah beres" hanya karena arahnya mirip.
- **(B) Audit dulu tiap file yang disentuh ad-hoc, putuskan per-task mana yang bisa dipertahankan vs ditulis ulang**, kalau ingin menghemat kerja yang kebetulan sudah benar.

Yang **tidak disarankan**: melanjutkan kode ad-hoc sebagai final tanpa mencocokkan ke REQ-*/AC-*/TASK-* plan resmi — beberapa perbedaan (retry delay, overflow buffer, cache negatif LID, tipe error validasi) bukan sekadar gaya kode, tapi bagian dari kontrak yang sudah dirancang plan resmi dan akan diuji lewat TASK-006/011/016 dengan AC spesifik.

## Instruksi eksekusi

1. **Baca dulu** `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` dan `spec/spec-process-m1-wave1-incoming-reliability.md` (repo AuliaPos) secara utuh — jangan cuma dari ringkasan di dokumen ini.
2. **Jangan** kerja di `C:\projects\WA-Gateway` (Gateway produksi aktif) — plan resmi eksplisit minta worktree terpisah `C:\projects\WA-Gateway-m1` (CON-005). Siapkan worktree itu dulu kalau belum ada.
3. Branch kerja: `feature/stage-1-reliability` (sudah berisi commit ad-hoc E-03/E-04/E-05/E-09 dari sandbox — lihat tabel di atas untuk apa yang perlu diperiksa ulang per task).
4. Jalankan lewat `/sdlc-write-code`, ikuti EXECUTION DIRECTIVE di plan: fase demi fase, **berhenti di tiap TASK-xxx APPROVAL** menunggu persetujuan eksplisit user.
5. TASK-017 (AC-001, uji `pm2 stop wa-gateway` nyata) **mematikan Gateway produksi** — perlu approval eksplisit terpisah, dan **hanya boleh dijalankan >21:00 atau <08:00** (RISK-003, klarifikasi v1.1).
6. Setelah tiap fase selesai dan diverifikasi, update `docs/TODO-CHAT.md` dan tulis/lengkapi decision log di `docs/decisions/` — jangan biarkan dokumen status jadi basi seperti yang sempat terjadi dengan kerja ad-hoc ini.

## Yang masih menggantung dari sesi sandbox (di luar scope plan resmi)

- **E-02 dan E-07** (pesan berbungkus/ephemeral, upsert tanpa konten) — eksplisit di luar scope plan resmi juga (Bagian 1.1 spec). Butuh verifikasi dengan WhatsApp nyata (obrolan pesan sementara untuk E-02, pengamatan stub dekripsi untuk E-07) sebelum diputuskan jadi task baru.
- **Ticket 01 Baseline 1 re-run** — kalau setelah TASK-017 (AC-001) lulus 3×, sebaiknya juga ulangi ringkas skenario burst 45 pesan dari Ticket 01 untuk pastikan tidak ada regresi di luar skenario restart murni.

## Referensi

- `docs/decisions/2026-09-21-m1-ticket02-audit-enqueue.md` — audit asli E-01 s/d E-09
- `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` — plan resmi (sumber kebenaran)
- `spec/spec-process-m1-wave1-incoming-reliability.md` — spec resmi
- `docs/audit/clarification-report-m1-wave1-incoming-reliability-2026-09-21.md` — hasil klarifikasi
- WA-Gateway PR #2 (merge ad-hoc), PR #3 (revert E-01/E-06) — riwayat lengkap ada di git log `feature/stage-1-reliability`
