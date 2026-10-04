# Requirements: Arsip transaksi tidak boleh menghapus piutang aktif (TODO-BL06)

- **Tanggal**: 2026-10-04
- **Status**: disetujui
- **Tier SDLC**: A
- **Penanggung jawab**: sesi agen (disetujui user)
- **Ref backlog**: `docs/TODO.md` TODO-BL06 (High) — **TODO-DEC3: A+ dengan katup E** (sudah diputuskan 2026-10-03)

## 1. Tujuan

Mengubah `TransaksiArchiveService` agar transaksi dengan piutang aktif
(`status_pembayaran` `belum_bayar`/`dp`, status bukan `batal`/`mangkrak`)
**tidak pernah** ikut dipindah ke database arsip, sesuai keputusan produk
DEC-3 ("A+ dengan katup E"). Transaksi yang sudah `lunas`, `batal`, atau
`mangkrak` tetap diarsipkan seperti biasa.

## 2. Keputusan produk yang mendasari (DEC-3, sudah final)

Dicatat di `docs/TODO.md` baris `TODO-DEC3` (2026-10-03):

> **A+ dengan katup E** — `belum_bayar`/`dp` tidak diarsipkan; pengaman UI
> (tampilkan jumlah/nilai piutang saat pilih bulan); piutang macet ditandai
> `mangkrak` dulu baru boleh diarsipkan; cek korektif piutang yang terlanjur
> terarsip (ESC-002).

Rincian (dari sesi keputusan, direkonstruksi dari transkrip karena
`docs/TODO.md` hanya menyimpan ringkasannya):

- **Default (varian A)**: hanya transaksi `lunas` dan `batal` yang eligible
  diarsipkan; `belum_bayar`/`dp` tetap di DB utama sampai lunas.
- **Pengaman UI (A+)**: saat admin memilih bulan untuk preview/eksekusi,
  tampilkan jumlah & nilai piutang aktif di bulan itu, supaya admin tahu
  sebagian data TIDAK ikut diarsipkan.
- **Katup E**: piutang yang benar-benar macet bisa ditandai `mangkrak`
  (alur yang SUDAH ADA, `TransaksiModel::ubahStatus()`, admin-only) lalu
  **boleh** diarsipkan meski belum lunas — supaya bulan tidak "nyangkut"
  selamanya karena satu piutang tak tertagih.
- **ESC-002 (korektif)**: cek apakah sudah ada piutang yang terlanjur
  terarsip sebelum perbaikan ini.

## 3. Hasil cek ESC-002 (terverifikasi 2026-10-04)

Query read-only langsung ke `writable/archive/aulia_pos_archive.db`:

```
SELECT status_pembayaran, COUNT(*), SUM(grand_total) FROM transaksi_archive GROUP BY status_pembayaran;
SELECT COUNT(*) FROM transaksi_archive;  -- hasil: 0
```

**`transaksi_archive` berisi 0 baris** — fitur archive belum pernah
dijalankan sama sekali di instalasi ini. **Tidak ada piutang yang terlanjur
terarsip; tidak ada tindakan korektif yang diperlukan.** Katup korektif
ESC-002 dianggap tertutup oleh bukti ini, didokumentasikan di sini untuk
jejak audit.

## 4. Kondisi saat ini (terverifikasi di kode)

1. `TransaksiArchiveService::terapkanFilterBulan()` (`:378-400`) hanya
   memfilter berdasarkan **rentang tanggal bulan**, tidak ada filter
   `status_pembayaran`/`status`.
2. `TransaksiArchiveService::jalankan()` (`:479-555`) memakai filter itu
   untuk mengambil SEMUA transaksi di bulan terpilih, lalu menyalin ke
   SQLite dan **menghapus semuanya** dari MySQL (`hapusDariUtama()`,
   `:711-726`) — termasuk yang masih `belum_bayar`/`dp`.
3. `TransaksiArchiveService::preview()` (`:414-473`) menghitung `per_status`
   dari `status_pembayaran` saja; baris dengan `transaksi.status = 'batal'`
   **tidak pernah masuk** ke bucket `batal` (bucket itu selalu 0) karena
   `status_pembayaran` tidak punya nilai `'batal'` — ini bug tampilan yang
   sudah ada, ditemukan saat menelusuri fungsi yang sama untuk fitur ini
   (lihat Section 7).
4. `Tagihan::tagihanBaseBuilder()` (`app/Controllers/Tagihan.php:65-73`)
   SUDAH mengecualikan `status IN ('batal','mangkrak')` dari daftar
   tagihan — jadi transaksi `mangkrak` **sudah** tersembunyi dari Tagihan
   terlepas dari diarsipkan atau tidak. `Tagihan.php` tidak memiliki
   rujukan apa pun ke database arsip (tidak perlu, karena piutang aktif
   dengan perbaikan ini tidak akan pernah berpindah ke sana).

## 5. User story

- Sebagai **admin**, saat saya menjalankan Archive Transaksi untuk bulan
  lama, saya ingin piutang pelanggan yang masih aktif TETAP terlihat di
  Tagihan, tidak diam-diam lenyap karena terarsip.
- Sebagai **admin**, saya ingin melihat berapa piutang yang TIDAK ikut
  diarsipkan sebelum saya mengeksekusi, supaya saya tahu apa yang perlu
  ditindaklanjuti (lunasi, atau tandai mangkrak).

## 6. Acceptance criteria

- **AC-1**: Given admin memilih bulan untuk preview, when bulan itu punya
  transaksi `belum_bayar`/`dp` (status bukan `batal`/`mangkrak`), then
  preview menampilkan jumlah & total nilai piutang tersebut **terpisah**
  dari jumlah/nilai yang akan benar-benar diarsipkan.
- **AC-2**: Given eksekusi archive pada bulan yang punya piutang aktif,
  when dijalankan, then piutang tersebut **tidak** dipindahkan maupun
  dihapus dari DB utama — tetap ada, tetap muncul di Tagihan.
- **AC-3**: Given transaksi berstatus `lunas` atau `status='batal'`, when
  bulan diarsipkan, then transaksi itu dipindah seperti perilaku lama
  (tidak ada regresi untuk kasus yang sudah benar).
- **AC-4**: Given transaksi ditandai `mangkrak` (lewat alur admin yang
  sudah ada), when bulan diarsipkan, then transaksi mangkrak tersebut ikut
  dipindahkan ke arsip meski `status_pembayaran` belum `lunas` (katup E).
- **AC-5**: Given seluruh transaksi di bulan terpilih adalah piutang aktif
  (tidak ada yang eligible diarsipkan), when eksekusi dijalankan, then
  proses berhenti dengan pesan jelas dan **tidak** menyentuh DB utama sama
  sekali.
- **AC-6**: Given ringkasan `per_status` di preview, when ditampilkan,
  then transaksi `status='batal'` dihitung di bucket `batal` (perbaikan
  bug tampilan yang ditemukan di Section 4.3), bukan tercampur ke bucket
  `status_pembayaran`-nya.
- **AC-7**: Given validasi hasil salin (jumlah baris & total nilai) setelah
  fix ini, when dijalankan, then validasi tetap dilakukan atas himpunan
  yang BENAR-BENAR dipindahkan (bukan seluruh bulan) — mekanisme
  backup JSON → validasi → hapus tetap seperti sebelumnya, tidak regresi.

## 7. Batasan dan di luar cakupan

- Batasan: tanpa mengubah skema `transaksi`/`transaksi_archive`; tanpa
  mengubah ambang eligibilitas bulan (`CUTOFF_BULAN = 6`); tanpa mengubah
  `Tagihan.php` (tidak diperlukan — lihat Section 4.4).
- Tidak termasuk:
  - Membuat alur baru untuk menandai `mangkrak` (sudah ada,
    `TransaksiModel::ubahStatus()` + UI `transaksi/detail.php`).
  - Migrasi/pemulihan data historis (ESC-002 terverifikasi tidak perlu).
  - BL-07 (gerbang role `koreksiPembayaran`), BL-33 (penomoran invoice) —
    item TODO terpisah.

## 8. Dampak aturan bisnis

- [x] Mengubah aturan bisnis (wajib dicatat di `docs/CHANGELOG.md`)
- [x] Menyentuh data keuangan (`transaksi`, `pembayaran`, `tagihan`, kas)
- [ ] Mengubah skema database
- [ ] Mengubah kontrak POS <-> WA Gateway

Aturan baru (DEC-3, sudah disetujui 2026-10-03): "Archive Transaksi tidak
pernah memindahkan transaksi yang masih punya piutang aktif; hanya
transaksi lunas, batal, atau yang sudah ditandai mangkrak yang eligible."

## 9. Asumsi dan pertanyaan terbuka

- Asumsi: DEC-3 sudah final (tidak perlu persetujuan ulang untuk
  substansi keputusannya); yang perlu disetujui di sini adalah **rencana
  implementasi**-nya (Gate 1/2), termasuk perbaikan bug bucket `batal`
  yang ditemukan selagi menyentuh fungsi yang sama.
- Tidak ada pertanyaan terbuka yang mengubah hasil — DEC-3 sudah
  menjawab semua keputusan produk yang relevan (varian A+E, cara
  menampilkan piutang, dan status ESC-002 sudah dicek).

## 10. Persetujuan (Gate 1)

- [x] Disetujui oleh: user (chat), tanggal: 2026-10-04
