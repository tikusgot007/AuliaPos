# Checkpoint Sesi

- **Tanggal**: 2026-10-07
- **Status**: sebagian (fitur ter-deploy & terverifikasi lokal/API; uji E2E produksi via HP belum; auto-start gateway belum andal)
- **Repo / branch**: AuliaPos `feat/whatsapp-read-receipt` → merged `v2.4` (`85f3998`, `0f5a69e`); WA-Gateway `feat/whatsapp-read-receipt` → merged `evolution` (`543f496`)

## Selesai

- Implementasi WhatsApp read receipt dua arah:
  - AuliaPos (Arah 1 minta mark-as-read + endpoint status Arah 2, kolom `messages.delivered_at`/`read_at`, UI centang ganda/biru) — commit `83d1158`, `f32e41b`; PR #54 merged `85f3998`.
  - WA-Gateway (`POST /read`, konsumsi `MESSAGES_UPDATE`, forward status ke CI4) — commit `24e1ea2`, `ecc18c2`; PR #7 merged `543f496`.
- Catatan temuan + opsi deploy gateway — commit `c4340ea`; PR #55 merged `0f5a69e`.
- Verifikasi lokal (rig `C:\AuliaGateway-test`): inject `messages.update` → `delivered_at`/`read_at` terisi, idempoten/monoton, `dead=0`; blue tick nyata di HP terverifikasi setelah `readreceipts=all`.
- Test hijau: AuliaPos unit 61/61, feature 99/99, JS 86/86; gateway `test-read-status.js` OK.
- Deploy produksi:
  - POS `W:\htdocs\aulia` → `85f3998`; `aulia_inboxdb.messages` + kolom `delivered_at`/`read_at`; migrasi dicatat di `aulia_kasirdb.migrations` (`id=32`, batch 14).
  - Gateway `\\AULIA3\D\evolution-gateway` → file baru (backup `_backup_pre-readreceipt-20261007-154416`); adapter kode baru terverifikasi (`{"success":true}`).
  - Instance WhatsApp `aulia-toko`: `readreceipts: all`; privacy `online`/`last` dikembalikan `all` (semula).
- Dokumentasi: `docs/deploy.md` §2 (checklist read receipts), gateway `installer/petunjuk-penggunaan.md` §4.1 + troubleshooting, `docs/CHANGELOG.md` (kedua repo).

## Keputusan penting

- Skema status baca = **kolom baru** (`delivered_at`/`read_at`), TIDAK mengubah ENUM `send_status` — alasan: jaga semantik state internal.
- Trigger Arah 1 = **buka percakapan + tombol "Tandai Dibaca"** (bukan tiap polling) — alasan: hindari spam + pesan lama ikut "terbaca".
- Uji lokal pakai rig `C:\AuliaGateway-test` sebelum produksi.
- Gateway produksi akan diganti ke **Windows Service (WinSW/NSSM)** + kemungkinan PC baru — alasan: scheduled task tidak andal saat reboot; remote via **WinRM** (dipilih).

## Tersisa

Lihat/pindahkan ke `docs/TODO.md` (TODO-Q3c/Q3d/Q3e, TODO-O5).

## Belum diverifikasi / risiko

- Uji E2E **produksi** Arah 1 & 2 via HP belum dilakukan pasca-deploy.
- `AuliaAdapter`/`AuliaEvolution`: startup saat boot tidak andal (task "Running" tanpa proses node; Evolution butuh PG). Perlu service/watchdog.
- `updatePrivacySettings` menutup soket Baileys (instance sempat flapping); hindari panggilan berulang.
- Fitur bergantung privasi akun WhatsApp (`readreceipts: all`) dan kestabilan soket; `@lid` tidak didukung mark-as-read.
- Deploy gateway = salin file manual (bukan git checkout); dependency baru harus disalin manual.

## Titik masuk sesi berikutnya

- **Baca**: `docs/TODO.md` (TODO-Q3c/d/e), `docs/design/2026-10-07-whatsapp-read-receipt.md`.
- **Jalankan**: uji HP E2E produksi; cek log `D:\kilo\logs\{adapter,evolution}.log`; saat PC baru siap, setup WinSW + (opsional) git clone gateway.
