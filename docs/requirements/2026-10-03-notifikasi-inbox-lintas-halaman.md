# Requirements: Notifikasi Inbox lintas halaman (judul tab, favicon, suara, toast)

- **Tanggal**: 2026-10-03
- **Status**: disetujui (rencana disetujui user di sesi; verifikasi manual di Brave masih menunggu, feature test butuh DB uji yang belum tersedia di sesi ini)
- **Tier SDLC**: A (fitur baru, menambah satu endpoint baca + layout global; tanpa skema baru, tanpa kontrak Gateway)
- **Penanggung jawab**: user (pemilik toko)

## 1. Tujuan

Kasir langsung tahu ada pesan WhatsApp yang perlu dibalas walau sedang di halaman lain (misalnya layar Kasir), tidak hanya saat membuka window Inbox.

## 2. Kondisi saat ini (terverifikasi sebelum perubahan)

- `app/Views/layout/main.php`: badge angka sidebar (`sidebarInboxBadge`) polling `GET /inbox/api/perlu-dibalas-count` tiap 20 detik — satu-satunya mekanisme lintas halaman, tapi cuma angka diam, tidak menarik perhatian.
- Tidak ada judul tab, favicon, suara, atau toast untuk pesan Inbox di halaman mana pun.
- `/inbox` dibuka sebagai popup window terpisah (`window.open(..., 'AuliaInbox', ...)`), tidak tahu-menahu soal window utama (Kasir, dst).

## 3. User story

- Sebagai kasir, saya ingin tahu lewat judul tab/favicon kalau ada percakapan yang perlu dibalas, supaya saya tidak perlu terus membuka window Inbox untuk mengecek.
- Sebagai kasir, saya ingin dengar bunyi dan lihat toast saat ada pesan baru yang jadi tanggung jawab saya (atau belum ada yang pegang), supaya saya bisa langsung respons walau sedang di layar Kasir.
- Sebagai kasir, saya ingin bisa membisukan suara itu kalau mengganggu.

## 4. Acceptance criteria

- **AC-1**: Given percakapan `perlu_dibalas` yang belum ada yang pegang ATAU dipegang user yang login, when poller lintas halaman berjalan, then percakapan itu masuk hasil `GET /inbox/api/notifikasi-ringkas`; percakapan milik kasir lain, grup, atau bukan `perlu_dibalas` tidak masuk (admin melihat semua, konsisten dengan `cekOwnership()`).
- **AC-2**: Given jumlah item hasil endpoint di atas > 0, then judul tab diawali `(N) ` dan favicon menampilkan titik merah; N = 0 mengembalikan judul/favicon asli.
- **AC-3**: Given sebuah percakapan relevan BARU muncul atau `last_message_at`-nya berubah sejak polling sebelumnya (dalam sesi tab yang sama), when poller berjalan, then muncul toast + bunyi beep (kecuali dibisukan); percakapan yang sudah pernah dilaporkan dengan `last_message_at` yang sama TIDAK memicu toast/bunyi lagi.
- **AC-4**: Given ini adalah polling PERTAMA sejak tab dibuka (belum ada riwayat di `sessionStorage`), then percakapan yang sudah `perlu_dibalas` sebelumnya TIDAK memicu toast/bunyi (hanya dicatat diam-diam); judul tab/favicon tetap menyala sesuai jumlah saat itu.
- **AC-5**: Given kasir pindah halaman (navigasi biasa, full page reload), then percakapan yang sudah pernah dilaporkan tidak memicu toast ulang (state tersimpan di `sessionStorage`, bertahan antar reload dalam tab yang sama).
- **AC-6**: Given toast diklik, then window Inbox (`'AuliaInbox'`) terbuka/fokus langsung ke percakapan itu lewat `?conversation_id=`.
- **AC-7**: Given tombol lonceng di sidebar diklik, then status bisu/tidak tersimpan di `localStorage` dan bertahan lintas sesi; saat dibisukan, beep tidak dibunyikan (toast & judul tab/favicon tetap jalan).

## 5. Batasan dan di luar cakupan

- Batasan teknis: notifikasi hanya jalan selagi tab/browser terbuka (tidak ada Web Notification API/push); beep bisa diblokir kebijakan autoplay browser sebelum ada interaksi user di tab itu — dibungkus try/catch, kegagalan beep tidak mengganggu toast.
- Tidak termasuk: notifikasi sistem (Web Notification API), notifikasi tetap menyala walau browser ditutup, perubahan kontrak Gateway, perubahan `apiPerluDibalasCount` yang sudah ada.

## 6. Dampak aturan bisnis

- [ ] Mengubah aturan bisnis (wajib dicatat di `docs/CHANGELOG.md`)
- [ ] Menyentuh data keuangan (`transaksi`, `pembayaran`, `tagihan`, kas)
- [ ] Mengubah skema database
- [ ] Mengubah kontrak POS <-> WA Gateway (perubahan lintas repositori)

Catatan: perubahan perilaku UI/notifikasi dicatat di CHANGELOG sebagai perubahan operasional.

## 7. Asumsi dan pertanyaan terbuka

- Asumsi: kepemilikan "punya saya"/"belum ada yang pegang" mengikuti aturan yang sama dengan `cekOwnership()` (admin selalu boleh/melihat semua).
- Belum diverifikasi: tampilan & bunyi di Brave kasir sungguhan; perilaku kebijakan autoplay audio Brave untuk beep; `InboxNotifikasiRingkasTest` (feature test) belum pernah dijalankan — tidak ada database uji (`aulia_inboxdb_test`) yang bisa dijangkau di sandbox sesi ini.

## 8. Persetujuan (Gate 1)

- [x] Disetujui oleh: user, tanggal: 2026-10-03 (rencana di sesi)
