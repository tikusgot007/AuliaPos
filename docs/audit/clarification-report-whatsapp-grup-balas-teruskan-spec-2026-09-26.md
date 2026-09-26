> [!SUCCESS]
> **REMEDIATION STATUS: RESOLVED**
> Laporan ini sudah diremediasi oleh Specification Architect (2026-09-26).
> - **Projected Readiness Score:** 93/100
> - Temuan Kritis #1–#4 (kontradiksi kode-vs-spec) diperbaiki langsung di `spec-design-grup-tahap1-tab-inbox.md`, `spec-design-balas-pesan.md`, `spec-design-teruskan.md`.
> - Temuan Kritis #5 (kontrak kutipan masuk dari pelanggan) ditutup dengan REQ-010–REQ-013 dan Section 4.4 baru di `spec-design-balas-pesan.md` (v1.1).

---
title: Clarification Report — Spec Grup, Balas Pesan, Teruskan
date: 2026-09-26
stage: Spec (post spec-index.md, spec-design-grup-tahap1-tab-inbox.md, spec-design-grup-tahap2-identitas.md, spec-design-balas-pesan.md, spec-design-teruskan.md)
source_prd: prd-20260926-0024-whatsapp-grup-balas-teruskan.md
readiness_score: 74/100
status: REFINE
---

# Laporan Klarifikasi — Spec Grup, Balas Pesan, Teruskan

**Dokumen diperiksa:** `spec-index.md`, `spec-design-grup-tahap1-tab-inbox.md`, `spec-design-grup-tahap2-identitas.md`, `spec-design-balas-pesan.md`, `spec-design-teruskan.md`, `prd-20260926-0024-whatsapp-grup-balas-teruskan.md`

**Catatan akses:** Repo `tikusgot007/WA-Gateway` tidak dibuka langsung dari sesi ini (sesuai izin akses yang berlaku untuk repo tersebut). Fakta WA-Gateway di spec diverifikasi dari kode publik GitHub oleh penulis spec sebelumnya, bukan oleh sesi klarifikasi ini.

## Readiness Score: 74/100 → status: REFINE

| Aspek | Skor | Alasan |
| --- | --- | --- |
| Completeness (40) | 27/40 | Keputusan 10 (kutipan masuk dari pelanggan) membuka gap struktural baru yang belum ada kontrak/skemanya di spec manapun. |
| Clarity (30) | 25/30 | Kontradiksi REQ-006/CON-002 (Balas Pesan) dan REQ-004 (Teruskan audio/video) sudah punya keputusan, tapi belum dituliskan ulang ke teks spec. |
| Alignment (30) | 22/30 | 3 kontradiksi kode-vs-spec ditemukan yang harus diperbaiki sebelum Plan. |

Skor di bawah ambang 80 → **tidak boleh lanjut ke `/sdlc-plan-tasks`** sebelum Temuan Kritis di bawah ini diperbaiki.

## 🔴 Temuan Kritis (harus diperbaiki sebelum `/sdlc-plan-tasks`)

1. **Tahap 1 §12 — contoh kode kontradiksi dengan REQ-003.** Contoh menaruh `queue_status = 'grup'` di awal loop `withComputedStatus()`, padahal kode asli (`app/Models/ConversationModel.php:230-240`) menimpa `queue_status` tanpa syarat di akhir loop — nilai `'grup'` akan otomatis tertimpa. Perbaikan: assignment `'grup'` harus ditaruh setelah blok if/elseif/else yang sudah ada, atau tambahkan cabang `else` di blok itu.
2. **`apiPerluDibalasCount()` (Tahap 1 REQ-004) — query tidak membawa `jid_type`.** `select()` di `app/Controllers/Inbox.php:323-335` tidak menyertakan kolom `jid_type`, padahal REQ-004 mensyaratkan filter berdasarkan kolom itu. Tanpa perbaikan, filter grup di fungsi ini tidak mungkin berjalan.
3. **CON-002 Tahap 1 — dasar rujukannya salah.** Spec menyebut pola disabled `tombolKonfirmasiNomor` (`app/Views/inbox/index.php:1166`) sebagai preseden, padahal baris itu adalah pola tampil/sembunyi (ternary), bukan disabled. Spec juga menyebut "Edit Profil" sebagai tombol header, padahal komentar kode (`index.php:1223`) menyatakan Edit & Hapus sudah dipindah ke baris daftar kiri, bukan di header. (Sudah diselaraskan lewat Resolved Item #5 di bawah — tinggal ditulis ulang ke file spec.)
4. **Endpoint Gateway tidak konsisten penamaannya.** Spec Balas Pesan & Teruskan Section 4.1 menulis `POST /api/inbox/gateway/send`, tapi Section 7 di spec yang sama dan kode asli (`Inbox.php:2226`, `Config/Inbox.php:32`) memakai `POST {gatewayBaseUrl}/send`. Perlu diseragamkan sebelum Plan.
5. **Kontrak kutipan masuk dari pelanggan (Resolved Item #10) belum ada di spec manapun.** Perlu REQ baru di `spec-design-balas-pesan.md`: field baru di payload `POST /api/inbox/gateway/messages` untuk kutipan pada pesan masuk, kolom penyimpanan baru, dan aturan tampilan di UI. Ini pekerjaan authoring (`/sdlc-define-specs`), bukan wewenang sesi klarifikasi ini.

## ✅ Resolved Items & Agreements

| # | Topik | Keputusan |
| --- | --- | --- |
| 1 | Deteksi grup | `jid_type = 'group'` ATAU `chat_id` berakhiran `@g.us` |
| 2 | Badge response_state & titik SLA pada grup | Disembunyikan pada baris percakapan grup |
| 3 | Tombol "Tandai Dibaca" & "Handoff" pada grup | Disembunyikan |
| 4 | `assigned_to`/`status` lama pada percakapan grup existing | **Tidak dibersihkan otomatis** — diterima sebagai limitasi yang didokumentasikan, bukan bug yang harus diperbaiki |
| 5 | Edit Profil vs Hapus percakapan pada grup | "Edit Profil" tampil *disabled*; "Hapus percakapan" tetap berfungsi penuh. CON-002 Tahap 1 harus ditulis ulang agar tidak merujuk pola/lokasi kode yang keliru (lihat Temuan Kritis #3) |
| 6 | Media untuk Balas Pesan & Teruskan | Field `quoted`/`forward` ditambahkan juga ke kontrak `POST /send-media` (bukan hanya `/send`), file diambil dari penyimpanan lokal AuliaPos, memakai mekanisme base64 yang sudah ada — termasuk kasus balas-dengan-media sambil mengutip. `forward_marker_applied` juga berlaku di jalur ini. |
| 7 | Aksi "Teruskan" pada pesan audio/video | Opsi tetap **muncul di menu tapi dalam keadaan mati (disabled)**, berlabel alasan (mis. "Teruskan — audio/video tidak dapat diteruskan") — bukan disembunyikan total. Ini memenuhi janji PRD GH-016 ("kasir menerima penjelasan yang bisa dipahami") tanpa melanggar prinsip "jangan tampilkan tombol yang pasti gagal" (tombol memang tidak bisa diklik). |
| 8 | Kegagalan pengiriman balasan berkutipan (Balas Pesan) | Tiga reaksi eksplisit, bukan dua: (a) Gateway melaporkan `quote_applied: false` → pesan tetap terkirim, label "Terkirim tanpa kutipan"; (b) Gateway menolak dengan error pasti → pesan ditandai gagal, kasir boleh kirim ulang; (c) hasil tidak pasti (koneksi putus/timeout) → peringatan "Hasil belum pasti, jangan kirim ulang dulu" (pola yang sudah ada di `index.php:2308`). Frasa di REQ-006 yang menyamakan "request gagal total" dengan "tetap terkirim" harus dihapus karena bertentangan dengan CON-002. |
| 9 | Istilah "kutipan" vs `CONTEXT.md` | "Kutipan" ditetapkan sebagai istilah sah untuk **hasil/tampilan** quote; "Balas Pesan" tetap istilah untuk **aksinya**. `CONTEXT.md` sudah diperbarui pada sesi ini (lihat commit lokal). |
| 10 | Kutipan pada pesan masuk dari pelanggan | **Cakupan Tahap 3 diperluas** untuk menangani ini (bukan dianggap Out of Scope) — konsekuensinya adalah Temuan Kritis #5 di atas. |

## 🟡 Assumed / Auto-Resolved / Out of Scope

- `ASSUMPTION-001` s/d `005` (nilai literal `jid_type`, ketersediaan `key.participant`, `groupMetadata().subject`, opsi `quoted` Baileys, `contextInfo.isForwarded`) — **tetap belum diverifikasi** terhadap instalasi WA-Gateway produksi dari sesi ini. Wajib diverifikasi developer WA-Gateway sebagai langkah pertama implementasi masing-masing tahap.
- Sinkronisasi ulang nama grup setelah `group_name` pertama kali terisi (mis. admin WhatsApp mengganti nama grup) — tetap **Out of Scope** untuk PRD ini, sudah dicatat sebagai TODO oleh `spec-design-grup-tahap2-identitas.md` sendiri.
- Daftar anggota grup, kelola anggota, riwayat forward berlapis — tetap Non-goal permanen sesuai PRD.

## ➡️ Next Steps

1. Perbaiki 4 Temuan Kritis teknis (#1–#4) langsung di file spec terkait — ini koreksi faktual berdasarkan kode yang sudah ada, tidak membutuhkan keputusan baru dari pemilik proyek.
2. Tulis REQ baru untuk kutipan pada pesan masuk dari pelanggan (Temuan Kritis #5) lewat `/sdlc-define-specs` — ini pekerjaan authoring spec, di luar wewenang skill klarifikasi.
3. Setelah butir 1–2 selesai, jalankan ulang `/sdlc-clarify-reqs` singkat untuk recheck, atau langsung lanjut ke `/sdlc-plan-tasks` bila pemilik proyek menilai kelima poin itu sudah cukup jelas untuk developer.
