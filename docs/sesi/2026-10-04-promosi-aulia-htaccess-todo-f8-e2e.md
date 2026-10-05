# Checkpoint Sesi

- **Tanggal**: 2026-10-04
- **Status**: berhenti sementara (lanjut sesi berikutnya), bukan selesai
- **Repo / branch**:
  - AuliaPos: `C:\xampp\htdocs\aulia` (sudah dipromosikan dari `aulia-app`) — branch **`todo-f8-message-text`** (checkout dari `origin/todo-f8-message-text`, commit dasar `4ecebf5`), BUKAN `v2.4`. Working tree ada perubahan **belum di-commit**.
  - Gateway: `C:\Projects\evolution-gateway` → `tikusgot007/WA-Gateway` branch `todo-f8-capture-fixture`, HEAD `28d98b4` (sinkron dengan origin).
  - Gateway-test (deployment nyata yang dipakai uji): `C:\AuliaGateway-test\evolution-gateway` — sudah disinkronkan manual (robocopy) dari `C:\Projects\evolution-gateway`, BUKAN git repo sendiri.
  - Evolution API test: `C:\AuliaGateway-test\evolution-api-server` — patch LID lokal terpasang.

## Yang dikerjakan sesi ini

1. **Promosi folder POS**: `C:\xampp\htdocs\aulia-app` (worktree, v2.4) dipromosikan jadi repo mandiri `C:\xampp\htdocs\aulia` memakai `D:\ARSIP\promote-aulia-app.ps1`. Sisa file kerja v2.3 lama diarsipkan ke `D:\ARSIP\repos\aulia-v2.3-working-files`. Worktree lama `C:\xampp\htdocs\aulia\.kilo\worktrees\deserted-ship` masih ada (sisa Agent Manager, aman/tidak dihapus, belum ada keputusan).
2. **Perbaikan `.htaccess` pasca-promosi**: `RewriteBase` di `.htaccess` root dan `public/.htaccess` diubah dari `/aulia-app/` → `/aulia/`; `.env` `app.baseURL` disamakan. Diverifikasi: `http://localhost/aulia/` memuat halaman login.
3. **TODO-F8 — sinkronisasi & verifikasi E2E nyata** (laporan sebelumnya dari WA-Gateway PR #5 mengklaim "semua test lulus" tapi offline-only):
   - `C:\Projects\evolution-gateway` di-fast-forward ke `origin/todo-f8-capture-fixture` (`28d98b4`), tertinggal 11 commit sebelumnya.
   - `npm test` di source sempat gagal (fixture lokal lama format pra-LID) — bukan regresi; diperbaiki dengan hapus fixture lama.
   - Ditemukan: **`C:\AuliaGateway-test\evolution-gateway` (adapter yang BENAR-BENAR jalan di port 3000) belum pernah di-deploy ulang sejak F8 mulai** — tidak punya `remoteJidLid` sama sekali. Disinkronkan manual via `robocopy` (`src/`, `test/`, `installer/`, `package.json`, docs patch LID).
   - `npm test` di deployed copy: **semua lulus** (robocopy manual terbukti benar).
   - Patch LID (`apply-lid-preservation-patch.ps1`) dipasang ke `C:\AuliaGateway-test\evolution-api-server`. **Catatan bug cross-repo**: self-check di `apply-lid-preservation-patch.ps1:93` memakai regex TANPA `(messageRaw.key as any)` cast sehingga salah melaporkan "GAGAL" walau patch sukses (diverifikasi manual benar). Belum dilaporkan ke repo WA-Gateway.
   - Stack gateway-test dinyalakan (`installer/start.ps1`): PostgreSQL (5433) + Evolution (8080) + adapter (3000) semua listen, instance `aulia-test` `open`.
   - **Bug konfigurasi ditemukan & diperbaiki**: `CI4_BASE_URL` di `C:\AuliaGateway-test\evolution-gateway\.env` masih `http://127.0.0.1/aulia-app` (path lama) setelah promosi folder POS → heartbeat 404 terus-menerus → Inbox tampil "Terputus". Diperbaiki ke `http://127.0.0.1/aulia`, adapter di-restart, status kembali `Terhubung`.
   - POS checkout branch `todo-f8-message-text` (origin, commit `4ecebf5`) — berisi endpoint `message-event` yang menerima `edited_text`. Tidak ada migrasi skema baru (hanya pakai kolom `text`/`edited_at` yang sudah ada dari F7). `composer test` 53/53, `composer test:feature` 65/65 — lulus di branch ini.
   - `.env` gateway-test: `EVOLUTION_DECRYPT_MESSAGE_EDIT=1` diaktifkan, adapter di-restart.
   - **Uji nyata oleh user** (kirim + edit pesan WhatsApp ke nomor gateway-test `6281913500707`, termasuk hapus): **BERHASIL**. Dikonfirmasi langsung dari DB (`aulia_inboxdb.messages`):
     - Pesan incoming dari pelanggan diedit → `text` = "Text lain" (teks hasil edit asli, bukan penanda) — `id=377`.
     - Pesan **outgoing** (staf kirim+edit sendiri via WA Web/HP, BUKAN dari POS) → `text` = "Wkwkw" (teks edit asli juga berhasil) — `id=379`.
     - Pesan dihapus → `revoked_at` terisi, teks asli dipertahankan ("Wow") — `id=378`.
   - Ini bukti E2E **pertama** jalur penuh WhatsApp → Evolution (LID preserved) → gateway (dekripsi HKDF-SHA256+AES-256-GCM+decode protobuf) → POS (`updateEditedText()`) bekerja — bukan cuma offline fixture test seperti klaim awal.
4. **Bug ditemukan dari hasil uji** (bukan regresi F8, sudah ada sejak F7, baru kelihatan sekarang karena staf baru pertama edit pesan outgoing miliknya sendiri): label `renderLabelDiedit`/`renderLabelDihapus` di `public/assets/js/inbox-thread.js` SELALU menyebut "pelanggan" meski pesan itu outgoing (staf edit sendiri). **Diperbaiki** (user approve opsi netral): label jadi "Pesan diedit"/"Pesan dihapus", tanpa sebut pelaku. Test JS, komentar di `app/Views/inbox/index.php`, dan `docs/CHANGELOG.md`/`docs/TODO.md` diperbarui sejalan.
   - `node tests/js/inbox-thread.test.js`: 50/50 lulus setelah perbaikan.
   - `composer test` 53/53, `composer test:feature` 65/65 tetap lulus.

## Keputusan penting

- **Label edit/hapus**: tidak menyebut pelaku ("pelanggan") — user approve opsi netral karena event juga terjadi pada pesan outgoing milik staf sendiri.
- **TODO-F8 lanjutan**: user approve lanjut ke Phase 2 (backend kirim sinyal baru semacam `is_edited_text_resolved` agar frontend bisa membedakan teks F8 yang sudah valid/final vs teks F7 lama yang masih basi) — **BELUM dikerjakan**, perlu requirement/design terpisah dulu (Tier A, sesuai SDLC karena menyentuh kontrak backend→frontend baru).

## Belum diverifikasi / risiko

- **Working tree POS punya perubahan uncommitted** di branch `todo-f8-message-text`: `app/Views/inbox/index.php`, `docs/CHANGELOG.md`, `docs/TODO.md`, `public/assets/js/inbox-thread.js`, `tests/js/inbox-thread.test.js`. **Belum di-commit**, belum di-push, belum direkonsiliasi dengan `v2.4` (branch kerja utama sehari-hari).
- **Branch `todo-f8-message-text` tertinggal 1 commit dari `v2.4`** (`24e7e0b` docs TODO) — perlu rebase/merge sebelum jadi branch kerja permanen.
- Perbaikan label (poin 4) secara logika juga berlaku untuk `v2.4` murni (bug ada sejak F7, independen dari F8) — **belum di-cherry-pick ke `v2.4`**.
- Styling UI "samar" (`inbox-teks-basi`) untuk teks hasil edit F8 yang SUDAH valid masih berjalan seperti pola F7 lama (opacity 0.5 + kesan "belum tentu terbaru") — **menyesatkan**, Phase 2 belum dikerjakan.
- Self-check bug di `apply-lid-preservation-patch.ps1:93` (repo WA-Gateway, cross-repo) — **belum dilaporkan/diperbaiki**.
- Verifikasi visual browser (hard refresh Inbox untuk lihat label baru) — **belum dikonfirmasi user**.
- PR #5 (`WA-Gateway`) dan PR #49 (`AuliaPos`) masih **Draft** — JANGAN merge sebelum: (a) deploy ke produksi `aulia3` diverifikasi (versi Evolution produksi belum dicek), (b) Phase 2 UI dikerjakan atau diputuskan ditunda secara eksplisit.
- Stack gateway-test (`C:\AuliaGateway-test`) **masih hidup** — jangan dimatikan (pola lama sesuai sesi-sesi sebelumnya), kecuali diminta user.

## Titik masuk sesi berikutnya

- **Baca**: file ini, `docs/TODO.md` (TODO-F7/F8), `docs/CHANGELOG.md` (2 entri 2026-10-04 terbaru), `docs/laporan-keputusan-todo-f8-dekripsi-pesan-edit.md`.
- **Keputusan yang perlu diambil**:
  1. Commit & push perubahan branch `todo-f8-message-text` (atau pindahkan ke `v2.4` langsung)?
  2. Cherry-pick perbaikan label netral ke `v2.4`?
  3. Lanjut Phase 2 TODO-F8 (requirement/design sinyal `edited_text` tervalidasi) atau tunda?
  4. Laporkan bug self-check `apply-lid-preservation-patch.ps1:93` ke WA-Gateway?
  5. Rencana deploy patch LID + flag dekripsi ke produksi `aulia3` (perlu cek versi Evolution produksi dulu).
- **Jalankan**: `git -C C:\xampp\htdocs\aulia status`, `powershell -File C:\AuliaGateway-test\evolution-gateway\installer\status.ps1 -InstallRoot C:\AuliaGateway-test -PgService postgresql-auliagw-test -PgPort 5433`.
