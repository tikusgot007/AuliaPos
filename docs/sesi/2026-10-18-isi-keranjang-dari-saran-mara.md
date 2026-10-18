# Checkpoint Sesi — Isi keranjang Kasir dari saran M.A.R.A

- **Tanggal**: 2026-10-18
- **Status**: selesai di dev — menunggu review/Gate 3 dan deploy
- **Repo / branch**: AuliaPos `C:\xampp\htdocs\aulia`, `v2.4`
- **Backlog**: `TODO-INBOX-KASIR3`
- **Dokumen**: `docs/requirements/2026-10-18-isi-keranjang-dari-saran-mara.md` (Gate 1), `docs/design/2026-10-18-isi-keranjang-dari-saran-mara.md` (Gate 2)

## Selesai

- Migrasi Inbox menambah `mara_suggestions.cart_items` nullable.
- Endpoint saran M.A.R.A memvalidasi `cart_items`, hanya menyimpan `produk_id` dan `jumlah`, membatasi 20 baris dan jumlah 1–999, serta menjumlahkan produk kembar.
- "Buka di Kasir" membawa `saran_id`; Kasir membangun ulang keranjang dari tabel `produk`, bukan dari harga/nama yang dikirim M.A.R.A.
- Saran harus aktif, belum kedaluwarsa, milik percakapan yang tertaut ke pelanggan URL. Produk tidak aktif, harga 0, atau tidak ada dilewati dan menghasilkan toast peringatan.
- JS Inbox/Kasir memakai `?v=filemtime` agar pembaruan skrip tidak tersangkut cache browser.
- Test: feature 366 (3 skip lama), unit 134 (7 incomplete lama), integrasi 65, seluruh test JS lulus. Mutasi 13/13 terbunuh.
- Smoke HTTP dan uji browser dev lulus: Dompet 10×Rp1.100 dan 4R 5×Rp2.500 masuk; Banner harga 0 dan id palsu ditolak. Saran simulasi dihapus, verifikasi tersisa 0.

## Keputusan penting

- Filter produk hanya `is_active = 1` dan `harga_jual > 0`; `is_locked` tidak dipakai karena redundan.
- Rilis wajib: migrasi → POS → M.A.R.A mulai mengirim `cart_items`.

## Tersisa

- Review/Gate 3 lalu persetujuan deploy eksplisit Aan.
- Verifikasi repo dan hak akses DB M.A.R.A belum dilakukan dari workspace POS.

## Titik masuk sesi berikutnya

- **Baca**: requirement, design, `docs/TODO.md` (`TODO-INBOX-KASIR3`), checkpoint ini.
- **Deploy**: ikuti `docs/deploy.md`; rollback ditentukan dari hash produksi saat pre-deploy.
