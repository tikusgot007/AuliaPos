# TODO / Backlog Terpusat

> Baca file ini di **AWAL setiap sesi** sebelum bekerja. Perbarui setiap kali ada
> temuan baru, atau item selesai. Struktur file: **Status → Prioritas → item**.
> Item yang sudah selesai tetap dipertahankan di bagian **Selesai / Ditutup** sebagai
> histori/decision record; jangan dihapus tanpa persetujuan eksplisit.
>
> Format item tetap: `- [ ] **ID** ringkas — prioritas — ref`.
>
> Jangan membuat daftar TODO kedua di tempat lain; checkpoint hanya menunjuk ke sini.

## Belum dikerjakan

### High

(Semua item high selesai)



### Low

(Semua item low selesai)

## Sedang dikerjakan

### Medium


- [ ] **TODO-MARA1** Kontrak M.A.R.A ↔ AuliaPos (agent AI pemantau Inbox, jalan di aulia7, satu LAN dengan server AuliaPos) — sedang — permintaan user 2026-10-13.
  - **Prinsip desain yang disepakati**: anonimisasi HANYA berlaku di jalur training/export (M.A.R.A tidak perlu tahu identitas untuk belajar pola); jalur realtime/operasional TIDAK dianonimkan (nama/nomor asli, dipakai untuk follow-up & lapor Telegram ke owner). Kunci rujukan training = `conversation_id` (bukan hash sepihak), bisa di-resolve manual saat eskalasi via endpoint terpisah yang dilog. M.A.R.A **tidak pernah** akses DB production (`aulia_inboxdb`) langsung — selalu lewat API read-only/kontrak terbatas yang disediakan AuliaPos.
  - **SELESAI & diverifikasi lokal (dev server, 2026-10-13)**: 2 dari 5 endpoint yang didesain.
    - `GET /api/inbox/mara/export/conversations` (`app/Controllers/MaraController.php::export()`) — cursor-based (`since`, `until`, `cursor`, `limit`), payload anonim (`customer_ref` = hash SHA-256 nomor+salt, TANPA `contact_name`/`phone` asli), `conversation_id` asli sebagai kunci rujukan, `order_outcome.resolution_time_min`.
    - `GET /api/inbox/mara/resolve/{conversation_id}` (`::resolve()`) — identitas ASLI (nama, nomor, histori lengkap); SETIAP panggilan wajib tercatat ke `mara_resolve_log` (migrasi `2026-10-13-000001_CreateMaraResolveLog.php`) SEBELUM data dikembalikan, termasuk saat conversation tidak ditemukan.
    - Auth: `app/Filters/MaraTokenFilter.php` (Bearer, `hash_equals`), token TERPISAH dari `inbox.gatewayToken` (lihat `app/Config/Mara.php`, env `mara.apiToken`/`mara.resolveSalt`).
    - **Bug ditemukan & diperbaiki**: `AuthFilter.php` punya exception hardcoded hanya untuk prefix `api/inbox/gateway/` (config `except` di `Filters.php` saja tidak cukup, pola sama dengan catatan historis Gateway) — ditambah `api/inbox/mara/` ke pengecekan eksplisit itu juga, atau endpoint M.A.R.A akan selalu kena redirect `/login` walau token benar.
    - Verifikasi: `php -l` semua file baru lulus; migrasi jalan bersih di `aulia_inboxdb` dev (`DESCRIBE mara_resolve_log` dikonfirmasi); dev server smoke test — tanpa token 401, token salah 401, token benar 200 (data anonim terkonfirmasi tanpa nama/nomor asli), resolve id tak ada → 404 **tapi tetap tercatat** ke `mara_resolve_log` (dikonfirmasi query SQL).
  - **Webhook realtime AuliaPos → M.A.R.A — ✅ SELESAI & TERVERIFIKASI END-TO-END DI PRODUCTION (2026-10-13)**:
    - **Spek FINAL** — dokumen serah terima resmi dari M.A.R.A (`2026-10-13-serah-terima-webhook-auliapos.md`), status M.A.R.A: **sudah aktif & lulus tes penerimaan mereka sendiri (13 Okt 13:35)**. Alamat: `http://192.168.1.68:8644/webhooks/auliapos-inbox` — **PENTING: jalur `/webhooks/` pakai huruf "s"**, bukan `/webhook/` seperti draft awal. Port 9119 BUKAN webhook (dashboard Hermes). Auth HMAC-SHA256 di atas body mentah, header `X-Webhook-Timestamp` + `X-Webhook-Signature-V2`, toleransi 300 detik, kunci bersama **SUDAH DITERIMA** dan diisi ke `.env` (`mara.webhookSecret`). Idempotency: `event_id` di header **`webhook-id` (lowercase)** DAN di body. Rate limit **dinaikkan M.A.R.A ke 120 req/menit** (default 30), body maks 1 MB. Payload per pesan: `event`, `event_id`, `conversation_id`, `chat_id`, `jid_type`, `direction`, `message_type`, `text`, `is_internal`, `wa_message_id`, `timestamp`, `contact_name`/`whatsapp_name` — tanpa nomor telepon mentah/isi media. M.A.R.A otomatis buang senyap (balas 200 `ignored`) untuk `direction=outgoing`, `is_internal=1`, `jid_type=group`, dan pesan sampah (fonnte/huruf P saja/kata "hadir").
    - Kode: `app/Config/Mara.php` (`$webhookUrl`/`$webhookSecret`/toleransi/timeout 3s), `app/Libraries/MaraWebhookClient.php` (HTTP+HMAC, header `webhook-id` lowercase — **sudah diperbaiki dari draft awal yang salah pakai `Webhook-Id`**), `app/Libraries/MaraWebhookDispatcher.php` (builder payload), di-hook setelah commit di 3 titik: `InboxGatewayApi::messages()`, `Inbox::kirimKeConversation()`, `Inbox::kirimMedia()`. Best-effort murni, no-op otomatis bila config kosong.
    - **`.env` dev SUDAH TERISI PENUH**: `mara.webhookUrl = http://192.168.1.68:8644/webhooks/auliapos-inbox`, `mara.webhookSecret` = kunci asli dari dokumen serah terima (tersimpan di `.env`, JANGAN commit ke repo publik).
    - Verifikasi yang SUDAH dilakukan: `php -l` semua file lulus; simulasi HMAC lokal (echo-server Python) — payload & kedua header terbaca benar, signature cocok persis saat dicek ulang independen.
    - **✅ TERVERIFIKASI END-TO-END DI PRODUCTION (2026-10-13)**: deploy ke server production `192.168.1.10` (via share `W:` → `git pull`, commit `7f44401`); `.env` produksi diisi 4 config M.A.R.A (backup lama: `.env.bak_pre_mara_20261013`). Endpoint `export`/`resolve` diuji nyata via HTTPS: tanpa token 401, token salah 401, token benar 200 (export anonim; resolve buka identitas asli), id tak ada 404, tabel `mara_resolve_log` ada (tidak ada error tulis log). **Webhook dikonfirmasi M.A.R.A: 14 event masuk & tercatat di `.mara-event-log.jsonl` (16:26–16:41 WIB), semua `message.incoming`, timestamp WIB tepat, tanpa kegagalan** — mis. `auliapos-msg-5522` conv#4 "Tes koneksi", `auliapos-msg-5523` conv#408 (teks+gambar), `auliapos-msg-5525` conv#417 (dokumen). Log produksi 0 baris `MaraWebhookDispatcher: gagal kirim`. (Catatan historis: dari PC dev `192.168.1.120` curl ke `:8644` memang TIMEOUT karena firewall M.A.R.A hanya izinkan `192.168.1.10` — bukan indikasi gagal.)
  - **BELUM dikerjakan**:
    1. `POST /api/inbox/mara/suggestions` — M.A.R.A → AuliaPos, draft field dari M.A.R.A: `{conversation_id, type: balasan|daftar-periksa|peringatan, text, reason, priority, expires_at}` — bentuk akhir ditentukan AuliaPos, M.A.R.A menyesuaikan. **✅ SELESAI & TERVERIFIKASI LOKAL (2026-10-13) — belum di-deploy ke produksi.**
       - **Gate 1 & Gate 2 DISETUJUI user 2026-10-13** (requirements + design). 3 pertanyaan terbuka §7 requirements dijawab: default `expires_at` = now+30mnt; balasan kasir → `status='expired'` baris tetap ada (audit); balasan endpoint `{status, data:{id}}`. Keputusan tambahan: **semua pesan keluar sukses** (termasuk Teruskan) menutup saran.
       - **Kode**: migrasi `2026-10-13-000002_CreateMaraSuggestions.php` (tabel `mara_suggestions`, `$DBGroup='inbox'`, additive, tanpa FK); `app/Models/MaraSuggestionModel.php` (`activeForConversation()`/`supersedeSameType()`/`expireForConversation()`); `MaraController::suggestions()` (`POST /api/inbox/mara/suggestions`, filter `maratoken`, validasi penuh, default expiry, supersede+insert 1 transaksi); `Inbox::apiSuggestions()` (`GET /inbox/api/conversations/(:num)/suggestions`, filter `auth`, fail-safe list kosong bila tabel belum dimigrasi); `Inbox::expireMaraSuggestions()` dipanggil di 2 cabang sukses kirim (`Inbox.php` ~1921 media, ~3501 teks, best-effort); UI panel `#panelSaranMara` (`Views/inbox/index.php`) + `public/assets/js/inbox-suggestions.js` + `inbox.css`.
       - **Verifikasi EXECUTABLE (dijalankan nyata, bukan klaim)**: `php -l` semua file lulus; `aulia:sync-inbox-test-db` membangun ulang DB uji dari 24 migrasi Inbox (`DESCRIBE mara_suggestions` dikonfirmasi, 11 kolom + 3 index sesuai desain); **feature `MaraSuggestionsTest` 20 test / 58 assertion LULUS** (AC-1..AC-11; AC-10/AC-11 lewat jalur kirim ASLI `/inbox/kirim` + fake gateway `ok`/`http500`; +3 test review-fix: outgoing gateway, incoming gateway, lock AC-5); **JS `tests/js/inbox-suggestions.test.js` 14 test LULUS** (AC-12..AC-15 termasuk escaping XSS + throttle). Regresi penuh: unit 134 (7 incomplete lama), JS 116, feature 269 (1 skip lama), integrasi 65 — semua lulus.
       - **Perbaikan pasca code-review (2026-10-13)**: (1) AC-10 kini juga menutup balasan staff yang disinkron dari WA Web/HP — hook expire ditambahkan di `InboxGatewayApi::messages()` saat `direction='outgoing'` (best-effort, setelah commit); (2) AC-5 diperkuat dengan `MaraSuggestionModel::lockConversation()` (`SELECT ... FOR UPDATE` baris conversation) di dalam transaksi supersede+insert, karena transaksi saja tidak mengunci saat belum ada baris active bertipe sama; (3) regression JS diperbaiki — `pilihConversation` pakai `force=true` (sekali) dan loop polling di-throttle 60 dtk, menghapus fetch ganda saat buka + fetch tiap tick; (4) comment `parseExpiresAt()` dikoreksi (jelas beda dengan `parseDateBoundary()`); (5) dead store `$items = null` dihapus.
       - **✅ DI-DEPLOY KE PRODUKSI & TERVERIFIKASI (2026-10-13)**: `git pull --ff-only` di AULIA-SERVER2 (`192.168.1.10`), `7f44401` → `007a9a5`; migrasi via `/migrasi-manual` (tabel `mara_suggestions` terkonfirmasi di `aulia_inboxdb`, 11 kolom + 3 index; tercatat di `aulia_kasirdb.migrations`). Backup pra-deploy diarsip di `%TEMP%\kilo\prod-backup-20261013\`. Smoke test produksi lulus: tanpa token 401 / token salah 401 / conversation tak ada 404 / kirim valid 201 `{status:ok,data:{id}}` (expires_at default tepat 1800 dtk) / endpoint baca kasir tanpa session 302. Baris uji dihapus. **Tidak ada perubahan gateway.** Temuan: kode sempat aktif ~7 jam sebelum migrasi → 2 baris ERROR `expireMaraSuggestions()` di log (best-effort, kirim tidak terganggu); pelajaran: jalankan migrasi segera setelah deploy kode bila tabel dipakai di jalur panas. Temuan `php spark`/`migrate:status -g inbox` dicatat di `docs/deploy.md` §9.
       - **Sisa**: (a) konfirmasi bentuk balasan ke M.A.R.A lalu kabari "endpoint siap untuk tes kirim"; (b) uji tampilan panel di browser kasir dengan saran nyata dari M.A.R.A (uji visual belum).
       - **Temuan baru (2026-10-13)**: `InboxViewStructureTest` memindai daftar file JS yang tetap untuk handler inline — file JS baru yang memuat handler inline WAJIB ditambahkan ke daftar itu, atau test guard gagal.
       - **SPEK FINAL DITERIMA dari M.A.R.A (2026-10-13, via Telegram)** — lihat dokumen handoff di bawah. Ringkas: 3 jenis saran; maks 3 aktif per percakapan (1 balasan + 1 daftar-periksa + 1 peringatan, tipe sama saling menggantikan); `expires_at` hilang otomatis (kasir sudah membalas ATAU lewat 30 menit, tanpa ditandai "basi"); `priority` normal (netral/biru) / tinggi (kuning, >10 mnt belum dibalas) / darurat (merah, keluhan atau order >Rp50.000); `daftar-periksa` dikirim sebagai array `items` terpisah (bukan teks bernomor) supaya kasir bisa centang satu-satu. Contoh payload: `{"conversation_id":403,"type":"balasan","priority":"normal","text":"...","items":null,"expires_at":"2026-10-13T17:00:00+07:00"}`. Jalur ke kasir = **Opsi A ("cukup simpan")**; push realtime (Opsi B) menyusul bila perlu. M.A.R.A minta dikabari kalau endpoint siap untuk tes kirim.
       - **HANDOFF SESI (2026-10-13) — kerjakan dari dokumen ini, bukan dari riwayat chat** (AGENTS.md §14): `docs/requirements/2026-10-13-mara-saran-ke-kasir.md` (Phase 1, Bahasa Indonesia — tujuan, AC-1..15, dampak skema, 3 pertanyaan terbuka) + `docs/design/2026-10-13-mara-saran-ke-kasir.md` (Phase 2, English — file+`file:line`, opsi, rencana, test plan, risiko). Keduanya **status draf**, menunggu **Gate 1 & Gate 2** (persetujuan user) sebelum kode ditulis (`.kilo/rules/sdlc.md` §3).
       - **Keputusan user 2026-10-13**: kasir butuh KETIGA jenis saran — (a) **balasan** = draft siap-pakai (kasir bisa klik untuk mengisi kotak balasan), (b) **daftar-periksa** = checklist langkah, (c) **peringatan** = alert. Bukan salah satu saja.
       - **Cara saran sampai ke kasir — 3 opsi, user mau diskusikan dulu dengan M.A.R.A sebelum finalisasi**:
         - **Opsi A (rekomendasi)**: M.A.R.A POST ke `/api/inbox/mara/suggestions` → CI4 simpan ke tabel `mara_suggestions` → Inbox fetch saran per-conversation saat kasir buka percakapan (Inbox sudah polling). Tanpa ubah gateway, ada jejak audit, tahan refresh. Delay beberapa detik.
         - **Opsi B**: Opsi A + CI4 minta gateway `aulia7` `broadcast()` event `mara.suggestion` via WS yang sudah ada → saran muncul instan. Butuh endpoint broadcast baru di gateway + deploy ulang `aulia7`.
         - **Opsi C (DITOLAK)**: M.A.R.A kirim langsung ke gateway/browser — melanggar prinsip "M.A.R.A hanya lewat kontrak AuliaPos", tanpa audit, saran hilang saat refresh.
       - **Infrastruktur realtime yang sudah ada** (jika pilih Opsi B): browser kasir buka WS ke gateway `aulia7` (`/realtime`, tiket short-lived dari `Inbox::apiRealtimeTicket()`), gateway `src/realtime/server.js` punya fungsi `broadcast(payload)`; saat ini hanya dipakai untuk event `message.created` (dikirim setelah CI4 ACK, `src/delivery/incomingDelivery.js`). Handler browser di `public/assets/js/inbox-page.js` (event `connection.ready` + `message.created`).
    2. `POST /api/inbox/mara/status` — heartbeat, draft M.A.R.A: `{status, counters, last_event_at}` tiap ~5 menit. Terkait TODO-F10 (kesehatan gateway `aulia7`).
    3. Belum ada unit/feature test otomatis untuk `MaraController`/`MaraWebhookDispatcher` (baru diverifikasi manual via curl/smoke command) — Section 10 AGENTS.md mewajibkan verifikasi executable untuk perubahan non-trivial.
    4. ~~Belum di-deploy ke production~~ **SUDAH di-deploy & terverifikasi 2026-10-13** (lihat bagian di atas) — sisa: pastikan `.env` production diisi manual setiap deploy (`.env` tidak ikut Git), dan endpoint suggestions/heartbeat belum ada.
    5. 3 pertanyaan desain lama (siapa generate token, field apa yang di-hash, strategi cursor final) — masih belum final, lihat histori sebelumnya.
  - Ref: ide awal di repo `tikusgot007/m.a.r.a` (`IDEAS.md`, `ideas/mara-cara-membangun.md`); spek webhook dikonfirmasi LANGSUNG oleh M.A.R.A via Telegram 2026-10-13 (bukan dari source code, karena repo tidak ada lokal di PC ini).

- [ ] **TODO-INBOX-KASIR3** Isi keranjang Kasir dari saran M.A.R.A (pecahan eks-TODO-INBOX-KASIR2, 2026-10-18; bagian nama pelanggan + tab utama sudah selesai & di-deploy). Implementasi dev + test + smoke + uji browser selesai 2026-10-18; menunggu review/Gate 3 dan deploy.
  - Requirements Gate 1: `docs/requirements/2026-10-18-isi-keranjang-dari-saran-mara.md`. Design Gate 2: `docs/design/2026-10-18-isi-keranjang-dari-saran-mara.md`.
  - Ada migrasi Inbox `2026-10-18-000001_AddCartItemsToMaraSuggestions.php`; urutan rilis wajib: migrasi → POS → M.A.R.A. Kontrak sisi repo M.A.R.A belum diverifikasi.

- [ ] **TODO-INBOX-EMOJI** Emoji picker composer + reaksi masuk sebagai kutipan di Inbox — sedang — permintaan user 2026-10-12. **Selesai, di-commit & push, dan SUDAH DI-DEPLOY ke produksi 2026-10-12; tinggal uji nyata di produksi.**
  - (A) Pemilih emoji lokal: `public/assets/js/inbox-emoji.js` + tombol `btnEmojiBalasan` di `app/Views/inbox/index.php`, style `inbox.css`, toggle tombol di `inbox-page.js`.
  - (B) Reaksi masuk 1-1 → baris teks emoji ber-`quoted` target, dihitung pesan baru: `C:\Projects\evolution-gateway\src\evolution\normalize.js` (`extractIncomingReaction`) + `webhookRoutes.js` (reaksi tidak masuk `quotedStore`). Grup / fromMe / reaksi ditarik tetap diabaikan. Tanpa perubahan PHP/skema CI4.
  - Payload nyata `reactionMessage` terverifikasi dari DB Evolution dev (11 baris, semua grup). Reaksi ditarik (text kosong) belum pernah teramati nyata (hanya test sintetis).
  - **Uji nyata 2026-10-12 08:13 WIB** (gateway TEST, chat 1-1 dari HP): baris `messages.id=577` (percakapan 4, `text`=👍, `quoted_wa_message_id`=`3EB085D94754B1EE4FD616`). Tampilan bubble kutipan dan panel pemilih emoji di browser dikonfirmasi sesuai oleh user.
  - Test: gateway `npm test` lulus (test reaksi terbukti gagal tanpa fix); CI4 unit 134, JS 102, feature 249 (3 skip lama), integrasi 65 — semua lulus.
  - **Deploy gateway TEST 2026-10-12 08:11 WIB**: `normalize.js` + `webhookRoutes.js` disalin ke `C:\AuliaGateway-service-test\evolution-gateway\src\evolution\` (backup `C:\AuliaGateway-service-test\_backup_pre-reaction-20261012-081123`), service `AuliaGatewayAdapterTest` di-restart (pid baru 39484, port 13000, start bersih). Uji asap: webhook reaksi sintetis → CI4 dev baris `messages.id=578` (`text`=👍, `quoted_wa_message_id`=target, snippet "Pesan tidak ditemukan"), percakapan `last_message_direction=incoming`, `status=open` — lulus. Uji ini membuat percakapan dummy `conversations.id=39` (`628999000111`) di `aulia_inboxdb` dev — hapus bila disetujui. Catatan: gateway test tertinggal dari repo di 5 file lain (`config/index.js`, `outgoingOperationService.js`, `heartbeat.js`, `outgoingOperations.js`, `package.json`) — sengaja TIDAK ikut disalin.
  - Commit: WA-Gateway `0630f7f` (branch `evolution`), AuliaPos `3120c2b` (branch `v2.4`).
  - **Deploy produksi 2026-10-12 ~09:55 WIB** (disetujui user):
    - Gateway `aulia7`: `git merge --ff-only` `fea0713` → `0630f7f` (ikut membawa TODO-G1), restart `AuliaPosGatewayAdapter` 09:55. Port 3000 LISTEN, 0 log error, realtime tersambung, `gateway_status=connected`. Rollback: kembali ke `fea0713` + restart.
    - POS `AULIA-SERVER2`: `git fetch` + `merge --ff-only` `2148a1a` → `86ae724` (29 commit, termasuk TODO-S1/Q5/J1; tanpa migrasi). Working tree bersih. Smoke: login + 7 aset CSS/JS baru HTTP 200; `writable/logs` sejak 09:56 nol ERROR/CRITICAL. Rollback: `git reset` ke `2148a1a` (perlu persetujuan).
  - Sisa: (1) uji reaksi nyata 1-1 di produksi (belum ada reaksi masuk saat deploy dicatat); (2) cek visual user di PC kasir (Inbox + halaman ber-style baru TODO-S1); (3) hapus percakapan dummy `conversations.id=39` di DB dev bila disetujui.
  - Ref: `docs/requirements/2026-10-12-reaksi-masuk-dan-emoji-picker-inbox.md`, `docs/design/2026-10-12-reaksi-masuk-dan-emoji-picker-inbox.md`, `docs/CHANGELOG.md` 2026-10-12.

- [ ] **TODO-G1** Pesan grup masuk hilang diam-diam di Gateway — tinggi — bug ditemukan 2026-10-12 (dev, grup `120363431232595303@g.us`). Akar: `evolution-gateway/src/evolution/normalize.js` memasukkan `senderKeyDistributionMessage` ke `NOISE_NODES`, padahal WhatsApp menempelkannya ke pesan grup asli (pesan pertama anggota setelah ganti kunci) → seluruh pesan di-skip (log debug), tidak pernah sampai CI4. Fix: node itu hanya dianggap noise bila tidak ada konten lain (`NON_CONTENT_KEYS`); regression test di `test/test-lifecycle.js` (gagal tanpa fix, `npm test` lulus). Repo `C:\Projects\evolution-gateway` (belum di-commit). E2E dev **lulus** 2026-10-12 (pesan grup masuk id 574, `sender_jid` terpetakan ke nomor, `group_name` terisi). Commit `abd74e9`. **Deploy `aulia7` 2026-10-12 ~09:55 WIB** (ikut dalam deploy TODO-INBOX-EMOJI, `fea0713` → `0630f7f`). Sisa: kirim pesan uji ke grup di produksi untuk memastikan pesan grup masuk. Dampak produksi (pesan grup yang hilang sebelumnya) belum diverifikasi.

- [ ] **TODO-O4** (opsional) `VACUUM` + pantau ukuran disk PostgreSQL Evolution — sedang

- [ ] **TODO-F3** Terapkan ulang patch Evolution (`PATCH-ADAPTER (2026-10-01)` di `whatsapp.baileys.service.ts`) setiap kali Evolution di-upgrade; prosedur `C:\\Projects\\evolution-gateway\\docs\\evolution-viewonce-patch.md` — sedang

- [ ] **TODO-F10** Kesehatan & keandalan gateway **`aulia7`** (eks-`aulia3`, pasca-cutover 2026-10-09): deteksi dini kehilangan pesan masuk (khususnya **media**) akibat kegagalan sesi/dekripsi LID, sekaligus tinjauan log. (Gabungan eks-**TODO-L1**.) — sedang — ref insiden 2026-10-05 pelanggan Mell `6287857570921`, `docs/sesi/2026-10-06-analisa-log-gateway-aulia3.md`.
  - **Perubahan lingkup (2026-10-09)**: gateway produksi kini `aulia7`; task `AuliaMonitor` di `aulia3` sudah **Disabled** dan gateway aulia3 berhenti → **tidak ada monitoring live lagi**. Skrip `monitor-aulia3.ps1` hardcoded ke aulia3 dan **belum dipasang di aulia7** → gateway produksi saat ini **tanpa pemantauan kesehatan** (gap). Log `\\aulia3\D\kilo\logs\` tinggal arsip (read-only), tidak tumbuh.
  - **Monitor aulia7 — SELESAI 2026-10-09**: `scripts/monitor-gateway-aulia7.ps1` (repo `WA-Gateway` @ `b975bfa`) + Scheduled Task `AuliaPosGatewayMonitor` (tiap **15 mnt**, run as **SYSTEM**) aktif; output `C:\AuliaPosGateway\logs\monitor-gateway-aulia7.log`. **Gap "gateway tanpa monitor" tertutup.** Adaptasi dari `monitor-aulia3.ps1`: cek Windows Service (bukan task), parse Evolution log plaintext+ANSI, baca roll WinSW.
  - **Insiden**: Mell mengirim PNG `1_20261005_094116_0000.png` (~6 MB, caption `160x60`) ke **nomor resmi toko `6285155105633`**, tetapi pesan itu **tidak pernah sampai ke perangkat tertaut (Evolution) → tidak ada di gateway → tidak ada di POS**. Terverifikasi berlapis: tidak ada di Evolution `chat/findMessages` (chat tersimpan di bawah LID `224854976532488@lid`; satu-satunya file = `IMG_20261005_095239.jpg` caption `200x50`), tidak ada di `incoming_queue` gateway, tidak ada di media store `D:\\evolution-gateway\\data\\media`, tidak ada di `aulia_inboxdb`. Karena file tak pernah diterima, **tidak bisa dipulihkan dari server** (solusi sementara: minta pelanggan kirim ulang).
  - **Indikasi akar masalah**: Evolution mencatat `SessionError: No matching sessions found for message` (LID `224854976532488@lid`, pesan `AC68ACAC…`) + 2× `Message ignored with messageStubParameters` → sesi Signal per-perangkat tidak lengkap sehingga sebagian pesan (terutama media) gagal didekripsi dan dibuang **sebelum** sampai ke adapter. Kemungkinan diperparah relink nomor 2026-10-04.
  - (histori aulia3) **Monitoring (dipasang 2026-10-05)**: `monitor-aulia3.ps1` mendeteksi `SessionError: No matching sessions found for message`, `failed to decrypt`, `Message ignored with messageStubParameters`, dan `MessageCounterError`; dijalankan tiap **15 menit**; **read-only/alert-only** (tanpa auto-restart atau perubahan session). Window observasi 3 hari.
  - **Hasil sementara 2026-10-06** (`D:\\kilo\\monitor-aulia3.log`, 05 Okt 10:09 → 06 Okt 09:53 WIB): semua sinyal F10 = **0**; 2 event F10 lama (`last=05 Okt 10:07:27 SESSION_NO_MATCH`, pra-monitoring); **1 WARN** false alarm (05 Okt 16:02, `state=(belum ada)` saat alat baru dipasang); `authReject=0`; `evolution.log` tak pernah 0-byte; `flaps` 2→3 akibat restart 08:03 lalu stabil; ada jeda malam 20:23→08:08 (aulia3 mati malam).
  - (histori aulia3) **Lingkup tinjauan log (eks-TODO-L1)** — Checkpoint 2026-10-02 ~13:00 WIB: `adapter.log` & `evolution.log` di `\\\\aulia3\\D\\kilo\\logs\\` dikosongkan ke 0 byte (nonaktifkan `AuliaStackWatchdog` → stop `AULIAADAPTER` & `AuliaEvolution` → `Clear-Content` → start `AuliaEvolution` → start `AULIAADAPTER` → enable watchdog). Verifikasi pasca: port 3000 & 8080 listen; adapter `connected`; Evolution `CONNECTED TO WHATSAPP`. Log lama terarsip di `...\\arsip\\adapter_2026-10-02_1254.log` & `evolution_2026-10-02_1254.log` (baseline). **Yang dicari**: (a) `[AUTH] Request dari CI4 ditolak` & `webhook ditolak: secret tidak cocok/absen`; (b) `dead-letter`/`[CRITICAL]` baru; (c) `[HEARTBEAT-EVOLUTION] … fetch failed` yang tidak kembali `connected`; (d) pertumbuhan ukuran file; (e) `"level":50 "error in sending keep alive"` (berulang = koneksi WA tidak stabil).
    - **Temuan pemantauan 2026-10-05**: (e) **berulang** 4× dalam ~2 hari (02 Okt 13:14; 03 Okt 08:11 & 10:33; 04 Okt 14:17 WIB) — tiap kali diikuti `stream errored out`/`unexpected error in 'init queries'` lalu **pulih sendiri**. **(a) bersih** (tidak ada auth reject; `EVOLUTION_WEBHOOK_SECRET` terisi 64 char). **LOGOUT instance** 2026-10-04 11:59:31 WIB lalu relink ke nomor resmi `6285155105633` — **disengaja**, bukan anomali. Burst `[OnWhatsappCache] Error processing item …` 1746 baris (04 Okt 12:00, sekali jalan). `error in handling message` 1× (04 Okt 12:00, pesan `@lid` `pkmsg` tak terdekripsi). `evolution.log` sempat 0 byte pasca-rotate 06:57 (rotasi saat Evolution hidup).
  - **Verifikasi infrastruktur (eks-TODO-L1, sesi 2026-10-03)**: (i) rotasi log `arsip\\adapter_*.zip` & `evolution_*.zip` + `rotate-logs.log`; (ii) prune retensi (`[MAINTENANCE] … dipangkas`: media 180 hari, kutipan 7 hari, `incoming_queue` completed 30 hari); (iii) `phone` backfill tetap terisi setelah restart; (iv) task `AuliaLogRotate` ada & sukses; (v) backup harian `D:\\backup\\aulia3\\{pg,sqlite,media,env}` + `pg_restore -l` valid; (vi) exclusion Avast `D:\\evolution-gateway` masih ada.
  - **Yang boleh diabaikan**: skenario uji 1 Okt (dead-letter `KILO-MX-17` "koordinat tidak valid", "Field 'text' wajib diisi"), `PERINGATAN SECURITY bind 0.0.0.0`, transisi `connecting→connected` yang recover, `body request terlalu besar` (>64MB), spam `CACHE: { cached: undefined, … }`. Jendela uji disengaja: 2026-10-02 14:08:37–14:11:23 (uji backlog media) & stress test 14:17:05–14:28:00 (~209 pesan backlog).
  - **Temuan 2026-10-06 (analisa log)**: 9 baris `outgoing_operations` `in_flight` = kiriman media berkutipan gagal 04–05 Okt (error Evolution `TypeError: … 'fromMe'` di `generateWAMessageFromContent`); **sudah diperbaiki** commit gateway `b48c2e5`; isi 9 pesan gagal tidak tersimpan → lihat TODO-L3. Ref `docs/sesi/2026-10-06-analisa-log-gateway-aulia3.md`.
  - **Pekerjaan yang diusulkan**: (1) alarm sudah dipasang; (2) opsi perbaikan sesi: restart instance `aulia-toko` (rebuild sesi LID) atau upgrade Baileys bila bug dekripsi LID dikenal; (3) putuskan perlu-tidaknya pemeriksaan gap berkala (rekap pesan masuk Evolution vs `incoming_queue` per hari) + dokumentasikan prosedur verifikasi.
  - **Review ulang 2026-10-11**: pertanyaan "apakah berdampak pada rencana pindah gateway ke Windows Service" **sudah terjawab** — migrasi ke Windows Service (WinSW) selesai 2026-10-09 di aulia7. Sisa terbuka: putuskan remediation sesi/LID + perlu-tidaknya pemeriksaan gap berkala, kini untuk **aulia7**.
  - **Monitor aulia7**: **SELESAI 2026-10-09** — dipasang & terverifikasi (lihat bullet "Monitor aulia7 — SELESAI 2026-10-09" di atas).
  - **Insiden 2026-10-14 (baru, kejadian kedua)**: pelanggan `628214065612` (chat 363, LID `89636722442406@lid`) mengirim **gambar poster promo** pukul **12:13 WIB** (terbukti ada di WhatsApp Web toko), tetapi **tidak sampai ke server**. Terverifikasi berlapis: tidak ada di Evolution `Message`/`MessageUpdate`, `Media` tabel **kosong**, tidak ada di media store gateway (antara 12:00–12:20 hanya `mv7ndrxq` 12:06 & `mv7nnyjt` 12:14:34), tidak ada di `incoming_queue` SQLite, adapter log 0 kemunculan, tidak ada di `aulia_inboxdb.messages`. Jejak tunggal: 2× Evolution `WARN Original message not found for update. Skipping.` untuk `keyId=AC4FC3D89119F2F9F649DC7608B388C2` (`fromMe:false`) pada 12:18:01 & 12:36:56. `err.log` memuat `Failed to decrypt` + `Bad MAC` (libsignal), terakhir ditulis 11:41:28 WIB (sebelum 12:13) lalu berhenti. Gambar 12:14:36 (`ACF88A6E…`, `5830.jpg`) **normal** (GET `/inbox/media/5830` → HTTP 200 `image/jpeg` 109074 byte). **Tidak bisa dipulihkan dari server** (solusi sementara: minta pelanggan kirim ulang / ambil dari WhatsApp Web toko). Ref: `docs/sesi/2026-10-14-analisa-pesan-gambar-hilang-628214065612.md`.
  - **Celah pemantauan (temuan 2026-10-14)**: `scripts/monitor-gateway-aulia7.ps1` hanya mem-parse `AuliaPosGatewayAdapter.out.log` dan `AuliaPosGatewayEvolution.out.log`; error dekripsi ditulis ke **stderr** (`AuliaPosGatewayEvolution.err.log`) sehingga **tidak terdeteksi** — monitor melaporkan `F10 recent=0` padahal hari itu ada kegagalan dekripsi. Usulan fix (perlu direview/disetujui): pindai juga `*.err.log` pola `Bad MAC`/`Failed to decrypt`, hitung delta baris/ukuran sejak run sebelumnya (err.log tanpa timestamp), naikkan ke WARN/CRITICAL. Lihat SOP §6.
  - **Eksperimen Bad MAC (2026-10-15)**: hipotesis user = bentrok karena 2 WhatsApp Web + 1 Evolution aktif bersamaan (belum terbukti). Verifikasi read-only 2026-10-15: mutex per-JID **sudah ada** di Baileys (`processingMutex` global + `parsedKeys.transaction` per-JID) dan antrean `eventProcessingQueue` di Evolution, jadi patch mutex ke-3 tidak dibuat; 0 dari 9.460 file key Signal korup (558 `lid-mapping-*` valid berisi string); server reboot hampir tiap hari (shutdown ~20:2x, boot ~05:28-08:22) tanpa crash. Alat ukur dibuat: `scripts/wa-badmac-counter.js` (repo gateway, zero-dependency, baca saja, offset di state JSON, CSV per run, `--report` per hari/jam) + `test/test-badmac-counter.js` (T1-T10 lulus, 4 mutasi terdeteksi; dry-run pada salinan `err.log` aulia7 = 119/16/16 identik dengan hitungan independen). **DEPLOY 2026-10-15 (disetujui user)**: commit gateway `2bdae79` (belum di-push); skrip disalin ke `C:\AuliaPosGateway\evolution-gateway\scripts\` (SHA256 identik dengan lokal); Scheduled Task `AuliaBadMacCounter` (tiap 5 mnt, SYSTEM, Highest, `IgnoreNew`, batas 2 mnt). **Baseline run 15:46:41 WIB** (offset 85.060; hitungan dimulai dari titik ini, kondisi 2 WhatsApp Web hidup). Terverifikasi 2 run otomatis (15:47:50, 15:52:51), `LastTaskResult=0`, tanpa missed run. Output: `C:\AuliaPosGateway\logs\badmac-counter.csv` + `badmac-counter-state.json`; laporan: `node scripts\wa-badmac-counter.js --report [--by=hour --date=YYYY-MM-DD]`. Hasil eksperimen (hari WA Web hidup vs mati) belum ada; semua run awal bernilai 0 karena `err.log` belum bertambah sejak baseline. Batasan desain eksperimen: 1 hari vs 1 hari dikacaukan volume pesan masuk; bandingkan juga per jumlah pesan masuk.
  - **Pembanding independen WAGRAB (2026-10-17)**: ekstensi WA Web di aulia3 vs Inbox, 10–17 Okt, pesan masuk 1:1: 2.729 vs 2.540 cocok; 44 kandidat hilang (gambar 11 Okt 18:31 [20 vs 8, 4 ciphertext/Bad MAC di WA Web] & 13 Okt 16:26 [16 vs 3], gambar 14 Okt 12:13 = insiden F10, 1 dokumen, 16 ciphertext/Bad MAC). Burst Bad MAC 16 Okt ~17:00 tidak menghilangkan pesan 1:1. Receiver permanen dipasang di aulia7 port 8645 via Scheduled Task `WAGRAB-Receiver`. Detail: `docs/sesi/2026-10-17-pembanding-wagrab-vs-inbox.md`.
  - **Calon solusi jangka panjang (masuk backlog 2026-10-17)**:
    1. *Pencegahan*: Penjadwalan restart instance Baileys rutin (SOP Tingkat 1) via API lokal sebelum sesi kelelahan saat load tinggi / burst album.
    2. *Auto-heal / Fail-safe*: Mekanisme sync komparasi otomatis (WAGRAB bridge -> Inbox POS) untuk menambal pesan masuk (terutama foto album beruntun) yang lolos/drop di gateway Baileys.
       - **Status 2026-10-17**: endpoint `POST /api/inbox/sync-missing` **live di produksi** (`aulia-server2`, commit `9747c31`) dan worker `C:\AuliaPosGateway\scripts\wagrab-reconciler.mjs` + Scheduled Task `WAGRAB-Reconciler` (SYSTEM, tiap 3 menit, `--use-system-ca`) aktif di aulia7. Smoke test produksi lulus (401 tanpa/salah token, 200 token valid, duplikat → `ignored`). Sisa: alarm Telegram belum diisi, dan belum ada pesan sungguhan yang ditambal. Ref: `docs/sesi/2026-10-17-wagrab-reconciler-aulia7.md`.
  - **SOP remediation (dibuat 2026-10-14)**: `docs/ops/SOP-remediasi-sesi-signal-lid-gateway-aulia7.md` — Tingkat 1 restart instance `aulia-toko` (tanpa pairing ulang; **dieksekusi sukses 2026-10-17 12:14 WIB**), Tingkat 2 relink/pairing ulang, Tingkat 3 upgrade Baileys/patch Evolution (TODO-F3), prosedur diagnosis read-only, verifikasi pasca, dan perbaikan pemantauan.



## Selesai / Ditutup

- [x] **TODO-J1** Tampilan identitas karyawan di Matrix Jadwal & Roster — rendah — **SELESAI 2026-10-12**. Permintaan user: nama lengkap tidak ditampilkan pada Matrix Admin, Master Jadwal, maupun Roster (mingguan dan bulanan); gunakan inisial (+ divisi di Matrix Admin/Roster mingguan), termasuk dialog edit cell dan konfirmasi Mode Swap. Implementasi JS di `public/assets/js/jadwal.js` dan `roster.js`, fallback `inisial || nama || '-'`; daftar administratif/modal Tambah Jadwal/analisis tetap nama lengkap.
  - **Keamanan FIX 2026-10-12**: akar `JadwalModel::getKaryawanUntukPeriode()` memakai `select('users.*')` dan `Jadwal::master()` memakai `UserModel::findAll()`, sehingga `password_hash` dikirim ke browser. Keduanya kini allowlist `id,nama,inisial,divisi,is_active,priority`; semua 4 endpoint matrix terbukti bebas dari `password_hash`, `no_hp`, dan `profile_photo`, dengan field persis allowlist. `php -l` controller/model dan `node --check` kedua JS lulus; halaman+endpoint Jadwal/Roster HTTP 200.
  - **Ikut dikerjakan (tinggi Matrix)**: wrapper `.matrix-table-responsive` diubah dari `max-height` menjadi `height` tetap = sisa viewport (`window.innerHeight - rect.top - 24`, min 240px) supaya ruang bawah terpakai walau baris sedikit; ditambah guard saat tab non-Matrix aktif (`rect.width == 0`) dan hitung ulang saat tab Matrix dipilih kembali (`initTabs`). Berlaku hanya tab Matrix; Kalender/Master/Analisis dan Roster tidak diubah.
  - **Status**: SELESAI. Kode + fix keamanan di-push (`aad9699`, `296b79f`). Terverifikasi visual oleh user 2026-10-12: Matrix Admin, Master Jadwal, Roster mingguan/bulanan, dialog edit cell, Mode Swap, dan tinggi Matrix.

- [x] **TODO-S1** Pemusatan style/warna view — rendah — **SELESAI 2026-10-12**. Ref keputusan 2026-10-12: warna dipertahankan persis (hanya dipusatkan), login tetap tema gelap sendiri, cetak (`nota`/`ticket`) & `errors/html` tidak disentuh.
  - **Tahap 1–2 SELESAI 2026-10-12**: token warna di `public/assets/css/app.css` (`:root`), CSS layout dipindah ke `layout-main.css` / `layout-minimal.css`; blok `<style>` di `layout/main.php` & `layout/minimal.php` diganti `<link>` (cache-bust `filemtime`). Verifikasi: nilai CSS setelah resolve token identik aturan-per-aturan dengan aslinya (84/84 & 8/8), file dilayani HTTP 200, `php -l` lulus. Cek visual di browser oleh user belum dilakukan.
  - **Keputusan 2026-10-12 (revisi)**: warna harus **konsisten antar halaman** (Opsi A): palet semantik di `app.css`; warna status = Bootstrap 5.3 `*-bg-subtle`/`*-text-emphasis` (perubahan visual kecil disengaja); nuansa nyaris-sama digabung. Warna utama tombol/link akan jadi **navy Inbox `#1f3b5c`** (`--aulia-navy`) — dikerjakan sebagai langkah terpisah (menyentuh `btn-primary` di 26 view). Dikerjakan bertahap per halaman, dicek user di browser sebelum lanjut.
  - **Tahap 3a SELESAI 2026-10-12**: `jadwal/index` & `roster/index` → `assets/css/pages/jadwal.css` / `roster.css`, semua warna lewat token; satu-satunya perubahan visual = warna shift P/S/PM/L ke palet BS 5.3 (diverifikasi aturan-per-aturan). Cek visual user belum.
  - **Tahap 3b SELESAI 2026-10-12**: `kasir/index` & `kasir/edit` (CSS grid produk duplikat) → satu `assets/css/pages/kasir.css`; warna kotak cari jadi token `--color-accent-bg/-border/--color-focus-ring`. Tanpa perubahan visual di `kasir/index`; kotak cari produk di `kasir/edit` kini memakai `.produk-search-box` dan dipindah ke atas "Pilih Produk" (posisi sama dengan kasir/index; sebelumnya polos di bawah Aksi Khusus), form pelanggan/No. Order di `kasir/edit` dipindah ke dalam kolom kiri (`col-md-8`) seperti kasir/index sehingga cart sejajar dari atas; area item cart `height:500px` → `max-height:500px`; header cart edit sengaja tetap kuning "Item Transaksi" (penanda mode edit); dan tinggi kartu `#aksiKhususList` (54px/48px) sama dengan kasir/index (sebelumnya 108px/96px di edit — inkonsistensi lama). Cek visual user belum.
  - **Tahap 3c SELESAI 2026-10-12**: `transaksi/hari_ini` → `assets/css/pages/hari-ini.css`; 7 nuansa baris digabung jadi 5 token tabel di `app.css` (`--table-row-stripe/-cell-bg/-cell-stripe/-row-hover/-group-border`). Tanpa perubahan visual. 6 aturan `.transaction-batal` dihapus karena mati: query `Transaksi::ambilItemTerjualHariIni()` (`app/Controllers/Transaksi.php:919`, `t.status != 'batal'`) tidak pernah mengembalikan transaksi batal.
  - **Temuan (belum dikerjakan)**: kode PHP `$isBatal` + class `transaction-batal` di `app/Views/transaksi/hari_ini.php` (~baris 81-86, 539-540, 686-690) juga mati karena alasan yang sama — kandidat dibersihkan terpisah (di luar lingkup pemusatan style).
  - **Tahap 3d SELESAI 2026-10-12**: `produk/index` (2 blok `<style>`) → satu `assets/css/pages/produk.css`; warna lewat token baru `--color-focus-border` + `--tint-primary/-warning/-danger/-success` (umpan balik edit inline). Tanpa perubahan visual (31/31 deklarasi efektif identik; aturan `.action-buttons` ganda digabung). Cek visual user belum.
  - **Tahap 3e SELESAI 2026-10-12**: `ukuran/index` → `assets/css/pages/ukuran.css`. Tanpa perubahan visual. Aturan `.card-header.bg-gradient-secondary` dihapus karena mati (class tidak dipakai di view mana pun). Warna swatch tabel "Kode Warna" (`#cc0000` dst.) sengaja tetap inline: itu data warna cetak, bukan tema UI.
  - **Tahap 3f SELESAI 2026-10-12**: `auth/login` → `assets/css/pages/login.css`; tetap tema gelap mandiri (tidak memuat `app.css`), sisa hex dijadikan token lokal di `:root` file itu. Tanpa perubahan visual (35/35 aturan identik).
  - **Tahap 3 SELESAI**: tidak ada lagi blok `<style>` di view selain `cetak/nota`, `cetak/ticket`, `errors/html/*` (sengaja).
  - **Tahap 4 SELESAI 2026-10-12**: inline `style="..."` berwarna di view diganti class/token. Kartu Aksi Khusus (kasir+edit) → `.aksi-khusus-*` (`--tint-banner/-manual/-custom`); avatar inisial → `.avatar-initial` + `.avatar-32/-100/-140` (foto pakai `.avatar-photo`); kotak "Jadwal Hari Ini" roster → class shift sama dengan sel (palet BS 5.3, **perubahan visual** dari BS4); inbox `background:#fff` → `bg-white`; warna inline header layout yang selalu ditimpa `layout-main.css` (`#495057` ikon, `#0d6efd` tombol cari & avatar) dihapus; badge Shift Leader `#145a32` (PHP+JS) → satu class `.badge-shift-leader`; opsi No. Order rekomendasi `pink` → `.no-order-recommended` (`--color-highlight`, **perubahan visual** pink→kuning) dipakai PHP & `kasir-shared.js` agar sama sebelum/sesudah refresh. Swatch "Kode Warna" `ukuran/index` tetap inline (data warna cetak). Verifikasi: 8 halaman HTTP 200 tanpa error, `php -l`/`node --check` lulus. Cek visual user belum.
  - **Warna utama navy SELESAI 2026-10-12**: `:root` `app.css` kini `--bs-primary-rgb` + `--bs-link-*` = navy `#1f3b5c` (hover `#16293f`, active `#0f1d2d`), plus override per komponen Bootstrap 5.3: `.btn-primary`, `.btn-outline-primary`, `.form-control/.form-select:focus`, `.form-check-input:checked/:focus`, `.form-range` thumb, `.nav-pills`, `.pagination`, `.dropdown-menu`, `.list-group`, `.progress`. Token internal `--color-primary`/`--tint-primary`/`--color-focus-ring`/`--color-today-overlay`/`--color-focus-border` ikut jadi navy. Semua halaman (14 dicek) HTTP 200 tanpa error. Cek visual user belum.
  - **Sengaja dibiarkan**: biru `#0d6efd` di `inbox.css` (aksen seleksi/filter) — palet Inbox memang memakai biru seleksi terpisah dari header navy; bila ikut navy, header & seleksi jadi sama. Kandidat keputusan user. Juga `laporan/index` (palet seri chart) dan `inbox-page.js` (palet warna avatar acak) tidak disentuh., `auth/login` (token gelap sendiri). Lalu langkah warna utama navy.
  - **Shell abu-abu muda + judul halaman SELESAI 2026-10-12**: shell (sidebar+header) jadi abu-abu muda dengan teks gelap; token `--dark-*` di-rename `--shell-*` (`--aulia-dark` → `--aulia-shell-bg`) dan aturan yang mengandalkan teks-terang-di-atas-gelap di `layout-main.css` disesuaikan. Semua judul halaman diseragamkan jadi `card-header bg-primary text-white` + `<h5 class="mb-0">` dengan aksi di kanan (varian `bg-success`/`bg-warning`/`bg-dark` dan heading `h1.h3`/`h4` dihapus; 11 halaman tanpa bilah judul diberi satu). Header seksi di dalam kartu dijadikan polos. Wrapper `container-fluid` dihapus dari `cash/*`, `produk/index`, `produk/maintenance`, `ukuran` agar posisi kartu sama dengan mayoritas. Subjudul `laporan_pembayaran` & `item_harian` `text-muted` → `opacity-75` (tadinya tak terbaca di atas navy); satu `</div>` tak tertutup di `laporan_pembayaran` diperbaiki.
  - **Verifikasi penyeragaman judul**: `php -l` seluruh view 0 error; 33 halaman HTTP 200 tanpa error; kedalaman tag `<div>` ke-33 halaman identik dengan baseline HEAD (dibandingkan lewat stash lalu dipulihkan) — tidak ada regresi struktur. Cek visual user: `laporan-pembayaran` OK.
  - **Sisa kecil SELESAI 2026-10-12**: 2 caption chart `laporan/index` (`card-header py-2` + `small.text-muted`) → `card-header` + `<h6 class="mb-0">` dengan teks Title Case, sesuai pola header seksi; `inbox/test.php` judulnya `bg-warning text-dark` → `bg-primary text-white`, dan `<h6>` di dalam body → `<h6 class="mb-3">`. Sekalian: 4 `<h6>` telanjang lain (archive-transaksi 2, `kasir/modal_produk` 1, migrasi 1) → `<h6 class="mb-3">`. `<h6>` roster sengaja dibiarkan (margin diatur `.roster-hariini-box h6`).
  - **Verifikasi sisa S1**: `php -l` 0 error; 9 halaman terkait + `/inbox/test` HTTP 200 tanpa error; tidak ada lagi `card-header` ber-utility tambahan maupun `<h6>` telanjang di luar roster. Perubahan visual: caption chart laporan jadi 1rem gelap Title Case (sebelumnya 12.8px abu-abu uppercase); judul halaman uji jadi navy.
  - **Skala ukuran font SELESAI 2026-10-12**: satu skala 7 langkah di `app.css` (`:root`): `--fs-caption` 0.7rem, `--fs-tiny` 0.75rem, `--fs-small` 0.8rem, `--fs-compact` 0.85rem, `--fs-body` 0.9rem, `--fs-medium` 1rem, `--fs-title` 1.25rem. Semua `font-size` di CSS dalam lingkup (`app.css`, `layout-main.css`, `layout-minimal.css`, `pages/*` kecuali `login.css`) beserta 42 `font-size` inline di view diganti ke token; baris keranjang kasir jadi kelas `.cart-footer`/`.cart-sub` (+ `.kembalian-nilai`), label header jadi `.nav-label`/`.user-name`. Perubahan visual kecil di tempat yang dinormalkan (badge header 0.55→0.7rem, tabel padat 0.88→0.85rem, angka ringkasan 22/18→20px; override mobile `.summary-value` dihapus karena jadi redundan). Dikecualikan: `cetak/*`, `errors/*`, `login.css` (tema sendiri), `inbox.css` (skala sendiri), ukuran huruf avatar (2rem/2.5rem — mengikuti diameter) dan `kbd` produk (`0.8em` relatif).
  - **Verifikasi skala font**: `php -l` 0 lint error; 29 halaman HTTP 200 tanpa error; kurung kurawal CSS seimbang. Cek visual user belum.
  - **Jenis font**: sudah seragam (font sistem bawaan Bootstrap); tidak diubah. Halaman cetak (Arial/Courier), login, `errors/*`, dan satu elemen monospace Inbox memang berbeda.
  - **Hierarki angka + bobot font SELESAI 2026-10-12**: angka/KPI diseragamkan ke kelas `fs-*` — `h3`/`h4` di modal pembayaran dan label status kasir jadi `div.fs-3/fs-4 fw-bold` (id tetap, JS tak terpengaruh); tersisa hanya 3 `h2.display-4` hero dashboard yang memang sudah seragam. Bobot font pakai token `--fw-normal/-medium/-semibold/-bold` (400/500/600/700); semua nilai mentah `font-weight` di CSS dalam lingkup diganti token (nilai sama). Judul seksi `h6` sengaja tidak disentuh: roster mengatur marginnya lewat CSS dan `mb-0` akan merusak jaraknya. Panduan bobot dicatat di `app.css`.
  - **Verifikasi angka+bobot**: `php -l` 0 error; 29 halaman + endpoint `/api/modal/pembayaran` HTTP 200 tanpa error; semua token `--fs-*`/`--fw-*` terpakai (tidak ada token mati); brace CSS seimbang. Perubahan visual: angka modal pembayaran & label status di kasir jadi tebal (700, sebelumnya bobot heading 500).

- [x] **TODO-Q5** Medium — God-object & duplikasi render Inbox — **SELESAI 2026-10-11**.
  - Q5a (`e97e96d`): render PHP daftar percakapan yang dobel dihapus; satu-satunya renderer `renderDaftarConversation()`.
  - Q5b (`e97e96d`): CSS inline dipindah ke `public/assets/css/inbox.css`.
  - Q5c-1 (`c242947`): kode HTTP CI4 → Gateway dipindah utuh ke `app/Libraries/InboxGatewayClient.php`; `Inbox.php` tinggal wrapper `protected callGateway*` (seam test lama tetap jalan); `InboxGatewayApi` tidak lagi membuat `new Inbox()`.
  - Q5c-2 (`9ba37a4`): JS inline view (2.908 baris) dipindah ke `public/assets/js/inbox-page.js`, data/URL lewat `window.INBOX_PAGE_CONFIG`; round-trip isi JS identik (0 beda).
  - Ikut dikerjakan: restyle UI ala WhatsApp Web + palet navy/biru/hijau (`e5af865`); aksi bubble jadi menu dropdown panah + penanda "dihapus" tidak lagi dobel (`920d613`, CHANGELOG 2026-10-11). Filter status tetap grid tombol lama (keputusan user).
  - Hasil: `Views/inbox/index.php` 4353 → 601 baris; `Inbox.php` 4756 → 4219 baris. Guard: `tests/unit/InboxViewStructureTest.php` (render tunggal, CSS/JS eksternal tanpa tag PHP, semua handler `onclick` terdefinisi, id elemen tetap ada).
  - Verifikasi: unit 133, feature 245 (3 skip: 2 di luar jam shift, 1 kirim WA manual), integrasi 65, JS 94 — semua lulus; uji browser dikonfirmasi user. Di-push ke `origin/v2.4`; **belum di-deploy ke produksi**.
  - Catatan (bukan TODO): `Inbox.php` (±4.200 baris, logika kirim/teruskan/kutipan/handoff) dan `inbox-page.js` (±2.900 baris, belum punya unit test JS) masih besar. Pecah lebih lanjut hanya saat bagian itu memang akan diubah (mis. fitur Inbox baru), bukan sebagai refactor tersendiri.

- [x] **TODO-N1** Medium — Notifikasi Windows Inbox (HTTPS self-signed AULIA-SERVER2 + realtime `/realtime-ws`) — **SELESAI 2026-10-11**.
  - Sertifikat diimpor di semua PC kasir. AULIA-DP1 diimpor agen via SSH (`certutil -addstore Root`) dan terverifikasi (thumbprint `FBF775F4…D79168` di `LocalMachine\Root`, `curl https://192.168.1.10/aulia/login` → 200, `ssl_verify=0`). AAN-PC dan PC lain dikonfirmasi user.
  - Uji E2E di browser kasir (notifikasi Windows muncul + pesan baru tampil tanpa refresh): **dikonfirmasi user 2026-10-11**, tidak diamati langsung oleh agen.
  - Ref: `docs/sesi/2026-10-05-https-self-signed-aulia-server2.md`, `docs/sesi/2026-10-05-notifikasi-windows-inbox.md`.

- [x] **TODO-S3** Medium — Route `/migrasi-manual` aktif di produksi AULIA-SERVER2 — **DITUTUP 2026-10-11, TIDAK DIKERJAKAN (keputusan user: jalur ini masih dibutuhkan)**.
  - Alasan: `php spark` tidak bisa dipakai di AULIA-SERVER2, sehingga `/migrasi-manual` adalah satu-satunya cara menjalankan migrasi saat deploy produksi (dipakai deploy F8 2026-10-05, L3 2026-10-10, BL10/Q3h 2026-10-09).
  - Route tetap ada: `app/Config/Routes.php:112-113` (`MigrasiManual::index`, `MigrasiManual::jalankan`).
  - Mitigasi yang sudah ada: admin-only dua lapis (prefix `migrasi-manual` di `AuthFilter::$adminRoutes` + cek inline di `app/Controllers/MigrasiManual.php`), konfirmasi ketik `JALANKAN`, dan aplikasi hanya diakses di LAN (DEC-S2).
  - Buka lagi bila: tersedia cara migrasi lain di produksi (mis. `php spark` bisa jalan), atau aplikasi diekspos ke luar LAN.

- [x] **TODO-BL22b** Medium — CSRF global untuk seluruh POST/AJAX — **DITUTUP 2026-10-11, TIDAK DIKERJAKAN (keputusan user: effort dan risiko terlalu besar)**.
  - Keputusan: filter `csrf` global **sengaja dibiarkan mati** (`Filters.php:64-74`). Bagian yang berbahaya dan murah sudah ditutup lewat TODO-BL22 (5 aksi hapus/batal dari GET menjadi POST).
  - Alasan effort/risiko: menyalakan CSRF global memutus aplikasi bila ada satu pemanggil yang terlewat. Ada **71 route POST/PUT/DELETE** dan **33 file view** dengan `fetch`/`$.ajax`/form POST; baru 23 tempat memakai `csrf_field()` dan `layout/main.php` belum menyiapkan token untuk AJAX. Test otomatis tidak menjalankan JavaScript, sehingga verifikasi hanya bisa lewat uji manual di browser untuk kasir, laporan, cash, dan inbox.
  - Risiko yang diterima: form POST lintas situs tidak diblok oleh token. Mitigasi yang sudah ada: cookie sesi `SameSite=Lax` (`Cookie.php:90`, browser modern tidak mengirim cookie pada POST lintas situs) dan aplikasi hanya diakses di LAN (DEC-S2). Catatan: perlindungan `Lax` bergantung pada browser kasir; browser yang sangat lama belum diperiksa.
  - Bila suatu hari dibuka lagi (mis. SameSite diubah ke `None`, aplikasi diekspos ke luar LAN, atau ada insiden): (1) satu wrapper di layout yang menambah header `X-CSRF-TOKEN` ke semua `fetch`/`$.ajax`; (2) `csrf_field()` di semua form POST; (3) aktifkan bertahap per prefix route, bukan sekaligus; (4) kecualikan `api/inbox/gateway/*` (machine-to-machine, sudah Bearer token); (5) putuskan `regenerate = true` di `Security.php:74` karena token berganti tiap submit dan AJAX paralel bisa gagal; (6) uji manual per modul.

- [x] **TODO-Q3a** Low — Gateway test `test/simulate-evolution-adapter.js` GAGAL di assertion "path webhook/set benar" — **SELESAI 2026-10-10**.
  - Root cause (bug di test, bukan kode produksi): `captured` tidak di-reset sebelum `realClient.setWebhook()`, sehingga `captured[0]` masih request `sendMedia` → assertion membandingkan URL yang salah; sebelumnya assertion di-**skip** (dikomentari) sehingga test "hijau" palsu. Pre-existing di HEAD gateway `b48c2e5`, bukan dari fitur read-receipt.
  - Fix: tambah `captured.length = 0;` sebelum `setWebhook()`, buka kembali assertion `path webhook/set benar` (`endsWith('/webhook/set/inst-uji')`), hapus komentar workaround — `test/simulate-evolution-adapter.js:1086-1093` (repo `evolution-gateway`).
  - Verifikasi: `npm test` gateway (12 file test) lulus semua, exit bersih; assertion kini benar-benar ditegakkan. Murni test-hygiene; tidak menyentuh kode produksi gateway/CI4.

- [x] **TODO-Q3b** Low — DB uji `aulia_inboxdb_test` harus disamakan manual setiap ada migrasi Inbox baru (karena `php spark migrate` tak membangun DB uji, riwayat migrasi ada di grup `default`) → feature suite bisa gagal `Unknown column` — **SELESAI 2026-10-10** (Opsi A).
  - Command baru `aulia:sync-inbox-test-db` (`app/Commands/SyncInboxTestDb.php`): memaksa grup `inbox` → `aulia_inboxdb_test` (menolak DB lain, DSN/failover dikosongkan), mengosongkan tabel uji, lalu menjalankan **hanya** migrasi Inbox (`$DBGroup='inbox'`, dibaca via reflection tanpa meng-instansiasi migrasi POS agar tak menyentuh DB live/grup `default`) berurutan timestamp. Idempoten.
  - `composer.json`: `test:feature` = `php spark aulia:sync-inbox-test-db && phpunit --configuration phpunit.feature.xml` → skema DB uji selalu sinkron sebelum suite.
  - `docs/ARCHITECTURE.md` §9: prosedur setup DB uji diperbarui (dump manual → command); referensi row-size ceiling disesuaikan.
  - Verifikasi: DB uji dibangun ulang dari 22 migrasi Inbox → `message_send_audit` (+ kolom preview) muncul, hanya 7 tabel Inbox (tanpa tabel POS); command diulang = idempoten. `composer test:feature` OK (162 tests, 527 assertions); unit 95 OK (7 incomplete pre-existing); integrasi 26 OK.
  - Sisa (opsional): komentar workaround di `LogKirimGagalTest.php:12-15`/`InboxMessageSendAuditTest` kini usang (test masih benar) — dirapikan menyusul bila perlu.

- [x] **TODO-L3** Low/Medium — Auditabilitas kiriman keluar yang **gagal**: gateway sengaja hanya simpan `payload_hash` (SEC-001) dan POS hanya menulis baris `messages` setelah kirim sukses, sehingga isi kiriman gagal tak bisa diaudit/dilihat. **DEC-L3: Opsi B (ringan)** → tambah pratinjau RINGAN (teks penuh / metadata media, BUKAN byte media) ke `message_send_audit` + 2 halaman admin read-only. — **SELESAI 2026-10-10**.
  - Migrasi additive (`2026-10-10-000001_AddPreviewToMessageSendAudit.php`): kolom `preview_text` (VARCHAR 1000), `media_type`, `media_file_name`, `media_size` — semua nullable, tidak mengubah kolom lama. **Sudah dijalankan di DB lokal DAN produksi (`aulia-server2`, 2026-10-10 via `/migrasi-manual`).**
  - `Inbox.php::gatewayFailureResponse()` menerima parameter `$preview` opsional; diteruskan dari 3 titik panggil (kirim teks, kirim media, edit pesan). **Hapus pesan sengaja TIDAK mengisi preview** (yang gagal adalah aksi, bukan konten baru).
  - `MessageSendAuditModel::daftarUntukLog()`/`hitungUntukLog()`: query + JOIN `conversations` (DB `inbox` sama, aman di-JOIN SQL); `user_id` (nama kasir) di-resolve terpisah lewat `UserModel` (DB lain, logical reference saja).
  - `CashExpenseAuditModel::daftarUntukLog()`/`hitungUntukLog()` (temuan tambahan sesi ini — pola sama persis, tabel `cash_expense_audit` dari TODO-BL10 juga write-only tanpa viewer): JOIN langsung ke `users` (satu DB yang sama).
  - Halaman baru admin-only (pola proteksi sama dengan ArchiveTransaksi/MigrasiManual — prefix di `AuthFilter::$adminRoutes` + cek inline): `/log-kirim-gagal` (`LogKirimGagal.php`) dan `/log-audit-kas` (`LogAuditKas.php`); keduanya tautan filter tanggal + pagination + link balik ke percakapan Inbox terkait (`?conversation_id=`).
  - Menu navigasi baru di grup "Administrasi" (`layout/main.php`).
  - Test baru: 4 test preview (`InboxMessageSendAuditTest` AT8), 6 test `LogKirimGagalTest`, 6 test `LogAuditKasTest` — semua admin-only guard + isi data diverifikasi.
  - Regresi penuh lulus: unit 95, feature 162 (146→162), integration 26.
  - **Tidak ada perubahan di sisi Gateway** — seluruh data preview berasal dari input yang CI4 sudah punya SEBELUM memanggil Gateway, bukan diminta balik dari Gateway.
  - **Deploy produksi 2026-10-10**: `git pull --ff-only` di `aulia-server2` (`78b6dfd`→`805ffee`) + migrasi via `/migrasi-manual`. Backup pra-deploy: `C:\xampp\backup-db-aulia-server2\aulia_inboxdb_20261010_190504.sql` & `aulia_kasirdb_20261010_190513.sql`. Smoke test produksi lulus: kedua halaman 200, kolom baru terverifikasi di DB, data endpoint menampilkan 3 baris nyata (baris lama tanpa preview = sesuai desain). Rollback: kembalikan kode (kolom tambahan aman dibiarkan).
  - Ref: `docs/sesi/2026-10-10-log-audit-kiriman-gagal-dan-kas-todo-l3.md`.

- [x] **TODO-Q3** Medium — Kontrak cross-repo CI4 ↔ Gateway: repo gateway tersedia di `C:\Projects\evolution-gateway` (branch `evolution`); sisanya "belum ada test kontrak formal dua sisi" — **SELESAI 2026-10-10**.
  - Refactor extract-method behavior-preserving di `app/Controllers/Inbox.php`: payload builder (`buildSendPayload()`, `buildSendMediaPayload()`, `buildDeletePayload()`, `buildEditPayload()`, `buildMarkReadPayload()`) dipisah dari eksekusi cURL, supaya bentuk JSON bisa diverifikasi tanpa HTTP nyata.
  - Test baru CI4: `tests/unit/InboxGatewayPayloadContractTest.php` (13 test) — bentuk payload `/send`, `/send-media`, `/delete`, `/edit`, `/read`.
  - Test baru Gateway: `C:\Projects\evolution-gateway\test\test-contract-ci4.js` (ditambahkan ke `npm test`) — fixture identik dikirim ke `ci4Routes.js` nyata (arah CI4→Gateway) + verifikasi body `deliverOne()`/`deliverLifecycle()`/`deliverStatus()` (arah Gateway→CI4).
  - Gap kecil tambahan: `tests/feature/GatewayTokenFilterTest.php` (5 test, auth Bearer token diuji langsung).
  - Regresi penuh lulus: unit 95, feature 146, integration 26 (aulia); `npm test` gateway (30+ test existing + kontrak baru) semua lulus.
  - Di luar scope (gap terdokumentasi, bukan tertutup): `/media/download` (respons binary); test end-to-end HTTP nyata lintas proses (kedua sisi masih pakai mock/stub di test masing-masing).
  - Ref: `docs/sesi/2026-10-10-kontrak-dua-sisi-ci4-gateway-todo-q3.md`.

- [x] **TODO-BL33** Low — penomoran invoice `random_int(1,999)` per hari; tanpa idempotency key — `Api.php:420-442` — **DEC-4: Opsi A** → sekuens per hari `INV-YYYYMMDD-NNN` via lock/transaksi — **SELESAI 2026-10-06**

- [x] **TODO-F11** Medium — Ambil Alih percakapan via tombol: non-admin boleh takeover saat owner off-shift (grace 30 menit via `last_seen_by_assignee_at`) atau bila pengambil = Shift Leader aktif; flag `bisa_diambil` di daftar; guard race `WHERE assigned_to = <owner lama>`. Perluasan izin dibatasi pada titik takeover — `cekOwnership` TIDAK diubah. **Perlu keputusan lanjutan**: (a) apakah takeover perlu dicatat di `conversation_handoffs`/audit trail; (b) apakah grup perlu kebijakan takeover sendiri. Ref `docs/CHANGELOG.md` 2026-10-06; test `tests/feature/InboxAmbilAlihTest.php`. — **SELESAI 2026-10-06** (implementasi inti selesai; dua sub-pertanyaan (a)/(b) masih terbuka)

- [x] **TODO-O5** Low — **DITUTUP: kedaluwarsa** — prosedur update adapter di `aulia3` (non-git, npm manual, restart via task) tak lagi relevan setelah cutover ke `aulia7` (repo git + Windows Service WinSW). Lihat TODO-Q3h.

- [x] **TODO-Q3c** — **DITUTUP: kedaluwarsa** — keandalan auto-start Scheduled Task di `aulia3` tak lagi relevan (aulia3 pensiun sebagai gateway); digantikan Windows Service WinSW di aulia7 (TODO-Q3h).

- [x] **TODO-Q3d** — **DITUTUP: terlaksana** — gateway dipindah ke PC lain (`aulia7`) sebagai Windows Service WinSW. Lihat TODO-Q3h.

- [x] **TODO-Q3f** — **DITUTUP: digantikan** — rencana cutover aulia3 -> WinSW tercakup oleh TODO-Q3h (selesai 2026-10-09).

- [x] **TODO-Q3g** Medium — **DITUTUP: diimplementasikan** — installer Git-based (`Ensure-GitSource`) dipakai untuk cutover aulia7 (TODO-Q3h); source ter-pin di `evolution` @ `4e73d0de`. Residual opsional: uji E2E fresh-install di PC tanpa Git belum dilakukan.

- [x] **TODO-Q3h** Medium — **Cutover gateway produksi `aulia3` → `aulia7` — SELESAI 2026-10-09** (switchover, bukan paralel).
  - aulia7: PostgreSQL 16.15 (service `postgresql-aulia7`) + Evolution API tag `2.3.7` + adapter (branch `evolution` @ `4e73d0de`) sebagai Windows Service WinSW (`AuliaPosGatewayEvolution`/`AuliaPosGatewayAdapter`); IP static `192.168.1.68`; firewall 3000 hanya dari `192.168.1.10`, 8080/5432 blok eksternal.
  - Instance `aulia-toko` di-pairing ulang di aulia7 → state `open`, nomor `6285155105633`; `gateway_status` CI4 = `connected` (terverifikasi di `aulia_inboxdb`).
  - CI4: `inbox.gatewayBaseUrl` → `http://192.168.1.68:3000`; Apache `ProxyPass "/realtime-ws"` → `ws://192.168.1.68:3000/realtime`; realtime WebSocket (4 klien) terverifikasi terhubung.
  - aulia3: task `AuliaAdapter`/`AuliaEvolution`/`AuliaStackWatchdog`/`AuliaMonitor` **Disabled** (tidak di-unregister, demi rollback); port 3000/8080 mati.
  - aulia-server2 (POS CI4): `git pull` ke `a55d86e` (fast-forward) + migrasi `cash_expense_audit` & `message_send_audit` diterapkan ke production.
  - Backup DB production pra-deploy: `C:\xampp\backup-db-aulia-server2\`.
  - Rollback: lihat `docs/deploy.md` §8.
  - Ref: `docs/requirements/2026-10-09-cutover-gateway-aulia7.md`.

- [x] **TODO-S2** Login: rate-limit/lockout — **DITUTUP: accepted risk (DEC-S2)**.
  - Alasan: aplikasi POS hanya diakses dari **LAN internal** (dikonfirmasi user 2026-10-09), tanpa eksposur internet; `prosesLogin()` (`Auth.php:64-117`) dibiarkan tanpa throttling/lockout.
  - `session()->regenerate()` (fixation) sudah ada (`Auth.php:108`).
  - Mitigasi murah yang **belum** dikerjakan (opsional, hanya bila suatu saat diekspos ke luar LAN): pesan error generik "Username atau password salah" untuk menutup enumeration (`Auth.php:81,86`) + `Throttler` bawaan CI4 per-IP.

- [x] **TODO-F9** Kasir bisa mengerjakan percakapan `belum_diambil` tanpa meninggalkan jejak "sedang dikerjakan" → **ditutup dengan nudge manual** (bukan auto-assign).
  - **Keputusan**: dorong kasir menekan `Ambil` lewat nudge. TIDAK auto-assign `assigned_to`, tidak tambah status queue, tidak ubah skema, tidak tambah writer ke `assigned_to`/`last_seen_by_assignee_at`.
  - **Nudge 1 (banner)** — `app/Views/inbox/index.php`: banner non-modal tepat di atas textarea balasan (di luar area scroll), teks "Mau diambil, atau lihat-lihat saja?", tombol [`Ambil`]/[`Lihat saja`]. Muncul 7 dtk setelah buka percakapan `belum_diambil`; boleh muncul lagi setelah pindah conversation dan kembali (state `nudgeAmbilTampilUntukId` di-reset saat `pilihConversation()`). `[Lihat saja]` HANYA menutup banner, tidak mencatat note.
  - **Nudge 2 (modal unduh)** — `app/Views/inbox/index.php`, `public/assets/js/inbox-thread.js`: konfirmasi sebelum unduh media pada percakapan `belum_diambil`/dipegang orang lain; tombol `[Ambil & Unduh]`/`[Unduh saja]`/`[Batal]` + cabang ambil-alih bila grace lewat; `[Unduh saja]` mencatat note internal `[auto]` (best-effort).
  - **Backend** — `app/Controllers/Inbox.php` (`Inbox::catatNudgeUnduh()`), `app/Config/Routes.php`: `POST /inbox/percakapan/(:num)/nudge-unduh`; note `[auto]` server-side, coalesce 30 mnt per (conversation, user), tolak 409 `{ok:false, reason:'already_assigned'}` bila percakapan sudah punya pemilik. `catatanInternal()` tidak diubah.
  - **Verifikasi**: `tests/feature/InboxNudgeUnduhTest.php` (8 test) lulus; JS `tests/js/inbox-thread.test.js` 60/60; suite unit 82 / integration 26 / feature 133 lulus. Uji browser manual (11+ skenario interaksi) oleh user: **PASS (2026-10-09)**.
  - **Status**: **SELESAI & LIVE di produksi** (koreksi 2026-10-10 — catatan "belum commit/deploy" sudah basi). Commit `8172043` ter-include di deploy `a55d86e` (2026-10-09); terverifikasi 1 baris catatan `[auto]` di `aulia_inboxdb.messages` tercatat 2026-10-10 08:28:33.
  - Ref prior: `docs/requirements/2026-10-03-unduh-media-inbox.md`.

- [x] **TODO-BL10** High — mutasi kas tak admin-gated; pengeluaran terima tanggal sembarang (termasuk lampau/depan) — `AuthFilter.php:70`; `Routes.php:231-246`; `Cash.php:439,547` — **DEC-1: Opsi B** → bukan admin-gating; dikerjakan = rekam audit + validasi tanggal pengeluaran
  - **DONE — code, migration, and tests verified; deployed to production 2026-10-09 (`git pull` ke `a55d86e` + migrasi `cash_expense_audit` diterapkan).**
  - Keputusan: audit = tabel terpisah `cash_expense_audit` (JSON before/after,
    Opsi B+A); tanggal = tolak masa depan & tolak tanggal yang sudah
    `closing_kas` (Opsi A); kunci closing berlaku untuk update **dan**
    delete (Opsi A).
  - File baru: `app/Database/Migrations/2026-10-08-000001_CreateCashExpenseAuditTable.php`,
    `app/Models/CashExpenseAuditModel.php`.
  - File diubah: `app/Models/CashExpenseModel.php` (`validasiTanggal()`,
    `updatePengeluaran()`, `hapusPengeluaran()` sekarang transaksional +
    menulis audit + wajib `$userId`), `app/Controllers/Cash.php`
    (`cekTanggalSudahClosing()` + guard di `tambahPengeluaran()`,
    `updatePengeluaran()`, `hapusPengeluaran()`).
  - **Self-review menemukan & memperbaiki bug**: `updatePengeluaran()`/
    `hapusPengeluaran()` semula tidak memeriksa hasil `update()`/`delete()`
    sebelum menulis audit & commit — kegagalan validasi model (bukan error
    DB) bisa lolos sebagai "sukses" dan menulis audit palsu. Diperbaiki
    dengan cek hasil eksplisit + `transRollback()` manual; ditambah test
    regresi.
  - Test: `tests/unit/CashExpenseValidasiTanggalTest.php` (4 kasus),
    `tests/integration/CashExpenseAuditTest.php` (4 kasus, termasuk regresi
    bug di atas), `tests/integration/CashExpenseClosingLockTest.php`
    (5 kasus) — semua lulus; regresi penuh `phpunit.xml` (82 tests) &
    `phpunit.integration.xml` (26 tests) tetap lulus.
  - Database: migrasi **belum** dijalankan ke database produksi (menunggu
    jadwal deploy terpisah); tidak ada data existing yang disentuh.
  - Ref: `docs/requirements/2026-10-08-audit-validasi-kas-keluar.md`,
    `docs/design/2026-10-08-audit-validasi-kas-keluar.md`,
    `docs/CHANGELOG.md` 2026-10-08.

- [x] **TODO-BL18** Medium — saat filter kategori, `grand_total` pro-rata tapi `sisa_tagihan` penuh → piutang overstated — `Laporan.php:1043-1061,1087`
  - **DONE — code fix and regression tests verified**
  - Commit: `745524a`
  - Root cause: Line 1087 menggunakan `$t['grand_total']` (invoice penuh) padahal `$grandTotal` sudah di-pro-rata per kategori.
  - Fix: `sisa_tagihan = (invoice_sisa) * (kategori_grand_total / invoice_grand_total)` untuk pro-rata pembayaran per kategori.
  - Regression tests all pass:
    - `LaporanBL18Test::testMultiKategoriPartialPaymentProrata()` ✓
    - `LaporanBL18Test::testMultiKategoriWithDiskonProrata()` ✓
    - `LaporanBL18Test::testWithoutKategoriFilterUnchanged()` ✓
    - `LaporanBL18Test::testEmptyDetailSkipped()` ✓
  - Database safety:
    - Production DB untouched (14,876 transaksi unchanged)
    - Test only, read-only fix
  - Backward compatibility:
    - Behavior tanpa kategori filter unchanged
    - BL19 (atribusi kategori) tidak disentuh

- [x] **TODO-BL15** Medium — Harian (basis kas) vs Periode/Kategori (akrual) tidak sinkron untuk tanggal sama — `Laporan.php:60-64` vs `:123-130`
  - **CLOSED / BY DESIGN — DEC-2**
  - Perbedaan basis kas (Harian) vs akrual (Periode/Kategori) diterima sebagai desain sistem.
  - Logic laporan tidak diubah.

- [x] **TODO-BL16** Medium — `exportExcel(jenis=harian)` memakai jalur akrual, beda dari tabel Harian — `Laporan.php:1433,1490-1541`
  - **CLOSED / NOT PRIORITY — DEC-2**
  - Export `jenis=harian` tidak menjadi kebutuhan aktif; penggunaan hanya tab Bulanan.
  - Logic export tidak diubah.

- [x] **TODO-BL19** Medium — atribusi kategori campur master-produk & `detail_transaksi.kategori_id`; pembulatan tak direkonsiliasi — `Laporan.php:869,1247-1251,1288-1305`
  - **CLOSED — TWO PARTS: BL19-A FIXED + BL19-B BY DESIGN**
  - Commit BL19-A: `0d70b5d`

  **BL19-A: Category Attribution (FIXED)**
  - Root cause: `processPerKategori()` and `processBulanan()` used `produk.kategori_id` (current master) instead of `detail_transaksi.kategori_id` (historical snapshot).
  - Fix: Changed both functions to use `$d['kategori_id']` from detail record (snapshot at transaction time).
  - Impact: 12 affected transactions restored to correct historical categorization (e.g., INV-20260830-078 now shows 2 kategoris instead of 1).
  - Tests: 4 BL19 code inspection tests pass; BL18 regression tests (4/4) pass; all unit tests (78/78) pass; all integration tests (17/17) pass.
  - Database: No writes; production untouched; read-only fix only.
  - Backward compatibility: Normal cases (detail.kat == master.kat) unchanged.

  **BL19-B: Rounding Reconciliation (BY DESIGN — NO CODE CHANGE)**
  - Issue: INV-20260909-586 shows SUM(kategori netto) = 105990 vs grand_total = 105900 (difference = 90).
  - Root cause: NOT a defect. Rounding happens once at invoice level (KalkulasiDiskonTransaksi.php: `floor(pre-rounding/100)*100`).
  - Category allocation uses pre-rounding amounts; selisih_pembulatan captured at invoice level only, not distributed to categories.
  - Business rule: Verified from code + tests + source comment (Laporan.php:1290-1291). Intentional: categories are analytical allocation, not accounting reconciliation.
  - Data integrity: All 15 sample transactions (multi-kategori with discount) reconcile exactly via selisih_pembulatan. No unexplainable discrepancies.
  - Test invariant (KalkulasiDiskonTransaksiTest): `grand_total + diskon + selisih_pembulatan == subtotal` passes 100+ test cases.
  - Audit: `AUDIT-BL19B.md` documents full analysis; no code change required.
  - Status: Mathematically sound, consistent behavior, intentional design. No fix needed.

- [x] **TODO-BL14** Medium — dua sumber `total_dibayar` (kolom cache vs jumlah pembayaran aktif) bisa berbeda — `Tagihan.php:94-96` vs `:180-181`; `Laporan.php:1076`
   - **DONE — code fix deployed to production 2026-10-09 (`git pull` ke `a55d86e`); data repair TIDAK PERLU DIJALANKAN (lihat verifikasi 2026-10-10).**
   - Commit final: `720d800`
   - Root cause: nested transactions dalam `Api::koreksiPembayaran()` menyebabkan `sinkronkanPembayaran()` membaca interim state (pembayaran lama aktif + pembayaran baru aktif = 2× cache).
   - Fix: removed nested transaction, single atomic boundary via `transBegin()` → UPDATE reversed → INSERT aktif → sync → `transComplete()`.
   - Regression tests all pass:
     - `KalkulasiStatusPembayaranTest`: 9 tests, 11 assertions ✓
     - `RepairTotalDibayarTest`: 2 tests, 5 assertions ✓
     - `TransaksiPembayaranStatusTest`: 3 tests, 16 assertions ✓
     - `TransaksiSimpanAtomikTest`: 9 tests, 26 assertions ✓
     - Full phpunit.integration.xml: 17 tests, 69 assertions ✓
   - **Verifikasi data produksi 2026-10-10**: query pemeriksaan `aulia:repair-total-dibayar`
     (mode cek, tanpa `--fix`) direplikasi langsung ke `aulia_kasirdb` produksi via MySQL
     client (15.717 transaksi total, 4.840 di rentang Sep–Okt 2026) — hasil **0 baris
     inkonsisten**. 12 transaksi yang sebelumnya teridentifikasi tidak lagi ditemukan;
     kemungkinan sudah terkoreksi lewat jalur lain sebelum verifikasi ini, atau catatan
     "12" berasal dari snapshot analisis pra-deploy yang sudah tidak merepresentasikan
     state sekarang. **Tidak ada tindakan repair yang diperlukan.**
   - Database safety:
     - Production DB untouched (query read-only saja)
     - `aulia:repair-total-dibayar --fix` tidak dijalankan — tidak ada data untuk diperbaiki
     - Overpayment cases (3) remain out of scope (TODO-BL18 separate issue)

## Catatan struktur

- **Status utama:** Belum dikerjakan → Sedang dikerjakan → Selesai / Ditutup.
- **Prioritas di dalam status:** High → Medium → Low → Tanpa prioritas.
- **Selesai / Ditutup** mempertahankan item `[x]` untuk histori; penghapusan tetap memerlukan approval eksplisit.
