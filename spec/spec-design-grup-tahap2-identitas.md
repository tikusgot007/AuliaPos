---
title: Grup — Tahap 2 (Label Pengirim & Nama Grup, Lintas Repo)
version: 1.1
date_created: 2026-09-26
last_updated: 2026-09-26
owner: AuliaPos Inbox module
tags: [inbox, chat, whatsapp, grup, tahap2, wa-gateway]
---

# Introduction

Spesifikasi ini mendefinisikan **Tahap 2** dari fitur Grup (`prd-20260926-0024-whatsapp-grup-balas-teruskan.md` GH-013, GH-014): menampilkan **siapa** yang menulis tiap pesan grup, dan **nama grup asli** sebagai judul percakapan yang stabil. Ini menutup ketiga gejala yang dilaporkan pemilik proyek (dianggap 1 percakapan, nama berubah-ubah, seperti pesan 1 orang), yang sudah di-*root cause* ke titik yang sama: WA-Gateway saat ini **tidak pernah mengambil/mengirim** identitas pengirim per pesan maupun nama (subject) grup.

> [!IMPORTANT]
> **Prasyarat: Tahap 1 sudah rilis** (`spec-design-grup-tahap1-tab-inbox.md`). Spec ini mengasumsikan tab Grup, penandaan, dan pengecualian badge sudah berjalan.

> [!NOTE]
> **Catatan revisi v1.1 (2026-09-26):** diamandemen dari v1.0 sebagai tindak lanjut `docs/audit/clarification-report-grup-tahap2-identitas-2026-09-26.md` (Readiness Score 84/100 → PROCEED). Perubahan: koreksi `REQ-004` (T1), aturan `400` menjadi `REQ-010` + `AC-009` (T2), redaksi identitas pengirim pada `REQ-008`/`AC-002` (T3), constraint urutan rilis `CON-004`/`EXT-001` (T4), label outgoing sinkron `REQ-009`/`AC-011` (T5), jalur `created=true` + `=== null` (T6), dan pencarian `group_name` sebagai backlog (T7). Tidak ada ADR baru; `CONTEXT.md` tidak berubah.

## 1. Purpose & Scope

Spec ini mencakup, dan hanya mencakup:

- **Kontrak baru** pada payload `POST /api/inbox/gateway/messages` (dikirim WA-Gateway → AuliaPos): identitas pengirim per pesan grup, dan nama grup.
- Penyimpanan identitas pengirim di kolom `messages.sender_jid` yang **sudah ada** di skema AuliaPos.
- Penyimpanan nama grup sebagai judul percakapan, dengan aturan "tidak pernah ditimpa nama pengirim terakhir" dan "tidak pernah mundur ke placeholder setelah pernah benar".
- Tampilan label pengirim di setiap pesan grup pada UI AuliaPos.

Audiens: developer WA-Gateway (untuk bagian kontrak keluar) dan developer AuliaPos (untuk bagian penyimpanan + tampilan), serta agent `/sdlc-plan-tasks` yang akan memecah dua repo ini menjadi **dua plan terpisah** (PRD Section 9.1).

### 1.1 Out of Scope

- Memulihkan label pengirim/nama grup pada pesan/percakapan grup **lama** (sebelum Tahap 2 rilis) — Non-goal permanen di PRD Section 2.3 dan Section 8.2.
- Daftar anggota grup, kelola anggota — tetap Non-goal permanen.
- Balas Pesan/Teruskan di grup — Tahap 3/4.
- Perubahan skema `conversations`/`messages` di AuliaPos — kolom yang dipakai (`messages.sender_jid`) **sudah ada** di migrasi (`app/Database/Migrations/2026-09-07-000001_CreateInboxTables.php:154`) dan **sudah** ada di daftar kolom yang boleh diisi model (`app/Models/MessageModel.php:46`), sesuai PRD Section 8.1.
- Perubahan mekanisme resolusi identitas percakapan (`resolveConversationId()`, Section 9 `docs/CHAT.md`) — grup tidak ikut alur reconciliation PN/LID, karena `@g.us` bukan nomor telepon.
- Pencarian percakapan berdasarkan **`group_name` asli** — `group_name` tidak termasuk `SEARCH_COLUMNS`, sehingga grup tidak dapat ditemukan lewat nama aslinya. PRD tidak meminta ini; grup tetap dapat ditemukan lewat `whatsapp_name`/isi pesan. Dicatat sebagai **backlog** (Section 14).

## 1.2 Open Questions & Assumptions

> [!WARNING]
> **ASSUMPTION-002: Baileys menyediakan `key.participant` untuk pesan grup masuk, dan `groupMetadata(jid).subject` untuk nama grup.** Ini adalah API standar library Baileys (dipakai WA-Gateway, dikonfirmasi dari `package.json`/README repo publik), **bukan** kode yang sudah ada di `connectionManager.js` saat ini — hasil investigasi kode publik menunjukkan `_handleIncomingMessage` **belum** mengekstrak `participant` maupun subject grup sama sekali (field ini tidak ada di objek `normalized` yang dibangun fungsi tersebut). Tahap 2 **menambah** ekstraksi ini di WA-Gateway, bukan mengaktifkan sesuatu yang sudah ada tapi mati.

> [!WARNING]
> **ASSUMPTION-003: `groupMetadata()` di-cache di sisi Gateway per JID grup, tidak dipanggil ulang di setiap pesan masuk.** PRD Section 8.3 secara eksplisit meminta ini dirancang ("cara memuatnya perlu dirancang agar tidak menambah permintaan per pesan"). Baileys `groupMetadata()` adalah panggilan jaringan ke server WhatsApp — memanggilnya di setiap pesan grup (bisa puluhan/menit di grup ramai) berisiko rate-limit. Rekomendasi: Gateway menyimpan cache in-memory `{jid: subject}` per sesi, di-refresh hanya saat cache miss atau lewat interval wajar (mis. 1 jam) — **bukan** setiap pesan. Ini keputusan desain WA-Gateway yang **tidak bisa dipaksakan** dari spec AuliaPos ini; dicatat sebagai syarat kontrak, implementasi caching sepenuhnya wewenang plan WA-Gateway.

> [!NOTE]
> **RESOLVED (Clarification Report):** Judul cadangan saat nama grup belum tersedia = teks generik **"Grup"** polos, tanpa nomor/JID pengirim terakhir (lihat REQ-005).

> [!WARNING]
> **OPEN ITEM T3 (redaksi PRD — diputuskan sebagai amandemen terpisah).** Spec ini (v1.1) memakai redaksi **"identitas pengirim (nomor telepon atau `LID`)"**. Sumber hulu masih memakai frasa lama **"nama pengirim"**: PRD `GH-013` (`prd-20260926-0024-whatsapp-grup-balas-teruskan.md` Section 10.3, acceptance criterion "menampilkan nama pengirimnya") dan bullet PRD Section 4 **"Label pengirim di dalam grup"** ("Setiap pesan grup menampilkan nama pengirimnya"). Keduanya **perlu redaksi yang sama** agar keterlacakan Spec↔PRD konsisten. **Keputusan pemilik proyek (2026-09-26): dicatat sebagai amandemen PRD terpisah**, bukan diedit pada sesi amandemen spec ini. PRD **tidak** diedit di sini; penyelarasan redaksi PRD dikerjakan lewat `/sdlc-draft-prd` dan selisihnya ditutup oleh `/sdlc-audit-consistency` berikutnya.

- **CLARIFICATION NEEDED:** Tidak ada gap tersisa untuk sisi AuliaPos. Detail caching Gateway (ASSUMPTION-003) adalah keputusan implementasi Gateway, bukan blocker spec ini. Satu item terbuka bersifat *proses* (redaksi PRD, lihat `OPEN ITEM T3` di atas), bukan gap teknis AuliaPos.

## 2. Definitions

Mengikuti `CONTEXT.md`: **Grup** (lihat spec Tahap 1). Istilah tambahan khusus spec ini:

- **Nama Grup / Subject**: nama grup yang ditetapkan admin grup di WhatsApp (disebut `subject` di Baileys). Berbeda dari `whatsapp_name` (push name kontak perorangan) yang sudah ada di skema — kolom itu **tidak dipakai ulang** untuk nama grup (lihat REQ-002).
- **Identitas Pengirim (dalam grup)**: JID anggota grup yang mengirim satu pesan tertentu (`participant` di Baileys), disimpan di `messages.sender_jid`. Berbeda dari `chat_id` percakapan (JID grup itu sendiri).

## 3. Requirements, Constraints & Guidelines

### Sisi WA-Gateway (kontrak keluar — implementasi ada di repo lain)

- **REQ-001**: Untuk pesan masuk dengan `jid_type = 'group'`, payload `POST /api/inbox/gateway/messages` menyertakan field baru `sender_jid` (JID anggota pengirim, format `<nomor>@s.whatsapp.net` atau `@lid`) yang **wajib diisi** — beda dari pesan `pn`/`lid` yang tidak butuh field ini (sender = pengirim percakapan itu sendiri, sudah implisit dari `chat_id`).
- **REQ-002**: Untuk pesan masuk dengan `jid_type = 'group'`, payload menyertakan field baru `group_name` (subject grup saat ini) — **hanya diisi kalau Gateway berhasil mendapatkannya**; kalau gagal/cache kosong, field ini **tidak dikirim** (bukan dikirim string kosong), supaya AuliaPos bisa membedakan "belum ada info" dari "nama grup memang kosong".
- **REQ-003**: `group_name` dikirim **di setiap pesan masuk grup** (bukan sekali saat grup pertama dikenali), supaya AuliaPos punya kesempatan berulang untuk mengisi nama yang sebelumnya gagal didapat (mendukung REQ-006 di sisi AuliaPos) — sesuai keputusan PRD Section 8.2 "nama grup lama akan terisi benar dengan sendirinya saat pesan berikutnya masuk".
- **CON-001**: `sender_jid`/`group_name` **tidak pernah** dikirim untuk pesan `jid_type` selain `'group'` — field ini murni tambahan untuk grup, tidak mengubah kontrak pesan pribadi sama sekali (Additive, tidak breaking).
- **GUD-001 (rekomendasi, wewenang plan WA-Gateway)**: `groupMetadata()` dipanggil dengan strategi cache (lihat ASSUMPTION-003), bukan sinkron di jalur kritis penerimaan tiap pesan kalau itu memperlambat throughput incoming.

### Sisi AuliaPos (penyimpanan & tampilan)

- **REQ-004**: Pengisian `messages.sender_jid` dari `payload['sender_jid']` **sudah berjalan** di kode saat ini: `app/Controllers/InboxGatewayApi.php:251` menulis `'sender_jid' => $payload['sender_jid'] ?? null` **tanpa syarat** untuk **semua** pesan (bukan hanya grup), dan kolomnya sudah terdaftar di `MessageModel::$allowedFields` (baris 46). Karena itu Tahap 2 **tidak** menambah baris penyimpanan baru dan **tidak** benar bahwa nilainya "tinggal diisi dari payload baru" (klaim v1.0 dikoreksi di sini). Sisa pekerjaan sisi AuliaPos untuk identitas pengirim adalah: (a) **validasi** payload grup yang mewajibkan `sender_jid` (`REQ-010`, T2) dan (b) **tampilan** label (`REQ-008`, T3).
- **REQ-005**: `InboxGatewayApi::messages()` menyimpan `payload['group_name']` ke kolom **baru** `conversations.group_name` (lihat CON-002) — **hanya kalau field ini ada di payload DAN conversation belum punya `group_name` yang tersimpan (REQ-006)**. Penulisan berlaku pada **kedua jalur**: (a) conversation yang sudah ada (jalur update), dan (b) conversation yang **baru dibuat dalam request yang sama** (`ConversationModel::resolveConversationId()` mengembalikan `created=true`, lihat `app/Models/ConversationModel.php:394-404`) — pada jalur (b), `group_name` dituliskan pada baris yang baru dibuat di request itu, bukan ditunda ke pesan berikutnya.
- **CON-002**: `conversations.group_name` adalah **kolom baru**, `VARCHAR(255) NULL` — migrasi database kecil dibutuhkan (satu-satunya perubahan skema di seluruh PRD ini). **Tidak memakai ulang** `whatsapp_name`/`contact_name` karena keduanya adalah identitas *kontak perorangan* (Section 10 `docs/CHAT.md`) dengan aturan penimpaan berbeda — mencampurnya akan melanggar invarian "setiap operasi hanya menulis kolom yang jadi tanggung jawabnya" (`docs/CHAT.md` §18).
- **REQ-006 (write-once, bukan overwrite)**: `group_name` **hanya ditulis kalau kolom itu masih `NULL`** di baris `conversations` yang sedang diproses. Setelah terisi, nilai berikutnya dari Gateway (termasuk kalau berbeda — mis. admin grup ganti nama) **tidak menimpanya** dalam lingkup PRD ini (PRD Section 4 GH-014 hanya mensyaratkan "tidak berubah ketika anggota berbeda mengirim", tidak mensyaratkan sinkronisasi ganti-nama-grup — itu di luar lingkup, dicatat sebagai TODO di Section 14). Dua penulisan `group_name` pertama yang benar-benar bersamaan (race) berakhir ***last-write-wins*** — **diterima**: kasus jarang, kedua nilai berasal dari subject grup yang sama di server WhatsApp, dan tidak ada kode baru untuk mencegahnya.
- **REQ-007**: Judul percakapan grup di UI (`app/Views/inbox/index.php`) menampilkan `conversations.group_name` kalau terisi; kalau `NULL`, menampilkan teks generik **"Grup"** — **tidak pernah** memakai `whatsapp_name`/nama pengirim terakhir sebagai fallback (ini mengubah baris 1122 `app/Views/inbox/index.php` yang saat ini memfallback ke nama kontak, tapi **hanya untuk kondisi `jid_type==='group'`**; perilaku pribadi tidak berubah).
- **REQ-008**: Setiap pesan grup yang ditampilkan menyertakan **identitas pengirim** — bukan nama orang — diambil dari `messages.sender_jid`; ditampilkan sebagai **nomor telepon** (untuk `sender_jid` berformat `@s.whatsapp.net`) atau penanda generik **`LID`** untuk `@lid` (mengikuti pola tampilan JID pribadi yang sudah ada, `jid_type === 'lid' ? 'LID' : ...`, baris 1123). Nama tampilan per peserta **tidak** dijamin tersedia dari Baileys tanpa sinkronisasi kontak, sehingga bukan bagian dari kontrak ini (Keputusan A, Clarification Report). Lihat `OPEN ITEM` di Section 1.2 soal penyelarasan redaksi PRD `GH-013`.
- **REQ-009**: Pesan grup yang dikirim kasir dari AuliaPos (outgoing) menampilkan nama staff pengirim (`sent_by_user_id` → `UserModel`) — **bukan** `messages.sender_jid` (yang tetap `NULL` untuk outgoing, tidak berubah dari perilaku sekarang). Untuk outgoing grup yang **tersinkron dari WA Web/HP** (`sent_by_user_id = NULL`, `docs/CHAT.md` §7), label yang ditampilkan mengikuti aturan yang sudah berlaku di seluruh Inbox: **"Staff (WA Web/HP)"** — bukan JID/nomor, bukan string kosong. Ini sama untuk grup maupun percakapan pribadi; tidak ada aturan label baru.
- **CON-003**: Pesan grup lama (`messages.sender_jid IS NULL`, tersimpan sebelum Tahap 2) ditampilkan **tanpa** baris label pengirim sama sekali — bukan placeholder "?" atau teks kosong yang membingungkan (PRD Section 5.3).
- **REQ-010 (validasi wajib — dinaikkan dari tabel §4.1)**: `InboxGatewayApi::messages()` **menolak request dengan `400`** kalau `jid_type === 'group'` dan `sender_jid` kosong/absen, dan **tidak menyimpan apa pun** (tidak ada baris `messages` baru, tidak ada perubahan `conversations`) — termasuk tidak menulis `group_name`. Validasi mengikuti pola validasi field wajib yang sudah ada di method yang sama (baris 55-63) dan dijalankan **sebelum** transaksi insert. Validasi **tidak** mengubah perilaku pesan `jid_type` selain `'group'`.
- **CON-004 (constraint urutan rilis — non-negotiable)**: Sisi AuliaPos Tahap 2 **hanya boleh dirilis setelah** perubahan kontrak Gateway (`REQ-001`/`REQ-002`/`REQ-003`) terpasang di toko. Konsekuensi yang **wajib** dipahami: pesan grup dari Gateway lama (belum mengirim `sender_jid`) **ditolak `400` oleh `REQ-010` dan TIDAK tersimpan** — bukan disimpan sebagian, bukan masuk antrean. Ini konsisten dengan invarian proyek: **tidak ada outgoing queue**, dan kegagalan berarti tidak ada baris tersimpan sama sekali, retry adalah aksi manusia (`docs/CHAT.md` §5, §18). Urutan rollout: **Gateway naik dulu → AuliaPos naik**. Urutan rollback: **Gateway turun dulu**. Ini **berkontras** dengan `group_name` yang *additive* (`GUD-002`): absennya `group_name` aman, sedangkan absennya `sender_jid` pada grup **fatal** (ditolak `400`).
- **GUD-002**: Field `group_name` di payload **additive** — kalau Gateway lama (belum upgrade) tetap mengirim payload tanpa field ini, AuliaPos tidak boleh error; `payload['group_name'] ?? null` (pola yang sama seperti field opsional lain di `InboxGatewayApi.php`, mis. `payload['contact_name']`, baris 205).

## 4. Interfaces & Data Contracts

### 4.1 `POST /api/inbox/gateway/messages` — payload tambahan (khusus `jid_type='group'`)

```json
{
  "wa_message_id": "...",
  "chat_id": "120363012345678901@g.us",
  "jid_type": "group",
  "sender_jid": "6281234567890@s.whatsapp.net",
  "group_name": "Reseller Cabang A",
  "message_type": "text",
  "text": "..."
}
```

| Field | Wajib untuk `jid_type='group'`? | Catatan |
| --- | --- | --- |
| `sender_jid` | **Ya** | JID anggota pengirim; request ditolak `400` dan **tidak disimpan** kalau kosong untuk pesan grup — aturan bernomor `REQ-010` + `AC-009` (konsisten dengan validasi field wajib yang sudah ada di `InboxGatewayApi::messages()` baris 55-63). |
| `group_name` | Tidak (opsional) | Diproses kalau ada; diabaikan kalau tidak dikirim atau conversation sudah punya `group_name`. |

### 4.2 Migrasi baru: `conversations.group_name`

```php
'group_name' => [
    'type'       => 'VARCHAR',
    'constraint' => 255,
    'null'       => true,
    'default'    => null,
],
```

### 4.3 Bentuk response API tidak berubah bentuk

`GET /inbox/api/conversations` dan `GET /inbox/api/conversations/{id}/messages` menambah key baru (`group_name` pada conversation, `sender_jid` sudah ada tapi kini terisi pada message) — **additive**, tidak menghapus/mengganti key yang sudah ada.

## 5. Acceptance Criteria

- **AC-001**: Given pesan grup baru masuk dengan `sender_jid` terisi, When disimpan, Then `messages.sender_jid` terisi nilai itu persis.
- **AC-002**: Given pesan grup ditampilkan, Then **identitas pengirim (nomor telepon atau `LID`)** tampil pada pesan itu, bertahan setelah reload thread dan setelah berpindah-kembali percakapan. Label yang tampil **bukan** nama orang (REQ-008, Keputusan A).
- **AC-003**: Given conversation grup baru pertama kali dikenali tanpa `group_name` dari Gateway, When ditampilkan, Then judul menampilkan **"Grup"** generik, bukan nama pengirim terakhir.
- **AC-004**: Given `group_name` sudah terisi pada suatu conversation, When pesan grup berikutnya masuk dari anggota berbeda (dengan atau tanpa `group_name` di payload), Then judul percakapan **tidak berubah**.
- **AC-005**: Given `group_name` masih `NULL` dan pesan berikutnya membawa `group_name` yang valid, When disimpan, Then judul percakapan langsung menampilkan nama itu tanpa aksi manual apa pun.
- **AC-006**: Given pesan grup lama (`sender_jid IS NULL`), When ditampilkan, Then tidak ada label pengirim, tanpa teks pengganti yang membingungkan.
- **AC-007**: Given kasir mengirim pesan ke grup dari AuliaPos, When ditampilkan, Then label yang tampil adalah nama staff, bukan JID/nomor.
- **AC-008**: Given percakapan pribadi (bukan grup), When Tahap 2 dirilis, Then tampilan judul dan nama kontak **tidak berubah** dari sebelumnya.
- **AC-009**: Given request `POST /api/inbox/gateway/messages` dengan `jid_type='group'` **tanpa** `sender_jid` (absen atau string kosong), When diproses, Then server menolak `400` dan **tidak ada** baris `messages` baru maupun perubahan `conversations` yang tersimpan — termasuk `group_name` tidak ditulis (REQ-010). Given request yang sama dengan `sender_jid` terisi, Then diproses normal. Given pesan `jid_type` selain `'group'` tanpa `sender_jid`, Then perilakunya **tidak berubah**.
- **AC-010**: Given conversation grup baru dibuat dalam request yang sama (`created=true`) dan payload membawa `group_name`, When disimpan, Then `conversations.group_name` terisi pada baris yang baru dibuat itu (REQ-005, REQ-006) — bukan hanya pada jalur update conversation yang sudah ada.
- **AC-011**: Given sebuah pesan grup outgoing yang tersinkron dari WA Web/HP (`sent_by_user_id = NULL`), When ditampilkan, Then label yang tampil adalah **"Staff (WA Web/HP)"** (REQ-009) — bukan nomor/JID dan bukan string kosong. Perilaku percakapan pribadi tidak berubah.

## 6. Test Automation Strategy & Testing Seams

- **Testing Seams**: HTTP boundary `InboxGatewayApi::messages()` (unit/feature test payload dengan `jid_type='group'` + `sender_jid`/`group_name`), dan `Inbox::apiConversations()`/`apiMessages()` untuk memastikan field baru ikut terbawa ke response.
- **Test Levels**: Unit (model), Feature (endpoint gateway masuk + endpoint baca AuliaPos).
  - **`400` untuk grup tanpa `sender_jid`** (AC-009) — assert response `400` **dan** tidak ada baris `messages`/perubahan `conversations` yang tersimpan.
  - **`group_name` pada jalur `created=true`** (AC-010) — assert kolom terisi pada baris conversation yang baru dibuat di request yang sama.
  - **Label outgoing sinkron WA Web/HP** (AC-011) — assert label **"Staff (WA Web/HP)"** pada pesan grup `sent_by_user_id = NULL`, dan tidak berubah untuk percakapan pribadi.
- **Test Data Management**: Tambah kasus factory `jid_type='group'` dengan dan tanpa `group_name`/`sender_jid` terisi, termasuk kasus "pesan lama tanpa sender_jid" untuk AC-006, dan kasus conversation grup baru (`created=true`) untuk AC-010.
- **Coverage Requirements**: `vendor/bin/phpunit --no-coverage` kode keluar 0, mengikuti kebijakan proyek (PRD Section 7.3).

## 7. Project Structure & Commands

### Project Structure (AuliaPos)

- Migrasi baru: `app/Database/Migrations/<timestamp>_AddGroupNameToConversations.php`.
- `app/Controllers/InboxGatewayApi.php` — tambah **validasi `400`** grup tanpa `sender_jid` (REQ-010); `sender_jid` **sudah** tertulis di baris 251 (REQ-004, tanpa perubahan); tulis `group_name` saat insert message/update conversation **termasuk jalur `created=true`** (REQ-005).
- `app/Models/ConversationModel.php` — tambahkan `group_name` ke `$allowedFields`.
- `app/Views/inbox/index.php` — tampilkan `group_name`/fallback "Grup", label pengirim per pesan.

### Project Structure (WA-Gateway — repo terpisah, plan terpisah)

- `src/whatsapp/connectionManager.js` — ekstraksi `participant`, pemanggilan `groupMetadata()` dengan cache, penambahan field ke payload keluar.

### Commands (AuliaPos)

- **Migrasi:** `php spark migrate`
- **Test:** `vendor/bin/phpunit --no-coverage`

## 8. Code Style & Conventions

Pengisian field opsional dari payload mengikuti pola yang **sudah ada** di `InboxGatewayApi.php`:

```php
// Pola yang sudah ada (baris 205), dipakai ulang untuk group_name.
// REQ-006 write-once: cek NULL eksplisit (=== null), BUKAN empty() --
// empty() juga cocok untuk '' dan akan menyamakan "belum pernah diisi"
// dengan "pernah diisi nilai kosong". Berlaku untuk kedua jalur:
// conversation yang sudah ada DAN conversation baru (created=true).
$groupNameFromPayload = !empty($payload['group_name']) ? (string) $payload['group_name'] : null;

if ($groupNameFromPayload !== null && $conversation['group_name'] === null) {
    $conversationModel->update($conversationId, ['group_name' => $groupNameFromPayload]);
}
```

## 9. Implementation Boundaries

- **Always do:** Jalankan migrasi di database lokal & test sebelum menyentuh kode payload; pastikan field baru bersifat additive (tidak mengubah validasi field lama); tegakkan validasi `400` grup tanpa `sender_jid` **sebelum** transaksi insert (`REQ-010`) dan pastikan tidak ada baris `messages`/perubahan `conversations` yang tersimpan saat ditolak; hormati constraint urutan rilis `CON-004`.
- **Ask first:** Perubahan pada `InboxGatewayApi::messages()` yang menyentuh alur reconciliation identitas (Section 9 `docs/CHAT.md`) — Tahap 2 **tidak boleh** menyentuh alur itu, kalau developer merasa perlu, itu sinyal salah paham dan wajib dikonfirmasi dulu.
- **Never do:** Menulis `group_name` untuk `jid_type` selain `'group'`; menimpa `group_name` yang sudah terisi (REQ-006); mengganti/memakai ulang kolom `whatsapp_name`/`contact_name` untuk nama grup; merilis AuliaPos sebelum perubahan Gateway terpasang (`CON-004`, pesan grup akan hilang `400`); menyimpan pesan grup tanpa `sender_jid`.

## 10. Rationale, Context & Architecture Decisions (ADRs)

Tidak ada ADR baru. Kolom `group_name` terpisah (bukan reuse `whatsapp_name`) adalah penerapan langsung invarian dimensi independen yang sudah ada (`docs/CHAT.md` §18), bukan trade-off baru yang butuh pencatatan ADR (gagal *Triple Gate Validation* — tidak *surprising*, sudah ada preseden `ADR-0001` soal reuse kolom yang justru menunjukkan pola sebaliknya sudah dipertimbangkan proyek ini).

## 11. Dependencies & External Integrations

### External Systems

- **EXT-001**: `tikusgot007/WA-Gateway` — **wajib** merilis perubahan kontrak `REQ-001`/`REQ-002`/`REQ-003` **sebelum** sisi AuliaPos dirilis (lihat `CON-004`). Ini bukan sekadar "sebelum AuliaPos punya data untuk ditampilkan": karena `sender_jid` wajib + `400` (`REQ-010`), Gateway lama membuat **setiap** pesan grup ditolak `400` dan **tidak tersimpan** (tidak ada outgoing queue, `docs/CHAT.md` §5/§18). **Urutan rollout: Gateway naik dulu → AuliaPos naik. Urutan rollback: Gateway turun dulu.** Dua plan terpisah (PRD Section 9.1); dependency ini **blocking**, kontras dengan `group_name` yang *additive* (`GUD-002`).

### Third-Party Services

- **SVC-001**: Baileys (library WhatsApp Web dipakai WA-Gateway) — `groupMetadata()` dan `key.participant` adalah API bawaan library, bukan yang dibangun sendiri.

## 12. Examples & Edge Cases

```php
// Fallback judul percakapan grup, app/Views/inbox/index.php
function judulPercakapan(array $conv): string {
    if (($conv['jid_type'] ?? null) === 'group') {
        return $conv['group_name'] ?: 'Grup';
    }
    // ... logic nama kontak pribadi yang sudah ada, tidak berubah ...
}
```

**Edge case**: Gateway mengirim `group_name` yang berbeda dari yang tersimpan (mis. admin grup ganti nama WhatsApp) — REQ-006 sengaja **mengabaikannya** dalam lingkup PRD ini; dicatat sebagai TODO di Section 14, bukan bug.

## 13. Validation Criteria

- `vendor/bin/phpunit --no-coverage` hijau 100%.
- Test otomatis: pesan grup tanpa `sender_jid` → `400` dan tidak tersimpan (`AC-009`); `group_name` terisi pada conversation `created=true` (`AC-010`); label outgoing sinkron WA Web/HP pada grup (`AC-011`).
- Manual check dengan grup WhatsApp uji: judul tidak berubah saat 2+ anggota berbeda bergantian mengirim; identitas pengirim (nomor/`LID`) tampil benar per pesan.
- Manual check urutan rilis (`CON-004`): sebelum merilis AuliaPos, pastikan pesan grup dari Gateway yang belum diperbarui ditolak `400` dan **tidak muncul** di thread — verifikasi bahwa Gateway sudah terpasang lebih dulu.

## 14. Related Specifications / Further Reading

- [`spec-index.md`](./spec-index.md)
- [`spec-design-grup-tahap1-tab-inbox.md`](./spec-design-grup-tahap1-tab-inbox.md) — prasyarat
- [`spec-design-balas-pesan.md`](./spec-design-balas-pesan.md) — tahap berikutnya
- `docs/CHAT.md` §9 (Identity & Conversation Reconciliation — tidak berlaku untuk grup), §18 (Developer Invariants)

**TODO (dicatat, di luar lingkup PRD ini):** sinkronisasi ganti-nama-grup setelah `group_name` pertama kali terisi — butuh PRD/keputusan produk sendiri kalau suatu saat dibutuhkan.

**BACKLOG (dicatat, out of scope / T7):** pencarian percakapan berdasarkan `group_name` asli — `group_name` tidak termasuk `SEARCH_COLUMNS`, sehingga grup tidak dapat ditemukan lewat nama aslinya. PRD tidak meminta ini; perlu keputusan produk sendiri bila suatu saat dibutuhkan.

**PENDING (amandemen terpisah / T3):** redaksi PRD `GH-013` (Section 10.3) dan bullet PRD Section 4 "Label pengirim di dalam grup" masih memakai frasa **"nama pengirim"** dan perlu diselaraskan menjadi **"identitas pengirim (nomor telepon atau `LID`)"** lewat `/sdlc-draft-prd`. Lihat `OPEN ITEM T3` di Section 1.2.
