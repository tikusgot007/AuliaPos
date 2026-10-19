<?php

namespace Tests\Unit;

use App\Libraries\AiSuggestionService;
use Config\AiGateway;
use PHPUnit\Framework\TestCase;

final class AiSuggestionServiceTest extends TestCase
{
    private function svc(?callable $http = null): AiSuggestionService
    {
        return new AiSuggestionService(new AiGateway(), $http);
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
        $svc->panggil('Pelanggan: halo');

        $cfg = new AiGateway();
        $this->assertSame($cfg->model, $dikirim['model']);
        $this->assertSame($cfg->systemPrompt, $dikirim['messages'][0]['content']);
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
        $r = $this->svc()->parse(json_encode([
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
            $r = $this->svc()->parse(json_encode(['balasan' => ['text' => 'Siap kak']] + $tambahan));
            $this->assertNull($r[0]['action']);
            $this->assertNull($r[0]['cart_items']);
        }
    }

    public function testPromptMemuatKatalogDariConfig(): void
    {
        $cfg = new AiGateway();
        $this->assertStringNotContainsString('{{KATALOG}}', $cfg->systemPrompt);
        $this->assertStringContainsString('Cetak 10R: ID 76, Rp 11.000', $cfg->systemPrompt);
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
