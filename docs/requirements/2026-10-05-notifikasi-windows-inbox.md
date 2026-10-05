# Requirements: Notifikasi Windows untuk pesan Inbox (ganti toast)

- **Tanggal**: 2026-10-05
- **Status**: disetujui (Gate 1)
- **Tier SDLC**: A
- **Penanggung jawab**: user (pemilik toko)

## 1. Tujuan

Kasir yang sedang di halaman lain (mis. layar Kasir) tetap langsung tahu ada pesan
WhatsApp yang perlu dibalas, lewat **notifikasi Windows (Action Center)** yang muncul
walau tab POS tidak difokuskan — menggantikan peran utama toast hijau di dalam halaman.

## 2. Kondisi saat ini (terverifikasi)

- Notifikasi lintas halaman sekarang: judul tab `(N)`, titik favicon, beep Web Audio, dan
  **toast hijau sticky** di `#inboxNotifToastStack` — `public/assets/js/inbox-notifikasi.js`;
  dimuat global dari `app/Views/layout/main.php:1231`, dipanggil `mulaiNotifikasiInbox(20000)`
  (`app/Views/layout/main.php:1261`).
- Endpoint sumber `GET /inbox/api/notifikasi-ringkas` (`app/Config/Routes.php:66`,
  `app/Controllers/Inbox.php:506`).
- Requirement lama secara eksplisit **mengecualikan notifikasi sistem / Web Notification API**
  — `docs/requirements/2026-10-03-notifikasi-inbox-lintas-halaman.md:40`.
- POS produksi diakses kasir lewat **HTTP LAN** `http://192.168.1.10/aulia/`
  (`docs/sesi/2026-09-30-wa-gateway-sesi-degraded.md:238`). Ini **bukan secure context** →
  `Notification` API **diblokir** selama akses masih HTTP.
- Gateway (adapter Node) memanggil balik POS lewat `CI4_BASE_URL` (mis. `http://127.0.0.1/aulia`)
  — `docs/sesi/2026-10-04-promosi-aulia-htaccess-todo-f8-e2e.md:22`,
  `docs/sesi/2026-10-02-paket-instalasi-gateway-pc-baru.md:48`.
- Apache PC dev memuat `ssl_module` + `httpd-ssl.conf`; konfigurasi SSL **AULIA-SERVER2 belum
  diverifikasi**.

## 3. User story

- Sebagai kasir, saya ingin menerima notifikasi **Windows** saat ada pesan WA perlu dibalas,
  supaya saya melihatnya walau POS tidak sedang aktif atau pesannya masuk ke Action Center.
- Sebagai kasir, saya ingin mengaktifkan izin notifikasi sekali saja, dan bisa membisukan
  bunyinya bila mengganggu.
- Sebagai kasir yang brousernya tidak mendukung / menolak izin, saya tetap ingin diberi tahu
  lewat toast seperti sekarang.

## 4. Acceptance criteria

- **AC-1**: Given `Notification` API tersedia dan `Notification.permission === 'granted'`,
  when polling menemukan percakapan relevan **baru**, then dibuat satu notifikasi Windows per
  percakapan (body berisi nama pengirim) dan **tidak** muncul toast hijau.
- **AC-2**: Given API tidak tersedia ATAU permission bukan `granted`, when ada item baru,
  then fallback = toast hijau sticky + beep (persis perilaku sekarang).
- **AC-3**: Given notifikasi Windows tampil, when diklik, then window Inbox (`AuliaInbox`)
  dibuka/difokuskan ke `?conversation_id=<id>` dan notifikasi itu ditutup.
- **AC-4**: Given status bisu aktif (tombol lonceng), when notifikasi Windows dibuat, then
  notifikasi tetap tampil tetapi **tanpa bunyi** (`silent: true`); beep fallback juga tidak bunyi.
- **AC-5**: Given tab POS sedang difokuskan, notifikasi Windows **tetap** dibuat (tidak
  disembunyikan karena fokus).
- **AC-6**: Given percakapan yang dinotifikasi sudah tidak relevan di polling berikutnya, then
  notifikasi Windows terkait ditutup (`close()`), analog toast yang hilang otomatis.
- **AC-7**: Given permission masih `default` dan API tersedia, when user menekan tombol
  "Aktifkan notifikasi" (gestur user), then `Notification.requestPermission()` dipanggil;
  `granted` mengaktifkan jalur Windows, `denied`/dismiss mempertahankan fallback toast.
- **AC-8**: Judul tab `(N)` dan titik favicon tetap berjalan seperti sekarang.
- **AC-9 (infra)**: Given POS disajikan HTTPS self-signed di `https://192.168.1.10/aulia/` dan
  sertifikat dipercaya di PC kasir, when halaman dibuka, then `Notification` API tersedia
  (secure context) dan AC-1..AC-8 berlaku; HTTP:80 tetap melayani gateway tanpa redirect.

## 5. Batasan dan di luar cakupan

- Batasan: notifikasi hanya saat browser/tab POS hidup (tanpa service worker/push); tidak ada
  perubahan kontrak Gateway.
- Di luar cakupan: Web Push saat browser ditutup; aplikasi desktop native; notifikasi ke HP.
- Infra HTTPS self-signed dikerjakan sebagai langkah operasional terpisah (butuh approval
  produksi tersendiri).

## 6. Dampak aturan bisnis

- [x] Mengubah perilaku UI/notifikasi (dicatat di `docs/CHANGELOG.md` sebagai perubahan operasional)
- [ ] Menyentuh data keuangan (`transaksi`, `pembayaran`, `tagihan`, kas)
- [ ] Mengubah skema database
- [ ] Mengubah kontrak POS <-> WA Gateway (tidak ada; gateway tetap HTTP:80)

## 7. Asumsi dan pertanyaan terbuka

- Keputusan: beep Web Audio **tetap dibunyikan** di jalur Windows sebagai jaring pengaman —
  Windows bisa membisukan notifikasi (Focus Assist / notifikasi per-app dimatikan) sementara
  konstruktor notifikasi tetap sukses. Konsekuensi: bunyi beep bisa berbarengan dengan bunyi
  bawaan Windows (bunyi dobel) — diterima demi jaminan sinyal ke kasir.
- Asumsi: "hapus toast lama total" = toast tidak tampil saat jalur Windows aktif; toast
  **dipertahankan hanya sebagai fallback** (sesuai jawaban "fallback ke toast sekarang").
- Belum diverifikasi: konfigurasi Apache/SSL di AULIA-SERVER2; cara impor sertifikat root di
  tiap PC kasir (jumlah PC belum diketahui); kemungkinan aset Inbox dimuat via URL HTTP absolut
  (risiko mixed-content saat halaman HTTPS).

## 8. Persetujuan (Gate 1)

- [x] Disetujui oleh: user, tanggal: 2026-10-05
