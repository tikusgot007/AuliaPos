# Verifikasi Manual — Standardisasi Pemilih Tanggal

- **Tanggal**: 2026-10-02
- **Status**: berjalan
- **Referensi**: `docs/design/2026-10-02-standardisasi-pemilih-tanggal.md`
- **Cara pakai**: kerjakan per tahap, catat hasil, lanjut hanya setelah tahap sebelumnya OK.

## Prasyarat

1. Jalankan server: `php spark serve` (default `http://localhost:8080`).
2. Login sebagai admin/kasir.
3. Buka DevTools browser: tab **Console** (pastikan tanpa error merah) dan tab **Network** (filter `daterangepicker`).
4. Siapkan data uji: minimal 1 pembayaran **hari ini**, dan beberapa transaksi lintas tanggal.

## Tahap 0 — Aset global (AC-6)

- Buka halaman mana pun yang memakai layout utama (mis. `/transaksi`).
- Di Network, `moment.min.js` dan `daterangepicker.min.js` hanya dimuat **sekali**.
- Di Console: `window.AULIA_DATEPICKER` ada dan berisi `locale` + 6 `presets`.
- Tidak ada permintaan 404 untuk aset picker.
- **Harapan**: 1 kali muat, config ada, tanpa 404.

## Tahap 1 — Picker rentang: locale, preset, "Terapkan"

Untuk tiap halaman: buka picker, cek (a) label Indonesia, (b) 6 preset, (c) harus klik "Terapkan".

- `/transaksi` — filter `#filterTanggal`
- `/tagihan` — filter `#filterTanggal`
- `/laporan` tab Periode & tab Kategori
- `/laporan/item-harian`
- `/laporan-pembayaran`

- **Harapan**: format `DD/MM/YYYY`, tombol Terapkan/Batal, daftar "Hari Ini, Kemarin, 7 Hari, 30 Hari, Bulan Ini, Bulan Lalu", nama hari 3 huruf, tanpa teks English.
- **Catatan**: `/laporan` tab Harian harus **satu tanggal** (single), tanpa daftar preset.

## Tahap 2 — Default rentang (AC-4)

- `/transaksi` → default Hari Ini–Hari Ini.
- `/laporan-pembayaran` → default Hari Ini–Hari Ini.
- `/laporan` Periode/Kategori, `/laporan/item-harian`, `/cash/riwayat`, `/cash/pengeluaran` → default Awal bulan–Hari Ini.
- `/tagihan` → **tanpa batas** (semua tagihan tampil).
- **Harapan**: sesuai konteks di atas.

## Tahap 3 — Filter benar-benar bekerja (param)

- `/laporan/item-harian` dan `/laporan-pembayaran`: pilih rentang → Terapkan → Tampilkan. Perhatikan URL memuat `tanggal_awal=...&tanggal_akhir=...`.
- `/laporan-pembayaran`: total baris/total nilai konsisten dengan rentang.
- **Harapan**: hasil berubah sesuai rentang; tidak ada error.

## Tahap 4 — Cash: rentang native → picker (AC-7)

- `/cash/riwayat`: satu picker rentang (bukan dua kolom tanggal), Filter bekerja, URL `tanggal_awal/akhir`.
- `/cash/pengeluaran`: sama; modal tambah pengeluaran tetap memakai input **tanggal+jam** (`datetime-local`).
- **Harapan**: hasil filter sama seperti sebelumnya untuk rentang yang sama.

## Tahap 5 — Jadwal: rentang & tanggal tunggal (AC-8, AC-9)

- Tab Analisis: pilih rentang → Analisis berjalan; Network menunjukkan `start`/`end` benar (`YYYY-MM-DD`).
- Tombol "Hapus Jadwal (Rentang)": picker rentang muncul dengan default minggu berjalan; konfirmasi mengirim `start`/`end` benar.
- "Cari Tanggal" (matrix), "Tambah Jadwal" (Tanggal), "Terapkan Master" (Mulai Minggu): memakai picker satu tanggal; nilai terkirim benar.
- **Harapan**: semua berfungsi, tanggal tidak bergeser (timezone).

## Tahap 6 — Pemilih bulan (AC-10)

- `/cash/closing`: dropdown Bulan + Tahun; ganti bulan → data berganti tanpa reload; URL `?bulan=YYYY-MM`.
- `/roster` tab Bulanan: dropdown Bulan + Tahun dan tombol Sebelumnya/Berikutnya tetap sinkron; ganti bulan → data berganti.
- `/archive-transaksi`: tetap **checkbox** multi-bulan.
- **Harapan**: filter bulan berfungsi; navigasi prev/next menyetel dropdown dengan benar.

## Tahap 7 — Regresi & pengecualian

- `/laporan-pembayaran`: bandingkan total untuk rentang yang sama sebelum/sesudah perubahan (harus sama).
- Pembayaran backdate (di detail transaksi): input tanggal **tetap native** dan validasi min/max masih jalan.
- Tidak ada error Console di semua halaman di atas.
- **Harapan**: nilai uang identik; backdate utuh; console bersih.

## Hasil

Metode: server `php spark serve` + login sesi + uji headless (Edge + puppeteer-core) terhadap HTML dan instance daterangepicker yang benar-benar terinisialisasi.

| Tahap | Status | Catatan |
|---|---|---|
| 0 | OK | `moment@2.31.0` & `daterangepicker@3.1.0` dimuat sekali; `window.AULIA_DATEPICKER` berisi locale + 6 preset; tanpa 404/error. |
| 1 | OK | 5 halaman rentang: `applyLabel='Terapkan'`, `firstDay=1`, format `DD/MM/YYYY`, 6 preset, `autoApply=false`, tombol Terapkan ada. `/laporan` harian = `singleDatePicker` tanpa preset. |
| 2 | OK | `/transaksi` & `/laporan-pembayaran` default Hari Ini; `/cash/*`, item-harian, laporan periode/kategori default awal bulan–Hari Ini; `/tagihan` kosong (tanpa batas). |
| 3 | OK | Hidden `tanggal_awal`/`tanggal_akhir` terisi pada Terapkan; tidak ada sisa `tanggal_mulai/sampai`. |
| 4 | OK | `/cash/riwayat` & `/cash/pengeluaran` = 1 picker rentang; hidden terisi (`2026-09-01/2026-09-30`); `datetime-local` pengeluaran tetap. |
| 5 | OK | Jadwal: 5 picker terinisialisasi (cari/tambah/apply `single`, analisis/hapus `range` 6 preset). |
| 6 | OK | Closing & roster memakai id `bulanPicker*`/`rosterBulan*`; `AuliaMonthPicker.set/get` benar (`2026-05`). Archive tetap checkbox. |
| 7 | Sebagian | Backdate `#paymentBackdateTanggal` tetap native `type="date"`. Dua anomali **pra-ada** (bukan dari perubahan ini): `/laporan/item-harian` membeku di headless (DataTables dimuat ganda), dan error `_DT_CellIndex` (DataTables 1.13.6) di `/laporan-pembayaran`. Diff tidak menyentuh baris tabel/konfigurasi DataTables. |

## Anomali & tindak lanjut

1. **FIXED** — Duplikasi aset DataTables di `layout/main.php` (blok 1192-1205 vs 1616-1631, plus `buttons.bootstrap5` ganda di 1637). Sekarang masing-masing dimuat **sekali** (terverifikasi HTTP: core=1, buttons=1, buttons.bootstrap5=1, responsive=1).
2. **FIXED** — `colspan` pada baris footer/empty menyebabkan `_DT_CellIndex`. Perbaikan: `laporan_pembayaran` tfoot = 9 sel, `item_harian` tfoot = 8 sel (satu sel per kolom), dan empty-state `item_harian` dipindah ke `<div>` di luar tabel. Verifikasi headless (rentang 2026-09-29): `tfootCells == headCells`, `isDT=true`, tanpa error.
3. **S1 & S2 SELESAI** — `item_harian` dan `laporan_pembayaran` kini server-side + ekspor CSV server-side:
   - S1 `item_harian`: endpoint `/laporan/item-harian-data` & `/laporan/item-harian-export`. Rentang lebar 20.496 baris → 50 baris/halaman, TOTAL cocok DB (Rp 318.325.600 / Rp 301.913.140).
   - S2 `laporan_pembayaran` (live + arsip): endpoint `/laporan-pembayaran-data` & `/laporan-pembayaran-export`. Rentang lebar 11.476 baris → 25 baris/halaman, TOTAL cocok DB (Rp 301.913.140; tunai 295.414.740 + qris 4.372.700 + transfer 2.125.700), tanpa error.
   - S3a `tagihan`: endpoint `/tagihan/data`. 511 tagihan → 25 baris/halaman; `hanya_terlambat` 509 (cocok DB); badge & tombol aksi benar; tanpa error.
   - Catatan: tombol Print masih mencetak halaman aktif.
   Sisa: **S3b** `transaksi` (paling kompleks: pencarian keyword lintas-arsip + render baris bergantung role) dan **S4** `laporan` (tabel dinamis per tab).


