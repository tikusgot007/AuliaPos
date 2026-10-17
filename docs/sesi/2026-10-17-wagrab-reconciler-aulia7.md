# Checkpoint Sesi — Worker reconciler WAGRAB + alarm Inbox (aulia7)

- **Tanggal**: 2026-10-17
- **Status**: sebagian — worker terpasang & task aktif di `aulia7`; **endpoint tujuan belum ada di produksi**
- **Repo / branch**: AuliaPos `v2.4` (docs saja di sesi ini), host `aulia7` (script + task)

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

- **Deploy endpoint ke produksi.** `POST https://192.168.1.10/aulia/api/inbox/sync-missing` masih `404 Can't find a route for 'POST: api/inbox/sync-missing'`; `app/Controllers/Api/` tidak ada di `W:\htdocs\aulia`, HEAD produksi `2662456`. Endpoint baru terverifikasi lokal (feature test 4/4 lulus).
- Selama endpoint belum ada, worker akan `exit 1` setiap kali ada kandidat (saat ini `candidates=0`, jadi task tampak sehat). Jangan biarkan kondisi ini permanen.
- Alarm Telegram belum aktif (`TELEGRAM_BOT_TOKEN`/`TELEGRAM_CHAT_ID` kosong).
- Uji end-to-end dengan pesan nyata yang benar-benar drop belum dilakukan.

## Belum diverifikasi / risiko

- Task belum diamati melewati menit ke-10 (jebakan `<Duration>PT10M`); perlu konfirmasi 3+ tembakan berurutan setelah menit ke-10.
- Payload besar (burst album) belum diuji ke endpoint produksi.
- WAGRAB DB pada host memakai WAL; worker membuka read-only tanpa menyalin sidecar — pembacaan konsisten belum diuji saat writer aktif menulis deras.

## Titik masuk sesi berikutnya

- **Baca**: `docs/design/2026-10-17-wagrab-reconciler.md`, `app/Controllers/Api/InboxReconcile.php`.
- **Jalankan**: `schtasks /Query /TN WAGRAB-Reconciler /V /FO LIST` di `aulia7`; `Get-Content C:\AuliaPosGateway\logs\wagrab-reconciler.log -Tail 20`.
