# Requirements: Tampilkan teks hasil edit WhatsApp yang sudah tervalidasi (TODO-F8 Phase 2)

- **Tanggal**: 2026-10-05
- **Status**: draf
- **Tier SDLC**: A (mengubah skema database + kontrak internal API list pesan + UI)
- **Penanggung jawab**: user (pemilik toko)

## 1. Tujuan

Melengkapi TODO-F7 (penanda "diedit"/"dihapus") dan TODO-F8 Phase 1 (gateway
sudah bisa mendekripsi teks hasil edit WhatsApp dan POS sudah bisa
menyimpannya). Saat ini **backend sudah bisa menyimpan teks hasil edit yang
valid**, tapi **frontend belum tahu bedanya** dengan teks lama yang basi —
akibatnya teks yang sudah final/benar tetap ditampilkan samar dengan label
"belum tentu terbaru", yang menyesatkan kasir.

## 2. Kondisi saat ini (terverifikasi)

- `messages.edited_at` diisi SEKALI (idempoten) oleh `MessageModel::markLifecycle()`
  setiap kali event `edited` diterima, terlepas dari apakah teks hasil edit
  berhasil didekripsi gateway atau tidak (`app/Models/MessageModel.php:195-217`).
- `messages.text` HANYA diperbarui oleh `MessageModel::updateEditedText()`
  (`app/Models/MessageModel.php:160-194`), dipanggil dari
  `InboxGatewayApi::messageEvent()` **hanya** ketika payload webhook membawa
  field `edited_text` yang valid (string non-kosong, ≤65535 byte) —
  `app/Controllers/InboxGatewayApi.php:816-852`. Field ini opsional: gateway
  hanya mengirimnya bila dekripsi (HKDF-SHA256 + AES-256-GCM + decode
  `WAProto.Message`) berhasil. Bila gagal/tidak tersedia, hanya `markLifecycle()`
  yang jalan dan `text` TIDAK berubah (tetap versi pra-edit).
- `Inbox::riwayat()` menormalkan `is_edited` dari `edited_at` ke bool
  (`app/Controllers/Inbox.php:448`) — **tidak ada sinyal lain** yang membedakan
  "text sudah final (F8 berhasil)" dari "text masih versi lama (F8 gagal/belum
  aktif, fallback F7)".
- Frontend (`public/assets/js/inbox-thread.js:558-562` `renderLabelDiedit()`,
  `:924-931` `renderIsiPesan()`) HANYA membaca `is_edited`/`is_revoked`:
  pesan diedit SELALU ditampilkan dengan opacity 0.5 (`inbox-teks-basi`) dan
  label "Pesan diedit — versi ini belum tentu terbaru" — **tanpa pengecualian**,
  termasuk saat `text` sebenarnya sudah berisi hasil edit yang valid.
- **Dibuktikan nyata 2026-10-04** (uji E2E TODO-F8 Phase 1, gateway-test
  `C:\AuliaGateway-test`, lihat `docs/sesi/2026-10-04-promosi-aulia-htaccess-todo-f8-e2e.md`):
  pesan `id=377` (`text`="Text lain") dan `id=379` (`text`="Wkwkw") di
  `aulia_inboxdb.messages` keduanya berisi teks hasil edit yang SUDAH valid dan
  final, tapi tetap tampil samar dengan label "belum tentu terbaru" di Inbox —
  sesuai perilaku kode saat ini, bukan bug baru.

## 3. User story

- Sebagai kasir, saya ingin melihat teks hasil edit pelanggan/staf yang SUDAH
  berhasil diproses gateway dengan jelas (tidak samar, tidak berlabel
  "belum tentu terbaru"), supaya saya percaya itu teks final dan tidak perlu
  cek WhatsApp Web secara manual.
- Sebagai kasir, saya tetap ingin melihat penanda "belum tentu terbaru" untuk
  kasus dekripsi gagal/tidak tersedia (fallback TODO-F7), supaya saya tahu
  harus cek WhatsApp Web.

## 4. Acceptance criteria

- **AC-1 (sinyal baru)**: Given gateway mengirim `edited_text` valid dan
  `MessageModel::updateEditedText()` berhasil memperbarui `text`, when Inbox
  memuat daftar pesan, then baris pesan tersebut membawa penanda terpisah
  yang menyatakan teks SUDAH tervalidasi/final (nama kolom & field ditentukan
  di Design).
- **AC-2 (fallback tak berubah)**: Given event `edited` diterima TANPA
  `edited_text` valid (gateway gagal dekripsi / flag F8 nonaktif), then
  penanda AC-1 TIDAK disetel; perilaku tampilan tetap seperti sekarang
  (samar + "belum tentu terbaru") — regresi TODO-F7 tidak boleh terjadi.
- **AC-3 (tampilan teks tervalidasi)**: Given penanda AC-1 aktif, when Inbox
  merender bubble pesan, then teks TIDAK dibuat samar (`inbox-teks-basi`
  tidak dipasang) dan label berubah dari "Pesan diedit — versi ini belum
  tentu terbaru" menjadi label yang menyatakan teks adalah hasil edit yang
  sudah dikonfirmasi (teks pasti berbeda dari AC-2, rujuk AC-9/catatan di §7
  untuk draft kalimat).
- **AC-4 (idempoten, selaras F8 Phase 1)**: Edit berulang pada pesan yang
  sama dengan `edited_text` valid tetap memperbarui `text` ke edit TERAKHIR
  (perilaku `updateEditedText()` sudah begini — pastikan penanda AC-1 juga
  konsisten, bukan hanya first-seen seperti `edited_at`).
- **AC-5 (hapus tidak terpengaruh)**: `is_revoked`/badge "Pesan dihapus" dan
  teks samar untuk pesan terhapus TIDAK berubah oleh perubahan ini (hapus
  tidak pernah membawa `edited_text` — `InboxGatewayApi.php:818-821` sudah
  menolak kombinasi itu).
- **AC-6 (regresi F7)**: Semua AC pada
  `docs/requirements/2026-10-04-tandai-pesan-diedit-inbox.md` tetap terpenuhi
  untuk kasus TANPA `edited_text` (AC-2 di atas).
- **AC-7 (riwayat/polling)**: Penanda AC-1 konsisten antara muat awal thread
  dan polling berkala (`inbox-thread.js` AC-7/AC-8/AC-9 existing) — bubble
  yang berubah dari "belum tervalidasi" ke "tervalidasi" (edit datang setelah
  render pertama) di-redraw, bukan baris baru.
- **AC-8 (tidak membocorkan internal gateway)**: Penanda AC-1 adalah fakta
  biner milik POS sendiri (hasil kolom `text` sudah diperbarui atau belum) —
  TIDAK menyimpan/menyingkap detail kriptografi gateway (messageSecret,
  encPayload, encIv, kandidat sender), selaras batasan di
  `docs/requirements/2026-10-04-todo-f8-decrypt-edited-text.md` §"Batasan".

## 5. Batasan dan di luar cakupan

- Batasan:
  - Bergantung penuh pada TODO-F8 Phase 1 yang SUDAH berjalan (gateway
    mengirim `edited_text` tervalidasi) — tidak mengubah kontrak
    `POST /api/inbox/gateway/message-event` yang sudah ada
    (`docs/requirements/2026-10-04-todo-f8-decrypt-edited-text.md`), hanya
    menambah cara POS MENGINGAT bahwa `text` sudah diperbarui oleh F8.
  - Tidak mengubah logika dekripsi di gateway (`C:\Projects\evolution-gateway`).
- Tidak termasuk:
  - Menampilkan riwayat semua versi edit (hanya versi TERAKHIR, sesuai
    `updateEditedText()` yang ada).
  - Mengubah penanganan event `deleted` (AC-5).
  - Deploy ke produksi `aulia3` (terpisah, menunggu verifikasi versi
    Evolution produksi — lihat `docs/TODO.md` TODO-F8).

## 6. Dampak aturan bisnis

- [x] Mengubah aturan bisnis (wajib dicatat di `docs/CHANGELOG.md`) — tampilan
      teks edit berubah dari "selalu samar" menjadi "samar hanya bila belum
      tervalidasi".
- [ ] Menyentuh data keuangan (`transaksi`, `pembayaran`, `tagihan`, kas)
- [x] Mengubah skema database — kemungkinan kolom tambahan `messages`
      (ADDITIVE-ONLY, pola sama dengan migrasi TODO-F7, keputusan final di
      Design).
- [ ] Mengubah kontrak POS <-> WA Gateway (kontrak `message-event` TIDAK
      berubah — gateway sudah mengirim `edited_text`; perubahan murni
      internal POS: API list pesan + frontend).

## 7. Asumsi dan pertanyaan terbuka

- Asumsi:
  - Sinyal baru bisa diturunkan murni dari data yang sudah ada di POS
    (apakah `updateEditedText()` pernah berhasil untuk baris ini) — tidak
    perlu field tambahan dari gateway.
  - "Tervalidasi" berarti PERNAH berhasil diproses `updateEditedText()`
    minimal sekali; tidak ada state "sedang menunggu validasi" yang terlihat
    user (event edited masuk dan diproses atomically di satu request).
- Pertanyaan (maks. 3, hanya yang mengubah hasil):
  - **Q1**: Desain penyimpanan sinyal AC-1 — kolom boolean baru
    (`edited_text_resolved` atau nama lain) vs diturunkan dari kolom lain
    yang sudah ada (mis. bandingkan `text` dengan nilai asli — TIDAK bisa,
    karena nilai asli tertimpa)? **Rekomendasi**: kolom boolean/timestamp baru
    (pola additive sama seperti `edited_at`/`revoked_at`), diputuskan detail
    di Design.
  - **Q2**: Draft kalimat label pengganti untuk AC-3 — opsi:
    (a) "Pesan diedit" (netral, tanpa kualifikasi, karena teksnya sudah pasti
    benar) atau (b) "Pesan diedit — teks terbaru" (eksplisit menyatakan
    kepastian)? **Rekomendasi**: (b), supaya kasir yang sudah terbiasa dengan
    label lama tidak bingung kenapa badge tiba-tiba hilang kualifikasinya.
  - **Q3**: Apakah perlu backfill untuk baris pesan yang SUDAH punya
    `edited_text` tervalidasi dari sesi uji 2026-10-04 (`id=377`, `id=379` di
    `aulia_inboxdb` lokal), atau cukup berlaku untuk event baru ke depan?
    **Rekomendasi**: cukup event baru (data uji lokal, bukan produksi; tidak
    sepadan menambah kompleksitas backfill untuk 2 baris data uji).

## 8. Persetujuan (Gate 1)

- [ ] Disetujui oleh: <nama>, tanggal: <YYYY-MM-DD>
