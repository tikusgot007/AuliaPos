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
- [ ] **TODO-O4** (opsional) `VACUUM` + pantau ukuran disk PostgreSQL Evolution — sedang
- [ ] **TODO-F1** Uji backlog **media** (kirim foto saat gateway/PC mati). Simulator `\\aan-pc\01\wa-sender-sim` hanya mengirim teks, jadi harus dikirim manual dari HP — sedang — ref `docs/sesi/2026-10-01-produksi-aulia3-inbox-tipe-pesan.md`
- [ ] **TODO-F2** `phone` di `gateway_status` NULL setelah restart adapter (hanya terisi dari `CONNECTION_UPDATE`) — sedang — ref `docs/sesi/2026-10-01-produksi-aulia3-inbox-tipe-pesan.md`
- [ ] **TODO-F3** Terapkan ulang patch Evolution (`PATCH-ADAPTER (2026-10-01)` di `whatsapp.baileys.service.ts`) setiap kali Evolution di-upgrade; prosedur `C:\Projects\evolution-gateway\docs\evolution-viewonce-patch.md` — sedang

## Higiene

- [ ] **TODO-H1** Bersihkan data uji Inbox (2 percakapan uji: `628563324637@s.whatsapp.net`, `6281913500707@s.whatsapp.net`) — higiene — ref `docs/sesi/2026-10-01-produksi-aulia3-inbox-tipe-pesan.md`
- [ ] **TODO-H2** (opsional) Samakan teks baris Inbox lama dengan gaya penanda baru — higiene
