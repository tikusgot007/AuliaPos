# Design: Cutover Gateway — dari Scheduled Task ke Windows Service (WinSW)

- **Date**: 2026-10-08
- **Status**: draft (belum dieksekusi; menunggu approval)
- **Requirements**: turunan TODO-Q3c/Q3d; mekanisme service di `docs/requirements/2026-10-07-installer-service-based-winsw.md`
- **SDLC tier**: A
- **Terkait**: `docs/design/2026-10-07-installer-service-based-winsw.md`, `docs/deploy.md`, `docs/TODO.md` (Q3c/Q3d)

## 1. Ringkasan

Ganti mekanisme supervisi gateway **produksi** di PC gateway `aulia3` dari **Scheduled Task** (`AuliaEvolution`, `AuliaAdapter`, `AuliaStackWatchdog`) menjadi **Windows Service (WinSW)** yang sudah diprovisioning installer. Tujuan: **auto-start saat boot** dan **auto-restart saat crash** yang andal (akar masalah TODO-Q3c: task sempat "Running" tapi proses `node` tidak ada).

Cutover dibuat **seminimal mungkin**: **port, instance, dan token dibiarkan identik** supaya **AuliaPos (CI4) tidak berubah** dan **nomor WhatsApp tidak perlu pairing ulang**.

## 2. Kondisi saat ini (referensi + bukti)

### 2.1 Topologi produksi (per `docs/deploy.md`/`TODO.md`, perlu verifikasi ulang di `aulia3`)
- POS server `aulia-server2` (Windows 7) menjalankan **CI4** + MySQL.
- PC gateway `aulia3` menjalankan **Evolution API** (`:8080`) + **adapter** (`:3000`) via **Scheduled Task**, sumber: Evolution `D:\evolution-api-server`, adapter `D:\evolution-gateway`.
- CI4 produksi: `inbox.gatewayBaseUrl = http://aulia3:3000`; instance Evolution `aulia-toko`; shared secret = `inbox.gatewayToken` (== `CI4_GATEWAY_TOKEN` di `.env` adapter).
- Task pendukung: `AuliaStackWatchdog` (penjaga), `AuliaLogRotate`, `AuliaMonitor`.

### 2.2 Bukti dari environment dev `C:\AuliaGateway-service-test` (SESI 2026-10-08, semua PASS)
- WinSW v2.12.0; 3 service (`postgresql-aulia-service-test`, `AuliaGatewayEvolutionTest`, `AuliaGatewayAdapterTest`) `Automatic`.
- **Reboot → auto-start** ketiga service tanpa aksi manual.
- **Kill paksa proses `node`** → service pulih otomatis (proses+service di-restart, port kembali).
- **Inbound E2E**: WhatsApp → Evolution → adapter → CI4 `/api/inbox/gateway/messages` → `aulia_inboxdb` → tampil di Inbox UI.
- **Outbound E2E**: POS kirim → CI4 → adapter `/send` → Evolution → WhatsApp; status `delivered`/`read` balik ke CI4.
- Token `CI4_GATEWAY_TOKEN` (test) disamakan dengan `inbox.gatewayToken` → auth `/status` & `/messages` bukan 401.

### 2.3 Constraint arsitektur (verified)
- `aulia_inboxdb.gateway_status` adalah **singleton global** (`id=1`).
- **Satu nomor WhatsApp hanya bisa pairing ke satu instance** Evolution.
- ⇒ **Dua gateway tidak boleh aktif bersamaan.** Cutover = **switchover**, bukan paralel. (Flag `HEARTBEAT_ENABLED` dibuat khusus untuk menjalankan dua gateway di dev tanpa bentrok singleton; di produksi hanya ada satu gateway sehingga **default `true` sudah benar**.)

## 3. Keputusan kunci

- **A. Cutover in-place, port/instance/token IDENTIK** (Evolution `:8080`, adapter `:3000`, instance `aulia-toko`, token sama). Hanya mekanisme supervisi (Task → Service) yang berubah; **CI4 tidak diubah**.
- **B. Port baru** (`:18080`/`:13000` ala dev). Membutuhkan ubah `gatewayBaseUrl` produksi + aturan firewall + `-PortList` installer. Lebih berisiko; ditolak untuk cutover pertama.
- **C. Ganti PC gateway sekaligus** (install baru + pairing ulang nomor resmi). Paling bersih tapi butuh QR/pairing ulang nomor produksi dan jendela lebih panjang; simpan sebagai opsi cadangan.

**Rekomendasi: A.** Diff paling kecil, tanpa pairing ulang, tanpa ubah CI4, dan sudah terbukti polanya di dev. Kelemahan: butuh jalur **"registrasi service saja"** (lihat §4) agar tidak menjalankan ulang provisioning (jangan menimpa `.env`/DB/instance produksi).

## 4. Prasyarat (blocker sebelum eksekusi)

1. **Installer "services-only" (WAJIB — belum ada).** `install.ps1` (per design WinSW) menjalankan provisioning penuh: menulis ulang `.env`, `install-postgres`, `prisma migrate`, `setup-instance`. Menjalankannya di `aulia3` berisiko **menimpa token `.env`** dan/atau menyentuh data. Diperlukan salah satu:
   - mode `-ServicesOnly` (atau `-RegisterOnly`) pada `install.ps1` yang **hanya** merender XML + `Install-WinSwService` + `Set-Service Automatic`, **tanpa** menyentuh `.env`/DB/instance; **atau**
   - skrip registrasi terpisah yang mengarah ke direktori `aulia3` yang sudah ada.
   → **Tambahkan sebagai pekerjaan kecil sebelum cutover** (Tier A, perlu design/approval sendiri).
2. **Commit source adapter ke repo WA-Gateway** (branch `evolution`): perubahan toggle heartbeat + test saat ini hanya ada di copy env test. Untuk produksi flag tidak wajib (single gateway), tapi repo resmi harus memuat kode yang diuji.
3. **Backup**: `.env` adapter & Evolution `aulia3`; `D:\evolution-gateway\data` (SQLite incoming/outgoing/media); dump PostgreSQL `aulia-toko`; folder data instance Evolution.
4. **Catat mekanisme lama**: nama+aksi task (`AuliaEvolution`, `AuliaAdapter`, `AuliaStackWatchdog`, `AuliaLogRotate`, `AuliaMonitor`), agar rollback presisi.
5. **Jendela cutover** (di luar jam sibuk) + operator standby; siapkan rollback.
6. **Verifikasi port bebas** setelah task dimatikan (`3000`/`8080`) dan **Path/TZ akun service** (`LocalSystem` vs akun task) — service berjalan `LocalSystem`; pastikan `.env`, direktori data, dan `node.exe` dapat diakses.

## 5. Runbook cutover (berurutan)

> Prinsip: satu gateway aktif pada satu waktu. Jangan menghapus task; cukup **disable** (untuk rollback).

1. **Freeze**: pastikan tidak ada kiriman sedang berjalan; catat `incoming_queue`/`outgoing_operations` = bersih (semua `completed`/`sent`).
2. **Disable watchdog & monitor**: `Disable-ScheduledTask -TaskName AuliaStackWatchdog` (dan `AuliaMonitor`) agar tidak menyalakan ulang task yang kita matikan.
3. **Stop gateway lama**: `Stop-ScheduledTask -TaskName AuliaAdapter` lalu `AuliaEvolution`. Verifikasi: tidak ada proses `node` gateway, port `3000` & `8080` kosong.
4. **Registrasi service (services-only)**: jalankan installer mode services-only (§4.1) dengan parameter **identik produksi**: `-InstallRoot <root> -PgPort 5432 -EvolutionPort 8080 -AdapterPort 3000 -InstanceName aulia-toko -ServiceName postgresql-auliagw -EvolutionServiceName AuliaGatewayEvolution -AdapterServiceName AuliaGatewayAdapter`, diarahkan ke direktori `aulia3` yang sudah ada (`D:\evolution-gateway`, `D:\evolution-api-server`). Pastikan **tidak** menulis `.env`/instance.
   - Service Evolution `<depend>` PostgreSQL service; adapter `<depend>` service Evolution (urutan SCM).
5. **Start service**: `Start-Service AuliaGatewayEvolution`, tunggu port `8080` siap; `Start-Service AuliaGatewayAdapter`, tunggu port `3000` siap.
6. **Verifikasi instance**: instance `aulia-toko` tetap ada & `open` (tidak pairing ulang).
7. **Disable task lama secara permanen (sementara)**: biarkan **disabled** (jangan `Unregister`) sampai service terbukti stabil minimal 2–3 hari.
8. **AuliaPos (CI4)**: **tidak diubah** (`gatewayBaseUrl` tetap `http://aulia3:3000`, token tetap).
9. **Bersih-bersih (setelah stabil)**: baru hapus/`Unregister` task lama + stop watchdog lama.

## 6. Verifikasi pasca-cutover (checklist)

- [ ] `Get-Service AuliaGatewayEvolution,AuliaGatewayAdapter` → **Running**, `StartType=Automatic`.
- [ ] Port `8080` & `3000` LISTEN (proses `node` milik service).
- [ ] `check-service.ps1` (AC-9) → PASS.
- [ ] **Reboot test**: reboot `aulia3`, setelah boot ketiga service Running **tanpa** aksi manual (mengatasi Q3c).
- [ ] **Kill test**: kill proses `node` → pulih otomatis dalam hitungan detik.
- [ ] **Inbound** dari WhatsApp asli → muncul di Inbox POS (DB + UI).
- [ ] **Outbound** dari POS → terkirim WhatsApp; status `delivered`/`read` balik ke CI4.
- [ ] `gateway_status id=1` fresh (heartbeat service), badge Inbox "Terhubung".
- [ ] Tidak ada `[AUTH] ... ditolak` baru, tidak ada restart loop, `err.log` bersih.
- [ ] Tidak ada task gateway yang Running (semua disabled).

## 7. Rollback

Bila ada masalah (port tak naik, instance hilang, pesan tak jalan):
1. `Stop-Service AuliaGatewayAdapter; Stop-Service AuliaGatewayEvolution`.
2. `Enable-ScheduledTask` watchdog + `AuliaEvolution; AuliaAdapter` (atau `Start-ScheduledTask`), **atau** jalankan ulang mekanisme lama.
3. Verifikasi port `3000`/`8080` + instance `aulia-toko` `open`.
4. Investigasi di luar jam sibuk.
Rollback aman karena: task tidak dihapus, `.env`/DB/instance tidak diubah oleh langkah services-only, dan CI4 sama sekali tidak berubah.

## 8. Risiko & mitigasi

- **Installer menimpa `.env`/instance produksi** → mitigasi wajib §4.1 (services-only) + backup + verifikasi `.env` tak berubah (hash sebelum/sesudah).
- **Services-only belum ada** → blocker; kerjakan lebih dulu (kecil, terisolasi).
- **Startup Evolution butuh PostgreSQL siap** → sudah ditangani `<depend>` PostgreSQL service (design WinSW §2/§4).
- **`.env`/data tak terbaca oleh akun `LocalSystem`** (beda konteks dari task) → verifikasi akses file & TZ sebelum go-live.
- **Windows Update/AV mengganggu** → pertimbangkan exclusion AV untuk direktori gateway (pernah kejadian di aulia3).
- **Jeda tak terhindarkan** (beberapa detik–menit saat switchover) → lakukan di luar jam sibuk; pesan masuk selama jeda tetap tampil di HP pelanggan, Evolution akan mengirim webhook saat tersambung lagi.
- **`HEARTBEAT_ENABLED` di produksi** → biarkan default (`true`); jangan set `false` (itu hanya untuk skenario dual-gateway dev).

## 9. Belum terverifikasi

- Parameter/struktur pasti `aulia3` (nama InstallRoot, path, akun task, token) — perlu dibaca langsung, bukan asumsi.
- Apakah `install.ps1` saat ini benar-benar idempoten terhadap `.env`/instance (perlu dibaca kode; design §5 menandai "in-place upgrade belum diselesaikan").
- Perilaku `Stop-ScheduledTask` vs proses `node` anak (apakah benar-benar mematikan proses, seperti pola `watchdog-stack.ps1`).
- `readreceipts: all` pada instance `aulia-toko` (TODO-Q3e) — pastikan tetap berlaku setelah cutover.
- Pengaruh `LocalSystem` terhadap akses `D:\` dan pemetaan jaringan (bila ada) di aulia3.

## 10. Referensi

- `docs/design/2026-10-07-installer-service-based-winsw.md` — mekanisme installer WinSW (templates, `<depend>`, `onfailure`).
- `docs/requirements/2026-10-07-installer-service-based-winsw.md` — AC lengkap mekanisme service.
- `docs/deploy.md` — prosedur deploy gateway.
- `docs/TODO.md` — Q3c (auto-start tak andal), Q3d (opsi deploy), Q3e (readreceipts).
- Sesi 2026-10-08 (dev `C:\AuliaGateway-service-test`): provisioning + reboot/kill recovery + inbound/outbound E2E + token + toggle `HEARTBEAT_ENABLED`.

## 11. Catatan environment dev saat ini (2026-10-08, bukan produksi)

Pada PC dev `DESKTOP-2DIS7VC`, agar E2E penuh lewat stack service:
- `C:\AuliaGateway-service-test\evolution-gateway\.env`: `HEARTBEAT_ENABLED=true`.
- `C:\xampp\htdocs\aulia\.env`: `inbox.gatewayBaseUrl = http://127.0.0.1:13000`.
- Perubahan source adapter (`src/config/index.js` `toBool`+`heartbeatEnabled`, `src/evolution/heartbeat.js` guard, `test/test-heartbeat-toggle.js`, `package.json`) **belum di-commit**.
Kembalikan ke semula (bila perlu): `inbox.gatewayBaseUrl` → `:3000`, `HEARTBEAT_ENABLED=false`.
