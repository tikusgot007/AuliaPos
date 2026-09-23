# Code Review Report — M3 Fase 2a (Handoff + Collision Detection)

**Date:** 2026-09-23
**Reviewer:** `/sdlc-code-review` (Expert Code Reviewer) — sesi terpisah, **nol perubahan source code**
**Branch / HEAD:** `feature/m3-operational-inbox-fase1a-task001` @ `3968aa0` (origin sinkron, working tree bersih)
**Reviewed range:** `600515c~1..HEAD` (seluruh Fase 2a) dan `b9f0e27..HEAD` (TB-02..TB-04)
**Normative upstream:** `plan/plan-feature-m3-operational-inbox-fase2a-v1.0.md` v1.0 (menang bila konflik, RISK-01),
`docs/audit/clarification-report-m3-fase2a-plan-2026-09-23.md` (Q1–Q9), `prd-20260922-0141-chat-whatsapp-inbox.md` v1.1
**Baseline dijalankan ulang:** `vendor/bin/phpunit --no-coverage` → **OK (283 tests, 867 assertions)**

## Executive Summary

- **Standards Axis (A):** sehat. Atomicity Handoff nyata (conditional write `<=>`, satu transaksi grup `inbox`,
  rollback terbukti lewat trigger MariaDB), identitas hanya dari session, injeksi tertutup (semua query
  parameter-bound), envelope/pesan Indonesia konsisten, dan `app/Controllers/Inbox.php` **312 insertions / 0 deletions**
  sehingga 7 method terlindungi byte-identical. Temuan terberat: validasi tipe payload (Major).
- **Spec Axis (B):** 22 dari 22 ID (REQ/CON) terpetakan ke kode; 20 punya test atau bukti statis setara.
  Dua celah: satu kontrak terkunci tanpa test (Q5) dan satu drift tafsir gerbang `belum_diambil` (Minor, butuh keputusan produk).
- **Verdict:** 0 Blocker, 0 Critical, 2 Major, 8 Minor. Aman untuk dilanjutkan; perbaikan berupa patch set kecil
  (lihat `plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md`).
- **Testing strategy assessment:** test inti menguji perilaku nyata, bukan mock — `C01`/`C02`/`E06` gagal bila conditional
  write diganti read-then-write, `C03` gagal bila `transRollback()` dihapus, dan suite regresi Fase 1 tetap hijau.
  `C03` memakai trigger MariaDB sungguhan dengan cleanup ganda (`finally` + `setUp`). Yang tumpul hanya mikro-kontrak
  (Minor-05) dan properti anti-leak body 409.

## 1. Verifikasi Ulang Batas (Independen)

| Klaim | Cara verifikasi | Hasil |
| --- | --- | --- |
| Baseline 283/867 | run ulang ke `build/review-baseline.txt` | ✅ `OK (283 tests, 867 assertions)` |
| 7 method lama tidak berubah | `git diff --numstat '600515c~1..HEAD'` | ✅ `Inbox.php` 312 insertions / **0 deletions** |
| Model/SLA/Gateway nol diff | daftar file `--numstat` | ✅ `ConversationModel`, `InboxSlaService`, `InboxGatewayApi` absen |
| Tidak ada ALTER tabel lama | baca migrasi `2026-09-23-000001` | ✅ hanya `createTable` + index + FK pada tabel baru |
| Tab/SLA/filter/thread UI tidak berubah | `git diff -- app/Views/inbox/index.php \| Select-String '^-'` | ✅ tepat 1 baris diganti (toolbar + `tombolHandoff`) |
| Identitas hanya dari session (K-06) | `Inbox.php:1007`, `:1169` | ✅ `initiated_by_user_id` tidak pernah dari body |
| Injeksi tertutup | `Inbox.php:1131-1136` | ✅ parameter-bound (`?` × 4), nol konkatenasi |
| Tanpa presence/unread/notifikasi | scan `app/**/*.php` | ✅ hanya komentar + heartbeat Gateway pre-existing |
| Test baru = 44 | hitung `public function test` | ✅ 7 + 7 + 4 + 26 (239 + 44 = 283) |

> **Catatan cakupan:** lampiran sesi menyebut `tests/database/ConversationHandoff{Cache}ModelTest.php`; file ber-"Cache"
> tidak ada di repo. Yang dinilai: `ConversationHandoffModelTest.php`, `ConversationHandoffsMigrationTest.php`,
> `UserModelDaftarKasirAktifTest.php`, `tests/session/InboxHandoffTest.php`.

## 2. Findings — Axis A: Standards (Kualitas & Keamanan)

### [MAJOR] [CR-01] Validasi payload tidak menolak nilai non-skalar (`summary`/`next_action`/`note`)

- **Description:** Field teks dikonversi paksa dengan `(string)`, sehingga sebuah array diterima sebagai string `"Array"`,
  lolos validasi "wajib non-kosong" dan tersimpan permanen ke jejak audit Handoff. Tidak ada dampak injeksi
  (query parameter-bound), tetapi ini pelanggaran REQ-H04 ("summary wajib non-kosong" = teks bermakna) dan mencemari riwayat.
  Bukti perilaku bahasa (dijalankan): `php -r "var_dump((string) array(1), trim((string) array(1)) === 'Array');"`
  → `Warning: Array to string conversion`, `string(5) "Array"`, `bool(true)`.
- **Category:** Clean Code (Input Validation) / Security (boundary hardening)
- **Location:** `app/Controllers/Inbox.php` (lines 1009-1014); bandingkan dua field yang sudah divalidasi tipe: lines 1036-1045, 1059-1076
- **Remedy:** Tolak tipe non-string dengan 400 sebelum `trim()`:

  ```php
  $summaryRaw    = $body['summary'] ?? null;
  $nextActionRaw = $body['next_action'] ?? null;
  $noteRaw       = $body['note'] ?? null;

  if (!is_string($summaryRaw) || !is_string($nextActionRaw)
      || ($noteRaw !== null && !is_string($noteRaw))) {
      return $this->response->setStatusCode(400)->setJSON([
          'status'  => 'error',
          'message' => 'Ringkasan, tindakan berikutnya, dan catatan harus berupa teks.',
      ]);
  }
  ```

  Tambah satu test: payload dengan `summary` berupa array → 400, ownership & riwayat utuh.

### [MINOR] [CR-05] Batas 4096 diukur byte (`strlen`), bukan karakter (`mb_strlen`)

- **Description:** `VARCHAR(4096)` dan `maxlength="4096"` menghitung karakter, sedangkan validasi server menghitung byte
  (`strlen('é') = 2`, `mb_strlen('é') = 1` — diverifikasi). Akibatnya teks multi-byte yang sah (aksen/emoji) bisa ditolak 400.
  Arah salahnya aman (terlalu ketat, bukan overflow).
- **Category:** Clean Code (Boundary Consistency)
- **Location:** `app/Controllers/Inbox.php` (lines 1028-1034); UI `app/Views/inbox/index.php` (lines 578, 583, 588)
- **Remedy:** Ganti tiga pemakaian `strlen` → `mb_strlen`; tambah 2 test (4096 karakter multi-byte → 200, 4097 → 400).

### [MINOR] [CR-06] Bentuk 409 tidak seragam: 409 `selesai` tanpa `current_owner_id`

- **Description:** Dua keluarga 409 pada endpoint yang sama memiliki bentuk body berbeda; klien generik harus bercabang.
  Bukan pelanggaran REQ-C02 (kewajiban `current_owner_id` hanya pada kekalahan conditional write), tetapi menambah permukaan kesalahan.
- **Category:** Clean Code (API Consistency)
- **Location:** `app/Controllers/Inbox.php` (lines 993-998 vs 1153-1159)
- **Remedy:** Tambahkan `'current_owner_id' => $assignedTo` pada 409 `selesai`, **atau** beri komentar eksplisit bahwa keluarga 409 ini berbeda + kunci bentuknya lewat test.

### [NIT] [CR-09] Format: docblock menempel pada `}` dan method 240 baris

- **Description:** `/**` pada line 1215 langsung mengikuti `}` line 1214 (konvensi file memisahkan dengan satu baris kosong),
  dan `handoffPercakapan()` mencakup 974-1214 (±240 baris) dengan 6 fase berurutan.
- **Category:** Clean Code (Readability / Functions)
- **Location:** `app/Controllers/Inbox.php` (lines 1214-1215, 974-1214)
- **Remedy:** Tambah satu baris kosong; opsional ekstraksi helper privat berperilaku identik
  (`validasiPayloadHandoff()`, `gerbangInisiatorHandoff()`, `balas409KepemilikanBasi()`) tanpa mengubah urutan gerbang Q3
  dan tanpa menyentuh 7 method terlindungi.

### [FYI] [CR-10] Index `to_user_id` dari K-05 tidak dibuat (sengaja)

- **Description:** K-05 menyebut index `(conversation_id, created_at)` + `to_user_id`; implementasi memakai
  `(conversation_id, id DESC)` sesuai Spec §4.1. Tidak ada query Fase 2a yang memfilter `to_user_id`, jadi ini YAGNI (benar).
- **Category:** Performance (informational)
- **Location:** `app/Database/Migrations/2026-09-23-000001_CreateConversationHandoffs.php` (lines 103-120)
- **Remedy:** Tidak ada aksi. Catat agar tidak dianggap kelalaian saat audit berikutnya.

## 3. Findings — Axis B: Spec (Kepatuhan Fungsional)

### [MAJOR] [CR-02] Kontrak terkunci Q5 ("`expected_owner` absen = 400") tidak dikunci satu test pun

- **Description:** Implementasi benar (`array_key_exists` → 400), tetapi **tidak ada test** yang mengirim payload tanpa
  field `expected_owner` (14 kemunculan `expected_owner` di test semuanya berupa komentar, definisi `validPayload()` yang
  selalu mengirim field, atau nilai kosong yang sengaja dikirim). Bila blok itu disederhanakan menjadi `?? null`, perilaku
  berubah menjadi 409 (conversation ber-owner) atau 200 (unassigned) dan **seluruh suite tetap hijau** — regresi "hijau palsu".
- **Spec Reference:** Plan REQ-H04 + Q5 lock; `docs/audit/clarification-report-m3-fase2a-plan-2026-09-23.md` §2 (Q5/Option A)
- **Location:** `app/Controllers/Inbox.php` (lines 1059-1064) vs `tests/session/InboxHandoffTest.php`
- **Remedy:** Tambah `testE09ExpectedOwnerAbsenDitolak400()`: `unset($payload['expected_owner'])` → 400 + pesan
  "Field expected_owner wajib dikirim." + ownership dan riwayat utuh, plus kontrol negatif payload lengkap → 200.

### [MINOR] [CR-03] Gerbang inisiator `belum_diambil` diimplementasikan sebagai `assigned_to IS NULL` (superset requirement)

- **Description:** Plan REQ-H01/P-05 dan PRD GH-006 AC-3 membatasi pengecualian pada **tab `Belum Diambil`**
  (`belum_diambil` mensyaratkan `response_state = perlu_dibalas`; lihat `ConversationModel::withComputedStatus()` lines 155-158),
  sedangkan kode memakai `$assignedTo === null`. State `assigned_to NULL` + tab lain **reachable** (mis. snooze lalu
  `lepasPercakapan()` pada line 1449), sehingga kasir mana pun bisa menyerahkan percakapan yang bukan miliknya di tab
  Menunggu/Ditunda. Dampak nyata kecil: setara dengan `ambilPercakapan()` (non-admin hanya menang bila `assigned_to IS NULL`,
  lines 1387-1389) lalu Handoff, dan tidak ada ownership yang bisa ditimpa — yang tersisa adalah drift makna requirement.
- **Spec Reference:** Plan REQ-H01/P-05 (ketat), Q1/Q3 lock; PRD GH-006 AC-3
- **Location:** `app/Controllers/Inbox.php` (lines 1092-1094); `app/Views/inbox/index.php` (lines 859-862)
- **Remedy:** Pilih setelah `/sdlc-clarify-reqs`: (1) selaraskan gate ke `queue_status === 'belum_diambil'`
  (+1 test: unassigned + snooze aktif + non-assignee → 403), atau (2) catat widening ini sebagai tafsir resmi.

### [MINOR] [CR-04] `expected_owner` (klien) sebagai syarat write vs `from_user_id` dari read pra-transaksi

- **Description:** Write memakai nilai klien (`assigned_to <=> :expected`), sedangkan `from_user_id` diambil dari `find()`
  sebelum transaksi (lines 1078-1080 → 1167). Pada race sangat sempit (nilai DB bergerak ke nilai `expected_owner` yang basi
  di antara read dan write) write bisa menang oleh inisiator yang sudah bukan assignee dan `from_user_id` tercatat salah.
  Kepemilikan **tidak pernah tertimpa** dan jalur kalah tetap 409, jadi ini hardening audit-trail + hole authz teoretis.
- **Spec Reference:** Plan REQ-H01/P-05, REQ-H07/K-06, REQ-C01
- **Location:** `app/Controllers/Inbox.php` (lines 1078-1080, 1131-1136, 1162-1174)
- **Remedy:** Setelah klarifikasi: fail-fast 409 bila `$expectedOwner !== $assignedTo` (hasil normalisasi) sebelum transaksi,
  memakai helper `balas409KepemilikanBasi()` hasil ekstraksi blok lines 1144-1159 (test 409 yang ada tetap hijau).

### [MINOR] [CR-07] Celah test mikro-kontrak lain

- **Description:** Beberapa kontrak detail tidak terkunci test, sehingga mutasi pada blok tersebut tidak terdeteksi.
- **Spec Reference:** Plan REQ-H04/H05/C01 + Q3 (urutan normatif) + DEP-06 (dual-read) + CON-H06
- **Location:** lihat tabel
- **Remedy:** lihat tabel (semua berupa penambahan test, tanpa perubahan source)

| Celah | Bukti kode | Risiko bila dirusak |
| --- | --- | --- |
| `to_user_id` non-numerik / `0` / negatif → 400 | `Inbox.php` 1036-1045; H05 hanya menguji `9999` | klien cacat menerima 403, bukan 400 |
| `note` > 4096 → 400 | `Inbox.php` 1028-1034; hanya `summary` diuji over-limit (H03 255-259) | batas catatan tidak terkunci |
| Jalur dual-read JSON (DEP-06) | `Inbox.php` 1000-1005; semua test POST form | fallback `getJSON(true)` bisa rusak tanpa terdeteksi |
| Filter `auth` pada route POST | `Routes.php` 55; G04 hanya GET (620-631) | filter POST terhapus → suite tetap hijau |
| Urutan gerbang pada pelanggaran ganda | `Inbox.php` 976-1118; E05 payload valid, H05/H08 satu pelanggaran | urutan 404→400 dan 403-inisiator→403-target tidak terkunci |
| Body 409 "hanya 3 key" (properti anti-leak) | `Inbox.php` 1153-1159; C01 hanya memeriksa 2 key | penambahan payload bocor tidak tertangkap |
| `CON-H06` Asia/Jakarta | `Inbox.php` 1124; H01 hanya `assertNotEmpty` | dampak kecil (`Config\App::$appTimezone = 'Asia/Jakarta'`, line 151) |

### [MINOR] [CR-08] `docs/ARCHITECTURE.md` belum menyebut `UserModel::daftarKasirAktif()`

- **Description:** TASK-014 mewajibkan Living Map memuat tabel + model + `UserModel::daftarKasirAktif()` + 2 route baru;
  `grep 'daftarKasirAktif' docs/ARCHITECTURE.md` → nol hasil. Bagian lain akurat: `conversation_handoffs` (line 160),
  `ConversationHandoffModel` (lines 84, 310), dua route (lines 199-200), subsection Handoff (lines 234-241),
  §12 gate M2 sempit (line 295), dan angka test 283/867 (line ~§11) cocok dengan hasil run ulang.
- **Spec Reference:** Plan TASK-014 (Living Map Mandate)
- **Location:** `docs/ARCHITECTURE.md` (§4.2, §13)
- **Remedy:** Tambah satu baris pada tabel §4.2/§13 yang menamai `UserModel::daftarKasirAktif()` sebagai sumber daftar kasir aktif.

## 4. Matriks Traceability (REQ/CON → Kode → Test)

| ID | Kode (file:baris) | Test (file:baris) | Status |
| --- | --- | --- | --- |
| REQ-H01 / P-05 / AC-H08 | `Inbox.php` 1085-1102; UI `index.php` 855-866 | `H08` 355, `E04` 716, `C01b` 414 | ✅ (drift → CR-03) |
| REQ-H02 / P-01 / AC-H02 | `Inbox.php` 989-998 | `H02` 222 | ✅ |
| REQ-H03 / P-03 / AC-H05 | `Inbox.php` 1104-1118; `UserModel.php` 44-51 | `H05` 279; `UserModelDaftarKasirAktifTest` (4) | ✅ |
| REQ-H04 / P-02 / Q5 / AC-H03 | `Inbox.php` 1000-1076 | `H03` 238, `E01` 657, `E02` 680 | ⚠️ CR-01, CR-02, CR-05, CR-07 |
| REQ-H05 / Q8 / AC-H04 | `Inbox.php` 1049-1054 | `H04` 265, `E03` 699 | ✅ |
| REQ-H06 | `Inbox.php` 1131-1136 | `H07` 326, `E07` 812, `E08` 838 | ✅ |
| REQ-H07 / K-06 / AC-H01, AC-H06 | `Inbox.php` 1162-1184 | `H01` 191, `H06` 305, `E01` 657 | ✅ (CR-04 hardening) |
| REQ-H08 / P-04 / Q7 | `Inbox.php` 1228-1247; `Routes.php` 60 | `G01` 520, `G02` 578, `G03` 608, `G04` 620, `G05` 633 | ✅ |
| REQ-H09 / AC-C03 | `Inbox.php` 1120-1197 (guard `id<=0` 1182-1184) | `C03` 463, `E06` 780 | ✅ |
| REQ-H10 (7 method utuh) | `--numstat` 312/0; nol diff model/SLA/Gateway | `ConversationHandoffsMigrationTest::testExistingInboxTablesAreNotAltered` 231 + suite regresi | ✅ |
| REQ-C01 / AC-C01 | `Inbox.php` 1131-1136 (`<=>`) | `C01` 382, `C02` 433, `E06` 780, `H06` 305 (NULL↔NULL) | ✅ (CR-04) |
| REQ-C02 / AC-C01, AC-C02 | `Inbox.php` 1139-1160 | `C01` 399-403, `C02` 443-445, `C04` 491 | ✅ (CR-07 anti-leak) |
| REQ-C03 (no presence) | nol kode presence (scan `app/`) | bukti statis (boundary audit) | ✅ |
| REQ-C04 | tidak ada logika fairness | n/a (tidak ada perilaku) | ✅ |
| CON-H01 / AC-H07 | segmen 940-1250 nol `MessageModel`/Gateway | `H07` 341-349, `G01` 566-570 | ✅ |
| CON-H02 | `Inbox.php` 1123; `ConversationHandoffModel.php` 28 | bukti statis | ✅ |
| CON-H03 | scan `presence|heartbeat|unread|notifikasi` | bukti statis | ✅ |
| CON-H04 | blok validasi 1000-1118 | semua test session | ✅ |
| CON-H05 | migrasi 35-121 | `MigrationTest` 7 test (kolom/tipe/index/FK CASCADE/idempotent/tabel lama) | ✅ |
| CON-H06 | `Inbox.php` 1124; `App.php` 151 | — (hanya `assertNotEmpty`) | ⚠️ CR-07 |
| GUD-H01 | envelope 1207-1213 (superset `tutupPercakapan()` 1590-1593) | `H01` 198-200, `H02` 230, `C02` 442, `E03` 709, `E04` 749/761, `G03` 613 | ✅ (CR-06) |
| PRD GH-006 AC-8 | `Inbox.php` 1131-1136 + `withComputedStatus()` | `E08` 838, `E07` 812 | ✅ |
| PRD GH-007 AC-3 (dobel-klik) | UI guard `index.php` 1064; server conditional write | server `C01` 382; UI demo manual (repo tanpa test harness JS) | ✅ server / ⚠️ UI struktural |
| TASK-014 Living Map | `docs/ARCHITECTURE.md` §6/§7/§8/§11/§12/§13 | — | ⚠️ CR-08 |

**Perilaku yatim:** tidak ada. Satu-satunya tambahan di luar daftar method adalah key `daftarKasir` pada `index()`
(lines 49-52) — sudah diputuskan dan diterima. Tidak ada route/field/enum baru yang tidak diminta (ALT-03 tidak diselundupkan).
**Requirement tanpa kode:** tidak ada. **Requirement tanpa test:** `CON-H06` dan properti anti-leak body 409 (CR-07).

## 5. Kualitas Test (Anti-Cheat & Mutation Sensitivity)

Mutasi berikut **pasti** menggagalkan test yang disebut — jadi klaim inti tidak hijau palsu:

| Skenario rusak | Test yang gagal | Alasan |
| --- | --- | --- |
| Conditional write → blind UPDATE (read-then-write) | `C01`, `C02`, `E06` | permintaan kedua jadi 200, bukan 409 |
| `transRollback()` dihapus saat insert gagal | `C03` | `assigned_to` berubah dari 7 |
| Guard `id <= 0` dihapus | `C03` | 200 tanpa baris riwayat + pesan 500 hilang |
| Handoff menyentuh `messages`/`last_message_*` | `H07`, `G01` | hitungan baris/last_message berubah |
| Cap 50 / newest-first dibalik | `G02`, `ConversationHandoffModelTest` 128-151 | isi/urutan array berubah |
| Snooze direset saat Handoff | `E07` | `snoozed_until` berubah |
| Gerbang inisiator/target dilonggarkan | `H08`, `C01b`, `E04`, `H05` | status berubah dari 403 |
| Nama pemenang 409 dihapus | `C01`, `C02`, `C04` | `assertStringContainsString` gagal |

Hygiene: nol `markTestSkipped`/`markTestIncomplete`/`@group`, nol assertion dummy, nol suppression pada file baru.
`C03` memakai trigger MariaDB sungguhan (`BEFORE INSERT ... SIGNAL SQLSTATE '45000'`) dengan cleanup di `finally` **dan** `setUp`.
Pre-existing di luar lingkup: `phpunit.dist.xml` men-exclude 4 file `tests/unit/Inbox*Test.php`; `composer test` selalu exit 1
karena warning coverage tanpa driver (`vendor/bin/phpunit --no-coverage` = sinyal yang benar).

## 6. Review Frontend (Ringkas)

| Item | Hasil | Bukti (`app/Views/inbox/index.php`) |
| --- | --- | --- |
| `expected_owner` dibaca saat dialog dibuka | ✅ (unassigned → `''`, bukan string `"null"`) | 1004, 1023-1026 |
| Dobel-submit guard | ✅ flag + disable tombol + reset saat dialog dibuka | 1059-1064, 1077-1079, 1031-1032 |
| Loser UX (409) | ✅ pesan server apa adanya + tombol "Muat ulang" (refresh Queue + header, tutup dialog) | 547-559, 1040-1053, 1116-1118 |
| XSS | ✅ semua string server di panel di-`escapeHtmlInbox()`; `json_encode` tetap meng-escape `/`; nama dropdown di-`esc()` | 1161-1173, 1141, 567 |
| Tanpa presence/unread/notifikasi | ✅ tanpa polling riwayat; reload hanya saat pindah/sukses/409 | 1182-1199, 1216 |
| Tab/SLA/filter/thread tidak berubah | ✅ hanya 1 baris toolbar di-replace | diff `index.php` |
| Tombol Handoff mencerminkan gerbang server | ✅ assignee atau (unassigned + role kasir), bukan `selesai` | 859-866 |

Demo manual yang direkomendasikan (butuh browser, belum dijalankan di sesi ini): dua tab bentrok → satu 200 + satu 409 bernama;
klik dobel cepat → satu request; kasir dinonaktifkan setelah dialog dibuka → 403 lalu ganti target harus tetap bisa sukses.

## 7. Keputusan yang Dinilai dan Diterima (Bukan Temuan)

1. Teks Spec v1.0 basi (500/403/admin/§12 staff-9) — Plan menang (RISK-01); tercermin di komentar `Inbox.php` 955-969 dan test `C01b`.
2. D-01 (contoh §12 tidak diimplementasikan) — konsisten dengan P-05, terkunci test.
3. Plan-vs-Q2 (admin non-assignee di `belum_diambil` = 403) — tafsir Plan tepat untuk Fase 2a ("tanpa jalur paksa admin"),
   tetapi tetap pertanyaan produk terbuka → `/sdlc-clarify-reqs`, bukan patch kode.
4. Resolve nama staff di klien dari `daftarKasir` + fallback `Kasir #id` — kontrak P-04 hanya membawa id; UI memakai sumber Q6 yang sama.
5. `conversation_id` BIGINT UNSIGNED — deviasi terdokumentasi (MariaDB errno 150), konsisten dengan preseden `messages`.
6. Key `daftarKasir` pada `index()` — `index()` bukan method terlindungi; alternatif (query di view) lebih buruk.
7. `emptyTable()` tabel `inbox` riil di `setUp` test — konvensi pre-existing (`InboxInternalNoteTest:31-34`).
8. Lint administratif pre-existing (MD013 repo-wide, MD012 di plan, kolom `Completed`/`Date` kosong).
9. Model/migrasi/`UserModel::daftarKasirAktif()` mengikuti konvensi modul Inbox (returnType array, `$useTimestamps=false`, `DBGroup='inbox'`).

## 8. Final Verdict

- **Total Findings:** 2 Major + 1 Minor "security/boundary/consistency" + 5 Minor spesifikasi/test + 1 NIT + 1 FYI
  (0 Blocker, 0 Critical). Dipadatkan menjadi **2 Major, 8 Minor**.
- **Worst Standards Issue:** CR-01 — validasi tipe payload (`summary`/`next_action`/`note` non-skalar lolos sebagai `"Array"`).
- **Worst Spec Issue:** CR-02 — kontrak terkunci Q5 (`expected_owner` absen = 400) tanpa test (mutation-insensitive).
- **Recommendation:** **Proceed to Refactoring Plan** — eksekusi `plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md`
  lewat `/sdlc-write-code` (Fase 1), sambil menjalankan `/sdlc-clarify-reqs` untuk item yang bergantung keputusan produk (Fase 2).
  Blocker/Critical nol, sehingga **tidak ada alasan menahan rilis Fase 2a**.

## 9. Next Steps

1. `/sdlc-write-code` (sesi baru) — eksekusi Fase 1 plan refactor: CR-01, CR-02, CR-05, CR-06, CR-07, CR-08, CR-09;
   verifikasi `vendor/bin/phpunit --no-coverage` tetap ≥ 283 test tanpa penurunan assertion.
2. `/sdlc-clarify-reqs` (sesi baru) — CR-03 dan CR-04 (plus pertanyaan produk Plan-vs-Q2); hasilnya menjadi Fase 2 plan refactor.
3. Setelah patch: finalisasi fisik `spec/spec-design-m3-operational-inbox-fase2a-handoff-collision.md`
   (P-01..P-06 + contoh §12 + batas 4096/409) supaya Spec tidak lagi basi, lalu `/sdlc-generate-docs`.

<!-- Reviewer scope: laporan + rencana refactoring saja. Implementasi wajib lewat /sdlc-write-code. -->
