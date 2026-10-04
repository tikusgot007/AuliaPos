# TODO-F8: Dekripsi teks hasil edit WhatsApp

Tanggal: 2026-10-04
Status: draft implementasi pada branch todo-f8-message-text

## Tujuan

Melengkapi TODO-F7 agar pesan asli di Inbox dapat menampilkan teks hasil edit
WhatsApp setelah Gateway berhasil melakukan dekripsi dan validasi protobuf.

TODO-F7 tetap menjadi fallback: bila dekripsi gagal, hanya marker edited yang
diterima dan teks lama tidak diubah.

## Kontrak Gateway -> AuliaPos

Endpoint:
POST /api/inbox/gateway/message-event

Body minimal:
{
  "wa_message_id": "<id pesan asli>",
  "event": "edited"
}

Body F8:
{
  "wa_message_id": "<id pesan asli>",
  "event": "edited",
  "edited_text": "<teks hasil edit yang sudah tervalidasi>"
}

Untuk event deleted, edited_text ditolak.

## Acceptance criteria F8

- F8-1: edited + edited_text valid mengganti text pada pesan ASLI.
- F8-2: edited_at tetap first-seen/idempoten walau beberapa edit berhasil.
- F8-3: target wa_message_id tidak ditemukan menghasilkan 200 matched=false.
- F8-4: edited_text wajib string non-kosong dan dibatasi 65535 byte.
- F8-5: edited_text pada event deleted ditolak 400.
- F8-6: teks lama tetap utuh bila payload F8 invalid/ditolak.
- F8-7: tidak ada baris messages baru dibuat oleh endpoint ini.

## Batasan

AuliaPos tidak mengetahui atau menyimpan messageSecret, encIv, encPayload, atau
candidate sender. Decryptor tetap menjadi tanggung jawab WA-Gateway. AuliaPos
hanya menerima edited_text setelah validasi payload.

## Verifikasi

Test feature InboxGatewayMessageEventTest mencakup F8-1 sampai F8-6.
Test F8-7 tetap diwarisi dari sifat endpoint message-event: endpoint hanya
memanggil update pada row yang sudah ada.
