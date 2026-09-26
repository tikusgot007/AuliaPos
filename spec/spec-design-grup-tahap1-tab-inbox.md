---
title: Grup — Tahap 1 (Tab Inbox, Penandaan, Aksi Dinonaktifkan, Badge)
version: 1.0
date_created: 2026-09-26
owner: AuliaPos Inbox module
tags: [inbox, chat, whatsapp, grup, tahap1]
---

# Introduction

Spesifikasi ini mendefinisikan **Tahap 1** dari fitur Grup (`prd-20260926-0024-whatsapp-grup-balas-teruskan.md` GH-011, GH-012): memisahkan percakapan grup WhatsApp dari antrean kerja kasir di AuliaPos. Tahap ini **murni sisi AuliaPos** — tidak menyentuh WA-Gateway, tidak menambah skema database, dan tidak menunggu repo lain. Ia menutup gejala "grup ikut menghitung badge `perlu_dibalas`", bukan gejala "nama grup berubah-ubah" (itu Tahap 2, lihat `spec-design-grup-tahap2-identitas.md`).

## 1. Purpose & Scope

Spec ini mencakup, dan hanya mencakup:

- Tab **Grup** baru di daftar percakapan `/inbox`.
- Penanda visual **Grup** pada baris daftar dan header percakapan.
- Penonaktifan/penyembunyian aksi yang tidak berlaku untuk grup: Ambil, Lepas, Tutup, Snooze, Konfirmasi Nomor, Edit Profil.
- Pengecualian percakapan grup dari perhitungan `response_state`/`queue_status`/badge `perlu_dibalas`.

Audiens: developer yang akan menjalankan `/sdlc-plan-tasks` → `/sdlc-write-code` untuk Tahap 1, serta `/sdlc-clarify-reqs` dan `/sdlc-audit-consistency` berikutnya.

### 1.1 Out of Scope

- Label pengirim per pesan dan nama grup sebagai judul (Tahap 2 — `spec-design-grup-tahap2-identitas.md`).
- Balas Pesan dan Teruskan pada percakapan grup (Tahap 3 & 4).
- Perubahan skema `conversations`/`messages` — Tahap 1 **tidak menambah kolom apa pun**, hanya membaca `jid_type` yang sudah ada dan sudah terisi.
- Perubahan apa pun di repo `tikusgot007/WA-Gateway`.
- Daftar anggota grup, keluar/kelola anggota grup, penautan ke data pelanggan POS — dikunci sebagai Non-goal permanen di PRD Section 2.3.
- Perubahan pada dimensi kepemilikan (`assigned_to`), lifecycle (`status`), atau Read/Unread — PRD ini hanya menyentuh dimensi **identitas** (`docs/CHAT.md` §18).

## 1.2 Open Questions & Assumptions

> [!WARNING]
> **ASSUMPTION-001: Nilai `jid_type` untuk grup adalah string literal `'group'`.** Dikonfirmasi dari kode publik `tikusgot007/WA-Gateway` (`src/whatsapp/jidUtils.js`, fungsi `classifyJid()`): JID `@g.us` → `'group'`, `@lid` → `'lid'`, `@s.whatsapp.net` → `'pn'`. Kolom `conversations.jid_type` (`app/Database/Migrations/2026-09-07-000001_CreateInboxTables.php:46`) adalah `VARCHAR(20)` bebas tanpa `ENUM` di level database, jadi nilai ini tidak dipaksa DB — kebenarannya bergantung pada apa yang benar-benar dikirim Gateway saat ini. **Database lokal sesi ini kosong dari data grup** (hanya 1 baris `jid_type='pn'`), sehingga nilai ini belum diverifikasi terhadap data produksi. Developer **wajib** memverifikasi nilai literal ini terhadap `aulia_inboxdb` produksi (atau log payload `POST /api/inbox/gateway/messages`) sebelum menulis kode filter, sebagai langkah pertama Tahap Implementation.

- **CLARIFICATION NEEDED:** Tidak ada — seluruh keputusan Tahap 1 sudah dikunci di PRD dan Clarification Report (Snooze termasuk aksi yang dinonaktifkan, per Resolved Items #1).

## 2. Definitions

Mengikuti `CONTEXT.md`:

- **Grup**: Percakapan WhatsApp yang diikuti lebih dari dua pihak, tidak terikat satu nomor/pelanggan. `_Avoid_`: Group chat, Group, Grup WhatsApp.
- **queue_status**: Status turunan (dihitung, bukan disimpan) yang menentukan tab tempat sebuah percakapan pribadi muncul di daftar (`belum_diambil`/`open`/`menunggu`/`ditunda`/`selesai`), dihitung di `ConversationModel::withComputedStatus()`. Istilah ini sudah ada di `spec-design-m3-operational-inbox-fase1.md`, dipakai apa adanya di sini.
- **Percakapan Pribadi**: istilah kerja spec ini untuk percakapan dengan `jid_type` selain `'group'` (yaitu `'pn'`, `'lid'`, atau `'unknown'`) — untuk membedakan dari percakapan Grup. Tidak ada di `CONTEXT.md` karena bukan istilah bisnis baru, hanya negasi dari Grup.

## 3. Requirements, Constraints & Guidelines

- **REQ-001**: `GET /inbox/api/conversations` menerima nilai `status` baru: `'grup'` (ditambahkan ke `Inbox::QUEUE_STATUSES`, `app/Controllers/Inbox.php:29`). Filter ini mengembalikan seluruh percakapan dengan `jid_type = 'group'`, mengabaikan `response_state`/`queue_status` yang dihitung.
- **REQ-002**: Percakapan dengan `jid_type = 'group'` **tidak pernah** cocok dengan filter `status` manapun dari `Inbox::QUEUE_STATUSES` yang lama (`belum_diambil`, `open`, `menunggu`, `ditunda`, `selesai`) — terlepas dari nilai `response_state` hasil hitung `withComputedStatus()`.
- **REQ-003**: `ConversationModel::withComputedStatus()` menghitung `queue_status = 'grup'` untuk baris dengan `jid_type = 'group'`, **sebelum** aturan `response_state` yang sudah ada dievaluasi (lihat CON-002). `response_state` untuk grup tetap dihitung apa adanya (tidak dipakai untuk keputusan tab, hanya supaya kontrak API tidak berubah bentuk untuk consumer lain).
- **REQ-004**: `GET /inbox/api/perlu-dibalas-count` (`Inbox::apiPerluDibalasCount()`, baris 316-333) mengecualikan percakapan `jid_type = 'group'` dari `count()`, terlepas dari `response_state` hasil hitungnya. Ini mensyaratkan menambah `jid_type` ke daftar kolom `select()` yang sudah ada (baris 327: `'status, last_message_direction, last_message_at, last_seen_by_assignee_at, snoozed_until'`) — tanpa kolom ini, filter grup di fungsi ini tidak mungkin berjalan.
- **REQ-005**: Baris percakapan grup di UI daftar (`app/Views/inbox/index.php`) menampilkan penanda teks **"Grup"** (badge), ditempatkan konsisten dengan badge `response_state` yang sudah ada (`RESPONSE_STATE_LABEL`, baris ~840).
- **REQ-006**: Header percakapan (saat grup dibuka) menampilkan penanda yang sama, terpisah dari judul percakapan — supaya tetap terbaca walau judul (Tahap 2) belum benar.
- **CON-001**: Tombol Ambil, Lepas, Tutup, Snooze **tidak dirender sama sekali** pada percakapan grup — bukan dirender lalu di-`disabled`. Ini mencegah kasir menekan tombol yang pasti gagal (PRD Section 4, "tidak boleh tampil sebagai tombol yang selalu gagal").
- **CON-002**: Konfirmasi Nomor dirender dalam keadaan **disabled** (bukan disembunyikan) untuk grup — memakai pola tampil/sembunyi kondisional yang sudah ada di kode (`tombolKonfirmasiNomor`, `app/Views/inbox/index.php:1166`, saat ini untuk kasus `jid_type === 'lid' && !phone`), diperluas dengan cabang disabled baru untuk grup. Edit Profil sudah **tidak lagi tampil di header** (dipindah ke baris daftar kiri per komentar kode `index.php:1223`) — untuk grup, tombol Edit Profil di baris daftar tampil **disabled**; Hapus percakapan di baris yang sama **tetap berfungsi penuh** (Resolved Item #5, Clarification Report Spec).
- **CON-003**: Aksi yang tetap tersedia pada grup **tanpa perubahan**: kirim pesan teks/media biasa, Internal Note. (Balas Pesan dan Teruskan baru tersedia setelah Tahap 3/4 — sebelum itu, tombolnya memang belum ada sama sekali untuk siapa pun, bukan aturan khusus grup.)
- **CON-004**: Endpoint `Inbox::ambilPercakapan()`, `lepasPercakapan()`, `tutupPercakapan()`, `snoozePercakapan()`, `konfirmasiNomor()`, dan endpoint Edit Profil **tetap menolak** request untuk `conversation.jid_type === 'group'` di sisi server dengan `403`, sekalipun UI sudah menyembunyikan tombolnya — defense in depth (pola yang sama dipakai `cekOwnership()` untuk kasus lain).
- **GUD-001**: Perubahan filter `status` dan badge count **tidak boleh** menambah query baru ke database — keduanya beroperasi di atas `findAll()` yang sudah ada (filter-after-fetch, konsisten dengan pola CL-010 di M3 Fase 1e).

## 4. Interfaces & Data Contracts

### 4.1 `GET /inbox/api/conversations?status=grup`

Tidak ada perubahan bentuk response per-baris. Baris yang dikembalikan adalah subset `conversations` dengan `jid_type = 'group'`, memakai bentuk yang sama seperti status lain (termasuk `response_state`, `queue_status='grup'`, `sla_color`).

```
GET /inbox/api/conversations?status=grup
→ 200 { "conversations": [ { ..., "jid_type": "group", "queue_status": "grup", ... } ] }
```

`status=grup` **tidak bisa digabung** dengan status lain dalam satu request — sama seperti status lain saat ini (satu nilai `status` per request).

### 4.2 `GET /inbox/api/perlu-dibalas-count`

Tidak ada perubahan bentuk response (`{"count": N}`). Hanya nilai `N` yang berubah: turun sebesar jumlah percakapan grup yang sebelumnya ikut terhitung `perlu_dibalas`.

### 4.3 `ConversationModel::withComputedStatus()` — kontrak internal

| Kondisi | `response_state` (tidak berubah) | `queue_status` (baru) |
| --- | --- | --- |
| `jid_type === 'group'` | dihitung seperti biasa (tidak dipakai) | **`'grup'`** |
| `jid_type !== 'group'` | seperti sebelumnya | seperti sebelumnya |

## 5. Acceptance Criteria

- **AC-001**: Given tab **Grup** dibuka, When daftar dimuat, Then hanya percakapan dengan `jid_type = 'group'` yang tampil.
- **AC-002**: Given sebuah percakapan grup, When kasir membuka tab Belum Diambil/Open/Menunggu/Ditunda/Selesai, Then percakapan grup tersebut **tidak muncul** di tab manapun selain Grup.
- **AC-003**: Given N percakapan grup yang sebelumnya berkontribusi ke badge `perlu_dibalas`, When Tahap 1 dirilis, Then badge berkurang tepat N pada data yang sama (selisih terukur, bukan dua kali hitung).
- **AC-004**: Given percakapan grup dibuka, When header dirender, Then penanda "Grup" tampil terpisah dari judul percakapan, dan tombol Ambil/Lepas/Tutup/Snooze **tidak ada** di layar.
- **AC-005**: Given percakapan grup dibuka, Then tombol Konfirmasi Nomor (di header) dan Edit Profil (di baris daftar kiri) tampil dalam keadaan disabled (bukan hilang), dan tombol Hapus percakapan pada baris yang sama tetap berfungsi penuh.
- **AC-006**: Given request langsung ke `POST /inbox/percakapan/{id}/ambil` (atau lepas/tutup/snooze/konfirmasi-nomor/edit-profil) untuk `conversation_id` yang `jid_type='group'`, When endpoint dipanggil (melewati UI), Then server menolak dengan `403`, bukan memprosesnya.
- **AC-007**: Given percakapan pribadi (`jid_type` bukan `'group'`), When Tahap 1 dirilis, Then seluruh tombol dan perilaku tab yang ada **tidak berubah** dibanding sebelum perubahan (regresi nol).

## 6. Test Automation Strategy & Testing Seams

- **Testing Seams**: Seam tertinggi yang tersedia — HTTP boundary Controller (`Inbox::apiConversations()`, `Inbox::apiPerluDibalasCount()`) untuk logic filter/badge, dan unit test langsung ke `ConversationModel::withComputedStatus()` untuk logic `queue_status='grup'`. Tidak menambah seam baru.
- **Test Levels**: Unit (`ConversationModelTest` — kasus `jid_type='group'`), Feature/HTTP (`InboxTest` — filter `status=grup`, badge count, dan penolakan 403 pada endpoint aksi grup).
- **Test Data Management**: Tambahkan factory/seed conversation dengan `jid_type='group'` di test yang relevan (pola factory sudah ada di test suite, lihat `app/Commands/SeedFase1ePerf.php:180` untuk contoh nilai `jid_type`).
- **CI/CD Integration**: Tidak ada — proyek belum punya CI otomatis (per `docs/CHAT.md` konvensi test manual).
- **Coverage Requirements**: Definition of Done mengikuti kebijakan proyek: `vendor/bin/phpunit --no-coverage` keluar kode 0 (PRD Section 7.3) — bukan `composer test` (diketahui gagal karena driver coverage, tidak berkaitan dengan pekerjaan ini).

## 7. Project Structure & Commands

### Project Structure

- `app/Controllers/Inbox.php` — tambah `'grup'` ke `QUEUE_STATUSES`, ubah `apiConversations()` dan `apiPerluDibalasCount()`.
- `app/Models/ConversationModel.php` — ubah `withComputedStatus()`.
- `app/Views/inbox/index.php` — tambah tab Grup, badge penanda, sembunyikan/disable tombol pada grup.

### Commands

- **Test:** `vendor/bin/phpunit --no-coverage`
- **Test spesifik:** `vendor/bin/phpunit --no-coverage --filter InboxTest`
- **Dev:** server XAMPP lokal (`http://localhost/aulia/`), tidak ada dev server terpisah.

## 8. Code Style & Conventions

Ikuti pola PHPDoc panjang yang sudah dipakai di `Inbox.php` (komentar menjelaskan *mengapa*, bukan cuma *apa*), contoh nyata dari kode yang sudah ada:

```php
/**
 * Hitung Response State per conversation -- COMPUTED, tidak pernah
 * disimpan sebagai kolom fisik ...
 */
private function attachResponseState(array $conversations): array
{
    return (new ConversationModel())->withComputedStatus($conversations);
}
```

Penambahan `'grup'` ke `QUEUE_STATUSES` mengikuti gaya array konstanta yang sudah ada (baris 29), bukan enum/class baru.

## 9. Implementation Boundaries

- **Always do:** Jalankan `vendor/bin/phpunit --no-coverage` sebelum commit; ikuti nama variabel Bahasa Indonesia yang sudah dipakai di file yang sama (`$conversationModel`, `$queueStatus`, dst.); validasi `jid_type` grup di server (CON-004), jangan hanya di UI.
- **Ask first:** Menambah kolom baru ke `conversations`/`messages` (Tahap 1 seharusnya **tidak perlu** ini — kalau developer merasa perlu, itu sinyal salah paham dan wajib konfirmasi ke Architect/user dulu).
- **Never do:** Mengubah kolom `assigned_to`/`status`/lifecycle untuk grup; menyentuh file di repo WA-Gateway; menghapus/mengubah perilaku tab untuk percakapan pribadi.

## 10. Rationale, Context & Architecture Decisions (ADRs)

Tidak ada ADR baru. Keputusan di spec ini (memakai `jid_type` yang sudah ada, filter-after-fetch tanpa query baru) adalah penerapan langsung pola yang sudah berdiri di `docs/CHAT.md` §18 dan `spec-design-m3-operational-inbox-fase1.md`, bukan keputusan baru yang hard-to-reverse/surprising/bertukar untung-rugi (gagal *Triple Gate Validation*, `.agents/standards/ADR-FORMAT.md`).

## 11. Dependencies & External Integrations

### External Systems

- **EXT-001**: `tikusgot007/WA-Gateway` — tidak disentuh sama sekali di Tahap 1; hanya kontrak data yang **sudah** dikirim (`jid_type`) yang dipakai.

### Data Dependencies

- **DAT-001**: Kolom `conversations.jid_type` — sudah ada dan sudah terisi sejak awal (Tahap 1 tidak menambah data).

## 12. Examples & Edge Cases

```php
// ConversationModel::withComputedStatus() -- logic queue_status yang
// sudah ada (baris 230-240) SELALU menimpa $conversation['queue_status']
// di akhir loop. Assignment 'grup' HARUS ditaruh SETELAH blok if/elseif
// yang sudah ada (bukan di awal loop), atau ia akan langsung tertimpa.
foreach ($conversations as &$conversation) {
    // ... logic response_state/queue_status yang sudah ada, TIDAK diubah ...
    if (($conversation['jid_type'] ?? null) === 'group') {
        $conversation['queue_status'] = 'grup';
        // response_state tetap dihitung di atas untuk konsistensi bentuk data,
        // tapi TIDAK dipakai untuk keputusan tab/badge manapun.
    }
}
```

**Edge case**: percakapan yang `jid_type`-nya berubah dari `'pn'`/`'lid'` ke `'group'` di tengah jalan tidak mungkin terjadi secara alami (satu JID tidak pernah berpindah kategori) — tidak perlu penanganan migrasi state.

## 13. Validation Criteria

- `vendor/bin/phpunit --no-coverage` hijau 100%.
- Manual check: badge `perlu_dibalas` di sidebar berkurang tepat sejumlah percakapan grup yang ada di data uji.
- Manual check: tidak ada tombol Ambil/Lepas/Tutup/Snooze yang tampak pada percakapan grup di browser.

## 14. Related Specifications / Further Reading

- [`spec-index.md`](./spec-index.md)
- [`spec-design-grup-tahap2-identitas.md`](./spec-design-grup-tahap2-identitas.md) — lanjutan tahap ini
- `docs/CHAT.md` §11 (Lifecycle), §18 (Developer Invariants), §19 (Response State)
- `spec-design-m3-operational-inbox-fase1.md` — asal `queue_status`/`response_state`
