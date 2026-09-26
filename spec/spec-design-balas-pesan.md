---
title: Balas Pesan (Reply/Quote) — Lintas Repo
version: 1.1
date_created: 2026-09-26
last_updated: 2026-09-26
owner: AuliaPos Inbox module
tags: [inbox, chat, whatsapp, balas-pesan, tahap3, wa-gateway]
---

# Introduction

Spesifikasi ini mendefinisikan fitur **Balas Pesan** (`prd-20260926-0024-whatsapp-grup-balas-teruskan.md` GH-015): kasir dapat mengutip (quote) satu pesan tertentu di suatu percakapan, lalu mengirim balasan yang menyertakan kutipan itu, memakai fitur reply native WhatsApp — bukan menyalin teks kutipan secara manual.

> [!IMPORTANT]
> **Prasyarat: Tahap 1 sudah rilis** (`spec-design-grup-tahap1-tab-inbox.md`). Balas Pesan berlaku baik di percakapan pribadi maupun grup (PRD Section 8.1 GH-015 tidak membatasi ke satu jenis percakapan).

## 1. Purpose & Scope

Spec ini mencakup, dan hanya mencakup:

- **Kontrak baru** pada `POST {gatewayBaseUrl}/send` (AuliaPos → WA-Gateway) untuk mengirim pesan sebagai balasan native ke pesan tertentu.
- Penyimpanan kutipan (snapshot cuplikan, bukan referensi hidup) di sisi AuliaPos.
- UI memilih pesan yang akan dikutip, membatalkan pilihan, dan menampilkan kutipan di bubble pesan.
- Penanganan kegagalan pengiriman balasan bertipe quote (Gateway menolak/tidak mendukung).

Audiens: developer WA-Gateway dan AuliaPos, serta agent `/sdlc-plan-tasks` (dua plan terpisah, PRD Section 9.1).

### 1.1 Out of Scope

- Meneruskan pesan (Teruskan) — Tahap 4, `spec-design-teruskan.md`.
- Mengutip pesan lintas percakapan (kutip dari percakapan A, kirim ke percakapan B) — itu adalah **Teruskan**, bukan Balas Pesan (`CONTEXT.md`, `_Avoid_` pada entri Teruskan).
- Sinkronisasi status "pesan dihapus" pada kutipan — sudah diputuskan Clarification Report: kutipan tetap tampil apa adanya walau pesan asli soft-deleted.
- Perubahan skema `conversations` — tidak dibutuhkan untuk fitur ini.

> [!NOTE]
> **Cakupan diperluas (Clarification Report Spec, Resolved Item #10 / Temuan Kritis #5):** kutipan **masuk** dari pelanggan (pelanggan membalas salah satu pesan di WhatsApp-nya sendiri, native reply) **termasuk** dalam cakupan Tahap 3 ini — bukan Out of Scope. Lihat REQ-010–REQ-013 dan Section 4.4.

## 1.2 Open Questions & Assumptions

> [!WARNING]
> **ASSUMPTION-004: Baileys mendukung reply native lewat opsi `quoted` pada fungsi pengiriman pesan** (`sock.sendMessage(jid, content, { quoted: originalMsgObject })`), yang menghasilkan `contextInfo.stanzaId`/`participant`/`quotedMessage` pada pesan terkirim — tampil sebagai kutipan native di UI WhatsApp penerima. Ini API standar Baileys, **belum** diimplementasikan di `src/api/ci4Routes.js`/`connectionManager.js` WA-Gateway saat ini (kode publik yang diperiksa hanya punya `/send` dan `/send-media` tanpa parameter quote sama sekali). Tahap 3 **menambah** kontrak ini sebagai fitur baru.
>
> **Konsekuensi penting**: opsi `quoted` Baileys butuh **objek pesan asli lengkap** (bukan hanya ID), yang Gateway sendiri **tidak menyimpan** (Gateway bukan sumber kebenaran, `docs/CHAT.md` §2/§18). Karena itu REQ-002 mewajibkan AuliaPos mengirim **seluruh data pesan asli yang dibutuhkan** (id pesan WhatsApp asli, JID pengirim asli, tipe & isi pesan) di setiap request balas, bukan hanya ID referensi — pola ini konsisten dengan prinsip "Gateway tidak pernah menjadi sumber riwayat pesan" yang sudah berlaku di proyek.

> [!NOTE]
> **RESOLVED (Clarification Report):** Kutipan bertahan apa adanya walau pesan asli soft-deleted; kutipan ke media yang sudah tidak tersedia menampilkan placeholder **"[Media tidak tersedia]"**.

- **CLARIFICATION NEEDED:** Tidak ada gap tersisa untuk sisi AuliaPos. Ketersediaan aktual `quoted` di versi Baileys yang dipakai (ASSUMPTION-004) adalah keputusan/verifikasi teknis wewenang plan WA-Gateway, dengan fallback sudah didefinisikan (lihat REQ-006).

> [!WARNING]
> **ASSUMPTION-006 (kutipan masuk): kolom kutipan yang sudah ada (`quoted_wa_message_id`, `quoted_sender_label`, `quoted_snippet`, `quoted_media_available`, Section 4.2) dipakai ulang apa adanya** untuk menyimpan kutipan pada pesan **masuk** dari pelanggan — bukan menambah 4 kolom baru yang duplikat. Kolom-kolom itu sudah generik (nullable, tidak terikat ke arah `direction` tertentu) sejak didesain di Section 4.2, jadi menambah kolom kedua untuk arah masuk akan melanggar `GUD-001` (additive tanpa kebutuhan nyata) dan prinsip minimalisme proyek (`CLAUDE.md`). Satu-satunya tambahan adalah field baru di payload `POST /api/inbox/gateway/messages` (REQ-010) dan logic resolusi di `InboxGatewayApi::messages()` (REQ-011). Apakah pemakaian ulang kolom ini sudah sesuai? Kalau tidak, beri tahu agar dipecah jadi kolom terpisah.
>
> **ASSUMPTION-007 (sumber kebenaran kutipan masuk): AuliaPos mencoba resolve `quoted.wa_message_id` dari payload Gateway ke tabel `messages` miliknya sendiri terlebih dulu** (query by `wa_message_id`, mekanisme yang sama dengan pengecekan idempotensi `existsByWaMessageId()` yang sudah ada) — **bukan** mempercayai teks/label yang dikirim Gateway begitu saja, konsisten dengan prinsip "Gateway bukan sumber kebenaran riwayat pesan" (`docs/CHAT.md` §2/§18, dikutip juga di ASSUMPTION-004 di atas). Fallback: jika pesan yang dikutip **tidak ditemukan** di DB lokal (mis. lebih tua dari retensi, atau race condition), pakai snippet apa adanya yang disertakan Gateway di payload (best-effort, ditandai sebagai tidak terverifikasi — lihat REQ-011).

## 2. Definitions

Mengikuti `CONTEXT.md`: **Balas Pesan** — membalas satu pesan spesifik dengan menyertakan kutipan (quote) dari pesan tersebut, memakai fitur reply native WhatsApp. `_Avoid_`: Reply, Quote, Kutip (lihat entri lengkap di `CONTEXT.md`).

Istilah tambahan:

- **Kutipan (Snapshot)**: cuplikan independen (teks/jenis media/nama pengirim) dari pesan yang dibalas, disimpan **sekali** saat balasan dibuat — bukan pointer/foreign key hidup ke `messages.id` yang bisa berubah kalau pesan asli diubah/dihapus.
- **Balasan Gagal-Quote**: kondisi saat Gateway tidak berhasil mengirim pesan sebagai reply native (lihat REQ-006).

## 3. Requirements, Constraints & Guidelines

### Sisi WA-Gateway (kontrak baru — implementasi ada di repo lain)

- **REQ-001**: `POST {gatewayBaseUrl}/send` menerima field opsional baru `quoted` (objek), berisi seluruh data yang dibutuhkan Baileys untuk membentuk `quoted` message object: `quoted.wa_message_id`, `quoted.sender_jid`, `quoted.message_type`, `quoted.text` (untuk teks) atau `quoted.media_type` (untuk media). Field ini **tidak wajib** — request tanpa `quoted` berperilaku seperti sekarang (pesan biasa).
- **REQ-002**: Saat `quoted` ada di request, Gateway membentuk objek pesan Baileys minimal yang cukup untuk parameter `quoted` (`key: {id, remoteJid, fromMe, participant}`, `message: {...}` sesuai `message_type`) dari data yang dikirim AuliaPos — **tanpa** perlu mencari/menyimpan pesan asli di sisi Gateway, sesuai ASSUMPTION-004.
- **REQ-003**: Response `POST {gatewayBaseUrl}/send` mengembalikan indikator keberhasilan reply native secara eksplisit, mis. `{"sent": true, "quote_applied": true}` vs `{"sent": true, "quote_applied": false}` — supaya AuliaPos tahu pasti apakah kutipan native berhasil diterapkan atau tidak (dibutuhkan REQ-006).
- **CON-001**: `quoted` **tidak pernah** memengaruhi pengiriman kalau Gateway gagal membentuknya — dalam kasus itu Gateway **tetap** mengirim isi pesan (tanpa quote), bukan menggagalkan seluruh pengiriman (prinsip "gagal dengan suara, bukan senyap" PRD, tapi pesan pengguna tidak boleh hilang total).

### Sisi AuliaPos (penyimpanan, UI, idempotensi)

- **REQ-004**: UI thread pesan (`app/Views/inbox/index.php`) menyediakan aksi "Balas" pada setiap bubble pesan (kecuali pesan yang sedang dihapus/gagal kirim) — mengikuti pola tombol aksi per-pesan yang sudah ada di kode saat ini.
- **REQ-005**: Memilih "Balas" menampilkan area kutipan aktif di atas kotak ketik, menunjukkan cuplikan pesan yang akan dikutip (teks terpotong / label jenis media) dan nama pengirim (memakai `sender_jid`/nama kontak yang sudah ada — untuk grup memakai label dari Tahap 2). Tersedia tombol batal untuk melepas pilihan kutipan tanpa mengirim.
- **REQ-006 (Balasan Gagal-Quote — eksplisit, tidak senyap)**: Jika response Gateway menunjukkan `quote_applied: false` (atau request gagal total karena field `quoted` — lihat CON-002), AuliaPos **tidak mengirim ulang otomatis secara diam-diam**. Pesan tetap tersimpan terkirim (isi teks/media berhasil, sesuai CON-001 Gateway), namun AuliaPos menandai pesan itu di UI sebagai "Terkirim tanpa kutipan" agar kasir sadar kutipan tidak sampai ke penerima — bukan berpura-pura kutipan berhasil.
- **REQ-007**: `messages` menyimpan kutipan sebagai kolom baru pada baris pesan balasan itu sendiri (lihat Section 4.2) — **snapshot saat itu**, tidak pernah di-refresh ulang dari pesan asli setelahnya (mendukung resolusi "kutipan tetap tampil apa adanya walau pesan asli di-soft-delete").
- **REQ-008**: Jika pesan yang hendak dikutip memiliki media yang sudah tidak tersedia (`media_status` gagal/expired — pola yang sudah ada untuk media biasa), UI kutipan menampilkan **"[Media tidak tersedia]"** (persis, sesuai Clarification Report) alih-alih mencoba memuat gambar.
- **REQ-009**: Pengiriman balasan **memakai kembali** mekanisme idempotensi yang sudah ada (`operation_id` dari frontend → `gateway_operation_id` unik, pola identik `kirim()`/`kirimKeConversation()`) — tidak ada mekanisme idempotensi baru.
- **CON-002**: Kalau `POST /inbox/percakapan/{id}/kirim` (endpoint AuliaPos yang memanggil Gateway) menerima kegagalan HTTP total dari Gateway saat mengirim field `quoted` (bukan `quote_applied: false`, tapi request itu sendiri error), perilaku **sama seperti kegagalan kirim pesan biasa saat ini** — pesan ditandai gagal, kasir bisa coba lagi (tidak ada penanganan khusus tambahan di luar yang sudah ada).
- **GUD-001**: Kolom kutipan baru (Section 4.2) **nullable**, tidak memengaruhi baris pesan yang bukan balasan (`quoted_*` semuanya `NULL`) — additive terhadap skema `messages` yang ada.

### Sisi AuliaPos — kutipan pada pesan MASUK dari pelanggan (cakupan diperluas)

- **REQ-010**: `POST /api/inbox/gateway/messages` (`InboxGatewayApi::messages()`) menerima field opsional baru `quoted` (objek) pada payload pesan **masuk** (`direction` kosong atau `'incoming'`), berisi `quoted.wa_message_id` (wajib jika objek `quoted` ada), `quoted.sender_jid` (opsional), dan `quoted.snippet` (opsional, teks/label yang menurut Gateway mewakili pesan asli — dipakai hanya sebagai fallback, lihat REQ-011). Field ini **tidak wajib** — payload tanpa `quoted` berperilaku seperti sekarang (pesan biasa, seluruh `quoted_*` tetap `NULL`).
- **REQ-011**: Saat `quoted.wa_message_id` ada, `InboxGatewayApi::messages()` mencari pesan tersebut di tabel `messages` milik AuliaPos sendiri (`WHERE wa_message_id = ?`, seam yang sama dengan `MessageModel::existsByWaMessageId()`) **sebelum** mengisi kolom kutipan pada baris pesan masuk yang baru:
  - **Ditemukan** → `quoted_wa_message_id`, `quoted_sender_label` (dari `sender_jid`/identitas pengirim baris itu), `quoted_snippet` (dari `text` baris itu, dipotong sama seperti REQ-007), dan `quoted_media_available` (dari `media_status` baris itu) diisi dari data lokal AuliaPos — **bukan** dari `quoted.snippet` yang dikirim Gateway.
  - **Tidak ditemukan** → `quoted_wa_message_id` tetap diisi (untuk keperluan Section 4.4/tampilan), `quoted_snippet` diisi dari `quoted.snippet` payload Gateway apa adanya jika ada (atau label generik **"Pesan tidak ditemukan"** jika `quoted.snippet` juga kosong), dan `quoted_media_available` diisi `NULL` (tidak diketahui) — **tidak** memblokir/menggagalkan penyimpanan pesan masuk itu sendiri (prinsip "gagal dengan suara, bukan senyap", tapi pesan pelanggan tidak boleh hilang).
- **REQ-012**: Pencarian REQ-011 **tidak boleh** menambah query baru per pesan pada jalur normal — hanya dijalankan saat `quoted` ada di payload (kondisional), konsisten dengan `GUD-001` Section 3 di atas dan `CL-010`/`GUD-001` M3 Fase 1e (filter/lookup tambahan hanya saat benar-benar dibutuhkan).
- **REQ-013**: UI thread pesan menampilkan kotak kutipan pada bubble pesan **masuk** yang punya `quoted_wa_message_id` terisi, memakai komponen tampilan yang **sama** dengan kotak kutipan pada bubble balasan kasir (REQ-005/REQ-008) — termasuk placeholder **"[Media tidak tersedia]"** bila `quoted_media_available = 0`, dan label generik **"Pesan tidak ditemukan"** bila kutipan tidak ter-resolve ke pesan lokal (REQ-011 kasus kedua). Tidak ada komponen UI baru.

## 4. Interfaces & Data Contracts

### 4.1 `POST {gatewayBaseUrl}/send` — payload tambahan

```json
{
  "operation_id": "...",
  "chat_id": "6281234567890@s.whatsapp.net",
  "message_type": "text",
  "text": "Baik, akan saya proseskan.",
  "quoted": {
    "wa_message_id": "3EB0XXXX...",
    "sender_jid": "6281234567890@s.whatsapp.net",
    "message_type": "text",
    "text": "Kapan pesanan saya dikirim?"
  }
}
```

Response:

```json
{ "sent": true, "gateway_operation_id": "...", "quote_applied": true }
```

### 4.2 Migrasi baru: kolom kutipan pada `messages`

```php
'quoted_wa_message_id' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
'quoted_sender_label'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true], // nama/nomor pengirim asli, snapshot
'quoted_snippet'       => ['type' => 'TEXT', 'null' => true], // cuplikan teks, atau label jenis media (mis. "[Foto]")
'quoted_media_available' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true, 'default' => null],
```

`quoted_snippet` diisi dari teks pesan asli (dipotong ke panjang wajar, mis. 200 karakter) **saat balasan dibuat** — bukan dihitung ulang saat ditampilkan.

### 4.3 Endpoint AuliaPos internal — parameter baru

`POST /inbox/percakapan/{id}/kirim` menerima field opsional `quoted_message_id` (ID lokal `messages.id`, bukan `wa_message_id`) — AuliaPos mengambil data pesan asli dari DB sendiri untuk mengisi `quoted_snippet` dkk. dan membentuk payload Section 4.1 ke Gateway.

### 4.4 `POST /api/inbox/gateway/messages` — payload tambahan untuk kutipan masuk (REQ-010–012)

```json
{
  "wa_message_id": "3EB0YYYY...",
  "chat_id": "6281234567890@s.whatsapp.net",
  "jid_type": "pn",
  "message_type": "text",
  "message_timestamp": 1758870000,
  "text": "Baik kalau begitu, saya tunggu ya",
  "quoted": {
    "wa_message_id": "3EB0XXXX...",
    "sender_jid": "6281234567890@s.whatsapp.net",
    "snippet": "Kapan pesanan saya dikirim?"
  }
}
```

`quoted` bersifat opsional dan hanya dikirim Gateway saat pelanggan benar-benar membalas (native reply) salah satu pesan di WhatsApp-nya. Tidak ada perubahan pada response `POST /api/inbox/gateway/messages` (`{"status":"success", ...}` seperti sekarang) — resolusi REQ-011 murni internal, tidak memengaruhi kontrak response ke Gateway.

Tidak ada migrasi tambahan — kutipan masuk memakai kolom yang **sama** dengan Section 4.2 (`quoted_wa_message_id`, `quoted_sender_label`, `quoted_snippet`, `quoted_media_available`), yang memang sudah didesain generik/nullable untuk baris pesan apa pun, bukan khusus balasan kasir (lihat ASSUMPTION-006).

## 5. Acceptance Criteria

- **AC-001**: Given kasir memilih "Balas" pada suatu pesan lalu mengirim balasan, When Gateway berhasil menerapkan quote native, Then pesan balasan tersimpan dengan `quoted_wa_message_id`/`quoted_snippet` terisi dan tampil dengan kotak kutipan di UI AuliaPos.
- **AC-002**: Given balasan terkirim, When dilihat di WhatsApp penerima (uji manual), Then muncul sebagai reply native WhatsApp (bukan teks biasa berisi kutipan manual).
- **AC-003**: Given Gateway mengembalikan `quote_applied: false`, When AuliaPos memproses response, Then pesan tetap tersimpan terkirim dan diberi penanda "Terkirim tanpa kutipan" di UI — bukan digagalkan atau dikirim ulang otomatis.
- **AC-004**: Given pesan asli yang dikutip kemudian di-soft-delete, When thread dimuat ulang, Then kutipan pada balasan tetap tampil apa adanya, tanpa penanda "pesan dihapus".
- **AC-005**: Given pesan media yang dikutip sudah tidak tersedia, When kutipan ditampilkan, Then muncul teks **"[Media tidak tersedia]"**, bukan gambar rusak.
- **AC-006**: Given kasir mengirim dua balasan dengan `operation_id` yang sama (retry jaringan), When diproses, Then hanya satu baris pesan tersimpan (idempotensi existing tetap berlaku).
- **AC-007**: Given percakapan grup, When kasir membalas pesan anggota tertentu, Then `quoted_sender_label` menampilkan identitas anggota itu (bukan nama grup).
- **AC-008**: Given pelanggan membalas (native reply) salah satu pesan yang **ada** di DB AuliaPos, When `InboxGatewayApi::messages()` memproses payload dengan `quoted.wa_message_id` tersebut, Then baris pesan masuk yang baru tersimpan dengan `quoted_snippet`/`quoted_sender_label`/`quoted_media_available` terisi dari data lokal AuliaPos (bukan dari `quoted.snippet` payload), dan tampil dengan kotak kutipan yang benar di UI.
- **AC-009**: Given pelanggan membalas pesan yang **tidak ditemukan** di DB AuliaPos (mis. lebih tua dari retensi), When payload diproses, Then pesan masuk tetap tersimpan (tidak gagal), kotak kutipan menampilkan `quoted.snippet` dari Gateway apa adanya jika ada, atau label **"Pesan tidak ditemukan"** jika `quoted.snippet` juga kosong.

## 6. Test Automation Strategy & Testing Seams

- **Testing Seams**: HTTP boundary `Inbox::kirim()`/method pengiriman terkait (mock respons Gateway dengan `quote_applied` true/false), `InboxGatewayApi::messages()` untuk kutipan masuk (REQ-010–012), dan `MessageModel` untuk penyimpanan kolom kutipan.
- **Test Levels**: Unit (model, pembentukan `quoted_snippet`), Feature (endpoint kirim dengan/tanpa quote, dengan quote gagal; endpoint `messages()` dengan `quoted.wa_message_id` ditemukan vs tidak ditemukan).
- **Test Data Management**: Factory pesan dengan `quoted_*` terisi, kasus media tidak tersedia, kasus grup, kasus kutipan masuk (pesan asli ada/tidak ada di DB).
- **Coverage Requirements**: `vendor/bin/phpunit --no-coverage` keluar kode 0.

## 7. Project Structure & Commands

### Project Structure (AuliaPos)

- Migrasi baru: `app/Database/Migrations/<timestamp>_AddQuoteColumnsToMessages.php`.
- `app/Controllers/Inbox.php` — tambah parameter `quoted_message_id` di endpoint kirim, bentuk payload ke Gateway, proses `quote_applied` dari response.
- `app/Controllers/InboxGatewayApi.php` — `messages()` menerima `quoted` di payload masuk, resolve `wa_message_id` ke DB lokal (REQ-011), isi `quoted_*` sebelum `$messageModel->insert()`.
- `app/Models/MessageModel.php` — tambah `quoted_*` ke `$allowedFields`; tambah method lookup by `wa_message_id` untuk kutipan masuk (reuse pola `existsByWaMessageId()` yang sudah ada, ubah jadi mengembalikan baris bukan cuma boolean, atau tambah method baru di sampingnya).
- `app/Views/inbox/index.php` — UI pilih/batal kutip, tampilan kotak kutipan pada bubble (dipakai untuk balasan kasir **dan** pesan masuk pelanggan, REQ-013), penanda "Terkirim tanpa kutipan".

### Project Structure (WA-Gateway — repo terpisah, plan terpisah)

- `src/api/ci4Routes.js` — terima field `quoted` di `/send`.
- `src/whatsapp/connectionManager.js` — bentuk parameter `quoted` Baileys, kembalikan `quote_applied`.

### Commands (AuliaPos)

- **Migrasi:** `php spark migrate`
- **Test:** `vendor/bin/phpunit --no-coverage`

## 8. Code Style & Conventions

Mengikuti pola idempotensi yang sudah ada (`operation_id` → `gateway_operation_id`) tanpa modifikasi; penambahan field kutipan mengikuti gaya penamaan kolom `snake_case` yang sudah dipakai di `messages`.

## 9. Implementation Boundaries

- **Always do:** Uji kasus `quote_applied: false` secara eksplisit sebelum menganggap fitur selesai — ini jalur yang paling mudah terlewat.
- **Ask first:** Perubahan pada mekanisme idempotensi (`operation_id`) itu sendiri — Tahap 3 hanya **memakai ulang**, tidak mengubahnya.
- **Never do:** Membuat kutipan sebagai foreign key hidup ke `messages.id` yang ikut berubah saat pesan asli diubah/dihapus (melanggar resolusi Clarification Report).

## 10. Rationale, Context & Architecture Decisions (ADRs)

Tidak ada ADR baru. Kutipan sebagai *snapshot* (bukan referensi hidup) adalah penerapan langsung prinsip "Gateway/DB bukan sumber riwayat yang bisa berubah retroaktif" yang sudah berlaku (`docs/CHAT.md` §2); gagal *Triple Gate Validation* untuk ADR (tidak *surprising* diberi konteks yang sudah ada).

## 11. Dependencies & External Integrations

### External Systems

- **EXT-001**: `tikusgot007/WA-Gateway` — wajib merilis dukungan `quoted` di `/send` sebelum Tahap 3 bisa dirilis penuh di AuliaPos (REQ-001–003).

### Third-Party Services

- **SVC-001**: Baileys — opsi `quoted` pada `sendMessage()` adalah API bawaan library.

## 12. Examples & Edge Cases

**Edge case**: Kasir memilih "Balas" pada pesan yang lalu dihapus (soft-delete) oleh kasir lain sebelum tombol kirim ditekan — kutipan tetap terbentuk dari data yang sudah dimuat di client saat pemilihan (idem dengan REQ-007, snapshot diambil saat balasan dibuat, bukan re-fetch pesan asli).

**Edge case**: Membalas pesan yang **itu sendiri** adalah pesan hasil Balas Pesan/Teruskan sebelumnya — kutipan yang ditampilkan adalah isi pesan tersebut apa adanya (termasuk teks "↪️ Diteruskan: ..." kalau ada), bukan menelusuri rantai ke pesan paling awal. Tidak ada rantai kutipan bertingkat.

**Edge case (kutipan masuk)**: Pelanggan membalas pesan yang **sama** dua kali secara berurutan (dua pesan masuk berbeda, masing-masing dengan `quoted.wa_message_id` yang sama) — setiap baris pesan masuk baru diproses independen lewat REQ-011, tidak ada dedup/caching hasil lookup lintas pesan (volumenya rendah, tidak butuh optimasi tambahan sesuai `GUD-001`).

## 13. Validation Criteria

- `vendor/bin/phpunit --no-coverage` hijau 100%.
- Manual check: kirim balasan dari AuliaPos, verifikasi tampil sebagai reply native di WhatsApp (HP uji).
- Manual check: matikan dukungan quote di Gateway (simulasi `quote_applied:false`), pastikan pesan tetap terkirim dengan penanda yang benar.
- Manual check: kirim payload `POST /api/inbox/gateway/messages` dengan `quoted.wa_message_id` yang ada dan yang tidak ada di DB, pastikan kedua kasus REQ-011 berjalan sesuai AC-008/AC-009.

## 14. Related Specifications / Further Reading

- [`spec-index.md`](./spec-index.md)
- [`spec-design-grup-tahap1-tab-inbox.md`](./spec-design-grup-tahap1-tab-inbox.md)
- [`spec-design-grup-tahap2-identitas.md`](./spec-design-grup-tahap2-identitas.md)
- [`spec-design-teruskan.md`](./spec-design-teruskan.md) — tahap berikutnya, berinteraksi dengan fitur ini (lihat Clarification Report resolusi #6)
- `docs/CHAT.md` §6 (Media), §18 (Developer Invariants)
