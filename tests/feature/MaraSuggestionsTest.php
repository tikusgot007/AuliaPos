<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FakeGatewayServer;
use Tests\Support\GatewayApiTestTrait;

/**
 * M.A.R.A -> AuliaPos -> kasir: endpoint saran (TODO-MARA1 butir 1).
 *
 * Maps docs/requirements/2026-10-13-mara-saran-ke-kasir.md AC-1..AC-11.
 * Endpoint tulis: POST /api/inbox/mara/suggestions (Bearer mara.apiToken).
 * Endpoint baca:  GET /inbox/api/conversations/(:num)/suggestions (session auth).
 *
 * DB uji: tabel `conversations`/`mara_suggestions` ada di group `inbox`
 * (aulia_inboxdb_test, disinkronkan lewat `aulia:sync-inbox-test-db`).
 *
 * @internal
 */
final class MaraSuggestionsTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use GatewayApiTestTrait;

    private string $maraToken = 'test-mara-token-for-phpunit';

    private FakeGatewayServer $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gatewaySetUp();
        $this->gateway = FakeGatewayServer::start();

        // Token M.A.R.A TERPISAH dari token Gateway (aturan wajib).
        putenv('mara.apiToken=' . $this->maraToken);
        $_ENV['mara.apiToken']    = $this->maraToken;
        $_SERVER['mara.apiToken'] = $this->maraToken;

        $this->inbox->table('mara_suggestions')->emptyTable();

        // Gateway 'connected' supaya jalur kirim balasan (AC-10/AC-11) lolos
        // pastikanGatewaySiap().
        $now = date('Y-m-d H:i:s');
        $this->inbox->table('gateway_status')->where('id', 1)->delete();
        $this->inbox->table('gateway_status')->insert([
            'id'                => 1,
            'status'            => 'connected',
            'last_heartbeat_at' => $now,
            'updated_at'        => $now,
        ]);

        // `users` ada di group default (SQLite uji) -- dibutuhkan cekOwnership()
        // & attachSenderNames() saat kasir mengirim balasan.
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
        db_connect()->table('users')->insert([
            'id' => 21, 'username' => 'user21', 'nama' => 'User 21', 'inisial' => 'U21',
            'role' => 'kasir', 'is_active' => 1, 'priority' => null,
        ]);
    }

    protected function tearDown(): void
    {
        $this->inbox->table('mara_suggestions')->emptyTable();
        putenv('mara.apiToken');
        unset($_ENV['mara.apiToken'], $_SERVER['mara.apiToken']);
        $this->gateway->stop();
        FakeGatewayServer::restoreGatewayBaseUrl();
        \Config\Database::forge()->dropTable('users', true);
        $this->gatewayTearDown();
        parent::tearDown();
    }

    /**
     * Kirim balasan teks lewat endpoint ASLI /inbox/kirim (session kasir),
     * menembak fake gateway. Dipakai AC-10/AC-11 supaya yang teruji adalah
     * jalur sukses/gagal SUNGGUHAN, bukan hanya model.
     */
    private function postBalasan(int $conversationId, string $operationId)
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => 21, 'role' => 'kasir'])
            ->post('/inbox/kirim', [
                'conversation_id' => $conversationId,
                'text'            => 'balasan kasir uji',
                'operation_id'    => $operationId,
            ]);
    }

    private function getSuggestions(int $conversationId)
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => 21, 'role' => 'kasir'])
            ->get('/inbox/api/conversations/' . $conversationId . '/suggestions');
    }

    private function seedSaranAktif(int $conversationId, string $text = 'Aktif'): void
    {
        $this->inbox->table('mara_suggestions')->insert([
            'conversation_id' => $conversationId,
            'type'            => 'balasan',
            'priority'        => 'normal',
            'text'            => $text,
            'status'          => 'active',
            'expires_at'      => date('Y-m-d H:i:s', time() + 3600),
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    private function postSuggestion(array $body, ?string $token = null)
    {
        $headers = $token === null ? [] : ['Authorization' => 'Bearer ' . $token];

        return $this
            ->withHeaders($headers)
            ->withBodyFormat('json')
            ->post('/api/inbox/mara/suggestions', $body);
    }

    private function bodyJson($response): array
    {
        $decoded = json_decode(trim(strip_tags($response->getBody())), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** AC-1: no/invalid token -> 401 and nothing stored. */
    public function testRejectsMissingToken(): void
    {
        $conversationId = $this->seedConversation('6281400000001@s.whatsapp.net');

        $response = $this->postSuggestion([
            'conversation_id' => $conversationId,
            'type'            => 'balasan',
            'text'            => 'Halo, ada yang bisa dibantu?',
        ]);

        $response->assertStatus(401);
        $this->assertSame(0, $this->inbox->table('mara_suggestions')->countAllResults());
    }

    public function testRejectsWrongToken(): void
    {
        $conversationId = $this->seedConversation('6281400000002@s.whatsapp.net');

        $response = $this->postSuggestion([
            'conversation_id' => $conversationId,
            'type'            => 'balasan',
            'text'            => 'Halo',
        ], 'token-yang-salah');

        $response->assertStatus(401);
        $this->assertSame(0, $this->inbox->table('mara_suggestions')->countAllResults());
    }

    /** AC-2: valid body -> 201 with id, row stored status='active'. */
    public function testStoresValidSuggestion(): void
    {
        $conversationId = $this->seedConversation('6281400000003@s.whatsapp.net');

        $response = $this->postSuggestion([
            'conversation_id' => $conversationId,
            'type'            => 'balasan',
            'priority'        => 'normal',
            'text'            => 'Draft balasan uji',
        ], $this->maraToken);

        $response->assertStatus(201);
        $json = $this->bodyJson($response);
        $this->assertSame('ok', $json['status'] ?? null);
        $this->assertArrayHasKey('id', $json['data'] ?? []);

        $row = $this->inbox->table('mara_suggestions')
            ->where('id', (int) $json['data']['id'])
            ->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame('active', $row['status']);
        $this->assertSame('balasan', $row['type']);
        $this->assertSame($conversationId, (int) $row['conversation_id']);
    }

    /** AC-3: daftar-periksa without items (or empty) -> 400, nothing stored. */
    public function testRejectsChecklistWithoutItems(): void
    {
        $conversationId = $this->seedConversation('6281400000004@s.whatsapp.net');

        $response = $this->postSuggestion([
            'conversation_id' => $conversationId,
            'type'            => 'daftar-periksa',
            'text'            => 'Cek langkah',
            'items'           => [],
        ], $this->maraToken);

        $response->assertStatus(400);
        $this->assertSame(0, $this->inbox->table('mara_suggestions')->countAllResults());
    }

    public function testRejectsChecklistWithNonStringItems(): void
    {
        $conversationId = $this->seedConversation('6281400000005@s.whatsapp.net');

        $response = $this->postSuggestion([
            'conversation_id' => $conversationId,
            'type'            => 'daftar-periksa',
            'items'           => ['ok', 123],
        ], $this->maraToken);

        $response->assertStatus(400);
        $this->assertSame(0, $this->inbox->table('mara_suggestions')->countAllResults());
    }

    /** AC-4: unknown conversation -> 404, nothing stored. */
    public function testRejectsUnknownConversation(): void
    {
        $response = $this->postSuggestion([
            'conversation_id' => 999999,
            'type'            => 'balasan',
            'text'            => 'Halo',
        ], $this->maraToken);

        $response->assertStatus(404);
        $this->assertSame(0, $this->inbox->table('mara_suggestions')->countAllResults());
    }

    /** AC-5: new same-type suggestion supersedes the old one. */
    public function testNewSameTypeSupersedesOld(): void
    {
        $conversationId = $this->seedConversation('6281400000007@s.whatsapp.net');

        $this->postSuggestion([
            'conversation_id' => $conversationId,
            'type'            => 'balasan',
            'text'            => 'Versi pertama',
        ], $this->maraToken)->assertStatus(201);

        $this->postSuggestion([
            'conversation_id' => $conversationId,
            'type'            => 'balasan',
            'text'            => 'Versi kedua',
        ], $this->maraToken)->assertStatus(201);

        $rows = $this->inbox->table('mara_suggestions')
            ->where('conversation_id', $conversationId)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        $this->assertCount(2, $rows);
        $this->assertSame('superseded', $rows[0]['status']);
        $this->assertSame('active', $rows[1]['status']);
    }

    /** AC-6: missing expires_at -> default now+30m (never null). */
    public function testDefaultExpiryWhenMissing(): void
    {
        $conversationId = $this->seedConversation('6281400000008@s.whatsapp.net');

        $response = $this->postSuggestion([
            'conversation_id' => $conversationId,
            'type'            => 'peringatan',
            'text'            => 'Chat mendekati SLA',
        ], $this->maraToken);

        $response->assertStatus(201);
        $id  = (int) $this->bodyJson($response)['data']['id'];
        $row = $this->inbox->table('mara_suggestions')->where('id', $id)->get()->getRowArray();

        $this->assertNotNull($row['expires_at']);

        // expires_at harus kira-kira 30 menit dari sekarang (toleransi lebar).
        $diff = strtotime($row['expires_at']) - strtotime($row['created_at']);
        $this->assertGreaterThan(25 * 60, $diff);
        $this->assertLessThan(35 * 60, $diff);
    }

    /** AC-7: oversize/invalid body -> rejected, nothing partial stored. */
    public function testRejectsUnknownType(): void
    {
        $conversationId = $this->seedConversation('6281400000009@s.whatsapp.net');

        $response = $this->postSuggestion([
            'conversation_id' => $conversationId,
            'type'            => 'entah-apa',
            'text'            => 'Halo',
        ], $this->maraToken);

        $response->assertStatus(400);
        $this->assertSame(0, $this->inbox->table('mara_suggestions')->countAllResults());
    }

    public function testRejectsReplyWithoutText(): void
    {
        $conversationId = $this->seedConversation('6281400000010@s.whatsapp.net');

        $response = $this->postSuggestion([
            'conversation_id' => $conversationId,
            'type'            => 'balasan',
            'text'            => '',
        ], $this->maraToken);

        $response->assertStatus(400);
        $this->assertSame(0, $this->inbox->table('mara_suggestions')->countAllResults());
    }

    public function testRejectsExpiresAtInThePast(): void
    {
        $conversationId = $this->seedConversation('6281400000011@s.whatsapp.net');

        $response = $this->postSuggestion([
            'conversation_id' => $conversationId,
            'type'            => 'balasan',
            'text'            => 'Halo',
            'expires_at'      => '2020-01-01T00:00:00+07:00',
        ], $this->maraToken);

        $response->assertStatus(400);
        $this->assertSame(0, $this->inbox->table('mara_suggestions')->countAllResults());
    }

    /** AC-8: only active, unexpired suggestions returned to the cashier. */
    public function testActiveOnlyReturnedToCashier(): void
    {
        $conversationId = $this->seedConversation('6281400000012@s.whatsapp.net');

        $this->postSuggestion([
            'conversation_id' => $conversationId,
            'type'            => 'balasan',
            'text'            => 'Aktif',
        ], $this->maraToken)->assertStatus(201);

        // Baris superseded langsung di-insert untuk membuktikan difilter.
        $this->inbox->table('mara_suggestions')->insert([
            'conversation_id' => $conversationId,
            'type'            => 'peringatan',
            'priority'        => 'normal',
            'text'            => 'Sudah digantikan',
            'status'          => 'superseded',
            'expires_at'      => date('Y-m-d H:i:s', time() + 3600),
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        $response = $this->getSuggestions($conversationId);
        $response->assertStatus(200);

        $json = $this->bodyJson($response);
        $this->assertSame('success', $json['status'] ?? null);
        $this->assertCount(1, $json['suggestions']);
        $this->assertSame('Aktif', $json['suggestions'][0]['text']);
    }

    /** AC-9: expired-by-time suggestions are not shown. */
    public function testExpiredNotReturned(): void
    {
        $conversationId = $this->seedConversation('6281400000013@s.whatsapp.net');

        $this->inbox->table('mara_suggestions')->insert([
            'conversation_id' => $conversationId,
            'type'            => 'peringatan',
            'priority'        => 'normal',
            'text'            => 'Sudah lewat waktu',
            'status'          => 'active',
            'expires_at'      => date('Y-m-d H:i:s', time() - 60),
            'created_at'      => date('Y-m-d H:i:s', time() - 3600),
            'updated_at'      => date('Y-m-d H:i:s', time() - 3600),
        ]);

        $response = $this->getSuggestions($conversationId);
        $response->assertStatus(200);

        $json = $this->bodyJson($response);
        $this->assertSame([], $json['suggestions']);
    }

    /** AC-10: a successful reply (via the real /inbox/kirim) expires suggestions. */
    public function testReplyExpiresSuggestions(): void
    {
        $conversationId = $this->seedConversation('6281400000014@s.whatsapp.net');
        $this->seedSaranAktif($conversationId, 'Saran yang harus expired');

        FakeGatewayServer::useGatewayBaseUrl($this->gateway->baseUrl('ok'));

        $response = $this->postBalasan($conversationId, 'op-mara-ac10');
        $response->assertStatus(200);

        $row = $this->inbox->table('mara_suggestions')
            ->where('conversation_id', $conversationId)->get()->getRowArray();

        $this->assertNotNull($row);
        // Balasan sukses -> saran di-expire, TETAPI baris tetap ada (audit).
        $this->assertSame('expired', $row['status']);
    }

    /** AC-11: a FAILED reply must not expire suggestions (still active). */
    public function testFailedReplyKeepsSuggestionsActive(): void
    {
        $conversationId = $this->seedConversation('6281400000015@s.whatsapp.net');
        $this->seedSaranAktif($conversationId, 'Saran harus tetap active');

        // Gateway mengembalikan HTTP 500 -> kirim dianggap gagal.
        FakeGatewayServer::useGatewayBaseUrl($this->gateway->baseUrl('http500'));

        $response = $this->postBalasan($conversationId, 'op-mara-ac11');
        $this->assertNotSame(200, $response->getStatusCode(), 'Kirim yang gagal bukan 200.');

        $row = $this->inbox->table('mara_suggestions')
            ->where('conversation_id', $conversationId)->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame('active', $row['status'], 'Kirim gagal TIDAK boleh menandai saran expired.');
    }

    /**
     * AC-10 (WA Web/HP path): a staff reply SYNCED from the phone arrives at
     * the Gateway webhook as direction='outgoing'; it must also expire the
     * conversation's active suggestions.
     */
    public function testGatewayOutgoingReplyExpiresSuggestions(): void
    {
        $chatId = '6281400000020@s.whatsapp.net';
        $conversationId = $this->seedConversation($chatId);
        $this->seedSaranAktif($conversationId, 'Harus expired oleh outgoing gateway');

        $response = $this->postGatewayMessage([
            'wa_message_id'     => 'WAMSG-MARA-AC10-OUT',
            'chat_id'           => $chatId,
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'balasan dari HP',
            'message_timestamp' => $this->nowIso(),
            'direction'         => 'outgoing',
        ]);
        $response->assertStatus(200);

        $row = $this->inbox->table('mara_suggestions')
            ->where('conversation_id', $conversationId)->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame('expired', $row['status']);
    }

    /**
     * AC-10 must NOT fire for an incoming gateway message (customer message
     * does not mean the cashier replied).
     */
    public function testGatewayIncomingDoesNotExpireSuggestions(): void
    {
        $chatId = '6281400000021@s.whatsapp.net';
        $conversationId = $this->seedConversation($chatId);
        $this->seedSaranAktif($conversationId, 'Tetap active saat customer chat');

        $response = $this->postGatewayMessage([
            'wa_message_id'     => 'WAMSG-MARA-AC10-IN',
            'chat_id'           => $chatId,
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'pesan pelanggan',
            'message_timestamp' => $this->nowIso(),
            'direction'         => 'incoming',
        ]);
        $response->assertStatus(200);

        $row = $this->inbox->table('mara_suggestions')
            ->where('conversation_id', $conversationId)->get()->getRowArray();

        $this->assertSame('active', $row['status']);
    }

    /** AC-5: the conversation lock is taken inside the transaction and a
     *  second same-type suggestion still ends with exactly one active row. */
    public function testLockKeepsSingleActivePerType(): void
    {
        $conversationId = $this->seedConversation('6281400000022@s.whatsapp.net');

        $this->postSuggestion([
            'conversation_id' => $conversationId,
            'type'            => 'peringatan',
            'text'            => 'Pertama',
        ], $this->maraToken)->assertStatus(201);

        $this->postSuggestion([
            'conversation_id' => $conversationId,
            'type'            => 'peringatan',
            'text'            => 'Kedua',
        ], $this->maraToken)->assertStatus(201);

        $activeCount = $this->inbox->table('mara_suggestions')
            ->where('conversation_id', $conversationId)
            ->where('type', 'peringatan')
            ->where('status', 'active')
            ->countAllResults();

        $this->assertSame(1, $activeCount);
    }

    /** Model-level: expireForConversation hanya menyentuh baris 'active'. */
    public function testExpireOnlyTouchesActiveRows(): void
    {
        $model = new \App\Models\MaraSuggestionModel();

        $conversationId = $this->seedConversation('6281400000016@s.whatsapp.net');

        $this->inbox->table('mara_suggestions')->insert([
            'conversation_id' => $conversationId,
            'type'            => 'balasan',
            'priority'        => 'normal',
            'text'            => 'Sudah superseded',
            'status'          => 'superseded',
            'expires_at'      => date('Y-m-d H:i:s', time() + 3600),
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame(0, $model->expireForConversation($conversationId));

        $row = $this->inbox->table('mara_suggestions')
            ->where('conversation_id', $conversationId)->get()->getRowArray();
        $this->assertSame('superseded', $row['status']);
    }

    /** Inbox->Kasir AC-12: balasan/peringatan with action 'buka_kasir' is stored and returned to the cashier. */
    public function testStoresAndReturnsWhitelistedAction(): void
    {
        foreach (['balasan', 'peringatan'] as $i => $type) {
            $conversationId = $this->seedConversation('628140000010' . $i . '@s.whatsapp.net');

            $response = $this->postSuggestion([
                'conversation_id' => $conversationId,
                'type'            => $type,
                'text'            => 'Buatkan pesanan',
                'action'          => 'buka_kasir',
            ], $this->maraToken);

            $response->assertStatus(201);

            $read = $this->bodyJson($this->getSuggestions($conversationId));
            $this->assertSame('buka_kasir', $read['suggestions'][0]['action'] ?? null, "type {$type}");
        }
    }

    /** No action (omitted, null or '') -> stored as NULL, i.e. no button. */
    public function testSuggestionWithoutActionHasNullAction(): void
    {
        $conversationId = $this->seedConversation('6281400000120@s.whatsapp.net');

        foreach ([[], ['action' => null], ['action' => '']] as $extra) {
            $this->postSuggestion(array_merge([
                'conversation_id' => $conversationId,
                'type'            => 'balasan',
                'text'            => 'Tanpa aksi',
            ], $extra), $this->maraToken)->assertStatus(201);
        }

        $rows = $this->inbox->table('mara_suggestions')->get()->getResultArray();
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertNull($row['action']);
        }
    }

    /**
     * Inbox->Kasir AC-13: anything outside the whitelist is rejected whole
     * (400) and nothing is stored -- unknown id, URL-ish value, wrong type.
     */
    public function testRejectsActionOutsideWhitelist(): void
    {
        $conversationId = $this->seedConversation('6281400000121@s.whatsapp.net');

        $bad = [
            'aksi tak dikenal'   => ['type' => 'balasan', 'action' => 'hapus_semua'],
            'berupa URL'         => ['type' => 'peringatan', 'action' => 'https://evil.example/x'],
            'bukan string'       => ['type' => 'balasan', 'action' => ['buka_kasir']],
            'huruf besar'        => ['type' => 'balasan', 'action' => 'BUKA_KASIR'],
            'pada daftar-periksa' => ['type' => 'daftar-periksa', 'items' => ['a', 'b'], 'action' => 'buka_kasir'],
        ];

        foreach ($bad as $label => $extra) {
            $this->postSuggestion(array_merge([
                'conversation_id' => $conversationId,
                'text'            => 'x',
            ], $extra), $this->maraToken)->assertStatus(400);
        }

        $this->assertSame(0, $this->inbox->table('mara_suggestions')->countAllResults(), 'nothing may be stored when action is invalid');
    }

    /** daftar-periksa with valid items stores them as a decodable JSON array. */
    public function testStoresChecklistItems(): void
    {
        $conversationId = $this->seedConversation('6281400000017@s.whatsapp.net');

        $response = $this->postSuggestion([
            'conversation_id' => $conversationId,
            'type'            => 'daftar-periksa',
            'priority'        => 'tinggi',
            'items'           => ['Cek ukuran', 'Cek jumlah cetak', 'Konfirmasi harga'],
        ], $this->maraToken);

        $response->assertStatus(201);

        $read = $this->getSuggestions($conversationId);
        $json = $this->bodyJson($read);

        $this->assertCount(1, $json['suggestions']);
        $this->assertSame(['Cek ukuran', 'Cek jumlah cetak', 'Konfirmasi harga'], $json['suggestions'][0]['items']);
    }
}
