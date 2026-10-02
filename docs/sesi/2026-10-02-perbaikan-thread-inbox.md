# Checkpoint Sesi

- **Tanggal**: 2026-10-02
- **Status**: implementasi selesai; disinkronkan dengan `v2.4` (merge `a79a7d8`); folder spike dihapus; menunggu PR/review dan uji manual di server pengembang
- **Repo / branch**: `AuliaPos`, branch `claude/pensive-feynman-lfajpe` (tanpa pull request)

## Selesai

- Spike `vue-advanced-chat` (kode sudah dihapus dari repo, ada di commit `deeedb1`; laporan `docs/laporan-spike-vue-advanced-chat.md`): hasil formal GO-BERSYARAT, tetapi 500 pesan membutuhkan 2,3-3,6 detik per pembaruan (render sendiri 130 ms). Keputusan user: Opsi C (tampilan ditiru, tanpa library), 200 pesan dengan pagination.
- Tahap 0: revisi `docs/requirements/2026-10-02-perbaikan-thread-inbox.md` (AC-1..AC-31) dan `docs/design/2026-10-02-perbaikan-thread-inbox.md`.
- Tahap 1: `MessageModel::getPageByConversation()` (200 pesan terbaru, kursor `(message_timestamp, id)`, `has_more`), `Inbox::apiMessages()` dengan `before_id` (400 / 404), `tests/feature/InboxMessagesPaginationTest.php`, entri `docs/CHANGELOG.md`. Bug batas 500 yang mengambil pesan **tertua** ikut teratasi.
- Tahap 2: logika thread dipindah ke `public/assets/js/inbox-thread.js`; render per pesan (HTML bubble sebagai tanda tangan perubahan, perencana murni). Terbukti di browser: gambar tetap node yang sama, seleksi teks bertahan, pembaca di atas tidak diseret; markup 39 bubble identik dengan versi lama.
- Tahap 3: tombol "Muat pesan lama", pemisah tanggal, kutipan bisa diklik (memuat riwayat bertahap, maks. 5 halaman), format teks WhatsApp dan tautan, centang dan tanda "!", tombol gulung ke pesan terbaru. Pengaman: respons polling percakapan lama dibuang setelah pindah percakapan.

## Verifikasi (dijalankan di sandbox)

- `node tests/js/inbox-thread.test.js`: 39 tes lulus (diuji dengan mutasi: tes gagal bila logika dirusak).
- `php vendor/phpunit/phpunit/phpunit --configuration phpunit.feature.xml`: 13 lulus (MariaDB 10.11 sandbox). `phpunit` bawaan: 32 lulus.
- Browser sungguhan (Playwright + aplikasi CI4 penuh + 539 pesan): 200 awal, muat lama menjaga posisi baca, polling tidak menghapus halaman lama, loncat kutipan memuat 2 halaman, tombol gulung menampilkan jumlah pesan baru.

## Keputusan penting

- Kursor pagination memakai `(message_timestamp, id)`, bukan `id` saja, supaya pesan terlambat dari gateway tidak terlewat atau ganda.
- HTML bubble dipakai sebagai tanda tangan perubahan: semua state sisi klien (media gagal, gateway terputus, penanda tanpa kutipan) otomatis ikut, tanpa daftar state terpisah.
- Format teks: escape HTML dahulu, kode dan URL disisihkan dalam penampung, penanda hanya berlaku bila menempel pada teks. `*tebal*` benar-benar tebal (library `vue-advanced-chat` menampilkannya miring).

## Tersisa

- Uji manual di server pengembang memakai data nyata: daftar regresi `docs/requirements/2026-10-02-perbaikan-thread-inbox.md` §7 (video, audio, grup, view-once, media kadaluarsa, kirim balasan sungguhan, Teruskan).
- Pengiriman balasan (`tampilkanBubbleOutgoing` lewat gateway) belum diuji dengan gateway nyata; hanya lewat DOM tiruan.
- Jalankan tes feature di mesin pengembang (sandbox memakai MariaDB sendiri). Kaitkan ke `composer test`: **TODO-T5**.
- Persetujuan Gate 1 (requirements) dan Gate 2 (design) belum dicentang oleh user.
