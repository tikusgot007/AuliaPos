# Requirements: Tandai pesan yang diedit atau dihapus pelanggan di Inbox WhatsApp

- **Tanggal**: 2026-10-04
- **Status**: draf (revisi setelah spike — kini mencakup edit DAN hapus)
- **Tier SDLC**: A (kontrak POS <-> WA Gateway lintas repositori + Webhook Evolution + UI baru)
- **Penanggung jawab**: user (pemilik toko)

## 1. Tujuan

Kasir perlu tahu ketika pelanggan **mengedit** atau **menghapus** pesan yang sudah
dikirim, supaya teks/keberadaan pesan yang tampil di Inbox tidak menyesatkan. Karena
isi hasil edit **tidak bisa dibaca** gateway (spike, §2), yang dibutuhkan bukan
menampilkan teks baru, melainkan **menandai pesan asli** ("diedit" / "dihapus
pelanggan — cek WhatsApp Web").

## 2. Kondisi saat ini & hasil spike (terverifikasi 2026-10-04)

Fakta terverifikasi (spike lokal TODO-F7; instance `aulia-test`):

- **Edit** tiba sebagai event `messages.upsert` dengan node
  `secretEncryptedMessage` (`secretEncType: 2` = MESSAGE_EDIT,
  `targetMessageKey.id` = `wa_message_id` pesan asli). Isi ada di `encPayload`
  (terenkripsi) dan **tidak bisa dibaca**:
  - Baileys `2.3000.1049242019` tidak men-dekode node ini (nama hanya ada di
    `WAProto`), jadi tidak muncul lewat `messages.update`.
  - Dekripsi manual dengan message secret pesan asli gagal (~400 kombinasi).
  - Evolution hanya menandai status pesan asli menjadi `EDITED` di DB-nya sendiri.
- **Hapus** tiba sebagai event webhook **`messages.delete`** (bukan `messages.upsert`
  maupun `messages.update`), dengan payload satu objek:
  `{ id: "<wa_message_id pesan yang dihapus>", remoteJid, remoteJidAlt, fromMe,
  status: "DELETED" }`. Sumber kode: Evolution
  `whatsapp.baileys.service.ts:1663-1678` (`Events.MESSAGES_DELETE`), lalu `continue`
  (tidak mengirim `MESSAGES_UPDATE`).
  - **Penting**: instance saat ini **tidak melanggan `MESSAGES_DELETE`**, sehingga
    event ini tidak pernah dikirim ke gateway. Setelah dilanggan (uji lokal),
    payload-nya tertangkap persis seperti di atas.
- Kedua kasus membawa `wa_message_id` target yang sama dengan kolom UNIQUE
  `messages.wa_message_id` di `aulia_inboxdb` → **bisa ditangani satu mekanisme**.
- Perilaku gateway sekarang (`C:\Projects\evolution-gateway\src\evolution\normalize.js`):
  - Edit → `detectMessageType()` null → `unsupportedLabel()` → **baris noise** baru,
    pesan asli tetap basi.
  - Hapus → `messages.delete` tidak punya handler → jatuh ke default "belum ditangani";
    pesan asli tetap tampil seolah masih ada.
- Kontrak gateway→CI4 yang ada: `POST /api/inbox/gateway/messages` & `/status`
  (`app/Config/Routes.php:33-34`, filter `gatewaytoken`).

## 3. User story

- Sebagai kasir, saya ingin pesan yang **diedit** pelanggan ditandai jelas, supaya
  saya tidak salah membaca teks lama sebagai isi final.
- Sebagai kasir, saya ingin pesan yang **dihapus** pelanggan ditandai jelas, supaya
  saya tidak mengira pesan yang sudah hilang masih berlaku.
- Sebagai kasir, saya ingin edit/hapus berulang tidak menghasilkan baris noise atau
  tanda berlipat, supaya percakapan tetap bersih.

## 4. Acceptance criteria

- **AC-1 (edit)**: Given pelanggan mengedit pesan teks yang sudah tersimpan, when
  Inbox dimuat/di-refresh, then pesan asli menampilkan penanda yang menyatakan
  **(a) sudah diedit pelanggan** dan **(b) teks yang tampil belum tentu versi
  terbaru — cek WhatsApp Web**; teks asli tidak diubah.
- **AC-2 (hapus)**: Given pelanggan menghapus pesan (untuk semua) yang sudah
  tersimpan, when Inbox dimuat/di-refresh, then pesan asli menampilkan penanda
  "dihapus pelanggan — cek WhatsApp Web"; isi asli tetap tersimpan (tidak dihapus
  dari DB).
- **AC-3 (semantik penanda edit)**: Penanda edit **tidak boleh** hanya "diedit"
  (di WhatsApp Web "diedit" berarti teks tampil = versi terbaru; di Inbox yang tampil
  justru versi lama).
- **AC-4 (tanpa baris noise)**: Given edit/hapus masuk, then tidak ada baris pesan
  baru bertipe `unsupported`/`secretEncryptedMessage`.
- **AC-5 (idempoten)**: Edit/hapus berulang pada pesan yang sama tetap menghasilkan
  satu penanda per jenis (retry/pengiriman ulang tidak menggandakan).
- **AC-6 (pesan asli tak ada)**: Bila pesan asli tidak ditemukan (balapan/menghilang),
  penanda aman dilewati tanpa error fatal.
- **AC-7 (non-teks)**: Pesan asli bertipe media/lokasi/kontak (tanpa teks) tetap bisa
  ditandai (tidak bergantung kolom `text`).
- **AC-8 (regresi)**: Jalur pesan masuk biasa (teks/gambar/media/sistem) tidak berubah.
- **AC-9 (langganan webhook)**: Instance Evolution melanggan `MESSAGES_DELETE` supaya
  hapus terdeteksi (perubahan konfigurasi, didokumentasikan).
- **AC-10 (teks basi samar)**: pada pesan ASLI yang sudah diedit atau dihapus
  pelanggan, **teks pesannya dibuat samar** (diredupkan) supaya kasir paham teks
  itu bukan isi terbaru/nyata. Badge "Diedit/Dihapus pelanggan" di atasnya tetap
  jelas (tidak ikut diredupkan). Penanda/placeholder lain (mis. "cek WhatsApp
  Web") TIDAK diubah.

## 5. Batasan dan di luar cakupan

- Batasan:
  - Isi teks hasil edit **tidak** ditampilkan (tidak terbaca) — hanya penanda.
  - Penanda bergantung pesan asli sudah tersimpan di CI4.
  - Deteksi hapus **wajib** melanggan `MESSAGES_DELETE` di Evolution.
- Tidak termasuk:
  - Mendekripsi/menampilkan teks hasil edit.
  - Menghapus isi pesan asli dari DB (hanya menandai).
  - Tipe `secretEncrypted` selain `MESSAGE_EDIT`, pesan terjadwal, stub sistem.

## 6. Dampak aturan bisnis

- [ ] Mengubah aturan bisnis (wajib dicatat di `docs/CHANGELOG.md`)
- [ ] Menyentuh data keuangan (`transaksi`, `pembayaran`, `tagihan`, kas)
- [x] Menambah kolom database (`messages`, grup `inbox` — diputuskan di Design)
- [x] Mengubah kontrak POS <-> WA Gateway (endpoint baru) dan langganan webhook Evolution

## 7. Asumsi dan pertanyaan terbuka

- Asumsi:
  - `data.id` pada `messages.delete` dan `targetMessageKey.id` pada edit sama dengan
    `wa_message_id` pesan asli (terverifikasi di uji lokal 2026-10-04).
  - Penanda disimpan agar tidak merusak teks asli dan idempoten.
- Usulan teks penanda (draft; finalisasi di Design — **wajib** mengikuti AC-3):
  - Edit: badge `✏️ Diedit pelanggan — versi ini belum tentu terbaru`.
  - Hapus: badge `🚫 Dihapus pelanggan — cek WhatsApp Web`.
  - Tooltip: `Aulia tidak bisa menampilkan versi terbaru; cek WhatsApp Web.`

## 8. Persetujuan (Gate 1)

- [ ] Disetujui oleh: <nama>, tanggal: <YYYY-MM-DD>
