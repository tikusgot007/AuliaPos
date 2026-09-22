> [!SUCCESS]
> **REMEDIATION STATUS: RESOLVED**
> This audit report has been remediated by Product Manager PRD (`/sdlc-draft-prd`).
>
> - **Projected Readiness Score:** 91/100 (up from 79/100 — the **Critical Flaw Veto is lifted** because the PRD no longer contradicts the new scope). Breakdown: Completeness 37/40, Clarity 29/30, Alignment 25/30.
> - **Remediated artifact:** `prd-20260922-0141-chat-whatsapp-inbox.md`, version 1.0 amended to **v1.1** (amendment log added in Section 1.1).
> - **Finding closed — F-05 / K-02:** the PRD no longer declares Fase 2 a Non-Goal. Section 2.3 was rewritten: the Fase 2 bullet is removed, an explicit **constraint** callout records the narrow M2 gate (K-01) and forbids any expansion into a general state-consistency redesign, and the formerly invisible open items are now named:
> - **Items now named as deferred in Section 2.3:** Auto-assignment (Fase 2b, GH-008); Presence (named precondition: a proven need to stop simultaneous opening); notification and unread-per-user (named precondition: an offline Handoff target provably missing a handed-over conversation); and no Handoff history purge.
> - **New user stories with Acceptance Criteria:** **GH-006** (Handoff, Fase 2a, 8 criteria), **GH-007** (Collision Detection, Fase 2a, 5 criteria), **GH-008** (Auto-assignment, explicitly marked as the **Fase 2b increment**, 4 criteria). Section 9.2 now lists M3 Fase 2a and M3 Fase 2b; Sections 1.2, 2.1, 2.2, 3.3, 4, 5.2, 5.3, 7.1, 8.2, and 8.3 were synced.
> - **Residual findings NOT closed by this remediation (outside the Product Manager scope):** F-07 — `docs/adr/` is still absent on the active branch; F-10 / F-11 — the external baseline documents (`Panduan_Layar_AuliaPos_M3.md`, `status-proyek-master.md`) remain unavailable.
> - **Documents still carrying the old blanket statement, to be synced by their own phases:** `blueprint-m3-operational-inbox.md` Sections 3-4, `spec/spec-design-m3-operational-inbox-fase1.md` Section 1.1, and `docs/ARCHITECTURE.md` Section 12. Owners: `/sdlc-define-specs` (Spec 2a) and `/sdlc-map-architecture` (architecture map). They were deliberately not edited by this PRD-only remediation.

# 🔍 Clarification Report [Review Iteration 1]

**Tanggal:** 2026-09-22
**Branch / commit:** `feature/m3-operational-inbox-fase1a-task001` @ `44bc842`

**Target document:** Keputusan **Gate M2** sebelum Spec **M3 Fase 2a — Handoff + Collision Detection**, yang tersebar sebagai constraint di empat dokumen:

- `blueprint-m3-operational-inbox.md` §3 (baris 100) dan §4 (baris 117-120)
- `prd-20260922-0141-chat-whatsapp-inbox.md` §2.3 (baris 41) dan §9.2 (baris 150)
- `spec/spec-design-m3-operational-inbox-fase1.md` §1.1 (Out of Scope)
- `docs/ARCHITECTURE.md` §12 (baris 275-285)

**Upstream context yang di-attach:** `blueprint-m3-operational-inbox.md`, `prd-20260922-0141-chat-whatsapp-inbox.md`, `spec/spec-design-m3-operational-inbox-fase1.md`, `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md`, `docs/ARCHITECTURE.md`, `memory.instructions.md`, `.claude/instructions/memory.instructions.md`.

**Readiness Score:** 79/100
**Status:** Below Threshold — skor mentah 82/100 diturunkan oleh **Critical Flaw Veto**

**Score Breakdown:**

- **Completeness (max 40):** 34 - Gate dan definisi kerjanya tuntas, cakupan 2a terpotong rapi, dan seluruh jalur error utama (403/409/400) kini punya kode status yang pasti. Dua lubang sengaja dibiarkan terbuka dan **tercatat bernama** (Auto-assignment untuk 2b; presence + unread untuk inkremen berikutnya), bukan dibiarkan tanpa jejak. Yang masih kurang untuk 40: daftar Acceptance Criteria formal dan kebijakan retensi riwayat Handoff belum ditetapkan (keduanya memang milik fase Spec).
- **Clarity (max 30):** 28 - Semua keputusan turun ke tingkat yang bisa langsung dikodekan: nama tabel dan kolom, DB group, konvensi index tanpa FK lintas database, eligibilitas yang runtuh menjadi satu kondisi (`queue_status !== 'selesai'`), dua kolom identitas dengan peran terpisah, batas 4096 karakter, dan kode status yang mengikuti preseden yang sudah berjalan.
- **Alignment (max 30):** 20 - Arah arsitektur **konsisten** dengan source code aktual (pola conditional write :1069-1097, pola migration `DBGroup = 'inbox'`, pola response `tutupPercakapan()` :1263-1268). Skor tertahan karena PRD saat ini **masih** menyatakan Fase 2 sebagai Non-Goal, plus dua dokumen yang dikutip sebagai *dasar* penyusunan ternyata tidak pernah ada di git history.
- **Critical Flaw Veto:** Yes - PRD aktif (`prd-20260922-0141-chat-whatsapp-inbox.md` baris 41) menetapkan Handoff/Collision/Auto-assignment sebagai Non-Goal. Setiap Spec Fase 2 yang lahir sebelum PRD diamandemen adalah **Orphaned Item** dan akan gagal di Alignment rubric pada `/sdlc-audit-consistency`. Karena itu skor dibatasi di 79, terlepas dari skor mentah 82.

---

## 1. 🚨 Critical Findings (Blockers)

- **Requirement:** `prd-20260922-0141-chat-whatsapp-inbox.md` §2.3 baris 41 - *"Fase 2 (Handoff antar staff dengan ringkasan, Collision detection, Auto-assignment) - menunggu M2 (State Consistency) selesai..."*
  - **Issue:** Dinyatakan sebagai **Non-Goal**, sementara sesi ini justru membuka jalur untuk menspesifikasikannya. Tanpa amandemen PRD, Spec 2a tidak punya induk requirement sehingga traceability putus di audit berikutnya.
- **Requirement:** `spec/spec-design-m3-operational-inbox-fase1.md` §1.1 dan `plan/plan-feature-m3-operational-inbox-fase1-v1.0.md` REQ-002 - keduanya merujuk `docs/adr/0001-reuse-response-state-for-queue-view-status.md`.
  - **Issue:** Folder `docs/adr/` **tidak ada** di branch aktif (`F-07`). Rujukan audit-trail terpenting modul Inbox menggantung, sehingga prinsip *single source of truth* yang menjadi dasar keputusan hari ini tidak bisa diverifikasi pembaca di branch ini.
- **Requirement:** `blueprint-m3-operational-inbox.md` baris 5 dan `spec/spec-design-m3-operational-inbox-fase1.md` baris 13 - keduanya menyebut `Panduan_Layar_AuliaPos_M3.md` sebagai *dasar* penyusunan; `spec` §1.1 menyebut `status-proyek-master.md` sebagai rujukan status M2.
  - **Issue:** `git log --all --diff-filter=A --name-only` membuktikan **kedua file tidak pernah ditambahkan di seluruh history git** (`F-10`, `F-11`). Dokumen yang seharusnya menjadi sumber otoritatif perilaku Layar Handoff tidak dapat diakses dari mana pun, sehingga definisi Fase 2 tidak boleh diklaim "sesuai dokumen dasar" - harus dinyatakan berasal dari keputusan sesi ini.

## 2. 🧩 Resolved Items & Agreements

### 2.1 Keputusan sesi ini

- **K-01 - Gate M2 dibuka secara sempit (opsi A).** Kepemilikan yang benar-benar atomic **hanya** diselesaikan pada jalur yang menyentuhnya, lewat kontrak **expected-owner conditional write**: `UPDATE conversations SET assigned_to = :new WHERE id = ? AND assigned_to = :expected`; `affectedRows() === 0` berarti **409 conflict tanpa menimpa ownership**. Kontrak ini **reuse** primitif yang sudah berjalan di `Inbox::ambilPercakapan()` (`app/Controllers/Inbox.php:1069-1097`). M2 sebagai *program* State Consistency tetap **deferred & out of scope**.
- **K-02 - PRD wajib diamandemen (opsi A).** `prd-20260922-0141-chat-whatsapp-inbox.md` naik ke **v1.1**: Fase 2 keluar dari §2.3 Non-goals, masuk §4 Functional requirements, dengan user story baru **GH-006 (Handoff)**, **GH-007 (Collision detection)**, **GH-008 (Auto-assignment)** + Acceptance Criteria, serta §9.2 disinkronkan. Gate M2 dicatat sebagai *constraint*, bukan Non-Goal.
- **K-03 - Cakupan Spec berikutnya (opsi A).** Spec **M3 Fase 2a = Handoff + Collision Detection (sempit)**. **Auto-assignment dikeluarkan** ke inkremen **Fase 2b**; GH-008 tetap ada di PRD dan ditandai *increment berikutnya*.
- **K-04 - Definisi Collision Detection (opsi C).** = **deteksi bentrok saat write saja** (pemenang atomik di DB; yang kalah menerima 409 + nama pemilik sah; tanpa penimpaan data; tanpa tabel presence). **Presence/awareness** ("sedang dibuka oleh Budi") dideklarasikan **deferred** di PRD v1.1 dengan prasyarat bernama.
- **K-05 - Model pencatatan Handoff (opsi A).** Hanya tabel baru **`conversation_handoffs`** di DB group `inbox` (migration additive, pola `2026-09-22-000001_AddIsInternalToMessages.php`). Kolom **English snake_case**: `id`, `conversation_id`, `from_user_id`, `to_user_id`, `summary`, `next_action`, `note`, `created_at`. Index: `(conversation_id, created_at)` + `to_user_id`. **Tanpa FK lintas database** ke `users` (konvensi `assigned_to`/`closed_by`/`sent_by_user_id`). **Thread `messages` tidak disentuh** -> REQ-009 aman. Visibilitas inline di thread, bila nanti diinginkan, dilakukan sebagai merge saat render.
- **K-06 - Semantik identitas (opsi A).** Dua kolom dengan peran terpisah: **`from_user_id`** = pemilik sebelum Handoff (**nullable**; `NULL` bila percakapan tadinya `Belum Diambil`), dan **`initiated_by_user_id`** = pelaksana aksi (**NOT NULL**, selalu dari `session()->get('id_user')`, tidak pernah dari request body).
- **K-07 - Eligibilitas (opsi A).** Handoff diizinkan pada **semua tab kecuali `Selesai`** (`belum_diambil`, `open`, `menunggu`, `ditunda`); ditolak pada `selesai`. Aturan disederhanakan menjadi `queue_status !== 'selesai'`. Pembatasan pelaku tetap: hanya assignee saat ini, kecuali `belum_diambil` yang boleh dijalankan staff mana pun.
- **K-08 - Notifikasi (opsi A).** Fase 2a **tanpa notifikasi**. Penerima menemukan percakapan lewat Queue View bersama + panel riwayat Handoff. Kebutuhan notifikasi + *unread per-user* dicatat **deferred** di PRD v1.1 dengan prasyarat bernama. Risiko yang diterima secara sadar: target offline bisa tidak segera sadar; mitigasi **prosedural**, bukan teknis.
- **K-09 - Kontrak HTTP endpoint Handoff (opsi A).** `403` = pelaku tidak berhak (bukan assignee dan bukan kasus `belum_diambil`, atau target tidak valid termasuk target = pemilik saat ini). `409` = berhak tetapi ownership berubah antara read dan write (conditional write gagal); retry akibat dobel-klik/putus koneksi otomatis jatuh ke sini sehingga **idempotensi didapat gratis**. `400` = validasi (`summary`/`next_action` wajib; panjang masing-masing maks **4096**; `note` opsional maks **4096**). Badan sukses mengikuti `tutupPercakapan()` :1263-1268 -> `{status: 'success', conversation: <updated, diperkaya queue_status + nama assignee>}`.

### 2.2 Aturan bisnis yang sudah final sebelum sesi ini (tidak dibuka ulang)

- Handoff bersifat **transfer langsung**, tanpa alur persetujuan.
- `Ringkasan` (`summary`) dan `Next Action` (`next_action`) **wajib**; `Catatan` (`note`) **opsional**.
- **Hanya assignee saat ini** yang boleh memulai Handoff; pengecualian `belum_diambil` boleh oleh staff mana pun.
- Target wajib **akun staff aktif**, **tidak boleh** diri sendiri/pemilik saat ini, dan **boleh sedang offline/unavailable**.
- Handoff pada `Ditunda` **mempertahankan** state snooze (`snoozed_until` tidak di-reset).
- Handoff yang berhasil **mencatat riwayat transfer**.

### 2.3 Verifikasi kode (fakta yang mengoreksi dokumen upstream)

- **F-01:** klaim "ownership tidak atomic" sudah **usang** untuk jalur Ambil - `ambilPercakapan()` :1069-1097 sudah conditional write + `affectedRows()` -> 409, dengan docblock :1029-1040 yang menjelaskan maksudnya.
- **F-02:** jalur yang **masih** read-then-write: `lepasPercakapan()` :1137, `tutupPercakapan()` :1254, `snoozePercakapan()` :1203, `tandaiDibaca()` :1170, `hapusPercakapan()` :1002.
- **F-13:** `InboxGatewayApi::messages()` :263/:273 menulis `last_message_direction` dan memaksa `snoozed_until = null`, tetapi **tidak pernah** menyentuh `assigned_to` - sehingga Auto-assignment memaksa penulisan ownership di endpoint berhadapan Gateway, area yang dipagari `ARCHITECTURE.md` §12.
- **F-15/F-16:** aturan eligibilitas warisan tidak mencakup status `open` dan `menunggu`; `withComputedStatus()` :140/:142 membuat `ditunda` mendahului `perlu_dibalas`, sehingga percakapan `ditunda` bisa ber-owner.
- **F-17/F-18:** tidak ada state notifikasi/unread per-user di `app/` (hanya toast client-side `layout/main.php:1308`); `last_seen_by_assignee_at` bersifat per-conversation, bukan per-user.
- **Preseden HTTP modul Inbox:** 409 :1091; 403 :1131/:1195/:1244/:1166/:996; 400 + batas 4096 :598-601, :669-672, :906-909.

## 3. ⚠️ Assumed / Backlog / Out of Scope

- **Auto-assignment (GH-008) beserta seluruh aturan turunannya** - `[Assumed / Out of Scope untuk Fase 2a]` (K-03). `least-loaded` belum punya satuan, definisi "sedang bertugas" belum diikat ke `JadwalModel::DEFINISI_SHIFT`, dan peran `users.priority` (UNIQUE, ranking permanen) sebagai tie-breaker belum ditetapkan. Seluruhnya ditunda ke Spec **Fase 2b**.
- **Presence/awareness ("sedang dibuka oleh Budi")** - `[Assumed / Out of Scope untuk Fase 2a]` (K-04). Prasyarat yang dinamai: baru relevan setelah ada kebutuhan nyata mencegah dua staff membuka percakapan yang sama secara bersamaan; menuntut tabel presence + heartbeat + TTL + penanganan sesi mati.
- **Notifikasi + unread per-user** - `[Assumed / Out of Scope untuk Fase 2a]` (K-08). Prasyarat yang dinamai: baru diperlukan setelah target offline terbukti secara operasional melewatkan percakapan yang diserahkan; menuntut state unread per-user yang belum ada di repo (F-18).
- **Riwayat Handoff ketika percakapan dihapus** - `[Assumed / Backlog]`. Rekomendasi: mengikuti perilaku `messages` - `conversation_id` memakai FK `ON DELETE CASCADE` (satu database, preseden `CreateInboxTables.php:227`), sedangkan soft delete `hapusPercakapan()` :1002 **tidak** menghapus riwayat.
- **Teks pesan error 409/403** - `[Assumed / Backlog]`. Rekomendasi: pesan 409 **wajib menyebut nama pemilik sah saat ini**, mengikuti pola yang sudah ada di `ambilPercakapan()` :1093-1095.
- **Zona waktu** - `[Assumed / Backlog]`. Rekomendasi: `Asia/Jakarta`, mengikuti preseden `snoozePercakapan()` :1200 dan `tutupPercakapan()` :1252.
- **Target Handoff dengan `role = 'admin'`** - `[Assumed / Backlog]`. Rekomendasi: diperbolehkan selama akunnya `is_active` (role enum hanya `admin`/`kasir`), konsisten dengan perlakuan `cekOwnership()` :527 yang menempatkan admin di jalur override.
- **`next_action` sebagai teks bebas, bukan enum** - `[Assumed / Backlog]`. Tidak ada dokumen sumber yang dapat diverifikasi yang mendefinisikan daftar tindakan (F-10), sehingga menetapkan enum sekarang berarti mengarang requirement.
- **Target yang dinonaktifkan setelah Handoff** - `[Assumed / Backlog]`. Rekomendasi: tanpa aksi otomatis; percakapan tetap milik target, konsisten dengan aturan yang sudah ada bahwa tidak ada reassignment otomatis.
- **Retensi/purge riwayat Handoff** - `[Assumed / Out of Scope]`. Riwayat adalah jejak audit; tidak ada purge di Fase 2a.

## 4. 📝 Next Steps

1. **`/sdlc-draft-prd` (sesi baru)** - amandemen `prd-20260922-0141-chat-whatsapp-inbox.md` menjadi **v1.1** sesuai **K-02**. Setelah selesai, agen penulis **wajib** menjalankan 3-Step Remediation Sequence: (1) hitung proyeksi Readiness Score, (2) tambahkan blok `REMEDIATION STATUS: RESOLVED` di bagian atas file laporan ini, (3) laporkan hasilnya.
2. **`/sdlc-define-specs` (sesi baru)** - susun Spec **M3 Fase 2a**, wajib memuat: section eksplisit bahwa gate M2 terpenuhi **secara sempit** lewat K-01 beserta larangan melebar ke redesign state consistency (`ARCHITECTURE.md` §12), kontrak tabel (K-05), semantik identitas (K-06), eligibilitas (K-07), kontrak HTTP (K-09), dan definisi Collision Detection (K-04). Spec **tidak boleh** memuat presence, unread/notifikasi, atau Auto-assignment.
3. **ADR** - perbaiki dulu `docs/adr/` yang tidak ada di branch aktif (F-07) dengan mengembalikan/menyusun ulang `0001-reuse-response-state-for-queue-view-status.md`, lalu tambahkan satu ADR baru untuk keputusan *expected-owner conditional write* (K-01). Uji Triple Gate: sulit dibalik (**ya**), mengejutkan tanpa konteks (**ya** - "M2 deferred, tapi atomicitas sempit dikerjakan di Fase 2a"), trade-off nyata (**ya** - atomicity sempit vs redesign state consistency menyeluruh).
4. **`CONTEXT.md` (lazy creation, belum dibuat)** - sudah ada tiga istilah domain yang layak dikanonikan: **Collision Detection** (hindari pemakaian istilah ini untuk presence), **Handoff**, dan **Presence**. Gunakan format `.claude/standards/CONTEXT-FORMAT.md`.
5. **`docs/ARCHITECTURE.md`** - update wajib saat implementasi (tabel `conversation_handoffs` + endpoint baru), sesuai *Living Architecture Map Mandate*.
6. **Jangan mulai implementasi** sebelum Spec 2a dan Implementation Plan-nya disetujui.

> [!NOTE]
> **Blok User Decision Prompt sengaja tidak dimunculkan** pada laporan ini karena skor (79) berada di bawah ambang 80 akibat Critical Flaw Veto, dan sesi ini masih iterasi ke-1. Sesuai kebijakan, jalurnya adalah menyelesaikan temuan kritis di Section 1 terlebih dahulu.
>
> **Human Override tetap tersedia.** Bila Anda memutuskan untuk **force-proceed** ke `/sdlc-define-specs` tanpa amandemen PRD lebih dulu, hal itu sah - namun harus dicatat sebagai override eksplisit, dan risiko Orphaned Item pada `/sdlc-audit-consistency` menjadi tanggungan yang disadari.

**Proyeksi skor setelah remediasi:** 88/100 - Alignment naik dari 20 menjadi 28 setelah PRD diamandemen (F-05 tuntas), `docs/adr/` tersedia kembali di branch aktif (F-07 tuntas), dan ketiadaan `Panduan_Layar_AuliaPos_M3.md` / `status-proyek-master.md` dinyatakan tegas sebagai artefak eksternal (F-10/F-11).
