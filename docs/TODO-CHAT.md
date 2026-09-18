# TODO — Modul Chat/Inbox WhatsApp

Dokumen pelacak keputusan & pekerjaan yang masih menggantung untuk modul Chat/Inbox. Update setiap ada keputusan baru — jangan biarkan item selesai tetap tercatat "pending".

---

## 🔶 Keputusan tertunda (butuh data nyata sebelum lanjut)

### Tahap B — Thumbnail instan (jpegThumbnail)
**Status: PENDING** — menunggu hasil test Tahap C di kondisi nyata.

- Tahap C (penyimpanan permanen media ke disk lokal/HDD eksternal) sudah selesai & teraudit di branch `feature/inbox-media-storage` (belum di-PR/merge ke `v2.2`).
- Sebelum mengerjakan Tahap B, **test dulu**: kirim beberapa gambar baru ke akun WhatsApp toko (setelah HDD eksternal terpasang & `.env` diisi), buka conversation-nya, rasakan apakah delay "kayak loading" sudah cukup hilang dengan Tahap C saja.
- Hal yang perlu dipisahkan saat test: apakah delay yang masih terasa (kalau ada) itu soal *media* (harusnya sudah teratasi Tahap C) atau soal *render/JS saat pindah conversation* (Tahap B tidak akan memperbaiki ini).
- **Kalau Tahap C saja sudah cukup** → coret Tahap B dari rencana, jangan cuma didiamkan. Update dokumen `Tahap-B-C-Thumbnail-dan-Storage-Permanen.md` untuk menandai Bagian TAHAP B sebagai "tidak dikerjakan, keputusan final" + alasannya.
- **Kalau ternyata masih perlu** → lanjutkan Langkah B1 (perlu akses ke repo Gateway/Node.js, di luar `AuliaPos` — belum ada linknya).

---

## 🔴 P0 — belum dikerjakan sama sekali (dari audit awal, masih relevan di `v2.2`)

- [ ] **`hapusPercakapan()` belum menegakkan aturan admin-only + wajib `status='closed'`** — saat ini cuma cek `cekOwnership()`. Ini gap keamanan/data-loss yang sudah disepakati sejak `docs/CHAT.md` awal tapi belum pernah ditutup di kode. *(app/Controllers/Inbox.php)*
- [ ] **Tidak ada test race-condition untuk `ambilPercakapan()`** — pernah diklaim "diverifikasi lewat test" di changelog lama, tapi tidak ada file test yang bisa dijalankan ulang.

## 🟡 P1 — belum dikerjakan

- [ ] Internal Note (catatan staff, tidak terkirim ke WhatsApp) — perlu tabel baru `conversation_notes`.
- [ ] Reply/Quote pesan.
- [ ] Customer Context panel (riwayat order) — **butuh audit terpisah ke modul Transaksi/`TransaksiModel` dulu**, belum pernah dicek apakah ada query siap-pakai "transaksi berdasarkan nomor HP".
- [ ] Takeover (ambil-alih assignment oleh admin) — cek apakah sudah reset `last_seen_by_assignee_at` untuk assignee baru; belum pernah diverifikasi.

## 🟢 P2/P3 — belum dikerjakan, tidak urgent

- Canned response/template balasan cepat, search pesan dalam conversation, label/tag manual (jangan pakai nama "Priority", sudah dipakai domain lain di `USER-SHIFT.md`), forward pesan.
- Edit/delete pesan, reaction, voice/video call, groups — sengaja di luar scope selamanya (lihat `Tahap-B-C...` & review awal untuk alasan).

---

## ✅ Sudah selesai & teraudit

- **Tahap A** — Response State (Perlu Dibalas/Menunggu Customer/Follow-up/Selesai), badge sidebar, snooze, tandai dibaca. Live di `v2.2`.
- **Port awal + sticker + drag-drop + HTTP cache media** — live di `v2.2`.
- **Tahap C** — penyimpanan permanen media ke disk lokal/HDD eksternal. Selesai di branch `feature/inbox-media-storage`, **belum di-PR/merge**.
- `v2.1` dikembalikan bersih ke sebelum ada chat (commit `82a5c68`) — seluruh fitur chat resmi tinggal di `v2.2`, bukan `v2.1`.

---

## Catatan arsitektur penting (supaya tidak diulang tanya)

- Prinsip lama "tidak pernah simpan media permanen" **sudah dicabut** — lihat Tahap C. Belum ada kebijakan retensi/pembersihan otomatis (sengaja, hindari kompleksitas prematur untuk skala 1 toko).
- Semua kerja chat dibangun di atas **`v2.2`**, bukan `v2.1`. `v2.1` = baseline produksi bersih, jangan disentuh fitur chat apa pun sampai keputusan sadar untuk merilis.
- Gateway WhatsApp (Node.js/Baileys) ada di **repo terpisah**, tidak pernah diaudit langsung di sesi manapun — kalau ada perubahan yang butuh sisi Gateway (seperti Tahap B1), itu selalu jadi dependency eksternal yang harus dikerjakan terpisah.
