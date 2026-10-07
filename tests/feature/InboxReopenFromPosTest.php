<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\GatewayApiTestTrait;
use App\Controllers\Inbox as InboxController;
use App\Libraries\InboxOutgoingRequest;
use Config\Inbox as InboxConfig;

/**
 * Test double controller: menjalankan alur kirim POS yang ASLI
 * (validasi, cekOwnership, pastikanGatewaySiap, simpan pesan, update
 * conversation) tetapi mengganti panggilan HTTP ke Gateway dengan
 * respons sukses palsu. Seam ini sah karena callGatewaySend()/
 * callGatewaySendMedia() memang `protected` di controller asli.
 *
 * Hanya itu yang dipalsukan: logika reopen/auto-assign
 * (updateSetelahKirimSukses) tetap yang asli, jadi test benar-benar
 * menguji aturan bisnis, bukan stub-nya.
 */
class InboxReopenStub extends InboxController
{
    /** @var array<int, array{ok: bool, wa_message_id?: ?string}> */
    public array $responses = [];

    protected function callGatewaySend(InboxConfig $config, InboxOutgoingRequest $request): array
    {
        return $this->responses[] = ['ok' => true, 'wa_message_id' => 'STUB-TEXT-' . count($this->responses), 'quote_applied' => true];
    }

    protected function callGatewaySendMedia(InboxConfig $config, InboxOutgoingRequest $request): array
    {
        return $this->responses[] = [
            'ok' => true,
            'wa_message_id' => 'STUB-MEDIA-' . count($this->responses),
            'media_ref' => ['direct_path' => '/stub', 'media_key_base64' => base64_encode('k')],
        ];
    }
}

/**
 * Balasan kasir DARI POS ke percakapan `closed` membuka kembali percakapan
 * (status='open') + auto-assign ke pengirim -- keputusan bisnis 2026-10-07.
 * Lihat Inbox::updateSetelahKirimSukses().
 *
 * Regresi yang dijaga di sini:
 *  - outgoing dari WA Web/HP TIDAK membuka kembali (tetap sync pesan saja);
 *  - incoming customer tetap membuka kembali TANPA auto-assign;
 *  - internal note tidak membuka kembali (bukan pesan ke customer);
 *  - owner lain pada percakapan open TIDAK dioverride.
 *
 * Media: jalur media (kirimMediaViaGateway) memakai helper yang SAMA
 * (updateSetelahKirimSukses) dengan jalur teks, jadi aturan reopen media
 * identik; tidak ada e2e multipart terpisah di sini (upload $_FILES tidak
 * dapat di-inject andal lewat FeatureTestTrait di repo ini).
 *
 * @internal
 */
final class InboxReopenFromPosTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use GatewayApiTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gatewaySetUp();

        // Gateway harus "usable" (pastikanGatewaySiap) tanpa HTTP nyata.
        // Kolom yang disisipkan sengaja minimal (schema test DB bisa lebih
        // lama dari produksi): isUsable() hanya membaca status +
        // last_heartbeat_at.
        putenv('inbox.gatewayBaseUrl=http://127.0.0.1:1');
        $now = date('Y-m-d H:i:s');
        $this->inbox->table('gateway_status')->where('id', 1)->delete();
        $this->inbox->table('gateway_status')->insert([
            'id'                => 1,
            'status'            => 'connected',
            'last_heartbeat_at' => $now,
            'updated_at'        => $now,
        ]);

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
        putenv('inbox.gatewayBaseUrl');
        \Config\Database::forge()->dropTable('users', true);
        $this->gatewayTearDown();
        parent::tearDown();
    }

    private function seedUser(int $id, string $role = 'kasir'): void
    {
        db_connect()->table('users')->insert([
            'id' => $id, 'username' => 'user' . $id, 'nama' => 'User ' . $id,
            'inisial' => 'U' . $id, 'role' => $role, 'is_active' => 1, 'priority' => null,
        ]);
    }

    private function seedConv(array $override = []): int
    {
        $now = date('Y-m-d H:i:s');

        $this->inbox->table('conversations')->insert(array_merge([
            'chat_id'                => '6281200009' . random_int(1000, 9999) . '@s.whatsapp.net',
            'jid_type'               => 'pn',
            'status'                 => 'closed',
            'assigned_to'            => null,
            'last_message_direction' => 'outgoing',
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

    private function row(int $conversationId): array
    {
        return $this->inbox->table('conversations')->where('id', $conversationId)->get()->getRowArray() ?? [];
    }

    /**
     * Route sintetis ke stub (withRoutes mereset seluruh tabel route, jadi
     * daftar di sini harus lengkap untuk tiap pemanggilan).
     */
    private function ruteStub(): void
    {
        $this->withRoutes([
            ['POST', 'inbox/kirim', '\InboxReopenStub::kirim', ['filter' => 'auth']],
            ['POST', 'inbox/percakapan/(:num)/catatan', '\App\Controllers\Inbox::catatanInternal/$1', ['filter' => 'auth']],
            ['POST', 'api/inbox/gateway/messages', '\App\Controllers\InboxGatewayApi::messages', ['filter' => 'gatewaytoken']],
        ]);
    }

    private function kirimTeks(int $userId, string $role, int $conversationId)
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => $userId, 'role' => $role])
            ->post('/inbox/kirim', ['conversation_id' => $conversationId, 'text' => 'Halo, ditindaklanjuti ya.']);
    }

    // ----------------------------------------------------------------
    // Inti: balasan POS ke closed -> reopen + auto-assign pengirim.
    // ----------------------------------------------------------------
    public function testBalasanPosKeClosedMembukaKembaliDanAssignPengirim(): void
    {
        $this->seedUser(21);
        $conv = $this->seedConv(['status' => 'closed', 'assigned_to' => null, 'closed_by' => 11, 'closed_at' => date('Y-m-d H:i:s')]);
        $this->ruteStub();

        $this->kirimTeks(21, 'kasir', $conv)->assertStatus(200);

        $row = $this->row($conv);
        $this->assertSame('open', $row['status'], 'Balasan POS ke percakapan closed harus membuka kembali.');
        $this->assertSame(21, (int) $row['assigned_to'], 'Reopen dari POS harus auto-assign ke pengirim.');
        $this->assertNull($row['snoozed_until']);
        // Audit penutupan terakhir dibiarkan (Q-A: tanpa audit reopen).
        $this->assertSame(11, (int) $row['closed_by']);
        $this->assertNotNull($row['closed_at']);
    }

    // ----------------------------------------------------------------
    // Regresi: open + assigned orang lain -> owner TIDAK dioverride.
    // ----------------------------------------------------------------
    public function testOpenMilikOrangLainTidakDioverride(): void
    {
        $this->seedUser(21);
        $this->seedUser(22);
        // Percakapan open milik user 22; user 21 hanya boleh kirim kalau admin
        // atau pemiliknya -- pakai admin agar lolos cekOwnership, lalu pastikan
        // owner 22 tidak tergantikan oleh pengirim (admin).
        $conv = $this->seedConv(['status' => 'open', 'assigned_to' => 22]);
        $this->ruteStub();

        $this->kirimTeks(1, 'admin', $conv)->assertStatus(200);

        $row = $this->row($conv);
        $this->assertSame('open', $row['status']);
        $this->assertSame(22, (int) $row['assigned_to'], 'Kirim oleh non-owner pada percakapan open tidak boleh mengoverride owner.');
    }

    // ----------------------------------------------------------------
    // Regresi: open + unassigned -> auto-assign (perilaku lama).
    // ----------------------------------------------------------------
    public function testOpenUnassignedTetapAutoAssign(): void
    {
        $this->seedUser(21);
        $conv = $this->seedConv(['status' => 'open', 'assigned_to' => null]);
        $this->ruteStub();

        $this->kirimTeks(21, 'kasir', $conv)->assertStatus(200);

        $row = $this->row($conv);
        $this->assertSame('open', $row['status']);
        $this->assertSame(21, (int) $row['assigned_to']);
    }

    // ----------------------------------------------------------------
    // Regresi: internal note pada closed TIDAK membuka kembali.
    // ----------------------------------------------------------------
    public function testCatatanInternalTidakMembukaKembali(): void
    {
        $this->seedUser(21);
        $conv = $this->seedConv(['status' => 'closed', 'assigned_to' => null]);
        $this->ruteStub();

        $this->withSession(['isLoggedIn' => true, 'id_user' => 21, 'role' => 'kasir'])
            ->post('/inbox/percakapan/' . $conv . '/catatan', ['teks' => 'catatan internal'])
            ->assertStatus(200);

        $row = $this->row($conv);
        $this->assertSame('closed', $row['status'], 'Internal note bukan pesan ke customer -> tidak reopen.');
        $this->assertNull($row['assigned_to']);
    }

    // ----------------------------------------------------------------
    // Regresi: incoming customer pada closed -> reopen + assigned_to NULL.
    // ----------------------------------------------------------------
    public function testIncomingCustomerReopenTanpaAssign(): void
    {
        $conv = $this->seedConv(['status' => 'closed', 'assigned_to' => null, 'closed_by' => 11]);
        $this->ruteStub();

        $chatId = $this->row($conv)['chat_id'];
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->gatewayToken])
            ->withBodyFormat('json')
            ->post('/api/inbox/gateway/messages', [
                'wa_message_id'     => 'WAMSG-REOPEN-FROM-POS-TEST-1',
                'chat_id'           => $chatId,
                'jid_type'          => 'pn',
                'message_type'      => 'text',
                'text'              => 'halo',
                'message_timestamp' => $this->nowIso(),
                'direction'         => 'incoming',
            ]);
        $response->assertStatus(200);

        $row = $this->row($conv);
        $this->assertSame('open', $row['status']);
        $this->assertNull($row['assigned_to'], 'Incoming customer reopen tidak boleh mengembalikan owner lama.');
    }

    // ----------------------------------------------------------------
    // Regresi: outgoing WA Web/HP (bukan POS) pada closed TIDAK reopen.
    // ----------------------------------------------------------------
    public function testOutgoingWaWebTidakMembukaKembali(): void
    {
        $conv = $this->seedConv(['status' => 'closed', 'assigned_to' => null]);
        $this->ruteStub();

        $chatId = $this->row($conv)['chat_id'];
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->gatewayToken])
            ->withBodyFormat('json')
            ->post('/api/inbox/gateway/messages', [
                'wa_message_id'     => 'WAMSG-WEBHP-REOPEN-TEST-1',
                'chat_id'           => $chatId,
                'jid_type'          => 'pn',
                'message_type'      => 'text',
                'text'              => 'dibalas dari HP',
                'message_timestamp' => $this->nowIso(),
                'direction'         => 'outgoing',
            ]);
        $response->assertStatus(200);

        $row = $this->row($conv);
        $this->assertSame('closed', $row['status'], 'Outgoing WA Web/HP tidak mengubah status (sync pesan saja).');
    }
}
