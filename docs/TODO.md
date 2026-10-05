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

- [ ] **TODO-BL08** High — edit path hitung `$hasKategori16` tapi tak dipakai; `no_order` ditulis tanpa lock/cek unik — `Transaksi.php:1288-1317,1444-1453`

- [ ] **TODO-BL09** High — `cash_opname.pemasukan_tunai` diisi `kas_awal + penjualan` → kas awal dobel — `Cash.php:216-219`; `CashBalanceService.php:27`

- [ ] **TODO-BL11** High — kas awal bisa di-insert dua kali (select-then-insert, tanpa unique key) → saldo membengkak — `CashBalanceService.php:49-73`

- [ ] **TODO-BL12** High — `alasan_selisih` opname dipaksa string tetap read-only → validasi selisih tak bermakna — `Views/cash/index.php:795-810`; `Cash.php:196-201`

### Medium


### Medium

- [ ] **TODO-BL13** Medium — `sisa_tagihan` tak di-clamp → bisa negatif & distorsi `total_piutang` — `Tagihan.php:180-181,252`; `Laporan.php:1076`

- [ ] **TODO-BL14** Medium — dua sumber `total_dibayar` (kolom cache vs jumlah pembayaran aktif) bisa berbeda — `Tagihan.php:94-96` vs `:180-181`; `Laporan.php:1076`

- [ ] **TODO-BL15** Medium — Harian (basis kas) vs Periode/Kategori (akrual) tidak sinkron untuk tanggal sama — `Laporan.php:60-64` vs `:123-130` — **DEC-2: diterima by-design (tidak diubah)**

- [ ] **TODO-BL16** Medium — `exportExcel(jenis=harian)` memakai jalur akrual, beda dari tabel Harian — `Laporan.php:1433,1490-1541` — **DEC-2: tidak prioritas (hanya tab Bulanan yang dipakai)**

- [ ] **TODO-BL17** Medium — `mangkrak` dikecualikan di Tagihan tapi dihitung di piutang laporan — `Tagihan.php:81-82`; `Laporan.php:117,128,1365`

- [ ] **TODO-BL18** Medium — saat filter kategori, `grand_total` pro-rata tapi `sisa_tagihan` penuh → piutang overstated — `Laporan.php:1043-1061,1076`

- [ ] **TODO-BL19** Medium — atribusi kategori campur master-produk & `detail_transaksi.kategori_id`; pembulatan tak direkonsiliasi — `Laporan.php:869,1247-1251,1288-1305`

- [ ] **TODO-BL20** Medium — merge live+arsip via `array_merge` tanpa dedup `id` → double-count saat delete gagal — `Laporan.php:150-153,179-193`; `TransaksiArchiveService.php:705-730`

- [ ] **TODO-BL21** Medium — `masterApply` menjadwalkan user non-aktif, tak transaksional, tanpa cap `jumlah_minggu` — `MasterJadwalModel.php:122-184`; `Jadwal.php:704-734`

- [ ] **TODO-BL22** Medium — CSRF global mati; perubahan state lewat GET (`/transaksi/batal`) — `Filters.php:64-74`; `Routes.php:187`

- [ ] **TODO-BL23** Medium — diskon manual divalidasi lalu diabaikan diam-diam saat diskon pelanggan aktif — `Api.php:172-183,246-250`; `KalkulasiDiskonTransaksi.php:45-54`

- [ ] **TODO-BL24** Medium — `parse_no_order()` memetakan dua format tampilan berbeda ke nomor internal sama — `order_helper.php:203-219`

- [ ] **TODO-BL25** Medium — `grand_total=0` pada `piutang`/`draft` → status `belum_bayar` selamanya (invoice Rp 0 tak bisa lunas) — `Api.php:285-290`; `KalkulasiStatusPembayaran.php:39-43`; `Tagihan.php:252-259`

- [ ] **TODO-BL26** Medium — edit path abaikan hasil `insert()` baris detail → silent data loss — `Transaksi.php:1459-1470`

- [ ] **TODO-BL27** Medium — race handler closing menangkap semua exception & retry buta; `$saved` null bisa fatal — `Cash.php:381-394`

- [ ] **TODO-BL28** Medium — baris closing bisa diubah kapan saja tanpa riwayat revisi — `ClosingKasModel.php:86-109`

- [ ] **TODO-BL29** Medium — arti "Pemasukan" beda: net di dashboard, gross di opname — `Views/cash/index.php:110-128` vs `Views/cash/opname.php:16-20`

- [ ] **TODO-BL30** Medium — saldo sistem negatif tak dideteksi/diperingatkan sebelum opname/closing — `CashBalanceService.php:31`; `Cash.php:180-185`

### Low


### Low

- [ ] **TODO-BL37** Low — jendela shift inklusif dua ujung; P/S dan S/PM tumpang-tindih — `EvaluasiJendelaKerjaShift.php:16`; `JadwalModel.php:50-61`

- [ ] **TODO-BL39** Low — "Tunai" diturunkan `total - non-tunai`; bisa salah saat detail/subtotal kosong — `Views/laporan/index.php:448,885`; `Laporan.php:371-384,494-516`

- [ ] **TODO-BL40** Low — `Tagihan::detail` tanpa guard status; tombol Lunasi tampil tanpa cek; `saya=1` + filter kasir → list kosong — `Tagihan.php:84-90,166-168`; `Views/transaksi/detail.php:357-363`

- [ ] **TODO-BL42** Low — `is_locked` milik `produk`, bukan `users`; proteksi user hanya dari controller — schema `2026-09-08-000001:44-59,111`; `Auth.php:333,415-421`

### Keputusan produk (diputuskan 2026-10-03)


- [ ] **TODO-Q4** Tidak ada CI; 3 skrip test JS tanpa runner — rendah

- [ ] **TODO-O5** Dokumentasikan prosedur update adapter produksi `aulia3` (bukan repo git; `npm` tidak ada → dependency baru seperti `ws` disalin manual dari `node_modules`; restart via task `AuliaEvolution` + `scripts/restart-adapter.ps1`; source Evolution `D:\\evolution-api-server`) — rendah

## Sedang dikerjakan

### High

- [ ] **TODO-BL10** High — mutasi kas tak admin-gated; pengeluaran terima tanggal sembarang (termasuk lampau/depan) — `AuthFilter.php:70`; `Routes.php:231-246`; `Cash.php:439,547` — **DEC-1: Opsi B** → bukan admin-gating; yang dikerjakan = rekam audit + validasi tanggal pengeluaran

### Medium

- [ ] **TODO-S2** Login: belum ada rate-limit/lockout (bagian `session()->regenerate()` selesai 2026-10-03) — `Auth.php:64-107` — sedang

- [ ] **TODO-S3** Route `/migrasi-manual` masih aktif di produksi AULIA-SERVER2 (dipakai deploy F8 2026-10-05); nonaktifkan/hapus 2 baris route setelah tidak diperlukan — `app/Config/Routes.php:100-101`; `app/Controllers/MigrasiManual.php` — sedang


- [ ] **TODO-Q1** `docs/ARCHITECTURE.md` basi (2026-09-29): masih menyebut "release branch tanpa docs/tests"; jumlah controller/model/layanan tak sinkron dengan kode kini — sedang

- [ ] **TODO-Q2** Test gap: belum ada test jalur kirim Gateway (`kirim`, `kirimMedia`, `callGatewaySend*`), `handoffPercakapan()` (290 baris), lifecycle percakapan, `GatewayTokenFilter` — sedang

- [ ] **TODO-Q3** Kontrak cross-repo Gateway baru terverifikasi satu sisi (repo gateway tidak ada di workspace) — sedang

- [ ] **TODO-Q5** God-object & duplikasi render: `Inbox.php` 3721 baris, `Views/inbox/index.php` 3650 baris, daftar percakapan dirender 2× (PHP `index.php:778` vs JS `index.php:1474`) — sedang


- [ ] **TODO-N1** Notifikasi Windows Inbox: **HTTPS self-signed AULIA-SERVER2 sudah aktif** (cert SAN `IP:192.168.1.10`+`DNS:AULIA-SERVER2`, `.env` baseURL→https, HTTP:80 tetap untuk gateway) dan kode `80df50f` sudah ter-deploy ke produksi. **Realtime WS sudah diperbaiki** via proxy same-origin `/realtime-ws` (Apache mod_proxy_wstunnel + `App\\Libraries\\InboxRealtimeWs`). **SISA**: jalankan `\\\\aulia-server2\\xampp\\import-sertifikat-aulia.bat` di tiap PC kasir yang belum, lalu uji E2E notifikasi Windows + realtime di browser kasir — sedang — ref `docs/sesi/2026-10-05-https-self-signed-aulia-server2.md`

- [ ] **TODO-O4** (opsional) `VACUUM` + pantau ukuran disk PostgreSQL Evolution — sedang

- [ ] **TODO-F3** Terapkan ulang patch Evolution (`PATCH-ADAPTER (2026-10-01)` di `whatsapp.baileys.service.ts`) setiap kali Evolution di-upgrade; prosedur `C:\\Projects\\evolution-gateway\\docs\\evolution-viewonce-patch.md` — sedang

- [ ] **TODO-F9** Kasir bisa mengerjakan percakapan `belum_diambil` tanpa meninggalkan jejak "sedang dikerjakan" — buka chat lalu **unduh gambar/dokumen** (`Inbox::media()` `Inbox.php:572`; baca terbuka REQ-002, tidak menulis `assigned_to` maupun `last_seen_by_assignee_at`) dan memprosesnya selesai (mis. bikin transaksi) **tanpa membalas**. Auto-assign hanya terjadi saat kirim (`Inbox.php:1646`, `kirimMedia`/`kirimKeConversation`), sedangkan `Ambil` (`Inbox.php:2267`) dan `Tandai Dibaca` (`Inbox.php:2395`) harus ditekan manual — jadi queue tetap `belum_diambil` (`ConversationModel::withComputedStatus()` `ConversationModel.php:234-237`) dan kasir lain tidak tahu percakapan ini sedang/sudah ditangani → risiko dikerjakan dobel. **Perlu keputusan**: perlukah aksi penanda "sedang dikerjakan" otomatis (mis. saat thread dibuka / media diunduh), atau cukup andalkan tombol `Ambil`. — sedang — ref `docs/requirements/2026-10-03-unduh-media-inbox.md`

- [ ] **TODO-F10** Perkuat pencegahan/deteksi kehilangan pesan masuk (khususnya **media**) akibat kegagalan sesi/dekripsi LID — sedang — ref insiden 2026-10-05 pelanggan Mell `6287857570921`.
  - **Insiden**: Mell mengirim PNG `1_20261005_094116_0000.png` (~6 MB, caption `160x60`) ke **nomor resmi toko `6285155105633`** (nomor yang di-link ke gateway), tetapi pesan itu **tidak pernah sampai ke perangkat tertaut (Evolution) → tidak ada di gateway → tidak ada di POS**. Terverifikasi berlapis: tidak ada di Evolution `chat/findMessages` (chat tersimpan di bawah LID `224854976532488@lid`; satu-satunya file yang ada = `IMG_20261005_095239.jpg` caption `200x50`), tidak ada di `incoming_queue` gateway, tidak ada di media store `D:\\evolution-gateway\\data\\media`, tidak ada di `aulia_inboxdb`. Karena file tak pernah diterima, **tidak bisa dipulihkan dari server** (solusi sementara: minta pelanggan kirim ulang).
  - **Indikasi akar masalah**: Evolution mencatat `SessionError: No matching sessions found for message` (LID `224854976532488@lid`, pesan `AC68ACAC…`) + 2× `Message ignored with messageStubParameters` untuk kontak ini → sesi Signal per-perangkat tidak lengkap sehingga sebagian pesan (terutama media) gagal didekripsi dan dibuang **sebelum** sampai ke adapter. Kemungkinan diperparah oleh relink nomor 2026-10-04 (sesi LID belum stabil untuk kontak ini).
  - **Pekerjaan yang diusulkan**:
    1. Tambah alarm di `\\\\aulia3\\D\\evolution-gateway\\scripts\\monitor-aulia3.ps1` untuk `failed to decrypt message` dan `Message ignored with messageStubParameters` (saat ini **belum** terpantau), plus deteksi penyimpangan (mis. jumlah pesan masuk Evolution per-chat vs `incoming_queue`).
    2. Opsi perbaikan sesi: **restart instance `aulia-toko`** agar sesi LID di-rebuild (turun koneksi beberapa detik), atau upgrade Baileys bila ini bug dekripsi LID yang dikenal.
    3. Putuskan perlu-tidaknya pemeriksaan gap berkala (rekap pesan masuk Evolution vs gateway per hari) dan dokumentasikan prosedur verifikasi.
  - **Status**: monitoring F10 **sudah dipasang di produksi aulia3** pada 2026-10-05. `monitor-aulia3.ps1` diperbarui untuk mendeteksi `SessionError: No matching sessions found for message`, `failed to decrypt`, `Message ignored with messageStubParameters`, dan `MessageCounterError`; monitoring dijalankan tiap **15 menit**. Perubahan monitoring bersifat **read-only/alert-only**, tidak melakukan auto-restart atau perubahan session. **Window observasi 3 hari**; analisis log dijadwalkan setelah periode tersebut (2026-10-08), lalu ditentukan apakah perlu remediation.


### Low

- [ ] **TODO-BL33** Low — penomoran invoice `random_int(1,999)` per hari; tanpa idempotency key — `Api.php:420-442` — **DEC-4: Opsi A** → sekuens per hari via lock/transaksi

- [ ] **TODO-L1** Analisa log adapter/Evolution produksi untuk periode **setelah checkpoint 2026-10-02** dan putuskan apa yang perlu ditindak. — **Checkpoint**: 2026-10-02 ~13:00 WIB (06:00 UTC) — log hidup (`adapter.log`, `evolution.log`) di `\\\\aulia3\\D\\kilo\\logs\\` sudah **dikosongkan ke 0 byte** dengan prosedur resmi: nonaktifkan `AuliaStackWatchdog` → stop task `AULIAADAPTER` & `AuliaEvolution` → `Clear-Content` kedua log → start `AuliaEvolution` → start `AULIAADAPTER` → enable kembali watchdog. Verifikasi pasca: port 3000 & 8080 listen, adapter `connected` ke nomor `62881082323928`, Evolution `CONNECTED TO WHATSAPP`. Isi log lama (sebelum dikosongkan) terarsip di `\\\\aulia3\\D\\kilo\\logs\\arsip\\adapter_2026-10-02_1254.log` & `evolution_2026-10-02_1254.log` sebagai baseline pembanding. **Tujuan**: pada 2026-10-05 tinjau log bersih ini untuk melihat apakah ada error **berulang/berlama** yang tidak self-recover (kebalikan lonjakan 2026-10-01 yang memang sesi uji). **Yang dicari**: (a) `[AUTH] Request dari CI4 ditolak` & `webhook ditolak: secret tidak cocok/absen`, (b) event `dead-letter`/`[CRITICAL]` baru, (c) `[HEARTBEAT-EVOLUTION] … fetch failed` yang tidak kembali `connected`, (d) pertumbuhan ukuran file, (e) `evolution.log` `"level":50 "error in sending keep alive"` — pada 2026-10-02 06:03 UTC muncul 1× (transien pasca-restart, pulih 17 detik kemudian); jika **berulang**, itu sinyal koneksi WhatsApp tidak stabil. **Yang boleh diabaikan** (terbukti berasal dari sesi uji 1 Okt, sebelum checkpoint): skenario uji (dead-letter `KILO-MX-17` "koordinat tidak valid", "Field 'text' wajib diisi"), `PERINGATAN SECURITY bind 0.0.0.0` (ulang tiap start), transisi `connecting→connected` yang recover, `body request terlalu besar` dari uji >64MB, dan spam `CACHE: { cached: undefined, … }` di `evolution.log` (dump internal Baileys, bukan error). Hubungkan ke TODO-O1/O3 (rotasi/backup log) dan TODO-F2 (`phone` NULL). **Jendela uji disengaja**: 2026-10-02 14:08:37–14:11:23 WIB gateway dimatikan lalu dinyalakan untuk uji backlog media (TODO-F1) — entri `adapter.log`/`evolution.log` di rentang itu bagian dari uji, bukan error produksi (termasuk transisi `connecting→connected` dan `[HEARTBEAT-EVOLUTION] … fetch failed` saat Evolution boot). Stress test 2026-10-02 14:17:05–14:28:00 WIB juga disengaja (gateway dimatikan; ~209 pesan backlog) — entri log di rentang itu bagian dari uji.
  - **Tambahan cek (dari sesi 2026-10-03)**: saat L1, sekalian verifikasi hasil deploy hari ini — (i) rotasi log berjalan: `arsip\\adapter_*.zip` & `evolution_*.zip` terbentuk tiap boot + ada `D:\\kilo\\rotate-logs.log`, dan `adapter.log`/`evolution.log` hidup tidak menumpuk; (ii) prune retensi benar-benar membuang data tua: baris `[MAINTENANCE] … dipangkas` (media 180 hari, kutipan 7 hari, `incoming_queue` completed 30 hari); (iii) `phone` backfill (TODO-F2) tetap terisi setelah adapter restart; (iv) task `AuliaLogRotate` ada dan sukses dijalankan; (v) backup harian terbentuk di `D:\\backup\\aulia3\\{pg,sqlite,media,env}` dan `pg_restore -l` valid; (vi) exclusion Avast untuk `D:\\evolution-gateway` masih ada (Avast pernah mengarantina script gateway 2026-10-03 → adapter mati).
  - **Temuan pemantauan 2026-10-05 (aulia3)** — hasil tinjauan log pasca-checkpoint + verifikasi alat monitor:
    - **LOGOUT instance** (`auth.key.delete`, `WAMonitoringService … LOGOUT`) pada 2026-10-04 11:59:31 WIB, lalu relink ke **nomor resmi toko `6285155105633`** (sebelumnya `62881082323928`). **Disengaja** (ganti ke nomor resmi) — dikonfirmasi user, bukan anomali.
    - **(e) `error in sending keep alive` berulang**: 4× dalam ~2 hari (02 Okt 13:14; 03 Okt 08:11 & 10:33; 04 Okt 14:17 WIB), tiap kali diikuti `stream errored out`/`unexpected error in 'init queries'` lalu **pulih sendiri** (reconnect). Koneksi WA tidak stabil, tapi tidak fatal.
    - **Burst `[OnWhatsappCache] Error processing item …`** 1746 baris pada 2026-10-04 12:00:18–19 WIB (tepat setelah relink). Sekali jalan, tidak menghilangkan pesan.
    - **`error in handling message`** 1× (2026-10-04 12:00:02 WIB) — pesan `@lid` `pkmsg` tak terdekripsi; terisolasi.
    - `evolution.log` sempat 0 byte** pasca-rotate 06:57 WIB (rotasi memindahkan file saat Evolution sedang hidup; `rotate-logs.ps1` dirancang rotasi saat boot). Pukul 10:01 WIB sudah terisi lagi. Perlu dijaga agar rotasi `evolution.log` hanya saat boot / proses di-restart setelah rotasi.
    - **(a) bersih**: tidak ada `[AUTH] Request dari CI4 ditolak` maupun `webhook ditolak: secret tidak cocok/absen`; `EVOLUTION_WEBHOOK_SECRET` terisi (64 char) di `D:\\evolution-gateway\\.env` aulia3.
    - **Alat baru**: `\\\\aulia3\\D\\evolution-gateway\\scripts\\monitor-aulia3.ps1` — ringkasan 1 baris/run ke `D:\\kilo\\monitor-aulia3.log` (port, state, flaps 24j, keepAlive 24j, `[AUTH]` ditolak, level 50/60, deteksi `evolution.log` 0 byte); exit 1 saat WARN. **Task harian `AuliaMonitor` SUDAH terpasang** di aulia3 (Daily 07:00, run as SYSTEM; dibuat + dijalankan sekali via remote `schtasks` user `ops` pada 2026-10-05, `Last Result=0`, output `D:\\kilo\\monitor-aulia3.log`). Alternatif registrasi lokal: `powershell -NoProfile -ExecutionPolicy Bypass -File D:\\evolution-gateway\\scripts\\monitor-aulia3.ps1 -InstallTask`. **TODO**: commit skrip ke repo gateway (masih untracked).



## Selesai / Ditutup

### Low

- [x] **TODO-BL31** Low — hapus route Kasir legacy yang menunjuk method non-existent dan pulihkan route GET/POST `/cash/opname`; handler opname menerima JSON maupun form POST.

- [x] **TODO-DEC1** Otorisasi kas (BL-10) — **DIPUTUSKAN: Opsi B** — kasir tetap boleh semua aksi kas, tetapi setiap perubahan direkam audit (siapa/kapan/nilai sebelum→sesudah); **tanpa** pembatasan peran ke admin.

- [x] **TODO-DEC2** Basis laporan (BL-15/16) — **DIPUTUSKAN: pertahankan basis sekarang** — Harian/Bulanan = basis kas, Periode/Kategori = akrual; fokus operasional pada **tab Bulanan**. BL-15 diterima by-design; BL-16 tidak prioritas.

- [x] **TODO-DEC3** Arsip piutang (BL-06) — **DIPUTUSKAN: A+ dengan katup E** — `belum_bayar`/`dp` tidak diarsipkan; pengaman UI (tampilkan jumlah/nilai piutang saat pilih bulan); piutang macet ditandai `mangkrak` dulu baru boleh diarsipkan; cek korektif piutang yang terlanjur terarsip (ESC-002).

- [x] **TODO-DEC4** Penomoran invoice (BL-33) — **DIPUTUSKAN: Opsi A** — sekuens per hari `INV-YYYYMMDD-NNN` via lock/transaksi.


- [x] **TODO-F7** SELESAI 2026-10-04. Tandai pesan WhatsApp yang diedit/dihapus pelanggan di Inbox (fitur terpadu, Tier A). Rencana: `docs/requirements/2026-10-04-tandai-pesan-diedit-inbox.md` + `docs/design/2026-10-04-tandai-pesan-diedit-inbox.md`. **Spike**: (1) **edit** via `messages.upsert` `secretEncryptedMessage` (`secretEncType=2`, `targetMessageKey.id`) — teks OPAQUE/tak terbaca (Baileys tak men-dekode; dekripsi manual gagal); (2) **hapus** via webhook **`messages.delete`** (`data.id` = wa_message_id pesan dihapus, `status:'DELETED'`) — sumber Evolution `whatsapp.baileys.service.ts:1663-1678`, perlu instance melanggan `MESSAGES_DELETE`. Keduanya ditangani satu mekanisme: endpoint `POST /api/inbox/gateway/message-event` + kolom `edited_at`/`revoked_at` + badge UI + teks pesan asli dibuat samar (`inbox-teks-basi`); baris noise `unsupported` dihapus. **Verifikasi**: feature 6/6, unit 53/53, JS 50/50, gateway suite OK (lokal) + **uji end-to-end produksi via HP nyata, dikonfirmasi user 2026-10-04 bekerja** (edit & hapus tampil benar di Inbox). **Deploy produksi**: POS `AULIA-SERVER2` commit `7fd5265` + migrasi `edited_at`/`revoked_at`; gateway `aulia3` commit `940242a` (`C:\\Projects\\evolution-gateway`) + langganan `MESSAGES_DELETE` + adapter di-restart. — selesai

## Catatan struktur

- **Status utama:** Belum dikerjakan → Sedang dikerjakan → Selesai / Ditutup.
- **Prioritas di dalam status:** High → Medium → Low.
- **Selesai / Ditutup** mempertahankan item `[x]` untuk histori; penghapusan tetap memerlukan approval eksplisit.
