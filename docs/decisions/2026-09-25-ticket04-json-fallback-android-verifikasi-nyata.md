---
title: Ticket 04 — Verifikasi Nyata Jalur Fallback JSON (IncomingBufferJsonFile) di Build Android
date: 2026-09-25
repo: WA-Gateway (dieksekusi read-only, tidak ada commit kode)
related_plan: plan/plan-process-m1-wave1-incoming-reliability-v1.0.md (TASK-015, REQ-016/017, AC-012)
related_assumption: spec/spec-process-m1-wave2-outgoing-idempotency.md ASSUMPTION-007
---

# Ticket 04 — Verifikasi Nyata Jalur Fallback JSON di Build Android

## 1. Latar Belakang

Sejak M1 Gelombang 1, `IncomingBufferJsonFile` (fallback murni JavaScript untuk `incomingBuffer.js`,
dipakai otomatis ketika `better-sqlite3` tidak tersedia) hanya diverifikasi lewat simulasi
(`test/simulate-json-recovery.js`, 8 skenario, semua di folder temp Node desktop). `docs/TODO-CHAT.md`
butir 04 mencatat ini terbuka: *"JSON recovery — verifikasi di lingkungan yang benar-benar memakai
fallback JSON (mis. build Android)"* belum pernah dilakukan. `spec-process-m1-wave2-outgoing-idempotency.md`
ASSUMPTION-007 mereferensikan gap yang sama untuk `outgoing_operations`.

Sesi ini menutup gap tersebut untuk `incomingBuffer` (Ticket 04), dengan mengeksekusi skenario korupsi
langsung di **build Android nyata yang berjalan di device fisik**, bukan di worktree/scratchpad.

## 2. Lingkungan yang Dipakai

| Item | Nilai |
| --- | --- |
| Working copy | `C:\home\wa-gateway-review`, `master` @ `3e356cd` (fix pairing-code, sudah ✅ closed sesi sebelumnya) |
| Device fisik | Samsung SM-G975F, serial `RR8N201VC9T` (device produksi aktif milik toko, BUKAN emulator/sandbox) |
| Package | `com.auliapos.wagateway`, `versionCode=1`, `lastUpdateTime=2026-09-25 07:34:49` |
| Bukti native addon tidak ada | `run-as com.auliapos.wagateway ls files/nodejs-project/node_modules` → **tidak ada folder `better-sqlite3`** (sesuai `scripts/prepare-android-assets.js`, yang sengaja menghapusnya pasca-`npm install` karena binary x64 tidak kompatibel arm64/armeabi-v7a) |
| Bukti fallback aktif | Isi nyata `files/nodejs-project/data/gateway.json` + `.bak` di storage privat app — **22 baris pesan pelanggan asli** (grup "Aulia Digital Photo Service", dll.), status `failed`, `attempts` 6–53×, `last_error: "Timeout menghubungi CI4"` |

Ini pertama kalinya jalur fallback JSON diverifikasi di runtime Node embedded (nodejs-mobile) pada
CPU ARM sungguhan, bukan di Node desktop x64 — persis definisi "lingkungan yang benar-benar
memakainya" yang diminta Ticket 04.

## 3. Prosedur

**Backup penuh sebelum mutasi apa pun** (VERIFY ad-hoc, bukan task plan formal — sesi ini murni
membaca/menulis data eksperimen pada device, tidak ada commit kode):

1. `adb shell run-as com.auliapos.wagateway cat files/nodejs-project/data/gateway.json[.bak]` →
   disalin ke `_tmp-android-verify/*.orig` di mesin dev.
2. Untuk tiap skenario: `adb shell am force-stop` → tulis file korup lewat `adb push` +
   `run-as ... cp` (native `cat >` di `run-as` shell tidak bisa menulis langsung ke path relatif
   app — lihat §5 Dead-End) → `adb logcat -c` → `adb shell monkey -p com.auliapos.wagateway -c
   android.intent.category.LAUNCHER 1` (start app) → `adb logcat -d` untuk membaca log JSON
   terstruktur (`NodeJS-stdout`, pino level 30/40/50) dari proses Node yang sungguh jalan di app.
3. Restore file asli dari backup, `force-stop`, start ulang, verifikasi `pendingSaatStartup`
   kembali ke 22 dan checksum MD5 identik dengan sebelum eksperimen.

## 4. Hasil per Skenario (dari `logcat`, proses Node nyata di device)

| # | Skenario | Setup | Log nyata dari device | Hasil |
| --- | --- | --- | --- | --- |
| A | Utama korup (truncated), `.bak` sehat (AC-012 kasus 1, REQ-016/017) | Timpa `gateway.json` dengan JSON terpotong di tengah objek, biarkan `.bak` = 22 baris asli | `level:40` (`warn`) *"File JSON incoming buffer tidak terbaca, dipulihkan dari cadangan (.bak)"*, `sizeBytes:41`, `quarantinePath:.../gateway.json.corrupt-1790307097741`, `pendingSetelahPemulihan:22`; lalu `level:30` *"JSON incoming buffer siap"*, `pendingSaatStartup:22` | ✅ **Pulih penuh 22/22 baris**, file korup dikarantina (bukan dihapus/ditimpa), TIDAK ada error keras, TIDAK crash |
| B | Utama **dan** `.bak` sama-sama korup (AC-012 kasus 2, jaring pengaman) | Timpa keduanya dengan teks non-JSON | `level:50` (`error`, `severity:critical`) *"[CRITICAL] File JSON incoming buffer DAN cadangannya (.bak) tidak bisa dipakai -- antrean dimulai dari kosong"*, `sizeBytes` dicatat SEBELUM dipindah, `quarantinePath` tercatat; lalu start normal `pendingSaatStartup:0` | ✅ **Gateway tetap start** (tidak crash), kegagalan terlihat KERAS di log (bukan senyap), berkas asli tetap ada di `.corrupt-<waktu>` untuk diperiksa manual — persis kontrak REQ-017 |

Kedua skenario ini mewakili dua cabang kunci `_load()` yang paling berisiko (pulih sukses vs.
jaring pengaman gagal total) dan keduanya lolos identik dengan hasil simulasi Node desktop
(`test/simulate-json-recovery.js` skenario 1 dan "keduanya rusak sekali") — **paritas perilaku
Android vs desktop terbukti untuk kedua cabang ini**, bukan lagi asumsi.

## 5. Insiden Selama Verifikasi (Dead-End, harus dicatat jujur) + Pemulihan

Saat mengambil backup awal, `adb shell run-as ... cat file > local-file` dijalankan lewat
**redirection PowerShell**, yang secara diam-diam mengonversi output menjadi **UTF-16 dengan BOM**
(bukan UTF-8 asli seperti di device). Saat file itu di-push kembali sebagai "restore", Node di
device membaca byte UTF-16 sebagai UTF-8 tidak valid → **kedua file (utama dan `.bak`) dianggap
korup oleh `_tryLoadFrom()`**, memicu ulang Skenario B secara tidak sengaja pada percobaan restore
pertama (`pendingSaatStartup:0`, file asli dikarantina ke `.corrupt-1790307223419`).

**Tidak ada data yang hilang** — file karantina di device tetap berisi 22 baris JSON valid (dapat
dibuktikan dengan decode `UTF-16`). Perbaikan: decode ulang backup awal sebagai UTF-16, tulis ulang
sebagai UTF-8 murni, push+restore ulang. Restore kedua dikonfirmasi lewat:

- Log startup bersih: `pendingSaatStartup:22`, **tanpa** `warn`/`error` (berkas sehat dibaca senyap).
- **MD5 identik** antara file yang di-push (`fixed-main.json`/`fixed-bak.json`) dan file yang
  benar-benar berada di `files/nodejs-project/data/` pada device (`b10b192277b21ee79d389aaa9434dbbc`
  main, `2633235112512eceb6a0d3792c35a35c` bak — dicocokkan dengan `md5sum` yang dijalankan LANGSUNG
  di device, bukan di mesin dev, untuk menghindari salah baca encoding yang sama).
- Isi 22 `wa_message_id` dan `nextId:23` dikonfirmasi identik dengan kondisi sebelum eksperimen
  (didekode ulang sebagai UTF-16 dari redirect PowerShell, dibandingkan satu per satu).
- `ls files/nodejs-project/data/` akhir hanya berisi `gateway.json` + `gateway.json.bak` — semua
  berkas karantina (`.corrupt-*`) dan file temp di `/data/local/tmp/` sudah dibersihkan.

**Pelajaran (kandidat promosi Knowledge Base):** JANGAN PERNAH memakai `adb shell ... cat > file`
lewat redirection PowerShell untuk mengambil/memulihkan berkas biner/teks dari device — PowerShell
mengonversi encoding output secara diam-diam. Selalu pakai `adb pull`/`adb push` (binary-safe) untuk
transfer berkas, dan verifikasi lewat `md5sum` yang dijalankan **di kedua sisi** (device dan file
lokal) sebelum mempercayai sebuah "restore" berhasil.

## 6. Kesimpulan — Status Ticket 04

- **TASK-015 / REQ-016 / REQ-017 / AC-012 kini terbukti nyata di build Android** (bukan lagi hanya
  simulasi Node desktop). `docs/TODO-CHAT.md` butir 04 baris kedua ("verifikasi di lingkungan yang
  benar-benar memakai fallback JSON") dapat ditandai selesai.
- **Batas yang masih jujur harus dinyatakan:** hanya 2 dari 8 skenario `simulate-json-recovery.js`
  yang direproduksi ulang secara manual di device (dipilih karena mewakili dua cabang kode
  ter-berisiko: pulih sukses dan gagal total). 6 skenario lain (bentuk JSON salah `{}`/`[]`/`null`,
  cadangan tidak diracuni saat berjalan, kegagalan salin cadangan non-fatal, start pertama senyap,
  round-trip normal) **tidak** direproduksi manual di HP ini — cakupannya sudah dijamin oleh kode
  yang identik (satu file `incomingBuffer.js`, tidak ada percabangan khusus Android) dan oleh suite
  otomatis `simulate-json-recovery.js` yang 8/8 lulus di Node desktop, tapi klaim "8/8 teruji di
  Android" akan tidak akurat dan TIDAK dibuat.
- **ASSUMPTION-007** (`spec-process-m1-wave2-outgoing-idempotency.md`, untuk `outgoing_operations`,
  bukan `incomingBuffer`) **masih terbuka** — modul itu berbeda file/kelas, verifikasi ini tidak
  otomatis menutupnya. Perlu sesi terpisah dengan APK yang sudah membawa kode Wave 2 (Gateway di HP
  ini masih `master` @ `3e356cd`, sebelum kode `outgoing_operations` ada).

## 7. Temuan Tak Terkait (Di Luar Scope, Wajib Dilaporkan)

Selama inspeksi read-only ditemukan **22 pesan pelanggan asli** di antrean fallback JSON device ini
berstatus `failed`, semua dengan `last_error: "Timeout menghubungi CI4"` dan `attempts` 6–53×. File
`.env` device menunjukkan `CI4_BASE_URL=http://192.168.10/aulia` — **alamat IP tampak tidak
lengkap** (pola lazim `192.168.10.x`). Ini kemungkinan besar akar masalah kegagalan pengiriman
berulang, TAPI **di luar scope Ticket 04** dan **tidak diperbaiki** dalam sesi ini (tidak ada
perubahan apa pun pada `.env` device). Direkomendasikan sebagai bug report terpisah
(`/sdlc-bug-report`) di sesi berikutnya.

### Update — Konfirmasi Perbaikan oleh Operator (2026-09-25, sesi berikutnya)

Operator toko memperbaiki `CI4_BASE_URL` di `.env` device (alamat IP yang sebelumnya terpotong
diganti dengan alamat LAN AuliaPos yang benar) dan me-restart Gateway. **Dikonfirmasi via audit
read-only `aulia_inboxdb.messages`:** seluruh backlog berhasil mendarat, bukan hanya 22 baris yang
tercatat di `gateway.json` saat inspeksi — total **62 baris** (`id` 204–265, 10 percakapan:
`11745`–`11754`, 56 incoming + 6 outgoing) muncul di `aulia_inboxdb` dalam satu jendela flush
2026-09-25 13:56:06–13:58:03, dengan jeda `message_timestamp` → `created_at` antara 9 dan 326 menit
per baris. Sebuah batch flush serupa yang lebih besar (51 baris, 19 percakapan, jeda hingga 2880
menit/2 hari) juga tercatat sehari sebelumnya (`id` 153–203, `created_at` 2026-09-24 04:13–05:07),
menunjukkan pola berulang: setiap kali koneksi ke CI4 gagal, backlog pesan menumpuk dan baru
ter-flush sekaligus saat koneksi/Gateway pulih — bukan insiden tunggal.

**Status akhir: RESOLVED oleh operator, dikonfirmasi via data DB** (bukan lagi hipotesis
"kemungkinan besar akar masalah"). Tidak ada perubahan kode yang diperlukan — perbaikan murni
konfigurasi `.env` di sisi device Gateway. `/sdlc-bug-report` terpisah untuk item ini **tidak
diperlukan lagi**.

**Catatan silang penting untuk `plan/plan-bugfix-inbox-message-ordering-v1.0.md`:** proses flush
backlog di atas adalah bukti nyata tambahan (bukan hanya simulasi) bahwa pesan yang tertunda lama
bisa masuk ke `messages` dengan urutan `id` (kedatangan) yang **tidak selaras** dengan urutan
`message_timestamp` aslinya — mis. percakapan `11745` baris `id=260` (`message_timestamp
2026-09-25 08:31:32`) mendarat SETELAH baris `id=243` (`message_timestamp 2026-09-25 09:48:37`)
yang sudah lebih dulu ada. Pola yang sama terjadi di percakapan `11746`, `11747`, `11748`, `11749`,
`11750` (7 inversi total). Temuan ini memperkuat urgensi bugfix `orderBy('id', 'ASC')` pada plan
tersebut — kondisi backlog-flush pasca-gangguan jaringan/koneksi CI4 adalah pemicu realistis, bukan
skenario edge-case teoretis.

### Temuan Baru (Terpisah, Belum Ada Plan) — `conversations.last_message_at` Bisa Mundur

Audit read-only yang sama menemukan cacat kedua, **belum tercakup** oleh
`plan-bugfix-inbox-message-ordering-v1.0.md` (plan itu hanya memperbaiki urutan baca
`MessageModel::getByConversation()`, bukan kolom denormalized `conversations`):

- **Kode:** `app/Controllers/InboxGatewayApi.php:260-276`. Setiap insert pesan (termasuk incoming)
  menulis `conversations.last_message_at = $messageTimestamp` **tanpa guard** "hanya jika lebih baru
  dari nilai saat ini".
- **Dampak nyata teramati:** **7 percakapan** kini punya `last_message_at` yang LEBIH LAMA daripada
  pesan terbaru yang sungguh ada di `messages` — 4 di antaranya akibat flush hari ini (`11745`
  `last_message_at=08:31:35` vs pesan terbaru `09:48:37`; `11747` `12:31:18` vs `13:47:15`; `11748`
  `11:23:51` vs `11:47:54`; `11750` `10:16:36` vs `13:16:17`) dan 3 akibat flush sehari sebelumnya
  (`11730`, `11739`, `11740`). Di semua kasus, baris yang terakhir di-insert bukan pesan dengan
  timestamp terbaru, sehingga nilai `last_message_at` tergantikan ke belakang.
- **Kenapa penting:** `last_message_at` adalah sumber SLA Timer (REQ-010,
  `spec-design-m3-operational-inbox-fase1.md:133`, threshold Hijau/Kuning/Merah) dan urutan daftar
  percakapan (`ORDER BY last_message_at DESC`, RISK-002 di plan ordering). Nilai yang mundur bisa
  membuat SLA badge/urutan daftar salah setiap kali backlog pesan tertunda ter-flush tidak berurutan
  — skenario yang sama persis dengan yang memicu bugfix ordering di atas, dan sudah terjadi dua kali
  (24 dan 25 Sep) sehingga bukan insiden sekali saja.
- **Status:** dilaporkan di sini sebagai bukti diagnostik (`/sdlc-bug-report`-style), **belum ada
  plan perbaikan**. Direkomendasikan sesi `/sdlc-bug-report` terpisah untuk merancang guard
  "`last_message_at` hanya maju, tidak pernah mundur" di titik insert `InboxGatewayApi.php`.

