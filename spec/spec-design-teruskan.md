---
title: Teruskan (Forward) — Lintas Repo
version: 1.0
date_created: 2026-09-26
owner: AuliaPos Inbox module
tags: [inbox, chat, whatsapp, teruskan, tahap4, wa-gateway]
---

# Introduction

Spesifikasi ini mendefinisikan fitur **Teruskan** (`prd-20260926-0024-whatsapp-grup-balas-teruskan.md` GH-016): kasir dapat meneruskan satu pesan dari satu percakapan ke percakapan lain yang **sudah ada** (tidak pernah membuat percakapan baru), dengan penanda "Diteruskan" yang terlihat penerima.

> [!IMPORTANT]
> **Prasyarat: Tahap 1 & Tahap 3 sudah rilis** (`spec-design-grup-tahap1-tab-inbox.md`, `spec-design-balas-pesan.md`). Teruskan memakai kolom migrasi yang sudah ditambahkan Tahap 3 tidak langsung, tetapi berbagi mekanisme "kirim pesan lintas percakapan" yang mirip dan **berinteraksi** dengan kutipan (lihat Section 3, aturan non-stacking).

## 1. Purpose & Scope

Spec ini mencakup, dan hanya mencakup:

- **Kontrak baru** pada `POST {gatewayBaseUrl}/send` untuk menandai pesan sebagai forwarded (native Baileys, dengan fallback teks).
- UI memilih pesan sumber, memilih percakapan tujuan yang **sudah ada**, dan mengirim.
- Aturan forwardability per jenis media (teks selalu bisa, gambar/dokumen/stiker bisa kalau file masih tersedia, audio/video **tidak pernah bisa** — limitasi permanen).
- Penerapan `cekOwnership()` hanya pada percakapan tujuan.
- Interaksi dengan Balas Pesan (kutipan tidak ikut terbawa, penanda tidak menumpuk).

Audiens: developer WA-Gateway dan AuliaPos, serta agent `/sdlc-plan-tasks`.

### 1.1 Out of Scope

- Membuat percakapan baru sebagai tujuan Teruskan — **dilarang secara permanen** (PRD Section 2.3, "Teruskan tidak pernah membuka percakapan baru").
- Meneruskan pesan audio/video — **limitasi permanen** (`docs/CHAT.md` §6.2, PRD Section 2.3) karena Gateway tidak pernah mengunduh biner audio/video sama sekali; tidak ada cara mendapatkan filenya untuk diteruskan.
- Meneruskan ke banyak percakapan sekaligus (broadcast) — tidak diminta PRD, tetap satu tujuan per aksi Teruskan.
- Perubahan pada `cekOwnership()` itu sendiri — hanya **penerapannya** (di percakapan mana ia dipanggil) yang diatur di sini.

## 1.2 Open Questions & Assumptions

> [!WARNING]
> **ASSUMPTION-005 [Assumption — perlu verifikasi teknis, sudah ditandai Clarification Report]: Penanda "Diteruskan" diutamakan memakai fitur native "forwarded message" Baileys** (`contextInfo.isForwarded = true`, `contextInfo.forwardingScore` — API standar Baileys). Ini **belum diverifikasi** dapat berjalan pada versi Baileys yang dipakai WA-Gateway saat ini (repo di komputer lain, tidak bisa diperiksa langsung dari sesi ini). **Fallback wajib disiapkan sejak awal implementasi** (bukan ditambah belakangan): jika native-forward gagal/tidak tersedia, sisipkan penanda sebagai teks biasa **"↪️ Diteruskan: "** di depan isi pesan, tanpa mengubah kontrak `/send` lebih jauh. Developer WA-Gateway wajib mencoba native lebih dulu; AuliaPos tidak perlu tahu mana yang dipakai (Gateway yang memutuskan, dilaporkan lewat field response, lihat REQ-003).

> [!NOTE]
> **RESOLVED (Clarification Report):**
> - `cekOwnership()` hanya diperiksa pada percakapan **tujuan**, bukan sumber (kasir tidak perlu memiliki percakapan sumber).
> - Meneruskan pesan yang mengandung kutipan (hasil Balas Pesan) atau yang sudah pernah diteruskan sebelumnya: hanya isi teks/media pesan itu sendiri yang dikirim ulang; kutipan **tidak** ikut terbawa; penanda "Diteruskan" **tidak menumpuk** (tetap satu label walau diteruskan berkali-kali).

- **CLARIFICATION NEEDED:** Tidak ada gap tersisa untuk sisi AuliaPos.

## 2. Definitions

Mengikuti `CONTEXT.md`: **Teruskan** — mengirim ulang isi satu pesan (teks/media) dari satu percakapan ke percakapan lain yang sudah ada, disertai penanda bahwa pesan tersebut diteruskan. `_Avoid_`: Forward, Kirim Ulang (lihat entri lengkap di `CONTEXT.md`).

Istilah tambahan:

- **Forwardable**: pesan yang secara teknis bisa diteruskan saat ini (teks selalu; gambar/dokumen/stiker jika file masih tersedia; audio/video tidak pernah).
- **Percakapan Tujuan**: percakapan yang **sudah ada** di AuliaPos tempat pesan hasil Teruskan dikirim — berbeda dari "percakapan sumber" (tempat pesan asli berada).

## 3. Requirements, Constraints & Guidelines

### Sisi WA-Gateway (kontrak baru)

- **REQ-001**: `POST {gatewayBaseUrl}/send` menerima field opsional baru `forward` (boolean, default `false`). Saat `true`, Gateway mencoba menandai pesan sebagai forwarded native lewat Baileys.
- **REQ-002**: Jika native-forward berhasil dibentuk, Gateway **tidak** menambah teks apa pun ke isi pesan (marker murni dari metadata WhatsApp). Jika gagal/tidak tersedia (ASSUMPTION-005), Gateway menyisipkan prefix teks **"↪️ Diteruskan: "** ke `text` sebelum dikirim (untuk pesan teks); untuk pesan media, prefix disisipkan ke `caption`.
- **REQ-003**: Response menyertakan field `forward_marker_applied: "native" | "text_fallback"` — supaya AuliaPos tahu cara apa yang dipakai (untuk keperluan log/debug, tidak memengaruhi UI kasir yang tetap menampilkan penanda "Diteruskan" sendiri di AuliaPos terlepas dari metode Gateway — lihat REQ-007).
- **CON-001**: Field `forward` **tidak pernah** dikombinasikan dengan field `quoted` (`spec-design-balas-pesan.md` Section 4.1) dalam satu request — meneruskan dan membalas-dengan-kutip adalah dua aksi terpisah yang tidak bisa digabung dalam satu kirim (konsisten dengan resolusi Clarification Report: kutipan tidak ikut terbawa saat Teruskan).

### Sisi AuliaPos (UI, aturan forwardability, ownership, non-stacking)

- **REQ-004**: UI thread pesan menyediakan aksi "Teruskan" pada bubble pesan, **kecuali** pesan bertipe audio/video — untuk kedua tipe ini, aksi "Teruskan" **tidak dirender sama sekali** (bukan disabled), sesuai pola CON-001 di `spec-design-grup-tahap1-tab-inbox.md` untuk aksi yang pasti gagal.
- **REQ-005**: Memilih "Teruskan" membuka pemilih **percakapan yang sudah ada** (pencarian/daftar percakapan, memakai UI/endpoint pencarian percakapan yang sudah ada di Inbox) — **tidak ada** opsi "buat percakapan baru" di alur ini.
- **REQ-006 (Forwardability per jenis pesan)**:
  | Jenis pesan | Forwardable? |
  | --- | --- |
  | Teks | Selalu |
  | Gambar / Dokumen / Stiker | Ya, **jika** file media masih tersedia (`media_status` sukses — pola pengecekan yang sudah ada) |
  | Gambar / Dokumen / Stiker dengan file tidak tersedia | **Tidak** — aksi ditolak, bukan dikirim tanpa lampiran |
  | Audio / Video | **Tidak pernah** — limitasi permanen (`docs/CHAT.md` §6.2), tidak bergantung status file |
- **CON-002 (All-or-nothing)**: Jika pesan gambar/dokumen/stiker yang dipilih ternyata filenya sudah tidak tersedia saat aksi kirim benar-benar dijalankan (race condition antara pilih dan kirim), seluruh aksi Teruskan **dibatalkan** dengan pesan error jelas ke kasir — **tidak** mengirim pesan teks kosong atau caption tanpa lampiran sebagai gantinya.
- **REQ-007**: `cekOwnership()` (`Inbox.php:693`) diperiksa **hanya** terhadap `conversation_id` **tujuan** — percakapan sumber (tempat pesan asli berada) **tidak** melewati pengecekan kepemilikan ini sama sekali, sesuai resolusi Clarification Report.
- **REQ-008**: Pesan hasil Teruskan disimpan sebagai baris `messages` baru pada percakapan tujuan, dengan kolom penanda baru `is_forwarded = true` (lihat Section 4.2) — AuliaPos menampilkan label "Diteruskan" di UI berdasarkan kolom ini, **independen** dari `forward_marker_applied` yang dilaporkan Gateway (REQ-003) — supaya kasir tetap melihat label konsisten di AuliaPos meskipun metode Gateway di baliknya berbeda-beda.
- **REQ-009 (Non-stacking & no-quote-carried-over)**: Saat meneruskan pesan yang **sendiri** merupakan hasil Balas Pesan (punya `quoted_*` terisi) atau hasil Teruskan sebelumnya (`is_forwarded = true`):
  - Hanya `text`/media pesan itu sendiri yang disalin ke pesan Teruskan baru.
  - Kolom `quoted_*` pada pesan Teruskan baru **selalu `NULL`** — kutipan tidak ikut terbawa.
  - `is_forwarded` pada pesan baru tetap `true` tunggal (bukan dihitung berlapis) — tidak ada kolom "jumlah forward" yang bertambah.
- **REQ-010**: Pengiriman Teruskan **memakai kembali** mekanisme idempotensi `operation_id`/`gateway_operation_id` yang sudah ada — tidak ada mekanisme baru.
- **GUD-001**: Pengecekan forwardability (REQ-006) dilakukan di **server** (endpoint kirim), bukan hanya di UI — defense in depth, konsisten dengan CON-004 di `spec-design-grup-tahap1-tab-inbox.md`.

## 4. Interfaces & Data Contracts

### 4.1 `POST {gatewayBaseUrl}/send` — payload tambahan

```json
{
  "operation_id": "...",
  "chat_id": "<tujuan>",
  "message_type": "text",
  "text": "Kapan pesanan saya dikirim?",
  "forward": true
}
```

Response:

```json
{ "sent": true, "gateway_operation_id": "...", "forward_marker_applied": "native" }
```

### 4.2 Migrasi baru: kolom pada `messages`

```php
'is_forwarded' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],
```

### 4.3 Endpoint AuliaPos internal — parameter baru

`POST /inbox/percakapan/{id}/kirim` (`{id}` = percakapan **tujuan**) menerima field baru `forward_from_message_id` (ID lokal `messages.id` pesan sumber). AuliaPos mengambil isi pesan sumber dari DB sendiri (bukan dari client) untuk mencegah manipulasi isi pesan saat forward, membentuk payload Section 4.1, dan menerapkan REQ-006/CON-002 sebelum memanggil Gateway.

## 5. Acceptance Criteria

- **AC-001**: Given pesan teks di percakapan A, When kasir meneruskannya ke percakapan B yang sudah ada, Then pesan baru muncul di percakapan B dengan label "Diteruskan", dan **tidak ada** percakapan baru yang terbentuk.
- **AC-002**: Given pesan audio atau video, When kasir membuka menu aksi pesan, Then opsi "Teruskan" **tidak muncul sama sekali**.
- **AC-003**: Given pesan gambar dengan file yang sudah tidak tersedia, When kasir mencoba meneruskannya (lewat request langsung ke endpoint, melewati UI), Then server menolak aksi dengan pesan error jelas, bukan mengirim pesan kosong.
- **AC-004**: Given kasir tidak memiliki (bukan assignee) percakapan sumber, When meneruskan pesan dari percakapan itu ke percakapan tujuan yang **dimilikinya**, Then aksi **berhasil** (ownership sumber tidak diperiksa).
- **AC-005**: Given kasir memiliki percakapan tujuan, When meneruskan pesan ke percakapan tujuan yang **bukan miliknya dan bukan tanpa pemilik**, Then server menolak dengan `403` (ownership tujuan tetap berlaku).
- **AC-006**: Given pesan sumber adalah hasil Balas Pesan (punya kutipan), When diteruskan, Then pesan hasil Teruskan **tidak membawa kutipan apa pun**.
- **AC-007**: Given pesan sumber adalah hasil Teruskan sebelumnya, When diteruskan lagi, Then label "Diteruskan" pada pesan baru tetap tunggal (tidak menumpuk/berlapis).
- **AC-008**: Given Gateway gagal menerapkan native-forward, When fallback teks dipakai, Then AuliaPos tetap menampilkan label "Diteruskan" yang konsisten di UI (tidak bergantung metode Gateway).

## 6. Test Automation Strategy & Testing Seams

- **Testing Seams**: HTTP boundary endpoint kirim (mock respons Gateway `forward_marker_applied` native/fallback), dan langsung ke logic forwardability/ownership di Controller.
- **Test Levels**: Unit (aturan forwardability per `message_type`+`media_status`), Feature (kirim forward sukses, forward audio/video ditolak, forward dengan ownership tujuan gagal, forward pesan yang sudah punya kutipan/forward sebelumnya).
- **Test Data Management**: Factory pesan dari tiap kombinasi tipe media × status file, serta pesan dengan `quoted_*`/`is_forwarded` terisi untuk kasus non-stacking.
- **Coverage Requirements**: `vendor/bin/phpunit --no-coverage` keluar kode 0.

## 7. Project Structure & Commands

### Project Structure (AuliaPos)

- Migrasi baru: `app/Database/Migrations/<timestamp>_AddIsForwardedToMessages.php`.
- `app/Controllers/Inbox.php` — endpoint kirim menerima `forward_from_message_id`, terapkan REQ-006/007/009, panggil `cekOwnership()` hanya untuk tujuan.
- `app/Models/MessageModel.php` — tambah `is_forwarded` ke `$allowedFields`.
- `app/Views/inbox/index.php` — aksi "Teruskan" per bubble (sembunyikan untuk audio/video), pemilih percakapan tujuan, label "Diteruskan".

### Project Structure (WA-Gateway — repo terpisah, plan terpisah)

- `src/api/ci4Routes.js` — terima field `forward` di `/send`.
- `src/whatsapp/connectionManager.js` — terapkan native-forward Baileys dengan fallback teks, kembalikan `forward_marker_applied`.

### Commands (AuliaPos)

- **Migrasi:** `php spark migrate`
- **Test:** `vendor/bin/phpunit --no-coverage`

## 8. Code Style & Conventions

Penerapan `cekOwnership()` hanya di sisi tujuan mengikuti pola pemanggilan fungsi yang sudah ada (`Inbox.php:693`), dipanggil dengan `conversation_id` tujuan secara eksplisit — bukan menambah parameter baru ke `cekOwnership()` itu sendiri (fungsi tidak diubah, hanya konteks pemanggilannya di endpoint baru).

## 9. Implementation Boundaries

- **Always do:** Ambil isi pesan sumber dari DB server (bukan payload dari client) sebelum membentuk request ke Gateway — mencegah kasir memalsukan isi pesan forward.
- **Ask first:** Mengubah signature/logic internal `cekOwnership()` — Tahap 4 hanya mengatur **di mana** dipanggil.
- **Never do:** Membuat percakapan baru sebagai efek samping Teruskan; mengizinkan Teruskan audio/video dengan alasan apa pun; membiarkan kutipan ikut terbawa pada pesan hasil Teruskan.

## 10. Rationale, Context & Architecture Decisions (ADRs)

Tidak ada ADR baru. Larangan permanen forward audio/video adalah konsekuensi langsung dari batasan arsitektur yang sudah ada dan didokumentasikan (`docs/CHAT.md` §6.2 — Gateway tidak pernah mengunduh biner audio/video), bukan keputusan baru yang butuh ADR.

## 11. Dependencies & External Integrations

### External Systems

- **EXT-001**: `tikusgot007/WA-Gateway` — wajib merilis dukungan `forward` di `/send` (REQ-001–003) sebelum Tahap 4 bisa dirilis penuh.

### Third-Party Services

- **SVC-001**: Baileys — `contextInfo.isForwarded` adalah API/metadata bawaan library; ketersediaannya di versi yang dipakai Gateway belum diverifikasi (ASSUMPTION-005).

## 12. Examples & Edge Cases

**Edge case**: Meneruskan pesan gambar yang sedang dalam proses decrypt-on-demand (belum selesai dimuat) saat tombol "Teruskan" ditekan — endpoint kirim menunggu status akhir (`media_status`) sebelum memutuskan forwardable/tidak, bukan menganggap "belum tersedia" sama dengan "tidak tersedia".

**Edge case**: Percakapan tujuan yang dipilih ternyata sama dengan percakapan sumber (kasir "meneruskan" pesan ke percakapan yang sama) — diizinkan (tidak ada larangan eksplisit di PRD), diperlakukan sama seperti tujuan lain; `cekOwnership()` tetap diperiksa karena secara teknis tetap "percakapan tujuan".

## 13. Validation Criteria

- `vendor/bin/phpunit --no-coverage` hijau 100%.
- Manual check: forward pesan teks & gambar ke percakapan lain, verifikasi label & isi benar; verifikasi audio/video tidak punya opsi Teruskan sama sekali di UI.

## 14. Related Specifications / Further Reading

- [`spec-index.md`](./spec-index.md)
- [`spec-design-grup-tahap1-tab-inbox.md`](./spec-design-grup-tahap1-tab-inbox.md)
- [`spec-design-grup-tahap2-identitas.md`](./spec-design-grup-tahap2-identitas.md)
- [`spec-design-balas-pesan.md`](./spec-design-balas-pesan.md) — interaksi non-stacking/no-quote-carried-over
- `docs/CHAT.md` §6.2 (Limitasi Media Audio/Video), §18 (Developer Invariants)
