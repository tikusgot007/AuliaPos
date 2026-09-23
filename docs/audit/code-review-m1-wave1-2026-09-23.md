# Code Review Report — M1 Gelombang 1 (Keandalan Pesan Masuk WA-Gateway)

**Date:** 2026-09-23
**Reviewer:** `/sdlc-code-review` (Expert Code Reviewer) — **nol perubahan source code** di kedua repo
**Repo / branch / HEAD:** WA-Gateway (`tikusgot007/WA-Gateway`), worktree `C:\projects\WA-Gateway-m1`,
`feature/stage-1-reliability` @ `065f683` (working tree bersih, diverifikasi sebelum dan sesudah review)
**Reviewed range:** `e18f716..065f683` — 21 komit, 21 berkas, `+2825 / −48` (angka cocok dengan decision log deploy)
**Normative upstream:** `spec/spec-process-m1-wave1-incoming-reliability.md` v1.1,
`plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` v1.2 (`Completed`),
`docs/decisions/2026-09-21-m1-wave1-eksekusi-fase1-3.md`, `docs/decisions/2026-09-23-m1-wave1-deploy-dan-ac001.md`
**Metode:** dua reviewer paralel (Standards+Security, Spec) + verifikasi ulang oleh orchestrator. Setiap `file:line`
di bawah diambil ulang dari berkas `065f683`, bukan dari diff. Folder live `C:\projects\WA-Gateway`, `auth/`, dan PM2 tidak disentuh.

## Executive Summary

- **Standards Axis (A):** pada umumnya sehat. Tidak ada rahasia di log, log injection tertutup oleh pino JSON,
  memori `ownSentRegistry` dan `overflowBuffer` dibatasi, validasi field wajib dijalankan sebelum insert, isolasi
  error per pesan benar, dan urutan `register()` sebelum `sendMessage()` cocok dengan kode Baileys 6.7.24.
  **Satu temuan P1 terbukti lewat reproduksi:** saat start, error *non-korup* (DB terkunci) dianggap "korup",
  dan kegagalan constructor SQLite jatuh diam-diam ke buffer JSON dengan log `warn` yang menyesatkan (CR-01).
  Sisanya P2: kopling drain overflow dengan siklus kirim CI4, tiga skrip uji lama yang menulis ke DB non-sementara,
  dan batas env yang tidak lengkap.
- **Spec Axis (B):** **19/19 REQ, CON-001..004, dan GUD-001/002 terpenuhi di kode.** CON-005 hanya bisa dibuktikan
  lewat decision log deploy. AC-002..AC-018 punya skrip otomatis, **kecuali AC-014** (skrip pembanding payload tidak
  ter-commit, jadi buktinya tidak bisa diulang). Dari 7 penyimpangan yang dideklarasikan, 6 cukup didokumentasikan.
  Poin (g) **tidak akurat**: `OWN_SENT_TTL_MS` negatif atau `0` diterima dan diam-diam mematikan filter kiriman sendiri.
- **Verdict:** **0 P0, 1 P1, 14 P2** (5 wajib, sisanya NIT/OPTIONAL/FYI). Tidak ada temuan yang membatalkan AC-001.
  Perbaikan dikumpulkan di `plan/plan-refactor-m1-wave1-incoming-reliability-v1.0.md`.
- **Testing strategy assessment:** uji inti menguji perilaku nyata, bukan sekadar mock. Contohnya: AC-002 menyuntik `append`
  di tengah `sendTextMessage` asli; AC-011 memakai berkas korup sungguhan dan memeriksa 3 berkas yang dipindah; AC-010
  mengukur waktu nyata (±2015 ms); dan pelaksana mencatat uji mutasi pada TASK-009/010/013/014/015. Yang lemah:
  batas keamanan start SQLite (DB terkunci) tidak diuji sama sekali, tiga skrip lama tidak memakai folder sementara,
  dan guard statis RISK-001 mudah dikelabui. Guard itu bukan satu-satunya pelindung, karena `simulate-append-handling` #1–#5 sudah
  memeriksa urutan saat runtime.

## 1. Verifikasi Ulang Batas (Independen)

| Klaim | Cara verifikasi | Hasil |
| --- | --- | --- |
| Worktree bersih, HEAD `065f683` | `git -C … status --short`; `rev-parse --short HEAD` | ✅ kosong; `065f683` di `feature/stage-1-reliability` |
| 21 komit, 21 berkas, +2825/−48 | `rev-list --count`; `diff --stat e18f716..HEAD` | ✅ cocok |
| CON-004 tanpa dependensi npm baru | `git diff --name-only e18f716..065f683 \| grep -i package` | ✅ nol hasil (`package.json`/`package-lock.json` absen dari diff) |
| Hanya 2 titik `sock.sendMessage()` | `grep -rn "sendMessage(" src` | ✅ `connectionManager.js:858` dan `:977`, keduanya memakai `{ messageId: ownMessageId }` |
| Opsi `messageId` menimpa ID otomatis Baileys | baca `node_modules/@whiskeysockets/baileys` 6.7.24 `messages-send.js` | ✅ `messageId: generateMessageIDV2(...), ...options`; Gateway memakai generator yang sama (`connectionManager.js:830-835`) |
| Tidak ada level `critical` di logger | `src/logging/index.js` (pino, info/warn/error/debug) | ✅ deviasi (b) benar |
| Token tidak pernah dicatat | grep `gatewayToken`/`CI4_GATEWAY_TOKEN`/`SUPERVISOR_TOKEN` + 19 `logger.*` baru | ✅ token hanya dipakai untuk header `Authorization` (`ci4Client.js:28`) dan cek truthiness. `SUPERVISOR_TOKEN` **tidak ada** di source WA-Gateway |
| Skrip uji lulus | dijalankan di worktree, Node v20.20.2, `SQLITE_PATH` ke folder sementara | ✅ **12/12 exit 0** (±16 s total). Setelahnya `git status` tetap bersih |
| F-01 (DB terkunci saat start) | reproduksi di scratchpad: DB sehat + `locking_mode=EXCLUSIVE` di koneksi lain → `new IncomingBufferSqlite()` | ❌ memblokir **7377 ms**, `checkIntegrity` → "tidak sehat", lalu `renameSync` → `EBUSY` dan constructor melempar (lihat CR-01) |

> **Catatan jejak uji:** `simulate-e09-json-recovery.js` menulis ke `data/` di worktree (folder itu masuk `.gitignore`), dan
> berkas `data/test-e09-buffer.sqlite` (20 KB, sejak 2026-09-21) masih tertinggal di sana. Tidak ada berkas di folder live yang disentuh.

## 2. Findings — Axis A: Standards (Kualitas & Keamanan)

### [P1] [REQUIRED] [CR-01] Start SQLite: error non-korup dianggap korup, lalu kegagalan constructor jatuh diam-diam ke buffer JSON

- **Description:** ada dua cacat pada satu jalur.
  1. `checkIntegrity()` memperlakukan **exception apa pun** sebagai "korup" (`catch (err) { return { healthy: false, … } }`),
     termasuk `SQLITE_BUSY`/`EPERM` yang sifatnya sementara. Pada database sehat yang sedang dikunci, Gateway mencoba memindahkannya ke
     `.corrupt-*`. Bila kunci sudah lepas saat `rename`, **antrean pending yang sehat keluar dari antrean aktif**
     (berkasnya tidak hilang, tetapi pesannya tidak terkirim sampai direkonsiliasi manual).
  2. Bila `rename` gagal (Windows: `EBUSY`, **terbukti**), constructor melempar error. Singleton lalu menangkapnya dengan `catch`
     yang sama untuk "modul tidak ada", mencatat **`warn`** "better-sqlite3 tidak tersedia", dan **pindah ke berkas JSON**.
     Baris pending SQLite tertahan, pesan baru masuk ke `gateway.json`, dan pada restart berikutnya yang normal isi JSON itu
     tidak pernah dibaca lagi. **Ini jalur kehilangan pesan senyap**, persis kelas masalah yang ingin ditutup GW-08, dan melanggar GUD-002
     (tidak ada log `error`). Mekanisme `catch`-nya sudah ada sebelum M1. M1 menambah dua pemicu baru (`quick_check` + `renameSync`).
  - Reproduksi (scratchpad, bukan folder live): koneksi lain memegang `BEGIN EXCLUSIVE` → probe read-only memblokir 7377 ms →
    `SQLITE_BUSY` → dianggap korup → `EBUSY: resource busy or locked, rename 'healthy.sqlite' -> 'healthy.sqlite.corrupt-…'` → constructor melempar.
  - Peluang kejadian rendah (butuh proses lain yang mengunci DB tepat saat start: instance Gateway kedua, DB browser, antivirus),
    tetapi dampaknya adalah kehilangan pesan tanpa log keras.
- **Category:** Error handling / Security (Availability & Repudiation, STRIDE) / Correctness
- **Location:** `src/store/incomingBuffer.js` (lines 79-93 `checkIntegrity`, 102-126 `openVerifiedDatabase`, 584-593 singleton)
- **Remedy:**
  - Karantina **hanya** bila `quick_check` mengembalikan selain `ok`, atau error berkode `SQLITE_CORRUPT`/`SQLITE_NOTADB`. Error lain dilempar ulang.
  - Pisahkan `require('better-sqlite3')` (boleh fallback JSON) dari `new IncomingBufferSqlite()`. Bila constructor gagal, catat `logger.error('[CRITICAL] …')`
    lalu lempar ulang (proses berhenti, PM2 menyalakan ulang), bukan pindah ke JSON.
  - Tambah skenario uji "DB sehat terkunci → tidak dikarantina, tidak ada fallback JSON".

### [P2] [REQUIRED] [CR-02] Pengurasan overflow tertahan oleh siklus kirim ke CI4

- **Description:** `tick()` keluar lebih dulu bila `isRunning` (line 70), sebelum `overflowBuffer.drain()` (line 79). Satu siklus
  bisa berisi sampai 20 `await deliverOne()` dengan timeout CI4 8 detik (±160 detik). Bila DB bermasalah **dan** CI4 lambat atau mati bersamaan,
  overflow tidak dikuras selama menit-menit itu, bisa penuh (500), lalu pesan baru dibuang padahal DB mungkin sudah pulih.
  REQ-011 masih terpenuhi secara harfiah ("di awal setiap siklus"), tetapi jalur durabilitas lokal jadi bergantung pada latensi jaringan.
- **Category:** Architecture (coupling) / Reliability
- **Location:** `src/delivery/incomingDelivery.js` (lines 69-79)
- **Remedy:** `drain()` sinkron dan tidak butuh CI4, jadi pindahkan pemanggilannya ke **sebelum** cek `isRunning`. Tambah satu uji: `isRunning` benar → overflow tetap terkuras.

### [P2] [REQUIRED] [CR-03] Tiga skrip uji lama menulis ke DB non-sementara, tumpang tindih, dan tidak ada di plan

- **Description:** `test/simulate-enqueue-failure.js`, `test/simulate-e05-lid-timeout.js`, dan `test/simulate-e09-json-recovery.js` berasal dari
  komit ad-hoc pra-plan (`b5d46a9`, `4e6ad69`→`b1e5dfa`, `eb02e38`), tidak tercantum di FILE-008, dan melanggar spec §6
  ("database dan berkas sementara di folder sementara sistem, dihapus setelah tiap skenario"):
  - `simulate-enqueue-failure.js` dan `simulate-e05-lid-timeout.js` tidak mengatur `SQLITE_PATH` maupun mengosongkan `CI4_*`. Keduanya membaca `.env` dari
    cwd, dan `enqueue-failure` menyisipkan baris `pending` palsu (`SIM-ENQ-3`, `SIM-ENQ-5`) tanpa menghapusnya.
    **Kalau dijalankan dari folder live** (skrip ini sekarang ikut ada di `master` folder live), baris palsu itu masuk DB produksi-lokal dan dikirim worker ke AuliaPos.
  - `simulate-e09-json-recovery.js` menulis ke `data/` repo dan tidak menghapus `test-e09-buffer.sqlite`.
  - Isinya redundan: `e05` ⊂ `simulate-lid-timeout.js` (batas `<6000ms` sisa timeout lama 5 s); `e09` ⊂ `simulate-json-recovery.js`;
    `enqueue-failure` ⊂ `simulate-durable-buffer.js` + `simulate-enqueue-integrity.js`.
- **Category:** Test hygiene / Security (Tampering pada data lokal)
- **Location:** `test/simulate-enqueue-failure.js` (lines 19-20, 60-67, 102); `test/simulate-e05-lid-timeout.js` (lines 168-173); `test/simulate-e09-json-recovery.js` (lines 23, 41)
- **Remedy:** hapus ketiganya **setelah** membuktikan setiap skenario punya padanan di skrip baru (tabel pemetaan di commit message).
  Skenario yang tidak punya padanan dipindah ke skrip baru dengan pola `mkdtemp` + `SQLITE_PATH` + `CI4_*` kosong + `rmSync` di `finally`.

### [P2] [REQUIRED] [CR-04] `OWN_SENT_TTL_MS` tanpa batas bawah; klaim deviasi (g) tidak akurat

- **Description:** `toInt()` hanya jatuh ke bawaan bila hasilnya `NaN`. Nilai negatif atau `0` untuk `OWN_SENT_TTL_MS` diterima apa adanya, sehingga
  `wasSentByUs()` (`now - t > ttlMs`) selalu `false` dan **filter kiriman sendiri (D-01) mati diam-diam**: setiap balasan kasir akan masuk ulang
  sebagai baris `outgoing` ganda. Kunci lain di-*clamp* (`Math.max`), bukan dikembalikan ke bawaan. `'12abc'` dibaca `12`.
  Kalimat decision log "nilai tidak valid memakai bawaan utuh" hanya benar untuk `ENQUEUE_RETRY_DELAYS_MS`.
- **Category:** Clean Code (boundary validation) / Correctness
- **Location:** `src/config/index.js` (lines 8-11 `toInt`, 102 `ownSentTtlMs`)
- **Remedy:** `ownSentTtlMs: Math.max(1, toInt(...))`, sama dengan kunci lain. Tambah satu asersi di `simulate-own-sent-registry.js` (#6 sudah membaca config lewat proses anak).
  Batas atas jeda retry dan `'12abc'` cukup dicatat (NIT).

### [P2] [OPTIONAL] [CR-05] Loop penerimaan bisa tertahan lama saat pesan hanya ada di memori

- **Description:** pesan diproses berurutan. Per pesan ada query LID hingga 2 detik **sebelum** disimpan (`_handleIncomingMessage`),
  lalu retry 50+200+800 ms bila penyimpanan gagal. Ditambah `new Database(dbPath)` tanpa opsi `timeout` memakai bawaan better-sqlite3
  5000 ms yang **memblokir event loop secara sinkron** (terukur ±7,4 detik pada reproduksi CR-01). Pada `SQLITE_BUSY`, satu pesan bisa membekukan proses
  lebih dari 20 detik (heartbeat, HTTP, keep-alive Baileys), dan tanda terima sudah terkirim. Ini batasan yang diterima spec (E-13), tetapi jendelanya
  lebih lebar dari yang tersirat.
- **Category:** Performance / Reliability
- **Location:** `src/store/incomingBuffer.js` (line 123); `src/store/enqueueRetry.js` (line 41); `src/whatsapp/connectionManager.js` (lines 423, 628-812)
- **Remedy (ditunda):** buka DB dengan `timeout` kecil (250–500 ms). Pertimbangkan "simpan dulu, perkaya LID kemudian" di gelombang berikutnya.

### [P2] [OPTIONAL] [CR-06] Guard statis RISK-001 (`check-register-before-send.js`) mudah dikelabui

- **Description:** reviewer menjalankan `checkSource` dengan fixture tambahan. Guard **lolos** (tidak mendeteksi) pada kasus berikut:
  - fungsi top-level sebelum method kelas pertama (teks sebelum `starts[0]` dibuang);
  - fungsi setelah kelas (digabung ke blok method terakhir);
  - `this.sock['sendMessage'](…)` atau `sendMessage` hasil destrukturisasi;
  - `register` di dalam cabang `if`;
  - `messageId` yang bukan hasil `_registerOwnSentId()`;
  - helper diganti nama (aturan 3 dilewati diam-diam).
  
  Ini sesuai pengakuan jujur di decision log ("melengkapi, bukan menggantikan"). Jawaban untuk titik prioritas 4:
  **asersi runtime sudah ada**. `simulate-append-handling.js` #1–#5 memeriksa lewat `sock` palsu bahwa ID tercatat saat `sendMessage` dipanggil,
  tetap tercatat saat kirim ditolak, dan berlaku untuk teks maupun media.
- **Category:** Test robustness
- **Location:** `test/check-register-before-send.js` (lines 37-47, 62-96)
- **Remedy (ditunda):** perlakukan uji runtime sebagai penjaga utama. Bila guard statis dipertahankan: pindai seluruh berkas,
  cocokkan `sendMessage` dalam bentuk apa pun, dan gagal bila helper tidak ditemukan.

### [P2] [NIT] [CR-07] Error ditelan tanpa alasan

- **Description:** `drain()` menangkap error tanpa mencatat penyebab (`catch (err) { remaining.push(event); }`). Event "beracun" (gagal permanen) dicoba ulang
  tiap 5 detik selamanya tanpa jejak, dan hanya terlihat dari log ukuran yang muncul **bila ukuran berubah**. `_tryLoadFrom()` mengembalikan `null` tanpa detail,
  sehingga log `[CRITICAL]` JSON tidak membedakan parse error dari `EACCES`.
- **Location:** `src/store/overflowBuffer.js` (lines 72-73); `src/store/incomingBuffer.js` (lines 450-466)
- **Remedy:** simpan `err.message` terakhir per event dan sertakan di log `debug`, dan teruskan alasan dari `_tryLoadFrom` ke log `_load`.

### [P2] [NIT] [CR-08] `_lidFailureCache` tidak dibatasi ukurannya

- **Description:** entri hanya dihapus bila JID yang **sama** dicari lagi setelah TTL. Tidak ada sapuan atau batas, jadi `Map` tumbuh sesuai jumlah JID `pn`
  berbeda yang pernah gagal (±100 B per entri). Pola yang sama sudah ada pada `_lidResolutionCache` (permanen, pra-M1).
- **Location:** `src/whatsapp/connectionManager.js` (lines 86, 536-540, 576)
- **Remedy:** saat `set`, bila `size` melewati ambang (mis. 5000), buang entri kedaluwarsa atau yang terlama.

### [P2] [OPTIONAL] [CR-09] `_persist()` JSON menambah O(n) per tulis; `.bak` tertinggal satu tulisan

- **Description:** setiap `enqueue`/`markCompleted`/`markFailedAttempt` membaca dan mem-*parse* ulang seluruh berkas utama (`_tryLoadFrom`), lalu menyalin ke `.bak`,
  lalu menulis. Ini jalur Android. Pemulihan dari `.bak` kehilangan operasi terakhir. Bila itu `enqueue`, satu pesan hilang (tanda terima sudah terkirim).
  Hal ini konsisten dengan ASSUMPTION spec ("satu cadangan dari penulisan sebelumnya").
- **Location:** `src/store/incomingBuffer.js` (lines 469-505)
- **Remedy (ditunda):** `rename(main→.bak)` lalu `rename(tmp→main)`. Biayanya O(1), dan `_load` sudah menangani "utama hilang, `.bak` valid".

### [P2] [OPTIONAL] [CR-10] SRP: `connectionManager.js` terus membesar (Divergent Change)

- **Description:** berkas kini 1158 baris. `_handleIncomingMessage` 628-812 (±185 baris), `_resolveLidForPhoneJid` 528-585 (dua cache + timeout),
  dan `IncomingBufferJsonFile._load` 371-447 (±77 baris). Pengelola socket kini juga memegang kebijakan persistensi, cache LID, dan filter `append`.
- **Remedy (ditunda, PR terpisah):** ekstraksi `LidResolver` dan `IncomingPersistence`, serta pecah `_load` menjadi karantina/pulihkan/reset.

### [P2] [NIT] [CR-11] Duplikasi dan detail kecil

- Pemetaan baris dan rumus backoff terduplikasi antara kelas SQLite dan JSON (sebagian pra-M1). `cleanup()` disalin di 5 skrip uji.
- `enqueueRetry.js:28` `return buffer.enqueue(event)` tanpa `await` (aman selama buffer sinkron). `lastError.message` akan melempar bila yang dilempar bukan `Error`.
- `connectionManager.js:489` `info` untuk tiap `append` non-pelanggan. Burst channel saat reconnect bisa menggusur ring buffer dashboard (300 entri).
  Spec REQ-018 memang meminta level **info**, jadi ini bukan pelanggaran.

### [FYI] [CR-12] Hal yang dinilai aman

- **Kebocoran rahasia:** nihil (lihat §1).
- **Log injection:** pino menulis JSON dengan field ter-escape, dan ring buffer dashboard hanya menyimpan string pesan konstan.
- **Batas memori:** `OwnSentRegistry` dibatasi `max` (terlama dikeluarkan, `ownSentRegistry.js:38-48`). Overflow dibatasi `enqueueOverflowMax` (min 1) dan membuang yang terbaru dengan `[CRITICAL]`.
  Kasus terburuk overflow ±500 × (teks + ref media) ≈ puluhan MB.
- **`Promise.race`:** timer dibersihkan di `finally` (`connectionManager.js:578-580`), dan penolakan terlambat tidak menjadi unhandled rejection.
- **Floor-guard 12 skrip uji:** nol `eslint-disable`, `@ts-ignore`, `.skip`, `xit`, `.only`, dan asersi yang dikomentari. Tidak ada `catch` yang menelan
  `AssertionError` (catch hanya membungkus `close`/`rmSync`). Jalur gagal keluar non-zero, dan `process.exit(0)` hanya ada di jalur sukses.
- **Prompt injection pada data yang dibaca:** tidak ditemukan instruksi tersisip di kode, komentar, atau pesan komit.

## 3. Findings — Axis B: Spec (Kepatuhan Fungsional)

### [P2] [REQUIRED] [CR-13] Sisa E-05: `null` di-cache permanen saat belum `connected`

- **Description:** bila `!isConnected()` (line 544), query dilewati, lalu line 583 `this._lidResolutionCache.set(phoneJid, null)` membuat identity hint
  JID itu hilang **sampai restart**. Ini tidak melanggar huruf REQ-014 (tidak ada query, jadi tidak ada timeout) maupun REQ-019 (yang mengatur kegagalan query),
  tetapi bertentangan dengan klaim decision log "kegagalan sesaat tidak lagi permanen". Peluangnya kecil, karena `setStatus('connected')` sinkron saat `open` dan
  event dari socket generasi lama diabaikan. Uji "Belum connected" di `simulate-lid-timeout.js` memeriksa bahwa query tidak dilakukan, tetapi **tidak** memeriksa
  bahwa `null` tidak disimpan permanen.
- **Spec Reference:** REQ-014, REQ-019 (tidak berbenturan); decision log eksekusi §"Belum dikerjakan"
- **Location:** `src/whatsapp/connectionManager.js` (lines 544, 583)
- **Remedy:** saat belum `connected`, `return null` **tanpa** menulis cache. Tambah satu asersi di `simulate-lid-timeout.js`.

### [P2] [REQUIRED] [CR-14] Salinan decision log AuliaPos di repo kode sudah basi

- **Description:** `docs/decisions/2026-09-21-m1-ticket01-baseline.md` masuk ke repo WA-Gateway lewat komit pra-plan `3fd5f40`. Salinan itu
  **81 baris** (versi pagi 21 Sep), sedangkan versi di AuliaPos sudah **275 baris** (koreksi hipotesis dekripsi, uji 2b, Baseline 4 nyata). Ada dua sumber
  dengan isi berbeda, dan salinan di repo kode masih memuat kesimpulan yang sudah dikoreksi (mis. "konfound sesi"). Berkas ini tidak diminta spec atau plan (yatim).
- **Spec Reference:** plan §8 menunjuk dokumen ini sebagai berkas repo AuliaPos. Spec §7 (Project Structure) tidak menyebut `docs/` WA-Gateway.
- **Location:** WA-Gateway `docs/decisions/2026-09-21-m1-ticket01-baseline.md`
- **Remedy:** hapus dari repo WA-Gateway di branch yang sama sebelum PR. Bila perlu, ganti dengan satu baris rujukan ke repo AuliaPos.

### [P2] [OPTIONAL] [CR-15] Bukti AC-014 / CON-001 tidak bisa diulang

- **Description:** pembandingan 12 payload terhadap `e18f716` dilakukan lewat skrip sementara (`git archive` ke folder temp) yang **tidak di-commit**.
  Hasilnya dilaporkan lulus dan inspeksi kode mendukung (`deliverOne` dan bentuk `normalized` tidak berubah di diff), tetapi spec §13 meminta skrip otomatis untuk AC-002..AC-018.
- **Spec Reference:** AC-014, CON-001, spec §13
- **Location:** tidak ada berkas (celah bukti)
- **Remedy:** terima sebagai batas bukti (dicatat di sini), **atau** commit skrip pembanding bila kontrak disentuh lagi di Gelombang 2.

### [P2] [NIT] [CR-16] Celah asersi kecil pada uji AC

| AC | Uji | Yang belum diasersikan |
| --- | --- | --- |
| AC-003 | `simulate-append-handling.js` #6 | "tanpa identitas staff": tidak ada asersi eksplisit pada field identitas baris `outgoing` |
| AC-007 | `simulate-durable-buffer.js` #2–#6 | "penampung sementara kosong" hanya diuji dengan buffer palsu, bukan lewat `_persistIncoming()` |
| AC-008 | `simulate-durable-buffer.js` #9–#12 | log **error** saat event masuk overflow tidak diasersikan |

- **Remedy:** tambah tiga asersi tanpa mengubah source.

### [FYI] [CR-17] Berkas di luar daftar FILES plan dinilai wajar

- `src/store/enqueueValidationError.js`: **bukan yatim.** TASK-001 meminta error bertipe khusus. Berkas terpisah menghindari pembukaan DB singleton saat
  modul lain melakukan `instanceof`. Cacatnya hanya pada daftar FILES plan (dokumentasi).
- `test/simulate-own-sent-registry.js`: unit test TASK-008 (spec §9 "tambah skrip uji untuk setiap perubahan").
- `test/check-register-before-send.js` dan `test/simulate-register-order-guard.js`: diwajibkan catatan v1.1 TASK-011.
- **Komit pra-plan** (`3fd5f40..091fe19`: perbaikan ad-hoc E-01/E-03/E-04/E-05/E-06/E-09, revert parsial `b1e5dfa`, merge PR #2/#3) ikut dalam rentang PR.
  **Tidak ada logika ad-hoc yang bertahan** di `065f683` (`_enqueueWithRetry`, timeout LID 5 s, dan `_load` lama sudah diganti kode plan). Yang tersisa hanya
  komentar "E-06 DIREVERT" (`connectionManager.js:438-444`), tiga skrip uji (CR-03), dan salinan dokumen (CR-14).

### [FYI] [CR-18] Ketidakakuratan dokumen (dicatat, tidak disunting — plan sudah `Completed`)

- Plan DEP-004 dan rollback Fase 3 menyebut JSON fallback "hanya aktif bila `better-sqlite3` tidak tersedia". Di kode, fallback juga aktif bila constructor SQLite gagal karena **alasan apa pun** (CR-01).
- Plan §9 Fase 3: berkas `.corrupt-<waktu>` "dipindah TASK-013/015". Yang benar TASK-005/015 (TASK-013 adalah timeout LID).
- Decision log eksekusi line 79 masih menyebut jendela `>21:00 / <08:00`, yang sudah dihapus plan v1.2. Karena log itu append-only, koreksinya cukup lewat entri baru.
- Decision log eksekusi poin (g): lihat CR-04.

## 4. Matriks Traceability (REQ/CON/GUD → Kode → Uji)

`cM` = `src/whatsapp/connectionManager.js`, `iB` = `src/store/incomingBuffer.js`. Semua nomor baris dari `065f683`.

| ID | Kode (file:baris) | Bukti uji | Status |
| --- | --- | --- | --- |
| REQ-001 | `cM` 395-412 (`notify`+`append`; lainnya `debug` + return) | `simulate-append-handling` #8 (AC-015) | ✅ terpenuhi |
| REQ-002 | `cM` 478-485 (`wasSentByUs` lebih dulu, hanya `append`) | `append-handling` #5, #10 (AC-002) | ✅ |
| REQ-003 | `ownSentRegistry.js` 38-59 (TTL, max, terlama keluar); `cM` 830-835, dipanggil sebelum `await` di 857-858 dan 976-977 | `simulate-own-sent-registry` #1–#6; `append-handling` #1–#4; guard statis | ✅ (CR-04 untuk env) |
| REQ-004 | `cM` 799 (`direction` dari `fromMe`) | `append-handling` #6 (AC-003) | ✅ (CR-16) |
| REQ-005 | `iB` 288-292 (SQLite), 510-511 (JSON) | `append-handling` #7 (AC-004); `enqueue-integrity` #3 | ✅ |
| REQ-006 | `iB` 55-65; pencatat `cM` 604-615 | `simulate-enqueue-integrity` #1; `durable-buffer` #13 (AC-005) | ✅ |
| REQ-007 | `iB` 288-296 | `enqueue-integrity` #4; `durable-buffer` #13c (AC-006) | ✅ |
| REQ-008 | `iB` 288/291 (SQLite), 537 (JSON) | `enqueue-integrity` #2, #3, #5 | ✅ |
| REQ-009 | `enqueueRetry.js` 24-52; `cM` 606 memakai `config.enqueueRetryDelaysMs` | `durable-buffer` #0–#6 (jeda nyata) (AC-007) | ✅ (CR-16) |
| REQ-010 | `overflowBuffer.js` 38-58; error `cM` 617-624 | `durable-buffer` #8, #12, #17 (AC-008, AC-009) | ✅ + deviasi (b), (c) |
| REQ-011 | `incomingDelivery.js` 79 sebelum `getDueEvents` 89 | `durable-buffer` #9, #10, #12 (`tick()` langsung) | ✅ (CR-02 kopling) |
| REQ-012 | `overflowBuffer.js` 53-56 (push), 78-82 (drain) | `durable-buffer` #7, #9–#11 (AC-016) | ✅ |
| REQ-013 | `iB` 79-126 (probe read-only, pindah utama/-wal/-shm, `[CRITICAL]`, `synchronous=FULL` di 125 untuk kedua jalur) | `durable-buffer` #14–#16 (AC-011) | ✅ jalur korup; ❌ jalur error non-korup (**CR-01**) |
| REQ-014 | `cM` 544-580 (`Promise.race`, `warn`, pesan tetap disimpan) | `simulate-lid-timeout` AC-010 (±2015 ms, `identity_hint_json` null) | ✅ |
| REQ-019 | `cM` 536-540, 576 | `lid-timeout` AC-018 (4 ms, batas 59 s vs 61 s) | ✅ (CR-13 sisa E-05) |
| REQ-015 | `cM` 429-437 (ID, JID, `contentType`, `upsertType`) | `simulate-error-isolation` (AC-013) | ✅ |
| REQ-016 | `iB` 481-500 (`.bak` dari tulisan valid sebelumnya) | `simulate-json-recovery` #1, #7, #8 | ✅ |
| REQ-017 | `iB` 371-447 (`.bak` + `warn`; keduanya gagal → `.corrupt-`, kosong, `[CRITICAL]` + `sizeBytes`) | `json-recovery` #2–#6 (AC-012) | ✅ + deviasi (d) |
| REQ-018 | `cM` 486-491 (`append` hanya `pn`/`lid`/`group`, `info` dengan JID + ID); `notify` tidak lewat sini | `append-handling` #9, #10 (AC-017) | ✅ |
| CON-001 | `deliverOne`/bentuk `normalized` tidak ada di diff | AC-014: skrip ad-hoc, tidak di-commit | ✅ inspeksi / ⚠️ **CR-15** |
| CON-002 | tidak ada perubahan skema | n/a | ✅ |
| CON-003 | `auth` hanya di `_connect` (tidak dipanggil uji); worktree tanpa `auth/` | decision log deploy (`auth/` absen dari diff merge) | ✅ |
| CON-004 | `package*.json` absen dari diff | §1 | ✅ |
| CON-005 | plan-level (tidak bisa dibaca dari kode) | decision log deploy: `merge --ff-only`, tanpa `checkout`, `auth/` tidak tersentuh | ✅ berdasar dokumen |
| GUD-001 | `config/index.js` 94-111 (6 env) | `durable-buffer` #17, `own-sent-registry` #6, config `lid-timeout` | ✅ (CR-04 batas) |
| GUD-002 | validasi, overflow, drop, karantina, error per pesan → `error` | lihat di atas | ⚠️ fallback JSON hanya `warn` (**CR-01**). Media dengan referensi tidak lengkap dibuang dengan `warn` (pra-M1, E-08 informasi, di luar scope) |

**Perilaku yatim:** tiga skrip uji lama (CR-03) dan salinan decision log (CR-14). **Requirement tanpa kode:** tidak ada.
**Requirement tanpa uji otomatis:** CON-001/AC-014 (CR-15). Jalur error non-korup REQ-013 belum diuji (CR-01).

## 5. Tinjauan 7 Penyimpangan yang Dideklarasikan

| # | Penyimpangan | Menyimpang dari spec/plan? | Risiko | Keputusan review |
| --- | --- | --- | --- | --- |
| (a) | `messageType` wajib, bawaan `'text'` dihapus | **Tidak.** REQ-006 mendaftar `message_type` sebagai field wajib. Semua pemanggil mengisinya | Pemanggil baru yang lupa mengisi akan ditolak dengan error keras (itu yang diinginkan) | Cukup didokumentasikan |
| (b) | Tidak ada level `critical`, dipakai `logger.error` + `[CRITICAL]` + `severity:'critical'` | Menyimpang dari huruf REQ-010 ("log `critical`"), sesuai niat GUD-002 | Aturan alert berbasis level pino 60 (`fatal`) tidak akan menangkapnya. Belum ada alerting, jadi dampaknya nol hari ini | Cukup didokumentasikan. **Butuh konfirmasi pemilik** (masih berstatus "asumsi" di decision log) |
| (c) | AC-009 mencatat `dropped: 1` dan `totalDropped` | Tidak. Memenuhi kedua tafsiran "jumlah yang dibuang" | Nihil | Cukup didokumentasikan |
| (d) | Karantina JSON dilakukan lebih awal, juga saat `.bak` memulihkan | Melebihi huruf REQ-017 | Lebih aman: tanpa ini, berkas korup bisa ditimpa atau disalin ke `.bak`. Efek samping: berkas yang hanya sesaat tidak terbaca (`EBUSY`) tetap dipindah, dan `.bak` (tertinggal satu tulisan) dimuat. Datanya tetap ada di `.corrupt-` | Cukup didokumentasikan. Sebaiknya dicatat sebagai tafsir resmi saat spec direvisi |
| (e) | TASK-009: dua titik sisipan | Tidak. Kriteria STOP adalah "lebih dari dua" | Terverifikasi hanya ada 2 `sendMessage` di `src/` | Cukup didokumentasikan |
| (f) | Skenario 5–6 `simulate-enqueue-failure.js` dipindah ke `_persistIncoming()` | Tidak. Kontrak D-02 memang "masuk overflow, tidak melempar". Asersinya setara | Berkasnya sendiri yatim dan tidak aman (CR-03) | **Perlu perbaikan** lewat CR-03, bukan karena perpindahannya |
| (g) | "Nilai env tidak valid memakai bawaan utuh" | **Klaim tidak akurat** | `OWN_SENT_TTL_MS ≤ 0` diam-diam mematikan filter kiriman sendiri | **Perlu perbaikan** (CR-04) + koreksi di entri decision log berikutnya |

## 6. Titik Prioritas Lain (jawaban langsung)

- **`enqueueValidationError.js` (titik 2):** wajar, bukan yatim (CR-17).
- **`simulate-e05-lid-timeout.js` / `simulate-e09-json-recovery.js` (titik 3):** redundan **dan** tidak aman dijalankan di folder live. Status yang diusulkan: **dihapus** setelah pemetaan skenario dibuktikan (CR-03).
- **Guard statis vs runtime (titik 4):** rapuh (CR-06), tetapi asersi runtime sudah ada. Tidak perlu tugas baru di Gelombang 1.
- **Sisa E-05 (titik 5):** terkonfirmasi, tidak berbenturan dengan REQ-014/019, dan diperbaiki satu baris (CR-13).
- **Dokumen keputusan di repo kode (titik 6):** temuan tata kelola P2 (CR-14). Hapus dari WA-Gateway.
- **Pemulihan JSON (titik 7):** skrip uji jujur menguji `IncomingBufferJsonFile` langsung. Yang menyesatkan adalah **plan** tentang kapan fallback aktif (CR-18, terkait CR-01).
- **Batas bukti yang sudah diketahui (titik 8):** tercatat jujur di dokumen, lihat §7.

## 7. Pernyataan Batas Bukti

AC-001 (3/3, 30/30, 0 hilang, 0 duplikat) **membuktikan**: pesan pelanggan `lid` yang tiba saat Gateway berhenti ±30 detik tersimpan tepat sekali
setelah start, lewat kode `065f683` yang sungguh berjalan di folder live. AC-001 **tidak membuktikan**:

1. **Jalur JSON fallback** (tanpa `better-sqlite3` / build Android). Hanya disimulasikan. Jalur fallback akibat constructor gagal (CR-01) bahkan tidak disimulasikan.
2. **Jalur DB bermasalah:** retry, overflow, drain, dan karantina tidak pernah terpicu di pengukuran nyata ("tidak ada log overflow/error M1").
3. **Filter kiriman sendiri pada balapan `append` sungguhan:** AC-001 hanya berisi pesan **masuk**. Tidak ada `/send` selama jendela ukur, jadi D-03 dan validasi spec §13 ("10 kiriman `/send` tidak menambah baris") **belum diukur nyata**.
4. **Alamat `pn`, grup, balasan `fromMe` dari HP, dan alamat `unknown`:** 30/30 baris AC-001 adalah `jid_type=lid`, `direction=incoming`. Query LID dengan timeout (REQ-014/019) tidak ikut teruji nyata.
5. **`auth/` dan sesi:** tidak disentuh, sehingga tidak ada klaim apa pun tentang dekripsi (GW-11/GW-25).
6. **Log `console`/stderr hari ini:** berkas log PM2 beku sejak 2026-09-21 20:46, sehingga `Session error: … Bad MAC` hari ini tidak dapat diverifikasi. Decision log deploy mencatat hal ini dengan jujur.
7. **Pesan `append` datang bergelombang** (5+5, 9+1, 6+4 dengan jeda hingga ±2 menit). Ini tercatat jujur. Pengukuran berikutnya harus menunggu ±2,5 menit sebelum menyimpulkan "hilang".
8. **Blob `node.exe` ±87 MB** sudah ter-commit di `e18f716` (sebelum M1) dan kini ada di `master` lokal. Tercatat jujur. Bukan bagian diff ini, tetapi **akan ikut** ke riwayat mana pun yang berbasis `e18f716`.
9. **AC-014** tidak bisa diulang tanpa skrip (CR-15).

## 8. Final Verdict

- **Total Findings:** Standards 12 (1 P1, 4 P2 wajib/opsional-berdampak, 7 NIT/OPTIONAL/FYI), Spec 6 (2 P2 wajib, 4 OPTIONAL/NIT/FYI). **0 P0.**
- **Worst Standards Issue:** CR-01. Start SQLite memperlakukan error sementara sebagai korup, dan kegagalan constructor jatuh diam-diam ke buffer JSON (jalur kehilangan pesan senyap, terbukti direproduksi).
- **Worst Spec Issue:** CR-13 (sisa E-05, cache `null` permanen), setara dengan CR-14 (salinan decision log basi di repo kode). Tidak ada REQ yang dilanggar.
- **Recommendation:** **Proceed to Refactoring Plan** — `plan/plan-refactor-m1-wave1-incoming-reliability-v1.0.md`.
  - Fase 1 (CR-01) sebaiknya selesai **sebelum** PR `feature/stage-1-reliability` → `master` dibuat. Kodenya sudah berjalan di folder live, tetapi pemicunya jarang, sehingga tidak ada alasan rollback.
  - Fase 2 (P2 wajib) bisa ikut PR yang sama atau PR susulan.
  - CR-05, CR-06, CR-07, CR-08, CR-09, CR-10, dan CR-11 **tidak** masuk plan dan dicatat sebagai backlog gelombang berikutnya (RISK-004/005: gelombang tidak diperluas).

<!-- Reviewer scope: laporan + rencana refactoring saja. Implementasi wajib lewat /sdlc-write-code. -->
