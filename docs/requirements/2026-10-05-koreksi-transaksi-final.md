# Requirements: Koreksi pembayaran dan transaksi final

- **Tanggal**: 2026-10-05
- **Status**: draf
- **Tier SDLC**: A
- **Penanggung jawab**: Tim Aulia

## 1. Tujuan

Menutup TODO-BL07 tanpa membatasi kasir untuk mengganti metode pembayaran, sekaligus menyediakan jalur koreksi ketika transaksi sudah `SELESAI` tetapi isi transaksi (qty/harga) ternyata salah. Semua koreksi harus mempertahankan histori finansial dan identitas pelaku koreksi.

## 2. Kondisi saat ini (terverifikasi)

- `POST /api/koreksi-pembayaran` hanya memakai filter `auth`; tidak ada gerbang role khusus.
- `Api::koreksiPembayaran()` membalik pembayaran lama menjadi `reversed`, lalu membuat pembayaran baru dengan `tanggal = now` dan `kasir_id = user yang melakukan koreksi`. Ini mengubah metadata seolah pembayaran baru berasal dari waktu/operator koreksi.
- Schema `pembayaran` hanya menyimpan `kasir_id`, `tanggal`, `created_at`, dan `status`; belum ada audit khusus untuk operator koreksi.
- View pembayaran efektif hanya menghitung `status='aktif'` dan transaksi bukan `batal`.
- Transaksi `SELESAI` tidak dapat diedit melalui `Transaksi::updateTransaksi()`; lifecycle hanya menyediakan pembatalan final.
- `merged_into` dipakai oleh fitur merge transaksi, sehingga bukan field yang tepat untuk relasi koreksi.
- Refund fisik saat ada kelebihan bayar sudah merupakan proses terpisah melalui Kas Keluar kategori `refund_penjualan`.

## 3. User story

- Sebagai kasir, saya ingin mengganti metode pembayaran tanpa pembatasan role, supaya kesalahan metode dapat dikoreksi segera.
- Sebagai kasir, saya ingin histori pembayaran tetap menunjukkan waktu dan kasir pencatat semula, supaya audit tidak berubah saat dikoreksi.
- Sebagai Admin/Shift Leader, saya ingin mengoreksi transaksi `SELESAI` yang salah qty/harga dengan membuat transaksi pengganti, supaya transaksi final tidak diedit diam-diam.
- Sebagai pengelola kas, saya ingin transaksi dan pembayaran lama tidak ikut dihitung sebagai penjualan aktif setelah dikoreksi.

## 4. Acceptance criteria

- **AC-1**: Given pembayaran aktif, when kasir mengubah metode pembayaran, then pembayaran lama menjadi `reversed`, pembayaran pengganti menjadi `aktif`, jumlah tetap sama, dan metode menjadi metode baru.
- **AC-2**: Given koreksi metode pembayaran, when koreksi selesai, then `tanggal` dan `kasir_id` pembayaran asal tidak ditimpa; operator koreksi dan waktu koreksi tersimpan pada histori koreksi.
- **AC-3**: Given dua metode pembayaran sama, when metode baru sama dengan metode lama, then request ditolak tanpa perubahan data.
- **AC-4**: Given transaksi `SELESAI`, when user biasa mencoba mengedit qty/harga melalui jalur edit lama, then request tetap ditolak.
- **AC-5**: Given transaksi `SELESAI` dan koreksi disetujui, when koreksi final dijalankan, then transaksi asli menjadi `BATAL`, detail aslinya tetap utuh, transaksi pengganti dibuat atomik dengan detail yang telah dikoreksi, dan terdapat relasi eksplisit asli -> pengganti.
- **AC-6**: Given koreksi final gagal di tengah proses, when transaksi database di-rollback, then tidak ada transaksi pengganti parsial dan transaksi asli tetap `SELESAI`.
- **AC-7**: Given transaksi asli memiliki pembayaran aktif, when koreksi final selesai, then pembayaran transaksi asli tidak lagi dihitung sebagai pembayaran aktif penjualan; transaksi pengganti memiliki pembayaran yang sesuai dengan hasil koreksi dan seluruh perpindahan/reversal tercatat.
- **AC-8**: Given total transaksi pengganti lebih kecil dari pembayaran sebelumnya, when koreksi selesai, then sistem tidak membuat refund kas otomatis; selisih tetap diproses melalui mekanisme `refund_penjualan` yang sudah ada.
- **AC-9**: Given laporan/closing kas, when transaksi asli sudah `BATAL`, then penjualan aktif tidak menghitung pembayaran aktif transaksi asli.
- **AC-10**: Given transaksi atau pembayaran dikoreksi, when data masuk arsip, then histori koreksi tetap dapat ditelusuri dan tidak bergantung pada keberadaan baris transaksi aktif.

## 5. Batasan dan di luar cakupan

- Koreksi metode pembayaran tetap tersedia untuk semua kasir yang terautentikasi.
- Koreksi isi transaksi final direkomendasikan hanya untuk Admin dan Shift Leader.
- Tidak mengubah kontrak POS <-> WA Gateway.
- Tidak melakukan refund otomatis ke pelanggan.
- Tidak menggunakan `merged_into` untuk relasi koreksi.
- Tidak mengubah perilaku edit transaksi `PROSES`.

## 6. Dampak aturan bisnis

- [x] Mengubah aturan bisnis (wajib dicatat di `docs/CHANGELOG.md`)
- [x] Menyentuh data keuangan (`transaksi`, `pembayaran`, kas)
- [x] Mengubah skema database
- [ ] Mengubah kontrak POS <-> WA Gateway

## 7. Asumsi dan pertanyaan terbuka

- **Asumsi**: Admin dan Shift Leader adalah kapabilitas yang tepat untuk koreksi isi transaksi yang sudah final; aturan ini mengikuti pola otorisasi pembatalan transaksi `SELESAI`.
- **Asumsi**: audit koreksi perlu tetap hidup setelah transaksi dipindahkan ke archive.
- **Pertanyaan**: tidak ada; keputusan role koreksi final mengikuti desain yang telah disepakati pada Gate 2.

## 8. Persetujuan (Gate 1)

- [x] Disetujui pada 2026-10-05.
