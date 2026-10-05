# Checkpoint Sesi

- **Tanggal**: 2026-10-05
- **Status**: sebagian (kode + test selesai; uji browser nyata & infra HTTPS produksi belum; belum di-commit)
- **Repo / branch**: `AuliaPos` / `v2.4`

## Selesai

- Notifikasi Inbox lintas halaman kini memakai **notifikasi Windows** (`Notification`
  API) sebagai jalur utama, dengan toast hijau lama sebagai **fallback** bila API tak
  tersedia / izin belum diberikan.
  - `public/assets/js/inbox-notifikasi.js`: `notifApiRef()`, `gunakanJalurWindows()`,
    `buatOpsiNotifikasiWindows()`, `tampilkanNotifikasiWindows()`,
    `bersihkanNotifikasiWindows()`, `mintaIzinNotifikasi()`,
    `perbaruiTombolIzinNotifikasi()`; percabangan di `muatNotifikasiInbox()`. Klik
    notifikasi → fokus window Inbox ke percakapan; notifikasi ditutup saat percakapan
    tak lagi relevan; `silent` mengikuti tombol bisu; `notifikasiAktif` di-dedup per `id`.
  - `app/Views/layout/main.php`: tombol "Aktifkan notifikasi" (`btnIzinNotifInbox`,
    muncul hanya saat izin `default`) untuk `Notification.requestPermission()`.
  - Beep Web Audio **tetap** dibunyikan di jalur Windows (jaring pengaman bila OS
    membisukan notifikasi); bunyi dobel dengan Windows diterima.
- Test: `tests/js/inbox-notifikasi.test.js` +8 test (AC-1..AC-8) → **25/25 lulus**;
  `node --check` OK; `php -l main.php` OK.
- Dokumen: `docs/requirements/2026-10-05-notifikasi-windows-inbox.md` (Gate 1),
  `docs/design/2026-10-05-notifikasi-windows-inbox.md` (Gate 2), entri
  `docs/CHANGELOG.md`, temuan `TODO-N1` di `docs/TODO.md`.

## Keputusan penting

- Jalur utama = `Notification` API dari halaman (tanpa service worker/push) — alasan:
  cukup untuk kebutuhan "tahu saat bekerja di PC POS", tanpa infrastruktur push baru.
- Toast hijau dipertahankan **hanya** sebagai fallback — alasan: permintaan user
  "fallback ke toast sekarang".
- Notifikasi Windows hanya aktif di secure context; produksi HTTP LAN butuh HTTPS
  self-signed pada AULIA-SERVER2 — alasan: `Notification` API diblokir di non-secure
  context. HTTP:80 harus tetap melayani agar gateway (`CI4_BASE_URL` http) tidak putus.

## Tersisa

- Lihat `docs/TODO.md` **TODO-N1** (infra HTTPS produksi; butuh approval tersendiri).
- Belum ada commit; perubahan masih di working tree.

## Belum diverifikasi / risiko

- Uji nyata di browser/secure context (izin, tampilan Action Center, klik notifikasi,
  bisu) belum dilakukan; baru diuji unit via `vm`.
- Infra HTTPS AULIA-SERVER2 belum dikerjakan: konfigurasi Apache/SSL, jumlah PC kasir
  untuk trust sertifikat, dan kemungkinan aset Inbox via URL HTTP absolut
  (mixed-content) belum diperiksa.
- Selama produksi masih `http://192.168.1.10/aulia`, yang aktif adalah fallback toast.

## Titik masuk sesi berikutnya

- **Baca**: `docs/design/2026-10-05-notifikasi-windows-inbox.md`, `docs/TODO.md` (TODO-N1)
- **Jalankan**: `node tests/js/inbox-notifikasi.test.js`; uji manual di
  `http://localhost/aulia` (secure context) → klik tombol "Aktifkan notifikasi".
