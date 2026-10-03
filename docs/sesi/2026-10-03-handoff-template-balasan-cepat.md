# Checkpoint Sesi

- **Tanggal**: 2026-10-03
- **Status**: blocked (hold — menunggu Gate 2 + implementasi oleh tim lain)
- **Repo / branch**: AuliaPos, `v2.4` (HEAD `6084e32`, bersih)

## Selesai

- **Riset fitur WhatsApp Web vs Inbox AuliaPos** (tanpa kode): daftar fitur
  ADA/TIDAK ADA/SEBAGIAN, dibedakan mana yang sengaja di luar cakupan (ada
  alasan desain tercatat di `docs/requirements/`) vs belum pernah dibahas.
  Hasil disampaikan di chat, tidak disimpan sebagai dokumen terpisah.
- **Requirement** `docs/requirements/2026-10-03-template-balasan-cepat.md`
  — **Status: disetujui (Gate 1 lolos)**. 12 acceptance criteria (AC-1..AC-12)
  mencakup CRUD admin dan pemakaian kasir di composer Inbox.
- **Desain teknis** `docs/design/2026-10-03-template-balasan-cepat.md`
  — **Status: draft, BELUM disetujui (Gate 2 belum lolos)**. Berisi:
  - Call chain existing yang diverifikasi (pola CRUD `Kategori`, pola upload
    `FotoProfilService`/`Profil::foto()`, pipeline kirim `Inbox::kirim()`/
    `kirimMedia()`, pola fetch-list JS existing).
  - 2 keputusan desain dengan rekomendasi: tabel `balasan_template` di
    `database.default` (bukan `inbox`), dan modal (bukan dropdown) untuk
    pemilihan template.
  - Tabel 10 file yang akan dibuat/diubah, dengan alasan per file.
  - Rencana test (7 test baru, unit+feature+JS, dipetakan ke tiap AC).
  - 3 item "belum terverifikasi" berdampak rendah (tidak menghalangi Gate 2).

## Keputusan penting

- **Hold setelah desain**: user meminta implementasi dikerjakan tim lain,
  bukan dilanjutkan di sesi ini. Fase 3 (Implementation) **belum dimulai**
  — tidak ada kode produksi yang ditulis/diubah sepanjang sesi ini, sesuai
  `.kilo/rules/sdlc.md` §4 ("Do not write production code during phases 1-2").
- Opsi A dipilih untuk lokasi tabel (`database.default`, pola `KategoriModel`)
  — alasan: konsisten dengan preseden "pengaturan POS dikelola admin" vs
  `aulia_inboxdb` yang murni data arsip WhatsApp. Lihat §3 Option set A di
  dokumen desain untuk trade-off lengkap.
- Opsi A dipilih untuk UI pemilihan (modal) — alasan: AC-12 butuh ruang
  untuk pesan "belum ada template", modal Handoff sudah jadi preseden UI
  serupa di Inbox.

## Tersisa

**Gate 2 (persetujuan desain) belum dilakukan** — ini yang harus terjadi
LEBIH DULU sebelum tim penerima mulai menulis kode, sesuai
`.kilo/rules/sdlc.md` §3. Setelah Gate 2 disetujui, urutan kerja tim
penerima:

1. Migrasi `app/Database/Migrations/2026-10-03-000001_CreateBalasanTemplate.php`
   (lihat desain §4 baris 1 untuk skema kolom).
2. Model `app/Models/BalasanTemplateModel.php` (clone `KategoriModel.php`).
3. Service `app/Libraries/BalasanTemplateImageService.php` (clone
   `FotoProfilService.php`).
4. Controller `app/Controllers/BalasanTemplate.php` (clone `Kategori.php`,
   6 method CRUD + 1 method streaming gambar).
5. View `app/Views/balasan_template/{index,tambah,edit}.php` (clone
   `app/Views/kategori/*.php`).
6. Routes + `AuthFilter.php` whitelist + menu sidebar (`main.php`).
7. `Inbox.php`: endpoint baru `GET /inbox/api/balasan-template` (read-only,
   TIDAK mengubah `kirim()`/`kirimMedia()` yang sudah ada).
8. `inbox/index.php`: tombol + modal + JS kecil yang reuse
   `antrianMediaBalasan` (dari PR #47) — lihat desain §4 baris terakhir.
9. Tulis 7 test di desain §6, jalankan, laporkan hasil nyata (jangan klaim
   lulus tanpa run, sesuai AGENTS.md §10/§18).
10. Review diri terhadap checklist AGENTS.md §20 sebelum lapor selesai.

Juga **tambahkan baris baru ke `docs/TODO.md`** untuk melacak status ini
sampai Gate 2 disetujui dan implementasi berjalan (lihat bagian bawah
checkpoint ini — sudah saya tambahkan di sesi ini).

## Belum diverifikasi / risiko

Disalin dari desain §8 (tidak menghalangi Gate 2, tapi perlu disadari
implementer):

1. Duplikasi kecil logic streaming gambar (`Profil::foto()` vs
   `BalasanTemplate::foto()`) — condong dibiarkan terpisah (YAGNI), bisa
   diputuskan ulang saat implementasi tanpa reopen Gate 2.
2. Posisi persis item menu di submenu "Administrasi" — kosmetik.
3. Format response JSON `gambar_url` — proposal sudah ada (server membangun
   URL penuh), tidak perlu keputusan ulang.

Risiko tambahan dari desain §7:
- `app/Views/inbox/index.php` sudah 3609 baris — kalau JS template makin
  besar, pecah ke file baru (`public/assets/js/inbox-template.js`),
  mengikuti preseden `inbox-thread.js`.
- Sidebar "Administrasi" makin ramai — mitigasi: tetap nested di submenu
  yang sudah ada, bukan grup baru.

## Titik masuk sesi berikutnya

- **Baca**: file ini, lalu urutan: `docs/requirements/2026-10-03-template-balasan-cepat.md`
  (konteks bisnis + 12 AC) → `docs/design/2026-10-03-template-balasan-cepat.md`
  (call chain + file yang diubah + test plan).
- **Keputusan yang dibutuhkan dari user SEBELUM kode ditulis**: approve Gate 2
  (centang `docs/design/2026-10-03-template-balasan-cepat.md` §9 "Approval").
  Tanpa itu, tim penerima TIDAK BOLEH mulai Phase 3 sesuai aturan SDLC
  proyek ini.
- **Jalankan** (setelah Gate 2 disetujui dan implementasi berjalan):
  `composer test` (unit) dan `composer test:feature` (butuh `aulia_inboxdb_test`
  — dan tabel `balasan_template` MEMANG ada di `aulia_inboxdb`, lihat koreksi
  review di bawah); `node tests/js/inbox-template.test.js`.

## Review implementasi (branch `claude/template-balasan-cepat`, 2026-10-03)

Implementasi oleh tim lain di branch `claude/template-balasan-cepat`
(basis `920cc4d`, 1 commit `c9e3dec`, 20 file). Review oleh sesi ini
menemukan **1 bug yang memblokir AC-9** + beberapa catatan. **Belum ada PR.**

### Bug: kasir tidak bisa memuat gambar template (blokir AC-9)

- **Gejala**: kasir memilih template bergambar di composer, gambar gagal
  dimuat (toast error / gambar rusak). Teks (jika ada) tetap masuk.
- **Root cause**: `app/Config/Routes.php` mendaftarkan
  `/balasan-template/foto/(:any)` dengan filter `auth`, dan
  `app/Filters/AuthFilter.php:70` menambahkan `'balasan-template'` ke
  `$adminRoutes`. Karena `AuthFilter.php:74` memblokir SEMUA URI berprefix
  `balasan-template/`, route foto ikut diblokir untuk kasir.
- **Alur rusak**: `bukaModalTemplate` → `pilihTemplate()` →
  `fetch(template.gambar_url)` (`public/assets/js/inbox-template.js:76`),
  `gambar_url` dibangun `base_url('/balasan-template/foto/' . filename)`
  (`app/Controllers/Inbox.php`, method `apiBalasanTemplate`) → untuk kasir
  request ini di-redirect ke `/kasir` oleh AuthFilter → `res.blob()`
  menerima HTML halaman, bukan gambar.
- **Perbaikan yang disarankan**: pindahkan route foto ke prefix yang TIDAK
  ada di `$adminRoutes` (pola same seperti `/foto-profil/` milik
  `Profil::foto()`, yang sengaja di prefix terpisah). Mis.
  `/foto-template/(:any)` atau `/inbox/api/balasan-template/foto/(:any)`
  (prefix `/inbox` bukan admin-only) + sesuaikan `gambar_url` yang dibangun
  di `Inbox::apiBalasanTemplate()`. JANGAN cukup hapus `balasan-template`
  dari `$adminRoutes` — itu akan membuka CRUD untuk kasir (langgar AC-7).
- **Test yang hilang**: tidak ada test yang mengakses route foto sebagai
  kasir. `BalasanTemplateCrudTest::testKasirIsBlockedFromCrudRoutes` hanya
  menguji blokir CRUD. Tambahkan test: kasir GET route foto → 200 (bukan
  redirect `/kasir`).

### Catatan lain

- **Deviasi desain tidak tercatat di dokumen desain**: tabel
  `balasan_template` ditaruh di `database.inbox`/`aulia_inboxdb` (kebalikan
  rekomendasi desain §3 Option A = `database.default`). Deviasi dicatat di
  `docs/TODO.md` TODO-R1 + komentar kode ("Option B chosen by the user"),
  tetapi `docs/design/2026-10-03-template-balasan-cepat.md` §3/§9 masih
  menulis rekomendasi Option A dan status draft — **dokumen desain belum
  diupdate** untuk mencerminkan keputusan final. Perlu diselaraskan.
- **Koreksi**: checkpoint ini sebelumnya menduga `balasan_template` ikut
  `aulia_kasirdb`; implementasi final menaruhnya di `aulia_inboxdb`, jadi
  test feature memang butuh `aulia_inboxdb_test`.
- **Test feature belum pernah dijalankan**: klaim 4 test feature baru
  (`BalasanTemplateModelTest`, `BalasanTemplateImageServiceTest`,
  `BalasanTemplateCrudTest`, `InboxTemplateListTest`) belum diverifikasi
  (tidak ada `aulia_inboxdb_test`). Unit + JS 6/6 diklaim hijau. Sesuai
  AGENTS.md §10, AC-1..AC-7 lewat feature belum dianggap selesai sampai
  jalan.
- **Migrasi & konvensi**: migrasi pakai `DATETIME` tanpa default (baseline
  `kategori` pakai `TIMESTAMP DEFAULT current_timestamp()`); model CI4
  mengisi timestamp sendiri jadi aman, hanya beda konvensi. `hapus($id)`
  pakai GET (konsisten pola `Kategori`, tapi GET untuk mutasi kurang ideal).

## Review perbaikan bug (branch `claude/template-balasan-cepat`, commit `ff60983`, 2026-10-03)

Commit `ff60983` memperbaiki bug route foto (lihat bagian di atas) DAN
menjalankan `composer test` (40/40 lulus, diverifikasi ulang di sesi ini)
+ `node tests/js/inbox-template.test.js` (6/6 lulus, diverifikasi ulang).
**Dokumen desain sudah diselaraskan**: §3 mencatat Option B sebagai
implementasi final, §9 Gate 2 sudah dicentang approved retroaktif. Baik.

Di sesi ini saya juga menjalankan `composer test:feature` (MySQL + client
`C:\xampp\mysql\bin\mysql.exe` ternyata TERSEDIA di lingkungan ini,
`aulia_inboxdb_test` sudah ada — klaim TODO-R1 "tidak ada MySQL terinstal"
tidak akurat untuk lingkungan ini). Setelah migrasi
`2026-10-03-000001_CreateBalasanTemplate.php` dijalankan manual ke
`aulia_inboxdb_test` (tidak otomatis lewat `composer test:feature`, perlu
`php spark migrate --group inbox -n` dengan `CI_ENVIRONMENT=testing`, atau
script PHP yang memanggil `$migration->up()` langsung), ditemukan **3 isu
tambahan**, 1 di antaranya bug fungsional nyata:

### Bug nyata (pre-existing pattern, bukan regresi branch ini): validasi ukuran upload tidak pernah berfungsi untuk file besar

- **Lokasi**: `app/Libraries/BalasanTemplateImageService.php:50` —
  `if ($file->getSizeByUnit('kb') > $maxKb)`.
- **Root cause**: `UploadedFile::getSizeByUnit('kb')` (CI4 versi di
  `vendor/`) memanggil `getSizeByBinaryUnit()` yang memformat hasilnya
  lewat `number_format($size, 3)` — mengembalikan **string berkoma ribuan**
  (mis. `"15,361.000"`), bukan angka murni. Dibandingkan `>` dengan int
  `$maxKb` (mis. `15360`), PHP type-juggling memotong string di karakter
  non-numerik pertama (`,`) sehingga `"15,361.000" > 15360` dievaluasi
  `15 > 15360` = **false**. Akibatnya file yang melebihi batas upload
  **lolos validasi**, bukan ditolak.
- **Dikonfirmasi lewat eksperimen langsung**:
  `var_dump($file->getSizeByUnit('kb'))` → `string(10) "15,361.000"`;
  `$file->getSizeByUnit('kb') > 15360` → `bool(false)`.
- **PENTING — ini BUKAN bug baru dari branch TODO-R1**: pola yang identik
  sudah ada sebelumnya di `app/Libraries/FotoProfilService.php:63`
  (`$file->getSizeByUnit('kb') > self::MAX_SIZE_KB`, batas 2048 KB) —
  kode template mengkloning API yang salah dari situ. Jadi upload foto
  profil pun kemungkinan punya bug validasi ukuran yang sama (di luar
  cakupan TODO-R1, tapi layak jadi temuan terpisah).
- **Pola yang benar** (sudah ada di repo): `Inbox.php:1303` —
  `$file->getSize() > $maxBytes` (bandingkan byte mentah, bukan
  `getSizeByUnit()`).
- **Proposed fix** (untuk `BalasanTemplateImageService`, TODO-R1): ganti
  jadi `$file->getSize() > ($maxKb * 1024)`, pola sama dengan `Inbox.php`.
  `FotoProfilService` sebaiknya dibuatkan temuan TODO terpisah (di luar
  TODO-R1) karena itu bug pre-existing yang tidak disentuh branch ini.
- **Test yang gagal**: `BalasanTemplateImageServiceTest::testSimpanRejectsOversizedFile`
  (lulus harusnya karena assert gagal, tapi assert `assertFalse($hasil['success'])`
  gagal karena `success` ternyata `true` — file besar lolos).

### Isu test, bukan bug fungsional: path separator Windows

- `BalasanTemplateImageServiceTest::testResolvePathUntukDitampilkanReturnsRealPathForKnownFile`
  gagal karena assert memakai `/` literal
  (`$this->service->getDir() . '/' . $hasil['filename']`), sedangkan kode
  (`BalasanTemplateImageService.php` method `resolvePathUntukDitampilkan`)
  pakai `DIRECTORY_SEPARATOR` yang di Windows jadi `\`. Pola yang sama
  (`DIRECTORY_SEPARATOR`) sudah ada sebelumnya di `FotoProfilService.php`.
  Bukan bug fungsional (`is_file()` Windows menerima kedua separator),
  murni test ditulis/divalidasi di lingkungan non-Windows. Kemungkinan
  muncul lagi di CI/lingkungan Linux kalau dijalankan di sana tanpa
  masalah — HANYA gagal di Windows seperti sesi ini.

### Catatan lingkungan (bukan bug kode)

- `BalasanTemplateCrudTest::testAdminCanCreateEditAndDeleteATextOnlyTemplate`,
  `testCreateIsRejectedWhenBothTeksAndGambarAreEmpty`, dan
  `testIndexListsTemplatesSortedByNama` gagal di sesi ini dengan error
  "no such table: db_balasan_template" / "no such table: db_users" —
  ini karena test feature di repo ini (semua file, bukan cuma yang baru)
  TIDAK memakai `DatabaseTestTrait`, sehingga `is_unique[balasan_template.nama]`
  (validasi rule bawaan CI4, `Rules::prepareUniqueQuery()`) dan
  `UserModel::find()` (dipanggil `main.php:1113` untuk foto profil header)
  query ke `defaultGroup` (SQLite in-memory `tests`, kosong) alih-alih ke
  `database.inbox` tempat tabel sungguhan berada. **Ini kemungkinan
  masalah umum test feature di repo ini** (pola sama berlaku untuk
  `is_unique[kategori.nama]` di `Kategori.php` kalau pernah diuji feature
  test serupa — belum diverifikasi), bukan spesifik ke TODO-R1. Tidak
  menandakan bug fungsional di endpoint CRUD produksi (endpoint tetap
  jalan normal saat `defaultGroup` = `database.default`/`aulia_kasirdb`
  sungguhan, yang punya tabel `users`; hanya tabel `balasan_template` yang
  TIDAK ada di `aulia_kasirdb` — lihat poin berikutnya).
- **Implikasi serius yang perlu diverifikasi**: karena
  `is_unique[balasan_template.nama]` (tanpa prefix dbGroup) di
  `BalasanTemplate.php` method `simpan()`/`update()` akan query ke
  **`defaultGroup`** (`database.default` = `aulia_kasirdb` di lingkungan
  NYATA, bukan SQLite test) saat dijalankan di luar test harness, sedangkan
  tabel `balasan_template` ada di **`database.inbox`** (`aulia_inboxdb`) —
  **rule `is_unique` ini kemungkinan menunjuk ke database yang SALAH juga
  di produksi**, bukan cuma di test. Builder validasi (`Rules.php:210`,
  `Database::connect($dbGroup)` dengan `$dbGroup = null`) connect ke
  `defaultGroup`, mencari tabel `balasan_template` di `aulia_kasirdb`
  (yang tidak punya tabel itu) → kemungkinan error 500 alih-alih validasi
  normal saat admin submit form Tambah/Edit Template di browser sungguhan.
  **BELUM diverifikasi langsung ke browser** (hanya dianalisis dari kode +
  perilaku `Rules::prepareUniqueQuery()`) — perlu dicoba manual: isi nama
  yang sudah ada di `aulia_inboxdb_test`, kalau errornya code 500
  "table doesn't exist" (bukan redirect dengan pesan "sudah dipakai"),
  bug ini terkonfirmasi nyata di produksi. **Proposed fix**: ubah rule jadi
  `is_unique[inbox.balasan_template.nama]` (format `dbGroup.table.field`,
  didukung native oleh `Rules::prepareUniqueQuery()`).

### Ringkasan tindak lanjut untuk tim pengembang

1. **Prioritas tinggi, perlu verifikasi manual segera**: cek apakah
   `is_unique[balasan_template.nama]` benar-benar error di luar test
   harness (kemungkinan besar error 500 saat submit form admin, karena
   `defaultGroup` tidak attach tabel yang benar). Kalau terkonfirmasi,
   perbaiki ke `is_unique[inbox.balasan_template.nama,...]` di kedua rule
   (`simpan()` dan `update()`, `BalasanTemplate.php`).
2. **Prioritas sedang**: perbaiki `BalasanTemplateImageService::simpan()`
   agar validasi ukuran berfungsi (`getSize()` byte murni, bukan
   `getSizeByUnit('kb')`).
3. **Prioritas rendah/opsional**: `FotoProfilService.php` punya bug
   `getSizeByUnit` yang identik — di luar cakupan TODO-R1, usul jadi TODO
   terpisah kalau user setuju.
4. Perbaiki ekspektasi test Windows (`DIRECTORY_SEPARATOR`) di
   `BalasanTemplateImageServiceTest::testResolvePathUntukDitampilkanReturnsRealPathForKnownFile`
   kalau tim menjalankan test di Windows.
5. Setelah 1-2 diperbaiki dan test feature lulus (gunakan
   `aulia_inboxdb_test` + migrasi dijalankan lebih dulu), verifikasi
   manual di browser sebagai admin DAN kasir, baru buka PR.
