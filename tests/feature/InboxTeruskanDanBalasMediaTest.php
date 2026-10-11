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
class InboxTeruskanTestFileCollection extends FileCollection
{
    public function __construct(array $files)
    {
        $this->files = $files;
    }
}

/**
 * Hanya sumber berkas unggahan yang dipalsukan (lihat InboxKirimMediaStub di
 * InboxKirimMediaGatewayPalsuTest). Saat $berkas null, request tidak disentuh
 * sama sekali -- jalur Teruskan memang tidak menerima unggahan.
 */
class InboxTeruskanMediaStub extends InboxController
{
    public static ?FakeUploadedFile $berkas = null;

    public function kirimMedia()
    {
        if (self::$berkas !== null && $this->request instanceof IncomingRequest) {
            (new ReflectionProperty(IncomingRequest::class, 'files'))
                ->setValue($this->request, new InboxTeruskanTestFileCollection(['media' => self::$berkas]));
        }

        return parent::kirimMedia();
    }
}

/**
 * TODO-Q2c: Teruskan lampiran (Inbox::kirimTeruskanMedia()) dan
 * Balas-dengan-lampiran (Inbox::kirimMediaBiasa() + quoted_message_id)
 * end-to-end lewat HTTP sungguhan ke fake gateway. Tidak ada media yang
 * terkirim ke WhatsApp.
 *
 * @internal
 */
final class InboxTeruskanDanBalasMediaTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use GatewayApiTestTrait;

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private FakeGatewayServer $gateway;
    private string $mediaDir;

    /** @var list<string> */
    private array $berkasSementara = [];

    /** @var array{0: mixed, 1: mixed, 2: string|false} */
    private array $mediaPathAsli;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gatewaySetUp();
        $this->gateway = FakeGatewayServer::start();
        InboxTeruskanMediaStub::$berkas = null;

        // Folder media lokal khusus test, bukan inbox.mediaStoragePath dev/produksi.
        $this->mediaDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aulia-teruskan-' . bin2hex(random_bytes(4));
        mkdir($this->mediaDir);
        $key = 'inbox.mediaStoragePath';
        $this->mediaPathAsli = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        putenv($key . '=' . $this->mediaDir);
        $_ENV[$key] = $_SERVER[$key] = $this->mediaDir;

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
            ['POST', 'inbox/kirim-media', '\InboxTeruskanMediaStub::kirimMedia', ['filter' => 'auth']],
        ]);
    }

    protected function tearDown(): void
    {
        $this->gateway->stop();
        FakeGatewayServer::restoreGatewayBaseUrl();
        InboxTeruskanMediaStub::$berkas = null;

        $key = 'inbox.mediaStoragePath';
        [$env, $server, $getenv] = $this->mediaPathAsli;
        if ($env === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $env;
        }
        if ($server === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $server;
        }
        putenv($getenv === false ? $key : $key . '=' . $getenv);

        foreach (glob($this->mediaDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->mediaDir);
        foreach ($this->berkasSementara as $path) {
            @unlink($path);
        }

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

    /** Pesan sumber; default = gambar masuk dari pelanggan. */
    private function seedPesan(int $conversationId, array $override = []): int
    {
        $now = date('Y-m-d H:i:s');
        $this->inbox->table('messages')->insert(array_merge([
            'conversation_id'   => $conversationId,
            'wa_message_id'     => 'WAMSG-SUMBER-' . random_int(100000, 999999),
            'direction'         => 'incoming',
            'message_type'      => 'image',
            'sender_jid'        => '6281299000001@s.whatsapp.net',
            'text'              => 'bukti transfer',
            'media_mime_type'   => 'image/jpeg',
            'media_filename'    => 'bukti.jpg',
            'media_size'        => 1234,
            'send_status'       => 'received',
            'message_timestamp' => $now,
            'created_at'        => $now,
            'is_internal'       => 0,
            'is_forwarded'      => 0,
        ], $override));

        return (int) $this->inbox->insertID();
    }

    private function refGateway(string $directPath): string
    {
        return json_encode([
            'media_type'       => 'image',
            'direct_path'      => $directPath,
            'media_key_base64' => base64_encode('kunci'),
        ]);
    }

    private function teruskan(int $tujuan, int $sumber, array $extra = [])
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => 21, 'role' => 'kasir'])
            ->post('/inbox/kirim-media', array_merge([
                'conversation_id'         => $tujuan,
                'forward_from_message_id' => $sumber,
                'operation_id'            => 'op-fwd-' . bin2hex(random_bytes(4)),
            ], $extra));
    }

    private function endpoints(): array
    {
        return array_column($this->gateway->requests(), 'endpoint');
    }

    private function sendMediaBody(): array
    {
        foreach ($this->gateway->requests() as $req) {
            if ($req['endpoint'] === 'send-media') {
                return $req['body'];
            }
        }

        $this->fail('Tidak ada request /send-media ke Gateway.');
    }

    private function outgoing(int $conversationId): array
    {
        return $this->inbox->table('messages')
            ->where('conversation_id', $conversationId)
            ->where('direction', 'outgoing')
            ->get()
            ->getResultArray();
    }

    private function assertDitolak400TanpaKirim($response, string $pesan, int $tujuan): void
    {
        $response->assertStatus(400);
        $this->assertSame($pesan, $this->gatewayBodyJson($response)['message']);
        $this->assertNotContains('send-media', $this->endpoints(), 'Penolakan tidak boleh mengirim apa pun ke Gateway.');
        $this->assertSame([], $this->outgoing($tujuan));
    }

    // ---------------- Teruskan lampiran ----------------

    public function testTeruskanDariDiskLokalTanpaMengunduhDariGateway(): void
    {
        $asal   = $this->seedConv();
        $tujuan = $this->seedConv();
        file_put_contents($this->mediaDir . DIRECTORY_SEPARATOR . 'lokal-1.jpg', 'BYTE-DARI-DISK-LOKAL');
        $sumber = $this->seedPesan($asal, [
            'media_local_filename' => 'lokal-1.jpg',
            'media_metadata'       => $this->refGateway('evolution-media:tidak-boleh-dipakai'),
        ]);
        $this->pakaiSkenario('ok');

        $res = $this->teruskan($tujuan, $sumber);

        $res->assertStatus(200);
        $json = $this->gatewayBodyJson($res);
        $this->assertSame('success', $json['status']);
        $this->assertSame(['send-media'], $this->endpoints(), 'Byte lokal tersedia: Gateway tidak boleh dimintai download.');

        $body = $this->sendMediaBody();
        $this->assertSame(base64_encode('BYTE-DARI-DISK-LOKAL'), $body['media_base64']);
        $this->assertTrue($body['forward']);
        $this->assertArrayNotHasKey('quoted', $body);
        $this->assertSame('image', $body['media_type']);
        $this->assertSame('image/jpeg', $body['mimetype']);
        $this->assertSame('bukti.jpg', $body['file_name']);
        $this->assertSame('bukti transfer', $body['caption'], 'Caption diambil server dari pesan sumber.');

        $rows = $this->outgoing($tujuan);
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['is_forwarded']);
        $this->assertSame('image', $rows[0]['message_type']);
    }

    public function testTeruskanMengunduhDariGatewayBilaTidakAdaDiDisk(): void
    {
        $asal   = $this->seedConv();
        $tujuan = $this->seedConv();
        $sumber = $this->seedPesan($asal, ['media_metadata' => $this->refGateway('evolution-media:abc123')]);
        $this->pakaiSkenario('ok');

        $res = $this->teruskan($tujuan, $sumber);

        $res->assertStatus(200);
        $this->assertSame(['media/download', 'send-media'], $this->endpoints());

        $download = $this->gateway->requests()[0]['body'];
        $this->assertSame('evolution-media:abc123', $download['direct_path']);
        $this->assertSame('image', $download['media_type']);

        $this->assertSame(
            base64_encode('FAKE-MEDIA-BYTES:evolution-media:abc123'),
            $this->sendMediaBody()['media_base64'],
            'Byte hasil live-fetch harus diteruskan utuh.'
        );
        $this->assertCount(1, $this->outgoing($tujuan));
    }

    public function testTeruskanMediaKedaluwarsaDibatalkanSeluruhnya(): void
    {
        $asal   = $this->seedConv();
        $tujuan = $this->seedConv();
        $sumber = $this->seedPesan($asal, ['media_metadata' => $this->refGateway('evolution-media:lama')]);
        $this->pakaiSkenario('media_hilang');

        $res = $this->teruskan($tujuan, $sumber);

        $this->assertDitolak400TanpaKirim($res, 'Lampiran ini sudah tidak tersedia, jadi tidak bisa diteruskan.', $tujuan);
        $this->assertSame(['media/download'], $this->endpoints());
    }

    public function testTeruskanMediaYangPastiHilangTidakMenghubungiGateway(): void
    {
        $asal   = $this->seedConv();
        $tujuan = $this->seedConv();
        $sumber = $this->seedPesan($asal, [
            'media_metadata'          => $this->refGateway('evolution-media:hilang'),
            'media_confirmed_gone_at' => date('Y-m-d H:i:s'),
        ]);
        $this->pakaiSkenario('ok');

        $res = $this->teruskan($tujuan, $sumber);

        $this->assertDitolak400TanpaKirim($res, 'Lampiran ini sudah tidak tersedia, jadi tidak bisa diteruskan.', $tujuan);
        $this->assertSame([], $this->endpoints(), 'Media yang pasti hilang tidak boleh memicu request ke Gateway.');
    }

    public function testTeruskanSumberTeksDitolakDiJalurMedia(): void
    {
        $tujuan = $this->seedConv();
        $sumber = $this->seedPesan($this->seedConv(), ['message_type' => 'text', 'text' => 'halo', 'media_mime_type' => null, 'media_filename' => null]);
        $this->pakaiSkenario('ok');

        $this->assertDitolak400TanpaKirim($this->teruskan($tujuan, $sumber), 'Jenis pesan ini tidak bisa diteruskan.', $tujuan);
    }

    public function testTeruskanAudioDitolak(): void
    {
        $tujuan = $this->seedConv();
        $sumber = $this->seedPesan($this->seedConv(), ['message_type' => 'audio', 'media_metadata' => $this->refGateway('evolution-media:a')]);
        $this->pakaiSkenario('ok');

        $this->assertDitolak400TanpaKirim($this->teruskan($tujuan, $sumber), 'Audio/video tidak dapat diteruskan.', $tujuan);
    }

    public function testTeruskanCatatanInternalDitolak(): void
    {
        $tujuan = $this->seedConv();
        $sumber = $this->seedPesan($this->seedConv(), ['is_internal' => 1, 'media_metadata' => $this->refGateway('evolution-media:i')]);
        $this->pakaiSkenario('ok');

        $this->assertDitolak400TanpaKirim($this->teruskan($tujuan, $sumber), 'Catatan internal tidak bisa diteruskan.', $tujuan);
    }

    public function testTeruskanSumberTidakAdaDitolak(): void
    {
        $tujuan = $this->seedConv();
        $this->pakaiSkenario('ok');

        $this->assertDitolak400TanpaKirim($this->teruskan($tujuan, 999999), 'Pesan yang ingin diteruskan tidak ditemukan.', $tujuan);
    }

    public function testTeruskanSekaligusKutipanDitolak(): void
    {
        $asal   = $this->seedConv();
        $tujuan = $this->seedConv();
        $sumber = $this->seedPesan($asal, ['media_metadata' => $this->refGateway('evolution-media:x')]);
        $kutip  = $this->seedPesan($tujuan, ['message_type' => 'text', 'text' => 'pesan', 'media_mime_type' => null, 'media_filename' => null]);
        $this->pakaiSkenario('ok');

        $res = $this->teruskan($tujuan, $sumber, ['quoted_message_id' => $kutip]);

        $this->assertDitolak400TanpaKirim($res, 'Tidak bisa menjawab sekaligus meneruskan. Pilih salah satu aksi.', $tujuan);
    }

    // ---------------- Balas dengan lampiran ----------------

    private function unggahPng(): string
    {
        $isi  = base64_decode(self::PNG_1X1);
        $path = tempnam(sys_get_temp_dir(), 'blm');
        file_put_contents($path, $isi);
        $this->berkasSementara[] = $path;
        InboxTeruskanMediaStub::$berkas = new FakeUploadedFile($path, 'balasan.png', 'image/png', strlen($isi), UPLOAD_ERR_OK);

        return $isi;
    }

    private function balasDenganLampiran(int $conversationId, int $quotedMessageId)
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => 21, 'role' => 'kasir'])
            ->post('/inbox/kirim-media', [
                'conversation_id'   => $conversationId,
                'caption'           => 'ini fotonya',
                'quoted_message_id' => $quotedMessageId,
                'operation_id'      => 'op-balas-' . bin2hex(random_bytes(4)),
            ]);
    }

    public function testBalasDenganLampiranMengirimKutipanDanMenyimpanSnapshot(): void
    {
        $conv  = $this->seedConv();
        $kutip = $this->seedPesan($conv, ['message_type' => 'text', 'text' => 'Minta foto produknya', 'media_mime_type' => null, 'media_filename' => null]);
        $isi   = $this->unggahPng();
        $this->pakaiSkenario('ok');

        $res = $this->balasDenganLampiran($conv, $kutip);

        $res->assertStatus(200);
        $json = $this->gatewayBodyJson($res);
        $this->assertSame('success', $json['status']);
        $this->assertTrue($json['quote_applied']);

        $body = $this->sendMediaBody();
        $this->assertSame(base64_encode($isi), $body['media_base64']);
        $this->assertArrayNotHasKey('forward', $body);
        $quotedSumber = $this->inbox->table('messages')->where('id', $kutip)->get()->getRowArray();
        $this->assertSame($quotedSumber['wa_message_id'], $body['quoted']['wa_message_id']);
        $this->assertFalse($body['quoted']['fromMe']);
        $this->assertSame('Minta foto produknya', $body['quoted']['text']);

        $rows = $this->outgoing($conv);
        $this->assertCount(1, $rows);
        $this->assertSame($quotedSumber['wa_message_id'], $rows[0]['quoted_wa_message_id']);
        $this->assertSame($kutip, (int) $rows[0]['quoted_source_message_id']);
        $this->assertSame(0, (int) $rows[0]['is_forwarded']);
    }

    public function testBalasDenganLampiranGatewayLamaDilaporkanTanpaKutipan(): void
    {
        $conv  = $this->seedConv();
        $kutip = $this->seedPesan($conv, ['message_type' => 'text', 'text' => 'halo', 'media_mime_type' => null, 'media_filename' => null]);
        $this->unggahPng();
        $this->pakaiSkenario('lama');

        $res = $this->balasDenganLampiran($conv, $kutip);

        $res->assertStatus(200);
        $this->assertFalse($this->gatewayBodyJson($res)['quote_applied']);
        $this->assertCount(1, $this->outgoing($conv));
    }

    public function testBalasDenganLampiranKutipanPercakapanLainDitolak(): void
    {
        $conv  = $this->seedConv();
        $kutip = $this->seedPesan($this->seedConv(), ['message_type' => 'text', 'text' => 'rahasia', 'media_mime_type' => null, 'media_filename' => null]);
        $this->unggahPng();
        $this->pakaiSkenario('ok');

        $res = $this->balasDenganLampiran($conv, $kutip);

        $res->assertStatus(400);
        $this->assertSame([], $this->endpoints(), 'Kutipan lintas percakapan tidak boleh sampai ke Gateway.');
        $this->assertSame([], $this->outgoing($conv));
    }
}
