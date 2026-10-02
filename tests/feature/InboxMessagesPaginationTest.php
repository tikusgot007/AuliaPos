<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * GET /inbox/api/conversations/(:num)/messages: latest-window pagination
 * (docs/requirements/2026-10-02-perbaikan-thread-inbox.md AC-1..AC-3,
 * AC-23..AC-26; design: docs/design/2026-10-02-perbaikan-thread-inbox.md).
 *
 * Page size is Inbox::MESSAGES_PER_PAGE (200). Like the other feature tests it
 * runs against `aulia_inboxdb_test` (see tests/_support/bootstrap-feature.php).
 *
 * @internal
 */
final class InboxMessagesPaginationTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const PAGE = 200;

    private $inbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inbox = db_connect('inbox');
        $this->inbox->table('messages')->emptyTable();
        $this->inbox->table('conversation_identities')->emptyTable();
        $this->inbox->table('conversations')->emptyTable();
    }

    private function seedConversation(string $chatId): int
    {
        $now = date('Y-m-d H:i:s');

        $this->inbox->table('conversations')->insert([
            'chat_id'    => $chatId,
            'jid_type'   => 'pn',
            'status'     => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->inbox->insertID();
    }

    /**
     * Inserts $count messages with strictly increasing timestamps (one second
     * apart) unless $sameSecond is true. Returns the ids in insert order.
     *
     * @return list<int>
     */
    private function seedMessages(int $conversationId, int $count, bool $sameSecond = false, string $prefix = 'M'): array
    {
        $base = strtotime('2026-10-01 08:00:00');
        $ids  = [];

        for ($i = 1; $i <= $count; $i++) {
            $this->inbox->table('messages')->insert([
                'conversation_id'   => $conversationId,
                'wa_message_id'     => $prefix . '-' . $conversationId . '-' . $i,
                'direction'         => 'incoming',
                'message_type'      => 'text',
                'text'              => 'pesan ' . $i,
                'message_timestamp' => date('Y-m-d H:i:s', $sameSecond ? $base : $base + $i),
                'send_status'       => 'received',
                'created_at'        => date('Y-m-d H:i:s'),
            ]);
            $ids[] = (int) $this->inbox->insertID();
        }

        return $ids;
    }

    private function fetchPage(int $conversationId, string $query = '')
    {
        return $this->withSession(['isLoggedIn' => true])
            ->get('/inbox/api/conversations/' . $conversationId . '/messages' . $query);
    }

    /** @return array<string, mixed> */
    private function json($response): array
    {
        return json_decode($response->getJSON(), true);
    }

    /** @return list<int> */
    private function ids(array $json): array
    {
        return array_map(static fn (array $m): int => (int) $m['id'], $json['messages']);
    }

    // AC-1
    public function testMoreThanOnePageReturnsTheLatestMessagesOldestFirst(): void
    {
        $conv = $this->seedConversation('6281200000101@s.whatsapp.net');
        $ids  = $this->seedMessages($conv, self::PAGE + 20);

        $response = $this->fetchPage($conv);
        $response->assertStatus(200);
        $json = $this->json($response);

        $this->assertSame(array_slice($ids, 20), $this->ids($json), 'Must be the 200 newest, ordered old to new.');
        $this->assertTrue($json['has_more']);
    }

    // AC-2 and AC-26
    public function testAtMostOnePageReturnsEverythingWithoutHasMore(): void
    {
        $conv = $this->seedConversation('6281200000102@s.whatsapp.net');
        $ids  = $this->seedMessages($conv, self::PAGE);

        $json = $this->json($this->fetchPage($conv));

        $this->assertSame($ids, $this->ids($json));
        $this->assertFalse($json['has_more'], 'Exactly one full page must not claim more.');
    }

    // AC-3
    public function testResponseKeepsExistingFieldsAndAddsHasMore(): void
    {
        $conv = $this->seedConversation('6281200000103@s.whatsapp.net');
        $this->seedMessages($conv, 3);

        $json = $this->json($this->fetchPage($conv));

        foreach (['status', 'conversation', 'messages', 'has_more'] as $key) {
            $this->assertArrayHasKey($key, $json);
        }
        foreach (['id', 'wa_message_id', 'direction', 'message_type', 'text', 'message_timestamp', 'send_status', 'is_internal', 'is_forwarded', 'sender_name'] as $field) {
            $this->assertArrayHasKey($field, $json['messages'][0]);
        }
    }

    // AC-23
    public function testBeforeIdReturnsTheOlderPageAndWalksToTheStart(): void
    {
        $conv = $this->seedConversation('6281200000104@s.whatsapp.net');
        $ids  = $this->seedMessages($conv, 450);

        $first = $this->json($this->fetchPage($conv));
        $this->assertSame(array_slice($ids, 250), $this->ids($first));

        $second = $this->json($this->fetchPage($conv, '?before_id=' . $this->ids($first)[0]));
        $this->assertSame(array_slice($ids, 50, 200), $this->ids($second));
        $this->assertTrue($second['has_more']);

        $third = $this->json($this->fetchPage($conv, '?before_id=' . $this->ids($second)[0]));
        $this->assertSame(array_slice($ids, 0, 50), $this->ids($third));
        $this->assertFalse($third['has_more']);
    }

    // AC-24
    public function testCursorDoesNotSkipOrDuplicateMessagesWithTheSameTimestamp(): void
    {
        $conv = $this->seedConversation('6281200000105@s.whatsapp.net');
        $ids  = $this->seedMessages($conv, 250, true);

        $first  = $this->json($this->fetchPage($conv));
        $second = $this->json($this->fetchPage($conv, '?before_id=' . $this->ids($first)[0]));

        $this->assertSame(array_slice($ids, 50), $this->ids($first), 'Equal timestamps are ordered by id.');
        $this->assertSame(array_slice($ids, 0, 50), $this->ids($second));
        $this->assertSame($ids, array_merge($this->ids($second), $this->ids($first)));
    }

    // AC-24: a late message (higher id, older timestamp) is positioned by timestamp, not by id
    public function testLateMessageWithOlderTimestampIsPaginatedByTimestamp(): void
    {
        $conv = $this->seedConversation('6281200000106@s.whatsapp.net');
        $ids  = $this->seedMessages($conv, self::PAGE + 5);

        $this->inbox->table('messages')->insert([
            'conversation_id'   => $conv,
            'wa_message_id'     => 'LATE-1',
            'direction'         => 'incoming',
            'message_type'      => 'text',
            'text'              => 'terlambat',
            'message_timestamp' => '2026-10-01 08:00:02', // older than ids[2..]
            'send_status'       => 'received',
            'created_at'        => date('Y-m-d H:i:s'),
        ]);
        $lateId = (int) $this->inbox->insertID();

        $first  = $this->json($this->fetchPage($conv));
        $second = $this->json($this->fetchPage($conv, '?before_id=' . $this->ids($first)[0]));

        $all = array_merge($this->ids($second), $this->ids($first));
        $this->assertSame(count($ids) + 1, count($all));
        $this->assertSame(count($all), count(array_unique($all)), 'No duplicates across pages.');
        $this->assertContains($lateId, $all, 'The late message must not be skipped.');
        // timestamp order: ids[0], ids[1] (08:00:01, :02 by id tie), late, ids[2]...
        $this->assertSame([$ids[0], $ids[1], $lateId, $ids[2]], array_slice($all, 0, 4));
    }

    // AC-25
    public function testInvalidBeforeIdIsRejected(): void
    {
        $conv = $this->seedConversation('6281200000107@s.whatsapp.net');
        $this->seedMessages($conv, 3);

        foreach (['abc', '0', '-5', '1.5', '1e3', '%20'] as $bad) {
            $this->fetchPage($conv, '?before_id=' . $bad)->assertStatus(400);
        }
    }

    // AC-25
    public function testBeforeIdFromAnotherConversationOrMissingIs404(): void
    {
        $convA = $this->seedConversation('6281200000108@s.whatsapp.net');
        $convB = $this->seedConversation('6281200000109@s.whatsapp.net');
        $this->seedMessages($convA, 3, false, 'A');
        $idsB = $this->seedMessages($convB, 3, false, 'B');

        $this->fetchPage($convA, '?before_id=' . $idsB[1])->assertStatus(404);
        $this->fetchPage($convA, '?before_id=999999999')->assertStatus(404);
    }

    // AC-23: an empty before_id behaves like no cursor
    public function testEmptyBeforeIdIsIgnored(): void
    {
        $conv = $this->seedConversation('6281200000110@s.whatsapp.net');
        $ids  = $this->seedMessages($conv, 3);

        $json = $this->json($this->fetchPage($conv, '?before_id='));

        $this->assertSame($ids, $this->ids($json));
    }
}
