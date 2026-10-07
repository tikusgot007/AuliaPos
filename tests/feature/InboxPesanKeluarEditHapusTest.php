<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\GatewayApiTestTrait;
use App\Controllers\Inbox as InboxController;
use Config\Inbox as InboxConfig;

/**
 * Test double: menjalankan alur aksi pesan keluar yang ASLI (validasi,
 * pastikanGatewaySiap, penandaan baris lokal) tetapi mengganti panggilan HTTP
 * ke Gateway dengan respons yang bisa diprogram. Seam sah karena
 * `callGatewayDelete()`/`callGatewayEdit()` memang `protected` di controller.
 *
 * Hanya pemanggilan HTTP yang dipalsukan; aturan bisnis (batas 15 menit, teks
 * saja, idempotensi hapus) tetap yang asli.
 */
class InboxPesanKeluarStub extends InboxController
{
    /** @var array<int, array<string, mixed>> respons berurutan; di-pop per panggilan */
    public static array $antrian = [];
    public static int $jumlahPanggilan = 0;

    private function berikutnya(): array
    {
        self::$jumlahPanggilan++;
        return array_shift(self::$antrian) ?? ['ok' => true, 'state' => 'deleted', 'replayed' => false];
    }

    protected function callGatewayDelete(InboxConfig $config, string $chatId, string $waMessageId, ?string $operationId): array
    {
        return $this->berikutnya();
    }

    protected function callGatewayEdit(InboxConfig $config, string $chatId, string $waMessageId, string $newText, ?string $operationId): array
    {
        return $this->berikutnya();
    }
}

/**
 * Aksi pesan KELUAR (edit/hapus) via POST /inbox/pesan/(:num)/edit|hapus.
 * Lihat docs/requirements/2026-10-07-edit-hapus-pesan-keluar-inbox.md.
 *
 * @internal
 */
final class InboxPesanKeluarEditHapusTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use GatewayApiTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gatewaySetUp();
        InboxPesanKeluarStub::$antrian = [];
        InboxPesanKeluarStub::$jumlahPanggilan = 0;

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
    }

    protected function tearDown(): void
    {
        putenv('inbox.gatewayBaseUrl');
        \Config\Database::forge()->dropTable('users', true);
        $this->gatewayTearDown();
        parent::tearDown();
    }

    private function ruteStub(): void
    {
        $this->withRoutes([
            ['POST', 'inbox/pesan/(:num)/edit', '\InboxPesanKeluarStub::editPesan/$1', ['filter' => 'auth']],
            ['POST', 'inbox/pesan/(:num)/hapus', '\InboxPesanKeluarStub::hapusPesan/$1', ['filter' => 'auth']],
        ]);
    }

    /** @return array{0:int,1:int} [conversationId, messageId] */
    private function seedPesanKeluar(array $override = []): array
    {
        $now = date('Y-m-d H:i:s');
        $chatId = '6281290000' . random_int(100, 999) . '@s.whatsapp.net';

        $this->inbox->table('conversations')->insert([
            'chat_id'     => $chatId,
            'jid_type'    => 'pn',
            'status'      => 'open',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        $conversationId = (int) $this->inbox->insertID();

        $waMessageId = $override['wa_message_id'] ?? ('WAMSG-KELUAR-' . random_int(10000, 99999));

        $this->inbox->table('messages')->insert(array_merge([
            'conversation_id'   => $conversationId,
            'wa_message_id'     => $waMessageId,
            'direction'         => 'outgoing',
            'message_type'      => 'text',
            'text'              => 'pesan keluar awal',
            'send_status'       => 'sent',
            'message_timestamp' => $now,
            'created_at'        => $now,
            'is_internal'       => 0,
            'is_forwarded'      => 0,
        ], $override));
        $messageId = (int) $this->inbox->insertID();

        return [$conversationId, $messageId];
    }

    private function pesan(int $messageId): array
    {
        return $this->inbox->table('messages')->where('id', $messageId)->get()->getRowArray() ?? [];
    }

    private function postEdit(int $messageId, array $body = [])
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => 21, 'role' => 'kasir'])
            ->post('/inbox/pesan/' . $messageId . '/edit', $body);
    }

    private function postHapus(int $messageId, array $body = [])
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => 21, 'role' => 'kasir'])
            ->post('/inbox/pesan/' . $messageId . '/hapus', $body);
    }

    private function json($response): array
    {
        $decoded = json_decode(trim(strip_tags($response->getBody())), true);
        return is_array($decoded) ? $decoded : [];
    }

    // ---------------- Edit ----------------

    public function testEditTeksSuksesMenandaiTeksTerbaru(): void
    {
        [, $msg] = $this->seedPesanKeluar();
        $this->ruteStub();

        $res = $this->postEdit($msg, ['new_text' => 'pesan sudah diedit', 'operation_id' => 'op-edit-1']);
        $res->assertStatus(200);
        $json = $this->json($res);
        $this->assertSame('success', $json['status']);
        $this->assertSame('edited', $json['state']);

        $row = $this->pesan($msg);
        $this->assertSame('pesan sudah diedit', $row['text']);
        $this->assertNotNull($row['edited_at']);
        $this->assertNotNull($row['edited_text_resolved_at']);
        $this->assertNull($row['revoked_at']);
    }

    public function testEditTolakNonTeksTanpaPanggilGateway(): void
    {
        [$conv, $msg] = $this->seedPesanKeluar(['message_type' => 'image', 'text' => null]);
        $this->ruteStub();

        $res = $this->postEdit($msg, ['new_text' => 'x', 'operation_id' => 'op-edit-2']);
        $res->assertStatus(400);
        $this->assertSame('EDIT_MEDIA_UNSUPPORTED', $this->json($res)['error_code']);
        $this->assertSame(0, $this->gatewayCallCount());
    }

    public function testEditTolakLuarJendela15Menit(): void
    {
        [, $msg] = $this->seedPesanKeluar(['created_at' => date('Y-m-d H:i:s', time() - 20 * 60)]);
        $this->ruteStub();

        $res = $this->postEdit($msg, ['new_text' => 'x', 'operation_id' => 'op-edit-3']);
        $res->assertStatus(400);
        $this->assertSame('EDIT_WINDOW_EXPIRED', $this->json($res)['error_code']);
        $this->assertSame(0, $this->gatewayCallCount());
    }

    public function testEditWajibOperationId(): void
    {
        [, $msg] = $this->seedPesanKeluar();
        $this->ruteStub();

        $res = $this->postEdit($msg, ['new_text' => 'x']);
        $res->assertStatus(400);
        $this->assertSame('MISSING_OPERATION_ID', $this->json($res)['error_code']);
    }

    public function testEditTolakTeksKosong(): void
    {
        [, $msg] = $this->seedPesanKeluar();
        $this->ruteStub();

        $res = $this->postEdit($msg, ['new_text' => '   ', 'operation_id' => 'op-edit-4']);
        $res->assertStatus(400);
        $this->assertSame('INVALID_TEXT', $this->json($res)['error_code']);
    }

    public function testEditTolakPesanMasuk(): void
    {
        [, $msg] = $this->seedPesanKeluar(['direction' => 'incoming']);
        $this->ruteStub();

        $res = $this->postEdit($msg, ['new_text' => 'x', 'operation_id' => 'op-edit-5']);
        $res->assertStatus(400);
    }

    public function testEditTolakTanpaWaMessageIdNyata(): void
    {
        [, $msg] = $this->seedPesanKeluar(['wa_message_id' => 'local-abcdef0123456789']);
        $this->ruteStub();

        $res = $this->postEdit($msg, ['new_text' => 'x', 'operation_id' => 'op-edit-6']);
        $res->assertStatus(400);
        $this->assertSame('MESSAGE_NOT_SYNCED', $this->json($res)['error_code']);
    }

    public function testEditTolakPesanSudahDihapus(): void
    {
        [, $msg] = $this->seedPesanKeluar(['revoked_at' => date('Y-m-d H:i:s')]);
        $this->ruteStub();

        $res = $this->postEdit($msg, ['new_text' => 'x', 'operation_id' => 'op-edit-7']);
        $res->assertStatus(400);
        $this->assertSame('MESSAGE_DELETED', $this->json($res)['error_code']);
    }

    public function testEditUnresolvedTidakDianggapSukses(): void
    {
        [, $msg] = $this->seedPesanKeluar();
        $this->ruteStub();
        $this->stubAntrian([['ok' => false, 'error_code' => 'EDIT_UNRESOLVED', 'error' => 'timeout', 'http_code' => 504, 'state' => 'in_flight', 'replayed' => false]]);

        $res = $this->postEdit($msg, ['new_text' => 'x', 'operation_id' => 'op-edit-8']);
        $res->assertStatus(504);
        $json = $this->json($res);
        $this->assertSame('EDIT_UNRESOLVED', $json['error_code']);
        $this->assertTrue($json['uncertain']);

        // Baris lokal TIDAK ditandai diedit (hasil belum pasti).
        $row = $this->pesan($msg);
        $this->assertNull($row['edited_at']);
    }

    // ---------------- Hapus ----------------

    public function testHapusSuksesMenandaiRevoked(): void
    {
        [, $msg] = $this->seedPesanKeluar();
        $this->ruteStub();

        $res = $this->postHapus($msg, ['operation_id' => 'op-del-1']);
        $res->assertStatus(200);
        $json = $this->json($res);
        $this->assertSame('success', $json['status']);
        $this->assertSame('deleted', $json['state']);

        $this->assertNotNull($this->pesan($msg)['revoked_at']);
    }

    public function testHapusIdempotenTanpaPanggilGateway(): void
    {
        [, $msg] = $this->seedPesanKeluar(['revoked_at' => date('Y-m-d H:i:s')]);
        $this->ruteStub();

        $res = $this->postHapus($msg, ['operation_id' => 'op-del-2']);
        $res->assertStatus(200);
        $json = $this->json($res);
        $this->assertTrue($json['already'] ?? false);
        $this->assertSame(0, $this->gatewayCallCount());
    }

    public function testHapusBolehTanpaOperationId(): void
    {
        [, $msg] = $this->seedPesanKeluar();
        $this->ruteStub();

        $res = $this->postHapus($msg);
        $res->assertStatus(200);
        $this->assertSame('deleted', $this->json($res)['state']);
    }

    public function testHapusUnresolvedTidakDianggapSukses(): void
    {
        [, $msg] = $this->seedPesanKeluar();
        $this->ruteStub();
        $this->stubAntrian([['ok' => false, 'error_code' => 'DELETE_UNRESOLVED', 'error' => 'timeout', 'http_code' => 504, 'state' => 'in_flight', 'replayed' => false]]);

        $res = $this->postHapus($msg, ['operation_id' => 'op-del-3']);
        $res->assertStatus(504);
        $this->assertTrue($this->json($res)['uncertain']);
        $this->assertNull($this->pesan($msg)['revoked_at']);
    }

    public function testPesanTidakDitemukan404(): void
    {
        $this->ruteStub();
        $this->postHapus(999999)->assertStatus(404);
    }

    // ---------------- helpers ----------------

    /** Program respons stub berikutnya. */
    private function stubAntrian(array $antrian): void
    {
        InboxPesanKeluarStub::$antrian = $antrian;
    }

    private function gatewayCallCount(): int
    {
        return InboxPesanKeluarStub::$jumlahPanggilan;
    }
}
