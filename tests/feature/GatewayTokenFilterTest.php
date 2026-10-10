<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\GatewayApiTestTrait;

/**
 * TODO-Q3: test langsung untuk `App\Filters\GatewayTokenFilter`, yang
 * sebelumnya hanya diuji IMPLISIT lewat `GatewayApiTestTrait::postGatewayMessage()`
 * (selalu mengirim token valid). Di sini auth ditembak langsung lewat
 * endpoint `/api/inbox/gateway/messages` (satu endpoint cukup -- filter
 * yang sama dipasang di semua rute `api/inbox/gateway/*`, lihat
 * `app/Config/Routes.php` & `app/Config/Filters.php`).
 *
 * Membuktikan (`app/Filters/GatewayTokenFilter.php:27-61`):
 *  - Authorization header absen -> 401.
 *  - Format bukan "Bearer <token>" -> 401.
 *  - Token salah -> 401.
 *  - Token kosong setelah "Bearer " -> 401 (tidak lolos sebagai string kosong).
 *  - Token benar -> lolos ke controller (bukan 401/503).
 *
 * TIDAK diuji: token server dikonfigurasi kosong (503) -- butuh override
 * `Config\Inbox::$gatewayToken` global yang bentrok dengan
 * `GatewayApiTestTrait` (selalu mengisi env token test); perilaku 503 itu
 * sendiri trivial (early return sebelum baca header) dan sudah jelas dari
 * pembacaan kode.
 *
 * @internal
 */
final class GatewayTokenFilterTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use GatewayApiTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gatewaySetUp();
    }

    protected function tearDown(): void
    {
        $this->gatewayTearDown();
        parent::tearDown();
    }

    private function validPayload(string $waMessageId): array
    {
        return [
            'wa_message_id'     => $waMessageId,
            'chat_id'           => '6281400000099@s.whatsapp.net',
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'uji token',
            'message_timestamp' => $this->nowIso(),
            'direction'         => 'incoming',
        ];
    }

    public function testMissingAuthorizationHeaderIsRejected(): void
    {
        $response = $this
            ->withBodyFormat('json')
            ->post('/api/inbox/gateway/messages', $this->validPayload('WAMSG-TOKEN-0001'));

        $response->assertStatus(401);
        $this->assertSame('error', $this->gatewayBodyJson($response)['status'] ?? null);
    }

    public function testMalformedAuthorizationHeaderIsRejected(): void
    {
        $response = $this
            ->withHeaders(['Authorization' => $this->gatewayToken])
            ->withBodyFormat('json')
            ->post('/api/inbox/gateway/messages', $this->validPayload('WAMSG-TOKEN-0002'));

        $response->assertStatus(401);
    }

    public function testWrongTokenIsRejected(): void
    {
        $response = $this
            ->withHeaders(['Authorization' => 'Bearer token-yang-salah'])
            ->withBodyFormat('json')
            ->post('/api/inbox/gateway/messages', $this->validPayload('WAMSG-TOKEN-0003'));

        $response->assertStatus(401);
    }

    public function testEmptyBearerTokenIsRejected(): void
    {
        $response = $this
            ->withHeaders(['Authorization' => 'Bearer '])
            ->withBodyFormat('json')
            ->post('/api/inbox/gateway/messages', $this->validPayload('WAMSG-TOKEN-0004'));

        $response->assertStatus(401);
    }

    public function testValidTokenPassesThrough(): void
    {
        $this->seedConversation('6281400000099@s.whatsapp.net');

        $response = $this->postGatewayMessage($this->validPayload('WAMSG-TOKEN-0005'));

        $response->assertStatus(200);
        $this->assertSame('success', $this->gatewayBodyJson($response)['status'] ?? null);
    }
}
