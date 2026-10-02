# Riset: Library UI Chat Siap Pakai untuk Inbox WhatsApp AuliaPos

- **Tanggal**: 2026-10-02
- **Jenis**: dokumen riset + rekomendasi (tidak ada perubahan kode)
- **Repo terkait**: `AuliaPos` branch `v2.4` (Inbox hanya ada di `v2.2`+; branch `v2.1` tidak punya modul ini)
- **Status**: riset selesai; keputusan adopsi menunggu user (lihat §9)
- **Prioritas keputusan**: kebutuhan nyata kasir > minim perubahan > YAGNI (AGENTS.md §1)
- **Pola dokumen**: mengikuti `docs/riset-alternatif-baileys.md`

> **Catatan verifikasi.** Data versi, tanggal rilis, lisensi, dependency, dan ukuran file
> diambil langsung dari registry npm dan jsDelivr (2026-10-02). Daftar fitur
> `vue-advanced-chat` v2 dikutip dari README resmi paket `2.1.2` (props, events, struktur
> data). Fitur `react-chat-elements` dan `@chatscope/chat-ui-kit-react` hanya dari ringkasan
> README/halaman resmi dan **tidak diuji**. Yang tidak bisa dikonfirmasi ditandai
> **`[BELUM DIVERIFIKASI]`**. Tidak ada library yang dijalankan di sesi ini.

---

## 1. Ringkasan Eksekutif

1. **Masalah yang memicu riset (TODO-F4, TODO-F5) ada di gateway, bukan di UI.** Endpoint
   `POST /api/inbox/gateway/messages` (`InboxGatewayApi::messages`) tidak menerima field
   forward sama sekali, dan `quoted` hilang untuk balasan teks polos. Mengganti library UI
   **tidak memperbaiki keduanya**. Perbaikan gateway harus dikerjakan lebih dulu, terlepas
   dari keputusan ini.
2. UI saat ini memang kurang untuk fitur chat standar (§3), tetapi **sebagian besar kekurangan
   itu murah ditambal di kode sendiri** (pemisah tanggal, centang, loncat ke kutipan).
3. **Hanya satu kandidat yang cocok dengan stack tanpa build step: `vue-advanced-chat`
   v2.1.2** (web component, file UMD siap pakai lewat CDN, MIT). Dua kandidat React
   (`react-chat-elements`, `@chatscope/chat-ui-kit-react`) butuh React + JSX + bundler yang
   tidak ada di proyek, jadi **tidak direkomendasikan**.
4. Kecocokan `vue-advanced-chat` dengan fitur khas AuliaPos **sebagian**: model pesannya
   tidak punya tempat untuk kutipan-media dengan 5 cabang fallback (REQ-008), catatan
   internal, handoff, snooze, dan SLA. Semua itu harus lewat slot kustom. Slot pesan
   (`message_{id}`) **mengganti seluruh template bubble**, sehingga keuntungan "siap pakai"
   menyusut tepat di bagian yang paling banyak logikanya.
5. **Rekomendasi: jangan ganti penuh sekarang.** Perbaiki gateway (TODO-F4/F5) dulu, lalu
   lakukan **spike terbatas** `vue-advanced-chat` 2.1.2 di halaman `/inbox/test`
   (`Inbox::testPage`) dengan kriteria go/no-go di §8. Alternatif yang lebih murah:
   tambal UI sekarang (opsi C di §7).

---

## 2. Kondisi saat ini (terverifikasi dari kode `v2.4`)

- `app/Views/inbox/index.php`: **3.794 baris**, `<style>` + HTML + `<script>` dalam satu
  file. Tidak ada `package.json`; JS frontend hanya `public/assets/js/*.js` biasa.
- `app/Views/layout/main.php` **sudah memuat Bootstrap 5.3, jQuery, Font Awesome, DataTables
  lewat CDN** (jsDelivr, cdnjs, code.jquery.com). Jadi memuat satu skrip tambahan dari CDN
  tidak melanggar pola yang ada. Menyalin ke `public/assets/` (vendoring) tetap bisa bila
  server perlu jalan tanpa internet.
- **Alur data**: gateway → `POST /api/inbox/gateway/messages` (Bearer, filter
  `gatewaytoken`) → tabel `aulia_inboxdb.messages` → `Inbox::apiMessages` →
  `renderPesan()` di browser. Media lewat `GET /inbox/media/(:num)`.
- **Polling**: thread tiap 4 detik, daftar percakapan tiap 6 detik, status gateway tiap 15
  detik (`index.php:3787-3794`).
- **Render**: `renderPesan()` mengganti `innerHTML` seluruh thread tiap siklus; HTML dirakit
  dengan string concatenation dan handler inline (`onclick`, `onerror`).
- **Batas data**: `getByConversation($id, 500)`, tanpa pagination.
- **Field gateway yang diterima CI4**: `wa_message_id`, `chat_id`, `jid_type`,
  `message_type`, `text`, `direction`, `sender_jid`, `media`, `extra`, `quoted`,
  `contact_name`, `group_name`, `message_timestamp`, `phone`, `identity_hint`. **Tidak
  ada** forward, reaction, edit/revoke, atau status centang.

## 3. Kekurangan UI yang diminta user (baseline)

| # | Kekurangan | Sebab | Lapisan |
|---|---|---|---|
| 1 | Thread dirender ulang total tiap 4 detik (img dibuat ulang, seleksi teks hilang, state media harus diingat manual lewat `mediaGagal`/`mediaSementara`) | `innerHTML` massal | UI |
| 2 | Tanpa pagination (maks. 500 pesan) | `getByConversation(…, 500)` | UI + API |
| 3 | Tidak ada pemisah tanggal antar pesan | belum ditulis | UI |
| 4 | Tidak ada centang status; `bubble-meta` hanya jam | `send_status` hanya `received/sent/failed`; delivered/read tidak dikirim gateway | Gateway + UI |
| 5 | Kutipan tidak bisa diklik untuk loncat ke pesan asal | belum ditulis | UI |
| 6 | Audio/video hanya placeholder "cek WhatsApp Web" | binary memang sengaja tidak diambil | Keputusan desain |
| 7 | Tidak ada format teks WhatsApp (`*tebal*`, `_miring_`), pratinjau tautan, lightbox gambar | belum ditulis | UI |
| 8 | Reaction, pesan diedit/dihapus tidak tampil | gateway tidak mengirim | Gateway |
| 9 | Kutipan balasan teks polos hilang (TODO-F4); forward masuk tidak ditandai (TODO-F5) | ekstraksi `contextInfo` di gateway | **Gateway** |
| 10 | Satu file 3.794 baris, sulit dirawat | struktur | UI |

Baris 4, 6, 8, 9 **tidak akan membaik dengan library UI apa pun** tanpa perubahan di gateway
atau keputusan desain. Hanya baris 1, 2, 3, 5, 7, 10 yang benar-benar masalah tampilan.

---

## 4. Kandidat yang dievaluasi

Data dari registry npm / jsDelivr (2026-10-02):

| Kandidat | Versi | Rilis terakhir | Lisensi | Jenis | Ukuran dist | Prasyarat |
|---|---|---|---|---|---|---|
| **`vue-advanced-chat`** (v2, stabil) | 2.1.2 | 2025-12-19 | MIT | Web component, ada UMD | `umd.js` 516 KB (mentah, termasuk runtime Vue), CSS ada di dalamnya | Tidak ada (CDN `<script>`) |
| **`@advanced-chat/components`** (v3, nama baru) | 3.0.0-rc.3 (**release candidate**) | 2026-08-09 | MIT | Web component ESM; "bundles its Vue runtime" | `components.umd.js` 249 KB, `advanced-chat-components.js` 413 KB, CSS 38 KB | ESM; "not a drop-in replacement" untuk v2. Skrip CDN: `[BELUM DIVERIFIKASI]` |
| **`react-chat-elements`** | 12.0.18 | 2025-03-18 (±18 bulan lalu) | MIT | Komponen React | 615 KB unpacked | React 18.2 + bundler |
| **`@chatscope/chat-ui-kit-react`** | 2.1.1 | 2025-05-15 | MIT | Komponen React | 719 KB unpacked | React 16–19 + bundler |
| Chatwoot | – | – | – | Platform penuh | – | **Dikeluarkan** (user hanya mau lapisan tampilan) |
| CometChat / Stream Chat | – | – | – | SDK terikat SaaS | – | **Tidak diriset**; terikat backend vendor, tidak cocok data self-managed |
| Deep Chat | 2.5.1 | 2026-08-27 | MIT | Web component | – | **Dikeluarkan**: berorientasi chatbot LLM, bukan inbox multi-percakapan |

Dependency `vue-advanced-chat@2.1.2`: `emoji-picker-element@1.12.1`, `micromark`,
`micromark-extension-gfm`. Lisensi ketiganya `[BELUM DIVERIFIKASI]` (belum dicek tiap
paket). `emoji-picker-element` memuat data emoji dari `cdn.jsdelivr.net` secara default
(`emoji-data-source`), jadi di server tanpa internet picker emoji butuh data lokal.

---

## 5. Perbandingan vs kebutuhan AuliaPos

### 5.1 Kecocokan stack (pertanyaan §4.1 handoff)

| Kandidat | Tanpa build step? | Catatan |
|---|---|---|
| `vue-advanced-chat` 2.1.2 | **Ya** | `<script src=".../vue-advanced-chat.umd.js">` lalu `window['vue-advanced-chat'].register()` (README resmi). Properti array/objek di HTML biasa harus dikirim sebagai **string JSON** atau lewat properti DOM. Wajib pakai assignment array baru (bukan `push`), kalau tidak UI tidak update. |
| `@advanced-chat/components` 3.0.0-rc.3 | Belum jelas | README hanya menunjukkan `import '@advanced-chat/components/web-component'` (ESM). Ada `components.umd.js` di paket; apakah itu jalur CDN resmi `[BELUM DIVERIFIKASI]`. RC, belum stabil. |
| `react-chat-elements`, `chatscope` | **Tidak** | Butuh React + JSX + bundler (Vite/webpack) dan `package.json` baru. `react-chat-elements` mensyaratkan `react-dom` tepat `18.2.0`. |

### 5.2 Fitur chat standar (yang kurang di UI sekarang)

| Fitur | `vue-advanced-chat` v2 (README 2.1.2) | `react-chat-elements` | `chatscope` |
|---|---|---|---|
| Pemisah tanggal | Ya (field `date`) | `[BELUM DIVERIFIKASI]` | ada `MessageSeparator` (`[BELUM DIVERIFIKASI]` perilakunya) |
| Centang status | Ya: `saved` 1 centang, `distributed` 2, `seen` 2 biru, `failure` ikon merah | Ya (waiting/sent/received/read, per ringkasan README) | `[BELUM DIVERIFIKASI]` |
| Reply/kutipan | Ya (`replyMessage`: `content`, `senderId`, `files`) | Ya (per ringkasan README) | `[BELUM DIVERIFIKASI]` |
| Forward | **Tidak ada tampilan "Diteruskan"**; hanya contoh tombol `forwardMessages` lewat `message-selection-actions` kustom | Ya (per ringkasan README) | `[BELUM DIVERIFIKASI]` |
| Reaction | Ya | Ya (per ringkasan README) | `[BELUM DIVERIFIKASI]` |
| Pagination riwayat | Ya (event `fetch-messages` + `messages-loaded`) | `[BELUM DIVERIFIKASI]` | `[BELUM DIVERIFIKASI]` |
| Format teks (`*bold*`, `_italic_`, `~strike~`) | Ya, penanda bisa diubah lewat `text-formatting` | Tidak | Tidak |
| Pemutar audio, pratinjau media | Ya (`files[].audio`, `media-preview-enabled`) | Ya | `[BELUM DIVERIFIKASI]` |
| Typing indicator, daftar ruang, pencarian ruang | Ya | sebagian | Ya |
| Tema gelap, i18n teks | Ya (`theme`, `text-messages`) | – | – |

### 5.3 Pemetaan data AuliaPos → `vue-advanced-chat` v2 (pertanyaan §4.2)

| Kolom `messages` / respons API | Field library | Catatan |
|---|---|---|
| `id` | `_id` (string) | |
| `sender_jid` / `direction` | `senderId` | Pesan keluar → `senderId = currentUserId`; masuk → JID/ID pelanggan |
| `text` | `content` | |
| `message_timestamp` | `date`, `timestamp` | Library menerima string siap tampil (mis. `'13 November'`, `'10:20'`); format dilakukan di adapter |
| `sender_name` (hasil `attachSenderNames`) | `username` | |
| `send_status = sent` | `saved: true` | `delivered`/`read` **tidak ada** di data gateway, jadi hanya 1 centang; `failed` → `failure: true` |
| `message_type = image/document/sticker` | `files[]` (`type`, `url = /inbox/media/{id}`, `name`) | Placeholder "media kadaluarsa/Gateway terputus" tidak punya padanan; harus slot atau abaikan |
| `message_type = audio/video` | – | AuliaPos tidak menyimpan binary; hanya bisa lewat slot teks placeholder |
| `message_type = location/contact/unsupported` | – | Hanya bisa lewat slot `message_{id}` |
| `quoted_*` (6 kolom) | `replyMessage` (`content`, `senderId`, `files`) | **Tidak cukup**: 5 cabang fallback media kutipan (REQ-008) dan "Pesan tidak ditemukan" tidak muat di model ini |
| `is_forwarded` | – | Tidak ada field; harus slot |
| `is_internal` | `system: true` (tampil di tengah) atau slot | Catatan internal bukan pesan ke pelanggan; `system` mengubah tampilan, bukan semantik |
| `assigned_to`, handoff, snooze, SLA | – | Slot `room-header` / `room-list-item_{id}` / `room-options` |

Adapter (JS ±150–300 baris, **perkiraan**) wajib dibuat. Perkiraan ini bukan hasil
pengukuran.

### 5.4 Fitur khas AuliaPos yang non-standar (pertanyaan §4.3)

`vue-advanced-chat` v2 punya titik ekstensi lewat slot bernama (`room-header`,
`room-list-item_{id}`, `message_{id}`, `message-failure_{id}`, ikon dropdown, dll.) dan
`message-actions` kustom (`name`/`title`/`onlyMe`). Konsekuensinya:

- **Aksi Balas/Teruskan** bisa memakai `message-actions` bawaan + `message-action-handler`.
  Pembatasan khusus (tombol Teruskan nonaktif untuk audio/video/lokasi/kontak, tidak
  tampil di catatan internal) **tidak bisa dinyatakan per-tipe** oleh daftar aksi global;
  harus dicek di handler atau lewat slot. `[BELUM DIVERIFIKASI]` apakah `disableActions`
  per pesan cukup.
- **Kutipan media 5 cabang**, label "Diteruskan", penanda "Terkirim tanpa kutipan", kartu
  lokasi/kontak, placeholder `unsupported`: semuanya lewat slot `message_{id}` yang
  mengganti **seluruh bubble**. Artinya logika `renderIsiPesan()` dan `renderKotakKutipan()`
  (±450 baris) tetap harus dipertahankan, dipindahkan ke slot.
- **Daftar percakapan**: model `rooms` library (`roomId`, `roomName`, `unreadCount`,
  `lastMessage`, `users`, `typingUsers`) tidak punya padanan untuk filter `perlu_dibalas`,
  tab status, badge SLA, assignment. Perlu `room-list-item_{id}` per ruang atau tetap
  memakai daftar percakapan sendiri dan hanya mengganti panel thread (`single-room`).

---

## 6. Lisensi & pemeliharaan (pertanyaan §4.4)

- Keempat paket bertipe **MIT** (registry npm).
- **`vue-advanced-chat`**: aktif (rilis 2.1.1 dan 2.1.2 pada Des 2025), tetapi pengembangan
  utama pindah ke v3 (`@advanced-chat/components`, RC sejak Mar 2026). v2 "tetap didukung
  di cabang `v2`" menurut README v3; seberapa lama `[BELUM DIVERIFIKASI]`. Homepage paket
  masih menunjuk `antoine92190/vue-advanced-chat`, sedangkan repo aktif di org
  `advanced-chat`; satu orang pemelihara utama `[BELUM DIVERIFIKASI]`.
- **`react-chat-elements`**: rilis terakhir 2025-03-18, nyaris 18 bulan tanpa rilis.
- **`@chatscope`**: rilis terakhir 2025-05-15, ±16 bulan.
- `THIRD_PARTY_LICENSES.md` v3 dan lisensi dependency transitif **belum dicek**.

---

## 7. Opsi

**Opsi A, ganti penuh Inbox dengan `vue-advanced-chat` v2.**
- Untung: pagination, pemisah tanggal, centang, format teks, pemutar audio, tema gelap,
  render diffing (tidak lagi `innerHTML` massal).
- Rugi: logika bubble tetap ditulis ulang di slot; daftar percakapan AuliaPos (filter,
  SLA, assignment, handoff) tetap kustom; adapter data baru; `forward`, status
  delivered/read, reaction tidak muncul sampai gateway mengirimnya; risiko regresi di
  fitur yang sudah diuji (REQ-005–REQ-013, `docs/uji-inbox-tipe-pesan-nyata.md`); library
  ±500 KB runtime Vue ikut dimuat.
- Upaya: besar `[BELUM DIVERIFIKASI]`, tidak diukur.

**Opsi B, hanya panel thread pesan (`single-room`) pakai library, daftar tetap kustom.**
- Mengurangi risiko Opsi A dan tetap menyelesaikan baris 1, 2, 3 di §3. Slot `message_{id}`
  tetap perlu logika bubble lama.
- Cocok sebagai **bahan spike** (§8).

**Opsi C, tambal UI sekarang tanpa library** (rekomendasi jangka dekat).
- Diffing render per pesan (kunci `data-id`, tambahkan hanya yang baru), pemisah tanggal,
  loncat ke kutipan, parser format teks WhatsApp, endpoint `before_id` untuk pagination.
- Sesuai AGENTS.md (YAGNI, boring over clever); tidak menambah dependency; bisa
  dikerjakan bertahap dan diuji dengan alur yang sudah ada.

**Opsi D, kandidat React.** Ditolak: butuh React, JSX, bundler, dan `package.json` yang
tidak ada di proyek, untuk manfaat yang tidak lebih besar dari Opsi A.

---

## 8. Rekomendasi

1. **Kerjakan TODO-F4 dan TODO-F5 di gateway lebih dulu.** Tanpa itu, UI mana pun tetap
   salah menampilkan kutipan/forward. Ini tidak bergantung pada riset ini.
2. **Pilih Opsi C untuk perbaikan cepat**, lalu **spike Opsi B** hanya bila setelah Opsi C
   masih ada kekurangan yang berarti.
3. **Spike Opsi B** (batas waktu singkat, di `/inbox/test`, tanpa menyentuh `/inbox`):
   `vue-advanced-chat@2.1.2` UMD dari CDN, `single-room`, data dari
   `/inbox/api/conversations/{id}/messages` lewat adapter. **Go** hanya bila semua benar:
   - kutipan 5 cabang + label Diteruskan + kartu lokasi/kontak bisa dirender lewat slot
     tanpa menulis ulang logika dari nol;
   - polling 4 detik tidak membuat `<img>` berkedip / posisi scroll melompat;
   - pagination `fetch-messages` bekerja dengan endpoint `before_id`;
   - catatan internal terlihat jelas berbeda dari pesan pelanggan;
   - ukuran dan waktu muat halaman di PC kasir masih wajar.
   **No-go** bila logika slot menyalin hampir seluruh `renderIsiPesan()`.
4. Jangan adopsi `@advanced-chat/components` v3 selama berstatus RC.
5. Jangan pakai kandidat React.

---

## 9. Pertanyaan untuk user

1. Setuju urutan: gateway (F4/F5) → Opsi C → spike Opsi B bila perlu?
2. Server produksi (`AULIA-SERVER2`) boleh memuat skrip dari CDN (seperti Bootstrap/jQuery
   sekarang), atau library harus di-vendor ke `public/assets/`?
3. Apakah audio/video tetap placeholder (keputusan desain lama), atau ada rencana mengunduh
   binary-nya? Ini menentukan apakah pemutar audio bawaan library berguna.
4. Status centang delivered/read penting? Bila ya, itu perubahan di gateway (event ack
   WhatsApp) dan kontrak `POST /api/inbox/gateway/messages`, bukan di UI.

---

## 10. Sumber

- npm registry: `vue-advanced-chat`, `@advanced-chat/components`, `react-chat-elements`,
  `@chatscope/chat-ui-kit-react`, `deep-chat` (versi, tanggal, lisensi, dependency).
- jsDelivr: README `vue-advanced-chat@2.1.2` (props, events, struktur data, slot);
  daftar berkas dist tiap paket.
- README `advanced-chat/advanced-chat-components` (GitHub, status RC, kompatibilitas v2/v3).
- README `react-chat-elements` (GitHub) dan README `@chatscope/chat-ui-kit-react` (jsDelivr);
  halaman Storybook chatscope **tidak terbaca** dari sesi ini.
- Kode AuliaPos `v2.4`: `app/Views/inbox/index.php`, `app/Controllers/Inbox.php`,
  `app/Controllers/InboxGatewayApi.php`, `app/Models/MessageModel.php`,
  `app/Views/layout/main.php`, `app/Config/Routes.php`, `docs/TODO.md`.
