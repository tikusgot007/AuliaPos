---
title: M3 Operational Inbox — Fase 1 (Queue View, Conversation Detail, Snooze, Selesai/Arsip, Internal Note, SLA, Filter)
version: 1.2
date_created: 2026-09-20
last_updated: 2026-09-24
owner: AuliaPos Inbox module
tags: [inbox, chat, whatsapp, m3, operational-inbox]
---

# Introduction

Spesifikasi ini mendefinisikan **M3 — Operational Inbox, Fase 1** (Fase 1a + Fase 1b) dari roadmap besar WhatsApp Inbox: mengubah Inbox dari sekadar viewer chat menjadi *operational customer workspace*. Fase 1 menyambungkan UI baru (Queue View, Conversation Detail, Internal Note, SLA indicator, Filter) ke backend `app/Controllers/Inbox.php` yang sebagian besar **sudah production-ready**, menutup gap yang tersisa (computed status terpadu, Internal Note, filter endpoint).

Dasar spec ini: `blueprint-m3-operational-inbox.md`, `Panduan_Layar_AuliaPos_M3.md`, dan `docs/audit/clarification-report-m3-fase1-operational-inbox-2026-09-20.md` (Readiness Score 92/100, sudah di-merge ke `v2.2`).

> [!NOTE]
> **Revisi 1.1 (2026-09-24), per `docs/audit/consistency-audit-m3-fase1-operational-inbox-2026-09-24.md`:** (1) CT-02 — §9 tidak lagi menyebut `findAll(500)`; kontraknya: tanpa batas baris, 50 per halaman lewat `page` (CL-001/CL-010). (2) CT-03 — §4.3 dan §8 disamakan dengan endpoint nyata `Inbox::catatanInternal()`: form field `teks`, response `{ status, conversation_id, message }`, 400 bila teks > 4096 byte. (3) AC baru: AC-009 (REQ-012 API), AC-010..AC-012 (level layar untuk GH-002, GH-004, Layar 7), selaras dengan plan rev 1.1 TASK-015/016/017. (4) Duplikat bullet `q` di §4.4 dihapus. Backlog kecil ikut dirapikan: §1.1/§2 (Fase 2 vs K-01), §6 (test `is_internal`), urutan AC dan §13.

> [!NOTE]
> **Revisi 1.2 (2026-09-24), per audit yang sama (Iterasi 2, REFINE langkah 3) dan PRD v1.3:** (1) NG-01 / TODO-SEARCH-01 — kontrak **Fase 1d** (GH-009): `q` mencocokkan semua nama/nomor yang tampil di daftar (`contact_name`, `whatsapp_name`, `phone`, `manual_phone`, `chat_id`) — CL-015, REQ-013, CON-003, §4.4, AC-013. TODO-SEARCH-01 ditutup di level Spec. (2) NG-04 — teks `\n` literal di §1.2 diganti baris baru. (3) NG-05 — batas ditulis "4096 byte" di CL-006, contoh §8, dan §12. **Fase 1e (GH-010, pencarian isi pesan) belum dicakup** — lihat §1.1.

## 1. Purpose & Scope

Spec ini mencakup:
- **Fase 1a** (bisa mulai sekarang, backend sudah siap): Queue View (5 tab), Conversation Detail dasar (thread + action bar), Snooze Dialog (tanpa field Alasan), tab Selesai/Arsip.
- **Fase 1b** (butuh migration baru): Internal Note (`is_internal`), SLA Timer + warna prioritas, field Alasan Snooze (via Internal Note), Filter & Pencarian.
- **Fase 1d** (tanpa migration, tanpa perubahan layar): pencarian nama/nomor lengkap (PRD GH-009) — `q` mencocokkan semua nama dan nomor yang tampil di daftar percakapan (REQ-013).

Audiens: developer yang akan mengeksekusi `/sdlc-plan-tasks` → `/sdlc-write-code` untuk modul Inbox AuliaPos v2.2.

Asumsi: seluruh kerja ini dibangun di atas branch turunan `v2.2` (bukan `v2.1`/`v2.x`, yang sengaja tidak punya fitur chat — lihat `docs/TODO-CHAT.md`).

## 1.1 Out of Scope

- **Fase 2** (Handoff, Collision detection, Auto-assignment) — tidak dicakup spec ini. Fase 2a (Handoff + Collision) diatur oleh spec/plan Fase 2a tersendiri, di bawah *constraint* sempit **K-01** PRD v1.1 §2.3 (atomicitas kepemilikan dibuka hanya pada jalur Handoff; M2 State Consistency tetap deferred sebagai program). Auto-assignment tetap menunggu M2. Spec ini tidak mengubah `cekOwnership()`.
- **Fase 3** (AI features: intent filter, AI summary, suggested reply) — menunggu M5.
- **Customer Context penuh** (riwayat order/payment dari modul Transaksi) — ditunda ke **M4**. Fase 1 Customer Context dibatasi ke data Inbox sendiri (nama, nomor, riwayat percakapan, internal note).
- **@mention dengan notifikasi nyata** (tabel `message_mentions`, mekanisme notifikasi) — ditunda ke Fase 2.
- Perubahan apa pun pada Gateway WhatsApp (Node.js/Baileys, repo terpisah `tikusgot007/WA-Gateway`) — Fase 1 murni sisi AuliaPos (CI4).
- **Fase 1e — pencarian isi pesan** (PRD GH-010: pesan pelanggan, balasan staff, Internal Note, plus potongan pesan yang cocok di hasil) — belum dicakup spec ini; akan ditulis di revisi spec tersendiri, termasuk rancangan kecepatan pencarian.
- **Pencarian berdasarkan data nota/transaksi POS** — ditunda ke M4 (PRD §2.3).
- **Normalisasi format nomor telepon** saat mencari (mis. `0812…` dianggap sama dengan `62812…`) — tidak dilakukan; nomor dicocokkan apa adanya (ASSUMPTION-001).
- M1 (Reliability, repo Gateway) tidak menjadi prasyarat teknis untuk Fase 1a/1b AuliaPos (tidak ada dependency kode), tapi tetap relevan secara operasional (data yang ditampilkan Queue View baru berguna kalau pesan masuk/keluar reliable).

## 1.2 Open Questions & Assumptions

> [!NOTE]
> **Klarifikasi berjalan — keputusan dicatat langsung di Blueprint M3.** Setiap keputusan baru pada sesi clarification wajib ditulis di dokumen ini agar tidak dibahas ulang.

Semua ambiguitas mayor sudah diselesaikan lewat sesi `/sdlc-clarify-reqs` (lihat `docs/audit/clarification-report-m3-fase1-operational-inbox-2026-09-20.md`). Ketiga asumsi teknis minor yang sebelumnya ditandai `[!WARNING]` sudah digali eksplisit ke user dan **dikonfirmasi final** lewat sesi klarifikasi kedua (lihat `docs/audit/clarification-report-m3-fase1-operational-inbox-spec-2026-09-20.md`, Readiness Score 87/100):

> [!IMPORTANT]
> **ASSUMPTION-001 — CONFIRMED:** Filter & Pencarian (Layar 7, Fase 1b) ditambahkan sebagai parameter query string baru di `GET /inbox/api/conversations` (`Inbox::apiConversations()`) — bukan endpoint baru. Query final: `?status=<tab>&q=<keyword nama/nomor>`. **Kontrak pencarian tidak boleh dibatasi hanya pada conversation terbaru atau limit tetap tertentu**: ketika `q` diberikan, hasil harus dapat menemukan conversation yang sesuai di seluruh dataset conversation yang relevan, termasuk conversation lama. Mekanisme teknis boleh menggunakan query DB/pagination/index atau cara lain yang tetap memenuhi kontrak ini; Blueprint tidak mengunci implementasi internal. Parameter `q` memakai pencarian terhadap `contact_name` atau `phone`, tanpa normalisasi format nomor telepon. **Diperluas di Fase 1d (rev 1.2, CL-015/REQ-013):** `q` mencocokkan kelima kolom identitas yang bisa tampil di daftar — `contact_name`, `whatsapp_name`, `phone`, `manual_phone`, `chat_id` — tetap tanpa normalisasi nomor. Detail lengkap: lihat Bagian 4.4.

> [!IMPORTANT]
> **ASSUMPTION-002 — CONFIRMED:** SLA warna (Bagian 5) dihitung **hanya untuk conversation yang statusnya bukan `selesai` dan bukan `follow_up` (snoozed)** — snoozed conversation sengaja tidak diberi warna SLA merah/kuning karena secara desain memang "ditunda dengan sengaja", bukan terlambat. Dikonfirmasi eksplisit bahwa Response State `menunggu_customer` **tetap ikut** dihitung warna SLA (sesuai AC-005 apa adanya) — SLA di sini mengukur usia percakapan sejak `last_message_at`, bukan spesifik kecepatan respons staff, sehingga warna pada tab "Menunggu" berfungsi sebagai reminder follow-up manual ke customer yang lama tidak merespons.

> [!IMPORTANT]
> **ASSUMPTION-003 — CONFIRMED:** Kolom `is_internal` pada `messages` diberi `default => false` dan **tidak nullable**. Justifikasi dikoreksi dari draf awal: klaim "konsisten dengan pola boolean lain di skema Inbox" tidak akurat — verifikasi ke `2026-09-07-000001_CreateInboxTables.php` dan `2026-09-19-000001_AddResponseStateFoundation.php` menunjukkan **tidak ada satu pun kolom `BOOLEAN`** di skema Inbox sampai saat ini. Preseden yang benar adalah pola `tinyint(1) NOT NULL DEFAULT ...` (`is_locked`, `aktif`) di modul POS (`2026-09-08-000001_CreateAuliaPosCore.php`). Kesimpulan (`NOT NULL DEFAULT FALSE`) tetap valid atas dasar ini. Baris lama (sebelum migration) otomatis terisi `false` lewat default kolom saat `ADD COLUMN`, tidak perlu backfill manual.

Sebagai gap tambahan yang ditemukan lewat verifikasi kode saat sesi klarifikasi kedua (di luar 3 ASSUMPTION di atas), dua hal berikut juga sudah diresolusi dan tercermin di Bagian 3/4.3/12: (a) endpoint Internal Note diizinkan ditulis pada conversation berstatus `closed` tanpa pembatasan tambahan; (b) REQ-009 direvisi karena `conversations.last_message_at`/`last_message_direction` adalah kolom denormalized yang di-`update()` manual di titik insert pesan (bukan hasil query agregasi) — lihat REQ-009 dan Bagian 12.

> [!NOTE]
> **Aturan klarifikasi:** hanya keputusan yang memengaruhi perilaku bisnis atau kontrak publik yang perlu dikunci lewat sesi klarifikasi. Detail implementasi dan edge case teknis yang tidak membutuhkan keputusan bisnis diselesaikan saat implementasi melalui guard/handling yang wajar dan test.

### 1.2.1 Decision Log — hasil klarifikasi sesi berjalan

| ID | Keputusan | Dampak pada Blueprint |
|---|---|---|
| CL-001 | **Search `q` harus mencari seluruh conversation yang relevan, bukan hanya 500 terbaru.** Cara teknisnya boleh berubah/dioptimalkan setelah sistem berjalan. | ASSUMPTION-001 dan Bagian 4.4 direvisi; tidak ada kontrak bisnis `limit=500` untuk search. |
| CL-002 | **`status` yang tidak termasuk 5 status Queue View harus ditolak dengan HTTP `400 Bad Request`.** Tidak boleh diam-diam diabaikan sebagai tanpa filter. | Bagian 4.4 menetapkan `status` sebagai enum; implementasi wajib memvalidasi nilai dan mengembalikan `400` untuk nilai lain. |
| CL-003 | **Jika `status` dan `q` dipakai bersama, keduanya harus berlaku sekaligus (AND).** Contoh `status=open&q=Budi` hanya menampilkan conversation yang statusnya `open` dan cocok dengan pencarian `Budi`. | Bagian 4.4 menetapkan kombinasi filter sebagai satu request; implementasi tidak boleh memperlakukan `q` sebagai pencarian terpisah. |
| CL-004 | **Semua staff yang login boleh melihat semua conversation.** Ownership tidak membatasi visibility; ownership hanya membatasi aksi yang memang mensyaratkannya (mis. balas/snooze/hapus sesuai aturan existing). | Kontrak visibility Queue View dan daftar conversation ditetapkan sebagai shared inbox untuk semua staff login. |
| CL-005 | **Jika pencarian/filter tidak menemukan hasil, endpoint tetap mengembalikan HTTP 200 dengan hasil kosong `[]`.** Ini bukan kondisi `404`. | Perilaku empty result ditetapkan sebagai hasil normal dari filter/pencarian. |
| CL-006 | **Alasan Snooze maksimal 4096 byte** (UTF-8, `strlen()`; huruf/emoji multi-byte memakan lebih dari 1 byte), sama dengan batas panjang Internal Note (§4.3). Jika melebihi batas, request ditolak `400` dan perubahan Snooze tidak dilakukan. | Batas validasi alasan ditetapkan di kontrak Snooze Fase 1b. |
| CL-007 | **Nilai `q` yang setelah di-trim hanya berisi spasi dianggap kosong.** Sistem tidak menjalankan pencarian untuk nilai tersebut; hasil mengikuti filter `status` bila ada. | Input pencarian harus di-trim sebelum dipakai sebagai keyword. |
| CL-008 | **Karakter `%` dan `_` pada `q` diperlakukan sebagai teks biasa, bukan wildcard SQL.** | Pencarian harus meng-escape wildcard tersebut sebelum menjalankan `LIKE`, sehingga keyword dicari apa adanya. |
| CL-009 | **Panjang `q` maksimal 255 karakter.** Jika setelah trim panjangnya lebih dari 255 karakter, request ditolak dengan HTTP `400` dan tidak menjalankan pencarian. | Batas input pencarian ditetapkan eksplisit di kontrak API. |
| CL-010 | **Hasil `GET /inbox/api/conversations` ditampilkan bertahap, 50 conversation per halaman.** Search `q` tetap berlaku ke seluruh dataset yang relevan; pagination hanya mengatur hasil yang dikirim per halaman. | Kontrak response perlu mendukung pagination; implementasi tidak boleh memotong search hanya ke 50/500 data terbaru. |
| CL-011 | **Pagination menggunakan parameter `page`, dimulai dari `page=1`.** | Endpoint `GET /inbox/api/conversations` harus mendukung pagination berbasis halaman; implementasi tidak memakai `offset` sebagai kontrak publik. |
| CL-012 | **Nilai `page` harus bilangan bulat positif mulai dari `1`.** `page=0`, nilai negatif, atau nilai yang bukan angka valid ditolak dengan HTTP `400`. | Validasi parameter pagination wajib dilakukan di API sebelum query diproses. |
| CL-013 | **Jika `page` valid tetapi tidak ada data pada halaman tersebut, endpoint tetap mengembalikan HTTP `200` dengan hasil kosong `[]`.** | Halaman di luar jumlah data dianggap empty result normal, bukan `404` atau `400`. |
| CL-014 | **Jika `last_message_at` kosong/null, `sla_color` harus `null`** dan conversation tidak diberi warna SLA. | SLA hanya dihitung bila timestamp pesan terakhir tersedia. |
| CL-015 | **(Fase 1d, PRD v1.3 GH-009, menutup TODO-SEARCH-01)** `q` cocok bila terkandung di **salah satu** dari lima kolom identitas yang bisa tampil di daftar: `contact_name`, `whatsapp_name`, `phone`, `manual_phone`, `chat_id` — **selalu kelimanya**, tidak peduli kolom mana yang sedang tampil. | REQ-013 dan §4.4 menggantikan cakupan lama (`contact_name`/`phone` saja). Parameter, validasi, pagination, dan bentuk response tidak berubah. |

## 2. Definitions

Istilah berikut mengikuti dokumen sumber (`Panduan_Layar_AuliaPos_M3.md`, `docs/CHAT.md`) — belum ada `CONTEXT.md` domain glossary terpisah untuk modul Inbox, jadi definisi ini yang jadi rujukan:

| Istilah | Definisi | _Avoid_ |
|---|---|---|
| **Response State** | Status computed existing (Tahap A) di `Inbox::attachResponseState()`: `selesai` / `follow_up` / `perlu_dibalas` / `menunggu_customer`. | "status balasan", "reply status" |
| **Queue View Status** | Status computed BARU (Fase 1a) untuk 5 tab: Belum Diambil / Open / Menunggu / Ditunda / Selesai — **turunan** dari Response State + `assigned_to` (lihat ADR-0001). | "display status", "queue status" sebagai kolom DB |
| **Internal Note** | Catatan staff yang TIDAK terkirim ke WhatsApp, disimpan sebagai baris `messages` dengan `is_internal = TRUE`. | "catatan internal", "note" saja (ambigu dengan pesan biasa) |
| **SLA Timer** | Indikator warna (hijau/kuning/merah) berdasarkan usia `last_message_at`, dihitung real-time di UI, tidak disimpan di DB. | "prioritas" (istilah ini sudah dipakai domain lain, lihat `docs/USER-SHIFT.md`) |
| **Handoff** | Perpindahan `assigned_to` dari satu staff ke staff lain dengan ringkasan/next action tersimpan. **Fase 2a, di luar scope spec ini** (diatur spec Fase 2a, constraint K-01 PRD §2.3). | — |

## 3. Requirements, Constraints & Guidelines

### Fase 1a

- **REQ-001**: Queue View menampilkan 5 tab (Belum Diambil, Open, Menunggu, Ditunda, Selesai), masing-masing hasil filter dari satu sumber computed status.
- **REQ-002**: Logika computed status hidup **satu-satunya** di `ConversationModel::withComputedStatus()`, yang **reuse** `Inbox::attachResponseState()` (bukan reimplementasi kondisi WHERE yang sama) — lihat `docs/adr/0001-reuse-response-state-for-queue-view-status.md`.
- **REQ-003**: Tab "Belum Diambil" = Response State `perlu_dibalas` DAN `assigned_to IS NULL`. Tab "Open" = Response State `perlu_dibalas` DAN `assigned_to IS NOT NULL`.
- **REQ-004**: Tab "Menunggu" = Response State `menunggu_customer`. Tab "Ditunda" = Response State `follow_up`. Tab "Selesai" = Response State `selesai`.
- **REQ-005**: Conversation Detail dasar menampilkan thread pesan (`GET /inbox/api/conversations/(:num)/messages`, sudah ada) + action bar (Balas, Ambil/Lepas, Snooze, Selesai) — semua memanggil endpoint existing, tidak ada endpoint baru di Fase 1a.
- **REQ-006**: Snooze Dialog (Fase 1a) hanya menerima input durasi (`menit`), TANPA field Alasan — konsisten dengan `Inbox::snoozePercakapan()` existing yang cuma menerima parameter `menit`.
- **CON-001**: Fase 1a tidak boleh menambah migration/kolom DB baru — murni kerja frontend memanggil endpoint existing.

### Fase 1b

- **REQ-007**: Migration baru menambah kolom `is_internal BOOLEAN NOT NULL DEFAULT FALSE` pada tabel `messages` (koneksi `inbox`, additive, mengikuti pola `2026-09-19-000001_AddResponseStateFoundation.php`).
- **REQ-008**: Endpoint baru untuk menulis Internal Note (POST) — menyisipkan baris ke `messages` dengan `is_internal = TRUE`, `direction` **TIDAK** dikirim ke Gateway (tidak memanggil `POST /send` Gateway sama sekali). Endpoint ini **diizinkan dipanggil pada conversation berstatus apa pun, termasuk `closed`** (Response State `selesai`) — tidak ada pembatasan status conversation, konsisten dengan SEC-001 yang sudah permisif dan dengan REQ-009 yang menjamin Internal Note tidak pernah mengubah `response_state`/SLA, sehingga tidak ada risiko integritas yang perlu dijaga dengan mengunci status (berbeda dari `TransaksiModel` yang mengunci status final demi integritas finansial).
- **SEC-001**: Penulisan Internal Note **tidak** melalui `cekOwnership()` — staff manapun (assigned atau tidak, bukan hanya admin) boleh menulis Internal Note ke conversation manapun. Ini beda eksplisit dari aturan balas/hapus/snooze yang tetap terkunci `cekOwnership()`.
- **REQ-009**: `conversations.last_message_at` dan `conversations.last_message_direction` **BUKAN** hasil query agregasi/trigger dari tabel `messages` — keduanya adalah kolom denormalized yang di-`update()` secara eksplisit di setiap titik insert pesan existing (`Inbox::kirim()`, endpoint balas tagihan, `InboxGatewayApi::messages()`). Karena itu, tidak ada "query WHERE" yang perlu difilter. Yang **WAJIB** dipatuhi: endpoint Internal Note baru (REQ-008) **TIDAK BOLEH memanggil `ConversationModel::update()` untuk kolom `last_message_at`/`last_message_direction`** sama sekali — berbeda dari titik-titik insert pesan lain yang melakukannya. Insert-nya cukup menulis ke tabel `messages` dengan `is_internal = TRUE`, tanpa menyentuh tabel `conversations`.
- **REQ-010**: SLA Timer dihitung di sisi UI/response payload dari `last_message_at`, threshold: Hijau `< 15 menit`, Kuning `15–60 menit`, Merah `> 60 menit`. Threshold disimpan sebagai konstanta di `app/Config/Inbox.php` (properti baru, bukan tabel setting).
- **REQ-011**: Field Alasan pada Snooze Dialog (Fase 1b) ditulis sebagai baris Internal Note otomatis saat staff snooze dengan alasan diisi — **tidak ada kolom `snooze_reason` baru** di `conversations`.
- **REQ-012**: `GET /inbox/api/conversations` diperluas menerima parameter filter (lihat ASSUMPTION-001) untuk Layar 7 (Filter & Pencarian) — tab status dan pencarian nama/nomor.
- **CON-002**: Internal Note tidak muncul dalam payload apa pun yang dikirim ke Gateway WhatsApp — pemisahan ini harus eksplisit di level query (bukan filter di frontend saja), sesuai prinsip "backend re-validates every time" (`CLAUDE.md`).
- **GUD-001**: Semua migration Fase 1b bersifat additive (tambah kolom/tanpa ALTER destruktif), konsisten dengan konvensi skema proyek (`CLAUDE.md`: "baseline + ADD COLUMN migrations").

### Fase 1d (PRD v1.3 GH-009)

- **REQ-013**: Pencarian `q` pada `GET /inbox/api/conversations` mencocokkan **semua nama dan nomor yang bisa tampil di daftar percakapan**. Daftar menampilkan nama = `contact_name` → `whatsapp_name` → `phone` → `chat_id` (nilai pertama yang tidak kosong) dan nomor = `manual_phone` → `phone`. Karena itu `q` wajib dicocokkan ke kelima kolom `contact_name`, `whatsapp_name`, `phone`, `manual_phone`, `chat_id` (CL-015). Conversation cocok bila `q` terkandung di **minimal satu** kolom tersebut. Aturan lain `q` tetap berlaku (§4.4): tidak membedakan huruf besar/kecil, di-trim, `%`/`_` sebagai teks biasa, maks. 255 karakter, AND dengan `status`, seluruh dataset, 50 per halaman.
- **CON-003**: Fase 1d tidak menambah migration, parameter, maupun perubahan layar. Kotak pencarian Fase 1c sudah mengirim `q` ke server (AC-012), jadi perluasan kolom langsung terlihat di layar. Batas ini membuat Fase 1d cukup berupa satu perubahan predikat pencarian di `apiConversations()` beserta test-nya.

## 4. Interfaces & Data Contracts

### 4.1 Skema baru — `messages.is_internal` (Fase 1b)

```php
// Migration, pola sama seperti 2026-09-19-000001_AddResponseStateFoundation.php
$this->forge->addColumn('messages', [
    'is_internal' => [
        'type' => 'BOOLEAN', 'null' => false, 'default' => false, 'after' => 'direction',
    ],
]);
```

### 4.2 `ConversationModel::withComputedStatus(array $conversations): array` (Fase 1a, baru)

Input: array conversation (hasil `findAll()`, sama seperti yang dikonsumsi `attachResponseState()` sekarang).
Output: array conversation yang sama + key baru `queue_status` (string, salah satu dari: `belum_diambil`, `open`, `menunggu`, `ditunda`, `selesai`).

Kontrak internal: method ini **memanggil** `attachResponseState()` (atau logika yang setara di-porting ke Model — lihat REQ-002) untuk mendapat `response_state`, lalu memetakan ke `queue_status` sesuai REQ-003/REQ-004. Tidak ada query SQL WHERE baru yang menduplikasi kondisi `attachResponseState()`.

### 4.3 Endpoint baru — Internal Note (Fase 1b)

```
POST /inbox/percakapan/(:num)/catatan
Body (form data, application/x-www-form-urlencoded): teks=<string>
Response 200: { "status": "success", "conversation_id": int, "message": { ...baris messages yang baru disimpan, termasuk "is_internal": true dan nama pengirim } }
Response 404: { "status": "error", "message": "Conversation tidak ditemukan." }
Response 400: { "status": "error", "message": ... } bila teks kosong setelah trim, ATAU teks > 4096 byte
```

- `teks` dibaca sebagai **form field** (`getPost('teks')`), bukan JSON body. Nilainya di-trim dulu.
- Batas panjang: **4096 byte** (`strlen()`, bukan `mb_strlen()`). Teks dengan huruf/emoji multi-byte bisa ditolak walau jumlah karakternya < 4096. Layar wajib memakai ukuran byte UTF-8 yang sama untuk validasi sisi klien (pola `SNOOZE_ALASAN_MAKS_BYTE`). Batas ini juga berlaku untuk Alasan Snooze (CL-006), karena alasan disimpan lewat endpoint yang sama.
- Urutan cek: conversation ditemukan (404) → teks kosong (400) → teks > 4096 byte (400) → insert.

Tidak melalui `cekOwnership()` (lihat SEC-001). Insert ke `messages` dengan `is_internal = TRUE`, `direction` diasumsikan `outgoing` (arah tidak relevan tapi field NOT NULL di skema existing — pakai `outgoing` sebagai nilai netral, TIDAK memicu pengiriman apa pun ke Gateway).

### 4.4 Endpoint diperluas — `GET /inbox/api/conversations` (Fase 1b)

Parameter baru (lihat ASSUMPTION-001 — CONFIRMED):
- `page` (opsional): nomor halaman, mulai dari `1`. Jika tidak diisi, gunakan `page=1`. Nilai `page` yang bukan bilangan bulat positif (termasuk `0` atau negatif) wajib menghasilkan HTTP 400. `page` yang dikirim tapi kosong (`?page=`) juga dianggap tidak valid (HTTP 400); hanya `page` yang tidak dikirim sama sekali yang memakai `page=1`. Jika `page` valid tetapi melewati halaman terakhir, response tetap HTTP 200 dengan array kosong `[]`.
- `status` (opsional): salah satu dari `belum_diambil|open|menunggu|ditunda|selesai`, filter tab Queue View. **Nilai selain enum tersebut wajib ditolak dengan HTTP 400.**
- `q` (opsional): keyword, cocok bila **terkandung** di minimal satu dari `contact_name`, `whatsapp_name`, `phone`, `manual_phone`, `chat_id` (Fase 1d, REQ-013/CL-015; sebelum rev 1.2 hanya `contact_name`/`phone`). Pencocokan setara `LIKE '%q%'`, per kolom: `q` tidak dicocokkan ke gabungan beberapa kolom. Nilai mentah, tanpa normalisasi format nomor telepon, tidak membedakan huruf besar/kecil. Kolom yang `NULL`/kosong dianggap tidak cocok dan tidak boleh menimbulkan error. Nilai `q` harus di-trim; bila hasil trim kosong, perlakukan sebagai tidak ada filter pencarian. Karakter `%` dan `_` harus diperlakukan sebagai teks biasa, bukan wildcard. Panjang `q` maksimal 255 karakter; nilai yang lebih panjang wajib menghasilkan HTTP 400.
- Bila filter `status`/`q` valid tetapi tidak ada conversation yang cocok, response tetap **HTTP 200** dengan array hasil kosong `[]`.

**Bentuk response 200:** `{ "status": "success", "conversations": [ ... ] }`. Setiap "array kosong `[]`" di bagian ini dan di CL-005/CL-013 berarti `conversations: []`. Response 400 berbentuk `{ "status": "error", "message": ... }`.

**Kontrak hasil:**
1. `q` harus dapat menemukan conversation yang cocok di seluruh dataset yang relevan, termasuk conversation lama; tidak boleh ada batas implisit "hanya N conversation terbaru" sebagai bagian dari kontrak bisnis M3.
2. Hasil dikirim **50 conversation per halaman**. Pagination mengatur hasil yang dikirim, bukan membatasi dataset yang dicari.
3. `status` tetap memfilter menggunakan `queue_status` hasil `withComputedStatus()`, bukan menduplikasi logika status di tempat lain.
4. Cara teknis mencapai kontrak tersebut (query SQL, pagination, index, filter-after-fetch, atau kombinasi) boleh dipilih saat implementasi dan **tidak dikunci oleh Blueprint** selama hasil pencarian lengkap dan tidak menduplikasi sumber computed status.

Response payload conversation bertambah key: `queue_status` (4.2), dan (Fase 1b) `sla_color` (`hijau|kuning|merah|null`, `null` untuk `selesai`/`ditunda`; **`menunggu_customer` tetap dihitung** — lihat ASSUMPTION-002 — CONFIRMED).

## 5. Acceptance Criteria

- **AC-001**: Given conversation `assigned_to = NULL` dan Response State `perlu_dibalas`, When Queue View di-load, Then conversation muncul di tab "Belum Diambil" saja.
- **AC-002**: Given conversation `assigned_to = <user aktif>` dan Response State `perlu_dibalas`, When Queue View di-load, Then conversation muncul di tab "Open" saja.
- **AC-003**: Given staff menulis Internal Note pada conversation dengan Response State `menunggu_customer`, When Queue View di-refresh, Then conversation TETAP di tab "Menunggu" (tidak berpindah ke "Open"/"Belum Diambil").
- **AC-004**: Given staff BUKAN assignee menulis Internal Note pada conversation yang di-assign staff lain, When request dikirim, Then request BERHASIL (200), tidak ditolak `cekOwnership()`.
- **AC-005**: Given `last_message_at` = 20 menit lalu dan conversation berstatus `perlu_dibalas`/`menunggu_customer`, When SLA dihitung, Then warna = kuning.
- **AC-006**: Given conversation berstatus `ditunda` (snoozed), When SLA dihitung, Then warna = `null` (tidak diwarnai merah/kuning) — sesuai ASSUMPTION-002.
- **AC-007**: Given staff mengisi field Alasan di Snooze Dialog (Fase 1b), When snooze disimpan, Then muncul 1 baris Internal Note baru berisi alasan tersebut, dan TIDAK ada kolom `snooze_reason` yang terisi (kolom itu tidak ada).
- **AC-008**: Given conversation `last_message_at = NULL`, When SLA dihitung, Then `sla_color = null`.

### AC Filter & Pencarian — API (REQ-012)

- **AC-009**: `GET /inbox/api/conversations` dengan `status`, `q`, dan `page`:
  - (a) Given ada 60+ conversation dan satu conversation `selesai` bernama "Budi" dengan `last_message_at` paling lama (di luar 50 terbaru), When `?status=selesai&q=budi`, Then HTTP 200 dan `conversations` berisi conversation "Budi" itu (CL-001, CL-003, tidak membedakan huruf besar/kecil).
  - (b) Given `status=open&q=Budi`, Then hasil hanya conversation yang `queue_status = open` **dan** namanya/nomornya mengandung "Budi" (AND, CL-003).
  - (c) Given tidak ada conversation yang cocok dengan `q`/`status`, Then HTTP 200 dengan `conversations: []` (CL-005). Given `page` valid tetapi melewati halaman terakhir, Then HTTP 200 dengan `conversations: []` (CL-013).
  - (d) Given hasil filter berjumlah 60, When `page=1` lalu `page=2`, Then masing-masing berisi 50 dan 10 conversation, tanpa duplikat. Tanpa `page`, hasilnya sama dengan `page=1` (CL-010, CL-011).
  - (e) Given `q` hanya berisi spasi, Then diperlakukan tanpa pencarian (CL-007). Given `q = "50%"`, Then hanya nama/nomor yang mengandung teks "50%" apa adanya yang cocok (CL-008).
  - (f) Given `status` di luar 5 nilai enum, ATAU `q` > 255 karakter setelah trim, ATAU `page` bukan bilangan bulat ≥ 1 (termasuk `?page=`), Then HTTP 400 (CL-002, CL-009, CL-012).

### AC level layar (Inbox, `app/Views/inbox/`)

> Test otomatis tidak bisa menjalankan JS di proyek ini. AC-010..AC-012 diverifikasi lewat test render halaman (elemen ada di HTML) + cek manual di browser (lihat §6).

- **AC-010 (GH-002, Internal Note mandiri)**:
  - (a) Given kasir membuka Conversation Detail milik staff lain (kasir itu bukan assignee), When menekan tombol "Catatan Internal", mengisi "cek stok" lalu Simpan, Then thread menampilkan "cek stok" dengan label "Internal", conversation tetap di tab yang sama, dan tidak ada pesan WhatsApp yang terkirim (SEC-001, CON-002, AC-003).
  - (b) Given conversation berstatus `selesai`, Then tombol "Catatan Internal" tetap aktif dan note berhasil disimpan (REQ-008).
  - (c) Given teks kosong/hanya spasi, ATAU lebih dari 4096 byte UTF-8, When Simpan ditekan, Then muncul peringatan dan **tidak ada request** yang dikirim.
  - (d) Given server menolak atau jaringan gagal, Then muncul pesan error, dialog tetap terbuka, dan teks yang sudah diketik tidak hilang.
  - (e) Tombol ini tidak bergantung pada `assigned_to`/ownership, dan note tidak dikirim lewat jalur Balas/Gateway.
- **AC-011 (GH-004, warna SLA Timer di daftar)**:
  - (a) Given daftar conversation sudah di-refresh dari `GET /inbox/api/conversations`, When `sla_color` bernilai `hijau`/`kuning`/`merah`, Then di samping nama tampil titik warna hijau/kuning/merah dengan tooltip arti ("< 15 menit", "15–60 menit", "> 60 menit").
  - (b) Given `sla_color` `null`/tidak ada/nilai lain (mis. tab Ditunda/Selesai, `last_message_at` null, atau tampilan pertama dari SSR `index()`), Then tidak ada titik warna dan tidak ada error.
  - (c) Warna hanya diambil dari nilai server; layar tidak menghitung SLA sendiri (REQ-010). Badge Response State, pemilik, dan closed yang sudah ada tetap tampil.
  - *Batasan yang diterima:* di tampilan pertama, titik warna baru muncul setelah refresh otomatis pertama (maks. ±6 detik), karena `index()` tidak mengirim `sla_color`.
- **AC-012 (Layar 7, pencarian nama/nomor)**:
  - (a) Given conversation lama `selesai` "Budi" di luar 50 baris pertama, When kasir mengetik "budi" di kotak pencarian lalu Enter, Then "Budi" muncul di tab Selesai dan angka tab Selesai = 1. Pencarian dikirim ke server sebagai `q`, bukan disaring di JS (CL-001, CL-003).
  - (b) Angka di tiap tab mengikuti hasil pencarian.
  - (c) Refresh otomatis tetap memakai kata kunci yang aktif; daftar tidak kembali ke semua conversation.
  - (d) Given tidak ada hasil, Then tampil "Tidak ditemukan percakapan untuk \"<kata kunci>\"." (CL-005). Given kata kunci hanya spasi, Then dianggap kosong dan tidak ada `q` yang dikirim (CL-007).
  - (e) Tombol ✕ menghapus kata kunci dan memuat ulang semua conversation.
  - (f) Given conversation yang sedang dibuka tidak ada di hasil pencarian, Then panel Conversation Detail dan tombol-tombolnya tetap berfungsi.
  - (g) Given request pencarian gagal (mis. 400), Then muncul satu pesan error dan daftar sebelumnya tetap tampil.

### AC Pencarian nama/nomor lengkap — Fase 1d (REQ-013, PRD GH-009)

- **AC-013**: `GET /inbox/api/conversations?q=...` mencocokkan semua nama/nomor yang tampil di daftar:
  - (a) Given conversation dengan `contact_name = NULL` dan `whatsapp_name = "Budi Cetak"` (daftar menampilkan "Budi Cetak"), When `?q=budi cetak`, Then conversation itu ada di `conversations` (GH-009 kriteria 1).
  - (b) Given empat conversation berbeda yang masing-masing hanya cocok lewat satu kolom — `contact_name`, `whatsapp_name`, `phone`, atau `manual_phone` — When `q` berisi potongan nilai kolom tersebut, Then masing-masing ditemukan (GH-009 kriteria 2).
  - (c) Given conversation tanpa nama dan tanpa `phone` (daftar menampilkan `chat_id`, mis. conversation `@lid`), When `q` berisi potongan `chat_id`-nya, Then conversation itu ditemukan.
  - (d) Given conversation `selesai` bernama WhatsApp "Budi Cetak" dengan `last_message_at` di luar 50 terbaru, When `?status=selesai&q=BUDI CETAK`, Then conversation itu ditemukan; When `?status=open&q=budi cetak`, Then tidak ikut (seluruh dataset, huruf besar/kecil, AND — GH-009 kriteria 3 dan 4, CL-001/CL-003).
  - (e) Given `contact_name = "Jamet"` dan `whatsapp_name = "Budi Cetak"` (daftar hanya menampilkan "Jamet"), When `?q=budi cetak`, Then conversation itu tetap ditemukan (CL-015: kelima kolom selalu dicari).
  - (f) Given `contact_name = "Budi"` dan `phone = "62812"`, When `?q=budi 62812`, Then conversation itu **tidak** cocok (per kolom, bukan gabungan).
  - (g) Given kolom `whatsapp_name`/`manual_phone` bernilai `NULL`, When pencarian apa pun, Then tidak ada error dan conversation tetap dinilai dari kolom lain.
  - (h) Level layar: Given conversation dari (a), When kasir mengetik "budi cetak" di kotak pencarian lalu Enter, Then "Budi Cetak" muncul di tab yang sesuai (dicek manual; kotak pencarian tidak berubah, CON-003).

## 6. Test Automation Strategy & Testing Seams

- **Testing Seams**: Prioritaskan seam tertinggi yang sudah ada — controller HTTP boundary (`tests/session/` untuk endpoint baru & yang diperluas) dan Model boundary murni (`tests/database/` untuk `ConversationModel::withComputedStatus()`). Hindari testing lewat browser/JS untuk logic computed status.
- **Test Levels**:
  - `tests/database/` — `ConversationModel::withComputedStatus()` per kombinasi `response_state` × `assigned_to` (5 tab). *(rev 1.1: tidak ada test "filter `is_internal = FALSE` pada query `last_message_at`" — query seperti itu tidak ada, lihat REQ-009. Perlindungannya adalah test endpoint di bawah yang memastikan `last_message_at`/`last_message_direction` tidak berubah.)*
  - `tests/session/` — endpoint POST Internal Note (akses tanpa `cekOwnership()`, AC-004; `conversations.last_message_at`/`last_message_direction` tidak berubah setelah note, REQ-009; 400 untuk teks kosong dan > 4096 byte), endpoint `GET /inbox/api/conversations` dengan parameter `status`/`q`/`page` (AC-009), dan (Fase 1d) `q` terhadap kelima kolom identitas (AC-013 a–g). Test AC-013 ditambahkan ke test session `apiConversations` yang sudah ada (seam yang sama dengan AC-009), bukan seam baru.
  - `tests/session/` (level layar) — GET `/inbox` sebagai kasir, lalu assert HTML berisi elemen dialog Internal Note, kotak pencarian, dan JS yang membaca `sla_color` serta mengirim `q` (AC-010..AC-012). Perilaku JS (klik, toast, polling) dicek manual di browser dengan checklist per poin AC, karena proyek ini tidak punya test runner JS.
  - `tests/unit/` — kalkulasi warna SLA (`hijau|kuning|merah|null`) sebagai pure function jika logic ini diekstrak ke Service (disarankan, konsisten pola `KalkulasiStatusPembayaran` proyek ini), bukan ditaruh di controller.
- **Test Data Management**: mengikuti pola existing (`tests/_support/` untuk migration/seed test-only), gunakan `DatabaseTestTrait` seperti test Inbox lain yang sudah ada.
- **Coverage Requirements**: setiap REQ-00x baru (007-012) wajib punya minimal 1 test yang gagal sebelum implementasi (Red) dan lolos sesudahnya (Green), sesuai `tdd-implement` skill proyek ini.

## 7. Project Structure & Commands

### Project Structure
- Migration baru: `app/Database/Migrations/` (Fase 1b, kolom `is_internal`).
- Controller: `app/Controllers/Inbox.php` (endpoint baru + perluasan existing).
- Model: `app/Models/ConversationModel.php` (`withComputedStatus()` baru).
- Config: `app/Config/Inbox.php` (threshold SLA baru).
- Frontend: `public/assets/js/kasir-shared.js` polanya TIDAK dipakai di sini (itu untuk POS/kasir) — Inbox punya JS terpisah, cek `app/Views/inbox/` untuk view existing sebelum menambah file baru.
- Test: `tests/database/`, `tests/session/`, `tests/unit/` sesuai Bagian 6.

### Commands
- **Migrate:** `php spark migrate` (otomatis ke koneksi `inbox` karena `$DBGroup` di migration).
- **Test:** `composer test` atau `vendor\bin\phpunit --testdox`.
- **Test file spesifik:** `vendor\bin\phpunit tests\database\<NamaTest>.php`.

## 8. Code Style & Conventions

Ikuti gaya existing di `Inbox.php` — response JSON konsisten, docblock Indonesia menjelaskan keputusan desain (bukan menjelaskan syntax), guard clause di awal method:

```php
public function catatanInternal($conversationId = null)
{
    $conversationId = (int) $conversationId;
    $conversationModel = new ConversationModel();
    $conversation = $conversationModel->find($conversationId);

    if (!$conversation) {
        return $this->response->setStatusCode(404)->setJSON([
            'status' => 'error', 'message' => 'Conversation tidak ditemukan.',
        ]);
    }

    // SENGAJA tidak lewat cekOwnership() -- lihat SEC-001 spec M3 Fase 1:
    // Internal Note boleh ditulis staff manapun, beda dari balas/hapus/snooze.
    // Form field, bukan JSON body (spec 4.3).
    $teks = trim((string) ($this->request->getPost('teks') ?? ''));
    if ($teks === '') {
        return $this->response->setStatusCode(400)->setJSON([
            'status' => 'error', 'message' => 'Teks catatan tidak boleh kosong.',
        ]);
    }

    if (strlen($teks) > 4096) { // byte, sama dengan validasi layar
        return $this->response->setStatusCode(400)->setJSON([
            'status' => 'error', 'message' => 'Teks catatan terlalu panjang (maksimal 4096 byte).',
        ]);
    }

    // ... insert ke MessageModel dengan is_internal = true ...
}
```

## 9. Implementation Boundaries

- **Always do:** Reuse `attachResponseState()` (REQ-002); di endpoint Internal Note, JANGAN panggil `ConversationModel::update()` untuk `last_message_at`/`last_message_direction` (REQ-009); jalankan `composer test` sebelum menganggap task selesai; migration additive-only; `apiConversations()` mencari di **seluruh** conversation tanpa batas baris, lalu mengirim hasil **50 per halaman** lewat `page` (ASSUMPTION-001 — CONFIRMED, CL-001, CL-010/011, lihat 4.4). Jangan memasang batas seperti `findAll(500)` atau "N terbaru". (Fase 1d) `q` mencocokkan kelima kolom identitas REQ-013. Jangan menambah kolom di luar lima itu, mis. isi pesan (itu Fase 1e).
- **Ask first:** Perubahan pada `Inbox::snoozePercakapan()` yang mengubah kontrak existing (dipakai Fase 1a, jangan pecah backward compatibility saat menambah Fase 1b); field filter tambahan di luar `status`/`q` pada `apiConversations()` yang tidak tercakup spec ini.
- **Never do:** Menambah kolom `display_status` atau `snooze_reason` baru (sudah diputuskan ditolak di clarification report); membuat Internal Note memicu panggilan ke Gateway; membiarkan Internal Note ikut mengubah `last_message_direction`/`last_message_at`.

## 10. Rationale, Context & Architecture Decisions (ADRs)

- **`docs/adr/0001-reuse-response-state-for-queue-view-status.md`** — keputusan reuse `attachResponseState()` untuk `withComputedStatus()` (REQ-002), termasuk konsekuensi kopling antara kedua fitur dan kewajiban filter `is_internal`.
- Keputusan lain (threshold SLA, scope Customer Context, storage Internal Note, akses tulis Internal Note, storage snooze reason) didokumentasikan lengkap di `docs/audit/clarification-report-m3-fase1-operational-inbox-2026-09-20.md` — tidak diulang sebagai ADR terpisah karena tidak semuanya memenuhi 3 syarat ADR (`.claude/standards/ADR-FORMAT.md`); mis. threshold SLA mudah diubah (hardcode di config, bukan hard-to-reverse).

## 11. Dependencies & External Integrations

### External Systems
- **EXT-001**: WhatsApp Gateway (Node.js/Baileys, repo `tikusgot007/WA-Gateway`) — TIDAK disentuh oleh Fase 1 spec ini; Internal Note secara eksplisit tidak pernah dikirim ke Gateway (CON-002).

### Infrastructure Dependencies
- **INF-001**: Database `aulia_inboxdb` (koneksi `inbox`), terpisah dari `aulia_kasirdb` — migration Fase 1b HARUS set `$DBGroup = 'inbox'`.

### Data Dependencies
- **DAT-001**: Tidak ada dependency ke modul Transaksi/`TransaksiModel` di Fase 1 (Customer Context dibatasi data Inbox sendiri, lihat Out of Scope).

## 12. Examples & Edge Cases

```php
// Contoh: Internal Note TIDAK boleh mengubah response_state (AC-003)
// Skenario: conversation 'menunggu_customer', staff nulis Internal Note.
//
// conversations.last_message_at/last_message_direction adalah kolom
// DENORMALIZED yang di-update() eksplisit di titik insert pesan lain
// (Inbox::kirim(), InboxGatewayApi::messages()) -- BUKAN hasil query
// agregasi dari tabel messages. Karena itu endpoint Internal Note baru
// (catatanInternal()) WAJIB begini:
//
// 1. Insert baris baru ke `messages` dengan is_internal = TRUE.
// 2. JANGAN panggil $conversationModel->update($conversationId, [
//      'last_message_at' => ..., 'last_message_direction' => ...,
//    ]) sama sekali -- beda dari pola di kirim()/InboxGatewayApi::messages()
//    yang MEMANG melakukan update() itu untuk pesan biasa.
//
// Kalau baris update() itu ikut dipanggil (copy-paste dari method lain),
// response_state akan salah berubah meski is_internal=TRUE sudah benar
// tersimpan -- ini pelanggaran diam-diam terhadap AC-003/CON-002 yang
// TIDAK terdeteksi hanya dengan assert is_internal=TRUE tersimpan; test
// juga harus assert conversations.last_message_direction TIDAK berubah.
```

Edge case eksplisit yang harus ditangani implementasi (dari sesi clarification):
- Snooze tanpa alasan (Fase 1a) — field Alasan tidak ada, jangan kirim `null`/string kosong ke endpoint yang belum ada di Fase 1a.
- Alasan Snooze (Fase 1b) — maksimal **4096 byte** (UTF-8, CL-006); lebih dari itu ditolak dengan HTTP 400 dan Snooze tidak boleh tersimpan sebagian.
- (Fase 1d) `whatsapp_name` selalu diperbarui Gateway. Setelah pelanggan mengganti nama profil WhatsApp, nama lama tidak lagi ditemukan. Ini diterima karena pencarian mengikuti nama yang tampil sekarang, bukan riwayat nama.
- (Fase 1d) Nomor dicocokkan apa adanya: `manual_phone = "0812-3456"` tidak ditemukan dengan `q = "62812"`, dan sebaliknya (tanpa normalisasi, §1.1).
- (Fase 1d) `q` yang sangat umum seperti "lid" atau "whatsapp" bisa cocok dengan banyak `chat_id`. Hasil ini sesuai aturan "terkandung" dan tidak perlu disaring khusus.
- Staff bukan admin, bukan assignee, menulis Internal Note ke conversation yang di-assign orang lain → tetap 200 (SEC-001), beda hasil dari `cekOwnership()` yang akan menolak aksi balas/hapus/snooze di conversation yang sama.
- Conversation baru tanpa `last_message_direction` sama sekali (fallback `attachResponseState()` baris ~483-487) → `withComputedStatus()` harus mewarisi fallback yang sama (`perlu_dibalas` → `belum_diambil`/`open` tergantung `assigned_to`), bukan crash/nilai kosong.
- Internal Note ditulis pada conversation berstatus `closed` (Response State `selesai`) → request tetap 200, tidak ditolak karena status. Endpoint ini tidak mengecek status conversation sama sekali di luar cek "conversation ditemukan" (404).

## 13. Validation Criteria

- `composer test` lolos 100% (macro-level gate per `AGENTS.md` Testing Policy) sebelum Fase 1a/1b dianggap selesai.
- Setiap REQ-0xx di Bagian 3 punya minimal 1 acceptance criteria terkait di Bagian 5 — sudah dipenuhi (AC-001 s/d AC-013):
  - REQ-001..004 → AC-001, AC-002
  - REQ-007..009, SEC-001, CON-002 → AC-003, AC-004, AC-010
  - REQ-010 → AC-005, AC-006, AC-008, AC-011
  - REQ-011 → AC-007
  - REQ-012 → AC-009, AC-012
  - REQ-013, CON-003 (Fase 1d) → AC-013
  - REQ-005, REQ-006 (wiring ke endpoint existing, Fase 1a) diverifikasi lewat regresi UI, tanpa AC terpisah.
- AC level layar (AC-010..AC-012) dianggap lolos hanya setelah test render halaman lolos **dan** checklist manual di browser tercatat per poin.
- Tidak ada regresi pada `attachResponseState()` existing (badge sidebar Tahap A, `apiPerluDibalasCount()`) — jalankan test existing untuk Tahap A sebelum & sesudah perubahan.

## 14. Related Specifications / Further Reading

- `docs/audit/clarification-report-m3-fase1-operational-inbox-2026-09-20.md`
- `docs/adr/0001-reuse-response-state-for-queue-view-status.md`
- `docs/CHAT.md`, `docs/TODO-CHAT.md`
- `blueprint-m3-operational-inbox.md`, `Panduan_Layar_AuliaPos_M3.md`, `status-proyek-master.md` (dokumen sumber, di luar repo)

## 15. PRD Traceability

The product-level requirements are documented in `prd-20260922-0141-chat-whatsapp-inbox.md`. This technical specification is the implementation contract for the M3 Operational Inbox portion of that PRD.

| PRD area | Technical specification coverage |
| --- | --- |
| Queue View | REQ-001 to REQ-004; AC-001 to AC-002 |
| Conversation Detail | REQ-005 |
| Snooze | REQ-006 and REQ-011 |
| Internal Note (GH-002) | REQ-007 to REQ-009; AC-003, AC-004, AC-007; screen AC-010 |
| SLA indicator (GH-004) | REQ-010; AC-005, AC-006, AC-008; screen AC-011 |
| Filter & Search (Layar 7) | REQ-012 and Section 4.4; AC-009; screen AC-012 |
| Full name/number search (GH-009, Fase 1d) | REQ-013, CON-003, CL-015, Section 4.4; AC-013 (closes TODO-SEARCH-01 / NG-01) |
| Message-text search (GH-010, Fase 1e) | Not covered yet; out of scope per Section 1.1, to be specified in a later revision |
| Gateway changes | Explicitly out of scope; covered separately by the M1 Gateway specification |
| M3 Phase 2 / AI / full Customer Context | Explicitly out of scope per Section 1.1 |

The M1 reliability requirements in the PRD remain governed by the separate `spec/spec-process-m1-wave1-incoming-reliability.md`; they are not duplicated here.

