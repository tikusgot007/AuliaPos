# Checkpoint Sesi

- **Tanggal**: 2026-10-10
- **Status**: selesai (perubahan produksi dikerjakan + diverifikasi; dokumentasi sempat tertinggal — ditutup sesi berikutnya)
- **Repo / branch**: AuliaPos `v2.4` (dokumentasi saja); `evolution-api-server` (working tree, detached HEAD `cd800f2`); gateway produksi `aulia7` (konfigurasi langsung, bukan repo lokal)

## Selesai

- **TODO-F3** — Perbaikan korupsi encoding (mojibake) pada komentar upstream Portugis di
  `whatsapp.baileys.service.ts` (aulia7), akibat double-encoding saat patch
  `PATCH-ADAPTER`/`PATCH-ADAPTER-LID` diterapkan manual sebelumnya. Kedua patch
  diterapkan ulang bersih di atas file upstream asli di dev
  (`C:\Projects\evolution-api-server`), lalu hasilnya (SHA256 `004E1D6B...`)
  disalin menggantikan file korup di aulia7. Backup lama:
  `whatsapp.baileys.service.ts.bak-encoding-20261010121735`. Service
  `AuliaPosGatewayEvolution` di-restart; instance `aulia-toko` kembali
  `connected` ~2 detik pasca-restart.
- **TODO-F8** — Aktifkan `EVOLUTION_DECRYPT_MESSAGE_EDIT=1` di `.env` adapter
  aulia7 (`C:\AuliaPosGateway\evolution-gateway\.env`). Sebelumnya flag
  kosong → adapter hanya kirim marker lifecycle `edited` tanpa `edited_text`,
  CI4 jatuh ke `MessageModel::markLifecycle()` (hanya isi `edited_at`), teks
  diedit tidak pernah ter-update di Inbox. Backup `.env.bak-20261010110856`;
  service `AuliaPosGatewayAdapter` di-restart.
- **TODO-F7** — Aktifkan event `MESSAGES_DELETE` di webhook Evolution
  (`POST /webhook/set/aulia-toko`) aulia7. Sebelumnya hanya subscribe
  `MESSAGES_UPSERT`/`MESSAGES_UPDATE`/`CONNECTION_UPDATE`/`QRCODE_UPDATED` —
  event hapus pesan tidak pernah terkirim ke adapter sama sekali (beda dari
  kasus edit: bukan payload kosong, event-nya memang tak lewat).
- **Audit lanjutan** (verifikasi saja, tanpa perubahan): `readreceipts: all`
  sudah aktif (TODO-Q3e, tidak ada masalah); patch Evolution view-once + LID
  preservation sudah terpasang (TODO-F3); Nudge 1/2 (TODO-F9) ternyata **sudah
  live** di produksi sejak deploy `a55d86e` (2026-10-09) — catatan "belum
  commit/deploy" di `docs/TODO.md` sudah basi dan dikoreksi.
- `docs/CHANGELOG.md` + `docs/TODO.md` diperbarui (3 entri CHANGELOG baru;
  status Nudge F9 & repair BL14 dikoreksi di TODO.md).

## Keputusan penting

- Investigasi dipicu laporan user: pesan masuk dari Hamet (`6281937281996`)
  menampilkan teks lama "Ok siao" padahal pelanggan sudah mengedit jadi
  "oke siap" — `edited_at` terisi tapi `edited_text_resolved_at` NULL.
- User menyetujui aktivasi langsung di produksi (opsi 2 dari 2 opsi yang
  ditawarkan: lapor saja vs aktifkan) — perubahan config produksi dengan
  approval eksplisit per permintaan (AGENTS.md §7 override).
- Perbaikan encoding patch Evolution dilakukan di **dev** dulu (source of
  truth bersih untuk upgrade Evolution berikutnya), baru disalin ke aulia7 —
  bukan diedit langsung di produksi.
- Pesan lama milik Hamet ("Ok siao") **tidak** dikoreksi retroaktif — payload
  dekripsi hanya tersedia saat event diterima, bukan disimpan ulang.

## Tersisa

Lihat `docs/TODO.md` — TODO-F3/F7/F8 kini berstatus aktif/terpasang;
TODO-F10 (kesehatan gateway aulia7) tetap "sedang dikerjakan" untuk sisa
keputusan remediation sesi/LID & pemeriksaan gap berkala.

## Belum diverifikasi / risiko

- Fitur hapus pesan (`MESSAGES_DELETE`) sempat **belum** diuji end-to-end
  di akhir sesi sebelumnya — **sudah dikonfirmasi user** pasca-sesi (badge
  "dihapus" muncul di Inbox, `revoked_at` terisi di DB untuk message id
  3127 & 3390). Lihat `docs/CHANGELOG.md` 2026-10-10.
- Dua perubahan `.env`/webhook di aulia7 bersifat **konfigurasi langsung di
  server produksi**, bukan lewat commit/deploy git — tidak ada mekanisme
  rollback otomatis selain restore file backup manual.
- `evolution-api-server` (dev, `C:\Projects`) masih **detached HEAD** dengan
  1 file uncommitted (`whatsapp.baileys.service.ts`, hasil re-apply patch
  bersih) — belum di-commit ke branch manapun.

## Titik masuk sesi berikutnya

- **Baca**: `docs/CHANGELOG.md` entri 2026-10-10 (3 entri: encoding F3, F8,
  F7); `docs/TODO.md` TODO-F3/F7/F8/F10/F9.
- **Jalankan**: tidak ada aksi wajib — fitur sudah live & terverifikasi.
  Opsional: commit perubahan `whatsapp.baileys.service.ts` di dev
  (`C:\Projects\evolution-api-server`) ke branch yang sesuai, agar tidak
  hilang di working tree detached HEAD.
