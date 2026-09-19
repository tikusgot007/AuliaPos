# AULIA — Point of Sale

Kasir untuk usaha cetak / foto / banner satu toko. Dibangun di atas **CodeIgniter 4** (PHP 8.2+), MySQL/MariaDB, dijalankan di XAMPP (`http://localhost/aulia/`). Bahasa UI dan istilah domain: Indonesia (`transaksi`, `pelanggan`, `produk`, `kasir`, `tagihan`, `pembayaran`, `jadwal`).

## Branch

| Branch | Isi |
|---|---|
| `v2.x` (branch ini) | POS inti (transaksi, pembayaran, kas, tagihan, jadwal karyawan, laporan, cetak). **Tanpa** modul chat. |
| `v2.1` | Sama dengan `v2.2` **tanpa** fitur chat — baseline produksi bersih. |
| `v2.2` | `v2.1` + Shared WhatsApp Inbox (Chat): Response State, sticker, drag-drop, penyimpanan media lokal, soft delete, stop retry media kadaluarsa, gate saat Gateway terputus, window standalone. |

Cabang lain sudah dibersihkan. Isi `v3.0` lama tersimpan di tag `archive/v3.0`.

## Setup

```powershell
composer install
copy env .env          # isi baseURL dan database.default.*
php spark migrate
php spark db:seed AuliaPosInitialSeeder   # data master saja
composer test
```

Persyaratan: PHP 8.2+ dengan ekstensi `intl`, `mbstring`, `json`, `mysqlnd`, `curl`. Arahkan web server ke folder `public/`.

## Dokumentasi

Aturan bisnis lengkap: [`docs/aturan-bisnis-AULIA.md`](docs/aturan-bisnis-AULIA.md) (baca bagian yang relevan sebelum mengubah lifecycle transaksi, pembayaran, kas, tagihan, atau laporan). Untuk panduan kerja bersama Claude Code, lihat [`CLAUDE.md`](CLAUDE.md). Pengujian: [`tests/README.md`](tests/README.md).
