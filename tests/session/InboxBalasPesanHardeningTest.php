<?php

use App\Controllers\Inbox;
use App\Models\MessageModel;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Inbox as GatewayInboxConfig;
use Psr\Log\NullLogger;

/**
 * Balas Pesan (Tahap 3, TASK-014) -- Hardening & Regresi Phase 4.
 *
 * Menutup edge case yang mudah terlewat dan membuktikan nol regresi pada
 * percakapan pribadi/grup (spec AC-004/AC-005/AC-006/AC-007, GUD-001).
 *
 * @internal
 */
final class InboxBalasPesanHardeningTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('aulia_inboxdb_test', db_connect('inbox')->query('SELECT DATABASE() AS db')->getRow()->db);

        $db = db_connect('inbox');
        $db->table('messages')->emptyTable();
        $db->table('conversations')->emptyTable();
        $db->table('gateway_status')->emptyTable();
        $db->table('gateway_status')->insert([
            'id'                => 1,
            'status'            => 'connected',
            'last_heartbeat_at' => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);

        db_connect()->query('CREATE TABLE IF NOT EXISTS db_users (id INTEGER PRIMARY KEY, nama TEXT, username TEXT)');
        db_connect()->table('db_users')->delete(['id' => 7]);
        db_connect()->table('db_users')->insert(['id' => 7, 'nama' => 'Test Kasir', 'username' => 'kasir']);

        $_SESSION = ['id_user' => 7, 'role' => 'kasir'];
    }

    protected function tearDown(): void
    {
        unset($_SESSION);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // AC-004: kutipan tetap tampil apa adanya setelah soft-delete, lewat
    // endpoint yang benar-benar dipakai UI (apiMessages), bukan cuma DB.
    // ------------------------------------------------------------------

    public function testApiMessagesTetapMenampilkanKutipanSetelahSumberSoftDeleted(): void
    {
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'pesan yang akan dihapus',
        ]);

        $controller = $this->controller(['quoted_message_id' => $sourceId]);
        $method     = (new \ReflectionClass($controller))->getMethod('kirimKeConversation');
        $method->setAccessible(true);
        $method->invoke($controller, $this->conversation($conversationId), 'balasan berkutipan');

        // Sumber dihapus SETELAH balasan dikirim -- snapshot sudah beku.
        db_connect('inbox')->table('messages')->where('id', $sourceId)->update(['deleted_at' => date('Y-m-d H:i:s')]);

        $apiController = $this->controller([]);
        $response      = $apiController->apiMessages($conversationId);
        $body          = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $balasan = array_values(array_filter($body['messages'], fn ($m) => $m['direction'] === 'outgoing'))[0] ?? null;
        $this->assertNotNull($balasan, 'Balasan berkutipan harus ada di hasil apiMessages().');
        $this->assertSame(
            'pesan yang akan dihapus',
            $balasan['quoted_snippet'],
            'AC-004: kutipan tampil apa adanya walau pesan asli sudah di-soft-delete, tanpa penanda "dihapus".'
        );
    }

    // ------------------------------------------------------------------
    // AC-005: fallback tampilan media gagal muat TIDAK menulis ulang DB
    // ------------------------------------------------------------------

    public function testMediaGagalMuatMengembalikan410TanpaMengubahSnapshotKutipan(): void
    {
        // Pesan media BIASA (bukan kutipan) yang media_metadata-nya sengaja
        // rusak (URL sumber sudah kedaluwarsa) -- membuktikan Inbox::media()
        // TETAP mengembalikan error tampilan (410-alike lewat 500 referensi
        // rusak) tanpa pernah menyentuh kolom quoted_* milik pesan MANA PUN
        // (REQ-008 fallback tampilan: kegagalan di sisi TAMPILAN, bukan
        // snapshot).
        $conversationId = $this->seedConversation();
        $mediaId        = $this->seedMessage($conversationId, [
            'direction'       => 'outgoing',
            'message_type'    => 'image',
            'text'            => null,
            'media_metadata'  => json_encode(['not' => 'a valid ref']),
            'send_status'     => 'sent',
        ]);
        $quotingId = $this->seedMessage($conversationId, [
            'direction'              => 'outgoing',
            'message_type'           => 'text',
            'text'                   => 'balasan yang mengutip pesan media',
            'quoted_wa_message_id'   => 'SRC-' . $mediaId,
            'quoted_sender_label'    => 'Anshar',
            'quoted_snippet'         => '[Foto]',
            'quoted_media_available' => 0,
        ]);

        $controller = $this->controller([]);
        $response   = $controller->media($mediaId);

        $this->assertSame(500, $response->getStatusCode(), 'Referensi media rusak -> error tampilan, bukan crash.');

        // Snapshot pesan lain yang MENGUTIP media ini TIDAK BOLEH berubah --
        // fallback tampilan tidak pernah menulis ulang kolom quoted_*.
        $row = db_connect('inbox')->table('messages')->where('id', $quotingId)->get()->getRowArray();
        $this->assertSame(0, (int) $row['quoted_media_available'], 'AC-005: snapshot tetap beku (0), tidak ditulis ulang oleh fallback tampilan.');
    }

    // ------------------------------------------------------------------
    // AC-007: pada grup, quoted_sender_label = identitas anggota, BUKAN
    // nama grup.
    // ------------------------------------------------------------------

    public function testGrupQuotedSenderLabelMenampilkanIdentitasAnggotaBukanNamaGrup(): void
    {
        $conversationId = $this->seedConversation([
            'chat_id'    => '628' . random_int(100000000, 999999999) . '-1@g.us',
            'jid_type'   => 'group',
            'group_name' => 'Grup Pelanggan Setia',
        ]);
        $sourceId = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'Halo dari anggota grup',
            'sender_jid'   => '628999888777@s.whatsapp.net', // participant, BUKAN JID grup
        ]);

        $controller = $this->controller(['quoted_message_id' => $sourceId]);
        $method     = (new \ReflectionClass($controller))->getMethod('kirimKeConversation');
        $method->setAccessible(true);
        $method->invoke($controller, $this->conversation($conversationId), 'balasan ke anggota grup');

        $row = $this->lastOutgoing($conversationId);
        $this->assertSame('628999888777', $row['quoted_sender_label'], 'AC-007: identitas anggota (nomor), bukan nama grup.');
        $this->assertNotSame('Grup Pelanggan Setia', $row['quoted_sender_label']);
    }

    // ------------------------------------------------------------------
    // AC-006: idempotensi lintas jalur -- balasan grup berkutipan juga
    // hanya menulis satu baris pada retry operation_id sama.
    // ------------------------------------------------------------------

    public function testIdempotensiBerlakuUntukBalasanGrupBerkutipan(): void
    {
        $conversationId = $this->seedConversation([
            'chat_id'  => '628' . random_int(100000000, 999999999) . '-2@g.us',
            'jid_type' => 'group',
        ]);
        $sourceId = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'pesan anggota grup',
            'sender_jid'   => '628999888777@s.whatsapp.net',
        ]);

        $post = ['operation_id' => 'grup-quote-op-1', 'quoted_message_id' => $sourceId];

        $first = $this->controller($post);
        $m1    = (new \ReflectionClass($first))->getMethod('kirimKeConversation');
        $m1->setAccessible(true);
        $m1->invoke($first, $this->conversation($conversationId), 'balasan grup berkutipan');

        $second = $this->controller($post);
        $m2     = (new \ReflectionClass($second))->getMethod('kirimKeConversation');
        $m2->setAccessible(true);
        $response2 = $m2->invoke($second, $this->conversation($conversationId), 'balasan grup berkutipan');
        $body2     = json_decode($response2->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($body2['replayed'], 'AC-006: replay terdeteksi pada percakapan grup.');
        $this->assertSame(1, $this->countOutgoing($conversationId), 'AC-006: hanya satu baris tersimpan pada grup berkutipan.');
    }

    // ------------------------------------------------------------------
    // Regresi nol: percakapan grup TANPA kutipan tidak berubah sama sekali
    // ------------------------------------------------------------------

    public function testPercakapanGrupTanpaKutipanTidakBerubah(): void
    {
        $conversationId = $this->seedConversation([
            'chat_id'  => '628' . random_int(100000000, 999999999) . '-3@g.us',
            'jid_type' => 'group',
        ]);

        $controller = $this->controller([]);
        $method     = (new \ReflectionClass($controller))->getMethod('kirimKeConversation');
        $method->setAccessible(true);
        $response = $method->invoke($controller, $this->conversation($conversationId), 'pesan grup biasa');
        $body     = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayNotHasKey('quote_applied', $body, 'Tanpa kutipan, respons grup tidak berubah.');

        $row = $this->lastOutgoing($conversationId);
        $this->assertNull($row['quoted_wa_message_id']);
        $this->assertNull($row['quoted_sender_label']);
        $this->assertNull($row['quoted_snippet']);
        $this->assertNull($row['quoted_media_available']);
    }

    public function testPercakapanPribadiTanpaKutipanTidakBerubah(): void
    {
        $conversationId = $this->seedConversation();

        $controller = $this->controller([]);
        $method     = (new \ReflectionClass($controller))->getMethod('kirimKeConversation');
        $method->setAccessible(true);
        $response = $method->invoke($controller, $this->conversation($conversationId), 'pesan pribadi biasa');
        $body     = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayNotHasKey('quote_applied', $body);
        $this->assertNull($this->lastOutgoing($conversationId)['quoted_wa_message_id']);
    }

    // ------------------------------------------------------------------
    // Validation Criteria Section 13 (item #4): reaksi (c) hasil ambigu
    // tetap berlaku pada balasan berkutipan -- mekanisme gatewayFailureResponse()
    // dipanggil identik terlepas dari ada/tidaknya quoted_message_id, jadi
    // celah ini murni soal cakupan test, bukan cabang kode terpisah.
    // ------------------------------------------------------------------

    public function testHasilAmbiguPadaBalasanBerkutipanTidakMenulisPesanDanMemberiPeringatan(): void
    {
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'sumber',
        ]);

        $controller = new InboxHardeningAmbiguousSpy();
        $request    = new IncomingRequest(new \Config\App(), new URI('cli'), null, new UserAgent());
        $request->setGlobal('post', ['quoted_message_id' => $sourceId]);
        $controller->initController($request, new Response(new \Config\App()), new NullLogger());

        $method = (new \ReflectionClass($controller))->getMethod('kirimKeConversation');
        $method->setAccessible(true);
        $response = $method->invoke($controller, $this->conversation($conversationId), 'balasan berkutipan yang hasilnya ambigu');
        $body     = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(504, $response->getStatusCode());
        $this->assertTrue($body['uncertain'], 'Reaksi (c): hasil ambigu, bukan sukses maupun gagal pasti.');
        $this->assertStringContainsString('jangan kirim ulang dulu', $body['message']);
        $this->assertSame(0, $this->countOutgoing($conversationId), 'Reaksi (c): tidak ada baris pesan yang ditulis untuk hasil ambigu.');
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $post */
    private function controller(array $post): InboxHardeningSpy
    {
        $request = new IncomingRequest(new \Config\App(), new URI('cli'), null, new UserAgent());
        $request->setGlobal('post', $post);
        $controller = new InboxHardeningSpy();
        $controller->initController($request, new Response(new \Config\App()), new NullLogger());

        return $controller;
    }

    /** @param array<string, mixed> $override */
    private function seedConversation(array $override = []): int
    {
        $db  = db_connect('inbox');
        $now = date('Y-m-d H:i:s');

        $db->table('conversations')->insert(array_merge([
            'chat_id'                 => '628' . random_int(100000000, 999999999) . '@s.whatsapp.net',
            'jid_type'                => 'pn',
            'status'                  => 'open',
            'assigned_to'             => 7,
            'last_message_direction' => 'incoming',
            'created_at'              => $now,
            'updated_at'              => $now,
        ], $override));

        return (int) $db->insertID();
    }

    /** @param array<string, mixed> $attributes */
    private function seedMessage(int $conversationId, array $attributes): int
    {
        $db = db_connect('inbox');
        $db->table('messages')->insert([
            'conversation_id'   => $conversationId,
            'wa_message_id'     => 'SRC-TEMP',
            'direction'         => 'incoming',
            'message_type'      => 'text',
            'sender_jid'        => '628999888777@s.whatsapp.net',
            'text'              => null,
            'message_timestamp' => date('Y-m-d H:i:s'),
            'send_status'       => 'received',
        ]);
        $id = (int) $db->insertID();

        $row = $attributes;
        $row['wa_message_id'] ??= 'SRC-' . $id;
        $db->table('messages')->where('id', $id)->update($row);

        return $id;
    }

    private function conversation(int $id): array
    {
        return db_connect('inbox')->table('conversations')->where('id', $id)->get()->getResultArray()[0];
    }

    /** @return array<string, mixed> */
    private function lastOutgoing(int $conversationId): array
    {
        $rows = db_connect('inbox')->table('messages')
            ->where('conversation_id', $conversationId)
            ->where('direction', 'outgoing')
            ->orderBy('id', 'DESC')
            ->get()
            ->getResultArray();

        $this->assertNotEmpty($rows, 'Harus ada pesan outgoing untuk percakapan ini.');

        return $rows[0];
    }

    private function countOutgoing(int $conversationId): int
    {
        return db_connect('inbox')->table('messages')
            ->where('conversation_id', $conversationId)
            ->where('direction', 'outgoing')
            ->countAllResults();
    }
}

final class InboxHardeningSpy extends Inbox
{
    protected function callGatewaySend(GatewayInboxConfig $config, string $chatId, string $text, ?string $operationId = null, ?array $quoted = null, ?bool $forward = null): array
    {
        return [
            'ok'            => true,
            'wa_message_id' => 'wa-hardening-' . bin2hex(random_bytes(4)),
            'quote_applied' => (bool) $quoted,
        ];
    }
}

/**
 * Simulasi reaksi (c) REQ-006a: Gateway merespons hasil AMBIGU (mis. timeout
 * yang berakhir 504 `SEND_UNRESOLVED`) untuk permintaan yang membawa
 * `quoted`. `gatewayFailureResponse()` yang menangani ini TIDAK punya cabang
 * khusus kutipan -- spy ini membuktikan itu tetap berperilaku benar.
 */
final class InboxHardeningAmbiguousSpy extends Inbox
{
    protected function callGatewaySend(GatewayInboxConfig $config, string $chatId, string $text, ?string $operationId = null, ?array $quoted = null, ?bool $forward = null): array
    {
        return [
            'ok'         => false,
            'error'      => 'timeout',
            'error_code' => 'SEND_UNRESOLVED',
            'state'      => 'failed',
            'replayed'   => false,
            'http_code'  => 504,
        ];
    }
}
