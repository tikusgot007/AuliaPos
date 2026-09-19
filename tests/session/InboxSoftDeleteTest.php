<?php

use App\Models\ConversationModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Tahap D -- Soft-Delete Conversation + guard admin/closed di
 * Inbox::hapusPercakapan(), dan revive() otomatis lewat
 * ConversationModel::resolveConversationId().
 *
 * Tabel inbox (`conversations`/`messages`/`conversation_identities`)
 * hidup di koneksi 'inbox' (aulia_inboxdb), TERPISAH dari koneksi
 * 'tests' yang dipakai DatabaseTestTrait untuk migrate/refresh
 * otomatis di bawah -- migration `$DBGroup='inbox'` SENGAJA di-skip
 * oleh migrate('tests') (CodeIgniter memfilter per group), jadi tidak
 * ikut ter-migrate ulang di sini. Jalankan
 * `php spark migrate --group inbox` dulu seperti biasa (lihat
 * CLAUDE.md) sebelum test ini -- setUp() di bawah cuma membersihkan
 * datanya, bukan skema-nya.
 *
 * @internal
 */
final class InboxSoftDeleteTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    protected $namespace = 'Tests\Support';
    protected $migrate   = true;
    protected $refresh   = true;

    private const HAPUS_URL = 'inbox/percakapan/';

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
        $db     = db_connect('inbox');
        $now    = date('Y-m-d H:i:s');
        $chatId = $override['chat_id'] ?? ('628' . random_int(100000000, 999999999) . '@s.whatsapp.net');

        $db->table('conversations')->insert(array_merge([
            'chat_id'    => $chatId,
            'jid_type'   => 'pn',
            'status'     => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ], $override));
        $id = (int) $db->insertID();

        $db->table('conversation_identities')->insert([
            'conversation_id' => $id,
            'chat_id'         => $chatId,
            'jid_type'        => 'pn',
            'created_at'      => $now,
        ]);

        return $id;
    }

    private function ambilConversation(int $id): ?array
    {
        return db_connect('inbox')->table('conversations')->getWhere(['id' => $id])->getRowArray();
    }

    // ---- Poin 1: non-admin -> 403, baris TIDAK tersentuh ---------------

    public function testNonAdminDitolak403(): void
    {
        $id = $this->seedConversation(['status' => 'closed']);

        $res = $this->withSession($this->sesi('kasir', 7))
            ->post(self::HAPUS_URL . $id . '/hapus');

        $res->assertStatus(403);
        $res->assertJSONFragment(['status' => 'error']);
        $this->assertNull($this->ambilConversation($id)['deleted_at']);
    }

    // ---- Poin 2: admin tapi masih 'open' -> 409, baris TIDAK tersentuh --

    public function testAdminTapiMasihOpenDitolak409(): void
    {
        $id = $this->seedConversation(['status' => 'open']);

        $res = $this->withSession($this->sesi('admin', 1))
            ->post(self::HAPUS_URL . $id . '/hapus');

        $res->assertStatus(409);
        $res->assertJSONFragment(['status' => 'error']);
        $this->assertNull($this->ambilConversation($id)['deleted_at']);
    }

    // ---- Poin 3: admin + closed -> sukses, SOFT delete (baris tetap ada) --

    public function testAdminBisaHapusConversationClosed(): void
    {
        $id = $this->seedConversation(['status' => 'closed']);

        $res = $this->withSession($this->sesi('admin', 1))
            ->post(self::HAPUS_URL . $id . '/hapus');

        $res->assertOK();
        $res->assertJSONFragment(['status' => 'success']);

        $row = $this->ambilConversation($id);
        $this->assertNotNull($row); // baris HARUS masih ada -- bukan hard delete
        $this->assertNotNull($row['deleted_at']);
    }

    // ---- Poin 4: chat_id yang sama chat lagi -> revived, bukan duplikat --

    public function testResolveConversationIdMenghidupkanKembaliYangSoftDeleted(): void
    {
        $chatId = '628' . random_int(100000000, 999999999) . '@s.whatsapp.net';
        $id     = $this->seedConversation(['chat_id' => $chatId, 'status' => 'closed']);

        (new ConversationModel())->delete($id);
        $this->assertNotNull($this->ambilConversation($id)['deleted_at']);

        $resolved = (new ConversationModel())->resolveConversationId($chatId, 'pn', null);

        $this->assertSame($id, $resolved['conversation_id']); // conversation LAMA dipakai lagi, bukan bikin baru
        $this->assertFalse($resolved['created']);

        $row = $this->ambilConversation($id);
        $this->assertNull($row['deleted_at']); // dihidupkan kembali
    }
}
