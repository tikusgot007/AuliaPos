# CHANGELOG

Perubahan aturan bisnis Aulia Kasir. Bahasa Indonesia (AGENTS.md §15).

Format entri:

```
## YYYY-MM-DD — <judul singkat>
- Aturan lama: <apa>
- Aturan baru: <apa>
- Alasan: <mengapa>
- Referensi: commit `<hash>`, docs/sesi/<file>
```

---

## 2026-10-02 — Inbox: riwayat thread menampilkan 200 pesan TERBARU dengan pagination

- Aturan lama: thread percakapan mengambil 500 pesan **tertua**
  (`message_timestamp ASC` + `LIMIT 500`), tanpa cara membuka pesan lain.
  Percakapan lebih dari 500 pesan berhenti menampilkan pesan baru.
- Aturan baru: `GET /inbox/api/conversations/{id}/messages` mengembalikan
  200 pesan **terbaru** (urut lama ke baru). Parameter opsional `before_id`
  mengambil 200 pesan tepat sebelum pesan itu (kursor `message_timestamp, id`),
  dan respons memuat `has_more`. Pesan lama dibuka lewat tombol "Muat pesan
  lama" (tahap berikutnya, belum ada di UI). Sampai tombol itu ada, pesan yang
  lebih lama dari 200 terbaru tidak terlihat di Inbox (data tetap tersimpan).
- Alasan: bug batas 500 memotong pesan terbaru; pagination menggantikan
  keputusan "tanpa pagination" setelah spike `vue-advanced-chat`
  (`docs/laporan-spike-vue-advanced-chat.md`).
- Referensi: `docs/requirements/2026-10-02-perbaikan-thread-inbox.md` (AC-1..AC-3,
  AC-23..AC-26), `docs/design/2026-10-02-perbaikan-thread-inbox.md`,
  `tests/feature/InboxMessagesPaginationTest.php`

## 2026-10-02 — Inbox: nama conversation tidak lagi berubah saat staff balas dari WA Web/HP

- Aturan lama: endpoint webhook Gateway (`InboxGatewayApi::messages()`)
  menulis `conversations.whatsapp_name` untuk SEMUA pesan (incoming maupun
  outgoing) selama nilainya berbeda. Untuk pesan outgoing yang disinkronkan
  dari WhatsApp Web/HP (`fromMe=true`), `contact_name` di payload adalah push
  name STAFF yang membalas, sehingga nama percakapan berubah menjadi nama
  staff.
- Aturan baru: `whatsapp_name` hanya dimutakhirkan dari push name customer
  untuk pesan **incoming** (`direction='incoming'`). Pesan outgoing dari WA
  Web/HP tidak lagi menyentuh `whatsapp_name`, sehingga nama percakapan tetap
  nama customer.
- Alasan: nama percakapan harus merefleksikan identitas customer, bukan staff
  yang membalas dari luar POS (laporan bug dari tim, dokumen "Penjelasan
  masalah untuk tim 01").
- Referensi: `docs/sesi/2026-10-02-bug-nama-conversation-wa-web.md`,
  `tests/feature/InboxGatewayApiWhatsappNameTest.php`.

## 2026-10-02 — Standardisasi pemilih tanggal/rentang/periode

- Aturan lama: tiap halaman mendefinisikan locale, preset, mode terapkan, dan
  pemuatan aset date picker sendiri-sendiri; nama parameter rentang bercampur
  (`tanggal_mulai/sampai` vs `tanggal_awal/akhir`).
- Aturan baru: satu konfigurasi standar (`App\Config\DatePicker`) + helper
  `public/assets/js/date-range.js`; locale Indonesia penuh, 6 preset, mode
  "Terapkan", aset dipin (`moment@2.31.0`, `daterangepicker@3.1.0`) dimuat
  global dari layout. Parameter rentang diseragamkan ke `tanggal_awal`/
  `tanggal_akhir` (termasuk `item_harian` & `laporan_pembayaran`). Default
  rentang per konteks dipertahankan.
- Alasan: konsistensi UX dan menghapus duplikasi yang menyebabkan drift.
- Referensi: `docs/requirements/2026-10-02-standardisasi-pemilih-tanggal.md`,
  `docs/design/2026-10-02-standardisasi-pemilih-tanggal.md`,
  `docs/sesi/2026-10-02-standardisasi-pemilih-tanggal.md`.

## 2026-09-30 — Import CSV Maintenance produk: kolom kosong Aktif/Locked

- Aturan lama: kolom Aktif/Locked yang kosong di CSV dipaksa menjadi 0 saat
  baris sudah ada di database (mengoverwrite nilai locked yang sedang
  berlaku).
- Aturan baru: untuk baris yang sudah ada, kolom Aktif/Locked yang kosong
  mempertahankan nilai di database. INSERT baris baru tetap default
  aktif=1, locked=0. Aksi NONAKTIF eksplisit tetap memaksa aktif=0.
- Alasan: import CSV yang tidak mengisi kolom Locked secara tidak sengaja
  membuka kunci semua produk yang diproses (lihat docs/sesi/2026-09-30-modul-produk.md).
- Referensi: commit `fc099ea` (fix(produk): keep Aktif/Locked when their CSV
  columns are blank).

## 2026-09-30 — Inbox: status gateway `degraded` (sesi WA terhubung tapi rusak)

- Aturan lama: gateway WhatsApp hanya punya status terhubung/terputus; sesi
  yang rusak (socket `connected` tapi gagal mendekripsi semua pesan) tidak
  terdeteksi sehingga pesan hilang tanpa peringatan.
- Aturan baru: status gateway dapat bernilai `degraded` saat terhubung tetapi
  sesi bermasalah. Inbox POS menampilkan badge merah dan memblokir kirim
  pesan sampai sesi dipulihkan (scan QR ulang).
- Alasan: mencegah kejadian 2026-09-29/30 terulang tanpa terdeteksi.
- Referensi: commit `f26138b`, `6afb732`;
  docs/sesi/2026-09-30-wa-gateway-sesi-degraded.md.

