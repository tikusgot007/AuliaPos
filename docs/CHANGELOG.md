# CHANGELOG

## 2026-10-10 — Perbaiki korupsi encoding patch Evolution (TODO-F3) di aulia7 + dev

- **Perubahan operasional (bukan aturan bisnis)**: file
  `whatsapp.baileys.service.ts` di aulia7 (`C:\AuliaPosGateway\evolution-api-server`)
  ternyata punya korupsi encoding (mojibake) di ~15 baris komentar upstream berbahasa
  Portugis (mis. `função` → `funÃƒÆ'Ã‚Â§Ã£o`) — hasil double-encoding saat patch
  `PATCH-ADAPTER`/`PATCH-ADAPTER-LID` diterapkan manual sebelumnya. Tidak fungsional
  (hanya komentar), tapi membuat file tidak bisa dipakai sebagai referensi bersih.
- **Ditemukan saat**: membandingkan repo dev `C:\Projects\evolution-api-server`
  (working tree bersih, TANPA patch) dengan aulia7 (working tree ada 2 modifikasi
  lokal tidak ter-commit) atas permintaan user.
- **Perbaikan**: kedua patch (`PATCH-ADAPTER` view-once 2026-10-01,
  `PATCH-ADAPTER-LID` 2026-10-04) diterapkan ulang secara bersih di atas file
  upstream asli di **dev**, lalu file hasil (SHA256 `004E1D6B...`) disalin
  menggantikan file korup di **aulia7**. Diff berkurang dari `+33/-23` menjadi
  `+12/-3` (noise encoding hilang, logic patch identik). Backup file lama:
  `whatsapp.baileys.service.ts.bak-encoding-20261010121735` (aulia7).
- **Restart**: service `AuliaPosGatewayEvolution` di-restart agar source baru
  termuat (dijalankan via `tsx` langsung dari source, tanpa build step).
  Instance `aulia-toko` kembali `connected` ~2 detik pasca-restart; alur pesan
  masuk/keluar terverifikasi normal sebelum & sesudah.
- **Hasil**: dev (`C:\Projects\evolution-api-server`) dan aulia7 kini punya
  patch **identik** (hash sama) — dev dapat dipakai sebagai source of truth
  untuk menerapkan ulang patch saat Evolution di-upgrade.
- Ref: `docs/TODO.md` **TODO-F3**.

## 2026-10-10 — Aktifkan dekripsi teks pesan diedit (TODO-F8) di aulia7

- **Perubahan operasional (bukan aturan bisnis)**: `EVOLUTION_DECRYPT_MESSAGE_EDIT=1`
  ditambahkan ke `.env` adapter di `aulia7` (`C:\AuliaPosGateway\evolution-gateway\.env`).
  Sebelum ini, flag kosong/off menyebabkan adapter hanya mengirim marker lifecycle
  `event:'edited'` tanpa `edited_text` ke CI4 — `InboxGatewayApi::messageEvent()` jatuh
  ke `MessageModel::markLifecycle()` yang hanya mengisi `edited_at`, sehingga Inbox tetap
  menampilkan teks pesan **sebelum** diedit (badge "diedit" muncul, isi tidak berubah).
  Resolver dekripsi (`src/evolution/messageEditResolver.js`,
  `src/evolution/messageEditCrypto.js`) sudah terimplementasi dari spike TODO-F8
  (2026-10-04) tapi belum pernah diaktifkan di konfigurasi produksi manapun.
- **Ditemukan saat**: investigasi laporan user atas percakapan Inbox nomor
  `6281937281996` (kontak "Hamet") — pesan masuk 2026-10-10 10:30 WIB tampil
  "Ok siao" padahal pelanggan sudah mengedit jadi "oke siap"; `messages.edited_at`
  terisi tapi `messages.edited_text_resolved_at` tetap NULL.
- **Perubahan**: backup `.env` lama disimpan sebagai
  `.env.bak-20261010110856` pada folder yang sama; service Windows
  `AuliaPosGatewayAdapter` di-restart agar flag terbaca. Startup terverifikasi
  bersih (tidak ada error baru), koneksi realtime kasir otomatis reconnect.
- **Catatan**: hanya berlaku untuk event edit yang terjadi **setelah** flag aktif;
  pesan "Ok siao" milik Hamet yang sudah lewat tidak otomatis terkoreksi karena
  payload dekripsi hanya tersedia sesaat event diterima (bukan disimpan ulang).
- Ref: `docs/TODO.md` **TODO-F7**/**TODO-F8**.

## 2026-10-10 — Aktifkan webhook `MESSAGES_DELETE` (TODO-F7) di aulia7

- **Perubahan operasional**: `POST /webhook/set/aulia-toko` ke Evolution API aulia7
  menambahkan event `MESSAGES_DELETE` ke daftar subscription (sebelumnya hanya
  `MESSAGES_UPSERT`, `MESSAGES_UPDATE`, `CONNECTION_UPDATE`, `QRCODE_UPDATED`).
  Tanpa event ini, webhook hapus pesan **tidak pernah terkirim** ke adapter sama
  sekali (beda dari kasus edit: bukan payload kosong, event-nya memang tak lewat),
  sehingga `messages.revoked_at` tidak pernah terisi dan Inbox/POS tetap
  menampilkan pesan yang sudah dihapus pelanggan di WhatsApp.
- **Ditemukan saat**: audit lanjutan pasca-temuan TODO-F8 (lihat entri di atas),
  dipicu laporan user mencoba hapus pesan tapi masih tampil di POS.
- **Verifikasi**: `GET /webhook/find/aulia-toko` mengonfirmasi `MESSAGES_DELETE`
  masuk daftar `events`. **Diuji end-to-end oleh user (2026-10-10 ~11:54 WIB)**:
  badge "dihapus" muncul di Inbox; dikonfirmasi di database —
  `messages.id=3127` & `id=3390` (`revoked_at` terisi pasca-perubahan).
- **Audit tambahan** (fitur lain yang sudah diimplementasikan, dicek statusnya):
  `readreceipts: all` sudah aktif (TODO-Q3e, tidak ada masalah); patch Evolution
  view-once + LID preservation sudah terpasang (TODO-F3); Nudge 1/2 (TODO-F9)
  ternyata **sudah live** sejak deploy `a55d86e` — catatan "belum commit/deploy"
  di `docs/TODO.md` sudah dikoreksi.
- Ref: `docs/TODO.md` **TODO-F7**.

## 2026-10-09 — Cutover gateway produksi `aulia3` → `aulia7` + deploy POS ke production

- **Perubahan operasional (bukan aturan bisnis)**: gateway WhatsApp produksi
  dipindah dari `aulia3` (Scheduled Task) ke `aulia7` (Windows Service WinSW:
  `AuliaPosGatewayEvolution` + `AuliaPosGatewayAdapter`). Nomor WhatsApp
  `6285155105633` di-pairing ulang di aulia7. Port/instance tetap identik
  (`:3000` adapter, `:8080` Evolution, instance `aulia-toko`) sehingga kontrak
  CI4↔adapter tidak berubah.
- **CI4 (`aulia-server2`)**: `inbox.gatewayBaseUrl` → `http://192.168.1.68:3000`;
  proxy WebSocket Apache `/realtime-ws` → `ws://192.168.1.68:3000/realtime`
  (realtime Inbox kembali terhubung). `gateway_status` terverifikasi `connected`.
- **aulia3**: task `AuliaEvolution`/`AuliaAdapter`/`AuliaStackWatchdog`/`AuliaMonitor`
  di-**disable** (bukan dihapus) demi rollback; port 3000/8080 berhenti.
- **Deploy POS**: `aulia-server2` di-update `4c99c42` → `a55d86e` (fast-forward,
  36 commit) dan migrasi DB diterapkan — `cash_expense_audit` (`aulia_kasirdb`)
  + `message_send_audit` (`aulia_inboxdb`) — melengkapi fitur yang sebelumnya
  berstatus *"production migration deployment pending"*.
- **Backup pra-deploy**: `C:\xampp\backup-db-aulia-server2\` (mysqldump
  `aulia_kasirdb` + `aulia_inboxdb`, hash SHA256 tercatat).
- Rollback: lihat `docs/TODO.md` **TODO-Q3i**.
- Ref: `docs/requirements/2026-10-09-cutover-gateway-aulia7.md`.

## 2026-10-08 — Kas Keluar: audit trail + validasi tanggal (TODO-BL10)

- **Aturan bisnis baru**: pengeluaran kas (tambah/ubah) **tidak boleh**
  bertanggal masa depan, dan **tidak boleh** menyentuh tanggal yang sudah
  punya snapshot `closing_kas` (baik tanggal lama maupun tanggal baru saat
  mengedit). Berlaku juga untuk hapus: baris pada tanggal yang sudah
  di-closing tidak boleh dihapus. Hari ini dan tanggal lampau yang **belum**
  di-closing tetap boleh, tidak berubah dari sebelumnya.
- **DEC-1 (tetap berlaku)**: modul Kas Keluar TIDAK menjadi admin-only; akses
  tetap seperti sebelumnya (filter `auth`, bukan admin-gating).
- **Audit trail baru**: setiap update/delete pada `cash_expense` menulis satu
  baris ke tabel baru `cash_expense_audit` (append-only, tanpa FK supaya
  baris delete tidak ikut terhapus cascade) berisi snapshot JSON nilai
  sebelum & sesudah, pelaku, dan waktu. Create tidak diaudit (sudah tercatat
  lewat `user_id` baris itu sendiri).
- File: `app/Database/Migrations/2026-10-08-000001_CreateCashExpenseAuditTable.php`,
  `app/Models/CashExpenseAuditModel.php`, `app/Models/CashExpenseModel.php`
  (`validasiTanggal()`, `updatePengeluaran()`, `hapusPengeluaran()`),
  `app/Controllers/Cash.php` (`cekTanggalSudahClosing()` + ketiga endpoint
  kas keluar).
- **Self-review menemukan bug**: `updatePengeluaran()`/`hapusPengeluaran()`
  sebelumnya tidak memeriksa hasil `update()`/`delete()` sebelum menulis baris
  audit & commit — kegagalan validasi model (mis. nominal gagal
  `greater_than[0]`) mengembalikan `false` TANPA error level-DB, sehingga
  `transStatus()` saja tidak mendeteksinya; audit palsu bisa tertulis untuk
  perubahan yang sebenarnya tidak terjadi. Diperbaiki dengan memeriksa hasil
  `update()`/`delete()` secara eksplisit dan `transRollback()` manual bila
  gagal, sebelum pernah menulis baris audit.
- Test: `tests/unit/CashExpenseValidasiTanggalTest.php` (4 kasus),
  `tests/integration/CashExpenseAuditTest.php` (4 kasus, termasuk regresi
  bug di atas), `tests/integration/CashExpenseClosingLockTest.php`
  (5 kasus) — seluruhnya lulus (`phpunit.xml`, `phpunit.integration.xml`).
- Ref: `docs/requirements/2026-10-08-audit-validasi-kas-keluar.md`,
  `docs/design/2026-10-08-audit-validasi-kas-keluar.md`.
- **Migrasi belum dijalankan ke database produksi** — menunggu jadwal
  deployment terpisah.

## 2026-10-07 — Inbox: Toggle admin untuk expand isi pesan yang dihapus (teks saja)

- Setting GLOBAL baru (bukan per-user, bukan RBAC) lewat `.env`:
  `inbox.deletedMessageLocked` (default `true` = LOCKED).
- **LOCKED** (default): respons `GET /inbox/api/conversations/(:num)/messages`
  untuk pesan dengan `revoked_at` terisi kini **membuang** `text` dan
  `media_metadata` dari payload server — bukan sekadar disembunyikan UI
  seperti sebelumnya. `expandable:false`.
- **UNLOCKED**: `text` dikirim apa adanya + `expandable:true`, **HANYA**
  untuk `message_type='text'`. Pesan media (image/document/sticker/audio/
  video) yang dihapus **tetap** `expandable:false` walau unlocked (Opsi A,
  cakupan teks saja) — konsisten untuk kedua arah (incoming & outgoing).
- Nilai `.env` yang tidak sah (bukan true/false) **fail-closed** ke LOCKED,
  dengan log warning.
- UI: placeholder pesan dihapus (`.inbox-pesan-dihapus`) kini punya chevron
  ▼ opsional ketika `expandable:true` — klik untuk expand/collapse isi asli
  + label "Dihapus di WhatsApp pada `<timestamp>`". Tanpa chevron saat
  locked/media (perilaku sebelumnya, tidak berubah secara visual).
- **Bug pra-eksisting diperbaiki sekalian**: `renderIsiPesan()` memeriksa
  `message_type` SEBELUM `is_revoked`, sehingga pesan MEDIA yang dihapus
  sebelumnya tetap merender `<img>`/kartu dokumen PENUH (bukan placeholder)
  — bertentangan dengan tujuan fitur "Pesan dihapus" itu sendiri. Guard
  `is_revoked` sekarang dicek PALING AWAL di `renderIsiPesan()`.
- **Tanpa migrasi, tanpa route baru, tanpa halaman admin, tanpa audit log**
  (sesuai scope). Kontrak adapter `/delete`/`/edit`, handler lifecycle, dan
  tombol Edit/Hapus TIDAK disentuh.
- File: `app/Config/Inbox.php`, `.env.example`, `app/Controllers/Inbox.php`
  (`terapkanKebijakanPesanDihapus()`), `public/assets/js/inbox-thread.js`
  (`renderPlaceholderDihapus()`, `togglePesanDihapus()`), `app/Views/inbox/index.php` (CSS).
- Test: `tests/feature/InboxApiMessagesDeletedLockTest.php` (12 kasus),
  `tests/js/inbox-thread.test.js` (+4 kasus baru, total 60).

## 2026-10-07 — Inbox: Edit & Hapus pesan KELUAR + tampilan "Pesan dihapus"

- Fitur baru: kasir bisa **mengedit** (teks, maks 15 menit) dan **menghapus**
  (teks & media, "hapus untuk semua orang") pesan KELUAR langsung dari Inbox.
  Adapter gateway `POST /delete`/`POST /edit` (Bearer `gatewayToken`) dipanggil
  oleh CI4; token tidak pernah sampai ke browser.
- POS: endpoint baru `POST /inbox/pesan/(:num)/edit` & `.../hapus` (filter `auth`);
  `(:num)` = `messages.id` lokal, dipetakan server ke `wa_message_id`. Otorisasi
  mengikuti pola `kirim()` (cukup `auth`, konsisten operasional shift); validasi
  baris harus `direction='outgoing'` & milik percakapan (cegah IDOR).
- Aturan bisnis: edit hanya `message_type='text'`; di luar jendela 15 menit
  ditolak (`EDIT_WINDOW_EXPIRED`); pesan tanpa `wa_message_id` nyata (placeholder
  `local-…`) ditolak (`MESSAGE_NOT_SYNCED`); hapus idempoten (sudah `revoked_at`
  → sukses tanpa memanggil adapter). `operation_id` wajib untuk edit, opsional
  untuk hapus; `*_UNRESOLVED` TIDAK auto-retry (kunci dipertahankan untuk retry
  manual).
- UI: tombol **Edit**/**Hapus** pada bubble pesan keluar; modal edit + modal
  konfirmasi hapus; pesan yang dihapus dirender placeholder italic abu-abu
  "Pesan ini telah dihapus" dan aksi Balas/Teruskan disembunyikan.
- **Tanpa migrasi**: memakai kolom yang sudah ada `revoked_at`/`edited_at`/
  `edited_text_resolved_at` (TODO-F7/F8). Handler `message-event` yang ada tetap
  dipakai (webhook `MESSAGES_DELETE` menyusul hapus dari POS, idempoten).
- Referensi: `docs/requirements/2026-10-07-edit-hapus-pesan-keluar-inbox.md`,
  `docs/design/2026-10-07-edit-hapus-pesan-keluar-inbox.md`.

## 2026-10-07 — Inbox: WhatsApp read receipt dua arah

- Arah 1 (customer -> POS): saat kasir membuka percakapan pribadi (atau menekan
  "Tandai Dibaca"), AuliaPos meminta Gateway/Evolution menandai pesan MASUK
  pelanggan sebagai dibaca, sehingga WhatsApp mengirim blue tick ke pelanggan.
  Fail-soft: kegagalan TIDAK menggagalkan buka percakapan. Grup & `@lid` dilewati.
- Arah 2 (POS -> customer): event `MESSAGES_UPDATE` Evolution (`READ`/`DELIVERY_ACK`)
  kini diteruskan Gateway ke `POST /api/inbox/gateway/message-status`.
  `messages.read_at`/`delivered_at` diisi idempotent & monoton; pesan tak cocok
  (bukan outgoing / tak ada) -> 200 `matched:false` (tanpa retry tak berujung).
- UI: pesan keluar menampilkan centang ganda (`delivered`) / centang ganda biru
  (`read`). State internal `last_seen_by_assignee_at` dan makna
  `send_status(received/sent/failed)` **tidak** berubah.
- Endpoint baru: Gateway `POST /read` (Bearer), POS
  `POST /api/inbox/gateway/message-status` (gatewaytoken), POS
  `POST /inbox/percakapan/:id/whatsapp-dibaca` (auth).
- Migrasi: `2026-10-07-000001_AddReadStatusToMessages` (kolom `delivered_at`,
  `read_at`; DB group `inbox`).
- Referensi: `docs/requirements/2026-10-07-whatsapp-read-receipt.md`,
  `docs/design/2026-10-07-whatsapp-read-receipt.md`; test
  `tests/feature/InboxGatewayApiMessageStatusTest.php` (POS) +
  `test/test-read-status.js` (Gateway).

## 2026-10-07 — Inbox: Balasan POS ke percakapan closed membuka kembali (reopen)

- Aturan baru: kasir membalas (teks/balasan/Teruskan/media) dari POS ke percakapan
  `closed` membuka kembali percakapan ke `status='open'` + auto-assign ke kasir
  pengirim. `snoozed_until` direset. `closed_at`/`closed_by` dibiarkan sebagai
  "terakhir ditutup" (tidak ada audit reopen tambahan, keputusan Q-A).
- Pemicu reopen: **hanya balasan dari POS**. Outgoing yang disinkronkan dari
  WA Web/HP (direction='outgoing' lewat Gateway) **tidak** membuka kembali —
  hanya sync pesan, status tidak diubah. Incoming customer tetap reopen seperti
  sebelumnya (TANPA auto-assign).
- Auto-assign saat reopen: kasir pengirim POS menjadi `assigned_to` (model
  `assigned_to` = penangan aktif; percakapan `closed` tidak boleh punya penangan
  aktif). Percakapan `open` tanpa pemilik -> auto-assign seperti sebelumnya.
  Owner lain pada `open` TIDAK dioverride.
- Internal note tidak membuka kembali (bukan pesan ke customer).
- Implementasi: helper tunggal `updateSetelahKirimSukses()` di
  `app/Controllers/Inbox.php` dipakai bersama oleh `kirimTeksViaGateway()`
  (teks/balasan/Teruskan) dan `kirimMediaViaGateway()` (media) supaya aturan
  reopen/auto-assign tidak terduplikasi.
- Referensi: `tests/feature/InboxReopenFromPosTest.php`.

## 2026-10-07 — Inbox: Tutup sekaligus lepas kepemilikan + tombol Tutup sesuai hak

- Aturan lama: `tutupPercakapan()` hanya menulis `status='closed'`, `closed_at`, dan
  `closed_by`; `assigned_to` tetap terisi. Tombol "Tutup" di UI dirender hanya
  berdasarkan `status === 'open'` (bukan grup), tanpa mengecek kepemilikan — sehingga
  tombol muncul untuk semua kasir walau endpoint server bisa menolak 403.
- Aturan baru:
  1. **Tutup = menutup + melepas kepemilikan.** Saat berhasil, `assigned_to` di-set
     `NULL` (tidak ada konsep PIC tetap/VIP; `assigned_to` hanya menandai siapa yang
     sedang menangani percakapan aktif). Riwayat pesan tetap utuh; `closed_by` tetap
     mencatat siapa yang menutup.
  2. **Flag `bisa_ditutup` server** menjadi satu-satunya sumber kebenaran hak tampil
     tombol "Tutup" di UI. Dihitung dari aturan yang sama dengan guard server
     `tutupPercakapan()`/`cekOwnership()`: grup → false; `status != open` → false;
     `assigned_to` NULL/self/admin → true; milik staff lain → false.
- Reopen tetap satu arah: pesan masuk customer pada percakapan `closed` membukanya
  kembali (`status='open'`) TANPA mengembalikan `assigned_to` (tetap NULL), sehingga
  percakapan kembali "belum diambil" dan kasir mana pun yang tersedia bisa mengambilnya.
- Idempoten dipertahankan: menutup percakapan yang sudah `closed` tidak menimpa
  `closed_at`/`closed_by` lama.
- Implementasi: `bisaTutupPercakapan()` + `attachBisaDitutup()` di
  `app/Controllers/Inbox.php`, di-chain ke `index()`/`apiConversations()`/
  `apiMessages()`/`tutupPercakapan()`; `tombolTutup` di `app/Views/inbox/index.php`
  memakai `conv.bisa_ditutup`.
- Referensi: `tests/feature/InboxTutupPercakapanTest.php`.

## 2026-10-06 — Inbox: Ambil Alih percakapan (takeover via tombol)

- Aturan lama: tombol "Ambil" hanya berlaku untuk percakapan belum diambil siapa pun
  (`assigned_to IS NULL`); kasir non-admin tidak bisa mengambil percakapan yang sudah
  dipegang staf lain (harus menunggu admin melepas). Setelah pergantian shift, chat
  masih tercatat milik staf shift sebelumnya.
- Aturan baru: kasir non-admin boleh mengambil alih percakapan yang sudah dipegang
  orang lain lewat tombol **"Ambil Alih"** bila salah satu berlaku:
  1. owner sedang off-shift: tidak ada row `jadwal` hari ini, `shift = 'L'` (Libur),
     atau jam sekarang di luar sesi shift-nya (dievaluasi dengan
     `EvaluasiJendelaKerjaShift::sedangBekerja()`), **atau**
  2. pengambil adalah Shift Leader aktif saat itu (override — berlaku walau owner
     masih on-shift).
  Untuk owner off-shift berlaku **grace 30 menit**: selama owner masih menunjukkan
  aktivitas (`last_seen_by_assignee_at` dalam 30 menit terakhir), percakapan tetap
  dianggap miliknya (mencegah chat lepas saat lembur). Grup tidak pernah bisa
  diambil alih (CON-004); admin tetap bisa override kapan pun (regresi: perilaku
  admin tidak berubah).
- Aturan balas TIDAK berubah: `cekOwnership()` tetap owner/null/admin — orang lain
  tetap harus klik Ambil/Ambil Alih dulu sebelum bisa membalas (menghindari balasan
  siluman tanpa jejak perpindahan).
- Race dua staf menekan tombol bersamaan tetap ditangani conditional UPDATE
  (`WHERE assigned_to = <nilai owner lama>` untuk takeover, `IS NULL` untuk klaim);
  yang kalah menerima 409 dengan nama pemenang.
- Implementasi: `ambilPercakapan()` (`app/Controllers/Inbox.php`) + flag
  `bisa_diambil` pada daftar percakapan (`index()`/`apiConversations()`) yang dipakai
  UI untuk merender tombol "Ambil" (unassigned) / "Ambil Alih" (owned).
- Batasan diketahui: perpindahan takeover tidak tercatat di `conversation_handoffs`
  (tabel riwayat Handoff eksplisit); jejak takeover hanya implicit dari
  `messages.sent_by_user_id` (di luar scope perubahan ini).
- Referensi: `tests/feature/InboxAmbilAlihTest.php`.

## 2026-10-06 — Inbox: Handoff menjadi event timeline

- Aturan lama: riwayat Handoff ditampilkan sebagai panel sticky di atas thread dan dibaca
  melalui endpoint riwayat terpisah.
- Aturan baru: Handoff tetap disimpan append-only di `conversation_handoffs`, tetapi
  diproyeksikan sebagai event di timeline Inbox bersama Internal Note. Tidak ada row baru
  yang ditambahkan ke `messages`.
- Alasan: Handoff adalah aktivitas internal pada percakapan, sehingga konteksnya lebih
  mudah dibaca bila berada pada posisi kronologisnya, tanpa mengambil ruang sticky.
- Referensi: branch `feat/inbox-handoff-timeline`, test feature/JS terkait.

## 2026-10-06 — Inbox: sederhanakan form Handoff

- Aturan lama: Handoff meminta ringkasan percakapan dan tindakan lanjutan sebagai dua
  field wajib, dengan catatan tambahan opsional.
- Aturan baru: Handoff hanya meminta satu **Catatan Handoff** yang opsional. Target
  kasir tetap wajib dipilih.
- Alasan: Handoff merupakan penanda perpindahan tanggung jawab; kasir tidak selalu
  perlu menulis template ringkasan/tindakan terpisah.
- Catatan teknis: schema `conversation_handoffs` tidak diubah. Kolom `next_action`
  legacy tetap disimpan sebagai string kosong untuk data Handoff baru.
- Referensi: branch `fix/inbox-handoff-form`.

## 2026-10-05 — Inbox: notifikasi Windows sebagai jalur utama (toast jadi fallback)

- Aturan lama: pesan Inbox yang perlu dibalas diberi tahu lewat toast hijau sticky di
  dalam halaman (plus judul tab, favicon, dan beep).
- Aturan baru: jalur utama pemberitahuan pesan Inbox adalah **notifikasi Windows**
  (browser `Notification` API) yang muncul walau tab POS tidak difokuskan; klik
  notifikasi membuka window Inbox ke percakapan itu. Toast hijau tetap ada **hanya**
  sebagai fallback bila API tidak tersedia atau izin user belum diberikan. Tombol
  "Aktifkan notifikasi" tampil di sidebar saat izin masih `default`.
- Alasan: kasir perlu tahu ada pesan WA perlu dibalas walau POS tidak sedang aktif /
  pesan masuk ke Action Center, bukan hanya saat halaman dipandang.
- Catatan operasional: `Notification` API hanya aktif di secure context; produksi
  HTTP LAN (`http://192.168.1.10/aulia`) harus disajikan HTTPS self-signed dulu
  (langkah infra terpisah, **belum dikerjakan**). Selama HTTP, yang aktif adalah
  fallback toast.
- Referensi: `docs/requirements/2026-10-05-notifikasi-windows-inbox.md`,
  `docs/design/2026-10-05-notifikasi-windows-inbox.md`,
  `public/assets/js/inbox-notifikasi.js`, `tests/js/inbox-notifikasi.test.js`.

## 2026-10-05 — Inbox WhatsApp: tampilkan teks edit yang sudah tervalidasi

- Aturan lama: semua pesan yang ditandai "diedit" ditampilkan samar dengan label
  "Pesan diedit — versi ini belum tentu terbaru", termasuk ketika Gateway sudah
  berhasil memproses dan menyimpan teks hasil edit yang benar.
- Aturan baru: pesan diedit yang teksnya SUDAH berhasil divalidasi dan disimpan
  POS ditampilkan normal dengan label "Pesan diedit — teks terbaru".
  Pesan diedit tanpa hasil edit tervalidasi tetap samar dengan label lama.
- Alasan: kasir perlu membedakan teks versi lama yang masih perlu dikonfirmasi
  dari teks hasil edit yang sudah tersedia dan tervalidasi di Inbox.
- Referensi: branch `todo-f8-message-text` (verification recorded 2026-10-05),
  `docs/requirements/2026-10-05-tampilkan-teks-edit-tervalidasi-inbox.md`,
  `docs/design/2026-10-05-tampilkan-teks-edit-tervalidasi-inbox.md`.
- Deploy produksi 2026-10-05: AuliaPos `v2.4` (`a127d63`) di AULIA-SERVER2 + migrasi
  `2026-10-05-000001` (kolom `edited_text_resolved_at` di `aulia_inboxdb`); gateway
  `evolution` di aulia3 dengan `EVOLUTION_DECRYPT_MESSAGE_EDIT=1`. Uji nyata F7 & F8
  lulus; lihat `docs/sesi/2026-10-05-deploy-produksi-f8.md`.

Perubahan aturan bisnis Aulia Kasir. Bahasa Indonesia (AGENTS.md §15).

Format entri:

```
## YYYY-MM-DD — <judul singkat>
- Aturan lama: <apa>
- Aturan baru: <apa>
- Alasan: <mengapa>
- Referensi: commit `<hash>`, docs/sesi/<file>
```

---

## 2026-10-04 — Inbox WhatsApp: tandai pesan yang diedit/dihapus pelanggan

- Aturan lama: pelanggan **mengedit** pesannya -> muncul baris noise
  `unsupported` ("...secretEncryptedMessage...") sementara pesan asli tetap
  menampilkan teks LAMA; pelanggan **menghapus** (untuk semua) pesannya -> tidak
  berjejak sama sekali dan pesan aslinya tetap tampil seolah masih ada.
- Aturan baru: pesan ASLI ditandai di Inbox — badge "Diedit pelanggan — versi ini
  belum tentu terbaru" (teks yang tampil adalah versi lama; isi edit tidak bisa
  dibaca) dan "Dihapus pelanggan — cek WhatsApp Web". Edit/hapus **tidak** lagi
  membuat baris pesan baru.
- Alasan: kasir tidak boleh salah membaca teks lama sebagai isi final, atau
  mengira pesan yang sudah dihapus masih berlaku. Isi hasil edit terenkripsi dan
  tidak terbaca gateway (spike 2026-10-04), jadi yang ditampilkan hanya penanda.
  Deteksi hapus butuh langganan webhook `MESSAGES_DELETE` di Evolution.
- Referensi: `docs/requirements/2026-10-04-tandai-pesan-diedit-inbox.md`,
  `docs/design/2026-10-04-tandai-pesan-diedit-inbox.md`, `docs/TODO.md` (TODO-F7).

## 2026-10-04 — Inbox WhatsApp: label edit/hapus tidak lagi menyebut "pelanggan"

- Aturan lama: badge selalu berbunyi "Diedit pelanggan — ..." / "Dihapus
  pelanggan — ..." untuk SEMUA pesan yang ditandai, tanpa membedakan arah.
- Aturan baru: badge jadi "Pesan diedit — versi ini belum tentu terbaru" /
  "Pesan dihapus — cek WhatsApp Web", tanpa menyebut pelaku.
- Alasan: event edit/hapus WhatsApp juga berlaku untuk pesan KELUAR
  (`direction=outgoing`) yang staf kirim & edit/hapus sendiri lewat WA Web/HP
  langsung (di luar POS) — ditemukan nyata saat uji end-to-end TODO-F8
  (2026-10-04, pesan `Wkwkw` diedit staf sendiri tetap berlabel "pelanggan").
  Label lama menyesatkan pada kasus ini.
- Referensi: `public/assets/js/inbox-thread.js` (`renderLabelDiedit`,
  `renderLabelDihapus`), `tests/js/inbox-thread.test.js`, `docs/TODO.md` (TODO-F7/F8).

## 2026-10-04 — Archive transaksi: piutang aktif tidak ikut diarsipkan

- Aturan lama: Archive Transaksi memindahkan **semua** transaksi pada bulan yang
  dipilih, tanpa memandang status pembayaran; transaksi `belum_bayar`/`dp`
  (piutang) ikut dipindah lalu dihapus dari DB utama, sehingga hilang dari daftar
  Tagihan.
- Aturan baru: hanya transaksi **`lunas`**, **`batal`**, atau **`mangkrak`** yang
  eligible diarsipkan. Piutang aktif (`belum_bayar`/`dp`, status bukan
  batal/mangkrak) **tetap** di DB utama. Piutang macet tetap bisa diarsipkan
  setelah admin menandainya `mangkrak` (katup keluar). Preview menampilkan jumlah
  & nilai piutang aktif yang **tidak** ikut diarsipkan.
- Alasan: piutang adalah data hidup yang masih harus ditagih; arsip tidak boleh
  menghilangkannya dari Tagihan (keputusan produk DEC-3: "A+ dengan katup E").
- Referensi: `app/Services/TransaksiArchiveService.php`,
  `app/Views/archive_transaksi/index.php`,
  `docs/requirements/2026-10-04-arsip-piutang.md`,
  `docs/design/2026-10-04-arsip-piutang.md`,
  `docs/TODO.md` (TODO-BL06, TODO-DEC3).

## 2026-10-04 — Transaksi POS: validasi baris item (jumlah/harga/subtotal)

- Aturan lama: jumlah, harga, dan subtotal tiap baris keranjang dipercaya apa
  adanya dari klien; `jumlah <= 0`, nilai negatif, dan `subtotal` yang tidak
  konsisten dengan `harga × jumlah` tetap tersimpan.
- Aturan baru: server menolak baris dengan `jumlah <= 0`, `jumlah > 9999`, harga
  atau subtotal negatif, dan (untuk item non-banner) `subtotal != harga × jumlah`.
  Item banner memakai `subtotal` (total masukan kasir) sebagai acuan dan
  `harga_satuan` dihitung server `round(subtotal / jumlah)`. Aturan ini dipakai
  bersama jalur buat & edit lewat `ValidasiItemTransaksi`.
- Alasan: laporan dan grand_total menjumlahkan `detail_transaksi.subtotal`;
  input klien yang tidak masuk akal bisa merusaknya.
- Referensi: `app/Services/ValidasiItemTransaksi.php`, `app/Controllers/Api.php`,
  `app/Controllers/Transaksi.php`,
  `docs/requirements/2026-10-04-validasi-item-transaksi.md`,
  `docs/design/2026-10-04-validasi-item-transaksi.md`, `docs/TODO.md` (TODO-BL03).

## 2026-10-04 — Closing kas: snapshot `saldo_sistem` bersifat final

- Aturan lama: `saldo_sistem` closing dihitung ulang dari data live setiap kali
  modal closing dibuka atau disimpan ulang. Setelah transaksi satu bulan
  dipindahkan ke arsip (baris live dihapus), hitung ulang menghasilkan penjualan
  tunai = 0 sehingga snapshot closing yang benar bisa tertimpa angka salah.
- Aturan baru: `saldo_sistem` closing yang sudah tersimpan bersifat **final**
  (imutabel); edit closing hanya mengubah `saldo_fisik` dan `selisih`
  (`selisih = saldo_fisik − saldo_sistem tersimpan`). Hitung ulang hanya untuk
  tanggal yang belum pernah di-closing, dan hitungan itu sudah mencakup
  penjualan tunai yang ada di database arsip.
- Alasan: closing adalah fakta historis; laporan kas & audit tidak boleh berubah
  hanya karena data operasional lama dipindahkan ke arsip.
- Referensi: `app/Controllers/Cash.php`, `app/Models/ClosingKasModel.php`,
  `app/Services/KalkulasiClosingKas.php`,
  `app/Services/TransaksiArchiveService.php`,
  `docs/requirements/2026-10-04-closing-kas-arsip.md`,
  `docs/design/2026-10-04-closing-kas-arsip.md`, `docs/TODO.md` (TODO-BL02).

## 2026-10-03 — Foto profil: batas ukuran 2 MB benar-benar ditegakkan

- Aturan lama: unggahan foto profil dibatasi 2 MB, tetapi validasinya memakai
  `UploadedFile::getSizeByUnit('kb')` yang mengembalikan string berformat ribuan
  (mis. `"2,048.000"`). Perbandingan `>` terhadap batas int karena itu selalu
  gagal, sehingga **file lebih dari 2 MB tetap tersimpan**.
- Aturan baru: batas 2 MB ditegakkan dengan membandingkan byte mentah
  (`getSize() > 2048 * 1024`); file lebih dari 2 MB ditolak dengan pesan
  "Ukuran file maksimal 2 MB." Nilai batasnya sendiri tidak berubah.
- Alasan: batas yang dijanjikan ke pengguna (maksimal 2 MB) harus benar-benar
  berlaku; bug ini membuat file besar lolos dan tersimpan ke disk.
- Referensi: `app/Libraries/FotoProfilService.php`,
  `tests/feature/FotoProfilServiceTest.php`, `docs/TODO.md` (TODO-F7); pola
  perbaikan sama dengan PR #48 (`BalasanTemplateImageService`).

## 2026-10-03 — Laporan: ekspor Excel saja (hapus Print/Copy/PDF) + format `.xls` berkolom

- Aturan lama: tabel laporan (Item Harian, Laporan Pembayaran, tab Periode, dan
  tab agregat Harian/Bulanan/Kategori) punya tombol Print/Copy/PDF/Excel; pada
  tabel server-side tombol hanya mencakup halaman aktif, dan CSV berpemisah `;`
  terbaca **satu kolom** di Excel (setting locale tertentu).
- Aturan baru: hanya **satu tombol Excel** per tabel; Print, Copy, dan PDF
  dihapus. Ekspor server-side menghasilkan berkas **`.xls` (tabel HTML)** via
  `App\Libraries\ExcelTable`, sehingga Excel membuka dengan kolom yang benar
  tanpa bergantung pada pemisah daftar/locale. Item Harian & Laporan Pembayaran
  memakai endpoint ekspor server-side yang sudah ada; tab Periode memakai
  `/laporan/periode-export` (seluruh baris terfilter); tab agregat tetap
  client-side (`excelHtml5`, seluruh baris).
- Alasan: kebutuhan operasional hanya ekspor Excel; tombol cetak/salin/PDF hanya
  mencakup halaman aktif dan format CSV berdelimiter menyesatkan kasir.
- Referensi: commit `853413d` (merge PR #45),
  `docs/sesi/2026-10-03-inbox-media-notifikasi-ops-gateway.md`,
  `app/Libraries/ExcelTable.php`, `tests/unit/ExcelTableTest.php`.

## 2026-10-03 — Inbox: notifikasi lintas halaman (judul tab, favicon, suara, toast)

- Aturan lama: satu-satunya mekanisme lintas halaman adalah badge angka diam
  di sidebar (`GET /inbox/api/perlu-dibalas-count`, total tim); tidak ada yang
  menarik perhatian kasir di luar window Inbox.
- Aturan baru: endpoint baru `GET /inbox/api/notifikasi-ringkas` mengembalikan
  percakapan `perlu_dibalas` yang RELEVAN untuk user yang login (belum ada
  yang pegang, ATAU dipegang user itu sendiri; admin melihat semua, sama
  seperti `cekOwnership()`). Poller di layout utama memakainya untuk: judul
  tab `(N) ...`, titik merah di favicon, beep (bisa dibisukan lewat lonceng
  di sidebar, `localStorage`), dan toast **sticky** (tidak hilang sendiri)
  yang bisa **menumpuk** (toast baru di BAWAH toast lama, warna hijau
  WhatsApp bukan biru generik, komponen terpisah dari `showToast()`/
  `#liveToast` yang dipakai fitur lain). **Satu toast selalu satu
  percakapan** — kalau beberapa percakapan jadi "baru" dalam satu siklus
  polling yang sama, masing-masing tetap mendapat toast sendiri (bukan
  digabung satu toast banyak nama), hanya satu beep untuk siklus itu;
  diklik selalu membuka window Inbox langsung ke percakapan yang disebut
  toast itu (`?conversation_id=`) DAN langsung menutup toast itu sendiri
  (toast lain yang sedang tampil tidak ikut tertutup). Toast juga hilang
  otomatis begitu percakapan yang disebutnya sudah tidak lagi relevan
  (sudah ditangani dari jalur lain), atau lewat tombol tutup manual.
  Percakapan yang sudah pernah dilaporkan
  (per `last_message_at`) tidak memicu toast/bunyi ulang; percakapan yang
  sudah `perlu_dibalas` SEBELUM tab dibuka juga tidak memicu toast/bunyi
  pada polling pertama sesi itu.
- Alasan: kasir perlu tahu ada pesan yang perlu dibalas walau sedang di
  halaman lain (misalnya layar Kasir), bukan hanya saat membuka Inbox.
- Referensi: `docs/requirements/2026-10-03-notifikasi-inbox-lintas-halaman.md`.
  `apiPerluDibalasCount()`/badge sidebar lama tidak diubah. Kontrak Gateway
  tidak berubah.

## 2026-10-03 — Inbox: unduh gambar & dokumen yang mudah (lightbox, kartu file, unduh massal)

- Aturan lama: gambar hanya bisa disimpan lewat klik kanan (nama `media-<id>` tanpa
  ekstensi); dokumen berupa tautan yang membuka tab baru, dan kegagalan media
  (kadaluarsa/Gateway mati) tampil sebagai tab berisi JSON.
- Aturan baru: klik gambar membuka lightbox dengan tombol Unduh; gambar/sticker
  punya ikon unduh saat hover; dokumen tampil sebagai kartu (ikon, nama, jenis/ukuran,
  tombol Unduh). File tersimpan dengan nama berekstensi (`media-<id>.jpg`, atau nama
  asli pengirim). Kegagalan unduh tampil sebagai toast dengan penyebab. Tombol
  "Pilih media" mengaktifkan mode pilih untuk mengunduh banyak file sekaligus
  (berurutan, maksimum 100 per aksi, tanpa ZIP, ke folder Download browser; Brave
  dapat meminta izin unduh banyak file sekali). `?unduh=1` pada `/inbox/media/:id`
  memaksa `attachment`; hanya gambar non-SVG yang `inline`.
- Alasan: kasir perlu menyimpan foto/nota/dokumen pelanggan dengan cepat dan jelas.
- Referensi: `docs/requirements/2026-10-03-unduh-media-inbox.md`. Audio/video tetap
  placeholder; kontrak Gateway tidak berubah.

## 2026-10-02 — Inbox: riwayat thread menampilkan 200 pesan TERBARU dengan pagination

- Aturan lama: thread percakapan mengambil 500 pesan **tertua**
  (`message_timestamp ASC` + `LIMIT 500`), tanpa cara membuka pesan lain.
  Percakapan lebih dari 500 pesan berhenti menampilkan pesan baru.
- Aturan baru: `GET /inbox/api/conversations/{id}/messages` mengembalikan
  200 pesan **terbaru** (urut lama ke baru). Parameter opsional `before_id`
  mengambil 200 pesan tepat sebelum pesan itu (kursor `message_timestamp, id`),
  dan respons memuat `has_more`. Di thread Inbox, tombol "Muat pesan lama"
  di atas daftar pesan memuat 200 pesan sebelumnya; pesan yang sudah dimuat
  tidak hilang saat polling. Klik kutipan memuat riwayat lama secara bertahap
  (maksimum 5 halaman, sekitar 1000 pesan) sampai pesan asal ditemukan.
- Alasan: bug batas 500 memotong pesan terbaru; pagination menggantikan
  keputusan "tanpa pagination" setelah spike `vue-advanced-chat`
  (`docs/laporan-spike-vue-advanced-chat.md`).
- Perubahan tampilan yang menyertai (bukan aturan bisnis, dicatat agar tim
  tahu): pemisah tanggal (Hari ini / Kemarin / tanggal), kotak kutipan bisa
  diklik untuk meloncat ke pesan asal, format teks WhatsApp (`*tebal*`,
  `_miring_`, `~coret~`, kode) dan tautan `http(s)` yang bisa diklik pada isi
  pesan dan caption, centang pada pesan keluar terkirim dan tanda "!" pada
  yang gagal, serta tombol "gulung ke pesan terbaru" dengan jumlah pesan baru.
  Thread tidak lagi digambar ulang seluruhnya tiap 4 detik.
- Referensi: `docs/requirements/2026-10-02-perbaikan-thread-inbox.md` (AC-1..AC-31),
  `docs/design/2026-10-02-perbaikan-thread-inbox.md`,
  `tests/feature/InboxMessagesPaginationTest.php`, `tests/js/inbox-thread.test.js`

## 2026-10-02 — Inbox: nama conversation tidak lagi berubah saat staff balas dari WA Web/HP

- Aturan lama: endpoint webhook Gateway (`InboxGatewayApi::messages()`)
  menulis `conversations.whatsapp_name` untuk SEMUA pesan (incoming maupun
  outgoing) selama nilainya berbeda. Untuk pesan outgoing yang disinkronkan
  dari WhatsApp Web/HP (`fromMe=true`), `contact_name` di payload adalah push
  name STAFF yang membalas, sehingga nama percakapan berubah menjadi nama
  staff.
- Aturan baru: `whatsapp_name` hanya dimutakhirkan dari push name customer
  untuk pesan **incoming** (`direction='incoming'`). Pesan outgoing dari WA
  Web/HP tidak lagi menyentuh `whatsapp_name`, sehingga nama percakapan tetap
  nama customer.
- Alasan: nama percakapan harus merefleksikan identitas customer, bukan staff
  yang membalas dari luar POS (laporan bug dari tim, dokumen "Penjelasan
  masalah untuk tim 01").
- Referensi: `docs/sesi/2026-10-02-bug-nama-conversation-wa-web.md`,
  `tests/feature/InboxGatewayApiWhatsappNameTest.php`.

## 2026-10-02 — Standardisasi pemilih tanggal/rentang/periode

- Aturan lama: tiap halaman mendefinisikan locale, preset, mode terapkan, dan
  pemuatan aset date picker sendiri-sendiri; nama parameter rentang bercampur
  (`tanggal_mulai/sampai` vs `tanggal_awal/akhir`).
- Aturan baru: satu konfigurasi standar (`App\Config\DatePicker`) + helper
  `public/assets/js/date-range.js`; locale Indonesia penuh, 6 preset, mode
  "Terapkan", aset dipin (`moment@2.31.0`, `daterangepicker@3.1.0`) dimuat
  global dari layout. Parameter rentang diseragamkan ke `tanggal_awal`/
  `tanggal_akhir` (termasuk `item_harian` & `laporan_pembayaran`). Default
  rentang per konteks dipertahankan.
- Alasan: konsistensi UX dan menghapus duplikasi yang menyebabkan drift.
- Referensi: `docs/requirements/2026-10-02-standardisasi-pemilih-tanggal.md`,
  `docs/design/2026-10-02-standardisasi-pemilih-tanggal.md`,
  `docs/sesi/2026-10-02-standardisasi-pemilih-tanggal.md`.

## 2026-09-30 — Import CSV Maintenance produk: kolom kosong Aktif/Locked

- Aturan lama: kolom Aktif/Locked yang kosong di CSV dipaksa menjadi 0 saat
  baris sudah ada di database (mengoverwrite nilai locked yang sedang
  berlaku).
- Aturan baru: untuk baris yang sudah ada, kolom Aktif/Locked yang kosong
  mempertahankan nilai di database. INSERT baris baru tetap default
  aktif=1, locked=0. Aksi NONAKTIF eksplisit tetap memaksa aktif=0.
- Alasan: import CSV yang tidak mengisi kolom Locked secara tidak sengaja
  membuka kunci semua produk yang diproses (lihat docs/sesi/2026-09-30-modul-produk.md).
- Referensi: commit `fc099ea` (fix(produk): keep Aktif/Locked when their CSV
  columns are blank).

## 2026-09-30 — Inbox: status gateway `degraded` (sesi WA terhubung tapi rusak)

- Aturan lama: gateway WhatsApp hanya punya status terhubung/terputus; sesi
  yang rusak (socket `connected` tapi gagal mendekripsi semua pesan) tidak
  terdeteksi sehingga pesan hilang tanpa peringatan.
- Aturan baru: status gateway dapat bernilai `degraded` saat terhubung tetapi
  sesi bermasalah. Inbox POS menampilkan badge merah dan memblokir kirim
  pesan sampai sesi dipulihkan (scan QR ulang).
- Alasan: mencegah kejadian 2026-09-29/30 terulang tanpa terdeteksi.
- Referensi: commit `f26138b`, `6afb732`;
  docs/sesi/2026-09-30-wa-gateway-sesi-degraded.md.

