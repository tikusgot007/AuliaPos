# Status Proyek — AuliaPos + WA-Gateway (Master Reference)

**Terakhir diupdate:** 23 September 2026 WIB, setelah **M1 Wave 1 ditutup** (deploy TASK-019, AC-001 nyata TASK-017 lulus 3/3, APPROVAL TASK-018). **Status M1 Wave 1:** TASK-001 s/d TASK-019 selesai mengikuti `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` (kode `master` di folder live **@ `065f683`**, 21 commit M1 di atas `e18f716`, sudah di-push ke `origin`; **sudah di-merge fast-forward ke folder live dan berjalan di Gateway nyata sejak 23 Sep 16:40 WIB**; sesudah itu branch `feature/stage-1-reliability` bergerak terus lewat sesi refactor paralel pasca `/sdlc-code-review` (beberapa komit di atas `065f683`, **belum di-deploy** — cek `git log` sebelum mengandalkan); PR belum dibuat). Kode ad-hoc sandbox (E-03/E-04/E-05/E-09) sudah ditimpa/disesuaikan sesuai kontrak plan, dan E-01/E-06 diimplementasikan ulang sesuai plan — rincian dan penyimpangan dari spec: `docs/decisions/2026-09-21-m1-wave1-eksekusi-fase1-3.md`. **Bukti AC-001 sudah nyata** (Gateway live, 3× `pm2 stop`: 30/30 pesan, 0 hilang, 0 duplikat); bukti AC-002–AC-018 tetap simulasi. **M1 Wave 1 ditutup 23 Sep** — bukti: `docs/decisions/2026-09-23-m1-wave1-deploy-dan-ac001.md`.
**Cek centang:** 21 September 2026 ~14:30 WIB (diverifikasi langsung ke repo dan mesin Aan-PC) + **pembaruan 23 September 2026**: M1 Wave 1 ditutup dengan **bukti nyata** (Gateway live, 3× `pm2 stop`), bukan lagi hanya simulasi/mock. Legenda: `[x]` = selesai dan terverifikasi (bukti dicatat di samping), `[~]` = selesai sebagian, `[ ]` = belum. Item yang selesai sebagian dipecah jadi dua baris.
**Cara pakai:** Sematkan/paste dokumen ini di awal sesi Claude Code baru sebagai context. Update bagian "Status Sekarang" dan "Yang Menggantung" setiap kali ada progres baru — dokumen ini gampang basi kalau kerja paralel jalan di beberapa sesi Claude Code sekaligus, jadi **selalu `git fetch` + cek HEAD nyata sebelum percaya isi dokumen ini secara buta**.

---

## Tujuan Jangka Panjang

> Mengembangkan WhatsApp Inbox menjadi operational customer workspace, bukan sekadar viewer chat.

## Roadmap Besar

- [x] Tahap 0 — Baseline (DONE 20 Sep; decision log `docs/decisions/2026-09-19-tahap-0-baseline.md`)
- [~] M1 — Reliability (Ticket 01 selesai; **Wave 1 = Ticket 02–04 SELESAI & LIVE 23 Sep** — AC-001 3/3 nyata, `065f683` berjalan di Gateway live; Ticket 05–16 belum)
- [ ] M2 — State Consistency (belum mulai)
- [ ] M3 — Operational Workflow (spec + plan siap, 0 dari 14 task dikerjakan)
- [ ] M4 — POS / Customer Context (belum dibahas)
- [ ] M5 — Intelligence / AI (belum dibahas)

Urutan bergantung ke bawah: Tahap 0 → M1 → M2 → M3 → M4 → M5.

## Aturan Tetap (berlaku di semua tahap)

- **Dokumentasi bukan source of truth.** Source code, migration, test, dan git diff adalah sumber kebenaran.
- **Jangan loncat tahap.** Tiap tahap punya prasyarat dari tahap sebelumnya — terutama M3 yang sebagian bergantung keras pada M2 (lihat catatan di bagian M3).
- **Klaim harus berbasis bukti nyata**, bukan asumsi. Kalau ada yang tidak bisa diverifikasi, catat sebagai limitation eksplisit, jangan ditutup-tutupi atau dipaksakan kesimpulan.

---

## Baseline Repository

### AuliaPos
- Repo: `tikusgot007/AuliaPos`
- Branch: `v2.2`
- HEAD terverifikasi (21 Sep): `d7abf1d` (merge PR #39, sebelum commit dokumen ini); pengecekan penuh terakhir di `0ee6a49` (#34). Sejak itu masuk plan/spec/handoff M1 Wave 1 (PR #35 s/d #39)
- 37 commit masuk sejak HEAD lama (`07d30c8`) — mencakup merge Tahap 0 (PR #24, #25) + rangkaian spec/plan M3 Fase 1 (PR #26, #28, #29, #30, #31, #32, #33, #34)
- Branch kerja M3 yang sudah ter-merge/selesai perannya: `claude/tahap-0-aulia-wa-handoff-ic861g`, `claude/cek-dulu-ubvqe2`, `claude/spec-operational-inbox-fase1-tuux4c`, `claude/m3-operational-inbox-plan-h244ji`, `claude/m3-inbox-plan-review-y9mbw3`
- Tooling baru: `.claude/standards/` berisi skema SDLC (spec → clarification report → plan → ADR), `AGENTS.md` diganti "SDLC Orchestrator template"

### WA-Gateway
- Repo: `tikusgot007/WA-Gateway`
- [x] Branch utama sekarang: `master` @ `065f683` (dicek 23 Sep; `origin/master` masih `e18f716` — fast-forward deploy M1 belum di-push). Branch `claude/android-app-p40bl1` **sudah tidak ada di remote** — kode `5b28eb6` masuk `master` lewat PR #1
- [x] `5b28eb6c8a7e6e6c2e1d5b7d7261389f9309c295` adalah ancestor `master`, jadi baseline tetap valid
- [ ] `master` sudah merge PR #1 dari `claude/android-app-p40bl1` + commit "Create node.exe" (packaging, di luar scope M1)
- Branch lain yang muncul (`fix/lid-fromme-pushname-leak`, `claude/cek-bandingkan-mimac-fln1h4`) — dicek, **tidak relevan** dengan kerja saat ini (versi lama/terpisah, salah satunya referensi "AuliaPos v3.0")
- [x] **`feature/stage-1-reliability` (worktree `C:\projects\WA-Gateway-m1`) berisi eksekusi plan resmi M1 Wave 1 Fase 1–3**: 21 commit M1 di atas `e18f716` (`baf1896` s/d `065f683`) — itulah kode yang **berjalan di folder live**; branch-nya kini bergerak lewat sesi refactor paralel di atas `065f683` (pasca `/sdlc-code-review`, **belum di-deploy** — cek `git log`). Sudah di-push ke `origin` (`master` lokal 21 komit di depan `origin/master`; PR belum dibuat). Kode ad-hoc sandbox sudah ditimpa sesuai kontrak plan (retry 50/200/800 ms + overflow 500 event, timeout LID 2 detik + cache negatif 60 detik, dst.); E-01 dan E-06 diimplementasikan ulang sesuai plan (`ownSentRegistry`, isolasi error tanpa pesan minimal). Semua bukti **simulasi/mock**. Rincian: `docs/decisions/2026-09-21-m1-wave1-eksekusi-fase1-3.md`. Riwayat kerja ad-hoc dan konfliknya dengan plan: `docs/decisions/2026-09-21-handoff-m1-wave1-eksekusi-lokal.md`

### Environment aktif (hasil verifikasi terakhir)
- [x] Gateway yang benar-benar jalan **sekarang**: `C:\projects\WA-Gateway` (branch `master` **@ `065f683`** sejak 23 Sep 16:40 WIB — hasil deploy fast-forward M1; PM2 `wa-gateway`, auto-start via registry `HKCU\...\Run`), nomor `6281913500707` (nomor uji), status `connected`. Titik rollback M1: `e18f716`
- [x] Catatan lama menyebut `G:\wa-gateway-5b28eb6`. Folder itu masih ada, tetapi HEAD-nya sebenarnya `e18f716` (nama folder menyesatkan). Jangan jalankan bersamaan dengan yang di atas (port 3000 dan sesi WhatsApp bentrok)
- [x] 3 folder Gateway lama di drive G sudah dirapikan: dipindah ke `G:\arsip-gateway\` (20 Sep) lengkap dengan `README.txt` berisi label dan peringatan
- [x] Folder `htdocs\wa-gateway` (yang `.env`-nya sempat diubah) sudah bukan yang dipakai dan sudah diarsipkan sebagai `htdocs-wa-gateway-poc-2026-09-12`. Isi `.env` di dalamnya tidak saya periksa ulang
- [x] Setup Aan-PC (21 Sep): XAMPP MySQL dan Apache sebagai Windows Service (Automatic), AuliaPos di `C:\xampp\htdocs\aulia` (HTTP 200), WA-Gateway di PM2
- ~~Tes reboot sungguhan untuk auto-start PM2~~ — **dicoret 21 Sep: di luar scope pengembangan** (auto-start sudah terpasang dan disimulasikan dengan `pm2 kill`)

---

## Status Per Tahap

### Tahap 0 — Baseline ✅ DONE (20 Sep 2026)

Decision log: `docs/decisions/2026-09-19-tahap-0-baseline.md`, commit `4062833`, branch `claude/tahap-0-aulia-wa-handoff-ic861g`

Hasil ringkas (item "dilaporkan" berasal dari decision log Tahap 0 dan tidak dijalankan ulang di Aan-PC; `phpunit` di sini menampilkan ringkasan berbeda, `8 PASS, 0 FAIL`, sehingga angka 82/144 tidak bisa dicocokkan):
- [x] Test unit AuliaPos: 82 test + 144 assertion PASS (dilaporkan)
- [x] Test database: 61/61 PASS (dilaporkan; jalan di SQLite, bukan MySQL — limitation tercatat)
- [ ] Test session: 63 PASS, **2 ERROR** belum diperbaiki (`no such table: db_closing_kas` di `LaporanBulananExcludeBatalTest`)
- [x] Smoke test incoming/outgoing/fromMe: semua lolos (dilaporkan)
- [x] Verifikasi ulang kasus dekripsi gagal (`AC0B72AD…`) di kondisi bersih: **tidak ada bug sistemik di kondisi normal** (16/16 fromMe sukses, 19/19 incoming/sticker burst sukses)
- [x] Hipotesis "gagal tepat setelah restart Gateway" diuji lewat M1 Ticket 01 skenario 2 (21 Sep). **Hasil: pola pesan hilang saat restart terkonfirmasi, tetapi mekanisme "dekripsi gagal" dibantah.** Penyebab yang ditemukan: pesan offline (`append`) dibuang oleh `connectionManager.js:385`. Lihat `docs/decisions/2026-09-21-m1-ticket01-baseline.md`

4 limitation tercatat:
- [ ] Test soft-delete tidak jalan
- [ ] Test DB pakai SQLite, bukan MySQL
- [x] Duplicate-on-timeout belum teruji → **sudah diuji 21 Sep** (M1 Ticket 01 skenario 3): retry `/send` menduplikasi pesan pada 2 dari 3 percobaan
- [ ] WebP non-sticker belum diverifikasi via WhatsApp asli

---

### M1 — Reliability 🚦 WAVE 1 SELESAI (ditutup 23 Sep) — Ticket 01 (pengukuran) selesai 21 Sep; Wave 1 (Ticket 02–04) selesai + live, AC-001 3/3 nyata; Ticket 05–16 (gelombang 2–3) belum

**Status nyata (dicek 21 Sep)**: Ticket 01 dijalankan di Aan-PC pada 21 Sep. Hasil lengkap: `docs/decisions/2026-09-21-m1-ticket01-baseline.md`. Ticket 01 murni pengukuran (tanpa perbaikan kode). **Update 23 Sep:** Wave 1 sudah **dideploy ke folder live** (`065f683` — fast-forward + `pm2 restart`, TASK-019) dan **AC-001 diukur dengan Gateway nyata**: 3/3 percobaan `pm2 stop`, 30/30 pesan, 0 hilang, 0 duplikat → **risiko P0 #1 tertutup**. Risiko P0 #2 (JSON fallback), #3 (idempotensi `/send`), #4, dan #5 masih terbuka. Rincian: `docs/decisions/2026-09-23-m1-wave1-deploy-dan-ac001.md`.
- [x] **`/sdlc-code-review` M1 (23 Sep)**: laporan `docs/audit/code-review-m1-wave1-2026-09-23.md` — verdict **0 P0 / 1 P1 / 14 P2**, 19/19 REQ terpenuhi, tidak ada temuan yang membatalkan AC-001; perbaikan dikumpulkan di `plan/plan-refactor-m1-wave1-incoming-reliability-v1.0.md` (Phase 1 dan seterusnya sudah dikomit oleh sesi refactor paralel di branch `feature/stage-1-reliability`, **belum di-deploy** ke folder live).

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
- [x] 03. Durable buffer — kode + simulasi selesai (TASK-002 s/d TASK-006: retry 50/200/800 ms, overflow 500 event, `PRAGMA quick_check` SQLite); bukti **simulasi**; di-merge 23 Sep dan berjalan live (`065f683`)
- [x] 03. Durable buffer — verifikasi nyata (TASK-017, AC-001): **lulus 3/3, 30/30 pesan, 0 hilang, 0 duplikat** (23 Sep); merge ke `master` selesai lewat deploy TASK-019 (fast-forward, rollback point `e18f716`)
- [x] 04. JSON recovery — kode + simulasi selesai (TASK-015: 8 skenario, lima celah E-09 ditutup); bukti **simulasi**
- [ ] 04. JSON recovery — verifikasi di lingkungan yang benar-benar memakai fallback JSON (mis. build Android)
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
- [x] 16. Merge — selesai 23 Sep (fast-forward `e18f716`→`065f683` ke folder live + `pm2 restart`; PR belum dibuat)

Risiko P0 yang jadi alasan M1 ada:
- [x] 1. Incoming enqueue failure — pesan hilang. **Terbukti nyata 21 Sep**: pesan yang tiba saat Gateway offline dibuang diam-diam (3 dari 14 dan 3 dari 15). **Fix kode sesuai plan resmi** (TASK-008 s/d TASK-010) **diverifikasi 23 Sep dengan restart Gateway sungguhan**: TASK-017/AC-001 lulus 3/3 (30/30 pesan, 0 hilang, 0 duplikat) pada Gateway live yang menjalankan `065f683` → **ditutup**. Bukti: `docs/decisions/2026-09-23-m1-wave1-deploy-dan-ac001.md`
- [ ] 2. JSON fallback corruption — queue rusak berisiko restart dari kosong. **Fix kode sesuai plan resmi sudah ada 21 Sep malam** (TASK-015: karantina, pemulihan `.bak`, cadangan divalidasi sebelum disalin), diuji lewat simulasi 8 skenario (bukan Gateway nyata) — belum diverifikasi di lingkungan yang benar-benar memakai fallback JSON (mis. build Android)
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

- [x] Risiko dikonfirmasi lewat analisis kode (saat menyusun blueprint M3): `cekOwnership()` di AuliaPos saat ini **read-then-write di level aplikasi, bukan atomic di database** — bukti nyata risiko P1 "ownership race" (`app/Controllers/Inbox.php`, `cekOwnership()` membaca `assigned_to` lalu memutuskan, tanpa transaksi/kunci)
- [ ] Breakdown ticket detail M2 — menyusul setelah M1 selesai

---

### M3 — Operational Inbox 🚦 PLAN LENGKAP & SIAP EKSEKUSI — belum ada task yang dikerjakan

**Update penting (21 Sep)**: sesi Claude Code sudah menghasilkan spec + plan formal yang jauh melampaui blueprint awal kita, lewat proses SDLC terstruktur (spec → clarification report → remediasi → plan → clarification report kedua → resolusi). Semua 5 keputusan desain 🔶 yang saya tandai sebelumnya **sudah diresolusikan** (jadi "7 resolusi klarifikasi").

Dokumen yang kini jadi acuan resmi (menggantikan blueprint lama sebagai working reference). Semua file dicek ada di repo (`v2.2`):
- [x] `spec/spec-design-m3-operational-inbox-fase1.md` — Readiness Score 96/100
- [x] `docs/audit/clarification-report-m3-fase1-operational-inbox-spec-2026-09-20.md` — klarifikasi spec + remediasi
- [x] `docs/adr/0001-reuse-response-state-for-queue-view-status.md` — keputusan status granular: **computed**, reuse `Inbox::attachResponseState()` yang sudah ada (persis rekomendasi blueprint awal, sekarang jadi ADR resmi)
- [x] `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — Readiness Score 97/100, status: Planned, 14 task
- [x] `docs/audit/clarification-report-m3-fase1-operational-inbox-plan-2026-09-21.md` — klarifikasi plan, 7 resolusi sudah ditulis ke TASK-002, 008, 011, 012

**Keputusan desain yang sudah final (bukan lagi 🔶):**
- [x] 1. Status granular → **computed** via `ConversationModel::withComputedStatus()`, reuse `attachResponseState()` — ADR-0001
- [x] 2. Threshold SLA → Hijau `<15m`, Kuning `15-60m`, Merah `>60m`, sumber waktu `last_message_at` (REQ-010)
- [x] 3. Customer Context → **dibatasi ke Fase 1a dasar**, tidak menarik M4 (konsisten prinsip urutan)
- [x] 4. Internal Note → kolom `is_internal BOOLEAN` di `messages` (REQ-007), bukan tabel terpisah
- [x] 5. @mention → **tidak masuk Fase 1** (disederhanakan, tidak disebut lagi di plan — kemungkinan didrop, perlu dikonfirmasi eksplisit kalau perlu dipastikan)

**Pembagian fase (final, dari plan resmi). Dicek 21 Sep: `withComputedStatus` dan `is_internal` belum ada di `app/`, migration terbaru masih `2026-09-19-000004_AddMediaConfirmedGone.php`, jadi 0 dari 14 task dikerjakan.**

Fase 1a — tanpa migration. Berhenti di TASK-006 (APPROVAL) menunggu konfirmasi Anda sebelum lanjut Fase 1b:
- [ ] TASK-001 `ConversationModel::withComputedStatus()`
- [ ] TASK-002 Panggil `withComputedStatus()` di `Inbox::apiConversations()`
- [ ] TASK-003 Verifikasi Conversation Detail (thread dan action bar)
- [ ] TASK-004 Verifikasi Snooze Dialog Fase 1a (hanya parameter `menit`)
- [ ] TASK-005 VERIFY: test `tests/database/` untuk `withComputedStatus()`
- [ ] TASK-006 APPROVAL: konfirmasi eksplisit sebelum Fase 1b

Fase 1b — migration `is_internal`, endpoint Internal Note, SLA Service, filter/pencarian, Alasan Snooze (disimpan sebagai Internal Note, bukan kolom baru — ALT-003 ditolak eksplisit). Berhenti di TASK-014 (APPROVAL):
- [ ] TASK-007 Migration `messages.is_internal`
- [ ] TASK-008 `Inbox::catatanInternal()` + route (**High Risk**, RISK-001)
- [ ] TASK-009 VERIFY: test `tests/session/` endpoint Internal Note
- [ ] TASK-010 `InboxSlaService` (pure function)
- [ ] TASK-011 Perluas `apiConversations()` (filter, pencarian, SLA, `findAll(500)`)
- [ ] TASK-012 Snooze Dialog: field Alasan opsional
- [ ] TASK-013 VERIFY: test `tests/unit/` untuk `InboxSlaService`
- [ ] TASK-014 APPROVAL: Fase 1a + 1b selesai, siap lanjut

Fase berikutnya:
- [ ] **Fase 2** (Handoff, Collision detection) — **RISK-003 eksplisit menulis**: "terkunci menunggu M2, plan ini tidak menyentuh area itu sama sekali." Prinsip prasyarat M2 **dihormati secara tertulis** di plan resmi.
- [ ] **Fase 3** — tunggu M5, tidak berubah.

**Risiko teknis yang tercatat di plan (lebih detail dari blueprint awal):**
- RISK-001 (High Risk): endpoint Internal Note rawan *silent violation* kalau accidentally ikut update `last_message_at`/`last_message_direction` — bisa merusak badge Tahap A & Queue View tanpa error kelihatan. Mitigasi: test wajib assert kolom itu TIDAK berubah, bukan cuma assert insert sukses.
- RISK-002: `findAll(500)` di filter-after-fetch berpotensi lambat di data besar — diterima sebagai risiko, tidak dimitigasi di Fase 1 (dicatat, bukan diabaikan diam-diam).

Migration baru yang dibutuhkan (final, dari plan):
- `messages.is_internal BOOLEAN NOT NULL DEFAULT FALSE` (satu-satunya migration Fase 1, additive)
- **Tidak ada** kolom `snooze_reason` (ditolak, pakai Internal Note)
- Tabel `conversation_handoffs` untuk Fase 2 — belum masuk plan resmi, baru rencana blueprint awal, perlu dibuatkan spec/plan resmi sendiri saat M2 selesai

---

### M4 — POS / Customer Context ⏳ BELUM DIBAHAS

- [ ] Rencana detail M4 (baru disinggung sebagai prasyarat untuk Customer Context penuh — order/payment — di Conversation Detail M3)

---

### M5 — Intelligence / AI ⏳ BELUM DIBAHAS

- [ ] Rencana M5. Ditunda sampai fondasi M1-M4 stabil. Fase 3 di blueprint M3 (intent filters, AI summary, suggested reply) menunggu ini.

---

## Yang Menggantung — Action Items Konkret

⚠️ **Keputusan prioritas yang perlu Anda ambil sekarang** (diperbarui 21 Sep setelah M1 Ticket 01 selesai): M3 plan sudah siap eksekusi (Readiness 97/100), dan pengukuran M1 sudah ada. Dua opsi:
- **(A) Mulai perbaikan M1 dulu**, dimulai Ticket 02 (filter `type !== 'notify'`), karena kehilangan pesan sekarang terbukti nyata — sesuai urutan roadmap asli.
- **(B) Mulai eksekusi M3 Fase 1a sekarang** (plan sudah matang, tidak menyentuh migration/M2), sambil perbaikan M1 menyusul paralel — valid karena Fase 1a memang didesain tidak bergantung M1/M2.

Tidak ada jawaban "benar" secara teknis untuk A vs B — keduanya sah. Ini murni soal prioritas kapasitas Anda. Yang penting: **jangan biarkan perbaikan M1 tertunda tanpa batas** karena risiko P0 di baliknya (message loss, duplicate) kini terbukti nyata dan tetap aktif selama belum ditangani.

Urutan prioritas realistis (dengan asumsi opsi B dipilih — sesuaikan kalau Anda pilih A):

- [ ] 1. **Eksekusi M3 Fase 1a** sesuai `plan-feature-m3-operational-inbox-fase1-v1.0.md` TASK-001 s/d TASK-006 — plan sudah siap pakai, tinggal jalankan di Claude Code.
- [ ] 2. **Approval checkpoint TASK-006** — review hasil Fase 1a sebelum izinkan lanjut Fase 1b.
- [x] 3. **Jalankan M1 Ticket 01** (baseline test 4 skenario) — selesai 21 Sep, lihat `docs/decisions/2026-09-21-m1-ticket01-baseline.md`.
  - [x] Baseline 1 versi asli selesai (45/45 sampai, 0 hilang, 0 duplikat; ada error dekripsi dan pergeseran urutan).
  - [x] Uji UI Inbox saat Gateway bermasalah di tengah kirim selesai (lihat M1 di atas).
  - [x] Semua sisa Ticket 01 selesai atau dicoret (penyebab error dekripsi diselidiki pasif, belum terbukti; percobaan 1 skenario 2 dicoret).
- [x] 4. **Rapikan housekeeping environment** — Gateway aktif diberi label (lihat "Environment aktif"), 3 folder lama di drive G dipindah ke `G:\arsip-gateway\` (20 Sep), folder `htdocs\wa-gateway` diarsipkan.
- [ ] 5. **Eksekusi M3 Fase 1b** (TASK-007 s/d TASK-014) setelah TASK-006 disetujui.
- [x] 6. **M1 Gelombang 1 (Ticket 02-04) — SELESAI & LIVE 23 Sep** — eksekusi lewat `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` (plan resmi, `/sdlc-write-code`, worktree `C:\projects\WA-Gateway-m1`), **BUKAN** melanjutkan kerja ad-hoc sandbox. Baca dulu `docs/decisions/2026-09-21-handoff-m1-wave1-eksekusi-lokal.md` untuk konteks lengkap (kerja ad-hoc E-03/E-04/E-05/E-09 yang sudah ter-merge ke `feature/stage-1-reliability` **sudah ditulis ulang/disesuaikan (lihat decision log 21 Sep malam)** sesuai TASK-001/002-004/013/015 plan resmi, bukan dipakai apa adanya). Langkah menggantung:
  - [x] Worktree `C:\projects\WA-Gateway-m1` siap; Fase 1, 2, dan 3 (TASK-001 s/d TASK-016) dieksekusi lewat `/sdlc-write-code`, APPROVAL TASK-007 dan TASK-012 diberikan. 13 commit, sudah di-push ke `origin`. Lihat `docs/decisions/2026-09-21-m1-wave1-eksekusi-fase1-3.md`
  - [x] TASK-017 (AC-001, `pm2 stop` nyata) — **selesai 23 Sep: 3/3 percobaan, 30/30 pesan, 0 hilang, 0 duplikat**. Catatan: jendela ">21:00 atau <08:00" sudah dihapus oleh plan v1.2 (RISK-003 — tidak ada jendela wajib)
    - **Sudah dijalankan** lewat TASK-019 (deploy fast-forward `e18f716`→`065f683` + `pm2 restart`); cara deploy ditetapkan oleh amandemen plan v1.2 (`docs/proposal-amandemen-plan-m1-wave1-2026-09-23.md`) dan prosedurnya ada di runbook `docs/runbooks/runbook-m1-wave1-task017-ac001-2026-09-23.md`
  - [x] TASK-018 — **disetujui 23 Sep**; plan v1.2 diubah ke `status: 'Completed'` + badge brightgreen (commit AuliaPos `4d43c8d`); M1 Gelombang 1 ditutup
  - [x] Push branch `feature/stage-1-reliability` ke `origin` (21 Sep malam, fast-forward `091fe19..065f683`, tanpa force)
  - [ ] Buat PR (`feature/stage-1-reliability` → `master`) — **belum dibuat**. `/sdlc-code-review` M1 **sudah dijalankan 23 Sep** (laporan `docs/audit/code-review-m1-wave1-2026-09-23.md`, verdict **0 P0 / 1 P1 / 14 P2**, spec 19/19 REQ); plan perbaikannya `plan/plan-refactor-m1-wave1-incoming-reliability-v1.0.md`. Rentang PR kini mencakup **komit refactor di atas `065f683`** (jumlahnya masih bertambah oleh sesi paralel) yang **belum di-deploy dan belum diverifikasi ulang di Gateway live**
  - [ ] E-02 dan E-07 (di luar scope plan resmi juga) — butuh verifikasi dengan WhatsApp nyata dulu sebelum diputuskan jadi task baru
  - [ ] Ticket 05, 06-08, 09-11, 12-16 (gelombang 2-3) belum dimulai, menyusul setelah gelombang 1
- [ ] 7. **M2** dimulai setelah M1 selesai (atau tepatnya ticket-ticket kritis M1 seperti idempotency — konfirmasi urutan pasti saat M1 mendekati akhir).
- [ ] 8. **Fase 2 M3** (Handoff, Collision detection) — **wajib** tunggu M2 selesai, ini sudah tertulis eksplisit di RISK-003 plan resmi, bukan lagi cuma catatan blueprint.
- ~~9. (baru) Tes reboot sungguhan untuk auto-start PM2 di Aan-PC~~ — **dicoret 21 Sep: di luar scope pengembangan.**
- [ ] 10. (baru) Perbaiki 2 ERROR test session Tahap 0 (`db_closing_kas`) dan putuskan apakah folder `G:\arsip-gateway\` sudah boleh dihapus.

---

## File-File Referensi

**Dari sesi chat ini (di luar repo, mungkin perlu disalin manual kalau belum ada di repo).** Centang berarti file ada di `C:\Users\AAN\Downloads` di Aan-PC. Yang tidak dicentang tidak ditemukan di Downloads, Documents, Desktop, `C:\projects`, dan `G:\` (kedalaman folder sampai 4, dicek 21 Sep); bisa saja ada di perangkat lain:
- [ ] `handoff-tahap0-claude-code.md` — handoff awal Tahap 0 (historis; tidak ditemukan di Aan-PC, Tahap 0 sudah DONE & merged)
- [x] `checklist-eksekusi-lokal-tahap0.md` — checklist smoke test Tahap 0 (historis)
- [ ] `verifikasi-dekripsi-gagal-fromme.md` — investigasi kasus `AC0B72AD…` (historis; tidak ditemukan di Aan-PC, sudah masuk decision log resmi)
- [x] `m1-ticket01-baseline-eksekusi.md` — rencana eksekusi Ticket 01 M1. **Sudah dijalankan 21 Sep** (lihat M1 di atas)
- [ ] `Panduan_Layar_AuliaPos_M3.md` — desain 7 layar M3 awal (tidak ditemukan di Aan-PC; sudah digantikan spec resmi di repo, referensi historis)
- [ ] `blueprint-m3-operational-inbox.md` — blueprint awal (tidak ditemukan di Aan-PC; sudah digantikan plan resmi di repo, referensi historis)

**Di repo AuliaPos (branch `v2.2`, sudah ter-push, ini yang jadi acuan resmi sekarang). Semua dicek ada:**
- [x] `docs/decisions/2026-09-19-tahap-0-baseline.md` — decision log Tahap 0 lengkap
- [x] `docs/decisions/2026-09-21-m1-ticket01-baseline.md` — decision log M1 Ticket 01 (baru)
- [x] `docs/decisions/2026-09-21-m1-ticket02-audit-enqueue.md` — laporan audit M1 Ticket 02 (baru)
- [x] `docs/decisions/2026-09-21-m1-wave1-eksekusi-fase1-3.md` — eksekusi M1 Wave 1 Fase 1–3: commit, penyimpangan dari spec, bukti simulasi, batas bukti (baru)
- [x] `spec/spec-design-m3-operational-inbox-fase1.md` — spec resmi M3 Fase 1
- [x] `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` — **plan eksekusi resmi, 14 task, siap jalan**
- [x] `docs/adr/0001-reuse-response-state-for-queue-view-status.md` — ADR status granular
- [x] `docs/audit/clarification-report-m3-fase1-operational-inbox-spec-2026-09-20.md`
- [x] `docs/audit/clarification-report-m3-fase1-operational-inbox-plan-2026-09-21.md`

**Prinsip update dokumen ini ke depan**: kalau ada progres baru dari sesi Claude Code manapun, jalankan `git fetch` + `git log <base>..<HEAD_terbaru> --oneline` dulu untuk lihat commit yang masuk sebelum percaya status di dokumen ini — jangan asumsikan dokumen ini otomatis sinkron dengan kerja paralel.
