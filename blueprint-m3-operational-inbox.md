# Blueprint Implementasi — M3 Operational Inbox

**Versi:** 1.0
**Tanggal:** 20 September 2026
**Dasar:** `Panduan_Layar_AuliaPos_M3.md` (v1.0) + analisis langsung terhadap kode AuliaPos v2.2
**Status:** Draft untuk direview — beberapa keputusan desain masih perlu konfirmasi Anda (ditandai 🔶)

---

## 0. Ringkasan Temuan Penting

Sebelum masuk ke rencana, ada temuan besar dari analisis kode: **backend untuk sebagian besar Fase 1 MVP sudah ada**, bukan cuma skema kolomnya. Ini mengubah blueprint dari "bangun dari nol" jadi "sambungkan UI ke backend yang sudah jalan + tutup beberapa gap."

Endpoint yang sudah ada dan sudah production-ready di `app/Controllers/Inbox.php`:

| Endpoint | Fungsi | Status |
|---|---|---|
| `ambilPercakapan($id)` | Take/assign ke diri sendiri | ✅ Ada |
| `lepasPercakapan($id)` | Release ownership | ✅ Ada |
| `tandaiDibaca($id)` | Mark read | ✅ Ada |
| `snoozePercakapan($id)` | Snooze dengan parameter menit | ✅ Ada — persis sesuai Layar 4 dokumen |
| `tutupPercakapan($id)` | Close, idempotent | ✅ Ada — reopen otomatis saat pesan masuk baru (sudah sesuai perilaku di Layar 6) |
| `kirim()` / `kirimMedia()` | Balas customer | ✅ Ada |
| `cekOwnership()` | Cegah 2 orang pegang 1 chat | ✅ Ada, tapi **application-level, bukan atomic** (lihat Bagian 3) |

**Artinya**: effort Fase 1 MVP sebagian besar adalah UI (Queue View, Conversation Detail) yang memanggil endpoint ini, bukan menulis backend baru. Ini mengubah estimasi ke arah lebih cepat — dengan syarat gap di Bagian 2 dan 3 di bawah ini ditutup dulu.

---

## 1. Pemetaan Layar → Backend

### Layar 1 — Queue View

Computed dari kolom existing, **tanpa migration baru**:

| Tab | Query |
|---|---|
| Belum Diambil | `assigned_to IS NULL AND status='open'` |
| Open | `assigned_to IS NOT NULL AND status='open' AND (last_message_direction='incoming' OR last_message_direction IS NULL)` |
| Menunggu | `status='open' AND last_message_direction='outgoing'` |
| Ditunda | `snoozed_until IS NOT NULL AND snoozed_until > NOW()` |
| Selesai | `status='closed'` |

🔶 **Keputusan diperlukan**: dokumen Anda mendefinisikan 5 state sebagai tampilan, bukan kolom DB. Rekomendasi saya: **pertahankan sebagai computed**, jangan buat kolom `display_status` baru — lebih murah, dan begitu M2 selesai (state consistency), computed state ini otomatis lebih akurat tanpa migration tambahan. Risikonya: logika computed ini harus konsisten di satu tempat (Model/Service), tidak boleh tersebar di controller & frontend. **Rekomendasi konkret**: taruh di method baru `ConversationModel::withComputedStatus()` atau sejenis, supaya satu sumber kebenaran.

Prioritas dot warna (SLA) — lihat Bagian 2.

### Layar 2 — Conversation Detail

Semua action bar (Balas, Assign, Tunda, Selesai) sudah punya endpoint. Yang perlu dibangun:
- UI 3-panel (kiri: quick list, tengah: thread, kanan: properties)
- Panel kanan: SLA timer (perlu Bagian 2), Customer Context (butuh keputusan cakupan — lihat 🔶 di bawah)

🔶 **Keputusan diperlukan**: "Customer Context" di dokumen menyebut "order/payment (jika ada)" — ini menyentuh wilayah **M4 (POS Integration)**, bukan M3 murni. Rekomendasi: di Fase 1 M3, batasi Customer Context ke data yang sudah ada di Inbox sendiri (nama, nomor, riwayat percakapan), dan tunda order/payment sampai M4. Kalau dipaksakan sekarang, ini bocor scope dari M3 ke M4 sebelum waktunya.

### Layar 3 — Handoff

**Gap nyata.** Tidak ada endpoint atau tabel untuk ini. Perlu:
- Migration baru: tabel `conversation_handoffs` (kolom minimal: `conversation_id`, `from_user_id`, `to_user_id`, `ringkasan`, `next_action`, `catatan`, `created_at`)
- Controller method baru: `handoffPercakapan($id)`
- Update `assigned_to` ke `to_user_id` setelah handoff (reuse logic mirip `ambilPercakapan`, tapi assign ke user lain, bukan diri sendiri)

### Layar 4 — Snooze Dialog

Backend **sudah lengkap** (`snoozePercakapan`). Murni kerjaan UI: dialog dengan input waktu + alasan. 🔶 Field "Alasan" di dokumen belum ada kolomnya — kalau mau disimpan, perlu kolom baru `snooze_reason` di `conversations`, atau simpan sebagai internal note (lihat Layar 5) supaya tidak nambah kolom.

### Layar 5 — Internal Note

**Gap nyata.** Tabel `messages` saat ini tidak punya flag `is_internal`. Opsi:
- **Opsi A (direkomendasikan)**: tambah kolom `is_internal BOOLEAN DEFAULT FALSE` di tabel `messages`, reuse struktur pesan yang ada. Internal note jadi baris `messages` biasa dengan flag ini, tidak dikirim ke Gateway.
- Opsi B: tabel terpisah `conversation_notes`. Lebih bersih secara konsep tapi duplikasi struktur thread — tidak direkomendasikan kecuali ada alasan kuat.

@mention: perlu tabel kecil terpisah (`message_mentions`: `message_id`, `mentioned_user_id`) kalau mau bisa di-query ("catatan yang mention saya"). Kalau cuma tampilan teks `@nama` tanpa fungsi notifikasi, cukup simpan sebagai teks biasa — **🔶 perlu diputuskan seberapa penting fitur notifikasi mention ini di Fase 1** (dokumen menandainya opsional).

### Layar 6 — Selesai/Arsip

Backend sudah ada (`status='closed'`, reopen otomatis). Murni UI, tinggal filter tab Selesai dari Queue View.

### Layar 7 — Filter & Pencarian

Murni UI + query parameter tambahan di endpoint `apiConversations()` yang sudah ada (perlu dicek apakah endpoint ini sudah terima parameter filter, atau perlu diperluas — bukan gap besar, cukup ekstensi endpoint existing).

---

## 2. Gap: SLA Timer & Warna Prioritas

Ini gap desain, bukan cuma teknis — dokumen belum eksplisit soal threshold dan sumber waktu.

🔶 **Perlu diputuskan sebelum coding**:
1. Threshold merah/kuning/hijau dalam menit — nilai pasti (misal: hijau <30m, kuning 30-60m, merah >60m)? Sama untuk semua conversation atau bisa beda per kategori?
2. Sumber waktu acuan: pakai `last_message_at` yang sudah ada (aging sejak pesan terakhir), bukan kolom baru — ini pilihan paling murah dan **direkomendasikan** kecuali ada kebutuhan SLA per-jenis-chat yang beda.
3. Threshold ini disimpan di mana — hardcode di config PHP, atau tabel setting yang bisa diubah admin tanpa deploy? Untuk Fase 1, hardcode di `app/Config/Inbox.php` (yang sudah ada) sudah cukup; jadikan tabel setting kalau nanti butuh fleksibel per-toko.

---

## 3. Prasyarat M1/M2 — Kenapa Ini Bukan Basa-Basi

Saya konfirmasi langsung di kode: `cekOwnership()` adalah **read-then-write check di level aplikasi**, bukan atomic di database (tidak ada row lock/versioning). Artinya: dua staff yang klik "Ambil" nyaris bersamaan **secara teknis masih bisa lolos race**, sekalipun kejadian sudah jarang di praktik. Ini persis risiko P1 "ownership race" dari Black Hat sebelumnya.

**Kesimpulan**: Anda **boleh mulai** membangun UI Fase 1 M3 (Queue View, Conversation Detail dasar) secara paralel dengan M2, **selama fitur assign/ambil tidak dipakai di kondisi produksi ramai sebelum M2 kelar**. Tapi Handoff (Layar 3) dan collision detection (Fase 2) harus tunggu M2 selesai — dua fitur ini secara langsung bergantung pada ownership yang benar-benar atomic, kalau dibangun sekarang hanya akan mewarisi bug yang sama.

---

## 4. Rencana Bertahap (Revisi dari Dokumen Asli)

### Fase 1a — Bisa mulai sekarang (paralel dengan M1/M2, backend sudah siap)
- Queue View (computed status, tanpa migration)
- Conversation Detail dasar (thread + action bar, tanpa SLA/Customer Context dulu)
- Snooze Dialog (backend sudah ada)
- Selesai/Arsip tab

### Fase 1b — Butuh keputusan desain dulu (Bagian 2 & 5 di atas), tapi tidak bergantung M2
- SLA Timer + warna prioritas (setelah threshold diputuskan)
- Internal Note (setelah opsi A/B diputuskan, migration kolom `is_internal`)
- Filter & Pencarian

### Fase 2 — Tunggu M2 selesai
- Handoff (butuh migration `conversation_handoffs` + ownership yang benar-benar atomic)
- Collision detection
- Auto-assignment

### Fase 3 — Tunggu M5 (tidak berubah dari dokumen asli)
- Intent-based filters, AI summary, suggested reply

---

## 5. Migration Baru yang Dibutuhkan (Ringkasan)

| Migration | Untuk Layar | Kapan |
|---|---|---|
| Tambah `is_internal` ke `messages` | Internal Note | Fase 1b |
| (Opsional) `snooze_reason` ke `conversations` | Snooze Dialog | Fase 1b, kalau alasan snooze mau disimpan terpisah dari note |
| Tabel baru `conversation_handoffs` | Handoff | Fase 2, setelah M2 |
| (Opsional) tabel `message_mentions` | @mention di note | Fase 1b, kalau fitur notifikasi mention diperlukan |

Semua migration ini **kecil dan additive** (tambah kolom/tabel baru, tidak mengubah struktur existing) — tidak ada risiko migrasi data besar.

---

## 6. Hal yang Perlu Anda Putuskan Sebelum Fase 1b Dimulai

Ringkasan 🔶 dari atas, supaya tidak terlewat:
1. Status granular: computed (direkomendasikan) atau kolom `display_status` baru?
2. Threshold SLA warna: berapa menit untuk kuning/merah?
3. Customer Context di Layar 2: dibatasi ke data Inbox saja dulu (rekomendasikan), atau ditarik order/payment dari M4 lebih awal?
4. Internal Note: opsi A (kolom `is_internal` di `messages`, direkomendasikan) atau opsi B (tabel terpisah)?
5. @mention: perlu fungsi notifikasi nyata, atau cukup teks tampilan di Fase 1?

Setelah ini diputuskan, saya bisa bantu susun ticket-ticket detail (mirip format ticket 01-16 M1) untuk M3 Fase 1a dan 1b.
