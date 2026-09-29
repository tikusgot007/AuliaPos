# Status Proyek — AuliaPos + WA-Gateway (Master Reference)

**Terakhir diupdate:** 29 September 2026 WIB. Pembaruan besar: **Fitur Teruskan (Tahap 4 / GH-016) SELESAI di dua repo** — WA-Gateway sudah di-*merge* + *deploy* ke `master` (`4a766d2`, live), AuliaPos plan `Completed`. Ditambah rantai perbaikan **media-bound** (plan v1.0–v1.4 + janitor A-01..A-04), **regenerate `docs/ARCHITECTURE.md`** (template §1–15), dan **port *concurrency lock* No Order** dari `v2.1`. Sebelumnya (28 Sep): seluruh fitur Inbox WhatsApp yang dimulai 26 September (**Grup Tahap 1**, **Grup Tahap 2**, **Balas Pesan Tahap 3**, **Inbox Read Authorization**) sudah **SELESAI penuh** dan ter-*push* ke `origin/v2.3`.
**Riwayat pembaruan sebelumnya:** 24 September 2026 (koreksi janitor: status live Gateway, roadmap M1 dan M3, pemulihan 2 laporan klarifikasi M1 Wave 1) — setelah **M1 Wave 1 ditutup** (deploy TASK-019, AC-001 nyata TASK-017 lulus 3/3, APPROVAL TASK-018). Bukti AC-001 sudah nyata (Gateway live, 3× `pm2 stop`: 30/30 pesan, 0 hilang, 0 duplikat); bukti AC-002–AC-018 tetap simulasi. Rincian: `docs/decisions/2026-09-23-m1-wave1-deploy-dan-ac001.md`. M1 Wave 1 masuk `origin/master` lewat **PR #4, merge commit `21a4cb6`** (23 Sep malam).
**Cek centang:** 21 September 2026 ~14:30 WIB (diverifikasi langsung ke repo dan mesin Aan-PC) + pembaruan 23, 24, 28, dan 29 September 2026. Legenda: `[x]` = selesai dan terverifikasi (bukti dicatat di samping), `[~]` = selesai sebagian, `[ ]` = belum. Item yang selesai sebagian dipecah jadi dua baris.
**Cara pakai:** Sematkan/paste dokumen ini di awal sesi Claude Code baru sebagai context. Update bagian "Status Sekarang" dan "Yang Menggantung" setiap kali ada progres baru — dokumen ini gampang basi kalau kerja paralel jalan di beberapa sesi Claude Code sekaligus, jadi **selalu `git fetch` + cek HEAD nyata sebelum percaya isi dokumen ini secara buta**.

---

## Status Sekarang (29 September 2026)

### Dua repo

| Repo | Branch | HEAD | Status |
| --- | --- | --- | --- |
| AuliaPos | `v2.3` | `676ef9a` | Bersih, sudah sinkron dengan `origin/v2.3` |
| WA-Gateway | `master` | `4a766d2` | Bersih, sudah sinkron dengan `origin/master`, ada di `C:\projects\WA-Gateway` (runtime live, sudah termasuk merge Teruskan) |

> [!IMPORTANT]
> Status proses Gateway **belum diverifikasi** saat pembaruan ini ditulis — `pm2` tidak ada di `PATH` shell yang dipakai, jadi jangan diasumsikan Gateway sedang online. Cek sendiri sebelum uji apa pun yang butuh Gateway hidup.

### Fitur WhatsApp Inbox yang sudah SELESAI (26–29 September 2026)

Fitur-fitur berikut sudah thru **PRD → Spec → Plan → Kode → Review** dan sudah ter-*push*. Semuanya punya dokumen spec + plan sendiri di `/spec/` dan `/plan/`. Beberapa item terakhir berbeda sifatnya: itu **perbaikan bug / hardening** yang plan-nya lahir langsung dari diagnosis dan review (tanpa PRD), tetapi tetap dieksekusi fase per fase dengan gerbang approval.

- [x] **Grup Tahap 1** — tab **Grup** terpisah dari antrean kasir, penandaan grup, penonaktifan aksi yang tidak berlaku untuk grup, perbaikan badge `perlu_dibalas`. Spec `spec-design-grup-tahap1-tab-inbox.md` v1.2, plan `plan-feature-grup-tahap1-v1.0.md` Completed. Review commit `09a6f0e`: **clear to merge**.
- [x] **Grup Tahap 2** — identitas pengirim per pesan (nomor telepon atau LID, tidak pernah JID mentah) + nama grup stabil sebagai judul percakapan. Spec `spec-design-grup-tahap2-identitas.md` v1.4, plan AuliaPos + Gateway Completed. Dilanjut **Sender Identity Hardening** (`plan-refactor-sender-identity-label-hardening-v1.0.md`) setelah review menemukan kebocoran JID mentah.
- [x] **Balas Pesan Tahap 3 (GH-015)** — balas pesan **berkutipan asli WhatsApp**, untuk teks maupun lampiran, plus tampilan kutipan masuk dari pelanggan. Berlaku **dua repo**: AuliaPos (snapshot kutipan di 7 kolom `messages.quoted_*` + `InboxQuoteSnapshotService`) dan WA-Gateway (`quoted` opsional di `/send` + `/send-media`, `quote_applied` di respons). Spec `spec-design-balas-pesan.md` **v1.8**, plan AuliaPos + Gateway Completed, lalu **2 ronde review** + 2 plan remediasi (`plan-refactor-balas-pesan-tahap3-v1.0.md`, `plan-refactor-balas-pesan-tahap3-review2-v1.0.md`) yang menutup temuan keamanan (urutan guard otorisasi, IDOR media, cuplikan tanpa batas), **fidelitas tipe media** kutipan (`REQ-008c` + kolom `quoted_media_type`), dan kebersihan arsitektur. **Diverifikasi live** terhadap Baileys sungguhan (kotak kutipan native terlihat di WhatsApp), bukan simulasi saja.
- [x] **Inbox Read Authorization (ALT-003/AUTHZ-02)** — keputusan **baca terbuka, tulis terbatas**: semua staff yang login boleh melihat semua percakapan, thread, dan media; operasi tulis tetap **terbatas ke pemegang/admin**; Internal Note terbuka. `GET /inbox/media/(:num)` **dibuka** (guard `cekOwnership()` dihapus), `GET /inbox/api/conversations/(:num)/messages` **tetap** `auth`-only tanpa guard kepemilikan. Spec `spec-design-inbox-read-authorization.md` v1.1, plan `plan-refactor-inbox-media-read-authorization-v1.0.md` Completed, review commit `1961bda`: **clear to merge**, 0 temuan CRITICAL/REQUIRED.
- [x] **Media Inbox: foto tidak pernah kadaluarsa** *(perbaikan bug, 28 Sep 2026)* — keluhan "Gambar tidak tersedia (kemungkinan sudah kadaluarsa)" ternyata **bukan** media basi: Gateway live sedang mati (Apache mencatat `502`, bukan `410`). Tiga cacat ditutup: latch `mediaGagal` yang permanen, pesan yang selalu menuduh "kadaluarsa", dan pemetaan **SEMUA** error Gateway ke `410` yang bisa memblacklist foto utuh selamanya. Kontrak `/media/download` sekarang `200` / `410` (hanya bila host media menyatakannya eksplisit) / `503` / `504`, dengan batas waktu unduhan 6 detik di Gateway. Ditambah **penyimpanan media masuk ke disk lokal** (`inbox.mediaStoragePath = D:\aulia_inbox_media\`) supaya membuka foto tidak lagi bergantung pada Gateway — ini menutup akar masalahnya. Plan `plan-bugfix-inbox-media-unavailable-v1.0.md` **Completed**; AuliaPos `1cb44ff` (`v2.3`), WA-Gateway `1591512` (**live**, `master`). Bukti: `docs/decisions/2026-09-28-inbox-media-not-expired-and-failure-classification.md`.
- [x] **Teruskan (Tahap 4 / GH-016)** — meneruskan pesan/lampiran ke percakapan lain. Dua repo: WA-Gateway menerima field `forward` di `/send` + `/send-media` (penanda native `contextInfo.forwardingScore`, fallback prefix teks `"↪️ Diteruskan: "`, respons `forward_marker_applied`); AuliaPos kolom `messages.is_forwarded` + modal pemilih tujuan. Spec `spec-design-teruskan.md` v1.3, plan AuliaPos + Gateway `Completed`, **bukti native terlihat di WhatsApp**, `EXT-001` terpenuhi (Gateway live `master` `4a766d2`).
- [x] **Media-bound hardening (rantai plan v1.0–v1.4 + janitor A-01..A-04)** — tiga sumbu batas media (ingest/unduh/unggah) yang terpisah, clamp env 3-kanal, plafon kebijakan 4096 MB, guard overflow 32-bit, pesan batas efektif (bukan nilai mentah). Semua plan `Completed`, sudah lewat `/sdlc-code-review`.
- [x] **Regenerate `docs/ARCHITECTURE.md`** (29 Sep) — 575 baris, mengikuti template resmi §1–15 (termasuk §12 topologi DB + §13 arsitektur modul Inbox).
- [x] **Port *concurrency lock* No Order dari `v2.1` ke `v2.3`** (29 Sep) — commit `4865c87`.

### Baseline test terbaru

- **691 test / 2714 assertion** (29 Sep 2026, `vendor/bin/phpunit --no-coverage`) — tetapi **4 error pra-eksisting** `Row size too large (8126)` (errno 1118) di 4 test migrasi (`GatewayOperationIdMigrationTest`, `IsForwardedMigrationTest`, `QuoteColumnsMigrationTest`, `QuotedSourceMessageIdMigrationTest`), karena tabel `messages` di `aulia_inboxdb_test` belum `ROW_FORMAT=DYNAMIC`. Sisanya lulus. **Exit-0 penuh baru tercapai setelah 4 error konfigurasi tabel itu dibereskan** — bukan bug kode, dan bukan regresi. Angka ini naik tiap sesi yang menambah test — jangan pakai angka beku sebagai gerbang.

---

## Tujuan Jangka Panjang

> Mengembangkan WhatsApp Inbox menjadi operational customer workspace, bukan sekadar viewer chat.

## Roadmap Besar

- [x] Tahap 0 — Baseline (DONE 20 Sep; decision log `docs/decisions/2026-09-19-tahap-0-baseline.md`)
- [~] M1 — Reliability (Ticket 01 selesai; **Wave 1 = Ticket 02–04 SELESAI & LIVE 23 Sep**; **Wave 2 (idempotensi outgoing) plan Completed 27 Sep**; Ticket 05–16 & sebagian Wave 2 belum — lihat butir M1 di bawah)
- [ ] M2 — State Consistency (belum mulai; **tidak menjadi blocker** — Fase 2 M3 sudah dibuka lewat amendemen PRD, lihat catatan urutan di bawah)
- [~] M3 — Operational Workflow (**Fase 1 (1a–1d) + Fase 1e (pencarian pesan) + Fase 2a (Handoff/Collision) SELESAI**; ditambah **Grup Tahap 1 & 2, Balas Pesan Tahap 3, Inbox Read Authorization** selesai Sep 2026; sisa M3 = Fase 2b auto-assignment + Fase 3 (menunggu M5))
- [ ] M4 — POS / Customer Context (belum dibahas)
- [ ] M5 — Intelligence / AI (belum dibahas)

> **Catatan urutan (28 Sep 2026):** Aturan lama "M3 harus menunggu M2" sudah **tidak berlaku** untuk Handoff/Collision. PRD diamandemen ke v1.1 dan Fase 2a selesai memakai **conditional write sempit** (`WHERE id = ? AND assigned_to = ?` + `affectedRows()`), pola yang sama dengan `ambilPercakapan()` yang memang sudah atomic — jadi Fase 2a **tidak butuh M2 penuh**. **M2 sebagai program besar tetap belum dimulai:** `docs/ARCHITECTURE.md` §12 masih menandai jalur `lepas`/`tutup`/`snooze`/`tandaiDibaca`/`hapus` sebagai non-atomic.

### Fitur WhatsApp Inbox (dipisah dari roadmap M3, 26–28 Sep 2026)

PRD: `prd-20260926-0024-whatsapp-grup-balas-teruskan.md` — **v1.2**.

- [x] **Tahap 1 — Grup di AuliaPos** (GH-011/GH-012)
- [x] **Tahap 2 — Grup di WA-Gateway** (GH-013/GH-014)
- [x] **Tahap 3 — Balas Pesan** (GH-015) — dua repo
- [x] **Tahap 4 — Teruskan** (GH-016) — dua repo, **SELESAI**. Spec `spec-design-teruskan.md` v1.3; plan `plan-feature-teruskan-auliapos-v1.0.md` + `plan-feature-teruskan-wa-gateway-v1.0.md` `Completed`; Gateway live `master` `4a766d2`.

Urutan bergantung ke bawah: Tahap 0 → M1 → M2 → M3 → M4 → M5.

## Aturan Tetap (berlaku di semua tahap)

- **Dokumentasi bukan source of truth.** Source code, migration, test, dan git diff adalah sumber kebenaran.
- **Jangan loncat tahap.** Tiap tahap punya prasyarat dari tahap sebelumnya. **Pengecualian yang sudah diputuskan:** prasyarat "M2 harus selesai" untuk Fase 2 M3 sudah dicabut (lihat catatan di bagian M3), jadi sebagian pekerjaan M3 berjalan lebih dulu.
- **Klaim harus berbasis bukti nyata**, bukan asumsi. Kalau ada yang tidak bisa diverifikasi, catat sebagai limitation eksplisit, jangan ditutup-tutupi atau dipaksakan kesimpulan.

---

## Baseline Repository

### AuliaPos
- Repo: `tikusgot007/AuliaPos`
- Branch: **`v2.3`** (dulu dokumen ini menyebut `v2.2` — sudah usang)
- HEAD terverifikasi **28 Sep 2026**: `bbb91c4` (3 perbaikan kecil: docblock `cekBukanGrup()`, test grup tertutup, paragraf perf-DB di `docs/ARCHITECTURE.md`), sinkron dengan `origin/v2.3`, working tree bersih
- `origin/HEAD` masih menunjuk ke `v2.1` — **abaikan**, itu cuma alias default remote
- Branch yang sudah selesai perannya: `claude/tahap-0-aulia-wa-handoff-ic861g`, `claude/cek-dulu-ubvqe2`, `claude/spec-operational-inbox-fase1-tuux4c`, `claude/m3-operational-inbox-plan-h244ji`, `claude/m3-inbox-plan-review-y9mbw3`, `feature/m3-operational-inbox-fase1a-task001` (merged via PR #41, dihapus 24 Sep)
- Konfigurasi AI sekarang: `.claude/` adalah **satu-satunya** sumber config AI (Claude Code, Kilo, Cline). Folder `.agents/` **sudah dihapus**; `AGENTS.md` membuka dengan penunjuk ke `.claude/`

### WA-Gateway
- Repo: `tikusgot007/WA-Gateway`
- Branch utama sekarang: `origin/master` @ `1591512` — **satu-satunya branch di remote**. HEAD terverifikasi **28 Sep 2026**: `1591512` = `origin/master`, sinkron. Commit ini berisi **perbaikan klasifikasi kegagalan `/media/download` + batas waktu unduhan** (plan `plan-bugfix-inbox-media-unavailable-v1.0.md`, sudah live); riwayat sebelumnya: `66bff03` (placeholder view-once), `a2ba409` (Balas Pesan), `1db79e1` (Grup Tahap 2)
- **Lokasi checkout: `C:\projects\WA-Gateway`.** (Catatan 25 Sep pernah mencatat checkout pindah ke `C:\home\wa-gateway-review` — **itu sudah tidak berlaku lagi**; `C:\home` tidak ada per 28 Sep, dan `C:\projects\WA-Gateway` kembali ada dan terverifikasi di `1591512`). Selalu cek disk dulu sebelum percaya path yang ingatan
- [x] `5b28eb6c8a7e6e6c2e1d5b7d7261389f9309c295` (PR #1, `claude/android-app-p40bl1` + "Create node.exe") adalah ancestor `master`, jadi baseline tetap valid
- Branch lain yang pernah muncul (`fix/lid-fromme-pushname-leak`, `claude/cek-bandingkan-mimac-fln1h4`) — **tidak relevan** dengan kerja saat ini (versi lama/terpisah, salah satunya referensi "AuliaPos v3.0")
- [x] **Riwayat M1 Wave 1 (referensi historis, bukan status):** branch `feature/stage-1-reliability` (dulu di worktree `C:\projects\WA-Gateway-m1`) berisi 21 commit di atas `e18f716` (8 pra-plan `3fd5f40`..`091fe19` + 13 plan `baf1896`..`065f683`) plus 9 komit refactor `2ca3065`..`fb585f1`; semua **di-merge ke `master` lewat PR #4 (merge commit `21a4cb6`, 23 Sep malam)** dan branch + worktree m1 **sudah dihapus**. Kode ad-hoc sandbox sudah ditimpa sesuai kontrak plan (retry 50/200/800 ms + overflow 500 event, timeout LID 2 detik + cache negatif 60 detik, dst.); E-01 dan E-06 diimplementasikan ulang sesuai plan (`ownSentRegistry`, isolasi error tanpa pesan minimal). Bukti AC-002..AC-018 simulasi; **AC-001 nyata** 23 Sep. Titik rollback: `065f683` (M1 tanpa refactor, sudah lulus AC-001 nyata) lalu `e18f716` (sebelum M1). Rincian: `docs/decisions/2026-09-21-m1-wave1-eksekusi-fase1-3.md`; konflik kerja ad-hoc vs plan: `docs/decisions/2026-09-21-handoff-m1-wave1-eksekusi-lokal.md`

### Environment aktif (hasil verifikasi 28 September 2026)
- [x] **Status Gateway (diverifikasi 28 Sep 2026, ~13:01 WIB):** **tidak ada PM2/supervisor** — Gateway dijalankan manual. Proses aktif PID `21048` (`node src/app/index.js`, cwd `C:\projects\WA-Gateway`), `connected`, nomor `6281913500707`, log `HTTP API + Dashboard berjalan di http://127.0.0.1:3000`. Kalau PC reboot, harus dijalankan manual lagi (RISK-005). Sesi deploy ini sempat memakai `pm2`? **Tidak** — `pm2` tidak dipakai; proses di-start langsung.
- [x] Catatan lama menyebut `G:\wa-gateway-5b28eb6` dan folder arsip `G:\arsip-gateway\` (20 Sep). Per 28 Sep 2026 `G:\arsip-gateway` **tidak ada** di disk. Jangan jalankan checkout Gateway ganda bersamaan (port 3000 dan sesi WhatsApp bentrok)
- [x] Folder `htdocs\wa-gateway` (yang `.env`-nya sempat diubah) sudah bukan yang dipakai dan sudah diarsipkan sebagai `htdocs-wa-gateway-poc-2026-09-12`. Isi `.env` di dalamnya tidak pernah diperiksa ulang
- [x] Setup Aan-PC (21 Sep): XAMPP MySQL dan Apache sebagai Windows Service (Automatic), AuliaPos di `C:\xampp\htdocs\aulia` (HTTP 200), WA-Gateway di PM2
- [x] **AuliaPos Inbox punya 3 database terpisah** yang tidak boleh dicampur: `aulia_inboxdb` (produksi), `aulia_inboxdb_test` (dipakai PHPUnit — semua test Inbox mengosongkan tabelnya di `setUp()`, jadi **jangan pernah jalanin 2 proses PHPUnit bersamaan**), `aulia_inboxdb_perf` (fixture 200.000 baris untuk ukur kecepatan pencarian, diisi lewat `php spark aulia:seed-fase1e-perf --dbgroup=inbox`, command ini menolak jalan kalau database aktif bukan `aulia_inboxdb_perf`). Resep provisioning ketiganya ada di `docs/ARCHITECTURE.md` §11
- ~~Tes reboot sungguhan untuk auto-start PM2~~ — **dicoret 21 Sep: di luar scope pengembangan** (auto-start sudah terpasang dan disimulasikan dengan `pm2 kill`)

---

## Status Per Tahap

### Tahap 0 — Baseline ✅ DONE (20 Sep 2026)

Decision log: `docs/decisions/2026-09-19-tahap-0-baseline.md`, commit `4062833`, branch `claude/tahap-0-aulia-wa-handoff-ic861g`

Hasil ringkas (item "dilaporkan" berasal dari decision log Tahap 0 dan tidak dijalankan ulang di Aan-PC; `phpunit` di sini menampilkan ringkasan berbeda, `8 PASS, 0 FAIL`, sehingga angka 82/144 tidak bisa dicocokkan):
- [x] Test unit AuliaPos: 82 test + 144 assertion PASS (dilaporkan)
- [x] Test database: 61/61 PASS (dilaporkan; jalan di SQLite, bukan MySQL — limitation tercatat)
- [x] Test session: **2 ERROR lama sudah tidak ada** — diverifikasi 28 Sep 2026: `LaporanBulananExcludeBatalTest` lulus `OK (2 tests, 4 assertions)` dan suite penuh hijau. Catatan "63 PASS, 2 ERROR (`no such table: db_closing_kas`)" sudah basi, jangan dibaca sebagai status terkini
- [x] Smoke test incoming/outgoing/fromMe: semua lolos (dilaporkan)
- [x] Verifikasi ulang kasus dekripsi gagal (`AC0B72AD…`) di kondisi bersih: **tidak ada bug sistemik di kondisi normal** (16/16 fromMe sukses, 19/19 incoming/sticker burst sukses)
- [x] Hipotesis "gagal tepat setelah restart Gateway" diuji lewat M1 Ticket 01 skenario 2 (21 Sep). **Hasil: pola pesan hilang saat restart terkonfirmasi, tetapi mekanisme "dekripsi gagal" dibantah.** Penyebab yang ditemukan: pesan offline (`append`) dibuang oleh `connectionManager.js:385`. Lihat `docs/decisions/2026-09-21-m1-ticket01-baseline.md`

4 limitation tercatat:
- [ ] Test soft-delete tidak jalan
- [ ] Test DB pakai SQLite, bukan MySQL
- [x] Duplicate-on-timeout belum teruji → **sudah diuji 21 Sep** (M1 Ticket 01 skenario 3): retry `/send` menduplikasi pesan pada 2 dari 3 percobaan
- [ ] WebP non-sticker belum diverifikasi via WhatsApp asli

---

### M1 — Reliability 🚦 WAVE 1 + WAVE 2 SELESAI — Ticket 01 (pengukuran) selesai 21 Sep; Wave 1 (Ticket 02–04) selesai + live, AC-001 3/3 nyata; **Wave 2 (idempotensi outgoing) plan Completed 27 Sep**; Ticket 05–16 belum

**Status nyata (dicek 21 Sep, diperbarui 28 Sep)**: Ticket 01 dijalankan di Aan-PC pada 21 Sep. Hasil lengkap: `docs/decisions/2026-09-21-m1-ticket01-baseline.md`. Ticket 01 murni pengukuran (tanpa perbaikan kode). **Update 23 Sep:** Wave 1 sudah **dideploy ke folder live** (`065f683` — fast-forward + `pm2 restart`, TASK-019) dan **AC-001 diukur dengan Gateway nyata**: 3/3 percobaan `pm2 stop`, 30/30 pesan, 0 hilang, 0 duplikat → **risiko P0 #1 tertutup**. Risiko P0 #2 (JSON fallback), #3 (idempotensi `/send`), #4, dan #5 masih terbuka. Rincian: `docs/decisions/2026-09-23-m1-wave1-deploy-dan-ac001.md`.
- [x] **Wave 2 — Idempotensi outgoing (selesai 27 Sep 2026)**: spec `spec/spec-process-m1-wave2-outgoing-idempotency.md`, plan `plan/plan-process-m1-wave2-outgoing-idempotency-v1.0.md` **Completed** (Fase 1–5; `operation_id` dibuat di frontend, lease 35 detik, batas 5 percobaan kirim, dedupe via `findMessageByOperationId()`, penerjemahan jawaban Gateway `SEND_IN_PROGRESS`/`SEND_UNRESOLVED` → `uncertain: true`). Fase 5 **migrasi `aulia_inboxdb` nyata**. Klarifikasi plan 88/100 PROCEED. Plan bugfix `plan-bugfix-outgoing-idempotency-f1-f2-v1.0.md` (F-1/F-2) juga **Completed**.
- [x] **`/sdlc-code-review` M1 (23 Sep)**: laporan `docs/audit/code-review-m1-wave1-2026-09-23.md` — verdict **0 P0 / 1 P1 / 14 P2**, 19/19 REQ terpenuhi, tidak ada temuan yang membatalkan AC-001; perbaikan dikumpulkan di `plan/plan-refactor-m1-wave1-incoming-reliability-v1.0.md` (plan refactor **Completed** — Fase 1 + Fase 2, TASK-101..209, 9 komit `2ca3065`..`fb585f1`, masuk `master` lewat PR #4 `21a4cb6`; CR-01/02/03/04/13/14/16 diperbaiki, backlog CR-05..CR-11 + CR-15; **di-deploy ke folder live 23 Sep 18:47 WIB**, bukti refactor = simulasi, AC-001 nyata belum diulang untuk `21a4cb6`).

**Ticket 01 — Baseline Test** (rencana asli: `m1-ticket01-baseline-eksekusi.md`, di luar repo):
- [x] 1. Enqueue normal — otomatis (mock CI4): 50/50 `completed` dalam satu siklus
- [x] 1. Enqueue normal — versi asli (pesan WhatsApp nyata, dibandingkan dengan `messages` AuliaPos): 3 burst × 15 pesan (incoming dari WhatsApp Web, incoming dari HP tes, fromMe dari HP Gateway) = **45/45 sampai, 0 hilang, 0 duplikat**
  - Tetapi 40 error dekripsi dengan retry, urutan tiba dan `message_timestamp` bergeser (sebaran 28–58 detik)
  - Penyebab error dekripsi belum diketahui. Dugaan "sesi tercemar oleh `/send` ke alamat nomor telepon" sudah dicabut (lihat koreksi di decision log)
- [x] 1. Penyelidikan pasif penyebab error dekripsi selesai (21 Sep). 15 dari 55 error adalah pengiriman ulang pesan yang sudah diproses setelah restart (benign, tanpa duplikat). Sisanya: pesan beralamat nomor telepon gagal dulu 10 dari 10, alamat LID 17 dari 35 sesudah 07:02 UTC, dan 0 dari 41 sebelumnya
  - [ ] Penyebab **belum terbukti**. Hipotesis utama H1: sesi beralamat nomor telepon setelah `/send` ke alamat itu. H2: kill saat mengenkripsi (skenario 3 T2). Uji pembeda butuh nomor uji kedua, dicatat sebagai kandidat M1 Ticket 05
- [x] 2. Restart Gateway saat burst (3 percobaan, kill di awal/tengah/akhir): hilang 3/14 dan 3/15 pada percobaan 2 dan 3. Penyebab: pesan offline bertipe `append` dibuang di `connectionManager.js:385` (`type !== 'notify'`) setelah di-ack Baileys
- ~~2. Percobaan 1 (K=2) diulang dengan pesan berhuruf unik dan hitungan kirim yang dicatat~~ — **dicoret 21 Sep**: pola sudah terlihat di percobaan 2 dan 3 (3 pesan hilang di masing-masing), percobaan 1 tidak bisa dinilai karena hitungan kirim tidak dicatat
- [x] 3. Duplicate-on-timeout: retry `/send` menduplikasi pesan pada 2 dari 3 percobaan (T1, T3); T2 (Gateway dimatikan) kiriman pertama hilang, tidak duplikat
- [x] 3. Perilaku UI Inbox AuliaPos saat Gateway bermasalah di tengah kirim (diuji 21 Sep, 3 percobaan lewat Inbox)
  - Gateway mati sebelum pesan keluar: tampil error jelas, teks tetap di kotak, retry menghasilkan 1 pesan (aman)
  - Gateway lambat lebih dari 10 detik (timeout AuliaPos): tampil "Gagal mengirim pesan", padahal pesan akhirnya terkirim. Retry membuat **pelanggan menerima 2 pesan sama**, dan kiriman pertama tidak tercatat di Inbox
- [x] 4. Retry/backoff — otomatis (mock CI4) dan **versi nyata** (AuliaPos dimatikan 6 menit, 5 pesan): pulih tanpa kehilangan (5/5, 0 duplikat), pemulihan 115 detik setelah AuliaPos hidup
  - Interval retry terukur 3, 6, 12, 24, 48, 96, 120, 120 detik. `attempts` naik sampai 8 tanpa batas atau dead-letter (teramati sampai 8, sisanya dari kode)
  - Buffer tidak menggeser timestamp, tetapi **urutan pesan di Inbox salah**: urutan kirim `Sjjs, Hhaaa, Hhhah, Hss, Hhsj` tampil sebagai `Hhaaa, Hhhah, Sjjs, Hhsj, Hss` (timestamp yang diterima Gateway sudah bergeser)
- [x] Decision log Ticket 01 ditulis: `docs/decisions/2026-09-21-m1-ticket01-baseline.md`

**Ticket 02-16**: audit Ticket 02 selesai. **JANGAN pakai baris di bawah ini sebagai acuan eksekusi** — plan resmi `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` sudah ada untuk Ticket 02-04 (gelombang 1) dan itu yang harus diikuti. Baris di bawah ini murni riwayat apa yang sempat terjadi di sesi sandbox 21 Sep, ditulis ulang malam harinya setelah revert:
- [x] 02. Audit enqueue — audit selesai 21 Sep (8 titik kehilangan, dari E-01 `append` yang terukur sampai E-09 JSON), laporan `docs/decisions/2026-09-21-m1-ticket02-audit-enqueue.md`.
  - **Perbaikan ad-hoc 21 Sep sore (repo WA-Gateway, branch `claude/buka-todo-chat-omnc7k`, merge PR #2 ke `feature/stage-1-reliability`) — dikerjakan TANPA sadar plan resmi sudah ada:**
    - [x] → **direvert malam harinya (PR #3)** E-01 — sempat menerima tipe `append`, tapi plan resmi (ALT-002) menemukan Baileys juga memancarkan `append` untuk kiriman Gateway sendiri; tanpa `ownSentRegistry` berisiko duplikat pesan keluar. Kembali ke `notify`-only
    - [x] **masih ada, belum tentu final** E-03 — `enqueue()` validasi field wajib, throw kalau kosong. Plan resmi (TASK-001) minta kontrak berbeda (`{status:'inserted'|'duplicate'}` + tipe error khusus yang tidak di-retry) — perlu diperiksa ulang
    - [x] **masih ada, belum tentu final** E-04 — `_enqueueWithRetry()` 3×200ms. Plan resmi (TASK-002/003/004) minta jeda 50/200/800ms + overflow buffer 500 event yang dikuras di siklus worker — belum ada di implementasi ad-hoc
    - [x] **masih ada, belum tentu final** E-05 — timeout query LID 5 detik. Plan resmi (TASK-013) minta 2 detik + cache negatif 60 detik per JID — cache negatif belum ada
    - [x] → **direvert malam harinya (PR #3)** E-06 — sempat menyimpan record minimal saat pemrosesan gagal, tapi plan resmi (ALT-004) menolak eksplisit pendekatan ini (jadi poison message). Kembali ke: hanya log
    - [x] **masih ada, belum tentu final** E-09 — karantina + pemulihan `.bak` JSON fallback. Cukup dekat dengan plan resmi (TASK-015) tapi belum dicocokkan detail
    - [ ] E-02, E-07 — **belum dikerjakan**, butuh verifikasi WhatsApp nyata dulu (juga eksplisit di luar scope plan resmi)
  - **Status akhir (malam 21 Sep, setelah eksekusi plan resmi di worktree `C:\projects\WA-Gateway-m1`):** butir E-03/E-04/E-05/E-09 bertanda "masih ada, belum tentu final" di atas sudah **ditimpa/disesuaikan** mengikuti plan resmi (TASK-001 s/d TASK-004, 013, 015). E-01 diimplementasikan ulang lewat `ownSentRegistry` (TASK-008 s/d TASK-010) dan E-06 sebagai isolasi error + log lengkap tanpa pesan minimal (TASK-014). Bukti **simulasi saja**. Rincian: `docs/decisions/2026-09-21-m1-wave1-eksekusi-fase1-3.md`
  - **Test simulasi (mock, bukan Gateway nyata) ditulis untuk semua di atas**, disesuaikan lagi setelah revert (`test/simulate-e05-lid-timeout.js` menggantikan `simulate-e05-e06-fallback.js`, `simulate-append-type.js` dihapus). Semua lolos, tidak regresi — tapi ini bukti simulasi, bukan bukti Gateway nyata
  - **Handoff lengkap + instruksi lanjutan untuk sesi Claude Code lokal**: `docs/decisions/2026-09-21-handoff-m1-wave1-eksekusi-lokal.md` — baca ini sebelum melanjutkan Ticket 02, bukan ringkasan di atas
- [x] 03. Durable buffer — kode + simulasi selesai (TASK-002 s/d TASK-006: retry 50/200/800 ms, overflow 500 event, `PRAGMA quick_check` SQLite); bukti **simulasi**; di-deploy ke folder live 23 Sep dan berjalan live (`065f683`); masuk `origin/master` lewat PR #4 (`21a4cb6`)
- [x] 03. Durable buffer — verifikasi nyata (TASK-017, AC-001): **lulus 3/3, 30/30 pesan, 0 hilang, 0 duplikat** (23 Sep); deploy ke folder live lewat TASK-019 (fast-forward, rollback point `e18f716`); merge ke `origin/master` lewat PR #4 (`21a4cb6`)
- [x] 04. JSON recovery — kode + simulasi selesai (TASK-015: 8 skenario, lima celah E-09 ditutup); bukti **simulasi**
- [x] 04. JSON recovery — verifikasi nyata di build Android (25 Sep 2026): device fisik Samsung SM-G975F (`RR8N201VC9T`), `com.auliapos.wagateway`, `node_modules` dikonfirmasi TANPA `better-sqlite3` (fallback JSON aktif nyata, bukan simulasi), 2 skenario kunci direproduksi lewat mutasi file + `adb logcat` (utama korup + `.bak` sehat → pulih 22/22 baris via `warn`; keduanya korup → `[CRITICAL]` + mulai kosong, tidak crash), data produksi 22 pesan pelanggan asli dipulihkan utuh (MD5 dikonfirmasi di device). Detail: `docs/decisions/2026-09-25-ticket04-json-fallback-android-verifikasi-nyata.md`. **Batas jujur:** hanya 2/8 skenario diuji manual di HP (6 sisanya dijamin kode identik + suite desktop 8/8); ASSUMPTION-007 (`outgoing_operations`, modul terpisah) masih terbuka.
- [x] 04b. (baru, resolved) Temuan tak terkait dari sesi 04 — `.env` device `CI4_BASE_URL` alamat IP terpotong (`http://192.168.10/aulia`), penyebab 22 pesan (lalu total 62 baris terhitung ulang lewat DB, 10 percakapan) macet `failed`. **Diperbaiki operator toko** (alamat dibetulkan + Gateway restart, 25 Sep 2026), dikonfirmasi via audit `aulia_inboxdb.messages`: seluruh backlog mendarat. Efek samping ditemukan: urutan `id` insert saat flush TIDAK selaras dengan `message_timestamp` (7 inversi) — bukti tambahan untuk `plan/plan-bugfix-inbox-message-ordering-v1.0.md`; dan `conversations.last_message_at` bisa tertimpa ke nilai lebih lama di 7 percakapan (`InboxGatewayApi.php:260-276` tidak menjaga arah maju) — **belum ada plan perbaikan**, direkomendasikan `/sdlc-bug-report` terpisah. Detail: update §7 `docs/decisions/2026-09-25-ticket04-json-fallback-android-verifikasi-nyata.md`.
- [ ] 05. Crash/restart test
- [ ] 06. Attempt counter
- [ ] 07. Dead-letter
- [ ] 08. Poison-message test
- [ ] 09. Outgoing operation ID
- [ ] 10. Idempotency
- [ ] 11. Ambiguous-send recovery
- [ ] 12. Worker correctness
- [ ] 13. Structured logging
- [ ] 14. Metrics / health
- [ ] 15. Full reliability test matrix
- [x] 16. Merge — **PR #4 di-merge ke `master` (merge commit `21a4cb6`, 23 Sep malam)**, berisi 21 komit M1 + 9 komit refactor. Deploy: folder live `065f683` (16:40 WIB) lalu `21a4cb6` (18:47 WIB), keduanya fast-forward + `pm2 restart`

Risiko P0 yang jadi alasan M1 ada:
- [x] 1. Incoming enqueue failure — pesan hilang. **Terbukti nyata 21 Sep**: pesan yang tiba saat Gateway offline dibuang diam-diam (3 dari 14 dan 3 dari 15). **Fix kode sesuai plan resmi** (TASK-008 s/d TASK-010) **diverifikasi 23 Sep dengan restart Gateway sungguhan**: TASK-017/AC-001 lulus 3/3 (30/30 pesan, 0 hilang, 0 duplikat) pada Gateway live yang menjalankan `065f683` → **ditutup**. Bukti: `docs/decisions/2026-09-23-m1-wave1-deploy-dan-ac001.md`
- [x] 2. JSON fallback corruption — queue rusak berisiko restart dari kosong. **Fix kode sesuai plan resmi sudah ada 21 Sep malam** (TASK-015: karantina, pemulihan `.bak`, cadangan divalidasi sebelum disalin), diuji lewat simulasi 8 skenario. **Diverifikasi nyata 25 Sep 2026** di build Android sungguhan (device fisik, `better-sqlite3` dikonfirmasi absen, 2 skenario kunci lolos lewat `adb logcat`, data pelanggan asli 22 baris pulih utuh) → **ditutup untuk `incomingBuffer`**. Bukti: `docs/decisions/2026-09-25-ticket04-json-fallback-android-verifikasi-nyata.md`. ASSUMPTION-007 (`outgoing_operations`, modul Wave 2 terpisah) masih terbuka
- [ ] 3. Outgoing duplicate — belum ada idempotency saat timeout. **Terbukti nyata 21 Sep** (skenario 3, 2 dari 3 percobaan) dan **terbukti lewat Inbox AuliaPos** (Gateway dijeda 12 detik: `U03` diterima 2× di HP pelanggan)
- [ ] 4. (baru) Retry pesan masuk tanpa batas percobaan dan tanpa dead-letter — dari kode, belum diamati berjalan lama
- [ ] 5. (baru) Dekripsi pesan gagal lalu di-retry: urutan tiba dan `message_timestamp` bergeser (sebaran 28–58 detik pada burst 15 pesan), padahal AuliaPos memakai timestamp untuk urutan Inbox dan `last_message_at` (dasar SLA di M3). Health tetap `connected` selama itu. Penyebab belum diketahui
- [x] 6. (baru) Requirement Gateway untuk AuliaPos disimpan di `docs/GATEWAY-REQUIREMENTS.md` (GW-01 s/d GW-25, dengan status terukur)
- [ ] 7. (baru) Pesan masuk beralamat campuran nomor telepon dan LID gagal didekripsi dulu (GW-25), penyebab belum terbukti; belum diuji pada kontak baru

---

### M2 — State Consistency ⏳ BELUM MULAI

Fokus:
- [ ] Ownership atomic
- [ ] Delivery state eksplisit
- [ ] Audit transition
- [ ] Health yang bisa dipercaya

- [x] Risiko dikonfirmasi lewat analisis kode: `cekOwnership()` di AuliaPos adalah **read-then-write di level aplikasi, bukan atomic di database** — risiko P1 "ownership race" (`app/Controllers/Inbox.php`, `cekOwnership()` membaca `assigned_to` lalu memutuskan, tanpa transaksi/kunci)
- [~] **Pengecualian yang sudah atomic**: `Inbox::ambilPercakapan()` (conditional UPDATE + `affectedRows()` → 409) dan jalur **Handoff** Fase 2a (pola `expected_owner` yang sama). Jadi bukan "semua ownership non-atomic" — yang masih **non-atomic**: `lepas`, `tutup`, `snooze`, `tandaiDibaca`, `hapus` (`docs/ARCHITECTURE.md` §12)
- [ ] Breakdown ticket detail M2 — menyusul. (Catatan: M2 dulunya "prasyarat keras" Fase 2 M3, tapi prasyarat itu sudah dicabut; M2 sekarang murni melanjutkan sisa jalur non-atomic di atas)

---

### M3 — Operational Inbox 🚦 FASE 1 (1a–1e) + FASE 2a SELESAI & TER-MERGE (28 Sep 2026)

> [!NOTE]
> **Seksi ini sudah disinkronkan 28 September 2026.** Fase 1 (1a–1d), **Fase 1e (pencarian teks pesan, GH-010)**, dan **Fase 2a (Handoff + Collision Detection)** sudah dieksekusi dan ter-merge ke `v2.3` (Fase 1/2a lewat PR #41 `ce94660`; Fase 1e + remediasi 25 Sep). Di atas itu, **Grup Tahap 1 & 2, Balas Pesan Tahap 3, dan Inbox Read Authorization** juga sudah selesai — lihat bagian "Status Sekarang". Plan-plan M3 yang relevan berstatus `Completed`. Yang **belum**: **Fase 2b (auto-assignment)** dan **Fase 3** (butuh M5).
> Semua daftar "Riwayat (21 Sep)", checklist Ticket 01/02-16, dan keputusan desain di bawah adalah **potret historis yang sudah dilewati** — jangan dibaca sebagai status terkini. Status fase M3 ada di paragraf "Pembagian fase M3" di bawah.

**Riwayat (21 Sep)**: sesi Claude Code menghasilkan spec + plan formal M3 lewat proses SDLC terstruktur (spec → clarification report → remediasi → plan → clarification report kedua → resolusi). Semua keputusan desain 🔶 yang ditandai sebelumnya **sudah diresolusikan**.

Dokumen acuan M3 Fase 1 (semuanya sudah dilewati; disimpan sebagai jejak):
- [x] `spec/spec-design-m3-operational-inbox-fase1.md`
- [x] `docs/audit/clarification-report-m3-fase1-operational-inbox-spec-2026-09-20.md` — klarifikasi spec + remediasi
- [x] `docs/adr/0001-reuse-response-state-for-queue-view-status.md` — keputusan status granular: **computed**, reuse `Inbox::attachResponseState()` (ADR resmi)
- [x] `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — **Completed** (14 task)
- [x] `plan/plan-feature-m3-operational-inbox-fase2a-v1.0.md` + `plan/plan-refactor-m3-fase2a-handoff-collision-v1.0.md` — **Completed**
- [x] `plan/plan-refactor-m3-fase1e-message-search-v1.0.md` — **Completed** (Fase 3 ditolak → 3 TODO, lihat "Yang Menggantung" butir B1–B3)
- [x] `docs/audit/clarification-report-m3-fase1-operational-inbox-plan-2026-09-21.md` — klarifikasi plan, 7 resolusi sudah ditulis ke TASK-002, 008, 011, 012

**Keputusan desain yang sudah final (bukan lagi 🔶):**
- [x] 1. Status granular → **computed** via `ConversationModel::withComputedStatus()`, reuse `attachResponseState()` — ADR-0001
- [x] 2. Threshold SLA → Hijau `<15m`, Kuning `15-60m`, Merah `>60m`, sumber waktu `last_message_at` (REQ-010)
- [x] 3. Customer Context → **dibatasi ke Fase 1a dasar**, tidak menarik M4 (konsisten prinsip urutan)
- [x] 4. Internal Note → kolom `is_internal BOOLEAN` di `messages` (REQ-007), bukan tabel terpisah
- [x] 5. @mention → **tidak masuk Fase 1** (disederhanakan, tidak disebut lagi di plan — kemungkinan didrop, perlu dikonfirmasi eksplisit kalau perlu dipastikan)

**Pembagian fase M3 (status terkini 28 Sep):**

- [x] **Fase 1a** — `ConversationModel::withComputedStatus()` + dipakai `Inbox::apiConversations()` (tanpa migration)
- [x] **Fase 1b** — migration `messages.is_internal`, endpoint Internal Note (`Inbox::catatanInternal()`, High Risk RISK-001), `InboxSlaService`, filter/pencarian, Alasan Snooze
- [x] **Fase 1c** — layar Inbox (`plan-refactor-m3-fase1c-inbox-screen-v1.0.md` Completed)
- [x] **Fase 1d** — pencarian kolom identitas (filter-after-fetch di PHP, CON-003)
- [x] **Fase 1e (GH-010)** — pencarian teks pesan + `match_snippet`; `InboxMatchSnippetService`; Spark command perf `aulia:seed-fase1e-perf`; AC-016 median 436/523/1010 ms (target ≤ 3 s). Diremediasi atas 2 temuan `[REQUIRED]` review — lihat `docs/walkthrough-m3-fase1e-message-search-2026-09-25.md` + `docs/walkthrough-m3-fase1e-remediation-2026-09-25.md`. **Fase 3 plan ditolak** → 3 TODO (butir B1–B3).
- [x] **Fase 2a** — Handoff + Collision Detection (`conversation_handoffs`, conditional write `expected_owner`, 403/409/400)
- [ ] **Fase 2b** — **Auto-assignment** (GH-008). Belum mulai; dipisah dari 2a karena lingkup berbeda.
- [ ] **Fase 3** — intent filters, AI summary, suggested reply. **Tunggu M5**, tidak berubah.

> **Prasyarat M2 sudah tidak dipakai untuk Fase 2.** PRD diamandemen ke **v1.1** (menambah GH-006 Handoff, GH-007 Collision Detection, GH-008 Auto-assignment) supaya Fase 2 bukan lagi *Orphaned Item*. Fase 2a selesai dengan pola *conditional write* sempit (`WHERE id = ? AND assigned_to = ?` → `affectedRows() === 0` = 409) yang tidak butuh M2 penuh. **M2 sebagai program besar tetap belum dimulai** — `docs/ARCHITECTURE.md` §12 masih mencatat jalur `lepas`/`tutup`/`snooze`/`tandaiDibaca`/`hapus` sebagai non-atomic; hanya `ambilPercakapan()` dan jalur Handoff yang atomic.

**Risiko teknis yang tercatat di plan Fase 1 (jejak historis):**
- RISK-001 (High Risk): endpoint Internal Note rawan *silent violation* kalau ikut update `last_message_at`/`last_message_direction` — bisa merusak badge & Queue View tanpa error kelihatan. Mitigasi diterapkan: test assert kolom itu TIDAK berubah. **Invariant ini masih berlaku** (`catatanInternal()` tidak boleh memanggil `ConversationModel::update()`).
- RISK-002: filter-after-fetch berpotensi lambat di data besar — **terbukti masih aman**: AC-016 median 436–1010 ms jauh di bawah target 3 s pada 200.000 pesan. `LIKE '%q%'` tetap tanpa index (RISK-007), dicatat sebagai risiko yang diterima.

**Migrasi yang ditambahkan M3 (semua additive):**
- `messages.is_internal` (Fase 1b) — satu-satunya migrasi Fase 1
- `conversation_handoffs` (Fase 2a) — `conversation_id BIGINT UNSIGNED`, `from_user_id` nullable, `initiated_by_user_id` NOT NULL; index `(conversation_id, created_at)` + `to_user_id`
- 7 kolom `messages.quoted_*` + `quoted_source_message_id` + `quoted_media_type` (Balas Pesan Tahap 3)
- **Tidak ada** kolom `snooze_reason` (ditolak; alasan snooze disimpan sebagai Internal Note)

---

### M4 — POS / Customer Context ⏳ BELUM DIBAHAS

- [ ] Rencana detail M4 (baru disinggung sebagai prasyarat untuk Customer Context penuh — order/payment — di Conversation Detail M3)

---

### M5 — Intelligence / AI ⏳ BELUM DIBAHAS

- [ ] Rencana M5. Ditunda sampai fondasi M1-M4 stabil. Fase 3 di blueprint M3 (intent filters, AI summary, suggested reply) menunggu ini.

---

## Yang Menggantung — Action Items Konkret

> **Diperbarui 29 September 2026.** Keputusan prioritas lama "M1 dulu vs M3 duluan" sudah **tidak relevan** — M1 Wave 1 & 2 selesai, M3 Fase 1 (1a–1e) + Fase 2a selesai, dan seluruh fitur Inbox (Grup Tahap 1/2, Balas Pesan Tahap 3, Inbox Read Authorization, Teruskan Tahap 4) selesai. Seksi ini sekarang berisi pekerjaan yang **benar-benar masih terbuka**.

### A. Pilihan arah berikutnya (per 29 Sep 2026)

- [x] **A1. Fitur Teruskan (Tahap 4 / GH-016)** — **SELESAI** dua repo (plan AuliaPos + Gateway `Completed`, Gateway live `4a766d2`, bukti native terlihat di WhatsApp). Arah berikutnya bergeser ke A2/A3/A4.
- [ ] **A2. M3 Fase 2b — Auto-assignment (GH-008)** — belum mulai; melengkapi M3 di samping Fase 2a yang sudah selesai.
- [ ] **A3. M2 — State Consistency (program besar)** — jalur `lepas`/`tutup`/`snooze`/`tandaiDibaca`/`hapus` masih non-atomic (`docs/ARCHITECTURE.md` §12). **Bukan blocker lagi** untuk M3, tapi belum pernah dikerjakan.
- [ ] **A4. M1 Ticket 05–16 (sisa gelombang reliability Gateway)** — khususnya Ticket 05 (uji pembeda penyebab error dekripsi, butuh nomor uji kedua) dan risiko P0 #3/#4/#5 (lihat seksi C).

### B. Pekerjaan kecil yang masih terbuka (non-blocking)

- [ ] **B1.** (25 Sep) **Tolak `q` non-UTF-8 dengan HTTP 400** di batas input `app/Controllers/Inbox.php` — byte rusak menjadi 500 di bawah `DBDebug = true`. Asal: `plan-refactor-m3-fase1e-message-search-v1.0.md` TASK-301 (`[OPTIONAL]`, ditolak pemilik 25 Sep). Test: `?q=%FF` → 400, kata kunci multi-byte valid (`é`) → 200. **Tidak mendesak.**
- [ ] **B2.** (25 Sep) **Pindahkan `potong()` ke sesudah paginasi** supaya `match_snippet` tidak dihitung untuk baris yang dibuang paginasi. Perilaku wajib byte-identical, jadi seluruh suite jadi bukti regresi. Asal TASK-302. **Tidak mendesak** (median AC-016 436–1010 ms jauh di bawah target 3000 ms).
- [ ] **B3.** (25 Sep) **`SeedFase1ePerf` memverifikasi hitungannya sendiri** lewat `countAllResults()` + catat komposisi fixture. Asal TASK-303. **Tidak mendesak** (perkakas ukur internal).
- [x] **B4.** ~~Perbaiki 2 ERROR test session Tahap 0~~ — **DIBATALKAN: sudah tidak ada.** Diverifikasi 28 Sep 2026: `vendor/bin/phpunit --filter LaporanBulananExcludeBatalTest` → `OK (2 tests, 4 assertions)`. Error `no such table: db_closing_kas` (20 Sep) sudah hilang; **tidak ada pekerjaan yang diperlukan**.
- [x] **B5.** ~~Putuskan nasib `G:\arsip-gateway\`~~ — **SELESAI: dicoret.** Diverifikasi 28 Sep 2026 (`Test-Path`): folder `G:\arsip-gateway` **tidak ada**, dan drive `G:\` sendiri juga tidak ada di mesin ini. Tidak ada yang perlu diputuskan.
- [x] **B6.** Catatan kaki `docs/CHAT.md` §13 (baris 323/331) **sudah diperbarui** 28 Sep 2026: frasa "mengikuti aturan visibility inbox yang sudah ada" diganti penjelasan eksplisit **baca terbuka** (ALT-003/AUTHZ-02, `REQ-001` di `spec/spec-design-inbox-read-authorization.md`) — kepemilikan hanya membatasi operasi tulis, bukan visibilitas baca.
- [x] **B7.** **Selesai** 28 Sep 2026 — dua item out-of-scope dari `spec/spec-design-grup-tahap2-identitas.md` §14 kini tercatat eksplisit di grup **D** di bawah.
- [ ] **B8.** **PRD Section 4 note (baris 158–165)** & ketidakcocokan fase **AC GH-012** — dibawa sejak beberapa sesi, belum ditindaklanjuti.

### C. Risiko Gateway yang masih terbuka (dari Ticket 01/02)

- [x] **C1. Risiko P0 #3** — duplicate-outgoing saat timeout. **SELESAI 28 Sep 2026**: 4 putaran uji nyata di Gateway + WhatsApp, **0 duplikat** (replay di jalur dalam lease, `409` diuji, pemulihan pasca-lease terbukti). Bukti: `docs/decisions/2026-09-28-c1-p0-3-outgoing-idempotency-remeasurement.md`. **ASSUMPTION-009 tetap OPEN** (celah ~1 ms "sudah diterima WhatsApp tapi belum tercatat"; penutup penuhnya GW-21 di M2) — jangan pernah menulis "duplikat mustahil".
- [x] **C2. Risiko P0 #4** — retry pesan masuk tanpa batas percobaan dan tanpa dead-letter. **SUDAH BERES sejak M1 Wave 2 Fase 3** (TASK-011..TASK-014): batas percobaan, batas usia, status `dead`, dan kolom `dead_lettered_at`. Diverifikasi 28 Sep 2026 langsung pada Gateway hidup: kolomnya ada di `incoming_queue` dan 0 baris berstatus `dead`. Sisa frasa "belum diamati jangka panjang" bersifat pemantauan, **bukan** cacat — Gateway memang sudah mencatat jumlah dead-letter saat start-up.
- [ ] **C3. Risiko P0 #5 / GW-25** — error dekripsi + `message_timestamp` bergeser (sebaran 28–58 detik); penyebab belum terbukti, butuh nomor uji kedua. **ESC-001..004 (GW-11 / GW-25) tetap OPEN** — sumber timestamp dikunci di luar repo ini. Catatan 28 Sep 2026: pada satu pesan masuk nyata, selisih `message_timestamp` vs `created_at` hanya **0,34 detik** dan tidak ada error dekripsi sama sekali — jadi **tidak tereproduksi** dari sampel tunggal ini; perlu nomor uji kedua seperti tertulis.
- [x] **C4. E-02 — akar masalah DIKOREKSI, perbaikan TERVERIFIKASI 28 Sep 2026, SUDAH DI-DEPLOY (`66bff03`).** Uji E2E nyata (instance Gateway terpisah, nomor uji `6281913500707`): teks biasa, foto biasa, dan dokumen + keterangan **sampai**; foto **"lihat sekali"** sebelumnya **hilang total**. **Koreksi akar masalah:** view-once ke perangkat tertaut **bukan** `viewOnceMessageV2` — WhatsApp mengirim stanza `<unavailable type="view_once">` **tanpa isi**, Baileys menandai `msg.key.isViewOnce = true` dan membiarkan `msg.message` undefined (`node_modules/baileys/lib/Utils/decode-wa-message.js:127,192-195`; `lib/Socket/messages-recv.js:624-632`), lalu tetap memancarkan `messages.upsert` (`lib/Socket/chats.js:764-765`). Gateway lama membuangnya di **baris pertama** `_handleIncomingMessage` (`if (!msg.message) return;`) — **bukan** di cabang `debug` seperti dugaan awal. **Perbaikan** (ter-commit & ter-deploy di `master` sebagai `66bff03`, sudah termasuk di `4a766d2`): deteksi `!msg.message && msg.key?.isViewOnce === true` → placeholder teks tanpa mengambil media; cabang `viewOnceMessageV2` tetap dipertahankan untuk jalur resend dari HP utama. Terverifikasi: `incoming_queue` id 5 + AuliaPos `messages` id 900041 (`wa_message_id` `3EB0F537BC07D69307D502`), `media_json = null`. Rencana + amandemen: `plan/plan-bugfix-wa-gateway-viewonce-unsupported-v1.0.md`. **E-07 (upsert tanpa konten) tetap belum terbukti.**

### D. Keputusan produk tertunda (bukan bug, bukan pekerjaan teknis)

> Dicatat 28 Sep 2026 supaya tidak hilang saat dokumen ini disinkronkan. Keduanya berasal dari `spec/spec-design-grup-tahap2-identitas.md` §14 dan **sengaja di luar lingkup** PRD Inbox saat ini (PRD tidak memintanya) — butuh keputusan produk sendiri bila suatu saat diperlukan, bukan perbaikan bug.

- [ ] **D1. Sinkronisasi ganti-nama grup** — setelah `conversations.group_name` terisi pertama kali, nama grup berikutnya dari WhatsApp **tidak menimpanya** (`REQ-006` write-once). Kalau admin grup mengganti nama di WhatsApp, Inbox tetap menampilkan nama lama. Sumber: `spec-design-grup-tahap2-identitas.md` §12 (Edge case) dan §14 (TODO).
- [ ] **D2. Pencarian percakapan lewat nama grup asli** — `group_name` **tidak** termasuk `SEARCH_COLUMNS`, jadi grup tidak bisa ditemukan lewat nama aslinya (grup masih bisa ditemukan lewat `whatsapp_name` atau isi pesan). Sumber: `spec-design-grup-tahap2-identitas.md` §1.1, §13, dan §14 (BACKLOG, T7).

> **Catatan 25 Sep — batas yang perlu diketahui saat membaca ini.** Regresi pada pengaman putaran daftar Inbox (`app/Views/inbox/index.php`) **tidak akan menggagalkan test otomatis**, karena proyek ini tidak punya test runner JavaScript. Yang menjaganya: harness `build/check-round-guard.php` (di luar repo, gitignored — jalankan manual) dan checklist browser. Kalau bikin perubahan pada `muatUlangDaftarConversation()`, jalankan keduanya.

---

## File-File Referensi

**Dari sesi chat ini (di luar repo, mungkin perlu disalin manual kalau belum ada di repo).** Centang berarti file ada di `C:\Users\AAN\Downloads` di Aan-PC. Yang tidak dicentang tidak ditemukan di Downloads, Documents, Desktop, `C:\projects`, dan `G:\` (kedalaman folder sampai 4, dicek 21 Sep); bisa saja ada di perangkat lain:
- [ ] `handoff-tahap0-claude-code.md` — handoff awal Tahap 0 (historis; tidak ditemukan di Aan-PC, Tahap 0 sudah DONE & merged)
- [x] `checklist-eksekusi-lokal-tahap0.md` — checklist smoke test Tahap 0 (historis)
- [ ] `verifikasi-dekripsi-gagal-fromme.md` — investigasi kasus `AC0B72AD…` (historis; tidak ditemukan di Aan-PC, sudah masuk decision log resmi)
- [x] `m1-ticket01-baseline-eksekusi.md` — rencana eksekusi Ticket 01 M1. **Sudah dijalankan 21 Sep** (lihat M1 di atas)
- [ ] `Panduan_Layar_AuliaPos_M3.md` — desain 7 layar M3 awal (tidak ditemukan di Aan-PC; sudah digantikan spec resmi di repo, referensi historis)
- [ ] `blueprint-m3-operational-inbox.md` — blueprint awal (tidak ditemukan di Aan-PC; sudah digantikan plan resmi di repo, referensi historis)

**Di repo AuliaPos (branch `v2.3`, sudah ter-push). Dokumen M1/M3 awal (semuanya sudah dilewati; disimpan sebagai jejak):**
- [x] `docs/decisions/2026-09-19-tahap-0-baseline.md` — decision log Tahap 0 lengkap
- [x] `docs/decisions/2026-09-21-m1-ticket01-baseline.md` — decision log M1 Ticket 01
- [x] `docs/decisions/2026-09-21-m1-ticket02-audit-enqueue.md` — laporan audit M1 Ticket 02
- [x] `docs/decisions/2026-09-21-m1-wave1-eksekusi-fase1-3.md` — eksekusi M1 Wave 1 Fase 1–3: commit, penyimpangan dari spec, bukti simulasi, batas bukti
- [x] `docs/decisions/2026-09-23-m1-wave1-deploy-dan-ac001.md` — deploy M1 Wave 1 + bukti AC-001 nyata
- [x] `docs/decisions/2026-09-25-ticket04-json-fallback-android-verifikasi-nyata.md` — verifikasi Android Ticket 04 (2/8 skenario)
- [x] `spec/spec-design-m3-operational-inbox-fase1.md` — spec M3 Fase 1
- [x] `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — **Completed** (14 task)
- [x] `docs/adr/0001-reuse-response-state-for-queue-view-status.md` — ADR status granular
- [x] `docs/audit/clarification-report-m3-fase1-operational-inbox-spec-2026-09-20.md`
- [x] `docs/audit/clarification-report-m3-fase1-operational-inbox-plan-2026-09-21.md`

**Dokumen fitur Inbox 26–29 Sep 2026 (Grup / Balas Pesan / Read Auth / Teruskan):**
- [x] `prd-20260926-0024-whatsapp-grup-balas-teruskan.md` — PRD v1.2 (GH-011..GH-016)
- [x] `spec/spec-design-grup-tahap1-tab-inbox.md` — v1.2
- [x] `spec/spec-design-grup-tahap2-identitas.md` — v1.4
- [x] `spec/spec-design-balas-pesan.md` — **v1.8**
- [x] `spec/spec-design-teruskan.md` — v1.3; plan Teruskan (AuliaPos + Gateway) sudah ada & `Completed`
- [x] `spec/spec-design-inbox-read-authorization.md` — v1.1
- [x] `plan/` berisi **34 plan** (29 Sep 2026); semua berstatus `Completed` — termasuk pasangan plan Teruskan (AuliaPos + Gateway). Catatan: file `plan-feature-teruskan-wa-gateway-v1.0.md` di disk masih tertulis `status: 'Planned'` (belum di-flip), padahal pekerjaannya sudah selesai & ter-deploy (`4a766d2`)
- [x] `docs/ARCHITECTURE.md` — peta arsitektur, **di-regenerate penuh 29 Sep 2026** (575 baris, template §1–15; sudah termasuk Balas Pesan, Read Auth, Teruskan, batas media, §11 perf-DB, §12 topologi DB, §13 arsitektur Inbox)

**Prinsip update dokumen ini ke depan**: kalau ada progres baru dari sesi manapun, jalankan `git fetch` + `git log <base>..<HEAD_terbaru> --oneline` dulu untuk lihat commit yang masuk sebelum percaya status di dokumen ini — jangan asumsikan dokumen ini otomatis sinkron dengan kerja paralel.
