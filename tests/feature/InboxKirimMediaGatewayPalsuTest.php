<?php

use App\Controllers\Inbox as InboxController;
use App\Database\Migrations\AddPreviewToMessageSendAudit;
use App\Database\Migrations\CreateMessageSendAudit;
use CodeIgniter\HTTP\Files\FileCollection;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FakeGatewayServer;
use Tests\Support\FakeUploadedFile;
use Tests\Support\GatewayApiTestTrait;

require_once __DIR__ . '/../../app/Database/Migrations/2026-10-09-000001_CreateMessageSendAudit.php';
require_once __DIR__ . '/../../app/Database/Migrations/2026-10-10-000001_AddPreviewToMessageSendAudit.php';

/** FileCollection yang isinya ditentukan test, bukan dibaca dari $_FILES. */
class InboxMediaTestFileCollection extends FileCollection
{
    public function __construct(array $files)
    {
        $this->files = $files;
    }
}

/**
 * Satu-satunya hal yang dipalsukan: sumber berkas unggahan. UploadedFile asli
 * menolak berkas apa pun di luar request multipart nyata (is_uploaded_file()
 * selalu false di CLI), jadi berkas disuntikkan sebagai FakeUploadedFile.
 * Validasi, cURL ke Gateway, dan penyimpanan tetap memakai kode ASLI
 * Inbox::kirimMedia() -- callGatewaySendMedia() TIDAK di-override.
 */
class InboxKirimMediaStub extends InboxController
{
    public static ?FakeUploadedFile $berkas = null;

    public function kirimMedia()
    {
        if (self::$berkas !== null && $this->request instanceof IncomingRequest) {
            (new ReflectionProperty(IncomingRequest::class, 'files'))
                ->setValue($this->request, new InboxMediaTestFileCollection(['media' => self::$berkas]));
        }

        return parent::kirimMedia();
    }
}

/**
 * TODO-Q2a: jalur kirim MEDIA (POST /inbox/kirim-media) end-to-end lewat
 * HTTP sungguhan ke fake gateway (tests/_support/fake-gateway-router.php).
 * Tidak ada media yang terkirim ke WhatsApp.
 *
 * @internal
 */
final class InboxKirimMediaGatewayPalsuTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use GatewayApiTestTrait;

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private FakeGatewayServer $gateway;

    /** @var list<string> */
    private array $berkasSementara = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->gatewaySetUp();
        $this->gateway = FakeGatewayServer::start();
        InboxKirimMediaStub::$berkas = null;

        $now = date('Y-m-d H:i:s');
        $this->inbox->table('gateway_status')->where('id', 1)->delete();
        $this->inbox->table('gateway_status')->insert([
            'id' => 1, 'status' => 'connected', 'last_heartbeat_at' => $now, 'updated_at' => $now,
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

        $this->withRoutes([
            ['POST', 'inbox/kirim-media', '\InboxKirimMediaStub::kirimMedia', ['filter' => 'auth']],
        ]);
    }

    protected function tearDown(): void
    {
        $this->gateway->stop();
        FakeGatewayServer::restoreGatewayBaseUrl();
        InboxKirimMediaStub::$berkas = null;
        foreach ($this->berkasSementara as $path) {
            @unlink($path);
        }
        (new CreateMessageSendAudit())->down();
        \Config\Database::forge()->dropTable('users', true);
        $this->gatewayTearDown();
        parent::tearDown();
    }

    private function berkas(string $isi, string $namaKlien, ?int $ukuran = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kmd');
        file_put_contents($path, $isi);
        $this->berkasSementara[] = $path;

        InboxKirimMediaStub::$berkas = new FakeUploadedFile(
            $path,
            $namaKlien,
            (string) mime_content_type($path),
            $ukuran ?? filesize($path),
            UPLOAD_ERR_OK
        );

        return $isi;
    }

    private function png(): string
    {
        return $this->berkas(base64_decode(self::PNG_1X1), 'foto.png');
    }

    private function webp(): string
    {
        // RIFF....WEBPVP8L -- cukup untuk dikenali finfo sebagai image/webp.
        $isi = "RIFF\x1a\x00\x00\x00WEBPVP8L\x0d\x00\x00\x00\x2f\x00\x00\x00\x10\x07\x10\x11\x11\x88\x88\xfe\x07\x00";

        return $this->berkas($isi, 'stiker.webp');
    }

    private function seedConv(): int
    {
        return $this->seedConversation('6281299' . random_int(10000, 99999) . '@s.whatsapp.net');
    }

    private function kirimMedia(int $conversationId, string $operationId, string $caption = 'foto pesanan')
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => 21, 'role' => 'kasir'])
            ->post('/inbox/kirim-media', [
                'conversation_id' => $conversationId,
                'caption'         => $caption,
                'operation_id'    => $operationId,
            ]);
    }

    private function pakaiSkenario(string $skenario): void
    {
        FakeGatewayServer::useGatewayBaseUrl($this->gateway->baseUrl($skenario));
    }

    private function outgoing(int $conversationId): array
    {
        return $this->inbox->table('messages')
            ->where('conversation_id', $conversationId)
            ->where('direction', 'outgoing')
            ->get()
            ->getResultArray();
    }

    private function auditRows(int $conversationId): array
    {
        return $this->inbox->table('message_send_audit')->where('conversation_id', $conversationId)->get()->getResultArray();
    }

    /** Gagal: tidak ada media tersimpan sebagai terkirim; audit mencatat metadata media (bukan byte). */
    private function assertGagalTanpaMediaTersimpan(int $conversationId, string $outcomeKind): void
    {
        $this->assertSame([], $this->outgoing($conversationId), 'Kirim media gagal tidak boleh menyimpan pesan sebagai terkirim.');

        $rows = $this->auditRows($conversationId);
        $this->assertCount(1, $rows);
        $this->assertSame($outcomeKind, $rows[0]['outcome_kind']);
        $this->assertSame('kirimMedia', $rows[0]['context']);
        $this->assertSame('image', $rows[0]['media_type']);
        $this->assertSame('foto.png', $rows[0]['media_file_name']);
        $this->assertSame('foto pesanan', $rows[0]['preview_text']);
    }

    public function testSuksesMenyimpanMediaDanMediaRef(): void
    {
        $conv = $this->seedConv();
        $isi  = $this->png();
        $this->pakaiSkenario('ok');

        $res = $this->kirimMedia($conv, 'op-media-ok');

        $res->assertStatus(200);
        $json = $this->gatewayBodyJson($res);
        $this->assertSame('success', $json['status']);

        $requests = $this->gateway->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('send-media', $requests[0]['endpoint']);
        $this->assertSame('Bearer ' . $this->gatewayToken, $requests[0]['authorization']);
        $this->assertSame('image', $requests[0]['body']['media_type']);
        $this->assertSame('image/png', $requests[0]['body']['mimetype']);
        $this->assertSame('foto.png', $requests[0]['body']['file_name']);
        $this->assertSame('foto pesanan', $requests[0]['body']['caption']);
        $this->assertSame(base64_encode($isi), $requests[0]['body']['media_base64'], 'Byte file harus terkirim utuh.');

        $rows = $this->outgoing($conv);
        $this->assertCount(1, $rows);
        $this->assertSame($json['message']['wa_message_id'], $rows[0]['wa_message_id']);
        $this->assertStringStartsWith('FAKEWA', $rows[0]['wa_message_id']);
        $this->assertSame('image', $rows[0]['message_type']);
        $this->assertSame('foto pesanan', $rows[0]['text']);
        $this->assertNull($rows[0]['media_path'], 'Media keluar tidak pernah disimpan ke disk lokal.');
        $meta = json_decode((string) $rows[0]['media_metadata'], true);
        $this->assertSame('/v/t62.fake/' . $rows[0]['wa_message_id'], $meta['direct_path']);
        $this->assertNotEmpty($meta['media_key_base64']);
        $this->assertSame([], $this->auditRows($conv));
    }

    public function testWebpDikirimSebagaiStickerTanpaCaption(): void
    {
        $conv = $this->seedConv();
        $this->webp();
        $this->pakaiSkenario('ok');

        $this->kirimMedia($conv, 'op-media-webp', 'caption ini harus dibuang')->assertStatus(200);

        $body = $this->gateway->requests()[0]['body'];
        $this->assertSame('sticker', $body['media_type']);
        $this->assertSame('', $body['caption']);
        $row = $this->outgoing($conv)[0];
        $this->assertSame('sticker', $row['message_type']);
        $this->assertNull($row['text']);
    }

    public function testFileMelebihiBatasDitolakSebelumKeGateway(): void
    {
        $conv = $this->seedConv();
        $this->berkas(base64_decode(self::PNG_1X1), 'foto.png', 200 * 1024 * 1024);
        $this->pakaiSkenario('ok');

        $res = $this->kirimMedia($conv, 'op-media-besar');

        $res->assertStatus(400);
        $this->assertStringContainsString('melebihi batas maksimum', $this->gatewayBodyJson($res)['message']);
        $this->assertSame([], $this->gateway->requests(), 'File terlalu besar tidak boleh dikirim ke Gateway.');
        $this->assertSame([], $this->outgoing($conv));
    }

    public function testTanpaFileDitolak(): void
    {
        $conv = $this->seedConv();
        $this->pakaiSkenario('ok');

        $res = $this->kirimMedia($conv, 'op-media-kosong');

        $res->assertStatus(400);
        $this->assertSame('File media wajib diupload.', $this->gatewayBodyJson($res)['message']);
        $this->assertSame([], $this->gateway->requests());
    }

    public function testGatewayHttp500(): void
    {
        $conv = $this->seedConv();
        $this->png();
        $this->pakaiSkenario('http500');

        $res = $this->kirimMedia($conv, 'op-media-500');

        $res->assertStatus(500);
        $this->assertSame('Gagal mengirim media: Evolution gagal mengirim', $this->gatewayBodyJson($res)['message']);
        $this->assertGagalTanpaMediaTersimpan($conv, 'definitive');
    }

    public function testResponsBukanJson(): void
    {
        $conv = $this->seedConv();
        $this->png();
        $this->pakaiSkenario('bukan_json');

        $res = $this->kirimMedia($conv, 'op-media-html');

        $res->assertStatus(502);
        $this->assertStringContainsString('respons Gateway tidak valid', $this->gatewayBodyJson($res)['message']);
        $this->assertGagalTanpaMediaTersimpan($conv, 'network');
    }

    public function testGatewayMati(): void
    {
        $conv = $this->seedConv();
        $this->png();
        FakeGatewayServer::useGatewayBaseUrl('http://127.0.0.1:' . FakeGatewayServer::unusedPort());

        $res = $this->kirimMedia($conv, 'op-media-mati');

        $res->assertStatus(502);
        $this->assertStringContainsString('Tidak bisa menghubungi Gateway', $this->gatewayBodyJson($res)['message']);
        $this->assertGagalTanpaMediaTersimpan($conv, 'network');
    }
}
