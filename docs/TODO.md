# TODO / Backlog Terpusat

> Baca file ini di **AWAL setiap sesi** sebelum bekerja. Perbarui setiap kali ada
> temuan baru, atau item selesai. Struktur file: **Status → Prioritas → item**.
> Item yang sudah selesai tetap dipertahankan di bagian **Selesai / Ditutup** sebagai
> histori/decision record; jangan dihapus tanpa persetujuan eksplisit.
>
> Format item tetap: `- [ ] **ID** ringkas — prioritas — ref`.
>
> Jangan membuat daftar TODO kedua di tempat lain; checkpoint hanya menunjuk ke sini.

## Belum dikerjakan

### High

(Semua item high selesai)



- [ ] **TODO-BL20** Medium — merge live+arsip via `array_merge` tanpa dedup `id` → double-count saat delete gagal — `Laporan.php:150-153,179-193`; `TransaksiArchiveService.php:705-730`

- [ ] **TODO-BL21** Medium — `masterApply` menjadwalkan user non-aktif, tak transaksional, tanpa cap `jumlah_minggu` — `MasterJadwalModel.php:122-184`; `Jadwal.php:704-734`

- [ ] **TODO-BL22** Medium — CSRF global mati; perubahan state lewat GET (`/transaksi/batal`) — `Filters.php:64-74`; `Routes.php:187`

- [ ] **TODO-BL24** Medium — `parse_no_order()` memetakan dua format tampilan berbeda ke nomor internal sama — `order_helper.php:203-219`

- [ ] **TODO-BL25** Medium — `grand_total=0` pada `piutang`/`draft` → status `belum_bayar` selamanya (invoice Rp 0 tak bisa lunas) — `Api.php:285-290`; `KalkulasiStatusPembayaran.php:39-43`; `Tagihan.php:252-259`

- [ ] **TODO-BL26** Medium — edit path abaikan hasil `insert()` baris detail → silent data loss — `Transaksi.php:1459-1470`

- [ ] **TODO-BL27** Medium — race handler closing menangkap semua exception & retry buta; `$saved` null bisa fatal — `Cash.php:381-394`

- [ ] **TODO-BL28** Medium — baris closing bisa diubah kapan saja tanpa riwayat revisi — `ClosingKasModel.php:86-109`

- [ ] **TODO-BL29** Medium — arti "Pemasukan" beda: net di dashboard, gross di opname — `Views/cash/index.php:110-128` vs `Views/cash/opname.php:16-20`

- [ ] **TODO-BL30** Medium — saldo sistem negatif tak dideteksi/diperingatkan sebelum opname/closing — `CashBalanceService.php:31`; `Cash.php:180-185`

### Low

- [ ] **TODO-Q3a** Gateway test `test/simulate-evolution-adapter.js` GAGAL di assertion "path webhook/set benar" (`captured[0]` dibaca setelah `setWebhook` tanpa reset `captured`) — **pre-existing** di HEAD gateway `b48c2e5`, bukan dari fitur read-receipt; buat `npm test` hijau — rendah — ref temuan sesi 2026-10-07 `feat/whatsapp-read-receipt`.

- [ ] **TODO-Q3b** DB uji `aulia_inboxdb_test` harus disamakan skemanya setiap ada migrasi Inbox baru (mis. kolom `delivered_at`/`read_at` ditambahkan manual 2026-10-07) karena `php spark migrate` tidak membangun DB uji — lemahkan/otomatiskan alur setup agar feature suite tidak gagal `Unknown column` — rendah — ref `docs/ARCHITECTURE.md` §9.

- [ ] **TODO-Q3e** Instance WhatsApp gateway WAJIB `readreceipts: all` (bila `none`, Evolution tetap balas 201 tetapi blue tick tidak pernah terkirim) — sudah dicatat di `docs/deploy.md` §2 & gateway `petunjuk-penggunaan.md` §4.1; usulkan **otomatiskan** saat setup instance (mis. di `scripts/setup-instance.js`) agar tak bergantung cek manual — rendah — ref temuan 2026-10-07.

- [ ] **TODO-Q3i** Low — **Prosedur rollback cutover gateway `aulia7` → `aulia3`**: dokumentasikan langkah memulihkan gateway lama bila cutover dibatalkan — (1) aulia3: `schtasks /change /enable` + `/run` untuk `AuliaEvolution`/`AuliaAdapter`/`AuliaStackWatchdog` (+`AuliaMonitor`); (2) CI4 aulia-server2: kembalikan `inbox.gatewayBaseUrl` ke `http://AULIA3:3000`; (3) Apache aulia-server2: kembalikan `ProxyPass "/realtime-ws"` ke `ws://AULIA3:3000/realtime` + restart Apache; (4) aulia7: stop + disable service `AuliaPosGatewayEvolution`/`AuliaPosGatewayAdapter` agar tak dobel; (5) verifikasi port 3000/8080, status task, dan `gateway_status` CI4. — rendah — ref `docs/requirements/2026-10-09-cutover-gateway-aulia7.md`

## Sedang dikerjakan

### Medium

- [ ] **TODO-S3** Route `/migrasi-manual` masih aktif di produksi AULIA-SERVER2 (dipakai deploy F8 2026-10-05); nonaktifkan/hapus 2 baris route setelah tidak diperlukan — `app/Config/Routes.php:100-101`; `app/Controllers/MigrasiManual.php` — sedang

- [ ] **TODO-Q2** Test gap: belum ada test jalur kirim Gateway (`kirim`, `kirimMedia`, `callGatewaySend*`), `handoffPercakapan()` (290 baris), lifecycle percakapan, `GatewayTokenFilter` — sedang

- [ ] **TODO-Q3** Kontrak cross-repo Gateway: repo gateway **tersedia** di `C:\Projects\evolution-gateway` (branch `evolution`) dan Evolution di `C:\Projects\evolution-api-server` (2 patch lokal: view-once, LID) — verifikasi dua sisi kini memungkinkan. Sisa: belum ada test kontrak formal dua sisi (CI4↔adapter) — sedang

- [ ] **TODO-Q5** God-object & duplikasi render: `Inbox.php` 3721 baris, `Views/inbox/index.php` 3650 baris, daftar percakapan dirender 2× (PHP `index.php:778` vs JS `index.php:1474`) — sedang

- [ ] **TODO-N1** Notifikasi Windows Inbox: **HTTPS self-signed AULIA-SERVER2 sudah aktif** (cert SAN `IP:192.168.1.10`+`DNS:AULIA-SERVER2`, `.env` baseURL→https, HTTP:80 tetap untuk gateway) dan kode `80df50f` sudah ter-deploy ke produksi. **Realtime WS sudah diperbaiki** via proxy same-origin `/realtime-ws` (Apache mod_proxy_wstunnel + `App\\Libraries\\InboxRealtimeWs`). **SISA**: jalankan `\\\\aulia-server2\\xampp\\import-sertifikat-aulia.bat` di tiap PC kasir yang belum, lalu uji E2E notifikasi Windows + realtime di browser kasir — sedang — ref `docs/sesi/2026-10-05-https-self-signed-aulia-server2.md`

- [ ] **TODO-O4** (opsional) `VACUUM` + pantau ukuran disk PostgreSQL Evolution — sedang

- [ ] **TODO-F3** Terapkan ulang patch Evolution (`PATCH-ADAPTER (2026-10-01)` di `whatsapp.baileys.service.ts`) setiap kali Evolution di-upgrade; prosedur `C:\\Projects\\evolution-gateway\\docs\\evolution-viewonce-patch.md` — sedang

- [ ] **TODO-F10** Kesehatan & keandalan gateway **`aulia7`** (eks-`aulia3`, pasca-cutover 2026-10-09): deteksi dini kehilangan pesan masuk (khususnya **media**) akibat kegagalan sesi/dekripsi LID, sekaligus tinjauan log. (Gabungan eks-**TODO-L1**.) — sedang — ref insiden 2026-10-05 pelanggan Mell `6287857570921`, `docs/sesi/2026-10-06-analisa-log-gateway-aulia3.md`.
  - **Perubahan lingkup (2026-10-09)**: gateway produksi kini `aulia7`; task `AuliaMonitor` di `aulia3` sudah **Disabled** dan gateway aulia3 berhenti → **tidak ada monitoring live lagi**. Skrip `monitor-aulia3.ps1` hardcoded ke aulia3 dan **belum dipasang di aulia7** → gateway produksi saat ini **tanpa pemantauan kesehatan** (gap). Log `\\aulia3\D\kilo\logs\` tinggal arsip (read-only), tidak tumbuh.
  - **Monitor aulia7 — SELESAI 2026-10-09**: `scripts/monitor-gateway-aulia7.ps1` (repo `WA-Gateway` @ `b975bfa`) + Scheduled Task `AuliaPosGatewayMonitor` (tiap **15 mnt**, run as **SYSTEM**) aktif; output `C:\AuliaPosGateway\logs\monitor-gateway-aulia7.log`. **Gap "gateway tanpa monitor" tertutup.** Adaptasi dari `monitor-aulia3.ps1`: cek Windows Service (bukan task), parse Evolution log plaintext+ANSI, baca roll WinSW.
  - **Insiden**: Mell mengirim PNG `1_20261005_094116_0000.png` (~6 MB, caption `160x60`) ke **nomor resmi toko `6285155105633`**, tetapi pesan itu **tidak pernah sampai ke perangkat tertaut (Evolution) → tidak ada di gateway → tidak ada di POS**. Terverifikasi berlapis: tidak ada di Evolution `chat/findMessages` (chat tersimpan di bawah LID `224854976532488@lid`; satu-satunya file = `IMG_20261005_095239.jpg` caption `200x50`), tidak ada di `incoming_queue` gateway, tidak ada di media store `D:\\evolution-gateway\\data\\media`, tidak ada di `aulia_inboxdb`. Karena file tak pernah diterima, **tidak bisa dipulihkan dari server** (solusi sementara: minta pelanggan kirim ulang).
  - **Indikasi akar masalah**: Evolution mencatat `SessionError: No matching sessions found for message` (LID `224854976532488@lid`, pesan `AC68ACAC…`) + 2× `Message ignored with messageStubParameters` → sesi Signal per-perangkat tidak lengkap sehingga sebagian pesan (terutama media) gagal didekripsi dan dibuang **sebelum** sampai ke adapter. Kemungkinan diperparah relink nomor 2026-10-04.
  - (histori aulia3) **Monitoring (dipasang 2026-10-05)**: `monitor-aulia3.ps1` mendeteksi `SessionError: No matching sessions found for message`, `failed to decrypt`, `Message ignored with messageStubParameters`, dan `MessageCounterError`; dijalankan tiap **15 menit**; **read-only/alert-only** (tanpa auto-restart atau perubahan session). Window observasi 3 hari.
  - **Hasil sementara 2026-10-06** (`D:\\kilo\\monitor-aulia3.log`, 05 Okt 10:09 → 06 Okt 09:53 WIB): semua sinyal F10 = **0**; 2 event F10 lama (`last=05 Okt 10:07:27 SESSION_NO_MATCH`, pra-monitoring); **1 WARN** false alarm (05 Okt 16:02, `state=(belum ada)` saat alat baru dipasang); `authReject=0`; `evolution.log` tak pernah 0-byte; `flaps` 2→3 akibat restart 08:03 lalu stabil; ada jeda malam 20:23→08:08 (aulia3 mati malam).
  - (histori aulia3) **Lingkup tinjauan log (eks-TODO-L1)** — Checkpoint 2026-10-02 ~13:00 WIB: `adapter.log` & `evolution.log` di `\\\\aulia3\\D\\kilo\\logs\\` dikosongkan ke 0 byte (nonaktifkan `AuliaStackWatchdog` → stop `AULIAADAPTER` & `AuliaEvolution` → `Clear-Content` → start `AuliaEvolution` → start `AULIAADAPTER` → enable watchdog). Verifikasi pasca: port 3000 & 8080 listen; adapter `connected`; Evolution `CONNECTED TO WHATSAPP`. Log lama terarsip di `...\\arsip\\adapter_2026-10-02_1254.log` & `evolution_2026-10-02_1254.log` (baseline). **Yang dicari**: (a) `[AUTH] Request dari CI4 ditolak` & `webhook ditolak: secret tidak cocok/absen`; (b) `dead-letter`/`[CRITICAL]` baru; (c) `[HEARTBEAT-EVOLUTION] … fetch failed` yang tidak kembali `connected`; (d) pertumbuhan ukuran file; (e) `"level":50 "error in sending keep alive"` (berulang = koneksi WA tidak stabil).
    - **Temuan pemantauan 2026-10-05**: (e) **berulang** 4× dalam ~2 hari (02 Okt 13:14; 03 Okt 08:11 & 10:33; 04 Okt 14:17 WIB) — tiap kali diikuti `stream errored out`/`unexpected error in 'init queries'` lalu **pulih sendiri**. **(a) bersih** (tidak ada auth reject; `EVOLUTION_WEBHOOK_SECRET` terisi 64 char). **LOGOUT instance** 2026-10-04 11:59:31 WIB lalu relink ke nomor resmi `6285155105633` — **disengaja**, bukan anomali. Burst `[OnWhatsappCache] Error processing item …` 1746 baris (04 Okt 12:00, sekali jalan). `error in handling message` 1× (04 Okt 12:00, pesan `@lid` `pkmsg` tak terdekripsi). `evolution.log` sempat 0 byte pasca-rotate 06:57 (rotasi saat Evolution hidup).
  - **Verifikasi infrastruktur (eks-TODO-L1, sesi 2026-10-03)**: (i) rotasi log `arsip\\adapter_*.zip` & `evolution_*.zip` + `rotate-logs.log`; (ii) prune retensi (`[MAINTENANCE] … dipangkas`: media 180 hari, kutipan 7 hari, `incoming_queue` completed 30 hari); (iii) `phone` backfill tetap terisi setelah restart; (iv) task `AuliaLogRotate` ada & sukses; (v) backup harian `D:\\backup\\aulia3\\{pg,sqlite,media,env}` + `pg_restore -l` valid; (vi) exclusion Avast `D:\\evolution-gateway` masih ada.
  - **Yang boleh diabaikan**: skenario uji 1 Okt (dead-letter `KILO-MX-17` "koordinat tidak valid", "Field 'text' wajib diisi"), `PERINGATAN SECURITY bind 0.0.0.0`, transisi `connecting→connected` yang recover, `body request terlalu besar` (>64MB), spam `CACHE: { cached: undefined, … }`. Jendela uji disengaja: 2026-10-02 14:08:37–14:11:23 (uji backlog media) & stress test 14:17:05–14:28:00 (~209 pesan backlog).
  - **Temuan 2026-10-06 (analisa log)**: 9 baris `outgoing_operations` `in_flight` = kiriman media berkutipan gagal 04–05 Okt (error Evolution `TypeError: … 'fromMe'` di `generateWAMessageFromContent`); **sudah diperbaiki** commit gateway `b48c2e5`; isi 9 pesan gagal tidak tersimpan → lihat TODO-L3. Ref `docs/sesi/2026-10-06-analisa-log-gateway-aulia3.md`.
  - **Pekerjaan yang diusulkan**: (1) alarm sudah dipasang; (2) opsi perbaikan sesi: restart instance `aulia-toko` (rebuild sesi LID) atau upgrade Baileys bila bug dekripsi LID dikenal; (3) putuskan perlu-tidaknya pemeriksaan gap berkala (rekap pesan masuk Evolution vs `incoming_queue` per hari) + dokumentasikan prosedur verifikasi.
  - **Review ulang 2026-10-11**: pertanyaan "apakah berdampak pada rencana pindah gateway ke Windows Service" **sudah terjawab** — migrasi ke Windows Service (WinSW) selesai 2026-10-09 di aulia7. Sisa terbuka: putuskan remediation sesi/LID + perlu-tidaknya pemeriksaan gap berkala, kini untuk **aulia7**.
  - **Monitor aulia7**: **SELESAI 2026-10-09** — dipasang & terverifikasi (lihat bullet "Monitor aulia7 — SELESAI 2026-10-09" di atas).

- [ ] **TODO-L2** Tutup/terminalize baris `outgoing_operations` berstatus `in_flight` yang basi saat adapter start (saat ini hanya **dicatat**, `outgoingOperationService.js:396`), supaya tidak mengendap & muncul terus di ringkasan startup — perubahan gateway (`src/store/outgoingOperations.js` / `src/delivery/outgoingOperationService.js`) — higiene; perlu persetujuan — ref `docs/sesi/2026-10-06-analisa-log-gateway-aulia3.md`
- [ ] **TODO-L3** Auditabilitas kiriman keluar yang **gagal**: gateway sengaja hanya simpan `payload_hash` (SEC-001) dan POS hanya menulis baris `messages` setelah kirim sukses (`Inbox.php:1594→1642`), sehingga isi kiriman gagal tak bisa diaudit/dilihat. **Perlu keputusan**: perlukah POS mencatat baris `messages`/status `failed` saat kirim gagal (agar kasir & investigasi punya jejak), atau diterima by-design. — rendah/sedang — ref `docs/sesi/2026-10-06-analisa-log-gateway-aulia3.md`


## Selesai / Ditutup

- [x] **TODO-BL33** Low — penomoran invoice `random_int(1,999)` per hari; tanpa idempotency key — `Api.php:420-442` — **DEC-4: Opsi A** → sekuens per hari `INV-YYYYMMDD-NNN` via lock/transaksi — **SELESAI 2026-10-06**

- [x] **TODO-F11** Medium — Ambil Alih percakapan via tombol: non-admin boleh takeover saat owner off-shift (grace 30 menit via `last_seen_by_assignee_at`) atau bila pengambil = Shift Leader aktif; flag `bisa_diambil` di daftar; guard race `WHERE assigned_to = <owner lama>`. Perluasan izin dibatasi pada titik takeover — `cekOwnership` TIDAK diubah. **Perlu keputusan lanjutan**: (a) apakah takeover perlu dicatat di `conversation_handoffs`/audit trail; (b) apakah grup perlu kebijakan takeover sendiri. Ref `docs/CHANGELOG.md` 2026-10-06; test `tests/feature/InboxAmbilAlihTest.php`. — **SELESAI 2026-10-06** (implementasi inti selesai; dua sub-pertanyaan (a)/(b) masih terbuka)

- [x] **TODO-O5** Low — **DITUTUP: kedaluwarsa** — prosedur update adapter di `aulia3` (non-git, npm manual, restart via task) tak lagi relevan setelah cutover ke `aulia7` (repo git + Windows Service WinSW). Lihat TODO-Q3h.

- [x] **TODO-Q3c** — **DITUTUP: kedaluwarsa** — keandalan auto-start Scheduled Task di `aulia3` tak lagi relevan (aulia3 pensiun sebagai gateway); digantikan Windows Service WinSW di aulia7 (TODO-Q3h).

- [x] **TODO-Q3d** — **DITUTUP: terlaksana** — gateway dipindah ke PC lain (`aulia7`) sebagai Windows Service WinSW. Lihat TODO-Q3h.

- [x] **TODO-Q3f** — **DITUTUP: digantikan** — rencana cutover aulia3 -> WinSW tercakup oleh TODO-Q3h (selesai 2026-10-09).

- [x] **TODO-Q3g** Medium — **DITUTUP: diimplementasikan** — installer Git-based (`Ensure-GitSource`) dipakai untuk cutover aulia7 (TODO-Q3h); source ter-pin di `evolution` @ `4e73d0de`. Residual opsional: uji E2E fresh-install di PC tanpa Git belum dilakukan.

- [x] **TODO-Q3h** Medium — **Cutover gateway produksi `aulia3` → `aulia7` — SELESAI 2026-10-09** (switchover, bukan paralel).
  - aulia7: PostgreSQL 16.15 (service `postgresql-aulia7`) + Evolution API tag `2.3.7` + adapter (branch `evolution` @ `4e73d0de`) sebagai Windows Service WinSW (`AuliaPosGatewayEvolution`/`AuliaPosGatewayAdapter`); IP static `192.168.1.68`; firewall 3000 hanya dari `192.168.1.10`, 8080/5432 blok eksternal.
  - Instance `aulia-toko` di-pairing ulang di aulia7 → state `open`, nomor `6285155105633`; `gateway_status` CI4 = `connected` (terverifikasi di `aulia_inboxdb`).
  - CI4: `inbox.gatewayBaseUrl` → `http://192.168.1.68:3000`; Apache `ProxyPass "/realtime-ws"` → `ws://192.168.1.68:3000/realtime`; realtime WebSocket (4 klien) terverifikasi terhubung.
  - aulia3: task `AuliaAdapter`/`AuliaEvolution`/`AuliaStackWatchdog`/`AuliaMonitor` **Disabled** (tidak di-unregister, demi rollback); port 3000/8080 mati.
  - aulia-server2 (POS CI4): `git pull` ke `a55d86e` (fast-forward) + migrasi `cash_expense_audit` & `message_send_audit` diterapkan ke production.
  - Backup DB production pra-deploy: `C:\xampp\backup-db-aulia-server2\`.
  - Rollback: lihat TODO-Q3i.
  - Ref: `docs/requirements/2026-10-09-cutover-gateway-aulia7.md`.

- [x] **TODO-S2** Login: rate-limit/lockout — **DITUTUP: accepted risk (DEC-S2)**.
  - Alasan: aplikasi POS hanya diakses dari **LAN internal** (dikonfirmasi user 2026-10-09), tanpa eksposur internet; `prosesLogin()` (`Auth.php:64-117`) dibiarkan tanpa throttling/lockout.
  - `session()->regenerate()` (fixation) sudah ada (`Auth.php:108`).
  - Mitigasi murah yang **belum** dikerjakan (opsional, hanya bila suatu saat diekspos ke luar LAN): pesan error generik "Username atau password salah" untuk menutup enumeration (`Auth.php:81,86`) + `Throttler` bawaan CI4 per-IP.

- [x] **TODO-F9** Kasir bisa mengerjakan percakapan `belum_diambil` tanpa meninggalkan jejak "sedang dikerjakan" → **ditutup dengan nudge manual** (bukan auto-assign).
  - **Keputusan**: dorong kasir menekan `Ambil` lewat nudge. TIDAK auto-assign `assigned_to`, tidak tambah status queue, tidak ubah skema, tidak tambah writer ke `assigned_to`/`last_seen_by_assignee_at`.
  - **Nudge 1 (banner)** — `app/Views/inbox/index.php`: banner non-modal tepat di atas textarea balasan (di luar area scroll), teks "Mau diambil, atau lihat-lihat saja?", tombol [`Ambil`]/[`Lihat saja`]. Muncul 7 dtk setelah buka percakapan `belum_diambil`; boleh muncul lagi setelah pindah conversation dan kembali (state `nudgeAmbilTampilUntukId` di-reset saat `pilihConversation()`). `[Lihat saja]` HANYA menutup banner, tidak mencatat note.
  - **Nudge 2 (modal unduh)** — `app/Views/inbox/index.php`, `public/assets/js/inbox-thread.js`: konfirmasi sebelum unduh media pada percakapan `belum_diambil`/dipegang orang lain; tombol `[Ambil & Unduh]`/`[Unduh saja]`/`[Batal]` + cabang ambil-alih bila grace lewat; `[Unduh saja]` mencatat note internal `[auto]` (best-effort).
  - **Backend** — `app/Controllers/Inbox.php` (`Inbox::catatNudgeUnduh()`), `app/Config/Routes.php`: `POST /inbox/percakapan/(:num)/nudge-unduh`; note `[auto]` server-side, coalesce 30 mnt per (conversation, user), tolak 409 `{ok:false, reason:'already_assigned'}` bila percakapan sudah punya pemilik. `catatanInternal()` tidak diubah.
  - **Verifikasi**: `tests/feature/InboxNudgeUnduhTest.php` (8 test) lulus; JS `tests/js/inbox-thread.test.js` 60/60; suite unit 82 / integration 26 / feature 133 lulus. Uji browser manual (11+ skenario interaksi) oleh user: **PASS (2026-10-09)**.
  - **Status**: **SELESAI & LIVE di produksi** (koreksi 2026-10-10 — catatan "belum commit/deploy" sudah basi). Commit `8172043` ter-include di deploy `a55d86e` (2026-10-09); terverifikasi 1 baris catatan `[auto]` di `aulia_inboxdb.messages` tercatat 2026-10-10 08:28:33.
  - Ref prior: `docs/requirements/2026-10-03-unduh-media-inbox.md`.

- [x] **TODO-BL10** High — mutasi kas tak admin-gated; pengeluaran terima tanggal sembarang (termasuk lampau/depan) — `AuthFilter.php:70`; `Routes.php:231-246`; `Cash.php:439,547` — **DEC-1: Opsi B** → bukan admin-gating; dikerjakan = rekam audit + validasi tanggal pengeluaran
  - **DONE — code, migration, and tests verified; deployed to production 2026-10-09 (`git pull` ke `a55d86e` + migrasi `cash_expense_audit` diterapkan).**
  - Keputusan: audit = tabel terpisah `cash_expense_audit` (JSON before/after,
    Opsi B+A); tanggal = tolak masa depan & tolak tanggal yang sudah
    `closing_kas` (Opsi A); kunci closing berlaku untuk update **dan**
    delete (Opsi A).
  - File baru: `app/Database/Migrations/2026-10-08-000001_CreateCashExpenseAuditTable.php`,
    `app/Models/CashExpenseAuditModel.php`.
  - File diubah: `app/Models/CashExpenseModel.php` (`validasiTanggal()`,
    `updatePengeluaran()`, `hapusPengeluaran()` sekarang transaksional +
    menulis audit + wajib `$userId`), `app/Controllers/Cash.php`
    (`cekTanggalSudahClosing()` + guard di `tambahPengeluaran()`,
    `updatePengeluaran()`, `hapusPengeluaran()`).
  - **Self-review menemukan & memperbaiki bug**: `updatePengeluaran()`/
    `hapusPengeluaran()` semula tidak memeriksa hasil `update()`/`delete()`
    sebelum menulis audit & commit — kegagalan validasi model (bukan error
    DB) bisa lolos sebagai "sukses" dan menulis audit palsu. Diperbaiki
    dengan cek hasil eksplisit + `transRollback()` manual; ditambah test
    regresi.
  - Test: `tests/unit/CashExpenseValidasiTanggalTest.php` (4 kasus),
    `tests/integration/CashExpenseAuditTest.php` (4 kasus, termasuk regresi
    bug di atas), `tests/integration/CashExpenseClosingLockTest.php`
    (5 kasus) — semua lulus; regresi penuh `phpunit.xml` (82 tests) &
    `phpunit.integration.xml` (26 tests) tetap lulus.
  - Database: migrasi **belum** dijalankan ke database produksi (menunggu
    jadwal deploy terpisah); tidak ada data existing yang disentuh.
  - Ref: `docs/requirements/2026-10-08-audit-validasi-kas-keluar.md`,
    `docs/design/2026-10-08-audit-validasi-kas-keluar.md`,
    `docs/CHANGELOG.md` 2026-10-08.

- [x] **TODO-BL18** Medium — saat filter kategori, `grand_total` pro-rata tapi `sisa_tagihan` penuh → piutang overstated — `Laporan.php:1043-1061,1087`
  - **DONE — code fix and regression tests verified**
  - Commit: `745524a`
  - Root cause: Line 1087 menggunakan `$t['grand_total']` (invoice penuh) padahal `$grandTotal` sudah di-pro-rata per kategori.
  - Fix: `sisa_tagihan = (invoice_sisa) * (kategori_grand_total / invoice_grand_total)` untuk pro-rata pembayaran per kategori.
  - Regression tests all pass:
    - `LaporanBL18Test::testMultiKategoriPartialPaymentProrata()` ✓
    - `LaporanBL18Test::testMultiKategoriWithDiskonProrata()` ✓
    - `LaporanBL18Test::testWithoutKategoriFilterUnchanged()` ✓
    - `LaporanBL18Test::testEmptyDetailSkipped()` ✓
  - Database safety:
    - Production DB untouched (14,876 transaksi unchanged)
    - Test only, read-only fix
  - Backward compatibility:
    - Behavior tanpa kategori filter unchanged
    - BL19 (atribusi kategori) tidak disentuh

- [x] **TODO-BL15** Medium — Harian (basis kas) vs Periode/Kategori (akrual) tidak sinkron untuk tanggal sama — `Laporan.php:60-64` vs `:123-130`
  - **CLOSED / BY DESIGN — DEC-2**
  - Perbedaan basis kas (Harian) vs akrual (Periode/Kategori) diterima sebagai desain sistem.
  - Logic laporan tidak diubah.

- [x] **TODO-BL16** Medium — `exportExcel(jenis=harian)` memakai jalur akrual, beda dari tabel Harian — `Laporan.php:1433,1490-1541`
  - **CLOSED / NOT PRIORITY — DEC-2**
  - Export `jenis=harian` tidak menjadi kebutuhan aktif; penggunaan hanya tab Bulanan.
  - Logic export tidak diubah.

- [x] **TODO-BL19** Medium — atribusi kategori campur master-produk & `detail_transaksi.kategori_id`; pembulatan tak direkonsiliasi — `Laporan.php:869,1247-1251,1288-1305`
  - **CLOSED — TWO PARTS: BL19-A FIXED + BL19-B BY DESIGN**
  - Commit BL19-A: `0d70b5d`

  **BL19-A: Category Attribution (FIXED)**
  - Root cause: `processPerKategori()` and `processBulanan()` used `produk.kategori_id` (current master) instead of `detail_transaksi.kategori_id` (historical snapshot).
  - Fix: Changed both functions to use `$d['kategori_id']` from detail record (snapshot at transaction time).
  - Impact: 12 affected transactions restored to correct historical categorization (e.g., INV-20260830-078 now shows 2 kategoris instead of 1).
  - Tests: 4 BL19 code inspection tests pass; BL18 regression tests (4/4) pass; all unit tests (78/78) pass; all integration tests (17/17) pass.
  - Database: No writes; production untouched; read-only fix only.
  - Backward compatibility: Normal cases (detail.kat == master.kat) unchanged.

  **BL19-B: Rounding Reconciliation (BY DESIGN — NO CODE CHANGE)**
  - Issue: INV-20260909-586 shows SUM(kategori netto) = 105990 vs grand_total = 105900 (difference = 90).
  - Root cause: NOT a defect. Rounding happens once at invoice level (KalkulasiDiskonTransaksi.php: `floor(pre-rounding/100)*100`).
  - Category allocation uses pre-rounding amounts; selisih_pembulatan captured at invoice level only, not distributed to categories.
  - Business rule: Verified from code + tests + source comment (Laporan.php:1290-1291). Intentional: categories are analytical allocation, not accounting reconciliation.
  - Data integrity: All 15 sample transactions (multi-kategori with discount) reconcile exactly via selisih_pembulatan. No unexplainable discrepancies.
  - Test invariant (KalkulasiDiskonTransaksiTest): `grand_total + diskon + selisih_pembulatan == subtotal` passes 100+ test cases.
  - Audit: `AUDIT-BL19B.md` documents full analysis; no code change required.
  - Status: Mathematically sound, consistent behavior, intentional design. No fix needed.

- [x] **TODO-BL14** Medium — dua sumber `total_dibayar` (kolom cache vs jumlah pembayaran aktif) bisa berbeda — `Tagihan.php:94-96` vs `:180-181`; `Laporan.php:1076`
   - **DONE — code fix deployed to production 2026-10-09 (`git pull` ke `a55d86e`); data repair TIDAK PERLU DIJALANKAN (lihat verifikasi 2026-10-10).**
   - Commit final: `720d800`
   - Root cause: nested transactions dalam `Api::koreksiPembayaran()` menyebabkan `sinkronkanPembayaran()` membaca interim state (pembayaran lama aktif + pembayaran baru aktif = 2× cache).
   - Fix: removed nested transaction, single atomic boundary via `transBegin()` → UPDATE reversed → INSERT aktif → sync → `transComplete()`.
   - Regression tests all pass:
     - `KalkulasiStatusPembayaranTest`: 9 tests, 11 assertions ✓
     - `RepairTotalDibayarTest`: 2 tests, 5 assertions ✓
     - `TransaksiPembayaranStatusTest`: 3 tests, 16 assertions ✓
     - `TransaksiSimpanAtomikTest`: 9 tests, 26 assertions ✓
     - Full phpunit.integration.xml: 17 tests, 69 assertions ✓
   - **Verifikasi data produksi 2026-10-10**: query pemeriksaan `aulia:repair-total-dibayar`
     (mode cek, tanpa `--fix`) direplikasi langsung ke `aulia_kasirdb` produksi via MySQL
     client (15.717 transaksi total, 4.840 di rentang Sep–Okt 2026) — hasil **0 baris
     inkonsisten**. 12 transaksi yang sebelumnya teridentifikasi tidak lagi ditemukan;
     kemungkinan sudah terkoreksi lewat jalur lain sebelum verifikasi ini, atau catatan
     "12" berasal dari snapshot analisis pra-deploy yang sudah tidak merepresentasikan
     state sekarang. **Tidak ada tindakan repair yang diperlukan.**
   - Database safety:
     - Production DB untouched (query read-only saja)
     - `aulia:repair-total-dibayar --fix` tidak dijalankan — tidak ada data untuk diperbaiki
     - Overpayment cases (3) remain out of scope (TODO-BL18 separate issue)

## Catatan struktur

- **Status utama:** Belum dikerjakan → Sedang dikerjakan → Selesai / Ditutup.
- **Prioritas di dalam status:** High → Medium → Low → Tanpa prioritas.
- **Selesai / Ditutup** mempertahankan item `[x]` untuk histori; penghapusan tetap memerlukan approval eksplisit.
