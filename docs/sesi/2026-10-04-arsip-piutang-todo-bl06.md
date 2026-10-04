# Checkpoint Sesi

- **Tanggal**: 2026-10-04
- **Status**: selesai (kode + test; belum di-commit)
- **Repo / branch**: `aulia-app` / `v2.4`

## Selesai

- TODO-BL06 (DEC-3 "A+ dengan katup E"): Archive Transaksi tidak lagi memindahkan
  piutang aktif.
  - `app/Services/TransaksiArchiveService.php`: filter eligibilitas di satu
    helper `terapkanFilterEligibleArchive()` (lunas / batal / mangkrak) yang
    dipanggil `preview()` & `jalankan()`; helper `terapkanFilterPiutangAktif()`
    untuk himpunan yang dikecualikan; `preview()` kini melaporkan
    `jumlah_piutang_aktif`/`total_piutang_aktif`; pesan `jalankan()` dibedakan
    saat bulan hanya berisi piutang; bug bucket `per_status['batal']` diperbaiki
    (dihitung dari kolom `status`, bukan `status_pembayaran`).
  - `app/Views/archive_transaksi/index.php`: baris "Piutang aktif (TIDAK
    diarsipkan)" + label "akan diarsipkan".
  - **Perbaikan keamanan test**: `TransaksiArchiveService` memakai
    `Database::connect()` (grup default) alih-alih literal `'default'`, supaya
    di `ENVIRONMENT=testing` ikut dialihkan ke grup `tests` — tanpa ini service
    membaca/menghapus MySQL live saat test.
  - Test: `tests/integration/TransaksiArsipPiutangTest.php` (3 test, AC-1..AC-6).
  - `docs/CHANGELOG.md`: aturan baru dicatat.
- ESC-002 dicek (read-only): `transaksi_archive` = 0 baris, arsip belum pernah
  dijalankan → tidak ada piutang terlanjur terarsip.

## Keputusan penting

- Aturan eligible: `status_pembayaran='lunas'` ATAU `status IN ('batal',
  'mangkrak')`. Piutang aktif (`belum_bayar`/`dp`, bukan batal/mangkrak) tidak
  pernah diarsipkan. `mangkrak` = katup (admin menandai dulu lewat alur yang
  sudah ada).
- `Tagihan.php` tidak diubah (sudah mengecualikan `batal`/`mangkrak`).
- Tanpa perubahan skema DB.

## Tersisa

Lihat/pindahkan ke `docs/TODO.md`. Baris TODO-BL06 **belum dihapus** (menunggu
persetujuan user).

## Belum diverifikasi / risiko

- Belum ada uji coba archive nyata dengan data produksi (arsip masih kosong);
  verifikasi via test integrasi SQLite sintetis.
- Uji browser halaman Archive Transaksi (panel preview) belum dilakukan.

## Titik masuk sesi berikutnya

- **Baca**: `docs/TODO.md` (TODO-BL06), `docs/design/2026-10-04-arsip-piutang.md`
- **Jalankan**: `php vendor/bin/phpunit --configuration phpunit.integration.xml`
  lalu `php vendor/bin/phpunit` dan `php vendor/bin/phpunit --configuration phpunit.feature.xml`
