<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\GatewayApiTestTrait;

/**
 * cart_items on M.A.R.A suggestions: AC-1..AC-4 of
 * docs/requirements/2026-10-18-isi-keranjang-dari-saran-mara.md.
 *
 * Tests the endpoint POST /api/inbox/mara/suggestions for the new optional
 * `cart_items` field. Endpoint read side (apiSuggestions) is also checked
 * to ensure `cart_items` are returned alongside existing fields.
 *
 * @internal
 */
final class MaraSuggestionsCartItemsTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use GatewayApiTestTrait;

    private string $maraToken = 'test-mara-token-for-phpunit';

    protected function setUp(): void
    {
        parent::setUp();
        $this->gatewaySetUp();

        putenv('mara.apiToken=' . $this->maraToken);
        $_ENV['mara.apiToken']    = $this->maraToken;
        $_SERVER['mara.apiToken'] = $this->maraToken;

        $this->inbox->table('mara_suggestions')->emptyTable();
    }

    protected function tearDown(): void
    {
        $this->inbox->table('mara_suggestions')->emptyTable();
        putenv('mara.apiToken');
        unset($_ENV['mara.apiToken'], $_SERVER['mara.apiToken']);
        $this->gatewayTearDown();
        parent::tearDown();
    }

    private function postSuggestion(array $body)
    {
        return $this
            ->withHeaders(['Authorization' => 'Bearer ' . $this->maraToken])
            ->withBodyFormat('json')
            ->post('/api/inbox/mara/suggestions', $body);
    }

    private function bodyJson($response): array
    {
        $decoded = json_decode(trim(strip_tags($response->getBody())), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function baseSaran(int $conversationId, array $cartItems): array
    {
        return [
            'conversation_id' => $conversationId,
            'type'            => 'balasan',
            'priority'        => 'normal',
            'text'            => 'Pesanan foto dompet 4 lembar',
            'action'          => 'buka_kasir',
            'cart_items'      => $cartItems,
        ];
    }

    // ---------------------------------------------------------------
    // AC-1: cart_items disimpan di kolom terpisah
    // ---------------------------------------------------------------

    public function testCartItemsDisimpanDiKolomTerpisah(): void
    {
        $cid = $this->seedConversation('6281400000090@s.whatsapp.net');

        $response = $this->postSuggestion($this->baseSaran($cid, [
            ['produk_id' => 68, 'jumlah' => 4],
        ]));

        $response->assertStatus(201);
        $json = $this->bodyJson($response);
        $this->assertArrayHasKey('data', $json);

        $row = $this->inbox->table('mara_suggestions')
            ->where('id', $json['data']['id'])->get()->getRowArray();

        $this->assertNotNull($row['cart_items'], 'cart_items harus tersimpan');
        $stored = json_decode($row['cart_items'], true);
        $this->assertCount(1, $stored);
        $this->assertSame(68, $stored[0]['produk_id']);
        $this->assertSame(4, $stored[0]['jumlah']);

        // `items` (daftar-periksa) tetap null
        $this->assertNull($row['items']);
    }

    public function testSaranTanpaCartItemsTidakBerubah(): void
    {
        $cid = $this->seedConversation('6281400000091@s.whatsapp.net');

        $response = $this->postSuggestion([
            'conversation_id' => $cid,
            'type'            => 'balasan',
            'text'            => 'Tanpa keranjang',
            'action'          => 'buka_kasir',
        ]);

        $response->assertStatus(201);
        $row = $this->inbox->table('mara_suggestions')
            ->where('id', $this->bodyJson($response)['data']['id'])
            ->get()->getRowArray();

        $this->assertNull($row['cart_items']);
    }

    // ---------------------------------------------------------------
    // AC-2: hanya produk_id dan jumlah yang disimpan
    // ---------------------------------------------------------------

    public function testHanyaProdukIdDanJumlahYangDisimpan(): void
    {
        $cid = $this->seedConversation('6281400000092@s.whatsapp.net');

        $response = $this->postSuggestion($this->baseSaran($cid, [
            ['produk_id' => 68, 'jumlah' => 4, 'harga' => 9999, 'nama' => 'Palsu', 'satuan' => 'kg'],
        ]));

        $response->assertStatus(201);
        $stored = json_decode(
            $this->inbox->table('mara_suggestions')
                ->where('id', $this->bodyJson($response)['data']['id'])
                ->get()->getRowArray()['cart_items'],
            true
        );

        $this->assertCount(1, $stored);
        $this->assertSame(['produk_id' => 68, 'jumlah' => 4], $stored[0]);
        $this->assertArrayNotHasKey('harga', $stored[0]);
        $this->assertArrayNotHasKey('nama', $stored[0]);
    }

    public function testLebihDari20BarisDitolak(): void
    {
        $cid = $this->seedConversation('6281400000093@s.whatsapp.net');

        $items = [];
        for ($i = 1; $i <= 21; $i++) {
            $items[] = ['produk_id' => $i, 'jumlah' => 1];
        }

        $response = $this->postSuggestion($this->baseSaran($cid, $items));
        $response->assertStatus(400);
        $this->assertSame(0, $this->inbox->table('mara_suggestions')->countAllResults());
    }

    public function testJumlahDiLuarRentangDitolak(): void
    {
        $cid = $this->seedConversation('6281400000094@s.whatsapp.net');

        // jumlah 0
        $response = $this->postSuggestion($this->baseSaran($cid, [
            ['produk_id' => 68, 'jumlah' => 0],
        ]));
        $response->assertStatus(400);

        // jumlah 1000
        $response = $this->postSuggestion($this->baseSaran($cid, [
            ['produk_id' => 68, 'jumlah' => 1000],
        ]));
        $response->assertStatus(400);

        // produk_id negatif
        $response = $this->postSuggestion($this->baseSaran($cid, [
            ['produk_id' => -1, 'jumlah' => 5],
        ]));
        $response->assertStatus(400);
    }

    // ---------------------------------------------------------------
    // AC-3: produk_id kembar digabung
    // ---------------------------------------------------------------

    public function testProdukIdKembarDigabung(): void
    {
        $cid = $this->seedConversation('6281400000095@s.whatsapp.net');

        $response = $this->postSuggestion($this->baseSaran($cid, [
            ['produk_id' => 68, 'jumlah' => 3],
            ['produk_id' => 62, 'jumlah' => 5],
            ['produk_id' => 68, 'jumlah' => 2],
        ]));

        $response->assertStatus(201);
        $stored = json_decode(
            $this->inbox->table('mara_suggestions')
                ->where('id', $this->bodyJson($response)['data']['id'])
                ->get()->getRowArray()['cart_items'],
            true
        );

        $this->assertCount(2, $stored);

        $byId = [];
        foreach ($stored as $item) {
            $byId[$item['produk_id']] = $item['jumlah'];
        }
        $this->assertSame(5, $byId[68], 'jumlah 68 harus 3+2=5');
        $this->assertSame(5, $byId[62]);
    }

    // ---------------------------------------------------------------
    // AC-4: cart_items tanpa action / pada daftar-periksa ditolak
    // ---------------------------------------------------------------

    public function testCartItemsTanpaActionDitolak(): void
    {
        $cid = $this->seedConversation('6281400000096@s.whatsapp.net');

        $response = $this->postSuggestion([
            'conversation_id' => $cid,
            'type'            => 'balasan',
            'text'            => 'Mau pesan foto',
            // action tidak ada
            'cart_items'      => [['produk_id' => 68, 'jumlah' => 1]],
        ]);

        $response->assertStatus(400);
    }

    public function testCartItemsPadaDaftarPeriksaDitolak(): void
    {
        $cid = $this->seedConversation('6281400000097@s.whatsapp.net');

        $response = $this->postSuggestion([
            'conversation_id' => $cid,
            'type'            => 'daftar-periksa',
            'items'           => ['Langkah 1'],
            'action'          => 'buka_kasir',
            'cart_items'      => [['produk_id' => 68, 'jumlah' => 1]],
        ]);

        $response->assertStatus(400);
    }

    // ---------------------------------------------------------------
    // AC-1 lanjutan: apiSuggestions mengembalikan cart_items + ada_keranjang
    // ---------------------------------------------------------------

    public function testApiSuggestionsMengembalikanCartItemsDanAdaKeranjang(): void
    {
        $cid = $this->seedConversation('6281400000098@s.whatsapp.net');

        $this->postSuggestion($this->baseSaran($cid, [
            ['produk_id' => 68, 'jumlah' => 4],
        ]));

        $response = $this
            ->withSession(['isLoggedIn' => true, 'id_user' => 1, 'role' => 'kasir'])
            ->get('/inbox/api/conversations/' . $cid . '/suggestions');

        $response->assertStatus(200);
        $json = $this->bodyJson($response);
        $saran = $json['suggestions'][0];

        $this->assertIsArray($saran['cart_items']);
        $this->assertSame(68, $saran['cart_items'][0]['produk_id']);
        $this->assertTrue($saran['ada_keranjang']);
    }

    public function testApiSuggestionsTanpaCartItemsAdaKeranjangFalse(): void
    {
        $cid = $this->seedConversation('6281400000099@s.whatsapp.net');

        $this->postSuggestion([
            'conversation_id' => $cid,
            'type'            => 'balasan',
            'text'            => 'Tanpa keranjang',
        ]);

        $response = $this
            ->withSession(['isLoggedIn' => true, 'id_user' => 1, 'role' => 'kasir'])
            ->get('/inbox/api/conversations/' . $cid . '/suggestions');

        $json = $this->bodyJson($response);
        $saran = $json['suggestions'][0];

        $this->assertNull($saran['cart_items']);
        $this->assertFalse($saran['ada_keranjang']);
    }

    // ---------------------------------------------------------------
    // Edge: cart_items kosong array [] = valid, disimpan null (tidak ada item)
    // ---------------------------------------------------------------

    public function testCartItemsKosongDisimpanNull(): void
    {
        $cid = $this->seedConversation('6281400000100@s.whatsapp.net');

        $response = $this->postSuggestion($this->baseSaran($cid, []));
        $response->assertStatus(201);

        $row = $this->inbox->table('mara_suggestions')
            ->where('id', $this->bodyJson($response)['data']['id'])
            ->get()->getRowArray();

        $this->assertNull($row['cart_items'], 'array kosong disimpan null');
    }
}
