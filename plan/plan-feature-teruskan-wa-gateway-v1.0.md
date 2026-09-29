---
goal: Teruskan (Tahap 4, WA-Gateway) — field forward di /send dan /send-media, forward_marker_applied, penanda native Baileys dengan fallback teks
version: 1.0
date_created: 2026-09-28
last_updated: 2026-09-29
owner: AuliaPos Inbox module (WA-Gateway consumer contract)
status: 'Completed'
tags: [feature, whatsapp, teruskan, tahap4, wa-gateway]
---

# Introduction

![Status: Completed](https://img.shields.io/badge/status-Completed-brightgreen)

Rincian eksekusi **sisi WA-Gateway** (`tikusgot007/WA-Gateway`, repo terpisah) untuk `spec/spec-design-teruskan.md` (v1.3, GH-016). Plan AuliaPos ada di `plan/plan-feature-teruskan-auliapos-v1.0.md`; plan ini adalah pasangannya dan **wajib naik lebih dulu** (`EXT-001`).

> [!IMPORTANT]
> **Cara kerja repo (pola `fix/media-download-classification`, sudah dipakai dua kali sebelumnya).** Working copy `C:\projects\WA-Gateway` adalah folder **LIVE** (branch `master` @ `1591512`, proses berjalan). **JANGAN** mengedit di sana. Buat worktree terpisah:
>
> ```powershell
> git -C C:\projects\WA-Gateway worktree add C:\Projects\wa-gateway-worktrees\teruskan -b feature/teruskan-forward-marker master
> ```
>
> Semua pengeditan dan pengujian dilakukan di worktree itu. Folder `auth/` **tidak boleh disentuh** (batas perubahan di repo ini). Merge ke `master` + restart proses hanya setelah pemilik memberi lampu hijau — bukan bagian dari plan ini.

> [!NOTE]
> **`ASSUMPTION-005` (spec) sudah bisa diverifikasi sebagian, langsung di repo ini.** Gateway memakai `baileys` 6.7.24. Bukti di source terpasang: `node_modules/baileys/lib/Utils/messages.js:244-257` menetapkan `contextInfo = { forwardingScore, isForwarded: true }` begitu `forwardingScore > 0`, dan `:465-473` menggabungkan `contextInfo` tingkat pesan ke konten sebelum dikirim. Artinya penanda native **tidak memerlukan** `quoted` sama sekali — justru yang diminta `CON-001`. Yang tersisa hanya **verifikasi kirim nyata** (TASK-005), yang akan menutup assumption ini seperti `ASSUMPTION-004` ditutup pada Tahap 3.

> [!WARNING]
> **Keputusan pemilik (2026-09-28, sesi `/sdlc-plan-tasks`):** request yang membawa `forward` **dan** `quoted` sekaligus ditolak dengan **`400`** (bukan didiamkan). Gagal dengan suara, bukan senyap — konsisten dengan gaya `CON-001` Tahap 3 pada `quoted` malformed.

## 1. Requirements & Constraints

**Diimplementasikan di plan ini (sisi WA-Gateway):**

- **REQ-001**: `POST /send` menerima field opsional `forward` (boolean, default `false`). Saat `true`, Gateway menandai pesan sebagai forwarded lewat Baileys.
- **REQ-001a**: `POST /send-media` menerima field opsional `forward` dengan struktur dan aturan **identik** REQ-001 — inilah jalur Teruskan lampiran.
- **REQ-002**: Bila native-forward berhasil dibentuk, Gateway **tidak menambah teks apa pun** ke isi pesan. Bila gagal/tidak tersedia, Gateway menyisipkan prefix **"↪️ Diteruskan: "** ke `text` (media: ke `caption`).
- **REQ-003**: Response **kedua** endpoint menyertakan `forward_marker_applied: "native" | "text_fallback"`, agar AuliaPos tahu metode yang dipakai untuk log/debug. Field ini **tidak** memengaruhi UI kasir (`REQ-008` spec: label AuliaPos berdiri sendiri).
- **CON-001**: `forward` **tidak pernah** dikombinasikan dengan `quoted` dalam satu request. Kalau keduanya datang, Gateway menolak `400` (keputusan pemilik).
- **GUD-001 (terapan)**: Penolakan forward audio/video tetap ditegakkan di sisi AuliaPos (server AuliaPos), karena Gateway tidak tahu asal-usul pesan. Plan ini tidak menambah validasi tipe pesan.

**Batas yang harus dijaga (dari spec `Section 1.1` dan `docs/CHAT.md` §18):**

- Gateway **tetap bukan sumber kebenaran** — tidak menyimpan salinan pesan, tidak mencari pesan asli, tidak membuat percakapan baru.
- Request **tanpa** `forward` harus berperilaku **persis seperti sebelumnya**, termasuk bentuk respons lama (`test/simulate-outgoing-idempotency.js` menguji daftar key secara persis).

**Acceptance Criteria yang dipetakan (spec `Section 5`):** `AC-001`, `AC-002` (bukti native di HP uji), `AC-008`, `AC-009`, `AC-010`.

## 2. Implementation Steps

> **EXECUTION DIRECTIVE FOR AI AGENTS (`/sdlc-write-code`):**
> Jalankan plan ini di **worktree** `C:\Projects\wa-gateway-worktrees\teruskan` (branch `feature/teruskan-forward-marker`), **bukan** di folder live. Jalankan seluruh skrip `test/simulate-*.js` dan `test/check-*.js` di akhir setiap phase, lalu **STOP DAN TUNGGU** persetujuan eksplisit owner. Jangan menyentuh `auth/`. Jangan merge ke `master` tanpa lampu hijau pemilik.

### Implementation Phase 1 — Kontrak `forward` di kedua endpoint

- **GOAL-001**: Gateway menerima `forward` di `/send` dan `/send-media`, memasang penanda diteruskan (native kalau bisa, prefix teks kalau tidak), selalu melaporkan `forward_marker_applied`, dan request biasa tidak berubah satu byte pun.

| Task     | Description                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          | Ref ID                | AC Ref                 | Dep      | Files | Completed | Date |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | --------------------- | ---------------------- | -------- | ----- | --------- | ---- |
| TASK-001 | Di `src/api/ci4Routes.js` (route `/send`, `:48`) dan `src/whatsapp/connectionManager.js` (`sendTextMessage()` `:1138` / `sendReply()` `:1194`): baca field opsional `forward` (boolean). `forward` yang **bukan boolean** diperlakukan `false` dan ke-log (pola degradasi `F-C` yang dipakai `quoted`, bukan penolakan) — konten pesan pengguna tidak boleh hilang karena field tambahan. Native marker: teruskan `forwardingScore` ke Baileys lewat opsi `contextInfo` pada konten, sehingga `normalizeMessageContent()` (`Utils/messages.js:244-257`) memasang `contextInfo.isForwarded = true` — **tanpa** `quoted`, jadi `CON-001` tetap aman di sisi Baileys juga. Bila native gagal/tidak tersedia → sisipkan prefix `"↪️ Diteruskan: "` di depan `text`, tandai `forward_marker_applied: "text_fallback"`, dan ke-log alasannya. Response gaining `forward_marker_applied` bernilai `"native"` atau `"text_fallback"` **hanya** bila request memang meminta `forward` — request tanpa `forward` menghasilkan response yang **identik** dengan sekarang. `forward` + `quoted` bersamaan → `400` `FORWARD_WITH_QUOTED` (keputusan pemilik). DoD: seluruh `test/simulate-*.js` + `test/check-*.js` hijau, termasuk `simulate-outgoing-idempotency.js` (daftar key response tidak berubah). | REQ-001, REQ-002, REQ-003, CON-001 | AC-001, AC-008, AC-002 | -        | 2     | ✅ | 2026-09-28 |
| TASK-002 | Di `src/api/ci4Routes.js` (route `/send-media`, `:161`) dan `sendMediaReply()`: `forward` dengan struktur dan aturan **identik** TASK-001 pada jalur media base64 yang sudah ada; prefix fallback disisipkan ke `caption` (bukan `text`), dan caption kosong tetap sah. `forward_marker_applied` dilaporkan sama di respons. `forward` + `quoted` → `400` dengan kode yang sama. Tanpa `forward`, jalur media tidak berubah sama sekali (termasuk validasi WebP sticker dan batas caption). DoD: seluruh skrip simulasi hijau; `simulate-send-media.js` dan `simulate-sticker.js` tetap lulus tanpa perubahan. | REQ-001a, REQ-002, REQ-003, CON-001 | AC-009, AC-010, AC-008 | TASK-001 | 2     | ✅ | 2026-09-28 |
| TASK-003 | Di `src/delivery/outgoingOperationService.js` + `src/store/outgoingOperations.js`: simpan `forward_marker_applied` pada baris operasi keluar lewat **ALTER TABLE idempoten** (pola kolom `quote_applied` yang sudah ada, tri-state `'native'` / `'text_fallback'` / `NULL`), supaya **replay** `operation_id` melaporkan marker yang sama tanpa mengirim ulang. Kolom `forward` **sengaja tidak** ikut `computePayloadHash` (pola `quoted`), sehingga `payload_hash` request yang sudah ada tidak berubah dan operasi `in_flight` milik AuliaPos **tidak** ter-abort saat Gateway naik versi. Perbarui `toHttpResponse()` agar menyertakan field tersebut hanya bila diminta. DoD: `node test/simulate-outgoing-store.js` hijau **tanpa** melemahkan assertion — skema tabel memang berubah, jadi assertion-nya ikut diperbarui ke jumlah kolom terbaru. | REQ-003, REQ-010 (idempotensi) | AC-001, AC-008 | TASK-001, TASK-002 | 2 | ✅ | 2026-09-28 |
| TASK-004 | Test `test/simulate-forward.js` (baru, mengikuti gaya `test/simulate-reply-quote.js`): (a) `/send` dengan `forward: true` menghasilkan `forward_marker_applied: "native"` dan payload Baileys ber-`contextInfo.forwardingScore`; (b) `/send-media` dengan `forward: true` sama, prefix fallback menyentuh `caption`; (c) `forward` + `quoted` → `400` pada kedua endpoint; (d) `forward` non-boolean → diperlakukan `false` + log, pesan tetap terkirim; (e) request **tanpa** `forward` → response **tanpa** `forward_marker_applied` dan daftar key persis seperti lama; (f) replay `operation_id` melaporkan marker yang sama tanpa kirim ulang. Jalankan **seluruh** `test/simulate-*.js` + `test/check-*.js` (repo ini tidak punya `npm test`; setiap skrip dijalankan `node test/<nama>.js`) dan pastikan semuanya hijau. | REQ-001, REQ-001a, REQ-003, CON-001 | AC-001, AC-008, AC-009 | TASK-003 | 1-2   | ✅ | 2026-09-28 |
| TASK-005 | **VERIFY + APPROVAL**: kirim nyata ke nomor uji lewat Gateway yang sedang berjalan dengan `forward: true` pada `/send` **dan** `/send-media`, lalu periksa di WhatsApp HP uji bahwa pesan tampil **bertanda diteruskan** oleh WhatsApp (bukan teks prefix saja). Catat hasilnya: kalau native berhasil, `forward_marker_applied: "native"` dan **`ASSUMPTION-005` tertutup**; kalau tidak, catat bukti teks fallback yang benar-benar tampil. **Catat commit ter-deploy sebagai bukti `EXT-001`** untuk plan AuliaPos (pola bukti `a2ba409` pada plan Balas Pesan). Verifikasi bahwa request tanpa `forward` tetap berperilaku persis seperti sebelum perubahan. Tunggu persetujuan eksplisit owner sebelum plan ini ditutup. | -                      | AC-002, AC-008, AC-009, AC-010 | TASK-004 | -     | ✅ (kirim nyata terverifikasi; merge/deploy masih terpisah — lihat catatan) | 2026-09-28 |

> [!NOTE]
> **Bukti verifikasi TASK-005 (kirim nyata, 2026-09-28).** Instance Gateway sementara dijalankan dari worktree ini (`C:\Projects\wa-gateway-worktrees\teruskan`, commit `960a41d`, port `3100` terpisah dari live `3000`, `CI4_BASE_URL` sengaja dikosongkan supaya tidak menyentuh Inbox AuliaPos sungguhan), login pakai nomor uji `62881082323928`, kirim ke nomor uji kedua `628563324637`:
>
> - `POST /send` dengan `forward: true` → `forward_marker_applied: "native"`, `wa_message_id: 3EB074B703208CA672CFD5`.
> - `POST /send-media` (gambar) dengan `forward: true` → `forward_marker_applied: "native"`, `wa_message_id: 3EB0D73A734AA82E78B8D4`.
> - **Dikonfirmasi visual oleh pemilik** (screenshot WhatsApp Web): kedua pesan tampil dengan label **"↪️ Forwarded"** asli dari WhatsApp di atas bubble, isi teks/caption bersih tanpa prefix apa pun — **`ASSUMPTION-005` resmi tertutup** (native forward Baileys terbukti bekerja pada pengiriman nyata, bukan cuma bukti source).
> - Instance uji dihentikan setelah verifikasi; Gateway live (`C:\projects\WA-Gateway`, port 3000) **tidak pernah dihentikan/disentuh** selama proses ini.
> - **`EXT-001` BELUM terpenuhi**: bukti di atas berasal dari branch `feature/teruskan-forward-marker` (worktree, belum merge), **bukan** commit yang ter-deploy ke `master`/proses live. Merge ke `master` + restart Gateway live masih menunggu **lampu hijau eksplisit terpisah** dari pemilik sebelum plan ini bisa ditandai `Completed` dan sebelum plan AuliaPos boleh mulai.

> [!NOTE]
> **Closure update (2026-09-29) — `EXT-001` satisfied, plan closed.** The Gateway change was merged to `master` and deployed to the live runtime as commit `4a766d2` (`C:\projects\WA-Gateway`, port 3000). The release gate is therefore met: this plan moves to `status: 'Completed'`, and its AuliaPos counterpart (`plan-feature-teruskan-auliapos-v1.0.md`) is no longer blocked. The "`EXT-001` BELUM terpenuhi" line above is preserved as the dated state at TASK-005 verification time, not the current status.

## 3. Alternatives

- **ALT-001**: Menggunakan jalur forward bawaan Baileys (`{ forward: <WAMessage>, force: true }`, `Utils/messages.js:321-323`) — **ditolak**: jalur itu menuntut Gateway membentuk ulang objek pesan asli, sehingga Gateway mulai memegang isi pesan (melanggar `docs/CHAT.md` §18) dan ruang lingkupnya lebih besar. Opsi `contextInfo.forwardingScore` menandai pesan yang sama tanpa menyimpan metadata apa pun.
- **ALT-002**: Menyimpan `forward` di `computePayloadHash` — **ditolak**: hash request yang sudah ada berubah, sehingga operasi `in_flight` milik AuliaPos ter-abort saat Gateway naik versi. `quoted` sudah menjadi preseden yang benar.
- **ALT-003**: Menolak request bila native-forward gagal — **ditolak** (`REQ-002` + konsekuensi `CON-001` Tahap 3): isi pesan pengguna tidak boleh hilang total karena penanda tambahan gagal dipasang.
- **ALT-004**: Diam-diam membuang `quoted` ketika `forward` datang bersamaan — **ditolak** (keputusan pemilik): kombinasi itu tidak pernah dikirim AuliaPos, jadi kemunculannya berarti bug; menolak `400` membuatnya kelihatan.
- **ALT-005**: Selalu memakai prefix teks tanpa mencoba native — **ditolak** (`REQ-002`): penanda native WhatsApp lebih jujur bagi penerima, dan spec meminta mencoba native lebih dulu.

## 4. Dependencies

- **DEP-001**: Repo `tikusgot007/WA-Gateway` (Node.js/Baileys 6.7.24), working copy live di `C:\projects\WA-Gateway`; eksekusi di worktree `C:\Projects\wa-gateway-worktrees\teruskan`.
- **DEP-002**: AuliaPos mengirim field `forward` sesuai spec `Section 4.1`/`4.1.1` (plan AuliaPos `TASK-002`/`TASK-006`). Sampai AuliaPos naik, `forward` tidak pernah terkirim dan Gateway berperilaku seperti sekarang.
- **DEP-003**: Tidak ada dependensi baru. Tidak ada library baru; tidak ada perubahan skema yang bersifat merusak.

## 5. Files

- **FILE-001**: `src/api/ci4Routes.js` — terima `forward` di `/send` **dan** `/send-media`; tolak kombinasi `forward` + `quoted` dengan `400`; sertakan `forward_marker_applied` di respons kedua endpoint.
- **FILE-002**: `src/whatsapp/connectionManager.js` — pasang `contextInfo.forwardingScore` untuk native marker, sisipkan prefix `"↪️ Diteruskan: "` sebagai fallback (ke `text`, atau ke `caption` pada jalur media), kembalikan nilai marker ke route.
- **FILE-003**: `src/delivery/outgoingOperationService.js` — teruskan `forward_marker_applied` pada respons, replay, dan `toHttpResponse()`.
- **FILE-004**: `src/store/outgoingOperations.js` — kolom additive `forward_marker_applied` (tri-state) via ALTER TABLE idempoten.
- **FILE-005**: `test/simulate-forward.js` (baru) + pembaruan assertion skema pada `test/simulate-outgoing-store.js`.
- **FILE-006**: `README.md` — perbarui tabel endpoint dan bagian verifikasi, mengikuti gaya entri `test/simulate-reply-quote.js` yang sudah ada.

## 6. Testing

- **TEST-001 (otomatis, repo Gateway)**: `node test/simulate-forward.js` (baru) plus seluruh `test/simulate-*.js` dan `test/check-*.js` hijau. Cakupan: native marker pada `/send` dan `/send-media`; prefix fallback pada `text` dan `caption`; `400` untuk kombinasi `forward` + `quoted`; `forward` non-boolean diperlakukan `false`; request tanpa `forward` menghasilkan response byte-identik dengan sebelumnya; replay `operation_id` melaporkan marker sama.
- **TEST-002 (manual, HP uji)**: pesan uji benar-benar tampil bertanda diteruskan di WhatsApp (`AC-002`); pesan biasa (tanpa `forward`) tampil tanpa penandan sama seperti sebelumnya.
- **TEST-003 (bukti gerbang rilis)**: catat commit Gateway yang ter-deploy + hasil uji positif, dipakai plan AuliaPos `TASK-011` (`EXT-001`).
- **CATATAN**: bukti "penanda diteruskan tampil di WhatsApp" hanya bisa diverifikasi manual — tidak ada simulasi yang bisa membuktikannya.

## 7. Risks & Assumptions

- **ASSUMPTION-005 (dari spec, PARTIAL — ditutup di TASK-005)**: ketersediaan native forward Baileys. **Sudah terverifikasi di source terpasang**: `node_modules/baileys/lib/Utils/messages.js:244-257` dan `:465-473` pada `baileys` 6.7.24. Belum diverifikasi terhadap **pengiriman nyata**; `TASK-005` menutupnya. Mitigasi bila gagal: prefix `"↪️ Diteruskan: "` sudah disiapkan sejak awal, bukan ditambahkan belakangan, dan AuliaPos **tidak** bergantung pada hasilnya (`REQ-008`).
- **ASSUMPTION-013 (keputusan pemilik, 2026-09-28)**: kombinasi `forward` + `quoted` ditolak `400` — di luar teks spec (spec hanya melarang kombinasi tanpa menetapkan respons), dicatat agar bisa diverifikasi ulang oleh `/sdlc-audit-consistency`.
- **RISK-001 (operasional, tinggi)**: Gateway naik lebih dulu tanpa AuliaPos masih aman — `forward` tidak pernah terkirim, kontrak tidak aktif. Sebaliknya, AuliaPos naik lebih dulu berarti label "Diteruskan" tampil padahal penerima tidak melihat penanda. Mitigasi: urutan rollout Gateway dulu, dijaga gerbang rilis di plan AuliaPos `TASK-011`.
- **RISK-002 (regresi)**: Menambah key baru ke respons bisa merusak skrip yang menguji daftar key secara persis (`test/simulate-outgoing-idempotency.js`). Mitigasi: `forward_marker_applied` hanya ikut bila diminta; `TASK-004` menjalankan seluruh skrip.
- **RISK-003 (operasional)**: Perubahan ini menyentuh proses WA-Gateway yang sedang melayani percakapan sungguhan. Mitigasi: kerjakan di worktree, tidak menyentuh folder live, dan restart hanya setelah lampu hijau pemilik.
- **Tidak ada ADR baru; `CONTEXT.md` tidak berubah** (spec `Section 10`).

## 8. Related Specifications / Further Reading

- [`spec-design-teruskan.md`](../spec/spec-design-teruskan.md) (v1.3)
- [`spec-design-balas-pesan.md`](../spec/spec-design-balas-pesan.md) — preseden kontrak `quoted`/`quote_applied` yang ditiru
- [`plan-feature-teruskan-auliapos-v1.0.md`](./plan-feature-teruskan-auliapos-v1.0.md) — pasangan (naik setelah plan ini)
- [`plan-feature-balas-pesan-wa-gateway-v1.0.md`](./plan-feature-balas-pesan-wa-gateway-v1.0.md) — pola plan Gateway yang diikuti
- [`spec-index.md`](../spec/spec-index.md)
- `docs/CHAT.md` §5 (Outgoing), §18 (Developer Invariants)

## 9. Rollback / Recovery Plan

1. Semua perubahan ada di branch `feature/teruskan-forward-marker` di worktree terisolasi, jadi rollback identitas: `git -C C:\Projects\wa-gateway-worktrees\teruskan reset --hard master` menghapus semua pekerjaan tanpa menyentuh apa pun yang sedang berjalan.
2. Bila perubahan terlanjur di-deploy ke `master`: kembalikan `master` ke `1591512` (commit sebelum plan ini), lalu restart proses WA-Gateway dengan lampu hijau pemilik. Setelah turun, AuliaPos yang masih mengirim `forward` akan tetap terkirim normal — Gateway lama mengabaikan field tak dikenal; `forward_marker_applied` tidak ada, dan itu tidak merusak apa pun karena UI AuliaPos tidak bergantung padanya.
3. Migrasi kolom `forward_marker_applied` di store Gateway bersifat **additive** dengan nilai kosong, dan kode versi lama tidak membacanya — tidak wajib di-rollback. Kolom boleh dibiarkan.
4. Tidak ada environment variable yang perlu direstorasi.
5. Jalankan seluruh `test/simulate-*.js` dan `test/check-*.js` untuk memastikan kembali hijau setelah rollback.
