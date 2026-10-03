# Requirements: Unduh gambar & file di Inbox WhatsApp

- **Tanggal**: 2026-10-03
- **Status**: disetujui (rencana disetujui user di sesi; verifikasi manual di Brave kasir masih menunggu)
- **Tier SDLC**: A (perilaku baru di UI dan header respons; tanpa perubahan kontrak Gateway)
- **Penanggung jawab**: user (pemilik toko)

## 1. Tujuan

Kasir dapat melihat, menyimpan, dan mengunduh banyak gambar/dokumen dari percakapan pelanggan dengan mudah, tanpa klik kanan dan tanpa tab berisi JSON saat media gagal.

## 2. Kondisi saat ini (terverifikasi sebelum perubahan)

- `GET /inbox/media/:id` (`Inbox::media()`): `attachment` hanya untuk dokumen; nama file hanya `addslashes()`, fallback `media-<id>` tanpa ekstensi; error berupa JSON.
- Gambar tidak bisa diklik (simpan hanya lewat klik kanan); dokumen berupa tautan `target=_blank`; saat media kadaluarsa/Gateway mati kasir melihat tab kosong berisi JSON.
- Audio/video sengaja tidak diambil (keputusan desain lama) dan tidak termasuk cakupan ini.

## 3. User story

- Sebagai kasir, saya ingin klik gambar untuk melihatnya besar dan menekan Unduh, supaya file tersimpan dengan nama dan ekstensi yang benar.
- Sebagai kasir, saya ingin kartu dokumen yang menampilkan jenis dan ukuran file, supaya saya tahu isinya sebelum mengunduh.
- Sebagai kasir, saya ingin memilih beberapa gambar/dokumen lalu mengunduhnya sekaligus ke folder Download, tanpa ZIP.

## 4. Acceptance criteria

- **AC-1**: Given gambar di thread, when diklik, then lightbox terbuka dengan tombol Unduh; Esc/klik latar menutupnya; polling tidak menutupnya.
- **AC-2**: Given gambar/sticker, when kursor di atasnya, then ikon unduh muncul; menekannya mengunduh file bernama `media-<id>.<ekstensi>` (ekstensi dari mime) atau nama asli bila ada.
- **AC-3**: Given dokumen, then tampil kartu berisi ikon sesuai ekstensi, nama (di-escape), ekstensi/ukuran bila ada, dan tombol Unduh; nama non-ASCII tersimpan benar.
- **AC-4**: Given media kadaluarsa (410), terlalu besar (413), atau Gateway tidak terhubung (502/503/504/jaringan), when Unduh ditekan, then muncul toast dengan penyebab yang jelas, bukan tab JSON.
- **AC-5**: Given mode pilih aktif, then hanya bubble gambar/dokumen/sticker yang bisa dicentang, dengan kotak centang besar di sisi kosong bubble (kanan untuk pesan masuk, kiri untuk pesan keluar); tombol Balas/Teruskan tersembunyi; pilihan bertahan melewati polling; keluar mode atau ganti percakapan menghapus pilihan.
- **AC-6**: Given N media terpilih, when Unduh ditekan, then diunduh berurutan (jeda ~300 ms), maksimum 100 per aksi, dengan ringkasan "X berhasil, Y gagal (rincian)"; yang gagal tetap tercentang.
- **AC-7**: Given `?unduh=1`, then respons `Content-Disposition: attachment`; tanpa parameter, hanya gambar non-SVG yang `inline`; semua respons membawa `X-Content-Type-Options: nosniff`.

## 5. Batasan dan di luar cakupan

- Batasan teknis: ukuran dibatasi `maxMediaDownloadMb` (server); unduhan massal memakai folder Download browser (tanpa `showDirectoryPicker`, karena alamat akses HTTPS/LAN belum diketahui). Brave dapat meminta izin "unduh banyak file" sekali; itu perilaku browser.
- Tidak termasuk: audio/video, ZIP, zoom/galeri di lightbox, unduh dari kotak kutipan, perubahan WA-Gateway.

## 6. Dampak aturan bisnis

- [ ] Mengubah aturan bisnis (wajib dicatat di `docs/CHANGELOG.md`)
- [ ] Menyentuh data keuangan (`transaksi`, `pembayaran`, `tagihan`, kas)
- [ ] Mengubah skema database
- [ ] Mengubah kontrak POS <-> WA Gateway (perubahan lintas repositori)

Catatan: perubahan perilaku UI/unduh dicatat di CHANGELOG sebagai perubahan operasional.

## 7. Asumsi dan pertanyaan terbuka

- Asumsi: `media_size`, `media_mime_type`, `media_filename` ikut dikirim ke klien oleh API pesan (kolom dibaca penuh oleh `getPageByConversation()`); ukuran bisa kosong untuk pesan lama.
- Belum diverifikasi: tampilan di Brave kasir, perilaku izin unduh berganda, unduh dari media WhatsApp sungguhan.

## 8. Persetujuan (Gate 1)

- [x] Disetujui oleh: user, tanggal: 2026-10-03 (rencana di sesi)
