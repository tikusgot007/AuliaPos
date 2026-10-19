# Requirements: Saran AI On-Demand Kasir Inbox via 9router

Dokumen Requirements (Phase 1). Bahasa Indonesia (dokumen bisnis).

- **Tanggal**: 2026-10-18
- **Status**: disetujui
- **Tier SDLC**: A (fitur baru integrasi AI kasir)
- **Penanggung jawab**: Aan

## 1. Tujuan

Memberikan tombol on-demand bagi kasir di halaman Inbox AuliaPos untuk meminta saran balasan, daftar periksa pesanan, dan saran keranjang belanja langsung ke gateway AI lokal (9router), tanpa ketergantungan pada bot pemantau eksternal (M.A.R.A).

## 2. Kondisi saat ini (terverifikasi)

- **Tabel & Skema Saran**: Tabel `mara_suggestions` di grup database `inbox` (`aulia_inboxdb`) sudah aktif di produksi dan dev (`app/Models/MaraSuggestionModel.php:21`). Kolom mencakup `conversation_id`, `type`, `text`, `reason`, `action`, `cart_items`, `status`, dan `expires_at`.
- **UI Saran Kasir**: Panel saran sudah ada di `public/assets/js/inbox-suggestions.js:67`, mengambil data aktif dari endpoint `GET /inbox/api/conversations/(:num)/suggestions`.
- **Eksekusi Kasir**: Kasir sudah bisa menyalin balasan cepat atau memicu pengisian keranjang kasir dari saran aktif (`docs/design/2026-10-18-isi-keranjang-dari-saran-mara.md`).
- **Gateway AI Lokal**: 9router aktif di port 20128 (`http://localhost:20128/v1`), mendukung model `aan-groq` (Groq/LPU) serta fallback model lokal/cloud lainnya. Belum ada pemanggil internal dari AuliaPos ke 9router.

## 3. User story

- Sebagai kasir toko, saya ingin menekan tombol "Minta Saran AI" saat membuka percakapan pelanggan yang panjang atau membingungkan, supaya saya segera mendapatkan draf balasan sopan dan daftar item yang dipesan tanpa harus membaca ulang seluruh riwayat chat secara manual.
- Sebagai pemilik sistem, saya ingin pemanggilan AI hanya terjadi saat kasir meminta (on-demand), supaya kuota token hemat, tidak ada delay pada webhook penerimaan pesan, dan sistem tetap bekerja tanpa perlu menjalankan daemon/bot M.A.R.A terpisah.

## 4. Acceptance criteria

- **AC-1 (Tombol On-Demand UI)**:
  Given kasir sedang membuka percakapan di Inbox (`conversation_id` aktif),
  When panel percakapan dimuat,
  Then tampil tombol "Minta Saran AI" di area kontrol Inbox.
- **AC-2 (Loading State & Anti Double-Click)**:
  Given kasir menekan tombol "Minta Saran AI",
  When proses permintaan ke AI sedang berlangsung,
  Then tombol menjadi disabled dan menampilkan indikator loading/spinner sampai proses selesai.
- **AC-3 (Pengambilan Riwayat Ringkas)**:
  Given kasir memicu permintaan saran untuk suatu percakapan,
  When sistem menyiapkan konteks untuk AI,
  Then sistem mengambil semua pesan hari ini dari percakapan tersebut (default). Jika hari ini tidak ada pesan, sistem fallback mengambil 15 pesan terakhir tanpa batasan tanggal.
- **AC-4 (System Prompt Hardcode di Config)**:
  Given sistem akan memanggil AI,
  When prompt konteks disiapkan,
  Then system prompt diambil dari `app/Config/AiGateway.php` (hardcode, tidak perlu input kasir). Perubahan prompt dilakukan via edit file config dan deploy ulang.
- **AC-5 (Integrasi 9router Lokal)**:
  Given prompt konteks siap,
  When sistem memanggil endpoint `http://localhost:20128/v1/chat/completions` (model `aan-groq`),
  Then 9router mengembalikan struktur JSON berisi teks balasan, daftar checklist, dan (opsional) item keranjang yang teridentifikasi.
- **AC-6 (Penyimpanan Saran Sesuai Skema)**:
  Given respons JSON valid dari AI diterima,
  When sistem memproses respons,
  Then saran baru tersimpan di tabel `mara_suggestions` dengan status `active`, dan saran lama pada tipe yang sama otomatis di-supersede (`status = 'superseded'`).
- **AC-7 (Pembaruan UI Otomatis)**:
  Given penyimpanan saran berhasil,
  When API mengembalikan HTTP 200/201,
  Then UI saran kasir (`inbox-suggestions.js`) otomatis memuat ulang dan menampilkan kartu saran terbaru tanpa perlu refresh halaman penuh.
- **AC-8 (Penanganan Kegagalan / Timeout)**:
  Given 9router mati, kuota habis (429), atau koneksi gagal,
  When permintaan saran gagal diproses,
  Then kasir menerima pesan notifikasi toast/alert yang ramah ("Gagal mendapatkan saran AI, silakan coba lagi") dan UI kasir tidak hang/rusak.

## 5. Batasan dan di luar cakupan

- **Batasan**:
  - Panggilan AI menggunakan konfigurasi endpoint dan API key 9router yang sudah ada di lokal dev/server.
  - Waktu tunggu (timeout) panggilan ke 9router dibatasi maksimal 15 detik agar browser kasir tidak menggantung.
- **Di luar cakupan**:
  - Otomatisasi pengiriman balasan langsung ke nomor WhatsApp pelanggan (kasir tetap memegang kontrol kirim).
  - Webhook otomatis saat pesan baru masuk (Opsi 1 / M.A.R.A style ditunda untuk fase berikutnya).
  - Pelatihan model atau penyimpanan histori percakapan ke server luar.

## 6. Dampak aturan bisnis

- [ ] Mengubah aturan bisnis (wajib dicatat di `docs/CHANGELOG.md`)
- [ ] Menyentuh data keuangan (`transaksi`, `pembayaran`, `tagihan`, kas)
- [ ] Mengubah skema database (menggunakan tabel `mara_suggestions` yang sudah ada, tanpa migrasi baru)
- [ ] Mengubah kontrak POS <-> WA Gateway (perubahan murni di internal POS dan UI Inbox)

## 7. Asumsi dan pertanyaan terbuka

- **Asumsi**:
  - Model `aan-groq` di 9router sudah terkonfigurasi dengan API key yang aktif saat pengujian integrasi. Jika Groq bermasalah, 9router memiliki fallback (atau fallback internal service ke model combo lain).
- **Keputusan (dijawab)**:
  1. Tombol "Minta Saran AI" ditempatkan di panel saran (bukan header percakapan).
  2. Saran keranjang (`cart_items`) otomatis diisi berdasarkan analisa AI. Di panel saran sudah ada tombol "Buat Pesanan" yang langsung mengisi cart kasir dari hasil analisa tersebut (fitur existing `KeranjangDariSaran`).

## 8. Persetujuan (Gate 1)

- [x] Disetujui oleh: Aan, tanggal: 2026-10-19
