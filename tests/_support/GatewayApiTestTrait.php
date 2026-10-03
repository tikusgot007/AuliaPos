<?php

namespace Tests\Support;

/**
 * Shared harness for the authenticated Gateway webhook feature tests that hit
 * `/api/inbox/gateway/messages`. It keeps the token lifecycle, conversation
 * seeding, and the authenticated POST/JSON decoding in ONE place so the
 * fixture and the endpoint contract cannot drift between test classes.
 *
 * The consuming class must also `use CodeIgniter\Test\FeatureTestTrait` and
 * call {@see gatewaySetUp()} from setUp() / {@see gatewayTearDown()} from
 * tearDown().
 */
trait GatewayApiTestTrait
{
    protected string $gatewayToken = 'test-gateway-token-for-phpunit';

    /** @var \CodeIgniter\Database\BaseConnection */
    protected $inbox;

    protected function gatewaySetUp(): void
    {
        // Endpoint requires a non-empty configured token (see
        // GatewayTokenFilter::before()); fixed here so tests do not depend on
        // whatever .env happens to have.
        putenv('inbox.gatewayToken=' . $this->gatewayToken);
        $_ENV['inbox.gatewayToken']    = $this->gatewayToken;
        $_SERVER['inbox.gatewayToken'] = $this->gatewayToken;

        $this->inbox = db_connect('inbox');
        $this->inbox->table('messages')->emptyTable();
        $this->inbox->table('conversation_identities')->emptyTable();
        $this->inbox->table('conversations')->emptyTable();
    }

    protected function gatewayTearDown(): void
    {
        putenv('inbox.gatewayToken');
        unset($_ENV['inbox.gatewayToken'], $_SERVER['inbox.gatewayToken']);
    }

    /**
     * Seed an existing conversation plus its chat_id alias.
     * resolveConversationId() recognises an existing conversation via
     * conversation_identities (not conversations.chat_id), so the alias row is
     * required -- otherwise the endpoint would create a new conversation and
     * hit the UNIQUE constraint on conversations.chat_id.
     */
    protected function seedConversation(string $chatId, ?string $whatsappName = null): int
    {
        $now = date('Y-m-d H:i:s');

        $this->inbox->table('conversations')->insert([
            'chat_id'       => $chatId,
            'jid_type'      => 'pn',
            'whatsapp_name' => $whatsappName,
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

    protected function postGatewayMessage(array $payload)
    {
        return $this
            ->withHeaders(['Authorization' => 'Bearer ' . $this->gatewayToken])
            ->withBodyFormat('json')
            ->post('/api/inbox/gateway/messages', $payload);
    }

    /**
     * Decode the controller's JSON body. The feature-test harness wraps the raw
     * output in a minimal HTML document, so strip that before decoding.
     */
    protected function gatewayBodyJson($response): array
    {
        $decoded = json_decode(trim(strip_tags($response->getBody())), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Gateway sends message_timestamp as an ISO 8601 string. A raw Unix integer
     * would be rejected by InboxGatewayApi::parseTimestamp() with HTTP 400.
     */
    protected function nowIso(): string
    {
        return gmdate('Y-m-d\TH:i:s.000\Z');
    }
}
