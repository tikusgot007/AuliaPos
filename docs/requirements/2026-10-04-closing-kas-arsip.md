# Requirements: Closing kas historis vs arsip transaksi (TODO-BL02)

- **Tanggal**: 2026-10-04
- **Status**: disetujui
- **Tier SDLC**: A
- **Penanggung jawab**: sesi agen (disetujui user)
- **Ref backlog**: `docs/TODO.md` TODO-BL02 (Critical)

## 1. Tujuan

Menjaga agar snapshot closing kas (`closing_kas.saldo_sistem`/`selisih`) yang
sudah tersimpan **tidak berubah/rusak** ketika transaksi bulan tersebut sudah
dipindahkan ke database arsip, dan agar pembuatan closing untuk tanggal lama
tetap menghasilkan angka yang benar.

## 2. Kondisi saat ini (terverifikasi)

Fakta dari kode:

1. Saat closing disimpan, sistem **menghitung ulang** `saldo_sistem` dari data
   live MySQL lalu menulisnya sebagai snapshot: `Cash::simpanClosing()`
   (`app/Controllers/Cash.php:374-376`) → `ClosingKasModel::simpanClosing()`
   (`app/Models/ClosingKasModel.php:90-109`).
2. Saat modal Edit/isi closing dibuka, sistem **juga menghitung ulang**:
   `Cash::closingDetail()` (`Cash.php:329-330`) memanggil
   `getSaldoKasHariIni($cutoff)`. View memakai nilai hitung-ulang itu dan
   **mengabaikan** snapshot `existing` (`app/Views/cash/closing.php:402-403`;
   `existing` yang dikembalikan controller tidak dipakai JS).
3. Perhitungan saldo hanya membaca tabel live: `CashBalanceService::getCashSales()`
   menjumlahkan `pembayaran` + `transaksi` live (`app/Services/CashBalanceService.php:94-100`);
   `kas_awal`/`pengeluaran`/`refund` dari `cash_expense` (`:23-28`).
4. Arsip memindahkan transaksi/detail/pembayaran ke DB SQLite arsip lalu
   **menghapus** barisnya dari MySQL (`TransaksiArchiveService::hapusDariUtama()`,
   `app/Services/TransaksiArchiveService.php:705-713`; tabel `pembayaran_archive`/
   `transaksi_archive` dibuat di `:89-151`). `cash_expense` **tidak** ikut diarsip.
5. Akibatnya, untuk bulan yang sudah diarsipkan, komponen **penjualan tunai**
   menjadi 0 saat dihitung ulang, sehingga `saldo_sistem` yang dihitung ulang ≠
   snapshot tersimpan. Bila admin membuka lalu menyimpan ulang closing tanggal
   itu, snapshot yang benar **tertimpa** dengan angka salah (`Cash.php:374-376`).
6. Daftar tabel closing satu bulan (`Cash::closingRowsForMonth()`,
   `Cash.php:289-313`) dan kolom "Closing Kas" di Laporan Bulanan
   (`ClosingKasModel::getByRentang()`, `:39-52`) membaca **snapshot tersimpan**,
   jadi keduanya saat ini aman (tidak ikut hitung ulang).

Belum diverifikasi: apakah sudah ada bulan yang benar-benar diarsipkan di
produksi (tidak bisa diperiksa dari workspace ini). Cacat bersifat laten.

## 3. User story

- Sebagai **admin**, saya ingin closing kas yang sudah saya simpan tidak berubah
  angkanya, supaya laporan kas historis tetap bisa dipercaya setelah arsip.
- Sebagai **admin**, saya ingin boleh membuat closing yang terlewat untuk
  tanggal lama dengan angka yang benar, supaya pembukuan tetap lengkap.

## 4. Acceptance criteria

- **AC-1**: Given tanggal sudah punya snapshot closing, when modal Edit/isi
  dibuka, then `saldo_sistem` yang ditampilkan = nilai **tersimpan** (bukan
  hitung ulang dari tabel live).
- **AC-2**: Given tanggal sudah punya snapshot closing, when closing disimpan
  ulang, then `closing_kas.saldo_sistem` **tidak berubah** dan
  `selisih = saldo_fisik − saldo_sistem_tersimpan`.
- **AC-3**: Given transaksi satu bulan sudah diarsipkan, when snapshot closing
  bulan itu dibuka dan disimpan ulang, then `saldo_sistem`/`selisih` tetap
  utuh (tidak drift).
- **AC-4**: Given sebuah tanggal lama **belum** pernah di-closing dan
  transaksinya sudah diarsipkan, when closing baru dibuat, then `saldo_sistem`
  dihitung dari penjualan live **+ penjualan yang ada di arsip** (bukan 0).
- **AC-5**: Given data existing, when daftar closing bulanan dan kolom "Closing
  Kas" Laporan Bulanan ditampilkan, then tetap memakai snapshot tersimpan (tidak
  regresi).
- **AC-6**: Given dashboard kas & opname hari ini, when halaman dibuka, then
  saldo tetap dihitung dari data live seperti sekarang (tidak ikut query arsip).

## 5. Batasan dan di luar cakupan

- Batasan: tanpa perubahan skema `closing_kas`; tanpa mengubah kontrak API
  (`POST /cash/closing/simpan`, `GET /cash/closing/detail`).
- Tidak termasuk:
  - `saldo_fisik` tetap boleh diubah (input fisik kasir apa adanya).
  - Perhitungan ulang saldo untuk **laporan** lain (Laporan Bulanan sudah pakai
    snapshot; tab lain di luar cakupan).
  - Dedup live-vs-arsip jika arsip pernah gagal separuh (TODO-BL20); mengikuti
    asumsi arsitektur yang sudah dipakai Laporan: satu transaksi ada di SATU
    sumber saja (live XOR arsip), lihat
    `TransaksiArchiveService.php:1015-1018`.
  - Deteksi duplikasi kas awal (TODO-BL11) dan validasi tanggal pengeluaran
    (TODO-BL10).

## 6. Dampak aturan bisnis

- [ ] Mengubah aturan bisnis (wajib dicatat di `docs/CHANGELOG.md`)
- [x] Menyentuh data keuangan (`transaksi`, `pembayaran`, `tagihan`, kas)
- [ ] Mengubah skema database
- [ ] Mengubah kontrak POS <-> WA Gateway

Catatan: aturan baru yang eksplisit = "`saldo_sistem` closing bersifat final
setelah disimpan". Ini aturan perilaku yang perlu dicatat di `docs/CHANGELOG.md`
bila disetujui.

## 7. Asumsi dan pertanyaan terbuka

- Asumsi:
  - `saldo_sistem` adalah nilai yang dihitung sistem, bukan angka yang diketik
    admin (admin hanya mengisi `saldo_fisik`).
  - Closing hanya relevan untuk tanggal yang sudah lewat (`ClosingKasModel::validasiTanggal`).
- Pertanyaan (maks. 3, hanya yang mengubah hasil):
  1. **D1**: Untuk tanggal yang **sudah** punya snapshot, apakah `saldo_sistem`
     harus **imutabel** (hanya `saldo_fisik` yang bisa diubah)? —
     **Diputuskan 2026-10-04: ya.**
  2. **D2**: Untuk closing **baru** pada bulan yang sudah diarsipkan, lebih baik
     (a) hitung archive-aware, atau (b) tolak dengan pesan "bulan sudah
     diarsipkan"? — **Diputuskan 2026-10-04: (a) archive-aware.**

## 8. Persetujuan (Gate 1)

- [x] Disetujui oleh: user (chat), tanggal: 2026-10-04
