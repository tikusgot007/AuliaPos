<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\GatewayApiTestTrait;

/**
 * POST /inbox/percakapan/(:num)/tutup + flag `bisa_ditutup`.
 *
 * Business rule (docs/CHANGELOG.md, 2026-10-06):
 *  - "Tutup" = menutup percakapan + MELEPAS kepemilikan (assigned_to=NULL).
 *  - Tombol "Tutup" di UI memakai flag `bisa_ditutup` server, dihitung dari
 *    aturan yang sama persis dengan guard tutupPercakapan()/cekOwnership():
 *    grup -> false, status != open -> false, assigned_to NULL/self/admin -> true,
 *    milik staff lain -> false.
 *  - Incoming customer pada percakapan closed membukanya kembali (status=open)
 *    TANPA mengembalikan assigned_to (tetap NULL).
 *
 * `conversations` ada di group `inbox` (aulia_inboxdb_test). `users` dibaca
 * cekOwnership() dari group default -> di ENVIRONMENT=testing diarahkan ke
 * SQLite :memory: (prefix db_), jadi tabelnya di-forge per test.
 *
 * @internal
 */
final class InboxTutupPercakapanTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use GatewayApiTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gatewaySetUp();

        $forge = \Config\Database::forge();
        $forge->dropTable('users', true);
        $forge->addField([
            'id'       => ['type' => 'INTEGER', 'auto_increment' => true],
            'username' => ['type' => 'TEXT'],
            'nama'     => ['type' => 'TEXT', 'null' => true],
            'inisial'  => ['type' => 'TEXT', 'null' => true],
            'role'     => ['type' => 'TEXT', 'default' => 'kasir'],
            'is_active' => ['type' => 'INTEGER', 'default' => 1],
            'priority' => ['type' => 'INTEGER', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('users', true);
    }

    protected function tearDown(): void
    {
        \Config\Database::forge()->dropTable('users', true);
        $this->gatewayTearDown();
        parent::tearDown();
    }

    private function seedUser(int $id, string $role = 'kasir'): void
    {
        db_connect()->table('users')->insert([
            'id'       => $id,
            'username' => 'user' . $id,
            'nama'     => 'User ' . $id,
            'inisial'  => 'U' . $id,
            'role'     => $role,
            'is_active' => 1,
            'priority' => null,
        ]);
    }

    /**
     * Seed conversation + alias (chat_id) supaya jalur inbound
     * resolveConversationId() mengenalinya. Pakai group khusus per test
     * supaya tidak bertabrakan dengan chat_id test lain.
     */
    private function seedConv(array $override = []): int
    {
        $now = date('Y-m-d H:i:s');

        $this->inbox->table('conversations')->insert(array_merge([
            'chat_id'                => '6281200009' . random_int(1000, 9999) . '@s.whatsapp.net',
            'jid_type'               => 'pn',
            'status'                 => 'open',
            'assigned_to'            => null,
            'last_message_direction' => 'incoming',
            'last_message_at'        => $now,
            'created_at'             => $now,
            'updated_at'             => $now,
        ], $override));
        $conversationId = (int) $this->inbox->insertID();

        $row = $this->inbox->table('conversations')->where('id', $conversationId)->get()->getRowArray();
        $this->inbox->table('conversation_identities')->insert([
            'conversation_id' => $conversationId,
            'chat_id'         => $row['chat_id'],
            'jid_type'        => $row['jid_type'],
            'created_at'      => $now,
        ]);

        return $conversationId;
    }

    private function tutup(int $userId, string $role, int $conversationId)
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => $userId, 'role' => $role])
            ->post('/inbox/percakapan/' . $conversationId . '/tutup');
    }

    private function apiConversations(int $userId, string $role)
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => $userId, 'role' => $role])
            ->get('/inbox/api/conversations');
    }

    private function row(int $conversationId): array
    {
        return $this->inbox->table('conversations')->where('id', $conversationId)->get()->getRowArray() ?? [];
    }

    /** Flag `bisa_ditutup` untuk satu conversation dari respons apiConversations(). */
    private function bisaDitutup(int $userId, string $role, int $conversationId): ?bool
    {
        $body = $this->bodyJson($this->apiConversations($userId, $role));

        foreach ($body['conversations'] ?? [] as $c) {
            if ((int) $c['id'] === $conversationId) {
                return isset($c['bisa_ditutup']) ? (bool) $c['bisa_ditutup'] : null;
            }
        }

        return null;
    }

    private function bodyJson($response): array
    {
        $decoded = json_decode(trim(strip_tags($response->getBody())), true);

        return is_array($decoded) ? $decoded : [];
    }

    // ----------------------------------------------------------------
    // Skenario 1 + 6: owner menutup; semua field terisi + assigned_to NULL.
    // ----------------------------------------------------------------
    public function testOwnerDapatMenutupDanMelepasKepemilikan(): void
    {
        $this->seedUser(11);
        $conv = $this->seedConv(['assigned_to' => 11]);

        $before = date('Y-m-d H:i:s', strtotime('-5 seconds'));

        $response = $this->tutup(11, 'kasir', $conv);
        $response->assertStatus(200);

        $row = $this->row($conv);
        $this->assertSame('closed', $row['status']);
        $this->assertSame(11, (int) $row['closed_by']);
        $this->assertNotNull($row['closed_at']);
        $this->assertGreaterThanOrEqual($before, $row['closed_at']);
        $this->assertNull($row['assigned_to'], 'Tutup harus melepas kepemilikan (assigned_to=NULL).');
    }

    // ----------------------------------------------------------------
    // Skenario 2: admin menutup milik staff lain.
    // ----------------------------------------------------------------
    public function testAdminDapatMenutupMilikStaffLain(): void
    {
        $this->seedUser(11);
        $this->seedUser(1, 'admin');
        $conv = $this->seedConv(['assigned_to' => 11]);

        $response = $this->tutup(1, 'admin', $conv);
        $response->assertStatus(200);

        $row = $this->row($conv);
        $this->assertSame('closed', $row['status']);
        $this->assertSame(1, (int) $row['closed_by']);
        $this->assertNull($row['assigned_to']);
    }

    // ----------------------------------------------------------------
    // Skenario 3: staff lain tidak berhak (403, tidak ada perubahan).
    // ----------------------------------------------------------------
    public function testStaffLainTidakDapatMenutup(): void
    {
        $this->seedUser(11);
        $this->seedUser(12);
        $conv = $this->seedConv(['assigned_to' => 11]);

        $response = $this->tutup(12, 'kasir', $conv);
        $response->assertStatus(403);

        $row = $this->row($conv);
        $this->assertSame('open', $row['status']);
        $this->assertSame(11, (int) $row['assigned_to']);
        $this->assertNull($row['closed_at']);
        $this->assertNull($row['closed_by']);
    }

    // ----------------------------------------------------------------
    // Skenario 4: grup tidak pernah bisa ditutup.
    // ----------------------------------------------------------------
    public function testGrupTidakDapatDitutup(): void
    {
        $this->seedUser(11);
        $conv = $this->seedConv(['jid_type' => 'group', 'assigned_to' => 11]);

        $response = $this->tutup(11, 'kasir', $conv);
        $response->assertStatus(403);

        $row = $this->row($conv);
        $this->assertSame('open', $row['status']);
        $this->assertNull($row['closed_at']);
    }

    // ----------------------------------------------------------------
    // Skenario 5: conversation unassigned boleh ditutup.
    // ----------------------------------------------------------------
    public function testUnassignedDapatDitutup(): void
    {
        $this->seedUser(12);
        $conv = $this->seedConv(['assigned_to' => null]);

        $response = $this->tutup(12, 'kasir', $conv);
        $response->assertStatus(200);

        $row = $this->row($conv);
        $this->assertSame('closed', $row['status']);
        $this->assertSame(12, (int) $row['closed_by']);
        $this->assertNull($row['assigned_to']);
    }

    // ----------------------------------------------------------------
    // Skenario 7: idempotent -- menutup yang sudah closed TIDAK menimpa
    // closed_at/closed_by lama.
    // ----------------------------------------------------------------
    public function testIdempotentTidakMenimpaClosedAtClosedBy(): void
    {
        $this->seedUser(11);
        $this->seedUser(12, 'admin');
        $closedAt = '2026-01-01 10:00:00';
        $conv = $this->seedConv([
            'status'      => 'closed',
            'assigned_to' => null,
            'closed_at'   => $closedAt,
            'closed_by'   => 99,
        ]);

        $response = $this->tutup(12, 'admin', $conv);
        $response->assertStatus(200);

        $row = $this->row($conv);
        $this->assertSame($closedAt, $row['closed_at']);
        $this->assertSame(99, (int) $row['closed_by']);
    }

    // ----------------------------------------------------------------
    // Skenario 8: incoming customer pada closed -> status=open,
    // assigned_to TETAP NULL (tidak auto-assign balik ke owner lama).
    // ----------------------------------------------------------------
    public function testIncomingMembukaKembaliTanpaMengembalikanOwnerLama(): void
    {
        // Conversation pernah dipegang user 11, lalu ditutup -> assigned_to NULL.
        $conv = $this->seedConv([
            'status'      => 'closed',
            'assigned_to' => null,
            'closed_at'   => date('Y-m-d H:i:s'),
            'closed_by'   => 11,
        ]);
        $chatId = $this->row($conv)['chat_id'];

        $response = $this->postGatewayMessage([
            'wa_message_id'     => 'WAMSG-REOPEN-0001',
            'chat_id'           => $chatId,
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'Halo, mau tanya lagi.',
            'message_timestamp' => $this->nowIso(),
            'direction'         => 'incoming',
        ]);

        $response->assertStatus(200);

        $row = $this->row($conv);
        $this->assertSame('open', $row['status'], 'Incoming customer harus membuka kembali percakapan closed.');
        $this->assertNull($row['assigned_to'], 'Reopen TIDAK boleh mengembalikan assigned_to ke owner lama.');
    }

    // ----------------------------------------------------------------
    // Skenario 9: UI tidak menampilkan tombol Tutup bagi user tanpa hak.
    // ----------------------------------------------------------------
    public function testBisaDitutupFalseUntukStaffLainDanClosedDanGrup(): void
    {
        $this->seedUser(11);
        $this->seedUser(12);

        $milikStaffLain = $this->seedConv(['assigned_to' => 11]);
        $sudahClosed    = $this->seedConv(['status' => 'closed', 'assigned_to' => null]);
        $grup           = $this->seedConv(['jid_type' => 'group', 'assigned_to' => null]);

        // Staff 12 mengintip daftar: milik staff lain -> false; closed -> false; grup -> false.
        $this->assertFalse($this->bisaDitutup(12, 'kasir', $milikStaffLain));
        $this->assertFalse($this->bisaDitutup(12, 'kasir', $sudahClosed));
        $this->assertFalse($this->bisaDitutup(12, 'kasir', $grup));
    }

    // ----------------------------------------------------------------
    // Skenario 10: UI menampilkan tombol Tutup bagi owner/admin pada
    // percakapan personal yang open (dan unassigned -> true).
    // ----------------------------------------------------------------
    public function testBisaDitutupTrueUntukOwnerAdminDanUnassigned(): void
    {
        $this->seedUser(11);
        $this->seedUser(1, 'admin');

        $milikSendiri  = $this->seedConv(['assigned_to' => 11]);
        $unassigned    = $this->seedConv(['assigned_to' => null]);
        $milikStaffLain = $this->seedConv(['assigned_to' => 11]);

        $this->assertTrue($this->bisaDitutup(11, 'kasir', $milikSendiri));
        $this->assertTrue($this->bisaDitutup(11, 'kasir', $unassigned));
        $this->assertTrue($this->bisaDitutup(1, 'admin', $milikStaffLain));
    }

    // ----------------------------------------------------------------
    // Skenario tambahan: setelah ditutup, flag bisa_ditutup jadi false
    // (server) -- UI tidak menampilkan tombol lagi setelah Tutup sukses.
    // ----------------------------------------------------------------
    public function testBisaDitutupFalseSetelahDitutup(): void
    {
        $this->seedUser(11);
        $conv = $this->seedConv(['assigned_to' => 11]);

        $this->assertTrue($this->bisaDitutup(11, 'kasir', $conv));

        $this->tutup(11, 'kasir', $conv)->assertStatus(200);

        $this->assertFalse($this->bisaDitutup(11, 'kasir', $conv));
    }
}
