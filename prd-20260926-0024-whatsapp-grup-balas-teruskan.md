# PRD: Inbox WhatsApp — Grup, Balas Pesan, Teruskan — AuliaPos

## 1. Product overview

### 1.1 Document title and version

- PRD: Inbox WhatsApp — Grup, Balas Pesan, Teruskan — AuliaPos
- Version: 1.0

**Hubungan dengan dokumen lain:**

| Dokumen | Hubungan |
| --- | --- |
| `docs/discovery-draft-20260925-2354-whatsapp-grup-balas-teruskan.md` | Sumber utama PRD ini (Phase 0, sudah disetujui pemilik proyek) |
| `prd-20260922-0141-chat-whatsapp-inbox.md` (v1.4) | PRD **terpisah** untuk M1 dan M3 Fase 1/2a. Dokumen itu **tidak diubah** oleh PRD ini |
| `CONTEXT.md` | Glosarium istilah kanonik: **Grup**, **Balas Pesan**, **Teruskan** |

**Mengapa PRD ini berdiri sendiri, bukan amandemen PRD lama.** PRD lama bersifat retroaktif — ia menjelaskan pekerjaan yang sudah dibangun (M1, M3 Fase 1a–1e, Fase 2a), dan menutup diri dengan Non-goal eksplisit di Section 2.3: *"Perubahan apa pun pada logika WhatsApp Gateway sebagai bagian dari M3 (M3 murni sisi AuliaPos/CI4)"*. Pekerjaan dalam PRD ini **wajib mengubah WA-Gateway** (lihat Section 8.1), sehingga menumpangkannya ke dokumen itu berarti mencabut Non-goal tersebut dan membuka kembali dokumen yang statusnya sudah selesai. PRD ini karena itu menjadi milestone tersendiri dengan riwayat versinya sendiri.

**Penomoran user story.** ID user story di PRD ini dimulai dari **GH-011**, melanjutkan penomoran PRD lama (GH-001 s.d. GH-010). Tujuannya menjaga keunikan ID di seluruh repo, karena audit keterlacakan merujuk ID cerita, bukan nama berkas.

### 1.2 Product summary

Modul Inbox AuliaPos dibangun di atas satu asumsi yang tidak pernah ditulis eksplisit: **satu percakapan = satu pelanggan = satu identitas**. Asumsi itu menembus lapisan penyimpanan, kepemilikan, profil pelanggan, sampai penautan ke data pelanggan POS.

Grup WhatsApp melanggar asumsi tersebut, dan pelanggarannya **sudah berjalan di produksi** — bukan skenario hipotetis. Per 2026-09-25 tercatat 4 JID grup (`@g.us`) dan sekitar 36 pesan grup tersimpan sukses di `aulia_inboxdb`. Gejala yang dilaporkan pemilik proyek: *"dianggap 1 percakapan, namanya berubah-ubah tergantung yang terakhir ngirim; jadi di halaman pesannya, seperti pesan dari 1 orang."* Ketiga gejala itu sudah di-*root cause* ke satu titik di repo WA-Gateway (draft §3.2, R-01/R-02/R-03), sehingga **fitur grup bukan sekadar penambahan fitur baru — ia perbaikan atas kebocoran model data yang sudah terjadi.**

PRD ini mencakup **tiga fitur** yang saling melengkapi, dikerjakan berurutan (**Grup** lebih dulu, baru **Balas Pesan**, lalu **Teruskan**):

1. **Grup** — percakapan grup ditampilkan sebagai grup yang jelas: punya tab sendiri, tidak mengotori antrean kerja kasir, dan setiap pesan menampilkan pengirimnya.
2. **Balas Pesan** — kasir membalas satu pesan tertentu dengan kutipan, di chat pribadi maupun di grup.
3. **Teruskan** — kasir mengirimkan kembali sebuah pesan ke percakapan lain yang sudah ada, dengan penanda "Diteruskan".

> [!IMPORTANT]
> **Constraint: pekerjaan ini menyentuh DUA repo, dan dirilis bertahap.**
> Sebagian perbaikan grup cukup dikerjakan di AuliaPos (pemisahan tampilan, perbaikan badge antrean). Sebagian lain **wajib** mengubah WA-Gateway (label pengirim per pesan, nama grup sebagai judul percakapan), karena data itu saat ini belum pernah dikirim Gateway sama sekali.
> Rilis **Grup** karena itu dibagi dua tahap:
>
> - **Tahap 1 (AuliaPos saja, bisa dirilis lebih dulu):** tab **Grup**, penandaan grup, penonaktifan aksi yang tidak berlaku untuk grup, dan perbaikan badge `perlu_dibalas` yang saat ini terinflasi oleh grup.
> - **Tahap 2 (menunggu WA-Gateway):** label pengirim per pesan di dalam grup, dan nama grup sebagai judul percakapan.
>
> **Yang harus dipahami sejak awal:** pada Tahap 1, **nama grup belum bisa ditampilkan dengan benar** — judulnya masih memakai nama yang tersimpan dari pengirim terakhir, dan masih bisa berubah. Perbaikan itu baru datang di Tahap 2. Keputusan ini diambil sadar demi mendapat manfaat Tahap 1 lebih cepat, bukan karena keterbatasan yang terlewat.

## 2. Goals

### 2.1 Business goals

- **Semua percakapan pelanggan ditangani dari satu layar AuliaPos.** Kasir tidak perlu lagi berpindah ke aplikasi WhatsApp di HP untuk melayani grup, sehingga tidak ada lagi riwayat jawaban yang hilang di luar sistem.
- **Antrean kerja kasir kembali bisa dipercaya.** Hari ini setiap grup yang belum ditutup ikut terhitung di badge `perlu_dibalas` dan muncul di tab Belum Diambil, padahal grup tidak punya konsep "dibalas oleh pelanggan". Setelah pekerjaan ini, angka antrean kembali mencerminkan percakapan yang benar-benar menunggu kasir.
- **Jawaban kasir tidak lagi salah sasaran.** Dengan **Balas Pesan** dan **Teruskan**, kasir bisa menunjuk pesan tertentu dan memindahkan konteks antar percakapan, sehingga pelanggan menerima jawaban yang jelas merujuk pada apa.
- **Kebocoran model data ditutup, bukan ditambal.** Grup diperlakukan sebagai jenis percakapan tersendiri yang dinyatakan secara eksplisit, bukan sebagai percakapan pribadi yang kebetulan aneh.

### 2.2 User goals

- Kasir bisa melihat percakapan grup di tempatnya sendiri, terpisah dari antrean percakapan pelanggan perorangan.
- Kasir bisa langsung tahu bahwa sebuah baris percakapan adalah **Grup**, bukan pelanggan perorangan — tanpa harus membukanya lebih dulu.
- Kasir bisa mengetahui **siapa** yang menulis tiap pesan di dalam grup, bukan melihat semua pesan seolah dari satu orang.
- Kasir bisa mengetahui **nama grup** yang sebenarnya sebagai judul percakapan, dan judul itu tidak berubah-ubah mengikuti orang yang terakhir menulis.
- Kasir bisa melihat dengan jelas aksi mana yang **tidak berlaku** untuk grup (Ambil, Lepas, Tutup, Snooze, Konfirmasi Nomor, Edit Profil), supaya tidak salah klik.
- Kasir bisa membalas satu pesan tertentu dengan kutipan, sehingga pelanggan tahu persis pesan mana yang dijawab.
- Kasir bisa meneruskan sebuah pesan (termasuk lampiran yang bisa diteruskan) ke percakapan lain yang sudah ada, dengan penanda yang jelas bahwa itu pesan teruskan.
- Kasir diberi tahu dengan jelas ketika sebuah aksi tidak bisa dilakukan (mis. Gateway menolak kutipan, atau lampiran sudah tidak tersedia), beserta pilihan yang masih bisa diambil.

### 2.3 Non-goals (Out of Scope)

> [!NOTE]
> **Constraint, bukan Non-goal — empat dimensi independen (`CHAT.md` §18).**
> Identitas, siklus hidup, kepemilikan, dan baca-belum-dibaca adalah empat dimensi terpisah. Pekerjaan **Grup** dalam PRD ini hanya menyentuh dimensi **identitas**: grup dikenali sebagai jenis percakapan tersendiri.
> Keputusan "grup tanpa kepemilikan" adalah pernyataan bahwa grup **tidak ikut** aturan dimensi kepemilikan — bukan perubahan pada cara kerja kepemilikan itu sendiri. PRD ini **tidak boleh** dipakai untuk menyentuh mekanisme `assigned_to`, Handoff, atau Collision Detection. Bila suatu saat grup perlu punya pemilik, itu kebutuhan baru yang memerlukan PRD sendiri.

Fitur dan perilaku yang **sengaja tidak** termasuk dalam lingkup PRD ini:

- **Daftar anggota grup** (siapa saja yang ada di grup) — tidak ditampilkan. Keputusan draft §5.1 butir 1.
- **Keluar dari grup, menambahkan/mengeluarkan anggota, membuat grup dari POS** — grup dikelola dari WhatsApp, bukan dari AuliaPos. Keputusan draft §5.1.
- **Menautkan grup ke data pelanggan POS** — grup tidak bisa dipetakan ke satu baris pelanggan, dan penautan ke data POS adalah lingkup M4 (Customer Context).
- **Meneruskan audio dan video** — secara desain, binary audio/video **tidak pernah diambil** (`CHAT.md` §6.2), sehingga tidak ada berkas yang bisa diteruskan. Ini batasan final, bukan bug yang menunggu diperbaiki.
- **Memulihkan label pengirim pada pesan grup lama** — pesan grup yang sudah tersimpan sebelum Tahap 2 tidak menyimpan identitas pengirimnya dan tidak bisa dipulihkan. Keputusan draft §5.1 butir 7: data grup lama dibiarkan apa adanya.
- **Memperbaiki nama grup lama secara retroaktif** — nama grup lama akan terisi benar dengan sendirinya saat pesan berikutnya masuk setelah Tahap 2 selesai; tidak ada perbaikan mundur.
- **Membuat percakapan baru dari aksi Teruskan** — tujuan Teruskan hanya percakapan yang sudah ada. Keputusan draft §5.1 butir 5.
- **Presence / "sedang dibuka oleh siapa" antar staff** — tetap ditunda, prasyaratnya sama dengan yang tercatat di PRD lama.
- **Notifikasi antar staff dan penanda belum dibaca (unread) per pengguna** — tetap ditunda dengan prasyarat yang sama seperti PRD lama.
- **Perubahan pada dimensi kepemilikan, siklus hidup, dan baca-belum-dibaca** — di luar lingkup, lihat catatan constraint di atas.
- **Pencarian yang mencakup isi grup secara khusus, filter grup lanjutan, atau ekspor riwayat grup** — tidak diminta dan tidak direncanakan.

## 3. User personas

### 3.1 Key user types

- Kasir (pengguna utama — orang yang membalas chat pelanggan sehari-hari)
- Admin/Owner (pengguna sekunder — ikut menangani percakapan, tanpa hak khusus untuk modul Inbox)

### 3.2 Basic persona details

- **Kasir**: staff yang membalas chat pelanggan soal pesanan, pembayaran, dan status cetakan, di sela tugas kasir di POS. Bekerja cepat, sering berpindah antar percakapan, dan memakai HP untuk WhatsApp hanya karena AuliaPos belum bisa menampilkan grup dengan layak. **Yang paling ia butuhkan dari pekerjaan ini:** tidak perlu keluar dari AuliaPos, dan tidak salah membaca mana grup mana pelanggan perorangan.
- **Admin/Owner**: mengawasi jalannya toko dan ikut menangani percakapan. **Yang paling ia butuhkan:** angka antrean di sidebar bisa dipercaya untuk menilai beban kerja kasir.

### 3.3 Role-based access

- **Kasir**: seluruh aksi **Balas Pesan** dan **Teruskan** tersedia di percakapan yang menjadi tanggung jawabnya, dengan aturan kepemilikan yang sama seperti balas biasa (`cekOwnership()`). Melihat tab **Grup** tidak dibatasi kepemilikan.
- **Admin**: akses sama seperti kasir untuk modul Inbox; belum ada hak admin-khusus dalam lingkup PRD ini.
- **Grup — aksi yang tidak berlaku bagi siapa pun (termasuk Admin):** Ambil, Lepas, Tutup, Snooze, Konfirmasi Nomor, dan Edit Profil. Grup tidak punya kepemilikan dan tidak punya siklus hidup, sehingga aksi-aksi itu tidak punya makna di sana. Keputusan draft §5.1 butir 2 dan 3.
- **Grup — aksi yang tetap berlaku:** membaca percakapan, **Balas Pesan**, **Teruskan**, dan Internal Note antar staff.

## 4. Functional requirements

- **Tab Grup** (Priority: Must-have — Grup Tahap 1, AuliaPos saja)
  - Ada satu tab baru bernama **Grup** di deretan tab daftar percakapan, sejajar dengan tab status yang sudah ada (Belum Diambil, Open, Menunggu, Ditunda, Selesai).
  - Tab Grup menampilkan seluruh percakapan bergrup. Grup **tidak** muncul di tab status mana pun, termasuk Belum Diambil.
  - Angka badge `perlu_dibalas` di sidebar **tidak** menghitung percakapan grup.
  - Grup mengikuti mekanisme pemuatan ulang (polling) dan pencarian yang sudah berlaku umum — tidak ada mekanisme khusus untuk grup.
  - Filter status yang ada tidak berubah perilakunya untuk percakapan pribadi.

- **Penandaan Grup** (Priority: Must-have — Grup Tahap 1, AuliaPos saja)
  - Setiap baris percakapan grup diberi penanda yang menyatakan bahwa itu **Grup**, sehingga kasir tahu sebelum membukanya.
  - Header percakapan juga menyatakan bahwa percakapan yang sedang dibuka adalah **Grup**.
  - Penanda harus tetap terbaca meski nama percakapan sedang tidak benar (lihat Tahap 2), sehingga kasir tidak pernah bergantung pada nama untuk membedakan grup dari pelanggan perorangan.

- **Aksi yang tidak berlaku untuk grup** (Priority: Must-have — Grup Tahap 1, AuliaPos saja)
  - Ambil, Lepas, Tutup, dan Snooze **tidak tersedia** pada percakapan grup.
  - Konfirmasi Nomor dan Edit Profil **dinonaktifkan** pada percakapan grup.
  - Aksi yang tetap tersedia pada grup: membaca, mengirim pesan biasa, **Balas Pesan**, **Teruskan**, dan Internal Note.
  - Aksi yang tidak berlaku tidak boleh tampil sebagai tombol yang selalu gagal — kasir tidak boleh diberi tombol yang pasti berujung error.

- **Label pengirim di dalam grup** (Priority: Must-have — Grup Tahap 2, wajib ubah WA-Gateway)
  - Setiap pesan grup menampilkan nama pengirimnya, sehingga percakapan grup tidak lagi terbaca sebagai pesan dari satu orang.
  - Label pengirim bertahan setelah percakapan dimuat ulang, termasuk saat kasir berpindah percakapan lalu kembali.
  - Pesan yang dikirim kasir dari AuliaPos ditandai sebagai berasal dari staff yang mengirim.
  - Pesan grup yang sudah tersimpan sebelum Tahap 2 tidak menampilkan label pengirim. Ini keterbatasan yang diterima, bukan cacat yang harus ditutup (Non-goal Section 2.3).

- **Nama grup sebagai judul percakapan** (Priority: Must-have — Grup Tahap 2, wajib ubah WA-Gateway)
  - Judul percakapan grup memakai **nama grup yang sebenarnya** dari WhatsApp.
  - Judul grup **tidak berubah** ketika ada pesan masuk dari pengirim yang berbeda — inilah gejala "namanya berubah-ubah" yang dilaporkan pemilik proyek.
  - Bila nama grup belum bisa diperoleh, judul menampilkan penanda Grup tanpa nama orang. Nama pengirim terakhir **tidak boleh** dipakai sebagai judul percakapan grup.
  - Nama grup yang sudah benar tidak boleh ditimpa oleh pesan berikutnya.

- **Balas Pesan** (Priority: Must-have — tahap setelah Grup selesai)
  - Kasir dapat menunjuk satu pesan tertentu, lalu membalasnya sehingga pesan yang ditunjuk ikut tampil sebagai kutipan.
  - Berlaku di percakapan pribadi **dan** di grup.
  - Berlaku untuk pesan teks **dan** pesan media.
  - Kutipan yang terkirim ke pelanggan adalah **kutipan WhatsApp asli**, bukan teks yang disalin ulang secara manual.
  - Kutipan tetap terlihat oleh kasir setelah percakapan dimuat ulang, termasuk setelah kasir berpindah percakapan dan kembali.
  - Di grup, kutipan menampilkan nama pengirim yang dikutip, supaya jelas siapa yang sedang dijawab.
  - Kasir dapat membatalkan pilihan kutipan sebelum pesan dikirim.
  - Bila kutipan ditolak oleh Gateway, kasir **diberi tahu dengan jelas** dan ditawarkan pilihan mengirim tanpa kutipan. Pesan **tidak boleh** terkirim tanpa sepengetahuan kasir, dan tidak boleh gagal senyap.

- **Teruskan** (Priority: Must-have — tahap terakhir)
  - Kasir dapat meneruskan sebuah pesan ke percakapan lain.
  - Tujuan Teruskan hanya percakapan yang **sudah ada** — baik percakapan pribadi maupun grup. Aksi ini tidak membuat percakapan baru.
  - Pesan yang diteruskan diberi penanda bahwa itu pesan **Teruskan**, sehingga penerima di sisi AuliaPos tidak salah mengira itu pesan asli pengirimnya.
  - Meneruskan pesan teks selalu tersedia.
  - Meneruskan lampiran (gambar, dokumen, stiker) tersedia selama berkasnya masih ada.
  - **Audio dan video tidak dapat diteruskan.** Kasir diberi tahu alasan yang bisa dipahami, bukan pesan error teknis.
  - Bila berkas lampiran sudah tidak tersedia saat diteruskan, pengiriman **dibatalkan seluruhnya** dan kasir diberi tahu. Pengiriman setengah jadi (teks terkirim, lampiran hilang) tidak boleh terjadi.

> [!NOTE]
> **Empat hal di Section 4 yang perlu diinterogasi di checkpoint klarifikasi.**
> Keempatnya adalah kesimpulan yang saya ambil saat menulis PRD ini, bukan keputusan yang sudah dikunci di draft discovery. Saya catat terbuka supaya tidak berubah menjadi asumsi diam-diam:
>
> 1. **Snooze termasuk aksi yang tidak berlaku untuk grup** — draft hanya menyebut Tutup/Lepas secara eksplisit.
> 2. **Balas Pesan dan Teruskan mengikuti aturan kepemilikan yang sudah ada** (`cekOwnership()`) — draft tidak membahas batas aksesnya.
> 3. **Penanda "Teruskan" ditampilkan di sisi AuliaPos** — draft tidak menyatakan apakah penanda ini juga harus menyertai pesan di sisi WhatsApp penerima.
> 4. **Perilaku cadangan judul grup saat nama grup belum tersedia** (menampilkan penanda Grup tanpa nama) — draft hanya menyatakan nama grup tidak bisa ditampilkan sebelum Gateway siap, tanpa menentukan apa yang ditampilkan sebagai gantinya.

## 5. User experience

### 5.1 Entry points & first-time user flow

- Tidak ada layar, menu, atau langkah pengaturan baru. Kasir membuka Inbox seperti biasa, dan tab **Grup** sudah ada di deretan tab.
- Tidak ada masa transisi atau migrasi data yang perlu dilakukan kasir. Percakapan grup yang sudah tersimpan akan muncul di tab Grup dengan sendirinya, karena pengenalan grup memakai penanda yang sudah tersimpan sejak awal (`jid_type`).
- Tidak ada pelatihan khusus yang dibutuhkan: yang berubah adalah **penambahan satu tab** dan **hilangnya tombol yang tidak berlaku** di percakapan grup.

### 5.2 Core experience

- **Mengenali grup**: Kasir membuka Inbox. Di deretan tab ada tab **Grup** dengan jumlah percakapan grup di dalamnya. Angka antrean di sidebar hanya berisi percakapan pelanggan, sehingga kasir bisa mempercayainya lagi.
- **Membaca percakapan grup**: Kasir membuka percakapan grup. Header menyatakan bahwa ini Grup. Setiap pesan menampilkan pengirimnya (Tahap 2), dan judul percakapan menampilkan nama grup yang sebenarnya (Tahap 2).
- **Menangani grup**: Kasir membalas seperti biasa, dan bila perlu menulis Internal Note untuk staff lain. Tombol Ambil, Lepas, Tutup, Snooze, Konfirmasi Nomor, dan Edit Profil tidak ada di layar grup — kasir tidak perlu menebak mana yang berlaku.
- **Membalas satu pesan**: Kasir menunjuk sebuah pesan, memilih untuk membalasnya, dan pesan itu tampil sebagai kutipan di kotak tulis. Kasir bisa membatalkan pilihan itu sebelum mengirim. Kutipan tetap terlihat setelah percakapan dimuat ulang, sehingga kasir tidak kehilangan konteks saat sedang mengetik.
- **Meneruskan pesan**: Kasir menunjuk sebuah pesan, memilih Teruskan, lalu memilih percakapan tujuan dari daftar percakapan yang sudah ada. Pesan terkirim dengan penanda Diteruskan. Bila pesan itu audio atau video, pilihan Teruskan tidak tersedia beserta alasannya.

### 5.3 UI/UX highlights & Edge cases

- **Grup tanpa nama (Tahap 1)**: selama Tahap 2 belum selesai, judul percakapan grup belum benar dan masih bisa berubah. Karena itu penanda **Grup** harus berdiri sendiri — kasir tidak boleh bergantung pada nama untuk mengenali grup.
- **Pesan grup lama**: pesan yang sudah tersimpan sebelum Tahap 2 tampil tanpa nama pengirim. Kasir tidak boleh melihat label kosong, tanda tanya, atau teks pengganti yang membingungkan.
- **Kutipan media yang sudah tidak tersedia**: pesan media yang dikutip bisa saja berkasnya sudah tidak ada. Kutipan tetap harus tampil sebagai rujukan tanpa membuat thread gagal dimuat.
- **Gateway menolak kutipan**: kasir melihat penjelasan yang jelas dan pilihan mengirim tanpa kutipan. Tidak ada pengiriman diam-diam tanpa kutipan.
- **Teruskan audio/video**: pilihan Teruskan tidak ditawarkan, dengan alasan yang bisa dipahami ("audio/video tidak dapat diteruskan"), bukan pesan teknis.
- **Teruskan lampiran yang sudah hilang**: pengiriman dibatalkan seluruhnya, kasir diberi tahu, dan tidak ada pesan setengah terkirim yang tersisa di percakapan.
- **Meneruskan ke grup**: diperlakukan sama seperti meneruskan ke percakapan pribadi. Tidak ada aturan tambahan.
- **Dua kasir menyentuh percakapan grup bersamaan**: bukan masalah baru, karena grup tidak punya kepemilikan. Aturan yang berlaku tetap aturan kirim pesan yang sudah ada.
- **Menutup atau menunda percakapan grup**: tidak mungkin dilakukan dari AuliaPos, dan grup **tidak boleh** dikecualikan dari aturan mana pun yang sudah berlaku untuk pengiriman pesan (idempotensi kirim, riwayat, soft delete).

## 6. Narrative

Seorang kasir membuka AuliaPos di pagi hari. Deretan tab di Inbox kini punya satu tab tambahan: **Grup**. Angka antrean di sidebar sudah bisa ia percayai lagi — sejak grup dipisahkan, angka itu hanya berisi percakapan pelanggan yang benar-benar menunggu balasan.

Di dalam tab Grup, ia membuka grup reseller. Setiap pesan kini menunjukkan siapa yang menulis, dan judul percakapannya menampilkan nama grup yang sebenarnya — tidak lagi berubah-ubah mengikuti orang yang terakhir mengirim. Ia membalas pertanyaan salah satu anggota dengan menunjuk pesan itu, sehingga kutipannya ikut terkirim dan semua anggota tahu persis pesan mana yang dijawab. Ketika ada anggota menanyakan harga yang sudah dijawab di percakapan pelanggan lain, ia meneruskan pesan jawaban itu ke sini, lengkap dengan penanda Diteruskan. Untuk pertanyaan yang perlu konfirmasi owner, ia menulis Internal Note — tidak terkirim ke grup, tapi terbaca kasir shift berikutnya.

Selama bekerja, ia tidak sekali pun berpindah ke aplikasi WhatsApp di HP-nya. Seluruh percakapan pelanggan, pribadi maupun grup, tertangani dari satu layar — dan riwayat jawabannya tersimpan di sistem, bukan di HP pribadinya.

## 7. Success metrics

### 7.1 User-centric metrics

- Kasir dapat mengenali sebuah percakapan sebagai **Grup** tanpa membukanya — diperiksa pada daftar: 100% baris percakapan grup membawa penanda Grup.
- Kasir dapat menyebutkan **siapa pengirim** tiap pesan di sebuah grup tanpa bertanya ke staff lain (setelah Tahap 2).
- Judul percakapan grup **tidak berubah** ketika anggota yang berbeda bergantian mengirim pesan (setelah Tahap 2) — inilah gejala yang dilaporkan pemilik proyek.
- **Balas Pesan** dan **Teruskan** benar-benar dipakai pada percakapan nyata dalam dua minggu pertama setelah rilis — bukan sekadar tersedia. Fitur yang tersedia tapi tidak dipakai berarti kebutuhannya salah dibaca.
- Tidak ada lagi jawaban ke grup yang dikirim kasir dari aplikasi WhatsApp di HP pribadinya.

### 7.2 Business metrics

- Angka badge `perlu_dibalas` di sidebar **akurat**: percakapan grup tidak lagi ikut terhitung (target: 0 grup terhitung).
- Tidak ada lagi riwayat jawaban percakapan grup yang berada di luar AuliaPos, sehingga koordinasi antar shift tidak lagi bergantung pada HP pribadi kasir.
- Keluhan "grup dianggap satu percakapan / namanya berubah-ubah / seperti pesan dari satu orang" tidak muncul lagi setelah Tahap 2.

### 7.3 Technical metrics

- Seluruh test AuliaPos lolos sebelum setiap tahap dinyatakan selesai (target: 0 gagal, 0 test dilewati).
  - **Sinyal yang dipakai:** `vendor/bin/phpunit --no-coverage` keluar dengan kode 0.
  - **Catatan:** perintah `composer test` **tidak** dipakai sebagai sinyal, karena perintah itu gagal dengan kode keluar 1 untuk alasan yang sudah ada sebelumnya dan tidak berkaitan dengan pekerjaan ini (konfigurasi meminta laporan coverage sementara driver coverage tidak terpasang).
- Tidak ada pesan hilang dan tidak ada pesan ganda pada pengiriman hasil **Balas Pesan** maupun **Teruskan** — memakai mekanisme idempotensi `operation_id` yang sudah berlaku, bukan mekanisme baru.
- Setelah Tahap 2: 100% pesan grup yang baru masuk memiliki identitas pengirim; tidak ada lagi pesan grup yang tersimpan tanpa pengirim.
- Tidak ada kemunduran kecepatan pada daftar percakapan dan pada pemuatan ulang thread percakapan dibandingkan kondisi sebelum perubahan.

## 8. Technical considerations (Input for Engineering Team)

### 8.1 Integration points

> [!IMPORTANT]
> **Pekerjaan ini menyentuh DUA repo, sehingga memerlukan dua plan terpisah.** Ini bukan preferensi gaya, melainkan konsekuensi batas repo: data yang dibutuhkan Tahap 2 dan seterusnya belum pernah dikirim oleh WA-Gateway sama sekali.

- **WA-Gateway** (Node.js/Baileys, repo terpisah `tikusgot007/WA-Gateway`) — jembatan WhatsApp ↔ AuliaPos, **bukan** sumber kebenaran data. Perubahan kontrak yang dibutuhkan:
  - Pesan masuk grup menyertakan **identitas pengirim** tiap pesan (saat ini tidak pernah dibaca, sehingga pengirim tergantikan oleh grup itu sendiri).
  - Pesan masuk grup menyertakan **nama grup** (subject grup, saat ini tidak pernah diambil).
  - `POST /send` menerima **kutipan** untuk Balas Pesan (saat ini hanya menerima tujuan, teks, dan penanda idempotensi).
  - Kontrak pengiriman untuk **Teruskan**, termasuk cara menyatakan bahwa pesan yang dikirim adalah pesan teruskan.
- **AuliaPos** — `app/Controllers/Inbox.php`, `ConversationModel`, dan layar Inbox:
  - Pemisahan tampilan grup **cukup** memakai `conversations.jid_type` yang **sudah ada** dan **sudah terisi** — bukan data baru.
  - `messages.sender_jid` **sudah ada** di skema dan **sudah** masuk daftar kolom yang boleh diisi; yang belum ada adalah pengisian nilainya yang benar dan menampilkannya di layar.
  - Perbaikan badge `perlu_dibalas` murni sisi AuliaPos dan tidak menambah panggilan ke Gateway.

### 8.2 Data storage & privacy

- Database Inbox (`aulia_inboxdb`, koneksi `inbox`) tetap terpisah dari database POS (`aulia_kasirdb`).
- **Tahap 1 tidak memerlukan perubahan skema sama sekali.** Pengenalan grup memakai kolom `jid_type` yang sudah ada; pemisahan tampilan dan perbaikan badge hanya membaca kolom itu.
- Identitas pengirim pesan grup disimpan pada kolom `messages.sender_jid` yang sudah ada — bukan kolom baru.
- **Balas Pesan menyimpan cuplikan teks yang dikutip di AuliaPos.** Ini disengaja dan diperlukan, karena thread percakapan dimuat ulang secara berkala sehingga kutipan tidak bisa diandalkan hanya dari memori layar. Data yang tersimpan tidak menambah jenis data pribadi baru di luar isi pesan yang sudah tersimpan.
- **Teruskan tidak menyalin berkas lampiran menjadi salinan baru** — pesan yang diteruskan merujuk pada lampiran yang sudah ada. Bila lampiran itu sudah tidak tersedia, pengiriman dibatalkan.
- **Tidak ada migrasi data mundur.** Data grup lama dibiarkan apa adanya; namanya terisi benar dengan sendirinya saat pesan berikutnya masuk setelah Tahap 2, dan label pengirim pesan lama tidak dipulihkan (keputusan draft §5.1 butir 7).
- Internal Note tetap **tidak pernah** dikirim ke Gateway/WhatsApp, termasuk di percakapan grup.
- Soft delete (`deleted_at`) tetap berlaku sama untuk pesan grup maupun pribadi.

### 8.3 Scalability & potential technical challenges

- **Pemisahan grup tidak menambah beban Gateway.** Penanda grup sudah ikut terkirim sejak awal, sehingga Tahap 1 hanya membaca data yang sudah ada di AuliaPos.
- **Kutipan menambah data yang harus ikut dimuat** saat thread percakapan dimuat ulang secara berkala. Perilaku muat ulang yang sudah ada tidak boleh melambat karenanya — ini yang perlu dirancang di Spec.
- **Label pengirim per pesan menambah data pada setiap baris pesan grup**, bukan pada percakapan. Jumlah pesan jauh lebih besar daripada jumlah percakapan, jadi cara memuatnya perlu dirancang agar tidak menambah permintaan per pesan.
- **Teruskan bergantung pada ketersediaan berkas lampiran.** Kegagalannya harus terdeteksi dan membatalkan pengiriman seluruhnya, mengikuti pola "gagal dengan suara, bukan senyap" yang sudah berlaku di proyek ini.
- **Idempotensi kirim wajib dipakai ulang.** Balas Pesan dan Teruskan harus memakai mekanisme `operation_id` yang sudah ada, bukan membuat mekanisme sendiri — supaya retry kasir tidak pernah mengirim pesan ganda.
- **Constraint empat dimensi (`CHAT.md` §18).** Pekerjaan ini hanya menyentuh dimensi **identitas**. Ia tidak boleh melebar ke dimensi kepemilikan, siklus hidup, atau baca-belum-dibaca. Keputusan "grup tanpa kepemilikan" adalah pernyataan bahwa grup tidak ikut aturan itu, bukan perubahan pada aturannya.
- **Audio dan video tidak akan pernah bisa diteruskan.** Ini keputusan desain final (`CHAT.md` §6.2: binary audio/video tidak pernah diambil), bukan pekerjaan yang tertunda. PRD ini menutupnya sebagai batasan, bukan sebagai utang.
- **Pencarian isi pesan yang sudah ada** akan mencakup pesan grup secara otomatis, karena grup disimpan sebagai pesan biasa. Tidak ada pekerjaan tambahan yang diminta untuk itu, dan tidak ada perilaku khusus pencarian grup dalam lingkup PRD ini.

## 9. Milestones & sequencing

### 9.1 Project estimate & Team composition

- **Size:** kecil–sedang, dikerjakan bertahap per fase SDLC, tanpa estimasi waktu formal.
- **Team:** developer tunggal dibantu AI pairing, mengikuti alur SDLC proyek.
- **Catatan komposisi:** karena pekerjaan ini menyentuh **dua repo**, disarankan **dua plan terpisah** (satu untuk AuliaPos, satu untuk WA-Gateway). Menggabungkannya dalam satu plan akan menyulitkan pelacakan status, karena kedua repo punya siklus rilis yang berbeda.

### 9.2 Suggested phases

Urutan mengikuti keputusan draft §5.1 butir 9 (**Grup lebih dulu**, baru Balas Pesan, lalu Teruskan).

- **Tahap 1 — Grup di AuliaPos** (repo AuliaPos saja, bisa dirilis lebih dulu)
  - Isi: tab **Grup**, penandaan grup, penonaktifan aksi yang tidak berlaku, perbaikan badge `perlu_dibalas`.
  - User story: GH-011, GH-012.
  - Tidak menunggu WA-Gateway. Tidak menambah skema. **Ini satu-satunya tahap yang bisa selesai tanpa repo kedua.**
- **Tahap 2 — Grup di WA-Gateway** (repo WA-Gateway + sisi tampilan AuliaPos)
  - Isi: identitas pengirim per pesan, nama grup sebagai judul percakapan.
  - User story: GH-013, GH-014.
  - Menutup ketiga gejala yang dilaporkan pemilik proyek secara tuntas.
- **Tahap 3 — Balas Pesan** (dua repo)
  - Isi: kutipan asli WhatsApp di `POST /send`, tampilan kutipan di AuliaPos, jalur gagal yang jelas.
  - User story: GH-015.
- **Tahap 4 — Teruskan** (dua repo)
  - Isi: kontrak pengiriman pesan teruskan, penanda Diteruskan, pembatasan audio/video, pembatalan saat lampiran hilang.
  - User story: GH-016.
  - Dikerjakan terakhir karena kontraknya paling baru dan paling bergantung pada ketersediaan lampiran.
- **Di luar urutan ini:** PRD, Spec, Plan, Kode, dan Review untuk setiap tahap mengikuti alur SDLC normal dengan checkpoint klarifikasi dan audit konsistensi di antara fase.

## 10. User stories & Acceptance Criteria

> [!NOTE]
> Seluruh kotak di bawah ini **sengaja belum dicentang**. PRD ini menuliskan perilaku yang **harus** terjadi, bukan status pekerjaan. Pencatatan status ada di dokumen `/plan/` pada fase berikutnya.

### 10.1. Melihat percakapan grup di tabnya sendiri (Grup Tahap 1)

- **ID**: GH-011
- **Story**: Sebagai kasir, saya ingin melihat percakapan grup di tab Grup yang terpisah dari antrean percakapan pelanggan, supaya saya bisa mempercayai angka antrean dan tetap bisa melayani grup tanpa berpindah ke WhatsApp di HP.
- **Acceptance criteria**:
  - [ ] Ada tab **Grup** di deretan tab daftar percakapan, sejajar dengan tab Belum Diambil, Open, Menunggu, Ditunda, dan Selesai.
  - [ ] Tab Grup menampilkan seluruh percakapan yang terdaftar sebagai grup.
  - [ ] Percakapan grup tidak muncul di tab Belum Diambil, Open, Menunggu, Ditunda, maupun Selesai.
  - [ ] Badge `perlu_dibalas` tidak bertambah oleh percakapan grup; pada data yang sama, selisih angkanya tepat sejumlah percakapan grup yang sebelumnya ikut terhitung.
  - [ ] Pemuatan ulang daftar dan pencarian yang sudah ada tetap berjalan; percakapan grup tetap mengikuti mekanisme pemuatan ulang yang berlaku.
  - [ ] Perilaku tab status untuk percakapan pribadi tidak berubah dibandingkan sebelum perubahan.

### 10.2. Mengenali grup dan tidak salah memberi aksi (Grup Tahap 1)

- **ID**: GH-012
- **Story**: Sebagai kasir, saya ingin langsung tahu bahwa sebuah percakapan adalah **Grup** dan tahu aksi mana yang tidak berlaku untuknya, supaya saya tidak salah klik dan tidak perlu menebak.
- **Acceptance criteria**:
  - [ ] Setiap baris percakapan grup di daftar membawa penanda **Grup**, terbaca tanpa membuka percakapan.
  - [ ] Header percakapan grup juga menyatakan bahwa percakapan itu Grup.
  - [ ] Penanda Grup tetap terbaca meski nama percakapan grup sedang tidak benar.
  - [ ] Tombol Ambil, Lepas, Tutup, dan Snooze tidak tersedia pada percakapan grup.
  - [ ] Konfirmasi Nomor dan Edit Profil dinonaktifkan pada percakapan grup.
  - [ ] Aksi yang tetap tersedia pada grup: mengirim pesan, **Balas Pesan**, **Teruskan**, dan Internal Note.
  - [ ] Tidak ada tombol yang tetap tampil lalu berakhir error saat ditekan pada percakapan grup.
  - [ ] Percakapan pribadi tidak kehilangan satu pun tombol yang sebelumnya tersedia.

### 10.3. Melihat siapa yang menulis tiap pesan di grup (Grup Tahap 2)

- **ID**: GH-013
- **Story**: Sebagai kasir, saya ingin melihat nama pengirim pada setiap pesan di dalam grup, supaya saya tahu sedang berbicara dengan siapa dan tidak membaca seluruh grup sebagai pesan satu orang.
- **Acceptance criteria**:
  - [ ] Setiap pesan grup yang baru masuk menampilkan nama pengirimnya.
  - [ ] Nama pengirim tetap tampil setelah kasir berpindah percakapan lalu kembali, dan setelah thread dimuat ulang.
  - [ ] Pesan grup yang dikirim kasir dari AuliaPos ditandai berasal dari staff yang mengirim, bukan dari grup.
  - [ ] Pesan grup yang tersimpan sebelum perubahan ini tampil tanpa nama pengirim, tanpa label kosong atau teks pengganti yang membingungkan.
  - [ ] Nama pengirim tidak pernah dipakai sebagai judul percakapan grup.

### 10.4. Melihat nama grup yang benar dan stabil (Grup Tahap 2)

- **ID**: GH-014
- **Story**: Sebagai kasir, saya ingin judul percakapan grup menampilkan nama grup yang sebenarnya dan tidak berubah-ubah, supaya saya bisa mengenali grup tanpa membukanya dan tidak salah mengira sedang membalas orang lain.
- **Acceptance criteria**:
  - [ ] Judul percakapan grup menampilkan nama grup dari WhatsApp.
  - [ ] Judul grup tidak berubah ketika anggota yang berbeda bergantian mengirim pesan.
  - [ ] Nama grup yang tersimpan tidak ditimpa oleh nama pengirim terakhir saat ada pesan masuk.
  - [ ] Bila nama grup belum bisa diperoleh, judul menampilkan penanda Grup tanpa nama orang.
  - [ ] Grup lama yang sudah tersimpan menampilkan nama yang benar setelah pesan berikutnya masuk, tanpa perbaikan data manual.
  - [ ] Percakapan pribadi tetap memakai nama kontak seperti sebelumnya — tidak ada perubahan perilaku.

### 10.5. Membalas satu pesan tertentu dengan kutipan (Balas Pesan)

- **ID**: GH-015
- **Story**: Sebagai kasir, saya ingin membalas satu pesan tertentu sehingga pesan itu ikut terkirim sebagai kutipan, supaya pelanggan tahu persis pesan mana yang saya jawab dan jawaban saya tidak salah sasaran.
- **Acceptance criteria**:
  - [ ] Kasir dapat menunjuk satu pesan lalu membalasnya.
  - [ ] **Balas Pesan** tersedia di percakapan pribadi dan di grup.
  - [ ] **Balas Pesan** tersedia untuk pesan teks maupun pesan media.
  - [ ] Pesan yang ditunjuk tampil sebagai kutipan di kotak tulis sebelum dikirim, dan kasir dapat membatalkannya.
  - [ ] Kutipan yang terkirim ke pelanggan adalah kutipan WhatsApp asli, bukan teks yang disalin manual.
  - [ ] Kutipan tetap terlihat oleh kasir setelah berpindah percakapan lalu kembali, dan setelah thread dimuat ulang.
  - [ ] Di grup, kutipan menampilkan nama pengirim yang dikutip.
  - [ ] Bila kutipan ditolak Gateway, kasir menerima penjelasan yang bisa dipahami dan ditawarkan pilihan mengirim tanpa kutipan.
  - [ ] Pesan tidak pernah terkirim tanpa kutipan tanpa sepengetahuan kasir.
  - [ ] Bila kutipan ditolak dan kasir memilih membatalkan, tidak ada pesan apa pun yang terkirim.
  - [ ] Pesan hasil **Balas Pesan** tidak terkirim ganda saat kasir menekan kirim berulang atau koneksi terputus (memakai mekanisme idempotensi kirim yang sudah ada).
  - [ ] Bila berkas media yang dikutip sudah tidak tersedia, rujukan kutipan tetap tampil dan thread tetap dapat dimuat.

### 10.6. Meneruskan pesan ke percakapan lain (Teruskan)

- **ID**: GH-016
- **Story**: Sebagai kasir, saya ingin meneruskan sebuah pesan ke percakapan lain yang sudah ada beserta penanda bahwa itu pesan teruskan, supaya saya tidak perlu mengetik ulang jawaban yang sudah saya tulis di percakapan lain.
- **Acceptance criteria**:
  - [ ] Kasir dapat menunjuk satu pesan lalu memilih **Teruskan**.
  - [ ] Daftar tujuan hanya memuat percakapan yang sudah ada, baik pribadi maupun grup.
  - [ ] **Teruskan** tidak pernah membuat percakapan baru.
  - [ ] Pesan yang diteruskan diberi penanda **Diteruskan**, dan penanda itu terbaca di sisi AuliaPos.
  - [ ] Pesan teks selalu dapat diteruskan.
  - [ ] Lampiran berupa gambar, dokumen, dan stiker dapat diteruskan selama berkasnya masih tersedia.
  - [ ] Audio dan video tidak dapat diteruskan, dan kasir menerima penjelasan yang bisa dipahami — bukan pesan error teknis.
  - [ ] Bila berkas lampiran sudah tidak tersedia, pengiriman dibatalkan seluruhnya dan kasir diberi tahu; tidak ada pesan setengah terkirim.
  - [ ] Meneruskan ke grup dan meneruskan ke percakapan pribadi mengikuti aturan yang sama.
  - [ ] Pesan hasil **Teruskan** tidak terkirim ganda saat kasir menekan kirim berulang atau koneksi terputus.
  - [ ] **Teruskan** menghormati aturan kepemilikan kirim yang sudah ada, sama seperti balas biasa.
