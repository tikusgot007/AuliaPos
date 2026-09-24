# Prompt & Handoff — `/sdlc-write-code` M1 Gelombang 2, Fase 4 (2026-09-24)

> [!IMPORTANT]
> **Dokumen ini non-normatif.** Sumber normatif tetap `plan/plan-process-m1-wave2-outgoing-idempotency-v1.0.md` (v1.0, commit `4fba319`) dan `spec/spec-process-m1-wave2-outgoing-idempotency.md` v1.1 (commit `7897d38`). Bila terjadi konflik, **Plan + Spec menang**. Dokumen ini adalah brief operasional + prompt siap paste untuk sesi `/sdlc-write-code` Fase 4. **Kenapa dokumen ini ada:** sesi 2026-09-24 diminta menjalankan **Fase 5** (TASK-022..TASK-024) dengan asumsi Fase 4 selesai dan TASK-021 disetujui — verifikasi read-only membuktikan asumsi itu **tidak benar** (§5.4), sehingga Fase 5 dibatalkan sebelum menyentuh folder live, dan pekerjaan dialihkan ke handoff Fase 4 ini. Tidak ada kode, migration, deploy, atau `pm2 restart` yang dijalankan.

## 1. Prompt siap paste

Salin blok berikut ke **sesi baru** (lampirkan berkasnya, jangan hanya menyebut nama):

```text
/sdlc-write-code

Attach: @plan/plan-process-m1-wave2-outgoing-idempotency-v1.0.md (v1.0, status Planned, commit 4fba319;
        kolom Completed TASK-001..014 sudah terisi)
Attach: @spec/spec-process-m1-wave2-outgoing-idempotency.md (v1.1, commit 7897d38)
Attach: @docs/decisions/2026-09-24-m1-wave2-phase3-incoming-dead-letter.md (Fase 3 ditutup, TASK-014 APPROVED)
Attach: @docs/audit/clarification-report-m1-wave2-outgoing-idempotency-plan-2026-09-24.md
        (F-01, F-02, K-08, K-09, K-10, K-13 -- wajib dibaca sebelum TASK-016)

LINGKUP SESI INI: FASE 4 SAJA -- TASK-015..TASK-021 (sisi pemanggil AuliaPos: branch, migration,
kirimKeConversation, callGatewaySend*, UI operation_id), lalu BERHENTI di TASK-021 (APPROVAL).
Jangan menyentuh Fase 5 (TASK-022..024: deploy Gateway, pm2 restart, pengukuran nyata AC-027/AC-042).
Fase 1-3 (Gateway) SUDAH selesai dan DISETUJUI pemilik; jangan dikerjakan ulang.

KONDISI AWAL (verifikasi dulu, jangan diasumsikan):
- AuliaPos: C:\xampp\htdocs\aulia pada branch v2.3, HEAD 4fba319, `git status --short` kosong.
  Buat branch feature/m1-wave2-outgoing-idempotency SEBELUM perubahan pertama (TASK-015).
  Working copy ini DIBAGI dengan pekerjaan M3 (RISK-002) -- jangan sentuh apiConversations(),
  ConversationModel, InboxGatewayApi; batasi ke kirimKeConversation(), callGatewaySend(),
  callGatewaySendMedia(), form balas di index.php, satu migration baru, dan tests/.
- WA-Gateway: JANGAN disentuh di Fase 4. Worktree C:\projects\WA-Gateway-m1w2 sudah di 4010cc1;
  folder live C:\projects\WA-Gateway tetap READ-ONLY di 21a4cb6 sampai TASK-022.

ATURAN EKSEKUSI:
- Satu task = satu commit kecil; tiap task kode mengirim ujinya pada penambahan yang sama.
- Floor-Guard: dilarang menambah suppression/skip atau menghapus assertion.
- CON-014: DILARANG menjalankan `php spark migrate` pada database kerja aulia_inboxdb tanpa
  persetujuan eksplisit pemilik. Migrasi DB uji memakai mekanika F-02 opsi (a):
  CI_ENVIRONMENT=testing + `php spark migrate --dbgroup inbox`, lalu verifikasi SHOW COLUMNS pada
  aulia_inboxdb_test. Migration DB kerja = SATU task DEPLOY terpisah yang menunggu keputusan pemilik
  (F-01) -- jangan dikerjakan diam-diam.
- Gate uji (K-09): baseline RIIL = hasil terukur tepat sebelum perubahan (terukur 2026-09-24:
  OK (328 tests, 1102 assertions)) + seluruh uji baru; exit code 0, nol skip, nol suppression.
- Jangan ubah kontrak POST /api/inbox/gateway/messages (CON-005/AC-040); payload harus identik.
- Setiap kali ragu soal urutan/isi task, tanya dulu; jangan mengarang task baru (RISK-009).
- JANGAN push tanpa perintah pemilik. Jangan menaikkan status plan di front-matter (itu TASK-024).

KELUARAN YANG DIMINTA:
1. TASK-015..TASK-020 dieksekusi + TASK-020 VERIFY, lalu BERHENTI di TASK-021 (APPROVAL).
2. Bukti TASK-020 apa adanya: output `vendor/bin/phpunit --no-coverage` (jumlah test/assertion),
   perbandingan payload AC-040, hasil AC-041/AC-045/AC-046, dan `git --no-pager diff --stat` yang
   membuktikan hanya berkas CON-012 yang tersentuh.
3. Ringkasan commit per task + decision log Fase 4 BARU di docs/decisions/ (jangan menimpa log lain).
4. Boleh mengisi kolom Completed/Date TASK-015..TASK-021 di plan; JANGAN ubah front-matter.
5. Akhiri tiap langkah dengan 3 baris: Selesai / Belum / Masih dalam tujuan awal? YA/TIDAK.
```

## 2. Kondisi terverifikasi saat handoff dibuat (2026-09-24)

| Objek | Fakta terukur | Perintah pembuktian |
| --- | --- | --- |
| AuliaPos | branch `v2.3`, HEAD `4fba319` ("docs: close M1 wave 2 phase 3"), working copy **bersih** | `git -C C:\xampp\htdocs\aulia status --short` → kosong |
| Branch M1 W2 AuliaPos | **belum ada** (`git branch -a --list '*m1-wave2*'` → kosong) | TASK-015 belum dijalankan |
| Migration | hanya `2026-09-22-000001_AddIsInternalToMessages.php` dan `2026-09-23-000001_CreateConversationHandoffs.php` | TASK-016 belum ada |
| Kode pengirim kunci | `operation_id`/`gateway_operation_id` → **0 kecocokan** di `app/Controllers/Inbox.php` + `app/Views/inbox/index.php` | TASK-017/018/019 belum ada |
| Uji AuliaPos | `tests/database/` + `tests/session/` → **0 berkas** memuat `gateway_operation_id` | TASK-020 belum ada |
| Gateway worktree | `C:\projects\WA-Gateway-m1w2` @ `4010cc1` (8 commit di atas `21a4cb6`), branch `feature/m1-wave2-outgoing-idempotency` | tidak disentuh Fase 4 |
| Gateway live | `C:\projects\WA-Gateway` @ `21a4cb6` (`master`), working copy bersih; `pm2` `wa-gateway` **online**, uptime 23h, `script path = C:\projects\WA-Gateway\src\app\index.js` | read-only; **jangan** restart di Fase 4 |

**Rantai commit Gateway (warisan Fase 1–3, jangan diulang):** `55a1ae1` TASK-002, `6fe151a` TASK-003, `bdbf534` TASK-004, `5a48311` TASK-005 VERIFY, `62e92c2` TASK-007, `e0f5585` TASK-008, `c766d6a` TASK-011, `4010cc1` TASK-012.

## 3. Lingkup Fase 4 (TASK-015..TASK-021) dan batas yang dilarang

| Task | Repo | Isi singkat | AC | Dep |
| --- | --- | --- | --- | --- |
| TASK-015 | AP | Catat HEAD bersih sebagai basis, buat branch `feature/m1-wave2-outgoing-idempotency`, tulis catatan urutan RISK-002 ke decision log baru | CON-012 | - |
| TASK-016 | AP | Migration additive `messages.gateway_operation_id VARCHAR(64) NULL` + indeks UNIQUE, grup DB `inbox`, `up()`/`down()` | CON-009, AC-041 (skema) | TASK-015 |
| TASK-017 | AP | `Inbox::kirimKeConversation()` — baca `operation_id` dari request kasir (JANGAN buat kunci di server), teruskan ke `callGatewaySend*`, dedupe baris `messages` lewat `gateway_operation_id` sebelum `insert` kedua | REQ-039, REQ-041, AC-041, AC-044 | TASK-016 |
| TASK-018 | AP | `callGatewaySend()`/`callGatewaySendMedia()` mengembalikan `error_code`, `state`, `replayed`; cabang `SEND_IN_PROGRESS`/`SEND_UNRESOLVED` = "hasil belum pasti", `OPERATION_ID_REUSED` = minta kunci baru, `NOT_CONNECTED`/`DEAD_LETTERED` = gagal biasa | REQ-040, REQ-041, AC-045, AC-046 | TASK-017 |
| TASK-019 | AP | Form balas di `app/Views/inbox/index.php` + JS: `operation_id` pada state composer (`crypto.randomUUID()` + fallback hex), dipakai ulang saat kirim ulang, dibuang saat sukses/isi berubah, baru saat `OPERATION_ID_REUSED`; tampilkan keadaan "hasil belum pasti, jangan kirim ulang dulu" | REQ-039, REQ-041, AC-046 | TASK-018 |
| TASK-020 | - | **VERIFY**: `vendor/bin/phpunit --no-coverage` 100% lulus (gate K-09), AC-040 payload identik, AC-045/AC-046 bukti controller + render, diff hanya menyentuh berkas CON-012 | AC-040, AC-045, AC-046, CON-012, CON-013 | TASK-019 |
| TASK-021 | - | **APPROVAL**: berhenti, tunggu konfirmasi eksplisit pemilik termasuk hasil suite; catat ringkasan commit per task di decision log | - | - |

**Dilarang di Fase 4** (plan §2 + CON-012 + RISK-002 + RISK-009):

- Menyentuh Fase 5: `git merge --ff-only` ke folder live, `pm2 restart/stop`, pengukuran nyata AC-027/AC-042, `TASK-022..TASK-024`.
- Menyentuh repo WA-Gateway (worktree maupun live), folder `auth/`, `data/`, versi Baileys.
- Mengubah `apiConversations()`, `ConversationModel`, `InboxGatewayApi.php`, atau area daftar/pencarian Inbox (milik M3 Fase 1e).
- Menambah dependensi npm/composer, mengubah kontrak `POST /api/inbox/gateway/messages`, atau mengubah arti kolom lama (`send_status`, `wa_message_id`).
- Menjalankan `php spark migrate` pada database kerja `aulia_inboxdb` tanpa persetujuan eksplisit pemilik (CON-014).
- Menambah task/requirement/AC baru di luar plan + spec v1.1, dan menaikkan `status: 'Planned'` di front-matter plan (itu TASK-024).

## 4. Fakta kode AuliaPos yang sudah terverifikasi (jangan diasumsikan)

Diambil langsung dari `C:\xampp\htdocs\aulia` @ `4fba319`:

| Aspek | Fakta terukur |
| --- | --- |
| Titik masuk kirim | `Inbox::kirimKeConversation()` — `app/Controllers/Inbox.php:1893` (satu-satunya tempat baris `messages` hasil kirim ditulis) |
| Pemanggil Gateway (teks) | `Inbox::callGatewaySend()` — `:2029`; `CURLOPT_TIMEOUT => 10` di `:2047` |
| Pemanggil Gateway (media) | `Inbox::callGatewaySendMedia()` — `:2085`; `CURLOPT_TIMEOUT => 30` di `:2112` |
| Wilayah M3 (JANGAN disentuh) | `Inbox::apiConversations()` — `:89`; `Inbox.php:433` memuat timeout lain milik jalur M3 |
| Migration contoh (TASK-016) | `app/Database/Migrations/2026-09-22-000001_AddIsInternalToMessages.php` — `protected $DBGroup = 'inbox';`, `$this->forge->addColumn('messages', [...])`, `down()` memakai `dropColumn` |
| Isolasi DB uji | `app/Config/Database.php:297-310`, `tests/_support/bootstrap.php`, `docs/ARCHITECTURE.md:298-308` — `aulia_inboxdb_test` adalah **salinan skema**, bukan hasil migrasi (dasar F-02) |

Pola migration yang harus ditiru (gaya berkas yang ada, jangan mengarang gaya baru):

```php
<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddGatewayOperationIdToMessages extends Migration
{
    protected $DBGroup = 'inbox';

    public function up()
    {
        // ADDITIVE ONLY (CON-009): kolom nullable + UNIQUE index.
        // Banyak NULL tetap diterima (AC-041); kolom lain tidak diubah.
    }

    public function down()
    {
        // Rollback: hapus indeks UNIQUE dulu, lalu kolom (RISK-012).
    }
}
```

**Batas yang harus dipegang TASK-016..TASK-019 (dari spec v1.1):**

- `operation_id` = 1–64 karakter, pola `^[A-Za-z0-9._:-]+$` (REQ-020) → kolom AuliaPos `VARCHAR(64)` agar pemotongan senyap mustahil (R-3).
- `operation_id` dimiliki **frontend**; Gateway maupun `Inbox.php` MUST NOT membuat kunci di server (REQ-039/A-4).
- Permintaan berulang yang `replayed:true` MUST NOT menulis baris `messages` kedua (AC-041); arti `send_status`/`wa_message_id` tidak berubah (CON-009).
- Bila `operation_id` hilang (mis. halaman dimuat ulang), kasir kembali ke perilaku lama — batas yang disadari (ASSUMPTION-010/RISK-007), bukan bug.
- Payload `POST /api/inbox/gateway/messages` (Gateway → AuliaPos) MUST tetap identik (CON-005/AC-040).

## 5. Temuan yang WAJIB diselesaikan lebih dulu (keputusan pemilik dibutuhkan)

Laporan klarifikasi plan (`ca98eff`) menghasilkan **readiness 82/100 + PROCEED**, tetapi mencatat bahwa item berikut **masih terutang** dan harus dibereskan **sebelum TASK-016**. Plan v1.0 (24 task) belum memuat perbaikannya, jadi ini titik pertama sesi Fase 4.

### 5.1 F-01 — tidak ada task yang menerapkan migration ke database kerja `aulia_inboxdb`

- **Masalah:** `TASK-017`/`TASK-018` menulis dan membaca `messages.gateway_operation_id` pada setiap kirim sukses, tetapi hanya TASK-016 (DB uji) yang ada. Tanpa kolom itu di `aulia_inboxdb`, setiap kirim kasir gagal `Unknown column 'gateway_operation_id'`.
- **Rekomendasi analis (opsi a):** tambahkan **satu task DEPLOY `[AP]`** — minta persetujuan eksplisit pemilik, jalankan migrasi ke `aulia_inboxdb`, buktikan dengan `SHOW COLUMNS`, catat titik rollback (`down()` menghapus indeks lalu kolom), dijalankan **sekali** setelah TASK-020 lulus dan tidak dicampur migrasi biasa.
- **Opsi lain:** (b) gabungkan sebagai dua langkah di TASK-016 (melanggar "satu task = satu commit kecil"); (c) serahkan sebagai langkah manual owner di luar plan (dengan konsekuensi tidak ada bukti terverifikasi di dalam plan).
- **Dampak:** tidak ada REQ/AC yang berubah pada ketiga opsi.

### 5.2 F-02 — `php spark migrate` di TASK-016/CON-014 tidak dapat dijalankan apa adanya

- **Masalah:** `php spark migrate` pada `development` memakai grup `default` (`aulia_kasirdb`) sebagai riwayat, sementara migrasi `$DBGroup='inbox'` diterapkan ke **`aulia_inboxdb` (database nyata)**; `aulia_inboxdb_test` justru **salinan skema** (`mysqldump --no-data`) yang akan drift dan gagal `Unknown column/table`.
- **Rekomendasi analis (opsi a):** perintah eksplisit untuk DB uji saja — `CI_ENVIRONMENT=testing` + `php spark migrate --dbgroup inbox`, lalu verifikasi `SHOW COLUMNS FROM messages` pada `aulia_inboxdb_test`; migrasi DB kerja tetap langkah terpisah (F-01).
- **Opsi lain:** (b) `ALTER TABLE`/`CREATE UNIQUE INDEX` manual di DB uji (skema uji tidak lagi berasal dari artefak yang sama); (c) sinkronkan DB uji via `mysqldump --no-data --routines --triggers aulia_inboxdb | mysql aulia_inboxdb_test` setelah migrasi DB kerja (menjadikan migrasi DB kerja sebagai prasyarat).

### 5.3 K-08 / K-09 / K-10 / K-13 (disiplin pelaporan, bukan perubahan kode)

| Kode | Ringkas | Perlakuan di Fase 4 |
| --- | --- | --- |
| K-08 | CON-014 tetap sah; belum menunjuk eksekutor migrasi DB kerja | Agent = DB uji; DB kerja = task DEPLOY + approval owner |
| K-09 | Angka gate TASK-020 yang beredar basi (298/948 vs 324/1097 vs 328/1102) | Gate = **≥ hasil terukur tepat sebelum perubahan** + seluruh uji baru; catat angkanya di decision log |
| K-10 | AC-026(b) `stub-only` dan cabang `422` `[Assumed / Out of Scope]` | Larangan klaim; label tertulis wajib di output uji/decision log |
| K-13 | Urutan bukti AC-027: percobaan pertama berakhir **502** (timeout cURL 10 s), keadaan "hasil belum pasti" baru muncul pada kirim ulang di dalam lease (`409 SEND_IN_PROGRESS`) | Menyangkut TASK-023 (Fase 5), **bukan** Fase 4 — tetapi jangan lupakan saat menulis skenario pengukuran |

> [!IMPORTANT]
> **Keputusan yang diminta pemilik sebelum sesi Fase 4 menulis kode:** (1) F-01 → opsi (a), (b), atau (c)? (2) F-02 → opsi (a), (b), atau (c)? (3) Bila memilih (a) di keduanya: apakah sesi `/sdlc-write-code` berwenang mengesahkan penambahan satu task DEPLOY + penulisan ulang TASK-016 (deviasi resmi yang dicatat di decision log), atau Anda ingin memperbarui plan lebih dulu lewat `/sdlc-plan-tasks`?

### 5.4 Status prasyarat Fase 5 (hasil verifikasi 2026-09-24, apa adanya)

| Prasyarat Fase 5 | Status | Bukti |
| --- | --- | --- |
| Fase 3 selesai + TASK-014 disetujui | ✅ | `docs/decisions/2026-09-24-m1-wave2-phase3-incoming-dead-letter.md` §7 |
| **Fase 4 selesai + TASK-021 disetujui** | ❌ | Branch/migration/kode/uji/log Fase 4 tidak ada (§2); baris TASK-015..TASK-021 di plan kolom `Completed` kosong |
| Tidak ada perubahan belum di-commit | ✅ | `git status --short` kosong di kedua repo |
| Regresi Fase 1–4 lulus | ❌ tidak dapat dinyatakan | Fase 4 belum punya kode/uji; regresi Gateway Fase 1–3 sendiri lulus 23/23 |

Konsekuensinya TASK-022 **dibatalkan** pada sesi itu: plan §2 menetapkan `Dep` TASK-022 = TASK-013 + **TASK-021**, dan grafik dependensi berbunyi "Fase 5 baru boleh dimulai setelah Fase 3 dan Fase 4 selesai". TASK-023 juga mustahil diukur tanpa Fase 4 (UI pengirim `operation_id` + keadaan "hasil belum pasti" + dedupe baris `messages`). **GW-09 tetap belum tertutup.**

## 6. Sumber referensi

- `plan/plan-process-m1-wave2-outgoing-idempotency-v1.0.md` — TASK-015..TASK-021 (§2 Phase 4), CON-012/CON-014, RISK-002, RISK-007, RISK-008, RISK-012, TEST-008, TEST-010, §9 Rollback Fase 4.
- `spec/spec-process-m1-wave2-outgoing-idempotency.md` v1.1 — REQ-039..REQ-041, AC-041/AC-044/AC-045/AC-046, D-12, ASSUMPTION-002/005/010, §4.7 (langkah 3–6).
- `docs/audit/clarification-report-m1-wave2-outgoing-idempotency-plan-2026-09-24.md` — F-01/F-02 (blocker), K-08/K-09/K-10/K-13, §4 Next Steps.
- `docs/decisions/2026-09-24-m1-wave2-phase3-incoming-dead-letter.md` — penutupan Fase 3, commit Gateway, batas bukti.
- `docs/decisions/2026-09-24-m1-wave2-eksekusi-fase1.md`, `...-fase2.md` — penyimpangan P-1..P-12 dan pola commit per task.
- `docs/handoff-m1-wave2-fase3-write-code-2026-09-24.md` — contoh bentuk handoff yang dokumen ini ikuti.
- `app/Controllers/Inbox.php`, `app/Views/inbox/index.php`, `app/Database/Migrations/2026-09-22-000001_AddIsInternalToMessages.php` — kode & pola nyata yang akan diubah.
- `docs/ARCHITECTURE.md` §11 — prosedur sinkronisasi skema DB uji.
