---
title: Balas Pesan (Reply/Quote) — Lintas Repo
version: 1.8
date_created: 2026-09-26
last_updated: 2026-09-27
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
> **ASSUMPTION-007 (sumber kebenaran kutipan masuk): AuliaPos mencoba resolve `quoted.wa_message_id` dari payload Gateway ke tabel `messages` miliknya sendiri terlebih dulu** (query by `wa_message_id`, mekanisme yang sama dengan pengecekan idempotensi `existsByWaMessageId()` yang sudah ada) — **bukan** mempercayai teks/label yang dikirim Gateway begitu saja, konsisten dengan prinsip "Gateway bukan sumber kebenaran riwayat pesan" (`docs/CHAT.md` §2/§18, dikutip juga di ASSUMPTION-004 di atas). Fallback: jika pesan yang dikutip **tidak ditemukan** di DB lokal (mis. lebih tua dari retensi, atau race condition), pakai cuplikan `quoted.snippet` dari Gateway yang **dipotong + dinormalisasi** dengan `potongSnippet()` yang sama seperti jalur sumber-ditemukan (best-effort, ditandai sebagai tidak terverifikasi — lihat REQ-011).

> [!NOTE]
> **Catatan revisi v1.3 (2026-09-27):** spec ini diamandemen dari v1.2 sebagai tindak lanjut `docs/audit/clarification-report-balas-pesan-plan-2026-09-27.md` (Iteration 1, Readiness Score 89/100, PROCEED) — tiga koreksi hulu pada sisi AuliaPos, **tanpa mengubah requirement lain**:
>
> 1. `REQ-008`/Section 4.2: rujukan ke kolom `media_status` yang **tidak ada** di skema `messages` diganti ke seam ketersediaan media yang riil (`media_local_filename`, `media_download_attempted_at`, `media_confirmed_gone_at`; `app/Models/MessageModel.php:54-56`, `app/Controllers/Inbox.php:418-499`), dengan aturan tiga-nilai eksplisit untuk `quoted_media_available` (selaras Resolved Item #3).
> 2. `REQ-001`/`REQ-001a`/Section 4.1/4.1.1: penambahan field opsional `fromMe` (boolean, additive, `absent = false`) pada objek `quoted`, karena baris **outgoing** menyimpan `sender_jid = NULL` (`app/Controllers/Inbox.php:2227` teks, `:1076` media) sehingga Gateway butuh `fromMe` eksplisit untuk membentuk `key` Baileys yang benar (selaras Resolved Item #6).
> 3. Section 12: perbaikan wording edge case — snapshot kutipan diambil **server** dari DB saat kirim (bukan dari data client saat pemilihan), konsisten `ALT-002` (`plan-feature-balas-pesan-auliapos-v1.0.md`) dan `REQ-007` (selaras F10).
>
> Tidak ada keputusan arsitektur baru, tidak ada ADR baru, `CONTEXT.md` tidak berubah. Divergensi AC PRD GH-015 tetap terbuka dan bukan lingkup revisi ini.

> [!NOTE]
> **Catatan revisi v1.4 (2026-09-27):** spec ini diamandemen dari v1.3 sebagai tindak lanjut `docs/audit/clarification-report-balas-pesan-spec-v1.3-2026-09-27.md` (Iteration 1, Readiness Score 88/100, PROCEED) — menerapkan sembilan resolusi (F1-1..F1-4, F2-1..F2-4, F3-1) pada `REQ-008`/`REQ-011`/Section 4.1/4.1.1/4.2/4.3/12 dan `AC-005`, **tanpa mengubah requirement lain**:
>
> 1. `quoted_media_available` (F1-1..F1-4): `0` hanya bila `media_local_filename` **kosong** **DAN** `media_confirmed_gone_at` terisi (file lokal menang atas confirmed-gone); `1` dinyatakan sebagai **heuristik** (media-typed, belum confirmed-gone → *potentially available*, **bukan** jaminan); `NULL` diperluas menjadi "bukan pesan media **ATAU** ketersediaan belum/tidak teresolusi"; ditambah **fallback tampilan** (permintaan media sumber `404`/`410`/error → tampil "[Media tidak tersedia]" **tanpa tulis DB**, snapshot tetap beku per `REQ-007`).
> 2. `quoted.fromMe` (F2-1..F2-4): diturunkan **hanya** dari kolom `direction` (`outgoing` → `true`, `incoming` → `false`); **dilarang** menurunkannya dari `sender_jid` (baris incoming juga dapat `sender_jid = NULL`); `quoted.sender_jid` boleh `null`/tidak ada untuk sumber outgoing; JSON `"fromMe": null` identik dengan absent = `false`; untuk `fromMe: true` Gateway mengisi `key.participant` dari JID akun-bot miliknya sendiri.
> 3. Section 4.3/12 (F3-1): lookup snapshot pesan sumber (dua arah, termasuk lookup incoming `REQ-011`) **wajib menyertakan baris soft-deleted** (`withDeleted()` atau query tanpa filter `deleted_at`); `MessageModel::find()`/`first()` polos **dilarang**.
>
> Tidak ada keputusan arsitektur baru, tidak ada ADR baru, `CONTEXT.md` tidak berubah. Divergensi AC PRD GH-015 tetap terbuka dan bukan lingkup revisi ini.

> [!NOTE]
> **Catatan revisi v1.5 (2026-09-27):** spec ini diamandemen dari v1.4 sebagai tindak lanjut `docs/audit/clarification-report-balas-pesan-plans-v1.4-2026-09-27.md` (Iteration 2, Readiness Score 89/100, PROCEED) — menerapkan empat temuan material (F-A..F-D) pada `REQ-001`/`REQ-001a`/`CON-001`/`REQ-011`/`REQ-013` serta Section 4.3/12, **tanpa mengubah requirement lain**:
>
> 1. **F-A (aturan intra-percakapan — Section 4.3 + Section 12):** pesan sumber yang ditunjuk `quoted_message_id` **wajib** berada di `conversation_id` percakapan tujuan; bila tidak, endpoint kirim menolak dengan **`400`**. Ini menutup celah IDOR/lintas-percakapan yang secara semantik adalah **Teruskan** (Out of Scope Section 1.1). `cekOwnership()` yang ada hanya memvalidasi percakapan **tujuan** (`app/Controllers/Inbox.php:984`, `:2157`), sehingga guard baru ini wajib ditambahkan berdampingan.
> 2. **F-B (diskriminator "tidak ditemukan" — `REQ-011`/`REQ-013`):** penanda tunggal "tidak ditemukan" adalah **`quoted_sender_label IS NULL`**. Saat **ditemukan**, `quoted_sender_label` **wajib non-NULL** — memakai `SenderIdentityFormatter::LABEL_FALLBACK` (`"Pengirim"`) bila formatter mengembalikan `null` (mis. baris legacy ber-JID grup, `app/Services/SenderIdentityFormatter.php:23,58-61`). Saat tidak ditemukan, label **tidak** ditulis (tetap `NULL`). UI **dilarang** mendeteksi label generik dengan membandingkan string snippet.
> 3. **F-C (degradasi `quoted` malformed — `REQ-001`/`REQ-001a`/`CON-001`):** `quoted` yang malformed (bukan objek, atau `wa_message_id` kosong) diperlakukan **"tanpa kutipan"** — pesan **tetap dikirim**, respons `sent:true, quote_applied:false`, dan kegagalan pembentukan quote **di-log** ("gagal dengan suara, bukan senyap").
> 4. **F-D (taksonomi kegagalan lookup sumber — Section 4.3):** **`400`** untuk (1) ID sumber yang tidak ada dan (2) ID sumber di percakapan lain (F-A); sumber ber-`wa_message_id` placeholder lokal (`'local-' . bin2hex(...)`, `app/Controllers/Inbox.php:2224`) tetap **dikirim** dan didegradasi lewat `quote_applied:false` (UI menampilkan "Terkirim tanpa kutipan"). Sumber yang sudah soft-deleted tetap diterima. **Tidak ada** kolom snapshot yang ditulis untuk kasus `400`.
>
> Tidak ada keputusan arsitektur baru, tidak ada ADR baru, `CONTEXT.md` tidak berubah. Divergensi AC PRD GH-015 tetap terbuka dan bukan lingkup revisi ini.

> [!NOTE]
> **Catatan revisi v1.6 (2026-09-27):** spec ini diamandemen dari v1.5 sebagai tindak lanjut `ALT-001` di `plan-refactor-balas-pesan-tahap3-v1.0.md` (`TASK-201` remediasi Tahap 3 berhenti karena fallback tampilan `REQ-008`/`AC-005` tidak dapat direalisasikan dengan data yang tersedia) — menutup celah kontrak, **tanpa mengubah requirement lain**:
>
> 1. **Akar masalah:** snapshot kutipan (Section 4.2) hanya menyimpan `quoted_wa_message_id` (ID WhatsApp pesan sumber), sedangkan `GET /inbox/media/(:num)` (`app/Controllers/Inbox.php:386`) butuh **ID lokal** `messages.id`. Tidak ada endpoint publik untuk resolve `wa_message_id → id lokal` dari browser, dan cache thread client (`getByConversation()`, `app/Models/MessageModel.php:139-146`, urut lama→baru dengan limit) tidak reliable sebagai basis fallback pesan lama.
> 2. **Resolusi (REQ-008b baru, Section 4.2):** ditambah kolom snapshot **additive** `quoted_source_message_id` (ID lokal `messages.id` pesan sumber) pada `messages`, diisi di titik yang sama tempat `quoted_wa_message_id` sudah ditulis (`Inbox::resolveKutipan()` untuk balasan kasir, `InboxGatewayApi::resolveKutipanMasuk()` untuk kutipan masuk pelanggan) — **tanpa query tambahan**, karena baris sumber (`$sumber`) sudah ada di tangan pemanggil saat snapshot dirakit. Kolom ini **snapshot beku**, bukan FK hidup — konsisten `Section 9 "Never do"`.
> 3. **`REQ-008` (fallback tampilan) direvisi:** live-fetch media sumber saat tampilan sekarang eksplisit memakai `GET /inbox/media/(:quoted_source_message_id)`, bukan mekanisme yang tidak terdefinisi. Bila `quoted_source_message_id` `NULL` (baris kutipan **legacy** yang dikirim sebelum v1.6, atau kasus "tidak ditemukan" REQ-011), UI **tidak** mencoba live-fetch sama sekali — cukup mengandalkan `quoted_media_available` yang sudah tersimpan (degradasi anggun, bukan error).
> 4. **`AC-005` direvisi** agar selaras dengan mekanisme baru (lihat Section 5).
> 5. **Tidak ada perubahan** pada `quoted_wa_message_id`/`quoted_sender_label`/`quoted_snippet`/`quoted_media_available`, kontrak Gateway (`quoted`/`quote_applied`), atau `REQ-001`–`REQ-007`, `REQ-009`–`REQ-013`.
>
> Tidak ada keputusan arsitektur baru yang memenuhi *Triple Gate* ADR (`.claude/standards/ADR-FORMAT.md`) — migrasi kolom nullable-additive dapat di-rollback trivial (`down()` drop kolom), sehingga **bukan** "hard to reverse"; tidak ada ADR baru, `CONTEXT.md` tidak berubah.

> [!NOTE]
> **Catatan revisi v1.7 (2026-09-27):** spec ini diamandemen dari v1.6 sebagai keputusan `ALT-001`/`REQ-008c` di `plan-refactor-balas-pesan-tahap3-review2-v1.0.md` (Phase 2 tertahan) — kutipan media non-gambar yang **tersedia** dilabeli "[Media tidak tersedia]" karena snapshot v1.6 tidak menyimpan tipe media sumber. **Keputusan: opsi (a)** — tambah kolom snapshot `quoted_media_type` (additive-nullable), **bukan** opsi (b) batasi live-fetch. Alasan: (1) konsisten prinsip snapshot beku `REQ-007`; (2) `quoted_snippet` untuk media bercaption berisi caption, sehingga menebak tipe dari string melanggar semangat F-B ("jangan bandingkan string cuplikan"); (3) opsi (b) menurunkan janji `AC-005(b)` (thumbnail media tersedia) dan sebagian media bercaption tak dapat thumbnail; (4) biaya minimal — satu kolom nullable, diisi **tanpa query tambahan** karena `$sumber['message_type']` sudah di tangan pemanggil. Perubahan:
>
> 1. **`REQ-008c` baru (Section 3 + Section 4.2):** kolom snapshot `quoted_media_type` menyimpan `message_type` sumber media (`image`/`document`/`sticker`/`audio`/`video`); `NULL` untuk sumber teks, sumber tidak ditemukan, atau baris legacy. Diisi di `InboxQuoteSnapshotService::rakitSnapshot()` (satu titik, mencakup kutipan keluar **dan** kutipan masuk).
> 2. **`REQ-008` fallback tampilan direvisi:** dispatch representasi oleh `quoted_media_type` — `image`/`sticker` → `<img>` live-fetch; `document` → tautan; `audio`/`video` → label tanpa fetch; `[Media tidak tersedia]` hanya untuk `quoted_media_available = 0` atau kegagalan `404`/`410`/error nyata.
> 3. **`REQ-013` diperbarui:** komponen kutipan yang sama (balasan kasir **dan** kutipan masuk) memakai dispatch tipe ini.
> 4. **`AC-005` direvisi** menjadi lima cabang (a–e), lihat Section 5.
> 5. **Section 4.2** menambah kolom `quoted_media_type` (`VARCHAR(30) NULL`, `after: quoted_source_message_id`) + migrasi additive baru `AddQuotedMediaTypeToMessages`; **Section 6/7/12/13** diselaraskan.
>
> **Tidak ada** perubahan kontrak Gateway (`quoted`/`quote_applied`), `REQ-001`–`REQ-007`, `REQ-009`–`REQ-012`, maupun nilai `quoted_media_available`. Kolom baru additive-nullable dapat di-rollback trivial (`down()` drop kolom) dan tidak memenuhi *Triple Gate* ADR (`.claude/standards/ADR-FORMAT.md`) — **tidak ada ADR baru**, `CONTEXT.md` tidak berubah (istilah domain "Kutipan" sudah mencakup "label jenis media").

> [!NOTE]
> **Catatan revisi v1.8 (2026-09-27):** spec ini diamandemen dari v1.7 sebagai tindak lanjut `TASK-303`/`SPEC-001` di `plan-refactor-balas-pesan-tahap3-review2-v1.0.md` — menyelaraskan wording `REQ-011` (cabang "tidak ditemukan") dan `AC-009` dengan perilaku `potongSnippet()` yang **membatasi + menormalkan** cuplikan. **Keputusan: perbaiki wording spec, bukan ubah implementasi** — Section 4.2 sudah menjanjikan cuplikan "dipotong ke panjang wajar", dan unit test (`tests/unit/InboxQuoteSnapshotServiceTest.php`) mengunci batas `MAKS_KARAKTER` (200 karakter) + elipsis + normalisasi whitespace; mengubah implementasi ke `mb_substr` murni justru menghapus perilaku yang dijanjikan. Perubahan:
>
> 1. **`REQ-011` (kedua cabang):** frasa "`quoted.snippet` payload Gateway **apa adanya**" diganti menjadi cuplikan yang **dilewatkan lewat `potongSnippet()` yang sama** seperti jalur sumber-ditemukan — satu standar: normalisasi whitespace + batas 200 karakter + elipsis bila terpotong. Bila hasilnya `null` (payload kosong **atau** bukan string — guard tipe `SEC-003`), dipakai label generik **"Pesan tidak ditemukan"**.
> 2. **`AC-009`:** diselaraskan dengan `REQ-011` (bukan lagi "apa adanya").
> 3. **Section 4.2:** penjelasan `quoted_snippet` dipertegas — satu standar pemotongan/normalisasi (`potongSnippet()`) berlaku untuk **kedua** jalur: teks baris sumber lokal (sumber **ditemukan**) **dan** fallback `quoted.snippet` Gateway (sumber **tidak ditemukan**).
> 4. **`ASSUMPTION-007`:** frasa "snippet apa adanya" diselaraskan menjadi "cuplikan yang dipotong + dinormalisasi".
>
> **Tidak ada** perubahan kontrak Gateway (`quoted`/`quote_applied`), `REQ-001`–`REQ-010`, `REQ-012`–`REQ-013`, nilai `quoted_media_available`/`quoted_media_type`, maupun skema kolom. Tidak ada requirement produk baru, tidak ada keputusan arsitektur baru, tidak ada ADR baru, `CONTEXT.md` tidak berubah.

## 2. Definitions

Mengikuti `CONTEXT.md`: **Balas Pesan** — membalas satu pesan spesifik dengan menyertakan kutipan (quote) dari pesan tersebut, memakai fitur reply native WhatsApp. `_Avoid_`: Reply, Quote, Kutip (lihat entri lengkap di `CONTEXT.md`).

Istilah tambahan:

- **Kutipan (Snapshot)**: cuplikan independen (teks/jenis media/nama pengirim) dari pesan yang dibalas, disimpan **sekali** saat balasan dibuat — bukan pointer/foreign key hidup ke `messages.id` yang bisa berubah kalau pesan asli diubah/dihapus.
- **Balasan Gagal-Quote**: kondisi saat Gateway tidak berhasil mengirim pesan sebagai reply native, atau saat hasil pengiriman tidak dapat dipastikan — tiga reaksi berbeda tergantung penyebabnya (lihat REQ-006 reaksi (a), CON-002 reaksi (b), REQ-006a reaksi (c)).

## 3. Requirements, Constraints & Guidelines

### Sisi WA-Gateway (kontrak baru — implementasi ada di repo lain)

- **REQ-001**: `POST {gatewayBaseUrl}/send` menerima field opsional baru `quoted` (objek), berisi seluruh data yang dibutuhkan Baileys untuk membentuk `quoted` message object: `quoted.wa_message_id`, `quoted.sender_jid`, `quoted.message_type`, `quoted.fromMe` (opsional, lihat di bawah), `quoted.text` (untuk teks) atau `quoted.media_type` (untuk media). Field `quoted` **tidak wajib** — request tanpa `quoted` berperilaku seperti sekarang (pesan biasa).
  - **`quoted` malformed → "tanpa kutipan" (F-C)**: bila `quoted` ada tetapi **bukan objek** atau `quoted.wa_message_id` **kosong**, field itu diperlakukan sebagai **"tanpa kutipan"** — pesan **tetap dikirim**, respons `{"sent": true, "quote_applied": false}`, dan kegagalan pembentukan quote **di-log** (tidak senyap). Validasi ketat hanya berlaku untuk field non-`quoted` yang sudah ada; `CON-001` otoritatif: defect kutipan **tidak pernah** menggagalkan pengiriman isi pesan.
  - **`quoted.fromMe` (boolean, opsional, additive; `absent`, JSON `null`, dan `false` diperlakukan identik menjadi `false`)**: menandai apakah pesan sumber adalah pesan **keluaran** (`true`) atau **masukan** (`false`). `fromMe` **hanya** diturunkan dari kolom `direction` baris sumber: `outgoing` → `fromMe: true`, `incoming` → `fromMe: false`. **Dilarang** menurunkan `fromMe` dari `sender_jid` — baris **incoming** juga dapat memiliki `sender_jid = NULL` (pesan grup pra-Tahap-2, dan payload incoming privat tanpa `sender_jid`; validasi hanya untuk grup-incoming — `app/Controllers/InboxGatewayApi.php:85-91`, `:293`), sehingga `sender_jid = NULL` **bukan** bukti pesan keluaran. `quoted.sender_jid` **boleh** `null`/tidak ada untuk pesan sumber outgoing (baris outgoing menyimpan `sender_jid = NULL` by design — `app/Controllers/Inbox.php:2227` untuk teks, `:1076` untuk media). Untuk `fromMe: true`, Gateway mengisi `key.participant` objek Baileys dari JID akun-bot miliknya sendiri (REQ-002), bukan dari `quoted.sender_jid`. Ketika `fromMe` tidak dikirim, Gateway memperlakukannya sebagai `false` (perilaku lama tidak berubah).
- **REQ-001a (Kutipan pada jalur media — Resolved Item #6 Clarification Report Spec)**: `POST {gatewayBaseUrl}/send-media` menerima field opsional `quoted` (objek) dengan struktur **identik** REQ-001 (`quoted.wa_message_id`, `quoted.sender_jid`, `quoted.message_type`, `quoted.fromMe`, `quoted.text`/`quoted.media_type`). Ini mencakup juga kasus **balas-dengan-media sambil mengutip** — kasir mengirim lampiran (gambar/dokumen/stiker) sebagai balasan yang menyertakan kutipan pesan lain. Field ini **tidak wajib**, mengikuti aturan yang sama seperti REQ-001 (termasuk semantik `fromMe` **dan** aturan `quoted` malformed F-C: `quoted` bukan objek / `wa_message_id` kosong → diperlakukan "tanpa kutipan", pesan media tetap dikirim, `sent:true, quote_applied:false`, kegagalan di-log). Lihat Section 4.1.1 untuk contoh payload.
- **REQ-002**: Saat `quoted` ada di request (`/send` maupun `/send-media`, REQ-001a), Gateway membentuk objek pesan Baileys minimal yang cukup untuk parameter `quoted` (`key: {id, remoteJid, fromMe, participant}`, `message: {...}` sesuai `message_type`) dari data yang dikirim AuliaPos — **tanpa** perlu mencari/menyimpan pesan asli di sisi Gateway, sesuai ASSUMPTION-004. Untuk `quoted.fromMe: true` (pesan sumber outgoing), `quoted.sender_jid` tidak dikirim AuliaPos; Gateway mengisi `key.participant` dari JID akun-bot miliknya sendiri.
- **REQ-003**: Response `POST {gatewayBaseUrl}/send` **dan** `POST {gatewayBaseUrl}/send-media` mengembalikan indikator keberhasilan reply native secara eksplisit, mis. `{"sent": true, "quote_applied": true}` vs `{"sent": true, "quote_applied": false}` — supaya AuliaPos tahu pasti apakah kutipan native berhasil diterapkan atau tidak (dibutuhkan REQ-006). `quote_applied` berlaku sama di kedua endpoint (REQ-001a).
- **CON-001**: `quoted` **tidak pernah** memengaruhi pengiriman kalau Gateway gagal membentuknya — dalam kasus itu Gateway **tetap** mengirim isi pesan (tanpa quote), bukan menggagalkan seluruh pengiriman (prinsip "gagal dengan suara, bukan senyap" PRD, tapi pesan pengguna tidak boleh hilang total). Berlaku sama untuk `/send` maupun `/send-media` (REQ-001a). **Otoritatif & mencakup `quoted` malformed (F-C):** `quoted` yang bukan objek atau ber-`wa_message_id` kosong, maupun `quoted` yang gagal dibentuk Baileys, semuanya diperlakukan "tanpa kutipan" → pesan tetap dikirim dengan `quote_applied: false` dan kegagalan **di-log**. Ini berlaku juga untuk sumber ber-`wa_message_id` placeholder lokal (`local-…`, F-D): AuliaPos tetap mengirim `quoted`, Gateway mendegradasi ke `quote_applied:false`.

### Sisi AuliaPos (penyimpanan, UI, idempotensi)

- **REQ-004**: UI thread pesan (`app/Views/inbox/index.php`) menyediakan aksi "Balas" pada setiap bubble pesan (kecuali pesan yang sedang dihapus/gagal kirim) — mengikuti pola tombol aksi per-pesan yang sudah ada di kode saat ini.
- **REQ-005**: Memilih "Balas" menampilkan area kutipan aktif di atas kotak ketik, menunjukkan cuplikan pesan yang akan dikutip (teks terpotong / label jenis media) dan nama pengirim (memakai `sender_jid`/nama kontak yang sudah ada — untuk grup memakai label dari Tahap 2). Tersedia tombol batal untuk melepas pilihan kutipan tanpa mengirim.
- **REQ-006 (Balasan Gagal-Quote — Reaksi (a): kutipan ditolak, pesan tetap terkirim)**: Jika response Gateway menunjukkan `quote_applied: false`, AuliaPos **tidak mengirim ulang otomatis secara diam-diam**. Pesan tetap tersimpan terkirim (isi teks/media berhasil, sesuai CON-001 Gateway), namun AuliaPos menandai pesan itu di UI sebagai "Terkirim tanpa kutipan" agar kasir sadar kutipan tidak sampai ke penerima — bukan berpura-pura kutipan berhasil. Reaksi ini **hanya** berlaku saat Gateway merespons normal dengan `quote_applied: false`; kegagalan HTTP total dan hasil tak pasti ditangani terpisah (CON-002 dan REQ-006a).
- **REQ-006a (Balasan Gagal-Quote — Reaksi (c): hasil ambigu/timeout)**: Jika request kirim bertanda `quoted` berakhir **tidak pasti** — koneksi putus, timeout, atau Gateway tidak mengembalikan respons definitif apa pun — AuliaPos **tidak** menandai pesan sebagai terkirim maupun gagal, dan **tidak** mengirim ulang otomatis. Pesan ditampilkan dengan peringatan **"Hasil belum pasti, jangan kirim ulang dulu"** (pola yang sudah ada di `app/Views/inbox/index.php:2308`), supaya kasir tidak memicu duplikat saat status sebenarnya belum diketahui. Ini reaksi **ketiga** yang melengkapi reaksi (a) `quote_applied: false` (REQ-006) dan reaksi (b) kegagalan HTTP total (CON-002).
- **REQ-007**: `messages` menyimpan kutipan sebagai kolom baru pada baris pesan balasan itu sendiri (lihat Section 4.2) — **snapshot saat itu**, tidak pernah di-refresh ulang dari pesan asli setelahnya (mendukung resolusi "kutipan tetap tampil apa adanya walau pesan asli di-soft-delete").
- **REQ-008**: Kolom `quoted_media_available` (Section 4.2) merekam ketersediaan media pesan sumber **saat balasan dibuat** (snapshot, REQ-007), memakai seam ketersediaan media yang riil di `messages` — `media_local_filename`, `media_download_attempted_at`, `media_confirmed_gone_at` (`app/Models/MessageModel.php:54-56`; `app/Controllers/Inbox.php:418-499`). Aturan nilainya (dua-kondisi untuk `0`, selaras urutan cek endpoint `app/Controllers/Inbox.php:418-438`):
  - `0` — **hanya** bila `media_local_filename` pesan sumber **kosong** **DAN** `media_confirmed_gone_at` terisi (media dipastikan gagal permanen **dan** tidak ada file lokal; pola short-circuit `app/Controllers/Inbox.php:440-450`/`:497-501`). File lokal **menang**: bila `media_local_filename` terisi, nilainya `1` meskipun `media_confirmed_gone_at` juga terisi (kasus HDD dicabut → live-fetch mengembalikan `410` dan menulis `media_confirmed_gone_at` sementara file lokal masih ada). Saat bernilai `0`, UI kutipan menampilkan **"[Media tidak tersedia]"** (persis, sesuai Clarification Report) alih-alih mencoba memuat gambar.
  - `1` — **heuristik, bukan jaminan**: pesan sumber bertipe media dan **belum** confirmed-gone → *potentially available*. Ini benar bila `media_local_filename` terisi (file lokal menang) **atau** pesan masih layak di-live-fetch meskipun `media_download_attempted_at` pernah gagal sementara (`app/Controllers/Inbox.php:418-438`). Perlu diketahui: baris media **outgoing** tidak punya `media_local_filename` dan `media_metadata`-nya bisa `NULL` (`app/Controllers/Inbox.php:1061-1068`), sehingga endpoint dapat mengembalikan `500`; kegagalan muat semacam ini ditangani **fallback tampilan** (lihat butir di bawah), bukan dengan mengubah nilai `1`.
  - `NULL` — bila pesan sumber **bukan** pesan media (teks) **ATAU** ketersediaan media belum/tidak teresolusi (mis. `REQ-011` kasus "tidak ditemukan").
  - **Fallback tampilan (display-time, bukan snapshot) — direvisi v1.7, lihat REQ-008c:** saat kutipan bertipe media (`quoted_media_available` bukan `NULL`) **dan** `quoted_source_message_id` (REQ-008b) **terisi**, representasi yang ditampilkan **ditentukan oleh `quoted_media_type`** (REQ-008c) — **bukan** diasumsikan gambar:
    - `image`/`sticker` → live-fetch `GET /inbox/media/(:quoted_source_message_id)` dan render thumbnail `<img>` (caption `quoted_snippet` boleh ditampilkan di bawahnya untuk `image`; stiker tidak punya caption); bila hasilnya **`404`/`410`/error**, UI menampilkan **"[Media tidak tersedia]"** **tanpa menulis ulang DB** — snapshot dibiarkan beku per `REQ-007`.
    - `document` → tautan (`<a href="…/inbox/media/:quoted_source_message_id" target="_blank">`) dengan label dari `quoted_snippet` (caption bila ada, atau `[Dokumen]`) — **tanpa** `<img>` dan **tanpa** fetch saat render.
    - `audio`/`video` → label jenis (`[Audio]`/`[Video]`) **tanpa** fetch, player, maupun unduhan (konsisten `renderIsiPesan()`).
    - Segala tipe dengan `quoted_media_available = 0` → **"[Media tidak tersedia]"** langsung, tanpa percobaan fetch.
    - Bila `quoted_source_message_id` **`NULL`** (kutipan legacy pra-v1.6, atau kasus "tidak ditemukan" REQ-011) **atau** `quoted_media_type` **`NULL`** (baris legacy pra-v1.7, atau sumber teks), UI **tidak** mencoba live-fetch — cukup memakai `quoted_snippet`/`quoted_media_available` tersimpan tanpa upaya tambahan (lihat REQ-008c dan AC-005).
  - Catatan: **tidak ada kolom `media_status`** pada skema `messages` (`app/Models/MessageModel.php:41-63`) — rujukan lama ke `media_status` dihapus.
- **REQ-008b (v1.6 — ID lokal sumber untuk live-fetch, pemicu: `ALT-001` `plan-refactor-balas-pesan-tahap3-v1.0.md`)**: `messages` menyimpan kolom snapshot tambahan `quoted_source_message_id` (Section 4.2) — ID lokal `messages.id` dari pesan sumber, **snapshot beku** (bukan FK hidup, konsisten `REQ-007`/Section 9 "Never do"), diisi **hanya** saat pesan sumber **ditemukan** di DB lokal AuliaPos, di titik yang sama tempat `quoted_wa_message_id` sudah dirakit:
  - **Kutipan keluar (balasan kasir)**: `Inbox::resolveKutipan()` — `$sumber['id']` (baris sudah tersedia di memori, **tidak ada query tambahan**, selaras `REQ-012`/`GUD-001`).
  - **Kutipan masuk (pelanggan)**: `InboxGatewayApi::resolveKutipanMasuk()` — hanya cabang `$sumber` **ditemukan** (`REQ-011` kasus pertama); cabang "tidak ditemukan" tetap `NULL` (konsisten `quoted_sender_label` yang juga `NULL` pada kasus itu, F-B).
  - Kolom ini memungkinkan UI memanggil `GET /inbox/media/(:num)` (`app/Controllers/Inbox.php:386`, endpoint existing, **tidak ada endpoint baru**) langsung dengan ID lokal yang benar, tanpa perlu resolve `wa_message_id → id` dari browser (yang tidak punya seam publik).
  - **Baris lama (dibuat sebelum migrasi kolom ini dijalankan)** akan punya `quoted_source_message_id = NULL` secara permanen (tidak ada backfill retroaktif — di luar lingkup REQ-008b) — diperlakukan sama seperti kasus "tidak ditemukan" pada REQ-008 (tidak ada live-fetch, hanya snapshot `quoted_media_available`).
- **REQ-008c (v1.7 — fidelitas tipe media kutipan, pemicu: `ALT-001`/`REQ-008c` di `plan-refactor-balas-pesan-tahap3-review2-v1.0.md`)**: `messages` menyimpan kolom snapshot tambahan `quoted_media_type` (Section 4.2) — **tipe media pesan sumber** (`message_type` baris sumber: `image`/`document`/`sticker`/`audio`/`video`), snapshot beku (bukan FK hidup, konsisten `REQ-007`), diisi di titik yang sama tempat snapshot lain dirakit (`InboxQuoteSnapshotService::rakitSnapshot()`, satu titik yang mencakup kutipan keluar **dan** kutipan masuk), **tanpa query tambahan** karena `$sumber['message_type']` sudah ada di tangan pemanggil (selaras `REQ-012`/`GUD-001`):
  - Nilainya `NULL` bila pesan sumber **bukan** pesan media (teks), pesan sumber **tidak ditemukan** (`REQ-011` kasus kedua), atau baris dibuat sebelum migrasi kolom ini (legacy, tanpa backfill retroaktif).
  - UI memakai kolom ini **hanya** untuk memilih representasi tampilan (image/sticker → thumbnail, document → tautan, audio/video → label) — **dilarang** menyimpulkan tipe media dari `quoted_snippet` (caption dapat menggantikan label jenis), konsisten semangat F-B "jangan bandingkan string cuplikan".
  - Kolom ini **tidak** mengubah `quoted_media_available` maupun kontrak `quoted`/`quote_applied` ke Gateway; ia murni metadata snapshot sisi AuliaPos untuk tampilan.
  - `[Media tidak tersedia]` **hanya** muncul untuk kegagalan nyata: `quoted_media_available = 0`, atau live-fetch `image`/`sticker` mengembalikan `404`/`410`/error.
- **REQ-009**: Pengiriman balasan **memakai kembali** mekanisme idempotensi yang sudah ada (`operation_id` dari frontend → `gateway_operation_id` unik, pola identik `kirim()`/`kirimKeConversation()`) — tidak ada mekanisme idempotensi baru.
- **CON-002 (Balasan Gagal-Quote — Reaksi (b): kegagalan HTTP total)**: Kalau `POST /inbox/percakapan/{id}/kirim` (endpoint AuliaPos yang memanggil Gateway) menerima kegagalan HTTP total dari Gateway saat mengirim field `quoted` (bukan `quote_applied: false`, tapi request itu sendiri error), perilaku **sama seperti kegagalan kirim pesan biasa saat ini** — pesan ditandai gagal, kasir bisa coba lagi (tidak ada penanganan khusus tambahan di luar yang sudah ada). Ini berbeda dari reaksi (a) REQ-006 (`quote_applied: false` → tetap terkirim) dan reaksi (c) REQ-006a (hasil tak pasti → jangan kirim ulang dulu).
- **GUD-001**: Kolom kutipan baru (Section 4.2) **nullable**, tidak memengaruhi baris pesan yang bukan balasan (`quoted_*` semuanya `NULL`) — additive terhadap skema `messages` yang ada.

### Sisi AuliaPos — kutipan pada pesan MASUK dari pelanggan (cakupan diperluas)

- **REQ-010**: `POST /api/inbox/gateway/messages` (`InboxGatewayApi::messages()`) menerima field opsional baru `quoted` (objek) pada payload pesan **masuk** (`direction` kosong atau `'incoming'`), berisi `quoted.wa_message_id` (wajib jika objek `quoted` ada), `quoted.sender_jid` (opsional), dan `quoted.snippet` (opsional, teks/label yang menurut Gateway mewakili pesan asli — dipakai hanya sebagai fallback, lihat REQ-011). Field ini **tidak wajib** — payload tanpa `quoted` berperilaku seperti sekarang (pesan biasa, seluruh `quoted_*` tetap `NULL`).
- **REQ-011**: Saat `quoted.wa_message_id` ada, `InboxGatewayApi::messages()` mencari pesan tersebut di tabel `messages` milik AuliaPos sendiri (`WHERE wa_message_id = ?`, seam yang sama dengan `MessageModel::existsByWaMessageId()`) **sebelum** mengisi kolom kutipan pada baris pesan masuk yang baru. Lookup ini **wajib menyertakan baris yang sudah soft-deleted** (`withDeleted()` atau query `db table('messages')` tanpa filter `deleted_at`, pola `findMessageByOperationId()` `app/Controllers/Inbox.php:2285-2303`); `MessageModel::find()`/`first()` polos **dilarang** karena `MessageModel` memakai `useSoftDeletes` (`app/Models/MessageModel.php:38`) — jika tidak, kutipan gagal terbentuk secara diam-diam setelah soft-delete bersamaan:
  - **Ditemukan** → `quoted_wa_message_id`, `quoted_sender_label` (dari `sender_jid`/identitas pengirim baris itu), `quoted_snippet` (dari `text` baris itu, dipotong **dan dinormalisasi** dengan `potongSnippet()` yang sama seperti Section 4.2 — maks. `MAKS_KARAKTER` 200 karakter, multibyte-safe, elipsis bila benar-benar terpotong), dan `quoted_media_available` (dari aturan ketersediaan media REQ-008: `0`/`1`/`NULL`) diisi dari data lokal AuliaPos — **bukan** dari `quoted.snippet` yang dikirim Gateway. **`quoted_sender_label` wajib non-NULL (F-B):** bila `SenderIdentityFormatter::labelFor()` mengembalikan `null` (mis. baris legacy yang `sender_jid`-nya ber-JID grup `@g.us`, `app/Services/SenderIdentityFormatter.php:58-61`), tulis `SenderIdentityFormatter::LABEL_FALLBACK` (`"Pengirim"`, `:23`). Label non-NULL inilah yang membedakan baris "ditemukan" dari baris "tidak ditemukan".
  - **Tidak ditemukan** → `quoted_wa_message_id` tetap diisi (untuk keperluan Section 4.4/tampilan), `quoted_media_available` diisi `NULL` (tidak diketahui), dan `quoted_snippet` diisi dari `quoted.snippet` payload Gateway yang **dilewatkan lewat `potongSnippet()` yang sama** seperti cabang "ditemukan" — dipotong ke `MAKS_KARAKTER` (200 karakter, multibyte-safe, ditambah elipsis bila benar-benar terpotong) **dan** dinormalisasi whitespace (runtutan spasi/baris-baru/tab dipadatkan menjadi satu spasi, ujung di-trim) — **bukan** disimpan mentah "apa adanya" (satu standar pemotongan untuk kedua cabang, selaras Section 4.2). Bila hasil normalisasi `null` — yaitu `quoted.snippet` kosong **atau** bukan string (guard tipe `SEC-003`: array/objek dari Gateway tidak boleh menjadi `TypeError`/`500`) — maka `quoted_snippet` diisi label generik **"Pesan tidak ditemukan"**. Ini **tidak** memblokir/menggagalkan penyimpanan pesan masuk itu sendiri (prinsip "gagal dengan suara, bukan senyap", tapi pesan pelanggan tidak boleh hilang). **`quoted_sender_label` TIDAK ditulis (tetap `NULL`) (F-B)** — inilah **penanda tunggal** status "tidak ditemukan"; UI **dilarang** menyimpulkan status ini dengan membandingkan string `quoted_snippet` terhadap label generik mana pun.
- **REQ-012**: Pencarian REQ-011 **tidak boleh** menambah query baru per pesan pada jalur normal — hanya dijalankan saat `quoted` ada di payload (kondisional), konsisten dengan `GUD-001` Section 3 di atas dan `CL-010`/`GUD-001` M3 Fase 1e (filter/lookup tambahan hanya saat benar-benar dibutuhkan).
- **REQ-013**: UI thread pesan menampilkan kotak kutipan pada bubble pesan **masuk** yang punya `quoted_wa_message_id` terisi, memakai komponen tampilan yang **sama** dengan kotak kutipan pada bubble balasan kasir (REQ-005/REQ-008/REQ-008c) — termasuk **representasi per tipe media** (REQ-008c: image/sticker → thumbnail, document → tautan, audio/video → label) dan placeholder **"[Media tidak tersedia]"** hanya saat `quoted_media_available = 0` atau live-fetch `image`/`sticker` gagal (`404`/`410`/error), serta label generik **"Pesan tidak ditemukan"** bila kutipan tidak ter-resolve ke pesan lokal (REQ-011 kasus kedua). **Penentuan status "tidak ditemukan" (F-B):** UI **wajib** memakai `quoted_sender_label IS NULL` sebagai satu-satunya penanda; bila label terisi (termasuk fallback `"Pengirim"`), kutipan dianggap **ditemukan**. UI **dilarang** mendeteksi label generik dengan membandingkan string `quoted_snippet`. Tidak ada komponen UI baru.

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
    "fromMe": false,
    "text": "Kapan pesanan saya dikirim?"
  }
}
```

Response:

```json
{ "sent": true, "gateway_operation_id": "...", "quote_applied": true }
```

Field `quoted.fromMe` opsional (additive; `absent`, JSON `null`, dan `false` diperlakukan identik menjadi `false`): `true` bila pesan sumber adalah pesan **keluaran** AuliaPos, `false` untuk pesan **masukan** pelanggan. AuliaPos **hanya** menurunkannya dari kolom `direction` baris sumber (Section 4.3) — `outgoing` → `true`, `incoming` → `false` — dan **dilarang** memakai `sender_jid` sebagai dasar (baris incoming juga dapat `sender_jid = NULL`). Untuk pesan sumber outgoing (`fromMe: true`), `quoted.sender_jid` boleh `null`/tidak ada; Gateway mengisi `key.participant` dari JID akun-bot-nya sendiri (REQ-002). Gateway memakai `fromMe` untuk mengisi `key.fromMe` objek Baileys.

### 4.1.1 `POST {gatewayBaseUrl}/send-media` — payload tambahan (REQ-001a)

```json
{
  "operation_id": "...",
  "chat_id": "6281234567890@s.whatsapp.net",
  "message_type": "image",
  "media_base64": "<...>",
  "mimetype": "image/jpeg",
  "caption": "Ini contoh cetakannya ya.",
  "quoted": {
    "wa_message_id": "3EB0XXXX...",
    "sender_jid": "6281234567890@s.whatsapp.net",
    "message_type": "text",
    "fromMe": false,
    "text": "Kapan pesanan saya dikirim?"
  }
}
```

Response:

```json
{ "sent": true, "gateway_operation_id": "...", "quote_applied": true }
```

Field `quoted` di endpoint ini memakai struktur & aturan yang sama seperti Section 4.1 (`quoted` opsional termasuk `quoted.fromMe` yang **hanya** diturunkan dari `direction`; `quoted.sender_jid` boleh `null` untuk sumber outgoing; untuk `fromMe: true` Gateway mengisi `key.participant` dari JID akun-bot-nya sendiri; `quote_applied` dilaporkan sama; CON-001 tetap berlaku). Bedanya hanya jalur pengiriman media (base64, mekanisme yang sudah ada), sehingga mencakup kasus **balas-dengan-media sambil mengutip**. Nama field media (`media_base64`, `mimetype`, `caption`, dst.) mengikuti kontrak `/send-media` yang sudah ada di WA-Gateway dan tidak diubah oleh spec ini.

### 4.2 Migrasi baru: kolom kutipan pada `messages`

```php
'quoted_wa_message_id' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
'quoted_sender_label'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true], // nama/nomor pengirim asli, snapshot
'quoted_snippet'       => ['type' => 'TEXT', 'null' => true], // cuplikan teks, atau label jenis media (mis. "[Foto]")
'quoted_media_available' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true, 'default' => null],
'quoted_source_message_id' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'null' => true, 'default' => null], // v1.6, REQ-008b: ID lokal messages.id pesan sumber, snapshot beku
'quoted_media_type'    => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'default' => null], // v1.7, REQ-008c: message_type sumber media (image/document/sticker/audio/video), snapshot beku
```

`quoted_snippet` diisi **saat balasan dibuat** — bukan dihitung ulang saat ditampilkan (snapshot beku, `REQ-007`). **Satu standar pemotongan** berlaku untuk **kedua** jalur: cuplikan dari `text` baris sumber lokal (sumber **ditemukan**, `REQ-011` cabang pertama) **dan** cuplikan fallback dari `quoted.snippet` Gateway (sumber **tidak ditemukan**, `REQ-011` cabang kedua, dari `REQ-010`). Keduanya dilewatkan lewat `potongSnippet()` (`InboxQuoteSnapshotService`): whitespace dinormalisasi (runtutan spasi/baris-baru/tab → satu spasi, ujung di-trim), teks ≤ `MAKS_KARAKTER` (200 karakter, dihitung multibyte-safe) dikembalikan utuh tanpa elipsis, dan teks yang lebih panjang dipotong pada 200 karakter lalu ditambah elipsis `…`; hasil kosong menjadi `null` (di jalur fallback `REQ-011` digantikan label generik "Pesan tidak ditemukan").

`quoted_media_available` juga disimpan sebagai snapshot saat balasan dibuat, diturunkan dari seam media riil pesan sumber (**bukan** kolom `media_status` — kolom itu tidak ada): `0` hanya bila `media_local_filename` **kosong** **DAN** `media_confirmed_gone_at` terisi (file lokal menang atas confirmed-gone); `1` adalah **heuristik** "media-typed & belum confirmed-gone → *potentially available*" (bukan jaminan); `NULL` bila sumber **bukan** pesan media **ATAU** ketersediaan belum/tidak teresolusi (`app/Models/MessageModel.php:54-56`; `app/Controllers/Inbox.php:418-499`; aturan lengkap di REQ-008, termasuk fallback tampilan saat media gagal dimuat). Nilai ini **tidak** ditulis ulang saat tampilan — kegagalan muat `404`/`410`/error ditangani di UI sebagai "[Media tidak tersedia]" tanpa tulis DB.

`quoted_source_message_id` (v1.6, REQ-008b) menyimpan ID lokal `messages.id` pesan sumber — **bukan** `wa_message_id` — snapshot beku, diisi **hanya** saat pesan sumber ditemukan di DB lokal saat balasan/kutipan masuk dibentuk (REQ-008b). `NULL` berarti sumber tidak ditemukan (REQ-011 kasus kedua) **atau** baris dibuat sebelum migrasi kolom ini ada (baris legacy). Dipakai **khusus** oleh UI sebagai target `GET /inbox/media/(:num)` untuk fallback tampilan REQ-008 — tidak dipakai untuk keperluan lain (bukan FK sungguhan, tidak ada constraint referensial, konsisten prinsip snapshot-only Section 9).

`quoted_media_type` (v1.7, REQ-008c) menyimpan `message_type` pesan sumber **bila sumbernya pesan media** (`image`/`document`/`sticker`/`audio`/`video`) — snapshot beku, diisi **hanya** saat pesan sumber ditemukan di DB lokal dan bertipe media; `NULL` untuk sumber teks, sumber tidak ditemukan, atau baris legacy pra-v1.7 (tanpa backfill). Dipakai **khusus** oleh UI untuk memilih representasi kutipan (thumbnail/tautan/label) tanpa menyimpulkan tipe dari `quoted_snippet` — bukan FK sungguhan, tidak dipakai untuk logika lain (konsisten prinsip snapshot-only Section 9).

### 4.3 Endpoint AuliaPos internal — parameter baru

`POST /inbox/percakapan/{id}/kirim` menerima field opsional `quoted_message_id` (ID lokal `messages.id`, bukan `wa_message_id`) — AuliaPos mengambil data pesan asli dari DB sendiri untuk mengisi `quoted_snippet` dkk. dan membentuk payload Section 4.1 ke Gateway. Pengambilan pesan sumber ini **wajib menyertakan baris soft-deleted** (`withDeleted()`/query tanpa filter `deleted_at`); `MessageModel::find()` polos **dilarang** (akan gagal membentuk snapshot setelah soft-delete bersamaan, lihat Section 12). `quoted.fromMe` (REQ-001) **hanya** diturunkan dari kolom `direction` baris sumber: `outgoing` → `true`, `incoming` → `false` — **dilarang** memakai `sender_jid` (baris incoming juga dapat `sender_jid = NULL`); `quoted.sender_jid` boleh `null` untuk sumber outgoing.

**Aturan intra-percakapan (F-A, WAJIB):** pesan sumber yang ditunjuk `quoted_message_id` **wajib** memiliki `conversation_id` yang **sama** dengan percakapan tujuan (`{id}`). Bila baris sumber tidak ditemukan atau `conversation_id`-nya berbeda, endpoint menolak dengan **`400`** — bukan membentuk kutipan lintas percakapan. Guard ini ditambahkan **berdampingan** dengan `cekOwnership()` yang ada (`app/Controllers/Inbox.php:2157` untuk teks, `:984` untuk media), karena `cekOwnership()` hanya memvalidasi percakapan **tujuan**, bukan keterkaitan pesan sumber. Mengutip lintas percakapan adalah **Teruskan** (Out of Scope Section 1.1); **tidak ada** kolom snapshot yang ditulis untuk kasus `400`.

**Taksonomi kegagalan lookup sumber (F-D):**

| Kondisi pesan sumber | Hasil |
| --- | --- |
| `messages.id` **tidak ada** | **`400`** (tolak; tidak ada snapshot) |
| `conversation_id` **berbeda** dari percakapan tujuan | **`400`** (tolak; F-A — cegah IDOR/lintas percakapan) |
| Baris ada, **sudah soft-deleted** | **Diterima** (lookup soft-delete-inclusive) → kirim berkutipan normal |
| `wa_message_id` = **placeholder lokal** `local-…` (`'local-' . bin2hex(...)`, `app/Controllers/Inbox.php:2224`) | **Diterima** → AuliaPos tetap mengirim `quoted`; Gateway mendegradasi ke `quote_applied:false` (UI: "Terkirim tanpa kutipan", REQ-006) |

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
- **AC-003**: Given Gateway mengembalikan `quote_applied: false` (reaksi (a), REQ-006), When AuliaPos memproses response, Then pesan tetap tersimpan terkirim dan diberi penanda "Terkirim tanpa kutipan" di UI — bukan digagalkan atau dikirim ulang otomatis.
- **AC-003a**: Given request kirim bertanda `quoted` berakhir ambigu/timeout tanpa respons definitif dari Gateway (reaksi (c), REQ-006a), When AuliaPos memproses hasil request, Then pesan **tidak** ditandai terkirim maupun gagal dan ditampilkan dengan peringatan **"Hasil belum pasti, jangan kirim ulang dulu"**, tanpa pengiriman ulang otomatis. (Berbeda dari AC-003 yang memakai `quote_applied: false`; berbeda juga dari CON-002 yang menandai pesan gagal pada kegagalan HTTP pasti.)
- **AC-003b**: Given kasir membalas sebuah pesan dengan lampiran media (balas-dengan-media sambil mengutip), When request dikirim lewat `POST {gatewayBaseUrl}/send-media` dengan field `quoted` (REQ-001a), Then Gateway mengembalikan `quote_applied` seperti pada jalur `/send`, dan pesan media tersimpan dengan `quoted_*` terisi sama seperti balasan teks.
- **AC-004**: Given pesan asli yang dikutip kemudian di-soft-delete, When thread dimuat ulang, Then kutipan pada balasan tetap tampil apa adanya, tanpa penanda "pesan dihapus".
- **AC-005 (direvisi v1.7, REQ-008b/REQ-008c)**: Given kutipan bertipe media, When kutipan ditampilkan, Then representasinya sesuai `quoted_media_type` dan **"[Media tidak tersedia]"** hanya muncul untuk kegagalan nyata, **tanpa** menulis ulang DB (snapshot tetap beku per `REQ-007`) — dengan cabang:
  - **(a)** `quoted_media_available = 0` (snapshot: `media_local_filename` kosong **dan** `media_confirmed_gone_at` terisi) → tampil **"[Media tidak tersedia]"** langsung, tanpa live-fetch (berlaku untuk semua tipe media).
  - **(b)** `quoted_media_type` = `image`/`sticker`, `quoted_media_available = 1`, **dan** `quoted_source_message_id` terisi → live-fetch `GET /inbox/media/(:quoted_source_message_id)` dan render `<img>`; bila **`404`/`410`/error**, Then tampil **"[Media tidak tersedia]"** (sama seperti (a)) **sekali** — kegagalan disimpan di memori klien (`mediaGagal`) agar tidak di-fetch ulang setiap siklus polling (PERF-001, lihat Section 3).
  - **(c)** `quoted_media_type` = `document`, `quoted_media_available = 1`, **dan** `quoted_source_message_id` terisi → tampil **tautan** ke `GET /inbox/media/(:quoted_source_message_id)`, label dari `quoted_snippet` (caption bila ada, atau `[Dokumen]`) — **bukan** `<img>`, tanpa fetch saat render.
  - **(d)** `quoted_media_type` = `audio`/`video` → tampil **label jenis** (`[Audio]`/`[Video]`) tanpa fetch, player, maupun unduhan (konsisten `renderIsiPesan()`), kecuali `quoted_media_available = 0` → cabang (a).
  - **(e)** `quoted_source_message_id` **`NULL`** (kutipan legacy pra-v1.6, atau sumber tidak ditemukan REQ-011) **atau** `quoted_media_type` **`NULL`** (baris legacy pra-v1.7, atau sumber teks) — UI **tidak** mencoba live-fetch sama sekali; tampilan mengikuti `quoted_snippet`/`quoted_media_available` tersimpan apa adanya (batasan yang diketahui, bukan bug).
- **AC-006**: Given kasir mengirim dua balasan dengan `operation_id` yang sama (retry jaringan), When diproses, Then hanya satu baris pesan tersimpan (idempotensi existing tetap berlaku).
- **AC-007**: Given percakapan grup, When kasir membalas pesan anggota tertentu, Then `quoted_sender_label` menampilkan identitas anggota itu (bukan nama grup).
- **AC-008**: Given pelanggan membalas (native reply) salah satu pesan yang **ada** di DB AuliaPos, When `InboxGatewayApi::messages()` memproses payload dengan `quoted.wa_message_id` tersebut, Then baris pesan masuk yang baru tersimpan dengan `quoted_snippet`/`quoted_sender_label`/`quoted_media_available` terisi dari data lokal AuliaPos (bukan dari `quoted.snippet` payload), dan tampil dengan kotak kutipan yang benar di UI.
- **AC-009**: Given pelanggan membalas pesan yang **tidak ditemukan** di DB AuliaPos (mis. lebih tua dari retensi), When payload diproses, Then pesan masuk tetap tersimpan (tidak gagal), dan kotak kutipan menampilkan `quoted.snippet` dari Gateway **yang sudah dipotong + dinormalisasi lewat `potongSnippet()` yang sama** seperti `AC-008` (maks. 200 karakter + elipsis bila terpotong; whitespace dipadatkan) — bukan mentah "apa adanya"; jika `quoted.snippet` kosong **atau** bukan string, ditampilkan label **"Pesan tidak ditemukan"**.

## 6. Test Automation Strategy & Testing Seams

- **Testing Seams**: HTTP boundary `Inbox::kirim()`/method pengiriman terkait (mock respons Gateway dengan `quote_applied` true/false, kegagalan HTTP total, dan timeout/ambigu), `InboxGatewayApi::messages()` untuk kutipan masuk (REQ-010–012), `InboxQuoteSnapshotService` untuk pembentukan snapshot termasuk `quoted_media_type` (REQ-008c), `Inbox::media()` (`GET /inbox/media/(:num)`, seam existing yang dipakai ulang REQ-008b — tidak ada seam baru), dan `MessageModel` untuk penyimpanan kolom kutipan — termasuk lookup soft-delete-inclusive (`REQ-008`/`REQ-011`/Section 12) dan fallback tampilan media saat muat `404`/`410`/error. **Guard intra-percakapan (F-A) dan taksonomi lookup sumber (F-D) diuji di seam endpoint kirim yang sama:** `quoted_message_id` lintas percakapan **atau** ID sumber tidak ada → `400`; sumber ber-`wa_message_id` placeholder lokal → tetap dikirim + `quote_applied:false`; sumber sudah soft-deleted → diterima.
- **Test Levels**: Unit (model, pembentukan `quoted_snippet`, aturan tiga-nilai `quoted_media_available` termasuk dua-kondisi `0`, penulisan `quoted_source_message_id` REQ-008b, penurunan `quoted_media_type` untuk tiap tipe media vs `NULL` untuk teks/tidak ditemukan REQ-008c), Feature (endpoint kirim dengan/tanpa quote, dengan/tanpa lampiran media `/send-media`, dengan quote gagal, kegagalan HTTP total, timeout; **kutip lintas percakapan / ID sumber tidak ada → `400` (F-A/F-D)**; `quoted` malformed → tetap terkirim dengan `quote_applied:false` (F-C); endpoint `messages()` dengan `quoted.wa_message_id` ditemukan vs tidak ditemukan serta jaminan `quoted_sender_label` non-NULL saat ditemukan dan `NULL` saat tidak ditemukan (F-B); lookup pesan sumber yang sudah soft-deleted; render "`[Media tidak tersedia]`" dari snapshot `0` (cabang a), dari live-fetch `GET /inbox/media/(:quoted_source_message_id)` yang gagal untuk `image`/`sticker` (cabang b), maupun dari `quoted_source_message_id`/`quoted_media_type` `NULL` yang melewati live-fetch (cabang e); **render per tipe media (REQ-008c): `image`/`sticker` → `<img>`, `document` → tautan tanpa `<img>`, `audio`/`video` → label tanpa fetch (cabang b/c/d)**).
- **Test Data Management**: Factory pesan dengan `quoted_*` terisi, kasus media tidak tersedia, kasus grup, kasus balas-dengan-media, kasus kutipan masuk (pesan asli ada/tidak ada di DB).
- **Coverage Requirements**: `vendor/bin/phpunit --no-coverage` keluar kode 0.

## 7. Project Structure & Commands

### Project Structure (AuliaPos)

- Migrasi baru: `app/Database/Migrations/<timestamp>_AddQuoteColumnsToMessages.php` (v1.5, sudah ada — `2026-09-27-000001_AddQuoteColumnsToMessages.php`).
- Migrasi tambahan (v1.6, REQ-008b): `app/Database/Migrations/<timestamp>_AddQuotedSourceMessageIdToMessages.php` — kolom `quoted_source_message_id`, additive, `after: quoted_media_available`.
- Migrasi tambahan (v1.7, REQ-008c): `app/Database/Migrations/<timestamp>_AddQuotedMediaTypeToMessages.php` — kolom `quoted_media_type` (`VARCHAR(30) NULL`, tanpa index/FK), additive, `after: quoted_source_message_id`.
- `app/Controllers/Inbox.php` — tambah parameter `quoted_message_id` di endpoint kirim, bentuk payload ke Gateway, proses `quote_applied` dari response; `resolveKutipan()` mengisi `quoted_source_message_id` dari `$sumber['id']` (REQ-008b, v1.6).
- `app/Controllers/InboxGatewayApi.php` — `messages()` menerima `quoted` di payload masuk, resolve `wa_message_id` ke DB lokal (REQ-011), isi `quoted_*` sebelum `$messageModel->insert()`; `resolveKutipanMasuk()` mengisi `quoted_source_message_id` dari `$sumber['id']` saat ditemukan (REQ-008b, v1.6) dan `quoted_media_type` otomatis lewat `rakitSnapshot()` yang sama saat sumber ditemukan (REQ-008c, v1.7).
- `app/Services/InboxQuoteSnapshotService.php` — `rakitSnapshot()` mengisi `quoted_media_type` dari `$sumber['message_type']` bila bertipe media, `NULL` selain itu (REQ-008c, v1.7) — satu titik yang sudah menangani kutipan keluar **dan** masuk.
- `app/Models/MessageModel.php` — tambah `quoted_*` ke `$allowedFields` (v1.6: `quoted_source_message_id`; v1.7: `quoted_media_type`); tambah method lookup by `wa_message_id` untuk kutipan masuk (reuse pola `existsByWaMessageId()` yang sudah ada, ubah jadi mengembalikan baris bukan cuma boolean, atau tambah method baru di sampingnya).
- `app/Views/inbox/index.php` — UI pilih/batal kutip, tampilan kotak kutipan pada bubble (dipakai untuk balasan kasir **dan** pesan masuk pelanggan, REQ-013), penanda "Terkirim tanpa kutipan"; `renderKotakKutipan()` (v1.6/v1.7, REQ-008b/REQ-008c): dispatch per `quoted_media_type` — `image`/`sticker` → `<img>` live-fetch `GET /inbox/media/(:quoted_source_message_id)` (kegagalan dicatat di `mediaGagal` key `m.id` agar tidak di-fetch ulang saat polling, PERF-001), `document` → tautan, `audio`/`video` → label tanpa fetch; `quoted_source_message_id`/`quoted_media_type` `NULL` → tanpa live-fetch, pakai snapshot tersimpan.

### Project Structure (WA-Gateway — repo terpisah, plan terpisah)

- `src/api/ci4Routes.js` — terima field `quoted` di `/send` **dan** `/send-media` (REQ-001a).
- `src/whatsapp/connectionManager.js` — bentuk parameter `quoted` Baileys, kembalikan `quote_applied` untuk jalur `/send` maupun `/send-media`.

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

Tidak ada ADR baru. Kutipan sebagai *snapshot* (bukan referensi hidup) adalah penerapan langsung prinsip "Gateway/DB bukan sumber riwayat yang bisa berubah retroaktif" yang sudah berlaku (`docs/CHAT.md` §2); gagal *Triple Gate Validation* untuk ADR (tidak *surprising* diberi konteks yang sudah ada). Keputusan v1.7 `quoted_media_type` (`ALT-001`/`REQ-008c`) juga tidak memenuhi *Triple Gate*: kolom nullable-additive dapat di-rollback lewat `down()` (bukan *hard to reverse*) dan tidak ada *real trade-off* arsitektural — hanya pemilihan representasi tampilan. Opsi (b) "batasi live-fetch" ditolak karena bergantung pada penebakan tipe dari string cuplikan dan menurunkan jaminan `AC-005(b)`.

## 11. Dependencies & External Integrations

### External Systems

- **EXT-001**: `tikusgot007/WA-Gateway` — wajib merilis dukungan `quoted` di `/send` **dan** `/send-media` sebelum Tahap 3 bisa dirilis penuh di AuliaPos (REQ-001, REQ-001a, REQ-003).

### Third-Party Services

- **SVC-001**: Baileys — opsi `quoted` pada `sendMessage()` adalah API bawaan library.

## 12. Examples & Edge Cases

**Edge case**: Kasir memilih "Balas" pada pesan yang lalu dihapus (soft-delete) oleh kasir lain sebelum tombol kirim ditekan — snapshot kutipan diambil **server** dari DB (`messages`, termasuk baris yang sudah soft-deleted) saat permintaan kirim diproses, **bukan** dari data pesan yang dimuat client saat pemilihan. Client hanya mengirim `quoted_message_id` (Section 4.3), bukan isi kutipan; server yang membentuk snapshot (idem `ALT-002` di `plan-feature-balas-pesan-auliapos-v1.0.md` dan `REQ-007`: snapshot dibuat sekali saat balasan dikirim, tidak pernah di-refetch dari pesan asli setelahnya). **Mekanisme wajib:** lookup pesan sumber — **dua arah**, baik kirim kasir (Section 4.3) maupun `REQ-011` incoming — **MUST** menyertakan baris soft-deleted (`withDeleted()` atau query tanpa filter `deleted_at`, pola `findMessageByOperationId()` `app/Controllers/Inbox.php:2285-2303`); `MessageModel::find()`/`first()` polos **dilarang** karena `MessageModel` memakai `useSoftDeletes` (`app/Models/MessageModel.php:38`) — jika tidak, snapshot gagal terbentuk secara diam-diam setelah soft-delete bersamaan.

**Edge case (F-A — aturan intra-percakapan)**: Kasir (atau request yang dimanipulasi) menunjuk `quoted_message_id` yang berada di **percakapan lain** — mis. kutip dari percakapan A lalu kirim ke percakapan B. Karena `cekOwnership()` hanya memvalidasi percakapan **tujuan** (`app/Controllers/Inbox.php:2157` teks, `:984` media), endpoint kirim **WAJIB** menambahkan guard yang membandingkan `messages.conversation_id` baris sumber dengan percakapan tujuan: ketidakcocokan **atau** ID sumber tidak ada → **`400`**, tanpa menulis snapshot (lihat tabel taksonomi F-D di Section 4.3). Ini mencegah IDOR dan menegakkan batas Out of Scope Section 1.1 (kutip lintas percakapan = **Teruskan**).

**Edge case**: Membalas pesan yang **itu sendiri** adalah pesan hasil Balas Pesan/Teruskan sebelumnya — kutipan yang ditampilkan adalah isi pesan tersebut apa adanya (termasuk teks "↪️ Diteruskan: ..." kalau ada), bukan menelusuri rantai ke pesan paling awal. Tidak ada rantai kutipan bertingkat.

**Edge case (kutipan masuk)**: Pelanggan membalas pesan yang **sama** dua kali secara berurutan (dua pesan masuk berbeda, masing-masing dengan `quoted.wa_message_id` yang sama) — setiap baris pesan masuk baru diproses independen lewat REQ-011, tidak ada dedup/caching hasil lookup lintas pesan (volumenya rendah, tidak butuh optimasi tambahan sesuai `GUD-001`).

**Edge case (v1.6, REQ-008b — kutipan legacy tanpa `quoted_source_message_id`)**: Baris pesan dengan kutipan yang dibuat **sebelum** migrasi `quoted_source_message_id` dijalankan (semua balasan/kutipan masuk Tahap 3 rilis awal) punya kolom ini `NULL` secara permanen — **tidak ada backfill retroaktif**, di luar lingkup REQ-008b. UI memperlakukan `NULL` ini sama seperti kasus "sumber tidak ditemukan": tidak ada percobaan live-fetch, kutipan media hanya mengandalkan nilai `quoted_media_available` yang sudah tersimpan (AC-005 cabang c). Ini bukan regresi — fallback tampilan display-time memang fitur baru v1.6, kutipan lama tetap tampil selama `quoted_snippet`/`quoted_media_available` snapshot-nya masih valid.

**Edge case (v1.6, REQ-008b — pesan sumber di-soft-delete setelah snapshot dibuat)**: `quoted_source_message_id` tetap merujuk ID lokal pesan sumber walau baris itu kemudian di-soft-delete. `Inbox::media()` (`app/Controllers/Inbox.php:391`) memakai `$messageModel->find()` **polos** (bukan soft-delete-inclusive) — bila dipanggil untuk pesan sumber yang sudah soft-deleted, endpoint membalas `404`. Ini **konsisten** dengan AC-005 cabang (b) (`404`/`410`/error → "[Media tidak tersedia]") dan **tidak melanggar** AC-004 (AC-004 menjamin *teks/label* kutipan tetap tampil apa adanya dari snapshot beku, bukan menjamin media bisa di-live-fetch ulang selamanya). Tidak ada perubahan pada `Inbox::media()` yang dibutuhkan untuk kasus ini.

**Edge case (v1.7, REQ-008c — kutipan media bercaption)**: Pesan sumber `image`/`document` yang punya caption, `quoted_snippet`-nya berisi **caption** (bukan label `[Foto]`/`[Dokumen]`) karena `snippetDari()` mengutamakan `text`. Inilah alasan `quoted_media_type` disimpan terpisah: UI tetap tahu untuk merender thumbnail (image) atau tautan (document) tanpa menebak tipe dari isi cuplikan. Untuk `document`, label tautan memakai `quoted_snippet` (caption bila ada, atau `[Dokumen]`).

**Edge case (v1.7, REQ-008c — baris legacy tanpa `quoted_media_type`)**: Baris kutipan yang dibuat **sebelum** migrasi `quoted_media_type` punya kolom ini `NULL` secara permanen (tanpa backfill retroaktif) — termasuk kutipan media yang sah. UI memperlakukannya sebagai kasus AC-005 cabang (e): tidak ada live-fetch, tampilan mengikuti `quoted_snippet`/`quoted_media_available` apa adanya. Ini degradasi anggun, bukan regresi; tidak ada `[Media tidak tersedia]` palsu selama `quoted_media_available = 1`, dan tidak ada gambar rusak karena tidak ada `<img>` yang dibuat.

## 13. Validation Criteria

- `vendor/bin/phpunit --no-coverage` hijau 100%.
- Manual check: kirim balasan dari AuliaPos, verifikasi tampil sebagai reply native di WhatsApp (HP uji).
- Manual check: matikan dukungan quote di Gateway (simulasi `quote_applied:false`), pastikan pesan tetap terkirim dengan penanda yang benar.
- Manual check: paksa timeout/putus koneksi saat mengirim balasan berkutipan (simulasi reaksi (c)), pastikan muncul peringatan "Hasil belum pasti, jangan kirim ulang dulu" dan tidak ada pengiriman ulang otomatis.
- Manual check: kirim balasan berlampiran media sambil mengutip lewat `/send-media`, pastikan kutipan tersimpan (`quoted_*` terisi) dan `quote_applied` ditangani sama seperti jalur `/send`.
- Manual check: kirim payload `POST /api/inbox/gateway/messages` dengan `quoted.wa_message_id` yang ada dan yang tidak ada di DB, pastikan kedua kasus REQ-011 berjalan sesuai AC-008/AC-009.
- Manual check: balas pesan yang lalu di-soft-delete sebelum kirim, pastikan snapshot tetap terbentuk (lookup soft-delete-inclusive, Section 12) dan kutipan tampil apa adanya.
- Manual check: paksa media kutipan gagal dimuat saat tampilan (`404`/`410`/error) dan/atau `quoted_media_available = 0`, pastikan muncul **"[Media tidak tersedia]"** tanpa perubahan nilai `quoted_media_available` di DB (snapshot beku, `REQ-007`/`REQ-008`).
- Manual check (v1.6, REQ-008b): kirim balasan berkutipan media, verifikasi `quoted_source_message_id` terisi dengan `messages.id` pesan sumber yang benar; hapus/rename file media lokal sumber lalu paksa `GET /inbox/media/(:quoted_source_message_id)` gagal, pastikan kutipan tampil "[Media tidak tersedia]" (AC-005 cabang b) tanpa menyentuh `quoted_media_available`.
- Manual check (v1.6, REQ-008b): pada baris kutipan lama (dibuat sebelum migrasi `quoted_source_message_id`, `quoted_source_message_id IS NULL`), pastikan UI **tidak** memanggil `GET /inbox/media/(:num)` sama sekali dan langsung memakai `quoted_media_available` tersimpan (AC-005 cabang c).
- Manual check (v1.7, REQ-008c): balas pesan sumber berurutan `image`, `sticker`, `document`, `audio`, dan `video` yang tersedia, lalu verifikasi kutipan masing-masing: `image`/`sticker` tampil thumbnail, `document` tampil tautan (bukan gambar rusak), `audio`/`video` tampil label — dan **tidak ada** "[Media tidak tersedia]" untuk media yang sebenarnya tersedia.
- Manual check (v1.7, REQ-008c): verifikasi `quoted_media_type` terisi `message_type` sumber untuk kelima tipe media dan `NULL` untuk sumber teks; pada baris kutipan lama (dibuat sebelum migrasi, `quoted_media_type IS NULL`), pastikan UI tidak mencoba live-fetch dan tetap menampilkan cuplikan snapshot (AC-005 cabang e), bukan "[Media tidak tersedia]".

## 14. Related Specifications / Further Reading

- [`spec-index.md`](./spec-index.md)
- [`spec-design-grup-tahap1-tab-inbox.md`](./spec-design-grup-tahap1-tab-inbox.md)
- [`spec-design-grup-tahap2-identitas.md`](./spec-design-grup-tahap2-identitas.md)
- [`spec-design-teruskan.md`](./spec-design-teruskan.md) — tahap berikutnya, berinteraksi dengan fitur ini (lihat Clarification Report resolusi #6)
- `docs/CHAT.md` §6 (Media), §18 (Developer Invariants)
