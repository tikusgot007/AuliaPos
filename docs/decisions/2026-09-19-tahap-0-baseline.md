# Tahap 0 — Baseline & Handoff Verification (2026-09-19)

Dokumen ini mencatat temuan nyata (bukan asumsi) dari eksekusi Step 2–6 Tahap 0,
melanjutkan sesi chat sebelumnya. Lingkungan eksekusi: sandbox Claude Code Web
(container terisolasi Linux), **bukan** mesin lokal dengan XAMPP/WhatsApp aktif.
Keterbatasan environment dicatat eksplisit di setiap bagian yang terdampak.

## 1. Verifikasi ulang branch & commit

### AuliaPos
- Repo: `tikusgot007/auliapos`
- Branch kerja: `claude/tahap-0-aulia-wa-handoff-ic861g`
- HEAD saat verifikasi: `07d30c883910e25f5f4fad30f6cf9071913053bd` — cocok dengan baseline handoff.
- Working tree: bersih.

### WA-Gateway
- Repo: `tikusgot007/wa-gateway` (redirect dari `tikusgot007/WA-Gateway`)
- Branch: `claude/android-app-p40bl1`
- HEAD saat verifikasi: `5b28eb6c8a7e6e6c2e1d5b7d7261389f9309c295` — cocok dengan baseline handoff.
- Working tree: bersih.
- Branch dev `feature/stage-1-reliability` dibuat dari HEAD di atas dan **berhasil di-push** ke origin di sesi ini.

## 2. Environment yang terpasang (mesin sandbox, bukan mesin lokal user)

| Komponen | Versi |
|---|---|
| PHP | 8.4.19 (CLI, NTS) |
| Composer | 2.8.12 |
| Node.js | v22.22.2 |
| npm | 10.9.7 |
| MySQL/MariaDB server | **tidak terpasang** — hanya driver PHP (`mysqli`, `pdo_mysql`) tersedia |
| Composer deps AuliaPos | ter-install dari `composer.lock` (`vendor/` dibangun di sesi ini) |
| npm deps WA-Gateway | ter-install dari `package.json` (217 paket, 3 moderate audit warning bawaan dependency) |

**Catatan penting:** environment ini tidak identik dengan mesin lokal user (XAMPP
+ MySQL + WhatsApp Gateway aktif). Versi di atas adalah versi sandbox eksekusi,
bukan versi mesin lokal user — user perlu mencatat versi mesin lokalnya sendiri
jika berbeda.

## 3. Hasil test suite existing

### WA-Gateway (`test/*.js` — skrip simulasi in-process, bukan test framework formal)
Tidak ada `npm test` script di `package.json`. Test yang ada adalah 6 skrip
simulasi manual di `test/`, dijalankan langsung dengan `node`:

| Skrip | Hasil |
|---|---|
| `simulate-audio-video.js` | PASS (exit 0) |
| `simulate-identity-hint.js` | PASS (exit 0) |
| `simulate-lid-conversation.js` | PASS (exit 0) — "SEMUA SIMULASI LOGIKA LULUS" |
| `simulate-send-media.js` | PASS (exit 0) — "SEMUA SIMULASI LOGIKA KIRIM MEDIA LULUS" |
| `simulate-sticker.js` | PASS (exit 0) — "SEMUA SIMULASI LOGIKA STICKER LULUS" |
| `simulate-tmpdir-override.js` | PASS (exit 0) — "SEMUA SIMULASI OVERRIDE os.tmpdir() LULUS" |

Semua skrip eksplisit mencatat sendiri bahwa ini **simulasi in-process**, bukan
pengujian terhadap koneksi WhatsApp/Baileys sungguhan (kecuali
`simulate-tmpdir-override.js` yang memang menjalankan child process Node dengan
Baileys asli, tapi tetap tanpa koneksi WhatsApp nyata).

### AuliaPos (`tests/unit/`)

**Temuan struktural:** dari 16 file di `tests/unit/`, hanya 12 file benar-benar
class `TestCase` (dijalankan lewat PHPUnit). 4 file lain
(`InboxMediaConfirmedGoneTest.php`, `InboxMediaFallbackTest.php`,
`InboxMediaStorageTest.php`, `InboxResponseStateManualTest.php`) adalah **skrip
PHP standalone** (assert manual + `exit()` di level top-level file, bukan class
`TestCase`). Karena PHPUnit meng-`require` tiap file saat scanning direktori
test, top-level `exit()` di file-file ini **menghentikan seluruh proses PHPUnit**
begitu file tersebut di-load — akibatnya `vendor/bin/phpunit tests/unit` yang
dijalankan apa adanya cuma menjalankan satu file lalu berhenti, TIDAK
menjalankan 15 file lainnya, tanpa pesan error yang jelas.

Ini bukan masalah database/environment — murni struktur file test yang
tercampur (PHPUnit TestCase vs skrip standalone). Dicatat sebagai baseline;
**tidak diperbaiki di Tahap 0** sesuai instruksi (perbaikan ada di scope lain).

Hasil aktual setelah dipisahkan manual di sesi ini:

- **12 file PHPUnit TestCase** (`tests/unit/`, dijalankan via `vendor/bin/phpunit -c phpunit.dist.xml tests/unit` dengan 4 file standalone dipindah sementara): **82 tests, 143 assertions, 0 failures.**
- **4 skrip standalone** (dijalankan langsung via `php tests/unit/<file>.php`): semua PASS —
  `InboxMediaConfirmedGoneTest.php` (8 PASS), `InboxMediaFallbackTest.php` (3 PASS),
  `InboxMediaStorageTest.php` (12 PASS), `InboxResponseStateManualTest.php` (6 PASS).

**Total: 82 PHPUnit test + 29 assert standalone, semua PASS, 0 FAIL.**

### `tests/database/` dan `tests/session/` (AuliaPos) — **SKIP**
Tidak dijalankan: environment sandbox ini tidak punya server MySQL/MariaDB
terpasang (hanya driver PHP), dan `database.tests.*` di `phpunit.dist.xml`
memang default nonaktif. Butuh mesin dengan DB `aulia_kasirdb` (atau DB test
terpisah) untuk dijalankan — ini scope mesin lokal user, bukan sandbox ini.

## 4. Smoke test Inbox — **tidak dapat dijalankan di sandbox ini**

Ketiga smoke test berikut (incoming WA → AuliaPos, outgoing AuliaPos → Gateway →
WhatsApp, fromMe=true handling) membutuhkan:
- Koneksi WhatsApp Gateway aktif (sesi Baileys ter-autentikasi ke akun WhatsApp
  nyata), dan
- Database `aulia_kasirdb` + `aulia_inboxdb` (MySQL/MariaDB) berjalan, dan
- AuliaPos ter-deploy dan dapat diakses oleh Gateway (HTTP callback).

Tidak satu pun dari ketiganya tersedia di sandbox eksekusi ini (tidak ada
MySQL server, tidak ada koneksi WhatsApp, tidak ada instance AuliaPos yang
di-serve). Smoke test ini **harus dijalankan di mesin lokal user** dengan
XAMPP + Gateway aktif, sesuai rencana asli di handoff.

Sebagai gantinya, verifikasi tidak langsung yang **bisa** dilakukan di sini:
- Fix `pushName` akun sendiri di commit `5b28eb6` (WA-Gateway) diverifikasi
  ada di HEAD branch `claude/android-app-p40bl1` yang sedang dipakai — commit
  message dan HEAD cocok dengan baseline handoff. Isi fix tidak dijalankan
  ulang di runtime nyata (butuh koneksi WA nyata), tapi source code fix
  tersebut ada di HEAD yang diverifikasi.
- `simulate-lid-conversation.js` dan `simulate-identity-hint.js` melakukan
  simulasi logic level pesan masuk/`fromMe` secara in-process dan PASS (lihat
  Bagian 3) — ini memvalidasi *logic*, bukan runtime end-to-end sungguhan.

## 5. Verifikasi runtime media — **sebagian dapat diverifikasi via source, tidak via runtime nyata**

- P2 (WebP selalu diklasifikasikan sebagai sticker): fungsi `isValidWebp()`
  dipakai di `src/whatsapp/mediaPayload.js`, `src/api/routes.js`, dan
  `src/api/ci4Routes.js`. `simulate-sticker.js` sendiri mencatat eksplisit di
  outputnya: `isValidWebp() SENGAJA cuma cek magic bytes, tidak memvalidasi`
  dimensi/ukuran yang disyaratkan WhatsApp untuk sticker valid — ini
  **dikonfirmasi masih ada di kode** (bukan sudah diperbaiki), sesuai catatan
  risiko P2 di handoff. Verifikasi lebih lanjut (WebP asli bukan-sticker
  terklasifikasi salah di runtime) butuh koneksi WhatsApp nyata — tidak
  dijalankan di sandbox ini.
- Upload/local storage fallback: dicek lewat unit test
  `InboxMediaStorageTest.php` dan `InboxMediaFallbackTest.php` (AuliaPos, 12 +
  3 assert, semua PASS) yang menguji logic fallback live-fetch saat file lokal
  hilang — tapi ini test logic terisolasi, bukan runtime penuh dengan disk
  I/O sungguhan atau Gateway nyata.

## 6. Kesimpulan Tahap 0

Yang **terverifikasi nyata** di sesi ini:
- Branch & HEAD kedua repo cocok baseline.
- Branch dev WA-Gateway dibuat & di-push.
- Working tree bersih di kedua repo.
- Versi environment sandbox tercatat (bukan mesin lokal user — lihat catatan Bagian 2).
- Seluruh test suite yang ADA (unit AuliaPos + simulasi WA-Gateway) dijalankan
  nyata: 82 PHPUnit test + 29 assert standalone (AuliaPos) + 6 skrip simulasi
  (WA-Gateway), **semua PASS**.
- Ditemukan bug struktural test discovery di `tests/unit/` AuliaPos (top-level
  `exit()` di 4 file menghentikan PHPUnit prematur) — dicatat, tidak diperbaiki.

Yang **tidak dapat diverifikasi** di sandbox ini (butuh mesin lokal user dengan
XAMPP, MySQL, dan Gateway WhatsApp aktif):
- `tests/database/` dan `tests/session/` AuliaPos (butuh DB).
- Smoke test incoming/outgoing/fromMe end-to-end (butuh DB + koneksi WA nyata).
- Verifikasi runtime P2 (WebP/sticker misclassification) terhadap WhatsApp
  sungguhan.

**Tahap 0 selesai secara parsial**: semua langkah yang bisa dieksekusi di
sandbox ini sudah dijalankan dan dicatat apa adanya. Tiga smoke test dan dua
test suite yang butuh DB/koneksi WA nyata **harus dijalankan oleh user di
mesin lokal** sebelum Tahap 0 benar-benar dinyatakan DONE secara penuh. Sampai
saat itu, mulai coding Tahap 1 (M1 Reliability) belum direkomendasikan untuk
risiko P0 yang menyentuh langsung enqueue/queue/idempotency, karena baseline
runtime nyata (bukan simulasi) belum ada.
