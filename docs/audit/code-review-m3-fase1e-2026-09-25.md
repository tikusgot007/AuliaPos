# Code Review — M3 Fase 1e (Pencarian Isi Pesan)

> [!NOTE]
> Laporan ini **non-normatif**. Sumber normatif tetap `spec/spec-design-m3-operational-inbox-fase1.md` (rev 1.4)
> dan `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` (v1.4, `Completed`). Bila terjadi konflik, Spec + Plan menang.

| Item | Nilai |
| --- | --- |
| Tanggal | 2026-09-25 |
| Branch | `v2.3` |
| Titik tetap | `0f22806^` → `HEAD` (`bd00c84`) |
| Cakupan | 7 berkas, +1139 / -8 |
| Metode | Two-Axis Review (Standards vs Spec) + verifikasi orkestrator |

## 1. Cakupan dan Metode

Review mengikuti Two-Axis Review: Axis A (Standards & Security) dan Axis B (Spec Compliance),
dijalankan oleh dua reviewer terpisah. Laporan ini **tidak menggabungkan atau mengurutkan ulang**
temuan antar-axis; kedua axis disajikan apa adanya.

Titik tetap `0f22806^` dipilih karena `0f22806` adalah commit pertama Fase 1e. Berkas yang ditinjau:

| Berkas | Status |
| --- | --- |
| `app/Services/InboxMatchSnippetService.php` | baru, 84 baris |
| `app/Controllers/Inbox.php` | diubah, +100 |
| `app/Views/inbox/index.php` | diubah, +67 |
| `app/Commands/SeedFase1ePerf.php` | baru, 328 baris |
| `tests/unit/InboxMatchSnippetServiceTest.php` | baru, 193 baris |
| `tests/session/OperationalInboxConversationTest.php` | diubah, +322 |
| `tests/session/OperationalInboxScreenTest.php` | diubah, +53 |

## 2. Verifikasi atas Verifikasi

| Klaim | Cara verifikasi | Hasil |
| --- | --- | --- |
| Gerbang makro `OK (395 tests, 1443 assertions)` | menjalankan `vendor/bin/phpunit --no-coverage` ulang | identik, exit 0, tanpa skip/warning |
| Tidak ada suppression atau assertion dihapus | memindai diff untuk `@ts-ignore`, `eslint-disable`, `->skip(`, `markTestSkipped` | nol temuan |
| Batas CON-004 utuh | `git diff --name-status` seluruh rentang | nol migrasi, route, atau Config berubah |
| Tidak ada perubahan supply-chain | memeriksa manifest dependensi | nol perubahan |
| `[SPEC-01]` escaping ganda | skrip sementara lewat `db_connect('inbox')` + SQL nyata | terbukti (lihat bagian 4) |
| `[CORR-02]` seeder gagal senyap | membaca `BaseBuilder::batchExecute()` dan `BaseConnection:830-842` | tidak terbukti, temuan diturunkan |

## 3. Axis A — Standards & Security

- **[REQUIRED] [CORR-01] Pengaman putaran dapat dilepas oleh putaran yang sudah kedaluwarsa**
  - `putaranDaftarBerjalan` disetel `true` tanpa syarat dan dilepas tanpa syarat oleh `.finally()`.
    Putaran lama yang selesai lebih dulu melepas flag milik putaran baru, sehingga tick 6 detik
    berikutnya memulai putaran ketiga secara bersamaan.
  - Lokasi: `app/Views/inbox/index.php` (1008-1036, 1041-1058, 2553-2556).
  - Remedy: token/penghitung per putaran; hanya lepas bila putaran yang selesai masih yang terbaru.
- **[OPTIONAL] [CORR-02] `SeedFase1ePerf` mencetak jumlah yang dicoba, bukan yang tertulis**
  - Nilai balik `insertBatch()` tidak diperiksa, tetapi `inbox.DBDebug = true` membuat kegagalan
    melempar `DatabaseException`, jadi tidak senyap. Sisa temuan: verifikasi sebaiknya lewat
    pembacaan ulang `countAllResults()`.
  - Lokasi: `app/Commands/SeedFase1ePerf.php` (159-164, 194-200, 239-247).
- **[OPTIONAL] [CORR-03] Flag dapat tertinggal `true` selamanya** — `encodeURIComponent()` dapat melempar
  sinkron sebelum promise terpasang. Lokasi: `app/Views/inbox/index.php` (982-985, 1008-1012).
- **[OPTIONAL] [SEC-01] `q` tidak divalidasi sebagai UTF-8 valid** sebelum di-bind ke SQL.
  Lokasi: `app/Controllers/Inbox.php` (95-105, 221-234).
- **[OPTIONAL] [SEC-02] `ESCAPE '!'` di-hardcode** menduplikasi `$db->likeEscapeChar` yang dapat
  di-override. Lokasi: `app/Controllers/Inbox.php` (231).
- **[OPTIONAL] [PERF-01] Snippet dipotong untuk semua conversation**, bukan hanya halaman yang
  ditampilkan. Lokasi: `app/Controllers/Inbox.php` (171-184, 221-257).
- **[OPTIONAL] [PERF-02] Paginasi sisi klien memperbanyak pemindaian penuh**, sementara AC-016
  mengukur satu request. Laporan saja; menambah index adalah item Ask-first.
- **[NIT] [CC-01] Pemeriksaan kebenaran `is_internal` terduplikasi.**
  Lokasi: `app/Views/inbox/index.php` (894, 1794).
- **[NIT] [CC-02] `escapeHtmlInbox()` tidak aman untuk konteks atribut.** Seluruh pemanggil saat ini
  konteks isi-elemen, jadi tidak ada XSS. Lokasi: `app/Views/inbox/index.php` (736-741).
- **[NIT] [CC-03] Jaminan REQ-017b/AC-015d tidak punya test yang dapat dieksekusi**; test yang ada
  hanya mencocokkan substring sumber JS. Lokasi: `tests/session/OperationalInboxScreenTest.php` (130-146).
- **[NIT] [CORR-04] Komposisi fixture menyimpang dari ASSUMPTION-004** (1% vs 5% Internal Note,
  ~55 vs ~100 karakter). Lokasi: `app/Commands/SeedFase1ePerf.php` (265-282).
- **[FYI] [SEC-03] Kesimpulan awal Axis A dikoreksi:** predikat pencarian **tidak** aman.
  Rantai escaping benar tetapi belum lengkap; cacatnya nyata dan dibuktikan pada `[SPEC-01]`.
- **[FYI] [SEC-04]** Penjaga CLI seeder terbukti kokoh terhadap `--dbgroup` maupun override `.env`.
- **[FYI] [SEC-05]** Dugaan stored XSS lewat `json_encode()` diselidiki dan tidak dapat dieksploitasi.
- **[FYI] [SEC-06]** Tidak ada security header di seluruh proyek (pra-eksisting).
- **[FYI] [ARCH-01]** SQL mentah di controller konsisten dengan gaya rumah; memindahkannya akan
  melampaui batas CON-004.
- **[FYI] [ARCH-02]** Batas layer Service benar; tidak ada pelanggaran Dependency Rule.
- **[FYI] [PERF-03]** Tidak ada N+1 dan tidak ada fetch tak terbatas.
- **[FYI] [CORR-05]** `ROW_NUMBER()` mensyaratkan MariaDB >= 10.2 / MySQL >= 8.0 sebagai prasyarat
  deployment yang belum tercatat.
- **[FYI] [CORR-06]** Guard `$snippet === null` tidak terjangkau untuk baris hasil DB.
- **[FYI] [CORR-07]** Cakupan test AC-014(a)-(i) dan REQ-016c lengkap; jalur render baru bebas
  `innerHTML` dan `document.write`.

## 4. Axis B — Spec Compliance

Seluruh 12 pertanyaan kepatuhan dijawab PASS, kecuali satu cacat yang bersembunyi di dalamnya.

- **[REQUIRED] [SPEC-01] Pola LIKE ter-escape dua kali**
  - Pola dibangun `'%' . $db->escapeLikeString($q) . '%'` lalu di-bind; mesin bind meng-escape
    nilai itu sekali lagi (`Query::matchSimpleBinds`, `Query.php:305`).
  - Bukti pada aplikasi nyata (read-only):

    | Uji | Hasil |
    | --- | --- |
    | Pola apa adanya (`LIKE '%it\\\'s%' ESCAPE '!'`) | 0 |
    | Pola perbaikan (`LIKE '%it\'s%' ESCAPE '!'`) | 1 |
    | Pesan nyata yang memuat tanda petik | 2 |
    | Terjangkau kode apa adanya | 0 |
    | Terjangkau pola perbaikan | 2 |

  - Spec Reference: Spec 4.4, REQ-014, CL-016; plan TASK-024 poin 3(b) mensyaratkan predikat
    setara `LIKE '%q%'` untuk **setiap** `q`.
  - Lokasi: `app/Controllers/Inbox.php` (230-234).
  - Remedy: escape wildcard LIKE sekali, biarkan bind meng-escape string SQL sekali; tambahkan
    test dengan kata kunci bertanda petik.
- **[NIT] [SPEC-02] Kata kunci yang diulang saat putaran berjalan memulai putaran kedua** — mekanisme
  sama dengan CORR-01. Cakupan "putaran baru" ternyata **sudah tegas** di spec: REQ-017b membatasi
  larangan itu pada *"(polling 6 detik maupun pencarian dengan kata kunci yang sama)"*, dan REQ-017c
  justru mengizinkan kata kunci **baru** memulai putaran. Jadi putaran kedua itu bukan pelanggaran;
  yang melanggar adalah pelepasan pengaman oleh putaran yang dikalahkan (lihat CORR-01). Temuan ini
  ditutup oleh perbaikan CORR-01 tanpa perlu klarifikasi spec. Catatan tambahan: REQ-017b mewajibkan
  pengaman *"dilepas saat putaran selesai"* — rumusan itu justru **mensyaratkan** pelepasan oleh
  putaran yang bersangkutan, sehingga token putaran adalah bentuk yang patuh, bukan deviasi.
- **[NIT] [SPEC-03] Fixture AC-016 menyimpang dari ASSUMPTION-004.**
- **[NIT] [SPEC-04] Klausa "tanpa `q` tidak lebih lambat" tidak punya baseline pembanding.**
- **[NIT] [SPEC-05] AC-015(e) diperiksa dengan data live, bukan fixture yang ditentukan.**
- **[FYI] [SPEC-06] CON-004 tidak menyebut `app/Commands/SeedFase1ePerf.php`** padahal Spec 6
  mewajibkannya; celah kata-kata spec, bukan scope creep.
- **[FYI] [SPEC-07] Klausa "tanpa panggilan Gateway" pada AC-014(i) hanya terbukti struktural.**

## 5. Verdict

- Total temuan: **20 Standards, 7 Spec**.
- Worst Standards: `[CORR-01]`.
- Worst Spec: `[SPEC-01]`.
- Rekomendasi: **Proceed to Refactoring Plan** — jangan merge apa adanya.

Rencana perbaikan: `plan/plan-refactor-m3-fase1e-message-search-v1.0.md`.

## 6. Batas Verifikasi

- Laporan ini tidak menjalankan ulang pengukuran AC-016 (terhadap `aulia_inboxdb_perf`); artefak
  mentahnya berada di direktori sementara dan tidak tersimpan di repo.
- Nilai `.env` `database.inbox.database` tidak dibaca langsung; verifikasi read-only pada database
  konsisten dengan klaim walkthrough/handoff.
- Hitungan pesan live bergeser dari catatan sebelumnya (30 conversation / 152 pesan menjadi 153 pesan);
  ini drift dokumentasi snapshot, bukan temuan kode.
- Isu JS pada `[CORR-01]` dan `[CORR-03]` tidak dapat direproduksi lewat test otomatis proyek karena
  tidak ada test runner JS; keduanya diturunkan dari pembacaan kode.
