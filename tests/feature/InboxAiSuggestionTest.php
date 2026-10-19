<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FakeGatewayServer;

/**
 * POST /inbox/api/conversations/(:num)/generate-saran ("Minta Saran AI").
 * Desain: docs/design/2026-10-18-ai-saran-on-demand.md. 9router diganti
 * server palsu lokal (php -S), jadi tidak ada panggilan ke AI sungguhan.
 *
 * @internal
 */
final class InboxAiSuggestionTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private $inbox;
    private $proc;
    private string $respFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inbox = db_connect('inbox');
        foreach (['mara_suggestions', 'messages', 'conversation_identities', 'conversations'] as $t) {
            $this->inbox->table($t)->emptyTable();
        }

        $this->respFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fake-ai-' . uniqid() . '.txt';
        $port = FakeGatewayServer::unusedPort();
        $env  = getenv();
        $env['FAKE_AI_RESPONSE_FILE'] = $this->respFile;
        $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fake-ai.log';
        $this->proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__DIR__) . '/_support/fake-ai-router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes, null, $env
        );
        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
            usleep(100_000);
        }
        foreach (['aigateway.baseUrl' => 'http://127.0.0.1:' . $port . '/v1', 'aigateway.timeoutSeconds' => '3', 'aigateway.jedaUlangDetik' => '0'] as $k => $v) {
            putenv("$k=$v");
            $_ENV[$k] = $_SERVER[$k] = $v;
        }
        \CodeIgniter\Config\Factories::reset('config');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
        }
        @unlink($this->respFile);
        @unlink($this->respFile . '.once');
        foreach (['aigateway.baseUrl', 'aigateway.timeoutSeconds', 'aigateway.jedaUlangDetik'] as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
        \CodeIgniter\Config\Factories::reset('config');
        $this->inbox->table('mara_suggestions')->emptyTable();
        parent::tearDown();
    }

    private function aiMenjawab(array $isi, int $status = 200): void
    {
        file_put_contents($this->respFile, $status . "\n" . json_encode(
            ['choices' => [['message' => ['content' => json_encode($isi)]]]]
        ));
    }

    private function seedConv(string $jid = 'pn', bool $denganPesan = true): int
    {
        $now = date('Y-m-d H:i:s');
        $this->inbox->table('conversations')->insert([
            'chat_id' => '62812000' . random_int(10000, 99999) . '@s.whatsapp.net', 'jid_type' => $jid,
            'status' => 'open', 'last_message_direction' => 'incoming', 'last_message_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $this->inbox->insertID();
        if ($denganPesan) {
            $this->inbox->table('messages')->insert([
                'conversation_id' => $id, 'wa_message_id' => 'W' . uniqid(), 'direction' => 'incoming',
                'message_type' => 'text', 'text' => 'kak cetak foto 4R 10 lembar', 'message_timestamp' => $now,
                'created_at' => $now,
            ]);
        }

        return $id;
    }

    private function minta(int $id, bool $login = true)
    {
        $req = $login ? $this->withSession(['isLoggedIn' => true, 'id_user' => 21, 'role' => 'kasir']) : $this;

        return $req->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'X-CSRF-TOKEN' => csrf_hash()])
            ->post('/inbox/api/conversations/' . $id . '/generate-saran');
    }

    private function aktif(int $id): array
    {
        return $this->inbox->table('mara_suggestions')->where('conversation_id', $id)->where('status', 'active')
            ->get()->getResultArray();
    }

    public function testSimpanDanSupersedeBalasan(): void
    {
        $id = $this->seedConv();
        $this->aiMenjawab(['balasan' => ['text' => 'Baik kak, 10 lembar 4R ya'], 'daftar_periksa' => ['items' => ['diabaikan']]]);
        $this->minta($id)->assertOK();
        $this->assertCount(1, $this->aktif($id), 'hanya balasan yang disimpan');

        $this->aiMenjawab(['balasan' => ['text' => 'Draft kedua']]);
        $this->minta($id)->assertOK();

        $aktif = $this->aktif($id);
        $this->assertCount(1, $aktif);
        $this->assertSame('Draft kedua', $aktif[0]['text']);
        $this->assertSame(1, $this->inbox->table('mara_suggestions')->where('status', 'superseded')->countAllResults());
        $this->assertNotEmpty($aktif[0]['expires_at']);
        $this->assertNull($aktif[0]['action']);
    }

    public function testKeranjangDanActionTersimpan(): void
    {
        $id = $this->seedConv();
        $this->aiMenjawab([
            'balasan' => ['text' => 'Siap kak, 3 lembar 10R ya'],
            'action' => 'buka_kasir',
            'cart_items' => [['produk_id' => 76, 'jumlah' => 3], ['produk_id' => 424242, 'jumlah' => 1]],
        ]);
        $this->minta($id)->assertOK();

        $aktif = $this->aktif($id);
        $this->assertSame('buka_kasir', $aktif[0]['action']);
        $this->assertSame([['produk_id' => 76, 'jumlah' => 3]], json_decode($aktif[0]['cart_items'], true));
    }

    public function testTanpaLoginDitolak(): void
    {
        $id = $this->seedConv();
        $this->aiMenjawab(['balasan' => ['text' => 'x']]);
        $res = $this->minta($id, false);
        $this->assertNotSame(200, $res->response()->getStatusCode());
        $this->assertCount(0, $this->aktif($id));
    }

    public function testPercakapanGrupDitolak403(): void
    {
        $id = $this->seedConv('group');
        $this->aiMenjawab(['balasan' => ['text' => 'x']]);
        $this->assertSame(403, $this->minta($id)->response()->getStatusCode());
        $this->assertCount(0, $this->aktif($id));
    }

    public function testPercakapanTidakAda404(): void
    {
        $this->assertSame(404, $this->minta(999999)->response()->getStatusCode());
    }

    public function testSaranLamaSemuaTipeDigantikan(): void
    {
        $id  = $this->seedConv();
        $now = '2026-10-19 10:00:00';
        foreach (['balasan', 'daftar-periksa', 'peringatan'] as $tipe) {
            $this->inbox->table('mara_suggestions')->insert([
                'conversation_id' => $id, 'type' => $tipe, 'priority' => 'normal', 'text' => "lama $tipe",
                'status' => 'active', 'expires_at' => '2099-01-01 00:00:00', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $this->aiMenjawab(['balasan' => ['text' => 'Saran baru']]);
        $this->minta($id)->assertOK();

        $aktif = $this->aktif($id);
        $this->assertCount(1, $aktif, 'daftar-periksa dan peringatan lama tidak boleh tersisa');
        $this->assertSame('Saran baru', $aktif[0]['text']);
    }

    public function testUlangSekaliBila429LaluBerhasil(): void
    {
        $id = $this->seedConv();
        $this->aiMenjawab(['balasan' => ['text' => 'Berhasil setelah ulang']]);
        file_put_contents($this->respFile . '.once', "429\n{}");
        $this->minta($id)->assertOK();
        $this->assertSame('Berhasil setelah ulang', $this->aktif($id)[0]['text']);
    }

    public function testGatewayErrorTidakMenulisDb(): void
    {
        $id = $this->seedConv();
        file_put_contents($this->respFile, "429\n{}");
        $res = $this->minta($id);
        $this->assertSame(502, $res->response()->getStatusCode());
        $this->assertStringContainsString('dibatasi', $res->getJSON());
        $this->assertSame(0, $this->inbox->table('mara_suggestions')->countAllResults());
    }

    public function testJsonRusakDanTanpaPesanTidakMenulisDb(): void
    {
        $id = $this->seedConv();
        file_put_contents($this->respFile, "200\n" . json_encode(['choices' => [['message' => ['content' => 'bukan json']]]]));
        $this->assertSame(502, $this->minta($id)->response()->getStatusCode());

        $kosong = $this->seedConv('pn', false);
        $this->assertSame(502, $this->minta($kosong)->response()->getStatusCode());
        $this->assertSame(0, $this->inbox->table('mara_suggestions')->countAllResults());
    }
}
