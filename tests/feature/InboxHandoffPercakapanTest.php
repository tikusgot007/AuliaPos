<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * TODO-Q2 (poin 2): POST /inbox/percakapan/(:num)/handoff
 * (Inbox::handoffPercakapan()).
 *
 * Setiap guard diuji dengan memastikan JAWABAN dan bahwa DATA TIDAK BERUBAH
 * (assigned_to tetap, tidak ada baris conversation_handoffs) untuk semua
 * jalur tolak; jalur sukses memastikan perpindahan + satu baris riwayat.
 *
 * `users` dibaca dari group default (SQLite :memory: saat testing) -> di-forge
 * per test, pola sama dengan InboxAmbilAlihTest.
 *
 * @internal
 */
final class InboxHandoffPercakapanTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private $inbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inbox = db_connect('inbox');
        $this->inbox->table('conversation_handoffs')->emptyTable();
        $this->inbox->table('messages')->emptyTable();
        $this->inbox->table('conversation_identities')->emptyTable();
        $this->inbox->table('conversations')->emptyTable();

        $forge = \Config\Database::forge();
        $forge->dropTable('users', true);
        $forge->addField([
            'id'        => ['type' => 'INTEGER', 'auto_increment' => true],
            'username'  => ['type' => 'TEXT'],
            'nama'      => ['type' => 'TEXT', 'null' => true],
            'inisial'   => ['type' => 'TEXT', 'null' => true],
            'role'      => ['type' => 'TEXT', 'default' => 'kasir'],
            'is_active' => ['type' => 'INTEGER', 'default' => 1],
            'priority'  => ['type' => 'INTEGER', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('users', true);

        $this->seedUser(11);
        $this->seedUser(12);
        $this->seedUser(13);
        $this->seedUser(1, 'admin');
        $this->seedUser(14, 'kasir', 0);
    }

    protected function tearDown(): void
    {
        \Config\Database::forge()->dropTable('users', true);
        parent::tearDown();
    }

    private function seedUser(int $id, string $role = 'kasir', int $isActive = 1): void
    {
        db_connect()->table('users')->insert([
            'id' => $id, 'username' => 'user' . $id, 'nama' => 'User ' . $id,
            'inisial' => 'U' . $id, 'role' => $role, 'is_active' => $isActive, 'priority' => null,
        ]);
    }

    private function seedConv(array $override = []): int
    {
        $now = date('Y-m-d H:i:s');

        $this->inbox->table('conversations')->insert(array_merge([
            'chat_id'                => '6281200007' . random_int(1000, 9999) . '@s.whatsapp.net',
            'jid_type'               => 'pn',
            'status'                 => 'open',
            'assigned_to'            => 11,
            'last_message_direction' => 'incoming',
            'last_message_at'        => $now,
            'created_at'             => $now,
            'updated_at'             => $now,
        ], $override));

        return (int) $this->inbox->insertID();
    }

    private function handoff(int $userId, string $role, int $conversationId, array $body)
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => $userId, 'role' => $role])
            ->post('/inbox/percakapan/' . $conversationId . '/handoff', $body);
    }

    private function json($response): array
    {
        $decoded = json_decode(trim(strip_tags($response->getBody())), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function ownerOf(int $conversationId): ?int
    {
        $row = $this->inbox->table('conversations')->where('id', $conversationId)->get()->getRowArray();

        return $row['assigned_to'] !== null ? (int) $row['assigned_to'] : null;
    }

    private function jumlahRiwayat(int $conversationId): int
    {
        return $this->inbox->table('conversation_handoffs')->where('conversation_id', $conversationId)->countAllResults();
    }

    /** Jalur tolak: kode + pesan sesuai, dan tidak ada perubahan data. */
    private function assertDitolakTanpaPerubahan($response, int $status, string $pesan, int $conv, ?int $ownerAwal): void
    {
        $response->assertStatus($status);
        $json = $this->json($response);
        $this->assertSame('error', $json['status']);
        $this->assertStringContainsString($pesan, $json['message']);
        $this->assertSame($ownerAwal, $this->ownerOf($conv), 'Penolakan tidak boleh mengubah assigned_to.');
        $this->assertSame(0, $this->jumlahRiwayat($conv), 'Penolakan tidak boleh menulis riwayat handoff.');
    }

    public function testSuksesMemindahkanPemilikDanMencatatRiwayat(): void
    {
        $conv = $this->seedConv();

        $res = $this->handoff(11, 'kasir', $conv, [
            'to_user_id' => '12', 'expected_owner' => '11', 'summary' => '  Tolong cek pembayaran  ',
        ]);

        $res->assertStatus(200);
        $json = $this->json($res);
        $this->assertSame('success', $json['status']);
        $this->assertSame('Percakapan berhasil diserahkan ke U12.', $json['message']);
        $this->assertSame(12, $json['to_user_id']);
        $this->assertSame(12, $this->ownerOf($conv));

        $riwayat = $this->inbox->table('conversation_handoffs')->where('conversation_id', $conv)->get()->getResultArray();
        $this->assertCount(1, $riwayat);
        $this->assertSame((int) $json['handoff_id'], (int) $riwayat[0]['id']);
        $this->assertSame(11, (int) $riwayat[0]['from_user_id']);
        $this->assertSame(12, (int) $riwayat[0]['to_user_id']);
        $this->assertSame(11, (int) $riwayat[0]['initiated_by_user_id']);
        $this->assertSame('Tolong cek pembayaran', $riwayat[0]['summary'], 'Catatan harus di-trim.');
    }

    public function testSuksesDariBelumDiambilOlehKasirAktif(): void
    {
        $conv = $this->seedConv(['assigned_to' => null]);

        $res = $this->handoff(13, 'kasir', $conv, ['to_user_id' => '12', 'expected_owner' => '']);

        $res->assertStatus(200);
        $this->assertSame(12, $this->ownerOf($conv));
        $riwayat = $this->inbox->table('conversation_handoffs')->where('conversation_id', $conv)->get()->getRowArray();
        $this->assertNull($riwayat['from_user_id'], 'Handoff dari belum_diambil: from_user_id NULL.');
        $this->assertSame(13, (int) $riwayat['initiated_by_user_id']);
    }

    public function testCatatanKosongDiizinkan(): void
    {
        $conv = $this->seedConv();

        $this->handoff(11, 'kasir', $conv, ['to_user_id' => '12', 'expected_owner' => '11'])->assertStatus(200);

        $this->assertSame('', $this->inbox->table('conversation_handoffs')->where('conversation_id', $conv)->get()->getRowArray()['summary']);
    }

    public function testPercakapanTidakAda404(): void
    {
        $res = $this->handoff(11, 'kasir', 999999, ['to_user_id' => '12', 'expected_owner' => '11']);

        $res->assertStatus(404);
        $this->assertSame(0, $this->inbox->table('conversation_handoffs')->countAllResults());
    }

    public function testGrupDitolak403(): void
    {
        $conv = $this->seedConv(['jid_type' => 'group', 'chat_id' => '120363000000' . random_int(1000, 9999) . '@g.us']);

        $res = $this->handoff(11, 'kasir', $conv, ['to_user_id' => '12', 'expected_owner' => '11']);

        $res->assertStatus(403);
        $this->assertSame(11, $this->ownerOf($conv));
        $this->assertSame(0, $this->jumlahRiwayat($conv));
    }

    public function testPercakapanSelesaiDitolak409(): void
    {
        $conv = $this->seedConv(['status' => 'closed']);

        $res = $this->handoff(11, 'kasir', $conv, ['to_user_id' => '12', 'expected_owner' => '11']);

        $this->assertDitolakTanpaPerubahan($res, 409, 'sudah selesai', $conv, 11);
        $this->assertSame(11, $this->json($res)['current_owner_id']);
    }

    public function testCatatanBukanTeksDitolak400(): void
    {
        $conv = $this->seedConv();

        $res = $this->handoff(11, 'kasir', $conv, ['to_user_id' => '12', 'expected_owner' => '11', 'summary' => ['a']]);

        $this->assertDitolakTanpaPerubahan($res, 400, 'harus berupa teks', $conv, 11);
    }

    public function testCatatanLebihDari4096Ditolak400(): void
    {
        $conv = $this->seedConv();

        $res = $this->handoff(11, 'kasir', $conv, [
            'to_user_id' => '12', 'expected_owner' => '11', 'summary' => str_repeat('a', 4097),
        ]);

        $this->assertDitolakTanpaPerubahan($res, 400, 'maksimal 4096', $conv, 11);
    }

    public function testCatatanTepat4096Diterima(): void
    {
        $conv = $this->seedConv();

        $this->handoff(11, 'kasir', $conv, [
            'to_user_id' => '12', 'expected_owner' => '11', 'summary' => str_repeat('a', 4096),
        ])->assertStatus(200);
    }

    public function testTargetTidakValidDitolak400(): void
    {
        $conv = $this->seedConv();

        foreach (['', 'abc', '0', '-3', '1.5'] as $target) {
            $res = $this->handoff(11, 'kasir', $conv, ['to_user_id' => $target, 'expected_owner' => '11']);
            $this->assertDitolakTanpaPerubahan($res, 400, 'to_user_id', $conv, 11);
        }
    }

    public function testKeDiriSendiriDitolak400(): void
    {
        $conv = $this->seedConv();

        $res = $this->handoff(11, 'kasir', $conv, ['to_user_id' => '11', 'expected_owner' => '11']);

        $this->assertDitolakTanpaPerubahan($res, 400, 'diri sendiri', $conv, 11);
    }

    public function testExpectedOwnerWajibDikirim(): void
    {
        $conv = $this->seedConv();

        $res = $this->handoff(11, 'kasir', $conv, ['to_user_id' => '12']);

        $this->assertDitolakTanpaPerubahan($res, 400, 'expected_owner wajib', $conv, 11);
    }

    public function testExpectedOwnerTidakValidDitolak400(): void
    {
        $conv = $this->seedConv();

        $res = $this->handoff(11, 'kasir', $conv, ['to_user_id' => '12', 'expected_owner' => 'x1']);

        $this->assertDitolakTanpaPerubahan($res, 400, 'expected_owner tidak valid', $conv, 11);
    }

    public function testBukanPemegangDitolak403(): void
    {
        $conv = $this->seedConv();

        $res = $this->handoff(13, 'kasir', $conv, ['to_user_id' => '12', 'expected_owner' => '11']);

        $this->assertDitolakTanpaPerubahan($res, 403, 'Hanya staff yang sedang menangani', $conv, 11);
    }

    /** Admin pun tidak boleh menyerahkan percakapan yang bukan miliknya (aturan handoff berbeda dari cekOwnership). */
    public function testAdminBukanPemegangDitolak403(): void
    {
        $conv = $this->seedConv();

        $res = $this->handoff(1, 'admin', $conv, ['to_user_id' => '12', 'expected_owner' => '11']);

        $this->assertDitolakTanpaPerubahan($res, 403, 'Hanya staff yang sedang menangani', $conv, 11);
    }

    public function testTanpaPemilikDiLuarBelumDiambilDitolak403(): void
    {
        // last_message_direction=outgoing -> queue_status 'menunggu' (bukan belum_diambil).
        $conv = $this->seedConv(['assigned_to' => null, 'last_message_direction' => 'outgoing']);

        $res = $this->handoff(13, 'kasir', $conv, ['to_user_id' => '12', 'expected_owner' => '']);

        $this->assertDitolakTanpaPerubahan($res, 403, 'tab Belum Diambil', $conv, null);
    }

    public function testBelumDiambilOlehNonKasirDitolak403(): void
    {
        $conv = $this->seedConv(['assigned_to' => null]);

        $res = $this->handoff(1, 'admin', $conv, ['to_user_id' => '12', 'expected_owner' => '']);

        $this->assertDitolakTanpaPerubahan($res, 403, 'Hanya kasir aktif', $conv, null);
    }

    public function testTargetAdminDitolak403(): void
    {
        $conv = $this->seedConv();

        $res = $this->handoff(11, 'kasir', $conv, ['to_user_id' => '1', 'expected_owner' => '11']);

        $this->assertDitolakTanpaPerubahan($res, 403, 'harus kasir aktif', $conv, 11);
    }

    public function testTargetKasirNonaktifDitolak403(): void
    {
        $conv = $this->seedConv();

        $res = $this->handoff(11, 'kasir', $conv, ['to_user_id' => '14', 'expected_owner' => '11']);

        $this->assertDitolakTanpaPerubahan($res, 403, 'harus kasir aktif', $conv, 11);
    }

    public function testTargetTidakDikenalDitolak403(): void
    {
        $conv = $this->seedConv();

        $res = $this->handoff(11, 'kasir', $conv, ['to_user_id' => '777', 'expected_owner' => '11']);

        $this->assertDitolakTanpaPerubahan($res, 403, 'harus kasir aktif', $conv, 11);
    }

    /** Layar kasir basi: percakapan sudah tidak dipegang, tapi klien masih mengklaim pemilik lama. */
    public function testExpectedOwnerBasiDitolak409(): void
    {
        $conv = $this->seedConv(['assigned_to' => null]);

        $res = $this->handoff(13, 'kasir', $conv, ['to_user_id' => '12', 'expected_owner' => '11']);

        $this->assertDitolakTanpaPerubahan($res, 409, 'sudah berpindah', $conv, null);
        $this->assertNull($this->json($res)['current_owner_id']);
    }

    public function testBodyJsonJugaDiterima(): void
    {
        $conv = $this->seedConv();

        $res = $this->withSession(['isLoggedIn' => true, 'id_user' => 11, 'role' => 'kasir'])
            ->withBodyFormat('json')
            ->post('/inbox/percakapan/' . $conv . '/handoff', ['to_user_id' => 12, 'expected_owner' => 11, 'summary' => 'json']);

        $res->assertStatus(200);
        $this->assertSame(12, $this->ownerOf($conv));
    }

    public function testRiwayatTampilDiEndpointGet(): void
    {
        $conv = $this->seedConv();
        $this->handoff(11, 'kasir', $conv, ['to_user_id' => '12', 'expected_owner' => '11', 'summary' => 'pertama'])->assertStatus(200);
        $this->handoff(12, 'kasir', $conv, ['to_user_id' => '13', 'expected_owner' => '12', 'summary' => 'kedua'])->assertStatus(200);

        $this->assertSame(13, $this->ownerOf($conv));
        $this->assertSame(2, $this->jumlahRiwayat($conv));

        $res = $this->withSession(['isLoggedIn' => true, 'id_user' => 13, 'role' => 'kasir'])
            ->get('/inbox/percakapan/' . $conv . '/handoff');
        $res->assertStatus(200);
        $body = $this->json($res);
        $this->assertSame('success', $body['status']);
        $this->assertSame(['kedua', 'pertama'], array_column($body['handoffs'], 'summary'), 'Riwayat terbaru dulu.');
    }
}
