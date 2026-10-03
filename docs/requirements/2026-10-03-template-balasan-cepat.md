# Requirements: Template Balasan Cepat (Inbox WhatsApp)

- **Tanggal**: 2026-10-03
- **Status**: disetujui
- **Tier SDLC**: A (fitur baru, menyentuh UI Inbox + tabel database baru)
- **Penanggung jawab**: -

## 1. Tujuan

Kasir sering mengetik ulang balasan yang sama berkali-kali (mis. QRIS pembayaran,
info jam buka, info ongkir) di Inbox WhatsApp. Fitur ini menyediakan daftar
template balasan siap pakai (kombinasi gambar + teks) yang bisa dipilih kasir
untuk mempercepat respons ke pelanggan, tanpa mengetik ulang dari nol.

## 2. Kondisi saat ini (terverifikasi)

- Composer balasan Inbox (`app/Views/inbox/index.php:872-883`) hanya berisi:
  tombol lampirkan media (`btnLampirkanMedia`), textarea (`teksBalasan`), dan
  tombol kirim (`btnKirimBalasan`). Tidak ada mekanisme template/saved-reply.
- Lampiran media sudah mendukung antrian multi-file dengan preview sebelum
  kirim (`antrianMediaBalasan`, `index.php:2746-2825`, PR #47/commit `6084e32`,
  fitur *multi-file attach*) — pola composer-preview-kirim sudah ada dan
  terbukti bekerja.
- Pengiriman media ke pelanggan lewat `POST /inbox/kirim-media`
  (`Inbox.php:1255-1357`), menerima `conversation_id`, `caption`, `media`
  (file upload), `operation_id` (idempotensi), `quoted_message_id` (opsional).
- Tidak ada pola *variable substitution* (`{nama_pelanggan}`, dll) di aplikasi
  mana pun — dikonfirmasi lewat pencarian kode, tidak ditemukan di
  `app/Libraries`/`app/Services`.
- Pola CRUD master data sederhana sudah ada dan konsisten: contoh `Kategori`
  (`app/Controllers/Kategori.php`, migrasi gabungan
  `app/Database/Migrations/2026-09-08-000001_CreateAuliaPosCore.php`, model
  `KategoriModel.php`, view `app/Views/kategori/tambah.php`) — migrasi + model +
  controller 6-method (index/tambah/simpan/edit/update/hapus) + view form.
- Role dibatasi lewat whitelist string prefix di `app/Filters/AuthFilter.php:70`
  (`$adminRoutes`) — hanya 2 role efektif: `admin` dan `kasir`
  (`app/Models/UserModel.php:12-23`).
- Pola upload file dengan penyimpanan di luar docroot publik (`WRITEPATH/`,
  bukan `public/`) sudah ada di `app/Libraries/FotoProfilService.php` — nama
  file dibuat server (`random_bytes` hex + ekstensi dari MIME tervalidasi),
  database hanya menyimpan nama file, validasi MIME dari isi file (bukan
  ekstensi), dan file di-stream lewat controller (bukan diakses langsung
  sebagai static file).
- `cekBukanGrup()` (`Inbox.php:1054-1061`) membatasi beberapa aksi (ambil,
  lepas, tutup, snooze, handoff) khusus percakapan personal — bukan berarti
  SEMUA fitur baru otomatis harus ikut membatasi grup; ini keputusan per-fitur.

## 3. User story

- Sebagai **kasir**, saya ingin memilih balasan siap pakai (gambar dan/atau
  teks) dari daftar template, supaya saya tidak perlu mengetik ulang atau
  mencari ulang gambar (mis. QRIS) setiap kali pelanggan bertanya hal yang
  sama.
- Sebagai **admin**, saya ingin mengelola (tambah/ubah/hapus) daftar template
  balasan, supaya isi dan gambar template selalu konsisten dan terkini untuk
  semua kasir.

## 4. Acceptance criteria

**Pengelolaan template (admin)**

- **AC-1**: Given saya login sebagai admin, when saya membuka halaman kelola
  Template Balasan, then saya melihat daftar template yang ada, diurutkan
  berdasarkan nama (A-Z).
- **AC-2**: Given saya admin membuka form tambah template, when saya mengisi
  nama template dan SALAH SATU dari (gambar ATAU teks) lalu simpan, then
  template tersimpan dan muncul di daftar.
- **AC-3**: Given saya admin mengisi form tambah template TANPA gambar dan
  TANPA teks, when saya simpan, then sistem menolak dengan pesan error yang
  jelas (minimal salah satu wajib diisi).
- **AC-4**: Given gambar yang saya upload melebihi batas ukuran
  (`InboxConfig->maxMediaUploadMb`) atau bukan tipe gambar yang didukung
  (image/jpeg, image/png, image/webp — selaras `FotoProfilService`), when saya
  simpan, then sistem menolak dengan pesan error yang jelas, file tidak
  tersimpan.
- **AC-5**: Given saya admin mengedit template yang ada, when saya ganti
  gambar/teksnya dan simpan, then perubahan tersimpan dan gambar lama (jika
  diganti) dihapus dari disk.
- **AC-6**: Given saya admin menghapus sebuah template (dengan konfirmasi
  dialog), when saya konfirmasi, then baris template dan file gambarnya (jika
  ada) terhapus permanen, dan hilang dari daftar.
- **AC-7**: Given saya login sebagai kasir, when saya mencoba mengakses
  halaman kelola Template Balasan (tambah/edit/hapus) secara langsung via URL,
  then saya ditolak ("Akses ditolak. Hanya untuk admin."), konsisten dengan
  pola `AuthFilter`.

**Pemakaian template (kasir, di Inbox)**

- **AC-8**: Given saya kasir sedang membuka sebuah percakapan (personal atau
  grup), when saya klik tombol Template di composer, then daftar nama
  template muncul (modal/dropdown), diurutkan A-Z.
- **AC-9**: Given daftar template terbuka, when saya pilih salah satu
  template, then gambar (jika ada) dan teks template masuk ke area
  preview/composer — BELUM terkirim ke pelanggan.
- **AC-10**: Given template sudah masuk composer, when saya ubah teksnya atau
  ganti/hapus gambarnya sebelum kirim, then composer memperlakukannya sama
  seperti lampiran media biasa (bisa diedit/dihapus, lihat `antrianMediaBalasan`
  yang sudah ada).
- **AC-11**: Given template (gambar+teks) sudah ada di composer, when saya
  klik tombol Kirim, then pesan terkirim ke pelanggan lewat jalur
  `/inbox/kirim-media` (jika ada gambar) atau `/inbox/kirim` (jika teks saja),
  identik dengan alur kirim manual yang sudah ada (idempotensi `operation_id`
  tetap berlaku).
- **AC-12**: Given tidak ada template yang dibuat admin, when kasir klik
  tombol Template, then tampil pesan kosong yang jelas (mis. "Belum ada
  template balasan"), bukan error atau daftar kosong tanpa keterangan.

## 5. Batasan dan di luar cakupan

- Batasan teknis/bisnis:
  - Hanya **1 gambar per template** (bukan multi-gambar) — kasus kirim banyak
    gambar sekaligus sudah tertutupi fitur *multi-file attach* yang sudah ada
    (PR #47).
  - Ukuran/tipe gambar mengikuti batas upload media Inbox yang sudah ada
    (`InboxConfig->maxMediaUploadMb`), tipe dibatasi ke `image/jpeg`,
    `image/png`, `image/webp` (selaras `FotoProfilService`).
  - Gambar template disimpan di `WRITEPATH/uploads/balasan_template/` (di luar
    docroot publik), bukan di `inbox.mediaStoragePath` (folder itu khusus
    arsip media pesan pelanggan, bukan aset tetap POS).
- Tidak termasuk (di luar cakupan, bisa jadi fitur lanjutan nanti):
  - **Variabel personalisasi** (mis. `{nama_pelanggan}`, `{nomor_transaksi}`)
    — scope awal ini murni teks dan gambar statis. Perlu requirement terpisah
    kalau dibutuhkan nanti (data transaksi POS ada di database terpisah,
    `aulia_kasirdb`, tidak terhubung langsung ke `conversations`).
  - **Pencarian/kategori template** — tidak dibutuhkan untuk perkiraan volume
    saat ini (< 10 template).
  - **Urutan tampilan manual (reorder/drag)** — urutan otomatis berdasarkan
    nama (A-Z) sudah cukup untuk volume kecil.
  - **Soft-delete/riwayat template yang dihapus** — hard delete langsung
    sudah cukup karena ini bukan data transaksional/finansial.
  - **Statistik pemakaian template** (mis. "template X dipakai N kali") —
    tidak diminta, bisa jadi fitur lanjutan.
  - Kasir **tidak bisa** membuat/mengubah template sendiri — murni admin-only.

## 6. Dampak aturan bisnis

- [ ] Mengubah aturan bisnis (wajib dicatat di `docs/CHANGELOG.md`)
- [ ] Menyentuh data keuangan (`transaksi`, `pembayaran`, `tagihan`, kas)
- [x] Mengubah skema database — tabel baru `balasan_template` (lihat §7)
- [ ] Mengubah kontrak POS <-> WA Gateway (perubahan lintas repositori) —
      TIDAK ada perubahan kontrak; pengiriman tetap lewat endpoint
      `/inbox/kirim` dan `/inbox/kirim-media` yang sudah ada, tanpa payload
      baru ke Gateway.

## 7. Asumsi dan pertanyaan terbuka

- Asumsi:
  - Tabel baru `balasan_template` disimpan di database **kasir**
    (`aulia_kasirdb`, koneksi `database.default`), BUKAN di `aulia_inboxdb`,
    karena ini aset pengaturan POS yang dikelola admin (sama kelasnya dengan
    `kategori`/`produk`), bukan data arsip percakapan WhatsApp. Diperkuat oleh
    temuan: `KategoriModel` TIDAK men-set `$DBGroup` (otomatis pakai
    `database.default`/`aulia_kasirdb`), sedangkan `ConversationModel` dan
    `MessageModel` eksplisit set `$DBGroup='inbox'` — pola ini menunjukkan
    pemisahan yang konsisten antara "pengaturan POS, dikelola admin" vs "arsip
    percakapan WhatsApp". Masih perlu konfirmasi eksplisit di Gate 2 (desain)
    karena controller `Inbox.php` yang akan memakai tabel ini terbiasa bekerja
    dengan koneksi `inbox`.
  - Kolom tabel: `id`, `nama` (unique, required), `teks` (nullable),
    `gambar_filename` (nullable, nama file saja seperti pola
    `FotoProfilService`), `created_at`, `updated_at`. Validasi aplikasi
    (bukan constraint DB) memastikan `teks` dan `gambar_filename` tidak
    kosong dua-duanya (AC-3).
  - Nama route/menu sementara: `/pengaturan/balasan-template` atau serupa
    (lokasi menu di sidebar admin perlu diselaraskan dengan struktur menu
    yang sudah ada — belum dicek).
- Pertanyaan (maks. 3, hanya yang mengubah hasil):
  1. Tabel `balasan_template` sebaiknya di `aulia_kasirdb` (pengaturan POS,
     admin-managed) atau `aulia_inboxdb` (selaras modul Inbox yang
     memakainya)? Ini memengaruhi `$DBGroup` model dan migrasi mana yang
     dipakai.
  2. Di menu sidebar mana halaman kelola template ini sebaiknya diletakkan
     (mis. submenu baru di bawah "Pengaturan", atau dekat menu Inbox)?

## 8. Persetujuan (Gate 1)

- [ ] Disetujui oleh: <nama>, tanggal: <YYYY-MM-DD>
