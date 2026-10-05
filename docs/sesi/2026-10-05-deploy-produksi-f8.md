# Checkpoint Sesi — Deploy Produksi TODO-F8 (teks pesan diedit)

- **Tanggal**: 2026-10-05
- **Status**: selesai — F7 & F8 terverifikasi di produksi; realtime produksi tersambung.
- **Repo / target**:
  - WA-Gateway `C:\Projects\evolution-gateway` branch `evolution` (`1c3c57f`, kode = `e653de5`) → produksi **aulia3** `D:\evolution-gateway` + Evolution `D:\evolution-api-server` (2.3.7).
  - AuliaPos `C:\xampp\htdocs\aulia` branch `v2.4` (`a127d63`) → produksi **AULIA-SERVER2** `W:\htdocs\aulia` (= `\\aulia-server2\...`).
- **Rujukan**: `docs/design/2026-10-05-tampilkan-teks-edit-tervalidasi-inbox.md`, `docs/sesi/2026-10-05-penutupan-gate-f8.md`.

## 1. Ringkasan eksekusi

### Gateway aulia3
- Backup: `D:\backup\evolution-gateway-pre-f8-20261005-134323`; source Evolution `whatsapp.baileys.service.ts.pre-f8-*` + `.orig-20261005134404`.
- Update adapter ke build `evolution` (F6 + realtime + F8). `.env` produksi dipertahankan (tidak ditimpa).
- **npm tidak tersedia di aulia3** dan `ws` (dependency baru) belum ada → paket `ws@8.22.0` disalin manual dari `node_modules` lokal.
- Patch LID diterapkan: `installer/apply-lid-preservation-patch.ps1 -EvolutionDir D:\evolution-api-server` (exit 0).
- Restart Evolution (task `AuliaEvolution`) lalu adapter (`scripts/restart-adapter.ps1`).
- `EVOLUTION_DECRYPT_MESSAGE_EDIT=1` diset di `.env` (backup `.env.pre-f8-20261005-135405`), adapter di-restart.

### POS AULIA-SERVER2 (via `W:\htdocs\aulia`)
- `git fetch` + `git merge --ff-only origin/v2.4`: `7fd5265` → `a127d63` (tanpa perubahan `composer.lock` → tidak perlu `composer install`).
- Migrasi dijalankan lewat endpoint aplikasi `/migrasi-manual` (login admin `aan`, `konfirmasi=JALANKAN`) karena tidak ada akses shell/WinRM ke server2. Hasil: kolom `edited_text_resolved_at` dibuat di `aulia_inboxdb.messages` (bukan `aulia_kasirdb`); history `2026-10-05-000001` batch 13.

## 2. Verifikasi (nyata)

- Gateway aulia3: port 3000/8080/5432 listen; `ws` terpasang; handshake WS `/realtime` balas **401** untuk tiket salah; pesan masuk diproses normal.
- POS smoke: `/inbox`, `/transaksi`, `/tagihan`, `/laporan` → 200 tanpa error.
- **F7** (flag OFF, 2026-10-05 13:52–13:53 WIB): `edited` `A59B1DFA…` `matched:true`; `deleted` `A524E624…` `matched:true`. DB: id 1116 `edited_at=13:52:40` (teks asli dipertahankan), id 1118 `revoked_at=13:53:20`.
- **F8** (flag ON): id **1119** (`A5B51CDE…`) incoming → `text="Telah diedit"`, `edited_text_resolved_at=13:55:02`. Baris lama tetap `NULL` (tanpa backfill).
- Realtime: log adapter `[REALTIME] browser connected` dari browser POS produksi.

## 3. Rollback

- Adapter: balikkan `D:\evolution-gateway` dari `D:\backup\evolution-gateway-pre-f8-*`, restart task.
- Evolution: restore `whatsapp.baileys.service.ts.orig-20261005134404` (`Copy-Item`), restart `AuliaEvolution`.
- Flag dekripsi: kosongkan `EVOLUTION_DECRYPT_MESSAGE_EDIT` di `.env` + restart adapter (mengembalikan perilaku F7 tanpa ubah kode).
- POS kode: `git reset`/checkout ke `7fd5265` (butuh persetujuan; operasi Git destruktif). Migrasi: kolom nullable boleh dibiarkan (aman).
- DB backup: `C:\Users\Anshar\AppData\Local\Temp\kilo\aulia-server2-backup-20261005-134651`.

## 4. Keputusan penting

- Gate (a) ditutup lewat E2E nyata di produksi (bukan aulia3-only).
- Flag dekripsi **ON** di aulia3 (perilaku F8 aktif).
- Route `/migrasi-manual` **dibiarkan aktif** untuk sementara (keputusan user) — dicatat di `docs/TODO.md`.

## 5. Belum / risiko

- **Soak realtime 30–60 menit** dimulai 2026-10-05 13:56 WIB (follow-up `TODO-REALTIME-SOAK`).
- Patch LID Evolution harus **di-reapply setiap upgrade Evolution** (pola TODO-F3).
- Update adapter aulia3 **manual** (bukan git; `npm` tidak ada) — prosedur perlu didokumentasikan.
- `/migrasi-manual` masih aktif di produksi (permukaan serangan) — lihat TODO.
