<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\GatewayApiTestTrait;

/**
 * Regression test for TODO-I1: the inbound Gateway webhook must stay
 * duplicate-safe. A retried/duplicated delivery of the same `wa_message_id`
 * must never create a second message and must answer 200 with
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
            'message_timestamp' => $this->nowIso(),
            'direction'         => 'incoming',
        ];

        $first = $this->postGatewayMessage($payload);
        $first->assertStatus(200);
        $firstBody = $this->gatewayBodyJson($first);
        $this->assertFalse($firstBody['duplicate'] ?? true, 'Pengiriman pertama bukan duplikat.');

        $second = $this->postGatewayMessage($payload);
        $second->assertStatus(200);
        $secondBody = $this->gatewayBodyJson($second);
        $this->assertTrue($secondBody['duplicate'] ?? false, 'Pengiriman ulang harus duplicate-safe (200 duplicate:true).');

        $count = $this->inbox->table('messages')->where('wa_message_id', 'WAMSG-DUP-0001')->countAllResults();
        $this->assertSame(1, $count, 'Duplikat tidak boleh membuat baris pesan kedua.');
    }
}
