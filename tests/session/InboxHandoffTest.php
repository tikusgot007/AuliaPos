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
 * TB-02 (TASK-006) adds the collision proofs C01-C04: sequential race
 * (exactly one 200 + one named 409), stale expected_owner, forced
 * history-insert failure that must roll back, and the `User #{id}`
 * naming fallback. Two decisions are recorded because they shape these
 * tests:
 *
 * D-01 -- a request can only reach the conditional write while its
 * initiator IS the current assignee (P-05 initiator gate evaluated
 * before the transaction, Q3 order). The losing request in a collision
 * is therefore the CURRENT owner holding a stale dialog. Spec v1.0
 * Section 12 ("staff 9 is not the owner yet still receives 409")
 * contradicts REQ-H01/P-05 (that staff gets 403 at step 4); the Plan
 * supersedes it (RISK-01) and it is NOT implemented here. Widening the
 * gate would require a new /sdlc-clarify-reqs round, never a silent
 * code change.
 *
 * D-02 -- the forced insert failure is produced by a MariaDB trigger
 * (BEFORE INSERT ... SIGNAL SQLSTATE '45000'), not by mocking the DB
 * layer (Spec Section 6 forbids new seams). It is dropped in a
 * `finally` and again in setUp(), because a leftover trigger would
 * break every later history insert in the suite.
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

    /**
     * D-02: nama trigger uji forced-insert-failure. Sangat spesifik
     * supaya tidak mungkin berbenturan dengan objek database lain, dan
     * selalu di-DROP (finally + setUp).
     */
    private const TRIGGER_INSERT_FAIL = 'trg_handoff_fail_insert';

    protected function setUp(): void
    {
        parent::setUp();

        $inbox = db_connect('inbox');

        // D-02 safety net: kalau test forced-insert-failure (C03) berhenti
        // keras sebelum finally-nya DROP, trigger sisa akan menggagalkan
        // SEMUA insert riwayat di test berikutnya. Selalu mulai bersih.
        $inbox->query('DROP TRIGGER IF EXISTS trg_handoff_fail_insert');

        $inbox->table('conversation_handoffs')->emptyTable();
        $inbox->table('messages')->emptyTable();
        $inbox->table('conversation_identities')->emptyTable();
        $inbox->table('conversations')->emptyTable();

        db_connect()->table('users')->emptyTable();

        // Kasir aktif: 7 (inisiator TB-01), 8 (target TB-01), 11 & 12
        // (pemenang/penerima skenario tabrakan TB-02 -- angka sama dengan
        // demo Plan GOAL-TB02). Non-aktif: 10. Admin: 9 (dipindah dari 11
        // supaya 11 bisa jadi kasir aktif untuk uji collision).
        $this->seedUser(7, 'Kasir Tujuh', 'kasir', 1);
        $this->seedUser(8, 'Kasir Delapan', 'kasir', 1);
        $this->seedUser(9, 'Boss Admin', 'admin', 1);
        $this->seedUser(10, 'Kasir Nonaktif', 'kasir', 0);
        $this->seedUser(11, 'Kasir Sebelas', 'kasir', 1);
        $this->seedUser(12, 'Kasir Dua Belas', 'kasir', 1);
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

    /**
     * D-02 -- deterministic way to force the history INSERT to fail,
     * without touching production code and without mocking the DB layer.
     * A MariaDB trigger (BEFORE INSERT + SIGNAL) reproduces a real SQL
     * error; because Config\Database::$inbox has DBDebug = true, CI4
     * throws DatabaseException, which the controller catches ->
     * transRollback() + HTTP 500 (REQ-H09 / AC-C03).
     *
     * The trigger name is deliberately specific so it cannot clash with
     * any other object; recovery is two-layered (finally + setUp).
     */
    private function pasangTriggerGagalInsert(string $triggerName): void
    {
        db_connect('inbox')->query(
            "CREATE TRIGGER `{$triggerName}` BEFORE INSERT ON `conversation_handoffs`"
            . " FOR EACH ROW SIGNAL SQLSTATE '45000'"
            . " SET MESSAGE_TEXT = 'forced handoff history insert failure (test)'"
        );
    }

    private function lepasTriggerGagalInsert(string $triggerName): void
    {
        db_connect('inbox')->query("DROP TRIGGER IF EXISTS `{$triggerName}`");
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

        // Admin (P-03): role admin selalu ditolak walau aktif (id 9 --
        // id 11 sengaja jadi kasir aktif untuk skenario tabrakan TB-02).
        $response = $this->withSession($this->sesi('kasir', 7))
            ->post(self::HANDOFF_URL . $id . '/handoff', $this->validPayload(9, 7));
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

    // ================================================================
    // TB-02 (TASK-006) -- Collision Detection: race, stale, rollback
    // ================================================================
    //
    // D-01 (recorded because one Spec example contradicts the locked
    // gate): the initiator gate P-05/Q1 plus the normative order Q3 mean
    // only a request whose initiator IS the current assignee can reach
    // the conditional write. The losing request in a collision is
    // therefore the CURRENT owner holding a stale dialog. C01 models it
    // exactly as the Plan GOAL-TB02 demo: 7 wins (owner 7 -> 11), then
    // 11 (new owner, expected_owner still 7) loses with 409 naming 11.

    public function testC01RaceSekuensialTepatSatuPemenangDan409MenyebutPemilikSah(): void
    {
        $id = $this->seedConversation(['assigned_to' => 7]);

        // Satu `expected_owner` dibaca sekali (7) dan dipakai kedua
        // permintaan; req1 diterapkan lebih dulu dan MENANG.
        $response1 = $this->withSession($this->sesi('kasir', 7))
            ->post(self::HANDOFF_URL . $id . '/handoff', $this->validPayload(11, 7));

        $response1->assertOK();
        $this->assertSame(11, (int) $this->conversation($id)['assigned_to']);

        // req2 masih memakai nilai BASI (7) -> conditional write kalah.
        $response2 = $this->withSession($this->sesi('kasir', 11))
            ->post(self::HANDOFF_URL . $id . '/handoff', $this->validPayload(12, 7));

        $response2->assertStatus(409);
        $json = json_decode($response2->getJSON(), true);
        $this->assertIsArray($json);
        $this->assertSame('error', $json['status']);
        $this->assertSame(11, (int) $json['current_owner_id']);
        $this->assertStringContainsString('Kasir Sebelas', $json['message']);

        // Kepemilikan akhir mencerminkan SATU staff saja (11) dan jalur
        // kalah tidak menulis riwayat -> tepat satu baris.
        $this->assertSame(11, (int) $this->conversation($id)['assigned_to']);
        $rows = $this->handoffRows($id);
        $this->assertCount(1, $rows);
        $this->assertSame(11, (int) $rows[0]['to_user_id']);
        $this->assertSame(7, (int) $rows[0]['from_user_id']);
    }

    public function testC01bNonAssigneeDenganExpectedOwnerBasiTetapDitolak403(): void
    {
        // Sisi lain D-01: non-assignee tidak pernah sampai ke conditional
        // write -- gerbang inisiator (step 4) mendahului transaksi, jadi
        // jawabannya 403 (AC-H08), BUKAN 409. Ini bukti eksplisit bahwa
        // contoh Section 12 Spec v1.0 ("staff 9 bukan owner tetap 409")
        // tidak diikuti karena bertentangan dengan REQ-H01/P-05.
        $id = $this->seedConversation(['assigned_to' => 7]);

        $response = $this->withSession($this->sesi('kasir', 8))
            ->post(self::HANDOFF_URL . $id . '/handoff', $this->validPayload(11, 7));

        $response->assertStatus(403);
        $response->assertJSONFragment(['status' => 'error']);

        $this->assertSame(7, (int) $this->conversation($id)['assigned_to']);
        $this->assertCount(0, $this->handoffRows($id));
    }

    public function testC02StaleExpectedOwnerDitolak409TanpaMengubahOwnershipDanRiwayat(): void
    {
        $id = $this->seedConversation(['assigned_to' => 7]);

        // Dialog dibuka saat percakapan masih milik 8, lalu kepemilikan
        // berpindah ke 7 -- nilai expected_owner klien sudah basi.
        $response = $this->withSession($this->sesi('kasir', 7))
            ->post(self::HANDOFF_URL . $id . '/handoff', $this->validPayload(12, 8));

        $response->assertStatus(409);
        $json = json_decode($response->getJSON(), true);
        $this->assertSame(7, (int) $json['current_owner_id']);
        $this->assertStringContainsString('Kasir Tujuh', $json['message']);

        // Klaim basi "saya lihat belum diambil" (expected_owner string
        // kosong -> NULL) juga kalah, karena pemilik sebenarnya bukan NULL
        // (`<=>` NULL-safe tidak cocok) -- REQ-C01/Q5.
        $payload = $this->validPayload(12, 7);
        $payload['expected_owner'] = '';
        $response2 = $this->withSession($this->sesi('kasir', 7))
            ->post(self::HANDOFF_URL . $id . '/handoff', $payload);
        $response2->assertStatus(409);
        $response2->assertJSONFragment(['status' => 'error']);

        // Tanpa perubahan apa pun: ownership tetap 7, nol baris riwayat
        // (tolak bersih, tanpa menimpa pemilik sah).
        $this->assertSame(7, (int) $this->conversation($id)['assigned_to']);
        $this->assertCount(0, $this->handoffRows($id));
    }

    public function testC03GagalInsertRiwayatRollbackOwnershipDanRespons500(): void
    {
        $id = $this->seedConversation(['assigned_to' => 7]);

        // D-02: paksa INSERT riwayat gagal (lihat pasangTriggerGagalInsert()).
        $this->pasangTriggerGagalInsert(self::TRIGGER_INSERT_FAIL);

        try {
            $response = $this->withSession($this->sesi('kasir', 7))
                ->post(self::HANDOFF_URL . $id . '/handoff', $this->validPayload(8, 7));

            $response->assertStatus(500);
            $response->assertJSONFragment(['status' => 'error']);
            // Pesan mengunci CABANG rollback yang benar (bukan 500 dari
            // sebab lain) -- REQ-H09/AC-C03.
            $response->assertJSONFragment(['message' => 'Gagal menyimpan riwayat Handoff, percakapan tidak berpindah.']);

            // AC-C03: ownership UTUH (tetap pemilik lama) dan tidak ada
            // baris riwayat yatim -- transaksi benar-benar di-rollback.
            $this->assertSame(7, (int) $this->conversation($id)['assigned_to']);
            $this->assertCount(0, $this->handoffRows($id));
        } finally {
            // Selalu dibersihkan, termasuk saat assertion di atas gagal,
            // supaya trigger tidak tertinggal dan merusak test lain.
            $this->lepasTriggerGagalInsert(self::TRIGGER_INSERT_FAIL);
        }
    }

    public function testC04PemilikSahTanpaBarisUserMemakaiFallbackUserId(): void
    {
        // Pemilik 42 tidak punya baris di `users` (akun sudah dihapus) --
        // pesan 409 tetap informatif lewat fallback 'User #42'
        // (REQ-C02 / ASSUMPTION-004). Inisiator tetap boleh karena dia
        // assignee SAAT INI (gerbang P-05 hanya butuh identitas itu).
        $id = $this->seedConversation(['assigned_to' => 42]);

        $response = $this->withSession($this->sesi('kasir', 42))
            ->post(self::HANDOFF_URL . $id . '/handoff', $this->validPayload(12, 7));

        $response->assertStatus(409);
        $json = json_decode($response->getJSON(), true);
        $this->assertSame(42, (int) $json['current_owner_id']);
        $this->assertStringContainsString('User #42', $json['message']);

        $this->assertSame(42, (int) $this->conversation($id)['assigned_to']);
        $this->assertCount(0, $this->handoffRows($id));
    }
}
