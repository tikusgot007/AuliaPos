# Design: Server-side DataTables untuk laporan/daftar (atasi freeze rentang lebar)

- **Date**: 2026-10-02
- **Status**: draft (menunggu Gate 2)
- **Tier**: A
- **Terkait**: `docs/sesi/2026-10-02-standardisasi-pemilih-tanggal-verifikasi.md` (Temuan 1)

## 1. Masalah

Halaman laporan/daftar memakai DataTables **client-side**: seluruh baris hasil filter dirender server-side lalu di-ingest DataTables di browser dengan `responsive: true`. Untuk rentang lebar (ribuan baris) main-thread membeku (terbukti headless: `/laporan-pembayaran`, `/laporan/item-harian`).

Halaman terdampak (client-side): `laporan_pembayaran`, `item_harian`, `transaksi`, `tagihan`, `laporan`. Referensi pola sudah ada: `produk` (server-side).

## 2. Target

DataTables `serverSide: true` + `processing: true`: browser hanya menerima 1 halaman (mis. 25 baris). Filter/urut/cari diselesaikan di server.

## 3. Pola bersama

- **Endpoint** GET per halaman, menerima param DataTables: `draw`, `start`, `length`, `search[value]`, `order[0][column]`, `order[0][dir]` + filter halaman (rentang tanggal, status, kategori). Mengembalikan `{ draw, recordsTotal, recordsFiltered, data, totals }` (envelope standar DataTables; `totals` untuk footer).
- **Ordering whitelist**: petakan `order[0][column]` ke daftar kolom yang diizinkan (cegah injeksi kolom).
- **Totals footer**: total (uang) dihitung server dari **seluruh** set terfilter (bukan hanya halaman) via query SUM terpisah, lalu ditampilkan dengan `footerCallback`. Ini wajib agar angka TOTAL tidak berubah.
- **Kolom**: definisikan `columns` eksplisit di view agar urutan/format konsisten dengan header.

## 4. Ekspor (keputusan penting)

Dengan server-side, tombol ekspor HTML5 hanya punya data halaman aktif. Rekomendasi: **endpoint ekspor server-side** yang mengeluarkan CSV/XLSX untuk **seluruh set terfilter** (streaming, bukan memori), satu endpoint generik per halaman. Tombol DataTables diarahkan ke URL itu (atau tombol terpisah "Ekspor semua").

Alternatif jika ingin cepat: ekspor hanya halaman aktif (regresi) — tidak direkomendasikan untuk laporan uang.

## 5. Khusus `laporan_pembayaran` (arsip)

Sumber = MySQL live (`v_daftar_pembayaran`) + SQLite arsip (`TransaksiArchiveService`). Paging lintas dua DB tidak bisa satu SQL. Pendekatan: hitung `recordsTotal`/`recordsFiltered` dari kedua sumber; karena arsip hanya berisi bulan ≥6 bulan (selalu lebih lama) dan urutan DESC, arsip selalu berada **setelah** live, sehingga irisan halaman bisa dihitung: `gabungan[i] = live[i]` jika `i < liveCount`, selain itu `arsip[i - liveCount]`. Dokumentasikan invariant ini dengan komentar `ponytail:` + fallback bila invariant dilanggar.

## 6. Rencana per halaman

| Halaman | Tabel | Ekspor | Catatan |
|---|---|---|---|
| `item_harian` | `tableItemHarian` | ya | satu sumber; total subtotal + teralokasi |
| `laporan_pembayaran` | `tableLaporanPembayaran` | ya | live + arsip |
| `transaksi` | `tableTransaksi` | tidak | lebih sederhana |
| `tagihan` | `tableTagihan` | tidak | tanpa batas tanggal |
| `laporan` (harian/periode/kategori) | `table_<containerId>` | ya | tabel dibangun dinamis per tab; agregasi per tab |

## 7. Tahapan

1. **S1** `item_harian` (endpoint + server-side + ekspor server-side).
2. **S2** `laporan_pembayaran` (endpoint live+arsip + server-side + ekspor).
3. **S3** `transaksi` + `tagihan`.
4. **S4** `laporan` (per tab).

Setiap tahap: verifikasi headless (rentang lebar: halaman menerima ~1 halaman baris, tidak beku; TOTAL sama dengan sebelumnya), lalu lanjut.

## 8. Risiko & mitigasi

- TOTAL berubah → hitung SUM terpisah dari set terfilter; uji banding sebelum/sesudah.
- Ekspor kehilangan baris → endpoint ekspor mengambil seluruh set terfilter (bukan halaman).
- Injeksi kolom via `order`/`search` → whitelist kolom + escaping; jangan interpolasi mentah.
- Binding parameter aman (query builder), hindari string SQL.
- `laporan` tabel dinamis → perlu peta kolom per tab; paling berisiko, dikerjakan terakhir.

## 9. Rencana uji

- Unit (pure service): pembentuk filter/urutan (whitelist) dan perhitungan slice live+arsip.
- Headless: rentang lebar → tidak beku, `recordsFiltered` benar, jumlah baris per halaman = `length`, TOTAL sama.
- Manual: ekspor seluruh set terfilter (jumlah baris = recordsFiltered).

## 10. Keputusan terbuka

- **Ekspor**: server-side penuh (rekomendasi) vs halaman aktif saja.
- **Urutan mulai**: S1 lalu S2 (rekomendasi) vs langsung semua.

## 11. Approval (Gate 2)

- [ ] Disetujui: <nama>, <tanggal>
