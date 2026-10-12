# Requirements: Emoji Picker Composer + Reaksi Masuk sebagai Kutipan di Inbox

- **Tanggal**: 2026-10-12
- **Status**: disetujui
- **Tier SDLC**: A (reaksi = perubahan lintas repositori POS ↔ WA Gateway; emoji picker sendiri setara Tier C namun ikut dalam lingkup permintaan)
- **Penanggung jawab**: (kasir Inbox AuliaPos)

## 1. Tujuan

Dua hal, dipicu permintaan user "emote seperti WhatsApp Web":

1. **Emoji picker** — kasir bisa menyisipkan emoji ke teks balasan dengan sekali klik, tanpa
   mengetik/kopi manual.
2. **Reaksi masuk** — reaksi emoji yang dikirim **pelanggan** pada sebuah pesan tidak lagi
   hilang diam-diam, melainkan tampil di thread sebagai bubble masuk yang **mengutip pesan
   yang direaksi** (memakai jalur kutipan yang sudah ada; bukan badge reaksi).

## 2. Kondisi saat ini (terverifikasi)

Fakta dari repo ini:

- Composer Inbox hanya punya tombol **Lampirkan** dan **Template**; **belum ada pemilih emoji**
  (`app/Views/inbox/index.php:120-132`). Pola tombol composer: `inbox-composer-ikon`.
- **Emoji di dalam teks sudah didukung** alat kirim yang ada: adapter menerima `text` apa
  adanya (maks 4096 char) (`C:\Projects\evolution-gateway\src\evolution\ci4Routes.js:151-186`),
  jadi mengetik/paste emoji sekarang pun sudah terkirim.
- **Reaksi masuk sengaja dibuang** sebagai noise: `reactionMessage` ada di `NOISE_NODES`
  (`C:\Projects\evolution-gateway\src\evolution\normalize.js:213-217`), terdokumentasi hasil uji
  nyata (`docs/uji-inbox-tipe-pesan-nyata.md:37,86,111`).
- **Jalur kutipan MASUK sudah lengkap** (dipakai balasan): `resolveKutipanMasuk()`
  (`app/Controllers/InboxGatewayApi.php:713`), kolom `quoted_wa_message_id` / `quoted_snippet` /
  `quoted_media_type` (`app/Models/MessageModel.php:69-79`), render bubble kutipan di
  `public/assets/js/inbox-thread.js`.
- **Pola sisip teks ke composer sudah ada**: Template Balasan mengisi `teksBalasan`
  (`public/assets/js/inbox-template.js:77-80`); event `input` pada composer membuang
  `operation_id` lama + auto-resize (`public/assets/js/inbox-page.js:2555-2559`).
- Adapter **tidak punya endpoint reaksi keluar**; hanya `/send`, `/send-media`, `/read`,
  `/delete`, `/edit`, `/media/download` (`src/evolution/ci4Routes.js`).

Fakta yang **belum diverifikasi**: bentuk pasti payload `reactionMessage` dari Evolution 2.3.7 —
apakah `reactionMessage.key` benar-benar memuat key pesan **target**, dan apakah tersedia id
unik per-reaksi untuk idempotensi (`wa_message_id`). Belum ada contoh payload nyata tersimpan
di repo; perlu 1× tangkapan nyata (log adapter/`evolution.log` atau tabel `Message` Evolution).

## 3. User story

- Sebagai kasir, saya ingin menyisipkan emoji ke balasan dengan cepat, supaya balasan lebih
  ramah tanpa mengetik berulang.
- Sebagai kasir, saya ingin melihat bahwa pelanggan memberi **reaksi** pada sebuah pesan,
  supaya saya paham pesan mana yang direspons pelanggan.

## 4. Acceptance criteria

### Emoji picker (EP)

- **AC-EP-1**: Given composer aktif (percakapan terpilih), when tombol emoji diklik, then panel
  emoji terbuka dan **tidak ada** pesan yang terkirim.
- **AC-EP-2**: Given panel terbuka dan kursor ada di posisi tertentu dalam teks, when sebuah
  emoji dipilih, then emoji **disisipkan di posisi kursor** (bukan mengganti seluruh isi),
  composer tetap fokus, dan belum terkirim.
- **AC-EP-3**: Given panel terbuka, when kasir klik tombol emoji lagi / klik di luar panel /
  tekan Esc, then panel tertutup.
- **AC-EP-4**: Given server **tanpa internet**, when panel emoji dibuka, then tetap berfungsi
  penuh (aset/data emoji lokal; tidak ada panggilan ke CDN).
- **AC-EP-5**: Given teks berisi emoji hasil sisip, when kirim, then teks terkirim apa adanya
  (tidak di-escape/diubah), dan bila penyisipan mengubah isi composer, `operation_id` lama
  dibuang (pola sama dengan `input` handler).

### Reaksi masuk (RX)

- **AC-RX-1**: Given pelanggan memberi reaksi pada sebuah pesan, when webhook diterima Gateway,
  then Gateway meneruskan baris **pesan masuk** dengan `message_type='text'`, `text=<emoji>`,
  dan `quoted.wa_message_id=<wa_message_id pesan target>`; reaksi **tidak lagi dibuang**.
- **AC-RX-2**: Given baris reaksi tersimpan, when thread dirender, then tampil bubble masuk
  berisi emoji yang **mengutip** pesan target lewat jalur kutipan yang ada; bila pesan target
  ada di DB kutipan menampilkan isinya, bila tidak tampil label "Pesan tidak ditemukan".
- **AC-RX-3**: Given reaksi masuk dicatat, when dihitung, then reaksi dihitung **sebagai pesan
  baru** (menambah unread dan menjadi pesan terakhir percakapan).
- **AC-RX-4**: Given reaksi berasal dari perangkat sendiri (`fromMe=true`), then diabaikan
  (tidak membuat baris).
- **AC-RX-5**: Given reaksi **ditarik** pelanggan (WhatsApp mengirim text kosong), then
  diabaikan (tidak membuat baris).
- **AC-RX-9**: Given reaksi terjadi di **grup**, then diabaikan (tidak membuat baris), sama
  seperti perilaku sekarang.
- **AC-RX-6**: Given webhook reaksi yang sama dikirim ulang, when diproses, then **tidak**
  membuat baris ganda (idempoten berdasarkan `wa_message_id` reaksi).
- **AC-RX-7**: Given pesan biasa (teks/media/album/dll.), when diproses, then perilakunya
  **tidak berubah** (regresi nol pada semua tipe yang sudah diuji di
  `docs/uji-inbox-tipe-pesan-nyata.md`).
- **AC-RX-8**: Given `text` reaksi berisi emoji/karakter khusus, when dirender, then tidak ada
  XSS (escape pada render, pola `escapeHtmlInbox`).

## 5. Batasan dan di luar cakupan

- **Batasan**: hanya reaksi **MASUK** (pelanggan → toko). Tidak membangun kemampuan kasir
  **mengirim** reaksi dari POS.
- **Tidak termasuk**:
  - Tombol/badge reaksi pada bubble (kasir tidak memberi reaksi dari POS).
  - Dedup/gabung beberapa reaksi pada satu pesan target menjadi satu elemen.
  - Edit/ganti/tarik reaksi dari sisi POS.
  - Notifikasi Windows/notifikasi khusus untuk reaksi (perilaku notifikasi mengikuti pesan baru).
  - Perubahan pada `/send`, `/send-media`, `/read`, `/delete`, `/edit`, `/media/download`,
    `/api/inbox/gateway/messages`, `/message-event`.

## 6. Dampak aturan bisnis

- [x] Mengubah kontrak POS ↔ WA Gateway — perubahan di **Gateway** (`normalize.js`), lintas repositori.
- [ ] Mengubah skema database — **rekomendasi: TIDAK** (pakai kolom `quoted_*` yang sudah ada).
- [ ] Menyentuh data keuangan (`transaksi`, `pembayaran`, `tagihan`, kas).
- [x] Mengubah perilaku Inbox (reaksi mulai tampil + memengaruhi unread) → **wajib dicatat di
      `docs/CHANGELOG.md`**.
- [ ] Menambah dependency frontend — **tidak** (panel kurasi lokal, keputusan Gate 1).

## 7. Asumsi dan pertanyaan terbuka

**Asumsi:**

- Payload `reactionMessage` memuat `key` pesan target (perlu verifikasi, §2).
- Reaksi yang dimaksud user terjadi pada chat **1-1** dengan pelanggan.

**Pertanyaan (mengubah hasil):**

1. **Cakupan reaksi — grup?** Hanya chat 1-1 (reaksi di grup diabaikan seperti sekarang), atau
   grup juga ikut (baris reaksi akan menyertakan nama pengirim)? Rekomendasi: **hanya 1-1** dulu
   (YAGNI; grup punya kebijakan sendiri dan nama pengirim menambah kompleksitas).
2. **Reaksi ditarik** (text kosong): diabaikan (rekomendasi) atau ditampilkan? Menampilkan
   berisiko menambah baris noise tanpa emoji.
3. **Cakupan emoji picker**: (a) panel **kurasi** emoji umum + beberapa kategori, tanpa
   dependency, data lokal (rekomendasi, YAGNI) — atau (b) picker **lengkap** ber-kategori +
   fitur cari, butuh library pihak ketiga + data emoji di-vendor ke `public/assets/`. Pilihan
   (b) menambah dependency dan ukuran aset; lihat juga `docs/riset-library-ui-chat-inbox.md:99-102`.

## 8. Persetujuan (Gate 1)

- [x] Disetujui oleh: pemilik proyek (via chat), tanggal: 2026-10-12 — jawaban: (1) reaksi hanya
  chat 1-1, grup diabaikan; (2) reaksi ditarik diabaikan; (3) emoji picker = panel kurasi lokal
  tanpa dependency.
