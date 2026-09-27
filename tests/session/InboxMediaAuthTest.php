<?php

use App\Controllers\Inbox;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Inbox as GatewayInboxConfig;
use Psr\Log\NullLogger;

/**
 * Inbox Read Authorization (REQ-002, `spec-design-inbox-read-authorization.md`
 * v1.1) -- `GET /inbox/media/(:num)` (`Inbox::media()`) kini terbuka bagi
 * seluruh staff yang login, menggantikan guard `SEC-002`/`TASK-103` yang
 * dulu menolak `403` pemanggil bukan pemegang percakapan.
 *
 * Cakupan: pemanggil bukan pemegang percakapan tetap DISAJIKAN (`200`) dari
 * disk tanpa menghubungi Gateway; pemegang, percakapan tanpa pemilik, dan
 * admin tetap disajikan seperti sebelumnya (regresi); pesan/percakapan tidak
 * ada tetap `404` (AC-007, guard `cekOwnership()` bukan sumber `404` itu);
 * jalur `410` boleh dipicu staff mana pun dan menulis
 * `media_confirmed_gone_at` (AC-009/C-2).
 *
 * @internal
 */
final class InboxMediaAuthTest extends CIUnitTestCase
{
    private string $mediaDir = '';

    /** @var string|null Nilai $_ENV['inbox.mediaStoragePath'] sebelum test. */
    private ?string $prevMediaStoragePath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('aulia_inboxdb_test', db_connect('inbox')->query('SELECT DATABASE() AS db')->getRow()->db);

        $db = db_connect('inbox');
        $db->table('messages')->emptyTable();
        $db->table('conversations')->emptyTable();

        db_connect()->query('CREATE TABLE IF NOT EXISTS db_users (id INTEGER PRIMARY KEY, nama TEXT, username TEXT)');
        db_connect()->table('db_users')->delete(['id' => 7]);
        db_connect()->table('db_users')->delete(['id' => 9]);
        db_connect()->table('db_users')->insert(['id' => 7, 'nama' => 'Kasir Sesi', 'username' => 'kasir7']);
        db_connect()->table('db_users')->insert(['id' => 9, 'nama' => 'Kasir Lain', 'username' => 'kasir9']);

        $_SESSION = ['id_user' => 7, 'role' => 'kasir'];

        // mediaStoragePath di-override ke folder sementara per-test supaya
        // test ini TIDAK bergantung ke konfigurasi .env mesin mana pun.
        $this->mediaDir = sys_get_temp_dir() . '/aulia_inbox_media_auth_' . bin2hex(random_bytes(4));
        mkdir($this->mediaDir, 0775, true);
        $this->prevMediaStoragePath = $_ENV['inbox.mediaStoragePath'] ?? null;
        $_ENV['inbox.mediaStoragePath'] = $this->mediaDir;
    }

    protected function tearDown(): void
    {
        if ($this->prevMediaStoragePath === null) {
            unset($_ENV['inbox.mediaStoragePath']);
        } else {
            $_ENV['inbox.mediaStoragePath'] = $this->prevMediaStoragePath;
        }

        unset($_SESSION);

        if ($this->mediaDir !== '' && is_dir($this->mediaDir)) {
            foreach (glob($this->mediaDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->mediaDir);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // REQ-002: percakapan milik kasir lain -> tetap disajikan dari disk
    // ------------------------------------------------------------------

    public function testMediaPercakapanMilikKasirLainDisajikanDariDisk(): void
    {
        $conversationId = $this->seedConversation('628222222222@s.whatsapp.net', 9);
        $messageId      = $this->seedImageMessage($conversationId, ['media_local_filename' => 'rahasia.jpg']);

        file_put_contents($this->mediaDir . DIRECTORY_SEPARATOR . 'rahasia.jpg', 'ISI-RAHASIA');

        $controller = $this->makeController();
        $response   = $controller->media($messageId);

        $this->assertSame(200, $response->getStatusCode(), 'REQ-002: media percakapan kasir lain tetap disajikan.');
        $this->assertSame(
            'ISI-RAHASIA',
            (string) $response->getBody(),
            'REQ-002: disajikan dari disk untuk non-pemegang, tanpa menghubungi Gateway.'
        );
        $this->assertSame(0, $controller->gatewayMediaDownloadCalls, 'REQ-002: tidak menghubungi Gateway saat file sudah ada di disk.');
    }

    // ------------------------------------------------------------------
    // Percakapan sendiri (assigned ke sesi) -> disajikan 200
    // ------------------------------------------------------------------

    public function testMediaPercakapanSendiriDisajikanDariDisk(): void
    {
        $conversationId = $this->seedConversation('628111111111@s.whatsapp.net', 7);
        $messageId      = $this->seedImageMessage($conversationId, ['media_local_filename' => 'milik-sendiri.jpg']);
        file_put_contents($this->mediaDir . DIRECTORY_SEPARATOR . 'milik-sendiri.jpg', 'ISI-SENDIRI');

        $controller = $this->makeController();
        $response   = $controller->media($messageId);

        $this->assertSame(200, $response->getStatusCode(), 'Media percakapan sendiri harus tetap disajikan.');
        $this->assertSame('ISI-SENDIRI', (string) $response->getBody());
        $this->assertSame(0, $controller->gatewayMediaDownloadCalls, 'Disajikan dari disk, bukan Gateway.');
    }

    // ------------------------------------------------------------------
    // Percakapan yang belum ditangani siapa pun -> boleh (aturan lama)
    // ------------------------------------------------------------------

    public function testMediaPercakapanBelumDitanganiTetapDisajikan(): void
    {
        $conversationId = $this->seedConversation('628333333333@s.whatsapp.net', null);
        $messageId      = $this->seedImageMessage($conversationId, ['media_local_filename' => 'tanpa-penangan.jpg']);
        file_put_contents($this->mediaDir . DIRECTORY_SEPARATOR . 'tanpa-penangan.jpg', 'ISI-BEBAS');

        $controller = $this->makeController();
        $response   = $controller->media($messageId);

        $this->assertSame(200, $response->getStatusCode(), 'assigned_to NULL -> semua kasir boleh.');
        $this->assertSame('ISI-BEBAS', (string) $response->getBody());
    }

    // ------------------------------------------------------------------
    // Admin selalu boleh (aturan cekOwnership) -- regresi
    // ------------------------------------------------------------------

    public function testAdminBolehMenyajikanMediaPercakapanKasirLain(): void
    {
        $_SESSION = ['id_user' => 7, 'role' => 'admin'];

        $conversationId = $this->seedConversation('628444444444@s.whatsapp.net', 9);
        $messageId      = $this->seedImageMessage($conversationId, ['media_local_filename' => 'admin-ok.jpg']);
        file_put_contents($this->mediaDir . DIRECTORY_SEPARATOR . 'admin-ok.jpg', 'ISI-ADMIN');

        $controller = $this->makeController();
        $response   = $controller->media($messageId);

        $this->assertSame(200, $response->getStatusCode(), 'Admin boleh (supervisi/override).');
        $this->assertSame('ISI-ADMIN', (string) $response->getBody());
    }

    // ------------------------------------------------------------------
    // AC-009/C-2: jalur 410 Gateway tetap diproses untuk non-pemegang,
    // dan media_confirmed_gone_at boleh ditulis staff mana pun.
    // ------------------------------------------------------------------

    public function testMediaKedaluwarsa410DiprosesUntukNonPemegangDanMenulisPenanda(): void
    {
        $conversationId = $this->seedConversation('628555555555@s.whatsapp.net', 9);
        $messageId      = $this->seedImageMessage($conversationId, [
            'media_local_filename'    => null,
            'media_confirmed_gone_at' => null,
        ]);

        $controller = $this->makeController();
        $controller->gatewayResponse = [
            'ok'     => false,
            'status' => 410,
            'error'  => 'Media sudah tidak tersedia (kedaluwarsa).',
        ];

        $response = $controller->media($messageId);

        $this->assertSame(410, $response->getStatusCode(), 'AC-009/C-2: jalur 410 tidak ditolak 403 di awal untuk non-pemegang.');

        $row = db_connect('inbox')->table('messages')->where('id', $messageId)->get()->getRowArray();
        $this->assertNotNull($row['media_confirmed_gone_at'], 'AC-009/C-2: penanda objektif tetap ditulis untuk non-pemegang.');

        $this->assertSame(1, $controller->gatewayMediaDownloadCalls, 'AC-009/C-2: request harus benar-benar mencapai jalur Gateway.');
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function makeController(): InboxMediaAuthSpy
    {
        $request = new IncomingRequest(new \Config\App(), new URI('cli'), null, new UserAgent());

        $controller = new InboxMediaAuthSpy();
        $controller->initController($request, new Response(new \Config\App()), new NullLogger());

        return $controller;
    }

    private function seedConversation(string $chatId, ?int $assignedTo): int
    {
        $db = db_connect('inbox');
        $db->table('conversations')->insert([
            'chat_id'                => $chatId,
            'jid_type'               => 'pn',
            'status'                 => 'open',
            'assigned_to'            => $assignedTo,
            'last_message_direction' => 'incoming',
            'created_at'             => date('Y-m-d H:i:s'),
            'updated_at'             => date('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }

    /** @param array<string, mixed> $overrides */
    private function seedImageMessage(int $conversationId, array $overrides = []): int
    {
        $db = db_connect('inbox');
        $db->table('messages')->insert(array_merge([
            'conversation_id'   => $conversationId,
            'wa_message_id'     => 'MEDIA-TEMP-' . bin2hex(random_bytes(4)),
            'direction'         => 'incoming',
            'message_type'      => 'image',
            'sender_jid'        => '628999888777@s.whatsapp.net',
            'media_metadata'    => json_encode(['direct_path' => '/x', 'media_key_base64' => 'k']),
            'media_mime_type'   => 'image/jpeg',
            'media_filename'    => 'x.jpg',
            'message_timestamp' => date('Y-m-d H:i:s'),
            'send_status'       => 'received',
        ], $overrides));

        return (int) $db->insertID();
    }
}

final class InboxMediaAuthSpy extends Inbox
{
    /** Berapa kali jalur live-fetch Gateway dipanggil (harus 0 saat ditolak). */
    public int $gatewayMediaDownloadCalls = 0;

    /**
     * TASK-003 (AC-009/C-2): respons Gateway yang dikembalikan
     * callGatewayMediaDownload(). Default backward-compatible -- sama
     * persis dengan literal lama -- supaya keempat test lama (yang tidak
     * pernah menyetel properti ini) tetap memakai default sukses tanpa
     * perubahan perilaku.
     *
     * @var array<string, mixed>
     */
    public array $gatewayResponse = ['ok' => true, 'binary' => 'GATEWAY-BYTES'];

    public function callGatewayMediaDownload(GatewayInboxConfig $config, array $mediaRef, ?string $mimetype, int $timeoutSeconds = 30): array
    {
        $this->gatewayMediaDownloadCalls++;

        return $this->gatewayResponse;
    }
}
