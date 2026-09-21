# M1 Ticket 01 — Baseline Test: hasil pengukuran (2026-09-21)

> **Catatan pembaca:** dokumen ini append-only, melanjutkan `2026-09-19-tahap-0-baseline.md`. Semua path kode yang disebut (`src/...`, `test/...`) ada di repo **WA-Gateway** (`tikusgot007/WA-Gateway`), bukan di repo AuliaPos. Bagian "Belum selesai" di bawah akan diperbarui lewat entri baru di akhir dokumen, bukan diedit ulang.

Tanggal: 2026-09-21 · Mesin: Aan-PC · Gateway: `5b28eb6` (branch `feature/stage-1-reliability`), Node v20.20.2, Baileys 6.7.24
Nomor Gateway: `6281913500707` · Nomor tes (HP pengirim/penerima): `628563324637`
Database: bukan produksi (AuliaPos lokal berisi data tes). Backup sebelum/sesudah ada di `C:\projects\_backup-m1-ticket01\`.

Ticket 01 murni pengukuran: tidak ada kode yang diubah. Perbaikan mulai dari ticket 02.

## Ringkasan

| Baseline | Hasil | Status |
|---|---|---|
| 1. Enqueue normal | Otomatis (mock CI4): 50/50 `completed` dalam 1 siklus. Versi asli (pesan WhatsApp nyata vs AuliaPos): lihat bagian "Belum selesai" | Sebagian |
| 2. Restart saat burst | Pesan hilang: 3/14 (percobaan 2) dan 3/15 (percobaan 3). Penyebab teridentifikasi dari kode + log, bukan dekripsi | Selesai (percobaan 1 tidak bisa dinilai) |
| 3. Duplicate-on-timeout | Retry `/send` setelah client putus: duplikat 2 dari 3 percobaan | Selesai |
| 4. Retry/backoff | Pulih tanpa kehilangan (mock CI4). Tidak ada batas percobaan / dead-letter (dari kode) | Selesai, dengan catatan |

## Baseline 2 — Restart Gateway saat burst pesan masuk

Metode: `kill-at.js` mematikan proses Gateway (`taskkill /F`, setara SIGKILL) tepat saat baris ke-K masuk `incoming_queue`; PM2 menyalakan ulang otomatis. Burst dikirim manual dari HP tes.

| Percobaan | K | Terkirim | Tercatat di antrean & AuliaPos | Hilang | Reconnect setelah kill |
|---|---|---|---|---|---|
| 1 | 2 | tidak dihitung | 3 stiker | tidak bisa dinilai | 3,6 s |
| 2 | 6 | 14 | 11 | 3 (B, Ha, D — berurutan tepat setelah titik kill) | 2,5 s |
| 3 | 10 | 15 | 12 | 3 (posisi 11–13 — berurutan tepat setelah titik kill) | 2,5 s |

Pencocokan dilakukan dengan tangkapan layar obrolan HP tes vs tabel `messages` AuliaPos. Semua pesan yang hilang tampil dua centang di HP pengirim.

Pesan yang sudah masuk `incoming_queue` sebelum kill selalu terkirim ke AuliaPos setelah restart (`attempts=0`, tanpa duplikat). Pesan yang tiba real-time sesudah `connected` juga aman. Yang hilang adalah pesan yang tiba **saat Gateway mati**.

### Penyebab (verifikasi kode + log debug pada percobaan 3)

1. Saat reconnect, WhatsApp mengirim ulang pesan tertunda dan menandainya offline. Baileys 6.7.24 `lib/Socket/messages-recv.js:699`: `upsertMessage(msg, node.attrs.offline ? 'append' : 'notify')`.
2. Gateway `src/whatsapp/connectionManager.js:385` (`_onMessagesUpsert`): `if (type !== 'notify') return;` — semua event `append` dibuang tanpa log.
3. Baileys sudah mengirim tanda terima (`sending receipt for messages`, type `inactive`) untuk pesan itu, sehingga WhatsApp menganggapnya terkirim dan tidak mengirim ulang.
4. Bukti log percobaan 3: 3 ID pesan (`A572E52D…`, `A5490942…`, `A54AE9EE…`) di-ack pada 06:33:49,5–49,7 UTC, tetapi tidak ada baris `pesan masuk diterima`, tidak ada di `incoming_queue`, tidak ada di AuliaPos. Pesan berikutnya (`A59AC2DD…`) masuk normal.
5. Tidak ada `Bad MAC`, `MessageCounterError`, atau `Failed to decrypt` di log level debug pada jendela kill.

Batasan yang harus tetap dicatat:
- Tipe `append` pada tiga ID itu **belum diamati langsung di runtime**. Yang terverifikasi: kode di kedua sisi dan urutan kejadian di log.
- Log Baileys mencatat `handled 2 offline messages/notifications` (percobaan 2 dan 3), sedangkan yang hilang 3 pesan; selisih satu belum terjelaskan.
- Penyebabnya bukan `SIGKILL` itu sendiri: pesan apa pun yang tiba saat Gateway offline (restart biasa, crash, listrik mati) akan bernasib sama.

### Status hipotesis kasus `AC0B72AD…` (Tahap 0)

Hipotesis lama: kegagalan dekripsi terjadi tepat setelah restart Gateway.
**Dibantah untuk mekanismenya** (tidak ada bukti dekripsi gagal di log debug), **dikonfirmasi untuk polanya** (pesan hilang terkait restart). Pengganti yang lebih mungkin: pesan offline dibuang oleh filter `type !== 'notify'`. Belum diuji ulang terhadap kasus `AC0B72AD…` itu sendiri.

## Baseline 3 — Duplicate-on-timeout pada `POST /send`

Metode: `POST /send` (`chat_id`=nomor tes, token dari `.env`), lalu kirim ulang teks identik sebagai simulasi retry CI4.

| Percobaan | Gangguan | Kiriman #1 | Kiriman #2 | Muncul di HP tes |
|---|---|---|---|---|
| T1 | client diputus 30 ms setelah kirim | server tetap menyelesaikan kirim (`3EB023CF…`) | sukses (`3EB0991A…`) | **2×** |
| T2 | Gateway dimatikan paksa 30 ms setelah kirim | tidak sempat keluar (`ECONNRESET`, log hanya `[SEND] mengirim`) | sukses (`3EB064612A…`) | **1×** (kiriman #1 hilang, tidak duplikat) |
| T3 | client diputus 5 ms setelah kirim | server tetap menyelesaikan kirim (`3EB0545B…`) | sukses (`3EB056345F…`) | **2×** |

- Server tidak membatalkan proses kirim saat client memutus koneksi, dan `/send` tidak menerima idempotency key (dikonfirmasi dari `src/api/ci4Routes.js`).
- T2 bergantung pada timing kill. Kill yang jatuh setelah pesan keluar dan sebelum response akan menghasilkan duplikat; dengan 3 percobaan dan satu titik timing, frekuensinya tidak bisa disimpulkan.
- Tidak diuji: sisi UI AuliaPos (status menggantung/error saat Gateway mati) — Gateway dipanggil langsung.
- Catatan pengukuran: pencarian teks pesan keluar dari `/send` di `incoming_queue` selalu 0 baris, jadi hitungan otomatis lewat antrean tidak valid untuk pesan keluar dan tidak dipakai. Penyebabnya belum diselidiki.

## Baseline 4 — Retry/backoff

- Otomatis (`test/simulate-reliability-baseline.js --scenario=retry`, mock CI4): saat CI4 membalas 503, 3 pesan tetap pending dan dijadwalkan ulang 3000 ms; setelah CI4 pulih semuanya `completed`, tidak ada yang hilang.
- Dari kode (`incomingBuffer.js` `markFailedAttempt`, `config/index.js`): delay `min(3000 × 2^attempts, 120000)` ms. **Tidak ada `maxAttempts` maupun dead-letter** — pesan yang gagal dicoba tiap 120 s tanpa batas. Belum diamati berjalan lama di runtime.
- Skrip tidak memverifikasi rumus backoff lewat `next_attempt_at`; hanya penjadwalan pertama (3000 ms) yang terlihat di log.

## Belum selesai

- Baseline 1 versi asli: 30–50 pesan nyata (incoming dan fromMe) dibandingkan dengan `incoming_queue` dan `messages` AuliaPos.
- Skenario 2 percobaan 1 diulang dengan pesan berhuruf unik dan hitungan kirim yang dicatat.
- Perilaku UI Inbox AuliaPos saat Gateway mati di tengah kirim.

## Masukan untuk ticket berikutnya (tanpa diperbaiki di sini)

- **Ticket 02 (audit enqueue)**: temuan `type !== 'notify'` adalah kandidat perbaikan langsung untuk risiko P0 #1 (pesan masuk hilang).
- **Ticket 06–07 (attempt counter, dead-letter)**: konfirmasi dari kode bahwa retry tak terbatas.
- **Ticket 09–10 (operation ID, idempotency)**: konfirmasi runtime bahwa retry `/send` menduplikasi pesan.

## Pembaruan (21 Sep, sore) — Baseline 1 versi asli dan temuan dekripsi

Gateway tidak dimatikan dalam pengukuran ini. Tiga burst masing-masing 15 pesan diketik manual, dengan label unik, dan dicocokkan dengan `incoming_queue` Gateway dan `messages` AuliaPos.

| Burst | Arah / pengirim | Terkirim | Di Gateway | Di AuliaPos | Hilang | Duplikat | Sebaran waktu tiba |
|---|---|---|---|---|---|---|---|
| I01–I15 | incoming, dari WhatsApp Web (perangkat tertaut `:4`) | 15 | 15 | 15 | 0 | 0 | 38 s |
| J01–J15 | incoming, dari HP tes itu sendiri | 15 | 15 | 15 | 0 | 0 | 28 s |
| F01–F15 | fromMe, diketik di HP Gateway | 15 | 15 | 15 (`outgoing`, `sent_by_user_id` kosong) | 0 | 0 | 58 s |

Seluruh baris `completed` dengan `attempts=0`. Balasan dari HP diteruskan sebagai `outgoing` tanpa identitas staff, sesuai `CHAT.md` Section 7.

### Temuan: dekripsi gagal lalu retry, urutan dan timestamp bergeser

- Ketiga burst menghasilkan `SessionError: No matching sessions found for message` (`failed to decrypt message`): 13 di burst I, 7 di burst J, 20 di burst F (untuk 12 pesan berbeda, `fromMe=true`), total 40. Baileys me-retry, sehingga pesan tiba terlambat dan tidak berurutan (contoh urutan tiba burst I: `I01 I11 I12 I13 I14 I04 I15 I05 I06 I07 I02 I08 I09 I10 I03`).
- `message_timestamp` yang tercatat mengikuti waktu tiba setelah retry, bukan waktu kirim (contoh: `I03` tercatat 14:29:29 WIB padahal dikirim di awal burst; `F06` lebih awal daripada `F05`). Inbox mengurutkan berdasarkan `message_timestamp` ascending (`MessageModel.php:94`), jadi urutan di layar setia pada data. Ini bukan bug tampilan, dan waktu kirim asli tidak tersimpan.
- Status Gateway tetap `connected` selama semua burst. Health saat ini tidak mencerminkan kegagalan dekripsi sesaat itu.
- Sebelum 07:28 UTC, seluruh log hari itu (sejak 05:09 UTC, termasuk tiga burst dan empat kill pada skenario 2) tidak memuat satu pun error dekripsi seperti ini.
- Error muncul dari kedua perangkat pengirim (`:4` dan perangkat utama), jadi WhatsApp Web bukan penjelasan tunggal.

### Konfound yang harus dicatat

- Backup "sebelum" tes (13:24 WIB) sudah berisi sesi `session-149701252890753.{0,4,8,9,10}` (alamat `@lid`).
- File sesi baru `session-628563324637.{0,4,8,9,10}` (alamat nomor telepon) bertanggal 14:02:27 WIB, tepat saat skenario 3 mengirim `/send` ke `628563324637@s.whatsapp.net`.
- **Dugaan (belum terbukti):** kiriman ke alamat nomor telepon memecah sesi enkripsi kontak itu ke dua ruang alamat, sehingga pesan masuk kadang tidak menemukan sesi yang cocok.
- Kalau benar, ini efek samping tes, dan Baseline 1 belum mewakili kondisi sehat murni.
- Di produksi AuliaPos membalas memakai `chat_id` percakapan, sehingga campuran alamat seperti ini mungkin tidak terjadi. Belum diverifikasi.
- Pembuktiannya butuh uji terkontrol pada sesi yang bersih (belum dilakukan, menyentuh folder `auth/` Gateway aktif).

### Keterbatasan Baileys yang sudah diamati (ringkas)

- **Terbukti hari ini:** pesan offline bertipe `append` dibuang (skenario 2); dekripsi bisa gagal lalu di-retry sehingga urutan dan timestamp bergeser; `/send` tanpa idempotency key.
- **Dari kode/log, belum diuji berjalan lama:**
  - retry pesan masuk tanpa batas dan tanpa dead-letter
  - kontak `@lid` tidak bisa dipetakan balik ke nomor telepon (`phone: null`)
  - riwayat percakapan tidak ikut (`History sync is disabled by config`)
  - fallback JSON saat `better-sqlite3` tidak bisa dimuat
  - satu sesi WhatsApp hanya untuk satu proses
  - Node 24 tidak didukung `better-sqlite3` (dikunci ke Node 20)
- **Pengetahuan umum, belum diverifikasi di sesi ini:** API tidak resmi sehingga ada risiko pembatasan nomor; rentan terhadap perubahan protokol WhatsApp; nomor Gateway berstatus perangkat tertaut yang bergantung pada HP utama.

### Requirement Gateway untuk AuliaPos

Daftar GW-01 sampai GW-23 (kontrak antarmuka, perilaku bisnis, operasional, roadmap) disusun dari `CHAT.md`, `InboxGatewayApi.php`, `Inbox.php`, dan spec M3, lalu dicocokkan dengan tes ini. Belum disimpan sebagai berkas di repo.

- Belum terpenuhi: GW-08 (pesan masuk tidak boleh hilang), GW-09 (idempotency `/send`), GW-19 (dead-letter), GW-20 (health).
- Diragukan: GW-11 (timestamp = waktu asli).
- Terpenuhi berdasarkan burst F: GW-10 (fromMe sebagai `outgoing`).

### Belum selesai (menggantikan daftar sebelumnya)

- Uji terkontrol dugaan konfound sesi (sesi bersih untuk kontak tes, lalu ulang burst kecil).
- Skenario 2 percobaan 1 diulang dengan pesan berhuruf unik dan hitungan kirim yang dicatat.
- Perilaku UI Inbox AuliaPos saat Gateway mati di tengah kirim.
- Tes reboot sungguhan untuk auto-start PM2.

## Koreksi (21 Sep, malam) — dugaan konfound sesi melemah

Setelah memeriksa folder `auth/` sebelum uji terkontrol, dugaan "sesi enkripsi tercemar oleh `/send` ke alamat nomor telepon" (bagian Konfound di atas) **tidak lagi didukung**:

- Pesan fromMe (burst F, 20 error) dienkripsi oleh perangkat utama akun Gateway sendiri.
  - Sesi yang dipakai adalah `session-6281913500707.0` dan `session-255490491736112.0` (nomor dan LID milik akun sendiri), ditulis ulang pukul 16:10 WIB.
  - Keduanya **sudah ada di backup sebelum semua tes**.
  - Sesi `session-628563324637.*` (dibuat 14:02) hanya menyangkut kontak tes dan tidak dipakai untuk pesan fromMe.
- Sesi LID kontak tes (`session-149701252890753.{0,4,8,9,10}`) juga sudah ada di backup sebelum tes, jadi burst I dan J tidak bisa dijelaskan oleh sesi baru itu.
- Uji yang direncanakan (hapus `session-628563324637.*`, lalu ulang burst) tidak dijalankan karena tidak menguji hipotesis yang relevan. Isi folder `auth/` tidak diubah. Hanya backup yang dibuat.

**Hipotesis alternatif (belum terbukti):**

- Skenario 3 T2 mematikan Gateway secara paksa 30 ms setelah kiriman keluar dimulai (07:02:07 UTC).
- Itu satu-satunya kill yang jatuh saat proses sedang mengenkripsi pesan keluar. Kill sebelumnya jatuh saat menerima pesan dan tidak diikuti error.
- Kalau state sesi di disk tertinggal dari state yang diyakini perangkat pengirim, pesan berikutnya dari kedua arah bisa gagal didekripsi sampai sesi disinkronkan lewat retry.
- Error pertama muncul di burst pertama sesudah kill itu (07:28 UTC).

Hipotesis ini cocok dengan urutan waktu, tetapi baru satu titik data dan tidak pernah direproduksi. Penyebab error dekripsi tetap **belum diketahui**. Catatan sebelumnya soal "konfound sesi" jangan dipakai sebagai kesimpulan.

## Keputusan (21 Sep, malam) — item yang dicoret

- Tes reboot sungguhan untuk auto-start PM2 dicoret oleh pemilik proyek: di luar scope pengembangan. Daftar "Belum selesai" di atas tidak lagi memuat item ini.
- Catatan koreksi: percakapan tes di AuliaPos memiliki `chat_id` `628563324637@s.whatsapp.net` (alamat nomor telepon), jadi balasan dari AuliaPos ke kontak ini memang memakai alamat itu. Kalimat di bagian Konfound bahwa campuran alamat "mungkin tidak terjadi di produksi" tidak benar untuk kontak ini.
- Skenario 2 percobaan 1 tidak diulang, dicoret oleh pemilik proyek: pola sudah terlihat di percobaan 2 dan 3. Item "Belum selesai" yang tersisa: penyebab error dekripsi dan uji UI Inbox saat Gateway mati di tengah kirim.

## Uji 2b (21 Sep, malam) — Inbox AuliaPos saat Gateway bermasalah di tengah kirim

Diuji lewat tombol Kirim di Inbox (percakapan tes `conversation_id=1`, akun `aan`). Gateway dimatikan atau dijeda tepat setelah log `[SEND] mengirim pesan keluar`, tanpa mengubah kode. Batas timeout AuliaPos ke Gateway: 10 detik (`callGatewaySend`).

| Percobaan | Gangguan | Yang tampil di layar | Server | HP tes |
|---|---|---|---|---|
| U01 | Gateway dimatikan paksa 140 ms setelah kirim dimulai (sebelum pesan keluar) | Kotak merah "Gagal mengirim pesan: Tidak bisa menghubungi Gateway: Recv failure: Connection was reset". Teks `U01` tetap di kotak balasan | Tidak ada baris tersimpan. Retry menyimpan 1 baris (id 88) | `U01` 1× (dari retry) |
| U02 | Gateway dimatikan 380 ms setelah kirim dimulai | Kirim sukses normal (tidak dilaporkan gagal) | Kiriman selesai dalam 374 ms sebelum kill, tersimpan (id 89) | 1× |
| U03 | Gateway dijeda 12 detik setelah kirim dimulai (simulasi Gateway lambat) | Kotak merah "Gagal mengirim pesan: Tidak bisa menghubungi Gateway: Operation timed out after 10009 milliseconds with 0 bytes received" | Timeout 16:40:08, tidak tersimpan. Gateway lanjut dan menyelesaikan kirim (`3EB05701…`, +12 s). Retry 16:40:39 mengirim lagi (`3EB034851…`), tersimpan (id 90) | **`U03` 2×** |

### Temuan

- Pesan ganda ke pelanggan terjadi lewat alur Inbox yang sebenarnya, bukan hanya lewat API (mengonfirmasi GW-09). Pemicunya Gateway lambat lebih dari 10 detik, bukan Gateway mati.
- Kill tidak bisa dipakai untuk mengenai jendela berisiko: jarak antara `[SEND] pesan berhasil dikirim` dan `[SEND-CI4] … berhasil dikirim` sekitar 1 ms. Karena itu penjedaan dipakai sebagai gantinya.
- Riwayat Inbox tidak sama dengan yang diterima pelanggan: pelanggan menerima 2 pesan `U03`, sedangkan Inbox hanya mencatat 1. Kiriman pertama yang terlambat tidak pernah masuk ke Inbox, jadi kasir tidak bisa melihatnya.
- Teks error "Tidak bisa menghubungi Gateway" menyesatkan pada kasus timeout, karena pesan bisa saja sudah terkirim. Kasir tidak mendapat petunjuk untuk memeriksa dulu sebelum mengirim ulang. Ini temuan tampilan, belum diputuskan sebagai perbaikan.
- Perilaku sesuai `CHAT.md` §5 (tidak ada baris tersimpan saat gagal, retry manual oleh kasir). Masalahnya ada di aturan itu sendiri: "gagal" pada timeout berarti tidak pasti, bukan pasti tidak terkirim.

### Batasan

- Satu percobaan per mode. Penjedaan proses mensimulasikan Gateway lambat, bukan gangguan jaringan atau WhatsApp yang sesungguhnya.
- Isi kotak balasan sesudah error pada U03 tidak dilaporkan.
- Tidak ada perbaikan yang dikerjakan atau diputuskan di sini. Kandidatnya untuk M1 Ticket 09–10 (operation ID/idempotency) dan penyesuaian aturan/teks di AuliaPos.

### Belum selesai (pembaruan)

- Penyelidikan penyebab error dekripsi.
- Requirement GW-01 sampai GW-23 belum menjadi berkas di repo.

## Analisis lanjutan penyebab error dekripsi (21 Sep, malam)

Hanya bukti pasif dari log Gateway, `incoming_queue`, dan folder `auth/` yang dipakai. Tidak ada sesi yang diubah dan tidak ada tes tambahan pada Gateway. Total error `failed to decrypt message` hari itu: 55.

### 1. Sebagian error adalah pengiriman ulang pesan yang sudah diproses (benign)

- 15 error terjadi pada 09:31:01–03 UTC, tepat setelah Gateway restart (kill uji U01). Semuanya `fromMe`, dan 15 ID-nya persis sama dengan pesan F01–F15 yang sudah tercatat di antrean.
- WhatsApp mengirim ulang pesan itu setelah restart. Kunci pesannya sudah terpakai, jadi dekripsi gagal. Tidak ada duplikat karena pesan tidak diproses ulang (15/15 unik di AuliaPos).
- Kesimpulan: pengiriman ulang pesan yang sudah diproses harus ditoleransi tanpa duplikat, dan ini sudah terpenuhi.
- Yang tersisa tanpa penjelasan: 40 error pada pengiriman pertama (burst I, J, F).

### 2. Korelasi dengan jenis alamat pesan (`jid_type`)

| Kelompok | Jumlah | Gagal dulu, lalu berhasil lewat retry | Lancar |
|---|---|---|---|
| Sebelum 07:02 UTC (semua alamat `lid`) | 41 | 0 | 41 |
| Sesudah 07:02, alamat `lid` (burst I, J, F) | 35 | 17 | 18 |
| Sesudah 07:02, alamat `pn` (nomor telepon) (burst J, F) | 10 | **10** | 0 |

- Sebelum 07:02 UTC tidak ada satu pun pesan beralamat `pn` dan tidak ada error. Pesan beralamat `pn` baru muncul sesudahnya, dan **10 dari 10 gagal dulu**.
- Pesan beralamat `lid` pun gagal jauh lebih sering sesudah 07:02 (17 dari 35) daripada sebelumnya (0 dari 41).
- Log AuliaPos untuk burst F menampilkan `chat_id` yang berganti antara `628563324637@s.whatsapp.net` dan `149701252890753@lid`.
- Folder `auth/`: file `session-628563324637.*` (alamat `pn`) tidak pernah ditulis ulang sesudah pembuatannya (14:02:27 WIB). File `session-149701252890753.4` dan `.0` (alamat `lid`) ditulis ulang tepat di akhir burst I dan J (14:29 dan 14:44 WIB), yang cocok dengan sesi yang dibentuk ulang lewat retry.

### 3. Hipotesis (belum terbukti)

Dua kejadian jatuh pada waktu yang sama, sekitar 07:02 UTC:

- **H1 (alamat campuran, kini paling didukung):** kiriman `/send` ke `628563324637@s.whatsapp.net` (07:02:27) membuat sesi beralamat nomor telepon. Sesudahnya kontak itu mulai mengirim pesan dengan alamat campuran `pn` dan `lid`, dan Gateway sering tidak menemukan sesi yang cocok sampai retry membentuk ulang sesi.
- **H2 (kill saat mengenkripsi):** kill paksa skenario 3 T2 (07:02:07) saat Gateway sedang mengirim.

H1 tidak dibantah oleh temuan pasif ini, dan korelasi `pn` (10 dari 10) tidak bisa dijelaskan oleh H2 saja. Namun keduanya tetap tidak terpisahkan karena terjadi hampir bersamaan.

### 4. Koreksi atas bagian "Koreksi" sebelumnya

Bagian "Koreksi (21 Sep, malam)" menyatakan dugaan sesi tercemar oleh `/send` ke alamat nomor telepon **tidak lagi didukung**. Pencabutan itu terlalu kuat. Yang benar: alasan pencabutan (sesi akun sendiri untuk pesan fromMe sudah ada sejak awal) masih berlaku, tetapi bukti korelasi di atas membuat H1 kembali menjadi hipotesis utama, dengan status **belum terbukti**.

### 5. Relevansi ke produksi (belum diuji)

AuliaPos memperbarui `chat_id` percakapan ke alamat terbaru (`CHAT.md` §9.3), jadi balasan ke kontak yang pernah mengirim pesan beralamat `pn` selalu memakai nomor telepon. Kalau H1 benar, gejala ini bisa muncul pada tiap kontak setelah balasan pertama. Hal ini belum diuji pada kontak baru.

### 6. Batasan dan status

- Jumlah data kecil (satu kontak, tiga burst) dan bersifat korelasi.
- Uji pembeda (membuktikan H1 atau H2) membutuhkan nomor uji kedua yang belum pernah dihubungi, atau mengubah sesi Gateway aktif. Keduanya tidak dilakukan.
- Status: penyelidikan pasif selesai, penyebab **belum terbukti**. Uji pembeda tercatat sebagai kandidat untuk M1 Ticket 05 (crash/restart test), bukan bagian Ticket 01.
