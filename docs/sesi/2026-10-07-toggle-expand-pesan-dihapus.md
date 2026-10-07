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

## Verifikasi manual browser (2026-10-07)

Dijalankan di dev lokal: `http://localhost/aulia/inbox` (Apache XAMPP port 80),
percakapan `628563324637` (conversation_id=4), dengan pesan revoked:
teks `3EB075D3278D6C9DDABE6C` (id 556, `oke2`) + `A5EEAADFEE8213C409189E92652D6722`
(id 551, `Tes ke 00707`), media `ACBA30FFC033FF8D1A746AFB0F3815D2` (id 555,
`image`). Command clear cache: `php spark cache:clear`.

LOCKED (`inbox.deletedMessageLocked=true`, default):
- Placeholder teks tanpa chevron, tidak bisa di-expand: OK.
- Placeholder media tanpa render gambar asli: OK.
- DevTools Network `/inbox/api/conversations/4/messages`: `text`=null,
  `media_metadata`=null, `expandable`=false: OK.
- DB tetap utuh (strip di response, bukan DB): OK — dicek langsung via MySQL.

UNLOCKED (`inbox.deletedMessageLocked=false`):
- Chevron muncul untuk teks; klik expand menampilkan teks asli + meta
  "Dihapus di WhatsApp pada ...": OK.
- Media tetap tanpa chevron & tanpa render: OK.
- DevTools: teks `expandable`=true + `text` asli; media `expandable`=false +
  `media_metadata`=null: OK.
- DB tetap utuh: OK.

REVERT (`inbox.deletedMessageLocked=true`):
- Chevron hilang, kembali placeholder permanen: OK.

Total 12/12 OK, tanpa bug / temuan CSS. `.env` lokal dikembalikan ke
`inbox.deletedMessageLocked = true`.

## Commit & push

- `ad938db` feat(inbox): toggle admin expand isi pesan dihapus (teks saja).
- `19aa8c3` docs(inbox): requirements/design/sesi + feature test edit/hapus pesan keluar.
- `43e7257` feat(inbox): route edit/hapus pesan keluar + tandai TODO-F12/F13 selesai.
- `26a1b9d` docs(todo): hapus TODO-F12 & TODO-F13.
- Push ke `origin/v2.4` (`f77bfa2..26a1b9d`).

## Belum diverifikasi

- Tidak ada; verifikasi manual browser (locked/unlocked/revert) sudah
  dijalankan dan lulus. Sisi gateway tidak tersentuh (endpoint `/delete` &
  `/edit` sudah ada & ter-push di `origin/evolution`).
