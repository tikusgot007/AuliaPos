# TODO / Backlog Terpusat

> Baca file ini di **AWAL setiap sesi** sebelum bekerja. Perbarui setiap kali ada
> temuan baru, atau item selesai (lihat aturan penghapusan di bawah). Format:
> `- [ ] **ID** ringkas — prioritas — ref`.
>
> Saat menutup item: usulkan ke user untuk menghapus barisnya, lalu **tunggu
> persetujuan eksplisit** sebelum menghapusnya. Jangan dihapus sepihak. Setelah
> disetujui, hapus baris itu dan sebut **ID**-nya di pesan commit.
> Jangan membuat daftar TODO kedua di tempat lain; checkpoint hanya menunjuk ke sini.

## Prioritas Tinggi

- [ ] **TODO-O1** Backup terjadwal: `pg_dump` PostgreSQL Evolution + SQLite antrean + `media/` + `.env` — tinggi — ref `docs/sesi/2026-10-01-produksi-aulia3-inbox-tipe-pesan.md`
- [ ] **TODO-O2** Sambungkan pemeliharaan yang sudah ada tapi belum pernah dipanggil: `mediaStore.prune()` & `quotedStore.prune()` (satu-satunya pemanggil lama hanya `pruneTerminal()` di `outgoingOperationService.runStartupRecovery()`, dipanggil `src/app/evolution.js:41` saat start). Akibatnya `MEDIA_RETENTION_DAYS=7` & TTL kutipan tidak berjalan. Sekaligus buat prune untuk `incoming_queue` (belum ada di `src/store/incomingBuffer.js`) untuk membuang baris `completed` tua — tinggi — ref `docs/sesi/2026-10-01-produksi-aulia3-inbox-tipe-pesan.md`
- [ ] **TODO-O3** Rotasi/trim log (`adapter.log` ~49 MB/tahun, `evolution.log` ~620 MB/tahun) + task terjadwal prune/backup (kini belum ada) — tinggi — ref `docs/sesi/2026-10-01-produksi-aulia3-inbox-tipe-pesan.md`

## Prioritas Sedang

- [ ] **TODO-T3** Verifikasi jalur merge arsip (SQLite) saat arsip **terisi** untuk S2 (`laporan_pembayaran`), S3b (`transaksi`), dan S4 (`periode`). Saat ini arsip kosong sehingga hanya jalur live yang teruji; bentuk SQL arsip S4 sudah divalidasi lewat PDO — sedang — ref `docs/sesi/2026-10-02-server-side-periode-laporan.md`
- [ ] **TODO-T4** Tombol Print pada tabel server-side (item-harian, laporan-pembayaran, tagihan, transaksi, periode) hanya mencetak halaman aktif; pertimbangkan cetak seluruh hasil terfilter — sedang — ref `docs/sesi/2026-10-02-server-side-periode-laporan.md`
- [ ] **TODO-T5** Feature test ber-DB (`tests/feature`, `phpunit.feature.xml`) belum terhubung ke `composer test`/CI; saat ini hanya dijalankan manual. Pertimbangkan script composer terpisah (mis. `composer test:feature`) yang butuh `aulia_inboxdb_test` — sedang — ref `docs/sesi/2026-10-02-bug-nama-conversation-wa-web.md`
- [ ] **TODO-O4** (opsional) `VACUUM` + pantau ukuran disk PostgreSQL Evolution — sedang
- [ ] **TODO-I1** Paket instalasi gateway untuk PC baru (`C:\Projects\evolution-gateway\installer\`) — **implementasi + uji jalan nyata di PC dev selesai (2026-10-02), 7 cek lokal lulus**, kode di `origin/evolution` commit **`ad26306`** (gunakan `-AdapterRef ad263061a08a2d039da64042d45da0acdbfd1a86`). Terverifikasi di PC dev: install resume, npm ci, patch view-once, `setup-env`, service PostgreSQL baru, `prisma migrate` (57 migrasi), start Evolution+adapter, firewall, instance+webhook, `status`, `install-summary.txt`. Sisa: verifikasi di PC benar-benar baru/VM dari nol, uji `stop.ps1`, uji negatif webhook tanpa secret; dan bersihkan/rotasi secret yang sempat bocor ke log uji — ref `docs/requirements/2026-10-02-paket-instalasi-gateway-pc-baru.md`, `docs/design/2026-10-02-paket-instalasi-gateway-pc-baru.md`
- [ ] **TODO-F2** `phone` di `gateway_status` NULL setelah restart adapter (hanya terisi dari `CONNECTION_UPDATE`) — sedang — ref `docs/sesi/2026-10-01-produksi-aulia3-inbox-tipe-pesan.md`
- [ ] **TODO-F3** Terapkan ulang patch Evolution (`PATCH-ADAPTER (2026-10-01)` di `whatsapp.baileys.service.ts`) setiap kali Evolution di-upgrade; prosedur `C:\Projects\evolution-gateway\docs\evolution-viewonce-patch.md` — sedang
- [ ] **TODO-F6** [repo evolution-gateway, catatan temuan] Forward **KELUAR** yang disinkronkan dari WA Web/HP (`fromMe:true`, staf meneruskan langsung dari HP, bukan lewat tombol Teruskan POS) **tidak ditandai** `is_forwarded` di jalur webhook: `normalize.js` `extractForwardFlag()` sengaja dibatasi `!fromMe` (cakupan TODO-F5 = masuk saja), sehingga baris tersimpan `is_forwarded=0` dan tidak berlabel "Diteruskan" di Inbox. Bukan regresi (perilaku lama memang begitu), tapi tidak konsisten dengan jalur kirim POS yang menandai forward keluar (`Inbox.php:1515`). **Ditemukan** 2026-10-02 saat investigasi TODO-F5 (payload nyata `evolution.log` baris 7925-7969: `fromMe:true` + `forwardingScore:1`). Perlu: putuskan apakah forward keluar tersinkron perlu ditandai juga (hapus batas `!fromMe` di adapter + pastikan CI4 tidak menimpa penanda jalur POS) atau memang dibiarkan — sedang

## Higiene

- [ ] **TODO-H1** Bersihkan data uji Inbox (2 percakapan uji: `628563324637@s.whatsapp.net`, `6281913500707@s.whatsapp.net`) — higiene — ref `docs/sesi/2026-10-01-produksi-aulia3-inbox-tipe-pesan.md`. Diverifikasi 2026-10-02 di produksi: hanya ada 2 percakapan itu (`id` 4 & 5), dan 2 baris `unsupported` berteks gaya lama (`[Pelanggan mengirim video singkat …]`, `[Pelanggan mengirim pesan lihat-sekali …]`) keduanya milik percakapan `id` 4. Menghapus 2 percakapan ini otomatis menghapus kedua baris tersebut (dulu dicatat sebagai TODO-H2, sudah dihapus karena tumpang-tindih). **Cakupan diperbarui 2026-10-02** setelah uji F1 + stress test — kini **13 percakapan** (`id` 4–16, total 270 pesan), bukan 2 seperti semula:

| id | chat_id | pesan | sumber |
|---|---|---|---|
| 4 | `628563324637` | 26 | uji 1 Okt + F1 (id 76–77) + balasan staf (id 150) |
| 5 | `6281913500707` | 35 | uji 1 Okt (tidak dapat pesan baru) |
| 6 | `6282332153590` | 9 | stress test |
| 7 | `6289510570459` | 11 | stress test |
| 8 | `6285791470326` | 160 | stress test (pengirim paling aktif) |
| 9 | `6289675973666` | 16 | stress test |
| 10 | `6283852845634` | 6 | stress test |
| 11 | `6285150636082` | 1 | stress test |
| 12 | `6285852977874` | 2 | stress test |
| 13 | `6281235830809` | 1 | stress test |
| 14 | `6285608821725` | 1 | stress test |
| 15 | `6282332619690` | 1 | stress test |
| 16 | `6282245633933` | 1 | stress test |

Tidak ada baris `deleted_at` terisi di seluruh 13 percakapan (belum pernah dibersihkan).

## Log & operasional (audit 5 Oktober)

- [ ] **TODO-L1** Analisa log adapter/Evolution produksi untuk periode **setelah checkpoint 2026-10-02** dan putuskan apa yang perlu ditindak. — **Checkpoint**: 2026-10-02 ~13:00 WIB (06:00 UTC) — log hidup (`adapter.log`, `evolution.log`) di `\\aulia3\D\kilo\logs\` sudah **dikosongkan ke 0 byte** dengan prosedur resmi: nonaktifkan `AuliaStackWatchdog` → stop task `AULIAADAPTER` & `AuliaEvolution` → `Clear-Content` kedua log → start `AuliaEvolution` → start `AULIAADAPTER` → enable kembali watchdog. Verifikasi pasca: port 3000 & 8080 listen, adapter `connected` ke nomor `62881082323928`, Evolution `CONNECTED TO WHATSAPP`. Isi log lama (sebelum dikosongkan) terarsip di `\\aulia3\D\kilo\logs\arsip\adapter_2026-10-02_1254.log` & `evolution_2026-10-02_1254.log` sebagai baseline pembanding. **Tujuan**: pada 2026-10-05 tinjau log bersih ini untuk melihat apakah ada error **berulang/berlama** yang tidak self-recover (kebalikan lonjakan 2026-10-01 yang memang sesi uji). **Yang dicari**: (a) `[AUTH] Request dari CI4 ditolak` & `webhook ditolak: secret tidak cocok/absen`, (b) event `dead-letter`/`[CRITICAL]` baru, (c) `[HEARTBEAT-EVOLUTION] … fetch failed` yang tidak kembali `connected`, (d) pertumbuhan ukuran file, (e) `evolution.log` `"level":50 "error in sending keep alive"` — pada 2026-10-02 06:03 UTC muncul 1× (transien pasca-restart, pulih 17 detik kemudian); jika **berulang**, itu sinyal koneksi WhatsApp tidak stabil. **Yang boleh diabaikan** (terbukti berasal dari sesi uji 1 Okt, sebelum checkpoint): skenario uji (dead-letter `KILO-MX-17` "koordinat tidak valid", "Field 'text' wajib diisi"), `PERINGATAN SECURITY bind 0.0.0.0` (ulang tiap start), transisi `connecting→connected` yang recover, `body request terlalu besar` dari uji >64MB, dan spam `CACHE: { cached: undefined, … }` di `evolution.log` (dump internal Baileys, bukan error). Hubungkan ke TODO-O1/O3 (rotasi/backup log) dan TODO-F2 (`phone` NULL). **Jendela uji disengaja**: 2026-10-02 14:08:37–14:11:23 WIB gateway dimatikan lalu dinyalakan untuk uji backlog media (TODO-F1) — entri `adapter.log`/`evolution.log` di rentang itu bagian dari uji, bukan error produksi (termasuk transisi `connecting→connected` dan `[HEARTBEAT-EVOLUTION] … fetch failed` saat Evolution boot). Stress test 2026-10-02 14:17:05–14:28:00 WIB juga disengaja (gateway dimatikan; ~209 pesan backlog) — entri log di rentang itu bagian dari uji.
