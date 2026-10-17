# Requirements: Worker reconciler WAGRAB dan alarm Inbox

- **Tanggal**: 2026-10-17
- **Status**: draf
- **Tier SDLC**: A
- **Penanggung jawab**: Aan

## 1. Tujuan

Menjalankan worker berkala di gateway `aulia7` untuk membaca pesan WhatsApp masuk yang terekam WAGRAB, mengirim kandidat dalam jendela waktu aman ke endpoint rekonsiliasi AuliaPos, lalu memberi alarm bila ada pesan yang benar-benar ditambal.

## 2. Kondisi saat ini (terverifikasi)

- WAGRAB receiver di `aulia7` memakai Node.js native dan SQLite; script receiver berada di `C:\AuliaPosGateway\wagrab-receiver\receiver.js`, database berada di `C:\AuliaPosGateway\wagrab-receiver\wa_messages.db`, dan task `WAGRAB-Receiver` berjalan sebagai `SYSTEM` (`docs/sesi/2026-10-17-pembanding-wagrab-vs-inbox.md:41-46`).
- Skema receiver menyimpan `wa_message_id`, `message_timestamp`, `direction`, `message_type`, `text`, `caption`, `file_name`, dan `phone_raw` (`C:\Projects\WAGRAB\receiver\receiver.js:13-43`; salinan dev, kesetaraan file produksi belum diverifikasi).
- Pembanding WAGRAB menemukan 44 kandidat pesan valid yang tidak ada di Inbox, terutama gambar dan satu dokumen (`docs/sesi/2026-10-17-pembanding-wagrab-vs-inbox.md:14-23`).
- Endpoint `POST /api/inbox/sync-missing` telah dinyatakan aktif dan lulus tes oleh pemohon. Implementasi/konfigurasi production endpoint belum diperiksa pada fase ini.

## 3. User story

- Sebagai pemilik toko, saya ingin pesan masuk yang drop dari Evolution tetapi tertangkap WAGRAB ditambal otomatis ke Inbox POS agar kasir tetap melihat riwayat percakapan.
- Sebagai pemilik toko, saya ingin menerima alarm hanya ketika worker benar-benar memasukkan pesan yang sebelumnya hilang agar dapat mengecek WhatsApp Web untuk media fisik.

## 4. Acceptance criteria

- **AC-1**: Given database WAGRAB berisi pesan dalam window UTC `now - 30 minutes` sampai `now - 2 minutes`, when worker berjalan, then worker hanya memilih pesan `direction='in'`, bukan `album`, `revoked`, atau `ciphertext`, dalam urutan `message_timestamp ASC`.
- **AC-2**: Given kandidat valid ditemukan, when token dan endpoint valid, then worker mengirim payload JSON ke `POST http://192.168.1.10/api/inbox/sync-missing` memakai header `X-Internal-Token` dan mencatat hasil respons.
- **AC-3**: Given respons endpoint `status='ok'` dan `inserted > 0`, when pengiriman selesai, then log memuat timestamp, jumlah dipulihkan, dan pengirim; Telegram menerima alarm Markdown bila kedua kredensial Telegram tersedia.
- **AC-4**: Given token Telegram atau chat ID kosong, when pesan dipulihkan, then rekonsiliasi tetap sukses dan worker tidak gagal hanya karena alert dilewati.
- **AC-5**: Given konfigurasi/token tidak tersedia, respons HTTP bukan 2xx, respons JSON tidak valid, atau SQLite gagal dibaca, when worker berjalan, then worker keluar non-zero, menulis error tanpa mencetak secret, dan tidak mengubah database WAGRAB.
- **AC-6**: Given task `WAGRAB-Reconciler` terdaftar, when host hidup tanpa login interaktif, then task berjalan setiap 3 menit tanpa batas pengulangan sebagai `SYSTEM` dan action menjalankan file `.mjs` dengan executable Node yang terverifikasi.
- **AC-7**: Given manual dry-run, when dilakukan sebelum task dijalankan, then output menyatakan jumlah kandidat terdeteksi dan tidak mengirim request atau alert.
- **AC-8**: Given manual execution normal, when dilakukan, then jumlah record terdeteksi/dikirim serta status task dibuktikan oleh output nyata dari `aulia7`.

## 5. Batasan dan di luar cakupan

- Batasan teknis: Node.js built-in saja (`node:sqlite`, `fetch`, `node:fs`, `node:path`); tanpa dependensi npm.
- Batasan keamanan: token tidak boleh di-hardcode, ditampilkan, dikirim ke log, atau masuk Git. File konfigurasi harus berada di luar repo dan dapat dibaca `SYSTEM`.
- Batasan data: worker hanya membaca SQLite WAGRAB; idempotensi insert tetap diputuskan endpoint AuliaPos melalui `wa_message_id`.
- Tidak termasuk: perbaikan Signal/Bad MAC, download file media dari WhatsApp Web, restart Evolution, perubahan schema SQLite/Inbox, atau pengiriman ulang media.

## 6. Dampak aturan bisnis

- [x] Mengubah aturan bisnis (wajib dicatat di `docs/CHANGELOG.md`)
- [ ] Menyentuh data keuangan (`transaksi`, `pembayaran`, `tagihan`, kas)
- [ ] Mengubah skema database
- [x] Mengubah kontrak POS <-> WA Gateway (perubahan lintas repositori)

## 7. Asumsi dan pertanyaan terbuka

- Asumsi: endpoint membolehkan satu payload array kandidat dan membalas `{status, inserted, ignored}` seperti spesifikasi pemohon.
- Asumsi: Node v22+ yang dipakai receiver juga menyediakan `node:sqlite` dan `fetch` pada konteks task `SYSTEM`.
- Pertanyaan: token internal yang sama dengan CI4 akan dipasang manual oleh Aan pada file konfigurasi lokal `C:\AuliaPosGateway\config\wagrab-reconciler.json`; worker hanya membaca file tersebut dan tidak pernah membuat/menampilkan token. Setujui lokasi ini?

## 8. Persetujuan (Gate 1)

- [ ] Disetujui oleh: <nama>, tanggal: <YYYY-MM-DD>
