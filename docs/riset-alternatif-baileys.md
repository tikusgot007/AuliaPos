# Riset: Alternatif Pengganti Baileys untuk WA Gateway AuliaPos

- **Tanggal**: 2026-09-30
- **Jenis**: dokumen riset + rekomendasi (tidak ada perubahan kode)
- **Repo terkait**: `aulia-app` (v2.4) dan `WA-Gateway` (`C:\Projects\WA-Gateway`, master)
- **Status**: riset selesai; keputusan menunggu jawaban user (lihat §8)
- **Prioritas keputusan**: jaminan delivery > kesederhanaan operasional > minim perubahan AuliaPos

> **Catatan verifikasi.** Dokumen resmi Meta (`developers.facebook.com`) **tidak bisa
> diakses** dari lingkungan sesi ini (selalu mengembalikan HTTP 400 / bot-protection),
> sehingga fakta kebijakan & harga di bawah dikutip dari **dokumentasi resmi BSP**
> yang mengimplementasikan dan meneruskan kebijakan Meta (360dialog, Twilio, Gupshup,
> Wati). Item yang tidak bisa dikonfirmasi ditandai eksplisit **`[BELUM DIVERIFIKASI]`**.
> Tarif Meta per negara **tidak boleh dikarang** — harus diambil dari rate card resmi
> Meta saat implementasi.

---

## 1. Ringkasan Eksekutif

1. Requirement keras "PC mati semalaman → pesan tetap masuk pagi" **tidak bisa dijamin
   oleh Baileys** (linked device + klien PC) — arsitekturnya client-side; hanya
   server-side/webhook yang bisa.
2. Semua kandidat resmi (**WhatsApp Cloud API** langsung maupun **BSP**: 360dialog,
   Twilio, Gupshup, Wati, Infobip/Vonage) memakai arsitektur server-side Meta dan
   mengirim pesan masuk ke kita lewat **webhook yang di-retry** → memenuhi syarat #1.
3. Kunci "penyesuaian AuliaPos minimal": bangun **Cloud API Bridge** — proses kecil
   yang **mempertahankan kontrak `WA-Gateway` yang ada** (`/send`, `/send-media`,
   `/media/download`, payload masuk, heartbeat) tetapi backend-nya Cloud API, bukan
   Baileys. Sisi CI4 nyaris tidak berubah; cukup ganti upstream.
4. **Nomor toko tidak harus ganti.** Ada **Coexistence**: nomor yang sama jalan di
   WhatsApp Business app **dan** Cloud API sekaligus (app tetap hidup, dengan syarat
   dibuka ≥1× tiap 13 hari). Alternatifnya migrasi penuh → app mati di nomor itu.
5. Konsekuensi baru yang wajib diterima: **jendela layanan 24 jam** + **template** untuk
   pesan di luar jendela, **opt-in** pelanggan, **verifikasi bisnis**, dan **biaya per
   pesan**. Per **1 Okt 2026** Meta mulai menagih **semua service message** (balasan
   bebas-form dalam jendela 24 jam tidak lagi gratis) — ini mengubah ekonomi secara
   signifikan dan harus masuk keputusan.
6. Rekomendasi: **Utama = BSP Gupshup** (paling murah + Coexistence self-serve), lewat
   **Cloud API Bridge**; **Cadangan = 360dialog** (paling terbukti, tapi berlangganan).
   Lihat revisi berbasis jawaban user di §10.
7. Estimasi upaya dev bridge: **±5–10 hari kerja** (belum termasuk lead time onboarding
   Meta/BSP yang biasanya berminggu-minggu dan bukan kerja developer).
8. Efek samping positif: **masalah `@lid` dan desync sesi hilang** karena Cloud API
   mengidentifikasi pelanggan dengan `wa_id` (nomor telepon), bukan sesi Signal per-device.

---

## 2. Baseline: apa yang dipakai sekarang (Baileys)

**Kontrak yang berjalan (terverifikasi dari README/AGENTS WA-Gateway + memory proyek):**

| Arah | Endpoint | Isi |
|---|---|---|
| Gateway → CI4 | `POST /api/inbox/gateway/messages` | pesan masuk (teks/media/sticker), Bearer token |
| Gateway → CI4 | `POST /api/inbox/gateway/status` | heartbeat: `status`, `phone`, `gateway_version`, `session_health` |
| CI4 → Gateway | `POST /send` | teks, opsi `quoted` / `forward` |
| CI4 → Gateway | `POST /send-media` | media base64 (image/document/sticker) |
| CI4 → Gateway | `POST /media/download` | ambil+dekripsi media masuk (binary) |

**Fitur terpakai**: terima/kirim teks, gambar, dokumen, sticker; balas (quote);
teruskan (forward); status + heartbeat; sesi via scan QR. Gateway punya buffer
SQLite durable + retry/dead-letter + idempotensi `operation_id` (invariant AGENTS
WA-Gateway §1.3).

**Masalah yang memicu riset (semua terverifikasi di `docs/sesi/`):**
- Baileys = klien **unofficial** → risiko ban, tanpa jaminan protokol.
- Sering `SessionError` pada trafik `@lid` (keterbatasan `libsignal-node`).
- Insiden 2026-09-29/30: sesi `connected` tapi gagal dekripsi semua pesan → pesan
  hilang ±12 jam tanpa peringatan.
- Reconciliation offline = batch tunggal 100 pesan tanpa loop (`socket.js`) →
  best-effort, **belum pernah diuji nyata**, dan retensi pesan di server WhatsApp
  tidak terdokumentasi.

---

## 3. Kandidat yang dievaluasi

**A. WhatsApp Cloud API resmi (Meta Graph API) langsung** — server-side penuh, webhook.
**B. Coexistence** — varian resmi: nomor toko tetap di app **dan** masuk Cloud API.
**C. BSP 360dialog** — BSP resmi; punya hosted Inbox + dukungan Coexistence; API
kompatibel Cloud API.
**D. BSP Twilio** — CPaaS; fee handling per pesan; dokumentasi kuat.
**E. BSP Gupshup** — self-serve; fee per pesan sangat rendah.
**F. BSP Wati** — SaaS shared-inbox (produk inbox sendiri).
**G. BSP Infobip / Vonage** — CPaaS enterprise (referensi pembanding).
**H. Non-WhatsApp** — SMS gateway lokal / Telegram Bot API (jaminan delivery berbeda,
tapi mengubah perilaku pelanggan — dibahas singkat).
**I. Library unofficial alternatif** (whatsapp-web.js, WPPConnect, open-wa,
Evolution API-mode-Baileys) — **sekelas Baileys**, dibahas di §18 (bukan solusi
requirement #1, ditambahkan atas permintaan user untuk perbandingan).
**Baseline.** Baileys (status quo) — pembanding.

---

## 4. Tabel perbandingan vs checklist

Legenda: **✔** terpenuhi · **±** sebagian/bersyarat · **✖** tidak · **?** belum
diverifikasi.

### 4.1 Delivery & operasional

| Kandidat | Jaminan delivery saat PC mati | Jaminan tidak hilang (retry/receipt/dead-letter) | Windows / hosting | Upaya & risiko |
|---|---|---|---|---|
| **A. Cloud API langsung (+Coexistence)** | ✔ pesan disimpan & didorong Meta; webhook di-retry (≈24 jam, `?`) | ✔ status `sent`/`delivered`/`read`/`failed` + retry webhook | Bridge bisa jalan di Windows/Linux; **wajib endpoint webhook HTTPS publik** (tunnel/VPS) | ±5–10 hari; risiko: onboarding Meta, operasikan relay 24/7 |
| **B. Coexistence (bagian dari A/C)** | ✔ sama dengan A | ✔ sama dengan A | sama | syarat: buka app ≥1×/13 hari; app tak boleh di-uninstall; tanpa centang biru |
| **C. 360dialog** | ✔ + infra BSP selalu hidup (hosted Inbox opsional) | ✔ sama (Meta) | bridge + webhook tetap perlu; BSP bantu onboarding | ±4–8 hari; biaya langganan bulanan |
| **D. Twilio** | ✔ | ✔ status + error codes | bridge + webhook; SDK bagus | ±4–8 hari; fee per pesan |
| **E. Gupshup** | ✔ | ✔ | bridge + webhook | ±4–8 hari; dukungan Indonesia `?` |
| **F. Wati** | ✔ (server-side) | ✔ | **produk inbox sendiri** → Inbox AuliaPos jadi ganda | ±10–15 hari; bukan sekadar backend, tapi ganti UI |
| **G. Infobip/Vonage** | ✔ | ✔ | bridge + webhook | B2B; lead time & minimum komersial `?` |
| **Baseline. Baileys** | ✖ (best-effort, terbukti bocor) | ± (buffer lokal saja) | Windows native, tanpa webhook | status quo; tidak memenuhi requirement #1 |

### 4.2 Fitur, batasan resmi, nomor, biaya, kontrak

| Kandidat | Fitur teks/media in-out · quote · forward · status | Batasan resmi (24 jam/template/opt-in/verifikasi) | Nasib nomor toko | Biaya volume kecil | Kesesuaian kontrak CI4 |
|---|---|---|---|---|---|
| **A. Cloud API langsung** | ✔ teks/gambar/dokumen/sticker (in & out) · quote via `context.message_id` ✔ · **forward native ✖** (tak ada flag resmi; pakai prefix teks) · health via quality rating | ✔ 24 jam; template di luar jendela; opt-in; verifikasi bisnis utk scale | tetap pakai nomor sama; **Coexistence** = app tetap hidup | **hanya biaya pesan Meta** `[? ada/tidak platform fee]` | via bridge → kontrak lama dipertahankan |
| **B. Coexistence** | idem A + message echo 2 arah (`smb_message_echoes`) | idem A; app tetap kena aturan app | nomor sama, app + API | biaya app = gratis; biaya API = tarif API | bridge |
| **C. 360dialog** | ✔ teks/media/sticker · quote via `context` ✔ · forward ✖ · hosted Inbox | idem A | didukung penuh (Coexistence) | **langganan 49 EUR/59 USD per bulan per channel** + tarif Meta | bridge |
| **D. Twilio** | ✔ · quote ✔ · forward ✖ | idem A | didukung | **USD 0,005/pesan (in/out)** + tarif Meta | bridge |
| **E. Gupshup** | ✔ · quote ✔ · forward ✖ | idem A | didukung `?` | **USD 0,001/pesan** + tarif Meta | bridge |
| **F. Wati** | ✔ + inbox/campaign/AI (produk sendiri) | idem A | didukung | paket bulanan (Growth/Pro/Business) + tarif WATI + tarif Meta | ✖ inbox terpisah → AuliaPos harus poll/duplikasi |
| **G. Infobip/Vonage** | ✔ + fitur CPaaS luas | idem A | didukung | komersial B2B `?` | bridge |
| **Baseline. Baileys** | ✔ semua (unofficial) | ✖ (tidak ada aturan resmi) | linked device di app | gratis (hosting sendiri) | native (kontrak asal) |

**Catatan penting biaya (terverifikasi dari dokumen BSP, 2026):**
- Model Meta kini **per pesan** (bukan lagi per conversation 24 jam) sejak Juli 2025.
- **1 Okt 2026**: Meta mulai menagih **semua service message** — balasan bebas-form
  dalam jendela 24 jam dan utility template di dalam jendela **tidak lagi gratis**.
  Ini relevan langsung karena tanggal sesi ini 30 Sep 2026 (perubahan tinggal 1 hari).
- Tarif Meta aktual per negara (Indonesia) **WAJIB** diambil dari rate card resmi Meta
  — tidak ada di dokumen yang berhasil diakses sesi ini.
- Volume tier: 0–100.000 pesan tertagih = Tier 1; di atasnya diskon bertahap (agregat
  antar-WABA).

---

## 5. Rekomendasi

### 5.1 Utama — Meta WhatsApp Cloud API resmi + Coexistence, diakses lewat "Cloud API Bridge"

**Apa:** ganti backend Baileys di WA-Gateway dengan **Cloud API Bridge** — proses yang
menerima webhook Meta dan memanggil Graph API, tetapi **tetap menyajikan kontrak HTTP
yang sama** ke AuliaPos (`/send`, `/send-media`, `/media/download`, payload pesan masuk,
heartbeat `/api/inbox/gateway/status`). Skeleton WA-Gateway yang sudah terbukti
(buffer SQLite durable, retry, dead-letter, idempotensi `operation_id`, heartbeat)
**dipakai ulang**; yang diganti hanya lapisan `src/whatsapp/*` (Baileys → Cloud API).

**Alasan memilih:**
1. **Memenuhi requirement keras #1** — Meta menyimpan dan mengirim pesan via webhook
   yang di-retry; tidak bergantung pada sesi HP/PC.
2. **Menghilangkan akar masalah** — tidak ada lagi ban-risk unofficial, tidak ada
   `SessionError @lid`, tidak ada desync sesi (Cloud API memakai `wa_id` = nomor).
3. **Minim perubahan AuliaPos** — endpoint CI4 & UI Inbox dipertahankan; perubahan
   terbesar hanya menambah indikator jendela 24 jam + status pesan.
4. **Nomor toko aman** — Coexistence mempertahankan app WhatsApp Business di HP.
5. **Biaya variabel paling murah** — hanya tarif pesan Meta (tanpa markup BSP).

**Trade-off jujur:**
- Wajib **endpoint webhook HTTPS publik**. Untuk "PC mati semalaman" tetap terjamin,
  penerima webhook sebaiknya **relay kecil yang selalu hidup** (VPS ±USD 5/bln atau
  Cloudflare Tunnel + Worker). Kalau relay menempel di PC toko yang mati, jaminannya
  turun ke "retry webhook Meta" (≈24 jam, `?`).
- Ada **biaya per pesan** dan beban **operasional kepatuhan** baru (template, opt-in,
  verifikasi bisnis).
- **Forward tanpa penanda native** — pakai prefix teks (sama seperti fallback lama).
- Onboarding Meta Business (verifikasi, display name, template approval) bukan kerja
  teknis tapi **lead time**.

### 5.2 Cadangan — BSP 360dialog (Regular tier), bridge yang sama

**Apa:** pakai 360dialog sebagai jalur ke Cloud API (onboarding dibantu, infra di-host,
dukungan Coexistence, Messaging API kompatibel Cloud API), tetap lewat Cloud API Bridge.

**Alasan:** bila toko tidak mau/tidak sanggup mengurus Meta Business + hosting webhook
sendiri, 360dialog memangkas risiko onboarding dan menyediakan infra 24/7. Trade-off:
**langganan 49 EUR / 59 USD per bulan per channel** + tarif Meta.

**Pembanding biaya BSP:** Twilio **tanpa langganan bulanan** + USD 0,005/pesan, cocok
untuk volume kecil (menang biaya bila < ±11.800 pesan/bulan); Gupshup USD 0,001/pesan
(paling murah, dukungan Indonesia `?`). Pertimbangkan Twilio sebagai cadangan alternatif
bila biaya bulanan 360dialog dirasa berat.

**Tidak direkomendasikan untuk kasus ini:** Wati (menggantikan/menduplikasi Inbox
AuliaPos — melanggar "minim perubahan AuliaPos") dan Infobip/Vonage (skala enterprise).

---

## 6. Peta migrasi kasar

Prinsip: **pertahankan kontrak CI4 ↔ Gateway**, ganti hanya backend WhatsApp. Ini
menjaga agar `InboxGatewayApi`, model Inbox, dan UI Inbox tetap jalan.

### 6.1 Endpoint CI4

| Endpoint / kontrak | Aksi | Keterangan |
|---|---|---|
| `POST /api/inbox/gateway/messages` (Gateway → CI4) | **Dipertahankan** | Bridge memetakan payload Cloud API (`messages[]`) → bentuk lama. Field `sender_jid`/`chat_id` diisi `wa_id` (nomor) — `@lid` tidak ada lagi. |
| `POST /api/inbox/gateway/status` (heartbeat) | **Dipertahankan (makna bergeser)** | Tidak ada lagi QR/sesi. `status=connected` & `session_health=ok` selama bridge + token sehat; `degraded` → webhook mati / token dicabut / quality rating turun. |
| `POST /send` (CI4 → Bridge) | **Dipertahankan** | Bridge menerjemah ke Graph API. `quoted` → `context.message_id`; `forward` → fallback prefix teks. |
| `POST /send-media` (CI4 → Bridge) | **Dipertahankan** | Media base64 → upload media Meta → kirim. |
| `POST /media/download` (CI4 → Bridge) | **Dipertahankan (perlu adaptasi referensi)** | Bridge menyimpan **media id** Cloud API; download via Graph API on-demand. Bentuk `media_ref` lama perlu dipetakan. |
| (baru) `POST /webhook` di sisi bridge | **Ditambah** | Verifikasi `GET` + penerima event Meta; dedupe `wamid`; wajib HTTPS publik. |

### 6.2 Skema / database

- `gateway_status.session_health` (kolom lama) **tetap dipakai** dengan makna baru.
- **Aditif yang mungkin perlu**: kolom `wa_id`/`wamid` pada pesan, `window_expires_at`
  per conversation (untuk indikator 24 jam), dan `template_name`/`template_status`.
- `conversation_identity` untuk `@lid` menjadi **tidak perlu** (identitas = nomor).
- Migrasi bersifat aditif/kompatibel mundur; **belum ada perubahan skema final**.

### 6.3 UI Inbox

- Badge/flow **"scan QR"** dan **"degraded, perlu scan ulang"** dihapus/diganti
  (tidak ada sesi untuk di-scan).
- **Ditambah**: indikator **jendela 24 jam** (bisa balas bebas-form / harus template),
  pemilih **template**, dan status pesan (`sent`/`delivered`/`read`). Layar & alur
  utama Inbox tetap sama.

### 6.4 Sisi WA-Gateway

- Reuse: `src/store/*` (SQLite durable), `src/delivery/*` (retry/dead-letter/
  idempotensi), `src/delivery/heartbeat.js`.
- Ganti: `src/whatsapp/connectionManager.js` (Baileys) → klien Graph API + normalizer
  webhook; hapus ketergantungan `baileys` & `auth/` (tidak ada QR).

---

## 7. Risiko & hal yang belum terverifikasi

**Belum terverifikasi (jangan dianggap fakta):**
1. **Dokumen resmi Meta tidak bisa diakses** dari sesi ini (HTTP 400). Semua kebijakan
   di atas dikutip dari BSP resmi yang meneruskan kebijakan Meta; **harus dikonfirmasi
   ulang** di `developers.facebook.com` sebelum implementasi.
2. **Tarif Meta per pesan untuk Indonesia** — belum ada angkanya. Wajib dari rate card
   resmi Meta.
3. **Durasi retry webhook Meta** — dokumen BSP menyebut "≈24 jam (dapat bervariasi)"
   dan di tempat lain "hingga 7 hari". Belum dipastikan angka resminya. Ini krusial
   untuk menentukan berapa lama PC boleh mati.
4. **Ada/tidak biaya langganan platform Cloud API langsung** — belum terkonfirmasi.
5. **Forward native** di Cloud API — belum ditemukan flag resminya (asumsi: tidak ada).
6. **Sticker outbound** di Cloud API (dimensi/format WebP) — detail belum diverifikasi.
7. **Masa berlaku URL media** Cloud API (retensi unduhan) — belum diverifikasi.
8. **Eligibilitas & status Coexistence** untuk nomor toko spesifik belum diperiksa.
9. **Dukungan Indonesia** beberapa BSP (Gupshup) dan detail komersial B2B
   (Infobip/Vonage) belum dipastikan.

**Risiko:**
- **Perubahan ekonomi**: sejak 1 Okt 2026 semua service message ditagih — volume
  balasan kasir jadi biaya berulang.
- **Kepatuhan**: pesan keluar di luar jendela 24 jam **hanya boleh template** yang
  disetujui; template bisa ditolak & butuh waktu approval. Opt-in pelanggan diperlukan.
- **Verifikasi bisnis**: butuh **website aktif + SSL** dengan nama/alamat/telepon sama,
  email domain bisnis, dan dokumen legal. Tanpa itu, limit **250 user unik/24 jam**.
- **Coexistence**: app wajib dibuka ≥1×/13 hari; **jangan uninstall**; companion
  Windows/WearOS tidak didukung; tanpa centang biru (OBA).
- **Ketergantungan pihak ketiga** (Meta/BSP) — perubahan kebijakan/harga di luar kendali.
- **Cross-repository contract** berubah (AGENTS §9) → **wajib persetujuan user eksplisit**
  sebelum implementasi.
- **Greenfield**: ini penulisan ulang lapisan WhatsApp; buffer/retry yang ada mengurangi
  risiko, tetapi kode baru tetap perlu test.

---

## 8. Pertanyaan yang HARUS dijawab user sebelum implementasi

1. **Nasib nomor toko**: pilih **Coexistence** (app WhatsApp Business tetap hidup di
   HP, syarat dibuka ≥1×/13 hari, tanpa centang biru) **atau** migrasi penuh (app mati
   di nomor itu)? Mana yang diprioritaskan?
2. **Kesiapan berkas bisnis**: apakah toko punya **website aktif + SSL**, **email domain
   bisnis**, dan **dokumen legal** untuk verifikasi bisnis? Kalau belum, bersediakah
   beroperasi di limit **250 user unik/24 jam**?
3. **Anggaran**: bersediakah membayar **biaya per pesan** (dan mungkin **langganan
   bulanan BSP**)? Berapa perkiraan **volume pesan WA/bulan**? Mana yang lebih penting:
   tanpa langganan bulanan (Cloud API/Twilio/Gupshup) atau onboarding dibantu (360dialog)?
4. **Relay webhook 24/7**: boleh menyediakan **VPS kecil (≈USD 5/bln)** atau
   **Cloudflare Tunnel** agar pesan tetap diterima saat PC toko mati? Kalau tidak, apakah
   dapat menerima risiko "hanya seandainya downtime < ±24 jam"?
5. **Template & jendela 24 jam**: apakah kasir perlu mengirim pesan **di luar 24 jam**
   sejak pesan terakhir pelanggan (butuh template)? **Siapa** yang akan membuat &
   mengelola template (konten Bahasa Indonesia + approval Meta)?
6. **Sticker & forward**: apakah **kirim sticker** wajib? Untuk fitur **Teruskan**,
   apakah dapat menerima **prefix teks "↻ Diteruskan:"** (tanpa penanda native)?
7. **Kepemilikan aset**: **siapa** yang memegang akun **Meta Business Manager** /
   akun BSP — toko atau vendor? Ini menentukan siapa pemilik nomor, WABA, dan riwayat.

---

## 9. Sumber

**Berhasil diakses sesi ini (dokumentasi resmi BSP):**
- 360dialog — Coexistence: `https://docs.360dialog.com/docs/resources/phone-numbers/coexistence.md`
- 360dialog — Free vs Billed Messaging: `https://docs.360dialog.com/docs/get-started/pricing/free-vs-billed-messaging.md`
- 360dialog — Webhook Reference: `https://docs.360dialog.com/docs/messaging/webhook/webhook-reference.md`
- 360dialog — Pricing: `https://docs.360dialog.com/docs/get-started/pricing.md`
- 360dialog — Upgrade from the WhatsApp Business app: `https://docs.360dialog.com/docs/inbox/hosted-inbox/playbooks/upgrade-from-the-whatsapp-business-app.md`
- Twilio — WhatsApp Pricing: `https://www.twilio.com/en-us/whatsapp/pricing`
- Gupshup — Self-serve Pricing: `https://www.gupshup.io/pricing`
- Wati — Pricing: `https://www.wati.io/pricing/`

**Tidak bisa diakses dari lingkungan sesi ini (HTTP 400 / bot-protection) — wajib
dikonfirmasi ulang:**
- `https://developers.facebook.com/docs/whatsapp/pricing` (rate card & volume tier)
- `https://developers.facebook.com/docs/whatsapp/cloud-api/*` (Cloud API)
- `https://business.whatsapp.com/products/platform-pricing`

Sumber tambahan yang berhasil diakses di sesi lanjutan:
- Gupshup — Coexistence: `https://docs.gupshup.io/docs/coex.md`
- Gupshup — Pricing updates (PMP per 1 Juli 2025): `https://docs.gupshup.io/docs/pricing-updates-on-the-whatsapp-business-platform.md`
- Twilio — WhatsApp API overview: `https://www.twilio.com/docs/whatsapp/api`
- 360dialog — Coexistence onboarding (Embedded Signup): `https://docs.360dialog.com/docs/hub/embedded-signup/coexistence-onboarding.md`

---

## 10. Keputusan user (2026-09-30) & revisi rekomendasi

### 10.1 Jawaban user

| # | Pertanyaan | Jawaban user |
|---|---|---|
| 1 | Nasib nomor toko | **App tetap hidup** → Coexistence |
| 2 | Verifikasi bisnis | **Tidak ada verifikasi** (minta penjelasan limit 250) |
| 3 | Anggaran | **Cari yang semurah mungkin** |
| 4 | Relay webhook 24/7 | **Boleh** |
| 5 | Perlu kirim di luar 24 jam? | **Belum paham** (butuh penjelasan) |
| 6 | Sticker & forward | **Fleksibel** |
| 7 | Pemilik akun Meta/BSP | **Toko** |

### 10.2 Penjelasan "250 user unik / 24 jam"

- Ini **batas pengiriman (messaging limit)**, bukan batas menerima.
- Yang dihitung: **jumlah pelanggan berbeda yang kita kirimi pesan inisiatif
  (business-initiated) di luar jendela layanan**, per 24 jam bergulir.
- **Balasan di dalam jendela 24 jam TIDAK dihitung.** Jadi untuk toko yang
  praktiknya hanya **membalas** pelanggan yang chat lebih dulu, limit 250 **tidak
  relevan** — balasan ke pelanggan tetap tak terbatas.
- Tanpa verifikasi: **250**. Setelah verifikasi: 2.000 → 10.000 → 100.000 →
  unlimited (dengan syarat volume/kualitas).
- Implikasi: **tanpa verifikasi tetap aman** untuk pola "kasir membalas pelanggan".
  Verifikasi baru perlu bila toko mulai mengirim pengumuman/promo/follow-up massal.

### 10.3 Penjelasan jendela 24 jam & template

- Ketika pelanggan mengirim WA ke toko, terbuka **jendela layanan 24 jam**
  (timer **reset** tiap pesan baru pelanggan).
- **Di dalam jendela**: kasir bebas kirim teks/gambar/dokumen/sticker seperti biasa.
- **Di luar jendela** (sudah >24 jam sejak pesan terakhir pelanggan): toko **hanya
  boleh mengirim template** — pesan yang sudah dibuat & **disetujui Meta** sebelumnya
  (mis. "Halo, ada yang bisa kami bantu?"). Tidak bisa ketik bebas.
- Template bisa **ditolak** dan butuh waktu approval.
- Implikasi untuk POS: hampir semua aktivitas kasir = **membalas** → selalu di dalam
  jendela → **template praktis tidak dibutuhkan**. Template hanya diperlukan jika
  toko ingin menyapa ulang pelanggan yang sudah lama tidak chat.

### 10.4 Temuan kunci yang mengubah rekomendasi

1. **Coexistence tidak tersedia lewat Cloud API self-serve murni.** Onboarding
   Coexistence memakai **Embedded Signup / Coexistence Onboarding** yang disediakan
   **BSP/Tech Provider** (dikonfirmasi dokumentasi 360dialog, Gupshup, dan Twilio).
   → Karena user memilih **app tetap hidup**, **jalur praktis = lewat BSP**, bukan
   Cloud API langsung.
2. **Gupshup mendukung Coexistence secara self-serve**, fee **USD 0,001/pesan**,
   **tanpa langganan bulanan** yang disebutkan → **paling murah** di antara BSP yang
   terverifikasi mendukung Coexistence.
   Catatan risiko dari Gupshup sendiri: kriteria kelayakan nomor belum dibuka Meta,
   ada error onboarding yang masih diselidiki, dan event Coexistence
   (`history`, contact sync, `smb_message_echoes`) **sempat bermasalah di sisi Meta**.
   Syarat: WA Business App ≥ 2.24.17, akun app ≥ 3 bulan aktif, **tidak ada metode
   pembayaran tertaut di app**, Indonesia termasuk negara didukung `[perlu konfirmasi]`.
3. **Twilio** fee handling **USD 0,005/pesan**, tanpa langganan bulanan; dukungan
   Coexistence **belum terverifikasi**.
4. **360dialog** langganan **49 EUR / 59 USD per bulan** per channel; **paling jelas**
   mendukung Coexistence + Infra/Hosted Inbox, tapi bukan yang termurah.
5. Biaya Meta per pesan **sama** di semua BSP (pass-through). **Karena 1 Okt 2026
   semua service message ditagih**, komponen dominan jangka panjang adalah **tarif
   Meta per pesan**, bukan markup BSP. Jadi strategi "termurah" yang benar:
   pilih BSP **tanpa langganan bulanan** (Gupshup/Twilio), bukan mengejar BSP mahal.

### 10.5 Rekomendasi revisi

- **Utama (hemat, sesuai jawaban user): Gupshup self-serve + Coexistence**, diakses
  lewat **Cloud API Bridge** yang mempertahankan kontrak WA-Gateway → AuliaPos minim
  perubahan. Biaya: **USD 0,001/pesan** (markup Gupshup) + **tarif Meta per pesan**.
  Trade-off: Coexistence di Gupshup masih punya isu Meta & kelayakan nomor belum pasti.
- **Cadangan (paling aman): 360dialog Regular** (USD 59/bln) — paling terbukti,
  menyediakan Infra/Hosted Inbox, tetapi ada biaya langganan tetap.
- **Alternatif setara:** Twilio (USD 0,005/pesan, tanpa langganan) **jika** Coexistence
  didukung (perlu dikonfirmasi ke Twilio).
- **Relay webhook**: user membolehkan → sediakan **relay kecil selalu hidup**
  (Cloudflare Worker/Queue gratis, atau VPS ≈ USD 5/bln) sebagai penampung webhook
  24/7, agar jaminan "PC mati semalaman → pesan masuk pagi" tidak bergantung pada
  PC toko maupun durasi retry Meta.
- **Kepemilikan**: akun Meta Business Manager & akun BSP **atas nama toko** (bukan
  vendor) — pastikan onboarding Embedded Signup memakai business portfolio toko.

### 10.6 Sisa yang perlu dikonfirmasi sebelum implementasi

- Tarif Meta per pesan **Indonesia** (rate card resmi) — belum ada.
- Apakah **Indonesia** didukung Coexistence di Gupshup; kelayakan nomor toko spesifik.
- Dukungan **Coexistence di Twilio**.
- Durasi pasti **retry webhook Meta** (≈24 jam vs 7 hari).
- Ada/tidak **biaya langganan platform** pada Cloud API langsung.

### 10.7 Estimasi biaya dalam Rupiah

**Kurs acuan (30 Sep 2026):** USD 1 ≈ **Rp 17.950**, EUR 1 ≈ **Rp 20.300**
(sumber: exchangerate-api.com & frankfurter/ECB, 2026-09-30). Kurs bisa berubah;
angka di bawah indikatif, bukan penawaran resmi.

**A. Biaya BSP (markup di luar tarif Meta):**

| Kandidat | Model | Per pesan (Rupiah) | Langganan (Rupiah/bulan) |
|---|---|---|---|
| **Gupshup** | USD 0,001/pesan, tanpa langganan | **≈ Rp 18/pesan** | **Rp 0** |
| **Twilio** | USD 0,005/pesan, tanpa langganan | **≈ Rp 90/pesan** | **Rp 0** |
| **360dialog Regular** | langganan + pass-through | Rp 0 markup | **49 EUR ≈ Rp 995 rb** (59 USD ≈ Rp 1,06 jt) |
| **360dialog Premium** | langganan + pass-through | Rp 0 markup | 99 EUR ≈ **Rp 2,01 jt** (119 USD ≈ Rp 2,14 jt) |
| **360dialog High Throughput** | langganan + pass-through | Rp 0 markup | 249 EUR ≈ **Rp 5,05 jt** (299 USD ≈ Rp 5,37 jt) |
| **Wati** | plan bulanan / pay-as-you-go | sesuai plan | (₹999 ≈ Rp 187 rb sekali bayar untuk paket awal) `[detail per plan perlu konfirmasi]` |

**B. Biaya tambahan milik kita:**

| Item | Estimasi (Rupiah) |
|---|---|
| Relay webhook VPS kecil | ≈ USD 5/bln ≈ **Rp 90 rb/bulan** |
| Cloudflare Worker/Queue (free tier) | **Rp 0** |
| Bridge (proses sendiri di PC/VPS) | Rp 0 (software sendiri) |

**C. Tarif Meta per pesan Indonesia** — **BELUM DIKETAHUI**; wajib dari rate card
resmi Meta. Ini berlaku **sama** untuk semua BSP (pass-through) dan **mulai
1 Okt 2026 dikenakan untuk SEMUA service message** (balasan bebas-form dalam
24 jam ikut ditagih). Jadi total biaya nyata = **(tarif Meta × jumlah pesan) +
markup BSP + langganan**.

**D. Titik impas (langganan vs tanpa langganan), belum termasuk tarif Meta:**

- Twilio vs 360dialog Regular: Rp 1,06 jt ÷ Rp 90/pesan ≈ **11.800 pesan/bulan**.
  Di bawah itu, **Twilio lebih murah**; di atas itu 360dialog lebih murah.
- Gupshup vs 360dialog Regular: Rp 1,06 jt ÷ Rp 18/pesan ≈ **59.000 pesan/bulan**.
  Artinya untuk toko kecil, **Gupshup/Twilio (tanpa langganan) hampir pasti lebih
  murah** daripada 360dialog.

**Kesimpulan biaya:** untuk volume toko kecil, pilihan **paling murah = Gupshup
(≈ Rp 18/pesan, tanpa langganan) + relay gratis (Cloudflare)**. Biaya dominan
sesungguhnya adalah **tarif Meta per pesan** yang belum diketahui dan baru bisa
dihitung setelah rate card Indonesia didapat.

---

## 11. Catatan khusus: Kirimi.id (pertanyaan user)

Sumber: `https://kirimi.id/`, `https://kirimi.id/docs`, blog resmi Kirimi
(diakses 2026-09-30).

### 11.1 Kirimi punya DUA jalur kirim yang terpisah

Terverifikasi dari dokumentasi API resmi mereka (`/docs`):

| Jalur | Endpoint | Penanda | Aturan |
|---|---|---|---|
| **Unofficial (perangkat QR scan)** | `/v1/send-message`, `/v1/broadcast-message` | butuh `device_id` | teks bebas kapan saja; bisa kirim ke grup; ada `delay` anti-ban minimum 30 detik |
| **WhatsApp Business API resmi (WABA)** | `/v1/waba/*` | butuh `waba_id` | **template wajib** untuk pesan yang dimulai bisnis; **balas bebas hanya dalam 24 jam**; tanpa grup |

Auth API: `user_code` + `secret` (+ `device_id` atau `waba_id`). Media dikirim
via **`media_url`** (bukan base64). Webhook tersedia untuk status pengiriman &
balasan. Base URL: `https://api.kirimi.id`.

### 11.2 Harga paket Kirimi (terverifikasi dari blog + docs resmi Kirimi, 2026)

| Paket | Harga | Kuota | Catatan |
|---|---|---|---|
| Gratis | Rp 0 | 1.000 pesan/bulan | **tanpa media** |
| **Lite** | **Rp 29.000/bln** | — | **media via `media_url` aktif** |
| **Basic** | **Rp 49.000/bln** | — | media aktif + Team Inbox |
| **Pro** | **Rp 99.000/bln** | (unlimited pesan) | multi-admin + 1000 AI response/bln (kelebihan Rp25/request) |
| Enterprise | custom | — | — |

Detail teknis (docs resmi `kirimi.id/docs`):
- **Media didukung di paket Lite/Basic/Pro** via parameter `media_url` (URL publik).
  Format: JPEG/PNG/GIF/WebP, MP4/AVI/MOV, MP3/WAV/OGG, PDF/DOC/DOCX/XLS/XLSX/PPT/PPTX.
  **Batas ukuran 64 MB.**
- **Balas/quote** via `quotedMessageId` (langsung di payload `/v1/send-message`).
- **Auth**: `user_code` + `secret` + `device_id` (jalur QR) atau `waba_id` (jalur WABA).
- **Rate limit**: Send Message 300/10 menit; Broadcast 5/menit; maks 1000 nomor/broadcast
  (500 paket dasar); jeda broadcast **min 30 detik** (anti-ban).
- **Webhook** untuk status pengiriman + balasan (diatur di dashboard).
- Punya **2 jalur**: `/v1/send-message` & `/v1/broadcast-message` (QR/unofficial) dan
  `/v1/waba/*` (resmi) — endpoint tidak saling menggantikan.

### 11.3 Penilaian untuk kasus AuliaPos

- **Paket murah Kirimi = unofficial / linked device (QR)** → **kelas yang SAMA
  dengan Baileys**. Karena itu **TIDAK memenuhi requirement #1** (jaminan delivery
  server-side resmi). Risiko ban & kerapuhan protokol tetap ada; tidak ada jaminan
  webhook-retry resmi dari Meta.
  - Nuansa: sesi di-host di server Kirimi (bukan PC toko), jadi untuk kasus
    "PC mati semalam" ia lebih baik daripada Baileys lokal. Tetapi arsitekturnya
    tetap **linked device**, bukan server-side resmi → tidak memenuhi syarat keras.
- **Jalur resmi WABA Kirimi** (`/v1/waba/*`) = **relevan dan bisa memenuhi syarat**,
  termasuk klaim mendukung **Coexistence**. TAPI **harga WABA tidak dipublikasikan**
  (lewat konsultasi/penawaran) → **tidak bisa diklaim "termurah"** dan tidak bisa
  dibandingkan head-to-head dengan angka.
- Kirimi **mengklaim** status "Meta Tech Provider" (logo di footer) dan dukungan
  Coexistence — **belum diverifikasi** ke direktori Meta.
- **Kontrak API berbeda** dari WA-Gateway (auth `user_code`/`secret`, media via
  `media_url` bukan base64, endpoint `/v1/waba/*`) → tetap perlu **bridge/adapter**,
  bukan drop-in.

### 11.4 Kesimpulan Kirimi

- **Jangan pakai paket murah Kirimi (Rp 29–99 rb)** untuk Inbox toko: itu unofficial
  dan mengulang masalah yang sedang dihindari.
- **Jalur resmi WABA Kirimi layak dimintai penawaran**, karena lokal + support
  Bahasa Indonesia; tetapi tanpa harga publik, ia **belum bisa disebut termurah**.
  Langkah: minta **penawaran resmi WABA + tarif per pesan + dukungan Coexistence +
  bukti status Meta Tech Provider**, lalu bandingkan dengan Gupshup/Twilio.
- Status sampai sekarang: **Gupshup tetap kandidat termurah yang terverifikasi**
  (USD 0,001/pesan, tanpa langganan, documented Coexistence); Kirimi WABA masuk
  daftar "perlu penawaran".

---

## 12. Catatan khusus: Fonnte.com (pertanyaan user)

Sumber: `https://fonnte.com/` dan `https://docs.fonnte.com/` (diakses 2026-09-30).

### 12.1 Fonnte = UNOFFICIAL (device/QR), bukan Cloud API resmi

- Judul situs Fonnte sendiri: **"Unofficial Whatsapp API Gateway Indonesia"**.
- Cara koneksi (docs resmi `how-to-connect`): **scan QR sebagai linked device**
  ("open whatsapp app on your phone -> go to linked device -> scan") — ini
  **persis mekanisme Baileys**.
- Endpoint di dokumentasi API: `API Get QR`, `API Add Device`, `API Device Profile`,
  `API Rotator`, `Webhook Device Status` → arsitektur **per-device berbasis QR**.
- Paket dihitung **per device** (tidak bisa dipakai bersama), berbasis kuota
  (mis. paket free: 900 pesan / 20 hari; regular: 10.000 pesan / 30 hari).

### 12.2 Penilaian

- **Tidak memenuhi requirement #1** (jaminan delivery server-side resmi). Sama
  kelasnya dengan Baileys: linked device, risiko ban, tanpa jaminan protokol
  maupun webhook-retry resmi Meta.
- Karena sesinya di-host di server Fonnte (bukan PC toko), ia lebih tahan terhadap
  kasus "PC mati semalam" daripada Baileys lokal — **tetapi tetap arsitektur
  linked device, jadi tetap tidak memenuhi syarat keras**.
- Harga Fonnte (**terverifikasi 2026-09-30 dari halaman harga resmi `fonnte.com`**,
  mode IDR/Bulanan): dua grup paralel —
  - **"Text Only"** (tanpa media sama sekali, dikonfirmasi FAQ resmi): Free Rp 0
    (1.000 pesan), Lite Rp 25.000 (1.000), Regular Rp 66.000 (10.000),
    Regular Pro Rp 110.000 (25.000), Master Rp 175.000 (unlimited).
  - **"All Feature"** (baru di sinilah **media aktif**): **Super Rp 165.000**
    (10.000 pesan), Advanced Rp 255.000 (25.000), Ultra Rp 355.000 (unlimited).
  - Jadi **kirim media pertama kali baru bisa di Super (Rp 165.000/bln)** — muncul
    juga di uji nyata §17 (paket gratis menolak media, bahkan gagal senyap
    dengan caption).

### 12.3 Kesimpulan

- **Fonnte tidak direkomendasikan** untuk Inbox toko karena unofficial.
- **Pola umum yang perlu diwaspadai:** Kirimi (paket murah), Fonnte, Wablas, dan
  sejenisnya adalah **gateway unofficial berbasis QR/device**. Harganya murah,
  tetapi tidak memenuhi jaminan delivery dan tetap membawa risiko ban — persis
  masalah yang ingin ditinggalkan.
- Yang memenuhi requirement #1 hanya **layanan dengan jalur WABA/Cloud API resmi**:
  Gupshup, 360dialog, Twilio, dan jalur `/v1/waba/*` milik Kirimi.

---

## 13. Pertimbangan: mengapa tidak pakai layanan unofficial yang sudah jadi?

**Poin user (valid):** kalau sama-sama unofficial, mengapa tidak pakai provider
hosted (Fonnte / Kirimi paket Lite) yang sudah jadi, daripada menyiapkan gateway
lokal sendiri?

**Jawaban jujur:** benar untuk *sebagian* hal, bukan untuk semuanya. Requirement #1
menyebut "linked device + PC lokal" tidak memenuhi — dan hosted unofficial memang
**bukan** PC lokal, sesinya di server provider. Jadi untuk *kasus PC mati*, hosted
unofficial **lebih baik** daripada Baileys lokal. Tetapi ia **tidak** menghilangkan
dua masalah yang jadi alasan migrasi: **risiko ban** dan **tidak ada jaminan
protokol**. Itu hanya pindah beban operasional, bukan pindah kelas risiko.

### 13.1 Perbandingan tiga jalur

| Aspek | A. Lokal Baileys | B. Hosted unofficial (Fonnte/Kirimi Lite) | C. Official (Cloud API) |
|---|---|---|---|
| PC mati semalam → pesan masuk pagi | ✖ best-effort, terbukti bocor | ± sesi di server provider → lebih baik; tetapi **retry/retensi webhook tidak dijamin** | ✔ webhook di-retry (resmi) |
| Risiko ban | tinggi | tinggi (bisa lebih tinggi: rotator/multi-device/broadcast) | rendah (resmi) |
| Jaminan protokol / SLA | tidak ada | tidak ada (tidak dipublikasikan) | ada (Meta) |
| Operasional | PC nyala + Node + QR | **paling ringan** (provider mengurus) | ringan-menengah (onboarding + webhook) |
| Kontrol & buffer sendiri | penuh (SQLite durable, retry, dead-letter) | **hilang** (pakai milik provider) | sebagian (bridge milik kita) |
| Patuh ToS WhatsApp | melanggar | melanggar | patuh |
| Biaya | gratis (hosting sendiri) | murah (≈ Rp 25–175 rb/bln) | per pesan (+ langganan utk 360dialog) |
| Kontrak CI4 | native | perlu adapter (API beda) | perlu bridge |

### 13.2 Yang hilang kalau pindah ke hosted unofficial

- **Buffer/retry/dead-letter milik sendiri hilang.** AuliaPos sudah punya jaminan
  "no message loss" di sisi Gateway; dengan provider, Anda bergantung pada
  reliabilitas mereka yang **tidak bisa diperiksa**.
- **Deteksi `degraded` milik sendiri hilang.** Insiden 2026-09-29/30 bisa terjadi
  juga di sesi provider (protokol multi-device yang sama, kelas Baileys) — dan Anda
  tak punya alat untuk mendeteksinya sedetail kode sendiri.
- **Kontrol & kepemilikan data** berpindah ke pihak ketiga untuk kanal bisnis
  kritis; harga/perilaku bisa berubah sepihak.

### 13.3 Catatan penting

- Gateway lokal **sudah ada** (biaya pembuatan sudah tenggelam). Pindah ke provider
  unofficial **tidak menurunkan risiko ban** — hanya memindahkan beban operasional.
- Baik A maupun B tetap **unofficial** → keduanya melanggar ToS dan berisiko
  kehilangan nomor. Migrasi ke C adalah satu-satunya yang menghapus risiko itu.
- Karena itu keputusan sebenarnya bergantung pada **apakah risiko ban diterima**:
  - Jika tujuannya **hanya** "pesan tidak hilang saat PC mati" dan risiko ban
    diterima → **B** (hosted unofficial) adalah pilihan paling murah & paling
    ringan; A tidak lebih baik dari B untuk kasus ini.
  - Jika tujuannya **juga** menghapus risiko ban + dapat jaminan resmi → **hanya C**.

---

## 14. Fokus utama user: error `fromMe:true @lid`

**Konteks:** user menyatakan kekhawatiran terbesarnya **bukan** ban atau PC mati,
melainkan error `fromMe:true @lid` (`SessionError: No matching sessions found`).
Bagian ini menilai apakah tiap opsi menyelesaikannya.

### 14.1 Apa sebenarnya error ini (dari `docs/sesi/2026-09-30-analisis-baileys-dan-requirement-offline.md`)

- Root cause: Baileys `decode-wa-message.js` (`isJidUser(sender) ? sender : author`)
  memakai JID `@lid` apa adanya → `Signal/libsignal.js`
  (`jidToSignalProtocolAddress()`) membuat alamat sesi **terpisah** dari alamat PN
  yang sudah ada → tidak ada sesi Signal untuk LID → `SessionError`.
  Ini keterbatasan **`libsignal-node`** (belum punya store pemetaan LID↔PN),
  **bukan bug kode Gateway**.
- `@lid` = keputusan WhatsApp per-akun/kontak (migrasi "Linked Identity" untuk
  privasi) — **tidak bisa dikontrol dari Gateway**.
- Ada **dua hal berbeda** yang sering tertukar:
  1. **Noise `fromMe:true @lid`** (echo sinkronisasi antar-device) — umumnya tidak
     fatal, tetapi **memicu false-`degraded`**.
  2. **Sesi desync** (mis. setelah HP WA crash-loop) — Gateway gagal mendekripsi
     **semua** pesan → **pesan hilang**. Ini yang benar-benar berbahaya.

### 14.2 Apakah hosted unofficial (Fonnte/Kirimi) menyelesaikannya? — TIDAK DIJAMIN

- Error ini berasal dari **protokol WhatsApp Web unofficial + library-nya**
  (Baileys/libsignal), bukan dari tempat proses dijalankan. **Pindah ke server
  provider tidak mengubah kelas protokolnya.**
- Provider hosted memakai kelas library yang sama (Baileys atau sejenis); **stack
  mereka tidak dipublikasikan**. Jika Baileys → error yang sama; jika `whatsmeow`
  (Go) mungkin penanganan LID lebih baik, tetapi **belum diverifikasi** dan bisa
  berubah sewaktu-waktu.
- Anda **kehilangan visibilitas**: log ada di server mereka, dan deteksi
  `degraded` buatan kita hilang. Untuk kekhawatiran khusus ini, hosted unofficial
  bisa **lebih buruk** daripada Gateway lokal.
- Mitigasi di jalur unofficial (lokal maupun hosted) hanya **menyembunyikan
  gejala**: (A) upgrade Baileys 7.x (berat, masih RC, belum terbukti memperbaiki
  LID), atau (B) redam noise di deteksi `degraded`.

### 14.3 Yang benar-benar menghilangkan error ini: Cloud API resmi

- Di Cloud API resmi **tidak ada sesi Signal yang Anda kelola** dan **tidak ada
  dekripsi yang bisa gagal** di pihak Anda. Pesan masuk dari device mana pun
  dikirim Meta sebagai **webhook JSON biasa**. → **Kelas error
  `SessionError`/`fromMe:true @lid` tidak ada.**
- Bahkan dengan **Coexistence** (app tetap hidup), pesan yang dikirim dari app
  dikirim sebagai event webhook **`smb_message_echoes`** yang **bisa dibaca** —
  bukan dekripsi Signal → tetap **tanpa** `SessionError`.
- Jadi Cloud API resmi menyelesaikan `@lid` secara **struktural**, sekaligus
  mempertahankan app di HP (Coexistence).

### 14.4 Kesimpulan

- Karena kekhawatiran utama = `fromMe:true @lid`, maka **hosted unofficial bukan
  solusi** — ia hanya memindahkan & menyembunyikan masalah, bahkan menghilangkan
  deteksi milik sendiri.
- **Hanya Cloud API resmi (Gupshup / 360dialog / Twilio / jalur `/v1/waba/*`)
  yang menghilangkan kelas error ini secara struktural.**
- Konsekuensi: jika `@lid` adalah prioritas #1, maka opsi resmi **naik** menjadi
  pilihan utama, dan pertimbangan biaya/sederhana turun menjadi sekunder.

---

## 15. Peringkat opsi (dari yang paling baik)

**Kriteria, sesuai prioritas user:**
(1) menghilangkan `fromMe:true @lid`, (2) jaminan delivery server-side,
(3) app tetap hidup (Coexistence), (4) biaya, (5) kesederhanaan & minim perubahan
AuliaPos.

| # | Opsi | Hilangkan `@lid`? | Kenapa di peringkat ini | Trade-off utama |
|---|---|---|---|---|
| **1** | **Gupshup (Cloud API) + Coexistence**, via Cloud API Bridge | ✔ | Resmi → `@lid` hilang; server-side; **termurah resmi** (≈ Rp 18/pesan, tanpa langganan); Coexistence terdokumentasi; bridge = minim perubahan CI4 | Isu Coexistence dari sisi Meta; kelayakan nomor & Indonesia perlu dikonfirmasi |
| **2** | **360dialog (Regular) + Coexistence** | ✔ | Resmi → `@lid` hilang; Coexistence + infra **paling terbukti** | Langganan ≈ Rp 1,06 jt/bulan |
| **3** | **Twilio (Cloud API)** | ✔ | Resmi → `@lid` hilang; tanpa langganan (≈ Rp 90/pesan) | Dukungan Coexistence belum terverifikasi |
| **4** | **Kirimi jalur WABA resmi** (`/v1/waba/*`) | ✔ | Resmi → `@lid` hilang; support lokal Bahasa Indonesia | Harga WABA tidak publik → perlu penawaran resmi |
| **5** | **Hosted unofficial** (Fonnte / Kirimi Lite / Wablas) | ✖ | Lebih baik dari lokal untuk kasus PC mati; paling ringan; murah | Tetap linked-device; `@lid` **tetap ada**; ban risk; deteksi `degraded` hilang |
| **6** | **Gateway lokal Baileys** (hidupkan + mitigasi) | ✖ | Sudah ada (biaya tenggelam); kontrol + deteksi penuh | PC 24/7; `@lid` **tetap ada** (hanya diredam); ban risk; wildcard: upgrade Baileys 7.x (belum terbukti) |

**Kesimpulan peringkat:**

- **Peringkat 1–4 adalah satu-satunya yang benar-benar menyelesaikan `@lid`** —
  semuanya jalur resmi (Cloud API). Perbedaannya cuma biaya, onboarding, dan
  kematangan Coexistence.
- **Peringkat 5–6 sama-sama unofficial** — berbeda kemasan saja; keduanya
  **tidak** menyelesaikan `@lid`.
- Jika prioritas #1 adalah `@lid`, pilih dari **peringkat 1–4**; di antara mereka,
  **peringkat 1 (Gupshup)** = termurah, **peringkat 2 (360dialog)** = paling aman.

---

## 16. Rencana uji Fonnte (adapter) & pilihan branch/repo

> Catatan: ini **rencana**, belum dieksekusi. Sesuai AGENTS.md, implementasi menunggu
> persetujuan eksplisit user.

### 16.1 Bisa tidak AuliaPos nyambung ke Fonnte? — YA, lewat adapter

AuliaPos **tidak perlu diubah** (cukup ubah `.env`) **jika** kita bangun sebuah
**adapter** yang meniru kontrak HTTP WA-Gateway sekarang, tetapi backend-nya Fonnte:

```
AuliaPos (kontrak lama, tidak berubah)
  → POST /send, /send-media, /media/download   → Adapter → https://api.fonnte.com/send
  ← POST /api/inbox/gateway/messages, /status   ← Adapter ← Webhook Fonnte
```

Adapter = "WA-Gateway dengan backend Fonnte" (bukan Baileys).

### 16.2 Peta kontrak: Fonnte vs kebutuhan CI4

| Kebutuhan (kontrak CI4) | Padanan di Fonnte | Catatan |
|---|---|---|
| `POST /send` (teks) | `POST https://api.fonnte.com/send`, header `Authorization: <TOKEN>` (tanpa `Bearer`), param `target`, `message` | cocok |
| `quoted` (balas/quote) | param `inboxid` | wajib **aktifkan fitur Inbox** di device; Fonnte menyimpan pesan masuk 3 hari |
| `forward` | tidak ada penanda native | pakai fallback prefix teks (seperti sekarang) |
| `POST /send-media` (base64) | param `url` (URL publik) atau `file` (biner) | **hanya paket super/advanced/ultra** |
| media masuk | webhook `url` + `filename` + `extension` | **hanya paket berfitur penuh** |
| `POST /media/download` | ambil `url` dari webhook | bentuk `media_ref` CI4 perlu adaptasi |
| heartbeat / `session_health` | webhook **device status** | adapter isi `ok`/`disconnected`; **tidak ada** konsep `degraded`/`@lid` |
| `chat_id` (JID) | nomor telepon biasa | adapter petakan `sender` ↔ `<nomor>@s.whatsapp.net` |

### 16.3 Batasan paket GRATIS (penting untuk uji)

- Kuota **900 pesan / 20 hari**; **tanpa media** dan **tanpa reply via `inboxid`**
  (fitur `url`/`file`/inbox/attachment hanya di paket berbayar).
- Jadi **uji gratis = teks masuk & keluar saja**. Balas (quote) dan media butuh
  paket berbayar.

### 16.4 Branch terpisah atau repo terpisah?

| Opsi | Penilaian |
|---|---|
| **A. Branch di repo WA-Gateway** (mis. `spike/fonnte`) | **Disarankan untuk uji.** Me-reuse `src/store`, `src/delivery`, `src/api/ci4Routes`; ganti `src/whatsapp/*` jadi klien Fonnte + receiver webhook. Cepat, tidak menyentuh AuliaPos. |
| **B. Repo terpisah** (`fonnte-gateway`) | Untuk **permanen** bila uji lolos — mencerminkan pola "sisi WhatsApp = repo sendiri" (`WA-Gateway` sudah terpisah dari `aulia-app`). |
| **C. Di dalam repo AuliaPos** | **TIDAK disarankan** — beda runtime (Node vs PHP), melanggar arsitektur monolith AuliaPos. |

**Rekomendasi:** **A untuk uji → promosikan ke B kalau lolos.**

### 16.5 Langkah uji (draft)

1. Pakai **nomor uji terpisah** — jangan nomor toko (Fonnte unofficial; ada risiko
   ban & riwayat insiden HP).
2. Buat akun Fonnte, connect device (QR), simpan **token**.
3. Bangun adapter: implementasi `/send`, `/send-media`, `/media/download` (kontrak
   CI4) → Fonnte; terima webhook Fonnte → `POST /api/inbox/gateway/messages`;
   heartbeat → `/api/inbox/gateway/status`.
4. Expose webhook via tunnel (Cloudflare Tunnel / ngrok).
5. Arahkan AuliaPos (dev) `.env`: `inbox.gatewayBaseUrl` + `inbox.gatewayToken` ke adapter.
6. Verifikasi: teks masuk & keluar 2 arah, cek tampil di Inbox POS.
7. Dokumentasikan hasil apa adanya (termasuk batasan media di paket gratis).

### 16.6 Konsekuensi yang harus diterima

- Fonnte tetap **unofficial** → `@lid`, ban risk, dan tanpa jaminan delivery **tetap ada**.
- Media keluar (terutama stiker WebP) **tidak bisa** di paket gratis; perlu paket berbayar.
- Bentuk `media_ref` CI4 (`direct_path`/`media_key_base64`) **tidak sama** → perlu
  penyesuaian kecil di adapter (bukan di AuliaPos bila bisa dipetakan).

---

## 17. Adendum — hasil uji NYATA adapter Fonnte (2026-09-30)

Uji konsep dijalankan di lingkungan dev lokal dengan **nomor uji terpisah**, lewat
adapter di branch `spike/fonnte` (repo WA-Gateway). **AuliaPos tidak diubah kodenya**
(hanya `.env` dev yang diarahkan ke adapter). Ini bukti lapangan, bukan simulasi.

### 17.1 Cakupan & hasil

| Skenario | Hasil | Bukti |
|---|---|---|
| Teks **masuk**: customer → Fonnte → adapter → buffer → CI4 → Inbox UI | **BERHASIL** | log `[FONNTE-IN] pesan masuk tersimpan di buffer durable` → `[DELIVERY] ... diteruskan ke CI4`; baris di `aulia_inboxdb.messages`; tampil di Inbox POS |
| Teks **keluar** dari UI Inbox → CI4 → adapter → Fonnte → WA | **BERHASIL** | log `[SEND-OPERATION] kirim berhasil, operasi sent` dengan `operation_id` (idempotensi CI4 aktif), `wa_message_id 182621219` |
| **Teruskan teks** (`forward: true`) | **BERHASIL** | respons `forward_marker_applied: "text_fallback"`, `wa_message_id 182621592` |
| **Heartbeat** status → badge Inbox | **BERHASIL** (setelah perbaikan) | `gateway_status.status='connected'`, badge UI "Terhubung" |
| **Media keluar** (gambar) **tanpa** caption | **GAGAL** | `FONNTE_SEND_FAILED: "Fonnte menolak kirim: message cannot empty"` |
| **Media keluar** (gambar) **dengan** caption | **GAGAL SENYAP** | respons `success:true` tetapi lampiran tidak terkirim (hanya teks); `media_ref: null` |

### 17.2 Temuan teknis penting

1. **Media keluar di paket gratis Fonnte gagal** — sesuai dokumentasi (`url`/`file` hanya
   paket berbayar). Bahaya sesungguhnya: bila ada **caption**, Fonnte membalas **sukses**
   padahal gambar **dibuang** → **kegagalan senyap**. Kasir mengira gambar terkirim,
   pelanggan hanya menerima teks. Ini risiko **integritas data**, bukan sekadar fitur
   kurang — dan wajib diatasi (verifikasi kirim media, atau tolak lebih awal) sebelum
   jalur ini dipakai untuk operasional.
2. **Idempotensi jalan** — CI4 mengirim `operation_id`; adapter memakai
   `outgoingOperationService` yang sama → `replayed`/`reused`/`in_progress` aman.
3. **Penanda forward hanya `text_fallback`** — konsisten dengan desain (Fonnte tidak
   punya penanda native), tidak ada kehilangan isi pesan.
4. **Media masuk belum diuji** — di paket gratis webhook tidak menyertakan `url`/lampiran;
   perlu paket berbayar.
5. **Setelan webhook Fonnte (terkonfirmasi lapangan)**: diatur di **Device → Edit**, dan
   **Auto Read WAJIB ON** — kalau OFF, webhook **tidak pernah** dipanggil.
6. **`@lid` tidak muncul** di jalur ini karena Fonnte memakai nomor telepon (`wa_id`) —
   tetapi ini karena **tidak ada sesi Signal yang dikelola kita**, bukan karena protokol
   resmi; kelas kegagalan `fromMe:true @lid` tetap milik jalur unofficial.
7. **Bug adapter ditemukan & diperbaiki saat uji**: status optimistic awal sempat
   dianggap "basi" sehingga adapter melaporkan `disconnected` setelah ±2 menit (badge
   Inbox "Terputus"). Diperbaiki: hanya status **eksplisit** (dari webhook device-status)
   yang boleh basi. Commit `46d2ff2` di `spike/fonnte`.

### 17.3 Kesimpulan adendum

- **Pola "adapter yang mempertahankan kontrak CI4" TERBUKTI berjalan penuh lewat `.env`,
  tanpa menyentuh kode AuliaPos.** Pola yang sama persis akan dipakai untuk **Cloud API
  resmi** (Gupshup/360dialog) nanti — jadi bentuk migrasi resmi sudah tervalidasi dan
  risiko integrasinya turun.
- **Uji ini TIDAK mengubah peringkat §15 maupun rekomendasi resmi.** Fonnte tetap
  **unofficial**: `@lid`, risiko ban, dan tanpa jaminan delivery **tetap ada**; media pun
  belum bisa di paket gratis dan **gagal senyap** saat ada caption.
- **Fonnte tetap berstatus "opsi kenyamanan unofficial"** — membuktikan integrasi &
  operasional, bukan penyelesaian masalah `@lid` (lihat §14). Untuk `@lid` dan jaminan
  delivery, jalur resmi (Cloud API) tetap satu-satunya.

---

## 18. Opsi unofficial berbasis library/API server (diminta user)

Ditambahkan: **whatsapp-web.js, WPPConnect, Evolution API, open-wa (`wa-automate`)**.
Semua diverifikasi dari repo resminya (2026-09-30). **Semuanya unofficial** — kecuali
**Evolution API mode Cloud API** (lihat di bawah).

### 18.1 Perbandingan

| Opsi | Teknologi | Bentuk | API/webhook bawaan | Fitur | Lisensi / catatan |
|---|---|---|---|---|---|
| **Baileys** (baseline) | Protokol WhatsApp Web (WebSocket) | Library | tidak (bikin sendiri) | teks/media/sticker/quote/forward (yang dipakai sekarang) | MIT; ringan; **@lid issue** |
| **whatsapp-web.js** | **Puppeteer** (Chromium menjalankan WhatsApp Web) | Library | tidak | multi-device, teks, media (video butuh Chrome), sticker, reply, grup, poll, channel | Apache-2.0; ±22.7k★; **butuh browser** (berat); disclaimer: tidak dijamin bebas blokir |
| **WPPConnect** | Puppeteer (sulla) | Library (+ `wppconnect-server` terpisah) | ya (lewat server) | teks/media/sticker+GIF/forward/multi-session/grup | LGPL; ±3.5k★; butuh browser |
| **open-wa / wa-automate** | Puppeteer/Playwright/Lightpanda | **Easy API** (HTTP API + docs) + SDK | ya (Easy API, integration-webhook, SocketClient, MCP) | teks/media/sticker/forward/grup/Chatwoot/Cloudflare-proxy | **Hippocratic + Do Not Harm v1.1** (bukan OSI); Node ≥22; butuh browser |
| **Evolution API** | **Baileys** *(atau Cloud API resmi)* | **REST API server** (Node/TS + PostgreSQL/MySQL + Redis, Docker) | ya (webhooks; event ke RabbitMQ/Kafka/SQS/Socket.io) | sangat lengkap; multi-instance; media ke S3/MinIO; Chatwoot/Typebot/AI | Apache-2.0; ±9.7k★; **mode Baileys = unofficial**, **mode Cloud API = official** |

### 18.2 Penilaian jujur

- **whatsapp-web.js, WPPConnect, open-wa** = **browser automation** (Chromium
  menjalankan WhatsApp Web). Ini **BUKAN** peningkatan atas Baileys untuk masalah kita:
  - Tetap **linked-device, unofficial** → `@lid`, ban risk, tanpa jaminan delivery.
  - **Lebih berat** (wajib ada Chromium) dan umumnya **lebih mudah terdeteksi** karena
    benar-benar mengotomasi klien web.
  - Kelebihannya: fitur lebih kaya, dan sebagian menyediakan **API/webhook siap pakai**.
- **Evolution API** = paling menarik dari keempat:
  - mode **Baileys** = **setara WA-Gateway sekarang** (fondasi Baileys yang sama) →
    **tidak menyelesaikan `@lid`**; tapi memberi REST API + webhook + manager siap pakai
    (bisa menggantikan adapter manual yang kita bangun).
  - mode **Cloud API resmi** = masuk **jalur resmi** → **menyelesaikan `@lid`/ban**, tapi
    **bukan lagi unofficial** (butuh akun Meta, aturan 24 jam/template, biaya per pesan).
    Ini jadi **bridge self-hosted** alternatif — mirip rencana "Cloud API Bridge" (§6),
    hanya saja memakai perangkat lunak pihak ketiga alih-alih menulis sendiri.
- **Kesimpulan penting:** menambahkan keempatnya **TIDAK mengubah peringkat §15**.
  Mereka masuk **tier unofficial** (setara §15 baris 5–6), kecuali **Evolution API mode
  Cloud API** yang masuk **tier resmi** (setara §15 baris 1–4, beda hanya cara hosting).

### 18.3 Kapan masuk akal memilih salah satu?

- Butuh **API + webhook siap pakai** untuk uji cepat dan tetap unofficial →
  **Evolution API (mode Baileys)** atau **open-wa Easy API**.
- Butuh **fitur spesifik klien web** → whatsapp-web.js / WPPConnect / open-wa.
- Mau **jalur resmi tanpa BSP** tapi tetap self-host → **Evolution API mode Cloud API**,
  konsekuensinya onboarding Meta + biaya per pesan (sama seperti §5).
- **Untuk memenuhi requirement #1 (`@lid` + jaminan delivery)** → tetap **Cloud API
  resmi** (§5/§15). Keempat ini **tidak menggantikannya**; mengubah Baileys → library
  browser-based lain hanya berpindah **dalam kelas unofficial** yang sama.

---

## 19. Provider hosted Indonesia: Fonnte vs Kirimi (fokus media murah)

Konteks: user cocok dengan Fonnte, tetapi **media tidak bisa di paket gratis**.
Ternyata biaya media di Fonnte jauh lebih mahal daripada Kirimi. Semua angka di
bawah **terverifikasi dari dokumentasi/halaman harga resmi masing-masing (2026-09-30)**.

### 19.1 Perbandingan

| Aspek | **Fonnte** | **Kirimi** |
|---|---|---|
| Gratis | Rp 0, 1.000 pesan/bln, **tanpa media** | Starter, 1.000 pesan/bln, **tanpa media** |
| **Paket termurah yang bisa kirim media** | **Super Rp 165.000/bln** | **Lite Rp 29.000/bln** |
| Batas ukuran file | **4 MB** | **64 MB** |
| Balas/quote | `inboxid` (wajib aktifkan fitur Inbox di device) | `quotedMessageId` (langsung, tanpa prasyarat fitur tambahan) |
| Jalur WABA resmi | tidak ditemukan | **ya** (`/v1/waba/*`) — jalur upgrade di provider yang sama |
| Auth | `Authorization: <token>` | `user_code` + `secret` + `device_id`/`waba_id` |
| Webhook | ya | ya |
| Sudah diuji langsung | **ya** (§17) | **belum** |
| Sifat | unofficial (QR) | unofficial (QR) |

### 19.2 Rekomendasi (di antara yang unofficial, untuk kebutuhan media)

- **Kalau media itu prioritas dan ingin tetap unofficial → Kirimi**, bukan Fonnte.
  Untuk fitur yang sama (kirim gambar/dokumen), Kirimi Lite **Rp 29.000/bln** vs
  Fonnte Super **Rp 165.000/bln** — hampir **6x lebih murah**, batas file 8x lebih besar.
- **Fonnte tetap pilihan paling sederhana kalau teks saja** (Free Rp 0 untuk uji,
  dan sudah terbukti jalan di §17).
- **Catatan kejujuran:**
  - **Kirimi belum diuji lapangan.** Statusnya "sesuai dokumentasi", bukan "terbukti".
    Disarankan spike serupa (adapter yang sama, cukup ganti base URL/kredensial)
    sebelum dipakai operasional.
  - Perbandingan kualitatif "Fonnte vs Kirimi" sebagian bersumber dari **blog Kirimi**
    (pihak penjual, bias); angka harga sudah di-cross-check ke dokumentasi resmi tapi
    klaim kualitatif (mis. "Fonnte kalah modern") **bukan** klaim kami.
  - **Keduanya tetap unofficial** → `@lid`, risiko ban, dan tanpa jaminan delivery
    resmi **tetap berlaku** (lihat §14/§15). Memilih Kirimi **bukan** menyelesaikan
    masalah `@lid`.
- **Nilai tambah Kirimi:** jalur **WABA resmi** di provider yang sama berarti toko bisa
  **mulai murah (unofficial) lalu naik ke resmi** tanpa ganti vendor — sesuatu yang
  Fonnte belum tawarkan.
