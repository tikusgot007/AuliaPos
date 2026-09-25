<?php

use App\Controllers\InboxGatewayApi;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Psr\Log\NullLogger;

/**
 * Bug-fix plan: plan-bugfix-inbox-last-message-at-monotonic-v1.0.md
 * (TASK-003, TEST-002) -- controller-level regression reproducing the
 * exact backlog-flush scenario from the Introduction: a newer message
 * arrives first, then a late-arriving (older-timestamped) backlog
 * message for the same chat_id. Before the fix, the second,
 * unconditional `update()` in `InboxGatewayApi::messages()` silently
 * moves `last_message_at`/`last_message_direction` backward.
 *
 * Direct-controller-call pattern mirrors
 * tests/session/InboxOutgoingIdempotencyTest.php: `initController()` +
 * calling the public method directly, so `GatewayTokenFilter` (a route
 * filter) never runs and no bearer token is needed.
 *
 * @internal
 */
final class InboxGatewayLastMessageAtTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('aulia_inboxdb_test', db_connect('inbox')->query('SELECT DATABASE() AS db')->getRow()->db);

        $db = db_connect('inbox');
        $db->table('messages')->emptyTable();
        $db->table('conversations')->emptyTable();
    }

    private function callMessages(array $payload): array
    {
        $request = new IncomingRequest(new \Config\App(), new URI('cli'), null, new UserAgent());
        $request->setBody(json_encode($payload, JSON_THROW_ON_ERROR));

        $controller = new InboxGatewayApi();
        $controller->initController($request, new Response(new \Config\App()), new NullLogger());

        $response = $controller->messages();

        return json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function conversation(string $chatId): array
    {
        $rows = db_connect('inbox')->table('conversations')->where('chat_id', $chatId)->get()->getResultArray();

        return $rows[0];
    }

    public function testLateArrivingBacklogMessageDoesNotMoveLastMessageAtBackward(): void
    {
        $chatId = '6281200000001@s.whatsapp.net';

        // 1) Newer message first (staff reply synced from WA Web/HP).
        $newerBody = $this->callMessages([
            'wa_message_id'     => 'wa-newer-001',
            'chat_id'           => $chatId,
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'Balasan staff',
            'message_timestamp' => '2026-09-25 10:30:00',
            'direction'         => 'outgoing',
        ]);
        $this->assertSame('success', $newerBody['status']);
        $conversationId = (int) $newerBody['conversation_id'];

        // The conversation is then closed and snoozed by staff.
        db_connect('inbox')->table('conversations')->where('id', $conversationId)->update([
            'status'        => 'closed',
            'snoozed_until' => '2026-09-26 10:30:00',
        ]);

        // 2) Late-arriving backlog item: older timestamp, delivered second.
        $olderBody = $this->callMessages([
            'wa_message_id'     => 'wa-older-002',
            'chat_id'           => $chatId,
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'Backlog lama',
            'message_timestamp' => '2026-09-25 10:00:00',
            'direction'         => 'incoming',
        ]);
        $this->assertSame('success', $olderBody['status']);
        $this->assertSame($conversationId, (int) $olderBody['conversation_id']);

        $conversation = $this->conversation($chatId);

        // REQ-001/REQ-002: the summary must still reflect the newer
        // message, NOT the older one delivered second.
        $this->assertSame('2026-09-25 10:30:00', $conversation['last_message_at']);
        $this->assertSame('outgoing', $conversation['last_message_direction']);

        // CON-001: the guard is scoped to exactly those 2 columns -- the
        // older INCOMING delivery's own status/snoozed_until side effects
        // still applied unconditionally.
        $this->assertSame('open', $conversation['status']);
        $this->assertNull($conversation['snoozed_until']);

        // CON-004: both messages were still persisted verbatim -- the
        // guard never drops or rewrites the message rows themselves.
        $messages = db_connect('inbox')
            ->table('messages')
            ->where('conversation_id', $conversationId)
            ->orderBy('message_timestamp', 'ASC')
            ->get()
            ->getResultArray();

        $this->assertCount(2, $messages);
        $this->assertSame('2026-09-25 10:00:00', $messages[0]['message_timestamp']);
        $this->assertSame('wa-older-002', $messages[0]['wa_message_id']);
        $this->assertSame('2026-09-25 10:30:00', $messages[1]['message_timestamp']);
        $this->assertSame('wa-newer-001', $messages[1]['wa_message_id']);
    }

    public function testInOrderDeliveryMovesSummaryForward(): void
    {
        $chatId = '6281200000002@s.whatsapp.net';

        $first = $this->callMessages([
            'wa_message_id'     => 'wa-inorder-001',
            'chat_id'           => $chatId,
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'Pesan pertama',
            'message_timestamp' => '2026-09-25 11:00:00',
            'direction'         => 'incoming',
        ]);
        $this->assertSame('success', $first['status']);

        $second = $this->callMessages([
            'wa_message_id'     => 'wa-inorder-002',
            'chat_id'           => $chatId,
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'Pesan kedua',
            'message_timestamp' => '2026-09-25 11:05:00',
            'direction'         => 'outgoing',
        ]);
        $this->assertSame('success', $second['status']);

        $conversation = $this->conversation($chatId);

        // Positive control: a genuinely newer message still advances the
        // summary in the normal case (the guard is not a no-op).
        $this->assertSame('2026-09-25 11:05:00', $conversation['last_message_at']);
        $this->assertSame('outgoing', $conversation['last_message_direction']);
    }
}
