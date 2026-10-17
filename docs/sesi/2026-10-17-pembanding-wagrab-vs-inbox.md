# Checkpoint Sesi — Pembanding independen WA Web (WAGRAB) vs Inbox

- **Tanggal**: 2026-10-17
- **Status**: selesai — analisa **read-only** (hanya `SELECT` ke `aulia_inboxdb`); tidak ada perubahan kode/data produksi.
- **Rujukan**: `docs/TODO.md` (TODO-F10), `docs/sesi/2026-10-14-analisa-pesan-gambar-hilang-628214065612.md`.

## Konteks

Pesan pelanggan sempat tidak masuk Inbox padahal muncul di WhatsApp Web. Dibuat pembanding independen:
ekstensi Chrome **WAGRAB** (`C:\Projects\WAGRAB`, bukan repo git) di WhatsApp Web `aulia3` mengirim semua pesan
yang tampil ke receiver lokal (SQLite `wa_messages.db`, PC dev). Pencocokan dengan Inbox lewat `wa_message_id`
(`messages.wa_message_id`), skrip `receiver/compare.py`.

## Hasil (tarikan 7 hari, 2026-10-10 12:00 s/d 2026-10-17 11:00 WIB, hanya pesan masuk 1:1)

- WA Web: **2.729** pesan masuk. Ada di Inbox: **2.540**. Tidak ada di Inbox: **189**.
- Sebagian besar dari 189 bukan pesan sungguhan (album 76, pesan ditarik 56, riwayat panggilan 13).
- Kandidat pesan benar-benar hilang: **44**, rinci:
  - **Gambar**: 11 Okt 18:31 pelanggan `...076319` (WA Web 20, Inbox 8); 13 Okt 16:26 pelanggan `...246748` (WA Web 16, Inbox 3);
    14 Okt 12:13 pelanggan `628214065612` (1 gambar, tidak ada di Inbox — **sama dengan insiden F10 14 Okt**, kini terbukti dari sisi WA Web).
  - **Dokumen**: 14 Okt 18:17 `Preview Surat Permohonan _ Buku Pelaut.pdf` (`...044349`).
  - **Ciphertext** (WA Web sendiri gagal membuka, jejak Bad MAC): 16 pesan — 11 Okt 10:26 (7), 12 Okt 20:04 (6), lainnya tunggal.
- Burst Bad MAC 16 Okt ~17:00 (counter aulia7: 10 bad_mac, 2 decrypt_fail): **tidak** ada pesan 1:1 hilang di jam itu menurut pembanding.

## Batas / belum terverifikasi

- Tarikan riwayat WA Web dibatasi 300 pesan per chat; angka 2.729 bisa lebih kecil dari sebenarnya.
- Pesan yang tiba saat tab WhatsApp Web tertutup tidak tertangkap WAGRAB.
- Kecocokan ID terbukti (2.540 cocok), tetapi penyebab tiap pesan hilang belum diselidiki satu per satu.
- Pola "banyak gambar sekaligus, sebagian hilang" (kasus 11 & 13 Okt) belum pernah tercatat; penyebabnya belum diketahui.

## Tindak lanjut (butuh keputusan Aan)

- Pertimbangkan SOP remediasi Tingkat 1 (`docs/ops/SOP-remediasi-sesi-signal-lid-gateway-aulia7.md`) — belum dijalankan.
- Selidiki pola kiriman gambar beruntun (11 & 13 Okt) di log Evolution aulia7.
- Putuskan apakah WAGRAB dipakai rutin (receiver dipindah ke server, atau endpoint di AuliaPos — Tier A, perlu gate).
