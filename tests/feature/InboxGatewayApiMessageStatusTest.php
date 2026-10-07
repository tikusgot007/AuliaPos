<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\GatewayApiTestTrait;

/**
 * WhatsApp read receipt Arah 2 (AuliaPos <- Gateway <- Evolution):
 * endpoint `POST /api/inbox/gateway/message-status` menandai pesan KELUAR
 * sebagai `delivered` (sampai HP pelanggan) atau `read` (dibaca pelanggan).
 *
 * Membuktikan:
 *   - `delivered` mengisi `messages.delivered_at`;
 *   - `read` mengisi `read_at` DAN `delivered_at` (read menyiratkan delivered);
 *   - IDEMPOTEN & MONOTON: retry tidak mengubah nilai; `delivered` setelah
 *     `read` tidak menurunkan status;
 *   - pesan MASUK / ID tak dikenal -> 200 `matched:false` (aman, bukan error);
 *   - validasi batas kepercayaan: `status`/`wa_message_id` invalid -> 400.
 *
 * Berjalan di `aulia_inboxdb_test` (tests/_support/bootstrap-feature.php).
 *
 * @internal
 */
final class InboxGatewayApiMessageStatusTest extends CIUnitTestCase
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

    private function postMessageStatus(array $payload)
    {
        return $this
            ->withHeaders(['Authorization' => 'Bearer ' . $this->gatewayToken])
            ->withBodyFormat('json')
            ->post('/api/inbox/gateway/message-status', $payload);
    }

    private function seedMessage(int $conversationId, string $waMessageId, string $direction = 'outgoing'): void
    {
        $now = date('Y-m-d H:i:s');
        $this->inbox->table('messages')->insert([
            'conversation_id'   => $conversationId,
            'wa_message_id'     => $waMessageId,
            'direction'         => $direction,
            'message_type'      => 'text',
            'text'              => 'halo',
            'message_timestamp' => $now,
            'send_status'       => $direction === 'outgoing' ? 'sent' : 'received',
            'is_forwarded'      => 0,
            'created_at'        => $now,
        ]);
    }

    private function column(string $waMessageId, string $column): ?string
    {
        $row = $this->inbox->table('messages')
            ->select($column)
            ->where('wa_message_id', $waMessageId)
            ->get()
            ->getRowArray();

        return $row === null ? null : ($row[$column] ?? null);
    }

    public function testDeliveredIsSetAndIdempotent(): void
    {
        $conversationId = $this->seedConversation('6281400000001@s.whatsapp.net');
        $this->seedMessage($conversationId, 'WAMSG-STATUS-DEL-0001', 'outgoing');

        $first = $this->postMessageStatus(['wa_message_id' => 'WAMSG-STATUS-DEL-0001', 'status' => 'delivered']);
        $first->assertStatus(200);
        $this->assertTrue($this->gatewayBodyJson($first)['matched']);
        $stamp = $this->column('WAMSG-STATUS-DEL-0001', 'delivered_at');
        $this->assertNotNull($stamp, 'delivered_at harus terisi.');
        $this->assertNull($this->column('WAMSG-STATUS-DEL-0001', 'read_at'), 'delivered tidak mengisi read_at.');

        $second = $this->postMessageStatus(['wa_message_id' => 'WAMSG-STATUS-DEL-0001', 'status' => 'delivered']);
        $second->assertStatus(200);
        $this->assertSame($stamp, $this->column('WAMSG-STATUS-DEL-0001', 'delivered_at'), 'Nilai tidak boleh berubah pada retry.');
    }

    public function testReadSetsReadAndDelivered(): void
    {
        $conversationId = $this->seedConversation('6281400000002@s.whatsapp.net');
        $this->seedMessage($conversationId, 'WAMSG-STATUS-READ-0001', 'outgoing');

        $response = $this->postMessageStatus(['wa_message_id' => 'WAMSG-STATUS-READ-0001', 'status' => 'read']);
        $response->assertStatus(200);
        $this->assertTrue($this->gatewayBodyJson($response)['matched']);
        $this->assertNotNull($this->column('WAMSG-STATUS-READ-0001', 'read_at'), 'read_at harus terisi.');
        $this->assertNotNull($this->column('WAMSG-STATUS-READ-0001', 'delivered_at'), 'read menyiratkan delivered_at terisi.');
    }

    public function testDeliveredAfterReadDoesNotDowngrade(): void
    {
        $conversationId = $this->seedConversation('6281400000003@s.whatsapp.net');
        $this->seedMessage($conversationId, 'WAMSG-STATUS-MONO-0001', 'outgoing');

        $this->postMessageStatus(['wa_message_id' => 'WAMSG-STATUS-MONO-0001', 'status' => 'read'])->assertStatus(200);
        $readStamp = $this->column('WAMSG-STATUS-MONO-0001', 'read_at');

        // Event `delivered` terlambat datang setelah `read` -- tidak boleh menghapus read_at.
        $this->postMessageStatus(['wa_message_id' => 'WAMSG-STATUS-MONO-0001', 'status' => 'delivered'])->assertStatus(200);
        $this->assertSame($readStamp, $this->column('WAMSG-STATUS-MONO-0001', 'read_at'), 'read_at tidak boleh hilang.');
        $this->assertNotNull($this->column('WAMSG-STATUS-MONO-0001', 'read_at'));
    }

    public function testIncomingMessageIsNotMatched(): void
    {
        $conversationId = $this->seedConversation('6281400000004@s.whatsapp.net');
        $this->seedMessage($conversationId, 'WAMSG-STATUS-IN-0001', 'incoming');

        $response = $this->postMessageStatus(['wa_message_id' => 'WAMSG-STATUS-IN-0001', 'status' => 'read']);
        $response->assertStatus(200);
        $this->assertFalse($this->gatewayBodyJson($response)['matched'], 'Pesan masuk tidak punya status kirim.');
        $this->assertNull($this->column('WAMSG-STATUS-IN-0001', 'read_at'));
    }

    public function testUnknownTargetIsSafeAndNotMatched(): void
    {
        $response = $this->postMessageStatus(['wa_message_id' => 'WAMSG-STATUS-TIDAK-ADA', 'status' => 'read']);
        $response->assertStatus(200);
        $this->assertFalse($this->gatewayBodyJson($response)['matched']);
    }

    public function testMalformedStatusIsRejected(): void
    {
        $response = $this->postMessageStatus(['wa_message_id' => 'X', 'status' => 'ngawur']);
        $response->assertStatus(400);
    }

    public function testMissingWaMessageIdIsRejected(): void
    {
        $response = $this->postMessageStatus(['wa_message_id' => '', 'status' => 'read']);
        $response->assertStatus(400);
    }
}
