# Peta Dokumentasi AULIA

Folder ini punya beberapa dokumen — file mana yang harus dibaca tergantung apa yang kamu cari.

## Baca ini dulu (aturan bisnis aktif)

| Domain | Baca |
|---|---|
| Transaksi, kasir, status SELESAI/BATAL, Effective Shift Leader, backdate payment | [`AULIA-01-transaksi-dan-kasir.md`](./AULIA-01-transaksi-dan-kasir.md) |
| Jadwal Karyawan, Profil, Notifikasi/Reminder/Jatuh Tempo Tagihan, Preview Banner, Archive Transaksi, Cetak Nota | [`AULIA-02-modul-pendukung.md`](./AULIA-02-modul-pendukung.md) |
| Riwayat implementasi (P1–P15), audit teknis, pekerjaan terbuka domain AULIA inti | [`AULIA-CHANGELOG.md`](./AULIA-CHANGELOG.md) |
| Modul Chat / Shared WhatsApp Inbox — aturan bisnis per-topik | [`CHAT-01-aturan-bisnis-inbox.md`](./CHAT-01-aturan-bisnis-inbox.md) |
| Modul Chat — riwayat implementasi per fase/tahap | [`CHAT-CHANGELOG.md`](./CHAT-CHANGELOG.md) |

## Referensi (jarang perlu dibuka langsung)

| File | Kegunaan |
|---|---|
| [`aturan-bisnis-AULIA.md`](./aturan-bisnis-AULIA.md) | **Arsip bernomor.** Sumber section number `[AULIA §N]` yang dikutip di `AULIA-01/02/CHANGELOG.md` dan di komentar kode (`app/**`, mis. "lihat Section 28"). Isi & nomor sectionnya tidak pernah diubah — kalau perlu menelusuri balik satu kutipan Section spesifik, buka file ini. |
| [`aturan-bisnis-CHAT.md`](./aturan-bisnis-CHAT.md) | **Arsip bernomor** untuk modul Chat, pola sama seperti di atas — sumber `[CHAT §N]`. |
| [`aturan-bisnis-USER-SHIFT.md`](./aturan-bisnis-USER-SHIFT.md) | Dokumen desain cross-version (v2.x/v3.x) untuk konsep Priority & Shift Leader. **Definisi Shift Leader di sini SUDAH DIGANTIKAN** — dokumen ini menyebut "satu Leader per kode shift" tapi implementasi nyata (lihat `AULIA-01-transaksi-dan-kasir.md` §4.5) memakai "satu Leader global". Ada catatan status di bagian atas file ini yang menjelaskan ini. Bagian Priority (kolom `users.priority`) tetap berlaku. |

## Kenapa ada dua salinan (arsip + bacaan utama)?

Kode (`app/**`) mengutip nomor section dokumen asli langsung di komentar (mis. `// lihat docs/aturan-bisnis-AULIA.md Section 28`). Supaya kutipan itu tidak pernah basi, dokumen asli **tidak pernah** di-renumber — hanya ditandai arsip dan "dibekukan" isinya. Semua penambahan aturan bisnis baru sejak masing-masing dokumen dipecah masuk ke `AULIA-01/02/CHANGELOG.md` atau `CHAT-01/CHANGELOG.md`, dengan rujukan balik `[AULIA §N]`/`[CHAT §N]` ke section asli yang relevan.

Kalau menambah aturan bisnis baru: **jangan** edit dokumen arsip — tambahkan section baru di file topik yang sesuai (`AULIA-01/02` atau `CHAT-01`), dan section baru itu boleh langsung diberi nomor lanjutan di dokumen arsip juga (supaya kode yang mengutip section tetap konsisten), tapi section-section lama di arsip tidak boleh disentuh/dipindah.
