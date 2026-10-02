# Analisis Akar Masalah: TODO-F4 (kutipan hilang) dan TODO-F5 (forward masuk tidak ditandai)

- **Tanggal**: 2026-10-02
- **Jenis**: analisis akar masalah (tidak ada perubahan kode)
- **Repo terkait**: `WA-Gateway` branch `evolution` (adapter Evolution, produksi) dan branch `master` (gateway Baileys lama); `AuliaPos` `v2.4` (CI4)
- **Pertanyaan user**: "dulu masalah ini sudah pernah terselesaikan, sebelum pakai Evolution?"
- **Status**: analisis selesai; perbaikan menunggu tim

> **Catatan verifikasi.** Kode adapter (`evolution`), gateway lama (`master`), dan CI4 `v2.4`
> dibaca langsung. Bentuk payload nyata Evolution **tidak saya lihat sendiri**: itu dikutip dari
> TODO-F4 (cuplikan `evolution.log` 2026-10-02 14:22:35 WIB). Perilaku internal Evolution API
> (mengapa field ada di tingkat `record`) **tidak diperiksa** karena kodenya di luar repo yang
> diizinkan; ditandai **`[BELUM DIVERIFIKASI]`**.

---

## 1. Jawaban singkat

| | Gateway Baileys lama (`master`) | Adapter Evolution (`evolution`, sekarang) |
|---|---|---|
| **F4: kutipan balasan masuk** | **Sudah ada dan berfungsi** (REQ-010): `extractIncomingQuote()` | **Ada, tapi lebih sempit dan lebih miskin** dari versi lama (3 regresi di §2.2) |
| **F5: forward masuk ditandai** | **Tidak pernah ada.** Gateway lama hanya menandai forward **keluar** | **Tidak ada**, sama seperti dulu |

Jadi ingatan user **benar untuk F4** (dulu jalan, lalu rusak sebagian saat pindah ke Evolution),
tetapi **F5 belum pernah terselesaikan** di versi mana pun. Yang dulu "beres" di sisi forward
adalah arah **keluar** (kasir meneruskan ke pelanggan): `forwardMarker.js`, `contextInfo.forwardingScore`.

---

## 2. F4: apa yang terjadi

### 2.1 Gateway lama (`master`), jalan

`src/whatsapp/connectionManager.js:1770-1810`:

- Membaca `contextInfo` dari **enam** tipe konten: `extendedTextMessage`, `imageMessage`,
  `documentMessage`, `stickerMessage`, `audioMessage`, `videoMessage`.
- Mengirim `quoted = { wa_message_id, sender_jid?, snippet? }`. **`snippet`** dibangun dari
  `contextInfo.quotedMessage` (`buildQuotedSnippet`: teks maks. 200 karakter, atau `[Foto]`,
  `[Dokumen]`, `[Stiker]`, `[Audio]`, `[Video]`).
- Tes: `test/simulate-reply-quote.js` memakai bentuk Baileys mentah
  `extendedTextMessage.contextInfo` (baris ±340, ±402).

Di WhatsApp mentah, balasan teks **selalu** berupa `extendedTextMessage`, sehingga pembacaan
di dalam sub-objek sudah cukup.

### 2.2 Adapter Evolution, rusak sebagian

`src/evolution/normalize.js:306-319` (`extractQuotedContext`), dipanggil di baris 407:

```js
const candidates = [ messageObj.extendedTextMessage, messageObj.imageMessage,
                     messageObj.documentMessage,     messageObj.stickerMessage ]
// hanya mengembalikan c.contextInfo bila ada c.contextInfo.stanzaId
```

Perbedaan dengan versi lama:

1. **Tidak pernah membaca `contextInfo` di tingkat `record`.** Menurut TODO-F4, payload nyata
   Evolution untuk balasan teks polos berbentuk `message.conversation` dengan `contextInfo`
   **sejajar** `message` (di `record.contextInfo`), bukan di dalam `extendedTextMessage`.
   Fungsi hanya menerima `messageObj` (hasil `unwrapMessage(record.message)`), jadi
   `record.contextInfo` **tidak terjangkau sama sekali**. Mengapa Evolution meratakan bentuk
   itu: `[BELUM DIVERIFIKASI]` (dugaan: Evolution memindahkan `contextInfo` ke tingkat atas
   saat menyusun payload webhook).
2. **Daftar kandidat menyusut dari 6 ke 4**: `audioMessage` dan `videoMessage` hilang. Balasan
   yang berupa voice note atau video kehilangan kutipannya. (Verifikasi: bandingkan
   `INCOMING_QUOTE_CONTENT_KEYS` di `master` dengan `candidates` di `evolution`.)
3. **`snippet` tidak pernah dikirim.** Objek `quoted` di adapter hanya `{ wa_message_id,
   sender_jid }` (`normalize.js:429-432`); tidak ada `buildQuotedSnippet`. Di CI4,
   `resolveKutipanMasuk()` memakai `snippet` sebagai **fallback bila pesan sumber tidak ada di
   DB** (`InboxGatewayApi.php`); tanpa snippet, kasir melihat "Pesan tidak ditemukan"
   padahal pelanggan jelas membalas sesuatu.

Akibat kasus F4 yang ditemukan (conv `id=6`, message `id=190` "Siap di goyang", balasan ke
sticker `id=183`): `quoted_wa_message_id = NULL`, kutipan tidak tampil. Pesan itu sendiri
tersimpan normal.

### 2.3 Mengapa tidak ketahuan

- Tes adapter (`test/simulate-evolution-adapter.js`) tidak punya kasus **balasan masuk**;
  yang diuji adalah balasan **keluar** (`quote` via `/send`).
- CHANGELOG 2026-10-01 menyebut "quote teks, quote sticker" **terverifikasi**, tetapi itu arah
  **keluar** (kasir membalas). Balasan **masuk** dari HP pelanggan baru dicek 2026-10-02.
- Normalizer sendiri bertanda "BELUM DIVERIFIKASI DENGAN PAYLOAD NYATA" di komentar kepala
  `normalize.js`; bentuk payload dibuat defensif berdasarkan dugaan, bukan sampel.

---

## 3. F5: apa yang terjadi

### 3.1 Tidak pernah ada, di mana pun

- `master`: `grep` `isForwarded`/`forwardingScore` hanya menemukan `forwardMarker.js`,
  `baileysLoader.js`, `connectionManager.js:1275` (semua **kirim**), dan `test/simulate-forward.js`.
  Tidak ada kode yang **membaca** penanda forward dari pesan masuk.
- `evolution`: `normalize.js` tidak menyebut `isForwarded`/`forwardingScore`.
- Rantai kirim ke CI4 juga tidak membawa field itu:
  `normalize.js` (objek `event`) → `incomingBuffer.js` (kolom SQLite `incoming_queue`, tidak ada
  kolom forward; hanya `quoted_json`, `extra_json`) → `incomingDelivery.js:40-70` (badan POST) →
  `InboxGatewayApi::messages` (tidak membaca field forward).

### 3.2 Sisi CI4 sudah siap menampilkan

Kolom `messages.is_forwarded` ada (migrasi `2026-09-28-000001_AddIsForwardedToMessages.php`),
`apiMessages` mengubahnya ke bool, dan UI menampilkan label "Diteruskan"
(`renderLabelDiteruskan`). Selama ini hanya terisi untuk forward **keluar**
(`Inbox.php:1515`). Kolom tetap `0` untuk 100% pesan masuk (270 dari 270 pesan uji,
menurut TODO-F5).

### 3.3 Sinyal di payload

Gateway lama sudah memakai `contextInfo.forwardingScore >= 1` dan `isForwarded: true` untuk
**menulis** penanda (`forwardMarker.js:40-42`), jadi field itu valid di Baileys. Untuk
**membaca** di payload Evolution: lokasi tepatnya (di `record.contextInfo` atau di dalam sub-
objek) dan apakah `isForwarded` selalu ikut disertakan **`[BELUM DIVERIFIKASI]`**; TODO-F5
sendiri menyebut ada `forwardingScore` pada payload di `evolution.log`.

---

## 4. Akar masalah (satu kalimat tiap kasus)

- **F4**: pindah ke Evolution mengganti bentuk payload (`contextInfo` bisa di `record`,
  bukan hanya di dalam sub-objek pesan), dan adapter ditulis dari dugaan tanpa tes balasan masuk,
  sehingga logika lama (6 tipe + `snippet`) tidak ikut terbawa utuh.
- **F5**: tidak ada yang menulisnya sejak awal; fitur "Teruskan" dibangun untuk arah keluar saja,
  dan rantai transport masuk (adapter → buffer SQLite → badan POST → CI4) tidak punya tempat
  untuk field itu.

---

## 5. Arah perbaikan (untuk tim, tanpa kode)

### 5.1 F4 (di `WA-Gateway`, `src/evolution/normalize.js`)

1. Tambahkan `record.contextInfo` sebagai **kandidat pertama atau kedua** pada pembacaan
   kutipan, tetap mensyaratkan `stanzaId` (tidak mengubah perilaku untuk pesan tanpa kutipan).
2. Kembalikan daftar kandidat sub-objek ke **enam tipe** (tambah `audioMessage`, `videoMessage`)
   supaya paritas dengan gateway lama.
3. Pulihkan `snippet` (padanan `buildQuotedSnippet` di `master`) dari
   `contextInfo.quotedMessage`, kirim lewat `quoted.snippet`. CI4 sudah memvalidasi dan
   memotongnya (SEC-002/SEC-003), tidak ada perubahan CI4.
4. Tes baru: balasan **masuk** untuk empat bentuk: teks polos (`conversation` + `record.contextInfo`),
   `extendedTextMessage`, balasan ke media, dan balasan audio/video. Ambil sampel payload
   dari `evolution.log` produksi (anonimkan nomor).

### 5.2 F5 (lintas repo)

| Lapisan | Perubahan yang diperlukan |
|---|---|
| Adapter `normalize.js` | Baca `isForwarded` / `forwardingScore > 0` dari `contextInfo` (lokasi sesuai sampel nyata) → `event.is_forwarded` (bool) |
| `incomingBuffer.js` | Persist ke antrean: kolom baru (mengikuti pola `ALTER TABLE` yang ada untuk `quoted_json`, `extra_json`) **atau** lipat ke JSON yang sudah ada. Pilihan kolom baru lebih jelas; antrean lama tanpa kolom harus tetap bisa dibaca |
| `incomingDelivery.js` | Kirim `is_forwarded: true` **hanya bila benar** (pola yang sama dengan `quoted`/`extra`: payload lama tidak berubah) |
| CI4 `InboxGatewayApi::messages` | Baca `$payload['is_forwarded']`, validasi tipe (bool), simpan ke `messages.is_forwarded`. Kolom dan UI sudah ada |
| Kontrak | Dokumentasikan field baru sebagai opsional di dokumen kontrak gateway↔CI4 |

Keputusan terbuka (dari TODO-F5): reuse `is_forwarded` yang ada (**direkomendasikan**, tidak
perlu migrasi DB dan UI sudah siap) atau kolom baru khusus forward masuk. Tidak ada alasan yang
terlihat untuk membedakan keduanya di UI.

### 5.3 Urutan dan ketergantungan

1. F4 dulu: kecil, hanya di adapter, tidak menyentuh CI4 dan tidak ada migrasi.
2. F5: butuh sampel payload nyata untuk memastikan field, lalu perubahan di dua repo.
3. Tidak ada ketergantungan ke riset library UI; keduanya bisa dikerjakan terlepas dari keputusan UI.

### 5.4 Risiko

- **Jangan menebak lokasi `contextInfo`**: perbaikan F4 di §5.1 hanya aman bila diuji terhadap
  sampel nyata (balasan teks, media, grup). Normalizer memang sudah ditandai belum diverifikasi.
- **Balasan di grup**: `participant` pada `contextInfo` memetakan siapa yang dikutip; di Evolution
  dengan `@lid` ada pemetaan khusus (`groupInfo.js`). Perlu dicek bahwa `sender_jid` kutipan
  tidak berupa LID mentah, supaya label pengirim kutipan di CI4 tidak jatuh ke fallback.
- **Patch Evolution lokal** (`docs/evolution-viewonce-patch.md`, TODO-F3) harus diterapkan ulang
  tiap upgrade; perubahan F4/F5 di adapter **tidak** membutuhkan patch Evolution baru
  (data sudah ada di payload webhook menurut TODO-F4/F5).

---

## 6. Yang belum diverifikasi

- Letak dan nama pasti field forward di payload Evolution untuk tiap tipe pesan (teks, gambar,
  sticker, dokumen).
- Alasan Evolution meletakkan `contextInfo` di tingkat `record` untuk pesan `conversation`.
- Apakah ada bentuk lain selain dua yang diketahui (mis. `contextInfo` di dalam wrapper
  `ephemeralMessage`; `unwrapMessage` sudah membuka wrapper, tetapi tidak diuji untuk kutipan).
- Apakah balasan masuk audio/video benar-benar membawa `contextInfo` di Evolution (di Baileys
  lama dianggap ya).

## 7. Sumber

- `WA-Gateway` `evolution`: `src/evolution/normalize.js` (baris 306-319, 360-445),
  `src/delivery/incomingDelivery.js`, `src/store/incomingBuffer.js`, `docs/CHANGELOG.md`,
  `docs/evolution-viewonce-patch.md`, `test/simulate-evolution-adapter.js`.
- `WA-Gateway` `master`: `src/whatsapp/connectionManager.js` (1170, 1770-1830),
  `src/whatsapp/forwardMarker.js`, `test/simulate-reply-quote.js`, `test/simulate-forward.js`.
- `AuliaPos` `v2.4`: `docs/TODO.md` (TODO-F4, TODO-F5), `app/Controllers/InboxGatewayApi.php`
  (`resolveKutipanMasuk`), `app/Controllers/Inbox.php`, `app/Views/inbox/index.php`.
