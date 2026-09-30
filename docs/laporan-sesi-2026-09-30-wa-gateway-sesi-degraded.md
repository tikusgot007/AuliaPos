# Laporan Sesi — Insiden WA Gateway "Connected" tapi Diam-diam Gagal Memproses Pesan

- **Tanggal sesi**: 2026-09-30 (WIB), menelusuri insiden sejak 2026-09-29 malam
- **Cakupan**: WA Gateway (`\\aulia3\D\WA-Gateway`) + AuliaPos CI4 (`W:\htdocs\aulia`, repo `tikusgot007/AuliaPos` branch `v2.4`)
- **Status akhir**: pencegahan sudah diimplementasikan & di-deploy; sesi WhatsApp masih menunggu scan QR ulang manual
- **Dokumen ini untuk**: handover ke sesi/agen lain yang melanjutkan pekerjaan

---

## 1. Ringkasan Eksekutif

Kasir melaporkan pesan WhatsApp berhenti masuk ke Inbox POS sejak **29 Sep 2026 ~19:06 WIB**, padahal menurutnya komputer gateway masih menyala dan pesan tetap masuk ke WhatsApp di HP. Pagi berikutnya (30 Sep) pesan yang masuk di HP juga tidak tertangkap Gateway.

Diagnosa awal sempat salah (dugaan "komputer mati"). Setelah analisis log dan investigasi HP, akar masalah sebenarnya:

1. **Aplikasi WhatsApp Business di HP nomor toko crash-loop** (`java.lang.StackOverflowError` di parser JSON bawaan Android) sejak 29 Sep 19:50 WIB.
2. Crash berulang di device utama menyebabkan **sesi Signal Protocol milik Gateway (linked device) desync** → Gateway gagal mendekripsi **semua** pesan, walau status socket tetap terlihat `connected`.
3. Gateway lama **tidak punya deteksi** untuk kondisi "connected tapi nol pesan berhasil diproses", sehingga insiden berjalan ~12 jam tanpa peringatan.

Hipotesis "Gateway custom penyebab kerusakan WhatsApp di HP" **tidak didukung bukti kode** (lihat §6).

Penyelesaian:
- **Jangka pendek**: hentikan Gateway di `aulia3`; sesi perlu di-reset (scan QR ulang) setelah WA Business di HP stabil.
- **Jangka panjang (sudah dibuat)**: deteksi otomatis status `degraded`, notifikasi WA ke admin, dan badge peringatan di Inbox POS.

---

## 2. Kronologi (waktu lokal WIB)

| Waktu | Kejadian | Bukti |
|---|---|---|
| 29 Sep 02:49 | WhatsApp Business di HP ter-update ke `2.26.38.73` (versionCode 263807314) | `dumpsys package com.whatsapp.w4b` |
| 29 Sep 19:06:13 | **Pesan terakhir yang berhasil** diproses Gateway & diteruskan ke CI4 | log `[DELIVERY] pesan masuk berhasil diteruskan ke CI4` |
| 29 Sep 19:50:22 | **Crash pertama** WA Business di HP | dropbox `data_app_crash` |
| 29 Sep 19:50–20:55 | Crash berulang 9× (termasuk 4× dalam ~40 detik) | dropbox (9 record) |
| 29 Sep 20:49:31 | Log terakhir proses Gateway lama (pid `7872`) — proses masih hidup, tapi **nol** pesan berhasil sejak 19:06 | log gateway |
| 29 Sep 20:49 → 30 Sep 07:51 | Komputer `aulia3` benar-benar mati (log kosong total) | `LastBootUpTime = 30 Sep 07:51:36` |
| 30 Sep 07:53 | Gateway otomatis start (pid `8172`), langsung minta QR / gagal login | log gateway |
| 30 Sep 04:52–09:05 | WA Business **masih** crash berulang (>20× lebih) | dropbox |
| 30 Sep ~10:03 | Sesi Gateway tidak login lagi (`not logged in, attempting registration...`) | log gateway |
| 30 Sep 10:05 | Restart Gateway dengan kode pencegahan baru (pid `13572`) | log gateway |
| 30 Sep ~10:08 | Gateway **dihentikan** atas permintaan; Scheduled Task tetap aktif (hidup lagi saat boot) | verifikasi proses |

> Catatan: beberapa jam pertama insiden (19:06–20:49) proses Gateway **masih hidup** — jadi ini bukan sekadar "komputer mati".

---

## 3. Gejala & Kesalahan Diagnosa Awal

**Gejala yang dilaporkan user:**
- Pesan terakhir masuk POS = **19:06**.
- Setelah itu komputer gateway masih menyala dan pesan tetap masuk ke WhatsApp (dicek di HP).
- Pagi ini pesan masuk di HP lagi, tapi Gateway tidak menangkap.

**Diagnosa awal (SALAH):** "Komputer `aulia3` mati/restart tak terduga semalam sehingga Gateway ikut mati." Ini berasal dari pengamatan bahwa log kosong 20:49→07:51. Koreksinya: kekosongan itu **memang** komputer mati, tetapi **penyebab utama hilangnya pesan sudah terjadi lebih dulu** (sejak 19:06) saat proses masih hidup.

**Diagnosa benar:** sesi Signal Protocol corrupt + app WA di HP crash-loop.

---

## 4. Data Diagnostik Kunci

### 4.1 Log WA Gateway (`\\aulia3\D\WA-Gateway\logs\gateway.log`)

- **2125** kegagalan dekripsi vs **364** keberhasilan sepanjang log.
  - `SessionError: No matching sessions found for message` — **2045×**
  - `MessageCounterError: Key used already or never filled` — **38×**
- Setelah restart pagi (pid `8172`): **48 gagal dekripsi, 0 pesan berhasil** → restart **tidak** memperbaiki.
- Pola khas: ratusan error beruntun pada JID `...@lid` dengan `fromMe:true` (sinkronisasi antar-device) **dan** pesan masuk asli.
- Heartbeat ke CI4 sempat gagal (`fetch failed`, lalu `500 ... target machine actively refused it` ke MySQL `192.168.1.10:3306`) pada jendela 00:57–01:00 UTC (07:57–08:00 WIB) — server POS baru boot / service belum siap.

### 4.2 Crash WhatsApp Business di HP

- Device: **Infinix X6728** (`146824057X003855`), Android 15.
- Paket: `com.whatsapp.w4b` v`2.26.38.73`.
- **31 record `data_app_crash`** (29 Sep 19:50 → 30 Sep 09:05).
- Stack trace **identik** di semua kejadian:

```
java.lang.StackOverflowError: stack size 1037KB
    at org.json.JSONTokener.readArray(JSONTokener.java:431)
    at org.json.JSONTokener.nextValue(JSONTokener.java:107)
    ... (berulang ratusan kali)
    at org.json.JSONObject.<init>(JSONObject.java:168)
    at X.3gy.A1F(:2)
    at X.60Z.run(:2059)
```

**Interpretasi**: WhatsApp Business mem-parsing struktur JSON lokal (cache/database internal app) yang bersarang terlalu dalam → rekursi parser tak berhenti → stack penuh → app crash. Parser `org.json` adalah **bawaan Android**, dieksekusi **di dalam app**, bukan dari jaringan/payload Gateway.

### 4.3 Kendala pengambilan data
- Tidak bisa `adb pull /data/data/com.whatsapp.w4b` (permission denied, non-root) → tidak dapat memastikan file mana yang korup.
- Remote ke `aulia3` via WinRM: awalnya gagal (TrustedHosts belum diset, WinRM belum aktif). Diperbaiki dengan `Enable-PSRemoting` + `Set-Item WSMan:\localhost\Client\TrustedHosts -Value aulia3` (butuh elevasi UAC). User: `ops`, kredensial lokal.

---

## 5. Analisis Kausal (rantai sebab-akibat)

1. WA Business di HP kena bug internal (kemungkinan dari update) → crash-loop saat parsing data JSON lokal.
2. Crash+restart berulang device utama mengganggu **sesi Multi-Device**; kunci Signal Protocol milik Gateway (linked device) menjadi **desync**.
3. Gateway mulai gagal dekripsi **semua** pesan; socket WhatsApp tetap `connected` sehingga tidak ada indikator error di UI.
4. Gateway lama tidak punya deteksi "connected tapi nol pesan diproses" → insiden senyap ~12 jam.
5. Restart proses Gateway **tidak** menolong (kerusakan ada di kredensial sesi tersimpan di `auth/`, bukan state memory).
6. Komputer `aulia3` lalu mati (sebab terpisah), menambah ~11 jam hilang.

**Penyelesaian wajib**: reset sesi Gateway (**Logout + scan QR ulang**) setelah WA Business di HP stabil — restart proses saja tidak cukup.

---

## 6. Apakah Gateway Custom Penyebab Kerusakan WhatsApp di HP? — TIDAK

Investigasi kode (Gateway + CI4) menemukan **3 lapis pembatas independen** yang justru mencegah masalah tipe ini:

| Lapis | Bukti |
|---|---|
| CI4 | `Inbox::quotedPayloadGateway()` hanya mengirim field flat (`wa_message_id`, `text`, `message_type`, `sender_jid`, `fromMe`) dari kolom DB — bukan blob JSON mentah WhatsApp |
| Gateway | `buildQuotedMessage()` (`connectionManager.js`) hanya membentuk `{ conversation: <string> }` atau `{ imageMessage: {} }` (objek **kosong**) — tidak pernah menyalin struktur bersarang |
| Baileys | `lib/Utils/messages.js` eksplisit `delete quotedContent.contextInfo` ("strip any redundant properties") saat membangun kutipan |

Kesimpulan:
- Tidak ada jalur di kode yang bisa menghasilkan JSON bersarang dalam.
- Baileys berjalan di **Node.js server**, tidak pernah mengeksekusi kode Java/`org.json` di HP.
- **Fakta kunci**: WhatsApp Web resmi di device lain **aman** → kalau penyebabnya payload jaringan, semua linked device seharusnya terdampak.
- Korelasi waktu (44 menit) kemungkinan besar kebetulan; penyebab paling konsisten: bug app WA Business sendiri / data lokal korup / migrasi `@lid`.

---

## 7. Perubahan yang Diimplementasikan (Pencegahan)

### 7.1 Sisi WA Gateway (`\\aulia3\D\WA-Gateway`)

| File | Perubahan |
|---|---|
| `src/whatsapp/decryptTracker.js` **(BARU)** | Proxy transparan pembungkus logger Baileys; menghitung setiap `SessionError`/`MessageCounterError`/`Bad MAC`/`failed to decrypt` **tanpa mengubah** logging asli. Titik intersep satu-satunya karena Baileys menangkap error dekripsi internal (tidak lewat `unhandledRejection`). |
| `src/whatsapp/connectionManager.js` | State baru `sessionHealth` (`ok`/`degraded`), buffer timestamp kegagalan, `_recordDecryptFailure()`, `_recordMessageProcessed()`, `_sendDegradedAlert()`; logger Baileys di-wrap; reset counter saat socket `open`; panggil `_recordMessageProcessed()` saat pesan sukses; `sessionHealth` masuk `getStatusSnapshot()` |
| `src/delivery/heartbeat.js` | Kirim field `session_health` ke CI4 (opsional, aditif) |
| `src/config/index.js` | `decryptFailureThreshold` (default 20), `decryptFailureWindowMs` (default 600000 = 10 menit), `adminAlertPhone` |
| `.env` | `DECRYPT_FAILURE_THRESHOLD=20`, `DECRYPT_FAILURE_WINDOW_MS=600000`, `ADMIN_ALERT_PHONE=628563324637` |

**Logika deteksi**: jika ≥ threshold kegagalan dekripsi dalam window **DAN** tidak ada satu pun pesan berhasil diproses di window yang sama **DAN** status `connected` → set `sessionHealth='degraded'`, log error tegas, kirim **1× WA notifikasi** ke `ADMIN_ALERT_PHONE` (best-effort, tidak fatal).

**Endpoint** `/api/status` kini mengembalikan `sessionHealth`.

### 7.2 Sisi AuliaPos CI4

| File | Perubahan |
|---|---|
| `app/Database/Migrations/2026-09-30-000001_AddSessionHealthToGatewayStatus.php` **(BARU)** | Tambah kolom `gateway_status.session_health` VARCHAR(20) NOT NULL DEFAULT 'ok' (aditif, kompatibel mundur) |
| `app/Models/GatewayStatusModel.php` | Tambah `session_health` ke `allowedFields` |
| `app/Controllers/InboxGatewayApi.php` | `status()` menerima field opsional `session_health` (fallback 'ok', validasi longgar) |
| `app/Controllers/Inbox.php` | `buildGatewayStatusPayload()` menurunkan `effective_status='degraded'` saat `connected` + `session_health='degraded'` (beda dari `disconnected`) |
| `app/Views/inbox/index.php` | Label status baru `degraded` → badge **merah "Bermasalah, perlu scan ulang"** + CSS `bg-danger` |

**Efek UI**: `gatewayTerhubung` otomatis `false` untuk `degraded` → kasir terblokir kirim pesan saat sesi rusak (perilaku benar).

### 7.3 Deploy & Git

- Commit `f26138b` ("feat(inbox): detect degraded WA session that appears connected but silently fails") **sudah di-push** ke `origin/v2.4`.
- Produksi `W:\htdocs\aulia`: `git pull origin v2.4` → **fast-forward** ke `f26138b` (25 commit masuk).
- Proses Gateway di `aulia3` **di-restart** sekali dengan kode baru (pid `13572`), lalu **dihentikan** atas permintaan user.

---

## 8. Jebakan Penting Saat Deploy (WAJIB DIBACA)

25 commit yang masuk produksi memuat commit lama `0b05849` yang **mengubah `RewriteBase` dari `/aulia/` menjadi `/aulia-app/`** (path XAMPP lokal dev). Karena produksi dilayani di `/aulia/`, pull mentah-mentah akan **merusak routing produksi**.

**Yang dilakukan:**
1. Setelah pull, `RewriteBase` di `.htaccess` dan `public/.htaccess` (2×) dikembalikan ke `/aulia/` — sebagai **perubahan lokal** produksi, tidak di-commit.
2. Kedua file ditandai `git update-index --skip-worktree .htaccess public/.htaccess` → `git status` produksi bersih, tidak bentrok pada pull berikutnya.

**Konsekuensi yang harus diingat sesi berikutnya:**
- Nilai yang **di-commit di origin = `/aulia-app/`** (untuk dev lokal, htdocs\aulia-app).
- Nilai **aktual di produksi = `/aulia/`** (htdocs\aulia).
- Jika nanti origin mengubah file `.htaccess`, `git pull` di produksi akan **error**. Solusi saat itu: `git update-index --no-skip-worktree .htaccess public/.htaccess` → pull → setel `/aulia/` lagi → `--skip-worktree` lagi.
- Perbaikan permanen yang disarankan (belum dikerjakan): jadikan `RewriteBase` tidak hardcode (atau hapus barisnya bila rules tetap bekerja di kedua path), lalu hapus `skip-worktree`.

---

## 9. Status Infrastruktur Saat Ini

| Item | Status |
|---|---|
| Gateway `aulia3` | **MATI** (dihentikan atas permintaan user) |
| Scheduled Task `WA-Gateway` | **Aktif / Ready** — auto-start via trigger **Boot** (dan Daily 07:45) |
| `node.exe` & port 3000 di aulia3 | Tidak ada / bebas |
| Sesi WhatsApp Gateway | **Belum login** — menunggu scan QR (folder `auth/` perlu sesi baru) |
| AuliaPos produksi (`W:\htdocs\aulia`) | Commit `f26138b`, working tree bersih, situs `/aulia/` → 200 OK |
| DB `aulia_inboxdb.gateway_status` | Kolom `session_health` ada; heartbeat terakhir `connecting` |
| UI Inbox POS | Kode badge `degraded` aktif (menunggu Gateway hidup untuk lihat heartbeat) |

---

## 10. Tindak Lanjut yang Belum Selesai

1. **Perbaiki WA Business di HP** (device utama nomor toko):
   - Setelan Android → Apps → WhatsApp Business → Storage → **Clear Cache** dulu.
   - Kalau masih crash: **Clear Data** (logout WA Business, login ulang nomor yang sama).
   - Cek update di Play Store.
2. **Restart komputer `aulia3`** → Scheduled Task `WA-Gateway` hidup otomatis.
3. **Logout + scan QR ulang** di dashboard Gateway (`http://AULIA3:3000`) setelah HP stabil.
4. **Verifikasi**: kirim WA uji dari nomor lain → pastikan masuk ke Inbox POS; cek `logs/gateway.log` beberapa menit pertama tidak ada `SessionError`/`MessageCounterError` menumpuk.
5. **Perbaikan permanen `RewriteBase`** (lihat §8) agar tidak perlu `skip-worktree` selamanya.
6. **Belum dikerjakan (opsional, dari rekomendasi awal)**: auto-restart proses saat `CRASHED` di `supervisor/processManager.js`; rotasi `logs/gateway.log` (saat ini tanpa rotasi, bisa membengkak tanpa batas).
7. **Pesan yang hilang 29–30 Sep**: WhatsApp/Baileys tidak menjamin reconciliation; pesan selama Gateway offline kemungkinan hilang permanen. Cek riwayat di HP utama untuk pelanggan yang perlu dihubungi ulang.

---

## 11. Catatan & Risiko Lain yang Ditemukan

- **`docs/aturan-bisnis-AULIA.md`, `docs/aturan-bisnis-CHAT.md`, `docs/aturan-bisnis-USER-SHIFT.md`** banyak dirujuk di kode tetapi **tidak ada di repo**. Perlu dicari/diminta ke pemilik sebelum mengubah logic bisnis kritikal.
- **Gateway `HOST=0.0.0.0` tanpa autentikasi** pada endpoint `/api/*` (hanya endpoint CI4 `/send`, `/send-media`, `/media/download` yang pakai Bearer token). Siapa pun di LAN bisa baca pesan/kirim pesan/logout lewat dashboard. Di luar scope sesi ini, tapi berisiko.
- **Kredensial** disimpan di file `.env` (tidak di-commit): `.env` produksi (`W:\htdocs\aulia`), `.env` Gateway (`\\aulia3\D\WA-Gateway`). Jangan salin nilainya ke dokumen/laporan.
- **`W:\htdocs\aulia` = `\\aulia-server2\xampp\htdocs\aulia`** = produksi; **`C:\xampp\htdocs\AuliaPos`** = clone dev lokal (dilayani `/aulia-app/`). DB produksi: `aulia_kasirdb` + `aulia_inboxdb` di `AULIA-SERVER2` (`192.168.1.10`).
- Migration `session_health` tercatat di `aulia_kasirdb.migrations` **batch 9** → `php spark migrate` di produksi = no-op (idempoten).
- `.env` AuliaPos **tidak ada di clone dev** (hanya `.env.example`); untuk menjalankan `spark` lokal harus dibuat `.env` dulu (yang dipakai sesi ini sudah dihapus kembali agar tidak menyimpan secret).
- `composer install` dijalankan di clone dev (membuat `vendor/`, gitignored).

---

## 12. Referensi Cepat

**Path penting**
- Gateway: `\\aulia3\D\WA-Gateway` (logs: `logs\gateway.log`)
- Produksi CI4: `W:\htdocs\aulia`
- Dev CI4: `C:\xampp\htdocs\AuliaPos`

**Endpoint / URL**
- Dashboard Gateway: `http://AULIA3:3000` (aka `http://192.168.1.71:3000`)
- Gateway status API: `GET http://192.168.1.71:3000/api/status`
- POS produksi: `http://192.168.1.10/aulia/`
- Endpoint heartbeat: `POST /api/inbox/gateway/status` (Bearer token)

**Perintah berguna**
- Cek proses/task Gateway (dari mesin lain, butuh WinRM TrustedHosts):
  `Invoke-Command -ComputerName aulia3 -Credential $cred -ScriptBlock { Get-ScheduledTask -TaskName "WA-Gateway" }`
- Mulai/hentikan Gateway: `Start-ScheduledTask` / `Stop-ScheduledTask -TaskName "WA-Gateway"`
- Cek crash HP: `adb shell dumpsys dropbox --print`
- Cek kolom DB: `mysql -h 192.168.1.10 -u <user> -p -e "DESCRIBE aulia_inboxdb.gateway_status;"`

**Logika deteksi degraded (ringkas)**
```
if (status === 'connected'
    && failuresDalamWindow >= DECRYPT_FAILURE_THRESHOLD
    && tidakAdaPesanSuksesDalamWindow) {
  sessionHealth = 'degraded';
  kirim WA 1x ke ADMIN_ALERT_PHONE;
}
```
