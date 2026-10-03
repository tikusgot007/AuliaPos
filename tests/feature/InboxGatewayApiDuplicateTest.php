<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Regression test for TODO-I1 (docs/TODO.md): the inbound Gateway webhook must
 * stay duplicate-safe. A retried/duplicated delivery of the same
 * `wa_message_id` must never create a second message and must answer 200 with
 * `duplicate: true` (the pre-check path), never a 500.
 *
 * The concurrent variant of the same race (both requests passing the pre-check
 * before either inserts) is handled on the insert result in
 * InboxGatewayApi::messages(); it needs real parallelism and is reproduced
 * separately, so this test locks the sequential idempotency contract.
 *
 * Runs against the `inbox` group, redirected to `aulia_inboxdb_test` under
 * testing (tests/_support/bootstrap-feature.php, app/Config/Database.php).
 *
 * @internal
 */
final class InboxGatewayApiDuplicateTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const GATEWAY_TOKEN = 'test-gateway-token-for-phpunit';

    private $inbox;

    protected function setUp(): void
    {
        parent::setUp();

        putenv('inbox.gatewayToken=' . self::GATEWAY_TOKEN);
        $_ENV['inbox.gatewayToken']    = self::GATEWAY_TOKEN;
        $_SERVER['inbox.gatewayToken'] = self::GATEWAY_TOKEN;

        $this->inbox = db_connect('inbox');
        $this->inbox->table('messages')->emptyTable();
        $this->inbox->table('conversation_identities')->emptyTable();
        $this->inbox->table('conversations')->emptyTable();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        putenv('inbox.gatewayToken');
        unset($_ENV['inbox.gatewayToken'], $_SERVER['inbox.gatewayToken']);
    }

    private function seedConversation(string $chatId): int
    {
        $now = date('Y-m-d H:i:s');

        $this->inbox->table('conversations')->insert([
            'chat_id'       => $chatId,
            'jid_type'      => 'pn',
            'whatsapp_name' => 'Pelanggan Uji',
            'status'        => 'open',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        $conversationId = (int) $this->inbox->insertID();

        $this->inbox->table('conversation_identities')->insert([
            'conversation_id' => $conversationId,
            'chat_id'         => $chatId,
            'jid_type'        => 'pn',
            'created_at'      => $now,
        ]);

        return $conversationId;
    }

    private function postMessage(array $payload)
    {
        return $this
            ->withHeaders(['Authorization' => 'Bearer ' . self::GATEWAY_TOKEN])
            ->withBodyFormat('json')
            ->post('/api/inbox/gateway/messages', $payload);
    }

    /**
     * Decode the controller's JSON body. The feature-test harness wraps the
     * raw output in a minimal HTML document, so strip that before decoding.
     */
    private function bodyJson($response): array
    {
        $decoded = json_decode(trim(strip_tags($response->getBody())), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function testSecondIdenticalDeliveryIsDuplicateSafe(): void
    {
        $chatId = '6281400000001@s.whatsapp.net';
        $this->seedConversation($chatId);

        $payload = [
            'wa_message_id'     => 'WAMSG-DUP-0001',
            'chat_id'           => $chatId,
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'pesan yang dikirim ulang',
            'message_timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'direction'         => 'incoming',
        ];

        $first = $this->postMessage($payload);
        $first->assertStatus(200);
        $firstBody = $this->bodyJson($first);
        $this->assertFalse($firstBody['duplicate'] ?? true, 'Pengiriman pertama bukan duplikat.');

        $second = $this->postMessage($payload);
        $second->assertStatus(200);
        $secondBody = $this->bodyJson($second);
        $this->assertTrue($secondBody['duplicate'] ?? false, 'Pengiriman ulang harus duplicate-safe (200 duplicate:true).');

        $count = $this->inbox->table('messages')->where('wa_message_id', 'WAMSG-DUP-0001')->countAllResults();
        $this->assertSame(1, $count, 'Duplikat tidak boleh membuat baris pesan kedua.');
    }
}
