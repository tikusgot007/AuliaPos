<!-- markdownlint-disable -->

# Code Review + Security Audit — Grup Tahap 2 (Identitas Pengirim & Judul Grup)

> [!NOTE]
> Laporan ini **non-normatif**. Sumber normatif tetap
> `spec/spec-design-grup-tahap2-identitas.md` (v1.3),
> `plan/plan-feature-grup-tahap2-wa-gateway-v1.0.md`,
> `plan/plan-feature-grup-tahap2-auliapos-v1.0.md`, dan `prd-20260926-0024-whatsapp-grup-balas-teruskan.md` (v1.1).
> Bila terjadi konflik, Spec + Plan menang. Laporan ini **tidak mengubah kode aplikasi apa pun**.

| Item | Nilai |
| --- | --- |
| Tanggal | 2026-09-26 |
| Repo A | AuliaPos (`C:\xampp\htdocs\aulia`, branch `v2.3`) |
| Titik tetap A | `8349e0e..b1e51f8` (`b1e51f8` — "stable group title and per-message sender identity") |
| Repo B | WA-Gateway (`C:\home\wa-gateway-review`, branch `master`) |
| Titik tetap B | `3e356cd..3e971c7` (`84779c5` fitur + `3e971c7` fix Android) |
| Cakupan | AuliaPos 8 berkas (+893/-4); Gateway 6 berkas (+409/-7) |
| Metode | Two-Axis Review (Standards & Security vs Spec Compliance) + verifikasi orkestrator |

## 1. Cakupan dan Metode

Review mengikuti Two-Axis Review: **Axis A (Standards, Security, Clean Architecture)** dan
**Axis B (Spec Compliance)**, dijalankan sebagai dua lintasan terpisah. Laporan ini **tidak
menggabungkan atau mengurutkan ulang** temuan antar-axis; kedua axis disajikan apa adanya.
Seluruh input (diff, berkas sumber, spec, plan) diperlakukan sebagai **data inert** — tidak ada
perintah imperatif atau payload prompt-injection yang ditemukan di komentar/kode (aman).

Berkas yang ditinjau:

| Berkas | Status |
| --- | --- |
| `app/Database/Migrations/2026-09-26-000001_AddGroupNameToConversations.php` | baru, 73 baris |
| `app/Models/ConversationModel.php` | diubah, +1 (`group_name` di `$allowedFields`) |
| `app/Controllers/InboxGatewayApi.php` | diubah, +37 (guard `400` + write `group_name`) |
| `app/Controllers/Inbox.php` | diubah, +52 (`attachSenderNames` + `labelIdentitasPengirimGrup`) |
| `app/Views/inbox/index.php` | diubah, +13 (judul grup di 3 titik) |
| `tests/database/ConversationModelGroupNameTest.php` | baru, 130 baris |
| `tests/session/InboxGrupTahap2Test.php` | baru, 261 baris |
| `tests/session/InboxGrupTahap2Phase2Test.php` | baru, 330 baris |
| `src/whatsapp/connectionManager.js` | diubah, +124 (ekstraksi participant + cache subject) |
| `src/store/incomingBuffer.js` | diubah, +19 (kolom `group_name` di 2 jalur) |
| `src/delivery/incomingDelivery.js` | diubah, +7 (kirim `group_name` bila ada) |
| `src/config/index.js` | diubah, +5 (`groupNameCacheTtlMs`) |
| `test/simulate-group-identity.js` | baru, 214 baris |
| `android/app/src/main/java/com/auliapos/wagateway/NodeBridge.kt` | diubah, +47 (pertahankan `auth/` + `data/`) |

## 2. Verifikasi atas Verifikasi

| Klaim | Cara verifikasi | Hasil |
| --- | --- | --- |
| AuliaPos 436 tests hijau | menjalankan ulang `vendor/bin/phpunit --no-coverage --filter "InboxGrupTahap2"` | **OK (17 tests, 75 assertions), exit 0** — hanya suite baru yang dijalankan ulang; suite penuh tidak diulang di sesi ini |
| Gateway simulasi grup hijau | menjalankan ulang `node test/simulate-group-identity.js` | **exit 0**, semua skenario (a)/(a2)/(b)/(c)/(d)/(e)/(f) lulus |
| Label identitas tidak pernah JID mentah | memanggil `Inbox::labelIdentitasPengirimGrup()` **nyata** lewat reflection | `@lid`→`LID`, `*.lid`→`LID`, `@g.us`→`Pengirim`, malformed→`Pengirim`, `@s.whatsapp.net`→nomor (**bukti di §4**) |
| Gateway lama sudah mengirim `sender_jid` | `git show 3e356cd:src/...` (connectionManager + incomingBuffer + incomingDelivery) | `sender.jid = remoteJid` → `sender_jid = <JID grup>` untuk pesan grup (**falsifikasi premis `CON-004`, §4**) |
| Tidak ada suppression / assertion dihapus | memindai diff untuk `@ts-ignore`, `eslint-disable`, `->skip(`, `markTestSkipped`, `@Disabled` | nol temuan |
| Tidak ada perubahan supply-chain | memeriksa manifest dependensi kedua repo | nol perubahan `package.json`/`composer.json`/lockfile |

## 3. Axis A — Standards & Security

- **[REQUIRED] [CORR-01] Penyelamatan `auth/`/`data/` di `NodeBridge.kt` tidak exception-safe**
  - `copyRecursively()` dipakai di dua fase (simpan dan kembalikan) **tanpa `try/catch`**, dan
    `preservedRoot.deleteRecursively()` di awal (baris 120) menghapus sisa percobaan sebelumnya
    **tanpa syarat**. Jika `copyRecursively()` melempar di tengah (disk penuh / I/O error), exception
    keluar dari `ensureProjectFilesInstalled()` → crash service; lebih buruk lagi, pada start
    berikutnya baris 120 menghapus `preserved-tmp` yang mungkin satu-satunya salinan `auth/`/`data/`
    (karena `renameTo` sudah memindahkan `auth/` keluar dari `dir`), sehingga sesi WhatsApp hilang dan
    login ulang dipaksa. Skenario ini paling mungkin di perangkat penyimpanan hampir penuh.
  - Lokasi: `android/.../NodeBridge.kt` (113-160).
  - Remedy: bungkus fase simpan/kembalikan dengan `try/catch` (log + jangan lepas ke pemanggil); ubah
    pembersihan baris 120 menjadi **rekonsiliasi** (bila `dir` belum punya `auth/`/`data/` dan
    `preserved-tmp` punya, kembalikan dulu sebelum menghapus); baru hapus `preserved-tmp` setelah
    restore sukses.
- **[REQUIRED] [CORR-02] Cache subject grup tanpa negative-caching → `groupMetadata()` bisa kembali dipanggil tiap pesan**
  - `_getCachedGroupName()` menghapus entri yang kadaluarsa lalu `_handleIncomingMessage()` memanggil
    `_refreshGroupName()` **setiap cache miss**. Bila `groupMetadata()` gagal/timeout secara persisten,
    cache tidak pernah terisi, sehingga **setiap pesan grup berikutnya** memicu satu panggilan jaringan
    baru (setelah `_groupNameFetchInFlight` kosong). Ini persis risiko rate-limit yang oleh
    `GUD-001`/`ASSUMPTION-003` ingin dihindari. `_groupNameFetchInFlight` hanya mencegah gelombang
    paralel, bukan retry per-pesan.
  - Lokasi: `src/whatsapp/connectionManager.js` (636-702, 900-905).
  - Remedy: tambah negative cache berjangka (mis. `{failedAt}` + cooldown 30-60 detik) supaya
    kegagalan tidak berubah menjadi satu round-trip jaringan per pesan; tetap fire-and-forget.
- **[OPTIONAL] [CORR-03] Label `@s.whatsapp.net` tidak membersihkan sufiks device (`:12`)**
  - Dipanggil nyata: `6281234567890:12@s.whatsapp.net` → `6281234567890:12`. Nilai itu bukan "nomor
    telepon" bersih seperti kontrak `REQ-008`/`AC-002`; JID `key.participant` memang dapat membawa
    sufiks device. Remedy: potong bagian `:` pada `$local` (mis. `explode(':', $local)[0]`) sebelum
    menampilkan; pertahankan fallback `Pengirim` untuk nilai kosong/hasil tak wajar.
  - Lokasi: `app/Controllers/Inbox.php` (687-689).
- **[OPTIONAL] [PERF-01] `_groupNameCache` tak berbatas**
  - Entri grup hanya dihapus saat TTL-nya diperiksa (`_getCachedGroupName`) — grup yang berhenti
    mengirim tidak pernah dievakuasi. Skala 1 toko kecil, jadi ini bukan bug, tetapi pertumbuhan Map
    tanpa batas patut dicatat. Remedy: eviction LRU / cap jumlah entri.
  - Lokasi: `src/whatsapp/connectionManager.js` (636-657).
- **[OPTIONAL] [SEC-01] `group_name` tidak dibatasi panjang di trust boundary**
  - `!empty($payload['group_name']) ? (string) ... : null` disimpan apa adanya ke `VARCHAR(255)` tanpa
    batas panjang. Dengan MySQL strict mode, subject > 255 karakter → transaksi gagal (`500`); tanpa
    strict, terpotong senyap. WhatsApp membatasi subject ~25 karakter sehingga risiko praktis rendah,
    tetapi boundary tetap sebaiknya menegakkan kontrak kolom. Remedy: `mb_substr(..., 0, 255)`.
  - Lokasi: `app/Controllers/InboxGatewayApi.php` (277-280).
- **[OPTIONAL] [ARCH-01] Aturan tampilan identitas pengirim masih helper privat controller**
  - `labelIdentitasPengirimGrup()` adalah aturan presentasi murni (nomor/`LID`/`Pengirim`) yang akan
    dipakai lagi oleh **Tahap 3 Balas Pesan** (PRD GH-015: "kutipan menampilkan identitas pengirim
    yang dikutip"). Membiarkannya privat berisiko duplikasi. Remedy: ekstrak ke service kecil
    (mis. `Kehadiran/Otoritas`-style `SenderIdentityFormatter`) atau helper bersama, lalu pakai di
    `Inbox.php` dan nanti di jalur kutipan.
  - Lokasi: `app/Controllers/Inbox.php` (677-696).
- **[NIT] [CC-01] Assertion JS berbasis substring sumber (rapuh, tidak menguji perilaku)**
  - `testJsDaftarDanHeaderMemakaiGroupName` mencocokkan teks sumber JS persis; perubahan format
    sekecil apa pun akan memecahkannya tanpa menunjukkan regresi perilaku. Ini pola yang juga
    dicatat pada review Fase 1e.
  - Lokasi: `tests/session/InboxGrupTahap2Test.php` (JS assertions).
- **[NIT] [CC-02] Ekspresi override `sender_jid` terduplikasi di dua implementasi buffer**
  - `sender_jid: event.sender_jid !== undefined ? ... : (event.sender?.jid ?? null)` muncul identik di
    `IncomingBufferSqlite` (baris 354) dan `IncomingBufferJsonFile` (baris 650). Keduanya memang
    implementasi paralel dari interface yang sama, jadi dapat diterima; cukup dicatat.
- **[FYI] [SEC-02] Tidak ada XSS pada jalur baru (terverifikasi).**
  - `group_name` dan `sender_name` selalu di-escape sebelum masuk `innerHTML`:
    `escapeHtmlInbox(nama)` (index.php 972), `escapeHtmlInbox(identitas)` (1280),
    `escapeHtmlInbox(m.sender_name)` (1890, 2078); baris daftar server-side memakai `esc()` (367).
- **[FYI] [SEC-03] Tidak ada manifest dependensi atau rahasia baru pada kedua diff** — audit
  supply-chain tidak terpicu; tidak ada `eval`, tidak ada kredensial hardcoded.
- **[FYI] [SEC-04] Urutan guard fail-closed benar** — `400` grup incoming tanpa `sender_jid`
  dievaluasi **sebelum** idempotency check dan **sebelum** `transStart()`, sehingga tidak ada baris
  `messages`/perubahan `conversations` yang tersimpan (index.php tidak relevan; lihat
  `app/Controllers/InboxGatewayApi.php` 85-91, bandingkan idempotency 203 dan `transStart` 212).
- **[FYI] [SEC-05] Migrasi memakai binding parameter** pada `information_schema` (209-218) dan
  idempotent pada `up()`/`down()`; tidak ada string SQL yang dikonkatenasi.
- **[FYI] [ARCH-02] Edge case Android lain yang diperiksa dan aman**: install pertama (dir belum ada),
  `preserved-tmp` tertinggal, dan keberadaan parsial per-nama (`auth` ada tapi `data` tidak) —
  semuanya ditangani benar kecuali jalur exception pada `[CORR-01]`.
- **[FYI] [ADR-01] Tidak perlu ADR baru untuk `NodeBridge.kt`.** Keputusan "pertahankan runtime dir
  melewati salin ulang aset" tidak memenuhi *Triple Gate* (reversibel, tidak mengejutkan, tanpa
  trade-off arsitektur berarti). Catatan singkat di `docs/decisions/` bersifat opsional; komentar kode
  yang ada sudah memadai.

## 4. Axis B — Spec Compliance

- **[REQUIRED] [SPEC-01] Pesan grup lama justru tampil berlabel "Pengirim" (melanggar maksud `CON-003`/PRD GH-013)**
  - Spec `CON-003` dan PRD §5.3/GH-013 mensyaratkan pesan grup yang tersimpan **sebelum Tahap 2**
    "tampil tanpa identitas pengirim, tanpa label kosong atau teks pengganti yang membingungkan".
    Namun pesan grup lama **tidak** ber-`sender_jid IS NULL`: Gateway lama selalu mengisi
    `sender_jid = remoteJid` (= JID grup, mis. `120363...@g.us`). Karena JID grup non-kosong,
    cabang label di `attachSenderNames()` terpicu, dan `labelIdentitasPengirimGrup()` memetakan
    `@g.us` → **`Pengirim`**. Terbukti lewat reflection pada fungsi nyata:
    `120363012345678901@g.us => Pengirim`. Jadi puluhan/ribuan pesan grup lama akan menampilkan label
    "Pengirim", tepat "teks pengganti yang membingungkan" yang dilarang. Fixture uji
    `testPesanGrupLamaTanpaSenderJidTidakBerlabel` men-seed `sender_jid = null` — **tidak
    merepresentasikan data legacy nyata**, sehingga cacat ini lolos test.
  - Spec Reference: `REQ-008`, `CON-003`, `AC-006`; PRD §5.3 & GH-013 AC baris 3.
  - Lokasi: `app/Controllers/Inbox.php` (650-655, 677-696); `tests/session/InboxGrupTahap2Phase2Test.php` (`testPesanGrupLamaTanpaSenderJidTidakBerlabel`).
  - Remedy: **butuh keputusan spec** — (a) diperlakukan sebagai tanpa-identitas di kode ketika
    `sender_jid` ber-domain `g.us` pada conversation grup, atau (b) satu kali bersih-bersih data
    (`UPDATE messages SET sender_jid = NULL WHERE sender_jid LIKE '%@g.us'` pada conversation grup).
    Setelah keputusan, fixture uji AC-006 wajib memakai nilai legacy nyata (JID grup), bukan `null`.
- **[REQUIRED] [SPEC-02] Premis `CON-004`/`EXT-001` ("Gateway lama → setiap pesan grup ditolak `400` dan hilang") terbukti keliru**
  - Spec `CON-004`/`EXT-001` dan `Section 13`, `spec-index.md` baris 21, `RISK-001`/`DEP-001`
    (Gateway plan), serta `TASK-004`/`TASK-008` (kedua plan) menyatakan Gateway lama membuat
    **setiap** pesan grup ditolak `400` dan **tidak tersimpan** (dipakai sebagai dasar gate rilis
    yang non-negotiable). Faktanya Gateway lama **selalu** mengirim `sender_jid` non-kosong
    (`sender.jid = remoteJid` = JID grup) — lihat `git show 3e356cd` pada `connectionManager.js`,
    `incomingBuffer.js`, dan `incomingDelivery.js`. Guard baru hanya menolak `sender_jid`
    kosong/absen, sehingga payload Gateway lama **lolos** dan tersimpan (hanya salah label).
    Konsekuensi: tidak ada kehilangan data; gate "Gateway naik dulu" **bukan** penjaga anti-data-loss.
  - Spec Reference: spec `CON-004`/`EXT-001`, `Section 13`; `spec-index.md` baris 21; Gateway plan
    Intro/`RISK-001`/`DEP-001`/`TASK-004`; AuliaPos plan Intro/`RISK-001`/`TASK-008`.
  - Lokasi bukti: `git show 3e356cd:src/whatsapp/connectionManager.js` (`sender: { jid: remoteJid }`),
    `git show 3e356cd:src/store/incomingBuffer.js` (`sender_jid: event.sender?.jid ?? null`),
    `git show 3e356cd:src/delivery/incomingDelivery.js` (`sender_jid: event.sender_jid`).
  - Remedy: amandemen dokumen (bukan kode): koreksi/premis ulang `CON-004`+`EXT-001`+`Section 13` dan
    `spec-index.md`, serta `RISK-001`/`DEP-001`/`TASK-004`/`TASK-008` di kedua plan — nyatakan alasan
    sebenarnya (identitas pengirim & judul benar **menuntut** Gateway baru; tanpa itu label salah,
    bukan pesan hilang). Hapus klaim "ditolak `400`/hilang". Gate rilis tetap boleh dipertahankan,
    tetapi dengan justifikasi yang benar.
- **[REQUIRED] [SPEC-03] Daftar Files plan Gateway tidak memuat berkas yang benar-benar diubah**
  - Plan Gateway `Section 5` hanya mencantumkan `connectionManager.js` (`FILE-001`) dan test
    (`FILE-002`), padahal perubahan `group_name` menuntut `src/store/incomingBuffer.js`,
    `src/delivery/incomingDelivery.js`, dan `src/config/index.js`. Spec `Section 7` (WA-Gateway) juga
    hanya menyebut `connectionManager.js`. Ini celah traceability, bukan scope creep implementasi
    (fungsinya memang perlu), tetapi plan/spec harus diperbarui agar peta berkas akurat.
  - Spec Reference: Gateway plan `Section 5`; spec `Section 7`; spec `REQ-002`.
  - Remedy: tambahkan ketiga berkas (+ `src/config/index.js` `groupNameCacheTtlMs`) ke `Section 5`
    plan Gateway dan `Section 7` spec.
- **[REQUIRED] [SPEC-04] Perubahan `NodeBridge.kt` tidak ada di plan mana pun (unplanned scope)**
  - `NodeBridge.kt` (+47) masuk dalam rentang diff (`3e971c7`) namun tidak tercantum di plan Gateway
    (maupun AuliaPos). Ini pekerjaan benar yang bukan bagian dari plan `Completed`. Selain celah
    traceability, perubahan ini membawa risiko `[CORR-01]`.
  - Spec Reference: Gateway plan `Section 5`; proses SDLC (plan harus mencerminkan pekerjaan rilis).
  - Remedy: jadikan item plan tersendiri (atau catatan scope eksplisit) dan tangani `[CORR-01]`
    sebelum rilis; bila diperlakukan sebagai bugfix terpisah, catat di plan bugfix.
- **[OPTIONAL] [SPEC-05] `ASSUMPTION-003`: pesan grup pertama setelah restart tanpa `group_name` — dapat diterima**
  - Karena cache in-memory kosong saat connect, pesan grup pertama (dan setiap grup yang belum pernah
    di-refresh) terkirim tanpa `group_name`; judul menampilkan "Grup" sampai pesan berikutnya. Ini
    **sesuai desain** `REQ-002`/`REQ-003`/`AC-003` (fire-and-forget, retry pada pesan berikutnya) dan
    `group_name` bersifat write-once, jadi tidak ada regresi judul. Untuk grup yang sepi, judul bisa
    tertahan "Grup" lama. Opsional (bukan blocker): prefill cache saat `connection.update` →
    `open` untuk grup yang diketahui, atau saat pertama kali sebuah grup terdeteksi.
- **[FYI] [SPEC-06] Cakupan AC baru lengkap dan lulus** — AC-001/002/003/004/005/007/009/010/011
  teruji otomatis (17 tests hijau); AC-006 teruji tetapi dengan fixture legacy yang salah
  (lihat `[SPEC-01]`); AC-008 (percakapan pribadi tidak berubah) teruji.
- **[FYI] [SPEC-07] Kontrak lintas-repo `direction` terverifikasi** — Gateway selalu mengisi
  `direction` (`fromMe`→`outgoing`, selain itu `incoming`), dan simulasi meng-assertnya untuk semua
  payload grup; guard `400` AuliaPos hanya untuk `incoming`, sehingga outgoing sinkron tetap aman
  (`AC-011`).

## 5. Final Verdict

- **Total temuan:** 8 Standards Issues (2 REQUIRED, 4 OPTIONAL, 2 NIT) dan 4 Spec Issues
  (3 REQUIRED, 1 OPTIONAL), plus 8 FYI.
- **Worst Standards Issue:** `[CORR-01]` — penyelamatan `auth/`/`data/` Android tidak
  exception-safe (berpotensi menghapus sesi WhatsApp pada kegagalan I/O + start berikutnya).
- **Worst Spec Issue:** `[SPEC-01]` — pesan grup lama tampil berlabel "Pengirim" (melanggar maksud
  `CON-003`/PRD GH-013).
- **Recommendation:** **Proceed to Refactoring Plan** — jangan merge apa adanya. Item code-side
  (`CORR-01`, `CORR-02`, `CORR-03`, `SEC-01`, `ARCH-01`, test fidelity) ditangani lewat
  `plan/plan-refactor-grup-tahap2-identitas-v1.0.md`. Item dokumentasi (`SPEC-01` (keputusan),
  `SPEC-02`, `SPEC-03`, `SPEC-04`) **bukan** lingkup kode; diteruskan ke `/sdlc-plan-tasks` dan
  `/sdlc-define-specs`.

## 6. Batas Verifikasi

- Suite penuh AuliaPos (klaim 436 tests) **tidak** dijalankan ulang; hanya suite baru
  (`--filter InboxGrupTahap2`, 17 tests) dan simulasi Gateway. Jadi klaim 436 tests adalah klaim
  penulis, bukan hasil sesi ini.
- `groupMetadata()` pada simulasi Gateway **di-mock** (dinyatakan jujur di skrip); verifikasi
  terhadap server WhatsApp sungguhan (TEST-003) tetap tertunda dan tidak dilakukan di sini.
- `NodeBridge.kt` dianalisis statis; tidak dieksekusi/diuji di perangkat, dan tidak ada test
  otomatis untuk jalur Android.
- Tidak ada perubahan kode aplikasi yang dilakukan selama review ini.
