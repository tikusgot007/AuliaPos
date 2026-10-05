# CHANGELOG

## 2026-10-05 — Koreksi pembayaran dan transaksi final

- Aturan lama: koreksi metode pembayaran membuat baris pengganti dengan `tanggal` dan `kasir_id` operator koreksi, sehingga histori tampak seperti pembayaran baru oleh operator tersebut.
- Aturan baru: semua kasir tetap boleh mengoreksi metode pembayaran; pembayaran asal mempertahankan waktu dan kasir pencatat, sedangkan operator koreksi dicatat pada histori koreksi terpisah.
- Aturan baru: transaksi `SELESAI` yang salah qty/harga dikoreksi melalui transaksi pengganti secara atomik; transaksi asli menjadi `BATAL`, detail dan histori pembayaran lama tetap tersimpan, dan transaksi pengganti mempertahankan status `SELESAI` bila tetap lunas.
- Refund akibat total koreksi yang lebih rendah tidak dibuat otomatis; selisih tetap menggunakan Kas Keluar kategori `refund_penjualan`.
- Alasan: mencegah histori pembayaran berubah, double-counting penjualan/kas, dan edit diam-diam terhadap transaksi final.
- Referensi: `docs/requirements/2026-10-05-koreksi-transaksi-final.md`, `docs/design/2026-10-05-koreksi-transaksi-final.md`, TODO-BL07/TODO-BL43.


## 2026-10-05 — Inbox: notifikasi Windows sebagai jalur utama (toast jadi fallback)

- Aturan lama: pesan Inbox yang perlu dibalas diberi tahu lewat toast hijau sticky di
  dalam halaman (plus judul tab, favicon, dan beep).
- Aturan baru: jalur utama pemberitahuan pesan Inbox adalah **notifikasi Windows**
  (browser `Notification` API) yang muncul walau tab POS tidak difokuskan; klik
  notifikasi membuka window Inbox ke percakapan itu. Toast hijau tetap ada **hanya**
  sebagai fallback bila API tidak tersedia atau izin user belum diberikan. Tombol
  "Aktifkan notifikasi" tampil di sidebar saat izin masih `default`.
- Alasan: kasir perlu tahu ada pesan WA perlu dibalas walau POS tidak sedang aktif /
  pesan masuk ke Action Center, bukan hanya saat halaman dipandang.
- Catatan operasional: `Notification` API hanya aktif di secure context; produksi
  HTTP LAN (`http://192.168.1.10/aulia`) harus disajikan HTTPS self-signed dulu
  (langkah infra terpisah, **belum dikerjakan**). Selama HTTP, yang aktif adalah
  fallback toast.
- Referensi: `docs/requirements/2026-10-05-notifikasi-windows-inbox.md`,
  `docs/design/2026-10-05-notifikasi-windows-inbox.md`,
  `public/assets/js/inbox-notifikasi.js`, `tests/js/inbox-notifikasi.test.js`.

## 2026-10-05 — Inbox WhatsApp: tampilkan teks edit yang sudah tervalidasi

- Aturan lama: semua pesan yang ditandai "diedit" ditampilkan samar dengan label
  "Pesan diedit — versi ini belum tentu terbaru", termasuk ketika Gateway sudah
  berhasil memproses dan menyimpan teks hasil edit yang benar.
- Aturan baru: pesan diedit yang teksnya SUDAH berhasil divalidasi dan disimpan
  POS ditampilkan normal dengan label "Pesan diedit — teks terbaru".
  Pesan diedit tanpa hasil edit tervalidasi tetap samar dengan label lama.
- Alasan: kasir perlu membedakan teks versi lama yang masih perlu dikonfirmasi
  dari teks hasil edit yang sudah tersedia dan tervalidasi di Inbox.
- Referensi: branch `todo-f8-message-text` (verification recorded 2026-10-05),
  `docs/requirements/2026-10-05-tampilkan-teks-edit-tervalidasi-inbox.md`,
  `docs/design/2026-10-05-tampilkan-teks-edit-tervalidasi-inbox.md`.
- Deploy produksi 2026-10-05: AuliaPos `v2.4` (`a127d63`) di AULIA-SERVER2 + migrasi
  `2026-10-05-000001` (kolom `edited_text_resolved_at` di `aulia_inboxdb`); gateway
  `evolution` di aulia3 dengan `EVOLUTION_DECRYPT_MESSAGE_EDIT=1`. Uji nyata F7 & F8
  lulus; lihat `docs/sesi/2026-10-05-deploy-produksi-f8.md`.

Perubahan aturan bisnis Aulia Kasir. Bahasa Indonesia (AGENTS.md §15).

Format entri:

```
## YYYY-MM-DD — <judul singkat>
- Aturan lama: <apa>
- Aturan baru: <apa>
- Alasan: <mengapa>
- Referensi: commit `<hash>`, docs/sesi/<file>
```

---

## 2026-10-04 — Inbox WhatsApp: tandai pesan yang diedit/dihapus pelanggan

- Aturan lama: pelanggan **mengedit** pesannya -> muncul baris noise
  `unsupported` ("...secretEncryptedMessage...") sementara pesan asli tetap
  menampilkan teks LAMA; pelanggan **menghapus** (untuk semua) pesannya -> tidak
  berjejak sama sekali dan pesan aslinya tetap tampil seolah masih ada.
- Aturan baru: pesan ASLI ditandai di Inbox — badge "Diedit pelanggan — versi ini
  belum tentu terbaru" (teks yang tampil adalah versi lama; isi edit tidak bisa
  dibaca) dan "Dihapus pelanggan — cek WhatsApp Web". Edit/hapus **tidak** lagi
  membuat baris pesan baru.
- Alasan: kasir tidak boleh salah membaca teks lama sebagai isi final, atau
  mengira pesan yang sudah dihapus masih berlaku. Isi hasil edit terenkripsi dan
  tidak terbaca gateway (spike 2026-10-04), jadi yang ditampilkan hanya penanda.
  Deteksi hapus butuh langganan webhook `MESSAGES_DELETE` di Evolution.
- Referensi: `docs/requirements/2026-10-04-tandai-pesan-diedit-inbox.md`,
  `docs/design/2026-10-04-tandai-pesan-diedit-inbox.md`, `docs/TODO.md` (TODO-F7).

## 2026-10-04 — Inbox WhatsApp: label edit/hapus tidak lagi menyebut "pelanggan"

- Aturan lama: badge selalu berbunyi "Diedit pelanggan — ..." / "Dihapus
  pelanggan — ..." untuk SEMUA pesan yang ditandai, tanpa membedakan arah.
- Aturan baru: badge jadi "Pesan diedit — versi ini belum tentu terbaru" /
  "Pesan dihapus — cek WhatsApp Web", tanpa menyebut pelaku.
- Alasan: event edit/hapus WhatsApp juga berlaku untuk pesan KELUAR
  (`direction=outgoing`) yang staf kirim & edit/hapus sendiri lewat WA Web/HP
  langsung (di luar POS) — ditemukan nyata saat uji end-to-end TODO-F8
  (2026-10-04, pesan `Wkwkw` diedit staf sendiri tetap berlabel "pelanggan").
  Label lama menyesatkan pada kasus ini.
- Referensi: `public/assets/js/inbox-thread.js` (`renderLabelDiedit`,
  `renderLabelDihapus`), `tests/js/inbox-thread.test.js`, `docs/TODO.md` (TODO-F7/F8).

## 2026-10-04 — Archive transaksi: piutang aktif tidak ikut diarsipkan

- Aturan lama: Archive Transaksi memindahkan **semua** transaksi pada bulan yang
  dipilih, tanpa memandang status pembayaran; transaksi `belum_bayar`/`dp`
  (piutang) ikut dipindah lalu dihapus dari DB utama, sehingga hilang dari daftar
  Tagihan.
- Aturan baru: hanya transaksi **`lunas`**, **`batal`**, atau **`mangkrak`** yang
  eligible diarsipkan. Piutang aktif (`belum_bayar`/`dp`, status bukan
  batal/mangkrak) **tetap** di DB utama. Piutang macet tetap bisa diarsipkan
  setelah admin menandainya `mangkrak` (katup keluar). Preview menampilkan jumlah
  & nilai piutang aktif yang **tidak** ikut diarsipkan.
- Alasan: piutang adalah data hidup yang masih harus ditagih; arsip tidak boleh
  menghilangkannya dari Tagihan (keputusan produk DEC-3: "A+ dengan katup E").
- Referensi: `app/Services/TransaksiArchiveService.php`,
  `app/Views/archive_transaksi/index.php`,
  `docs/requirements/2026-10-04-arsip-piutang.md`,
  `docs/design/2026-10-04-arsip-piutang.md`,
  `docs/TODO.md` (TODO-BL06, TODO-DEC3).

## 2026-10-04 — Transaksi POS: validasi baris item (jumlah/harga/subtotal)

- Aturan lama: jumlah, harga, dan subtotal tiap baris keranjang dipercaya apa
  adanya dari klien; `jumlah <= 0`, nilai negatif, dan `subtotal` yang tidak
  konsisten dengan `harga × jumlah` tetap tersimpan.
- Aturan baru: server menolak baris dengan `jumlah <= 0`, `jumlah > 9999`, harga
  atau subtotal negatif, dan (untuk item non-banner) `subtotal != harga × jumlah`.
  Item banner memakai `subtotal` (total masukan kasir) sebagai acuan dan
  `harga_satuan` dihitung server `round(subtotal / jumlah)`. Aturan ini dipakai
  bersama jalur buat & edit lewat `ValidasiItemTransaksi`.
- Alasan: laporan dan grand_total menjumlahkan `detail_transaksi.subtotal`;
  input klien yang tidak masuk akal bisa merusaknya.
- Referensi: `app/Services/ValidasiItemTransaksi.php`, `app/Controllers/Api.php`,
  `app/Controllers/Transaksi.php`,
  `docs/requirements/2026-10-04-validasi-item-transaksi.md`,
  `docs/design/2026-10-04-validasi-item-transaksi.md`, `docs/TODO.md` (TODO-BL03).

## 2026-10-04 — Closing kas: snapshot `saldo_sistem` bersifat final

- Aturan lama: `saldo_sistem` closing dihitung ulang dari data live setiap kali
  modal closing dibuka atau disimpan ulang. Setelah transaksi satu bulan
  dipindahkan ke arsip (baris live dihapus), hitung ulang menghasilkan penjualan
  tunai = 0 sehingga snapshot closing yang benar bisa tertimpa angka salah.
- Aturan baru: `saldo_sistem` closing yang sudah tersimpan bersifat **final**
  (imutabel); edit closing hanya mengubah `saldo_fisik` dan `selisih`
  (`selisih = saldo_fisik − saldo_sistem tersimpan`). Hitung ulang hanya untuk
  tanggal yang belum pernah di-closing, dan hitungan itu sudah mencakup
  penjualan tunai yang ada di database arsip.
- Alasan: closing adalah fakta historis; laporan kas & audit tidak boleh berubah
  hanya karena data operasional lama dipindahkan ke arsip.
- Referensi: `app/Controllers/Cash.php`, `app/Models/ClosingKasModel.php`,
  `app/Services/KalkulasiClosingKas.php`,
  `app/Services/TransaksiArchiveService.php`,
  `docs/requirements/2026-10-04-closing-kas-arsip.md`,
  `docs/design/2026-10-04-closing-kas-arsip.md`, `docs/TODO.md` (TODO-BL02).

## 2026-10-03 — Foto profil: batas ukuran 2 MB benar-benar ditegakkan

- Aturan lama: unggahan foto profil dibatasi 2 MB, tetapi validasinya memakai
  `UploadedFile::getSizeByUnit('kb')` yang mengembalikan string berformat ribuan
  (mis. `"2,048.000"`). Perbandingan `>` terhadap batas int karena itu selalu
  gagal, sehingga **file lebih dari 2 MB tetap tersimpan**.
- Aturan baru: batas 2 MB ditegakkan dengan membandingkan byte mentah
  (`getSize() > 2048 * 1024`); file lebih dari 2 MB ditolak dengan pesan
  "Ukuran file maksimal 2 MB." Nilai batasnya sendiri tidak berubah.
- Alasan: batas yang dijanjikan ke pengguna (maksimal 2 MB) harus benar-benar
  berlaku; bug ini membuat file besar lolos dan tersimpan ke disk.
- Referensi: `app/Libraries/FotoProfilService.php`,
  `tests/feature/FotoProfilServiceTest.php`, `docs/TODO.md` (TODO-F7); pola
  perbaikan sama dengan PR #48 (`BalasanTemplateImageService`).

## 2026-10-03 — Laporan: ekspor Excel saja (hapus Print/Copy/PDF) + format `.xls` berkolom

- Aturan lama: tabel laporan (Item Harian, Laporan Pembayaran, tab Periode, dan
  tab agregat Harian/Bulanan/Kategori) punya tombol Print/Copy/PDF/Excel; pada
  tabel server-side tombol hanya mencakup halaman aktif, dan CSV berpemisah `;`
  terbaca **satu kolom** di Excel (setting locale tertentu).
- Aturan baru: hanya **satu tombol Excel** per tabel; Print, Copy, dan PDF
  dihapus. Ekspor server-side menghasilkan berkas **`.xls` (tabel HTML)** via
  `App\Libraries\ExcelTable`, sehingga Excel membuka dengan kolom yang benar
  tanpa bergantung pada pemisah daftar/locale. Item Harian & Laporan Pembayaran
  memakai endpoint ekspor server-side yang sudah ada; tab Periode memakai
  `/laporan/periode-export` (seluruh baris terfilter); tab agregat tetap
  client-side (`excelHtml5`, seluruh baris).
- Alasan: kebutuhan operasional hanya ekspor Excel; tombol cetak/salin/PDF hanya
  mencakup halaman aktif dan format CSV berdelimiter menyesatkan kasir.
- Referensi: commit `853413d` (merge PR #45),
  `docs/sesi/2026-10-03-inbox-media-notifikasi-ops-gateway.md`,
  `app/Libraries/ExcelTable.php`, `tests/unit/ExcelTableTest.php`.

## 2026-10-03 — Inbox: notifikasi lintas halaman (judul tab, favicon, suara, toast)

- Aturan lama: satu-satunya mekanisme lintas halaman adalah badge angka diam
  di sidebar (`GET /inbox/api/perlu-dibalas-count`, total tim); tidak ada yang
  menarik perhatian kasir di luar window Inbox.
- Aturan baru: endpoint baru `GET /inbox/api/notifikasi-ringkas` mengembalikan
  percakapan `perlu_dibalas` yang RELEVAN untuk user yang login (belum ada
  yang pegang, ATAU dipegang user itu sendiri; admin melihat semua, sama
  seperti `cekOwnership()`). Poller di layout utama memakainya untuk: judul
  tab `(N) ...`, titik merah di favicon, beep (bisa dibisukan lewat lonceng
  di sidebar, `localStorage`), dan toast **sticky** (tidak hilang sendiri)
  yang bisa **menumpuk** (toast baru di BAWAH toast lama, warna hijau
  WhatsApp bukan biru generik, komponen terpisah dari `showToast()`/
  `#liveToast` yang dipakai fitur lain). **Satu toast selalu satu
  percakapan** — kalau beberapa percakapan jadi "baru" dalam satu siklus
  polling yang sama, masing-masing tetap mendapat toast sendiri (bukan
  digabung satu toast banyak nama), hanya satu beep untuk siklus itu;
  diklik selalu membuka window Inbox langsung ke percakapan yang disebut
  toast itu (`?conversation_id=`) DAN langsung menutup toast itu sendiri
  (toast lain yang sedang tampil tidak ikut tertutup). Toast juga hilang
  otomatis begitu percakapan yang disebutnya sudah tidak lagi relevan
  (sudah ditangani dari jalur lain), atau lewat tombol tutup manual.
  Percakapan yang sudah pernah dilaporkan
  (per `last_message_at`) tidak memicu toast/bunyi ulang; percakapan yang
  sudah `perlu_dibalas` SEBELUM tab dibuka juga tidak memicu toast/bunyi
  pada polling pertama sesi itu.
- Alasan: kasir perlu tahu ada pesan yang perlu dibalas walau sedang di
  halaman lain (misalnya layar Kasir), bukan hanya saat membuka Inbox.
- Referensi: `docs/requirements/2026-10-03-notifikasi-inbox-lintas-halaman.md`.
  `apiPerluDibalasCount()`/badge sidebar lama tidak diubah. Kontrak Gateway
  tidak berubah.

## 2026-10-03 — Inbox: unduh gambar & dokumen yang mudah (lightbox, kartu file, unduh massal)

- Aturan lama: gambar hanya bisa disimpan lewat klik kanan (nama `media-<id>` tanpa
  ekstensi); dokumen berupa tautan yang membuka tab baru, dan kegagalan media
  (kadaluarsa/Gateway mati) tampil sebagai tab berisi JSON.
- Aturan baru: klik gambar membuka lightbox dengan tombol Unduh; gambar/sticker
  punya ikon unduh saat hover; dokumen tampil sebagai kartu (ikon, nama, jenis/ukuran,
  tombol Unduh). File tersimpan dengan nama berekstensi (`media-<id>.jpg`, atau nama
  asli pengirim). Kegagalan unduh tampil sebagai toast dengan penyebab. Tombol
  "Pilih media" mengaktifkan mode pilih untuk mengunduh banyak file sekaligus
  (berurutan, maksimum 100 per aksi, tanpa ZIP, ke folder Download browser; Brave
  dapat meminta izin unduh banyak file sekali). `?unduh=1` pada `/inbox/media/:id`
  memaksa `attachment`; hanya gambar non-SVG yang `inline`.
- Alasan: kasir perlu menyimpan foto/nota/dokumen pelanggan dengan cepat dan jelas.
- Referensi: `docs/requirements/2026-10-03-unduh-media-inbox.md`. Audio/video tetap
  placeholder; kontrak Gateway tidak berubah.

## 2026-10-02 — Inbox: riwayat thread menampilkan 200 pesan TERBARU dengan pagination

- Aturan lama: thread percakapan mengambil 500 pesan **tertua**
  (`message_timestamp ASC` + `LIMIT 500`), tanpa cara membuka pesan lain.
  Percakapan lebih dari 500 pesan berhenti menampilkan pesan baru.
- Aturan baru: `GET /inbox/api/conversations/{id}/messages` mengembalikan
  200 pesan **terbaru** (urut lama ke baru). Parameter opsional `before_id`
  mengambil 200 pesan tepat sebelum pesan itu (kursor `message_timestamp, id`),
  dan respons memuat `has_more`. Di thread Inbox, tombol "Muat pesan lama"
  di atas daftar pesan memuat 200 pesan sebelumnya; pesan yang sudah dimuat
  tidak hilang saat polling. Klik kutipan memuat riwayat lama secara bertahap
  (maksimum 5 halaman, sekitar 1000 pesan) sampai pesan asal ditemukan.
- Alasan: bug batas 500 memotong pesan terbaru; pagination menggantikan
  keputusan "tanpa pagination" setelah spike `vue-advanced-chat`
  (`docs/laporan-spike-vue-advanced-chat.md`).
- Perubahan tampilan yang menyertai (bukan aturan bisnis, dicatat agar tim
  tahu): pemisah tanggal (Hari ini / Kemarin / tanggal), kotak kutipan bisa
  diklik untuk meloncat ke pesan asal, format teks WhatsApp (`*tebal*`,
  `_miring_`, `~coret~`, kode) dan tautan `http(s)` yang bisa diklik pada isi
  pesan dan caption, centang pada pesan keluar terkirim dan tanda "!" pada
  yang gagal, serta tombol "gulung ke pesan terbaru" dengan jumlah pesan baru.
  Thread tidak lagi digambar ulang seluruhnya tiap 4 detik.
- Referensi: `docs/requirements/2026-10-02-perbaikan-thread-inbox.md` (AC-1..AC-31),
  `docs/design/2026-10-02-perbaikan-thread-inbox.md`,
  `tests/feature/InboxMessagesPaginationTest.php`, `tests/js/inbox-thread.test.js`

## 2026-10-02 — Inbox: nama conversation tidak lagi berubah saat staff balas dari WA Web/HP

- Aturan lama: endpoint webhook Gateway (`InboxGatewayApi::messages()`)
  menulis `conversations.whatsapp_name` untuk SEMUA pesan (incoming maupun
  outgoing) selama nilainya berbeda. Untuk pesan outgoing yang disinkronkan
  dari WhatsApp Web/HP (`fromMe=true`), `contact_name` di payload adalah push
  name STAFF yang membalas, sehingga nama percakapan berubah menjadi nama
  staff.
- Aturan baru: `whatsapp_name` hanya dimutakhirkan dari push name customer
  untuk pesan **incoming** (`direction='incoming'`). Pesan outgoing dari WA
  Web/HP tidak lagi menyentuh `whatsapp_name`, sehingga nama percakapan tetap
  nama customer.
- Alasan: nama percakapan harus merefleksikan identitas customer, bukan staff
  yang membalas dari luar POS (laporan bug dari tim, dokumen "Penjelasan
  masalah untuk tim 01").
- Referensi: `docs/sesi/2026-10-02-bug-nama-conversation-wa-web.md`,
  `tests/feature/InboxGatewayApiWhatsappNameTest.php`.

## 2026-10-02 — Standardisasi pemilih tanggal/rentang/periode

- Aturan lama: tiap halaman mendefinisikan locale, preset, mode terapkan, dan
  pemuatan aset date picker sendiri-sendiri; nama parameter rentang bercampur
  (`tanggal_mulai/sampai` vs `tanggal_awal/akhir`).
- Aturan baru: satu konfigurasi standar (`App\Config\DatePicker`) + helper
  `public/assets/js/date-range.js`; locale Indonesia penuh, 6 preset, mode
  "Terapkan", aset dipin (`moment@2.31.0`, `daterangepicker@3.1.0`) dimuat
  global dari layout. Parameter rentang diseragamkan ke `tanggal_awal`/
  `tanggal_akhir` (termasuk `item_harian` & `laporan_pembayaran`). Default
  rentang per konteks dipertahankan.
- Alasan: konsistensi UX dan menghapus duplikasi yang menyebabkan drift.
- Referensi: `docs/requirements/2026-10-02-standardisasi-pemilih-tanggal.md`,
  `docs/design/2026-10-02-standardisasi-pemilih-tanggal.md`,
  `docs/sesi/2026-10-02-standardisasi-pemilih-tanggal.md`.

## 2026-09-30 — Import CSV Maintenance produk: kolom kosong Aktif/Locked

- Aturan lama: kolom Aktif/Locked yang kosong di CSV dipaksa menjadi 0 saat
  baris sudah ada di database (mengoverwrite nilai locked yang sedang
  berlaku).
- Aturan baru: untuk baris yang sudah ada, kolom Aktif/Locked yang kosong
  mempertahankan nilai di database. INSERT baris baru tetap default
  aktif=1, locked=0. Aksi NONAKTIF eksplisit tetap memaksa aktif=0.
- Alasan: import CSV yang tidak mengisi kolom Locked secara tidak sengaja
  membuka kunci semua produk yang diproses (lihat docs/sesi/2026-09-30-modul-produk.md).
- Referensi: commit `fc099ea` (fix(produk): keep Aktif/Locked when their CSV
  columns are blank).

## 2026-09-30 — Inbox: status gateway `degraded` (sesi WA terhubung tapi rusak)

- Aturan lama: gateway WhatsApp hanya punya status terhubung/terputus; sesi
  yang rusak (socket `connected` tapi gagal mendekripsi semua pesan) tidak
  terdeteksi sehingga pesan hilang tanpa peringatan.
- Aturan baru: status gateway dapat bernilai `degraded` saat terhubung tetapi
  sesi bermasalah. Inbox POS menampilkan badge merah dan memblokir kirim
  pesan sampai sesi dipulihkan (scan QR ulang).
- Alasan: mencegah kejadian 2026-09-29/30 terulang tanpa terdeteksi.
- Referensi: commit `f26138b`, `6afb732`;
  docs/sesi/2026-09-30-wa-gateway-sesi-degraded.md.

