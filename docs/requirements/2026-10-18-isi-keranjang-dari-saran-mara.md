# Requirements: Isi keranjang Kasir otomatis dari saran M.A.R.A

- **Tanggal**: 2026-10-18
- **Status**: disetujui (Gate 1, 2026-10-18)
- **Tier SDLC**: A (kolom DB baru, endpoint baru, kontrak lintas repositori POS <-> M.A.R.A)
- **Penanggung jawab**: Aan
- **Item backlog**: `TODO-INBOX-KASIR3` (pecahan eks-`TODO-INBOX-KASIR2`)
- **Sumber usulan**: `\\aulia7\AuliaPosGateway-Handoff\usulan-fitur-buat-pesanan.md` bagian "Rekomendasi 3"

## 1. Tujuan

Bila pesanan di chat sudah jelas (contoh: "cetak foto dompet 4 lembar"), kasir yang menekan
"Buka di Kasir" langsung mendapat keranjang berisi produk itu, tanpa mengetik ulang. Kasir tetap
bebas mengubah, menambah, atau menghapus item sebelum membayar.

## 2. Kondisi saat ini

Terverifikasi (dibaca 2026-10-18):

- Tombol "Buka di Kasir" pada saran M.A.R.A memanggil `buatPesanan()` yang sama dengan tombol
  header (`public/assets/js/inbox-suggestions.js:193-204`). Hasilnya hanya mengisi pelanggan;
  keranjang selalu kosong (`Inbox::buatPesanan()` membangun `redirect_url` di `app/Controllers/Inbox.php:3441`).
- Kolom `mara_suggestions.items` sudah dipakai `daftar-periksa` untuk teks langkah
  (`app/Controllers/MaraController.php:312-338`), jadi tidak boleh dipakai ulang untuk produk.
- `mara_suggestions.action` hanya whitelist `buka_kasir`, tanpa parameter
  (`MaraController.php:56-63`, migrasi `2026-10-17-000001_AddActionToMaraSuggestions.php`).
- Kasir sudah punya `tambahItemKeCart(item)` yang menerima `produk_id`, `kategori_id`, `nama`,
  `harga`, `satuan`, `jumlah` (`public/assets/js/kasir-shared.js:419-444`). Keranjang lama dibuang
  saat URL membawa `dari_inbox=1` (`kasir-shared.js:53-78`).
- Tabel `produk` (`aulia_kasirdb`): 468 baris, 453 aktif; kolom `harga_jual`, `satuan`, `is_active`,
  `is_locked`, `kategori_id`. Contoh: id 68 "Dompet", satuan lembar, Rp 1.100.

Belum diverifikasi:

- Sisi M.A.R.A (repo terpisah di aulia7): apakah M.A.R.A sanggup menentukan `produk_id` dari chat.
- Perilaku produk khusus (banner, cetak ukuran custom, item manual) bila diisi otomatis.
- Apakah ada produk yang namanya kembar sehingga katalog ambigu.

## 3. User story

- Sebagai kasir, saya ingin keranjang sudah berisi produk yang disebut pelanggan di chat, supaya
  saya hanya mengecek lalu membayar.
- Sebagai kasir, saya ingin tahu bila ada item yang gagal dimasukkan, supaya saya menambahkannya
  manual dan tidak ada pesanan yang terlewat.
- Sebagai pemilik toko, saya ingin harga selalu dihitung POS dari harga master, supaya salah
  baca atau manipulasi dari M.A.R.A tidak bisa mengubah tagihan.

## 4. Acceptance criteria

- **AC-1**: Given M.A.R.A mengirim saran dengan `action = buka_kasir` dan daftar produk
  `[{produk_id, jumlah}]`, when POS menerimanya, then daftar disimpan di kolom baru
  (bukan `items`) dan saran lama tanpa daftar tetap berfungsi persis seperti sekarang.
- **AC-2**: Given daftar produk dari M.A.R.A, when POS memvalidasi, then hanya `produk_id`
  (bilangan bulat > 0) dan `jumlah` (bilangan bulat 1-999) yang diterima. Field harga, nama,
  subtotal, satuan dari M.A.R.A **diabaikan** dan tidak disimpan. Maksimal 20 baris per saran;
  lebih dari itu ditolak 400.
- **AC-3**: Given `produk_id` yang sama muncul dua kali dalam satu saran, when divalidasi, then
  jumlahnya digabung menjadi satu baris.
- **AC-4**: Given daftar produk dikirim untuk saran yang bukan `balasan` / `peringatan` atau
  tanpa `action = buka_kasir`, when divalidasi, then ditolak 400 (daftar tanpa aksi tidak berguna).
- **AC-5**: Given kasir menekan "Buka di Kasir" pada saran yang membawa daftar produk, when
  `buatPesanan()` sukses, then Kasir terbuka dengan pelanggan terisi **dan** keranjang berisi
  item itu. Nama, satuan, kategori, dan harga satuan diambil server dari tabel `produk`
  (`harga_jual`), bukan dari M.A.R.A; subtotal = harga x jumlah.
- **AC-6**: Given sebagian produk tidak ditemukan atau `is_active = 0`, when Kasir dibuka, then
  produk yang valid tetap masuk keranjang dan kasir melihat satu peringatan yang menyebut
  jumlah item yang gagal (mis. "1 item dari saran M.A.R.A tidak ditemukan, tambahkan manual").
- **AC-7**: Given semua produk gagal atau daftar kosong, when Kasir dibuka, then keranjang kosong
  seperti sekarang, dengan peringatan bila daftar sempat dikirim.
- **AC-8**: Given item otomatis sudah di keranjang, when kasir mengubah jumlah, mengganti harga
  lewat alur Kasir yang ada, menghapus, atau menambah item lain, then semuanya bekerja seperti
  keranjang biasa (item bukan terkunci).
- **AC-9**: Given saran dibuka dua kali (tombol ditekan ulang atau halaman Kasir di-refresh),
  when Kasir dibuka, then item tidak terdobel di keranjang.
- **AC-10**: Given kasir mengambil saran milik percakapan lain dengan memanipulasi id, when
  request dikirim, then daftar produk hanya dibaca dari saran **milik percakapan yang sedang
  diproses** dan masih aktif (belum kedaluwarsa / belum diganti).
- **AC-11**: Given M.A.R.A membaca tabel `produk` langsung (keputusan Aan 2026-10-18), when POS memvalidasi `produk_id` dari M.A.R.A, then POS tetap memeriksa sendiri bahwa produk ada, `is_active = 1` dan `harga_jual > 0`; produk yang tidak lolos dihitung gagal (AC-6); kebenaran `produk_id` dari M.A.R.A tidak pernah dipercaya. Tidak ada endpoint katalog baru di POS.
- **AC-12**: Given pelanggan lama dan keranjang tab utama masih berisi item, when kasir
  menyetujui penggantian, then keranjang lama dibuang dan diganti item dari saran (alur
  konfirmasi `buatPesanan()` yang sudah ada tidak berubah).

## 5. Batasan dan di luar cakupan

- Batasan:
  - Harga **tidak pernah** dipercaya dari M.A.R.A; hanya POS yang menghitung (AC-2, AC-5).
  - Hanya produk standar (satu `produk_id` + `jumlah`). Tidak ada konfigurasi cetak custom,
    banner, atau diskon.
  - Tidak ada pembuatan transaksi otomatis: keranjang hanya terisi, kasir yang membayar.
- Tidak termasuk:
  - Perubahan di repo M.A.R.A (agen di sisi itu menentukan sendiri `produk_id`; lihat bagian 7).
  - Auto-balas ke pelanggan.
  - Produk khusus (banner / custom / manual) diisi otomatis.
  - Diskon atau bonus otomatis.

## 6. Dampak aturan bisnis

- [x] Mengubah aturan bisnis (wajib dicatat di `docs/CHANGELOG.md`)
- [ ] Menyentuh data keuangan (`transaksi`, `pembayaran`, `tagihan`, kas) -- tidak menulis ke tabel
  keuangan; hanya mengisi keranjang di browser
- [x] Mengubah skema database (kolom baru di `mara_suggestions`, grup `inbox`; migrasi aditif, nullable)
- [x] Mengubah kontrak POS <-> M.A.R.A (endpoint baru + field baru di `POST /api/inbox/mara/suggestions`)

Urutan deploy bila disetujui: migrasi dulu, lalu kode POS, lalu M.A.R.A mulai mengirim daftar.
Selama M.A.R.A belum mengirim, perilaku POS sama seperti sekarang.

## 7. Asumsi dan pertanyaan terbuka

- Asumsi:
  - Keputusan Aan 2026-10-18: M.A.R.A mencari `produk_id` sendiri lewat akses baca ke database
    AuliaPos (bukan endpoint katalog, bukan mencocokkan nama di POS). Syarat keamanan, belum
    terverifikasi: akun database M.A.R.A hanya `SELECT` pada kolom `produk(id, nama, satuan,
    kategori_id, is_active)`, tanpa `harga_beli`, tanpa tabel lain milik kasir.
  - Keputusan Aan 2026-10-18: bila sebagian produk gagal, item yang valid tetap masuk dan
    kasir diberi peringatan (AC-6).
- Pertanyaan terbuka: tidak ada. Jawaban Aan 2026-10-18: pengubahan M.A.R.A dikerjakan Aan dan M.A.R.A sendiri; semua produk aktif boleh dipakai (M.A.R.A bisa membaca database).

## 8. Persetujuan (Gate 1)

- [x] Disetujui oleh: Aan, tanggal: 2026-10-18
