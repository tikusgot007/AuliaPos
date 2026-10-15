# Requirements: M.A.R.A mengirim saran ke kasir (balasan / daftar-periksa / peringatan)

- **Tanggal**: 2026-10-13
- **Status**: DISETUJUI Gate 1 (2026-10-13)
- **Tier SDLC**: A (fitur baru + perubahan skema database)
- **Penanggung jawab**: pemilik proyek

## 1. Tujuan

Memberi M.A.R.A (asisten AI pemantau Inbox yang jalan di `aulia7`) satu jalur resmi untuk
mengirim **saran** kepada kasir lewat AuliaPos: draft balasan siap-pakai, daftar-periksa
langkah, dan peringatan. Kasir melihat saran itu di halaman Inbox saat membuka percakapan.
Ini melengkapi arah yang sudah ada (AuliaPos → M.A.R.A lewat webhook, 2026-10-13) dengan arah
kebalikannya (M.A.R.A → AuliaPos → kasir).

## 2. Kondisi saat ini (terverifikasi)

Fakta dari repo ini:

- **Jalur AuliaPos → M.A.R.A sudah live** (webhook realtime, terverifikasi end-to-end di
  production 2026-10-13): `app/Libraries/MaraWebhookDispatcher.php` di-hook di
  `InboxGatewayApi::messages()` dan `Inbox::kirimKeConversation()`/`kirimMedia()`. Payload
  mengirim `direction`, sehingga M.A.R.A **membuang pesan keluar** (`direction="outgoing"`).
- **Endpoint M2M M.A.R.A → AuliaPos yang sudah ada**: `GET /api/inbox/mara/export/conversations`
  dan `GET /api/inbox/mara/resolve/{id}` (`app/Config/Routes.php:47-48`), diproteksi filter
  `maratoken` (`app/Config/Filters.php:36`, `app/Filters/MaraTokenFilter.php`).
- **Endpoint M2M sudah dikecualikan dari session `auth`**: `app/Config/Filters.php:139-142`
  (`api/inbox/mara/*`) **dan** pengecekan eksplisit di `app/Filters/AuthFilter.php:33-34`
  (`strpos($uri, 'api/inbox/mara/')`). Jadi endpoint `suggestions` baru otomatis tidak kena
  redirect `/login` — **tidak perlu** mengubah `AuthFilter`/`Filters.php` lagi.
- **Pola tabel + model di grup `inbox`**: `app/Database/Migrations/2026-10-13-000001_CreateMaraResolveLog.php`
  dan `app/Models/MaraResolveLogModel.php:12,14` (`$DBGroup = 'inbox'`). Tabel saran baru
  mengikuti pola ini.
- **Endpoint browser kasir memakai filter `auth`** (`app/Config/Routes.php:52-92`), contoh
  `GET /inbox/api/conversations/(:num)/messages` (`Inbox::apiMessages`, `Inbox.php:426`).
- **Titik kasir membalas**: `Inbox::kirimKeConversation()` (`Inbox.php:3136`) untuk teks,
  `Inbox::kirimMedia()` (`Inbox.php:1472`) untuk media. Keduanya dipanggil dari route
  `POST /inbox/kirim` / `/inbox/kirim-media` (session `auth`).
- **Kanál realtime ke browser sudah ada** (untuk opsi push nanti): WS gateway `aulia7`
  (`/realtime`, tiket dari `Inbox::apiRealtimeTicket()`, `Inbox.php:628`), handler browser di
  `public/assets/js/inbox-page.js`.

Fakta yang **belum diverifikasi** (perlu dibaca sebelum implementasi UI):

- Struktur render panel Inbox di `app/Views/inbox/index.php` dan `public/assets/js/inbox-page.js`
  (tempat menaruh panel saran, cara mengisi kotak balasan dari sebuah saran) — belum ditelusuri
  di sesi ini.

## 3. User story

- Sebagai kasir, saya ingin melihat **draft balasan** dari M.A.R.A dan mengisinya ke kotak balasan
  dengan sekali klik, supaya saya membalas lebih cepat dan konsisten.
- Sebagai kasir, saya ingin melihat **daftar-periksa** langkah (yang bisa saya centang satu-satu)
  untuk order rawan salah (mis. cetak foto banyak ukuran), supaya saya tidak melewatkan langkah.
- Sebagai kasir, saya ingin melihat **peringatan** (mis. chat mendekati SLA, order besar) dengan
  warna yang jelas, supaya saya memprioritaskan percakapan yang tepat.

## 4. Acceptance criteria

Endpoint tulis (M.A.R.A → AuliaPos):

- **AC-1**: Given request `POST /api/inbox/mara/suggestions` **tanpa** `Authorization: Bearer`
  yang benar, when dipanggil, then dibalas **401** dan tidak ada baris yang disimpan.
- **AC-2**: Given token benar dan body valid
  (`conversation_id`, `type` ∈ {balasan, daftar-periksa, peringatan}, `priority` ∈
  {normal, tinggi, darurat}), when dipanggil, then dibalas **200/201** dengan `id` saran baru,
  dan baris tersimpan `status='active'`.
- **AC-3**: Given `type='daftar-periksa'`, when `items` **bukan** array string atau kosong, then
  dibalas **400** (ditolak) dan tidak ada baris yang disimpan.
- **AC-4**: Given `conversation_id` tidak ada di `conversations`, when dipanggil, then dibalas
  **404/400** dan tidak ada baris yang disimpan.
- **AC-5**: Given sudah ada saran `active` bertipe sama pada percakapan itu, when saran baru
  bertipe sama dikirim, then saran lama `status` → `superseded` dan yang baru `active`
  (maks 1 aktif per tipe; total maks 3 per percakapan).
- **AC-6**: Given `expires_at` tidak dikirim, when saran disimpan, then AuliaPos mengisi default
  (mis. `now + 30 menit`) — saran tidak pernah tersimpan tanpa batas waktu.
- **AC-7**: Given body melebihi batas ukuran / field tak dikenal, when dipanggil, then permintaan
  ditolak dengan pesan jelas (tidak menyimpan data setengah jadi).

Endpoint baca (kasir):

- **AC-8**: Given percakapan punya saran `active` yang belum lewat `expires_at`, when kasir
  membuka percakapan, then `GET /inbox/api/conversations/(:num)/suggestions` mengembalikan saran
  itu; saran `superseded`/`expired` **tidak** dikembalikan.
- **AC-9**: Given saran `active` sudah lewat `expires_at`, when dibaca, then saran itu **tidak
  ditampilkan** (dianggap hilang), tanpa perlu ditandai "basi" di layar.
- **AC-10**: Given kasir berhasil membalas percakapan (teks atau media), when kirim sukses, then
  semua saran `active` pada percakapan itu di-set `expired` (karena M.A.R.A tidak menerima event
  pesan keluar, jadi tidak tahu kasir sudah membalas).
- **AC-11**: Given kasir membalas **gagal** (kirim tidak sukses), when terjadi, then status saran
  **tidak** berubah (jangan tandai expired sebelum balasan benar-benar terkirim).

Tampilan:

- **AC-12**: Given saran `balasan`, when ditampilkan, then kasir bisa memakainya untuk mengisi
  kotak balasan (bukan otomatis terkirim).
- **AC-13**: Given saran `daftar-periksa`, when ditampilkan, then setiap item tampil sebagai baris
  yang bisa dicentang terpisah.
- **AC-14**: Given `priority` `normal`/`tinggi`/`darurat`, when ditampilkan, then warnanya
  netral/biru, kuning, dan merah.
- **AC-15**: Given teks saran dari M.A.R.A (tidak dipercaya), when dirender, then di-escape
  (tidak ada XSS), mengikuti pola render Inbox yang ada.

## 5. Batasan dan di luar cakupan

- **Batasan**:
  - M.A.R.A **tidak pernah** menyentuh DB/gateway/browser langsung — semua lewat kontrak
    AuliaPos (`POST /api/inbox/mara/suggestions`).
  - Mulai dengan **"cukup simpan"** (Opsi A): kasir melihat saran saat membuka/menyegarkan
    percakapan. Delay beberapa detik masih jauh di dalam SLA 15 menit.
  - Token memakai `mara.apiToken` yang sama dengan export/resolve (bukan token WA-Gateway).
- **Tidak termasuk**:
  - Push realtime (Opsi B) — ditunda sampai benar-benar perlu.
  - Auto-reply: saran **tidak** pernah terkirim otomatis; kasir yang memutuskan.
  - Kasus instan keluhan/pelanggan marah — ditangani M.A.R.A terpisah (lapor ke AAN), bukan
    lewat saran ke kasir.
  - `POST /api/inbox/mara/status` (heartbeat) — terpisah, lihat TODO-F10.
  - Perubahan pada webhook/export/resolve yang sudah live.

## 6. Dampak aturan bisnis

- [x] Mengubah skema database — **tabel baru** `mara_suggestions` di grup `inbox`
  (`aulia_inboxdb`); tidak mengubah tabel yang ada. Perlu migrasi saat deploy produksi.
- [x] Menambah kontrak API baru M.A.R.A → AuliaPos (`POST /api/inbox/mara/suggestions`) dan
  endpoint browser baru (GET suggestions). Bukan perubahan breaking pada kontrak lama.
- [ ] Menyentuh data keuangan (`transaksi`, `pembayaran`, `tagihan`, kas) — tidak langsung.
- [x] Menambah perilaku baru di Inbox (panel saran) → **wajib dicatat di `docs/CHANGELOG.md`**
  saat diimplementasikan.

## 7. Asumsi dan pertanyaan terbuka

**Asumsi:**

- M.A.R.A memakai token yang sama (`mara.apiToken`) dan mengirim satu saran per POST.
- Saran kedaluwarsa "hilang otomatis" — tidak ada aksi hapus manual dari kasir.
- `conversation_id` yang dikirim M.A.R.A adalah `conversations.id` asli (bukan hash).

**Pertanyaan (mengubah hasil) — SUDAH DIJAWAB user 2026-10-13:**

1. **Bentuk default `expires_at`** bila M.A.R.A tidak mengirimnya — **DIPUTUSKAN: `now + 30 menit`**
   (sesuai spek M.A.R.A). Saran tidak pernah tersimpan tanpa batas waktu.
2. **Penandaan expired saat kasir membalas** — **DIPUTUSKAN: set `status='expired'`, baris TETAP ADA**
   (tidak dihapus) sebagai jejak audit.
3. **Bentuk balasan endpoint tulis** — **DIPUTUSKAN: `{status, data:{id}}`** (konsisten gaya endpoint
   lain). **✅ KONFIRMASI FINAL dari M.A.R.A (2026-10-13, via Telegram)**: sukses = HTTP 201
   `{"status":"ok","data":{"id":<int>}}`; gagal = `{"status":"error","message":"..."}` (400/401/404/500).
   Seluruh kontrak lain juga dikonfirmasi cocok (type/priority, items WAJIB untuk daftar-periksa,
   text WAJIB untuk balasan/peringatan, expires_at default now+30m, maks 1 aktif per tipe,
   hilang saat kasir membalas termasuk dari WA Web/HP). **Tidak ada perubahan kontrak.**

## 8. Persetujuan (Gate 1)

- [x] Disetujui oleh: pemilik proyek, tanggal: 2026-10-13 (Gate 1 — balasan "lanjut")

## 9. Rujukan

- Spek dari M.A.R.A (via Telegram, 2026-10-13): 3 jenis saran; maks 3 aktif (1+1+1);
  `expires_at` hilang otomatis (kasir membalas atau 30 menit); `priority`
  normal/tinggi/darurat; `daftar-periksa` sebagai array `items`; contoh payload
  `{"conversation_id":403,"type":"balasan","priority":"normal","text":"...","items":null,"expires_at":"2026-10-13T17:00:00+07:00"}`.
- `docs/TODO.md` item **TODO-MARA1** (butir 1).
- `docs/design/2026-10-13-mara-saran-ke-kasir.md` (desain teknis).
- Webhook AuliaPos → M.A.R.A (arah sebaliknya, sudah live): dokumen serah terima M.A.R.A
  2026-10-13.
