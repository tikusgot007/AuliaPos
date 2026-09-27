---
goal: Balas Pesan (Tahap 3, WA-Gateway) — kontrak quoted di /send dan /send-media, quote_applied, ekstraksi kutipan masuk
version: 1.0
date_created: 2026-09-27
last_updated: 2026-09-27
owner: AuliaPos Inbox module (WA-Gateway consumer contract)
status: 'Completed'
tags: [feature, whatsapp, balas-pesan, tahap3, wa-gateway]
---

# Introduction

![Status: Completed](https://img.shields.io/badge/status-Completed-green)

Rincian eksekusi **sisi WA-Gateway** (`tikusgot007/WA-Gateway`, repo terpisah) untuk `spec/spec-design-balas-pesan.md` (v1.5, GH-015). Plan AuliaPos ada di `plan/plan-feature-balas-pesan-auliapos-v1.0.md`; plan ini adalah pasangan yang **wajib ter-deploy lebih dulu** (`EXT-001`).

> [!NOTE]
> **Catatan revisi plan (2026-09-27, diterapkan tanpa ubah requirement):** menindaklanjuti `docs/audit/clarification-report-balas-pesan-plans-v1.4-2026-09-27.md` (Iteration 2, 89/100, PROCEED) dan spec **v1.5**. **F-C** ditegaskan pada TASK-001/002: `quoted` malformed (bukan objek, atau `wa_message_id` kosong) → pesan/media **tetap dikirim**, `quote_applied:false`, kegagalan **di-log** (`CON-001`). Tidak mengubah `AC`/`REQ`; `CONTEXT.md`/ADR tidak berubah.

> [!IMPORTANT]
> **Repo terpisah.** Working copy `tikusgot007/WA-Gateway` **tidak ada di sesi ini** (repo di komputer lain). Fakta teknis di plan ini bersumber dari spec v1.5 (diverifikasi dari kode publik branch `master` atas izin pemilik proyek): `src/api/ci4Routes.js`, `src/whatsapp/connectionManager.js`. Eksekusi task di sini dilakukan di repo Gateway, bukan di repo AuliaPos.

> [!WARNING]
> **ASSUMPTION-004 (spec, HIGH RISK)**: Baileys mendukung reply native lewat opsi `quoted` pada `sock.sendMessage(jid, content, { quoted: originalMsgObject })`, menghasilkan `contextInfo.stanzaId`/`participant`/`quotedMessage` pada pesan terkirim. Ini API standar Baileys, **belum diverifikasi** pada versi Baileys yang dipakai Gateway saat ini. `TASK-001`/`TASK-002` harus mencoba native **lebih dulu**, dengan fallback aman: quote gagal **tidak boleh** menggagalkan pengiriman isi pesan (spec `CON-001`).

## 1. Requirements & Constraints

**Diimplementasikan di plan ini (sisi WA-Gateway):**

- **REQ-001**: `POST /send` menerima field opsional baru `quoted` (objek) berisi `quoted.wa_message_id`, `quoted.sender_jid`, `quoted.message_type`, `quoted.text` (teks) atau `quoted.media_type` (media). Field opsional `quoted.fromMe` diperlakukan identik untuk `absent`, JSON `null`, dan `false` (semuanya = `false`); untuk `fromMe: true` Gateway mengisi `key.participant` dari JID akun-bot-nya sendiri (`quoted.sender_jid` tidak dikirim untuk sumber outgoing). Request tanpa `quoted` berperilaku seperti sekarang.
- **REQ-001a**: `POST /send-media` menerima `quoted` dengan struktur **identik** REQ-001, mencakup balas-dengan-media-sambil-mengutip.
- **REQ-002**: Saat `quoted` ada, Gateway membentuk objek pesan Baileys minimal (`key: {id, remoteJid, fromMe, participant}`, `message: {...}`) dari data AuliaPos — **tanpa** mencari/menyimpan pesan asli di sisi Gateway.
- **REQ-003**: Response **kedua** endpoint menyertakan `quote_applied: true|false` secara eksplisit.
- **CON-001**: `quoted` **tidak pernah** menggagalkan pengiriman kalau Gateway gagal membentuknya — Gateway tetap mengirim isi pesan (tanpa quote), bukan menggagalkan seluruh pengiriman (prinsip "gagal dengan suara, bukan senyap", tetapi pesan pengguna tidak boleh hilang total).
- **REQ-010 (konsekuensi arah masuk)**: Gateway mengirim objek `quoted` pada payload `POST /api/inbox/gateway/messages` saat pelanggan membalas (native reply) salah satu pesan: `quoted.wa_message_id` (wajib jika ada), `quoted.sender_jid` (opsional), `quoted.snippet` (opsional, best-effort). Field ini opsional — payload tanpa kutipan berperilaku seperti sekarang.

**Acceptance Criteria yang dipetakan:** `AC-002` (reply native tampil di WhatsApp penerima), `AC-003b` (jalur `/send-media` berkutipan), `AC-008`/`AC-009` (kutipan masuk terkirim Gateway ke AuliaPos).

## 2. Implementation Steps

> **EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> Jalankan plan ini di repo `tikusgot007/WA-Gateway`, phase demi phase. Jalankan task **VERIFY** di akhir setiap phase dan **STOP DAN TUNGGU** persetujuan eksplisit user sebelum lanjut. Karena repo tidak ada di sesi ini, eksekusi dilakukan di sesi terpisah pada komputer yang memiliki working copy Gateway.

### Implementation Phase 1 — Contract outbound: `quoted` pada `/send` dan `/send-media`

- **GOAL-001**: Gateway menerima `quoted` di kedua endpoint, mencoba reply native Baileys, dan selalu melaporkan `quote_applied`; kegagalan quote tidak pernah menghilangkan isi pesan.

| Task     | Description                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         | Ref ID                       | AC Ref             | Dep      | Files | Completed | Date |
| -------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------- | ------------------ | -------- | ----- | --------- | ---- |
| TASK-001 | Di `src/api/ci4Routes.js`: endpoint `/send` membaca field opsional `quoted` (**F-C**: `quoted` yang **malformed** — bukan objek, atau `quoted.wa_message_id` kosong — diperlakukan **"tanpa kutipan"**: pesan **tetap dikirim**, respons `{"sent": true, "quote_applied": false}`, dan kegagalan pembentukan quote **di-log** ("gagal dengan suara, bukan senyap"); validasi ketat hanya untuk field non-`quoted` yang sudah ada). Teruskan ke `connectionManager` yang membentuk objek pesan Baileys minimal (`key: {id, remoteJid, fromMe, participant}`, `message` sesuai `message_type`), di mana `key.fromMe` diisi dari `quoted.fromMe`, dan untuk `quoted.fromMe: true` Gateway mengisi `key.participant` dari JID akun-bot miliknya sendiri (`quoted.sender_jid` **tidak** dikirim AuliaPos untuk sumber outgoing); field `quoted.fromMe` diperlakukan identik untuk `absent`, JSON `null`, dan `false` (semuanya = `false`). Objek itu dimasukkan sebagai opsi `quoted` pada `sock.sendMessage(...)`. Response menyertakan `quote_applied: true` bila opsi native diterapkan, `false` bila tidak — **termasuk** kasus `quoted` malformed (F-C) — **tetap** kirim isi pesan (`CON-001`). Ukur/tandai kegagalan quote di log tanpa menggagalkan request. DoD (repo Gateway): test otomatis/lint sesuai konvensi repo Gateway hijau; request `/send` tanpa `quoted` tidak berubah. | REQ-001, REQ-002, REQ-003, CON-001 | AC-002         | -        | 1-2   | ✅        | 2026-09-27 |
| TASK-002 | Di `src/api/ci4Routes.js` + `src/whatsapp/connectionManager.js`: endpoint `/send-media` menerima `quoted` dengan struktur identik TASK-001 (termasuk semantik `fromMe`: `absent`/JSON `null`/`false` identik `false`; `fromMe: true` → `key.participant` dari JID akun-bot Gateway; dan aturan **F-C**: `quoted` malformed — bukan objek / `wa_message_id` kosong — diperlakukan **"tanpa kutipan"**: media **tetap dikirim**, `sent:true, quote_applied:false`, kegagalan di-log) dan menerapkannya di jalur pengiriman media (base64 yang sudah ada). Response menyertakan `quote_applied` sama (termasuk `false` untuk kasus malformed F-C). Tanpa `quoted`, perilaku tidak berubah. DoD: test/lint repo Gateway hijau; balas-dengan-media berkutipan menghasilkan `quote_applied`. | REQ-001a, REQ-002, REQ-003, CON-001 | AC-003b, AC-002    | TASK-001 | 1-2   | ✅        | 2026-09-27 |
| TASK-003 | Ekstraksi kutipan **arah masuk** (konsekuensi `REQ-010`): saat memproses pesan masuk dari Baileys, baca `contextInfo` (`stanzaId`/`participant`/`quotedMessage`) dan, bila ada, sertakan objek `quoted: { wa_message_id, sender_jid, snippet }` pada payload webhook ke AuliaPos (`POST /api/inbox/gateway/messages`) — `snippet` best-effort (teks terpotong atau label jenis media). Bila tidak ada kutipan, field **tidak** dikirim (payload lama). DoD: pesan masuk dengan native reply menghasilkan `quoted` pada payload webhook; tanpa reply, payload tidak berubah. | REQ-010                      | AC-008, AC-009     | TASK-001 | 1-2   | ✅        | 2026-09-27 |
| TASK-004 | **VERIFY + APPROVAL**: uji `/send` dan `/send-media` berkutipan terhadap Baileys asli — verifikasi reply native **benar-benar** tampil di WhatsApp penerima (`AC-002`) dan catat apakah native tersedia (menutup `ASSUMPTION-004`); uji payload masuk dari pelanggan yang membalas → `quoted` terkirim (`AC-008`). Catat versi/commit Gateway yang ter-deploy sebagai bukti gate rilis untuk plan AuliaPos (`EXT-001`). Tunggu persetujuan eksplisit user sebelum plan ini ditutup. | -                            | AC-002, AC-003b, AC-008, AC-009 | TASK-002, TASK-003 | -     | ✅        | 2026-09-27 |

### Bukti Verifikasi `EXT-001` (gate rilis untuk plan AuliaPos)

> [!IMPORTANT]
> **Commit ter-deploy: `a2ba409`** — `feat(chat): native reply quoting (Balas Pesan) on /send and /send-media` (repo `tikusgot007/WA-Gateway`, branch `master`; 8 file, +731/-17). Plan AuliaPos **wajib** menunjuk build/commit ini atau yang mewarisi perubahannya sebelum认为自己 siapEWORK penuh.

Verifikasi dilakukan terhadap **Baileys sungguhan** (bukan simulasi) memakai nomor uji `628563324637`, Gateway dijalankan di PC (revision `a2ba409`).

| AC / Kebutuhan | Bukti |
| --- | --- |
| `ASSUMPTION-004` (native tersedia) | **TERTUTUP** — opsi `quoted` Baileys 6.7.24 terbukti nyata: `POST /send` berkutipan → `quote_applied: true`; `contextInfo` masuk terbaca (`stanzaId`/`participant`/`quotedMessage`) |
| `AC-002` (reply native teks) | Respons `quote_applied: true`; **kotak kutipan tampil** di WhatsApp penerima berisi "Halo, ini pesan pembuka dari Gateway PC (uji balas pesan)." |
| `AC-003b` (balas-dengan-media berkutipan) | `POST /send-media` → `quote_applied: true` + `media_ref` terbentuk; **kotak kutipan tampil** pada pesan gambar + caption |
| `CON-001` + `F-C` (degradasi) | 3 varian malformed (bukan objek / `wa_message_id` kosong / `message_type` tak dikenal) → `sent: true` + `quote_applied: false`, **tetap terkirim tanpa kotak kutipan** |
| "gagal dengan suara, bukan senyap" | Log `[SEND] kutipan (quoted) tidak dapat dibentuk -- pesan tetap dikirim tanpa kutipan (CON-001)` |
| `AC-008` (kutipan masuk) | Native reply pelanggan "Oi" → Gateway membentuk `quoted: {wa_message_id, sender_jid, snippet:"Halo"}`; **kotak kutipan tampil** di sisi pelanggan juga |
| `REQ-003` (replay idempotensi) | `quote_applied` dipersistensi di baris operasi; replay `operation_id` melaporkan indikator sama **tanpa** kirim ulang ke Baileys |
| `EXT-001` (rollout aman) | Delivery ke AuliaPos sukses (`pending=0`, pesan id 313 tersimpan, `send_status=received`) — AuliaPos **mengabaikan** field `quoted` (whitelist kolom eksplisit di `app/Controllers/InboxGatewayApi.php:288-300`), **tanpa 400 / error SQL** |
| DoD (test/lint) | **26/26** `simulate-*` + `check-*` hijau (DB terisolasi per test), termasuk `test/simulate-reply-quote.js` baru (15 skenario) |

> [!NOTE]
> **Perilaku request tanpa `quoted` tidak berubah**: field `quote_applied` **hanya** ikut di respons bila request memang membawa `quoted`, sehingga respons lama (`{success, state, replayed, wa_message_id, timestamp}`) tetap identik — inilah yang membuat `test/simulate-outgoing-idempotency.js` (yang mem asserts daftar key persis) tetap hijau tanpa perubahan.

> [!WARNING]
> **Penyimpangan file terhadap Section 5 (Files) plan, dan alasannya.** Section 5 hanya mencantumkan FILE-001/002, tetapi `TASK-003` (dan `REQ-003` pada jalur replay) **tidak dapat** dit accomplishing tanpa menyentuh store & delivery. `DEP-001` menunjuk repo terpisah, bukan batasan file keras.
>
> - `src/store/incomingBuffer.js` + `src/delivery/incomingDelivery.js` — `TASK-003` butuh `quoted` bertahan sebagai kolom `quoted_json` (buffer harus durable; kalau hanya dipayload sekali, retry setelah restart kehilangan kutipan).
> - `src/delivery/outgoingOperationService.js` + `src/store/outgoingOperations.js` — `REQ-003`(explicit) & `TASK-001` soal `quote_applied` **pada respons**; dengan replay idempotensi, respons dibangun ulang dari baris operasi, jadi nilai `quote_applied` harus ikut tersimpan (kolom additive `quote_applied`, tri-state 1/0/NULL, migrasi `ALTER TABLE` idempoten). Tanpa ini, replay akan melaporkan `quote_applied:false` walau kutipan sebenarnya sudah terkirim —lees menyesatkan kasir.
> - `test/simulate-outgoing-store.js` — assertion skema diperbarui ke 14 kolom (menambahkan `quote_applied`); **bukan** pelemahan assertion, kontrak skemanya memang berubah.
>
> Kolom `quoted` **sengaja tidak** ikut dalam `computePayloadHash` (idempotensi), sehingga `payload_hash` request yang sudah ada **tidak berubah** dan operasi `in_flight` milik AuliaPos **tidak** ikut ter-abort saat Gateway naik versi.

## 3. Alternatives

- **ALT-001**: AuliaPos mengirim objek pesan asli lengkap ke Gateway alih-alih `quoted` minimal — **ditolak** (`REQ-002`): bentuk minimal sudah cukup untuk parameter `quoted` Baileys dan menjaga Gateway tetap bukan sumber riwayat.
- **ALT-002**: Gateway menyimpan/mencari pesan asli untuk membangun quote — **ditolak** (invariant `docs/CHAT.md` §18: Gateway bukan sumber kebenaran).
- **ALT-003**: Menolak request bila `quoted` gagal dibentuk — **ditolak** (`CON-001`): isi pesan pengguna tidak boleh hilang total.

## 4. Dependencies

- **DEP-001**: Repo `tikusgot007/WA-Gateway` (Node.js/Baileys) — working copy di komputer lain; eksekusi di sesi terpisah.
- **DEP-002**: Versi Baileys yang mendukung opsi `quoted` pada `sendMessage()` (`ASSUMPTION-004`); bila tidak, `quote_applied: false` tetap benar dan fitur tidak menggagalkan pengiriman (fallback `CON-001`).
- **DEP-003**: AuliaPos mengirim field `quoted` sesuai spec Section 4.1/4.1.1 (plan AuliaPos `TASK-002`/`TASK-006`) — tanpa itu, `quote_applied` selalu `false`.

## 5. Files

- **FILE-001**: `src/api/ci4Routes.js` — terima `quoted` di `/send` dan `/send-media`; sertakan `quote_applied` di respons; bentuk payload webhook masuk dengan `quoted`.
- **FILE-002**: `src/whatsapp/connectionManager.js` — bentuk objek `quoted` Baileys, jalankan `sendMessage(..., { quoted })`, kembalikan keberhasilan quote; ekstraksi `contextInfo` arah masuk.
- **FILE-003**: Test/lint sesuai konvensi repo Gateway.

## 6. Testing

- **TEST-001 (otomatis, repo Gateway)**: request `/send` dan `/send-media` dengan/tanpa `quoted`; respons `quote_applied` benar; payload webhook masuk menyertakan `quoted` saat ada native reply, tidak menyertakannya saat tidak ada.
- **TEST-002 (manual, HP uji)**: balasan berkutipan tampil sebagai reply native di WhatsApp penerima (`AC-002`); balas-dengan-media berkutipan berfungsi (`AC-003b`).
- **TEST-003 (bukti gate rilis)**: catat versi/commit Gateway yang ter-deploy + hasil uji positif, dipakai plan AuliaPos `TASK-015`.

## 7. Risks & Assumptions

- **ASSUMPTION-004 (CLOSED 2026-09-27)**: ketersediaan opsi `quoted` Baileys **terbukti nyata** — diverifikasi terhadap source Baileys 6.7.24 yang terpasang (`lib/Types/Message.d.ts:211` `quoted?: WAMessage`; `lib/Utils/messages.js:487-507`) dan terhadap pengiriman nyata (`quote_applied: true`). Fallback `CON-001` tetap dipertahankan sebagai jaring pengaman.
- ~~**RISK-001**~~: **TIDAK TERJADI** — `quoted` tersedia, sehingga Balas Pesan berjalan sebagai reply native sungguhan, bukan pesan biasa. Degradasi `quote_applied: false` tetap ada sebagai jaring pengaman kalau versi Baileys di masa depan berubah.
- **RISK-002 (celah spec, MASIH TERBUKA)**: `Section 7` spec tidak menyebut ekstraksi `quoted` arah masuk di Gateway, padahal `REQ-010` menuntutnya (`TASK-003`). Dicatat untuk `/sdlc-audit-consistency`.
- **Tidak ada ADR baru; `CONTEXT.md` tidak berubah** (spec Section 10).

## 8. Related Specifications / Further Reading

- [`spec-design-balas-pesan.md`](../spec/spec-design-balas-pesan.md) (v1.5)
- [`plan-feature-balas-pesan-auliapos-v1.0.md`](./plan-feature-balas-pesan-auliapos-v1.0.md) — pasangan (AuliaPos, dirilis setelah plan ini)
- [`spec-index.md`](../spec/spec-index.md)
- `docs/CHAT.md` §5 (Outgoing), §18 (Developer Invariants)

## 9. Rollback / Recovery Plan

1. Rollback Gateway **dilakukan lebih dulu** pada urutan pemulihan, tetapi karena ini repo terpisah, `git revert` dilakukan di repo `tikusgot007/WA-Gateway` untuk `src/api/ci4Routes.js` dan `src/whatsapp/connectionManager.js`.
2. Setelah Gateway kembali ke versi lama, field `quoted` dari AuliaPos diabaikan → pesan tetap terkirim **tanpa kutipan** (tidak hilang, tidak error); payload masuk tidak lagi membawa `quoted` → seluruh kolom `quoted_*` pesan masuk baru tetap `NULL`.
3. Tidak ada migrasi database di sisi Gateway untuk plan ini.
4. Jalankan kembali test/lint repo Gateway untuk memastikan hijau setelah rollback.
