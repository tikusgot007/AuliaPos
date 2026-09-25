# Handoff — M3 Fase 1e (Pencarian Isi Pesan), Sesi Code Review

> [!IMPORTANT]
> **Dokumen ini non-normatif.** Sumber normatif tetap `spec/spec-design-m3-operational-inbox-fase1.md` (rev 1.4) dan `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` (v1.4, `Completed`). Bila terjadi konflik, Spec + Plan menang.

## 1. Prompt siap paste

Salin blok berikut ke **sesi baru** (persona review) dan lampirkan berkasnya:

```text
/sdlc-code-review

Attach: @spec/spec-design-m3-operational-inbox-fase1.md (rev 1.4)
Attach: @plan/plan-feature-m3-operational-inbox-fase1-v1.0.md (v1.4, status Completed)
Attach: @docs/walkthrough-m3-fase1e-message-search-2026-09-25.md (bukti AC-014..AC-016)
Attach: @docs/audit/clarification-report-m3-fase1e-message-search-2026-09-25.md (R-01..R-06, F-01..F-05)

LINGKUP REVIEW: hanya perubahan Fase 1e di repo ini (branch v2.3):
- commit 0f22806 dan 79ba24b (TASK-023), 596dd5d (TASK-024), 7ba0308 (TASK-025), 1272b12 (TASK-026)
- berkas kode: app/Services/InboxMatchSnippetService.php, app/Controllers/Inbox.php,
  app/Views/inbox/index.php, app/Commands/SeedFase1ePerf.php
- berkas test: tests/unit/InboxMatchSnippetServiceTest.php,
  tests/session/OperationalInboxConversationTest.php, tests/session/OperationalInboxScreenTest.php

Fokus yang diminta:
1. Kebenaran predikat q: identitas OR isi pesan, escapeLikeString() + like(..., 'both', false),
   pengecualian deleted_at / text NULL / kosong, satu baris per conversation (ROW_NUMBER),
   tie-break message_timestamp lalu id.
2. Batas match_snippet: potong() murni, hanya mb_*, maks 120 karakter (<= 122 dengan "…"),
   null saat cocok lewat identitas atau tanpa q (CL-018), dan bentuk payload { text, is_internal, message_timestamp }.
3. Keamanan rendering: escapeHtmlInbox untuk snippet + label "Internal"; pastikan tidak ada innerHTML mentah.
4. Pengaman putaran: putaranDaftarBerjalan dilepas di .finally(), kata kunci baru tetap dikirim,
   hasil kata kunci lama dibuang, tanpa AbortController/timeout (sesuai CL-020).
5. Risiko performa LIKE '%q%' tanpa index (RISK-007) — apakah bukti AC-016 cukup kuat dan apa pemicu
   untuk meninjau ulang.
6. Guard SeedFase1ePerf (menolak database non-perf) dan isolasinya dari composer test/CI.

JANGAN mengubah kode: hasilkan rencana perbaikan, bukan patch (aturan skill review).
```

## 2. Kondisi akhir yang sudah diverifikasi

| Item | Nilai |
| --- | --- |
| Repo / branch | `C:\xampp\htdocs\aulia`, branch `v2.3` |
| Commit kode fase | `0f22806`, `79ba24b`, `596dd5d`, `7ba0308`, `1272b12` (lima commit, +1139 / −8) |
| Commit dokumen | `18c860f` (bukti TASK-028) dan commit penutupan TASK-029 (plan + walkthrough + brief ini) |
| Suite penuh | `OK (395 tests, 1443 assertions)`, exit 0, nol failure/error/skip |
| Gate makro | dijalankan dua kali saat penutupan (9,73 s dan 9,93 s) dengan hasil identik |
| Boundary check (CON-004) | hanya 7 berkas `app/`+`tests/`; tanpa migration, index, route, parameter, atau endpoint baru |
| DB live | `aulia_inboxdb` 30 conversation / 152 pesan (10 terlihat), nol baris `fase1eperf-%` |
| DB perf | `aulia_inboxdb_perf` sudah dihapus (tidak ada di `information_schema.SCHEMATA`) |
| DB uji | `aulia_inboxdb_test`, guard fail-closed di `tests/_support/bootstrap.php` tetap aktif |
| `.env` | `database.inbox.database = aulia_inboxdb` (kembali normal) |
| Apache lokal | masih berjalan (pid 5944) sejak pengukuran AC-016; aman dimatikan |
| Artefak uji sengaja ditinggal | catatan Internal Note `zzztag` di conversation terlihat `11746` (`messages.id = 305`, `is_internal = 1`) sebagai bukti checklist plan (b) dan (d) |

## 3. Cara mereproduksi diff untuk review

```powershell
cd C:\xampp\htdocs\aulia
git --no-pager log --oneline 0f22806^..HEAD
git --no-pager diff --stat 0f22806^..HEAD -- app tests
git --no-pager diff 0f22806^..HEAD -- app/Services/InboxMatchSnippetService.php app/Controllers/Inbox.php
git --no-pager diff 0f22806^..HEAD -- app/Views/inbox/index.php app/Commands/SeedFase1ePerf.php
```

Peta berkas yang berubah:

- **Baru:** `app/Services/InboxMatchSnippetService.php` (+84), `app/Commands/SeedFase1ePerf.php` (+328), `tests/unit/InboxMatchSnippetServiceTest.php` (+193).
- **Berubah:** `app/Controllers/Inbox.php` (+100), `app/Views/inbox/index.php` (+67), `tests/session/OperationalInboxConversationTest.php` (+322), `tests/session/OperationalInboxScreenTest.php` (+53).
- **Dokumentasi:** plan (v1.4), walkthrough Fase 1e, brief ini.

## 4. Bukti yang sudah tersedia (jangan dijalankan ulang tanpa alasan)

- **Gate makro & boundary check:** baris TASK-028 di plan (dua kali pengukuran penutupan) dan §8 walkthrough.
- **AC-014:** perintah + angka per kelas test di §5 walkthrough; cakupan (a)–(i) di baris TASK-024 plan.
- **AC-015:** tabel checklist manual per huruf plan/Spec di §6 walkthrough, termasuk bukti DevTools Network dan catatan batas pengamatan.
- **AC-016:** tabel 3×3 + baseline tanpa `q` di §7 walkthrough; detail provisioning, seeding, dan payload spot-check di baris TASK-027 plan.
- **Log mentah pengukuran:** berkas sementara di `%TEMP%` mesin ini (respons JSON 12 request, log seeder, log phpunit). Berkas itu **tidak** ada di repo — bila angka perlu diaudit ulang, ulangi prosedur TASK-027 (§10 walkthrough).

> [!WARNING]
> **Aturan menjalankan test.** DB uji `aulia_inboxdb_test` dipakai bersama dan `setUp()` setiap kelas Inbox memanggil `emptyTable()`. Jangan menjalankan dua proses PHPUnit bersamaan: pada 2026-09-25 cara itu menghasilkan 1 lalu 7 failure di `OperationalInboxConversationTest` dan 3 error di `InboxHandoffTest`, semuanya hijau begitu dijalankan sekuensial. Pakai `composer test` (satu proses, seluruh suite) sebagai gate.

## 5. Batas bukti yang tidak boleh diklaim tertutup

- **Plan (e) separuh pertama** — "tick saat putaran berjalan tidak memulai request baru" dibuktikan lewat kode (`app/Views/inbox/index.php:2553-2556`, `1008-1036`) dan test TASK-025, **bukan** rekaman browser; satu putaran terlalu cepat (122-327 ms live, ± 1,0 s pada 200.000 pesan) untuk melewati tick 6 detik.
- **`docs/ARCHITECTURE.md` §11** masih berutang paragraf `aulia_inboxdb_perf` + `aulia:seed-fase1e-perf`; auditor mengarahkannya ke `/sdlc-map-architecture` (`docs/audit/clarification-report-m3-fase1e-message-search-2026-09-25.md` §3.3) dan bukan bagian Fase 1e.
- **RISK-007** (`LIKE '%q%'` tanpa index) tetap terbuka dan hanya dipantau; AC-016 lulus pada 200.000 baris, bukan pada skala produksi di masa depan.
- **RISK-009** (pengaman per layar) diterima; dua tab bisa mengirim dua pencarian sekaligus.
- **Cakupan AC-014 dibatasi teks ASCII** (Spec §6/§12); huruf beraksen/non-latin tidak diuji.
- **Artefak uji di DB live** (`zzztag`, conversation `11746`) sengaja dibiarkan; hapus hanya bila pemilik produk memintanya.

## 6. Setelah review

- Bila reviewer menemukan perbaikan, jalankan lewat `/sdlc-write-code` dengan plan perbaikan resmi dari review (skill review tidak mengubah kode).
- Item lanjutan yang berdiri sendiri: `/sdlc-map-architecture` untuk paragraf `docs/ARCHITECTURE.md` §11; opsional `/code-janitor` untuk membersihkan catatan uji di conversation `11746` dan mematikan Apache pid 5944.
- Simpan checkpoint sesi lewat `memory-manager` sebelum berpindah fase (proyek ini selalu menutup fase dengan checkpoint memory).

## 7. Rujukan

- `spec/spec-design-m3-operational-inbox-fase1.md` (rev 1.4) — REQ-014..REQ-017, CL-016..CL-021, AC-014..AC-016.
- `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` (v1.4) — Phase 5 / TASK-023..TASK-029.
- `docs/walkthrough-m3-fase1e-message-search-2026-09-25.md` — walkthrough fase (bukti + batas).
- `docs/audit/clarification-report-m3-fase1e-message-search-2026-09-25.md` — klarifikasi Fase 1e.