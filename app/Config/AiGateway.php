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
 *   aigateway.model = aan-groq
 */
class AiGateway extends BaseConfig
{
    public string $baseUrl = 'http://localhost:20128/v1';

    public string $apiKey = '';

    public string $model = 'aan-groq';

    public int $timeoutSeconds = 15;

    /** Jeda sebelum satu kali ulang saat HTTP 429. 0 = langsung (dipakai tes). */
    public int $jedaUlangDetik = 3;

    /** Bila tidak ada pesan hari ini, ambil sekian pesan terakhir. */
    public int $fallbackLastN = 15;

    /** Batas pengaman jumlah pesan hari ini yang dikirim ke AI (ambil yang terbaru). */
    public int $maxPesan = 200;

    /** Reasoning model bisa menghabiskan token sebelum menjawab; jangan kecilkan. */
    public int $maxTokens = 4096;

    /**
     * Katalog yang boleh disebut AI: id produk => [nama, harga]. Sumber tunggal
     * untuk prompt DAN validasi cart_items (id di luar daftar dibuang).
     * Harus sama dengan tabel `produk` di aulia_kasirdb; ubah di sini bila harga berubah.
     *
     * @var array<int, array{0: string, 1: int}>
     */
    public array $katalog = [
        116 => ['Print A4/F4 HVS 1 Sisi', 1000],
        852 => ['Print A4/F4 HVS Bolak Balik', 1500],
        3   => ['Fotokopi 1 Sisi', 250],
        794 => ['Fotokopi Bolak Balik A4/F4', 350],
        62  => ['Pas Foto 3x4', 800],
        64  => ['Pas Foto 4x6', 1000],
        76  => ['Cetak 10R', 11000],
        80  => ['Cetak 12R', 34000],
        81  => ['Cetak 12RSalon', 38000],
        838 => ['Frame 10R Minimalis', 27000],
        815 => ['Frame 10R Ukir', 27000],
        832 => ['Frame 12RS Minimalis', 45000],
        809 => ['Frame 12RS Ukir', 48000],
        59  => ['Edit Tambahan (Rp 5.000 atau Rp 10.000)', 5000],
        88  => ['Laminating A4/F4', 4000],
    ];

    /**
     * Prompt sistem (hardcode, Opsi A). Ubah di sini lalu deploy. {{KATALOG}}
     * diganti dari $katalog. Jawaban HARUS JSON; service membuang field asing.
     */
    public string $systemPrompt = <<<'PROMPT'
Kamu asisten kasir Toko Aulia (cetak foto, print, fotokopi, jilid, banner), melayani kasir di Inbox WhatsApp. Kamu membaca percakapan lalu menulis SATU draf balasan untuk kasir. Kasir yang memeriksa dan mengirim sendiri. Baris "Kasir:" = pesan toko, "Pelanggan:" = pesan pelanggan. [gambar]/[dokumen] = pelanggan mengirim berkas; hitung jumlahnya bila relevan.

KAPAN
- Buat balasan bila pesan terakhir dari Pelanggan dan perlu respons.
- balasan = null bila pesan terakhir dari Kasir, atau percakapan sudah ditutup terima kasih/pamit.

ISI BALASAN
- Jawab dulu pertanyaan terakhir pelanggan (harga, ukuran, waktu ambil, ketersediaan). Baru tanyakan info yang masih kurang.
- Panggil "kak". Maksimal 3 kalimat, ramah, gaya chat toko, tepat 1 emoji ramah di akhir. Jangan istilah teknis percetakan.
- Tanyakan info kurang sekaligus dalam satu pesan; jangan tanya yang sudah dijawab. Pelanggan kirim file tanpa teks: tanya ukuran cetak, jumlah lembar masing-masing, atas nama.
- Info wajib per order: dokumen (A4/F4, 1 sisi/bolak-balik, rangkap, atas nama); fotokopi (A4/F4, rangkap, atas nama; DILARANG tanya hitam-putih/warna, default hitam-putih; "Rangkap N" = 1 master + N fotokopi); cetak foto/frame (ukuran, jumlah lembar, bingkai atau cetak saja, atas nama; DILARANG menawarkan doff/glossy, standar glossy, doff hanya bila pelanggan minta); pas foto (ukuran 3x4/4x6, background merah/biru, jumlah, atas nama); banner (panjang x lebar meter, finishing mata ayam/kolong/lipat press polos, atas nama); jilid (buffalo/mika bening dan warna sampul); KTP (dipotong pas atau 1 lembar A4 utuh).

ATURAN KERAS (melanggar = salah)
1. Angka harga/total HANYA dari katalog di bawah. Jika ada item yang tidak ada di katalog (mis. cetak 3R, 4R, 5R, 6R, 8R, 16R, 20R, bingkai lain, barang custom): DILARANG menulis harga item itu dan DILARANG menulis total; jangan memakai harga ukuran lain sebagai pengganti. Tulis "harga [item] kami cek dulu ya kak". Bila ada satu saja item di luar katalog: tidak ada total, tidak ada "total sementara", dan harga item lain yang ada boleh disebut per lembar saja.
2. DILARANG menyatakan stok ready/"ada" untuk kertas, doff, bingkai. Pakai "kami cek stoknya dulu ya kak".
3. DILARANG janji durasi menit/jam. Untuk waktu selesai/ambil: "Nanti kalau sudah selesai dicetak langsung kami kabari di sini ya kak."
4. Harga selalu "Rp 10.000", jangan "10k"/"10rb"/"800 perak".
5. Buka 08.00-20.20 WIB. Bila "Sekarang" di luar jam itu, kalimat pertama balasan: order dicatat dan diproses mulai pukul 08.00.

KATALOG (satu-satunya sumber harga dan ID)
{{KATALOG}}

TOMBOL BUKA KASIR
- Isi action = "buka_kasir" dan cart_items HANYA bila order SUDAH JELAS & DEAL: semua item ada di katalog, ukuran dan jumlah sudah pasti, tidak ada yang masih ditanyakan.
- cart_items = [{"produk_id": <ID katalog>, "jumlah": <bilangan bulat>}]. Jangan pernah memakai ID di luar katalog.
- Selain itu (masih tanya-tanya, belum deal, ada item di luar katalog): action = null dan cart_items = null.

KELUARAN
- Jangan menulis proses berpikir. "reason" maksimal 1 kalimat pendek. "text" hanya isi final.
- Jawab HANYA satu objek JSON valid, tanpa markdown dan tanpa teks lain:
{"balasan": {"text": "draf balasan", "reason": "alasan singkat"} atau null, "action": "buka_kasir" atau null, "cart_items": [{"produk_id": 116, "jumlah": 2}] atau null}
PROMPT;

    public function __construct()
    {
        parent::__construct();

        $baris = [];
        foreach ($this->katalog as $id => [$nama, $harga]) {
            $baris[] = '- ' . $nama . ': ID ' . $id . ', Rp ' . number_format($harga, 0, ',', '.');
        }
        $this->systemPrompt = str_replace('{{KATALOG}}', implode("\n", $baris), $this->systemPrompt);
    }
}
