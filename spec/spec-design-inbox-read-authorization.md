---
title: Inbox — Aturan Akses Baca/Tulis Percakapan (ALT-003 / AUTHZ-02)
version: 1.1
date_created: 2026-09-27
last_updated: 2026-09-27
owner: AuliaPos Inbox module
tags: [inbox, chat, whatsapp, authz, security, alt-003, authz-02]
---

# Introduction

Spec ini menetapkan **aturan resmi** siapa yang boleh **membaca** dan siapa yang boleh **mengubah** data percakapan pada modul Inbox WhatsApp AuliaPos. Spec ini menjawab temuan audit `ALT-003`/`AUTHZ-02` (`docs/audit/consistency-audit-balas-pesan-tahap3-review3-2026-09-27.md:53`) dan menutupnya sebagai **keputusan yang disengaja**, bukan celah yang harus ditambal dengan guard kepemilikan.

Aturan yang diputuskan pemilik proyek pada sesi ini:

1. **Semua staff yang login boleh melihat semua percakapan** — daftar, isi pesan, dan lampiran media — tanpa dibatasi `assigned_to`.
2. **Semua staff yang login boleh menulis Internal Note** ke percakapan mana pun.
3. **Operasi tulis** (balas, kirim media, hapus, tutup, snooze, handoff, tandai dibaca, ubah profil, konfirmasi nomor, ambil/lepas) **hanya untuk pemegang atau admin**.

Tujuan aturan: setiap staff dapat menilai sendiri apakah sebuah percakapan **sudah dipegang atau belum**, dan **siapa yang memegangnya**, sambil tetap menjaga agar hanya pemegang yang mengubah keadaan percakapan.

> [!NOTE]
> **Catatan revisi v1.1 (2026-09-27):** spec ini diamandemen dari v1.0 sebagai tindak lanjut `docs/audit/clarification-report-inbox-read-authorization-2026-09-27.md` (Readiness Score 92/100, PROCEED) — menerapkan dua resolusi (C-1, C-2), **tanpa mengubah requirement lain**:
>
> 1. **C-1 (opsi A):** `REQ-002` dan Section 7/8 diperjelas — pembukaan guard **hanya** menghapus blok `cekOwnership()` (`app/Controllers/Inbox.php:425-431`). Pemuatan percakapan (`:416-423`) dan `404`-nya **dipertahankan**, bukan dihapus, supaya `AC-007` ("pesan atau percakapan tidak ada → `404`") tetap benar. Section 8 contoh kode diperbarui menampilkan blok `404` percakapan.
> 2. **C-2 (opsi A):** `REQ-002`/Section 2 kini menyatakan eksplisit bahwa penulisan penanda `media_confirmed_gone_at` pada jalur `410` (`app/Controllers/Inbox.php:531-535`) **boleh dipicu staff mana pun yang login**, bukan hanya pemegang — penanda ini mencatat fakta objektif (media benar-benar sudah tidak tersedia di WhatsApp) dan hanya mencegah panggilan Gateway berulang, bukan mengubah kepemilikan/state percakapan. `AC-008`/Section 6/13 menambah test bahwa jalur `410` tidak diblokir untuk non-pemegang.
>
> Tidak ada keputusan arsitektur baru, tidak ada ADR baru (Triple Gate tetap gagal), `CONTEXT.md` tidak berubah.

## 1. Purpose & Scope

Spec ini mencakup, dan hanya mencakup:

- Penegasan **aturan baca terbuka** untuk seluruh endpoint baca Inbox (daftar, thread, media, riwayat Handoff, status Gateway, jumlah perlu-dibalas).
- Penegasan **aturan tulis terbatas pemegang** untuk seluruh endpoint yang mengubah keadaan percakapan.
- **Perubahan perilaku satu endpoint:** penjagaan kepemilikan pada `GET /inbox/media/(:num)` dibuka (lihat REQ-002).
- Penutupan temuan `ALT-003`/`AUTHZ-02` sebagai keputusan resmi (lihat SEC-001), dengan menegaskan `GET /inbox/api/conversations/(:num)/messages` **tetap** `auth`-only.

Audiens: developer yang akan menjalankan `/sdlc-clarify-reqs` → `/sdlc-plan-tasks` → `/sdlc-write-code` untuk pekerjaan ALT-003, serta agent audit berikutnya.

### 1.1 Out of Scope

- Mengubah isi/aturan internal `cekOwnership()` (`app/Controllers/Inbox.php:761`) — fungsi itu **dipakai apa adanya**; yang diatur di sini hanya **endpoint mana** yang boleh memakainya.
- Menambah endpoint, kolom, tabel, atau migrasi baru.
- Mengubah aturan grup dari Grup Tahap 1 (`spec-design-grup-tahap1-tab-inbox.md` CON-004): baca grup tetap terbuka, aksi tulis grup tetap `403`.
- Mengubah kebijakan Internal Note (`spec-design-m3-operational-inbox-fase1.md` SEC-001).
- Mengubah autentikasi machine-to-machine Gateway (`gatewaytoken`).
- Presence, Collision Detection, dan auto-assignment (M3 Fase 2a).
- Otorisasi endpoint di luar modul Inbox.

### 1.2 Open Questions & Assumptions

- **ASSUMPTION-001:** seluruh staff yang login saling dipercaya (model **shared inbox** toko kecil); tidak ada kebutuhan privasi antar-staff di level percakapan. Bila kelak ada percakapan yang harus privat terhadap staff lain, spec ini **wajib** ditinjau ulang.
- **ASSUMPTION-002:** guard kepemilikan media `SEC-002`/`TASK-103` (`plan-refactor-balas-pesan-tahap3-review2-v1.0.md:50`) **dibatalkan** karena premisnya ("pemanggil yang tidak berhak atas percakapan pemiliknya") tidak lagi berlaku di bawah aturan baca terbuka.
- **CLARIFICATION NEEDED:** tidak ada. Kedua keputusan di atas sudah dikonfirmasi pemilik proyek (2026-09-27): media ikut dibuka seperti teks.
- **Catatan cakupan:** dari seluruh endpoint baca, **hanya `media()`** yang saat ini membatasi; endpoint baca lain sudah terbuka. Karena itu satu-satunya perubahan kode di spec ini adalah pembukaan `media()`.

## 2. Definitions

Mengikuti `CONTEXT.md`:

- **Kepemilikan Percakapan** — dimensi `conversations.assigned_to`; di dokumen ini dipakai untuk membedakan aksi **baca** (tidak dibatasi) dari aksi **tulis** (dibatasi).
- **Belum Diambil** — percakapan yang menunggu balasan dan belum dipegang staff mana pun (`assigned_to IS NULL`, response state `perlu_dibalas`).
- **Tanpa Pemilik** — percakapan yang tidak dipegang siapa pun, tanpa syarat sedang menunggu balasan.
- **Grup** — percakapan WhatsApp lebih dari dua pihak; tidak terikat satu nomor/pelanggan.

Istilah kerja tambahan (bukan istilah bisnis baru):

- **Pemegang (Holder)** — staff yang tercatat di `conversations.assigned_to`. Dipakai bergantian dengan "dipegang". `_Avoid_`: owner, pemilik chat, penanggung jawab.
- **Akses Baca** — endpoint HTTP `GET` yang hanya mengembalikan data dan tidak mengubah state apa pun.
- **Operasi Tulis** — endpoint yang mengubah state tersimpan, mengirim ke Gateway, atau menulis baris `messages`/`conversations`.
- **Penanda Objektif (pengecualian Operasi Tulis)** — satu kekecualian pada definisi Operasi Tulis di atas: penulisan `messages.media_confirmed_gone_at` pada jalur `410` di dalam `Inbox::media()` (`app/Controllers/Inbox.php:531-535`) **tidak** dianggap Operasi Tulis yang butuh kepemilikan, karena hanya mencatat fakta objektif bahwa WhatsApp memang sudah tidak menyediakan media itu lagi (bukan perubahan state percakapan apa pun) dan semata mencegah panggilan Gateway berulang untuk request baca berikutnya. Lihat `REQ-002` (C-2).

## 3. Requirements, Constraints & Guidelines

- **REQ-001 (baca terbuka):** Seluruh staff yang login (role `admin` maupun `kasir`) **boleh membaca seluruh percakapan tanpa dibatasi `assigned_to`** — daftar percakapan, thread pesan, lampiran media, riwayat Handoff, status Gateway, dan jumlah perlu-dibalas. Ini menegaskan **CL-004** (`spec-design-m3-operational-inbox-fase1.md:86`) dan memperluasnya secara eksplisit ke endpoint thread dan media.
- **REQ-002 (media ikut dibuka):** `GET /inbox/media/(:num)` **wajib menyajikan** media kepada setiap staff yang login, tanpa memandang kepemilikan percakapan pemilik media. Guard `cekOwnership()` di `Inbox::media()` (`app/Controllers/Inbox.php:425-431`) **dihapus**; pemuatan percakapan (`:416-423`) **dipertahankan** karena masih dipakai untuk memastikan `404` saat percakapan pemilik pesan tidak ada (lihat C-1, `docs/audit/clarification-report-inbox-read-authorization-2026-09-27.md`). Pemeriksaan `404` untuk pesan/percakapan yang tidak ditemukan **tetap**, tidak berubah menjadi `403`. REQ ini **menggantikan** `SEC-002`/`TASK-103` (`plan-refactor-balas-pesan-tahap3-review2-v1.0.md:50`).
  - **Pengecualian tulis (C-2):** penulisan penanda `media_confirmed_gone_at` pada jalur `410` (`:531-535`) **boleh dipicu staff mana pun yang login**, bukan hanya pemegang percakapan — lihat definisi "Penanda Objektif" di Section 2. Ini bukan pelanggaran `REQ-003`; ini satu-satunya pengecualian tulis pada endpoint `media()` dan tidak berlaku untuk endpoint tulis lain.
- **REQ-003 (tulis terbatas pemegang):** Seluruh operasi tulis yang mengubah keadaan percakapan **tetap** dibatasi pemegang (`assigned_to` = user yang login) atau admin, lewat `cekOwnership()` — pola yang sudah ada, **tidak diubah**. Matriks lengkap di Section 4.
- **REQ-004 (Internal Note terbuka):** Penulisan Internal Note (`POST /inbox/percakapan/(:num)/catatan`, `Inbox::catatanInternal()` `app/Controllers/Inbox.php:1217`) **tetap** boleh dilakukan semua staff, tanpa `cekOwnership()` — konsisten `SEC-001` M3 Fase 1 dan pernyataan pemilik proyek ("semua bisa tulis internal note").
- **REQ-005 (indikator kepemilikan terlihat):** Untuk percakapan pribadi, indikator kepemilikan pada daftar (`"Belum diambil"` / `"Dipegang: X"`) dan header **tetap tampil** kepada semua pembaca, supaya setiap staff tahu status pegangan dan siapa pemegangnya. Untuk Grup, indikator ini **tetap disembunyikan** (`spec-design-grup-tahap1-tab-inbox.md` CON-006).
- **SEC-001 (ALT-003 ditutup — thread tetap terbuka):** `GET /inbox/api/conversations/(:num)/messages` (`Inbox::apiMessages()` `app/Controllers/Inbox.php:303`) **DILARANG** menambahkan `cekOwnership()` atau gerbang kepemilikan apa pun; endpoint tetap berfilter `auth` saja (`app/Config/Routes.php:40`). Perilaku ini **disengaja**, bukan celah. Alasannya: (a) daftar percakapan sudah terbuka (REQ-001), sehingga gerbang pada thread akan membuat percakapan tampil di daftar tetapi tidak bisa dibuka; (b) Internal Note boleh ditulis siapa pun (REQ-004), sehingga staff bisa menulis catatan ke percakapan yang tak bisa ia baca — kontradiksi. Temuan `ALT-003`/`AUTHZ-02` **ditutup** sebagai keputusan yang terdokumentasi.
- **CON-001:** Tidak ada perubahan skema/migrasi/kolom. Pekerjaan ini murni perizinan endpoint.
- **CON-002:** Aturan grup tidak berubah: baca grup terbuka untuk semua staff (lihat juga `prd-20260926-0024-whatsapp-grup-balas-teruskan.md:105`); aksi tulis grup tetap `403` lewat `cekBukanGrup()` (`spec-design-grup-tahap1-tab-inbox.md` CON-004).
- **CON-003:** Filter `auth` pada seluruh route Inbox (`app/Config/Routes.php:38-62`) **wajib tetap ada** — aturan baca terbuka berlaku untuk **staff yang sudah login**, bukan untuk publik.
- **GUD-001:** Tidak ada endpoint baru. Aturan kepemilikan tulis **tetap satu sumber** (`cekOwnership()`); dilarang menyalin/menduplikasi logika itu di tempat lain.

## 4. Interfaces & Data Contracts

Tidak ada perubahan bentuk request/response. Yang ditetapkan adalah **gerbang** setiap endpoint.

| Endpoint | Metode | Jenis | Gerbang |
| --- | --- | --- | --- |
| `/inbox` (`index`) | GET | Baca | `auth` |
| `/inbox/api/conversations` | GET | Baca | `auth` |
| `/inbox/api/conversations/(:num)/messages` | GET | Baca | `auth` — **tanpa guard kepemilikan** (SEC-001) |
| `/inbox/media/(:num)` | GET | Baca | `auth` — **guard kepemilikan dihapus** (REQ-002); jalur `410` menulis `media_confirmed_gone_at` untuk staff mana pun (C-2) |
| `/inbox/api/gateway-status` | GET | Baca | `auth` |
| `/inbox/api/perlu-dibalas-count` | GET | Baca | `auth` |
| `/inbox/percakapan/(:num)/handoff` | GET | Baca | `auth` |
| `/inbox/kirim` | POST | Tulis | `cekOwnership()` + grup `403` |
| `/inbox/kirim-media` | POST | Tulis | `cekOwnership()` + grup `403` |
| `/inbox/percakapan/(:num)/hapus` | POST | Tulis | admin + `cekOwnership()` |
| `/inbox/percakapan/(:num)/ambil` | POST | Tulis | Klaim: kasir non-admin hanya bila belum dipegang; admin boleh override |
| `/inbox/percakapan/(:num)/lepas` | POST | Tulis | `cekOwnership()` + grup `403` |
| `/inbox/percakapan/(:num)/tutup` | POST | Tulis | `cekOwnership()` + grup `403` |
| `/inbox/percakapan/(:num)/snooze` | POST | Tulis | `cekOwnership()` + grup `403` |
| `/inbox/percakapan/(:num)/tandai-dibaca` | POST | Tulis | `cekOwnership()` + grup `403` |
| `/inbox/percakapan/(:num)/profil` | POST | Tulis | `cekOwnership()` + grup `403` |
| `/inbox/percakapan/(:num)/konfirmasi-nomor` | POST | Tulis | `cekOwnership()` + grup `403` |
| `/inbox/percakapan/(:num)/handoff` | POST | Tulis | Inisiator = pemegang, atau kasir aktif pada `belum_diambil` + grup `403` |
| `/inbox/percakapan/(:num)/catatan` | POST | Tulis | `auth` — **pengecualian sengaja** (REQ-004) |
| `/inbox/mulai-percakapan` | POST | Tulis | `cekOwnership()` via `kirimKeConversation()` |

Bentuk balasan saat ditolak pada operasi tulis **tidak berubah**: `403` dengan `{"status":"error","message": ...}` dari `cekOwnership()`; grup `403` dari `cekBukanGrup()`.

## 5. Acceptance Criteria

- **AC-001:** Given percakapan pribadi dipegang kasir A, When kasir B (kasir, bukan admin) membuka thread `GET /inbox/api/conversations/{id}/messages`, Then server membalas `200` berisi pesan percakapan itu — bukan `403`.
- **AC-002:** Given percakapan pribadi dipegang kasir A dengan lampiran gambar, When kasir B membuka `GET /inbox/media/{messageId}` untuk lampiran itu, Then server **menyajikan** media (`200`) — bukan `403`; dan tidak ada byte media yang berubah/tertulis ulang.
- **AC-003:** Given percakapan pribadi dipegang kasir A, When kasir B memanggil operasi tulis (kirim, kirim-media, tutup, snooze, tandai-dibaca, handoff, profil, konfirmasi-nomor, lepas), Then server menolak `403`; When kasir A (pemegang) atau admin memanggil aksi yang sama, Then aksi berjalan seperti sebelumnya.
- **AC-004:** Given percakapan pribadi dipegang kasir A, When kasir B menulis Internal Note (`POST .../catatan`), Then server membalas `200` dan catatan tersimpan — tanpa `cekOwnership()`.
- **AC-005:** Given percakapan pribadi (belum dipegang) muncul di daftar, When daftar dan header dirender untuk staff mana pun, Then indikator `"Belum diambil"` tampil; setelah dipegang kasir A, indikator berubah menjadi `"Dipegang: A"` dan tetap terlihat oleh semua staff.
- **AC-006:** Given percakapan Grup, When staff mana pun membuka thread atau medianya, Then server membalas `200` (baca terbuka); When staff memanggil aksi tulis grup (ambil/lepas/tutup/snooze/tandai-dibaca/profil/konfirmasi-nomor/handoff), Then server menolak `403` seperti sebelumnya (CON-002).
- **AC-007:** Given pesan atau percakapan tidak ada, When `GET /inbox/media/{id}` dipanggil, Then server tetap membalas `404` — `404` tidak berubah dan tidak boleh menjadi `403`.
- **AC-008:** Given perubahan ini dirilis, When seluruh suite dijalankan, Then `vendor/bin/phpunit --no-coverage` keluar kode 0 dengan test media yang diperbarui (kasus non-pemegang kini `200`), tanpa regresi pada test gerbang tulis.
- **AC-009 (C-2):** Given percakapan pribadi dipegang kasir A dengan lampiran media yang sudah kedaluwarsa di WhatsApp (respons Gateway `410`), When kasir B (bukan pemegang, bukan admin) memanggil `GET /inbox/media/{messageId}` untuk pertama kali, Then server tetap memproses request sampai memanggil Gateway, membalas `410`, dan menulis `media_confirmed_gone_at` pada baris pesan itu — **tidak** ditolak `403` di awal karena bukan pemegang.

## 6. Test Automation Strategy & Testing Seams

- **Testing Seams**: Seam tertinggi yang sudah ada — HTTP boundary controller (`Inbox::media()`, `Inbox::apiMessages()`) dan feature/session route (`tests/session/`). Tidak menambah seam baru.
- **Test Levels**:
  - **Feature/HTTP (`tests/session/InboxMediaAuthTest.php`)** — **diperbarui**: kasus `testMediaPercakapanMilikKasirLainDitolak403TanpaHubungiGateway` berubah menjadi "kasir lain **boleh** membaca media percakapan kasir lain" (`200`, isi file keluar). Kasus pemegang, percakapan tanpa pemilik, dan admin tetap dipertahankan sebagai regresi.
  - **Feature/HTTP (`tests/session/InboxMediaAuthTest.php`)** — **tambah** (AC-009/C-2): kasir non-pemegang, non-admin memanggil `GET /inbox/media/{id}` untuk media yang memicu `410` dari Gateway → server tetap membalas `410` (bukan `403` di awal) **dan** menulis `media_confirmed_gone_at` pada baris pesan itu, membuktikan penanda objektif tidak diblokir gerbang kepemilikan.
  - **Feature/HTTP (`tests/session/`)** — tambah/tegaskan test bahwa `GET /inbox/api/conversations/(:num)/messages` untuk percakapan milik kasir lain membalas `200` (AC-001), sebagai jangkar anti-regresi agar guard kepemilikan tidak pernah ditambahkan kembali ke endpoint itu.
  - **Feature/HTTP (`tests/session/`)** — pertahankan test gerbang tulis yang sudah ada (kirim/hapus/tutup/snooze/tandai-dibaca/handoff/profil/konfirmasi-nomor) sebagai bukti REQ-003 tidak tergeser.
- **Test Data Management**: Pola seed yang sudah ada di `InboxMediaAuthTest` (`seedConversation()`, `seedImageMessage()`) dipakai apa adanya; tambahkan variasi `assigned_to` (kasir lain, NULL, admin).
- **CI/CD Integration**: Tidak ada CI otomatis; suite dijalankan manual lewat PHPUnit.
- **Coverage Requirements**: `vendor/bin/phpunit --no-coverage` keluar kode 0.

## 7. Project Structure & Commands

### Project Structure

- `app/Controllers/Inbox.php` — **hanya** `media()` (`:396`): hapus **hanya** blok `cekOwnership()` (`:425-431`). Pemuatan percakapan (`:416-423`) **dipertahankan** (bukan dihapus) karena baris ini yang menghasilkan `404` saat percakapan pemilik pesan tidak ada — tanpanya AC-007 akan gagal (media tanpa percakapan akan lolos ke jalur disk/Gateway alih-alih `404`). `apiMessages()` (`:303`) **tidak disentuh** (SEC-001).
- `tests/session/InboxMediaAuthTest.php` — perbarui ekspektasi kasus non-pemegang menjadi `200`; pertahankan kasus `404` dan kasus pemegang/admin; tambah kasus `410` non-pemegang (AC-009/C-2).
- `docs/ARCHITECTURE.md` — perbarui catatan otorisasi endpoint Inbox (Living Architecture Map mandate) saat implementasi/review selesai.

### Commands

- **Test:** `vendor/bin/phpunit --no-coverage`
- **Test spesifik:** `vendor/bin/phpunit --no-coverage --filter InboxMediaAuthTest`
- **Dev:** server XAMPP lokal (`http://localhost/aulia/`), tidak ada dev server terpisah.

## 8. Code Style & Conventions

Perubahan mengikuti gaya guard clause yang sudah ada di `Inbox.php`. Contoh yang mengunci keputusan ini (C-1) — `media()` **mempertahankan** kedua blok `404` (pesan, lalu percakapan) apa adanya, **hanya** blok `cekOwnership()` di antara keduanya yang dihapus:

```php
public function media($messageId = null)
{
    $messageId = (int) $messageId;

    $messageModel = new MessageModel();
    $message = $messageModel->find($messageId);

    if (!$message) {
        return $this->response->setStatusCode(404)->setJSON([
            'status'  => 'error',
            'message' => 'Pesan tidak ditemukan.',
        ]);
    }

    // C-1: pemuatan percakapan DIPERTAHANKAN -- bukan sisa kode mati --
    // karena inilah yang menghasilkan 404 saat percakapan pemilik pesan
    // tidak ada (AC-007). Hanya blok cekOwnership() di bawahnya yang
    // dihapus.
    $conversation = (new ConversationModel())->find((int) $message['conversation_id']);

    if (!$conversation) {
        return $this->response->setStatusCode(404)->setJSON([
            'status'  => 'error',
            'message' => 'Pesan tidak ditemukan.',
        ]);
    }

    // REQ-002: baca terbuka -- TIDAK ada cekOwnership() di sini.
    // Semua staff yang sudah login (filter auth) boleh membaca media
    // percakapan mana pun; kepemilikan hanya membatasi operasi tulis.
    // ...

    // C-2: jalur 410 di bawah (media_confirmed_gone_at) boleh ditulis
    // staff mana pun -- penanda objektif, bukan Operasi Tulis kepemilikan.
}
```

Untuk endpoint tulis, pola guard **tidak berubah** (contoh acuan: `Inbox::kirimMedia()` `app/Controllers/Inbox.php:1018-1024`).

## 9. Implementation Boundaries

- **Always do:** Pertahankan `404` untuk pesan/percakapan tidak ditemukan pada `media()`; pertahankan filter `auth` di semua route; jalankan `vendor/bin/phpunit --no-coverage` sebelum commit; pertahankan perilaku gerbang tulis apa adanya.
- **Ask first:** Menyentuh logika `cekOwnership()`/`cekBukanGrup()`; mengubah gerbang tulis endpoint mana pun; mengubah aturan grup.
- **Never do:** Menambahkan `cekOwnership()` ke endpoint baca (`apiMessages`, `apiConversations`, `apiHandoffs`, `media`); menghapus filter `auth`; mengubah perilaku `404` pada media; menyentuh skema/migrasi; mengubah kebijakan Internal Note.

## 10. Rationale, Context & Architecture Decisions (ADRs)

Inbox AuliaPos adalah **shared inbox**: seluruh staff melayani satu antrean pelanggan yang sama. Karena itu `assigned_to` dipahami sebagai **tanggung jawab**, bukan **hak privasi** — konsisten `CL-004` (`"Ownership bukan visibility filter"`, `docs/Rencana Implementasi M3 Operational Inbox.md:216`). Membuka baca untuk semua staff justru membuat model pegangan bekerja: setiap orang tahu percakapan mana yang belum diambil dan siapa yang memegangnya (REQ-005), sehingga tidak ada dua staff mengerjakan percakapan yang sama secara diam-diam. Guard kepemilikan tetap penuh pada **operasi tulis**, tempat risiko sebenarnya berada (mengubah kepemilikan, mengirim ke pelanggan, menutup percakapan).

Konsistensi teks dan media inilah alasan REQ-002: bila teks percakapan terbuka untuk semua staff, menutup media justru menciptakan ketidakcocokan (teks boleh dibaca, gambar tidak) tanpa menambah keamanan nyata — setiap staff yang boleh membaca teks sudah boleh mengetahui isi percakapan. Pemisahan teks/media karena itu **tidak dipertahankan**.

**Tidak ada ADR baru.** Keputusan ini gagal *Triple Gate Validation* (`.claude/standards/ADR-FORMAT.md`): menambahkan kembali satu guard `cekOwnership()` bersifat **mudah dibalik** (revert satu blok), sehingga tidak memenuhi kriteria "hard to reverse". Rasionalnya dicatat di sini, bukan sebagai ADR terpisah.

## 11. Dependencies & External Integrations

### External Systems

- **EXT-001**: `tikusgot007/WA-Gateway` — tidak disentuh. Media tetap diambil lewat kontrak `POST /media/download` yang sudah ada (`Inbox::callGatewayMediaDownload()`); perizinan baca tidak mengubah kontrak Gateway.

### Infrastructure Dependencies

- **INF-001**: Filter `auth` session (`app/Config/Filters.php`) — prasyarat REQ-001; aturan baca terbuka berlaku hanya untuk staff yang sudah login.

## 12. Examples & Edge Cases

**Edge case (percakapan tanpa pemilik):** `assigned_to IS NULL`. Semua staff boleh membaca (REQ-001) dan boleh **mengambil** percakapan (klaim, `ambilPercakapan()`) supaya menjadi pemegangnya — perilaku lama tidak berubah.

**Edge case (admin):** admin boleh membaca semua **dan** menulis ke semua percakapan (override supervisi) — konsisten `cekOwnership()` yang selalu meloloskan admin.

**Edge case (kasir non-admin mencoba tulis):** kasir B memanggil `POST /inbox/kirim` untuk percakapan yang dipegang kasir A → `403` dengan pesan `cekOwnership()` (menyebut siapa pemegangnya). Membaca thread yang sama → `200` (AC-001). Kombinasi ini **disengaja**: tahu isinya, tidak boleh mengubahnya.

**Edge case (media pesan yang sudah soft-deleted):** tidak berubah. `Inbox::media()` memakai `MessageModel::find()` polos; pesan soft-deleted tetap `404` (lihat `spec-design-balas-pesan.md` Section 12). Pembukaan guard kepemilikan tidak mengubah perilaku `404` ini.

**Edge case (percakapan grup dengan `assigned_to` warisan terisi):** baca tetap terbuka untuk semua (CON-002); aksi tulis tetap `403` lewat `cekBukanGrup()` sebelum `cekOwnership()` (`spec-design-grup-tahap1-tab-inbox.md` CON-004/AC-013).

## 13. Validation Criteria

- `vendor/bin/phpunit --no-coverage` hijau 100%.
- Test otomatis: `GET /inbox/media/{id}` untuk media percakapan yang dipegang kasir lain → `200` (bukan `403`); pesan/percakapan tidak ada → `404`; pemegang/admin → `200`.
- Test otomatis: `GET /inbox/media/{id}` yang berujung `410` dari Gateway dipanggil kasir non-pemegang → tetap `410` (bukan `403` di awal) dan `media_confirmed_gone_at` tertulis (AC-009/C-2).
- Test otomatis: `GET /inbox/api/conversations/{id}/messages` untuk percakapan milik kasir lain → `200` (anti-regresi SEC-001).
- Test otomatis: operasi tulis oleh kasir non-pemegang → `403`; oleh pemegang/admin → perilaku lama (regresi nol).
- Manual check: indikator `"Belum diambil"` / `"Dipegang: X"` terlihat oleh staff mana pun pada daftar dan header percakapan pribadi; tersembunyi pada Grup.

## 14. Related Specifications / Further Reading

- [`spec-index.md`](./spec-index.md)
- [`spec-design-m3-operational-inbox-fase1.md`](./spec-design-m3-operational-inbox-fase1.md) — sumber `CL-004` (baca terbuka) dan `SEC-001` (Internal Note tanpa `cekOwnership()`)
- [`spec-design-balas-pesan.md`](./spec-design-balas-pesan.md) — memakai `GET /inbox/media/(:num)` sebagai seam live-fetch kutipan (REQ-008b)
- [`spec-design-grup-tahap1-tab-inbox.md`](./spec-design-grup-tahap1-tab-inbox.md) — aturan grup (`CON-004`, `CON-006`)
- `plan-refactor-balas-pesan-tahap3-review2-v1.0.md` — `TASK-103`/`SEC-002` yang digantikan REQ-002
- `docs/audit/consistency-audit-balas-pesan-tahap3-review3-2026-09-27.md` — temuan `ALT-003`/`AUTHZ-02` yang ditutup spec ini
- `docs/CHAT.md` §18 (Developer Invariants)
