<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * M3 Phase 1b -- Snooze reason (TASK-012, REQ-011, AC-007).
 *
 * The Snooze dialog runs two requests: POST /snooze, then (only when a
 * reason is filled) POST /catatan with the reason. These tests replay
 * that sequence at the HTTP boundary.
 *
 * @internal
 */
final class InboxSnoozeAlasanTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    protected $namespace = 'Tests\\Support';
    protected $migrate   = true;
    protected $refresh   = true;

    private const URL = 'inbox/percakapan/';

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect('inbox');
        $db->table('messages')->emptyTable();
        $db->table('conversation_identities')->emptyTable();
        $db->table('conversations')->emptyTable();
    }

    private function sesi(int $idUser): array
    {
        return [
            'isLoggedIn'    => true,
            'role'          => 'kasir',
            'id_user'       => $idUser,
            'nama'          => 'Test kasir',
            'last_activity' => time(),
        ];
    }

    private function seedConversation(int $assignedTo): int
    {
        $db = db_connect('inbox');
        $now = date('Y-m-d H:i:s');

        $db->table('conversations')->insert([
            'chat_id'                => '628' . random_int(100000000, 999999999) . '@s.whatsapp.net',
            'jid_type'               => 'pn',
            'status'                 => 'open',
            'assigned_to'            => $assignedTo,
            'last_message_direction' => 'incoming',
            'last_message_at'        => $now,
            'created_at'             => $now,
            'updated_at'             => $now,
        ]);

        return (int) $db->insertID();
    }

    private function snooze(int $conversationId, int $menit)
    {
        return $this->withSession($this->sesi(7))
            ->withBodyFormat('json')
            ->post(self::URL . $conversationId . '/snooze', ['menit' => $menit]);
    }

    private function simpanAlasan(int $conversationId, string $alasan)
    {
        return $this->withSession($this->sesi(7))
            ->withBodyFormat('')
            ->post(self::URL . $conversationId . '/catatan', ['teks' => $alasan]);
    }

    private function snoozedUntil(int $conversationId): ?string
    {
        return db_connect('inbox')->table('conversations')
            ->getWhere(['id' => $conversationId])->getRowArray()['snoozed_until'];
    }

    private function messages(int $conversationId): array
    {
        return db_connect('inbox')->table('messages')
            ->where('conversation_id', $conversationId)
            ->get()->getResultArray();
    }

    public function testSnoozeDenganAlasanMenyimpanSatuInternalNote(): void
    {
        $id = $this->seedConversation(7);

        $this->snooze($id, 60)->assertOK();
        $this->simpanAlasan($id, 'Customer minta dihubungi setelah gajian.')->assertOK();

        $this->assertNotNull($this->snoozedUntil($id));

        $messages = $this->messages($id);
        $this->assertCount(1, $messages);
        $this->assertSame(1, (int) $messages[0]['is_internal']);
        $this->assertSame('Customer minta dihubungi setelah gajian.', $messages[0]['text']);

        $this->assertFalse(
            db_connect('inbox')->fieldExists('snooze_reason', 'conversations'),
            'REQ-011: the reason must live in an Internal Note, not a snooze_reason column.'
        );
    }

    public function testSnoozeTanpaAlasanTidakMembuatInternalNote(): void
    {
        $id = $this->seedConversation(7);

        $this->snooze($id, 60)->assertOK();

        $this->assertNotNull($this->snoozedUntil($id));
        $this->assertCount(0, $this->messages($id));
    }

    public function testAlasanGagalDisimpanTidakMembatalkanSnooze(): void
    {
        $id = $this->seedConversation(7);

        $this->snooze($id, 60)->assertOK();
        $snoozedUntil = $this->snoozedUntil($id);

        // Second call fails (over the 4096 limit): the snooze stays saved.
        $this->simpanAlasan($id, str_repeat('a', 4097))->assertStatus(400);

        $this->assertNotNull($snoozedUntil);
        $this->assertSame($snoozedUntil, $this->snoozedUntil($id));
        $this->assertCount(0, $this->messages($id));
    }
}
