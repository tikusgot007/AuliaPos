# Checkpoint Sesi

- **Tanggal**: 2026-10-04
- **Status**: selesai (kode + test; commit `bb5e5da`)
- **Repo / branch**: `aulia-app` / `v2.4`

## Selesai

- TODO-BL04: transaksi `batal` tidak bisa lagi menerima pembayaran baru.
  - `app/Models/TransaksiModel.php`: guard di chokepoint `tambahPembayaran()` —
    tolak bila `$transaksi['status'] === 'batal'`. Menutup `Api::tambahPembayaran()`,
    `Api::koreksiPembayaran()`, dan caller lain tanpa duplikasi.
  - Test: `tests/integration/TransaksiPembayaranStatusTest.php` (2 test) — batal
    ditolak (0 baris pembayaran) + kontrol `proses` tetap menerima pembayaran.

## Keputusan penting

- Guard ditaruh di model (satu root cause bersama), bukan diduplikasi di tiap
  kontroler. `Tagihan::lunasi()` tetap punya guard lebih awal (sudah ada).
- Tidak ada perubahan aturan bisnis baru: `batal` = status terminal sudah
  ditegakkan di `Tagihan::lunasi` (`Tagihan.php:293-299`) dan UI; ini menutup
  celah endpoint. Karena itu `docs/CHANGELOG.md` tidak diubah.

## Tersisa

Tidak ada. Baris TODO-BL04 sudah dihapus dari `docs/TODO.md` dengan persetujuan
user (ID direferensikan di pesan commit `bb5e5da`).

## Belum diverifikasi / risiko

- Tidak ada uji endpoint-level otomatis untuk `POST /api/tambah-pembayaran`
  (butuh sesi/auth); guard diverifikasi di level model.
- UI/`BL-40` (tombol Lunasi tanpa cek status) di luar cakupan ini.

## Titik masuk sesi berikutnya

- **Baca**: `docs/TODO.md` (TODO-BL04)
- **Jalankan**: `php vendor/bin/phpunit --configuration phpunit.integration.xml`
  lalu `php vendor/bin/phpunit` dan `php vendor/bin/phpunit --configuration phpunit.feature.xml`
