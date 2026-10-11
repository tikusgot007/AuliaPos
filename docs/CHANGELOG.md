# CHANGELOG

## 2026-10-11 — Inbox: penanda pesan dihapus tidak lagi dobel + tombol aksi bubble melayang

- **Masalah**: bubble pesan yang dihapus di WhatsApp menampilkan informasi yang sama
  sampai tiga kali: label "Pesan diedit — teks terbaru" (bila sempat diedit), label
  "Pesan dihapus — cek WhatsApp Web" (TODO-F7), dan placeholder "Pesan ini telah dihapus"
  (task 2026-10-07). Label TODO-F7 dibuat saat isi pesan yang dihapus masih ditampilkan
  samar; sejak 2026-10-07 isi itu diganti placeholder, sehingga label tersebut berulang.
- **Perubahan tampilan**: untuk pesan `is_revoked`, hanya placeholder
  "Pesan ini telah dihapus" yang tampil. Label "Pesan dihapus — cek WhatsApp Web" dihapus,
  dan label "Pesan diedit" tidak ditampilkan lagi setelah pesan dihapus. Toggle admin
  untuk melihat isi pesan teks yang dihapus tetap sama. Label "Pesan diedit" pada pesan
  yang TIDAK dihapus tetap seperti sebelumnya.
- **Aksi bubble jadi menu dropdown** (pola WhatsApp Web): deretan tombol
  Balas/Teruskan/Edit/Hapus diganti satu panah kecil di pojok kanan-atas bubble yang
  membuka menu. Sebelumnya tombolnya hanya transparan saat tidak di-hover, sehingga tetap
  memakan satu baris kosong, membuat bubble pendek melebar, dan masih bisa terklik walau
  tak terlihat. Menu muncul saat hover/fokus dan tetap terbuka selama dipakai; pada
  perangkat sentuh panahnya selalu tampil. Bootstrap dropdown dipakai karena bundle-nya
  sudah dimuat halaman ini; tidak ada library baru. Aturan kelayakan tiap aksi tidak
  berubah (mis. Teruskan untuk audio/video/lokasi/kontak tetap tampil tapi nonaktif).
- **Tidak berubah**: data, API, skema, kebijakan privasi pesan dihapus (tetap ditentukan
  server lewat `expandable`).
- Test: `tests/js/inbox-thread.test.js` (93 test, termasuk kasus baru "edited-then-revoked").

## 2026-10-11 — Kegagalan kirim ke Gateway tidak lagi dijawab HTTP 200 (TODO-Q2b) + test jalur kirim/handoff (TODO-Q2, TODO-Q2a)

- **Masalah (TODO-Q2b)**: bila Gateway membalas HTTP 200 tetapi isinya `success:false`,
  `Inbox::gatewayFailureResponse()` meneruskan kode HTTP Gateway apa adanya, sehingga
  browser kasir menerima **HTTP 200** dengan `status:'error'`. Tampilan kasir tetap
  benar (UI membaca `json.status`), tetapi log dan monitoring melihat "sukses".
- **Perbaikan**: bila kode HTTP Gateway di bawah 400 (atau tidak ada), browser menerima
  **502**. Kode error dari Gateway (400/409/500/504, dll.) tetap diteruskan apa adanya.
  Kolom `message_send_audit.http_code` tetap menyimpan kode asli dari Gateway.
  Berlaku untuk kirim teks, kirim media, edit, dan hapus pesan (satu fungsi bersama).
- **Tidak berubah**: bentuk JSON respons, isi pesan ke kasir, skema, route, kontrak
  Gateway. Tidak ada perubahan di sisi Gateway.
- **Test baru (hanya file test, kode aplikasi lain tidak diubah)**:
  - Fake gateway `php -S` (`tests/_support/fake-gateway-router.php`,
    `FakeGatewayServer.php`) sehingga cURL asli di `Inbox.php` diuji lewat HTTP
    sungguhan tanpa mengirim ke WhatsApp.
  - `InboxKirimGatewayPalsuTest` (13 test), `InboxKirimMediaGatewayPalsuTest` (7 test),
    `InboxHandoffPercakapanTest` (23 test), `InboxLepasDanAlurPercakapanTest` (6 test),
    `InboxKirimGatewayAsliTest` (manual, di-skip kecuali `INBOX_REAL_GATEWAY_TO` diisi).
- **Verifikasi**: 2 test TODO-Q2b gagal terhadap kode lama (200 ≠ 502) dan lulus
  terhadap kode baru. Feature **233 OK** (1 skipped = test manual), unit **128 OK**
  (7 incomplete pra-ada), integration **65 OK**.
- **Deploy**: perlu `git pull` di AULIA-SERVER2; tanpa migrasi.
- Ref: `docs/TODO.md` **TODO-Q2**, **TODO-Q2a**, **TODO-Q2b**.

## 2026-10-11 — Saldo sistem negatif diperingatkan dan butuh konfirmasi sebelum opname/closing (TODO-BL30)

- **Masalah**: `CashBalanceService::getBalance()` menghitung `pemasukan - pengeluaran`
  tanpa batas bawah. Saldo kas fisik tidak mungkin negatif, jadi nilai negatif hampir
  pasti menandakan data salah (kas awal belum diisi, pengeluaran/refund salah nominal,
  atau penjualan tunai salah catat). Sebelumnya nilai itu dipakai diam-diam oleh
  Opname (`Cash::simpanOpname`) dan Closing (`Cash::simpanClosing`), dan closing
  menyimpannya sebagai snapshot imutabel (TODO-BL02), sehingga kesalahan menempel
  permanen. Terbukti bisa terjadi lewat input yang ada (mis. pengeluaran tanpa kas
  awal, atau refund besar): saldo jadi negatif tanpa peringatan apa pun.
- **Perbaikan (opsi B, keputusan user 2026-10-11)**:
  - Saat saldo sistem < 0, **server menolak** penyimpanan opname dan closing dengan
    HTTP 422 + pesan berisi nominal, sampai request membawa
    `konfirmasi_saldo_negatif = true`. Penegakan di server (bukan cuma tombol di
    browser) supaya request langsung ke endpoint tidak bisa melewatinya.
  - Opname yang dikonfirmasi mencatat jejak permanen di `alasan_selisih` dengan awalan
    `[SALDO SISTEM NEGATIF Rp -X - dikonfirmasi kasir]` (terpotong aman di 255).
  - `Cash::closingDetail()` menambahkan field `saldo_negatif`; kartu "Penerimaan
    sampai saat ini" (`/cash`) menampilkan banner merah; pop-up Opname dan modal
    Closing menampilkan peringatan + kotak centang wajib.
- **Batasan yang disengaja**: tabel `closing_kas` tidak punya kolom catatan, jadi
  konfirmasi closing tidak tersimpan sebagai teks; jejaknya hanya berupa lolosnya
  penyimpanan dari pemeriksaan server (menambah kolom = perubahan skema, diluar cakupan
  ini). Snapshot closing yang SUDAH ada tetap imutabel dan tidak diperiksa ulang.
- **Tidak berubah**: rumus saldo, nilai yang disimpan, skema, route, API
  `/cash/get-saldo-sistem`. Kasus sah tetap bisa lolos (kasir cukup mencentang).
- **Belum terverifikasi**: perilaku kotak centang/tombol di browser (test tidak
  menjalankan JavaScript). Mohon uji sekali dengan saldo negatif (pengeluaran tanpa
  kas awal) di data uji.
- **Verifikasi**: `tests/integration/CashSaldoNegatifTest.php` (6 test, endpoint
  sungguhan: opname/closing negatif ditolak tanpa konfirmasi, diterima dengan
  konfirmasi, awalan tercatat, saldo normal tak berubah, banner di `/cash`). Terhadap
  kode lama 4 test gagal; terhadap kode baru lulus. Unit **128 OK** (7 incomplete
  pra-ada), integration **65 OK**, feature **183 OK** (2 skipped).
- Ref: `docs/TODO.md` **TODO-BL30**.

## 2026-10-11 — Istilah "Pemasukan" di layar Kas diseragamkan (TODO-BL29)

- **Masalah**: kata "Pemasukan" berarti dua hal berbeda pada hari yang sama. Kartu biru
  di **Dashboard Kas** (`/cash`, menu Kas) menampilkan kotak "Penjualan / Pemasukan" =
  `saldo - kas awal` (sudah dikurangi pengeluaran dan refund; **net**), sedangkan
  pop-up **Opname Kas** ("Masuk"), halaman `/cash/opname` ("Pemasukan") dan nilai
  tersimpan `cash_opname.pemasukan_tunai` = `kas awal + penjualan tunai` (**gross**).
  Contoh (kas awal 100.000, penjualan tunai 500.000, pengeluaran 30.000, refund
  20.000): kartu menampilkan 450.000, opname 600.000. Kotak "Pengeluaran" di kartu
  juga diambil dari sumber lain (`CashExpenseModel::getTotalPengeluaranHariIni()`,
  batas waktu berbeda, tanpa memisahkan refund) daripada yang dipakai menghitung saldo.
- **Perbaikan (hanya label dan sumber angka; rumus saldo dan data tersimpan tidak
  berubah)**:
  - kartu `/cash`: kotak menjadi **"Penjualan tunai"** berisi penjualan tunai
    sebenarnya (500.000); kotak Pengeluaran kini dari `CashBalanceService` (sudah
    termasuk refund), sehingga `kas awal + penjualan tunai - pengeluaran = saldo`
    selalu cocok (100.000 + 500.000 - 50.000 = 550.000);
  - pop-up Opname: "Masuk" menjadi **"Kas awal + penjualan"** (angka tetap);
  - halaman `/cash/opname`: "Pemasukan" menjadi **"Penjualan tunai"** (500.000) di
    samping Kas Awal dan Pengeluaran;
  - halaman detail opname (`/cash/detail/(:num)`): "Pemasukan Tunai" menjadi
    **"Penjualan Tunai"** = `pemasukan_tunai - saldo_awal_hari`, karena nilai tersimpan
    sudah memuat kas awal yang tampil di baris atasnya (sebelumnya terbaca dua kali).
- **Keputusan user (2026-10-11)**: opsi A (pisahkan istilah, tanpa mengubah angka
  tersimpan) dan kotak Pengeluaran disamakan dengan perhitungan saldo.
- **Tidak berubah**: `CashBalanceService`, saldo sistem, nilai `cash_opname`
  (`pemasukan_tunai` tetap berisi kas awal + penjualan), closing kas, laporan, API
  `/cash/get-saldo-sistem` (field sama, termasuk `pemasukan`), skema, route. Tidak ada
  aturan bisnis yang berubah.
- **Dampak**: dua angka di kartu `/cash` berubah tampil: kotak penjualan (net menjadi
  penjualan murni) dan kotak Pengeluaran (kini termasuk refund bila ada).
- **Belum terverifikasi**: (1) tampilan visual di browser (test tidak menjalankan
  JavaScript; yang diuji adalah HTML yang dirender); (2) asumsi bahwa
  `pemasukan_tunai` pada opname lama di produksi selalu berisi kas awal + penjualan —
  benar untuk kode saat ini (`Cash::simpanOpname()`), tetapi opname yang dibuat oleh
  versi kode lebih lama tidak bisa diperiksa dari sini (data lokal: 0 opname).
- **Verifikasi**: `tests/integration/CashPemasukanLabelTest.php` (5 test, halaman
  sungguhan pada SQLite uji: angka kartu cocok dengan saldo, kata ambigu hilang,
  halaman opname, detail opname tanpa hitung ganda kas awal dan nilai DB utuh,
  endpoint `get-saldo-sistem` tetap). Terhadap kode lama 4 test gagal; terhadap kode
  baru lulus. Unit **128 OK** (7 incomplete pra-ada), integration **59 OK**, feature
  **183 OK** (2 skipped).
- Ref: `docs/TODO.md` **TODO-BL29**.

## 2026-10-11 — Transaksi bertotal Rp 0 mendapat status pembayaran sesuai rumus resmi (TODO-BL25)

- **Masalah** (`Api::simpanTransaksi()`): cabang `piutang` dan `draft` menulis
  `status_pembayaran = 'belum_bayar'` secara hard-coded, sedangkan rumus resmi
  `KalkulasiStatusPembayaran::hitung()` menyatakan total Rp 0 = `lunas` (tidak ada
  sisa untuk dibayar; aturan ini sudah ada dan diuji). Akibatnya transaksi Rp 0
  tersimpan `belum_bayar`: daftar tagihan (yang menghitung ulang dari angka)
  menampilkan **LUNAS**, tetapi filter "belum lunas", badge tagihan terbuka
  (`status_pembayaran != 'lunas'`) dan laporan yang membaca kolom DB melihat
  `belum_bayar`. Tagihan itu tidak bisa dilunasi (`Tagihan::lunasi` menolak karena
  sisa Rp 0) dan tidak bisa dibayar (`tambahPembayaran` menolak jumlah di atas sisa).
- **Perbaikan**: cabang `piutang` dan `draft` kini memakai
  `KalkulasiStatusPembayaran::hitung($jumlahBayar, $grandTotal)`. Total > 0 tetap
  `belum_bayar` (identik dengan sebelumnya); total tepat Rp 0 menjadi `lunas`.
  Tidak ada baris pembayaran yang dibuat untuk total Rp 0. Cabang `dp` dan metode
  bayar biasa tidak disentuh.
- **Keputusan user (2026-10-11)**: opsi A — ikuti rumus yang ada (bukan menolak
  transaksi Rp 0, yang akan mengubah aturan bisnis dan dialog "Total belanja Rp 0.
  Lanjutkan transaksi?" di `kasir/index.php`). Draft Rp 0 ikut `lunas`; ini hanya
  berarti "tidak ada yang harus dibayar", status pekerjaan tetap `proses`.
- **Catatan koreksi deskripsi TODO**: transaksi Rp 0 **tidak** terjebak selamanya —
  `ubahStatus('selesai')` memanggil `sinkronkanPembayaran()` dulu sehingga syarat
  lunas terpenuhi. Dampak nyatanya adalah ketidakkonsistenan tampilan/filter/laporan.
- **Tidak berubah**: aturan bisnis, `KalkulasiStatusPembayaran`, `Tagihan`,
  `TransaksiModel`, skema, kontrak respons. Data lama tidak disentuh.
- **Belum terverifikasi**: apakah ada transaksi Rp 0 di produksi. Data uji lokal
  (`aulia_kasirdb`: 2 transaksi, `aulia_kasirdb_clone`: kosong) tidak memilikinya,
  tetapi itu bukan data toko. Cek read-only di server:
  `SELECT id, kode_invoice, tanggal, status, status_pembayaran FROM transaksi WHERE grand_total = 0;`
  Bila ada yang berstatus `belum_bayar`, perintah `RepairTotalDibayar` dapat
  merapikannya — hanya dengan persetujuan terpisah.
- **Verifikasi**: `tests/integration/TransaksiTotalNolStatusTest.php` (6 test, lewat
  endpoint `Api::simpanTransaksi` sungguhan: piutang/draft Rp 0 → lunas,
  piutang/draft > 0 → belum_bayar, tanpa baris pembayaran, DP dan tunai tak
  berubah). Terhadap `Api.php` lama 2 test gagal; terhadap yang baru lulus. Unit
  **128 OK** (7 incomplete pra-ada), integration **54 OK**, feature **183 OK**
  (2 skipped).
- Ref: `docs/TODO.md` **TODO-BL25**.

## 2026-10-11 — `parse_no_order()` hanya menerima nomor yang bisa dihasilkan `format_no_order()` (TODO-BL24)

- **Masalah** (`app/Helpers/order_helper.php`): `parse_no_order()` hanya memakai regex
  `^([A-Z]+)(\d+)$` tanpa memeriksa rentang. Akibatnya input manual `A10000` dan
  `B0001` sama-sama menjadi nomor internal `110000` (dua format tampilan berbeda
  bertabrakan; nota menampilkan `B0001` padahal kasir mengetik `A10000`), `A0000`
  dipetakan ke `100000` (nomor lama polos), dan input sangat panjang
  (`ZZZZZZZZZZZZZZZZZZZZ0001`, `A99999999999999999999`) memicu **`TypeError`
  (HTTP 500)** karena hasil `float`, sedangkan angka polos 22 digit terpotong diam-diam.
- **Dampak data**: tidak ada duplikat nomor di DB — `no_order` bukan kolom unik dan
  duplikat dicegah aplikasi pada nomor internal (`TransaksiModel.php:380-408`); yang
  salah adalah kesesuaian tampilan dengan input. Hanya jalur input manual
  (checkbox mode manual) yang terpengaruh; dropdown mengirim angka mentah.
- **Perbaikan** (satu fungsi, tiga pemanggil ikut benar: `Api::simpanTransaksi`,
  `Transaksi::updateTransaksi`, pencarian `Transaksi::parseNoOrder`): nomor dalam
  siklus harus `1..9999` (`A0000`, `A10000` ditolak), huruf maksimal 3 karakter,
  angka setelah huruf maksimal 4 digit, angka polos maksimal 9 digit. Input yang
  ditolak memakai pesan yang sudah ada ("Format No Order tidak valid."). Docblock
  ganda di atas fungsi dibersihkan.
- **Keputusan user (2026-10-11)**: bentuk pendek tanpa nol di depan (`A1`) **tetap
  diterima** sebagai `A0001`.
- **Tidak berubah**: aturan penomoran (`siklus=9999`, `ambang=100000`),
  `format_no_order()`, nomor lama (angka polos dan seluruh keluaran
  `format_no_order()` memberi hasil identik — dibuktikan test round trip), skema.
  Belum terverifikasi: apakah ada nomor sah di produksi di luar batas yang dipilih
  (`ZZZ9999` ≈ 182 juta, jauh di atas pemakaian toko).
- **Verifikasi**: `tests/unit/OrderHelperTest.php` (30 test: round trip di batas
  siklus A/B/Z/AA/ZZ/AAA, `A1` tetap valid, huruf kecil/spasi, input tidak valid
  ditolak tanpa error). Dijalankan terhadap helper lama: 7 test gagal (2 di
  antaranya `TypeError`); terhadap helper baru: lulus. Unit **128 OK** (7 incomplete
  pra-ada), integration **48 OK**, feature **183 OK** (2 skipped).
- Ref: `docs/TODO.md` **TODO-BL24**.

## 2026-10-11 — Aksi hapus/batal tidak lagi bisa dipicu lewat link GET (TODO-BL22, sebagian)

- **Masalah**: lima route yang mengubah/menghapus data terdaftar sebagai `GET`, jadi
  satu klik link atau `<img src>` dari halaman lain (selama kasir/admin sedang login)
  sudah cukup memicunya: `/auth/hapus-user/(:num)`, `/kategori/hapus/(:num)`,
  `/pelanggan/hapus/(:num)`, `/balasan-template/hapus/(:num)`, `/transaksi/batal/(:num)`.
  `Transaksi::batal()` tidak memeriksa peran di controller (aturan peran ada di
  `TransaksiModel::ubahStatus()`), sehingga rentan dipicu dengan hak akses korban.
- **Perbaikan**: kelima route diubah menjadi `POST` (URL sama). Tombol hapus di
  `auth/user_management`, `kategori/index`, `pelanggan/index`, dan
  `balasan_template/index` diganti dari `<a href>` menjadi
  `<form method="post" data-confirm-message=...>` berisi `csrf_field()` — memakai
  mekanisme dialog "Yakin ingin menghapus?" yang sudah ada (pola sama dengan
  `produk/index.php`), jadi tampilan dan konfirmasi tidak berubah bagi pengguna.
- **Tidak berubah / sengaja ditunda**: `GET /logout` tetap GET (dampak kecil, banyak
  titik sentuh); filter `csrf` **global tetap mati** — **TODO-BL22b ditutup tanpa
  dikerjakan atas keputusan user** (effort dan risiko terlalu besar: menyalakannya
  akan memutus 71 route POST dan 33 view). Risiko yang diterima: form POST lintas
  situs tidak diblok token; mitigasi: cookie sesi `SameSite=Lax` dan akses hanya di
  LAN. `Transaksi::batal` tidak punya tombol di UI; hanya dijadikan POST, route
  tidak dihapus. Tidak ada perubahan skema atau aturan bisnis.
- **Dampak operasional**: link hapus lama yang tersimpan (bookmark/riwayat browser)
  kini menghasilkan 404. Disarankan klik satu tombol hapus di browser setelah deploy
  untuk memastikan dialog konfirmasi dan penghapusan berjalan.
- **Verifikasi**: `tests/feature/HapusHanyaPostTest.php` (12: tiap route GET →
  route tidak ditemukan, POST sampai ke controller, kasir/non-login ditolak);
  `BalasanTemplateCrudTest` disesuaikan ke POST dan tetap lulus. Unit **98 OK**
  (7 incomplete pra-ada), integration **48 OK**, feature **183 OK** (2 skipped).
  Klik tombol di browser **belum** diverifikasi (test tidak menjalankan JavaScript).
- Ref: `docs/TODO.md` **TODO-BL22** (bagian GET→POST) dan **TODO-BL22b** (ditutup
  tanpa dikerjakan; alasan dan syarat membuka ulang ada di bagian Selesai / Ditutup).

## 2026-10-11 — Perketat "Terapkan master jadwal": lewati non-aktif, atomik, batas 12 minggu (TODO-BL21)

- **Masalah** (`MasterJadwalModel::applyMaster()` / `Jadwal::masterApply()`):
  1. karyawan **non-aktif** tetap dijadwalkan (query detail master tanpa filter
     `is_active`), sehingga muncul di Matrix/Calendar/roster/statistik;
  2. **tidak transaksional**: gagal di tengah meninggalkan jadwal sebagian, dan
     hasil `simpanJadwal()` tidak diperiksa (insert gagal tanpa exception pada
     `DBDebug=false` tetap dihitung `diisi`);
  3. **tanpa batas atas** `jumlah_minggu` di server (batas `max="12"` hanya di
     atribut input HTML).
- **Perbaikan**:
  1. karyawan non-aktif **dilewati dan dilaporkan** di field terpisah
     `dilewati_nonaktif` (jumlah sel) + `nonaktif` (daftar karyawan), tidak
     dicampur dengan `dilewati`/`conflict`; UI menampilkannya sebagai peringatan;
  2. seluruh apply berjalan dalam **satu transaksi DB** dan hasil simpan tiap sel
     diperiksa — gagal berarti rollback total (tidak ada jadwal yang berubah) dan
     controller membalas error generik (detail hanya di log);
  3. server menolak `jumlah_minggu` **> 12** (`Jadwal::MAX_MINGGU_APPLY`).
- **Keputusan user (2026-10-11)**: non-aktif dilewati + dilaporkan; batas 12 minggu;
  apply semua-atau-tidak-sama-sekali.
- **Tidak berubah**: aturan isi slot kosong / lewati conflict / `overwrite`
  eksplisit (Section 24-25), `getDetailMaster()` (tampilan master tetap
  menampilkan semua karyawan), skema DB, route. Respons hanya **bertambah** dua
  field opsional. Jadwal non-aktif yang sudah terlanjur dibuat sebelumnya tidak
  dibersihkan.
- **Verifikasi**: `tests/integration/MasterJadwalApplyTest.php` (5: non-aktif
  dilewati & terlapor, conflict/overwrite tak berubah, semua non-aktif, rollback
  saat gagal di tengah, simpan gagal-diam dibatalkan) dan
  `tests/feature/JadwalMasterApplyBatasTest.php` (4: 13 & 100000 minggu ditolak,
  12 lolos batas, kasir ditolak). Unit **98 OK** (7 incomplete pra-ada),
  integration **48 OK**, feature **171 OK** (2 skipped).
- Ref: `docs/TODO.md` **TODO-BL21**.

## 2026-10-11 — Riwayat revisi closing kas + halaman Log Revisi (TODO-BL28)

- **Masalah**: baris `closing_kas` boleh diubah kapan saja (sesuai desain), tetapi
  hanya menyimpan `updated_at`/`updated_by` — nilai lama (`saldo_sistem`,
  `saldo_fisik`, `selisih`) **hilang** saat ditimpa, sehingga revisi tidak bisa
  diaudit.
- **Perubahan (aturan "closing boleh diedit" TIDAK diubah)**: setiap edit closing
  yang sudah tersimpan kini menulis satu baris riwayat append-only ke tabel baru
  `closing_kas_audit` (snapshot JSON penuh sebelum/sesudah, `user_id`,
  `created_at`) **dalam transaksi DB yang sama** dengan perubahannya — perubahan
  tanpa riwayat tidak mungkin terjadi (gagal catat riwayat → rollback). Pembuatan
  closing pertama tidak dicatat; nilai awalnya terlihat sebagai "Sebelum" pada
  revisi pertama. Pola identik `cash_expense_audit` (TODO-BL10).
- **Perubahan skema (additive)**: migrasi
  `2026-10-11-000001_CreateClosingKasAuditTable.php` membuat `closing_kas_audit`
  (tanpa FK ke `closing_kas`; index `closing_id`, `user_id`). **Wajib dijalankan di
  produksi saat deploy** (via `/migrasi-manual`) — tanpa tabel ini edit closing akan
  gagal. Rollback kode aman; tabel boleh dibiarkan.
- **Halaman baru (admin-only, read-only)**: `/log-closing-kas` ("Log Revisi Closing
  Kas", menu Administrasi) — daftar revisi dengan filter rentang tanggal revisi +
  pagination; guard admin (prefix di `AuthFilter::$adminRoutes` + cek inline).
- **File**: `ClosingKasAuditModel`, `LogClosingKas`, view `log_closing_kas/index`,
  `ClosingKasModel::simpanClosing()` (update via `updateDenganAudit()`), route,
  filter, menu `layout/main.php`.
- **Verifikasi**: `tests/integration/ClosingKasAuditTest.php` (5 test: tanpa
  riwayat saat insert, snapshot before/after, riwayat per edit, rollback saat
  riwayat gagal, JOIN user + filter waktu), `tests/feature/LogClosingKasTest.php`
  (5 test guard admin + data), `ClosingKasSimpanRaceTest` disesuaikan.
  Unit **98 OK** (7 incomplete pra-ada), integration **43 OK**, feature
  (`composer test:feature`) **167 OK** (2 skipped).
- Ref: `docs/TODO.md` **TODO-BL28**.

## 2026-10-11 — Tangani race closing kas secara presisi (TODO-BL27)

- **Bug (keandalan kas)**: `Cash::simpanClosing()` memakai `catch (\Exception)`
  yang menangkap **semua** exception lalu mengulang operasi yang sama ("retry
  buta") — error asli (mis. DB down) ikut diulang dan tertutupi. Selain itu
  `$saved = getByTanggal()` tidak diperiksa `null`, padahal respons tetap
  `status: success` sambil membaca nilai dari baris yang tak ada.
- **Perbaikan**: penanganan race unique-index `closing_kas.tanggal` dipindah ke
  `ClosingKasModel::simpanClosing()` — bila `insert()` gagal (exception **atau**
  `false` saat `DBDebug=false`), cek ulang baris tanggal; bila ada (proses lain
  menang race) → `update()`; bila tidak ada → **error asli diteruskan**. Controller
  memanggil sekali di dalam `try/catch (\Throwable)` yang mencatat log dan
  mengembalikan respons error (tidak ditelan), lalu memeriksa `$saved === null`
  → respons error 500, bukan akses `$saved[...]` pada null.
- **Bukan perubahan aturan bisnis**: snapshot `saldo_sistem` tetap imutabel
  (TODO-BL02), validasi tanggal/saldo fisik dan skema tidak berubah.
- **Verifikasi**: `tests/integration/ClosingKasSimpanRaceTest.php` (5 test:
  insert, update, race → fallback update, error asli rethrow, insert `false`
  non-race). Unit **98 OK** (7 incomplete pra-ada), integration **38 OK**.
  Feature suite tidak dijalankan di lingkungan ini.
- Ref: `docs/TODO.md` **TODO-BL27**.

## 2026-10-11 — Cegah silent data loss saat edit transaksi (TODO-BL26)

- **Bug (data-integrity transaksi)**: `Transaksi::updateTransaksi()` mengabaikan
  hasil `update()` header, `delete()` detail lama, dan setiap `insert()` detail
  baru di dalam transaksi DB. Bila query gagal — khususnya dengan `DBDebug=false`
  (kondisi produksi) kegagalan mengembalikan `false` tanpa exception — edit
  **dianggap sukses lalu di-commit**, sehingga header berubah tetapi baris detail
  hilang **tanpa pesan error**.
- **Perbaikan**: hasil `update()` header, `delete()` detail lama, dan tiap
  `insert()` detail baru kini **diperiksa**; kegagalan melempar exception sehingga
  `catch` yang ada menjalankan `transRollback()` + melepas `GET_LOCK` (tidak ada
  perubahan tersimpan sebagian). Blok hapus+insert detail dipindah ke method
  privat `simpanDetailEdit()` agar teruji; pesan error DB dibungkus
  `pesanErrorDb()` supaya pembuatan pesan tidak pernah gagal sendiri. Pola
  cek-hasil disamakan dengan jalur buat `TransaksiModel::simpanTransaksi()`.
- **Bukan perubahan aturan bisnis/kontrak**: bentuk request/response, kalkulasi
  diskon/grand total, dan aturan kelebihan bayar tidak berubah. Tidak ada
  perubahan skema.
- **Verifikasi**: `tests/unit/TransaksiDetailEditGuardTest.php` (3 test) memakai
  model double yang memaksa `delete()`/`insert()` mengembalikan `false` → pastikan
  exception dilempar (test gagal bila guard dihapus). Unit **98 OK** (7 incomplete
  pra-ada), integration **33 OK**. Feature suite tidak dijalankan di lingkungan ini
  (butuh MySQL `aulia_inboxdb_test`).
- Ref: `docs/TODO.md` **TODO-BL26**.

## 2026-10-11 — Perbaiki double-count laporan saat transaksi ter-archive tetapi gagal dihapus dari MySQL (TODO-BL20)

- **Bug (data-integrity pelaporan)**: `TransaksiArchiveService::jalankan()`
  menyalin transaksi ke archive lalu menghapusnya dari MySQL. Bila langkah hapus
  (`hapusDariUtama()`) gagal setelah salin+validasi sukses, transaksi **tertinggal
  di kedua sumber**. Laporan (`Laporan.php`) menggabungkan live + archive dengan
  `array_merge` **tanpa dedup** → transaksi/detail/pembayaran dihitung **dua kali**
  (nilai & total laporan membengkak).
- **Perbaikan (MySQL menang)**: baris archive yang ID-nya masih ada di MySQL
  dibuang saat merge, di **seluruh** jalur laporan: Per Periode (`getData()`),
  Per Kategori, Pemasukan Harian, Bulanan, Item Harian, Daftar Pembayaran, serta
  ekspor Excel/CSV. `recordsTotal`/paging ikut disesuaikan (Item Harian,
  Pembayaran, Periode) agar konsisten dengan baris yang ditampilkan.
- **Helper baru**: `Laporan::buangArsipKembar()` (kunci tunggal `id`/`pembayaran_id`
  maupun kunci gabungan untuk Item Harian), `Laporan::saringArsipById()`, plus
  deteksi read-only `TransaksiArchiveService::idTransaksiGandaDiAktif()` /
  `idTransaksiGandaPeriodeDiAktif()` (mencatat **satu** warning bila menemukan
  duplikat; tidak ada tulisan DB).
- **Bukan perubahan aturan bisnis**: nilai transaksi tidak diubah; hanya mencegah
  penghitungan ganda pada keadaan langka (penghapusan pasca-archive gagal). Tidak
  ada perubahan skema/migrasi.
- **Verifikasi**: `tests/integration/LaporanDedupArsipTest.php` (7 test) — deteksi
  duplikat live+archive, kunci tunggal & gabungan, filter paging; regresi
  `phpunit.integration.xml` **33 OK**, `phpunit.xml` (unit) **95 OK** (7 incomplete
  pra-ada). Feature suite tidak dijalankan di lingkungan ini (butuh MySQL
  `aulia_inboxdb_test`, tidak tersedia).
- Ref: `docs/TODO.md` **TODO-BL20**.

## 2026-10-10 — Halaman Log Kiriman Gagal + Log Audit Kas (TODO-L3)

- **Fitur baru (admin-only, read-only)**: dua halaman untuk membaca tabel audit
  yang sebelumnya hanya ditulis tanpa cara melihatnya.
  - **Log Kiriman Gagal** (`/log-kirim-gagal`, tabel `message_send_audit`):
    daftar kiriman keluar Inbox (teks/media/edit) yang gagal diteruskan Gateway
    WhatsApp, lengkap dengan **pratinjau isi** dan tautan ke percakapan terkait.
  - **Log Audit Kas** (`/log-audit-kas`, tabel `cash_expense_audit`): riwayat
    edit/hapus pengeluaran kas (dari TODO-BL10) — temuan tambahan sesi ini,
    polanya identik (audit write-only tanpa viewer).
- **Perubahan skema (additive)**: migrasi
  `2026-10-10-000001_AddPreviewToMessageSendAudit.php` menambah kolom
  `preview_text` (VARCHAR 1000), `media_type`, `media_file_name`, `media_size`
  ke `message_send_audit` (semua nullable). Tidak mengubah kolom lama.
- **Perilaku pratinjau**: `preview_text` diisi teks penuh untuk kirim teks/edit,
  dan caption untuk media; untuk media hanya **metadata ringan** (nama file,
  ukuran, tipe) yang disimpan — **bukan isi byte/base64**, demi menghindari
  pembesaran DB dan menjaga semangat SEC-001 (gateway juga sengaja tidak
  menyimpan isi media). Aksi **hapus pesan** tidak mengisi pratinjau (yang gagal
  adalah aksi, bukan konten baru). Baris audit yang terjadi **sebelum** migrasi
  ini akan selamanya tanpa pratinjau (bukan bug).
- **Bukan perubahan aturan bisnis transaksional**: murni penambahan fitur
  audit/visibilitas; tidak menyentuh alur transaksi, pembayaran, tagihan, atau
  kas. Tidak ada perubahan kontrak POS↔Gateway.
- **Deploy produksi**: `aulia-server2` — `git pull --ff-only` + migrasi via
  `/migrasi-manual` (2026-10-10). Backup pra-migrasi: `aulia_inboxdb` &
  `aulia_kasirdb` di `C:\xampp\backup-db-aulia-server2\`.
- Ref: `docs/TODO.md` **TODO-L3**; `docs/sesi/2026-10-10-log-audit-kiriman-gagal-dan-kas-todo-l3.md`.

## 2026-10-10 — Perbaiki korupsi encoding patch Evolution (TODO-F3) di aulia7 + dev

- **Perubahan operasional (bukan aturan bisnis)**: file
  `whatsapp.baileys.service.ts` di aulia7 (`C:\AuliaPosGateway\evolution-api-server`)
  ternyata punya korupsi encoding (mojibake) di ~15 baris komentar upstream berbahasa
  Portugis (mis. `função` → `funÃƒÆ'Ã‚Â§Ã£o`) — hasil double-encoding saat patch
  `PATCH-ADAPTER`/`PATCH-ADAPTER-LID` diterapkan manual sebelumnya. Tidak fungsional
  (hanya komentar), tapi membuat file tidak bisa dipakai sebagai referensi bersih.
- **Ditemukan saat**: membandingkan repo dev `C:\Projects\evolution-api-server`
  (working tree bersih, TANPA patch) dengan aulia7 (working tree ada 2 modifikasi
  lokal tidak ter-commit) atas permintaan user.
- **Perbaikan**: kedua patch (`PATCH-ADAPTER` view-once 2026-10-01,
  `PATCH-ADAPTER-LID` 2026-10-04) diterapkan ulang secara bersih di atas file
  upstream asli di **dev**, lalu file hasil (SHA256 `004E1D6B...`) disalin
  menggantikan file korup di **aulia7**. Diff berkurang dari `+33/-23` menjadi
  `+12/-3` (noise encoding hilang, logic patch identik). Backup file lama:
  `whatsapp.baileys.service.ts.bak-encoding-20261010121735` (aulia7).
- **Restart**: service `AuliaPosGatewayEvolution` di-restart agar source baru
  termuat (dijalankan via `tsx` langsung dari source, tanpa build step).
  Instance `aulia-toko` kembali `connected` ~2 detik pasca-restart; alur pesan
  masuk/keluar terverifikasi normal sebelum & sesudah.
- **Hasil**: dev (`C:\Projects\evolution-api-server`) dan aulia7 kini punya
  patch **identik** (hash sama) — dev dapat dipakai sebagai source of truth
  untuk menerapkan ulang patch saat Evolution di-upgrade.
- Ref: `docs/TODO.md` **TODO-F3**.

## 2026-10-10 — Aktifkan dekripsi teks pesan diedit (TODO-F8) di aulia7

- **Perubahan operasional (bukan aturan bisnis)**: `EVOLUTION_DECRYPT_MESSAGE_EDIT=1`
  ditambahkan ke `.env` adapter di `aulia7` (`C:\AuliaPosGateway\evolution-gateway\.env`).
  Sebelum ini, flag kosong/off menyebabkan adapter hanya mengirim marker lifecycle
  `event:'edited'` tanpa `edited_text` ke CI4 — `InboxGatewayApi::messageEvent()` jatuh
  ke `MessageModel::markLifecycle()` yang hanya mengisi `edited_at`, sehingga Inbox tetap
  menampilkan teks pesan **sebelum** diedit (badge "diedit" muncul, isi tidak berubah).
  Resolver dekripsi (`src/evolution/messageEditResolver.js`,
  `src/evolution/messageEditCrypto.js`) sudah terimplementasi dari spike TODO-F8
  (2026-10-04) tapi belum pernah diaktifkan di konfigurasi produksi manapun.
- **Ditemukan saat**: investigasi laporan user atas percakapan Inbox nomor
  `6281937281996` (kontak "Hamet") — pesan masuk 2026-10-10 10:30 WIB tampil
  "Ok siao" padahal pelanggan sudah mengedit jadi "oke siap"; `messages.edited_at`
  terisi tapi `messages.edited_text_resolved_at` tetap NULL.
- **Perubahan**: backup `.env` lama disimpan sebagai
  `.env.bak-20261010110856` pada folder yang sama; service Windows
  `AuliaPosGatewayAdapter` di-restart agar flag terbaca. Startup terverifikasi
  bersih (tidak ada error baru), koneksi realtime kasir otomatis reconnect.
- **Catatan**: hanya berlaku untuk event edit yang terjadi **setelah** flag aktif;
  pesan "Ok siao" milik Hamet yang sudah lewat tidak otomatis terkoreksi karena
  payload dekripsi hanya tersedia sesaat event diterima (bukan disimpan ulang).
- Ref: `docs/TODO.md` **TODO-F7**/**TODO-F8**.

## 2026-10-10 — Aktifkan webhook `MESSAGES_DELETE` (TODO-F7) di aulia7

- **Perubahan operasional**: `POST /webhook/set/aulia-toko` ke Evolution API aulia7
  menambahkan event `MESSAGES_DELETE` ke daftar subscription (sebelumnya hanya
  `MESSAGES_UPSERT`, `MESSAGES_UPDATE`, `CONNECTION_UPDATE`, `QRCODE_UPDATED`).
  Tanpa event ini, webhook hapus pesan **tidak pernah terkirim** ke adapter sama
  sekali (beda dari kasus edit: bukan payload kosong, event-nya memang tak lewat),
  sehingga `messages.revoked_at` tidak pernah terisi dan Inbox/POS tetap
  menampilkan pesan yang sudah dihapus pelanggan di WhatsApp.
- **Ditemukan saat**: audit lanjutan pasca-temuan TODO-F8 (lihat entri di atas),
  dipicu laporan user mencoba hapus pesan tapi masih tampil di POS.
- **Verifikasi**: `GET /webhook/find/aulia-toko` mengonfirmasi `MESSAGES_DELETE`
  masuk daftar `events`. **Diuji end-to-end oleh user (2026-10-10 ~11:54 WIB)**:
  badge "dihapus" muncul di Inbox; dikonfirmasi di database —
  `messages.id=3127` & `id=3390` (`revoked_at` terisi pasca-perubahan).
- **Audit tambahan** (fitur lain yang sudah diimplementasikan, dicek statusnya):
  `readreceipts: all` sudah aktif (TODO-Q3e, tidak ada masalah); patch Evolution
  view-once + LID preservation sudah terpasang (TODO-F3); Nudge 1/2 (TODO-F9)
  ternyata **sudah live** sejak deploy `a55d86e` — catatan "belum commit/deploy"
  di `docs/TODO.md` sudah dikoreksi.
- Ref: `docs/TODO.md` **TODO-F7**.

## 2026-10-09 — Cutover gateway produksi `aulia3` → `aulia7` + deploy POS ke production

- **Perubahan operasional (bukan aturan bisnis)**: gateway WhatsApp produksi
  dipindah dari `aulia3` (Scheduled Task) ke `aulia7` (Windows Service WinSW:
  `AuliaPosGatewayEvolution` + `AuliaPosGatewayAdapter`). Nomor WhatsApp
  `6285155105633` di-pairing ulang di aulia7. Port/instance tetap identik
  (`:3000` adapter, `:8080` Evolution, instance `aulia-toko`) sehingga kontrak
  CI4↔adapter tidak berubah.
- **CI4 (`aulia-server2`)**: `inbox.gatewayBaseUrl` → `http://192.168.1.68:3000`;
  proxy WebSocket Apache `/realtime-ws` → `ws://192.168.1.68:3000/realtime`
  (realtime Inbox kembali terhubung). `gateway_status` terverifikasi `connected`.
- **aulia3**: task `AuliaEvolution`/`AuliaAdapter`/`AuliaStackWatchdog`/`AuliaMonitor`
  di-**disable** (bukan dihapus) demi rollback; port 3000/8080 berhenti.
- **Deploy POS**: `aulia-server2` di-update `4c99c42` → `a55d86e` (fast-forward,
  36 commit) dan migrasi DB diterapkan — `cash_expense_audit` (`aulia_kasirdb`)
  + `message_send_audit` (`aulia_inboxdb`) — melengkapi fitur yang sebelumnya
  berstatus *"production migration deployment pending"*.
- **Backup pra-deploy**: `C:\xampp\backup-db-aulia-server2\` (mysqldump
  `aulia_kasirdb` + `aulia_inboxdb`, hash SHA256 tercatat).
- Rollback: lihat `docs/deploy.md` §8 (ditulis 2026-10-11, TODO-Q3i).
- Ref: `docs/requirements/2026-10-09-cutover-gateway-aulia7.md`.

## 2026-10-08 — Kas Keluar: audit trail + validasi tanggal (TODO-BL10)

- **Aturan bisnis baru**: pengeluaran kas (tambah/ubah) **tidak boleh**
  bertanggal masa depan, dan **tidak boleh** menyentuh tanggal yang sudah
  punya snapshot `closing_kas` (baik tanggal lama maupun tanggal baru saat
  mengedit). Berlaku juga untuk hapus: baris pada tanggal yang sudah
  di-closing tidak boleh dihapus. Hari ini dan tanggal lampau yang **belum**
  di-closing tetap boleh, tidak berubah dari sebelumnya.
- **DEC-1 (tetap berlaku)**: modul Kas Keluar TIDAK menjadi admin-only; akses
  tetap seperti sebelumnya (filter `auth`, bukan admin-gating).
- **Audit trail baru**: setiap update/delete pada `cash_expense` menulis satu
  baris ke tabel baru `cash_expense_audit` (append-only, tanpa FK supaya
  baris delete tidak ikut terhapus cascade) berisi snapshot JSON nilai
  sebelum & sesudah, pelaku, dan waktu. Create tidak diaudit (sudah tercatat
  lewat `user_id` baris itu sendiri).
- File: `app/Database/Migrations/2026-10-08-000001_CreateCashExpenseAuditTable.php`,
  `app/Models/CashExpenseAuditModel.php`, `app/Models/CashExpenseModel.php`
  (`validasiTanggal()`, `updatePengeluaran()`, `hapusPengeluaran()`),
  `app/Controllers/Cash.php` (`cekTanggalSudahClosing()` + ketiga endpoint
  kas keluar).
- **Self-review menemukan bug**: `updatePengeluaran()`/`hapusPengeluaran()`
  sebelumnya tidak memeriksa hasil `update()`/`delete()` sebelum menulis baris
  audit & commit — kegagalan validasi model (mis. nominal gagal
  `greater_than[0]`) mengembalikan `false` TANPA error level-DB, sehingga
  `transStatus()` saja tidak mendeteksinya; audit palsu bisa tertulis untuk
  perubahan yang sebenarnya tidak terjadi. Diperbaiki dengan memeriksa hasil
  `update()`/`delete()` secara eksplisit dan `transRollback()` manual bila
  gagal, sebelum pernah menulis baris audit.
- Test: `tests/unit/CashExpenseValidasiTanggalTest.php` (4 kasus),
  `tests/integration/CashExpenseAuditTest.php` (4 kasus, termasuk regresi
  bug di atas), `tests/integration/CashExpenseClosingLockTest.php`
  (5 kasus) — seluruhnya lulus (`phpunit.xml`, `phpunit.integration.xml`).
- Ref: `docs/requirements/2026-10-08-audit-validasi-kas-keluar.md`,
  `docs/design/2026-10-08-audit-validasi-kas-keluar.md`.
- **Migrasi belum dijalankan ke database produksi** — menunggu jadwal
  deployment terpisah.

## 2026-10-07 — Inbox: Toggle admin untuk expand isi pesan yang dihapus (teks saja)

- Setting GLOBAL baru (bukan per-user, bukan RBAC) lewat `.env`:
  `inbox.deletedMessageLocked` (default `true` = LOCKED).
- **LOCKED** (default): respons `GET /inbox/api/conversations/(:num)/messages`
  untuk pesan dengan `revoked_at` terisi kini **membuang** `text` dan
  `media_metadata` dari payload server — bukan sekadar disembunyikan UI
  seperti sebelumnya. `expandable:false`.
- **UNLOCKED**: `text` dikirim apa adanya + `expandable:true`, **HANYA**
  untuk `message_type='text'`. Pesan media (image/document/sticker/audio/
  video) yang dihapus **tetap** `expandable:false` walau unlocked (Opsi A,
  cakupan teks saja) — konsisten untuk kedua arah (incoming & outgoing).
- Nilai `.env` yang tidak sah (bukan true/false) **fail-closed** ke LOCKED,
  dengan log warning.
- UI: placeholder pesan dihapus (`.inbox-pesan-dihapus`) kini punya chevron
  ▼ opsional ketika `expandable:true` — klik untuk expand/collapse isi asli
  + label "Dihapus di WhatsApp pada `<timestamp>`". Tanpa chevron saat
  locked/media (perilaku sebelumnya, tidak berubah secara visual).
- **Bug pra-eksisting diperbaiki sekalian**: `renderIsiPesan()` memeriksa
  `message_type` SEBELUM `is_revoked`, sehingga pesan MEDIA yang dihapus
  sebelumnya tetap merender `<img>`/kartu dokumen PENUH (bukan placeholder)
  — bertentangan dengan tujuan fitur "Pesan dihapus" itu sendiri. Guard
  `is_revoked` sekarang dicek PALING AWAL di `renderIsiPesan()`.
- **Tanpa migrasi, tanpa route baru, tanpa halaman admin, tanpa audit log**
  (sesuai scope). Kontrak adapter `/delete`/`/edit`, handler lifecycle, dan
  tombol Edit/Hapus TIDAK disentuh.
- File: `app/Config/Inbox.php`, `.env.example`, `app/Controllers/Inbox.php`
  (`terapkanKebijakanPesanDihapus()`), `public/assets/js/inbox-thread.js`
  (`renderPlaceholderDihapus()`, `togglePesanDihapus()`), `app/Views/inbox/index.php` (CSS).
- Test: `tests/feature/InboxApiMessagesDeletedLockTest.php` (12 kasus),
  `tests/js/inbox-thread.test.js` (+4 kasus baru, total 60).

## 2026-10-07 — Inbox: Edit & Hapus pesan KELUAR + tampilan "Pesan dihapus"

- Fitur baru: kasir bisa **mengedit** (teks, maks 15 menit) dan **menghapus**
  (teks & media, "hapus untuk semua orang") pesan KELUAR langsung dari Inbox.
  Adapter gateway `POST /delete`/`POST /edit` (Bearer `gatewayToken`) dipanggil
  oleh CI4; token tidak pernah sampai ke browser.
- POS: endpoint baru `POST /inbox/pesan/(:num)/edit` & `.../hapus` (filter `auth`);
  `(:num)` = `messages.id` lokal, dipetakan server ke `wa_message_id`. Otorisasi
  mengikuti pola `kirim()` (cukup `auth`, konsisten operasional shift); validasi
  baris harus `direction='outgoing'` & milik percakapan (cegah IDOR).
- Aturan bisnis: edit hanya `message_type='text'`; di luar jendela 15 menit
  ditolak (`EDIT_WINDOW_EXPIRED`); pesan tanpa `wa_message_id` nyata (placeholder
  `local-…`) ditolak (`MESSAGE_NOT_SYNCED`); hapus idempoten (sudah `revoked_at`
  → sukses tanpa memanggil adapter). `operation_id` wajib untuk edit, opsional
  untuk hapus; `*_UNRESOLVED` TIDAK auto-retry (kunci dipertahankan untuk retry
  manual).
- UI: tombol **Edit**/**Hapus** pada bubble pesan keluar; modal edit + modal
  konfirmasi hapus; pesan yang dihapus dirender placeholder italic abu-abu
  "Pesan ini telah dihapus" dan aksi Balas/Teruskan disembunyikan.
- **Tanpa migrasi**: memakai kolom yang sudah ada `revoked_at`/`edited_at`/
  `edited_text_resolved_at` (TODO-F7/F8). Handler `message-event` yang ada tetap
  dipakai (webhook `MESSAGES_DELETE` menyusul hapus dari POS, idempoten).
- Referensi: `docs/requirements/2026-10-07-edit-hapus-pesan-keluar-inbox.md`,
  `docs/design/2026-10-07-edit-hapus-pesan-keluar-inbox.md`.

## 2026-10-07 — Inbox: WhatsApp read receipt dua arah

- Arah 1 (customer -> POS): saat kasir membuka percakapan pribadi (atau menekan
  "Tandai Dibaca"), AuliaPos meminta Gateway/Evolution menandai pesan MASUK
  pelanggan sebagai dibaca, sehingga WhatsApp mengirim blue tick ke pelanggan.
  Fail-soft: kegagalan TIDAK menggagalkan buka percakapan. Grup & `@lid` dilewati.
- Arah 2 (POS -> customer): event `MESSAGES_UPDATE` Evolution (`READ`/`DELIVERY_ACK`)
  kini diteruskan Gateway ke `POST /api/inbox/gateway/message-status`.
  `messages.read_at`/`delivered_at` diisi idempotent & monoton; pesan tak cocok
  (bukan outgoing / tak ada) -> 200 `matched:false` (tanpa retry tak berujung).
- UI: pesan keluar menampilkan centang ganda (`delivered`) / centang ganda biru
  (`read`). State internal `last_seen_by_assignee_at` dan makna
  `send_status(received/sent/failed)` **tidak** berubah.
- Endpoint baru: Gateway `POST /read` (Bearer), POS
  `POST /api/inbox/gateway/message-status` (gatewaytoken), POS
  `POST /inbox/percakapan/:id/whatsapp-dibaca` (auth).
- Migrasi: `2026-10-07-000001_AddReadStatusToMessages` (kolom `delivered_at`,
  `read_at`; DB group `inbox`).
- Referensi: `docs/requirements/2026-10-07-whatsapp-read-receipt.md`,
  `docs/design/2026-10-07-whatsapp-read-receipt.md`; test
  `tests/feature/InboxGatewayApiMessageStatusTest.php` (POS) +
  `test/test-read-status.js` (Gateway).

## 2026-10-07 — Inbox: Balasan POS ke percakapan closed membuka kembali (reopen)

- Aturan baru: kasir membalas (teks/balasan/Teruskan/media) dari POS ke percakapan
  `closed` membuka kembali percakapan ke `status='open'` + auto-assign ke kasir
  pengirim. `snoozed_until` direset. `closed_at`/`closed_by` dibiarkan sebagai
  "terakhir ditutup" (tidak ada audit reopen tambahan, keputusan Q-A).
- Pemicu reopen: **hanya balasan dari POS**. Outgoing yang disinkronkan dari
  WA Web/HP (direction='outgoing' lewat Gateway) **tidak** membuka kembali —
  hanya sync pesan, status tidak diubah. Incoming customer tetap reopen seperti
  sebelumnya (TANPA auto-assign).
- Auto-assign saat reopen: kasir pengirim POS menjadi `assigned_to` (model
  `assigned_to` = penangan aktif; percakapan `closed` tidak boleh punya penangan
  aktif). Percakapan `open` tanpa pemilik -> auto-assign seperti sebelumnya.
  Owner lain pada `open` TIDAK dioverride.
- Internal note tidak membuka kembali (bukan pesan ke customer).
- Implementasi: helper tunggal `updateSetelahKirimSukses()` di
  `app/Controllers/Inbox.php` dipakai bersama oleh `kirimTeksViaGateway()`
  (teks/balasan/Teruskan) dan `kirimMediaViaGateway()` (media) supaya aturan
  reopen/auto-assign tidak terduplikasi.
- Referensi: `tests/feature/InboxReopenFromPosTest.php`.

## 2026-10-07 — Inbox: Tutup sekaligus lepas kepemilikan + tombol Tutup sesuai hak

- Aturan lama: `tutupPercakapan()` hanya menulis `status='closed'`, `closed_at`, dan
  `closed_by`; `assigned_to` tetap terisi. Tombol "Tutup" di UI dirender hanya
  berdasarkan `status === 'open'` (bukan grup), tanpa mengecek kepemilikan — sehingga
  tombol muncul untuk semua kasir walau endpoint server bisa menolak 403.
- Aturan baru:
  1. **Tutup = menutup + melepas kepemilikan.** Saat berhasil, `assigned_to` di-set
     `NULL` (tidak ada konsep PIC tetap/VIP; `assigned_to` hanya menandai siapa yang
     sedang menangani percakapan aktif). Riwayat pesan tetap utuh; `closed_by` tetap
     mencatat siapa yang menutup.
  2. **Flag `bisa_ditutup` server** menjadi satu-satunya sumber kebenaran hak tampil
     tombol "Tutup" di UI. Dihitung dari aturan yang sama dengan guard server
     `tutupPercakapan()`/`cekOwnership()`: grup → false; `status != open` → false;
     `assigned_to` NULL/self/admin → true; milik staff lain → false.
- Reopen tetap satu arah: pesan masuk customer pada percakapan `closed` membukanya
  kembali (`status='open'`) TANPA mengembalikan `assigned_to` (tetap NULL), sehingga
  percakapan kembali "belum diambil" dan kasir mana pun yang tersedia bisa mengambilnya.
- Idempoten dipertahankan: menutup percakapan yang sudah `closed` tidak menimpa
  `closed_at`/`closed_by` lama.
- Implementasi: `bisaTutupPercakapan()` + `attachBisaDitutup()` di
  `app/Controllers/Inbox.php`, di-chain ke `index()`/`apiConversations()`/
  `apiMessages()`/`tutupPercakapan()`; `tombolTutup` di `app/Views/inbox/index.php`
  memakai `conv.bisa_ditutup`.
- Referensi: `tests/feature/InboxTutupPercakapanTest.php`.

## 2026-10-06 — Inbox: Ambil Alih percakapan (takeover via tombol)

- Aturan lama: tombol "Ambil" hanya berlaku untuk percakapan belum diambil siapa pun
  (`assigned_to IS NULL`); kasir non-admin tidak bisa mengambil percakapan yang sudah
  dipegang staf lain (harus menunggu admin melepas). Setelah pergantian shift, chat
  masih tercatat milik staf shift sebelumnya.
- Aturan baru: kasir non-admin boleh mengambil alih percakapan yang sudah dipegang
  orang lain lewat tombol **"Ambil Alih"** bila salah satu berlaku:
  1. owner sedang off-shift: tidak ada row `jadwal` hari ini, `shift = 'L'` (Libur),
     atau jam sekarang di luar sesi shift-nya (dievaluasi dengan
     `EvaluasiJendelaKerjaShift::sedangBekerja()`), **atau**
  2. pengambil adalah Shift Leader aktif saat itu (override — berlaku walau owner
     masih on-shift).
  Untuk owner off-shift berlaku **grace 30 menit**: selama owner masih menunjukkan
  aktivitas (`last_seen_by_assignee_at` dalam 30 menit terakhir), percakapan tetap
  dianggap miliknya (mencegah chat lepas saat lembur). Grup tidak pernah bisa
  diambil alih (CON-004); admin tetap bisa override kapan pun (regresi: perilaku
  admin tidak berubah).
- Aturan balas TIDAK berubah: `cekOwnership()` tetap owner/null/admin — orang lain
  tetap harus klik Ambil/Ambil Alih dulu sebelum bisa membalas (menghindari balasan
  siluman tanpa jejak perpindahan).
- Race dua staf menekan tombol bersamaan tetap ditangani conditional UPDATE
  (`WHERE assigned_to = <nilai owner lama>` untuk takeover, `IS NULL` untuk klaim);
  yang kalah menerima 409 dengan nama pemenang.
- Implementasi: `ambilPercakapan()` (`app/Controllers/Inbox.php`) + flag
  `bisa_diambil` pada daftar percakapan (`index()`/`apiConversations()`) yang dipakai
  UI untuk merender tombol "Ambil" (unassigned) / "Ambil Alih" (owned).
- Batasan diketahui: perpindahan takeover tidak tercatat di `conversation_handoffs`
  (tabel riwayat Handoff eksplisit); jejak takeover hanya implicit dari
  `messages.sent_by_user_id` (di luar scope perubahan ini).
- Referensi: `tests/feature/InboxAmbilAlihTest.php`.

## 2026-10-06 — Inbox: Handoff menjadi event timeline

- Aturan lama: riwayat Handoff ditampilkan sebagai panel sticky di atas thread dan dibaca
  melalui endpoint riwayat terpisah.
- Aturan baru: Handoff tetap disimpan append-only di `conversation_handoffs`, tetapi
  diproyeksikan sebagai event di timeline Inbox bersama Internal Note. Tidak ada row baru
  yang ditambahkan ke `messages`.
- Alasan: Handoff adalah aktivitas internal pada percakapan, sehingga konteksnya lebih
  mudah dibaca bila berada pada posisi kronologisnya, tanpa mengambil ruang sticky.
- Referensi: branch `feat/inbox-handoff-timeline`, test feature/JS terkait.

## 2026-10-06 — Inbox: sederhanakan form Handoff

- Aturan lama: Handoff meminta ringkasan percakapan dan tindakan lanjutan sebagai dua
  field wajib, dengan catatan tambahan opsional.
- Aturan baru: Handoff hanya meminta satu **Catatan Handoff** yang opsional. Target
  kasir tetap wajib dipilih.
- Alasan: Handoff merupakan penanda perpindahan tanggung jawab; kasir tidak selalu
  perlu menulis template ringkasan/tindakan terpisah.
- Catatan teknis: schema `conversation_handoffs` tidak diubah. Kolom `next_action`
  legacy tetap disimpan sebagai string kosong untuk data Handoff baru.
- Referensi: branch `fix/inbox-handoff-form`.

## 2026-10-05 — Inbox: notifikasi Windows sebagai jalur utama (toast jadi fallback)

- Aturan lama: pesan Inbox yang perlu dibalas diberi tahu lewat toast hijau sticky di
  dalam halaman (plus judul tab, favicon, dan beep).
- Aturan baru: jalur utama pemberitahuan pesan Inbox adalah **notifikasi Windows**
  (browser `Notification` API) yang muncul walau tab POS tidak difokuskan; klik
  notifikasi membuka window Inbox ke percakapan itu. Toast hijau tetap ada **hanya**
  sebagai fallback bila API tidak tersedia atau izin user belum diberikan. Tombol
  "Aktifkan notifikasi" tampil di sidebar saat izin masih `default`.
- Alasan: kasir perlu tahu ada pesan WA perlu dibalas walau POS tidak sedang aktif /
  pesan masuk ke Action Center, bukan hanya saat halaman dipandang.
- Catatan operasional: `Notification` API hanya aktif di secure context; produksi
  HTTP LAN (`http://192.168.1.10/aulia`) harus disajikan HTTPS self-signed dulu
  (langkah infra terpisah, **belum dikerjakan**). Selama HTTP, yang aktif adalah
  fallback toast.
- Referensi: `docs/requirements/2026-10-05-notifikasi-windows-inbox.md`,
  `docs/design/2026-10-05-notifikasi-windows-inbox.md`,
  `public/assets/js/inbox-notifikasi.js`, `tests/js/inbox-notifikasi.test.js`.

## 2026-10-05 — Inbox WhatsApp: tampilkan teks edit yang sudah tervalidasi

- Aturan lama: semua pesan yang ditandai "diedit" ditampilkan samar dengan label
  "Pesan diedit — versi ini belum tentu terbaru", termasuk ketika Gateway sudah
  berhasil memproses dan menyimpan teks hasil edit yang benar.
- Aturan baru: pesan diedit yang teksnya SUDAH berhasil divalidasi dan disimpan
  POS ditampilkan normal dengan label "Pesan diedit — teks terbaru".
  Pesan diedit tanpa hasil edit tervalidasi tetap samar dengan label lama.
- Alasan: kasir perlu membedakan teks versi lama yang masih perlu dikonfirmasi
  dari teks hasil edit yang sudah tersedia dan tervalidasi di Inbox.
- Referensi: branch `todo-f8-message-text` (verification recorded 2026-10-05),
  `docs/requirements/2026-10-05-tampilkan-teks-edit-tervalidasi-inbox.md`,
  `docs/design/2026-10-05-tampilkan-teks-edit-tervalidasi-inbox.md`.
- Deploy produksi 2026-10-05: AuliaPos `v2.4` (`a127d63`) di AULIA-SERVER2 + migrasi
  `2026-10-05-000001` (kolom `edited_text_resolved_at` di `aulia_inboxdb`); gateway
  `evolution` di aulia3 dengan `EVOLUTION_DECRYPT_MESSAGE_EDIT=1`. Uji nyata F7 & F8
  lulus; lihat `docs/sesi/2026-10-05-deploy-produksi-f8.md`.

Perubahan aturan bisnis Aulia Kasir. Bahasa Indonesia (AGENTS.md §15).

Format entri:

```
## YYYY-MM-DD — <judul singkat>
- Aturan lama: <apa>
- Aturan baru: <apa>
- Alasan: <mengapa>
- Referensi: commit `<hash>`, docs/sesi/<file>
```

---

## 2026-10-04 — Inbox WhatsApp: tandai pesan yang diedit/dihapus pelanggan

- Aturan lama: pelanggan **mengedit** pesannya -> muncul baris noise
  `unsupported` ("...secretEncryptedMessage...") sementara pesan asli tetap
  menampilkan teks LAMA; pelanggan **menghapus** (untuk semua) pesannya -> tidak
  berjejak sama sekali dan pesan aslinya tetap tampil seolah masih ada.
- Aturan baru: pesan ASLI ditandai di Inbox — badge "Diedit pelanggan — versi ini
  belum tentu terbaru" (teks yang tampil adalah versi lama; isi edit tidak bisa
  dibaca) dan "Dihapus pelanggan — cek WhatsApp Web". Edit/hapus **tidak** lagi
  membuat baris pesan baru.
- Alasan: kasir tidak boleh salah membaca teks lama sebagai isi final, atau
  mengira pesan yang sudah dihapus masih berlaku. Isi hasil edit terenkripsi dan
  tidak terbaca gateway (spike 2026-10-04), jadi yang ditampilkan hanya penanda.
  Deteksi hapus butuh langganan webhook `MESSAGES_DELETE` di Evolution.
- Referensi: `docs/requirements/2026-10-04-tandai-pesan-diedit-inbox.md`,
  `docs/design/2026-10-04-tandai-pesan-diedit-inbox.md`, `docs/TODO.md` (TODO-F7).

## 2026-10-04 — Inbox WhatsApp: label edit/hapus tidak lagi menyebut "pelanggan"

- Aturan lama: badge selalu berbunyi "Diedit pelanggan — ..." / "Dihapus
  pelanggan — ..." untuk SEMUA pesan yang ditandai, tanpa membedakan arah.
- Aturan baru: badge jadi "Pesan diedit — versi ini belum tentu terbaru" /
  "Pesan dihapus — cek WhatsApp Web", tanpa menyebut pelaku.
- Alasan: event edit/hapus WhatsApp juga berlaku untuk pesan KELUAR
  (`direction=outgoing`) yang staf kirim & edit/hapus sendiri lewat WA Web/HP
  langsung (di luar POS) — ditemukan nyata saat uji end-to-end TODO-F8
  (2026-10-04, pesan `Wkwkw` diedit staf sendiri tetap berlabel "pelanggan").
  Label lama menyesatkan pada kasus ini.
- Referensi: `public/assets/js/inbox-thread.js` (`renderLabelDiedit`,
  `renderLabelDihapus`), `tests/js/inbox-thread.test.js`, `docs/TODO.md` (TODO-F7/F8).

## 2026-10-04 — Archive transaksi: piutang aktif tidak ikut diarsipkan

- Aturan lama: Archive Transaksi memindahkan **semua** transaksi pada bulan yang
  dipilih, tanpa memandang status pembayaran; transaksi `belum_bayar`/`dp`
  (piutang) ikut dipindah lalu dihapus dari DB utama, sehingga hilang dari daftar
  Tagihan.
- Aturan baru: hanya transaksi **`lunas`**, **`batal`**, atau **`mangkrak`** yang
  eligible diarsipkan. Piutang aktif (`belum_bayar`/`dp`, status bukan
  batal/mangkrak) **tetap** di DB utama. Piutang macet tetap bisa diarsipkan
  setelah admin menandainya `mangkrak` (katup keluar). Preview menampilkan jumlah
  & nilai piutang aktif yang **tidak** ikut diarsipkan.
- Alasan: piutang adalah data hidup yang masih harus ditagih; arsip tidak boleh
  menghilangkannya dari Tagihan (keputusan produk DEC-3: "A+ dengan katup E").
- Referensi: `app/Services/TransaksiArchiveService.php`,
  `app/Views/archive_transaksi/index.php`,
  `docs/requirements/2026-10-04-arsip-piutang.md`,
  `docs/design/2026-10-04-arsip-piutang.md`,
  `docs/TODO.md` (TODO-BL06, TODO-DEC3).

## 2026-10-04 — Transaksi POS: validasi baris item (jumlah/harga/subtotal)

- Aturan lama: jumlah, harga, dan subtotal tiap baris keranjang dipercaya apa
  adanya dari klien; `jumlah <= 0`, nilai negatif, dan `subtotal` yang tidak
  konsisten dengan `harga × jumlah` tetap tersimpan.
- Aturan baru: server menolak baris dengan `jumlah <= 0`, `jumlah > 9999`, harga
  atau subtotal negatif, dan (untuk item non-banner) `subtotal != harga × jumlah`.
  Item banner memakai `subtotal` (total masukan kasir) sebagai acuan dan
  `harga_satuan` dihitung server `round(subtotal / jumlah)`. Aturan ini dipakai
  bersama jalur buat & edit lewat `ValidasiItemTransaksi`.
- Alasan: laporan dan grand_total menjumlahkan `detail_transaksi.subtotal`;
  input klien yang tidak masuk akal bisa merusaknya.
- Referensi: `app/Services/ValidasiItemTransaksi.php`, `app/Controllers/Api.php`,
  `app/Controllers/Transaksi.php`,
  `docs/requirements/2026-10-04-validasi-item-transaksi.md`,
  `docs/design/2026-10-04-validasi-item-transaksi.md`, `docs/TODO.md` (TODO-BL03).

## 2026-10-04 — Closing kas: snapshot `saldo_sistem` bersifat final

- Aturan lama: `saldo_sistem` closing dihitung ulang dari data live setiap kali
  modal closing dibuka atau disimpan ulang. Setelah transaksi satu bulan
  dipindahkan ke arsip (baris live dihapus), hitung ulang menghasilkan penjualan
  tunai = 0 sehingga snapshot closing yang benar bisa tertimpa angka salah.
- Aturan baru: `saldo_sistem` closing yang sudah tersimpan bersifat **final**
  (imutabel); edit closing hanya mengubah `saldo_fisik` dan `selisih`
  (`selisih = saldo_fisik − saldo_sistem tersimpan`). Hitung ulang hanya untuk
  tanggal yang belum pernah di-closing, dan hitungan itu sudah mencakup
  penjualan tunai yang ada di database arsip.
- Alasan: closing adalah fakta historis; laporan kas & audit tidak boleh berubah
  hanya karena data operasional lama dipindahkan ke arsip.
- Referensi: `app/Controllers/Cash.php`, `app/Models/ClosingKasModel.php`,
  `app/Services/KalkulasiClosingKas.php`,
  `app/Services/TransaksiArchiveService.php`,
  `docs/requirements/2026-10-04-closing-kas-arsip.md`,
  `docs/design/2026-10-04-closing-kas-arsip.md`, `docs/TODO.md` (TODO-BL02).

## 2026-10-03 — Foto profil: batas ukuran 2 MB benar-benar ditegakkan

- Aturan lama: unggahan foto profil dibatasi 2 MB, tetapi validasinya memakai
  `UploadedFile::getSizeByUnit('kb')` yang mengembalikan string berformat ribuan
  (mis. `"2,048.000"`). Perbandingan `>` terhadap batas int karena itu selalu
  gagal, sehingga **file lebih dari 2 MB tetap tersimpan**.
- Aturan baru: batas 2 MB ditegakkan dengan membandingkan byte mentah
  (`getSize() > 2048 * 1024`); file lebih dari 2 MB ditolak dengan pesan
  "Ukuran file maksimal 2 MB." Nilai batasnya sendiri tidak berubah.
- Alasan: batas yang dijanjikan ke pengguna (maksimal 2 MB) harus benar-benar
  berlaku; bug ini membuat file besar lolos dan tersimpan ke disk.
- Referensi: `app/Libraries/FotoProfilService.php`,
  `tests/feature/FotoProfilServiceTest.php`, `docs/TODO.md` (TODO-F7); pola
  perbaikan sama dengan PR #48 (`BalasanTemplateImageService`).

## 2026-10-03 — Laporan: ekspor Excel saja (hapus Print/Copy/PDF) + format `.xls` berkolom

- Aturan lama: tabel laporan (Item Harian, Laporan Pembayaran, tab Periode, dan
  tab agregat Harian/Bulanan/Kategori) punya tombol Print/Copy/PDF/Excel; pada
  tabel server-side tombol hanya mencakup halaman aktif, dan CSV berpemisah `;`
  terbaca **satu kolom** di Excel (setting locale tertentu).
- Aturan baru: hanya **satu tombol Excel** per tabel; Print, Copy, dan PDF
  dihapus. Ekspor server-side menghasilkan berkas **`.xls` (tabel HTML)** via
  `App\Libraries\ExcelTable`, sehingga Excel membuka dengan kolom yang benar
  tanpa bergantung pada pemisah daftar/locale. Item Harian & Laporan Pembayaran
  memakai endpoint ekspor server-side yang sudah ada; tab Periode memakai
  `/laporan/periode-export` (seluruh baris terfilter); tab agregat tetap
  client-side (`excelHtml5`, seluruh baris).
- Alasan: kebutuhan operasional hanya ekspor Excel; tombol cetak/salin/PDF hanya
  mencakup halaman aktif dan format CSV berdelimiter menyesatkan kasir.
- Referensi: commit `853413d` (merge PR #45),
  `docs/sesi/2026-10-03-inbox-media-notifikasi-ops-gateway.md`,
  `app/Libraries/ExcelTable.php`, `tests/unit/ExcelTableTest.php`.

## 2026-10-03 — Inbox: notifikasi lintas halaman (judul tab, favicon, suara, toast)

- Aturan lama: satu-satunya mekanisme lintas halaman adalah badge angka diam
  di sidebar (`GET /inbox/api/perlu-dibalas-count`, total tim); tidak ada yang
  menarik perhatian kasir di luar window Inbox.
- Aturan baru: endpoint baru `GET /inbox/api/notifikasi-ringkas` mengembalikan
  percakapan `perlu_dibalas` yang RELEVAN untuk user yang login (belum ada
  yang pegang, ATAU dipegang user itu sendiri; admin melihat semua, sama
  seperti `cekOwnership()`). Poller di layout utama memakainya untuk: judul
  tab `(N) ...`, titik merah di favicon, beep (bisa dibisukan lewat lonceng
  di sidebar, `localStorage`), dan toast **sticky** (tidak hilang sendiri)
  yang bisa **menumpuk** (toast baru di BAWAH toast lama, warna hijau
  WhatsApp bukan biru generik, komponen terpisah dari `showToast()`/
  `#liveToast` yang dipakai fitur lain). **Satu toast selalu satu
  percakapan** — kalau beberapa percakapan jadi "baru" dalam satu siklus
  polling yang sama, masing-masing tetap mendapat toast sendiri (bukan
  digabung satu toast banyak nama), hanya satu beep untuk siklus itu;
  diklik selalu membuka window Inbox langsung ke percakapan yang disebut
  toast itu (`?conversation_id=`) DAN langsung menutup toast itu sendiri
  (toast lain yang sedang tampil tidak ikut tertutup). Toast juga hilang
  otomatis begitu percakapan yang disebutnya sudah tidak lagi relevan
  (sudah ditangani dari jalur lain), atau lewat tombol tutup manual.
  Percakapan yang sudah pernah dilaporkan
  (per `last_message_at`) tidak memicu toast/bunyi ulang; percakapan yang
  sudah `perlu_dibalas` SEBELUM tab dibuka juga tidak memicu toast/bunyi
  pada polling pertama sesi itu.
- Alasan: kasir perlu tahu ada pesan yang perlu dibalas walau sedang di
  halaman lain (misalnya layar Kasir), bukan hanya saat membuka Inbox.
- Referensi: `docs/requirements/2026-10-03-notifikasi-inbox-lintas-halaman.md`.
  `apiPerluDibalasCount()`/badge sidebar lama tidak diubah. Kontrak Gateway
  tidak berubah.

## 2026-10-03 — Inbox: unduh gambar & dokumen yang mudah (lightbox, kartu file, unduh massal)

- Aturan lama: gambar hanya bisa disimpan lewat klik kanan (nama `media-<id>` tanpa
  ekstensi); dokumen berupa tautan yang membuka tab baru, dan kegagalan media
  (kadaluarsa/Gateway mati) tampil sebagai tab berisi JSON.
- Aturan baru: klik gambar membuka lightbox dengan tombol Unduh; gambar/sticker
  punya ikon unduh saat hover; dokumen tampil sebagai kartu (ikon, nama, jenis/ukuran,
  tombol Unduh). File tersimpan dengan nama berekstensi (`media-<id>.jpg`, atau nama
  asli pengirim). Kegagalan unduh tampil sebagai toast dengan penyebab. Tombol
  "Pilih media" mengaktifkan mode pilih untuk mengunduh banyak file sekaligus
  (berurutan, maksimum 100 per aksi, tanpa ZIP, ke folder Download browser; Brave
  dapat meminta izin unduh banyak file sekali). `?unduh=1` pada `/inbox/media/:id`
  memaksa `attachment`; hanya gambar non-SVG yang `inline`.
- Alasan: kasir perlu menyimpan foto/nota/dokumen pelanggan dengan cepat dan jelas.
- Referensi: `docs/requirements/2026-10-03-unduh-media-inbox.md`. Audio/video tetap
  placeholder; kontrak Gateway tidak berubah.

## 2026-10-02 — Inbox: riwayat thread menampilkan 200 pesan TERBARU dengan pagination

- Aturan lama: thread percakapan mengambil 500 pesan **tertua**
  (`message_timestamp ASC` + `LIMIT 500`), tanpa cara membuka pesan lain.
  Percakapan lebih dari 500 pesan berhenti menampilkan pesan baru.
- Aturan baru: `GET /inbox/api/conversations/{id}/messages` mengembalikan
  200 pesan **terbaru** (urut lama ke baru). Parameter opsional `before_id`
  mengambil 200 pesan tepat sebelum pesan itu (kursor `message_timestamp, id`),
  dan respons memuat `has_more`. Di thread Inbox, tombol "Muat pesan lama"
  di atas daftar pesan memuat 200 pesan sebelumnya; pesan yang sudah dimuat
  tidak hilang saat polling. Klik kutipan memuat riwayat lama secara bertahap
  (maksimum 5 halaman, sekitar 1000 pesan) sampai pesan asal ditemukan.
- Alasan: bug batas 500 memotong pesan terbaru; pagination menggantikan
  keputusan "tanpa pagination" setelah spike `vue-advanced-chat`
  (`docs/laporan-spike-vue-advanced-chat.md`).
- Perubahan tampilan yang menyertai (bukan aturan bisnis, dicatat agar tim
  tahu): pemisah tanggal (Hari ini / Kemarin / tanggal), kotak kutipan bisa
  diklik untuk meloncat ke pesan asal, format teks WhatsApp (`*tebal*`,
  `_miring_`, `~coret~`, kode) dan tautan `http(s)` yang bisa diklik pada isi
  pesan dan caption, centang pada pesan keluar terkirim dan tanda "!" pada
  yang gagal, serta tombol "gulung ke pesan terbaru" dengan jumlah pesan baru.
  Thread tidak lagi digambar ulang seluruhnya tiap 4 detik.
- Referensi: `docs/requirements/2026-10-02-perbaikan-thread-inbox.md` (AC-1..AC-31),
  `docs/design/2026-10-02-perbaikan-thread-inbox.md`,
  `tests/feature/InboxMessagesPaginationTest.php`, `tests/js/inbox-thread.test.js`

## 2026-10-02 — Inbox: nama conversation tidak lagi berubah saat staff balas dari WA Web/HP

- Aturan lama: endpoint webhook Gateway (`InboxGatewayApi::messages()`)
  menulis `conversations.whatsapp_name` untuk SEMUA pesan (incoming maupun
  outgoing) selama nilainya berbeda. Untuk pesan outgoing yang disinkronkan
  dari WhatsApp Web/HP (`fromMe=true`), `contact_name` di payload adalah push
  name STAFF yang membalas, sehingga nama percakapan berubah menjadi nama
  staff.
- Aturan baru: `whatsapp_name` hanya dimutakhirkan dari push name customer
  untuk pesan **incoming** (`direction='incoming'`). Pesan outgoing dari WA
  Web/HP tidak lagi menyentuh `whatsapp_name`, sehingga nama percakapan tetap
  nama customer.
- Alasan: nama percakapan harus merefleksikan identitas customer, bukan staff
  yang membalas dari luar POS (laporan bug dari tim, dokumen "Penjelasan
  masalah untuk tim 01").
- Referensi: `docs/sesi/2026-10-02-bug-nama-conversation-wa-web.md`,
  `tests/feature/InboxGatewayApiWhatsappNameTest.php`.

## 2026-10-02 — Standardisasi pemilih tanggal/rentang/periode

- Aturan lama: tiap halaman mendefinisikan locale, preset, mode terapkan, dan
  pemuatan aset date picker sendiri-sendiri; nama parameter rentang bercampur
  (`tanggal_mulai/sampai` vs `tanggal_awal/akhir`).
- Aturan baru: satu konfigurasi standar (`App\Config\DatePicker`) + helper
  `public/assets/js/date-range.js`; locale Indonesia penuh, 6 preset, mode
  "Terapkan", aset dipin (`moment@2.31.0`, `daterangepicker@3.1.0`) dimuat
  global dari layout. Parameter rentang diseragamkan ke `tanggal_awal`/
  `tanggal_akhir` (termasuk `item_harian` & `laporan_pembayaran`). Default
  rentang per konteks dipertahankan.
- Alasan: konsistensi UX dan menghapus duplikasi yang menyebabkan drift.
- Referensi: `docs/requirements/2026-10-02-standardisasi-pemilih-tanggal.md`,
  `docs/design/2026-10-02-standardisasi-pemilih-tanggal.md`,
  `docs/sesi/2026-10-02-standardisasi-pemilih-tanggal.md`.

## 2026-09-30 — Import CSV Maintenance produk: kolom kosong Aktif/Locked

- Aturan lama: kolom Aktif/Locked yang kosong di CSV dipaksa menjadi 0 saat
  baris sudah ada di database (mengoverwrite nilai locked yang sedang
  berlaku).
- Aturan baru: untuk baris yang sudah ada, kolom Aktif/Locked yang kosong
  mempertahankan nilai di database. INSERT baris baru tetap default
  aktif=1, locked=0. Aksi NONAKTIF eksplisit tetap memaksa aktif=0.
- Alasan: import CSV yang tidak mengisi kolom Locked secara tidak sengaja
  membuka kunci semua produk yang diproses (lihat docs/sesi/2026-09-30-modul-produk.md).
- Referensi: commit `fc099ea` (fix(produk): keep Aktif/Locked when their CSV
  columns are blank).

## 2026-09-30 — Inbox: status gateway `degraded` (sesi WA terhubung tapi rusak)

- Aturan lama: gateway WhatsApp hanya punya status terhubung/terputus; sesi
  yang rusak (socket `connected` tapi gagal mendekripsi semua pesan) tidak
  terdeteksi sehingga pesan hilang tanpa peringatan.
- Aturan baru: status gateway dapat bernilai `degraded` saat terhubung tetapi
  sesi bermasalah. Inbox POS menampilkan badge merah dan memblokir kirim
  pesan sampai sesi dipulihkan (scan QR ulang).
- Alasan: mencegah kejadian 2026-09-29/30 terulang tanpa terdeteksi.
- Referensi: commit `f26138b`, `6afb732`;
  docs/sesi/2026-09-30-wa-gateway-sesi-degraded.md.

