# Checkpoint Sesi — Analisa pesan gambar hilang dari 628214065612

- **Tanggal**: 2026-10-14
- **Status**: selesai — murni analisa **read-only**, tidak ada perubahan kode/data produksi.
- **Repo / target**: aulia7 (`\\aulia7` via WinRM, service `AuliaPosGateway*`), `aulia_inboxdb`
  (AULIA-SERVER2, MySQL), Evolution Postgres `evolution_gateway_pg` (aulia7:5432), snapshot
  media store `C:\AuliaPosGateway\evolution-gateway\data\media`, SQLite gateway
  `evolution-gateway.sqlite`.
- **Rujukan**: `docs/TODO.md` (TODO-F10); insiden pendahulu 2026-10-05 pelanggan Mell,
  `docs/sesi/2026-10-06-analisa-log-gateway-aulia3.md`.

## Konteks

Laporan awal: "pesan dari nomor `628214065612`, ada 1 file gambar yg tidak masuk jam 12.13".
Ditindaklanjuti dengan penelusuran berlapis dari WhatsApp → Evolution (aulia7) → adapter gateway
→ CI4 → DB, plus verifikasi media store gateway dan store persisten `incoming_queue`.

## Selesai

- Penelusuran read-only lintas lapis untuk `conversations.id=363`
  (`628214065612@s.whatsapp.net`, kontak "Farel").
- Verifikasi bahwa gambar id=5830 (12:14:36, `5830.jpg`, 109074 byte) **normal & bisa dibuka**
  (login produksi, `GET /inbox/media/5830` → HTTP 200 `image/jpeg` 109074 byte, sama dengan DB).
- Akses WinRM ke aulia7 (`ops`, Negotiate; target `aulia7` harus memakai `-ComputerName aulia7`,
  bukan IP — `TrustedHosts` berisi `aulia7`). Sesi ditutup di akhir; tidak ada sesi menggantung.
- Query Evolution Postgres (`Message`, `MessageUpdate`, `Media`), log `Evolution.out.log`/`err.log`,
  dan SQLite gateway (`incoming_queue`).

## Temuan

1. **Pesan masuk yang benar-benar sampai hari itu (chat 363, 2026-10-14)** — konsisten di 4 lapis
   (Evolution store, gateway `incoming_queue`, adapter log, `aulia_inboxdb.messages`):
   - 12:13:06 `AC7F29FBEF19427DE9713DAF9CC8D240` teks "Prin kak"
   - 12:13:07 `ACC6C1C024D59EBFDBAE902CE829A8DC` teks "Yang kecil cocok di buku kak"
   - **12:14:36 `ACF88A6EB20F07582F169C2327F35D7A` imageMessage** ("Seukuran ini kak", `5830.jpg`)
   - 12:16:58 `AC44EC0D2A8C7E93DDF93851DFC63393` teks "4n kak"
   - 12:18:03 `ACD55539785C4DC2B712C1B38E64CE16` teks "Atas nama jepri kak"
2. **Gambar 12:13 memang ada di WhatsApp, tapi tidak sampai ke server.** Bukti dari screenshot
   WhatsApp Web toko: pelanggan mengirim **poster promo "pizza khas jawa barat"** pukul 12:13,
   tepat sebelum balasan kasir "kirim failenya kk". Namun pesan itu **tidak ada di lapis mana pun
   di server**:
   - Evolution `Message`: tidak ada; `MessageUpdate`: 0 baris; `Media`: tabel **kosong**.
   - Media store gateway: antara 12:00–12:20 hanya `mv7ndrxq` (12:06, media keluar) dan
     `mv7nnyjt` (12:14:34, gambar 5830) — **tidak ada media pada 12:13**.
   - Gateway `incoming_queue` (SQLite): tidak ada. Adapter log: **0 kemunculan**.
   - `aulia_inboxdb.messages`: tidak ada.
3. **Jejak tunggal pesan hilang**: Evolution log memuat `WARN Original message not found for update.
   Skipping.` untuk `keyId=AC4FC3D89119F2F9F649DC7608B388C2`, `remoteJid=89636722442406@lid`,
   `fromMe:false` — 2× (12:18:01 dan 12:36:56). Artinya WhatsApp mengirim **kabar baca** untuk
   pesan masuk pelanggan ini, tetapi **isi pesan tak pernah diterima Evolution**. ID ini **tidak
   ada** di Evolution Postgres, gateway `incoming_queue`, adapter log, maupun CI4.
4. **Indikasi akar masalah sama dengan TODO-F10**: `AuliaPosGatewayEvolution.err.log` berisi
   tumpukan `Failed to decrypt message with any known session...` + `Session error:Error: Bad MAC`
   (`libsignal ... SessionCipher.decryptWithSessions`) — kegagalan dekripsi Signal **per-perangkat**.
   File ini terakhir ditulis **2026-10-14 11:41:28 WIB** (sebelum 12:13) dan tidak bertambah sejak
   itu. Karena Evolution membuang pesan yang gagal didekripsi **tanpa menulis baris `Message`**,
   gambar itu tidak dapat dipulihkan dari server.
5. **Celah pemantauan**: `monitor-gateway-aulia7.log` melaporkan `F10 recent=0` (decrypt=0,
   sessionNoMatch=0, stubIgnored=0) padahal hari itu ada kegagalan dekripsi. Monitor aulia7 hanya
   mem-parse `Evolution.out.log`; error dekripsi ditulis ke **stderr** (`err.log`), sehingga tidak
   terdeteksi. Ini gap yang perlu ditutup (lihat SOP remediation §4).

## Keputusan penting

- **Tidak ada** perubahan kode/data/konfigurasi. Semua akses read-only (WinRM, MySQL, Postgres,
  SQLite read-only, HTTP GET). File probe sementara di `%TEMP%` aulia7 sudah dihapus; sesi WinRM
  ditutup.
- **Verifikasi sidik jari lanjutan tidak diperlukan**: screenshot WhatsApp sudah memastikan itu
  gambar, dan store server terbukti kosong — tidak ada lagi yang bisa diambil dari server.
- Remediation gateway (restart instance / relink / upgrade Baileys) **ditunda** dan didokumentasikan
  sebagai SOP; menunggu keputusan & persetujuan user (menyentuh produksi).

## Tersisa

Dipindahkan ke `docs/TODO.md` (lihat TODO-F10; catatan baru 2026-10-14). SOP remediation tersedia di
`docs/ops/SOP-remediasi-sesi-signal-lid-gateway-aulia7.md`.

## Belum diverifikasi / risiko

- Jenis pasti pesan hilang (`AC4FC3D…`) tidak tersimpan di mana pun; screenshot mengindikasikan
  gambar, tetapi tidak bisa dikonfirmasi dari data server.
- Apakah kegagalan 12:13 menulis ke `err.log` (kemungkinan tidak, karena Baileys drop senyap) —
  `err.log` tanpa timestamp, hanya bisa dibatasi lewat mtime file.
- Apakah restart instance (Tingkat 1 SOP) benar-benar menghentikan `Bad MAC` — belum diuji.

## Titik masuk sesi berikutnya

- **Baca**: file ini; `docs/TODO.md` (TODO-F10);
  `docs/ops/SOP-remediasi-sesi-signal-lid-gateway-aulia7.md`.
- **Jalankan** (bila user menyetujui): SOP remediation Tingkat 1 (restart instance `aulia-toko`)
  di luar jam ramai; atau tindak lanjut gambar yang hilang dengan minta pelanggan kirim ulang.
