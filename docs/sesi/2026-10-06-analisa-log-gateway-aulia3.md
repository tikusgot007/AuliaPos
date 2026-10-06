# Checkpoint Sesi — Analisa log gateway aulia3 (bug balas-gambar + hasil F10)

- **Tanggal**: 2026-10-06
- **Status**: selesai — murni analisa **read-only**, tidak ada perubahan kode.
- **Repo / target**: aulia3 (`D:\evolution-gateway`, `D:\evolution-api-server`,
  `D:\kilo\logs`), `aulia_inboxdb` (AULIA-SERVER2), snapshot `evolution-gateway.sqlite`.
- **Rujukan**: `docs/TODO.md` (TODO-F10, TODO-L1); repo gateway
  `C:\Projects\evolution-gateway`; `docs/laporan-keputusan-todo-f8-dekripsi-pesan-edit.md`.

## Konteks

Berawal dari permintaan "cek & tampilkan log gateway aulia3", berkembang menjadi
diagnosis 9 baris `outgoing_operations` berstatus `in_flight` dan akar bug
"balas gambar dengan gambar", lalu tinjauan hasil monitoring F10.

## Selesai

- Tinjauan `adapter.log` + `evolution.log` (+ arsip `arsip\*.zip`) dan
  `monitor-aulia3.log` di aulia3.
- Snapshot SQLite `outgoing_operations` (copy `.sqlite`+`-wal`+`-shm` ke lokal,
  query `better-sqlite3` read-only).
- Query read-only `aulia_inboxdb.messages` di AULIA-SERVER2 (mysql client) untuk
  korelasi chat+waktu; bandingkan file Evolution sebelum/sesudah patch F8 di aulia3.

## Temuan

1. **Stack sehat.** Per 06 Okt: `pg/evo/adapter up`, `state=connected(6285155105633)`,
   `authReject=0`, tanpa dead-letter/decrypt. Adapter restart **06 Okt 08:03 WIB**
   (rotasi log boot `arsip\adapter_20261006_080248.log.zip`).
2. **9 baris `in_flight` = kiriman MEDIA BERKUTIPAN (balas-gambar) yang gagal 04–05 Okt.**
   Bukan kiriman saat restart — sisa lama yang dilaporkan rutin startup. Semua
   `kind=media`; error Evolution `TypeError: Cannot read properties of undefined
   (reading 'fromMe')` di `generateWAMessageFromContent` (Baileys `messages.js:522`),
   dipanggil dari `mediaMessage` → `SendMessageController.sendMedia`. Artinya tiap
   kiriman membawa `quoted` (balasan). **Sudah diperbaiki** oleh commit gateway
   `b48c2e5` (05 Okt 15:29, "Fix quoted image replies via Evolution sendMedia",
   `sendMedia` pakai JSON+base64 dengan `quoted` object); kode terpasang di aulia3
   sudah memuat perbaikan. **Tidak ada** error `fromMe` sejak 05 Okt 15:04:55.
3. **Isi pesan gagal tidak tersimpan di mana pun (celah auditabilitas).** Gateway
   sengaja hanya menyimpan `payload_hash` (`src/store/outgoingOperations.js:15`,
   SEC-001); POS hanya menulis baris `messages` **setelah kirim sukses**
   (`Inbox.php:1594` → insert `:1642`), dan `send_status` tak punya nilai `failed`.
   Bukti: 0 exact match 9 `operation_id` di `messages`. Kandidat isi (dari chat+waktu)
   = gambar (mis. `01. Qris + Rekening.jpg`, `image.png`) tanpa caption; 8 gagal
   `fromMe`, 1 `Timeout`.
4. **Perubahan pencatatan log 05 Okt (F8/LID).** Patch F8 menghapus
   `console.log(messageRaw)` + `this.logger.verbose(messageRaw)` di
   `whatsapp.baileys.service.ts` (jalur `MESSAGES_UPSERT`). Terverifikasi: file
   cadangan `.orig-20261005134404`/`.pre-f8-*` punya 2 baris, produksi tidak.
   Efek: `evolution.log` tak lagi memuat payload mentah (baik dari sisi keamanan,
   tapi mengurangi jejak forensik payload).
5. **Hasil monitoring F10** (`D:\kilo\monitor-aulia3.log`, 05 Okt 10:09 → 06 Okt 09:53):
   `decrypt=0 sessionNoMatch=0 stubIgnored=0 msgCounter=0`; 2 event F10 lama
   (`last=05 Okt 10:07:27 SESSION_NO_MATCH`, pra-monitoring); **1 WARN** (05 Okt 16:02,
   false alarm `state=(belum ada)` saat alat baru dipasang); `authReject=0`,
   `evolution.log` tidak pernah 0-byte; `flaps` 2→3 akibat restart 08:03 lalu stabil.
   Ada **jeda malam 20:23→08:08** (aulia3 mati malam), bukan kegagalan monitor.

## Keputusan penting

- Tidak ada perubahan kode/data. Analisa memakai akses read-only (snapshot SQLite +
  query `aulia_inboxdb`); tidak ada operasi tulis terhadap produksi.

## Tersisa

Dipindahkan ke `docs/TODO.md` (lihat TODO-L2, TODO-L3; catatan 06 Okt di F10/L1).

## Belum diverifikasi / risiko

- Apakah 8 kiriman gagal akhirnya sampai ke pelanggan lewat kirim-ulang (baris sukses
  punya `operation_id` berbeda, jadi tak bisa dipastikan 1:1).
- Tipe target kutipan (gambar/teks) tidak tersimpan pada catatan gagal.
- Window observasi F10 belum selesai (dijadwalkan 2026-10-08).

## Titik masuk sesi berikutnya

- **Baca**: file ini; `docs/TODO.md` (TODO-F10, TODO-L1/L2/L3).
- **Jalankan**: tinjau `\\aulia3\D\kilo\monitor-aulia3.log` (analisis F10 08 Okt);
  opsional cleanup 9 baris `in_flight`.
