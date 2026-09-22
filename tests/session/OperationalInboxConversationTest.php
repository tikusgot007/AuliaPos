<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * M3 TASK-011 -- conversation API filters and payload contract.
 *
 * Covers:
 * - status filter matches computed queue_status;
 * - q filter matches contact_name / phone after compute;
 * - empty q behaves as no filter;
 * - message thread exposes is_internal as a boolean.
 *
 * @internal
 */
final class OperationalInboxConversationTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    protected $namespace = 'Tests\\Support';
    protected $migrate = true;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect('inbox');
        $db->table('messages')->emptyTable();
        $db->table('conversation_identities')->emptyTable();
        $db->table('conversations')->emptyTable();
    }

    private function sesi(): array
    {
        return [
            'isLoggedIn' => true,
            'role' => 'kasir',
            'id_user' => 7,
            'nama' => 'Test Kasir',
            'last_activity' => time(),
        ];
    }

    private function seedConversation(array $override = []): int
    {
        $db = db_connect('inbox');
        $now = '2026-09-22 14:00:00';

        $db->table('conversations')->insert(array_merge([
            'chat_id' => '628' . random_int(100000000, 999999999) . '@s.whatsapp.net',
            'jid_type' => 'pn',
            'contact_name' => null,
            'phone' => null,
            'status' => 'open',
            'assigned_to' => 7,
            'last_seen_by_assignee_at' => null,
            'snoozed_until' => null,
            'last_message_at' => $now,
            'last_message_direction' => 'incoming',
            'created_at' => $now,
            'updated_at' => $now,
        ], $override));

        return (int) $db->insertID();
    }

    public function testStatusFilterMemakaiQueueStatusComputed(): void
    {
        $belum = $this->seedConversation([
            'assigned_to' => null,
            'last_message_direction' => 'incoming',
            'last_seen_by_assignee_at' => null,
        ]);
        $selesai = $this->seedConversation([
            'status' => 'closed',
            'contact_name' => 'Closed Customer',
        ]);

        $res = $this->withSession($this->sesi())
            ->get('inbox/api/conversations?status=selesai');

        $res->assertOK();
        $data = $res->getJSON(true);

        $this->assertSame('success', $data['status']);
        $this->assertCount(1, $data['conversations']);
        $this->assertSame($selesai, (int) $data['conversations'][0]['id']);
        $this->assertSame('selesai', $data['conversations'][0]['queue_status']);
        $this->assertArrayHasKey('sla_color', $data['conversations'][0]);
        $this->assertNull($data['conversations'][0]['sla_color']);
        $this->assertNotSame($belum, (int) $data['conversations'][0]['id']);
    }

    public function testQFilterCocokContactNameDanPhone(): void
    {
        $byName = $this->seedConversation([
            'contact_name' => 'Budi Surabaya',
            'phone' => '628123450001',
        ]);
        $byPhone = $this->seedConversation([
            'contact_name' => 'Customer Lain',
            'phone' => '628123459999',
        ]);

        $resName = $this->withSession($this->sesi())
            ->get('inbox/api/conversations?q=Surabaya');
        $nameData = $resName->getJSON(true);

        $this->assertSame('success', $nameData['status']);
        $this->assertCount(1, $nameData['conversations']);
        $this->assertSame($byName, (int) $nameData['conversations'][0]['id']);

        $resPhone = $this->withSession($this->sesi())
            ->get('inbox/api/conversations?q=9999');
        $phoneData = $resPhone->getJSON(true);

        $this->assertSame('success', $phoneData['status']);
        $this->assertCount(1, $phoneData['conversations']);
        $this->assertSame($byPhone, (int) $phoneData['conversations'][0]['id']);
    }

    public function testQKosongTidakMemfilter(): void
    {
        $first = $this->seedConversation(['contact_name' => 'Alpha']);
        $second = $this->seedConversation(['contact_name' => 'Beta']);

        $res = $this->withSession($this->sesi())
            ->get('inbox/api/conversations?q=');
        $data = $res->getJSON(true);

        $this->assertSame('success', $data['status']);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $data['conversations']);

        $this->assertContains($first, $ids);
        $this->assertContains($second, $ids);
    }

    public function testThreadPayloadMembawaIsInternalSebagaiBoolean(): void
    {
        $conversationId = $this->seedConversation();

        $db = db_connect('inbox');
        $now = '2026-09-22 14:00:00';
        $db->table('messages')->insert([
            'conversation_id' => $conversationId,
            'wa_message_id' => 'thread-normal-' . bin2hex(random_bytes(4)),
            'direction' => 'incoming',
            'message_type' => 'text',
            'sender_jid' => '628123450000@s.whatsapp.net',
            'text' => 'Pesan customer',
            'message_timestamp' => $now,
            'send_status' => 'received',
            'is_internal' => false,
            'created_at' => $now,
        ]);
        $db->table('messages')->insert([
            'conversation_id' => $conversationId,
            'wa_message_id' => 'thread-internal-' . bin2hex(random_bytes(4)),
            'direction' => 'outgoing',
            'message_type' => 'text',
            'sender_jid' => null,
            'text' => 'Catatan staff',
            'message_timestamp' => $now,
            'sent_by_user_id' => 7,
            'send_status' => 'sent',
            'is_internal' => true,
            'created_at' => $now,
        ]);

        $res = $this->withSession($this->sesi())
            ->get('inbox/api/conversations/' . $conversationId . '/messages');

        $res->assertOK();
        $data = $res->getJSON(true);

        $this->assertSame('success', $data['status']);
        $this->assertCount(2, $data['messages']);
        $this->assertFalse($data['messages'][0]['is_internal']);
        $this->assertTrue($data['messages'][1]['is_internal']);
        $this->assertArrayHasKey('queue_status', $data['conversation']);
    }
}
