# SOP Remediasi Sesi Signal/LID — Gateway `aulia7`

Dokumen operasional untuk tim (Bahasa Indonesia). Perintah di bawah menyentuh **gateway produksi**
(`aulia7`) dan harus dijalankan **oleh manusia** di server; agen AI tidak boleh menjalankannya
(`.kilo/rules/sdlc.md` §4, `AGENTS.md` §7), **kecuali** user menyetujui secara eksplisit per
permintaan. Persetujuan bersifat per-request, tidak berlaku otomatis untuk sesi berikutnya.

Dasar: temuan sesi `docs/sesi/2026-10-14-analisa-pesan-gambar-hilang-628214065612.md` dan
`docs/TODO.md` (TODO-F10).

## 1. Kapan SOP ini dipakai

Gejala: **pesan masuk hilang senyap** — khususnya **media/gambar** — padahal terlihat di WhatsApp
Web toko. Ditandai salah satu/lebih:

- Evolution log memuat `WARN Original message not found for update. Skipping.` untuk pesan masuk
  (`fromMe:false`) yang tak pernah tersimpan.
- `AuliaPosGatewayEvolution.err.log` memuat `Failed to decrypt message with any known session...`
  atau `Session error:Error: Bad MAC Error: Bad MAC`.
- Monitor melaporkan `F10 ingress failure ... recent>0` (lihat §4 — saat ini monitor **tidak**
  membaca `err.log`, jadi sinyal ini bisa luput; cek manual §3).

## 2. Prasyarat & keselamatan

- Jalankan di **luar jam ramai kasir**.
- Buka akses WinRM/RDP ke `aulia7` (user `ops`). Dari mesin kerja, WinRM harus memakai
  `-ComputerName aulia7` (nama), **bukan** IP — `TrustedHosts` berisi `aulia7`.
- Catat baseline dulu (ukuran & mtime `err.log`) supaya bisa menilai apakah error berhenti:

```powershell
$err = 'C:\AuliaPosGateway\logs\AuliaPosGatewayEvolution.err.log'
Get-Item $err | Select-Object LastWriteTime, Length
```

- **Backup sebelum tindakan besar** (Tingkat 2): salin `C:\AuliaPosGateway\evolution-api-server\instances\`
  dan dump `evolution_gateway_pg`. Rotation/backup harian sudah ada (lihat TODO-L1 di `docs/TODO.md`).

## 3. Diagnosis cepat (read-only, aman)

Di `aulia7`:

```powershell
$log = 'C:\AuliaPosGateway\logs'
# 1) Pertumbuhan error dekripsi (err.log) — mtime & ukuran
Get-Item "$log\AuliaPosGatewayEvolution.err.log" | Select-Object LastWriteTime, Length
# 2) Pesan masuk yang hilang (kabar baca untuk pesan yang tak ada)
Select-String "$log\AuliaPosGatewayEvolution.out.log" -Pattern 'Original message not found for update' |
  ForEach-Object { ($_.Line -replace '\x1b\[[0-9;]*m','') }
# 3) Riwayat Bad MAC / gagal dekripsi (err.log, tanpa timestamp — batasi lewat mtime)
Select-String "$log\AuliaPosGatewayEvolution.err.log" -Pattern 'Bad MAC|Failed to decrypt'
# 4) Status monitor terakhir
Get-Content "$log\monitor-gateway-aulia7.log" -Tail 8
```

Catat `LastWriteTime` `err.log` sebagai **waktu error terakhir**. Setelah tiap langkah §5,
bandingkan: kalau `err.log` **tidak** bertambah lagi dan tidak ada `Original message not found for
update` baru untuk pesan yang benar-benar dikirim, sesi sudah pulih.

## 4. Remediasi bertingkat

### Tingkat 1 — Restart instance `aulia-toko` (paling ringan, tanpa pairing ulang)

Memaksa rebuild sesi Signal/LID. **Sesi login WhatsApp tetap valid — tidak perlu scan QR.**

Instance id (dari log/DB): `47bdc5b1-1008-4554-a6b1-b958dd9462d0` (nama `aulia-toko`).
Cara paling aman adalah restart **instance** (bukan seluruh service) lewat Evolution API, agar
adapter tetap hidup dan reconnect otomatis:

```powershell
$apiKey = '<AUTHENTICATION_API_KEY dari C:\AuliaPosGateway\evolution-api-server\.env>'
$inst   = 'aulia-toko'
# Lihat status
Invoke-RestMethod -Uri "http://127.0.0.1:8080/instance/fetchInstances" `
  -Headers @{ apikey = $apiKey } | Where-Object { $_.name -eq $inst }

# Restart instance (rebuild sesi)
Invoke-RestMethod -Method Post -Uri "http://127.0.0.1:8080/instance/restart/$inst" `
  -Headers @{ apikey = $apiKey; 'Content-Type' = 'application/json' } -Body '{}'
```

Alternatif bila endpoint instance tidak tersedia: restart **service** Evolution
(`Restart-Service AuliaPosGatewayEvolution`), lalu pastikan adapter kembali `connected`.

Verifikasi keberhasilan Tingkat 1:
- Monitor menunjukkan `state: connected(6285155105633)`.
- `err.log` **berhenti** bertambah (bandingkan mtime dengan waktu restart).
- Kirim pesan uji (teks **dan** gambar) ke nomor resmi `6285155105633` dari HP lain; pastikan
  keduanya sampai ke Inbox dan gambar terbuka.

Bila setelah ~1×24 jam `err.log` masih bertambah → lanjut Tingkat 2.

### Tingkat 2 — Relink / pairing ulang nomor (bila Tingkat 1 gagal)

Sesi rusak lebih dalam. **Perlu scan QR dari HP toko.** Ada jeda koneksi — lakukan di luar jam ramai.

1. Backup dulu (lihat §2).
2. Logout & pairing ulang instance:

```powershell
$apiKey = '<AUTHENTICATION_API_KEY>'
$inst   = 'aulia-toko'
Invoke-RestMethod -Method Delete -Uri "http://127.0.0.1:8080/instance/logout/$inst" `
  -Headers @{ apikey = $apiKey }
# Ambil QR, lalu scan dari WhatsApp HP toko (Perangkat Tertaut > Tautkan Perangkat)
Invoke-RestMethod -Uri "http://127.0.0.1:8080/instance/connect/$inst" `
  -Headers @{ apikey = $apiKey }
```

3. Setelah `connected`, ulangi verifikasi Tingkat 1 (pesan uji teks + gambar).

> Catatan: instance terakhir relink disengaja 2026-10-04 ke nomor resmi `6285155105633`
> (lihat `docs/TODO.md` TODO-F10). Setelah relink, sesi LID per-perangkat dibangun ulang.

**Pelajaran 2026-11-03 (pairing):**
- **Pairing dengan kode (`?number=...`) tidak andal di Baileys `7.0.0-rc.9`**: HP menolak kode dan log
  Evolution mencatat `Error: Invalid buffer` (`messages-recv.js`, `decipherLinkPublicKey`), lalu instance
  `close 401`. Empat kode berturut-turut gagal. **Pakai QR.**
- Cara QR dari PC lain: buka `http://192.168.1.68:8080/manager` (port 8080 terbuka untuk **LAN saja**,
  aturan firewall `AuliaPosGateway Evolution manager 8080 (LAN only)`), masuk dengan API key
  Evolution, pilih `aulia-toko`, **Connect**, scan dari HP toko. Jangan simpan atau kirim QR (kredensial login).
- Setelah logout + scan, webhook ke adapter (`127.0.0.1:3000/evolution/webhook`) tetap terpasang;
  verifikasi `webhook/find/aulia-toko`, bukan dipasang ulang.
- Instance `mara-bot` (nomor 6287875619833) hidup di proses Evolution yang sama dan dipakai; jangan
  dihentikan saat remediasi `aulia-toko`.
- Yang **tidak** memperbaiki sesi `open` tapi tuli: restart instance (`/instance/restart`) dan reboot aulia7.

### Tingkat 3 — Upgrade Baileys / patch Evolution (bila bug versi)

Bila kegagalan LID/`Bad MAC` tetap berulang padahal sesi sudah segar, kemungkinan bug versi
Baileys/Evolution. Ini pekerjaan **kode/rilis** di repo gateway (`evolution-api-server`), bukan
operasional. **Ingat TODO-F3**: patch Evolution (`PATCH-ADAPTER`) harus diterapkan ulang setiap kali
Evolution di-upgrade. Perlu analisis & rilis tersendiri dengan persetujuan user.

**Patch yang sudah terpasang (2026-11-03) — wajib diterapkan ulang setelah Evolution/Baileys diinstal ulang:**
- Berkas: `C:\AuliaPosGateway\evolution-api-server\node_modules\baileys\lib\Socket\messages-recv.js`
  (Baileys `7.0.0-rc.9`). Menambah handler `ws.on('CB:status', ...)` yang meng-ack stanza `<status>`
  (tanpa memprosesnya), sebelum `CB:ack,class:message`. Penanda: komentar `hermes-patch: ack <status> stanza`.
- Alasan: WhatsApp mengirim Status kontak sebagai stanza `<status>` yang tidak dikenal rc.9, jadi tidak
  di-ack; ~50 menit kemudian server memutus koneksi (`Stream Errored (ack)`, `class="status"`) dan
  mengulang tiap ~50 menit. Rujukan upstream: WhiskeySockets/Baileys issue #2858, perbaikan PR #2866
  (belum dirilis saat dicatat). Di aulia7 galat ini tercatat 07:37, 08:27, 11:15, 12:05, 13:50 WIB.
- Skrip penerap (idempoten, membuat cadangan, punya `--self-test` bila tanpa argumen):
  `C:\AuliaPosGateway\scripts\patch-baileys-ack-status.mjs <path messages-recv.js>`.
  Cadangan berkas asli: `messages-recv.js.orig-8b9329dd` (sha256 asli `8B9329DD...`).
- Terapkan: `node patch-baileys-ack-status.mjs <path>` lalu `node --check <path>` lalu
  `Restart-Service AuliaPosGatewayEvolution` (jeda pesan masuk ~1 menit; login WhatsApp tetap).
- Rollback: salin `messages-recv.js.orig-8b9329dd` menimpa `messages-recv.js`, restart servis.
- Hasil terukur: sebelum patch 12 putus-nyambung pada 2026-11-03; 96 menit pertama setelah patch 0 putus
  dan 0 galat `ack status` baru. Kepastian penuh menunggu data semalam.
- **Tidak menutup** bug "deaf session" terpisah (Baileys #2491: ACK tertahan mutex pemrosesan pesan,
  `messages.upsert` berhenti walau koneksi tampak sehat). Itu ditangkap `AuliaIngressWatchdog`.
- Pairing kode juga punya perbaikan upstream (Baileys #2602, #2765; bundel di Evolution PR #2727)
  yang **belum** diterapkan di aulia7.

## 5. Verifikasi pasca-remediasi (wajib)

- [ ] Monitor: `state: connected(6285155105633)`, heartbeat segar.
- [ ] `err.log` tidak bertambah (mtime tidak bergerak setelah restart).
- [ ] Pesan uji **teks** dan **gambar** sampai ke Inbox & gambar terbuka.
- [ ] Tidak ada `Original message not found for update` baru untuk pesan yang benar-benar dikirim.

## 6. Perbaikan pemantauan (usulan, perlu persetujuan)

Monitor `scripts/monitor-gateway-aulia7.ps1` **hanya** mem-parse `AuliaPosGatewayAdapter.out.log`
dan `AuliaPosGatewayEvolution.out.log` (termasuk `*.out.log` yang diroll). Error dekripsi ditulis
ke **stderr** → `AuliaPosGatewayEvolution.err.log`, sehingga **tidak terdeteksi** (itu sebabnya pada
insiden 2026-10-14 monitor melaporkan `F10 recent=0`).

Usulan (perubahan kode, perlu direview & disetujui):
- Tambah `AuliaPosGatewayEvolution.err.log` (+ roll `*.err.log`) ke sumber yang dipindai untuk
  pola `Bad MAC`, `Failed to decrypt`, `decryptWithSessions`.
- Karena `err.log` **tanpa timestamp**, hitung sinyal dari **delta baris/ukuran sejak run sebelumnya**
  (simpan state di file kecil) agar bisa dibedakan kegagalan baru vs lama.
- Naikkan ke `WARN`/`CRITICAL` di `monitor-gateway-aulia7.log` bila ada pertambahan.

## 7. Penanganan gambar yang sudah hilang

Tidak dapat dipulihkan dari server (tidak pernah diterima; tidak ada di Evolution, gateway, maupun
`aulia_inboxdb`). Satu-satunya jalan: **minta pelanggan kirim ulang**, atau ambil dari **WhatsApp Web
toko** (gambar masih tampil & bisa diunduh dari browser).

## 8. Hal yang belum diverifikasi

- Apakah Tingkat 1 (restart instance) benar-benar menghentikan `Bad MAC` — **belum diuji**.
  (Diuji 2026-11-03 untuk kasus lain: restart instance **tidak** memulihkan sesi `open` tapi tuli;
  yang memulihkan hanya logout + pairing ulang. Untuk `Bad MAC` murni tetap belum diuji.)
- Endpoint Evolution pasti untuk restart/logout instance (`/instance/restart`, `/instance/logout`,
  `/instance/connect`) mengikuti Evolution v2.3.7; verifikasi respons saat dijalankan.
- Apakah kegagalan 2026-10-14 menulis ke `err.log` — kemungkinan tidak (Baileys drop senyap);
  `err.log` tanpa timestamp, hanya terbatas oleh mtime.
