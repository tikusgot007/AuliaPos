<?php

use App\Database\Migrations\AddGroupNameToConversations;
use App\Models\ConversationModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Grup Tahap 2 / TASK-001 (spec-design-grup-tahap2-identitas v1.3,
 * section 4.2; CON-002) -- schema contract for the additive
 * `conversations.group_name` column plus the ConversationModel
 * write/read round-trip.
 *
 * Runs against the `inbox` group, redirected to `aulia_inboxdb_test`
 * under testing (a schema copy of the migrated Inbox database). The
 * up()/down() round-trip proves the migration artifact itself
 * reproduces the schema.
 *
 * @internal
 */
final class ConversationModelGroupNameTest extends CIUnitTestCase
{
    private const MIGRATION_FILE = '2026-09-26-000001_AddGroupNameToConversations.php';

    private $inbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inbox = db_connect('inbox');
        $this->inbox->table('messages')->emptyTable();
        $this->inbox->table('conversation_identities')->emptyTable();
        $this->inbox->table('conversations')->emptyTable();
    }

    /** Satu kolom dari information_schema, atau null bila tidak ada. */
    private function columnInfo(string $column): ?array
    {
        return $this->inbox->table('information_schema.COLUMNS')
            ->select('DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_TYPE')
            ->where('TABLE_SCHEMA', $this->inbox->database)
            ->where('TABLE_NAME', 'conversations')
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

    public function testKolomGroupNameAdaSebagaiVarchar255Nullable(): void
    {
        $column = $this->columnInfo('group_name');

        $this->assertNotNull($column, 'conversations.group_name must exist (CON-002).');
        $this->assertSame('varchar', $column['DATA_TYPE']);
        $this->assertSame(255, (int) $column['CHARACTER_MAXIMUM_LENGTH'], 'CON-002: VARCHAR(255).');
        $this->assertSame('YES', $column['IS_NULLABLE'], 'Column must stay NULLable.');
        $this->assertSame('varchar(255)', $column['COLUMN_TYPE']);
    }

    public function testModelDapatMenulisDanMembacaKembaliGroupName(): void
    {
        $id = $this->seedConversation();

        $model = new ConversationModel();
        $this->assertTrue($model->update($id, ['group_name' => 'Grup Jualan Online']));

        $row = $model->find($id);
        $this->assertSame('Grup Jualan Online', $row['group_name']);
    }

    public function testKolomLamaTidakBerubahDanTanpaForeignKey(): void
    {
        // CON-002: the additive migration must not change whatsapp_name /
        // contact_name, and must not introduce a foreign key.
        $this->assertSame('YES', $this->columnInfo('whatsapp_name')['IS_NULLABLE'], 'whatsapp_name stays nullable.');
        $this->assertSame('YES', $this->columnInfo('contact_name')['IS_NULLABLE'], 'contact_name stays nullable.');

        $fkCount = $this->inbox->query(
            'SELECT COUNT(*) AS total FROM information_schema.KEY_COLUMN_USAGE'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$this->inbox->database, 'conversations', 'group_name']
        )->getRowArray();

        $this->assertSame(0, (int) $fkCount['total'], 'No foreign key may be added (CON-002).');
    }

    public function testUpDownRoundTripMemulihkanKolom(): void
    {
        $this->assertNotNull(
            $this->columnInfo('group_name'),
            'Precondition: the test database already carries the column.'
        );

        // Migrations are excluded from the composer classmap
        // (exclude-from-classmap **/Database/Migrations/**), so load the
        // class file explicitly before instantiating it.
        require_once APPPATH . 'Database/Migrations/' . self::MIGRATION_FILE;
        $migration = new AddGroupNameToConversations();

        try {
            $migration->down();
            $this->assertNull($this->columnInfo('group_name'), 'down() must drop the column.');

            $migration->up();
            $column = $this->columnInfo('group_name');
            $this->assertNotNull($column, 'up() must recreate the column.');
            $this->assertSame('varchar(255)', $column['COLUMN_TYPE']);
            $this->assertSame('YES', $column['IS_NULLABLE']);
        } finally {
            // Never leave the shared test schema incomplete.
            if ($this->columnInfo('group_name') === null) {
                $migration->up();
            }
        }
    }
}
