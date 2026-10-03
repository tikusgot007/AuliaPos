<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\GatewayApiTestTrait;

/**
 * Bug fix regression test (docs/sesi/2026-10-02-bug-nama-conversation-wa-web.md,
 * reported as "Penjelasan masalah untuk tim 01.pdf"):
 *
 * InboxGatewayApi::messages() used to overwrite `conversations.whatsapp_name`
 * for BOTH incoming and outgoing messages. For an outgoing message synced
 * from WA Web/HP (direction='outgoing', fromMe=true), the payload's
 * `contact_name` is the STAFF's own WhatsApp push name, not the customer's --
 * so the conversation's displayed name flipped to the staff member's name
 * every time staff replied outside the POS.
 *
 * Fix: app/Controllers/InboxGatewayApi.php now requires
 * $direction === 'incoming' before updating whatsapp_name, mirroring the
 * existing direction check used for conversation status.
 *
 * Runs against the `inbox` group, redirected to `aulia_inboxdb_test` under
 * testing (see tests/_support/bootstrap-feature.php and
 * app/Config/Database.php).
 *
 * @internal
 */
final class InboxGatewayApiWhatsappNameTest extends CIUnitTestCase
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

    private function currentWhatsappName(int $conversationId): ?string
    {
        $row = $this->inbox->table('conversations')
            ->select('whatsapp_name')
            ->where('id', $conversationId)
            ->get()
            ->getRowArray();

        return $row['whatsapp_name'] ?? null;
    }

    public function testOutgoingMessageFromWaWebDoesNotOverwriteWhatsappName(): void
    {
        $chatId = '6281200000001@s.whatsapp.net';
        $conversationId = $this->seedConversation($chatId, 'Ahmad');

        $response = $this->postGatewayMessage([
            'wa_message_id'     => 'WAMSG-OUTGOING-0001',
            'chat_id'           => $chatId,
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'Baik kak, stok masih ada.',
            'message_timestamp' => $this->nowIso(),
            'direction'         => 'outgoing',
            // fromMe=true on the Gateway side: this is the STAFF's own
            // WhatsApp push name, never the customer's.
            'contact_name'      => 'James',
        ]);

        $response->assertStatus(200);
        $response->assertJSONFragment(['status' => 'success']);

        $this->assertSame(
            'Ahmad',
            $this->currentWhatsappName($conversationId),
            'BUG REGRESSION: outgoing message from WA Web/HP overwrote whatsapp_name with the staff push name.'
        );
    }

    public function testIncomingMessageStillUpdatesWhatsappName(): void
    {
        $chatId = '6281200000002@s.whatsapp.net';
        $conversationId = $this->seedConversation($chatId, 'Nama Lama');

        $response = $this->postGatewayMessage([
            'wa_message_id'     => 'WAMSG-INCOMING-0001',
            'chat_id'           => $chatId,
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'Halo, mau tanya stok.',
            'message_timestamp' => $this->nowIso(),
            'direction'         => 'incoming',
            'contact_name'      => 'Ahmad Baru',
        ]);

        $response->assertStatus(200);

        $this->assertSame(
            'Ahmad Baru',
            $this->currentWhatsappName($conversationId),
            'Incoming messages must keep updating whatsapp_name with the customer push name (no regression).'
        );
    }

    public function testDefaultDirectionIsIncomingAndStillUpdatesWhatsappName(): void
    {
        // Contract note at InboxGatewayApi::messages() docblock: 'direction'
        // is optional and defaults to 'incoming' for backward compatibility
        // with the original Phase 2 contract.
        $chatId = '6281200000003@s.whatsapp.net';
        $conversationId = $this->seedConversation($chatId, 'Nama Lama');

        $response = $this->postGatewayMessage([
            'wa_message_id'     => 'WAMSG-NODIRECTION-0001',
            'chat_id'           => $chatId,
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'Halo, mau tanya stok.',
            'message_timestamp' => $this->nowIso(),
            'contact_name'      => 'Ahmad Tanpa Direction',
        ]);

        $response->assertStatus(200);

        $this->assertSame(
            'Ahmad Tanpa Direction',
            $this->currentWhatsappName($conversationId)
        );
    }

    public function testOutgoingMessageForUnknownChatIdCreatesConversationWithoutStaffName(): void
    {
        // Create-path guard: an outgoing message from WA Web/HP for a chat_id
        // that is not yet known still creates a conversation via
        // resolveConversationId() step 4. Before the create-path fix, the
        // staff push name was written as whatsapp_name there too, so the
        // reported bug survived whenever staff started a brand-new chat from
        // their phone.
        $chatId = '6281200000004@s.whatsapp.net';

        $response = $this->postGatewayMessage([
            'wa_message_id'     => 'WAMSG-OUTGOING-NEWCHAT-0001',
            'chat_id'           => $chatId,
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'Permisi, ini toko.',
            'message_timestamp' => $this->nowIso(),
            'direction'         => 'outgoing',
            'contact_name'      => 'James',
        ]);

        $response->assertStatus(200);

        $row = $this->inbox->table('conversations')
            ->where('chat_id', $chatId)
            ->get()
            ->getRowArray();

        $this->assertNotNull($row, 'Outgoing message for an unknown chat_id must still create the conversation.');
        $this->assertNull(
            $row['whatsapp_name'],
            'BUG REGRESSION: a new conversation created by an outgoing WA Web/HP message was named after the staff.'
        );
    }
}
