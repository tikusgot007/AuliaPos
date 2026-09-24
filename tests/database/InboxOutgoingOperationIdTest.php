<?php

use App\Models\MessageModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * TASK-017 regression checks for outgoing operation id propagation and
 * database replay de-duplication.
 *
 * @internal
 */
final class InboxOutgoingOperationIdTest extends CIUnitTestCase
{
    private string $controllerSource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controllerSource = file_get_contents(APPPATH . 'Controllers/Inbox.php');
        $db = db_connect('inbox');
        $db->table('messages')->emptyTable();
        $db->table('conversations')->emptyTable();
    }

    public function testAjaxOperationIdIsReadAndForwardedWithoutServerGeneratedKey(): void
    {
        $this->assertStringContainsString("getPost('operation_id')", $this->controllerSource);
        $this->assertStringContainsString("callGatewaySend(\$config, \$chatId, \$text, \$operationId)", $this->controllerSource);
        $this->assertStringContainsString("callGatewaySendMedia(\$config, \$conversation['chat_id']", $this->controllerSource);
        $this->assertStringContainsString("\$captionUntukGateway, \$operationId)", $this->controllerSource);
        $this->assertStringNotContainsString('random_bytes(16)', $this->controllerSource);
    }

    public function testExistingOperationIdReturnsOneStoredMessageWithoutSecondInsert(): void
    {
        $conversationId = $this->seedConversation();
        $operationId = 'ui-' . bin2hex(random_bytes(6));
        $model = new MessageModel();
        $model->insert($this->messageData($conversationId, $operationId));
        $model->builder()->resetQuery();

        $existing = $model->where('gateway_operation_id', $operationId)->first();
        $model->builder()->resetQuery();

        $this->assertIsArray($existing);
        $this->assertSame($operationId, $existing['gateway_operation_id']);
        $this->assertSame(1, $model->where('gateway_operation_id', $operationId)->countAllResults());
    }

    public function testTextAndMediaInsertsPersistGatewayOperationId(): void
    {
        $conversationId = $this->seedConversation();
        $operationId = 'media-' . bin2hex(random_bytes(6));
        $db = db_connect('inbox');
        $db->table('messages')->insert($this->messageData($conversationId, $operationId));

        $row = $db->table('messages')->where('gateway_operation_id', $operationId)->get()->getRowArray();
        $this->assertSame($operationId, $row['gateway_operation_id']);
    }

    private function seedConversation(): int
    {
        $db = db_connect('inbox');
        $db->table('conversations')->insert([
            'chat_id' => '628' . random_int(100000000, 999999999) . '@s.whatsapp.net',
            'jid_type' => 'pn',
            'status' => 'open',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return (int) $db->insertID();
    }

    private function messageData(int $conversationId, string $operationId): array
    {
        return [
            'conversation_id' => $conversationId,
            'wa_message_id' => 'local-' . bin2hex(random_bytes(8)),
            'direction' => 'outgoing',
            'message_type' => 'text',
            'text' => 'test reply',
            'message_timestamp' => date('Y-m-d H:i:s'),
            'send_status' => 'sent',
            'gateway_operation_id' => $operationId,
        ];
    }
}
