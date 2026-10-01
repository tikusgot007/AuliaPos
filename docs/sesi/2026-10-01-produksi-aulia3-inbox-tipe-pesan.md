# Checkpoint Sesi

- **Tanggal**: 2026-10-01
- **Status**: sebagian — semua pekerjaan kode selesai & terverifikasi, **menunggu uji backlog semalam** (keputusan akhir ada di hasilnya)
- **Repo / branch**:
  - Adapter: `C:\Projects\evolution-gateway` → `tikusgot007/WA-Gateway` branch **`evolution`**, HEAD **`67afacf`** (sudah di-push)
  - AuliaPos: `v2.4`, HEAD **`7208431`** (lokal = GitHub = server, semua sinkron)
  - Produksi: **aulia3** (Evolution 2.3.7 + adapter 3000) & **AULIA-SERVER2** (POS + `aulia_inboxdb`)

## Selesai

- **Watchdog boot** (`faaa2ab`): berhenti membunuh Evolution saat boot — umur proses nyata + grace 420s; terverifikasi lewat reboot nyata.
- **Inbox DB produksi dibersihkan** (4 tabel chat; `gateway_status` dipertahankan) dengan dump + **restore rehearsal** yang cocok persis. Backup: `D:\aulia_inbox_clean_backup_20261001`.
- **Tipe pesan (Tahap 1–4)**: jangan pernah kirim teks kosong; pembungkus dibuka; noise dilewati; album (wadah dilewati, foto = baris gambar); lokasi & kontak sebagai tipe nyata + kolom JSON `extra_json`.
  Adapter `5dc0913`, `95626a0`, `a63deb1`; POS `3e13bfd`, `838ffe2` (perbaikan review).
- **Berkas besar** (`c2e87e6`, `d1cddc1`): ambang 64 MB + penanda; batas badan dinaikkan bertahap (16→96→160 MB). Ditemukan dari dokumen 27 MB pelanggan yang **hilang senyap** (413), lalu pulih.
- **View-once** (`6a6f7cd` + patch Evolution): WhatsApp mengirim stanza `unavailable view_once` tanpa isi; Evolution 2.3.7 membuangnya sebelum webhook. Dipatch (1 blok) + adapter mengenali `key.isViewOnce` → baris penanda, media tidak diunduh. Prosedur: `C:\Projects\evolution-gateway\docs\evolution-viewonce-patch.md`.
- **mediaMode `ondemand`** (`67afacf`): webhook tanpa base64; adapter memutuskan dari metadata lalu mengunduh lewat Evolution, gagal → penanda. **Menutup lubang terakhir "hilang senyap"** untuk ukuran berkas apa pun. Alat bantu: `npm run webhook:info`.
- **Kosmetik penanda** (`904d884`, POS `0d10b9d`, `7208431`): semua placeholder dirender dalam kotak yang sama + teks seragam "Customer mengirim … — cek WhatsApp Web."
- **Higiene repo** (`5d7185c`): `.htaccess` tidak lagi dilacak (`/.htaccess`, `/public/.htaccess` + contoh `.example`) sehingga `git pull` di server tidak merusak `RewriteBase`. Lokal = GitHub = server.
- **Uji**: matriks sintetis 17 kasus dead-letter (`completed` naik sesuai, `dead` tidak bertambah kecuali 1 uji negatif sengaja); uji nyata dari HP (album 7 foto, lokasi, kontak, voice note, dokumen 176 KB & 27 MB, view-once, video note, reaction, file audio) — semua sesuai.

## Keputusan penting

- Anggap **"tidak boleh ada pesan hilang senyap"** sebagai aturan utama; setiap tipe/ukuran yang tak bisa diproses harus tetap muncul sebagai baris penanda — alasan: Inbox adalah satu-satunya kanal kasir.
- Media besar **tidak diunduh**, hanya penanda — alasan: hemat disk/memori; isi tetap bisa dilihat dari WhatsApp.
- **View-once tidak diambil isinya** (hanya penanda) — alasan: paritas perilaku WA-Gateway lama (CON-002) dan isinya memang tidak tersedia di perangkat tertaut.
- `ondemand` dipilih daripada menaikkan batas badan terus-menerus — alasan: batas berapa pun selalu menyisakan lubang.
- Patch Evolution disimpan sebagai **satu file dokumen** (bukan fork) — alasan: dipin ke v2.3.7; cukup diterapkan ulang saat upgrade.

## Tersisa / TODO

- [ ] **Uji backlog semalam** (owner: user + sesi berikutnya) — cutoff `#0003`; simulator `\\aan-pc\01\wa-sender-sim` sudah jalan (interval 20–60 menit). Verifikasi besok: cocokkan `data/sent.jsonl` dengan Inbox.
- [ ] Bersihkan data uji Inbox (2 percakapan uji: `628563324637`, `6281913500707`) **setelah** data backlog masuk.
- [ ] (opsional) UPDATE teks baris lama agar seragam dengan gaya penanda baru.
- [ ] Rotasi header rahasia webhook adapter (`x-adapter-webhook-secret`) — nilainya sempat tampil di transkrip sesi ini (endpoint hanya LAN, jadi rendah). *(Password PostgreSQL `evolution_gw` sudah dirotasi user — tidak perlu diingatkan lagi.)*

## Belum diverifikasi / risiko

- **Hasil uji backlog semalam** — inti persoalan; belum diketahui sampai besok.
- Penanda untuk berkas >64 MB baru teruji **unit test** + jalur unduh terverifikasi; belum ada berkas >64 MB nyata dari HP.
- `phone` di `gateway_status` menjadi NULL setelah restart adapter (hanya terisi dari `CONNECTION_UPDATE`) — kosmetik.
- Log adapter/Evolution **tanpa rotasi**; backup terjadwal (PostgreSQL Evolution, SQLite antrean, media) belum ada.
- Evolution `WHATSAPP-BAILEYS` tetap unofficial (risiko ban); patch Evolution hilang bila di-upgrade.
- Simulator mengirim **teks saja** — backlog media saat gateway mati belum diuji.

## Titik masuk sesi berikutnya

- **Baca**: `docs/sesi/2026-10-01-produksi-aulia3-inbox-tipe-pesan.md` (ini), `plan/2026-10-01-inbox-tipe-pesan-bertahap.md`, `docs/uji-inbox-tipe-pesan-nyata.md`, `C:\Projects\evolution-gateway\docs\CHANGELOG.md` + `docs\evolution-viewonce-patch.md`.
- **Verifikasi backlog**:
  - baca `\\aan-pc\01\wa-sender-sim\data\sent.jsonl` (+ `state.json`), bandingkan dengan Inbox POS (cari `TES-GATEWAY`);
  - antrean adapter: jalankan `cmd /c D:\evolution-gateway\scripts\inspect-queue.cmd` di aulia3.
- **Catatan operasional aulia3**: RPC/schtasks dari mesin dev sering gagal → pakai **WMI/DCOM** (`New-CimSessionOption -Protocol Dcom`) untuk menjalankan perintah; restart service: `restart-adapter.ps1` / task `AuliaEvolution`.
