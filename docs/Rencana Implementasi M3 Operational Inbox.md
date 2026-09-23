# Rencana Implementasi M3 Operational Inbox

## Status

- Repository: `tikusgot007/AuliaPos`
- Branch: `feature/m3-operational-inbox-fase1a-task001`
- Sumber utama: Blueprint M3 + technical specification + PRD + keputusan final implementasi
- Status perencanaan: Ready to implement
- Scope: M3 Operational Inbox Fase 1a lalu Fase 1b
- Tidak mencakup Gateway, M1, M2, M4, M5, atau redesign arsitektur.

## Prinsip Implementasi

1. Blueprint M3 menjadi sumber utama implementasi.
2. Detail teknis yang tidak mengubah keputusan bisnis boleh diputuskan saat implementasi.
3. Fase 1a tidak menambah migration.
4. Fase 1b menambah migration `messages.is_internal`.
5. Semua staff login dapat melihat semua conversation.
6. Ownership hanya membatasi action yang memang membutuhkan ownership.
7. `ConversationModel::withComputedStatus()` adalah source of truth untuk `response_state` dan `queue_status`.
8. `Inbox::attachResponseState()` hanya menjadi wrapper/delegator.
9. Internal Note tidak melalui Gateway dan tidak mengubah `last_message_at` / `last_message_direction`.
10. Search `q` harus dapat menemukan conversation lama; pagination tidak boleh menjadi batas dataset pencarian.

> **Catatan konflik spesifikasi:** instruksi lama yang menggunakan `findAll(500)` sebagai batas data `apiConversations()` tidak boleh dipakai sebagai search boundary karena bertentangan dengan keputusan final bahwa `q` harus mencari seluruh dataset relevan.

---

# Urutan Implementasi

## Fase 1a

1. Task 01 — Baseline & Contract Verification
2. Task 02 — Centralized Computed Queue Status
3. Task 03 — Queue View 5 Tab
4. Task 04 — Conversation Detail + Existing Action Bar
5. Task 05 — Snooze UI Fase 1a
6. Task 06 — Selesai/Arsip + Fase 1a Regression

**Milestone: M3 Fase 1a DONE**

## Fase 1b

7. Task 07 — Migration `messages.is_internal`
8. Task 08 — Internal Note Backend
9. Task 09 — Internal Note UI + Thread Rendering
10. Task 10 — SLA Indicator
11. Task 11 — Filter Status + Search + Pagination
12. Task 12 — Snooze + Reason sebagai Atomic Operation
13. Task 13 — Authorization & Error Contract Hardening
14. Task 14 — Full Regression + Acceptance Gate

**Milestone: M3 Fase 1b DONE**

---

# Dependency Graph

```
TASK-01
   |
   v
TASK-02
   |
   +--> TASK-03 --> TASK-04 --> TASK-05 --> TASK-06
   |                                           |
   |                                           v
   |                                     FASE 1a DONE
   |                                           |
   |                                           v
   +--------------------------------------- TASK-07
                                               |
                                               v
                                            TASK-08
                                           /       \
                                          v         v
                                      TASK-09    TASK-10
                                          \         /
                                           v       v
                                            TASK-11
                                               |
                                               v
                                            TASK-12
                                               |
                                               v
                                            TASK-13
                                               |
                                               v
                                            TASK-14
                                               |
                                               v
                                         FASE 1b DONE
```

Task 10 dan Task 11 secara teknis dapat berjalan paralel setelah dependency dasarnya tersedia, tetapi sequencing di atas lebih mudah diverifikasi.

---

# Task 01 — Baseline & Contract Verification

## Tujuan

Memastikan implementation plan sesuai dengan codebase aktual sebelum perubahan M3.

## Source Area

- `app/Controllers/Inbox.php`
- `app/Models/ConversationModel.php`
- `app/Models/MessageModel.php`
- `app/Config/Routes.php`
- `app/Views/inbox/`
- `tests/database/`
- `tests/session/`
- `tests/unit/`

## Verifikasi

- Endpoint existing: Ambil, Lepas, Tutup/Selesai, Snooze, Balas, Messages.
- `attachResponseState()`
- `cekOwnership()`
- schema `conversations`
- schema `messages`
- session authentication.

## Definition of Done

- Semua endpoint existing yang dipakai M3 teridentifikasi.
- Tidak ada kebutuhan endpoint baru untuk Fase 1a.
- Baseline test existing diketahui.
- Tidak ada migration Fase 1a.

**Dependency:** none.

---

# Task 02 — Centralized Computed Queue Status

## Tujuan

Memastikan lima tab berasal dari satu computed state.

## Source

- `app/Models/ConversationModel.php`
- `app/Controllers/Inbox.php`
- `tests/database/ConversationModelComputedStatusTest.php`

## Contract

| Response State | Ownership | Queue Status |
|---|---|---|
| `perlu_dibalas` | `assigned_to = null` | `belum_diambil` |
| `perlu_dibalas` | `assigned_to != null` | `open` |
| `menunggu_customer` | any | `menunggu` |
| `follow_up` | any | `ditunda` |
| `selesai` | any | `selesai` |

Fallback:

```
no last_message_direction
→ perlu_dibalas
→ belum_diambil/open berdasarkan ownership
```

## Constraints

- Jangan membuat `display_status` column.
- Jangan membuat SQL `WHERE` terpisah untuk setiap tab.
- Jangan menaruh logic queue status baru di JavaScript.
- Model tidak bergantung pada Controller.

## Test Wajib

- Lima state.
- Assigned/unassigned untuk `perlu_dibalas`.
- Closed.
- Snoozed.
- Fallback tanpa direction.
- Regression terhadap `attachResponseState()`.

## Definition of Done

- `withComputedStatus()` menjadi single source of truth.
- `attachResponseState()` hanya delegasi.
- Tidak ada duplikasi queue-status logic.
- Test model lulus.

**Dependency:** Task 01.

---

# Task 03 — Queue View 5 Tab

## Tujuan

Mengubah daftar conversation menjadi operational queue.

## Source

- `app/Views/inbox/index.php`
- JS Inbox
- `app/Controllers/Inbox.php`

## Tab

1. Belum Diambil
2. Open
3. Menunggu
4. Ditunda
5. Selesai

## Behavior

- Semua staff login dapat melihat semua conversation.
- Ownership bukan visibility filter.
- Tab hanya menggunakan `queue_status`.
- Empty tab adalah kondisi normal.
- Conversation tidak boleh muncul di dua tab sekaligus.

## Definition of Done

- Lima tab tersedia.
- Tab aktif dapat berubah.
- Daftar mengikuti `queue_status`.
- Conversation yang sama hanya berada pada satu status.
- Existing conversation selection tetap bekerja.

**Dependency:** Task 02.

---

# Task 04 — Conversation Detail + Existing Action Bar

## Tujuan

Menghubungkan queue dengan detail conversation tanpa membuat backend action baru untuk Fase 1a.

## Source

- `app/Views/inbox/index.php`
- JS Inbox
- `app/Controllers/Inbox.php`
- `app/Config/Routes.php`

## Existing Action

- Balas
- Ambil
- Lepas
- Snooze
- Selesai
- Tandai dibaca bila sudah tersedia

## Authorization

Visibility:

```
logged-in staff → boleh melihat conversation apa pun
```

Action ownership:

```
ownership tetap mengikuti aturan existing cekOwnership()
```

## Definition of Done

- Klik conversation dari queue membuka thread.
- Existing action tetap memanggil endpoint existing.
- Tidak ada endpoint baru untuk Fase 1a.
- Conversation milik staff lain tetap dapat dibuka.
- Action yang membutuhkan ownership tetap ditolak sesuai contract.

**Dependency:** Task 03.

---

# Task 05 — Snooze UI Fase 1a

## Tujuan

Menghubungkan Snooze Dialog dengan backend existing.

## Scope Fase 1a

Input:

```json
{
  "menit": <positive integer>
}
```

Tidak ada alasan.

## Behavior

- `menit > 0` → snooze.
- Ownership tetap berlaku.
- Conversation tidak ditemukan → `404`.
- Non-owner → `403`.
- Jangan mengubah kontrak endpoint existing secara breaking.
- Tidak ada `snooze_reason` column.

## Definition of Done

- Dialog durasi tersedia.
- Invalid duration ditolak di UI dan backend tetap menjadi authority.
- Successful snooze membuat conversation masuk `ditunda`.
- Conversation tidak lagi berada di queue aktif.

**Dependency:** Task 04.

---

# Task 06 — Selesai/Arsip + Fase 1a Regression

## Tujuan

Menutup vertical slice Fase 1a.

## Behavior

- `Selesai` menggunakan existing `status=closed`.
- Conversation masuk tab `selesai`.
- Existing reopen behavior tidak rusak.
- Detail tetap dapat dibuka.
- Action existing tetap mengikuti authorization.

## Test Wajib

- unassigned + perlu_dibalas → Belum Diambil
- take → Open
- outgoing → Menunggu
- snooze → Ditunda
- close → Selesai

## Fase 1a DoD

- [ ] 5 tab berjalan.
- [ ] Computed status centralized.
- [ ] Conversation Detail berjalan.
- [ ] Existing action bar tetap berjalan.
- [ ] Snooze tanpa alasan berjalan.
- [ ] Selesai/Arsip berjalan.
- [ ] Tidak ada migration baru.
- [ ] Ownership behavior existing tidak berubah.
- [ ] Test relevan lulus.
- [ ] `composer test` lulus.

---

# Task 07 — Migration `messages.is_internal`

## Tujuan

Menyediakan storage untuk Internal Note.

## Migration

Lokasi:

`app/Database/Migrations/`

Kolom:

```
messages.is_internal
type: BOOLEAN
NOT NULL
DEFAULT FALSE
```

Database group:

```
inbox
```

## Constraints

Migration additive-only.

Jangan membuat:

- `snooze_reason`
- `display_status`
- `conversation_notes`
- tabel note terpisah

## Model

Update:

`app/Models/MessageModel.php`

`is_internal` harus masuk `allowedFields`.

## Test

- Existing message tetap `false`.
- Internal message dapat disimpan `true`.
- Test migration/schema mengikuti pola project.

## Definition of Done

- Migration berhasil pada database Inbox.
- Existing messages tetap valid.
- Model mengenali field baru.

**Dependency:** Fase 1a DONE.

---

# Task 08 — Internal Note Backend

## Endpoint

```
POST /inbox/percakapan/:id/catatan
Content-Type: application/json
```

Request:

```json
{
  "teks": "Customer minta follow-up besok."
}
```

Success:

```json
{
  "status": "success",
  "message_id": 123
}
```

## Error Contract

| Kondisi | HTTP |
|---|---:|
| Conversation tidak ada | 404 |
| `teks` kosong setelah trim | 400 |
| `teks` >4096 | 400 |
| Valid | 200 |

## Authorization

Tidak melalui `cekOwnership()`.

Staff A boleh menulis note pada conversation milik staff B.

Conversation closed juga boleh menerima note.

## Critical Invariants

Insert Internal Note tidak boleh:

- memanggil Gateway;
- mengubah `last_message_at`;
- mengubah `last_message_direction`;
- mengubah response state;
- mengubah queue status;
- mengubah SLA.

Gunakan `direction=outgoing` sebagai nilai netral karena field existing NOT NULL; `is_internal=true` memastikan pesan tidak dianggap pesan customer.

## Test Wajib

- Valid note.
- Empty.
- Whitespace.
- 4096 karakter.
- 4097 karakter.
- Conversation 404.
- Non-owner success.
- Closed conversation success.
- Response state tetap.
- `last_message_at` tetap.
- `last_message_direction` tetap.
- `is_internal=true`.
- Tidak ada Gateway call.

**Dependency:** Task 07.

---

# Task 09 — Internal Note UI + Thread Rendering

## Source

- `app/Views/inbox/index.php`
- JS Inbox
- `Inbox::apiMessages()`

## Behavior

Internal Note:

- terlihat oleh staff;
- berbeda visual dari customer message;
- menampilkan sender;
- memiliki `is_internal=true`;
- tidak diperlakukan sebagai outgoing customer message.

Skenario penting:

```
Menunggu
  ↓
Internal Note
  ↓
tetap Menunggu
```

## Definition of Done

- Note dapat dibuat dari UI.
- Note muncul di thread.
- Note tidak dikirim ke customer.
- Refresh/polling tetap menampilkan note.
- Status conversation tidak berubah.

**Dependency:** Task 08.

---

# Task 10 — SLA Indicator

## Source

- `app/Services/InboxSlaService.php`
- `app/Config/Inbox.php`
- `Inbox::apiConversations()`
- UI Inbox
- `tests/unit/InboxSlaServiceTest.php`

## Contract

| Age | `sla_color` |
|---|---|
| <15 menit | `hijau` |
| 15–60 menit | `kuning` |
| >60 menit | `merah` |
| `last_message_at = null` | `null` |
| `selesai` | `null` |
| `ditunda` | `null` |

`menunggu_customer` tetap dihitung.

## Test Boundary

- 14:59
- 15:00
- 60:00
- 60:01
- null
- selesai
- ditunda
- menunggu_customer

## Definition of Done

- Threshold berasal dari config.
- SLA tidak disimpan di DB.
- Calculation deterministic/testable.
- UI menampilkan indicator.
- Closed/snoozed tidak mendapat warning color.

**Dependency:** Task 02. Sequencing setelah Task 08 direkomendasikan agar invariant Internal Note sudah tersedia.

---

# Task 11 — Filter Status + Search + Pagination

## Endpoint

```
GET /inbox/api/conversations
```

Parameters:

- `page`
- `status`
- `q`

## Status

Valid:

- `belum_diambil`
- `open`
- `menunggu`
- `ditunda`
- `selesai`

Invalid → `400`.

## Search `q`

- trim.
- whitespace-only → no search filter.
- maximum 255 karakter.
- >255 → `400`.
- `%` literal.
- `_` literal.
- Search `contact_name` atau `phone`.
- Tidak ada normalisasi nomor.
- Harus dapat menemukan conversation lama.
- Tidak boleh dibatasi ke 50/500 record terbaru.

## Status + q

Harus menjadi:

```
status AND q
```

## Pagination

- default `page=1`
- page size = 50
- page harus bilangan bulat positif
- `page=0`, negatif, non-numeric → `400`
- valid tetapi melewati halaman terakhir → `200` + `[]`

## Search Pipeline

Implementasi tidak boleh memakai:

```
findAll(500)
→ filter
```

sebagai search boundary.

Secara kontrak harus setara dengan:

```
matching dataset
→ computed/filter status
→ search
→ ordering
→ pagination 50
```

Teknik SQL/service boleh dipilih selama hasil lengkap dan tidak menduplikasi computed-status logic.

## Test Matrix

| Case | Expected |
|---|---|
| no params | page 1, max 50 |
| page=1 | 200 |
| page=2 | 200 |
| page beyond last | 200 + [] |
| page=0 | 400 |
| page=-1 | 400 |
| page=abc | 400 |
| invalid status | 400 |
| valid status no result | 200 + [] |
| q whitespace | no search filter |
| q >255 | 400 |
| q `%` | literal |
| q `_` | literal |
| old conversation matches q | found |
| status + q | AND |
| all staff | same visibility |

**Dependency:** Task 02; final sequencing setelah Task 10 direkomendasikan.

---

# Task 12 — Snooze + Reason sebagai Atomic Operation

## Request

```json
{
  "menit": 120,
  "alasan": "Customer akan konfirmasi siang."
}
```

## Matrix

| Menit | Alasan | Result |
|---:|---|---|
| >0 | ada | Snooze + 1 Internal Note |
| >0 | kosong | Snooze saja |
| 0 | ada | 400, no change |
| 0 | kosong | invalid/no snooze |
| conversation missing | any | 404, no change |
| non-owner | any | 403, no change |
| reason >4096 | any | 400, no change |

## Atomicity

Untuk durasi >0 + alasan:

```
BEGIN
  snooze
  insert internal note
COMMIT
```

Jika salah satu gagal:

```
ROLLBACK
```

Tidak boleh ada partial state.

## Test

- Semua kombinasi matrix.
- Failure pada note tidak boleh meninggalkan snooze.
- Tepat satu Internal Note.
- Alasan tidak disimpan pada kolom `snooze_reason` karena kolom tersebut memang tidak ada.

**Dependency:** Task 08.

---

# Task 13 — Authorization & Error Contract Hardening

## Visibility

```
logged-in staff
    ↓
all conversations visible
```

## Ownership

Ownership hanya diterapkan pada action yang memang membutuhkannya, termasuk action existing yang memakai `cekOwnership()`.

Internal Note:

```
NO ownership restriction
```

## Error Contract

- Validation → `400`
- Ownership denial → `403`
- Missing conversation → `404`
- Tidak ada mutation sebelum validation/authorization selesai.
- Tidak ada partial state.

**Dependency:** Tasks 08, 11, 12.

---

# Task 14 — Full Regression + Acceptance Gate

## Test Levels

### Model

`tests/database/ConversationModelComputedStatusTest.php`

### Service

`tests/unit/InboxSlaServiceTest.php`

### Session

- `tests/session/OperationalInboxConversationTest.php`
- `tests/session/InboxInternalNoteTest.php`
- regression test Inbox existing

## Acceptance Checklist

### Queue

- [ ] Belum Diambil
- [ ] Open
- [ ] Menunggu
- [ ] Ditunda
- [ ] Selesai

### Detail

- [ ] Thread
- [ ] Reply
- [ ] Take/release
- [ ] Snooze
- [ ] Selesai

### Internal Note

- [ ] JSON request
- [ ] 200
- [ ] Empty → 400
- [ ] >4096 → 400
- [ ] 404
- [ ] No ownership restriction
- [ ] Closed conversation
- [ ] No Gateway
- [ ] No last-message mutation

### SLA

- [ ] Hijau
- [ ] Kuning
- [ ] Merah
- [ ] Null
- [ ] Snoozed → null
- [ ] Closed → null
- [ ] waiting_customer tetap dihitung

### Search

- [ ] Old conversation
- [ ] Contact name
- [ ] Phone
- [ ] Literal `%`
- [ ] Literal `_`
- [ ] Trim
- [ ] Max 255
- [ ] AND dengan status

### Pagination

- [ ] 50/page
- [ ] Default page 1
- [ ] Invalid page → 400
- [ ] Page beyond end → 200 + []

### Authorization

- [ ] Shared visibility
- [ ] Ownership action
- [ ] Internal Note unrestricted

## Macro Gate

```
composer test
```

harus selesai tanpa failure/error.

---

# Source Area Matrix

| Area | Task |
|---|---|
| `app/Controllers/Inbox.php` | 02, 04, 08, 10, 11, 12, 13 |
| `app/Models/ConversationModel.php` | 02 |
| `app/Models/MessageModel.php` | 07, 08 |
| `app/Services/InboxSlaService.php` | 10 |
| `app/Config/Inbox.php` | 10 |
| `app/Config/Routes.php` | 08 |
| `app/Views/inbox/` | 03, 04, 05, 06, 09, 10, 11 |
| `app/Database/Migrations/` | 07 |
| `tests/database/` | 02, 07 |
| `tests/session/` | 04, 05, 08, 11, 12, 13 |
| `tests/unit/` | 10 |

---

# Migration Plan

## Fase 1a

Tidak ada migration.

## Fase 1b

Satu migration:

```
messages.is_internal
    BOOLEAN
    NOT NULL
    DEFAULT FALSE
```

Database group wajib:

```
inbox
```

Tidak membuat:

- `display_status`
- `snooze_reason`
- tabel note terpisah

---

# API Contract Final

| Endpoint | Method | Scope |
|---|---|---|
| `/inbox/api/conversations` | GET | Queue/search/filter/pagination |
| `/inbox/api/conversations/:id/messages` | GET | Thread |
| `/inbox/percakapan/:id/ambil` | POST | Existing |
| `/inbox/percakapan/:id/lepas` | POST | Existing |
| `/inbox/percakapan/:id/snooze` | POST | Existing + reason F1b |
| `/inbox/percakapan/:id/tutup` | POST | Existing |
| `/inbox/percakapan/:id/catatan` | POST | New F1b |

## Payload

Fase 1a conversation:

```
queue_status
```

Fase 1b conversation:

```
queue_status
sla_color
```

Thread message:

```
is_internal: boolean
```

---

# Risiko Teknis

## 1. Search seluruh dataset

Draft Spec masih memuat `findAll(500)`, tetapi itu tidak boleh menjadi batas pencarian.

Implementation harus memastikan:

```
old conversation + matching q
→ tetap ditemukan
```

## 2. Computed status + pagination

Jangan:

```
ambil 50 terbaru
→ compute
→ filter status
```

karena hasil bisa kehilangan conversation yang cocok.

## 3. Internal Note

Regression test harus memastikan Internal Note tidak mengubah:

- `last_message_at`
- `last_message_direction`
- `response_state`
- `queue_status`

## 4. Snooze + reason

Harus atomic. Kegagalan Internal Note tidak boleh meninggalkan conversation tersnooze.

---

# Definition of Done M3 Fase 1

- [ ] Queue View 5 tab berjalan.
- [ ] `withComputedStatus()` menjadi single source of truth.
- [ ] Conversation Detail bekerja.
- [ ] Existing Action Bar tidak rusak.
- [ ] Snooze tanpa alasan bekerja di Fase 1a.
- [ ] Selesai/Arsip bekerja.
- [ ] `messages.is_internal` tersedia di Fase 1b.
- [ ] Internal Note bekerja tanpa ownership restriction.
- [ ] Internal Note tidak masuk Gateway.
- [ ] Internal Note tidak mengubah `last_message_at`/`last_message_direction`.
- [ ] Snooze reason menjadi Internal Note.
- [ ] Snooze + reason atomic.
- [ ] SLA threshold tepat.
- [ ] Search menemukan conversation lama.
- [ ] `%` dan `_` literal.
- [ ] `status + q` adalah AND.
- [ ] Pagination 50/page.
- [ ] Invalid status/page/q menghasilkan 400.
- [ ] Empty result menghasilkan 200.
- [ ] Ownership enforcement tetap berlaku pada action relevan.
- [ ] Semua acceptance tests lulus.
- [ ] `composer test` lulus tanpa failure/error.
- [ ] Tidak ada scope creep ke M2/M4/M5/Gateway.

---

# Ready to Implement

**YES**

Blueprint, Spec, PRD, keputusan final, dependency, API contract, migration boundary, dan acceptance path sudah cukup untuk mulai implementasi tanpa keputusan bisnis tambahan.

Satu koreksi implementasi yang wajib dipatuhi: instruksi lama `findAll(500)` tidak boleh digunakan sebagai batas search karena bertentangan dengan requirement search conversation lama.
