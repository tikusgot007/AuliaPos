# Requirements: Validasi item transaksi POS (harga/jumlah/subtotal) — TODO-BL03

- **Tanggal**: 2026-10-04
- **Status**: disetujui
- **Tier SDLC**: A
- **Penanggung jawab**: sesi agen (disetujui user)
- **Ref backlog**: `docs/TODO.md` TODO-BL03 (High)

## 1. Tujuan

Memastikan server tidak menyimpan baris transaksi yang tidak masuk akal:
`jumlah <= 0`, `harga`/`subtotal` negatif, atau `subtotal` yang tidak konsisten
dengan `harga × jumlah`. Tanpa ini, laporan (yang menjumlahkan
`detail_transaksi.subtotal`) dan `grand_total` bisa rusak oleh input klien yang
salah/berubah.

## 2. Kondisi saat ini (terverifikasi)

Fakta dari kode:

1. Jalur buat (`Api::simpanTransaksi()`): `$subtotal` dijumlahkan langsung dari
   `$item['subtotal']` klien (`app/Controllers/Api.php:167-170`), dan baris detail
   disimpan apa adanya dari `$item['jumlah']`, `$item['harga']`, `$item['subtotal']`
   (`Api.php:337-347`). Tidak ada cek `jumlah > 0`, tidak ada cek non-negatif,
   tidak ada cek `subtotal = harga × jumlah`.
2. Jalur edit (`Transaksi::updateTransaksi()`): pola sama — subtotal dijumlah dari
   klien (`app/Controllers/Transaksi.php:1395-1399`) dan detail dibentuk apa adanya
   (`Transaksi.php:1489-1499`). Ini akar masalah yang **sama** di dua kontroler.
3. Yang mengirim keranjang adalah JS browser (`keranjang` di body JSON), sehingga
   nilai `harga/jumlah/subtotal` sepenuhnya berasal dari klien
   (`public/assets/js/kasir-shared.js:406-433`).
4. Jenis item (terverifikasi):
   - Katalog: kasir boleh mengubah harga (`app/Views/kasir/modal_produk.php:261-299`),
     `subtotal` dihitung klien `= harga × jumlah`.
   - Manual: `harga = total`, `jumlah = 1` (`modal_produk.php:362-375`) →
     `subtotal = harga × jumlah`.
   - Custom cetak kategori 16: `harga = totalHarga`, `subtotal = totalHarga × qty`
     (`modal_produk.php:489-496,566-581`) → konsisten.
   - **Banner**: `subtotal` = total yang diisi kasir, `harga = round(subtotal / jumlah)`
     (`kasir-shared.js:1134-1136`) → `harga × jumlah` bisa **beda** dari `subtotal`
     karena pembulatan. Ini satu-satunya kasus sah yang tidak persis sama.
5. `KalkulasiDiskonTransaksi` (`app/Services/KalkulasiDiskonTransaksi.php`) sudah
   meng-clamp diskon ke `[0, subtotal]`, jadi sumber cacat ada di subtotal item
   yang belum divalidasi, bukan di kalkulasi diskon.

## 3. User story

- Sebagai **pemilik/admin**, saya ingin transaksi yang tersimpan selalu punya
  baris dengan `subtotal = harga × jumlah` (kecuali banner), supaya laporan dan
  grand_total tidak bisa dipalsukan/rusak oleh request klien yang tidak wajar.
- Sebagai **kasir**, saya ingin input yang tidak masuk akal (jumlah 0, harga
  negatif) ditolak dengan pesan jelas, bukan tersimpan diam-diam.

## 4. Acceptance criteria

- **AC-1**: Given item dengan `jumlah` <= 0 / bukan angka, when disimpan (buat
  atau edit), then ditolak dengan pesan jelas dan tidak ada baris tersimpan.
- **AC-2**: Given item dengan `harga` atau `subtotal` negatif, when disimpan,
  then ditolak.
- **AC-3**: Given item **non-banner** dengan `subtotal != harga × jumlah` (di luar
  toleransi pembulatan), when disimpan, then ditolak.
- **AC-4**: Given item **banner** dengan `subtotal` = total dan `harga` =
  `round(subtotal / jumlah)`, when disimpan, then diterima; `detail_transaksi.subtotal`
  = `subtotal` dan `harga_satuan` = `round(subtotal / jumlah)`.
- **AC-5**: Given item valid, when disimpan, then `detail_transaksi.subtotal` =
  `harga × jumlah` untuk item non-banner, dan `transaksi.subtotal` = jumlah
  seluruh baris (setelah normalisasi).
- **AC-6**: Given jalur buat (`Api::simpanTransaksi()`) dan jalur edit
  (`Transaksi::updateTransaksi()`), when item divalidasi, then memakai aturan yang
  sama persis (satu sumber kebenaran), tanpa duplikasi logika.
- **AC-7**: Given diskon/grand_total, when dihitung, then tetap dihitung dari
  subtotal hasil normalisasi (tidak ada regresi perilaku diskon).

## 5. Batasan dan di luar cakupan

- Batasan: kasir **tetap boleh** mengubah harga katalog (fitur yang ada,
  `tambahDenganHargaBaru()`); validasi ini soal konsistensi & tanda nilai, bukan
  memaksa harga master.
- Tidak termasuk:
  - Verifikasi keberadaan `produk_id`/`kategori_id` di master (di luar cakupan).
  - Validasi nama produk / panjang string.
  - Perubahan skema database.
  - Harga khusus per pelanggan / otorisasi diskon (TODO lain).

## 6. Dampak aturan bisnis

- [x] Mengubah aturan bisnis (wajib dicatat di `docs/CHANGELOG.md`)
- [x] Menyentuh data keuangan (`transaksi`, `pembayaran`, `tagihan`, kas)
- [ ] Mengubah skema database
- [ ] Mengubah kontrak POS <-> WA Gateway

Aturan baru: baris transaksi wajib punya `jumlah > 0`, nilai non-negatif, dan
`subtotal` konsisten dengan `harga × jumlah` (kecuali banner yang memakai
`subtotal` sebagai acuan).

## 7. Asumsi dan pertanyaan terbuka

- Asumsi:
  - Nilai uang buku besar selalu bilangan bulat rupiah, sehingga toleransi
    `harga × jumlah` bisa ketat (`<= 0.01`) untuk item non-banner.
  - Klien normal (JS existing) selalu mengirim nilai konsisten untuk item
    katalog/manual/custom.
- Pertanyaan (maks. 3, hanya yang mengubah hasil):
  1. **Q1**: Untuk item banner, `subtotal` (total masukan kasir) dijadikan acuan
     dan `harga_satuan` dihitung ulang server `round(subtotal / jumlah)` —
     **Diputuskan 2026-10-04: ya.**
  2. **Q2**: Untuk item non-banner yang `subtotal != harga × jumlah`, **tolak**
     (rekomendasi) atau server **hitung ulang** `subtotal = harga × jumlah`? —
     **Diputuskan 2026-10-04: tolak.**
  3. **Q3**: Batasi `jumlah` maksimum `9999` (samakan dengan batas UI di
     `kasir-shared.js:463-467`)? — **Diputuskan 2026-10-04: ya.**

## 8. Persetujuan (Gate 1)

- [x] Disetujui oleh: user (chat), tanggal: 2026-10-04
