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

    public function testWithComputedStatusTreatsExpiredSnoozeAsActiveQueueState(): void
    {
        $model = new ConversationModel();

        $result = $model->withComputedStatus([
            [
                'id' => 20,
                'status' => 'open',
                'assigned_to' => 9,
                'last_message_direction' => 'incoming',
                'last_message_at' => '2026-01-01 10:00:00',
                'last_seen_by_assignee_at' => '2026-01-01 10:00:00',
                'snoozed_until' => '2020-01-01 10:00:00',
            ],
            [
                'id' => 21,
                'status' => 'open',
                'assigned_to' => 9,
                'last_message_direction' => 'outgoing',
                'last_message_at' => '2026-01-01 10:00:00',
                'last_seen_by_assignee_at' => '2026-01-01 10:00:00',
                'snoozed_until' => null,
            ],
        ]);

        $this->assertSame(
            ['menunggu', 'menunggu'],
            array_column($result, 'queue_status')
        );
        $this->assertSame(
            ['menunggu_customer', 'menunggu_customer'],
            array_column($result, 'response_state')
        );
    }

    public function testWithComputedStatusGivesClosedStatePriorityOverSnoozeAndAssignment(): void
    {
        $model = new ConversationModel();

        $result = $model->withComputedStatus([
            [
                'id' => 30,
                'status' => 'closed',
                'assigned_to' => null,
                'last_message_direction' => 'incoming',
                'last_message_at' => '2099-01-01 10:00:00',
                'last_seen_by_assignee_at' => null,
                'snoozed_until' => '2099-12-31 23:59:59',
            ],
        ]);

        $this->assertSame('selesai', $result[0]['queue_status']);
        $this->assertSame('selesai', $result[0]['response_state']);
    }

    public function testWithComputedStatusTreatsIncomingMessageAsWaitingWhenSeenAtIsEqualToLastMessage(): void
    {
        $model = new ConversationModel();

        $result = $model->withComputedStatus([
            [
                'id' => 31,
                'status' => 'open',
                'assigned_to' => 11,
                'last_message_direction' => 'incoming',
                'last_message_at' => '2026-01-01 10:00:00',
                'last_seen_by_assignee_at' => '2026-01-01 10:00:00',
                'snoozed_until' => null,
            ],
        ]);

        $this->assertSame('menunggu', $result[0]['queue_status']);
        $this->assertSame('menunggu_customer', $result[0]['response_state']);
    }

    public function testWithComputedStatusPreservesInputAndOnlyAddsComputedFields(): void
    {
        $model = new ConversationModel();

        $input = [
            [
                'id' => 40,
                'status' => 'open',
                'assigned_to' => null,
                'last_message_direction' => null,
                'last_message_at' => null,
                'last_seen_by_assignee_at' => null,
                'snoozed_until' => null,
                'custom_field' => 'keep-me',
            ],
        ];

        $result = $model->withComputedStatus($input);

        $this->assertSame('keep-me', $result[0]['custom_field']);
        $this->assertArrayHasKey('response_state', $result[0]);
        $this->assertArrayHasKey('queue_status', $result[0]);
        $this->assertSame('perlu_dibalas', $result[0]['response_state']);
        $this->assertSame('belum_diambil', $result[0]['queue_status']);
        $this->assertSame($input, [$result[0]] ? array_intersect_key($result[0], $input[0]) : []);
    }

}
