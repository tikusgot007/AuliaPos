# Checkpoint Sesi — Investigasi akar penyebab insiden WA Gateway degraded

- **Tanggal**: 2026-09-30
- **Status**: sebagian (investigasi selesai; keputusan nyalakan Gateway ke
  nomor toko belum diambil)
- **Repo / branch**: `aulia-app` (v2.4) — tidak ada perubahan kode di sesi
  ini; investigasi menyentuh `WA-Gateway` (`C:\Projects\WA-Gateway`, master)
  dan HP toko (Infinix X6728, `146824057X003855`) via `adb`.

## Latar belakang

User mengajukan keberatan atas kesimpulan §6 laporan insiden
(`docs/sesi/2026-09-30-wa-gateway-sesi-degraded.md`): "Gateway custom BUKAN
penyebab kerusakan WhatsApp di HP" dianggap terlalu cepat disimpulkan,
karena waktunya kebetulan pas (hari pertama Gateway jalan, crash HP muncul
beberapa jam kemudian). Sesi ini melakukan investigasi forensik ulang
sebelum Gateway dinyalakan lagi ke nomor toko.

## Selesai

- **Autostart Gateway di `aulia3` dinonaktifkan** (`Disable-ScheduledTask`
  via CIM/DCOM, bukan WinRM — WinRM butuh TrustedHosts+elevasi yang tidak
  tersedia). Terverifikasi: `State: Disabled`, tidak ada proses `node.exe`
  berjalan. Task tidak dihapus, tinggal `Enable-ScheduledTask` untuk
  mengaktifkan lagi.
- **`adb` (Android Platform Tools) diinstal** di mesin kerja via
  `winget install Google.PlatformTools`. HP toko disambungkan USB; driver
  awal error (Code 19), diperbaiki lewat uninstall+reconnect device di
  Device Manager (bukan reinstall paksa) — device akhirnya terdeteksi
  normal (`ADB Interface` status OK).
- **Log Gateway penuh dianalisis** (`\\aulia3\D\WA-Gateway\logs\gateway.log`,
  5.716 baris, 29 Sep 09:14Z → 30 Sep 03:08Z — Gateway proses berhenti
  30 Sep 10:08:32 WIB, dikonfirmasi lewat isi log dan `LastWriteTime` file).
- **Riwayat crash HP diambil via `adb shell dumpsys dropbox --print`**
  (81 entri, 28 Sep 03:53 → 30 Sep 10:48 WIB — mencakup ~36 jam SEBELUM
  Gateway pertama kali jalan).
- **Timezone/jam HP diverifikasi sama dengan PC** (`Asia/Jakarta`, WIB) —
  timestamp log Gateway dan dropbox HP bisa dibandingkan langsung tanpa
  offset.
- **Dicari referensi publik** (GitHub Issues) untuk pola serupa: 3 laporan
  independen (Baileys #2074, NousResearch/hermes-agent #63277,
  moryoav/ha-addons #7) mengonfirmasi pola "@lid + SessionError memicu
  korupsi koneksi" dan "status API melaporkan `connected` padahal sesi
  sedang bermasalah" adalah **masalah yang dikenal di ekosistem Baileys**,
  bukan sesuatu yang unik ke setup ini.

## Temuan kunci (revisi atas laporan insiden sebelumnya)

1. **Tidak ada riwayat crash WhatsApp Business SEBELUM Gateway pernah
   jalan.** Dropbox HP menyimpan riwayat sejak 28 Sep 03:53 (36 jam sebelum
   Gateway hidup); satu-satunya entri pada window itu adalah ANR
   `com.google.ar.core` (tidak relevan). Ini **mendukung** kecurigaan user
   bahwa korelasi waktu bukan kebetulan murni.
2. **Tapi crash TERUS terjadi 40 menit SETELAH Gateway proses benar-benar
   mati** (30 Sep 10:08:32 → crash berlanjut sampai 10:48:33, 7+ kali,
   dengan nol proses `node.exe` berjalan). Ini **membantah** model
   "Gateway aktif = penyebab langsung tiap crash" dari laporan sebelumnya
   dan dari kecurigaan awal user — kalau memang aktivitas real-time
   Gateway penyebabnya, crash seharusnya berhenti begitu Gateway mati.
3. **Reinstall WhatsApp Business (30 Sep 10:50:11 WIB) menyembuhkan total**
   — nol crash pada ~9 jam berikutnya (sampai sesi ini dijalankan).
   Reinstall menghapus data lokal app, bukan mengubah apa pun di sisi
   Gateway.
4. **Kesimpulan kerja (hipotesis "sebab-akibat tertunda", BUKAN bukti
   final)**: linking Gateway sebagai device baru kemungkinan memicu
   penulisan data yang korup di storage lokal WhatsApp Business (via jalur
   sinkronisasi `@lid` yang dikenal rawan di Baileys — didukung 3 referensi
   publik di atas), dan data korup itu menjadi penyebab crash berulang
   TERLEPAS dari status Gateway hidup/mati, sampai dibersihkan lewat
   reinstall.
5. §6 laporan insiden lama ("Gateway custom penyebab kerusakan WhatsApp di
   HP — TIDAK") **perlu dikoreksi**: sanggahannya hanya membahas payload
   jaringan (bukan vektor yang relevan), padahal kekhawatiran user adalah
   efek samping ke app-state/storage lokal HP — vektor yang berbeda dan
   TIDAK terbantahkan oleh argumen lama. Koreksi ditambahkan sebagai
   adendum di file laporan insiden, lihat bagian bawah file tersebut.

## Keputusan penting

- **Gateway TIDAK dinyalakan ke nomor toko di sesi ini** — user secara
  eksplisit menahan diri sampai ada kepastian lebih kuat bahwa insiden
  tidak akan terulang.
- Autostart (Scheduled Task) sengaja dimatikan sebagai langkah pencegahan
  sementara, supaya Gateway tidak hidup sendiri tanpa pengawasan saat
  komputer `aulia3` reboot.

## Tersisa / TODO

- [ ] **Keputusan akhir**: nyalakan Gateway ke nomor toko atau tidak,
      dan dengan mitigasi apa. Rekomendasi kerja (belum disetujui user):
      uji dulu di nomor/HP terpisah sebelum link ke nomor toko; kalau
      tidak memungkinkan, link ke nomor toko tapi pantau ketat 4-6 jam
      pertama dengan siap logout+matikan bila ada tanda crash.
- [ ] Kalau diputuskan nyalakan lagi: `Enable-ScheduledTask -TaskName
      "WA-Gateway"` di `aulia3`, lalu Logout + scan QR ulang (sesi lama
      sudah tidak valid).
- [ ] Root cause presisi (file/data spesifik apa yang korup di storage
      WhatsApp Business, dan baris kode Gateway persis mana yang
      memicunya) masih belum diverifikasi — butuh akses root HP atau
      reproduksi terkontrol di nomor uji untuk dikonfirmasi lebih jauh.
- [ ] TODO lama dari laporan insiden (§10, belum berubah): perbaikan
      permanen `RewriteBase` `.htaccess` produksi (workaround
      skip-worktree masih berlaku).
- [ ] **Keamanan**: password akun `ops` (`aulia3`) sempat diketik polos di
      chat sesi ini untuk operasi `Disable-ScheduledTask`. User disarankan
      mengganti password akun tersebut. Ke depan gunakan `Get-Credential`
      agar kredensial tidak masuk transkrip.

## Belum diverifikasi / risiko

- Mekanisme presis "linking Gateway → data lokal HP korup" belum
  dikonfirmasi lewat kode/dokumentasi resmi Baileys atau WhatsApp — ini
  tetap hipotesis kerja berbasis korelasi forensik + referensi komunitas,
  bukan bukti definitif.
- Keterbatasan tool pencarian web di sesi ini: mesin pencari umum (Bing
  via webfetch) tidak memproses query teknis presisi (tanda kutip,
  istilah spesifik) dengan baik — hasil sering melenceng ke istilah yang
  mirip secara leksikal. Pencarian GitHub Issues jauh lebih presisi dan
  itu yang menghasilkan temuan berguna. Pencarian tambahan di masa depan
  sebaiknya prioritaskan GitHub Issues/Discussions untuk topik teknis.
- Belum ada percobaan reproduksi terkontrol (nomor uji terpisah) untuk
  mengonfirmasi/membantah hipotesis secara langsung.

## Titik masuk sesi berikutnya

- **Baca**: file ini dulu, lalu adendum koreksi di
  `docs/sesi/2026-09-30-wa-gateway-sesi-degraded.md` (bagian bawah file),
  lalu `docs/sesi/README.md` untuk daftar checkpoint lain.
- **Jalankan**: `git -C C:\xampp\htdocs\aulia-app status` dan
  `git -C C:\Projects\WA-Gateway status` untuk pastikan tidak ada drift.
  Cek status Scheduled Task `WA-Gateway` di `aulia3` (masih `Disabled`?)
  sebelum mengasumsikan Gateway mati.
- **Keputusan yang menunggu user**: apakah dan bagaimana menyalakan
  Gateway ke nomor toko lagi (lihat TODO di atas).
