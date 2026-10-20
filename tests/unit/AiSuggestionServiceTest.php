<?php

namespace Tests\Unit;

use App\Libraries\AiSuggestionService;
use App\Libraries\ProdukPencari;
use Config\AiGateway;
use PHPUnit\Framework\TestCase;

final class AiSuggestionServiceTest extends TestCase
{
    /** ProdukPencari palsu: tanpa DB. $valid = id yang dianggap sah; $baris = isi tabel. */
    private function pencari(array $baris = [], ?array $valid = null): ProdukPencari
    {
        return new class($baris, $valid) extends ProdukPencari {
            public function __construct(private array $baris, private ?array $valid)
            {
            }

            public function byIds(array $ids): array
            {
                return array_values(array_filter($this->baris, fn ($r) => in_array($r['id'], $ids, true)));
            }

            public function cari(array $kata): array
            {
                $kata = ProdukPencari::bersihkanKata($kata);

                return array_values(array_filter($this->baris, function ($r) use ($kata) {
                    foreach ($kata as $k) {
                        if (stripos($r['nama'], $k) !== false) {
                            return true;
                        }
                    }

                    return false;
                }));
            }

            public function valid(array $ids): array
            {
                return array_values(array_filter($ids, fn ($i) => $this->valid === null || in_array($i, $this->valid, true)));
            }
        };
    }

    private function svc(?callable $http = null, ?ProdukPencari $p = null): AiSuggestionService
    {
        return new AiSuggestionService(new AiGateway(), $http, $p ?? $this->pencari());
    }

    private function balasanAi(string $content): string
    {
        return json_encode(['choices' => [['message' => ['content' => $content]]]]);
    }

    public function testFormatPesanTanpaIdentitasDanTandaiMedia(): void
    {
        $out = $this->svc()->formatPesan([
            ['direction' => 'incoming', 'message_type' => 'text', 'text' => 'kak cetak ini'],
            ['direction' => 'incoming', 'message_type' => 'image', 'text' => null],
            ['direction' => 'outgoing', 'message_type' => 'text', 'text' => 'siap'],
            ['direction' => 'incoming', 'message_type' => 'text', 'text' => '  '],
        ]);
        $this->assertSame("Pelanggan: kak cetak ini\nPelanggan: [gambar]\nKasir: siap", $out);
    }

    public function testSystemPromptDariConfigDikirimKeGateway(): void
    {
        $dikirim = null;
        $svc = $this->svc(function (array $p) use (&$dikirim): string {
            $dikirim = $p;
            return $this->balasanAi('{"balasan":null}');
        });
        $svc->panggil('Pelanggan: halo', '- Cetak 10R: ID 76');

        $cfg = new AiGateway();
        $this->assertSame($cfg->model, $dikirim['model']);
        $this->assertSame(str_replace('{{KATALOG}}', '- Cetak 10R: ID 76', $cfg->systemPrompt), $dikirim['messages'][0]['content']);
        $this->assertGreaterThanOrEqual(4096, $dikirim['max_tokens']);
        $this->assertFalse($dikirim['stream']);
    }

    public function testPanggilTahanEkorDoneDanTanpaIsiMelempar(): void
    {
        $ok = $this->svc(fn () => $this->balasanAi('hai') . "\n\ndata: [DONE]");
        $this->assertSame('hai', $ok->panggil('x'));

        $this->expectException(\RuntimeException::class);
        $this->svc(fn () => '{"choices":[]}')->panggil('x');
    }

    public function testParseHanyaBalasanDanBuangFieldAsing(): void
    {
        $isi = json_encode([
            'balasan' => ['text' => 'Baik kak', 'reason' => 'ramah'],
            'daftar_periksa' => ['text' => 'Cek', 'items' => ['a']],
            'peringatan' => ['text' => 'Awas', 'priority' => 'darurat'],
            'lain' => 'x',
        ]);
        $r = $this->svc()->parse("```json\n$isi\n```");

        $this->assertCount(1, $r, 'checklist dan peringatan tidak diproses');
        $this->assertSame('balasan', $r[0]['type']);
        $this->assertNull($r[0]['action']);
        $this->assertNull($r[0]['cart_items']);
    }

    public function testKeranjangHanyaIdKatalogDanJumlahSah(): void
    {
        $r = $this->svc(null, $this->pencari([], [76, 116, 64]))->parse(json_encode([
            'balasan' => ['text' => 'Siap kak'],
            'action' => 'buka_kasir',
            'cart_items' => [
                ['produk_id' => 76, 'jumlah' => 2],
                ['produk_id' => 76, 'jumlah' => 1],      // digabung -> 3
                ['produk_id' => 999999, 'jumlah' => 1],  // id karangan -> dibuang
                ['produk_id' => '116', 'jumlah' => 1],   // bukan int -> dibuang
                ['produk_id' => 116, 'jumlah' => 0],     // di luar rentang -> dibuang
                ['produk_id' => 116, 'jumlah' => 1000],  // di luar rentang -> dibuang
                ['produk_id' => 64, 'jumlah' => 4, 'harga' => 1],
            ],
        ]));

        $this->assertSame('buka_kasir', $r[0]['action']);
        $this->assertSame([['produk_id' => 76, 'jumlah' => 3], ['produk_id' => 64, 'jumlah' => 4]], $r[0]['cart_items']);
    }

    public function testActionTanpaKeranjangSahDiabaikan(): void
    {
        foreach ([
            ['action' => 'buka_kasir', 'cart_items' => [['produk_id' => 999999, 'jumlah' => 1]]],
            ['action' => 'buka_kasir', 'cart_items' => null],
            ['action' => 'hapus_semua', 'cart_items' => [['produk_id' => 76, 'jumlah' => 1]]],
            ['action' => null, 'cart_items' => [['produk_id' => 76, 'jumlah' => 1]]],
        ] as $tambahan) {
            $r = $this->svc(null, $this->pencari([], [76]))->parse(json_encode(['balasan' => ['text' => 'Siap kak']] + $tambahan));
            $this->assertNull($r[0]['action']);
            $this->assertNull($r[0]['cart_items']);
        }
    }

    public function testBangunKatalogGabungDasarDanHasilCariTanpaDuplikat(): void
    {
        $tabel = [
            ['id' => 76, 'nama' => 'Cetak 10R', 'harga' => 11000],
            ['id' => 73, 'nama' => '4R', 'harga' => 2500],
            ['id' => 868, 'nama' => 'Frame 4R Minimalis', 'harga' => 16000],
        ];
        $cfg = new AiGateway();
        $cfg->produkDasar = [76];
        $svc = new AiSuggestionService($cfg, null, $this->pencari($tabel));

        $this->assertSame(
            "- Cetak 10R: ID 76\n- 4R: ID 73\n- Frame 4R Minimalis: ID 868",
            $svc->bangunKatalog(['4R', 'Cetak 10R'])
        );
        $this->assertSame('- Cetak 10R: ID 76', $svc->bangunKatalog([]));
    }

    public function testTimeoutPindahKeModelCadangan(): void
    {
        $dicoba = [];
        $svc = $this->svc(function (array $p) use (&$dicoba): string {
            $dicoba[] = $p['model'];
            if (count($dicoba) < 3) {
                throw new \RuntimeException('AI terlalu lama menjawab. Coba lagi.');
            }
            return $this->balasanAi('{"balasan":null}');
        });
        $svc->panggil('Pelanggan: halo');
        $cfg = new AiGateway();
        $this->assertSame(array_merge([$cfg->model], array_slice($cfg->modelCadangan, 0, 2)), $dicoba);
    }

    public function testError4xxTidakPindahModel(): void
    {
        $n = 0;
        $svc = $this->svc(function () use (&$n): string {
            $n++;
            throw new \RuntimeException('AI menolak permintaan (HTTP 401).', 401);
        });
        $this->expectException(\RuntimeException::class);
        try {
            $svc->panggil('Pelanggan: halo');
        } finally {
            $this->assertSame(1, $n);
        }
    }

    public function testSemuaModelGagalMelemparError(): void
    {
        $svc = $this->svc(function (): string {
            throw new \RuntimeException('AI terlalu lama menjawab. Coba lagi.');
        });
        $this->expectExceptionMessage('AI terlalu lama menjawab');
        $svc->panggil('Pelanggan: halo');
    }

    public function testKataCariLokal(): void
    {
        $s = $this->svc();
        $this->assertSame(['4R'], $s->kataCariLokal('Pelanggan: kak cetak foto 4R 10 lembar'));
        $this->assertSame(['10RS', '4R', 'Frame 10RS', 'Frame 4R'], $s->kataCariLokal('mau 10 RS dan 4r pakai bingkai'));
        $this->assertSame(['3x4', 'Print', 'Jilid', 'Buffallo', 'Mika'], $s->kataCariLokal('pas foto 3 x 4 sama jilid skripsi'));
        $this->assertSame(['Fotokopi Ktp', 'Fotokopi'], $s->kataCariLokal('fotokopi ktp 2'));
        $this->assertSame(['Frame'], $s->kataCariLokal('ada pigura?'));
        $this->assertSame([], $s->kataCariLokal('halo kak, buka jam berapa?'));
        $this->assertSame([], $s->kataCariLokal('nomor 24RB'), 'menempel di tengah kata tidak dihitung');
    }

    public function testTentukanKataCari(): void
    {
        $isi = fn (string $c) => fn () => $this->balasanAi($c);
        $this->assertSame(['4R', 'Frame 4R'], $this->svc($isi('{"cari":["4R","Frame 4R"]}'))->tentukanKataCari('x'));
        $this->assertSame(['4R'], $this->svc($isi("```json\n{\"cari\":[\"4R\"]}\n```"))->tentukanKataCari('x'));
        $this->assertSame([], $this->svc($isi('bukan json'))->tentukanKataCari('x'), 'rusak -> kosong');
        $this->assertSame([], $this->svc($isi('{"cari":"4R"}'))->tentukanKataCari('x'), 'bukan array -> kosong');
        $this->assertSame([], $this->svc(function () { throw new \RuntimeException('429'); })->tentukanKataCari('x'), 'error -> kosong, tidak melempar');
    }

    public function testBersihkanKataSanitasiDanBatas(): void
    {
        $this->assertSame(['4R', 'Frame'], ProdukPencari::bersihkanKata(['4R', '  Frame ', '4r', 'a', str_repeat('x', 41), 5, null, '%_']));
        $this->assertSame(['ab'], ProdukPencari::bersihkanKata(['a%b_']), 'wildcard LIKE dibuang');
        $this->assertCount(ProdukPencari::KATA_MAX, ProdukPencari::bersihkanKata(['aa', 'bb', 'cc', 'dd', 'ee', 'ff', 'gg']));
        $this->assertSame([], ProdukPencari::bersihkanKata('4R'));
    }

    public function testParseBatasPanjangDanNullKosong(): void
    {
        $r = $this->svc()->parse(json_encode([
            'balasan' => ['text' => str_repeat('x', 5000)],
            'peringatan' => null,
        ]));
        $this->assertCount(1, $r);
        $this->assertSame(AiSuggestionService::TEXT_MAX, mb_strlen($r[0]['text']));
    }

    public function testParseJsonRusakMelempar(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->svc()->parse('bukan json sama sekali');
    }
}
