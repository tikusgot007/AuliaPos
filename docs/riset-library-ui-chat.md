# Handoff Riset: Library UI Chat Siap Pakai untuk Inbox WhatsApp

- **Tanggal**: 2026-10-02
- **Jenis**: handoff riset (tidak ada perubahan kode)
- **Repo terkait**: `aulia-app` (v2.4)
- **Status**: draf awal untuk tim riset — perlu pendalaman sebelum keputusan adopsi
- **Konteks pemicu**: dua bug ditemukan hari ini di gateway (quoted-reply hilang, forward masuk tidak ditandai — lihat `docs/TODO.md` TODO-F4/TODO-F5). Ini memicu pertanyaan: apakah UI chat kelas ini lebih baik pakai library matang daripada menulis ulang sendiri.

---

## 1. Tujuan riset

Evaluasi apakah tampilan Inbox WhatsApp (`app/Views/inbox/index.php`) sebaiknya diganti sebagian/seluruhnya dengan **library UI chat siap pakai**, untuk mengurangi kelas bug "fitur chat umum belum lengkap" (quoted-reply, forward, reactions, dll.) yang saat ini ditulis manual.

**Scope riset**: hanya lapisan **tampilan** (render bubble, daftar percakapan, input, indikator quote/forward). **Bukan** riset backend/gateway — itu sudah ada di `docs/riset-alternatif-baileys.md` (topik berbeda: pengganti Baileys, bukan UI).

## 2. Kondisi saat ini (terverifikasi)

- **Stack tampilan**: vanilla JS + PHP dalam satu file (`app/Views/inbox/index.php`, 178 KB, ~3.795 baris mencampur `<style>`, HTML, dan `<script>`). **Tidak ada** framework frontend (React/Vue/Alpine) terdeteksi di proyek ini.
- **Alur data**: `Inbox` controller (CI4) menyediakan endpoint JSON yang dipanggil AJAX dari view:
  - `GET /inbox/api/conversations` — daftar percakapan
  - `GET /inbox/api/conversations/(:num)/messages` — pesan dalam satu percakapan (`Inbox::apiMessages`, `app/Controllers/Inbox.php:383`)
  - `POST /inbox/kirim`, `/inbox/kirim-media` — kirim teks/media
  - Daftar lengkap route: `app/Config/Routes.php:38-62`
- **Skema data pesan** (`aulia_inboxdb.messages`, lihat `app/Models/MessageModel.php`): kolom relevan untuk UI chat —
  `direction` (incoming/outgoing), `message_type` (text/image/document/sticker/audio/video/location/contact/unsupported), `text`, `media_path`/`media_mime_type`/`media_filename`, `sender_jid`, `message_timestamp`, `send_status` (received/sent/failed), `quoted_wa_message_id`/`quoted_sender_label`/`quoted_snippet`/`quoted_media_available`/`quoted_source_message_id`/`quoted_media_type`, `is_forwarded`, `is_internal`.
- **Fitur chat yang SUDAH ada secara custom** (ditulis manual di controller + view): bubble pesan, badge "Diteruskan" (`is_forwarded`, lihat `Inbox.php:1512-1515`, label UI di `app/Views/inbox/index.php:2364`), kutipan/quote (REQ-007/REQ-008, kolom `quoted_*`), catatan internal (`is_internal`), status kirim, upload media, assignment agen (`assigned_to`), snooze, handoff antar kasir.
- **Bug yang baru ditemukan** (TODO-F4, TODO-F5 di `docs/TODO.md`): gateway gagal mengekstrak metadata `quoted`/`forwarded` dari sebagian bentuk payload WhatsApp — **ini bug di lapisan gateway (ekstraksi data), bukan di lapisan tampilan**. Mengganti library UI **tidak otomatis memperbaiki** bug ini; data `quoted_wa_message_id`/`is_forwarded` tetap harus benar dari gateway dulu, baru UI bisa menampilkannya.

## 3. Kandidat yang sempat dibahas (belum diriset mendalam)

| Kandidat | Jenis | Catatan awal |
|---|---|---|
| **Advanced Chat Components** (nama baru dari `vue-advanced-chat`) | Library UI murni, framework-neutral | MIT license, 2.1k★. Backend-agnostic ("you own the data layer"). Fitur: rooms, messages, files, audio, reactions, replies, edits, typing indicator. Tersedia sebagai **Web Component** (`<advanced-chat-components>`) — bisa ditempel di halaman PHP vanilla tanpa migrasi ke SPA penuh. Ada 2 versi: `3.0.0-rc.3` (next, belum stabil) dan `vue-advanced-chat@2.1.2` (stabil, branch `v2` terpisah). **Belum dicek**: dukungan forward secara eksplisit, lisensi dependency pihak ketiga (`THIRD_PARTY_LICENSES.md`), beban bundle/build step. |
| **react-chat-elements** | Library komponen React | Bubble, quoted-reply, forward indicator, status centang — tampilan mendekati WhatsApp. Perlu React (belum ada di proyek). Belum dicek lisensi/maintenance aktif. |
| **@chatscope/chat-ui-kit-react** | Library komponen React | Mirip di atas + typing indicator, sidebar. Perlu React. Belum dicek. |
| **Chatwoot** | Platform omnichannel inbox lengkap (self-hosted) | Ini **bukan** library UI saja — solusi penuh (backend+UI+DB sendiri). Disebutkan di awal diskusi sebagai pembanding, tapi user secara eksplisit memilih **hanya ambil tampilannya**, bukan platform penuh. Dicatat di sini sebagai referensi "kalau mau lihat UI matang seperti apa", bukan kandidat utama. |
| CometChat UI Kit / Stream Chat SDK | SDK chat (UI + realtime) | Biasanya terikat ke backend SaaS vendor; perlu cek apakah ada mode "UI only" dengan data self-managed dan skema lisensinya untuk itu. Belum diriset. |

## 4. Pertanyaan yang perlu dijawab tim riset

1. **Kecocokan arsitektur**: proyek ini PHP (CI4) + vanilla JS, tanpa build step JS (tidak ada `package.json` bundler untuk frontend — perlu dikonfirmasi). Apakah menambah library berbasis npm (perlu Vite/webpack untuk build, meski dipakai sebagai Web Component) sepadan dengan manfaatnya, atau cukup pakai versi UMD/CDN siap pakai tanpa build step?
2. **Mapping data**: skema `messages`/`conversations` AuliaPos (lihat §2) perlu dipetakan ke bentuk data yang diharapkan library (mis. `ChatModel`/`MessageModel` ala Advanced Chat Components). Berapa besar lapisan adapter yang dibutuhkan?
3. **Fitur yang benar-benar dipakai AuliaPos tapi non-standar**: `is_internal` (catatan internal, bukan pesan ke pelanggan), `assigned_to`/handoff antar kasir, `snoozed_until`, badge SLA (`InboxSlaService`) — apakah kandidat library punya titik ekstensi (slot/custom render) untuk fitur-fitur khas POS ini, atau akan butuh banyak workaround?
4. **Lisensi & maintenance**: MIT/lisensi kompatibel, aktif di-maintain, tidak ada dependency bermasalah (cek `THIRD_PARTY_LICENSES.md` tiap kandidat).
5. **Precedent internal**: `docs/riset-alternatif-baileys.md` (riset backend gateway, bukan UI) punya pola dokumen yang bisa dicontoh untuk struktur rekomendasi (ringkasan eksekutif → kandidat → rekomendasi dengan trade-off).
6. **Biaya migrasi vs manfaat**: sesuai AGENTS.md §1.2 (YAGNI, boring over clever) — bug TODO-F4/F5 sebenarnya ada di **gateway**, bukan UI. Perlu dipastikan mengganti library UI benar-benar menyelesaikan masalah yang memicu riset ini, bukan solusi yang mengarah ke masalah yang salah.

## 5. Batasan dan di luar cakupan riset ini

- **Tidak** mengevaluasi ulang pengganti gateway/Baileys — itu topik `docs/riset-alternatif-baileys.md`.
- **Tidak** mengevaluasi platform inbox penuh (Chatwoot dkk.) — user eksplisit hanya mau lapisan tampilan.
- **Tidak** ada keputusan adopsi di dokumen ini — ini murni titik awal/pointer untuk tim riset menindaklanjuti.
- Perbaikan bug TODO-F4/F5 (gateway) **tidak bergantung** pada hasil riset ini dan bisa dikerjakan independen.

## 6. Output yang diharapkan dari tim riset

Dokumen riset lanjutan (bisa pakai pola `docs/riset-alternatif-baileys.md`) yang menjawab §4, dengan:
- Perbandingan kandidat (minimal 2-3) mencakup: kecocokan stack, kebutuhan adapter data, fitur custom AuliaPos yang harus didukung, lisensi, estimasi upaya integrasi.
- Satu rekomendasi dengan trade-off, sesuai format AGENTS.md §1 (opsi A/B + rekomendasi beralasan).
- Status eksplisit: `[BELUM DIVERIFIKASI]` untuk klaim yang belum dicek langsung dari kode/dokumentasi resmi kandidat.

---

**Catatan metodologi**: dokumen ini ditulis dari diskusi eksploratif (bukan riset mendalam) — sumber utama adalah README resmi 1 kandidat (`advanced-chat/advanced-chat-components` di GitHub) dan pengetahuan umum untuk kandidat lain yang **belum diverifikasi** terhadap dokumentasi resminya masing-masing. Tim riset wajib memverifikasi ulang semua klaim sebelum dipakai sebagai dasar keputusan.
