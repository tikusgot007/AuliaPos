# TODO / Backlog Terpusat

> Baca file ini di **AWAL setiap sesi** sebelum bekerja. Perbarui setiap kali ada
> temuan baru, atau item selesai (lihat aturan penghapusan di bawah). Format:
> `- [ ] **ID** ringkas — prioritas — ref`.
>
> Saat menutup item: usulkan ke user untuk menghapus barisnya, lalu **tunggu
> persetujuan eksplisit** sebelum menghapusnya. Jangan dihapus sepihak. Setelah
> disetujui, hapus baris itu dan sebut **ID**-nya di pesan commit.
> Jangan membuat daftar TODO kedua di tempat lain; checkpoint hanya menunjuk ke sini.

## Remediasi inti — register BL (diserap dari plan, audit 2026-10-03)

> Register cacat logika bisnis inti (transaksi/kas/tagihan/laporan/otorisasi/arsip).
> Diserap dari `plan/plan-business-logic-remediation-1.0.md` (file lokal & untracked, dibuang
> 2026-10-03 setelah diserap ke sini). Urutan batch disarankan: A (BL-01/02) → B → C → D → E → F;
> tiap batch: implementasi → verifikasi → approval user.

### High

- [ ] **TODO-BL06** High — arsip menghapus tagihan belum lunas; `Tagihan` tanpa fallback arsip → piutang hilang — `TransaksiArchiveService.php:372-394,475-478,713`; `Tagihan.php:58-95,162-168,207-214` — **DEC-3: A+ dengan katup E** (siap dikerjakan)
- [ ] **TODO-BL07** High — `koreksiPembayaran` membalik pembayaran tanpa gerbang role; menimpa `tanggal` & `kasir_id` — `Api.php:546-643`; `Routes.php:258-262`
- [ ] **TODO-BL08** High — edit path hitung `$hasKategori16` tapi tak dipakai; `no_order` ditulis tanpa lock/cek unik — `Transaksi.php:1288-1317,1444-1453`
- [ ] **TODO-BL09** High — `cash_opname.pemasukan_tunai` diisi `kas_awal + penjualan` → kas awal dobel — `Cash.php:216-219`; `CashBalanceService.php:27`
- [ ] **TODO-BL10** High — mutasi kas tak admin-gated; pengeluaran terima tanggal sembarang (termasuk lampau/depan) — `AuthFilter.php:70`; `Routes.php:231-246`; `Cash.php:439,547` — **DEC-1: Opsi B** → bukan admin-gating; yang dikerjakan = rekam audit + validasi tanggal pengeluaran
- [ ] **TODO-BL11** High — kas awal bisa di-insert dua kali (select-then-insert, tanpa unique key) → saldo membengkak — `CashBalanceService.php:49-73`
- [ ] **TODO-BL12** High — `alasan_selisih` opname dipaksa string tetap read-only → validasi selisih tak bermakna — `Views/cash/index.php:795-810`; `Cash.php:196-201`

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

- [ ] **TODO-BL31** Low — route mati `/kasir/proses`, `/kasir/tambah-pembayaran`; `/cash/opname` hilang padahal view posting ke sana — `Routes.php:123-124,217-227`; `Views/cash/opname.php:32`
- [ ] **TODO-BL32** Low — status legacy `diambil` jatuh tanpa grup filter — `Transaksi.php:556-575`; migration `2026-09-09-000001:31`
- [ ] **TODO-BL33** Low — penomoran invoice `random_int(1,999)` per hari; tanpa idempotency key — `Api.php:420-442` — **DEC-4: Opsi A** → sekuens per hari via lock/transaksi
- [ ] **TODO-BL34** Low — `laporan-pembayaran` masih `auth` (bukan admin) — `Routes.php:253-257`; `Laporan.php:1975` (sisa BL-34/BL-41; `auth/simpan-user` & `auth/update-user` selesai 2026-10-03)
- [ ] **TODO-BL36** Low — navigasi bulan roster pakai `toISOString()` (UTC) → bulan salah dekat tengah malam WIB — `public/assets/js/roster.js:342,367`
- [ ] **TODO-BL37** Low — jendela shift inklusif dua ujung; P/S dan S/PM tumpang-tindih — `EvaluasiJendelaKerjaShift.php:16`; `JadwalModel.php:50-61`
- [ ] **TODO-BL39** Low — "Tunai" diturunkan `total - non-tunai`; bisa salah saat detail/subtotal kosong — `Views/laporan/index.php:448,885`; `Laporan.php:371-384,494-516`
- [ ] **TODO-BL40** Low — `Tagihan::detail` tanpa guard status; tombol Lunasi tampil tanpa cek; `saya=1` + filter kasir → list kosong — `Tagihan.php:84-90,166-168`; `Views/transaksi/detail.php:357-363`
- [ ] **TODO-BL41** Low — `/laporan-pembayaran` hanya `auth` (bukan admin), controller tanpa cek admin — `Routes.php:253-257`; `AuthFilter.php:70-77`; `Laporan.php:1975`
- [ ] **TODO-BL42** Low — `is_locked` milik `produk`, bukan `users`; proteksi user hanya dari controller — schema `2026-09-08-000001:44-59,111`; `Auth.php:333,415-421`

### Keputusan produk (diputuskan 2026-10-03)

- [x] **TODO-DEC1** Otorisasi kas (BL-10) — **DIPUTUSKAN: Opsi B** — kasir tetap boleh semua aksi kas, tetapi setiap perubahan direkam audit (siapa/kapan/nilai sebelum→sesudah); **tanpa** pembatasan peran ke admin.
- [x] **TODO-DEC2** Basis laporan (BL-15/16) — **DIPUTUSKAN: pertahankan basis sekarang** — Harian/Bulanan = basis kas, Periode/Kategori = akrual; fokus operasional pada **tab Bulanan**. BL-15 diterima by-design; BL-16 tidak prioritas.
- [x] **TODO-DEC3** Arsip piutang (BL-06) — **DIPUTUSKAN: A+ dengan katup E** — `belum_bayar`/`dp` tidak diarsipkan; pengaman UI (tampilkan jumlah/nilai piutang saat pilih bulan); piutang macet ditandai `mangkrak` dulu baru boleh diarsipkan; cek korektif piutang yang terlanjur terarsip (ESC-002).
- [x] **TODO-DEC4** Penomoran invoice (BL-33) — **DIPUTUSKAN: Opsi A** — sekuens per hari `INV-YYYYMMDD-NNN` via lock/transaksi.

## Keamanan & kualitas — audit 2026-10-03

- [ ] **TODO-S2** Login: belum ada rate-limit/lockout (bagian `session()->regenerate()` selesai 2026-10-03) — `Auth.php:64-107` — sedang

## Kualitas, dokumen & cakupan — audit 2026-10-03

- [ ] **TODO-Q1** `docs/ARCHITECTURE.md` basi (2026-09-29): masih menyebut "release branch tanpa docs/tests"; jumlah controller/model/layanan tak sinkron dengan kode kini — sedang
- [ ] **TODO-Q2** Test gap: belum ada test jalur kirim Gateway (`kirim`, `kirimMedia`, `callGatewaySend*`), `handoffPercakapan()` (290 baris), lifecycle percakapan, `GatewayTokenFilter` — sedang
- [ ] **TODO-Q3** Kontrak cross-repo Gateway baru terverifikasi satu sisi (repo gateway tidak ada di workspace) — sedang
- [ ] **TODO-Q4** Tidak ada CI; 3 skrip test JS tanpa runner — rendah
- [ ] **TODO-Q5** God-object & duplikasi render: `Inbox.php` 3721 baris, `Views/inbox/index.php` 3650 baris, daftar percakapan dirender 2× (PHP `index.php:778` vs JS `index.php:1474`) — sedang

## Prioritas Sedang

- [ ] **TODO-O4** (opsional) `VACUUM` + pantau ukuran disk PostgreSQL Evolution — sedang
- [ ] **TODO-F3** Terapkan ulang patch Evolution (`PATCH-ADAPTER (2026-10-01)` di `whatsapp.baileys.service.ts`) setiap kali Evolution di-upgrade; prosedur `C:\Projects\evolution-gateway\docs\evolution-viewonce-patch.md` — sedang

## Higiene

- [ ] **TODO-H1** Bersihkan data uji Inbox (2 percakapan uji: `628563324637@s.whatsapp.net`, `6281913500707@s.whatsapp.net`) — higiene — ref `docs/sesi/2026-10-01-produksi-aulia3-inbox-tipe-pesan.md`. Diverifikasi 2026-10-02 di produksi: hanya ada 2 percakapan itu (`id` 4 & 5), dan 2 baris `unsupported` berteks gaya lama (`[Pelanggan mengirim video singkat …]`, `[Pelanggan mengirim pesan lihat-sekali …]`) keduanya milik percakapan `id` 4. Menghapus 2 percakapan ini otomatis menghapus kedua baris tersebut (dulu dicatat sebagai TODO-H2, sudah dihapus karena tumpang-tindih). **Cakupan diperbarui 2026-10-02** setelah uji F1 + stress test — kini **13 percakapan** (`id` 4–16, total 270 pesan), bukan 2 seperti semula:

| id | chat_id | pesan | sumber |
|---|---|---|---|
| 4 | `628563324637` | 26 | uji 1 Okt + F1 (id 76–77) + balasan staf (id 150) |
| 5 | `6281913500707` | 35 | uji 1 Okt (tidak dapat pesan baru) |
| 6 | `6282332153590` | 9 | stress test |
| 7 | `6289510570459` | 11 | stress test |
| 8 | `6285791470326` | 160 | stress test (pengirim paling aktif) |
| 9 | `6289675973666` | 16 | stress test |
| 10 | `6283852845634` | 6 | stress test |
| 11 | `6285150636082` | 1 | stress test |
| 12 | `6285852977874` | 2 | stress test |
| 13 | `6281235830809` | 1 | stress test |
| 14 | `6285608821725` | 1 | stress test |
| 15 | `6282332619690` | 1 | stress test |
| 16 | `6282245633933` | 1 | stress test |

Tidak ada baris `deleted_at` terisi di seluruh 13 percakapan (belum pernah dibersihkan).

## Log & operasional (audit 5 Oktober)

- [ ] **TODO-L1** Analisa log adapter/Evolution produksi untuk periode **setelah checkpoint 2026-10-02** dan putuskan apa yang perlu ditindak. — **Checkpoint**: 2026-10-02 ~13:00 WIB (06:00 UTC) — log hidup (`adapter.log`, `evolution.log`) di `\\aulia3\D\kilo\logs\` sudah **dikosongkan ke 0 byte** dengan prosedur resmi: nonaktifkan `AuliaStackWatchdog` → stop task `AULIAADAPTER` & `AuliaEvolution` → `Clear-Content` kedua log → start `AuliaEvolution` → start `AULIAADAPTER` → enable kembali watchdog. Verifikasi pasca: port 3000 & 8080 listen, adapter `connected` ke nomor `62881082323928`, Evolution `CONNECTED TO WHATSAPP`. Isi log lama (sebelum dikosongkan) terarsip di `\\aulia3\D\kilo\logs\arsip\adapter_2026-10-02_1254.log` & `evolution_2026-10-02_1254.log` sebagai baseline pembanding. **Tujuan**: pada 2026-10-05 tinjau log bersih ini untuk melihat apakah ada error **berulang/berlama** yang tidak self-recover (kebalikan lonjakan 2026-10-01 yang memang sesi uji). **Yang dicari**: (a) `[AUTH] Request dari CI4 ditolak` & `webhook ditolak: secret tidak cocok/absen`, (b) event `dead-letter`/`[CRITICAL]` baru, (c) `[HEARTBEAT-EVOLUTION] … fetch failed` yang tidak kembali `connected`, (d) pertumbuhan ukuran file, (e) `evolution.log` `"level":50 "error in sending keep alive"` — pada 2026-10-02 06:03 UTC muncul 1× (transien pasca-restart, pulih 17 detik kemudian); jika **berulang**, itu sinyal koneksi WhatsApp tidak stabil. **Yang boleh diabaikan** (terbukti berasal dari sesi uji 1 Okt, sebelum checkpoint): skenario uji (dead-letter `KILO-MX-17` "koordinat tidak valid", "Field 'text' wajib diisi"), `PERINGATAN SECURITY bind 0.0.0.0` (ulang tiap start), transisi `connecting→connected` yang recover, `body request terlalu besar` dari uji >64MB, dan spam `CACHE: { cached: undefined, … }` di `evolution.log` (dump internal Baileys, bukan error). Hubungkan ke TODO-O1/O3 (rotasi/backup log) dan TODO-F2 (`phone` NULL). **Jendela uji disengaja**: 2026-10-02 14:08:37–14:11:23 WIB gateway dimatikan lalu dinyalakan untuk uji backlog media (TODO-F1) — entri `adapter.log`/`evolution.log` di rentang itu bagian dari uji, bukan error produksi (termasuk transisi `connecting→connected` dan `[HEARTBEAT-EVOLUTION] … fetch failed` saat Evolution boot). Stress test 2026-10-02 14:17:05–14:28:00 WIB juga disengaja (gateway dimatikan; ~209 pesan backlog) — entri log di rentang itu bagian dari uji.
  - **Tambahan cek (dari sesi 2026-10-03)**: saat L1, sekalian verifikasi hasil deploy hari ini — (i) rotasi log berjalan: `arsip\adapter_*.zip` & `evolution_*.zip` terbentuk tiap boot + ada `D:\kilo\rotate-logs.log`, dan `adapter.log`/`evolution.log` hidup tidak menumpuk; (ii) prune retensi benar-benar membuang data tua: baris `[MAINTENANCE] … dipangkas` (media 180 hari, kutipan 7 hari, `incoming_queue` completed 30 hari); (iii) `phone` backfill (TODO-F2) tetap terisi setelah adapter restart; (iv) task `AuliaLogRotate` ada dan sukses dijalankan; (v) backup harian terbentuk di `D:\backup\aulia3\{pg,sqlite,media,env}` dan `pg_restore -l` valid; (vi) exclusion Avast untuk `D:\evolution-gateway` masih ada (Avast pernah mengarantina script gateway 2026-10-03 → adapter mati).
