# Laporan Spike: `vue-advanced-chat` untuk Thread Pesan Inbox

- **Tanggal**: 2026-10-02
- **Jenis**: laporan hasil spike (prototipe sekali pakai; tidak ada perubahan pada `app/`, `public/`, `tests/`)
- **Rencana yang dijalankan**: `docs/rencana-spike-vue-advanced-chat.md`
- **Basis**: branch `claude/pensive-feynman-lfajpe`, kode Inbox `v2.4` (commit `26a7847`); nomor baris di §3 rencana diverifikasi ulang dan cocok
- **Kode spike**: folder `spike/vue-advanced-chat/` (adapter, slot, fixture, skrip Playwright, `screenshots/`, `results.json`) sudah **dihapus dari repo** karena sekali pakai. Isinya utuh di riwayat git pada commit `deeedb1`; semua rujukan `screenshots/...`, `results.json`, dan nama berkas di laporan ini mengacu ke folder itu. Contoh membuka: `git show deeedb1:spike/vue-advanced-chat/results.json`, atau `git checkout deeedb1 -- spike/vue-advanced-chat` di salinan kerja sementara.
- **Library**: `vue-advanced-chat@2.1.2` UMD dari CDN jsDelivr, mode `single-room`, tanpa build step

## 1. Rekomendasi

**Hasil menurut aturan keputusan §5 rencana: GO-BERSYARAT.** Semua kriteria wajib (K1, K3, K4, K6, K7) lulus, tetapi K2 jatuh di pita 190-285 baris (248 baris) dan empat kriteria non-wajib (K5, K8, K9, K10) gagal atau hanya sebagian lulus.

**Catatan tegas untuk pembaca keputusan:** syarat yang paling berat (K5) bertentangan dengan keputusan yang sudah diambil ("tanpa pagination, batas 500 pesan dipertahankan", `docs/requirements/2026-10-02-perbaikan-thread-inbox.md` §5). Dengan 500 pesan, library memblokir halaman sekitar **2,3-3,6 detik di setiap pembaruan** (render saat ini: 130 ms). Bila tim tetap pada keputusan "500 pesan tanpa pagination", hasil spike ini harus dibaca sebagai **NO-GO**. Saya menilai GO-BERSYARAT hanya layak diteruskan jika tim menerima jendela pesan kecil (sekitar 100 pesan) beserta pagination.

Tiga alasan lain yang membuat keuntungan library tipis:
1. **30 dari 39 kasus (77%) tetap dirender lewat slot kita sendiri**; library hanya merender bubble teks biasa dan dokumen.
2. **Biaya kode tidak lebih hemat bila dihitung utuh**: 326 baris (adapter + slot + state media + glue) berbanding 331 baris yang digantikan (aturan hitung yang sama).
3. **Format teks bawaan salah untuk WhatsApp**: `*tebal*` tampil miring.

## 2. Ringkasan K1-K11

| # | Kriteria | Hasil | Status |
|---|---|---|---|
| K1 | Semua tipe pesan tampil benar | 39/39 kasus tampil; 9 native, 30 lewat slot; HTML 30 kasus slot **identik** dengan keluaran fungsi `v2.4` | **Lulus** |
| K2 | Biaya adapter + slot (ambang usulan ≤ 190) | **248 baris** (152 bila `media.js` tidak dihitung; 326 dengan glue) | **Pita bersyarat** (190-285) |
| K3 | Tidak berkedip saat polling | 0 `<img>` dibuat ulang dalam 60 detik (15 polling); scroll tidak berubah | **Lulus** |
| K4 | Auto-scroll | Dasar: ikut turun. Baca ke atas: tidak diseret. Ambang library ±60 px (baseline ±80 px) | **Lulus** (dengan catatan) |
| K5 | Performa 500 pesan | Tampil pertama 1,7 detik; pembaruan polling 2,3-3,6 detik; baseline 130 ms | **Gagal** |
| K6 | Aksi Balas/Teruskan | Bisa dibatasi per pesan (`disableActions`, tombol di slot); dua mekanisme berbeda | **Lulus** |
| K7 | Keamanan XSS | 0 skrip jalan, 0 `alert`, 0 elemen injeksi pada 9 vektor | **Lulus** |
| K8 | Pemisah tanggal dan format teks | Tanggal: berfungsi. Format: `*` salah (miring), blok kode bukan blok, ada salah-format `2*3*4` | **Sebagian** |
| K9 | Kutipan | `replyMessage` tidak memuat cabang fallback; klik-loncat **tidak ada** bawaan | **Sebagian** |
| K10 | Jaringan dan ukuran | 516 KB mentah (161 KB gzip); data emoji diminta saat muat walau footer mati | Dicatat (lihat §3) |
| K11 | Kecocokan visual | Mirip; ada beberapa beda (lihat §3) | Penilaian |

## 3. Hasil rinci per kriteria

### K1. Semua tipe pesan tampil benar: lulus

Fixture `fixtures.json` memuat 39 pesan dengan bentuk respons `apiMessages()` (kolom sesuai rencana §3 butir 4). Bukti: satu screenshot per kasus di `screenshots/k1/` (39 berkas), dan dua tangkapan penuh `screenshots/k11-library-full.png` (library) serta `screenshots/k11-baseline-full.png` (fungsi `v2.4` asli).

**Uji pembeda terhadap kode asli.** `build-baseline.js` memotong fungsi render asli dari `app/Views/inbox/index.php` (`renderPesan`, `renderIsiPesan`, `renderKotakKutipan`, `renderAksi*`, `renderLabelDiteruskan`, state media) lalu menjalankannya pada fixture yang sama. Untuk setiap pesan yang dirender lewat slot, HTML bubble dibandingkan dengan HTML versi asli (tanpa tombol aksi dan jam). Hasil: **30 dari 30 identik**, dan teks tombol aksi (termasuk status `disabled`) identik. Uji ini sempat menangkap dua kesalahan port saya (gaya `font-style:normal` pada placeholder audio/video/kontak/`unsupported`, dan label tombol Teruskan nonaktif) yang lalu diperbaiki.

| Mode | Kasus (id fixture) |
|---|---|
| **Native** (9) | teks masuk 1, teks keluar kasir 2, dokumen+caption 6, kirim gagal 30, empat teks XSS/format/URL 31, 32, 38, 39, dokumen XSS 34 |
| **Slot** (30) | image 3, 4, 29, 33; sticker 5; audio 7; video 8; lokasi 9, 10, 36; kontak 11, 37; `unsupported` 12-16; catatan internal 17; diteruskan 18; semua kutipan 19-28 dan 35 |

Pemeriksaan wajib:
- **Kutipan 5 cabang** (diperiksa lewat DOM, `results.json` `k1.branches`): (a) media tidak tersedia, (b) gambar dan sticker dengan `<img>` hidup, (c) dokumen sebagai tautan, (d) audio/video sebagai label, (e) snapshot bila id sumber/tipe kosong. Ditambah "Pesan tidak ditemukan" dan gambar kutipan 404 yang jatuh ke placeholder. Semua `true`.
- **Label "Diteruskan"** tampil. **Catatan internal** berlatar `rgb(255,243,205)` dengan garis putus-putus dan label "Internal", berbeda dari bubble keluar `rgb(217,253,211)`.
- **Kartu lokasi** (tautan `https://www.google.com/maps?q=-7.2575%2C%20112.7521`, "(langsung)" untuk live) dan **kartu kontak** (nomor `+6281234567890` dari vCard) benar.
- Media 404 (`id` 29 dan 33): jatuh ke "Gambar tidak tersedia (kemungkinan sudah kadaluarsa)".

Catatan: kasus yang lewat slot adalah **logika lama yang disalin**, bukan logika baru. Itu sebabnya hasilnya identik.

### K2. Biaya slot dan adapter: pita bersyarat

Penghitung `count-loc.js` (tanpa baris kosong dan baris komentar) diterapkan **sama** pada kode spike dan pada baseline.

| Berkas spike | Baris kode |
|---|---|
| `adapter.js` (pemetaan baris API ke model library, `needsSlot`, tanggal) | 54 |
| `slots.js` (isi slot `message_{id}`: bubble, kutipan, per tipe, tombol aksi) | 98 |
| `media.js` (state machine media gagal, disalin hampir apa adanya dari `v2.4` baris 2602-2742 dan 990-1000) | 96 |
| `main.js` (glue: mount, properti library, polling, sinkron slot, aksi) | 78 |

| Cara hitung | Baris | Terhadap ambang 190 / 285 |
|---|---|---|
| Harfiah rencana (`adapter.js` + `slots.js`) | 152 | di bawah 190 |
| **Dipakai untuk keputusan**: ditambah `media.js`, karena baseline yang digantikan (2602-2742) juga menghitung blok itu | **248** | pita 190-285 |
| Ditambah glue produksi (`main.js`) | 326 | di atas 285 |

Baseline yang digantikan, dengan aturan hitung yang sama, **331 baris** (bukan ±379 di rencana; selisih karena rencana tampaknya masih menghitung sebagian baris komentar). Rinciannya: `renderPesan` 38, `renderIsiPesan` 108, `renderKotakKutipan` 44, `renderLabelDiteruskan` 5, aksi 23, `aksiPesanTersedia` 5, state media 90, `tampilkanBubbleOutgoing` 18.

Tidak diporting, jadi angka di atas sedikit **terlalu rendah**: penanda "Terkirim tanpa kutipan" (`pesanTerkirimTanpaKutipan`), `tampilkanBubbleOutgoing`, dan pemanggilan `bersihkanSementaraSetelahReconnect`. Perkiraan tambahan 10-20 baris (tidak diukur). Sebaliknya, sebagian isi `main.js` hanya untuk spike (parameter query string, hook uji), jadi angka 326 juga kasar.

Karena `main.js` tidak punya padanan di baseline (skrol dan polling ada di bagian lain `index.php`), perbandingan paling adil ada di antara 248 dan 326. Keduanya tidak menunjukkan penghematan berarti terhadap 331.

### K3. Tidak berkedip saat polling: lulus

Dua varian, masing-masing 60 detik dengan polling 4 detik (15 siklus), data tidak berubah, pembaca digulung 600 px ke atas. `MutationObserver` memantau DOM biasa (slot) dan shadow DOM library. Node gambar diberi tanda sebelum polling dan dicek kembali sesudahnya.

| Varian | Polling | `<img>` ditambah / dibuang | Node bertanda yang sama | Rekaman mutasi shadow DOM | `scrollTop` (awal / min / maks) |
|---|---|---|---|---|---|
| `chat.messages` di-assign ulang tiap polling | 15 | **0 / 0** | 5 dari 5 | 30 (±2 per polling) | 4639 / 4639 / 4639 |
| Dilewati bila JSON sama | 0 | 0 / 0 | 5 dari 5 | 0 | 4639 / 4639 / 4639 |

Skenario data berubah (satu pesan `sent` menjadi `failed`, satu pesan baru): 5 dari 5 `<img>` tetap node yang sama, pesan baru tampil, ikon gagal muncul pada pesan yang berubah (`screenshots/k3-data-berubah.png`).

Syarat agar lulus: isi slot kita **harus diberi tanda tangan** yang memuat state sisi klien (`mediaGagal`, `mediaSementara`, `gatewayTerhubung`), sama dengan risiko 1 di dokumen requirements §7. Tanpa itu `<img>` di slot ikut dibuat ulang. Ini kode tambahan (sinkron slot ±20 baris di `main.js`), bukan fitur gratis dari library. Bagian yang dirender library sendiri tidak membuat ulang gambar.

### K4. Perilaku auto-scroll: lulus, ambang berbeda

Polling dipicu manual dengan satu pesan baru tiap skenario (jarak dari dasar dalam px):

| Posisi sebelum pesan baru | Jarak sesudah | Arti |
|---|---|---|
| Di dasar (0) | 0 | ikut turun |
| 600 | 648 | **tidak diseret** (jarak bertambah setinggi pesan baru) |
| 50 | 0 | ikut turun |
| 60 | 0 | ikut turun |
| 70, 80, 90, 100, 110, 120 | lebih dari 0 (jarak tetap atau bertambah) | tidak ikut turun |

Aturan sekarang ±80 px; library memakai ambang sekitar 60-70 px. Selisihnya kecil, tetapi bukan identik. Tambahan bawaan: tombol "gulung ke pesan terbaru" dengan penghitung pesan baru (`screenshots/k4-setelah-skenario.png`).

### K5. Performa 500 pesan: gagal

Chromium headless di sandbox 4 inti (patokan CPU: loop 5×10⁷ iterasi 90 ms, jadi bukan mesin yang lambat). Fixture 500 pesan (`fixtures-500.json`), 386 di antaranya lewat slot. Library merender **semua** 500 pesan sekaligus (500 `.vac-message-wrapper`, 3.730 node di shadow DOM); tidak ada virtualisasi.

| Pengukuran | CPU 1× | CPU diperlambat 4× (CDP) |
|---|---|---|
| Tampil pertama (waktu `apply` memblokir halaman) | 1.676 ms | 11.419 ms |
| Polling data tidak berubah (assign ulang) | median 2.617 ms | median 11.355 ms |
| Polling dengan satu pesan baru | median 2.314 ms | median 20.616 ms |
| Polling dengan satu pesan berubah | median 3.577 ms | median 8.430 ms |
| **Baseline**: `renderPesan` (innerHTML) 500 pesan yang sama | **median 130 ms** | tidak diukur |

Ambang usulan (tampil < 1 detik, polling < 200 ms) tidak tercapai, di mesin biasa maupun yang diperlambat. Angka 4× memakai satu ulangan, jadi hanya indikatif.

**Penyebabnya ada di library, bukan di slot kita.** Uji `k5b` memberi 500 pesan teks biasa tanpa slot dan tanpa gambar. Waktu `chat.messages = ...`:

| Jumlah pesan | Assign pertama | Assign ulang (isi sama) |
|---|---|---|
| 50 | 108 ms | 33 ms |
| 100 | 156 ms | 125 ms |
| 250 | 789 ms | 787 ms |
| 500 | 2.502 ms | 2.270 ms |

Pertumbuhannya lebih cepat dari linear (naik 5× dari 100 ke 500 pesan tetapi waktunya naik 16×). Dengan jendela 50-100 pesan dan pagination (`fetch-messages`), biayanya wajar; dengan 500 pesan tidak. Melewatkan polling bila JSON sama (varian K3) membantu untuk polling yang tidak berubah, tetapi tidak untuk pesan baru atau pesan yang berubah.

Catatan metode: angka "tampil pertama" memakai `applySyncMs` (waktu thread utama terblokir). Kolom `renderMs` di `results.json` tidak andal dan tidak dipakai.

### K6. Aksi Balas dan Teruskan: lulus (dua mekanisme)

- **Pesan native** (teks, dokumen): `message-actions` kustom `[{name:'balas'},{name:'teruskan'}]` (bukan nama bawaan `replyMessage`, supaya tidak memicu UI balas milik library) dan event `message-action-handler`. Klik "Balas" dan "Teruskan" pada pesan 2 tercatat sebagai `balas`/`teruskan` dengan id `2`.
- **Pembatasan per pesan**: `disableActions: true` per pesan menghilangkan dropdown sepenuhnya. Dipakai dengan predikat yang sama dengan `aksiPesanTersedia()` untuk catatan internal dan pesan keluar yang belum terkirim. Terbukti pada pesan gagal kirim (id 30): tidak ada tombol aksi, sementara teks masuk (id 1) punya.
- **Pembatasan per tipe** (Teruskan nonaktif untuk audio/video/lokasi/kontak) **tidak bisa dinyatakan lewat daftar aksi global**. Dalam praktik semua tipe itu sudah lewat slot, jadi tombolnya digambar sendiri dalam bubble (`slots.js`, `actions()`), dengan teks, atribut `disabled`, dan `title` yang sama dengan versi asli (terverifikasi identik pada uji K1). Pesan internal tidak punya tombol (`[]`). Klik tombol di slot (`data-act`) pada pesan 21 tercatat benar.
- Konsekuensi: **dua tampilan aksi berbeda** dalam satu thread (dropdown di pesan native, tombol di dalam bubble di pesan slot).

### K7. Keamanan XSS: lulus (wajib)

Sembilan vektor di fixture: `<script>alert(1)</script>`, `<img src=x onerror=alert(1)>`, `<svg onload=...>` di caption dokumen, `<b onmouseover=...>` di `sender_name`, tag HTML di nama berkas dokumen, di cuplikan dan label kutipan, di nama dan alamat lokasi, di nama kontak. Hasil dari `results.json` `k1.k7`:

- `alert`/dialog terbuka: **0**
- elemen `img[src="x"]`, `svg[onload]`, `b[onmouseover]`, `img[onerror*=alert]`, `script` inline, `<i>` injeksi: **0** di DOM biasa maupun shadow DOM
- teks tampil sebagai teks literal (`&lt;script&gt;...` pada bubble native dan slot, contoh `screenshots/k1/31-xss-script.png`)

Pesan native di-escape oleh library; pesan slot di-escape oleh `esc()` di adapter (fungsi yang sama dengan `escapeHtmlInbox`). Artinya keamanan slot **bergantung pada disiplin kode kita**, bukan pada library.

### K8. Fitur bawaan untuk P4 dan P6: sebagian

- **Pemisah tanggal: berfungsi.** Label dibuat adapter ("Kemarin", "Hari ini", tanggal panjang). Hasil: "Kemarin" dan "Hari ini" sebagai pemisah, dan pesan pertama menjadi banner "Percakapan dimulai pada: 25 September 2026" (bukan pemisah biasa; AC-14 minta pesan pertama didahului pemisah, jadi ini beda tampilan). CSS library membuatnya huruf kapital.
- **Format teks: tidak sesuai aturan WhatsApp.** Masukan `Format: *tebal* _miring_ ~coret~ `+"`kode sebaris`"+` dan `+"```blok\nkode```"+``:

| Penanda | Hasil library | Yang diharapkan |
|---|---|---|
| `*tebal*` | `<em>` (**miring**) | tebal |
| `_miring_` | `<em>` | miring (benar) |
| `~coret~` | `<del>` | coret (benar) |
| `` `kode` `` | `<code>` | monospace sebaris (benar) |
| ```` ```blok``` ```` | `<code>` sebaris | monospace **blok** (salah) |
| `2*3*4` | `2<em>3</em>4` | tidak berubah (salah) |
| `snake_case_name`, `* bukan *` | tidak berubah | tidak berubah (benar) |

  Menetapkan ulang `text-formatting` secara eksplisit (`bold:'*'`, dst.) tidak mengubah hasil, jadi bug ini tidak bisa diperbaiki lewat properti yang saya coba. Mematikan formatting library lalu memakai parser sendiri **belum diuji**.
- **Bonus yang tidak diminta**: URL otomatis menjadi tautan, tanda centang satu (`saved`), ikon gagal merah "!" (`screenshots/k1/39-teks-keluar-url.png`, `30-send-status-failed.png`).

### K9. Kutipan: sebagian

`replyMessage` (`screenshots/k9-replyMessage-native.png`) memuat teks dan `files` (gambar tampil). Yang **tidak punya tempat** di model itu (`content`, `senderId`, `files`): label pengirim bebas (label "Pelanggan" di tangkapan layar berasal dari daftar user ruang), judul "Pesan tidak ditemukan", dan fallback saat gambar gagal dimuat (`onerror` ke "[Media tidak tersedia]"). Hasil cabang (a)-(e) bisa dihitung adapter dan dimasukkan ke `content`/`files`, tetapi kegagalan saat runtime tidak tertangani. Karena itu semua kutipan di spike tetap lewat slot.

**Klik-loncat tidak tersedia bawaan**: klik pada kotak kutipan tidak menggulung dan tidak memicu satu pun event (`go-to-reply`, `open-file`, `message-action-handler`; `scrollTop` 5269 sebelum dan sesudah). Elemen tujuan tersedia di shadow DOM dengan `id` pesan (`#19` ditemukan), jadi loncatan buatan sendiri mungkin dibuat, tetapi **tidak dibuat dan tidak dihitung** di K2.

### K10. Ketergantungan jaringan dan ukuran

- **Skrip library**: 516.220 byte mentah, 160.927 byte gzip, 135.541 byte brotli (dihitung lokal dari berkas yang sama, bukan transfer jsDelivr).
- **Permintaan jaringan halaman** (Playwright): Bootstrap CSS (`cdn.jsdelivr.net`), Font Awesome (`cdnjs.cloudflare.com`), `vue-advanced-chat.umd.js` (`cdn.jsdelivr.net`), data emoji `emoji-picker-element-data@^1/en/emojibase/data.json` (`cdn.jsdelivr.net`), serta berkas lokal (`index.html`, `spike.css`, JS spike, `fixtures.json`, `media/*`).
- **Temuan: data emoji diminta saat halaman dimuat walau footer dan emoji dimatikan** (`footerMati.https` memuat permintaan itu, jadi ini bukan akibat footer).
- **Bila data emoji tidak terjangkau** (host diblokir, footer nyala): permintaan gagal (`net::ERR_FAILED`), panel pesan tetap tampil penuh (39 pesan), tidak ada galat yang terlihat di panel (`screenshots/k10-emoji-diblokir.png`). Pemilih emoji tidak ada di DOM. Klik ikon emoji tidak saya uji sampai tuntas (kehabisan waktu tunggu 3 detik), jadi perilaku pemilih saat offline **tidak terbukti**.
- **Bila skrip library tidak terjangkau**: `Cannot read properties of undefined (reading 'register')`, **0 pesan tampil, panel kosong** (`screenshots/k10-library-cdn-diblokir.png`). Untuk server tanpa internet library wajib di-vendor ke `public/assets/`; data emoji juga perlu sumber lokal bila composer library dipakai.

Pencatatan lingkungan: Chromium di sandbox tidak memercayai CA proxy sesi. Berkas CDN diunduh dengan `curl` (TLS terverifikasi) dari URL yang sama, lalu disajikan lewat `page.route`. Verifikasi TLS tidak dimatikan.

### K11. Kecocokan visual: penilaian

Bandingkan `screenshots/k11-library-full.png` dengan `screenshots/k11-baseline-full.png`. Warna bubble dan latar sudah dipadankan lewat properti `styles`; kutipan, label, kartu, dan placeholder sama persis karena memakai CSS dan HTML yang sama. Beda yang terlihat:
- Bubble slot menyempit mengikuti isi, versi asli memenuhi hingga 70% lebar.
- Jam hanya `HH.mm` (karena ada pemisah tanggal); versi asli `dd/mm, HH.mm`.
- Pemisah tanggal berlatar biru muda huruf kapital bergaya library.
- Dokumen native tampil sebagai kartu unduh dengan nama terpotong, bukan tautan seperti `renderIsiPesan`.
- Bubble native memakai gaya library (sudut, bayangan) yang sedikit berbeda dari bubble slot di thread yang sama.
- Tombol aksi di bubble slot hanya muncul saat kursor di atas bubble (sama dengan versi asli).

Penilaian ini tidak menentukan keputusan sendirian.

## 4. Penerapan aturan keputusan

| Syarat | Hasil |
|---|---|
| K1 lulus, kasus wajib terpenuhi | Ya |
| K3 lulus | Ya |
| K4 lulus | Ya (ambang 60 px, bukan 80 px) |
| K6 lulus | Ya |
| K7 lulus | Ya |
| K2 di bawah 190 | **Tidak**: 248 (pita 190-285) |
| Kriteria non-wajib gagal | K5 (gagal), K8 (sebagian), K9 (sebagian), K10 (catatan) |
| Syarat NO-GO (K7 gagal / K2 > 285 / kasus wajib K1 harus ditulis ulang dari nol) | Tidak terpenuhi menurut huruf aturan. K2 baru lewat 285 bila glue ikut dihitung (326) |

Hasil: **GO-BERSYARAT**, dengan catatan di §1.

**Syarat yang harus dipenuhi** bila tim melanjutkan:
1. **K5**: jendela pesan dibatasi (usulan ≤ 100 pesan) dan pagination `fetch-messages` + endpoint `before_id` dibuat. Ini membatalkan keputusan "tanpa pagination" dan memperluas cakupan P1.
2. **K8**: format `*tebal*` harus ditangani (mis. matikan formatting library dan tulis parser P6 sendiri, lalu uji). Bukan penghematan terhadap P6.
3. **K10**: library dan data emoji di-vendor ke `public/assets/`.
4. **K2**: tim menerima bahwa 248-326 baris adapter, slot, dan glue menggantikan 331 baris yang ada. Tidak ada penghematan kode; manfaatnya hanya pada hal-hal di §5.

## 5. Dampak ke poin P2-P6

| Poin | Dengan library | Tanpa library (Opsi C) |
|---|---|---|
| P2 pindahkan logika thread ke JS terpisah | Sebagian besar logika slot tetap harus dipertahankan dan dipindahkan | Sama |
| P3 diffing per pesan | Teks native: diurus library. Slot: diffing tanda tangan tetap ditulis (±20 baris). Hanya berlaku bila jendela kecil (K5) | Ditulis sendiri |
| P4 pemisah tanggal | Tersedia bawaan (beda tampilan banner pertama) | Ditulis sendiri |
| P5 kutipan bisa diklik | **Tidak tersedia**; tetap ditulis sendiri | Ditulis sendiri |
| P6 format teks | **Tidak sesuai WhatsApp** (K8); parser sendiri tetap diperlukan | Ditulis sendiri |

Dari lima poin, library hanya menggantikan sebagian pekerjaan P3 dan P4. Biayanya: adapter, 30 kasus lewat slot, dua mekanisme aksi, vendoring 516 KB, dan pagination.

## 6. Yang spike ini TIDAK bisa membuktikan

Dari rencana §6:
- Integrasi dengan `/inbox/test` asli: login, CSRF, endpoint, media dari `/inbox/media/{id}`. URL media di spike adalah `media/{id}.png` dan data berasal dari `fixtures.json`.
- Gambar dan sticker produksi, percakapan nyata yang panjang.
- Kecepatan di PC kasir dan perilaku di server tanpa internet. Angka K5 berasal dari Chromium headless di sandbox; penurunan 4× adalah emulasi CPU, bukan mesin nyata.
- Keuntungan library yang bergantung data gateway (centang delivered/read, reaction, pemutar audio): datanya tidak dikirim gateway sekarang. Tidak diuji.

Tambahan dari spike ini:
- Hanya Chromium; Firefox/Safari tidak diuji.
- Berkas CDN disajikan dari cache lokal (`page.route`), bukan dari jaringan sungguhan; latensi dan kegagalan CDN nyata tidak diukur. Hanya pemblokiran host yang disimulasikan.
- Kebocoran memori atau perlambatan setelah jam kerja: polling hanya diamati 60 detik.
- Composer, header, daftar percakapan, handoff, snooze, SLA di luar cakupan (tidak diuji). Perilaku pemilih emoji saat offline tidak terbukti (lihat K10).
- Tidak diporting: penanda "Terkirim tanpa kutipan", `tampilkanBubbleOutgoing`, `pesanCached`, pemanggilan `bersihkanSementaraSetelahReconnect`. Tidak ada uji bahwa bubble langsung setelah kirim tidak menggandakan pesan (AC-12).
- Retry media (`MEDIA_COBAAN_MAKS`, jeda 30 detik) disalin dari kode asli dan dicek hanya untuk jalur 404/410; kategori `sementara` dan `terlalu_besar` tidak diuji ulang.
- Vektor XSS terbatas pada sembilan yang ada di fixture; ini bukan audit keamanan penuh.
- Aksesibilitas (keyboard, pembaca layar) di dalam slot tidak diuji.
- Pemeliharaan jangka panjang v2 (README v3 hanya menyebut "tetap didukung di cabang v2"; lamanya tidak diverifikasi).

## 7. Langkah lanjut untuk tim

1. **Putuskan lebih dulu**: apakah keputusan "tanpa pagination, 500 pesan" tetap berlaku. Bila ya, hentikan opsi library; lanjutkan P2-P6 dengan Opsi C (P1 tetap berdiri sendiri). Patokan: render 500 pesan saat ini 130 ms.
2. Bila pagination diterima: ulangi K5 dengan jendela 50-100 pesan di **mesin pengembang dan PC kasir sungguhan**, dengan data nyata dari `/inbox/test`.
3. Sebelum adopsi, uji di mesin pengembang: integrasi `/inbox/media/{id}` (CSRF, status 410/413/5xx), grup (label pengirim), `tampilkanBubbleOutgoing` tanpa duplikasi, pemanggilan aksi nyata (`pilihKutipan`, `bukaPemilihTeruskan`).
4. Sebelum adopsi, putuskan K8 (parser format sendiri) dan vendoring library serta data emoji.
5. Lisensi dependency transitif (`emoji-picker-element`, `micromark`) belum dicek (riset §4).

**Usulan item `docs/TODO.md`** (belum ditambahkan, karena rencana §2 melarang mengubah `docs/` yang sudah ada; AGENTS.md §21 mengharuskan temuan baru dicatat di sana, jadi mohon ditambahkan bila setuju): tidak ada temuan baru yang berdiri sendiri di luar keputusan adopsi library. Patokan 130 ms untuk render 500 pesan saat ini dapat dipakai sebagai batas atas saat P3 dikerjakan.

## 8. Cara mengulang

Folder spike sudah dihapus dari branch ini. Pulihkan dari riwayat (`git checkout deeedb1 -- spike/vue-advanced-chat`), lalu ikuti `README.md` di folder itu. Ringkas: `node make-fixtures.js`, `node serve.js 8765`, `node build-baseline.js`, lalu `node run-spike.js` dengan variabel `PLAYWRIGHT_MODULE`, `CHROMIUM_PATH`, dan `CDN_CACHE`. Satu putaran penuh memakan sekitar 3 menit (K3 saja 60 detik). `build-baseline.js` membaca fungsi render dari `app/Views/inbox/index.php` pada rentang baris `v2.4` lama; setelah perpindahan ke `public/assets/js/inbox-thread.js` rentang itu tidak berlaku lagi, jadi pembanding baseline hanya valid terhadap commit `26a7847`.
