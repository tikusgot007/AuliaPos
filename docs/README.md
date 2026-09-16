# Peta Dokumentasi AULIA

Sebagai developer, dokumen mana yang harus dibaca?

## Aturan bisnis saat ini (baca ini)

| Domain | Baca |
|---|---|
| Transaksi, kasir, status, pembayaran, kas, tagihan, jadwal karyawan, printing, laporan | [`AULIA.md`](./AULIA.md) |
| Chat / Shared WhatsApp Inbox | [`CHAT.md`](./CHAT.md) |
| Priority karyawan & Effective Shift Leader | [`USER-SHIFT.md`](./USER-SHIFT.md) |
| Riwayat perubahan ("kenapa") | [`CHANGELOG.md`](./CHANGELOG.md) |

**Aturan:** dokumen di atas adalah satu-satunya sumber kebenaran aktif. Kalau menambah/mengubah aturan bisnis, edit di sini — bukan di `archive/`.

## Riwayat (`archive/`) — hanya untuk penelusuran historis

Dokumen lama (dokumen topik menengah + arsip bernomor asli) dipindah ke [`archive/`](./archive/). Isinya **dibekukan**, tidak pernah diedit lagi, dan **bukan** sumber kebenaran — hanya dibuka kalau perlu menelusuri jejak sejarah sebuah keputusan atau kutipan `Section N` yang masih dirujuk di komentar kode.

`docs/aturan-bisnis-{AULIA,CHAT,USER-SHIFT}.md` di folder ini (bukan di `archive/`) adalah file penunjuk singkat — dipertahankan di lokasi asal karena dikutip langsung oleh komentar di `app/**`, tapi isinya sudah pindah ke `archive/`.
