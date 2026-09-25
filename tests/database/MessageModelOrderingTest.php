<?php

use App\Models\MessageModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Bugfix plan `plan/plan-bugfix-inbox-message-ordering-v1.0.md`
 * (Part B, GW-11): `MessageModel::getByConversation()` MUST return a
 * deterministic order for every conversation, including when several
 * rows share the same `message_timestamp` (REQ-001/REQ-002). The tie
 * MUST resolve to insertion order (`messages.id ASC`), which is the
 * order the Gateway actually delivered them in.
 *
 * Runs against the `inbox` group, redirected to `aulia_inboxdb_test`
 * under testing, same as `ConversationHandoffModelTest.php`.
 *
 * @internal
 */
final class MessageModelOrderingTest extends CIUnitTestCase
{
    private $inbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inbox = db_connect('inbox');

        $actualDatabase = config('Config\Database')->inbox['database'] ?? '';
        $this->assertSame(
            'aulia_inboxdb_test',
            $actualDatabase,
            'Refusing to run: inbox group must point to aulia_inboxdb_test under testing.'
        );

        $this->inbox->table('messages')->emptyTable();
        $this->inbox->table('conversations')->emptyTable();
    }

    private function seedConversation(): int
    {
        $now = date('Y-m-d H:i:s');

        $this->inbox->table('conversations')->insert([
            'chat_id'    => '628' . random_int(100000000, 999999999) . '@s.whatsapp.net',
            'jid_type'   => 'pn',
            'status'     => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->inbox->insertID();
    }

    private function seedMessage(int $conversationId, string $messageTimestamp): int
    {
        $now = date('Y-m-d H:i:s');

        $this->inbox->table('messages')->insert([
            'conversation_id'   => $conversationId,
            'wa_message_id'     => 'wamid-' . bin2hex(random_bytes(8)),
            'direction'         => 'incoming',
            'message_type'      => 'text',
            'text'              => 'pesan',
            'message_timestamp' => $messageTimestamp,
            'send_status'       => 'received',
            'created_at'        => $now,
        ]);

        return (int) $this->inbox->insertID();
    }

    public function testTiedTimestampsKeepInsertionOrder(): void
    {
        $conversationId = $this->seedConversation();
        $timestamp      = '2026-09-25 08:31:35';

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->seedMessage($conversationId, $timestamp);
        }

        $rows = (new MessageModel())->getByConversation($conversationId);

        $this->assertSame($ids, array_map('intval', array_column($rows, 'id')));
    }

    public function testQueryOrdersByTimestampThenId(): void
    {
        $conversationId = $this->seedConversation();
        $this->seedMessage($conversationId, '2026-09-25 08:31:35');

        (new MessageModel())->getByConversation($conversationId);

        $sql = (string) db_connect('inbox')->getLastQuery();

        // Plan `plan-bugfix-inbox-message-ordering-v1.0.md` TEST-002 called
        // for a regex that merely *tolerates* a `messages.` qualifier and
        // backticks (see its RISK-003). The published snippet made the
        // qualifier mandatory, but CodeIgniter's query builder emits this
        // ORDER BY unqualified (`ORDER BY `message_timestamp` ASC, `id`
        // ASC`), which is verified by the recorded red run in the plan's
        // evidence log. The qualifier group is therefore optional here,
        // exactly as the plan's own risk note describes. The assertion is
        // NOT weakened: it still requires both keys, both ASC, in order.
        $this->assertMatchesRegularExpression(
            '/order by\s+(?:`?messages`?\.)?`?message_timestamp`?\s+asc\s*,\s*(?:`?messages`?\.)?`?id`?\s+asc/i',
            $sql,
            'getByConversation() must define the tie-break explicitly. Executed SQL: ' . $sql
        );
    }

    public function testMixedTiedAndDistinctTimestampsOrderCorrectly(): void
    {
        $conversationId = $this->seedConversation();
        $tiedTimestamp  = '2026-09-25 08:31:35';

        $firstId  = $this->seedMessage($conversationId, $tiedTimestamp);
        $secondId = $this->seedMessage($conversationId, $tiedTimestamp);
        $thirdId  = $this->seedMessage($conversationId, '2026-09-25 08:31:36');

        $rows = (new MessageModel())->getByConversation($conversationId);

        $this->assertSame(
            [$firstId, $secondId, $thirdId],
            array_map('intval', array_column($rows, 'id'))
        );
    }

    public function testDoesNotLeakRowsFromOtherConversations(): void
    {
        $conversationId      = $this->seedConversation();
        $otherConversationId = $this->seedConversation();

        $ownId = $this->seedMessage($conversationId, '2026-09-25 08:31:35');
        $this->seedMessage($otherConversationId, '2026-09-25 08:31:35');
        $this->seedMessage($otherConversationId, '2026-09-25 08:31:36');

        $rows = (new MessageModel())->getByConversation($conversationId);

        $this->assertCount(1, $rows);
        $this->assertSame($ownId, (int) $rows[0]['id']);
    }

    public function testHonoursExistingLimit(): void
    {
        $conversationId = $this->seedConversation();

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->seedMessage($conversationId, '2026-09-25 08:31:3' . $i);
        }

        $rows = (new MessageModel())->getByConversation($conversationId, 3);

        $this->assertCount(3, $rows);
        $this->assertSame(
            array_slice($ids, 0, 3),
            array_map('intval', array_column($rows, 'id'))
        );
    }
}
