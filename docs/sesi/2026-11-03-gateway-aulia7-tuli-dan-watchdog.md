# Checkpoint Sesi

- **Tanggal**: 2026-11-03
- **Status**: selesai (pemulihan + watchdog terpasang); tindak lanjut tersisa di TODO-F10
- **Repo / branch**: AuliaPos `v2.4` (hanya dokumen) + perubahan operasional di `aulia7` (`C:\AuliaPosGateway`, di luar repo ini)

## Selesai

- **Insiden 1 — Bad MAC, 09:00-09:59 WIB**: counter `wa-badmac-counter` mencatat 171 `bad_mac`, 6 `decrypt_fail`, 12 `closed_decrypt` dalam satu jam, jam lain ~0. Pesan yang gagal didekripsi tidak bisa dipulihkan; pelanggan harus kirim ulang.
- **Insiden 2 — Evolution "tersambung tapi tuli", ~12:05-12:59 WIB**: instance `aulia-toko` berstatus `open`, tetapi tidak ada `messages.upsert` sejak 12:00:32. Didahului putus-nyambung 12:05, 12:06, 12:11 (`error in sending keep alive`, `unexpected error in 'init queries'`). POS, servis aulia7 dan jaringan sehat.
- **Upaya yang TIDAK memperbaiki**: `POST /instance/restart/aulia-toko` (12:42), reboot aulia7 (12:50).
- **Yang memperbaiki**: logout instance (`DELETE /instance/logout/aulia-toko`) + pairing ulang dengan kode di HP toko; `connected` kembali 12:59:46. Teks dan foto uji dari `628563324637` terbukti masuk lewat jalur langsung (13:00:52 dan 13:02:33).
- **Teks 12:05-12:59 dipulihkan WAGRAB reconciler** (`sync-missing`, prefiks `[AUTO-HEAL WAGRAB]`). Foto/dokumen pada jam itu TIDAK ikut (`media_path` NULL).
- **Watchdog baru di aulia7**: `C:\AuliaPosGateway\scripts\ingress-watchdog.mjs` + Scheduled Task `AuliaIngressWatchdog` (tiap 5 mnt, SYSTEM, tanpa durasi). Alert Telegram (config sama dengan `wagrab-reconciler.json`) untuk: TULI (>=2 pesan 1:1 terlihat di WAGRAB tapi tidak di gateway), GOYAH (>=4 putus-nyambung/30 mnt), SEPI GANDA (08-20 WIB, 90 mnt tanpa pesan di kedua jalur). Dedupe 30 mnt + pesan pulih. Log: `logs\ingress-watchdog.log`.
- Bukti watchdog: `--self-test` lolos; replay 12:30/12:40/12:56 WIB menghasilkan alert TULI (10/11/28 pesan terlewat); 3 tembakan berurutan terjadwal (13:12, 13:17, 13:22) `LastTaskResult 0`; pesan uji Telegram terkirim (`message_id=891`).
- **Patch Baileys rc.9 untuk putus tiap ~50 menit** (dipasang 15:58 WIB): handler `CB:status` meng-ack stanza `<status>`
  di `node_modules\baileys\lib\Socket\messages-recv.js` aulia7. Rincian, cara menerapkan ulang dan rollback: SOP
  `docs/ops/SOP-remediasi-sesi-signal-lid-gateway-aulia7.md` Tingkat 3. Bukti: 5 galat `Stream Errored (ack)` kelas
  `status` hari itu (07:37, 08:27, 11:15, 12:05, 13:50), dua berjarak tepat 50 menit; sebelum patch 12 putus-nyambung,
  96 menit pertama setelah patch 0.
- Port 8080 dibuka permanen untuk LAN saja (aturan `AuliaPosGateway Evolution manager 8080 (LAN only)`); aturan blokir
  lama dipersempit ke `Internet`. Hemat daya adapter Ethernet aulia7 dimatikan.
- Pengingat evaluasi sebulan: cron Hermes `21e952032f08`, 2026-12-03 09:00 WIB, via Telegram.

## Keputusan penting

- **Pairing ulang rutin (mingguan) TIDAK dijadwalkan.** Alasan: butuh HP toko, ada jeda buta 1-5 menit (foto tidak tertolong), pairing ulang bisa memicu Bad MAC (re-registrasi perangkat), dan belum ada bukti pola berkala. Pairing ulang dilakukan saat alert TULI. Evaluasi ulang 2026-12-03: jika alert TULI >= 2x sebulan, jadwalkan di jam sepi (Minggu pagi).
- Urutan penanganan "tuli": restart instance tidak cukup; langsung logout + pairing ulang (SOP Tier 1 perlu dilengkapi, lihat Tersisa).

## Tersisa

- Perbarui `docs/ops/SOP-remediasi-sesi-signal-lid-gateway-aulia7.md`: tambah kasus "open tapi tuli" (gejala, deteksi watchdog, urutan: logout + pairing kode).
- Selidiki `ERROR HTTP 400: request failed` di `wagrab-reconciler.log` (2026-10-22 09:30 dan 2026-11-03 12:57), kemungkinan satu pesan dalam batch ditolak validasi `sync-missing`.
- Pertimbangkan upgrade Evolution di jadwal terpisah. 2.4.0 final mewajibkan aktivasi lisensi (berubah dari 2.3.7);
  2.4.0-rc2 masih pre-release. Jangan upgrade tanpa analisis dan persetujuan.
- Pairing kode rusak di rc.9 (`Invalid buffer`): pakai QR via `http://192.168.1.68:8080/manager`.
- Salin `ingress-watchdog.mjs` ke repo `WA-Gateway` (`scripts/`) agar terversi; sekarang hanya ada di aulia7.
- Ganti password akun `ops` dan rotasi secret webhook adapter (sempat tercetak di output sesi kerja).

## Belum diverifikasi / risiko

- Pemicu putus-nyambung: penyebab utama kini diketahui (stanza `<status>` tidak di-ack, Baileys #2858) dan sudah
  di-patch; belum terbukti tuntas. Bandingkan jumlah putus-nyambung harian dengan rata-rata 3-4 sebelum 3 Nov.
- Patch ada di `node_modules` di luar git: hilang bila Evolution/Baileys diinstal ulang atau di-upgrade.
- Bug "deaf session" (Baileys #2491) belum punya perbaikan upstream; hanya dijaga watchdog.
- Alert TULI bergantung pada WAGRAB (tab WA Web aulia3 harus terbuka); alert SEPI GANDA menutup kasus itu tapi lambat (90 mnt).
- Alert nyata dari watchdog belum pernah terpicu di produksi; baru replay dan pesan uji Telegram.
- Jumlah pesan yang hilang di insiden 1 (09:00) belum dihitung terhadap pelanggan tertentu.

## Titik masuk sesi berikutnya

- **Baca**: `docs/TODO.md` TODO-F10, SOP di `docs/ops/`, `C:\AuliaPosGateway\logs\ingress-watchdog.log` (aulia7).
- **Jalankan** (aulia7, read-only): `node C:\AuliaPosGateway\scripts\ingress-watchdog.mjs --dry-run` dan `node scripts\wa-badmac-counter.js --report --by=hour --date=<tanggal>`.
