<?php

use App\Database\Migrations\AddGatewayOperationIdToMessages;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * M1 Wave 2 (TASK-016) -- schema contract for the additive
 * `messages.gateway_operation_id` column and its UNIQUE index
 * (CON-009, spec 4.7, AC-041 schema part).
 *
 * Runs against the `inbox` group, redirected to `aulia_inboxdb_test`
 * under testing (a schema copy of the migrated Inbox database). That
 * copy carries the same DDL the migration produces; the up()/down()
 * round-trip test below proves the migration artifact itself reproduces
 * the schema, because `php spark migrate` cannot build this database
 * (migration history lives in the `default` database -- F-02).
 *
 * @internal
 */
final class GatewayOperationIdMigrationTest extends CIUnitTestCase
{
    private const MIGRATION_FILE = '2026-09-24-000001_AddGatewayOperationIdToMessages.php';
    private const INDEX_NAME     = 'uniq_messages_gateway_operation_id';

    private $inbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inbox = db_connect('inbox');
        $this->inbox->table('messages')->emptyTable();
        $this->inbox->table('conversations')->emptyTable();
    }

    /** Satu kolom dari information_schema, atau null bila tidak ada. */
    private function columnInfo(string $column): ?array
    {
        return $this->inbox->table('information_schema.COLUMNS')
            ->select('DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_TYPE')
            ->where('TABLE_SCHEMA', $this->inbox->database)
            ->where('TABLE_NAME', 'messages')
            ->where('COLUMN_NAME', $column)
            ->get()
            ->getRowArray();
    }

    /** Baris indeks UNIQUE gateway_operation_id, atau null bila tidak ada. */
    private function uniqueIndexRow(): ?array
    {
        return $this->inbox->table('information_schema.STATISTICS')
            ->select('NON_UNIQUE, COLUMN_NAME')
            ->where('TABLE_SCHEMA', $this->inbox->database)
            ->where('TABLE_NAME', 'messages')
            ->where('INDEX_NAME', self::INDEX_NAME)
            ->get()
            ->getRowArray();
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

    private function seedMessage(int $conversationId, ?string $gatewayOperationId): void
    {
        $this->inbox->table('messages')->insert([
            'conversation_id'      => $conversationId,
            'wa_message_id'        => 'local-' . bin2hex(random_bytes(8)),
            'direction'            => 'outgoing',
            'message_type'         => 'text',
            'text'                 => 'pesan uji',
            'message_timestamp'    => date('Y-m-d H:i:s'),
            'send_status'          => 'sent',
            'gateway_operation_id' => $gatewayOperationId,
        ]);
    }

    public function testKolomGatewayOperationIdAdaSebagaiVarchar64Nullable(): void
    {
        $column = $this->columnInfo('gateway_operation_id');

        $this->assertNotNull($column, 'messages.gateway_operation_id must exist (TASK-016).');
        $this->assertSame('varchar', $column['DATA_TYPE']);
        $this->assertSame(64, (int) $column['CHARACTER_MAXIMUM_LENGTH'], 'Width 64 = REQ-020 limit (R-3).');
        $this->assertSame('YES', $column['IS_NULLABLE'], 'Column must stay NULLable (CON-007/CON-009).');
        $this->assertSame('varchar(64)', $column['COLUMN_TYPE']);
    }

    public function testIndeksUniqueTerpasangPadaKolomGatewayOperationId(): void
    {
        $index = $this->uniqueIndexRow();

        $this->assertNotNull($index, 'UNIQUE index ' . self::INDEX_NAME . ' must exist.');
        $this->assertSame(0, (int) $index['NON_UNIQUE'], 'The index must be UNIQUE.');
        $this->assertSame('gateway_operation_id', $index['COLUMN_NAME']);
    }

    public function testKolomLamaTidakBerubah(): void
    {
        // CON-009: the additive migration must not change the meaning of
        // the pre-existing send bookkeeping columns.
        $waMessageId = $this->columnInfo('wa_message_id');
        $this->assertSame('varchar', $waMessageId['DATA_TYPE']);
        $this->assertSame(255, (int) $waMessageId['CHARACTER_MAXIMUM_LENGTH']);
        $this->assertSame('NO', $waMessageId['IS_NULLABLE']);

        $sendStatus = $this->columnInfo('send_status');
        $this->assertSame("enum('received','sent','failed')", $sendStatus['COLUMN_TYPE']);
        $this->assertSame('NO', $sendStatus['IS_NULLABLE']);

        // Defensive: the additive column must not introduce a foreign key.
        $fkCount = $this->inbox->query(
            'SELECT COUNT(*) AS total FROM information_schema.KEY_COLUMN_USAGE'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$this->inbox->database, 'messages', 'gateway_operation_id']
        )->getRowArray();

        $this->assertSame(0, (int) $fkCount['total'], 'No foreign key may be added (TASK-016).');
    }

    public function testDuplikatNonNulDitolakIndeksUnique(): void
    {
        $conversationId = $this->seedConversation();
        $operationId    = 'op-' . bin2hex(random_bytes(6));

        $this->seedMessage($conversationId, $operationId);

        try {
            $this->seedMessage($conversationId, $operationId);
            $this->fail('A second row with the same gateway_operation_id must be rejected.');
        } catch (DatabaseException $e) {
            // Expected: UNIQUE constraint violation (AC-041).
        }

        $this->assertSame(
            1,
            $this->inbox->table('messages')->where('gateway_operation_id', $operationId)->countAllResults(),
            'Only one row may carry a given gateway_operation_id (AC-041).'
        );
    }

    public function testBanyakNilaiNullTetapDiterima(): void
    {
        // AC-041: incoming rows (and any request without operation_id)
        // keep gateway_operation_id = NULL, and MySQL accepts many NULLs
        // on a UNIQUE index.
        $conversationId = $this->seedConversation();

        $this->seedMessage($conversationId, null);
        $this->seedMessage($conversationId, null);
        $this->seedMessage($conversationId, null);

        $this->assertSame(
            3,
            $this->inbox->table('messages')->where('gateway_operation_id', null)->countAllResults(),
            'Multiple NULL gateway_operation_id rows must be accepted (AC-041).'
        );
    }

    public function testUpDownRoundTripMemulihkanKolomDanIndeks(): void
    {
        $this->assertNotNull(
            $this->columnInfo('gateway_operation_id'),
            'Precondition: the test database already carries the column.'
        );

        // Migrations are excluded from the composer classmap
        // (exclude-from-classmap **/Database/Migrations/**), so load the
        // class file explicitly before instantiating it.
        require_once APPPATH . 'Database/Migrations/' . self::MIGRATION_FILE;
        $migration = new AddGatewayOperationIdToMessages();

        try {
            // RISK-012 rollback: index goes first, then the column.
            $migration->down();
            $this->assertNull($this->columnInfo('gateway_operation_id'), 'down() must drop the column.');
            $this->assertNull($this->uniqueIndexRow(), 'down() must drop the UNIQUE index.');

            $migration->up();
            $column = $this->columnInfo('gateway_operation_id');
            $this->assertSame('varchar(64)', $column['COLUMN_TYPE'], 'up() must recreate the column.');
            $this->assertSame('YES', $column['IS_NULLABLE']);
            $this->assertNotNull($this->uniqueIndexRow(), 'up() must recreate the UNIQUE index.');
            $this->assertSame(0, (int) $this->uniqueIndexRow()['NON_UNIQUE']);
        } finally {
            // Never leave the shared test schema incomplete, even if an
            // assertion above failed.
            if ($this->columnInfo('gateway_operation_id') === null || $this->uniqueIndexRow() === null) {
                $migration->up();
            }
        }
    }
}

