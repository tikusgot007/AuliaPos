# Requirements: Atomisitas penyimpanan transaksi POS (TODO-BL01)

- **Tanggal**: 2026-10-04
- **Status**: disetujui
- **Tier SDLC**: A
- **Penanggung jawab**: sesi agen (disetujui user)
- **Ref backlog**: `docs/TODO.md` TODO-BL01 (Critical)

## 1. Tujuan

Menjamin transaksi POS baru tersimpan sebagai **satu kesatuan atomik**: header
transaksi, baris detail, dan baris pembayaran awal (bila ada) berhasil bersama
atau gagal bersama. Saat ini header dan pembayaran ditulis dalam **dua transaksi
database terpisah**, sehingga bisa tersisa header berstatus `lunas` tanpa baris
`pembayaran`, dan percobaan ulang kasir menghasilkan transaksi duplikat.

## 2. Kondisi saat ini (terverifikasi)

Fakta terverifikasi dari kode:

1. `Api::simpanTransaksi()` menghitung `status_pembayaran` (`lunas`/`dp`/
   `belum_bayar`) **sebelum** menyimpan, lalu memasukkannya ke `$dataTransaksi`
   (`app/Controllers/Api.php:314-330`; nilai `status_pembayaran` dari
   `Api.php:255-302`).
2. `TransaksiModel::simpanTransaksi()` menulis header + detail dalam satu
   transaksi database, lalu **commit** (`app/Models/TransaksiModel.php:396-544`).
   Header sudah berstatus `lunas` di titik ini dengan `total_dibayar = 0`.
3. Sesudah `simpanTransaksi()` kembali (sudah commit), `Api::simpanTransaksi()`
   baru memanggil `TransaksiModel::tambahPembayaran()` untuk pembayaran awal
   (`app/Controllers/Api.php:351-391`). `tambahPembayaran()` membuka **transaksi
   database kedua** (`TransaksiModel.php:838-849`) yang menyisipkan baris
   `pembayaran` dan menyinkronkan `total_dibayar`/`status_pembayaran`
   (`sinkronkanPembayaran()`, `TransaksiModel.php:627-651`).
4. Karena dua transaksi terpisah, bila langkah 3 gagal (error DB, timeout,
   proses berhenti) **header lunas tanpa pembayaran tetap tersimpan**. Ini juga
   sumber duplikat: kasir menekan simpan lagi dan terbentuk transaksi baru.
5. Jalur pembayaran `Api::tambahPembayaran()` (`Api.php:444-538`),
   `Api::koreksiPembayaran()` (`Api.php:546-672`), dan `Tagihan::lunasi()`
   (`app/Controllers/Tagihan.php:371`) memakai `tambahPembayaran()` pada
   transaksi yang **sudah ada**; operasi mereka sudah atomik untuk satu operasi
   pembayaran.
6. `Transaksi::updateTransaksi()` (jalur edit) **tidak menerima data
   pembayaran** (lihat catatan `app/Views/kasir/edit.php:499-501`), jadi bukan
   bagian dari cacat atomisitas ini.

Belum diverifikasi: tidak ada bukti empiris jumlah kejadian di produksi; cacat
disimpulkan dari alur kode.

## 3. User story

- Sebagai **kasir**, saya ingin transaksi yang gagal disimpan tidak
  meninggalkan data setengah jadi, supaya saya bisa mengulang dengan tenang
  tanpa menghasilkan tagihan ganda.
- Sebagai **pemilik/admin**, saya ingin tidak ada transaksi berstatus lunas
  tanpa baris pembayaran, supaya laporan kas dan piutang tetap benar.

## 4. Acceptance criteria

- **AC-1**: Given transaksi POS baru dengan metode pembayaran riil
  (tunai/qris/transfer), when penyimpanan berhasil, then tepat ada **satu**
  baris `transaksi`, baris `detail_transaksi` sesuai keranjang, **satu** baris
  `pembayaran` status `aktif`, `transaksi.total_dibayar = grand_total`, dan
  `transaksi.status_pembayaran = 'lunas'`.
- **AC-2**: Given transaksi POS baru, when penyimpanan baris pembayaran gagal di
  tengah proses, then **tidak ada** baris `transaksi`, `detail_transaksi`,
  maupun `pembayaran` yang tersisa (rollback penuh) dan API mengembalikan
  status error.
- **AC-3**: Given percobaan pertama gagal dan dilaporkan error ke kasir, when
  kasir mengulang simpan, then hanya **satu** transaksi lengkap yang tersimpan
  (tidak ada header yatim dari percobaan pertama).
- **AC-4**: Given transaksi POS baru dengan metode `piutang` atau `draft`, when
  disimpan, then tidak ada baris `pembayaran` dan
  `transaksi.status_pembayaran = 'belum_bayar'`.
- **AC-5**: Given transaksi POS baru dengan metode `dp`, when disimpan, then ada
  satu baris `pembayaran` sebesar nominal DP dan
  `transaksi.status_pembayaran = 'dp'`.
- **AC-6**: Given jalur `Api::tambahPembayaran()`, `Api::koreksiPembayaran()`,
  dan `Tagihan::lunasi()` pada transaksi yang sudah ada, when dipanggil, then
  perilaku tidak berubah (pembayaran tercatat dan status/`total_dibayar`
  tersinkron) — tidak ada regresi.

## 5. Batasan dan di luar cakupan

- Batasan: tanpa perubahan skema database; tanpa perubahan kontrak API/response;
  tetap memakai pola transaksi CodeIgniter yang sudah ada.
- Tidak termasuk:
  - **Idempotency** untuk kasus response hilang di jaringan setelah commit
    (retry oleh klien tanpa tahu hasil). Itu memerlukan idempotency key baru —
    terkait TODO-BL33/DEC-4, dikerjakan terpisah.
  - Penomoran invoice `random_int` tanpa lock (TODO-BL33/DEC-4).
  - Jalur edit transaksi (`Transaksi::updateTransaksi`, TODO-BL08/BL26).
  - Nested transaction di `Api::koreksiPembayaran()` (TODO-BL07).
  - Validasi harga/qty dari klien (TODO-BL03).

## 6. Dampak aturan bisnis

- [ ] Mengubah aturan bisnis (wajib dicatat di `docs/CHANGELOG.md`)
- [x] Menyentuh data keuangan (`transaksi`, `pembayaran`, `tagihan`, kas)
- [ ] Mengubah skema database
- [ ] Mengubah kontrak POS <-> WA Gateway (perubahan lintas repositori)

Catatan: tidak mengubah aturan bisnis yang tertulis; ini penegakan aturan
"transaksi dan pembayaran awalnya satu kesatuan" yang selama ini dilanggar oleh
implementasi. `docs/CHANGELOG.md` tidak wajib berubah kecuali user meminta.

## 7. Asumsi dan pertanyaan terbuka

- Asumsi:
  - `Api.php` `simpanTransaksi()` adalah **satu-satunya** pemanggil
    `TransaksiModel::simpanTransaksi()` (terverifikasi via pencarian kode).
  - Kasus "lunas tanpa pembayaran" untuk `grand_total = 0` (diskon penuh)
    dengan metode riil tetap `lunas` (perilaku sekarang dipertahankan).
- Pertanyaan (maks. 3, hanya yang mengubah hasil):
  1. Untuk transaksi metode riil dengan `grand_total = 0` (diskon 100%),
     status akhir sebaiknya tetap `lunas` (seperti sekarang) atau
     `belum_bayar`? — **Diputuskan 2026-10-04: tetap `lunas`** (tidak
     mengubah perilaku; dipertahankan oleh implementasi).

## 8. Persetujuan (Gate 1)

- [x] Disetujui oleh: user (chat), tanggal: 2026-10-04
