<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\GatewayApiTestTrait;

/**
 * Pengujian endpoint rekonsiliasi fail-safe WAGRAB -> Inbox AuliaPos.
 * POST /api/inbox/sync-missing
 *
 * @internal
 */
final class InboxReconcileTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use GatewayApiTestTrait;

    private string $reconcileToken = 'test-reconcile-token-secret-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->gatewaySetUp();

        putenv('INTERNAL_API_TOKEN=' . $this->reconcileToken);
        $_ENV['INTERNAL_API_TOKEN']    = $this->reconcileToken;
        $_SERVER['INTERNAL_API_TOKEN'] = $this->reconcileToken;
    }

    public function testUnauthorizedWithoutToken(): void
    {
        $result = $this->call('post', '/api/inbox/sync-missing', []);
        $result->assertStatus(401);
        $result->assertJSONFragment(['status' => 'error']);
    }

    public function testUnauthorizedWithInvalidToken(): void
    {
        $result = $this->withHeaders(['X-Internal-Token' => 'wrong-token'])
            ->call('post', '/api/inbox/sync-missing', []);
        $result->assertStatus(401);
        $result->assertJSONFragment(['status' => 'error']);
    }

    public function testSyncInsertAndIdempotency(): void
    {
        $waId = 'WAGRAB_TEST_' . bin2hex(random_bytes(6));
        $payload = [
            [
                'wa_message_id'     => $waId,
                'phone_raw'         => '081298765432',
                'chat_label'        => 'Test Reconcile User',
                'message_type'      => 'text',
                'text'              => 'Halo pesan uji rekonsiliasi',
                'message_timestamp' => date('Y-m-d H:i:s'),
            ],
        ];

        // 1. Tembak pertama: insert 1, ignore 0
        $res1 = $this->withHeaders(['X-Internal-Token' => $this->reconcileToken])
            ->withBodyFormat('json')
            ->post('/api/inbox/sync-missing', $payload);
        $res1->assertStatus(200);
        $res1->assertJSONExact(['status' => 'ok', 'inserted' => 1, 'ignored' => 0]);

        // Cek database
        $msg = $this->inbox->table('messages')->where('wa_message_id', $waId)->get()->getRowArray();
        $this->assertNotNull($msg);
        $this->assertSame('incoming', $msg['direction']);
        $this->assertSame('Halo pesan uji rekonsiliasi', $msg['text']);

        // 2. Tembak kedua dengan data yang sama: insert 0, ignore 1 (idempotent)
        $res2 = $this->withHeaders(['X-Internal-Token' => $this->reconcileToken])
            ->withBodyFormat('json')
            ->post('/api/inbox/sync-missing', $payload);
        $res2->assertStatus(200);
        $res2->assertJSONExact(['status' => 'ok', 'inserted' => 0, 'ignored' => 1]);
    }

    public function testSyncMediaMessagePrefix(): void
    {
        $waId = 'WAGRAB_MEDIA_' . bin2hex(random_bytes(6));
        $payload = [
            [
                'wa_message_id'     => $waId,
                'phone_raw'         => '081298765432',
                'chat_label'        => 'Test Reconcile User',
                'message_type'      => 'image',
                'caption'           => 'foto bukti bayar',
                'file_name'         => 'bukti.jpg',
                'message_timestamp' => date('Y-m-d H:i:s'),
            ],
        ];

        $res = $this->withHeaders(['X-Internal-Token' => $this->reconcileToken])
            ->withBodyFormat('json')
            ->post('/api/inbox/sync-missing', $payload);
        $res->assertStatus(200);
        $res->assertJSONExact(['status' => 'ok', 'inserted' => 1, 'ignored' => 0]);

        $msg = $this->inbox->table('messages')->where('wa_message_id', $waId)->get()->getRowArray();
        $this->assertNotNull($msg);
        $this->assertSame('image', $msg['message_type']);
        $this->assertSame('⚠️ [CEK WA WEB] foto bukti bayar', $msg['text']);
        $this->assertNull($msg['media_path']);
        $this->assertSame('bukti.jpg', $msg['media_filename']);
    }
}
