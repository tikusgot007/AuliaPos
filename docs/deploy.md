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
- [ ] Jika menyentuh status baca WhatsApp (read receipt): instance gateway sudah
  `readreceipts: all` (cek via `chat/fetchPrivacySettings`; cara lengkap di
  `petunjuk-penggunaan.md` gateway bagian 4.1). Tanpa ini blue tick tidak muncul.
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

## 8. Rollback gateway WhatsApp `aulia7` → `aulia3`

Dipakai hanya bila gateway produksi di `aulia7` bermasalah dan tidak bisa dipulihkan di tempat
(mis. PC rusak). Sejak cutover 2026-10-09 (`docs/requirements/2026-10-09-cutover-gateway-aulia7.md`),
`aulia7` adalah gateway utama dan `aulia3` cadangan. Kontrak POS ↔ Gateway, port (`3000` adapter,
`8080` Evolution), dan instance `aulia-toko` sama di kedua PC, jadi tidak ada perubahan kode.

| Komponen | `aulia7` (aktif) | `aulia3` (cadangan) |
|---|---|---|
| Mekanisme | Windows Service WinSW | Scheduled Task |
| Gateway | `AuliaPosGatewayEvolution`, `AuliaPosGatewayAdapter` | `AuliaEvolution`, `AuliaAdapter` |
| Penjaga/monitor | `AuliaPosGatewayMonitor` (task) | `AuliaStackWatchdog`, `AuliaMonitor` (task) |
| Alamat | `192.168.1.68` | `AULIA3` |

Nilai di `aulia-server2` saat ini (dicek 2026-10-11):

- `\\aulia-server2\xampp\htdocs\aulia\.env`: `inbox.gatewayBaseUrl = 'http://192.168.1.68:3000'`
- `\\aulia-server2\xampp\apache\conf\extra\httpd-ssl.conf`:
  `ProxyPass "/realtime-ws" "ws://192.168.1.68:3000/realtime"`

(`\\aulia-server2\xampp` adalah folder `xampp` di server; dari PC dev tersedia sebagai drive `W:`.)

Prinsip: **hanya satu gateway aktif pada satu waktu.** `gateway_status` di CI4 hanya satu baris
(`id=1`), jadi dua gateway yang hidup bersamaan saling menimpa status. Bila keduanya tertaut ke
nomor yang sama, pesan juga bisa masuk dua kali. Karena itu `aulia7` dimatikan **lebih dulu**.

### 8.1 Langkah

1. **Matikan `aulia7` dulu** (PowerShell admin di `aulia7`):

        Disable-ScheduledTask -TaskName AuliaPosGatewayMonitor
        Stop-Service AuliaPosGatewayAdapter
        Stop-Service AuliaPosGatewayEvolution
        Set-Service AuliaPosGatewayAdapter -StartupType Disabled
        Set-Service AuliaPosGatewayEvolution -StartupType Disabled

   Service PostgreSQL `postgresql-aulia7` boleh dibiarkan hidup. Jangan uninstall apa pun, supaya
   `aulia7` bisa diaktifkan lagi dengan langkah kebalikannya.
2. **Hidupkan `aulia3`** (PowerShell admin di `aulia3`; dari PC lain RPC/`schtasks` ke `aulia3`
   sering gagal, jadi jalankan langsung di `aulia3`):

        Enable-ScheduledTask -TaskName AuliaEvolution
        Enable-ScheduledTask -TaskName AuliaAdapter
        Start-ScheduledTask  -TaskName AuliaEvolution
        # tunggu port 8080 LISTEN (netstat -ano | findstr :8080), lalu:
        Start-ScheduledTask  -TaskName AuliaAdapter
        Enable-ScheduledTask -TaskName AuliaStackWatchdog
        Enable-ScheduledTask -TaskName AuliaMonitor

3. **Cek koneksi WhatsApp di `aulia3`**: buka Evolution `http://AULIA3:8080` → instance `aulia-toko`
   harus `open` dengan nomor `6285155105633`. Bila tidak `open`, **pairing ulang** (scan QR dari HP
   toko). Kemungkinan besar ini diperlukan karena nomor sudah di-pairing ke `aulia7`; sesi lama di
   `aulia3` belum diverifikasi masih berlaku. Setelah pairing ulang, pastikan `readreceipts: all`
   (lihat checklist §2).
4. **Arahkan CI4 ke `aulia3`** (di `aulia-server2`): ubah `\\aulia-server2\xampp\htdocs\aulia\.env` menjadi
   `inbox.gatewayBaseUrl = 'http://AULIA3:3000'`. CI4 membaca `.env` per request, tidak perlu restart.
5. **Arahkan realtime ke `aulia3`**: ubah baris `ProxyPass "/realtime-ws"` di
   `\\aulia-server2\xampp\apache\conf\extra\httpd-ssl.conf` menjadi `ws://AULIA3:3000/realtime`, lalu restart
   Apache (XAMPP Control Panel → Apache Stop/Start).
6. **Verifikasi**:
   - `aulia3`: port `3000` dan `8080` LISTEN; task `AuliaEvolution`/`AuliaAdapter` Running.
   - `aulia7`: port `3000`/`8080` tidak LISTEN; kedua service Stopped + Disabled.
   - CI4: `SELECT status, last_heartbeat_at FROM aulia_inboxdb.gateway_status WHERE id=1` →
     `connected` dengan heartbeat baru (< 30 detik).
   - Inbox POS: badge gateway "Terhubung"; kirim satu pesan ke nomor sendiri dan terima satu pesan
     masuk; realtime (pesan baru muncul tanpa refresh) jalan.
7. **Catat kejadian** di `docs/sesi/` dan `docs/CHANGELOG.md`.

### 8.2 Yang perlu diketahui

- **Data tidak hilang**: riwayat pesan tersimpan di `aulia_inboxdb` (CI4), bukan di gateway. Pesan
  yang masuk ke WhatsApp selama jeda tetap dikirim Evolution setelah tersambung lagi.
- **Media lama**: media yang diterima `aulia7` tersimpan di media store `aulia7`. Media yang sudah
  di-prefetch ke `inbox.mediaStoragePath` CI4 tetap bisa dibuka; yang belum, tidak bisa diambil dari
  `aulia3` (pesan Inbox menampilkan media tidak tersedia).
- **Kiriman yang sedang berjalan** (`outgoing_operations` `in_flight` di `aulia7`) tidak dikenal
  `aulia3`; kasir yang ragu sebaiknya mengecek WhatsApp sebelum mengirim ulang.
- **Kembali ke `aulia7`**: jalankan langkah yang sama dengan arah dibalik (matikan `aulia3`:
  disable `AuliaStackWatchdog`/`AuliaMonitor` dulu, lalu stop + disable `AuliaAdapter`/`AuliaEvolution`;
  hidupkan service `aulia7` + `AuliaPosGatewayMonitor`; kembalikan `.env` dan `ProxyPass` ke
  `192.168.1.68`).

### 8.3 Belum terverifikasi

- Apakah sesi WhatsApp `aulia3` masih berlaku atau wajib pairing ulang (langkah 3).
- Apakah `aulia-server2` bisa me-resolve nama `AULIA3` (dari PC dev hanya resolve ke alamat IPv6
  link-local). Bila tidak, pakai IP `aulia3` di langkah 4 dan 5.
- Apakah Apache di `aulia-server2` berjalan sebagai Windows Service atau dari XAMPP Control Panel.
- Status task di `aulia3` saat ini (query jarak jauh 2026-10-11 gagal: RPC tidak tersedia). Per
  catatan cutover, keempat task gateway berstatus Disabled, bukan dihapus.
- Prosedur ini belum pernah dilatih (rehearsal).

## 9. Belum terverifikasi

- **TERJAWAB (2026-10-13)**: `/migrasi-manual` (`MigrationRunner::latest()`) MENJALANKAN migrasi
  grup `inbox` dan mencatatnya di tabel `migrations` grup **default** (`aulia_kasirdb.migrations`,
  bukan `aulia_inboxdb.migrations` — tabel itu tidak ada). Dibuktikan saat deploy `007a9a5`:
  `2026-10-13-000002 CreateMaraSuggestions` tercatat di `aulia_kasirdb.migrations` dan tabel
  `mara_suggestions` terkonfirmasi ada di `aulia_inboxdb`. **Konsekuensi**: `php spark migrate:status`
  (tanpa `-g`) menampilkan migrasi Inbox sebagai "sudah jalan" karena membaca tabel yang sama;
  sedangkan `php spark migrate:status -g inbox` MENYESATKAN (menganggap hampir semua migrasi Inbox
  belum jalan) — JANGAN pakai `-g inbox` untuk memutuskan migrasi di produksi.
- `php spark` **berjalan** di AULIA-SERVER2 (via `\\aulia-server2\xampp\php\php.exe`), walau ada
  warning `Unable to load dynamic library 'curl'` yang tidak fatal. Meski begitu, prosedur resmi
  tetap memakai `/migrasi-manual` (pilihan user 2026-10-13).
- **Jalur DB produksi harus eksplisit host**: menjalankan `mysql.exe`/`php.exe` produksi dari PC dev
  dengan `localhost` akan menyambung ke MySQL **PC dev**, bukan AULIA-SERVER2. Gunakan
  `-h 192.168.1.10` (atau `--defaults-extra-file` yang memuat `host=192.168.1.10`) dan verifikasi
  `SELECT @@hostname` = `AULIA-SERVER2` sebelum mempercayai hasilnya.
- Web server produksi (`.htaccess` mengindikasikan Apache) dan cara me-restart layanan gateway;
  catatan sesi menyebut `restart-adapter.ps1` dan task `AuliaEvolution` di repositori gateway.
- Jadwal backup otomatis: catatan sesi 2026-10-01 menyebut belum ada untuk sebagian komponen gateway.
