<?php

use App\Database\Migrations\AddPreviewToMessageSendAudit;
use App\Database\Migrations\CreateMessageSendAudit;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FakeGatewayServer;
use Tests\Support\GatewayApiTestTrait;

require_once __DIR__ . '/../../app/Database/Migrations/2026-10-09-000001_CreateMessageSendAudit.php';
require_once __DIR__ . '/../../app/Database/Migrations/2026-10-10-000001_AddPreviewToMessageSendAudit.php';

/**
 * TODO-Q2 (poin 1): jalur kirim/edit pesan lewat HTTP SUNGGUHAN.
 *
 * Berbeda dari InboxMessageSendAuditTest/InboxReopenFromPosTest (yang
 * mengganti callGatewaySend() dengan stub), test ini memakai controller ASLI
 * tanpa subclass: cURL di Inbox.php benar-benar memanggil fake gateway
 * (tests/_support/fake-gateway-router.php, `php -S`), jadi kode pembaca
 * respons Gateway (Inbox.php callGatewaySend()/callGatewayPesanKeluar())
 * ikut teruji. Tidak ada pesan WhatsApp yang terkirim.
 *
 * Test `lambat` (timeout cURL 10 detik) sengaja diletakkan paling akhir.
 *
 * @internal
 */
final class InboxKirimGatewayPalsuTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use GatewayApiTestTrait;

    private FakeGatewayServer $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gatewaySetUp();
        $this->gateway = FakeGatewayServer::start();

        $now = date('Y-m-d H:i:s');
        $this->inbox->table('gateway_status')->where('id', 1)->delete();
        $this->inbox->table('gateway_status')->insert([
            'id'                => 1,
            'status'            => 'connected',
            'last_heartbeat_at' => $now,
            'updated_at'        => $now,
        ]);

        $migration = new CreateMessageSendAudit();
        $migration->down();
        $migration->up();
        (new AddPreviewToMessageSendAudit())->up();

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
        $this->gateway->stop();
        FakeGatewayServer::restoreGatewayBaseUrl();
        (new CreateMessageSendAudit())->down();
        \Config\Database::forge()->dropTable('users', true);
        $this->gatewayTearDown();
        parent::tearDown();
    }

    private function pakaiSkenario(string $skenario): void
    {
        FakeGatewayServer::useGatewayBaseUrl($this->gateway->baseUrl($skenario));
    }

    private function seedConv(): int
    {
        return $this->seedConversation('6281299' . random_int(10000, 99999) . '@s.whatsapp.net');
    }

    private function seedPesan(int $conversationId, string $direction, string $text): int
    {
        $now = date('Y-m-d H:i:s');
        $this->inbox->table('messages')->insert([
            'conversation_id'   => $conversationId,
            'wa_message_id'     => 'WAMSG-PALSU-' . random_int(100000, 999999),
            'direction'         => $direction,
            'message_type'      => 'text',
            'sender_jid'        => $direction === 'incoming' ? '6281299000001@s.whatsapp.net' : null,
            'text'              => $text,
            'send_status'       => $direction === 'outgoing' ? 'sent' : 'received',
            'message_timestamp' => $now,
            'created_at'        => $now,
            'is_internal'       => 0,
            'is_forwarded'      => 0,
        ]);

        return (int) $this->inbox->insertID();
    }

    private function postKirim(int $conversationId, string $operationId, array $extra = [])
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => 21, 'role' => 'kasir'])
            ->post('/inbox/kirim', array_merge([
                'conversation_id' => $conversationId,
                'text'            => 'halo dari test',
                'operation_id'    => $operationId,
            ], $extra));
    }

    private function json($response): array
    {
        return $this->gatewayBodyJson($response);
    }

    private function auditRows(int $conversationId): array
    {
        return $this->inbox->table('message_send_audit')
            ->where('conversation_id', $conversationId)
            ->get()
            ->getResultArray();
    }

    private function outgoingCount(int $conversationId): int
    {
        return $this->inbox->table('messages')
            ->where('conversation_id', $conversationId)
            ->where('direction', 'outgoing')
            ->countAllResults();
    }

    /** Gagal: tidak ada pesan "terkirim" tersimpan dan tepat satu baris audit. */
    private function assertGagalTanpaPesanTersimpan(int $conversationId, string $outcomeKind): array
    {
        $this->assertSame(0, $this->outgoingCount($conversationId), 'Kirim gagal tidak boleh menyimpan pesan sebagai terkirim.');

        $rows = $this->auditRows($conversationId);
        $this->assertCount(1, $rows, 'Kirim gagal harus tercatat tepat 1 baris di message_send_audit.');
        $this->assertSame($outcomeKind, $rows[0]['outcome_kind']);

        return $rows[0];
    }

    public function testSuksesMenyimpanPesanDenganWaMessageIdDariGateway(): void
    {
        $conv = $this->seedConv();
        $this->pakaiSkenario('ok');

        $res = $this->postKirim($conv, 'op-palsu-ok');

        $res->assertStatus(200);
        $json = $this->json($res);
        $this->assertSame('success', $json['status']);
        $this->assertStringStartsWith('FAKEWA', $json['message']['wa_message_id']);
        $this->assertSame(1, $this->outgoingCount($conv));
        $this->assertSame([], $this->auditRows($conv));

        $requests = $this->gateway->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('send', $requests[0]['endpoint']);
        $this->assertSame('Bearer ' . $this->gatewayToken, $requests[0]['authorization']);
        $this->assertSame('halo dari test', $requests[0]['body']['text']);
        $this->assertSame('op-palsu-ok', $requests[0]['body']['operation_id']);

        $row = $this->inbox->table('conversations')->where('id', $conv)->get()->getRowArray();
        $this->assertSame(21, (int) $row['assigned_to'], 'Kirim sukses ke percakapan tanpa pemilik harus auto-assign ke pengirim.');
    }

    public function testGatewayVersiLamaTanpaQuoteAppliedDilaporkanTanpaKutipan(): void
    {
        $conv   = $this->seedConv();
        $sumber = $this->seedPesan($conv, 'incoming', 'pesan pelanggan');
        $this->pakaiSkenario('lama');

        $res = $this->postKirim($conv, 'op-palsu-lama', ['quoted_message_id' => $sumber]);

        $res->assertStatus(200);
        $json = $this->json($res);
        $this->assertSame('success', $json['status']);
        $this->assertFalse($json['quote_applied'], 'Gateway lama tanpa quote_applied harus dibaca sebagai "terkirim tanpa kutipan".');
        $this->assertArrayHasKey('quoted', $this->gateway->requests()[0]['body']);
    }

    public function testKutipanDiterapkanGatewayDilaporkanTrue(): void
    {
        $conv   = $this->seedConv();
        $sumber = $this->seedPesan($conv, 'incoming', 'pesan pelanggan');
        $this->pakaiSkenario('ok');

        $json = $this->json($this->postKirim($conv, 'op-palsu-quote', ['quoted_message_id' => $sumber]));

        $this->assertSame('success', $json['status']);
        $this->assertTrue($json['quote_applied']);
    }

    public function testGatewayMenolak400Definitif(): void
    {
        $conv = $this->seedConv();
        $this->pakaiSkenario('tolak400');

        $res = $this->postKirim($conv, 'op-palsu-400');

        $res->assertStatus(400);
        $json = $this->json($res);
        $this->assertSame('error', $json['status']);
        $this->assertSame('INVALID_CHAT_ID', $json['error_code']);
        $audit = $this->assertGagalTanpaPesanTersimpan($conv, 'definitive');
        $this->assertSame(400, (int) $audit['http_code']);
        $this->assertSame('halo dari test', $audit['preview_text']);
    }

    public function testGatewayHttp500Definitif(): void
    {
        $conv = $this->seedConv();
        $this->pakaiSkenario('http500');

        $res = $this->postKirim($conv, 'op-palsu-500');

        $res->assertStatus(500);
        $json = $this->json($res);
        $this->assertSame('error', $json['status']);
        $this->assertSame('SEND_FAILED', $json['error_code']);
        $this->assertSame('Gagal mengirim pesan: Evolution gagal mengirim', $json['message']);
        $this->assertGagalTanpaPesanTersimpan($conv, 'definitive');
    }

    public function testHasilBelumPastiDitandaiUncertain(): void
    {
        $conv = $this->seedConv();
        $this->pakaiSkenario('unresolved');

        $res = $this->postKirim($conv, 'op-palsu-504');

        $res->assertStatus(504);
        $json = $this->json($res);
        $this->assertTrue($json['uncertain']);
        $this->assertSame('in_flight', $json['state']);
        $this->assertGagalTanpaPesanTersimpan($conv, 'unresolved');
    }

    public function testResponsBukanJsonDiperlakukanGagal(): void
    {
        $conv = $this->seedConv();
        $this->pakaiSkenario('bukan_json');

        $res = $this->postKirim($conv, 'op-palsu-html');

        $res->assertStatus(502);
        $json = $this->json($res);
        $this->assertSame('error', $json['status']);
        $this->assertNull($json['error_code']);
        $this->assertStringContainsString('respons Gateway tidak valid', $json['message']);
        $this->assertGagalTanpaPesanTersimpan($conv, 'network');
    }

    /**
     * TODO-Q2b: HTTP 200 dengan success:false tetap GAGAL, dan browser
     * menerima 502 (bukan 200) supaya log/monitoring tidak tertipu. Audit
     * tetap menyimpan kode asli dari Gateway (200).
     */
    public function testHttp200DenganSuccessFalseDijawab502(): void
    {
        $conv = $this->seedConv();
        $this->pakaiSkenario('sukses_palsu');

        $res = $this->postKirim($conv, 'op-palsu-200');

        $res->assertStatus(502);
        $json = $this->json($res);
        $this->assertSame('error', $json['status']);
        $this->assertSame('Gagal mengirim pesan: ditolak walau HTTP 200', $json['message']);
        $audit = $this->assertGagalTanpaPesanTersimpan($conv, 'network');
        $this->assertSame(200, (int) $audit['http_code'], 'Audit harus menyimpan kode HTTP asli dari Gateway.');
    }

    /** TODO-Q2b: kode error dari Gateway (>= 400) tetap diteruskan apa adanya, termasuk lewat jalur edit. */
    public function testEditHttp200DenganSuccessFalseDijawab502(): void
    {
        $conv  = $this->seedConv();
        $msgId = $this->seedPesan($conv, 'outgoing', 'teks asli');
        $this->pakaiSkenario('sukses_palsu');

        $res = $this->withSession(['isLoggedIn' => true, 'id_user' => 21, 'role' => 'kasir'])
            ->post('/inbox/pesan/' . $msgId . '/edit', ['new_text' => 'teks baru', 'operation_id' => 'op-palsu-edit-200']);

        $res->assertStatus(502);
        $this->assertSame('error', $this->json($res)['status']);
        $this->assertSame('teks asli', $this->inbox->table('messages')->where('id', $msgId)->get()->getRowArray()['text']);
    }

    public function testGatewayMatiDilaporkanTidakBisaDihubungi(): void
    {
        $conv = $this->seedConv();
        FakeGatewayServer::useGatewayBaseUrl('http://127.0.0.1:' . FakeGatewayServer::unusedPort());

        $res = $this->postKirim($conv, 'op-palsu-mati');

        $res->assertStatus(502);
        $json = $this->json($res);
        $this->assertStringContainsString('Tidak bisa menghubungi Gateway', $json['message']);
        $this->assertGagalTanpaPesanTersimpan($conv, 'network');
    }

    public function testEditGatewayHttp500TidakMengubahTeks(): void
    {
        $conv  = $this->seedConv();
        $msgId = $this->seedPesan($conv, 'outgoing', 'teks asli');
        $this->pakaiSkenario('http500');

        $res = $this->withSession(['isLoggedIn' => true, 'id_user' => 21, 'role' => 'kasir'])
            ->post('/inbox/pesan/' . $msgId . '/edit', ['new_text' => 'teks baru', 'operation_id' => 'op-palsu-edit-500']);

        $res->assertStatus(500);
        $this->assertSame('Gagal mengedit pesan: Evolution gagal mengirim', $this->json($res)['message']);
        $row = $this->inbox->table('messages')->where('id', $msgId)->get()->getRowArray();
        $this->assertSame('teks asli', $row['text']);
        $this->assertNull($row['edited_at']);

        $audit = $this->auditRows($conv);
        $this->assertCount(1, $audit);
        $this->assertSame('editPesan', $audit[0]['context']);
        $this->assertSame('teks baru', $audit[0]['preview_text']);
    }

    public function testEditSuksesMengubahTeks(): void
    {
        $conv  = $this->seedConv();
        $msgId = $this->seedPesan($conv, 'outgoing', 'teks asli');
        $this->pakaiSkenario('ok');

        $res = $this->withSession(['isLoggedIn' => true, 'id_user' => 21, 'role' => 'kasir'])
            ->post('/inbox/pesan/' . $msgId . '/edit', ['new_text' => 'teks baru', 'operation_id' => 'op-palsu-edit-ok']);

        $res->assertStatus(200);
        $this->assertSame('edited', $this->json($res)['state']);
        $row = $this->inbox->table('messages')->where('id', $msgId)->get()->getRowArray();
        $this->assertSame('teks baru', $row['text']);
        $this->assertSame('edit', $this->gateway->requests()[0]['endpoint']);
    }

    /** Paling akhir: menunggu ~10 detik (CURLOPT_TIMEOUT di Inbox.php). */
    public function testZzTimeoutDilaporkanTidakBisaDihubungi(): void
    {
        $conv = $this->seedConv();
        $this->pakaiSkenario('lambat');

        $mulai = microtime(true);
        $res   = $this->postKirim($conv, 'op-palsu-lambat');
        $durasi = microtime(true) - $mulai;

        $res->assertStatus(502);
        $this->assertStringContainsString('Tidak bisa menghubungi Gateway', $this->json($res)['message']);
        $this->assertLessThan(12, $durasi, 'cURL harus berhenti di batas timeout, bukan menunggu Gateway selesai.');
        $this->assertGagalTanpaPesanTersimpan($conv, 'network');
    }
}
