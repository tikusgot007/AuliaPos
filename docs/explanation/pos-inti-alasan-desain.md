# Alasan Desain POS Inti AULIA

> [!NOTE]
> Dokumen ini bertipe **Explanation** (Diátaxis): menjelaskan *mengapa* POS inti dirancang seperti ini. Untuk langkah kerja gunakan panduan how-to, dan untuk fakta lengkap gunakan referensi. Aturan bisnis yang otoritatif tetap berada di [`docs/AULIA.md`](../AULIA.md).

## 1. Pengantar

Dokumen ini ditujukan bagi tiga pembaca, berurutan menurut prioritas:

1. **Developer atau kontributor baru**, yang perlu memahami mengapa kode ditulis dengan cara tertentu sebelum mengubahnya.
2. **Admin atau pemilik toko**, yang perlu memahami mengapa sistem menolak tindakan tertentu.
3. **Kasir**, yang perlu memahami mengapa tombol tertentu tidak muncul atau ditolak.

Cakupannya adalah POS inti: siklus hidup `transaksi`, `pembayaran`, kas, dan `tagihan`. Modul Laporan, Jadwal, Inbox/Chat, dan Pencetakan tidak dibahas di sini.

## 2. Masalah yang diselesaikan

AULIA adalah POS satu toko untuk usaha cetak, foto, dan banner. Pekerjaan di toko seperti ini jarang selesai dalam satu kunjungan. Pelanggan memberi uang muka (DP), pekerjaan dikerjakan berhari-hari, lalu pelunasan terjadi belakangan. Akibatnya, "keadaan uang" dan "keadaan pekerjaan" hampir tidak pernah berubah bersamaan.

Pada versi awal, aturan seperti "kapan transaksi boleh ditandai selesai" atau "bagaimana total dibayar dihitung" tersebar di banyak controller dan endpoint API. Setiap salinan aturan berisiko menyimpang dari salinan lainnya, dan penyimpangan pada data keuangan sulit ditemukan setelah terjadi.

Karena itu, desain POS inti bertumpu pada satu prinsip: **aturan hidup di satu tempat, dan tempat itu adalah backend**. Controller hanya meneruskan permintaan, sedangkan keputusan diambil oleh model dan service.

## 3. Dua sumbu status yang independen

Sebuah `transaksi` memiliki dua status yang dicatat terpisah:

- **`status`** menggambarkan kondisi pekerjaan: `proses`, `selesai`, `batal`, atau `mangkrak`.
- **`status_pembayaran`** menggambarkan kondisi uang: `belum_bayar`, `dp`, atau `lunas`.

Keduanya sengaja tidak digabung. Contoh yang menjelaskan alasannya: pelanggan sudah melunasi banner, tetapi banner belum selesai dicetak. Uangnya final, pekerjaannya belum. Jika keduanya satu status, sistem terpaksa berbohong pada salah satu sisi.

Konsekuensi terpentingnya: **`lunas` tidak pernah otomatis menjadi `selesai`**. Transaksi `proses` yang sudah `lunas` tetap `proses` sampai pihak berwenang menandainya selesai secara eksplisit. Menurut `AULIA.md` bagian 16, `selesai` adalah final bagi pekerjaan, sedangkan `lunas` hanya final bagi pembayaran.

Kombinasi yang tampak janggal, yaitu `selesai` dengan `belum_bayar` atau `dp`, tetap mungkin muncul. Penyebabnya bukan alur normal, melainkan koreksi pembayaran yang membalik (`reversed`) pembayaran setelah transaksi selesai. Sistem memilih menerima kombinasi ini daripada memutar balik status pekerjaan secara otomatis, karena tidak ada aturan bisnis yang menyatakan pekerjaan yang sudah diserahkan harus "dibuka kembali".

## 4. Satu pintu tulis

### 4.1 Prinsip

Dokumentasi bisnis menyebut pendekatan ini **Opsi B**. Dua pintu utama:

| Jenis perubahan | Satu-satunya pintu |
|---|---|
| Perubahan `status` transaksi | `TransaksiModel::ubahStatus()` |
| Penambahan `pembayaran` | `TransaksiModel::tambahPembayaran()` |

`ubahStatus()` adalah satu-satunya kode yang menulis `status = 'selesai'`. `tambahPembayaran()` dipakai oleh POS, penambahan pembayaran, pelunasan tagihan, dan koreksi metode. Nilai metode pembayaran, nominal, dan rentang tanggal diperiksa di sana, sehingga tidak ada jalur yang lolos tanpa pemeriksaan.

Manfaatnya bersifat struktural: ketika aturan berubah, developer mengubah satu tempat, dan seluruh jalur ikut berubah. Tanpa pintu tunggal, setiap jalur baru harus mengulang pemeriksaan dan mudah lupa satu di antaranya.

### 4.2 "UI bukan enforcement"

Menyembunyikan tombol hanya lapisan pertama. Backend memvalidasi ulang setiap kali:

- role pengguna,
- kepemilikan transaksi (`kasir_id`),
- asal transaksi (`sumber`),
- status pembayaran,
- status transaksi saat ini.

Alasannya sederhana: klien dapat dimanipulasi, dan tampilan bisa tertinggal dari keadaan data. Pola yang sama muncul pada diskon, di mana persentase diskon pelanggan selalu dibaca ulang dari `pelanggan.diskon` dan tidak pernah dipercaya dari permintaan.

### 4.3 Siapa boleh menyelesaikan atau membatalkan

Keputusan siapa boleh melakukan apa mengikuti risiko kesalahannya:

- **Menyelesaikan** (`proses → selesai`) mensyaratkan `lunas` dan pihak berwenang: admin, Effective Shift Leader saat itu, atau kasir pemilik transaksi `kasir_pos` melalui endpoint khusus POS.
- **Membatalkan** dapat dilakukan admin atau Effective Shift Leader, baik dari `proses` maupun dari `selesai`. Kasir biasa tidak boleh. Aturan ini sengaja diseragamkan agar tidak ada dua standar yang berbeda untuk dua jenis pembatalan.

Pengertian Effective Shift Leader dijelaskan di [`docs/USER-SHIFT.md`](../USER-SHIFT.md).

## 5. Mengapa `batal` berbeda dari `mangkrak`

Keduanya tampak mirip, yaitu transaksi yang tidak berlanjut, tetapi maknanya berbeda:

| | `batal` | `mangkrak` |
|---|---|---|
| Makna | Transaksi dianggap tidak pernah terjadi | Transaksi benar-benar terjadi, tetapi macet tanpa kejelasan |
| `no_order` | Dikosongkan | Tetap dipertahankan |
| Jalan keluar | Tidak ada, `batal` bersifat final | Hanya kembali ke `proses` |
| Siapa boleh | Admin atau Effective Shift Leader | Admin saja |

Pemisahan ini menjaga kejujuran data. Bila transaksi macet ditandai `batal`, laporan seolah-olah pekerjaan itu tidak pernah ada, padahal ada DP yang sudah diterima. Status `mangkrak` melepas transaksi dari radar aktif (tagihan, badge notifikasi, pengingat kasir) tanpa mengklaim hal yang tidak benar.

`mangkrak` sengaja hanya untuk admin dan tidak diperluas ke Shift Leader. Keputusan "lepaskan dari radar" dan "masukkan lagi" dianggap kapabilitas tersendiri, terpisah dari pembatalan. Dari `mangkrak`, transaksi hanya boleh kembali ke `proses`, bukan langsung ke `selesai` atau `batal`, supaya tetap melewati validasi normal seperti pelunasan.

## 6. Riwayat pembayaran yang append-only

Kesalahan mencatat metode pembayaran, misalnya tercatat tunai padahal transfer, tidak diperbaiki dengan mengubah kolom `pembayaran.metode`. Sistem menandai baris lama `reversed` dan menyisipkan baris baru `aktif`.

Alasannya adalah keterauditan. Untuk data uang, pertanyaan "apa yang tercatat sebelumnya, dan siapa yang mengubahnya" harus selalu dapat dijawab. Mengubah baris di tempat menghapus jejak itu. Hanya pembayaran `aktif` yang dihitung dalam total.

Ada tiga hal berbeda yang tidak boleh dicampur:

- **Salah isi transaksi**: batalkan lalu buat transaksi baru.
- **Salah metode pembayaran**: koreksi pembayaran (`reversed` lalu `aktif`).
- **Uang benar-benar dikembalikan**: refund, proses terpisah.

### Cache `total_dibayar`

`transaksi.total_dibayar` adalah salinan hasil jumlah semua pembayaran aktif, disimpan agar tidak menjumlahkan ulang di setiap tampilan. Sumber kebenarannya tetap jumlah pembayaran aktif. `sinkronkanPembayaran()` memperbarui cache setelah tiap perubahan, dan `cekKonsistensiPembayaran()` membandingkannya dengan hasil hitung sebenarnya.

Trade-off-nya jelas: cache mempercepat baca tetapi berisiko menyimpang bila ada penulisan yang melewati jalur resmi. Perintah `aulia:repair-total-dibayar` ada sebagai jaring pengaman untuk menyelaraskan kembali.

## 7. Pembayaran backdate

Uang kadang sudah diterima lebih dulu, tetapi baru dicatat belakangan. Karena itu tiga kolom pada `pembayaran` memiliki arti yang dijaga ketat:

- `tanggal`: kapan uang benar-benar diterima.
- `kasir_id`: kasir yang benar-benar menangani uang, bukan otomatis yang mengetik.
- `created_at`: kapan baris dibuat di database, tidak pernah disentuh kode aplikasi.

Karena `tanggal` dan `created_at` terpisah, laporan dan saldo kas memakai `pembayaran.tanggal`, sehingga uang otomatis jatuh ke hari yang benar tanpa kode tambahan, sedangkan waktu pencatatan sebenarnya tetap terekam.

### Cara model mengenali backdate

Model tidak menerima penanda `is_backdate` dari klien. Pembayaran dianggap backdate bila `tanggal`-nya berbeda lebih dari 60 detik dari waktu server. Komentar pada kode menyebut alasannya: penanda terpisah dapat lupa disinkronkan dengan tanggal yang benar-benar dikirim, sedangkan membandingkan tanggalnya langsung tidak dapat salah.

### Batas yang tidak pernah dilonggarkan

Admin dan Shift Leader boleh melakukan backdate, tetapi dua aturan tetap berlaku bagi keduanya: tanggal tidak boleh di masa depan, dan tanggal tidak boleh sebelum hari transaksi. Perbandingan terhadap hari transaksi dilakukan pada tingkat tanggal, bukan jam, karena skenario utamanya adalah uang diterima lebih dulu di hari yang sama.

### Risiko yang diterima secara sadar

Bila `cash_opname` suatu tanggal sudah dilakukan lalu ada pembayaran yang di-backdate ke tanggal itu, hasil rekonsiliasi retroaktif dapat berbeda dari opname saat itu. Ini didokumentasikan sebagai risiko, bukan bug, dan tidak ada mekanisme koreksi otomatis.

## 8. Kas dan tagihan tanpa tabel bantu

### Kas dihitung real-time

Tidak ada tabel buku besar berjalan (running ledger) untuk kas. Saldo dihitung saat dibutuhkan dari `cash_expense` dan pembayaran tunai berstatus `aktif` pada tanggal tertentu, oleh `CashBalanceService`. Tabel saldo berjalan harus selalu disinkronkan dan mudah menyimpang, sedangkan menghitung ulang dari data sumber selalu benar selama data sumbernya benar.

### Tagihan ditentukan pembayaran

Sebuah transaksi masuk tagihan bila `status_pembayaran` adalah `belum_bayar` atau `dp`, dan `status` bukan `batal` maupun `mangkrak`. Status pekerjaan tidak menentukan apakah uang masih ditagih. Karena itu transaksi `selesai` yang masih `dp` tetap muncul di tagihan, dan pelunasannya tidak mengubah status transaksi.

### Jatuh tempo tanpa kolom database

Jatuh tempo dihitung dari `transaksi.tanggal` ditambah `Config\Tagihan::$defaultTempoHari` (bawaan 7 hari) setiap kali halaman tagihan dibuka, dan tidak disimpan. Ini keputusan produk untuk tidak menambah kolom atau migrasi. Konsekuensi yang diterima: satu nilai tempo berlaku untuk semua pelanggan, dan transaksi baru dianggap terlambat sehari setelah tanggal jatuh tempo, bukan pada hari jatuh tempo itu sendiri.

## 9. Service kalkulasi yang stateless

Logika hitung dipisahkan ke kelas di `app/Services/` yang tidak menyimpan state dan tidak menyentuh database, sehingga dapat diuji tanpa bootstrap framework:

| Service | Tugas | Pengujian |
|---|---|---|
| `KalkulasiStatusPembayaran` | Menghitung `status_pembayaran` dari total dibayar dan grand total | `KalkulasiStatusPembayaranTest` |
| `KalkulasiDiskonTransaksi` | Menghitung diskon, grand total, dan pembulatan | `KalkulasiDiskonTransaksiTest` |
| `KalkulasiJatuhTempo` | Menghitung tanggal jatuh tempo dan status terlambat | `KalkulasiJatuhTempoTest` |

Desain diskon memuat dua keputusan yang layak dipahami:

- Diskon persen pelanggan dan diskon manual **tidak pernah digabung**.
- Grand total dibulatkan ke bawah ke kelipatan Rp100, dan sisanya disimpan di `selisih_pembulatan` agar tidak ada selisih uang yang hilang tanpa jejak.

`CashBalanceService` bekerja dengan database sehingga tidak termasuk kelompok murni ini, dan saat ini tidak memiliki berkas uji unit sendiri di `tests/unit/`.

## 10. Batasan dan celah yang diketahui

Beberapa hal sengaja belum dibangun atau belum sempurna, dan dicatat pada `AULIA.md` bagian 15:

- Satu nilai jatuh tempo global; tidak dapat berbeda per pelanggan tanpa kolom baru.
- Belum ada mekanisme otorisasi generik selain Effective Shift Leader; sengaja ditunda sampai ada kebutuhan nyata.
- Sejumlah hal keamanan (daftar putih metode pembayaran di endpoint tertentu, validasi nilai kas dari klien, CSRF, audit log) teridentifikasi tetapi berada di luar cakupan perubahan bisnis.
- Fitur Closing Kas dan beberapa tampilan belum memiliki dokumentasi bisnis eksplisit.

Bagian ini mencerminkan keadaan saat dokumen ditulis. Periksa `AULIA.md` bagian 15 untuk daftar terkini.

## 11. Bacaan lanjutan

- [`docs/AULIA.md`](../AULIA.md): aturan bisnis otoritatif POS inti.
- [`docs/USER-SHIFT.md`](../USER-SHIFT.md): Priority dan Effective Shift Leader.
- [`docs/CHANGELOG.md`](../CHANGELOG.md): riwayat keputusan dan alasannya.
- [`docs/README.md`](../README.md): peta seluruh dokumentasi.
