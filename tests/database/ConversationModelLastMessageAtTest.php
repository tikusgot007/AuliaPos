<?php

use App\Models\ConversationModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Bug-fix plan: plan-bugfix-inbox-last-message-at-monotonic-v1.0.md
 * (TASK-001/002, TEST-001) -- model-level contract for
 * `ConversationModel::updateLastMessageIfNewer()`.
 *
 * Pins REQ-001 (monotonic `last_message_at`), REQ-002 (`last_message_at`
 * and `last_message_direction` move together atomically), CON-001 (other
 * fields stay unconditional), CON-005 (stored NULL always accepts the
 * first write), and CON-010 (`updated_at` still advances on the raw
 * builder write).
 *
 * Runs against the `inbox` group, redirected to `aulia_inboxdb_test`
 * under testing -- same guard as
 * tests/session/InboxOutgoingIdempotencyTest.php:28.
 *
 * @internal
 */
final class ConversationModelLastMessageAtTest extends CIUnitTestCase
{
    private $inbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('aulia_inboxdb_test', db_connect('inbox')->query('SELECT DATABASE() AS db')->getRow()->db);

        $this->inbox = db_connect('inbox');
        $this->inbox->table('messages')->emptyTable();
        $this->inbox->table('conversations')->emptyTable();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function seedConversation(array $overrides = []): int
    {
        $now = date('Y-m-d H:i:s');

        $this->inbox->table('conversations')->insert(array_merge([
            'chat_id'                => '628' . random_int(100000000, 999999999) . '@s.whatsapp.net',
            'jid_type'               => 'pn',
            'status'                 => 'open',
            'last_message_at'        => null,
            'last_message_direction' => null,
            'created_at'             => $now,
            'updated_at'             => $now,
        ], $overrides));

        return (int) $this->inbox->insertID();
    }

    private function row(int $conversationId): array
    {
        return $this->inbox->table('conversations')->where('id', $conversationId)->get()->getRowArray();
    }

    public function testRejectsOlderTimestamp(): void
    {
        $conversationId = $this->seedConversation([
            'last_message_at'        => '2026-09-25 10:00:00',
            'last_message_direction' => 'incoming',
        ]);

        $result = (new ConversationModel())->updateLastMessageIfNewer($conversationId, '2026-09-25 09:59:00', 'outgoing');

        $this->assertFalse($result);

        $row = $this->row($conversationId);
        $this->assertSame('2026-09-25 10:00:00', $row['last_message_at']);
        $this->assertSame('incoming', $row['last_message_direction']);
    }

    public function testAppliesNewerTimestamp(): void
    {
        $conversationId = $this->seedConversation([
            'last_message_at'        => '2026-09-25 10:00:00',
            'last_message_direction' => 'incoming',
        ]);

        $result = (new ConversationModel())->updateLastMessageIfNewer($conversationId, '2026-09-25 10:01:00', 'outgoing');

        $this->assertTrue($result);

        $row = $this->row($conversationId);
        $this->assertSame('2026-09-25 10:01:00', $row['last_message_at']);
        $this->assertSame('outgoing', $row['last_message_direction']);
    }

    public function testTieIsRejected(): void
    {
        $conversationId = $this->seedConversation([
            'last_message_at'        => '2026-09-25 10:00:00',
            'last_message_direction' => 'incoming',
        ]);

        $result = (new ConversationModel())->updateLastMessageIfNewer($conversationId, '2026-09-25 10:00:00', 'outgoing');

        $this->assertFalse($result);

        $row = $this->row($conversationId);
        $this->assertSame('2026-09-25 10:00:00', $row['last_message_at']);
        $this->assertSame('incoming', $row['last_message_direction']);
    }

    public function testAcceptsFirstWriteWhenStoredIsNull(): void
    {
        $conversationId = $this->seedConversation([
            'last_message_at'        => null,
            'last_message_direction' => null,
        ]);

        $result = (new ConversationModel())->updateLastMessageIfNewer($conversationId, '2026-09-25 10:00:00', 'incoming');

        $this->assertTrue($result);

        $row = $this->row($conversationId);
        $this->assertSame('2026-09-25 10:00:00', $row['last_message_at']);
        $this->assertSame('incoming', $row['last_message_direction']);
    }

    public function testOtherFieldsWrittenOnRejectedBranch(): void
    {
        $conversationId = $this->seedConversation([
            'last_message_at'        => '2026-09-25 10:00:00',
            'last_message_direction' => 'incoming',
            'status'                 => 'closed',
        ]);

        $result = (new ConversationModel())->updateLastMessageIfNewer(
            $conversationId,
            '2026-09-25 09:59:00',
            'incoming',
            ['status' => 'open']
        );

        $this->assertFalse($result);

        $row = $this->row($conversationId);
        $this->assertSame('open', $row['status']);
        $this->assertSame('2026-09-25 10:00:00', $row['last_message_at']);
        $this->assertSame('incoming', $row['last_message_direction']);
    }

    public function testOtherFieldsWrittenOnAppliedBranch(): void
    {
        $conversationId = $this->seedConversation([
            'last_message_at'        => '2026-09-25 10:00:00',
            'last_message_direction' => 'incoming',
            'status'                 => 'closed',
        ]);

        $result = (new ConversationModel())->updateLastMessageIfNewer(
            $conversationId,
            '2026-09-25 10:01:00',
            'incoming',
            ['status' => 'open']
        );

        $this->assertTrue($result);

        $row = $this->row($conversationId);
        $this->assertSame('open', $row['status']);
        $this->assertSame('2026-09-25 10:01:00', $row['last_message_at']);
    }

    public function testEmptyOtherFieldsSkipsSecondUpdate(): void
    {
        $conversationId = $this->seedConversation([
            'last_message_at'        => '2026-09-25 10:00:00',
            'last_message_direction' => 'incoming',
        ]);

        $result = (new ConversationModel())->updateLastMessageIfNewer($conversationId, '2026-09-25 10:01:00', 'outgoing', []);

        $this->assertTrue($result);

        $row = $this->row($conversationId);
        $this->assertSame('2026-09-25 10:01:00', $row['last_message_at']);
        $this->assertSame('outgoing', $row['last_message_direction']);
    }

    public function testBumpsUpdatedAtOnAppliedBranch(): void
    {
        $conversationId = $this->seedConversation([
            'last_message_at'        => '2026-09-25 10:00:00',
            'last_message_direction' => 'incoming',
            'updated_at'             => '2020-01-01 00:00:00',
        ]);

        $before = $this->row($conversationId)['updated_at'];
        $this->assertSame('2020-01-01 00:00:00', $before);

        (new ConversationModel())->updateLastMessageIfNewer($conversationId, '2026-09-25 10:01:00', 'outgoing');

        $after = $this->row($conversationId)['updated_at'];
        $this->assertGreaterThan($before, $after);
    }

    public function testFullyRejectedCallTouchesNoRow(): void
    {
        $conversationId = $this->seedConversation([
            'last_message_at'        => '2026-09-25 10:00:00',
            'last_message_direction' => 'incoming',
            'updated_at'             => '2020-01-01 00:00:00',
        ]);

        $result = (new ConversationModel())->updateLastMessageIfNewer($conversationId, '2026-09-25 09:00:00', 'outgoing', []);

        $this->assertFalse($result);

        $row = $this->row($conversationId);
        $this->assertSame('2026-09-25 10:00:00', $row['last_message_at']);
        $this->assertSame('incoming', $row['last_message_direction']);
        $this->assertSame('2020-01-01 00:00:00', $row['updated_at']);
    }
}
