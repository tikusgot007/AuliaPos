# Runbook — M1 Gelombang 1 TASK-017 (Pengukuran AC-001 di Gateway Nyata)

> [!IMPORTANT]
> **Dokumen non-normatif.** Sumber normatif tetap
> `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` dan
> `spec/spec-process-m1-wave1-incoming-reliability.md`. Bila ada konflik, keduanya menang.
> Dokumen ini hanya panduan operasional untuk menjalankan satu task VERIFY.

| Item | Nilai |
| --- | --- |
| Tanggal | 2026-09-23 |
| Task | TASK-017 (`VERIFY/APPROVAL`, AC-001) |
| Kode M1 | WA-Gateway `feature/stage-1-reliability` @ `065f683` |
| Folder live (PM2) | `C:\projects\WA-Gateway` — `master` @ `e18f716` |
| Kode yang diukur | `065f683` — **setelah** deploy fast-forward (bagian 3) |
| Titik rollback | `e18f716` |
| Perubahan jendela uji | **Tidak ada jendela wajib.** Modul Inbox belum dipakai staf dan tidak ada pelanggan yang menunggu di nomor itu (dikonfirmasi user, 2026-09-23). Dampak nyata hanya keterlambatan sementara. |

## 1. Tujuan

Membuktikan AC-001 di Gateway nyata: ketika proses dihentikan ±30 detik dan 10 pesan pelanggan
dikirim selama itu, setelah proses dijalankan lagi **10 pesan muncul di `incoming_queue` dan di
AuliaPos (0 hilang, 0 duplikat)**. Diulang **3 kali**.

## 2. Prasyarat

1. **M1 sudah berjalan dari folder live.** Saat ini PM2 masih menjalankan `master` `e18f716`;
   tanpa deploy, pengukuran ini menguji kode lama dan hasilnya tidak bermakna (lihat bagian 3).
2. **`docs/decisions/` tersedia di branch AuliaPos aktif** — tempat log hasil wajib ditulis.
   Folder itu saat ini hanya ada di branch `v2.2`; pulihkan lebih dulu.
3. **HP tes siap**, dan tiap pesan diberi penanda unik per percobaan (mis. `AC001-P1-01` …
   `AC001-P1-10`) supaya mudah dihitung.
4. **Izin eksplisit pemilik sistem** — task ini menghentikan proses yang sedang berjalan.

## 3. DEPLOY (sekali, prasyarat TASK-017)

Jalankan dari PowerShell (setiap perintah PM2 dibungkus `cmd /c`).

```powershell
# 1. Pastikan folder live bersih (tidak ada perubahan tak ter-commit)
git -C C:\projects\WA-Gateway status --short

# 2. Catat titik rollback — HARUS e18f716
git -C C:\projects\WA-Gateway rev-parse --short HEAD

# 3. Merge fast-forward branch M1
git -C C:\projects\WA-Gateway merge --ff-only feature/stage-1-reliability

# 4. Verifikasi — HARUS 065f683
git -C C:\projects\WA-Gateway rev-parse --short HEAD

# 5. Restart proses, lalu pastikan masih menunjuk folder live dan status online
cmd /c "pm2 restart wa-gateway"
cmd /c "pm2 describe wa-gateway"
```

Merge ini **fast-forward** (terverifikasi: `master` adalah ancestor langsung dari `065f683`),
sehingga tidak ada konflik dan tidak ada commit yang hilang.

> [!CAUTION]
> **Jangan menyentuh `C:\projects\WA-Gateway\auth\`.** Folder itu memuat sesi WhatsApp aktif
> (239 berkas) dan hanya ada di folder live — bukan di worktree M1.

## 4. Per percobaan (n = 1, 2, 3)

```powershell
# 1. Cek awal — harus online
cmd /c "pm2 describe wa-gateway"

# 2. Hentikan proses
cmd /c "pm2 stop wa-gateway"

# 3-4. Tunggu ±30 detik, lalu kirim 10 pesan dari HP tes
#      (penanda AC001-P<n>-01 ... AC001-P<n>-10)

# 5. Jalankan lagi, lalu tunggu sampai log menunjukkan "connected"
cmd /c "pm2 start wa-gateway"
cmd /c "pm2 logs wa-gateway --nostream --lines 100"
```

Setelah proses kembali `connected`, lanjutkan verifikasi:

- Verifikasi `incoming_queue`: **10 baris, 0 duplikat**. Path DB SQLite mengikuti
  `src/config/index.js` pada branch yang sedang berjalan — pastikan saat eksekusi.
- Verifikasi AuliaPos: 10 pesan muncul di Inbox (tab **Belum Diambil** / tabel `messages`
  grup `inbox`), tanpa dobel.
- Catat hasil pada tabel bagian 5, lalu jeda sebelum percobaan berikutnya.

## 5. Tabel bukti (isi saat eksekusi)

| Percobaan | Waktu | Diterima | Hilang | Duplikat | Catatan |
| --- | --- | --- | --- | --- | --- |
| 1 | | /10 | | | |
| 2 | | /10 | | | |
| 3 | | /10 | | | |

## 6. Kriteria lulus / gagal

- **Lulus:** 3 percobaan, masing-masing 10/10 diterima, 0 hilang, 0 duplikat → lanjut TASK-018.
- **Gagal:** Gelombang 1 **tidak ditutup**. Simpan log, catat di decision log baru, lalu buka
  kembali fase terkait. Bila penyebabnya E-02 (ephemeral/view-once) atau E-07 (upsert tanpa
  konten), catat sebagai temuan gelombang berikutnya — **jangan perluas plan** (RISK-004).

## 7. Rollback

```powershell
git -C C:\projects\WA-Gateway reset --hard e18f716
cmd /c "pm2 restart wa-gateway"
```

## 8. Catatan operasional (terbukti di mesin ini)

- PM2 **wajib dipanggil lewat `cmd /c`**: shim PowerShell `pm2.ps1` diblokir execution policy
  (`cannot be loaded because running scripts is disabled`).
- Gunakan `--nostream` pada `pm2 logs` agar perintah tidak menggantung.
- Pesan yang tiba selama proses berhenti **memang diharapkan** terkirim ulang setelah start —
  itulah perilaku yang diukur, bukan kerusakan.

## 9. Rujukan

- `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` — TASK-017/018, RISK-003/004, §9 Rollback.
- `spec/spec-process-m1-wave1-incoming-reliability.md` — AC-001 dan batas kelulusan AC-001…AC-018.
- `docs/proposal-amandemen-plan-m1-wave1-2026-09-23.md` — usulan perubahan plan yang melandasi runbook ini.
- `docs/decisions/*` pada branch `v2.2` — log eksekusi Fase 1–3 dan baseline pengukuran.
