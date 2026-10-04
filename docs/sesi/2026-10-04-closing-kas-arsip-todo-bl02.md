# Checkpoint Sesi

- **Tanggal**: 2026-10-04
- **Status**: selesai (kode + test; commit `efe83ed`)
- **Repo / branch**: `aulia-app` / `v2.4`

## Selesai

- TODO-BL02: snapshot `saldo_sistem` closing kas kini **final** dan perhitungan
  untuk closing baru sudah memperhitungkan penjualan tunai di arsip.
  - `app/Services/KalkulasiClosingKas.php` (baru): `saldoSistemFinal($snapshot,
    callable $hitungUlang)` — snapshot tersimpan selalu menang; hitung ulang
    dievaluasi lazy (hanya saat belum ada snapshot).
  - `app/Controllers/Cash.php`: `closingDetail()` & `simpanClosing()` memakai
    snapshot bila sudah ada; hitung `getSaldoKasHistoris($cutoff)` hanya untuk
    tanggal baru.
  - `app/Helpers/cash_helper.php`: `getSaldoKasHistoris()` = saldo live +
    penjualan tunai arsip (opsi injeksi service arsip untuk test).
  - `app/Services/TransaksiArchiveService.php`: konstruktor menerima koneksi
    arsip opsional (injeksi); method baru `getPenjualanTunaiMentah()`.
  - Test: `tests/unit/KalkulasiClosingKasTest.php` (3 test),
    `tests/integration/ClosingKasArsipTest.php` (2 test).
  - `docs/CHANGELOG.md`: aturan baru "snapshot saldo_sistem closing final".
- Gate 1 & 2 disetujui user (D1: imutabel = ya; D2: archive-aware).

## Keputusan penting

- Snapshot closing bersifat final (imutabel); hanya `saldo_fisik`/`selisih` yang
  bisa berubah — alasan: closing = fakta historis, tidak boleh drift setelah
  arsip.
- Closing baru pada bulan terarsip dihitung archive-aware (bukan ditolak) —
  alasan: admin tetap bisa melengkapi closing yang terlewat dengan angka benar.
- Jalur dashboard/opname hari ini tetap live-only (tidak ikut query arsip).

## Tersisa

Tidak ada. Baris TODO-BL02 sudah dihapus dari `docs/TODO.md` dengan
persetujuan user (ID direferensikan di pesan commit `efe83ed`).

## Belum diverifikasi / risiko

- Tidak ada uji browser/manual untuk modal Closing Kas; verifikasi via unit +
  integration (bukan end-to-end controller).
- Belum diketahui apakah sudah ada bulan yang benar-benar diarsip di produksi
  (cacat laten).
- Beban instansiasi `TransaksiArchiveService` pada jalur closing baru belum
  diukur (frekuensi rendah).

## Titik masuk sesi berikutnya

- **Baca**: `docs/TODO.md` (TODO-BL02), `docs/design/2026-10-04-closing-kas-arsip.md`
- **Jalankan**: `php vendor/bin/phpunit --configuration phpunit.integration.xml`
  lalu `php vendor/bin/phpunit` dan `php vendor/bin/phpunit --configuration phpunit.feature.xml`
