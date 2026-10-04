# Prosedur Rilis dan Rollback

Dokumen operasional untuk tim (Bahasa Indonesia). Perintah di bawah dijalankan oleh manusia
di server; agen AI tidak boleh menjalankannya terhadap lingkungan nyata (`.kilo/rules/sdlc.md` §4),
**kecuali** user secara eksplisit menyetujui agen AI menjalankannya untuk permintaan itu (lihat
`AGENTS.md` §3). Persetujuan hanya berlaku untuk permintaan saat itu, tidak otomatis berlaku untuk
sesi/rilis berikutnya.

Dasar dokumen ini: isi repositori (`.env.example`, `.htaccess.example`, `migrate.bat`,
`app/Database/Migrations`) dan `docs/sesi/2026-10-01-produksi-aulia3-inbox-tipe-pesan.md`.
Hal yang belum terverifikasi ada di bagian terakhir.

## 1. Gambaran lingkungan

| Hal | Dev | Produksi |
|---|---|---|
| `CI_ENVIRONMENT` | `development` | `production` |
| `RewriteBase` di `.htaccess` | `/aulia-app/` | `/aulia/` |
| `app.baseURL` di `.env` | sesuai URL dev, diakhiri `/` | sesuai URL produksi, diakhiri `/` |

- `.env` dan `.htaccess` **tidak dilacak Git**; jangan ditimpa saat `git pull`.
- Dua database: `default` (`aulia_kasirdb`) dan `inbox` (`aulia_inboxdb`).
- Menurut catatan sesi 2026-10-01, produksi terdiri dari **AULIA-SERVER2** (POS + `aulia_inboxdb`)
  dan **aulia3** (Evolution + adapter gateway). Konfirmasi ulang sebelum mengandalkan nama ini.

## 2. Checklist pra-rilis

- [ ] `vendor/bin/phpunit` hijau (laporkan hasil nyata, jangan perkiraan).
- [ ] Review selesai dan Gate 3 disetujui.
- [ ] `git status` bersih; commit dan tag rilis sudah dibuat (mis. `v2.5`).
- [ ] Migrasi baru dibaca dan `down()`-nya dipahami (lihat bagian 5).
- [ ] Perubahan aturan bisnis sudah tercatat di `docs/CHANGELOG.md`.
- [ ] Jika menyentuh kontrak POS <-> Gateway: sisi Gateway sudah disiapkan lebih dulu atau bersamaan.
- [ ] Waktu rilis di luar jam ramai kasir.

## 3. Backup (wajib sebelum migrasi)

Dump **kedua** database, simpan di luar folder aplikasi, lalu catat lokasinya.

    mysqldump -u <user> -p --single-transaction --routines aulia_kasirdb > <folder_backup>\aulia_kasirdb_YYYYMMDD.sql
    mysqldump -u <user> -p --single-transaction --routines aulia_inboxdb > <folder_backup>\aulia_inboxdb_YYYYMMDD.sql

Backup yang belum pernah diuji restore belum bisa dianggap backup. Lakukan restore rehearsal ke
database sementara (bukan database produksi) sekurang-kurangnya untuk rilis yang mengubah skema.

## 4. Langkah rilis

1. Catat versi saat ini: `git rev-parse --short HEAD` (untuk rollback).
2. Ambil kode: `git fetch --tags` lalu `git pull --ff-only`.
3. Jika `composer.lock` berubah: `composer install --no-dev --optimize-autoloader`.
4. Jalankan migrasi: `php spark migrate` (atau `migrate.bat` di Windows).
5. Cek hasil: `php spark migrate:status`. Semua migrasi baru harus berstatus sudah dijalankan,
   untuk grup `default` maupun `inbox`.
6. Cek `writable/logs` untuk error baru setelah rilis.

## 5. Smoke test pasca-rilis

Jangan membuat transaksi uji di produksi kecuali ada data uji yang disepakati; transaksi
adalah data keuangan.

- [ ] Login berhasil dan halaman Kasir terbuka.
- [ ] Halaman Transaksi dan Tagihan terbuka; angka sebuah transaksi lama tidak berubah.
- [ ] Laporan dan Kas terbuka tanpa error.
- [ ] Inbox terbuka dan badge status gateway wajar (bukan `degraded`/terputus tanpa sebab).
- [ ] Cetak struk hanya diuji bila perubahan menyentuh `Cetak`/printer.
- [ ] Tidak ada error baru di `writable/logs`.

## 6. Rollback

Pilih berdasarkan apa yang berubah.

| Situasi | Tindakan |
|---|---|
| Hanya kode berubah, tanpa migrasi | Kembali ke commit/tag sebelumnya (catatan di langkah rilis 1). Perlu persetujuan eksplisit karena operasi Git destruktif (`AGENTS.md` §11). |
| Ada migrasi yang hanya **menambah** kolom/tabel kosong | Kembalikan kode; kolom tambahan biasanya aman dibiarkan. |
| Ada migrasi yang mengubah/menghapus data atau kolom berisi data | **Restore dari dump** bagian 3. Jangan mengandalkan `php spark migrate:rollback`, karena `down()` yang menghapus kolom ikut membuang datanya. |
| Kontrak POS <-> Gateway berubah | Rollback kedua sisi (POS dan Gateway) bersama-sama, jangan salah satu. |

Setelah rollback: ulangi smoke test bagian 5 dan catat kejadian di `docs/sesi/`.

## 7. Pasca-rilis

- [ ] Checkpoint sesi ditulis di `docs/sesi/` (salin `TEMPLATE.md`, tambahkan baris di `README.md`).
- [ ] `docs/CHANGELOG.md` diperbarui bila ada perubahan aturan bisnis.
- [ ] Lokasi backup dan versi sebelumnya dicatat di checkpoint.

## 8. Belum terverifikasi

- Apakah `php spark migrate` tanpa opsi tambahan menjalankan migrasi grup `inbox` (migrasi Inbox
  mendeklarasikan `$DBGroup = 'inbox'`). Pastikan lewat `php spark migrate:status` pada rilis pertama,
  lalu perbarui bagian ini.
- Web server produksi (`.htaccess` mengindikasikan Apache) dan cara me-restart layanan gateway;
  catatan sesi menyebut `restart-adapter.ps1` dan task `AuliaEvolution` di repositori gateway.
- Jadwal backup otomatis: catatan sesi 2026-10-01 menyebut belum ada untuk sebagian komponen gateway.
