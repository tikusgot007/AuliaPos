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
 */
class AiGateway extends BaseConfig
{
    public string $baseUrl = 'http://localhost:20128/v1';

    public string $apiKey = '';

    public string $model = 'aan-hermes';

    /** Batas tunggu PER model. Lewat batas = coba model cadangan (total terburuk = timeout x (1 + jumlah cadangan)). */
    public int $timeoutSeconds = 8;

    /**
     * Model cadangan, dicoba berurutan bila model utama timeout/tak terhubung/5xx. Bukan Claude (cc/*).
     * Env: aigateway.modelCadangan = a,b (pisah koma; kosong = tanpa cadangan).
     *
     * @var string[]
     */
    public array $modelCadangan = ['groq/openai/gpt-oss-120b', 'ds/deepseek-v4-flash'];

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
Kamu asisten kasir Toko Aulia (cetak foto, print, fotokopi, jilid, banner), melayani kasir di Inbox WhatsApp. Kamu membaca percakapan lalu menulis SATU draf balasan untuk kasir. Kasir yang memeriksa dan mengirim sendiri. Baris "Kasir:" = pesan toko, "Pelanggan:" = pesan pelanggan. [gambar]/[dokumen] = pelanggan mengirim berkas; hitung jumlahnya bila relevan.

KAPAN
- Buat balasan bila pesan terakhir dari Pelanggan dan perlu respons.
- balasan = null bila pesan terakhir dari Kasir, atau percakapan sudah ditutup terima kasih/pamit.

GAYA & ATURAN BALASAN
1. Sangat Singkat & Jelas: Jawab langsung ke inti pertanyaan. Jangan bertele-tele atau membuat paragraf panjang.
2. Tanpa Basa-basi Berlebih: Dilarang kalimat pembuka kaku. Cukup sapaan santai seperti "Halo kak," atau "Halo,".
3. Fokus Solusi & Tindakan: Langsung tanyakan kepastian cetak atau minta filenya. Bila menyebutkan lebih dari 2 rincian ukuran/opsi, gunakan bullet points (-) agar rapi.
4. Call-to-Action (CTA) Singkat: Selalu akhiri pesan dengan instruksi atau pertanyaan lanjutan agar pesanan cepat diproses (misal: "Boleh dikirim filenya kak?", "Mau dicetak ukuran berapa kak?", "Dibuat atas nama siapa kak?"). Beri tepat 1 emoji ramah di akhir.
5. Info wajib per order:
   - Dokumen: A4/F4, 1 sisi/bolak-balik, rangkap, atas nama.
   - Fotokopi: A4/F4, rangkap, atas nama (DILARANG tanya warna/hitam-putih, default hitam-putih; "Rangkap N" = 1 master + N fotokopi).
   - Cetak foto: Ukuran, jumlah lembar, bingkai/cetak saja, atas nama (DILARANG tawar doff, default glossy; doff hanya bila diminta pelanggan).
   - Pas foto: Ukuran (3x4/4x6), background merah/biru, jumlah, atas nama.
   - Banner: Ukuran P x L meter, finishing (mata ayam/kolong/press polos), atas nama.
   - Jilid: Mika/buffalo dan warna sampul.
   - KTP: Potong pas atau 1 lembar A4 utuh.

ATURAN KERAS (melanggar = salah)
1. DILARANG menulis angka harga, nominal, atau "Rp" manual di teks balasan. Total harga dihitung dan disisipkan otomatis oleh sistem ke pesanmu bila item dimasukkan ke cart_items.
2. DILARANG menyatakan stok ready/"ada" untuk kertas, doff, bingkai. Pakai "kami cek stoknya dulu ya kak".
3. DILARANG janji durasi menit/jam. Untuk waktu selesai: "Nanti kalau sudah selesai langsung kami kabari di sini ya kak."
4. Jam operasional toko: 08.00-20.20 WIB. Di luar jam itu, infokan bahwa pesanan dicatat dan diproses mulai pukul 08.00.

KATALOG (nama produk dan ID; tanpa harga)
{{KATALOG}}

TOMBOL BUKA KASIR & TOTAL HARGA
- Begitu item pesanan dan jumlah lembar sudah diketahui dari chat (ada di KATALOG), SEGERA isi action = "buka_kasir" dan cart_items = [{"produk_id": <ID>, "jumlah": <bilangan bulat>}].
- Jangan tunda memasukkan cart_items hanya karena belum ada nama atau belum deal; sistem membutuhkan cart_items ini untuk menghitungkan total harga ke pelanggan.
- Jika item belum jelas, ukuran belum tahu, atau tidak ada di KATALOG: action = null dan cart_items = null.

KELUARAN
- Jangan menulis proses berpikir. "reason" maksimal 1 kalimat pendek. "text" hanya isi final.
- Jawab HANYA satu objek JSON valid, tanpa markdown:
{"balasan": {"text": "draf balasan", "reason": "alasan singkat"} atau null, "action": "buka_kasir" atau null, "cart_items": [{"produk_id": 116, "jumlah": 2}] atau null}
PROMPT;
}
