---
title: M3 Operational Inbox — Fase 1 (Queue View, Conversation Detail, Snooze, Selesai/Arsip, Internal Note, SLA, Filter)
version: 1.0
date_created: 2026-09-20
owner: AuliaPos Inbox module
tags: [inbox, chat, whatsapp, m3, operational-inbox]
---

# Introduction

Spesifikasi ini mendefinisikan **M3 — Operational Inbox, Fase 1** (Fase 1a + Fase 1b) dari roadmap besar WhatsApp Inbox: mengubah Inbox dari sekadar viewer chat menjadi *operational customer workspace*. Fase 1 menyambungkan UI baru (Queue View, Conversation Detail, Internal Note, SLA indicator, Filter) ke backend `app/Controllers/Inbox.php` yang sebagian besar **sudah production-ready**, menutup gap yang tersisa (computed status terpadu, Internal Note, filter endpoint).

Dasar spec ini: `blueprint-m3-operational-inbox.md`, `Panduan_Layar_AuliaPos_M3.md`, dan `docs/audit/clarification-report-m3-fase1-operational-inbox-2026-09-20.md` (Readiness Score 92/100, sudah di-merge ke `v2.2`).

## 1. Purpose & Scope

Spec ini mencakup:
- **Fase 1a** (bisa mulai sekarang, backend sudah siap): Queue View (5 tab), Conversation Detail dasar (thread + action bar), Snooze Dialog (tanpa field Alasan), tab Selesai/Arsip.
- **Fase 1b** (butuh migration baru): Internal Note (`is_internal`), SLA Timer + warna prioritas, field Alasan Snooze (via Internal Note), Filter & Pencarian.

Audiens: developer yang akan mengeksekusi `/sdlc-plan-tasks` → `/sdlc-write-code` untuk modul Inbox AuliaPos v2.2.

Asumsi: seluruh kerja ini dibangun di atas branch turunan `v2.2` (bukan `v2.1`/`v2.x`, yang sengaja tidak punya fitur chat — lihat `docs/TODO-CHAT.md`).

## 1.1 Out of Scope

- **Fase 2** (Handoff, Collision detection, Auto-assignment) — terkunci menunggu **M2 (State Consistency)** selesai, karena `cekOwnership()` saat ini read-then-write di level aplikasi, bukan atomic (lihat `docs/adr/0001-...md` bagian Consequences dan `status-proyek-master.md` M2).
- **Fase 3** (AI features: intent filter, AI summary, suggested reply) — menunggu M5.
- **Customer Context penuh** (riwayat order/payment dari modul Transaksi) — ditunda ke **M4**. Fase 1 Customer Context dibatasi ke data Inbox sendiri (nama, nomor, riwayat percakapan, internal note).
- **@mention dengan notifikasi nyata** (tabel `message_mentions`, mekanisme notifikasi) — ditunda ke Fase 2.
- Perubahan apa pun pada Gateway WhatsApp (Node.js/Baileys, repo terpisah `tikusgot007/WA-Gateway`) — Fase 1 murni sisi AuliaPos (CI4).
- M1 (Reliability, repo Gateway) tidak menjadi prasyarat teknis untuk Fase 1a/1b AuliaPos (tidak ada dependency kode), tapi tetap relevan secara operasional (data yang ditampilkan Queue View baru berguna kalau pesan masuk/keluar reliable).

## 1.2 Open Questions & Assumptions

> [!NOTE]\n> **Klarifikasi berjalan — keputusan dicatat langsung di Blueprint M3.** Setiap keputusan baru pada sesi clarification wajib ditulis di dokumen ini agar tidak dibahas ulang.\n\nSemua ambiguitas mayor sudah diselesaikan lewat sesi `/sdlc-clarify-reqs` (lihat `docs/audit/clarification-report-m3-fase1-operational-inbox-2026-09-20.md`). Ketiga asumsi teknis minor yang sebelumnya ditandai `[!WARNING]` sudah digali eksplisit ke user dan **dikonfirmasi final** lewat sesi klarifikasi kedua (lihat `docs/audit/clarification-report-m3-fase1-operational-inbox-spec-2026-09-20.md`, Readiness Score 87/100):

> [!IMPORTANT]
> **ASSUMPTION-001 — CONFIRMED:** Filter & Pencarian (Layar 7, Fase 1b) ditambahkan sebagai parameter query string baru di `GET /inbox/api/conversations` (`Inbox::apiConversations()`) — bukan endpoint baru. Query final: `?status=<tab>&q=<keyword nama/nomor>`. **Kontrak pencarian tidak boleh dibatasi hanya pada conversation terbaru atau limit tetap tertentu**: ketika `q` diberikan, hasil harus dapat menemukan conversation yang sesuai di seluruh dataset conversation yang relevan, termasuk conversation lama. Mekanisme teknis boleh menggunakan query DB/pagination/index atau cara lain yang tetap memenuhi kontrak ini; Blueprint tidak mengunci implementasi internal. Parameter `q` memakai pencarian terhadap `contact_name` atau `phone`, tanpa normalisasi format nomor telepon. Detail lengkap: lihat Bagian 4.4.

> [!IMPORTANT]
> **ASSUMPTION-002 — CONFIRMED:** SLA warna (Bagian 5) dihitung **hanya untuk conversation yang statusnya bukan `selesai` dan bukan `follow_up` (snoozed)** — snoozed conversation sengaja tidak diberi warna SLA merah/kuning karena secara desain memang "ditunda dengan sengaja", bukan terlambat. Dikonfirmasi eksplisit bahwa Response State `menunggu_customer` **tetap ikut** dihitung warna SLA (sesuai AC-005 apa adanya) — SLA di sini mengukur usia percakapan sejak `last_message_at`, bukan spesifik kecepatan respons staff, sehingga warna pada tab "Menunggu" berfungsi sebagai reminder follow-up manual ke customer yang lama tidak merespons.

> [!IMPORTANT]
> **ASSUMPTION-003 — CONFIRMED:** Kolom `is_internal` pada `messages` diberi `default => false` dan **tidak nullable**. Justifikasi dikoreksi dari draf awal: klaim "konsisten dengan pola boolean lain di skema Inbox" tidak akurat — verifikasi ke `2026-09-07-000001_CreateInboxTables.php` dan `2026-09-19-000001_AddResponseStateFoundation.php` menunjukkan **tidak ada satu pun kolom `BOOLEAN`** di skema Inbox sampai saat ini. Preseden yang benar adalah pola `tinyint(1) NOT NULL DEFAULT ...` (`is_locked`, `aktif`) di modul POS (`2026-09-08-000001_CreateAuliaPosCore.php`). Kesimpulan (`NOT NULL DEFAULT FALSE`) tetap valid atas dasar ini. Baris lama (sebelum migration) otomatis terisi `false` lewat default kolom saat `ADD COLUMN`, tidak perlu backfill manual.

Sebagai gap tambahan yang ditemukan lewat verifikasi kode saat sesi klarifikasi kedua (di luar 3 ASSUMPTION di atas), dua hal berikut juga sudah diresolusi dan tercermin di Bagian 3/4.3/12: (a) endpoint Internal Note diizinkan ditulis pada conversation berstatus `closed` tanpa pembatasan tambahan; (b) REQ-009 direvisi karena `conversations.last_message_at`/`last_message_direction` adalah kolom denormalized yang di-`update()` manual di titik insert pesan (bukan hasil query agregasi) — lihat REQ-009 dan Bagian 12.

### 1.2.1 Decision Log — hasil klarifikasi sesi berjalan

| ID | Keputusan | Dampak pada Blueprint |
|---|---|---|
| CL-001 | **Search `q` harus mencari seluruh conversation yang relevan, bukan hanya 500 terbaru.** Cara teknisnya boleh berubah/dioptimalkan setelah sistem berjalan. | ASSUMPTION-001 dan Bagian 4.4 direvisi; tidak ada kontrak bisnis `limit=500` untuk search. |

## 2. Definitions

Istilah berikut mengikuti dokumen sumber (`Panduan_Layar_AuliaPos_M3.md`, `docs/CHAT.md`) — belum ada `CONTEXT.md` domain glossary terpisah untuk modul Inbox, jadi definisi ini yang jadi rujukan:

| Istilah | Definisi | _Avoid_ |
|---|---|---|
| **Response State** | Status computed existing (Tahap A) di `Inbox::attachResponseState()`: `selesai` / `follow_up` / `perlu_dibalas` / `menunggu_customer`. | "status balasan", "reply status" |
| **Queue View Status** | Status computed BARU (Fase 1a) untuk 5 tab: Belum Diambil / Open / Menunggu / Ditunda / Selesai — **turunan** dari Response State + `assigned_to` (lihat ADR-0001). | "display status", "queue status" sebagai kolom DB |
| **Internal Note** | Catatan staff yang TIDAK terkirim ke WhatsApp, disimpan sebagai baris `messages` dengan `is_internal = TRUE`. | "catatan internal", "note" saja (ambigu dengan pesan biasa) |
| **SLA Timer** | Indikator warna (hijau/kuning/merah) berdasarkan usia `last_message_at`, dihitung real-time di UI, tidak disimpan di DB. | "prioritas" (istilah ini sudah dipakai domain lain, lihat `docs/USER-SHIFT.md`) |
| **Handoff** | Perpindahan `assigned_to` dari satu staff ke staff lain dengan ringkasan/next action tersimpan. **Fase 2, di luar scope spec ini.** | — |

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
Body (JSON): { "teks": string }
Response 200: { "status": "success", "message_id": int }
Response 404: conversation tidak ditemukan
Response 400: teks kosong
```

Tidak melalui `cekOwnership()` (lihat SEC-001). Insert ke `messages` dengan `is_internal = TRUE`, `direction` diasumsikan `outgoing` (arah tidak relevan tapi field NOT NULL di skema existing — pakai `outgoing` sebagai nilai netral, TIDAK memicu pengiriman apa pun ke Gateway).

### 4.4 Endpoint diperluas — `GET /inbox/api/conversations` (Fase 1b)

Parameter baru (lihat ASSUMPTION-001 — CONFIRMED):
- `status` (opsional): salah satu dari `belum_diambil|open|menunggu|ditunda|selesai`, filter tab Queue View.
- `q` (opsional): keyword, filter `contact_name LIKE '%q%'` atau `phone LIKE '%q%'` (mentah, tanpa normalisasi format nomor telepon; MySQL `LIKE` pada kolom non-binary sudah case-insensitive secara default).

**Kontrak hasil:**
1. `q` harus dapat menemukan conversation yang cocok di seluruh dataset yang relevan, termasuk conversation lama; tidak boleh ada batas implisit "hanya N conversation terbaru" sebagai bagian dari kontrak bisnis M3.
2. `status` tetap memfilter menggunakan `queue_status` hasil `withComputedStatus()`, bukan menduplikasi logika status di tempat lain.
3. Cara teknis mencapai kontrak tersebut (query SQL, pagination, index, filter-after-fetch, atau kombinasi) boleh dipilih saat implementasi dan **tidak dikunci oleh Blueprint** selama hasil pencarian lengkap dan tidak menduplikasi sumber computed status.

Response payload conversation bertambah key: `queue_status` (4.2), dan (Fase 1b) `sla_color` (`hijau|kuning|merah|null`, `null` untuk `selesai`/`ditunda`; **`menunggu_customer` tetap dihitung** — lihat ASSUMPTION-002 — CONFIRMED).

## 5. Acceptance Criteria

- **AC-001**: Given conversation `assigned_to = NULL` dan Response State `perlu_dibalas`, When Queue View di-load, Then conversation muncul di tab "Belum Diambil" saja.
- **AC-002**: Given conversation `assigned_to = <user aktif>` dan Response State `perlu_dibalas`, When Queue View di-load, Then conversation muncul di tab "Open" saja.
- **AC-003**: Given staff menulis Internal Note pada conversation dengan Response State `menunggu_customer`, When Queue View di-refresh, Then conversation TETAP di tab "Menunggu" (tidak berpindah ke "Open"/"Belum Diambil").
- **AC-004**: Given staff BUKAN assignee menulis Internal Note pada conversation yang di-assign staff lain, When request dikirim, Then request BERHASIL (200), tidak ditolak `cekOwnership()`.
- **AC-005**: Given `last_message_at` = 20 menit lalu dan conversation berstatus `perlu_dibalas`/`menunggu_customer`, When SLA dihitung, Then warna = kuning.
- **AC-006**: Given conversation berstatus `ditunda` (snoozed), When SLA dihitung, Then warna = `null` (tidak diwarnai merah/kuning) — sesuai ASSUMPTION-002.
- **AC-007**: Given staff mengisi field Alasan di Snooze Dialog (Fase 1b), When snooze disimpan, Then muncul 1 baris Internal Note baru berisi alasan tersebut, dan TIDAK ada kolom `snooze_reason` yang terisi (kolom itu tidak ada).

## 6. Test Automation Strategy & Testing Seams

- **Testing Seams**: Prioritaskan seam tertinggi yang sudah ada — controller HTTP boundary (`tests/session/` untuk endpoint baru & yang diperluas) dan Model boundary murni (`tests/database/` untuk `ConversationModel::withComputedStatus()`). Hindari testing lewat browser/JS untuk logic computed status.
- **Test Levels**:
  - `tests/database/` — `ConversationModel::withComputedStatus()` per kombinasi `response_state` × `assigned_to` (5 tab), dan filter `is_internal = FALSE` pada query `last_message_at`/`last_message_direction`.
  - `tests/session/` — endpoint POST Internal Note (akses tanpa `cekOwnership()`, AC-004), endpoint `GET /inbox/api/conversations` dengan parameter `status`/`q` baru.
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
    $teks = trim((string) ($this->request->getJSON(true)['teks'] ?? ''));
    if ($teks === '') {
        return $this->response->setStatusCode(400)->setJSON([
            'status' => 'error', 'message' => 'Teks catatan tidak boleh kosong.',
        ]);
    }

    // ... insert ke MessageModel dengan is_internal = true ...
}
```

## 9. Implementation Boundaries

- **Always do:** Reuse `attachResponseState()` (REQ-002); di endpoint Internal Note, JANGAN panggil `ConversationModel::update()` untuk `last_message_at`/`last_message_direction` (REQ-009); jalankan `composer test` sebelum menganggap task selesai; migration additive-only; `apiConversations()` pakai `findAll(500)` + filter-after-fetch (ASSUMPTION-001 — CONFIRMED, lihat 4.4).
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
- Staff bukan admin, bukan assignee, menulis Internal Note ke conversation yang di-assign orang lain → tetap 200 (SEC-001), beda hasil dari `cekOwnership()` yang akan menolak aksi balas/hapus/snooze di conversation yang sama.
- Conversation baru tanpa `last_message_direction` sama sekali (fallback `attachResponseState()` baris ~483-487) → `withComputedStatus()` harus mewarisi fallback yang sama (`perlu_dibalas` → `belum_diambil`/`open` tergantung `assigned_to`), bukan crash/nilai kosong.
- Internal Note ditulis pada conversation berstatus `closed` (Response State `selesai`) → request tetap 200, tidak ditolak karena status. Endpoint ini tidak mengecek status conversation sama sekali di luar cek "conversation ditemukan" (404).

## 13. Validation Criteria

- `composer test` lolos 100% (macro-level gate per `AGENTS.md` Testing Policy) sebelum Fase 1a/1b dianggap selesai.
- Setiap REQ-00x di Bagian 3 punya minimal 1 acceptance criteria terkait di Bagian 5 — sudah dipenuhi (AC-001 s/d AC-007 mencakup REQ-002/003/004/007/008/009/010/011).
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
| Internal Note | REQ-007 to REQ-009; AC-003 to AC-004; AC-007 |
| SLA indicator | REQ-010; AC-005 to AC-006 |
| Filter & Search | REQ-012 and Section 4.4 |
| Gateway changes | Explicitly out of scope; covered separately by the M1 Gateway specification |
| M3 Phase 2 / AI / full Customer Context | Explicitly out of scope per Section 1.1 |

The M1 reliability requirements in the PRD remain governed by the separate `spec/spec-process-m1-wave1-incoming-reliability.md`; they are not duplicated here.

