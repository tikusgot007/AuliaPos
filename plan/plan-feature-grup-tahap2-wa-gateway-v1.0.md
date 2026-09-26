---
goal: Grup Tahap 2 (WA-Gateway) — kirim identitas pengirim dan nama grup pada payload pesan grup masuk
version: 1.0
date_created: 2026-09-26
last_updated: 2026-09-26
owner: WA-Gateway (tikusgot007/WA-Gateway, repo terpisah)
status: 'Planned'
tags: [feature, wa-gateway, grup, tahap2, baileys]
---

# Introduction

![Status: Planned](https://img.shields.io/badge/status-Planned-yellow)

Rincian eksekusi **sisi WA-Gateway** untuk `spec/spec-design-grup-tahap2-identitas.md` (v1.3): membuat pesan grup **masuk** membawa `sender_jid` (identitas pengirim, dari `key.participant`) dan `group_name` (subject grup, dari `groupMetadata().subject`) pada payload `POST /api/inbox/gateway/messages` yang dikirim ke AuliaPos. Plan ini adalah **plan terpisah** untuk repo `tikusgot007/WA-Gateway` (Node.js/Baileys) — bukan repo AuliaPos — sesuai PRD Section 9.1 dan `spec-index.md` ("dua plan terpisah" karena siklus rilis berbeda).

> [!IMPORTANT]
> **Plan ini blocking untuk plan AuliaPos.** Per `CON-004`/`EXT-001`, Gateway **wajib naik lebih dulu**. Sisi AuliaPos Tahap 2 mewajibkan `sender_jid` untuk pesan grup masuk dan menolak `400` tanpa menyimpan apa pun; Gateway lama membuat setiap pesan grup ditolak dan hilang (tanpa outgoing queue, `docs/CHAT.md` §5/§18). Urutan rollout: **Gateway naik dulu → AuliaPos naik**. Urutan rollback: **Gateway turun dulu**.

> [!NOTE]
> **Akses repo.** Sesi AuliaPos ini tidak memiliki working copy `tikusgot007/WA-Gateway`; plan ini ditulis untuk dieksekusi di repo tersebut. Semua anchor file merujuk kode publik repo `tikusgot007/WA-Gateway` (branch `master`): `src/whatsapp/connectionManager.js`. Sebelum menulis kode, `/sdlc-write-code` **wajib** mengonfirmasi working copy yang otoritatif (lokasi checkout) — pola yang sama dipakai `plan/plan-bugfix-wa-gateway-pairing-code-logged-out-v1.0.md` (`ASSUMPTION-001`).

## 1. Requirements & Constraints

- **REQ-001**: Untuk pesan masuk dengan `jid_type = 'group'`, payload `POST /api/inbox/gateway/messages` menyertakan field baru `sender_jid` (JID anggota pengirim, `key.participant`, format `<nomor>@s.whatsapp.net` atau `@lid`) yang **wajib diisi**. Untuk pesan `pn`/`lid` field ini **tidak dikirim** (sender sudah implisit dari `chat_id`).
- **REQ-002**: Untuk pesan masuk `jid_type = 'group'`, payload menyertakan `group_name` (subject grup saat ini) — **hanya bila Gateway berhasil mendapatkannya**. Bila gagal/cache kosong, field **tidak dikirim** (bukan string kosong), supaya AuliaPos dapat membedakan "belum ada info" dari "nama grup kosong".
- **REQ-003**: `group_name` dikirim **di setiap pesan masuk grup** (bukan sekali saat grup pertama dikenali), memberi AuliaPos kesempatan berulang mengisi nama yang sebelumnya gagal didapat (mendukung `REQ-006` sisi AuliaPos). Nilai yang berbeda (admin grup ganti nama) tetap dikirim apa adanya; AuliaPos yang memutuskan write-once (`REQ-006`).
- **CON-001**: `sender_jid`/`group_name` **tidak pernah** dikirim untuk `jid_type` selain `'group'` (Additive, tidak breaking untuk kontrak pesan pribadi).
- **GUD-001 (rekomendasi)**: `groupMetadata()` memakai strategi cache (lihat `ASSUMPTION-003`), **bukan** panggilan sinkron tanpa cache di jalur kritis penerimaan tiap pesan. Implementasi caching sepenuhnya wewenang plan ini.
- **ASSUMPTION-002** *(dari spec)*: Baileys menyediakan `key.participant` untuk pesan grup masuk dan `groupMetadata(jid).subject` untuk nama grup (`SVC-001`). Keduanya **ditambahkan** di plan ini — `connectionManager.js` saat ini **belum** mengekstrak `participant` maupun subject grup.
- **ASSUMPTION-003** *(dari spec)*: `groupMetadata()` di-cache in-memory per JID grup sesi, di-refresh hanya saat cache miss atau interval wajar (mis. 1 jam) — bukan setiap pesan.
- **Keputusan pemilik (2026-09-26)**: `sender_jid` wajib untuk pesan grup **masuk** (`incoming`). Pesan grup **keluar** yang disinkronkan (`fromMe=true`, `direction='outgoing'`) **tidak** diwajibkan membawa `sender_jid`; plan ini **tidak** berusaha mengekstrak `participant` untuk `fromMe` (perilaku Baileys untuk `fromMe` tidak diandalkan, dan AuliaPos menerima outgoing grup tanpa `sender_jid`).
- **Rollout/rollback (`CON-004`/`EXT-001`)**: Gateway naik dulu, turun dulu.

## 2. Implementation Steps

> **EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> Jalankan plan ini phase demi phase. Jalankan task **VERIFY** di akhir setiap phase. Setelah diuji, **STOP DAN TUNGGU** persetujuan eksplisit user sebelum lanjut. Definition of Done repo ini adalah harness Node yang sudah ada: `node test/simulate-group-identity.js` (baru) **dan** regresi `node test/simulate-*.js` / `node test/check-*.js` keluar kode **0** — `vendor/bin/phpunit` tidak berlaku di repo Node.js ini (berlaku hanya untuk plan AuliaPos).

### Implementation Phase 1 — Vertical Slice: "Payload pesan grup masuk membawa identitas pengirim & nama grup"

- **GOAL-001**: Setiap **pesan grup masuk** yang dipos Gateway ke AuliaPos membawa `sender_jid` (wajib) dan `group_name` (bila diketahui, dengan cache); pesan non-grup sama sekali tidak berubah.

| Task     | Description                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              | Ref ID                    | Dep      | Files | Completed | Date |
| -------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------- | -------- | ----- | --------- | ---- |
| TASK-001 | Di `src/whatsapp/connectionManager.js` (`_handleIncomingMessage`), untuk pesan dengan `jid_type === 'group'` **dan** arah incoming: ekstrak `key.participant` dari pesan Baileys dan tambahkan ke objek `normalized` sebagai `sender_jid`. Untuk `jid_type` selain `'group'` **tidak** menambahkan field ini (CON-001). **Kontrak `direction` (eksplisit, lintas-repo):** setiap payload pesan grup **wajib** menyertakan `direction` yang benar — `fromMe === true` → `'outgoing'`, selain itu → `'incoming'`; Gateway **tidak boleh** mengirim pesan grup tanpa `direction` (AuliaPos default `'incoming'` — lihat `ASSUMPTION-002`; tanpa ini pesan grup `fromMe` salah-anggap incoming dan hilang `400`). Bila `participant` tidak tersedia untuk pesan grup masuk, catat log jelas (pesan akan ditolak `400` oleh AuliaPos — kegagalan yang terlihat, bukan senyap). DoD: `node test/simulate-group-identity.js` exit 0. Test: grup masuk terisi `sender_jid`; pesan `pn`/`lid` tanpa field ini; setiap pesan grup memuat `direction` yang benar (`fromMe` → `'outgoing'`, selain itu `'incoming'`); `direction='outgoing'` tidak diwajibkan `sender_jid`. | REQ-001, CON-001          | -        | 1     |           |      |
| TASK-002 | Di `src/whatsapp/connectionManager.js`, untuk pesan grup masuk: ambil nama grup dari `groupMetadata(jid).subject` dengan **cache in-memory per JID** (map `{jid: subject}` per sesi; refresh saat cache miss atau interval wajar, mis. 1 jam) — bukan panggilan jaringan tiap pesan (GUD-001, `ASSUMPTION-003`). **Pada CACHE MISS, `groupMetadata()` dijalankan FIRE-AND-FORGET:** pesan diteruskan **segera TANPA `group_name`**, dan cache diisi **di latar** untuk pesan berikutnya — **bukan** ditunggu inline di jalur kritis penerimaan pesan (tanpa latensi tambahan, tanpa risiko pesan tertahan menuju `400`). Tambahkan ke `normalized` sebagai `group_name` **hanya bila nilainya sudah tersedia di cache** (bukan string kosong); bila `groupMetadata()` gagal/timeout, field **tidak** dikirim dan pesan tetap diteruskan (REQ-002). Kirim `group_name` di **setiap** pesan grup masuk (REQ-003); **retry terjadi otomatis pada pesan grup berikutnya** saat cache sudah terisi (mendukung REQ-003). DoD: `node test/simulate-group-identity.js` exit 0. Test: cache miss pertama → `group_name` absen namun pesan terkirim segera, pesan grup berikutnya (cache sudah terisi) → `group_name` terkirim; pesan grup JID sama saat cache terisi → `groupMetadata()` **tidak** dipanggil ulang (cache); kegagalan metadata → `group_name` absen namun pesan tetap terkirim; non-grup tanpa `group_name`. | REQ-002, REQ-003, GUD-001 | TASK-001 | 1     |           |      |
| TASK-003 | **VERIFY**: tulis dan jalankan `test/simulate-group-identity.js` (stub `makeWASocket`, pola `test/simulate-outgoing-idempotency.js`): (a) pesan grup masuk dengan cache **terisi** → payload memuat `sender_jid` + `group_name`; (a2) cache **miss** → pesan terkirim **segera tanpa `group_name`** (fire-and-forget), lalu pesan grup berikutnya memuat `group_name`; (b) pesan `pn` masuk → tidak memuat keduanya (CON-001); (c) pesan grup JID sama saat cache terisi → `groupMetadata()` **tidak** dipanggil ulang (cache `ASSUMPTION-003`); (d) `groupMetadata()` gagal → `group_name` absen, pesan tetap terkirim; (e) pesan grup `direction='outgoing'` → tidak diwajibkan `sender_jid`; (f) **assertion kontrak `direction`**: setiap payload pesan grup memuat `direction` yang benar — `fromMe === true` → `'outgoing'`, selain itu → `'incoming'` (tidak pernah absen). Lalu jalankan regresi `node test/simulate-*.js` dan `node test/check-*.js` yang menyentuh `connectionManager.js` — semua harus **tetap PASS tanpa modifikasi** (assertion baru bersifat additive). | -                         | TASK-002 | 2     |           |      |
| TASK-004 | **APPROVAL + RELEASE GATE**: Tunggu konfirmasi eksplisit user, lalu deploy/rilis perubahan Gateway ini **sebelum** AuliaPos Tahap 2 dirilis (`CON-004`). Catat bukti versi Gateway terpasang untuk checklist rilis plan AuliaPos (`TASK-008` plan AuliaPos). | CON-004                   | TASK-003 | -     |           |      |

## 3. Alternatives

- **ALT-001**: Memanggil `groupMetadata()` langsung (sinkron, tanpa cache) di setiap pesan grup — ditolak (`GUD-001`, `ASSUMPTION-003`): panggilan jaringan per pesan pada grup ramai berisiko rate-limit dan memperlambat throughput incoming.
- **ALT-002**: Mengirim `group_name` sebagai string kosong saat gagal mendapatkannya — ditolak (`REQ-002`): AuliaPos harus bisa membedakan "belum ada info" dari "nama grup memang kosong".
- **ALT-003**: Mengekstrak `participant` untuk pesan grup `fromMe` supaya `sender_jid` selalu terisi — ditolak (keputusan pemilik 2026-09-26): semantik Baileys untuk `fromMe` tidak diandalkan dan tidak dibutuhkan; AuliaPos menerima outgoing grup tanpa `sender_jid` dan menampilkan label "Staff (WA Web/HP)" (`AC-011`).

## 4. Dependencies

- **DEP-001**: `baileys` (v6.x) — `key.participant` dan `groupMetadata(jid).subject` (`SVC-001`); tidak ada library baru.
- **DEP-002**: `src/whatsapp/jidUtils.js` (`classifyJid()`) — read-only, sudah dipakai untuk menentukan `jid_type`; tidak diubah.
- **DEP-003**: Harness test Node yang sudah ada (`test/simulate-*.js`, `test/check-*.js`) sebagai basis script baru dan regresi.
- **DEP-004 (koordinasi rilis)**: Rilis Gateway harus mendahului rilis AuliaPos (`CON-004`); plan AuliaPos memblokir `TASK-009` sampai bukti Gateway terpasang.

## 5. Files

- **FILE-001**: `src/whatsapp/connectionManager.js` — ekstraksi `key.participant` → `sender_jid`; subjek grup ter-cache → `group_name`; penambahan field ke payload `normalized` hanya untuk grup masuk.
- **FILE-002**: `test/simulate-group-identity.js` (baru) — test otomatis untuk TASK-001/002 dan regresi kontrak non-grup.

## 6. Testing

- **TEST-001 (otomatis)**: `node test/simulate-group-identity.js` keluar **0**, mencakup (a) grup masuk dengan cache terisi → `sender_jid` + `group_name`; (a2) cache miss → pesan terkirim segera **tanpa** `group_name` (fire-and-forget), pesan berikutnya memuat `group_name`; (b) non-grup → kedua field absen (CON-001); (c) `groupMetadata()` tidak dipanggil ulang saat cache terisi; (d) kegagalan metadata → `group_name` absen, pesan tetap terkirim; (e) outgoing grup tidak diwajibkan `sender_jid`; (f) setiap pesan grup memuat `direction` yang benar (`fromMe` → `'outgoing'`, selain itu → `'incoming'`).
- **TEST-002 (regresi)**: `node test/simulate-*.js` dan `node test/check-*.js` tetap PASS tanpa perubahan assertion (additive only) — memastikan jalur incoming/outgoing non-grup tidak berubah.
- **TEST-003 (integrasi manual)**: setelah Gateway naik, kirim pesan uji ke satu grup WhatsApp uji dan verifikasi payload/log memuat `sender_jid` untuk pesan masuk dan `group_name` saat metadata tersedia; verifikasi pesan pribadi tidak membawa field tambahan.
- **Catatan DoD**: repo ini Node.js — **bukan** `vendor/bin/phpunit`. Gate `vendor/bin/phpunit --no-coverage` adalah Definition of Done plan AuliaPos, bukan plan ini.

## 7. Risks & Assumptions

- **ASSUMPTION-002 (tinggi)**: `key.participant` tersedia untuk pesan grup masuk dan `groupMetadata(jid).subject` mengembalikan subject. **Kontrak lintas-repo (arah):** setiap payload pesan grup **wajib** menyertakan `direction` yang benar (`fromMe` → `'outgoing'`, selain itu → `'incoming'`); AuliaPos default `'incoming'` bila absen (`InboxGatewayApi.php:71`), sehingga pesan grup `fromMe` tanpa `direction` akan salah-anggap incoming dan **hilang `400`**. Kontrak ini di-assert di TASK-003 (f). Keduanya API standar Baileys, tetapi **belum pernah dipakai** di `connectionManager.js` saat ini; TASK-001/002 adalah penambahan baru, bukan mengaktifkan yang sudah ada. Bila `participant` kosong untuk sebagian pesan grup masuk, Gateway harus mencatat log jelas (pesan akan `400` di AuliaPos) — kegagalan yang terlihat, bukan senyap.
- **ASSUMPTION-003 (sedang)**: Cache `groupMetadata()` per JID sesi; TTL/strategi refresh adalah keputusan implementasi (mis. 1 jam). **Pada cache miss, `groupMetadata()` dijalankan fire-and-forget** — pesan diteruskan segera tanpa `group_name`, cache diisi di latar; **tidak ada opsi "menunggu inline"**. Bila nama grup berubah di WhatsApp, nama baru hanya terkirim setelah cache refresh — AuliaPos tetap write-once, jadi tidak ada dampak pada judul setelah terisi pertama kali.
- **RISK-001 (operasional, tinggi)**: Bila AuliaPos Tahap 2 dirilis sebelum Gateway ini naik, seluruh pesan grup ditolak `400` dan hilang. Mitigasi: TASK-004 (release gate) dan checklist `CON-004` di plan AuliaPos.
- **RISK-002 (diterima)**: `groupMetadata()` bisa gagal/lambat; desain cache + opsi `group_name` absen memastikan pesan grup tetap terkirim walau nama belum didapat (jalur judul fallback "Grup" di AuliaPos).
- **ASSUMPTION-001 (proses)**: Working copy otoritatif repo `tikusgot007/WA-Gateway` harus dikonfirmasi sebelum implementasi (lihat catatan di Introduction).
- **Tanpa ADR baru**: keputusan di plan ini adalah penerapan kontrak yang sudah disetujui spec, bukan trade-off arsitektur baru (spec Section 10).

## 8. Related Specifications / Further Reading

- [`spec-design-grup-tahap2-identitas.md`](../spec/spec-design-grup-tahap2-identitas.md) (v1.3)
- [`spec-index.md`](../spec/spec-index.md) — "Urutan Pengerjaan Wajib" / dua plan terpisah
- [`plan-feature-grup-tahap2-auliapos-v1.0.md`](./plan-feature-grup-tahap2-auliapos-v1.0.md) — repo kedua (dependent)
- [`prd-20260926-0024-whatsapp-grup-balas-teruskan.md`](../prd-20260926-0024-whatsapp-grup-balas-teruskan.md) v1.1 (Section 8.1, Section 9.1)
- `docs/CHAT.md` §5 (no outgoing queue), §18 (Developer Invariants)
- `plan/plan-bugfix-wa-gateway-pairing-code-logged-out-v1.0.md` — preseden struktur plan/test repo WA-Gateway

## 9. Rollback / Recovery Plan

Per `CON-004`, urutan rollback = **Gateway turun dulu**, baru AuliaPos.

1. **Gateway turun dulu**: `git revert` commit plan ini di repo `tikusgot007/WA-Gateway` (`src/whatsapp/connectionManager.js` dan `test/simulate-group-identity.js`), lalu deploy ulang Gateway versi sebelumnya. Tidak ada perubahan skema/`.env`/`auth/`, jadi tidak ada risiko kehilangan data Gateway (SQLite Gateway hanya buffer reliability, bukan sumber kebenaran).
2. **Konsekuensi jendela**: setelah Gateway lama + AuliaPos baru, pesan grup ditolak `400` dan tidak tersimpan; **minimalkan durasi** dan lakukan pada jam sepi. Segera lanjutkan langkah 3.
3. **AuliaPos turun**: jalankan rollback plan AuliaPos (`plan-feature-grup-tahap2-auliapos-v1.0.md`, Section 9).
4. Jalankan ulang `node test/simulate-*.js`/`check-*.js` di repo Gateway untuk memastikan kembali hijau setelah rollback.
