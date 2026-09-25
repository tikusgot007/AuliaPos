# Walkthrough — M3 Fase 1e: Remediasi Temuan Code Review

> [!IMPORTANT]
> **Dokumen ini non-normatif.** Sumber normatif tetap `spec/spec-design-m3-operational-inbox-fase1.md` (rev 1.4) dan `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` (v1.4, `Completed`). Rencana pelaksana remediasi ini adalah `plan/plan-refactor-m3-fase1e-message-search-v1.0.md` (v1.0). Bila terjadi konflik, Spec + Plan menang.

## 1. Ringkasan

Remediasi atas `docs/audit/code-review-m3-fase1e-2026-09-25.md`, yang menemukan **2 temuan `[REQUIRED]`** pada Fase 1e (7 berkas, +1139 / -8, rentang `0f22806^..bd00c84`):

| Temuan | Akibat bagi pemakai | Status |
| --- | --- | --- |
| `[SPEC-01]` pola LIKE ter-escape dua kali | Kata kunci yang memuat tanda petik (`'`), tanda petik ganda (`"`), atau backslash (`\`) **selalu menghasilkan nol hasil, tanpa pesan error** — padahal itu teks percakapan yang lazim di Indonesia (`Jum'at`, `Assalamu'alaikum`) | **SELESAI** (Fase 1) |
| `[CORR-01]` pengaman putaran milik bersama | Putaran lama yang selesai lebih dulu dapat melepas pengaman milik putaran baru, sehingga tick 6 detik memulai putaran ketiga — persis penumpukan request yang ingin dicegah REQ-017b | **SELESAI** (Fase 2) |

Dua temuan `[NIT]` yang menyertai keduanya ikut tertutup: `[SPEC-02]` (putaran kata kunci berulang) dan `[CORR-03]` (pengaman dapat tertinggal `true` selamanya).

**Tidak ada** perubahan pada boundary CON-004: tanpa migration, index, FULLTEXT, parameter kueri baru, endpoint baru, panggilan Gateway, atau penulisan data.

Dua fase dikirim sebagai dua commit terpisah, sesuai DEP-005, supaya perbaikan predikat dan perbaikan frontend dapat dibatalkan sendiri-sendiri:

| Fase | Commit | Perubahan |
| --- | --- | --- |
| 1 | `2a538af` | 2 berkas, +109 / -4 |
| 2 | `0919960` | 1 berkas, +40 / -3 |

Status rencana: **Completed** — `plan/plan-refactor-m3-fase1e-message-search-v1.0.md` §8. Fase 3 ditolak pemilik proyek dan dipindahkan menjadi TODO berjalan.

## 2. Fase 1 — predikat escape-once (`app/Controllers/Inbox.php`)

### 2.1 Akar masalah, dinyatakan tepat

`escapeLikeString()` **sudah** melakukan satu escape SQL penuh (`escapeString($q, true)` → `_escapeString()` → mysqli `real_escape_string`), lalu meng-escape metakarakter LIKE. Nilai itu kemudian di-bind lewat `$db->query($sql, [$nilai])`, dan mesin bind meng-escape-nya **sekali lagi** di `Query::matchSimpleBinds()` (`Query.php:305` → `$this->db->escape()`).

Untuk `q = "Jum'at"`, nilai yang sampai ke SQL menjadi `%Jum\\\'at%` — di bawah `ESCAPE '!'` itu menuntut backslash **literal** di dalam `messages.text`, yang tidak dimiliki pesan nyata mana pun. Hasilnya nol, tanpa error.

Perbaikannya bukan preferensi gaya: dua urusan escaping dipisahkan supaya masing-masing terjadi **tepat sekali**.

| Urusan | Penanggung jawab |
| --- | --- |
| Kutip string SQL | bind engine (sekali) |
| Metakarakter LIKE (`!`, `%`, `_`) | pemanggil, lewat `strtr()` (sekali) |

`strtr()` dengan map adalah satu lintasan dan **tidak memindai ulang hasilnya sendiri**, sehingga `!` → `!!` tidak berlanjut menjadi `!!!!`. Tiga `str_replace()` berurutan justru salah karena alasan itu. Klausa `ESCAPE '!'`, subkueri `ROW_NUMBER()`, dan teks SQL lainnya **byte-identical**.

### 2.2 Kunci regresi (`tests/session/OperationalInboxConversationTest.php`, +86 / **-0**)

Lima kasus baru, masing-masing dengan kontrol negatifnya sendiri:

| Kasus | `q` | Harapan | Mengunci |
| --- | --- | --- | --- |
| (j) | `it's` | conversation ditemukan | cacat `[SPEC-01]` sendiri |
| (k) | `its` | **tidak** ditemukan | kontrol negatif: apostrof tidak diabaikan |
| (l) | `50%` | cocok `diskon 50%`, tidak cocok `diskon 500` | CL-008 / AC-014h tetap utuh |
| (m) | `lumayan!` | cocok `lumayan!`, tidak cocok `lumayan` | escape char `!` tetap benar |
| (n) | `C:\foto` | cocok literal `C:\foto` | backslash tetap literal |

Bukti RED sebelum perbaikan: kasus (j) dan (n) **gagal**; (k), (l), (m) sudah lulus — persis seperti yang diramalkan, karena `!`, `%`, dan `_` memang selamat dari escape ganda. Sesudah perbaikan: `OK (5 tests, 19 assertions)`.

### 2.3 Konfirmasi read-only pada database live

Skrip `build/phase1-live-check.php` menarik teks SQL **langsung dari berkas sumber** (potongan literal dilepas escape PHP-nya lalu digabung), sehingga yang diuji adalah SQL yang benar-benar dikirim. Seluruh operasi `SELECT`.

| Uji | Hasil |
| --- | --- |
| SQL yang dijalankan identik dengan `cariPesanCocok()` | YA (407 karakter) |
| DB / driver / ENVIRONMENT | `aulia_inboxdb` / MySQLi / production |
| Pesan live (`deleted_at IS NULL`) | 153 |
| Pesan live memuat tanda petik | 2 (`id=220`, `id=229`, keduanya `conversation_id = 11750`) |
| `q = "Jum'at"` — SQL final **lama** | `LIKE '%Jum\\\'at%'` → **0 pesan** |
| `q = "Jum'at"` — SQL final **baru** | `LIKE '%Jum\'at%'` → **2 pesan / 1 conversation** |
| `cariPesanCocok("Jum'at")` dipanggil langsung (reflection) | **1 conversation** |
| Operasi tulis | tidak ada |

Tiga backslash versus satu backslash di dua baris SQL final itu adalah bukti telanjangnya.

> [!NOTE]
> **Penyimpangan dari tabel di laporan review — dilaporkan apa adanya, tidak ditutup-tutupi.** Tabel `[SPEC-01]` memakai contoh `q = "it's"` dan mencatat hasil `1`. Pada `aulia_inboxdb` hari ini **tidak ada satu pun pesan yang memuat `it's`** (0 dari 153), jadi angka itu tidak dapat direproduksi. Skrip bukti karena itu **mencari sendiri** kata kunci berapostrof yang benar-benar ada di data (`Jum'at`, `JUM'AT`) dan membuktikan cacat yang sama di sana: 0 → 2. Ini bukti yang lebih kuat karena memakai data nyata, bukan contoh yang sudah tidak ada.

## 3. Fase 2 — kepemilikan pengaman putaran (`app/Views/inbox/index.php`, +40 / -3)

### 3.1 Yang berubah, semuanya di dalam `muatUlangDaftarConversation()`

1. **Identitas putaran.** Satu penghitung lingkup-modul `let putaranDaftarTerakhir = 0;` di samping `putaranDaftarBerjalan`. Setiap putaran mengambil `const giliran = ++putaranDaftarTerakhir;` **sebelum** putaran mulai (RISK-003: kalau diambil sesudah, putaran yang sudah kalah bisa keburu memakai nomor yang sama).
2. **Pelepasan berdasarkan identitas.** `.finally()` melepas pengaman **hanya bila** `giliran === putaranDaftarTerakhir`. Putaran yang sudah dikalahkan kata kunci baru membiarkannya.
3. **Prelude tahan-lempar (TASK-202).** `ambilSemuaConversation()` dapat melempar **sinkron** lewat `encodeURIComponent()` (`URIError` pada lone surrogate). Dulu itu terjadi sebelum promise ada, sehingga `.finally()` tidak pernah terpasang dan pengaman tertinggal `true` selamanya. Kini promise-nya ditampung lebih dulu di dalam `try/catch`; jalur `catch` melepas pengaman dan meneruskan galat ke `saatGagal` — melaporkan, bukan menelan.

Yang **tidak** disentuh: kondisi `if (putaranDaftarBerjalan && baru === kataKunciSebelumnya) return;` di `jalankanPencarianConversation()` (itu encoding literal REQ-017b dan izin REQ-017c), `renderSnippetCocok()`, markup snippet, dan `setInterval` 6 detik. **Tidak ada** `AbortController`, `AbortSignal`, atau timeout (CON-002).

### 3.2 Bukti eksekutabel — harness atas kode asli

Proyek ini tidak punya test runner JS (ALT-008), dan TASK-204 melarang mengubah berkas test di fase ini. Karena itu `build/check-round-guard.php` **mengekstrak kode asli** `muatUlangDaftarConversation()` dari view lewat pencocokan kurung kurawal, menyisipkannya ke driver, lalu menjalankannya dengan `node`. Yang diuji adalah kode yang dikirim, bukan salinannya — bila nama variabel berubah, ekstraksinya gagal berisik.

| Skenario | Hasil |
| --- | --- |
| S1 putaran K dikalahkan K2, K selesai lebih dulu | pengaman **tetap terpasang**; K tidak menulis DOM |
| S1 lanjutan: K2 selesai | pengaman dilepas oleh pemiliknya; DOM ditulis sekali |
| S2 `ambilSemuaConversation()` melempar sinkron | pengaman **tidak** tertinggal `true`; galat diteruskan utuh |
| S3 putaran tunggal normal | tidak ada regresi |
| S4 putaran gagal lewat promise reject | pengaman tetap dilepas |

`=== HASIL: 13 lulus, 0 gagal ===` (`build/round-guard-harness.js`).

**Uji mutasi — membuktikan harness ini bisa gagal.** Test yang selalu hijau tidak membuktikan apa pun, jadi bug lamanya dimasukkan kembali ke kode yang sudah diekstrak:

| Mutasi | Yang gagal | Kesimpulan |
| --- | --- | --- |
| M1: pelepasan tanpa cek kepemilikan (`[CORR-01]` dikembalikan) | **hanya** S1c | harness menangkap CORR-01 |
| M2: `catch` tidak melepas pengaman (`[CORR-03]` dikembalikan) | **hanya** S2a | harness menangkap CORR-03 |

Keduanya `EXIT=1`, dan tidak ada assertion lain yang ikut jatuh — sinyalnya tepat sasaran, bukan kebetulan.

Validasi sintaks JS view: `php build/check-inbox-js.php && node --check build/inbox-js-check.js` → `JS SYNTAX: OK` (86.553 byte).

## 4. Checklist AC-015d — skenario putaran yang dikalahkan (browser)

Tambahan pada checklist manual §6 walkthrough Fase 1e. Skenario ini **hanya dapat dijalankan manusia dengan browser + DevTools** (DEP-004: proyek tidak punya otomasi browser, dan CON-006 melarang menyentuh Gateway live).

**Cara menjalankan.**

1. Buka Inbox (`/inbox`) dengan DevTools terbuka pada tab **Network**, filter `api/conversations`, jenis **Fetch/XHR**.
2. Nyalakan pembatasan jaringan (**Slow 3G**, atau custom latency ± 2 s) supaya satu putaran berlangsung cukup lama untuk diamati. Tanpa ini putaran hanya 122-327 ms dan penumpukan tidak akan terlihat.
3. Ketik kata kunci **K** (mis. `mapan`) lalu Enter → putaran K mulai.
4. **Selagi K masih berjalan**, ketik kata kunci **K2** (mis. `oke`) lalu Enter.
5. Amati tiga hal:
   - request `?q=mapan` dan `?q=oke` **tumpang tindih** di panel Network;
   - **tidak ada** request polling `?page=1&q=oke` kedua yang mulai selagi putaran K2 masih berjalan;
   - daftar di layar akhirnya hanya menampilkan hasil **K2**.

| Plan | Spec | Hasil | Bukti |
| --- | --- | --- | --- |
| (f) putaran yang dikalahkan tidak melepas pengaman | AC-015(d) | **PASS** | dikonfirmasi pemilik proyek 2026-09-25 ("tes browser pass"). Dijalankan sesuai prosedur §4 dengan jaringan diperlambat di DevTools. Angka Network-panel (jumlah request, ukuran, jeda) **tidak dicatat** — lihat catatan di bawah tabel |

> [!NOTE]
> **Apa yang baris (f) buktikan, dan apa yang tidak.**
>
> - **Terbukti lewat konfirmasi manusia:** integrasi di browser sungguhan — dua putaran benar-benar tumpang tindih saat jaringan diperlambat, tidak ada putaran polling kedua yang mulai selagi K2 berjalan, dan layar akhirnya hanya menampilkan hasil K2.
> - **Terbukti otomatis:** logika pengaman pada kode asli (harness §3.2, 13/13) dan sintaks view (`node --check`).
> - **Tidak terbukti, dan tidak diklaim:** sensitivitas harness itu sendiri diukur lewat uji mutasi (§3.2), bukan lewat baris ini. Hasil baris (f) adalah satu kali pengamatan manusia; tidak ada angka Network-panel yang tersimpan, sehingga temuan ini **tidak dapat direproduksi dari dokumen ini saja** — beda dengan baris (e) walkthrough Fase 1e yang mencatat jumlah request dan jedanya. Pelajaran untuk pengujian manual berikutnya: catat angkanya saat pengamatan masih di layar.

## 5. Gate makro, boundary, dan kebersihan

| Item | Hasil |
| --- | --- |
| Gate makro setelah Fase 1 | `vendor/bin/phpunit --no-coverage` → exit 0, `OK (400 tests, 1462 assertions)`, nol skip/risky/incomplete |
| Gate makro setelah Fase 2 | sama: exit 0, `OK (400 tests, 1462 assertions)`, nol skip |
| Baseline review | 395 test / 1443 assertion → **naik** +5 / +19, tidak pernah turun (CON-004) |
| Kunci regresi AC-014 (a)-(i) + REQ-016c | `OperationalInboxScreenTest` & `OperationalInboxConversationTest` hijau; AC-014h "Persen dan underscore literal di isi pesan" tetap lulus |
| Floor-Guard | `git diff -U0` berkas test: **0 baris dihapus**; tanpa `@group`, `markTestSkipped`, atau assertion yang dilemahkan |
| Boundary Fase 2 (CON-001) | berkas `app/`+`tests/` yang berubah setelah gate Fase 1: **hanya** `app/Views/inbox/index.php` → nol perubahan pada `app/Controllers/Inbox.php`, `app/Services/`, model, dan test |
| Boundary kumulatif | 4 berkas: controller, view, satu berkas test, satu dokumen. Tanpa migration, index, route, parameter, atau endpoint baru |

Seluruh artefak bukti ada di `build/` (gitignored): `phase1-refactor.txt`, `phase1-ac014.txt`, `phase1-live-check.txt`, `phase1-live-check.php`, `phase2-refactor.txt`, `check-round-guard.php`, `round-guard-driver.js`, `round-guard-harness.js`, `round-guard-mutation.txt`, `check-inbox-js.php`, `inbox-js-check.js`.

## 6. Batas dan hal yang belum

- **Sudah dijalankan:** checklist browser §4 baris (f) → PASS (konfirmasi pemilik proyek 2026-09-25). Angka Network-panel tidak tersimpan; keterbatasannya dijelaskan di §4.
- **Tidak dikerjakan:** Fase 3 (TASK-301..TASK-305) bersifat opsional dan menunggu keputusan eksplisit pemilik proyek. Isinya: penolakan `q` non-UTF-8 dengan HTTP 400, pemindahan `potong()` ke sesudah paginasi, dan verifikasi hitungan di `SeedFase1ePerf`.
- **Risiko residual yang diterima sadar (RISK-004):** regresi pada pengaman putaran tidak akan menggagalkan CI karena tidak ada test runner JS. Mitigasinya adalah bukti manusia §4 ditambah harness §3.2 — dan celah ini dinyatakan terbuka, bukan diklaim tertutup.
- **Catatan tepi yang ditemukan tetapi tidak diperbaiki (di luar temuan review, RISK-008):** pemeriksa `kataKunci !== kataKunciAktif` di `.then()`/`.catch()` membandingkan **nilai** kata kunci, bukan identitas putaran. Bila urutannya K → kosong → K lagi, putaran K yang pertama tetap menulis DOM. Dampaknya nihil (datanya memang data K, dan putaran terbaru akan menimpanya), dan rencana menetapkan cek identitas di `.then()`/`.catch()` sebagai **opsional** (TASK-201 detail (d)). Dicatat di sini supaya tidak hilang.
- **Kesalahan pengukuran yang sudah tercatat:** DB uji `aulia_inboxdb_test` dipakai bersama dan setiap kelas Inbox memanggil `emptyTable()` di `setUp()`. Jalankan **satu** proses PHPUnit pada satu waktu (CON-005).
