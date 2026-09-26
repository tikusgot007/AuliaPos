---
title: Grup — Tahap 1 (Tab Inbox, Penandaan, Aksi Dinonaktifkan, Badge)
version: 1.1
date_created: 2026-09-26
last_updated: 2026-09-26
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

**Instruksi STOP (wajib) — konsekuensi langsung ASSUMPTION-001.** Jika verifikasi langkah pertama menemukan nilai literal `jid_type` grup **berbeda** dari `'group'` (mis. beda kapitalisasi atau nilai lain), developer **wajib BERHENTI** — jangan lanjut menulis kode filter. Amandemen spec ini lebih dulu lewat `/sdlc-define-specs` (`REQ-001`, `REQ-002`, `REQ-003`, `REQ-007`, `REQ-008`, `CON-004`, `CON-005`, `CON-006`, dan contoh kode Section 12) supaya menyebut nilai literal yang benar, baru implementasi dilanjutkan. Ini keputusan sadar dari pemilik proyek: lebih baik menahan satu tahap daripada menulis filter yang menargetkan nilai yang salah.

- **CLARIFICATION NEEDED:** Tidak ada — seluruh keputusan Tahap 1 sudah dikunci di PRD dan Clarification Report (Snooze termasuk aksi yang dinonaktifkan). Enam resolusi dari `docs/audit/clarification-report-grup-tahap1-plan-2026-09-26.md` (instruksi STOP ASSUMPTION-001, badge/SLA/Tandai-Dibaca/Handoff tersembunyi, pengecualian Hapus dari syarat closed, pengecualian auto-assign, posisi tab Grup, entri `QUEUE_STATUS_LABEL`) sudah dituliskan pada **v1.1** sebagai `REQ-007`/`REQ-008`/`REQ-009`/`CON-005`/`CON-006` dan `AC-008`..`AC-012`. Satu keputusan tambahan dari pemilik proyek pada 2026-09-26 memperluas CON-006: badge kepemilikan dan badge lifecycle juga disembunyikan pada grup.

> [!NOTE]
> **Catatan revisi v1.1 (2026-09-26):** spec ini diamandemen dari v1.0 sebagai tindak lanjut wajib dari `docs/audit/clarification-report-grup-tahap1-plan-2026-09-26.md` (Readiness Score 82/100 → PROCEED). Semua perubahan bersifat **penulisan keputusan yang sudah dikunci pemilik proyek** menjadi Ref ID resmi; tidak ada keputusan arsitektur baru dan tidak ada ADR baru. Pewarisan Ref ID ini ke task plan dilakukan terpisah lewat `/sdlc-plan-tasks`.

## 2. Definitions

Mengikuti `CONTEXT.md`:

- **Grup**: Percakapan WhatsApp yang diikuti lebih dari dua pihak, tidak terikat satu nomor/pelanggan. `_Avoid_`: Group chat, Group, Grup WhatsApp.
- **queue_status**: Status turunan (dihitung, bukan disimpan) yang menentukan tab tempat sebuah percakapan pribadi muncul di daftar (`belum_diambil`/`open`/`menunggu`/`ditunda`/`selesai`), dihitung di `ConversationModel::withComputedStatus()`. Istilah ini sudah ada di `spec-design-m3-operational-inbox-fase1.md`, dipakai apa adanya di sini.
- **Percakapan Pribadi**: istilah kerja spec ini untuk percakapan dengan `jid_type` selain `'group'` (yaitu `'pn'`, `'lid'`, atau `'unknown'`) — untuk membedakan dari percakapan Grup. Tidak ada di `CONTEXT.md` karena bukan istilah bisnis baru, hanya negasi dari Grup.

## 3. Requirements, Constraints & Guidelines

- **REQ-001**: `GET /inbox/api/conversations` menerima nilai `status` baru: `'grup'` (ditambahkan ke `Inbox::QUEUE_STATUSES`, `app/Controllers/Inbox.php:29`). Filter ini mengembalikan seluruh percakapan dengan `jid_type = 'group'`, mengabaikan `response_state`/`queue_status` yang dihitung.
- **REQ-002**: Percakapan dengan `jid_type = 'group'` **tidak pernah** cocok dengan filter `status` manapun dari `Inbox::QUEUE_STATUSES` yang lama (`belum_diambil`, `open`, `menunggu`, `ditunda`, `selesai`) — terlepas dari nilai `response_state` hasil hitung `withComputedStatus()`.
- **REQ-003**: `ConversationModel::withComputedStatus()` menghitung `queue_status = 'grup'` untuk baris dengan `jid_type = 'group'`, dan assignment itu **wajib ditaruh SETELAH** blok `if/elseif/else` `response_state`/`queue_status` yang sudah ada di dalam loop (lihat contoh kode Section 12) — blok lama menimpa `queue_status` tanpa syarat, sehingga assignment yang ditaruh lebih awal akan langsung tertimpa (temuan kritis #1, `clarification-report-whatsapp-grup-balas-teruskan-spec-2026-09-26.md`). `response_state` untuk grup tetap dihitung apa adanya (tidak dipakai untuk keputusan tab, hanya supaya kontrak API tidak berubah bentuk untuk consumer lain).
- **REQ-004**: `GET /inbox/api/perlu-dibalas-count` (`Inbox::apiPerluDibalasCount()`, baris 316-333) mengecualikan percakapan `jid_type = 'group'` dari `count()`, terlepas dari `response_state` hasil hitungnya. Ini mensyaratkan menambah `jid_type` ke daftar kolom `select()` yang sudah ada (baris 327: `'status, last_message_direction, last_message_at, last_seen_by_assignee_at, snoozed_until'`) — tanpa kolom ini, filter grup di fungsi ini tidak mungkin berjalan.
- **REQ-005**: Baris percakapan grup di UI daftar (`app/Views/inbox/index.php`) menampilkan penanda teks **"Grup"** (badge), ditempatkan konsisten dengan badge `response_state` yang sudah ada (`RESPONSE_STATE_LABEL`, baris ~840).
- **REQ-006**: Header percakapan (saat grup dibuka) menampilkan penanda yang sama, terpisah dari judul percakapan — supaya tetap terbaca walau judul (Tahap 2) belum benar.
- **REQ-007**: `Inbox::hapusPercakapan()` (`app/Controllers/Inbox.php:1531`) **mengecualikan** percakapan `jid_type = 'group'` dari syarat `status === 'closed'` (`:1552`). Percakapan grup tidak pernah bisa mencapai `status='closed'` (tombol Tutup tidak dirender per CON-001, endpoint `tutupPercakapan()` menolak 403 per CON-004, dan tidak ada reopen manual), sehingga syarat itu membuat "Hapus" **selalu** gagal 409 — bertentangan langsung dengan janji CON-002. Gate admin-only (`:1545`) **tidak berubah**: kasir non-admin tetap ditolak 403.
- **REQ-008**: Auto-assign pada `Inbox::kirimMedia()` (`:1051-1053`) dan `Inbox::kirimKeConversation()` (`:2143-2145`) **dikecualikan** untuk `jid_type = 'group'`: saat kasir membalas grup, `assigned_to` **tidak boleh** diisi. Alasannya kebuntuan permanen — grup akan ter-assign ke satu kasir padahal `lepasPercakapan()` (CON-004) selalu menolak 403 untuk grup, jadi tidak ada jalan melepasnya; ini juga menjaga konsistensi Section 1.1 ("grup tidak menyentuh dimensi kepemilikan").
- **REQ-009**: Tab **Grup** diletakkan di **posisi paling akhir** pada baris tab (setelah `Selesai`), sehingga urutan menjadi `Belum Diambil, Open, Menunggu, Ditunda, Selesai, Grup` — 5 tab lama tidak bergeser. Menambah elemen tombol tab saja **tidak cukup**: entri `grup: 'Grup'` **wajib** ditambahkan ke objek JS `QUEUE_STATUS_LABEL` (`app/Views/inbox/index.php:790-796`) yang menjadi satu-satunya sumber kebenaran untuk highlight tombol aktif (`renderFilterButtons()`, `:798-804`) dan hitung badge angka tab (`renderTabCounts()`, `:806-818`). Tanpa entri ini, filter data tetap jalan tetapi tombol tab Grup tidak pernah menyala aktif dan badge angkanya tidak pernah ter-update.
- **CON-001**: Tombol Ambil, Lepas, Tutup, Snooze **tidak dirender sama sekali** pada percakapan grup — bukan dirender lalu di-`disabled`. Ini mencegah kasir menekan tombol yang pasti gagal (PRD Section 4, "tidak boleh tampil sebagai tombol yang selalu gagal").
- **CON-002**: Konfirmasi Nomor dirender dalam keadaan **disabled** (bukan disembunyikan) untuk grup — memakai pola tampil/sembunyi kondisional yang sudah ada di kode (`tombolKonfirmasiNomor`, `app/Views/inbox/index.php:1166`, saat ini untuk kasus `jid_type === 'lid' && !phone`), diperluas dengan cabang disabled baru untuk grup. Edit Profil sudah **tidak lagi tampil di header** (dipindah ke baris daftar kiri per komentar kode `index.php:1223`) — untuk grup, tombol Edit Profil di baris daftar tampil **disabled**; Hapus percakapan di baris yang sama **tetap berfungsi penuh** (Resolved Item #5, Clarification Report Spec).
- **CON-003**: Aksi yang tetap tersedia pada grup **tanpa perubahan**: kirim pesan teks/media biasa, Internal Note. (Balas Pesan dan Teruskan baru tersedia setelah Tahap 3/4 — sebelum itu, tombolnya memang belum ada sama sekali untuk siapa pun, bukan aturan khusus grup.)
- **CON-004**: Endpoint `Inbox::ambilPercakapan()`, `lepasPercakapan()`, `tutupPercakapan()`, `snoozePercakapan()`, `konfirmasiNomor()`, dan endpoint Edit Profil **tetap menolak** request untuk `conversation.jid_type === 'group'` di sisi server dengan `403`, sekalipun UI sudah menyembunyikan tombolnya — defense in depth (pola yang sama dipakai `cekOwnership()` untuk kasus lain).
- **CON-005**: Informasi turunan yang tidak bermakna untuk grup **tidak dirender sama sekali** pada baris daftar maupun header percakapan grup — pola yang sama dengan CON-001 (tidak dirender, bukan disabled):
  - Badge `response_state` pada baris daftar (`index.php:932-933`).
  - Titik warna SLA pada baris daftar (`renderTitikSla()`, `:877-883`, dipanggil di `:947`).
  - Tombol **Tandai Dibaca** pada header (`:1186-1189`) — aksinya (`tandaiDibacaAktif()`) juga tidak boleh ditawarkan.
  - Tombol **Handoff** pada header (`:1197-1204`).
  Ini menggabungkan Resolved Item #2 dan #3 dari `clarification-report-whatsapp-grup-balas-teruskan-spec-2026-09-26.md` ke dalam Tahap 1 (kesepakatan itu sebelumnya belum masuk ke spec/plan Tahap 1).
- **CON-006**: Badge **kepemilikan** dan badge **lifecycle** juga **tidak dirender** untuk grup, konsisten dengan Section 1.1 ("grup tidak menyentuh dimensi kepemilikan/`status`"):
  - Badge kepemilikan pada baris daftar (`"Belum diambil"` / `"Dipegang: X"`, `index.php:937-940`) dan pada header (`:1149-1157`).
  - Badge lifecycle `OPEN`/`CLOSED` pada header (`badgeStatus`, `:1175-1177`, dirender di `:1228`), dan badge `closed` pada baris daftar (`:931`).
  Penanda **"Grup"** (REQ-005/REQ-006) tetap tampil menggantikan posisi informasi tersebut, supaya baris grup tetap punya identitas visual tanpa menampilkan status yang tidak berlaku.
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
- **AC-008**: Given percakapan grup tampil di baris daftar dan dibuka di header, When UI dirender, Then badge `response_state`, titik SLA, tombol Tandai Dibaca, tombol Handoff, badge kepemilikan, dan badge lifecycle **tidak ada** di layar — tanpa memengaruhi tombol/penanda lain yang tetap berlaku.
- **AC-009**: Given percakapan grup dengan `status` **bukan** `'closed'`, When request `POST /inbox/percakapan/{id}/hapus` dikirim oleh **admin**, Then server memproses dan mengembalikan `200` (soft delete) — bukan `409`. Given request yang sama dikirim oleh **kasir non-admin**, Then server tetap menolak `403` (gate admin-only tidak berubah).
- **AC-010**: Given percakapan grup yang belum punya `assigned_to`, When kasir mengirim balasan teks (`kirimKeConversation()`) atau media (`kirimMedia()`), Then pesan tetap terkirim normal **dan** `assigned_to` percakapan itu tetap kosong (tidak terisi otomatis).
- **AC-011**: Given halaman Inbox dimuat, When baris tab dirender, Then tab **Grup** berada di posisi paling akhir (setelah Selesai) dan objek `QUEUE_STATUS_LABEL` memuat entri `grup`; saat tab Grup dipilih, tombol itu menyala `active` dan badge angka tab-nya ter-update.
- **AC-012**: Given verifikasi langkah pertama menemukan nilai literal `jid_type` grup **berbeda** dari `'group'`, Then implementasi **berhenti** dan spec diamandemen lebih dulu lewat `/sdlc-define-specs` (lihat Section 1.2) — tidak ada kode filter yang ditulis terhadap nilai yang belum terkonfirmasi.

## 6. Test Automation Strategy & Testing Seams

- **Testing Seams**: Seam tertinggi yang tersedia — HTTP boundary Controller (`Inbox::apiConversations()`, `Inbox::apiPerluDibalasCount()`) untuk logic filter/badge, dan unit test langsung ke `ConversationModel::withComputedStatus()` untuk logic `queue_status='grup'`. Tidak menambah seam baru.
- **Test Levels**: Unit (`ConversationModelTest` — kasus `jid_type='group'` → `queue_status='grup'`), Feature/HTTP (`InboxTest`):
  - Filter `status=grup` mengembalikan hanya grup; grup tidak muncul di 5 status lama (AC-001, AC-002).
  - Badge `perlu_dibalas` mengecualikan grup (AC-003).
  - Penolakan `403` pada endpoint aksi grup (AC-006).
  - **`hapusPercakapan()` grup oleh admin → `200`** walau `status` bukan `closed`, dan `403` untuk kasir non-admin (AC-009) — ini test otomatis, bukan sekadar manual check.
  - **Auto-assign tidak mengisi `assigned_to`** pada `kirimKeConversation()` dan `kirimMedia()` untuk grup (AC-010).
  - **Pembagian kelas uji:** item yang mengubah kondisi endpoint (Hapus, auto-assign) **wajib** test otomatis karena bisa diassert programatis; item yang murni tampilan visual (badge/tombol tersembunyi per CON-005/CON-006, penanda "Grup") diverifikasi lewat **manual check browser**, sama seperti pola CON-001 empat tombol lainnya.
- **Test Data Management**: Tambahkan factory/seed conversation dengan `jid_type='group'` di test yang relevan (pola factory sudah ada di test suite, lihat `app/Commands/SeedFase1ePerf.php:180` untuk contoh nilai `jid_type`).
- **CI/CD Integration**: Tidak ada — proyek belum punya CI otomatis (per `docs/CHAT.md` konvensi test manual).
- **Coverage Requirements**: Definition of Done mengikuti kebijakan proyek: `vendor/bin/phpunit --no-coverage` keluar kode 0 (PRD Section 7.3) — bukan `composer test` (diketahui gagal karena driver coverage, tidak berkaitan dengan pekerjaan ini).

## 7. Project Structure & Commands

### Project Structure

- `app/Controllers/Inbox.php` — tambah `'grup'` ke `QUEUE_STATUSES`, ubah `apiConversations()`, `apiPerluDibalasCount()`, kumpulan endpoint aksi (403 grup), `hapusPercakapan()` (REQ-007), serta `kirimKeConversation()`/`kirimMedia()` (REQ-008).
- `app/Models/ConversationModel.php` — ubah `withComputedStatus()`.
- `app/Views/inbox/index.php` — tambah tab Grup (paling akhir) + entri `QUEUE_STATUS_LABEL['grup']`, badge penanda "Grup", sembunyikan/disable tombol & badge pada grup (CON-001, CON-002, CON-005, CON-006).

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

- **Always do:** Jalankan `vendor/bin/phpunit --no-coverage` sebelum commit; ikuti nama variabel Bahasa Indonesia yang sudah dipakai di file yang sama (`$conversationModel`, `$queueStatus`, dst.); validasi `jid_type` grup di server (CON-004), jangan hanya di UI; tambahkan test otomatis untuk jalur Hapus grup (AC-009) dan auto-assign grup (AC-010).
- **Ask first:** Menambah kolom baru ke `conversations`/`messages` (Tahap 1 seharusnya **tidak perlu** ini — kalau developer merasa perlu, itu sinyal salah paham dan wajib konfirmasi ke Architect/user dulu).
- **Never do:** Membiarkan `assigned_to` terisi otomatis untuk grup lewat jalur kirim (REQ-008); mensyaratkan `status='closed'` untuk menghapus grup (REQ-007); mengubah kolom `assigned_to`/`status`/lifecycle untuk grup; menyentuh file di repo WA-Gateway; menghapus/mengubah perilaku tab untuk percakapan pribadi.

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

```php
// REQ-007 -- hapusPercakapan(): grup dikecualikan dari syarat "harus closed".
// Gate admin-only (baris 1545) TETAP di atas dan tidak berubah.
if ($conversation['jid_type'] !== 'group' && $conversation['status'] !== 'closed') {
    return $this->response->setStatusCode(409)->setJSON([
        'status'  => 'error',
        'message' => 'Percakapan harus ditutup (Selesai) dulu sebelum bisa dihapus.',
    ]);
}
```

```php
// REQ-008 -- auto-assign: grup TIDAK pernah mengisi assigned_to,
// baik di kirimKeConversation() maupun kirimMedia() (dua titik yang
// sama-sama punya blok ini).
if (($conversation['jid_type'] ?? null) !== 'group' && empty($conversation['assigned_to'])) {
    $conversationUpdate['assigned_to'] = $userId;
}
```

**Edge case**: bila grup warisan sudah terlanjur punya `assigned_to` terisi (data lama, sebelum Tahap 1), REQ-008 hanya mencegah **penulisan baru** — nilai lama **tidak dibersihkan otomatis**, konsisten dengan keputusan "data grup lama dibiarkan" dan CON-006 (badge kepemilikannya tidak dirender, jadi nilainya tidak terlihat kasir).

## 13. Validation Criteria

- `vendor/bin/phpunit --no-coverage` hijau 100%.
- Test otomatis: hapus grup oleh admin → `200` meski `status` bukan `closed`; hapus oleh kasir non-admin → `403`; balas grup tidak mengisi `assigned_to`.
- Manual check: badge `perlu_dibalas` di sidebar berkurang tepat sejumlah percakapan grup yang ada di data uji.
- Manual check: tidak ada tombol Ambil/Lepas/Tutup/Snooze yang tampak pada percakapan grup di browser.
- Manual check: pada grup, badge `response_state`, titik SLA, Tandai Dibaca, Handoff, badge kepemilikan, dan badge lifecycle (OPEN/CLOSED) tidak tampak; tab Grup berada di posisi paling akhir dan menyala aktif saat dipilih.

## 14. Related Specifications / Further Reading

- [`spec-index.md`](./spec-index.md)
- [`spec-design-grup-tahap2-identitas.md`](./spec-design-grup-tahap2-identitas.md) — lanjutan tahap ini
- `docs/CHAT.md` §11 (Lifecycle), §18 (Developer Invariants), §19 (Response State)
- `spec-design-m3-operational-inbox-fase1.md` — asal `queue_status`/`response_state`
