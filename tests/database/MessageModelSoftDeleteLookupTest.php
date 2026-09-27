<?php

use App\Models\MessageModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Balas Pesan (Tahap 3, ARCH-001) -- `MessageModel::findByIdIncludingDeleted()`
 * MUST return the source row even when it is already soft-deleted, because
 * `Inbox::resolveKutipan()` builds the quote snapshot from that row (AC-004).
 *
 * The plain `find()`/`first()` path is deliberately shown to hide the row, to
 * document why the dedicated seam exists (spec REQ-008b/REQ-011, Section 12).
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
}
