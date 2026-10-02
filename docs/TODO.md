# TODO / Backlog Terpusat

> Baca file ini di **AWAL setiap sesi** sebelum bekerja. Perbarui setiap kali item
> selesai (hapus barisnya) atau ada temuan baru. Format:
> `- [ ] **ID** ringkas — prioritas — ref`.
>
> Saat menutup item: hapus barisnya dan sebut **ID**-nya di pesan commit.
> Jangan membuat daftar TODO kedua di tempat lain; checkpoint hanya menunjuk ke sini.

## Prioritas Tinggi

- [ ] **TODO-O1** Backup terjadwal: `pg_dump` PostgreSQL Evolution + SQLite antrean + `media/` + `.env` — tinggi — ref `docs/sesi/2026-10-01-produksi-aulia3-inbox-tipe-pesan.md`
- [ ] **TODO-O2** Sambungkan pemeliharaan yang sudah ada tapi belum pernah dipanggil: `mediaStore.prune()` & `quotedStore.prune()` (satu-satunya pemanggil lama hanya `pruneTerminal()` di `outgoingOperationService.runStartupRecovery()`, dipanggil `src/app/evolution.js:41` saat start). Akibatnya `MEDIA_RETENTION_DAYS=7` & TTL kutipan tidak berjalan. Sekaligus buat prune untuk `incoming_queue` (belum ada di `src/store/incomingBuffer.js`) untuk membuang baris `completed` tua — tinggi — ref `docs/sesi/2026-10-01-produksi-aulia3-inbox-tipe-pesan.md`
- [ ] **TODO-O3** Rotasi/trim log (`adapter.log` ~49 MB/tahun, `evolution.log` ~620 MB/tahun) + task terjadwal prune/backup (kini belum ada) — tinggi — ref `docs/sesi/2026-10-01-produksi-aulia3-inbox-tipe-pesan.md`

## Prioritas Sedang

- [ ] **TODO-T1** Perbaiki `Tagihan::tagihanOrder()` (`app/Controllers/Tagihan.php:93-123`): kolom 8 "Sisa" masih dipetakan ke `transaksi.grand_total` (bug yang sama sudah diperbaiki di `Transaksi` pada review S3b). Urutkan pakai ekspresi `grand_total - total_dibayar` (atau jadikan kolom 8 non-orderable) — sedang — ref `docs/sesi/2026-10-02-server-side-periode-laporan.md`
- [ ] **TODO-T2** Rapikan duplikasi aset DataTables/Buttons dari CDN di `app/Views/laporan/index.php:180-198` (duplikat dengan `layout/main.php`, termasuk `jquery.dataTables` & `dataTables.bootstrap5` dua kali) — sedang — ref `docs/sesi/2026-10-02-server-side-periode-laporan.md`
- [ ] **TODO-O4** (opsional) `VACUUM` + pantau ukuran disk PostgreSQL Evolution — sedang
- [ ] **TODO-F1** Uji backlog **media** (kirim foto saat gateway/PC mati). Simulator `\\aan-pc\01\wa-sender-sim` hanya mengirim teks, jadi harus dikirim manual dari HP — sedang — ref `docs/sesi/2026-10-01-produksi-aulia3-inbox-tipe-pesan.md`
- [ ] **TODO-F2** `phone` di `gateway_status` NULL setelah restart adapter (hanya terisi dari `CONNECTION_UPDATE`) — sedang — ref `docs/sesi/2026-10-01-produksi-aulia3-inbox-tipe-pesan.md`
- [ ] **TODO-F3** Terapkan ulang patch Evolution (`PATCH-ADAPTER (2026-10-01)` di `whatsapp.baileys.service.ts`) setiap kali Evolution di-upgrade; prosedur `C:\Projects\evolution-gateway\docs\evolution-viewonce-patch.md` — sedang
- [ ] **TODO-F4** Perbaiki `Tagihan::tagihanOrder()`: kolom "Sisa" diurutkan memakai `grand_total`, seharusnya ekspresi `grand_total - total_dibayar` (defek pra-ada, ketemu saat review S3b) — sedang — ref `docs/sesi/2026-10-02-server-side-datatables.md`
- [ ] **TODO-F5** Tombol Print pada tabel server-side (item-harian, laporan-pembayaran, tagihan, transaksi, periode) hanya mencetak halaman aktif; pertimbangkan cetak seluruh hasil terfilter — sedang — ref `docs/sesi/2026-10-02-server-side-periode-laporan.md`

## Higiene

- [ ] **TODO-H1** Bersihkan data uji Inbox (2 percakapan uji: `628563324637@s.whatsapp.net`, `6281913500707@s.whatsapp.net`) — higiene — ref `docs/sesi/2026-10-01-produksi-aulia3-inbox-tipe-pesan.md`
- [ ] **TODO-H2** (opsional) Samakan teks baris Inbox lama dengan gaya penanda baru — higiene
- [ ] **TODO-H4** (opsional) Hapus duplikasi aset DataTables/CDN di `app/Views/laporan/index.php:180-198` (pra-ada) — higiene — ref `docs/requirements/2026-10-02-server-side-periode-laporan.md` §5
- [ ] **TODO-H5** (opsional) Refactor dropdown Bulan+Tahun `laporan/index.php` ke partial `components/month_year_picker.php` — higiene — ref `docs/sesi/2026-10-02-standardisasi-pemilih-tanggal.md`
