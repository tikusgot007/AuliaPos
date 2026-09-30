# Sesi 2026-09-30 — Perbaikan modul Produk

Status: selesai

Repo / branch: `aulia-app`, branch `v2.4`. Rentang commit: `4d56a70..fc099ea`
(10 commit, sudah di-push ke `origin/v2.4`).

## Selesai

- **Filter toolbar daftar produk** — kolom/filter Kategori diurutkan
  berdasarkan `kategori_nama` (alfabetis) lewat whitelist, bukan id. Filter
  `terkunci` mengabaikan nilai tidak valid. — `4d56a70`, `aa89d48`,
  `e731393`, `d606dc7`.
- **Inline edit barcode** — kesalahan jalur inline barcode diseragamkan:
  mengembalikan respons 422 ramah tanpa membocorkan detail SQL. — `324eb1a`.
- **Endpoint Kunci / Buka Kunci produk** — `POST /produk/kunci/(:num)` dan
  `POST /produk/buka-kunci/(:num)` dengan guard `bolehBukaKunci()` yang
  menolak membuka kunci ID khusus `[1,2,4]` (Banner/Manual/Custom).
  `ProdukModel::getDataTablesProduk()` mengembalikan kolom `is_locked` agar
  tombol dan filter Terkunci merender status benar. — `0fdaef2`, `b541363`.
- **Config** — kembalikan default `baseURL` yang valid + tambah `.env.example`.
  — `3e43e26`.
- **Import CSV Maintenance** — kolom Aktif/Locked yang kosong pada baris yang
  sudah ada kini mempertahankan nilai di database (tidak lagi dipaksa 1/0).
  Lihat `docs/CHANGELOG.md`. — `fc099ea`.
- Sumber kebenaran field yang boleh diedit inline: `INLINE_EDITABLE_FIELDS`
  di `app/Models/ProdukModel.php` (dipakai bersama controller dan model).

## Keputusan penting

- Kolom Locked yang kosong pada import CSV **tidak** dianggap 0 — sengaja
  dibuat asimetris terhadap Aktif agar import parsial tidak membuka kunci
  produk secara tak sengaja.
- ID khusus `[1,2,4]` tidak boleh dibuka kuncinya (guard server-side).

## Tersisa / TODO

- [ ] `Produk::hapus()` (soft delete via `is_active=0`) **tidak** mengecek
      proteksi `is_locked`/ID khusus — perlu ditinjau apakah ini disengaja.
- [ ] Verifikasi modul produk dijalankan lewat skrip PHP yang mem-boot CI
      terhadap DB aktual; pastikan skrip itu tetap dipelihara/dipulihkan.

## Belum diverifikasi / risiko

- Verifikasi memakai DB aktual (bukan suite otomatis) — data uji dipulihkan
  manual setelah pengujian.

## Titik masuk sesi berikutnya

- **Baca**: `app/Models/ProdukModel.php` (`INLINE_EDITABLE_FIELDS`,
  `ID_KHUSUS`, `bolehDihapus`), `app/Controllers/Produk.php`.
- **Jalankan**: cek status filter/kunci di halaman produk, lalu tinjau
  `Produk::hapus()` untuk proteksi ID khusus.
