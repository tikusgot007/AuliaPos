---
title: Grup — Tahap 2 (Label Pengirim & Nama Grup, Lintas Repo)
version: 1.0
date_created: 2026-09-26
owner: AuliaPos Inbox module
tags: [inbox, chat, whatsapp, grup, tahap2, wa-gateway]
---

# Introduction

Spesifikasi ini mendefinisikan **Tahap 2** dari fitur Grup (`prd-20260926-0024-whatsapp-grup-balas-teruskan.md` GH-013, GH-014): menampilkan **siapa** yang menulis tiap pesan grup, dan **nama grup asli** sebagai judul percakapan yang stabil. Ini menutup ketiga gejala yang dilaporkan pemilik proyek (dianggap 1 percakapan, nama berubah-ubah, seperti pesan 1 orang), yang sudah di-*root cause* ke titik yang sama: WA-Gateway saat ini **tidak pernah mengambil/mengirim** identitas pengirim per pesan maupun nama (subject) grup.

> [!IMPORTANT]
> **Prasyarat: Tahap 1 sudah rilis** (`spec-design-grup-tahap1-tab-inbox.md`). Spec ini mengasumsikan tab Grup, penandaan, dan pengecualian badge sudah berjalan.

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

## 1.2 Open Questions & Assumptions

> [!WARNING]
> **ASSUMPTION-002: Baileys menyediakan `key.participant` untuk pesan grup masuk, dan `groupMetadata(jid).subject` untuk nama grup.** Ini adalah API standar library Baileys (dipakai WA-Gateway, dikonfirmasi dari `package.json`/README repo publik), **bukan** kode yang sudah ada di `connectionManager.js` saat ini — hasil investigasi kode publik menunjukkan `_handleIncomingMessage` **belum** mengekstrak `participant` maupun subject grup sama sekali (field ini tidak ada di objek `normalized` yang dibangun fungsi tersebut). Tahap 2 **menambah** ekstraksi ini di WA-Gateway, bukan mengaktifkan sesuatu yang sudah ada tapi mati.

> [!WARNING]
> **ASSUMPTION-003: `groupMetadata()` di-cache di sisi Gateway per JID grup, tidak dipanggil ulang di setiap pesan masuk.** PRD Section 8.3 secara eksplisit meminta ini dirancang ("cara memuatnya perlu dirancang agar tidak menambah permintaan per pesan"). Baileys `groupMetadata()` adalah panggilan jaringan ke server WhatsApp — memanggilnya di setiap pesan grup (bisa puluhan/menit di grup ramai) berisiko rate-limit. Rekomendasi: Gateway menyimpan cache in-memory `{jid: subject}` per sesi, di-refresh hanya saat cache miss atau lewat interval wajar (mis. 1 jam) — **bukan** setiap pesan. Ini keputusan desain WA-Gateway yang **tidak bisa dipaksakan** dari spec AuliaPos ini; dicatat sebagai syarat kontrak, implementasi caching sepenuhnya wewenang plan WA-Gateway.

> [!NOTE]
> **RESOLVED (Clarification Report):** Judul cadangan saat nama grup belum tersedia = teks generik **"Grup"** polos, tanpa nomor/JID pengirim terakhir (lihat REQ-005).

- **CLARIFICATION NEEDED:** Tidak ada gap tersisa untuk sisi AuliaPos. Detail caching Gateway (ASSUMPTION-003) adalah keputusan implementasi Gateway, bukan blocker spec ini.

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

- **REQ-004**: `InboxGatewayApi::messages()` menyimpan `payload['sender_jid']` ke `messages.sender_jid` untuk pesan `jid_type='group'` — kolom dan pengisiannya **sudah didaftarkan** di `MessageModel::$allowedFields` (baris 46), tinggal diisi dari payload baru (pola identik dengan pengisian field lain di method yang sama, `app/Controllers/InboxGatewayApi.php:251`).
- **REQ-005**: `InboxGatewayApi::messages()` menyimpan `payload['group_name']` ke kolom **baru** `conversations.group_name` (lihat CON-002) — **hanya kalau field ini ada di payload DAN conversation belum punya `group_name` yang tersimpan (REQ-006)**.
- **CON-002**: `conversations.group_name` adalah **kolom baru**, `VARCHAR(255) NULL` — migrasi database kecil dibutuhkan (satu-satunya perubahan skema di seluruh PRD ini). **Tidak memakai ulang** `whatsapp_name`/`contact_name` karena keduanya adalah identitas *kontak perorangan* (Section 10 `docs/CHAT.md`) dengan aturan penimpaan berbeda — mencampurnya akan melanggar invarian "setiap operasi hanya menulis kolom yang jadi tanggung jawabnya" (`docs/CHAT.md` §18).
- **REQ-006 (write-once, bukan overwrite)**: `group_name` **hanya ditulis kalau kolom itu masih `NULL`** di baris `conversations` yang sedang diproses. Setelah terisi, nilai berikutnya dari Gateway (termasuk kalau berbeda — mis. admin grup ganti nama) **tidak menimpanya** dalam lingkup PRD ini (PRD Section 4 GH-014 hanya mensyaratkan "tidak berubah ketika anggota berbeda mengirim", tidak mensyaratkan sinkronisasi ganti-nama-grup — itu di luar lingkup, dicatat sebagai TODO di Section 14).
- **REQ-007**: Judul percakapan grup di UI (`app/Views/inbox/index.php`) menampilkan `conversations.group_name` kalau terisi; kalau `NULL`, menampilkan teks generik **"Grup"** — **tidak pernah** memakai `whatsapp_name`/nama pengirim terakhir sebagai fallback (ini mengubah baris 1122 `app/Views/inbox/index.php` yang saat ini memfallback ke nama kontak, tapi **hanya untuk kondisi `jid_type==='group'`**; perilaku pribadi tidak berubah).
- **REQ-008**: Setiap pesan grup yang ditampilkan menyertakan label pengirim, diambil dari `messages.sender_jid` — ditampilkan sebagai nomor telepon (untuk `sender_jid` berformat `@s.whatsapp.net`) atau penanda generik untuk `@lid` (mengikuti pola tampilan JID pribadi yang sudah ada, `jid_type === 'lid' ? 'LID' : ...`, baris 1123).
- **REQ-009**: Pesan grup yang dikirim kasir dari AuliaPos (outgoing) menampilkan nama staff pengirim (`sent_by_user_id` → `UserModel`) — **bukan** `messages.sender_jid` (yang tetap `NULL` untuk outgoing, tidak berubah dari perilaku sekarang).
- **CON-003**: Pesan grup lama (`messages.sender_jid IS NULL`, tersimpan sebelum Tahap 2) ditampilkan **tanpa** baris label pengirim sama sekali — bukan placeholder "?" atau teks kosong yang membingungkan (PRD Section 5.3).
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
| `sender_jid` | **Ya** | JID anggota pengirim; request ditolak `400` kalau kosong untuk pesan grup (konsisten dengan validasi field wajib yang sudah ada di `InboxGatewayApi::messages()` baris 55-63). |
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
- **AC-002**: Given pesan grup ditampilkan, Then nama/nomor pengirim tampil pada pesan itu, bertahan setelah reload thread dan setelah berpindah-kembali percakapan.
- **AC-003**: Given conversation grup baru pertama kali dikenali tanpa `group_name` dari Gateway, When ditampilkan, Then judul menampilkan **"Grup"** generik, bukan nama pengirim terakhir.
- **AC-004**: Given `group_name` sudah terisi pada suatu conversation, When pesan grup berikutnya masuk dari anggota berbeda (dengan atau tanpa `group_name` di payload), Then judul percakapan **tidak berubah**.
- **AC-005**: Given `group_name` masih `NULL` dan pesan berikutnya membawa `group_name` yang valid, When disimpan, Then judul percakapan langsung menampilkan nama itu tanpa aksi manual apa pun.
- **AC-006**: Given pesan grup lama (`sender_jid IS NULL`), When ditampilkan, Then tidak ada label pengirim, tanpa teks pengganti yang membingungkan.
- **AC-007**: Given kasir mengirim pesan ke grup dari AuliaPos, When ditampilkan, Then label yang tampil adalah nama staff, bukan JID/nomor.
- **AC-008**: Given percakapan pribadi (bukan grup), When Tahap 2 dirilis, Then tampilan judul dan nama kontak **tidak berubah** dari sebelumnya.

## 6. Test Automation Strategy & Testing Seams

- **Testing Seams**: HTTP boundary `InboxGatewayApi::messages()` (unit/feature test payload dengan `jid_type='group'` + `sender_jid`/`group_name`), dan `Inbox::apiConversations()`/`apiMessages()` untuk memastikan field baru ikut terbawa ke response.
- **Test Levels**: Unit (model), Feature (endpoint gateway masuk + endpoint baca AuliaPos).
- **Test Data Management**: Tambah kasus factory `jid_type='group'` dengan dan tanpa `group_name`/`sender_jid` terisi, termasuk kasus "pesan lama tanpa sender_jid" untuk AC-006.
- **Coverage Requirements**: `vendor/bin/phpunit --no-coverage` kode keluar 0, mengikuti kebijakan proyek (PRD Section 7.3).

## 7. Project Structure & Commands

### Project Structure (AuliaPos)

- Migrasi baru: `app/Database/Migrations/<timestamp>_AddGroupNameToConversations.php`.
- `app/Controllers/InboxGatewayApi.php` — isi `sender_jid`/`group_name` saat insert message/update conversation.
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
// Pola yang sudah ada (baris 205), dipakai ulang untuk group_name:
$groupNameFromPayload = !empty($payload['group_name']) ? (string) $payload['group_name'] : null;

if ($groupNameFromPayload !== null && empty($conversation['group_name'])) {
    $conversationModel->update($conversationId, ['group_name' => $groupNameFromPayload]);
}
```

## 9. Implementation Boundaries

- **Always do:** Jalankan migrasi di database lokal & test sebelum menyentuh kode payload; pastikan field baru bersifat additive (tidak mengubah validasi field lama).
- **Ask first:** Perubahan pada `InboxGatewayApi::messages()` yang menyentuh alur reconciliation identitas (Section 9 `docs/CHAT.md`) — Tahap 2 **tidak boleh** menyentuh alur itu, kalau developer merasa perlu, itu sinyal salah paham dan wajib dikonfirmasi dulu.
- **Never do:** Menulis `group_name` untuk `jid_type` selain `'group'`; menimpa `group_name` yang sudah terisi (REQ-006); mengganti/memakai ulang kolom `whatsapp_name`/`contact_name` untuk nama grup.

## 10. Rationale, Context & Architecture Decisions (ADRs)

Tidak ada ADR baru. Kolom `group_name` terpisah (bukan reuse `whatsapp_name`) adalah penerapan langsung invarian dimensi independen yang sudah ada (`docs/CHAT.md` §18), bukan trade-off baru yang butuh pencatatan ADR (gagal *Triple Gate Validation* — tidak *surprising*, sudah ada preseden `ADR-0001` soal reuse kolom yang justru menunjukkan pola sebaliknya sudah dipertimbangkan proyek ini).

## 11. Dependencies & External Integrations

### External Systems

- **EXT-001**: `tikusgot007/WA-Gateway` — **wajib** merilis perubahan kontrak REQ-001/002/003 sebelum sisi AuliaPos punya data untuk ditampilkan. Dua plan terpisah (PRD Section 9.1).

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
- Manual check dengan grup WhatsApp uji: judul tidak berubah saat 2+ anggota berbeda bergantian mengirim; label pengirim tampil benar per pesan.

## 14. Related Specifications / Further Reading

- [`spec-index.md`](./spec-index.md)
- [`spec-design-grup-tahap1-tab-inbox.md`](./spec-design-grup-tahap1-tab-inbox.md) — prasyarat
- [`spec-design-balas-pesan.md`](./spec-design-balas-pesan.md) — tahap berikutnya
- `docs/CHAT.md` §9 (Identity & Conversation Reconciliation — tidak berlaku untuk grup), §18 (Developer Invariants)

**TODO (dicatat, di luar lingkup PRD ini):** sinkronisasi ganti-nama-grup setelah `group_name` pertama kali terisi — butuh PRD/keputusan produk sendiri kalau suatu saat dibutuhkan.
