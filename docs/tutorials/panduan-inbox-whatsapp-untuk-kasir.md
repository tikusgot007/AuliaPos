# Panduan Inbox WhatsApp untuk Kasir — Tutorial

Tutorial ini memandu Anda, seorang kasir baru, menyelesaikan satu siklus kerja penuh di modul
Inbox WhatsApp AuliaPos: menerima percakapan, membalas, menunda, menutup, menyerahkan percakapan
ke kasir lain, dan menangani penolakan ketika dua kasir menyerahkan percakapan yang sama.

Ikuti langkahnya berurutan. Setiap langkah menyebutkan apa yang Anda klik dan apa yang berubah
di layar.

> [!NOTE]
> Ini adalah **tutorial** untuk pemakaian sehari-hari. Aturan teknis lengkapnya ada di
> `spec/spec-design-m3-operational-inbox-fase2a-handoff-collision.md` v1.2,
> `spec/spec-design-m3-operational-inbox-fase1.md`, dan `CONTEXT.md` (lihat bagian Rujukan).

## Apa yang akan Anda kuasai

Di akhir tutorial ini Anda bisa:

- membuka Inbox WhatsApp dan membaca lima tab daftar percakapan;
- mengambil percakapan baru lalu membalas pelanggan;
- menandai percakapan sudah dibaca dan menundanya dengan Follow-up;
- menutup percakapan yang sudah selesai;
- menyerahkan percakapan ke kasir lain beserta ringkasannya;
- menangani kasus ketika percakapan sudah diserahkan orang lain lebih dulu.

## Sebelum mulai

- Anda sudah login ke AuliaPos memakai akun dengan peran **kasir**.
- Anda melihat menu **Inbox WhatsApp** di sidebar kiri.
- Ada sedikitnya satu percakapan masuk untuk dilatih. Kalau belum ada, minta rekan Anda
  mengirim pesan WhatsApp ke nomor toko.

> [!TIP]
> Berlatihlah pada percakapan uji, bukan percakapan pelanggan sungguhan, sampai Anda merasa
> nyaman.

## Membuka Inbox WhatsApp

1. Di sidebar kiri, klik **Inbox WhatsApp**.
2. Perhatikan bahwa layar Inbox terbuka di **jendela browser terpisah**, sehingga Anda tetap
   bisa berpindah halaman tanpa kehilangan daftar percakapan.
3. Lihat angka merah pada menu **Inbox WhatsApp**. Angka itu adalah jumlah percakapan yang
   **Perlu Dibalas**, dan diperbarui otomatis setiap 20 detik.

Kalau menu itu tidak terlihat, hubungi admin — jendela Inbox hanya bisa dibuka oleh pengguna
yang sudah login.

## Mengenal layar dalam satu menit

Layar Inbox terbagi dua bagian.

**Panel kiri — daftar percakapan.** Di bagian atasnya ada lima tab, masing-masing dengan angka
jumlah percakapan:

| Tab | Isinya |
| --- | --- |
| Belum Diambil | Perlu dibalas dan belum dipegang siapa pun. |
| Open | Perlu dibalas dan sudah dipegang seorang kasir. |
| Menunggu | Sudah dibalas, menunggu balasan pelanggan. |
| Ditunda | Sedang ditunda lewat Follow-up. |
| Selesai | Sudah ditutup. |

**Panel kanan — percakapan terpilih.** Berisi judul percakapan dengan tombol aksinya, panel
**Riwayat Penyerahan**, isi percakapan, dan kotak balasan di bagian bawah.

Beberapa hal yang selalu terlihat di layar:

- **Indikator Gateway** di kanan atas: **Terhubung** (hijau) atau **Terputus** (abu). Selama
  Terputus, tombol kirim dinonaktifkan.
- **Label keadaan** pada tiap baris daftar: Perlu Dibalas (merah), Menunggu Customer (biru),
  Follow-up (kuning), Selesai (abu).
- **Label pemegang**: `Dipegang: <nama>` atau `Belum diambil`.
- Tombol **Chat Baru** di atas panel kiri, untuk memulai percakapan ke nomor yang belum pernah
  masuk.

> [!IMPORTANT]
> Layar Inbox menyegarkan dirinya sendiri: daftar percakapan tiap 6 detik, isi percakapan tiap
> 4 detik, dan status Gateway tiap 15 detik. Anda tidak perlu menekan tombol apa pun untuk
> melihat keadaan terbaru.

## Langkah 1 — Menerima percakapan baru

1. Klik tab **Belum Diambil**.
2. Klik salah satu percakapan pada daftar. Isi percakapan muncul di panel kanan.
3. Baca pesan terakhir pelanggan dari atas ke bawah.
4. Klik **Ambil** pada judul percakapan.

Apa yang berubah: label pemegang menjadi `Dipegang: <nama Anda>`, tombol **Ambil** berganti
menjadi **Lepas**, dan percakapan pindah dari tab Belum Diambil ke tab **Open**.

> [!NOTE]
> Kalau muncul pesan `Percakapan ini sudah diambil oleh <nama>.`, artinya rekan Anda lebih dulu
> mengambil percakapan itu. Pola penanganannya sama seperti Langkah 7.

### Melepas percakapan

Kalau Anda sudah mengambil percakapan tetapi belum sanggup menanganinya, klik **Lepas**.
Percakapan kembali "Belum diambil" dan boleh diambil siapa saja.

## Langkah 2 — Membalas pelanggan

1. Pastikan indikator Gateway menunjukkan **Terhubung**.
2. Pastikan percakapan sudah dipegang Anda (lihat Langkah 1).
3. Klik kotak balasan di bawah isi percakapan, lalu ketik balasan Anda.
4. Klik tombol kirim (ikon pesawat kertas).

Apa yang berubah: balasan tampil sebagai gelembung keluar di isi percakapan, label keadaan
menjadi **Menunggu Customer**, dan percakapan berpindah ke tab **Menunggu**.

> [!TIP]
> Untuk mengirim gambar atau berkas, klik ikon klip kertas di sebelah kotak balasan, pilih
> berkasnya, lalu klik kirim.

## Langkah 3 — Menandai percakapan sudah dibaca

Kadang pelanggan menulis hal yang tidak perlu dibalas, atau sudah Anda tangani di luar sistem.
Supaya percakapan keluar dari daftar "Perlu Dibalas":

1. Buka percakapan berlabel **Perlu Dibalas** yang sudah dipegang Anda.
2. Klik **Tandai Dibaca**.

Apa yang berubah: sistem mencatat waktu Anda membaca, label keadaan menjadi **Menunggu
Customer**, dan percakapan pindah ke tab **Menunggu**.

## Langkah 4 — Menunda dengan Follow-up

Pakai Follow-up bila Anda ingin percakapan muncul kembali nanti, bukan sekarang.

1. Buka percakapan yang sudah Anda pegang.
2. Klik **Follow-up**.
3. Pilih **1 jam**, **3 jam**, atau **Besok pagi**.

Apa yang berubah: label keadaan menjadi **Follow-up** dan percakapan pindah ke tab **Ditunda**.
Percakapan keluar dari tab Ditunda begitu waktunya tiba, dan juga begitu pelanggan mengirim
pesan baru.

Untuk membatalkan penundaan sebelum waktunya:

1. Buka percakapan yang sedang ditunda.
2. Klik **Follow-up**, lalu pilih **Batal**.

## Langkah 5 — Menutup percakapan

1. Buka percakapan yang sudah selesai Anda tangani.
2. Klik **Tutup**.

Apa yang berubah: percakapan pindah ke tab **Selesai** dan tombol **Tutup** hilang. Menutup
tidak menghapus apa pun — riwayat pesan dan pemegang percakapan tetap utuh.

> [!IMPORTANT]
> Percakapan yang sudah ditutup terbuka kembali secara otomatis bila pelanggan mengirim pesan
> baru — tidak ada tombol untuk membuka ulang secara manual.
>
> Tombol **Follow-up** dan **Tutup** hanya berhasil pada percakapan yang sedang Anda pegang.
> Kalau Anda menekannya pada percakapan milik kasir lain, server menolaknya. **Ambil** dulu
> percakapan itu.

## Langkah 6 — Menyerahkan percakapan (Handoff)

Handoff memindahkan tanggung jawab sebuah percakapan ke kasir lain, disertai ringkasan tertulis.

1. Buka percakapan yang sedang Anda pegang. Pastikan tabnya **bukan** Selesai.
2. Klik **Handoff** pada judul percakapan.
3. Pada dialog **Serahkan Percakapan**, lengkapi:
   1. **Serahkan kepada (kasir aktif)** — pilih nama kasir penerima; daftar ini hanya memuat
      kasir aktif.
   2. **Ringkasan Keadaan Percakapan (wajib)** — ini *Handoff Summary*: apa yang sudah terjadi.
   3. **Tindakan Lanjutan yang Diharapkan (wajib)** — ini *Next Action*: apa yang harus
      dilakukan penerima.
   4. **Catatan (opsional)** — ini *Handoff Note*: catatan antar staff; isinya tidak pernah
      terkirim ke pelanggan.
4. Klik **Serahkan**.

Apa yang berubah:

- Percakapan berpindah ke penerima, dan daftar kiri menampilkan `Dipegang: <nama penerima>`.
- Panel **Riwayat Penyerahan** muncul atau memperbarui entri teratas dengan bentuk
  `<pengirim> → <penerima> oleh <yang menyerahkan>, <waktu>`, lalu ringkasan,
  `Tindakan lanjutan: ...`, dan `Catatan: ...` bila Anda mengisinya.
- Pelanggan tidak menerima pesan apa pun, dan penundaan percakapan tidak berubah.

> [!TIP]
> Tulis ringkasan seolah Anda menyerahkan shift kepada rekan yang belum melihat percakapan ini:
> apa masalahnya, apa yang sudah dilakukan, dan apa langkah berikutnya.

### Siapa yang boleh menyerahkan

- **Pemegang percakapan saat ini.** Tombol **Handoff** hanya muncul untuknya.
- **Kasir aktif, pada tab Belum Diambil.** Percakapan yang belum diambil boleh langsung
  diserahkan tanpa mengambilnya lebih dulu. Administrator yang bukan pemegang percakapan
  **tidak** bisa menyerahkan pada tab ini.

Kalau penerima dinonaktifkan setelah dialog dibuka, server menolak dan percakapan tidak
berpindah — pilih kasir lain.

## Langkah 7 — Ketika percakapan sudah diserahkan orang lain

Dua kasir bisa membuka dialog Handoff untuk percakapan yang sama. Percakapan hanya berpindah
sekali: permintaan yang kalah ditolak, dan pemilik yang sah tidak pernah tertimpa.

Ketika Anda yang kalah:

1. Dialog menampilkan kotak merah: `Percakapan ini sudah ditangani oleh <nama>.`
2. Klik **Muat ulang**. Dialog tertutup dan daftar percakapan disegarkan dari server.
3. Koordinasikan langsung dengan pemilik baru.

Jangan mengirim ulang formulir yang sama — percakapan itu sudah bukan milik Anda.

Pesan penolakan lain yang mungkin Anda temui:

| Pesan yang muncul | Artinya | Yang Anda lakukan |
| --- | --- | --- |
| `Percakapan ini sudah ditangani oleh <nama>.` | Percakapan sudah berpindah ke orang lain. | Klik **Muat ulang**, lalu koordinasi dengan pemilik baru. |
| `Hanya staff yang sedang menangani percakapan ini yang bisa menyerahkannya.` | Anda bukan pemegang percakapan. | Ambil dulu percakapan itu. |
| `Hanya kasir aktif yang bisa menyerahkan percakapan yang belum diambil.` | Anda bukan kasir aktif, sedangkan percakapannya ada di tab Belum Diambil. | Minta kasir aktif menyerahkannya. |
| `Percakapan tanpa pemilik hanya bisa diserahkan dari tab Belum Diambil. Ambil dulu percakapan ini.` | Percakapan tanpa pemilik, tetapi tidak ada di tab Belum Diambil. | **Ambil** dulu percakapan itu, lalu serahkan. |
| `Target Handoff harus kasir aktif.` | Penerima bukan kasir aktif. | Pilih kasir lain dari daftar. |
| `Percakapan sudah selesai dan tidak bisa diserahkan.` | Percakapan sudah ditutup. | Tidak ada penyerahan untuk percakapan selesai. |
| `Ringkasan Handoff (summary) wajib diisi.` | Ringkasan kosong. | Isi ringkasan, lalu kirim lagi. |
| `Tindakan berikutnya (next_action) wajib diisi.` | Tindakan lanjutan kosong. | Isi tindakan lanjutan, lalu kirim lagi. |
| `Tidak bisa menyerahkan percakapan ke diri sendiri.` | Penerima sama dengan Anda. | Pilih kasir lain. |
| `Ringkasan, tindakan berikutnya, dan catatan maksimal 4096 karakter.` | Ada isian yang melebihi 4096 karakter. | Pendekkan isian, lalu kirim lagi. |
| `Gagal menyimpan riwayat Handoff, percakapan tidak berpindah.` | Sistem gagal mencatat riwayat; percakapan tetap pada pemilik semula. | Coba serahkan sekali lagi. Kalau tetap gagal, laporkan ke admin. |

> [!IMPORTANT]
> Pesan `Percakapan ini sudah berpindah, silakan muat ulang daftar.` muncul bila percakapan
> menjadi tanpa pemilik saat Anda mengirim. Klik **Muat ulang**, lalu periksa keadaan
> terbaru sebelum mengulang.

## Latihan mandiri

Lakukan berurutan pada percakapan uji:

- [ ] Buka tab **Belum Diambil** dan ambil satu percakapan.
- [ ] Balas percakapan itu, lalu pastikan ia pindah ke tab **Menunggu**.
- [ ] Buka percakapan lain, lalu klik **Tandai Dibaca**.
- [ ] Tunda satu percakapan dengan **Follow-up → 1 jam**, lalu batalkan dengan **Batal**.
- [ ] Tutup satu percakapan dengan **Tutup**, lalu temukan di tab **Selesai**.
- [ ] Serahkan satu percakapan ke rekan Anda lengkap dengan ringkasan dan tindakan lanjutan.
- [ ] Buka percakapan yang baru Anda serahkan dan baca entri di **Riwayat Penyerahan**.

## Yang tidak dilakukan otomatis oleh sistem

- Penerima Handoff **tidak menerima notifikasi**. Ia menemukan percakapan lewat tab bersama
  dan panel Riwayat Penyerahan.
- Tidak ada pembatalan penyerahan setelah berhasil. Kalau salah serah, minta penerima
  menyerahkannya kembali.
- Tidak ada tombol untuk membuka ulang percakapan yang sudah ditutup; hanya pesan baru dari
  pelanggan yang membukanya.
- Tidak ada indikator "sedang dibuka siapa" pada tahap ini.

## Rujukan

- `spec/spec-design-m3-operational-inbox-fase2a-handoff-collision.md` v1.2 — kontrak Handoff dan
  penanganan bentrok.
- `spec/spec-design-m3-operational-inbox-fase1.md` — Queue View, Snooze, Selesai, dan Internal
  Note.
- `CONTEXT.md` — istilah baku: Handoff, Handoff Summary, Next Action, Handoff Note, Collision
  Detection, Belum Diambil, Tanpa Pemilik.
- `plan/plan-feature-m3-operational-inbox-fase2a-v1.0.md` — rencana implementasi.
