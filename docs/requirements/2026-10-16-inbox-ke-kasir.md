# Requirements: Integrasi Inbox ke Kasir ("Buat Pesanan")

- **Tanggal**: 2026-10-16
- **Status**: disetujui (Gate 1, 2026-10-16, via review Arsitek)
- **Tier SDLC**: A (fitur baru + perubahan skema database grup `inbox`)
- **Penanggung jawab**: pemilik proyek

## 1. Tujuan

Kasir yang sedang melayani pelanggan di Inbox WhatsApp bisa membuka halaman Kasir dengan data
pelanggan sudah terisi, tanpa mengetik ulang nama dan nomor, sehingga pembuatan pesanan lebih cepat
dan data pelanggan tidak ganda.

## 2. Kondisi saat ini (terverifikasi)

- Percakapan Inbox (`conversations`, DB `inbox`) dan pelanggan POS (`pelanggan`, DB `default`) tidak
  punya hubungan. Kasir harus mencari/mengetik pelanggan sendiri di halaman Kasir.
- Halaman Kasir sudah bisa terisi otomatis dari URL: `restorePelangganFromUrl()`
  (`public/assets/js/kasir-shared.js:880`) membaca `pelanggan_id`, `pelanggan_nama`, `pelanggan_telp`,
  `pelanggan_diskon` (`pelanggan_id` dan `pelanggan_nama` wajib ada). Format yang sama sudah dipakai
  `Pelanggan::update()` (`app/Controllers/Pelanggan.php:127`).
- Nama pelanggan dijaga unik secara aplikasi: `Pelanggan::simpan()` menolak nama yang sudah ada
  (`Pelanggan.php:47`); `Api::tambahPelanggan()` memakai ulang pelanggan dengan nama sama.
- Kolom `pelanggan.no_hp` berupa teks bebas (`varchar(20)`), format data produksi **belum diverifikasi**.
- Kolom nomor di percakapan: `phone` (terverifikasi, format `62xxx`) dan `manual_phone` (ketikan kasir).
  Tidak ada kolom `contact_number`.

## 3. User story

- Sebagai kasir, saya ingin menekan satu tombol di percakapan WhatsApp untuk langsung membuka Kasir
  dengan pelanggan yang sama sudah terpilih, supaya saya tidak mengetik ulang dan tidak membuat
  pelanggan ganda.

## 4. Acceptance criteria

- **AC-1**: Given kolom `conversations.pelanggan_id` belum ada, when migrasi dijalankan, then kolom
  `pelanggan_id INT UNSIGNED NULL` ada dan semua percakapan lama bernilai NULL.
- **AC-2**: Given percakapan pribadi dengan nomor yang sudah ada di `pelanggan` (format apa pun:
  `62...`, `08...`, `+62...`, ada spasi/strip), when kasir menekan "Buat Pesanan", then pelanggan lama
  dipakai (tidak ada baris baru) dan `conversations.pelanggan_id` terisi.
- **AC-3**: Given nomor belum ada di `pelanggan` dan nama tersedia, when tombol ditekan, then satu
  pelanggan baru dibuat (`nama` maks. 100 karakter, `no_hp` format `08...`, `diskon` 0) dan ditautkan.
- **AC-4**: Given percakapan sudah tertaut ke pelanggan yang masih ada, when tombol ditekan lagi,
  then pelanggan yang sama dipakai dan tidak ada baris baru.
- **AC-5**: Given `pelanggan_id` tertaut menunjuk pelanggan yang sudah dihapus, when tombol ditekan,
  then tautan diselesaikan ulang (AC-2/AC-3), bukan error.
- **AC-6**: Given percakapan grup, tidak ditemukan, atau dipegang kasir lain (bukan admin), when
  tombol ditekan, then ditolak (403/404/403) dan tidak ada data yang ditulis.
- **AC-7**: Given tidak ada nama yang layak (kosong/kurang dari 2 karakter), when tombol ditekan, then
  ditolak 422 dan tidak ada pelanggan dibuat; given nama sudah dipakai pelanggan lain dengan nomor
  berbeda, then ditolak 409 dengan pesan agar kasir mengubah nama lewat Edit Profil.
- **AC-8**: Given respons sukses, then berisi URL `/kasir?pelanggan_id&pelanggan_nama&pelanggan_telp&pelanggan_diskon`
  dan halaman Kasir terisi pelanggan tersebut.
- **AC-9**: Given tombol ditekan dua kali beruntun atau oleh dua kasir bersamaan, then hanya satu
  pelanggan yang terbentuk untuk percakapan itu, dan tombol nonaktif selama permintaan berjalan.

- **AC-10** (keamanan): Given request `POST /inbox/percakapan/(:num)/buat-pesanan` tanpa token CSRF
  yang benar, when dipanggil, then ditolak 403 dan tidak ada data yang ditulis. Token dikirim lewat
  header `X-CSRF-TOKEN` dan diperbarui dari field `csrf` di setiap balasan server.
- **AC-11**: Given percakapan pribadi (`jid_type='pn'`) yang `phone` dan `manual_phone`-nya kosong tetapi
  `chat_id` berbentuk `<digit>@s.whatsapp.net`, when tombol ditekan, then nomor diambil dari `chat_id`
  (sufiks `@s.whatsapp.net` dibuang) dan dipakai untuk pencocokan. Percakapan `lid` tidak pernah dipakai.
- **AC-12** (M.A.R.A): Given saran `balasan` atau `peringatan` dengan `action='buka_kasir'`, when kasir
  melihat panel saran, then muncul tombol "Buka di Kasir" yang menjalankan alur yang sama dengan tombol
  "Buat Pesanan" untuk percakapan yang sedang terbuka.
- **AC-13** (M.A.R.A): Given `action` selain `buka_kasir`, atau `action` pada `daftar-periksa`, when M.A.R.A
  mengirim saran, then ditolak 400 dan tidak ada baris yang disimpan; given nilai `action` tak dikenal
  sampai ke browser, then tidak ada tombol yang dirender.

## 5. Batasan dan di luar cakupan

- Tidak ada perubahan pada `Kasir.php`, `kasir-shared.js`, dan Gateway. Kontrak M.A.R.A hanya ditambah
  satu field opsional `action` (lihat bagian 7).
- Pencocokan pelanggan hanya lewat nomor persis (setelah dinormalisasi); tidak menebak berdasarkan
  kemiripan nama. Percakapan `@lid` tanpa nomor tidak dicocokkan lewat nomor.
- Tidak membuat transaksi otomatis; kasir tetap menyelesaikan pesanan di halaman Kasir.
- Tidak ada backfill `pelanggan_id` untuk percakapan lama.

## 6. Dampak aturan bisnis

- [x] Mengubah aturan bisnis (pelanggan dibuat otomatis dari Inbox; wajib dicatat di `docs/CHANGELOG.md`)
- [ ] Menyentuh data keuangan (`transaksi`, `pembayaran`, `tagihan`, kas)
- [x] Mengubah skema database (`conversations.pelanggan_id` dan `mara_suggestions.action`, DB `inbox`)
- [ ] Mengubah kontrak POS <-> WA Gateway (perubahan lintas repositori)
- [x] Menambah field opsional ke kontrak M.A.R.A -> AuliaPos (`action`); sisi M.A.R.A belum diverifikasi

## 7. Pemanggilan dari M.A.R.A (tombol "Buka di Kasir")

M.A.R.A **tidak** memanggil `buat-pesanan` sendiri. Endpoint itu memakai sesi kasir dan token CSRF, dan
M.A.R.A tidak punya keduanya. M.A.R.A hanya menempelkan **pengenal aksi** pada saran; kasir yang
menekan tombolnya, lalu browser kasir memanggil endpoint dengan sesi kasir itu sendiri. Dengan begitu
M.A.R.A tidak bisa membuat pelanggan atau mengarahkan siapa pun, dan semua aturan pada AC-2 sampai AC-9
tetap berlaku (termasuk penolakan untuk grup dan percakapan milik kasir lain).

Contoh payload (`POST /api/inbox/mara/suggestions`, Bearer token M.A.R.A, tidak berubah selain `action`):

```json
{
  "conversation_id": 403,
  "type": "peringatan",
  "priority": "tinggi",
  "text": "Pelanggan minta cetak 200 lembar, nilai di atas Rp50.000. Buatkan pesanan.",
  "reason": "order besar terdeteksi",
  "action": "buka_kasir"
}
```

Aturan field `action`:

- Opsional. Kosong atau `null` berarti tanpa tombol.
- Nilai yang sah saat ini hanya `buka_kasir` (whitelist di `MaraController::SUGGESTION_ACTIONS`).
- Hanya untuk `type` `balasan` atau `peringatan`. Pada `daftar-periksa` atau nilai lain: **400**, saran ditolak utuh.
- Bukan URL dan bukan parameter. Tombol selalu bekerja untuk percakapan yang sedang terbuka di layar, bukan
  untuk id dari data saran.
- Disimpan di kolom `mara_suggestions.action` (`VARCHAR(30) NULL`, migrasi `2026-10-17-000001`).
  Jalankan migrasi **sebelum** M.A.R.A mulai mengirim `action`; kode tanpa migrasi tetap menyimpan
  saran biasa karena kolom hanya ditulis bila `action` dikirim.

Sisi browser: `inbox-suggestions.js` merender tombol hijau "Buka di Kasir" bila `action` ada di daftar
dikenal, lalu `jalankanAksiSaran()` memanggil `buatPesanan()` di `inbox-page.js`. Penambahan aksi baru
perlu perubahan di tiga tempat sekaligus: whitelist PHP, daftar `AKSI_SARAN_DIKENAL`, dan cabang
`jalankanAksiSaran()`.

## 8. Penanganan CSRF

- Filter `csrf` **global tetap mati** sesuai keputusan TODO-BL22b (2026-10-11); `Filters.php` dan
  `Security.php` tidak diubah di fitur ini.
- Route `buat-pesanan` dipasang `['filter' => ['auth', 'csrf']]` di `Routes.php`, sehingga hanya route ini
  yang diverifikasi CSRF. Token dikirim lewat header `X-CSRF-TOKEN`.
- Token awal dirender di `INBOX_PAGE_CONFIG.csrf` (`csrf_header()` dan `csrf_hash()`). Karena
  `Security::$regenerate = true`, token berganti setiap POST yang lolos; setiap balasan endpoint (sukses dan
  error) membawa token baru di field `csrf` dan `inbox-page.js` memperbaruinya.
- Cookie CSRF berstatus `httponly`, jadi JS tidak bisa membacanya; itu sebabnya token disisipkan server.
- Bila halaman Inbox terbuka di dua tab, token tab lama kedaluwarsa dan klik berikutnya dijawab 403 dengan
  pesan agar memuat ulang halaman.

## 9. Asumsi dan pertanyaan terbuka

- Asumsi: diskon pelanggan yang dikirim ke Kasir adalah nilai `pelanggan.diskon` sebenarnya (0 untuk
  pelanggan baru), sama seperti memilih pelanggan itu lewat pencarian di Kasir; nilai final tetap
  dihitung ulang di backend saat transaksi disimpan.
- Pertanyaan: apakah nama bentrok (AC-7, 409) sebaiknya otomatis memakai pelanggan lama bernama sama?
  Untuk sementara ditolak agar tidak salah orang.

## 10. Persetujuan (Gate 1)

- [x] Disetujui oleh: pemilik proyek (via Arsitek), tanggal: 2026-10-16
