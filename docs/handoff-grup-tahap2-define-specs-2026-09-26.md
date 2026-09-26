# Handoff — Prompt `/sdlc-define-specs` untuk Amandemen Grup Tahap 1 + Tahap 2

Dokumen ini menyiapkan sesi authoring berikutnya. Sumber keputusan: `docs/audit/clarification-report-grup-tahap2-identitas-2026-09-26.md` (Readiness 84/100, PROCEED).

## 1. Prompt Siap Pakai

Salin teks berikut apa adanya ke sesi chat baru.

```text
/sdlc-define-specs

Amandemen DUA spec dalam satu sesi, berdasarkan laporan klarifikasi resmi
docs/audit/clarification-report-grup-tahap2-identitas-2026-09-26.md
(Readiness 84/100, PROCEED).

Upstream wajib dibaca:
- prd-20260926-0024-whatsapp-grup-balas-teruskan.md v1.0
- spec/spec-index.md
- spec/spec-design-grup-tahap1-tab-inbox.md v1.1
- spec/spec-design-grup-tahap2-identitas.md v1.0
- docs/CHAT.md
- docs/audit/clarification-report-grup-tahap2-identitas-2026-09-26.md

BAGIAN A — spec/spec-design-grup-tahap1-tab-inbox.md (naik ke v1.2):
1. Perluas CON-004 dan AC-006 agar juga mencakup Inbox::handoffPercakapan()
   dan Inbox::tandaiDibaca() -> tolak 403 untuk conversation.jid_type='group'.
   Guard diletakkan setelah cek 404 dan sebelum cek eligibility/ownership,
   supaya jawabannya 403 (konsisten 6 endpoint lain), bukan 409.
2. Selaraskan Section 9 menjadi invariant positif: pada percakapan grup hanya
   berlaku baca, kirim pesan (teks/media), dan Internal Note; seluruh endpoint
   aksi lain menolak 403. Sebut dimensi Read/Unread secara eksplisit agar tidak
   bentrok dengan Section 1.1 (yang menyatakan Read/Unread out of scope).
3. Tambahkan AC + catatan test otomatis yang menuntut kedua endpoint 403,
   tanpa mengubah perilaku percakapan pribadi.

BAGIAN B — spec/spec-design-grup-tahap2-identitas.md (naik ke v1.1):
1. T1: koreksi REQ-004 — InboxGatewayApi.php:251 SUDAH menulis sender_jid
   tanpa syarat untuk semua pesan; hapus klaim "tinggal diisi dari payload
   baru"; sisa pekerjaan hanya validasi (T2) dan tampilan.
2. T2: naikkan aturan "grup tanpa sender_jid -> 400, tidak disimpan" dari
   tabel Section 4.1 menjadi REQ bernomor (mis. REQ-010) plus AC eksplisit.
3. T3: selaraskan REQ-008/AC-002 menjadi "identitas pengirim (nomor telepon
   atau LID)", bukan "nama pengirim"; catat bahwa PRD GH-013 dan Section 4
   perlu redaksi yang sama, dan tanyakan ke pemilik apakah PRD ikut diedit
   di sesi ini atau dicatat sebagai amandemen terpisah.
4. T4: nyatakan constraint urutan rilis secara eksplisit — AuliaPos hanya boleh
   dirilis setelah perubahan Gateway terpasang; pesan grup dari Gateway lama
   ditolak 400 dan TIDAK tersimpan (tidak ada queue, docs/CHAT.md §5/§18);
   kontraskan dengan group_name yang additive (GUD-002).
5. T5: tambahkan aturan label untuk outgoing grup yang tersinkron dari WA
   Web/HP (sent_by_user_id NULL), mengikuti label docs/CHAT.md §7.
6. T6: tentukan penulisan group_name juga pada jalur conversation created=true;
   ganti empty($conversation['group_name']) menjadi === null pada contoh §8;
   catat bahwa dua penulisan pertama yang bersamaan berakhir last-write-wins
   (diterima).
7. T7: catat pencarian berdasarkan group_name sebagai out of scope / backlog.

BATAS:
- Jangan menulis kode. Output hanya amandemen dua file spec di atas.
- Tidak ada ADR baru (gagal Triple Gate) dan CONTEXT.md tidak diubah.
- Jangan menyentuh spec Tahap 3 (balas pesan) atau Tahap 4 (teruskan).
```

## 2. Ringkasan Keputusan yang Harus Masuk Spec

- SEC-01: `CON-004`/`AC-006`/`Section 9` Tahap 1 diperluas ke `handoffPercakapan()` dan `tandaiDibaca()`.
- T3: label pengirim per pesan = nomor telepon / `LID` (Opsi A).
- T1/T2/T4/T5/T6/T7: koreksi penulisan dan edge case pada Tahap 2.

## 3. Setelah Sesi Ini

1. Jalankan `/sdlc-audit-consistency` — spec Tahap 1 **dan** PRD ikut berubah, jadi keterlacakan wajib dicek ulang.
2. Baru kemudian `/sdlc-plan-tasks`, tetap **dua plan terpisah** (AuliaPos dan WA-Gateway) sesuai `spec-index.md`.
3. Implementasi belum boleh dimulai; SEC-01 tidak boleh ditambal di kode sebelum spec diamandemen.
