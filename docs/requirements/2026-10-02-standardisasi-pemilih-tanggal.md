# Requirements: Standardisasi Pemilih Tanggal / Rentang / Periode

- **Tanggal**: 2026-10-02
- **Status**: draf
- **Tier SDLC**: A
- **Penanggung jawab**: (diisi saat disetujui)

## 1. Tujuan

Menyeragamkan cara memilih tanggal, rentang tanggal, dan periode (bulan) di
seluruh halaman Aulia Kasir supaya:

- pengalaman kasir/admin konsisten (locale, tampilan, perilaku, preset);
- duplikasi konfigurasi picker berkurang (satu sumber konfigurasi);
- perubahan berikutnya tidak perlu mengedit banyak file view.

Fokus utama: pemilih **rentang** tanggal (daterangepicker), lalu input tanggal
tunggal dan pemilih bulan.

## 2. Kondisi saat ini (terverifikasi)

Terdapat tiga kelompok kontrol tanggal dengan pola berbeda:

**A. Picker rentang (bootstrap-daterangepicker + moment, CDN jsDelivr tanpa versi):**

- `app/Views/laporan/index.php:211-268` (harian single, periode, kategori)
- `app/Views/laporan/item_harian.php:758-861`
- `app/Views/transaksi/laporan_pembayaran.php:700-807`
- `app/Views/transaksi/index.php:484-512`
- `app/Views/tagihan/index.php:242-271`

**B. Input native (tanggal/rentang/waktu):**

- `app/Views/cash/riwayat.php:15,19` (2 × `date`, rentang)
- `app/Views/cash/pengeluaran.php:33,37` (2 × `date`, rentang)
- `app/Views/cash/pengeluaran.php:122` (`datetime-local`, butuh jam)
- `app/Views/components/payment/modal.php:32` (`date`, backdate pembayaran)
- `app/Views/jadwal/index.php:324,327` (rentang analisis), `:437,441` (rentang hapus), `:192,390,465` (tanggal tunggal)

**C. Pemilih bulan:**

- `app/Views/cash/closing.php:15` (`type="month"`)
- `app/Views/roster/index.php:240-248` (tombol Bulan Sebelumnya/Berikutnya)
- `app/Views/laporan/index.php:82-105` (dropdown Bulan + Tahun)
- `app/Views/archive_transaksi/index.php:40-51` (checkbox multi-bulan)

Inkonsistensi terverifikasi:

1. **Locale**: `transaksi/index.php:485-498` & `tagihan/index.php:243-256` memakai
   locale Indonesia penuh; `item_harian.php:770-804`, `laporan_pembayaran.php:712-745`,
   `laporan/index.php:212-221` hanya sebagian (nama hari 2 huruf, tanpa `firstDay`);
   `laporan/index.php:242-244, 257-259` hanya menyetel `format` sehingga label/nama
   hari-bulan tampil **English**.
2. **Preset**: 6 preset (`transaksi`, `tagihan`) vs 5 (`item_harian`, `laporan_pembayaran`)
   vs 4 (`laporan` periode & kategori).
3. **Mode terapkan**: `autoApply:true` (`laporan`, `item_harian`, `laporan_pembayaran`)
   vs wajib klik "Terapkan" (`transaksi`, `tagihan`).
4. **Default rentang**: Hari Ini (`transaksi`, `laporan_pembayaran`, laporan harian);
   Awal bulan–Hari Ini (`item_harian`, `cash`, laporan periode/kategori);
   tanpa batas (`tagihan`).
5. **Nama parameter**: `tanggal_awal`/`tanggal_akhir` (mayoritas) vs
   `tanggal_mulai`/`tanggal_sampai` (`item_harian`, `laporan_pembayaran`).
6. **Aset**: `daterangepicker.css` sudah global di `layout/main.php:21` tetapi
   dimuat ulang di 3 view; `moment` + `daterangepicker.js` dimuat per-view di 6 view;
   URL CDN tanpa versi.

Catatan: default "tanpa batas" pada Tagihan (`Tagihan.php:138-145`) dan default
"Hari Ini" pada Laporan Pembayaran (`Laporan.php:1985`) dinilai sengaja dan
dipertahankan (lihat §4 AC-4).

## 3. User story

- Sebagai kasir/admin, saya ingin pemilih tanggal tampil dan berperilaku sama di
  semua halaman, supaya tidak bingung dan lebih cepat memfilter.
- Sebagai developer, saya ingin konfigurasi pemilih tanggal terpusat, supaya
  perubahan berikutnya tidak perlu mengedit banyak file.

## 4. Acceptance criteria

- **AC-1** (locale & tampilan): Semua picker rentang memakai satu locale Indonesia
  penuh — `format 'DD/MM/YYYY'`, `separator ' - '`, `applyLabel 'Terapkan'`,
  `cancelLabel 'Batal'`, `fromLabel 'Dari'`, `toLabel 'Sampai'`,
  `customRangeLabel 'Custom'`, `weekLabel 'M'`,
  `daysOfWeek ['Min','Sen','Sel','Rab','Kam','Jum','Sab']`, `monthNames` Indonesia,
  `firstDay: 1`. Tidak ada lagi picker berlokalisasi English atau bernama hari 2 huruf.
- **AC-2** (preset): Semua picker rentang menampilkan 6 preset: Hari Ini, Kemarin,
  7 Hari Terakhir, 30 Hari Terakhir, Bulan Ini, Bulan Lalu. Picker harian
  (`laporan/index.php` tab Harian) tetap `singleDatePicker` tanpa preset.
- **AC-3** (mode terapkan): Semua picker rentang memakai mode "Terapkan"
  (`autoApply` tidak aktif). Tidak ada picker yang menerapkan tanpa klik "Terapkan".
- **AC-4** (default rentang, dipertahankan per konteks):
  - Operasional harian = Hari Ini: `transaksi/index.php`, laporan harian.
  - Laporan periodik = Awal bulan–Hari Ini: laporan periode & kategori,
    `item_harian.php`, `cash/riwayat.php`, `cash/pengeluaran.php`.
  - Tagihan = tanpa batas; Laporan Pembayaran = Hari Ini.
- **AC-5** (nama parameter): Nama parameter rentang seragam `tanggal_awal`/
  `tanggal_akhir`. `item_harian.php` dan `laporan_pembayaran.php` diubah dari
  `tanggal_mulai`/`tanggal_sampai`; `Laporan::itemHarian()` dan `Laporan::pembayaran()`
  menyesuaikan pembacaan parameter. Format nilai tetap `YYYY-MM-DD`.
- **AC-6** (aset): `moment` + `daterangepicker` (CSS+JS) dimuat **sekali** di
  `layout/main.php` dengan versi dipin. Tidak ada include per-view lagi
  (`laporan/index.php:171-173`, `item_harian.php:609-616`, `tagihan/index.php:188-189`,
  `transaksi/index.php:290-291,369`, `laporan_pembayaran.php:671-673` dihapus).
- **AC-7** (rentang native Cash): `cash/riwayat.php` dan `cash/pengeluaran.php`
  memakai satu daterangepicker standar dengan hidden `tanggal_awal`/`tanggal_akhir`
  (menggantikan 2 input `date` terpisah). Controller tidak berubah (param sama).
- **AC-8** (rentang Jadwal): Pasangan rentang di `jadwal/index.php`
  (`analisisStart/End`, `hapusRangeStart/End`) memakai range picker standar;
  `jadwal.js` disesuaikan agar tetap membaca nilai dengan benar.
- **AC-9** (tanggal tunggal): Input date-only — `paymentBackdateTanggal`
  (`components/payment/modal.php:32`) dan tanggal jadwal
  (`jadwal/index.php:192,390,465`) — memakai `singleDatePicker` standar.
  `datetime-local` pengeluaran (`cash/pengeluaran.php:122`) tetap native.
- **AC-10** (pemilih bulan): `cash/closing.php`, `roster/index.php`, dan laporan
  bulanan (`laporan/index.php`) memakai dropdown Bulan + Tahun standar. Tombol
  prev/next roster boleh dipertahankan sebagai pelengkap. `archive_transaksi`
  tetap checkbox multi-pilih.
- **AC-11** (tanpa regresi): Filter tetap mengirim parameter yang benar, total/
  ringkasan tidak berubah nilainya, dan alur backdate pembayaran tidak berubah
  nilainya. Tidak ada error console saat halaman dimuat.

## 5. Batasan dan di luar cakupan

- Batasan:
  - Tidak mengubah query, model, atau skema database.
  - Tampilan tanggal user-facing tetap `DD/MM/YYYY`; nilai yang dikirim/di-simpan
    tetap `YYYY-MM-DD`.
  - `datetime-local` (butuh jam) tidak diubah.
- Tidak termasuk:
  - Mengubah aturan bisnis nominal/status pembayaran, tagihan, atau kas.
  - Mengubah `archive_transaksi/index.php` (tetap checkbox multi-pilih).
  - Mengubah kontrak POS <-> WA Gateway (perubahan lintas repositori).
  - Mengubah `bulan`/`start`/`end`/`tanggal` pada endpoint yang sudah logis
    berbeda konsep (kecuali yang disebut AC-5).

## 6. Dampak aturan bisnis

- [ ] Mengubah aturan bisnis (wajib dicatat di `docs/CHANGELOG.md`)
- [x] Menyentuh tampilan data keuangan (tanpa mengubah nilai/perhitungan)
- [ ] Mengubah skema database
- [ ] Mengubah kontrak POS <-> WA Gateway (perubahan lintas repositori)

Catatan: nilai uang dan perhitungan tidak berubah; hanya cara memilih rentang
dan konsistensi tampilan.

## 7. Asumsi dan pertanyaan terbuka

- Asumsi:
  - Roster memakai dropdown Bulan + Tahun; tombol prev/next dipertahankan sebagai
    pelengkap navigasi cepat.
  - Versi pin yang dipakai: `moment` 2.29.4 dan `daterangepicker` 3.1.0 (diverifikasi
    saat Design).
  - Controller Cash tidak berubah karena parameter sudah `tanggal_awal`/`tanggal_akhir`.
- Pertanyaan (maks. 3, hanya yang mengubah hasil):
  - (tidak ada; seluruh keputusan sudah dipilih pada sesi ini)

## 8. Persetujuan (Gate 1)

- [ ] Disetujui oleh: <nama>, tanggal: <YYYY-MM-DD>
