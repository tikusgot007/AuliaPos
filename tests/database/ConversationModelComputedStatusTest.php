<?php

use App\Models\ConversationModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ConversationModelComputedStatusTest extends CIUnitTestCase
{
    public function testWithComputedStatusMapsAllQueueStates(): void
    {
        $model = new ConversationModel();

        $conversations = [
            [
                'id' => 1,
                'status' => 'open',
                'assigned_to' => null,
                'last_message_direction' => 'incoming',
                'last_message_at' => '2099-01-01 10:00:00',
                'last_seen_by_assignee_at' => null,
                'snoozed_until' => null,
            ],
            [
                'id' => 2,
                'status' => 'open',
                'assigned_to' => 7,
                'last_message_direction' => 'incoming',
                'last_message_at' => '2099-01-01 10:00:00',
                'last_seen_by_assignee_at' => null,
                'snoozed_until' => null,
            ],
            [
                'id' => 3,
                'status' => 'open',
                'assigned_to' => 7,
                'last_message_direction' => 'incoming',
                'last_message_at' => '2026-01-01 10:00:00',
                'last_seen_by_assignee_at' => '2099-01-01 10:00:00',
                'snoozed_until' => null,
            ],
            [
                'id' => 4,
                'status' => 'open',
                'assigned_to' => 7,
                'last_message_direction' => 'incoming',
                'last_message_at' => '2026-01-01 10:00:00',
                'last_seen_by_assignee_at' => null,
                'snoozed_until' => '2099-01-01 10:00:00',
            ],
            [
                'id' => 5,
                'status' => 'closed',
                'assigned_to' => 7,
                'last_message_direction' => 'outgoing',
                'last_message_at' => '2026-01-01 10:00:00',
                'last_seen_by_assignee_at' => '2026-01-01 10:00:00',
                'snoozed_until' => null,
            ],
        ];

        $result = $model->withComputedStatus($conversations);

        $this->assertSame(
            ['belum_diambil', 'open', 'menunggu', 'ditunda', 'selesai'],
            array_column($result, 'queue_status')
        );
        $this->assertSame(
            ['perlu_dibalas', 'perlu_dibalas', 'menunggu_customer', 'follow_up', 'selesai'],
            array_column($result, 'response_state')
        );
    }

    public function testWithComputedStatusHandlesMissingLastMessageDirection(): void
    {
        $model = new ConversationModel();

        $result = $model->withComputedStatus([
            [
                'id' => 10,
                'status' => 'open',
                'assigned_to' => null,
            ],
            [
                'id' => 11,
                'status' => 'open',
                'assigned_to' => 8,
            ],
        ]);

        $this->assertSame(
            ['belum_diambil', 'open'],
            array_column($result, 'queue_status')
        );
        $this->assertSame(
            ['perlu_dibalas', 'perlu_dibalas'],
            array_column($result, 'response_state')
        );
    }
}
