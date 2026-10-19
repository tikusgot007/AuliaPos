# Requirements: Perbaikan Alur Identitas Pelanggan (Kasir & Inbox)

- **Tanggal**: 2026-10-19
- **Status**: draf
- **Tier SDLC**: A
- **Penanggung jawab**: Aan / Hermes Agent

## 1. Tujuan

Menghilangkan kebingungan kasir dan ketidakpastian identitas pelanggan antara pelanggan lama dan baru, baik saat transaksi langsung di meja Kasir (offline) maupun saat pembuatan pesanan dari percakapan WhatsApp di Inbox.

## 2. Kondisi saat ini (terverifikasi)

1. **Auto-select agresif di Kasir (`public/assets/js/kasir-shared.js:818-832`)**: saat kasir mengetik nama di `#namaPelanggan`, jika ketikan cocok persis dengan salah satu hasil AJAX, fungsi `pilihPelanggan()` langsung dijalankan otomatis, menutup dropdown, mengunci field `#telpPelanggan` menjadi `readonly`, dan mengisi nomor HP lama. Jika kasir lanjut mengetik, field dikosongkan kembali (`line 776`).
2. **Edit nomor telepon melempar halaman (`public/assets/js/kasir-shared.js:888-892`)**: tombol edit di samping nomor telepon mengarahkan browser ke `/pelanggan/edit/{id}?return=kasir`, mengganggu antrean transaksi kasir.
3. **Backend diam-diam mencomot ID lama saat kasir input baru (`app/Controllers/Api.php:208-215`)**: saat frontend mengirim `pelanggan: 'new'`, backend menjalankan `findByName($pelangganNama)`. Jika ada nama yang sama, ID lama dipakai dan nomor HP baru yang diketik kasir diabaikan. Berseberangan dengan `app/Controllers/Transaksi.php:1446-1450` yang selalu `insert()`.
4. **Inbox WhatsApp kasus pelanggan tanpa nomor (`app/Controllers/Inbox.php:3349-3351`)**: pencocokan pelanggan hanya lewat `findByNomor($nomor)`. Jika pelanggan pernah bertransaksi offline tanpa nomor HP (hanya nama, misal "Soleha"), Inbox tidak menemukannya dan membuat pelanggan baru `SOLEHA {4DIGIT}`, menghasilkan data ganda tanpa konfirmasi ke kasir.

## 3. User story

- Sebagai **kasir**, saya ingin mengetik nama pelanggan tanpa dibajak otomatis oleh sistem, agar saya bebas menentukan apakah ini pelanggan baru atau lama.
- Sebagai **kasir**, saya ingin memilih pelanggan lama cukup dengan tombol keyboard (panah bawah + Enter) tanpa harus menyentuh mouse.
- Sebagai **kasir**, saya ingin bisa mengetik atau memperbarui nomor HP pelanggan langsung di meja kasir tanpa dialihkan ke halaman lain.
- Sebagai **kasir**, saat membuat pesanan dari Inbox untuk pelanggan yang namanya sudah ada di kasir tapi belum punya nomor HP, saya ingin sistem bertanya apakah ini orang yang sama atau bukan, agar riwayat belanjanya tidak terpecah.

## 4. Acceptance criteria

- **AC-1 (Kasir: Tanpa Auto-Select Membajak)**: Given kasir mengetik nama di `#namaPelanggan`, when ketikan cocok persis dengan nama pelanggan di database, then dropdown tetap terbuka dan sistem TIDAK memilih otomatis; pilihan hanya terjadi jika kasir menekan Enter pada item yang tersorot atau mengklik item tersebut.
- **AC-2 (Kasir: Opsi Pelanggan Baru Eksplisit)**: Given kasir mengetik nama baru yang belum ada di database atau ingin membuat entitas baru, when dropdown muncul, then baris terakhir selalu menampilkan opsi `+ Jadikan Pelanggan Baru: "<nama>"`.
- **AC-3 (Kasir: No HP Selalu Dapat Diedit di Tempat)**: Given kasir memilih pelanggan terdaftar atau memasukkan pelanggan baru, when kasir ingin mengisi/mengubah nomor HP di `#telpPelanggan`, then field nomor HP selalu aktif (tidak readonly) dan perubahan nomor HP tersimpan ke master pelanggan saat transaksi berhasil disimpan.
- **AC-4 (Kasir: Navigasi Keyboard Cepat)**: Given dropdown pencarian pelanggan terbuka, when kasir menekan tombol Panah Bawah/Atas dan Enter, then kasir dapat memilih pelanggan tanpa harus menyentuh mouse.
- **AC-5 (Kasir: Validasi Transaksi Piutang)**: Given kasir memilih metode pembayaran Piutang, when kasir tidak memilih pelanggan terdaftar dan nomor HP kosong, then sistem menolak transaksi dengan pesan jelas bahwa piutang membutuhkan identitas/nomor HP terverifikasi.
- **AC-6 (Inbox: Deteksi Nama Sama Tanpa Nomor HP)**: Given kasir menekan "Buat Pesanan" di Inbox untuk percakapan WhatsApp baru yang nomornya belum terdaftar, when di database ditemukan pelanggan bernama sama persis yang kolom `no_hp`-nya masih kosong/NULL, then modal Inbox menampilkan pilihan konfirmasi: (a) "Gunakan & Tautkan ke [Nama]" atau (b) "Buat Pelanggan Baru".
- **AC-7 (Inbox: Resolusi Pilihan Kasir)**: Given kasir memilih "Gunakan & Tautkan", when proses dilanjutkan, then nomor WA tersebut disimpan ke data pelanggan lama dan nama diperbarui sesuai format standar tanpa membuat baris baru di tabel pelanggan.

- **AC-8 (Format nama seragam)**: Given pelanggan dibuat dari jalur mana pun (Kasir, Inbox), when nomor HP ada, then `pelanggan.nama` disimpan `NAMA 4DIGIT` (uppercase), 4 digit dihitung server dari nomor HP, bukan ketikan kasir. Tanpa HP: `NAMA` polos, 4 digit ditambah saat nomor masuk. Tampilan nama di Inbox tetap nama biasa tanpa digit.
- **AC-9 (Nomor sama, nama beda)**: Given nomor WA sudah terdaftar atas nama lain (contoh: chat bilang "Alby", data pelanggan `SUHERMAN 1234`), when kasir klik "Buat Pesanan", then sistem memakai pelanggan lama (nomor = identitas), TIDAK membuat pelanggan baru, dan nama dari chat ("Alby") ditampilkan ke kasir sebagai catatan "atas nama".

- **AC-10 ("Atas nama" = kolom terpisah, opsional)**: Mengubah kolom nama di Kasir TETAP melepas pelanggan terpilih beserta nomornya (perilaku aman sekarang, `kasir-shared.js:769-778`); nama yang diketik tidak pernah mempertahankan nomor lama. Ada kolom terpisah "Atas nama (opsional)" yang tampil hanya saat ada pelanggan terpilih, kosong secara default. Given pelanggan terpilih `SUHERMAN 1234` dan kasir mengisi "Alby" di kolom itu, then transaksi masuk ke Suherman (piutang/riwayat/diskon ikut Suherman), "Alby" disimpan sebagai `transaksi.atas_nama`, nama master tidak ditimpa. Given kasir mengubah nomor ke nomor yang belum terdaftar, then dibuat pelanggan baru `ALBY <4 digit>` dan pelanggan lama tidak berubah. Given nomor diubah ke nomor yang sudah terdaftar, then pakai pelanggan pemilik nomor itu.
- **AC-11 (Atas nama otomatis dari Inbox)**: Given chat Inbox mengaku "Alby" dan nomor terverifikasi milik `SUHERMAN 1234`, when kasir klik "Buat Pesanan", then kolom "Atas nama" di Kasir terisi "Alby" otomatis.
- **AC-12 (Tampilan "atas nama")**: Bila `atas_nama` terisi, nama pelanggan transaksi tampil `Alby(Suherman)`. Dikerjakan di Tahap 2 (daftar, laporan, arsip); Tahap 1 hanya menyimpan datanya.

## 5. Batasan dan di luar cakupan

- Transaksi tunai/QRIS umum (walk-in tanpa nama/nomor HP) tetap diperbolehkan tanpa membuat baris baru di tabel pelanggan.
- Tidak ada migrasi massal data pelanggan lama.
- Skema tabel `pelanggan` tidak diubah (kolom `nama`, `no_hp`, `alamat`, `diskon` tetap sama).

## 6. Dampak aturan bisnis

- [x] Mengubah aturan bisnis (wajib dicatat di `docs/CHANGELOG.md`)
- [x] Menyentuh data keuangan (`transaksi`, `pembayaran`, `tagihan`, kas)
- [x] Mengubah skema database (kolom nullable baru `transaksi.atas_nama`, migrasi default group)
- [ ] Mengubah kontrak POS <-> WA Gateway (perubahan lintas repositori)

## 7. Asumsi dan pertanyaan terbuka

- Asumsi: nama pelanggan yang dicari untuk pencocokan Inbox hanya yang kolom `no_hp`-nya NULL atau string kosong. Pelanggan lama yang sudah punya nomor HP berbeda tidak akan disarankan untuk ditautkan otomatis (mencegah penimpaan nomor orang lain).

## 8. Persetujuan (Gate 1)

- [x] Disetujui oleh: Aan, tanggal: 2026-10-19
