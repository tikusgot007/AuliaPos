# Checkpoint Sesi

- **Tanggal**: 2026-10-03
- **Status**: selesai
- **Repo / branch**:
  - AuliaPos: `v2.4`, HEAD **`e6a2538`** (lokal = origin = `aulia-server2`)
  - Gateway: `C:\Projects\evolution-gateway` → `tikusgot007/WA-Gateway` branch **`evolution`**, HEAD **`f88c8ed`** (aulia3 memakai isi ini)
  - Produksi: **aulia-server2** (POS) & **aulia3** (Evolution 2.3.7 + adapter 3000)

## Selesai

- **Unduh media Inbox** (lightbox, kartu dokumen, mode pilih + unduh massal) — PR #44, merge `75a97d0`; verifikasi manual OK.
- **Notifikasi lintas halaman Inbox** (judul tab, favicon, beep, toast hijau sticky/stackable, tombol bisu) — PR #46, merge `9a147e2`; feature test `InboxNotifikasiRingkasTest` 4/4, suite feature 21/21; verifikasi manual OK.
- **`composer test:feature`** (suite feature ber-DB terpisah dari `composer test`) — `99ce5d7`.
- **TODO-O2** (retensi): aktifkan `mediaStore.prune()` (media 180 hari), `quotedStore.prune()` (7 hari), `pruneCompleted()` `incoming_queue` (30 hari); jalan saat start + tiap 24 jam — gateway `48132dc`, deploy aulia3.
- **TODO-F2** (backfill `gateway_status.phone` setelah restart): merge `98487e1`; terverifikasi badge "Terhubung (62881082323928)".
- **TODO-O3** (rotasi log): `rotate-logs.ps1` + rotasi saat boot (`run-adapter.cmd`/`run-evolution.cmd`) + task `AuliaLogRotate` harian 09:00 (prune arsip) — gateway `2dc2830`; rotasi adapter terbukti nyata di aulia3.
- **TODO-O1** (backup terjadwal): `backup-sqlite.js` (online) + `backup-stack.ps1` (`pg_dump -Fc`, SQLite, media zip, env zip; retensi 30 hari) + task `AuliaBackup` harian 20:00 — gateway `f4c324b`; run pertama di aulia3 sukses (pg 237 KB, sqlite 872 KB, media 62,6 MB), `pg_restore -l` valid.
- **Insiden Avast**: Avast aulia3 mengarantina `run-adapter.cmd`/`watchdog-stack.ps1`/`backup-stack.ps1` → task adapter/watchdog/backup lenyap → gateway mati. Dipulihkan (exclusion Avast `D:\evolution-gateway` oleh user + nama standar + task didaftarkan ulang) — didokumentasikan di gateway `docs/CHANGELOG.md` (`f88c8ed`).
- **Bersih-bersih TODO**: hapus TODO-U1, U2, T5, O2, F2, O1 dari `docs/TODO.md`; catatan **L1** ditambah poin cek hasil deploy hari ini.
- **Briefing TODO-F6** (forward KELUAR `fromMe` tak berlabel) disiapkan & disampaikan ke tim remote (belum ada keputusan).

## Keputusan penting

- Retensi **media 180 hari** (bukan 7) — alasan: media store gateway adalah fallback live-fetch bila prefetch POS gagal; jangan dipangkas terlalu cepat.
- Rotasi log **saat boot**, bukan jadwal dini hari — alasan: PC gateway mati malam, jadi tiap boot = satu file log baru tanpa downtime tambahan.
- Backup disimpan lokal `D:\backup\aulia3` (belum offline/share) — alasan: kesederhanaan; salin ke share bisa ditambah kemudian.
- Avast **tidak bisa** dikonfigurasi via remote/CLI (tidak ada `ashCmd`) — exclusion wajib lewat UI aulia3.

## Tambahan (lanjutan sesi, setelah checkpoint di atas)

- **TODO-F6** selesai & terverifikasi: tim remote sudah mengimplementasikan di
  branch `claude/forward-marker-outgoing-f6` (gateway, commit `196a9fd`) —
  buang batas `!fromMe` di `normalize.js` `extractForwardFlag()` sehingga
  forward KELUAR tersinkron dari WA Web/HP kini ditandai `is_forwarded` juga.
  Di-merge ke `evolution` (commit `35fc7cf`, konflik `docs/CHANGELOG.md`
  dengan entri Avast diresolve manual), `npm test` lulus. Dideploy ke stack
  **gateway-test** (`C:\AuliaGateway-test`) untuk uji manual, lalu ke
  **aulia3** (produksi) setelah uji manual lulus — file: `src/evolution/normalize.js`,
  `src/delivery/incomingDelivery.js`, `test/simulate-evolution-adapter.js`,
  backup `*.bak-20261003c`; adapter restart (pid `12128`), Evolution `aulia-toko`
  tetap `open`, tidak ada error baru di `adapter.log`.
- **Verifikasi manual nyata** (bukan stub): user forward pesan "Hai" dari WA
  Web/HP langsung (fitur Forward WhatsApp asli) ke nomor gateway-test
  `6281913500707` → tampil berlabel **"↪ Diteruskan"** di Inbox POS.
  Non-regresi: pesan "Tt" (kirim biasa, bukan forward) dari HP yang sama
  **tidak** berlabel, tombol Balas/Teruskan normal. Ini menutup kedua risiko
  "belum diverifikasi" di checkpoint branch F6 (bentuk `contextInfo` forward
  keluar nyata + label UI).

## Tersisa

Lihat `docs/TODO.md`. Masih terbuka: **T3** (verifikasi jalur arsip SQLite), **O4** (VACUUM/pantau disk PG), **F3** (prosedur reapply patch Evolution), **H1** (bersihkan 13 percakapan uji di produksi), **L1** (audit 2026-10-05). **F6 selesai** (lihat di atas) — diusulkan untuk dihapus dari `docs/TODO.md`.

## Belum diverifikasi / risiko

- Rotasi **`evolution.log`** belum pernah jalan sekali pun (akan jalan saat Evolution start berikutnya / boot).
- **Exclusion Avast** bisa hilang jika Avast di-update/reset → script terkarantina lagi (sudah masuk catatan L1 poin vi).
- Backup ada di **disk yang sama** (D: aulia3) — belum melindungi dari disk rusak.
- Password `ops` tampil polos di transkrip chat — **sebaiknya dirotasi**.
- Gateway-test lokal (`C:\AuliaGateway-test`) masih hidup (diminta jangan dimatikan).

## Titik masuk sesi berikutnya

- **Baca**: file ini, `docs/TODO.md`, gateway `docs/CHANGELOG.md` (entri 2026-10-03).
- **Jalankan**:
  - aulia3: cek port 3000/8080 `listen`, task `Get-ScheduledTask Aulia*`, pastikan `D:\evolution-gateway\scripts\*` ada (Avast), `D:\backup\aulia3\*` terisi.
  - POS: `composer test` (unit) & `composer test:feature` (butuh `aulia_inboxdb_test`).
  - Gateway: `npm test` di `C:\Projects\evolution-gateway`.
