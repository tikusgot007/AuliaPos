<?php

namespace App\Libraries;

use App\Models\MessageModel;
use Config\AiGateway;

/**
 * Saran AI on-demand: kumpulkan pesan -> panggil 9router -> validasi JSON.
 * Tidak menulis DB; penyimpanan dilakukan Inbox::generateSaran().
 * Desain: docs/design/2026-10-18-ai-saran-on-demand.md
 *
 * Output AI TIDAK DIPERCAYA: hanya field yang dikenal yang lolos, dengan
 * batas panjang yang sama seperti MaraController.
 */
class AiSuggestionService
{
    public const TEXT_MAX = 4096;
    public const ITEM_MAX = 500;
    public const ITEMS_MAX = 50;
    private const CART_MAX = 20;
    private const QTY_MAX  = 999;

    private const PRIORITAS = ['normal', 'tinggi', 'darurat'];

    private AiGateway $config;

    /** @var callable|null fn(array $payload): string  -- isi balasan HTTP; diganti di test */
    private $http;

    private ProdukPencari $produk;

    public function __construct(?AiGateway $config = null, ?callable $http = null, ?ProdukPencari $produk = null)
    {
        $this->config = $config ?? config(AiGateway::class);
        $this->http   = $http;
        $this->produk = $produk ?? new ProdukPencari();
    }

    /**
     * Pesan hari ini (WIB); bila kosong, N pesan terakhir. Catatan internal
     * tidak ikut. Urut lama -> baru.
     *
     * @return array<int, array{direction:string, message_type:string, text:?string}>
     */
    public function kumpulkanPesan(int $conversationId, ?string $nowWib = null): array
    {
        $awalHari = substr($nowWib ?? \App\Models\MaraSuggestionModel::nowWib(), 0, 10) . ' 00:00:00';

        $dasar = static function () use ($conversationId): MessageModel {
            return (new MessageModel())
                ->select('direction, message_type, text')
                ->where('conversation_id', $conversationId)
                ->where('is_internal', 0);
        };

        // ponytail: dibatasi maxPesan terbaru; naikkan bila chat sehari bisa lebih panjang.
        $rows = $dasar()->where('message_timestamp >=', $awalHari)
            ->orderBy('message_timestamp', 'DESC')->orderBy('id', 'DESC')
            ->findAll($this->config->maxPesan);

        if ($rows === []) {
            $rows = $dasar()
                ->orderBy('message_timestamp', 'DESC')->orderBy('id', 'DESC')
                ->findAll($this->config->fallbackLastN);
        }

        return array_reverse($rows);
    }

    /** Teks percakapan untuk prompt. Tanpa nomor/nama kontak. */
    public function formatPesan(array $pesan): string
    {
        $baris = [];
        foreach ($pesan as $p) {
            $siapa = ($p['direction'] ?? '') === 'outgoing' ? 'Kasir' : 'Pelanggan';
            $teks  = trim((string) ($p['text'] ?? ''));
            $tipe  = (string) ($p['message_type'] ?? 'text');

            if ($tipe === 'image') {
                $teks = '[gambar]' . ($teks !== '' ? ' ' . $teks : '');
            } elseif ($tipe !== 'text') {
                $teks = '[' . ($tipe === 'document' ? 'dokumen' : $tipe) . ']' . ($teks !== '' ? ' ' . $teks : '');
            }
            if ($teks === '') {
                continue;
            }
            $baris[] = $siapa . ': ' . $teks;
        }

        return implode("\n", $baris);
    }

    /**
     * @return array<int, array<string, mixed>> daftar saran tervalidasi (bisa kosong)
     * @throws \RuntimeException pesan aman ditampilkan ke kasir
     */
    public function minta(int $conversationId): array
    {
        $pesan = $this->kumpulkanPesan($conversationId);
        $teks  = $this->formatPesan($pesan);
        if ($teks === '') {
            throw new \RuntimeException('Belum ada pesan untuk dianalisa.');
        }

        // Tahap 1: kata cari -> baris produk dari tabel `produk` (desain §10).
        $katalog = $this->bangunKatalog($this->tentukanKataCari($teks));

        return $this->parse($this->panggil($teks, $katalog));
    }

    /**
     * Tahap 1: AI menebak kata cari produk dari percakapan. Gagal/kosong/rusak
     * apa pun -> [] (tahap 2 tetap jalan dengan katalog dasar saja).
     *
     * @return string[]
     */
    public function tentukanKataCari(string $percakapan): array
    {
        try {
            $raw = $this->kirim([
                'model'       => $this->config->model,
                'max_tokens'  => $this->config->maxTokensCari,
                'temperature' => 0,
                'stream'      => false,
                'messages'    => [
                    ['role' => 'system', 'content' => $this->config->promptCari],
                    ['role' => 'user', 'content' => "Percakapan:\n" . $percakapan],
                ],
            ], $this->config->timeoutCariSeconds, false);

            $json = json_decode($this->jsonPenuh(trim($raw)), true);
            $isi  = $json['choices'][0]['message']['content'] ?? '';
            $data = json_decode($this->jsonPenuh((string) $isi), true);

            return ProdukPencari::bersihkanKata($data['cari'] ?? []);
        } catch (\Throwable $e) {
            log_message('warning', 'AiSuggestionService: tahap kata cari dilewati (' . $e->getMessage() . ')');

            return [];
        }
    }

    /** Katalog prompt tahap 2 = produk dasar + hasil cari dari tabel `produk`. Hanya nama + ID, TANPA harga. */
    public function bangunKatalog(array $kata): string
    {
        $baris = [];
        foreach (array_merge($this->produk->byIds($this->config->produkDasar), $this->produk->cari($kata)) as $p) {
            $baris[$p['id']] = '- ' . $p['nama'] . ': ID ' . $p['id']; // tanpa harga: harga/total dihitung server (desain §11)
        }

        return implode("\n", $baris);
    }

    /** Panggil 9router (OpenAI-compatible), kembalikan teks jawaban model. */
    public function panggil(string $percakapan, string $katalog = ''): string
    {
        $payload = [
            'model'       => $this->config->model,
            'max_tokens'  => $this->config->maxTokens,
            'temperature' => 0.2,
            'stream'      => false,
            'messages'    => [
                ['role' => 'system', 'content' => str_replace('{{KATALOG}}', $katalog, $this->config->systemPrompt)],
                ['role' => 'user', 'content' => 'Sekarang: ' . (new \DateTime('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i') . " WIB\nPercakapan:\n" . $percakapan],
            ],
        ];

        $raw = $this->kirim($payload, $this->config->timeoutSeconds, true);

        $json = json_decode($this->jsonPenuh(trim($raw)), true);
        $isi  = $json['choices'][0]['message']['content'] ?? null;
        if (!is_string($isi) || trim($isi) === '') {
            throw new \RuntimeException('AI tidak memberi jawaban. Coba lagi.');
        }

        return $isi;
    }

    private function kirim(array $payload, int $timeout, bool $ulang429): string
    {
        return $this->http !== null ? ($this->http)($payload) : $this->curl($payload, $timeout, $ulang429);
    }

    /** Satu kali ulang bila 429 (batas laju sesaat); error lain langsung dilempar. */
    private function curl(array $payload, int $timeout, bool $ulang429): string
    {
        try {
            return $this->curlSekali($payload, $timeout);
        } catch (\RuntimeException $e) {
            if (!$ulang429 || $e->getCode() !== 429) {
                throw $e;
            }
            sleep($this->config->jedaUlangDetik);

            return $this->curlSekali($payload, $timeout);
        }
    }

    private function curlSekali(array $payload, int $timeout): string
    {
        $ch = curl_init(rtrim($this->config->baseUrl, '/') . '/chat/completions');
        $header = ['Content-Type: application/json'];
        if ($this->config->apiKey !== '') {
            $header[] = 'Authorization: Bearer ' . $this->config->apiKey;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $header,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $res    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno  = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || !is_string($res)) {
            log_message('error', 'AiSuggestionService: curl errno=' . $errno);
            throw new \RuntimeException($errno === CURLE_OPERATION_TIMEDOUT
                ? 'AI terlalu lama menjawab. Coba lagi.'
                : 'AI tidak bisa dihubungi. Coba lagi nanti.');
        }
        if ($status < 200 || $status >= 300) {
            // Isi balasan tidak dilog: bisa memuat potongan percakapan.
            log_message('error', 'AiSuggestionService: HTTP ' . $status);
            throw new \RuntimeException($status === 429
                ? 'AI sedang dibatasi (terlalu banyak permintaan). Coba lagi sebentar.'
                : 'AI menolak permintaan (HTTP ' . $status . ').', $status);
        }

        return $res;
    }

    private function jsonPenuh(string $raw): string
    {
        // Ambil dari '{' pertama sampai '}' terakhir (menyingkirkan ekor [DONE]).
        $a = strpos($raw, '{');
        $b = strrpos($raw, '}');

        return ($a === false || $b === false || $b < $a) ? $raw : substr($raw, $a, $b - $a + 1);
    }

    /**
     * Validasi jawaban model -> baris siap simpan (tanpa conversation_id/waktu).
     *
     * @return array<int, array<string, mixed>>
     * @throws \RuntimeException
     */
    public function parse(string $isi): array
    {
        $data = json_decode($isi, true);
        if (!is_array($data)) {
            // Satu percobaan ekstraksi: model kadang membungkus dengan ```json.
            $data = json_decode($this->jsonPenuh($isi), true);
        }
        if (!is_array($data)) {
            throw new \RuntimeException('Jawaban AI tidak bisa dibaca. Coba lagi.');
        }

        $hasil = [];

        $b = $data['balasan'] ?? null;
        if (is_array($b) && ($t = $this->teks($b['text'] ?? null)) !== null) {
            $t = $this->buangHarga($t);
            if ($t === null) {
                return [];
            }
            $baris = ['type' => 'balasan', 'priority' => 'normal', 'text' => $t,
                'items' => null, 'reason' => $this->teks($b['reason'] ?? null, 500),
                'action' => null, 'cart_items' => null];

            // Tombol "Buat Pesanan": hanya bila AI minta buka_kasir DAN ada baris keranjang sah.
            $cart = $this->keranjang($data['cart_items'] ?? null);
            if (($data['action'] ?? null) === 'buka_kasir' && $cart !== []) {
                $baris['action']     = 'buka_kasir';
                $baris['cart_items'] = $cart;
                $baris['text']       = $this->sisipTotal($t, $this->total($cart));
            }
            $hasil[] = $baris;
        }

        return $hasil;
    }

    /**
     * Keranjang dari AI -> hanya {produk_id, jumlah} dengan id sah di tabel `produk`.
     * Baris tak sah dibuang diam-diam (kasir tetap melihat balasan); id kembar digabung.
     *
     * @return array<int, array{produk_id: int, jumlah: int}>
     */
    private function keranjang($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $gabung = [];
        foreach ($raw as $row) {
            $id  = is_array($row) ? ($row['produk_id'] ?? null) : null;
            $qty = is_array($row) ? ($row['jumlah'] ?? null) : null;
            if (!is_int($id) || $id < 1 || !is_int($qty) || $qty < 1 || $qty > self::QTY_MAX) {
                continue;
            }
            $gabung[$id] = min(self::QTY_MAX, ($gabung[$id] ?? 0) + $qty);
        }
        // ID harus produk aktif, tidak terkunci, berharga di tabel `produk` (bukan karangan AI).
        $sah    = array_flip($this->produk->valid(array_keys($gabung)));
        $gabung = array_intersect_key($gabung, $sah);
        $hasil = [];
        foreach (array_slice($gabung, 0, self::CART_MAX, true) as $id => $qty) {
            $hasil[] = ['produk_id' => $id, 'jumlah' => $qty];
        }

        return $hasil;
    }

    /** Total keranjang dari harga master `produk` (bukan angka AI). */
    private function total(array $cart): int
    {
        $harga = array_column($this->produk->byIds(array_column($cart, 'produk_id')), 'harga', 'id');
        $total = 0;
        foreach ($cart as $c) {
            $total += ($harga[$c['produk_id']] ?? 0) * $c['jumlah'];
        }

        return $total;
    }

    /**
     * AI dilarang menulis harga (desain §11). Pengaman: kalimat yang memuat angka
     * harga dibuang. Semua kalimat terbuang -> null (tidak ada saran).
     */
    private function buangHarga(string $t): ?string
    {
        $kalimat = preg_split('/(?<=[.!?])\s+/u', $t) ?: [$t];
        $sisa    = array_filter($kalimat, static fn (string $k) => !preg_match('/\bRp\.?\s*\d|\d\s*(rb|ribu|k)\b/iu', $k));

        return $this->teks(implode(' ', $sisa));
    }

    /** "Total Rp 60.000." disisipkan sebelum emoji penutup bila ada. */
    private function sisipTotal(string $t, int $total): string
    {
        $kalimat = 'Total Rp ' . number_format($total, 0, ',', '.') . '.';
        if (preg_match('/^(.*?)(\s*[^\p{L}\p{N}\s.,!?]+)$/su', $t, $m)) {
            return mb_substr(rtrim($m[1]) . ' ' . $kalimat . $m[2], 0, self::TEXT_MAX);
        }

        return mb_substr(rtrim($t) . ' ' . $kalimat, 0, self::TEXT_MAX);
    }

    private function teks($v, int $max = self::TEXT_MAX): ?string
    {
        if (!is_string($v) || trim($v) === '') {
            return null;
        }

        return mb_substr(trim($v), 0, $max);
    }
}
