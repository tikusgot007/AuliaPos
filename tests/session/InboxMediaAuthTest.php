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
 * Balas Pesan (Tahap 3 remediasi ronde-2, SEC-002) -- otorisasi object-level
 * pada `GET /inbox/media/(:num)` (`Inbox::media()`).
 *
 * Cakupan: pemanggil yang TIDAK berhak atas percakapan pemilik media ditolak
 * `403` SEBELUM baca disk/ETag/hubungi Gateway; pemilik (atau admin, atau
 * percakapan yang belum ditangani) tetap disajikan.
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
    // SEC-002: percakapan milik kasir lain -> 403 tanpa byte keluar
    // ------------------------------------------------------------------

    public function testMediaPercakapanMilikKasirLainDitolak403TanpaHubungiGateway(): void
    {
        $conversationId = $this->seedConversation('628222222222@s.whatsapp.net', 9);
        $messageId      = $this->seedImageMessage($conversationId, ['media_local_filename' => 'rahasia.jpg']);

        // File SENGAJA ada di disk: kalau otorisasi bocor, responsnya 200
        // dan isi rahasia keluar -- jadi test ini membuktikan urutan guard.
        file_put_contents($this->mediaDir . DIRECTORY_SEPARATOR . 'rahasia.jpg', 'ISI-RAHASIA');

        $controller = $this->makeController();
        $response   = $controller->media($messageId);

        $this->assertSame(403, $response->getStatusCode(), 'SEC-002: media percakapan orang lain -> 403.');
        $this->assertStringNotContainsString(
            'ISI-RAHASIA',
            (string) $response->getBody(),
            'SEC-002: tidak ada byte media yang boleh keluar sebelum otorisasi.'
        );
        $this->assertSame(0, $controller->gatewayMediaDownloadCalls, 'SEC-002: tidak menghubungi Gateway pada kasus ditolak.');
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

    public function callGatewayMediaDownload(GatewayInboxConfig $config, array $mediaRef, ?string $mimetype, int $timeoutSeconds = 30): array
    {
        $this->gatewayMediaDownloadCalls++;

        return ['ok' => true, 'binary' => 'GATEWAY-BYTES'];
    }
}
