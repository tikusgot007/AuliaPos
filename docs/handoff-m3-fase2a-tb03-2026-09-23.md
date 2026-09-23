# Handoff Eksekusi — M3 Fase 2a TB-03 (Riwayat Handoff: endpoint baca + panel UI)

> [!IMPORTANT]
> **Dokumen ini non-normatif.** Sumber normatif tetap `plan/plan-feature-m3-operational-inbox-fase2a-v1.0.md` (TB-03 = TASK-009..TASK-011) dan `docs/audit/clarification-report-m3-fase2a-plan-2026-09-23.md` (Q1–Q9). Bila ada konflik, Plan menang (RISK-01) — dokumen ini hanya brief operasional untuk sesi `/sdlc-write-code` berikutnya.

## 1. Status & prasyarat

- **Status:** siap dieksekusi, menunggu approval eksplisit user (TB-02 sudah berhenti di batasnya).
- **Branch:** `feature/m3-operational-inbox-fase1a-task001`.
- **Prasyarat selesai:** TB-01 (`600515c`, `4ae724e`, `b9f0e27`) dan TB-02 (`bedf810`, `62afbbb`, `3fda052`). Belum ada yang di-push.
- **Baseline hijau:** `vendor/bin/phpunit --no-coverage` → **270 tests / 765 assertions**.

## 2. Lingkup (dan batas yang dilarang)

Lingkup TB-03 hanya TASK-009, TASK-010, dan TASK-011:

- **TASK-009** — `Inbox::apiHandoffs($id)` (method BARU) + route `GET /inbox/percakapan/(:num)/handoff` (filter `auth`) + test.
- **TASK-010** — panel riwayat di `app/Views/inbox/index.php`, di-refresh setelah handoff sukses **dan** setelah 409.
- **TASK-011** — VERIFY (full suite hijau) + demo, lalu **berhenti dan tunggu approval**.

Dilarang di TB-03:

- mengubah `GET /inbox/api/conversations/(:num)/messages` (REQ-H08/P-04);
- menyentuh 7 method lama (`ambil`, `lepas`, `tutup`, `snooze`, `tandai-dibaca`, `hapus`, `catatanInternal`) dan `handoffPercakapan()`;
- presence, unread, notifikasi, ataupun Auto-assignment;
- mengubah tab Queue/SLA/filter atau `withComputedStatus()`;
- migrasi baru (tabel `conversation_handoffs` sudah ada sejak TB-01);
- `docs/ARCHITECTURE.md` (milik TB-04/TASK-014).

## 3. Kontrak yang sudah dikunci

| Aspek | Nilai | Sumber |
| --- | --- | --- |
| Path | `GET /inbox/percakapan/(:num)/handoff` | P-04 |
| Filter | `auth` saja — **tanpa** gerbang assignee | Q7 |
| 404 | hanya untuk conversation id tak dikenal | Q7/P-04 |
| Envelope sukses | `{status: success, handoffs: [...], limit: 50}` | P-04 |
| Urutan | terbaru dulu (newest-first) | REQ-H08 |
| Cap | 50 entri; riwayat lebih lama tetap di tabel, tanpa purge | P-04 |
| Field entri | `id`, `from_user_id`, `to_user_id`, `initiated_by_user_id`, `summary`, `next_action`, `note`, `created_at` | P-04 |

## 4. Titik sentuh kode (fakta hasil TB-01/TB-02)

- `ConversationHandoffModel::forConversation(int $id, int $limit = 50): array` **sudah ada** dan sudah diuji (newest-first + cap 50) — pakai apa adanya, jangan duplikasi query.
- Route handoff POST saat ini ada di `app/Config/Routes.php` baris 55; taruh route GET di dekatnya dengan gaya yang sama.
- View: `renderThreadHeader()` (`app/Views/inbox/index.php:747`), `muatUlangDaftarConversation()` (`:709`). Panggil refresh riwayat dari `kirimHandoff()` pada jalur sukses **dan** pada jalur error (notice 409 lewat `tampilkanNoticeHandoff()`), dan dari `muatUlangSetelahHandoffBasi()`.
- `handoffPercakapan()` sudah mengembalikan `handoff_id`, jadi refresh panel setelah sukses tidak butuh state tambahan.
- Panel riwayat ditempatkan di dalam `inbox-thread-panel` (mis. di bawah `inbox-thread-messages`), **tanpa** menyentuh `renderPesan()`/`renderIsiPesan()`.

## 5. Jebakan yang sudah terbukti (jangan diulang)

- **Git:** jalankan `add`+`commit` sebagai **satu** command string sekuensial (`;`). Tiga tool call paralel bertabrakan di `.git/index.lock` dan menghasilkan commit dengan isi tidak sesuai pesannya.
- **PHPUnit:** pakai `cmd /c 'vendor\bin\phpunit --no-coverage > build\<nama>.txt 2>&1'` lalu baca filenya. Pipe PowerShell adalah bagian yang lambat (suite sendiri hanya ±5 detik).
- **Response JSON:** `json_decode($response->getJSON(), true)`; `getBody()` mengembalikan `null`.
- **ID dari DB = string** (`numberNative=false`) → cast `(int)` di assertion.
- **Kalau perlu `insert_line`:** hitung dulu jumlah baris file, jangan menebak.

## 6. Rencana test TB-03 (TEST-04, bagian pertama)

Semua test masuk ke `tests/session/InboxHandoffTest.php` (pola yang sudah ada: `FeatureTestTrait` + `DatabaseTestTrait`, users di grup `tests`, conversations/handoffs di grup `inbox`, `emptyTable` di `setUp`):

1. Dua handoff sukses → `GET` menampilkan **2 entri terbaru-dulu** (assert urutan `id` menurun).
2. Cap 50: seed 55 baris lewat `ConversationHandoffModel` → `GET` hanya 50, `limit` = 50 di envelope.
3. `404` untuk id tak dikenal (sebelum validasi apa pun).
4. **Gerbang baca Q7 dibuktikan:** staff yang bukan assignee tetap **200** saat membaca riwayat (jangan hanya berasumsi; ini pembeda dari endpoint POST).
5. `GET` tidak menulis apa pun: jumlah baris `messages` dan `conversation_handoffs` tidak berubah.
6. Shape kunci tiap entri lengkap (8 field) + `from_user_id` tetap `NULL` untuk kasus belum-diambil.

## 7. DoD TB-03

- `vendor/bin/phpunit --no-coverage` hijau 100% (perkiraan ≥ 275 test).
- Demo matriks testdox untuk endpoint riwayat.
- Review diff: 7 method lama 0 penghapusan; `GET messages` tidak tersentuh; tidak ada migrasi baru.
- Commit per task, gaya repo (contoh: `feat(inbox): expose handoff history endpoint`, `feat(inbox): render handoff history panel`).
- **BERHENTI dan tunggu approval. Jangan push tanpa perintah.** Tawarkan memory checkpoint.

## 8. Setelah TB-03

**TB-04 (TASK-012..TASK-014):** edge case (blank-spasi, `to == initiator` walau bukan owner, `ambilPercakapan()` di tengah, `ditunda` mempertahankan `snoozed_until`, `belum_diambil` → tab `open`), boundary audit, lalu update `docs/ARCHITECTURE.md` (Living Map: tabel `conversation_handoffs`, `ConversationHandoffModel`, `UserModel::daftarKasirAktif()`, dua route Handoff) dan approval akhir.

## 9. Rujukan

- `plan/plan-feature-m3-operational-inbox-fase2a-v1.0.md` (TB-03, TEST-04, FILE-04/05/06/08)
- `docs/audit/clarification-report-m3-fase2a-plan-2026-09-23.md` (Q7, Q9)
- `.claude/instructions/memory.instructions.md` (checkpoint TB-01 dan TB-02)
