# Checkpoint Sesi

- **Tanggal**: 2026-10-10
- **Status**: selesai (kode + test terverifikasi; belum di-commit)
- **Repo / branch**: AuliaPos `v2.4` (uncommitted); `evolution-gateway` `evolution` (uncommitted)

## Selesai

- **TODO-Q3** — Kontrak CI4 ↔ Gateway (`evolution-gateway`) kini punya test
  formal dua sisi untuk bentuk payload HTTP, menutup gap yang ditemukan saat
  riset sesi ini: semua test CI4 sebelumnya memakai fake subclass yang
  meng-override `callGateway*()` seluruhnya, jadi tidak ada satupun yang
  memverifikasi JSON asli yang keluar; begitu juga sebaliknya, test gateway
  selalu memakai `postToCI4` yang di-mock.
  - **Refactor extract-method (behavior-preserving)** di `app/Controllers/Inbox.php`:
    dipisah logika "bangun payload" dari "eksekusi cURL" — method baru
    `buildSendPayload()`, `buildSendMediaPayload()`, `buildDeletePayload()`,
    `buildEditPayload()`, `buildMarkReadPayload()` (semua `protected`, murni,
    tanpa HTTP/DB/session). `callGatewaySend()` dkk sekarang hanya memanggil
    builder lalu kirim — perilaku identik, diverifikasi oleh regression suite
    penuh sebelum & sesudah (unit 95, feature 141→146, integration 26, semua
    lulus tanpa perubahan hasil kecuali test baru).
  - **Test unit baru (CI4)** — `tests/unit/InboxGatewayPayloadContractTest.php`
    (13 test): memanggil builder lewat Reflection, assert bentuk JSON persis
    untuk `/send`, `/send-media`, `/delete`, `/edit`, `/read` — key wajib
    selalu ada, key opsional (`operation_id`, `quoted`, `forward`) hanya ada
    saat diminta, termasuk kasus khusus `/edit` yang WAJIB mengirim key
    `operation_id` walau nilainya `null` (beda dari `/send`/`/delete` yang
    opsional).
  - **Test Node baru (Gateway)** — `C:\Projects\evolution-gateway\test\test-contract-ci4.js`
    (18 assertion section, ditambahkan ke `npm test`): fixture LITERAL yang
    sama persis dengan test PHP dikirim ke `ci4Routes.js` via Express asli
    (`evolutionClient` di-stub, pola sama dengan `simulate-evolution-adapter.js`),
    memverifikasi error code yang didokumentasikan benar-benar dikembalikan
    (`OPERATION_ID_REUSED`, `FORWARD_WITH_QUOTED`, `MISSING_OPERATION_ID`,
    `MISSING_FILE_NAME`). Arah sebaliknya (Gateway→CI4) diverifikasi dengan
    memanggil `deliverOne()`/`deliverLifecycle()`/`deliverStatus()` langsung
    dan memeriksa body yang dikirim ke `postToCI4` (mock) cocok field yang
    divalidasi `InboxGatewayApi.php` — termasuk field opsional (`group_name`,
    `quoted`, `extra`, `is_forwarded`) yang HARUS absen (bukan `null`) saat
    tidak diminta.
  - **`sendHeartbeat()`**: tidak bisa di-mock langsung (import destructured
    top-level di `heartbeat.js`) — diverifikasi secara statis (baca source +
    cocokkan daftar status terhadap `$validStatuses` CI4). Didokumentasikan
    sebagai keterbatasan yang disengaja, bukan false-positive.
  - **Gap kecil tambahan** — `tests/feature/GatewayTokenFilterTest.php` (5
    test): auth `Bearer` token diuji langsung (header absen, format salah,
    token salah, token kosong, token benar), sebelumnya hanya diuji implisit
    lewat `GatewayApiTestTrait` yang selalu mengirim token valid.

## Keputusan penting

- Pendekatan dipilih **tanpa dependency JSON Schema baru** di kedua repo
  (YAGNI) — fixture literal diduplikasi dengan sengaja di kedua repo test
  (keduanya git repo terpisah, tidak ada shared path), bukan satu file
  "kontrak" fisik bersama.
- `/media/download` (respons binary, bukan JSON) **sengaja tidak di-test** di
  sesi ini — dicatat sebagai gap terpisah, bukan diam-diam diabaikan.
- Tidak ada test end-to-end HTTP nyata lintas proses (CI4 asli memanggil
  gateway asli via network) — tetap di luar scope (lihat risiko di bawah).

## Tersisa

Tidak ada TODO baru dari pekerjaan ini — TODO-Q3 di `docs/TODO.md` sudah
boleh dipertimbangkan untuk dipindah ke "Selesai/Ditutup" setelah commit +
review user (bagian "belum ada test kontrak formal dua sisi" kini tertutup).

## Belum diverifikasi / risiko

- Semua test kontrak baru berjalan di kedua repo **secara terpisah** —
  belum ada CI/pipeline yang menjalankan keduanya bersamaan dan gagal
  otomatis bila salah satu diubah tanpa yang lain (risiko drift fixture
  literal antara dua repo tetap ada, hanya lebih kecil peluangnya sekarang).
- `/media/download` tidak tertest formal (lihat di atas).
- Perubahan BELUM di-commit di kedua repo.

## Titik masuk sesi berikutnya

- **Baca**: `app/Controllers/Inbox.php` (method `build*Payload()` baru,
  dekat `callGatewaySend()` dkk), `tests/unit/InboxGatewayPayloadContractTest.php`,
  `C:\Projects\evolution-gateway\test\test-contract-ci4.js`.
- **Jalankan**: `php vendor/bin/phpunit` (unit+feature+integration via 3
  config file) di aulia; `npm test` di `evolution-gateway`. Lalu bila
  disetujui: commit kedua repo, pertimbangkan pindah TODO-Q3 ke "Selesai".
