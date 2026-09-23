---
title: M3 Operational Inbox — Fase 2a (Handoff + Collision Detection)
version: 1.1
date_created: 2026-09-22
last_updated: 2026-09-23
owner: AuliaPos Inbox module
tags: [inbox, chat, whatsapp, m3, handoff, collision-detection]
---

# Introduction

Spesifikasi ini mendefinisikan **M3 — Operational Inbox, Fase 2a**: kemampuan *Handoff* antar staff dan *Collision Detection* pada modul Inbox WhatsApp AuliaPos. Fase 2a adalah pemotongan sempit dari "Fase 2" yang disebut dokumen lama — hanya dua perilaku itu, di atas fondasi Fase 1 (Queue View, Conversation Detail, Snooze, Selesai) yang sudah berjalan.

Kontrak teknis di spec ini **bukan karangan baru**. Seluruh keputusan intinya sudah ditetapkan sebagai **K-01 s.d. K-09** di `docs/audit/clarification-report-m3-fase2-m2-gate-2026-09-22.md`, dan requirement produknya ada di `prd-20260922-0141-chat-whatsapp-inbox.md` v1.1 (GH-006, GH-007). Spec ini menerjemahkan keduanya menjadi kontrak yang bisa langsung dikodekan dan diuji, tanpa menambah perilaku yang belum disepakati.

> [!IMPORTANT]
> **v1.1 — finalisasi fisik (2026-09-23).** Implementasi Fase 2a sudah selesai di commit `51fb1fc` (suite hijau **298 test / 948 assertion**). Spec v1.0 memuat teks yang **basi** terhadap kode (batas 500, `selesai`=403, target admin boleh, tanpa gerbang inisiator). Sesuai **RISK-01/CON-003** pada `plan/plan-feature-m3-operational-inbox-fase2a-v1.0.md`, **Plan menang bila konflik**. v1.1 menyelaraskan Spec dengan enam patch normatif **P-01 s.d. P-06** plus keputusan klarifikasi terbaru (**CR-04 = A1**, **CR-03 = A**) dan penolakan **Q2** terhadap Plan. Perubahan v1.1 bersifat dokumentasi murni: tanpa migrasi, tanpa perubahan schema, tanpa panggilan Gateway.

## 1. Purpose & Scope

Spec ini mencakup, dan hanya mencakup:

- **Handoff** — pemindahan `conversations.assigned_to` dari satu staff ke staff lain, disertai `summary` (wajib), `next_action` (wajib), dan `note` (opsional), dengan riwayat penyerahan yang tercatat permanen dan bisa dibaca kembali.
- **Collision Detection** — perilaku sistem ketika dua staff mengubah kepemilikan percakapan yang sama pada saat yang hampir bersamaan: tepat satu perubahan diterima (conditional write di database), permintaan yang kalah ditolak tanpa menimpa kepemilikan yang sudah sah, dan pelaku yang kalah diberi tahu siapa pemilik sah saat itu.
- **Gate M2 secara sempit (K-01)** — atomicitas kepemilikan **hanya** dibuka pada jalur Handoff, memakai primitif *expected-owner conditional write* yang sudah berjalan di `Inbox::ambilPercakapan()`.

Audiens: developer yang akan mengeksekusi `/sdlc-plan-tasks` → `/sdlc-write-code`, serta agent `/sdlc-clarify-reqs` dan `/sdlc-audit-consistency` yang akan memeriksa kelengkapan dan ketertelusuran spec ini.

Asumsi dasar (lihat juga Section 1.2):

1. Spec ini dibangun di atas branch turunan `v2.2`/`v2.3` yang sudah punya modul Inbox M3 Fase 1 (Queue View + `queue_status` + Internal Note).
2. Database Inbox (`aulia_inboxdb`, connection group `inbox`) tetap terpisah dari database POS (`aulia_kasirdb`).
3. Seluruh pekerjaan Fase 2a berada di sisi AuliaPos (CI4). Tidak ada perubahan di repo `tikusgot007/WA-Gateway`.
4. Definition of Done Fase 2a mengikuti kebijakan tes proyek: `composer test` lolos 100%.

### 1.1 Out of Scope

- **Presence / awareness** ("sedang dibuka oleh Budi", tabel presence, heartbeat, TTL) — dideklarasikan *deferred* di PRD v1.1 dengan prasyarat bernama (K-04). Collision Detection di spec ini **bukan** presence.
- **Notifikasi antar staff dan penanda belum dibaca (unread) per pengguna** (K-08) — Fase 2a sengaja berjalan tanpa notifikasi; penerima Handoff menemukan percakapan lewat Queue View bersama dan riwayat Handoff. Risiko target offline yang melewatkan penyerahan dimitigasi secara prosedural, bukan teknis.
- **Auto-assignment (Fase 2b, GH-008)** — dipisahkan ke inkremen berikutnya (K-03). Aturan beban kerja, definisi "sedang bertugas", dan tie-breaker belum ditetapkan.
- **Program M2 (State Consistency) secara menyeluruh** — tetap *deferred*. Jalur kepemilikan lain yang masih *read-then-write* (`lepas`, `tutup`, `snooze`, `tandai-dibaca`, `hapus`) **tidak diubah** di Fase 2a.
- **Penghapusan/purge riwayat Handoff** — tidak ada di Fase 2a; riwayat adalah jejak audit.
- **Handoff paksa oleh admin, alur persetujuan (approval), atau reassignment otomatis** — Handoff bersifat transfer langsung tanpa persetujuan; tidak ada jalur override admin pada Fase 2a.
- **@mention dengan notifikasi nyata** dan **Customer Context penuh (M4)** — tetap di luar lingkup.
- **Penulisan pesan/Internal Note otomatis saat Handoff** — lihat REQ-009 dan Section 10 (konteks lama yang sudah disuperseded oleh K-05).

### 1.2 Open Questions & Assumptions

Semua ambiguitas **mayor** sudah diselesaikan lewat sesi `/sdlc-clarify-reqs` dan tercatat sebagai K-01 s.d. K-09 di `docs/audit/clarification-report-m3-fase2-m2-gate-2026-09-22.md`. Yang tersisa hanya detail kontrak minor berikut. Sesuai protokol *heavy lifting*, setiap butir sudah diisi dengan pilihan paling logis berdasarkan kode yang ada, dan ditandai agar bisa diinterogasi di sesi klarifikasi berikutnya.

> [!WARNING]
> **[ASSUMPTION-001] Kontrak baca riwayat Handoff.** PRD GH-006 mewajibkan riwayat penyerahan "tercatat serta bisa dibaca kembali oleh staff", sedangkan K-05 hanya mengunci *model pencatatan* (tabel `conversation_handoffs`) dan menyebut visibilitas inline "bila nanti diinginkan". Spec ini mengambil pilihan paling minimal dan paling mudah diuji: endpoint **baca khusus** `GET /inbox/percakapan/(:num)/handoff` yang mengembalikan daftar riwayat (terbaru dulu), **tanpa** mengubah kontrak `GET /inbox/api/conversations/(:num)/messages` dan **tanpa** menulis apa pun ke tabel `messages`. Lihat Section 4.4.

> [!NOTE]
> **[ASSUMPTION-002 — LOCKED by P-01] Kode status untuk penolakan eligibilitas `selesai` = 409.** K-09 hanya menetapkan `403` (pelaku/target tidak berhak), `409` (ownership berubah antara read dan write), dan `400` (validasi). Penolakan karena percakapan sudah di tab `Selesai` (K-07) diklasifikasikan sebagai **409** — satu keluarga dengan penolakan berbasis *state* yang menuntut klien memuat ulang kondisi terkini, mengikuti preseden `hapusPercakapan()`. Alternatif 403 ditolak: 403 di modul ini bermakna "identitas pelaku/target tidak berhak", bukan "state percakapan tidak mengizinkan". **Terkunci** oleh patch **P-01** dan test `H02`/`H02b`.

> [!WARNING]
> **[ASSUMPTION-003 — SUPERSEDED by P-03] Target Handoff wajib kasir aktif; target `admin` = 403.** Asumsi v1.0 ("target boleh admin") dibatalkan oleh patch **P-03** dan test `H05`. Kontrak final: target harus berasal dari `UserModel::daftarKasirAktif()` (`role = 'kasir'` + `is_active`); `admin`, unknown, inactive, atau ineligible semuanya **403**. Ini tetap konsisten dengan enum `users.role` yang hanya `admin`/`kasir`.

> [!WARNING]
> **[ASSUMPTION-004] Pesan error 409 wajib menyebut nama pemilik sah saat itu.** Mengikuti preseden pesan 409 di `ambilPercakapan()` ("Percakapan ini sudah diambil oleh {nama}."), termasuk fallback `User #{id}` bila nama tidak ditemukan.

> [!WARNING]
> **[ASSUMPTION-005] Zona waktu `Asia/Jakarta`.** Mengikuti preseden `snoozePercakapan()`, `tutupPercakapan()`, dan `catatanInternal()` yang menulis waktu dengan `new \DateTime('now', new \DateTimeZone('Asia/Jakarta'))`.

> [!WARNING]
> **[ASSUMPTION-006] Riwayat Handoff mengikuti siklus hidup percakapan.** `conversation_handoffs.conversation_id` memakai foreign key **dalam database Inbox** ke `conversations.id` dengan `ON DELETE CASCADE` (preseden `messages` di `2026-09-07-000001_CreateInboxTables.php`), sedangkan soft-delete `hapusPercakapan()` **tidak** menghapus riwayat.

> [!WARNING]
> **[ASSUMPTION-007] `next_action` adalah teks bebas, bukan enum.** Tidak ada dokumen sumber yang dapat diverifikasi yang mendefinisikan daftar tindakan lanjutan; menetapkan enum sekarang berarti mengarang requirement. Enum dapat ditambahkan di inkremen berikutnya bila polanya sudah terlihat dari data nyata.

> [!WARNING]
> **[ASSUMPTION-008] Istilah "Belum Diambil" != "tanpa pemilik".** Dua konsep yang mudah tertukar: **`belum_diambil`** = status turunan (`perlu_dibalas` + `assigned_to` kosong, hanya mungkin di satu tab); **tanpa pemilik** = `assigned_to IS NULL`, yang bisa terjadi di tab apa pun (mis. `menunggu`/`ditunda` setelah `lepasPercakapan()`). CR-03 = A membuat gerbang inisiator bergantung pada status **`belum_diambil`**, bukan pada `assigned_to IS NULL`. Glosarium `CONTEXT.md` belum memuat kedua istilah ini; usulan pencatatan (lazy creation) ada di `clarification-report-m3-fase2a-refactor-plan-2026-09-23.md` §3.

> [!WARNING]
> **[ASSUMPTION-009] Window mikro antara fail-fast 409 dan conditional write tetap mungkin.** CR-04 = A1 menambahkan pre-check `expected_owner !== assigned_to` sebelum transaksi, tetapi ownership masih bisa bergerak **lagi** antara pre-check dan `UPDATE`. Jaminan penuh tetap pada conditional write `<=>` + 409 kalah; pre-check hanya menutup celah audit-trail/otorisasi deterministik. Sudah dinilai `[Assumed / Out of Scope]` (tidak butuh seam atau kode baru).

> [!WARNING]
> **[ASSUMPTION-010] Bentuk 409 kini dua keluarga dengan satu bentuk body.** Keluarga **state** (`selesai`, P-01) dan keluarga **ownership** (kalah conditional write / fail-fast CR-04) berbagi key set yang sama: `status`, `message`, `current_owner_id` (nullable). Dikunci `H02b` (state) + `E18` (anti-leak tiga key).

> [!WARNING]
> **[ASSUMPTION-011] Pemisahan tes H/C/G/E/F memetakan 1:1 ke kontrak.** ID tes di §6 dan §12 merujuk `tests/session/InboxHandoffTest.php`: `H01-H08` (happy/rejection), `C01-C04` (collision), `G01-G05` (read-back), `E01-E18` (edge/micro-contract, termasuk `E09` non-string, `E10` Q5 absen, `E11`/`E12` batas karakter, `E17` urutan gerbang, `E18` anti-leak), `F01-F04` (fail-fast + narrowing CR-03). Bila nama file/ID berubah, dokumen ini yang harus disesuaikan.

> [!NOTE]
> **Konteks yang sudah disuperseded (jangan dipakai).** Catatan Handoff lama di repo-root `memory.instructions.md` menyatakan "Successful Handoff ... creates one Internal Note in the conversation thread". Keputusan **K-05** yang lebih baru dan sudah diremediasi ke PRD v1.1 membatalkan itu: tabel `messages` **tidak disentuh** oleh Handoff. Spec ini mengikuti K-05.

### 1.2.1 Decision Log — locked this session

| ID | Keputusan | Sumber |
|---|---|---|
| K-01 | Atomicitas kepemilikan dibuka **sempit** di jalur Handoff lewat *expected-owner conditional write*; `affectedRows() === 0` berarti 409 tanpa menimpa ownership. M2 sebagai program tetap *deferred*. | `clarification-report-m3-fase2-m2-gate-2026-09-22.md` K-01 |
| K-03 | Lingkup Fase 2a = **Handoff + Collision Detection**. Auto-assignment dikeluarkan ke Fase 2b. | K-03, PRD v1.1 §2.3 dan §9.2 |
| K-04 | Collision Detection = **deteksi bentrok saat write saja**. Presence *deferred* dengan prasyarat bernama. | K-04 |
| K-05 | Riwayat Handoff hanya di tabel baru `conversation_handoffs` (DB group `inbox`, additive). Thread `messages` tidak disentuh. | K-05 |
| K-06 | Dua kolom identitas: `from_user_id` (nullable, pemilik sebelum Handoff) dan `initiated_by_user_id` (NOT NULL, pelaksana aksi dari session). | K-06 |
| K-07 | Eligibilitas runtuh menjadi satu kondisi: `queue_status !== 'selesai'`. | K-07 |
| K-08 | Fase 2a **tanpa** notifikasi dan tanpa unread per-user. | K-08 |
| K-09 | Kontrak HTTP: `400` validasi, `403` pelaku/target tidak berhak, `409` kalah conditional write; sukses mengikuti bentuk `tutupPercakapan()`. | K-09 |
| P-01 | Penolakan eligibilitas `selesai` = **409** (bukan 403); mengoreksi teks v1.0. | `plan-feature-m3-operational-inbox-fase2a-v1.0.md` P-01 |
| P-02 | Batas `summary`/`next_action`/`note` = **4096 KARAKTER** (bukan 500). | Plan Fase 2a P-02 |
| P-03 | Target **kasir aktif saja**; target `admin`/unknown/inactive = **403**. | Plan Fase 2a P-03 |
| P-04 | Endpoint baca khusus `GET /inbox/percakapan/(:num)/handoff` (filter `auth`, cap **50**, terbaru dulu); `GET messages` tidak diubah. | Plan Fase 2a P-04 |
| P-05 | Inisiator = assignee saat ini; pengecualian tanpa-pemilik **hanya** untuk `queue_status === 'belum_diambil'`; non-assignee = **403**. | Plan Fase 2a P-05 |
| P-06 | Penolakan non-assignee dikunci oleh **AC-H08** + satu test controller. | Plan Fase 2a P-06 |
| CR-04 = A1 | **Fail-fast 409** bila `expected_owner !== assigned_to`, diletakkan **sebelum `$db->transBegin()`** dan **setelah kedua gerbang 403**; body byte-identical dengan 409 kalah (termasuk `current_owner_id`). | `clarification-report-m3-fase2a-refactor-plan-2026-09-23.md` §2 |
| CR-03 = A | Gerbang inisiator tanpa-pemilik dipersempit ke **`queue_status === 'belum_diambil'`** (bukan sekadar `assigned_to IS NULL`); pesan 403 bercabang tiga. | `clarification-report-m3-fase2a-refactor-plan-2026-09-23.md` §2 |
| Q2 (ditolak) | Inisiator `admin` **tetap 403** pada `belum_diambil`; Plan menang atas Q2 (penyempitan deliberat). | `clarification-report-m3-fase2a-refactor-plan-2026-09-23.md` §2 |

> [!IMPORTANT]
> **Q2 — PENYEMPITAN DELIBERAT (LOCKED).** Q2 pada `docs/audit/clarification-report-m3-fase2a-plan-2026-09-23.md:39` menyatakan *"an admin MAY initiate while being the assignee (or on `belum_diambil`)"*. Untuk Fase 2a kalimat itu **dipersempit secara sengaja**: seorang **admin yang bukan assignee TETAP 403** ketika percakapan berada di tab `belum_diambil`; hanya **kasir aktif** yang boleh menjadi inisiator pada keadaan itu. Ini keputusan produk yang sadar, bukan kelalaian: **Plan menang atas Q2** (RISK-01/CON-003) dan menutup "celah jalur paksa admin" yang dilarang PRD v1.1. Admin tidak kehilangan kemampuan apa pun karena `ambilPercakapan()` `:1387-1389` mengizinkan admin meng-*claim* percakapan lebih dulu (jalur override tanpa syarat), setelah itu cabang assignee mengizinkan Handoff — yang berbeda hanya `from_user_id` (admin vs `NULL`) dan satu request tambahan. Perubahan kode/test/UI akibat penyempitan ini: **nol** (sudah terkunci oleh test `E04(b)` dan mirror UI `index.php:861`).

### 1.2.2 Patch Normatif P-01 s.d. P-06 (kontrak Handoff final)

Enam patch ini **menggantikan** teks v1.0 yang bertentangan dan mengikat implementasi serta audit:

| Patch | Kontrak final (mengikat) | Konsekuensi section |
|---|---|---|
| **P-01** | Penolakan eligibilitas `selesai` = **409** (keluarga state-reload), bukan 403. | REQ-H02, §4.3 langkah 2, §4.4, AC-H02 |
| **P-02** | Batas `summary`/`next_action`/`note` = **4096 KARAKTER** (`mb_strlen`), bukan 500. | REQ-H04, §4.1, §4.3, §4.4 |
| **P-03** | Target = **kasir aktif saja** (`daftarKasirAktif()`); target `admin`/unknown/inactive = **403**. | REQ-H03, §4.3 langkah 5, §4.4 |
| **P-04** | Riwayat dibaca via endpoint khusus `GET /inbox/percakapan/(:num)/handoff`; filter `auth` saja (tanpa gerbang assignee), cap **50**, terbaru dulu; `GET messages` tidak diubah. | REQ-H08, §4.3b |
| **P-05** | Inisiator = assignee saat ini; pada percakapan tanpa pemilik **hanya** kasir aktif saat `queue_status === 'belum_diambil'`; selain itu **403**. | REQ-H01, §4.3 langkah 4, §4.4, §12 |
| **P-06** | Penolakan non-assignee dikunci oleh **AC-H08** + satu test controller. | §5 AC-H08, §6 |

## 2. Definitions

The following terms follow `CONTEXT.md` (Domain Glossary, created in the M2 gate session).

| Istilah | Definisi | _Avoid_ |
|---|---|---|
| **Handoff** | Pemindahan tanggung jawab percakapan antar staff, tercatat sebagai riwayat. | Transfer, Reassign |
| **Handoff Summary** | Ringkasan keadaan percakapan saat diserahkan, wajib diisi. | Deskripsi, Keterangan |
| **Next Action** | Tindakan lanjutan yang diharapkan dari penerima, wajib diisi, teks bebas. | Next step, TODO |
| **Handoff Note** | Catatan bebas penyerta Handoff, opsional, tidak terkirim ke pelanggan. | Komentar |
| **Collision Detection** | Tepat satu perubahan kepemilikan diterima saat dua staff menulis bersamaan; yang kalah ditolak. | Presence, Live view |
| **Presence** | Pengetahuan siapa sedang membuka percakapan. Bukan bagian Fase 2a (K-04). | Sedang online |
| **Expected Owner** | Nilai `assigned_to` yang dibaca sebelum tulis, dipakai sebagai syarat `WHERE`. | Pemilik lama |
| **Queue View Status** | Status turunan 5 tab; sumber tunggal `ConversationModel::withComputedStatus()`. | display status |
| **Internal Note** | Baris `messages` dengan `is_internal = TRUE`. Tidak dipakai Handoff (K-05). | catatan internal |

## 3. Requirements, Constraints & Guidelines

Requirement IDs: **REQ-Hxx** = Handoff, **REQ-Cxx** = Collision. Constraint IDs: **CON-Hxx**.

### 3.1 Handoff

- **REQ-H01 (who may initiate, P-05/CR-03 = A):** Inisiator adalah staff sesi aktif **DAN** harus memenuhi salah satu: (a) `assigned_to` saat ini **adalah** inisiator, **atau** (b) percakapan tanpa pemilik dan `queue_status === 'belum_diambil'` serta inisiator adalah **kasir aktif**. Non-assignee pada tab selain `belum_diambil` = **403** (AC-H08). **Admin non-assignee tetap 403 pada `belum_diambil`** (Plan menang atas Q2; lihat §1.2.1 Q2). Tanpa approval, tanpa jalur paksa admin.
- **REQ-H02 (eligible conversation, P-01):** Handoff diizinkan bila Queue View Status apapun kecuali `selesai` (K-07). Pelanggaran = **409** (keluarga state-reload, preseden `hapusPercakapan()`), **bukan** 403 seperti teks v1.0.
- **REQ-H03 (eligible target, P-03):** Target wajib **kasir aktif** (`role = 'kasir'` + `is_active`) dari `UserModel::daftarKasirAktif()` seperti `ambilPercakapan()`; target `admin`/unknown/inactive/ineligible = **403**. Target boleh sedang offline.
- **REQ-H04 (request payload):** `summary` wajib non-kosong, `next_action` wajib non-kosong teks bebas (ASSUMPTION-007), `note` opsional, `to_user_id` wajib, `expected_owner` wajib.
- **REQ-H05 (self-Handoff rejected):** Handoff ke diri sendiri ditolak 400 (K-09).
- **REQ-H06 (ownership change):** Saat sukses `assigned_to` menjadi `to_user_id`; kolom lain tidak berubah.
- **REQ-H07 (history record):** Tiap sukses menyisipkan satu baris `conversation_handoffs` dengan `from_user_id` (nullable) dan `initiated_by_user_id` (NOT NULL) (K-06).
- **REQ-H08 (read-back, P-04):** Riwayat dibaca via endpoint khusus `GET /inbox/percakapan/(:num)/handoff` (filter `auth` saja, **tanpa** gerbang assignee), terbaru dulu, cap **50**; tidak diedit/dihapus di Fase 2a. `GET /inbox/api/conversations/(:num)/messages` **tidak diubah**; 404 hanya untuk id tak dikenal.
- **REQ-H09 (atomicity, K-01):** Perubahan ownership dan insert riwayat dalam satu transaksi grup `inbox`; 0 affected rows berarti rollback + 409.
- **REQ-H10 (other paths untouched):** `lepas`, `tutup`, `snooze`, `tandai-dibaca`, `hapus` tidak diubah (K-01 sempit).
- **CON-H01:** Handoff tidak menulis tabel `messages` (K-05).
- **CON-H02:** Semua tulis Handoff ke grup DB `inbox` saja.
- **CON-H03:** Tanpa notifikasi dan tanpa unread per-user (K-08).

### 3.2 Collision Detection (write-time only, K-04)

- **REQ-C01 (expected-owner conditional write):** Server menulis `SET assigned_to = :to WHERE id = :id AND assigned_to <=> :expected`; pola sama seperti `ambilPercakapan()`.
- **REQ-C02 (loser loses cleanly):** 0 affected rows berarti tanpa perubahan, tanpa insert riwayat, respons 409 menyebut `current_owner_id` (K-09).
- **REQ-C03 (no presence):** Presence (heartbeat, TTL) deferred; dilarang diselundupkan ke Fase 2a (K-04).
- **REQ-C04 (determinism note):** Hanya dijamin tepat satu pemenang; urutan dari database, tidak ada janji fairness.

### 3.3 Cross-cutting constraints

- **CON-H04:** Semua mutasi divalidasi ulang di server (identitas sesi, eligibilitas REQ-H02/H03, bentuk payload).
- **CON-H05:** Migrasi additive saja (tabel baru, tanpa ALTER tabel lama).
- **CON-H06:** Timestamp zona `Asia/Jakarta` (ASSUMPTION-005).
- **GUD-H01:** Bentuk respons mengikuti `tutupPercakapan()`; kode status ikut K-09.

## 4. Interfaces & Data Contracts

### 4.1 New schema — `conversation_handoffs` (DB group `inbox`, additive)

Migration (new file, following the `2026-09-07-000001_CreateInboxTables.php` precedent; table creation guarded by the same "create only if missing" pattern used for the Inbox baseline):

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | NOT NULL | — | PK |
| `conversation_id` | INT UNSIGNED | NOT NULL | — | FK inside the Inbox database to `conversations.id`, `ON DELETE CASCADE` (ASSUMPTION-006; precedent: `messages` FK in baseline migration) |
| `from_user_id` | INT UNSIGNED | NULL | NULL | Owner before this Handoff; NULL when the conversation was unassigned (K-06). No FK to the POS `users` table — cross-database FKs are impossible with the split `inbox`/`kasirdb` groups |
| `to_user_id` | INT UNSIGNED | NOT NULL | — | New owner (same no-cross-DB-FK rationale) |
| `initiated_by_user_id` | INT UNSIGNED | NOT NULL | — | Session user who performed the Handoff; may differ from `from_user_id` (K-06) |
| `summary` | VARCHAR(4096) | NOT NULL | — | Required Handoff Summary. **P-02:** cap = **4096 characters** (was 500 in v1.0); enforced in the controller as 400 via `mb_strlen`. The migration docblock records `VARCHAR(4096)` with a `TEXT` fallback noted under RISK-03. |
| `next_action` | VARCHAR(4096) | NOT NULL | — | Required Next Action, free text (ASSUMPTION-007; same **4096-character** cap as summary, P-02). |
| `note` | TEXT | NULL | NULL | Optional Handoff Note. Optional and capped at **4096 characters** in the controller (P-02); `TEXT` on purpose, not `VARCHAR(4096)`. |
| `created_at` | DATETIME | NOT NULL | — | Write time in `Asia/Jakarta` (CON-H06) |

Indexes: `KEY idx_handoffs_conversation (conversation_id, id DESC)` — newest-first read-back without filesort. No changes to existing tables.

> [!NOTE]
> **Char vs byte boundary (CR-05).** The 4096 boundary is a **CHARACTER** boundary, not a byte boundary. The server validates with `mb_strlen()` so a legal multi-byte value (accents, emoji) of 4096 characters is accepted and 4097 is rejected (`E11`/`E12`); `VARCHAR(4096)` and the UI `maxlength="4096"` count characters too. Mixing `strlen` here would wrongly reject valid multi-byte text.

### 4.2 New model — `ConversationHandoffModel` (DB group `inbox`)

New file `app/Models/ConversationHandoffModel.php`, mirroring `MessageModel` conventions (`$DBGroup = 'inbox'`, `$table = 'conversation_handoffs'`, `$allowedFields`, `$useTimestamps = false` with explicit `created_at`, `$returnType = 'array'`). Minimal surface: `insertHandoff(array $row): int` and `forConversation(int $conversationId, int $limit = 50): array` (newest-first). No business logic in the model — eligibility, conditional write, and transaction orchestration live in the controller (consistent with existing Inbox code where `Inbox.php` owns the workflows).

### 4.3 Endpoints — Handoff

Both routes carry the existing `auth` filter, like every other `/inbox/percakapan/*` POST route.

**`POST /inbox/percakapan/(:num)/handoff` → `Inbox::handoffPercakapan/$1`**

Request (form-encoded or JSON, same dual-read pattern as `catatanInternal()`):

| Field | Required | Type | Rule |
|---|---|---|---|
| `to_user_id` | yes | int | Existing, active, Handoff-eligible staff (REQ-H03/P-03); must differ from session user (REQ-H05). Non-numeric, `0`, or negative = 400. |
| `summary` | yes | string | **Must be a string** (an array or any non-string = 400). Non-empty after trim, max **4096 characters** (`mb_strlen`, P-02). |
| `next_action` | yes | string | **Must be a string**. Non-empty after trim, max **4096 characters** (`mb_strlen`, P-02). |
| `note` | no | string | Free text, may be empty; if present must be a string; max **4096 characters** (P-02). |
| `expected_owner` | yes | int or null | `assigned_to` as seen by the client (REQ-C01). **Q5:** the field must be PRESENT — absent = 400 (malformed); `null` or empty string = a lawful "saw unassigned" claim forwarded to the NULL-safe `<=>` write, which then decides 200 vs 409. |

Server flow (**Q3 — urutan normatif, LOCKED**):

1. Resolve conversation; **404** if the id is unknown (before any identity query).
2. Recompute `queue_status` via `ConversationModel::withComputedStatus()`; if it is `selesai`, respond **409** (P-01 state-reload family, `current_owner_id` nullable). The conversation is **not** rejected here for any other tab.
3. Validate payload shape; **400** on violation (non-string `summary`/`next_action`/`note`, missing/blank `summary`/`next_action`, over 4096 characters, self-Handoff, malformed `to_user_id`, absent `expected_owner`).
4. **403 initiator gate (P-05/CR-03 = A):** the initiator must be the current assignee; on an unowned conversation the initiator is admitted **only** when `queue_status === 'belum_diambil'` and the initiator is an active kasir. Non-assignee on any other tab = 403 (AC-H08). This gate runs **before** the target gate so state/identity answers stay deterministic.
5. **403 target gate (P-03):** the target must be a member of `UserModel::daftarKasirAktif()`; `admin`, unknown, inactive, or ineligible = 403.
6. **Fail-fast 409 (CR-04 = A1, LOCKED):** if the normalised `expected_owner` differs from the server-read `assigned_to`, respond **409** immediately — **before** `transBegin()` and **no write of any kind** occurs. Placed here (after both 403 gates) so a non-assignee still receives 403 and never leaks the owner's name. Body must be byte-identical to the loser 409 below.
7. **Transaction (REQ-H09):** `transBegin()` on the `inbox` group → conditional write (`SET assigned_to = :to WHERE id = :id AND assigned_to <=> :expected`) → history insert (REQ-H07) → `transCommit()`. Zero affected rows = `transRollback()` + **409** naming the current owner (REQ-C02). History-insert failure = `transRollback()` with ownership unchanged.
8. Success response mirrors `tutupPercakapan()` (`status: success`, Indonesian message, plus the new owner id and the created history id).

> [!IMPORTANT]
> **Q3 — urutan gerbang normatif (LOCKED), ringkas:** **404** → **409 selesai** → **400** validasi → **403 inisiator** → **403 target** → **fail-fast 409** → **transaksi conditional write**. Setiap pelanggaran ganda diselesaikan oleh urutan ini (mis. non-assignee dengan `expected_owner` basi tetap **403**, bukan 409 — dikunci `C01b`).

### 4.3b New endpoint — read Handoff history (P-04)

**`GET /inbox/percakapan/(:num)/handoff` → `Inbox::apiHandoffs/$1`**

- Auth: the existing `auth` filter only. Any active staff may read (Q7/PRD GH-006 "readable again by staff"); there is **no** assignee/participant gate, and this gate is deliberately different from the write path in §4.3.
- 404 only for an unknown conversation id.
- Success `200`: `{ status: 'success', handoffs: [{ id, from_user_id, to_user_id, initiated_by_user_id, summary, next_action, note, created_at }], limit: 50 }`, newest-first, capped at **50** entries (older rows stay in the table; no purge in Fase 2a).
- **`GET /inbox/api/conversations/(:num)/messages` is NOT changed** — the message thread is never mixed with Handoff history (K-05, ASSUMPTION-001).

### 4.4 Collision response contract (K-09)

- `400` — payload/validation family: non-string or blank `summary`/`next_action`, over **4096 characters** (P-02), self-Handoff, malformed `to_user_id`, absent `expected_owner` (Q5).
- `403` — identity family: initiator not the assignee (and not the lawful unowned-`belum_diambil` case), or target not a kasir aktif (P-03). Two 403 gates exist; see the Q3 order. The 403 message has **three branches** (see §4.4 note below).
- `404` — conversation id unknown.
- **`409` — TWO families, one shared body shape (CR-06).** Both families MUST return the same key set:
  - `status` = `'error'`,
  - `message` (Indonesian) — the ownership family names the current owner (with `User #{id}` fallback), the `selesai` family states the conversation is finished,
  - `current_owner_id` — **nullable**, present in **both** families (this closes CR-06; `null` when the conversation is unowned).
  - **Family 1 (state, P-01):** the conversation is in `selesai`. **Family 2 (ownership, REQ-C02 + CR-04 fail-fast):** the conditional write lost, OR the fail-fast pre-check detected a stale `expected_owner`. Both ownership responses must be **byte-identical** so `C02`/`E06` pass without assertion edits.
  - `assigned_to` and history are untouched by any losing request.
- Success — HTTP 200 with the same envelope shape as `tutupPercakapan()` (`status: success`).

> [!NOTE]
> **403 message branches (CR-03 = A).** The initiator gate emits three distinct messages so an active kasir is never misled: **(i)** conversation has an owner but the initiator is not that owner → "Hanya staff yang sedang menangani percakapan ini yang bisa menyerahkannya."; **(ii)** conversation is unowned and in tab `belum_diambil`, but the initiator is not an active kasir → "Hanya kasir aktif yang bisa menyerahkan percakapan yang belum diambil."; **(iii)** conversation is unowned but in **any other** tab (e.g. `menunggu`/`ditunda` after `lepasPercakapan()`) → "Percakapan tanpa pemilik hanya bisa diserahkan dari tab Belum Diambil. Ambil dulu percakapan ini.". Branches (i) and (ii) are verbatim (locked by `E04(c)` and `E04(b)`); branch (iii) is new.

## 5. Acceptance Criteria

- **AC-H01:** Staff A opens an eligible conversation (any Queue View Status except `selesai`), picks staff B, fills `summary` + `next_action`, submits: `assigned_to` becomes B, exactly one `conversation_handoffs` row exists with correct `from/to/initiated_by`, and the history panel shows the new entry newest-first.
- **AC-H02 (P-01):** Handoff on a `selesai` conversation is rejected (**409**, not 403) with a body carrying `current_owner_id` (nullable); ownership and history unchanged. Locked by `H02`/`H02b`.
- **AC-H03:** Handoff with blank `summary` or blank `next_action` is rejected (400); ownership and history unchanged.
- **AC-H04:** Handoff to self is rejected (400); ownership and history unchanged.
- **AC-H05:** Handoff to an unknown/inactive/ineligible user is rejected (403); ownership and history unchanged.
- **AC-H06:** Handoff of an unassigned conversation succeeds and records `from_user_id = NULL`.
- **AC-H07:** The `messages` table gains zero rows from any Handoff (success or rejection); no Gateway call is made; `last_message_*` unchanged.
- **AC-H08 (P-06):** A non-assignee (including an admin who is not the assignee) initiating a Handoff on a conversation that is not a lawful unowned-`belum_diambil` case is rejected with **403**; ownership and history unchanged. Locked by `H08`/`E04`/`C01b` and the narrowing tests `F02`/`F03`.
- **AC-H09 (CR-04 = A1):** When the client's `expected_owner` differs from the server-read `assigned_to`, the request is rejected with **409** before any transaction begins — no write occurs, and `updated_at` is untouched. Locked by `F01`; bodies must match the loser 409 byte-for-byte (`C02`/`E06`).
- **AC-C01:** Two staff submit Handoff for the same conversation with the same `expected_owner`: exactly one succeeds (200), the other receives 409 naming the winner; only one history row exists; `assigned_to` equals the winner's target.
- **AC-C02:** A Handoff with a stale `expected_owner` (ownership changed since the dialog was opened) is rejected (409) without touching ownership or history.
- **AC-C03:** History insert failure rolls back the ownership change (`assigned_to` unchanged, no orphan row).
- **AC-R01:** Full suite `composer test` passes with zero failures (project Definition of Done).

## 6. Test Automation Strategy & Testing Seams

Per the Two-Layer Mandate, every code change ships with tests in the same increment, and the full suite must pass before the phase is declared complete.

- **Migration test:** `conversation_handoffs` table exists on the `inbox` group with the columns, defaults, index, and FK from Section 4.1; existing tables untouched.
- **Model tests (`tests/database/`):** `insertHandoff()` persists all fields incl. NULL `from_user_id`; `forConversation()` returns newest-first capped at 50; following the `ConversationModelComputedStatusTest` precedent (see Fase 1 spec Section 6).
- **Controller tests (`tests/session/`, following the `InboxInternalNoteTest` precedent):** AC-H01–H07 (success shape, 400/403/404 paths, unassigned-from-NULL, messages-table-untouched assertion), AC-C01–C03 (simulated race: second request reuses the first request's `expected_owner` and must get 409; stale-owner 409; forced history-insert failure rolls back ownership).
- **No new testing seams required:** the conditional write is verified through its observable effect (affected-rows-driven 200 vs 409), not by mocking the database layer. The "race" is simulated sequentially — open two logical reads of the same owner value, apply request 1, then apply request 2 with the stale value — which deterministically exercises the same `WHERE assigned_to <=> :expected` path a real race would take.

## 7. Project Structure & Commands

### Project Structure

New/changed files only (all paths repo-root-relative; everything else follows `docs/ARCHITECTURE.md`):

- `app/Database/Migrations/<timestamp>_CreateConversationHandoffs.php` — new table (Section 4.1).
- `app/Models/ConversationHandoffModel.php` — new model (Section 4.2).
- `app/Controllers/Inbox.php` — add `handoffPercakapan($id)` + `apiHandoffs($id)`; no changes to existing methods (REQ-H10).
- `app/Config/Routes.php` — two new routes (Section 4.3).
- `app/Views/inbox/index.php` (+ existing inbox JS) — Handoff dialog (fields per REQ-H04, `expected_owner` captured at dialog open) + history panel rendering + 409-loser notice naming the current owner.
- `tests/database/ConversationHandoffModelTest.php`, `tests/session/InboxHandoffTest.php` — Section 6.
- `docs/ARCHITECTURE.md` — update during `/sdlc-write-code` completion (Living Architecture Map Mandate): new table, model, routes.

### Commands

- `composer test` — full suite, must pass 100% (DoD, AC-R01).
- `php spark migrate --database inbox` (per the project's migration invocation convention) — apply the new additive migration; rollback path verified in tests.

## 8. Code Style & Conventions

Follows Fase 1 spec Section 8: Indonesian-language controller/response messages (GUD-H01 precedent), session-based staff identity, `auth`-filtered routes, `$DBGroup = 'inbox'` on all Inbox models, explicit `Asia/Jakarta` timestamps (CON-H06), additive migrations only (CON-H05), no new third-party libraries. Frontend work reuses the existing inbox view/JS patterns (dialog + fetch + panel render) rather than introducing a new framework or component system.

```php
// Handoff handlers live in app/Controllers/Inbox.php next to the existing
// ownership actions and follow the tutupPercakapan() response envelope:
// { status: 'success' | 'error', message: '<Indonesian sentence>' }.
public function handoffPercakapan(int $id) { /* ... */ }
public function apiHandoffs(int $id) { /* ... */ }
```

## 9. Implementation Boundaries

Guardrails for the `/sdlc-write-code` agent (three-tier system):

- **Always do:** re-validate everything server-side (CON-H04), keep the ownership write and the history insert in one `inbox`-group transaction (REQ-H09), run `composer test` before declaring the increment done (AC-R01).

- The Handoff implementation MUST NOT modify `ambilPercakapan()`, `lepasPercakapan()`, `tutupPercakapan()`, `snoozePercakapan()`, `tandaiDibaca()`, `hapusPercakapan()`, or `catatanInternal()` — it only reuses their patterns (REQ-H10).
- No changes to the `messages` or `conversations` table definitions; no changes to `ConversationModel::withComputedStatus()` semantics; no Gateway contract changes.
- Frontend scope is limited to the Handoff dialog, the history panel, and the 409 notice — no Queue View tab changes, no SLA/filter changes.
- Anything resembling presence (viewing indicators, heartbeats, "currently open by" state) is out of bounds for Fase 2a code review (REQ-C03).

## 10. Rationale, Context & Architecture Decisions (ADRs)

No new ADR is created in Fase 2a. Triple-gate check: the conditional-write primitive already exists (`ambilPercakapan()` precedent, ADR-0001 context); reusing it on one more path is reversible, unsurprising given K-01, and carries no new trade-off beyond what K-01 already decided. The narrow M2 gate itself (K-01) remains documented in `docs/audit/clarification-report-m3-fase2-m2-gate-2026-09-22.md`. If a later increment generalizes conditional writes to all ownership paths (full M2 program), that increment SHOULD record an ADR.

## 11. Dependencies & External Integrations

### External Systems

- WhatsApp Gateway (`tikusgot007/WA-Gateway`): **none** — Handoff never calls the Gateway (CON-H01).
- POS database (`aulia_kasirdb`): read-only user lookup via the existing `UserModel` path; zero writes (CON-H02).

### Infrastructure Dependencies

- MySQL NULL-safe equality (`<=>`) for the conditional write; InnoDB transactions on the `inbox` group for REQ-H09. Both already assumed by the existing codebase.

### Data Dependencies

- `ConversationModel::withComputedStatus()` (eligibility single source, REQ-H02).
- `UserModel::daftarKasirAktif()` (target eligibility, REQ-H03).
- Session authentication + `auth` filter (actor identity, route protection).

## 12. Examples & Edge Cases

**Happy path:** Conversation #123 (`assigned_to = 7`, Queue View Status `open`). Staff 7 opens the Handoff dialog (client records `expected_owner = 7`), selects staff 9, fills summary "Customer asked about bulk pricing; quoted price list v3." and next action "Follow up tomorrow morning if no reply.", submits. Server: eligibility OK → payload OK → target 9 eligible → transaction: conditional write matches (1 row) → history row (`from 7`, `to 9`, `initiated_by 7`) → commit. Response 200; Queue View now shows #123 under staff 9; history panel lists the entry.

**Collision:** Staff 7 and staff 9 both open the dialog while `assigned_to = 7` (both hold `expected_owner = 7`). Staff 7 hands off to 11 (wins, 200). Staff 9's request to hand off to 12 now finds `assigned_to = 11 ≠ 7`: 0 affected rows → rollback → 409 naming staff 11. Staff 9 refreshes, sees the new owner, coordinates with staff 11.

**Edge cases:**

- Unassigned conversation (`assigned_to IS NULL`, status `belum_diambil`): Handoff allowed; `expected_owner` null matches via `<=>`; history records `from_user_id = NULL` (AC-H06).
- `selesai` conversation: rejected even if the dialog was somehow opened (server recomputes; **409** per P-01).
- Empty `note`: accepted (optional). Blank `summary`/`next_action` (whitespace only): 400.
- `to_user_id` equals initiator: 400 even if the initiator is not the current owner.
- Initiator is not the current owner: **rejected 403** (P-05/CR-03 = A) unless the conversation is unowned **and** in `belum_diambil` and the initiator is an active kasir. This supersedes the v1.0 wording that allowed any authenticated staff to hand off someone else's conversation; `from_user_id = initiator` in the unowned case, and `from_user_id = previous owner` otherwise (K-06).
- Unowned conversation outside `belum_diambil` (e.g. `menunggu`/`ditunda` after `lepasPercakapan()`): rejected **403** with the branch-(iii) message, even for an active kasir — an active kasir cannot silently hand off a thread it never claimed. Positive control: after `ambilPercakapan()` the same kasir may Handoff (`F04`).
- Client `expected_owner` stale relative to the server read: rejected **409** by the fail-fast pre-check, before any transaction (`F01`, CR-04 = A1).
- Unknown conversation id: 404 before any validation.
- Concurrent non-Handoff write (e.g. someone claims via `ambilPercakapan()` between dialog open and submit): same 409 path — the conditional write only cares that `assigned_to` moved, not which path moved it.
- History panel pagination: capped at 50 newest; older entries remain in the table (no purge in Fase 2a).

## 13. Validation Criteria

1. Every REQ/CON ID in Section 3 traces to at least one AC in Section 5 and one test bullet in Section 6 (reviewer checks the matrix during `/sdlc-audit-consistency`).
2. No requirement contradicts K-01–K-09; any deviation from the clarification report is flagged as a finding, not silently absorbed.
3. `composer test` passes 100% after implementation (AC-R01).
4. Markdownlint passes on this file (project AGENTS.md mandate; single pre-existing MD025 note: the `# Introduction` + `## 1.` pattern is inherited verbatim from the approved Fase 1 spec, so both spec files report the identical MD025/single-title finding — no new lint regression introduced here).
5. Readiness self-check against the Clarification scoring rubric: Completeness (all Handoff + Collision behaviours, payloads, codes, edge cases specified), Clarity (implementable without guessing — table DDL, endpoint flow order, response codes all explicit), Alignment (100% traceable to PRD v1.1 GH-006/GH-007 and K-01–K-09; no orphaned items — auto-assignment, presence, notifications explicitly excluded).

## 14. Related Specifications / Further Reading

- `prd-20260922-0141-chat-whatsapp-inbox.md` v1.1 (GH-006 Handoff, GH-007 Collision Detection; Deferred: presence, notifications/unread, auto-assignment Fase 2b).
- `docs/audit/clarification-report-m3-fase2-m2-gate-2026-09-22.md` (K-01–K-09 — the normative source for every Decision Log entry in Section 1.2.1).
- `spec/spec-design-m3-operational-inbox-fase1.md` (foundation: Queue View, computed status, Internal Note, Section 6/8 conventions reused here).
- `docs/adr/0001-reuse-response-state-for-queue-view-status.md` (why Queue View Status is computed, not a DB column — background for REQ-H02).
- `docs/ARCHITECTURE.md`, `CONTEXT.md` (glossary source for Section 2).
- `blueprint-m3-operational-inbox.md` (roadmap context; Fase 2b auto-assignment lives there, not here).

## 15. PRD Traceability

| PRD item (v1.1) | Spec coverage |
|---|---|
| GH-006 Handoff antar staff (summary + next action wajib, riwayat permanen) | REQ-H01–H09 (P-01..P-06), Sections 4.1–4.3/4.3b, AC-H01–H09 |
| GH-007 Collision Detection (penolakan + info pemilik sah, tanpa presence) | REQ-C01–C04, Section 4.4, AC-C01–C03 (+ AC-H09 fail-fast) |
| GH-006 AC-3 (initiator gate) | REQ-H01, §4.3 langkah 4, §4.4 branch note, AC-H08, CR-03 = A |
| Fail-fast ownership pre-check (CR-04 = A1) | REQ-C02, §4.3 langkah 6, AC-H09 |
| Deferred: presence (prasyarat bernama K-04) | REQ-C03, Out of Scope, Section 9 boundary |
| Deferred: notifikasi + unread per-user (K-08) | CON-H03, Out of Scope |
| Fase 2b: auto-assignment GH-008 (K-03) | Out of Scope |
| DoD: `composer test` 100% | AC-R01, Section 6 |
