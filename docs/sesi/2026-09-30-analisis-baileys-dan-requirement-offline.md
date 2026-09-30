# Checkpoint Sesi — Analisis Baileys (@lid), requirement offline, dan arah pengganti

- **Tanggal**: 2026-09-30
- **Status**: sebagian (analisis selesai; keputusan solusi belum diambil)
- **Repo / branch**: `aulia-app` (v2.4) — tidak ada perubahan kode; analisis
  menyentuh sumber `baileys` 6.7.24 di `C:\Projects\WA-Gateway`.
- **Catatan**: lanjutan dari sesi yang sama —
  `docs/sesi/2026-09-30-investigasi-akar-penyebab-degraded.md` (bagian
  forensik/crash HP). File ini mencatat paruh kedua sesi.

## Latar belakang

Setelah investigasi forensik crash HP, sesi berlanjut ke: (1) kasus error
`fromMe:true @lid` yang mendominasi log Gateway, (2) apakah upgrade Baileys
layak, (3) requirement keras user tentang pesan saat PC mati semalaman.

## Selesai

- **Root cause `fromMe:true @lid` teridentifikasi di kode Baileys**:
  `decode-wa-message.js` (`isJidUser(sender) ? sender : author`) memakai JID
  `@lid` apa adanya, lalu `Signal/libsignal.js` `jidToSignalProtocolAddress()`
  men-decode LID jadi alamat sesi terpisah dari alamat PN yang sudah ada →
  sesi Signal untuk LID tidak ada → `SessionError: No matching sessions found`.
  Ini keterbatasan `libsignal-node` (belum punya LID↔PN mapping store), bukan
  bug di kode Gateway kita.
- **Versi dependency diverifikasi**: terpasang `baileys` 6.7.24; npm
  `dist-tags` = `latest: 7.0.0-rc14`, `legacy: 6.7.24`. Baileys 7.x = rewrite
  besar (migrasi ke `whatsapp-rust-bridge`, Rust→WASM), masih **release
  candidate**.
- **Risiko upgrade ke 7.0.0-rc14 dianalisis**: rewrite arsitektural; API
  internal berubah (kode custom kita bergantung pada perilaku 6.7.24 yang
  diverifikasi manual di `forwardMarker.js`, `baileysLoader.js`,
  `decryptTracker.js`, `connectionManager.js`); 29 skrip simulasi perlu
  verifikasi ulang; tidak ada jaminan LID di 7.x benar-benar lebih baik.
- **Mekanisme pesan offline Baileys diverifikasi**: `socket.js:520-527`
  (`CB:ib,,offline_preview` → minta `offline_batch` **count 100**, tanpa loop)
  dan `socket.js:547-556` (`CB:ib,,offline`, lapor jumlah offline); pesan
  ditandai `attrs.offline` → `messages-recv.js:699` upsert sebagai `'append'`.
- **Kode Gateway sudah menangani offline `append`**: commit `ae9f94b`
  (M1 W1 TASK-010) di `connectionManager.js:577-586,596,658`. `README.md` §9
  Gateway yang menyatakan reconciliation "tidak dijamin" **sudah basi** dan
  perlu diperbarui (kontradiksi dokumentasi ditandai, AGENTS.md §13).
- **Prompt untuk sesi lain** disusun dan diserahkan ke user (di chat, sengaja
  tidak disimpan): riset pengganti Baileys yang menjamin delivery
  (server-side/webhook), cocok untuk AuliaPos dengan penyesuaian minimal.

## Keputusan penting

- **Android dikesampingkan** — fokus deployment ke **Windows**.
- **PC Gateway akan dimatikan malam** (keputusan user) → reconciliation
  offline menjadi mekanisme utama, yang sifatnya **best-effort** dan belum
  pernah diuji nyata di setup toko.
- Arah baru: **mempertimbangkan mengganti Baileys** ke solusi yang menjamin
  delivery (riset di sesi terpisah), karena requirement "PC mati semalaman →
  pesan tetap masuk pagi" tidak bisa dijamin oleh linked device Baileys.

## Tersisa / TODO

- [ ] **Test 7 (reconciliation offline) secara nyata** dengan nomor uji +
      beberapa nomor pengirim, termasuk uji backlog >100 pesan. Belum ada
      mekanisme uji yang disepakati (bahas: pengirim manual vs script).
- [ ] **Riset pengganti Baileys** (jalankan prompt yang sudah disusun di sesi
      terpisah).
- [ ] **Bereskan kasus `fromMe:true @lid`** — pilih: (A) upgrade Baileys 7.x
      (berat, rc) atau (B) redam noise di deteksi `degraded` (kecil, tapi
      hanya meredam gejala). Belum diputuskan.
- [ ] **Perbarui `README.md` §9 WA-Gateway** yang basi soal reconciliation.
- [ ] **Auto-restart saat `CRASHED`** di `supervisor/processManager.js`
      (belum ada) — makin penting kalau mengandalkan PC nyala terus.
- [ ] Saat Gateway mau dipakai lagi: `Enable-ScheduledTask -TaskName
      "WA-Gateway"` di `aulia3` (saat ini `Disabled`).
- [ ] (warisan) `RewriteBase` `.htaccess` produksi; **ganti password `ops`**
      yang terekspos di chat.

## Belum diverifikasi / risiko

- Reconciliation offline **belum pernah diuji nyata**; batch tunggal 100
  pesan tanpa loop → backlog besar berisiko tidak tertarik seluruhnya.
- Retensi pesan offline di server WhatsApp **tidak terdokumentasi** — durasi
  PC mati yang masih aman tidak diketahui.
- Risiko **false-`degraded`**: saat catch-up pagi, flood `fromMe @lid` yang
  gagal dekripsi bisa memicu ambang `degraded` di deteksi baru meski sesi
  sebenarnya sehat.
- Apakah Baileys 7.x benar-benar memperbaiki LID **belum diverifikasi**
  (hanya dugaan dari pola rewrite).

## Titik masuk sesi berikutnya

- **Baca**: file ini, lalu
  `docs/sesi/2026-09-30-investigasi-akar-penyebab-degraded.md`, lalu adendum
  §13 di `docs/sesi/2026-09-30-wa-gateway-sesi-degraded.md`.
- **Jalankan**: `git -C C:\xampp\htdocs\aulia-app status` dan
  `git -C C:\Projects\WA-Gateway status` untuk pastikan tidak ada drift.
- **Menunggu keputusan user**: pilih jalur solusi (bereskan `@lid` di
  Baileys vs ganti ke solusi lain), lalu laksanakan Test 7.
