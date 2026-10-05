# Checkpoint Sesi

- **Tanggal**: 2026-10-05
- **Status**: sebagian (server-side HTTPS aktif & kode ter-deploy; trust PC kasir lain + uji E2E notifikasi Windows belum)
- **Repo / branch**: `AuliaPos` / `v2.4`

## Selesai

- Sertifikat self-signed baru terpasang di **AULIA-SERVER2** (`apache\conf\ssl.crt\server.crt`
  + `apache\conf\ssl.key\server.key`): `CN=192.168.1.10`, SAN
  `IP:192.168.1.10, DNS:AULIA-SERVER2, DNS:localhost`, berlaku s/d 2036-10-02, thumbprint
  `FBF775F4F822CF87BECCA6083CEFF2FC9CD79168`.
- Apache di server2 di-restart oleh user; HTTPS aktif dan menyajikan cert baru (diverifikasi
  lewat handshake TLS).
- `.env` produksi `app.baseURL` → `https://192.168.1.10/aulia/` (backup `.env.bak-20261005-170129`).
- Kode POS produksi fast-forward `a127d63` → **`80df50f`** (`v2.4`, notifikasi Windows).
- `.bat` impor sertifikat untuk PC kasir: `\\aulia-server2\xampp\import-sertifikat-aulia.bat`
  (self-elevate, ambil cert dari share, idempoten).

## Verifikasi

- `https://192.168.1.10/aulia/` → 200 ke `https://.../login`; sertifikat = cert baru (SAN benar).
- `http://192.168.1.10/aulia/index.php/login` → 200 (tanpa redirect paksa ke https) →
  **port 80 tetap melayani gateway**.
- `POST http://192.168.1.10/aulia/api/inbox/gateway/status` → **401 JSON** (bukan redirect) →
  endpoint gateway via http masih berfungsi.
- Asset `https://192.168.1.10/aulia/assets/js/inbox-notifikasi.js` memuat fungsi baru
  (`tampilkanNotifikasiWindows`, `gunakanJalurWindows`, `mintaIzinNotifikasi`).
- Sertifikat sudah dipercaya di PC ini (`LocalMachine\Root`).

## Keputusan penting

- Self-signed leaf (bukan CA) — alasan: langkah paling sedikit, cukup untuk 1 server.
- HTTP:80 dibiarkan tanpa redirect 80→443 — alasan: gateway memakai `CI4_BASE_URL` http.

## Tersisa

- Lihat `docs/TODO.md` **TODO-N1**: jalankan `.bat` di tiap PC kasir yang belum, lalu uji
  E2E notifikasi Windows di browser.

## Belum diverifikasi / risiko

- Notifikasi Windows muncul nyata (izin, toast Windows, klik buka percakapan) **belum diuji**
  di browser kasir.
- PC kasir selain PC ini belum dikonfirmasi sudah impor sertifikat.
- Temuan di luar scope: `.env` produksi `CI_ENVIRONMENT = development`.

## Perbaikan regresi: Realtime WebSocket (setelah HTTPS)

- Gejala: setelah HTTPS, badge Inbox "Realtime: Error" — halaman HTTPS tidak boleh membuka
  `ws://AULIA3:3000` (mixed content). `Inbox::apiRealtimeTicket()` membangun `ws_url` dari
  `inbox.gatewayBaseUrl`.
- Perbaikan (Opsi A, same-origin proxy):
  - Apache server2: aktifkan `mod_proxy_http` + `mod_proxy_wstunnel`; tambah
    `ProxyPass "/realtime-ws" "ws://AULIA3:3000/realtime"` di vhost 443
    (config di-backup `httpd.conf.bak-20261005-170440` / `httpd-ssl.conf.bak-20261005-170440`).
  - Kode: `App\Libraries\InboxRealtimeWs::url()` baru; `apiRealtimeTicket()` memakai
    `wss://<host>/realtime-ws` saat HTTPS, `ws://<gateway>/realtime` saat HTTP/dev.
- Verifikasi: `GET https://192.168.1.10/realtime-ws` → `{"ok":false,"error":"Not found"}`
  (dari gateway/Express, membuktikan proxy tembus); WS upgrade tanpa ticket valid →
  **401 Unauthorized** dari gateway. Gateway hanya cek path `/realtime` + ticket
  (`C:\Projects\evolution-gateway\src\realtime\server.js:119-128`), tidak cek Origin/Host.
- Unit test: `tests/unit/InboxRealtimeWsTest.php` — suite unit **57/57 lulus**.

## Titik masuk sesi berikutnya

- **Baca**: `docs/design/2026-10-05-https-self-signed-aulia-server2.md`, `docs/TODO.md` (TODO-N1)
- **Jalankan**: buka `https://192.168.1.10/aulia/` di PC kasir → klik tombol "Aktifkan
  notifikasi" → kirim pesan WA masuk → pastikan notifikasi Windows muncul.
