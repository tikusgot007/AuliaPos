<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\GatewayApiTestTrait;

/**
 * TODO-F7 (docs/requirements/2026-10-04-tandai-pesan-diedit-inbox.md):
 * endpoint `POST /api/inbox/gateway/message-event` menandai pesan ASLI yang
 * DIEDIT atau DIHAPUS pelanggan di WhatsApp.
 *
 * Membuktikan (AC-1, AC-2, AC-5, AC-6, AC-7):
 *   - `edited` mengisi `messages.edited_at`, `deleted` mengisi `revoked_at`;
 *   - IDEMPOTEN: pemanggilan ulang tidak mengubah nilai (retry Gateway);
 *   - target tak ditemukan -> 200 `matched:false` (aman, bukan error);
 *   - pesan media tanpa caption tetap bisa ditandai (tidak bergantung `text`);
 *   - validasi batas kepercayaan: `event`/`wa_message_id` invalid -> 400;
 *   - TODO-F8: `edited_text` yang valid mengganti teks pesan asli dan tetap
 *     mempertahankan `edited_at` pertama; tipe kosong/salah/terlalu panjang
 *     ditolak; deleted tidak menerima edited_text; target tak ada aman 200.
 *
 * Berjalan di `aulia_inboxdb_test` (tests/_support/bootstrap-feature.php,
 * app/Config/Database.php redirect grup `inbox` saat ENVIRONMENT==='testing').
 *
 * @internal
 */
final class InboxGatewayMessageEventTest extends CIUnitTestCase
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

    private function postMessageEvent(array $payload)
    {
        return $this
            ->withHeaders(['Authorization' => 'Bearer ' . $this->gatewayToken])
            ->withBodyFormat('json')
            ->post('/api/inbox/gateway/message-event', $payload);
    }

    private function marker(string $waMessageId, string $column): ?string
    {
        $row = $this->inbox->table('messages')
            ->select($column)
            ->where('wa_message_id', $waMessageId)
            ->get()
            ->getRowArray();

        return $row === null ? null : ($row[$column] ?? null);
    }

    private function messageText(string $waMessageId): ?string
    {
        $row = $this->inbox->table('messages')
            ->select('text')
            ->where('wa_message_id', $waMessageId)
            ->get()
            ->getRowArray();

        return $row === null ? null : ($row['text'] ?? null);
    }

    private function seedMessage(int $conversationId, string $waMessageId, string $messageType = 'text', ?string $text = 'halo'): void
    {
        $now = date('Y-m-d H:i:s');
        $this->inbox->table('messages')->insert([
            'conversation_id'   => $conversationId,
            'wa_message_id'     => $waMessageId,
            'direction'         => 'incoming',
            'message_type'      => $messageType,
            'text'              => $text,
            'message_timestamp' => $now,
            'send_status'       => 'received',
            'is_forwarded'      => 0,
            'created_at'        => $now,
        ]);
    }

    public function testEditMarkerIsSetAndIdempotent(): void
    {
        $chatId = '6281300000010@s.whatsapp.net';
        $conversationId = $this->seedConversation($chatId);
        $this->seedMessage($conversationId, 'WAMSG-LC-EDIT-0001');

        $first = $this->postMessageEvent(['wa_message_id' => 'WAMSG-LC-EDIT-0001', 'event' => 'edited']);
        $first->assertStatus(200);
        $this->assertTrue($this->gatewayBodyJson($first)['matched'], 'Pesan asli harus ditemukan.');
        $stamp = $this->marker('WAMSG-LC-EDIT-0001', 'edited_at');
        $this->assertNotNull($stamp, 'edited_at harus terisi.');
        $this->assertNull($this->marker('WAMSG-LC-EDIT-0001', 'revoked_at'), 'edit tidak mengisi revoked_at.');

        // AC-5: idempoten -- retry tidak mengubah nilai pertama.
        $second = $this->postMessageEvent(['wa_message_id' => 'WAMSG-LC-EDIT-0001', 'event' => 'edited']);
        $second->assertStatus(200);
        $this->assertSame($stamp, $this->marker('WAMSG-LC-EDIT-0001', 'edited_at'), 'Nilai edited_at tidak boleh berubah pada retry.');
    }

    public function testDeleteMarkerIsSetAndIdempotent(): void
    {
        $chatId = '6281300000011@s.whatsapp.net';
        $conversationId = $this->seedConversation($chatId);
        $this->seedMessage($conversationId, 'WAMSG-LC-DEL-0001', 'text', 'Pesan mau dihapus');

        $first = $this->postMessageEvent(['wa_message_id' => 'WAMSG-LC-DEL-0001', 'event' => 'deleted']);
        $first->assertStatus(200);
        $this->assertTrue($this->gatewayBodyJson($first)['matched']);
        $stamp = $this->marker('WAMSG-LC-DEL-0001', 'revoked_at');
        $this->assertNotNull($stamp, 'revoked_at harus terisi.');
        $this->assertNull($this->marker('WAMSG-LC-DEL-0001', 'edited_at'), 'delete tidak mengisi edited_at.');

        $second = $this->postMessageEvent(['wa_message_id' => 'WAMSG-LC-DEL-0001', 'event' => 'deleted']);
        $second->assertStatus(200);
        $this->assertSame($stamp, $this->marker('WAMSG-LC-DEL-0001', 'revoked_at'), 'Nilai revoked_at tidak boleh berubah pada retry.');
    }

    public function testUnknownTargetIsSafeAndNotMatched(): void
    {
        $response = $this->postMessageEvent(['wa_message_id' => 'WAMSG-LC-TIDAK-ADA', 'event' => 'deleted']);
        $response->assertStatus(200); // AC-6: bukan error, hanya matched:false
        $this->assertFalse($this->gatewayBodyJson($response)['matched'], 'Target tak ada -> matched:false, tanpa error.');
    }

    public function testEditedTextReplacesTextAndPreservesFirstEditMarker(): void
    {
        $chatId = '6281300000013@s.whatsapp.net';
        $conversationId = $this->seedConversation($chatId);
        $this->seedMessage($conversationId, 'WAMSG-F8-TEXT-0001', 'text', 'versi lama');

        $first = $this->postMessageEvent([
            'wa_message_id' => 'WAMSG-F8-TEXT-0001',
            'event'         => 'edited',
            'edited_text'   => 'versi hasil edit pertama',
        ]);
        $first->assertStatus(200);
        $this->assertTrue($this->gatewayBodyJson($first)['matched']);
        $stamp = $this->marker('WAMSG-F8-TEXT-0001', 'edited_at');
        $this->assertNotNull($stamp);
        $this->assertSame('versi hasil edit pertama', $this->messageText('WAMSG-F8-TEXT-0001'));

        $second = $this->postMessageEvent([
            'wa_message_id' => 'WAMSG-F8-TEXT-0001',
            'event'         => 'edited',
            'edited_text'   => 'versi hasil edit terbaru',
        ]);
        $second->assertStatus(200);
        $this->assertTrue($this->gatewayBodyJson($second)['matched']);
        $this->assertSame('versi hasil edit terbaru', $this->messageText('WAMSG-F8-TEXT-0001'));
        $this->assertSame($stamp, $this->marker('WAMSG-F8-TEXT-0001', 'edited_at'));
    }

    public function testEditedTextIsRejectedForDeletedEvent(): void
    {
        $chatId = '6281300000014@s.whatsapp.net';
        $conversationId = $this->seedConversation($chatId);
        $this->seedMessage($conversationId, 'WAMSG-F8-DEL-0001');

        $response = $this->postMessageEvent([
            'wa_message_id' => 'WAMSG-F8-DEL-0001',
            'event'         => 'deleted',
            'edited_text'   => 'jangan diterima',
        ]);
        $response->assertStatus(400);
        $this->assertSame('halo', $this->messageText('WAMSG-F8-DEL-0001'));
        $this->assertNull($this->marker('WAMSG-F8-DEL-0001', 'revoked_at'));
    }

    public function testEditedTextRequiresNonEmptyStringWithinLimit(): void
    {
        $chatId = '6281300000015@s.whatsapp.net';
        $conversationId = $this->seedConversation($chatId);
        $this->seedMessage($conversationId, 'WAMSG-F8-VALIDATE-0001');

        $cases = [
            'empty'   => '',
            'array'   => ['not', 'text'],
            'boolean' => true,
            'too_long'=> str_repeat('x', 65536),
        ];

        foreach ($cases as $label => $editedText) {
            $response = $this->postMessageEvent([
                'wa_message_id' => 'WAMSG-F8-VALIDATE-0001',
                'event'         => 'edited',
                'edited_text'   => $editedText,
            ]);
            $response->assertStatus(400, "Kasus {$label} harus ditolak.");
        }

        $this->assertSame('halo', $this->messageText('WAMSG-F8-VALIDATE-0001'));
        $this->assertNull($this->marker('WAMSG-F8-VALIDATE-0001', 'edited_at'));
    }

    public function testEditedTextOnUnknownTargetIsSafe(): void
    {
        $response = $this->postMessageEvent([
            'wa_message_id' => 'WAMSG-F8-UNKNOWN-0001',
            'event'         => 'edited',
            'edited_text'   => 'teks baru',
        ]);
        $response->assertStatus(200);
        $this->assertFalse($this->gatewayBodyJson($response)['matched']);
    }

    public function testMediaMessageWithoutCaptionCanBeMarked(): void
    {
        $chatId = '6281300000012@s.whatsapp.net';
        $conversationId = $this->seedConversation($chatId);
        // AC-7: pesan media tanpa caption (text NULL) tetap bisa ditandai.
        $this->seedMessage($conversationId, 'WAMSG-LC-MEDIA-0001', 'image', null);

        $response = $this->postMessageEvent(['wa_message_id' => 'WAMSG-LC-MEDIA-0001', 'event' => 'edited']);
        $response->assertStatus(200);
        $this->assertTrue($this->gatewayBodyJson($response)['matched']);
        $this->assertNotNull($this->marker('WAMSG-LC-MEDIA-0001', 'edited_at'), 'Media tanpa caption harus bisa ditandai.');
    }

    public function testMalformedEventIsRejected(): void
    {
        $response = $this->postMessageEvent(['wa_message_id' => 'WAMSG-LC-VALID-0001', 'event' => 'ngawur']);
        $response->assertStatus(400);
    }

    public function testMissingWaMessageIdIsRejected(): void
    {
        $response = $this->postMessageEvent(['wa_message_id' => '', 'event' => 'edited']);
        $response->assertStatus(400);
    }
}
