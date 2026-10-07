# Checkpoint sesi: Toggle admin expand isi pesan dihapus (teks saja)

- **Tanggal**: 2026-10-07
- **Tier**: B (perubahan perilaku tanpa skema/rute baru; menyentuh privasi data,
  diperlakukan dengan kehati-hatian setara A pada verifikasi)

## Ringkasan

Setting GLOBAL baru lewat `.env`: `inbox.deletedMessageLocked` (default `true`).
Locked: server membuang `text`/`media_metadata` dari respons untuk pesan
`revoked_at` terisi (privasi nyata, bukan cuma UI). Unlocked: `text` dikirim
+ `expandable:true` HANYA untuk `message_type='text'`; media tetap
`expandable:false` dalam kondisi apa pun (Opsi A).

## Temuan penting (sebelum coding)

1. Server **tidak pernah** melakukan stripping sebelumnya — fitur "Pesan
   dihapus" dari sesi lalu adalah **UI-only masking** (`text`/`media_metadata`
   asli selalu ada di payload JSON, hanya disembunyikan render JS). Task ini
   adalah privacy hardening BARU, bukan mengembalikan perilaku lama.
2. **Bug pra-eksisting ditemukan & diperbaiki**: `renderIsiPesan()` di
   `inbox-thread.js` memeriksa `message_type` (image/sticker/document/audio/
   video) SEBELUM memeriksa `is_revoked`, sehingga pesan MEDIA yang dihapus
   tetap merender `<img>`/kartu dokumen PENUH. Ini bertentangan langsung
   dengan requirement "Media TIDAK di-expand, bahkan saat unlocked" — guard
   `is_revoked` dipindah ke paling awal fungsi.

## Perubahan

- `app/Config/Inbox.php`: `$deletedMessageLocked` (bool, default true),
  parsing env fail-closed ke `true` bila nilai tidak valid.
- `.env.example`: baris `inbox.deletedMessageLocked = true` + komentar.
- `app/Controllers/Inbox.php`: `terapkanKebijakanPesanDihapus()` dipanggil
  dari `apiMessages()` per pesan revoked; config dibaca sekali per request.
- `public/assets/js/inbox-thread.js`: `renderPlaceholderDihapus()`,
  `pesanDihapusBisaDiexpand()`, `togglePesanDihapus()` (onclick inline,
  konsisten pola file ini); guard `is_revoked` dipindah ke awal
  `renderIsiPesan()`.
- `app/Views/inbox/index.php`: CSS `.inbox-pesan-dihapus__toggle/__chevron/__isi/__teks/__meta`.
- `docs/CHANGELOG.md`, `docs/TODO.md` (TODO-F13).

## Verifikasi yang DIJALANKAN

- Unit: `phpunit` → **61/61 OK** (tidak berubah).
- Feature: `phpunit --configuration phpunit.feature.xml` → **125 OK** (2 skipped
  pra-eksisting, tidak bertambah); termasuk `InboxApiMessagesDeletedLockTest`
  **12/12** (locked default, locked explicit, non-revoked unaffected, unlocked
  text, unlocked 5 tipe media, outgoing, fail-closed env tidak valid).
- JS: `node --test tests/js/*.test.js` → **92/92 OK**; `inbox-thread.test.js`
  naik dari 56 → 60 (4 kasus baru: locked no-chevron, unlocked text chevron,
  unlocked semua tipe media no-chevron, toggle expand/collapse).

## Belum diverifikasi

- Verifikasi manual UI di browser (klik chevron sungguhan, DevTools Network
  untuk konfirmasi payload locked tidak bocor) — belum dijalankan, butuh
  server PHP + DB dev berjalan.
- `inbox.deletedMessageLocked` belum ditambahkan ke `.env` lokal (hanya
  `.env.example`) — default Config (`true`) sudah berlaku tanpa itu.
