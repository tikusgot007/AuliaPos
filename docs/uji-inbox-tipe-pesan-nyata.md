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
| 6 | **Foto view-once** | Attach foto → pilih "1× lihat" / View once | 1 baris penanda **`unsupported`**: "Customer mengirim pesan lihat-sekali — cek WhatsApp Web.", media **tidak** diunduh. |
| 7 | **Dokumen + keterangan** | Attach dokumen + tulis caption sebelum kirim | 1 baris **`document`** + caption. |
| 8 | **Reaction** | Tekan lama sebuah pesan → pilih emoji | **Tidak ada baris baru** dan **tidak ada dead-letter**. |
| 9 | **Video note** (video bulat) | Rekam video singkat mode pesan video | 1 baris **`unsupported`**: "Customer mengirim video singkat — cek WhatsApp Web." |
| 10 | **File audio** (bukan voice note) | Attach → Audio (file musik) | 1 baris **`audio`** (mime `audio/mpeg`), placeholder sama seperti voice note. |
| 11 | **Berkas > 64 MB** | Attach → Document (berkas besar) | 1 baris penanda **`unsupported`**: "Customer mengirim file besar diatas 64mb — cek WhatsApp Web.", tanpa unduhan media. |

## 4. Catatan penting

- **Balasan tombol/daftar (kasus 5):** pesan ini hanya ada kalau toko mengirim
  pesan interaktif lebih dulu (template WA Business). AuliaPos belum mengirim
  template, jadi tidak bisa dipicu dari HP biasa. Perilakunya sudah diuji lewat
  jalur sintetis (dikirim sebagai penanda `unsupported`), dan akan diuji nyata
  saat fitur template dipakai.
- **Album:** foto bisa tiba beberapa detik **setelah** wadah album (yang sengaja
  dilewati). Yang benar adalah baris gambar muncul, bukan baris "album".
- **Voice note vs file audio:** keduanya tiba sebagai `audioMessage` → baris
  `audio`. Bedanya hanya mime: voice note `audio/ogg; codecs=opus`, file audio
  (attach) `audio/mpeg`. Dugaan awal "file audio = document" TERBUKTI SALAH
  pada Android (uji 2026-10-01).
- **Berkas besar (> 64 MB):** TIDAK diunduh/disimpan — dikirim sebagai baris
  penanda teks (`unsupported`) supaya tidak hilang. Batas badan webhook adapter
  160 MB, jadi berkas sampai ~120 MB tetap sampai sebagai penanda; di atas itu
  masih akan ditolak 413 (perlu mode tanpa base64 / unduh on-demand).
- **View-once butuh patch Evolution.** WhatsApp mengirim view-once ke perangkat
  tertaut TANPA isi pesan (`key.isViewOnce=true`), dan Evolution 2.3.7 membuang
  pesan tanpa isi sebelum webhook dikirim. Tanpa patch di
  `docs/evolution-viewonce-patch.md` (repo adapter), pesannya hilang total.
  Isinya sengaja tidak diambil — hanya baris penanda.
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

## 8. Hasil uji nyata — 2026-10-01 (Android)

Pengirim: `628563324637` (tampil "Muhammad Anshar"). Nomor tujuan `62881082323928`.

| # | Kasus | Node Evolution | Hasil | Catatan |
| --- | --- | --- | --- | --- |
| 1 | Album foto (7 foto) | `albumMessage` + 7 `imageMessage` | ✅ 7× `image`, tanpa baris album | wadah album dilewati |
| 2 | Lokasi | `locationMessage` | ✅ `location` + koordinat | — |
| 3 | Kontak | `contactMessage` | ✅ `contact` + vCard | nomor dari vCard |
| 4 | Voice note | `audioMessage` (ogg/opus) | ✅ `audio` | — |
| 5 | Balasan tombol | — | ⏭️ dilewati | butuh template dari toko |
| 6 | View-once | `messages.upsert` (tanpa isi) | ✅ `unsupported` "lihat-sekali" | setelah patch Evolution (sebelumnya hilang total) |
| 7a | Dokumen 176 KB (tanpa caption) | `documentMessage` | ✅ `document` | — |
| 7b | Dokumen 27 MB (caption) | `documentMessage` | ✅ `document` + caption | **awalnya HILANG** (batas 16 MB) → pulih setelah fix |
| 8 | Reaction | `reactionMessage` | ✅ tidak ada baris | noise |
| 9 | Video note | `ptvMessage` | ✅ `unsupported` "video singkat" | belum didukung penuh |
| 10 | File audio (attach) | `audioMessage` (mpeg) | ✅ `audio` | ekspektasi dokumen salah (lihat §4) |
| 11 | Berkas > 64 MB | — | ⏭️ tidak diuji | pengirim tidak punya berkas >64 MB |

Hasil antrean setelah uji: `completed=35, dead=0, pending=0`.

**Temuan dari uji ini:** berkas > ±12 MB hilang senyap karena batas badan
webhook 16 MB (ditemukan lewat kasus 7b). Sudah diperbaiki — lihat riwayat
commit adapter (`fix(evolution): handle oversized incoming media ...`).
