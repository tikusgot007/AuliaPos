# Requirement WA-Gateway untuk AuliaPos

> **Catatan pembaca:**
>
> - Dokumen ini adalah daftar requirement dan status terukur, bukan aturan bisnis. Aturan bisnis Inbox ada di [`CHAT.md`](./CHAT.md).
> - Status berasal dari tes di Aan-PC pada 21 September 2026 (Baileys 6.7.24, Node 20), dicatat lengkap di [`decisions/2026-09-21-m1-ticket01-baseline.md`](./decisions/2026-09-21-m1-ticket01-baseline.md).
> - Prioritas (Wajib / Sebaiknya / Nanti) adalah penilaian penyusun dan perlu ditinjau.

Sumber requirement: `docs/CHAT.md` (§2, §4–7, §9, §14, §18), `app/Controllers/InboxGatewayApi.php`, `app/Controllers/Inbox.php`, `app/Config/Inbox.php`, dan spec M3. Hanya tuntutan yang sudah tertulis di kode dan dokumen AuliaPos yang dimuat.

Legenda status: `[x]` terpenuhi, `[~]` sebagian atau belum pasti, `[ ]` belum terpenuhi.

## A. Kontrak antarmuka

- [x] **GW-01** (Wajib) Teruskan pesan ke `POST /api/inbox/gateway/messages` dengan field wajib `wa_message_id`, `chat_id`, `jid_type`, `message_type`, `message_timestamp`. Untuk pesan teks, `text` wajib.
  Status: terpenuhi, 100+ pesan tes sampai tanpa error 400.
- [x] **GW-02** (Wajib) Idempoten berdasarkan `wa_message_id`. Kirim ulang tidak boleh membuat baris ganda.
  Status: terpenuhi (UNIQUE di Gateway + cek di CI4), 0 duplikat di seluruh tes pesan masuk, termasuk saat WhatsApp mengirim ulang pesan setelah restart (lihat GW-24).
- [x] **GW-03** (Wajib) Heartbeat ke `POST /api/inbox/gateway/status` dengan status `connected` / `connecting` / `disconnected` / `logged_out`, interval maksimal 15 detik (AuliaPos menganggap basi setelah 30 detik).
  Status: terpenuhi (interval 15 detik). Perilaku `logged_out` belum diuji.
- [~] **GW-04** (Wajib) `POST /send` (`chat_id`, `text`) membalas `{success, wa_message_id, timestamp}` dalam kurang dari 10 detik (timeout `callGatewaySend` di AuliaPos).
  Status: 277–441 ms di kondisi normal. Kalau Gateway lambat lebih dari 10 detik, AuliaPos menyerah sementara pesan tetap terkirim, dan retry kasir menghasilkan pesan ganda (lihat GW-09).
- [x] **GW-05** (Wajib) `POST /send-media` (base64, batas Gateway 20 MB, sedangkan CI4 menolak lebih dulu di 15 MB) dan `POST /media/download` (timeout 30 detik on-demand, 8 detik prefetch).
  Status: terpenuhi menurut log media on-demand. Belum diuji ulang pada 21 Sep.
- [x] **GW-06** (Wajib) Autentikasi Bearer dengan satu shared secret dua arah. Token kosong berarti semua request ditolak (fail-closed).
  Status: terpenuhi (`requireCI4Token`).
- [x] **GW-07** (Wajib) Balas `409 NOT_CONNECTED` saat WhatsApp belum `connected`, supaya AuliaPos bisa memblokir aksi dengan cepat.
  Status: terpenuhi (dari kode).

## B. Perilaku yang dituntut aturan bisnis AuliaPos

- [ ] **GW-08** (Wajib) Pesan masuk tidak boleh hilang, termasuk yang tiba saat Gateway mati atau restart.
  Status: **belum**. Pesan offline (tipe `append`) dibuang di `connectionManager.js:385` (`type !== 'notify'`) setelah di-ack Baileys. Hilang 3/14 dan 3/15 pada tes kill.
- [ ] **GW-09** (Wajib) Kirim ulang manual oleh kasir tidak boleh menghasilkan pesan ganda tanpa disadari. `/send` dan `/send-media` harus menerima operation ID atau idempotency key, karena AuliaPos sengaja tanpa outgoing queue dan retry dilakukan manusia.
  Status: **belum**. Retry setelah timeout menduplikasi pesan pada 2 dari 3 percobaan API, dan **terbukti lewat Inbox** (Gateway dijeda 12 detik: pelanggan menerima 2 pesan, Inbox hanya mencatat 1).
- [x] **GW-10** (Wajib) Balasan dari WA Web atau HP diteruskan sebagai `direction='outgoing'` tanpa identitas staff, supaya kasir lain tahu pesan sudah dibalas.
  Status: terpenuhi, burst F: 15/15 sampai, semuanya `outgoing` dengan `sent_by_user_id` kosong. Pesan dari `/send` tidak terekam di antrean (bukan bagian requirement ini).
- [ ] **GW-11** (Wajib) `message_timestamp` harus waktu asli pesan di WhatsApp, karena AuliaPos memakainya untuk `last_message_at` dan urutan Inbox.
  Status: **belum terpenuhi**.
  - Buffer retry tidak menambah pergeseran (pemadaman 6 menit: selisih 447–460 detik antara pesan diterima dan dicatat tidak mengubah nilainya).
  - Tetapi timestamp yang diterima Gateway sudah tidak mencerminkan waktu kirim untuk sebagian pesan.
  - Pada tes pemadaman urutan kirim `Sjjs, Hhaaa, Hhhah, Hss, Hhsj` tampil di Inbox sebagai `Hhaaa, Hhhah, Sjjs, Hhsj, Hss`. Sama dengan burst I, J, F (contoh `I03`). Asalnya belum terbukti.
- [~] **GW-12** (Wajib) `jid_type` selalu benar (`lid` atau `pn`). `phone` hanya diisi untuk `pn`, tidak ditebak dari `@lid`. `identity_hint.lid` hanya untuk pesan `pn`.
  Status: `phone` sudah benar (null untuk `@lid`). `identity_hint` belum pernah diverifikasi ke server WhatsApp live (tercatat sebagai gap di `CHAT.md` §17).
- [x] **GW-13** (Wajib) Update Status WhatsApp (`status@broadcast`) tidak pernah diteruskan.
  Status: terpenuhi (`connectionManager.js:470`, filter paling awal).
- [x] **GW-14** (Wajib) Gambar, dokumen, dan sticker diteruskan dengan referensi lengkap (`direct_path`, `media_key`). Bila tidak lengkap, pesan dibuang. Audio dan video hanya metadata, tanpa binary.
  Status: terpenuhi menurut log dan tes Tahap 0.
- [x] **GW-15** (Wajib) Media kedaluwarsa dibalas `410`, kegagalan sementara dibalas `502`, supaya AuliaPos tahu mana yang boleh dicoba ulang.
  Status: terpenuhi (Tahap E).
- [x] **GW-16** (Wajib) Gateway tidak menyimpan state bisnis atau file media. SQLite hanya sebagai buffer retry.
  Status: terpenuhi.
- [x] **GW-24** (Wajib) Pesan yang dikirim ulang oleh WhatsApp setelah Gateway restart (sudah pernah diproses) tidak boleh menghasilkan baris ganda.
  Status: terpenuhi. Setelah restart, F01–F15 dikirim ulang dan gagal didekripsi (kunci sudah terpakai), dan tidak ada duplikat di AuliaPos (15/15 unik).
- [ ] **GW-25** (Sebaiknya) Pesan masuk tetap terdekripsi pada percobaan pertama walaupun kontak memakai alamat campuran `pn` dan `lid`.
  Status: **belum terjamin**.
  - Sesudah 07:02 UTC pada 21 Sep, 27 dari 45 pesan (burst I, J, F) gagal didekripsi dulu dan baru berhasil lewat retry.
  - Pola berlanjut pada tes pemadaman (7 error baru pada 5 pesan).
  - Pesan beralamat `pn`: 10 dari 10 gagal dulu. Sebelum 07:02 UTC: 0 dari 41.
  - Penyebab belum terbukti (hipotesis: sesi beralamat nomor telepon setelah `/send` ke alamat itu). Lihat bagian "Analisis lanjutan" di decision log.

## C. Kebutuhan operasional

- [~] **GW-17** (Wajib) Hidup kembali sendiri setelah crash, tanpa scan QR.
  Status: PM2 memulihkan dalam 2,5–4,8 detik tanpa QR. Tes reboot sungguhan dicoret dari scope pengembangan.
- [x] **GW-18** (Wajib) Satu nomor WhatsApp hanya untuk satu proses Gateway (dua proses membuat port 3000 bentrok dan sesi saling melepas).
  Status: terpenuhi. Hanya satu proses Gateway berjalan (dicek 21 Sep). Folder lama di `G:\wa-gateway-5b28eb6` masih ada dan berisiko dijalankan bersamaan.
- [ ] **GW-19** (Sebaiknya) Antrean retry pesan masuk punya batas percobaan dan dead-letter.
  Status: **belum**. Terukur pada pemadaman AuliaPos 6 menit: interval 3, 6, 12, 24, 48, 96, 120, 120 detik, `attempts` naik sampai 8 tanpa batas atau dead-letter. Pesan tetap sampai 5/5 setelah AuliaPos hidup lagi (115 detik kemudian, ditentukan batas backoff 120 detik).
- [ ] **GW-20** (Sebaiknya) Health yang bisa dipercaya: `connected` hanya bila benar-benar bisa kirim dan terima.
  Status: **belum diukur**. Status tetap `connected` selama semua burst tes, padahal banyak pesan sempat gagal didekripsi.

## D. Kebutuhan roadmap (belum berlaku sekarang)

- [ ] **GW-21** (Nanti, M2) Kabar status pengiriman eksplisit (terkirim, diterima, dibaca, gagal). Sekarang Gateway hanya membalas `success` saat kirim dan tidak punya handler `messages.update` atau receipt.
- [ ] **GW-22** (Nanti, M4) Strategi untuk kontak `@lid` tanpa nomor telepon (Customer Context). Baileys hanya menyediakan arah nomor ke LID.
- [x] **GW-23** (Info) M3 Fase 1 tidak butuh perubahan Gateway. Internal Note tidak pernah dikirim ke Gateway (spec CON-002).

## E. Catatan sisi AuliaPos (di luar Gateway)

Temuan uji 2b yang menyangkut AuliaPos, bukan Gateway, dicatat di sini supaya tidak hilang:

- Pada timeout, layar menampilkan "Tidak bisa menghubungi Gateway", padahal pesan bisa sudah terkirim. Kasir tidak mendapat petunjuk untuk memeriksa dulu sebelum mengirim ulang.
- Kiriman pertama yang terlambat tidak pernah tercatat di Inbox, sehingga riwayat Inbox berbeda dari yang diterima pelanggan.
- Belum diputuskan sebagai perbaikan. Kandidatnya mengikuti keputusan GW-09.

## Prioritas tindak lanjut

1. GW-08 dan GW-09 melanggar tuntutan inti AuliaPos. Ditutup oleh M1 Ticket 02 (filter `append`) dan Ticket 09–10 (idempotency).
2. GW-11 dan GW-25 perlu diselidiki, karena M3 memakai `last_message_at` untuk SLA dan urutan Inbox.
3. GW-19 dan GW-20 masuk M1 Ticket 06–07 dan 14.
