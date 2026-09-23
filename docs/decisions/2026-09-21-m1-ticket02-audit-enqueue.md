# M1 Ticket 02 — Audit Enqueue: titik kehilangan pesan masuk (2026-09-21)

> **Catatan pembaca:** dokumen ini append-only, melanjutkan `2026-09-21-m1-ticket01-baseline.md`. Ticket 02 murni audit: **tidak ada kode yang diubah**. Semua path kode ada di repo **WA-Gateway** (branch `feature/stage-1-reliability` @ `3fd5f40`, kode sama dengan `5b28eb6`), bukan di repo AuliaPos. Perbaikan tercatat sebagai usulan, bukan keputusan.

Tujuan: mendata semua tempat di jalur pesan masuk, dari event Baileys sampai baris tersimpan di `incoming_queue`, tempat sebuah pesan bisa hilang tanpa terlihat. Ini melanjutkan risiko P0 #1 ("incoming enqueue failure") dan temuan `type !== 'notify'` dari Ticket 01.

Jalur yang diaudit: `_onMessagesUpsert()` → `_handleIncomingMessage()` → `incomingBuffer.enqueue()` (`src/whatsapp/connectionManager.js`, `src/store/incomingBuffer.js`). Bagian pengiriman ke AuliaPos (`incomingDelivery.js`) sudah tercakup di Ticket 01 Baseline 4.

Legenda bukti: **Terukur** = terlihat di tes nyata. **Diuji** = dibuktikan dengan uji kecil hari ini. **Kode** = dari membaca kode, belum terlihat di runtime.

## Ringkasan temuan

| ID | Titik | Dampak | Bukti | Prioritas usulan |
|---|---|---|---|---|
| E-01 | `connectionManager.js:385` `type !== 'notify'` | Pesan yang tiba saat Gateway offline (`append`) dibuang | **Terukur** (hilang 3/14 dan 3/15) | 1 |
| E-03 | `INSERT OR IGNORE` + hasil `changes` tidak dicek | Pelanggaran `NOT NULL` dibuang diam-diam tanpa error | **Diuji** | 2 |
| E-04 | `connectionManager.js:635` catch di sekitar `enqueue` | Gagal simpan hanya dilog, pesan hilang (sudah di-ack Baileys) | Kode | 2 |
| E-05 | `_resolveLidForPhoneJid()` di-`await` sebelum enqueue, tanpa timeout | Pesan `pn` menunggu query jaringan sebelum tersimpan | Kode | 3 |
| E-06 | `connectionManager.js:395` catch-all di `_onMessagesUpsert` | Error apa pun sebelum enqueue berarti pesan dibuang, tanpa retry | Kode | 3 |
| E-02 | `_handleIncomingMessage` tidak membuka pembungkus pesan | Pesan ephemeral / view-once / edited dianggap "belum didukung" dan dibuang di level debug | Kode + Baileys | 3 |
| E-09 | JSON fallback `_load()` | File korup berarti antrean mulai dari kosong | Kode | 3 (P0 #2) |
| E-07 | `connectionManager.js:464` `!msg.message` | Upsert tanpa konten dibuang tanpa log | Hipotesis | perlu verifikasi |

Temuan informasi (bukan cacat): E-08 (media dengan referensi tidak lengkap dibuang, sesuai `CHAT.md` §6.1), E-10 (idempotensi terbukti bekerja), E-11 (event dari socket generasi lama diabaikan, sengaja), E-12 (filter `status@broadcast`, sengaja), E-13 (batasan desain Baileys).

## Rincian

### E-01 — Pesan offline (`append`) dibuang (Terukur)

- `_onMessagesUpsert` langsung `return` untuk semua tipe selain `notify`. Baileys 6.7.24 memberi tipe `append` pada pesan yang dikirim ulang WhatsApp setelah reconnect (`messages-recv.js:699`).
- Baileys sudah mengirim tanda terima untuk pesan itu, sehingga tidak akan dikirim ulang. Rincian dan angka ada di decision log Ticket 01.
- Usulan: proses `append` juga dan andalkan `wa_message_id UNIQUE` untuk idempotensi (terbukti tidak menghasilkan duplikat, lihat E-10). Perlu memastikan riwayat yang tidak diinginkan tidak ikut masuk. History sync sudah dinonaktifkan di konfigurasi (`History sync is disabled by config`).

### E-03 — `INSERT OR IGNORE` membuang pelanggaran `NOT NULL` diam-diam (Diuji)

- Skema `incoming_queue` mewajibkan `wa_message_id`, `chat_id`, `jid_type`, `message_type`, `message_timestamp` (`NOT NULL`).
- Uji pada database memori dengan `better-sqlite3`: baris normal `changes=1`; duplikat `changes=0` tanpa exception; `wa_message_id` NULL `changes=0` tanpa exception; `message_timestamp` NULL `changes=0` tanpa exception.
- Gateway tidak pernah memeriksa `changes`, jadi `enqueue()` kembali normal untuk baris yang sebenarnya tidak tersimpan. Contoh pemicu: `msg.key?.id` kosong sehingga `messageId` menjadi `null` (`connectionManager.js` membangun `messageId: msg.key?.id || null`).
- Kemungkinan terjadi di dunia nyata kecil (`key.id` hampir selalu ada), tetapi kegagalannya senyap sepenuhnya.
- Usulan: cek `changes`. Kalau 0, cek apakah `wa_message_id` sudah ada (duplikat sah) atau tidak (log error keras). Validasi field wajib sebelum insert.

### E-04 — Gagal enqueue hanya dilog (Kode, P0 #1)

- Blok `try/catch` di sekitar `incomingBuffer.enqueue(...)` (`connectionManager.js:631–644`) hanya menulis `logger.error('[DELIVERY] GAGAL menyimpan pesan ke SQLite buffer -- pesan ini berisiko tidak sampai ke POS')`, lalu lanjut.
- Karena Baileys sudah mengirim tanda terima, pesan itu tidak akan datang lagi. Pemicu yang mungkin: disk penuh, database terkunci, file rusak.
- Usulan: coba ulang di dalam proses beberapa kali, dan sediakan penyimpanan sementara di memori atau berkas yang dikuras ulang. Ini beririsan dengan Ticket 03 (durable buffer).

### E-05 — Enqueue menunggu query jaringan tanpa timeout (Kode)

- Untuk pesan `jid_type='pn'`, `_resolveLidForPhoneJid()` memanggil `this.sock.onWhatsApp(phoneJid)` dengan `await`, hanya dibungkus `try/catch`, tanpa `Promise.race` atau timeout. Enqueue baru terjadi sesudahnya.
- Karena `_onMessagesUpsert` memproses pesan berurutan, satu query yang tersangkut menahan semua pesan berikutnya dalam batch. Kalau proses mati pada jendela itu, pesan yang sudah di-ack hilang.
- Belum terlihat tersangkut di tes. Ini risiko dari kode.
- Usulan: simpan pesan ke buffer dulu, lalu tambahkan `identity_hint` secara terpisah (atau beri timeout pendek).

### E-06 — Catch-all membuang pesan tanpa retry (Kode)

- `connectionManager.js:395`: exception apa pun dari `_handleIncomingMessage` hanya dilog (`Gagal memproses satu pesan masuk, dilewati`). Semua yang terjadi sebelum `enqueue` (ekstraksi teks, media, LID) bisa membuat pesan hilang.
- Usulan: sediakan jalur cadangan yang menyimpan event mentah minimal sebelum pemrosesan lanjut.

### E-02 — Pesan berbungkus dianggap tidak didukung (Kode + Baileys)

- `_handleIncomingMessage` membaca `Object.keys(msg.message)[0]` dan hanya mengenali teks, gambar, dokumen, sticker, audio, video. Selain itu dibuang dengan `logger.debug(...)` dan `return` (`connectionManager.js:560–565`).
- Baileys memiliki `normalizeMessageContent()` yang membuka `ephemeralMessage`, `viewOnceMessage`, `viewOnceMessageV2`, `viewOnceMessageV2Extension`, `documentWithCaptionMessage`, dan `editedMessage`. Gateway tidak memanggilnya, dan `messages-recv.js` tidak menormalkan konten sebelum memancarkan `upsert` (dicek 21 Sep).
- Dugaan dampak: pelanggan yang mengaktifkan pesan sementara (disappearing messages) mengirim pesan berbungkus `ephemeralMessage`, dan pesan itu dibuang di level `debug`, jadi tidak terlihat di log normal.
- Belum diuji dengan obrolan pesan sementara yang nyata.
- Usulan: normalkan konten di awal, dan catat tipe yang dibuang di level `info`.

### E-09 — JSON fallback mulai dari kosong bila file korup (Kode, P0 #2)

- `IncomingBufferJsonFile._load()` menangkap error baca atau parse, mencatat, lalu `rows = []` dan `nextId = 1`. Penulisan berikutnya menimpa file lama.
- Penulisan memakai berkas sementara lalu `rename` (baik), tetapi tanpa cadangan dan tanpa `fsync`.
- Usulan: pindahkan file korup ke nama lain alih-alih menimpanya, coba pulihkan dari cadangan.

### E-07 — Upsert tanpa konten (Hipotesis, perlu verifikasi)

- `if (!msg.message) return;` membuang pesan tanpa konten (pesan protokol). Kalau Baileys memancarkan pesan yang gagal didekripsi sebagai stub tanpa `message` (bertipe `CIPHERTEXT`), pesan itu dibuang diam-diam sampai retry berhasil.
- Belum terverifikasi bahwa stub seperti itu dipancarkan. Ini terkait dengan error dekripsi di Ticket 01, tetapi jangan dijadikan kesimpulan.
- Usulan verifikasi: catat `messageStubType` dan ID pada level `info` saat `msg.message` kosong, lalu ulangi burst.

### Informasi

- **E-08:** referensi media tidak lengkap dibuang (`warn`), sesuai `CHAT.md` §6.1. Tidak ada penghitung, jadi kejadiannya tidak terukur.
- **E-10:** idempotensi bekerja. Tidak ada duplikat di seluruh tes pesan masuk, termasuk pengiriman ulang 15 pesan setelah restart.
- **E-11:** event dari socket generasi lama diabaikan (`myGeneration !== this.generation`). Sengaja, untuk mencegah efek ganda. Pesan yang tiba di socket lama pada saat reconnect tidak dievaluasi.
- **E-12:** `status@broadcast` dan tipe tidak didukung dibuang sengaja. Yang perlu diperbaiki hanya kurangnya penghitung per alasan (terkait Ticket 14).
- **E-13:** Baileys mengirim tanda terima sebelum aplikasi menyimpan pesan, sehingga di antara penerimaan dan enqueue pesan hanya ada di memori (`at-most-once`). Keandalan penuh tidak bisa dicapai lewat kode Gateway saja. Yang bisa dilakukan adalah memperpendek jendela itu (E-05, E-06) dan membuat kegagalannya terlihat.

## Usulan urutan pekerjaan (belum diputuskan)

1. **E-01**: proses pesan `append`. Satu-satunya temuan yang terbukti menghilangkan pesan.
2. **E-03 dan E-04**: buat kegagalan enqueue terlihat dan bisa dicoba ulang.
3. **E-05 dan E-06**: perpendek jendela sebelum pesan tersimpan.
4. **E-02 dan E-07**: verifikasi lebih dulu (obrolan pesan sementara, catat stub), baru diputuskan.
5. **E-09**: pemulihan JSON, beririsan dengan Ticket 03 dan 04.

Tiap usulan sebaiknya disertai uji otomatis kecil di `test/` Gateway (pola `simulate-*.js`): `append` menghasilkan baris antrean, insert dengan field wajib kosong menghasilkan error atau log keras, query LID yang menggantung tidak menahan enqueue, dan pesan berbungkus terbaca. Uji tersebut belum ditulis.

## Batasan

- Hanya jalur pesan masuk teks dan media dalam satu proses Gateway yang diaudit. Jalur pengiriman keluar dan pengelolaan sesi tidak termasuk.
- E-02, E-05, dan E-07 belum terlihat di runtime. E-05 juga belum terlihat tersangkut.
- Satu-satunya bukti uji baru hari ini adalah perilaku `INSERT OR IGNORE` pada database memori. Tidak ada tes pada Gateway aktif.
