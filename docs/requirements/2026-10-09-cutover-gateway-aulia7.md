# Requirements: Cutover Gateway Produksi dari `aulia3` ke `aulia7`

- **Tanggal**: 2026-10-09
- **Status**: draf (menunggu Gate 1)
- **Tier SDLC**: A (infrastruktur produksi, lintas-repo, menyentuh nomor WhatsApp resmi)
- **Penanggung jawab**: —
- **Terkait**:
  - `docs/design/2026-10-08-cutover-gateway-service.md` (cutover **in-place** di `aulia3`; dokumen ini = opsi **ganti PC**, opsi C di sana).
  - `docs/requirements/2026-10-07-installer-service-based-winsw.md`, `docs/design/2026-10-07-installer-service-based-winsw.md` (mekanisme WinSW).
  - `docs/requirements/2026-10-08-installer-git-based-source.md` (installer Git untuk PC baru).
  - `docs/TODO.md` **TODO-Q3c/Q3d/Q3f/Q3g** (auto-start tak andal; opsi deploy di PC lain; cutover; installer Git).

## 1. Tujuan

Memindahkan gateway produksi WhatsApp (Evolution API + adapter `evolution-gateway`) dari PC `aulia3` ke PC **`aulia7`** (PC lain yang sudah ada, spesifikasi hardware **di atas** `aulia3`), sehingga gateway lebih andal (auto-start/auto-restart), tanpa mengubah aturan bisnis, kontrak POS↔Gateway, atau nomor WhatsApp resmi, dan tanpa mengorbankan kemampuan rollback ke `aulia3`.

Rencana harus **matang sebelum eksekusi**: tidak ada operasi apa pun yang menyentuh `aulia3` (task, service, `.env`, instance, DB) sampai Gate 2 disetujui dan jendela cutover disepakati.

## 2. Kondisi saat ini (terverifikasi dari dokumen/kode)

Fakta terverifikasi dari repo/kode:
- CI4 menunjuk gateway lewat `inbox.gatewayBaseUrl` (dibaca dari `.env`, `app/Config/Inbox.php:136`; dipakai di `app/Controllers/Inbox.php:651,893,4227,4237,4316,4416,4475` dan `app/Libraries/InboxRealtimeWs.php:20`). Produksi: `http://aulia3:3000` (per `docs/design/2026-10-08-cutover-gateway-service.md:20`).
- Token bersama: `inbox.gatewayToken` (CI4) == `CI4_GATEWAY_TOKEN` (adapter `.env`).
- Constraint arsitektur: `aulia_inboxdb.gateway_status` **singleton** (`id=1`) dan satu nomor WhatsApp hanya ter-pairing ke **satu** instance Evolution → **dua gateway tidak boleh aktif bersamaan**; cutover = switchover.
- Paket installer berbasis **WinSW** + **Git source** sudah ada (untuk PC baru) dan telah lulus 6–9 cek lokal; belum E2E di PC/VM bersih.

Kondisi `aulia3` (dari dokumen, **belum diverifikasi ulang langsung** — sengaja tidak diakses):
- Evolution `:8080`, adapter `:3000`, instance `aulia-toko`, sumber `D:\evolution-api-server` / `D:\evolution-gateway`, dijalankan lewat Scheduled Task (`AuliaEvolution`, `AuliaAdapter`, `AuliaStackWatchdog`, `AuliaLogRotate`, `AuliaMonitor`).
- Gejala andal: task "Running" tapi proses `node` mati; Evolution naik ~9 menit pasca-boot; perlu pemulihan manual (TODO-Q3c).

`aulia7` (dari user): PC lain yang **sudah ada**; hardware di atas `aulia3`. Detail (OS, hostname, IP, akses LAN ke server POS, firewall, akun admin) **belum diverifikasi** → lihat §7.

## 3. User story

- Sebagai **operator gateway**, saya ingin gateway produksi berjalan di `aulia7` dengan auto-start & auto-restart yang andal, supaya downtime WhatsApp tidak bergantung pada operator.
- Sebagai **kasir**, saya ingin Inbox POS tetap berfungsi normal (terima/kirim/edit/hapus pesan) setelah pindah, tanpa perubahan perilaku.
- Sebagai **operator**, saya ingin cutover bisa **di-rollback** ke `aulia3` bila ada masalah, tanpa kehilangan data atau pairing.
- Sebagai **pemilik produk**, saya ingin perpindahan dilakukan **terkendali** (satu gateway aktif, jendela cutover, backup), tanpa risiko menerima pesan ganda atau kehilangan pesan.

## 4. Acceptance criteria

- **AC-1**: Given rencana & jendela cutover disetujui, when cutover dijalankan, then Evolution API dan adapter berjalan di `aulia7` sebagai **Windows Service (WinSW) `StartType=Automatic`**, dengan **port (`:8080`/`:3000`), instance (`aulia-toko`), dan token identik** dengan produksi saat ini.
- **AC-2**: Given cutover, then pada satu waktu **hanya satu** gateway yang aktif/terhubung ke nomor WhatsApp resmi (tidak ada bentrok singleton `gateway_status id=1`); `aulia3` dihentikan sebelum `aulia7` diaktifkan.
- **AC-3**: Given `aulia7` aktif, then **inbound** (WhatsApp → Evolution → adapter → CI4 `/api/inbox/gateway/messages` → `aulia_inboxdb` → Inbox UI) dan **outbound** (POS → CI4 → adapter `/send` → Evolution → WhatsApp, beserta status `delivered`/`read` balik) berfungsi **E2E** dengan nomor asli.
- **AC-4**: Given `aulia7` telah ditetapkan sebagai host, then **AuliaPos (CI4) menunjuk `aulia7`** sesuai keputusan §7.2, tanpa mengubah kontrak/payload/endpoint (perubahan maksimum: nilai `inbox.gatewayBaseUrl`).
- **AC-5**: Given `aulia7` aktif, when PC **di-reboot**, then ketiga layanan (PostgreSQL/Evolution/adapter atau ekuivalennya) naik otomatis; dan when proses `node` **dibunuh**, then pulih otomatis dalam ≤ 30 detik.
- **AC-6**: Given masalah pasca-cutover, when rollback dijalankan, then `aulia3` kembali melayani gateway dengan pairing/nomor & data utuh, dan mekanisme lama (`aulia3`) **di-disable, bukan di-unregister**, sampai `aulia7` stabil.
- **AC-7**: Given sebelum eksekusi, then tersedia **backup** (`aulia3`): `.env` adapter & Evolution, data SQLite adapter (`D:\evolution-gateway\data`: incoming/outgoing/media), **dump PostgreSQL instance `aulia-toko`**, dan folder data instance Evolution.
- **AC-8**: Given cutover, when selesai, then **tidak ada perubahan** pada skema DB POS, tabel `messages`/`send_status`, UI Inbox, patch Evolution (view-once/LID), atau kode adapter selain yang sudah ada di repo resmi.
- **AC-9**: Given setelah `aulia7` stabil (mis. ≥ 2–3 hari), then `aulia3` (task/service lama) di-nonaktifkan permanen; dokumentasi (`docs/deploy.md`) diperbarui agar menunjuk `aulia7`.
- **AC-10**: Given jendela cutover, then dilakukan **di luar jam sibuk** oleh operator standby, dengan checklist pra/pasca dan pencatatan hasil.

## 5. Batasan dan di luar cakupan

Batasan:
- **Dilarang menyentuh `aulia3`** (task/service/`.env`/instance/DB) sebelum Gate 2 + jendela cutover.
- Satu gateway aktif pada satu waktu (constraint singleton).
- Tidak mengubah kontrak HTTP CI4↔adapter, patch Evolution, atau aturan bisnis.
- Tool supervisi: **WinSW** (bukan NSSM/Docker), konsisten keputusan 2026-10-07.
- Sumber kode via **Git** (installer 2026-10-08), bukan ZIP.

Di luar cakupan:
- **In-place cutover Task→Service di `aulia3`** (design terpisah 2026-10-08) — dokumen ini menggantinya sebagai arah utama, tetapi tidak mengeksekusinya.
- Perubahan kapasitas/nomor WhatsApp atau instance baru.
- Perubahan `docs/CHANGELOG.md` (bukan perubahan aturan bisnis).

## 6. Dampak aturan bisnis

- [ ] Mengubah aturan bisnis.
- [ ] Menyentuh data keuangan.
- [ ] Mengubah skema database.
- [ ] Mengubah kontrak POS <-> WA Gateway — **tidak berubah** (hanya alamat host gateway yang ditunjuk CI4, sesuai §7.2).

Tidak ada perubahan aturan bisnis → tidak perlu entri `docs/CHANGELOG.md` (kecuali muncul keputusan yang mengubah operasional, akan dicatat terpisah).

## 7. Asumsi dan pertanyaan terbuka

### 7.1 Asumsi
- `aulia7` adalah PC yang **sudah ada**, Windows 10/11 x64, admin + akses internet, dan dapat menjangkau server POS (LAN) tempat CI4 berjalan.
- Installer WinSW/Git dapat dipakai di `aulia7` (installation awal); belum E2E di PC bersih (perlu diuji saat eksekusi).
- Port/instance/token tetap sama → tidak perlu pairing ulang **jika** sesi berhasil dimigrasi (§7.3).

### 7.2 Pertanyaan terbuka — cara CI4 menunjuk gateway (perlu keputusan)
Opsi:
- **(a) Ubah `inbox.gatewayBaseUrl` ke host/IP `aulia7`** (mis. `http://aulia7:3000`) + aturan firewall. Diff kecil di `.env` produksi; perlu jendela.
- **(b) `aulia7` memakai hostname/IP yang sama** dengan `aulia3` (mis. entri hosts di server POS atau `aulia7` memakai IP `aulia3`), sehingga CI4 **tidak berubah**.
- **(c) Reverse-proxy/DNS** agar CI4 tetap memanggil satu nama.
- **Rekomendasi**: **(a)** — paling eksplisit, tidak menyisakan hostname menyesatkan, dan mudah di-rollback (kembalikan `.env` ke `aulia3`). (b) menyimpan risiko kebingungan operasional; (c) menambah komponen baru.
- **Belum diverifikasi**: apakah `aulia3`/`aulia7` diakses lewat hostname (`aulia3`) atau IP; perlu dicek saat persiapan, bukan sekarang.

### 7.3 Pertanyaan terbuka — sesi WhatsApp (perlu keputusan)
Opsi:
- **(a) Migrasi sesi/instance + dump PostgreSQL `aulia-toko`** ke `aulia7` (tanpa pairing ulang). Diff pelanggan minimal, riwayat terjaga. Risiko: kompatibilitas sesi Signal/LID antar-mesin (kita baru saja mengalami isu LID/sesi, TODO-F10); butuh verifikasi koneksi pasca-switchover (tidak bisa uji paralel karena singleton).
- **(b) Pairing ulang (QR)** nomor resmi di `aulia7`. Lebih bersih untuk sesi baru, tapi butuh akses HP pemegang nomor + jendela; riwayat chat di Evolution bergantung migrasi DB.
- **Rekomendasi**: **primarily (a)** untuk meminimalkan gangguan pelanggan, dengan **(b) sebagai fallback** bila verifikasi sesi pasca-switchover gagal. Sesi di `aulia7` **wajib diverifikasi** (kirim/terima uji) sebelum diumumkan stabil.
- **Belum diverifikasi**: lokasi & bentuk penyimpanan sesi Evolution (folder creds vs DB), dan apakah aman dipindah antar-mesin.

### 7.4 Pertanyaan (maks. 3)
1. Apakah `aulia7` sudah dipakai untuk hal lain, atau **khusus** gateway (mempengaruhi isolasi/firewall/window)?
2. Apakah ada **nomor WhatsApp lain** yang berbagi instance/nomor ini (mempengaruhi larangan paralel)?
3. Kapan **jendela cutover** yang diinginkan (jam sepi) dan siapa operator standby saat eksekusi?

## 8. Persetujuan (Gate 1)

- [ ] Disetujui oleh: <nama>, tanggal: <YYYY-MM-DD>
