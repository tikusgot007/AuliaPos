# Requirements: Audit trail & validasi tanggal untuk Kas Keluar (TODO-BL10)

- **Tanggal**: 2026-10-08
- **Status**: draf
- **Tier SDLC**: A (menyentuh data keuangan + skema database)
- **Penanggung jawab**: -

## 1. Tujuan

Mencegah mutasi kas keluar (`cash_expense`) yang tidak terlacak dan yang
merusak integritas laporan kas yang sudah difinalkan (Closing Kas), tanpa
mengubah siapa yang boleh mengakses modul Kas Keluar (DEC-1: Opsi B —
bukan admin-gating).

## 2. Kondisi saat ini (terverifikasi)

- Route tambah/edit/hapus pengeluaran hanya memakai filter `auth` (login
  biasa); `AuthFilter::$adminRoutes` (`app/Filters/AuthFilter.php:70`) **tidak**
  memuat prefix `cash/pengeluaran`, jadi kasir non-admin bisa menambah,
  mengedit, dan menghapus pengeluaran — `app/Config/Routes.php:267-282`.
- `CashExpenseModel::updatePengeluaran()` (`app/Models/CashExpenseModel.php:143-152`)
  dan `::hapusPengeluaran()` (`:157-160`) mengubah/menghapus baris `cash_expense`
  **tanpa jejak** siapa yang melakukannya atau nilai sebelumnya. Tabel hanya
  punya `user_id` pembuat + `created_at`/`updated_at`
  (`app/Database/Migrations/2026-09-08-000001_CreateAuliaPosCore.php:231-245`).
- `Cash::tambahPengeluaran()` (`app/Controllers/Cash.php:441-486`) dan
  `Cash::updatePengeluaran()` (`:521-594`) menerima field `tanggal` dari
  request tanpa batas lampau/depan; validasi model hanya `valid_date`
  (`CashExpenseModel.php:25`).
- `cash_expense` adalah salah satu sumber langsung `saldo_sistem` pada
  Closing Kas: `CashBalanceService::getBalance()`
  (`app/Services/CashBalanceService.php:19-40`) menjumlahkan kategori
  `kas_awal_hari`, `pengeluaran`/`penyesuaian`, dan `refund_penjualan` dari
  tabel ini.
- Setelah sebuah tanggal tercatat di `closing_kas`, snapshotnya **imutabel**:
  `Cash::closingDetail()`/`simpanClosing()` hanya menghitung ulang
  `saldo_sistem` untuk tanggal yang **belum** pernah di-closing
  (`app/Controllers/Cash.php:330-331,383-384`; `App\Services\KalkulasiClosingKas::saldoSistemFinal()`).
  Mengubah/menghapus `cash_expense` pada tanggal yang sudah closing membuat
  snapshot final tidak lagi cocok dengan data sumber.
- Preseden aturan tanggal yang sudah ada: `ClosingKasModel::validasiTanggal()`
  (`app/Models/ClosingKasModel.php:66-84`) — metode pure static, teruji
  (`tests/unit` pola serupa `KalkulasiClosingKasTest.php`).

## 3. User story

- Sebagai admin, saya ingin setiap perubahan/penghapusan catatan pengeluaran
  kas meninggalkan jejak (siapa, kapan, nilai sebelum/sesudah), supaya saya
  bisa mengaudit kejadian yang mencurigakan.
- Sebagai admin, saya ingin sistem menolak pencatatan/pengubahan pengeluaran
  pada tanggal masa depan atau pada tanggal yang sudah di-Closing Kas, supaya
  laporan yang sudah difinalkan tidak diam-diam berubah.

## 4. Acceptance criteria

- **AC-1**: Given kasir mengubah (update) satu baris `cash_expense`, when
  perubahan disimpan, then satu baris baru tercatat di tabel audit berisi
  `expense_id`, aksi `update`, nilai field sebelum, nilai field sesudah,
  `user_id` pelaku, dan waktu.
- **AC-2**: Given kasir menghapus satu baris `cash_expense`, when penghapusan
  berhasil, then satu baris tercatat di tabel audit dengan aksi `delete` dan
  nilai field sebelum dihapus (`nilai_sesudah` null), lalu baris `cash_expense`
  benar-benar terhapus (hard delete, tidak berubah dari perilaku sekarang).
- **AC-3**: Given tanggal yang diinput (tambah atau ubah) adalah hari esok
  atau lebih jauh ke depan, when disimpan, then request ditolak dengan pesan
  error, dan tidak ada baris yang berubah/ter-insert.
- **AC-4**: Given tanggal yang diinput (tambah atau ubah) adalah hari ini atau
  hari lampau yang **belum** punya record `closing_kas`, when disimpan, then
  request berhasil seperti perilaku sekarang.
- **AC-5**: Given baris `cash_expense` yang **tanggal lama**-nya sudah punya
  record `closing_kas`, when kasir mencoba update atau delete baris itu, then
  request ditolak dengan pesan error dan tidak ada perubahan pada baris
  maupun tabel audit.
- **AC-6**: Given kasir mencoba update sebuah baris dengan **tanggal baru**
  yang sudah punya record `closing_kas` (memindahkan pengeluaran ke hari yang
  sudah closing), when disimpan, then request ditolak dengan pesan error.
- **AC-7**: Given penambahan baris baru (create), when disimpan, then
  **tidak** ada baris audit dibuat (create sudah tercatat lewat `user_id`
  milik baris itu sendiri) — hanya update & delete yang diaudit.

## 5. Batasan dan di luar cakupan

- Batasan teknis/bisnis:
  - Tidak ada perubahan pada siapa yang boleh mengakses modul Kas Keluar
    (DEC-1: tetap `auth`, bukan admin-only).
  - Tidak ada UI baru untuk melihat tabel audit di tahap ini (di luar
    cakupan); data cukup tersimpan dan bisa diquery manual/lewat query
    terpisah bila dibutuhkan investigasi.
  - Validasi tanggal berbasis tanggal kalender (bukan jam); "besok" dibanding
    di level `Y-m-d`.
- Tidak termasuk:
  - Admin-gating modul Kas Keluar (ditolak di DEC-1).
  - Mekanisme "buka kunci"/reopen closing yang sudah final (TODO-BL02 tetap
    berlaku: snapshot closing final).
  - Soft-delete pada `cash_expense` (hapus tetap hard delete + jejak di tabel
    audit terpisah).
  - Perbaikan masalah lain yang disebut di catatan BL10 lama (dedup arsip =
    TODO-BL20, duplikasi kas awal = TODO-BL11) — tidak disentuh.

## 6. Dampak aturan bisnis

- [x] Mengubah aturan bisnis (wajib dicatat di `docs/CHANGELOG.md`) — aturan
      baru: "pengeluaran kas tidak boleh tanggal masa depan atau tanggal yang
      sudah di-Closing Kas".
- [x] Menyentuh data keuangan (`transaksi`, `pembayaran`, `tagihan`, kas)
- [x] Mengubah skema database — tabel baru `cash_expense_audit`.
- [ ] Mengubah kontrak POS <-> WA Gateway

## 7. Asumsi dan pertanyaan terbuka

- Asumsi:
  - "Tanggal yang sudah di-closing" = ada record di `closing_kas` untuk
    tanggal kalender tersebut (`ClosingKasModel::getByTanggal()`), sama
    seperti definisi yang sudah dipakai modul Closing Kas sendiri.
  - Penambahan (`create`) pengeluaran pada hari ini atau hari lampau yang
    belum closing tidak berubah — AC-3/AC-5/AC-6 juga berlaku untuk create
    (tanggal masa depan & tanggal sudah closing ditolak saat create juga,
    bukan hanya update).
- Pertanyaan: tidak ada pertanyaan terbuka tersisa — tiga keputusan (lokasi
  audit, aturan tanggal, kunci closing) sudah disetujui user pada sesi ini
  (B / A / A).

## 8. Persetujuan (Gate 1)

- [x] Disetujui oleh: user (percakapan sesi 2026-10-08), tanggal: 2026-10-08
