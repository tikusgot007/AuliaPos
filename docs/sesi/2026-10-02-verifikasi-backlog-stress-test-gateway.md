# Checkpoint Sesi

- **Tanggal**: 2026-10-02
- **Status**: selesai (verifikasi + dokumentasi; tidak ada perubahan kode)
- **Repo / branch**: `aulia-app`, `v2.4`

## Selesai

- **TODO-F1** (uji backlog media): dieksekusi end-to-end di produksi (aulia3) —
  matikan watchdog + `AuliaAdapter`/`AuliaEvolution`, kirim foto dari HP saat
  gateway mati, nyalakan kembali, verifikasi backlog sampai. Terbukti lolos di
  level gateway (log) **dan** database (`aulia_inboxdb`, conversation `id=4`
  message `id=77`: timestamp pesan 14:09:25 WIB saat gateway mati, baru
  tersimpan 14:11:23 setelah gateway hidup). Baris TODO-F1 dihapus dari
  `docs/TODO.md` dengan persetujuan user — commit `26a7847`.
- **Stress test tambahan** (bukan di TODO asli, inisiatif user): gateway
  dimatikan lagi, ~209 pesan dikirim dari banyak nomor karyawan sekaligus.
  Terverifikasi lolos: `completed` queue 76→285, `pending`/`failed`/`dead` =
  0/0/0, 209 pesan masuk cocok persis di 11 conversation baru (`id` 6–16) di
  database, tidak ada yang hilang atau soft-deleted.
- **TODO-H1** (bersih-bersih data uji): cakupan dienumerasi ulang dan
  didokumentasikan — membesar dari 2 jadi 13 percakapan (270 pesan) akibat
  F1 + stress test hari ini. Belum dieksekusi (masih menunggu, lihat Tersisa).
  Commit `26a7847`.
- **Audit log**: dikonfirmasi tidak ada error baru akibat F1/stress test;
  satu-satunya error (`"error in sending keep alive"` 13:03 WIB) sudah
  tercatat sebelumnya di TODO-L1 dan terjadi sebelum jendela uji dimulai.
  Jendela uji 14:08–14:28 WIB dicatat di TODO-L1 supaya tidak disalahartikan
  saat audit log 5 Okt — commit `26a7847`.
- **Bug ditemukan saat verifikasi DB** (dicatat, belum diperbaiki — repo
  `evolution-gateway` terpisah):
  - **TODO-F4**: `extractQuotedContext()` (`normalize.js:306-319`) kehilangan
    relasi balas-pesan untuk balasan teks polos (`message.conversation`),
    karena `contextInfo` WhatsApp ada di level `record.contextInfo` (sejajar
    `message`), bukan di sub-objek tipe pesan yang dicek fungsi itu. Bukti:
    conv `id=6` message `id=190` ("Siap di goyang", balasan ke sticker
    `id=183`) tersimpan dengan `quoted_wa_message_id=NULL`.
  - **TODO-F5**: pesan **masuk** yang di-forward pelanggan tidak pernah
    ditandai — `normalize.js` tidak membaca `contextInfo.isForwarded`/
    `forwardingScore` sama sekali. Beda dengan arah keluar (fitur Teruskan
    kasir→pelanggan) yang sudah lengkap di CI4. 0 dari 270 pesan uji punya
    `is_forwarded=1`.
- **Dokumen riset baru**: `docs/riset-library-ui-chat.md` — handoff untuk tim
  riset terpisah user, mengevaluasi apakah tampilan Inbox sebaiknya pakai
  library UI chat siap pakai (mis. Advanced Chat Components/vue-advanced-chat)
  alih-alih fitur chat custom yang ditulis manual. Draf awal, belum
  mendalam — eksplisit menandai bug TODO-F4/F5 ada di lapisan gateway, bukan
  tampilan, supaya tim riset tidak salah sasaran.

## Keputusan penting

- **User ambil alih kendali remote langsung** (kredensial `ops`/aulia3 dan
  `aan`/MySQL inbox read-only) untuk menjalankan uji F1 dan stress test —
  bukan hanya memandu dari jauh seperti rencana awal.
- **User read-only `aan`** (dibuat sebelumnya oleh user, bukan sesi ini)
  dipakai untuk verifikasi database langsung dari mesin dev — alasan: akses
  root MySQL produksi menolak koneksi remote, dan membuat user baru
  mengikuti prinsip *least privilege* (AGENTS.md §6) ketimbang memakai root.
- **Pembacaan SQLite via SMB tidak diandalkan**: ditemukan bahwa membaca
  `incoming_queue` (mode WAL) lewat UNC path sempat memberi hasil basi
  (`pending=42` yang ternyata sudah 0) — pembacaan sahih memakai salinan
  lokal (WAL diputar) atau log/DB langsung, bukan SQLite read-only via SMB.
- **TODO-F4/F5 dicatat, tidak diperbaiki sesi ini**: perbaikannya ada di repo
  `evolution-gateway` yang terpisah dari `aulia-app`, di luar scope sesi ini.

## Tersisa

Lihat `docs/TODO.md`:
- **TODO-H1** — cakupan sudah terenumerasi (13 percakapan/270 pesan), belum
  dieksekusi pembersihannya.
- **TODO-F4**, **TODO-F5** — perbaikan bug gateway (repo terpisah).
- Riset lanjutan untuk `docs/riset-library-ui-chat.md` — ditangani tim riset
  user, bukan sesi kode berikutnya.

## Belum diverifikasi / risiko

- Baris Inbox POS untuk F1/stress test **belum dikonfirmasi visual di UI**
  oleh user (hanya diverifikasi lewat query database langsung); secara data
  sudah cukup kuat, tapi rendering di browser belum dicek mata.
- `docs/riset-library-ui-chat.md` ditulis dari diskusi eksploratif + satu
  README resmi (Advanced Chat Components); kandidat lain yang disebut
  (react-chat-elements, chatscope, CometChat/Stream) **belum diverifikasi**
  sama sekali terhadap dokumentasi resminya.

## Titik masuk sesi berikutnya

- **Baca**: `docs/TODO.md` (TODO-H1 cakupan 13 percakapan, TODO-F4/F5),
  `docs/riset-library-ui-chat.md` jika melanjutkan riset UI.
- **Jalankan**: tidak ada perintah wajib; kalau melanjutkan TODO-H1, cek dulu
  apakah user sudah menyetujui daftar 13 `conversation.id` yang akan
  dibersihkan sebelum menjalankan DELETE/soft-delete apa pun di
  `aulia_inboxdb` (data produksi, perlu persetujuan eksplisit — AGENTS.md
  §7, §11).
