# Requirements: WhatsApp Read Receipt Dua Arah (Inbox)

- **Tanggal**: 2026-10-07
- **Status**: draf
- **Tier SDLC**: A
- **Penanggung jawab**: (agent) — menunggu persetujuan pemilik

## 1. Tujuan

Membuat status baca WhatsApp terintegrasi dengan Inbox AuliaPos dalam dua arah:
(Arah 1) ketika kasir melihat pesan customer, WhatsApp mengirim blue tick ke
customer; (Arah 2) ketika customer membaca pesan kiriman POS, Inbox menampilkan
status baca. Tujuan akhirnya: kasir tahu pesan benar-benar sampai/dibaca tanpa
keluar dari POS.

## 2. Kondisi saat ini (terverifikasi)

- Gateway **sudah melanggan** event `MESSAGES_UPDATE` Evolution
  (`scripts/set-webhook.js`) tetapi **membuangnya** di `webhookRoutes.js:81-86`.
- AuliaPos **tidak punya** endpoint status baca, dan `messages.send_status`
  hanya `ENUM(received,sent,failed)` (`Migrations/2026-09-07-000001`).
- Evolusi sudah menyediakan mark-as-read: `POST /chat/markMessageAsRead/{instance}`
  dengan body `{ readMessages: [{ id, fromMe, remoteJid }] }` (source
  `src/api/routes/chat.router.ts:63`; `whatsapp.baileys.service.ts:3676`).
- Tombol "Tandai Dibaca" AuliaPos (`Inbox::tandaiDibaca`, `Inbox.php:2657`) hanya
  menulis `conversations.last_seen_by_assignee_at` (state **internal**), tanpa
  menyentuh WhatsApp.
- UI sudah punya placeholder centang (`renderCentangKirim`, `inbox-thread.js:1022`)
  tetapi hanya `sent`/`failed`; komentarnya menyatakan delivered/read belum ada.
- **Belum diverifikasi**: bentuk payload `MESSAGES_UPDATE` nyata dari instance
  produksi (diverifikasi dari source Evolution v2.3.7, belum dari webhook nyata).

## 3. User story

- Sebagai kasir, saya ingin pesan customer yang saya buka langsung "dibaca" di
  WhatsApp, supaya customer merasa cepat ditanggapi.
- Sebagai kasir, saya ingin melihat centang biru pada pesan saya, supaya tahu
  customer sudah membaca.

## 4. Acceptance criteria

- **AC-1**: Given percakapan pribadi `@s.whatsapp.net` dibuka kasir, when
  percakapan dirender, then AuliaPos memanggil Gateway `POST /read`; Gateway
  memanggil Evolution `markMessageAsRead`; kegagalan tidak menggagalkan pembukaan.
- **AC-2**: Tombol "Tandai Dibaca" (selain menulis state internal) juga memicu
  `POST /read` (best-effort).
- **AC-3**: Percakapan grup dan chat `@lid` dilewati (tidak ada request).
- **AC-4**: Given webhook `MESSAGES_UPDATE` `{ fromMe:true, status:'READ' }`,
  when Gateway meneruskannya, then `messages.read_at` baris cocok terisi
  (Asia/Jakarta).
- **AC-5**: `DELIVERY_ACK` -> `messages.delivered_at` terisi.
- **AC-6**: Idempotent & monoton: event `read` berulang tidak mengubah nilai;
  `delivered` yang datang setelah `read` tidak menghapus `read_at`; `read` juga
  mengisi `delivered_at`.
- **AC-7**: `wa_message_id` tidak ada / bukan pesan `outgoing` -> HTTP 200
  `matched:false` (bukan error, tidak memicu retry).
- **AC-8**: UI pesan keluar: `read_at` -> centang ganda biru; `delivered_at` ->
  centang ganda; tanpa keduanya -> satu centang; `failed` -> ikon "!".
- **AC-9**: Batas kepercayaan: `status` di luar {delivered,read} atau
  `wa_message_id` kosong/`>255` -> HTTP 400.
- **AC-10**: State internal `last_seen_by_assignee_at` dan makna
  `send_status(received/sent/failed)` **tidak berubah**.

## 5. Batasan dan di luar cakupan

- Batasan: fitur best-effort; keberhasilan blue tick bergantung setting
  read-receipts WhatsApp kedua pihak; `@lid` tidak didukung Evolution untuk read.
- Tidak termasuk: mengubah arti "Tandai Dibaca" internal; read receipt grup;
  indikator "mengetik"/presence; retensi/rotasi.

## 6. Dampak aturan bisnis

- [ ] Mengubah aturan bisnis (wajib dicatat di `docs/CHANGELOG.md`) — **tidak**;
  ini penambahan informasi status, bukan perubahan aturan.
- [ ] Menyentuh data keuangan — tidak.
- [x] Mengubah skema database (additif: `delivered_at`, `read_at`).
- [x] Mengubah kontrak POS <-> WA Gateway (endpoint baru `/read` dan
  `/api/inbox/gateway/message-status`).

## 7. Asumsi dan pertanyaan terbuka

- Asumsi: Evolution produksi = v2.3.7 (payload & endpoint sesuai source lokal).
- Pertanyaan (sudah dijawab pemilik pada 2026-10-07):
  1. Trigger Arah 1: **buka percakapan + tombol**.
  2. Skema: **kolom baru** (bukan ubah ENUM).
  3. Base/target PR POS: **v2.4 → v2.4**.

## 8. Persetujuan (Gate 1)

- [x] Disetujui oleh: pemilik (via "oke, kalau aman, lanjutkan"), tanggal: 2026-10-07
