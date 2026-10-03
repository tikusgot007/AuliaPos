<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * GET /inbox/api/notifikasi-ringkas: ownership filter for the cross-page
 * notification poller (docs/requirements/2026-10-03-notifikasi-inbox-lintas-halaman.md).
 *
 * Runs against `aulia_inboxdb_test` (see tests/_support/bootstrap-feature.php),
 * same as the other feature tests. Not yet run in this session -- no test
 * database is reachable here; see docs/TODO.md (TODO-U2).
 *
 * @internal
 */
final class InboxNotifikasiRingkasTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private $inbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inbox = db_connect('inbox');
        $this->inbox->table('messages')->emptyTable();
        $this->inbox->table('conversation_identities')->emptyTable();
        $this->inbox->table('conversations')->emptyTable();
    }

    /**
     * perlu_dibalas needs last_message_direction='incoming' and
     * last_seen_by_assignee_at older than last_message_at (ConversationModel::withComputedStatus()).
     */
    private function seedConversation(string $chatId, array $override = []): int
    {
        $now = date('Y-m-d H:i:s');

        $this->inbox->table('conversations')->insert(array_merge([
            'chat_id'                  => $chatId,
            'jid_type'                 => 'pn',
            'status'                   => 'open',
            'last_message_direction'   => 'incoming',
            'last_message_at'          => $now,
            'last_seen_by_assignee_at' => null,
            'assigned_to'              => null,
            'contact_name'             => null,
            'created_at'               => $now,
            'updated_at'               => $now,
        ], $override));

        return (int) $this->inbox->insertID();
    }

    private function fetch(int $userId, string $role)
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => $userId, 'role' => $role])
            ->get('/inbox/api/notifikasi-ringkas');
    }

    /** @return list<int> */
    private function ids($response): array
    {
        $json = json_decode($response->getJSON(), true);

        return array_map(static fn (array $c): int => (int) $c['id'], $json['items']);
    }

    public function testKasirSeesUnassignedAndOwnButNotSomeoneElses(): void
    {
        $belumDiambil = $this->seedConversation('6281200000001@s.whatsapp.net');
        $milikSaya    = $this->seedConversation('6281200000002@s.whatsapp.net', ['assigned_to' => 7]);
        $milikOrangLain = $this->seedConversation('6281200000003@s.whatsapp.net', ['assigned_to' => 9]);

        $response = $this->fetch(7, 'kasir');
        $response->assertStatus(200);

        $this->assertEqualsCanonicalizing([$belumDiambil, $milikSaya], $this->ids($response));
        $this->assertStringNotContainsString((string) $milikOrangLain, $response->getJSON());
    }

    public function testAdminSeesEveryoneElsesToo(): void
    {
        $milikOrangLain = $this->seedConversation('6281200000004@s.whatsapp.net', ['assigned_to' => 9]);

        $response = $this->fetch(1, 'admin');

        $this->assertContains($milikOrangLain, $this->ids($response));
    }

    public function testExcludesGroupsClosedAndAlreadyAnsweredConversations(): void
    {
        $grup = $this->seedConversation('120363000000000001@g.us', ['jid_type' => 'group']);
        $closed = $this->seedConversation('6281200000005@s.whatsapp.net', ['status' => 'closed']);
        $now = date('Y-m-d H:i:s');
        $sudahDibalas = $this->seedConversation('6281200000006@s.whatsapp.net', [
            'last_message_direction'   => 'outgoing',
            'last_seen_by_assignee_at' => $now,
        ]);
        $perluDibalas = $this->seedConversation('6281200000007@s.whatsapp.net');

        $response = $this->fetch(7, 'kasir');
        $ids = $this->ids($response);

        $this->assertSame([$perluDibalas], $ids);
        foreach ([$grup, $closed, $sudahDibalas] as $excluded) {
            $this->assertNotContains($excluded, $ids);
        }
    }

    public function testItemCarriesIdLabelLastMessageAtAndAssignedTo(): void
    {
        $conv = $this->seedConversation('6281200000008@s.whatsapp.net', [
            'assigned_to'  => 7,
            'contact_name' => 'Budi',
        ]);

        $response = $this->fetch(7, 'kasir');
        $json = json_decode($response->getJSON(), true);
        $item = $json['items'][0];

        $this->assertSame($conv, $item['id']);
        $this->assertSame('Budi', $item['label']);
        $this->assertSame(7, $item['assigned_to']);
        $this->assertNotEmpty($item['last_message_at']);
    }
}
