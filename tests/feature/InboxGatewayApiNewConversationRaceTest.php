<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\GatewayApiTestTrait;

/**
 * Regression test for TODO-I4: two concurrent deliveries for a BRAND NEW
 * (never-seen) chat_id must never lose a message with a 500. The reported
 * symptom (docs/TODO.md TODO-I4, proven empirically 2026-10-03, 7/12 rounds
 * 500) is a check-then-act race in
 * ConversationModel::resolveConversationId() Step 4: both requests see
 * "chat_id unknown" at Step 1, both attempt the INSERT, the loser hits the
 * unique key on conversations.chat_id.
 *
 * True concurrency cannot be reproduced inside a single PHPUnit process
 * (same limitation documented in InboxGatewayApiDuplicateTest.php for
 * TODO-I1). Instead, this test reproduces the exact DB state the loser sees
 * at the moment its INSERT statement runs: a `conversations` row for the
 * chat_id already committed, with NO `conversation_identities` alias yet
 * (the narrowest possible window -- Step 1 still reports "unknown" via the
 * alias table, but the literal INSERT collides). This is byte-for-byte the
 * precondition resolveConversationId() must survive.
 *
 * Runs against the `inbox` group, redirected to `aulia_inboxdb_test` under
 * testing (tests/_support/bootstrap-feature.php, app/Config/Database.php).
 *
 * @internal
 */
final class InboxGatewayApiNewConversationRaceTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use GatewayApiTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gatewaySetUp();
    }

    protected function tearDown(): void
    {
        $this->gatewayTearDown();
        parent::tearDown();
    }

    /**
     * Seed ONLY the conversations row (no conversation_identities alias) --
     * simulates the "winner" having committed Step 4's first INSERT but not
     * yet (or never, for the purposes of this test) its second INSERT.
     * Deliberately bypasses GatewayApiTestTrait::seedConversation(), which
     * always inserts both rows together.
     */
    private function seedConversationWithoutIdentityAlias(string $chatId): int
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

    public function testLosingRaceOnBrandNewChatIdReusesWinnerInsteadOf500(): void
    {
        $chatId = '6281400000099@s.whatsapp.net';
        $winnerConversationId = $this->seedConversationWithoutIdentityAlias($chatId);

        $payload = [
            'wa_message_id'     => 'WAMSG-RACE-0001',
            'chat_id'            => $chatId,
            'jid_type'           => 'pn',
            'message_type'       => 'text',
            'text'               => 'pesan dari request yang "kalah race"',
            'message_timestamp'  => $this->nowIso(),
            'direction'          => 'incoming',
        ];

        $response = $this->postGatewayMessage($payload);

        // Pokok bug TODO-I4: sebelumnya ini jadi 500 (insert conversation
        // gagal, resolveConversationId() balik conversation_id=0/exception
        // tak tertangani, pesan hilang). Harus tetap 200 sukses.
        $response->assertStatus(200);
        $body = $this->gatewayBodyJson($response);
        $this->assertSame('success', $body['status'] ?? null);

        // Pesan HARUS tersimpan dan menempel ke conversation milik
        // "pemenang" race, bukan hilang dan bukan bikin conversation kedua.
        $message = $this->inbox->table('messages')
            ->where('wa_message_id', 'WAMSG-RACE-0001')
            ->get()->getRowArray();
        $this->assertNotNull($message, 'Pesan harus tetap tersimpan, bukan hilang.');
        $this->assertSame($winnerConversationId, (int) $message['conversation_id']);

        // Tidak boleh ada conversation KEDUA untuk chat_id yang sama --
        // unique key conversations.chat_id tetap dihormati, tidak ada baris
        // yatim/duplikat yang tertinggal.
        $conversationCount = $this->inbox->table('conversations')
            ->where('chat_id', $chatId)
            ->countAllResults();
        $this->assertSame(1, $conversationCount);
    }
}
