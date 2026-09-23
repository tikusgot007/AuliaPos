<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * M3 Phase 1b -- Internal Note.
 *
 * Verifies that internal notes are available to any authenticated
 * staff member, do not change conversation response-state inputs,
 * and are allowed on closed conversations.
 *
 * @internal
 */
final class InboxInternalNoteTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    protected $namespace = 'Tests\\Support';
    protected $migrate   = true;
    protected $refresh   = true;

    private const NOTE_URL = 'inbox/percakapan/';

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect('inbox');
        $db->table('messages')->emptyTable();
        $db->table('conversation_identities')->emptyTable();
        $db->table('conversations')->emptyTable();
    }

    private function sesi(string $role, int $idUser): array
    {
        return [
            'isLoggedIn'    => true,
            'role'          => $role,
            'id_user'       => $idUser,
            'nama'          => 'Test ' . $role,
            'last_activity' => time(),
        ];
    }

    private function seedConversation(array $override = []): int
    {
        $db = db_connect('inbox');
        $now = date('Y-m-d H:i:s');
        $chatId = $override['chat_id']
            ?? ('628' . random_int(100000000, 999999999) . '@s.whatsapp.net');

        $db->table('conversations')->insert(array_merge([
            'chat_id' => $chatId,
            'jid_type' => 'pn',
            'status' => 'open',
            'last_message_direction' => 'incoming',
            'last_message_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $override));

        return (int) $db->insertID();
    }

    private function conversation(int $id): array
    {
        return db_connect('inbox')
            ->table('conversations')
            ->getWhere(['id' => $id])
            ->getRowArray();
    }

    private function internalMessages(int $conversationId): array
    {
        return db_connect('inbox')
            ->table('messages')
            ->where('conversation_id', $conversationId)
            ->where('is_internal', true)
            ->get()
            ->getResultArray();
    }

    public function testStaffBukanAssigneeTetapBisaMenulisCatatan(): void
    {
        $id = $this->seedConversation(['assigned_to' => 99]);

        $response = $this->withSession($this->sesi('kasir', 7))
            ->post(self::NOTE_URL . $id . '/catatan', [
                'teks' => 'Catatan untuk staff berikutnya.',
            ]);

        $response->assertOK();
        $response->assertJSONFragment([
            'status' => 'success',
            'conversation_id' => $id,
        ]);

        $notes = $this->internalMessages($id);
        $this->assertCount(1, $notes);
        $this->assertSame('Catatan untuk staff berikutnya.', $notes[0]['text']);
        $this->assertSame(7, (int) $notes[0]['sent_by_user_id']);
        $this->assertSame('outgoing', $notes[0]['direction']);
        $this->assertSame('sent', $notes[0]['send_status']);
        $this->assertSame(1, (int) $notes[0]['is_internal']);
    }

    public function testCatatanTidakMengubahLastMessageConversation(): void
    {
        $lastMessageAt = '2026-09-22 12:00:00';
        $id = $this->seedConversation([
            'status' => 'open',
            'assigned_to' => 7,
            'last_message_direction' => 'incoming',
            'last_message_at' => $lastMessageAt,
        ]);

        $before = $this->conversation($id);

        $response = $this->withSession($this->sesi('kasir', 7))
            ->post(self::NOTE_URL . $id . '/catatan', [
                'teks' => 'Internal only.',
            ]);

        $response->assertOK();

        $after = $this->conversation($id);
        $this->assertSame($before['last_message_direction'], $after['last_message_direction']);
        $this->assertSame($before['last_message_at'], $after['last_message_at']);
    }

    public function testCatatanPadaClosedTetapBerhasil(): void
    {
        $id = $this->seedConversation([
            'status' => 'closed',
            'assigned_to' => 42,
        ]);

        $response = $this->withSession($this->sesi('kasir', 7))
            ->post(self::NOTE_URL . $id . '/catatan', [
                'teks' => 'Catatan setelah percakapan selesai.',
            ]);

        $response->assertOK();

        $notes = $this->internalMessages($id);
        $this->assertCount(1, $notes);
        $this->assertSame('Catatan setelah percakapan selesai.', $notes[0]['text']);
        $this->assertSame('closed', $this->conversation($id)['status']);
    }

    public function testTeksKosongDitolak(): void
    {
        $id = $this->seedConversation();

        $response = $this->withSession($this->sesi('kasir', 7))
            ->post(self::NOTE_URL . $id . '/catatan', [
                'teks' => '   ',
            ]);

        $response->assertStatus(400);
        $response->assertJSONFragment(['status' => 'error']);
        $this->assertCount(0, $this->internalMessages($id));
    }
}
