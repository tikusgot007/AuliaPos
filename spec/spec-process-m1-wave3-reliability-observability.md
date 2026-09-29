---
title: M1 Gelombang 3 — Keandalan Terverifikasi, Logging Terstruktur & Observabilitas WA-Gateway (Ticket 05, 12–15)
version: 1.1
date_created: 2026-09-29
last_updated: 2026-09-29
owner: WA-Gateway reliability (M1)
tags: [gateway, whatsapp, baileys, m1, reliability, observability, logging, metrics, health, test-matrix]
---

# Introduction

> [!WARNING] FROZEN 2026-09-29 — dibekukan, **bukan dihapus**.
> Spec ini beserta plan Wave 3 dihentikan sementara atas keputusan pemilik. Isinya dipertahankan utuh sebagai rujukan: kalau muncul masalah nyata di operasional, gejala itu dicocokkan dulu ke requirement/acceptance criteria di sini untuk menentukan ticket Wave 3 mana yang menanganinya. Tidak ada implementasi baru dari spec ini selama status frozen. Requirement yang butuh input luar (`OI-001` nomor uji, `OI-002` bentuk payload status kirim) **tidak perlu disediakan** selama beku.

> [!SUCCESS]
> **REMEDIATION STATUS: RESOLVED**
> This specification has been remediated by the Specification Architect against
> `docs/audit/clarification-report-m1-wave3-reliability-observability-2026-09-29.md`
> (Readiness Score at submission: 71/100). All 14 resolutions (CB-01..CB-14) from
> §1 of that report have been applied below and the document is bumped to **v1.1**.
>
> **Projected Readiness Score: 90/100**
>
> - **Completeness: 37/40** — `### E-W5` (REQ-068..071) is now written in §3 and
>   anchored to the previously orphaned AC-073..076; missing config knobs
>   (`HEALTH_INBOUND_STALE_MS`) and missing gauges (`group_name_cache_size`,
>   `event_buffer_size`) are added to §4.3/§4.7; heartbeat worker correctness gets
>   3 new REQ/AC (E-W2 extension). Remaining 3 points withheld because the exact
>   `messages.update` payload shape (OI-002) is deliberately deferred to
>   `/sdlc-plan-tasks`, not resolved here.
> - **Clarity: 28/30** — `send_ready`/`receive_ready`/`delivery_ready` now have
>   explicit formulas (§4.2); the true worst-case per-event time budget (~16000 ms)
>   is written out against `SHUTDOWN_DRAIN_MS` (REQ-049); the single-instance lock
>   now specifies PID+heartbeat takeover semantics (REQ-052). Remaining 2 points
>   withheld because the exact consecutive-probe-failure threshold `N` (REQ-063) is
>   still a range (2–3), finalized at `/sdlc-plan-tasks`.
> - **Alignment: 25/30** — orphaned ACs are resolved, OI-001 dependency is
>   propagated to AC-047/AC-051, ASSUMPTION-015 is rewritten to match all 8 E-W2
>   REQs plus the new heartbeat items, and the unsupported §10 claim about closing
>   ASSUMPTION-009 is removed. Remaining 5 points withheld because REQ-066/OI-002
>   introduces a new hard gate whose downstream plan impact is not yet audited by
>   `/sdlc-audit-consistency`.
> - **Critical Flaw Veto:** Cleared — REQ-052's naive-lock risk to GW-17/AC-048 and
>   the missing E-W5 block (M1 closure gate) are both resolved below.
>
> User selected **PROCEED** at the projected 90/100 score (per clarification report
> §"User Decision Prompt"). Per Handoff protocol, route to `/sdlc-plan-tasks` after
> this remediation (see §14).

Spesifikasi ini mendefinisikan **gelombang 3 dari M1 (Reliability)** dan menutup sisa Ticket M1 yang belum dispesifikasikan: **Ticket 05** (crash/restart test), **Ticket 12** (worker correctness), **Ticket 13** (structured logging), **Ticket 14** (metrics/health, GW-20 dan GW-21), dan **Ticket 15** (full reliability test matrix). Gelombang 1 menutup jalur pesan masuk (Ticket 02–04, AC-001..AC-018), dan gelombang 2 menutup idempotensi kirim keluar serta batas percobaan/dead-letter (Ticket 06–11, AC-019..AC-046). Gelombang 3 tidak mengubah jaminan kedua gelombang itu; ia **membuktikan, mengukur, dan membuat terlihat** apa yang sudah dibangun, lalu menjawab sisa risiko P0 #5/#7 (GW-11 dan GW-25) sebagai pengukuran terkontrol.

Dasar spec ini adalah `docs/GATEWAY-REQUIREMENTS.md` (GW-20, GW-21; rujukan GW-11, GW-19, GW-25), `docs/TODO-CHAT.md` (M1 Ticket 05 dan 12–15, risiko P0 #3/#4/#5, butir C3), dan hasil terukur `docs/decisions/2026-09-21-m1-ticket01-baseline.md` serta `docs/decisions/2026-09-21-m1-ticket02-audit-enqueue.md` (temuan E-08, E-12, dan bagian "Analisis lanjutan … error dekripsi"). Tidak ada PRD untuk M1.

## 1. Purpose & Scope

**Tujuan:** (a) membuktikan lewat pengukuran terkontrol bahwa pemulihan crash/restart gelombang 1 dan 2 benar-benar bekerja end-to-end, termasuk menguji hipotesis penyebab kegagalan dekripsi secara **terpisah** (H1 vs H2); (b) menetapkan dan menegakkan invariant kebenaran ketiga worker Gateway (`incomingDelivery`, `heartbeat`, `outgoingOperationService`); (c) menyeragamkan logging terstruktur agar jejak kegagalan bisa dibaca mesin; (d) menyediakan metrik dan kesehatan yang **jujur** — `connected` hanya bila Gateway benar-benar bisa kirim dan terima; (e) menyusun satu matriks uji keandalan penuh sebagai gerbang penutupan M1.

**Audiens:** `/sdlc-clarify-reqs`, `/sdlc-plan-tasks`, dan developer yang mengubah kode WA-Gateway.

**Dalam scope (semuanya kode di repo WA-Gateway, kecuali bila dinyatakan lain):**

- **E-W1 — Ticket 05: Crash/restart & uji pembeda dekripsi.** Protokol uji kill/restart terkontrol (SIGKILL dan graceful) yang memverifikasi pemulihan gelombang 1–2, plus **uji pembeda** antara H1 (sesi bercampur alamat `pn`/`lid`) dan H2 (kill saat mengenkripsi) memakai **nomor uji kedua** yang belum pernah dihubungi. Perlu instrumentasi diagnostik read-only (REQ-042). Ini ticket **pengukuran**, bukan perbaikan GW-11/GW-25.
- **E-W2 — Ticket 12: Worker correctness.** Invariant kebenaran `incomingDelivery`, `heartbeat`, dan `outgoingOperationService`: single-flight, isolasi error per event, anggaran waktu per event, urutan FIFO, drain saat shutdown, guard satu instance, batas sumber daya, serta invariant minimal worker `heartbeat` (anti-tumpang-tindih, isolasi error per siklus, batas waktu kirim).
- **E-W3 — Ticket 13: Structured logging.** Satu logger, satu skema field, katalog event, kebijakan level, dan larangan konten — tanpa menghilangkan teks log lama yang sudah dipakai runbook.
- **E-W4 — Ticket 14: Metrics & health (GW-20, GW-21).** Registri metrik in-process tanpa dependensi baru, model kesehatan yang bisa dipercaya (dengan probe reachability aktif), endpoint `GET /api/health` dan `GET /api/metrics`, serta perekaman kabar status pengiriman (`messages.update`) untuk GW-21 pada tingkat Gateway.
- **E-W5 — Ticket 15: Full reliability test matrix.** Matriks uji tunggal yang mencakup AC-001..AC-046 (gelombang 1–2) dan AC baru gelombang 3, plus harness, protokol ukur, dan kriteria keluar M1.

## 1.1 Out of Scope

- **Ticket 06, 07, 08, 09, 10, 11** (attempt counter, dead-letter, poison-message, operation ID, idempotency, ambiguous-send recovery) — sudah dispesifikasikan dan diimplementasikan di gelombang 2. Spec ini hanya **memverifikasi** hasilnya di Ticket 15, tidak mengubahnya.
- **Ticket 16 (merge)** — sudah selesai (`21a4cb6`, live `4a766d2`).
- **E-02** (pesan berbungkus ephemeral/view-once) dan **E-07** (upsert tanpa konten) — masih menunggu verifikasi WhatsApp nyata, sama seperti gelombang 1.
- **Akar penyebab GW-11/GW-25 dan perbaikannya.** Wave 3 hanya **mengukur dan membedakan hipotesis**; sumber `message_timestamp` yang bergeser berada di luar repo ini (ESC-001..004 tetap OPEN). Tidak boleh ada klaim "sudah diperbaiki".
- **AuliaPos.** Wave 3 tidak mengubah kode AuliaPos (lihat ASSUMPTION-012 tentang tampilan GW-21). Payload masuk `POST /api/inbox/gateway/messages` dan seluruh kontrak keluar `/send`, `/send-media`, `/media/download` **tidak berubah**.
- **M2 State Consistency** (`assigned_to`, ownership, state percakapan, `messages.update` sebagai penutup penuh jaminan idempotensi).
- **Rotasi/retensi berkas log** (lihat ASSUMPTION-019), versi Baileys, folder `auth/`, dan skema autentikasi Bearer.
- **Klaim "duplikat mustahil".** ASSUMPTION-009 gelombang 2 tetap berlaku dan MUST tetap ditulis jujur.

## 1.2 Open Questions & Assumptions

### ASSUMPTIONS I'M MAKING

Keputusan dan asumsi yang diambil saat menulis spec ini. Asumsi melanjutkan penomoran `ASSUMPTION-001..011` dari gelombang 2, dan keputusan yang diambil **di sini** memakai penomoran `D-xx` yang melanjutkan `D-01..D-13` gelombang 2 (yaitu **D-14**), supaya traceability lintas gelombang M1 tetap utuh:

> [!IMPORTANT] OPEN INPUT — OI-001 (BUKAN asumsi; butuh **dua** pemasok luar, D-14)
> **Instance uji terisolasi (akun Gateway) dan nomor kontak uji, keduanya belum pernah tercemar.** REQ-044/AC-049 (uji pembeda H1 vs H2) MUST memakai (a) **akun WhatsApp ketiga** sebagai Gateway uji, dengan sesi bersih (satu kali scan QR), bukan salinan folder `auth/` nomor aktif — lihat **D-14** di bawah — dan (b) nomor kontak uji yang belum pernah menerima `/send` maupun pesan dari Gateway mana pun. Kedua pemasok ini **tidak dapat diturunkan dari kode** dan MUST disediakan pemilik proyek sebelum AC-049 dijalankan. Bila salah satu belum tersedia, AC-049 tetap `BLOCKED — menunggu OI-001`, bukan dilewati senyap dan bukan diganti asumsi. Nomor Gateway produksi tetap `6281913500707` dan **tidak pernah** dipakai sebagai pengirim Arm H1 (lihat REQ-046); nomor tes lama `628563324637` **tidak** memenuhi syarat sebagai kontak uji karena riwayat sesinya sudah tercemar.

> [!IMPORTANT] OPEN INPUT — OI-002 (BUKAN asumsi; GERBANG KERAS sebelum REQ-066 diimplementasikan, dari CB-08/CB-09)
> **Verifikasi bentuk payload `messages.update` Baileys 6.7.24.** Sebelum REQ-066 (GW-21, handler `messages.update`) boleh diimplementasikan, `/sdlc-plan-tasks`/implementasi MUST lebih dulu memverifikasi bentuk payload nyata yang dipancarkan `sock.ev.on('messages.update')` pada Baileys 6.7.24, dan menulis tabel pemetaan eksplisit dari status numerik/enum yang diamati ke `receipt_state` (`pending`/`sent`/`delivered`/`read`/`failed`). Ini **bukan** sekadar catatan referensi di §11 EXT-001 seperti versi sebelumnya — ia adalah gerbang keras: REQ-066 MUST NOT dikunci/diimplementasikan sebelum OI-002 tertutup. Bila bentuk payload tidak sesuai dugaan, REQ-066/AC-071/§4.6 MUST direvisi sebelum kode ditulis, bukan ditambal setelahnya.

> [!NOTE] D-14 (instance uji Ticket 05, dari CB-06): **Akun WhatsApp ketiga, sesi bersih.** Instance Gateway uji yang dipakai Ticket 05 (REQ-046) MUST memakai **akun WhatsApp ketiga** dengan **sesi bersih** (`auth/` baru, satu kali scan QR), **bukan** salinan folder `auth/` nomor aktif mana pun. Karena itu **Arm H1 (REQ-044) MUST mengirim `/send` DARI nomor uji ini** ke alamat `pn` kontak uji (OI-001b), **bukan** dari nomor produksi `6281913500707`; bila H1 mengirim dari nomor produksi, reproduksi tidak sah dan hasilnya MUST NOT dicatat sebagai bukti. Pilihan yang ditolak: menyalin `auth/` nomor aktif (melanggar GW-18 dan REQ-046) dan menjalankan uji pada Gateway produksi (tidak terisolasi).

> [!WARNING] ASSUMPTION-012: **GW-21 (kabar status pengiriman eksplisit) dianggap bagian Ticket 14**, sesuai instruksi sesi ini, meskipun `docs/GATEWAY-REQUIREMENTS.md` menandainya `[ ] **GW-21** (Nanti, M2)`. Untuk menghindari scope creep ke M2, cakupannya **dibatasi ke sisi Gateway**: menangkap `messages.update`, mencatat status ke baris `outgoing_operations`, mengeksposnya di metrik/health dan respons replay `/send` (aditif). **Menampilkannya di AuliaPos tetap M2** dan di luar scope.
> *Risiko bila salah:* bila pemilik proyek menghendaki GW-21 murni M2, REQ-066, AC-071, dan kolom `receipt_*` dihapus; sisa Ticket 14 (metrik + health GW-20) tetap utuh.

> [!WARNING] ASSUMPTION-013: **Bentuk endpoint kesehatan.** Kesehatan lokal diekspos di `GET /api/health` (tanpa token, mengikuti pola `GET /api/status` yang juga tanpa token pada bind loopback), dengan isi blok `health` yang sama dengan yang dikirim di heartbeat. Heartbeat ke AuliaPos **tetap** memakai empat nilai `status` (`connected`/`connecting`/`disconnected`/`logged_out`) dan **tidak** menambah nilai enum baru, supaya AuliaPos lama tidak rusak; informasi degradasi masuk lewat field aditif `health.degraded_reasons[]`.
> *Risiko bila salah:* penamaan/path endpoint berubah — murah diperbaiki sebelum dirilis.

> [!WARNING] ASSUMPTION-014: **Metrik in-process, tanpa dependensi npm baru** (mengikuti CON-004/CON-008). Registri metrik adalah objek JavaScript dengan counter/gauge bernama; diekspos sebagai JSON di `GET /api/metrics` dan disertakan dalam heartbeat. Metrik **reset saat proses restart** (dicatat sebagai batasan); format Prometheus text hanya opsional dan bukan syarat.
> *Risiko bila salah:* bila pemilik proyek menginginkan Prometheus/Grafana, exporter terpisah diperlukan — itu pekerjaan baru di luar M1.

> [!WARNING] ASSUMPTION-015 (direvisi, CB-11): **Perbaikan worker dibatasi** pada seluruh **delapan** REQ E-W2: single-flight `incomingDelivery` (REQ-047), isolasi error per event (REQ-048), anggaran waktu per event (REQ-049), urutan FIFO/overflow-sebelum-due (REQ-050), drain saat shutdown (REQ-051), guard satu instance (REQ-052), batas sumber daya in-memory (REQ-053), dan observabilitas tick (REQ-054) — **plus** invariant kebenaran minimal worker `heartbeat` yang baru ditambahkan di revisi ini: guard anti-tumpang-tindih siklus (REQ-072), isolasi error per siklus (REQ-073), dan batas waktu kirim heartbeat (REQ-074). Klaim versi sebelumnya ("lima hal") salah karena E-W2 sejak awal memuat 8 REQ (047–054); worker `heartbeat` sendiri sebelumnya tidak punya satu pun invariant kebenaran meski disebut sebagai salah satu dari tiga worker di §2. **Menulis ulang arsitektur worker (mis. worker thread/antrean terpisah) tetap di luar scope.**
> *Risiko bila salah:* bila audit menemukan cacat kebenaran di luar kesebelas hal itu, temuan MUST dicatat sebagai backlog, bukan ditambal diam-diam.

> [!WARNING] ASSUMPTION-016: **Logging terstruktur bersifat aditif.** Teks log lama (mis. `[DELIVERY] pesan masuk berhasil diteruskan ke CI4`, awalan `[CRITICAL]`) MUST tetap dipertahankan karena runbook dan pencarian grep bergantung padanya; yang ditambahkan adalah field `event` (nama bertitik, bahasa Inggris) dan field korelasi. Event buffer dashboard (300 entri) diperluas agar menyimpan field terstruktur, bukan hanya `message`.
> *Risiko bila salah:* parser log lama rusak bila teks inti diubah — karena itu REQ-056 melarang penghapusan teks lama.

> [!WARNING] ASSUMPTION-017: **Instrumentasi Ticket 05 bersifat read-only dan bergerbang.** Diaktifkan hanya bila `WA_DIAG_RAW_MESSAGE=1` (bawaan `0`/mati). Ia MUST NOT mengubah jalur pemrosesan pesan, MUST NOT menulis isi pesan/nama kontak/`base64` ke log (SEC-001/SEC-003), dan MUST NOT menyentuh folder `auth/`. Tujuan: merekam alamat asal (`pn`/`lid`), `type` (`notify`/`append`), ada/tidaknya retry dekripsi, stempel waktu mentah dari Baileys, `messageStubType`, dan apakah ada `/send` ke alamat `pn` di sesi yang sama.
> *Risiko bila salah:* instrumentasi membebani jalur kritis — mitigasi: hanya field ringan, tanpa I/O sinkron tambahan.

> [!WARNING] ASSUMPTION-018 (diperluas, CB-02/CB-05/CB-13): **Ambang kesehatan (dapat diatur lewat env):** `HEALTH_PROBE_ENABLED=1` (**aktif di semua lingkungan termasuk produksi**), `HEALTH_PROBE_INTERVAL_MS=300000` (5 menit), `HEALTH_PROBE_TIMEOUT_MS=2000`, `HEALTH_PROBE_FRESH_MS=600000` (2× interval), `HEALTH_PROBE_FAILURES_THRESHOLD=2` (N kegagalan probe berturut-turut sebelum `status` diturunkan), `HEALTH_INBOUND_STALE_MS=600000` (jendela pesan masuk untuk `receive_ready`), `HEALTH_PENDING_WARN=100`, `HEALTH_OVERFLOW_WARN=50`, `HEALTH_DECRYPT_FAILURE_THRESHOLD=5` dalam `HEALTH_DECRYPT_WINDOW_MS=300000`. Nilai ini dipilih agar degradasi terlihat lebih dulu daripada kegagalan total, tanpa membuat health berfluktuasi pada toko sepi, dan tanpa menurunkan `status` pada satu kegagalan probe tunggal.
> *Risiko bila salah:* health terlalu sensitif (flapping) atau terlalu longgar; keduanya dapat diperbaiki lewat env tanpa mengubah kode.

> [!WARNING] ASSUMPTION-019: **Rotasi/retensi berkas log TIDAK termasuk Wave 3.** Berkas `logs/gateway.log` saat ini tumbuh tanpa batas. Wave 3 hanya **mendokumentasikan** batas ini di §11 dan merekomendasikan rotasi di tingkat OS/operasional. Implementasi rotasi (ukuran/usia) adalah pekerjaan terpisah karena menyentuh lifecycle proses, bukan observabilitas murni.
> *Risiko bila salah:* log tumbuh besar di operasi jangka panjang — risiko diterima dan dicatat jujur, bukan ditutup-tutupi.

> [!WARNING] ASSUMPTION-020 (diperjelas, CB-02): **Health "bisa terima" hanya bisa dibuktikan secara tidak langsung oleh satu proses.** Gateway tidak bisa mengirim pesan ke dirinya sendiri dengan andal untuk uji loopback, sehingga `receive_ready` dihitung dari (a) hasil probe reachability ke server WhatsApp, ATAU (b) pesan masuk yang benar-benar tersimpan dalam jendela `HEALTH_INBOUND_STALE_MS` (bawaan `600000` ms, §4.7). Bila **tidak ada satu pun bukti** (probe nonaktif/basi dan tidak ada pesan masuk dalam jendela), `receive_ready` bernilai **`unknown`**, bukan `false` — ketiadaan bukti bukan bukti ketiadaan. Rumus lengkap ketiga field kesiapan ada di REQ-062. Batas jujur ini MUST ditulis di decision log Ticket 14; `healthy` berarti "sesi valid dan server terjangkau", bukan "secara matematis mustahil kehilangan pesan".

> [!WARNING] ASSUMPTION-021: Spec ditulis dalam bahasa Indonesia, mengikuti `spec-process-m1-wave1-incoming-reliability.md` dan `spec-process-m1-wave2-outgoing-idempotency.md`. `AGENTS.md` menetapkan bahasa Inggris untuk dokumen SDLC; dua gelombang sebelumnya memakai bahasa Indonesia dengan asumsi yang sama. Ubah bila diminta.

## 2. Definitions

- **Worker:** loop berjadwal di dalam proses Gateway. Ada tiga: `incomingDelivery` (kirim pesan masuk ke AuliaPos), `heartbeat` (status ke AuliaPos), dan `outgoingOperationService` (pemulihan start-up operasi kirim keluar, bukan loop berkala).
- **Single-flight:** paling banyak satu batch pengiriman berjalan pada satu waktu; siklus yang tiba saat batch sebelumnya belum selesai dilewati (bukan diantrekan).
- **Tick:** satu siklus `incomingDelivery.tick()`.
- **Overflow buffer:** penampung sementara in-memory (gelombang 1) untuk event yang gagal disimpan; dikuras di awal tick.
- **Dead-letter:** keadaan terminal (gelombang 2). Antrean masuk: `incoming_queue.status='dead'`. Kirim keluar: `outgoing_operations.state='abandoned'`.
- **Kabar status pengiriman (receipt):** peristiwa WhatsApp yang menyatakan nasib pesan keluar (`sent`, `delivered`, `read`, `failed`) yang diterima lewat `messages.update` (GW-21).
- **Metrik:** angka bernama yang dipakai untuk mengukur perilaku runtime (counter naik-turun, gauge nilai saat ini).
- **Gauge:** metrik bernilai saat ini (mis. panjang antrean), bukan akumulasi.
- **Probe reachability:** panggilan ringan terjadwal ke server WhatsApp (mis. `onWhatsApp()` ke nomor Gateway sendiri) untuk membuktikan sesi masih valid dan server terjangkau.
- **Verdict kesehatan:** salah satu dari `healthy`, `degraded`, `unknown`.
- **Socket status (mentah) vs health debounce:** `socket_status` adalah keadaan socket apa adanya dari `connectionManager` (`connected`/`connecting`/`disconnected`/`logged_out`) tanpa pembalikan apa pun; `wa_status`/`status` yang dipakai heartbeat adalah verdict health yang **di-debounce** — ia baru diturunkan ke `disconnected` setelah `HEALTH_PROBE_FAILURES_THRESHOLD` kegagalan probe **berturut-turut** (CB-05, REQ-063).
- **`unknown` (kesiapan):** nilai `receive_ready` ketika tidak ada bukti sama sekali (probe nonaktif/basi dan tidak ada pesan masuk dalam `HEALTH_INBOUND_STALE_MS`). Berbeda dari `false` yang berarti bukti ada dan menunjukkan tidak siap (CB-02).
- **Reason enum drop:** himpunan tetap alasan sebuah event/tipe dilewati, dipakai di metrik dan log: `own_sent_append`, `append_non_customer_jid`, `upsert_non_notify_append`, `no_content`, `unsupported_wrapper`, `media_ref_incomplete`, `status_broadcast`, `enqueue_validation`, `overflow_full`, `generation_mismatch` (lanjutan E-08/E-12).
- **Event log (nama event):** nama bertitik stabil untuk satu peristiwa (mis. `delivery.event.completed`), berbeda dari teks manusia yang bisa berubah.
- **Correlation ID:** field yang mengaitkan baris log/metrik dengan satu pesan/operasi: `wa_message_id`, `operation_id`, `chat_id`.

## 3. Requirements, Constraints & Guidelines

Penomoran melanjutkan gelombang 2 (REQ-041, SEC-002, CON-010, GUD-004) supaya traceability lintas spec M1 tetap utuh.

### E-W1 — Ticket 05: Crash/restart & uji pembeda dekripsi

- **REQ-042**: Gateway MUST menyediakan instrumentasi diagnostik **read-only** untuk jalur pesan masuk, aktif hanya bila `WA_DIAG_RAW_MESSAGE=1`. Per pesan ia MUST merekam: `wa_message_id`, `type` (`notify`/`append`), alamat asal dan `jid_type` (`pn`/`lid`/`group`), ada/tidaknya `msg.message`, `messageStubType`, stempel waktu mentah Baileys, waktu tiba lokal, jumlah percobaan dekripsi/retry untuk ID itu, serta penanda apakah Gateway pernah `/send` ke alamat `pn` kontak itu pada sesi berjalan. Instrumentasi MUST NOT mengubah perilaku pemrosesan dan MUST NOT mencatat isi pesan atau nama kontak.
- **REQ-043**: Harness wajib mendukung protokol crash/restart terkontrol: (a) `SIGKILL` di awal/tengah/akhir burst, dan (b) restart graceful, masing-masing diulang ≥3 kali dengan jumlah pesan kirim yang dicatat eksplisit. Setelah restart, harness MUST memverifikasi: tidak ada pesan hilang di `incoming_queue`/AuliaPos, tidak ada duplikat (GW-24), pemulihan operasi `in_flight` kirim keluar, dan pemeriksaan/prune start-up berjalan.
- **REQ-044 (direvisi, CB-06/D-14)**: Harness MUST mendukung **uji pembeda H1 vs H2** memakai instance Gateway uji beserta kontak uji dari OI-001 (dua pemasok luar, lihat D-14):
  - **Arm H1:** `/send` **DARI instance/nomor uji Gateway (D-14)** ke alamat `pn` kontak uji B, lalu kontak uji B mengirim pesan masuk; ukur apakah muncul `SessionError: No matching sessions found for message` pada percobaan pertama. Arm H1 MUST NOT mengirim dari nomor produksi `6281913500707` — pengiriman dari nomor produksi membuat reproduksi tidak sah dan hasilnya MUST NOT dicatat sebagai bukti H1/H2.
  - **Arm H2:** kill paksa tepat saat Gateway (instance uji) sedang mengenkripsi kiriman keluar; ukur error dekripsi pada pesan berikutnya dari kedua arah.
  - Tiap arm MUST dijalankan pada sesi bersih untuk kontak itu, dengan jumlah data dan hasil dicatat. Kesimpulan MUST dinyatakan sebagai "H1 didukung/ditolak" atau "H2 didukung/ditolak" berdasarkan bukti, atau "belum konklusif" bila data tidak mencukupi — **tidak boleh** diklaim sebagai perbaikan.
- **REQ-045**: Setiap pengukuran crash/restart dan uji pembeda MUST merekam, per pesan, pasangan nilai `message_timestamp` (dari Baileys) dan waktu tiba/insert, sehingga bukti GW-11 (pergeseran urutan) menumpuk walau akar penyebab tetap tak terbukti.
- **REQ-046 (direvisi, CB-06/D-14)**: Semua uji Ticket 05 MUST terisolasi: worktree/instance Gateway terpisah, database sementara (bukan `data/gateway.sqlite` produksi), **instance uji MUST memakai akun WhatsApp ketiga dengan sesi bersih (`auth/` baru, satu kali scan QR) — bukan salinan folder `auth/` nomor aktif** (D-14), dan uji MUST NOT menghapus atau mengubah folder `auth/` nomor aktif. Uji pembeda memakai kontak uji dari OI-001 justru supaya sesi aktif tidak perlu disentuh.

### E-W2 — Ticket 12: Worker correctness

- **REQ-047**: `incomingDelivery` MUST tetap single-flight: paling banyak satu batch aktif; guard `isRunning` MUST selalu dilepas pada akhir siklus (termasuk saat error). Tick yang tiba saat guard aktif MUST dilewati tanpa mengantre.
- **REQ-048**: Error pada pemrosesan satu event (termasuk `JSON.parse` payload) MUST NOT menghentikan event lain dalam batch yang sama. Event yang gagal MUST ditandai lewat jalur `markFailedAttempt()` dan dicatat, lalu loop MUST lanjut ke event berikutnya.
- **REQ-049 (diperjelas, CB-14)**: Anggaran `DELIVERY_EVENT_TIMEOUT_MS` (bawaan mengikuti `CI4_REQUEST_TIMEOUT_MS`, 8000 ms) **hanya** membungkus panggilan AuliaPos `postToCI4()`, **bukan seluruh pemrosesan satu event**. Batas nyata terburuk satu event karena itu adalah **±16000 ms** (`LID 2000 + media 6000 + AuliaPos 8000`) dan angka itu MUST ditulis eksplisit (lihat juga AC-054 dan §12 Kasus 9). Batas per tahap yang sudah ada: query LID (2 dtk, gelombang 1) dan unduhan media (6 dtk, `mediaDownloadTimeoutMs` diperjelas 28 Sep) MUST dipertahankan. Perbandingan penting: `SHUTDOWN_DRAIN_MS` (5000 ms) **lebih pendek** dari batas terburuk ini, sehingga event yang belum selesai saat drain MUST tetap tersimpan dan dijadwalkan ulang untuk siklus berikutnya — **bukan** dianggap hilang dan **bukan** dipaksa selesai. Pelanggaran anggaran `postToCI4()` MUST dicatat dan event diperlakukan sebagai gagal sementara.
- **REQ-050**: Dalam satu batch, event MUST diproses berurutan menurut `id ASC` (FIFO). Overflow MUST dikuras **sebelum** pengambilan event jatuh tempo, tanpa menahan batch kirim ke AuliaPos. Worker MUST NOT memperkenalkan pengurutan ulang baru; pengurutan ulang akibat sumber `message_timestamp` (GW-11) tetap di luar scope dan MUST dicatat apa adanya.
- **REQ-051**: `incomingDelivery.stop()` MUST mencegah tick baru **dan** menunggu batch yang sedang berjalan selesai dalam batas `SHUTDOWN_DRAIN_MS` (bawaan 5000 ms). `app/index.js` `shutdown()` MUST menunggu drain ini sebelum menutup proses. Event yang belum `completed` MUST tetap tersimpan (terjadwal ulang), sehingga tidak ada status setengah-jadi yang menghilangkan data.
- **REQ-052 (direvisi, CB-04)**: Gateway MUST memakai guard satu instance berbasis kunci eksklusif pada path database (lock file) saat start. Kunci MUST berisi **PID pemilik**, **timestamp start**, dan MUST diperbarui lewat **heartbeat berkala** (mis. setiap beberapa detik) selama proses hidup. Saat start, bila kunci sudah ada, Gateway MUST memeriksa: (a) apakah PID pemilik masih hidup **langsung ke OS** (mis. `process.kill(pid, 0)` di POSIX, pemeriksaan proses setara di Windows), dan (b) apakah heartbeat kunci sudah basi melebihi ambang toleransi. Bila PID pemilik sudah mati **atau** heartbeat basi, Gateway MUST **mengambil alih kunci secara otomatis** dan melanjutkan start (bukan menolak). Hanya bila PID pemilik masih hidup dan heartbeat masih segar, proses kedua MUST menolak start dengan pesan jelas dan keluar non-nol. Ini menegakkan GW-18, mencegah dua worker berebut antrean, **dan** memastikan crash (`SIGKILL`) tidak meninggalkan lock basi yang menghalangi pemulihan otomatis GW-17/AC-048. Guard MUST tidak menambah dependensi npm baru dan MUST bekerja identik di Windows maupun fallback JSON (Android).
- **REQ-053 (diperjelas, CB-03)**: Semua struktur in-memory MUST terbatas dan batasnya MUST terlihat di metrik: overflow (`ENQUEUE_OVERFLOW_MAX`), daftar ID kiriman sendiri (`OWN_SENT_MAX`), **cache nama grup** (`GROUP_NAME_CACHE_MAX_ENTRIES`, gauge `group_name_cache_size`), dan **event buffer dashboard** (300, gauge `event_buffer_size`). Worker MUST NOT membuat struktur yang tumbuh tanpa batas.
- **REQ-054**: Setiap tick MUST mencatat jumlah event diproses/berhasil/gagal dan durasinya, dan error tak terduga di dalam tick MUST tetap tertangkap serta tercatat (proses MUST NOT mati hanya karena satu tick gagal).
- **REQ-072 (baru, CB-11 — invariant `heartbeat`)**: Worker `heartbeat` MUST tetap single-flight: paling banyak satu siklus kirim heartbeat aktif pada satu waktu; guard `isRunning`-nya sendiri MUST selalu dilepas pada akhir siklus (termasuk saat error), dan siklus yang tiba saat guard aktif MUST dilewati tanpa mengantre — pola yang sama dengan REQ-047 untuk `incomingDelivery`.
- **REQ-073 (baru, CB-11 — invariant `heartbeat`)**: Error pada satu siklus kirim heartbeat (mis. `POST /api/inbox/gateway/status` gagal/timeout) MUST NOT menghentikan penjadwalan siklus berikutnya; error MUST tertangkap, dicatat, dan proses MUST NOT mati hanya karena satu siklus heartbeat gagal — pola yang sama dengan REQ-048/REQ-054.
- **REQ-074 (baru, CB-11 — invariant `heartbeat`)**: Satu percobaan kirim heartbeat MUST tunduk pada anggaran waktu (`HEARTBEAT_SEND_TIMEOUT_MS`, bawaan mengikuti `CI4_REQUEST_TIMEOUT_MS`, 8000 ms); worker MUST NOT menunggu panggilan `POST /api/inbox/gateway/status` tanpa batas. Pelanggaran anggaran MUST dicatat dan siklus itu diperlakukan sebagai gagal sementara, tanpa menghentikan jadwal siklus berikutnya (REQ-073).

### E-W3 — Ticket 13: Structured logging

- **REQ-055**: Seluruh modul `src/` MUST mencatat lewat satu logger (`src/logging/index.js`). Pemakaian `console.*` langsung hanya diizinkan pada jalur fatal start-up. Output berkas MUST berupa satu baris JSON per entri (pino), sedangkan event buffer dashboard tetap disediakan untuk operator.
- **REQ-056**: Setiap entri log penting MUST memuat `event` (nama bertitik stabil, bahasa Inggris) dan `component`; entri yang menyangkut pesan/operasi MUST memuat correlation ID yang relevan (`wa_message_id`, `operation_id`, `chat_id`). **Teks log lama MUST dipertahankan** (aditif); dilarang menghapus atau memparafrase teks yang sudah dipakai runbook/grep. Katalog event minimum ada di §4.5.
- **REQ-057**: Kebijakan level MUST: `debug` untuk event frekuensi tinggi dan noise Baileys yang sudah dikenali; `info` untuk transisi status dan keberhasilan menyimpan/mengirim; `warn` untuk kegagalan yang dapat dicoba ulang atau event yang sengaja dilewati; `error` untuk apa pun yang berisiko kehilangan data. Keadaan terminal MUST menulis awalan literal `[CRITICAL]` **dan** field `severity:'critical'` (keduanya, agar parser lama dan baru sama-sama bekerja).
- **REQ-058**: SEC-001 diperluas: log MUST NOT memuat isi pesan pelanggan, teks balasan kasir, string `media_base64`, token, atau kredensial. Daftar redaksi (`REDACT_PATHS`) MUST mencakup header `Authorization`, objek kredensial, dan field payload sensitif. Log MUST aman dibagikan ke pihak ketiga tanpa penyuntingan.
- **REQ-059**: Event buffer dashboard MUST menyimpan entri terstruktur (minimal `time`, `level`, `event`, `message`, dan correlation ID yang ada), bukan hanya `message`, dan tetap terbatas 300 entri.
- **REQ-060**: Kejadian "dilewati" yang sebelumnya hanya tercatat di log (E-08, E-12: `status@broadcast`, alamat non-pelanggan, referensi media tidak lengkap, upsert tanpa konten, tipe tak didukung) MUST menaikkan counter metrik per alasan (§4.3), supaya jumlahnya terukur, bukan hanya terlihat.

### E-W4 — Ticket 14: Metrics & health (GW-20, GW-21)

- **REQ-061**: Gateway MUST memiliki registri metrik in-process dengan counter/gauge bernama (§4.3) yang mencakup **counter per alasan drop (reason enum §2)** dan panjang antrean. Registri MUST diekspos sebagai JSON di `GET /api/metrics` dan blok ringkasnya disertakan dalam heartbeat. Tidak ada dependensi npm baru.
- **REQ-062 (diperjelas, CB-02 — rumus eksplisit)**: Gateway MUST menghitung model kesehatan dengan field minimum: `verdict` (`healthy`/`degraded`/`unknown`), `wa_status`, `send_ready`, `receive_ready`, `delivery_ready`, `degraded_reasons[]`, `last_inbound_at`, `last_outbound_at`, `last_delivery_ok_at`, `last_probe_ok_at`, dan gauge antrean (§4.2). Ketiga field kesiapan MUST dihitung persis dengan rumus berikut:
  - **`send_ready`** = socket hidup (`socket_status='connected'`) **AND** probe terakhir sukses dan segar (`last_probe_ok_at` dalam `HEALTH_PROBE_FRESH_MS`).
  - **`receive_ready`** = probe segar (sama seperti di atas) **OR** ada pesan masuk yang tersimpan dalam jendela `HEALTH_INBOUND_STALE_MS` (baru, §4.7, bawaan `600000` ms). Bila **tidak ada bukti sama sekali** (probe nonaktif/basi dan tidak ada pesan masuk dalam jendela), nilainya **`unknown`**, bukan `false` (ASSUMPTION-020).
  - **`delivery_ready`** = `last_delivery_ok_at` segar dalam `HEALTH_PROBE_FRESH_MS` **AND** gauge `pending`/`overflow` berada di bawah ambang warn (`HEALTH_PENDING_WARN`/`HEALTH_OVERFLOW_WARN`).
  `verdict` MUST `degraded` bila ada `degraded_reasons` non-kosong, `unknown` bila bukti tidak cukup dan probe nonaktif, selain itu `healthy`.
- **REQ-063 (direvisi, CB-05 — debounce + `socket_status`)**: Gateway MUST menjalankan **probe reachability** terjadwal (`HEALTH_PROBE_INTERVAL_MS`, bawaan 5 menit; timeout `HEALTH_PROBE_TIMEOUT_MS`) yang membuktikan sesi masih valid dan server WhatsApp terjangkau. Gateway MUST mengekspos field baru **`socket_status`** (§4.2) yang selalu mencerminkan state socket mentah dari `connectionManager` (`connected`/`connecting`/`disconnected`/`logged_out`) tanpa debounce apa pun. Heartbeat MUST mengirim `status='connected'` **hanya** bila socket terhubung **dan** probe terakhir sukses dalam `HEALTH_PROBE_FRESH_MS`. `status` (verdict health yang di-debounce, dipakai heartbeat/AuliaPos) MUST diturunkan ke `disconnected` **hanya setelah `HEALTH_PROBE_FAILURES_THRESHOLD` kegagalan/kebasian probe berturut-turut** (bawaan `2`, §4.7) — **bukan** pada kegagalan probe pertama, supaya jendela "offline palsu" yang bisa memblokir tombol kirim kasir di AuliaPos tidak muncul dari satu probe gagal yang kebetulan. Saat `status` diturunkan, `health.degraded_reasons[]` MUST menyebut alasannya. Bila probe dinonaktifkan, `verdict` MUST `unknown` sampai ada bukti pesan masuk/keluar.
- **REQ-064**: Perubahan payload heartbeat MUST aditif. `status`, `phone`, dan `gateway_version` MUST tetap ada dengan arti lama; blok `health` adalah tambahan. AuliaPos lama yang hanya membaca `status` MUST tetap bekerja.
- **REQ-065**: Gateway MUST menyediakan `GET /api/health` yang mengembalikan `verdict`, `send_ready`, `receive_ready`, `delivery_ready`, `wa_status`, `socket_status`, `degraded_reasons[]`, stempel waktu terakhir, dan gauge antrean — isi blok `health` yang sama dengan heartbeat.
- **REQ-066 (GW-21, direvisi CB-09)**: Gateway MUST mendaftarkan handler `messages.update` untuk menangkap kabar status pengiriman, mengaitkannya ke baris `outgoing_operations` lewat `wa_message_id`, dan menyimpan `receipt_state` (`pending`/`sent`/`delivered`/`read`/`failed`) beserta `receipt_updated_at` dan nilai mentah yang diterima. **Ini semata pencatat (Gateway-only, `receipt` record-only): ia TIDAK menutup ASSUMPTION-009 dan TIDAK menyelesaikan operasi `in_flight` yang ambigu — tidak ada konsumen receipt di Wave 3.** Status MUST hanya mencerminkan bukti yang benar-benar diamati; bila bentuk payload tidak dikenali, Gateway MUST mencatatnya (`warn`) dan MUST NOT mengarang status — termasuk bila pemetaan status numerik Baileys ke `receipt_state` belum terverifikasi. **Pemetaan status numerik Baileys 6.7.24 ke `receipt_state` merupakan OI-002 (§1.2), gerbang keras yang MUST ditutup sebelum REQ-066 diimplementasikan.** Respons replay `/send` dan `/send-media` MUST menambahkan field aditif `receipt_state` bila tersedia. Baris operasi tanpa kabar tetap `pending`, bukan dianggap `failed`.
- **REQ-067**: Metrik dan health MUST NOT memuat isi pesan maupun label berkardinalitas tak terbatas (mis. `wa_message_id`/`operation_id` dilarang sebagai label metrik). Correlation ID hanya boleh muncul di log, bukan di label metrik.

### E-W5 — Ticket 15: Full reliability test matrix & kriteria keluar M1

- **REQ-068 (baru, CB-01)**: Proyek MUST menyediakan satu **matriks uji keandalan penuh** (artefak Ticket 15) yang memetakan **setiap** AC-001..AC-079 ke: REQ sumber, level (unit/integrasi/nyata), otomatis atau prosedur tertulis, bukti yang dihasilkan, dan ambang lulus. Matriks ini menggabungkan AC gelombang 1–2 (AC-001..AC-046) dan AC gelombang 3 (AC-047..AC-079) dalam satu tempat. Dispesifikasikan di sini karena sebelumnya AC-073..AC-076 **yatim** (menunjuk REQ-068..071 yang tidak pernah ditulis).
- **REQ-069 (baru, CB-01)**: Harness Ticket 15 MUST menjalankan seluruh skrip matriks secara berurutan dan MUST keluar non-nol bila ada satu skrip gagal. Harness MUST NOT menyentuh `data/gateway.sqlite` produksi maupun folder `auth/`.
- **REQ-070 (baru, CB-01 + CB-10 — kriteria keluar M1)**: Kriteria keluar M1 berupa **GERBANG LUNAK**: M1 boleh ditutup untuk **semua AC yang dapat dijalankan sekarang**; AC yang tidak dapat dijalankan (AC-049, dan bagian yang bergantung OI-001 dari AC-047/AC-051) MUST dicatat sebagai **`OPEN CARRY-OVER` eksplisit** beserta pemilik aksi (pemilik proyek yang harus menyediakan OI-001), dan **TIDAK dihitung lulus**. Ticket 15/laporan matriks MUST memuat bagian **"carry-over terbuka"** yang mendaftar item itu. Tidak ada klaim "M1 selesai penuh" selama carry-over terbuka.
- **REQ-071 (baru, CB-01)**: Setiap pengukuran real-run Ticket 15 MUST menghasilkan **decision log tertulis** yang memuat angka mentah, kondisi pengukuran, batas bukti, dan pernyataan eksplisit bahwa GW-11/GW-25/GW-21 (dan ASSUMPTION-009) **tidak** diklaim tertutup penuh. Sebagai bagian anti-data-loss: matriks boleh memakai ulang bukti real-run lama **HANYA JIKA** commit tempat bukti itu dihasilkan adalah **leluhur HEAD final**; bila tidak (mis. HEAD sudah bergerak melampaui commit bukti), protokol real-run MUST dijalankan ulang, bukan memakai ulang bukti basi.

### Security & operasional

- **SEC-003**: Instrumentasi diagnostik (REQ-042), log (REQ-058), metrik (REQ-067), dan endpoint `GET /api/health`/`GET /api/metrics` MUST NOT membocorkan isi pesan, kredensial, token, nomor lengkap pelanggan di luar `phone` yang memang sudah dikirim ke AuliaPos, atau struktur `auth/`.
- **SEC-004**: `GET /api/metrics` dan `GET /api/health` MUST mengikuti kebijakan eksposur yang sama dengan `GET /api/status` (loopback tanpa token). Bila HOST dibind ke LAN, Gateway MUST memperingatkan (perilaku `isBoundToLan` yang sudah ada) dan MUST NOT menambah data sensitif ke respons.
- **SEC-005**: Handler `messages.update` MUST memperlakukan payload sebagai data tidak tepercaya: hanya membaca field yang dikenal, memvalidasi bentuk, dan tidak mengeksekusi apa pun dari isinya.

### Batasan

- **CON-011**: Payload `POST /api/inbox/gateway/messages` (Gateway → AuliaPos) MUST tidak berubah, termasuk seluruh field dan artinya. Ini melanjutkan CON-001/CON-005.
- **CON-012**: Perubahan skema `incoming_queue` dan `outgoing_operations` MUST hanya menambah kolom (pola `ALTER TABLE ... ADD COLUMN` yang sudah ada) dan MUST kompatibel dengan database SQLite yang sudah berisi data.
- **CON-013**: Kontrak respons `/send`, `/send-media`, dan `/media/download` MUST tetap kompatibel; `receipt_state` dan field observabilitas lain bersifat aditif.
- **CON-014**: Tidak ada dependensi npm baru; folder `auth/` dan versi Baileys (6.7.24) MUST tidak disentuh.
- **CON-015**: Wave 3 MUST NOT mengubah kode AuliaPos. Bila integrasi AuliaPos diperlukan (mis. menampilkan health atau receipt), itu pekerjaan M2 terpisah.
- **CON-016**: Gateway tetap tidak menyimpan state bisnis atau berkas media. `outgoing_operations` tetap buffer kendali operasi, bukan riwayat percakapan.

### Panduan

- **GUD-005**: Semua ambang baru (`WA_DIAG_RAW_MESSAGE`, `SHUTDOWN_DRAIN_MS`, `DELIVERY_EVENT_TIMEOUT_MS`, `HEARTBEAT_SEND_TIMEOUT_MS`, `HEALTH_PROBE_*`, `HEALTH_PROBE_FAILURES_THRESHOLD`, `HEALTH_INBOUND_STALE_MS`, `HEALTH_PENDING_WARN`, `HEALTH_OVERFLOW_WARN`, `HEALTH_DECRYPT_FAILURE_THRESHOLD`, `HEALTH_DECRYPT_WINDOW_MS`, `METRICS_ENABLED`) SHOULD dapat diatur lewat variabel lingkungan dengan nilai bawaan di spec ini; nilai tidak valid MUST memakai bawaan utuh (pola `toInt`/`toIntList`).
- **GUD-006**: Output observabilitas (log/metrik/health) SHOULD cukup untuk mengukur sendiri keberhasilan AC Wave 3 tanpa alat tambahan.
- **GUD-007**: Setiap penurunan kesehatan SHOULD menyertakan alasan yang dapat ditindaklanjuti operator (bukan sekadar `error`), mis. `probe_failed`, `delivery_failing`, `overflow_high`, `pending_high`, `decrypt_failures_high`.

## 4. Interfaces & Data Contracts

Semua antarmuka Gateway bersifat internal atau aditif. `POST /api/inbox/gateway/messages` dan kontrak kirim/media tidak berubah.

### 4.1 Endpoint observabilitas (baru)

| Endpoint | Metode | Auth | Isi |
|---|---|---|---|
| `/api/health` | GET | mengikuti `/api/status` (loopback) | Snapshot `health` (§4.2) |
| `/api/metrics` | GET | mengikuti `/api/status` (loopback) | Semua counter/gauge (§4.3) sebagai JSON |

### 4.2 Snapshot health

```json
{
  "verdict": "healthy",
  "wa_status": "connected",
  "socket_status": "connected",
  "send_ready": true,
  "receive_ready": true,
  "delivery_ready": true,
  "degraded_reasons": [],
  "last_inbound_at": "2026-09-29T04:10:00.000Z",
  "last_outbound_at": "2026-09-29T04:09:00.000Z",
  "last_delivery_ok_at": "2026-09-29T04:10:00.000Z",
  "last_probe_ok_at": "2026-09-29T04:05:00.000Z",
  "pending": 0,
  "dead": 0,
  "in_flight": 0,
  "abandoned": 0,
  "overflow": 0,
  "decrypt_failures_recent": 0,
  "generated_at": "2026-09-29T04:10:05.000Z"
}
```

Field `degraded_reasons` memakai enum alasan dari GUD-007. `verdict` dihitung dari: socket, kesegaran probe, gauge antrean melewati `HEALTH_*_WARN`, keberhasilan delivery terakhir, dan `decrypt_failures_recent` melewati ambang. `wa_status` adalah verdict health yang di-debounce (turun ke `disconnected` setelah `HEALTH_PROBE_FAILURES_THRESHOLD` kegagalan probe berturut-turut, REQ-063); `socket_status` adalah state socket mentah tanpa debounce (§2, REQ-063). Rumus lengkap `send_ready`/`receive_ready`/`delivery_ready` ada di REQ-062.

### 4.3 Registri metrik minimum

| Nama | Jenis | Label | Arti |
|---|---|---|---|
| `incoming_enqueued_total` | counter | — | Event masuk tersimpan |
| `incoming_duplicate_total` | counter | — | Duplikat sah (idempoten) |
| `incoming_dropped_total` | counter | `reason` (enum §2) | Event/tipe dilewati, per alasan (E-08/E-12) |
| `incoming_enqueue_retry_total` | counter | — | Percobaan ulang `enqueue()` |
| `overflow_dropped_total` | counter | — | Event dibuang karena overflow penuh |
| `delivery_attempt_total` | counter | — | Percobaan kirim ke AuliaPos |
| `delivery_failed_total` | counter | — | Percobaan gagal (dapat dicoba ulang) |
| `delivery_dead_total` | counter | `reason` (`max_attempts`/`max_age`/`permanent_rejection`) | Event masuk dead-letter |
| `tick_total` / `tick_error_total` | counter | — | Siklus worker / siklus gagal |
| `outgoing_send_total` | counter | `kind`,`outcome` | Hasil kirim keluar |
| `outgoing_replay_total` | counter | — | Permintaan dijawab dari catatan |
| `outgoing_abandoned_total` | counter | — | Operasi kirim keluar dead-letter |
| `outgoing_receipt_total` | counter | `state` (GW-21) | Kabar status pengiriman diterima |
| `media_download_total` | counter | `outcome` (`200`/`410`/`503`/`504`) | Hasil `/media/download` |
| `decrypt_failure_total` | counter | — | Error dekripsi (GW-25); observabilitas saja |
| `health_probe_total` | counter | `outcome` | Hasil probe reachability |
| `incoming_pending` | gauge | — | Baris `pending`+`failed` |
| `incoming_dead` | gauge | — | Baris `dead` |
| `outgoing_in_flight` | gauge | — | Operasi `in_flight` |
| `outgoing_abandoned` | gauge | — | Operasi `abandoned` |
| `overflow_size` | gauge | — | Isi penampung sementara |
| `group_name_cache_size` | gauge | — | Entri cache nama grup (batas `GROUP_NAME_CACHE_MAX_ENTRIES`), CB-03 |
| `event_buffer_size` | gauge | — | Entri event buffer dashboard (batas 300), CB-03 |

Nama metrik dan label memakai bahasa Inggris (snake_case) mengikuti konvensi kolom/tabel yang ada. Tidak ada label berkardinalitas tinggi (REQ-067).

### 4.4 Skema log terstruktur (aditif pada pino)

| Field | Wajib | Arti |
|---|---|---|
| `level` | ya | Sesuai pino |
| `time` | ya | ISO-8601 |
| `event` | ya (entri penting) | Nama event bertitik (§4.5) |
| `component` | ya (entri penting) | `connectionManager`, `incomingDelivery`, `outgoingOperationService`, `heartbeat`, `api`, … |
| `wa_message_id` / `operation_id` / `chat_id` | bila relevan | Correlation ID |
| `severity` | bila `[CRITICAL]` | `'critical'` |
| `msg` | ya | Teks manusia lama (MUST dipertahankan, REQ-056) |

### 4.5 Katalog event minimum

`gateway.start`, `gateway.shutdown`, `wa.status.changed`, `wa.message.persisted`, `wa.message.dropped` (label `reason`), `wa.decrypt.failure`, `delivery.event.completed`, `delivery.event.failed`, `delivery.event.dead`, `delivery.overflow.drained`, `delivery.dead.burst`, `send.operation.begin`, `send.operation.resolved`, `send.operation.unresolved`, `send.operation.abandoned`, `send.receipt.received` (GW-21), `media.download.failed`, `worker.tick`, `worker.shutdown.drain`, `health.probe`, `health.degraded`.

Nama event bersifat stabil; teks `msg` boleh berubah. Menambah event diperbolehkan; menghapus/mengganti nama event yang sudah dipakai adalah perubahan yang MUST dibahas (bukan diam-diam).

### 4.6 Penambahan kolom `outgoing_operations` (GW-21)

```sql
ALTER TABLE outgoing_operations ADD COLUMN receipt_state TEXT;
ALTER TABLE outgoing_operations ADD COLUMN receipt_updated_at TEXT;
ALTER TABLE outgoing_operations ADD COLUMN receipt_raw TEXT;
```

Nilai `receipt_state` yang sah: `pending` (belum ada kabar — bawaan), `sent`, `delivered`, `read`, `failed`. Kolom ditambahkan lewat pola migrasi ringan yang sudah ada (`PRAGMA table_info` + `ALTER TABLE`), aman dijalankan berkali-kali. `receipt_raw` menyimpan bentuk mentah yang diterima untuk audit pembelajaran, **tanpa isi pesan**.

> [!IMPORTANT] OI-002 — gerbang keras §4.6 (CB-08/CB-09): bentuk pasti payload `messages.update` Baileys 6.7.24 **dan** tabel pemetaan `update.status` numerik → `receipt_state` MUST diverifikasi lebih dulu (lihat §1.2 OI-002) sebelum handler REQ-066 diimplementasikan. Tabel `receipt_state` di atas adalah **enum target**, bukan pemetaan numerik Baileys; pemetaan itu ditetapkan saat OI-002 ditutup.

### 4.7 Konfigurasi baru (variabel lingkungan)

| Nama | Bawaan | Arti |
|---|---|---|
| `WA_DIAG_RAW_MESSAGE` | `0` | Instrumentasi diagnostik Ticket 05 (read-only) |
| `SHUTDOWN_DRAIN_MS` | `5000` | Batas tunggu batch berjalan saat shutdown |
| `DELIVERY_EVENT_TIMEOUT_MS` | `8000` | Anggaran waktu **hanya** panggilan `postToCI4()` (REQ-049); batas nyata terburuk satu event ±16000 ms |
| `HEARTBEAT_SEND_TIMEOUT_MS` | `8000` | Anggaran waktu satu percobaan kirim heartbeat (REQ-074, CB-11) |
| `METRICS_ENABLED` | `1` | Mengaktifkan registri metrik |
| `HEALTH_PROBE_ENABLED` | `1` | Mengaktifkan probe reachability, **aktif di semua lingkungan termasuk produksi** (CB-13) |
| `HEALTH_PROBE_INTERVAL_MS` | `300000` | Jeda antar probe |
| `HEALTH_PROBE_TIMEOUT_MS` | `2000` | Batas waktu satu probe |
| `HEALTH_PROBE_FRESH_MS` | `600000` | Usia maksimum hasil probe agar dianggap segar |
| `HEALTH_PROBE_FAILURES_THRESHOLD` | `2` | Jumlah kegagalan probe **berturut-turut** sebelum `status` diturunkan ke `disconnected` (REQ-063, CB-05) |
| `HEALTH_INBOUND_STALE_MS` | `600000` | Jendela pesan masuk untuk `receive_ready` bila probe tidak segar (REQ-062, CB-02) |
| `HEALTH_PENDING_WARN` | `100` | Ambang gauge `pending` untuk degradasi |
| `HEALTH_OVERFLOW_WARN` | `50` | Ambang gauge `overflow` untuk degradasi |
| `HEALTH_DECRYPT_FAILURE_THRESHOLD` | `5` | Ambang error dekripsi dalam jendela |
| `HEALTH_DECRYPT_WINDOW_MS` | `300000` | Jendela hitung error dekripsi |
| `LOG_LEVEL` | `info` | Level log bawaan pino (CB-12) — sudah terverifikasi ke kode `src/config/index.js`, dicatat di sini untuk kelengkapan §4.7, bukan perubahan perilaku |

Semua nilai angka MUST di-clamp minimum 1 (kecuali nilai boolean yang hanya `0`/bukan-`0`), mengikuti pola `Math.max(...)` yang sudah dipakai.

## 5. Acceptance Criteria

- **AC-047 (REQ-042, direvisi CB-07)**: Given `WA_DIAG_RAW_MESSAGE=0`, When pesan masuk diproses, Then tidak ada field diagnostik mentah yang ditulis (**bagian ini testable sekarang**). Given `WA_DIAG_RAW_MESSAGE=1` dan pesan masuk beralamat `pn` yang gagal didekripsi lalu berhasil di retry, Then log memuat `type`, `jid_type`, `messageStubType`, stempel waktu mentah, dan penanda retry **tanpa isi pesan/nama kontak** (**bagian ini bergantung pada sesi/nomor uji OI-001/D-14 → BLOCKED-OI-001**, karena memerlukan pesan `pn` nyata dari kontak uji yang belum pernah dihubungi). Bagian OI-001-dependent ini adalah **OPEN CARRY-OVER** (REQ-070) selama belum terpenuhi, dan MUST NOT diklaim lulus.
- **AC-048 (REQ-043)**: Given protocol crash/restart dijalankan ≥3 kali (SIGKILL variatif + graceful), When Gateway pulih, Then 0 pesan hilang, 0 duplikat, operasi `in_flight` yang basi tercatat, dan prune terminal berjalan.
- **AC-049 (REQ-044) — BLOCKED menunggu OI-001**: Given instance uji Gateway (akun ketiga, sesi bersih, D-14) dan kontak uji tersedia, When Arm H1 dan Arm H2 dijalankan (Arm H1 mengirim `/send` DARI instance uji, bukan dari `6281913500707`), Then hasil per arm dicatat dengan jumlah data dan bukti, dan kesimpulan H1/H2 dinyatakan "didukung/ditolak/belum konklusif". Selama OI-001 belum terpenuhi (kedua pemasok), AC-049 MUST berstatus `BLOCKED`, bukan dilewati atau diganti asumsi. Bagian OI-001-dependent ini adalah **OPEN CARRY-OVER** untuk kriteria keluar M1 (REQ-070) selama belum terpenuhi.
- **AC-050 (REQ-045)**: Given burst pesan dengan error dekripsi, When pengukuran selesai, Then setiap pesan punya pasangan `message_timestamp` (Baileys) dan waktu insert, dan pergeseran urutan (bila ada) terlacak tanpa klaim perbaikan.
- **AC-051 (REQ-046, direvisi CB-07)**: Given suite Ticket 05 dijalankan, When diff dan berkas diperiksa, Then tidak ada perubahan pada `data/gateway.sqlite` produksi, tidak ada perubahan `auth/` nomor aktif, dan uji pembeda memakai instance/kontak uji OI-001 (D-14). **Bagian yang bergantung pada OI-001 (mis. verifikasi memakai instance/kontak uji nyata) berstatus BLOCKED-OI-001, konsisten dengan AC-049**; bagian yang tidak membutuhkan OI-001 (mis. pemeriksaan diff `data/gateway.sqlite` dan `auth/` pada instance uji yang sudah ada) tetap testable sekarang.
- **AC-052 (REQ-047)**: Given satu batch lambat (disimulasikan), When tick kedua tiba, Then tick kedua dilewati dan guard sudah terlepas di akhir siklus (tick ketiga berjalan normal).
- **AC-053 (REQ-048)**: Given event pertama melempar error saat `deliverOne` (mis. `media_json` rusak), When batch diproses, Then event itu `failed` dan event berikutnya dalam batch tetap diproses.
- **AC-054 (REQ-049, direvisi CB-14)**: Given `postToCI4` menggantung melebihi `DELIVERY_EVENT_TIMEOUT_MS` (8000 ms), When event diproses, Then percobaan `postToCI4` dihentikan pada anggaran waktu itu (bukan seluruh event), event ditandai gagal sementara, dan batch lanjut. Given event dengan LID (2000 ms) + media (6000 ms) + `postToCI4` (8000 ms) semuanya menggantung pada batasnya masing-masing, When durasi total diukur, Then durasi mendekati batas terburuk **±16000 ms** yang tertulis di REQ-049, bukan 8000 ms. Given batas terburuk (~16000 ms) dibandingkan `SHUTDOWN_DRAIN_MS` (5000 ms) dan `stop()` dipanggil di tengah event itu, Then event tetap tersimpan untuk siklus berikutnya (tidak hilang), konsisten dengan AC-056.
- **AC-055 (REQ-050)**: Given beberapa event jatuh tempo dengan `id` berbeda, When batch diproses, Then event dikirim berurutan `id ASC`; overflow dikuras sebelum event jatuh tempo tanpa menahan pengiriman.
- **AC-056 (REQ-051)**: Given batch sedang berjalan saat `stop()` dipanggil, When shutdown selesai, Then tidak ada tick baru, batch berjalan tuntas dalam `SHUTDOWN_DRAIN_MS` (atau event tetap tersimpan untuk siklus berikutnya), dan `app/index.js` menunggu drain sebelum keluar.
- **AC-057 (REQ-052, diperluas CB-04)**: Given satu instance Gateway berjalan (PID hidup, heartbeat kunci segar), When instance kedua dijalankan pada database yang sama, Then instance kedua menolak start dengan pesan jelas dan keluar non-nol. Given kunci tertinggal dengan PID pemilik sudah mati (mis. setelah `SIGKILL`) atau heartbeat sudah basi, When Gateway dijalankan (atau dijalankan ulang), Then kunci diambil alih otomatis dan start berhasil — **ini yang mempertahankan pemulihan crash GW-17/AC-048** dan MUST diverifikasi di Windows maupun fallback JSON.
- **AC-058 (REQ-053, diperluas CB-03)**: Given overflow penuh / cache nama grup mencapai `GROUP_NAME_CACHE_MAX_ENTRIES` / event buffer dashboard penuh, When kejadian berikutnya masuk, Then batas dipertahankan dan gauge terkait (`overflow_size`, `group_name_cache_size`, `event_buffer_size`) tercermin di `GET /api/metrics`.
- **AC-059 (REQ-054)**: Given satu siklus worker selesai, When log diperiksa, Then ada entri `worker.tick` dengan jumlah diproses/gagal dan durasi; Given tick melempar error tak terduga, Then error tercatat dan proses tetap hidup.
- **AC-060 (REQ-055)**: Given seluruh `src/` dijalankan pada lintasan normal, When log diperiksa, Then setiap baris berkas adalah JSON valid dan tidak ada pemakaian `console.*` di luar jalur fatal (guard statis pada review).
- **AC-061 (REQ-056)**: Given pesan berhasil diteruskan, When log diperiksa, Then entri memuat `event`, `component`, `wa_message_id`, dan teks lama `[DELIVERY] pesan masuk berhasil diteruskan ke CI4` tetap ada.
- **AC-062 (REQ-057)**: Given event masuk dead-letter, When log diperiksa, Then baris memuat awalan `[CRITICAL]` **dan** `severity:'critical'`; given event noise Baileys, Then tercatat pada level `debug`.
- **AC-063 (REQ-058)**: Given `/send` dan `/send-media` gagal, When log metrik/hasil diperiksa, Then tidak ada teks pesan, `media_base64`, token, atau header `Authorization` di dalamnya.
- **AC-064 (REQ-059)**: Given beberapa entri log dihasilkan, When `GET /api/events` dipanggil, Then entri memuat `event` dan correlation ID (bila ada), dan jumlah entri tetap ≤300.
- **AC-065 (REQ-060)**: Given `status@broadcast`, alamat non-pelanggan, dan referensi media tidak lengkap dilewati, When `GET /api/metrics` dipanggil, Then `incoming_dropped_total` bertambah dengan `reason` yang sesuai.
- **AC-066 (REQ-061)**: Given Gateway berjalan, When `GET /api/metrics` dipanggil, Then semua metrik §4.3 tersedia sebagai JSON dan heartbeat memuat ringkasannya.
- **AC-067 (REQ-062, diperluas CB-02)**: Given tidak ada masalah, When `GET /api/health` dipanggil, Then `verdict='healthy'` dan `degraded_reasons=[]`. Given `pending` melampaui `HEALTH_PENDING_WARN`, Then `verdict='degraded'` dengan alasan `pending_high`. Given probe nonaktif/basi dan tidak ada pesan masuk dalam `HEALTH_INBOUND_STALE_MS`, When `receive_ready` dihitung, Then nilainya `unknown` (bukan `false`). Given probe basi tetapi ada pesan masuk dalam `HEALTH_INBOUND_STALE_MS`, Then `receive_ready=true`.
- **AC-068 (REQ-063, direvisi CB-05)**: Given socket terhubung tetapi probe gagal **satu kali**, When heartbeat dikirim, Then `status` **tetap `connected`** (belum mencapai `HEALTH_PROBE_FAILURES_THRESHOLD`) sementara `socket_status='connected'` dan `wa_status`/verdict mencerminkan kegagalan probe di `degraded_reasons` bila relevan. Given probe gagal **`HEALTH_PROBE_FAILURES_THRESHOLD` kali berturut-turut** (bawaan 2), When heartbeat berikutnya dikirim, Then `status='disconnected'` dan `health.degraded_reasons` memuat `probe_failed`. Given probe sukses dan segar, Then `status='connected'`. Given probe dinonaktifkan tanpa bukti, Then `verdict='unknown'`. Given `GET /api/health` dipanggil kapan pun, Then `socket_status` selalu mencerminkan state socket mentah tanpa debounce.
- **AC-069 (REQ-064)**: Given AuliaPos lama hanya membaca `status`, When heartbeat diterima, Then `status`, `phone`, dan `gateway_version` tetap ada dengan arti sama dan blok `health` bersifat tambahan.
- **AC-070 (REQ-065)**: Given `GET /api/health` dan heartbeat dipanggil berdekatan, When dibandingkan, Then blok `health` identik isinya.
- **AC-071 (REQ-066, diperluas CB-09)**: Given `messages.update` tiba untuk `wa_message_id` yang dimiliki sebuah operasi, When handler selesai, Then `receipt_state` baris itu berubah sesuai bukti dan `outgoing_receipt_total` bertambah. Given bentuk payload tidak dikenali, Then dicatat `warn` dan `receipt_state` **tidak** dikarang. Given tidak ada kabar, Then tetap `pending`. **Pemetaan status numerik → `receipt_state` MUST berasal dari OI-002 (§1.2); sebelum OI-002 tertutup, AC ini MUST NOT diklaim lulus.** Bukti bahwa handler ini **hanya mencatat** (tidak menyelesaikan operasi `in_flight` ambigu) MUST tercatat — pemetaan receipt tidak boleh dipakai sebagai klaim penutup ASSUMPTION-009.
- **AC-072 (REQ-067)**: Given `GET /api/metrics` dan `GET /api/health` dipanggil, When isinya diperiksa, Then tidak ada isi pesan dan tidak ada label `wa_message_id`/`operation_id`.
- **AC-073 (REQ-068, diperjelas CB-01)**: Given matriks uji Ticket 15, When ditinjau, Then setiap AC-001..AC-079 terpetakan ke REQ, level (unit/integrasi/nyata), otomatis/tidak, bukti, dan ambang lulus — termasuk REQ-068..071 (E-W5) yang sebelumnya yatim.
- **AC-074 (REQ-069)**: Given harness dijalankan, When salah satu skrip gagal, Then harness keluar non-nol; dan Given dijalankan penuh, Then tidak ada skrip yang menyentuh `data/gateway.sqlite` produksi.
- **AC-075 (REQ-070, diperjelas CB-10)**: Given protokol ukur nyata (crash/restart, pemadaman, poison, receipt) dijalankan, When kriteria keluar M1 dievaluasi, Then tiap protokol menghasilkan bukti tertulis dengan 0 hilang/0 duplikat (bila berlaku) atau temuan yang dijelaskan, dan M1 boleh dinyatakan tertutup **hanya untuk AC yang dapat dijalankan sekarang**; AC-049 (dan bagian OI-001-dependent dari AC-047/AC-051) MUST tercatat sebagai **`OPEN CARRY-OVER` eksplisit dengan pemilik aksi** dan **TIDAK dihitung lulus**. Laporan matriks MUST memuat bagian **"carry-over terbuka"** yang mendaftar item ini. Tidak ada klaim "M1 selesai penuh" selama carry-over terbuka.
- **AC-076 (REQ-071)**: Given satu pengukuran real-run selesai, When decision log ditulis, Then memuat angka mentah, batas bukti, dan tidak mengklaim GW-11/GW-25/GW-21 sebagai tertutup penuh. Given matriks memakai ulang bukti real-run lama, When bukti itu diverifikasi, Then commit penghasil bukti adalah leluhur HEAD final; bila bukan, protokol dijalankan ulang.
- **AC-077 (REQ-072, baru CB-11 — `heartbeat` single-flight)**: Given satu siklus kirim heartbeat masih berjalan (pengiriman status lambat/menggantung), When siklus berikutnya tiba, Then siklus itu dilewati tanpa mengantre, dan guard `isRunning` `heartbeat` sudah terlepas di akhir siklus (siklus setelahnya berjalan normal). Diverifikasi di seam `heartbeat` dengan `postStatusToCI4` di-stub menggantung.
- **AC-078 (REQ-073, baru CB-11 — isolasi error `heartbeat`)**: Given satu siklus `heartbeat` melempar error (mis. `postStatusToCI4` gagal), When siklus berikutnya dijadwalkan, Then error tercatat, proses tetap hidup, dan siklus berikutnya tetap berjalan. Diverifikasi dengan stub yang melempar.
- **AC-079 (REQ-074, baru CB-11 — anggaran waktu `heartbeat`)**: Given `postStatusToCI4` menggantung melebihi `HEARTBEAT_SEND_TIMEOUT_MS`, When satu siklus `heartbeat` diproses, Then panggilan dihentikan pada anggaran waktu, pelanggaran dicatat, dan proses maupun jadwal siklus berikutnya tidak terganggu (konsisten AC-078).

## 6. Test Automation Strategy & Testing Seams

- **Testing Seams**:
  1. `incomingDelivery.tick()` dengan `postToCI4` di-stub (deterministik: sukses/gagal/gantung) dan DB sementara.
  2. `health`/`metrics` langsung sebagai modul murni dengan injeksi snapshot koneksi dan angka antrean (tanpa jaringan).
  3. `connectionManager` handler `messages.update` dengan payload Baileys palsu, dan store `outgoingOperations` dengan DB sementara.
  4. Logger dengan destination in-memory untuk meng-assert bentuk JSON, `event`, dan tidak adanya konten.
  5. Router `GET /api/health`/`GET /api/metrics` via `createServer()` tanpa `listen()`.
  6. Ticket 05 memakai **pengukuran nyata** (protokol tertulis), bukan stub, karena inti ticket ini adalah kondisi crash nyata.
  7. `heartbeat` dengan `postStatusToCI4` di-stub (sukses/gagal/gantung) dan guard anti-tumpang-tindih → untuk AC-077..AC-079 (CB-11).
  8. Guard satu instance dengan lock file di folder temp (skenario PID hidup+mematikan, PID mati/ganti, heartbeat basi) → untuk AC-057 (CB-04).
- **Test Levels**: skrip integrasi ringan berbasis `assert` (pola `test/simulate-*.js`) untuk seam 1–5, 7, 8; prosedur ukur nyata untuk Ticket 05 (seam 6) dan Ticket 15.
- **Batas bukti**: AC-049 dan bagian OI-001-dependent dari AC-047/AC-051 bersifat **BLOCKED-OI-001** dan dicatat sebagai **OPEN CARRY-OVER** (REQ-070); hasil H1/H2 MUST NOT diklaim sebagai perbaikan. `receive_ready` bersifat tidak langsung (ASSUMPTION-020). REQ-066 tidak boleh diimplementasikan sebelum OI-002 tertutup.
- **Test Data Management**: database SQLite sementara di folder temp sistem, dihapus tiap skenario (pola `simulate-durable-buffer.js`). MUST NOT menyentuh `data/gateway.sqlite` produksi (pelajaran CR gelombang 1).
- **CI/CD Integration**: tidak ada pipeline. Skrip dijalankan manual `node test/<nama>.js`; harness Ticket 15 menggabungkan semuanya + guard statis terkait.
- **Coverage Requirements**: setiap REQ Wave 3 punya minimal satu AC; setiap AC punya skrip otomatis, kecuali AC-048, AC-049, AC-050, AC-051 (bagian OI-001-dependent), dan AC-075 yang memakai prosedur ukur nyata tertulis. AC-071 (REQ-066) MUST NOT dijalankan sebagai bukti lulus sebelum OI-002 tertutup. Tidak ada ambang persentase.
- **Pemetaan REQ ke AC**:
  - E-W1: REQ-042→AC-047, REQ-043→AC-048, REQ-044→AC-049, REQ-045→AC-050, REQ-046→AC-051.
  - E-W2: REQ-047→AC-052, REQ-048→AC-053, REQ-049→AC-054, REQ-050→AC-055, REQ-051→AC-056, REQ-052→AC-057, REQ-053→AC-058, REQ-054→AC-059, REQ-072→AC-077, REQ-073→AC-078, REQ-074→AC-079.
  - E-W3: REQ-055→AC-060, REQ-056→AC-061, REQ-057→AC-062, REQ-058→AC-063, REQ-059→AC-064, REQ-060→AC-065.
  - E-W4: REQ-061→AC-066, REQ-062→AC-067, REQ-063→AC-068, REQ-064→AC-069, REQ-065→AC-070, REQ-066→AC-071 (gerbang OI-002), REQ-067→AC-072.
  - E-W5: REQ-068→AC-073, REQ-069→AC-074, REQ-070→AC-075, REQ-071→AC-076.
  - SEC-003 diverifikasi lewat review + AC-063/AC-072; SEC-004/005 lewat review kode.

## 7. Project Structure & Commands

### Project Structure

**WA-Gateway** (`C:\projects\WA-Gateway`, branch kerja baru `feature/m1-wave3-reliability-observability`, worktree terpisah — pola `C:\projects\WA-Gateway-m1w3` mengikuti CON-005 gelombang 1). Wave 3 **tidak** mengubah AuliaPos (CON-015).

- `src/observability/metrics.js` (baru): registri counter/gauge + snapshot JSON.
- `src/observability/health.js` (baru): perhitungan verdict + `degraded_reasons`.
- `src/observability/probe.js` (baru): probe reachability terjadwal.
- `src/logging/index.js`: skema field,event buffer terstruktur, redaksi diperluas.
- `src/delivery/incomingDelivery.js`: isolasi error per event, anggaran waktu, observabilitas tick, drain.
- `src/delivery/heartbeat.js`: blok `health` aditif, status diturunkan saat degraded.
- `src/whatsapp/connectionManager.js`: handler `messages.update`, instrumentasi diagnostik bergerbang, counter drop/decrypt.
- `src/api/routes.js`: `GET /api/health`, `GET /api/metrics`.
- `src/store/outgoingOperations.js`: kolom `receipt_*` + operasi update receipt.
- `src/config/index.js`: variabel lingkungan baru (§4.7).
- `src/app/index.js`: guard satu instance, drain shutdown, start probe.
- `test/`: skrip baru dengan pola `simulate-*.js` + harness Ticket 15.

### Commands

- **Build:** tidak ada.
- **Test (Gateway):** `node test/<nama-skrip>.js`; harness Ticket 15: `node test/run-reliability-matrix.js` (nama final ditentukan di plan).
- **Lint/Format:** tidak ada konfigurasi lint. Markdownlint manual untuk berkas ini (lihat §13).
- **Dev (Gateway):** `npm run dev` — MUST NOT dijalankan pada Gateway yang sedang aktif.
- **Deploy:** pola gelombang 1/2 (`merge --ff-only` + `pm2 restart`) dengan titik rollback dicatat sebelum deploy.
- **AuliaPos:** tidak ada perintah yang dijalankan pada Wave 3 (CON-015).

## 8. Code Style & Conventions

Gateway memakai CommonJS, `'use strict'`, dua spasi, tanda kutip tunggal, komentar bahasa Indonesia, dan pencatatan lewat `logger`. Skema field metrik/log memakai bahasa Inggris (snake_case / nama event bertitik) mengikuti konvensi kolom database. Contoh gaya untuk registri metrik:

```javascript
'use strict';

const logger = require('../logging');

// Counter sederhana tanpa dependensi (ASSUMPTION-014). Label dibatasi ke
// himpunan nilai tertutup (enum reason) supaya kardinalitas tetap kecil
// (REQ-067) -- wa_message_id/operation_id TIDAK boleh jadi label.
const counters = new Map();
const gauges = new Map();

function inc(name, labels = {}, delta = 1) {
  const key = `${name}${JSON.stringify(labels)}`;
  const entry = counters.get(key) || { name, labels, value: 0 };
  entry.value += delta;
  counters.set(key, entry);
}

function set(name, value, labels = {}) {
  gauges.set(`${name}${JSON.stringify(labels)}`, { name, labels, value });
}

function snapshot() {
  return {
    counters: [...counters.values()],
    gauges: [...gauges.values()],
  };
}

// REQ-060: kejadian "dilewati" harus terukur, bukan cuma tertulis di log.
function recordDrop(reason, meta = {}) {
  inc('incoming_dropped_total', { reason });
  logger.warn('[CHAT] pesan masuk dilewati', { event: 'wa.message.dropped', component: 'connectionManager', reason, ...meta });
}

module.exports = { inc, set, snapshot, recordDrop };
```

Catatan: snippet menunjukkan **gaya dan kontrak**, bukan implementasi lengkap. Validasi `reason` terhadap enum §2, penggabungan label, dan format `GET /api/metrics` akan ditentukan di plan.

## 9. Implementation Boundaries

- **Always do:** menambah skrip uji untuk setiap perubahan; menjaga kontrak masuk/keluar tetap kompatibel; membuat ambang baru dapat diatur lewat env; bekerja hanya di worktree Gateway baru; mencatat titik rollback sebelum deploy; mempertahankan teks log lama; mengaktifkan `HEALTH_PROBE_ENABLED=1` bawaan di semua lingkungan termasuk produksi (CB-13, keputusan sudah eksplisit di §4.7 — bukan lagi item yang butuh persetujuan terpisah).
- **Ask first:** menambah kolom `receipt_*` pada `outgoing_operations`; menambah event/endpoint observabilitas baru di luar §4; menjalankan uji yang mematikan/menjeda Gateway aktif; menjalankan uji yang menyentuh folder `auth/`; mengimplementasikan REQ-066 sebelum OI-002 (§1.2) tertutup.
- **Never do:** mengubah kontrak `POST /api/inbox/gateway/messages`; mengubah `/send`, `/send-media`, `/media/download` secara non-aditif; menulis isi pesan/`media_base64`/token ke log, metrik, atau health; menjadikan `wa_message_id`/`operation_id` sebagai label metrik; menghapus atau melewati uji yang gagal; mengubah kode AuliaPos; menyentuh `data/gateway.sqlite` produksi atau `auth/` nomor aktif; mengklaim GW-11/GW-25/GW-21/ASSUMPTION-009 sebagai tertutup penuh; mengklaim REQ-066/receipt sebagai penutup ASSUMPTION-009 (CB-09).

## 10. Rationale, Context & Architecture Decisions (ADRs)

- **Kenapa Ticket 05 masih perlu meski gelombang 1/2 sudah mengukur pemulihan:** gelombang 1 membuktikan `append`/buffer, gelombang 2 membuktikan idempotensi, tetapi keduanya diukur pada kondisi yang berbeda-beda. Ticket 05 menyatukan kondisi crash nyata (SIGKILL di titik berbeda) dan menambahkan **uji pembeda** H1/H2 yang belum pernah bisa dijalankan karena membutuhkan nomor kedua (`docs/decisions/2026-09-21-m1-ticket01-baseline.md` bagian "Analisis lanjutan", §3 dan §6).
- **Kenapa instrumentasi diagnostik, bukan perbaikan:** sumber `message_timestamp` dan penyebab kegagalan dekripsi berada di luar kode yang dapat diubah Gateway (ESC-001..004). Jalur yang bisa ditempuh Gateway hanya membuat kejadiannya terukur dan terlihat (GUD-006), bukan mengklaim akar masalahnya.
- **Kenapa worker correctness jadi ticket terpisah:** gelombang 1/2 menambah beban jalur (retry, dead-letter, operasi) tanpa pernah mengaudit invariant loop. Dua cacat nyata terlihat dari kode: `stop()` tidak menunggu batch berjalan, dan satu event yang melempar error membatalkan sisa batch (satu `try/catch` membungkus seluruh loop). Keduanya bisa menghilangkan/menunda pemrosesan tanpa jejak.
- **Kenapa health memakai probe (GW-20):** status socket saja terbukti menyesatkan — `connected` tetap dilaporkan selama seluruh burst yang penuh kegagalan dekripsi (`docs/GATEWAY-REQUIREMENTS.md` GW-20). Hanya bukti aktif (probe) atau pesan nyata yang bisa membedakan "socket hidup" dari "benar-benar bisa kirim/terima".
- **Kenapa GW-21 dibatasi ke Gateway (ASSUMPTION-012, dikoreksi CB-09):** `GATEWAY-REQUIREMENTS.md` menandainya M2; menampilkannya di AuliaPos akan menarik scope M2 ke M1. Merekam receipt di Gateway adalah langkah minimum yang memberi fondasi M2 tanpa mengubah AuliaPos. **Klaim versi sebelumnya bahwa ini "menutup sebagian ASSUMPTION-009" DIHAPUS karena tidak didukung:** tidak ada apa pun di Wave 3 yang mengonsumsi receipt untuk menyelesaikan operasi `in_flight` yang ambigu, sehingga celah jendela ~1 ms "sudah diterima WhatsApp tetapi belum tercatat" (ASSUMPTION-009 gelombang 2) tetap terbuka. RECEIPT di sini **record-only**; implementasinya pun digerbang OI-002 (§1.2).
- **Kenapa metrik in-process (ASSUMPTION-014):** kebutuhan nyata adalah mengukur (E-08/E-12) dan memberi dasar keputusan, bukan membangun infrastruktur monitoring. Menambah dependensi melanggar CON-004/CON-008 dan memperbesar risiko di build Android.
- **ADR:** tidak dibuat. Semua keputusan di sini mudah dibalik (path endpoint, ambang env, menambah/hapus kolom aditif) sehingga tidak memenuhi Triple Gate `.claude/standards/ADR-FORMAT.md`. Bila ke depan diputuskan **menjadikan GW-21 sebagai kontrak status pengiriman lintas-repo yang mengikat** (menyentuh AuliaPos dan database produksi), keputusan itu sulit dibalik dan wajib dibuatkan ADR di `docs/adr/`.

## 11. Dependencies & External Integrations

### External Systems

- **EXT-001**: Baileys 6.7.24. Yang dipakai Wave 3: `sock.ev.on('messages.update')` (GW-21) dan `sock.onWhatsApp()` sebagai probe (GW-20). **Bentuk pasti payload `messages.update` dan tabel pemetaan status numerik → `receipt_state` adalah OI-002 (§1.2), GERBANG KERAS** — REQ-066 MUST NOT dikunci/diimplementasikan sebelum OI-002 ditutup di `/sdlc-plan-tasks`/implementasi (bukan sekadar catatan referensi seperti versi sebelumnya).
- **EXT-002**: AuliaPos (`POST /api/inbox/gateway/status` menerima heartbeat; `POST /api/inbox/gateway/messages` menerima event). AuliaPos lama MUST tetap bekerja (REQ-064).

### Third-Party Services

- **SVC-001**: Server WhatsApp (lewat Baileys) — sumber probe, receipt, dan pesan. Tidak ada SLA; ketidakstabilan sesi adalah risiko yang diukur, bukan dihilangkan.

### Infrastructure Dependencies

- **INF-001**: Node.js 20 dan `better-sqlite3` di desktop; fallback JSON untuk build Android (guard satu instance REQ-052 MUST bekerja di keduanya).
- **INF-002**: PM2/supervisor sebagai pengelola proses; deploy pola `merge --ff-only` + restart.

### Data Dependencies

- **DAT-001**: Skema `incoming_queue` (gelombang 1–2) dan `outgoing_operations` (gelombang 2). Wave 3 hanya menambah kolom `receipt_*`.
- **DAT-002 (diperluas, CB-06)**: **OI-001 — dua pemasok luar** (bukan data di repo, D-14): (a) akun WhatsApp ketiga sebagai instance Gateway uji dengan sesi bersih, dan (b) nomor kontak uji yang belum pernah dihubungi Gateway mana pun. Prasyarat AC-049 (dan bagian AC-047/AC-051 yang bergantung padanya); MUST disediakan pemilik proyek dan tidak pernah di-hardcode ke kode/dokumen.
- **DAT-003**: Berkas log `logs/gateway.log` — tumbuh tanpa rotasi (ASSUMPTION-019, risiko diterima).

## 12. Examples & Edge Cases

```text
Kasus 1 (Ticket 12, isolasi error per event):
  Batch berisi event A (media_json rusak) dan B (normal).
  Lama: JSON.parse(A) melempar -> catch membatalkan seluruh batch -> B tidak terkirim.
  Baru: A ditandai failed + log; B tetap terkirim pada siklus yang sama.

Kasus 2 (Ticket 12, drain shutdown):
  stop() dipanggil saat batch berisi 3 event sedang berjalan.
  -> tidak ada tick baru, batch menuntaskan (<= SHUTDOWN_DRAIN_MS),
     event yang belum completed tetap tersimpan untuk siklus setelah restart.

Kasus 3 (Ticket 14, health jujur + debounce CB-05):
  Socket connected; probe onWhatsApp gagal SATU kali (server tidak menjawab).
  -> socket_status tetap 'connected'; status TETAP 'connected' (belum N=2 berturut).
  -> health.degraded_reasons boleh memuat 'probe_failed'; verdict boleh 'degraded'.
  Probe gagal lagi (gagal berturut ke-2) -> status baru turun ke 'disconnected'.
  AuliaPos lama melihat 'disconnected' seperti biasa; operator melihat alasan di
  health dan bisa membedakan socket mati vs health yang di-debounce (socket_status).

Kasus 4 (Ticket 14, GW-21 receipt):
  /send sukses -> outgoing_operations.receipt_state='pending'.
  messages.update tiba (delivered) -> receipt_state='delivered', counter naik.
  Replay /send op yang sama -> respons 200 replayed:true + receipt_state:'delivered'.
  Tidak ada kabar -> tetap 'pending' (TIDAK dianggap failed).

Kasus 5 (Ticket 14, kardinalitas):
  Operator ingin melihat "pesan mana" dari metrik.
  -> DILARANG: label wa_message_id. Yang tersedia: counter + log dengan wa_message_id.
  (REQ-067)

Kasus 6 (Ticket 05, pembeda H1, direvisi D-14):
  Instance uji = akun WhatsApp ketiga (sesi bersih). Kontak uji B belum pernah
  dihubungi. Instance uji /send ke B@pn (BUKAN dari 6281913500707), lalu B
  membalas. Bila muncul "No matching sessions found" pada percobaan pertama
  -> H1 didukung. Bila tidak -> H1 ditolak pada arm ini; H2 diuji terpisah.
  Hasil dicatat sebagai bukti, BUKAN sebagai "sudah diperbaiki".

Kasus 7 (edge, probe nonaktif):
  HEALTH_PROBE_ENABLED=0 dan toko sepi (tidak ada pesan berjam-jam).
  -> receive_ready='unknown' dan verdict='unknown' (bukan 'healthy'/'false'),
     supaya tidak mengklaim bukti yang tidak ada (CB-02).

Kasus 8 (edge, instance ganda vs takeover CB-04):
  Operator tak sengaja menjalankan Gateway kedua pada DB yang sama SAAT
  instance pertama masih hidup (PID hidup, heartbeat kunci segar).
  -> start kedua menolak dengan pesan jelas + keluar non-nol (GW-18),
     mencegah dua worker berebut antrean yang sama.
  SEBALIKNYA: instance pertama di-SIGKILL (kunci tertinggal, PID sudah mati).
  -> start Gateway berikutnya MENGAMBIL ALIH kunci otomatis dan start berhasil
     (tidak menolak) -- inilah yang menjaga pemulihan crash GW-17/AC-048 tetap
     berfungsi meski ada guard satu instance.

Kasus 9 (Ticket 12, anggaran waktu berlapis CB-14):
  Satu event: query LID menggantung 2000 ms, unduhan media menggantung 6000 ms,
  lalu postToCI4 menggantung 8000 ms -- total mendekati 16000 ms, BUKAN 8000 ms
  (DELIVERY_EVENT_TIMEOUT_MS hanya membungkus postToCI4, REQ-049).
  Bila stop() dipanggil di tengah event ini (SHUTDOWN_DRAIN_MS=5000 ms jauh
  lebih pendek dari 16000 ms), event TETAP tersimpan dan dijadwalkan ulang
  untuk siklus berikutnya setelah restart -- bukan hilang (REQ-051).

Kasus 10 (Ticket 12, worker heartbeat CB-11):
  Siklus heartbeat sedang mengirim status (lambat/menggantung).
  Siklus berikutnya tiba -> dilewati tanpa mengantre (REQ-072, seperti
  incomingDelivery). Bila siklus yang sedang berjalan melempar error
  (POST /api/inbox/gateway/status gagal) -> dicatat, proses tetap hidup,
  siklus berikutnya tetap terjadwal normal (REQ-073). Bila panggilan
  menggantung melebihi HEARTBEAT_SEND_TIMEOUT_MS -> dihentikan pada anggaran
  waktu itu, siklus itu gagal sementara, jadwal berikutnya tidak terganggu
  (REQ-074).
```

## 13. Validation Criteria

1. Seluruh AC-047 sampai AC-079 yang dapat dijalankan lulus, dengan skrip otomatis untuk AC-047, AC-052..AC-074, AC-076, dan AC-077..AC-079; AC-048/AC-049/AC-050/AC-051 (bagian OI-001-dependent)/AC-075 memakai prosedur ukur nyata tertulis. AC-049 MUST tetap `BLOCKED` sampai OI-001 terpenuhi, dan MUST dicatat sebagai `OPEN CARRY-OVER` (REQ-070) — **bukan dihitung lulus**. Bagian OI-001-dependent dari AC-047/AC-051 mengikuti aturan yang sama.
2. Harness Ticket 15 (AC-074) dijalankan penuh dan keluar `0`; tidak ada skrip yang menyentuh `data/gateway.sqlite` produksi atau `auth/`.
3. `GET /api/health` dan `GET /api/metrics` diverifikasi pada Gateway hidup, termasuk skenario degradasi terkontrol (probe gagal disimulasikan, `pending` dinaikkan melampaui ambang) dan verifikasi debounce CB-05 (`status` tetap `connected` pada 1 kegagalan probe pertama, turun setelah N berturut-turut, sementara `socket_status` mentah tak berubah).
4. Rekaman GW-21 diverifikasi dengan minimal satu pesan keluar nyata yang menerima kabar `delivered`/`read`, dan satu kasus tanpa kabar yang tetap `pending` — **tetapi HANYA setelah OI-002 (bentuk payload + tabel pemetaan status) tertutup**; sebelum itu, AC-071 MUST NOT diklaim lulus.
5. Log diverifikasi: JSON valid per baris, memuat `event` + correlation ID, tetap memuat teks lama, dan tidak memuat konten (`grep` terhadap teks pesan uji + `media_base64` = 0 hasil).
6. Tidak ada klaim tertutup untuk GW-11, GW-25, GW-21 penuh, atau ASSUMPTION-009 di decision log mana pun; dan tidak ada klaim bahwa REQ-066/receipt menutup ASSUMPTION-009 (record-only, CB-09).
7. Matriks Ticket 15 mencakup AC-001..AC-079 dan menandai level, otomatisasi, bukti, serta ambang lulus untuk tiap baris, plus bagian **"carry-over terbuka"** yang mendaftar AC yang belum dapat dijalankan beserta pemilik aksinya (REQ-070).
8. Markdownlint dijalankan pada berkas ini. Profil temuannya **sama jenisnya** dengan `spec-process-m1-wave2-outgoing-idempotency.md` v1.1 yang sudah di-approve: `MD013` (panjang baris, bawaan 80), `MD028` (baris kosong antar dua blok `> [!WARNING]`), `MD060` (gaya pipa tabel), dan satu `MD025` (pola `# Introduction` + `## 1.`). Repositori tidak memiliki konfigurasi `.markdownlint*`, dan `.claude/instructions/markdown.instructions.md` menetapkan batas 400 karakter, sehingga `MD013` bawaan tidak mencerminkan konvensi proyek. Sesuai batas kewenangan skill ini (hanya menulis di `/spec/`), normalisasi lint lintas-repo MUST NOT dilakukan di sini. Yang MUST dipastikan: **tidak ada jenis temuan baru** dibanding spec gelombang 2.
9. Uji Readiness mandiri terhadap rubrik klarifikasi (dikoreksi v1.1, CB-01 — klaim versi sebelumnya "lima ticket E-W1..E-W5 punya REQ, AC" salah karena E-W5/REQ-068..071 sebelumnya tidak ada): **Completeness** — kelima ticket E-W1..E-W5 **kini benar-benar** punya REQ, AC, seam, batas bukti; OI-001 (dua pemasok, D-14) dan OI-002 (gerbang keras) tercatat eksplisit di §1.2. **Clarity** — nama metrik, path endpoint, skema field, ambang env, enum alasan, dan ketiga rumus kesiapan (`send_ready`/`receive_ready`/`delivery_ready`) tertulis eksplisit. **Alignment** — terlacak ke GW-20/GW-21 serta Ticket 05/12–15; out-of-scope eksplisit menutup 06–11/16 dan M2; klaim ASSUMPTION-009 yang tidak didukung sudah dihapus dari §10. Skor mandiri berkas ini konsisten dengan proyeksi remediasi di kepala dokumen: **90/100** (lihat blok `REMEDIATION STATUS: RESOLVED` setelah Introduction untuk rincian per kriteria).

## 14. Related Specifications / Further Reading

- `spec/spec-process-m1-wave1-incoming-reliability.md` v1.1 — gelombang 1 (AC-001..AC-018, CON-001..004, REQ-001..019).
- `spec/spec-process-m1-wave2-outgoing-idempotency.md` v1.1 — gelombang 2 (AC-019..AC-046, SEC-001..002, CON-005..010, REQ-020..041; penomoran yang dilanjutkan di sini).
- `plan-process-m1-wave2-outgoing-idempotency-v1.0.md` — pola TASK/APPROVAL/VERIFY/DEPLOY dan RISK-009 (pagar scope).
- `docs/GATEWAY-REQUIREMENTS.md` — GW-20, GW-21 (dan rujukan GW-11, GW-19, GW-25).
- `docs/TODO-CHAT.md` — Ticket 05 dan 12–15, risiko P0 #3/#4/#5, butir C3.
- `docs/decisions/2026-09-21-m1-ticket01-baseline.md` — Baseline 2/3/4 dan bagian "Analisis lanjutan … error dekripsi" (H1/H2, nomor kedua).
- `docs/decisions/2026-09-21-m1-ticket02-audit-enqueue.md` — E-08 dan E-12 (dasar counter drop per alasan).
- `docs/decisions/2026-09-23-m1-wave1-deploy-dan-ac001.md` dan `docs/decisions/2026-09-25-ticket04-json-fallback-android-verifikasi-nyata.md` — pola bukti nyata dan batas bukti perangkat.
- `docs/audit/code-review-m1-wave1-2026-09-23.md` — temuan CR dan pelajaran pengujian (DB sementara, guard statis).
- `docs/audit/clarification-report-m1-wave3-reliability-observability-2026-09-29.md` — laporan klarifikasi final (Review Iteration 1, Readiness 71/100 → proyeksi 90/100, PROCEED) yang 14 resolusinya (CB-01..CB-14) diterapkan di **v1.1** ini. Blok `REMEDIATION STATUS: RESOLVED` di kepala dokumen menutup siklus audit ini.

> **Handoff v1.1:** spec ini siap untuk `/sdlc-clarify-reqs` ulang (opsional) atau langsung `/sdlc-plan-tasks`. Dua item terbuka yang MUST ditutup lebih dulu di plan: **OI-002** (verifikasi bentuk payload `messages.update` 6.7.24 + tabel pemetaan status, sebagai tugas pertama Ticket 14) dan penyediaan **OI-001/D-14** (akun Gateway uji + nomor kontak uji, prasyarat Ticket 05).
