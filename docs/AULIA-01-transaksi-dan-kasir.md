# Aturan Bisnis AULIA — Transaksi & Kasir

> **Sumber asli:** `aturan-bisnis-AULIA.md` (Section 1–15, 27, 29).
> Dokumen ini adalah **penomoran ulang** dari sumber tersebut, khusus
> mencakup domain **transaksi & kasir**. Isi/pengertian tidak diubah;
> setiap bagian mencantumkan rujukan ke section asli (format `[AULIA §N]`)
> supaya bisa ditelusuri balik ke dokumen sumber.
>
> Riwayat pekerjaan (P1–P15), audit teknis, dan arsitektur implementasi
> untuk domain ini dipindah ke `AULIA-CHANGELOG.md` (bukan dihapus).
> Modul lain (Jadwal Karyawan, Profil, Notifikasi, Preview Banner,
> Archive, Cetak Nota) ada di `AULIA-02-modul-pendukung.md`.

**Status:** Baseline aktif — 2026-09-10 (update terakhir pada sumber asli).

---

## Daftar Isi

1. [Prinsip Pengembangan](#1-prinsip-pengembangan)
2. [Lifecycle Status Transaksi](#2-lifecycle-status-transaksi)
3. [Status Transaksi vs Status Pembayaran](#3-status-transaksi-vs-status-pembayaran)
4. [Transaksi Baru & Syarat SELESAI](#4-transaksi-baru--syarat-selesai)
5. [Edit Transaksi](#5-edit-transaksi)
6. [Koreksi Metode Pembayaran](#6-koreksi-metode-pembayaran)
7. [Konsistensi Total Pembayaran](#7-konsistensi-total-pembayaran)
8. [Tagihan](#8-tagihan)
9. [No Order](#9-no-order)
10. [Banner — Aturan Harga](#10-banner--aturan-harga)
11. [Kas dan Pembayaran Tunai](#11-kas-dan-pembayaran-tunai)
12. [Pembatalan dan Histori](#12-pembatalan-dan-histori)
13. [Tampilan Transaksi](#13-tampilan-transaksi)
14. [Status `diambil` (Dihapus)](#14-status-diambil-dihapus)
15. [Laporan](#15-laporan)
16. [Status Transaksi MANGKRAK](#16-status-transaksi-mangkrak)
17. [Aturan Emas (Ringkasan)](#17-aturan-emas-ringkasan)

---

## 1. Prinsip Pengembangan
`[AULIA §1]`

Dokumen ini menjadi acuan utama untuk aturan bisnis yang telah disepakati. Dokumentasi aturan bisnis **dipisahkan** dari kode yang menegakkannya.

Workflow pengembangan yang disepakati:
1. Tentukan masalah.
2. Sepakati aturan/tujuan.
3. Audit kode yang ada.
4. Daftarkan semua opsi solusi.
5. Pilih solusi.
6. Baru lakukan perubahan.
7. Perubahan kode dilakukan satu file per satu file.
8. Jangan menggunakan patch kecuali diminta.
9. Setiap perubahan diuji sebelum dianggap selesai.

---

## 2. Lifecycle Status Transaksi
`[AULIA §2, §4.1, §4.2]`

Status transaksi resmi hanya tiga: `proses`, `selesai`, `batal`. Status `diambil` **tidak lagi** bagian dari lifecycle (lihat Section 14 dokumen ini).

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

### 2.1 `proses`
Transaksi masih berjalan.
- Boleh Edit, boleh Batal.
- Boleh Selesai — **hanya jika** `status_pembayaran = lunas`, dan hanya lewat jalur yang diizinkan untuk role terkait (lihat 4.2 & 4.3 di bawah).
- Bisa `belum_bayar`, `dp`, atau `lunas`.
- **Penting:** `proses + lunas` tetap `proses` — lunas tidak otomatis menyelesaikan transaksi.

### 2.2 `selesai`
`selesai` adalah **final** untuk pekerjaan/transaksi.

**Definisi (berlaku sejak 2026-09-05):** `SELESAI` berarti garapan sudah benar-benar selesai dikerjakan **DAN** pembayaran transaksi sudah **LUNAS**. Kedua syarat harus terpenuhi bersamaan — bukan salah satu. (Detail syarat transisi lihat Section 4 dokumen ini.)

Perilaku lain:
- Tidak boleh Edit, tidak boleh menambah barang lewat Edit.
- Tidak kembali ke `proses` lewat alur normal.
- Tetap boleh menerima pembayaran jika masih ada sisa (dalam praktik seharusnya sudah `lunas` sejak awal; sisa hanya mungkin muncul lewat jalur lama/khusus atau data historis).

> Kombinasi `status=selesai` + `status_pembayaran=belum_bayar` **tidak valid** untuk transaksi baru (lihat Section 4, Kasus C).

### 2.3 `batal`
Transaksi dibatalkan.
- Tidak boleh Edit, tidak boleh menerima pembayaran baru, tidak masuk Tagihan.
- Pembatalan **tidak otomatis** berarti refund; pembayaran historis tidak dihapus.
- Saat batal, `no_order` dikosongkan (`null`).
- Mekanisme `aktifkan` untuk transaksi batal masih ada di baseline (belum diputuskan untuk dihapus). Reaktivasi **tidak** otomatis mengembalikan No Order lama.

---

## 3. Status Transaksi vs Status Pembayaran
`[AULIA §3]`

Kedua status **independen** satu sama lain.

- **Status transaksi** (kondisi pekerjaan): `proses`, `selesai`, `batal`.
- **Status pembayaran** (kondisi uang): `belum_bayar`, `dp`, `lunas`.

**`lunas` bukan status final transaksi.**

| Status transaksi | Status pembayaran | Arti |
|---|---|---|
| proses | belum_bayar | pekerjaan berjalan, belum bayar |
| proses | dp | pekerjaan berjalan, sudah DP |
| proses | lunas | pekerjaan berjalan, sudah lunas |
| selesai | belum_bayar | pekerjaan final, masih ada tagihan* |
| selesai | dp | pekerjaan final, masih ada tagihan* |
| selesai | lunas | pekerjaan final dan lunas |
| batal | apa pun | transaksi dibatalkan |

\* Baris `selesai + belum_bayar` dan `selesai + dp` **tidak bisa terbentuk** lewat transisi normal (transisi `proses → selesai` selalu mewajibkan `lunas`, lihat Section 4). Kombinasi ini hanya mungkin muncul jika pembayaran transaksi yang sudah `selesai` kemudian di-reversal lewat Koreksi Pembayaran (Section 6).

---

## 4. Transaksi Baru & Syarat SELESAI
`[AULIA §4, §4.1, §4.2]`

### 4.1 Transaksi baru
Semua transaksi baru masuk dengan `status = proses`, **tidak bergantung** pada metode pembayaran (tunai, QRIS, transfer, DP, piutang — semuanya `proses`). `status_pembayaran` dihitung terpisah.

### 4.2 Syarat transisi `proses → selesai`
**Keputusan resmi (2026-09-05):** transisi hanya diizinkan jika ketiganya terpenuhi:

```text
status transaksi saat ini = proses
AND
status_pembayaran         = lunas
AND
user yang mengubah        = admin  (atau kasir pemilik lewat jalur khusus, lihat 4.3)
```

Jika salah satu syarat gagal, backend **menolak** perubahan dan tidak melakukan update apa pun. Pesan error dibedakan:
- Bukan admin/pihak berwenang → `"Hanya admin yang dapat menyelesaikan transaksi."`
- Belum lunas → `"Transaksi belum dapat diselesaikan karena pembayaran belum lunas. Sisa pembayaran: Rp<nominal>"`

**`lunas` ≠ otomatis `selesai`.** Pembayaran lunas hanya membuat transaksi *memenuhi syarat* untuk diselesaikan. Tombol Selesai tetap harus ditekan secara eksplisit setelah memastikan garapan benar-benar clear.

```text
             PROSES
                │
        ┌───────┴────────┐
        │                │
   belum lunas         LUNAS
        │                │
        │          menunggu admin/kasir
        │          memastikan garapan
        │                │
        │         klik SELESAI (eksplisit)
        │                │
        └──────────┬─────┘
                   ▼
                SELESAI
```

#### Contoh kasus
**Kasus A — Lunas di awal, garapan masih berjalan**
Total Rp50.000, dibayar penuh di awal → `status_pembayaran=lunas`, `status=proses` (tetap, sampai admin klik Selesai). Setelah garapan clear dan admin klik Selesai → `status=selesai`.

**Kasus B — DP, garapan sudah selesai dikerjakan**
Total Rp50.000, DP Rp20.000 → `status_pembayaran=dp`, `status=proses` (tidak boleh diubah ke selesai). Setelah pelunasan sisa → `status_pembayaran=lunas`, `status` **tetap** `proses` (pelunasan tidak otomatis mengubah status transaksi — lihat Section 8). Baru setelah admin/kasir memastikan garapan clear dan klik Selesai → `status=selesai`.

**Kasus C — Tidak boleh terjadi lagi**
`status_pembayaran=belum_bayar` + `status=selesai` → **tidak valid**. Backend menolak transisi `proses → selesai` selama pembayaran belum `lunas`.

### 4.3 Siapa yang boleh menyelesaikan (per konteks)

| Role | Workflow umum — Daftar/Detail, `/api/ubah-status` | Workflow Kasir/POS — `/api/kasir/selesaikan-transaksi` |
|---|---|---|
| admin | Ya, jika `lunas` | Ya, jika `lunas` |
| kasir | **Tidak** — selalu ditolak backend | Ya, jika `lunas` **dan** transaksi miliknya sendiri dari POS |

Syarat `lunas` identik di kedua jalur; yang berbeda hanya siapa yang boleh memicu dan dari mana. Rumusan permission kasir bukan "kasir boleh mengubah status menjadi selesai", melainkan **"kasir boleh menyelesaikan transaksi lewat workflow Kasir/POS untuk transaksi miliknya yang sudah lunas."**

**Keputusan resmi (Phase 2, 2026-09-10):** kapabilitas ini ditambahkan untuk mengurangi beban admin — kasir yang membuat transaksi sekaligus menerima pembayaran dapat langsung menuntaskannya tanpa menunggu admin. Syarat (semua wajib, dicek backend):

| # | Syarat | Diperiksa di |
|---|---|---|
| a | `sumber = 'kasir_pos'` | controller endpoint |
| b | `kasir_id === id_user` (admin dikecualikan dari syarat ini) | controller endpoint |
| c | status transaksi masih `proses` | controller endpoint + `TransaksiModel::ubahStatus()` |
| d | `status_pembayaran = lunas` | `TransaksiModel::ubahStatus()` — sama persis dengan syarat umum |

Kasir **tetap tidak boleh** menyelesaikan transaksi lewat workflow umum (Daftar/Detail Transaksi, `/api/ubah-status`) — selalu ditolak backend di sana.

Role SPV belum dibuat; jika dibutuhkan nanti, ditambahkan sebagai perubahan terpisah.

> **UI bukan enforcement.** Penyembunyian tombol di UI hanyalah lapis pertama. Sumber kebenaran ada di backend untuk: role, kepemilikan (`kasir_id`), `sumber` transaksi, status pembayaran, dan status transaksi. Memanggil endpoint langsung (melewati UI) tetap tunduk pada semua pemeriksaan tersebut.

### 4.4 Cakupan perubahan syarat SELESAI
- **Tidak** menambah status baru (`diambil` tetap tidak dipakai — Section 14).
- **Tidak** mengubah behavior `batal` (Section 2.3, 12).
- **Tidak** mengubah mekanisme pembayaran, Tagihan (Section 8), atau Laporan (Section 15).
- **Tidak** mengubah alur Edit (Section 5) — Edit tetap hanya untuk `status=proses`.

---

## 5. Edit Transaksi
`[AULIA §5, §5.1]`

Edit normal hanya berlaku untuk `status = proses` (bukan berdasarkan `status_pembayaran`).

| Status | Pembayaran | Edit |
|---|---|---|
| proses | belum_bayar / dp / lunas | Ya |
| selesai | apa pun | Tidak |
| batal | apa pun | Tidak |

Jika perlu menambah barang setelah transaksi `selesai` → buat **transaksi baru**. Jika isi transaksi salah, SOP: **Batal → buat transaksi baru**.

### 5.1 Perubahan total saat edit & kelebihan bayar
**Keputusan resmi (2026-09-04):** Edit transaksi boleh menaikkan **atau** menurunkan total belanja, tanpa batas bawah — tidak ada validasi yang menolak edit hanya karena total baru lebih kecil dari yang sudah dibayar.

Konsekuensi kalau total baru **lebih kecil** dari total pembayaran aktif yang sudah ada:
- Transaksi tetap tersimpan seperti biasa.
- `total_dibayar` **tidak diubah** — tetap sesuai jumlah pembayaran aktif yang sebenarnya.
- `status_pembayaran` otomatis `lunas` (karena sudah dibayar ≥ total).
- Selisihnya adalah **kelebihan bayar** (`total_dibayar - grand_total`), ditampilkan sebagai indikator di UI (halaman edit & detail transaksi).

**Kelebihan bayar BUKAN refund.** Edit transaksi tidak pernah mencatat refund otomatis ke kas. Refund fisik ke pelanggan — jika memang dilakukan — adalah aksi manual dan terpisah, dicatat lewat **Kas Keluar → kategori "Refund Penjualan"**, kapan saja kasir memutuskan (konsisten dengan Section 6 dan 12: *"refund adalah proses tersendiri"*).

Sumber kebenaran `total_dibayar` & `status_pembayaran` setelah edit adalah `TransaksiModel::sinkronkanPembayaran()`, bukan hitungan manual di controller (lihat juga Section 7).

**Alasan keputusan ini:** AULIA adalah POS toko tunggal tanpa infrastruktur "reopen transaksi + refund otomatis" seperti POS besar (mis. Lightspeed). Menjaga item dan pembayaran tetap dalam satu record yang sama (bukan dipecah jadi Batal + transaksi baru) mengurangi kerja ulang kasir dan risiko rekonsiliasi manual yang lebih rumit.

---

## 6. Koreksi Metode Pembayaran
`[AULIA §6]`

Kesalahan isi transaksi berbeda dengan kesalahan metode pembayaran:
- Salah isi transaksi → Batal + transaksi baru.
- Salah metode pembayaran → **Koreksi Pembayaran**.
- Uang benar-benar dikembalikan → **Refund** terpisah.

Jangan langsung mengubah `pembayaran.metode` karena histori harus tetap dapat diaudit. Mekanisme koreksi:

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

## 7. Konsistensi Total Pembayaran
`[AULIA §7]`

`transaksi.total_dibayar` dipertahankan sebagai cache/denormalisasi untuk menghindari `SUM` berulang. Nilai sumber pembayaran aktif secara konsep: `SUM(pembayaran.jumlah WHERE status='aktif')`. Cache disinkronkan setelah pembayaran ditambahkan; tersedia sinkronisasi, pemeriksaan konsistensi, dan command repair.

---

## 8. Tagihan
`[AULIA §8]`

**Keputusan resmi: Tagihan berdasarkan pembayaran saja**, bukan status transaksi.

| Status transaksi | Pembayaran | Tagihan |
|---|---|---|
| proses | belum_bayar / dp | YA |
| proses | lunas | TIDAK |
| selesai | belum_bayar / dp | YA |
| selesai | lunas | TIDAK |
| batal | apa pun | TIDAK |

Pelunasan melalui Tagihan: menambah pembayaran aktif, memperbarui status pembayaran, **tidak otomatis mengubah status transaksi**. Contoh: `selesai + dp` → bayar sisa → `selesai + lunas`.

---

## 9. No Order
`[AULIA §9]`

No Order **bukan** nomor urut transaksi database — merepresentasikan **nama/nomor file foto fisik yang sedang diproses**. Aplikasi membantu dengan: No Order terakhir, kandidat berikutnya, dan No Order yang masih tersedia. `recommended_no_order` adalah **saran/kandidat**, bukan generator wajib (Generate New Order tetap berguna sebagai shortcut bila nomor fisik memang berikutnya).

Setelah No Order digunakan pada transaksi berhasil, nomor tersebut tidak lagi tersedia. Produk kategori Studio/Foto **membutuhkan** No Order; produk lain dapat diproses tanpa No Order. Saat transaksi batal, `no_order = null`.

---

## 10. Banner — Aturan Harga
`[AULIA §10]`

**Baris tetap terpisah.** Banner dengan ukuran sama **tidak otomatis digabung**, karena ukuran sama dapat berasal dari pekerjaan/desain berbeda.

### 10.1 Total < 1 m²
Minimum pricing berdasarkan **total luas seluruh item Banner**. Rumus: `totalArea × hargaPerM2 × 1.1`, lalu: pembulatan Rp500, maksimum harga standar per m², minimum Rp10.000.

Contoh: 0,8 m² @ Rp22.000 → Rp19.360 → dibulatkan Rp19.500.

Jika beberapa baris: total dihitung global, harga dibagi proporsional berdasarkan luas × qty, Rupiah utuh, baris terakhir menjadi residual balancer, grand total harus tepat sama dengan total global.

### 10.2 Total ≥ 1 m²
Setiap baris: `luas × qty × hargaPerM2`, dibulatkan ke Rp500. Baris tidak digabung dan tidak ada biaya tambahan karena total luas "nanggung".

---

## 11. Kas dan Pembayaran Tunai
`[AULIA §11]`

Kas bertambah berdasarkan uang yang benar-benar menjadi penerimaan penjualan setelah kembalian.

Contoh: Total Rp100.000, Bayar Rp150.000, Kembalian Rp50.000 → Kas masuk Rp100.000.

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

## 12. Pembatalan dan Histori
`[AULIA §12]`

Pembatalan **tidak menghapus** histori pembayaran. Prinsip:
- histori pembayaran dipertahankan;
- status transaksi menjadi `batal`;
- refund adalah proses tersendiri;
- koreksi pembayaran memakai `reversed`/`aktif`.

---

## 13. Tampilan Transaksi
`[AULIA §13]`

Belum ada halaman baru khusus transaksi `proses`; untuk sementara dikelola lewat `transaksi/index`.

```text
TRANSAKSI BARU → PROSES → transaksi/index → [Selesai] atau [Batal]
```

Aksi per status:
- `proses` → Edit, Selesai (khusus admin di halaman ini, hanya jika `lunas`; kasir menyelesaikan transaksinya sendiri lewat workflow Kasir/POS — Section 4.3), Batal.
- `selesai` → lihat detail dan bayar jika masih ada sisa.
- `batal` → tidak Edit dan tidak menerima pembayaran baru.
- `diambil` → tidak digunakan.

Tombol Selesai di Daftar/Detail **hanya ditampilkan untuk admin**. Kasir tidak melihatnya di sini, dan `/api/ubah-status` menolak kasir di backend. Penyembunyian tombol hanyalah lapis pertama — backend selalu memvalidasi ulang role, kepemilikan, `sumber`, status pembayaran, dan status transaksi sebelum eksekusi.

---

## 14. Status `diambil` (Dihapus)
`[AULIA §14]`

`diambil` **dihapus** dari lifecycle. Alur lama `selesai → diambil` tidak digunakan lagi.

Alur resmi: `proses → selesai`, `proses → batal`. `selesai` adalah final.

---

## 15. Laporan
`[AULIA §15]`

Transaksi `batal` tidak dianggap transaksi aktif. Pola `status != batal` tetap dapat digunakan bila laporan memang bermaksud menghitung semua transaksi non-batal.

Namun laporan tertentu perlu dibedakan berdasarkan tujuan: semua transaksi non-batal / hanya `proses` / hanya `selesai` / hanya yang `lunas`. **Jangan menyamakan status pekerjaan dengan status pembayaran.**

---

## 16. Status Transaksi MANGKRAK
`[AULIA §29, ditambahkan 2026-09-09]`

### 16.1 Latar belakang & beda dengan BATAL
`batal` berarti **"transaksi ini dianggap tidak pernah terjadi"**. Tapi ada kasus nyata: transaksi yang **beneran terjadi** (ada order, kadang sudah ada DP/pekerjaan berjalan) tapi macet tanpa kejelasan — belum dibayar, pelanggan tidak mengambil, barang entah kemana. Memakai `batal` untuk kasus ini salah secara makna (mengklaim transaksi tidak pernah terjadi, padahal terjadi).

**`mangkrak`** dibuat khusus untuk kasus ini: transaksi tetap diakui pernah terjadi (data tidak diubah/dihapus), tapi **dilepas dari radar aktif** (Tagihan, badge notifikasi, reminder kasir) supaya tidak terus mengganggu meski belum jelas ujungnya.

### 16.2 Aturan transisi (`TransaksiModel::ubahStatus()`)

```
PROSES ──(tandai mangkrak, ADMIN)──> MANGKRAK
SELESAI + belum lunas ──(tandai mangkrak, ADMIN)──> MANGKRAK
MANGKRAK ──(aktifkan kembali, ADMIN)──> PROSES
```

- Bisa ditandai mangkrak dari **PROSES** (kasus paling umum), atau dari **SELESAI** kalau `status_pembayaran` **bukan** `lunas` — bisa terjadi kalau transaksi sempat SELESAI (mensyaratkan lunas saat itu) tapi kemudian pembayarannya di-reversal lewat Koreksi Pembayaran (Section 6), sehingga `status_pembayaran` turun lagi tanpa status transaksi ikut berubah. Kalau SELESAI + lunas (kondisi normal), tidak bisa ditandai mangkrak.
- Dari MANGKRAK, **satu-satunya jalan keluar adalah balik ke PROSES** (tidak bisa langsung ke SELESAI/BATAL) — supaya tetap melalui validasi normal (pelunasan, dst) kalau nanti dilanjutkan.
- **Admin-only** (baik menandai maupun mengaktifkan kembali) — beda dari PROSES→BATAL yang terbuka untuk siapa saja.
- Tidak ada syarat status pembayaran untuk menandai mangkrak dari PROSES (justru kasus paling umum adalah belum dibayar sama sekali).
- `no_order` **TIDAK** dikosongkan (beda dari BATAL) — transaksi ini masih bisa dilanjutkan kapan saja.

### 16.3 Dampak ke modul lain

| Modul | Perlakuan |
|---|---|
| Tagihan | Dikeluarkan (`whereNotIn('status', ['batal','mangkrak'])`) |
| Badge notifikasi | Dikeluarkan |
| Widget tagihan di dashboard Kasir | Dikeluarkan |
| Reminder tagihan 3 hari | Dikeluarkan |
| Tombol Bayar (list & detail transaksi) | Disembunyikan — harus "Aktifkan Kembali" ke PROSES dulu |
| Laporan | **TIDAK dikecualikan** — transaksi mangkrak tetap tercatat di laporan (data historis nyata) |
| Archive Transaksi | **Tidak ada perlakuan khusus** — ikut ter-archive normal begitu bulannya eligible, sama seperti status lain |

---

## 17. Aturan Emas (Ringkasan)
`[AULIA §27]`

> **`selesai` adalah final transaksi/pekerjaan. `lunas` hanya final pembayaran.**
> Sejak 2026-09-05: `selesai` HANYA boleh dicapai jika `lunas` DAN ditandai eksplisit. `lunas` sendirian tidak pernah cukup untuk menjadi `selesai`. Penanda eksplisit itu: **admin** lewat workflow umum, atau — sejak Phase 2 (2026-09-10) — **kasir pemilik** lewat workflow Kasir/POS untuk transaksinya sendiri yang sudah lunas. Syarat `lunas` tidak pernah bisa dilewati siapa pun.

> **Tagihan ditentukan berdasarkan status pembayaran, bukan status transaksi; transaksi `batal` selalu dikecualikan.**

> **Backdate pembayaran (tanggal berbeda dari sekarang) hanya boleh oleh admin, tidak pernah mengubah `created_at`, dan divalidasi ulang di backend terlepas dari apa yang dikirim client.** (Lihat `AULIA-CHANGELOG.md` P11.)

> **Modul Jadwal Karyawan adalah domain terpisah dari transaksi/kasir** — jadwal ≠ absensi, tidak pernah memblokir transaksi, dan mutation-nya admin-only sementara viewing read-only terbuka untuk semua role login. (Lihat `AULIA-02-modul-pendukung.md`.)

Lifecycle pekerjaan:
```text
TRANSAKSI BARU → PROSES → [EDIT | BATAL] → SELESAI (FINAL)
```

Lifecycle pembayaran:
```text
BELUM BAYAR → DP → LUNAS
```

**Kedua lifecycle tersebut tidak boleh dicampur.**
