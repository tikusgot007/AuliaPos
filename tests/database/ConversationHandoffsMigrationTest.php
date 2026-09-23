<?php

use App\Database\Migrations\CreateConversationHandoffs;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * M3 Phase 2a (TB-01, TASK-001) -- schema contract for the additive
 * `conversation_handoffs` migration.
 *
 * Runs against the real Inbox database (group `inbox`) -- the same
 * environment the migration was applied to via `php spark migrate`,
 * mirroring how the session tests already exercise the Inbox schema.
 *
 * @internal
 */
final class ConversationHandoffsMigrationTest extends CIUnitTestCase
{
    /**
     * Column name => [dataType, maxLength|null, nullable, extraContains].
     */
    private const EXPECTED_COLUMNS = [
        'id'                   => ['int', null, false, 'auto_increment'],
        'conversation_id'      => ['bigint', null, false, ''],
        'from_user_id'         => ['int', null, true, ''],
        'to_user_id'           => ['int', null, false, ''],
        'initiated_by_user_id' => ['int', null, false, ''],
        'summary'              => ['varchar', 4096, false, ''],
        'next_action'          => ['varchar', 4096, false, ''],
        'note'                 => ['text', null, true, ''],
        'created_at'           => ['datetime', null, false, ''],
    ];

    private $inbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inbox = db_connect('inbox');
        $this->inbox->table('conversation_handoffs')->emptyTable();
        $this->inbox->table('messages')->emptyTable();
        $this->inbox->table('conversations')->emptyTable();
    }

    private function columnInfo(string $column): array
    {
        return $this->inbox->table('information_schema.COLUMNS')
            ->select('DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, EXTRA')
            ->where('TABLE_SCHEMA', $this->inbox->database)
            ->where('TABLE_NAME', 'conversation_handoffs')
            ->where('COLUMN_NAME', $column)
            ->get()
            ->getRowArray();
    }

    private function seedConversation(array $override = []): int
    {
        $now = date('Y-m-d H:i:s');

        $this->inbox->table('conversations')->insert(array_merge([
            'chat_id'    => '628' . random_int(100000000, 999999999) . '@s.whatsapp.net',
            'jid_type'   => 'pn',
            'status'     => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ], $override));

        return (int) $this->inbox->insertID();
    }

    private function seedHandoff(int $conversationId, array $override = []): int
    {
        $this->inbox->table('conversation_handoffs')->insert(array_merge([
            'conversation_id'      => $conversationId,
            'from_user_id'         => 7,
            'to_user_id'           => 8,
            'initiated_by_user_id' => 7,
            'summary'              => 'Ringkasan handoff.',
            'next_action'          => 'Cek status pesanan.',
            'note'                 => null,
            'created_at'           => date('Y-m-d H:i:s'),
        ], $override));

        return (int) $this->inbox->insertID();
    }

    public function testTableExistsWithContractColumns(): void
    {
        $this->assertTrue(
            $this->inbox->tableExists('conversation_handoffs'),
            'conversation_handoffs must exist on the inbox database.'
        );

        $actual = $this->inbox->table('information_schema.COLUMNS')
            ->select('COLUMN_NAME')
            ->where('TABLE_SCHEMA', $this->inbox->database)
            ->where('TABLE_NAME', 'conversation_handoffs')
            ->orderBy('ORDINAL_POSITION', 'ASC')
            ->get()
            ->getResultArray();

        $actualNames = array_column($actual, 'COLUMN_NAME');
        $this->assertSame(array_keys(self::EXPECTED_COLUMNS), $actualNames);

        foreach (self::EXPECTED_COLUMNS as $column => [$type, $length, $nullable, $extra]) {
            $info = $this->columnInfo($column);
            $this->assertNotNull($info, "Column {$column} is missing.");

            $this->assertSame($type, $info['DATA_TYPE'], "DATA_TYPE of {$column}.");
            if ($length !== null) {
                $this->assertSame(
                    $length,
                    (int) $info['CHARACTER_MAXIMUM_LENGTH'],
                    "CHARACTER_MAXIMUM_LENGTH of {$column}."
                );
            }
            $this->assertSame($nullable ? 'YES' : 'NO', $info['IS_NULLABLE'], "Nullability of {$column}.");
            if ($extra !== '') {
                $this->assertStringContainsString($extra, (string) $info['EXTRA'], "EXTRA of {$column}.");
            }
        }
    }

    public function testUnsignedIntegerTypes(): void
    {
        // All id columns are UNSIGNED (users.id and conversations.id are
        // unsigned/compatible), verified via COLUMN_TYPE which carries
        // the unsigned flag (DATA_TYPE alone does not).
        foreach (['id', 'conversation_id', 'from_user_id', 'to_user_id', 'initiated_by_user_id'] as $column) {
            $columnType = $this->inbox->table('information_schema.COLUMNS')
                ->select('COLUMN_TYPE')
                ->where('TABLE_SCHEMA', $this->inbox->database)
                ->where('TABLE_NAME', 'conversation_handoffs')
                ->where('COLUMN_NAME', $column)
                ->get()
                ->getRowArray()['COLUMN_TYPE'];

            $this->assertStringContainsString('unsigned', $columnType, "COLUMN_TYPE of {$column}.");
        }
    }


    public function testCompositeIndexIsConversationIdThenId(): void
    {
        $rows = $this->inbox->query(
            "SHOW INDEX FROM `conversation_handoffs` WHERE `Key_name` = 'idx_handoffs_conversation'"
        )->getResultArray();

        $this->assertCount(2, $rows, 'idx_handoffs_conversation must be a two-column index.');

        $bySeq = [];
        foreach ($rows as $row) {
            $bySeq[(int) $row['Seq_in_index']] = $row['Column_name'];
        }

        $this->assertSame([1 => 'conversation_id', 2 => 'id'], $bySeq);
    }

    public function testForeignKeyCascadesOnConversationDelete(): void
    {
        $rule = $this->inbox->table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->select('DELETE_RULE, REFERENCED_TABLE_NAME')
            ->where('CONSTRAINT_SCHEMA', $this->inbox->database)
            ->where('TABLE_NAME', 'conversation_handoffs')
            ->get()
            ->getRowArray();

        $this->assertNotNull($rule, 'A foreign key must exist on conversation_handoffs.');
        $this->assertSame('CASCADE', $rule['DELETE_RULE']);
        $this->assertSame('conversations', $rule['REFERENCED_TABLE_NAME']);

        // Behavioural proof: deleting the conversation removes its history.
        $conversationId = $this->seedConversation();
        $this->seedHandoff($conversationId);
        $this->seedHandoff($conversationId, ['from_user_id' => null]);

        $this->assertSame(2, $this->inbox->table('conversation_handoffs')
            ->where('conversation_id', $conversationId)
            ->countAllResults());

        $this->inbox->table('conversations')->where('id', $conversationId)->delete();

        $this->assertSame(0, $this->inbox->table('conversation_handoffs')
            ->where('conversation_id', $conversationId)
            ->countAllResults(), 'Handoff rows must cascade with their conversation.');
    }

    public function testFromUserIdAllowsNullForUnassignedConversations(): void
    {
        $conversationId = $this->seedConversation(['assigned_to' => null]);
        $handoffId = $this->seedHandoff($conversationId, ['from_user_id' => null]);

        $row = $this->inbox->table('conversation_handoffs')
            ->where('id', $handoffId)
            ->get()
            ->getRowArray();

        $this->assertNull($row['from_user_id'], 'from_user_id must be NULL for a previously unassigned conversation.');
    }

    public function testMigrationUpIsIdempotentGuard(): void
    {
        $conversationId = $this->seedConversation();
        $this->seedHandoff($conversationId);

        // Migrations are excluded from the composer classmap
        // (exclude-from-classmap **/Database/Migrations/**), so load
        // the class file explicitly before instantiating it.
        require_once APPPATH . 'Database/Migrations/2026-09-23-000001_CreateConversationHandoffs.php';
        $migration = new CreateConversationHandoffs();

        // Create-only-if-missing guard: re-running up() must be a no-op
        // (no exception, no duplicate index, existing data untouched).
        $migration->up();
        $migration->up();

        // SHOW INDEX returns one row per indexed column; the composite
        // (conversation_id, id) index therefore yields exactly 2 rows.
        // A duplicated index would yield 4 (or up() would have thrown
        // on the duplicate key name).
        $indexCount = $this->inbox->query(
            "SHOW INDEX FROM `conversation_handoffs` WHERE `Key_name` = 'idx_handoffs_conversation'"
        )->getNumRows();
        $this->assertSame(2, $indexCount, 'Re-running up() must not create a duplicate index.');

        $this->assertSame(1, $this->inbox->table('conversation_handoffs')
            ->where('conversation_id', $conversationId)
            ->countAllResults());
    }

    public function testExistingInboxTablesAreNotAltered(): void
    {
        // Additive-only guarantee (CON-H05 / TASK-001): the migration must
        // not add handoff-related columns to the pre-existing tables.
        foreach (['conversations', 'messages'] as $table) {
            $columns = $this->inbox->table('information_schema.COLUMNS')
                ->select('COLUMN_NAME')
                ->where('TABLE_SCHEMA', $this->inbox->database)
                ->where('TABLE_NAME', $table)
                ->get()
                ->getResultArray();

            foreach ($columns as $column) {
                $this->assertStringNotContainsStringIgnoringCase(
                    'handoff',
                    $column['COLUMN_NAME'],
                    "{$table} must not gain handoff columns (additive-only migration)."
                );
            }
        }

        // Sentinel columns from earlier phases must still be present.
        $messageColumns = $this->inbox->table('information_schema.COLUMNS')
            ->select('COLUMN_NAME')
            ->where('TABLE_SCHEMA', $this->inbox->database)
            ->where('TABLE_NAME', 'messages')
            ->get()
            ->getResultArray();
        $messageColumns = array_column($messageColumns, 'COLUMN_NAME');
        $this->assertContains('is_internal', $messageColumns);
        $this->assertContains('deleted_at', $messageColumns);

        $conversationColumns = $this->inbox->table('information_schema.COLUMNS')
            ->select('COLUMN_NAME')
            ->where('TABLE_SCHEMA', $this->inbox->database)
            ->where('TABLE_NAME', 'conversations')
            ->get()
            ->getResultArray();
        $conversationColumns = array_column($conversationColumns, 'COLUMN_NAME');
        $this->assertContains('assigned_to', $conversationColumns);
        $this->assertContains('snoozed_until', $conversationColumns);
    }
}