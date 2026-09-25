# Walkthrough — M3 Fase 1e: Pencarian Isi Pesan + Match Snippet

> [!IMPORTANT]
> **Dokumen ini non-normatif.** Sumber normatif tetap `spec/spec-design-m3-operational-inbox-fase1.md` (rev 1.4; REQ-014..REQ-017, CL-016..CL-021, AC-014..AC-016) dan `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` (v1.4; Phase 5 / TASK-023..TASK-029, status `Completed`). Bila terjadi konflik, Spec + Plan menang.

## 1. Ringkasan

- **Tujuan:** kasir dapat menemukan percakapan dari **isi pesan**, bukan hanya dari nama/nomor (PRD GH-010, REQ-014).
- **Hasil:** `GET /inbox/api/conversations?q=...` kini mencocokkan `messages.text` di seluruh riwayat (pesan pelanggan `incoming`, balasan staff `outgoing`, dan Internal Note), setiap elemen `conversations` membawa `match_snippet` dari pesan cocok terbaru, dan daftar menampilkan satu baris snippet di bawah nama dengan label "Internal" bila sumber cocoknya Internal Note.
- **Status:** TASK-023..TASK-029 **selesai** (approval user 2026-09-25). Plan kembali `Completed` (rev 1.4). Gate makro saat penutupan: `php vendor/bin/phpunit --no-coverage` → `OK (395 tests, 1443 assertions)`, exit 0.
- **Gate berikutnya:** review kode lewat `/sdlc-code-review` — brief siap pakai ada di `docs/handoff-m3-fase1e-code-review-2026-09-25.md`.

## 2. Alur kasir setelah Fase 1e

1. Kasir membuka **Inbox** (`/inbox`) lalu mengetik kata di kotak pencarian, mis. `saerah`.
2. Layar mengirim `GET /inbox/api/conversations?page=1&q=saerah`. Kata kunci yang sedang aktif juga ikut pada refresh otomatis, jadi daftar tidak kembali ke semua conversation.
3. Server mencocokkan kata itu ke kolom identitas (CL-015) **atau** ke isi pesan (CL-016), lalu mengirim hasil beserta `match_snippet` untuk setiap conversation yang cocok lewat isi pesan (CL-018).
4. Conversation yang cocok lewat isi pesan muncul dengan **baris snippet** di bawah nomor telepon/LID. Bila sumber cocoknya Internal Note, snippet diawali label **Internal**, sehingga staff tahu catatan itu tidak terkirim ke pelanggan.
5. Selama menunggu respons, **daftar sebelumnya tetap tampil dan tetap bisa diklik** (REQ-017c). Satu putaran pemuatan daftar hanya boleh berjalan satu kali pada satu waktu (REQ-017b).

## 3. Kontrak yang dikunci

| Aspek | Nilai | Sumber |
| --- | --- | --- |
| Parameter | `q` pada `GET /inbox/api/conversations` — tanpa parameter/endpoint baru | CON-004, REQ-014 |
| Cakupan `q` | kolom identitas (CL-015) **atau** `messages.text` di seluruh riwayat | REQ-014, CL-016 |
| Pesan yang dicari | `incoming`, `outgoing`, dan Internal Note (`is_internal = TRUE`) | CL-016 |
| Pesan yang dikecualikan | `deleted_at` terisi, `text` `NULL`, atau kosong | REQ-014, AC-014f |
| Pola cocok | `LIKE '%q%'` dengan `escapeLikeString()` + `like(..., 'both', false)` → `%`/`_` diperlakukan sebagai teks biasa | CL-008, AC-014h |
| Satu conversation | muncul satu kali; snippet dari pesan cocok terbaru (`message_timestamp` terbesar, tie-break `id` terbesar) | CL-017 |
| `match_snippet` | `{ text, is_internal, message_timestamp }`; `null` saat tidak ada `q` atau saat cocok lewat identitas | REQ-015, CL-018 |
| Panjang snippet | maks. 120 karakter `mb_*` di sekitar kemunculan pertama `q`, dengan `…` di sisi yang dipotong | CL-019, AC-014g |
| Tanpa `q` | query ke `messages` **tidak dijalankan sama sekali** dan `match_snippet = null` | REQ-016c |
| Layar | snippet di-escape (`escapeHtmlInbox`) + label "Internal"; satu putaran daftar pada satu waktu | REQ-017a/b |
| Kecepatan | median 3 percobaan ≤ 3 detik pada dataset ASSUMPTION-004 (2.000 conversation × 100 pesan) | REQ-016, AC-016 |

Yang **tidak** berubah: parameter, validasi `q` (trim, maks. 255 karakter, `400` bila lebih), AND dengan `status`, seluruh dataset tanpa batas baris, 50 per halaman, dan urutan `last_message_at` terbaru (REQ-014, CL-003/CL-010/CL-011).

## 4. Perubahan kode (dan batas CON-004)

Diff Fase 1e penuh (`git diff --stat 0f22806^..HEAD -- app tests`): **7 berkas, +1139 / −8**.

| Berkas | Ukuran | Isi | Task |
| --- | --- | --- | --- |
| `app/Services/InboxMatchSnippetService.php` | baru, +84 | `potong(?string $teks, string $q): ?string` — pure function, hanya `mb_*` | TASK-023 |
| `app/Controllers/Inbox.php` | +100 | `cariPesanCocok()` (satu query agregat `ROW_NUMBER()`), predikat `q` = identitas **OR** isi pesan, key `match_snippet` | TASK-024 |
| `app/Views/inbox/index.php` | +67 | `renderSnippetCocok()`, label "Internal", pengaman `putaranDaftarBerjalan` | TASK-025 |
| `app/Commands/SeedFase1ePerf.php` | baru, +328 | alat ukur AC-016 dengan guard nama database | TASK-026 |
| `tests/unit/InboxMatchSnippetServiceTest.php` | baru, +193 | 14 tests / 41 assertions | TASK-023 |
| `tests/session/OperationalInboxConversationTest.php` | +322 | 35 tests / 249 assertions (AC-009, AC-013, dan AC-014) | TASK-024 |
| `tests/session/OperationalInboxScreenTest.php` | +53 | 7 tests / 30 assertions (markup snippet + pelepasan `.finally()`) | TASK-025 |

Batasan yang dipegang (CON-004/CL-020): **tidak ada** migration, index, FULLTEXT, parameter baru, endpoint baru, maupun perubahan `app/Config/Routes.php`. Satu-satunya pengecualian yang sudah disetujui pemilik produk (R-02) adalah Service murni beserta unit test-nya.

> [!NOTE]
> Plan TASK-028 memakai rentang `79ba24b^..HEAD` sehingga menyebut **enam** berkas kode: rentang itu memang tidak memuat commit Service (`0f22806`) yang sudah diverifikasi terpisah di TASK-023. Angka **tujuh** berkas di tabel di atas berasal dari rentang penuh fase (`0f22806^..HEAD`).

## 5. Bukti AC-014 — API & service (otomatis)

| Pemeriksaan | Perintah | Hasil |
| --- | --- | --- |
| Service murni | `vendor\bin\phpunit --no-coverage tests\unit\InboxMatchSnippetServiceTest.php` | `OK (14 tests, 41 assertions)`, exit 0 |
| API percakapan | `vendor\bin\phpunit --no-coverage tests\session\OperationalInboxConversationTest.php` | `OK (35 tests, 249 assertions)`, exit 0 |
| Layar (markup) | `vendor\bin\phpunit --no-coverage tests\session\OperationalInboxScreenTest.php` | `OK (7 tests, 30 assertions)`, exit 0 |
| Gate makro (suite penuh) | `php vendor/bin/phpunit --no-coverage` | `OK (395 tests, 1443 assertions)`, exit 0 (9.73 s dan 9.93 s pada dua kali pengukuran penutupan) |

Cakupan AC-014 yang dibuktikan (detail lengkap di TASK-024 plan): (a) cocok lewat isi pesan dengan `match_snippet.is_internal = false`; (b) tiga jenis sumber — `incoming`, `outgoing`, Internal Note — dengan `is_internal = true` untuk Note; (c) `status` dan `q` berlaku bersamaan (AND); (d) satu conversation muncul satu kali dan snippet berasal dari pesan cocok terbaru, dengan tie-break `id` terbesar; (e) `match_snippet = null` saat cocok lewat identitas atau saat tidak ada `q`; (f) pesan ter-soft-delete, `text` `NULL`, atau kosong tidak pernah cocok; (g) potongan tidak pernah melebihi 122 karakter (unit test); (h) `q = "50%"` cocok ke `diskon 50%` tetapi tidak ke `diskon 500`; (i) pencarian tidak menulis apa pun (jumlah baris `messages` dan kolom denormalized tetap).

> [!WARNING]
> **Jebakan pengukuran (bukan cacat produk).** DB uji `aulia_inboxdb_test` dipakai bersama dan `setUp()` setiap kelas Inbox memanggil `emptyTable()`. Menjalankan **dua proses PHPUnit bersamaan** membuat keduanya saling menghapus data dan menghasilkan angka palsu: pada 2026-09-25 satu berkas test melaporkan 1 lalu 7 failure, dan `tests/session` melaporkan 3 error di `InboxHandoffTest`, padahal semuanya `OK` begitu dijalankan **sekuensial**. Aturan praktis: jalankan satu proses PHPUnit pada satu waktu, dan pakai `composer test` (seluruh suite dalam satu proses) sebagai gate resmi.

## 6. Bukti AC-015 — layar (checklist manual di browser)

Huruf di plan berbeda dari huruf di Spec. Pemetaannya: plan (a) + plan (b) bersama-sama = Spec AC-015(a); plan (c) = Spec AC-015(b); plan (d) = Spec AC-015(c); plan (e) = Spec AC-015(d). Spec AC-015(e) (kasir mengetik kata kunci dan melihat hasilnya di browser) tercakup oleh dua pencarian kata kunci yang dipakai untuk plan (a) dan plan (c).

| Plan | Spec | Hasil | Bukti |
| --- | --- | --- | --- |
| (a) snippet pesan publik | AC-015(a) | **PASS** | `plafon` → conversation terlihat `11749`, baris snippet muncul di bawah nama, tanpa label "Internal" |
| (b) label "Internal" | AC-015(a) | **PASS** | `zzztag` → catatan uji di conversation terlihat `11746` (`messages.id = 305`, `is_internal = 1`); snippet membawa label "Internal" (dikonfirmasi user 2026-09-25, diverifikasi ulang read-only dengan `SELECT ... WHERE text LIKE '%zzztag%'`) |
| (c) tanpa baris tambahan | AC-015(b) | **PASS** | `Duwi` → conversation `11752` cocok lewat identitas, tidak ada baris snippet (`match_snippet = null`, CL-018) |
| (d) escaping | AC-015(c) | **PASS** | catatan `zzztag` yang berisi `<b>tebal</b>` dan `<script>alert(1)</script>` tampil sebagai teks apa adanya: tidak menebal dan tidak dieksekusi |
| (e) pengaman putaran + kata kunci baru | AC-015(d) | **PASS (dengan satu batas pengamatan)** | DevTools Network (filter `api/conversations`, Fetch/XHR): 15 request `?page=1` dalam ± 120 s, semuanya `200` dan `10.7 kB`, waktu 122-327 ms, berjarak ± 6 detik dan **tidak menumpuk**; sesudah itu muncul `?page=1&q=mapan` (1.7 kB) dan `?page=1&q=oke` (1.6 kB) → kata kunci **baru** tetap dikirim sementara polling 6 detik terus berjalan |

> [!NOTE]
> **Batas pengamatan plan (e).** Separuh klaim "tick yang jatuh saat putaran masih berjalan tidak memulai request baru" tidak dapat direkam secara live: satu putaran hanya 122-327 ms pada 10 conversation live, dan bahkan dataset 200.000 pesan memakan ± 1,0 s (TASK-027) — untuk melewati tick 6 detik dibutuhkan sekitar 1,2 juta pesan. Karena itu separuh tersebut dibuktikan secara struktural: early return `if (putaranDaftarBerjalan) return;` di dalam `setInterval` 6 detik dan pelepasan penanda di `.finally()` (`app/Views/inbox/index.php:2553-2556` dan `1008-1036`), yang dikunci oleh test `tests/session/OperationalInboxScreenTest.php` dari TASK-025. Tidak ada `AbortController`, timeout, atau queue yang ditambahkan (CL-020).
>
> **Temuan visibilitas (bukan cacat).** Hanya 10 dari 30 conversation di `aulia_inboxdb` yang tampil karena `ConversationModel` memakai `useSoftDeletes = true` dan 20 baris membawa `deleted_at`; ketujuh Internal Note lama semuanya berada di conversation ter-soft-delete (`11730`, `11739`, `11740`). Karena itu pencarian `stok` dan `approval` yang tidak mengembalikan apa pun adalah perilaku **benar**.

## 7. Bukti AC-016 — kecepatan (pengukuran manual pada database perf)

- **Target (mengikat):** median dari 3 percobaan ≤ 3 detik untuk setiap kata kunci, pada dataset ASSUMPTION-004 (2.000 conversation × 100 pesan = 200.000 baris `messages`).
- **Database:** `aulia_inboxdb_perf`, dibuat *schema-only* dari `aulia_inboxdb` memakai resep `docs/ARCHITECTURE.md` §11 — **bukan** `php spark migrate`, karena migration history hidup di database `default`. Override `database.inbox.database` di `.env` bersifat sementara dan **sudah dikembalikan**.
- **Pengisi data:** `php spark aulia:seed-fase1e-perf --dbgroup=inbox` (TASK-026) → 2.000 conversation / 200.000 pesan dalam 10,3 s, `EXIT=0`. Volume kata kunci: `zarahrafi` 1 pesan, `katalog` 20.000 pesan (± 10%), `a` 190.000 pesan.
- **Cara ukur:** endpoint HTTP asli di Apache (`http://localhost/aulia`) dengan sesi kasir, waktu dari `curl -w %{time_starttransfer}` (`time_total` berselisih < 1 ms).

| Kata kunci | Percobaan 1 | Percobaan 2 | Percobaan 3 | Median | Target ≤ 3 s | Hasil |
| --- | --- | --- | --- | --- | --- | --- |
| `zarahrafi` (1 pesan) | 421,5 ms | 466,4 ms | 436,0 ms | **436,0 ms** | ✅ | **PASS** |
| `katalog` (± 10% pesan) | 555,8 ms | 516,4 ms | 523,1 ms | **523,1 ms** | ✅ | **PASS** |
| `a` (190.000 pesan) | 1010,1 ms | 1002,5 ms | 1037,5 ms | **1010,1 ms** | ✅ | **PASS** |
| tanpa `q` (baseline REQ-016c) | 162,4 ms | 151,6 ms | 143,0 ms | **151,6 ms** | — | baseline tercepat |

Catatan hasil: `zarahrafi` → HTTP 200, 1 baris, `match_snippet` ada; `katalog` → 50 baris dengan 50 snippet (62.656 byte); `a` → 50 baris dengan `match_snippet` `null` di semua baris karena `chat_id` fixture `fase1eperf-*` sudah cocok lewat kolom identitas (*identity hit by design*, CL-018); tanpa `q` tetap menjadi jalur tercepat, sehingga REQ-016c terbukti tidak melambat, dan test TASK-024 membuktikan query `messages` tidak dijalankan bila `q` kosong.

> [!NOTE]
> **Stop rule tidak terpicu.** Bila ada median melebihi 3 detik, prosedurnya adalah berhenti dan melaporkan hasil ukur sebelum menambah index/FULLTEXT/migration apa pun (CL-020, Spec §9 "Ask first"). Semua median jauh di bawah 3 detik, jadi tidak ada permintaan izin yang diperlukan.

## 8. Gate makro, boundary check, dan kebersihan

| Item | Hasil |
| --- | --- |
| Gate makro (TASK-028) | `php vendor/bin/phpunit --no-coverage` → exit 0, `OK (395 tests, 1443 assertions)`, nol failure/error/skip; dijalankan ulang saat penutupan TASK-029 dengan hasil identik |
| Boundary check (CON-004) | diff hanya menyentuh 7 berkas di `app/` + `tests/`; tanpa migration, index, route, parameter, atau endpoint baru |
| DB live | `aulia_inboxdb` tetap 30 conversation / 152 pesan, nol baris `fase1eperf-%` |
| DB perf | `aulia_inboxdb_perf` **tidak ada** di `information_schema.SCHEMATA` (dibersihkan; diverifikasi ulang 2026-09-25) |
| DB uji | `aulia_inboxdb_test` utuh dan dipakai suite; guard fail-closed di `tests/_support/bootstrap.php` tetap aktif |
| Konfigurasi | `.env` `database.inbox.database` = `aulia_inboxdb` (kembali normal) |

## 9. Batas, risiko, dan item yang dibawa ke depan

- **Batas pengamatan plan (e)** — lihat §6: separuh klaim "tick saat putaran berjalan tidak memulai request baru" dibuktikan lewat kode + test TASK-025, bukan rekaman browser, karena tidak ada dataset yang bisa membuat satu putaran melewati 6 detik.
- **RISK-007 (terbuka, dipantau):** `LIKE '%q%'` tidak memakai index `(conversation_id, message_timestamp)`, jadi kata kunci sangat umum bisa melewati 3 detik pada dataset yang jauh lebih besar daripada 200.000 baris. Bila terjadi, laporkan hasil ukur lebih dulu (CL-020).
- **RISK-009 (diterima):** pengaman putaran bersifat per layar; dua tab atau halaman lama masih bisa mengirim dua pencarian sekaligus. Tidak ada `AbortController`/timeout, sesuai CL-020.
- **Cakupan AC-014 dibatasi teks ASCII** (Spec §6/§12): untuk huruf beraksen/non-latin, perilaku MySQL `LIKE` dan PHP `mb_stripos` bisa berbeda; ini pembatasan cakupan yang disengaja dan tidak diuji.
- **Dokumentasi yang masih berutang:** `docs/ARCHITECTURE.md` §11 belum memuat paragraf `aulia_inboxdb_perf` + command `aulia:seed-fase1e-perf`. Auditor sudah mengarahkan item ini ke `/sdlc-map-architecture` (`docs/audit/clarification-report-m3-fase1e-message-search-2026-09-25.md` §3.3) dan **sengaja tidak dikerjakan** di sesi write-code ini agar tidak melanggar batas fase.
- **Artefak uji di DB live:** catatan Internal Note manual pada conversation terlihat `11746` (`messages.id = 305`) ditinggalkan sebagai bukti checklist plan (b) dan (d). Catatan ini bisa dihapus kapan saja atas permintaan.

## 10. Cara mereproduksi

```powershell
# Gate makro: seluruh suite dalam SATU proses (jangan menjalankan dua proses bersamaan)
cd C:\xampp\htdocs\aulia
php vendor/bin/phpunit --no-coverage

# Riwayat dan ukuran diff Fase 1e
git --no-pager log --oneline 0f22806^..HEAD
git --no-pager diff --stat 0f22806^..HEAD -- app tests

# Bukti checklist plan (b)/(d) di DB live (read-only)
& 'C:\xampp\mysql\bin\mysql.exe' -u root -e "SELECT c.id AS conv, c.deleted_at AS conv_deleted, m.id AS msg, m.is_internal FROM aulia_inboxdb.conversations c JOIN aulia_inboxdb.messages m ON m.conversation_id = c.id WHERE m.text LIKE '%zzztag%';"

# Database perf harus sudah bersih
& 'C:\xampp\mysql\bin\mysql.exe' -u root -e "SELECT COUNT(*) AS perf_schema FROM information_schema.schemata WHERE schema_name = 'aulia_inboxdb_perf';"
```

Mengukur ulang AC-016 mengikuti urutan TASK-027: provisioning `aulia_inboxdb_perf` secara *schema-only* → override `database.inbox.database` di `.env` → `php spark aulia:seed-fase1e-perf --dbgroup=inbox` → ukur `GET /inbox/api/conversations?page=1&q=<kata>` tiga kali per kata kunci → catat median → bersihkan data uji → kembalikan `.env`. Command itu menolak berjalan bila database aktif bukan `aulia_inboxdb_perf`, dan tidak pernah di-wiring ke `composer test` maupun CI.

## 11. Rollback

Semua perubahan Fase 1e bisa dibalik tanpa langkah database karena tidak ada perubahan skema: revert commit `0f22806`, `79ba24b`, `596dd5d`, `7ba0308`, dan `1272b12`, lalu hapus `app/Services/InboxMatchSnippetService.php` serta `app/Commands/SeedFase1ePerf.php` bila masih tersisa. Setelah rollback, pencarian identitas Fase 1d (CL-015) tetap berfungsi seperti sebelum Fase 1e, dan tidak ada tabel atau kolom yang perlu dibersihkan.

## 12. Rujukan

- `spec/spec-design-m3-operational-inbox-fase1.md` (rev 1.4) — REQ-014..REQ-017, CL-016..CL-021, AC-014..AC-016.
- `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` (v1.4, `Completed`) — Phase 5 / TASK-023..TASK-029 beserta bukti per task.
- `docs/audit/clarification-report-m3-fase1e-message-search-2026-09-25.md` — klarifikasi Fase 1e (Readiness 87/100; R-01..R-06, F-01..F-05).
- `docs/handoff-m3-fase1e-code-review-2026-09-25.md` — brief siap pakai untuk sesi `/sdlc-code-review`.
- `prd-20260922-0141-chat-whatsapp-inbox.md` — GH-010 (pencarian isi pesan).
