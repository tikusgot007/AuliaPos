# Checkpoint Sesi

- **Tanggal**: 2026-10-04
- **Status**: selesai (kode + test; commit `3f0294e`)
- **Repo / branch**: `aulia-app` / `v2.4`

## Selesai

- TODO-BL05: user dengan `is_active = 0` tidak bisa login lagi.
  - `app/Controllers/Auth.php`: setelah verifikasi password berhasil, cek
    `empty($user['is_active'])` → tolak dengan pesan "Akun Anda tidak aktif.
    Hubungi admin." tanpa membuat session.
  - Test: `tests/feature/AuthLoginAktifTest.php` (3 test) — non-aktif ditolak,
    aktif berhasil login, dan non-aktif + password salah tetap "Password salah"
    (tidak membocorkan status).

## Keputusan penting

- Cek `is_active` ditaruh SETELAH verifikasi password — supaya status akun
  non-aktif tidak bisa diprobe tanpa kredensial yang benar.
- Tanpa `CHANGELOG` (bug fix: menegakkan `users.is_active` yang selama ini tidak
  berlaku untuk login).

## Tersisa

Tidak ada. Baris TODO-BL05 sudah dihapus dari `docs/TODO.md` dengan persetujuan
user (ID direferensikan di pesan commit `3f0294e`).

## Belum diverifikasi / risiko

- Session user yang sudah aktif lalu dinonaktifkan di tengah sesi TIDAK
  dibatalkan (session guard) — di luar cakupan register, perlu item tersendiri.
- Tidak ada rate-limit/lockout login (TODO-S2, terpisah).

## Titik masuk sesi berikutnya

- **Baca**: `docs/TODO.md` (TODO-BL05)
- **Jalankan**: `php vendor/bin/phpunit --configuration phpunit.feature.xml`
  lalu `php vendor/bin/phpunit` dan `php vendor/bin/phpunit --configuration phpunit.integration.xml`
