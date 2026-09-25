# 🔍 Clarification Report [Review Iteration 1]

> [!SUCCESS]
> **REMEDIATION STATUS: RESOLVED**
> This audit report was remediated by the Specification Architect on 2026-09-25 (Spec v1.3 → **v1.4**, surgical: 25 insertions / 15 deletions).
>
> - **F-01 (test database facts) — RESOLVED:** §6, §10, and §12 no longer claim SQLite `:memory:`; they state that Inbox tests run on MariaDB `aulia_inboxdb_test`, that the ASCII-only scope of AC-014 is a deliberate scope limit, and that AC-016 is not measured through PHPUnit because the test database is empty (its `setUp()` methods call `emptyTable()`).
> - **F-02 (CON-004 vs §6 contradiction) — RESOLVED:** CON-004 now carries the approved exception for **one pure Match Snippet Service** plus its unit test, and §7 lists it as a project-structure entry.
> - **F-03 (missing AC-016 procedure) — RESOLVED:** §6 now holds the binding procedure (dedicated `aulia_inboxdb_perf`; schema-only provisioning per `docs/ARCHITECTURE.md` §11, not `php spark migrate`; guarded Spark command `aulia:seed-fase1e-perf`; temporary `.env` override; 3 keywords × 3 runs → median; record → clean up → restore `.env`), with §9 "Ask first" and §13 pointing back to it.
> - **Side item — ASSUMPTION-004:** promoted from `[!WARNING] ... perlu dikonfirmasi` to `[!IMPORTANT] ... CONFIRMED` with the R-01 numbers.
> - **Side item — F-05:** the extra "FULLTEXT is not available in SQLite" rationale was removed; CL-020 was not reopened.
> - **Side item — R-06:** REQ-017b now states that the guard is a boolean released in `.finally()`, with no timeout added.
> - **Still owed outside this skill:** F-04 (stale claim plus missing Fase 1d/1e sections in `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md`) belongs to `/sdlc-plan-tasks`; `docs/ARCHITECTURE.md` §11 still owes a paragraph for `aulia_inboxdb_perf` and the new Spark command; PRD §9.2 and the plan still cite "Spec v1.3".
> - **Projected Readiness Score:** **96/100**

**Target Document:** `spec/spec-design-m3-operational-inbox-fase1.md` v1.3 — kontrak **Fase 1e (GH-010, pencarian isi pesan)**: §1.2 (ASSUMPTION-004, CL-016..CL-021), §3 (REQ-014..REQ-017, CON-004), §4.4 (`match_snippet`), §5 (AC-014..AC-016), §6, §9, §10, §12, §13.

**Reference Documents:**

- `prd-20260922-0141-chat-whatsapp-inbox.md` v1.3 GH-010 (kriteria 1–5) — sumber perilaku yang diklarifikasi.
- `docs/ARCHITECTURE.md` §11 (baris 310–322) — prosedur baku membuat database uji non-live dari skema Inbox asli, sekaligus alasan `php spark migrate` tidak dapat dipakai membangunnya.
- `app/Commands/RepairTotalDibayar.php` dan `docs/.../11. dokumentasi teknis untuk developer.md` (baris 246) — preseden command satu kali pakai milik proyek ini.
- `app/Views/inbox/index.php` (baris 940–1010 dan 2493) — fungsi pemuatan daftar, pencarian `q`, dan `setInterval` 6 detik yang diaudit oleh REQ-017b.
- `app/Controllers/Inbox.php` (`apiConversations()`), `app/Models/ConversationModel.php`, `tests/session/OperationalInboxConversationTest.php`, `app/Config/Database.php` — seam yang dipakai AC-014 dan AC-016.
- `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — plan Fase 1a–1c yang belum memuat Fase 1d/1e.

**Batas sesi:** sesi ini hanya bertanya dan mengaudit. PRD, Spec, dan Plan **tidak** ditulis ulang, tidak ada source code yang dibuat atau diubah, dan tidak ada keputusan yang diterapkan langsung ke dokumen mana pun.

---

## Readiness Score

**Readiness Score:** **87/100** — *Good Enough* (di atas ambang 80).

**Status:** Kontrak Fase 1e sudah dapat direncanakan dan diimplementasikan tanpa tafsir tambahan. Tersisa **3 perbaikan dokumen spec wajib** (F-01..F-03 di §2) dan **1 perbaikan plan** (F-04). F-01..F-03 harus selesai sebelum `/sdlc-write-code` Fase 1e menyentuh §6 dan §9.

**Score Breakdown:**

| Kriteria | Skor | Alasan |
| --- | --- | --- |
| **Completeness (40)** | **34/40** | Seluruh butir Fase 1e lengkap dan saling terhubung: REQ-014..REQ-017, CON-004, CL-016..CL-021, AC-014 a–i, AC-015 a–e, AC-016, plus definisi istilah Match Snippet dan jejak ke GH-010 di §13/§15. Dikurangi 6 poin karena: (a) prosedur operasional AC-016 belum ditulis di dokumen mana pun (target DB, angka data uji, cara menjalankan, cara membersihkan) sehingga hanya hidup di laporan ini; (b) ASSUMPTION-004 masih berstatus asumsi padahal angkanya sudah ditetapkan; (c) §9 "Ask first" menyebut konsekuensi gagal AC-016 tetapi tidak menyebut langkah pengukurannya. |
| **Clarity (30)** | **27/30** | Bahasa terukur: rumus potongan Match Snippet (120 karakter, jendela ±40 sebelum kemunculan pertama, `mb_*`), urutan pesan cocok terbaru, aturan `match_snippet = null` (CL-018), dan bentuk response §4.4 semuanya presisi. Dikurangi 3 poin karena CON-004 (baris 157) masih berbicara seolah perubahan hanya di `apiConversations()` dan view, bertentangan dengan §6 baris 315 yang mengizinkan ekstraksi Service (R-02), sehingga pembaca tidak tahu file apa saja yang boleh disentuh. |
| **Alignment (30)** | **26/30** | Traceability ke GH-010 lengkap dan kosakata istilah konsisten dengan definisi Match Snippet; tidak ada requirement yatim. Dikurangi 4 poin karena dua klaim fakta di Spec tidak sesuai kondisi repo: §6 baris 316 dan §12 baris 436/387 menyatakan database test adalah SQLite `:memory:`, padahal test Inbox berjalan di MariaDB `aulia_inboxdb_test` (§F-01), dan plan Fase 1a–1c masih menyatakan Spec belum memuat Fase 1e (F-04). |

**Critical Flaw Veto:** **Tidak aktif.** Tidak ada kontradiksi fundamental yang mematikan Fase 1e. Dua temuan (F-01 klaim database test, F-02 kontradiksi CON-004 vs §6) adalah **koreksi fakta dan kalimat pengecualian** berbiaya kecil, bukan pertentangan yang membatalkan desain, sehingga skor tidak dikunci di 79.

---

## Bukti Verifikasi Runtime (2026-09-25)

Dibaca langsung dari lingkungan kerja pada sesi klarifikasi ini (read-only, tidak ada berkas aplikasi yang ditulis):

| Butir | Hasil | Dampak |
| --- | --- | --- |
| Folder utility di root | Pencarian folder bernama `scripts`, `tools`, atau `bin` di root = **kosong**; proyek tidak punya folder skrip | Rencana lama "seeder di `scripts/seed-fase1e-perf.php`" tidak punya preseden → R-03 direvisi |
| `app/Commands/` | Diisi satu command: `RepairTotalDibayar.php` — `namespace App\Commands`, `extends BaseCommand`, `$group='AULIA'`, `$name='aulia:repair-total-dibayar'`, opsi `--fix`, pola `CLI::write()` + `EXIT_SUCCESS`/`EXIT_ERROR` | Preseden resmi untuk alat satu kali pakai; command ini juga didaftarkan sebagai command utama di `docs/.../11. dokumentasi teknis untuk developer.md` baris 246 |
| `docs/ARCHITECTURE.md` §11 baris 310–322 | Prosedur baku DB uji non-live: `CREATE DATABASE ... utf8mb4_general_ci` lalu `mysqldump --no-data --routines --triggers aulia_inboxdb \| mysql ...`; disertai catatan *"`php spark migrate` cannot build this database: migration history is stored in the `default` database"* | Cara menyiapkan `aulia_inboxdb_perf` sudah baku; `php spark migrate --dbgroup inbox` **tidak** boleh diandalkan |
| Database `aulia_inboxdb_test` (terukur hari ini) | 5 tabel Inbox + 1 tabel tambahan `test_messages`; `messages` = **0 baris** | Skema test identik dengan live; aman dipakai sebagai sumber klon skema maupun dibersihkan |
| Database live `aulia_inboxdb` | 5 tabel Inbox dengan nama yang sama; sebelumnya terukur hanya 30 conversation / 142 messages | Tidak boleh disentuh alat ukur (Spec §9); tetap jadi sumber schema-only |
| `.gitignore` | Memuat `.env` dan `.env.*` (komentar "JANGAN PERNAH ter-commit") | Override `.env` sementara pada R-05 tidak berisiko ter-commit |
| `app/Views/inbox/index.php` | `setInterval(muatUlangDaftarConversation, 6000)` baris 2493; `muatUlangDaftarConversation()` baris 966–988 hanya `.then()`/`.catch()` (tanpa `.finally()`, tanpa timeout); `ambilSemuaConversation()` baris 940–962 memuat halaman berurutan sampai halaman terakhir tidak penuh | STD-01 (pengaman in-flight) terkonfirmasi belum ada; REQ-017b dan R-06 mengikat fungsi yang sama |
| `index.php` baris 936–943 | Komentar dan kode menunjukkan **setiap** pemuatan daftar, termasuk polling 6 detik, mengirim `q` yang aktif | Pengaman REQ-017b harus memperlakukan polling dan pencarian seragam |
| Index tabel `messages` | Hanya `(conversation_id, message_timestamp)` dan `wa_message_id` unik; tidak ada index `text` | Konsisten dengan CL-020: `LIKE '%q%'` tanpa index, AC-016 satu-satunya pengaman kecepatan |

---

## 1. Ambiguitas yang Sudah Ditutup (R-01..R-06)

Seluruh keputusan di bawah ini diambil oleh pemilik produk pada sesi klarifikasi Fase 1e (2026-09-25 dan sesi sebelumnya). Kolom "Dampak" menyebut ke mana keputusan itu harus mendarat.

| ID | Ambiguitas | Keputusan pemilik | Dampak ke dokumen |
| --- | --- | --- | --- |
| R-01 | Angka data uji ASSUMPTION-004 untuk AC-016 belum ditetapkan | **2.000 conversation × 100 pesan = 200.000 baris `messages`**, tiap kata kunci diukur **median 3 percobaan**, memakai **3 kata kunci** (termasuk satu yang sangat umum) | Spec §1.2: ASSUMPTION-004 naik status menjadi CONFIRMED dengan angka ini; AC-016 tetap ≤ 3 detik |
| R-02 | Lokasi logika Match Snippet (potong 120 karakter) — di controller atau unit terpisah | Diekstrak ke **Service baru mengikuti pola `InboxSlaService`**, beserta unit test; bukan ditanam di controller | Spec: CON-004 perlu kalimat pengecualian (lihat F-02); §6 baris 315 sudah mengizinkan; `/sdlc-define-specs` yang merevisi |
| R-03 | Bentuk alat pengisi data uji AC-016 | **Direvisi:** bukan `scripts/seed-fase1e-perf.php` (folder itu tidak ada di repo), melainkan **Spark Command `app/Commands/SeedFase1ePerf.php`** dengan perintah `php spark aulia:seed-fase1e-perf --dbgroup=inbox`, mengikuti preseden `aulia:repair-total-dibayar`; **guard di dalam `run()` menolak** bila nama database aktif bukan `aulia_inboxdb_perf`; tidak dijalankan otomatis oleh `composer test` maupun CI | Spec §6/§9 menyebut alat ini beserta guard-nya; `/sdlc-plan-tasks` membuat tasknya |
| R-04 | Target database alat ukur | **Database khusus baru `aulia_inboxdb_perf`** — bukan `aulia_inboxdb_test`, karena `setUp()` belasan test Inbox memanggil `emptyTable()` sehingga 200.000 baris akan terhapus bila ada `composer test` di antara pengisian dan pengukuran; bukan pula `aulia_inboxdb` (live) | Spec §6/§9 menyebut DB perf terpisah; `docs/ARCHITECTURE.md` §11 perlu resep provisioning-nya |
| R-05 | Cara alat ukur dan aplikasi mencapai `aulia_inboxdb_perf` | **Override sementara `.env`** (`database.inbox.database = aulia_inboxdb_perf`; berkas ini di-gitignore) sehingga seeder dan **pengukuran endpoint HTTP asli** memakai group `inbox` yang sama; command tetap menolak bila nama DB aktif bukan DB perf; **`.env` dikembalikan** dan data uji dibersihkan setelah pengukuran selesai | Spec §6/§9 menuliskan urutan langkahnya: provisioning skema → isi data → ukur 3×3 → catat hasil → bersihkan → kembalikan `.env` |
| R-06 | REQ-017b: kapan pengaman putaran pemuatan dilepas dan apakah perlu batas waktu tegas | **Flag boolean dilepas di `.finally()` saja** — perubahan paling kecil, tanpa aturan baru di Spec dan tanpa AC tambahan. **Tidak** memakai AbortController/timeout. Risiko disetujui secara sadar: bila satu request benar-benar menggantung, refresh daftar berhenti sampai halaman di-reload | Spec §3 REQ-017b sudah cukup; AC-015d tidak perlu butir baru; implementasi wajib memakai `.finally()` |

Catatan untuk R-04/R-05: skema `aulia_inboxdb_perf` disiapkan dengan resep baku `docs/ARCHITECTURE.md` §11 (schema-only dari `aulia_inboxdb`), **bukan** lewat migration, supaya struktur DB perf dan produksi dijamin identik dan tetap tidak ada migration baru (menjaga CL-020/CON-004).

---

## 2. Findings (Perbaikan Dokumen)

### F-01 — Spec salah menyebut database test sebagai SQLite `:memory:`

**Bukti:** test Inbox berjalan pada MariaDB `aulia_inboxdb_test` (5 tabel Inbox, `messages` = 0 baris, terukur hari ini). Hanya group `default` (AuliaPos) yang dialihkan ke SQLite `tests` saat `ENVIRONMENT === 'testing'`; group `inbox` justru dipaksa ke MariaDB `aulia_inboxdb_test`, dan `docs/ARCHITECTURE.md` §11 mendokumentasikan database itu sebagai baku.

**Lokasi yang perlu dikoreksi:** §6 baris 316 ("test memakai SQLite `:memory:`"), §6 baris 317 ("SQLite in-memory tidak mewakili MariaDB"), §12 baris 436 (perbedaan huruf beraksen antara SQLite dan MariaDB), dan §10 baris 387 (FULLTEXT "tidak ada di SQLite").

**Dampak:** alasan di balik keputusan "AC-014 cukup memakai teks ASCII" (§6 baris 316) dan sebagian alasan penolakan FULLTEXT tidak lagi berdasar fakta. Karena engine test sekarang sama dengan produksi (`utf8mb4_general_ci`), perilaku `LIKE` untuk huruf besar/kecil dan huruf beraksen **sebenarnya dapat diuji otomatis** — sepanjang schema `aulia_inboxdb_test` disinkronkan lebih dulu.

**Catatan:** mengunci cakupan test ke ASCII tetap **boleh** dipertahankan (hemat biaya, tidak menyentuh perilaku yang dijanjikan), tetapi kalimatnya harus diubah dari "karena SQLite" menjadi "sebagai pembatasan cakupan yang disengaja". Klaim §6 baris 317 bahwa pengukuran kecepatan tidak bisa lewat PHPUnit tetap benar apa adanya, tetapi alasannya harus berubah menjadi "PHPUnit berjalan di DB test yang kosong dan tidak punya data uji 200.000 baris".

**Aksi:** `/sdlc-define-specs` mengoreksi §6, §10, dan §12.

### F-02 — CON-004 bertentangan dengan §6 soal ekstraksi Service

**Bukti:** CON-004 (§3 baris 157) menulis *"Perubahan terbatas pada `apiConversations()` (predikat + `match_snippet`) dan daftar di `app/Views/inbox/index.php`"*, sedangkan §6 baris 315 mengizinkan pemotongan Match Snippet diuji sebagai pure function "bila logikanya diekstrak ke Service, mengikuti pola `InboxSlaService`".

**Dampak:** implementasi yang mengikuti R-02 (Service terpisah + file test baru) terlihat seperti melanggar CON-004, padahal R-02 sudah disetujui pemilik. Ini jenis kontradiksi yang membuat `/sdlc-code-review` menandai penyimpangan palsu.

**Aksi:** `/sdlc-define-specs` menambahkan satu pengecualian eksplisit di CON-004, misalnya "kecuali penambahan satu Service murni untuk pemotongan Match Snippet beserta unit test-nya (tanpa migration, index, parameter, atau endpoint baru)".

### F-03 — Prosedur operasional AC-016 belum ada di dokumen mana pun

**Bukti:** Spec §6 baris 317 hanya menyebut "diukur manual dengan data uji ASSUMPTION-004 (mis. seeder/skrip sekali pakai di luar `composer test`)". Rincian yang sudah diputuskan pada sesi ini (R-03, R-04, R-05) hanya hidup di laporan ini.

**Dampak:** siapa pun yang mengerjakan Fase 1e harus menafsir ulang, dan justru di langkah yang paling berisiko menyentuh database.

**Aksi:** `/sdlc-define-specs` menambahkan ke §6 (dan satu rujukan di §9 "Ask first") butir berisi: database target `aulia_inboxdb_perf` yang terpisah dari `aulia_inboxdb` dan `aulia_inboxdb_test`; provisioning skema memakai resep schema-only `docs/ARCHITECTURE.md` §11; alat pengisi data adalah Spark Command `aulia:seed-fase1e-perf` yang menolak berjalan bila database aktif bukan DB perf; pengukuran memakai endpoint HTTP asli dengan `database.inbox.database` di-override sementara melalui `.env` lalu dikembalikan; larangan absolut menyentuh `aulia_inboxdb`; larangan menyambungkannya ke `composer test`/CI; kewajiban mencatat hasil (3 kata kunci × 3 percobaan, median) di plan/walkthrough dan membersihkan data uji setelahnya.

### F-04 — Plan Fase 1a–1c basi dan tidak punya Fase 1d/1e

**Bukti:** `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` baris 21 masih menyatakan Spec belum memuat Fase 1e, padahal Spec v1.3 sudah memuatnya; plan juga tidak punya bagian Fase 1d (GH-009) maupun Fase 1e (GH-010).

**Aksi:** `/sdlc-plan-tasks` menghapus klaim basi itu dan menambah bagian Fase 1d serta Fase 1e.

### F-05 — Alasan penolakan FULLTEXT perlu dirapikan (informasional, tanpa AC baru)

Substansi penolakan FULLTEXT tetap valid dan tidak berubah: FULLTEXT mencocokkan **kata** (bukan potongan seperti `aerah` pada "Saerah"), mengabaikan kata pendek dan *stopword*, sehingga perilakunya berbeda dari pencarian nama/nomor. Yang perlu dihapus hanya alasan tambahan "FULLTEXT tidak ada di SQLite", karena database test proyek ini MariaDB (F-01). Keputusan CL-020 sendiri **tidak** dibuka kembali di sesi ini.

---

## 3. Handoff

### 3.1 Ke `/sdlc-define-specs` (Spec v1.3 → v1.4, surgical)

1. **ASSUMPTION-004 → CONFIRMED** dengan angka R-01 (2.000 conversation × 100 pesan; 3 kata kunci; median 3 percobaan).
2. **CON-004** (F-02): tambah pengecualian untuk satu Service murni pemotong Match Snippet beserta unit test-nya.
3. **§6/§10/§12** (F-01, F-05): koreksi klaim database test; ubah alasan pembatasan ASCII dari "karena SQLite" menjadi pembatasan cakupan yang disengaja; sesuaikan alasan mengapa kecepatan tidak diuji lewat PHPUnit.
4. **§6 dan §9** (F-03): tambahkan prosedur operasional AC-016 sesuai R-03/R-04/R-05 (DB perf terpisah, guard command, override `.env` sementara, larangan menyentuh `aulia_inboxdb`, larangan wiring ke `composer test`/CI, kewajiban mencatat hasil dan membersihkan data uji).
5. **REQ-017b**: tidak perlu rumusan baru (R-06), cukup pastikan implementasi memakai `.finally()`; bila dianggap perlu, tambahkan satu klausa "pengaman dilepas saat putaran selesai, berhasil maupun gagal".

### 3.2 Ke `/sdlc-plan-tasks` (Plan Fase 1d + Fase 1e)

Fase 1e dipecah sebagai irisan vertikal (tracer bullet), masing-masing dapat didemokan sendiri:

1. **Service Match Snippet** — `app/Services/` (pola `InboxSlaService`) + unit test di `tests/unit/`; murni, tanpa DB (R-02, CL-019, §4.4).
2. **API pencarian isi pesan** — perluasan `apiConversations()`: predikat `EXISTS`/join agregat satu query ke `messages` (termasuk `is_internal`, mengecualikan `deleted_at`, `text` NULL) dan pembentukan `match_snippet` per conversation; test session AC-014 a–i pada seam `tests/session/` yang sudah ada (REQ-014..REQ-016, CL-016..CL-018).
3. **Layar** — render Match Snippet + label "Internal" memakai `escapeHtmlInbox()`, dan pengaman putaran pemuatan di `.finally()` (REQ-017, AC-015 a–e; perilaku JS dicek manual per poin karena proyek tidak punya test runner JS).
4. **Alat pengisi data** — Spark Command `app/Commands/SeedFase1ePerf.php` (`aulia:seed-fase1e-perf`) mengikuti preseden `aulia:repair-total-dibayar`, dengan guard nama database dan tanpa wiring ke `composer test`/CI (R-03).
5. **Provisioning + pengukuran AC-016** — `aulia_inboxdb_perf` dibuat schema-only dari `aulia_inboxdb` (resep §11 `docs/ARCHITECTURE.md`), data 200.000 baris diisi, endpoint HTTP asli diukur 3 kata kunci × 3 percobaan (median ≤ 3 detik) dengan `database.inbox.database` di-override sementara, hasil dicatat di walkthrough, lalu data dibersihkan dan `.env` dikembalikan (R-04, R-05).
6. **Perbaikan plan** — hapus klaim basi baris 21 dan tambahkan bagian Fase 1d (F-04).

Aturan berhenti yang tetap berlaku: bila AC-016 gagal, **berhenti dan laporkan hasil pengukuran** sebelum menambah index/FULLTEXT/migration apa pun (CL-020, §9 "Ask first").

### 3.3 Ke `/sdlc-map-architecture`

`docs/ARCHITECTURE.md` §11 perlu satu paragraf tambahan tentang `aulia_inboxdb_perf` dan command `aulia:seed-fase1e-perf`, supaya agen berikutnya tidak menemukan database tanpa penjelasan.

---

## 4. Verifikasi yang Sudah Dijalankan di Sesi Ini

- Membaca kode, bukan menebak: `app/Views/inbox/index.php` (baris 936–1010, 2493), `app/Commands/RepairTotalDibayar.php`, `app/Config/Database.php`, `tests/session/OperationalInboxConversationTest.php`, Spec §1.2/§3/§4.4/§5/§6/§10/§12/§13.
- Mengukur langsung: `SHOW TABLES` pada `aulia_inboxdb_test` dan `aulia_inboxdb`, jumlah baris `messages` pada DB test, serta keberadaan folder `scripts`/`tools`/`bin` di root.
- Tidak ada perubahan pada PRD, Spec, Plan, maupun source code. Satu-satunya berkas yang dibuat di sesi ini adalah laporan ini.

---

## 5. User Decision

Readiness Score **87/100** berada di atas ambang 80 (tanpa *Critical Flaw Veto*). Pilihan yang tersedia: **PROCEED** ke `/sdlc-define-specs` (memakai F-01..F-03 sebagai daftar revisi) lalu `/sdlc-plan-tasks`, atau **REFINE** lebih dulu bila masih ada butir yang ingin diperiksa ulang.
