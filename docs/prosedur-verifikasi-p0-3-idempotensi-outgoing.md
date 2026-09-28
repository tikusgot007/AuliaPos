# Prosedur Verifikasi P0 #3 — Idempotensi Kirim Keluar (C1)

> [!IMPORTANT]
> Dokumen ini adalah **prosedur yang harus dijalankan**, bukan laporan hasil. Selama belum ada
> catatan hasil di Bagian 7, **P0 #3 tetap OPEN**. Jangan menandainya selesai hanya karena kode
> idempotensinya sudah ada.

## 1. Tujuan

Membuktikan dengan **Gateway sungguhan + WhatsApp sungguhan** bahwa:

1. **Skenario A (AC-027):** saat AuliaPos kehabisan waktu (timeout) lalu kasir mengirim ulang
   **di dalam** masa tunggu (lease 35 detik), pelanggan menerima pesan itu **paling banyak satu kali**.
2. **Skenario B (AC-042):** setelah lease lewat, permintaan ulang pada operasi yang sudah terkirim
   dijawab sebagai pengulangan hasil lama (**tidak** mengirim ulang ke WhatsApp).

Asal: `spec/spec-process-m1-wave2-outgoing-idempotency.md`, AC-027 dan AC-042, serta butir 2 di
Bagian 13 spec yang sama.

## 2. Batas kejujuran (baca sebelum mengklaim apa pun)

- **Yang TIDAK dicakup prosedur ini:** mematikan Gateway secara mendadak pada jendela antara
  "WhatsApp sudah menerima pesan" dan "baris operasi ditandai `sent`". Jendela itu lebarnya sekitar
  1 ms dan duplikat di sana **masih mungkin** (ASSUMPTION-009). Prosedur ini memakai *pembekuan*
  proses, bukan pembunuhan, jadi jendela itu memang tidak diuji.
- **Kesimpulan yang boleh ditulis:** "skenario timeout + kirim ulang manusia tidak lagi
  menghasilkan pesan ganda". **Yang tidak boleh ditulis:** "P0 #3 tertutup sepenuhnya" atau
  "duplikat mustahil".
- Jangan mengarang hasil. Kalau percobaan gagal atau tidak meyakinkan, catat apa adanya.

## 3. Prasyarat

### 3.1 Gateway hidup dan tersambung WhatsApp

Checkout ada di `C:\projects\WA-Gateway` (branch `master`). Saat prosedur ini ditulis, checkout itu
**belum siap jalan**: belum ada `node_modules`, `.env`, `auth/`, maupun `data/`. Urutannya:

```text
cd C:\projects\WA-Gateway
npm install
copy .env.example .env
```

Isi `.env`:

| Kunci | Nilai |
| --- | --- |
| `CI4_BASE_URL` | alamat AuliaPos, mis. `http://localhost/aulia` |
| `CI4_GATEWAY_TOKEN` | **sama persis** dengan `inbox.gatewayToken` di `.env` AuliaPos |
| `SQLITE_PATH` | biarkan bawaan `./data/gateway.sqlite` |

Jalankan, lalu tautkan WhatsApp:

```text
npm start
```

Buka `http://127.0.0.1:3000` di browser, lalu scan QR dari HP (WhatsApp → Perangkat Tertaut) atau
pakai pairing code. **Prasyarat lolos bila dashboard menampilkan status `connected`.**

> [!WARNING]
> Jangan pernah menyentuh folder `auth/` secara manual dan jangan memakai nomor WhatsApp pribadi
> yang penting. Pakai nomor uji toko.

### 3.2 AuliaPos hidup

XAMPP (Apache + MySQL) jalan, Inbox bisa dibuka. `.env` AuliaPos sudah ada.

### 3.3 Nomor uji penerima

HP kedua dengan WhatsApp aktif. **Jangan** memakai nomor pelanggan sungguhan.

### 3.4 Alat bantu

`build/suspend-gateway.ps1` (lokal, gitignored). Alat ini membekukan proses Gateway memakai
`NtSuspendProcess`, dan menolak jalan kalau proses Gateway tidak ditemukan.

## 4. Fakta yang dipakai

| Hal | Nilai | Sumber |
| --- | --- | --- |
| Timeout kirim teks AuliaPos | 10 detik | `app/Controllers/Inbox.php:2655` |
| Timeout kirim media AuliaPos | 30 detik | `app/Controllers/Inbox.php:2758` |
| Lease operasi keluar | 35.000 ms | `OUTGOING_LEASE_MS` (spec §4.6) |
| Batas kiriman per operasi | 5 | `OUTGOING_MAX_ATTEMPTS` (spec §4.6) |
| Endpoint kirim Gateway | `POST /send`, `POST /send-media` | README Gateway §5.1 |
| Berkas operasi Gateway | `data/gateway.sqlite`, tabel `outgoing_operations` | `src/config/index.js:73` |
| Log Gateway | `logs/gateway.log` + panel Event/Log dashboard | `.env` `LOG_FOLDER` |
| Kolom jejak AuliaPos | `messages.gateway_operation_id` | `CON-009` (spec §4.7) |
| AuliaPos menganggap Gateway mati | 30 detik tanpa heartbeat | `app/Config/Inbox.php:44` (`heartbeatStaleSeconds`; heartbeat tiap 15 detik) |
| Baileys menganggap soket WhatsApp mati | ≈35 detik tanpa data server | `baileys/lib/Socket/socket.js:295` (`keepAliveIntervalMs + 5000`) |
| Baileys menyerah menunggu tanda terima kirim | 60 detik | `baileys/lib/Utils/generics.js:131` via `waitForMessage` |

Karena lease (35 detik) sengaja lebih panjang daripada timeout teks AuliaPos (10 detik), kirim ulang
manusia **selalu** jatuh di dalam lease selama dilakukan cepat.

> [!CAUTION]
> **Batas pembekuan proses adalah yang PALING DULU tercapai, bukan 30 detik.** Dua batas berjalan
> bersamaan: AuliaPos menyerah lewat heartbeat (30 detik), tetapi **Baileys memutus soket WhatsApp
> lebih dulu** (≈35 detik tanpa data server). Karena itu, **jaga pembekuan ≤ ~15 detik.**
>
> Pembekuan 26 detik terbukti mematikan soket **tepat saat** kiriman sedang berjalan: kiriman itu
> lalu menggantung 60 detik dan berakhir gagal tanpa pernah sampai ke pelanggan
> (`docs/decisions/2026-09-28-c1-p0-3-outgoing-idempotency-remeasurement.md` §5.3, §6).

## 5. Skenario A — kirim ulang di dalam lease (AC-027, inti C1)

### 5.1 Persiapan

1. Pastikan dashboard Gateway `connected` dan Inbox AuliaPos menampilkan percakapan uji.
2. Buka percakapan ke nomor uji, **ketik teks yang unik** (mis. `VERIF-C1-A1`). Jangan diedit lagi
   sampai percobaan selesai — mengedit teks membuang `operation_id` dan membatalkan percobaan.
3. Buka DevTools browser (F12) → tab **Network**, siap menyaring `kirim`.
4. Siapkan PowerShell kedua untuk alat bantu, dan **posisikan jari di tombol Kirim**.

### 5.2 Langkah

1. Di PowerShell kedua, jalankan:

   ```text
   powershell -ExecutionPolicy Bypass -File build\suspend-gateway.ps1 -Seconds 15
   ```

   Alat ini menghitung mundur 5 detik, lalu mencetak `FREEZING`. **Jangan** memakai nilai di atas
   ~15 detik: lihat peringatan batas pembekuan di §4.
2. **Tepat saat muncul `FREEZING`, tekan Kirim di Inbox.**
3. Tunggu. Sekitar 10 detik kemudian Inbox harus menampilkan **gagal/timeout** (AuliaPos membatalkan
   permintaan), padahal Gateway masih beku.
4. Setelah alat mencetak `RESUMED`, **segera tekan "Kirim ulang"** di Inbox (dalam beberapa detik;
   jauh di bawah sisa lease 35 detik).
5. Amati hasilnya.

> [!TIP]
> **Kalau yang diinginkan cabang `409` (bukan replay):** tekan "Kirim ulang" **selagi Gateway masih
> beku**, lalu minta operator alat mencairkan secepatnya (harus sebelum detik ke-30). Ini menghasilkan
> `409 SEND_IN_PROGRESS` + UI "hasil belum pasti" — tetapi menuntut pembekuan lebih lama, sehingga
> soket WhatsApp **berisiko** diputus dan kirimannya bisa gagal. Itu pernah terjadi dan bukan cacat
> produk: lihat §5.5 untuk pemulihannya.

> [!NOTE]
> **Gerbang keabsahan percobaan.** Percobaan hanya **sah** kalau Inbox benar-benar menampilkan
> keadaan gagal/timeout di langkah 3. Kalau Inbox malah menampilkan **sukses**, berarti pembekuan
> datang terlambat (pesan sudah terkirim dan dijawab sebelum beku) — percobaan itu **tidak sah**,
> ulangi dari langkah 1.

### 5.3 Kriteria lulus

Percobaan dinyatakan **LULUS** hanya bila **semuanya** benar:

| # | Yang diperiksa | Hasil yang diharapkan (LULUS) |
| --- | --- | --- |
| 1 | HP penerima | menerima teks uji **tepat satu kali** (bukan dua) |
| 2 | Log Gateway | hanya **satu** baris kirim sukses untuk operasi itu; permintaan ulang **tidak** memanggil Baileys |
| 3 | Balasan Gateway ke permintaan ulang | `409 SEND_IN_PROGRESS` (masih di dalam lease) **atau** `200` dengan `replayed: true` |
| 4 | UI Inbox | menampilkan keadaan "hasil belum pasti, jangan kirim ulang dulu" untuk jalur `409`; tidak pernah menyarankan kirim ulang buta |
| 5 | `messages` AuliaPos | **tepat satu** baris dengan `gateway_operation_id` itu |

**GAGAL** bila pelanggan menerima dua pesan, atau log menunjukkan `sendMessage` kedua, atau ada
**dua** baris `messages` dengan `gateway_operation_id` yang sama.

### 5.4 Ulangi

Ulangi Skenario A **minimal 3 kali** dengan teks unik berbeda (`VERIF-C1-A2`, `VERIF-C1-A3`),
mengikuti pola pengukuran Ticket 01. Catat 3/3 atau berapa pun hasil apa adanya.

### 5.5 Pemulihan bila kiriman benar-benar hilang

Kalau pembekuan memutus soket WhatsApp **tepat saat** kirim, pesannya tidak sampai dan operasinya
tertinggal `in_flight` dengan `resolved_at` kosong serta peringatan "hasil belum pasti" di UI. Ini
pulih lewat langkah berikut, **tanpa risiko ganda** — asalkan kiriman pertama memang tidak sampai:

1. Periksa WhatsApp penerima dulu. Kalau pesannya **tidak ada**, lanjut.
2. **Tunggu sampai lease lewat** — 35 detik sejak `updated_at` baris operasi itu.
3. Tekan **"Kirim ulang"** di Inbox. UI mempertahankan `operation_id` yang sama, jadi Gateway akan
   menaikkan `attempts` lalu **benar-benar mengirim**.
4. Buktikan hasilnya: `state=sent`, `attempts` naik satu, dan `messages` tetap **satu** baris.

Kalau setelah lease lewat pesannya tetap tidak masuk, jangan mengulang buta — periksa
`C:\projects\WA-Gateway\logs\gateway.log` dan `GET /api/status` lebih dulu.

## 6. Skenario B — kirim ulang setelah lease pada operasi yang sudah terkirim (AC-042)

Di sini antarmuka Inbox **tidak bisa dipakai**, karena setelah kirim sukses AuliaPos sengaja
membuang `operation_id`. Jadi permintaan ulang dilakukan langsung ke Gateway.

1. Kirim satu pesan normal lewat Inbox (tanpa pembekuan) sampai **sukses**.
2. Catat `operation_id` dan `chat_id`-nya, dari salah satu sumber:
   - DevTools → Network → request `kirim` → Request Payload; atau
   - query ke `data/gateway.sqlite` (lihat Bagian 7.3).
3. **Tunggu lebih dari 40 detik** (di atas lease 35 detik).
4. Kirim ulang dengan `operation_id` **sama** dan `text` **sama persis**:

   ```text
   curl -X POST http://127.0.0.1:3000/send ^
     -H "Authorization: Bearer <CI4_GATEWAY_TOKEN>" ^
     -H "Content-Type: application/json" ^
     -d "{\"chat_id\":\"<chat_id>\",\"text\":\"<teks sama>\",\"operation_id\":\"<operation_id>\"}"
   ```

5. **LULUS** bila respons `200` dengan `replayed: true` dan HP penerima **tidak** menerima pesan
   baru. Bila muncul pesan kedua, itu **GAGAL** (dan merupakan temuan serius).

## 7. Bukti yang harus dicatat

Tulis hasilnya sebagai berkas baru di `docs/decisions/` (ikuti pola berkas decision log lain),
berisi tabel per percobaan dan tangkapan layar HP penerima.

### 7.1 Pesan log Gateway yang dicari

Restart Gateway dari terminal, jadi keluarannya terlihat; atau buka `logs/gateway.log`. Yang dicari:
satu baris kirim sukses, dan **tidak ada** baris kirim kedua untuk operasi yang sama.

### 7.2 Query AuliaPos

Simpan sebagai berkas `.sql` lalu jalankan (jangan menempelkan SQL bertanda kutip langsung di
argumen PowerShell):

```sql
SELECT id, conversation_id, direction, send_status, gateway_operation_id, left(text, 40) AS cuplikan
FROM messages
WHERE gateway_operation_id = '<operation_id>';
```

```text
C:\xampp\mysql\bin\mysql.exe -u root --batch --raw aulia_inboxdb -e "source C:\path\absolut\cek-c1.sql"
```

Harus keluar **tepat satu baris**.

### 7.3 Query Gateway

Dari `C:\projects\WA-Gateway`, dengan basis data dibuka **read-only**:

```text
node -e "const D=require('better-sqlite3');const d=new D('./data/gateway.sqlite',{readonly:true});console.table(d.prepare('SELECT operation_id,state,attempts,last_error,updated_at FROM outgoing_operations ORDER BY created_at DESC LIMIT 10').all())"
```

Yang diharapkan untuk operasi uji: `state` = `sent` atau `in_flight`, `attempts` = **1**.

## 8. Kalau gagal atau aneh

| Gejala | Kemungkinan penyebab | Tindakan |
| --- | --- | --- |
| Alat bantu bilang proses Gateway tidak ditemukan | Gateway belum jalan | `npm start` dulu di `C:\projects\WA-Gateway` |
| Inbox tetap menampilkan sukses (tidak timeout) | Pembekuan terlambat | Percobaan tidak sah; ulangi, tekan Kirim lebih tepat saat `FREEZING` |
| Inbox timeout, tetapi pelanggan **tidak** menerima pesan | Permintaan batal sebelum Gateway memprosesnya | Ulangi; catat sebagai percobaan tidak konklusif, bukan lulus |
| Pelanggan menerima **dua** pesan | Dugaan regresi idempotensi | **GAGAL** — simpan log Gateway + isi `outgoing_operations`, lalu laporkan |
| `409 OPERATION_ID_REUSED` saat kirim ulang | Teks diedit antar-percobaan | Ulangi tanpa mengedit kotak teks |

## 9. Status pelaksanaan

### 9.1 Saat dokumen ini pertama disusun (28 Sep 2026)

Gateway **tidak** sedang berjalan (port 3000 kosong; dua proses `node` yang hidup adalah `9router`,
proxy AI, bukan Gateway). Checkout `C:\projects\WA-Gateway` bersih di `master @ a2ba409` tetapi belum
punya `node_modules`, `.env`, `auth/`, maupun `data/`. Node terpasang `v22.23.2` (spec: minimal 20;
Node 24 tidak didukung). Prosedur belum pernah dijalankan.

### 9.2 Hasil pelaksanaan (28 Sep 2026, sesi yang sama)

Prosedur **sudah dijalankan** terhadap Gateway nyata + WhatsApp nyata. Bukti lengkap beserta tabel
per-putaran ada di
`docs/decisions/2026-09-28-c1-p0-3-outgoing-idempotency-remeasurement.md`. Ringkasnya:

| Run | Jalur Gateway | Sampai ke HP? | Baris `messages` |
| --- | --- | --- | --- |
| Baseline | `sent`, attempts 1 | ya, 1× | 1 |
| #1 | replay (`tidak dikirim ulang`) | ya, 1× | 1 |
| #2 | `409 SEND_IN_PROGRESS` | **tidak** (soket putus) | 0 |
| #2b | `lease lewat` → attempts 2 → `sent` | ya, 1× | 1 |

**Tidak ada duplikat di seluruh putaran.**

- **Klaim inti P0 #3 (C1) terverifikasi:** timeout + kirim ulang manusia tidak lagi menggandakan pesan.
- **ASSUMPTION-009 tetap OPEN.** Celah ~1 ms "sudah diterima WhatsApp tetapi belum tercatat" belum
  tertutup; penutup penuhnya GW-21 di M2. Jangan menulis "duplikat mustahil".
- **Satu run belum pernah menghasilkan cabang `409` sekaligus pengiriman sukses.** Diperlukan cara
  memperlambat hanya jalur keluar WhatsApp, yang tidak bisa dilakukan teknik pembekuan proses.
- Koreksi yang sudah masuk ke dokumen ini: batas pembekuan ≤ ~15 detik (§4), timeout ack Baileys
  60 detik (§4), langkah pemulihan pasca-lease (§5.5).
