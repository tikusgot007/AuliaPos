# Checkpoint Sesi

- **Tanggal**: 2026-10-08
- **Status**: selesai
- **Repo / branch**: `aulia-app` `v2.4` (commit `1336aee`); `WA-Gateway` (`C:\Projects\evolution-gateway`) `evolution` (commit `82d1b9b`)

## Selesai

- Diskusi & verifikasi: cara update gateway di dev (`C:\AuliaGateway-test`,
  `C:\AuliaGateway-service-test`) dan produksi `aulia3` sama-sama manual
  copy/ZIP, bukan Git — alasan lama (`docs/design/2026-10-02-...md`): repo
  mungkin privat. Diverifikasi ulang via GitHub API: kedua repo (adapter
  `tikusgot007/WA-Gateway`, Evolution `evolution-foundation/evolution-api`)
  **sudah public** — blocker itu tidak berlaku lagi.
- Requirements (Gate 1) + Design (Gate 2) ditulis dan disetujui user dalam
  sesi ini: `docs/requirements/2026-10-08-installer-git-based-source.md`,
  `docs/design/2026-10-08-installer-git-based-source.md`.
- Implementasi di `C:\Projects\evolution-gateway\installer\` — commit
  `82d1b9b`:
  - `lib/common.ps1`: fungsi baru `Ensure-Git`, `Resolve-GitTool`,
    `Ensure-GitSource` (clone sekali; fetch+checkout `--detach` pada run
    berikutnya; tidak pernah menghapus working tree sehingga
    `.env`/`data/`/`node_modules` gitignored selamat lewat update).
  - `install.ps1`: `Get-ZipUrl`/`Ensure-Source` (ZIP) dihapus total,
    diganti 2 panggilan `Ensure-GitSource` (Evolution API, adapter). `npm
    ci` sekarang digerbang oleh perubahan HEAD git, bukan hanya
    keberadaan `node_modules`.
  - Patch Evolution (`apply-viewonce-patch.ps1`,
    `apply-lid-preservation-patch.ps1`) **tidak diubah** — tetap
    dipanggil setiap run, idempotent seperti sebelumnya (keputusan
    eksplisit: bukan fork Evolution, patch tetap runtime).
  - Test baru `installer/tests/check-git-source.ps1`: memanggil
    `Ensure-GitSource` **produksi** langsung (lewat parameter `-Url` baru
    untuk override ke repo bare lokal), verifikasi AC-1/2/3/7 tanpa
    network/admin.
  - `check-static.ps1`, `check-runbook.ps1` diperluas: menolak jejak ZIP
    lama, mewajibkan mekanisme Git.
  - `petunjuk-penggunaan.md` §2 + tabel parameter diperbarui.
  - `installer/tests/run-checks.ps1` → **9/9 cek lulus**.
- Dokumentasi di `aulia-app` — commit `1336aee`: requirements, design,
  `docs/TODO.md` ditambah **TODO-Q3g**.

## Keputusan penting

- **Git untuk kedua sumber** (Evolution API + adapter), bukan hanya
  adapter — alasan: begitu Git jadi prasyarat untuk salah satu, tidak ada
  keuntungan punya 2 mekanisme berbeda untuk 2 sumber.
- **Bukan fork Evolution API** ke repo sendiri — 2 patch yang ada kecil
  dan sudah idempotent + teruji; overhead sinkronisasi upstream tidak
  sepadan. Patch tetap dijalankan ulang tiap checkout.
- **Checkout selalu `--detach`** (bukan local branch yang di-`pull`) —
  konsisten dengan kondisi lapangan yang sudah terjadi di
  `C:\AuliaGateway-service-test` (HEAD detached), dan menghindari masalah
  local branch basi terhadap ref yang bergerak (mis. `master`).
- **Scope dibatasi ke instalasi PC baru** — tidak mengubah `aulia3`
  produksi (masih Scheduled Task, TODO-Q3c terpisah) maupun rig dev yang
  sudah ada (`C:\AuliaGateway-test`, `C:\AuliaGateway-service-test`),
  yang tetap pakai ZIP/robocopy kecuali diputuskan lain di sesi
  berikutnya.
- `npm ci` diberi guard fail-open: kalau status perubahan HEAD ambigu,
  tetap jalankan `npm ci` (lebih aman daripada diam-diam skip dependency
  baru).

## Tersisa

Lihat `docs/TODO.md` **TODO-Q3g**.

## Belum diverifikasi / risiko

- **E2E instalasi penuh di PC/VM bersih** (AC-1 end-to-end) — sesi ini PC
  dev sudah punya Git terpasang, jadi jalur `Ensure-Git` (bootstrap via
  `winget install Git.Git`) belum teruji nyata.
- **Perilaku stderr `git` terhadap `Invoke-Native`/`$ErrorActionPreference
  = 'Stop'`** pada mesin lain — berisiko sama seperti masalah yang sudah
  pernah ditemukan untuk `winget`/`npm` (dicatat di `install.ps1:68-87`),
  belum terbukti bermasalah untuk `git` tapi juga belum diuji di luar
  sesi ini.
- **Winget package ID `Git.Git`** belum dikonfirmasi tersedia/benar di
  semua target Windows (asumsi paralel dengan `OpenJS.NodeJS.LTS`, belum
  diverifikasi langsung).
- Rig dev lama (`C:\AuliaGateway-test`, `C:\AuliaGateway-service-test`)
  **tidak diubah** — kalau user ingin rig itu juga pakai Git, itu kerja
  terpisah (sempat didiskusikan, belum diputuskan/dieksekusi).
- TODO-Q3c/Q3d/Q3f (cutover produksi `aulia3` ke WinSW) **tidak
  terpengaruh** oleh sesi ini — tetap rencana terpisah yang belum
  dieksekusi.

## Titik masuk sesi berikutnya

- **Baca**: `docs/requirements/2026-10-08-installer-git-based-source.md`,
  `docs/design/2026-10-08-installer-git-based-source.md`,
  `docs/TODO.md` TODO-Q3g.
- **Jalankan**: `powershell -NoProfile -ExecutionPolicy Bypass -File
  C:\Projects\evolution-gateway\installer\tests\run-checks.ps1` (regresi
  cek lokal; harus tetap 9/9).
