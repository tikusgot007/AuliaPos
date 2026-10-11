<?php

use App\Models\ConversationModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FakeGatewayServer;
use Tests\Support\GatewayApiTestTrait;

/**
 * TODO-Q2 (poin 3):
 *  - POST /inbox/percakapan/(:num)/lepas (Inbox::lepasPercakapan()).
 *  - Satu skenario siklus hidup utuh lewat endpoint ASLI: pesan masuk
 *    (webhook Gateway) -> belum_diambil -> ambil -> kirim (fake gateway,
 *    HTTP sungguhan) -> handoff -> tutup -> pelanggan chat lagi ->
 *    kembali belum_diambil. Test per-endpoint yang sudah ada tidak bisa
 *    menangkap transisi status yang salah di antara langkah-langkah ini.
 *
 * @internal
 */
final class InboxLepasDanAlurPercakapanTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use GatewayApiTestTrait;

    private ?FakeGatewayServer $gateway = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gatewaySetUp();
        $this->inbox->table('conversation_handoffs')->emptyTable();

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

        foreach ([[11, 'kasir'], [12, 'kasir'], [1, 'admin']] as [$id, $role]) {
            db_connect()->table('users')->insert([
                'id' => $id, 'username' => 'user' . $id, 'nama' => 'User ' . $id,
                'inisial' => 'U' . $id, 'role' => $role, 'is_active' => 1, 'priority' => null,
            ]);
        }
    }

    protected function tearDown(): void
    {
        $this->gateway?->stop();
        FakeGatewayServer::restoreGatewayBaseUrl();
        \Config\Database::forge()->dropTable('users', true);
        $this->gatewayTearDown();
        parent::tearDown();
    }

    private function sebagai(int $userId, string $role = 'kasir')
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => $userId, 'role' => $role]);
    }

    private function seedConv(array $override = []): int
    {
        $now = date('Y-m-d H:i:s');
        $this->inbox->table('conversations')->insert(array_merge([
            'chat_id'                => '6281200006' . random_int(1000, 9999) . '@s.whatsapp.net',
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

    private function row(int $conversationId): array
    {
        return $this->inbox->table('conversations')->where('id', $conversationId)->get()->getRowArray() ?? [];
    }

    private function queueStatus(int $conversationId): string
    {
        return (new ConversationModel())->withComputedStatus([$this->row($conversationId)])[0]['queue_status'];
    }

    private function owner(int $conversationId): ?int
    {
        $assigned = $this->row($conversationId)['assigned_to'];

        return $assigned !== null ? (int) $assigned : null;
    }

    // ---------------- Lepas ----------------

    public function testLepasOlehPemegangMengosongkanPemilik(): void
    {
        $conv = $this->seedConv();

        $res = $this->sebagai(11)->post('/inbox/percakapan/' . $conv . '/lepas');

        $res->assertStatus(200);
        $this->assertSame('success', $this->gatewayBodyJson($res)['status']);
        $this->assertNull($this->owner($conv));
        $this->assertSame('open', $this->row($conv)['status'], 'Lepas tidak menutup percakapan.');
        $this->assertSame('belum_diambil', $this->queueStatus($conv));
    }

    public function testLepasOlehAdminDiizinkan(): void
    {
        $conv = $this->seedConv();

        $this->sebagai(1, 'admin')->post('/inbox/percakapan/' . $conv . '/lepas')->assertStatus(200);

        $this->assertNull($this->owner($conv));
    }

    public function testLepasOlehKasirLainDitolak(): void
    {
        $conv = $this->seedConv();

        $res = $this->sebagai(12)->post('/inbox/percakapan/' . $conv . '/lepas');

        $res->assertStatus(403);
        $this->assertStringContainsString('sedang ditangani oleh U11', $this->gatewayBodyJson($res)['message']);
        $this->assertSame(11, $this->owner($conv));
    }

    public function testLepasGrupDitolak(): void
    {
        $conv = $this->seedConv(['jid_type' => 'group', 'chat_id' => '120363000001' . random_int(1000, 9999) . '@g.us']);

        $this->sebagai(11)->post('/inbox/percakapan/' . $conv . '/lepas')->assertStatus(403);

        $this->assertSame(11, $this->owner($conv));
    }

    public function testLepasPercakapanTidakAda404(): void
    {
        $this->sebagai(11)->post('/inbox/percakapan/999999/lepas')->assertStatus(404);
    }

    // ---------------- Siklus hidup utuh ----------------

    public function testSiklusHidupPercakapanUtuh(): void
    {
        $chatId = '6281200005' . random_int(1000, 9999) . '@s.whatsapp.net';
        // Timestamp eksplisit: last_message_at hanya maju bila LEBIH BARU
        // (ConversationModel::updateLastMessageIfNewer), jadi pesan-pesan
        // dalam detik yang sama tidak boleh dipakai.
        $masukPertama = gmdate('Y-m-d\TH:i:s.000\Z', time() - 600);
        $masukKedua   = gmdate('Y-m-d\TH:i:s.000\Z', time() + 60);

        // 1. Pesan masuk dari pelanggan baru -> percakapan baru, belum_diambil.
        $this->postGatewayMessage([
            'wa_message_id' => 'WAMSG-ALUR-1', 'chat_id' => $chatId, 'jid_type' => 'pn',
            'message_type' => 'text', 'text' => 'Halo, mau tanya harga', 'direction' => 'incoming',
            'message_timestamp' => $masukPertama,
        ])->assertStatus(200);

        $conv = (int) $this->inbox->table('conversations')->where('chat_id', $chatId)->get()->getRowArray()['id'];
        $this->assertSame('belum_diambil', $this->queueStatus($conv));
        $this->assertNull($this->owner($conv));

        // 2. Kasir 11 mengambil.
        $this->sebagai(11)->post('/inbox/percakapan/' . $conv . '/ambil')->assertStatus(200);
        $this->assertSame(11, $this->owner($conv));
        $this->assertSame('open', $this->queueStatus($conv));

        // 3. Kasir 11 membalas lewat Gateway (HTTP sungguhan ke fake gateway).
        $this->gateway = FakeGatewayServer::start();
        FakeGatewayServer::useGatewayBaseUrl($this->gateway->baseUrl('ok'));
        $now = date('Y-m-d H:i:s');
        $this->inbox->table('gateway_status')->where('id', 1)->delete();
        $this->inbox->table('gateway_status')->insert(['id' => 1, 'status' => 'connected', 'last_heartbeat_at' => $now, 'updated_at' => $now]);

        $res = $this->sebagai(11)->post('/inbox/kirim', [
            'conversation_id' => $conv, 'text' => 'Harganya 50 ribu', 'operation_id' => 'op-alur-1',
        ]);
        $res->assertStatus(200);
        $this->assertSame('menunggu', $this->queueStatus($conv), 'Setelah dibalas, menunggu pelanggan.');
        $this->assertSame(11, (int) $this->row($conv)['last_replied_by']);
        $this->assertSame($chatId, $this->gateway->requests()[0]['body']['chat_id']);

        // 4. Kasir 11 menyerahkan ke kasir 12.
        $this->sebagai(11)->post('/inbox/percakapan/' . $conv . '/handoff', [
            'to_user_id' => '12', 'expected_owner' => '11', 'summary' => 'Lanjutkan, pelanggan mau DP',
        ])->assertStatus(200);
        $this->assertSame(12, $this->owner($conv));
        // Pemilik lama tidak lagi berhak membalas.
        $this->sebagai(11)->post('/inbox/kirim', [
            'conversation_id' => $conv, 'text' => 'coba', 'operation_id' => 'op-alur-2',
        ])->assertStatus(403);

        // 5. Kasir 12 menutup -> selesai, pemilik dilepas.
        $this->sebagai(12)->post('/inbox/percakapan/' . $conv . '/tutup')->assertStatus(200);
        $row = $this->row($conv);
        $this->assertSame('closed', $row['status']);
        $this->assertSame(12, (int) $row['closed_by']);
        $this->assertNull($row['assigned_to']);
        $this->assertSame('selesai', $this->queueStatus($conv));

        // Handoff percakapan yang sudah selesai ditolak.
        $this->sebagai(12)->post('/inbox/percakapan/' . $conv . '/handoff', [
            'to_user_id' => '11', 'expected_owner' => '',
        ])->assertStatus(409);

        // 6. Pelanggan chat lagi -> dibuka kembali, kembali belum_diambil.
        $this->postGatewayMessage([
            'wa_message_id' => 'WAMSG-ALUR-2', 'chat_id' => $chatId, 'jid_type' => 'pn',
            'message_type' => 'text', 'text' => 'Oke, saya transfer DP', 'direction' => 'incoming',
            'message_timestamp' => $masukKedua,
        ])->assertStatus(200);

        $row = $this->row($conv);
        $this->assertSame('open', $row['status']);
        $this->assertNull($row['assigned_to'], 'Pesan masuk membuka kembali TANPA mengembalikan pemilik.');
        $this->assertSame('belum_diambil', $this->queueStatus($conv));

        // Riwayat percakapan tetap utuh: 2 masuk + 1 keluar, 1 handoff.
        $this->assertSame(3, $this->inbox->table('messages')->where('conversation_id', $conv)->countAllResults());
        $this->assertSame(1, $this->inbox->table('conversation_handoffs')->where('conversation_id', $conv)->countAllResults());
    }
}
