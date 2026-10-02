# Rencana Spike: `vue-advanced-chat` untuk Thread Pesan Inbox

- **Tanggal**: 2026-10-02
- **Jenis**: rencana spike (prototipe sekali pakai, bukan kode produksi)
- **Status**: siap dikerjakan
- **Konteks**: `docs/riset-library-ui-chat-inbox.md` (riset kandidat), `docs/requirements/2026-10-02-perbaikan-thread-inbox.md` (poin P2 sampai P6 bergantung pada hasil spike ini)
- **Keputusan user (2026-10-02)**: spike dikerjakan **sebelum** P2 sampai P6. P1 (ambil 500 pesan terbaru) tidak menunggu dan berdiri sendiri.

## 1. Tujuan

Menjawab satu pertanyaan dengan angka: **apakah `vue-advanced-chat` v2.1.2 layak menggantikan panel thread pesan Inbox AuliaPos**, atau biaya adapter dan slot-nya mendekati biaya menambal UI yang ada.

Hasilnya satu rekomendasi: **GO**, **GO-BERSYARAT**, atau **NO-GO**, berdasarkan kriteria di §5.

## 2. Batasan (wajib)

- Spike HANYA ditaruh di folder baru `spike/vue-advanced-chat/`. **Jangan mengubah** `app/`, `public/`, `tests/`, `docs/` yang sudah ada, atau berkas konfigurasi proyek. Satu-satunya berkas baru di luar folder spike adalah laporan hasil (§7).
- Tanpa dependency baru di proyek: jangan membuat `package.json` atau `composer` baru di akar repo. Playwright dan alat bantu lain dipasang di luar repo (mis. direktori sementara sesi) atau di dalam folder spike dengan `.gitignore` untuk `node_modules`.
- Library dimuat dari CDN sebagai berkas UMD, **tanpa build step**: `https://cdn.jsdelivr.net/npm/vue-advanced-chat@2.1.2/dist/vue-advanced-chat.umd.js` (README resmi: `window['vue-advanced-chat'].register()`). Jangan memakai `@advanced-chat/components` v3 (masih release candidate).
- Data **tiruan** (fixture JSON); sesi ini tidak punya akses ke MariaDB atau data produksi. Jangan mencoba menjalankan aplikasi CI4 penuh.
- Hanya panel thread pesan (mode `single-room`). Daftar percakapan, header, handoff, snooze, SLA, dan composer kasir **di luar cakupan**.
- Pengerjaan hanya di branch `claude/pensive-feynman-lfajpe` repo `AuliaPos`. Jangan membuat pull request. Commit dan push ke branch itu.
- Ikuti aturan `AGENTS.md` (YAGNI, jangan menambah fitur di luar kriteria). Dokumentasi dan penjelasan dalam Bahasa Indonesia; kode dan nama dalam Bahasa Inggris.
- Hentikan lebih awal bila K2 (biaya slot) jelas gagal di tengah jalan (§5): tulis laporan NO-GO dengan data yang sudah ada, tidak perlu menuntaskan sisanya.

## 3. Yang dibaca lebih dulu

1. `docs/riset-library-ui-chat-inbox.md` (§4 dan §5: kandidat, pemetaan data, fitur).
2. `docs/uji-inbox-tipe-pesan-nyata.md` (daftar tipe pesan).
3. Fungsi render yang ada di `app/Views/inbox/index.php`, sebagai **acuan perilaku** yang harus dicocokkan:

| Fungsi | Baris | Peran |
|---|---|---|
| `renderPesan` | 2910-2963 | rakit bubble per pesan |
| `renderIsiPesan` | 2760-2908 | isi per tipe (image, sticker, document, audio/video, location, contact, unsupported, teks) |
| `renderKotakKutipan` | 2167-2243 | kutipan, 5 cabang media |
| `renderLabelDiteruskan` | 2369-2379 | label "Diteruskan" |
| `renderAksiPesan`, `renderAksiBalas`, `renderAksiTeruskan`, `aksiPesanTersedia` | 2244-2285, 2357-2368 | tombol Balas dan Teruskan, nonaktif untuk audio/video/lokasi/kontak |
| `htmlMediaTidakTersedia`, `tanganiMediaGagal`, dll. | 2602-2742 | placeholder media gagal/kadaluarsa/Gateway terputus |
| `tampilkanBubbleOutgoing` | 3127-3149 | bubble langsung setelah kirim |

Nomor baris dari `v2.4` commit `26a7847`; verifikasi ulang.

4. Bentuk data pesan dari API: `app/Controllers/Inbox.php` `apiMessages()` (±baris 383) dan `app/Models/MessageModel.php` (`$allowedFields`). Kolom penting: `id`, `wa_message_id`, `direction`, `message_type`, `text`, `sender_jid`, `sender_name`, `message_timestamp`, `send_status`, `is_internal`, `is_forwarded`, `media_filename`, `media_local_filename`, `extra_json`, dan kolom kutipan `quoted_wa_message_id`, `quoted_sender_label`, `quoted_snippet`, `quoted_media_available`, `quoted_source_message_id`, `quoted_media_type`.

## 4. Pekerjaan

**Langkah 1: fixture.** Buat `fixtures.json` berisi satu percakapan tiruan dengan bentuk persis respons API (field di atas), mencakup semua kasus:

| Kasus | Keterangan |
|---|---|
| text masuk dan keluar | keluar punya `sender_name` kasir |
| image dengan dan tanpa caption | `url` media bisa gambar placeholder lokal |
| sticker | |
| document + caption | |
| audio dan video | placeholder "cek WhatsApp Web" |
| location (biasa dan live), contact | lewat `extra_json` |
| `unsupported` | beberapa penanda (lihat-sekali, polling, dll.) |
| catatan internal | `is_internal = true` |
| diteruskan | `is_forwarded = true` |
| kutipan: 5 cabang media | `quoted_media_available` 0/1, `quoted_media_type` image/sticker/document/audio/video/null, id sumber ada/tidak |
| kutipan tidak ditemukan | `quoted_sender_label` kosong |
| `send_status = failed` | |
| teks berbahaya | `<script>alert(1)</script>`, `<img src=x onerror=alert(1)>` |
| teks berformat | `*tebal*`, `_miring_`, `~coret~`, ```` ```kode``` ```` |
| lintas hari | pesan hari ini, kemarin, minggu lalu |

Buat juga versi besar: **500 pesan** (campuran) untuk uji performa.

**Langkah 2: adapter.** `adapter.js` memetakan satu baris pesan API ke model pesan library (`_id`, `senderId`, `content`, `username`, `date`, `timestamp`, `saved`/`failure`, `files`, `replyMessage`, `system`, dll.) sesuai pemetaan di riset §5.3.

**Langkah 3: halaman spike.** `index.html` memuat Bootstrap dan Font Awesome dari CDN yang sama dengan `app/Views/layout/main.php`, memuat library, lalu menampilkan `<vue-advanced-chat>` dengan `single-room`. Tipe yang tidak muat di model bawaan dirender lewat slot `message_{id}`; **hitung baris kode slot**. Aksi Balas dan Teruskan lewat `message-actions` kustom.

**Langkah 4: simulasi polling.** Skrip yang setiap 4 detik "mengambil ulang" data dan menetapkan ulang array `messages` (aturan library: tugaskan array baru, jangan `push`). Termasuk skenario data tidak berubah, ada pesan baru, dan satu pesan berubah (`send_status`).

**Langkah 5: uji otomatis dengan Playwright** (Chromium sudah terpasang, `PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers`; **jangan** `playwright install`). Jalankan halaman lewat server statis lokal (mis. `python3 -m http.server`). Ambil screenshot setiap kasus.

## 5. Kriteria dan aturan keputusan

Isi tiap kriteria dengan **angka atau ya/tidak beserta bukti** (screenshot, log, jumlah baris). Ambang di bawah adalah **usulan** dan boleh disesuaikan tim.

| # | Kriteria | Cara ukur | Lulus bila |
|---|---|---|---|
| K1 | Semua tipe pesan di fixture tampil benar | Screenshot per kasus; catat mana yang native dan mana yang lewat slot | Semua tampil sama dengan perilaku `renderIsiPesan`/`renderKotakKutipan`. **Wajib**: kutipan media 5 cabang, label "Diteruskan", catatan internal terlihat beda, kartu lokasi/kontak |
| K2 | **Biaya slot dan adapter** | Hitung baris kode (tanpa komentar dan baris kosong) di `adapter.js` + semua slot | **Tidak lebih dari 190 baris**, yaitu setengah dari ±379 baris kode yang digantikan (daftar fungsi di §3, dihitung 2026-10-02). Lebih dari itu berarti library tidak menghemat |
| K3 | Tidak berkedip saat polling | `MutationObserver` pada `<img>` selama 60 detik polling data tak berubah | **0** elemen gambar yang dibuat ulang; posisi scroll tidak berubah bila kasir menggulung ke atas |
| K4 | Perilaku auto-scroll | Pesan baru saat di dasar vs saat menggulung ke atas | Menggulung ke bawah hanya bila dekat dasar (aturan sekarang ±80px), tidak menyeret kasir yang membaca ke atas |
| K5 | Performa 500 pesan | Waktu tampil pertama dan waktu pembaruan polling (`performance.now`) | Catat angkanya; usulan lulus bila tampil pertama di bawah 1 detik dan pembaruan polling di bawah 200 ms di Chromium headless |
| K6 | Aksi Balas dan Teruskan | Aksi per pesan; Teruskan nonaktif untuk audio/video/lokasi/kontak dan tidak ada untuk catatan internal | Bisa dibatasi per pesan (lewat `disableActions`, slot, atau pengecekan di handler); catat caranya |
| K7 | **Keamanan XSS** | Fixture teks berbahaya | Tidak ada skrip yang berjalan, `alert` tidak muncul; teks tampil sebagai teks. **Wajib lulus** |
| K8 | Fitur bawaan yang menggantikan pekerjaan P4 dan P6 | Pemisah tanggal, format `*`, `_`, `~`, kode | Catat yang berfungsi sesuai aturan WhatsApp dan yang tidak |
| K9 | Kutipan | Field `replyMessage` bisa memuat 5 cabang? Kutipan bisa diklik untuk meloncat? | Catat bagian yang hilang; klik-loncat tersedia atau tidak |
| K10 | Ketergantungan jaringan dan ukuran | Daftar semua permintaan jaringan halaman (Playwright), ukuran skrip terkompresi | Catat. Khusus: apa yang terjadi saat data emoji dari `cdn.jsdelivr.net` tidak terjangkau |
| K11 | Kecocokan visual | Screenshot dibandingkan dengan gaya WhatsApp Inbox sekarang | Penilaian; tidak menentukan keputusan sendirian |

**Aturan keputusan:**
- **GO**: K1, K3, K4, K6, K7 lulus dan K2 di bawah ambang.
- **GO-BERSYARAT**: semua wajib lulus tetapi K2 di antara 190 dan 285 baris (sampai 75% dari baris yang digantikan), atau ada satu kriteria non-wajib (K5, K8, K9, K10) yang gagal. Tulis syarat yang harus dipenuhi.
- **NO-GO**: K7 gagal, **atau** K2 di atas 285 baris, **atau** salah satu kasus wajib K1 tidak bisa dirender tanpa menulis ulang logikanya dari nol.

## 6. Yang spike ini TIDAK bisa membuktikan

Tulis di laporan sebagai batasan, jangan disimpulkan sebaliknya:
- Integrasi dengan `/inbox/test` asli (login, CSRF, endpoint, media dari `/inbox/media/{id}`).
- Gambar dan sticker produksi sungguhan, percakapan nyata yang panjang.
- Kecepatan di PC kasir dan perilaku di server tanpa internet.
- Keuntungan library yang bergantung data dari gateway (centang delivered/read, reaction, pemutar audio): datanya memang tidak dikirim gateway sekarang.

## 7. Keluaran

1. Folder `spike/vue-advanced-chat/`: `index.html`, `adapter.js`, `fixtures.json` (dan versi 500 pesan), skrip Playwright, folder `screenshots/`, serta README satu paragraf cara menjalankan.
2. Laporan `docs/laporan-spike-vue-advanced-chat.md` (Bahasa Indonesia): tabel K1 sampai K11 dengan hasil dan bukti, jumlah baris K2 yang sebenarnya, rekomendasi GO / GO-BERSYARAT / NO-GO beserta alasannya, §6 sebagai batasan, dan langkah lanjut untuk tim (mis. bagian integrasi yang harus diuji di mesin pengembang dengan data nyata).
3. Commit dan push ke `claude/pensive-feynman-lfajpe`. Jangan membuat pull request.
4. Pesan akhir ke user: rekomendasi dalam satu kalimat, tiga temuan terpenting, dan jalur berkas laporan.

## 8. Batas waktu

Satu sesi kerja. Bila K2 sudah jelas gagal di langkah 3, berhenti dan tulis laporan NO-GO (§2).
