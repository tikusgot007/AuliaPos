<?php

use App\Models\ConversationHandoffModel;
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

    // ================================================================
    // TB-03 (TASK-009) -- GET /inbox/percakapan/(:num)/handoff
    // ================================================================
    //
    // Endpoint KHUSUS riwayat Handoff (P-04). Gerbang baca sengaja hanya
    // `auth` (Q7): staff mana pun boleh MEMBACA riwayat, beda dari jalur
    // tulis yang mensyaratkan assignee. `GET /inbox/api/conversations/
    // (:num)/messages` tidak boleh berubah.

    public function testG01RiwayatDibacaTerbaruDuluLengkapDanTanpaGerbangAssignee(): void
    {
        $id = $this->seedConversation(['assigned_to' => 7]);

        // Dua penyerahan berurutan: 7 -> 8, lalu 8 -> 11.
        $this->withSession($this->sesi('kasir', 7))
            ->post(self::HANDOFF_URL . $id . '/handoff', $this->validPayload(8, 7))
            ->assertOK();

        $this->withSession($this->sesi('kasir', 8))
            ->post(self::HANDOFF_URL . $id . '/handoff', $this->validPayload(11, 8))
            ->assertOK();

        // Dibaca oleh staff 12 yang TIDAK terlibat apa pun -- inilah bukti
        // gerbang baca Q7 (auth saja, tanpa gerbang assignee).
        $response = $this->withSession($this->sesi('kasir', 12))
            ->get(self::HANDOFF_URL . $id . '/handoff');

        $response->assertOK();

        $json = json_decode($response->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertSame(50, $json['limit'], 'Envelope P-04 harus menyertakan limit 50.');
        $this->assertCount(2, $json['handoffs']);

        $riwayat = $json['handoffs'];

        // Terbaru dulu: penyerahan 8 -> 11 muncul lebih dulu.
        $this->assertGreaterThan((int) $riwayat[1]['id'], (int) $riwayat[0]['id']);
        $this->assertSame(8, (int) $riwayat[0]['from_user_id']);
        $this->assertSame(11, (int) $riwayat[0]['to_user_id']);
        $this->assertSame(8, (int) $riwayat[0]['initiated_by_user_id']);
        $this->assertSame(7, (int) $riwayat[1]['from_user_id']);
        $this->assertSame(8, (int) $riwayat[1]['to_user_id']);
        $this->assertSame(7, (int) $riwayat[1]['initiated_by_user_id']);

        // Envelope entri P-04 lengkap (8 field) dan tidak kosong.
        foreach ($riwayat as $entri) {
            foreach (['id', 'from_user_id', 'to_user_id', 'initiated_by_user_id', 'summary', 'next_action', 'note', 'created_at'] as $field) {
                $this->assertArrayHasKey($field, $entri);
            }
            $this->assertNotEmpty($entri['summary']);
            $this->assertNotEmpty($entri['next_action']);
            $this->assertNotEmpty($entri['created_at']);
        }

        // Membaca riwayat tidak menulis apa pun (GET murni).
        $this->assertSame(0, db_connect('inbox')->table('messages')
            ->where('conversation_id', $id)
            ->countAllResults());
        $this->assertCount(2, $this->handoffRows($id));

        // Regresi kontrak lama: endpoint thread pesan tetap seperti semula.
        $this->withSession($this->sesi('kasir', 12))
            ->get('inbox/api/conversations/' . $id . '/messages')
            ->assertOK();
    }

    public function testG02RiwayatDibatasiLimaPuluhEntriTerbaru(): void
    {
        $id = $this->seedConversation(['assigned_to' => 7]);
        $model = new ConversationHandoffModel();

        for ($i = 1; $i <= 55; $i++) {
            $model->insertHandoff([
                'conversation_id'      => $id,
                'from_user_id'         => 7,
                'to_user_id'           => 8,
                'initiated_by_user_id' => 7,
                'summary'              => 'Handoff #' . $i,
                'next_action'          => 'Lanjutkan penanganan.',
                'note'                 => null,
                'created_at'           => date('Y-m-d H:i:s'),
            ]);
        }

        $response = $this->withSession($this->sesi('kasir', 12))
            ->get(self::HANDOFF_URL . $id . '/handoff');

        $response->assertOK();

        $json = json_decode($response->getJSON(), true);
        $this->assertSame(50, $json['limit']);
        $this->assertCount(50, $json['handoffs'], 'Riwayat harus dipotong di 50 entri terbaru.');
        $this->assertSame('Handoff #55', $json['handoffs'][0]['summary']);
        $this->assertSame('Handoff #6', $json['handoffs'][49]['summary']);
    }

    public function testG03RiwayatPercakapanTidakDikenalDitolak404(): void
    {
        $response = $this->withSession($this->sesi('kasir', 7))
            ->get(self::HANDOFF_URL . '999999/handoff');

        $response->assertStatus(404);

        $json = json_decode($response->getJSON(), true);
        $this->assertSame('error', $json['status']);
        $this->assertSame('Conversation tidak ditemukan.', $json['message']);
    }

    public function testG04RiwayatWajibLoginLewatFilterAuth(): void
    {
        $id = $this->seedConversation(['assigned_to' => 7]);

        // Tanpa session: filter `auth` mengalihkan ke /login. Test ini
        // memastikan route GET handoff benar-benar terpasang filter
        // (bukan hanya terdaftar di Routes.php).
        $response = $this->get(self::HANDOFF_URL . $id . '/handoff');

        $response->assertRedirectTo('/login');
        $response->assertStatus(302);
    }

    public function testG05HalamanInboxMerenderPanelRiwayatHandoff(): void
    {
        // TASK-010: test render (bukan cuma kontrak JSON) -- membuktikan
        // panel riwayat benar-benar ada di markup dan peta nama kasirnya
        // ter-render sebagai JSON dari sumber Q6 yang sama dengan dropdown
        // dialog Handoff (bukan daftar terpisah yang bisa drift).
        $response = $this->withSession($this->sesi('kasir', 7))->get('inbox');

        $response->assertOK();

        // Body dibaca langsung (TestResponse meneruskan getBody() ke
        // response aslinya) -- assertSee() milik CI4 mengambil argumen
        // kedua sebagai CSS selector, bukan flag escape.
        $body = (string) $response->getBody();

        $this->assertStringContainsString('id="panelRiwayatHandoff"', $body);
        $this->assertStringContainsString('id="daftarRiwayatHandoff"', $body);
        $this->assertStringContainsString('"11":"Kasir Sebelas"', $body);
    }

    // ================================================================
    // TB-04 (TASK-012) -- edge cases + boundaries
    // ================================================================

    public function testE01NoteKosongDiperbolehkanDanFromSelaluSamaDenganInisiator(): void
    {
        // `note` opsional (REQ-H04): string kosong disimpan sebagai NULL,
        // bukan sebagai string kosong.
        $id = $this->seedConversation(['assigned_to' => 7]);

        $payload = $this->validPayload(8, 7);
        $payload['note'] = '';

        $this->withSession($this->sesi('kasir', 7))
            ->post(self::HANDOFF_URL . $id . '/handoff', $payload)
            ->assertOK();

        $rows = $this->handoffRows($id);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['note']);

        // Invariant K-06 pada kasus ber-owner: from = initiator = assignee
        // sebelum write (per D-01 jalur ini hanya bisa ditempuh assignee).
        $this->assertSame(7, (int) $rows[0]['from_user_id']);
        $this->assertSame((int) $rows[0]['from_user_id'], (int) $rows[0]['initiated_by_user_id']);
    }

    public function testE02SummaryDanNextActionSpasiSajaDitolak400(): void
    {
        $id = $this->seedConversation(['assigned_to' => 7]);

        foreach ([["\t \n", 'Tindakan valid.'], ['Ringkasan valid.', "\t \n"]] as [$summary, $nextAction]) {
            $payload = $this->validPayload(8, 7);
            $payload['summary'] = $summary;
            $payload['next_action'] = $nextAction;

            $this->withSession($this->sesi('kasir', 7))
                ->post(self::HANDOFF_URL . $id . '/handoff', $payload)
                ->assertStatus(400);
        }

        // Ditolak tanpa efek samping apa pun.
        $this->assertSame(7, (int) $this->conversation($id)['assigned_to']);
        $this->assertCount(0, $this->handoffRows($id));
    }

    public function testE03HandoffKeDiriSendiriDitolak400WalauBukanPemilik(): void
    {
        // Q8 (locked): validasi self-Handoff (step 3) mendahului gerbang
        // inisiator (step 4), jadi non-assignee yang mengirim ke dirinya
        // sendiri menerima 400 -- bukan 403 (urutan Q3 deterministik).
        $id = $this->seedConversation(['assigned_to' => 7]);

        $response = $this->withSession($this->sesi('kasir', 8))
            ->post(self::HANDOFF_URL . $id . '/handoff', $this->validPayload(8, 7));

        $response->assertStatus(400);
        $response->assertJSONFragment(['message' => 'Tidak bisa menyerahkan percakapan ke diri sendiri.']);

        $this->assertSame(7, (int) $this->conversation($id)['assigned_to']);
        $this->assertCount(0, $this->handoffRows($id));
    }

    public function testE04GerbangInisiatorBelumDiambilDanPosisiAdmin(): void
    {
        // (a) P-05: pada `belum_diambil` kasir aktif mana pun boleh
        // menginisiasi (tidak ada assignee), dan from_user_id tetap NULL
        // karena percakapan memang belum pernah dimiliki (K-06).
        $idA = $this->seedConversation(['assigned_to' => null]);

        // Klaim klien untuk percakapan yang belum diambil = expected_owner
        // kosong (Q5), bukan id siapa pun.
        $payloadA = $this->validPayload(11, 7);
        $payloadA['expected_owner'] = '';

        $this->withSession($this->sesi('kasir', 8))
            ->post(self::HANDOFF_URL . $idA . '/handoff', $payloadA)
            ->assertOK();

        $rowsA = $this->handoffRows($idA);
        $this->assertCount(1, $rowsA);
        $this->assertNull($rowsA[0]['from_user_id']);
        $this->assertSame(8, (int) $rowsA[0]['initiated_by_user_id']);
        $this->assertSame(11, (int) $this->conversation($idA)['assigned_to']);

        // (b) Admin non-assignee pada `belum_diambil` = 403. REQ-H01 (Plan)
        // membatasi pengecualian ini pada "kasir aktif", jadi teks PLAN
        // MENANG atas Q2 (yang menyebut admin boleh menginisiasi) sesuai
        // RISK-01 "Plan menang bila konflik". Kalau tim ingin admin
        // diizinkan di titik ini, itu PERUBAHAN requirement -> wajib lewat
        // /sdlc-clarify-reqs, bukan diubah diam-diam di sini.
        $idB = $this->seedConversation(['assigned_to' => null]);

        $responseB = $this->withSession($this->sesi('admin', 9))
            ->post(self::HANDOFF_URL . $idB . '/handoff', $this->validPayload(11, 7));

        $responseB->assertStatus(403);
        $responseB->assertJSONFragment(['message' => 'Hanya kasir aktif yang bisa menyerahkan percakapan yang belum diambil.']);
        $this->assertNull($this->conversation($idB)['assigned_to']);
        $this->assertCount(0, $this->handoffRows($idB));

        // (c) Admin non-assignee pada percakapan yang sudah diambil: 403
        // juga (AC-H08) -- tidak ada jalur paksa admin di Fase 2a.
        $idC = $this->seedConversation(['assigned_to' => 7]);

        $responseC = $this->withSession($this->sesi('admin', 9))
            ->post(self::HANDOFF_URL . $idC . '/handoff', $this->validPayload(11, 7));

        $responseC->assertStatus(403);
        $responseC->assertJSONFragment(['message' => 'Hanya staff yang sedang menangani percakapan ini yang bisa menyerahkannya.']);
        $this->assertSame(7, (int) $this->conversation($idC)['assigned_to']);
        $this->assertCount(0, $this->handoffRows($idC));
    }

    public function testE05PercakapanTidakDikenalDitolak404(): void
    {
        // 404 terjadi SEBELUM validasi apa pun, jadi payload valid pun
        // tidak menulis apa-apa.
        $response = $this->withSession($this->sesi('kasir', 7))
            ->post(self::HANDOFF_URL . '999999/handoff', $this->validPayload(8, 7));

        $response->assertStatus(404);
        $response->assertJSONFragment(['message' => 'Conversation tidak ditemukan.']);

        $this->assertSame(0, db_connect('inbox')->table('conversation_handoffs')->countAllResults());
    }

    public function testE06AmbilPercakapanDiAntaraBukaDialogDanSubmitMenyebabkan409(): void
    {
        // Dialog dibuka saat percakapan MASIH belum diambil (klaim klien:
        // expected_owner = belum diambil). Sebelum submit, staff yang sama
        // mengambil percakapan lewat ambilPercakapan() -- jalur NON-Handoff
        // yang menggeser ownership. Klaim 'belum diambil' jadi basi -> 409
        // (REQ-C01: conditional write hanya peduli ownership bergerak,
        // bukan siapa yang menggesernya).
        $id = $this->seedConversation(['assigned_to' => null]);

        $this->withSession($this->sesi('kasir', 7))
            ->post('inbox/percakapan/' . $id . '/ambil')
            ->assertOK();

        $this->assertSame(7, (int) $this->conversation($id)['assigned_to']);

        $payload = $this->validPayload(8, 7);
        $payload['expected_owner'] = '';

        $response = $this->withSession($this->sesi('kasir', 7))
            ->post(self::HANDOFF_URL . $id . '/handoff', $payload);

        $response->assertStatus(409);

        $json = json_decode($response->getJSON(), true);
        $this->assertSame(7, (int) $json['current_owner_id']);

        // Tidak ada penimpaan ownership dan tidak ada riwayat yatim.
        $this->assertSame(7, (int) $this->conversation($id)['assigned_to']);
        $this->assertCount(0, $this->handoffRows($id));
    }

    public function testE07HandoffPadaDitundaMempertahankanSnoozeDanTetapTabDitunda(): void
    {
        $snooze = (new \DateTime('now', new \DateTimeZone('Asia/Jakarta')))
            ->modify('+2 hours')
            ->format('Y-m-d H:i:s');

        $id = $this->seedConversation([
            'assigned_to'   => 7,
            'snoozed_until' => $snooze,
        ]);

        $response = $this->withSession($this->sesi('kasir', 7))
            ->post(self::HANDOFF_URL . $id . '/handoff', $this->validPayload(8, 7));

        $response->assertOK();

        $json = json_decode($response->getJSON(), true);

        // REQ-H06 + PRD GH-006: snooze TIDAK direset, percakapan tetap di
        // tab Ditunda atas nama penerima baru (bukan pindah ke tab lain).
        $this->assertSame($snooze, $this->conversation($id)['snoozed_until']);
        $this->assertSame('ditunda', $json['conversation']['queue_status']);
        $this->assertSame('Kasir Delapan', $json['conversation']['assigned_to_name']);
        $this->assertSame(8, (int) $json['conversation']['assigned_to']);
    }

    public function testE08HandoffDariBelumDiambilPindahKeTabOpenAtasNamaPenerima(): void
    {
        $id = $this->seedConversation(['assigned_to' => null]);

        // Percakapan belum diambil -> klaim klien expected_owner kosong
        // (Q5), bukan id siapa pun.
        $payload = $this->validPayload(8, 7);
        $payload['expected_owner'] = '';

        $response = $this->withSession($this->sesi('kasir', 7))
            ->post(self::HANDOFF_URL . $id . '/handoff', $payload);

        $response->assertOK();

        $json = json_decode($response->getJSON(), true);

        // PRD GH-006: dari Belum Diambil -> Open atas nama penerima.
        // (Handoff tidak menulis last_message_* / last_seen_by_assignee_at,
        // jadi queue_status turunannya 'open' tanpa efek samping lain.)
        $this->assertSame('open', $json['conversation']['queue_status']);
        $this->assertSame('Kasir Delapan', $json['conversation']['assigned_to_name']);
        $this->assertSame(8, (int) $json['conversation']['assigned_to']);
        $this->assertSame(8, (int) $this->conversation($id)['assigned_to']);
    }

    // ================================================================
    // Phase 1 Refactor (TASK-101..TASK-105) -- hardening + micro-contracts
    // ================================================================

    public function testE09SummaryBertipeArrayDitolak400OwnershipDanRiwayatUtuh(): void
    {
        // REQ-001 (CR-01): nilai non-string (array) ditolak 400 SEBELUM
        // coercion apa pun, jadi array tidak pernah tersimpan sebagai
        // string "Array" di kolom teks wajib.
        $id = $this->seedConversation(['assigned_to' => 7]);

        $payload = $this->validPayload(8, 7);
        $payload['summary'] = ['bukan', 'teks'];

        $response = $this->withSession($this->sesi('kasir', 7))
            ->post(self::HANDOFF_URL . $id . '/handoff', $payload);

        $response->assertStatus(400);
        $response->assertJSONFragment(['message' => 'Ringkasan, tindakan berikutnya, dan catatan harus berupa teks.']);

        // Ownership tetap 7 dan tidak ada baris riwayat yatim.
        $this->assertSame(7, (int) $this->conversation($id)['assigned_to']);
        $this->assertCount(0, $this->handoffRows($id));
    }
}
