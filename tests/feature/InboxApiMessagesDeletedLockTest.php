<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * GET /inbox/api/conversations/(:num)/messages: toggle admin GLOBAL
 * `inbox.deletedMessageLocked` (task 2026-10-07).
 *
 * Locked (default, true): pesan revoked -> `text`/`media_metadata` DIBUANG
 * dari respons, `expandable=false`.
 * Unlocked (false): pesan revoked TEXT -> `text` asli + `expandable=true`;
 * pesan revoked MEDIA -> tetap `expandable=false` (Opsi A, teks saja).
 *
 * Lihat Inbox::terapkanKebijakanPesanDihapus(), Config\Inbox::$deletedMessageLocked.
 *
 * @internal
 */
final class InboxApiMessagesDeletedLockTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private $inbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inbox = db_connect('inbox');
        $this->inbox->table('messages')->emptyTable();
        $this->inbox->table('conversation_handoffs')->emptyTable();
        $this->inbox->table('conversation_identities')->emptyTable();
        $this->inbox->table('conversations')->emptyTable();
    }

    protected function tearDown(): void
    {
        putenv('inbox.deletedMessageLocked');
        unset($_ENV['inbox.deletedMessageLocked'], $_SERVER['inbox.deletedMessageLocked']);
        parent::tearDown();
    }

    private function setLocked(?string $value): void
    {
        if ($value === null) {
            putenv('inbox.deletedMessageLocked');
            unset($_ENV['inbox.deletedMessageLocked'], $_SERVER['inbox.deletedMessageLocked']);
            return;
        }
        putenv('inbox.deletedMessageLocked=' . $value);
        $_ENV['inbox.deletedMessageLocked']    = $value;
        $_SERVER['inbox.deletedMessageLocked'] = $value;
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

    /** @return array<string, mixed> baris `messages` yang baru dibuat */
    private function seedRevokedMessage(int $conversationId, array $override = []): array
    {
        $now = date('Y-m-d H:i:s');

        $this->inbox->table('messages')->insert(array_merge([
            'conversation_id'   => $conversationId,
            'wa_message_id'     => 'WAMSG-LOCK-' . random_int(10000, 99999),
            'direction'         => 'incoming',
            'message_type'      => 'text',
            'text'              => 'isi asli yang seharusnya tidak bocor',
            'send_status'       => 'received',
            'message_timestamp' => $now,
            'created_at'        => $now,
            'revoked_at'        => $now,
        ], $override));

        $id = (int) $this->inbox->insertID();

        return $this->inbox->table('messages')->where('id', $id)->get()->getRowArray();
    }

    private function fetchPage(int $conversationId)
    {
        return $this->withSession(['isLoggedIn' => true])
            ->get('/inbox/api/conversations/' . $conversationId . '/messages');
    }

    private function json($response): array
    {
        return json_decode($response->getJSON(), true);
    }

    private function firstMessage(array $json): array
    {
        return $json['messages'][0];
    }

    // ---------------- LOCKED (default) ----------------

    public function testLockedDefaultStripsTextForRevokedMessage(): void
    {
        $this->setLocked(null); // tidak diset -> default Config (true)
        $conv = $this->seedConversation('6281200000201@s.whatsapp.net');
        $this->seedRevokedMessage($conv);

        $json = $this->json($this->fetchPage($conv));
        $m    = $this->firstMessage($json);

        $this->assertTrue($m['is_revoked']);
        $this->assertNull($m['text'], 'LOCKED must strip original text.');
        $this->assertFalse($m['expandable']);
    }

    public function testLockedExplicitTrueStripsMediaMetadata(): void
    {
        $this->setLocked('true');
        $conv = $this->seedConversation('6281200000202@s.whatsapp.net');
        $this->seedRevokedMessage($conv, [
            'message_type'   => 'image',
            'text'           => null,
            'media_metadata' => json_encode(['width' => 100, 'height' => 100]),
        ]);

        $json = $this->json($this->fetchPage($conv));
        $m    = $this->firstMessage($json);

        $this->assertNull($m['media_metadata'], 'LOCKED must strip media_metadata too.');
        $this->assertFalse($m['expandable']);
    }

    public function testLockedNonRevokedMessageUnaffected(): void
    {
        $this->setLocked('true');
        $conv = $this->seedConversation('6281200000203@s.whatsapp.net');
        $this->seedRevokedMessage($conv, ['revoked_at' => null, 'text' => 'pesan aktif biasa']);

        $json = $this->json($this->fetchPage($conv));
        $m    = $this->firstMessage($json);

        $this->assertFalse($m['is_revoked']);
        $this->assertSame('pesan aktif biasa', $m['text']);
        // Pesan BUKAN revoked tidak diberi field `expandable` sama sekali
        // (lihat docblock terapkanKebijakanPesanDihapus()).
        $this->assertArrayNotHasKey('expandable', $m);
    }

    // ---------------- UNLOCKED ----------------

    public function testUnlockedTextMessageKeepsTextAndMarksExpandable(): void
    {
        $this->setLocked('false');
        $conv = $this->seedConversation('6281200000204@s.whatsapp.net');
        $this->seedRevokedMessage($conv, ['text' => 'Harga beras Rp 12.000/kg']);

        $json = $this->json($this->fetchPage($conv));
        $m    = $this->firstMessage($json);

        $this->assertTrue($m['is_revoked']);
        $this->assertSame('Harga beras Rp 12.000/kg', $m['text']);
        $this->assertTrue($m['expandable']);
    }

    public function testUnlockedMediaMessageStaysStrippedAndNotExpandable(): void
    {
        $this->setLocked('false');
        $conv = $this->seedConversation('6281200000205@s.whatsapp.net');
        $this->seedRevokedMessage($conv, [
            'message_type'   => 'image',
            'text'           => null,
            'media_metadata' => json_encode(['width' => 100, 'height' => 100]),
        ]);

        $json = $this->json($this->fetchPage($conv));
        $m    = $this->firstMessage($json);

        $this->assertFalse($m['expandable'], 'Media must never be expandable, even unlocked (Opsi A).');
    }

    /**
     * @dataProvider mediaTypesProvider
     */
    public function testUnlockedAllMediaTypesStayNotExpandable(string $type): void
    {
        $this->setLocked('false');
        $conv = $this->seedConversation('6281200000206@s.whatsapp.net');
        $this->seedRevokedMessage($conv, ['message_type' => $type, 'text' => null]);

        $json = $this->json($this->fetchPage($conv));
        $m    = $this->firstMessage($json);

        $this->assertFalse($m['expandable'], $type . ' must not be expandable.');
    }

    public static function mediaTypesProvider(): array
    {
        return [
            ['image'], ['document'], ['sticker'], ['audio'], ['video'],
        ];
    }

    public function testUnlockedOutgoingRevokedAlsoHonoursPolicy(): void
    {
        $this->setLocked('false');
        $conv = $this->seedConversation('6281200000207@s.whatsapp.net');
        $this->seedRevokedMessage($conv, [
            'direction' => 'outgoing', 'send_status' => 'sent',
            'text' => 'pesan keluar yang dihapus kasir',
        ]);

        $json = $this->json($this->fetchPage($conv));
        $m    = $this->firstMessage($json);

        $this->assertTrue($m['is_revoked']);
        $this->assertSame('pesan keluar yang dihapus kasir', $m['text']);
        $this->assertTrue($m['expandable'], 'Policy must be direction-agnostic (incoming AND outgoing).');
    }

    public function testLockedInvalidEnvValueFailsClosedToLocked(): void
    {
        // SEC-901: nilai tidak sah -> fail-closed ke LOCKED (true), bukan
        // diam-diam membuka isi pesan dihapus.
        $this->setLocked('bukan-boolean');
        $conv = $this->seedConversation('6281200000208@s.whatsapp.net');
        $this->seedRevokedMessage($conv, ['text' => 'harus tetap tersembunyi']);

        $json = $this->json($this->fetchPage($conv));
        $m    = $this->firstMessage($json);

        $this->assertNull($m['text'], 'Invalid env value must fail-closed to LOCKED.');
        $this->assertFalse($m['expandable']);
    }
}
