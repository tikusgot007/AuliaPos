# Requirements: Tombol Edit/Hapus Pesan Keluar + Tampilan "Pesan Dihapus" di Inbox

- **Tanggal**: 2026-10-07
- **Status**: disetujui
- **Tier SDLC**: A (fitur baru + perubahan lintas repositori POS ↔ WA Gateway)
- **Penanggung jawab**: (kasir Inbox AuliaPos)

## 1. Tujuan

Memberi kasir kemampuan mengedit dan menghapus pesan yang **sudah dikirim** ke pelanggan
langsung dari Inbox AuliaPos (setara "Edit"/"Hapus untuk semua orang" di WhatsApp Web),
serta menampilkan pesan yang **dihapus pelanggan** sebagai teks "Pesan ini telah dihapus"
alih-alih hilang dari daftar.

## 2. Kondisi saat ini (terverifikasi)

Fakta yang diverifikasi dari repo ini:

- Adapter `evolution-gateway` **sudah** menyediakan endpoint `POST /delete` dan `POST /edit`
  (Bearer `inbox.gatewayToken`), terverifikasi E2E 2026-10-07 (commit gateway `8d77caf`).
- CI4 **belum** punya klien pemanggil kedua endpoint itu. Pola pemanggil yang ada:
  `Inbox::kirim()` (`app/Controllers/Inbox.php:1462`) → `callGatewaySend()`
  (`app/Controllers/Inbox.php:3849`), `callGatewayMarkRead()` (`:3949`),
  `callGatewaySendMedia()` (`:4008`).
- Penanda pesan diperbarui/dihapus **sudah ada** (TODO-F7/F8, selesai 2026-10-04):
  - Kolom: `messages.edited_at`, `messages.revoked_at` (`2026-10-04-000001_AddEditedRevokedToMessages.php`),
    `messages.edited_text_resolved_at` (`2026-10-05-000001_AddEditedTextResolvedToMessages.php`).
  - Handler Gateway: `InboxGatewayApi::messageEvent()` (`app/Controllers/InboxGatewayApi.php:797`)
    → `MessageModel::markLifecycle()` (`app/Models/MessageModel.php:202`) / `updateEditedText()` (`:176`),
    **idempoten** (`WHERE <kolom> IS NULL`).
  - `apiMessages()` menormalkan turunan: `is_edited`, `is_edited_text_resolved`,
    `is_revoked` (`app/Controllers/Inbox.php:463-465`).
  - UI: `public/assets/js/inbox-thread.js` — label `renderLabelDiedit()` (`:561`),
    `renderLabelDihapus()` (`:574`), teks samar `inbox-teks-basi` (`:938-942`).
- Pesan keluar tersimpan dengan `direction='outgoing'`, `send_status='sent'`, `wa_message_id`
  (dari respons Gateway), `message_type`, `text`, `created_at`
  (`app/Database/Migrations/2026-09-07-000001_CreateInboxTables.php`, tabel `messages`).
- Tombol aksi per bubble sudah ada: `renderAksiPesan()` / `aksiPesanTersedia()`
  (`public/assets/js/inbox-thread.js:511,531`).
- Infrastruktur `operation_id` klien sudah ada: `buatOperationId()` (UUID v4)
  (`app/Views/inbox/index.php:3133`).

Fakta yang **belum diverifikasi**: perilaku nyata Evolution saat edit > 15 menit pada
instance produksi; bentuk `MESSAGES_DELETE` webhook untuk pesan keluar (diasumsikan sama
dengan pesan masuk, sudah ditangani `messageEvent`).

## 3. User story

- Sebagai kasir, saya ingin mengedit teks pesan keluar yang sudah dikirim (dalam 15 menit),
  supaya saya bisa memperbaiki salah ketik tanpa mengirim pesan baru.
- Sebagai kasir, saya ingin menghapus pesan keluar (teks atau media) untuk semua orang,
  supaya pesan yang salah tidak lagi terlihat pelanggan.
- Sebagai kasir, saya ingin pesan masuk yang dihapus pelanggan tetap terlihat sebagai
  "Pesan ini telah dihapus", supaya saya paham ada pesan yang hilang, bukan bug UI.

## 4. Acceptance criteria

- **AC-1**: Given pesan keluar `message_type='text'`, `send_status='sent'`, `revoked_at IS NULL`,
  dan `created_at` < 15 menit lalu, when bubble dirender, then tombol **Edit** tampil.
- **AC-2**: Given pesan keluar yang sama tetapi `created_at` ≥ 15 menit, when bubble dirender,
  then tombol **Edit** tidak tampil (atau disabled) dengan tooltip batas 15 menit.
- **AC-3**: Given pesan keluar `message_type` media (image/document/sticker/video/audio),
  when bubble dirender, then tombol **Edit** TIDAK tampil.
- **AC-4**: Given pesan keluar (tipe apa pun) yang belum `revoked_at`, when bubble dirender,
  then tombol **Hapus** tampil.
- **AC-5**: Given kasir submit edit teks valid (dalam window), when endpoint CI4 `/edit`
  dipanggil, then adapter `POST /edit` dipanggil dan respons sukses (`state='edited'`)
  diteruskan; UI menampilkan teks baru + label "Diedit".
- **AC-6**: Given kasir konfirmasi hapus, when endpoint CI4 `/delete` dipanggil, then adapter
  `POST /delete` dipanggil dan respons sukses (`state='deleted'`) diteruskan; UI menampilkan
  "Pesan ini telah dihapus".
- **AC-7**: Given pesan masuk dengan `revoked_at` terisi (baik dari hapus pelanggan maupun
  webhook `MESSAGES_DELETE` menyusul hapus dari POS), when bubble dirender, then tampil
  placeholder italic abu-abu "Pesan ini telah dihapus" dan tombol Balas/Teruskan disembunyikan.
- **AC-8**: Given adapter menjawab HTTP 504 / `*_UNRESOLVED`, when kasir submit edit/hapus,
  then CI4 TIDAK auto-retry dan UI menampilkan "Hasil belum pasti, cek WhatsApp Web".
- **AC-9**: Given request edit/hapus yang sama diulang (retry manual dengan `operation_id`
  yang sama), when adapter dipanggil ulang, then hasil `replayed=true` diteruskan tanpa
  membuat operasi baru.
- **AC-10**: Given HTML `text`/`new_text` berisi karakter khusus, when dirender kembali,
  then tidak ada XSS (escape pada render).
- **AC-11**: Given `message-event` `deleted`/`edited` diproses dua kali, when handler dijalankan
  ulang, then tidak error dan nilai pertama dipertahankan (idempoten).

## 5. Batasan dan di luar cakupan

- Batasan WhatsApp: edit hanya teks; window edit 15 menit; hapus berlaku untuk semua tipe.
- Batasan adapter: `POST /edit` **wajib** `operation_id`; `POST /delete` opsional.
- Tidak termasuk: edit/hapus pesan masuk dari sisi POS; hapus pesan yang belum terkirim;
  riwayat revisi edit; edit media/caption.
- Tidak termasuk: mengubah kontrak `/send`, `/send-media`, `/media/download`, `/read`,
  `/api/inbox/gateway/messages`, `/message-event`.

## 6. Dampak aturan bisnis

- [ ] Mengubah aturan bisnis (wajib dicatat di `docs/CHANGELOG.md`)
- [ ] Menyentuh data keuangan (`transaksi`, `pembayaran`, `tagihan`, kas)
- [ ] Mengubah skema database — **rekomendasi: TIDAK** (pakai `revoked_at`/`edited_at` yang ada)
- [x] Mengubah kontrak POS ↔ WA Gateway (perubahan lintas repositori) — sisi adapter sudah ada & terverifikasi

## 7. Asumsi dan pertanyaan terbuka

- Asumsi: webhook `MESSAGES_DELETE` untuk pesan keluar yang dihapus dari POS datang menyusul
  dan ditangani `messageEvent` yang sama (idempoten) → `revoked_at` terisi pada baris keluar.
- Asumsi: `created_at` (waktu server Asia/Jakarta) dipakai sebagai basis window 15 menit.

**Pertanyaan (mengubah hasil):**

1. **Penempatan & penamaan endpoint CI4.** Task menyebut `POST /api/inbox/gateway/delete|edit`
   di `InboxGatewayApi` (filter `gatewaytoken`). Padahal `InboxGatewayApi` adalah saluran
   **Gateway→CI4**, sedangkan aksi kasir adalah **browser→CI4** (butuh sesi `auth`).
   Rekomendasi: tambah `Inbox::hapusPesan()`/`editPesan()` + route
   `POST /inbox/pesan/(:num)/hapus|edit` (filter `auth`), memanggil adapter via
   `callGatewayDelete()`/`callGatewayEdit()` (Bearer `gatewayToken`). Setuju?
2. **Otorisasi.** `Inbox::kirim()` TIDAK mengecek kepemilikan (`cekOwnership`). Apakah edit/hapus
   mengikuti pola kirim (cukup `auth`), atau wajib `cekOwnership` (hanya pemilik percakapan)?
   Rekomendasi: ikuti pola kirim (cukup `auth`) demi konsistensi; risiko sama seperti kirim.
3. **Confirm kolom.** Konfirmasi pakai `revoked_at`/`edited_at` yang sudah ada (tanpa migrasi
   `is_deleted` baru). Rekomendasi: ya, reuse.

## 8. Persetujuan (Gate 1)

- [x] Disetujui oleh: pemilik proyek (via chat), tanggal: 2026-10-07 — jawaban: endpoint di `Inbox` (auth), otorisasi `auth` saja (ikuti `kirim()`), reuse kolom `revoked_at`/`edited_at`.
