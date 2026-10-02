# Requirements: Paket Instalasi Gateway WhatsApp untuk PC Baru

- **Tanggal**: 2026-10-02
- **Status**: terimplementasi (implementasi selesai & 6 cek lokal lulus 2026-10-02; verifikasi E2E di PC baru/VM belum)
- **Tier SDLC**: A (artefak baru, lintas repositori gateway <-> AuliaPos)
- **Penanggung jawab**: —
- **Terkait**:
  - `C:\Projects\evolution-gateway\scripts\*` (toolkit provisioning yang sudah ada, hardcode aulia3)
  - `C:\Projects\evolution-gateway\docs\evolution-viewonce-patch.md` (TODO-F3)
  - `docs/sesi/2026-10-01-produksi-aulia3-inbox-tipe-pesan.md`, `docs/sesi/2026-10-01-evolution-gateway-adapter.md`
- **Keputusan user 2026-10-02** (scoping): **gateway-only** (AuliaPos tetap di server lain), **bootstrap online** (bukan paket offline), **start manual** (tanpa scheduled task / watchdog).

## 1. Tujuan

Menyediakan satu paket instalasi berbasis skrip (bootstrap online) yang memasang seluruh stack gateway WhatsApp — PostgreSQL 16 + Evolution API 2.3.7 + adapter `evolution-gateway` — di PC Windows baru yang terpisah dari AuliaPos, supaya operator dapat menyiapkan PC gateway baru dengan satu pintu masuk, tanpa mengikuti rangkaian skrip manual yang path/host-nya di-hardcode ke aulia3.

Paket juga menyertakan **petunjuk penggunaan** (runbook Bahasa Indonesia) untuk operator, agar instalasi dan pengoperasian harian tidak bergantung pada orang yang menulis skrip.

## 2. Kondisi saat ini (terverifikasi)

Toolkit provisioning sudah ada dan terbukti di aulia3, di `C:\Projects\evolution-gateway\scripts\`:

| Skrip | Fungsi | Hardcode ke aulia3 |
|---|---|---|
| `setup-env.ps1` | generate API key + tulis `.env` adapter & Evolution | `D:\evolution-gateway`, `D:\evolution-api-server`, `D:\WA-Gateway\.env`, `AULIA-SERVER2`, `AULIA3`, instance `aulia-toko` (`:23-36`) |
| `install-postgres.ps1` | `initdb` cluster + service + role/db | `D:\pgsql16`, service `postgresql-aulia3` (`:27-34`) |
| `create-instance.ps1` | buat instance `WHATSAPP-BAILEYS` | `D:\evolution-api-server\.env`, `http://127.0.0.1:8080`, `aulia-toko` (`:15-18`) |
| `enable-webhook-secret.ps1` | tulis secret + daftar webhook + restart | `D:\evolution-gateway`, `D:\node\node.exe`, `D:\kilo` (`:20-24`) |
| `allow-lan-ports.ps1` | firewall inbound 3000/8080 terbatas IP | sumber default `192.168.1.10` (`:27`) |
| `register-services.ps1` | task AtStartup SYSTEM + watchdog | `D:\evolution-gateway`, `D:\evolution-api-server`, `D:\kilo` (`:62-64`) |
| `start-stack.ps1` | jalankan Evolution + adapter | `D:\node\node.exe`, `D:\evolution-api-server`, `D:\evolution-gateway` (`:18-21`) |
| `watchdog-stack.ps1` | sembuhkan komponen mati tiap 5 menit | `D:\pgsql16`, `postgresql-aulia3`, `D:\evolution-gateway` (`:25-37`) |
| `restart-adapter.ps1`, `status-*.ps1`, `run-*.cmd`, `set-webhook.js`, `setup-instance.js` | operasional | aulia3 |

Celah yang menghalangi PC baru (terverifikasi di sesi ini):

1. **Semua path/host/port/service-name/nama instance di-hardcode** ke aulia3 (lihat tabel) — bukan parameter.
2. **Prasyarat tidak disiapkan skrip mana pun**: runtime Node, binari PostgreSQL di `PgRoot\bin`, sumber Evolution, dan `node_modules` kedua repo.
3. **`setup-env.ps1:25,:115` mengambil `CI4_GATEWAY_TOKEN` dari `D:\WA-Gateway\.env`** (gateway lama aulia3). Di PC baru file itu tidak ada.
4. **`setup-env.ps1:105,:110` membaca file acuan `C:\Projects\evolution-api-server\.env.template`** — terverifikasi `Test-Path = False`; `setup-env.ps1` akan gagal di mesin baru.
5. **Belum ada satu pintu orkestrasi** yang menjalankan urutan env -> postgres -> start -> instance -> webhook -> firewall, plus verifikasi akhir.
6. **Patch view-once (TODO-F3)** wajib diterapkan manual ke `whatsapp.baileys.service.ts` (`docs/evolution-viewonce-patch.md`).

Fakta sumber yang terverifikasi:

- Sumber Evolution API: remote `https://github.com/evolution-foundation/evolution-api.git`, tag **`2.3.7`** (detached HEAD `cd800f2`).
- Sumber adapter: remote `https://github.com/tikusgot007/WA-Gateway.git`, branch **`master`** (HEAD `c4075c5`, memuat TODO-F4/F5).
- Adapter telah punya `.env.example` (`C:\Projects\evolution-gateway\.env.example`) dengan kunci `CI4_BASE_URL`, `CI4_GATEWAY_TOKEN`, `EVOLUTION_*`, `PORT`, `SQLITE_PATH`, `MEDIA_STORE_DIR`, `EVOLUTION_WEBHOOK_SECRET`.
- Di PC dev ini sudah terpasang Node `v22.23.2` (syarat adapter `>=20`) dan PostgreSQL 16 di `C:\Program Files\PostgreSQL\16`.

**Belum diverifikasi**: apakah `npm ci` Evolution menghasilkan `better-sqlite3` prebuilt yang cocok dengan Node LTS PC baru (native addon — risiko build tools bila prebuilt tidak tersedia); bentuk unduhan resmi binari PostgreSQL 16 untuk Windows (zip binari vs installer EDB) yang paling andal untuk bootstrap.

## 3. User story

- Sebagai **operator/admin gateway**, saya ingin menjalankan satu skrip instalasi di PC baru dengan beberapa parameter saja, supaya gateway siap tanpa salah path/host dan tanpa mengedit skrip.
- Sebagai **operator**, saya ingin bisa `start`/`stop` dan melihat `status` stack secara manual, supaya saya bisa menguji tanpa mengubah task sistem PC.
- Sebagai **operator**, saya ingin petunjuk penggunaan yang jelas (prasyarat, langkah pasang, cara start/stop, pairing QR, verifikasi, penanganan masalah), supaya saya bisa memasang dan mengoperasikan gateway tanpa bertanya ke pengembang.
- Sebagai **admin AuliaPos**, saya ingin nilai `inbox.gatewayBaseUrl` + `inbox.gatewayToken` yang harus dipakai tercatat jelas, supaya saya bisa mencocokkannya di `.env` server POS.

## 4. Acceptance criteria

- **AC-1**: Given PC Windows x64 bersih dengan internet + PowerShell admin, when `install.ps1` dijalankan dengan parameter valid (`-Ci4BaseUrl`, `-Ci4GatewayToken`, `-LanSources`, `-InstallRoot`, `-InstanceName`), then instalasi selesai tanpa mengedit file/skrip secara manual dan keluar dengan kode 0.
- **AC-2**: Given Node LTS dan/atau binari PostgreSQL 16 sudah ada, when instalasi diulang, then langkah tersebut dilewati (idempotent), bukan dipasang ganda atau gagal.
- **AC-3**: Given instalasi baru, when selesai, then sumber Evolution API berada tepat pada tag `2.3.7` dan sumber adapter pada branch `master`, dan `npm ci` kedua repo selesai tanpa error.
- **AC-4**: Given sumber Evolution yang baru diambil, when instalasi dijalankan, then patch view-once diterapkan otomatis dan idempotent (dijalankan dua kali tidak menggandakan blok patch).
- **AC-5**: Given parameter token/URL, when `.env` dihasilkan, then `EVOLUTION_API_KEY` adapter sama persis dengan `AUTHENTICATION_API_KEY` Evolution, `CI4_GATEWAY_TOKEN` sama dengan parameter `-Ci4GatewayToken`, dan tidak ada secret yang tercetak ke layar/log.
- **AC-6**: Given instalasi baru, when selesai, then Windows service PostgreSQL berstatus Running, role aplikasi + database Evolution dibuat, dan login role memakai password dari `.env` terverifikasi berhasil (perilaku `install-postgres.ps1` yang sudah ada).
- **AC-7**: Given stack terpasang, when `start` dijalankan, then Evolution listen di 8080 dan adapter listen di 3000; when `stop` dijalankan, then keduanya berhenti; both idempotent.
- **AC-8**: Given Evolution berjalan, when instance dibuat, then `GET /instance/connectionState/<instance>` merespons; bila belum `open`, output menunjuk ke langkah QR manual (QR/pairing code **tidak** pernah dicetak atau disimpan otomatis).
- **AC-9**: Given instance `open`, when webhook didaftarkan, then webhook terdaftar membawa header `EVOLUTION_WEBHOOK_SECRET`, dan request webhook tanpa secret ditolak adapter.
- **AC-10**: Given `-LanSources` berisi daftar IP, when firewall dikonfigurasi, then inbound TCP 3000 hanya diizinkan dari IP itu pada profil Private; given `-LanSources` kosong, then skrip menolak (tidak membuka ke semua).
- **AC-11**: Given instalasi selesai, when `status` dijalankan, then ringkasan pg/evolution/adapter/port tercetak; dan sebuah smoke test pengiriman pesan (`test-send`) berhasil.
- **AC-12**: Given paket instalasi, when dijalankan, then tidak ada scheduled task/watchdog yang didaftarkan (sesuai keputusan "manual"), dan tidak ada file di repo `aulia-app` atau data produksi yang diubah.
- **AC-13**: Given `rg "aulia3|D:\\\\|AULIA3|AULIA-SERVER2|192\\.168\\.1\\.10"` pada paket, when diperiksa, then tidak ada sisa nilai aulia3 yang tidak dapat di-override lewat parameter; semua path/host/port/service-name/nama instance memakai default netral.
- **AC-14**: Given paket instalasi, when dibuka, then tersedia **petunjuk penggunaan** berbahasa Indonesia yang memuat minimal: prasyarat, daftar parameter instalasi, urutan langkah, cara `start`/`stop`/`status`, langkah pairing QR lewat Evolution Manager, verifikasi (smoke test `test-send`), konfigurasi sisi AuliaPos (`inbox.gatewayBaseUrl` + `inbox.gatewayToken`), dan bagian troubleshooting untuk kegagalan umum (port 3000/8080 terpakai, PostgreSQL belum siap, instance belum `open`, `better-sqlite3` gagal dibangun).
- **AC-15**: Given petunjuk penggunaan, when diikuti apa adanya oleh operator tanpa akses ke `docs/sesi/` atau riwayat chat, then instalasi dan start berhasil; setiap perintah di dalamnya memakai path/host yang sudah diparameterkan (bukan literal aulia3).

## 5. Batasan dan di luar cakupan

Batasan:

- Windows x64 saja (PowerShell). Bukti & toolkit seluruhnya Windows.
- **Bootstrap online**: butuh internet saat pemasangan (keputusan user). Bukan paket offline/zip bundel.
- **Gateway-only**: paket hanya memasang PostgreSQL + Evolution + adapter. AuliaPos (CI4 + MySQL) tetap di server lain dan tidak dipasang/diubah oleh paket ini.
- **Start manual**: tanpa scheduled task / watchdog (keputusan user). Tidak ada auto-start saat boot.
- Instalasi bersih: tidak memigrasikan data/instance lama; nomor WhatsApp di-pair ulang lewat QR.

Isi paket (deliverable):

- Skrip orkestrasi `install.ps1` + skrip operasional (`start`/`stop`/`status`).
- Template `.env` adapter & Evolution yang diparameterkan.
- Berkas patch view-once + penerap otomatisnya.
- **Petunjuk penggunaan** (runbook Bahasa Indonesia) — lihat AC-14/AC-15.

Tidak termasuk cakupan:

- Pemasangan/konfigurasi AuliaPos dan MySQL di sisi POS (hanya didokumentasikan: `inbox.gatewayBaseUrl` + `inbox.gatewayToken`).
- Otomatisasi scan QR / pairing nomor WhatsApp (sengaja manual, demi keamanan).
- Registrasi task terjadwal + watchdog (`register-services.ps1`) — sengaja tidak dipakai.
- Backup terjadwal & rotasi log (`TODO-O1`, `TODO-O3`) dan prune media/kutipan (`TODO-O2`) — di luar cakupan.
- Perbaikan bug gateway lain (`TODO-F2`, `TODO-F6`) dan pembersihan data uji (`TODO-H1`).
- Mode Cloud API / BSP (tetap `WHATSAPP-BAILEYS`, unofficial, risiko ban tetap ada).

## 6. Dampak aturan bisnis

- [ ] Mengubah aturan bisnis.
- [ ] Menyentuh data keuangan.
- [ ] Mengubah skema database aplikasi (paket membuat DB gateway baru, bukan skema AuliaPos).
- [x] Menyentuh kontrak POS <-> WA Gateway — **tetap sama**; paket hanya memasang ulang sisi gateway dan mendokumentasikan konfigurasi sisi POS. Tidak ada perubahan bentuk payload/endpoint.

Karena tidak ada perubahan aturan bisnis, tidak perlu entri `docs/CHANGELOG.md`. Perubahan ini murni infrastruktur.

## 7. Asumsi dan pertanyaan terbuka

Asumsi:

- PC baru: Windows 10/11 x64, PowerShell 5.1+, hak admin, akses internet.
- Sumber kode tersedia dari GitHub publik (`evolution-foundation/evolution-api`, `tikusgot007/WA-Gateway`).
- `InstallRoot` dapat diatur (default netral, mis. `C:\AuliaGateway`); drive D: bukan keharusan.
- Server AuliaPos dapat dijangkau dari PC gateway lewat LAN (IP/hostname diberikan sebagai parameter `-Ci4BaseUrl`).

Pertanyaan: tidak ada — tiga keputusan scoping sudah dijawab user 2026-10-02 (gateway-only, bootstrap online, manual start).

## 8. Persetujuan (Gate 1)

- [x] Disetujui oleh: user, tanggal: 2026-10-02 (plus tambahan "petunjuk penggunaan")
