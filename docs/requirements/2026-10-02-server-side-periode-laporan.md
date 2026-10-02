# Requirements: Server-side DataTables untuk Tab Periode Laporan

- **Tanggal**: 2026-10-02
- **Status**: terimplementasi & terverifikasi (Gate 1 & 2 disetujui user 2026-10-02; hasil di `docs/sesi/2026-10-02-server-side-periode-laporan.md`)
- **Tier SDLC**: A
- **Penanggung jawab**: —
- **Terkait**: `docs/design/2026-10-02-server-side-datatables.md` (induk pola S1–S3b)

## 1. Tujuan

Memindahkan tab **Periode** pada halaman Laporan dari DataTables client-side menjadi server-side
(paging/urut/cari di server) plus ekspor CSV server-side, supaya halaman tidak membeku saat admin memilih
rentang lebar (ribuan transaksi). Tiga tab lain (Harian, Bulanan, Per Kategori) **tidak** diubah karena
jumlah barisnya kecil.

## 2. Kondisi saat ini (terverifikasi)

- Halaman `/laporan` hanya untuk admin; `Laporan::index()` menolak non-admin (`app/Controllers/Laporan.php:22-28`).
- Data tab Periode diambil via `POST /laporan/get-data` (`Laporan::getData()`, `Laporan.php:46`), khusus
  `jenis === 'periode'` masuk ke `processData()` → `processDetailTransaksi()` (`Laporan.php:999-1083`).
- Sumber: `transaksi` + `pelanggan` (live) digabung arsip SQLite (`getTransaksiMentah`, `TransaksiArchiveService.php:806`),
  status `batal` dikecualikan, plus detail per transaksi (`Laporan.php:123-199`).
- Satu baris hasil = satu transaksi yang punya detail (`processDetailTransaksi`, `Laporan.php:1032-1083`);
  kolom: Tanggal, Invoice, No Order, Pelanggan, Subtotal, Diskon, Grand Total, Sisa Tagihan, Status
  (`app/Views/laporan/index.php:833`, `:868-878`).
- `Sisa Tagihan = grand_total - total_dibayar` **tanpa** clamp ke 0 (`Laporan.php:1076`).
- View merender **seluruh** baris ke `<table id="table_resultPeriode">` lalu DataTables client-side
  (`serverSide` tidak diset → `false`) di `app/Views/laporan/index.php:502-814` (init `:718-801`).
- Jumlah baris terukur (`aulia_kasirdb`, 2026-10-02):
  - Periode 2026-01-01..2026-10-02 (status ≠ batal, punya detail): **11.926 baris** → membeku.
  - Harian: 1 baris; Bulanan: ≤31 baris; Per Kategori: 8 baris → tidak bermasalah.
  - Transaksi tanpa detail pada rentang itu: **0** (aturan "harus punya detail" saat ini tidak pernah memotong, tapi tetap harus dipertahankan).
  - Transaksi dengan `grand_total < total_dibayar`: **7** — membuktikan Sisa Tagihan bisa negatif dan tidak di-clamp.
- Ekspor saat ini memakai DataTables Buttons client-side (Copy/CSV/Excel/PDF/Print) di `Laporan.php:1372-1445` —
  setelah server-side, ini hanya akan mengekspor halaman aktif; karena itu CSV dipindah ke server.

Fakta terverifikasi dari kode & DB. Belum diverifikasi: perilaku saat arsip SQLite terisi (saat ini arsip kosong).

## 3. User story

- Sebagai **admin**, saya ingin membuka tab Periode dengan rentang tanggal lebar dan mengurutkan/mencari datanya
  tanpa halaman membeku, supaya saya tetap bisa menganalisis laporan transaksi.
- Sebagai **admin**, saya ingin mengunduh CSV tab Periode berisi **seluruh** baris terfilter, supaya laporan
  yang diekspor tidak terpotong hanya pada halaman yang terlihat.

## 4. Acceptance criteria

- **AC-1**: Given rentang 2026-01-01..2026-10-02, when tab Periode dibuka, then tabel server-side menampilkan
  25 baris per halaman (bukan 11.926) dan halaman tidak membeku.
- **AC-2**: Given filter yang sama, when dibandingkan, then `recordsFiltered` dari endpoint server-side **sama**
  dengan `total` dari `POST /laporan/get-data` `jenis=periode` (paritas).
- **AC-3**: Given filter tanggal/status, when diterapkan, then baris hanya dari rentang itu, status `batal`
  dikecualikan, dan hanya transaksi yang punya detail.
- **AC-4**: Given klik header kolom, when diurutkan, then urutan benar di server; khusus **Sisa Tagihan**
  diurutkan dari nilai `grand_total - total_dibayar` (bukan `grand_total`) dan **tanpa** clamp ke 0.
- **AC-5**: Given mengetik di kotak Cari, when diketik, then server memfilter pada Invoice / No Order / Pelanggan
  (perilaku search lama yang mencakup semua sel disederhanakan ke 3 kolom ini; didokumentasikan di design).
- **AC-6**: Given tombol **CSV**, when diklik, then terunduh CSV seluruh baris terfilter (jumlah baris data =
  `recordsFiltered`). Tombol Copy/Excel/PDF/Print tetap hanya mengekspor baris yang tampil (halaman aktif) —
  perilaku sama dengan S1/S2.
- **AC-7**: Given tab Harian/Bulanan/Per Kategori, when dimuat, then perilaku & tampilan **tidak berubah**
  (masih memakai alur lama).
- **AC-8**: Given user login **non-admin**, when mengakses `/laporan/periode-data` atau `/laporan/periode-export`,
  then ditolak (403/redirect), tidak membocorkan data.
- **AC-9**: Given `order[0][column]` di luar whitelist atau nilai tanggal tidak valid, when dikirim, then server
  tidak error/SQL-injection — pakai urutan default / nilai tanggal default.
- **AC-10**: Given filter tanggal baru dipilih, when tombol Tampilkan ditekan, then data & `recordsFiltered`
  diperbarui sesuai rentang baru.

## 5. Batasan dan di luar cakupan

Batasan:

- Tanpa perubahan skema DB, tanpa migrasi, tanpa perubahan data (read-only).
- Tanpa perubahan aturan bisnis; angka harus identik dengan `processDetailTransaksi()`.
- Pola arsip: live + arsip digabung (arsip SQLite tidak bisa di-JOIN lintas DB); pakai jalur `ponytail:` yang
  sudah dipakai S1/S2/S3b.
- Admin-only.

Tidak termasuk cakupan:

- Tab Harian, Bulanan, Per Kategori (tetap client-side).
- Retrofit server-side ke `POST /laporan/get-data` (dibiarkan utuh untuk tab lain).
- Perubahan `processDetailTransaksi()`/`calculateSummary()` (tidak disentuh).
- Perbaikan temuan lain (mis. `Tagihan::tagihanOrder()` kolom Sisa) — dicatat terpisah.
- Duplikasi aset DataTables/CDN di `app/Views/laporan/index.php:180-198` (pre-existing, di luar cakupan).

## 6. Dampak aturan bisnis

- [ ] Mengubah aturan bisnis.
- [x] Menyentuh data keuangan (transaksi/pembayaran) — **read-only**, tidak ada perubahan nilai.
- [ ] Mengubah skema database.
- [ ] Mengubah kontrak POS <-> WA Gateway.

Karena hanya presentasi/paging dan tidak mengubah perhitungan, tidak ada perubahan aturan bisnis → tidak perlu
entri `docs/CHANGELOG.md` (selaras S1/S2/S3b). Jika ternyata ada selisih angka, itu bug dan wajib diperbaiki.

## 7. Asumsi dan pertanyaan terbuka

Asumsi:

- Rentang default tab Periode = awal bulan s/d hari ini (sesuai view `loadLaporan('periode')`, `index.php:289-292`).
- Arsip kosong saat ini; jalur merge arsip akan diuji saat arsip terisi.

Pertanyaan: tidak ada (cakupan **Periode saja** + **ekspor CSV server-side** sudah diputuskan user 2026-10-02).

## 8. Persetujuan (Gate 1)

- [x] Disetujui oleh: user, tanggal: 2026-10-02 (Gate 1 tercatat bersamaan dengan persetujuan design §9)