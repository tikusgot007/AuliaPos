# Requirements: Instalasi Gateway Pakai Git (Ganti ZIP) untuk PC Baru

- **Tanggal**: 2026-10-08
- **Status**: draf
- **Tier SDLC**: A
- **Penanggung jawab**: deepseek (aan-deepseek), repo target `C:\Projects\evolution-gateway`

## 1. Tujuan

Mengganti mekanisme pengambilan source code Evolution API dan adapter di
`installer/install.ps1` dari **download ZIP** (`codeload.github.com`) menjadi
**git clone/pull**, supaya instalasi gateway di **PC baru** (termasuk rencana
pindah produksi ke PC baru) bisa di-*update* dengan `git pull`/`checkout`
alih-alih harus unduh ulang seluruh source tiap kali ada perubahan. Ini
menjawab keluhan operasional: update gateway (adapter maupun Evolution API)
saat ini manual-copy/unduh-ulang, bukan alur versi yang wajar.

## 2. Kondisi saat ini (terverifikasi)

- `installer/install.ps1:177-183` (`Ensure-Source`): mengunduh ZIP via
  `Get-ZipUrl` (`install.ps1:89-94`, `codeload.github.com/<repo>/zip/<ref>`),
  ekstrak (`Expand-RepoZip`, `lib/common.ps1:164-182`), **skip total** kalau
  `package.json` sudah ada di tujuan — tidak ada jalur upgrade in-place.
- Dua sumber diunduh begini: Evolution API (`evolution-foundation/evolution-api`,
  pin **tag** `-EvolutionRef` default `2.3.7`) dan adapter
  (`tikusgot007/WA-Gateway`, pin `-AdapterRef` default `master`,
  `install.ps1:239-240`).
- Kedua repo **public** (terverifikasi via GitHub API 2026-10-08): tidak perlu
  token/credential untuk clone.
- Keputusan desain lama (`docs/design/2026-10-02-paket-instalasi-gateway-pc-baru.md:42-50`,
  disetujui 2026-10-02) memilih ZIP dan menolak Git, dengan alasan saat itu:
  "adds Git as a prerequisite and credentials for a possibly-private repo".
  Alasan kedua (repo mungkin privat) **tidak berlaku lagi** — repo sudah
  terverifikasi publik.
- Evolution API **bukan vendor murni**: ada 2 patch lokal wajib yang
  dijalankan otomatis setelah source diambil (`install.ps1:270-271`):
  `apply-viewonce-patch.ps1` (TODO-F3, marker `PATCH-ADAPTER (2026-10-01)`)
  dan `apply-lid-preservation-patch.ps1`. Keduanya idempotent (ada
  self-check + test: `check-viewonce-patch.ps1`, `check-lid-preservation-patch.ps1`).
  Patch ini **wajib tetap diterapkan ulang** setiap kali Evolution API
  di-checkout ke commit/ref baru — ini tidak berubah oleh perubahan ZIP→Git.
- `npm ci` saat ini juga **skip total** kalau `node_modules` sudah ada
  (`install.ps1:260-263`) — dirancang untuk mempercepat resume setelah
  kegagalan step berikutnya, bukan untuk update berkala. Ini jadi gap kalau
  source di-update via git: dependency baru di commit baru tidak akan
  terpasang kalau `node_modules` lama tetap ada.
- `installer/tests/check-static.ps1` saat ini **tidak** melarang penggunaan
  Git (tidak ada assertion anti-Git); hanya melarang scheduled task dan NSSM.
- Produksi (`aulia3`) **belum** memakai mekanisme ini sama sekali — masih
  Scheduled Task + manual copy (TODO-O5, TODO-Q3c). Perubahan ini **tidak**
  menyentuh `aulia3` yang berjalan sekarang; ini hanya mengubah bagaimana
  **instalasi PC baru** (termasuk rencana cutover produksi ke PC baru,
  TODO-Q3d) mengambil source.

## 3. User story

- Sebagai operator yang memasang/mengupgrade gateway, saya ingin source
  Evolution API dan adapter diambil lewat Git, supaya update berikutnya
  cukup `git pull`/`checkout` tanpa unduh ulang semua file.

## 4. Acceptance criteria

- **AC-1**: Given `install.ps1` dijalankan di `InstallRoot` baru (kosong),
  when instalasi selesai, then `evolution-api-server/` dan
  `evolution-gateway/` masing-masing adalah **git working tree** dengan
  `HEAD` sama dengan ref yang diresolve dari `-EvolutionRef`/`-AdapterRef`.
- **AC-2**: Given `install.ps1` dijalankan ulang tanpa perubahan
  `-EvolutionRef`/`-AdapterRef`, when instalasi selesai, then tidak ada
  re-clone (hanya `fetch`, commit tidak berubah), dan `.env`/`data/`/
  `node_modules/` (gitignored) tidak tersentuh.
- **AC-3**: Given `-AdapterRef` atau `-EvolutionRef` diubah ke commit/tag
  lain yang sudah ada di remote, when `install.ps1` dijalankan ulang, then
  working tree checkout ke ref baru (via `fetch`+`checkout`, bukan hapus
  total+clone ulang), dan file gitignored (`.env`, `data/`) tetap utuh.
- **AC-4**: Given Evolution API di-checkout (baik instalasi pertama maupun
  update ke ref baru), when proses checkout selesai, then 2 patch
  (`apply-viewonce-patch.ps1`, `apply-lid-preservation-patch.ps1`)
  dijalankan ulang dan tetap idempotent (exactly 1 marker block masing-masing,
  diverifikasi `check-viewonce-patch.ps1`/`check-lid-preservation-patch.ps1`).
- **AC-5**: Given Git belum terpasang di PC target dan `winget` tersedia,
  when `install.ps1` dijalankan, then Git LTS dipasang otomatis (pola sama
  dengan `Ensure-Node`); given `winget` tidak tersedia, then installer
  berhenti dengan pesan jelas (bukan error samar).
- **AC-6**: Given commit `HEAD` Evolution API atau adapter berubah akibat
  checkout, when langkah berikutnya dijalankan, then `npm ci` **tetap
  dijalankan** untuk repo yang HEAD-nya berubah (tidak di-skip hanya karena
  `node_modules` sudah ada); given `HEAD` tidak berubah, then `npm ci`
  tetap di-skip seperti sekarang (mempertahankan perilaku resume-setelah-gagal).
- **AC-7**: Given adapter dan Evolution API masing-masing punya ref
  independen, when salah satu diupdate, then yang lain **tidak ikut**
  di-fetch/checkout (dua operasi Git terpisah, bukan satu `pull` gabungan).
- **AC-8**: Given paket installer diaudit statis, then `check-static.ps1`
  mengonfirmasi mekanisme sumber berbasis Git terpakai (bukan lagi
  `codeload.github.com`/ZIP untuk kedua sumber ini).
- **AC-9**: Given operator membaca `petunjuk-penggunaan.md`, then dokumen
  menjelaskan mekanisme Git (bukan ZIP) dan cara melakukan update (re-run
  `install.ps1` dengan `-AdapterRef`/`-EvolutionRef` baru).

## 5. Batasan dan di luar cakupan

- Batasan teknis: hanya mengubah **cara source code diambil/diupdate**
  (`Ensure-Source` dan prasyarat Git). Tidak mengubah logika patch Evolution,
  `setup-env.ps1`, `install-postgres.ps1`, registrasi WinSW, firewall,
  pembuatan instance, atau kontrak HTTP CI4.
- Tidak termasuk:
  - Fork Evolution API ke repo sendiri (ditolak — overhead sinkronisasi
    upstream tidak sepadan untuk 2 patch kecil yang sudah idempotent).
  - Perubahan apa pun ke `aulia3` produksi saat ini (masih Scheduled Task).
    Penerapan ke produksi adalah pekerjaan cutover terpisah (TODO-Q3c/Q3d/Q3f)
    yang baru relevan **setelah** PC baru benar-benar dipakai produksi.
  - Auto-update terjadwal/otomatis — update tetap aksi manual operator
    (menjalankan ulang `install.ps1` dengan ref baru).
  - Mengubah pin default `-EvolutionRef` (`2.3.7`) atau `-AdapterRef`
    (`master`) — hanya mekanisme fetch yang berubah, bukan versi yang dipin.

## 6. Dampak aturan bisnis

- [ ] Mengubah aturan bisnis
- [ ] Menyentuh data keuangan (`transaksi`, `pembayaran`, `tagihan`, kas)
- [ ] Mengubah skema database
- [x] Mengubah kontrak POS <-> WA Gateway — **tidak**, ini murni mekanisme
      instalasi/provisioning di sisi gateway; kontrak HTTP CI4 tidak disentuh.
      Dicentang untuk menegaskan sudah dicek, bukan karena berubah.

## 7. Asumsi dan pertanyaan terbuka

- Asumsi: PC target (baru) punya akses internet ke `github.com` saat
  instalasi/update (sama seperti asumsi ZIP saat ini ke `codeload.github.com`).
- Asumsi: `winget` tersedia di PC target untuk memasang Git (fallback manual
  sudah ada polanya untuk Node, akan direplikasi untuk Git).
- Pertanyaan: tidak ada pertanyaan terbuka yang mengubah hasil — opsi dan
  rekomendasi sudah dibahas dan disetujui user di sesi ini (git clone untuk
  kedua sumber, patch tetap runtime, pull terpisah per repo).

## 8. Persetujuan (Gate 1)

- [x] Disetujui oleh: user (chat, "oke deal. gas"), tanggal: 2026-10-08
