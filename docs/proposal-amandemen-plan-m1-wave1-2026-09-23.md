# Proposal Amandemen Plan M1 Gelombang 1 — 2026-09-23

> [!IMPORTANT]
> **Dokumen non-normatif (usulan).** Sumber normatif tetap
> `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md`. Dokumen ini **tidak mengubah** plan;
> ia hanya mengusulkan empat perubahan supaya dieksekusi oleh `/sdlc-plan-tasks`. Bila ada konflik,
> plan yang berlaku sampai perubahan benar-benar diterapkan.

| Item | Nilai |
| --- | --- |
| Tanggal | 2026-09-23 |
| Plan target | `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` (v1.1, status `In progress`) |
| Task terdampak | TASK-017, RISK-003, + satu task baru (TASK-019) |
| Alasan utama | Kode M1 belum berjalan di folder live → TASK-017 berisiko mengukur kode lama |
| Dikonfirmasi user | Tidak ada pengguna Inbox produksi; tidak ada pelanggan yang menunggu di nomor itu |

## 1. Dasar temuan (dapat diverifikasi ulang)

| Pemeriksaan | Perintah | Hasil |
| --- | --- | --- |
| Proses produksi PM2 | `cmd /c "pm2 describe wa-gateway"` | `script path = C:\projects\WA-Gateway\src\app\index.js`, `exec cwd = C:\projects\WA-Gateway`, `status online` |
| Folder live | `git -C C:\projects\WA-Gateway rev-parse --short HEAD` | `master` @ `e18f716`, working tree bersih |
| Branch M1 | `git -C C:\projects\WA-Gateway rev-parse --short feature/stage-1-reliability` | `065f683` (belum di-merge) |
| Jarak komit | `git rev-list --count master..065f683` | `21` (master tertinggal 8 komit dari base `091fe19`) |
| Kelayakan merge | `git merge-base --is-ancestor master 065f683` | exit `0` → **fast-forward**, tanpa konflik |
| Sesi WhatsApp | `Test-Path C:\projects\WA-Gateway\auth` | ada (239 berkas) — **hanya** di folder live |
| Sesi di worktree M1 | `Test-Path C:\projects\WA-Gateway-m1\auth` | **tidak ada** (`.env` juga tidak ada) |
| Status lingkungan | log `v2.2` `2026-09-21-m1-ticket01-baseline.md` | *"Database: bukan produksi (AuliaPos lokal berisi data tes)"* |

**Kesimpulan:** TASK-017 sebagaimana tertulis menstop/start proses yang menjalankan `e18f716`
(kode lama yang masih memuat GW-08), sedangkan perbaikan M1 ada di `065f683` dan belum di-merge.
Worktree M1 **tidak bisa** dipakai untuk mengukur karena tidak memiliki sesi WhatsApp maupun `.env`.
Karena itu mengukur AC-001 mensyaratkan **kode M1 berjalan dari folder live** — satu langkah yang
belum ada di plan.

## 2. Empat perubahan yang diusulkan

### 2.1 RISK-003 — ditulis ulang

Ganti seluruh isi RISK-003 dengan:

> **RISK-003 (rendah):** TASK-017 menghentikan proses Gateway ±90 detik total (3 × 30 detik) plus
> waktu pengiriman manual 10 pesan tiap percobaan. Lingkungan ini **bukan produksi**: modul Inbox
> belum dipakai staf dan tidak ada pelanggan yang bergantung pada nomor tersebut (dikonfirmasi user
> 2026-09-23). Dampak nyata hanya **keterlambatan sementara** bagi pesan yang tiba saat proses
> berhenti — bukan kehilangan, karena WhatsApp mengirim ulang dan justru itu yang diukur AC-001.
> **Tidak ada jendela waktu wajib.** APPROVAL eksplisit tetap diwajibkan karena task ini menghentikan
> proses yang sedang berjalan.

Yang **dihapus**: kalimat *"berdampak langsung ke staf yang memakai Inbox"* dan seluruh frasa
*"MUST hanya dijalankan >21:00 atau <08:00"*.

### 2.2 TASK-017 — hapus note klarifikasi v1.1, tambah prasyarat

- Hapus note klarifikasi v1.1 yang mewajibkan jendela waktu tetap.
- Tambahkan prasyarat pada deskripsi task:
  *"MUST dijalankan hanya setelah TASK-019 selesai. Kode yang diukur MUST `065f683`; menjalankannya
  selagi folder live masih `e18f716` mengukur kode lama dan hasilnya tidak sah."*

### 2.3 TASK-019 (baru) — DEPLOY, prasyarat TASK-017

> **TASK-019 (baru, DEPLOY):** Arahkan folder live ke kode M1 secara fast-forward dan restart proses.
>
> - `git -C C:\projects\WA-Gateway status --short` → **harus kosong** sebelum mulai.
> - Catat `e18f716` sebagai titik rollback.
> - `git -C C:\projects\WA-Gateway merge --ff-only feature/stage-1-reliability` → HEAD harus `065f683`.
> - `cmd /c "pm2 restart wa-gateway"`, lalu `cmd /c "pm2 describe wa-gateway"` → `status online`,
>   `script path` tetap menunjuk folder live.
> - **Jangan menyentuh `C:\projects\WA-Gateway\auth\`.**
> - Dep: — · Files: (WA-Gateway, bukan AuliaPos) · Rollback: `reset --hard e18f716` + restart.
> - Catat hasil deploy (HEAD sebelum/sesudah, waktu, status PM2) di decision log baru.

### 2.4 Housekeeping

- Versi plan `1.1` → `1.2` + baris catatan perubahan (RISK-003 ditulis ulang; TASK-017 prasyarat
  baru; TASK-019 ditambahkan).
- Setelah TASK-018 disetujui: status front-matter `In progress` → selesai.

## 3. Yang TIDAK diusulkan berubah (pagar lingkup)

- Definisi AC-001 tidak diubah — ia tetap diukur apa adanya (10 pesan, 0 hilang, 0 duplikat, 3×).
- Tidak ada perilaku/kode baru; TASK-019 hanya memindahkan branch yang sudah ada ke folder live.
- E-02 (ephemeral/view-once) dan E-07 (upsert tanpa konten) tetap di luar lingkup (RISK-004) —
  bila pengukuran menemukannya, catat sebagai temuan gelombang berikutnya, jangan perluas plan.
- Fase 1–3 tidak dibuka kembali; `auth/` dan sesi WhatsApp tidak disentuh.

## 4. Setelah usulan ini diterapkan

1. **`/sdlc-plan-tasks`** (sesi baru) menerapkan 2.1–2.4 pada plan M1.
2. **`/sdlc-write-code`** mengeksekusi TASK-019 (deploy) → TASK-017 (3 percobaan, mengikuti
   `docs/runbooks/runbook-m1-wave1-task017-ac001-2026-09-23.md`) → menulis decision log ke
   `docs/decisions/` (folder itu perlu dipulihkan dari `v2.2` lebih dulu).
3. **TASK-018**: persetujuan Anda menutup Gelombang 1.

## 5. Rujukan

- `docs/runbooks/runbook-m1-wave1-task017-ac001-2026-09-23.md` — panduan eksekusi TASK-017.
- `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` — dokumen yang akan diamandemen.
- `spec/spec-process-m1-wave1-incoming-reliability.md` — AC-001 dan DoD Gelombang 1.
- `docs/decisions/*` pada `v2.2` — baseline, audit enqueue, dan log eksekusi Fase 1–3.
