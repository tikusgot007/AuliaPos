# Prompt & Handoff — M1 Gelombang 2, Fase 5: Deploy & Pengukuran Nyata (2026-09-24)

> [!IMPORTANT]
> **Dokumen ini non-normatif.** Sumber normatif tetap `plan/plan-process-m1-wave2-outgoing-idempotency-v1.0.md` (v1.0) dan `spec/spec-process-m1-wave2-outgoing-idempotency.md` v1.1. Bila terjadi konflik, **Plan + Spec menang**. **Kenapa dokumen ini ada:** Fase 4 (TASK-015..TASK-021) sudah selesai dan **TASK-021 sudah DISETUJUI pemilik**, sehingga gerbang Fase 5 terbuka — tetapi Fase 5 **tidak boleh** dieksekusi dari sesi AuliaPos yang mengerjakan Fase 4 karena menyentuh repo WA-Gateway, proses Gateway aktif, dan database kerja berisi data nyata (alasan di §3.4). Sesi itu berhenti tanpa deploy, tanpa `pm2`, dan tanpa migrasi produksi.

## 1. Prompt siap paste

Salin blok berikut ke **sesi baru** (lampirkan berkasnya, jangan hanya menyebut nama):

```text
/sdlc-write-code

Attach: @plan/plan-process-m1-wave2-outgoing-idempotency-v1.0.md (status Planned;
        TASK-015..TASK-021 terisi, TASK-021 APPROVED, TASK-022 memuat sub-langkah (f))
Attach: @spec/spec-process-m1-wave2-outgoing-idempotency.md (v1.1)
Attach: @docs/decisions/2026-09-24-m1-wave2-phase4-aulias-pos-caller.md (§4 F-01/F-03, §5 bukti,
        §6 ringkasan commit, §8 TASK-021 APPROVED)
Attach: @docs/decisions/2026-09-24-m1-wave2-phase3-incoming-dead-letter.md (Fase 3 ditutup)
Attach: @docs/audit/clarification-report-m1-wave2-outgoing-idempotency-plan-2026-09-24.md
        (K-08, K-13 -- wajib dibaca sebelum TASK-023)

LINGKUP SESI INI: FASE 5 SAJA -- TASK-022 (deploy WA-Gateway + sub-langkah AuliaPos (f)),
TASK-023 (pengukuran nyata AC-027/AC-042, minimal 3 kali), TASK-024 (penutupan + handoff).
Jangan mengerjakan ulang Fase 1-4: AuliaPos Fase 4 SUDAH selesai di branch
feature/m1-wave2-outgoing-idempotency (kode commit 8bc4a8e, 5ce8efb, f56446b, 0c53e1c, ef98533, 94845a0).

PRASYARAT (verifikasi dulu, jangan diasumsikan -- semuanya bisa berubah sejak 2026-09-24):
- AuliaPos C:\xampp\htdocs\aulia: `git status --short` kosong; branch feature/m1-wave2-outgoing-idempotency
  berisi seluruh commit Fase 4; suite `vendor/bin/phpunit --no-coverage` hijau.
- WA-Gateway C:\projects\WA-Gateway: status kosong, catat HEAD sebagai titik rollback (terakhir terlihat
  `21a4cb6`), branch feature/m1-wave2-outgoing-idempotency berisi TASK-011/TASK-012 (commit 4010cc1).
- Gateway aktif: `pm2 describe wa-gateway` harus online SEBELUM di-restart; catat statusnya.
- TIDAK ada pekerjaan M3 Fase 1e yang sedang berjalan di file yang sama (RISK-002).

ATURAN EKSEKUSI:
- Satu task = satu commit kecil; setiap perubahan kode membawa ujinya sendiri.
- CON-014: migrasi `aulia_inboxdb` WAJIB dengan persetujuan pemilik + backup lebih dulu; tidak boleh senyap.
- Floor-Guard: dilarang menambah suppression/skip atau menghapus assertion.
- TASK-023 mematikan/menjeda Gateway yang melayani pelanggan nyata -> minta izin eksplisit SEBELUM
  menjalankan, dan siapkan titik rollback tertulis lebih dulu.
- Jangan push tanpa perintah pemilik. JANGAN ubah front-matter plan (itu TASK-024).
- Setelah AuliaPos di-merge ke v2.3, M3 Fase 1e baru boleh mulai plan/kode di atas basis baru (RISK-002).

KELUARAN YANG DIMINTA:
1. TASK-022: deploy Gateway + TASK-022(f) AuliaPos, dengan decision log baru berisi HEAD sebelum/sesudah,
   status PM2, bukti `SHOW COLUMNS`/`SHOW INDEX`, dan hasil smoke test teks + media.
2. TASK-023: minimal 3 putaran pengukuran nyata; catat per putaran (diterima / hilang / duplikat),
   pernyataan jujur batas ASSUMPTION-009, plus pemeriksaan payload tetap identik (AC-040).
3. TASK-024: setelah approval pemilik, ubah front-matter plan ke Completed + badge, tawarkan
   penyimpanan memori, arahkan ke /sdlc-code-review.
```

## 2. Kondisi awal yang sudah diverifikasi (di sesi Fase 4)

| Item | Nilai terverifikasi |
| --- | --- |
| Branch AuliaPos | `feature/m1-wave2-outgoing-idempotency` (satu worktree, `C:\xampp\htdocs\aulia`) |
| Commit kode Fase 4 | `8bc4a8e` (TASK-015), `5ce8efb` (TASK-016), `f56446b` (TASK-017), `0c53e1c` (TASK-018), `ef98533` (TASK-019), `94845a0` (perbaikan F-03) |
| Suite terakhir | `OK (349 tests, 1209 assertions)` — 0 fail, 0 error, 0 skip |
| Bukti AC-040/AC-045 | harness listener HTTP nyata: `24 PASS, 0 FAIL` (`build/ac040-router.php` + `build/scratch-ac040-verify.php`, gitignored) |
| Migration | `app/Database/Migrations/2026-09-24-000001_AddGatewayOperationIdToMessages.php` (dijalankan **hanya** di `aulia_inboxdb_test`) |
| `aulia_inboxdb` (kerja) | **belum** punya kolom `gateway_operation_id` (F-01) |
| Front-matter plan | masih `status: 'Planned'` (perubahan = TASK-024) |
| Push | belum ada; seluruh commit masih lokal |

## 3. Rincian Fase 5

### 3.1 TASK-022 — Deploy Gateway + sub-langkah AuliaPos (f)

Sisi WA-Gateway (sesuai plan, jangan diubah urutannya): `git -C C:\projects\WA-Gateway status --short` kosong → catat HEAD sebagai rollback → `git merge --ff-only feature/m1-wave2-outgoing-idempotency` → `cmd /c "pm2 restart wa-gateway"` → `pm2 describe wa-gateway` (harus `online`, `script path` tetap folder live) → periksa log start (operasi `in_flight` basi, baris terminal yang dipangkas, jumlah baris `dead`). **MUST NOT** memakai `git checkout`/`git reset` dan **MUST NOT** menyentuh `auth/`.

Sisi AuliaPos — sub-langkah **(f)**, keputusan pemilik F-01 opsi (i), dijalankan dalam **satu jendela perubahan** dengan urutan ini:

1. `mysqldump` backup `aulia_inboxdb` → simpan berkasnya dan catat lokasinya.
2. Migrasi kolom ke `aulia_inboxdb`; bukti wajib: `SHOW COLUMNS FROM messages LIKE 'gateway_operation_id'` → `varchar(64)`/`YES`/`NULL`, dan `SHOW INDEX FROM messages` → `uniq_messages_gateway_operation_id`.
3. Re-sync `aulia_inboxdb_test`: `mysqldump --no-data --routines --triggers aulia_inboxdb | mysql aulia_inboxdb_test` (prosedur `docs/ARCHITECTURE.md` §11).
4. Merge branch `feature/m1-wave2-outgoing-idempotency` ke `v2.3` (`--ff-only` bila bisa, kalau tidak `--no-ff`), lalu deploy kode AuliaPos.
5. Smoke test dari UI kasir: **satu kirim teks** + **satu kirim media**; periksa baris `messages` baru punya `gateway_operation_id` terisi dan `send_status='sent'`.

Rollback migrasi: `down()` (drop index + kolom) — aman karena seluruh nilai lama `NULL`. Rollback Gateway: HEAD yang dicatat di langkah pertama.

> [!WARNING]
> Urutan 1–4 tidak boleh dibalik: begitu kode AuliaPos live, setiap kirim sukses menulis `gateway_operation_id`, sehingga kolom yang belum ada akan membuat **setiap** kirim gagal (`Unknown column`). Jangan menunda langkah 2 setelah langkah 4.

### 3.2 TASK-023 — Pengukuran nyata AC-027/AC-042 (butuh izin khusus)

Ini satu-satunya task yang **mengganggu Gateway aktif** yang melayani pelanggan nyata. Minta izin eksplisit sebelum menjalankan, dan jangan lakukan pada jam sibuk.

Prosedur spec §13 butir 2, minimal **3 kali**: perlambat respons `/send` (jeda terkontrol atau blokir port sementara) supaya cURL AuliaPos timeout (teks 10 s, media 30 s), kasir mengirim lewat UI, lalu:

| Putaran | Yang harus dibuktikan |
| --- | --- |
| Kirim ulang **di dalam** lease 35 s | Gateway membalas `409 SEND_IN_PROGRESS`, `sendMessage` tidak dipanggil lagi, UI menampilkan "hasil belum pasti", pelanggan menerima **maksimal satu** pesan (AC-027) |
| Kirim ulang **setelah** lease, operasi sudah `sent` | `200` + `replayed:true` (AC-042) |
| Selama seluruh pengukuran | payload `/send` dan `/send-media` tetap identik (AC-040) dan jumlah baris masuk baru bertambah sesuai pesan yang benar-benar terkirim, bukan jumlah percobaan |

> [!IMPORTANT]
> **Gotcha K-13 (jangan lupa saat menulis skenario):** percobaan **pertama** berakhir sebagai AuliaPos **502** (timeout cURL), jadi keadaan UI "hasil belum pasti" baru muncul pada **kirim ulang di dalam lease** (`409 SEND_IN_PROGRESS`). Prosedur versi lama menyiratkan keadaan itu muncul di percobaan pertama — itu tidak dapat terjadi tanpa mengubah kode.

Catat per putaran: diterima / hilang / duplikat, plus pernyataan jujur batas **ASSUMPTION-009** (jendela crash sempit belum tertutup penuh; penutup penuhnya GW-21 di M2). AC-026(b) tetap `stub-only` dan `422` tetap `[Assumed / Out of Scope]` — jangan diklaim tertutup.

### 3.3 TASK-024 — Penutupan

Setelah pemilik menyatakan Wave 2 selesai: ubah front-matter plan `status: 'Planned'` → `'Completed'` + badge `status-Planned-yellow` → `status-Completed-brightgreen`; tawarkan penyimpanan progres ke `memory.instructions.md` (`memory-manager`); arahkan ke `/sdlc-code-review` lalu `/sdlc-audit-consistency` (opsional). Sampaikan juga follow-up **RISK-002**: setelah AuliaPos di-merge, M3 Fase 1e boleh mulai plan/kode di atas basis baru.

### 3.4 Mengapa Fase 5 bukan lanjutan sesi Fase 4

| Batas | Alasan |
| --- | --- |
| Repo berbeda | TASK-022 bekerja di `C:\projects\WA-Gateway` dan pada proses Gateway; sesi Fase 4 dibatasi ke repo AuliaPos |
| Trafik nyata | TASK-023 menjeda/memperlambat Gateway yang sedang melayani pelanggan; butuh izin pada saat itu juga |
| Database produksi | Sub-langkah (f) memigrasi `aulia_inboxdb` yang berisi percakapan nyata; CON-014 melarang eksekusi senyap |
| Aturan sesi-per-fase | Preferensi pemilik: satu fase = satu sesi baru, dengan handoff seperti dokumen ini |

## 4. Prasyarat Fase 5 — status terkini

| Prasyarat | Status | Bukti |
| --- | --- | --- |
| Fase 1–3 (Gateway) selesai + TASK-014 disetujui | ✅ | `docs/decisions/2026-09-24-m1-wave2-phase3-incoming-dead-letter.md` §7 |
| Fase 4 selesai (TASK-015..TASK-020) + remediasi F-03 | ✅ | `docs/decisions/2026-09-24-m1-wave2-phase4-aulias-pos-caller.md` §5, §7; commit `94845a0` |
| **TASK-021 disetujui pemilik** | ✅ | decision log Fase 4 §8 (approval 2026-09-24) + baris TASK-021 di plan |
| Working copy AuliaPos bersih | ✅ (saat handoff) | `git status --short` kosong |
| Suite AuliaPos hijau | ✅ | `OK (349 tests, 1209 assertions)`, 0 skip/suppression |
| Kolom di `aulia_inboxdb` (kerja) | ❌ **belum** | F-01; dijalankan sebagai TASK-022(f) dengan backup |
| M3 Fase 1e belum jalan (RISK-002) | ✅ | masih Spec-only, tidak ada plan/kode |
| GW-09 | ⏳ terbuka | baru tertutup setelah TASK-023 |

> [!CAUTION]
> Status di atas adalah snapshot 2026-09-24 dari sesi Fase 4. **Verifikasi ulang semuanya di sesi baru** — status Gateway/PM2, HEAD kedua repo, dan isi `aulia_inboxdb` bisa berubah (Gateway melayani trafik nyata).

## 5. Catatan yang mudah terlewat

| Kode | Ringkas | Perlakuan di Fase 5 |
| --- | --- | --- |
| F-01 | Kolom belum ada di `aulia_inboxdb` | Sub-langkah (f); wajib backup + bukti `SHOW COLUMNS`/`SHOW INDEX` + izin pemilik |
| F-02 | `php spark migrate` untuk `$DBGroup='inbox'` menyasar DB kerja, sedangkan DB uji adalah **salinan skema** | Migrasi DB kerja dengan mekanika yang benar; setelahnya re-sync DB uji (`mysqldump --no-data`) supaya tidak drift |
| F-03 | Jalur media tidak dedupe | **Sudah diperbaiki** (`94845a0`); jangan dikerjakan ulang |
| K-13 | Urutan bukti AC-027 | Keadaan "hasil belum pasti" muncul pada kirim ulang di dalam lease, bukan pada percobaan pertama yang berakhir 502 |
| K-10 | AC-026(b) `stub-only`; `422` `[Assumed / Out of Scope]` | Jangan diklaim tertutup; label tertulis wajib |
| K-04 | `DELIVERY_MAX_ATTEMPTS=100` + `DELIVERY_DEAD_AFTER_MS` belum dikalibrasi | Tinjau setelah pemadaman nyata bila ada |
| RISK-002 | Working copy AuliaPos dibagi dengan M3 | Merge Wave 2 lebih dulu; M3 Fase 1e baru mulai setelah itu |
| DBDebug | Grup DB `inbox` memakai `DBDebug = true` | Kesalahan DB muncul sebagai exception (HTTP 500), bukan gagal senyap — berguna saat membaca bukti |

## 6. Sumber referensi

- `plan/plan-process-m1-wave2-outgoing-idempotency-v1.0.md` — TASK-022 (termasuk sub-langkah (f)), TASK-023, TASK-024, CON-011/CON-014, RISK-002, §9 Rollback Fase 4.
- `spec/spec-process-m1-wave2-outgoing-idempotency.md` v1.1 — REQ-039..REQ-041, AC-027, AC-031/AC-032/AC-040/AC-042/AC-043, ASSUMPTION-009, §13 prosedur pengukuran.
- `docs/decisions/2026-09-24-m1-wave2-phase4-aulias-pos-caller.md` — §4 keputusan F-01/F-03, §5 bukti verifikasi, §6 ringkasan commit per task, §8 TASK-021 APPROVED.
- `docs/decisions/2026-09-24-m1-wave2-phase3-incoming-dead-letter.md` — penutupan Fase 3, commit Gateway, batas bukti.
- `docs/audit/clarification-report-m1-wave2-outgoing-idempotency-plan-2026-09-24.md` — K-04/K-08/K-10/K-13.
- `docs/ARCHITECTURE.md` §11 — prosedur sinkronisasi skema DB uji.
- `build/ac040-router.php`, `build/scratch-ac040-verify.php` — harness AC-040/AC-045 (gitignored) yang bisa dijalankan ulang: `php build/scratch-ac040-verify.php <port>` dengan `php -S 127.0.0.1:<port> build/ac040-router.php`.
