Kamu M.A.R.A. Ini permintaan analisis dari Aan (pemilik toko Aulia) sebelum kami membangun fitur baru di AuliaPos. Jangan kirim apa pun ke AuliaPos dulu. Jawab pertanyaan di bawah, berdasarkan kemampuan dan akses kamu yang sebenarnya. Kalau tidak tahu atau belum diuji, tulis "belum diverifikasi". Jangan mengarang.

## Fitur yang direncanakan

Saat kasir menekan "Buka di Kasir" pada saranmu, keranjang Kasir langsung terisi produk yang disebut pelanggan di chat. Kamu mengirim daftar produk lewat endpoint saran yang sudah ada, `POST /api/inbox/mara/suggestions`, dengan dua field baru:

    "action": "buka_kasir",
    "cart_items": [{"produk_id": 68, "jumlah": 4}]

Aturan dari sisi AuliaPos:
- Hanya `produk_id` (bilangan bulat > 0) dan `jumlah` (bilangan bulat 1-999) yang dibaca. Maksimal 20 baris. `produk_id` kembar dijumlahkan.
- Harga, nama, dan satuan tidak boleh kamu kirim. AuliaPos membacanya sendiri dari tabel `produk`, jadi harga tidak pernah bergantung padamu.
- `cart_items` hanya berlaku untuk saran bertipe `balasan` atau `peringatan` yang membawa `action = buka_kasir`.
- Produk yang tidak ditemukan atau nonaktif dilewati, kasir diberi peringatan, dan item lainnya tetap masuk.
- Keranjang hanya draf. Kasir yang mengecek, mengubah, lalu membayar. Tidak ada transaksi otomatis.

## Fakta data produk (database AuliaPos, 2026-10-18)

- 468 produk, 453 aktif. Kategori: Penjualan Umum (384), Foto (42), Digital Printing (24), Fotokopi (9), Minuman (9).
- Cocok diisi otomatis: produk foto per lembar dengan harga tetap. Contoh: id 60 "2x3" Rp600, id 61 "3x3" Rp800, id 62 "3x4" Rp800, id 63 "3x4 Visa" Rp900, id 64 "4x6" Rp1.000, id 65 "4x6 Visa" Rp1.100, id 66 "5x5" Rp1.100, id 68 "Dompet" Rp1.100.
- Tidak cocok diisi otomatis:
  - id 1 "Banner", id 2 "Penjualan Manual", id 4 "Ukuran Custom": terkunci, berharga 0, Kasir punya modal sendiri untuk mengisinya.
  - 45 produk aktif berharga 0 yang harganya diketik kasir, misalnya "Foto*", "Frame", "Foto Mdf 20x30", "ZZZ".
- Nama produk tidak unik: "ZZZ" 19 baris, "Frame" 4 baris, "Buku Tulis Sinar Dunia 38Lbr" 2 baris. Pencocokan wajib lewat `id`, bukan nama.
- Semua produk kategori Foto (`kategori_id = 16`) membuat Kasir mewajibkan No Order. Keranjang berisi foto otomatis meminta kasir mengisi No Order.

## Aturan yang diusulkan untukmu

Kirim hanya produk yang memenuhi `is_active = 1`, `is_locked = 0`, dan `harga_jual > 0`. AuliaPos memeriksa ulang, tapi kamu jangan mengandalkan itu. Kalau pesanan pelanggan belum jelas, kirim `cart_items` kosong atau tidak usah dikirim, dan jelaskan di teks saran.

## Pertanyaan (jawab bernomor)

1. Akses data: apakah kamu sudah bisa membaca tabel `produk` di `aulia_kasirdb`? Pakai akun apa dan lewat jalur apa? Apakah akses itu hanya `SELECT` pada `id, nama, satuan, kategori_id, harga_jual, is_active, is_locked` tanpa `harga_beli`? Kalau lebih luas dari itu, sebutkan apa saja yang terbuka.
2. Memilih produk: dari kalimat seperti "foto dompet 4 lembar" atau "print warna F4 2 lembar", bagaimana kamu menentukan `produk_id`? Pencocokan nama, alias, atau ukuran? Seberapa yakin kamu, dan di bawah ambang berapa kamu memilih tidak mengirim `cart_items`?
3. Ambigu: kalau ada lebih dari satu kandidat (misalnya "4R" cocok dengan id 64 dan 65), apakah kamu mengosongkan `cart_items` dan hanya bertanya di teks saran? Usulan kami: ya.
4. Produk khusus: setuju bahwa banner, ukuran custom, penjualan manual, dan produk berharga 0 tidak pernah kamu kirim di `cart_items`, hanya disebut di teks saran?
5. Jumlah dan banyak baris: kalau jumlah tidak disebut, atau pelanggan menyebut beberapa jenis sekaligus ("10 dompet, 5 4R, 5 3x4"), apa yang kamu kirim? Usulan kami: banyak baris untuk yang jelas, kosongkan bila jumlah tidak pasti.
6. Foto dan No Order: perlukah kamu menyebut di teks saran bahwa kasir harus mengisi No Order untuk pesanan foto? Apakah kamu sudah tahu aturan ini?
7. Saran berubah: AuliaPos menimpa saran lama bertipe sama pada percakapan yang sama. Kalau pesanan pelanggan berubah di tengah chat, apakah kamu mengirim ulang saran dengan `cart_items` yang utuh dan terbaru?
8. Kesiapan: apa yang perlu diubah di sisimu, dan kapan kamu bisa mengirim `cart_items` di lingkungan dev untuk uji bersama? Bagian AuliaPos selesai lebih dulu dan aman menganggur sampai kamu siap.

## Format jawaban

Jawab per nomor, singkat. Di akhir tulis dua daftar: "Yang sudah bisa" dan "Yang belum diverifikasi atau perlu diubah". Jangan mengirim data ke endpoint produksi sebagai bagian dari jawaban ini.
