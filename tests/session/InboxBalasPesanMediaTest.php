<?php

use App\Controllers\Inbox;
use App\Libraries\InboxOutgoingRequest;
use CodeIgniter\HTTP\Files\FileCollection;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Inbox as GatewayInboxConfig;
use Psr\Log\NullLogger;

/**
 * Balas Pesan (Tahap 3, TASK-006) -- feature test untuk
 * balas-dengan-lampiran sambil mengutip di seam HTTP `Inbox::kirimMedia()`.
 *
 * Cakupan: payload `quoted` diteruskan ke `/send-media`, snapshot tersimpan
 * di baris media, `quote_applied` dilaporkan, dan guard taksonomi F-A/F-D
 * berlaku sama seperti jalur teks.
 *
 * @internal
 */
final class InboxBalasPesanMediaTest extends CIUnitTestCase
{
    /** @var list<string> */
    private array $tempMediaFiles = [];

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

        foreach ($this->tempMediaFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempMediaFiles = [];

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // AC-003b: kutipan ikut pada jalur media
    // ------------------------------------------------------------------

    public function testKirimGambarBerkutipanMengisiKolomSnapshot(): void
    {
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'Boleh kirim fotonya?',
        ]);

        $controller = $this->controller(['quoted_message_id' => $sourceId]);
        $response   = $controller->kirimMedia();
        $body       = $this->body($controller);

        $this->assertSame(200, $response->getStatusCode(), 'AC-003b: media berkutipan harus terkirim.');

        $row = $this->lastOutgoing($conversationId);
        $this->assertSame('image', $row['message_type']);
        $this->assertSame('SRC-' . $sourceId, $row['quoted_wa_message_id']);
        $this->assertSame('628999888777', $row['quoted_sender_label']);
        $this->assertSame('Boleh kirim fotonya?', $row['quoted_snippet']);
        $this->assertTrue($body['quote_applied']);
    }

    public function testPayloadKeGatewayMenghormatiQuoted(): void
    {
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'Boleh kirim fotonya?',
            'sender_jid'   => '628999888777@s.whatsapp.net',
        ]);

        $controller = $this->controller(['quoted_message_id' => $sourceId]);
        $controller->kirimMedia();

        $this->assertIsArray($controller->capturedQuoted, 'REQ-001a: quoted harus diteruskan ke /send-media.');
        $this->assertSame('SRC-' . $sourceId, $controller->capturedQuoted['wa_message_id']);
        $this->assertSame('628999888777@s.whatsapp.net', $controller->capturedQuoted['sender_jid']);
        $this->assertSame('text', $controller->capturedQuoted['message_type']);
        $this->assertFalse($controller->capturedQuoted['fromMe'], 'incoming -> fromMe:false.');
        $this->assertSame('Boleh kirim fotonya?', $controller->capturedQuoted['text']);
    }

    public function testSumberMediaMengirimMediaTypePadaPayload(): void
    {
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'image',
            'text'         => null,
        ]);

        $controller = $this->controller(['quoted_message_id' => $sourceId]);
        $controller->kirimMedia();

        $this->assertSame('image', $controller->capturedQuoted['media_type']);
        $this->assertArrayNotHasKey('text', $controller->capturedQuoted, 'Sumber media tidak mengirim field text.');
    }

    public function testFromMeHanyaDariDirectionPadaJalurMedia(): void
    {
        $conversationId = $this->seedConversation();
        $outgoingSource = $this->seedMessage($conversationId, [
            'direction'    => 'outgoing',
            'message_type' => 'text',
            'text'         => 'pesan kasir sebelumnya',
            'sender_jid'   => null,
        ]);

        $controller = $this->controller(['quoted_message_id' => $outgoingSource]);
        $controller->kirimMedia();

        $this->assertTrue($controller->capturedQuoted['fromMe'], 'outgoing -> fromMe:true walau sender_jid NULL.');
        $this->assertNull($controller->capturedQuoted['sender_jid'], 'Sumber outgoing boleh tanpa sender_jid.');
    }

    // ------------------------------------------------------------------
    // Reaksi (a) REQ-006 pada jalur media
    // ------------------------------------------------------------------

    public function testQuoteAppliedFalseTetapMengirimMedia(): void
    {
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'sumber',
        ]);

        $controller = $this->controller(['quoted_message_id' => $sourceId], ['quote_applied' => false]);
        $response   = $controller->kirimMedia();
        $body       = $this->body($controller);

        $this->assertSame(200, $response->getStatusCode(), 'Kegagalan kutipan TIDAK menggagalkan pengiriman media.');
        $this->assertFalse($body['quote_applied']);
        $this->assertSame('sent', $body['message']['send_status']);
        $this->assertNotNull($body['message']['quoted_wa_message_id'], 'Snapshot tetap tercatat.');
    }

    public function testGatewayTanpaFieldQuoteAppliedDiperlakukanFalse(): void
    {
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'sumber',
        ]);

        $controller = $this->controller(['quoted_message_id' => $sourceId], ['quote_applied' => null]);
        $controller->kirimMedia();
        $body = $this->body($controller);

        $this->assertArrayHasKey('quote_applied', $body);
        $this->assertFalse($body['quote_applied'], 'ASSUMPTION-010: field yang hilang = false.');
    }

    // ------------------------------------------------------------------
    // Taksonomi F-D & guard F-A
    // ------------------------------------------------------------------

    public function testSumberTidakAdaDitolakDengan400(): void
    {
        $conversationId = $this->seedConversation();
        $controller     = $this->controller(['quoted_message_id' => 999999]);
        $response       = $controller->kirimMedia();

        $this->assertSame(400, $response->getStatusCode(), 'F-D: ID sumber tidak ada -> 400.');
        $this->assertSame(0, $this->countOutgoing($conversationId), 'F-D: tidak ada baris yang ditulis.');
    }

    public function testKutipanLintasPercakapanDitolakDengan400(): void
    {
        $conversationA = $this->seedConversation('628111111111@s.whatsapp.net');
        $conversationB = $this->seedConversation('628222222222@s.whatsapp.net');
        $sourceId      = $this->seedMessage($conversationA, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'pesan dari percakapan lain',
        ]);

        $controller = $this->controller(['quoted_message_id' => $sourceId], conversationId: $conversationB);
        $response   = $controller->kirimMedia();

        $this->assertSame(400, $response->getStatusCode(), 'F-A: kutip lintas percakapan -> 400.');
        $this->assertSame(0, $this->countOutgoing($conversationB), 'F-A: tidak ada baris yang ditulis.');
    }

    public function testSumberSoftDeletedTetapDapatDikutip(): void
    {
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'pesan yang dihapus setelah dipilih',
        ]);

        db_connect('inbox')->table('messages')
            ->where('id', $sourceId)
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);

        $controller = $this->controller(['quoted_message_id' => $sourceId]);
        $response   = $controller->kirimMedia();

        $this->assertSame(200, $response->getStatusCode(), 'AC-004: sumber soft-deleted tetap terkirim.');
        $this->assertSame('pesan yang dihapus setelah dipilih', $this->lastOutgoing($conversationId)['quoted_snippet']);
    }

    // ------------------------------------------------------------------
    // GUD-001: tanpa kutipan, jalur media tidak berubah
    // ------------------------------------------------------------------

    public function testKirimMediaTanpaKutipanTidakMengisiKolomKutipan(): void
    {
        $conversationId = $this->seedConversation();
        $controller     = $this->controller([]);
        $response       = $controller->kirimMedia();
        $body           = $this->body($controller);

        $this->assertSame(200, $response->getStatusCode());

        $row = $this->lastOutgoing($conversationId);
        $this->assertNull($row['quoted_wa_message_id']);
        $this->assertNull($row['quoted_snippet']);
        $this->assertNull($controller->capturedQuoted, 'Tanpa kutipan, field quoted TIDAK dikirim ke Gateway.');
        $this->assertArrayNotHasKey('quote_applied', $body, 'Respons lama tidak berubah (CON-007).');
    }

    public function testReplayDenganOperationIdSamaTidakMenulisBarisKedua(): void
    {
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'sumber',
        ]);

        $post = ['operation_id' => 'ui-media-quote-1', 'quoted_message_id' => $sourceId];

        $first = $this->controller($post);
        $first->kirimMedia();
        $this->assertFalse(($this->body($first)['replayed'] ?? false));

        $second = $this->controller($post);
        $second->kirimMedia();
        $secondBody = $this->body($second);

        $this->assertTrue($secondBody['replayed'], 'AC-006: replay terdeteksi.');
        $this->assertTrue($secondBody['quote_applied'], 'Replay tetap melaporkan indikator kutipan.');
        $this->assertSame(1, $this->countOutgoing($conversationId), 'AC-006: hanya satu baris tersimpan.');
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $gatewayOverrides
     */
    private function controller(
        array $post,
        array $gatewayOverrides = [],
        ?int $conversationId = null
    ): InboxBalasPesanMediaSpy {
        $conversationId ??= $this->lastConversationId();

        $request = new IncomingRequest(new \Config\App(), new URI('cli'), null, new UserAgent());
        $request->setGlobal('post', $post + ['conversation_id' => $conversationId]);

        // Lampiran PNG 1x1 asli supaya finfo mendeteksi image/png persis seperti
        // unggahan kasir sungguhan (pola InboxOutgoingIdempotencyTest).
        $path = tempnam(sys_get_temp_dir(), 'aulia-media-quote-');
        $this->assertIsString($path);
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8AAAwAB/wD/AH8AAAAASUVORK5CYII='));
        $this->tempMediaFiles[] = $path;

        $file = new InboxBalasPesanMediaUpload($path, 'foto.png', 'image/png', (int) filesize($path), UPLOAD_ERR_OK);

        $collection    = new FileCollection();
        $filesProperty = new \ReflectionProperty($collection, 'files');
        $filesProperty->setAccessible(true);
        $filesProperty->setValue($collection, ['media' => $file]);

        $requestFiles = new \ReflectionProperty($request, 'files');
        $requestFiles->setAccessible(true);
        $requestFiles->setValue($request, $collection);

        $controller = new InboxBalasPesanMediaSpy($gatewayOverrides);
        $controller->initController($request, new Response(new \Config\App()), new NullLogger());

        return $controller;
    }

    private function body(Inbox $controller): array
    {
        $property = (new \ReflectionClass($controller))->getParentClass()->getProperty('response');
        $property->setAccessible(true);

        return json_decode($property->getValue($controller)->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function seedConversation(string $chatId = '628123456789@s.whatsapp.net'): int
    {
        $db = db_connect('inbox');
        $db->table('conversations')->insert([
            'chat_id'                => $chatId,
            'jid_type'               => 'pn',
            'status'                 => 'open',
            'assigned_to'            => 7,
            'last_message_direction' => 'incoming',
            'created_at'             => date('Y-m-d H:i:s'),
            'updated_at'             => date('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }

    private function lastConversationId(): int
    {
        return (int) db_connect('inbox')->table('conversations')->selectMax('id')->get()->getRowArray()['id'];
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

        $db->table('messages')->where('id', $id)->update(['wa_message_id' => 'SRC-' . $id] + $attributes);

        return $id;
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

final class InboxBalasPesanMediaSpy extends Inbox
{
    /** @var array<string, mixed>|null Objek `quoted` yang diterima controller. */
    public ?array $capturedQuoted = null;

    public function __construct(private readonly array $gatewayOverrides = [])
    {
    }

    protected function callGatewaySendMedia(GatewayInboxConfig $config, InboxOutgoingRequest $request): array
    {
        $this->capturedQuoted = $request->quoted;

        return [
            'ok'            => true,
            'wa_message_id' => 'wa-media-out-' . bin2hex(random_bytes(4)),
            'media_ref'     => null,
            'error_code'    => null,
            'state'         => 'sent',
            'replayed'      => false,
            'quote_applied' => array_key_exists('quote_applied', $this->gatewayOverrides)
                ? $this->gatewayOverrides['quote_applied']
                : true,
        ];
    }
}

/**
 * Test-only upload: hanya melonggarkan isValid(); method file lain memakai
 * perilaku framework sungguhan (termasuk deteksi mime via finfo).
 */
final class InboxBalasPesanMediaUpload extends UploadedFile
{
    public function isValid(): bool
    {
        return true;
    }
}
