<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * M3 Phase 2a (TB-01, TASK-003) -- Inbox::handoffPercakapan() H01-H08.
 *
 * Covers the normative gate order (Plan TASK-003 / locked Q3):
 * 404 -> 409 selesai (P-01) -> 400 validation (P-02/Q5) -> 403 initiator
 * gate (P-05/AC-H08) -> 403 target gate (P-03) -> conditional-write
 * transaction (REQ-H09/C01/C02).
 *
 * Users are seeded into the `tests` group (SQLite in-memory) because
 * UserModel has no explicit $DBGroup and resolves to the default group,
 * which the Database constructor switches to `tests` under ENVIRONMENT
 * testing -- the same setup as UserModelDaftarKasirAktifTest.
 *
 * Conversations / messages / handoffs live in the real `inbox` group
 * (MySQL aulia_inboxdb), same as InboxInternalNoteTest.
 *
 * @internal
 */
final class InboxHandoffTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    protected $namespace = 'Tests\\Support';
    protected $migrate   = true;
    protected $refresh   = true;

    private const HANDOFF_URL = 'inbox/percakapan/';

    protected function setUp(): void
    {
        parent::setUp();

        $inbox = db_connect('inbox');
        $inbox->table('conversation_handoffs')->emptyTable();
        $inbox->table('messages')->emptyTable();
        $inbox->table('conversation_identities')->emptyTable();
        $inbox->table('conversations')->emptyTable();

        db_connect()->table('users')->emptyTable();

        // Kasir aktif: 7 (inisiator), 8 (target). Non-aktif: 10.
        // Admin: 11. Semua kandidat target diuji lewat daftarKasirAktif().
        $this->seedUser(7, 'Kasir Tujuh', 'kasir', 1);
        $this->seedUser(8, 'Kasir Delapan', 'kasir', 1);
        $this->seedUser(10, 'Kasir Nonaktif', 'kasir', 0);
        $this->seedUser(11, 'Boss Admin', 'admin', 1);
    }

    private function seedUser(int $id, string $nama, string $role, int $isActive): void
    {
        db_connect()->table('users')->insert([
            'id'        => $id,
            'username'  => 'user' . $id,
            'nama'      => $nama,
            'role'      => $role,
            'is_active' => $isActive,
        ]);
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
        $db  = db_connect('inbox');
        $now = date('Y-m-d H:i:s');

        $db->table('conversations')->insert(array_merge([
            'chat_id'                => '628' . random_int(100000000, 999999999) . '@s.whatsapp.net',
            'jid_type'               => 'pn',
            'status'                 => 'open',
            'assigned_to'            => 7,
            'last_message_direction' => 'incoming',
            'last_message_at'        => $now,
            'created_at'             => $now,
            'updated_at'             => $now,
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

    private function handoffRows(int $conversationId): array
    {
        return db_connect('inbox')
            ->table('conversation_handoffs')
            ->where('conversation_id', $conversationId)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
    }

    private function validPayload(int $toUserId, int $expectedOwner): array
    {
        return [
            'to_user_id'     => $toUserId,
            'summary'        => 'Serah terima ke shift berikutnya.',
            'next_action'    => 'Cek status pesanan meja 3.',
            'note'           => 'Opsional.',
            'expected_owner' => $expectedOwner,
        ];
    }

    public function testH01HandoffHappyPathMencatatRiwayatDanMemindahkanOwnership(): void
    {
        $id = $this->seedConversation(['assigned_to' => 7]);

        $response = $this->withSession($this->sesi('kasir', 7))
            ->post(self::HANDOFF_URL . $id . '/handoff', $this->validPayload(8, 7));

        $response->assertOK();
        $response->assertJSONFragment(['status' => 'success']);
        $response->assertJSONFragment(['to_user_id' => 8]);

        // Ownership berpindah ke target.
        $this->assertSame(8, (int) $this->conversation($id)['assigned_to']);

        // Tepat satu baris riwayat dengan from/to/initiated_by benar (K-06).
        $rows = $this->handoffRows($id);
        $this->assertCount(1, $rows);
        $this->assertSame(7, (int) $rows[0]['from_user_id']);
        $this->assertSame(8, (int) $rows[0]['to_user_id']);
        $this->assertSame(7, (int) $rows[0]['initiated_by_user_id']);
        $this->assertSame('Serah terima ke shift berikutnya.', $rows[0]['summary']);
        $this->assertSame('Cek status pesanan meja 3.', $rows[0]['next_action']);
        $this->assertSame('Opsional.', $rows[0]['note']);
        $this->assertNotEmpty($rows[0]['created_at']);

        // Response menyertakan id riwayat (Spec Section 4.3).
        $json = json_decode($response->getJSON(), true);
        $this->assertIsArray($json);
        $this->assertSame((int) $rows[0]['id'], (int) $json['handoff_id']);
    }

    public function testH02HandoffPadaSelesaiDitolak409(): void
    {
        // status=closed -> response_state=selesai -> queue_status=selesai.
        $id = $this->seedConversation(['status' => 'closed', 'assigned_to' => 7]);

        $response = $this->withSession($this->sesi('kasir', 7))
            ->post(self::HANDOFF_URL . $id . '/handoff', $this->validPayload(8, 7));

        $response->assertStatus(409);
        $response->assertJSONFragment(['status' => 'error']);

        // Ownership dan riwayat tidak berubah (AC-H02).
        $this->assertSame(7, (int) $this->conversation($id)['assigned_to']);
        $this->assertCount(0, $this->handoffRows($id));
    }

    public function testH03SummaryAtauNextActionKosongDitolak400(): void
    {
        $id = $this->seedConversation(['assigned_to' => 7]);

        $kosongSummary = $this->validPayload(8, 7);
        $kosongSummary['summary'] = '   ';
        $response = $this->withSession($this->sesi('kasir', 7))
            ->post(self::HANDOFF_URL . $id . '/handoff', $kosongSummary);
        $response->assertStatus(400);

        $kosongNext = $this->validPayload(8, 7);
        $kosongNext['next_action'] = '';
        $response = $this->withSession($this->sesi('kasir', 7))
            ->post(self::HANDOFF_URL . $id . '/handoff', $kosongNext);
        $response->assertStatus(400);

        // Over batas P-02 (4096) juga 400.
        $kepanjangan = $this->validPayload(8, 7);
        $kepanjangan['summary'] = str_repeat('a', 4097);
        $response = $this->withSession($this->sesi('kasir', 7))
            ->post(self::HANDOFF_URL . $id . '/handoff', $kepanjangan);
        $response->assertStatus(400);

        $this->assertSame(7, (int) $this->conversation($id)['assigned_to']);
        $this->assertCount(0, $this->handoffRows($id));
    }

    public function testH04HandoffKeDiriSendiriDitolak400(): void
    {
        $id = $this->seedConversation(['assigned_to' => 7]);

        $response = $this->withSession($this->sesi('kasir', 7))
            ->post(self::HANDOFF_URL . $id . '/handoff', $this->validPayload(7, 7));

        $response->assertStatus(400);
        $response->assertJSONFragment(['status' => 'error']);

        $this->assertSame(7, (int) $this->conversation($id)['assigned_to']);
        $this->assertCount(0, $this->handoffRows($id));
    }

    public function testH05TargetUnknownNonaktifAtauAdminDitolak403(): void
    {
        $id = $this->seedConversation(['assigned_to' => 7]);

        // Unknown id.
        $response = $this->withSession($this->sesi('kasir', 7))
            ->post(self::HANDOFF_URL . $id . '/handoff', $this->validPayload(9999, 7));
        $response->assertStatus(403);

        // Kasir non-aktif.
        $response = $this->withSession($this->sesi('kasir', 7))
            ->post(self::HANDOFF_URL . $id . '/handoff', $this->validPayload(10, 7));
        $response->assertStatus(403);

        // Admin (P-03): role admin selalu ditolak walau aktif.
        $response = $this->withSession($this->sesi('kasir', 7))
            ->post(self::HANDOFF_URL . $id . '/handoff', $this->validPayload(11, 7));
        $response->assertStatus(403);
        $response->assertJSONFragment(['status' => 'error']);

        // Tidak ada perubahan sama sekali dari tiga percobaan.
        $this->assertSame(7, (int) $this->conversation($id)['assigned_to']);
        $this->assertCount(0, $this->handoffRows($id));
    }

    public function testH06HandoffPercakapanBelumDiambilMencatatFromNull(): void
    {
        $id = $this->seedConversation(['assigned_to' => null]);

        $payload = $this->validPayload(8, 7);
        $payload['expected_owner'] = ''; // form-POST null -> string kosong (Q5)

        $response = $this->withSession($this->sesi('kasir', 7))
            ->post(self::HANDOFF_URL . $id . '/handoff', $payload);

        $response->assertOK();

        $this->assertSame(8, (int) $this->conversation($id)['assigned_to']);

        $rows = $this->handoffRows($id);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['from_user_id'], 'from_user_id must stay NULL for unassigned (K-06).');
        $this->assertSame(7, (int) $rows[0]['initiated_by_user_id'], 'initiated_by always comes from session.');
        $this->assertSame(8, (int) $rows[0]['to_user_id']);
    }

    public function testH07HandoffTidakMenulisMessagesDanLastMessageTidakBerubah(): void
    {
        $id = $this->seedConversation([
            'assigned_to'            => 7,
            'last_message_direction' => 'incoming',
            'last_message_at'        => '2026-09-23 08:00:00',
        ]);

        $before = $this->conversation($id);

        $response = $this->withSession($this->sesi('kasir', 7))
            ->post(self::HANDOFF_URL . $id . '/handoff', $this->validPayload(8, 7));

        $response->assertOK();

        // messages: nol baris dari Handoff (AC-H07 / CON-H01).
        $this->assertSame(0, db_connect('inbox')->table('messages')
            ->where('conversation_id', $id)
            ->countAllResults());

        // last_message_* tersimpan utuh.
        $after = $this->conversation($id);
        $this->assertSame($before['last_message_direction'], $after['last_message_direction']);
        $this->assertSame($before['last_message_at'], $after['last_message_at']);

        // Riwayat tetap satu baris (Handoff sukses tercatat).
        $this->assertCount(1, $this->handoffRows($id));
    }

    public function testH08NonAssigneeDitolak403(): void
    {
        // Pemilik = 7; inisiator = 8 (bukan assignee, tapi kasir aktif).
        $id = $this->seedConversation(['assigned_to' => 7]);

        $response = $this->withSession($this->sesi('kasir', 8))
            ->post(self::HANDOFF_URL . $id . '/handoff', $this->validPayload(7, 7));

        $response->assertStatus(403);
        $response->assertJSONFragment(['status' => 'error']);

        $this->assertSame(7, (int) $this->conversation($id)['assigned_to']);
        $this->assertCount(0, $this->handoffRows($id));
    }
}
