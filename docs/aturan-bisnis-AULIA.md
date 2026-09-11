# AULIA — Dokumentasi Aturan Bisnis & Keputusan Teknis

**Status:** Baseline aktif  
**Tanggal:** 2026-09-10 (update terakhir)  
**Project:** AULIA — PHP CodeIgniter 4 POS

> **Ringkasan update terbaru:** Phase 2 menambah **Section 4.2** —
> kapabilitas SELESAI terbatas untuk kasir lewat workflow Kasir/POS
> (transaksi miliknya sendiri yang sudah `lunas`), tanpa melonggarkan
> jalur status umum yang tetap admin-only. Lihat juga Section 19 P15.
> Sebelumnya dokumen ini juga sudah mencakup fitur **Archive
> Transaksi** (Section 28) — memindahkan transaksi lama (≥6 bulan
> penuh) ke database SQLite terpisah supaya database utama tetap
> ramping tanpa kehilangan histori. Modul Jadwal Karyawan (Section 20)
> sepenuhnya terpisah dari domain transaksi/kasir di Section 1-19.
> Section 19 memuat entri pekerjaan P9-P15: UI/error-handling tombol
> Selesai, filter status transaksi eksplisit, fitur Pelunasan
> Terlambat/Backdate, beberapa perbaikan UI kecil, dan kapabilitas
> SELESAI dari workflow Kasir/POS.

---

## 1. Tujuan dan workflow pengembangan

Dokumen ini menjadi acuan utama untuk aturan bisnis yang telah disepakati.

Workflow:
1. Tentukan masalah.
2. Sepakati aturan/tujuan.
3. Audit kode yang ada.
4. Daftarkan semua opsi solusi.
5. Pilih solusi.
6. Baru lakukan perubahan.
7. Perubahan kode dilakukan satu file per satu file.
8. Jangan menggunakan patch kecuali diminta.
9. Setiap perubahan diuji sebelum dianggap selesai.

Dokumentasi aturan bisnis dipisahkan dari kode yang menegakkannya.

---

# 2. Lifecycle transaksi

Status transaksi resmi:
- `proses`
- `selesai`
- `batal`

Status `diambil` tidak lagi menjadi bagian lifecycle.

```text
TRANSAKSI BARU
      |
      v
    PROSES
   /      \
 Edit     Batal
   |
   v
 SELESAI
 (FINAL)
```

## `proses`
Transaksi masih berjalan.

- Boleh Edit.
- Boleh Batal.
- Boleh Selesai — hanya jika `status_pembayaran = lunas`, dan hanya
  lewat jalur yang diizinkan untuk role terkait (Section 4.1 & 4.2).
- Bisa `belum_bayar`, `dp`, atau `lunas`.

**Penting:** `proses + lunas` tetap `proses`.

## `selesai`
`selesai` adalah **final untuk pekerjaan/transaksi**.

**Definisi (2026-09-05, menggantikan versi sebelumnya):**
`SELESAI` berarti garapan sudah benar-benar selesai dikerjakan **DAN**
pembayaran transaksi sudah **LUNAS**. Kedua syarat harus terpenuhi
bersamaan — bukan salah satu.

Syarat transisi `proses → selesai`:

1. Status transaksi saat ini harus `proses`.
2. `status_pembayaran` harus `lunas`.
3. Jalur + role sesuai konteks:
   - **Workflow umum** (Daftar/Detail Transaksi, endpoint
     `/api/ubah-status`): hanya `admin`. Kasir selalu ditolak backend.
   - **Workflow Kasir/POS** (endpoint `/api/kasir/selesaikan-transaksi`):
     `admin`, atau `kasir` untuk transaksi **miliknya sendiri** yang
     berasal dari POS — lihat Section 4.2.

**`lunas` ≠ otomatis `selesai`.** Pembayaran lunas hanya membuat
transaksi *memenuhi syarat* untuk diselesaikan. Tombol Selesai tetap
harus ditekan secara eksplisit — oleh admin di workflow umum, atau
oleh kasir pemilik di workflow POS (Section 4.2) — setelah memastikan
garapan benar-benar clear. Lihat Section 4.1.

Perilaku lain tidak berubah:
- Tidak boleh Edit.
- Tidak boleh menambah barang melalui Edit.
- Tidak kembali ke `proses` melalui alur normal.
- Tetap boleh menerima pembayaran jika masih ada sisa (meski dengan
  aturan baru ini, dalam praktiknya `selesai` seharusnya selalu
  sudah `lunas` sejak awal — sisa hanya mungkin muncul lewat jalur
  lama/khusus, misalnya data historis sebelum aturan ini berlaku).

~~Contoh valid: `status=selesai` + `status_pembayaran=belum_bayar`~~
**Tidak berlaku lagi.** Kondisi ini sekarang dianggap tidak valid
untuk transaksi baru (lihat Section 4.1, Kasus C).

## `batal`
Transaksi dibatalkan.

- Tidak boleh Edit.
- Tidak boleh menerima pembayaran baru.
- Tidak masuk Tagihan.
- Pembatalan tidak otomatis berarti refund.
- Pembayaran historis tidak dihapus.
- Saat batal, `no_order` dikosongkan (`null`).

Mekanisme `aktifkan` untuk transaksi batal masih ada di baseline dan belum diputuskan untuk dihapus. Perlu diperhatikan bahwa reaktivasi tidak otomatis mengembalikan No Order lama.

---

# 3. Status transaksi vs status pembayaran

Kedua status **independen**.

### Status transaksi
Menjawab kondisi pekerjaan:
- `proses`
- `selesai`
- `batal`

### Status pembayaran
Menjawab kondisi pembayaran:
- `belum_bayar`
- `dp`
- `lunas`

**`lunas` bukan status final transaksi.**

| Status transaksi | Status pembayaran | Arti |
|---|---|---|
| proses | belum_bayar | pekerjaan berjalan, belum bayar |
| proses | dp | pekerjaan berjalan, sudah DP |
| proses | lunas | pekerjaan berjalan, sudah lunas |
| selesai | belum_bayar | pekerjaan final, masih ada tagihan |
| selesai | dp | pekerjaan final, masih ada tagihan |
| selesai | lunas | pekerjaan final dan lunas |
| batal | apa pun | transaksi dibatalkan |

> Baris `selesai + belum_bayar` dan `selesai + dp` **tidak bisa dibentuk**
> lewat transisi normal — transisi `proses → selesai` selalu mewajibkan
> `lunas` (Section 4.1 & 4.2). Kombinasi itu hanya mungkin jika pembayaran
> transaksi yang sudah `selesai` kemudian di-reversal lewat Koreksi
> Pembayaran (Section 6).

---

# 4. Transaksi baru

Semua transaksi baru masuk:

```text
status = proses
```

Tidak bergantung pada metode pembayaran.

Contoh:
- Tunai → `proses`
- QRIS → `proses`
- Transfer → `proses`
- DP → `proses`
- Piutang → `proses`

`status_pembayaran` dihitung terpisah.

---

## 4.1 Syarat SELESAI (2026-09-05)

**Keputusan resmi:** `PROSES → SELESAI` hanya diizinkan jika:

```text
status transaksi saat ini = proses
AND
status_pembayaran         = lunas
AND
user yang mengubah        = admin
```

Jika salah satu syarat gagal, backend **menolak** perubahan status
dan tidak melakukan update apa pun. Pesan error dibedakan:

- Bukan admin →
  `"Hanya admin yang dapat menyelesaikan transaksi."`
- Belum lunas →
  `"Transaksi belum dapat diselesaikan karena pembayaran belum lunas.
  Sisa pembayaran: Rp<nominal>"`

**Siapa yang boleh menyelesaikan (per konteks):**

| Role | Workflow umum — Daftar/Detail, `/api/ubah-status` | Workflow Kasir/POS — `/api/kasir/selesaikan-transaksi` |
|---|---|---|
| admin | Ya, jika `lunas` | Ya, jika `lunas` |
| kasir | **Tidak** — selalu ditolak backend | Ya, jika `lunas` **dan** transaksi miliknya sendiri dari POS (Section 4.2) |

Syarat `lunas` identik di kedua jalur; yang berbeda hanya siapa yang
boleh memicunya dan dari mana. Rumusan permission kasir bukan
"kasir boleh mengubah status menjadi selesai", melainkan
**"kasir boleh menyelesaikan transaksi lewat workflow Kasir/POS untuk
transaksi miliknya yang sudah lunas"** (Section 4.2).

Role SPV belum dibuat; jika dibutuhkan nanti, ditambahkan sebagai
perubahan terpisah. Restriction diterapkan di backend
(`TransaksiModel::ubahStatus()` untuk syarat status/`lunas`;
controller endpoint untuk role, kepemilikan, dan `sumber`), bukan
hanya disembunyikan di UI.

### Contoh kasus

**Kasus A — Lunas di awal, garapan masih berjalan**
```text
Total Rp50.000, Dibayar Rp50.000, Sisa Rp0
status_pembayaran = lunas
status transaksi  = proses   (tetap, sampai admin klik Selesai)
```
Setelah garapan benar-benar clear dan admin klik Selesai:
`status = selesai`, `status_pembayaran = lunas`.

**Kasus B — DP, garapan sudah selesai dikerjakan**
```text
Total Rp50.000, Dibayar Rp20.000, Sisa Rp30.000
status_pembayaran = dp
status transaksi  = proses   (tidak boleh diubah ke selesai)
```
Setelah pelanggan melunasi sisa Rp30.000:
`status_pembayaran = lunas`, `status transaksi` **tetap** `proses`
(pelunasan tidak otomatis mengubah status transaksi — lihat juga
Section 8). Baru setelah admin memastikan garapan clear dan klik
Selesai: `status = selesai`.

**Kasus C — Tidak boleh terjadi lagi**
```text
Total Rp11.000, Dibayar Rp0, Sisa Rp11.000
status_pembayaran = belum_bayar
status transaksi  = selesai   ← TIDAK VALID
```
Backend menolak transisi `proses → selesai` selama pembayaran belum
`lunas`. Kombinasi `selesai + belum_bayar` atau `selesai + dp` tidak
lagi bisa terbentuk lewat jalur normal aplikasi.

### Prinsip

```text
             PROSES
                │
        ┌───────┴────────┐
        │                │
   belum lunas         LUNAS
        │                │
        │          menunggu admin
        │          memastikan garapan
        │                │
        │         Admin klik SELESAI
        │                │
        └──────────┬─────┘
                   ▼
                SELESAI
```

Dua syarat untuk `SELESAI`: (A) pembayaran sudah `lunas`, (B) transaksi
ditandai selesai secara eksplisit. `LUNAS` sendirian tidak pernah
cukup. Penanda pada diagram di atas adalah **admin** (jalur umum);
sejak Phase 2, **kasir pemilik** juga bisa menandai lewat workflow
Kasir/POS untuk transaksinya sendiri (Section 4.2) — syarat (A) tetap
berlaku sama.

### Cakupan perubahan ini

- **Tidak** menambah status baru (`diambil`/`siap diambil` tetap
  tidak dipakai — lihat Section 14; tetap jadi pembahasan terpisah).
- **Tidak** mengubah behavior `batal` (Section 6, 12 tetap berlaku
  apa adanya).
- **Tidak** mengubah mekanisme pembayaran, Tagihan (Section 8), atau
  laporan (Section 15) — semuanya tetap berbasis `status_pembayaran`
  seperti sebelumnya.
- **Tidak** mengubah alur Edit (Section 5) — Edit tetap hanya untuk
  `status = proses`, tidak bergantung pada syarat SELESAI yang baru.

---

## 4.2 Kapabilitas SELESAI dari workflow Kasir/POS (2026-09-10, Phase 2)

**Keputusan resmi:** kasir boleh menyelesaikan transaksi **hanya**
lewat workflow Kasir/POS (`kasir/index.php`), untuk transaksi miliknya
sendiri yang sudah lunas. Tujuannya mengurangi beban admin: kasir yang
membuat transaksi sekaligus menerima pembayaran dapat langsung
menuntaskannya tanpa menunggu admin.

Bukan: "kasir boleh mengubah status menjadi `selesai`".
Melainkan: **"kasir boleh menyelesaikan transaksi lewat workflow
Kasir/POS untuk transaksi miliknya yang sudah `lunas`."**

**Syarat (semua wajib, dicek backend):**

| # | Syarat | Diperiksa di |
|---|---|---|
| a | `sumber = 'kasir_pos'` | controller endpoint |
| b | `kasir_id === id_user` (transaksi dibuat kasir tersebut) — admin dikecualikan dari syarat ini | controller endpoint |
| c | status transaksi masih `proses` | controller endpoint + `TransaksiModel::ubahStatus()` |
| d | `status_pembayaran = lunas` | `TransaksiModel::ubahStatus()` (sama persis dengan Section 4.1) |

**Jalur:** hanya endpoint khusus POS
`POST /api/kasir/selesaikan-transaksi`. Endpoint ini meneruskan
kapabilitas konteks ke `TransaksiModel::ubahStatus()` sehingga gate
"harus admin" dilewati untuk kasir pemilik — **tetapi syarat `lunas`
tidak pernah dilewati**. `belum_bayar` / `dp` tetap ditolak untuk
semua role di semua jalur.

**Yang tetap TIDAK boleh untuk kasir:** menyelesaikan transaksi lewat
workflow umum — halaman Daftar Transaksi, halaman Detail Transaksi,
dan endpoint `/api/ubah-status`. Di sana kasir selalu ditolak backend
(Section 4.1), tak berubah oleh Phase 2.

**Admin** tetap dapat menyelesaikan transaksi lewat workflow umum
(jika `lunas`) maupun lewat endpoint POS. Transaksi yang belum lunas /
DP tetap tidak boleh menjadi `selesai` untuk admin sekalipun.

**UI bukan enforcement.** Tombol "Tandai Selesai" di modal sukses
kasir dan penyembunyian tombol Selesai untuk kasir di Daftar/Detail
hanyalah lapis pertama. Sumber kebenaran enforcement ada di backend
untuk: **role, kepemilikan (`kasir_id`), `sumber` transaksi, status
pembayaran, dan status transaksi**. Melewati UI dan memanggil endpoint
langsung tetap tunduk pada semua pemeriksaan di atas.

---

# 5. Edit transaksi

Edit normal hanya berlaku untuk:

```text
status = proses
```

Bukan berdasarkan `status_pembayaran`.

| Status | Pembayaran | Edit |
|---|---|---|
| proses | belum_bayar | Ya |
| proses | dp | Ya |
| proses | lunas | Ya |
| selesai | belum_bayar | Tidak |
| selesai | dp | Tidak |
| selesai | lunas | Tidak |
| batal | apa pun | Tidak |

Jika perlu menambah barang setelah transaksi `selesai`, buat **transaksi baru**.

Jika isi transaksi salah, SOP:
**Batal → buat transaksi baru.**

## 5.1 Perubahan total saat edit & kelebihan bayar

**Keputusan resmi (2026-09-04):** Edit transaksi boleh menaikkan **atau**
menurunkan total belanja, tanpa batas bawah. Tidak ada validasi yang menolak
edit hanya karena total baru lebih kecil dari yang sudah dibayar.

Konsekuensi kalau total baru **lebih kecil** dari total pembayaran aktif
yang sudah ada:

- Transaksi tetap tersimpan seperti biasa.
- `total_dibayar` **tidak diubah** — tetap sesuai jumlah pembayaran aktif
  yang sebenarnya (tidak pernah ditimpa turun).
- `status_pembayaran` otomatis `lunas` (karena sudah dibayar ≥ total).
- Selisihnya adalah **kelebihan bayar**, dihitung sebagai
  `total_dibayar - grand_total`, dan ditampilkan sebagai indikator di UI
  (halaman edit & detail transaksi).

**Kelebihan bayar BUKAN refund.** Edit transaksi **tidak pernah**
mencatat refund otomatis ke kas. Refund fisik ke pelanggan — kalau memang
dilakukan — adalah aksi manual dan terpisah, dicatat lewat
**Kas Keluar → kategori "Refund Penjualan"**, kapan saja kasir memutuskan,
konsisten dengan Section 6 dan Section 12 (*"refund adalah proses
tersendiri"*).

Sumber kebenaran `total_dibayar` & `status_pembayaran` setelah edit adalah
`TransaksiModel::sinkronkanPembayaran()` — bukan hitungan manual di
controller (lihat juga Section 7 dan Section 16).

**Alasan keputusan ini** (dibanding alternatif "tolak edit jika turun di
bawah yang sudah dibayar", ala Square, atau "auto-refund", yang sempat jadi
perilaku bawaan sebelum diperbaiki): AULIA adalah POS toko tunggal tanpa
infrastruktur "reopen transaksi + refund otomatis" seperti POS besar
(mis. Lightspeed). Menjaga item dan pembayaran tetap dalam satu record yang
sama (bukan dipecah jadi Batal + transaksi baru) mengurangi kerja ulang
kasir dan risiko rekonsiliasi manual yang lebih rumit.

---

# 6. Koreksi metode pembayaran

Kesalahan isi transaksi berbeda dengan kesalahan metode pembayaran.

- Salah isi transaksi → Batal + transaksi baru.
- Salah metode pembayaran → **Koreksi Pembayaran**.
- Uang benar-benar dikembalikan → **Refund** terpisah.

Jangan langsung mengubah `pembayaran.metode` karena histori harus tetap dapat diaudit.

Mekanisme koreksi:
```text
pembayaran lama: aktif
        |
     koreksi
        |
        +--> lama = reversed
        +--> baru = aktif
```

Pembaca total pembayaran hanya menghitung pembayaran `status=aktif`.

---

# 7. Konsistensi total pembayaran

`transaksi.total_dibayar` dipertahankan sebagai cache/denormalisasi untuk menghindari SUM berulang.

Nilai sumber pembayaran aktif secara konsep:

```text
SUM(pembayaran.jumlah WHERE status='aktif')
```

Cache harus disinkronkan setelah pembayaran ditambahkan.

Tersedia:
- sinkronisasi;
- pemeriksaan konsistensi;
- command repair.

Baseline terakhir:
`OK: tidak ditemukan total_dibayar yang tidak konsisten.`

**Catatan historis (2026-09-04):** `Transaksi::updateTransaksi()` sempat
menghitung `total_dibayar` dengan akses tipe data yang salah
(`->first()->jumlah`, gaya object, padahal `PembayaranModel` mengembalikan
array) — nilainya diam-diam selalu jadi `0` tanpa error, dan menimpa
`total_dibayar` transaksi setiap kali diedit. Sudah diperbaiki: method ini
sekarang memanggil `TransaksiModel::sinkronkanPembayaran()`, sama seperti
`tambahPembayaran()`, sehingga tidak ada lagi logic penghitungan
`total_dibayar` yang terpisah/duplikat di controller. Lihat juga
Section 5.1.

---

# 8. Tagihan

**Keputusan resmi: Tagihan berdasarkan pembayaran saja**, bukan status transaksi.

Aturan:
- `belum_bayar` → masuk Tagihan.
- `dp` → masuk Tagihan.
- `lunas` → tidak masuk Tagihan.
- `batal` → tidak masuk Tagihan.

| Status transaksi | Pembayaran | Tagihan |
|---|---|---|
| proses | belum_bayar | YA |
| proses | dp | YA |
| proses | lunas | TIDAK |
| selesai | belum_bayar | YA |
| selesai | dp | YA |
| selesai | lunas | TIDAK |
| batal | apa pun | TIDAK |

Pelunasan melalui Tagihan:
- menambah pembayaran aktif;
- memperbarui status pembayaran;
- **tidak otomatis mengubah status transaksi**.

Contoh:
`selesai + dp` → bayar sisa → `selesai + lunas`.

---

# 9. No Order

No Order bukan nomor urut transaksi database.

No Order merepresentasikan **nama/nomor file foto fisik yang sedang diproses**.

Aplikasi membantu dengan:
- No Order terakhir;
- kandidat berikutnya;
- No Order yang masih tersedia.

`recommended_no_order` adalah **saran/kandidat**, bukan generator wajib.

Generate New Order tetap berguna sebagai shortcut bila nomor fisik memang berikutnya.

Setelah No Order digunakan pada transaksi berhasil, nomor tersebut tidak lagi tersedia.

Produk kategori Studio/Foto membutuhkan No Order. Produk lain dapat diproses tanpa No Order.

Saat transaksi batal:
```text
no_order = null
```

---

# 10. Banner

## Baris tetap terpisah
Banner dengan ukuran sama **tidak otomatis digabung**, karena ukuran sama dapat berasal dari pekerjaan/desain berbeda.

## Total < 1 m²
Minimum pricing berdasarkan **total luas seluruh item Banner**.

Rumus:
```text
totalArea × hargaPerM2 × 1.1
```

Kemudian:
- pembulatan Rp500;
- maksimum harga standar per m²;
- minimum Rp10.000.

Contoh 0,8 m² @ Rp22.000:
`Rp19.360` → `Rp19.500`.

Jika beberapa baris:
- total dihitung global;
- harga dibagi proporsional berdasarkan luas × qty;
- Rupiah utuh;
- baris terakhir menjadi residual balancer;
- grand total harus tepat sama dengan total global.

## Total ≥ 1 m²
Setiap baris:
```text
luas × qty × hargaPerM2
```
dibulatkan ke Rp500.

Baris tidak digabung dan tidak ada biaya tambahan karena total luas "nanggung".

---

# 11. Kas dan pembayaran tunai

Kas bertambah berdasarkan uang yang benar-benar menjadi penerimaan penjualan setelah kembalian.

Contoh:
```text
Total      = Rp100.000
Bayar      = Rp150.000
Kembalian  = Rp50.000
Kas masuk  = Rp100.000
```

Konsep rekonsiliasi:
```text
Kas Awal
+ Penjualan Tunai
+ Pemasukan Kas Lain
- Pengeluaran
- Refund
= Saldo Kas Sistem
```

Saldo sistem dapat dibandingkan dengan Opname Kas Fisik untuk mendapatkan selisih.

---

# 12. Pembatalan dan histori

Pembatalan tidak menghapus histori pembayaran.

Prinsip:
- histori pembayaran dipertahankan;
- status transaksi menjadi `batal`;
- refund adalah proses tersendiri;
- koreksi pembayaran memakai `reversed`/`aktif`.

---

# 13. Tampilan transaksi

Belum ada halaman baru khusus transaksi `proses`.

Untuk sementara transaksi `proses` dikelola melalui:

```text
transaksi/index
```

Alur:
```text
TRANSAKSI BARU
      ↓
    PROSES
      ↓
transaksi/index
      ↓
[Selesai] atau [Batal]
```

Action:
- `proses` → Edit, Selesai (di halaman ini: **khusus admin**, hanya
  jika `lunas` — lihat Section 4.1; kasir menyelesaikan transaksinya
  sendiri lewat workflow Kasir/POS, Section 4.2), Batal.
- `selesai` → lihat detail dan bayar jika masih ada sisa.
- `batal` → tidak Edit dan tidak menerima pembayaran baru.
- `diambil` → tidak digunakan.

Di halaman Daftar/Detail Transaksi, tombol Selesai **hanya
ditampilkan untuk admin** (P9). Kasir tidak melihatnya di sini, dan
`/api/ubah-status` menolak kasir di backend (Section 4.1).
Penyembunyian tombol hanyalah lapis pertama — backend selalu
memvalidasi ulang role, kepemilikan, `sumber`, status pembayaran, dan
status transaksi sebelum eksekusi (Section 4.1, 4.2, 16).

---

# 14. Status `diambil`

`diambil` dihapus dari lifecycle.

Alur lama:
```text
selesai → diambil
```

tidak digunakan lagi.

Alur resmi:
```text
proses → selesai
proses → batal
```

`selesai` adalah final.

---

# 15. Laporan

Transaksi `batal` tidak dianggap transaksi aktif.

Pola `status != batal` tetap dapat digunakan bila laporan memang bermaksud menghitung semua transaksi non-batal.

Namun laporan tertentu perlu dibedakan berdasarkan tujuan:
- semua transaksi non-batal;
- hanya `proses`;
- hanya `selesai`;
- hanya yang `lunas`.

Jangan menyamakan status pekerjaan dengan status pembayaran.

---

# 16. Arsitektur aturan status

Pendekatan yang dipilih untuk implementasi lifecycle adalah **Opsi B — terpusat dan lebih aman**.

Prinsip:
- `TransaksiModel` menjadi pusat aturan status;
- API/controller tidak membuat aturan transisi sendiri-sendiri;
- View mengikuti aturan backend;
- endpoint status divalidasi;
- transisi ilegal harus ditolak backend.

Konsep:
```text
View
  ↓
Controller/API
  ↓
TransaksiModel
  ↓
aturan lifecycle
```

---

# 17. Audit status yang wajib

Sebelum perubahan lifecycle dilakukan, audit mencakup:

1. Lokasi yang menetapkan `proses`.
2. Lokasi yang menetapkan `selesai`.
3. Lokasi yang menetapkan `diambil`.
4. Lokasi yang menetapkan `batal`.
5. Semua pembacaan/filter `status`.
6. Tombol/label Selesai, Diambil, Batal.
7. Endpoint/API perubahan status.
8. Routes status.
9. `TransaksiModel`.
10. Alur Edit.
11. Alur Tagihan/pelunasan.
12. Laporan.
13. Perhitungan kas.

Area utama yang telah teridentifikasi:
```text
app/Controllers/Api.php
app/Controllers/Transaksi.php
app/Controllers/Kasir.php
app/Controllers/Tagihan.php
app/Controllers/Laporan.php
app/Models/TransaksiModel.php
app/Services/CashBalanceService.php
app/Views/transaksi/index.php
app/Views/transaksi/detail.php
app/Views/transaksi/hari_ini.php
app/Views/tagihan/index.php
app/Config/Routes.php
```

Daftar ini adalah titik audit; tidak berarti semua file pasti harus diubah.

**Status audit untuk perubahan syarat SELESAI (2026-09-05):** selesai
dilakukan — lihat Section 19 P8 untuk hasil dan file yang benar-benar
disentuh (hanya `TransaksiModel.php`).

---

# 18. P7 stabilization

| Area | Status |
|---|---|
| P7-1 No Order | Selesai / Stabil |
| P7-2 Cart | Selesai / Stabil |
| P7-3 Diskon & Total | Selesai / Stabil |
| P7-4 Customer | Selesai / Stabil |
| P7-5 Payment | Selesai / Stabil |
| P7-6 Piutang | Selesai / Stabil |
| P7-7 Reset setelah transaksi | Selesai / Stabil |
| P7-8 Print | Selesai / Stabil |
| P7-9 Banner | Selesai / Stabil |
| P7-10 Audit `kasir/edit.php` | Selesai / Stabil |

`Regression` adalah jenis pengujian, bukan status pekerjaan.

---

# 19. Pekerjaan yang sudah selesai

## P1 — Konsistensi Total Pembayaran
Solusi:
`cache + auto sync + consistency check/repair`.

Sudah diuji dan stabil.

## P2 — Koreksi Metode Pembayaran
Solusi:
`pembayaran.status = aktif/reversed`.

Sudah diuji dengan berbagai kombinasi metode dan stabil.

## P7-1 — No Order
Audit dan perbaikan minimal API sudah diuji dan stabil.

## P7-6 — Piutang
Pembuatan piutang dan pelunasan sudah diuji dan stabil.

## P7-7 — Reset
Reset cart/customer/No Order/diskon setelah transaksi sudah diuji dan stabil.

## P7-8 — Print
Dinyatakan aman/stabil.

## P7-9 — Banner
Aturan:
- baris tetap terpisah;
- minimum pricing berdasarkan total area untuk <1 m².

Sudah diuji dengan berbagai luas dan qty dan stabil.

## P7-10 — Audit `kasir/edit.php` & Pembayaran (2026-09-04)

Scope yang diaudit dan diselesaikan:

1. **Bug `total_dibayar` hilang saat edit** — akses tipe data salah di
   `updateTransaksi()` (lihat Section 7). Diperbaiki dengan
   `sinkronkanPembayaran()`.
2. **Auto-refund dihapus** — `updateTransaksi()` sebelumnya mencatat
   refund otomatis ke `cash_expense` begitu total baru lebih kecil dari
   yang sudah dibayar, termasuk bug yang membuat edit kedua pada
   transaksi yang sama langsung error 500. Perilaku ini bertentangan
   dengan Section 6/12 ("refund adalah proses tersendiri") dan sudah
   dihapus. Lihat Section 5.1 untuk keputusan resminya.
3. **Duplikasi logika Banner** — `modal_banner.php` diperbaiki (sempat
   kehilangan seluruh `<script>` fungsi banner akibat edit manual di luar
   proses ini).
4. **State cart & shared JS** — logic kasir (cart, diskon, katalog produk,
   pelanggan, no order, banner) diekstrak ke
   `public/assets/js/kasir-shared.js`, dipakai bersama oleh
   `kasir/index.php` dan `kasir/edit.php`.
5. **Indikator kelebihan bayar** — ditambahkan di `kasir/edit.php` dan
   `transaksi/detail.php` supaya kelebihan bayar akibat edit-turun selalu
   terlihat, tidak cuma muncul sesaat lewat toast.

Sudah diuji: transaksi belum bayar, transaksi lunas yang diturunkan
(kelebihan bayar), dan transaksi DP yang dinaikkan — ketiganya stabil.

## P8 — Syarat SELESAI: admin + lunas (2026-09-05)

**Masalah:** `TransaksiModel::ubahStatus()` mengizinkan
`proses → selesai` tanpa memeriksa `status_pembayaran` maupun role
user, sehingga kondisi seperti `selesai + belum_bayar` (kasus "Dini
Moyo") bisa terjadi.

**Audit:** Ditemukan bahwa `ubahStatus()` adalah **satu-satunya**
jalur penulisan `status='selesai'` ke tabel `transaksi` di seluruh
codebase (dikonfirmasi lewat pencarian menyeluruh di
`app/Controllers` dan `app/Models`). `Api::ubahStatus()` sudah
menghitung `$isAdmin` dari session role dan meneruskannya ke model.
`Transaksi::updateTransaksi()` (alur Edit) tidak menyentuh kolom
`status` sama sekali. Frontend (`transaksi/index.php`,
`transaksi/detail.php`) sudah meneruskan pesan error backend ke
`showToast()` tanpa perlu perubahan tambahan.

**Solusi:** Validasi ditambahkan langsung di
`TransaksiModel::ubahStatus()`, tepat sebelum transisi
`proses → selesai` dieksekusi:
1. Cek `$isAdmin`; tolak dengan pesan jelas jika bukan admin.
2. Panggil `sinkronkanPembayaran()` untuk menyegarkan
   `status_pembayaran` sebelum dicek (menghindari cache basi —
   lihat riwayat bug di Section 7), lalu tolak dengan pesan jelas
   (termasuk nominal sisa) jika belum `lunas`.

Tidak ada perubahan di `Api.php`, view, atau mekanisme pembayaran —
seluruhnya sudah otomatis mengikuti aturan baru karena tersentralisasi
di satu method (Section 16, Opsi B).

**Pengujian:** 8 skenario diuji via harness standalone (model
di-stub, tanpa DB): admin+lunas→berhasil; admin+belum_bayar→ditolak;
admin+dp→ditolak; kasir+lunas→ditolak; pelunasan via
`sinkronkanPembayaran()` tidak mengubah status transaksi (memenuhi
"LUNAS ≠ otomatis SELESAI"); klik Selesai dua kali pada transaksi
yang sudah selesai bersifat idempotent; regression `batal` (dengan
maupun tanpa admin) tidak berubah. Semua 8 lulus.

Status: **Selesai / Stabil**.

---

## P9 — UI & Error Handling tombol Selesai (2026-09-05)

**Konteks:** setelah P8 mengunci business rule di backend, tombol
"Selesai" di `transaksi/index.php` dan "Selesai Dikerjakan" di
`transaksi/detail.php` masih tampil untuk semua role dan memakai
konfirmasi generik yang sama untuk semua kondisi.

**Perubahan (UI/error-handling saja, tidak menyentuh business rule):**
- Tombol digating `session()->get('role') === 'admin'` di halaman
  Daftar/Detail — kasir tidak melihat tombol ini di sana (lapis 1).
  Backend P8 tetap jadi lapis 2 yang otoritatif. (Diperluas oleh
  Phase 2 / Section 4.2: kasir kini bisa menyelesaikan transaksinya
  sendiri lewat workflow Kasir/POS — bukan lewat halaman Daftar/Detail.)
- JS dipecah: `ubahStatus()` (untuk Batal, tidak berubah) vs
  `selesaikanTransaksi(id, statusPembayaran)` (khusus Selesai) —
  kalau `lunas`, tampil `confirm()` peringatan garapan sebelum kirim;
  kalau belum lunas, langsung kirim dan biarkan pesan penolakan
  spesifik dari backend (P8) tampil lewat `showToast()` yang sudah
  ada (tidak ada duplikasi pesan error di frontend).

File: `transaksi/index.php`, `transaksi/detail.php`.

## P10 — Filter Status Transaksi eksplisit (2026-09-05)

**Masalah:** filter Status Transaksi di `transaksi/index.php` cuma
punya 2 pilihan ambigu: `Aktif` (proses+selesai) dan `Batal`.

**Solusi:** diganti 4 pilihan eksplisit sesuai nilai database:
**Semua / Proses / Selesai / Batal**. Urutan filter dirapikan jadi
Tanggal → Status Transaksi → Status Pembayaran → Pelanggan.

**Keputusan default yang disengaja:** default filter (termasuk saat
klik Reset) berubah dari "proses+selesai, exclude batal" menjadi
**Semua** (tidak difilter) — konsisten dengan pola `Semua` yang sudah
dipakai Status Pembayaran. Value lama `status_transaksi=aktif` **tetap
didukung di backend** untuk backward-compat link lama, hanya tidak
lagi ditawarkan sebagai pilihan di UI.

**Kombinasi penting yang jadi mungkin:** `Proses + Lunas` — daftar
kerja admin untuk transaksi yang sudah lunas tapi belum ditandai
Selesai (persis kasus yang dijaga P8 di atas).

File: `transaksi/index.php`, `Transaksi.php` (controller).

## P11 — Fitur Pelunasan Terlambat / Backdate (2026-09-05)

Fitur besar: Admin bisa mencatat pembayaran dengan **tanggal berbeda
dari sekarang** (uang sudah diterima sebelumnya, baru dicatat
belakangan) dan memilih **kasir penerima** yang sebenarnya menangani,
tanpa membuat modal/flow pembayaran baru — cukup checkbox opsional
"Pembayaran diterima sebelumnya" di modal Tunai/DP/Konfirmasi
(QRIS/Transfer) yang sudah ada.

**Arti field pembayaran (ditegaskan, tidak diubah):**
- `tanggal` = kapan uang **benar-benar diterima**.
- `kasir_id` = kasir yang **benar-benar menangani** (bukan otomatis
  jadi Admin yang menginput).
- `created_at` = kapan record dibuat di sistem — **selalu** diisi DB
  (`PembayaranModel::$useTimestamps = false`), tidak pernah disentuh
  kode aplikasi, di skenario normal maupun backdate.

**Validasi backend (`TransaksiModel::tambahPembayaran()`, dipanggil
dari `Api::tambahPembayaran()` dan `Tagihan::lunasi()`):**
- tanggal pembayaran dianggap "backdate" kalau berbeda >60 detik dari
  waktu server saat itu (tanpa perlu flag terpisah);
- backdate **wajib admin** — kasir yang mencoba mengirim tanggal
  manual tetap ditolak backend meski lolos UI (defense in depth,
  sama pola dengan P8);
- tanggal tidak boleh **sebelum tanggal transaksi** (dibandingkan di
  level **tanggal/hari saja**, bukan jam — lihat catatan bug-fix di
  bawah) dan tidak boleh **di masa depan** (dibandingkan penuh sampai
  jam, sesuai kata dokumen asli "tanggal/waktu sekarang").

**Sengaja dikecualikan dari scope:** pembayaran awal transaksi BARU
(`kasir/index.php`) — karena `transaksi.tanggal` transaksi baru selalu
"sekarang", sehingga validasi "tidak boleh sebelum tanggal transaksi"
akan langsung konflik. Backdate hanya berlaku untuk pembayaran atas
transaksi yang **sudah ada** (Bayar Sekarang / Tagihan → Lunasi).

**Efek samping yang sudah benar tanpa kode tambahan:**
`CashBalanceService::getCashSales()` sudah filter berdasarkan
`pembayaran.tanggal`, dan semua query laporan (`Laporan.php`) sudah
pakai `pembayaran.tanggal` bukan `created_at` — begitu backdate
aktif, uang otomatis "jatuh" ke tanggal yang benar di kas & laporan
tanpa perubahan kode di area itu.

**Risiko yang didokumentasikan (bukan bug, konsekuensi inheren):**
kalau `cash_opname` untuk suatu tanggal **sudah** dilakukan sebelum
ada pembayaran yang di-backdate ke tanggal itu, rekonsiliasi
retroaktif bisa berbeda dari hasil opname saat itu. Tidak dibuatkan
mekanisme koreksi otomatis (di luar scope — tidak boleh bikin
cash_opname/cash_expense baru).

**Endpoint baru:** `GET /api/kasir-list` (admin-only) untuk dropdown
"Kasir Penerima".

**Histori pembayaran** (`transaksi/detail.php`) sekarang menampilkan
nama kasir penerima + badge "Dicatat belakangan" (murni tampilan,
dihitung dari selisih `tanggal` vs `created_at` >5 menit, tidak
disimpan di database, tidak memengaruhi kalkulasi apa pun).

File: `TransaksiModel.php`, `Api.php`, `Tagihan.php`, `Transaksi.php`,
`Routes.php`, `components/payment/modal.php`, `payment.js`,
`transaksi/index.php`, `transaksi/detail.php`, `tagihan/index.php`.

Pengujian: 17 skenario standalone lulus (15 acceptance test dokumen +
2 regresi tambahan).

## P11-fix — Backdate: perbandingan tanggal vs timestamp (2026-09-05)

**Bug ditemukan saat testing manual:** validasi "tidak boleh sebelum
tanggal transaksi" awalnya membandingkan **timestamp lengkap**
(tanggal+jam), bukan cuma tanggal. Akibatnya, backdate ke hari yang
SAMA dengan transaksi tapi jam lebih awal (mis. transaksi tercatat
jam 14:00, pembayaran di-backdate ke jam 05:40 di hari yang sama)
salah ditolak — padahal ini justru skenario utama fitur ini (uang
diterima lebih pagi, transaksi baru diinput belakangan di hari yang
sama).

**Fix:** perbandingan batas bawah diubah ke level **tanggal (hari)**
saja, bukan timestamp penuh. Batas atas ("tidak boleh masa depan")
**tetap** presisi jam, karena dokumen asli eksplisit menyebut
"tanggal/waktu sekarang" untuk itu.

**Implikasi yang sudah dicek:** tidak berdampak ke pembayaran normal
(selalu "sekarang", tidak pernah masuk cabang backdate), tidak
berdampak ke `simpanTransaksi()`/`koreksiPembayaran()` (selalu kirim
`tanggal=now()`), tidak berdampak ke rule SELESAI (file berbeda).
Efek yang memang disengaja: kas real-time (`CashBalanceService`)
sekarang bisa menghitung pembayaran backdate jam pagi masuk ke
snapshot pagi hari yang sama — sebelumnya malah tidak bisa tersimpan
sama sekali.

File: `TransaksiModel.php` (satu method, `tambahPembayaran()`).

## P12 — Perbaikan UI kecil (2026-09-05)

- Tombol "Selesai Dikerjakan" di `transaksi/detail.php`: warna diganti
  dari kuning (`btn-warning`, sama dengan tombol Edit) jadi biru
  (`btn-primary`), supaya tidak tertukar visual dengan tombol Edit.
- Riwayat Pembayaran: nama kasir dan keterangan (mis. "Lunas", "DP
  (Rp 20.000)") dipisah dengan " · " supaya tidak terbaca menyatu
  jadi satu nama.

File: `transaksi/detail.php`. Murni kosmetik, tidak ada logic yang
berubah.

## P13 — Restrukturisasi aksi di daftar transaksi (2026-09-05)

Atas permintaan eksplisit: baris tabel `transaksi/index.php` tidak
lagi bisa diklik untuk ke halaman detail (listener JS dihapus).
Tombol Edit (kuning, pensil) di kolom Aksi **dihapus**, digantikan
tombol Lihat Detail (`btn-info`, ikon mata, selalu tampil untuk semua
status) — pola yang sama seperti yang sudah dipakai di
`tagihan/index.php`. Tombol Edit yang sesungguhnya tetap ada di
halaman detail (tidak diubah) — jadi alur edit sekarang wajib lewat
halaman detail dulu.

File: `transaksi/index.php`.

## P14 — Pisah Aksi Khusus dari daftar produk di Kasir (2026-09-05)

Di halaman Kasir Baru & Edit Transaksi, kartu "Banner", "Manual
Input", "Ukuran Custom" sebelumnya dirender **di dalam** `#produkList`
sehingga ikut kena filter kategori/pencarian produk (meski secara
data sebenarnya sudah terpisah dari `SEMUA_PRODUK_KASIR`).
Direstrukturisasi: ketiga kartu dipindah ke section terpisah
"Aksi Khusus" di atas kotak pencarian, `#produkList` sekarang murni
hasil render JS dari data produk. Fungsi ketiga tombol (`bukaModalBanner()`,
`showManualInput()`, `showModalCustomSize()`) **tidak diubah sama
sekali** — hanya lokasi/strukturnya di DOM.

File: `kasir/index.php`, `kasir/edit.php` (treatment identik, kedua
file memang byte-identik untuk bagian ini), `kasir-shared.js`
(`renderProdukKasir()` disederhanakan, tidak perlu lagi
"preserve" kartu spesial saat render ulang).

## P15 — Kapabilitas SELESAI dari workflow Kasir/POS (2026-09-10, Phase 2)

**Konteks:** setelah P8/P9 mengunci `proses → selesai` ke admin, semua
transaksi tetap harus dituntaskan admin — termasuk transaksi POS yang
dibuat kasir sendiri dan sudah lunas di tempat. Diputuskan memberi
kasir kapabilitas terbatas untuk kasus itu **tanpa** melonggarkan
jalur status umum.

**Aturan final:** lihat **Section 4.2**. Ringkas: kasir boleh
menyelesaikan transaksi hanya lewat endpoint POS
`/api/kasir/selesaikan-transaksi`, untuk transaksi `sumber='kasir_pos'`
+ `kasir_id === id_user` + `status='proses'` + `status_pembayaran='lunas'`.
Jalur umum (`/api/ubah-status`, Daftar/Detail) tidak berubah — kasir
tetap ditolak.

**Enforcement:** `TransaksiModel::ubahStatus()` dapat parameter
kapabilitas-konteks yang melewati gate "harus admin" tetapi **tidak**
melewati syarat `lunas`. Cek role/kepemilikan/`sumber` ada di
controller endpoint. Bukan disembunyikan di UI — panggilan langsung ke
endpoint tetap tunduk semua cek.

**Tidak mengubah** business rule Section 2/3/4.1: status transaksi &
pembayaran tetap 3 nilai masing-masing; `lunas` tetap bukan `selesai`
otomatis; `belum_bayar`/`dp` tetap tidak boleh jadi `selesai`.

File: `TransaksiModel.php`, `Api.php`, `Routes.php`, `kasir/index.php`.

---

# 20. Modul Jadwal Karyawan (2026-09-05, domain baru — terpisah dari transaksi/kasir)

> Modul ini **independen** dari Section 1-19 di atas. Tidak ada
> irisan tabel, tidak ada irisan business rule, dan sengaja
> dipisah code-path-nya (lihat 20.6) supaya perubahan di modul ini
> tidak pernah menyentuh transaksi/kasir dan sebaliknya.

## 20.1 Tujuan & 4 mode

**Jadwal Karyawan** (`/jadwal`, admin-only) punya 4 mode dalam satu
halaman, default **Matrix**:
- **Matrix** — roster mingguan (Karyawan × Sen-Min), tampilan
  operasional utama, klik cell untuk lihat/edit/tambah/hapus.
- **Kalender** — FullCalendar, detail jam per shift.
- **Master Jadwal** — template mingguan yang bisa diterapkan
  (apply) ke beberapa minggu ke depan.
- **Analisis** — kombinasi karyawan yang pernah/belum pernah bekerja
  bersama.

## 20.2 Business rule shift

```text
P  = Pagi   08:00-15:00
S  = Siang  13:30-20:30
PM = PM     08:00-12:30 & 18:00-20:30 (SATU row, dua sesi)
L  = Libur eksplisit (row ada, shift='L')
-  = BELUM DIJADWALKAN (tidak ada row sama sekali)
```

Aturan mutlak: **tidak ada row ≠ Libur**. `-` tidak pernah diubah
jadi `L` di mana pun (Matrix, Master, Analisis, Statistik).

**PM = satu business schedule**, bukan dua shift terpisah — ditegaskan
di seluruh operasi: create/edit/delete/swap PM semuanya memperlakukan
satu row PM sebagai satu paket. (Ini **sengaja berbeda** dari AULIA
LAMA yang menyimpan PM sebagai 2 row terpisah dengan jam_masuk/keluar
masing-masing — di skema baru jam tidak disimpan sama sekali, selalu
dihitung dari `JadwalModel::DEFINISI_SHIFT`.)

**Tidak ada batas jumlah hari kerja** (tidak ada validasi "maksimal N
hari", "wajib libur", dst). Statistik hanya informasi, PM dihitung 1
hari kerja meski 2 sesi.

**Jadwal ≠ Absensi.** Modul ini planned schedule, tidak ada tabel
attendance/clock-in-out/payroll. `P/S/PM` tidak berarti "pasti
hadir", `L` tidak berarti "pasti tidak bekerja" — itu urusan modul
lain di luar scope ini.

## 20.3 Database

- `users` +`is_active TINYINT(1) DEFAULT 1` — aktif = bisa dipilih
  untuk schedule baru; nonaktif = tidak bisa dipilih baru, TAPI tetap
  tampil di histori kalau punya row jadwal lama. **Bukan pengganti
  role.**
- Tabel baru `jadwal`: `id, karyawan_id, tanggal, shift ENUM('P','S','PM','L'), created_at, updated_at`.
  `UNIQUE(karyawan_id, tanggal)` — satu karyawan satu schedule per
  tanggal. FK ke `users.id` **tanpa** `ON DELETE CASCADE` (histori
  jadwal harus mencegah physical delete user yang masih punya
  histori).
  **Catatan schema penting:** `karyawan_id` harus `INT(11)` **signed**
  (bukan `UNSIGNED`) karena `users.id` di database asli signed —
  mismatch signedness pernah menyebabkan MySQL errno 150 "foreign key
  constraint incorrectly formed" saat migration pertama kali dijalankan.
- Tabel baru `master_jadwal` (header template) + `master_jadwal_detail`
  (`master_jadwal_id, karyawan_id, hari 1-7, shift`). Detail
  `ON DELETE CASCADE` ke master (bukan histori, aman ikut terhapus);
  ke `users` tanpa cascade (konsisten dengan `jadwal`).
- Migration idempotent (cek `tableExists`/`fieldExists` dulu). Raw SQL
  alternatif disediakan terpisah kalau migration CI4 tidak bisa jalan.

## 20.4 Aturan create/edit/swap

- **Create/Edit manual**: employee picker HANYA `is_active=1` untuk
  schedule baru. Edit manual **tidak dibatasi divisi** — admin bebas
  ubah schedule siapa saja ke shift apa saja.
- **Swap**: WAJIB divisi sama, divalidasi **di backend**
  (`Jadwal::swap()`), bukan cuma di JS.

  **Desain (revisi 2026-09-05 setelah bug ditemukan saat testing):**
  yang ditukar adalah **NILAI SHIFT** dua baris, bukan kepemilikan
  baris (`karyawan_id`/`tanggal` tiap baris tidak pernah berubah) —
  supaya tidak mungkin bentrok `unique(karyawan_id,tanggal)`, berapa
  pun lengkapnya jadwal kedua karyawan (lihat 20.9 untuk kronologi
  bug awalnya). Kalau kedua shift sama, ditolak ("tidak ada yang
  perlu ditukar").

  **Cabang khusus: tukar Libur** (kedua cell sama-sama `L`, karyawan
  beda) — sekadar tukar label `L`↔`L` tidak ada efeknya, jadi
  perlakuannya beda: hari libur direlokasi (libur A pindah ke
  tanggal B, libur B pindah ke tanggal A), dan masing-masing
  mengambil alih shift kerja yang tadinya dikerjakan lawannya di
  tanggal itu — diimplementasikan sebagai tukar nilai shift pada 4
  baris yang **sudah ada** (butuh kedua karyawan sudah punya
  schedule kerja di tanggal masing-masing lawan; kalau salah satu
  belum dijadwalkan atau juga libur di sana, ditolak dengan pesan
  jelas). Konsep ini diadaptasi dari AULIA LAMA (Section 18), tapi
  implementasinya ditulis ulang total untuk skema baru yang punya
  unique constraint (AULIA LAMA tidak punya constraint ini sama
  sekali — makanya PM di sana bisa 2 row).

  PM di-swap sebagai satu paket secara otomatis (karena memang cuma
  satu row, nilai string `'PM'` dipindah utuh).
- **Delete range**: admin-only (dicek eksplisit di controller,
  defense-in-depth di atas admin-only-nya seluruh modul), hanya
  menghapus `jadwal` (actual), tidak pernah menyentuh `master_jadwal`.

## 20.5 Master Jadwal & Apply

Master adalah **template**, bukan actual schedule — hidup independen:
edit master tidak mengubah `jadwal` yang sudah di-apply sebelumnya,
dan edit `jadwal` aktual tidak mengubah master. Master membedakan
`L` (eksplisit) dari kosong (tidak ada assignment) — sama seperti
aturan `-` di actual schedule.

**Apply master** ke N minggu ke depan, default behavior **"isi slot
kosong saja"** — kalau actual schedule sudah ada di tanggal tsb,
TIDAK ditimpa, malah dilaporkan sebagai conflict (tanggal, karyawan,
shift master vs shift existing). Overwrite eksplisit tersedia sebagai
opsi terpisah (checkbox), dengan confirmation, tidak pernah diam-diam.

## 20.6 Role / Authorization — pemisahan prefix URL

Mutation (`/jadwal/simpan`, `/hapus`, `/hapus-range`, `/swap`,
`/master/*`) admin-only lewat `AuthFilter::$adminRoutes` (ditambahkan
entri `'jadwal'`, mekanisme yang **sudah ada**, tidak bikin sistem
permission baru).

**Keputusan desain penting:** endpoint read-only untuk Kasir (roster)
sengaja diberi **prefix URL berbeda** (`/roster/*`, bukan
`/jadwal/*`) supaya otomatis tidak ikut ter-blok oleh
`$adminRoutes` yang match berdasarkan prefix string. Ini menghindari
perlunya mengubah logic matching `AuthFilter` yang sudah melindungi
Admin — jadi regresi ke Admin risikonya nol. Endpoint `/roster/*`
cukup filter `auth` biasa (login saja, role apa pun).

## 20.7 Fitur Kasir — Roster read-only

Halaman `/roster` (menu sidebar "Jadwal Karyawan" untuk role kasir,
terpisah dari cabang menu admin yang tetap ke `/jadwal`):
- **Ringkasan Hari Ini**: dikelompokkan per shift (Pagi/Siang/PM/Libur/
  Belum Dijadwalkan), karyawan yang sedang login ditandai ★.
  "Belum Dijadwalkan" dihitung dari karyawan aktif yang tidak punya
  row hari itu — **bukan** dari row `shift='L'`.
- **Mingguan** & **Bulanan** — read-only, filter divisi/shift/search,
  histori karyawan nonaktif tetap tampil kalau punya jadwal di
  periode itu.
- Backend reuse method `JadwalModel` yang sama dipakai Matrix Admin
  (tidak ada SQL/logic yang diduplikasi) — hanya orkestrasi controller
  tambahan di prefix `/roster`.
- JS terpisah (`roster.js`, bukan `jadwal.js`) — kasir tidak memuat
  kode mutasi admin sama sekali di browser-nya.

**Validasi jadwal user login** (`GET /roster/status-saya`, polling 60
detik, reuse pola `updateBadgeTagihan` yang sudah ada + `showToast()`
existing — tidak ada notification framework baru):
- Query RINGAN — satu baris (`karyawan_id` + tanggal hari ini),
  **bukan** seluruh roster.
- Dihitung **di server (PHP)**, bukan browser JS — pelajaran dari bug
  timezone di 20.9.
- Status: `sesuai` / `belum_masuk` / `lewat` / `jeda` (khusus jeda PM
  12:30-18:00) / `libur` / `belum_dijadwalkan`.

> **INI MURNI INFORMASI, BUKAN OTORISASI.** Tidak pernah, di mana pun,
> dipakai untuk memblokir transaksi. Libur atau belum dijadwalkan
> hanya memicu toast, transaksi kasir tetap normal 100% di semua
> kondisi.

## 20.8 Fitur sort tabel

Header tabel Matrix (Admin) dan Mingguan (Kasir) bisa diklik untuk
sort, murni client-side (data yang sudah dimuat, tidak fetch ulang):
- Klik "Karyawan" → sort per **divisi** (kosong selalu di bawah).
- Klik nama hari → sort per **shift hari itu**, urutan tetap
  P → S → PM → L, tanpa-jadwal (`-`) selalu di bawah.
- Klik ulang → toggle asc/desc. Preferensi sort bertahan saat
  navigasi minggu (tidak reset).
- Logic sort di-duplikasi kecil antara `jadwal.js` dan `roster.js`
  (bukan business logic/SQL, cuma fungsi comparator JS) — konsekuensi
  dari keputusan 20.7 memisahkan JS admin vs kasir.

## 20.9 Bug yang pernah terjadi (dicatat supaya tidak terulang)

1. **FK errno 150** — `jadwal.karyawan_id`/`master_jadwal_detail.karyawan_id`
   sempat didefinisikan `UNSIGNED` padahal `users.id` asli signed.
   MySQL/MariaDB mewajibkan tipe identik persis (termasuk signedness)
   untuk foreign key. Fix: hapus `unsigned` dari kedua kolom tsb.
2. **Timezone shift 1 hari** — fungsi `tanggalPlus()` di JS
   (dipakai untuk navigasi minggu DAN lookup tanggal tiap kolom
   Matrix) sempat pakai `.toISOString()` untuk membentuk string
   tanggal. `toISOString()` mengonversi ke UTC; di WIB (UTC+7) tengah
   malam lokal jatuh ke tanggal sebelumnya saat dikonversi —
   menyebabkan seluruh kolom Matrix salah lookup tanggal (mundur 1
   hari) dan navigasi minggu cuma maju 6 hari, bukan 7. Fix: hitung
   tanggal murni pakai komponen lokal (`getFullYear`/`getMonth`/
   `getDate`), tidak pernah menyentuh representasi UTC. **Pelajaran
   berlaku umum:** semua perhitungan tanggal/jam yang bisa
   memengaruhi query atau tampilan HARUS di server (PHP,
   `appTimezone` sudah `Asia/Jakarta`), bukan di browser JS.
3. **Swap salah desain: tukar kepemilikan baris, bukan nilai shift**
   — implementasi awal `Jadwal::swap()` menukar `karyawan_id` antar
   dua baris. Begitu Master Jadwal diterapkan (setiap karyawan punya
   baris di ke-7 hari), hampir semua swap lintas tanggal gagal false-
   positive "bentrok" — karena tiap karyawan sudah pasti punya baris
   sendiri di tanggal tujuan lawannya. Fix: desain diubah total jadi
   menukar **nilai shift** saja (`karyawan_id`/`tanggal` tiap baris
   tidak pernah berubah), plus ditambahkan cabang khusus untuk tukar
   Libur (lihat 20.4) yang butuh menyentuh 4 baris sekaligus. Setelah
   fix ini ditemukan lagi sub-kasus: tukar Libur↔Libur murni dengan
   desain "tukar nilai shift" adalah no-op (L tetap L) — makanya
   dibuatkan cabang `swapLibur()` terpisah yang benar-benar
   merelokasi hari libur, bukan sekadar menukar label.

## 20.10 File-file modul ini

Migration: `2026-09-05-000001_AddIsActiveToUsers.php`,
`...-000002_CreateJadwalTable.php`, `...-000003_CreateMasterJadwalTables.php`,
`jadwal_module_raw.sql`. Model: `JadwalModel.php`, `MasterJadwalModel.php`.
Controller: `Jadwal.php` (satu file untuk admin + roster kasir).
View: `jadwal/index.php`, `roster/index.php`. JS: `jadwal.js`, `roster.js`.
Diubah: `Routes.php`, `Filters/AuthFilter.php`, `Models/UserModel.php`,
`Views/layout/main.php`.

## 20.11 Pekerjaan terbuka — Modul Jadwal

- Belum ada test end-to-end dengan DB sungguhan (sandbox pengembangan
  tidak punya MySQL) — seluruh pengujian sejauh ini adalah logic
  murni (standalone, tanpa DB) untuk algoritma paling berisiko
  (validasi waktu shift, grouping hari-ini, pairing analisis, offset
  tanggal apply-master, sort comparator).
- ~~UI edit cell di tab Master Jadwal masih pakai `prompt()` browser
  (placeholder kasar), belum modal proper seperti di Matrix.~~ Sudah
  diganti modal (`modalMasterCell`, 2026-09-11) — pola sama dengan
  `modalCell` di Matrix (select shift + tombol Hapus terpisah,
  bukan input teks kosong).
- Staffing conflict (configurable, warning-only, bukan hard block)
  ada di requirement tapi **belum diimplementasikan** — perlu sesi
  terpisah kalau mau dilanjutkan.
- Ada baris karyawan dengan `nama`/`inisial` kosong yang muncul di
  Matrix maupun Master (terlihat di screenshot testing) — kemungkinan
  data lama di tabel `users`, perlu dicek manual ke database, belum
  ada tindakan (menunggu konfirmasi apakah data asli atau perlu
  dibersihkan).

---

# 21. Housekeeping — Dead Code (2026-09-05)

Dikonfirmasi via audit (grep referensi + cek routing) bahwa 3 file
berikut **tidak dipakai di mana pun** dan aman dihapus:

| File | Bukti tidak terpakai | Status |
|---|---|---|
| `app/Controllers/LaporanPenjualan.php` | Tidak ada route yang mengarah ke sana di `Routes.php`; `$autoRoute = false` di `Config/Routing.php` sehingga tidak mungkin ke-hit lewat URL konvensi otomatis CI4. | **Sudah dihapus.** |
| `public/assets/js/banner.js` | File 0 byte (kosong), tidak direferensikan `<script src>` di view manapun. | Sudah tidak ada di repo (tidak tercatat di git history sama sekali -- kemungkinan sudah dihapus sebelum baseline `d90aa23`). |
| `public/js/modal_banner.js` | File 0 byte (kosong), tidak direferensikan di view manapun. | Sudah tidak ada di repo (tidak tercatat di git history sama sekali -- kemungkinan sudah dihapus sebelum baseline `d90aa23`). |

Fitur Banner di Kasir **tidak terpengaruh** — logic-nya ada inline di
`app/Views/kasir/modal_banner.php` (`bukaModalBanner()`,
`kelolaBanner()`), bukan di kedua file JS kosong di atas.

Ketiga item di atas sudah selesai (tereksekusi atau memang sudah
tidak ada). Housekeeping lanjutan (removal `LaporanTest.php` dan
view cetak lama) dicatat terpisah di riwayat commit, bukan di
section ini.

---

# 22. Pekerjaan terbuka (Transaksi/Kasir)

Tidak ada pekerjaan terbuka terkait lifecycle status transaksi saat
dokumen ini ditulis. Lihat Section 19 (P8-P14) untuk status
implementasi terakhir.

Area terkait yang masih terbuka untuk pembahasan terpisah:
- Status `diambil`/"siap diambil" untuk penanganan barang yang belum
  diambil pelanggan (lihat Section 14) — sengaja belum
  diimplementasikan pada perubahan ini.
- Role SPV, jika suatu saat dibutuhkan selain admin/kasir.
- Cleanup dead code: lihat Section 21 (Housekeeping).
- Cetak Nota langsung ke Epson L3210 — **ditahan (belum dikerjakan)**,
  lihat Section 22.1.

## 22.1 Cetak Nota langsung ke Epson L3210 (rencana 2026-09-05, terealisasi -- lihat Section 30)

**Masalah:** Tombol "Nota" saat ini (`Cetak::index()`) hanya membuka
window baru berisi HTML nota (`cetak/nota.php`) — user harus cetak
manual lewat dialog print browser. Ini beda dari tombol "Thermal"
yang sudah cetak langsung otomatis (server kirim ESC/POS ke share
`smb://guest@aulia6/POS-58`). Diminta: Nota juga bisa "langsung
cetak" ke printer inkjet **Epson L3210** yang di-share di jaringan
lokal dari satu komputer (server aplikasi jalan di Windows).

**Kendala yang sudah dikonfirmasi:** browser **tidak bisa** mendeteksi
atau memilih printer OS secara otomatis/diam-diam (dibatasi browser,
bukan keterbatasan kode) — jadi solusi murni client-side tidak bisa
mencapai "langsung cetak tanpa dialog".

**Opsi yang dipertimbangkan:**
- **Opsi A (dipilih):** server generate PDF nota (Dompdf, A6
  landscape — generator final ditulis sebagai
  `Cetak::generateNotaPdfBinary()`) lalu kirim langsung ke share
  Epson L3210 pakai tool cetak PDF command-line (mis. SumatraPDF),
  pola yang sama persis dengan `Cetak::thermal()` yang sudah jalan.
  Konsisten di semua komputer kasir, tidak butuh setting per-browser.
- Opsi B (tidak dipilih): `window.print()` + CSS `@page` A6
  landscape + Chrome dijalankan dengan flag `--kiosk-printing` di
  tiap komputer kasir. Ditolak karena bergantung pada konfigurasi
  manual per-komputer (rawan human error, tidak scalable kalau nambah
  komputer kasir baru).

**Yang masih dibutuhkan sebelum implementasi Opsi A:**
- Nama/alamat share network printer Epson L3210 (format
  `\\NamaKomputer\NamaPrinter`).
- Pastikan SumatraPDF (atau tool sejenis) ter-install di komputer
  yang bertindak sebagai print server, dan akun yang menjalankan
  PHP/web server punya akses ke share printer tersebut.

**Status:** Sudah diimplementasikan — lihat Section 30 untuk desain
dan flow final (`Cetak::notaLangsung()` -> `generateNotaPdfBinary()`
-> `kirimPdfKePrinter()`).

---

# 23. Security/hardening — scope terpisah

Potensi hardening yang sudah teridentifikasi tetapi belum otomatis masuk perubahan lifecycle:
- whitelist metode pembayaran pada endpoint tertentu;
- validasi nilai cash/kembalian dari client;
- potensi injection pada inline `onclick`;
- authorization dan identitas user/kasir;
- CSRF;
- concurrency/audit log.

Area ini sebaiknya dikerjakan sebagai scope terpisah agar perubahan lifecycle tidak bercampur dengan hardening keamanan.

---

# 24. Fitur Profil Saya + Pengelolaan Profil oleh Admin (2026-09-06)

## 24.1 Scope

Dua area terpisah, keduanya di atas tabel `users` yang sudah ada
(tidak ada tabel `profile` baru):

- **Profil Saya** (`/profil`) — semua role yang login (admin & kasir)
  bisa ubah **nama**, **no. HP**, dan **foto profil** milik diri
  sendiri. Field `username`, `inisial`, `divisi`, `role`, `is_active`
  read-only di halaman ini.
- **Manajemen User** (`/user-management`, `Auth` controller) — admin
  tetap pakai halaman yang sama seperti sebelumnya, sekarang
  diperluas supaya bisa mengelola nama, no. HP, foto, username,
  inisial, divisi, role, dan status aktif milik user lain.

## 24.2 Database

Kolom baru di tabel `users` (migration
`2026-09-06-000001_AddProfileFieldsToUsers`), keduanya nullable
(aman untuk user lama):
- `no_hp` VARCHAR(20)
- `profile_photo` VARCHAR(255) — nama file saja, bukan path lengkap,
  bukan binary.

## 24.3 Penyimpanan foto

File fisik disimpan di `WRITEPATH/uploads/foto_profil/`, **di luar**
docroot publik (`public/`) — pola yang sama dengan upload CSV
maintenance produk yang sudah ada sebelumnya. Ini memastikan file
yang diupload user tidak pernah bisa dieksekusi sebagai PHP oleh
webserver, terlepas dari konfigurasi `.htaccess`.

Nama file **selalu** dibuat server (`random_bytes` hex + ekstensi
dari MIME tervalidasi), tidak pernah nama file asli dari user.
Validasi upload: ukuran maks 2MB, MIME whitelist (jpeg/png/webp),
dan `getimagesize()` untuk memastikan isi file benar-benar gambar
(bukan cuma header MIME yang dipalsukan). Logic ini ada di satu
tempat (`App\Libraries\FotoProfilService`) dan dipakai ulang oleh
Profil Saya maupun Manajemen User — tidak diduplikasi.

Foto ditampilkan lewat `GET /foto-profil/{filename}`
(`Profil::foto()`) yang men-stream file dari disk, bukan lewat akses
statis langsung. Nama file divalidasi ketat dengan regex sebelum
dipakai untuk resolve path (cegah path traversal).

Saat foto diganti/dihapus (baik oleh user sendiri maupun admin),
file lama dibersihkan dari disk setelah update database berhasil,
supaya tidak ada orphan file.

## 24.4 Otorisasi — aturan paling penting

`Profil::index()`, `update()`, `uploadFoto()`, `hapusFoto()` di
`app/Controllers/Profil.php` **selalu** memakai
`session()->get('id_user')` sebagai target perubahan. Tidak satu pun
endpoint ini membaca `user_id`/ID target dari form/request. Dengan
begitu, user A tidak bisa mengubah profil user B walau memanipulasi
request.

`UserModel::updateProfilSaya()` juga sengaja hanya menerima parameter
`nama` dan `no_hp` (bukan array bebas) sebagai pertahanan kedua di
level model — field lain (role, is_active, dst) secara struktural
tidak mungkin lewat lewat method ini, terlepas dari apa pun yang
dikirim controller.

Manajemen User (admin) tetap pakai `$id` dari parameter route seperti
sebelumnya — itu memang wewenang admin untuk kelola user lain.
Ditambahkan proteksi baru: admin tidak bisa mengubah role akun
sendiri menjadi non-admin atau menonaktifkan akun sendiri (mencegah
mengunci diri sendiri keluar dari akses admin).

## 24.5 Integrasi header/layout

Header (`app/Views/layout/main.php`) menampilkan foto profil (jika
ada) di avatar dropdown, plus link baru "Profil Saya". Foto diambil
dengan query langsung ke `UserModel` di dalam view (by primary key,
query kecil) — **sengaja tidak disimpan salinannya ke session**,
supaya begitu foto diganti/dihapus, tampilan header langsung
mencerminkan perubahan tanpa perlu logout/login ulang.

## 24.6 Kompatibilitas

- Tidak mengubah cara Jadwal Karyawan membaca `nama`, `inisial`,
  `divisi`, `is_active` dari tabel `users` — hanya kolom baru yang
  ditambahkan, kolom existing tidak disentuh strukturnya.
- Route baru (`/profil`, `/foto-profil/*`) sengaja pakai prefix yang
  tidak match daftar `AuthFilter::$adminRoutes`, supaya kasir juga
  bisa akses halaman profilnya sendiri.
- CRUD Manajemen User (`Auth::simpanUser`/`updateUser`) yang sudah
  ada sebelumnya hanya menangani `username`/`password`/`role` —
  diperluas di perubahan ini untuk benar-benar mendukung
  nama/inisial/divisi/no_hp/is_active/foto sesuai kebutuhan admin,
  bukan modul baru.

---

# 25. Notifikasi, Konfirmasi & Reminder Tagihan (2026-09-06)

## 25.1 Latar belakang

Sebelum perubahan ini, aplikasi punya 5 gaya notifikasi berbeda yang
tidak seragam: toast (Bootstrap Toast), alert box dari flashdata,
`alert()`/`confirm()` bawaan browser, dan info box statis. Perubahan
ini menyeragamkan 3 dari 5 kategori tsb (notifikasi hasil-aksi,
konfirmasi aksi berbahaya) dan menambah 1 fitur baru (reminder
tagihan). Info box statis (kategori ke-5, misal keterangan role di
form user) sengaja tidak diubah — itu memang bukan notifikasi
transient.

## 25.2 Bug flashdata dobel — diperbaiki

Sebelumnya, flashdata `success`/`error` dirender **dua kali** di ~15
halaman: sekali secara global di `layout/main.php`, sekali lagi
manual di masing-masing file view. Semua render lokal yang duplikat
sudah dihapus — sekarang hanya ada **satu** titik render (lihat
25.3). Flashdata `errors` (jamak, untuk daftar error validasi
per-field) **tidak termasuk** bug ini — itu memang beda tujuan dan
tetap dipertahankan apa adanya.

## 25.3 Alert box → Toast

Alert box (`<div class="alert-success">`/`alert-danger"` dari
`session()->getFlashdata()`) dihapus total. Sekarang di
`layout/main.php`, begitu ada flashdata `success`/`error`, langsung
dipanggil `showToast()` lewat `<script>` inline (pesan di-escape
dengan `json_encode()`, bukan `esc()`, karena konteksnya JS string
bukan HTML). Ini membuat **semua** notifikasi hasil-aksi (baik dari
redirect biasa maupun dari AJAX) konsisten lewat satu komponen toast
yang sama.

`auth/login.php` adalah pengecualian yang disengaja — halaman itu
tidak memakai `layout/main.php` (belum ada sesi login untuk
menampilkan header/toast container), jadi tetap pakai alert box
biasa.

## 25.4 Toast: durasi per tipe & posisi baru

`showToast(message, type, opsi)` di `layout/main.php` sekarang:
- **Durasi berbeda per tipe**: sukses/info 3 detik, warning 4 detik,
  error/danger **sticky** (tidak auto-hilang, harus di-close manual
  lewat tombol × yang sudah ada di markup toast).
- **Posisi**: pindah dari bawah-kanan ke **atas-tengah**
  (`position-fixed top-0 start-50 translate-middle-x`).
- **Opsi `onClick`** (baru): parameter ketiga opsional
  `{ onClick: fn }` membuat toast bisa diklik dan memanggil `fn`.
  Dipakai oleh reminder tagihan (lihat 25.6) untuk membuka halaman
  Tagihan saat toast-nya diklik.

Catatan desain: sistem toast ini masih **satu instance tunggal**
(`#liveToast`), belum mendukung stacking banyak toast sekaligus.
Kalau dua toast dipanggil nyaris bersamaan (misal flashdata + toast
lain di halaman yang sama), yang kedua akan menimpa yang pertama.
Ini keterbatasan lama yang sudah ada sebelum perubahan ini, dan
kasusnya jarang terjadi bersamaan — tidak diperbaiki di perubahan
ini karena di luar scope yang disepakati.

## 25.5 Konfirmasi "Batalkan" transaksi — dari 2x jadi 1x

Sebelumnya tombol "Batalkan" di `transaksi/detail.php` memicu **dua**
modal konfirmasi berturutan: konfirmasi spesifik ("Yakin ingin
membatalkan transaksi ini?") lalu konfirmasi generik dari dalam
`ubahStatus()` ("Ubah status jadi BATAL?"). Sekarang tombol ini
memanggil `kirimUbahStatusAjax()` langsung setelah 1 konfirmasi,
melewati `ubahStatus()` sepenuhnya. Fungsi `ubahStatus()` di file ini
jadi tidak terpakai lagi (dead code, sengaja tidak dihapus di
perubahan ini — di luar scope).

## 25.6 Reminder Tagihan 3 Hari Terakhir

**Tujuan**: mengingatkan kasir (atau admin, kalau dia juga punya
transaksi atas namanya sendiri) soal tagihan yang dia garap sendiri
dan masih "segar" (3 hari terakhir), supaya tidak kelupaan
di-follow-up.

**Kriteria** (dicek di `Kasir::getReminderTagihanSaya()`, dipanggil
dari `Kasir::index()`):
- `kasir_id` = user yang sedang login (`session()->get('id_user')`)
- `status_pembayaran` IN (`belum_bayar`, `dp`)
- `status` != `batal`
- `tanggal` >= (sekarang − 3 hari)

**Trigger**: dicek ulang setiap kali halaman `/kasir` dibuka
(server-side, bukan polling AJAX terpisah — beda pola dari
`updateBadgeTagihan`/`cekStatusJadwalSaya` yang memang polling
berkala).

**Jeda anti-spam**: 15 menit, disimpan di
`session('reminder_tagihan_last_shown')` (timestamp Unix). Timestamp
ini **hanya** di-update saat toast benar-benar ditampilkan (count >
0) — supaya kalau saat ini belum ada tagihan yang perlu diingatkan
tapi muncul satu 2 menit kemudian, kasir tetap langsung diberi tahu
tanpa harus menunggu jeda yang tidak relevan.

**Tampilan (revisi):** semula toast tipe `warning` (bisa diklik,
auto-hilang 4 detik sesuai 25.4). **Diganti jadi modal `konfirmasi()`**
(dua tombol eksplisit: **Tutup** / **Lihat**) supaya reminder tidak
hilang sendiri sebelum benar-benar direspons kasir secara sadar.
`data-bs-backdrop="static"` (bawaan `confirmModal`, tidak diubah)
membuat klik di luar modal tidak menutupnya — hanya tombol Tutup/X/Esc
atau tombol Lihat yang bisa menutup. Tombol **Lihat** membuka
**tab/window baru** ke `/tagihan?saya=1`; tombol **Tutup** (atau X/Esc)
sekadar menutup modal tanpa aksi lain. `konfirmasi()` diperluas dengan
opsi baru `cancelText` (default tetap `"Batal"` supaya seluruh
pemanggil existing seperti konfirmasi hapus/batalkan tidak berubah
tampilannya) khusus untuk mengubah label tombol Batal jadi "Tutup" di
kasus ini.

**Filter `?saya=1` di `Tagihan::index()`** (baru): menampilkan hanya
tagihan dengan `kasir_id` = user yang login. Nilai filter **selalu**
diambil dari `session()->get('id_user')`, tidak pernah dari ID user
di query string — supaya tidak bisa dipakai mengintip filter atas
nama user lain sekadar dengan mengganti angka di URL. Kalau parameter
`saya` tidak dikirim, perilaku default (tampilkan semua tagihan)
tidak berubah sama sekali. Saat filter aktif, halaman Tagihan
menampilkan notice "Menampilkan tagihan atas nama Anda saja" plus
link untuk kembali ke daftar lengkap.

**Berlaku untuk**: kasir dan admin — tidak ada pengecekan role,
murni berdasarkan `kasir_id` di tabel `transaksi`, jadi otomatis
berlaku untuk siapa pun yang login dan membuka `/kasir`.

## 25.7 Header nempel penuh ke sidebar

`<header class="top-header">` berada di dalam `<main class="px-md-4">`,
yang mewariskan padding kiri-kanan 1.5rem ke header — bikin header
kelihatan "mengambang", terpisah dari sidebar oleh celah terang.
Diperbaiki dengan margin negatif responsif (`margin-left/right:
-1.5rem` mulai breakpoint `md`, sama seperti breakpoint `px-md-4`)
supaya header menembus padding tsb dan nempel rata ke tepi sidebar.
Konten di dalam header (search box, ikon) tidak perlu disesuaikan
karena `container-fluid`/`row` di dalamnya sudah punya padding
sendiri.

Sekalian dibersihkan: inline style `background:#fff` dkk di elemen
`<header>` dihapus karena itu dead code (selalu ditimpa total oleh
CSS class `.top-header { ... !important }`).

---

# 26. Preview Banner (2026-09-07)

## 26.1 Apa ini

Alat bantu internal untuk staff membuat gambar preview banner
(lengkap dengan garis ukuran & crop mark) sebelum dicetak, untuk
dikirim ke customer lewat WhatsApp minta approval sebelum eksekusi
cetak -- mengurangi reprint akibat revisi mendadak setelah barang
jadi.

Alur: upload desain (gambar) → masukkan ukuran pesanan (lebar x
tinggi cm) → generate preview. Kalau proporsi gambar vs ukuran
pesanan beda jauh (>10% distorsi), otomatis tampil 5 opsi (Sesuai
ukuran / Crop / Fit / Ikut Lebar / Ikut Tinggi) supaya customer bisa
pilih cara penyesuaian sebelum dicetak.

## 26.2 Keputusan integrasi

- **Murni client-side, tidak ada penyimpanan data.** Tidak terhubung
  ke transaksi, tidak ada tabel baru, tidak ada endpoint API baru.
  Upload gambar, generate preview, dan download semuanya terjadi di
  browser lewat `<canvas>` -- tidak ada apa pun yang dikirim ke
  server AULIA.
- **File asli TIDAK DIUBAH sama sekali**
  (`public/tools/preview-banner.html`), ditampilkan lewat
  `<iframe>` di `views/preview-banner/index.php`. Ini disengaja:
  CSS alat ini pakai nama class umum (`.btn`, `.btn-primary`, dst)
  yang kalau ditempel langsung ke `layout/main.php` akan bentrok dan
  merusak tampilan tombol Bootstrap di **seluruh** halaman AULIA
  lain. Iframe mengisolasi total CSS/JS-nya, zero risiko regresi ke
  halaman lain.
- **Bisa diakses semua role yang login** (kasir & admin) — menu baru
  di sidebar, di luar Transaksi/Keuangan/Master Data/Jadwal
  (berdiri sendiri), route `/preview-banner`.

---

# 27. Aturan emas AULIA

> **`selesai` adalah final transaksi/pekerjaan. `lunas` hanya final pembayaran.**
> **Sejak 2026-09-05: `selesai` HANYA boleh dicapai jika `lunas` DAN
> ditandai eksplisit. `lunas` sendirian tidak pernah cukup untuk
> menjadi `selesai`.** Penanda eksplisit itu: **admin** lewat workflow
> umum, atau — sejak Phase 2 (2026-09-10) — **kasir pemilik** lewat
> workflow Kasir/POS untuk transaksinya sendiri yang sudah lunas
> (Section 4.2). Syarat `lunas` tidak pernah bisa dilewati siapa pun.

> **Tagihan ditentukan berdasarkan status pembayaran, bukan status transaksi; transaksi `batal` selalu dikecualikan.** (Tidak berubah oleh perubahan 2026-09-05 — Tagihan tetap tidak melihat status transaksi maupun role.)

> **Backdate pembayaran (tanggal berbeda dari sekarang) hanya boleh oleh admin, tidak pernah mengubah `created_at`, dan divalidasi ulang di backend terlepas dari apa yang dikirim client.** (Section 19 P11)

> **Modul Jadwal Karyawan (Section 20) adalah domain terpisah dari transaksi/kasir — jadwal ≠ absensi, tidak pernah memblokir transaksi, dan mutation-nya admin-only sementara viewing read-only terbuka untuk semua role login.**

Lifecycle pekerjaan:
```text
TRANSAKSI BARU
      ↓
    PROSES
   /      \
 EDIT     BATAL
   |
   ↓
 SELESAI
  FINAL
```

Lifecycle pembayaran:
```text
BELUM BAYAR
     ↓
     DP
     ↓
   LUNAS
```

Kedua lifecycle tersebut **tidak boleh dicampur**.

---

# 28. Archive Transaksi (2026-09-09)

## 28.1 Tujuan & prinsip

Mengurangi beban database utama (MySQL) dan menghilangkan transaksi
historis dari Tagihan/badge notifikasi, **tanpa kehilangan histori**
transaksi & pembayaran yang masih dibutuhkan untuk laporan dan
pencarian. Bersifat **manual sepenuhnya** — admin yang menjalankan
lewat UI, **tidak ada cron/scheduler**.

Prinsip utama: **integritas data di atas segalanya**. Data hanya boleh
dihapus dari MySQL setelah terbukti tersalin lengkap & valid di
archive.

## 28.2 Aturan cutoff bulan eligible

> **Bulan X eligible untuk di-archive kalau X berjarak ≥ 6 bulan PENUH
> dari bulan berjalan** (perbandingan granularitas BULAN, bukan
> tanggal persis).

Contoh (hari ini 8 September 2026): Januari/Februari/Maret 2026
eligible, April 2026 belum.

**Catatan rekonsiliasi**: spesifikasi awal fitur ini menyebut aturan
level tanggal ("tanggal terakhir bulan < hari ini dikurangi 6 bulan"),
tapi itu tidak cocok dengan contoh konkret yang sama-sama diberikan di
spesifikasi (Maret seharusnya belum eligible kalau dihitung literal
per-tanggal). Aturan final di atas mengikuti **contoh konkretnya**
(lebih presisi & bisa diuji), diimplementasikan di
`TransaksiArchiveService::isBulanEligible()`.

Yang di-archive: **semua transaksi pada bulan eligible**, tanpa filter
status transaksi/pembayaran (proses/selesai/batal, belum_bayar/dp/
lunas semua ikut). Status & nilai transaksi **tidak pernah diubah**
saat archive.

## 28.3 Database archive

SQLite terpisah, koneksi CodeIgniter grup `archive` (lihat
`Config\Database::$archive`). Path default
`WRITEPATH . 'archive/aulia_pos_archive.db'`, bisa di-override lewat
`.env` (`database.archive.database=...`) tanpa ubah kode — database
utama (MySQL, grup `default`) **tidak pernah diganti/disentuh
strukturnya**.

Tabel: `transaksi_archive`, `detail_transaksi_archive`,
`pembayaran_archive` — skema di-generate otomatis saat pertama kali
dipakai (`TransaksiArchiveService::pastikanSkemaArchive()`, self-
initializing, tidak butuh langkah migrate terpisah). `id` di archive
**sama persis** dengan `id` asli di MySQL (bukan autoincrement baru),
supaya relasi & referensi lama tetap konsisten.

Nama pelanggan (`pelanggan_nama`) dan kasir (`kasir_nama`,
`kasir_username`, `kasir_inisial`) di-**snapshot** langsung ke archive
saat archive dijalankan — pola yang sama dengan `nama_produk` yang
sudah lebih dulu ada di `detail_transaksi`. Ini supaya archive
tetap self-contained (bisa dibaca sendiri) walau baris
pelanggan/users di DB utama nanti berubah, **tanpa** perlu menyalin
seluruh tabel master `pelanggan`/`produk`/`kategori`/`users` (audit
kode: tidak ditemukan hard-delete pada kedua tabel itu, tapi snapshot
tetap dipilih untuk keamanan jangka panjang).

## 28.4 Alur eksekusi (destruktif, wajib berurutan)

```text
Admin buka /archive-transaksi (admin-only)
        ↓
Sistem tampilkan bulan eligible (dari data yang benar-benar ada di MySQL)
        ↓
Admin pilih 1+ bulan → Preview (read-only)
        ↓
Admin ketik "ARCHIVE" (unlock tombol) → modal konfirmasi
        ↓
1. Tulis backup JSON mentah ke writable/archive/backups/
2. Salin ke SQLite archive (INSERT OR REPLACE — idempotent,
   retry setelah gagal sebagian TIDAK menduplikasi baris)
3. VALIDASI: jumlah baris (transaksi/detail/pembayaran) & total
   grand_total archive harus cocok PERSIS dengan sumber
        ↓
   Validasi gagal?  →  STOP. MySQL tidak tersentuh sama sekali.
        ↓ (valid)
4. Hapus dari MySQL (satu transaksi DB; detail_transaksi &
   pembayaran ikut lewat FK ON DELETE CASCADE yang sudah ada,
   tidak perlu DELETE terpisah per tabel anak)
```

Kalau langkah hapus dari MySQL sendiri gagal (setelah archive
tervalidasi lengkap): data **tetap ada di kedua tempat** (tidak
hilang), admin tinggal jalankan archive lagi untuk bulan yang sama —
aman diulang karena `INSERT OR REPLACE`.

## 28.5 Integrasi ke modul lain

| Modul | Dampak |
|---|---|
| **Tagihan + badge notifikasi** | Otomatis aman, **tanpa perubahan kode** — query-nya (`status_pembayaran != lunas AND status != batal`) tidak pernah dibatasi tanggal, jadi begitu baris dihapus dari MySQL langsung hilang dari Tagihan. |
| **Kas** (`CashBalanceService`) | Terbukti **tidak perlu diubah** — saldo kas selalu query `pembayaran` untuk tanggal target saja (same-day), dan cutoff archive minimal 6 bulan lalu tidak mungkin overlap. |
| **Pencarian transaksi** (`Transaksi::index()` dgn keyword) | Dual-source: keyword yang diisi memicu query tambahan ke archive (`TransaksiArchiveService::cariUntukDaftarTransaksi()`), hasil digabung & ditandai `_sumber` (`aktif`/`archive`, badge di UI). |
| **Detail transaksi** (`Transaksi::detail()`) | Fallback ke archive kalau ID tidak ketemu di MySQL. Halaman jadi **read-only**: semua tombol aksi (edit/bayar/selesai/batal/**cetak**) disembunyikan lewat flag `$dariArchive`. |
| **`Tagihan::detail()`** | **BELUM** di-fallback ke archive (beda dari `Transaksi::detail()`) — risiko kecil karena Tagihan tidak punya fitur cari-lewat-histori, kemungkinan hit ID archive di sini cuma dari bookmark lama. |
| **Cetak ulang/reprint** | **Sengaja tidak didukung** untuk transaksi archive (dikonfirmasi tidak diperlukan). |
| **Laporan** (`Laporan.php`) | Dual-source penuh di semua jalur: `getData()` (Periode/Per Kategori), `getPemasukanHarianData()`, `getLaporanBulananData()`, `itemHarian()`, `pembayaran()`, `exportExcel()`. Karena MySQL tidak bisa JOIN ke SQLite, tiap sumber di-query terpisah lalu digabung di PHP **sebelum** masuk ke logic olah-data yang sudah ada — logic aggregasi asli tidak diubah. |
| **`Api::searchGlobal()`** | Dibuat dual-source juga, tapi ternyata **dead code** — tidak dipanggil frontend mana pun (search box asli submit form biasa ke `/transaksi?keyword=`). Dibiarkan siap pakai kalau suatu saat diaktifkan. |

## 28.6 Keterbatasan yang diketahui

- Tidak ada UI restore otomatis (archive → MySQL) — data tetap bisa
  dikembalikan manual dari SQLite archive atau file backup JSON kalau
  benar-benar dibutuhkan.
- File backup JSON di `writable/archive/backups/` menumpuk tanpa
  pembersihan otomatis (sengaja, karena tidak ada scheduler) — perlu
  dibersihkan manual sesekali.
- Belum ada test end-to-end dengan MySQL sungguhan (disarankan dites
  di staging dengan data dummy dulu sebelum dipakai di production).

---

# 29. Status Transaksi MANGKRAK (2026-09-09)

## 29.1 Latar belakang & beda dengan BATAL

`batal` berarti **"transaksi ini dianggap tidak pernah terjadi"**.
Tapi ada kasus nyata: transaksi yang **beneran terjadi** (ada order,
kadang sudah ada DP/pekerjaan berjalan) tapi macet tanpa kejelasan --
belum dibayar, pelanggan tidak mengambil, barang entah kemana. Pakai
`batal` untuk kasus ini salah secara makna (mengklaim transaksi tidak
pernah terjadi, padahal terjadi).

**`mangkrak`** dibuat khusus untuk kasus ini: transaksi tetap diakui
pernah terjadi (data tidak diubah/dihapus), tapi **dilepas dari radar
aktif** (Tagihan, badge notifikasi, reminder kasir) supaya tidak terus
mengganggu meski belum jelas ujungnya.

## 29.2 Aturan transisi (`TransaksiModel::ubahStatus()`)

```
PROSES ──(tandai mangkrak, ADMIN)──> MANGKRAK
SELESAI + belum lunas ──(tandai mangkrak, ADMIN)──> MANGKRAK
MANGKRAK ──(aktifkan kembali, ADMIN)──> PROSES
```

- Bisa ditandai mangkrak dari **PROSES** (kasus paling umum), atau
  dari **SELESAI** kalau `status_pembayaran` **bukan** `lunas` --
  ini bisa terjadi kalau transaksi sempat SELESAI (mensyaratkan lunas
  saat itu) tapi kemudian pembayarannya di-reversal lewat
  `Api::koreksiPembayaran()`, membuat `status_pembayaran` turun lagi
  tanpa status transaksi ikut berubah (tidak ada mekanisme otomatis
  yang mengembalikan SELESAI ke PROSES). Kalau SELESAI + lunas
  (kondisi normal), tidak bisa ditandai mangkrak -- tidak ada yang
  perlu dilepas dari radar.
- Dari MANGKRAK, **satu-satunya jalan keluar adalah balik ke PROSES**
  (tidak bisa langsung ke SELESAI/BATAL) -- supaya tetap melalui
  validasi normal (pelunasan, dst) kalau nanti dilanjutkan.
- **Admin-only** (baik menandai maupun mengaktifkan kembali) --
  keputusan "lepas dari radar aktif" maupun "masukkan lagi" sengaja
  tidak dibuka untuk semua role, beda dari PROSES→BATAL yang terbuka
  untuk siapa saja.
- Tidak ada syarat status pembayaran untuk menandai mangkrak dari
  PROSES (justru kasus paling umum adalah belum dibayar sama sekali).
- `no_order` **TIDAK** dikosongkan (beda dari BATAL) -- transaksi ini
  masih bisa dilanjutkan kapan saja.

## 29.3 Dampak ke modul lain

| Modul | Perlakuan |
|---|---|
| Tagihan (`Tagihan::index()`) | Dikeluarkan (`whereNotIn('status', ['batal','mangkrak'])`) |
| Badge notifikasi (`Api::getJumlahTagihan()`) | Dikeluarkan |
| Widget tagihan di dashboard Kasir | Dikeluarkan |
| Reminder tagihan 3 hari (`Kasir::getReminderTagihanSaya()`) | Dikeluarkan |
| Tombol Bayar (list & detail transaksi) | Disembunyikan -- harus "Aktifkan Kembali" ke PROSES dulu |
| Laporan | **TIDAK dikecualikan** -- transaksi mangkrak tetap tercatat di laporan (data historis nyata, beda dari batal yang juga tetap muncul di laporan existing) |
| **Archive Transaksi** (Section 28) | **Tidak ada perlakuan khusus** -- transaksi mangkrak ikut ter-archive normal begitu bulan-nya eligible (≥6 bulan), sama seperti status lain. Archive memang sengaja tidak membatasi berdasarkan status transaksi (lihat Section 28.2). |

## 29.4 Migration

`2026-09-09-000001_AddStatusMangkrakTransaksi.php` -- menambah
`'mangkrak'` ke enum `transaksi`.`status` (mempertahankan `diambil`
yang sudah tidak dipakai di level aplikasi, lihat Section 1). Wajib
dijalankan (lewat `/migrasi-manual` atau `php spark migrate`) sebelum
fitur ini bisa dipakai -- tanpa migration, transaksi tidak bisa
diubah ke `mangkrak` (MySQL akan menolak nilai enum yang tidak valid).

---

# 30. Cetak Nota: "Cetak Langsung" & "Pilih Printer" (2026-09-09)

## 30.1 Konsep

Tombol "Cetak Nota" (setelah transaksi disimpan, maupun cetak ulang
dari detail transaksi) sekarang jadi dropdown dua pilihan:

- **Cetak Langsung** -- server-side, langsung ke `\\AULIA-DP1\L300`
  (Epson L300), TANPA dialog print browser.
- **Pilih Printer** -- perilaku LAMA yang sudah ada (buka PDF/HTML di
  window baru, user pilih printer sendiri lewat dialog print browser)
  -- **tidak diubah sama sekali**, cuma dipindah jadi salah satu opsi
  dropdown (sebelumnya tombol "Nota" berdiri sendiri).

## 30.2 Audit yang mendasari desain ini

- Tidak ditemukan mekanisme command-line PDF printing apa pun di
  codebase sebelumnya (`grep` untuk SumatraPDF/PDFtoPrinter/exec/
  shell_exec/proc_open: nihil, kecuali `WindowsPrintConnector` untuk
  Thermal yang MURNI ESC/POS raw text, bukan PDF).
- Karena itu, mekanisme Thermal (`smb://guest@aulia6/POS-58` via
  `Mike42\Escpos`) **tidak bisa** dipakai ulang untuk mengirim PDF --
  library itu cuma bicara protokol printer struk/receipt, tidak
  kompatibel dengan printer PDF biasa seperti Epson L300.
- **"Pilih Printer" justru SUDAH ADA** -- itu literally perilaku
  tombol "Nota"/"Cetak PDF" yang sudah lama berjalan (buka window,
  browser print dialog otomatis menampilkan semua printer yang
  ter-install di komputer kasir). Tidak ada kode baru untuk opsi ini.
- Untuk "Cetak Langsung", satu-satunya jalan adalah tool command-line
  PDF-to-printer -- ini **genuinely baru**, tidak ada yang bisa
  di-reuse untuk bagian pengiriman-nya (generate PDF-nya tetap reuse
  Dompdf + view `cetak/nota.php` yang sama persis dengan "Pilih
  Printer", cuma jalur pengiriman ke printernya yang beda).

## 30.3 Konfigurasi (`App\Config\PrintNota`)

Printer tujuan & path tool cetak **sepenuhnya di sisi server**, tidak
pernah diterima dari request browser (mencegah browser mengirim
command/path printer sembarangan):

```php
public string $printerLangsung  = '\\\\AULIA-DP1\\L300';
public string $sumatraPdfPath   = 'C:\\Tools\\SumatraPDF\\SumatraPDF.exe';
public int    $timeoutDetik     = 25;
```

Override lewat `.env` (`printnota.printerLangsung`,
`printnota.sumatraPdfPath`) tanpa ubah kode -- default mengasumsikan
tool **SumatraPDF** (portable, gratis, mendukung
`-print-to <printer> -silent <file>`). Kalau server production pakai
tool lain, sesuaikan `.env` DAN format argumen di
`Cetak::kirimPdfKePrinter()`.

## 30.4 Flow "Cetak Langsung"

```
Klik "Cetak Langsung"
  -> GET /cetak/nota-langsung/{id} (AJAX, read-only)
  -> fetch transaksi (SAMA persis dengan "Pilih Printer")
  -> render view cetak/nota.php -> HTML (SAMA template)
  -> Dompdf -> PDF A6 landscape
  -> simpan PDF sementara di writable/uploads/nota_print/
  -> proc_open([SumatraPDF, -print-to, <printer>, -silent, <file>])
  -> tunggu proses selesai (timeout 25 detik default)
  -> hapus file sementara (berhasil maupun gagal)
  -> log exit code + stdout + stderr
  -> JSON {status, message} ke browser (toast, TANPA dialog print)
```

Endpoint ini **read-only** terhadap transaksi (SELECT saja) -- gagal
generate PDF atau gagal kirim ke printer TIDAK PERNAH mempengaruhi
transaksi yang sudah tersimpan (endpoint dipanggil SETELAH simpan,
tidak pernah menulis ke `transaksi`/`detail_transaksi`/`pembayaran`).

## 30.5 Regression Thermal

`Cetak::thermal()` **tidak disentuh sama sekali** -- target
`smb://guest@aulia6/POS-58` dan seluruh implementasinya tetap persis
seperti sebelumnya.
