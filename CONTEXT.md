# AuliaPos

Kamus istilah bisnis (Ubiquitous Language) yang dipakai bersama oleh tim produk, dokumen SDLC, dan implementasi AuliaPos. Berkas ini dibuat secara bertahap dan hanya memuat istilah yang sudah disepakati secara eksplisit; ketiadaan sebuah istilah berarti istilah itu belum pernah diperdebatkan, bukan bahwa istilah tersebut tidak dipakai.

## Language

### Handoff

**Handoff**:
Pemindahan tanggung jawab sebuah percakapan dari satu staff kepada staff lain, disertai ringkasan dan tindakan lanjutan yang tercatat sebagai riwayat. Berlaku untuk percakapan yang belum ditutup, dan hanya boleh dimulai oleh staff yang sedang memegang percakapan tersebut, kecuali percakapan yang tampil di tab Belum Diambil.
_Avoid_: Transfer, Reassign, Alih tangan, Serah terima

**Handoff Summary**:
Ringkasan singkat keadaan sebuah percakapan pada saat diserahkan, wajib diisi. Menjelaskan konteks bagi penerima, bukan pesan untuk pelanggan.
_Avoid_: Ringkasan, Deskripsi, Keterangan

**Next Action**:
Tindakan yang diharapkan dilakukan penerima setelah menerima sebuah Handoff, wajib diisi. Berupa teks bebas, bukan pilihan dari daftar yang tetap.
_Avoid_: Next step, Langkah berikutnya, TODO

**Handoff Note**:
Catatan bebas yang menyertai sebuah Handoff dan bersifat opsional. Isinya tidak pernah terkirim ke pelanggan.
_Avoid_: Catatan Internal, Komentar

### Deteksi Bentrok

**Collision Detection**:
Perilaku sistem ketika dua staff mengubah kepemilikan percakapan yang sama pada saat yang hampir bersamaan: tepat satu perubahan diterima, dan permintaan lain ditolak tanpa menimpa kepemilikan yang sudah sah. Hanya mencakup bentrok saat perubahan disimpan.
_Avoid_: Presence, Live view, Tabrakan

**Presence**:
Pengetahuan tentang staff mana yang sedang membuka sebuah percakapan saat ini. Bukan bagian dari Collision Detection, dan belum menjadi perilaku yang berlaku.
_Avoid_: Collision detection, Sedang online

### Kepemilikan Percakapan

**Belum Diambil**:
Keadaan percakapan yang menunggu balasan dan belum dipegang staff mana pun.
Hanya percakapan inilah yang tampil di tab Belum Diambil dan boleh diserahkan
oleh staff mana pun.
_Avoid_: Unassigned, Belum dipegang

**Tanpa Pemilik**:
Keadaan percakapan yang tidak sedang dipegang staff mana pun, tanpa syarat
sedang menunggu balasan — bisa sudah ditandai dibaca atau sedang ditunda,
sehingga dapat tampil di tab selain Belum Diambil.
_Avoid_: Belum diambil, Kosong
