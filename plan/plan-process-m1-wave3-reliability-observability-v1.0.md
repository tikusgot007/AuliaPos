---
goal: M1 Gelombang 3 — Keandalan Terverifikasi, Logging Terstruktur & Observabilitas WA-Gateway (Ticket 05, 12–15)
version: 1.0
date_created: 2026-09-29
last_updated: 2026-09-29
owner: WA-Gateway reliability (M1)
status: 'Planned'
tags: [process, gateway, whatsapp, baileys, m1, reliability, observability, logging, metrics, health, test-matrix]
---

# Introduction

![Status: Planned](https://img.shields.io/badge/status-Planned-yellow)

Plan ini mengeksekusi `spec/spec-process-m1-wave3-reliability-observability.md` (v1.1) untuk menutup **Ticket 05** (crash/restart & uji pembeda dekripsi), **Ticket 12** (worker correctness), **Ticket 13** (structured logging), **Ticket 14** (metrics/health GW-20 + receipt GW-21), dan **Ticket 15** (matriks uji keandalan penuh & kriteria keluar M1), sesuai `docs/TODO-CHAT.md` dan `docs/GATEWAY-REQUIREMENTS.md` (GW-20, GW-21; rujukan GW-11, GW-19, GW-25). Gelombang ini **tidak mengubah** jaminan gelombang 1 (Ticket 02–04) maupun gelombang 2 (Ticket 06–11); ia membuktikan, mengukur, dan membuat terlihat apa yang sudah dibangun.

Bentuk plan, pola **VERIFY/APPROVAL/DEPLOY**, penomoran `REQ`/`CON`/`SEC`/`GUD`, dan gaya tabel mengikuti `plan-process-m1-wave2-outgoing-idempotency-v1.0.md` (dirujuk spec §14) agar traceability lintas-gelombang M1 tetap utuh.

**Kode hanya di repo WA-Gateway** — Wave 3 **Gateway-only** (CON-015): tidak ada task AuliaPos di plan ini. Kerja **hanya** di worktree baru `C:\projects\WA-Gateway-m1w3`, branch `feature/m1-wave3-reliability-observability`. Folder live `C:\projects\WA-Gateway` bersifat **read-only sampai TASK-031 (DEPLOY)**. Folder `auth/` dan versi Baileys (6.7.24) tidak disentuh (CON-014).

**Sesi ini tidak mengubah kode apa pun.** Eksekusi kode dilakukan oleh `/sdlc-write-code` di sesi terpisah per fase.

> [!NOTE] Bahasa & konvensi dokumen. Plan ini ditulis dalam **bahasa Indonesia**, mengikuti pola `plan-process-m1-wave2-outgoing-idempotency-v1.0.md` dan ASSUMPTION-021 spec v1.1. `AGENTS.md` menetapkan bahasa Inggris untuk dokumen SDLC; dua gelombang sebelumnya memakai bahasa Indonesia dengan asumsi yang sama. Ubah bila diminta.

> [!NOTE] Anti-Data-Loss Guard. Per 2026-09-29 tidak ada berkas `plan/*wave3*` di `/plan/` (diperiksa lewat pencarian direktori), sehingga berkas ini dibuat baru dan tidak menimpa plan yang belum selesai. Plan gelombang 1 dan 2 berstatus `Completed`.

> [!IMPORTANT] Klarifikasi granularitas gerbang OI-001 (diputuskan sesi ini, memperhalus pagar wajib #2 dari instruksi user). Pagar wajib #2 menyatakan "OI-001/D-14 = prasyarat **semua** tugas Ticket 05". User mengonfirmasi secara eksplisit saat sesi `/sdlc-plan-tasks` ini bahwa penerapannya adalah: **kode instrumentasi/harness (REQ-042, REQ-043, REQ-045, REQ-046 — TASK-034..037) boleh ditulis dan diuji-stub lebih dulu tanpa OI-001**, karena tidak membutuhkan akun/nomor uji nyata; **hanya eksekusi protokol nyata (AC-047..051, TASK-038) yang MUST diblokir** sampai OI-001/D-14 tersedia. Keputusan ini konsisten dengan spec (REQ-042/043/045/046 tidak menyebut OI-001 sebagai prasyarat kode, hanya AC-049/bagian AC-047/AC-051 yang eksplisit `BLOCKED`), dan TASK-033 (gerbang OI-001) tetap menjadi `Dep` **eksklusif** untuk TASK-038, bukan seluruh Fase 7.

## 1. Requirements & Constraints

Penomoran requirement mengikuti spec v1.1 apa adanya (REQ-042..REQ-074 melanjutkan REQ-001..REQ-041 gelombang 1–2). Plan ini **tidak menambah** requirement baru (Red Flag #7 — tidak ada fitur halusinasi), kecuali satu variabel lingkungan konfigurasi (`GROUP_NAME_CACHE_MAX_ENTRIES`) yang wajib secara logis oleh REQ-053/AC-058 namun hilang dari tabel §4.7 spec — dicatat sebagai **RISK-001 (gap dokumen)**, bukan requirement baru, dan diselesaikan ke arah pemenuhan REQ-053 (pola yang sama dengan RISK-005 plan gelombang 2).

**E-W1 — Ticket 05: Crash/restart & uji pembeda dekripsi (instrumentasi & pengukuran, BUKAN perbaikan GW-11/GW-25):**

- **REQ-042**: instrumentasi diagnostik read-only bergerbang `WA_DIAG_RAW_MESSAGE` (bawaan `0`); merekam `wa_message_id`, `type`, alamat/`jid_type`, ada/tidaknya `msg.message`, `messageStubType`, stempel waktu mentah, waktu tiba lokal, jumlah retry dekripsi, penanda `/send` ke alamat `pn` kontak pada sesi berjalan; MUST NOT mengubah perilaku pemrosesan atau mencatat isi pesan/nama kontak.
- **REQ-043**: harness crash/restart terkontrol (SIGKILL awal/tengah/akhir + graceful, ≥3× masing-masing); verifikasi tidak ada pesan hilang/duplikat, pemulihan `in_flight`, dan prune start-up berjalan.
- **REQ-044 (D-14)**: uji pembeda H1 (sesi campuran `pn`/`lid`) vs H2 (kill saat mengenkripsi) memakai instance Gateway uji (akun ketiga, D-14) dan kontak uji (OI-001); Arm H1 MUST `/send` dari instance/nomor uji, **bukan** dari `6281913500707`.
- **REQ-045**: setiap pengukuran MUST merekam pasangan `message_timestamp` (Baileys) dan waktu tiba/insert.
- **REQ-046 (D-14)**: seluruh uji Ticket 05 terisolasi (worktree/instance terpisah, DB sementara, akun ketiga sesi bersih — bukan salinan `auth/` aktif); MUST NOT menyentuh `auth/` nomor aktif.

**E-W2 — Ticket 12: Worker correctness:**

- **REQ-047**: `incomingDelivery` single-flight; guard `isRunning` selalu terlepas di akhir siklus (termasuk error); tick tumpang tindih dilewati tanpa antre.
- **REQ-048**: error satu event (termasuk `JSON.parse`) MUST NOT menghentikan event lain dalam batch; event gagal ditandai `markFailedAttempt()`, loop lanjut.
- **REQ-049**: `DELIVERY_EVENT_TIMEOUT_MS` (bawaan 8000 ms) **hanya** membungkus `postToCI4()`; batas nyata terburuk satu event ±16000 ms (LID 2000 + media 6000 + AuliaPos 8000) MUST ditulis eksplisit; `SHUTDOWN_DRAIN_MS` (5000 ms) lebih pendek — event belum selesai saat drain tetap tersimpan untuk siklus berikutnya.
- **REQ-050**: event diproses `id ASC` (FIFO); overflow dikuras sebelum event jatuh tempo; tidak ada pengurutan ulang baru diperkenalkan.
- **REQ-051**: `stop()` mencegah tick baru dan menunggu batch berjalan selesai dalam `SHUTDOWN_DRAIN_MS`; `app/index.js` `shutdown()` menunggu drain; event belum `completed` tetap tersimpan.
- **REQ-052 (CB-04)**: guard satu instance berbasis lock file (PID + timestamp start + heartbeat berkala); saat start, bila PID pemilik mati **atau** heartbeat basi → ambil alih otomatis dan lanjut start; bila PID hidup dan heartbeat segar → tolak start, keluar non-nol. Bekerja identik di Windows dan fallback JSON.
- **REQ-053 (CB-03)**: seluruh struktur in-memory terbatas dan terlihat di metrik: overflow, own-sent, **cache nama grup** (`GROUP_NAME_CACHE_MAX_ENTRIES`, gauge `group_name_cache_size`), event buffer dashboard (300, gauge `event_buffer_size`).
- **REQ-054**: setiap tick mencatat jumlah diproses/berhasil/gagal + durasi; error tak terduga tertangkap dan tercatat, proses tidak mati.
- **REQ-072 (CB-11)**: worker `heartbeat` single-flight, pola sama REQ-047.
- **REQ-073 (CB-11)**: error satu siklus `heartbeat` tidak menghentikan penjadwalan siklus berikutnya, pola sama REQ-048/054.
- **REQ-074 (CB-11)**: anggaran waktu satu percobaan kirim heartbeat (`HEARTBEAT_SEND_TIMEOUT_MS`, bawaan 8000 ms); pelanggaran dicatat, jadwal berikutnya tidak terganggu.

**E-W3 — Ticket 13: Structured logging:**

- **REQ-055**: seluruh `src/` mencatat lewat satu logger (`src/logging/index.js`); `console.*` hanya di jalur fatal start-up; output berkas satu baris JSON per entri (pino).
- **REQ-056**: entri penting memuat `event` (nama bertitik, Inggris) dan `component`; correlation ID (`wa_message_id`/`operation_id`/`chat_id`) bila relevan; teks log lama MUST dipertahankan (aditif).
- **REQ-057**: kebijakan level `debug`/`info`/`warn`/`error`; keadaan terminal menulis `[CRITICAL]` **dan** `severity:'critical'`.
- **REQ-058 (SEC-001 diperluas)**: log MUST NOT memuat isi pesan, `media_base64`, token, kredensial; `REDACT_PATHS` mencakup `Authorization` dan field sensitif.
- **REQ-059**: event buffer dashboard menyimpan entri terstruktur (`time`, `level`, `event`, `message`, correlation ID), tetap terbatas 300 entri.
- **REQ-060**: kejadian "dilewati" (E-08/E-12) menaikkan counter metrik per alasan (§4.3).

**E-W4 — Ticket 14: Metrics & health (GW-20, GW-21):**

- **REQ-061**: registri metrik in-process (counter/gauge, §4.3), termasuk counter per alasan drop; diekspos `GET /api/metrics` JSON + ringkasan di heartbeat; tanpa dependensi npm baru.
- **REQ-062 (CB-02)**: model kesehatan dengan rumus eksplisit — `send_ready` = socket connected AND probe segar; `receive_ready` = probe segar OR pesan masuk dalam `HEALTH_INBOUND_STALE_MS` (bawaan 600000 ms), **`unknown`** bila tidak ada bukti sama sekali; `delivery_ready` = `last_delivery_ok_at` segar AND gauge di bawah ambang warn. `verdict` = `degraded` bila ada alasan non-kosong, `unknown` bila bukti tidak cukup & probe nonaktif, selain itu `healthy`.
- **REQ-063 (CB-05)**: probe reachability terjadwal (`HEALTH_PROBE_INTERVAL_MS` 300000 ms, timeout 2000 ms); `socket_status` mentah tanpa debounce; `status` (verdict debounced) turun ke `disconnected` **hanya setelah `HEALTH_PROBE_FAILURES_THRESHOLD` (bawaan 2) kegagalan berturut-turut** — bukan kegagalan pertama.
- **REQ-064**: perubahan heartbeat aditif; `status`/`phone`/`gateway_version` tetap ada; blok `health` tambahan.
- **REQ-065**: `GET /api/health` mengembalikan isi blok `health` yang sama dengan heartbeat.
- **REQ-066 (GW-21, CB-09 — GERBANG OI-002)**: handler `messages.update` mengaitkan ke `outgoing_operations` via `wa_message_id`, menyimpan `receipt_state`/`receipt_updated_at`/nilai mentah. **Record-only** — TIDAK menutup ASSUMPTION-009, TIDAK menyelesaikan `in_flight` ambigu. **MUST NOT dikunci/diimplementasikan sebelum OI-002 (verifikasi bentuk payload + tabel pemetaan status numerik → `receipt_state`) tertutup.**
- **REQ-067**: metrik/health MUST NOT memuat label berkardinalitas tak terbatas (`wa_message_id`/`operation_id` dilarang sebagai label).

**E-W5 — Ticket 15: Full reliability test matrix & kriteria keluar M1:**

- **REQ-068**: satu matriks uji memetakan **setiap** AC-001..AC-079 ke REQ sumber, level, otomatis/prosedur, bukti, ambang lulus.
- **REQ-069**: harness menjalankan seluruh skrip berurutan, keluar non-nol bila satu gagal; MUST NOT menyentuh `data/gateway.sqlite` produksi atau `auth/`.
- **REQ-070 (CB-10 — GERBANG LUNAK kriteria keluar M1)**: M1 boleh ditutup untuk semua AC yang dapat dijalankan sekarang; AC-049 dan bagian OI-001-dependent AC-047/AC-051 MUST dicatat sebagai **`OPEN CARRY-OVER`** dengan pemilik aksi, **TIDAK dihitung lulus**. Laporan matriks MUST memuat bagian **"carry-over terbuka"**.
- **REQ-071**: setiap real-run MUST menghasilkan decision log tertulis (angka mentah, batas bukti, tanpa klaim GW-11/GW-25/GW-21/ASSUMPTION-009 tertutup); bukti lama boleh dipakai ulang HANYA JIKA commit sumbernya leluhur HEAD final.

**Security & operasional:**

- **SEC-003**: instrumentasi/log/metrik/health MUST NOT membocorkan isi pesan, kredensial, token, nomor lengkap di luar `phone`, atau struktur `auth/`.
- **SEC-004**: `GET /api/metrics`/`GET /api/health` mengikuti kebijakan eksposur `GET /api/status` (loopback tanpa token); peringatan `isBoundToLan` bila dibind ke LAN.
- **SEC-005**: handler `messages.update` memperlakukan payload sebagai data tidak tepercaya — hanya membaca field dikenal, validasi bentuk, tidak eksekusi isi.

**Panduan:**

- **GUD-005**: seluruh ambang baru dapat diatur via env dengan bawaan spec; nilai tidak valid memakai bawaan (pola `toInt`/`toIntList`).
- **GUD-006**: output observabilitas cukup untuk mengukur sendiri keberhasilan AC Wave 3.
- **GUD-007**: penurunan kesehatan menyertakan alasan dapat ditindaklanjuti (`probe_failed`, `delivery_failing`, `overflow_high`, `pending_high`, `decrypt_failures_high`).

**Constraints:**

- **CON-011**: payload `POST /api/inbox/gateway/messages` MUST tidak berubah.
- **CON-012**: perubahan skema `incoming_queue`/`outgoing_operations` hanya additive (`ALTER TABLE ... ADD COLUMN`).
- **CON-013**: kontrak respons `/send`/`/send-media`/`/media/download` tetap kompatibel; `receipt_state`+observabilitas aditif.
- **CON-014**: tidak ada dependensi npm baru; `auth/` dan Baileys 6.7.24 tidak disentuh.
- **CON-015**: Wave 3 MUST NOT mengubah kode AuliaPos.
- **CON-016**: Gateway tetap tidak menyimpan state bisnis/berkas media.
- **CON-017 (plan-level)**: tidak ada `git checkout`/`git reset` pada folder live `C:\projects\WA-Gateway` kecuali `merge --ff-only` sekali di TASK-031 (pola CON-011/TASK-022 gelombang 2).
- **CON-018 (plan-level)**: setiap task VERIFY wajib menjalankan ulang seluruh skrip uji fase sebelumnya **dan** seluruh skrip gelombang 1–2 (regresi kumulatif), tanpa menambah suppression/skip (Floor-Guard).
- **CON-019 (plan-level)**: TASK-038 (eksekusi protokol nyata Ticket 05) dan TASK-043 (eksekusi matriks Ticket 15) MUST NOT dijalankan tanpa persetujuan eksplisit user, karena keduanya menyentuh Gateway/instance uji hidup.

## 2. Implementation Steps

> **EXECUTION DIRECTIVE FOR AI AGENTS:**
> Eksekusi plan ini fase demi fase. Kode **hanya** ditulis di worktree `C:\projects\WA-Gateway-m1w3` (branch `feature/m1-wave3-reliability-observability`). Satu-satunya pengecualian adalah **TASK-031 (DEPLOY)**: `merge --ff-only` satu kali ke folder live `C:\projects\WA-Gateway` + `pm2 restart wa-gateway`.
> Jangan pernah `git checkout`/`git reset` di folder live, jangan menyentuh `auth/`, jangan menjalankan `npm run dev` pada Gateway yang sedang aktif, dan jangan menulis ke `data/gateway.sqlite` produksi (CON-017, CON-014, spec §9).
> Jalankan task **VERIFY** di akhir tiap fase, lalu **BERHENTI DAN TUNGGU** persetujuan eksplisit user sebelum masuk fase berikutnya. Satu task = satu commit kecil (pola gelombang 1–2). Setiap task kode MUST menyertakan ujinya pada penambahan yang sama (Micro-level Testing Mandate).
> **Pagar wajib yang mengikat seluruh eksekusi:** (1) OI-002 adalah TASK-019, tugas pertama Ticket 14, gerbang keras sebelum REQ-066 (TASK-027/028); (2) OI-001/D-14 adalah gerbang TASK-038 (eksekusi protokol nyata Ticket 05); (3) Gateway-only (CON-015) — Ticket 06–11/16 tidak dispesifikasikan ulang; (4) JANGAN memperbaiki akar GW-11/GW-25 — hanya ukur/bedakan H1/H2; (5) TASK-041/043 wajib memuat bagian **"carry-over terbuka"** (REQ-070).

**Grafik dependensi (bottom-up; `Dep` hanya menunjuk task yang sudah dijadwalkan sebelumnya):**

- `[GW]` TASK-001 (worktree + branch) → TASK-002 (config §4.7 + `GROUP_NAME_CACHE_MAX_ENTRIES`) → TASK-003 (metrik registry) → TASK-004 VERIFY → TASK-005 APPROVAL.
  - TASK-002 → TASK-006 (lock satu instance) · TASK-007 (single-flight + isolasi error) → TASK-008 (anggaran waktu + FIFO/overflow) → TASK-009 (drain + tick) · TASK-010 (invariant `heartbeat`) · TASK-003 → TASK-011 (batas in-memory + gauge) → TASK-012 VERIFY → TASK-013 APPROVAL.
  - TASK-002 → TASK-014 (logging field/level) → TASK-015 (redaksi) · TASK-003 + TASK-014 → TASK-016 (buffer terstruktur + drop counter) → TASK-017 VERIFY → TASK-018 APPROVAL.
  - TASK-001 → TASK-019 (**OI-002 gerbang**) · TASK-003 + TASK-011 → TASK-020 (health model) → TASK-021 (probe + `socket_status`) · TASK-020 → TASK-023 (heartbeat health aditif) · TASK-021 → TASK-022 (endpoint + gauge) → TASK-024 (kardinalitas/isi) → TASK-025 VERIFY → TASK-026 APPROVAL.
  - TASK-019 → TASK-027 (kolom `receipt_*` + store) → TASK-028 (handler `messages.update` + counter + replay) → TASK-029 VERIFY → TASK-030 APPROVAL.
- Fase 6: TASK-026 + TASK-030 → TASK-031 (DEPLOY `[GW]`) → TASK-032 APPROVAL.
- `[GW]` Fase 7: TASK-033 (**GERBANG OI-001/D-14**, aksi pemilik) · TASK-034 (instrumentasi) · TASK-035 (harness crash/restart) · TASK-036 (isolasi) · TASK-037 (harness H1/H2) · TASK-033 → TASK-038 (eksekusi protokol nyata + decision log) → TASK-039 VERIFY → TASK-040 APPROVAL.
- Fase 8: TASK-038 + TASK-041 (artefak matriks) → TASK-042 (harness runner) → TASK-043 (run matriks + carry-over) → TASK-044 VERIFY → TASK-045 APPROVAL.
- Fase 9: TASK-045 → TASK-046 (ARCHITECTURE.md + handoff).

### Implementation Phase 1 — Fondasi: worktree, konfigurasi, primitif metrik

- GOAL-001: Lingkungan kerja terisolasi siap, seluruh ambang §4.7 dapat diatur lewat env, dan registri metrik in-process (primitif lintas-potong untuk Ticket 12/13/14) tersedia tanpa dependensi npm baru. Penempatan `metrics.js` di Fondasi mencegah dependency inversion (dipakai worker, logging, dan health); OI-002 tetap menjadi tugas pertama Ticket 14 di Fase 4.

| Task | Repo | Description | Ref ID | AC Ref | Dep | Files | Completed | Date |
|---|---|---|---|---|---|---|---|---|
| TASK-001 | GW | Siapkan lingkungan kerja: `git -C C:\projects\WA-Gateway worktree add C:\projects\WA-Gateway-m1w3 -b feature/m1-wave3-reliability-observability <HEAD-master-terkini>`. Verifikasi `git -C C:\projects\WA-Gateway status --short` kosong dan folder live tidak tersentuh; `git worktree list` menampilkan worktree baru; `npm ci` di worktree sukses dan `require('better-sqlite3')` jalan di Node 20; catat SHA master nyata (read-only, diverifikasi saat task ini) sebagai **titik rollback** di decision log baru (`docs/decisions/`, repo AuliaPos). Jangan menyentuh `auth/` maupun `data/`. | Spec §7, CON-014, CON-017 | - | - | 0 (XS) | | |
| TASK-002 | GW | Konfigurasi: di `src/config/index.js` tambah seluruh variabel §4.7 dengan bawaan spec + clamp minimum (kecuali boolean `0`/bukan-`0`) mengikuti pola `Math.max`/`toInt` yang ada: `WA_DIAG_RAW_MESSAGE=0`, `SHUTDOWN_DRAIN_MS=5000`, `DELIVERY_EVENT_TIMEOUT_MS=8000`, `HEARTBEAT_SEND_TIMEOUT_MS=8000`, `METRICS_ENABLED=1`, `HEALTH_PROBE_ENABLED=1`, `HEALTH_PROBE_INTERVAL_MS=300000`, `HEALTH_PROBE_TIMEOUT_MS=2000`, `HEALTH_PROBE_FRESH_MS=600000`, `HEALTH_PROBE_FAILURES_THRESHOLD=2`, `HEALTH_INBOUND_STALE_MS=600000`, `HEALTH_PENDING_WARN=100`, `HEALTH_OVERFLOW_WARN=50`, `HEALTH_DECRYPT_FAILURE_THRESHOLD=5`, `HEALTH_DECRYPT_WINDOW_MS=300000`, `LOG_LEVEL=info`, **plus `GROUP_NAME_CACHE_MAX_ENTRIES=500`** (wajib oleh REQ-053/AC-058 walau absen dari tabel §4.7 — lihat RISK-001). Sertakan uji `test/simulate-config-wave3.js` (bawaan + clamp + nilai tidak valid). | GUD-005, REQ-053, CB-13 | - | TASK-001 | 1 (S) | | |
| TASK-003 | GW | Primitif metrik: buat `src/observability/metrics.js` (ASSUMPTION-014) dengan `inc(name, labels, delta)`, `set(name, value, labels)`, `snapshot()`, dan `recordDrop(reason)` yang memvalidasi `reason` terhadap enum §2 dan menaikkan `incoming_dropped_total{reason}`; label hanya dari himpunan tertutup (REQ-067). Registri in-process, reset saat restart (batasan dicatat). Sertakan uji `test/simulate-metrics.js` (counter/gauge, enum reason ditolak bila tidak dikenal, snapshot JSON). | REQ-060, REQ-061, REQ-067, ASSUMPTION-014 | - | TASK-002 | 1 (S) | | |
| TASK-004 | - | **VERIFY**: jalankan `node test/simulate-config-wave3.js` dan `node test/simulate-metrics.js` → 0 gagal. Wajib: (a) **guard statis** yang gagal bila ada skrip uji menulis ke `data/gateway.sqlite` (pola gelombang 1); (b) jalankan ulang seluruh `test/simulate-*.js` gelombang 1–2 (regresi kumulatif, CON-018); (c) periksa tidak ada dependensi npm baru (`package.json`/`package-lock.json` tidak berubah, CON-014). | - | CON-014, CON-018 | TASK-003 | 1 (S) | | |
| TASK-005 | - | **APPROVAL**: tunggu konfirmasi eksplisit user sebelum lanjut ke Fase 2. | - | - | - | - | | |

### Implementation Phase 2 — Ticket 12: Worker correctness (E-W2)

- GOAL-002: Ketiga worker Gateway memiliki invariant kebenaran yang terverifikasi: satu instance saja, single-flight, isolasi error per event/siklus, anggaran waktu berlapis, urutan FIFO, drain shutdown yang aman, dan seluruh struktur in-memory terbatas serta terlihat.

| Task | Repo | Description | Ref ID | AC Ref | Dep | Files | Completed | Date |
|---|---|---|---|---|---|---|---|---|
| TASK-006 | GW | Guard satu instance (REQ-052/CB-04): buat helper lock (mis. `src/store/instanceLock.js`) yang menulis lock file berisi **PID + timestamp start** pada path database, memperbarui **heartbeat berkala**, dan pada start memeriksa (a) PID pemilik hidup langsung ke OS (`process.kill(pid, 0)`/setara Windows) dan (b) kesegaran heartbeat. PID mati **atau** heartbeat basi → **ambil alih otomatis** lalu lanjut start; PID hidup + heartbeat segar → tolak start dengan pesan jelas + keluar non-nol. Wire di `src/app/index.js`. MUST bekerja identik di Windows dan fallback JSON (Android) tanpa dependensi baru. Sertakan `test/simulate-instance-lock.js` (PID hidup+tolak, PID mati+takeover, heartbeat basi+takeover). | REQ-052 | AC-057 | TASK-002 | 2 (S) | | |
| TASK-007 | GW | `src/delivery/incomingDelivery.js` — single-flight + isolasi error per event: guard `isRunning` selalu dilepas di akhir siklus (termasuk saat error); tick tumpang tindih dilewati tanpa antre (REQ-047); pemrosesan satu event dibungkus try/catch per-event sehingga `JSON.parse`/error satu event tidak membatalkan batch — event gagal ditandai `markFailedAttempt()` dan dicatat, loop lanjut (REQ-048). Sertakan `test/simulate-worker-singleflight.js`. | REQ-047, REQ-048 | AC-052, AC-053 | TASK-002 | 1 (S) | | |
| TASK-008 | GW | `src/delivery/incomingDelivery.js` — anggaran waktu berlapis + FIFO: `DELIVERY_EVENT_TIMEOUT_MS` (8000 ms) **hanya** membungkus `postToCI4()`; pertahankan batas LID (2 dtk) dan media (`mediaDownloadTimeoutMs`, 6 dtk); tulis komentar eksplisit batas nyata terburuk **±16000 ms** dan hubungannya dengan `SHUTDOWN_DRAIN_MS` (REQ-049, Kasus 9); pelanggaran dicatat, event diperlakukan gagal sementara. Proses event `id ASC`; kuras overflow **sebelum** pengambilan event jatuh tempo tanpa menahan batch (REQ-050). Tambah cabang uji pada `test/simulate-worker-singleflight.js`. | REQ-049, REQ-050 | AC-054, AC-055 | TASK-007 | 1 (M) | | |
| TASK-009 | GW | Drain shutdown + observabilitas tick: `incomingDelivery.stop()` mencegah tick baru dan menunggu batch berjalan dalam `SHUTDOWN_DRAIN_MS` (5000 ms); wiring `app/index.js` `shutdown()` menunggu drain sebelum menutup proses; event belum `completed` tetap tersimpan dan dijadwalkan ulang (REQ-051). Setiap tick mencatat jumlah diproses/berhasil/gagal + durasi lewat entri `worker.tick`, dan error tak terduga di dalam tick tertangkap + tercatat tanpa mematikan proses (REQ-054). Sertakan `test/simulate-worker-drain.js`. | REQ-051, REQ-054 | AC-056, AC-059 | TASK-008 | 2 (M) | | |
| TASK-010 | GW | `src/delivery/heartbeat.js` — invariant minimal worker `heartbeat` (CB-11): single-flight dengan guard `isRunning` yang selalu dilepas di akhir siklus dan tick tumpang tindih dilewati (REQ-072); isolasi error per siklus—kegagalan `POST /api/inbox/gateway/status` dicatat dan tidak menghentikan jadwal berikutnya (REQ-073); anggaran waktu `HEARTBEAT_SEND_TIMEOUT_MS` (8000 ms)—panggilan tidak ditunggu tanpa batas, pelanggaran dicatat sebagai gagal sementara (REQ-074). Sertakan `test/simulate-heartbeat.js` (stub `postStatusToCI4`: menggantung/gagal/sukses). | REQ-072, REQ-073, REQ-074 | AC-077, AC-078, AC-079 | TASK-002 | 1 (S) | | |
| TASK-011 | GW | Batas struktur in-memory (REQ-053/CB-03): tegakkan cap pada overflow (`ENQUEUE_OVERFLOW_MAX`), daftar own-sent (`OWN_SENT_MAX`), **cache nama grup** (`GROUP_NAME_CACHE_MAX_ENTRIES`), dan event buffer dashboard (300). Daftarkan gauge `overflow_size`, `group_name_cache_size`, `event_buffer_size` ke `metrics.js` (TASK-003) — eksposur JSON di endpoint dilakukan TASK-022. Sertakan `test/simulate-memory-limits.js` (isi penuh → kejadian berikutnya tidak menambah, gauge mencerminkan). | REQ-053 | AC-058 (bagian batas) | TASK-003, TASK-007 | 3 (M) | | |
| TASK-012 | - | **VERIFY**: jalankan `test/simulate-instance-lock.js`, `test/simulate-worker-singleflight.js`, `test/simulate-worker-drain.js`, `test/simulate-heartbeat.js`, `test/simulate-memory-limits.js` → 0 gagal (AC-052..AC-059, AC-077..AC-079). Wajib: (a) uji AC-057 di **Windows** dan fallback JSON (bila tersedia); (b) assert AC-049/AC-054 bahwa `postToCI4` dihentikan pada 8000 ms dan durasi total mendekati ±16000 ms (bukan 8000 ms); (c) semua skrip memakai SQLite folder temp, tidak menulis `data/gateway.sqlite`; (d) regresi kumulatif Fase 1 + gelombang 1–2 (CON-018). | - | CON-018 | TASK-009, TASK-010, TASK-011 | 1 (S) | | |
| TASK-013 | - | **APPROVAL**: tunggu konfirmasi eksplisit user sebelum lanjut ke Fase 3. | - | - | - | - | | |

### Implementation Phase 3 — Ticket 13: Structured logging (E-W3)

- GOAL-003: Seluruh `src/` mencatat lewat satu logger dengan skema field, katalog event, dan kebijakan level yang seragam; log lama tetap dipertahankan; konten sensitif teredaksi; buffer dashboard terstruktur; kejadian "dilewati" terukur sebagai metrik.

| Task | Repo | Description | Ref ID | AC Ref | Dep | Files | Completed | Date |
|---|---|---|---|---|---|---|---|---|
| TASK-014 | GW | `src/logging/index.js` — skema field + kebijakan level: setiap entri penting memuat `event` (nama bertitik, Inggris, katalog §4.5) dan `component`; correlation ID (`wa_message_id`/`operation_id`/`chat_id`) bila relevan; **teks `msg` lama MUST dipertahankan** (aditif, REQ-056). Kebijakan level: `debug` (noise Baileys frekuensi tinggi), `info` (transisi/keberhasilan), `warn` (kegagalan dapat dicoba ulang/dilewati), `error` (berisiko kehilangan data); keadaan terminal menulis awalan literal `[CRITICAL]` **dan** `severity:'critical'` (REQ-057). Sertakan `test/simulate-logging-schema.js` (destination in-memory: JSON valid per baris, `event`/`component` ada, teks lama ada, `[CRITICAL]`+`severity`). | REQ-055, REQ-056, REQ-057 | AC-060, AC-061, AC-062 | TASK-002 | 1 (S) | | |
| TASK-015 | GW | `src/logging/index.js` — redaksi diperluas (REQ-058/SEC-003): `REDACT_PATHS` mencakup header `Authorization`, objek kredensial/token, `media_base64`, dan isi teks pesan; log MUST aman dibagikan pihak ketiga tanpa penyuntingan. Tambah uji pemindaian: kirim payload uji berisi teks + `media_base64` + `Authorization`, assert tidak ada yang muncul di output log. | REQ-058, SEC-003 | AC-063 | TASK-014 | 1 (S) | | |
| TASK-016 | GW | `src/logging/index.js` + `src/whatsapp/connectionManager.js` — buffer dashboard terstruktur & counter drop: event buffer (300 entri) menyimpan entri terstruktur minimal (`time`, `level`, `event`, `message`, correlation ID), tetap ≤300 (REQ-059, AC-064). Kejadian "dilewati" E-08/E-12 (`status@broadcast`, alamat non-pelanggan, referensi media tidak lengkap, upsert tanpa konten, tipe tak didukung) MUST menaikkan `incoming_dropped_total{reason}` via `recordDrop()` (REQ-060, AC-065). Sertakan `test/simulate-logging-buffer-drops.js`. | REQ-059, REQ-060 | AC-064, AC-065 | TASK-003, TASK-014 | 2 (M) | | |
| TASK-017 | - | **VERIFY**: jalankan `test/simulate-logging-schema.js`, `test/simulate-logging-redaction.js` (bila dipisah dari TASK-015), dan `test/simulate-logging-buffer-drops.js` → 0 gagal (AC-060..AC-065). Wajib: (a) **guard statis** bahwa tidak ada `console.*` di `src/` di luar jalur fatal start-up (AC-060); (b) pemindaian berkas log nyata hasil uji: JSON valid per baris, `grep` teks pesan uji + `media_base64` + `Authorization` = 0 hasil (AC-063); (c) `GET /api/events` ≤300 entri (bila endpoint ada) atau assert buffer langsung (AC-064); (d) regresi kumulatif Fase 1–2 + gelombang 1–2 (CON-018). | - | CON-018 | TASK-015, TASK-016 | 1 (S) | | |
| TASK-018 | - | **APPROVAL**: tunggu konfirmasi eksplisit user sebelum lanjut ke Fase 4. | - | - | - | - | | |

### Implementation Phase 4 — Ticket 14: Metrics & health (GW-20), gerbang OI-002 (E-W4)

- GOAL-004: Kesehatan Gateway diukur jujur lewat probe aktif (bukan sekadar status socket), diekspos di `GET /api/health`/`GET /api/metrics` dan heartbeat aditif; **OI-002 ditutup sebagai tugas pertama ticket ini**, sebelum satu baris kode REQ-066 (GW-21) ditulis.

| Task | Repo | Description | Ref ID | AC Ref | Dep | Files | Completed | Date |
|---|---|---|---|---|---|---|---|---|
| TASK-019 | GW | **GERBANG OI-002 — TUGAS PERTAMA Ticket 14, wajib selesai sebelum TASK-027/028.** Verifikasi bentuk payload nyata yang dipancarkan `sock.ev.on('messages.update')` pada Baileys 6.7.24 (baca sumber `node_modules/baileys` + jalankan pengamatan pada instance uji/live sesuai kebijakan operator — **read-only, tidak mengubah kode produksi**), lalu tulis **tabel pemetaan eksplisit** dari status numerik/enum yang diamati ke `receipt_state` (`pending`/`sent`/`delivered`/`read`/`failed`) di `docs/decisions/` (repo AuliaPos, sesuai pola gelombang sebelumnya) atau catatan setara di worktree Gateway. Bila bentuk payload tidak sesuai dugaan spec §4.6, catat deviasinya secara eksplisit — REQ-066/AC-071/§4.6 MUST direvisi berdasarkan temuan ini sebelum TASK-027/028 dimulai, bukan ditambal setelahnya. **Task ini TIDAK menulis kode Gateway apa pun** — hanya observasi + dokumentasi pemetaan. | OI-002 (§1.2), REQ-066 (gerbang) | AC-071 (prasyarat) | TASK-001 | 1 (S) | | |
| TASK-020 | GW | `src/observability/health.js` (baru) — model kesehatan dengan rumus eksplisit (REQ-062/CB-02): `send_ready` = `socket_status='connected'` AND `last_probe_ok_at` segar dalam `HEALTH_PROBE_FRESH_MS`; `receive_ready` = probe segar OR pesan masuk tersimpan dalam `HEALTH_INBOUND_STALE_MS` — **`unknown`** (bukan `false`) bila tidak ada bukti sama sekali; `delivery_ready` = `last_delivery_ok_at` segar AND gauge `pending`/`overflow` di bawah `HEALTH_PENDING_WARN`/`HEALTH_OVERFLOW_WARN`. `verdict` = `degraded` bila `degraded_reasons` non-kosong, `unknown` bila bukti tidak cukup & probe nonaktif, selain itu `healthy`. Tambah counter `decrypt_failure_total` dan gauge `decrypt_failures_recent` (ambang `HEALTH_DECRYPT_FAILURE_THRESHOLD` dalam `HEALTH_DECRYPT_WINDOW_MS`) sebagai alasan degradasi (`decrypt_failures_high`, GUD-007). Fungsi murni, diinjeksi snapshot (tanpa jaringan). Sertakan `test/simulate-health-model.js` (semua kombinasi rumus, termasuk Kasus 7 probe nonaktif). | REQ-062 | AC-067 | TASK-003, TASK-011 | 2 (M) | | |
| TASK-021 | GW | `src/observability/probe.js` (baru) — probe reachability terjadwal (`HEALTH_PROBE_INTERVAL_MS`, timeout `HEALTH_PROBE_TIMEOUT_MS`) via `sock.onWhatsApp()` ke nomor Gateway sendiri; ekspos `socket_status` mentah dari `connectionManager` tanpa debounce apa pun. Heartbeat mengirim `status='connected'` **hanya** bila socket terhubung **dan** probe segar; `status` (verdict debounced) turun ke `disconnected` **hanya setelah `HEALTH_PROBE_FAILURES_THRESHOLD` (bawaan 2) kegagalan/kebasian berturut-turut** — bukan kegagalan pertama (REQ-063/CB-05, Kasus 3). Catat `health_probe_total{outcome}`. Sertakan `test/simulate-health-probe-debounce.js` (1 kegagalan → tetap `connected`; N berturut → turun; sukses → pulih). | REQ-063 | AC-068 | TASK-020 | 2 (M) | | |
| TASK-022 | GW | `src/api/routes.js` — endpoint `GET /api/health` (isi blok `health` identik heartbeat, REQ-065) dan `GET /api/metrics` (semua counter/gauge §4.3 sebagai JSON, termasuk `group_name_cache_size`/`event_buffer_size` dari TASK-011, REQ-061); keduanya mengikuti kebijakan eksposur `GET /api/status` (loopback tanpa token, SEC-004) dan peringatan `isBoundToLan` bila relevan. Sertakan `test/simulate-api-observability.js` via `createServer()` tanpa `listen()` (Testing Seam 5). | REQ-061, REQ-065, SEC-004 | AC-066, AC-070, AC-058 (eksposur gauge) | TASK-011, TASK-021 | 2 (M) | | |
| TASK-023 | GW | `src/delivery/heartbeat.js` — sisipkan blok `health` aditif (REQ-064) ke payload heartbeat: `status`/`phone`/`gateway_version` tetap ada dengan arti lama; blok `health` berisi field REQ-062 lengkap. Verifikasi manual AuliaPos lama yang hanya membaca `status` tetap bekerja (kompatibilitas mundur). Tambah cabang uji pada `test/simulate-heartbeat.js` (TASK-010). | REQ-064 | AC-069 | TASK-020, TASK-021 | 1 (S) | | |
| TASK-024 | GW | Guard kardinalitas & kebersihan (REQ-067/SEC-003): audit `metrics.js`/`health.js`/endpoint bahwa **tidak ada** label `wa_message_id`/`operation_id` pada metrik dan tidak ada isi pesan di `GET /api/health`/`GET /api/metrics` (Kasus 5). Tambah guard statis/uji regex yang gagal bila label terlarang ditemukan. | REQ-067, SEC-003 | AC-072 | TASK-022 | 1 (S) | | |
| TASK-025 | - | **VERIFY**: jalankan `test/simulate-health-model.js`, `test/simulate-health-probe-debounce.js`, `test/simulate-api-observability.js`, cabang heartbeat TASK-023 → 0 gagal (AC-066..AC-070, AC-072). Wajib: (a) verifikasi debounce CB-05 eksplisit (1 kegagalan → `connected`; N berturut → `disconnected`; `socket_status` mentah tak berubah, pola §13 butir 3); (b) verifikasi Kasus 7 (probe nonaktif + toko sepi → `unknown`, bukan `healthy`/`false`); (c) guard kardinalitas TASK-024 lulus; (d) regresi kumulatif Fase 1–3 + gelombang 1–2 (CON-018). | - | CON-018 | TASK-023, TASK-024 | 1 (S) | | |
| TASK-026 | - | **APPROVAL**: tunggu konfirmasi eksplisit user sebelum lanjut ke Fase 5 (REQ-066/GW-21, dependen OI-002 TASK-019). | - | - | - | - | | |

### Implementation Phase 5 — Ticket 14: Kabar status pengiriman GW-21 (record-only, gerbang OI-002)

- GOAL-005: Gateway mencatat kabar status pengiriman (`messages.update`) ke `outgoing_operations` sebagai **record-only** — sesuai pemetaan OI-002 yang ditutup di TASK-019 — tanpa mengklaim menutup ASSUMPTION-009 dan tanpa menjadi konsumen receipt apa pun.

| Task | Repo | Description | Ref ID | AC Ref | Dep | Files | Completed | Date |
|---|---|---|---|---|---|---|---|---|
| TASK-027 | GW | `src/store/outgoingOperations.js` — tambah kolom `receipt_state`, `receipt_updated_at`, `receipt_raw` lewat pola migrasi ringan yang sudah ada (`PRAGMA table_info` + `ALTER TABLE ADD COLUMN`, aman diulang, CON-012); nilai `receipt_state` sah: `pending` (bawaan)/`sent`/`delivered`/`read`/`failed`. Tambah method `markReceipt(waMessageId, { receiptState, raw })`. **MUST memakai tabel pemetaan hasil TASK-019**, bukan asumsi baru. Sertakan `test/simulate-receipt-store.js`. | REQ-066 (skema), CON-012 | AC-071 (bagian skema) | TASK-019 | 1 (S) | | |
| TASK-028 | GW | `src/whatsapp/connectionManager.js` — daftarkan handler `messages.update` (SEC-005: payload tidak tepercaya, hanya field dikenal, tidak eksekusi isi) yang mengaitkan ke baris `outgoing_operations` via `wa_message_id` dan memanggil `markReceipt()` sesuai **tabel pemetaan OI-002** (TASK-019); bila bentuk payload tidak dikenali → `warn`, `receipt_state` **tidak dikarang**; tidak ada kabar → tetap `pending`. Tambah `outgoing_receipt_total{state}` counter (TASK-003). Respons replay `/send`/`/send-media` (di `ci4Routes.js`) menambahkan field aditif `receipt_state` bila tersedia (CON-013). Tulis komentar eksplisit: handler ini **hanya mencatat**, tidak menyelesaikan `in_flight` ambigu, tidak menutup ASSUMPTION-009 (CB-09). Sertakan `test/simulate-receipt-handler.js` (payload dikenal/tidak dikenal/tanpa kabar, replay memuat `receipt_state`). | REQ-066 | AC-071 | TASK-027 | 2 (M) | | |
| TASK-029 | - | **VERIFY**: jalankan `test/simulate-receipt-store.js`, `test/simulate-receipt-handler.js` → 0 gagal (AC-071). Wajib: (a) assert eksplisit bahwa tidak ada kode yang menyelesaikan operasi `in_flight` berdasarkan receipt (guard statis/review manual dicatat); (b) assert `outgoing_receipt_total` bertambah sesuai state; (c) `receipt_state` field aditif muncul di respons replay tanpa mengubah field lama (CON-013); (d) regresi kumulatif Fase 1–4 + gelombang 1–2 (CON-018). | - | CON-018, CON-013 | TASK-028 | 1 (S) | | |
| TASK-030 | - | **APPROVAL**: tunggu konfirmasi eksplisit user sebelum lanjut ke Fase 6 (DEPLOY). | - | - | - | - | | |

### Implementation Phase 6 — Deploy terukur

- GOAL-006: Seluruh kode Ticket 12–14 berjalan di folder live Gateway, dengan titik rollback tercatat dan verifikasi awal endpoint observabilitas pada Gateway nyata.

| Task | Repo | Description | Ref ID | AC Ref | Dep | Files | Completed | Date |
|---|---|---|---|---|---|---|---|---|
| TASK-031 | GW | **DEPLOY**: pola TASK-022 gelombang 2. (a) `git -C C:\projects\WA-Gateway status --short` MUST kosong sebelum mulai; (b) catat SHA HEAD saat ini (dari TASK-001) sebagai titik rollback; (c) `git -C C:\projects\WA-Gateway merge --ff-only feature/m1-wave3-reliability-observability`; (d) `cmd /c "pm2 restart wa-gateway"` lalu `cmd /c "pm2 describe wa-gateway"` → status `online`; (e) panggil `GET /api/health` dan `GET /api/metrics` pada Gateway live, verifikasi respons terbentuk sesuai §4.2/§4.3; (f) periksa log start: guard satu instance (TASK-006) tidak menolak start yang sah, dan tidak ada regresi pada pemulihan `in_flight` gelombang 2. TASK-031 MUST NOT memakai `git checkout`/`git reset` dan MUST NOT menyentuh `auth/`. Catat HEAD sebelum/sesudah, waktu, status PM2, dan snapshot health/metrics awal di decision log baru (`docs/decisions/`). | Spec §7, CON-017 | AC-057, AC-066, AC-070 (bukti nyata) | TASK-026, TASK-030 | 0 (XS) | | |
| TASK-032 | - | **APPROVAL**: tunggu konfirmasi eksplisit user bahwa deploy berhasil dan stabil sebelum lanjut ke Fase 7 (Ticket 05). | - | - | - | - | | |

### Implementation Phase 7 — Ticket 05: Crash/restart & uji pembeda dekripsi (E-W1)

- GOAL-007: Protokol crash/restart dan uji pembeda H1/H2 tersedia sebagai harness, dijalankan pada kondisi terkontrol dengan instance uji terisolasi (D-14), lalu menghasilkan **pengukuran** + decision log berbatas jujur — tanpa memperbaiki akar GW-11/GW-25 dan tanpa klaim perbaikan.
- **Pagar:** kode instrumentasi/harness (TASK-034..037) **boleh ditulis lebih dulu** (dikonfirmasi user); **TASK-038 (eksekusi protokol nyata) adalah satu-satunya task yang bergantung gerbang OI-001/D-14** (TASK-033). Selama OI-001 belum tersedia, AC-047..051 tetap `BLOCKED`/`OPEN CARRY-OVER` (REQ-070).

| Task | Repo | Description | Ref ID | AC Ref | Dep | Files | Completed | Date |
|---|---|---|---|---|---|---|---|---|
| TASK-033 | Owner | **GERBANG OI-001/D-14 (aksi pemilik, bukan kode)**: sediakan **dua** pemasok luar — (a) **akun WhatsApp ketiga** sebagai instance Gateway uji dengan sesi bersih (`auth/` baru, satu kali scan QR; **bukan** salinan folder `auth/` nomor aktif mana pun), dan (b) **nomor kontak uji** yang belum pernah menerima `/send` maupun pesan dari Gateway mana pun (nomor lama `628563324637` tidak memenuhi syarat karena riwayat sesinya sudah tercemar). Nomor Gateway produksi `6281913500707` **tidak pernah** dipakai sebagai pengirim Arm H1. Catat nilai/token di luar repo (DAT-002; MUST NOT di-hardcode). Status task ini `BLOCKED` sampai pemilik menyediakan keduanya. **Dep eksklusif TASK-038.** | OI-001/D-14 (§1.2), DAT-002 | - | - | 0 (XS) | | |
| TASK-034 | GW | Instrumentasi diagnostik read-only (REQ-042/ASSUMPTION-017): aktif hanya bila `WA_DIAG_RAW_MESSAGE=1`; rekam per pesan `wa_message_id`, `type` (`notify`/`append`), alamat asal + `jid_type`, ada/tidaknya `msg.message`, `messageStubType`, stempel waktu mentah Baileys, waktu tiba lokal, jumlah retry dekripsi, dan penanda `/send` ke alamat `pn` kontak pada sesi berjalan. MUST NOT mengubah jalur pemrosesan, MUST NOT mencatat isi pesan/nama kontak, MUST NOT menyentuh `auth/`. Sertakan `test/simulate-diag-raw-message.js` (flag `0` → tidak ada field diagnostik ditulis — **bagian testable sekarang**, AC-047a; flag `1` diuji dengan socket mock). | REQ-042, SEC-003, ASSUMPTION-017 | AC-047 (bagian non-OI-001) | TASK-002 | 2 (S) | | |
| TASK-035 | GW | Harness crash/restart (REQ-043): protokol terkontrol SIGKILL awal/tengah/akhir **dan** restart graceful, masing-masing ≥3 kali, dengan jumlah pesan kirim dicatat eksplisit; verifikasi pasca-restart: tidak ada pesan hilang di `incoming_queue`/AuliaPos, tidak ada duplikat (GW-24), pemulihan operasi `in_flight`, prune start-up berjalan. Rekam pasangan `message_timestamp` (Baileys) dan waktu tiba/insert per pesan (REQ-045). **Task ini menyiapkan harness + dry-run dengan DB temp/socket mock; bukti AC-048/AC-050 yang sebenarnya dihasilkan saat eksekusi TASK-038.** Sertakan `test/simulate-crash-restart-harness.js`. | REQ-043, REQ-045 | AC-048, AC-050 (harness) | TASK-002 | 2 (M) | | |
| TASK-036 | GW | Jaminan isolasi (REQ-046): harness Ticket 05 MUST memakai worktree/instance Gateway terpisah dan **database sementara** (bukan `data/gateway.sqlite` produksi), memakai akun ketiga sesi bersih (D-14), dan MUST NOT menghapus/mengubah `auth/` nomor aktif. Tambah guard statis + pemeriksaan eksplisit yang gagal bila harness menyentuh DB produksi atau `auth/`. Sertakan uji isolasi. | REQ-046 | AC-051 (bagian non-OI-001) | TASK-035 | 1 (S) | | |
| TASK-037 | GW | Harness uji pembeda H1 vs H2 (REQ-044/D-14): **Arm H1** mengirim `/send` **DARI instance/nomor uji** ke alamat `pn` kontak uji B, lalu B mengirim pesan masuk; ukur apakah muncul `SessionError: No matching sessions found for message` pada percobaan pertama. **Arm H2**: kill paksa tepat saat instance uji sedang mengenkripsi kiriman keluar; ukur error dekripsi pada pesan berikutnya. Tiap arm dijalankan pada sesi bersih untuk kontak itu, jumlah data + hasil dicatat; kesimpulan dinyatakan "H1 didukung/ditolak" / "H2 didukung/ditolak" / "belum konklusif" — **tidak boleh diklaim sebagai perbaikan**. Arm H1 MUST NOT memakai nomor produksi. Sertakan `test/simulate-h1-h2-harness.js` (kerangka + validasi parameter, tanpa eksekusi nyata). | REQ-044 | AC-049 (harness) | TASK-035 | 2 (M) | | |
| TASK-038 | GW | **EKSEKUSI PROTOKOL NYATA (mematikan/menjeda instance uji — butuh persetujuan eksplisit user, CON-019) — TERGANTUNG OI-001 (TASK-033).** Jalankan harness TASK-034..037 pada instance uji terisolasi: crash/restart ≥3× (AC-048), rekam pasangan timestamp (AC-050), pemeriksaan isolasi (AC-051), dan Arm H1/H2 (AC-049). Tulis **decision log** baru (`docs/decisions/`): angka mentah, kondisi pengukuran, batas bukti, dan pernyataan eksplisit bahwa GW-11/GW-25/GW-21 dan ASSUMPTION-009 **TIDAK** diklaim tertutup penuh (REQ-071). Bila OI-001 belum tersedia, task ini **tidak dijalankan** dan AC-047..051 tetap `OPEN CARRY-OVER` (REQ-070). | REQ-043, REQ-044, REQ-045, REQ-046, REQ-071 | AC-047, AC-048, AC-049, AC-050, AC-051 | TASK-033, TASK-034, TASK-035, TASK-036, TASK-037 | 1 (M) | | |
| TASK-039 | - | **VERIFY**: bila TASK-038 dijalankan, verifikasi decision log memuat angka mentah + batas bukti + tidak ada klaim perbaikan GW-11/GW-25/GW-21 (AC-047..AC-051, AC-076 sebagian). Bila TASK-038 belum dapat dijalankan (OI-001 belum tersedia), verifikasi bahwa AC-047..051 **tercatat eksplisit sebagai `OPEN CARRY-OVER`** di decision log/berkas matriks dan **tidak** dihitung lulus. Jalankan ulang regresi kumulatif Fase 1–6 + gelombang 1–2 (CON-018). | - | AC-047..AC-051, CON-018 | TASK-038 | 1 (S) | | |
| TASK-040 | - | **APPROVAL**: tunggu konfirmasi eksplisit user sebelum lanjut ke Fase 8 (matriks Ticket 15). | - | - | - | - | | |

### Implementation Phase 8 — Ticket 15: Matriks uji keandalan penuh & kriteria keluar M1 (E-W5)

- GOAL-008: Satu matriks menutup AC-001..AC-079 dengan level/otomatisasi/bukti/ambang, dijalankan oleh satu harness yang keluar non-nol bila gagal, dan laporan penutupan M1 yang memuat bagian **"carry-over terbuka"** (REQ-070) — tanpa klaim "M1 selesai penuh" selama carry-over ada.

| Task | Repo | Description | Ref ID | AC Ref | Dep | Files | Completed | Date |
|---|---|---|---|---|---|---|---|---|
| TASK-041 | GW | Artefak matriks (REQ-068): susun berkas matriks yang memetakan **setiap** AC-001..AC-079 ke REQ sumber, level (unit/integrasi/nyata), otomatis atau prosedur tertulis, bukti yang dihasilkan, dan ambang lulus. Gabungkan AC gelombang 1–2 (AC-001..AC-046) dan AC gelombang 3 (AC-047..AC-079) dalam satu tempat (menutup AC-073). **WAJIB memuat bagian "carry-over terbuka"** yang mendaftar AC yang belum dapat dijalankan (AC-049 dan bagian OI-001-dependent AC-047/AC-051) beserta **pemilik aksi** (pemilik proyek yang harus menyediakan OI-001) — REQ-070. | REQ-068, REQ-070 | AC-073 | TASK-038 | 1 (S) | | |
| TASK-042 | GW | Harness runner (REQ-069): buat `test/run-reliability-matrix.js` yang menjalankan seluruh skrip matriks secara berurutan dan **keluar non-nol** bila ada satu skrip gagal; MUST NOT menyentuh `data/gateway.sqlite` produksi maupun `auth/`. Sertakan `test/simulate-matrix-harness.js` (skenario skrip gagal → exit non-nol; skrip tidak menyentuh DB produksi). | REQ-069 | AC-074 | TASK-041 | 1 (S) | | |
| TASK-043 | GW | **Jalankan matriks + protokol real-run**: jalankan `node test/run-reliability-matrix.js` penuh; jalankan protokol ukur nyata yang tersisa (crash/restart, pemadaman, poison, receipt) sesuai spec §13; tulis **laporan matriks** + decision log dengan bagian **"carry-over terbuka"** yang eksplisit dan **tidak menghitung** AC-049/bagian OI-001-dependent AC-047/AC-051 sebagai lulus (REQ-070, AC-075). Pernyataan eksplisit bahwa GW-11/GW-25/GW-21/ASSUMPTION-009 tidak diklaim tertutup penuh (REQ-071, AC-076). Bila matriks memakai ulang bukti real-run lama, verifikasi commit sumbernya **leluhur HEAD final** — bila bukan, jalankan ulang. Butuh persetujuan eksplisit user (CON-019). | REQ-070, REQ-071 | AC-075, AC-076 | TASK-038, TASK-042 | 2 (M) | | |
| TASK-044 | - | **VERIFY**: verifikasi laporan matriks mencakup AC-001..AC-079 (AC-073), harness keluar `0` pada suite penuh (AC-074) dan non-nol saat skrip sengaja digagalkan, bagian "carry-over terbuka" ada + tidak menghitung item OI-001-dependent sebagai lulus (AC-075), dan tidak ada klaim penutupan GW-11/GW-25/GW-21/ASSUMPTION-009 (AC-076). Jalankan markdownlint pada artefak matriks (jenis temuan tidak boleh baru dibanding spec v1.1). | - | AC-073..AC-076 | TASK-043 | 1 (S) | | |
| TASK-045 | - | **APPROVAL**: tunggu konfirmasi eksplisit user bahwa matriks + kriteria keluar M1 diterima sebelum penutupan (Fase 9). | - | - | - | - | | |

### Implementation Phase 9 — Penutupan & handoff

- GOAL-009: Peta arsitektur evergreen, status plan difinalkan, dan handoff ke checkpoint SDLC berikutnya tanpa klaim penutupan yang tidak didukung.

| Task | Repo | Description | Ref ID | AC Ref | Dep | Files | Completed | Date |
|---|---|---|---|---|---|---|---|---|
| TASK-046 | GW + AP | Penutupan (Living Architecture Map Mandate + Handoff): (a) perbarui `docs/ARCHITECTURE.md` (repo AuliaPos — dokumentasi, bukan kode; CON-015 tidak dilanggar) dengan modul baru `src/observability/*`, kolom `receipt_*`, endpoint observabilitas, dan invariant worker Wave 3; (b) ubah front-matter plan ini `status: 'Planned'` → `'Completed'` dan badge; (c) tawarkan penyimpanan progres ke `.claude/instructions/memory.instructions.md` (`memory-manager`); (d) arahkan user ke `/sdlc-code-review` (spec + plan + diff), lalu `/sdlc-audit-consistency` (opsional), di **sesi baru**; (e) sampaikan eksplisit bahwa selama **carry-over terbuka** (AC-049, bagian OI-001-dependent AC-047/AC-051) belum tertutup, M1 **belum** dinyatakan selesai penuh (REQ-070). | Spec §7, REQ-070, CON-015 | - | TASK-045 | 2 (XS) | | |

## 3. Alternatives

- **ALT-001**: Menaruh `metrics.js` (registri metrik) di dalam Fase 4 (Ticket 14) sebagai ganti Fondasi — ditolak karena akan menimbulkan dependency inversion: TASK-011 (gauge REQ-053), TASK-016 (drop counter REQ-060), dan TASK-020 (health model REQ-062) membutuhkan registry yang belum ada. Registri tetap "milik" E-W4 secara konseptual, tetapi dibangun lebih dulu sebagai primitif lintas-potong; **OI-002 tetap tugas pertama Ticket 14** dan tetap menggerbangi REQ-066 (pagar wajib #1 tidak dilanggar).
- **ALT-002**: Menggabungkan crash/restart (AC-048) dan H1/H2 (AC-049) menjadi satu task besar Ticket 05 — ditolak (Task Sizing): keduanya menyentuh kondisi dan tujuan berbeda, dan memisahkannya memungkinkan AC-048/AC-050 dijalankan tanpa menunggu OI-001 bila kedepannya pemilik mengizinkan (saat ini keduanya tetap di TASK-038, gated OI-001).
- **ALT-003**: Membangun health dari status socket saja (tanpa probe) — ditolak (spec §10, GW-20): status socket terbukti menyesatkan (tetap `connected` selama burst penuh kegagalan dekripsi). Probe reachability + debounce CB-05 adalah inti GW-20.
- **ALT-004**: Menjadikan `messages.update` sebagai penutup jaminan idempotensi (menyelesaikan `in_flight` ambigu) — ditolak (ASSUMPTION-012/CB-09, CON-016): itu menarik M2 ke M1; Wave 3 hanya **mencatat** receipt (record-only) dan tidak mengklaim menutup ASSUMPTION-009.
- **ALT-005**: Memperbaiki akar GW-11/GW-25 di Wave 3 — ditolak (pagar wajib #4, spec §1.1): sumber `message_timestamp` dan penyebab kegagalan dekripsi berada di luar repo yang dapat diubah (ESC-001..004); Wave 3 hanya mengukur dan membedakan H1/H2.
- **ALT-006**: Menambah dependensi npm (mis. klien probe, exporter Prometheus, generator lock) — ditolak (CON-014): probe memakai `sock.onWhatsApp()` yang sudah ada, lock memakai fs + `process.kill`, metrik in-process.
- **ALT-007**: Menghapus/memparafrase teks log lama agar seragam — ditolak (REQ-056): runbook dan grep bergantung pada teks lama; perubahan bersifat aditif.
- **ALT-008**: Rotasi/retensi berkas log di Wave 3 — ditolak (ASSUMPTION-019): di luar scope observabilitas murni (menyentuh lifecycle proses); hanya didokumentasikan.
- **ALT-009**: Menjalankan uji Ticket 05 pada Gateway produksi — ditolak (REQ-046/D-14): MUST terisolasi; memakai instance uji dan akun ketiga, tanpa menyentuh `auth/` aktif.
- **ALT-010**: Menulis ulang `incomingDelivery.js`/`heartbeat.js` atau arsitektur worker (worker thread/antrean terpisah) — ditolak (ASSUMPTION-015, Surgical Edit Mandate): perubahan bersifat menambah invariant pada struktur yang ada, bukan menulis ulang arsitektur.

## 4. Dependencies

- **DEP-001**: `C:\projects\WA-Gateway` @ HEAD `master` (diverifikasi saat TASK-001; dicatat sebagai titik rollback) — repositori sumber untuk worktree baru. Branch `feature/m1-wave3-reliability-observability` dan worktree `C:\projects\WA-Gateway-m1w3` **belum ada** dan dibuat di TASK-001 (RISK-002).
- **DEP-002**: Baileys 6.7.24 — `sock.ev.on('messages.update')` (GW-21, gerbang OI-002), `sock.onWhatsApp()` (probe GW-20), dan kontrak `connectionManager.js` (EXT-001).
- **DEP-003**: `better-sqlite3` — dipakai `incoming_queue` (dead-letter/cap) dan `outgoing_operations` (kolom `receipt_*`); jalur fallback JSON (`IncomingBufferJsonFile`) untuk build Android — guard satu instance REQ-052 MUST bekerja di keduanya (INF-001).
- **DEP-004**: Node.js 20 dan PM2 (`wa-gateway`) di Aan-PC — prasyarat TASK-001 dan TASK-031.
- **DEP-005**: Gelombang 2 (`outgoing_operations`, `incoming_queue.dead_lettered_at`, `outgoingOperationService`) sudah live — Wave 3 hanya menambah kolom `receipt_*` dan mengonsumsi baris yang ada (DAT-001).
- **DEP-006**: AuliaPos `POST /api/inbox/gateway/status` (heartbeat) dan `POST /api/inbox/gateway/messages` (event) — kontrak MUST tidak berubah (CON-011, EXT-002).
- **DEP-007**: `plan-process-m1-wave2-outgoing-idempotency-v1.0.md` — pola VERIFY/APPROVAL/DEPLOY, CON-011 (folder live read-only), dan pola TASK-022 (DEPLOY) yang dipakai ulang di TASK-031.
- **DEP-008**: `docs/audit/clarification-report-m1-wave3-reliability-observability-2026-09-29.md` (Readiness 71/100 → proyeksi 90/100, PROCEED) — sumber 14 resolusi CB-01..CB-14 yang sudah tertanam di spec v1.1.
- **DEP-009**: **OI-001/D-14** (dua pemasok luar: akun Gateway uji ketiga + nomor kontak uji) — prasyarat TASK-038; **bukan** dapat diturunkan dari kode (DAT-002). Status: BELUM tersedia.
- **DEP-010**: **OI-002** (bentuk payload `messages.update` 6.7.24 + tabel pemetaan status numerik → `receipt_state`) — prasyarat TASK-027/TASK-028; ditutup lewat TASK-019 (spec §1.2, EXT-001).

## 5. Files

**WA-Gateway (worktree `C:\projects\WA-Gateway-m1w3`):**

- **FILE-001**: `src/config/index.js` — seluruh variabel §4.7 + `GROUP_NAME_CACHE_MAX_ENTRIES` + clamp — TASK-002.
- **FILE-002**: `src/observability/metrics.js` (baru) — registri counter/gauge, enum reason, `recordDrop`, snapshot JSON — TASK-003.
- **FILE-003**: `src/observability/health.js` (baru) — rumus `send_ready`/`receive_ready`/`delivery_ready`, `verdict`, `degraded_reasons`, counter dekripsi — TASK-020.
- **FILE-004**: `src/observability/probe.js` (baru) — probe reachability terjadwal + `socket_status` + debounce CB-05 — TASK-021.
- **FILE-005**: `src/store/instanceLock.js` (baru) — lock file PID + heartbeat + takeover — TASK-006.
- **FILE-006**: `src/delivery/incomingDelivery.js` — single-flight, isolasi error per event, anggaran waktu, FIFO/overflow, drain, observabilitas tick — TASK-007..TASK-009.
- **FILE-007**: `src/delivery/heartbeat.js` — invariant `heartbeat` + blok `health` aditif — TASK-010, TASK-023.
- **FILE-008**: `src/logging/index.js` — skema field, katalog event, level, redaksi, buffer terstruktur — TASK-014..TASK-016.
- **FILE-009**: `src/whatsapp/connectionManager.js` — counter drop (E-08/E-12), instrumentasi diagnostik bergerbang, handler `messages.update`, counter dekripsi — TASK-016, TASK-020, TASK-028, TASK-034.
- **FILE-010**: `src/store/outgoingOperations.js` — kolom `receipt_*` + `markReceipt()` — TASK-027.
- **FILE-011**: `src/api/routes.js` — `GET /api/health`, `GET /api/metrics` — TASK-022.
- **FILE-012**: `src/api/ci4Routes.js` — field aditif `receipt_state` pada respons replay `/send`/`/send-media` — TASK-028.
- **FILE-013**: `src/app/index.js` — guard satu instance, drain shutdown, start probe — TASK-006, TASK-009, TASK-021.
- **FILE-014**: `test/` — skrip baru pola `simulate-*.js` (`simulate-config-wave3`, `simulate-metrics`, `simulate-instance-lock`, `simulate-worker-singleflight`, `simulate-worker-drain`, `simulate-heartbeat`, `simulate-memory-limits`, `simulate-logging-schema`, `simulate-logging-buffer-drops`, `simulate-health-model`, `simulate-health-probe-debounce`, `simulate-api-observability`, `simulate-receipt-store`, `simulate-receipt-handler`, `simulate-diag-raw-message`, `simulate-crash-restart-harness`, `simulate-h1-h2-harness`, `simulate-matrix-harness`) + `test/run-reliability-matrix.js` — TASK-003..TASK-043.

**Dokumentasi (repo AuliaPos):**

- **FILE-015**: `docs/decisions/` — decision log baru: titik rollback + hasil TASK-001; pemetaan OI-002 (TASK-019); hasil deploy + snapshot health/metrics (TASK-031); decision log pengukuran Ticket 05 + batas bukti (TASK-038); laporan matriks + **carry-over terbuka** (TASK-043).
- **FILE-016**: `docs/ARCHITECTURE.md` — diperbarui di TASK-046 (Living Architecture Map Mandate).
- **FILE-017** (opsional): revisi `spec/spec-process-m1-wave3-reliability-observability.md` §4.6/REQ-066/AC-071 **hanya bila** TASK-019 menemukan bentuk payload berbeda dari dugaan (spec §1.2 OI-002).

## 6. Testing

- **TEST-001 (unit)**: `test/simulate-config-wave3.js`, `test/simulate-metrics.js` — bawaan env + clamp, enum reason, snapshot metrik (TASK-002/003).
- **TEST-002 (integrasi, seam 7/8)**: `test/simulate-instance-lock.js` (PID hidup/basi/takeover, Windows + JSON), `test/simulate-worker-singleflight.js` (AC-052/053/054/055), `test/simulate-worker-drain.js` (AC-056/059), `test/simulate-heartbeat.js` (AC-077/078/079), `test/simulate-memory-limits.js` (AC-058) — TASK-006..011.
- **TEST-003 (unit, seam 4)**: `test/simulate-logging-schema.js`, `test/simulate-logging-buffer-drops.js` — JSON per baris, `event`/`component`, `[CRITICAL]`+`severity`, buffer ≤300, counter drop (AC-060..AC-065) — TASK-014..016.
- **TEST-004 (unit, seam 2/5)**: `test/simulate-health-model.js`, `test/simulate-health-probe-debounce.js`, `test/simulate-api-observability.js` — rumus kesiapan, `unknown` vs `false`, debounce CB-05, endpoint tanpa `listen()` (AC-066..AC-070, AC-072) — TASK-020..024.
- **TEST-005 (integrasi, seam 3)**: `test/simulate-receipt-store.js`, `test/simulate-receipt-handler.js` — kolom `receipt_*`, handler payload dikenal/tidak dikenal, replay `receipt_state`, tanpa konsumen `in_flight` (AC-071) — TASK-027/028.
- **TEST-006 (instrumentasi)**: `test/simulate-diag-raw-message.js` — flag `0` → tanpa field; flag `1` + socket mock (AC-047 bagian non-OI-001) — TASK-034.
- **TEST-007 (harness, prosedur nyata)**: `test/simulate-crash-restart-harness.js`, `test/simulate-h1-h2-harness.js` + eksekusi nyata (AC-047..AC-051) — TASK-035/037/038. Eksekusi nyata = **Macro Gate** dan butuh persetujuan user (CON-019).
- **TEST-008 (harness matriks + Macro Gate)**: `test/run-reliability-matrix.js` (AC-074, exit non-nol bila gagal) + laporan matriks AC-001..AC-079 (AC-073/AC-075/AC-076) — TASK-041..043.
- **TEST-009 (Guard statis)**: skrip pemeriksa yang gagal bila (a) ada penulisan ke `data/gateway.sqlite`; (b) ada `console.*` di `src/` di luar jalur fatal; (c) label metrik terlarang (`wa_message_id`/`operation_id`); (d) kode menyelesaikan operasi `in_flight` dari receipt; (e) harness menyentuh `auth/` — TASK-004/012/017/024/029/036.
- **TEST-010 (Regresi kumulatif, CON-018)**: seluruh skrip `simulate-*.js` gelombang 1–2 + Fase 1..n dijalankan ulang di setiap VERIFY; 0 gagal.
- **TEST-011 (Kebersihan data)**: semua skrip Gateway memakai SQLite folder temp (pola `simulate-durable-buffer.js`); MUST NOT menulis `data/gateway.sqlite` produksi.
- **TEST-012 (Lint dokumen)**: `markdownlint-cli2` pada plan/artefak matriks; jenis temuan tidak boleh baru dibanding spec v1.1 (`MD013` bawaan 80, `MD028`, `MD060`, `MD025`) — repo tidak punya `.markdownlint*` dan standar proyek menetapkan 400 karakter.

## 7. Risks & Assumptions

### 7.1 Risiko dari pagar wajib instruksi user

- **RISK-001 (medium — gap dokumen spec, `GROUP_NAME_CACHE_MAX_ENTRIES`)**: REQ-053/AC-058 mewajibkan cap cache nama grup dan gauge `group_name_cache_size`, tetapi `GROUP_NAME_CACHE_MAX_ENTRIES` **tidak** ada di tabel §4.7 maupun GUD-005 spec v1.1. *Mitigasi (dikonfirmasi user)*: implementasikan env `GROUP_NAME_CACHE_MAX_ENTRIES` bawaan **500** di TASK-002 mengikuti pola cap existing, dan catat sebagai inkonsistensi dokumen — bukan requirement baru. Task: TASK-002, TASK-011, TASK-022.
- **RISK-002 (medium — prasyarat fisik)**: worktree `C:\projects\WA-Gateway-m1w3` dan branch `feature/m1-wave3-reliability-observability` belum ada; HEAD master nyata harus diverifikasi (tidak di-hardcode). *Mitigasi*: TASK-001 adalah task pertama; bila pembuatan worktree/`npm ci` gagal (mis. `better-sqlite3` tidak ter-build di Node 20), hentikan fase dan lapor user (§9 Kontingensi Plan B). Task: TASK-001.
- **RISK-003 (high — OI-001/D-14 belum tersedia)**: TASK-038 (eksekusi protokol nyata Ticket 05) **BLOCKED** hingga pemilik menyediakan akun Gateway uji ketiga + nomor kontak uji. *Mitigasi*: kode instrumentasi/harness (TASK-034..037) boleh dikerjakan lebih dulu (dikonfirmasi user); AC-047..051 dicatat sebagai **`OPEN CARRY-OVER`** (REQ-070) dan **tidak** dihitung lulus; M1 tidak dinyatakan selesai penuh. Task: TASK-033, TASK-038, TASK-041, TASK-043.
- **RISK-004 (high — OI-002 gerbang keras)**: REQ-066 (GW-21) MUST NOT diimplementasikan sebelum bentuk payload `messages.update` 6.7.24 + tabel pemetaan status numerik → `receipt_state` terverifikasi (TASK-019). Bila bentuk payload menyimpang, REQ-066/AC-071/§4.6 perlu direvisi. *Mitigasi*: TASK-019 adalah tugas pertama Ticket 14 dan `Dep` TASK-027; TASK-029 memverifikasi tidak ada konsumen receipt. Task: TASK-019, TASK-027, TASK-028.
- **RISK-005 (high — batas jujur, wajib tertulis di decision log)**: tidak boleh ada klaim "GW-11/GW-25/GW-21/ASSUMPTION-009 tertutup" (pagar wajib #4, CB-09). *Mitigasi*: TASK-038/TASK-043 menulis pernyataan batas eksplisit; AC-076 memverifikasinya; handler receipt ditandai record-only di kode + komentar. Task: TASK-028, TASK-038, TASK-043, TASK-046.

### 7.2 Asumsi yang diekstrak dari spec (PRD-bypass synergy)

Seluruh tag `[ASSUMPTION-*]` spec v1.1 diekstrak apa adanya; task yang bergantung padanya ditandai **High Risk**.

- **ASSUMPTION-012 (GW-21 dibatasi ke Gateway, record-only) — High Risk**: bila pemilik menghendaki GW-21 murni M2, REQ-066/AC-071 dan kolom `receipt_*` dihapus; sisa Ticket 14 (metrik + health GW-20) tetap utuh. *Mitigasi*: seluruh Wave 5 terisolasi (TASK-027..030) sehingga dapat dibuang sebagai satu unit tanpa menyentuh Fase 4. Task: TASK-027, TASK-028.
- **ASSUMPTION-013 (bentuk endpoint `GET /api/health`)**: nama/path endpoint berubah murah diperbaiki. Task: TASK-022.
- **ASSUMPTION-014 (metrik in-process, reset saat restart)**: tanpa dependensi npm baru; Prometheus/Grafana di luar M1. Task: TASK-003, TASK-022.
- **ASSUMPTION-015 (perbaikan worker dibatasi 8 REQ E-W2 + 3 invariant heartbeat)**: temuan kebenaran di luar kesebelas hal itu dicatat sebagai backlog, bukan ditambal diam-diam; menulis ulang arsitektur worker di luar scope. Task: TASK-007..011.
- **ASSUMPTION-016 (logging aditif)**: teks log lama dipertahankan (REQ-056); event buffer diperluas menyimpan field terstruktur. Task: TASK-014, TASK-016.
- **ASSUMPTION-017 (instrumentasi Ticket 05 read-only & bergerbang) — High Risk**: hanya aktif bila `WA_DIAG_RAW_MESSAGE=1`; tanpa I/O sinkron tambahan; tanpa isi pesan/nama kontak. Task: TASK-034.
- **ASSUMPTION-018 (ambang kesehatan via env)**: `HEALTH_PROBE_ENABLED=1` aktif di semua lingkungan termasuk produksi (CB-13); debounce N=2. Task: TASK-002, TASK-020, TASK-021.
- **ASSUMPTION-019 (tanpa rotasi log)**: risiko diterima, hanya didokumentasikan di §11 spec. Task: (tidak ada implementasi).
- **ASSUMPTION-020 (`receive_ready` tidak langsung) — High Risk**: `receive_ready='unknown'` bila tidak ada bukti; `healthy` berarti "sesi valid dan server terjangkau", bukan "mustahil kehilangan pesan". Batas ini MUST ditulis di decision log Ticket 14. Task: TASK-020, TASK-031.
- **ASSUMPTION-021 (bahasa dokumen)**: spec/plan berbahasa Indonesia; lihat note Introduction. Task: seluruh dokumen.

### 7.3 Risiko tambahan yang ditemukan saat perencanaan

- **RISK-006 (medium — OI-002 butuh aktivitas Gateway nyata)**: verifikasi bentuk payload `messages.update` idealnya memerlukan pesan keluar nyata yang menerima receipt. *Mitigasi*: TASK-019 boleh membaca sumber `node_modules/baileys` terlebih dulu (statis) dan pengamatan pada instance uji/live secara read-only; jika receipt nyata belum bisa dipicu, tulis pemetaan dari sumber kode + tandai bagian yang belum terverifikasi empiris, dan tunda TASK-027/028 sampai pemetaan lengkap. Task: TASK-019.
- **RISK-007 (medium — base commit & folder live)**: `C:\projects\WA-Gateway` adalah runtime live; risiko menyentuhnya sebelum TASK-031. *Mitigasi*: CON-017 (folder live read-only sampai TASK-031) + TASK-001 mencatat titik rollback; deploy hanya `merge --ff-only`. Task: TASK-001, TASK-031.
- **RISK-008 (medium — uji hidup & gerbang user)**: TASK-038 dan TASK-043 mematikan/menjeda Gateway/instance uji → butuh persetujuan user (CON-019). *Mitigasi*: keduanya ditandai VERIFY/APPROVAL dan tidak dijalankan otomatis. Task: TASK-038, TASK-043.
- **RISK-009 (low — paritas fallback JSON)**: guard satu instance (REQ-052) dan kolom `receipt_*` harus bekerja di jalur fallback JSON (Android) yang belum pernah diuji nyata. *Mitigasi*: uji paritas di TASK-006/TASK-012, batas bukti ditulis jujur. Task: TASK-006, TASK-027.
- **RISK-010 (low — scope creep)**: Wave 3 rawan melebar ke M2, Ticket 06–11/16, E-02/E-07, dan perbaikan GW-11/GW-25. *Mitigasi*: setiap task terpetakan `Ref ID`; permintaan di luar itu MUST di-PUSHBACK (AGENTS.md §Phase Code); pagar wajib #3/#4. Task: seluruh task.

## 8. Related Specifications / Further Reading

- `spec/spec-process-m1-wave3-reliability-observability.md` v1.1 — sumber tunggal REQ-042..REQ-074, AC-047..AC-079, OI-001/OI-002, D-14, ASSUMPTION-012..021, SEC/CON/GUD Wave 3.
- `spec/spec-process-m1-wave2-outgoing-idempotency.md` v1.1 — REQ-020..REQ-041, AC-019..AC-046, dan pola yang dilanjutkan (penomoran REQ/AC/CON).
- `spec/spec-process-m1-wave1-incoming-reliability.md` v1.1 — AC-001..AC-018 dan CON-001..004 (dasar gelombang 1).
- `plan-process-m1-wave2-outgoing-idempotency-v1.0.md` — pola bentuk plan, CON-011 (folder live read-only), dan pola TASK-022 (DEPLOY).
- `docs/audit/clarification-report-m1-wave3-reliability-observability-2026-09-29.md` — Readiness 71/100 → proyeksi 90/100 (PROCEED); 14 resolusi CB-01..CB-14.
- `docs/GATEWAY-REQUIREMENTS.md` — GW-20 (health) dan GW-21 (receipt); rujukan GW-11/GW-19/GW-25.
- `docs/TODO-CHAT.md` — M1 Ticket 05, 12–15; risiko P0 #3/#4/#5; butir C3 (GW-25 belum tereproduksi).
- `docs/decisions/2026-09-21-m1-ticket01-baseline.md` — Baseline 2/3/4 dan "Analisis lanjutan … error dekripsi" (H1/H2, nomor kedua).
- `docs/decisions/2026-09-21-m1-ticket02-audit-enqueue.md` — E-08/E-12 (dasar counter drop per alasan).
- `docs/decisions/2026-09-23-m1-wave1-deploy-dan-ac001.md`, `docs/decisions/2026-09-25-ticket04-json-fallback-android-verifikasi-nyata.md` — pola bukti nyata & batas bukti perangkat.
- `docs/audit/code-review-m1-wave1-2026-09-23.md` — temuan CR (DB sementara, guard statis) → TEST-009/TEST-011.
- `CONTEXT.md` — kamus domain (Handoff/Collision/Grup/Balas Pesan/Kutipan/Teruskan); tidak ada istilah Wave 3 baru yang disepakati (lazy creation dipertahankan).
- `docs/adr/` — ADR-0001, 0002, 0003; tidak ada ADR baru (spec §10: semua keputusan mudah dibalik).

## 9. Rollback / Recovery Plan

- **Umum**: setiap task dikerjakan sebagai commit terpisah di branch `feature/m1-wave3-reliability-observability` (worktree `C:\projects\WA-Gateway-m1w3`). Rollback per fase = `git revert` commit-commit task fase itu (bukan `reset --hard`), agar histori tetap bisa diaudit. `auth/` dan sesi WhatsApp tidak pernah tersentuh, sehingga rollback apa pun tidak berisiko kehilangan sesi Gateway.
- **Deploy (TASK-031)**: rollback = `git -C C:\projects\WA-Gateway reset --hard <SHA-titik-rollback-dari-TASK-001>` lalu `cmd /c "pm2 restart wa-gateway"`, kemudian `pm2 describe wa-gateway` (status `online`, `script path` tetap folder live). `reset --hard` hanya dipakai di folder live dan folder itu selalu dipastikan bersih (`status --short` kosong) sebelum deploy. Kolom `receipt_*` yang sudah terbentuk tidak perlu dihapus — kode lama tidak membacanya; rollback tidak menyentuh `data/gateway.sqlite`.
- **Fase 1 (fondasi)**: rollback = `git revert` TASK-002/TASK-003. Gateway kembali ke konfigurasi/metrik sebelum Wave 3; variabel env baru yang tidak dibaca kode lama bersifat inert.
- **Fase 2 (worker correctness)**: rollback = `git revert` TASK-006..TASK-011. **Perhatian**: guard satu instance (TASK-006) mengubah perilaku start — bila guard menolak start yang sah, revert khusus TASK-006 sambil mempertahankan sisanya; bila `stop()`/drain (TASK-009) bermasalah, revert TASK-009 saja. Kolom DB tidak berubah di fase ini (kecuali yang sudah ada dari gelombang 2).
- **Fase 3 (logging)**: rollback = `git revert` TASK-014..TASK-016. Teks log lama tetap ada (perubahan aditif), sehingga rollback aman; event buffer kembali ke bentuk lama tanpa menghilangkan berkas log.
- **Fase 4 (metrics/health)**: rollback = `git revert` TASK-019..TASK-024. Endpoint `GET /api/health`/`GET /api/metrics` hilang; heartbeat kembali tanpa blok `health`. **Catatan**: TASK-019 hanya dokumentasi observasi — tidak ada kode untuk di-revert; pemetaannya tetap tersimpan sebagai referensi.
- **Fase 5 (GW-21 receipt)**: kolom `receipt_*` bersifat additive (CON-012) → revert kode aman; kolomnya boleh tetap ada dan diabaikan kode lama. Tidak ada konsumen receipt, jadi tidak ada data yang perlu direkonsiliasi.
- **Fase 6 (deploy)**: lihat butir Deploy di atas; bila verifikasi live gagal, rollback ke SHA titik rollback sebelum melanjutkan Fase 7.
- **Fase 7 (Ticket 05)**: rollback = `git revert` TASK-034..TASK-038 (instrumentasi/harness + decision log; decision log sebaiknya **dipertahankan** sebagai jejak). Bila TASK-038 dijalankan pada instance uji terpisah, tidak ada dampak pada Gateway live; matikan instance uji dan hapus DB sementara.
- **Fase 8 (matriks)**: artefak matriks + laporan bersifat dokumentasi; bila ada skrip matriks yang gagal, jangan tandai plan selesai — buka kembali fase terkait dan perbaiki sebelum TASK-045 disetujui.
- **Fase 9 (penutupan)**: perubahan `docs/ARCHITECTURE.md` = `git revert`; `status` plan dikembalikan ke `'In progress'` bila penutupan dibatalkan.

### Kontingensi (Plan B)

- **Kontingensi 1 — worktree/branch gagal dibuat (TASK-001)**: fallback `git clone C:\projects\WA-Gateway C:\projects\WA-Gateway-m1w3` lalu `git switch -c feature/m1-wave3-reliability-observability <SHA-titik-rollback>`. Bila `better-sqlite3` gagal di-build (Node 20), berhenti dan lapor user sebelum menulis kode — mengubah versi Node atau memasang kompiler di luar kewenangan plan ini.
- **Kontingensi 2 — OI-002 belum bisa ditutup (TASK-019)**: tunda TASK-027/028; kerjakan sisa Ticket 14 (metrik + health GW-20) yang tidak bergantung receipt. Jangan implementasikan REQ-066 dengan pemetaan yang belum terverifikasi.
- **Kontingensi 3 — OI-001 tidak tersedia (TASK-033)**: TASK-034..037 tetap boleh dikerjakan; TASK-038 ditunda dan AC-047..051 dicatat sebagai `OPEN CARRY-OVER` (REQ-070). Ticket 15 tetap menjalankan AC yang dapat dijalankan sekarang.
- **Kontingensi 4 — guard satu instance menolak start yang sah (TASK-006)**: periksa mekanisme cek PID heartbeat; bila tidak dapat diandalkan lintas-OS, revert TASK-006 dan catat sebagai backlog, jangan menambal dengan dependensi baru.
- **Kontingensi 5 — probe reachability tidak dapat diandalkan (`onWhatsApp` rate-limit)**: turunkan frekuensi via env (`HEALTH_PROBE_INTERVAL_MS`), dan pastikan `receive_ready='unknown'` (bukan `false`) ketika probe tidak tersedia — jangan mengklaim siap/tidak siap tanpa bukti.

## 10. Pre-Flight Self-Correction Checklist

- [x] **Tidak ada horizontal slicing**: setiap task mengikat lapisan yang dibutuhkan satu perilaku ujung-ke-ujung (config+registry; lock+start; worker+loop; logger+buffer; health+probe+endpoint; kolom+store+handler+respons). Tidak ada pola "buat semua kolom lalu semua handler".
- [x] **Tidak ada task XL**: task terbesar menyentuh 3 berkas (TASK-011, dan beberapa M dengan 2 berkas); tidak ada task yang menggabungkan dua subsistem independen atau memakai kata "dan" untuk dua aksi besar.
- [x] **Traceability ketat**: setiap task aksi memuat `Ref ID` (REQ/CON/SEC/GUD/OI/spec §) dan `AC Ref`; VERIFY/APPROVAL/DEPLOY ditandai khusus.
- [x] **Dependensi bottom-up**: kolom `Dep` hanya menunjuk task yang dijadwalkan lebih dulu; grafik dependensi ditulis eksplisit di §2.
- [x] **VERIFY + APPROVAL di setiap fase**: TASK-004/005, 012/013, 017/018, 025/026, 029/030, 031/032, 039/040, 044/045.
- [x] **Pagar wajib #1 (OI-002)**: TASK-019 adalah tugas pertama Fase 4 (Ticket 14) dan `Dep` TASK-027/028 (REQ-066).
- [x] **Pagar wajib #2 (OI-001/D-14)**: TASK-033 adalah gerbang TASK-038 (eksekusi protokol nyata Ticket 05); kode instrumentasi/harness boleh lebih dulu (dikonfirmasi user).
- [x] **Pagar wajib #3 (Gateway-only, CON-015)**: tidak ada task AuliaPos; Ticket 06–11/16 tidak dispesifikasikan ulang (hanya diverifikasi di Fase 8).
- [x] **Pagar wajib #4 (tanpa perbaikan GW-11/GW-25)**: instrumentasi read-only + pengukuran H1/H2; tidak ada task yang mengubah timestamp/dekripsi.
- [x] **Pagar wajib #5 (carry-over)**: TASK-041/043 memuat bagian **"carry-over terbuka"** (REQ-070); AC-049 dan bagian OI-001-dependent AC-047/AC-051 tidak dihitung lulus.
- [x] **Asumsi tidak memblokir**: semua `[ASSUMPTION-*]` spec diekstrak ke §7.2 dengan mitigasi dan task terkait; yang berkonsekuensi besar ditandai High Risk.
- [x] **Batas jujur tercatat**: RISK-004/RISK-005 menampung OI-002, GW-11/GW-25/GW-21, dan ASSUMPTION-009 sebagai batas yang MUST ditulis di decision log, bukan klaim tertutup.
- [x] **Tidak mengubah requirement**: tidak ada kolom, endpoint, enum, atau AC baru di luar spec v1.1; satu gap dokumen (`GROUP_NAME_CACHE_MAX_ENTRIES`) diselesaikan ke arah pemenuhan REQ-053 (RISK-001), bukan dengan mengubah spec.
- [x] **Tidak menulis kode**: dokumen ini hanya berisi rencana; seluruh penulisan kode diserahkan ke `/sdlc-write-code`.
