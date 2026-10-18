# Requirements: Penyempurnaan "Buat Pesanan" Inbox (konfirmasi nama pelanggan baru + pengarahan ke tab utama)

- **Tanggal**: 2026-10-18
- **Status**: disetujui (Gate 1, 2026-10-18)
- **Tier SDLC**: A (aturan bisnis penamaan pelanggan + perubahan alur lintas halaman)
- **Penanggung jawab**: Aan
- **Sumber**: evaluasi alur bersama Aan 2026-10-18; usulan teknisi `usulan-fitur-buat-pesanan.md`.
- **Fitur dasar**: `docs/requirements/2026-10-16-inbox-ke-kasir.md` (commit `2662456`).

## 1. Tujuan

1. Master `pelanggan` tidak terisi nama profil WhatsApp yang samaran/alay/emoji. Kasir mengoreksi nama sebelum pelanggan baru disimpan, dengan format standar toko `NAMA 4DIGITHP`.
2. Tombol "Buat Pesanan" mengarahkan **tab utama** (tempat kasir bekerja) ke Kasir, bukan membuka tab Kasir baru, dengan pengaman supaya keranjang yang sedang dikerjakan tidak hilang tanpa sengaja.

## 2. Kondisi saat ini

Terverifikasi dengan membaca kode dan data produksi (read-only) 2026-10-18.

- `Inbox::buatPesanan()` (`app/Controllers/Inbox.php:3184`) langsung `INSERT` pelanggan baru bila nomor tidak ketemu. Nama = `contact_name`, lalu `whatsapp_name`, tanpa konfirmasi kasir, tanpa 4 digit HP. Nama < 2 karakter ditolak (422); nama sudah dipakai ditolak (409).
- `buatPesanan()` di `public/assets/js/inbox-page.js:676` selalu membuka tab baru (`window.open('', '_blank')`).
- Window Inbox dibuka dari sidebar sebagai `window.open(href, 'AuliaInbox', ...)` (`app/Views/layout/main.php:173`) tanpa `noopener`, jadi `window.opener` menunjuk tab pembukanya. Dibuka dari notifikasi (`inbox-notifikasi.js:222`) memakai nama window yang sama.
- `restorePelangganFromUrl()` (`public/assets/js/kasir-shared.js:879`) hanya mengisi pelanggan; keranjang lama tetap dipulihkan dari `sessionStorage` kunci `aulia_kasir_cart_v1` (`kasir-shared.js:59`). `kasir/edit` tidak mempersist keranjang (`cartStorageKey: null`).
- Data produksi `aulia_inboxdb` (542 percakapan pribadi): 540 punya `phone` valid (99,6%); 2 kosong (id 244 `@lid` murni; id 404 nomor Malaysia `60…` yang tidak lolos `PhoneNumber::normalize()`); 0 yang hanya punya `manual_phone`; hanya 1 percakapan sudah tertaut ke pelanggan.

Belum diverifikasi: ukuran kolom `pelanggan.nama` di DB produksi; perilaku `window.opener` setelah Inbox di-refresh (F5) di browser kasir yang dipakai.

## 3. User story

- Sebagai kasir, saya ingin mengoreksi nama pelanggan baru sebelum disimpan, supaya master pelanggan bersih dan seragam.
- Sebagai kasir, saya tidak mau mengetik 4 digit HP, supaya tidak salah ketik.
- Sebagai kasir, saya ingin tab Kasir yang sedang saya pakai yang terisi pelanggan dari Inbox, supaya tidak muncul banyak tab Kasir.
- Sebagai kasir, saya ingin diperingatkan bila keranjang yang sedang saya kerjakan akan tertimpa.

## 4. Acceptance criteria

**A. Konfirmasi nama pelanggan baru**

- **AC-1**: Given pelanggan sudah ada (terdaftar lewat nomor atau percakapan sudah tertaut) DAN namanya sudah berformat `NAMA 4DIGIT` dengan 4 digit sama dengan HP-nya, when "Buat Pesanan" ditekan, then tidak ada modal, tidak ada pelanggan baru, langsung ke Kasir.
- **AC-2**: Given nomor belum terdaftar dan `phone` terverifikasi, when "Buat Pesanan" ditekan, then muncul modal dengan DUA kolom terpisah: kolom nama (editable, terisi nama profil dalam HURUF BESAR; kosong bila nama profil kosong/hanya emoji) dan kolom 4 digit terakhir HP (read-only, tidak bisa diubah). Kasir hanya mengedit bagian nama. Belum ada baris `pelanggan` yang dibuat.
- **AC-3**: Given modal terbuka, when kasir menekan Enter atau "Simpan & Buka Kasir", then pelanggan disimpan dengan nama `<NAMA> <4 digit>` (contoh `RULY 6600`), ditautkan ke percakapan, lalu kasir diarahkan sesuai bagian B. Esc atau "Batal" menutup modal tanpa menyimpan apa pun.
- **AC-4**: Validasi **di server** (bukan hanya di browser): bagian nama diubah ke huruf besar, di-trim, minimal 2 karakter, maksimal 95 karakter (nama penuh <= 100); 4 digit selalu dihitung server dari nomor tersimpan dan input 4 digit dari klien diabaikan; nama penuh yang sudah dipakai pelanggan lain ditolak 409 dengan pesan jelas.
- **AC-5**: Given nomor belum terdaftar dan tidak ada nomor terverifikasi (`phone` kosong), when "Buat Pesanan" ditekan, then diblok dengan pesan "Konfirmasi nomor WhatsApp dulu" dan tidak ada pelanggan dibuat. Pengisian nomor memakai aksi konfirmasi nomor yang sudah ada (`Inbox::konfirmasiNomorWhatsapp()`), tanpa jalur baru.
- **AC-6**: Given dua kasir menyimpan pelanggan baru dari percakapan yang sama bersamaan, then hanya satu baris `pelanggan` yang terbentuk (kunci baris percakapan yang sudah ada dipertahankan).
- **AC-2b** (pelanggan lama yang belum sesuai format, keputusan Aan 2026-10-18 opsi 2): Given pelanggan sudah ada tetapi `no_hp` kosong atau namanya belum berformat/huruf besar, when "Buat Pesanan" ditekan, then modal yang sama muncul: kolom nama terisi nama lama (huruf besar, akhiran 4 digit lama dibuang dari kolom), kolom 4 digit terkunci dari `phone` terverifikasi percakapan. Saat disimpan, `nama` dan `no_hp` pelanggan itu diperbarui (UPDATE, bukan baris baru). Cek nama kembar (AC-4) tidak menghitung pelanggan itu sendiri. Bila percakapan tidak punya `phone` terverifikasi, berlaku AC-5.
- **AC-7**: Tombol "Buka di Kasir" dari saran M.A.R.A memakai alur AC-1..AC-6 yang sama tanpa kode terpisah.

**B. Pengarahan ke tab utama**

- **AC-8**: Given window Inbox punya `window.opener` yang masih terbuka, when "Buat Pesanan" selesai sukses, then `opener` diarahkan ke `/kasir?...` (halaman apa pun yang sedang dibuka) dan difokuskan. Tidak ada tab baru.
- **AC-9**: Given `opener` ada dan keranjang Kasir di sana berisi item, atau halamannya `kasir/edit`, when "Buat Pesanan" ditekan, then muncul konfirmasi di window Inbox "Tab Kasir masih berisi N item / sedang mengedit transaksi. Ganti dengan pesanan baru?" dengan tombol "Ganti" dan "Batal". "Batal" tidak mengubah apa pun (termasuk tidak membuat pelanggan baru).
- **AC-10**: Given `opener` ada dan keranjang kosong atau halamannya bukan Kasir, then langsung diarahkan tanpa konfirmasi.
- **AC-11**: Given `opener` tidak ada atau sudah ditutup, then fallback ke tab baru (dibuka sinkron di dalam klik supaya tidak diblokir popup blocker).
- **AC-12**: Setelah "Ganti" atau pengarahan langsung, Kasir menampilkan pelanggan dari Inbox dengan **keranjang kosong**: keranjang tersimpan di `sessionStorage` dibuang, tidak dipulihkan. Penanda di URL (mis. `dari_inbox=1`) dibersihkan setelah dipakai.

## 5. Batasan dan di luar cakupan

- Tidak ada perubahan skema database. Tabel `pelanggan` dan `conversations` dipakai apa adanya.
- **Di luar cakupan**: mengisi keranjang dari item saran M.A.R.A (Rek 3 usulan teknisi). Dicatat sebagai item TODO terpisah karena butuh kesepakatan kontrak dengan M.A.R.A; `mara_suggestions.items` sudah dipakai `daftar-periksa` di produksi. Harga tidak boleh dipercaya dari M.A.R.A (hitung server dari master produk).
- Di luar cakupan: nomor luar negeri seperti percakapan id 404 (`60…`). Tetap diblok oleh AC-5 sampai ada keputusan terpisah soal normalisasi nomor non-Indonesia.
- Pelanggan lama TIDAK diubah massal. Hanya pelanggan yang dipakai lewat "Buat Pesanan" dan belum sesuai format yang diperbarui (AC-2b). Data produksi 2026-10-18 (read-only, `aulia_kasirdb.pelanggan`): 2.086 pelanggan; 151 punya no HP dan semuanya SUDAH berformat `NAMA 4DIGIT` (digit cocok dengan HP); 1.935 tanpa no HP (4 digit tidak bisa dibentuk); 5 nama belum huruf besar; 0 nama kembar setelah huruf besar. Jadi migrasi massal tidak diperlukan; sisanya (1.935 tanpa HP) dirapikan bertahap lewat AC-2b.

## 6. Dampak aturan bisnis

- [x] Mengubah aturan bisnis (wajib dicatat di `docs/CHANGELOG.md`): nama pelanggan baru dari Inbox berformat `NAMA 4DIGITHP` huruf besar.
- [ ] Menyentuh data keuangan
- [ ] Mengubah skema database
- [ ] Mengubah kontrak POS <-> WA Gateway

## 7. Asumsi dan pertanyaan terbuka

- Asumsi: Inbox dan Kasir satu origin, sehingga `window.opener.sessionStorage` dan `.location` dapat diakses (perlu diuji di browser kasir).
- Aan 2026-10-18: pelanggan lama "ikut format" tersebut. Data menunjukkan semua pelanggan ber-HP sudah sesuai; yang tanpa HP tidak punya 4 digit. Keputusan Aan 2026-10-18: pelanggan lama dirapikan bertahap saat dipakai lewat "Buat Pesanan" (AC-2b); tanpa skrip massal.
- Risiko diterima: bila `opener` hilang (mis. Inbox dibuka dari bookmark), alur jatuh ke tab baru sehingga bisa ada lebih dari satu tab Kasir.
- Pertanyaan terbuka:
  1. (Terjawab: kolom `pelanggan.nama` = 100 karakter.)
  2. (Terjawab Aan 2026-10-18: nama kosong/emoji -> kolom nama dibiarkan kosong, kasir wajib mengetik.)

## 8. Persetujuan (Gate 1)

- [x] Disetujui oleh: Aan, tanggal: 2026-10-18
