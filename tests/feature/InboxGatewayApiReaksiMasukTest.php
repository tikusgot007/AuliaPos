<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\GatewayApiTestTrait;

/**
 * Incoming customer reactions (docs/requirements/2026-10-12-reaksi-masuk-dan-emoji-picker-inbox.md).
 *
 * The Gateway (evolution-gateway normalize.js extractIncomingReaction()) now
 * sends a 1:1 reaction as an ordinary incoming text row: `text` = emoji,
 * `quoted.wa_message_id` = reacted message, `quoted.snippet` = null. No CI4
 * code changed; these tests pin that the existing endpoint stores and
 * summarises it as a new quoted message.
 *
 * Runs against `aulia_inboxdb_test` (tests/_support/bootstrap-feature.php).
 *
 * @internal
 */
final class InboxGatewayApiReaksiMasukTest extends CIUnitTestCase
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

    private function reaksiPayload(string $chatId, string $id, string $targetId, string $timestamp): array
    {
        return [
            'wa_message_id'     => $id,
            'chat_id'           => $chatId,
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => '👍',
            'message_timestamp' => $timestamp,
            'direction'         => 'incoming',
            'quoted'            => ['wa_message_id' => $targetId, 'sender_jid' => null, 'snippet' => null],
        ];
    }

    private function pesan(string $waMessageId): ?array
    {
        return $this->inbox->table('messages')->where('wa_message_id', $waMessageId)->get()->getRowArray();
    }

    /** AC-RX-2: the reaction row quotes the stored target message with local data. */
    public function testReaksiMengutipPesanTargetYangAda(): void
    {
        $chatId = '6281300009001@s.whatsapp.net';
        $this->seedConversation($chatId);

        $this->postGatewayMessage([
            'wa_message_id'     => 'RXN-TARGET-0001',
            'chat_id'           => $chatId,
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'Pesanan sudah siap diambil',
            'message_timestamp' => gmdate('Y-m-d\TH:i:s.000\Z', time() - 120),
            'direction'         => 'outgoing',
        ])->assertStatus(200);

        $this->postGatewayMessage($this->reaksiPayload($chatId, 'RXN-0001', 'RXN-TARGET-0001', $this->nowIso()))
            ->assertStatus(200);

        $row = $this->pesan('RXN-0001');
        $this->assertNotNull($row);
        $this->assertSame('text', $row['message_type']);
        $this->assertSame('👍', $row['text'], 'emoji disimpan utuh (utf8mb4)');
        $this->assertSame('incoming', $row['direction']);
        $this->assertSame('RXN-TARGET-0001', $row['quoted_wa_message_id']);
        $this->assertSame('Pesanan sudah siap diambil', $row['quoted_snippet'], 'cuplikan dari data lokal');
        $this->assertNotNull($row['quoted_sender_label'], 'target ditemukan -> label pengirim terisi');
    }

    /** AC-RX-2: target not stored locally -> generic "Pesan tidak ditemukan". */
    public function testReaksiPadaPesanYangTidakAda(): void
    {
        $chatId = '6281300009002@s.whatsapp.net';
        $this->seedConversation($chatId);

        $this->postGatewayMessage($this->reaksiPayload($chatId, 'RXN-0002', 'TIDAK-ADA-0002', $this->nowIso()))
            ->assertStatus(200);

        $row = $this->pesan('RXN-0002');
        $this->assertSame('TIDAK-ADA-0002', $row['quoted_wa_message_id']);
        $this->assertSame('Pesan tidak ditemukan', $row['quoted_snippet']);
        $this->assertNull($row['quoted_sender_label']);
    }

    /** AC-RX-3: a reaction counts as a new incoming message for the conversation. */
    public function testReaksiMenjadiPesanTerakhirMasuk(): void
    {
        $chatId = '6281300009003@s.whatsapp.net';
        $conversationId = $this->seedConversation($chatId);
        $this->inbox->table('conversations')->where('id', $conversationId)->update([
            'status'                 => 'closed',
            'last_message_at'        => date('Y-m-d H:i:s', time() - 3600),
            'last_message_direction' => 'outgoing',
        ]);

        $this->postGatewayMessage($this->reaksiPayload($chatId, 'RXN-0003', 'TARGET-0003', $this->nowIso()))
            ->assertStatus(200);

        $conv = $this->inbox->table('conversations')->where('id', $conversationId)->get()->getRowArray();
        $this->assertSame('incoming', $conv['last_message_direction'], 'reaksi jadi pesan terakhir (perlu dibalas)');
        $this->assertSame('open', $conv['status'], 'pesan masuk membuka kembali percakapan');
    }

    /** AC-RX-6: a redelivered reaction is not stored twice. */
    public function testReaksiDuplikatTidakDobel(): void
    {
        $chatId = '6281300009004@s.whatsapp.net';
        $this->seedConversation($chatId);
        $payload = $this->reaksiPayload($chatId, 'RXN-0004', 'TARGET-0004', $this->nowIso());

        $this->postGatewayMessage($payload)->assertStatus(200);
        $this->postGatewayMessage($payload)->assertStatus(200);

        $this->assertSame(1, $this->inbox->table('messages')->where('wa_message_id', 'RXN-0004')->countAllResults());
    }
}
