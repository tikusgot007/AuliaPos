# Requirements: Installer Evolution Gateway Berbasis Windows Service (WinSW)

- **Tanggal**: 2026-10-07
- **Status**: disetujui
- **Tier SDLC**: A (infrastruktur installer, lintas proses, menyentuh startup produksi)
- **Penanggung jawab**: —
- **Terkait**:
  - `C:\Projects\evolution-gateway\installer\*` (paket installer gateway-only, lihat `docs/design/2026-10-02-paket-instalasi-gateway-pc-baru.md`)
  - `docs/TODO.md` **TODO-Q3c** dan **TODO-Q3d** — gateway produksi `aulia3` memakai scheduled task (`AuliaAdapter`/`AuliaEvolution`) yang **tidak andal auto-start saat reboot**; opsi yang diusulkan: Windows Service via WinSW.
  - Desain teknis detail: `docs/design/2026-10-07-installer-service-based-winsw.md` (Bahasa Inggris, untuk implementasi).

## 1. Tujuan

Mengganti model start/stop **manual berbasis proses** (`Start-Process`/`Stop-Process` di `installer/start.ps1`, `stop.ps1`) pada paket installer gateway (`installer/install.ps1`) menjadi **tiga Windows Service**: PostgreSQL (sudah service native, tidak berubah), Evolution API, dan adapter `evolution-gateway` — keduanya dibungkus **WinSW** (bukan NSSM) — supaya gateway **otomatis menyala saat PC boot** dan **otomatis pulih sendiri saat proses `node` mati mendadak**, tanpa bergantung pada operator yang login dan menjalankan skrip secara manual.

## 2. Kondisi saat ini (terverifikasi)

Paket installer (`C:\Projects\evolution-gateway\installer\`) saat ini:

| Skrip | Perilaku sekarang |
|---|---|
| `install.ps1:269-274` | Setelah provisioning, memanggil `start.ps1` yang menjalankan Evolution + adapter sebagai **proses `Start-Process` biasa** (`start.ps1:42-50`), bukan service. |
| `start.ps1:30-51` | `Start-StackApp`: `Start-Process -FilePath node -ArgumentList ... -WindowStyle Hidden`, lalu `Wait-Listen`. Proses ini **tidak bertahan setelah reboot** dan **tidak auto-restart** bila `node.exe` mati. |
| `stop.ps1:31-61` | `Stop-OwnedProcess`: mencari proses `node.exe` pemilik port, verifikasi command line cocok, lalu `Stop-Process -Force`. PostgreSQL (sudah service native `postgresql-auliagw`) **tidak** dimatikan kecuali `-StopPostgres`. |
| `status.ps1:30-41` | Melaporkan status service PostgreSQL + port listen untuk ketiga komponen. |
| `installer/tests/check-static.ps1:1,10-21,33-36` | **AC-12**: paket **MELARANG** registrasi scheduled task (`Register-ScheduledTask`, `schtasks`, dst.) — keputusan 2026-10-02 adalah "start manual, tanpa scheduled task/watchdog". |
| `installer/petunjuk-penggunaan.md` §3 | Menjelaskan `start.ps1`/`stop.ps1`/`status.ps1` sebagai operasi **manual**, tanpa auto-start. |

Konteks produksi (`aulia3`, **repo/skrip berbeda, TIDAK diubah oleh paket ini** — lihat `scripts/register-services.ps1`, `watchdog-stack.ps1`, `run-*.cmd`): memakai **scheduled task** `AuliaEvolution`/`AuliaAdapter` + watchdog 5 menit. **TODO-Q3c** (`docs/TODO.md`) mencatat task ini **terbukti tidak andal**: status "Running" tapi proses `node` sudah mati; Evolution baru benar-benar naik ~9 menit pasca-boot; harus dipulihkan manual. **TODO-Q3d** mengusulkan dua opsi: (a) Windows Service (WinSW/NSSM), (b) Docker Compose. User pada task ini memilih **(a) WinSW**, secara eksplisit **bukan NSSM**.

Fakta tambahan yang relevan dan sudah diverifikasi (lihat desain teknis untuk rincian `file:line`):

- Adapter (`src/app/evolution.js:80-92`) sudah punya handler `SIGINT`/`SIGTERM` yang menutup server + worker secara graceful (timeout fallback 5 detik).
- Evolution API (`src/main.ts`, `src/config/error.config.ts`) **tidak** punya handler shutdown graceful eksplisit; proses Node akan berhenti langsung saat menerima sinyal terminasi — ini **sama** dengan perilaku `stop.ps1` saat ini yang memakai `Stop-Process -Force`, sehingga tidak ada regresi.
- WinSW (versi stabil terverifikasi: `v2.12.0`, biner `WinSW-x64.exe`, self-contained .NET — tidak menambah prasyarat runtime) adalah Windows Service Wrapper gratis/MIT, mendukung auto-restart via elemen `<onfailure action="restart" .../>` dan auto-start via `<startmode>Automatic</startmode>`.

## 3. User story

- Sebagai **operator gateway**, saya ingin PC gateway yang baru di-restart (listrik mati, Windows Update, dsb.) **langsung menyalakan ulang seluruh stack tanpa saya login**, supaya downtime WhatsApp tidak bergantung pada ada-tidaknya operator di tempat.
- Sebagai **operator gateway**, saya ingin proses `node` yang mati mendadak (crash, out-of-memory, dibunuh antivirus) **pulih sendiri dalam hitungan detik**, bukan dalam hitungan menit seperti watchdog scheduled-task saat ini (TODO-Q3c).
- Sebagai **operator gateway**, saya tetap ingin bisa `start`/`stop`/`status` secara manual lewat skrip yang sudah familiar, tanpa harus hafal nama service Windows atau perintah `sc.exe`.
- Sebagai **penguji instalasi**, saya ingin satu skrip verifikasi (`check-service.ps1`) yang memastikan ketiga service terdaftar dan dalam keadaan `Running`, supaya saya tidak perlu memeriksa satu per satu secara manual.

## 4. Acceptance criteria

- **AC-1**: Given paket installer dijalankan di PC baru, when `install.ps1` selesai, then **dua Windows Service baru** terdaftar (Evolution API, adapter) dengan `StartType = Automatic`, dibungkus WinSW (bukan NSSM, bukan scheduled task). PostgreSQL tetap memakai service native yang sudah ada (`install-postgres.ps1`, tidak diubah).
- **AC-2**: Given kedua service terdaftar, when PC gateway **di-reboot**, then setelah boot selesai, ketiga service (PostgreSQL, Evolution, adapter) berstatus `Running` **tanpa login operator** dan tanpa menjalankan skrip apa pun secara manual.
- **AC-3**: Given service Evolution atau adapter sedang `Running`, when proses `node.exe` miliknya **dibunuh** (`Stop-Process -Force` atau sejenisnya di luar jalur service), then Windows Service Control Manager **menyalakan ulang proses tersebut dalam waktu ≤ 30 detik** tanpa intervensi manual.
- **AC-4**: Given stack terpasang sebagai service, when operator menjalankan `installer\stop.ps1`, then **seluruh service** (Evolution + adapter, dan PostgreSQL bila `-StopPostgres`) berhenti lewat `Stop-Service`, bukan lewat pencarian proses berdasarkan port.
- **AC-5**: Given stack sudah ter-install dan dalam keadaan stop, when operator menjalankan `installer\start.ps1`, then **seluruh service** menyala lewat `Start-Service`, dan skrip menunggu (`Wait-Listen`) sampai port masing-masing benar-benar listen sebelum melaporkan sukses.
- **AC-6**: Given `installer\status.ps1` dijalankan, then output menunjukkan status **tiga** service (`Get-Service`: PostgreSQL, Evolution, adapter) beserta `StartType`, di samping info port/instance yang sudah ada.
- **AC-7**: Given `install.ps1` dijalankan dua kali pada root yang sama (idempotensi, sesuai AC-2 desain 2026-10-02), then registrasi service **tidak gagal dan tidak dobel** — service yang sudah terdaftar dilewati, hanya konfigurasi (XML WinSW) yang disegarkan.
- **AC-8**: Given paket installer, when diperiksa `installer/tests/check-static.ps1`, then cek tersebut **mewajibkan** keberadaan mekanisme service (template XML WinSW di `installer/services/`, pemanggilan registrasi service di `install.ps1`, pemakaian `Start-Service`/`Stop-Service` di `start.ps1`/`stop.ps1`) — **bukan lagi** sekadar melarang scheduled task. Larangan scheduled task (AC-12 desain lama) **tetap dipertahankan** sebagai pagar pengaman, ditambah larangan baru terhadap literal `nssm` (tool yang secara eksplisit tidak dipakai).
- **AC-9**: Given paket installer, then tersedia skrip baru `installer/tests/check-service.ps1` yang memeriksa, pada mesin yang sudah terinstal, bahwa ketiga service **terdaftar** dan **`Running`** (dan `StartType = Automatic`), lalu keluar dengan kode bukan-nol bila ada yang tidak sesuai.
- **AC-10**: Given `installer/petunjuk-penggunaan.md` §3, when dibaca ulang oleh operator, then bagian tersebut menjelaskan model **service** (auto-start, auto-restart, nama service, cara cek lewat `Get-Service`/`check-service.ps1`) — bukan lagi model manual murni — sekaligus tetap mencantumkan kata kunci yang diwajibkan `check-runbook.ps1` (`install.ps1`, `start.ps1`, `stop.ps1`, `status.ps1`, dll., tidak berubah).
- **AC-11**: Given perubahan ini, then **tidak ada** file di `src/` (adapter), kontrak HTTP CI4, atau patch Evolution (`apply-viewonce-patch.ps1`, `apply-lid-preservation-patch.ps1`) yang berubah — perubahan murni di `installer/`.
- **AC-12**: Given literal scan `rg "aulia3|AULIA3|AULIA-SERVER2|D:\\|nssm|NSSM"` pada `installer/`, then tidak ada hasil (konsisten dengan AC-13 desain 2026-10-02, ditambah larangan NSSM).

## 5. Batasan dan di luar cakupan

Batasan:

- Hanya menyentuh **paket installer** (`installer/*`) di repo `evolution-gateway` (lokasi lokal: `C:\Projects\evolution-gateway`). **Tidak** menyentuh skrip produksi `aulia3` yang sudah ada (`scripts/register-services.ps1`, `watchdog-stack.ps1`, `run-*.cmd`, dst.) — itu stack terpisah yang dipakai di `aulia3` saat ini dan di luar cakupan permintaan ini.
- Tool wrapper service: **WinSW** saja. NSSM secara eksplisit **tidak** dipakai (keputusan user).
- Tidak mengubah adapter (`src/`), kontrak HTTP CI4, atau patch Evolution (view-once, LID) — ketiganya tetap seperti sekarang.
- PostgreSQL **sudah** berjalan sebagai Windows Service native (`install-postgres.ps1`, `pg_ctl register`) — tidak diubah; hanya **dimasukkan** ke dalam pengecekan status/verifikasi yang baru.
- Pengujian "reboot PC" dan "kill node.exe" adalah pengujian **manual/E2E** di PC uji oleh operator — bukan test otomatis dalam sandbox dev (konsisten dengan pola AC-1/AC-2/AC-6/AC-7 di desain installer 2026-10-02 yang juga E2E-only).

Di luar cakupan:

- Tidak membuat ulang/menonaktifkan stack scheduled-task `aulia3` yang sudah ada.
- Tidak menambah Docker/Docker Compose (opsi (b) di TODO-Q3d) — eksplisit memilih opsi (a) WinSW.
- Tidak mengubah `docs/CHANGELOG.md` (ini perubahan infrastruktur installer, bukan aturan bisnis).

## 6. Dampak aturan bisnis

- [ ] Mengubah aturan bisnis.
- [ ] Menyentuh data keuangan.
- [ ] Mengubah skema database.
- [ ] Mengubah kontrak POS <-> WA Gateway — **tidak berubah**; perubahan murni di mekanisme start/stop/auto-restart proses, payload/endpoint tetap sama.

Karena tidak ada perubahan aturan bisnis, tidak perlu entri `docs/CHANGELOG.md`.

## 7. Asumsi dan pertanyaan terbuka

Asumsi:

- PC uji sudah terpasang Windows (10/11 x64) dan memenuhi prasyarat `install.ps1` yang sudah ada (admin, internet saat instalasi). Node.js dan PostgreSQL dipasang lewat jalur `Ensure-Node`/`Ensure-Postgres` yang sudah ada di `install.ps1` — **tidak berubah** oleh permintaan ini.
- WinSW diunduh dari GitHub Releases resmi (`winsw/winsw`), dipin ke versi tertentu (`v2.12.0`) demi reproducibility, sama seperti pola pin versi Evolution API (`2.3.7`) dan PostgreSQL (`16.15-1`) yang sudah ada.
- Service berjalan sebagai **LocalSystem** (default WinSW), konsisten dengan task `aulia3` yang sebelumnya berjalan sebagai `SYSTEM`.
- "Restart dalam 30 detik" (AC-3) mengacu pada **Windows melaporkan proses sudah hidup kembali** (service `Running`, proses `node.exe` baru ada), bukan Evolution API sudah selesai boot dingin penuh (yang menurut `watchdog-stack.ps1:34-36` lama bisa sampai ~420 detik pada cold boot dari nol). Pada restart akibat crash (bukan cold boot pertama), dependency (`node_modules`, Prisma client) sudah ada di disk sehingga start ulang jauh lebih cepat dari cold boot.

Pertanyaan: tidak ada — tool (WinSW), lokasi (installer/ saja), dan target uji sudah ditentukan eksplisit oleh user pada permintaan ini.

## 8. Persetujuan (Gate 1)

- [x] Disetujui oleh: user, tanggal: 2026-10-07
