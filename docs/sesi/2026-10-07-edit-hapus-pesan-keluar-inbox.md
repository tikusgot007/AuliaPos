# Checkpoint sesi: Edit/Hapus pesan keluar + tampilan "Pesan dihapus" (Inbox)

- **Tanggal**: 2026-10-07
- **Tier**: A (fitur baru + lintas repositori POS ↔ WA Gateway)
- **Dokumen**: `docs/requirements/2026-10-07-edit-hapus-pesan-keluar-inbox.md`,
  `docs/design/2026-10-07-edit-hapus-pesan-keluar-inbox.md`

## Ringkasan

Kasir dapat mengedit (teks, ≤15 menit) dan menghapus (teks & media, "hapus untuk
semua orang") pesan KELUAR dari Inbox AuliaPos. Pesan yang dihapus (baik oleh
pelanggan maupun hasil hapus dari POS lewat webhook `MESSAGES_DELETE`) tampil
sebagai placeholder italic abu-abu "Pesan ini telah dihapus".

## Keputusan (Gate 1 & 2, via chat)

1. Endpoint CI4 di `Inbox` (browser→CI4, sesi `auth`) — BUKAN `InboxGatewayApi`
   (itu arah Gateway→CI4, filter `gatewaytoken`). Token gateway tidak pernah ke browser.
2. Otorisasi cukup `auth` (ikuti `Inbox::kirim()`), tanpa `cekOwnership` — konsisten
   dengan realitas shift; IDOR tetap dicegah (baris harus `outgoing` & milik percakapan).
3. Reuse kolom `revoked_at`/`edited_at`/`edited_text_resolved_at` (TODO-F7/F8); tanpa migrasi.

## Perubahan

- `app/Config/Routes.php`: `POST /inbox/pesan/(:num)/edit` & `/hapus` (filter `auth`).
- `app/Controllers/Inbox.php`: `editPesan()`, `hapusPesan()`, helper `aksiPesanKeluar()`,
  `pesanDiLuarJendelaEdit()`, klien `callGatewayDelete()`/`callGatewayEdit()`/
  `callGatewayPesanKeluar()`; `gatewayFailureResponse()` diperluas mengenali
  `DELETE_UNRESOLVED`/`EDIT_UNRESOLVED`.
- `public/assets/js/inbox-thread.js`: `pesanBisaDiedit()`/`pesanBisaDihapus()`,
  `renderAksiEdit()`/`renderAksiHapus()`, gerbang revoked pada `aksiPesanTersedia()`,
  placeholder revoked di `renderIsiPesan()`, akses `pesanDikenal()`.
- `app/Views/inbox/index.php`: modal edit + modal konfirmasi hapus + handler
  `bukaEditPesan`/`simpanEditPesan`/`bukaKonfirmasiHapus`/`konfirmasiHapusPesan`
  (operation_id stabil, tanpa auto-retry pada 504/jaringan), CSS `.inbox-pesan-dihapus`.
- `docs/CHANGELOG.md`, `docs/TODO.md` (TODO-F12).

## Verifikasi yang DIJALANKAN

- Unit: `phpunit` → **61/61 OK**.
- Feature: `phpunit --configuration phpunit.feature.xml` → **113 OK** (2 skipped pra-eksisting);
  termasuk `InboxPesanKeluarEditHapusTest` **14/14**.
- JS: `node --test tests/js/*.test.js` → **88/88 OK** (inbox-thread 56/56).

## Belum diverifikasi

- Uji manual UI di browser (5 skenario) — butuh lingkungan POS berjalan + adapter +
  WhatsApp nyata; belum dijalankan di sesi ini.
- Perilaku Evolution nyata untuk edit >15 menit (`EDIT_WINDOW_EXPIRED`) dan bentuk
  webhook `MESSAGES_DELETE` untuk pesan KELUAR.
