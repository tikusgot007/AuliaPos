# Checkpoint Sesi — Inbox ke Kasir ("Buat Pesanan") + aksi saran M.A.R.A

- **Tanggal**: 2026-10-17
- **Status**: kode + test selesai dan hijau di DB uji; **belum di-commit, migrasi dev/produksi belum dijalankan, belum diuji di browser**
- **Repo / branch**: AuliaPos `C:\xampp\htdocs\aulia`, branch `v2.4`

## Selesai

- Tier A: requirements (Gate 1) dan design (Gate 2, Opsi A) disetujui:
  `docs/requirements/2026-10-16-inbox-ke-kasir.md`, `docs/design/2026-10-16-inbox-ke-kasir.md`.
- Endpoint `Inbox::buatPesanan()` (`POST /inbox/percakapan/(:num)/buat-pesanan`, filter `['auth','csrf']`),
  `PelangganModel::findByNomor()`, tombol di `inbox-page.js`, aksi `buka_kasir` pada saran M.A.R.A
  (`MaraController`, `MaraSuggestionModel`, `inbox-suggestions.js`).
- Dua migrasi additive grup `inbox`: `2026-10-16-000001_AddPelangganIdToConversations`,
  `2026-10-17-000001_AddActionToMaraSuggestions`. Dijalankan **hanya** ke `aulia_inboxdb_test` lewat
  `aulia:sync-inbox-test-db` (26 migrasi); `aulia_inboxdb` (live) dikonfirmasi tidak berubah (0 kolom baru).
- CSRF: per-route, **bukan** global (TODO-BL22b tetap berlaku); `Filters.php`/`Security.php` tidak diubah.

## Verifikasi (dijalankan nyata)

| Suite | Hasil |
|---|---|
| Unit (`phpunit.xml`) | 134 tes, 7 incomplete lama |
| Feature (`phpunit.feature.xml`, setelah `aulia:sync-inbox-test-db`) | 313 tes / 1178 assertion, 3 skip lama |
| Integration | 65 tes lulus |
| JS (`node --test tests/js/*.test.js`) | 136 tes lulus |

- Baru: `InboxBuatPesananTest` 28, `PelangganFindByNomorTest` 13 (MariaDB sungguhan), `MaraSuggestionsTest` +3,
  `inbox-buat-pesanan.test.js` 14, `inbox-suggestions.test.js` +6.
- Uji mutasi (kode dirusak sementara lalu dipulihkan, tiap kali tes gagal): hapus verifikasi PHP nomor,
  hapus REPLACE kurung, hapus filter csrf, longgarkan regex `chat_id`, hapus cek nama bentrok, longgarkan
  whitelist `action`, hapus header CSRF, hapus pembaruan token, hapus guard double-click, hapus whitelist/tipe
  render tombol aksi.

## Temuan selama pengujian

- **Bug nyata ditemukan dan diperbaiki**: `buatPesanan()` memakai `REPLACE` tanpa menghapus `(` dan `)`, jadi
  `no_hp` berformat `(0812) 3456 7890` tidak ketemu. Pencarian dipindah ke `PelangganModel::findByNomor()` dan
  diuji di MariaDB.
- Filter `csrf` di harness feature melempar `SecurityException` (403) alih-alih mengembalikan respons;
  di produksi dengan header `X-Requested-With` kegagalan kembali sebagai 403 JSON. Test memeriksa pengecualiannya.
- `TestResponse::getBody()` membungkus output sebagai HTML (`&` jadi `&amp;`) sehingga `redirect_url` rusak;
  controller sudah benar. Test AC-8 memakai body mentah.
- `PelangganFindByNomorTest` membuat tabel `pelanggan` sementara di `aulia_inboxdb_test` (bukan DB lain) dan
  menjatuhkannya lagi; bootstrap menolak berjalan bila bukan DB uji.

## Belum dikerjakan / belum terverifikasi

- Commit; migrasi `aulia_inboxdb` dev dan produksi (urutan: migrasi **sebelum** deploy kode).
- Uji manual di browser: tombol di header, tab baru terisi pelanggan, fallback pop-up diblokir, toast 409/422,
  rotasi token CSRF saat dua tab Inbox terbuka (risiko diterima).
- Sisi M.A.R.A belum mengonfirmasi field `action`.
- Dua kasir menekan tombol bersamaan (AC-9) bergantung pada `SELECT … FOR UPDATE` tetapi tidak bisa direproduksi
  dalam satu proses PHP; hanya klik beruntun yang diuji.
- Ceiling 20 kandidat pencocokan nomor terdokumentasi di test; belum ada kolom `no_hp_normal` ber-index.
