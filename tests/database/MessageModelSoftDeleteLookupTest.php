<?php

use App\Models\MessageModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Balas Pesan (Tahap 3, ARCH-001) -- dua seam lookup soft-delete-inclusive
 * pada `MessageModel`:
 *
 * 1. `findByIdIncludingDeleted(int $id)` MUST return the source row even when
 *    it is already soft-deleted, because `Inbox::resolveKutipan()` builds the
 *    quote snapshot from that row (AC-004). The plain `find()`/`first()` path
 *    is deliberately shown to hide the row, to document why the dedicated
 *    seam exists (spec REQ-008b/REQ-011, Section 12).
 * 2. `findByOperationIdIncludingDeleted(?string $operationId, int $conversationId)`
 *    -- replay lookup. Soft-delete-inclusive because the UNIQUE index
 *    `uniq_messages_gateway_operation_id` also covers soft-deleted rows, and
 *    scoped to the conversation so a replay can never match another
 *    conversation's row (ARCH-001 review ronde-2).
 *
 * Runs against the `inbox` group, redirected to `aulia_inboxdb_test` under
 * testing.
 *
 * @internal
 */
final class MessageModelSoftDeleteLookupTest extends CIUnitTestCase
{
    private $inbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inbox = db_connect('inbox');

        $this->assertSame(
            'aulia_inboxdb_test',
            $this->inbox->database,
            'Refusing to run: inbox group must point to aulia_inboxdb_test under testing.'
        );

        $this->inbox->table('messages')->emptyTable();
        $this->inbox->table('conversations')->emptyTable();
    }

    private function seedConversation(): int
    {
        $now = date('Y-m-d H:i:s');

        $this->inbox->table('conversations')->insert([
            'chat_id'    => '628' . random_int(100000000, 999999999) . '@s.whatsapp.net',
            'jid_type'   => 'pn',
            'status'     => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->inbox->insertID();
    }

    private function seedMessage(int $conversationId, ?string $deletedAt = null): int
    {
        $now = date('Y-m-d H:i:s');

        $this->inbox->table('messages')->insert([
            'conversation_id'   => $conversationId,
            'wa_message_id'     => 'wamid-' . bin2hex(random_bytes(8)),
            'direction'         => 'incoming',
            'message_type'      => 'text',
            'text'              => 'pesan sumber',
            'message_timestamp' => $now,
            'send_status'       => 'received',
            'created_at'        => $now,
            'deleted_at'        => $deletedAt,
        ]);

        return (int) $this->inbox->insertID();
    }

    private function seedOutgoing(int $conversationId, string $operationId, bool $softDeleted = false): int
    {
        $now = date('Y-m-d H:i:s');

        $this->inbox->table('messages')->insert([
            'conversation_id'      => $conversationId,
            'wa_message_id'        => 'op-' . bin2hex(random_bytes(8)),
            'direction'            => 'outgoing',
            'message_type'         => 'text',
            'text'                 => 'pesan replay',
            'message_timestamp'    => $now,
            'send_status'          => 'sent',
            'gateway_operation_id' => $operationId,
            'created_at'           => $now,
            'deleted_at'           => $softDeleted ? $now : null,
        ]);

        return (int) $this->inbox->insertID();
    }

    // ------------------------------------------------------------------
    // findByIdIncludingDeleted(): kutipan pesan sumber (AC-004)
    // ------------------------------------------------------------------

    public function testFindByIdIncludingDeletedMengembalikanBarisAktif(): void
    {
        $conversationId = $this->seedConversation();
        $id             = $this->seedMessage($conversationId);

        $row = (new MessageModel())->findByIdIncludingDeleted($id);

        $this->assertNotNull($row);
        $this->assertSame($id, (int) $row['id']);
        $this->assertSame('pesan sumber', $row['text']);
    }

    public function testFindByIdIncludingDeletedTetapMengembalikanBarisSoftDeleted(): void
    {
        $conversationId = $this->seedConversation();
        $id             = $this->seedMessage($conversationId, date('Y-m-d H:i:s'));

        $row = (new MessageModel())->findByIdIncludingDeleted($id);

        $this->assertNotNull($row, 'ARCH-001/AC-004: soft-deleted source must still be returned.');
        $this->assertSame($id, (int) $row['id']);
        $this->assertNotNull($row['deleted_at']);
    }

    public function testFindByIdIncludingDeletedMengembalikanNullUntukIdTidakAda(): void
    {
        $this->assertNull((new MessageModel())->findByIdIncludingDeleted(999999));
    }

    public function testFindPolosMenyembunyikanBarisSoftDeleted(): void
    {
        // Dokumentasi seam: `find()` polos memang menyaring baris soft-deleted
        // (useSoftDeletes). Inilah alasan `resolveKutipan()` memakai method
        // khusus di atas, bukan `find()`.
        $conversationId = $this->seedConversation();
        $id             = $this->seedMessage($conversationId, date('Y-m-d H:i:s'));

        $this->assertNull((new MessageModel())->find($id));
    }

    // ------------------------------------------------------------------
    // findByOperationIdIncludingDeleted(): replay kirim (ARCH-001 review-2)
    // ------------------------------------------------------------------

    public function testFindByOperationIdMengembalikanBarisSoftDeletedPercakapanSama(): void
    {
        $conversationId = $this->seedConversation();
        $messageId      = $this->seedOutgoing($conversationId, 'OP-DELETED', true);

        $row = (new MessageModel())->findByOperationIdIncludingDeleted('OP-DELETED', $conversationId);

        $this->assertNotNull($row, 'Indeks UNIQUE mencakup baris soft-deleted -> wajib dikembalikan.');
        $this->assertSame($messageId, (int) $row['id']);
    }

    public function testFindByOperationIdTidakMengembalikanBarisPercakapanLain(): void
    {
        $conversationA = $this->seedConversation();
        $conversationB = $this->seedConversation();
        $this->seedOutgoing($conversationA, 'OP-SCOPE');

        $model = new MessageModel();

        $this->assertNotNull(
            $model->findByOperationIdIncludingDeleted('OP-SCOPE', $conversationA),
            'Percakapan pemilik wajib menemukan barisnya.'
        );
        $this->assertNull(
            $model->findByOperationIdIncludingDeleted('OP-SCOPE', $conversationB),
            'ARCH-001: percakapan lain TIDAK BOLEH menemukan baris dengan operation_id yang sama.'
        );
    }

    public function testFindByOperationIdKosongAtauNullMengembalikanNull(): void
    {
        $conversationId = $this->seedConversation();
        $model          = new MessageModel();

        $this->assertNull($model->findByOperationIdIncludingDeleted(null, $conversationId));
        $this->assertNull($model->findByOperationIdIncludingDeleted('', $conversationId));
    }

    public function testFindByOperationIdTidakAdaMengembalikanNull(): void
    {
        $conversationId = $this->seedConversation();
        $this->seedOutgoing($conversationId, 'OP-LAIN');

        $this->assertNull((new MessageModel())->findByOperationIdIncludingDeleted('OP-TIDAK-ADA', $conversationId));
    }

    public function testFindByOperationIdMengembalikanBarisAktifSepertiPerilakuLama(): void
    {
        $conversationId = $this->seedConversation();
        $messageId      = $this->seedOutgoing($conversationId, 'OP-AKTIF');

        $row = (new MessageModel())->findByOperationIdIncludingDeleted('OP-AKTIF', $conversationId);

        $this->assertNotNull($row);
        $this->assertSame($messageId, (int) $row['id']);
    }
}
