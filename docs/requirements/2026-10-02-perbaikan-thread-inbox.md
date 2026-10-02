# Requirements: Perbaikan Thread Pesan Inbox

- **Tanggal**: 2026-10-02
- **Status**: direvisi 2026-10-02 (cakupan diubah setelah spike); rencana pelaksanaan disetujui user, Gate 1 tetap di bagian 9
- **Tier SDLC**: A (fitur baru, termasuk satu perbaikan bug di poin 1). Bila ragu, naikkan tier (sdlc.md §1)
- **Penanggung jawab**: tim pengembang AuliaPos
- **Asal**: `docs/riset-library-ui-chat-inbox.md` (hasil riset) dan `docs/analisis-akar-masalah-gateway-f4-f5.md`. Hasil spike `docs/laporan-spike-vue-advanced-chat.md`. Keputusan user 2026-10-02: tanpa library UI (Opsi C, tampilan ditiru), **jendela 200 pesan terbaru dengan pagination** (menggantikan keputusan awal "tanpa pagination, 500 pesan")

## 1. Tujuan

Memperbaiki tampilan thread pesan di Inbox untuk kasir: pesan terbaru selalu tampil, riwayat lama bisa dibuka bertahap, thread tidak berkedip saat polling, dan fitur chat standar (pemisah tanggal, loncat ke kutipan, format teks, tombol gulung ke pesan terbaru, centang status, tautan otomatis) tersedia dengan tampilan yang meniru `vue-advanced-chat`, **tanpa** mengganti Inbox dengan library UI.

## 2. Kondisi saat ini (terverifikasi dari kode `v2.4`)

Nomor baris dari checkout `v2.4` commit `26a7847`; verifikasi ulang sebelum mengubah.

- **Bug laten, batas 500 memotong pesan terbaru** (model sendiri punya default `$limit = 200`, tetapi pemanggil memberi 500): `MessageModel::getByConversation()` (`app/Models/MessageModel.php:216-223`) mengurutkan `message_timestamp ASC, id ASC` lalu `limit($limit)`, sehingga yang diambil adalah pesan **tertua**. Satu-satunya pemanggil: `Inbox::apiMessages()` dengan limit 500 (`app/Controllers/Inbox.php:400`). Percakapan dengan lebih dari 500 pesan berhenti menampilkan pesan baru. Belum pernah teramati di produksi (pesan terbanyak saat ini 160 di conv 8); ini dari pembacaan kode.
- **Render ulang total**: `renderPesan()` (`app/Views/inbox/index.php:2910-2963`) mengganti `innerHTML` `#threadMessages` (baris 599) pada tiap siklus polling 4 detik (`index.php:3787-3789`, `muatUlangPesan()` baris 2965). Akibatnya `<img>` dibuat ulang, seleksi teks hilang.
- **State di sisi klien yang memengaruhi hasil render**: `mediaGagal` (baris 990), `mediaSementara` (997), `pesanTerkirimTanpaKutipan` (2120), `gatewayTerhubung`, `pesanCached` (2125, direset di 2930 dan 2092).
- **Bubble keluar langsung**: `tampilkanBubbleOutgoing()` (3127-3156) menyisipkan bubble setelah kirim tanpa menunggu polling.
- **Aksi per pesan** bergantung pada `pesanCached`: `pilihKutipan()` (2306), `bukaPemilihTeruskan()` (2380), `teruskanPesan()` (2506).
- **Kutipan**: `renderKotakKutipan()` (2167-2242); tidak bisa diklik. Respons API memuat `wa_message_id` tiap pesan (`SELECT *` via `findAll()`) dan `quoted_wa_message_id` pada pesan yang mengutip Terverifikasi: `attachSenderNames()` (`Inbox.php`) tidak membuang kolom apa pun dan `MessageModel` tidak punya `$hidden`/`$select`, jadi `wa_message_id` ikut di respons.
- **Waktu**: `formatWaktuInbox()` (1019) mem-parse `message_timestamp` sebagai waktu lokal browser.
- **Teks**: `escapeHtmlInbox()` (1012) dipakai untuk semua teks; tidak ada format teks WhatsApp.
- **JavaScript** Inbox seluruhnya ada di dalam `app/Views/inbox/index.php` (3.794 baris); tidak ada tes JavaScript di repo (`tests/` hanya `unit` dan `feature` PHPUnit; tidak ada `package.json`).

## 3. User story

- Sebagai kasir, saya ingin selalu melihat pesan terbaru di percakapan panjang, dan bisa membuka pesan yang lebih lama bila perlu, supaya tidak melewatkan pesan pelanggan.
- Sebagai kasir, saya ingin thread tidak berkedip dan teks yang saya seleksi tidak hilang saat pesan baru masuk.
- Sebagai kasir, saya ingin melihat pemisah tanggal dan bisa meloncat ke pesan yang dikutip, supaya konteks percakapan mudah dibaca.
- Sebagai kasir, saya ingin `*tebal*`, `_miring_`, dan `~coret~` dari pelanggan tampil seperti di WhatsApp.
- Sebagai kasir, saya ingin tombol gulung ke pesan terbaru, centang status kirim, dan tautan yang bisa diklik, supaya thread nyaman dibaca.
- Sebagai pengembang, saya ingin logika thread berada di berkas JavaScript terpisah, supaya bisa dites otomatis.

## 4. Acceptance criteria

Nomor dirujuk oleh tes (`AC-n`). Poin P1-P6 mengikuti urutan pengerjaan yang disarankan.

**P1. Pesan terbaru (perbaikan bug)**
- **AC-1**: Given percakapan berisi lebih dari 200 pesan, when `GET /inbox/api/conversations/{id}/messages` tanpa `before_id`, then respons memuat **200 pesan terbaru**, urut lama ke baru, dan pesan paling baru ada di akhir.
- **AC-2**: Given percakapan berisi 200 pesan atau kurang, then respons memuat semua pesan dengan urutan yang sama seperti sekarang (`message_timestamp ASC, id ASC`, tie-breaker `id`).
- **AC-3**: Field yang sudah ada (`status`, `conversation`, `messages` dan field tiap pesan) tidak berubah. Satu field baru ditambahkan, `has_more` (boolean), tanpa memengaruhi pembaca lama.

**P1b. Pagination riwayat**
- **AC-23**: Given `GET .../messages?before_id={id}` dengan `id` milik percakapan yang sama, then respons memuat sampai 200 pesan **tepat sebelum** pesan itu menurut urutan `(message_timestamp, id)`, urut lama ke baru, dan `has_more` bernilai `true` bila masih ada pesan yang lebih lama.
- **AC-24**: Given beberapa pesan memiliki `message_timestamp` yang sama, then kursor tidak melewatkan dan tidak menggandakan pesan di batas halaman (tie-breaker `id`).
- **AC-25**: Given `before_id` bukan angka positif, atau pesan itu tidak ada, atau milik percakapan lain, then respons 400 atau 404 tanpa membocorkan data percakapan lain.
- **AC-26**: Given jendela terbaru memuat seluruh riwayat, then `has_more` bernilai `false` dan tombol "Muat pesan lama" tidak tampil.
- **AC-27**: When kasir menekan "Muat pesan lama", then pesan lama ditambahkan di atas thread, posisi gulir tidak melompat (pesan yang sedang dibaca tetap di tempatnya), dan polling berikutnya tidak menghapus pesan lama yang sudah dimuat.

**P2. Pindahkan logika thread ke berkas JS terpisah**
- **AC-4**: Fungsi penggambar thread (`renderPesan` dan pendukung yang hanya dipakai thread) berada di berkas di `public/assets/js/`, dimuat oleh `index.php`. Nilai dari PHP (`base_url`, dll.) dikirim lewat satu objek konfigurasi.
- **AC-5**: Perilaku Balas, Teruskan, kutipan media 5 cabang, placeholder media gagal/kadaluarsa, label "Diteruskan", penanda "Terkirim tanpa kutipan", kartu lokasi/kontak, dan placeholder `unsupported` sama dengan sebelum perubahan (daftar regresi di §7).
- **AC-6**: Ada satu pemeriksaan JavaScript yang bisa dijalankan dari baris perintah (berbasis `assert`, tanpa framework) untuk fungsi murni thread.

**P3. Render per pesan (diffing)**
- **AC-7**: Given polling mengembalikan data yang sama, then tidak ada elemen bubble yang diganti; elemen `<img>` yang sudah termuat tetap node DOM yang sama.
- **AC-8**: Given polling mengembalikan pesan baru, then hanya pesan baru yang ditambahkan di akhir; bubble lain tidak disentuh.
- **AC-9**: Given sebuah pesan berubah (mis. `send_status` `sent` menjadi `failed`, kutipan terisi, state media berubah), then hanya bubble itu yang digambar ulang.
- **AC-10**: Given sebuah pesan yang berasal dari jendela terbaru tidak ada lagi di respons polling dan belum pernah dimuat lewat "Muat pesan lama", then bubble-nya dihapus. Pesan yang sudah dimuat lewat "Muat pesan lama" **tidak** dihapus oleh polling. Given kasir berganti percakapan, then thread dikosongkan (termasuk pesan lama yang dimuat) dan digambar dari awal dengan scroll ke bawah.
- **AC-11**: Auto-scroll tidak berubah: pesan baru menggulung ke bawah hanya bila posisi dalam 80px dari dasar atau saat `paksaScroll`.
- **AC-12**: Setelah kasir mengirim pesan (bubble langsung dari `tampilkanBubbleOutgoing()`), polling berikutnya **tidak menggandakan** bubble tersebut.
- **AC-13**: Aksi Balas dan Teruskan tetap bekerja pada pesan lama maupun baru (`pesanCached` konsisten dengan yang tampil).

**P4. Pemisah tanggal**
- **AC-14**: Given pesan berpindah hari (zona waktu browser, sama dengan `formatWaktuInbox`), then muncul pemisah dengan label "Hari ini", "Kemarin", atau tanggal lengkap. Pesan pertama selalu didahului pemisah.
- **AC-15**: Pesan baru pada hari yang sama tidak menambah pemisah; pesan baru di hari berikutnya menambah tepat satu.
- **AC-16**: Pemisah tidak ikut dihitung sebagai pesan (tidak masuk `pesanCached`, tidak punya aksi).

**P5. Kutipan bisa diklik**
- **AC-17**: Given kotak kutipan pada bubble dan pesan sumber ada di thread (dicocokkan lewat `quoted_wa_message_id` = `wa_message_id`), when diklik, then thread menggulung ke bubble sumber dan menyorotnya sebentar.
- **AC-18**: Given pesan sumber tidak ada di thread tetapi `has_more` bernilai `true`, when diklik, then thread memuat halaman lama berurutan sampai pesan sumber ditemukan (maksimum 5 halaman, ±1000 pesan), lalu menggulung dan menyorotnya. Given tidak ditemukan sampai batas itu atau riwayat habis, then tampil `showToast` "Pesan asal tidak ada di riwayat yang dimuat" dan tidak terjadi error.
- **AC-19**: Kotak kutipan dengan "Pesan tidak ditemukan" (`quoted_sender_label` kosong) tidak menjadi tombol yang menyesatkan; klik tidak melakukan apa pun.

**P6. Format teks WhatsApp**
- **AC-20**: `*teks*` menjadi tebal, `_teks_` miring, `~teks~` coret, ```` ```teks``` ```` monospace blok, `` `teks` `` monospace sebaris, mengikuti aturan penanda WhatsApp (penanda harus menempel pada teks dan tidak mencakup spasi di tepinya).
- **AC-21 (keamanan)**: Given teks mengandung `<script>`, `<img onerror=...>`, atau atribut HTML lain, then tampil sebagai teks biasa. Teks di-escape HTML **sebelum** format diterapkan.
- **AC-22**: Teks tanpa penanda tampil identik dengan sekarang; penanda yang tidak berpasangan dibiarkan apa adanya.

**P7. Tampilan (meniru `vue-advanced-chat`)**
- **AC-28**: Pemisah tanggal (P4) tampil sebagai kapsul di tengah thread.
- **AC-29**: Given kasir menggulung ke atas dan pesan baru masuk, then thread **tidak** diseret ke bawah dan muncul tombol bulat "gulung ke pesan terbaru" dengan jumlah pesan baru. Menekan tombol menggulung ke dasar dan menghilangkan tombol. Tombol hilang sendiri bila kasir menggulung ke dasar.
- **AC-30**: Pesan keluar dengan `send_status = sent` menampilkan satu centang di samping jam; `failed` menampilkan ikon "!" merah. Pesan lain tidak menampilkan penanda. Centang ganda atau biru tidak ada karena gateway tidak mengirim datanya.
- **AC-31**: Teks `http://` dan `https://` pada isi pesan teks dan caption menjadi tautan (`target="_blank"`, `rel="noopener noreferrer"`). Skema lain (`javascript:`, `data:`) tidak pernah menjadi tautan. Tautan dibuat **setelah** teks di-escape.

## 5. Batasan dan di luar cakupan

- **Batasan teknis**: tanpa dependency baru, tanpa build step JavaScript, tanpa perubahan skema database, dan tanpa perubahan kontrak gateway.
- **Tidak termasuk**:
  - Penggantian dengan library UI chat (`vue-advanced-chat` dkk.). Ditunda sampai gateway mengirim status delivered/read atau reaction.
  - Centang delivered/read (ganda atau biru), reaction, pemutar audio/video, typing indicator (datanya tidak dikirim gateway).
  - Pratinjau tautan (kartu), lightbox gambar.
  - Perubahan daftar percakapan (panel kiri), header, handoff, snooze, SLA.

## 6. Dampak aturan bisnis

- [x] Mengubah aturan bisnis (wajib dicatat di `docs/CHANGELOG.md`): batas riwayat thread berubah dari 500 pesan tertua menjadi 200 terbaru plus pagination
- [ ] Menyentuh data keuangan (`transaksi`, `pembayaran`, `tagihan`, kas)
- [ ] Mengubah skema database
- [ ] Mengubah kontrak POS <-> WA Gateway (perubahan lintas repositori)

Yang dicentang hanya perubahan batas riwayat (dicatat di `docs/CHANGELOG.md` saat P1 selesai). Tidak ada perubahan skema, kontrak gateway, atau data keuangan: perubahan di tampilan Inbox, satu query pembacaan, dan satu parameter opsional baru.

## 7. Risiko dan daftar regresi yang harus dicek tim

**Risiko P3 (diffing)** paling tinggi; hal yang mudah luput:
1. **Tanda tangan perubahan harus mencakup state sisi klien**, bukan hanya data dari server: `mediaGagal`, `mediaSementara` (termasuk jatah percobaan ulang `bolehCobaLagiMedia`), `pesanTerkirimTanpaKutipan`, dan `gatewayTerhubung`. Bila tidak, bubble tidak digambar ulang saat state itu berubah (mis. placeholder "Gateway terputus" tidak pernah hilang).
2. `onerror` pada `<img>` mengganti DOM sendiri (`tanganiMediaGagal`, `gantiMediaDenganPlaceholder`); diffing tidak boleh menimpa hasilnya secara keliru.
3. `tampilkanBubbleOutgoing()` harus memakai kunci id dan tanda tangan yang sama dengan jalur polling (AC-12), kalau tidak pesan terkirim tampil dua kali.
4. `pesanCached` saat ini direset tiap render; dengan diffing ia harus tetap memuat semua pesan yang tampil (AC-13).
5. Reset saat ganti percakapan (`pilihConversation`, baris 2080) harus mengosongkan semua state thread.

**Risiko P5**: pesan sumber di luar 200 terbaru adalah kasus normal; pemuatan bertahap dan pesan gagalnya (AC-18) harus jelas.

**Risiko pagination (P1b)**: (1) kursor hanya berdasarkan `id` salah bila gateway mengirim pesan terlambat dengan timestamp lebih lama, jadi kursor memakai `(message_timestamp, id)` (AC-24); (2) penggabungan jendela terbaru dengan halaman lama harus dedup berdasarkan `id` dan tidak boleh menghapus halaman lama (AC-10, AC-27); (3) posisi gulir harus dijaga saat pesan ditambahkan di atas (AC-27).

**Risiko P6**: format yang dijalankan sebelum escape membuka XSS; AC-21 wajib punya tes sendiri.

**Daftar regresi manual** (untuk reviu, mengikuti `docs/uji-inbox-tipe-pesan-nyata.md`): teks, gambar, sticker, dokumen+caption, audio/video placeholder, lokasi, kontak, `unsupported`, view-once, grup (label pengirim), catatan internal, Balas, Teruskan (teks dan media, tombol nonaktif untuk audio/video/lokasi/kontak), kutipan teks dan media (5 cabang), "Terkirim tanpa kutipan", media gagal/kadaluarsa/Gateway terputus.

## 8. Asumsi dan pertanyaan terbuka

- **Asumsi**: kasir memakai satu zona waktu (WIB) sehingga zona waktu browser sama dengan server; pemisah tanggal memakai konvensi `formatWaktuInbox`.
- **Asumsi**: format teks berlaku untuk isi pesan bertipe teks dan caption media; tidak untuk cuplikan di kotak kutipan.
- **Pertanyaan 1**: apakah mesin pengembang punya Node.js untuk menjalankan pemeriksaan JavaScript (AC-6)? Bila tidak, alternatifnya halaman uji di `/inbox/test`, atau tes manual terstruktur.
- **Pertanyaan 2 (terjawab)**: bertahap, tiap tahap disetujui ulang: Tahap 1 (P1 + P1b), Tahap 2 (P2 + P3), Tahap 3 (UI pagination, P4-P7).
- **Pertanyaan 3 (terjawab)**: ya, P1 dicatat di `docs/CHANGELOG.md`.

## 9. Persetujuan (Gate 1)

- [ ] Disetujui oleh: <nama>, tanggal: <YYYY-MM-DD>
