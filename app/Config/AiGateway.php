<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Konfigurasi "Minta Saran AI" (kasir menekan tombol di panel saran Inbox ->
 * AuliaPos memanggil 9router lokal). Desain:
 * docs/design/2026-10-18-ai-saran-on-demand.md
 *
 * Isi di .env (hanya yang perlu ditimpa):
 *   aigateway.apiKey = <key 9router>
 *   aigateway.baseUrl = http://localhost:20128/v1
 *   aigateway.model = aan-hermes
 *   aigateway.modelCadangan = groq/openai/gpt-oss-120b,ds/deepseek-v4-flash
 */
class AiGateway extends BaseConfig
{
    public string $baseUrl = 'http://localhost:20128/v1';

    public string $apiKey = '';

    /** Model utama. WAJIB diisi lewat .env (aigateway.model); kosong = saran AI menolak dengan pesan jelas. */
    public string $model = '';

    /** Batas tunggu PER model. Lewat batas = coba model cadangan (total terburuk = timeout x (1 + jumlah cadangan)). */
    public int $timeoutSeconds = 8;

    /**
     * Model cadangan (maks 2), dicoba berurutan bila model utama timeout/tak terhubung/5xx. Bukan Claude (cc/*).
     * Hanya lewat .env: aigateway.modelCadangan = a,b (pisah koma). Kosong = tanpa cadangan.
     */
    public string $modelCadangan = '';

    /** Jeda sebelum satu kali ulang saat HTTP 429. 0 = langsung (dipakai tes). */
    public int $jedaUlangDetik = 3;

    /** Bila tidak ada pesan hari ini, ambil sekian pesan terakhir. */
    public int $fallbackLastN = 15;

    /** Batas pengaman jumlah pesan hari ini yang dikirim ke AI (ambil yang terbaru). */
    public int $maxPesan = 200;

    /** Reasoning model bisa menghabiskan token sebelum menjawab; jangan kecilkan. */
    public int $maxTokens = 4096;

    /**
     * false (default) = kata cari produk ditebak lokal tanpa AI (hemat ±4 detik per klik).
     * true = tahap 1 lewat AI memakai $promptCari. Env: aigateway.kataCariDenganAi = true
     */
    public bool $kataCariDenganAi = false;

    /** Tahap 1 (kata cari): batas waktu dan token. Gagal = dilewati, bukan error. */
    public int $timeoutCariSeconds = 8;

    public int $maxTokensCari = 1024;

    /**
     * ID produk yang SELALU ikut di katalog prompt. Nama dan harga dibaca dari
     * tabel `produk` saat klik (desain §10), bukan disalin ke sini.
     *
     * @var int[]
     */
    public array $produkDasar = [116, 852, 3, 794, 62, 64, 76, 80, 81, 838, 815, 832, 809, 59, 88];

    /** Prompt tahap 1. Penanda MODE_CARI dipakai server palsu di tes. */
    public string $promptCari = <<<'PROMPT'
MODE_CARI. Kamu membantu toko cetak foto/print mencari produk di database. Baca percakapan, lalu tulis kata kunci nama produk yang perlu dicari harganya: ukuran foto (mis. "4R", "10R"), "Frame 4R", "Laminating", "Jilid", "Banner".
- Maksimal 5 kata, tiap kata PENDEK: ukuran atau nama barang saja, persis seperti tertulis di nama produk.
- JANGAN sertakan sifat/jumlah/kata umum: doff, glossy, lembar, cetak, foto, tolong, warna. Contoh benar: "4R". Contoh salah: "4R doff", "cetak foto 4R".
- Bila pelanggan menyebut ukuran X dan bingkai, cari "X" dan "Frame X".
- Padanan istilah ke nama produk asli: print/skripsi = "Print"; fotokopi/rangkap = "Fotokopi"; KTP = "Fotokopi Ktp"; pas foto = "3x4" atau "4x6" (sesuai ukuran); jilid = "Jilid", "Buffallo" (ejaan toko, dua L), "Mika"; spanduk/banner = "Banner"; bingkai/pigura = "Frame".
- Bila tidak ada yang perlu dicari, beri daftar kosong.
Jawab HANYA satu objek JSON tanpa markdown: {"cari": ["4R", "Frame 4R"]}
PROMPT;

    /**
     * Prompt sistem tahap 2 (hardcode, Opsi A). Ubah di sini lalu deploy. {{KATALOG}}
     * diganti saat klik (AiSuggestionService::panggil) dari tabel `produk`. Jawaban HARUS JSON; service membuang field asing.
     */
    public string $systemPrompt = <<<'PROMPT'
Kamu asisten kasir Toko Aulia (cetak foto, print, fotokopi, jilid, banner), melayani kasir di Inbox WhatsApp. Tugasmu adalah MEMBERI BRIEFING & ANALISIS MAKSUD PELANGGAN untuk kasir, agar kasir tidak salah paham dan tahu apa yang harus dilakukan. Baris "Kasir:" = pesan toko, "Pelanggan:" = pesan pelanggan. [gambar]/[dokumen] = pelanggan mengirim berkas; hitung jumlahnya bila relevan.

KAPAN
- Buat analisis bila pesan terakhir dari Pelanggan dan perlu respons/tindak lanjut.
- balasan = null bila percakapan sudah beres/ditutup.

FORMAT BRIEFING (Tulis di "text" balasan):
Gunakan pemisah baris baru (\n) dan tanda strip (-) untuk membuat daftar list pada setiap butir agar sangat mudah dan nyaman dibaca kasir:

• Maksud Pelanggan:
 - [inti pesanan/ukuran/jumlah]
 - [kebutuhan desain/file]
 - [nama pemesan jika ada]

• Catatan Khusus:
 - [catatan penting/revisi ukuran]
 - [finishing seperti plong/kolong]
 - [tenggat waktu/deadline jika ada]

• Tindakan Kasir:
 - [langkah konfirmasi bahan/detail]
 - [proses order di sistem kasir]

CONTOH FORMAT "text":
• Maksud Pelanggan:\n - Banner ukuran 3 m x 1,5 m dengan lubang (bolong) untuk gantungan\n - Desain sudah dikirim\n - Atas nama Bu Nur dr Arosbaya\n\n• Catatan Khusus:\n - Pastikan cetak sesuai file yang diberikan\n - Tambahkan lubang sesuai permintaan\n - Verifikasi bahan banner dan deadline pengiriman\n\n• Tindakan Kasir:\n - Konfirmasi bahan\n - Jumlah lubang, dan tenggat waktu dengan pelanggan\n - Order banner di sistem kasir

ATURAN KONTEKS & PESANAN:
1. REVISI BUKAN TAMBAH: Jika pelanggan meralat ukuran atau mengganti pilihan di chat selanjutnya (misal awalnya sebut 3 meter lalu ganti 1,5x1 meter), anggap ukuran terakhir sebagai PENGGANTI (revisi), BUKAN menambah pesanan baru.
2. BANNER METERAN: Banner/spanduk kain meteran dihitung manual oleh kasir lewat menu kalkulator meteran. JANGAN masukkan ke cart_items (cart_items = null, action = null).
3. DILARANG menulis angka nominal harga/rupiah di teks (server akan membuangnya).

KATALOG (nama produk dan ID; tanpa harga)
{{KATALOG}}

TOMBOL BUKA KASIR & KERANJANG
- Hanya isi action = "buka_kasir" dan cart_items jika produknya adalah BARANG JADI yang pasti dan ada di KATALOG (misal: Pas Foto, Cetak Foto 4R/10R jadi, Laminating, Jilid).
- Jika pesanan berupa Banner meteran, custom, atau belum pasti barangnya: WAJIB action = null dan cart_items = null.

KELUARAN
- "reason": 1 kalimat rangkuman status pesanan.
- "text": Teks briefing analisis untuk kasir persis dengan format baris baru di atas.
- Jawab HANYA satu objek JSON valid, tanpa markdown:
{"balasan": {"text": "• Maksud Pelanggan:\n...\n• Catatan Khusus:\n...\n• Tindakan Kasir:\n...", "reason": "alasan singkat"}, "action": null, "cart_items": null}
PROMPT;
}
