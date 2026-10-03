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
