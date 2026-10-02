# Checkpoint Sesi

- **Tanggal**: 2026-10-02
- **Status**: sebagian — implementasi selesai & 6 cek lokal lulus; **verifikasi E2E di PC baru/VM belum**
- **Repo / branch**: implementasi di `C:\Projects\evolution-gateway` (`tikusgot007/WA-Gateway`, branch `master`) — **belum di-commit**. Dokumen di `aulia-app` `v2.4` (`docs/requirements`, `docs/design`, `docs/TODO.md`, checkpoint ini) — **belum di-commit**.

## Selesai

- Requirement + design (Tier A) disetujui user:
  - `docs/requirements/2026-10-02-paket-instalasi-gateway-pc-baru.md`
  - `docs/design/2026-10-02-paket-instalasi-gateway-pc-baru.md`
- Keputusan scoping user: **gateway-only**, **bootstrap online**, **start manual** (tanpa schedule/watchdog).
- Paket instalasi dibuat di `C:\Projects\evolution-gateway\installer\`:
  - `install.ps1` — orkestrator: cek admin & port, siapkan Node LTS (winget) + binari PostgreSQL 16 (`get.enterprisedb.com`, terverifikasi HTTP 200 untuk `16.15-1`), unduh ZIP `codeload` Evolution tag `2.3.7` + adapter ref, `npm ci` (`HUSKY=0`), patch view-once, `setup-env`, `install-postgres`, `prisma generate` + `migrate deploy`, start, firewall, instance+webhook, tulis `install-summary.txt`.
  - `start.ps1` / `stop.ps1` / `status.ps1` — manual; `stop` memverifikasi pemilik port sebelum mematikan (pola `scripts/restart-adapter.ps1`).
  - `apply-viewonce-patch.ps1` — idempotent (penanda `PATCH-ADAPTER`), backup `.orig-<tanggal>`.
  - `lib/common.ps1` — helper bersama (Invoke-Native anti-stderr, Get-EnvValue, port, Resolve-NodeTools, unduh/ekstrak).
  - `petunjuk-penggunaan.md` — runbook Bahasa Indonesia (prasyarat, parameter, start/stop/status, pairing QR, verifikasi, config AuliaPos, troubleshooting).
  - `tests/` — 6 cek assert-based (`check-viewonce-patch`, `check-env-parity`, `check-firewall`, `check-runbook`, `check-static`, `check-syntax`) + `run-checks.ps1`.
- Perubahan kecil pada skrip yang sudah ada (repo adapter):
  - `scripts/setup-env.ps1`: tambah `-Ci4GatewayToken` (token dari parameter, fallback gateway lama) dan `-EvolutionEnvTemplate` (default `.env.template` bila ada, jika tidak `env.example`); tambah override Evolution menyamai config produksi aulia3 (Redis/websocket/telemetry mati, `DATABASE_DELETE_MESSAGE=true`, dll).
  - `scripts/setup-instance.js`: hapus literal `AULIA3`/`aulia-toko` di pesan "belum tertaut" (dipakai `BASE`+`INSTANCE`).
- Verifikasi yang dijalankan (nyata):
  - `installer/tests/run-checks.ps1` -> **6/6 lulus**.
  - `node test/simulate-evolution-adapter.js` -> **SEMUA ASSERT LULUS (0 gagal)**.
  - `node test/simulate-evolution-boot.js` -> **OK**.
- Definisi paket: `installer/install.ps1` + `lib/` + `start/stop/status` + `apply-viewonce-patch.ps1` + `petunjuk-penggunaan.md` + `tests/`.
- **Review lokal (`/review uncommitted`) — 5 temuan diperbaiki, semua cek tetap lulus:**
  1. Preflight port kini sadar-resume: port boleh sudah listen bila itu service PG yang dikelola atau proses node stack kita; jalur pemulihan `stop.ps1` → `install.ps1` tidak lagi gagal karena 5432.
  2. `install.ps1` menambah guard kecocokan versi sumber adapter (memastikan `setup-env.ps1` memuat dukungan `-Ci4GatewayToken`) dengan pesan actionable, serta menyarankan pin `-AdapterRef` ke SHA.
  3. `stop.ps1` sekarang `exit 1` bila port stack masih listen (tidak lagi sukses palsu).
  4. `check-static.ps1` menyaring ekstensi manual (kuirk `-Include` + `-LiteralPath`).
  5. Token CI4 bisa lewat `-Ci4GatewayTokenFile` dan diteruskan ke `setup-env.ps1` via environment `CI4_GATEWAY_TOKEN_INPUT` (tidak tampil di command line); `check-env-parity` diperbarui menguji jalur env ini.
- **Temuan review #6 (di luar pekerjaan ini)**: `.kilo/rules/sdlc.md:59` menghapus larangan menjalankan perintah deploy/migrasi/rollback ke lingkungan nyata. **Belum diubah** — menunggu keputusan user apakah itu disengaja.

## Keputusan penting

- **ZIP snapshot `codeload` (tanpa Git)** dipilih dari git clone — kedua ZIP terverifikasi publik (HTTP 200), menghilangkan prasyarat Git.
- **Reuse skrip aulia3 yang sudah teruji** (parameterize) alih-alih menulis ulang; skrip task aulia3 (`run-*.cmd`, `watchdog-stack.ps1`, `register-services.ps1`) **tidak disentuh**.
- **Deviasi dari design**: tidak dibuat `installer/templates/evolution.env.template`; `setup-env.ps1` memakai `env.example` bawaan sumber Evolution sebagai template (lebih terjamin lengkap; nilai penting di-override eksplisit). Fallback MSI langsung untuk Node **tidak** diimplementasikan — bila `winget` tidak ada, runbook memandu pasang Node manual lalu `-SkipPrereqs`.
- **Smoke test `test-send.js` tidak dijalankan otomatis** saat install (mengirim pesan WhatsApp nyata); verifikasi install = port listen + `connectionState`.
- Service PostgreSQL tetap `auto` (kebutuhan Evolution); start manual hanya untuk Evolution + adapter.

## Tersisa

- **TODO-I1** (baru, `docs/TODO.md`): verifikasi E2E di PC baru/VM (AC-1/2/6/7/9/11) + pin `-AdapterRef` ke commit SHA saat rilis.
- Commit perubahan di repo adapter **dan** dokumen di `aulia-app` (belum dilakukan; menunggu permintaan user).
- (Opsional) `templates/evolution.env.template` bila ingin tidak bergantung pada `env.example` upstream.

## Belum diverifikasi / risiko

- **Seluruh E2E belum dijalankan**: AC-1, AC-2, AC-3, AC-6, AC-7, AC-8, AC-9, AC-11 hanya bisa dibuktikan di PC baru/VM. Tidak dijalankan di mesin dev ini agar setup yang sudah jalan tidak rusak.
- Perilaku `npm ci` Evolution di mesin bersih (husky `prepare` saat bukan git repo, `prisma generate`, `better-sqlite3` prebuilt) — mitigasi sudah ada (`HUSKY=0`, generate eksplisit) tetapi belum diuji nyata.
- URL binari PostgreSQL 16.15 terverifikasi ada (HTTP 200), tetapi ekstrak + `initdb` di mesin baru belum diuji.
- Perubahan `setup-env.ps1` menyentuh skrip yang juga dipakai aulia3 — perilaku default aulia3 harus tetap (default `-OldGatewayEnv` dan `.env.template` dipertahankan), belum diuji ulang di aulia3.
- E2E membutuhkan internet, hak admin, dan nomor WhatsApp untuk pairing QR (manual).

## Titik masuk sesi berikutnya

- **Baca**: `docs/requirements/2026-10-02-paket-instalasi-gateway-pc-baru.md`, `docs/design/2026-10-02-paket-instalasi-gateway-pc-baru.md`, `C:\Projects\evolution-gateway\installer\petunjuk-penggunaan.md`.
- **Jalankan**: `C:\Projects\evolution-gateway\installer\tests\run-checks.ps1` (regresi cek lokal).
- **E2E**: jalankan `installer\install.ps1` di PC baru/VM dengan parameter nyata, ikuti `petunjuk-penggunaan.md`, lalu catat hasil AC-1/2/6/7/9/11.
- **Commit**: repo adapter (`installer/` + 2 skrip) dan `aulia-app` (requirements, design, TODO, checkpoint) — minta persetujuan user lebih dulu (AGENTS.md §11).
