# Checkpoint Sesi — Worker reconciler WAGRAB + alarm Inbox (aulia7)

- **Tanggal**: 2026-10-17
- **Status**: selesai — worker + task aktif di `aulia7`, endpoint sudah live di produksi dan lulus smoke test
- **Repo / branch**: AuliaPos `v2.4` (commit `6530f07`, `9747c31`), host `aulia7` (script + task)

## Selesai

- Worker `C:\AuliaPosGateway\scripts\wagrab-reconciler.mjs` di `aulia7` (zero-dependency, ESM, `node:sqlite` read-only). SHA256 `2DA40D00C4AC5144968E684520284610FEA38B78F96FBE32017559EA81F93A3B`.
- Konfigurasi `C:\AuliaPosGateway\config\wagrab-reconciler.json` (+ `.example`), ACL `SYSTEM: Read`; token internal diambil dari `inbox.gatewayToken` produksi tanpa dicetak.
- Scheduled Task `WAGRAB-Reconciler`: `SYSTEM`, `HighestAvailable`, repeat `PT3M` tanpa `Duration`, `IgnoreNew`, `ExecutionTimeLimit PT0S`, action `node.exe --use-system-ca "<script>"`.
- Log worker: `C:\AuliaPosGateway\logs\wagrab-reconciler.log` (rotasi mandiri 5 MB).
- Dokumen: `docs/requirements/2026-10-17-wagrab-reconciler.md`, `docs/design/2026-10-17-wagrab-reconciler.md`.

## Keputusan penting

- Tanpa state pengiriman lokal — idempotensi `wa_message_id` di CI4 sudah cukup; menghindari mode gagal baru.
- `--use-system-ca` wajib: sertifikat HTTPS AULIA-SERVER2 self-signed ada di Root store Windows, tetapi `fetch` Node tidak memakainya tanpa flag ini (terbukti `DEPTH_ZERO_SELF_SIGNED_CERT` → `STATUS=200` setelah flag).
- URL API memakai `https://192.168.1.10/aulia/api/inbox/sync-missing` (bukan `http://…/api/...` yang menjawab 404 dari root Apache).

## Tersisa

- **Alarm Telegram belum aktif** (`TELEGRAM_BOT_TOKEN`/`TELEGRAM_CHAT_ID` kosong). Isi dua nilai itu di `C:\AuliaPosGateway\config\wagrab-reconciler.json` bila alert ingin dipakai.
- Uji end-to-end dengan pesan nyata yang benar-benar drop belum dilakukan — worker sudah terbukti bisa bicara dengan endpoint, tetapi belum pernah menambal pesan sungguhan (sejak deploy belum ada kandidat).
- **Deploy produksi 2026-10-17**: `W:\htdocs\aulia` `2662456` → `9747c31` (fast-forward, working tree bersih, `.env` backup di `W:\htdocs\.env.bak_pre_syncmissing_20261017`). Smoke test: tanpa token 401, token salah 401, token valid + `[]` → 200 `{inserted:0, ignored:0}`, duplikat `wa_message_id` nyata → 200 `{inserted:0, ignored:1}`. `writable/logs` 0 ERROR.

## Belum diverifikasi / risiko

- Task belum diamati melewati menit ke-10 (jebakan `<Duration>PT10M`); perlu konfirmasi 3+ tembakan berurutan setelah menit ke-10.
- Payload besar (burst album) belum diuji ke endpoint produksi.
- WAGRAB DB pada host memakai WAL; worker membuka read-only tanpa menyalin sidecar — pembacaan konsisten belum diuji saat writer aktif menulis deras.

## Titik masuk sesi berikutnya

- **Baca**: `docs/design/2026-10-17-wagrab-reconciler.md`, `app/Controllers/Api/InboxReconcile.php`.
- **Jalankan**: `schtasks /Query /TN WAGRAB-Reconciler /V /FO LIST` di `aulia7`; `Get-Content C:\AuliaPosGateway\logs\wagrab-reconciler.log -Tail 20`.
