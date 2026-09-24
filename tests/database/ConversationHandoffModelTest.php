<?php

use App\Models\ConversationHandoffModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * M3 Phase 2a (TB-01, TASK-002) -- ConversationHandoffModel contract
 * (Spec Section 4.2): insertHandoff (incl. NULL from for K-06) and
 * forConversation (newest-first, cap 50).
 *
 * Runs against the `inbox` group, redirected to `aulia_inboxdb_test`
 * under testing, same as the migration test and the session tests.
 *
 * @internal
 */
final class ConversationHandoffModelTest extends CIUnitTestCase
{
    private $inbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inbox = db_connect('inbox');
        $this->inbox->table('conversation_handoffs')->emptyTable();
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

    private function baseRow(int $conversationId): array
    {
        return [
            'conversation_id'      => $conversationId,
            'from_user_id'         => 7,
            'to_user_id'           => 8,
            'initiated_by_user_id' => 7,
            'summary'              => 'Serah terima percakapan Pak Budi.',
            'next_action'          => 'Konfirmasi ulang pesanan meja 3.',
            'note'                 => 'Catatan tambahan.',
            'created_at'           => date('Y-m-d H:i:s'),
        ];
    }

    public function testInsertHandoffReturnsNewIdAndPersistsRow(): void
    {
        $conversationId = $this->seedConversation();

        $id = (new ConversationHandoffModel())->insertHandoff($this->baseRow($conversationId));

        $this->assertGreaterThan(0, $id);

        $row = $this->inbox->table('conversation_handoffs')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        $this->assertSame($conversationId, (int) $row['conversation_id']);
        $this->assertSame(7, (int) $row['from_user_id']);
        $this->assertSame(8, (int) $row['to_user_id']);
        $this->assertSame(7, (int) $row['initiated_by_user_id']);
        $this->assertSame('Serah terima percakapan Pak Budi.', $row['summary']);
        $this->assertSame('Konfirmasi ulang pesanan meja 3.', $row['next_action']);
        $this->assertSame('Catatan tambahan.', $row['note']);
        $this->assertNotEmpty($row['created_at']);
    }

    public function testInsertHandoffAllowsNullFromUserForUnassignedConversation(): void
    {
        // K-06: percakapan sebelumnya belum diambil (assigned_to NULL)
        // -> from_user_id ikut NULL, initiated_by tetap id sesi.
        $conversationId = $this->seedConversation();

        $row = $this->baseRow($conversationId);
        $row['from_user_id'] = null;

        $id = (new ConversationHandoffModel())->insertHandoff($row);

        $saved = $this->inbox->table('conversation_handoffs')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        $this->assertNull($saved['from_user_id']);
        $this->assertSame(7, (int) $saved['initiated_by_user_id']);
    }

    public function testForConversationReturnsNewestFirst(): void
    {
        $conversationId = $this->seedConversation();
        $model = new ConversationHandoffModel();

        $firstId = $model->insertHandoff($this->baseRow($conversationId));
        usleep(1000); // keep created_at/order deterministic even without auto timestamps
        $secondRow = $this->baseRow($conversationId);
        $secondRow['summary'] = 'Handoff kedua.';
        $secondId = $model->insertHandoff($secondRow);
        usleep(1000);
        $thirdRow = $this->baseRow($conversationId);
        $thirdRow['summary'] = 'Handoff ketiga.';
        $thirdId = $model->insertHandoff($thirdRow);

        $rows = $model->forConversation($conversationId);

        $this->assertCount(3, $rows);
        // numberNative=false => ids arrive as strings; compare as ints.
        $this->assertSame(
            [$thirdId, $secondId, $firstId],
            array_map('intval', array_column($rows, 'id'))
        );
        $this->assertSame('Handoff ketiga.', $rows[0]['summary']);
    }

    public function testForConversationCapsAtFiftyRows(): void
    {
        $conversationId = $this->seedConversation();
        $otherConversationId = $this->seedConversation();
        $model = new ConversationHandoffModel();

        for ($i = 1; $i <= 55; $i++) {
            $row = $this->baseRow($conversationId);
            $row['summary'] = 'Handoff #' . $i;
            $model->insertHandoff($row);
        }

        // Rows belonging to another conversation must never leak in.
        $model->insertHandoff($this->baseRow($otherConversationId));

        $rows = $model->forConversation($conversationId);

        $this->assertCount(50, $rows, 'forConversation must cap at 50 rows.');
        // Newest-first: newest is #55, oldest of the window is #6.
        $this->assertSame('Handoff #55', $rows[0]['summary']);
        $this->assertSame('Handoff #6', $rows[49]['summary']);

        $this->assertCount(1, $model->forConversation($otherConversationId));
    }

    public function testForConversationReturnsEmptyArrayWhenNoHistory(): void
    {
        $conversationId = $this->seedConversation();

        $rows = (new ConversationHandoffModel())->forConversation($conversationId);

        $this->assertSame([], $rows);
    }

    public function testForConversationHonoursCustomLimit(): void
    {
        $conversationId = $this->seedConversation();
        $model = new ConversationHandoffModel();

        for ($i = 1; $i <= 5; $i++) {
            $model->insertHandoff($this->baseRow($conversationId));
        }

        $rows = $model->forConversation($conversationId, 3);

        $this->assertCount(3, $rows);
    }

    public function testInsertHandoffAllowsNullNote(): void
    {
        $conversationId = $this->seedConversation();

        $row = $this->baseRow($conversationId);
        $row['note'] = null;

        $id = (new ConversationHandoffModel())->insertHandoff($row);

        $saved = $this->inbox->table('conversation_handoffs')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        $this->assertNull($saved['note']);
    }
}
