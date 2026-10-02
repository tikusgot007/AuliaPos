# Checkpoint Sesi — Server-side DataTables + Review Fix

- **Tanggal**: 2026-10-02
- **Status**: sebagian — S1, S2, S3a, **S3b** selesai & terverifikasi; S4 belum
- **Repo**: C:\xampp\htdocs\aulia-app (perubahan sudah di-commit s/d `d83584c`; S3b belum di-commit)
- **Terkait**:
  - `docs/requirements/2026-10-02-standardisasi-pemilih-tanggal.md`
  - `docs/design/2026-10-02-standardisasi-pemilih-tanggal.md`
  - `docs/design/2026-10-02-server-side-datatables.md`
  - `docs/sesi/2026-10-02-standardisasi-pemilih-tanggal-verifikasi.md`

## Ringkas yang sudah selesai

### A. Standardisasi pemilih tanggal (selesai)
Satu config sumber `App\Config\DatePicker` + helper `public/assets/js/date-range.js`;
`moment@2.31.0` & `daterangepicker@3.1.0` dipin & dimuat global di `layout/main.php`;
locale Indonesia penuh, 6 preset, mode "Terapkan"; parameter rentang seragam
`tanggal_awal`/`tanggal_akhir`; Cash/Jadwal/menu bulan diseragamkan. Verifikasi headless OK.

### B. Perbaikan temuan review (selesai)
- CRITICAL `$this->include()` mengabaikan data → `closing`/`roster` pakai
  `$this->setData([...])->include('components/month_year_picker')`.
- `colspan` di `<tfoot>`/empty → `_DT_CellIndex`; tfoot kini satu sel per kolom,
  empty-state pindah ke `<div>`.
- Duplikasi aset DataTables di `layout/main.php` dihapus (kini sekali).

### C. Server-side DataTables (mengatasi freeze rentang lebar)

Pola yang dipakai (referensi: `Produk::getProdukData()`):
endpoint JSON envelope `{draw, recordsTotal, recordsFiltered, data, totals?}`,
`columns` eksplisit + `render`, `order` whitelist, TOTAL dihitung server (bukan per halaman),
ekspor CSV server-side.

- **S1 `item_harian` — SELESAI**
  - Controller: `Laporan::itemHarianData()`, `Laporan::itemHarianExport()` + helper
    (`itemHarianFilterBag`, `itemHarianBaseBuilder`, `itemHarianApplyFilters`,
    `itemHarianLiveCount`, `itemHarianLiveTotals`, `itemHarianLiveRows`,
    `itemHarianOrder`, `itemHarianSortRows`, `itemHarianArchiveRows`).
  - Route: `/laporan/item-harian-data`, `/laporan/item-harian-export`.
  - View: `app/Views/laporan/item_harian.php` (server-side + footerCallback + summary).
  - Verifikasi: 20.496 baris → 50/halaman; TOTAL cocok DB (318.325.600 / 301.913.140).

- **S2 `laporan_pembayaran` — SELESAI** (live + arsip)
  - Controller: `Laporan::pembayaranData()`, `Laporan::pembayaranExport()` + helper `pembayaran*`.
  - Route: `/laporan-pembayaran-data`, `/laporan-pembayaran-export`.
  - View: `app/Views/transaksi/laporan_pembayaran.php`.
  - Verifikasi: 11.476 baris → 25/halaman; TOTAL cocok DB (301.913.140; tunai+qris+transfer).

- **S3a `tagihan` — SELESAI**
  - Controller: `Tagihan::data()` + helper `tagihan*`. `hanya_terlambat` → SQL
    `transaksi.tanggal < (hari ini − tempoHari)` (setara `KalkulasiJatuhTempo::isOverdue`).
  - Route: `/tagihan/data`.
  - View: `app/Views/tagihan/index.php` (11 kolom + render badge/tombol).
  - Verifikasi: 511 tagihan, `hanya_terlambat` 509 (cocok DB); 25 baris/halaman; tanpa error.

- **S3b `transaksi` — SELESAI**
  - Controller: parsing filter dipindah ke `Transaksi::transaksiFilterBag()` (dipakai
    `index()` + `data()`); helper `transaksiBaseBuilder`, `transaksiApplyFilters`,
    `transaksiOrder` (whitelist kolom), `transaksiLiveCount`, `transaksiLiveRows`,
    `transaksiArchiveRows`, `transaksiSortRows`, `transaksiRowForJson`;
    endpoint `Transaksi::data()`.
  - `index()` tidak lagi `findAll()` — hanya menyemai form filter.
  - Route: `/transaksi/data`. Tanpa ekspor & tanpa footer TOTAL.
  - View: `app/Views/transaksi/index.php` server-side; render baris (termasuk tombol
    Bayar/Selesai/Batal sesuai role) dipindah ke `columns.render` memakai flag
    `window.paymentModalConfig.isAdmin/isShiftLeader` yang sudah ada.
  - Kotak "Cari:" bawaan DataTables dimatikan (`searching: false`) — pencarian tetap
    lewat kotak search global header (`keyword`) yang mencakup live + arsip.
  - Verifikasi: rentang 2026-01-01..2026-10-02 → default `aktif` 11.912 baris (cocok DB),
    `kasir_id=4` 2.293 (cocok DB), status belum_lunas 511 (cocok DB), keyword "762" 40
    (cocok DB). Headless: `serverSide=true`, 25 baris/halaman, nomor baris lanjut ke 26
    di halaman 2, sorting kolom benar, 0 error console.
  - **Perbaikan pasca-`/review`** (4 temuan, semua sudah diverifikasi ulang):
    1. Nilai filter status legacy dari URL (mis. `?status_transaksi=mangkrak`) sempat hilang
       karena `ajax.data` membaca nilai `<select>` yang tidak punya opsi untuk nilai itu.
       Diperbaiki dengan hidden `statusTransaksiEfektif`/`statusPembayaranEfektif` yang
       disemai dari parsing server, lalu di-`syncFilterEfektif()` saat Filter/apply date.
    2. Kolom 8 "Sisa" sempat diurutkan dengan `grand_total`. Diperbaiki ke ekspresi
       `Transaksi::SQL_SISA` = `GREATEST(grand_total - total_dibayar, 0)` (identik dengan
       `transaksiRowForJson()`), plus key turunan `sisa` di `transaksiSortRows()` agar jalur
       live dan merge sama. Catatan: `Tagihan::tagihanOrder()` masih punya defek yang sama
       (pre-existing, di luar cakupan diff ini).
    3. Jalur keyword tidak lagi menarik SELURUH baris live; cukup `start + length`.
    4. Query `users` untuk validasi `kasir_id` hanya jalan bila parameter `kasir_id`
       benar-benar dikirim.

## Yang belum dikerjakan

### S4 `laporan` (tabel dinamis per tab)
- `app/Views/laporan/index.php` membangun tabel dinamis `#table_<containerId>` per tab
  (harian/periode/kategori/bulanan) dengan DataTables + buttons, diisi dari `Laporan::getData()`.
- Perlu peta kolom per tab; paling berisiko; kerjakan terakhir.

## Catatan penting / gotcha

- **CI4 4.7.4**: `$this->include($view, $data)` MENGABAIKAN `$data`; pakai
  `$this->setData($data)->include($view)` (lihat `closing.php`, `roster/index.php`).
- **TOTAL** harus dihitung server dari seluruh set terfilter (bukan hanya halaman),
  tampilkan via `footerCallback` (contoh S1/S2).
- **Ekspor**: tombol mengarah ke endpoint CSV server (`*-export`). Tombol **Print** masih
  `window.print()` (hanya halaman aktif) — kandidat perbaikan berikutnya.
- **Arsip**: saat ini kosong → jalur cepat (paging SQL murni). Bila arsip terisi,
  jalur full-merge (terdokumentasi `ponytail:`) yang bisa berat.
- **Test infra**: PHPUnit 10.5, `tests/unit` (tanpa DB). `tests/unit/DatePickerConfigTest.php` ada.

## Cara verifikasi (yang sudah dipakai)

- Login via sesi HTTP (`aan`/`aan`) ke `http://127.0.0.1:8123` atau `http://localhost/aulia-app/index.php`.
- Headless: puppeteer-core + Edge (`C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe`),
  skrip di `C:\Users\Anshar\AppData\Local\Temp\kilo\verify\probe*.js` (di luar repo).
  Catatan host: cookie sesi terikat host — pakai `localhost/aulia-app` untuk headless.
- DB parity: `C:\xampp\mysql\bin\mysql.exe -u root aulia_kasirdb -N -B -e "..."`.
- Lint: `php -l`; unit: `php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml`.

## Titik masuk sesi berikutnya

1. Baca: `docs/design/2026-10-02-server-side-datatables.md` §6 (schema per halaman) + checkpoint ini.
2. Kerjakan **S4 `laporan`** (tabel dinamis per tab harian/periode/kategori/bulanan) — paling berisiko, perlu peta kolom per tab.
3. Setelah S4, pertimbangkan commit + perbaikan tombol Print (masih `window.print()` halaman aktif saja).
