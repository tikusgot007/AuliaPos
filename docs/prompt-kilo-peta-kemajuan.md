# Prompt untuk Kilo — Peta Kemajuan AuliaPos Inbox (halaman HTML statis)

Tempel prompt di bawah ini ke Kilo. Sertakan file `peta-kemajuan-inbox-seed.html` (dikirim terpisah) sebagai lampiran — itu adalah kondisi terakhir peta yang sudah dikerjakan Claude sampai commit tertentu, dipakai sebagai titik awal.

---

## PROMPT (mulai dari baris ini)

Kamu bertugas membuat dan merawat satu halaman HTML statis, `docs/peta-kemajuan-inbox.html`, di repo `AuliaPos` (working copy lokal ini). Halaman ini adalah "peta kemajuan" WhatsApp Inbox — status M1/M2/M3/M4/M5 plus fitur GH-011 (Grup/Balas/Teruskan), ditujukan untuk pembaca non-teknis (pemilik proyek).

### Langkah pertama kali (setup)

1. Cek apakah `docs/peta-kemajuan-inbox.html` sudah ada di repo.
   - **Kalau belum ada**: salin isi `peta-kemajuan-inbox-seed.html` (lampiran) apa adanya ke `docs/peta-kemajuan-inbox.html`. Itu sudah berisi seluruh histori sampai commit yang tertulis di header (`v2.3 @ <hash>`).
   - **Kalau sudah ada**: pakai file yang ada di repo sebagai dasar, JANGAN timpa dengan seed kalau isinya sudah lebih baru (bandingkan hash di header eyebrow).

### Setiap kali diminta "update peta kemajuan" (atau dijalankan otomatis)

1. Baca file `docs/peta-kemajuan-inbox.html` yang ada sekarang. Ambil hash commit di header (`v2.3 @ <hash>` untuk AuliaPos; kalau ada juga baris terpisah untuk WA-Gateway `master @ <hash>`, kalau repo itu ikut dipantau).
2. Jalankan `git log <hash-lama>..origin/v2.3 --oneline` (dan untuk repo WA-Gateway kalau relevan: `git log <hash-lama>..origin/master --oneline` di working copy repo itu).
3. **Kalau tidak ada commit baru**: laporkan itu, JANGAN ubah file apa pun.
4. **Kalau ada commit baru**: untuk SETIAP commit, baca isi lengkapnya (`git show <hash>`) — jangan menyimpulkan status hanya dari judul commit. Baca juga file yang disentuh kalau perlu konteks (plan/, spec/, docs/audit/, docs/decisions/, docs/TODO-CHAT.md, `.claude/instructions/memory.instructions.md`).
5. Update `docs/peta-kemajuan-inbox.html` secara **surgical** (edit bagian yang berubah saja, JANGAN tulis ulang dari nol), dengan struktur yang HARUS tetap sama seperti file yang ada:
   - Header: eyebrow `Status per <tanggal> · GitHub tikusgot007/AuliaPos · v2.3 @ <hash baru>` (dan baris WA-Gateway kalau ada), judul, dan legend 4 warna (done/partial/todo/gated).
   - Kotak "Berubah sejak versi `<hash lama>`": 2–5 poin ringkas, bahasa sederhana.
   - Rantai roadmap (Tahap 0 → M1 → M2 → M3 → M4 → M5) — JANGAN diubah kecuali milestone benar-benar pindah fase.
   - Satu lane per milestone aktif (M1, M2, M3), lane "Fix" untuk perbaikan lintas milestone, dan lane terpisah untuk fitur baru di luar rantai M1-M5 (misalnya `GH-011` Grup/Balas/Teruskan) — tambahkan lane baru kalau memang ada inisiatif baru yang tidak cocok masuk milestone manapun.
   - Tiap lane: verdict 1-2 kalimat, kartu step dengan kelas `step done` / `step partial` / `step todo` / `step gated`, tag singkat, tanggal, dan (kalau relevan) daftar bukti dalam `<ul>`.
   - Kotak "notes" per lane: `Bukti` dan `Risiko/Masih terbuka`.
   - Kotak "Langkah berikutnya" (`section class="next"`): SATU jalur utama yang paling mendesak, 1-3 langkah konkret. Kalau ada insiden produksi nyata (data pelanggan kena dampak, layanan mati), itu SELALU jadi prioritas nomor 1, di atas apa pun. Kalau tidak ada satu pun kartu kuning/merah tersisa, katakan itu terus terang dan sebut pilihan-pilihan yang tersisa sebagai keputusan pemilik, bukan tugas mendesak.
   - Footer: sebutkan sumber commit/file yang dipakai untuk klaim di halaman ini, dan tandai jelas kalau ada angka yang diambil dari laporan/commit message tapi TIDAK kamu jalankan ulang sendiri.
6. JANGAN ubah CSS, token warna, atau font kecuali ada yang benar-benar rusak saat dibuka di browser.

### Aturan isi (wajib dipatuhi)

- Semua teks dalam Bahasa Indonesia sederhana (pembaca bukan orang teknis). Nama file, commit hash, nama fungsi, dan command tetap apa adanya (jangan diterjemahkan).
- Tandai sebuah item "Selesai" (`step done`) HANYA kalau ada bukti konkret: plan berstatus `Completed`, code review dengan verdict Merge/tanpa temuan blocking, atau hasil test/pengukuran tertulis di laporan. Commit message yang bilang "done" tanpa bukti test/review TIDAK cukup — tandai `partial` dan jelaskan apa yang masih kurang.
- Bedakan tegas: "selesai di kode" vs "sudah di-review" vs "sudah live/di-deploy" vs "terbukti lewat pengukuran nyata". Jangan campur adukkan keempatnya jadi satu klaim "selesai".
- Kalau spec/plan/laporan sendiri secara eksplisit bilang sesuatu TIDAK boleh diklaim selesai (misalnya "jangan klaim paritas Android terverifikasi", atau "stub-only"), JANGAN klaim itu selesai di halaman ini walau kelihatannya sudah berjalan.
- Kalau ada temuan yang sengaja ditunda pemilik proyek ke tahap berikutnya, tapi ternyata TIDAK muncul di plan/kode tahap berikutnya itu — laporkan itu sebagai celah terbuka, jangan diam-diam anggap sudah tertangani.
- Satu insiden produksi (bug yang berdampak ke pelanggan nyata, bukan cuma ke kode) SELALU lebih prioritas untuk ditampilkan sebagai "Langkah berikutnya" dibanding pekerjaan pengembangan biasa, sampai insiden itu dinyatakan selesai lewat bukti (bukan cuma "sudah saya perbaiki" tanpa verifikasi).
- Jangan menambah cakupan pekerjaan baru ke kotak "Langkah berikutnya" kalau masih ada kartu kuning yang sudah berjalan (`step partial`) — utamakan menuntaskan yang sudah dimulai. Kecuali: insiden produksi (lihat poin di atas), atau permintaan fitur baru dari pemilik yang eksplisit disebutkan sebagai prioritas.

### Setelah selesai update

Tampilkan ringkasan singkat ke saya (maks 5 poin): apa yang berubah di file, dan satu langkah berikutnya. Beri tahu juga path file yang berubah supaya saya bisa buka di browser (`file:///.../docs/peta-kemajuan-inbox.html`) atau lihat di GitHub kalau sudah di-commit.

Jangan commit/push perubahan file ini secara otomatis kecuali saya minta secara eksplisit.

## AKHIR PROMPT
