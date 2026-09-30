# Checkpoint Sesi

- **Tanggal**: 2026-09-30
- **Status**: sebagian (riset selesai; spike Fonnte terbukti jalan; keputusan solusi final ditahan)
- **Repo / branch**: `aulia-app` (v2.4, working tree tidak diubah selain 1 dokumen
  baru) + `WA-Gateway` (`C:\Projects\WA-Gateway`, branch `spike/fonnte`)

## Selesai

- Riset lengkap alternatif pengganti Baileys, disimpan di
  `docs/riset-alternatif-baileys.md` (19 bagian: perbandingan Cloud API resmi vs
  BSP vs unofficial, kebijakan WA Business Platform, estimasi biaya Rupiah,
  catatan khusus Kirimi/Fonnte, perbandingan library unofficial lain
  whatsapp-web.js/WPPConnect/open-wa/Evolution API, adendum hasil uji nyata).
- **Spike adapter Fonnte** (unofficial) di branch `spike/fonnte` WA-Gateway,
  TERBUKTI end-to-end tanpa mengubah kode AuliaPos:
  - commit `3cd2f8b` — adapter awal (mempertahankan kontrak CI4 `/send`,
    `/send-media`, `/media/download`, heartbeat; reuse buffer SQLite +
    delivery + idempotensi `operation_id` dari WA-Gateway).
  - commit `46d2ff2` — fix bug status optimistic yang keliru dianggap basi
    (menyebabkan badge Inbox salah tampil "Terputus" setelah ±2 menit).
  - Uji nyata: teks masuk (Fonnte→adapter→CI4→Inbox UI) OK; teks keluar
    (Inbox UI→CI4→adapter→Fonnte→WA) OK; forward (`text_fallback`) OK;
    heartbeat→badge "Terhubung" OK.
  - Temuan penting: **media gagal di paket gratis Fonnte, dan gagal SENYAP**
    saat ada caption (`success:true` tapi lampiran dibuang) — risiko integritas
    data, dicatat di riset §17.
- Perbandingan harga **Fonnte vs Kirimi** untuk kebutuhan media (§19 riset):
  Fonnte baru bisa kirim media di paket Super **Rp 165.000/bln**; Kirimi sudah
  bisa di paket Lite **Rp 29.000/bln** — ~6x lebih murah. Kirimi **belum diuji
  langsung** (baru dari dokumentasi).
- Penjelasan ke user: named tunnel vs VPS (beda tujuan: stabilitas URL vs
  ketersediaan 24 jam saat PC mati); rekomendasi VPS/BSP untuk requirement
  keras "PC mati semalam → pesan tetap masuk".
- `aulia_inboxdb` lokal (MySQL dev) dikosongkan atas permintaan user sebelum
  uji (TRUNCATE 5 tabel, skema dipertahankan) — bukan operasi destruktif
  produksi, hanya DB dev lokal.
- Disusun **prompt self-contained** untuk sesi terpisah: membangun repo BARU
  `evolution-gateway` (adapter Evolution API↔AuliaPos, kontrak CI4 sama),
  disimpan di `plan/2026-09-30-prompt-evolution-api-gateway.md`.

## Keputusan penting

- **Prioritas user**: menghilangkan error `fromMe:true @lid` > jaminan
  delivery > app tetap hidup (Coexistence) > biaya semurah mungkin — alasan:
  dinyatakan eksplisit oleh user di sesi ini.
- **Peringkat opsi resmi vs unofficial** (riset §15): hanya jalur Cloud API
  resmi (Gupshup/360dialog/Twilio/Kirimi-WABA) yang menghilangkan `@lid`
  secara struktural; semua varian unofficial (Baileys, Fonnte, Kirimi-QR,
  whatsapp-web.js, WPPConnect, open-wa, Evolution-mode-Baileys) **tidak**
  menyelesaikannya — hanya memindahkan tempat hosting sesi.
- **Spike Fonnte dijalankan** murni sebagai pembuktian pola "adapter yang
  mempertahankan kontrak CI4" — alasan: user eksplisit minta uji coba
  ("saya mau test pake fonnte.com"), bukan keputusan pindah produksi.
- Kirimi direkomendasikan di atas Fonnte **khusus untuk kebutuhan media** bila
  tetap ingin unofficial — alasan: harga media 6x lebih murah (§19), namun
  belum diuji lapangan (beda dengan Fonnte yang sudah).

## Tersisa / TODO

- [ ] Keputusan final: tetap unofficial (Fonnte/Kirimi) atau naik ke jalur
      resmi (Gupshup/360dialog/Evolution-Cloud-API) — belum diputuskan user.
- [ ] Jika lanjut unofficial: guard anti-silent-drop untuk `/send-media` di
      adapter (jangan laporkan sukses kalau lampiran dibuang provider).
- [ ] Jika lanjut unofficial: jalankan spike serupa untuk Kirimi sebelum
      dipercaya (owner: sesi lanjutan, pakai pola yang sama dengan `spike/fonnte`).
- [ ] Jika mau coba Evolution API: jalankan prompt di
      `plan/2026-09-30-prompt-evolution-api-gateway.md` di sesi/repo terpisah.
- [ ] `supervisor/` WA-Gateway belum mendukung entry point non-Baileys
      (hardcoded ke `src/app/index.js`) — perlu disesuaikan bila adapter mana
      pun dipakai lebih dari uji manual.
- [ ] Minta tarif Meta per pesan Indonesia (rate card resmi) — belum ada
      angka, dibutuhkan untuk estimasi biaya akurat jalur resmi (owner: user,
      lewat akun Meta Business atau BSP terpilih).

## Belum diverifikasi / risiko

- Dokumen resmi Meta (`developers.facebook.com`) tidak bisa diakses dari
  lingkungan ini (HTTP 400) — semua kebijakan resmi dikutip dari dokumentasi
  BSP pihak ketiga (360dialog/Gupshup/Twilio), bukan langsung dari Meta.
- Durasi pasti retry webhook Meta (≈24 jam vs 7 hari, sumber tidak konsisten).
- Dukungan Coexistence di Gupshup untuk Indonesia + kelayakan nomor spesifik
  — belum dikonfirmasi ke Gupshup.
- Status "Meta Tech Provider" Kirimi — belum diverifikasi ke direktori Meta.
- Adapter Fonnte hanya diuji di lingkungan dev lokal dengan nomor uji
  (62881082323928), tunnel Cloudflare quick tunnel (URL berubah tiap
  restart) — **belum** diuji dengan skenario "PC/tunnel mati lalu nyala lagi"
  (retry recovery `runStartupRecovery` ada di kode tapi belum diuji lapangan
  untuk kasus ini).
- Saat checkpoint ini ditulis, `background_process list` pada sesi ini **kosong**
  → adapter Fonnte (port 3000) + tunnel Cloudflare dari tahap uji **sudah tidak
  berjalan** (proses bersifat session-lifetime). Untuk mengulang uji: jalankan
  ulang adapter + tunnel, lalu **daftarkan ulang URL webhook** di Fonnte
  (quick tunnel berganti URL tiap restart).
- `.env` WA-Gateway lokal berisi `FONNTE_TOKEN` nyata (kredensial aktif,
  bukan dummy) — sudah gitignored, tapi perlu rotate bila dianggap bocor.

## Titik masuk sesi berikutnya

- **Baca**: `docs/riset-alternatif-baileys.md` (terutama §14 soal `@lid`, §15
  peringkat, §17 hasil uji nyata, §19 Fonnte vs Kirimi); branch `spike/fonnte`
  di `C:\Projects\WA-Gateway` (commit `3cd2f8b`, `46d2ff2`); dan
  `plan/2026-09-30-uji-fonnte-adapter.md` untuk status tahapan uji.
- **Jalankan** (bila melanjutkan uji Fonnte): cek proses aktif dulu, lalu
  `git -C C:\Projects\WA-Gateway log --oneline spike/fonnte -5` untuk konteks;
  test: `node test/simulate-fonnte-adapter.js` dan
  `node test/simulate-fonnte-boot.js` (workdir `C:\Projects\WA-Gateway`).
- **Bila mulai Evolution API**: buka sesi/agent baru, tempel seluruh isi
  `plan/2026-09-30-prompt-evolution-api-gateway.md` sebagai prompt pembuka.
