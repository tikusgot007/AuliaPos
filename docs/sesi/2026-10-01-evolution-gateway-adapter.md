# Checkpoint Sesi

- **Tanggal**: 2026-10-01
- **Status**: selesai
- **Repo / branch**: `evolution-gateway` (repo baru, `master`) — adapter AuliaPos ↔ Evolution API. Konsumen: `aulia-app` `v2.4` (kode tidak diubah).

## Selesai

- Repo baru `evolution-gateway` (`C:\Projects\evolution-gateway`): adapter Node.js yang mempertahankan kontrak HTTP WA-Gateway sehingga AuliaPos tidak diubah; mesin durability SQLite (buffer masuk, retry, dead-letter, idempotensi `operation_id`) di-reuse dari `spike/fonnte`. Commit `ea1cd98`.
- Evolution API v2.3.7 dijalankan native di mesin dev (Node + **PostgreSQL 16**), instance `aulia-uji` (nomor uji `62881082323928`).
- Uji end-to-end via UI Inbox AuliaPos: teks, gambar, dokumen, sticker, quote teks, quote sticker, forward teks, grup (masuk & keluar).
- Perbaikan bug saat uji: (1) media keluar `media_ref` kosong ("Gambar tidak tersedia"); (2) `sendSticker` gagal `500 Invalid URL`; (3) quote **sticker** muncul di WhatsApp Web tapi tidak di HP (field `bytes` terkirim sebagai objek JSON, bukan base64).
- Fitur baru: dukungan **grup** — `group_name` dari `/group/findGroupInfos` (cache 10 menit) + pemetaan pengirim LID→nomor (`src/evolution/groupInfo.js`).
- Migration AuliaPos yang sudah ada `2026-09-30-000001_AddSessionHealthToGatewayStatus` diterapkan ke `aulia_inboxdb` (disetujui user) agar heartbeat/badge status berfungsi.
- Checkpoint ini.

## Keputusan penting

- Mode Evolution `WHATSAPP-BAILEYS`, nomor uji terpisah, adapter di repository terpisah — alasan: AuliaPos tidak diubah; risiko ban dicatat.
- Evolution dipindah dari MariaDB → **PostgreSQL** — alasan: v2.3.7 menjalankan raw SQL khusus PostgreSQL sehingga handler `messages.upsert` gagal (`P2010`) di MariaDB dan webhook tidak terkirim.
- Media masuk **dan keluar** disimpan lokal di adapter (`MEDIA_STORE_DIR`), direferensikan sebagai `evolution-media:<id>` — alasan: Evolution tidak memberi `directPath`/`mediaKey`; POS butuh referensi untuk menampilkan media.
- `quoted.message` dikirim dengan field `bytes` sebagai **base64** + `quoted.key` dibersihkan — alasan: HP gagal merender quote (khusus sticker) bila bytes berbentuk objek JSON.
- Info grup diambil & di-cache (bukan per pesan) — alasan: menghindari panggilan Evolution berulang.

## Tersisa / TODO

- [ ] Sediakan remote + push repo `evolution-gateway` (owner: user)
- [ ] Siapkan stack produksi di PC gateway (Docker: Evolution + PostgreSQL + Redis) dan pindahkan `.env` (owner: user)
- [ ] (opsional) Tahap 5 lanjutan: auto-prune media terjadwal, label/pin/contact sync, dokumentasi operasional final

## Belum diverifikasi / risiko

- Mode `WHATSAPP-BAILEYS` tetap **unofficial** (risiko ban WhatsApp; bukan solusi yang menghilangkan risiko).
- `session_health` SELALU `ok` — tidak ada detektor "connected tapi diam-diam gagal dekripsi" di jalur Evolution.
- Retensi media adapter (`MEDIA_RETENTION_DAYS`, default 7 hari) → media lama tidak bisa dimuat ulang.
- Evolution dijalankan **native di mesin dev** (bukan Docker); konfigurasi produksi belum diuji.
- `@lid` masih ada; baru dipetakan sebagian (pengirim grup). Chat 1:1 dikirim ke PN dan tetap diterima/dibaca (READ).

## Titik masuk sesi berikutnya

- **Baca**: `plan/2026-10-01-evolution-gateway-adapter.md`; `C:\Projects\evolution-gateway\README.md` + `docs/CHANGELOG.md` (commit `ea1cd98`).
- **Jalankan**:
  - PostgreSQL: `"C:\Program Files\PostgreSQL\16\bin\pg_ctl.exe" -D C:\Projects\pgdata-evolution -o "-p 5432" start`
  - Evolution API: `cd C:\Projects\evolution-api-server && npm run start` (port 8080)
  - Adapter: `cd C:\Projects\evolution-gateway && npm start` (port 3000)
  - Test adapter: `cd C:\Projects\evolution-gateway && npm test`
