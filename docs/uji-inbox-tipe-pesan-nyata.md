# Skenario Uji — Kiriman Nyata dari HP ke Inbox POS

Status: prosedur operasional. Terakhir diperbarui 2026-10-01.

Uji ini membuktikan kiriman WhatsApp **nyata** dari HP sampai ke Inbox AuliaPos
sebagai baris yang benar, dan tidak ada yang menjadi dead-letter. Pelengkap dari
uji sintetis (webhook buatan) yang sudah lulus.

## 1. Persiapan

- **Nomor tujuan (gateway):** `62881082323928` (instance `aulia-toko`).
- **HP pengirim:** WAJIB nomor lain. Pesan dari nomor gateway sendiri
  (echo/`fromMe`) sengaja diabaikan oleh adapter, jadi tidak akan teruji.
- **Pastikan stack hidup:**
  - aulia3: Evolution API (port 8080) + adapter (port 3000).
  - POS produksi: `http://AULIA-SERVER2/aulia` (halaman Inbox).
- **Catat:** nomor HP pengirim dan waktu tiap kiriman (untuk pemeriksaan log).

Saran: pakai satu nomor pengirim saja agar pemeriksaan mudah difilter.

## 2. Kasus wajib

| # | Yang dikirim | Cara kirim | Hasil yang diharapkan di Inbox |
| --- | --- | --- | --- |
| 1 | **Album foto** | Pilih 3 foto sekaligus, kirim sebagai satu album | 3 baris **`image`** (satu per foto), tampil sebagai gambar. **Tidak ada** baris tambahan untuk "album". |
| 2 | **Lokasi** | Attach → Location → pilih satu titik | 1 baris **`location`**: kartu berisi nama/koordinat + tautan Google Maps. |
| 3 | **Kontak** | Attach → Contact → pilih 1 kontak | 1 baris **`contact`**: nama + nomor (diambil dari vCard). |
| 4 | **Voice note** | Tahan tombol mic lalu kirim (pesan suara asli) | 1 baris **`audio`** dengan placeholder "Customer mengirim audio — cek WhatsApp Web." |
| 5 | **Balasan tombol/daftar** | Hanya bisa dipicu bila toko lebih dulu mengirim pesan interaktif (template ber-tombol) | Lihat catatan §4 — untuk sekarang dilewati di uji nyata. |

## 3. Kasus tambahan (murah, sangat berguna)

| # | Yang dikirim | Cara kirim | Hasil yang diharapkan |
| --- | --- | --- | --- |
| 6 | **Foto view-once** | Attach foto → pilih "1× lihat" / View once | 1 baris **`image`** (pembungkus dibuka). |
| 7 | **Dokumen + keterangan** | Attach dokumen + tulis caption sebelum kirim | 1 baris **`document`** + caption. |
| 8 | **Reaction** | Tekan lama sebuah pesan → pilih emoji | **Tidak ada baris baru** dan **tidak ada dead-letter**. |
| 9 | **Video note** (video bulat) | Rekam video singkat mode pesan video | 1 baris **`unsupported`** dengan penanda "video singkat". |
| 10 | **File audio** (bukan voice note) | Attach → Audio (file musik) | 1 baris **`document`** — beda dari voice note (kasus 4). |

## 4. Catatan penting

- **Balasan tombol/daftar (kasus 5):** pesan ini hanya ada kalau toko mengirim
  pesan interaktif lebih dulu (template WA Business). AuliaPos belum mengirim
  template, jadi tidak bisa dipicu dari HP biasa. Perilakunya sudah diuji lewat
  jalur sintetis (dikirim sebagai penanda `unsupported`), dan akan diuji nyata
  saat fitur template dipakai.
- **Album:** foto bisa tiba beberapa detik **setelah** wadah album (yang sengaja
  dilewati). Yang benar adalah baris gambar muncul, bukan baris "album".
- **Voice note vs file audio:** voice note (tahan mic) = `audio`; file audio
  (attach) = `document`. Jangan tertukar saat menilai.
- **Lokasi live** ("Lokasi terkini") menampilkan tanda "(langsung)"; lokasi
  biasa tidak.

## 5. Cara memeriksa

Setelah semua dikirim, minta pemeriksaan (atau jalankan sendiri):

```powershell
# Baris Inbox untuk nomor pengirim + tipe + cuplikan:
powershell -File verify\check-inbox-nyata.ps1 -ChatId 62812xxxxxxx

# Ringkasan antrean adapter (completed/dead/pending):
#   jalankan di aulia3:  cmd /c D:\evolution-gateway\scripts\inspect-queue.cmd
```

Ganti `62812xxxxxxx` dengan nomor HP pengirim dalam format `62812...@s.whatsapp.net`.

## 6. Kriteria LULUS

- Tiap kasus muncul dengan tipe yang benar (atau penanda untuk yang belum didukung).
- **`dead` TIDAK bertambah** (tetap 0 dengan baseline saat ini).
- `pending`/`failed` kembali **0** beberapa detik setelah kiriman terakhir.
- Reaction (kasus 8) tidak menghasilkan baris apa pun.

## 7. Kalau ada yang tidak sesuai

1. Catat nomor kasus, nomor pengirim, dan **waktu kirim** (WIB).
2. Ambil `D:\kilo\logs\adapter.log` di aulia3 di sekitar waktu itu (cari
   `[EVOLUTION-IN]` dan `[EVOLUTION-ENQUEUE]`).
3. Bandingkan tipe node yang sebenarnya dikirim Evolution (`evolution.log`,
   atau tabel `Message` di PostgreSQL Evolution).
4. Laporkan temuan + cuplikan log.

## 8. Tabel hasil (isi saat menjalankan)

| # | Kasus | Waktu kirim | Muncul di Inbox? | Tipe benar? | Catatan |
| --- | --- | --- | --- | --- | --- |
| 1 | Album 3 foto | | | | |
| 2 | Lokasi | | | | |
| 3 | Kontak | | | | |
| 4 | Voice note | | | | |
| 5 | Balasan tombol | (dilewati) | | | |
| 6 | View-once | | | | |
| 7 | Dokumen + caption | | | | |
| 8 | Reaction | | (harusnya TIDAK ada) | | |
| 9 | Video note | | | | |
| 10 | File audio | | | | |
