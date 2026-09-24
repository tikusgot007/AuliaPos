# 🔍 Clarification Report [Review Iteration 1]

**Target Document:** `plan/plan-process-m1-wave2-outgoing-idempotency-v1.0.md` (v1.0, status `Planned`, commit `57de122`)

**Reference Documents:**

- `spec/spec-process-m1-wave2-outgoing-idempotency.md` v1.1 (commit `7897d38`) — sumber tunggal REQ-020..REQ-041, AC-019..AC-046, D-05..D-13, dan A-1..A-8.
- `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` v1.2 — pola VERIFY/APPROVAL/DEPLOY, CON-005, dan pola TASK-019 (DEPLOY) / TASK-017 (VERIFY nyata) yang dipakai ulang.
- `docs/audit/clarification-report-m1-wave2-outgoing-idempotency-2026-09-24.md` — Readiness 88/100 (PROCEED); keputusan R-1..R-3 dan A-1..A-8 yang sudah tertanam di spec v1.1.
- `docs/audit/clarification-report-m1-wave1-incoming-reliability-plan-2026-09-21.md` — pola bentuk laporan klarifikasi level plan (Readiness 92/100).
- `plan/plan-bugfix-inbox-test-db-isolation-v1.0.md` (**`Completed`**, 2026-09-24) dan `docs/ARCHITECTURE.md` §5/§11 — isolasi database uji AuliaPos; **bukti baru di luar plan M1 W2** yang mengubah cara migrasi TASK-016 harus dijalankan.
- `docs/GATEWAY-REQUIREMENTS.md`, `docs/TODO-CHAT.md` (Ticket 06–11), `docs/decisions/2026-09-21-m1-ticket01-baseline.md` (Baseline 3 dan 4).

**Batas sesi:** sesi ini **hanya audit dan pertanyaan**. Plan dan spec **tidak** ditulis ulang, tidak ada kode yang ditulis, dan tidak ada keputusan yang diterapkan ke dokumen mana pun.

---

## Readiness Score

**Readiness Score:** **82/100** — *Good Enough* (di atas ambang 80).

**Status:** layak dilanjutkan **dengan 2 perbaikan dokumen wajib** (F-01 dan F-02 di §1) dan **11 butir keputusan pemilik** (§2). Tidak ada item yang menahan seluruh rencana; tidak ada item terbuka yang memblokir `/sdlc-write-code` Fase 1 (TASK-001).

**Score Breakdown:**

| Kriteria | Skor | Alasan |
| --- | --- | --- |
| **Completeness (40)** | **32/40** | Semua 24 task punya `Ref ID`, `AC Ref`, dan `Dep`; kelima fase punya VERIFY + APPROVAL; batas jujur RISK-003 ditulis eksplisit. Dikurangi 8 poin karena tiga celah nyata: (a) **tidak ada task yang menerapkan migration AuliaPos ke database kerja `aulia_inboxdb`** (F-01) padahal kode TASK-017/018 langsung menulis/membaca kolom itu; (b) mekanika `php spark migrate` di TASK-016/CON-014 bertabrakan dengan isolasi DB uji yang baru di-`Completed` (F-02); (c) angka gate TASK-020 (324/1097) dan urutan bukti UI pada AC-027 belum presisi (K-13, K-14). |
| **Clarity (30)** | **25/30** | Bahasa terukur: nilai bawaan eksplisit (enam variabel env), kode HTTP per cabang, matriks state. Dikurangi 5 poin karena: kontrak browser↔controller untuk keadaan "hasil belum pasti" (status HTTP/JSON yang dikembalikan `kirimKeConversation()`) belum ditetapkan (K-15 (i)); perintah migrasi yang dapat dieksekusi belum ditulis apa adanya (F-02); pemilik "siapa yang mengeksekusi migrate" belum ditunjuk (K-08); kebijakan `gateway_operation_id = NULL` untuk permintaan tanpa `operation_id` tidak dinyatakan eksplisit (K-15). |
| **Alignment (30)** | **25/30** | Traceability penuh ke spec v1.1 (tidak ada task yatim, tidak ada requirement baru, RISK-005 diselesaikan ke arah requirement). Dikurangi 5 poin karena plan bertabrakan dengan **dokumen `Completed` + `docs/ARCHITECTURE.md` §11** soal cara menyinkronkan skema DB uji setelah migrasi baru (F-02), dan karena spec §13 butir 4 sendiri masih memuat baseline uji yang basi (K-14) tanpa tugas koreksi dokumen. |

**Critical Flaw Veto:** **Tidak aktif.** Tidak ada kontradiksi fundamental yang mematikan seluruh rencana. F-01 (migrasi DB kerja tidak tercakup) dan F-02 (mekanika migrasi vs DB uji) adalah **celah cakupan/prosedur** dengan opsi perbaikan yang jelas dan berbiaya kecil, bukan pertentangan antar-dokumen yang membatalkan desain. Karena itu skor tidak dikunci di 79.

---

## Bukti Verifikasi Runtime (2026-09-24)

Dibaca langsung dari lingkungan kerja pada sesi ini (read-only, tidak ada yang ditulis):

| Butir | Hasil pengukuran | Dampak ke plan |
| --- | --- | --- |
| WA-Gateway `C:\projects\WA-Gateway` | `HEAD` = `21a4cb6`; `git worktree list` hanya folder live; `git branch -a` hanya `master` + `origin/master` | **RISK-001 terkonfirmasi**; TASK-001 (worktree + branch) memang task pertama dan prasyarat semua |
| Toolchain Gateway | Node `v20.20.2`; `package.json`: `baileys 6.7.24`, `better-sqlite3 ^11.3.0`, `engines.node >= 20` | Kontingensi 1 (gagal `npm ci`/build) berisiko rendah saat ini |
| Proses live | `pm2` 7.0.4, `wa-gateway` id 0, **online**, uptime 21 jam, user `AAN` | Gateway aktif di mesin yang sama → TASK-022/023 dapat dijalankan lokal; jeda/stop berdampak langsung ke lalu lintas nyata |
| AuliaPos `C:\xampp\htdocs\aulia` | branch `v2.3`, working copy bersih, HEAD `51a2798` | Basis TASK-015 (`v2.3`) tersedia; tidak ada pekerjaan M3 yang sedang berjalan (sesuai RISK-002) |
| Suite AuliaPos (terukur hari ini) | `vendor/bin/phpunit --no-coverage` → **`OK (328 tests, 1102 assertions)`** | Angka gate plan (324/1097) dan spec §13 butir 4 (298/948) **keduanya basi** → K-14 |
| Data nyata `aulia_inboxdb` | `messages` = 87 (semuanya `incoming`), `conversations` = 2, `message_timestamp` terakhir `2026-09-24 14:34:11`; `gateway_status.status = connected` (updated `15:53:48`) | **Ada lalu lintas WhatsApp nyata yang mengalir sekarang** → premis "lingkungan bukan produksi" dari gelombang 1 wajib dikonfirmasi ulang untuk TASK-023 (K-06) |
| Isolasi DB uji | `app/Config/Database.php:297-310` memaksa grup `inbox` → `aulia_inboxdb_test` saat `ENVIRONMENT === 'testing'`; `tests/_support/bootstrap.php` menolak menjalankan PHPUnit bila bukan DB itu; `docs/ARCHITECTURE.md:298-308` menetapkan sinkronisasi skema lewat `mysqldump --no-data` | **F-02**: TASK-016/CON-014 harus mengikuti mekanika ini, bukan `php spark migrate` apa adanya |
| Kontrak AuliaPos (`kirimKeConversation()`, `callGatewaySend*`) | `Inbox.php:2047` timeout 10 s, `:2112` timeout 30 s, `:2062-2074` hanya membaca `success`/`wa_message_id`/`timestamp` | R-1 (lease 35 000) dan REQ-040 terverifikasi benar |
| Gateway → AuliaPos (`InboxGatewayApi`) | Hanya `setStatusCode(400)`, `(200)`, `(500)` di seluruh berkas (tidak ada `422`) | A-8b/REQ-036 terverifikasi benar → K-10 |
| Form balas UI | `index.php:408` `#formBalas onsubmit="return kirimBalasan(event)"`; `:2164` `fetch('/inbox/kirim')` urlencoded (`conversation_id` + `text`); **tidak ada** `sessionStorage`/`localStorage` | Jalur AJAX → `operation_id` mudah ditambahkan; RISK-007 (muat ulang halaman) tetap berlaku apa adanya |

---

## 1. 🚨 Critical Findings (Blocker Dokumen)

Dua temuan berikut **tidak** membatalkan rencana, tetapi harus diperbaiki di dokumen sebelum TASK-016 dijalankan. Keduanya berasal dari bukti yang **belum ada** saat plan ditulis (plan dibuat sebelum `plan-bugfix-inbox-test-db-isolation-v1.0.md` dinyatakan `Completed` pada hari yang sama).

### F-01 — Tidak ada task yang menerapkan migration ke database kerja `aulia_inboxdb`

- **Rujukan:** TASK-016, TASK-020, TASK-021, CON-009, CON-014, spec §9 ("Ask first").
- **Temuan:** seluruh rencana hanya menyebut `php spark migrate` **pada database uji**. Tidak ada satu pun task yang menambahkan kolom `messages.gateway_operation_id` ke `aulia_inboxdb` — database yang dipakai aplikasi yang sedang berjalan. Padahal TASK-017 dan TASK-018 mengubah `Inbox::kirimKeConversation()` agar **menulis dan membaca** kolom itu pada setiap kirim sukses.
- **Akibat bila dibiarkan:** begitu kode Fase 4 berjalan di working copy bersama (branch `v2.3`), setiap kirim pesan kasir gagal dengan `Unknown column 'gateway_operation_id'`. Gejalanya muncul pertama kali di TASK-020/TASK-023, tetapi akar masalahnya adalah task yang hilang — bukan bug kode.
- **Opsi jawaban:**
  - **(a) Tambah satu task DEPLOY migrasi DB kerja (rekomendasi analis).** Task baru `[AP]` (mis. `TASK-016b` atau `TASK-021a`): minta persetujuan eksplisit pemilik, jalankan migrasi ke `aulia_inboxdb`, buktikan kolom + indeks ada, catat titik rollback (`down()` menghapus indeks lalu kolom). Dijalankan **sekali**, tepat sebelum kode Fase 4 dipakai kasir (setelah TASK-020 lulus), dan **tidak** dicampur dengan `php spark migrate` biasa.
  - **(b) Gabungkan ke TASK-016** dengan dua langkah di dalamnya (DB uji dulu, DB kerja belakangan dengan persetujuan). Lebih sedikit task, tetapi melanggar "satu task = satu commit kecil" dan mencampur aksi berisiko ke dalam task yang seharusnya murni skema.
  - **(c) Serahkan ke owner sebagai langkah manual di luar plan** (dicatat di decision log). Plan tetap 24 task, tetapi pernyataan Introduction "satu-satunya DEPLOY adalah TASK-022" menjadi tidak benar dan tidak ada bukti terverifikasi di dalam plan.
- **Dampak pemilihan:** (a) dan (b) menambah 1 task (atau 1 sub-langkah + 1 approval); (c) tidak menambah task tetapi menambah ketergantungan pada disiplin manual. Ketiganya **tidak** mengubah REQ/AC mana pun.

### F-02 — `php spark migrate` (TASK-016/CON-014) bertabrakan dengan isolasi DB uji yang sudah `Completed`

- **Rujukan:** TASK-016 ("Jalankan `php spark migrate` hanya pada database uji"), CON-014, `plan/plan-bugfix-inbox-test-db-isolation-v1.0.md`, `docs/ARCHITECTURE.md:298-308`.
- **Temuan:** `php spark migrate` pada environment biasa (`development`) memakai grup `default` (`aulia_kasirdb`) sebagai tempat riwayat migrasi, sementara migrasi bergrup `inbox` diterapkan ke **`aulia_inboxdb` (database nyata)** — bukan ke `aulia_inboxdb_test`. Sebaliknya, `aulia_inboxdb_test` adalah **salinan skema** (`mysqldump --no-data`), bukan hasil migrasi; plan bugfix itu sendiri mencatat bahwa setelah migrasi `inbox` baru, skema DB uji akan **drift** dan uji gagal dengan `Unknown column/table` sampai langkah sinkronisasi di `docs/ARCHITECTURE.md` §11 dijalankan ulang. Jadi kalimat TASK-016 saat ini **tidak dapat dieksekusi apa adanya**: satu tafsir melanggar CON-014 (menyentuh DB kerja), tafsir lain membuat uji Fase 4 gagal karena skema DB uji tidak ikut berubah.
- **Opsi jawaban:**
  - **(a) Tulis perintah eksplisit untuk DB uji saja (rekomendasi analis):** `set CI_ENVIRONMENT=testing` + `php spark migrate --dbgroup inbox`, lalu verifikasi `SHOW COLUMNS FROM messages` pada `aulia_inboxdb_test`; catat bahwa DB kerja masih butuh langkah terpisah (F-01).
  - **(b) Lewati `spark` untuk DB uji:** terapkan `ALTER TABLE` + `CREATE UNIQUE INDEX` langsung ke `aulia_inboxdb_test`, dan simpan berkas migrasi hanya untuk jejak riwayat. Cepat, tetapi skema DB uji tidak lagi berasal dari artefak yang sama dengan DB kerja.
  - **(c) Sinkronkan DB uji dengan prosedur resmi:** jalankan `mysqldump --no-data --routines --triggers aulia_inboxdb | mysql aulia_inboxdb_test` **setelah** migrasi diterapkan ke DB kerja (menggabungkan F-01 dan F-02 menjadi satu urutan). Memakai prosedur yang sudah tertulis di `docs/ARCHITECTURE.md` §11, tetapi menjadikan migrasi DB kerja sebagai prasyarat DB uji.
- **Dampak pemilihan:** (a) menjaga CON-014 dan membuat uji Fase 4 berjalan tanpa menyentuh data nyata, dengan biaya satu langkah sinkronisasi DB kerja di kemudian hari; (c) paling sedikit langkah manual tetapi mengubah urutan (migrasi DB kerja lebih dulu, dengan persetujuan pemilik). Ketiganya **tidak** mengubah REQ/AC.

---

## 2. 🧩 Temuan Interogasi & Opsi Jawaban

Setiap butir di bawah mengikuti urutan prioritas yang diminta pemilik. Format: rujukan → temuan → opsi jawaban konkret → dampak → rekomendasi analis.

> [!NOTE] Penomoran. **K-01..K-11** memetakan langsung pertanyaan 1..11 pemilik. **K-12..K-15** adalah temuan tambahan hasil verifikasi runtime pada sesi ini (mekanika migrasi, urutan bukti AC-027, angka baseline, dan detail kontrak kecil); ringkasannya ada di §3.

### K-01 — ASSUMPTION-001: cakupan ganda dead-letter (`incoming_queue` **dan** `outgoing_operations`)

- **Rujukan:** ASSUMPTION-001 (spec §1.2), REQ-029, AC-029, TASK-007, TASK-011, D-05.
- **Temuan:** plan sudah mengekstrak asumsi ini dengan mitigasi "bila ditolak, REQ-029/AC-029/TASK-007 dibuang sebagai satu unit". Secara struktural pembuangan itu aman: TASK-007 menangani lease (REQ-028) **dan** cap (REQ-029) sekaligus, jadi pembuangan cap menyisakan TASK-007 versi lebih kecil, bukan task mati.
- **Opsi jawaban:**
  - **(a) Terima apa adanya (rekomendasi analis).** `abandoned` adalah satu-satunya terminal state kirim keluar; tanpa cap, operasi yang selalu ambigu akan dicoba ulang **selamanya** setiap kali lease lewat, dan GW-19/GW-09 hanya tertutup setengah. Biaya implementasinya kecil (satu nilai state + satu kolom `dead_lettered_at` + satu cabang `502`).
  - **(b) Batasi ke antrean masuk saja.** Buang REQ-029/AC-029 dan bagian cap pada TASK-007 (sisakan lease + matriks respons + pemulihan); Fase 3 tetap utuh. Konsekuensinya harus ditulis jujur di plan: operasi keluar **tidak punya terminal state**, dan `[CRITICAL]` dead-letter kirim keluar tidak pernah ada.
  - **(c) Terima tetapi jadwalkan ke gelombang berikutnya.** Fase 2 hanya lease + pemulihan (REQ-027/028/030/031/032); cap/`abandoned`/`502`/AC-029/AC-042 dipindah ke Wave 3. Sama seperti (b) dari sisi cakupan, beda pada penjadwalan (tidak ada task yang dibuang dari dokumen, hanya ditunda).
- **Dampak:** (a) = 24 task apa adanya; (b) = 24 task dengan TASK-007 dan daftar AC diperkecil; (c) = 24 task dengan Fase 2 dipersempit + gelombang baru.
- **Catatan:** keputusan ini sudah pernah dinyatakan diterima pada klarifikasi spec (88/100, tanpa item terbuka). Mengubahnya sekarang berarti mengubah spec v1.1 lebih dulu (bukan wewenang sesi ini).

### K-02 — ASSUMPTION-002: perubahan AuliaPos in-scope

- **Rujukan:** ASSUMPTION-002, REQ-039..REQ-041, AC-041, AC-044..AC-046, TASK-015..TASK-021, CON-009, CON-012.
- **Temuan:** plan sudah menandai ini High Risk dan mensyaratkan konfirmasi sebelum Fase 4. Yang perlu ditegaskan pemilik bukan hanya "in-scope atau tidak", tetapi **konsekuensi bila dipotong**: `operation_id` bersifat opsional (D-09/REQ-026), jadi bila Fase 4 dibuang, **tidak ada klien mana pun yang mengirim `operation_id`** dan GW-09 **tidak tertutup di praktik** — Gateway hanya menyediakan infrastruktur yang tidak dipakai. Nilai bisnis TASK-001..TASK-014 lalu terbatas pada GW-19 (dead-letter antrean masuk) dan fondasi untuk M2.
- **Opsi jawaban:**
  - **(a) Terima penuh (rekomendasi analis).** Fase 4 dieksekusi utuh (TASK-015..TASK-021). GW-09 benar-benar tertutup; biayanya 7 task kecil + risiko konflik working copy yang sudah dimitigasi RISK-002/CON-012.
  - **(b) Potong Fase 4.** Buang TASK-015..TASK-021 beserta AC-041 dan AC-044..AC-046 dan versi UI dari AC-027/AC-042. Plan menjadi 14 task (Gateway saja) + Fase 5 hanya TASK-022/024 (tanpa TASK-023, karena pengukuran UI tidak mungkin). **GW-09 MUST ditulis "tidak tertutup"** di ringkasan Wave 2.
  - **(c) Potong sebagian: buang hanya UI (TASK-019) dan pertahankan controller (TASK-015..TASK-018).** Ditolak analis: tanpa UI, `operation_id` tidak pernah dibuat (REQ-039 melarang server membuat kunci), sehingga TASK-017/018 menjadi kode yang memproses field yang selalu kosong.
- **Dampak:** (a) = 24 task; (b) = 14–16 task dan GW-09 tetap terbuka; (c) = 22 task dengan hasil yang tidak dapat diuji.

### K-03 — RISK-002: urutan, branch, dan batas merge AuliaPos

- **Rujukan:** RISK-002, TASK-015, TASK-021, TASK-024, `spec/spec-design-m3-operational-inbox-fase1.md` rev 1.3 (Fase 1e).
- **Temuan:** usulan plan ("branch `feature/m1-wave2-outgoing-idempotency`, dikerjakan + di-merge sebelum kode M3 Fase 1e dimulai") konsisten dengan keadaan terukur hari ini: branch `v2.3` bersih, M3 Fase 1d sudah ter-merge (`db7f301`), dan Fase 1e masih Spec-only. Dua hal yang belum dinyatakan tegas: **target merge** (branch `v2.3`) dan **titik batas** kapan gate itu dianggap terpenuhi.
- **Opsi jawaban:**
  - **(a) Batas = TASK-021 (rekomendasi analis).** Merge ke `v2.3` setelah TASK-020 lulus dan TASK-021 disetujui; Fase 1e boleh mulai `plan`/`kode` **setelah** merge itu. Deploy Gateway (TASK-022) dan pengukuran (TASK-023) berjalan paralel tanpa menahan Fase 1e karena TASK-022 menyentuh repo lain.
  - **(b) Batas = TASK-024.** Fase 1e baru boleh mulai setelah seluruh Wave 2 (termasuk deploy + pengukuran + handoff) ditutup. Lebih aman, tetapi menahan Fase 1e ±1 sesi tambahan tanpa manfaat teknis.
  - **(c) Batas = TASK-019 selesai (UI selesai) tanpa menunggu VERIFY.** Merge lebih cepat, tetapi memindahkan risiko regresi suite ke Fase 1e.
- **Tambahan yang harus ditulis apa pun pilihannya:** target merge (`v2.3`), perintah `git merge --ff-only`/`--no-ff` yang dipakai, dan bukti `git status --short` kosong sebelum merge (pola CON-011).

### K-04 — Nilai batas (ASSUMPTION-003 + spec §4.6): mana yang tidak boleh dipakai apa adanya

- **Rujukan:** ASSUMPTION-003, spec §4.6, REQ-029, REQ-032, REQ-033, REQ-038, RISK-011, D-13.
- **Temuan:** enam nilai bawaan sudah terverifikasi **konsisten dengan kontrak kode** (lease 35 000 > timeout media 30 000 di `Inbox.php:2112`; cap 5 diperiksa sebelum kirim ulang; TTL 24 jam memangkas baris terminal saja). Yang belum layak disebut "nilai produksi" hanyalah yang **tidak punya dasar pengukuran** atau yang **berdampak pada pesan pelanggan saat gangguan panjang**:

| Variabel | Status | Catatan |
| --- | --- | --- |
| `OUTGOING_LEASE_MS=35000` | Aman, **tetapi terikat** | Harus tetap > timeout klien terpanjang AuliaPos. Bila timeout media dinaikkan >30 s, nilai ini MUST ikut naik; kalau tidak, kiriman kedua lolos (duplikat). |
| `OUTGOING_MAX_ATTEMPTS=5` | Aman | Risiko terbesar hanya "pesan berhenti dicoba setelah 5 kiriman", dan itu memang keputusan D-05. |
| `OUTGOING_OPERATION_TTL_MS=86400000` | Aman dengan batas jujur | Setelah 24 jam, `operation_id` lama = operasi baru (D-13/Kasus 10). Tidak boleh diklaim sebagai jaminan permanen. |
| `DELIVERY_MAX_ATTEMPTS=100` | **Perlu keputusan** | Setara ±3,4 jam dengan `maxDelayMs=120000`. Pemadaman AuliaPos lebih lama (mis. semalaman) memindahkan pesan pelanggan ke `dead`; karena `replayDeadLetter()` hanya memberi **tepat satu** siklus tambahan (REQ-037), operator harus me-replay berulang kali setelah pemulihan. |
| `DELIVERY_DEAD_AFTER_MS=86400000` | **Perlu keputusan** | Pasangan baris di atas: usia 24 jam memaksa `dead` walau `attempts` jauh di bawah cap. Nilai `0` (tanpa batas usia) MUST dipertimbangkan untuk operasi toko. |
| `DELIVERY_DEAD_BURST_THRESHOLD=10` | **Belum terkalibrasi** | Spec sendiri mencatat ini sebagai poin residual: ambang 10/siklus dipilih tanpa data produksi, padahal ia memicu `[CRITICAL]` + instruksi menghentikan replay otomatis (alarm palsu atau terlambat). |

- **Opsi jawaban:**
  - **(a) Terima semua nilai bawaan untuk M1 W2 + catat dua hal di decision log (rekomendasi analis):** (1) pasangan `DELIVERY_MAX_ATTEMPTS`/`DELIVERY_DEAD_AFTER_MS` belum final dan wajib ditinjau setelah pemadaman nyata; (2) `DELIVERY_DEAD_BURST_THRESHOLD` adalah ambang awal. Tambahkan satu baris runbook: **cara me-replay baris `dead` secara massal** (satu replay = satu siklus).
  - **(b) Ubah nilai sekarang lewat env tanpa mengubah kode:** mis. `DELIVERY_MAX_ATTEMPTS=500` (±17 jam) dan/atau `DELIVERY_DEAD_AFTER_MS=0` (tanpa batas usia) → pesan tidak mati saat pemadaman panjang, dengan konsekuensi antrean tumbuh lebih lama sebelum `dead`.
  - **(c) Perketat cap outbound** (mis. `OUTGOING_MAX_ATTEMPTS=3`) karena tiap percobaan berpotensi menduplikasi pesan ke pelanggan; trade-off-nya pesan kasir lebih cepat berhenti dicoba.
- **Dampak:** ketiga opsi hanya mengubah nilai env (GUD-003); tidak ada kode, REQ, atau AC yang berubah.

### K-05 — RISK-005: jumlah variabel env ("lima" di spec §7 vs "enam" di §4.6/GUD-003)

- **Rujukan:** RISK-005, spec §4.6, spec §7, GUD-003, REQ-038, AC-038, TASK-002.
- **Temuan:** benar — spec §7 masih menulis "lima variabel lingkungan baru", sedangkan §4.6 mencantumkan enam (`DELIVERY_DEAD_BURST_THRESHOLD` tidak terhitung). Plan sudah memutuskan ke arah requirement (enam) dan mencatatnya sebagai RISK-005; yang tersisa hanya menyelaraskan dokumen sumber.
- **Opsi jawaban:**
  - **(a) Plan tetap memakai enam (rekomendasi analis) dan spec §7 diperbaiki menjadi "enam"** saat spec disentuh berikutnya (mis. `/sdlc-audit-consistency` atau revisi lanjutan). Plan tidak perlu diubah.
  - **(b) Plan tetap enam, spec tidak disentuh**; ketidakkonsistenan dibiarkan terdokumentasi sebagai RISK-005. Bekerja, tetapi meninggalkan jebakan bagi pembaca berikutnya.
  - **(c) Turunkan kembali menjadi lima** (buang `DELIVERY_DEAD_BURST_THRESHOLD`) — **ditolak**: REQ-038/AC-038 bergantung padanya; membuangnya berarti mengubah requirement.
- **Dampak:** (a)/(b) tidak mengubah task apa pun; (c) membutuhkan `/sdlc-define-specs`.

### K-06 — AC-027 & AC-042: jendela waktu, kasir uji, dan teknik perlambatan

- **Rujukan:** AC-027, AC-042, TASK-023, spec §6 ("Batas bukti (R-1)"), spec §13 butir 2, RISK-004, Kontingensi 2.
- **Temuan kritis (jadi K-13 di §3):** urutan bukti yang ditulis plan/spec **belum dapat dijalankan apa adanya**. Pada skenario yang dimaksud, kasir mengirim → cURL AuliaPos (10 s) timeout → `callGatewaySend()` mengembalikan `ok:false` → `kirimKeConversation()` membalas **502 "Gagal mengirim"**; UI **tidak** dapat menampilkan "hasil belum pasti" pada percobaan pertama karena AuliaPos tidak pernah menerima balasan Gateway (`504 SEND_UNRESOLVED` tidak sampai). Keadaan "hasil belum pasti" (REQ-041) baru muncul pada **kirim ulang berikutnya**, ketika Gateway membalas `409 SEND_IN_PROGRESS`.
- **Temuan lingkungan (baru):** nomor WhatsApp Gateway saat ini **menerima lalu lintas nyata** (`aulia_inboxdb.messages` = 87 baris `incoming`, terakhir `2026-09-24 14:34`; `gateway_status` = `connected`; `pm2` online 21 jam). Premis RISK-003 gelombang 1 ("bukan produksi, tidak ada pelanggan yang bergantung pada nomor itu") perlu ditegaskan ulang untuk sesi pengukuran ini.
- **Opsi jawaban — prosedur (menutup K-13):**
  - **(a) Perjelas teks prosedur (rekomendasi analis):** nyatakan bahwa percobaan pertama memang berbuntut 502, dan keadaan "hasil belum pasti" diuji pada kirim ulang **di dalam lease** (yang menghasilkan `409 SEND_IN_PROGRESS`). Tidak ada kode/REQ baru; hanya TASK-023 dan spec §13 butir 2 yang diperjelas.
  - **(b) Perluas scope:** perlakukan error cURL timeout AuliaPos (`CURLE_OPERATION_TIMEDOUT`) juga sebagai keadaan "hasil belum pasti" agar muncul sejak percobaan pertama. Ini menyentuh REQ-041/AC-046 (butuh `/sdlc-define-specs`) dan menambah satu cabang di TASK-018.
  - **(c) Keduanya:** terapkan (a) untuk rilis ini dan catat (b) sebagai kandidat gelombang berikutnya; TASK-023 tetap mengukur jalur (a).
- **Opsi jawaban — teknik perlambatan:**
  - **(a) Blokir port sementara (rekomendasi analis).** Satu aturan firewall masuk untuk port Gateway agar koneksi dari AuliaPos menggantung (bukan ditolak) → cURL timeout 10 s persis seperti skenario. Tidak ada perubahan kode maupun `.env`, sesi WhatsApp tidak hilang, dan **tidak** memerlukan `pm2 stop`.
  - **(b) Jeda terkontrol di sisi Gateway.** Menuntut perubahan kode (mis. env `SEND_DELAY_MS`) yang harus ikut di-deploy ke folder live — kode yang diukur lalu berbeda dari kode yang di-commit (kecuali jeda itu memang menjadi bagian kode produksi). Kurang diinginkan.
  - **(c) Proxy penunda sementara.** Ubah `gatewayBaseUrl` di `.env` sementara ke proxy lokal yang menunda; tidak menyentuh kode Gateway dan mudah dibalik, tetapi menambah komponen yang harus dibuat dan dipercaya saat pengukuran.
  - **(d) `pm2 stop` seperti gelombang 1.** Hasilnya **berbeda**: AuliaPos gagal cepat (connection refused), bukan timeout, sehingga skenario "hasil tidak pasti" tidak terbentuk. Hanya cocok sebagai kontrol negatif.
- **Opsi jawaban — jendela, pelaksana, dan durasi:**
  - **(a) Pemilik menyediakan jendela + bertindak sebagai penguji UI (rekomendasi analis).** Pemilik membuka `/inbox` di peramban dan memakai nomor uji sebagai pelanggan; tiga ulangan boleh berurutan dalam satu jendela (total ≤10 menit) karena tidak ada proses yang dimatikan. Batas yang ikut disetujui: blokir port ≤15 detik per percobaan, satu pesan teks per percobaan (tanpa media), tanpa `pm2 stop`.
  - **(b) Pemilik menyediakan kasir uji (staf) pada jam yang ditentukan**, lalu eksekutor menunggu. Paling mendekati pemakaian nyata, tetapi menambah ketergantungan pada kehadiran staf.
  - **(c) Hanya pemilik, tetapi menunda sampai lalu lintas masuk nol** (mis. malam). Menghindari pertanyaan "ada pesan pelanggan yang tertunda?", dengan biaya waktu tunggu.
- **Dampak:** pilihan (a) pada kedua daftar membuat TASK-023 dapat dijalankan tanpa mengubah kode dan tanpa mematikan proses; pilihan (b) pada daftar prosedur menambah pekerjaan spec.

### K-07 — RISK-003: tiga batas jujur (ASSUMPTION-009, ASSUMPTION-007, D-13)

- **Rujukan:** RISK-003 (i)(ii)(iii), ASSUMPTION-009, ASSUMPTION-007, D-13/A-5, AC-043, TASK-008, TASK-023.
- **Temuan:** ketiganya sudah terdokumentasi di plan **dan** diwajibkan oleh spec (ASSUMPTION-009: "MUST ditulis jujur di decision log dan tidak boleh diklaim tertutup"; ASSUMPTION-007: batas bukti fallback JSON; D-13: jaminan ≤ TTL). Tidak ada satu pun yang memblokir eksekusi, dan plan sudah menyediakan mitigasinya (log `[CRITICAL]` untuk `abandoned` yang dipangkas, log `error` untuk `in_flight` basi, catatan paritas fallback JSON).
- **Opsi jawaban:**
  - **(a) Terima ketiganya sebagai keterbatasan terdokumentasi dan tulis apa adanya di decision log (rekomendasi analis).** Ini juga yang sudah diwajibkan spec, jadi pilihan lain berarti mengubah hulu.
  - **(b) Jadikan salah satunya syarat penutupan Wave 2:** mis. "fallback JSON Android MUST diuji nyata sebelum M1 ditutup". Menahan GW-09 tanpa perangkat Android; butuh gelombang/perangkat terpisah dan keputusan pemilik.
  - **(c) Ubah batas TTL 24 jam** (mis. 7 hari) agar jendela idempotensi lebih lebar. Ini mengubah D-13/REQ-032 → `/sdlc-define-specs`; juga memperbesar tabel `outgoing_operations` yang dipangkas lebih jarang.
- **Dampak:** (a) nol pekerjaan tambahan; (b) menunda penutupan; (c) mengubah requirement + nilai env.

### K-08 — CON-014: izin `php spark migrate` dan siapa yang mengeksekusi

- **Rujukan:** CON-014, TASK-016, spec §9 ("Ask first"), F-01, F-02, `plan-bugfix-inbox-test-db-isolation-v1.0.md`.
- **Temuan:** CON-014 (`tidak ada php spark migrate pada database kerja tanpa persetujuan eksplisit`) **sah dan harus dipertahankan** — terlebih lagi karena `aulia_inboxdb` kini berisi data nyata (2 percakapan, 87 pesan masuk) dan pemilik sudah pernah kehilangan data Inbox akibat jalannya suite uji (lihat plan bugfix isolasi DB uji). Namun CON-014 belum menunjuk **siapa** yang mengeksekusi dan **perintah apa** yang dipakai, dan belum menyelesaikan F-01 (DB kerja harus tetap mendapat kolom itu sebelum kode Fase 4 dipakai).
- **Opsi jawaban:**
  - **(a) Eksekutor = `/sdlc-write-code` untuk DB uji, owner untuk DB kerja (rekomendasi analis).** TASK-016 menjalankan migrasi **hanya** ke `aulia_inboxdb_test` (mekanika F-02 opsi a) tanpa persetujuan tambahan karena bukan database kerja. Migrasi `aulia_inboxdb` menjadi satu task DEPLOY terpisah (F-01 opsi a) yang dieksekusi **hanya setelah owner menyetujui**, dengan bukti `SHOW COLUMNS` dan titik rollback.
  - **(b) Semua migrasi dieksekusi owner secara manual** dari instruksi plan (agent tidak menjalankan `spark` sama sekali). Paling konservatif, tetapi memindahkan seluruh verifikasi skema keluar dari plan dan memperlambat TASK-016/TASK-020.
  - **(c) Jangan pakai `spark` sama sekali:** `ALTER TABLE` manual di kedua database (DB uji oleh agent, DB kerja oleh owner). Menghilangkan riwayat migrasi sebagai sumber kebenaran skema — tidak dianjurkan karena `app/Database/Migrations/` adalah konvensi repo ini.
- **Dampak:** (a) menjaga CON-014 apa adanya dan menambah satu approval; (b) tidak menambah approval tetapi menambah langkah manual; (c) menyimpang dari konvensi migrasi repo.

### K-09 — Baseline uji AuliaPos: angka mana yang menjadi gate TASK-020

- **Rujukan:** TEST-008, TASK-020, DEP-009, spec §13 butir 4.
- **Temuan:** **ketiga angka yang beredar semuanya basi atau berbeda konteks:** spec §13 butir 4 menulis 298/948 (setelah M3 Fase 2a, 2026-09-23), plan menulis 324/1097 (setelah M3 Fase 1d), dan pengukuran saya hari ini di branch `v2.3` menghasilkan **`OK (328 tests, 1102 assertions)`**. Plan bugfix isolasi DB uji pun mencatat pergeseran serupa ("plan's 317 baseline was stale"). Jadi angka beku apa pun akan selalu salah.
- **Opsi jawaban:**
  - **(a) Gate = "≥ jumlah yang terukur tepat sebelum perubahan + seluruh uji baru" (rekomendasi analis)**, dengan **328/1102** sebagai angka awal hari ini, dicatat di decision log saat TASK-020 dijalankan. Menutup masalah drift secara permanen.
  - **(b) Pakai angka plan apa adanya (≥ 324/1097).** Uji baru tetap wajib ditambahkan, jadi gate ini secara teknis lolos; tetapi angka yang salah memberi kesan pengukuran yang tidak teliti.
  - **(c) Sinkronkan spec §13 butir 4 ke 328/1102 sekarang.** Membutuhkan `/sdlc-define-specs`; tidak disarankan di sesi klarifikasi (dan angka itu akan basi lagi).
- **Dampak:** (a) nol perubahan dokumen selain catatan di decision log; (b) dan (c) menyimpan angka yang cepat basi. Apa pun pilihannya, gate RIIL tetap sama: exit code 0, nol skip, nol suppression (Floor-Guard).

### K-10 — Bukti yang tidak boleh diklaim (AC-026(b) stub-only & cabang 422)

- **Rujukan:** AC-026(b), REQ-036, A-2, A-8b, TASK-009, TASK-012, TASK-013, spec §6 ("Batas bukti (A-2)").
- **Temuan:** keduanya **terverifikasi benar** dari kode: `InboxGatewayApi.php` hanya mengembalikan `200`/`400`/`500` (tidak ada `422`), dan `INVALID_CHAT_ID` dijaga `isDecodableJid()` di Gateway **sebelum** `sendMessage()` sehingga tidak pernah muncul dari Baileys pada lalu lintas normal. Plan sudah mewajibkan penandaan eksplisit di TASK-009 dan TASK-012.
- **Opsi jawaban:**
  - **(a) Konfirmasi larangan klaim + wajibkan label tertulis (rekomendasi analis).** Output uji, ringkasan TASK-009/TASK-013, dan decision log MUST memuat kata `stub-only` untuk AC-026(b) dan `[Assumed / Out of Scope]` untuk cabang `422`; keduanya MUST NOT muncul di bagian bukti pengukuran nyata TASK-023.
  - **(b) Hapus kedua cabang dari kode** agar tidak ada kode mati. Menghilangkan jaring pengaman (`failed` untuk JID tidak terdecode, `dead` untuk penolakan permanen) dan menambah perubahan kontrak — tidak dianjurkan.
  - **(c) Tambahkan AC baru "tidak ada klaim bukti produksi palsu".** Berlebihan: larangan ini sudah ada di spec §6 dan plan §7.1 (RISK-006).
- **Dampak:** (a) nol perubahan kode/REQ; hanya disiplin pelaporan yang ditegaskan.

### K-11 — Bila cakupan harus dipersempit/diperluas: TASK dan AC mana

- **Rujukan:** seluruh §2 dan §3 plan; Introduction (24 task / 5 fase); RISK-009 (anti scope creep).
- **Temuan:** plan sudah bottom-up, tidak ada task XL, dan tidak ada task yang bisa dibuang tanpa memutus AC. Opsi penyempitan yang **tersedia secara struktural** hanya dua (memindahkan Fase 2 atau Fase 3); keduanya punya konsekuensi besar yang harus disadari pemilik.
- **Opsi jawaban (pilih satu):**

| Opsi | TASK dibuang/ditunda | AC hilang | Konsekuensi |
| --- | --- | --- | --- |
| **(a) Tanpa perubahan cakupan (rekomendasi analis)** | tidak ada; hanya **+1 task DEPLOY migrasi DB kerja** (F-01) dan penyesuaian teks TASK-023 (K-13) | tidak ada | 25 task; GW-09 + GW-19 tertutup penuh; sesuai spec v1.1 apa adanya |
| **(b) Tunda Fase 3** (TASK-011..TASK-014) | 4 task | AC-033..AC-038 | GW-19 tidak tertutup; tinggal 20–21 task; hanya idempotensi keluar yang selesai |
| **(c) Tunda Fase 2** (TASK-007..TASK-010) | 4 task | AC-026, AC-028..AC-032, AC-042, AC-043 | Terminal state, lease, dan pemulihan saat start hilang; **AC-027 ikut tidak dapat diukur** (butuh lease), sehingga GW-09 pun hanya separuh |
| **(d) Perluas ke "hasil belum pasti" saat cURL timeout** (K-06 opsi b) | +1 cabang TASK-018 + REQ/AC baru | — | Membutuhkan `/sdlc-define-specs` lebih dulu; menambah 1 sesi dokumen |

- **Tambahan yang diminta pemilik ("sebutkan TASK dan AC mana yang dibuang/ditambah"):** atas rekomendasi analis, yang **ditambah** hanya satu task AuliaPos (`[AP]`, DEPLOY migrasi `aulia_inboxdb`) dan penjelasan prosedur TASK-023; **tidak ada** TASK atau AC yang dibuang. Penyusunan ulang plan tetap diserahkan ke `/sdlc-plan-tasks` di sesi terpisah, bukan di sini.
- **Dampak:** (a) mempertahankan traceability penuh ke spec v1.1; (b)/(c) mengharuskan plan menyatakan dengan jujur bahwa GW-09/GW-19 tidak tertutup.

---

## 3. ⚠️ Assumed / Auto-Resolved / Out of Scope

Butir yang **tidak** dijadikan pertanyaan pemblokir, beserta penanganannya bila pemilik tidak menjawab (pola `[Assumed / Auto-Resolved]` mengikuti laporan klarifikasi gelombang 1):

- **K-12 (turunan F-01 & F-02) — mekanika migrasi.** *Handling:* `[Assumed / Auto-Resolved]` → jalankan **opsi (a)** pada F-01 (tambah 1 task DEPLOY migrasi DB kerja, karena tanpa itu Fase 4 pasti rusak) dan **opsi (a)** pada F-02 (migrasi DB uji lewat `CI_ENVIRONMENT=testing` + `--dbgroup inbox`). Keduanya tidak mengubah REQ/AC dan aman untuk Fase 1.
- **K-13 — urutan bukti UI pada AC-027.** *Handling:* `[Assumed / Auto-Resolved]` → opsi (a): percobaan pertama berbuntut 502 dan keadaan "hasil belum pasti" diverifikasi pada kirim ulang di dalam lease (`409 SEND_IN_PROGRESS`); opsi (b) dicatat sebagai kandidat gelombang berikutnya. Tidak menambah kode.
- **K-14 — angka baseline suite.** *Handling:* `[Assumed / Auto-Resolved]` → gate TASK-020 memakai aturan "≥ jumlah terukur tepat sebelum perubahan + uji baru"; angka awal hari ini **328 test / 1102 assertion** (menggantikan 324/1097 di plan dan 298/948 di spec §13 butir 4).
- **K-15 — detail kontrak yang belum ditulis.** *Handling:* `[Assumed / Auto-Resolved]` untuk tiga hal kecil:
  - (i) respons `kirimKeConversation()` untuk keadaan "belum pasti" memakai `200` + `status:'error'` + `error_code`/`state`/`replayed` (additive, UI bercabang pada `error_code`) — konstanta `status` lama tidak berubah;
  - (ii) permintaan tanpa `operation_id` menyimpan `gateway_operation_id = NULL` (indeks UNIQUE menerima banyak `NULL`) dan TASK-017 MUST memuat satu assert untuk itu;
  - (iii) jaminan satu baris `messages` pada AC-041 sekaligus dijaga oleh `wa_message_id` UNIQUE yang sudah ada (Gateway mengembalikan `wa_message_id` tersimpan saat replay) — tidak perlu kolom tambahan, cukup dicatat sebagai pertahanan ganda.
- **ASSUMPTION-004, 005, 006, 008, 010, 011 — tidak diinterogasi ulang.** *Handling:* `[Assumed / Accepted]` — keenamnya sudah terbukti konsisten dengan kode berjalan pada sesi klarifikasi spec (88/100) dan tidak mengandung ambiguitas yang mengubah eksekusi. ASSUMPTION-010 diverifikasi hari ini: form balas memang AJAX (`index.php:2164`) dan tidak memakai `sessionStorage`, sehingga state composer bertahan setelah kegagalan — RISK-007 (muat ulang halaman) tetap apa adanya.
- **Out of scope sesi ini (tidak dipertanyakan):** E-02/E-07, GW-20/GW-21, M2 State Consistency, Ticket 05 dan 12–16, penyimpanan media, serta perubahan kontrak masuk `POST /api/inbox/gateway/messages` — semuanya sudah dinyatakan di luar scope oleh spec §1.1 dan plan RISK-009. Tidak menurunkan skor.

---

## 4. 📝 Next Steps

**Keputusan dokumen minimum (sebelum TASK-016 dijalankan — TIDAK memblokir Fase 1):**

1. **F-01:** tambahkan satu task DEPLOY `[AP]` untuk migrasi `aulia_inboxdb` (persetujuan owner + bukti + rollback).
2. **F-02:** ganti kalimat TASK-016 dengan perintah migrasi DB uji yang eksplisit + langkah verifikasi `SHOW COLUMNS`.
3. **K-13:** perjelas urutan bukti AC-027 di TASK-023 (percobaan pertama 502 → kirim ulang di dalam lease → `409` + keadaan "hasil belum pasti").
4. **K-09:** ganti angka gate TASK-020/TEST-008/DEP-009 dengan aturan "≥ terukur sebelum perubahan" dan angka awal 328/1102.
5. **K-06:** tulis teknik perlambatan terpilih, durasi blokir, pelaksana, dan konfirmasi ulang premis lalu lintas nyata pada TASK-023.
6. **K-15 (i):** tetapkan bentuk respons "belum pasti" pada TASK-018 agar uji controller punya kontrak pasti.

**Yang tidak perlu diubah:** struktur 5 fase, penomoran TASK-001..TASK-024 (kecuali penambahan satu task DEPLOY), pola VERIFY/APPROVAL/DEPLOY, keputusan RISK-005 (enam variabel), aturan guard statis TASK-005, larangan klaim bukti TASK-009/TASK-012, dan keputusan "tidak ada ADR".

**Kesiapan Fase 1 (tidak terpengaruh temuan apa pun):**

- **TASK-001 dapat dijalankan sekarang.** RISK-001 terverifikasi hari ini (`C:\projects\WA-Gateway` @ `21a4cb6`, hanya `master`, tanpa worktree tambahan), Node `v20.20.2`, `better-sqlite3 ^11.3.0`, dan `pm2` 7.0.4 dengan `wa-gateway` online.

**Pengelolaan artefak:**

- **`CONTEXT.md`:** tidak ada istilah domain baru yang perlu dibakukan (plan sendiri menunda pembakuan istilah seperti gelombang 1).
- **ADR:** tidak ada. Semua keputusan di laporan ini (nilai env, mekanika migrasi, urutan merge, teknik pengukuran) dapat dibalik dengan biaya kecil, sehingga tidak memenuhi Triple Gate `.claude/standards/ADR-FORMAT.md`.

---

> **User Decision Prompt:**
> Dokumen telah mencapai Readiness Score **82/100** dan **siap** untuk fase berikutnya, dengan dua perbaikan dokumen wajib (F-01, F-02) yang tidak memblokir Fase 1. Apakah Anda ingin **PROCEED** ke fase berikutnya, atau ingin **REFINE** dan memperjelas lebih lanjut?
>
> **Bila memilih PROCEED, arahkan ke `/sdlc-write-code` Fase 1 mulai TASK-001** (membuat worktree `C:\projects\WA-Gateway-m1w2` + branch `feature/m1-wave2-outgoing-idempotency` dari `21a4cb6`), pada sesi baru. Keputusan K-01..K-11, F-01, dan F-02 dibawa sebagai catatan amendment agar tidak hilang; F-01/F-02 baru diperlukan saat menyentuh TASK-016 (Fase 4), bukan Fase 1.
>
> **Jawaban pemilik proyek:** **PROCEED** (24 September 2026). Butir yang tidak dijawab berbeda mengikuti `[Assumed / Auto-Resolved]` di §3 (rekomendasi analis). Laporan difinalkan pada Readiness **82/100**; pekerjaan diarahkan ke **`/sdlc-write-code` sesi baru, Fase 1, mulai TASK-001**. Bila pemilik ingin menyimpang pada butir tertentu (terutama K-01 cakupan dead-letter atau K-02 scope AuliaPos), penyimpangan itu MUST melalui `/sdlc-define-specs` lebih dulu karena mengubah spec v1.1, bukan plan.
